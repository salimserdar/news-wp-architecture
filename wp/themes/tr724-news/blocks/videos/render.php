<?php
/**
 * Video carousel. Latest YouTube videos from the site aggregator.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/videos.php';

$count = isset( $attributes['videosToShow'] ) ? (int) $attributes['videosToShow'] : 10;
$count = max( 1, min( 20, $count ) );

$all_label = isset( $attributes['allLabel'] ) ? trim( (string) $attributes['allLabel'] ) : '';
if ( '' === $all_label ) {
	$all_label = __( 'Tüm Videolar', 'tr724-news' );
}

$all_url = isset( $attributes['allUrl'] ) ? trim( (string) $attributes['allUrl'] ) : '';
if ( '' === $all_url ) {
	$all_url = tr724_youtube_channel_videos_url();
}

$result  = tr724_youtube_videos();
$videos  = is_wp_error( $result ) ? [] : array_slice( $result, 0, $count );
$message = is_wp_error( $result ) ? $result->get_error_message() : '';
if ( '' === $message && [] === $videos ) {
	$message = __( 'No videos found.', 'tr724-news' );
}

$title_id = wp_unique_id( 'videos-title-' );
$play     = '<span class="video-card__play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M8 5.2v13.6L19 12z" /></svg></span>';
$prev     = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 6.5 9 12l5.5 5.5" /></svg>';
$next     = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 6.5 15 12l-5.5 5.5" /></svg>';

$wrapper = [
	'class'               => 'videos',
	'data-tr724-videos'   => '1',
	'data-loop'           => count( $videos ) >= 6 ? '1' : '0',
	'aria-labelledby'     => $title_id,
];

echo '<section ' . get_block_wrapper_attributes( $wrapper ) . '>';
echo '<header class="videos__header">';
echo '<div class="videos__heading">';
echo '<span class="videos__watermark" aria-hidden="true">VIDEO</span>';
echo '<h2 class="section-title videos__title" id="' . esc_attr( $title_id ) . '">' . esc_html__( 'VİDEO', 'tr724-news' ) . '</h2>';
echo '</div>';
if ( '' !== $all_url ) {
	echo '<a class="videos__all" href="' . esc_url( $all_url ) . '">';
	echo esc_html( $all_label ) . ' <span aria-hidden="true">→</span>';
	echo '</a>';
}
echo '</header>';

if ( [] === $videos ) {
	echo '<p class="videos__empty">' . esc_html( $message ) . '</p>';
	echo '</section>';
	return;
}

echo '<div class="videos__carousel">';
echo '<div class="swiper videos-swiper">';
echo '<div class="swiper-wrapper">';

foreach ( $videos as $index => $video ) {
	echo '<div class="swiper-slide">';
	echo '<article class="video-card">';
	echo '<a href="' . esc_url( $video['url'] ) . '">';
	echo '<div class="video-card__media">';
	if ( '' !== $video['thumb_url'] ) {
		$loading = 0 === $index ? 'eager' : 'lazy';
		echo '<img src="' . esc_url( $video['thumb_url'] ) . '" alt=""';
		if ( $video['thumb_width'] > 0 && $video['thumb_height'] > 0 ) {
			echo ' width="' . (int) $video['thumb_width'] . '" height="' . (int) $video['thumb_height'] . '"';
		}
		echo ' loading="' . esc_attr( $loading ) . '" decoding="async" />';
	}
	echo $play;
	echo '</div>';
	echo '<h3 class="video-card__title">' . esc_html( $video['title'] ) . '</h3>';
	echo '</a>';
	echo '</article>';
	echo '</div>';
}

echo '</div>';
echo '</div>';
echo '<button class="videos__nav videos__prev" type="button" aria-label="' . esc_attr__( 'Önceki videolar', 'tr724-news' ) . '">' . $prev . '</button>';
echo '<button class="videos__nav videos__next" type="button" aria-label="' . esc_attr__( 'Sonraki videolar', 'tr724-news' ) . '">' . $next . '</button>';
echo '</div>';
echo '</section>';
