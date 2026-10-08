<?php
/**
 * Plugin Name: News Single Image
 * Description: Non-administrators must upload a 16:9 JPEG, PNG, or WebP of at least 640×360. It is stored once, as a 640×360 WebP. Administrators keep the normal sizes.
 * Version:     1.0.0
 */

namespace News\SingleImage;

defined( 'ABSPATH' ) || exit;

const WIDTH      = 640;
const HEIGHT     = 360;
const RATIO_SLACK = 0.02;
const META_KEY   = '_news_single_image';
const MSG_TYPE   = 'Görsel JPEG, PNG veya WebP olmalı.';
const MSG_RATIO  = 'Görsel 16:9 olmalı.';
const MSG_SIZE   = 'Görsel en az 640×360 olmalı.';
const MSG_SAVE   = 'Görsel 640×360 WebP olarak kaydedilemedi.';

add_filter( 'wp_handle_upload_prefilter', __NAMESPACE__ . '\reject_wrong_picture' );
add_filter( 'wp_handle_upload', __NAMESPACE__ . '\store_single_webp' );
add_action( 'add_attachment', __NAMESPACE__ . '\mark_attachment', 0 );
add_filter( 'intermediate_image_sizes_advanced', __NAMESPACE__ . '\skip_subsizes', 999, 3 );

/**
 * Administrators keep the original file and the usual generated sizes.
 */
function is_exempt(): bool {
	return current_user_can( 'manage_options' );
}

/**
 * @param array<string,mixed> $file
 * @return array<string,mixed>
 */
function reject_wrong_picture( array $file ): array {
	if ( is_exempt() || has_upload_error( $file ) ) {
		return $file;
	}
	$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
	if ( '' === $tmp || ! is_file( $tmp ) ) {
		return $file;
	}
	$picture = read_picture( $tmp );
	if ( null === $picture ) {
		return $file;
	}
	$reason = picture_error( $picture['mime'], $picture['width'], $picture['height'] );
	if ( '' !== $reason ) {
		$file['error'] = $reason;
	}
	return $file;
}

/**
 * Replace an accepted upload with one 640×360 WebP and drop the file the editor sent.
 *
 * @param array<string,mixed> $upload
 * @return array<string,mixed>
 */
function store_single_webp( array $upload ): array {
	if ( is_exempt() || has_upload_error( $upload ) ) {
		return $upload;
	}
	$path = isset( $upload['file'] ) ? (string) $upload['file'] : '';
	if ( '' === $path || ! is_file( $path ) ) {
		return $upload;
	}
	$picture = read_picture( $path );
	if ( null === $picture ) {
		return $upload;
	}

	$saved = write_single_webp( $path );
	if ( is_wp_error( $saved ) ) {
		wp_delete_file( $path );
		return [ 'error' => failure_message( $saved ) ];
	}
	if ( $saved !== $path && is_file( $path ) ) {
		wp_delete_file( $path );
	}

	$GLOBALS['news_single_image'] = true;
	$url = isset( $upload['url'] ) ? (string) $upload['url'] : '';
	$upload['file'] = $saved;
	$upload['type'] = 'image/webp';
	if ( '' !== $url ) {
		$upload['url'] = trailingslashit( dirname( $url ) ) . wp_basename( $saved );
	}
	return $upload;
}

function mark_attachment( int $attachment_id ): void {
	if ( empty( $GLOBALS['news_single_image'] ) ) {
		return;
	}
	$GLOBALS['news_single_image'] = false;
	if ( 'image/webp' !== get_post_mime_type( $attachment_id ) ) {
		return;
	}
	update_post_meta( $attachment_id, META_KEY, '1' );
}

/**
 * The marked file is the only copy. Later thumbnail regeneration must not add sizes back.
 *
 * @param array<string,array<string,mixed>> $sizes
 * @param array<string,mixed>               $metadata
 * @return array<string,array<string,mixed>>
 */
function skip_subsizes( array $sizes, array $metadata, int $attachment_id ): array {
	if ( '1' === (string) get_post_meta( $attachment_id, META_KEY, true ) ) {
		return [];
	}
	return $sizes;
}

function picture_error( string $mime, int $width, int $height ): string {
	if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
		return MSG_TYPE;
	}
	if ( $width < 1 || $height < 1 ) {
		return MSG_SAVE;
	}
	$target = 16 / 9;
	if ( abs( ( $width / $height ) - $target ) > RATIO_SLACK ) {
		return MSG_RATIO;
	}
	if ( $width < WIDTH || $height < HEIGHT ) {
		return MSG_SIZE;
	}
	return '';
}

/**
 * Width and height after the EXIF orientation a phone photo is displayed with.
 *
 * @return array{mime:string,width:int,height:int}|null
 */
function read_picture( string $path ): ?array {
	$info = @getimagesize( $path );
	if ( ! is_array( $info ) ) {
		return null;
	}
	$width  = (int) $info[0];
	$height = (int) $info[1];
	$mime   = isset( $info['mime'] ) ? (string) $info['mime'] : '';
	if ( 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) ) {
		$exif        = @exif_read_data( $path );
		$orientation = is_array( $exif ) ? (int) ( $exif['Orientation'] ?? 1 ) : 1;
		if ( in_array( $orientation, [ 5, 6, 7, 8 ], true ) ) {
			[ $width, $height ] = [ $height, $width ];
		}
	}
	return [
		'mime'   => $mime,
		'width'  => $width,
		'height' => $height,
	];
}

/**
 * @return string|\WP_Error Final path. The source file is removed when a new file replaces it.
 */
function write_single_webp( string $path ): string|\WP_Error {
	$editor = wp_get_image_editor( $path );
	if ( is_wp_error( $editor ) ) {
		return $editor;
	}
	if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
		$rotated = $editor->maybe_exif_rotate();
		if ( is_wp_error( $rotated ) ) {
			return $rotated;
		}
	}

	$size   = $editor->get_size();
	$width  = (int) ( $size['width'] ?? 0 );
	$height = (int) ( $size['height'] ?? 0 );
	$mime   = method_exists( $editor, 'get_mime_type' ) ? (string) $editor->get_mime_type() : '';
	$reason = picture_error( '' !== $mime ? $mime : 'image/jpeg', $width, $height );
	if ( '' !== $reason ) {
		return new \WP_Error( 'news_single_image', $reason );
	}
	if ( WIDTH === $width && HEIGHT === $height && 'image/webp' === $mime ) {
		return $path;
	}

	$resized = $editor->resize( WIDTH, HEIGHT, true );
	if ( is_wp_error( $resized ) ) {
		return $resized;
	}

	$dir       = dirname( $path );
	$stem      = pathinfo( $path, PATHINFO_FILENAME );
	$preferred = $dir . '/' . $stem . '.webp';
	$write     = $preferred;
	if ( $write === $path || is_file( $write ) ) {
		$write = $dir . '/' . wp_unique_filename( $dir, $stem . '-single.webp' );
	}
	$saved = $editor->save( $write, 'image/webp' );
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_string( $saved['path'] ) ) {
		if ( is_file( $write ) && $write !== $path ) {
			wp_delete_file( $write );
		}
		return is_wp_error( $saved ) ? $saved : new \WP_Error( 'news_single_image', MSG_SAVE );
	}
	$saved_path = $saved['path'];
	if ( ! is_file( $saved_path ) || 0 === (int) filesize( $saved_path ) ) {
		if ( is_file( $saved_path ) ) {
			wp_delete_file( $saved_path );
		}
		return new \WP_Error( 'news_single_image', MSG_SAVE );
	}
	if ( $saved_path !== $path && is_file( $path ) ) {
		wp_delete_file( $path );
	}
	if ( $saved_path !== $preferred && ! is_file( $preferred ) && rename( $saved_path, $preferred ) ) {
		return $preferred;
	}
	return $saved_path;
}

/**
 * @param array<string,mixed> $file
 */
function has_upload_error( array $file ): bool {
	return isset( $file['error'] ) && is_string( $file['error'] ) && '' !== $file['error'];
}

function failure_message( \WP_Error $error ): string {
	error_log( '[news-single-image] ' . $error->get_error_message() );
	if ( 'news_single_image' === $error->get_error_code() ) {
		return $error->get_error_message();
	}
	return MSG_SAVE;
}
