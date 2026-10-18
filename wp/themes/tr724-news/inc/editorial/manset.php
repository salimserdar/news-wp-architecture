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

if ( ! function_exists( 'tr724_editorial_manset_sync_post' ) ) {
	/**
	 * Keep the queue in sync with Manşet membership and publish status.
	 * New members go to the top. Existing members keep their place.
	 *
	 * @param bool $terms_changed True when categories were just written, so leaving Manşet drops the post.
	 */
	function tr724_editorial_manset_sync_post( int $post_id, bool $terms_changed = false ): void {
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
			array_unshift( $ids, $post_id );
			$ids = array_slice( $ids, 0, tr724_editorial_manset_max() );
			tr724_editorial_manset_save_ids( $ids );
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
	'save_post_post',
	static function ( int $post_id ): void {
		tr724_editorial_manset_sync_post( $post_id );
	},
	20
);

add_action(
	'set_object_terms',
	static function ( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( 'category' !== (string) $taxonomy ) {
			return;
		}
		tr724_editorial_manset_sync_post( (int) $object_id, true );
	},
	20,
	4
);

add_action(
	'deleted_post',
	static function ( $post_id ): void {
		tr724_editorial_manset_drop_id( (int) $post_id );
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
