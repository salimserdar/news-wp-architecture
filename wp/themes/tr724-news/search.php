<?php
/**
 * Search results from the site aggregator.
 *
 * {SITE_AGGREGATOR_SERVICE_URL}/api/v1/search/posts?q=&page=&limit=20&status=publish&sort=
 */

defined( 'ABSPATH' ) || exit;

$raw   = get_search_query();
$query = tr724_search_query( $raw );
$sort  = tr724_search_sort( isset( $_GET['sort'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['sort'] ) ) : 'date:desc' );
$page  = max( 1, (int) get_query_var( 'paged' ) );

$result = '' === $query ? null : tr724_search_posts( $query, $page, $sort );
$items  = is_array( $result ) ? $result['results'] : [];
$total  = is_array( $result ) ? (int) $result['total'] : 0;
$limit  = is_array( $result ) && (int) $result['limit'] > 0 ? (int) $result['limit'] : 20;
$pages  = (int) min( 50, (int) ceil( $total / $limit ) );
$error  = is_wp_error( $result ) ? $result->get_error_message() : '';
if ( '' === $query && '' !== trim( $raw ) ) {
	$error = __( 'Arama için en az 2 karakter yazın.', 'tr724-news' );
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
<main class="container category-archive search-archive">
	<header class="category__header">
		<span class="category__watermark" aria-hidden="true"><?php esc_html_e( 'ARAMA', 'tr724-news' ); ?></span>
		<h1 class="section-title category__title" id="search-page-title"><?php echo esc_html( '' !== $query ? $query : __( 'Arama', 'tr724-news' ) ); ?></h1>
	</header>

	<form class="search-archive__form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<label class="search-archive__label" for="search-page-query"><?php esc_html_e( 'Haber ara', 'tr724-news' ); ?></label>
		<input
			id="search-page-query"
			type="search"
			name="s"
			value="<?php echo esc_attr( $query ); ?>"
			minlength="2"
			maxlength="120"
			required
			placeholder="<?php esc_attr_e( 'Haber ara', 'tr724-news' ); ?>"
			autocomplete="off"
		>
		<label class="search-archive__sort">
			<span class="search-archive__sort-label"><?php esc_html_e( 'Sırala', 'tr724-news' ); ?></span>
			<select name="sort">
				<option value="date:desc" <?php selected( $sort, 'date:desc' ); ?>><?php esc_html_e( 'En yeni', 'tr724-news' ); ?></option>
				<option value="date:asc" <?php selected( $sort, 'date:asc' ); ?>><?php esc_html_e( 'En eski', 'tr724-news' ); ?></option>
			</select>
		</label>
		<button class="outline-btn" type="submit"><?php esc_html_e( 'Ara', 'tr724-news' ); ?></button>
	</form>

	<div class="row">
		<div class="column">
			<section class="stories<?php echo [] === $items ? ' stories--empty' : ''; ?>" aria-labelledby="search-page-title">
				<?php if ( [] !== $items ) : ?>
					<div class="search-results">
						<?php foreach ( $items as $item ) : ?>
							<?php
							$kicker = isset( $item['kicker'] ) ? (string) $item['kicker'] : '';
							if ( 'MANŞET' === $kicker ) {
								$kicker = '';
							}
							$author = isset( $item['author'] ) ? (string) $item['author'] : '';
							if ( '' !== $author && false !== mb_stripos( $author, 'TR724' ) ) {
								$author = '';
							}
							$time = isset( $item['time'] ) ? (string) $item['time'] : '';
							?>
							<a class="search-result" href="<?php echo esc_url( (string) $item['url'] ); ?>">
								<?php if ( '' !== $kicker ) : ?>
									<span class="search-result__kicker"><?php echo esc_html( $kicker ); ?></span>
								<?php endif; ?>
								<span class="search-result__title"><?php echo esc_html( (string) $item['title'] ); ?></span>
								<?php if ( '' !== $author || '' !== $time ) : ?>
									<span class="search-result__meta">
										<?php if ( '' !== $author ) : ?>
											<span class="search-result__author"><?php echo esc_html( $author ); ?></span>
										<?php endif; ?>
										<?php if ( '' !== $time ) : ?>
											<time<?php echo '' !== $item['datetime'] ? ' datetime="' . esc_attr( (string) $item['datetime'] ) . '"' : ''; ?>><?php echo esc_html( $time ); ?></time>
										<?php endif; ?>
									</span>
								<?php endif; ?>
							</a>
						<?php endforeach; ?>
					</div>
					<?php if ( $pages > 1 ) : ?>
						<nav class="pagination" aria-label="<?php esc_attr_e( 'Sayfalama', 'tr724-news' ); ?>">
							<?php if ( $page > 1 ) : ?>
								<a class="pagination__step" href="<?php echo esc_url( tr724_search_page_link( $query, $page - 1, $sort ) ); ?>" aria-label="<?php esc_attr_e( 'Önceki sayfa', 'tr724-news' ); ?>">‹</a>
							<?php else : ?>
								<span class="pagination__step" aria-disabled="true" aria-label="<?php esc_attr_e( 'Önceki sayfa', 'tr724-news' ); ?>">‹</span>
							<?php endif; ?>
							<?php foreach ( tr724_archive_pages( $page, $pages ) as $number ) : ?>
								<?php if ( 'gap' === $number ) : ?>
									<span class="pagination__gap" aria-hidden="true">…</span>
								<?php elseif ( (int) $number === $page ) : ?>
									<span class="pagination__page" aria-current="page"><?php echo (int) $number; ?></span>
								<?php else : ?>
									<a class="pagination__page" href="<?php echo esc_url( tr724_search_page_link( $query, (int) $number, $sort ) ); ?>"><?php echo (int) $number; ?></a>
								<?php endif; ?>
							<?php endforeach; ?>
							<?php if ( $page < $pages ) : ?>
								<a class="pagination__step" href="<?php echo esc_url( tr724_search_page_link( $query, $page + 1, $sort ) ); ?>" aria-label="<?php esc_attr_e( 'Sonraki sayfa', 'tr724-news' ); ?>">›</a>
							<?php else : ?>
								<span class="pagination__step" aria-disabled="true" aria-label="<?php esc_attr_e( 'Sonraki sayfa', 'tr724-news' ); ?>">›</span>
							<?php endif; ?>
						</nav>
					<?php endif; ?>
				<?php elseif ( '' !== $error || '' !== $query ) : ?>
					<p class="stories__empty"><?php echo esc_html( '' !== $error ? $error : __( 'Sonuç bulunamadı', 'tr724-news' ) ); ?></p>
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
