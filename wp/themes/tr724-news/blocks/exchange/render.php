<?php
/**
 * Sidebar markets. Gold and BIST first, then free-market currencies.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/ticker/rates.php';

if ( ! tr724_exchange_is_visible( $attributes ) ) {
	return;
}

$title_id = wp_unique_id( 'exchange-title-' );
$title    = isset( $attributes['title'] ) ? trim( (string) $attributes['title'] ) : '';
if ( '' === $title ) {
	$title = __( 'PİYASALAR', 'tr724-news' );
}

$wrapper = get_block_wrapper_attributes(
	[
		'class'           => 'exchange',
		'aria-labelledby' => $title_id,
	]
);

/**
 * @param array<int, array{symbol: string, name: string, label: string, group: string, price: float, change: float|null, changePercent: float}> $instruments
 */
$pick = static function ( array $instruments, string $group, string $preferred ): ?array {
	$fallback = null;
	foreach ( $instruments as $instrument ) {
		if ( $group !== $instrument['group'] ) {
			continue;
		}
		if ( $preferred === $instrument['symbol'] ) {
			return $instrument;
		}
		if ( null === $fallback ) {
			$fallback = $instrument;
		}
	}
	return $fallback;
};

/**
 * @param array{symbol: string, name: string, label: string, group: string, price: float, change: float|null, changePercent: float} $item
 */
$change_markup = static function ( array $item ): string {
	$direction = tr724_ticker_direction( $item['change'], $item['changePercent'] );
	$class     = 'exchange__change';
	if ( 'up' === $direction ) {
		$class .= ' exchange__change--up';
	} elseif ( 'down' === $direction ) {
		$class .= ' exchange__change--down';
	}
	return '<span class="' . esc_attr( $class ) . '">' . esc_html( tr724_ticker_change_text( $item['change'], $item['changePercent'] ) ) . '</span>';
};

$instruments = tr724_ticker_instruments();
$featured    = [];
$rows        = [];

if ( ! is_wp_error( $instruments ) ) {
	$gold = $pick( $instruments, 'gold', 'GLDGR' );
	$bist = $pick( $instruments, 'bist', 'XU100' );
	if ( null !== $gold ) {
		$featured[] = $gold;
	}
	if ( null !== $bist ) {
		$featured[] = $bist;
	}

	$used = [];
	foreach ( $featured as $item ) {
		$used[ $item['symbol'] ] = true;
	}
	foreach ( $instruments as $instrument ) {
		if ( 'freeMarket' !== $instrument['group'] || isset( $used[ $instrument['symbol'] ] ) ) {
			continue;
		}
		$rows[] = $instrument;
	}
}

$unavailable = is_wp_error( $instruments ) || ( [] === $featured && [] === $rows );
if ( $unavailable && ! current_user_can( 'edit_posts' ) ) {
	return;
}

echo '<section ' . $wrapper . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo '<div class="exchange__box">';
echo '<div class="exchange__head">';
echo '<h2 class="exchange__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $title ) . '</h2>';
echo '</div>';

if ( $unavailable ) {
	$message = is_wp_error( $instruments )
		? $instruments->get_error_message()
		: __( 'Exchange rates are unavailable.', 'tr724-news' );
	echo '<p class="exchange__empty">' . esc_html( $message ) . '</p>';
	echo '</div></section>';
	return;
}

if ( [] !== $featured ) {
	$featured_class = 'exchange__featured';
	if ( 1 === count( $featured ) ) {
		$featured_class .= ' exchange__featured--single';
	}
	echo '<div class="' . esc_attr( $featured_class ) . '">';
	foreach ( $featured as $item ) {
		echo '<div class="exchange__card">';
		echo '<span class="exchange__label">' . esc_html( $item['label'] ) . '</span>';
		echo '<span class="exchange__value">' . esc_html( tr724_ticker_format_amount( $item['price'] ) ) . '</span>';
		echo $change_markup( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}
	echo '</div>';
}

if ( [] !== $rows ) {
	echo '<ul class="exchange__list">';
	foreach ( $rows as $item ) {
		echo '<li class="exchange__row">';
		echo '<span class="exchange__label">' . esc_html( $item['label'] ) . '</span>';
		echo '<span class="exchange__quote">';
		echo '<span class="exchange__value">' . esc_html( tr724_ticker_format_amount( $item['price'] ) ) . '</span>';
		echo $change_markup( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</span></li>';
	}
	echo '</ul>';
}

echo '</div></section>';
