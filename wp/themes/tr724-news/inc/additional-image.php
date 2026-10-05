<?php
/**
 * Extra photo, title, and hide-title flag for a news post.
 * Spotlight uses them on the slide. The article page still uses the featured image and the post title.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_sanitize_additional_image' ) ) {
	/**
	 * Keep an image attachment ID, or 0 when the value is empty or not an image.
	 */
	function tr724_sanitize_additional_image( mixed $value ): int {
		$id = absint( $value );
		if ( $id <= 0 ) {
			return 0;
		}
		if ( 'attachment' !== get_post_type( $id ) || ! wp_attachment_is_image( $id ) ) {
			return 0;
		}
		return $id;
	}
}

if ( ! function_exists( 'tr724_sanitize_hide_title' ) ) {
	/**
	 * Store a real boolean. The string "false" and "0" stay off.
	 */
	function tr724_sanitize_hide_title( mixed $value ): bool {
		if ( true === $value || 1 === $value || '1' === $value ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ 'true', 'yes', 'on' ], true );
		}
		return false;
	}
}

if ( ! function_exists( 'tr724_additional_meta_auth' ) ) {
	/**
	 * Only someone who can edit the post may change the extra Spotlight fields.
	 */
	function tr724_additional_meta_auth( bool $allowed, string $meta_key, int $post_id ): bool {
		$keys = [ 'tr724_additional_image', 'tr724_additional_title', 'tr724_upper_title', 'tr724_hide_title', 'tr724_youtube_url' ];
		if ( ! in_array( $meta_key, $keys, true ) ) {
			return $allowed;
		}
		return current_user_can( 'edit_post', $post_id );
	}
}

if ( ! function_exists( 'tr724_additional_image_id' ) ) {
	/**
	 * Attachment ID for the extra post photo, or 0 when none is set.
	 */
	function tr724_additional_image_id( int $post_id = 0 ): int {
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}
		if ( $post_id <= 0 ) {
			return 0;
		}
		return (int) get_post_meta( $post_id, 'tr724_additional_image', true );
	}
}

if ( ! function_exists( 'tr724_additional_title' ) ) {
	/**
	 * Ana Başlık for the Spotlight slide, or an empty string when the post title should be used.
	 */
	function tr724_additional_title( int $post_id = 0 ): string {
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}
		if ( $post_id <= 0 ) {
			return '';
		}
		return trim( (string) get_post_meta( $post_id, 'tr724_additional_title', true ) );
	}
}

if ( ! function_exists( 'tr724_upper_title' ) ) {
	/**
	 * Üst Başlık for the Spotlight slide, or an empty string when none is set.
	 */
	function tr724_upper_title( int $post_id = 0 ): string {
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}
		if ( $post_id <= 0 ) {
			return '';
		}
		return trim( (string) get_post_meta( $post_id, 'tr724_upper_title', true ) );
	}
}

if ( ! function_exists( 'tr724_youtube_id' ) ) {
	/**
	 * 11-character YouTube id from a watch, share, embed, shorts, or live URL.
	 */
	function tr724_youtube_id( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$host = (string) preg_replace( '/^www\./', '', $host );
		$path = (string) ( $parts['path'] ?? '' );
		$id   = '';

		if ( 'youtu.be' === $host ) {
			$id = trim( $path, '/' );
		} elseif ( in_array( $host, [ 'youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com' ], true ) ) {
			if ( preg_match( '#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $matches ) ) {
				$id = $matches[1];
			} else {
				$query = [];
				parse_str( (string) ( $parts['query'] ?? '' ), $query );
				$id = isset( $query['v'] ) ? (string) $query['v'] : '';
			}
		}

		$id = rawurldecode( $id );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{11}$/', $id ) ) {
			return '';
		}
		return $id;
	}
}

if ( ! function_exists( 'tr724_sanitize_youtube_url' ) ) {
	/**
	 * Keep a YouTube link, or an empty string when it is not a video URL.
	 */
	function tr724_sanitize_youtube_url( mixed $value ): string {
		$url = trim( (string) $value );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		$url = esc_url_raw( $url );
		if ( '' === $url || '' === tr724_youtube_id( $url ) ) {
			return '';
		}
		return $url;
	}
}

if ( ! function_exists( 'tr724_youtube_url' ) ) {
	/**
	 * YouTube link for the article hero, or an empty string when the featured image should show.
	 */
	function tr724_youtube_url( int $post_id = 0 ): string {
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}
		if ( $post_id <= 0 ) {
			return '';
		}
		return tr724_sanitize_youtube_url( get_post_meta( $post_id, 'tr724_youtube_url', true ) );
	}
}

if ( ! function_exists( 'tr724_hide_title' ) ) {
	/**
	 * Whether the Spotlight slide should omit its title.
	 */
	function tr724_hide_title( int $post_id = 0 ): bool {
		if ( $post_id <= 0 ) {
			$post_id = (int) get_the_ID();
		}
		if ( $post_id <= 0 ) {
			return false;
		}
		return tr724_sanitize_hide_title( get_post_meta( $post_id, 'tr724_hide_title', true ) );
	}
}

add_action(
	'init',
	static function (): void {
		register_post_meta(
			'post',
			'tr724_additional_image',
			[
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'show_in_rest'      => true,
				'sanitize_callback' => 'tr724_sanitize_additional_image',
				'auth_callback'     => 'tr724_additional_meta_auth',
			]
		);
		register_post_meta(
			'post',
			'tr724_additional_title',
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => 'tr724_additional_meta_auth',
			]
		);
		register_post_meta(
			'post',
			'tr724_upper_title',
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => 'tr724_additional_meta_auth',
			]
		);
		register_post_meta(
			'post',
			'tr724_youtube_url',
			[
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => 'tr724_sanitize_youtube_url',
				'auth_callback'     => 'tr724_additional_meta_auth',
			]
		);
		register_post_meta(
			'post',
			'tr724_hide_title',
			[
				'type'              => 'boolean',
				'single'            => true,
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => 'tr724_sanitize_hide_title',
				'auth_callback'     => 'tr724_additional_meta_auth',
			]
		);
	}
);

add_action(
	'enqueue_block_editor_assets',
	static function (): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || 'post' !== $screen->post_type ) {
			return;
		}

		$relative = '/assets/js/additional-image.js';
		$path     = get_template_directory() . $relative;
		if ( ! is_readable( $path ) ) {
			return;
		}

		wp_enqueue_script(
			'tr724-additional-image',
			get_template_directory_uri() . $relative,
			[
				'wp-plugins',
				'wp-edit-post',
				'wp-element',
				'wp-components',
				'wp-data',
				'wp-block-editor',
				'wp-i18n',
			],
			(string) filemtime( $path ),
			true
		);
	}
);
