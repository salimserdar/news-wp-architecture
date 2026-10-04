<?php
/**
 * Günün manşetleri. Latest posts from the Manşet category as a card carousel.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$count = isset( $attributes['postsToShow'] ) ? (int) $attributes['postsToShow'] : 15;
$count = max( 4, min( 20, $count ) );

$category_id = isset( $attributes['categoryId'] ) ? (int) $attributes['categoryId'] : 13694;

$exclude_id = isset( $attributes['excludeId'] ) ? (int) $attributes['excludeId'] : 0;
if ( $exclude_id <= 0 && is_singular( 'post' ) ) {
	$exclude_id = (int) get_queried_object_id();
}

$query_args = [
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => $count,
	'ignore_sticky_posts' => true,
	'no_found_rows'       => true,
];
if ( $category_id > 0 ) {
	$query_args['cat'] = $category_id;
}
if ( $exclude_id > 0 ) {
	$query_args['post__not_in'] = [ $exclude_id ];
}

$query = new WP_Query( $query_args );

if ( ! $query->have_posts() ) {
	if ( function_exists( 'tr724_category_is_editor_preview' ) && tr724_category_is_editor_preview() ) {
		echo '<section ' . get_block_wrapper_attributes(
			[
				'class' => 'headlines headlines--empty',
			]
		) . '>';
		echo '<p class="headlines__empty">' . esc_html__( 'No posts found.', 'tr724-news' ) . '</p>';
		echo '</section>';
	}
	wp_reset_postdata();
	return;
}

update_post_thumbnail_cache( $query );

$title    = function_exists( 'tr724_archive_upper' )
	? tr724_archive_upper( __( 'Günün manşetleri', 'tr724-news' ) )
	: __( 'Günün manşetleri', 'tr724-news' );
$title_id = wp_unique_id( 'headlines-title-' );
$total    = count( $query->posts );
$prev     = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 6.5 9 12l5.5 5.5" /></svg>';
$next     = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 6.5 15 12l-5.5 5.5" /></svg>';

echo '<section ' . get_block_wrapper_attributes(
	[
		'class'                 => 'headlines',
		'data-tr724-headlines'  => '1',
		'aria-roledescription'  => 'carousel',
		'aria-labelledby'       => $title_id,
	]
) . '>';
echo '<header class="headlines__header">';
echo '<h2 class="headlines__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $title ) . '</h2>';
echo '<div class="headlines__tools">';
echo '<div class="headlines__progress" aria-hidden="true"><span class="headlines__progress-bar"></span></div>';
echo '<p class="headlines__count" aria-hidden="true"><span data-headlines-current>1</span> / ' . (int) $total . '</p>';
echo '<div class="headlines__nav">';
echo '<button class="headlines__prev" type="button" aria-label="' . esc_attr__( 'Önceki manşet', 'tr724-news' ) . '">' . $prev . '</button>';
echo '<button class="headlines__next" type="button" aria-label="' . esc_attr__( 'Sonraki manşet', 'tr724-news' ) . '">' . $next . '</button>';
echo '</div></div></header>';

echo '<div class="swiper headlines-swiper">';
echo '<div class="swiper-wrapper">';

$index = 0;
while ( $query->have_posts() ) {
	$query->the_post();
	$post_id   = (int) get_the_ID();
	$thumb_id  = (int) get_post_thumbnail_id( $post_id );
	$image     = '';
	if ( $thumb_id > 0 ) {
		$image = wp_get_attachment_image(
			$thumb_id,
			'medium_large',
			false,
			[
				'alt'      => '',
				'loading'  => $index < 2 ? 'eager' : 'lazy',
				'decoding' => 'async',
			]
		);
	}

	$kicker = '';
	$categories = get_the_category( $post_id );
	if ( $categories ) {
		$kicker = function_exists( 'tr724_archive_upper' )
			? tr724_archive_upper( $categories[0]->name )
			: $categories[0]->name;
	}

	$published = (int) get_post_timestamp( $post_id );
	$datetime  = $published ? (string) get_post_time( DATE_W3C, true, $post_id ) : '';
	$clock     = $published ? wp_date( 'H:i', $published ) : '';

	echo '<div class="swiper-slide">';
	echo '<article class="headlines-card">';
	echo '<a href="' . esc_url( get_permalink( $post_id ) ) . '">';
	echo '<div class="headlines-card__media">';
	echo $image;
	echo '</div>';
	echo '<p class="headlines-card__meta">';
	if ( '' !== $kicker ) {
		echo '<span class="headlines-card__kicker">' . esc_html( $kicker ) . '</span>';
	}
	if ( '' !== $clock ) {
		echo '<time datetime="' . esc_attr( $datetime ) . '">' . esc_html( $clock ) . '</time>';
	}
	echo '</p>';
	echo '<h3 class="headlines-card__title">' . esc_html( get_the_title( $post_id ) ) . '</h3>';
	echo '</a></article></div>';
	++$index;
}

echo '</div></div>';
echo '</section>';

wp_reset_postdata();
