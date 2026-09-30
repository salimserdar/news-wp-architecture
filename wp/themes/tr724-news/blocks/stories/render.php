<?php
/**
 * Story card grid. Latest posts, filtered by category and/or tag, with an offset
 * so a block can start after posts already shown (for example, 6 posts after the 15th).
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$count = isset( $attributes['postsToShow'] ) ? (int) $attributes['postsToShow'] : 6;
$count = max( 1, min( 24, $count ) );

$offset = isset( $attributes['offset'] ) ? (int) $attributes['offset'] : 0;
$offset = max( 0, min( 200, $offset ) );

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
	'post_type'              => 'post',
	'post_status'            => 'publish',
	'posts_per_page'         => $count,
	'offset'                 => $offset,
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

$show_more  = ! array_key_exists( 'showMore', $attributes ) || (bool) $attributes['showMore'];
$more_label = isset( $attributes['moreLabel'] ) ? trim( (string) $attributes['moreLabel'] ) : '';
if ( '' === $more_label ) {
	$more_label = __( 'Devamı', 'tr724-news' );
}

$more_url = isset( $attributes['moreUrl'] ) ? trim( (string) $attributes['moreUrl'] ) : '';
if ( '' === $more_url && $category ) {
	$term_link = get_term_link( $category );
	if ( ! is_wp_error( $term_link ) ) {
		$more_url = $term_link;
	}
}
if ( '' === $more_url && $tag ) {
	$term_link = get_term_link( $tag );
	if ( ! is_wp_error( $term_link ) ) {
		$more_url = $term_link;
	}
}
if ( '' === $more_url ) {
	$posts_page_id = (int) get_option( 'page_for_posts' );
	$more_url      = $posts_page_id > 0 ? (string) get_permalink( $posts_page_id ) : home_url( '/' );
}

$clock_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" /><path d="M12 8v4.5l2.5 1.5" /></svg>';

if ( ! $query || ! $query->have_posts() ) {
	echo '<section ' . get_block_wrapper_attributes(
		[
			'class' => 'stories stories--empty',
		]
	) . '>';
	echo '<p class="stories__empty">' . esc_html__( 'No posts found.', 'tr724-news' ) . '</p>';
	echo '</section>';
	if ( $query ) {
		wp_reset_postdata();
	}
	return;
}

update_post_thumbnail_cache( $query );

$forced_kicker = $category ? $category->name : '';

echo '<section ' . get_block_wrapper_attributes(
	[
		'class'      => 'stories',
		'aria-label' => __( 'Haber kartları', 'tr724-news' ),
	]
) . '>';
echo '<div class="story-grid">';

while ( $query->have_posts() ) {
	$query->the_post();
	$post_id = get_the_ID();

	$kicker = $forced_kicker;
	if ( '' === $kicker ) {
		$categories = get_the_category( $post_id );
		if ( $categories ) {
			$kicker = $categories[0]->name;
		} elseif ( $tag ) {
			$kicker = $tag->name;
		}
	}

	$thumb_id = get_post_thumbnail_id( $post_id );
	$image    = '';
	if ( $thumb_id ) {
		$alt = (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
		if ( '' === $alt ) {
			$alt = get_the_title( $post_id );
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
	$image = '<span class="story-card__media">' . $image . '</span>';

	$published = get_post_timestamp( $post_id );
	$time_text = sprintf(
		/* translators: %s: human-readable interval, such as "2 saat". */
		__( '%s önce', 'tr724-news' ),
		human_time_diff( $published )
	);
	$datetime = get_post_time( DATE_W3C, true, $post_id );

	echo '<article class="story-card">';
	echo '<a href="' . esc_url( get_permalink( $post_id ) ) . '">';
	echo $image;
	echo '<span class="story-card__body">';
	if ( '' !== $kicker ) {
		echo '<span class="story-card__kicker">' . esc_html( $kicker ) . '</span>';
	}
	echo '<h3 class="story-card__title">' . esc_html( get_the_title( $post_id ) ) . '</h3>';
	echo '<time class="story-card__time" datetime="' . esc_attr( $datetime ) . '">';
	echo $clock_icon . esc_html( $time_text );
	echo '</time></span></a></article>';
}

echo '</div>';

if ( $show_more && '' !== $more_url ) {
	echo '<a class="outline-btn" href="' . esc_url( $more_url ) . '">';
	echo esc_html( $more_label ) . ' <span aria-hidden="true">→</span>';
	echo '</a>';
}

echo '</section>';

wp_reset_postdata();
