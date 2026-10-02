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

function tr724_popular_slug( string $path ): string {
	$path = tr724_popular_path( $path );
	if ( '' === $path ) {
		return '';
	}
	$slug = basename( untrailingslashit( $path ) );
	if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
		return '';
	}
	return $slug;
}

/**
 * @param array<int, string> $slugs
 * @return array<string, WP_Post>
 */
function tr724_popular_posts_by_slug( array $slugs ): array {
	$slugs = array_values( array_unique( array_filter( $slugs, 'is_string' ) ) );
	if ( [] === $slugs ) {
		return [];
	}

	global $wpdb;
	$placeholders = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );
	$sql          = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_name IN ($placeholders)";
	$ids          = $wpdb->get_col( $wpdb->prepare( $sql, ...$slugs ) );
	if ( ! is_array( $ids ) || [] === $ids ) {
		return [];
	}

	$ids = array_map( 'intval', $ids );
	_prime_post_caches( $ids, false, false );

	$map = [];
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( $post instanceof WP_Post ) {
			$map[ $post->post_name ] = $post;
		}
	}
	return $map;
}

/**
 * @param array<string, mixed> $item
 * @return array{path: string, slug: string, title: string}|null
 */
function tr724_popular_read_item( array $item ): ?array {
	$path = isset( $item['path'] ) ? tr724_popular_path( (string) $item['path'] ) : '';
	$slug = '' !== $path ? tr724_popular_slug( $path ) : '';
	$title = isset( $item['title'] ) ? tr724_popular_title( (string) $item['title'] ) : '';
	if ( '' === $path || '' === $slug || '' === $title ) {
		return null;
	}
	return [
		'path'  => $path,
		'slug'  => $slug,
		'title' => $title,
	];
}

/**
 * @param array<string, mixed>          $data
 * @param array<string, WP_Post>        $posts
 * @return array{today: array<int, array{url: string, title: string, time: string, datetime: string}>, week: array<int, array{url: string, title: string, time: string, datetime: string}>, month: array<int, array{url: string, title: string, time: string, datetime: string}>}
 */
function tr724_popular_hydrate( array $data, array $posts ): array {
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

			$url      = home_url( $row['path'] );
			$title    = $row['title'];
			$time     = '';
			$datetime = '';
			$post     = $posts[ $row['slug'] ] ?? null;
			if ( $post instanceof WP_Post ) {
				$url   = get_permalink( $post );
				$title = tr724_popular_title( get_the_title( $post ) );
				if ( '' === $title ) {
					$title = $row['title'];
				}
				$published = get_post_timestamp( $post );
				if ( $published ) {
					$time     = sprintf(
						/* translators: %s: human-readable interval, such as "2 saat". */
						__( '%s önce', 'tr724-news' ),
						human_time_diff( $published )
					);
					$datetime = get_post_time( DATE_W3C, true, $post );
				}
			}
			if ( ! is_string( $url ) || '' === $url ) {
				$url = home_url( $row['path'] );
			}

			$lists[ $period ][] = [
				'url'      => $url,
				'title'    => $title,
				'time'     => $time,
				'datetime' => is_string( $datetime ) ? $datetime : '',
			];
		}
	}

	return $lists;
}

/**
 * @param array<string, mixed> $data
 * @return array{today: array<int, array{path: string, slug: string, title: string}>, week: array<int, array{path: string, slug: string, title: string}>, month: array<int, array{path: string, slug: string, title: string}>}
 */
function tr724_popular_collect( array $data ): array {
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
 * @param array{today: array<int, array{path: string, slug: string, title: string}>, week: array<int, array{path: string, slug: string, title: string}>, month: array<int, array{path: string, slug: string, title: string}>} $lists
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
 * @return array{today: array<int, array{url: string, title: string, time: string, datetime: string}>, week: array<int, array{url: string, title: string, time: string, datetime: string}>, month: array<int, array{url: string, title: string, time: string, datetime: string}>}|WP_Error
 */
function tr724_popular_lists(): array|WP_Error {
	$cache_key = 'tr724_popular_posts';
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$response = wp_remote_get(
		tr724_popular_endpoint(),
		[
			'timeout' => 8,
			'headers' => [
				'Accept' => 'application/json',
			],
		]
	);

	$lists = null;
	if ( ! is_wp_error( $response ) ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			$collected = tr724_popular_collect( $data );
			if ( tr724_popular_has_items( $collected ) ) {
				$slugs = [];
				foreach ( tr724_popular_periods() as $period ) {
					foreach ( $collected[ $period ] as $row ) {
						$slugs[] = $row['slug'];
					}
				}
				$lists = tr724_popular_hydrate( $data, tr724_popular_posts_by_slug( $slugs ) );
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
