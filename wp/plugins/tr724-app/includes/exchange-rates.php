<?php
/**
 * Market rates for the mobile app.
 *
 * Same fetch, labels, and cache as the theme ticker and Piyasalar box:
 * tr724_ticker_instruments(). A fresh copy lasts about 5 minutes. After an
 * upstream failure the last good copy can be returned for up to 12 hours.
 * 503 means there is no copy to return.
 */

namespace Tr724\App;

defined( 'ABSPATH' ) || exit;

function register_exchange_rate_routes(): void {
	$key = TR724_APP_ROUTE_KEY;
	if ( ! is_string( $key ) || '' === $key ) {
		return;
	}

	register_rest_route(
		$key,
		'/c',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\exchange_rates',
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
		]
	);
}

/**
 * @return \WP_REST_Response|\WP_Error
 */
function exchange_rates() {
	if ( ! function_exists( 'tr724_ticker_instruments' ) ) {
		return exchange_rates_unavailable();
	}

	$instruments = tr724_ticker_instruments();
	if ( is_wp_error( $instruments ) ) {
		return exchange_rates_unavailable();
	}

	$rows = [];
	foreach ( $instruments as $instrument ) {
		if ( ! is_array( $instrument ) ) {
			continue;
		}
		$row = exchange_rate_row( $instrument );
		if ( null !== $row ) {
			$rows[] = $row;
		}
	}

	return rest_ensure_response(
		[
			'rates' => $rows,
		]
	);
}

function exchange_rates_unavailable(): \WP_Error {
	return new \WP_Error(
		'tr724_app_exchange_rates',
		'Exchange rates are unavailable.',
		[ 'status' => 503 ]
	);
}

/**
 * @param array<string, mixed> $instrument
 * @return array{symbol: string, name: string, label: string, group: string, price: float, change: float|null, change_percent: float, direction: string}|null
 */
function exchange_rate_row( array $instrument ): ?array {
	$symbol = isset( $instrument['symbol'] ) ? (string) $instrument['symbol'] : '';
	if ( '' === $symbol || ! isset( $instrument['price'] ) || ! is_numeric( $instrument['price'] ) ) {
		return null;
	}

	$change = null;
	if ( isset( $instrument['change'] ) && is_numeric( $instrument['change'] ) ) {
		$change = (float) $instrument['change'];
	}
	$percent = isset( $instrument['changePercent'] ) && is_numeric( $instrument['changePercent'] ) ? (float) $instrument['changePercent'] : 0.0;

	return [
		'symbol'         => $symbol,
		'name'           => isset( $instrument['name'] ) ? (string) $instrument['name'] : $symbol,
		'label'          => isset( $instrument['label'] ) ? (string) $instrument['label'] : $symbol,
		'group'          => isset( $instrument['group'] ) ? (string) $instrument['group'] : '',
		'price'          => (float) $instrument['price'],
		'change'         => $change,
		'change_percent' => $percent,
		'direction'      => exchange_rate_direction( $change, $percent ),
	];
}

function exchange_rate_direction( ?float $change, float $percent ): string {
	$direction = function_exists( 'tr724_ticker_direction' ) ? tr724_ticker_direction( $change, $percent ) : '';
	if ( 'up' === $direction || 'down' === $direction ) {
		return $direction;
	}
	return 'flat';
}
