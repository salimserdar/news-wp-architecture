<?php
/**
 * Author archive. Profile and posts from wp/html-them/yazar.html.
 * The sidebar (.column--aside) is the same Category widget area as category.php.
 */

defined( 'ABSPATH' ) || exit;

$author = get_queried_object();
if ( ! $author instanceof WP_User ) {
	$author = get_userdata( (int) get_query_var( 'author' ) );
}

$user_id = ( $author instanceof WP_User ) ? (int) $author->ID : 0;
$name    = ( $author instanceof WP_User ) ? $author->display_name : '';
if ( '' === $name && $author instanceof WP_User ) {
	$name = $author->user_login;
}

$bio = ( $author instanceof WP_User ) ? trim( $author->description ) : '';

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

$watermark = __( 'YAZAR', 'tr724-news' );
$heading   = __( 'YAZAR PROFİL', 'tr724-news' );
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
<main class="container category-archive author-page">
	<header class="category__header">
		<span class="category__watermark" aria-hidden="true"><?php echo esc_html( $watermark ); ?></span>
		<h1 class="section-title category__title"><?php echo esc_html( $heading ); ?></h1>
	</header>

	<?php if ( $user_id > 0 ) : ?>
		<article class="author-profile">
			<?php
			echo tr724_yazarlar_avatar(
				$user_id,
				[
					'class'      => 'author-profile__avatar',
					'alt'        => $name,
					'size'       => 192,
					'image_size' => 'medium',
				]
			);
			?>
			<div class="author-profile__body">
				<h2 class="author-profile__name"><?php echo esc_html( tr724_archive_upper( $name ) ); ?></h2>
				<?php if ( '' !== $bio ) : ?>
					<p class="author-profile__bio"><?php echo esc_html( $bio ); ?></p>
				<?php endif; ?>
				<?php echo tr724_yazarlar_social( $user_id, 'author-profile__social' ); ?>
			</div>
		</article>
	<?php endif; ?>

	<div class="row">
		<div class="column">
			<section class="author-posts<?php echo have_posts() ? '' : ' author-posts--empty'; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: author display name. */ __( '%s yazıları', 'tr724-news' ), $name ) ); ?>">
				<?php if ( have_posts() ) : ?>
					<?php
					while ( have_posts() ) {
						the_post();
						$post_id   = (int) get_the_ID();
						$published = (int) get_post_timestamp( $post_id );
						$datetime  = get_post_time( DATE_W3C, true, $post_id );
						$date_text = '';
						if ( $published ) {
							$month_num = (int) wp_date( 'n', $published );
							$date_text = wp_date( 'd', $published ) . ' ' . ( $months[ $month_num ] ?? '' ) . ' ' . wp_date( 'Y', $published );
						}
						$excerpt = get_the_excerpt( $post_id );
						?>
						<article <?php post_class( 'author-post' ); ?>>
							<?php if ( '' !== $date_text ) : ?>
								<time datetime="<?php echo esc_attr( (string) $datetime ); ?>"><?php echo esc_html( $date_text ); ?></time>
							<?php endif; ?>
							<h2 class="author-post__title">
								<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
							</h2>
							<?php if ( '' !== $excerpt ) : ?>
								<p class="author-post__excerpt"><?php echo esc_html( $excerpt ); ?></p>
							<?php endif; ?>
						</article>
						<?php
					}
					?>
				<?php else : ?>
					<p class="author-posts__empty"><?php esc_html_e( 'No posts found.', 'tr724-news' ); ?></p>
				<?php endif; ?>
			</section>
			<?php tr724_archive_pagination(); ?>
		</div>
		<div class="column column--aside"><?php dynamic_sidebar( 'category' ); ?></div>
	</div>
</main>
<?php block_template_part( 'footer' ); ?>
<?php wp_footer(); ?>
</body>
</html>
