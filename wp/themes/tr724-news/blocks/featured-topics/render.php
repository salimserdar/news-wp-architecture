<?php
/**
 * Featured topics from Editorial → Featured Topics.
 * This block has no topic or label settings of its own.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$topics = function_exists( 'tr724_editorial_get_featured_topics' )
	? tr724_editorial_get_featured_topics()
	: [];

if ( ! $topics ) {
	$preview = function_exists( 'tr724_category_is_editor_preview' ) && tr724_category_is_editor_preview();
	if ( ! $preview ) {
		return;
	}
	echo '<div ' . get_block_wrapper_attributes( [ 'class' => 'featured-topics featured-topics--empty' ] ) . '>';
	$empty = function_exists( 'tr724_editorial_ui' )
		? tr724_editorial_ui( 'block_empty' )
		: 'No featured topics yet. Choose tags in Editorial → Featured Topics.';
	echo '<p class="featured-topics__empty">' . esc_html( $empty ) . '</p>';
	echo '</div>';
	return;
}

$lang = function_exists( 'tr724_editorial_current_language' )
	? tr724_editorial_current_language()
	: 'tr';

if ( 'en' === $lang ) {
	$heading = mb_strtoupper( 'Featured Topics', 'UTF-8' );
} elseif ( function_exists( 'tr724_archive_upper' ) ) {
	$heading = tr724_archive_upper( 'Öne Çıkan Konular' );
} else {
	$heading = 'ÖNE ÇIKAN KONULAR';
}

$title_id = wp_unique_id( 'featured-topics-title-' );

echo '<section ' . get_block_wrapper_attributes(
	[
		'class'           => 'featured-topics',
		'aria-labelledby' => $title_id,
	]
) . '>';
echo '<h2 class="featured-topics__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $heading ) . '</h2>';
echo '<ul class="featured-topics__list">';

foreach ( $topics as $topic ) {
	if ( ! is_array( $topic ) ) {
		continue;
	}
	$label = isset( $topic['label'] ) ? trim( (string) $topic['label'] ) : '';
	$url   = isset( $topic['url'] ) ? (string) $topic['url'] : '';
	if ( '' === $label || '' === $url ) {
		continue;
	}
	echo '<li><a href="' . esc_url( $url ) . '" rel="tag">' . esc_html( $label ) . '</a></li>';
}

echo '</ul></section>';
