<?php
/**
 * Plugin Name: TR724 App API
 * Description: Unlisted JSON endpoints for the TR724 mobile app.
 * Version: 0.1.0
 * Requires PHP: 8.3
 * Text Domain: tr724-app
 *
 * TR724_APP_ROUTE_KEY is the unguessable REST namespace. Define it in
 * wp-config.php before this plugin loads to use a different key.
 */

namespace Tr724\App;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'TR724_APP_ROUTE_KEY' ) ) {
	define( 'TR724_APP_ROUTE_KEY', '624bdbc5fbfb0a03edba3cb4ec4d0757' );
}

require_once __DIR__ . '/includes/post-detail.php';
require_once __DIR__ . '/includes/authors.php';
require_once __DIR__ . '/includes/role-posts.php';
require_once __DIR__ . '/includes/term-posts.php';
require_once __DIR__ . '/includes/manset.php';
require_once __DIR__ . '/includes/cache.php';

add_action( 'rest_api_init', __NAMESPACE__ . '\register_routes' );
add_action( 'rest_api_init', __NAMESPACE__ . '\register_author_routes' );
add_action( 'rest_api_init', __NAMESPACE__ . '\register_role_post_routes' );
add_action( 'rest_api_init', __NAMESPACE__ . '\register_term_post_routes' );
add_action( 'rest_api_init', __NAMESPACE__ . '\register_manset_routes' );
register_cache();
