<?php
/**
 * Exchange rates from the site aggregator.
 *
 * SITE_AGGREGATOR_SERVICE_URL is the WordPress-side address of the service
 * at http://127.0.0.1:3000 on the host. Inside Docker that host is
 * host.docker.internal, not 127.0.0.1.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Symbols shown when a ticker block has no selection yet.
 *
 * @return array<int, string>
 */
function tr724_ticker_default_symbols(): array {
	return [ 'USDTRY', 'EURTRY', 'GBPTRY', 'XU100', 'GLDGR' ];
}

function tr724_ticker_endpoint(): string {
	$base = 'http://127.0.0.1:3000';
	if ( defined( 'SITE_AGGREGATOR_SERVICE_URL' ) && is_string( SITE_AGGREGATOR_SERVICE_URL ) && '' !== SITE_AGGREGATOR_SERVICE_URL ) {
		$base = SITE_AGGREGATOR_SERVICE_URL;
	}
	return rtrim( $base, '/' ) . '/api/v1/exchange-rates';
}

function tr724_ticker_upper( string $text ): string {
	$text = strtr(
		$text,
		[
			'i' => 'İ',
			'ı' => 'I',
		]
	);
	return mb_strtoupper( $text, 'UTF-8' );
}

function tr724_ticker_label( string $symbol, string $name, string $group ): string {
	$defaults = [
		'USDTRY' => 'DOLAR',
		'EURTRY' => 'EURO',
		'GBPTRY' => 'STERLİN',
		'XU100'  => 'BIST',
		'GLDGR'  => 'ALTIN',
	];
	if ( isset( $defaults[ $symbol ] ) ) {
		return $defaults[ $symbol ];
	}

	$label = tr724_ticker_upper( $name );
	if ( 'centralBank' === $group ) {
		return 'TCMB ' . $label;
	}
	return $label;
}

/**
 * @param array<string, mixed> $item
 * @return array{symbol: string, name: string, label: string, group: string, price: float, change: float|null, changePercent: float}|null
 */
function tr724_ticker_read_item( array $item, string $group ): ?array {
	$symbol = isset( $item['symbol'] ) ? strtoupper( (string) $item['symbol'] ) : '';
	if ( ! preg_match( '/^[A-Z0-9_]{1,16}$/', $symbol ) ) {
		return null;
	}

	$price_key = '';
	if ( isset( $item['price'] ) && is_numeric( $item['price'] ) ) {
		$price_key = 'price';
	} elseif ( isset( $item['rate'] ) && is_numeric( $item['rate'] ) ) {
		$price_key = 'rate';
	}
	if ( '' === $price_key ) {
		return null;
	}

	$name  = isset( $item['name'] ) ? trim( (string) $item['name'] ) : '';
	$name  = '' !== $name ? $name : $symbol;
	$price = (float) $item[ $price_key ];

	$change = null;
	if ( isset( $item['change'] ) && is_numeric( $item['change'] ) ) {
		$change = (float) $item['change'];
	} elseif ( isset( $item['previousClose'] ) && is_numeric( $item['previousClose'] ) ) {
		$change = $price - (float) $item['previousClose'];
	}

	$percent = isset( $item['changePercent'] ) && is_numeric( $item['changePercent'] ) ? (float) $item['changePercent'] : 0.0;

	return [
		'symbol'        => $symbol,
		'name'          => $name,
		'label'         => tr724_ticker_label( $symbol, $name, $group ),
		'group'         => $group,
		'price'         => $price,
		'change'        => $change,
		'changePercent' => $percent,
	];
}

/**
 * @param array<string, mixed> $data
 * @return array<int, array{symbol: string, name: string, label: string, group: string, price: float, change: float|null, changePercent: float}>
 */
function tr724_ticker_normalize( array $data ): array {
	$instruments = [];
	$seen        = [];

	foreach ( [ 'freeMarket', 'centralBank', 'parities', 'gold', 'bist' ] as $group ) {
		if ( ! isset( $data[ $group ] ) || ! is_array( $data[ $group ] ) ) {
			continue;
		}
		$items = array_is_list( $data[ $group ] ) ? $data[ $group ] : [ $data[ $group ] ];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$row = tr724_ticker_read_item( $item, $group );
			if ( null === $row || isset( $seen[ $row['symbol'] ] ) ) {
				continue;
			}
			$seen[ $row['symbol'] ] = true;
			$instruments[]          = $row;
		}
	}

	return $instruments;
}

/**
 * @return array<int, array{symbol: string, name: string, label: string, group: string, price: float, change: float|null, changePercent: float}>|WP_Error
 */
function tr724_ticker_instruments(): array|WP_Error {
	$cache_key = 'tr724_fx_rates_v2';
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$response = wp_remote_get(
		tr724_ticker_endpoint(),
		[
			'timeout' => 8,
			'headers' => tr724_aggregator_headers(),
		]
	);

	$instruments = null;
	if ( ! is_wp_error( $response ) ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			$normalized = tr724_ticker_normalize( $data );
			if ( [] !== $normalized ) {
				$instruments = $normalized;
			}
		}
	}

	if ( null === $instruments ) {
		$stale = get_transient( 'tr724_fx_rates_last_v2' );
		if ( is_array( $stale ) ) {
			return $stale;
		}
		return new WP_Error( 'tr724_exchange_rates', __( 'Exchange rates are unavailable.', 'tr724-news' ), [ 'status' => 503 ] );
	}

	set_transient( $cache_key, $instruments, 5 * MINUTE_IN_SECONDS );
	set_transient( 'tr724_fx_rates_last_v2', $instruments, 12 * HOUR_IN_SECONDS );

	return $instruments;
}

/**
 * @param array<string, mixed> $attributes
 * @return array<int, string>
 */
function tr724_ticker_symbols( array $attributes ): array {
	$raw = $attributes['symbols'] ?? null;
	if ( ! is_array( $raw ) ) {
		$raw = tr724_ticker_default_symbols();
	}

	$symbols = [];
	foreach ( $raw as $symbol ) {
		$symbol = strtoupper( (string) $symbol );
		if ( ! preg_match( '/^[A-Z0-9_]{1,16}$/', $symbol ) || in_array( $symbol, $symbols, true ) ) {
			continue;
		}
		$symbols[] = $symbol;
		if ( count( $symbols ) >= 12 ) {
			break;
		}
	}

	return $symbols;
}

function tr724_ticker_format_amount( float $value ): string {
	return number_format( abs( $value ), 2, ',', '.' );
}

function tr724_ticker_change_text( ?float $change, float $percent ): string {
	$percent_text = '%' . tr724_ticker_format_amount( $percent );
	if ( null === $change ) {
		return '(' . $percent_text . ')';
	}
	return tr724_ticker_format_amount( $change ) . '(' . $percent_text . ')';
}

function tr724_ticker_direction( ?float $change, float $percent ): string {
	$delta = 0.0 !== $percent ? $percent : (float) $change;
	if ( $delta > 0 ) {
		return 'up';
	}
	if ( $delta < 0 ) {
		return 'down';
	}
	return '';
}

add_action( 'rest_api_init', static function (): void {
	register_rest_route(
		'tr724/v1',
		'/exchange-rates',
		[
			'methods'             => 'GET',
			'permission_callback' => static function (): bool {
				return current_user_can( 'edit_posts' );
			},
			'callback'            => static function () {
				$instruments = tr724_ticker_instruments();
				if ( is_wp_error( $instruments ) ) {
					return $instruments;
				}

				$rows = [];
				foreach ( $instruments as $instrument ) {
					$rows[] = [
						'symbol' => $instrument['symbol'],
						'name'   => $instrument['name'],
						'label'  => $instrument['label'],
						'group'  => $instrument['group'],
					];
				}

				return rest_ensure_response( [ 'instruments' => $rows ] );
			},
		]
	);
} );
