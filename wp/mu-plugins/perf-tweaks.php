<?php
/**
 * Plugin Name: News Performance Tweaks
 * Description: Small, safe defaults for a high-traffic news site: no emoji script, slower heartbeat, no pingbacks/XML-RPC, fewer head tags, bigger admin-ajax gaps.
 * Version:     1.0.0
 */

defined( 'ABSPATH' ) || exit;

// Emoji detection script + inline styles on every page: unnecessary bytes.
add_action( 'init', function (): void {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'emoji_svg_url', '__return_false' );

	// Head noise: RSD, WLW manifest, shortlink, generator (version disclosure).
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	remove_action( 'wp_head', 'wp_generator' );
} );

// Heartbeat: 60 s in the editor, off elsewhere in wp-admin. Dozens of editors
// polling admin-ajax every 15 s is a real load on the admin pool.
add_filter( 'heartbeat_settings', function ( array $settings ): array {
	$settings['interval'] = 60;
	return $settings;
} );
add_action( 'admin_enqueue_scripts', function ( string $hook ): void {
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
		wp_deregister_script( 'heartbeat' );
	}
} );

// XML-RPC and pingbacks: brute-force vector and DDoS amplifier; nginx blocks
// xmlrpc.php too, this closes the internal paths.
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', function ( array $methods ): array {
	unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
	return $methods;
} );
add_filter( 'wp_headers', function ( array $headers ): array {
	unset( $headers['X-Pingback'] );
	return $headers;
} );
add_action( 'pre_ping', function ( array &$links ): void {
	// No self-pings when linking our own articles.
	$home = home_url();
	foreach ( $links as $i => $link ) {
		if ( 0 === strpos( $link, $home ) ) {
			unset( $links[ $i ] );
		}
	}
} );

// Don't let WordPress generate huge srcset lists for every image size.
add_filter( 'max_srcset_image_width', fn() => 1600 );

// Big-image threshold: scale down giant uploads from photographers (default 2560).
add_filter( 'big_image_size_threshold', fn() => 2560 );

// WebP for newly generated JPEG sub-sizes (core feature, opt-in since 6.1).
// Only affects new uploads; ~30 % smaller images. Comment out if a plugin
// already handles image formats (ShortPixel, Imagify, Cloudflare Polish...).
add_filter( 'image_editor_output_format', function ( array $formats ): array {
	$formats['image/jpeg'] = 'image/webp';
	return $formats;
} );
