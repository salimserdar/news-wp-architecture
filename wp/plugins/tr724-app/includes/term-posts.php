<?php
/**
 * Published posts in a category or tag, with page and per_page.
 *
 * per_page defaults to 10 and may be 1 through 20. Category results include
 * child categories, the same set as the category archive. Tags are exact.
 */

namespace Tr724\App;

defined( 'ABSPATH' ) || exit;

const TERM_POSTS_DEFAULT_PER_PAGE = 10;
const TERM_POSTS_MAX_PER_PAGE     = 20;

function register_term_post_routes(): void {
	$key = TR724_APP_ROUTE_KEY;
	if ( ! is_string( $key ) || '' === $key ) {
		return;
	}

	register_rest_route(
		$key,
		'/t',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\term_posts',
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
			'args'                => [
				'taxonomy' => [
					'description'       => 'category or tag.',
					'type'              => 'string',
					'required'          => true,
					'enum'              => [ 'category', 'tag' ],
					'sanitize_callback' => static function ( $value ): string {
						return sanitize_key( is_string( $value ) ? $value : '' );
					},
				],
				'slug'     => [
					'description'       => 'Term slug. Used when id is omitted or 0.',
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => static function ( $value ): string {
						return sanitize_title( is_string( $value ) ? $value : '' );
					},
				],
				'id'       => [
					'description'       => 'Term ID. Wins when both id and slug are sent.',
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
					'default'           => TERM_POSTS_DEFAULT_PER_PAGE,
					'minimum'           => 1,
					'maximum'           => TERM_POSTS_MAX_PER_PAGE,
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
function term_posts( \WP_REST_Request $request ) {
	$taxonomy = $request->get_param( 'taxonomy' );
	$taxonomy = is_string( $taxonomy ) ? $taxonomy : '';
	if ( 'category' !== $taxonomy && 'tag' !== $taxonomy ) {
		return new \WP_Error(
			'tr724_app_bad_taxonomy',
			'Taxonomy must be category or tag.',
			[ 'status' => 400 ]
		);
	}

	$term_id = (int) $request->get_param( 'id' );
	$slug    = $request->get_param( 'slug' );
	$slug    = is_string( $slug ) ? $slug : '';
	if ( $term_id < 1 && '' === $slug ) {
		return new \WP_Error(
			'tr724_app_bad_term',
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
	if ( $per_page < 1 || $per_page > TERM_POSTS_MAX_PER_PAGE ) {
		return new \WP_Error(
			'tr724_app_bad_per_page',
			'per_page must be between 1 and 20.',
			[ 'status' => 400 ]
		);
	}

	$term = term_posts_term( $taxonomy, $term_id, $slug );
	if ( is_wp_error( $term ) ) {
		return $term;
	}

	$query = term_posts_query( $term, $page, $per_page );
	$rows  = [];
	foreach ( $query->posts as $post ) {
		if ( $post instanceof \WP_Post ) {
			$rows[] = role_post_row( $post );
		}
	}
	wp_reset_postdata();

	$payload = term_payload( $term );

	return rest_ensure_response(
		[
			'taxonomy'    => $taxonomy,
			'term'        => null !== $payload ? $payload : [
				'id'   => (int) $term->term_id,
				'name' => $term->name,
				'slug' => $term->slug,
				'url'  => '',
			],
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'posts'       => $rows,
		]
	);
}

/**
 * @return \WP_Term|\WP_Error
 */
function term_posts_term( string $taxonomy, int $term_id, string $slug ) {
	$wp_taxonomy = 'tag' === $taxonomy ? 'post_tag' : 'category';
	if ( $term_id > 0 ) {
		$term = get_term( $term_id, $wp_taxonomy );
	} else {
		$term = get_term_by( 'slug', $slug, $wp_taxonomy );
	}
	if ( ! $term instanceof \WP_Term ) {
		return not_found();
	}
	return $term;
}

function term_posts_query( \WP_Term $term, int $page, int $per_page ): \WP_Query {
	return new \WP_Query(
		[
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'tax_query'           => [
				[
					'taxonomy'         => $term->taxonomy,
					'field'            => 'term_id',
					'terms'            => [ (int) $term->term_id ],
					'include_children' => 'category' === $term->taxonomy,
				],
			],
		]
	);
}
