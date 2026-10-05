<?php
/**
 * Sub page header. Watermark plus title from wp/html-them category pages.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$title = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
if ( '' === $title ) {
	$post_id = (int) get_the_ID();
	$title   = $post_id ? get_the_title( $post_id ) : '';
}
if ( '' === $title ) {
	$title = __( 'Başlık', 'tr724-news' );
}
if ( function_exists( 'tr724_archive_upper' ) ) {
	$title = tr724_archive_upper( $title );
}

$watermark = isset( $attributes['watermark'] ) ? trim( (string) $attributes['watermark'] ) : '';
if ( '' === $watermark ) {
	$watermark = $title;
} elseif ( function_exists( 'tr724_archive_upper' ) ) {
	$watermark = tr724_archive_upper( $watermark );
}

$title_id = wp_unique_id( 'sub-page-title-' );

echo '<header ' . get_block_wrapper_attributes(
	[
		'class' => 'category__header sub-page-header',
	]
) . '>';
echo '<span class="category__watermark" aria-hidden="true">' . esc_html( $watermark ) . '</span>';
echo '<h1 class="section-title category__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $title ) . '</h1>';
echo '</header>';
