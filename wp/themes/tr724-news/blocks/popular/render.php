<?php
/**
 * Most-read sidebar. Today, week, and month lists from the site aggregator.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/posts.php';

$title_id = wp_unique_id( 'popular-title-' );
$tabs     = [
	'today' => [
		'label' => __( 'BUGÜN', 'tr724-news' ),
		'id'    => wp_unique_id( 'popular-tab-today-' ),
		'panel' => wp_unique_id( 'popular-today-' ),
	],
	'week'  => [
		'label' => __( 'BU HAFTA', 'tr724-news' ),
		'id'    => wp_unique_id( 'popular-tab-week-' ),
		'panel' => wp_unique_id( 'popular-week-' ),
	],
	'month' => [
		'label' => __( 'BU AY', 'tr724-news' ),
		'id'    => wp_unique_id( 'popular-tab-month-' ),
		'panel' => wp_unique_id( 'popular-month-' ),
	],
];

$result  = tr724_popular_lists();
$message = is_wp_error( $result ) ? $result->get_error_message() : '';

$wrapper = [
	'class'              => 'popular',
	'data-tr724-popular' => '1',
	'aria-labelledby'    => $title_id,
];

echo '<section ' . get_block_wrapper_attributes( $wrapper ) . '>';

$image_id = isset( $attributes['adImageId'] ) ? (int) $attributes['adImageId'] : 0;
$ad_label = isset( $attributes['adLabel'] ) ? trim( (string) $attributes['adLabel'] ) : '';
$ad_alt   = '' !== $ad_label ? $ad_label : __( 'Reklam', 'tr724-news' );
$ad_image = '';
if ( $image_id > 0 ) {
	$attachment_alt = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
	if ( '' !== $attachment_alt ) {
		$ad_alt = $attachment_alt;
	}
	$ad_image = wp_get_attachment_image(
		$image_id,
		'medium_large',
		false,
		[
			'alt'      => $ad_alt,
			'loading'  => 'lazy',
			'decoding' => 'async',
		]
	);
}

if ( '' !== $ad_image ) {
	$ad_url = isset( $attributes['adUrl'] ) ? esc_url( $attributes['adUrl'] ) : '';
	if ( '' !== $ad_url ) {
		echo '<a class="popular__ad" href="' . $ad_url . '" aria-label="' . esc_attr( $ad_alt ) . '">';
		echo $ad_image;
		echo '</a>';
	} else {
		echo '<div class="popular__ad">';
		echo $ad_image;
		echo '</div>';
	}
	if ( '' !== $ad_label ) {
		echo '<p class="popular__ad-label">' . esc_html( $ad_label ) . '</p>';
	}
} elseif ( $image_id > 0 && function_exists( 'tr724_category_is_editor_preview' ) && tr724_category_is_editor_preview() ) {
	echo '<p class="popular__empty">' . esc_html__( 'Select an ad image', 'tr724-news' ) . '</p>';
}

echo '<div class="popular__box">';
echo '<div class="popular__head">';
echo '<h2 class="popular__title" id="' . esc_attr( $title_id ) . '">' . esc_html__( 'EN ÇOK OKUNANLAR', 'tr724-news' ) . '</h2>';

if ( '' !== $message ) {
	echo '</div>';
	echo '<p class="popular__empty">' . esc_html( $message ) . '</p>';
	echo '</div></section>';
	return;
}

echo '<div class="popular__tabs" role="tablist" aria-label="' . esc_attr__( 'Zaman aralığı', 'tr724-news' ) . '">';
foreach ( $tabs as $period => $tab ) {
	$selected = 'week' === $period;
	echo '<button class="popular__tab" type="button" role="tab"';
	echo ' id="' . esc_attr( $tab['id'] ) . '"';
	echo ' aria-controls="' . esc_attr( $tab['panel'] ) . '"';
	echo ' aria-selected="' . ( $selected ? 'true' : 'false' ) . '"';
	if ( ! $selected ) {
		echo ' tabindex="-1"';
	}
	echo '>' . esc_html( $tab['label'] ) . '</button>';
}
echo '</div></div>';

foreach ( $tabs as $period => $tab ) {
	$selected = 'week' === $period;
	$items    = isset( $result[ $period ] ) && is_array( $result[ $period ] ) ? $result[ $period ] : [];
	echo '<ol class="popular__list" id="' . esc_attr( $tab['panel'] ) . '" role="tabpanel"';
	echo ' aria-labelledby="' . esc_attr( $tab['id'] ) . '"';
	if ( ! $selected ) {
		echo ' hidden';
	}
	echo '>';
	if ( [] === $items ) {
		echo '<li class="popular__empty">' . esc_html__( 'No popular posts found.', 'tr724-news' ) . '</li>';
	}
	foreach ( $items as $index => $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$url   = isset( $item['url'] ) ? (string) $item['url'] : '';
		$title = isset( $item['title'] ) ? (string) $item['title'] : '';
		if ( '' === $url || '' === $title ) {
			continue;
		}
		$time     = isset( $item['time'] ) ? (string) $item['time'] : '';
		$datetime = isset( $item['datetime'] ) ? (string) $item['datetime'] : '';
		echo '<li class="popular__item">';
		echo '<a href="' . esc_url( $url ) . '">';
		echo '<span class="popular__rank">' . (int) ( $index + 1 ) . '</span>';
		echo '<span class="popular__copy">';
		if ( '' !== $time ) {
			echo '<time';
			if ( '' !== $datetime ) {
				echo ' datetime="' . esc_attr( $datetime ) . '"';
			}
			echo '>' . esc_html( $time ) . '</time>';
		}
		echo '<span class="popular__headline">' . esc_html( $title ) . '</span>';
		echo '</span></a></li>';
	}
	echo '</ol>';
}

echo '</div></section>';
