<?php
/**
 * Front-end layout for the site header.
 *
 * The logo, navigation, and search inner blocks are placed into the bar,
 * the category strip, the search modal, and the hamburger drawer.
 * Navigation is rendered twice so the strip and the drawer stay one menu.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$logo      = '';
$nav_strip = '';
$nav_drawer = '';
$search    = '';

foreach ( $block->inner_blocks as $inner ) {
	if ( 'core/site-logo' === $inner->name ) {
		$logo = $inner->render();
	} elseif ( 'core/navigation' === $inner->name ) {
		$nav_strip  = $inner->render();
		$nav_drawer = $inner->render();
	} elseif ( 'core/search' === $inner->name ) {
		$search = $inner->render();
	}
}

if ( trim( $logo ) === '' ) {
	$logo = sprintf(
		'<a class="custom-logo-link" href="%1$s" rel="home"><img class="custom-logo" src="%2$s" alt="%3$s" /></a>',
		esc_url( home_url( '/' ) ),
		esc_url( get_theme_file_uri( 'assets/images/tr724-logo.png' ) ),
		esc_attr( get_bloginfo( 'name' ) )
	);
}

$tv_label    = isset( $attributes['tvLabel'] ) && $attributes['tvLabel'] !== '' ? $attributes['tvLabel'] : 'TR724 Tv';
$tv_url      = isset( $attributes['tvUrl'] ) && $attributes['tvUrl'] !== '' ? $attributes['tvUrl'] : '#';
$flash_label = isset( $attributes['flashLabel'] ) && $attributes['flashLabel'] !== '' ? $attributes['flashLabel'] : 'Son Dakika';
$flash_url   = isset( $attributes['flashUrl'] ) && $attributes['flashUrl'] !== '' ? $attributes['flashUrl'] : '#';

$drawer_id = wp_unique_id( 'tr724-drawer-' );
$search_id = wp_unique_id( 'tr724-search-' );
$title_id  = wp_unique_id( 'tr724-search-title-' );

$tv_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M23 12.2s0-3.2-.4-4.6c-.2-.9-.9-1.6-1.8-1.8C19.2 5.4 12 5.4 12 5.4s-7.2 0-8.8.4c-.9.2-1.6.9-1.8 1.8C1 9 1 12.2 1 12.2s0 3.2.4 4.6c.2.9.9 1.6 1.8 1.8 1.6.4 8.8.4 8.8.4s7.2 0 8.8-.4c.9-.2 1.6-.9 1.8-1.8.4-1.4.4-4.6.4-4.6z"/><path fill="#1a4fbf" d="M9.8 15.5V8.9l6 3.3-6 3.3z"/></svg>';

$flash_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4.5l2.5 1.5"/></svg>';

$wrapper = get_block_wrapper_attributes(
	[
		'class'             => 'tr724-site-header',
		'data-tr724-header' => '',
	]
);
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<header class="site-header">
		<div class="site-header__bar">
			<div class="site-header__inner">
				<div class="site-header__logo">
					<?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="site-header__tools">
					<a class="site-header__tv" href="<?php echo esc_url( $tv_url ); ?>">
						<?php echo $tv_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo esc_html( $tv_label ); ?>
					</a>
					<button
						class="site-header__search"
						type="button"
						aria-label="<?php esc_attr_e( 'Ara', 'tr724-news' ); ?>"
						aria-haspopup="dialog"
						aria-controls="<?php echo esc_attr( $search_id ); ?>"
					>
						<svg viewBox="0 0 24 24" aria-hidden="true">
							<circle cx="11" cy="11" r="6.5" />
							<path d="m16 16 4 4" />
						</svg>
					</button>
					<button
						class="site-header__menu"
						type="button"
						aria-label="<?php esc_attr_e( 'Menü', 'tr724-news' ); ?>"
						aria-expanded="false"
						aria-controls="<?php echo esc_attr( $drawer_id ); ?>"
					>
						<span></span>
						<span></span>
						<span></span>
					</button>
				</div>
			</div>
		</div>
		<div class="site-nav">
			<div class="site-header__inner site-header__scroll">
				<?php echo $nav_strip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
	</header>

	<div class="search-modal" id="<?php echo esc_attr( $search_id ); ?>" hidden>
		<div class="search-modal__backdrop" data-search-close></div>
		<div
			class="search-modal__dialog"
			role="dialog"
			aria-modal="true"
			aria-labelledby="<?php echo esc_attr( $title_id ); ?>"
		>
			<div class="search-modal__head">
				<h2 class="search-modal__title" id="<?php echo esc_attr( $title_id ); ?>"><?php esc_html_e( 'Ara', 'tr724-news' ); ?></h2>
				<button
					class="search-modal__close"
					type="button"
					aria-label="<?php esc_attr_e( 'Kapat', 'tr724-news' ); ?>"
					data-search-close
				>
					×
				</button>
			</div>
			<?php echo $search; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>

	<div class="drawer" id="<?php echo esc_attr( $drawer_id ); ?>" hidden>
		<div class="drawer__backdrop" data-drawer-close></div>
		<div class="drawer__panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Menü', 'tr724-news' ); ?>">
			<div class="drawer__scroll">
				<div class="drawer__tools">
					<a class="drawer__flash" href="<?php echo esc_url( $flash_url ); ?>">
						<?php echo $flash_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo esc_html( $flash_label ); ?>
					</a>
					<a class="drawer__tv" href="<?php echo esc_url( $tv_url ); ?>">
						<?php echo $tv_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo esc_html( $tv_label ); ?>
					</a>
				</div>
				<div class="drawer__nav">
					<?php echo $nav_drawer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</div>
		</div>
	</div>
</div>
