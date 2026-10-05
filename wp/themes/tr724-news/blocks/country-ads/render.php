<?php
/**
 * Full-width ad. The fallback creative is in the HTML. view.js swaps in a
 * country creative after reading the visitor country.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_country_ad_creative' ) ) {
	/**
	 * Original file URL so animated GIFs keep playing.
	 *
	 * @return array{src:string,href:string,alt:string}
	 */
	function tr724_country_ad_creative( int $image_id, string $url ): array {
		$empty = [
			'src'  => '',
			'href' => '',
			'alt'  => '',
		];
		if ( $image_id <= 0 ) {
			return $empty;
		}
		$src = wp_get_attachment_url( $image_id );
		if ( ! is_string( $src ) || '' === $src ) {
			return $empty;
		}
		$src = esc_url_raw( $src );
		if ( '' === $src ) {
			return $empty;
		}
		$alt = trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
		if ( '' === $alt ) {
			$alt = __( 'Reklam', 'tr724-news' );
		}
		return [
			'src'  => $src,
			'href' => esc_url_raw( $url ),
			'alt'  => $alt,
		];
	}
}

if ( ! function_exists( 'tr724_country_ad_frame' ) ) {
	/**
	 * Centered image, linked when a target URL is set.
	 *
	 * @param array{src:string,href:string,alt:string} $creative
	 */
	function tr724_country_ad_frame( array $creative ): string {
		$image = '<img class="country-ad__image" src="' . esc_url( $creative['src'] ) . '" alt="' . esc_attr( $creative['alt'] ) . '" loading="lazy" decoding="async" />';
		if ( '' !== $creative['href'] ) {
			return '<a class="country-ad__link" href="' . esc_url( $creative['href'] ) . '" target="_blank" rel="sponsored noopener noreferrer">' . $image . '</a>';
		}
		return '<div class="country-ad__link">' . $image . '</div>';
	}
}

$fallback_id  = isset( $attributes['fallbackImageId'] ) ? (int) $attributes['fallbackImageId'] : 0;
$fallback_url = isset( $attributes['fallbackUrl'] ) ? (string) $attributes['fallbackUrl'] : '';
$fallback     = tr724_country_ad_creative( $fallback_id, $fallback_url );
$preview      = function_exists( 'tr724_category_is_editor_preview' ) && tr724_category_is_editor_preview();

if ( '' === $fallback['src'] ) {
	if ( ! $preview ) {
		return;
	}
	echo '<div ' . get_block_wrapper_attributes( [ 'class' => 'country-ad country-ad--empty' ] ) . '>';
	echo '<p class="country-ad__placeholder">' . esc_html__( 'Select a fallback ad. It shows when a country has no creative.', 'tr724-news' ) . '</p>';
	echo '</div>';
	return;
}

$countries = [];
$rows      = isset( $attributes['countries'] ) && is_array( $attributes['countries'] ) ? $attributes['countries'] : [];
foreach ( $rows as $row ) {
	if ( ! is_array( $row ) ) {
		continue;
	}
	$code = isset( $row['code'] ) ? strtoupper( (string) $row['code'] ) : '';
	$code = preg_replace( '/[^A-Z]/', '', $code );
	if ( ! is_string( $code ) || 2 !== strlen( $code ) ) {
		continue;
	}
	$image_id = isset( $row['imageId'] ) ? (int) $row['imageId'] : 0;
	$row_url  = isset( $row['url'] ) ? (string) $row['url'] : '';
	$creative = tr724_country_ad_creative( $image_id, $row_url );
	if ( '' === $creative['src'] ) {
		continue;
	}
	$countries[ $code ] = [
		'src'  => $creative['src'],
		'href' => $creative['href'],
		'alt'  => $creative['alt'],
	];
}

$config = [
	'fallback'  => [
		'src'  => $fallback['src'],
		'href' => $fallback['href'],
		'alt'  => $fallback['alt'],
	],
	'countries' => $countries,
];

echo '<aside ' . get_block_wrapper_attributes(
	[
		'class'           => 'country-ad',
		'data-country-ad' => '1',
		'aria-label'      => __( 'Reklam', 'tr724-news' ),
	]
) . '>';
echo '<script type="application/json" class="country-ad__config">' . wp_json_encode( $config ) . '</script>';
echo tr724_country_ad_frame( $fallback ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the helper.

if ( $preview ) {
	$codes = array_keys( $countries );
	if ( $codes ) {
		echo '<p class="country-ad__note">' . esc_html(
			sprintf(
				/* translators: %s: comma-separated country codes. */
				__( 'Country ads: %s. Everyone else sees the fallback.', 'tr724-news' ),
				implode( ', ', $codes )
			)
		) . '</p>';
	} else {
		echo '<p class="country-ad__note">' . esc_html__( 'Everyone sees the fallback until a country ad is added.', 'tr724-news' ) . '</p>';
	}
}

echo '</aside>';
