<?php
/**
 * Front-end layout for the site footer.
 *
 * Link columns are the navigation inner blocks, in order. An empty column
 * still prints its heading. WhatsApp, social, Patreon, and the app stores
 * come from Editorial → Links. The legal bar and column headings come from
 * block attributes.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$attr = static function ( string $key, string $default ) use ( $attributes ): string {
	if ( isset( $attributes[ $key ] ) && is_string( $attributes[ $key ] ) && $attributes[ $key ] !== '' ) {
		return $attributes[ $key ];
	}
	return $default;
};

$whatsapp_label = __( 'WHATSAPP İLETİŞİM HATTI', 'tr724-news' );
$whatsapp_phone = tr724_editorial_link( 'whatsapp_phone' );
$whatsapp_url   = tr724_editorial_link( 'whatsapp_url' );
$copyright      = $attr( 'copyright', '© 2026 TR724 ' );
$privacy_label  = $attr( 'privacyLabel', 'Privacy Policy' );
$privacy_url    = $attr( 'privacyUrl', '#' );
$terms_label    = $attr( 'termsLabel', 'Terms Of Use' );
$terms_url      = $attr( 'termsUrl', '#' );

$headings = [
	$attr( 'col1Heading', 'HABER' ),
	$attr( 'col2Heading', 'YAZARLAR' ),
	$attr( 'col3Heading', 'YOUTUBE' ),
	$attr( 'col4Heading', 'BİZ KİMİZ' ),
];

$menus = [];
foreach ( $block->inner_blocks as $inner ) {
	if ( 'core/navigation' !== $inner->name ) {
		continue;
	}
	$has_items = count( $inner->inner_blocks ) > 0 || ! empty( $inner->attributes['ref'] );
	$menus[]   = $has_items ? $inner->render() : '';
}

$social = [
	[
		'url'   => tr724_editorial_link( 'facebook' ),
		'label' => __( 'Facebook', 'tr724-news' ),
		'mod'   => 'facebook',
		'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 8.2H17V5h-2.5C11.9 5 10 6.9 10 9.5V11H8v3h2v7h3v-7h2.4l.6-3H13V9.6c0-.8.6-1.4 1.5-1.4z"/></svg>',
	],
	[
		'url'   => tr724_editorial_link( 'x' ),
		'label' => __( 'X', 'tr724-news' ),
		'mod'   => 'x',
		'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.8 4h2.4l-5.3 6L20.5 20h-4.5l-3.5-4.6L8.4 20H6l5.6-6.4L4.2 4h4.6l3.2 4.2L16.8 4zm-.8 14.4h1.3L8.1 5.5H6.7l9.3 12.9z"/></svg>',
	],
	[
		'url'   => tr724_editorial_link( 'instagram' ),
		'label' => __( 'Instagram', 'tr724-news' ),
		'mod'   => 'instagram',
		'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4.8h8A3.2 3.2 0 0 1 19.2 8v8a3.2 3.2 0 0 1-3.2 3.2H8A3.2 3.2 0 0 1 4.8 16V8A3.2 3.2 0 0 1 8 4.8zm8 1.5H8A1.7 1.7 0 0 0 6.3 8v8A1.7 1.7 0 0 0 8 17.7h8A1.7 1.7 0 0 0 17.7 16V8A1.7 1.7 0 0 0 16 6.3zM12 8.7A3.3 3.3 0 1 1 8.7 12 3.3 3.3 0 0 1 12 8.7zm0 1.5A1.8 1.8 0 1 0 13.8 12 1.8 1.8 0 0 0 12 10.2zM16.7 8a.8.8 0 1 1-.8-.8.8.8 0 0 1 .8.8z"/></svg>',
	],
	[
		'url'   => tr724_editorial_link( 'youtube' ),
		'label' => __( 'YouTube', 'tr724-news' ),
		'mod'   => 'youtube',
		'icon'  => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.6 8.2a2.2 2.2 0 0 0-1.5-1.6C17.5 6.2 12 6.2 12 6.2s-5.5 0-7.1.4a2.2 2.2 0 0 0-1.5 1.6A23 23 0 0 0 3 12a23 23 0 0 0 .4 3.8 2.2 2.2 0 0 0 1.5 1.6c1.6.4 7.1.4 7.1.4s5.5 0 7.1-.4a2.2 2.2 0 0 0 1.5-1.6A23 23 0 0 0 21 12a23 23 0 0 0-.4-3.8zM10.3 15V9l5.2 3-5.2 3z"/></svg>',
	],
];

$whatsapp_icon = '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M12.04 2C6.58 2 2.15 6.4 2.15 11.83c0 1.74.46 3.44 1.34 4.94L2 22l5.39-1.41a10 10 0 0 0 4.65 1.18h.01c5.46 0 9.89-4.4 9.89-9.83C21.94 6.4 17.5 2 12.04 2zm5.76 13.9c-.24.68-1.4 1.3-1.94 1.38-.5.08-1.12.11-1.81-.11-.41-.14-.95-.31-1.63-.61-2.87-1.24-4.74-4.13-4.88-4.32-.14-.19-1.16-1.54-1.16-2.94s.73-2.08 1-2.37c.24-.28.64-.41 1.02-.41.12 0 .23 0 .33.01.3.01.44.03.64.49.24.58.82 2 .89 2.15.07.14.12.31.02.5-.1.19-.14.31-.28.48-.14.16-.3.37-.42.49-.14.14-.29.29-.12.56.16.28.73 1.2 1.57 1.95 1.08.96 1.99 1.26 2.27 1.4.28.14.44.12.6-.07.17-.19.69-.8.88-1.08.19-.28.37-.23.63-.14.26.09 1.64.77 1.92.91.28.14.47.21.54.33.07.12.07.68-.17 1.36z"/></svg>';

$apple_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16.4 12.6c0-2.1 1.7-3.1 1.8-3.2-1-1.5-2.5-1.7-3.1-1.7-1.3-.1-2.5.8-3.2.8-.7 0-1.7-.8-2.8-.7-1.4.1-2.7.8-3.4 2.1-1.5 2.6-.4 6.4 1 8.5.7 1 1.5 2.2 2.6 2.1 1-.1 1.4-.7 2.7-.7s1.6.7 2.8.6c1.1 0 1.8-1 2.5-2 .8-1.1 1.1-2.2 1.1-2.3-.1 0-2.1-.8-2-3.5zM14.6 6.6c.6-.7 1-1.7.9-2.6-1 .1-2 .6-2.6 1.4-.6.7-1.1 1.7-.9 2.7 1 .1 2-.5 2.6-1.5z"/></svg>';

$play_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M3.4 20.5 13 12 3.5 3.4c-.3.2-.5.6-.5 1.1v14.9c0 .5.2.9.4 1.1z"/><path fill="#FBBC04" d="M16.7 9.8 13 12 3.5 3.4l9.5 5.5 3.7.9z"/><path fill="#34A853" d="M16.7 14.2 13 12 3.4 20.5c.2.2.6.2.8 0l12.5-6.3z"/><path fill="#EA4335" d="M20.6 11.2 16.7 9l-3.7 3 3.7 3.2 3.9-2.2c.6-.4.6-1.4 0-1.8z"/></svg>';

$wrapper = get_block_wrapper_attributes(
	[
		'class' => 'tr724-site-footer',
	]
);
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<footer class="site-footer">
		<div class="site-footer__inner">
			<div class="site-footer__main">
				<a class="site-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
					<img
						src="<?php echo esc_url( get_theme_file_uri( 'assets/images/tr724-footer-logo.png' ) ); ?>"
						alt="<?php echo esc_attr__( 'TR724', 'tr724-news' ); ?>"
						width="434"
						height="446"
					/>
				</a>
				<div class="site-footer__aside">
					<a class="wa-link site-footer__whatsapp" href="<?php echo esc_url( $whatsapp_url ); ?>">
						<span class="site-footer__wa-icon" aria-hidden="true">
							<?php echo $whatsapp_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</span>
						<span class="site-footer__wa-copy">
							<strong><?php echo esc_html( $whatsapp_label ); ?></strong>
							<?php echo esc_html( $whatsapp_phone ); ?>
						</span>
					</a>
					<div class="site-footer__social">
						<?php foreach ( $social as $item ) : ?>
							<a
								class="icon-btn site-footer__social-link site-footer__social-link--<?php echo esc_attr( $item['mod'] ); ?>"
								href="<?php echo esc_url( $item['url'] ); ?>"
								aria-label="<?php echo esc_attr( $item['label'] ); ?>"
							>
								<?php echo $item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</a>
						<?php endforeach; ?>
					</div>
					<div class="site-footer__stores">
						<a
							class="store-badge"
							href="<?php echo esc_url( tr724_editorial_link( 'ios' ) ); ?>"
							aria-label="<?php echo esc_attr__( "App Store'dan indirin", 'tr724-news' ); ?>"
						>
							<?php echo $apple_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span>
								<small><?php echo esc_html__( 'Download on the', 'tr724-news' ); ?></small>
								<?php echo esc_html__( 'App Store', 'tr724-news' ); ?>
							</span>
						</a>
						<a
							class="store-badge"
							href="<?php echo esc_url( tr724_editorial_link( 'android' ) ); ?>"
							aria-label="<?php echo esc_attr__( "Google Play'den indirin", 'tr724-news' ); ?>"
						>
							<?php echo $play_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span>
								<small><?php echo esc_html__( 'GET IT ON', 'tr724-news' ); ?></small>
								<?php echo esc_html__( 'Google Play', 'tr724-news' ); ?>
							</span>
						</a>
					</div>
					<a class="site-footer__patron" href="<?php echo esc_url( tr724_editorial_link( 'patreon' ) ); ?>" target="_blank" rel="noopener noreferrer">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M0 .48v23.04h4.22V.48zm15.385 0c-4.764 0-8.641 3.88-8.641 8.65 0 4.755 3.877 8.623 8.641 8.623 4.75 0 8.615-3.868 8.615-8.623C24 4.36 20.136.48 15.385.48z"/></svg>
						<?php echo esc_html__( 'Patron ol', 'tr724-news' ); ?>
					</a>
				</div>
				<div class="site-footer__cols">
					<?php foreach ( $headings as $index => $heading ) : ?>
						<div class="site-footer__col">
							<h2><?php echo esc_html( $heading ); ?></h2>
							<?php
							if ( isset( $menus[ $index ] ) && $menus[ $index ] !== '' ) {
								echo $menus[ $index ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<div class="site-footer__bottom">
			<div class="site-footer__inner">
				<p><?php echo esc_html( $copyright ); ?></p>
				<nav aria-label="<?php echo esc_attr__( 'Yasal', 'tr724-news' ); ?>">
					<a href="<?php echo esc_url( $privacy_url ); ?>"><?php echo esc_html( $privacy_label ); ?></a>
					<a href="<?php echo esc_url( $terms_url ); ?>"><?php echo esc_html( $terms_label ); ?></a>
				</nav>
			</div>
		</div>
	</footer>
</div>
