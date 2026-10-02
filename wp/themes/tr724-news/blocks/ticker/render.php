<?php
/**
 * Market ticker. Rates come from the exchange-rates service.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/rates.php';

$symbols     = tr724_ticker_symbols( $attributes );
$instruments = tr724_ticker_instruments();

$wrapper = get_block_wrapper_attributes(
	[
		'class'      => 'ticker',
		'aria-label' => __( 'Piyasalar', 'tr724-news' ),
	]
);

if ( is_wp_error( $instruments ) ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	echo '<section ' . $wrapper . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<p class="ticker__empty">' . esc_html( $instruments->get_error_message() ) . '</p>';
	echo '</section>';
	return;
}

$by_symbol = [];
foreach ( $instruments as $instrument ) {
	$by_symbol[ $instrument['symbol'] ] = $instrument;
}

$rows = [];
foreach ( $symbols as $symbol ) {
	if ( isset( $by_symbol[ $symbol ] ) ) {
		$rows[] = $by_symbol[ $symbol ];
	}
}

if ( [] === $rows ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	echo '<section ' . $wrapper . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<p class="ticker__empty">' . esc_html__( 'Select rates in the block settings.', 'tr724-news' ) . '</p>';
	echo '</section>';
	return;
}

/**
 * @param array<int, array{symbol: string, name: string, label: string, group: string, price: float, change: float|null, changePercent: float}> $items
 */
$render_items = static function ( array $items, bool $hidden ): void {
	foreach ( $items as $item ) {
		$direction = tr724_ticker_direction( $item['change'], $item['changePercent'] );
		$change    = 'ticker__change';
		if ( 'up' === $direction ) {
			$change .= ' ticker__change--up';
		} elseif ( 'down' === $direction ) {
			$change .= ' ticker__change--down';
		}

		echo '<li class="ticker__item"' . ( $hidden ? ' aria-hidden="true"' : '' ) . '>';
		echo '<span class="ticker__label">' . esc_html( $item['label'] ) . '</span>';
		echo '<span class="ticker__row">';
		echo '<span class="ticker__value">' . esc_html( tr724_ticker_format_amount( $item['price'] ) ) . '</span>';
		echo '<span class="' . esc_attr( $change ) . '">' . esc_html( tr724_ticker_change_text( $item['change'], $item['changePercent'] ) ) . '</span>';
		echo '</span>';
		echo '</li>';
	}
};

echo '<section ' . $wrapper . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '<ul class="ticker__list">';
$render_items( $rows, false );
$render_items( $rows, true );
echo '</ul>';
echo '</section>';
