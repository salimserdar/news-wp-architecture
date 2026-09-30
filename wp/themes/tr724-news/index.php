<?php
defined( 'ABSPATH' ) || exit;
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
<main class="container">
<?php
if ( have_posts() ) {
	while ( have_posts() ) {
		the_post();
		?>
		<article <?php post_class(); ?>>
			<?php
			if ( 'page' !== get_post_type() ) {
				the_title( '<h1>', '</h1>' );
			}
			the_content();
			?>
		</article>
		<?php
	}
}
?>
</main>
<?php block_template_part( 'footer' ); ?>
<?php wp_footer(); ?>
</body>
</html>
