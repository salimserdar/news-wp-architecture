<?php
/**
 * Site links used by the header, footer, and article pages.
 *
 * One option holds the social, Patreon, WhatsApp, and app-store addresses.
 * Templates read tr724_editorial_get_links() and do not keep their own copy.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_editorial_links_option' ) ) {
	function tr724_editorial_links_option(): string {
		return 'tr724_editorial_links';
	}
}

if ( ! function_exists( 'tr724_editorial_links_capability' ) ) {
	/**
	 * Only the Administrator role can see or save these addresses.
	 */
	function tr724_editorial_links_capability(): string {
		return 'manage_options';
	}
}

if ( ! function_exists( 'tr724_editorial_links_defaults' ) ) {
	/**
	 * Values shown before the first save. Empty social addresses stay blank here;
	 * templates turn a blank social address into "#".
	 *
	 * @return array<string, string>
	 */
	function tr724_editorial_links_defaults(): array {
		return [
			'facebook'          => '',
			'x'                 => '',
			'instagram'         => '',
			'youtube'           => '',
			'patreon'           => 'https://www.patreon.com/tr724',
			'whatsapp_phone'    => '0212 212 12 12',
			'whatsapp_url'      => 'https://wa.me/902122121212',
			'whatsapp_channel'  => 'https://whatsapp.com/channel/0029Va8otpD4tRruE0WH4G0T',
			'ios'               => 'https://www.apple.com/app-store/',
			'android'           => 'https://play.google.com/store',
		];
	}
}

if ( ! function_exists( 'tr724_editorial_sanitize_link_url' ) ) {
	/**
	 * Absolute http(s) address, or an empty string when the value is blank or invalid.
	 */
	function tr724_editorial_sanitize_link_url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || '#' === $value ) {
			return '';
		}

		$url = esc_url_raw( $value );
		if ( '' === $url ) {
			return '';
		}

		return mb_substr( $url, 0, 500 );
	}
}

if ( ! function_exists( 'tr724_editorial_links_whatsapp_url' ) ) {
	/**
	 * Use the entered address. When it is empty, build https://wa.me/ from the phone digits.
	 */
	function tr724_editorial_links_whatsapp_url( string $phone, string $url ): string {
		$url = tr724_editorial_sanitize_link_url( $url );
		if ( '' !== $url ) {
			return $url;
		}

		$digits = preg_replace( '/\D+/', '', $phone );
		if ( ! is_string( $digits ) || '' === $digits ) {
			return '';
		}

		return 'https://wa.me/' . $digits;
	}
}

if ( ! function_exists( 'tr724_editorial_links_memo' ) ) {
	/**
	 * Request-local copy. Header, footer, and the article page read the same list many times.
	 *
	 * @return array{loaded: bool, links: array<string, string>}
	 */
	function &tr724_editorial_links_memo(): array {
		static $memo = [
			'loaded' => false,
			'links'  => [],
		];
		return $memo;
	}
}

if ( ! function_exists( 'tr724_editorial_read_links' ) ) {
	/**
	 * Saved addresses, with defaults for any key that has never been stored.
	 * The option is autoloaded, so this reads the options already in memory.
	 *
	 * @return array<string, string>
	 */
	function tr724_editorial_read_links(): array {
		$defaults = tr724_editorial_links_defaults();
		$stored   = get_option( tr724_editorial_links_option(), false );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$links = [];
		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				$links[ $key ] = $default;
				continue;
			}
			if ( 'whatsapp_phone' === $key ) {
				$links[ $key ] = mb_substr( sanitize_text_field( (string) $stored[ $key ] ), 0, 40 );
				continue;
			}
			$links[ $key ] = tr724_editorial_sanitize_link_url( (string) $stored[ $key ] );
		}

		return $links;
	}
}

if ( ! function_exists( 'tr724_editorial_get_links' ) ) {
	/**
	 * Addresses for this request. The first call loads them; later calls reuse that copy.
	 *
	 * @return array<string, string>
	 */
	function tr724_editorial_get_links(): array {
		$memo = &tr724_editorial_links_memo();
		if ( $memo['loaded'] ) {
			return $memo['links'];
		}

		$memo['links']  = tr724_editorial_read_links();
		$memo['loaded'] = true;
		return $memo['links'];
	}
}

if ( ! function_exists( 'tr724_editorial_link' ) ) {
	/**
	 * One address ready for an href. A blank URL becomes "#". The phone is returned as stored.
	 */
	function tr724_editorial_link( string $key ): string {
		$links = tr724_editorial_get_links();
		if ( ! isset( $links[ $key ] ) ) {
			return 'whatsapp_phone' === $key ? '' : '#';
		}

		$value = trim( (string) $links[ $key ] );
		if ( 'whatsapp_phone' === $key ) {
			return $value;
		}

		return '' !== $value ? $value : '#';
	}
}

if ( ! function_exists( 'tr724_editorial_sanitize_links_request' ) ) {
	/**
	 * @return array<string, string>
	 */
	function tr724_editorial_sanitize_links_request(): array {
		$posted = [];
		if ( isset( $_POST['tr724_links'] ) && is_array( $_POST['tr724_links'] ) ) {
			$posted = wp_unslash( $_POST['tr724_links'] );
		}

		$clean = [];
		foreach ( array_keys( tr724_editorial_links_defaults() ) as $key ) {
			$raw = isset( $posted[ $key ] ) ? (string) $posted[ $key ] : '';
			if ( 'whatsapp_phone' === $key ) {
				$clean[ $key ] = mb_substr( sanitize_text_field( $raw ), 0, 40 );
				continue;
			}
			$clean[ $key ] = tr724_editorial_sanitize_link_url( $raw );
		}

		$clean['whatsapp_url'] = tr724_editorial_links_whatsapp_url(
			$clean['whatsapp_phone'],
			$clean['whatsapp_url']
		);

		return $clean;
	}
}

if ( ! function_exists( 'tr724_editorial_links_redirect' ) ) {
	/**
	 * @param array<string, string> $args
	 */
	function tr724_editorial_links_redirect( array $args ): void {
		$args['page'] = 'tr724-editorial';
		foreach ( tr724_editorial_pages() as $index => $page ) {
			if ( 'links' === (string) ( $page['id'] ?? '' ) ) {
				$args['page'] = tr724_editorial_screen_slug( $index, $page );
				break;
			}
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}

if ( ! function_exists( 'tr724_editorial_purge_links_cache' ) ) {
	/**
	 * These addresses appear in the header, footer, and on article pages.
	 */
	function tr724_editorial_purge_links_cache(): void {
		$memo            = &tr724_editorial_links_memo();
		$memo['loaded']  = false;
		$memo['links']   = [];

		if ( function_exists( 'News\CachePurge\queue_everything' ) ) {
			\News\CachePurge\queue_everything();
		}
	}
}

add_action(
	'admin_post_tr724_save_links',
	static function (): void {
		if ( ! current_user_can( tr724_editorial_links_capability() ) ) {
			wp_die(
				esc_html( tr724_editorial_ui( 'no_permission' ) ),
				'',
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'tr724_save_links' );

		update_option( tr724_editorial_links_option(), tr724_editorial_sanitize_links_request(), true );
		tr724_editorial_links_redirect(
			[
				'updated' => '1',
			]
		);
	}
);

add_action( 'add_option_' . tr724_editorial_links_option(), 'tr724_editorial_purge_links_cache' );
add_action( 'update_option_' . tr724_editorial_links_option(), 'tr724_editorial_purge_links_cache' );
