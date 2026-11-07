<?php
/**
 * Latest published posts written by users in a role.
 *
 * Defaults to the Author role and 8 posts. count may be 1 through 20.
 */

namespace Tr724\App;

defined( 'ABSPATH' ) || exit;

const ROLE_POSTS_DEFAULT_ROLE  = 'author';
const ROLE_POSTS_DEFAULT_COUNT = 8;
const ROLE_POSTS_MAX_COUNT     = 20;

function register_role_post_routes(): void {
	$key = TR724_APP_ROUTE_KEY;
	if ( ! is_string( $key ) || '' === $key ) {
		return;
	}

	register_rest_route(
		$key,
		'/p',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\role_posts',
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
			'args'                => [
				'role'  => [
					'description'       => 'WordPress role slug. Defaults to author.',
					'type'              => 'string',
					'default'           => ROLE_POSTS_DEFAULT_ROLE,
					'sanitize_callback' => static function ( $value ): string {
						return sanitize_key( is_string( $value ) ? $value : '' );
					},
				],
				'count' => [
					'description'       => 'How many posts to return. Defaults to 8. Maximum 20.',
					'type'              => 'integer',
					'default'           => ROLE_POSTS_DEFAULT_COUNT,
					'minimum'           => 1,
					'maximum'           => ROLE_POSTS_MAX_COUNT,
					'sanitize_callback' => static function ( $value ): int {
						return (int) $value;
					},
				],
			],
		]
	);
}

/**
 * @return \WP_REST_Response|\WP_Error
 */
function role_posts( \WP_REST_Request $request ) {
	$role = $request->get_param( 'role' );
	$role = is_string( $role ) ? $role : '';
	if ( '' === $role || ! get_role( $role ) ) {
		return new \WP_Error(
			'tr724_app_bad_role',
			'Unknown role.',
			[ 'status' => 400 ]
		);
	}

	$count = (int) $request->get_param( 'count' );
	if ( $count < 1 || $count > ROLE_POSTS_MAX_COUNT ) {
		return new \WP_Error(
			'tr724_app_bad_count',
			'Count must be between 1 and 20.',
			[ 'status' => 400 ]
		);
	}

	return rest_ensure_response(
		[
			'role'  => $role,
			'count' => $count,
			'posts' => role_post_rows( $role, $count ),
		]
	);
}

/**
 * @return array<int, array<string, mixed>>
 */
function role_post_rows( string $role, int $count ): array {
	$user_ids = get_users(
		[
			'role'   => $role,
			'fields' => 'ID',
		]
	);
	$user_ids = array_values( array_filter( array_map( 'intval', (array) $user_ids ) ) );
	if ( ! $user_ids ) {
		return [];
	}

	$query = new \WP_Query(
		[
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'author__in'          => $user_ids,
			'posts_per_page'      => $count,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		]
	);

	$rows = [];
	foreach ( $query->posts as $post ) {
		if ( $post instanceof \WP_Post ) {
			$rows[] = role_post_row( $post );
		}
	}
	wp_reset_postdata();
	return $rows;
}

/**
 * @return array<string, mixed>
 */
function role_post_row( \WP_Post $post ): array {
	$title     = get_the_title( $post );
	$excerpt   = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
	$published = get_post_time( DATE_W3C, true, $post );

	return [
		'id'           => (int) $post->ID,
		'slug'         => $post->post_name,
		'url'          => (string) get_permalink( $post ),
		'title'        => $title,
		'excerpt'      => is_string( $excerpt ) ? $excerpt : '',
		'published_at' => is_string( $published ) ? $published : '',
		'image'        => role_post_image( $post, $title ),
		'author'       => author_payload( $post ),
	];
}

/**
 * @return array{url: string, alt: string}
 */
function role_post_image( \WP_Post $post, string $title ): array {
	$thumb_id = (int) get_post_thumbnail_id( $post );
	if ( $thumb_id <= 0 ) {
		return [
			'url' => '',
			'alt' => '',
		];
	}

	$url = wp_get_attachment_image_url( $thumb_id, 'large' );
	if ( ! is_string( $url ) || '' === $url ) {
		$url = (string) wp_get_attachment_url( $thumb_id );
	}
	$alt = (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
	if ( '' === $alt ) {
		$alt = $title;
	}

	return [
		'url' => $url,
		'alt' => $alt,
	];
}
