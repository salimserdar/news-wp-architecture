<?php
/**
 * Plugin Name: News Cache Control
 * Description: Emits Cache-Control / X-Accel-Expires headers so nginx (FastCGI cache) and Cloudflare cache each page type for the right duration.
 * Version:     1.0.0
 *
 * nginx defaults to 10 min for 200/301/302 (config/nginx/snippets/fastcgi-cache.conf).
 * X-Accel-Expires overrides that per response: nginx honours it and strips it.
 * Cache-Control is passed through to Cloudflare (Cache Rules may override Edge TTL).
 */

namespace News\CacheControl;

defined( 'ABSPATH' ) || exit;

const TTL_PAGE   = 600;  // regular articles / archives; purge-on-publish keeps them fresh
const TTL_SEARCH = 10;   // microcache: collapses bursts of identical searches
const TTL_FEED   = 300;
const TTL_REST   = 10;
const TTL_404    = 60;

/**
 * @param int $nginx_ttl  seconds for the nginx FastCGI cache (0 = do not cache)
 * @param int $edge_ttl   seconds for Cloudflare's s-maxage
 */
function send( int $nginx_ttl, int $edge_ttl ): void {
	if ( headers_sent() ) {
		return;
	}
	header( 'X-Accel-Expires: ' . $nginx_ttl );
	if ( $nginx_ttl === 0 ) {
		nocache_headers();
		return;
	}
	// max-age=0: browsers always revalidate with the edge (a stale page in a
	// reader's browser is worse than a 20 ms round-trip to Cloudflare).
	header( sprintf(
		'Cache-Control: public, max-age=0, s-maxage=%d, stale-while-revalidate=60, stale-if-error=86400',
		$edge_ttl
	) );
}

// Front-end pages. template_redirect runs after the main query, so conditional
// tags are reliable, and before any output.
add_action( 'template_redirect', function (): void {
	if ( is_admin() || is_user_logged_in() ) {
		return; // nginx already bypasses on the login cookie; WP sends nocache headers
	}
	if ( is_preview() || is_customize_preview() || post_password_required() ) {
		send( 0, 0 );
		return;
	}
	if ( is_search() ) {
		send( TTL_SEARCH, TTL_SEARCH );
		return;
	}
	if ( is_feed() ) {
		send( TTL_FEED, TTL_FEED );
		return;
	}
	if ( is_404() ) {
		send( TTL_404, TTL_404 );
		return;
	}
	send( TTL_PAGE, 120 );
}, 0 );

// REST API: anonymous GETs are cacheable for a few seconds (tickers, "most
// read", live-blog polling). Anything authenticated carries the login cookie
// and is bypassed by nginx before it gets here.
add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
	if ( ! ( $response instanceof \WP_HTTP_Response ) ) {
		return $response;
	}
	if ( 'GET' !== $request->get_method() || is_user_logged_in() ) {
		return $response;
	}
	if ( 200 === $response->get_status() ) {
		$response->header( 'X-Accel-Expires', (string) TTL_REST );
		$response->header( 'Cache-Control', sprintf( 'public, max-age=0, s-maxage=%d', TTL_REST ) );
	} else {
		$response->header( 'X-Accel-Expires', '0' );
	}
	return $response;
}, 10, 3 );
