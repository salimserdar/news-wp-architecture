<?php
/**
 * Published post detail for the mobile app.
 *
 * Fields follow the single post template. The first gallery block is returned
 * separately and removed from content_html, matching single.php.
 */

namespace Tr724\App;

defined( 'ABSPATH' ) || exit;

function register_routes(): void {
	$key = TR724_APP_ROUTE_KEY;
	if ( ! is_string( $key ) || '' === $key ) {
		return;
	}

	$common = [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'show_in_index'       => false,
	];

	register_rest_route(
		$key,
		'/d/(?P<id>\d+)',
		array_merge(
			$common,
			[
				'callback' => __NAMESPACE__ . '\detail_by_id',
				'args'     => [
					'id' => [
						'description'       => 'Published post ID.',
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => static function ( $value ): int {
							return (int) $value;
						},
					],
				],
			]
		)
	);

	register_rest_route(
		$key,
		'/d',
		array_merge(
			$common,
			[
				'callback' => __NAMESPACE__ . '\detail_by_path',
				'args'     => [
					'path' => [
						'description'       => 'Site path or canonical absolute URL of a published post.',
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => static function ( $value ): string {
							return is_string( $value ) ? trim( $value ) : '';
						},
					],
				],
			]
		)
	);

	add_filter( 'rest_index', __NAMESPACE__ . '\hide_from_index' );
	add_filter( 'rest_namespace_index', __NAMESPACE__ . '\hide_namespace_index', 10, 2 );
}

/**
 * Drop this namespace from the public REST index.
 *
 * @param mixed $response Index response.
 * @return mixed
 */
function hide_from_index( $response ) {
	if ( ! $response instanceof \WP_REST_Response ) {
		return $response;
	}
	$data = $response->get_data();
	if ( ! is_array( $data ) ) {
		return $response;
	}

	$key    = TR724_APP_ROUTE_KEY;
	$prefix = '/' . $key;

	if ( isset( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) {
		$data['namespaces'] = array_values(
			array_filter(
				$data['namespaces'],
				static function ( $namespace ) use ( $key ): bool {
					return $namespace !== $key;
				}
			)
		);
	}

	if ( isset( $data['routes'] ) && is_array( $data['routes'] ) ) {
		foreach ( array_keys( $data['routes'] ) as $route ) {
			if ( ! is_string( $route ) ) {
				continue;
			}
			if ( $route === $prefix || str_starts_with( $route, $prefix . '/' ) ) {
				unset( $data['routes'][ $route ] );
			}
		}
	}

	$response->set_data( $data );
	return $response;
}

/**
 * The namespace index would list every route. Respond as if it does not exist.
 *
 * @param mixed $response Index response.
 * @param mixed $request  Request.
 * @return mixed
 */
function hide_namespace_index( $response, $request ) {
	if ( ! $request instanceof \WP_REST_Request ) {
		return $response;
	}
	$namespace = $request->get_param( 'namespace' );
	if ( ! is_string( $namespace ) || '' === $namespace ) {
		$namespace = (string) ( $request['namespace'] ?? '' );
	}
	if ( TR724_APP_ROUTE_KEY !== $namespace ) {
		return $response;
	}

	return new \WP_Error(
		'rest_no_route',
		'No route was found matching the URL and request method.',
		[ 'status' => 404 ]
	);
}

/**
 * @return \WP_REST_Response|\WP_Error
 */
function detail_by_id( \WP_REST_Request $request ) {
	$post = published_post( (int) $request->get_param( 'id' ) );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	return rest_ensure_response( payload( $post ) );
}

/**
 * @return \WP_REST_Response|\WP_Error
 */
function detail_by_path( \WP_REST_Request $request ) {
	$path = $request->get_param( 'path' );
	$path = is_string( $path ) ? $path : '';
	if ( '' === $path ) {
		return new \WP_Error(
			'rest_missing_callback_param',
			'Missing parameter(s): path',
			[
				'status' => 400,
				'params' => [ 'path' ],
			]
		);
	}

	$post_id = post_id_from_path( $path );
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}
	$post = published_post( $post_id );
	if ( is_wp_error( $post ) ) {
		return $post;
	}
	return rest_ensure_response( payload( $post ) );
}

/**
 * Resolve a site path or canonical URL to a post ID. Off-site URLs are a 404.
 *
 * @return int|\WP_Error
 */
function post_id_from_path( string $path ) {
	if ( strlen( $path ) > 2048 ) {
		return not_found();
	}

	$home      = wp_parse_url( home_url( '/' ) );
	$home_host = strtolower( (string) ( is_array( $home ) ? ( $home['host'] ?? '' ) : '' ) );

	if ( str_starts_with( $path, '//' ) || preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path ) ) {
		$parts = wp_parse_url( $path );
		$host  = strtolower( (string) ( is_array( $parts ) ? ( $parts['host'] ?? '' ) : '' ) );
		if ( '' === $host || '' === $home_host || $host !== $home_host ) {
			return not_found();
		}
		$path = (string) ( is_array( $parts ) ? ( $parts['path'] ?? '/' ) : '/' );
	}

	$path = strtok( $path, "?#" );
	$path = is_string( $path ) ? $path : '';
	if ( '' === $path ) {
		return not_found();
	}
	if ( ! str_starts_with( $path, '/' ) ) {
		$path = '/' . $path;
	}

	return (int) url_to_postid( home_url( $path ) );
}

/**
 * @return \WP_Post|\WP_Error
 */
function published_post( int $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
		return not_found();
	}
	return $post;
}

function not_found(): \WP_Error {
	return new \WP_Error(
		'tr724_app_not_found',
		'Not found.',
		[ 'status' => 404 ]
	);
}

/**
 * @return array<string, mixed>
 */
function payload( \WP_Post $post ): array {
	$title     = get_the_title( $post );
	$permalink = (string) get_permalink( $post );
	$excerpt   = has_excerpt( $post ) ? get_the_excerpt( $post ) : '';
	$published     = get_post_time( DATE_W3C, true, $post );
	$modified      = get_post_modified_time( DATE_W3C, true, $post );
	$gallery_block = find_gallery( parse_blocks( (string) $post->post_content ) );
	$gallery       = null !== $gallery_block ? gallery_images( $gallery_block ) : [];

	return [
		'id'           => (int) $post->ID,
		'slug'         => $post->post_name,
		'url'          => $permalink,
		'title'        => $title,
		'excerpt'      => is_string( $excerpt ) ? $excerpt : '',
		'published_at' => is_string( $published ) ? $published : '',
		'modified_at'  => is_string( $modified ) ? $modified : '',
		'author'       => author_payload( $post ),
		'breadcrumb'   => breadcrumb( $post ),
		'categories'   => category_payload( $post ),
		'tags'         => tag_payload( $post ),
		'hero'         => hero( $post, $title ),
		'gallery'      => $gallery,
		'content_html' => content_html( $post, null !== $gallery_block ),
	];
}

/**
 * @return array{id: int, name: string, url: string, avatar: string, kind: string}
 */
function author_payload( \WP_Post $post ): array {
	$author_id = (int) $post->post_author;
	$name      = (string) get_the_author_meta( 'display_name', $author_id );
	if ( '' === $name ) {
		$name = (string) get_the_author_meta( 'user_login', $author_id );
	}

	return [
		'id'     => $author_id,
		'name'   => $name,
		'url'    => get_author_posts_url( $author_id ),
		'avatar' => author_avatar_url( $author_id ),
		'kind'   => author_kind( $author_id ),
	];
}

function author_kind( int $author_id ): string {
	$user = get_userdata( $author_id );
	if ( ! $user instanceof \WP_User ) {
		return '';
	}
	$roles = array_values( array_filter( (array) $user->roles, 'is_string' ) );
	return $roles[0] ?? '';
}

/**
 * Profile photo from the theme field, then older avatar fields, then the WordPress avatar.
 */
function author_avatar_url( int $user_id ): string {
	$attachment_id = author_avatar_attachment_id( $user_id );
	if ( $attachment_id > 0 ) {
		$url = wp_get_original_image_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			$url = (string) wp_get_attachment_url( $attachment_id );
		}
		if ( '' !== $url ) {
			return $url;
		}
	}

	$fallback = get_avatar_url( $user_id, [ 'size' => 96 ] );
	return is_string( $fallback ) ? $fallback : '';
}

/**
 * Attachment chosen on the user profile. The theme owns the field order.
 */
function author_avatar_attachment_id( int $user_id ): int {
	if ( function_exists( 'tr724_author_photo_id' ) ) {
		return (int) tr724_author_photo_id( $user_id );
	}

	foreach ( [ 'tr724_author_photo', 'wp_user_avatar', 'yazar_resim', 'yazar_photo' ] as $meta_key ) {
		$attachment_id = (int) get_user_meta( $user_id, $meta_key, true );
		if ( $attachment_id <= 0 ) {
			continue;
		}
		$url = wp_get_original_image_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			$url = (string) wp_get_attachment_url( $attachment_id );
		}
		if ( '' !== $url ) {
			return $attachment_id;
		}
	}
	return 0;
}

/**
 * Home, then the first category and its ancestors. Same trail as the article page.
 *
 * @return array<int, array{id: int, name: string, slug: string, url: string}>
 */
function breadcrumb( \WP_Post $post ): array {
	$crumbs = [
		[
			'id'   => 0,
			'name' => 'Ana Sayfa',
			'slug' => '',
			'url'  => home_url( '/' ),
		],
	];

	$categories = get_the_category( $post->ID );
	if ( ! $categories ) {
		return $crumbs;
	}

	$category     = $categories[0];
	$ancestor_ids = array_reverse( get_ancestors( (int) $category->term_id, 'category' ) );
	foreach ( $ancestor_ids as $ancestor_id ) {
		$ancestor = get_term( (int) $ancestor_id, 'category' );
		$item     = $ancestor instanceof \WP_Term ? term_payload( $ancestor ) : null;
		if ( null !== $item ) {
			$crumbs[] = $item;
		}
	}

	$item = term_payload( $category );
	if ( null !== $item ) {
		$crumbs[] = $item;
	}
	return $crumbs;
}

/**
 * @return array<int, array{id: int, name: string, slug: string, url: string}>
 */
function category_payload( \WP_Post $post ): array {
	$terms = get_the_category( $post->ID );
	return term_list( is_array( $terms ) ? $terms : [] );
}

/**
 * @return array<int, array{id: int, name: string, slug: string, url: string}>
 */
function tag_payload( \WP_Post $post ): array {
	$terms = get_the_tags( $post->ID );
	return term_list( is_array( $terms ) ? $terms : [] );
}

/**
 * @param \WP_Term[] $terms Terms.
 * @return array<int, array{id: int, name: string, slug: string, url: string}>
 */
function term_list( array $terms ): array {
	$rows = [];
	foreach ( $terms as $term ) {
		if ( ! $term instanceof \WP_Term ) {
			continue;
		}
		$item = term_payload( $term );
		if ( null !== $item ) {
			$rows[] = $item;
		}
	}
	return $rows;
}

/**
 * @return array{id: int, name: string, slug: string, url: string}|null
 */
function term_payload( \WP_Term $term ): ?array {
	$link = get_term_link( $term );
	if ( is_wp_error( $link ) ) {
		return null;
	}
	return [
		'id'   => (int) $term->term_id,
		'name' => $term->name,
		'slug' => $term->slug,
		'url'  => $link,
	];
}

/**
 * YouTube hero wins over the featured image, as on the article page.
 *
 * @return array<string, mixed>
 */
function hero( \WP_Post $post, string $title ): array {
	$youtube_url = '';
	if ( function_exists( 'tr724_youtube_url' ) ) {
		$youtube_url = (string) tr724_youtube_url( (int) $post->ID );
	}
	$youtube_id = '';
	if ( '' !== $youtube_url && function_exists( 'tr724_youtube_id' ) ) {
		$youtube_id = (string) tr724_youtube_id( $youtube_url );
	}
	if ( '' !== $youtube_id ) {
		return [
			'type'  => 'video',
			'video' => [
				'youtube_id' => $youtube_id,
				'url'        => $youtube_url,
				'embed_url'  => 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $youtube_id ),
			],
		];
	}

	$thumb_id = (int) get_post_thumbnail_id( $post );
	if ( $thumb_id <= 0 ) {
		return [ 'type' => 'none' ];
	}

	$url = wp_get_original_image_url( $thumb_id );
	if ( ! is_string( $url ) || '' === $url ) {
		$url = (string) wp_get_attachment_url( $thumb_id );
	}
	if ( '' === $url ) {
		return [ 'type' => 'none' ];
	}

	$alt = (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
	if ( '' === $alt ) {
		$alt = $title;
	}
	$caption = wp_get_attachment_caption( $thumb_id );

	return [
		'type'  => 'image',
		'image' => [
			'url'     => $url,
			'alt'     => $alt,
			'caption' => is_string( $caption ) ? $caption : '',
		],
	];
}

/**
 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
 * @return array<string, mixed>|null
 */
function find_gallery( array $blocks ): ?array {
	foreach ( $blocks as $block ) {
		if ( ( $block['blockName'] ?? '' ) === 'core/gallery' ) {
			return $block;
		}
		$inner = $block['innerBlocks'] ?? [];
		if ( is_array( $inner ) && $inner ) {
			$found = find_gallery( $inner );
			if ( null !== $found ) {
				return $found;
			}
		}
	}
	return null;
}

/**
 * @param array<string, mixed> $block Gallery block.
 * @return array<int, array{src: string, thumb: string, alt: string}>
 */
function gallery_images( array $block ): array {
	$images     = [];
	$inner      = $block['innerBlocks'] ?? [];
	$used_inner = false;
	if ( ! is_array( $inner ) ) {
		$inner = [];
	}

	foreach ( $inner as $image_block ) {
		if ( ! is_array( $image_block ) || ( $image_block['blockName'] ?? '' ) !== 'core/image' ) {
			continue;
		}
		$used_inner = true;
		$attrs      = $image_block['attrs'] ?? [];
		if ( ! is_array( $attrs ) ) {
			$attrs = [];
		}
		$id   = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
		$alt  = isset( $attrs['alt'] ) ? (string) $attrs['alt'] : '';
		$item = image_data( $id, $alt );
		if ( null === $item ) {
			$url = isset( $attrs['url'] ) ? (string) $attrs['url'] : '';
			if ( '' !== $url ) {
				$item = [
					'src'   => $url,
					'thumb' => $url,
					'alt'   => $alt,
				];
			}
		}
		if ( null !== $item ) {
			$images[] = $item;
		}
	}

	if ( $used_inner ) {
		return $images;
	}

	$ids = $block['attrs']['ids'] ?? [];
	if ( ! is_array( $ids ) ) {
		return $images;
	}
	foreach ( $ids as $id ) {
		$item = image_data( (int) $id );
		if ( null !== $item ) {
			$images[] = $item;
		}
	}
	return $images;
}

/**
 * @return array{src: string, thumb: string, alt: string}|null
 */
function image_data( int $id, string $alt = '' ): ?array {
	if ( $id <= 0 ) {
		return null;
	}
	$src = wp_get_attachment_image_url( $id, 'large' );
	if ( ! is_string( $src ) || '' === $src ) {
		$src = (string) wp_get_attachment_url( $id );
	}
	if ( '' === $src ) {
		return null;
	}
	$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
	if ( ! is_string( $thumb ) || '' === $thumb ) {
		$thumb = $src;
	}
	if ( '' === $alt ) {
		$alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
	}
	return [
		'src'   => $src,
		'thumb' => $thumb,
		'alt'   => $alt,
	];
}

/**
 * Rendered article HTML. The first gallery block is omitted when the gallery array carries it.
 */
function content_html( \WP_Post $post, bool $strip_gallery ): string {
	$previous        = $GLOBALS['post'] ?? null;
	$GLOBALS['post'] = $post;
	setup_postdata( $post );

	$filter = null;
	if ( $strip_gallery ) {
		$skip   = true;
		$filter = static function ( $html, $block ) use ( &$skip ): string {
			$html = is_string( $html ) ? $html : '';
			if ( $skip && is_array( $block ) && ( $block['blockName'] ?? '' ) === 'core/gallery' ) {
				$skip = false;
				return '';
			}
			return $html;
		};
		add_filter( 'render_block', $filter, 10, 2 );
	}

	try {
		$html = apply_filters( 'the_content', $post->post_content );
	} finally {
		if ( null !== $filter ) {
			remove_filter( 'render_block', $filter, 10 );
		}
		wp_reset_postdata();
		if ( $previous instanceof \WP_Post ) {
			$GLOBALS['post'] = $previous;
			setup_postdata( $previous );
		} else {
			unset( $GLOBALS['post'] );
		}
	}

	return is_string( $html ) ? $html : '';
}
