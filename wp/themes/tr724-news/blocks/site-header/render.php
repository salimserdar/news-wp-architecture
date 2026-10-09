<?php
/**
 * Front-end layout for the site header.
 *
 * The logo and navigation inner blocks are placed into the bar,
 * the category strip, and the hamburger drawer. Search runs in the modal.
 * Navigation is rendered twice so the strip and the drawer stay one menu.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$logo       = '';
$nav_strip  = '';
$nav_drawer = '';

foreach ( $block->inner_blocks as $inner ) {
	if ( 'core/site-logo' === $inner->name ) {
		$logo = $inner->render();
	} elseif ( 'core/navigation' === $inner->name ) {
		$nav_strip  = $inner->render();
		$nav_drawer = $inner->render();
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

$tv_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#ff0000" d="M23 12.2s0-3.2-.4-4.6c-.2-.9-.9-1.6-1.8-1.8C19.2 5.4 12 5.4 12 5.4s-7.2 0-8.8.4c-.9.2-1.6.9-1.8 1.8C1 9 1 12.2 1 12.2s0 3.2.4 4.6c.2.9.9 1.6 1.8 1.8 1.6.4 8.8.4 8.8.4s7.2 0 8.8-.4c.9-.2 1.6-.9 1.8-1.8.4-1.4.4-4.6.4-4.6z"/><path fill="#fff" d="M9.8 15.5V8.9l6 3.3-6 3.3z"/></svg>';

$flash_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M12 8v4.5l2.5 1.5"/></svg>';

$whatsapp_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2C6.58 2 2.15 6.4 2.15 11.83c0 1.74.46 3.44 1.34 4.94L2 22l5.39-1.41a10 10 0 0 0 4.65 1.18h.01c5.46 0 9.89-4.4 9.89-9.83C21.94 6.4 17.5 2 12.04 2zm5.76 13.9c-.24.68-1.4 1.3-1.94 1.38-.5.08-1.12.11-1.81-.11-.41-.14-.95-.31-1.63-.61-2.87-1.24-4.74-4.13-4.88-4.32-.14-.19-1.16-1.54-1.16-2.94s.73-2.08 1-2.37c.24-.28.64-.41 1.02-.41.12 0 .23 0 .33.01.3.01.44.03.64.49.24.58.82 2 .89 2.15.07.14.12.31.02.5-.1.19-.14.31-.28.48-.14.16-.3.37-.42.49-.14.14-.29.29-.12.56.16.28.73 1.2 1.57 1.95 1.08.96 1.99 1.26 2.27 1.4.28.14.44.12.6-.07.17-.19.69-.8.88-1.08.19-.28.37-.23.63-.14.26.09 1.64.77 1.92.91.28.14.47.21.54.33.07.12.07.68-.17 1.36z"/></svg>';

$apple_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16.4 12.6c0-2.1 1.7-3.1 1.8-3.2-1-1.5-2.5-1.7-3.1-1.7-1.3-.1-2.5.8-3.2.8-.7 0-1.7-.8-2.8-.7-1.4.1-2.7.8-3.4 2.1-1.5 2.6-.4 6.4 1 8.5.7 1 1.5 2.2 2.6 2.1 1-.1 1.4-.7 2.7-.7s1.6.7 2.8.6c1.1 0 1.8-1 2.5-2 .8-1.1 1.1-2.2 1.1-2.3-.1 0-2.1-.8-2-3.5zM14.6 6.6c.6-.7 1-1.7.9-2.6-1 .1-2 .6-2.6 1.4-.6.7-1.1 1.7-.9 2.7 1 .1 2-.5 2.6-1.5z"/></svg>';

$play_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M3.4 20.5 13 12 3.5 3.4c-.3.2-.5.6-.5 1.1v14.9c0 .5.2.9.4 1.1z"/><path fill="#FBBC04" d="M16.7 9.8 13 12 3.5 3.4l9.5 5.5 3.7.9z"/><path fill="#34A853" d="M16.7 14.2 13 12 3.4 20.5c.2.2.6.2.8 0l12.5-6.3z"/><path fill="#EA4335" d="M20.6 11.2 16.7 9l-3.7 3 3.7 3.2 3.9-2.2c.6-.4.6-1.4 0-1.8z"/></svg>';

$social = [
	[
		'label' => __( 'Facebook', 'tr724-news' ),
		'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 8.5h2.5V5.5H14c-2.2 0-4 1.8-4 4V12H8v3h2v6h3v-6h2.4l.6-3H13v-2c0-.8.7-1.5 1-1.5z"/></svg>',
	],
	[
		'label' => __( 'X', 'tr724-news' ),
		'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.8 4h2.4l-5.3 6L20.5 20h-4.5l-3.5-4.6L8.4 20H6l5.6-6.4L4.2 4h4.6l3.2 4.2L16.8 4zm-.8 14.4h1.3L8.1 5.5H6.7l9.3 12.9z"/></svg>',
	],
	[
		'label' => __( 'Instagram', 'tr724-news' ),
		'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4.5h8A3.5 3.5 0 0 1 19.5 8v8a3.5 3.5 0 0 1-3.5 3.5H8A3.5 3.5 0 0 1 4.5 16V8A3.5 3.5 0 0 1 8 4.5zm8 1.6H8A1.9 1.9 0 0 0 6.1 8v8A1.9 1.9 0 0 0 8 17.9h8A1.9 1.9 0 0 0 17.9 16V8A1.9 1.9 0 0 0 16 6.1zM12 8.6A3.4 3.4 0 1 1 8.6 12 3.4 3.4 0 0 1 12 8.6zm0 1.6A1.8 1.8 0 1 0 13.8 12 1.8 1.8 0 0 0 12 10.2zM16.8 7.7a.85.85 0 1 1-.85-.85.85.85 0 0 1 .85.85z"/></svg>',
	],
	[
		'label' => __( 'YouTube', 'tr724-news' ),
		'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 8.2a2.2 2.2 0 0 0-1.6-1.6C17.6 6.2 12 6.2 12 6.2s-5.6 0-7.2.4a2.2 2.2 0 0 0-1.6 1.6A23 23 0 0 0 2.8 12a23 23 0 0 0 .4 3.8 2.2 2.2 0 0 0 1.6 1.6c1.6.4 7.2.4 7.2.4s5.6 0 7.2-.4a2.2 2.2 0 0 0 1.6-1.6 23 23 0 0 0 .4-3.8 23 23 0 0 0-.4-3.8zM10.2 15.1V8.9l5.4 3.1-5.4 3.1z"/></svg>',
	],
];

$patron_item = sprintf(
	'<li class="wp-block-navigation-item site-nav__patron"><a class="site-nav__patron-link" href="%1$s" target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M0 .48v23.04h4.22V.48zm15.385 0c-4.764 0-8.641 3.88-8.641 8.65 0 4.755 3.877 8.623 8.641 8.623 4.75 0 8.615-3.868 8.615-8.623C24 4.36 20.136.48 15.385.48z"/></svg>%2$s</a></li>',
	esc_url( 'https://www.patreon.com/tr724' ),
	esc_html__( 'Patron ol', 'tr724-news' )
);

/**
 * Append the Patreon button to the navigation list.
 *
 * @param string $html Rendered navigation markup.
 * @param string $item List item to insert before the container closes.
 */
$append_patron = static function ( string $html, string $item ): string {
	if ( ! preg_match( '/<ul\b[^>]*wp-block-navigation__container[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
		return $html;
	}

	$offset = $match[0][1] + strlen( $match[0][0] );
	$length = strlen( $html );
	$depth  = 1;

	while ( $offset < $length && $depth > 0 ) {
		$next_open  = stripos( $html, '<ul', $offset );
		$next_close = stripos( $html, '</ul>', $offset );

		if ( false === $next_close ) {
			return $html;
		}

		if ( false !== $next_open && $next_open < $next_close ) {
			++$depth;
			$offset = $next_open + 3;
			continue;
		}

		--$depth;

		if ( 0 === $depth ) {
			return substr( $html, 0, $next_close ) . $item . substr( $html, $next_close );
		}

		$offset = $next_close + 5;
	}

	return $html;
};

$nav_strip  = $append_patron( $nav_strip, $patron_item );
$nav_drawer = $append_patron( $nav_drawer, $patron_item );

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

	<div
		class="search-modal"
		id="<?php echo esc_attr( $search_id ); ?>"
		hidden
		data-search-endpoint="<?php echo esc_url( tr724_search_browser_endpoint() ); ?>"
		data-search-page="<?php echo esc_url( home_url( '/' ) ); ?>"
		data-search-empty="<?php esc_attr_e( 'Sonuç bulunamadı', 'tr724-news' ); ?>"
		data-search-error="<?php esc_attr_e( 'Arama şu anda kullanılamıyor.', 'tr724-news' ); ?>"
		data-search-loading="<?php esc_attr_e( 'Aranıyor…', 'tr724-news' ); ?>"
	>
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
			<input
				class="search-modal__input"
				type="search"
				placeholder="<?php esc_attr_e( 'Haber ara', 'tr724-news' ); ?>"
				aria-label="<?php esc_attr_e( 'Haber ara', 'tr724-news' ); ?>"
				autocomplete="off"
			/>
			<label class="search-modal__sort" hidden>
				<span class="search-modal__sort-label"><?php esc_html_e( 'Sırala', 'tr724-news' ); ?></span>
				<select>
					<option value="date:desc"><?php esc_html_e( 'En yeni', 'tr724-news' ); ?></option>
					<option value="date:asc"><?php esc_html_e( 'En eski', 'tr724-news' ); ?></option>
				</select>
			</label>
			<div class="search-modal__results">
				<div class="search-modal__list" aria-live="polite"></div>
				<a class="outline-btn search-modal__more" hidden>
					<?php esc_html_e( 'Daha Fazla', 'tr724-news' ); ?> <span aria-hidden="true">→</span>
				</a>
			</div>
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
			<div class="drawer__footer">
				<a class="wa-link drawer__whatsapp" href="https://wa.me/902122121212">
					<?php echo $whatsapp_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span>
						<strong><?php esc_html_e( 'WHATSAPP İLETİŞİM HATTI', 'tr724-news' ); ?></strong>
						0212 212 12 12
					</span>
				</a>
				<div class="drawer__apps">
					<a class="store-badge" href="https://www.apple.com/app-store/" aria-label="<?php esc_attr_e( "App Store'dan indirin", 'tr724-news' ); ?>">
						<?php echo $apple_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span>
							<small><?php esc_html_e( 'Download on the', 'tr724-news' ); ?></small>
							<?php esc_html_e( 'App Store', 'tr724-news' ); ?>
						</span>
					</a>
					<a class="store-badge" href="https://play.google.com/store" aria-label="<?php esc_attr_e( "Google Play'den indirin", 'tr724-news' ); ?>">
						<?php echo $play_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span>
							<small><?php esc_html_e( 'GET IT ON', 'tr724-news' ); ?></small>
							<?php esc_html_e( 'Google Play', 'tr724-news' ); ?>
						</span>
					</a>
				</div>
				<a class="drawer__patron" href="https://www.patreon.com/tr724" target="_blank" rel="noopener noreferrer">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M0 .48v23.04h4.22V.48zm15.385 0c-4.764 0-8.641 3.88-8.641 8.65 0 4.755 3.877 8.623 8.641 8.623 4.75 0 8.615-3.868 8.615-8.623C24 4.36 20.136.48 15.385.48z"/></svg>
					<?php esc_html_e( 'Patron ol', 'tr724-news' ); ?>
				</a>
				<div class="drawer__social">
					<?php foreach ( $social as $item ) : ?>
						<a class="icon-btn" href="#" aria-label="<?php echo esc_attr( $item['label'] ); ?>">
							<?php echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
</div>
