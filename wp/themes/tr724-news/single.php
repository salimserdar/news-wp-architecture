<?php
/**
 * Single post. Article column from wp/html-them/haber.html.
 * The sidebar (.post-layout__aside) is the Single post widget area.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_single_upper' ) ) {
	/**
	 * Turkish-aware uppercase for the author name.
	 */
	function tr724_single_upper( string $text ): string {
		$text = strtr(
			$text,
			[
				'i' => 'İ',
				'ı' => 'I',
			]
		);
		return mb_strtoupper( $text, 'UTF-8' );
	}
}

if ( ! function_exists( 'tr724_single_find_gallery' ) ) {
	/**
	 * First gallery block, including one nested in another block.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, mixed>|null
	 */
	function tr724_single_find_gallery( array $blocks ): ?array {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === 'core/gallery' ) {
				return $block;
			}
			$inner = $block['innerBlocks'] ?? [];
			if ( $inner ) {
				$found = tr724_single_find_gallery( $inner );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}
}

if ( ! function_exists( 'tr724_single_image_data' ) ) {
	/**
	 * Lightbox and thumbnail URLs for an attachment.
	 *
	 * @return array{src: string, thumb: string, alt: string}|null
	 */
	function tr724_single_image_data( int $id, string $alt = '' ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		$src = wp_get_attachment_image_url( $id, 'large' );
		if ( ! $src ) {
			$src = wp_get_attachment_url( $id );
		}
		if ( ! $src ) {
			return null;
		}
		$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
		if ( ! $thumb ) {
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
}

if ( ! function_exists( 'tr724_single_gallery_images' ) ) {
	/**
	 * Images from a gallery block, in editor order.
	 *
	 * @param array<string, mixed> $block Gallery block.
	 * @return array<int, array{src: string, thumb: string, alt: string}>
	 */
	function tr724_single_gallery_images( array $block ): array {
		$images     = [];
		$inner      = $block['innerBlocks'] ?? [];
		$used_inner = false;

		foreach ( $inner as $image_block ) {
			if ( ( $image_block['blockName'] ?? '' ) !== 'core/image' ) {
				continue;
			}
			$used_inner = true;
			$attrs      = $image_block['attrs'] ?? [];
			$id         = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
			$alt        = isset( $attrs['alt'] ) ? (string) $attrs['alt'] : '';
			$item       = tr724_single_image_data( $id, $alt );
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
			$item = tr724_single_image_data( (int) $id );
			if ( null !== $item ) {
				$images[] = $item;
			}
		}
		return $images;
	}
}

if ( ! function_exists( 'tr724_single_author_avatar' ) ) {
	function tr724_single_author_avatar( int $author_id ): string {
		$attachment_id = (int) get_user_meta( $author_id, 'wp_user_avatar', true );
		if ( $attachment_id <= 0 ) {
			$attachment_id = (int) get_user_meta( $author_id, 'yazar_photo', true );
		}

		$avatar = '';
		if ( $attachment_id > 0 ) {
			$avatar = wp_get_attachment_image(
				$attachment_id,
				'thumbnail',
				false,
				[
					'class'    => 'post__avatar',
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
				]
			);
		}
		if ( '' === $avatar ) {
			$avatar = get_avatar(
				$author_id,
				96,
				'',
				'',
				[
					'class'    => 'post__avatar',
					'loading'  => 'lazy',
					'decoding' => 'async',
				]
			);
		}
		return $avatar;
	}
}

if ( ! function_exists( 'tr724_single_is_columnist' ) ) {
	/**
	 * Columnist posts are written by the Author or Archive Author role.
	 */
	function tr724_single_is_columnist( int $author_id ): bool {
		$user = get_userdata( $author_id );
		if ( ! $user instanceof WP_User ) {
			return false;
		}
		return (bool) array_intersect( (array) $user->roles, [ 'author', 'archive_author' ] );
	}
}

if ( ! function_exists( 'tr724_single_author_date' ) ) {
	/**
	 * Archive-style date: "03 EKİM 2026".
	 */
	function tr724_single_author_date( int $post_id ): string {
		$published = (int) get_post_timestamp( $post_id );
		if ( ! $published ) {
			return '';
		}
		$months = [
			1  => 'OCAK',
			2  => 'ŞUBAT',
			3  => 'MART',
			4  => 'NİSAN',
			5  => 'MAYIS',
			6  => 'HAZİRAN',
			7  => 'TEMMUZ',
			8  => 'AĞUSTOS',
			9  => 'EYLÜL',
			10 => 'EKİM',
			11 => 'KASIM',
			12 => 'ARALIK',
		];
		$month_num = (int) wp_date( 'n', $published );
		return wp_date( 'd', $published ) . ' ' . ( $months[ $month_num ] ?? '' ) . ' ' . wp_date( 'Y', $published );
	}
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php block_template_part( 'header' ); ?>
<main class="container post-page">
<?php
if ( have_posts() ) {
	while ( have_posts() ) {
		the_post();
		$post_id = (int) get_the_ID();

		if ( 'post' !== get_post_type( $post_id ) ) {
			?>
			<article <?php post_class(); ?>>
				<?php
				the_title( '<h1>', '</h1>' );
				the_content();
				?>
			</article>
			<?php
			continue;
		}

		$published = (int) get_post_timestamp( $post_id );
		$modified  = (int) get_post_timestamp( $post_id, 'modified' );
		$permalink = get_permalink( $post_id );
		$title     = get_the_title( $post_id );

		$gallery_block = tr724_single_find_gallery( parse_blocks( (string) get_post_field( 'post_content', $post_id ) ) );
		$gallery       = null !== $gallery_block ? tr724_single_gallery_images( $gallery_block ) : [];

		$author_id = (int) get_post_field( 'post_author', $post_id );
		$author    = get_the_author_meta( 'display_name', $author_id );
		if ( '' === $author ) {
			$author = get_the_author_meta( 'user_login', $author_id );
		}

		$share_url = rawurlencode( (string) $permalink );
		$share_text = rawurlencode( $title . ' ' . $permalink );

		$crumbs = [
			[
				'url'   => home_url( '/' ),
				'label' => __( 'Ana Sayfa', 'tr724-news' ),
			],
		];
		$categories = get_the_category( $post_id );
		if ( $categories ) {
			$category     = $categories[0];
			$ancestor_ids = array_reverse( get_ancestors( (int) $category->term_id, 'category' ) );
			foreach ( $ancestor_ids as $ancestor_id ) {
				$ancestor = get_term( (int) $ancestor_id, 'category' );
				if ( ! $ancestor || is_wp_error( $ancestor ) ) {
					continue;
				}
				$ancestor_link = get_term_link( $ancestor );
				if ( is_wp_error( $ancestor_link ) ) {
					continue;
				}
				$crumbs[] = [
					'url'   => $ancestor_link,
					'label' => $ancestor->name,
				];
			}
			$category_link = get_term_link( $category );
			if ( ! is_wp_error( $category_link ) ) {
				$crumbs[] = [
					'url'   => $category_link,
					'label' => $category->name,
				];
			}
		}
		?>
		<article <?php post_class(); ?>>
			<div class="post-layout">
				<header class="post-layout__intro">
					<nav class="post__breadcrumb" aria-label="<?php esc_attr_e( 'Sayfa yolu', 'tr724-news' ); ?>">
						<?php
						foreach ( $crumbs as $index => $crumb ) {
							if ( $index > 0 ) {
								echo '<span class="post__breadcrumb-sep" aria-hidden="true">›</span>';
							}
							printf(
								'<a href="%1$s">%2$s</a>',
								esc_url( $crumb['url'] ),
								esc_html( $crumb['label'] )
							);
						}
						?>
					</nav>
					<h1 class="post__title"><?php echo esc_html( $title ); ?></h1>
					<?php if ( has_excerpt( $post_id ) ) : ?>
						<p class="post__lead"><?php echo esc_html( get_the_excerpt( $post_id ) ); ?></p>
					<?php endif; ?>
					<?php if ( $published ) : ?>
						<p class="post__meta">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: publication date and time. */
									__( 'Haber Giriş: %s', 'tr724-news' ),
									wp_date( 'F j, Y, g:i:sA', $published )
								)
							);
							if ( $modified > $published ) {
								echo ' &nbsp;|&nbsp; ';
								echo esc_html(
									sprintf(
										/* translators: %s: last modified date and time. */
										__( 'Son Güncelleme: %s', 'tr724-news' ),
										wp_date( 'F j, Y, g:i:sA', $modified )
									)
								);
							}
							?>
						</p>
					<?php endif; ?>
					<div class="post__author">
						<?php echo tr724_single_author_avatar( $author_id ); ?>
						<span class="post__author-copy">
							<a class="post__author-name" href="<?php echo esc_url( get_author_posts_url( $author_id ) ); ?>"><?php echo esc_html( tr724_single_upper( $author ) ); ?></a>
							<?php if ( $published ) : ?>
								<span class="post__author-date"><?php echo esc_html( wp_date( "j F 'y", $published ) ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<div class="post__share">
						<span class="post__share-label">
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7a2.5 2.5 0 0 0 0-.7l7.05-4.11c.54.5 1.25.81 2.04.81a3 3 0 1 0-3-3c0 .24.04.47.09.7L8.04 9.81A2.99 2.99 0 0 0 6 9a3 3 0 1 0 0 6c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65a2.92 2.92 0 1 0 2.92-2.92z" /></svg>
							<?php esc_html_e( 'PAYLAŞ', 'tr724-news' ); ?>
						</span>
						<a class="post__share-link post__share-link--facebook" href="<?php echo esc_url( 'https://www.facebook.com/sharer/sharer.php?u=' . $share_url ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( "Facebook'ta paylaş", 'tr724-news' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 8.2H17V5h-2.5C11.9 5 10 6.9 10 9.5V11H8v3h2v7h3v-7h2.4l.6-3H13V9.6c0-.8.6-1.4 1.5-1.4z" /></svg>
						</a>
						<a class="post__share-link post__share-link--x" href="<?php echo esc_url( 'https://twitter.com/intent/tweet?url=' . $share_url . '&text=' . rawurlencode( $title ) ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( "X'te paylaş", 'tr724-news' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.8 4h2.4l-5.3 6L20.5 20h-4.5l-3.5-4.6L8.4 20H6l5.6-6.4L4.2 4h4.6l3.2 4.2L16.8 4zm-.8 14.4h1.3L8.1 5.5H6.7l9.3 12.9z" /></svg>
						</a>
						<a class="post__share-link post__share-link--whatsapp" href="<?php echo esc_url( 'https://wa.me/?text=' . $share_text ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( "WhatsApp'ta paylaş", 'tr724-news' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2C6.58 2 2.15 6.4 2.15 11.83c0 1.74.46 3.44 1.34 4.94L2 22l5.39-1.41a10 10 0 0 0 4.65 1.18h.01c5.46 0 9.89-4.4 9.89-9.83C21.94 6.4 17.5 2 12.04 2zm5.76 13.9c-.24.68-1.4 1.3-1.94 1.38-.5.08-1.12.11-1.81-.11-.41-.14-.95-.31-1.63-.61-2.87-1.24-4.74-4.13-4.88-4.32-.14-.19-1.16-1.54-1.16-2.94s.73-2.08 1-2.37c.24-.28.64-.41 1.02-.41.12 0 .23 0 .33.01.3.01.44.03.64.49.24.58.82 2 .89 2.15.07.14.12.31.02.5-.1.19-.14.31-.28.48-.14.16-.3.37-.42.49-.14.14-.29.29-.12.56.16.28.73 1.2 1.57 1.95 1.08.96 1.99 1.26 2.27 1.4.28.14.44.12.6-.07.17-.19.69-.8.88-1.08.19-.28.37-.23.63-.14.26.09 1.64.77 1.92.91.28.14.47.21.54.33.07.12.07.68-.17 1.36z" /></svg>
						</a>
						<a class="post__share-link post__share-link--email" href="<?php echo esc_url( 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . $share_text ); ?>" aria-label="<?php esc_attr_e( 'E-posta ile paylaş', 'tr724-news' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5L4 8V6l8 5 8-5v2z" /></svg>
						</a>
						<button class="post__share-link post__share-link--print" type="button" data-post-print aria-label="<?php esc_attr_e( 'Yazdır', 'tr724-news' ); ?>">
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z" /></svg>
						</button>
					</div>
					<div class="post__follow">
						<a class="post__follow-link post__follow-link--news" href="https://www.google.com/preferences/source?q=tr724.com" target="_blank" rel="noopener noreferrer">
							<img src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/google-news.svg' ); ?>" alt="" width="24" height="24" />
							<span><?php esc_html_e( 'Gerçekler bizimle anlaşılır. Tıklayın güvenilir haber kaynağınıza ekleyin!', 'tr724-news' ); ?></span>
						</a>
						<a class="post__follow-link post__follow-link--whatsapp" href="https://whatsapp.com/channel/0029Va8otpD4tRruE0WH4G0T" target="_blank" rel="noopener noreferrer">
							<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#fff" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" /></svg>
							<span><?php esc_html_e( 'TR724 WhatsApp kanalına katılın, haberlere herkesten önce siz ulaşın.', 'tr724-news' ); ?></span>
						</a>
					</div>
				</header>

				<div class="post-layout__main">
					<?php
					$youtube_id = tr724_youtube_id( tr724_youtube_url( $post_id ) );
					if ( '' !== $youtube_id ) {
						$embed = 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $youtube_id );
						echo '<figure class="post__hero post__hero--video">';
						printf(
							'<iframe src="%1$s" title="%2$s" loading="eager" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>',
							esc_url( $embed ),
							esc_attr( $title )
						);
						echo '</figure>';
					}

					$thumb_id = (int) get_post_thumbnail_id( $post_id );
					if ( '' === $youtube_id && $thumb_id ) {
						$hero_alt = (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
						if ( '' === $hero_alt ) {
							$hero_alt = $title;
						}
						$hero = wp_get_attachment_image(
							$thumb_id,
							'large',
							false,
							[
								'alt'      => $hero_alt,
								'loading'  => 'eager',
								'decoding' => 'async',
							]
						);
						$caption = wp_get_attachment_caption( $thumb_id );
						if ( $hero ) {
							echo '<figure class="post__hero">';
							echo $hero;
							if ( is_string( $caption ) && '' !== $caption ) {
								echo '<figcaption>' . esc_html( $caption ) . '</figcaption>';
							}
							echo '</figure>';
						}
					}

					if ( $gallery ) {
						$gallery_json = wp_json_encode(
							array_map(
								static function ( array $image ): array {
									return [
										'src' => $image['src'],
										'alt' => $image['alt'],
									];
								},
								$gallery
							),
							JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
						);
						$visible  = array_slice( $gallery, 0, 4 );
						$remaining = count( $gallery ) - count( $visible );
						echo '<div class="post__gallery">';
						echo '<script type="application/json" id="post-gallery-data">' . $gallery_json . '</script>';
						foreach ( $visible as $index => $image ) {
							$label = sprintf(
								/* translators: %d: image number in the gallery, starting at 1. */
								__( 'Galeri görseli %d', 'tr724-news' ),
								$index + 1
							);
							printf(
								'<button class="post__thumb" type="button" data-gallery="%1$d" style="background-image:url(%2$s)" aria-label="%3$s"></button>',
								$index,
								esc_url( $image['thumb'] ),
								esc_attr( $label )
							);
						}
						if ( $remaining > 0 ) {
							$more_label = sprintf(
								/* translators: %d: number of additional gallery images. */
								__( '%d görsel daha', 'tr724-news' ),
								$remaining
							);
							printf(
								'<button class="post__thumb post__thumb--more" type="button" data-gallery="4" aria-label="%1$s">+%2$d</button>',
								esc_attr( $more_label ),
								$remaining
							);
						}
						echo '<button class="post__gallery-open" type="button" data-gallery="0">' . esc_html__( 'GALERİYİ GÖRÜNTÜLE', 'tr724-news' ) . '</button>';
						echo '</div>';
					}
					?>

					<div class="post__text">
						<?php
						if ( null !== $gallery_block ) {
							$skip_gallery = true;
							$gallery_filter = static function ( string $html, array $block ) use ( &$skip_gallery ): string {
								if ( $skip_gallery && ( $block['blockName'] ?? '' ) === 'core/gallery' ) {
									$skip_gallery = false;
									return '';
								}
								return $html;
							};
							add_filter( 'render_block', $gallery_filter, 10, 2 );
							the_content();
							remove_filter( 'render_block', $gallery_filter, 10 );
						} else {
							the_content();
						}
						?>
					</div>
					<?php
					$tags = get_the_tags( $post_id );
					if ( $tags ) :
						?>
						<div class="post__tags">
							<span class="post__tags-label"><?php esc_html_e( 'Etiketler', 'tr724-news' ); ?></span>
							<ul class="post__tags-list">
								<?php
								foreach ( $tags as $tag ) {
									$tag_link = get_tag_link( $tag );
									if ( is_wp_error( $tag_link ) ) {
										continue;
									}
									printf(
										'<li><a href="%1$s">%2$s</a></li>',
										esc_url( $tag_link ),
										esc_html( $tag->name )
									);
								}
								?>
							</ul>
						</div>
					<?php endif; ?>
					<div class="post__patron">
						<div class="post__patron-copy">
							<p class="post__patron-kicker"><?php esc_html_e( 'TR724 Patreon', 'tr724-news' ); ?></p>
							<p class="post__patron-title"><?php esc_html_e( 'Bağımsız haberciliğe destek olun', 'tr724-news' ); ?></p>
							<p class="post__patron-text"><?php esc_html_e( 'TR724, okurlarının katkısıyla yayın yapıyor. Patron olarak bu haberi ve yenilerini mümkün kılın.', 'tr724-news' ); ?></p>
						</div>
						<a class="post__patron-cta" href="https://www.patreon.com/tr724" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Patron ol', 'tr724-news' ); ?>
							<span aria-hidden="true">→</span>
						</a>
					</div>
					<?php
					if ( tr724_author_is_newsroom( $author_id ) ) {
						echo render_block(
							[
								'blockName'    => 'tr724/headlines',
								'attrs'        => [
									'excludeId'   => $post_id,
									'categoryId'  => 13694,
									'postsToShow' => 15,
								],
								'innerBlocks'  => [],
								'innerHTML'    => '',
								'innerContent' => [],
							]
						);
					}
					if ( tr724_single_is_columnist( $author_id ) ) {
						$author_posts = new WP_Query(
							[
								'post_type'           => 'post',
								'post_status'         => 'publish',
								'author'              => $author_id,
								'post__not_in'        => [ $post_id ],
								'posts_per_page'      => 5,
								'ignore_sticky_posts' => true,
								'no_found_rows'       => true,
							]
						);
						if ( $author_posts->have_posts() ) {
							$author_posts_title_id = wp_unique_id( 'author-posts-title-' );
							$author_posts_heading  = tr724_single_upper(
								sprintf(
									/* translators: %s: author display name. */
									__( '%s yazıları', 'tr724-news' ),
									$author
								)
							);
							?>
							<section class="post__author-posts" aria-labelledby="<?php echo esc_attr( $author_posts_title_id ); ?>">
								<h2 class="post__author-posts-title" id="<?php echo esc_attr( $author_posts_title_id ); ?>">
									<a href="<?php echo esc_url( get_author_posts_url( $author_id ) ); ?>"><?php echo esc_html( $author_posts_heading ); ?></a>
								</h2>
								<?php
								while ( $author_posts->have_posts() ) {
									$author_posts->the_post();
									$recent_id = (int) get_the_ID();
									$date_text = tr724_single_author_date( $recent_id );
									?>
									<article class="post__author-post">
										<?php if ( '' !== $date_text ) : ?>
											<time datetime="<?php echo esc_attr( (string) get_post_time( DATE_W3C, true, $recent_id ) ); ?>"><?php echo esc_html( $date_text ); ?></time>
										<?php endif; ?>
										<h3 class="post__author-post-title">
											<a href="<?php echo esc_url( get_permalink( $recent_id ) ); ?>"><?php echo esc_html( get_the_title( $recent_id ) ); ?></a>
										</h3>
									</article>
									<?php
								}
								?>
								<a class="post__author-posts-all" href="<?php echo esc_url( get_author_posts_url( $author_id ) ); ?>">
									<?php esc_html_e( 'Tüm yazıları gör', 'tr724-news' ); ?>
									<span aria-hidden="true">→</span>
								</a>
							</section>
							<?php
						}
						wp_reset_postdata();
					}
					comments_template();
					?>
				</div>
				<aside class="post-layout__aside"><?php dynamic_sidebar( 'single-post' ); ?></aside>
			</div>
		</article>
		<?php
		if ( $gallery ) {
			?>
			<dialog class="lightbox" id="lightbox" aria-label="<?php esc_attr_e( 'Galeri', 'tr724-news' ); ?>">
				<button class="lightbox__close" type="button" data-lightbox-close aria-label="<?php esc_attr_e( 'Kapat', 'tr724-news' ); ?>">×</button>
				<button class="lightbox__nav lightbox__nav--prev" type="button" data-lightbox-step="-1" aria-label="<?php esc_attr_e( 'Önceki görsel', 'tr724-news' ); ?>">‹</button>
				<figure>
					<img class="lightbox__image" alt="" />
					<figcaption class="lightbox__caption"></figcaption>
				</figure>
				<button class="lightbox__nav lightbox__nav--next" type="button" data-lightbox-step="1" aria-label="<?php esc_attr_e( 'Sonraki görsel', 'tr724-news' ); ?>">›</button>
			</dialog>
			<?php
		}
	}
}
?>
</main>
<?php block_template_part( 'footer' ); ?>
<?php wp_footer(); ?>
</body>
</html>
