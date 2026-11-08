<?php
/**
 * Cache headers and purge queue for the app post detail routes.
 *
 * Anonymous REST GETs are microcached for 10 seconds by cache-control.php.
 * Article detail, the author list, and the role post list use the page TTL.
 * Detail is purged with the article. The author list is purged when a user
 * account changes. The role list is purged for that author's roles. The manset
 * list is purged when the editorial queue or a listed post changes. The
 * category and tag list stays on the 10-second microcache: page and per_page
 * combinations are too many to purge when a post is published.
 */

namespace Tr724\App;

defined( 'ABSPATH' ) || exit;

const DETAIL_TTL = 600;

/**
 * Route matches GET /{key}/d or GET /{key}/d/{id}. The key is not repeated here.
 */
function is_detail_route( string $route ): bool {
	$base = '/' . TR724_APP_ROUTE_KEY . '/d';
	if ( $route === $base ) {
		return true;
	}
	return 1 === preg_match( '#^' . preg_quote( $base, '#' ) . '/\d+$#', $route );
}

function register_cache(): void {
	add_filter( 'rest_post_dispatch', __NAMESPACE__ . '\cache_headers', 20, 3 );
	add_action( 'transition_post_status', __NAMESPACE__ . '\queue_detail_purge', 20, 3 );
	add_action( 'profile_update', __NAMESPACE__ . '\queue_authors_purge' );
	add_action( 'user_register', __NAMESPACE__ . '\queue_authors_purge' );
	add_action( 'delete_user', __NAMESPACE__ . '\queue_authors_purge' );
	add_action( 'set_user_role', __NAMESPACE__ . '\queue_authors_purge' );
	add_action( 'init', __NAMESPACE__ . '\register_manset_purge' );
}

/**
 * Route matches GET /{key}/w. The key is not repeated here.
 */
function is_authors_route( string $route ): bool {
	return $route === '/' . TR724_APP_ROUTE_KEY . '/w';
}

/**
 * Route matches GET /{key}/p. The key is not repeated here.
 */
function is_role_posts_route( string $route ): bool {
	return $route === '/' . TR724_APP_ROUTE_KEY . '/p';
}

function is_cached_app_route( string $route ): bool {
	return is_detail_route( $route ) || is_authors_route( $route ) || is_role_posts_route( $route ) || is_manset_route( $route );
}

/**
 * Route matches GET /{key}/m. The key is not repeated here.
 */
function is_manset_route( string $route ): bool {
	return $route === '/' . TR724_APP_ROUTE_KEY . '/m';
}

/**
 * The option name comes from the theme, which loads after this plugin.
 */
function register_manset_purge(): void {
	$option = function_exists( 'tr724_editorial_manset_option' ) ? tr724_editorial_manset_option() : 'tr724_editorial_manset';
	add_action( 'update_option_' . $option, __NAMESPACE__ . '\queue_manset_purge' );
}

/**
 * @param mixed $response Response.
 * @param mixed $server   Server.
 * @param mixed $request  Request.
 * @return mixed
 */
function cache_headers( $response, $server, $request ) {
	unset( $server );
	if ( ! $response instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request ) {
		return $response;
	}
	if ( 'GET' !== $request->get_method() || is_user_logged_in() ) {
		return $response;
	}
	$route = $request->get_route();
	if ( 200 !== $response->get_status() || ! is_cached_app_route( $route ) ) {
		return $response;
	}

	$ttl = DETAIL_TTL;
	if ( defined( 'News\CacheControl\TTL_PAGE' ) ) {
		$ttl = (int) \News\CacheControl\TTL_PAGE;
	}
	$response->header( 'X-Accel-Expires', (string) $ttl );
	$response->header( 'Cache-Control', sprintf( 'public, max-age=0, s-maxage=%d', $ttl ) );
	return $response;
}

/**
 * Purge the canonical detail URL when a post enters or leaves publish.
 */
function queue_detail_purge( string $new, string $old, \WP_Post $post ): void {
	if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
		return;
	}
	if ( 'post' !== $post->post_type ) {
		return;
	}
	if ( 'publish' !== $new && 'publish' !== $old ) {
		return;
	}
	if ( ! function_exists( 'News\CachePurge\queue_urls' ) ) {
		return;
	}

	$urls = [
		rest_url( TR724_APP_ROUTE_KEY . '/d/' . $post->ID ),
		rest_url( TR724_APP_ROUTE_KEY . '/m' ),
	];
	$user = get_userdata( (int) $post->post_author );
	if ( $user instanceof \WP_User ) {
		foreach ( (array) $user->roles as $role ) {
			if ( is_string( $role ) && '' !== $role ) {
				$urls = array_merge( $urls, role_posts_purge_urls( $role ) );
			}
		}
	}

	\News\CachePurge\queue_urls( $urls );
}

/**
 * URLs for one role's latest-post list, including every allowed count.
 *
 * @return string[]
 */
function role_posts_purge_urls( string $role ): array {
	$base = rest_url( TR724_APP_ROUTE_KEY . '/p' );
	$urls = [
		$base,
		add_query_arg( 'role', $role, $base ),
	];
	for ( $count = 1; $count <= ROLE_POSTS_MAX_COUNT; $count++ ) {
		$urls[] = add_query_arg(
			[
				'role'  => $role,
				'count' => $count,
			],
			$base
		);
		if ( ROLE_POSTS_DEFAULT_ROLE === $role ) {
			$urls[] = add_query_arg( 'count', $count, $base );
		}
	}
	return $urls;
}

/**
 * Purge the author list when an account is created, edited, deleted, or its role changes.
 */
function queue_authors_purge(): void {
	if ( ! function_exists( 'News\CachePurge\queue_urls' ) ) {
		return;
	}

	\News\CachePurge\queue_urls(
		[
			rest_url( TR724_APP_ROUTE_KEY . '/w' ),
		]
	);
}

/**
 * Purge the manset spotlight when the editorial queue is saved.
 */
function queue_manset_purge(): void {
	if ( ! function_exists( 'News\CachePurge\queue_urls' ) ) {
		return;
	}

	\News\CachePurge\queue_urls(
		[
			rest_url( TR724_APP_ROUTE_KEY . '/m' ),
		]
	);
}
