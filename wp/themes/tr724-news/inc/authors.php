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

if ( ! function_exists( 'tr724_author_photo_meta_key' ) ) {
	/**
	 * User meta that stores the profile photo attachment ID.
	 */
	function tr724_author_photo_meta_key(): string {
		return 'tr724_author_photo';
	}
}

if ( ! function_exists( 'tr724_author_photo_id' ) ) {
	/**
	 * Profile photo, then the older avatar fields. Zero when none resolve to a file.
	 */
	function tr724_author_photo_id( int $user_id ): int {
		$keys = [
			tr724_author_photo_meta_key(),
			'wp_user_avatar',
			'yazar_resim',
			'yazar_photo',
		];
		foreach ( $keys as $meta_key ) {
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
}

if ( ! function_exists( 'tr724_yazarlar_avatar' ) ) {
	/**
	 * Author photo from the profile field, then older avatar fields, then the WordPress avatar.
	 *
	 * Custom photos use the original file only. No srcset or intermediate sizes.
	 *
	 * @param array $args {
	 *     @type string $class Extra class on the image.
	 *     @type string $alt   Alt text.
	 *     @type int    $size  Pixel size for the WordPress avatar fallback.
	 * }
	 */
	function tr724_yazarlar_avatar( int $user_id, array $args = [] ): string {
		$class = isset( $args['class'] ) ? (string) $args['class'] : '';
		$alt   = isset( $args['alt'] ) ? (string) $args['alt'] : '';
		$size  = isset( $args['size'] ) ? max( 1, (int) $args['size'] ) : 96;

		$attachment_id = tr724_author_photo_id( $user_id );

		if ( $attachment_id > 0 ) {
			$url = wp_get_original_image_url( $attachment_id );
			if ( ! is_string( $url ) || '' === $url ) {
				$url = (string) wp_get_attachment_url( $attachment_id );
			}
			if ( '' !== $url ) {
				$class_attr = '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '';
				return sprintf(
					'<img src="%1$s" alt="%2$s"%3$s loading="lazy" decoding="async" />',
					esc_url( $url ),
					esc_attr( $alt ),
					$class_attr
				);
			}
		}

		$avatar_args = [
			'loading'  => 'lazy',
			'decoding' => 'async',
		];
		if ( '' !== $class ) {
			$avatar_args['class'] = $class;
		}
		$avatar = get_avatar( $user_id, $size, '', $alt, $avatar_args );
		$avatar = preg_replace( '/\s(?:srcset|sizes)=([\'"]).*?\1/s', '', $avatar );
		return is_string( $avatar ) ? $avatar : '';
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

add_action(
	'init',
	static function (): void {
		register_meta(
			'user',
			tr724_author_photo_meta_key(),
			[
				'type'              => 'integer',
				'single'            => true,
				'default'           => 0,
				'description'       => 'Profile photo attachment ID.',
				'sanitize_callback' => static function ( $value ): int {
					$attachment_id = absint( $value );
					if ( $attachment_id <= 0 || ! wp_attachment_is_image( $attachment_id ) ) {
						return 0;
					}
					return $attachment_id;
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $user_id ): bool {
					unset( $allowed, $meta_key );
					return current_user_can( 'edit_user', (int) $user_id );
				},
				'show_in_rest'      => [
					'schema' => [
						'type'    => 'integer',
						'context' => [ 'edit' ],
					],
				],
			]
		);
	}
);

add_action(
	'admin_enqueue_scripts',
	static function ( string $hook ): void {
		if ( ! in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) ) {
			return;
		}

		wp_enqueue_media();

		$path = get_template_directory() . '/assets/js/author-photo.js';
		wp_enqueue_script(
			'tr724-author-photo',
			get_template_directory_uri() . '/assets/js/author-photo.js',
			[ 'media-editor' ],
			is_readable( $path ) ? (string) filemtime( $path ) : null,
			true
		);
	}
);

/**
 * Profile photo picker on the user edit screen.
 */
function tr724_author_photo_field( WP_User $user ): void {
	if ( ! current_user_can( 'edit_user', $user->ID ) ) {
		return;
	}

	$attachment_id = (int) get_user_meta( $user->ID, tr724_author_photo_meta_key(), true );
	$preview       = $attachment_id > 0 ? (string) wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : '';
	if ( '' === $preview && $attachment_id > 0 ) {
		$preview = (string) wp_get_attachment_url( $attachment_id );
	}
	?>
	<h2><?php esc_html_e( 'Yazar fotoğrafı', 'tr724-news' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr class="tr724-author-photo">
			<th scope="row"><?php esc_html_e( 'Fotoğraf', 'tr724-news' ); ?></th>
			<td>
				<?php wp_nonce_field( 'tr724_author_photo_' . $user->ID, 'tr724_author_photo_nonce' ); ?>
				<input type="hidden" class="tr724-author-photo-id" name="tr724_author_photo" value="<?php echo esc_attr( (string) $attachment_id ); ?>">
				<div class="tr724-author-photo-preview">
					<?php if ( '' !== $preview ) : ?>
						<img src="<?php echo esc_url( $preview ); ?>" alt="" style="display:block;max-width:96px;height:auto">
					<?php endif; ?>
				</div>
				<p>
					<button type="button" class="button tr724-author-photo-select" data-select="<?php esc_attr_e( 'Fotoğraf seç', 'tr724-news' ); ?>" data-replace="<?php esc_attr_e( 'Fotoğrafı değiştir', 'tr724-news' ); ?>">
						<?php echo esc_html( $attachment_id > 0 ? __( 'Fotoğrafı değiştir', 'tr724-news' ) : __( 'Fotoğraf seç', 'tr724-news' ) ); ?>
					</button>
					<button type="button" class="button tr724-author-photo-remove" <?php echo $attachment_id > 0 ? '' : 'hidden'; ?>>
						<?php esc_html_e( 'Kaldır', 'tr724-news' ); ?>
					</button>
				</p>
				<p class="description"><?php esc_html_e( 'Yazar listesinde ve haber detayında kullanılan fotoğraf.', 'tr724-news' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}

add_action( 'show_user_profile', 'tr724_author_photo_field' );
add_action( 'edit_user_profile', 'tr724_author_photo_field' );

/**
 * Save the profile photo. A missing nonce leaves the stored photo alone.
 */
function tr724_save_author_photo( int $user_id ): void {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	$nonce = isset( $_POST['tr724_author_photo_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tr724_author_photo_nonce'] ) ) : '';
	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'tr724_author_photo_' . $user_id ) ) {
		return;
	}

	$attachment_id = isset( $_POST['tr724_author_photo'] ) ? absint( wp_unslash( $_POST['tr724_author_photo'] ) ) : 0;
	if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
		update_user_meta( $user_id, tr724_author_photo_meta_key(), $attachment_id );
		return;
	}
	delete_user_meta( $user_id, tr724_author_photo_meta_key() );
}

add_action( 'personal_options_update', 'tr724_save_author_photo' );
add_action( 'edit_user_profile_update', 'tr724_save_author_photo' );
