<?php
/**
 * Category archive. Story grid from wp/html-them/ekonomi.html.
 * The sidebar (.column--aside) is the Category widget area.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_category_archive_upper' ) ) {
	/**
	 * Turkish-aware uppercase for the category title.
	 */
	function tr724_category_archive_upper( string $text ): string {
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

if ( ! function_exists( 'tr724_category_archive_pages' ) ) {
	/**
	 * Page numbers to show, with gaps when the archive is long.
	 *
	 * @return array<int, int|string>
	 */
	function tr724_category_archive_pages( int $current, int $total ): array {
		if ( $total <= 7 ) {
			return range( 1, $total );
		}

		$pages = [ 1 ];
		$start = max( 2, $current - 1 );
		$end   = min( $total - 1, $current + 1 );

		if ( $start > 2 ) {
			$pages[] = 'gap';
		}
		for ( $page = $start; $page <= $end; $page++ ) {
			$pages[] = $page;
		}
		if ( $end < $total - 1 ) {
			$pages[] = 'gap';
		}
		$pages[] = $total;

		return $pages;
	}
}

if ( ! function_exists( 'tr724_category_archive_pagination' ) ) {
	/**
	 * Previous, page numbers, and next. Inactive on the first and last page.
	 */
	function tr724_category_archive_pagination(): void {
		global $wp_query;

		$total = isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 0;
		if ( $total < 2 ) {
			return;
		}

		$current = max( 1, (int) get_query_var( 'paged' ) );
		$pages   = tr724_category_archive_pages( $current, $total );

		echo '<nav class="pagination" aria-label="' . esc_attr__( 'Sayfalama', 'tr724-news' ) . '">';

		if ( $current > 1 ) {
			printf(
				'<a class="pagination__step" href="%1$s" aria-label="%2$s">‹</a>',
				esc_url( get_pagenum_link( $current - 1 ) ),
				esc_attr__( 'Önceki sayfa', 'tr724-news' )
			);
		} else {
			printf(
				'<span class="pagination__step" aria-disabled="true" aria-label="%s">‹</span>',
				esc_attr__( 'Önceki sayfa', 'tr724-news' )
			);
		}

		foreach ( $pages as $page ) {
			if ( 'gap' === $page ) {
				echo '<span class="pagination__gap" aria-hidden="true">…</span>';
				continue;
			}

			$page = (int) $page;
			if ( $page === $current ) {
				printf(
					'<span class="pagination__page" aria-current="page">%d</span>',
					$page
				);
				continue;
			}

			printf(
				'<a class="pagination__page" href="%1$s">%2$d</a>',
				esc_url( get_pagenum_link( $page ) ),
				$page
			);
		}

		if ( $current < $total ) {
			printf(
				'<a class="pagination__step" href="%1$s" aria-label="%2$s">›</a>',
				esc_url( get_pagenum_link( $current + 1 ) ),
				esc_attr__( 'Sonraki sayfa', 'tr724-news' )
			);
		} else {
			printf(
				'<span class="pagination__step" aria-disabled="true" aria-label="%s">›</span>',
				esc_attr__( 'Sonraki sayfa', 'tr724-news' )
			);
		}

		echo '</nav>';
	}
}

$term = get_queried_object();
$name = ( $term instanceof WP_Term ) ? $term->name : single_cat_title( '', false );
$heading = tr724_category_archive_upper( $name );

$clock_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8" /><path d="M12 8v4.5l2.5 1.5" /></svg>';
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
<main class="container category-archive">
	<header class="category__header">
		<span class="category__watermark" aria-hidden="true"><?php echo esc_html( $heading ); ?></span>
		<h1 class="section-title category__title" id="category-title"><?php echo esc_html( $heading ); ?></h1>
	</header>

	<div class="row">
		<div class="column">
			<section class="stories<?php echo have_posts() ? '' : ' stories--empty'; ?>" aria-labelledby="category-title">
				<?php if ( have_posts() ) : ?>
					<?php
					global $wp_query;
					update_post_thumbnail_cache( $wp_query );
					?>
					<div class="story-grid">
						<?php
						while ( have_posts() ) {
							the_post();
							$post_id  = (int) get_the_ID();
							$thumb_id = (int) get_post_thumbnail_id( $post_id );
							$image    = '';
							if ( $thumb_id ) {
								$alt = (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
								if ( '' === $alt ) {
									$alt = get_the_title( $post_id );
								}
								$image = wp_get_attachment_image(
									$thumb_id,
									'medium_large',
									false,
									[
										'alt'      => $alt,
										'loading'  => 'lazy',
										'decoding' => 'async',
									]
								);
							}

							$published = (int) get_post_timestamp( $post_id );
							$time_text = $published
								? sprintf(
									/* translators: %s: human-readable interval, such as "2 saat". */
									__( '%s önce', 'tr724-news' ),
									human_time_diff( $published )
								)
								: '';
							$datetime = get_post_time( DATE_W3C, true, $post_id );
							?>
							<article <?php post_class( 'story-card' ); ?>>
								<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>">
									<span class="story-card__media"><?php echo $image; ?></span>
									<span class="story-card__body">
										<?php if ( '' !== $name ) : ?>
											<span class="story-card__kicker"><?php echo esc_html( $name ); ?></span>
										<?php endif; ?>
										<h3 class="story-card__title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h3>
										<?php if ( '' !== $time_text ) : ?>
											<time class="story-card__time" datetime="<?php echo esc_attr( (string) $datetime ); ?>"><?php echo $clock_icon . esc_html( $time_text ); ?></time>
										<?php endif; ?>
									</span>
								</a>
							</article>
							<?php
						}
						?>
					</div>
					<?php tr724_category_archive_pagination(); ?>
				<?php else : ?>
					<p class="stories__empty"><?php esc_html_e( 'No posts found.', 'tr724-news' ); ?></p>
				<?php endif; ?>
			</section>
		</div>
		<div class="column column--aside"><?php dynamic_sidebar( 'category' ); ?></div>
	</div>
</main>
<?php block_template_part( 'footer' ); ?>
<?php wp_footer(); ?>
</body>
</html>
