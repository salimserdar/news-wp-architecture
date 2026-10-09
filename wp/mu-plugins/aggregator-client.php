<?php
/**
 * Plugin Name: News Aggregator Client
 * Description: Private aggregator calls from this server. Search stays on the public same-origin proxy and does not use a bearer token.
 * Version:     1.0.0
 *
 * /etc/news-wp/aggregator.env (root:www-data, mode 0640) holds AGGREGATOR_IP,
 * SYNC_TOKEN, and READ_TOKEN. The trust anchor is /etc/nginx/ssl/site-aggregator.crt.
 * The address, name, certificate, and tokens are not sent to browsers.
 */

namespace News\Aggregator;

defined( 'ABSPATH' ) || exit;

const ENV_FILE = '/etc/news-wp/aggregator.env';
const CA_FILE  = '/etc/nginx/ssl/site-aggregator.crt';
const TLS_HOST = 'aggregator.internal';

/**
 * @return array{ip:string,sync_token:string,read_token:string}
 */
function config(): array {
	static $loaded = null;
	if ( is_array( $loaded ) ) {
		return $loaded;
	}

	$loaded = [
		'ip'         => '',
		'sync_token' => '',
		'read_token' => '',
	];
	if ( ! is_readable( ENV_FILE ) ) {
		return $loaded;
	}

	$lines = file( ENV_FILE, FILE_IGNORE_NEW_LINES );
	if ( ! is_array( $lines ) ) {
		return $loaded;
	}

	foreach ( $lines as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line || str_starts_with( $line, '#' ) ) {
			continue;
		}
		$eq = strpos( $line, '=' );
		if ( false === $eq ) {
			continue;
		}
		$key   = trim( substr( $line, 0, $eq ) );
		$value = trim( substr( $line, $eq + 1 ) );
		if ( strlen( $value ) >= 2 ) {
			$quote = $value[0];
			if ( ( '"' === $quote || "'" === $quote ) && str_ends_with( $value, $quote ) ) {
				$value = substr( $value, 1, -1 );
			}
		}
		if ( 'AGGREGATOR_IP' === $key && is_ipv4( $value ) ) {
			$loaded['ip'] = $value;
		} elseif ( 'SYNC_TOKEN' === $key ) {
			$loaded['sync_token'] = $value;
		} elseif ( 'READ_TOKEN' === $key ) {
			$loaded['read_token'] = $value;
		}
	}

	return $loaded;
}

function uses_vps(): bool {
	$config = config();
	return '' !== $config['ip'] && is_readable( CA_FILE );
}

function token( string $kind ): string {
	$config = config();
	if ( 'sync' === $kind ) {
		return $config['sync_token'];
	}
	if ( 'read' === $kind ) {
		return $config['read_token'];
	}
	return '';
}

function is_ipv4( string $ip ): bool {
	return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
}

/**
 * Private routes only. Search is not accepted here, so it never gets a bearer token.
 *
 * @param array{timeout?:int,body?:string,query?:array<string,scalar>,headers?:array<string,string>} $args
 * @return array<string,mixed>|\WP_Error
 */
function request( string $method, string $path, array $args = [] ): array|\WP_Error {
	$method = strtoupper( $method );
	$kind   = private_kind( $method, $path );
	if ( null === $kind || ! uses_vps() ) {
		return new \WP_Error( 'news_aggregator_http', 'Aggregator request failed.' );
	}

	$secret = token( $kind );
	if ( '' === $secret ) {
		return new \WP_Error( 'news_aggregator_http', 'Aggregator request failed.' );
	}

	$config = config();
	$url    = 'https://' . TLS_HOST . $path;
	$query  = [];
	if ( isset( $args['query'] ) && is_array( $args['query'] ) ) {
		foreach ( $args['query'] as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			if ( ! is_string( $value ) && ! is_int( $value ) ) {
				continue;
			}
			$query[ $key ] = (string) $value;
		}
	}
	if ( [] !== $query ) {
		$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
	}

	$headers = [
		'Accept'        => 'application/json',
		'Authorization' => 'Bearer ' . $secret,
	];
	if ( isset( $args['headers'] ) && is_array( $args['headers'] ) ) {
		foreach ( $args['headers'] as $name => $value ) {
			if ( ! is_string( $name ) || ! is_string( $value ) ) {
				continue;
			}
			if ( 0 === strcasecmp( $name, 'Authorization' ) || 0 === strcasecmp( $name, 'Host' ) ) {
				continue;
			}
			$headers[ $name ] = $value;
		}
	}

	$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : 8;
	if ( $timeout < 1 ) {
		$timeout = 1;
	}
	if ( $timeout > 30 ) {
		$timeout = 30;
	}

	$body = null;
	if ( isset( $args['body'] ) && is_string( $args['body'] ) && 'GET' !== $method && 'HEAD' !== $method ) {
		$body = $args['body'];
	}

	return curl_exchange( $method, $url, $headers, $body, $timeout, $config['ip'] );
}

function private_kind( string $method, string $path ): ?string {
	$map = [
		'POST /api/v1/sync/posts'    => 'sync',
		'POST /api/v1/sync/media'    => 'sync',
		'GET /api/v1/exchange-rates' => 'read',
		'GET /api/v1/youtube/videos' => 'read',
		'GET /api/v1/popular-posts'  => 'read',
		'POST /api/v1/cache/purge'   => 'read',
	];
	return $map[ $method . ' ' . $path ] ?? null;
}

/**
 * Pinned TLS call. Upstream response headers are dropped so they cannot be printed later.
 *
 * @param array<string,string> $headers
 * @return array<string,mixed>|\WP_Error
 */
function curl_exchange( string $method, string $url, array $headers, ?string $body, int $timeout, string $ip ): array|\WP_Error {
	if ( ! function_exists( 'curl_init' ) || ! is_ipv4( $ip ) ) {
		return new \WP_Error( 'news_aggregator_http', 'Aggregator request failed.' );
	}

	$handle = curl_init( $url );
	if ( false === $handle ) {
		return new \WP_Error( 'news_aggregator_http', 'Aggregator request failed.' );
	}

	$lines = [];
	foreach ( $headers as $name => $value ) {
		$lines[] = $name . ': ' . $value;
	}

	$options = [
		CURLOPT_CUSTOMREQUEST  => $method,
		CURLOPT_HTTPHEADER     => $lines,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER         => false,
		CURLOPT_TIMEOUT        => $timeout,
		CURLOPT_CONNECTTIMEOUT => min( 5, $timeout ),
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_CAINFO         => CA_FILE,
		CURLOPT_RESOLVE        => [ TLS_HOST . ':443:' . $ip ],
		CURLOPT_FOLLOWLOCATION => false,
	];
	if ( defined( 'CURLOPT_PROTOCOLS' ) && defined( 'CURLPROTO_HTTPS' ) ) {
		$options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTPS;
	}

	if ( ! curl_setopt_array( $handle, $options ) ) {
		curl_close( $handle );
		return new \WP_Error( 'news_aggregator_http', 'Aggregator request failed.' );
	}
	if ( null !== $body && ! curl_setopt( $handle, CURLOPT_POSTFIELDS, $body ) ) {
		curl_close( $handle );
		return new \WP_Error( 'news_aggregator_http', 'Aggregator request failed.' );
	}

	$raw = curl_exec( $handle );
	if ( ! is_string( $raw ) ) {
		curl_close( $handle );
		return new \WP_Error( 'news_aggregator_http', 'Aggregator request failed.' );
	}

	$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
	curl_close( $handle );

	return [
		'headers'  => [],
		'body'     => $raw,
		'response' => [
			'code'    => $status,
			'message' => '',
		],
		'cookies'  => [],
		'filename' => null,
	];
}
