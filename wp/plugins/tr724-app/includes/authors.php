<?php
/**
 * Author list for the mobile app.
 *
 * Same people as the yazarlar grid: users with the Author role, ordered by
 * display name. archive_author and newsroom editors are not in that grid.
 */

namespace Tr724\App;

defined( 'ABSPATH' ) || exit;

function register_author_routes(): void {
	$key = TR724_APP_ROUTE_KEY;
	if ( ! is_string( $key ) || '' === $key ) {
		return;
	}

	register_rest_route(
		$key,
		'/w',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\author_list',
			'permission_callback' => '__return_true',
			'show_in_index'       => false,
		]
	);
}

/**
 * @return \WP_REST_Response
 */
function author_list(): \WP_REST_Response {
	$users = get_users(
		[
			'role'    => 'author',
			'orderby' => 'display_name',
			'order'   => 'ASC',
		]
	);

	$rows = [];
	foreach ( $users as $user ) {
		if ( $user instanceof \WP_User ) {
			$rows[] = author_list_row( $user );
		}
	}

	return rest_ensure_response(
		[
			'authors' => $rows,
		]
	);
}

/**
 * @return array{id: int, slug: string, name: string, url: string, avatar: string, email: string, kind: string, social: array{facebook: string, x: string, youtube: string}}
 */
function author_list_row( \WP_User $user ): array {
	$name = $user->display_name;
	if ( '' === $name ) {
		$name = $user->user_login;
	}
	$user_id = (int) $user->ID;

	return [
		'id'     => $user_id,
		'slug'   => $user->user_nicename,
		'name'   => $name,
		'url'    => get_author_posts_url( $user_id ),
		'avatar' => author_avatar_url( $user_id ),
		'email'  => $user->user_email,
		'kind'   => author_kind( $user_id ),
		'social' => [
			'facebook' => author_social_url( (string) get_user_meta( $user_id, 'facebook', true ) ),
			'x'        => author_social_url( (string) get_user_meta( $user_id, 'twitter', true ) ),
			'youtube'  => author_social_url( (string) get_user_meta( $user_id, 'youtube', true ) ),
		],
	];
}

/**
 * Stored profile URL or @handle, using the theme helper when the theme is active.
 */
function author_social_url( string $raw ): string {
	if ( function_exists( 'tr724_yazarlar_href' ) ) {
		return tr724_yazarlar_href( $raw );
	}

	$raw = trim( $raw );
	if ( '' === $raw ) {
		return '';
	}
	if ( str_starts_with( $raw, '@' ) ) {
		$handle = rawurlencode( ltrim( substr( $raw, 1 ), '@' ) );
		return '' === $handle ? '' : 'https://twitter.com/' . $handle;
	}
	if ( ! preg_match( '#^https?://#i', $raw ) ) {
		$raw = 'https://' . ltrim( $raw, '/' );
	}
	return $raw;
}
