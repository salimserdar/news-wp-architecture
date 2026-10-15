<?php
/**
 * Plugin Name: News Single Image
 * Description: Non-administrators: a featured image must be a 16:9 JPEG, PNG, or WebP of at least 1280×720, stored as one 1280×720 WebP. An image uploaded into the article may use any aspect ratio, but it cannot be wider than 1280px, and it is stored as one WebP. Administrators keep the normal sizes.
 * Version:     1.1.0
 */

namespace News\SingleImage;

defined( 'ABSPATH' ) || exit;

const WIDTH      = 1280;
const HEIGHT     = 720;
const RATIO_SLACK = 0.02;
const META_KEY   = '_news_single_image';
const MSG_TYPE   = 'Görsel JPEG, PNG veya WebP olmalı.';
const MSG_RATIO  = 'Görsel 16:9 olmalı.';
const MSG_SIZE   = 'Görsel en az 1280×720 olmalı.';
const MSG_WIDTH  = 'Görsel en fazla 1280 px genişliğinde olmalı.';
const MSG_SAVE   = 'Görsel WebP olarak kaydedilemedi.';

add_filter( 'wp_handle_upload_prefilter', __NAMESPACE__ . '\reject_wrong_picture' );
add_filter( 'wp_handle_upload', __NAMESPACE__ . '\store_single_webp' );
add_action( 'add_attachment', __NAMESPACE__ . '\mark_attachment', 0 );
add_filter( 'intermediate_image_sizes_advanced', __NAMESPACE__ . '\skip_subsizes', 999, 3 );
add_action( 'rest_api_init', __NAMESPACE__ . '\register_featured_guard' );
add_filter( 'add_post_metadata', __NAMESPACE__ . '\block_bad_thumbnail', 10, 4 );
add_filter( 'update_post_metadata', __NAMESPACE__ . '\block_bad_thumbnail', 10, 4 );
add_action( 'wp_ajax_set-post-thumbnail', __NAMESPACE__ . '\ajax_guard_featured', 0 );
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_editor_script' );
add_action( 'wp_enqueue_media', __NAMESPACE__ . '\enqueue_editor_script' );

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
	$reason = upload_error( $picture['mime'], $picture['width'], $picture['height'] );
	if ( '' !== $reason ) {
		$file['error'] = $reason;
	}
	return $file;
}

/**
 * Replace an accepted upload with one WebP and drop the file the editor sent.
 * Featured images are cropped to 1280×720. Article images keep their size.
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

	$saved = write_single_webp( $path, is_featured_upload() );
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

/**
 * Featured-image uploads keep the 16:9 rule. Anything else is an article image.
 * Missing or unknown context stays on the stricter rule.
 */
function is_featured_upload(): bool {
	$use = '';
	if ( isset( $_POST['news_image_use'] ) ) {
		$use = wp_unslash( $_POST['news_image_use'] );
	}
	return ! ( is_string( $use ) && 'content' === $use );
}

function upload_error( string $mime, int $width, int $height ): string {
	if ( is_featured_upload() ) {
		return picture_error( $mime, $width, $height );
	}
	return content_picture_error( $mime, $width, $height );
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
 * Article images may use any aspect ratio. Width is the only size limit.
 */
function content_picture_error( string $mime, int $width, int $height ): string {
	if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp' ], true ) ) {
		return MSG_TYPE;
	}
	if ( $width < 1 || $height < 1 ) {
		return MSG_SAVE;
	}
	if ( $width > WIDTH ) {
		return MSG_WIDTH;
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
function write_single_webp( string $path, bool $featured ): string|\WP_Error {
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
	// get_mime_type() is protected on WP_Image_Editor; calling it fatals the upload.
	$mime   = wp_get_image_mime( $path );
	$mime   = is_string( $mime ) ? $mime : '';
	$reason = $featured ? picture_error( $mime, $width, $height ) : content_picture_error( $mime, $width, $height );
	if ( '' !== $reason ) {
		return new \WP_Error( 'news_single_image', $reason );
	}
	$ready = $featured
		? ( WIDTH === $width && HEIGHT === $height && 'image/webp' === $mime )
		: ( 'image/webp' === $mime );
	if ( $ready ) {
		return $path;
	}

	if ( $featured ) {
		$resized = $editor->resize( WIDTH, HEIGHT, true );
		if ( is_wp_error( $resized ) ) {
			return $resized;
		}
	}

	$dir       = dirname( $path );
	$stem      = pathinfo( $path, PATHINFO_FILENAME );
	$preferred = $dir . '/' . $stem . '.webp';
	$write     = $preferred;
	if ( $write === $path || is_file( $write ) ) {
		$write = $dir . '/' . wp_unique_filename( $dir, $stem . '-single.webp' );
	}
	$editor->set_quality( 90 );
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

/**
 * Tell the editor which upload is the featured image. Article uploads send news_image_use=content.
 */
function enqueue_editor_script(): void {
	if ( is_exempt() ) {
		return;
	}
	wp_enqueue_script(
		'news-single-image',
		plugins_url( 'single-image.js', __FILE__ ),
		[ 'wp-api-fetch', 'wp-plupload' ],
		'1.1.0',
		true
	);
}

function register_featured_guard(): void {
	foreach ( get_post_types( [ 'show_in_rest' => true ] ) as $type ) {
		if ( 'attachment' === $type ) {
			continue;
		}
		add_filter( "rest_pre_insert_{$type}", __NAMESPACE__ . '\reject_bad_featured_media', 10, 2 );
	}
}

/**
 * Picking an article image from the library must not bypass the featured-image rule.
 * An image that is already the featured image stays, so older posts can still be saved.
 *
 * @param mixed $prepared
 * @return mixed
 */
function reject_bad_featured_media( $prepared, \WP_REST_Request $request ) {
	if ( is_exempt() || ! $request->has_param( 'featured_media' ) ) {
		return $prepared;
	}
	$media_id = (int) $request->get_param( 'featured_media' );
	if ( $media_id <= 0 ) {
		return $prepared;
	}
	$post_id = (int) $request->get_param( 'id' );
	if ( $post_id > 0 && (int) get_post_thumbnail_id( $post_id ) === $media_id ) {
		return $prepared;
	}
	$reason = featured_assignment_error( $media_id );
	if ( '' === $reason ) {
		return $prepared;
	}
	return new \WP_Error( 'news_single_image', $reason, [ 'status' => 400 ] );
}

/**
 * @param mixed  $check
 * @param int    $object_id
 * @param string $meta_key
 * @param mixed  $meta_value
 * @return mixed
 */
function block_bad_thumbnail( $check, $object_id, $meta_key, $meta_value ) {
	if ( null !== $check || '_thumbnail_id' !== $meta_key || is_exempt() ) {
		return $check;
	}
	$attachment_id = (int) $meta_value;
	if ( $attachment_id <= 0 ) {
		return $check;
	}
	if ( (int) get_post_meta( (int) $object_id, '_thumbnail_id', true ) === $attachment_id ) {
		return $check;
	}
	if ( '' === featured_assignment_error( $attachment_id ) ) {
		return $check;
	}
	return false;
}

function ajax_guard_featured(): void {
	if ( is_exempt() ) {
		return;
	}
	$post_id      = isset( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;
	$thumbnail_id = isset( $_REQUEST['thumbnail_id'] ) ? (int) $_REQUEST['thumbnail_id'] : 0;
	if ( $post_id <= 0 || $thumbnail_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( (int) get_post_thumbnail_id( $post_id ) === $thumbnail_id ) {
		return;
	}
	$reason = featured_assignment_error( $thumbnail_id );
	if ( '' === $reason ) {
		return;
	}
	wp_die( esc_html( $reason ) );
}

function featured_assignment_error( int $attachment_id ): string {
	$meta   = wp_get_attachment_metadata( $attachment_id );
	$width  = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
	$height = is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0;
	$mime   = (string) get_post_mime_type( $attachment_id );
	if ( $width < 1 || $height < 1 ) {
		$path = get_attached_file( $attachment_id );
		if ( is_string( $path ) && is_file( $path ) ) {
			$picture = read_picture( $path );
			if ( null !== $picture ) {
				$width  = $picture['width'];
				$height = $picture['height'];
				$mime   = $picture['mime'];
			}
		}
	}
	return picture_error( $mime, $width, $height );
}
