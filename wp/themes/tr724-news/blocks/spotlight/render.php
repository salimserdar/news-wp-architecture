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

$filtered_kicker = '';
if ( $category_id > 0 ) {
	$term = get_term( $category_id, 'category' );
	if ( $term instanceof WP_Term ) {
		$filtered_kicker = $term->name;
	}
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
	$kicker  = $filtered_kicker;
	if ( '' === $kicker ) {
		$categories = get_the_category( $post_id );
		if ( $categories ) {
			$kicker = $categories[0]->name;
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
			'large',
			false,
			[
				'alt'           => $alt,
				'loading'       => 0 === $index ? 'eager' : 'lazy',
				'fetchpriority' => 0 === $index ? 'high' : 'low',
				'decoding'      => 'async',
			]
		);
	}

	echo '<div class="swiper-slide">';
	echo '<a class="news-slide" href="' . esc_url( get_permalink( $post_id ) ) . '">';
	echo $image;
	echo '<span class="news-slide__shade" aria-hidden="true"></span>';
	echo '<span class="news-slide__copy">';
	if ( '' !== $kicker ) {
		echo '<span class="news-slide__kicker">' . esc_html( $kicker ) . '</span>';
	}
	echo '<h3 class="news-slide__title">' . esc_html( get_the_title( $post_id ) ) . '</h3>';
	echo '</span></a></div>';
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
