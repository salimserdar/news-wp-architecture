<?php
/**
 * Theme setup. CSS is versioned with filemtime so a deploy busts the
 * Cloudflare /wp-content/* cache, which ignores every query string except ver.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', function (): void {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ] );
	add_theme_support(
		'custom-logo',
		[
			'height'      => 52,
			'width'       => 180,
			'flex-height' => true,
			'flex-width'  => true,
		]
	);
	add_theme_support( 'block-template-parts' );
} );

add_action( 'init', function (): void {
	wp_register_style(
		'tr724-news-inter',
		'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap',
		[],
		null
	);
	wp_register_style(
		'tr724-swiper',
		'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css',
		[],
		'11'
	);
	wp_register_script(
		'tr724-swiper',
		'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js',
		[],
		'11',
		true
	);

	register_block_type( get_template_directory() . '/blocks/site-header' );
	register_block_type( get_template_directory() . '/blocks/spotlight' );
} );

add_filter(
	'register_block_type_args',
	static function ( array $args, string $block_type ): array {
		if ( 'tr724/site-header' === $block_type ) {
			// Inner blocks are placed by render.php, including a second navigation render.
			$args['skip_inner_blocks'] = true;
		}
		return $args;
	},
	10,
	2
);

/**
 * Block handles get a static version from the asset file. Swap in filemtime
 * so a theme deploy busts the cache the same way style.css does.
 */
add_action( 'enqueue_block_assets', function (): void {
	$map = [
		'tr724-site-header-style'         => '/blocks/site-header/style.css',
		'tr724-site-header-editor-style'  => '/blocks/site-header/editor.css',
		'tr724-site-header-view-script'   => '/blocks/site-header/view.js',
		'tr724-site-header-editor-script' => '/blocks/site-header/edit.js',
		'tr724-spotlight-style'           => '/blocks/spotlight/style.css',
		'tr724-spotlight-editor-style'    => '/blocks/spotlight/editor.css',
		'tr724-spotlight-editor-script'   => '/blocks/spotlight/edit.js',
		'tr724-spotlight-script'          => '/blocks/spotlight/view.js',
	];

	foreach ( $map as $handle => $relative ) {
		$path = get_template_directory() . $relative;
		if ( ! is_readable( $path ) ) {
			continue;
		}
		$version = (string) filemtime( $path );
		if ( wp_style_is( $handle, 'registered' ) ) {
			wp_styles()->registered[ $handle ]->ver = $version;
		}
		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_scripts()->registered[ $handle ]->ver = $version;
		}
	}
} );

add_action( 'wp_enqueue_scripts', function (): void {
	$path = get_stylesheet_directory() . '/style.css';
	wp_enqueue_style(
		'tr724-news',
		get_stylesheet_uri(),
		[],
		is_readable( $path ) ? (string) filemtime( $path ) : null
	);
} );
