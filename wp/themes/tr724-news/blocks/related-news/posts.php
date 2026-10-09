<?php
/**
 * Related-news lookup for the block editor and the public render.
 *
 * Editor search calls the site aggregator:
 * {SITE_AGGREGATOR_SERVICE_URL}/api/v1/search/posts?q=&page=1&limit=100&status=publish&sort=date:desc
 * A pasted link or numeric ID still resolves that one story in WordPress.
 * The public block renders the chosen IDs from WordPress.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Unique published-post IDs, capped for an in-article module.
 *
 * @param array<int, mixed> $ids
 * @param int               $exclude Post currently being edited or read.
 * @return int[]
 */
function tr724_related_news_normalize_ids( array $ids, int $exclude = 0 ): array {
	$clean = [];
	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( $id <= 0 || $id === $exclude || isset( $clean[ $id ] ) ) {
			continue;
		}
		$clean[ $id ] = $id;
		if ( count( $clean ) >= 8 ) {
			break;
		}
	}
	return array_values( $clean );
}

/**
 * @param mixed $value Comma-separated IDs or a list.
 * @return int[]
 */
function tr724_related_news_id_list( $value, int $max ): array {
	$parts = is_array( $value ) ? $value : explode( ',', (string) $value );
	$ids   = [];
	foreach ( $parts as $part ) {
		$id = (int) $part;
		if ( $id <= 0 || in_array( $id, $ids, true ) ) {
			continue;
		}
		$ids[] = $id;
		if ( count( $ids ) >= $max ) {
			break;
		}
	}
	return $ids;
}

function tr724_related_news_date( int $post_id ): string {
	$published = (int) get_post_timestamp( $post_id );
	if ( ! $published ) {
		return '';
	}
	return wp_date( 'j F Y', $published );
}

function tr724_related_news_category_name( int $post_id ): string {
	$categories = get_the_category( $post_id );
	if ( ! $categories ) {
		return '';
	}
	return $categories[0]->name;
}

/**
 * Compact story used by the editor search and the selected list.
 *
 * @return array{id: int, title: string, date: string, category: string, thumb: string}
 */
function tr724_related_news_summary( WP_Post $post ): array {
	$post_id  = (int) $post->ID;
	$thumb_id = (int) get_post_thumbnail_id( $post_id );
	$thumb    = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';

	return [
		'id'       => $post_id,
		'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
		'date'     => tr724_related_news_date( $post_id ),
		'category' => tr724_related_news_category_name( $post_id ),
		'thumb'    => $thumb,
	];
}

/**
 * @param int[] $ids
 * @return WP_Post[]
 */
function tr724_related_news_posts_by_ids( array $ids ): array {
	$ids = tr724_related_news_normalize_ids( $ids );
	if ( ! $ids ) {
		return [];
	}

	$query = new WP_Query(
		[
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'post__in'            => $ids,
			'orderby'             => 'post__in',
			'posts_per_page'      => count( $ids ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		]
	);
	update_post_thumbnail_cache( $query );
	$posts = $query->posts;
	wp_reset_postdata();

	$ordered = [];
	if ( is_array( $posts ) ) {
		foreach ( $posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$ordered[] = $post;
			}
		}
	}
	return $ordered;
}

/**
 * Permalink, "#123", or a post ID of 5+ digits. Shorter numbers stay in
 * title search so a year like 2024 does not open an unrelated story.
 */
function tr724_related_news_is_direct_query( string $search ): bool {
	$search = trim( $search );
	if ( preg_match( '#^https?://#i', $search ) ) {
		return true;
	}
	if ( preg_match( '/^(?:#|id\s*:\s*)\d+$/i', $search ) ) {
		return true;
	}
	return ctype_digit( $search ) && strlen( $search ) >= 5;
}

/**
 * A pasted permalink or ID, when it is a published story.
 */
function tr724_related_news_direct_post( string $search ): ?WP_Post {
	$search  = trim( $search );
	$post_id = 0;

	if ( preg_match( '#^https?://#i', $search ) ) {
		$post_id = (int) url_to_postid( $search );
		if ( $post_id <= 0 ) {
			$path = wp_parse_url( $search, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				$post_id = (int) url_to_postid( home_url( $path ) );
			}
		}
	} elseif ( preg_match( '/^(?:#|id\s*:\s*)(\d+)$/i', $search, $matches ) ) {
		$post_id = (int) $matches[1];
	} elseif ( ctype_digit( $search ) && strlen( $search ) >= 5 ) {
		$post_id = (int) $search;
	}

	$post = $post_id > 0 ? get_post( $post_id ) : null;
	if ( ! $post instanceof WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return null;
	}
	return $post;
}

/**
 * Drop media URLs that only resolve inside the aggregator's own host.
 */
function tr724_related_news_remote_thumb( string $url ): string {
	$url = esc_url_raw( trim( $url ) );
	if ( '' === $url ) {
		return '';
	}
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! is_string( $host ) || '' === $host ) {
		return '';
	}
	$host = strtolower( $host );
	if ( 'localhost' === $host || '127.0.0.1' === $host || str_ends_with( $host, '.localhost' ) ) {
		return '';
	}
	return $url;
}

/**
 * One aggregator hit, preferring the local post for the thumbnail and title.
 *
 * @param array<string, mixed> $item
 * @return array{id: int, title: string, date: string, category: string, thumb: string}|null
 */
function tr724_related_news_summary_from_hit( array $item, ?WP_Post $post ): ?array {
	if ( $post instanceof WP_Post ) {
		return tr724_related_news_summary( $post );
	}

	$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
	$title = isset( $item['title'] ) ? trim( wp_strip_all_tags( html_entity_decode( (string) $item['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) : '';
	$title = (string) preg_replace( '/\s+-\s+TR724\s*$/u', '', $title );
	$title = trim( $title );
	if ( $id <= 0 || '' === $title ) {
		return null;
	}

	$date = '';
	if ( isset( $item['date'] ) && is_string( $item['date'] ) && '' !== $item['date'] ) {
		$published = strtotime( $item['date'] );
		if ( $published ) {
			$date = wp_date( 'j F Y', $published );
		}
	}

	$category = '';
	if ( isset( $item['categories'][0] ) && is_array( $item['categories'][0] ) && isset( $item['categories'][0]['name'] ) ) {
		$category = trim( wp_strip_all_tags( (string) $item['categories'][0]['name'] ) );
	}

	$thumb = isset( $item['thumbnail'] ) ? tr724_related_news_remote_thumb( (string) $item['thumbnail'] ) : '';

	return [
		'id'       => $id,
		'title'    => $title,
		'date'     => $date,
		'category' => $category,
		'thumb'    => $thumb,
	];
}

/**
 * Published stories from the site aggregator, in aggregator order.
 *
 * @param int[] $exclude
 * @return array<int, array{id: int, title: string, date: string, category: string, thumb: string}>|WP_Error
 */
function tr724_related_news_search_posts( string $search, int $category_id, array $exclude, int $author_id = 0 ): array|WP_Error {
	$search = trim( wp_strip_all_tags( $search ) );
	if ( mb_strlen( $search ) > 160 ) {
		$search = mb_substr( $search, 0, 160 );
	}

	if ( tr724_related_news_is_direct_query( $search ) ) {
		$direct = tr724_related_news_direct_post( $search );
		if ( $direct instanceof WP_Post && ! in_array( (int) $direct->ID, $exclude, true ) ) {
			return [ tr724_related_news_summary( $direct ) ];
		}
		return [];
	}

	if ( mb_strlen( $search ) < 2 ) {
		return [];
	}

	if ( ! function_exists( 'tr724_search_endpoint' ) ) {
		return new WP_Error(
			'tr724_related_news_search',
			__( 'Search is unavailable.', 'tr724-news' ),
			[ 'status' => 502 ]
		);
	}

	$query_args = [
		'q'      => $search,
		'page'   => 1,
		'limit'  => 100,
		'status' => 'publish',
		'sort'   => 'date:desc',
	];
	if ( $category_id > 0 ) {
		$query_args['categoryId'] = $category_id;
	}
	if ( $author_id > 0 ) {
		$query_args['authorId'] = $author_id;
	}

	$response = wp_remote_get(
		add_query_arg( $query_args, tr724_search_endpoint() ),
		[
			'timeout'            => 8,
			'reject_unsafe_urls' => false,
			'headers'            => [
				'Accept' => 'application/json',
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'tr724_related_news_search',
			__( 'Search is unavailable.', 'tr724-news' ),
			[ 'status' => 502 ]
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( $code < 200 || $code >= 300 || ! is_array( $data ) || ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
		return new WP_Error(
			'tr724_related_news_search',
			__( 'Search is unavailable.', 'tr724-news' ),
			[ 'status' => 502 ]
		);
	}

	$hits = [];
	$ids  = [];
	foreach ( $data['results'] as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}
		$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		if ( $id <= 0 || in_array( $id, $exclude, true ) || isset( $hits[ $id ] ) ) {
			continue;
		}
		$hits[ $id ] = $item;
		$ids[]       = $id;
	}

	$local = [];
	if ( $ids ) {
		$query = new WP_Query(
			[
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'post__in'            => $ids,
				'orderby'             => 'post__in',
				'posts_per_page'      => count( $ids ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			]
		);
		update_post_thumbnail_cache( $query );
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$local[ (int) $post->ID ] = $post;
			}
		}
		wp_reset_postdata();
	}

	$rows = [];
	foreach ( $ids as $id ) {
		$post = $local[ $id ] ?? null;
		$row  = tr724_related_news_summary_from_hit( $hits[ $id ], $post instanceof WP_Post ? $post : null );
		if ( null !== $row ) {
			$rows[] = $row;
		}
	}
	return $rows;
}

/**
 * @param WP_Post[] $posts
 * @return array<int, array{id: int, title: string, date: string, category: string, thumb: string}>
 */
function tr724_related_news_summaries( array $posts ): array {
	$rows = [];
	foreach ( $posts as $post ) {
		if ( $post instanceof WP_Post ) {
			$rows[] = tr724_related_news_summary( $post );
		}
	}
	return $rows;
}

add_action( 'rest_api_init', static function (): void {
	register_rest_route(
		'tr724/v1',
		'/related-news',
		[
			'methods'             => 'GET',
			'permission_callback' => static function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'callback'            => static function ( WP_REST_Request $request ) {
				$include = tr724_related_news_id_list( $request->get_param( 'include' ), 8 );
				if ( $include ) {
					return rest_ensure_response(
						[
							'posts' => tr724_related_news_summaries( tr724_related_news_posts_by_ids( $include ) ),
						]
					);
				}

				$search   = $request->get_param( 'search' );
				$search   = is_string( $search ) ? $search : '';
				$category = (int) $request->get_param( 'category' );
				$author   = (int) $request->get_param( 'author' );
				$exclude  = tr724_related_news_id_list( $request->get_param( 'exclude' ), 20 );
				$posts    = tr724_related_news_search_posts( $search, $category, $exclude, $author );
				if ( is_wp_error( $posts ) ) {
					return $posts;
				}

				return rest_ensure_response(
					[
						'posts' => $posts,
					]
				);
			},
			'args'                => [
				'search'   => [
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => static function ( $value ): string {
						$value = is_string( $value ) ? trim( wp_strip_all_tags( $value ) ) : '';
						if ( mb_strlen( $value ) > 160 ) {
							$value = mb_substr( $value, 0, 160 );
						}
						return $value;
					},
				],
				'include'  => [
					'type'    => 'string',
					'default' => '',
				],
				'exclude'  => [
					'type'    => 'string',
					'default' => '',
				],
				'category' => [
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
				'author'   => [
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
			],
		]
	);
} );
