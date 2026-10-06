<?php
/**
 * Plugin Name: News Media Search
 * Description: Replaces attachment text search with GET /api/v1/search/media. Core search stays when the service is unreachable or the query has author, parent, date, or non-image constraints.
 * Version:     1.0.1
 *
 * Base URL: SITE_AGGREGATOR_SERVICE_URL (wp-config.php constant, then the environment variable).
 */

namespace News\MediaSearch;

defined( 'ABSPATH' ) || exit;

add_action( 'pre_get_posts', __NAMESPACE__ . '\handle_query', 20 );
add_filter( 'found_posts', __NAMESPACE__ . '\filter_found_posts', 20, 2 );
add_action( 'wp_enqueue_media', __NAMESPACE__ . '\enqueue_order_script' );

/**
 * Keep the media grid in service rank order. The grid otherwise sorts by date.
 */
function enqueue_order_script(): void {
	wp_enqueue_script(
		'news-media-search',
		plugins_url( 'media-search-order.js', __FILE__ ),
		[ 'media-models' ],
		'1.0.1',
		true
	);
}

function handle_query( \WP_Query $query ): void {
	if ( ! should_handle( $query ) ) {
		return;
	}

	$search = trim( (string) $query->get( 's' ) );
	$page   = page_number( $query );
	$limit  = page_limit( $query );
	if ( ( $page - 1 ) * $limit >= 1000 ) {
		return;
	}

	$result = search( $search, $page, $limit );
	if ( null === $result ) {
		return;
	}

	$ids = ids_from_results( $result['results'] );
	if ( ! $ids ) {
		$ids = [ 0 ];
	}

	$query->set( 's', '' );
	$query->set( 'post__in', $ids );
	$query->set( 'orderby', 'post__in' );
	// The service already returned this page. Reset SQL paging so those IDs are not skipped.
	$query->set( 'paged', 1 );
	if ( is_numeric( $query->get( 'offset' ) ) ) {
		$query->set( 'offset', 0 );
	}
	$query->news_media_search_found_posts = (int) $result['total'];
}

/**
 * @param int $found Number of rows MySQL reported.
 */
function filter_found_posts( $found, \WP_Query $query ): int {
	if ( isset( $query->news_media_search_found_posts ) ) {
		return (int) $query->news_media_search_found_posts;
	}
	return (int) $found;
}

function should_handle( \WP_Query $query ): bool {
	$post_type = $query->get( 'post_type' );
	if ( is_array( $post_type ) ) {
		$post_type = array_values( $post_type );
		if ( [ 'attachment' ] !== $post_type ) {
			return false;
		}
	} elseif ( 'attachment' !== $post_type ) {
		return false;
	}

	$search = $query->get( 's' );
	if ( ! is_string( $search ) || '' === trim( $search ) ) {
		return false;
	}

	return ! has_blocked_constraint( $query );
}

function has_blocked_constraint( \WP_Query $query ): bool {
	$author = $query->get( 'author' );
	if ( is_numeric( $author ) && (int) $author !== 0 ) {
		return true;
	}
	if ( is_string( $author ) && '' !== $author && '0' !== $author ) {
		return true;
	}

	$parent = $query->get( 'post_parent' );
	if ( '' !== $parent && null !== $parent && false !== $parent ) {
		return true;
	}

	if ( (int) $query->get( 'year' ) > 0 || (int) $query->get( 'monthnum' ) > 0 ) {
		return true;
	}

	$post_in = $query->get( 'post__in' );
	if ( is_array( $post_in ) && [] !== array_filter( $post_in ) ) {
		return true;
	}

	return ! mime_allows_service( $query->get( 'post_mime_type' ) );
}

/**
 * Empty mime and image types can use the service. Other types stay on core search.
 */
function mime_allows_service( mixed $mime ): bool {
	if ( '' === $mime || null === $mime || false === $mime ) {
		return true;
	}
	if ( ! is_array( $mime ) ) {
		$mime = [ $mime ];
	}
	if ( ! $mime ) {
		return true;
	}
	foreach ( $mime as $type ) {
		if ( ! is_string( $type ) ) {
			return false;
		}
		$type = strtolower( $type );
		if ( 'image' !== $type && ! str_starts_with( $type, 'image/' ) ) {
			return false;
		}
	}
	return true;
}

function page_number( \WP_Query $query ): int {
	$page = (int) $query->get( 'paged' );
	if ( $page < 1 ) {
		$page = (int) $query->get( 'page' );
	}
	if ( $page < 1 ) {
		$page = 1;
	}
	return $page;
}

function page_limit( \WP_Query $query ): int {
	$limit = (int) $query->get( 'posts_per_page' );
	if ( $limit < 1 ) {
		$limit = 20;
	}
	if ( $limit > 100 ) {
		$limit = 100;
	}
	return $limit;
}

/**
 * @return array{total:int,results:array<int,mixed>}|null
 */
function search( string $search, int $page, int $limit ): ?array {
	$response = wp_remote_get(
		add_query_arg(
			[
				'q'     => $search,
				'page'  => $page,
				'limit' => $limit,
			],
			service_base_url() . '/api/v1/search/media'
		),
		[
			'timeout' => 5,
			'headers' => [
				'Accept' => 'application/json',
			],
		]
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || ! isset( $data['total'], $data['results'] ) || ! is_array( $data['results'] ) || ! is_numeric( $data['total'] ) ) {
		return null;
	}

	return [
		'total'   => (int) $data['total'],
		'results' => $data['results'],
	];
}

/**
 * @param array<int,mixed> $results
 * @return list<int>
 */
function ids_from_results( array $results ): array {
	$ids = [];
	foreach ( $results as $hit ) {
		if ( ! is_array( $hit ) || ! isset( $hit['id'] ) ) {
			continue;
		}
		$id = (int) $hit['id'];
		if ( $id > 0 ) {
			$ids[] = $id;
		}
	}
	return array_values( array_unique( $ids ) );
}

function service_base_url(): string {
	if ( defined( 'SITE_AGGREGATOR_SERVICE_URL' ) && is_string( SITE_AGGREGATOR_SERVICE_URL ) && '' !== SITE_AGGREGATOR_SERVICE_URL ) {
		return untrailingslashit( SITE_AGGREGATOR_SERVICE_URL );
	}

	$env = getenv( 'SITE_AGGREGATOR_SERVICE_URL' );
	if ( is_string( $env ) && '' !== $env ) {
		return untrailingslashit( $env );
	}

	return 'http://host.docker.internal:80';
}
