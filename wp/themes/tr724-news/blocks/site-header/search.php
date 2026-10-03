<?php
/**
 * Header search against the site aggregator.
 *
 * The browser calls this route. The route calls
 * /api/v1/search/posts?q=&page=&limit=20&sort=date:desc on the aggregator, which is not
 * reachable from the browser (no CORS, and inside Docker the host is
 * host.docker.internal).
 */

defined( 'ABSPATH' ) || exit;

function tr724_search_endpoint(): string {
	$base = 'http://127.0.0.1:3000';
	if ( defined( 'SITE_AGGREGATOR_SERVICE_URL' ) && is_string( SITE_AGGREGATOR_SERVICE_URL ) && '' !== SITE_AGGREGATOR_SERVICE_URL ) {
		$base = SITE_AGGREGATOR_SERVICE_URL;
	}
	return rtrim( $base, '/' ) . '/api/v1/search/posts';
}

function tr724_search_sort( string $sort ): string {
	return 'date:asc' === $sort ? 'date:asc' : 'date:desc';
}

function tr724_search_page_link( string $query, int $page = 1, string $sort = 'date:desc' ): string {
	$args = [
		's'    => $query,
		'sort' => tr724_search_sort( $sort ),
	];
	if ( $page > 1 ) {
		$args['paged'] = $page;
	}
	return add_query_arg( $args, home_url( '/' ) );
}

function tr724_search_query( string $query ): string {
	$query = trim( wp_strip_all_tags( $query ) );
	$query = (string) preg_replace( '/\s+/u', ' ', $query );
	$length = mb_strlen( $query );
	if ( $length < 2 || $length > 120 || preg_match( '/[\x00-\x1F\x7F]/', $query ) ) {
		return '';
	}
	return $query;
}

function tr724_search_title( string $title ): string {
	$title = trim( wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	$title = (string) preg_replace( '/\s+-\s+TR724\s*$/u', '', $title );
	return trim( $title );
}

function tr724_search_path( string $link ): string {
	$path = wp_parse_url( trim( $link ), PHP_URL_PATH );
	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}
	$path = rawurldecode( $path );
	if ( str_contains( $path, '..' ) || str_contains( $path, '\\' ) || str_contains( $path, '://' ) || str_contains( $path, '//' ) ) {
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
 * @return array{url: string, title: string, kicker: string, author: string, time: string, datetime: string}|null
 */
function tr724_search_read_result( array $item ): ?array {
	$title = isset( $item['title'] ) ? tr724_search_title( (string) $item['title'] ) : '';
	$path  = isset( $item['link'] ) ? tr724_search_path( (string) $item['link'] ) : '';
	if ( '' === $title || '' === $path ) {
		return null;
	}

	$kicker = '';
	if ( isset( $item['categories'][0] ) && is_array( $item['categories'][0] ) && isset( $item['categories'][0]['name'] ) ) {
		$kicker = trim( wp_strip_all_tags( (string) $item['categories'][0]['name'] ) );
		if ( '' !== $kicker && function_exists( 'tr724_ticker_upper' ) ) {
			$kicker = tr724_ticker_upper( $kicker );
		}
	}

	$author = '';
	if ( isset( $item['author'] ) && is_array( $item['author'] ) && isset( $item['author']['name'] ) ) {
		$author = trim( wp_strip_all_tags( (string) $item['author']['name'] ) );
	}

	$time     = '';
	$datetime = '';
	if ( isset( $item['date'] ) && is_string( $item['date'] ) && '' !== $item['date'] ) {
		$published = strtotime( $item['date'] );
		if ( $published ) {
			$time     = sprintf(
				/* translators: %s: human-readable interval, such as "2 saat". */
				__( '%s önce', 'tr724-news' ),
				human_time_diff( $published )
			);
			$datetime = gmdate( 'c', $published );
		}
	}

	return [
		'url'      => home_url( $path ),
		'title'    => $title,
		'kicker'   => $kicker,
		'author'   => $author,
		'time'     => $time,
		'datetime' => $datetime,
	];
}

/**
 * @return array{query: string, page: int, limit: int, sort: string, total: int, results: array<int, array{url: string, title: string, kicker: string, author: string, time: string, datetime: string}>}|WP_Error
 */
function tr724_search_posts( string $query, int $page, string $sort = 'date:desc' ): array|WP_Error {
	$query = tr724_search_query( $query );
	if ( '' === $query ) {
		return new WP_Error(
			'tr724_search_query',
			__( 'Arama için en az 2 karakter yazın.', 'tr724-news' ),
			[ 'status' => 400 ]
		);
	}

	$page  = max( 1, min( 50, $page ) );
	$limit = 20;
	$sort  = tr724_search_sort( $sort );
	$url   = add_query_arg(
		[
			'q'      => $query,
			'page'   => $page,
			'limit'  => $limit,
			'status' => 'publish',
			'sort'   => $sort,
		],
		tr724_search_endpoint()
	);

	$response = wp_remote_get(
		$url,
		[
			'timeout' => 8,
			'headers' => [
				'Accept' => 'application/json',
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'tr724_search_unavailable',
			__( 'Arama şu anda kullanılamıyor.', 'tr724-news' ),
			[ 'status' => 502 ]
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 || ! is_array( $data ) || ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
		return new WP_Error(
			'tr724_search_unavailable',
			__( 'Arama şu anda kullanılamıyor.', 'tr724-news' ),
			[ 'status' => 502 ]
		);
	}

	$results = [];
	foreach ( $data['results'] as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$row = tr724_search_read_result( $item );
		if ( null === $row ) {
			continue;
		}
		$results[] = $row;
	}

	$total = isset( $data['total'] ) ? (int) $data['total'] : count( $results );
	if ( $total < count( $results ) ) {
		$total = count( $results );
	}

	return [
		'query'   => $query,
		'page'    => $page,
		'limit'   => $limit,
		'sort'    => $sort,
		'total'   => $total,
		'results' => $results,
	];
}

add_action( 'rest_api_init', static function (): void {
	register_rest_route(
		'tr724/v1',
		'/search/posts',
		[
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => [
				'q'    => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => static function ( $value ): string {
						return is_string( $value ) ? tr724_search_query( $value ) : '';
					},
				],
				'page' => [
					'default'           => 1,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
				'sort' => [
					'default'           => 'date:desc',
					'type'              => 'string',
					'sanitize_callback' => static function ( $value ): string {
						return is_string( $value ) ? tr724_search_sort( $value ) : 'date:desc';
					},
				],
			],
			'callback'            => static function ( WP_REST_Request $request ) {
				$result = tr724_search_posts(
					(string) $request->get_param( 'q' ),
					(int) $request->get_param( 'page' ),
					(string) $request->get_param( 'sort' )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return rest_ensure_response( $result );
			},
		]
	);
} );
