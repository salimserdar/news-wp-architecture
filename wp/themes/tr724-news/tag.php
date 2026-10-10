<?php
/**
 * Tag archive. Same story grid as category.php.
 * The sidebar (.column--aside) is the Category widget area.
 */

defined( 'ABSPATH' ) || exit;

$term = get_queried_object();
$name = ( $term instanceof WP_Term ) ? $term->name : single_tag_title( '', false );
$heading = tr724_archive_upper( $name );

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
		<h1 class="section-title category__title" id="tag-title"><?php echo esc_html( $heading ); ?></h1>
	</header>

	<div class="row">
		<div class="column">
			<section class="stories<?php echo have_posts() ? '' : ' stories--empty'; ?>" aria-labelledby="tag-title">
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
								$image = tr724_story_card_image( $thumb_id, $alt );
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
					<?php tr724_archive_pagination(); ?>
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
