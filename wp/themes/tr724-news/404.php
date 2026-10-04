<?php
/**
 * Missing page. Same frame as the category archive: watermark, section title, Inter.
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'document_title_parts',
	static function ( array $parts ): array {
		$parts['title'] = __( 'Sayfa bulunamadı', 'tr724-news' );
		return $parts;
	}
);

$heading = tr724_archive_upper( __( 'Sayfa bulunamadı', 'tr724-news' ) );
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
<main class="container not-found">
	<header class="not-found__header">
		<span class="not-found__watermark" aria-hidden="true">404</span>
		<h1 class="section-title not-found__title" id="not-found-title"><?php echo esc_html( $heading ); ?></h1>
	</header>

	<div class="not-found__body">
		<p class="not-found__lead">
			<?php esc_html_e( 'Aradığınız sayfa gazetemizde yok. Haber taşınmış, yayından kalkmış ya da adres yanlış yazılmış olabilir.', 'tr724-news' ); ?>
		</p>

		<form class="not-found__search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="not-found__label" for="not-found-query"><?php esc_html_e( 'Haber ara', 'tr724-news' ); ?></label>
			<input
				id="not-found-query"
				type="search"
				name="s"
				minlength="2"
				maxlength="120"
				required
				placeholder="<?php esc_attr_e( 'Haber ara', 'tr724-news' ); ?>"
				autocomplete="off"
			>
			<button class="outline-btn" type="submit"><?php esc_html_e( 'Ara', 'tr724-news' ); ?></button>
		</form>

		<a class="outline-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php esc_html_e( 'Ana sayfaya dön', 'tr724-news' ); ?> <span aria-hidden="true">→</span>
		</a>
	</div>
</main>
<?php block_template_part( 'footer' ); ?>
<?php wp_footer(); ?>
</body>
</html>
