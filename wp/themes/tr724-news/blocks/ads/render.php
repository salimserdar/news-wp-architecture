<?php
/**
 * Page ad. Same fields and markup as the Ads widget.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$attributes = is_array( $attributes ) ? $attributes : [];
$markup     = tr724_category_ad_markup( $attributes );
$preview    = tr724_category_is_editor_preview();

if ( '' === $markup && ! $preview ) {
	return;
}

echo '<div ' . get_block_wrapper_attributes() . '>';

if ( '' === $markup ) {
	echo '<aside class="ads" aria-label="' . esc_attr__( 'Reklamlar', 'tr724-news' ) . '">';
	echo '<div class="ads__preview">' . esc_html__( 'Choose an ad in the block settings.', 'tr724-news' ) . '</div>';
	echo '</aside>';
} else {
	echo $markup;
}

echo '</div>';
