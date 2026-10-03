<?php
/**
 * Author grid from wp/html-them/yazarlar.html (.yazarlar).
 * One card per user in the selected role. Defaults to Author.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
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
	 */
	function tr724_yazarlar_avatar( int $user_id ): string {
		$attachment_id = (int) get_user_meta( $user_id, 'wp_user_avatar', true );
		if ( $attachment_id <= 0 ) {
			$attachment_id = (int) get_user_meta( $user_id, 'yazar_resim', true );
		}
		if ( $attachment_id <= 0 ) {
			$attachment_id = (int) get_user_meta( $user_id, 'yazar_photo', true );
		}

		$avatar = '';
		if ( $attachment_id > 0 ) {
			$avatar = wp_get_attachment_image(
				$attachment_id,
				'thumbnail',
				false,
				[
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
				]
			);
		}
		if ( '' === $avatar ) {
			$avatar = get_avatar(
				$user_id,
				96,
				'',
				'',
				[
					'loading'  => 'lazy',
					'decoding' => 'async',
				]
			);
		}
		return $avatar;
	}
}

if ( ! function_exists( 'tr724_yazarlar_social' ) ) {
	/**
	 * Facebook, X, and YouTube links that the author actually has.
	 */
	function tr724_yazarlar_social( int $user_id ): string {
		$icons = [
			'facebook' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 8.2H17V5h-2.5C11.9 5 10 6.9 10 9.5V11H8v3h2v7h3v-7h2.4l.6-3H13V9.6c0-.8.6-1.4 1.5-1.4z"/></svg>',
			'x'        => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.3 7.4c.5-.3.8-.8.9-1.3-.4.3-.9.5-1.5.6A2.3 2.3 0 0 0 15.4 8c-1.3 0-2.3 1-2.3 2.3 0 .2 0 .4.1.5-1.9-.1-3.6-1-4.7-2.4-.2.3-.3.7-.3 1.1 0 .8.4 1.5 1 1.9-.4 0-.7-.1-1-.3v.1c0 1.1.8 2 1.8 2.2-.2.1-.4.1-.7.1-.1 0-.3 0-.4-.1.3.9 1.1 1.5 2 1.6A4.6 4.6 0 0 1 7 16.2a6.5 6.5 0 0 0 3.5 1c4.2 0 6.5-3.5 6.5-6.5v-.3c.5-.3.8-.7 1.1-1.2-.4.2-.9.3-1.3.4z"/></svg>',
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
		return '<div class="writer__social">' . $links . '</div>';
	}
}

$section = isset( $attributes['sectionTitle'] ) ? trim( (string) $attributes['sectionTitle'] ) : '';
if ( '' === $section ) {
	$section = __( 'TÜM YAZARLARI', 'tr724-news' );
}

$columns = isset( $attributes['columns'] ) ? (int) $attributes['columns'] : 4;
$columns = max( 1, min( 6, $columns ) );

$role = isset( $attributes['role'] ) ? sanitize_key( (string) $attributes['role'] ) : 'author';
if ( '' === $role || ! get_role( $role ) ) {
	$role = 'author';
}

$authors = get_users(
	[
		'role'    => $role,
		'orderby' => 'display_name',
		'order'   => 'ASC',
	]
);

$title_id = wp_unique_id( 'yazarlar-title-' );

echo '<div ' . get_block_wrapper_attributes(
	[
		'class'           => 'yazarlar',
		'aria-labelledby' => $title_id,
		'style'           => '--yazarlar-columns:' . $columns,
	]
) . '>';
echo '<h2 class="section-title writers__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $section ) . '</h2>';

if ( ! $authors ) {
	echo '<p class="writers__empty">' . esc_html__( 'Yazar bulunamadı.', 'tr724-news' ) . '</p>';
	echo '</div>';
	return;
}

echo '<div class="writers__grid">';
foreach ( $authors as $author ) {
	$user_id = (int) $author->ID;
	$name    = $author->display_name;
	if ( '' === $name ) {
		$name = $author->user_login;
	}
	$url   = get_author_posts_url( $user_id );
	$email = $author->user_email;

	echo '<article class="writer">';
	echo '<a class="writer__avatar" href="' . esc_url( $url ) . '">' . tr724_yazarlar_avatar( $user_id ) . '</a>';
	echo '<div>';
	echo '<a class="writer__name" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
	if ( '' !== $email ) {
		echo '<a class="writer__mail" href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
	}
	echo tr724_yazarlar_social( $user_id );
	echo '</div></article>';
}
echo '</div></div>';
