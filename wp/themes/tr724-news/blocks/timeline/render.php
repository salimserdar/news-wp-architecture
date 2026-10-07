<?php
/**
 * Timeline (Yayın Akışı).
 * Programs come from Editorial → Timeline. This block has no schedule of its own.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$anchor = isset( $attributes['anchor'] ) ? trim( (string) $attributes['anchor'] ) : '';
$anchor = preg_replace( '/[^A-Za-z0-9\-_:.]+/', '', $anchor );
$anchor = is_string( $anchor ) ? substr( $anchor, 0, 80 ) : '';
if ( ! preg_match( '/^[A-Za-z]/', $anchor ) ) {
	$anchor = '';
}

$programs = function_exists( 'tr724_editorial_get_timeline' )
	? tr724_editorial_get_timeline()
	: [];

$items = [];
foreach ( $programs as $program ) {
	if ( ! is_array( $program ) ) {
		continue;
	}
	if ( ! empty( $program['hidden'] ) ) {
		continue;
	}
	$time = isset( $program['time'] ) ? trim( (string) $program['time'] ) : '';
	$name = isset( $program['name'] ) ? trim( (string) $program['name'] ) : '';
	$url  = isset( $program['url'] ) ? trim( (string) $program['url'] ) : '';
	if ( '' === $time || '' === $name ) {
		continue;
	}
	if ( '' !== $url && function_exists( 'tr724_youtube_id' ) && '' === tr724_youtube_id( $url ) ) {
		$url = '';
	}
	$items[] = [
		'time' => $time,
		'name' => $name,
		'url'  => $url,
	];
}

if ( ! $items ) {
	$preview = function_exists( 'tr724_category_is_editor_preview' ) && tr724_category_is_editor_preview();
	if ( ! $preview ) {
		return;
	}
	$empty_wrapper = [ 'class' => 'timeline timeline--empty' ];
	if ( '' !== $anchor ) {
		$empty_wrapper['id'] = $anchor;
	}
	echo '<div ' . get_block_wrapper_attributes( $empty_wrapper ) . '>';
	$empty = function_exists( 'tr724_editorial_ui' )
		? tr724_editorial_ui( 'timeline_block_empty' )
		: 'No programs yet. Add them in Editorial → Timeline.';
	echo '<p class="timeline__empty">' . esc_html( $empty ) . '</p>';
	echo '</div>';
	return;
}

$lang = function_exists( 'tr724_editorial_current_language' )
	? tr724_editorial_current_language()
	: 'tr';

if ( 'en' === $lang ) {
	$heading = 'TIMELINE';
} elseif ( function_exists( 'tr724_archive_upper' ) ) {
	$heading = tr724_archive_upper( 'Yayın Akışı' );
} else {
	$heading = 'YAYIN AKIŞI';
}

$title_id = wp_unique_id( 'timeline-title-' );
$play     = '<svg class="timeline__play" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="#ff0000" d="M23 12.2s0-3.2-.4-4.6c-.2-.9-.9-1.6-1.8-1.8C19.2 5.4 12 5.4 12 5.4s-7.2 0-8.8.4c-.9.2-1.6.9-1.8 1.8C1 9 1 12.2 1 12.2s0 3.2.4 4.6c.2.9.9 1.6 1.8 1.8 1.6.4 8.8.4 8.8.4s7.2 0 8.8-.4c.9-.2 1.6-.9 1.8-1.8.4-1.4.4-4.6.4-4.6z"/><path fill="#fff" d="M9.8 15.5V8.9l6 3.3-6 3.3z"/></svg>';

$wrapper = [
	'class'           => 'timeline',
	'aria-labelledby' => $title_id,
];
if ( '' !== $anchor ) {
	$wrapper['id'] = $anchor;
}
echo '<section ' . get_block_wrapper_attributes( $wrapper ) . '>';
echo '<h2 class="timeline__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $heading ) . '</h2>';
echo '<ul class="timeline__list">';

foreach ( $items as $item ) {
	echo '<li class="timeline__item">';
	if ( '' !== $item['url'] ) {
		echo '<a class="timeline__program" href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener noreferrer">';
	} else {
		echo '<div class="timeline__program">';
	}
	echo '<time class="timeline__time" datetime="' . esc_attr( $item['time'] ) . '">' . esc_html( $item['time'] ) . '</time>';
	echo '<span class="timeline__name">' . esc_html( $item['name'] ) . '</span>';
	if ( '' !== $item['url'] ) {
		echo $play; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</a>';
	} else {
		echo '</div>';
	}
	echo '</li>';
}

echo '</ul></section>';
