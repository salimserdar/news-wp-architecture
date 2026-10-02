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
