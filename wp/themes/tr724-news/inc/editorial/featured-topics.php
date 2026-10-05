<?php
/**
 * Featured Topics configuration.
 *
 * Stores ordered WordPress tag IDs. The site shows each tag's own name.
 * Blocks read tr724_editorial_get_featured_topics() and do not keep their own copy.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_editorial_featured_topics_option' ) ) {
	function tr724_editorial_featured_topics_option(): string {
		return 'tr724_editorial_featured_topics';
	}
}

if ( ! function_exists( 'tr724_editorial_featured_topics_max' ) ) {
	function tr724_editorial_featured_topics_max(): int {
		return 40;
	}
}

if ( ! function_exists( 'tr724_editorial_featured_topics_config' ) ) {
	/**
	 * Saved tag IDs, in display order.
	 *
	 * @return array<int, array{tag_id: int}>
	 */
	function tr724_editorial_featured_topics_config(): array {
		$stored = get_option( tr724_editorial_featured_topics_option(), [] );
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$clean = [];
		$seen  = [];
		foreach ( $stored as $item ) {
			$id = 0;
			if ( is_array( $item ) ) {
				$id = isset( $item['tag_id'] ) ? (int) $item['tag_id'] : 0;
			} else {
				$id = (int) $item;
			}
			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$clean[]     = [
				'tag_id' => $id,
			];
		}

		return $clean;
	}
}

if ( ! function_exists( 'tr724_editorial_get_featured_topics' ) ) {
	/**
	 * Selected tags in saved order, skipping tags that were deleted.
	 * The visible name is always the WordPress tag name.
	 *
	 * @return array<int, array{id: int, slug: string, name: string, label: string, url: string}>
	 */
	function tr724_editorial_get_featured_topics(): array {
		$lang   = tr724_editorial_current_language();
		$topics = [];

		foreach ( tr724_editorial_featured_topics_config() as $item ) {
			$term = get_term( (int) $item['tag_id'], 'post_tag' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$topics[] = [
				'id'    => (int) $term->term_id,
				'slug'  => $term->slug,
				'name'  => $term->name,
				'label' => $term->name,
				'url'   => get_tag_link( $term ),
			];
		}

		$filtered = apply_filters( 'tr724_editorial_featured_topics', $topics, $lang );
		return is_array( $filtered ) ? $filtered : $topics;
	}
}

if ( ! function_exists( 'tr724_editorial_sanitize_featured_topics_request' ) ) {
	/**
	 * Turn the Featured Topics form into the stored list.
	 *
	 * @return array{topics: array<int, array{tag_id: int}>, dropped: bool}
	 */
	function tr724_editorial_sanitize_featured_topics_request(): array {
		$posted = [];
		if ( isset( $_POST['tr724_featured_topics'] ) && is_array( $_POST['tr724_featured_topics'] ) ) {
			$posted = wp_unslash( $_POST['tr724_featured_topics'] );
		}

		$order_raw = isset( $_POST['tr724_featured_topics_order'] )
			? (string) wp_unslash( $_POST['tr724_featured_topics_order'] )
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

		$max     = tr724_editorial_featured_topics_max();
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

			$term = get_term( $id, 'post_tag' );
			if ( ! $term instanceof WP_Term ) {
				$dropped = true;
				continue;
			}

			$seen[ $id ] = true;
			$clean[]     = [
				'tag_id' => $id,
			];
		}

		return [
			'topics'  => $clean,
			'dropped' => $dropped,
		];
	}
}

if ( ! function_exists( 'tr724_editorial_purge_featured_topics_cache' ) ) {
	/**
	 * Featured topics can appear on any page that contains the block.
	 */
	function tr724_editorial_purge_featured_topics_cache(): void {
		if ( function_exists( 'News\CachePurge\queue_everything' ) ) {
			\News\CachePurge\queue_everything();
		}
	}
}

add_action(
	'admin_post_tr724_save_featured_topics',
	static function (): void {
		if ( ! current_user_can( tr724_editorial_capability() ) ) {
			wp_die(
				esc_html( tr724_editorial_ui( 'no_permission' ) ),
				'',
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'tr724_save_featured_topics' );

		$result = tr724_editorial_sanitize_featured_topics_request();
		update_option( tr724_editorial_featured_topics_option(), $result['topics'] );

		$args = [
			'page'    => 'tr724-editorial',
			'updated' => '1',
		];
		foreach ( tr724_editorial_pages() as $index => $page ) {
			if ( 'featured-topics' === (string) ( $page['id'] ?? '' ) ) {
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

add_action( 'add_option_' . tr724_editorial_featured_topics_option(), 'tr724_editorial_purge_featured_topics_cache' );
add_action( 'update_option_' . tr724_editorial_featured_topics_option(), 'tr724_editorial_purge_featured_topics_cache' );
