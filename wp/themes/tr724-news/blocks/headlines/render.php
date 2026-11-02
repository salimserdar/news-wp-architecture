<?php
/**
 * Günün manşetleri. Ordered posts from Editorial → Manşet as a card carousel.
 *
 * The list is the same on every newsroom single except the current post is
 * excluded. Caching per excludeId would miss on every URL. Cache one hydrated
 * list and drop the current id here.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$count = isset( $attributes['postsToShow'] ) ? (int) $attributes['postsToShow'] : 15;
$count = max( 4, min( 20, $count ) );

$exclude_id = isset( $attributes['excludeId'] ) ? (int) $attributes['excludeId'] : 0;
if ( $exclude_id <= 0 && is_singular( 'post' ) ) {
	$exclude_id = (int) get_queried_object_id();
}

$items = function_exists( 'tr724_editorial_manset_headline_items' )
	? tr724_editorial_manset_headline_items()
	: [];
if ( $exclude_id > 0 ) {
	$items = array_values(
		array_filter(
			$items,
			static fn( array $item ): bool => $item['id'] !== $exclude_id
		)
	);
}
$items = array_slice( $items, 0, $count );

if ( [] === $items ) {
	if ( function_exists( 'tr724_category_is_editor_preview' ) && tr724_category_is_editor_preview() ) {
		echo '<section ' . get_block_wrapper_attributes(
			[
				'class' => 'headlines headlines--empty',
			]
		) . '>';
		echo '<p class="headlines__empty">' . esc_html__( 'No posts found.', 'tr724-news' ) . '</p>';
		echo '</section>';
	}
	return;
}

$title    = function_exists( 'tr724_archive_upper' )
	? tr724_archive_upper( __( 'Günün manşetleri', 'tr724-news' ) )
	: __( 'Günün manşetleri', 'tr724-news' );
$title_id = wp_unique_id( 'headlines-title-' );
$total    = count( $items );
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
foreach ( $items as $item ) {
	$image = '';
	if ( $item['thumb_id'] > 0 ) {
		$image = tr724_story_card_image(
			$item['thumb_id'],
			'',
			$index < 2 ? 'eager' : 'lazy'
		);
	}

	echo '<div class="swiper-slide">';
	echo '<article class="headlines-card">';
	echo '<a href="' . esc_url( $item['url'] ) . '">';
	echo '<div class="headlines-card__media">';
	echo $image;
	echo '</div>';
	echo '<p class="headlines-card__meta">';
	if ( '' !== $item['kicker'] ) {
		echo '<span class="headlines-card__kicker">' . esc_html( $item['kicker'] ) . '</span>';
	}
	if ( '' !== $item['clock'] ) {
		echo '<time datetime="' . esc_attr( $item['datetime'] ) . '">' . esc_html( $item['clock'] ) . '</time>';
	}
	echo '</p>';
	echo '<h3 class="headlines-card__title">' . esc_html( $item['title'] ) . '</h3>';
	echo '</a></article></div>';
	++$index;
}

echo '</div></div>';
echo '</section>';
