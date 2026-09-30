<?php
/**
 * Plugin Name: News Media URLs
 * Description: Rewrites public /wp-content/uploads/ URLs to the R2 custom domain. Object keys are unchanged.
 * Version:     1.0.0
 *
 * Readers, feeds, the block editor, and the media library receive
 * https://media.turkishnote.com/wp-content/uploads/...
 * Local paths stay on disk so image generation and r2-offload.php can still read them.
 * Stored post HTML is rewritten when WordPress prints or edits it, not by a database search-replace.
 *
 * Override the origin with NEWS_MEDIA_URL in wp-config.php (no trailing slash).
 */

namespace News\MediaUrls;

defined( 'ABSPATH' ) || exit;

const DEFAULT_ORIGIN = 'https://media.turkishnote.com';

add_filter( 'upload_dir', __NAMESPACE__ . '\filter_upload_dir', 9999 );
add_filter( 'wp_get_attachment_url', __NAMESPACE__ . '\rewrite_string' );
add_filter( 'wp_get_original_image_url', __NAMESPACE__ . '\rewrite_string' );
add_filter( 'wp_get_attachment_image_src', __NAMESPACE__ . '\filter_image_src' );
add_filter( 'wp_calculate_image_srcset', __NAMESPACE__ . '\filter_srcset' );
add_filter( 'wp_get_attachment_image_attributes', __NAMESPACE__ . '\filter_image_attributes', 9999 );
add_filter( 'content_url', __NAMESPACE__ . '\rewrite_string' );
add_filter( 'the_content', __NAMESPACE__ . '\rewrite_string', 11 );
add_filter( 'the_content', __NAMESPACE__ . '\rewrite_string', 9999 );
add_filter( 'the_excerpt', __NAMESPACE__ . '\rewrite_string', 11 );
add_filter( 'widget_text_content', __NAMESPACE__ . '\rewrite_string', 11 );
add_filter( 'widget_block_content', __NAMESPACE__ . '\rewrite_string', 11 );
add_filter( 'render_block', __NAMESPACE__ . '\rewrite_string', 11 );
add_filter( 'the_editor_content', __NAMESPACE__ . '\rewrite_string' );
add_filter( 'rest_pre_echo_response', __NAMESPACE__ . '\filter_rest_response', 9999 );

function media_origin(): string {
	static $resolving = false;

	$origin = DEFAULT_ORIGIN;
	if ( defined( 'NEWS_MEDIA_URL' ) && is_string( NEWS_MEDIA_URL ) && '' !== NEWS_MEDIA_URL ) {
		$origin = NEWS_MEDIA_URL;
	}
	if ( $resolving ) {
		return rtrim( $origin, '/' );
	}
	$resolving = true;
	$filtered  = apply_filters( 'news_media_origin', $origin );
	$resolving = false;
	if ( is_string( $filtered ) && '' !== $filtered ) {
		$origin = $filtered;
	}
	return rtrim( $origin, '/' );
}

/**
 * @param array<string,mixed> $uploads
 * @return array<string,mixed>
 */
function filter_upload_dir( array $uploads ): array {
	if ( ! empty( $uploads['error'] ) ) {
		return $uploads;
	}
	$base   = media_origin() . '/wp-content/uploads';
	$subdir = isset( $uploads['subdir'] ) && is_string( $uploads['subdir'] ) ? $uploads['subdir'] : '';
	$uploads['baseurl'] = $base;
	$uploads['url']     = $base . $subdir;
	return $uploads;
}

/**
 * Replace any host's /wp-content/uploads path with the R2 origin.
 * Absolute, protocol-relative, root-relative, and JSON-escaped forms.
 */
function rewrite_string( mixed $value ): mixed {
	if ( ! is_string( $value ) || '' === $value ) {
		return $value;
	}
	if ( ! str_contains( $value, 'wp-content/uploads' ) && ! str_contains( $value, 'wp-content\\/uploads' ) ) {
		return $value;
	}

	$origin        = media_origin();
	$plain_prefix  = $origin . '/wp-content/uploads';
	$escaped_prefix = str_replace( '/', '\\/', $origin ) . '\\/wp-content\\/uploads';

	$value = preg_replace_callback(
		'#https?:\\\\/\\\\/[^\\\\/"\']+\\\\/wp-content\\\\/uploads#i',
		static function () use ( $escaped_prefix ): string {
			return $escaped_prefix;
		},
		$value
	) ?? $value;

	$value = preg_replace_callback(
		'#(?:https?:)?//[^/\'"\s<>]+/wp-content/uploads#i',
		static function () use ( $plain_prefix ): string {
			return $plain_prefix;
		},
		$value
	) ?? $value;

	return preg_replace_callback(
		'#(?<=["\'\(=\s])/wp-content/uploads(?=/|["\'\s<>]|$)#',
		static function () use ( $plain_prefix ): string {
			return $plain_prefix;
		},
		$value
	) ?? $value;
}

/**
 * @param mixed $image
 * @return mixed
 */
function filter_image_src( $image ) {
	if ( ! is_array( $image ) || ! isset( $image[0] ) || ! is_string( $image[0] ) ) {
		return $image;
	}
	$image[0] = rewrite_string( $image[0] );
	return $image;
}

/**
 * @param mixed $sources
 * @return mixed
 */
function filter_srcset( $sources ) {
	if ( ! is_array( $sources ) ) {
		return $sources;
	}
	foreach ( $sources as $width => $source ) {
		if ( is_array( $source ) && isset( $source['url'] ) && is_string( $source['url'] ) ) {
			$sources[ $width ]['url'] = rewrite_string( $source['url'] );
		}
	}
	return $sources;
}

/**
 * @param array<string,string> $attr
 * @return array<string,string>
 */
function filter_image_attributes( array $attr ): array {
	foreach ( [ 'src', 'srcset', 'data-src', 'data-srcset', 'data-lazy-src', 'data-orig-file' ] as $key ) {
		if ( isset( $attr[ $key ] ) && is_string( $attr[ $key ] ) ) {
			$rewritten = rewrite_string( $attr[ $key ] );
			if ( is_string( $rewritten ) ) {
				$attr[ $key ] = $rewritten;
			}
		}
	}
	return $attr;
}

/**
 * @param mixed $result
 * @return mixed
 */
function filter_rest_response( $result ) {
	if ( ! is_array( $result ) ) {
		return $result;
	}
	return rewrite_data( $result );
}

function rewrite_data( mixed $data, ?string $key = null ): mixed {
	if ( 'guid' === $key ) {
		return $data;
	}
	if ( is_string( $data ) ) {
		return rewrite_string( $data );
	}
	if ( ! is_array( $data ) ) {
		return $data;
	}
	foreach ( $data as $child_key => $child ) {
		$data[ $child_key ] = rewrite_data( $child, is_string( $child_key ) ? $child_key : null );
	}
	return $data;
}
