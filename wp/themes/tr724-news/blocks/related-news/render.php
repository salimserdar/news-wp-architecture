<?php
/**
 * Related stories inside an article. Editors pick the posts; readers see
 * either a compact list or one featured story.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/posts.php';

if ( ! function_exists( 'tr724_related_news_image' ) ) {
	function tr724_related_news_image( int $post_id, string $size ): string {
		$thumb_id = (int) get_post_thumbnail_id( $post_id );
		if ( $thumb_id <= 0 ) {
			return '';
		}
		$html = wp_get_attachment_image(
			$thumb_id,
			$size,
			false,
			[
				'alt'      => '',
				'loading'  => 'lazy',
				'decoding' => 'async',
			]
		);
		return is_string( $html ) ? $html : '';
	}
}

if ( ! function_exists( 'tr724_related_news_render_meta' ) ) {
	function tr724_related_news_render_meta( int $post_id ): void {
		$kicker   = tr724_related_news_category_name( $post_id );
		$date     = tr724_related_news_date( $post_id );
		$datetime = (string) get_post_time( DATE_W3C, true, $post_id );
		if ( '' === $kicker && '' === $date ) {
			return;
		}
		echo '<span class="related-news__meta">';
		if ( '' !== $kicker ) {
			echo '<span class="related-news__kicker">' . esc_html( $kicker ) . '</span>';
		}
		if ( '' !== $date ) {
			echo '<time datetime="' . esc_attr( $datetime ) . '">' . esc_html( $date ) . '</time>';
		}
		echo '</span>';
	}
}

if ( ! function_exists( 'tr724_related_news_render_row' ) ) {
	/**
	 * Compact row: thumbnail, section, date, headline.
	 */
	function tr724_related_news_render_row( WP_Post $post ): void {
		$post_id = (int) $post->ID;
		echo '<li class="related-news__item">';
		echo '<a class="related-news__link" href="' . esc_url( (string) get_permalink( $post_id ) ) . '">';
		echo '<span class="related-news__media">' . tr724_related_news_image( $post_id, 'medium' ) . '</span>';
		echo '<span class="related-news__copy">';
		tr724_related_news_render_meta( $post_id );
		echo '<span class="related-news__headline">' . esc_html( get_the_title( $post ) ) . '</span>';
		echo '</span></a></li>';
	}
}

if ( ! function_exists( 'tr724_related_news_render_single' ) ) {
	/**
	 * One story, large enough to read as a separate article.
	 */
	function tr724_related_news_render_single( WP_Post $post ): void {
		$post_id = (int) $post->ID;
		$excerpt = wp_trim_words( wp_strip_all_tags( get_the_excerpt( $post ) ), 24, '…' );

		echo '<a class="related-news__feature" href="' . esc_url( (string) get_permalink( $post_id ) ) . '">';
		echo '<span class="related-news__media">' . tr724_related_news_image( $post_id, 'medium_large' ) . '</span>';
		echo '<span class="related-news__copy">';
		tr724_related_news_render_meta( $post_id );
		echo '<span class="related-news__headline">' . esc_html( get_the_title( $post ) ) . '</span>';
		if ( '' !== $excerpt ) {
			echo '<span class="related-news__excerpt">' . esc_html( $excerpt ) . '</span>';
		}
		echo '<span class="related-news__more">' . esc_html__( 'Haberi oku', 'tr724-news' ) . ' <span aria-hidden="true">→</span></span>';
		echo '</span></a>';
	}
}

$layout = isset( $attributes['layout'] ) && 'single' === $attributes['layout'] ? 'single' : 'list';

$heading = isset( $attributes['heading'] ) ? trim( (string) $attributes['heading'] ) : '';
if ( '' === $heading && ! array_key_exists( 'heading', $attributes ) ) {
	$heading = 'single' === $layout
		? __( 'İlgili Haber', 'tr724-news' )
		: __( 'İlgili Haberler', 'tr724-news' );
}

$is_preview = function_exists( 'tr724_category_is_editor_preview' ) && tr724_category_is_editor_preview();

$exclude = 0;
if ( isset( $block->context['postId'] ) ) {
	$exclude = (int) $block->context['postId'];
}
if ( $exclude <= 0 ) {
	$exclude = (int) get_the_ID();
}
if ( $is_preview && isset( $_GET['post_id'] ) ) {
	$from_editor = (int) $_GET['post_id'];
	if ( $from_editor > 0 ) {
		$exclude = $from_editor;
	}
}

$raw_ids = isset( $attributes['postIds'] ) && is_array( $attributes['postIds'] ) ? $attributes['postIds'] : [];
$ids     = tr724_related_news_normalize_ids( $raw_ids, $exclude );
if ( 'single' === $layout ) {
	$ids = array_slice( $ids, 0, 1 );
}

$posts = tr724_related_news_posts_by_ids( $ids );

if ( ! $posts ) {
	if ( ! $is_preview ) {
		return;
	}
	echo '<section ' . get_block_wrapper_attributes(
		[
			'class' => 'related-news related-news--placeholder',
		]
	) . '>';
	echo '<p class="related-news__placeholder">' . esc_html__( 'No related stories yet.', 'tr724-news' ) . '</p>';
	echo '</section>';
	return;
}

$title_id = wp_unique_id( 'related-news-title-' );
$classes  = 'related-news related-news--' . $layout;

$wrapper = [
	'class' => $classes,
];
if ( '' !== $heading ) {
	$wrapper['aria-labelledby'] = $title_id;
} else {
	$wrapper['aria-label'] = 'single' === $layout
		? __( 'İlgili haber', 'tr724-news' )
		: __( 'İlgili haberler', 'tr724-news' );
}

echo '<section ' . get_block_wrapper_attributes( $wrapper ) . '>';

if ( '' !== $heading ) {
	echo '<h2 class="related-news__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $heading ) . '</h2>';
}

if ( 'single' === $layout ) {
	tr724_related_news_render_single( $posts[0] );
} else {
	echo '<ul class="related-news__list">';
	foreach ( $posts as $post ) {
		tr724_related_news_render_row( $post );
	}
	echo '</ul>';
}

echo '</section>';
