<?php
/**
 * Manşet spotlight for the mobile app.
 *
 * Same source and order as the Spotlight block: the Editorial → Manşet queue.
 * The extra slide image and title win over the post's own image and title.
 */

namespace Tr724\App;

defined( 'ABSPATH' ) || exit;

function register_manset_routes(): void {
	$key = TR724_APP_ROUTE_KEY;
	if ( ! is_string( $key ) || '' === $key ) {
		return;
	}

	register_rest_route(
		$key,
		'/m',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\manset_spotlight',
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
		]
	);
}

/**
 * @return \WP_REST_Response
 */
function manset_spotlight(): \WP_REST_Response {
	$posts = [];
	if ( function_exists( 'tr724_editorial_get_manset' ) ) {
		$posts = tr724_editorial_get_manset();
	}

	$rows = [];
	foreach ( $posts as $post ) {
		if ( $post instanceof \WP_Post ) {
			$rows[] = manset_slide( $post );
		}
	}

	return rest_ensure_response(
		[
			'posts' => $rows,
		]
	);
}

/**
 * @return array{id: int, slug: string, url: string, title: string, label: string, upper_title: string, hide_title: bool, image: array{url: string, alt: string}}
 */
function manset_slide( \WP_Post $post ): array {
	$post_id     = (int) $post->ID;
	$extra_title = function_exists( 'tr724_additional_title' ) ? tr724_additional_title( $post_id ) : '';
	$title       = manset_text( '' !== $extra_title ? $extra_title : get_the_title( $post ) );

	return [
		'id'          => $post_id,
		'slug'        => $post->post_name,
		'url'         => (string) get_permalink( $post ),
		'title'       => $title,
		'label'       => manset_text( function_exists( 'tr724_post_label' ) ? tr724_post_label( $post_id ) : '' ),
		'upper_title' => manset_text( function_exists( 'tr724_upper_title' ) ? tr724_upper_title( $post_id ) : '' ),
		'hide_title'  => function_exists( 'tr724_hide_title' ) && tr724_hide_title( $post_id ),
		'image'       => manset_image( $post_id, $title ),
	];
}

/**
 * Spotlight image: the extra photo, otherwise the featured image.
 *
 * @return array{url: string, alt: string}
 */
function manset_image( int $post_id, string $title ): array {
	$attachment_id = 0;
	if ( function_exists( 'tr724_additional_image_id' ) ) {
		$attachment_id = (int) tr724_additional_image_id( $post_id );
	}
	if ( $attachment_id <= 0 ) {
		$attachment_id = (int) get_post_thumbnail_id( $post_id );
	}
	if ( $attachment_id <= 0 ) {
		return [
			'url' => '',
			'alt' => '',
		];
	}

	$url = wp_get_original_image_url( $attachment_id );
	if ( ! is_string( $url ) || '' === $url ) {
		$url = (string) wp_get_attachment_url( $attachment_id );
	}

	return [
		'url' => $url,
		'alt' => $title,
	];
}

function manset_text( string $text ): string {
	return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
}
