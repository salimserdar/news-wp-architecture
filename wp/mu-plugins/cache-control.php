<?php
/**
 * Plugin Name: News Cache Control
 * Description: Emits Cache-Control / X-Accel-Expires headers so nginx (FastCGI cache) and Cloudflare cache each page type for the right duration.
 * Version:     1.0.0
 *
 * nginx defaults to 10 min for 200/301/302 (config/nginx/snippets/fastcgi-cache.conf).
 * X-Accel-Expires overrides that per response: nginx honours it and strips it.
 * Cache-Control is passed through to Cloudflare (Cache Rules may override Edge TTL).
 *
 * Search HTML and redirects are not stored at the edge. nginx's cache key includes
 * the query string, so a 10s search microcache cannot replace `/`. A Cloudflare
 * cache key that drops the query string can: `/?s=` HTML or a `/?p=` 302 to
 * media.tr724.com is then served for the homepage until s-maxage expires.
 * template_redirect priority 0 runs before redirect_canonical, so a 302 was
 * inheriting the page TTL. Same-host redirects (apex ↔ www, trailing slash)
 * stay in the nginx cache. Off-site targets are not cached anywhere.
 */

namespace News\CacheControl;

defined( 'ABSPATH' ) || exit;

const TTL_PAGE   = 600;  // regular articles and the home page; purge-on-publish keeps them fresh
// Tag / category / author / date archives and their /page/N/. Crawlers walk
// tens of thousands of these (34k tag MISSes in one day) and readers rarely
// do, so a 10-minute TTL meant almost every crawler hit was a render. Publish
// purges the term and author archives of the post (cache-purge.php), so a
// long TTL only delays pages that nothing changed on.
const TTL_ARCHIVE = 6 * HOUR_IN_SECONDS;
const TTL_SEARCH = 10;   // microcache: collapses bursts of identical searches
const TTL_FEED   = 300;
const TTL_REST   = 10;
const TTL_404    = 60;

/**
 * Tell Cloudflare not to store this response. nginx ignores Cache-Control.
 *
 * @param bool $replace overwrite headers template_redirect already sent
 */
function bypass_edge( bool $replace = false ): void {
	if ( headers_sent() ) {
		return;
	}
	header( 'Cache-Control: private, no-store', $replace );
	header( 'CDN-Cache-Control: no-store', $replace );
}

/**
 * Apex and www are the same site. A missing host is a relative Location.
 */
function is_offsite_location( string $location ): bool {
	$host = wp_parse_url( $location, PHP_URL_HOST );
	if ( ! is_string( $host ) || '' === $host ) {
		return false;
	}
	$home = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! is_string( $home ) || '' === $home ) {
		return true;
	}
	$host = strtolower( $host );
	$bare = strtolower( $home );
	if ( str_starts_with( $bare, 'www.' ) ) {
		$bare = substr( $bare, 4 );
	}
	return $host !== $bare && $host !== 'www.' . $bare;
}

/**
 * @param int $nginx_ttl  seconds for the nginx FastCGI cache (0 = do not cache)
 * @param int $edge_ttl   seconds for Cloudflare's s-maxage (0 = do not store at the edge)
 */
function send( int $nginx_ttl, int $edge_ttl ): void {
	if ( headers_sent() ) {
		return;
	}
	header( 'X-Accel-Expires: ' . $nginx_ttl );
	if ( $edge_ttl === 0 ) {
		bypass_edge();
		if ( $nginx_ttl === 0 ) {
			nocache_headers();
		}
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
		send( TTL_SEARCH, 0 );
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
	if ( is_archive() && ! is_front_page() ) {
		send( TTL_ARCHIVE, 300 );
		return;
	}
	send( TTL_PAGE, 120 );
}, 0 );

// redirect_canonical (priority 10) runs after the headers above, so a 302 was
// cached with the page TTL. Drop it from the edge. Off-site targets (media
// files) are not stored in nginx either.
add_filter(
	'wp_redirect',
	static function ( $location, $status ) {
		if ( headers_sent() || ! is_string( $location ) || (int) $status < 300 || (int) $status > 399 ) {
			return $location;
		}
		bypass_edge( true );
		if ( is_offsite_location( $location ) ) {
			header( 'X-Accel-Expires: 0', true );
		}
		return $location;
	},
	0,
	2
);

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
