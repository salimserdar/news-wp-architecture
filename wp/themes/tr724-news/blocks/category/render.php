<?php
/**
 * Category card columns. Latest posts, filtered by category and/or tag,
 * with an optional image ad, Google AdSense unit, or custom ad markup.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_category_upper' ) ) {
	/**
	 * Turkish-aware uppercase for the watermark and section title.
	 */
	function tr724_category_upper( string $text ): string {
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

if ( ! function_exists( 'tr724_category_is_editor_preview' ) ) {
	/**
	 * Block editor server-side render. Skip live ad scripts there.
	 */
	function tr724_category_is_editor_preview(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}
		$route = '';
		if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof WP ) {
			$route = (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' );
		}
		if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) ) {
			$route = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
		}
		return str_contains( $route, '/block-renderer/' );
	}
}

if ( ! function_exists( 'tr724_category_render_card' ) ) {
	function tr724_category_render_card( int $post_id ): void {
		$title = get_the_title( $post_id );

		$thumb_id = get_post_thumbnail_id( $post_id );
		$image    = '';
		if ( $thumb_id ) {
			$alt = (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
			if ( '' === $alt ) {
				$alt = $title;
			}
			$image = wp_get_attachment_image(
				$thumb_id,
				'medium_large',
				false,
				[
					'alt'      => $alt,
					'loading'  => 'lazy',
					'decoding' => 'async',
				]
			);
		}

		$published = get_post_timestamp( $post_id );
		$time_html = '';
		if ( $published ) {
			$time_text = sprintf(
				/* translators: %s: human-readable interval, such as "2 gün". */
				__( '%s önce', 'tr724-news' ),
				human_time_diff( $published )
			);
			$time_html = '<time class="card__time" datetime="' . esc_attr( get_post_time( DATE_W3C, true, $post_id ) ) . '">' . esc_html( $time_text ) . '</time>';
		}

		echo '<article class="card">';
		echo '<a href="' . esc_url( get_permalink( $post_id ) ) . '">';
		echo '<span class="card__media">';
		if ( '' !== $image ) {
			echo $image;
		}
		echo '</span>';
		echo '<h3 class="card__title">' . esc_html( $title ) . '</h3>';
		echo $time_html;
		echo '</a></article>';
	}
}

if ( ! function_exists( 'tr724_category_ad_markup' ) ) {
	/**
	 * Sidebar ad: uploaded image, a generated AdSense unit, or stored markup.
	 *
	 * @param array $attributes Block attributes.
	 */
	function tr724_category_ad_markup( array $attributes ): string {
		$mode = isset( $attributes['adMode'] ) ? (string) $attributes['adMode'] : 'none';
		if ( ! in_array( $mode, [ 'image', 'google', 'html' ], true ) ) {
			return '';
		}

		$preview = tr724_category_is_editor_preview();
		$label   = isset( $attributes['adLabel'] ) ? trim( (string) $attributes['adLabel'] ) : '';
		$inner   = '';

		if ( 'image' === $mode ) {
			$image_id = isset( $attributes['adImageId'] ) ? (int) $attributes['adImageId'] : 0;
			$image    = $image_id > 0 ? wp_get_attachment_image(
				$image_id,
				'medium_large',
				false,
				[
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
				]
			) : '';
			if ( '' === $image ) {
				if ( ! $preview ) {
					return '';
				}
				$inner = '<div class="ads__preview">' . esc_html__( 'Select an ad image', 'tr724-news' ) . '</div>';
			} else {
				$url = isset( $attributes['adUrl'] ) ? esc_url( $attributes['adUrl'] ) : '';
				if ( '' !== $url ) {
					$inner = '<a class="ad ad--image" href="' . $url . '" target="_blank" rel="sponsored noopener noreferrer">' . $image . '</a>';
				} else {
					$inner = '<div class="ad ad--image">' . $image . '</div>';
				}
			}
		} elseif ( 'google' === $mode ) {
			$client = isset( $attributes['adClient'] ) ? trim( (string) $attributes['adClient'] ) : '';
			$slot   = isset( $attributes['adSlot'] ) ? trim( (string) $attributes['adSlot'] ) : '';
			$format = isset( $attributes['adFormat'] ) ? (string) $attributes['adFormat'] : 'auto';
			if ( ! in_array( $format, [ 'auto', 'vertical', 'rectangle', 'horizontal' ], true ) ) {
				$format = 'auto';
			}
			$client_ok = (bool) preg_match( '/^ca-pub-\d{8,20}$/', $client );
			$slot_ok   = (bool) preg_match( '/^\d{6,20}$/', $slot );

			if ( ! $client_ok || ! $slot_ok ) {
				if ( ! $preview ) {
					return '';
				}
				$inner = '<div class="ads__preview">' . esc_html__( 'Add an AdSense publisher ID and slot', 'tr724-news' ) . '</div>';
			} elseif ( $preview ) {
				$inner = '<div class="ads__preview">' . esc_html__( 'Google AdSense', 'tr724-news' ) . '</div>';
			} else {
				$styles = [
					'auto'       => 'display:block;width:100%;min-height:250px',
					'vertical'   => 'display:inline-block;width:160px;height:600px',
					'rectangle'  => 'display:inline-block;width:196px;height:250px',
					'horizontal' => 'display:inline-block;width:196px;height:90px',
				];
				$src    = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . rawurlencode( $client );
				$inner  = '<div class="ads__unit">';
				$inner .= '<script async src="' . esc_url( $src ) . '" crossorigin="anonymous"></script>';
				$inner .= '<ins class="adsbygoogle" style="' . esc_attr( $styles[ $format ] ) . '"';
				$inner .= ' data-ad-client="' . esc_attr( $client ) . '"';
				$inner .= ' data-ad-slot="' . esc_attr( $slot ) . '"';
				if ( 'auto' === $format ) {
					$inner .= ' data-ad-format="auto" data-full-width-responsive="true"';
				}
				$inner .= '></ins>';
				$inner .= '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
				$inner .= '</div>';
			}
		} else {
			$html = isset( $attributes['adHtml'] ) ? trim( (string) $attributes['adHtml'] ) : '';
			if ( '' === $html ) {
				if ( ! $preview ) {
					return '';
				}
				$inner = '<div class="ads__preview">' . esc_html__( 'Paste ad code', 'tr724-news' ) . '</div>';
			} elseif ( $preview ) {
				$inner = '<div class="ads__preview">' . esc_html__( 'Custom ad code', 'tr724-news' ) . '</div>';
			} else {
				// Stored like a Custom HTML block. WordPress kses runs on save.
				$inner = '<div class="ads__unit">' . $html . '</div>';
			}
		}

		$markup  = '<aside class="ads" aria-label="' . esc_attr__( 'Reklamlar', 'tr724-news' ) . '">';
		$markup .= '<div class="ads__sticky">' . $inner;
		if ( '' !== $label ) {
			$markup .= '<p class="ads__label">' . esc_html( $label ) . '</p>';
		}
		$markup .= '</div></aside>';

		return $markup;
	}
}

$count = isset( $attributes['postsToShow'] ) ? (int) $attributes['postsToShow'] : 8;
$count = max( 1, min( 16, $count ) );

$category_id = isset( $attributes['categoryId'] ) ? (int) $attributes['categoryId'] : 0;
$tag_id      = isset( $attributes['tagId'] ) ? (int) $attributes['tagId'] : 0;

$category = null;
if ( $category_id > 0 ) {
	$category = get_term( $category_id, 'category' );
	if ( ! $category || is_wp_error( $category ) ) {
		$category = null;
	}
}

$tag = null;
if ( $tag_id > 0 ) {
	$tag = get_term( $tag_id, 'post_tag' );
	if ( ! $tag || is_wp_error( $tag ) ) {
		$tag = null;
	}
}

$term_missing = ( $category_id > 0 && ! $category ) || ( $tag_id > 0 && ! $tag );

$query_args = [
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => $count,
	'ignore_sticky_posts' => true,
	'no_found_rows'       => true,
];

$tax_query = [];
if ( $category ) {
	$tax_query[] = [
		'taxonomy' => 'category',
		'field'    => 'term_id',
		'terms'    => (int) $category->term_id,
	];
}
if ( $tag ) {
	$tax_query[] = [
		'taxonomy' => 'post_tag',
		'field'    => 'term_id',
		'terms'    => (int) $tag->term_id,
	];
}
if ( count( $tax_query ) > 1 ) {
	$tax_query['relation'] = 'AND';
}
if ( $tax_query ) {
	$query_args['tax_query'] = $tax_query;
}

$query = $term_missing ? null : new WP_Query( $query_args );
$posts = ( $query && $query->have_posts() ) ? $query->posts : [];

if ( $query && $posts ) {
	update_post_thumbnail_cache( $query );
}

$heading = isset( $attributes['heading'] ) ? trim( (string) $attributes['heading'] ) : '';
if ( '' === $heading && $category ) {
	$heading = $category->name;
} elseif ( '' === $heading && $tag ) {
	$heading = $tag->name;
}
if ( '' !== $heading ) {
	$heading = tr724_category_upper( $heading );
}

$ad_html = tr724_category_ad_markup( $attributes );
$classes = 'category';
if ( '' !== $ad_html ) {
	$classes .= ' category--has-ads';
}
if ( ! $posts ) {
	$classes .= ' category--empty';
}

$title_id = wp_unique_id( 'category-title-' );
$wrapper  = [
	'class' => $classes,
];
if ( '' !== $heading ) {
	$wrapper['aria-labelledby'] = $title_id;
} else {
	$wrapper['aria-label'] = __( 'Kategori', 'tr724-news' );
}

echo '<section ' . get_block_wrapper_attributes( $wrapper ) . '>';

if ( '' !== $heading ) {
	echo '<header class="category__header">';
	echo '<span class="category__watermark" aria-hidden="true">' . esc_html( $heading ) . '</span>';
	echo '<h2 class="section-title category__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $heading ) . '</h2>';
	echo '</header>';
}

if ( $posts ) {
	$split    = (int) ceil( count( $posts ) / 2 );
	$columns  = [
		array_slice( $posts, 0, $split ),
		array_slice( $posts, $split ),
	];
	foreach ( $columns as $column ) {
		if ( ! $column ) {
			continue;
		}
		echo '<div class="category__col">';
		foreach ( $column as $post ) {
			tr724_category_render_card( (int) $post->ID );
		}
		echo '</div>';
	}
} else {
	echo '<p class="category__empty">' . esc_html__( 'No posts found.', 'tr724-news' ) . '</p>';
}

echo $ad_html;
echo '</section>';

if ( $query ) {
	wp_reset_postdata();
}
