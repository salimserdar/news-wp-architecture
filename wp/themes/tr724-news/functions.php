<?php
/**
 * Theme setup. CSS is versioned with filemtime so a deploy busts the
 * Cloudflare /wp-content/* cache, which ignores every query string except ver.
 */

defined( 'ABSPATH' ) || exit;

require_once get_template_directory() . '/inc/ads.php';
require_once get_template_directory() . '/inc/widgets.php';
require_once get_template_directory() . '/blocks/ticker/rates.php';

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
		'tr724-news-roboto',
		'https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap',
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
	register_block_type( get_template_directory() . '/blocks/site-footer' );
	register_block_type( get_template_directory() . '/blocks/spotlight' );
	register_block_type( get_template_directory() . '/blocks/authors-recent-post' );
	register_block_type( get_template_directory() . '/blocks/yazarlar' );
	register_block_type( get_template_directory() . '/blocks/stories' );
	register_block_type( get_template_directory() . '/blocks/videos' );
	register_block_type( get_template_directory() . '/blocks/category' );
	register_block_type( get_template_directory() . '/blocks/ticker' );
	register_block_type( get_template_directory() . '/blocks/popular' );
} );

add_action( 'enqueue_block_editor_assets', function (): void {
	$roles = [];
	foreach ( wp_roles()->get_names() as $slug => $label ) {
		$roles[] = [
			'value' => $slug,
			'label' => translate_user_role( $label ),
		];
	}

	wp_add_inline_script(
		'tr724-yazarlar-editor-script',
		'window.tr724YazarlarRoles = ' . wp_json_encode( $roles ) . ';',
		'before'
	);
} );

add_filter(
	'register_block_type_args',
	static function ( array $args, string $block_type ): array {
		if ( 'tr724/site-header' === $block_type || 'tr724/site-footer' === $block_type ) {
			// Inner blocks are placed by each block's render.php.
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
		'tr724-site-footer-style'         => '/blocks/site-footer/style.css',
		'tr724-site-footer-editor-style'  => '/blocks/site-footer/editor.css',
		'tr724-site-footer-editor-script' => '/blocks/site-footer/edit.js',
		'tr724-spotlight-style'           => '/blocks/spotlight/style.css',
		'tr724-spotlight-editor-style'    => '/blocks/spotlight/editor.css',
		'tr724-spotlight-editor-script'   => '/blocks/spotlight/edit.js',
		'tr724-spotlight-script'          => '/blocks/spotlight/view.js',
		'tr724-authors-recent-post-style'         => '/blocks/authors-recent-post/style.css',
		'tr724-authors-recent-post-editor-style'  => '/blocks/authors-recent-post/editor.css',
		'tr724-authors-recent-post-editor-script' => '/blocks/authors-recent-post/edit.js',
		'tr724-authors-recent-post-script'        => '/blocks/authors-recent-post/view.js',
		'tr724-yazarlar-style'                    => '/blocks/yazarlar/style.css',
		'tr724-yazarlar-editor-style'             => '/blocks/yazarlar/editor.css',
		'tr724-yazarlar-editor-script'            => '/blocks/yazarlar/edit.js',
		'tr724-stories-style'                     => '/blocks/stories/style.css',
		'tr724-stories-editor-style'              => '/blocks/stories/editor.css',
		'tr724-stories-editor-script'             => '/blocks/stories/edit.js',
		'tr724-videos-style'                      => '/blocks/videos/style.css',
		'tr724-videos-editor-style'               => '/blocks/videos/editor.css',
		'tr724-videos-editor-script'              => '/blocks/videos/edit.js',
		'tr724-videos-script'                     => '/blocks/videos/view.js',
		'tr724-category-style'                    => '/blocks/category/style.css',
		'tr724-category-editor-style'             => '/blocks/category/editor.css',
		'tr724-category-editor-script'            => '/blocks/category/edit.js',
		'tr724-ticker-style'                      => '/blocks/ticker/style.css',
		'tr724-ticker-editor-style'               => '/blocks/ticker/editor.css',
		'tr724-ticker-editor-script'              => '/blocks/ticker/edit.js',
		'tr724-popular-style'                     => '/blocks/popular/style.css',
		'tr724-popular-editor-style'              => '/blocks/popular/editor.css',
		'tr724-popular-editor-script'             => '/blocks/popular/edit.js',
		'tr724-popular-script'                    => '/blocks/popular/view.js',
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

add_filter(
	'comments_open',
	static function ( bool $open, int $post_id ): bool {
		if ( 'post' === get_post_type( $post_id ) ) {
			return true;
		}
		return $open;
	},
	10,
	2
);

add_filter(
	'comment_form_default_fields',
	static function ( array $fields ): array {
		unset( $fields['url'], $fields['cookies'] );
		return $fields;
	}
);

add_action( 'pre_get_posts', function ( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_category() ) {
		return;
	}
	$query->set( 'posts_per_page', 10 );
} );

add_action( 'wp_enqueue_scripts', function (): void {
	$path = get_stylesheet_directory() . '/style.css';
	wp_enqueue_style(
		'tr724-news',
		get_stylesheet_uri(),
		[],
		is_readable( $path ) ? (string) filemtime( $path ) : null
	);

	if ( is_singular( 'post' ) ) {
		wp_enqueue_style( 'tr724-news-inter' );

		$single_css = get_template_directory() . '/assets/css/single.css';
		wp_enqueue_style(
			'tr724-single',
			get_template_directory_uri() . '/assets/css/single.css',
			[ 'tr724-news', 'tr724-news-inter' ],
			is_readable( $single_css ) ? (string) filemtime( $single_css ) : null
		);

		$single_js = get_template_directory() . '/assets/js/single.js';
		wp_enqueue_script(
			'tr724-single',
			get_template_directory_uri() . '/assets/js/single.js',
			[],
			is_readable( $single_js ) ? (string) filemtime( $single_js ) : null,
			true
		);

		$ads_css = get_template_directory() . '/assets/css/ads.css';
		wp_enqueue_style(
			'tr724-ads',
			get_template_directory_uri() . '/assets/css/ads.css',
			[ 'tr724-single' ],
			is_readable( $ads_css ) ? (string) filemtime( $ads_css ) : null
		);
		return;
	}

	if ( ! is_category() ) {
		return;
	}

	wp_enqueue_style( 'tr724-news-inter' );

	$category_css = get_template_directory() . '/assets/css/category.css';
	wp_enqueue_style(
		'tr724-category-archive',
		get_template_directory_uri() . '/assets/css/category.css',
		[ 'tr724-news', 'tr724-news-inter' ],
		is_readable( $category_css ) ? (string) filemtime( $category_css ) : null
	);

	$ads_css = get_template_directory() . '/assets/css/ads.css';
	wp_enqueue_style(
		'tr724-ads',
		get_template_directory_uri() . '/assets/css/ads.css',
		[ 'tr724-category-archive' ],
		is_readable( $ads_css ) ? (string) filemtime( $ads_css ) : null
	);
} );
