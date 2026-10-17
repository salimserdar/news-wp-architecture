<?php
/**
 * Günün manşetleri. Latest posts from the Manşet category as a card carousel.
 *
 * The query is the same on every newsroom single (single.php:493) except the
 * current post is excluded. Caching per excludeId would miss on every URL.
 * Cache one list per category (count+1 rows) and drop the current id here.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_headlines_cache_key' ) ) {
	function tr724_headlines_cache_key( int $category_id ): string {
		return 'tr724_hl_' . $category_id;
	}

	/**
	 * @return array<int, array{id: int, url: string, title: string, kicker: string, datetime: string, clock: string, thumb_id: int}>
	 */
	function tr724_headlines_items( int $category_id ): array {
		$key    = tr724_headlines_cache_key( $category_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query_args = [
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => 21,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		];
		if ( $category_id > 0 ) {
			$query_args['cat'] = $category_id;
		}

		$query = new WP_Query( $query_args );
		update_post_thumbnail_cache( $query );

		$items = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$post_id  = (int) $post->ID;
			$kicker   = '';
			$cats     = get_the_category( $post_id );
			if ( $cats ) {
				$kicker = function_exists( 'tr724_archive_upper' )
					? tr724_archive_upper( $cats[0]->name )
					: $cats[0]->name;
			}
			$published = (int) get_post_timestamp( $post_id );
			$items[]   = [
				'id'       => $post_id,
				'url'      => get_permalink( $post ),
				'title'    => get_the_title( $post ),
				'kicker'   => $kicker,
				'datetime' => $published ? (string) get_post_time( DATE_W3C, true, $post ) : '',
				'clock'    => $published ? wp_date( 'H:i', $published ) : '',
				'thumb_id' => (int) get_post_thumbnail_id( $post_id ),
			];
		}
		wp_reset_postdata();

		set_transient( $key, $items, 5 * MINUTE_IN_SECONDS );
		return $items;
	}
}

$count = isset( $attributes['postsToShow'] ) ? (int) $attributes['postsToShow'] : 15;
$count = max( 4, min( 20, $count ) );

$category_id = isset( $attributes['categoryId'] ) ? (int) $attributes['categoryId'] : 13694;

$exclude_id = isset( $attributes['excludeId'] ) ? (int) $attributes['excludeId'] : 0;
if ( $exclude_id <= 0 && is_singular( 'post' ) ) {
	$exclude_id = (int) get_queried_object_id();
}

$items = tr724_headlines_items( $category_id );
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
