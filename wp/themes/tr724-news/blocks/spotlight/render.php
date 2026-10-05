<?php
/**
 * Spotlight carousel. Latest posts, filtered by the block's category.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$count = isset( $attributes['postsToShow'] ) ? (int) $attributes['postsToShow'] : 15;
$count = max( 1, min( 30, $count ) );

$category_id = isset( $attributes['categoryId'] ) ? (int) $attributes['categoryId'] : 0;

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

$query = new WP_Query( $query_args );

if ( ! $query->have_posts() ) {
	echo '<section ' . get_block_wrapper_attributes(
		[
			'class' => 'spotlight spotlight--empty',
		]
	) . '>';
	echo '<p class="spotlight__empty">' . esc_html__( 'No posts found.', 'tr724-news' ) . '</p>';
	echo '</section>';
	wp_reset_postdata();
	return;
}

update_post_thumbnail_cache( $query );

$extra_ids = [];
foreach ( $query->posts as $spotlight_post ) {
	$extra_id = tr724_additional_image_id( (int) $spotlight_post->ID );
	if ( $extra_id > 0 ) {
		$extra_ids[] = $extra_id;
	}
}
if ( $extra_ids ) {
	_prime_post_caches( $extra_ids, false, true );
}

$prev_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 6.5 9 12l5.5 5.5" /></svg>';
$next_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 6.5 15 12l-5.5 5.5" /></svg>';
$more_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.5 12a7.5 7.5 0 1 1-2.1-5.2" /><path d="M19.5 4.5V9H15" /></svg>';

echo '<section ' . get_block_wrapper_attributes(
	[
		'class'              => 'spotlight',
		'data-tr724-spotlight' => '1',
		'aria-label'         => __( 'Manşet', 'tr724-news' ),
	]
) . '>';
echo '<div class="swiper news-swiper">';
echo '<div class="swiper-wrapper">';

$index = 0;
while ( $query->have_posts() ) {
	$query->the_post();
	$post_id = get_the_ID();

	$thumb_id = tr724_additional_image_id( $post_id );
	if ( $thumb_id <= 0 ) {
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
	}
	$extra_title = tr724_additional_title( $post_id );
	$upper_title = tr724_upper_title( $post_id );
	$slide_title = '' !== $extra_title ? $extra_title : get_the_title( $post_id );
	$hide_title  = tr724_hide_title( $post_id );
	$slide_label = ( ! $hide_title && '' !== $upper_title ) ? $upper_title . ' ' . $slide_title : $slide_title;
	$image       = '';
	if ( $thumb_id ) {
		$image = wp_get_attachment_image(
			$thumb_id,
			'large',
			false,
			[
				'alt'           => $slide_title,
				'loading'       => 0 === $index ? 'eager' : 'lazy',
				'fetchpriority' => 0 === $index ? 'high' : 'low',
				'decoding'      => 'async',
			]
		);
	}

	echo '<div class="swiper-slide">';
	echo '<a class="news-slide" href="' . esc_url( get_permalink( $post_id ) ) . '" aria-label="' . esc_attr( $slide_label ) . '">';
	echo $image;
	if ( ! $hide_title ) {
		echo '<span class="news-slide__shade" aria-hidden="true"></span>';
		echo '<span class="news-slide__copy">';
		if ( '' !== $upper_title ) {
			echo '<span class="news-slide__eyebrow">' . esc_html( $upper_title ) . '</span>';
		}
		echo '<h3 class="news-slide__title">' . esc_html( $slide_title ) . '</h3>';
		echo '</span>';
	}
	echo '</a></div>';
	++$index;
}

echo '</div>';
echo '<button class="news-swiper__nav news-swiper__prev" type="button" aria-label="' . esc_attr__( 'Önceki haber', 'tr724-news' ) . '">' . $prev_icon . '</button>';
echo '<button class="news-swiper__nav news-swiper__next" type="button" aria-label="' . esc_attr__( 'Sonraki haber', 'tr724-news' ) . '">' . $next_icon . '</button>';
echo '</div>';
echo '<div class="news-pager">';
echo '<div class="news-pager__numbers"></div>';
echo '<button class="news-pager__more" type="button">';
echo '<span class="news-pager__more-label">' . esc_html__( 'Devamı', 'tr724-news' ) . '</span>';
echo $more_icon;
echo '</button></div></section>';

wp_reset_postdata();
