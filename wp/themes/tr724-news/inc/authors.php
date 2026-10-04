<?php
/**
 * Shared author photo and social links for the yazarlar block and author archives.
 * Direct children of the yazarlar category resolve to the matching author archive.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_yazarlar_href' ) ) {
	/**
	 * Turn a stored profile URL or @handle into an absolute http(s) URL.
	 */
	function tr724_yazarlar_href( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( str_starts_with( $raw, '@' ) ) {
			$handle = rawurlencode( ltrim( substr( $raw, 1 ), '@' ) );
			return '' === $handle ? '' : 'https://twitter.com/' . $handle;
		}
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			$raw = 'https://' . ltrim( $raw, '/' );
		}
		return $raw;
	}
}

if ( ! function_exists( 'tr724_yazarlar_avatar' ) ) {
	/**
	 * Author photo from the custom avatar fields, then the WordPress avatar.
	 *
	 * @param array $args {
	 *     @type string $class      Extra class on the image.
	 *     @type string $alt        Alt text.
	 *     @type int    $size       Pixel size for the WordPress avatar.
	 *     @type string $image_size Attachment size for a custom photo.
	 * }
	 */
	function tr724_yazarlar_avatar( int $user_id, array $args = [] ): string {
		$class      = isset( $args['class'] ) ? (string) $args['class'] : '';
		$alt        = isset( $args['alt'] ) ? (string) $args['alt'] : '';
		$size       = isset( $args['size'] ) ? max( 1, (int) $args['size'] ) : 96;
		$image_size = isset( $args['image_size'] ) ? (string) $args['image_size'] : 'thumbnail';

		$attachment_id = (int) get_user_meta( $user_id, 'wp_user_avatar', true );
		if ( $attachment_id <= 0 ) {
			$attachment_id = (int) get_user_meta( $user_id, 'yazar_resim', true );
		}
		if ( $attachment_id <= 0 ) {
			$attachment_id = (int) get_user_meta( $user_id, 'yazar_photo', true );
		}

		$image_attrs = [
			'alt'      => $alt,
			'loading'  => 'lazy',
			'decoding' => 'async',
		];
		if ( '' !== $class ) {
			$image_attrs['class'] = $class;
		}

		$avatar = '';
		if ( $attachment_id > 0 ) {
			$avatar = wp_get_attachment_image(
				$attachment_id,
				'' !== $image_size ? $image_size : 'thumbnail',
				false,
				$image_attrs
			);
		}
		if ( '' === $avatar ) {
			$avatar_args = [
				'loading'  => 'lazy',
				'decoding' => 'async',
			];
			if ( '' !== $class ) {
				$avatar_args['class'] = $class;
			}
			$avatar = get_avatar( $user_id, $size, '', $alt, $avatar_args );
		}
		return $avatar;
	}
}

if ( ! function_exists( 'tr724_yazarlar_social' ) ) {
	/**
	 * Facebook, X, and YouTube links that the author actually has.
	 *
	 * @param string $class Wrapper class. Defaults to the yazarlar grid.
	 */
	function tr724_yazarlar_social( int $user_id, string $class = 'writer__social' ): string {
		$icons = [
			'facebook' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 8.2H17V5h-2.5C11.9 5 10 6.9 10 9.5V11H8v3h2v7h3v-7h2.4l.6-3H13V9.6c0-.8.6-1.4 1.5-1.4z"/></svg>',
			'x'        => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.8 4h2.4l-5.3 6L20.5 20h-4.5l-3.5-4.6L8.4 20H6l5.6-6.4L4.2 4h4.6l3.2 4.2L16.8 4zm-.8 14.4h1.3L8.1 5.5H6.7l9.3 12.9z"/></svg>',
			'youtube'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.6 8.2a2.2 2.2 0 0 0-1.5-1.6C17.5 6.2 12 6.2 12 6.2s-5.5 0-7.1.4a2.2 2.2 0 0 0-1.5 1.6A23 23 0 0 0 3 12a23 23 0 0 0 .4 3.8 2.2 2.2 0 0 0 1.5 1.6c1.6.4 7.1.4 7.1.4s5.5 0 7.1-.4a2.2 2.2 0 0 0 1.5-1.6A23 23 0 0 0 21 12a23 23 0 0 0-.4-3.8zM10.3 15V9l5.2 3-5.2 3z"/></svg>',
		];
		$labels = [
			'facebook' => __( 'Facebook', 'tr724-news' ),
			'x'        => __( 'X', 'tr724-news' ),
			'youtube'  => __( 'YouTube', 'tr724-news' ),
		];
		$keys = [
			'facebook' => 'facebook',
			'x'        => 'twitter',
			'youtube'  => 'youtube',
		];

		$links = '';
		foreach ( $keys as $modifier => $meta_key ) {
			$url = tr724_yazarlar_href( (string) get_user_meta( $user_id, $meta_key, true ) );
			if ( '' === $url ) {
				continue;
			}
			$links .= sprintf(
				'<a class="writer__social-link writer__social-link--%1$s" href="%2$s" aria-label="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a>',
				esc_attr( $modifier ),
				esc_url( $url ),
				esc_attr( $labels[ $modifier ] ),
				$icons[ $modifier ]
			);
		}
		if ( '' === $links ) {
			return '';
		}
		return '<div class="' . esc_attr( $class ) . '">' . $links . '</div>';
	}
}

if ( ! function_exists( 'tr724_yazarlar_author_for_term' ) ) {
	/**
	 * User whose nicename matches a direct child of the yazarlar category.
	 */
	function tr724_yazarlar_author_for_term( WP_Term $term ): ?WP_User {
		if ( 'category' !== $term->taxonomy || (int) $term->parent <= 0 ) {
			return null;
		}

		$parent = get_term( (int) $term->parent, 'category' );
		if ( ! $parent instanceof WP_Term || 'yazarlar' !== $parent->slug ) {
			return null;
		}

		$user = get_user_by( 'slug', $term->slug );
		return $user instanceof WP_User ? $user : null;
	}
}

add_action(
	'template_redirect',
	static function (): void {
		if ( ! is_category() ) {
			return;
		}

		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$user = tr724_yazarlar_author_for_term( $term );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$url = get_author_posts_url( (int) $user->ID );
		if ( is_feed() ) {
			$feed = get_query_var( 'feed' );
			if ( ! is_string( $feed ) || '' === $feed || 'feed' === $feed ) {
				$feed = '';
			}
			$feed_url = get_author_feed_link( (int) $user->ID, $feed );
			if ( is_string( $feed_url ) && '' !== $feed_url ) {
				$url = $feed_url;
			}
		} else {
			$paged = (int) get_query_var( 'paged' );
			if ( $paged > 1 ) {
				$url = trailingslashit( $url ) . user_trailingslashit( 'page/' . $paged, 'paged' );
			}
		}

		wp_safe_redirect( $url, 301 );
		exit;
	}
);

add_filter(
	'term_link',
	static function ( string $termlink, WP_Term $term, string $taxonomy ): string {
		if ( 'category' !== $taxonomy ) {
			return $termlink;
		}

		$user = tr724_yazarlar_author_for_term( $term );
		if ( ! $user instanceof WP_User ) {
			return $termlink;
		}

		return get_author_posts_url( (int) $user->ID );
	},
	10,
	3
);
