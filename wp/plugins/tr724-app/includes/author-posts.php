<?php
/**
 * One author's profile and published posts, for the author profile screen.
 *
 * Same card as the role and term lists. per_page defaults to 10, matching
 * the author archive, and may be 1 through 20. Any user can be requested:
 * the profile page exists for editors as well as columnists. A page past
 * the end is still 200, with an empty posts array.
 */

namespace Tr724\App;

defined( 'ABSPATH' ) || exit;

const AUTHOR_POSTS_DEFAULT_PER_PAGE = 10;
const AUTHOR_POSTS_MAX_PER_PAGE     = 20;

function register_author_post_routes(): void {
	$key = TR724_APP_ROUTE_KEY;
	if ( ! is_string( $key ) || '' === $key ) {
		return;
	}

	register_rest_route(
		$key,
		'/a',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\author_posts',
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
			'args'                => [
				'slug'     => [
					'description'       => 'User nicename. Used when id is omitted or 0.',
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => static function ( $value ): string {
						return sanitize_title( is_string( $value ) ? $value : '' );
					},
				],
				'id'       => [
					'description'       => 'User ID. Wins when both id and slug are sent.',
					'type'              => 'integer',
					'default'           => 0,
					'minimum'           => 0,
					'sanitize_callback' => static function ( $value ): int {
						return (int) $value;
					},
				],
				'page'     => [
					'description'       => 'Page number. Defaults to 1.',
					'type'              => 'integer',
					'default'           => 1,
					'minimum'           => 1,
					'sanitize_callback' => static function ( $value ): int {
						return (int) $value;
					},
				],
				'per_page' => [
					'description'       => 'Posts on this page. Defaults to 10. Maximum 20.',
					'type'              => 'integer',
					'default'           => AUTHOR_POSTS_DEFAULT_PER_PAGE,
					'minimum'           => 1,
					'maximum'           => AUTHOR_POSTS_MAX_PER_PAGE,
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
function author_posts( \WP_REST_Request $request ) {
	$user_id = (int) $request->get_param( 'id' );
	$slug    = $request->get_param( 'slug' );
	$slug    = is_string( $slug ) ? $slug : '';
	if ( $user_id < 1 && '' === $slug ) {
		return new \WP_Error(
			'tr724_app_bad_author',
			'Provide a slug or id.',
			[ 'status' => 400 ]
		);
	}

	$page = (int) $request->get_param( 'page' );
	if ( $page < 1 ) {
		return new \WP_Error(
			'tr724_app_bad_page',
			'Page must be 1 or greater.',
			[ 'status' => 400 ]
		);
	}

	$per_page = (int) $request->get_param( 'per_page' );
	if ( $per_page < 1 || $per_page > AUTHOR_POSTS_MAX_PER_PAGE ) {
		return new \WP_Error(
			'tr724_app_bad_per_page',
			'per_page must be between 1 and 20.',
			[ 'status' => 400 ]
		);
	}

	$user = author_posts_user( $user_id, $slug );
	if ( is_wp_error( $user ) ) {
		return $user;
	}

	$query = author_posts_query( (int) $user->ID, $page, $per_page );
	$rows  = [];
	foreach ( $query->posts as $post ) {
		if ( $post instanceof \WP_Post ) {
			$rows[] = role_post_row( $post );
		}
	}
	wp_reset_postdata();

	return rest_ensure_response(
		[
			'author'      => author_profile_row( $user ),
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'posts'       => $rows,
		]
	);
}

/**
 * @return \WP_User|\WP_Error
 */
function author_posts_user( int $user_id, string $slug ) {
	if ( $user_id > 0 ) {
		$user = get_userdata( $user_id );
	} else {
		$user = get_user_by( 'slug', $slug );
	}
	if ( ! $user instanceof \WP_User ) {
		return not_found();
	}
	return $user;
}

function author_posts_query( int $user_id, int $page, int $per_page ): \WP_Query {
	return new \WP_Query(
		[
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'author'              => $user_id,
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
		]
	);
}

/**
 * Writer card plus the biography shown on the profile page.
 *
 * @return array{id: int, slug: string, name: string, url: string, avatar: string, email: string, kind: string, social: array{facebook: string, x: string, youtube: string}, bio: string}
 */
function author_profile_row( \WP_User $user ): array {
	$row        = author_list_row( $user );
	$row['bio'] = trim( $user->description );
	return $row;
}
