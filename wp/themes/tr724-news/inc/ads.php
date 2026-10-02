<?php
/**
 * Shared ad markup for the category block and the Ads widget.
 * Image, a generated AdSense unit, or stored markup.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_category_is_editor_preview' ) ) {
	/**
	 * Block editor server-side render. Skip live ad scripts there.
	 */
	function tr724_category_is_editor_preview(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}
		$route = '';
		if ( isset( $GLOBALS['wp'] ) && $GLOBALS['wp'] instanceof WP ) {
			$route = (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' );
		}
		if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) ) {
			$route = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
		}
		return str_contains( $route, '/block-renderer/' );
	}
}

if ( ! function_exists( 'tr724_category_ad_markup' ) ) {
	/**
	 * Sidebar ad: uploaded image, a generated AdSense unit, or stored markup.
	 *
	 * @param array $attributes Block or widget settings. Keys match the category block.
	 */
	function tr724_category_ad_markup( array $attributes ): string {
		$mode = isset( $attributes['adMode'] ) ? (string) $attributes['adMode'] : 'none';
		if ( ! in_array( $mode, [ 'image', 'google', 'html' ], true ) ) {
			return '';
		}

		$preview = tr724_category_is_editor_preview();
		$label   = isset( $attributes['adLabel'] ) ? trim( (string) $attributes['adLabel'] ) : '';
		$inner   = '';

		if ( 'image' === $mode ) {
			$image_id = isset( $attributes['adImageId'] ) ? (int) $attributes['adImageId'] : 0;
			$image    = $image_id > 0 ? wp_get_attachment_image(
				$image_id,
				'medium_large',
				false,
				[
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
				]
			) : '';
			if ( '' === $image ) {
				if ( ! $preview ) {
					return '';
				}
				$inner = '<div class="ads__preview">' . esc_html__( 'Select an ad image', 'tr724-news' ) . '</div>';
			} else {
				$url = isset( $attributes['adUrl'] ) ? esc_url( $attributes['adUrl'] ) : '';
				if ( '' !== $url ) {
					$inner = '<a class="ad ad--image" href="' . $url . '" target="_blank" rel="sponsored noopener noreferrer">' . $image . '</a>';
				} else {
					$inner = '<div class="ad ad--image">' . $image . '</div>';
				}
			}
		} elseif ( 'google' === $mode ) {
			$client = isset( $attributes['adClient'] ) ? trim( (string) $attributes['adClient'] ) : '';
			$slot   = isset( $attributes['adSlot'] ) ? trim( (string) $attributes['adSlot'] ) : '';
			$format = isset( $attributes['adFormat'] ) ? (string) $attributes['adFormat'] : 'auto';
			if ( ! in_array( $format, [ 'auto', 'vertical', 'rectangle', 'horizontal' ], true ) ) {
				$format = 'auto';
			}
			$client_ok = (bool) preg_match( '/^ca-pub-\d{8,20}$/', $client );
			$slot_ok   = (bool) preg_match( '/^\d{6,20}$/', $slot );

			if ( ! $client_ok || ! $slot_ok ) {
				if ( ! $preview ) {
					return '';
				}
				$inner = '<div class="ads__preview">' . esc_html__( 'Add an AdSense publisher ID and slot', 'tr724-news' ) . '</div>';
			} elseif ( $preview ) {
				$inner = '<div class="ads__preview">' . esc_html__( 'Google AdSense', 'tr724-news' ) . '</div>';
			} else {
				$styles = [
					'auto'       => 'display:block;width:100%;min-height:250px',
					'vertical'   => 'display:inline-block;width:160px;height:600px',
					'rectangle'  => 'display:inline-block;width:196px;height:250px',
					'horizontal' => 'display:inline-block;width:196px;height:90px',
				];
				$src    = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . rawurlencode( $client );
				$inner  = '<div class="ads__unit">';
				$inner .= '<script async src="' . esc_url( $src ) . '" crossorigin="anonymous"></script>';
				$inner .= '<ins class="adsbygoogle" style="' . esc_attr( $styles[ $format ] ) . '"';
				$inner .= ' data-ad-client="' . esc_attr( $client ) . '"';
				$inner .= ' data-ad-slot="' . esc_attr( $slot ) . '"';
				if ( 'auto' === $format ) {
					$inner .= ' data-ad-format="auto" data-full-width-responsive="true"';
				}
				$inner .= '></ins>';
				$inner .= '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>';
				$inner .= '</div>';
			}
		} else {
			$html = isset( $attributes['adHtml'] ) ? trim( (string) $attributes['adHtml'] ) : '';
			if ( '' === $html ) {
				if ( ! $preview ) {
					return '';
				}
				$inner = '<div class="ads__preview">' . esc_html__( 'Paste ad code', 'tr724-news' ) . '</div>';
			} elseif ( $preview ) {
				$inner = '<div class="ads__preview">' . esc_html__( 'Custom ad code', 'tr724-news' ) . '</div>';
			} else {
				// Stored like a Custom HTML block. WordPress kses runs on save.
				$inner = '<div class="ads__unit">' . $html . '</div>';
			}
		}

		$markup  = '<aside class="ads" aria-label="' . esc_attr__( 'Reklamlar', 'tr724-news' ) . '">';
		$markup .= '<div class="ads__sticky">' . $inner;
		if ( '' !== $label ) {
			$markup .= '<p class="ads__label">' . esc_html( $label ) . '</p>';
		}
		$markup .= '</div></aside>';

		return $markup;
	}
}
