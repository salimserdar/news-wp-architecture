<?php
/**
 * Most-read stories from the site aggregator.
 *
 * SITE_AGGREGATOR_SERVICE_URL is the WordPress-side address of the service
 * at http://127.0.0.1:3000 on the host. Inside Docker that host is
 * host.docker.internal, not 127.0.0.1.
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return array<int, string>
 */
function tr724_popular_periods(): array {
	return [ 'today', 'week', 'month' ];
}

function tr724_popular_endpoint(): string {
	$base = 'http://127.0.0.1:3000';
	if ( defined( 'SITE_AGGREGATOR_SERVICE_URL' ) && is_string( SITE_AGGREGATOR_SERVICE_URL ) && '' !== SITE_AGGREGATOR_SERVICE_URL ) {
		$base = SITE_AGGREGATOR_SERVICE_URL;
	}
	return rtrim( $base, '/' ) . '/api/v1/popular-posts';
}

function tr724_popular_title( string $title ): string {
	$title = trim( wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	$title = (string) preg_replace( '/\s+-\s+TR724\s*$/u', '', $title );
	return trim( $title );
}

function tr724_popular_path( string $path ): string {
	$path = trim( $path );
	if ( '' === $path || str_contains( $path, '://' ) || str_starts_with( $path, '//' ) || str_contains( $path, '..' ) ) {
		return '';
	}
	if ( ! str_starts_with( $path, '/' ) ) {
		$path = '/' . $path;
	}
	if ( ! str_ends_with( $path, '/' ) ) {
		$path .= '/';
	}
	if ( ! preg_match( '#^/(?:[a-z0-9]+(?:-[a-z0-9]+)*/)+$#', $path ) ) {
		return '';
	}
	return $path;
}

/**
 * @param array<string, mixed> $item
 * @return array{url: string, title: string}|null
 */
function tr724_popular_read_item( array $item ): ?array {
	$path  = isset( $item['path'] ) ? tr724_popular_path( (string) $item['path'] ) : '';
	$title = isset( $item['title'] ) ? tr724_popular_title( (string) $item['title'] ) : '';
	if ( '' === $path || '' === $title ) {
		return null;
	}
	$url = home_url( $path );
	if ( ! is_string( $url ) || '' === $url ) {
		return null;
	}
	return [
		'url'   => $url,
		'title' => $title,
	];
}

/**
 * @param array<string, mixed> $data
 * @return array{today: array<int, array{url: string, title: string}>, week: array<int, array{url: string, title: string}>, month: array<int, array{url: string, title: string}>}
 */
function tr724_popular_from_payload( array $data ): array {
	$lists = [
		'today' => [],
		'week'  => [],
		'month' => [],
	];

	foreach ( tr724_popular_periods() as $period ) {
		if ( ! isset( $data[ $period ] ) || ! is_array( $data[ $period ] ) ) {
			continue;
		}
		foreach ( $data[ $period ] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$row = tr724_popular_read_item( $item );
			if ( null === $row ) {
				continue;
			}
			$lists[ $period ][] = $row;
		}
	}

	return $lists;
}

/**
 * @param array{today: array<int, array{url: string, title: string}>, week: array<int, array{url: string, title: string}>, month: array<int, array{url: string, title: string}>} $lists
 */
function tr724_popular_has_items( array $lists ): bool {
	foreach ( tr724_popular_periods() as $period ) {
		if ( [] !== $lists[ $period ] ) {
			return true;
		}
	}
	return false;
}

/**
 * @return array{today: array<int, array{url: string, title: string}>, week: array<int, array{url: string, title: string}>, month: array<int, array{url: string, title: string}>}|WP_Error
 */
function tr724_popular_lists(): array|WP_Error {
	$cache_key = 'tr724_popular_posts';
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	if ( function_exists( 'News\\Aggregator\\uses_vps' ) && \News\Aggregator\uses_vps() ) {
		$response = \News\Aggregator\request(
			'GET',
			'/api/v1/popular-posts',
			[
				'timeout' => 8,
			]
		);
	} else {
		$response = wp_remote_get(
			tr724_popular_endpoint(),
			[
				'timeout' => 8,
				'headers' => tr724_aggregator_headers(),
			]
		);
	}

	$lists = null;
	if ( ! is_wp_error( $response ) ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			$collected = tr724_popular_from_payload( $data );
			if ( tr724_popular_has_items( $collected ) ) {
				$lists = $collected;
			}
		}
	}

	if ( null === $lists ) {
		$stale = get_transient( 'tr724_popular_posts_last' );
		if ( is_array( $stale ) ) {
			return $stale;
		}
		return new WP_Error( 'tr724_popular_posts', __( 'Popular posts are unavailable.', 'tr724-news' ) );
	}

	set_transient( $cache_key, $lists, 5 * MINUTE_IN_SECONDS );
	set_transient( 'tr724_popular_posts_last', $lists, 12 * HOUR_IN_SECONDS );

	return $lists;
}
