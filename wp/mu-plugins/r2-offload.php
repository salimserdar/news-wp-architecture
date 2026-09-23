<?php
/**
 * Plugin Name: News R2 Offload
 * Description: During an upload, PUTs the original and generated sizes to Cloudflare R2 so the Worker can serve /wp-content/uploads/ immediately. Local files stay. Public URLs are not rewritten.
 * Version:     1.0.0
 *
 * Configuration (constants, set in wp-config.php by scripts/setup-vps.sh from .env):
 *   NEWS_R2_ACCOUNT_ID
 *   NEWS_R2_ACCESS_KEY_ID
 *   NEWS_R2_SECRET_ACCESS_KEY
 *   NEWS_R2_BUCKET            e.g. news-media
 *
 * Object key = public path without the leading slash
 * (wp-content/uploads/2026/09/23/photo.webp), matching the Worker.
 *
 * New uploads are stored under today's date in the WordPress timezone
 * (wp-content/uploads/YYYY/MM/DD/), not under the article's publish date.
 */

namespace News\R2Offload;

defined( 'ABSPATH' ) || exit;

const REGION      = 'auto';
const SERVICE     = 's3';
const ATTEMPTS    = 3;
const RETRY_MAX   = 8;
const OPTION_RETRY = 'news_r2_offload_retry';
const OPTION_ERROR = 'news_r2_offload_error';
const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

/** @var array<string,true> Keys already handled in this request. */
$GLOBALS['news_r2_attempted'] = [];

add_filter( 'upload_dir', __NAMESPACE__ . '\use_today_folder', 999 );
add_filter( 'wp_generate_attachment_metadata', __NAMESPACE__ . '\on_metadata', 999, 2 );
add_filter( 'wp_update_attachment_metadata', __NAMESPACE__ . '\on_metadata', 999, 2 );
add_action( 'delete_attachment', __NAMESPACE__ . '\on_delete' );
add_action( 'news_r2_offload_retry', __NAMESPACE__ . '\drain_retry' );
add_action( 'admin_init', __NAMESPACE__ . '\ensure_retry_scheduled' );
add_action( 'admin_notices', __NAMESPACE__ . '\admin_notice' );

/**
 * WordPress picks the upload folder from the article date, so a photo added
 * while editing yesterday's story lands in yesterday's folder. Put new uploads
 * in today's folder instead. Reads of existing files use basedir + the stored
 * relative path, which this does not change.
 *
 * @param array<string,mixed> $uploads
 * @return array<string,mixed>
 */
function use_today_folder( array $uploads ): array {
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) || ! is_new_upload() ) {
		return $uploads;
	}
	$time = current_time( 'mysql' );
	if ( ! is_string( $time ) || strlen( $time ) < 10 ) {
		return $uploads;
	}
	$subdir = sprintf( '/%s/%s/%s', substr( $time, 0, 4 ), substr( $time, 5, 2 ), substr( $time, 8, 2 ) );
	if ( ( $uploads['subdir'] ?? '' ) === $subdir ) {
		return $uploads;
	}
	$updated           = $uploads;
	$updated['subdir'] = $subdir;
	$updated['path']   = rtrim( (string) $uploads['basedir'], '/\\' ) . $subdir;
	$updated['url']    = rtrim( (string) $uploads['baseurl'], '/' ) . $subdir;
	if ( ! wp_mkdir_p( $updated['path'] ) ) {
		return $uploads;
	}
	return $updated;
}

function is_new_upload(): bool {
	if ( empty( $_FILES ) || ! is_array( $_FILES ) ) {
		return false;
	}
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return true;
	}
	return false;
}

/**
 * @param mixed $metadata
 * @param int   $attachment_id
 * @return mixed
 */
function on_metadata( $metadata, $attachment_id ) {
	if ( ! is_array( $metadata ) || ! is_numeric( $attachment_id ) ) {
		return $metadata;
	}
	offload( (int) $attachment_id, $metadata );
	return $metadata;
}

function on_delete( int $attachment_id ): void {
	if ( ! configured() ) {
		return;
	}
	$metadata = wp_get_attachment_metadata( $attachment_id );
	$files    = files_for( $attachment_id, is_array( $metadata ) ? $metadata : [] );
	if ( ! $files ) {
		return;
	}

	$result = send( 'DELETE', $files );
	$ok     = $result['ok'];
	$failed = $result['failed'];
	if ( $ok && function_exists( '\\News\\CachePurge\\queue_urls' ) ) {
		\News\CachePurge\queue_urls( urls_of( $ok ) );
	}
	forget( array_column( $ok, 'key' ) );
	if ( $failed ) {
		remember( $failed, 'DELETE' );
		schedule_retry();
		error_log( '[news-r2] delete failed for ' . implode( ', ', array_column( $failed, 'key' ) ) );
	}
}

/**
 * @param array<string,mixed> $metadata
 */
function offload( int $attachment_id, array $metadata ): void {
	static $busy = false;
	if ( $busy || ! configured() ) {
		return;
	}
	$busy  = true;
	$files = [];
	foreach ( files_for( $attachment_id, $metadata ) as $file ) {
		if ( isset( $GLOBALS['news_r2_attempted'][ $file['key'] ] ) ) {
			continue;
		}
		$GLOBALS['news_r2_attempted'][ $file['key'] ] = true;
		$files[] = $file;
	}
	if ( ! $files ) {
		$busy = false;
		return;
	}

	try {
		$result = put_with_retries( $files );
	} finally {
		$busy = false;
	}

	if ( $result['ok'] && function_exists( '\\News\\CachePurge\\queue_urls' ) ) {
		\News\CachePurge\queue_urls( urls_of( $result['ok'] ) );
	}
	forget( array_column( $result['ok'], 'key' ) );
	if ( $result['failed'] ) {
		remember( $result['failed'], 'PUT' );
		schedule_retry();
		remember_error( $result['failed'] );
		error_log( '[news-r2] put failed for ' . implode( ', ', array_column( $result['failed'], 'key' ) ) );
	} elseif ( ! retry_queue() ) {
		delete_option( OPTION_ERROR );
	}
}

function drain_retry(): void {
	if ( ! configured() ) {
		if ( retry_queue() ) {
			schedule_retry();
		}
		return;
	}
	$queue = retry_queue();
	if ( ! $queue ) {
		delete_option( OPTION_ERROR );
		return;
	}

	$puts    = [];
	$deletes = [];
	$kept    = [];
	$gave_up = [];
	foreach ( $queue as $key => $item ) {
		if ( ! is_array( $item ) || empty( $item['key'] ) || ! is_string( $item['key'] ) ) {
			continue;
		}
		$method = ( isset( $item['method'] ) && 'DELETE' === $item['method'] ) ? 'DELETE' : 'PUT';
		$tries  = isset( $item['tries'] ) ? (int) $item['tries'] : 0;
		if ( $tries >= RETRY_MAX ) {
			error_log( '[news-r2] giving up on ' . $item['key'] . ' after ' . $tries . ' attempts' );
			if ( 'PUT' === $method ) {
				$gave_up[] = $item;
			}
			continue;
		}
		if ( 'PUT' === $method && ( empty( $item['path'] ) || ! is_string( $item['path'] ) || ! is_file( $item['path'] ) ) ) {
			error_log( '[news-r2] drop retry, file missing: ' . $item['key'] );
			continue;
		}
		$item['method'] = $method;
		$item['tries']  = $tries + 1;
		$kept[ $key ]   = $item;
		if ( 'DELETE' === $method ) {
			$deletes[] = $item;
		} else {
			$puts[] = $item;
		}
	}

	$failed_keys = [];
	foreach ( [ 'PUT' => $puts, 'DELETE' => $deletes ] as $method => $batch ) {
		if ( ! $batch ) {
			continue;
		}
		$result = 'PUT' === $method ? put_with_retries( $batch ) : send( 'DELETE', $batch );
		if ( $result['ok'] && function_exists( '\\News\\CachePurge\\queue_urls' ) ) {
			\News\CachePurge\queue_urls( urls_of( $result['ok'] ) );
		}
		foreach ( $result['ok'] as $file ) {
			unset( $kept[ $file['key'] ] );
		}
		foreach ( $result['failed'] as $file ) {
			$failed_keys[ $file['key'] ] = true;
			error_log( '[news-r2] retry ' . $method . ' failed for ' . $file['key'] );
		}
	}

	if ( $kept ) {
		save_queue( $kept );
		$still = $gave_up;
		foreach ( $kept as $item ) {
			if ( isset( $failed_keys[ $item['key'] ] ) && ( $item['method'] ?? 'PUT' ) === 'PUT' ) {
				$still[] = $item;
			}
		}
		if ( $still ) {
			remember_error( $still );
		}
		schedule_retry();
		return;
	}

	delete_option( OPTION_RETRY );
	if ( $gave_up ) {
		remember_error( $gave_up );
		return;
	}
	delete_option( OPTION_ERROR );
}

function ensure_retry_scheduled(): void {
	if ( retry_queue() ) {
		schedule_retry();
	}
}

function admin_notice(): void {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}
	$message = get_option( OPTION_ERROR, '' );
	if ( ! is_string( $message ) || '' === $message ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

function configured(): bool {
	static $logged = false;
	$ok = defined( 'NEWS_R2_ACCOUNT_ID' ) && is_string( NEWS_R2_ACCOUNT_ID ) && '' !== NEWS_R2_ACCOUNT_ID
		&& defined( 'NEWS_R2_ACCESS_KEY_ID' ) && is_string( NEWS_R2_ACCESS_KEY_ID ) && '' !== NEWS_R2_ACCESS_KEY_ID
		&& defined( 'NEWS_R2_SECRET_ACCESS_KEY' ) && is_string( NEWS_R2_SECRET_ACCESS_KEY ) && '' !== NEWS_R2_SECRET_ACCESS_KEY
		&& defined( 'NEWS_R2_BUCKET' ) && is_string( NEWS_R2_BUCKET ) && '' !== NEWS_R2_BUCKET;
	if ( ! $ok && ! $logged ) {
		$logged = true;
		error_log( '[news-r2] offload disabled: set NEWS_R2_ACCOUNT_ID, NEWS_R2_ACCESS_KEY_ID, NEWS_R2_SECRET_ACCESS_KEY, NEWS_R2_BUCKET' );
	}
	return $ok;
}

/**
 * Original, scaled file, original_image, each size, and any sources entry.
 *
 * @param array<string,mixed> $metadata
 * @return list<array{path:string,key:string,mime:string,url:string}>
 */
function files_for( int $attachment_id, array $metadata ): array {
	$uploads = wp_get_upload_dir();
	if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
		return [];
	}
	$basedir = wp_normalize_path( $uploads['basedir'] );
	$realbase = realpath( $basedir );
	if ( false === $realbase ) {
		return [];
	}
	$baseurl = (string) $uploads['baseurl'];

	$main = $metadata['file'] ?? get_post_meta( $attachment_id, '_wp_attached_file', true );
	if ( ! is_string( $main ) || '' === $main ) {
		return [];
	}
	$main = ltrim( str_replace( '\\', '/', $main ), '/' );
	$dir  = dirname( $main );
	$dir  = ( '.' === $dir ) ? '' : $dir;

	$items = [];
	$add   = static function ( string $rel, string $mime ) use ( &$items, $basedir, $realbase, $baseurl ): void {
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		$rel = preg_replace( '#/+#', '/', $rel ) ?? $rel;
		if ( '' === $rel || str_contains( $rel, '..' ) ) {
			return;
		}
		if ( preg_match( '/\.(?:php|phtml|phar|pl|py|cgi|sh)$/i', $rel ) ) {
			return;
		}
		$abs  = wp_normalize_path( $basedir . '/' . $rel );
		$real = realpath( $abs );
		if ( false === $real || ! is_file( $real ) || ! str_starts_with( $real, $realbase . DIRECTORY_SEPARATOR ) ) {
			return;
		}
		if ( filesize( $real ) === 0 ) {
			error_log( '[news-r2] skip empty file ' . $rel );
			return;
		}
		$key = 'wp-content/uploads/' . $rel;
		$items[ $key ] = [
			'path' => $real,
			'key'  => $key,
			'mime' => '' !== $mime ? $mime : mime_for( $real ),
			'url'  => public_url( $baseurl, $rel ),
		];
	};

	$add( $main, mime_for( $basedir . '/' . $main ) );

	if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
		$add( ( '' === $dir ? '' : $dir . '/' ) . $metadata['original_image'], '' );
	}

	if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
		foreach ( $metadata['sizes'] as $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}
			collect_named_file( $add, $dir, $size, isset( $size['mime-type'] ) && is_string( $size['mime-type'] ) ? $size['mime-type'] : '' );
			if ( ! empty( $size['sources'] ) && is_array( $size['sources'] ) ) {
				foreach ( $size['sources'] as $source_mime => $source ) {
					$mime = is_string( $source_mime ) ? $source_mime : '';
					if ( is_array( $source ) ) {
						collect_named_file( $add, $dir, $source, $mime );
					}
				}
			}
		}
	}

	if ( ! empty( $metadata['sources'] ) && is_array( $metadata['sources'] ) ) {
		foreach ( $metadata['sources'] as $source_mime => $source ) {
			$mime = is_string( $source_mime ) ? $source_mime : '';
			if ( is_array( $source ) ) {
				collect_named_file( $add, $dir, $source, $mime );
			}
		}
	}

	return array_values( $items );
}

/**
 * @param callable(string,string):void $add
 * @param array<string,mixed>          $entry
 */
function collect_named_file( callable $add, string $dir, array $entry, string $mime ): void {
	if ( empty( $entry['file'] ) || ! is_string( $entry['file'] ) ) {
		return;
	}
	$file = ltrim( str_replace( '\\', '/', $entry['file'] ), '/' );
	if ( str_contains( $file, '/' ) ) {
		$add( $file, $mime );
		return;
	}
	$add( ( '' === $dir ? '' : $dir . '/' ) . $file, $mime );
}

function mime_for( string $path ): string {
	$checked = wp_check_filetype( $path );
	if ( ! empty( $checked['type'] ) && is_string( $checked['type'] ) ) {
		return $checked['type'];
	}
	return 'application/octet-stream';
}

function public_url( string $baseurl, string $rel ): string {
	$segments = array_map( 'rawurlencode', explode( '/', $rel ) );
	return rtrim( $baseurl, '/' ) . '/' . implode( '/', $segments );
}

/**
 * @param list<array{path:string,key:string,mime:string,url:string}> $files
 * @return array{ok:list<array{path:string,key:string,mime:string,url:string}>,failed:list<array{path:string,key:string,mime:string,url:string}>}
 */
function put_with_retries( array $files ): array {
	$pending = $files;
	$ok      = [];
	$fatal   = [];
	for ( $attempt = 1; $attempt <= ATTEMPTS && $pending; $attempt++ ) {
		if ( $attempt > 1 ) {
			usleep( 200000 * ( $attempt - 1 ) );
		}
		$result  = send( 'PUT', $pending );
		$ok      = array_merge( $ok, $result['ok'] );
		$pending = [];
		foreach ( $result['failed'] as $file ) {
			if ( ! empty( $file['retry'] ) ) {
				unset( $file['retry'] );
				$pending[] = $file;
			} else {
				unset( $file['retry'] );
				$fatal[] = $file;
			}
		}
	}
	return [
		'ok'     => $ok,
		'failed' => array_merge( $fatal, $pending ),
	];
}

/**
 * @param list<array{path?:string,key:string,mime?:string,url?:string}> $files
 * @return array{ok:list<array{path:string,key:string,mime:string,url:string}>,failed:list<array{path:string,key:string,mime:string,url:string,retry?:bool}>}
 */
function send( string $method, array $files ): array {
	$ok      = [];
	$failed  = [];
	$batch   = [];
	$host    = NEWS_R2_ACCOUNT_ID . '.r2.cloudflarestorage.com';
	$amzDate = gmdate( 'Ymd\THis\Z' );

	$mh = curl_multi_init();
	if ( false === $mh ) {
		foreach ( $files as $file ) {
			$file['retry'] = true;
			$failed[]      = $file;
		}
		return [ 'ok' => [], 'failed' => $failed ];
	}

	foreach ( $files as $file ) {
		if ( empty( $file['key'] ) || ! is_string( $file['key'] ) ) {
			continue;
		}
		$path = isset( $file['path'] ) && is_string( $file['path'] ) ? $file['path'] : '';
		$mime = isset( $file['mime'] ) && is_string( $file['mime'] ) && '' !== $file['mime']
			? $file['mime']
			: 'application/octet-stream';
		$hash = EMPTY_SHA256;
		$fp   = null;
		if ( 'PUT' === $method ) {
			if ( '' === $path || ! is_file( $path ) ) {
				$file['retry'] = false;
				$failed[]      = $file;
				continue;
			}
			$hash = hash_file( 'sha256', $path );
			if ( ! is_string( $hash ) || '' === $hash ) {
				$file['retry'] = true;
				$failed[]      = $file;
				continue;
			}
			$fp = fopen( $path, 'rb' );
			if ( false === $fp ) {
				$file['retry'] = true;
				$failed[]      = $file;
				continue;
			}
		}

		$canonical = '/' . encode_path( NEWS_R2_BUCKET . '/' . $file['key'] );
		$headers   = [
			'host'                 => $host,
			'x-amz-content-sha256' => $hash,
			'x-amz-date'           => $amzDate,
		];
		if ( 'PUT' === $method ) {
			$headers['content-type'] = $mime;
		}
		$authorization = authorization( $method, $canonical, $hash, $headers, $amzDate );

		$header_lines = [
			'Host: ' . $host,
			'x-amz-content-sha256: ' . $hash,
			'x-amz-date: ' . $amzDate,
			'Authorization: ' . $authorization,
			'Expect:',
		];
		if ( 'PUT' === $method ) {
			$header_lines[] = 'Content-Type: ' . $mime;
		}

		$ch = curl_init( 'https://' . $host . $canonical );
		if ( false === $ch ) {
			if ( is_resource( $fp ) ) {
				fclose( $fp );
			}
			$file['retry'] = true;
			$failed[]      = $file;
			continue;
		}
		$opts = [
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $header_lines,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => false,
			CURLOPT_TIMEOUT        => 15,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
		];
		if ( 'PUT' === $method && is_resource( $fp ) ) {
			$opts[ CURLOPT_UPLOAD ]     = true;
			$opts[ CURLOPT_INFILE ]     = $fp;
			$opts[ CURLOPT_INFILESIZE ] = filesize( $path );
		}
		curl_setopt_array( $ch, $opts );
		curl_multi_add_handle( $mh, $ch );
		$batch[] = [
			'ch'   => $ch,
			'fp'   => $fp,
			'file' => $file,
		];
	}

	$running = 0;
	do {
		$status = curl_multi_exec( $mh, $running );
		if ( CURLM_OK === $status && $running ) {
			curl_multi_select( $mh, 1.0 );
		}
	} while ( $running && CURLM_OK === $status );

	foreach ( $batch as $item ) {
		$ch   = $item['ch'];
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$err  = curl_error( $ch );
		$body = curl_multi_getcontent( $ch );
		curl_multi_remove_handle( $mh, $ch );
		curl_close( $ch );
		if ( is_resource( $item['fp'] ) ) {
			fclose( $item['fp'] );
		}
		$success = ( 'PUT' === $method && 200 === $code ) || ( 'DELETE' === $method && ( 204 === $code || 200 === $code || 404 === $code ) );
		if ( $success ) {
			$ok[] = $item['file'];
			continue;
		}
		$retry = ( '' !== $err || 0 === $code || 429 === $code || $code >= 500 );
		$snippet = is_string( $body ) ? preg_replace( '/\s+/', ' ', substr( $body, 0, 180 ) ) : '';
		error_log( '[news-r2] ' . $method . ' ' . $item['file']['key'] . ' HTTP ' . $code . ( $err ? ' ' . $err : '' ) . ( $snippet ? ' ' . $snippet : '' ) );
		$item['file']['retry'] = $retry;
		$failed[]              = $item['file'];
	}
	curl_multi_close( $mh );

	return [ 'ok' => $ok, 'failed' => $failed ];
}

/**
 * @param array<string,string> $headers Lowercase names.
 */
function authorization( string $method, string $canonical_uri, string $payload_hash, array $headers, string $amz_date ): string {
	ksort( $headers );
	$canonical_headers = '';
	$signed            = [];
	foreach ( $headers as $name => $value ) {
		$name = strtolower( $name );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) ?? $value );
		$canonical_headers .= $name . ':' . $value . "\n";
		$signed[] = $name;
	}
	$signed_headers = implode( ';', $signed );
	$canonical      = $method . "\n"
		. $canonical_uri . "\n"
		. "\n"
		. $canonical_headers . "\n"
		. $signed_headers . "\n"
		. $payload_hash;

	$date      = substr( $amz_date, 0, 8 );
	$scope     = $date . '/' . REGION . '/' . SERVICE . '/aws4_request';
	$to_sign   = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical );
	$signature = hash_hmac( 'sha256', $to_sign, signing_key( NEWS_R2_SECRET_ACCESS_KEY, $date ) );

	return 'AWS4-HMAC-SHA256 Credential=' . NEWS_R2_ACCESS_KEY_ID . '/' . $scope
		. ', SignedHeaders=' . $signed_headers
		. ', Signature=' . $signature;
}

function signing_key( string $secret, string $date ): string {
	$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
	$k_region  = hash_hmac( 'sha256', REGION, $k_date, true );
	$k_service = hash_hmac( 'sha256', SERVICE, $k_region, true );
	return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
}

function encode_path( string $path ): string {
	$segments = explode( '/', $path );
	$segments = array_map( 'rawurlencode', $segments );
	return implode( '/', $segments );
}

/**
 * @param list<array{url?:string}> $files
 * @return list<string>
 */
function urls_of( array $files ): array {
	$urls = [];
	foreach ( $files as $file ) {
		if ( ! empty( $file['url'] ) && is_string( $file['url'] ) ) {
			$urls[] = $file['url'];
		}
	}
	return $urls;
}

/**
 * @param list<array{path?:string,key:string,mime?:string,url?:string}> $files
 */
function remember( array $files, string $method ): void {
	$queue = retry_queue();
	foreach ( $files as $file ) {
		if ( empty( $file['key'] ) ) {
			continue;
		}
		$prev  = $queue[ $file['key'] ] ?? [];
		$tries = isset( $prev['tries'] ) ? (int) $prev['tries'] : 0;
		$queue[ $file['key'] ] = [
			'method' => $method,
			'path'   => isset( $file['path'] ) && is_string( $file['path'] ) ? $file['path'] : '',
			'key'    => $file['key'],
			'mime'   => isset( $file['mime'] ) && is_string( $file['mime'] ) ? $file['mime'] : 'application/octet-stream',
			'url'    => isset( $file['url'] ) && is_string( $file['url'] ) ? $file['url'] : '',
			'tries'  => $tries,
		];
	}
	save_queue( $queue );
}

/**
 * @param list<string> $keys
 */
function forget( array $keys ): void {
	if ( ! $keys ) {
		return;
	}
	$queue = retry_queue();
	if ( ! $queue ) {
		return;
	}
	foreach ( $keys as $key ) {
		unset( $queue[ $key ] );
	}
	if ( $queue ) {
		save_queue( $queue );
	} else {
		delete_option( OPTION_RETRY );
	}
}

/**
 * @return array<string,array{method:string,path:string,key:string,mime:string,url:string,tries:int}>
 */
function retry_queue(): array {
	$queue = get_option( OPTION_RETRY, [] );
	return is_array( $queue ) ? $queue : [];
}

/**
 * @param array<string,array<string,mixed>> $queue
 */
function save_queue( array $queue ): void {
	if ( false === get_option( OPTION_RETRY, false ) ) {
		add_option( OPTION_RETRY, $queue, '', false );
		return;
	}
	update_option( OPTION_RETRY, $queue, false );
}

/**
 * @param list<array{key:string}> $files
 */
function remember_error( array $files ): void {
	$keys = [];
	foreach ( $files as $file ) {
		if ( ! empty( $file['key'] ) ) {
			$keys[] = $file['key'];
		}
	}
	if ( ! $keys ) {
		return;
	}
	$shown = array_slice( $keys, 0, 3 );
	$extra = count( $keys ) > 3 ? ' and ' . ( count( $keys ) - 3 ) . ' more' : '';
	update_option(
		OPTION_ERROR,
		'R2 offload failed for ' . implode( ', ', $shown ) . $extra
		. '. The files are still on this server and will be retried. Readers will not see them until the upload succeeds.',
		false
	);
}

function schedule_retry(): void {
	if ( wp_next_scheduled( 'news_r2_offload_retry' ) ) {
		return;
	}
	wp_schedule_single_event( time() + 5, 'news_r2_offload_retry' );
}
