<?php
/**
 * Timeline (Yayın Akışı) configuration.
 *
 * Stores one list of YouTube programs. Saving replaces that list.
 * The Timeline block reads tr724_editorial_get_timeline() and does not keep its own copy.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_editorial_timeline_option' ) ) {
	function tr724_editorial_timeline_option(): string {
		return 'tr724_editorial_timeline';
	}
}

if ( ! function_exists( 'tr724_editorial_timeline_max' ) ) {
	function tr724_editorial_timeline_max(): int {
		return 40;
	}
}

if ( ! function_exists( 'tr724_editorial_timeline_time' ) ) {
	/**
	 * 24-hour HH:MM, or an empty string.
	 */
	function tr724_editorial_timeline_time( string $value ): string {
		$value = trim( $value );
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $matches ) ) {
			return '';
		}

		$hour   = (int) $matches[1];
		$minute = (int) $matches[2];
		if ( $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 ) {
			return '';
		}

		return sprintf( '%02d:%02d', $hour, $minute );
	}
}

if ( ! function_exists( 'tr724_editorial_timeline_normalize_program' ) ) {
	/**
	 * One program, or null when the time or name is missing.
	 * url_dropped is true when a link was entered and it is not a YouTube video.
	 *
	 * @return array{time: string, name: string, url: string, url_dropped: bool}|null
	 */
	function tr724_editorial_timeline_normalize_program( mixed $program ): ?array {
		if ( ! is_array( $program ) ) {
			return null;
		}

		$time = tr724_editorial_timeline_time( isset( $program['time'] ) ? (string) $program['time'] : '' );
		$name = sanitize_text_field( isset( $program['name'] ) ? (string) $program['name'] : '' );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
		$name = mb_substr( $name, 0, 120 );
		if ( '' === $time || '' === $name ) {
			return null;
		}

		$raw_url = trim( (string) ( $program['url'] ?? '' ) );
		$url     = '';
		if ( '' !== $raw_url && function_exists( 'tr724_sanitize_youtube_url' ) ) {
			$url = tr724_sanitize_youtube_url( $raw_url );
		}

		return [
			'time'        => $time,
			'name'        => $name,
			'url'         => $url,
			'url_dropped' => '' !== $raw_url && '' === $url,
		];
	}
}

if ( ! function_exists( 'tr724_editorial_timeline_sort' ) ) {
	/**
	 * Earliest time first. Equal times keep their previous order.
	 *
	 * @param array<int, array{time: string, name: string, url: string}> $programs
	 * @return array<int, array{time: string, name: string, url: string}>
	 */
	function tr724_editorial_timeline_sort( array $programs ): array {
		$indexed = [];
		foreach ( array_values( $programs ) as $index => $program ) {
			if ( ! is_array( $program ) ) {
				continue;
			}
			$program['_seq'] = $index;
			$indexed[]       = $program;
		}

		usort(
			$indexed,
			static function ( array $a, array $b ): int {
				$by_time = strcmp( (string) $a['time'], (string) $b['time'] );
				if ( 0 !== $by_time ) {
					return $by_time;
				}
				return ( (int) $a['_seq'] ) <=> ( (int) $b['_seq'] );
			}
		);

		$sorted = [];
		foreach ( $indexed as $program ) {
			unset( $program['_seq'] );
			$sorted[] = $program;
		}

		return $sorted;
	}
}

if ( ! function_exists( 'tr724_editorial_timeline_raw_programs' ) ) {
	/**
	 * The saved list. An older build stored programs under calendar dates;
	 * that shape is read as the latest day's list until the next save.
	 *
	 * @return array<int, mixed>
	 */
	function tr724_editorial_timeline_raw_programs(): array {
		$stored = get_option( tr724_editorial_timeline_option(), [] );
		if ( ! is_array( $stored ) || ! $stored ) {
			return [];
		}

		$first = reset( $stored );
		if ( is_array( $first ) && ( isset( $first['time'] ) || isset( $first['name'] ) || isset( $first['url'] ) ) ) {
			return array_values( $stored );
		}

		$latest = '';
		$found  = [];
		foreach ( $stored as $date => $programs ) {
			if ( ! is_string( $date ) || ! is_array( $programs ) || ! $programs ) {
				continue;
			}
			if ( '' === $latest || strcmp( $date, $latest ) > 0 ) {
				$latest = $date;
				$found  = $programs;
			}
		}

		return array_values( $found );
	}
}

if ( ! function_exists( 'tr724_editorial_timeline_config' ) ) {
	/**
	 * Saved programs, in time order.
	 *
	 * @return array<int, array{time: string, name: string, url: string}>
	 */
	function tr724_editorial_timeline_config(): array {
		$max   = tr724_editorial_timeline_max();
		$clean = [];

		foreach ( tr724_editorial_timeline_raw_programs() as $program ) {
			$item = tr724_editorial_timeline_normalize_program( $program );
			if ( null === $item ) {
				continue;
			}
			unset( $item['url_dropped'] );
			$clean[] = $item;
			if ( count( $clean ) >= $max ) {
				break;
			}
		}

		return tr724_editorial_timeline_sort( $clean );
	}
}

if ( ! function_exists( 'tr724_editorial_get_timeline' ) ) {
	/**
	 * The single schedule shown by the Timeline block.
	 *
	 * @return array<int, array{time: string, name: string, url: string}>
	 */
	function tr724_editorial_get_timeline(): array {
		$items    = tr724_editorial_timeline_config();
		$filtered = apply_filters( 'tr724_editorial_timeline', $items );
		return is_array( $filtered ) ? $filtered : $items;
	}
}

if ( ! function_exists( 'tr724_editorial_sanitize_timeline_request' ) ) {
	/**
	 * Turn the Timeline form into the stored list.
	 *
	 * @return array{programs: array<int, array{time: string, name: string, url: string}>, dropped: bool}
	 */
	function tr724_editorial_sanitize_timeline_request(): array {
		$posted = [];
		if ( isset( $_POST['tr724_timeline_programs'] ) && is_array( $_POST['tr724_timeline_programs'] ) ) {
			$posted = wp_unslash( $_POST['tr724_timeline_programs'] );
		}

		$dropped = false;
		if ( count( $posted ) > 80 ) {
			$posted  = array_slice( $posted, 0, 80 );
			$dropped = true;
		}

		$max   = tr724_editorial_timeline_max();
		$clean = [];

		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$time = trim( (string) ( $row['time'] ?? '' ) );
			$name = trim( (string) ( $row['name'] ?? '' ) );
			$url  = trim( (string) ( $row['url'] ?? '' ) );
			if ( '' === $time && '' === $name && '' === $url ) {
				continue;
			}

			$item = tr724_editorial_timeline_normalize_program( $row );
			if ( null === $item ) {
				$dropped = true;
				continue;
			}
			if ( ! empty( $item['url_dropped'] ) ) {
				$dropped = true;
			}
			unset( $item['url_dropped'] );

			if ( count( $clean ) >= $max ) {
				$dropped = true;
				break;
			}
			$clean[] = $item;
		}

		return [
			'programs' => tr724_editorial_timeline_sort( $clean ),
			'dropped'  => $dropped,
		];
	}
}

if ( ! function_exists( 'tr724_editorial_timeline_redirect' ) ) {
	/**
	 * @param array<string, string> $args
	 */
	function tr724_editorial_timeline_redirect( array $args ): void {
		$args['page'] = 'tr724-editorial';
		foreach ( tr724_editorial_pages() as $index => $page ) {
			if ( 'timeline' === (string) ( $page['id'] ?? '' ) ) {
				$args['page'] = tr724_editorial_screen_slug( $index, $page );
				break;
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}

if ( ! function_exists( 'tr724_editorial_purge_timeline_cache' ) ) {
	/**
	 * The timeline can appear on any page that contains the block.
	 */
	function tr724_editorial_purge_timeline_cache(): void {
		if ( function_exists( 'News\CachePurge\queue_everything' ) ) {
			\News\CachePurge\queue_everything();
		}
	}
}

add_action(
	'admin_post_tr724_save_timeline',
	static function (): void {
		if ( ! current_user_can( tr724_editorial_capability() ) ) {
			wp_die(
				esc_html( tr724_editorial_ui( 'no_permission' ) ),
				'',
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'tr724_save_timeline' );

		$result = tr724_editorial_sanitize_timeline_request();
		update_option( tr724_editorial_timeline_option(), $result['programs'] );

		$args = [
			'updated' => '1',
		];
		if ( $result['dropped'] ) {
			$args['dropped'] = '1';
		}

		tr724_editorial_timeline_redirect( $args );
	}
);

add_action( 'add_option_' . tr724_editorial_timeline_option(), 'tr724_editorial_purge_timeline_cache' );
add_action( 'update_option_' . tr724_editorial_timeline_option(), 'tr724_editorial_purge_timeline_cache' );

add_action( 'enqueue_block_editor_assets', static function (): void {
	if ( ! wp_script_is( 'tr724-timeline-editor-script', 'registered' ) ) {
		return;
	}

	wp_add_inline_script(
		'tr724-timeline-editor-script',
		'window.tr724TimelineAdmin = ' . wp_json_encode( tr724_editorial_admin_url( 'timeline' ) ) . ';'
		. 'window.tr724TimelineUi = ' . wp_json_encode(
			[
				'title' => tr724_editorial_ui( 'timeline' ),
				'help'  => tr724_editorial_ui( 'timeline_block_help' ),
				'edit'  => tr724_editorial_ui( 'timeline_edit' ),
			]
		) . ';',
		'before'
	);
} );
