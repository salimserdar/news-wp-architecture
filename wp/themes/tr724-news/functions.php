<?php
/**
 * Theme setup. CSS is versioned with filemtime so a deploy busts the
 * Cloudflare /wp-content/* cache, which ignores every query string except ver.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Headers for Docker aggregator reads. Search stays public.
 * On the VPS, private reads use the aggregator client instead of these headers.
 *
 * @return array<string, string>
 */
function tr724_aggregator_headers(): array {
	$headers = [
		'Accept' => 'application/json',
	];
	if ( defined( 'SITE_AGGREGATOR_READ_API_KEY' ) && is_string( SITE_AGGREGATOR_READ_API_KEY ) ) {
		$key = trim( SITE_AGGREGATOR_READ_API_KEY );
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
	}
	return $headers;
}

require_once get_template_directory() . '/inc/ads.php';
require_once get_template_directory() . '/inc/additional-image.php';
require_once get_template_directory() . '/inc/category-carousel.php';
require_once get_template_directory() . '/inc/authors.php';
require_once get_template_directory() . '/inc/editorial/bootstrap.php';
require_once get_template_directory() . '/inc/widgets.php';
require_once get_template_directory() . '/blocks/ticker/rates.php';
require_once get_template_directory() . '/blocks/site-header/search.php';
require_once get_template_directory() . '/blocks/related-news/posts.php';

/**
 * Exchange sidebar is limited to one category archive when a category is chosen.
 *
 * @param array<string, mixed> $attributes
 */
function tr724_exchange_is_visible( array $attributes ): bool {
	$category_id = isset( $attributes['categoryId'] ) ? (int) $attributes['categoryId'] : 0;
	if ( $category_id < 1 ) {
		return true;
	}
	if ( tr724_category_is_editor_preview() ) {
		return true;
	}
	return is_category( $category_id );
}

/**
 * Drop the widget wrapper when the exchange block is the only content and this archive is not its category.
 *
 * @param array|false $instance Widget instance.
 * @param WP_Widget   $widget   Widget object.
 * @return array|false
 */
add_filter(
	'widget_display_callback',
	static function ( $instance, $widget ) {
		if ( false === $instance || ! $widget instanceof WP_Widget_Block || ! is_array( $instance ) ) {
			return $instance;
		}

		$content = isset( $instance['content'] ) ? (string) $instance['content'] : '';
		if ( ! str_contains( $content, 'wp:tr724/exchange' ) ) {
			return $instance;
		}

		$visible = 0;
		foreach ( parse_blocks( $content ) as $parsed ) {
			$name = $parsed['blockName'] ?? null;
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			if ( 'tr724/exchange' !== $name ) {
				$visible++;
				continue;
			}
			$attrs = isset( $parsed['attrs'] ) && is_array( $parsed['attrs'] ) ? $parsed['attrs'] : [];
			if ( tr724_exchange_is_visible( $attrs ) ) {
				$visible++;
			}
		}

		return 0 === $visible ? false : $instance;
	},
	10,
	2
);

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
	register_block_type( get_template_directory() . '/blocks/authors-recent-wide' );
	register_block_type( get_template_directory() . '/blocks/yazarlar' );
	register_block_type( get_template_directory() . '/blocks/sub-page-header' );
	register_block_type( get_template_directory() . '/blocks/stories' );
	register_block_type( get_template_directory() . '/blocks/videos' );
	register_block_type( get_template_directory() . '/blocks/category' );
	register_block_type( get_template_directory() . '/blocks/ticker' );
	register_block_type( get_template_directory() . '/blocks/exchange' );
	register_block_type( get_template_directory() . '/blocks/popular' );
	register_block_type( get_template_directory() . '/blocks/post-list' );
	register_block_type( get_template_directory() . '/blocks/headlines' );
	register_block_type( get_template_directory() . '/blocks/related-news' );
	register_block_type( get_template_directory() . '/blocks/country-ads' );
	register_block_type( get_template_directory() . '/blocks/featured-topics' );
	register_block_type( get_template_directory() . '/blocks/timeline' );
} );

/**
 * Administrators insert every tr724 block. Editors insert only İlgili Haberler.
 * Other roles insert none of them. Blocks already saved in a post still render.
 */
add_filter(
	'allowed_block_types_all',
	static function ( $allowed_block_types ) {
		$roles = (array) wp_get_current_user()->roles;
		if ( in_array( 'administrator', $roles, true ) ) {
			return $allowed_block_types;
		}

		$keep_related = in_array( 'editor', $roles, true );
		$registered   = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
		$hidden       = [];
		foreach ( $registered as $name ) {
			if ( ! str_starts_with( $name, 'tr724/' ) ) {
				continue;
			}
			if ( $keep_related && 'tr724/related-news' === $name ) {
				continue;
			}
			$hidden[] = $name;
		}

		if ( true === $allowed_block_types ) {
			$allowed_block_types = $registered;
		}
		if ( ! is_array( $allowed_block_types ) ) {
			return $allowed_block_types;
		}

		return array_values( array_diff( $allowed_block_types, $hidden ) );
	}
);

if ( ! function_exists( 'tr724_author_is_newsroom' ) ) {
	/**
	 * Newsroom posts are written by an editor or an administrator.
	 */
	function tr724_author_is_newsroom( int $author_id ): bool {
		$user = get_userdata( $author_id );
		if ( ! $user instanceof WP_User ) {
			return false;
		}
		return (bool) array_intersect( (array) $user->roles, [ 'editor', 'administrator' ] );
	}
}

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
		'tr724-site-header-style-2'       => '/blocks/site-header/style.css',
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
		'tr724-authors-recent-wide-style-2'       => '/blocks/authors-recent-wide/style.css',
		'tr724-authors-recent-wide-editor-style'  => '/blocks/authors-recent-wide/editor.css',
		'tr724-authors-recent-wide-editor-script' => '/blocks/authors-recent-wide/edit.js',
		'tr724-yazarlar-style'                    => '/blocks/yazarlar/style.css',
		'tr724-yazarlar-editor-style'             => '/blocks/yazarlar/editor.css',
		'tr724-yazarlar-editor-script'            => '/blocks/yazarlar/edit.js',
		'tr724-yazarlar-script'                   => '/blocks/yazarlar/view.js',
		'tr724-sub-page-header-style'             => '/blocks/sub-page-header/style.css',
		'tr724-sub-page-header-editor-style'      => '/blocks/sub-page-header/editor.css',
		'tr724-sub-page-header-editor-script'     => '/blocks/sub-page-header/edit.js',
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
		'tr724-exchange-style'                    => '/blocks/exchange/style.css',
		'tr724-exchange-editor-style'             => '/blocks/exchange/editor.css',
		'tr724-exchange-editor-script'            => '/blocks/exchange/edit.js',
		'tr724-popular-style'                     => '/blocks/popular/style.css',
		'tr724-popular-editor-style'              => '/blocks/popular/editor.css',
		'tr724-popular-editor-script'             => '/blocks/popular/edit.js',
		'tr724-popular-script'                    => '/blocks/popular/view.js',
		'tr724-post-list-style-2'                 => '/blocks/post-list/style.css',
		'tr724-post-list-editor-style'            => '/blocks/post-list/editor.css',
		'tr724-post-list-editor-script'           => '/blocks/post-list/edit.js',
		'tr724-headlines-style'                   => '/blocks/headlines/style.css',
		'tr724-headlines-editor-style'            => '/blocks/headlines/editor.css',
		'tr724-headlines-editor-script'           => '/blocks/headlines/edit.js',
		'tr724-headlines-script'                  => '/blocks/headlines/view.js',
		'tr724-related-news-style'                => '/blocks/related-news/style.css',
		'tr724-related-news-editor-style'         => '/blocks/related-news/editor.css',
		'tr724-related-news-editor-script'        => '/blocks/related-news/edit.js',
		'tr724-country-ads-style'                 => '/blocks/country-ads/style.css',
		'tr724-country-ads-editor-style'          => '/blocks/country-ads/editor.css',
		'tr724-country-ads-editor-script'         => '/blocks/country-ads/edit.js',
		'tr724-country-ads-script'                => '/blocks/country-ads/view.js',
		'tr724-featured-topics-style-2'           => '/blocks/featured-topics/style.css',
		'tr724-featured-topics-editor-style'      => '/blocks/featured-topics/editor.css',
		'tr724-featured-topics-editor-script'     => '/blocks/featured-topics/edit.js',
		'tr724-timeline-style-2'                  => '/blocks/timeline/style.css',
		'tr724-timeline-editor-style'             => '/blocks/timeline/editor.css',
		'tr724-timeline-editor-script'            => '/blocks/timeline/edit.js',
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

if ( ! function_exists( 'tr724_story_card_image' ) ) {
	/**
	 * Original attachment file, without a srcset of resized copies.
	 */
	function tr724_story_card_image( int $attachment_id, string $alt, string $loading = 'lazy' ): string {
		if ( $attachment_id < 1 ) {
			return '';
		}
		if ( ! in_array( $loading, [ 'lazy', 'eager' ], true ) ) {
			$loading = 'lazy';
		}

		$strip_srcset = static function ( array $attr ): array {
			unset( $attr['srcset'], $attr['sizes'] );
			return $attr;
		};
		add_filter( 'wp_get_attachment_image_attributes', $strip_srcset, 10000 );
		$html = wp_get_attachment_image(
			$attachment_id,
			'full',
			false,
			[
				'alt'      => $alt,
				'loading'  => $loading,
				'decoding' => 'async',
			]
		);
		remove_filter( 'wp_get_attachment_image_attributes', $strip_srcset, 10000 );

		return is_string( $html ) ? $html : '';
	}
}

if ( ! function_exists( 'tr724_archive_upper' ) ) {
	/**
	 * Turkish-aware uppercase for archive titles and dates.
	 */
	function tr724_archive_upper( string $text ): string {
		$text = strtr(
			$text,
			[
				'i' => 'İ',
				'ı' => 'I',
			]
		);
		return mb_strtoupper( $text, 'UTF-8' );
	}
}

if ( ! function_exists( 'tr724_archive_pages' ) ) {
	/**
	 * Page numbers to show, with gaps when the archive is long.
	 *
	 * @return array<int, int|string>
	 */
	function tr724_archive_pages( int $current, int $total ): array {
		if ( $total <= 7 ) {
			return range( 1, $total );
		}

		$pages = [ 1 ];
		$start = max( 2, $current - 1 );
		$end   = min( $total - 1, $current + 1 );

		if ( $start > 2 ) {
			$pages[] = 'gap';
		}
		for ( $page = $start; $page <= $end; $page++ ) {
			$pages[] = $page;
		}
		if ( $end < $total - 1 ) {
			$pages[] = 'gap';
		}
		$pages[] = $total;

		return $pages;
	}
}

if ( ! function_exists( 'tr724_archive_pagination' ) ) {
	/**
	 * Previous, page numbers, and next. Inactive on the first and last page.
	 */
	function tr724_archive_pagination(): void {
		global $wp_query;

		$total = isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 0;
		if ( $total < 2 ) {
			return;
		}

		$current = max( 1, (int) get_query_var( 'paged' ) );
		$pages   = tr724_archive_pages( $current, $total );

		echo '<nav class="pagination" aria-label="' . esc_attr__( 'Sayfalama', 'tr724-news' ) . '">';

		if ( $current > 1 ) {
			printf(
				'<a class="pagination__step" href="%1$s" aria-label="%2$s">‹</a>',
				esc_url( get_pagenum_link( $current - 1 ) ),
				esc_attr__( 'Önceki sayfa', 'tr724-news' )
			);
		} else {
			printf(
				'<span class="pagination__step" aria-disabled="true" aria-label="%s">‹</span>',
				esc_attr__( 'Önceki sayfa', 'tr724-news' )
			);
		}

		foreach ( $pages as $page ) {
			if ( 'gap' === $page ) {
				echo '<span class="pagination__gap" aria-hidden="true">…</span>';
				continue;
			}

			$page = (int) $page;
			if ( $page === $current ) {
				printf(
					'<span class="pagination__page" aria-current="page">%d</span>',
					$page
				);
				continue;
			}

			printf(
				'<a class="pagination__page" href="%1$s">%2$d</a>',
				esc_url( get_pagenum_link( $page ) ),
				$page
			);
		}

		if ( $current < $total ) {
			printf(
				'<a class="pagination__step" href="%1$s" aria-label="%2$s">›</a>',
				esc_url( get_pagenum_link( $current + 1 ) ),
				esc_attr__( 'Sonraki sayfa', 'tr724-news' )
			);
		} else {
			printf(
				'<span class="pagination__step" aria-disabled="true" aria-label="%s">›</span>',
				esc_attr__( 'Sonraki sayfa', 'tr724-news' )
			);
		}

		echo '</nav>';
	}
}

add_action( 'pre_get_posts', function ( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( ! $query->is_category() && ! $query->is_tag() && ! $query->is_author() ) {
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

	if ( is_404() ) {
		wp_enqueue_style( 'tr724-news-inter' );

		$not_found_css = get_template_directory() . '/assets/css/not-found.css';
		wp_enqueue_style(
			'tr724-not-found',
			get_template_directory_uri() . '/assets/css/not-found.css',
			[ 'tr724-news', 'tr724-news-inter' ],
			is_readable( $not_found_css ) ? (string) filemtime( $not_found_css ) : null
		);
		return;
	}

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

		$newsroom_author = (int) get_post_field( 'post_author', get_queried_object_id() );
		if ( tr724_author_is_newsroom( $newsroom_author ) ) {
			wp_enqueue_style( 'tr724-headlines-style' );
			wp_enqueue_script( 'tr724-headlines-script' );
		}

		$ads_css = get_template_directory() . '/assets/css/ads.css';
		wp_enqueue_style(
			'tr724-ads',
			get_template_directory_uri() . '/assets/css/ads.css',
			[ 'tr724-single' ],
			is_readable( $ads_css ) ? (string) filemtime( $ads_css ) : null
		);
		return;
	}

	if ( ! is_category() && ! is_tag() && ! is_author() && ! is_search() ) {
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

	$ads_deps = [ 'tr724-category-archive' ];
	if ( is_author() ) {
		$author_css = get_template_directory() . '/assets/css/author.css';
		wp_enqueue_style(
			'tr724-author',
			get_template_directory_uri() . '/assets/css/author.css',
			[ 'tr724-category-archive' ],
			is_readable( $author_css ) ? (string) filemtime( $author_css ) : null
		);
		$ads_deps[] = 'tr724-author';
	}

	$ads_css = get_template_directory() . '/assets/css/ads.css';
	wp_enqueue_style(
		'tr724-ads',
		get_template_directory_uri() . '/assets/css/ads.css',
		$ads_deps,
		is_readable( $ads_css ) ? (string) filemtime( $ads_css ) : null
	);

	if ( ! is_category() ) {
		return;
	}

	$carousel_term = get_queried_object();
	if ( ! $carousel_term instanceof WP_Term || ! tr724_category_carousel_should_show( $carousel_term ) ) {
		return;
	}

	wp_enqueue_style( 'tr724-swiper' );

	$carousel_css = get_template_directory() . '/assets/css/category-carousel.css';
	wp_enqueue_style(
		'tr724-category-carousel',
		get_template_directory_uri() . '/assets/css/category-carousel.css',
		[ 'tr724-swiper', 'tr724-news-inter' ],
		is_readable( $carousel_css ) ? (string) filemtime( $carousel_css ) : null
	);

	$carousel_js = get_template_directory() . '/assets/js/category-carousel.js';
	wp_enqueue_script(
		'tr724-category-carousel',
		get_template_directory_uri() . '/assets/js/category-carousel.js',
		[ 'tr724-swiper' ],
		is_readable( $carousel_js ) ? (string) filemtime( $carousel_js ) : null,
		true
	);
} );
