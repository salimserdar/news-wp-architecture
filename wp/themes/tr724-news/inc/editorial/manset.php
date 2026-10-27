<?php
/**
 * Manşet queue. Ordered post IDs for Spotlight and Headlines.
 *
 * Membership is still the Manşet category. Display order is this option.
 * Blocks read tr724_editorial_get_manset() instead of querying the category.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_manset_category_id' ) ) {
	/**
	 * Production Manşet category. Used to auto-sync the queue, not to query it on the front.
	 */
	function tr724_manset_category_id(): int {
		$id = (int) apply_filters( 'tr724_manset_category_id', 13694 );
		return $id > 0 ? $id : 13694;
	}
}

if ( ! function_exists( 'tr724_editorial_manset_option' ) ) {
	function tr724_editorial_manset_option(): string {
		return 'tr724_editorial_manset';
	}
}

if ( ! function_exists( 'tr724_editorial_manset_items_cache_key' ) ) {
	function tr724_editorial_manset_items_cache_key(): string {
		return 'tr724_manset_headline_items';
	}
}

if ( ! function_exists( 'tr724_editorial_manset_max' ) ) {
	function tr724_editorial_manset_max(): int {
		return 30;
	}
}

if ( ! function_exists( 'tr724_editorial_manset_is_source_category' ) ) {
	/**
	 * Spotlight uses the editorial queue for "all categories" and for Manşet.
	 */
	function tr724_editorial_manset_is_source_category( int $category_id ): bool {
		return $category_id <= 0 || $category_id === tr724_manset_category_id();
	}
}

if ( ! function_exists( 'tr724_editorial_manset_normalize' ) ) {
	/**
	 * @param mixed $stored
	 * @return array<int, array{post_id: int}>
	 */
	function tr724_editorial_manset_normalize( $stored ): array {
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$clean = [];
		$seen  = [];
		$max   = tr724_editorial_manset_max();
		foreach ( $stored as $item ) {
			$id = 0;
			if ( is_array( $item ) ) {
				$id = isset( $item['post_id'] ) ? (int) $item['post_id'] : 0;
			} else {
				$id = (int) $item;
			}
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$clean[]     = [
				'post_id' => $id,
			];
			if ( count( $clean ) >= $max ) {
				break;
			}
		}

		return $clean;
	}
}

if ( ! function_exists( 'tr724_editorial_manset_seed_from_category' ) ) {
	/**
	 * One-time backfill: latest published posts in Manşet, newest first.
	 *
	 * @return array<int, array{post_id: int}>
	 */
	function tr724_editorial_manset_seed_from_category(): array {
		$category_id = tr724_manset_category_id();
		if ( $category_id <= 0 ) {
			return [];
		}

		$query = new WP_Query(
			[
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => tr724_editorial_manset_max(),
				'cat'                 => $category_id,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'fields'              => 'ids',
			]
		);

		$items = [];
		foreach ( $query->posts as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$items[] = [
					'post_id' => $id,
				];
			}
		}

		return $items;
	}
}

if ( ! function_exists( 'tr724_editorial_manset_config' ) ) {
	/**
	 * Saved post IDs, in display order. Seeds once when the option has never been stored.
	 *
	 * @return array<int, array{post_id: int}>
	 */
	function tr724_editorial_manset_config(): array {
		$key    = tr724_editorial_manset_option();
		$stored = get_option( $key, false );
		if ( false === $stored ) {
			$stored = tr724_editorial_manset_seed_from_category();
			add_option( $key, $stored );
		}

		return tr724_editorial_manset_normalize( $stored );
	}
}

if ( ! function_exists( 'tr724_editorial_manset_ids' ) ) {
	/**
	 * @return array<int, int>
	 */
	function tr724_editorial_manset_ids(): array {
		$ids = [];
		foreach ( tr724_editorial_manset_config() as $item ) {
			$ids[] = (int) $item['post_id'];
		}
		return $ids;
	}
}

if ( ! function_exists( 'tr724_editorial_manset_save_ids' ) ) {
	/**
	 * @param array<int, int> $ids
	 */
	function tr724_editorial_manset_save_ids( array $ids ): void {
		$items = [];
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$items[] = [
					'post_id' => $id,
				];
			}
		}
		update_option( tr724_editorial_manset_option(), tr724_editorial_manset_normalize( $items ) );
	}
}

if ( ! function_exists( 'tr724_editorial_get_manset' ) ) {
	/**
	 * Published posts in saved order. Missing or unpublished IDs are skipped.
	 *
	 * @return array<int, WP_Post>
	 */
	function tr724_editorial_get_manset(): array {
		static $posts = null;
		if ( null !== $posts ) {
			return $posts;
		}

		$ids = tr724_editorial_manset_ids();
		if ( ! $ids ) {
			$posts = [];
			return $posts;
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

		$posts = [];
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$posts[] = $post;
			}
		}

		$filtered = apply_filters( 'tr724_editorial_manset', $posts, tr724_editorial_current_language() );
		$posts    = is_array( $filtered ) ? array_values( $filtered ) : $posts;

		return $posts;
	}
}

if ( ! function_exists( 'tr724_editorial_manset_headline_items' ) ) {
	/**
	 * Hydrated cards for Headlines. Cached until the queue or a listed post changes.
	 *
	 * @return array<int, array{id: int, url: string, title: string, kicker: string, datetime: string, clock: string, thumb_id: int}>
	 */
	function tr724_editorial_manset_headline_items(): array {
		$key    = tr724_editorial_manset_items_cache_key();
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$items = [];
		$posts = tr724_editorial_get_manset();
		$thumb_ids = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$thumb_id = (int) get_post_thumbnail_id( $post );
			if ( $thumb_id > 0 ) {
				$thumb_ids[] = $thumb_id;
			}
		}
		if ( $thumb_ids ) {
			_prime_post_caches( $thumb_ids, false, true );
		}

		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$post_id = (int) $post->ID;
			$kicker  = '';
			$cats    = get_the_category( $post_id );
			if ( $cats ) {
				$kicker = function_exists( 'tr724_archive_upper' )
					? tr724_archive_upper( $cats[0]->name )
					: $cats[0]->name;
			}
			$published = (int) get_post_timestamp( $post );
			$items[]   = [
				'id'       => $post_id,
				'url'      => get_permalink( $post ),
				'title'    => get_the_title( $post ),
				'kicker'   => $kicker,
				'datetime' => $published ? (string) get_post_time( DATE_W3C, true, $post ) : '',
				'clock'    => $published ? wp_date( 'H:i', $published ) : '',
				'thumb_id' => (int) get_post_thumbnail_id( $post_id ),
			];
		}

		set_transient( $key, $items, DAY_IN_SECONDS );
		return $items;
	}
}

if ( ! function_exists( 'tr724_editorial_sanitize_manset_request' ) ) {
	/**
	 * Turn the Manşet form into the stored list.
	 *
	 * @return array{posts: array<int, array{post_id: int}>, dropped: bool}
	 */
	function tr724_editorial_sanitize_manset_request(): array {
		$posted = [];
		if ( isset( $_POST['tr724_manset_posts'] ) && is_array( $_POST['tr724_manset_posts'] ) ) {
			$posted = wp_unslash( $_POST['tr724_manset_posts'] );
		}

		$order_raw = isset( $_POST['tr724_manset_order'] )
			? (string) wp_unslash( $_POST['tr724_manset_order'] )
			: '';

		$order = [];
		foreach ( preg_split( '/\s*,\s*/', $order_raw ) ?: [] as $piece ) {
			$id = (int) $piece;
			if ( $id > 0 ) {
				$order[] = $id;
			}
		}

		$posted_ids = [];
		foreach ( array_keys( $posted ) as $key ) {
			$id = (int) $key;
			if ( $id <= 0 || isset( $posted_ids[ $id ] ) ) {
				continue;
			}
			$posted_ids[ $id ] = true;
			if ( ! in_array( $id, $order, true ) ) {
				$order[] = $id;
			}
		}

		$max     = tr724_editorial_manset_max();
		$clean   = [];
		$seen    = [];
		$dropped = false;

		foreach ( $order as $id ) {
			if ( ! isset( $posted[ $id ] ) ) {
				continue;
			}
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( count( $clean ) >= $max ) {
				$dropped = true;
				break;
			}

			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
				$dropped = true;
				continue;
			}

			$seen[ $id ] = true;
			$clean[]     = [
				'post_id' => $id,
			];
		}

		return [
			'posts'   => $clean,
			'dropped' => $dropped,
		];
	}
}

if ( ! function_exists( 'tr724_editorial_purge_manset_cache' ) ) {
	/**
	 * Spotlight and Headlines can appear on the homepage and on newsroom singles.
	 */
	function tr724_editorial_purge_manset_cache(): void {
		static $queued = false;

		delete_transient( tr724_editorial_manset_items_cache_key() );

		if ( $queued ) {
			return;
		}
		$queued = true;

		if ( function_exists( 'News\CachePurge\queue_everything' ) ) {
			\News\CachePurge\queue_everything();
		}
	}
}

if ( ! function_exists( 'tr724_editorial_manset_should_skip_sync' ) ) {
	function tr724_editorial_manset_should_skip_sync( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return true;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return true;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'tr724_editorial_manset_just_published' ) ) {
	/**
	 * True when this request moved the post into publish from another status.
	 * The mark survives until set_object_terms, which often runs after save_post.
	 */
	function tr724_editorial_manset_just_published( int $post_id, bool $mark = false ): bool {
		static $ids = [];

		if ( $post_id <= 0 ) {
			return false;
		}
		if ( $mark ) {
			$ids[ $post_id ] = true;
		}

		return ! empty( $ids[ $post_id ] );
	}
}

if ( ! function_exists( 'tr724_editorial_manset_term_taxonomy_id' ) ) {
	/**
	 * term_taxonomy_id for Manşet. set_object_terms compares these, not term IDs.
	 */
	function tr724_editorial_manset_term_taxonomy_id(): int {
		$term = get_term( tr724_manset_category_id(), 'category' );
		if ( ! $term instanceof WP_Term ) {
			return 0;
		}

		return (int) $term->term_taxonomy_id;
	}
}

if ( ! function_exists( 'tr724_editorial_manset_category_gained' ) ) {
	/**
	 * True when this category write newly includes Manşet.
	 * Sending the same categories again does not count.
	 *
	 * @param mixed $tt_ids     Term taxonomy IDs written by this call.
	 * @param mixed $old_tt_ids Term taxonomy IDs the post had before this call.
	 */
	function tr724_editorial_manset_category_gained( $tt_ids, $old_tt_ids ): bool {
		$manset_tt = tr724_editorial_manset_term_taxonomy_id();
		if ( $manset_tt <= 0 || ! is_array( $tt_ids ) || ! is_array( $old_tt_ids ) ) {
			return false;
		}

		$new = array_map( 'intval', $tt_ids );
		$old = array_map( 'intval', $old_tt_ids );

		return in_array( $manset_tt, $new, true ) && ! in_array( $manset_tt, $old, true );
	}
}

if ( ! function_exists( 'tr724_editorial_manset_sync_post' ) ) {
	/**
	 * Keep the queue in sync with Manşet membership and publish status.
	 * A first publish in this request, or an update that newly assigns Manşet, is prepended.
	 * An update that already had Manşet keeps its place.
	 *
	 * @param bool $terms_changed True when categories were just written, so leaving Manşet drops the post.
	 * @param bool $manset_gained True when this write added the Manşet category.
	 */
	function tr724_editorial_manset_sync_post( int $post_id, bool $terms_changed = false, bool $manset_gained = false ): void {
		static $busy = [];

		if ( isset( $busy[ $post_id ] ) || tr724_editorial_manset_should_skip_sync( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}

		$busy[ $post_id ] = true;

		$ids       = tr724_editorial_manset_ids();
		$was_in    = in_array( $post_id, $ids, true );
		$published = 'publish' === $post->post_status;
		$in_manset = $published && has_category( tr724_manset_category_id(), $post_id );

		if ( ! $published ) {
			if ( $was_in ) {
				tr724_editorial_manset_drop_id( $post_id );
			}
			unset( $busy[ $post_id ] );
			return;
		}

		if ( $in_manset && ! $was_in ) {
			if ( tr724_editorial_manset_just_published( $post_id ) || $manset_gained ) {
				array_unshift( $ids, $post_id );
				$ids = array_slice( $ids, 0, tr724_editorial_manset_max() );
				tr724_editorial_manset_save_ids( $ids );
			}
			unset( $busy[ $post_id ] );
			return;
		}

		if ( $was_in && $terms_changed && ! $in_manset ) {
			tr724_editorial_manset_drop_id( $post_id );
			unset( $busy[ $post_id ] );
			return;
		}

		if ( $was_in ) {
			tr724_editorial_purge_manset_cache();
		}

		unset( $busy[ $post_id ] );
	}
}

if ( ! function_exists( 'tr724_editorial_manset_usable_post' ) ) {
	function tr724_editorial_manset_usable_post( $post ): ?WP_Post {
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return null;
		}
		if ( 'publish' !== $post->post_status ) {
			return null;
		}
		return $post;
	}
}

if ( ! function_exists( 'tr724_editorial_manset_extract_slug' ) ) {
	function tr724_editorial_manset_extract_slug( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$path = $raw;
		if ( preg_match( '#^https?://#i', $raw ) || str_starts_with( $raw, '/' ) ) {
			$parsed = wp_parse_url( $raw, PHP_URL_PATH );
			$path   = is_string( $parsed ) ? $parsed : '';
		} else {
			$path = strtok( $raw, '?#' ) ?: $raw;
		}

		$path = trim( (string) $path, '/' );
		if ( '' === $path ) {
			return '';
		}

		$parts = explode( '/', $path );
		$last  = (string) end( $parts );
		return rawurldecode( $last );
	}
}

if ( ! function_exists( 'tr724_editorial_manset_resolve_post' ) ) {
	/**
	 * Resolve a pasted ID, permalink, admin edit URL, or slug. Exact lookup only.
	 */
	function tr724_editorial_manset_resolve_post( string $raw ): ?WP_Post {
		$raw = trim( wp_unslash( $raw ) );
		if ( '' === $raw ) {
			return null;
		}

		if ( preg_match( '/(?:^|[?&])post=(\d+)/', $raw, $matches ) ) {
			$found = tr724_editorial_manset_usable_post( get_post( (int) $matches[1] ) );
			if ( $found ) {
				return $found;
			}
		}

		if ( preg_match( '/^\d+$/', $raw ) ) {
			$found = tr724_editorial_manset_usable_post( get_post( (int) $raw ) );
			if ( $found ) {
				return $found;
			}
		}

		if ( preg_match( '#^https?://#i', $raw ) || str_starts_with( $raw, '/' ) ) {
			$id = url_to_postid( $raw );
			if ( $id > 0 ) {
				$found = tr724_editorial_manset_usable_post( get_post( $id ) );
				if ( $found ) {
					return $found;
				}
			}
		}

		$slug = tr724_editorial_manset_extract_slug( $raw );
		if ( '' === $slug ) {
			return null;
		}

		$query = new WP_Query(
			[
				'name'                => $slug,
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => 1,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
			]
		);

		$post = $query->posts[0] ?? null;
		return tr724_editorial_manset_usable_post( $post );
	}
}

if ( ! function_exists( 'tr724_editorial_manset_drop_id' ) ) {
	function tr724_editorial_manset_drop_id( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		$ids = tr724_editorial_manset_ids();
		if ( ! in_array( $post_id, $ids, true ) ) {
			return;
		}

		$ids = array_values(
			array_filter(
				$ids,
				static fn( int $id ): bool => $id !== $post_id
			)
		);
		tr724_editorial_manset_save_ids( $ids );
	}
}

add_action(
	'admin_post_tr724_save_manset',
	static function (): void {
		if ( ! current_user_can( tr724_editorial_capability() ) ) {
			wp_die(
				esc_html( tr724_editorial_ui( 'no_permission' ) ),
				'',
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'tr724_save_manset' );

		$result = tr724_editorial_sanitize_manset_request();
		update_option( tr724_editorial_manset_option(), $result['posts'] );

		$args = [
			'page'    => 'tr724-editorial',
			'updated' => '1',
		];
		foreach ( tr724_editorial_pages() as $index => $page ) {
			if ( 'manset' === (string) ( $page['id'] ?? '' ) ) {
				$args['page'] = tr724_editorial_screen_slug( $index, $page );
				break;
			}
		}
		if ( $result['dropped'] ) {
			$args['dropped'] = '1';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
);

add_action( 'add_option_' . tr724_editorial_manset_option(), 'tr724_editorial_purge_manset_cache' );
add_action( 'update_option_' . tr724_editorial_manset_option(), 'tr724_editorial_purge_manset_cache' );

add_action(
	'transition_post_status',
	static function ( $new_status, $old_status, $post ): void {
		if ( 'publish' !== (string) $new_status || 'publish' === (string) $old_status ) {
			return;
		}
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return;
		}
		tr724_editorial_manset_just_published( (int) $post->ID, true );
	},
	10,
	3
);

add_action(
	'save_post_post',
	static function ( int $post_id ): void {
		tr724_editorial_manset_sync_post( $post_id );
	},
	20
);

add_action(
	'set_object_terms',
	static function ( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		if ( 'category' !== (string) $taxonomy ) {
			return;
		}
		$gained = tr724_editorial_manset_category_gained( $tt_ids, $old_tt_ids );
		tr724_editorial_manset_sync_post( (int) $object_id, true, $gained );
	},
	20,
	6
);

add_action(
	'deleted_post',
	static function ( $post_id ): void {
		tr724_editorial_manset_drop_id( (int) $post_id );
	}
);

add_action(
	'wp_ajax_tr724_manset_lookup',
	static function (): void {
		if ( ! current_user_can( tr724_editorial_capability() ) ) {
			wp_send_json_error( [ 'message' => tr724_editorial_ui( 'no_permission' ) ], 403 );
		}

		check_ajax_referer( 'tr724_manset_lookup', 'nonce' );

		$raw  = isset( $_POST['ref'] ) ? (string) wp_unslash( $_POST['ref'] ) : '';
		$post = tr724_editorial_manset_resolve_post( $raw );
		if ( ! $post instanceof WP_Post ) {
			wp_send_json_error( [ 'message' => tr724_editorial_ui( 'manset_none' ) ], 404 );
		}

		wp_send_json_success(
			[
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'date'  => get_the_date( 'Y-m-d H:i', $post ),
			]
		);
	}
);

add_action( 'enqueue_block_editor_assets', static function (): void {
	$payload = 'window.tr724MansetAdmin = ' . wp_json_encode( tr724_editorial_admin_url( 'manset' ) ) . ';'
		. 'window.tr724MansetCategoryId = ' . wp_json_encode( tr724_manset_category_id() ) . ';'
		. 'window.tr724MansetUi = ' . wp_json_encode(
			[
				'title' => tr724_editorial_ui( 'manset' ),
				'help'  => tr724_editorial_ui( 'manset_block_help' ),
				'edit'  => tr724_editorial_ui( 'manset_edit' ),
			]
		) . ';';

	foreach ( [ 'tr724-spotlight-editor-script', 'tr724-headlines-editor-script' ] as $handle ) {
		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_add_inline_script( $handle, $payload, 'before' );
		}
	}
} );
