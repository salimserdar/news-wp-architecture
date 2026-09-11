<?php
/**
 * Plugin Name: News Cache Purge
 * Description: When content changes, purges the nginx FastCGI cache (by deleting cache files), purges Cloudflare by URL, then re-warms the affected pages. Adds a "Purge cache" admin-bar button and `wp news-cache` CLI commands.
 * Version:     1.0.0
 *
 * Configuration (constants, set via WORDPRESS_CONFIG_EXTRA in docker-compose.yml):
 *   NEWS_NGINX_CACHE_PATH  directory of fastcgi_cache_path (shared volume, levels=1:2)
 *   NEWS_CF_ZONE_ID / NEWS_CF_API_TOKEN   Cloudflare purge credentials (optional)
 *   NEWS_WARM_URL          internal base URL of nginx used for warming (default https://nginx)
 *
 * nginx cache key (nginx.conf):  "$scheme$host$cache_uri"  -> md5 -> levels=1:2
 * e.g. https://www.example.com/2026/09/story/  =>  <dir>/<md5[-1]>/<md5[-3:-1]>/<md5>
 */

namespace News\CachePurge;

defined( 'ABSPATH' ) || exit;

const CF_API       = 'https://api.cloudflare.com/client/v4/zones/%s/purge_cache';
const CF_BATCH     = 30;   // Cloudflare limit per purge call
const WARM_MAX     = 20;   // never fire more than this many warm requests per event

/** @var string[] URLs collected during this request, flushed at shutdown */
$GLOBALS['news_purge_queue']     = [];
$GLOBALS['news_purge_everything'] = false;

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

function queue_urls( array $urls ): void {
	foreach ( $urls as $url ) {
		if ( is_string( $url ) && '' !== $url ) {
			$GLOBALS['news_purge_queue'][ $url ] = true;
		}
	}
	ensure_flush();
}

function queue_everything(): void {
	$GLOBALS['news_purge_everything'] = true;
	ensure_flush();
}

function ensure_flush(): void {
	static $hooked = false;
	if ( ! $hooked ) {
		$hooked = true;
		add_action( 'shutdown', __NAMESPACE__ . '\flush', 0 );
	}
}

/** Run at shutdown: dedupe, purge nginx -> Cloudflare -> warm. */
function flush(): void {
	$everything = $GLOBALS['news_purge_everything'];
	$urls       = array_keys( $GLOBALS['news_purge_queue'] );
	$GLOBALS['news_purge_queue']      = [];
	$GLOBALS['news_purge_everything'] = false;

	if ( ! $everything && empty( $urls ) ) {
		return;
	}

	// Editors shouldn't wait for the Cloudflare API: hand the response to nginx first.
	if ( function_exists( 'fastcgi_finish_request' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		fastcgi_finish_request();
	}

	if ( $everything ) {
		purge_everything();
		warm( default_warm_urls() );
		return;
	}

	$urls = apply_filters( 'news_cache_purge_urls', array_values( array_unique( $urls ) ) );
	nginx_purge_urls( $urls );
	cloudflare_purge_urls( $urls );
	warm( array_slice( $urls, 0, WARM_MAX ) );
	do_action( 'news_cache_purged', $urls );
}

function purge_everything(): void {
	nginx_purge_all();
	cloudflare_purge_everything();
	do_action( 'news_cache_purged_everything' );
}

// ---------------------------------------------------------------------------
// URL collection
// ---------------------------------------------------------------------------

/** Every URL that displays this post or a list containing it. */
function urls_for_post( \WP_Post $post ): array {
	$urls   = [];
	$urls[] = get_permalink( $post );
	$urls[] = home_url( '/' );
	$urls[] = get_feed_link();

	if ( ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[] = $archive;
		}
	}

	foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
		if ( ! $tax->public ) {
			continue;
		}
		$terms = get_the_terms( $post, $tax->name );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}
	}

	if ( $post->post_author ) {
		$urls[] = get_author_posts_url( (int) $post->post_author );
	}

	if ( 'post' === $post->post_type ) {
		$t = strtotime( $post->post_date );
		if ( $t ) {
			$urls[] = get_day_link( gmdate( 'Y', $t ), gmdate( 'm', $t ), gmdate( 'd', $t ) );
		}
	}

	return array_values( array_unique( array_filter( $urls ) ) );
}

function default_warm_urls(): array {
	return apply_filters( 'news_cache_warm_urls', [ home_url( '/' ), get_feed_link() ] );
}

// ---------------------------------------------------------------------------
// nginx: delete cache files directly (shared volume, same uid as nginx workers)
// ---------------------------------------------------------------------------

function nginx_cache_dir(): string {
	return defined( 'NEWS_NGINX_CACHE_PATH' ) ? rtrim( NEWS_NGINX_CACHE_PATH, '/' ) : '';
}

/** Mirror of nginx's fastcgi_cache_key "$scheme$host$cache_uri" + levels=1:2. */
function nginx_cache_file( string $url ): ?string {
	$dir = nginx_cache_dir();
	if ( '' === $dir ) {
		return null;
	}
	$p = wp_parse_url( $url );
	if ( empty( $p['host'] ) ) {
		return null;
	}
	$scheme = $p['scheme'] ?? 'https';
	$uri    = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	$md5    = md5( $scheme . $p['host'] . $uri );
	return sprintf( '%s/%s/%s/%s', $dir, substr( $md5, -1 ), substr( $md5, -3, 2 ), $md5 );
}

function nginx_purge_urls( array $urls ): int {
	if ( ! is_dir( nginx_cache_dir() ) ) {
		return 0;
	}
	$n = 0;
	foreach ( $urls as $url ) {
		// Purge the https entry and (cheap insurance) the http one.
		foreach ( [ $url, preg_replace( '#^https://#', 'http://', $url ) ] as $variant ) {
			$file = nginx_cache_file( $variant );
			if ( $file && is_file( $file ) && @unlink( $file ) ) {
				$n++;
			}
		}
	}
	return $n;
}

function nginx_purge_all(): int {
	$dir = nginx_cache_dir();
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return 0;
	}
	$n  = 0;
	$it = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
		\RecursiveIteratorIterator::LEAVES_ONLY
	);
	foreach ( $it as $file ) {
		if ( $file->isFile() && @unlink( $file->getPathname() ) ) {
			$n++;
		}
	}
	return $n;
}

// ---------------------------------------------------------------------------
// Cloudflare
// ---------------------------------------------------------------------------

function cloudflare_enabled(): bool {
	return defined( 'NEWS_CF_ZONE_ID' ) && defined( 'NEWS_CF_API_TOKEN' )
		&& '' !== NEWS_CF_ZONE_ID && '' !== NEWS_CF_API_TOKEN;
}

function cloudflare_request( array $body ): bool {
	$res = wp_remote_post( sprintf( CF_API, NEWS_CF_ZONE_ID ), [
		'timeout' => 10,
		'headers' => [
			'Authorization' => 'Bearer ' . NEWS_CF_API_TOKEN,
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( $body ),
	] );
	if ( is_wp_error( $res ) ) {
		error_log( '[news-cache] Cloudflare purge failed: ' . $res->get_error_message() );
		return false;
	}
	$code = wp_remote_retrieve_response_code( $res );
	if ( 200 !== $code ) {
		error_log( '[news-cache] Cloudflare purge HTTP ' . $code . ': ' . wp_remote_retrieve_body( $res ) );
		return false;
	}
	return true;
}

function cloudflare_purge_urls( array $urls ): void {
	if ( ! cloudflare_enabled() || empty( $urls ) ) {
		return;
	}
	foreach ( array_chunk( $urls, CF_BATCH ) as $chunk ) {
		cloudflare_request( [ 'files' => $chunk ] );
	}
}

function cloudflare_purge_everything(): void {
	if ( cloudflare_enabled() ) {
		cloudflare_request( [ 'purge_everything' => true ] );
	}
}

// ---------------------------------------------------------------------------
// Warming: refill nginx (and, through it, the next Cloudflare miss) before a
// real reader pays for the render. Requests go straight to the nginx
// container with the public Host header, so the cache key matches.
// ---------------------------------------------------------------------------

function warm( array $urls ): void {
	$base = defined( 'NEWS_WARM_URL' ) ? rtrim( NEWS_WARM_URL, '/' ) : '';
	if ( '' === $base ) {
		return;
	}
	foreach ( $urls as $url ) {
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			continue;
		}
		$path = ( $p['path'] ?? '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		wp_remote_get( $base . $path, [
			'timeout'     => 15,
			'blocking'    => false,
			'sslverify'   => false,            // origin cert is for the public host, not "nginx"
			'redirection' => 0,
			'headers'     => [ 'Host' => $p['host'], 'User-Agent' => 'news-cache-warmer/1.0' ],
		] );
	}
}

// ---------------------------------------------------------------------------
// Hooks
// ---------------------------------------------------------------------------

function is_purgeable_post( \WP_Post $post ): bool {
	if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
		return false;
	}
	$type = get_post_type_object( $post->post_type );
	return $type && $type->public;
}

// Fires on every save/publish/unpublish/trash. Purge when the post is or was public.
add_action( 'transition_post_status', function ( string $new, string $old, \WP_Post $post ): void {
	if ( ! is_purgeable_post( $post ) ) {
		return;
	}
	if ( 'publish' !== $new && 'publish' !== $old ) {
		return; // draft -> draft etc.
	}
	queue_urls( urls_for_post( $post ) );
}, 10, 3 );

// Comments change the article page (count / list).
$comment_purge = function ( $comment_id ): void {
	$c = get_comment( $comment_id );
	if ( $c && $c->comment_post_ID ) {
		$post = get_post( $c->comment_post_ID );
		if ( $post ) {
			queue_urls( [ get_permalink( $post ) ] );
		}
	}
};
add_action( 'wp_insert_comment', $comment_purge );
add_action( 'wp_set_comment_status', $comment_purge );

// Term renamed / deleted: archive page + home.
add_action( 'edited_term', function ( int $term_id, int $tt_id, string $taxonomy ): void {
	$link = get_term_link( $term_id, $taxonomy );
	queue_urls( array_filter( [ is_wp_error( $link ) ? null : $link, home_url( '/' ) ] ) );
}, 10, 3 );

// Global changes: theme, customizer, menus, widgets, core/plugin/theme updates.
foreach ( [ 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'update_option_sidebars_widgets', 'upgrader_process_complete' ] as $hook ) {
	add_action( $hook, __NAMESPACE__ . '\queue_everything' );
}

// ---------------------------------------------------------------------------
// Admin bar button + handler (administrators and editors)
// ---------------------------------------------------------------------------

add_action( 'admin_bar_menu', function ( \WP_Admin_Bar $bar ): void {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	$bar->add_node( [
		'id'    => 'news-purge-all',
		'title' => 'Purge cache',
		'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=news_purge_all' ), 'news_purge_all' ),
		'meta'  => [ 'title' => 'Purge nginx + Cloudflare cache for the whole site' ],
	] );
}, 100 );

add_action( 'admin_post_news_purge_all', function (): void {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( 'Not allowed', 403 );
	}
	check_admin_referer( 'news_purge_all' );
	queue_everything();
	wp_safe_redirect( add_query_arg( 'news-purged', '1', wp_get_referer() ?: admin_url() ) );
	exit;
} );

add_action( 'admin_notices', function (): void {
	if ( isset( $_GET['news-purged'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Cache purged (nginx + Cloudflare).</p></div>';
	}
} );

// ---------------------------------------------------------------------------
// WP-CLI:  wp news-cache purge <url>...  |  wp news-cache purge-all  |  wp news-cache warm [<url>...]
// ---------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'news-cache', new class {
		/**
		 * Purge specific URLs from nginx and Cloudflare.
		 *
		 * ## OPTIONS
		 * <url>...
		 * : Absolute URLs.
		 */
		public function purge( array $args ): void {
			$n = nginx_purge_urls( $args );
			cloudflare_purge_urls( $args );
			warm( $args );
			\WP_CLI::success( sprintf( '%d nginx cache file(s) removed, %d URL(s) sent to Cloudflare, warming.', $n, cloudflare_enabled() ? count( $args ) : 0 ) );
		}

		/**
		 * Purge the whole nginx cache and Cloudflare zone.
		 *
		 * @subcommand purge-all
		 */
		public function purge_all(): void {
			$n = nginx_purge_all();
			cloudflare_purge_everything();
			warm( default_warm_urls() );
			\WP_CLI::success( sprintf( '%d nginx cache file(s) removed; Cloudflare purge_everything %s.', $n, cloudflare_enabled() ? 'sent' : 'skipped (no credentials)' ) );
		}

		/**
		 * Warm URLs (default: homepage + feed).
		 *
		 * ## OPTIONS
		 * [<url>...]
		 * : Absolute URLs.
		 */
		public function warm( array $args ): void {
			$urls = $args ?: default_warm_urls();
			warm( $urls );
			\WP_CLI::success( sprintf( 'Warm requests sent for %d URL(s).', count( $urls ) ) );
		}

		/** Show the nginx cache file path that a URL maps to (debugging). */
		public function path( array $args ): void {
			foreach ( $args as $url ) {
				\WP_CLI::line( ( nginx_cache_file( $url ) ?? '(unresolvable)' ) . '  ' . ( is_file( (string) nginx_cache_file( $url ) ) ? 'CACHED' : 'not cached' ) );
			}
		}
	} );
}
