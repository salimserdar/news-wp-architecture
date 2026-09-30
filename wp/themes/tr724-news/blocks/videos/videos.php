<?php
/**
 * YouTube videos from the site aggregator.
 *
 * SITE_AGGREGATOR_SERVICE_URL, YOUTUBE_API_KEY, and YOUTUBE_CHANNEL_ID
 * are wp-config constants. The key stays on the server.
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return array{url: string, width: int, height: int}
 */
function tr724_youtube_thumbnail( array $thumbnails ): array {
	$best     = [ 'url' => '', 'width' => 0, 'height' => 0 ];
	$fallback = [ 'url' => '', 'width' => 0, 'height' => 0 ];

	foreach ( $thumbnails as $thumb ) {
		if ( ! is_array( $thumb ) ) {
			continue;
		}
		$url = isset( $thumb['url'] ) ? (string) $thumb['url'] : '';
		if ( '' === $url || ! str_starts_with( $url, 'https://' ) ) {
			continue;
		}
		$width  = isset( $thumb['width'] ) ? (int) $thumb['width'] : 0;
		$height = isset( $thumb['height'] ) ? (int) $thumb['height'] : 0;
		$item   = [
			'url'    => $url,
			'width'  => $width,
			'height' => $height,
		];
		if ( $width > $fallback['width'] ) {
			$fallback = $item;
		}
		if ( $width >= 480 && ( 0 === $best['width'] || $width < $best['width'] ) ) {
			$best = $item;
		}
	}

	return '' !== $best['url'] ? $best : $fallback;
}

/**
 * @param array<string, mixed> $video
 * @return array{id: string, title: string, url: string, thumb_url: string, thumb_width: int, thumb_height: int}|null
 */
function tr724_youtube_normalize_video( array $video ): ?array {
	$title = isset( $video['title'] ) ? (string) $video['title'] : '';
	$title = trim( wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	$url   = isset( $video['url'] ) ? esc_url_raw( (string) $video['url'] ) : '';
	if ( '' === $title || '' === $url ) {
		return null;
	}

	$thumb = tr724_youtube_thumbnail( isset( $video['thumbnails'] ) && is_array( $video['thumbnails'] ) ? $video['thumbnails'] : [] );

	return [
		'id'           => isset( $video['id'] ) ? (string) $video['id'] : '',
		'title'        => $title,
		'url'          => $url,
		'thumb_url'    => $thumb['url'],
		'thumb_width'  => $thumb['width'],
		'thumb_height' => $thumb['height'],
	];
}

function tr724_youtube_configured(): bool {
	foreach ( [ 'SITE_AGGREGATOR_SERVICE_URL', 'YOUTUBE_API_KEY', 'YOUTUBE_CHANNEL_ID' ] as $constant ) {
		if ( ! defined( $constant ) || ! is_string( constant( $constant ) ) || '' === constant( $constant ) ) {
			return false;
		}
	}
	return true;
}

function tr724_youtube_channel_videos_url(): string {
	if ( ! defined( 'YOUTUBE_CHANNEL_ID' ) || ! is_string( YOUTUBE_CHANNEL_ID ) ) {
		return '';
	}
	$id = trim( YOUTUBE_CHANNEL_ID );
	if ( '' === $id || ! preg_match( '/^[A-Za-z0-9_-]+$/', $id ) ) {
		return '';
	}
	return 'https://www.youtube.com/channel/' . rawurlencode( $id ) . '/videos';
}

/**
 * @return array<int, array{id: string, title: string, url: string, thumb_url: string, thumb_width: int, thumb_height: int}>|WP_Error
 */
function tr724_youtube_videos(): array|WP_Error {
	if ( ! tr724_youtube_configured() ) {
		return new WP_Error( 'tr724_youtube_config', __( 'Video service is not configured.', 'tr724-news' ) );
	}

	$cache_key = 'tr724_yt_' . md5( YOUTUBE_CHANNEL_ID );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$endpoint = add_query_arg(
		[
			'YOUTUBE_API_KEY'    => YOUTUBE_API_KEY,
			'YOUTUBE_CHANNEL_ID' => YOUTUBE_CHANNEL_ID,
		],
		rtrim( SITE_AGGREGATOR_SERVICE_URL, '/' ) . '/api/v1/youtube/videos'
	);

	$response = wp_remote_get(
		$endpoint,
		[
			'timeout' => 8,
			'headers' => [
				'Accept' => 'application/json',
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'tr724_youtube_http', __( 'Videos are unavailable.', 'tr724-news' ) );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 || ! is_array( $data ) || ! isset( $data['videos'] ) || ! is_array( $data['videos'] ) ) {
		return new WP_Error( 'tr724_youtube_http', __( 'Videos are unavailable.', 'tr724-news' ) );
	}

	$videos = [];
	foreach ( $data['videos'] as $video ) {
		if ( ! is_array( $video ) ) {
			continue;
		}
		$normalized = tr724_youtube_normalize_video( $video );
		if ( null === $normalized ) {
			continue;
		}
		$videos[] = $normalized;
	}

	set_transient( $cache_key, $videos, 10 * MINUTE_IN_SECONDS );

	return $videos;
}
