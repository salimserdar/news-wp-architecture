<?php
/**
 * Plugin Name: News Post Sync
 * Description: Notifies the Site Aggregator when a post is saved, recategorized, retagged, has its featured image changed, or is deleted.
 * Version:     1.0.0
 *
 * POST {SITE_AGGREGATOR_SERVICE_URL}/api/v1/sync/posts
 * Authorization: Bearer SITE_AGGREGATOR_SYNC_TOKEN
 * Both values come from wp-config.php constants, then the environment.
 */

namespace News\PostSync;

defined( 'ABSPATH' ) || exit;

add_action( 'wp_after_insert_post', __NAMESPACE__ . '\on_after_insert', 20, 2 );
add_action( 'set_object_terms', __NAMESPACE__ . '\on_set_object_terms', 20, 4 );
add_action( 'added_post_meta', __NAMESPACE__ . '\on_thumbnail_meta', 20, 4 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\on_thumbnail_meta', 20, 4 );
add_action( 'delete_post_meta', __NAMESPACE__ . '\on_thumbnail_meta', 20, 4 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\on_thumbnail_meta', 20, 4 );
add_action( 'before_delete_post', __NAMESPACE__ . '\on_before_delete', 20, 2 );
add_action( 'shutdown', __NAMESPACE__ . '\flush_pending', 20 );

/**
 * Post ids waiting for one request on shutdown. Delete replaces upsert.
 *
 * @return array<int,string>
 */
function &pending_queue(): array {
	static $pending = [];
	return $pending;
}

function &is_flushing(): bool {
	static $flushing = false;
	return $flushing;
}

function on_after_insert( int $post_id, \WP_Post $post ): void {
	queue_upsert( $post_id, $post );
}

/**
 * @param mixed $terms
 * @param mixed $tt_ids
 */
function on_set_object_terms( int $object_id, $terms, $tt_ids, string $taxonomy ): void {
	unset( $terms, $tt_ids );
	if ( 'category' !== $taxonomy && 'post_tag' !== $taxonomy ) {
		return;
	}
	queue_upsert( $object_id );
}

/**
 * Featured-image changes queue a post sync.
 *
 * wp_delete_attachment() removes every `_thumbnail_id` pointing at that file
 * with delete_metadata( 'post', null, '_thumbnail_id', $attachment_id, true ).
 * The object id in that call is null. Typing it as int fatals the request
 * before the attachment row is deleted, so the media library reports a failed
 * delete and the photo stays.
 *
 * @param mixed $meta_id
 * @param mixed $object_id
 * @param mixed $meta_value Attachment id when WordPress is deleting every match.
 */
function on_thumbnail_meta( $meta_id, $object_id, string $meta_key, $meta_value = null ): void {
	unset( $meta_id );
	if ( '_thumbnail_id' !== $meta_key ) {
		return;
	}
	if ( is_numeric( $object_id ) && (int) $object_id > 0 ) {
		queue_upsert( (int) $object_id );
		return;
	}

	$attachment_id = is_numeric( $meta_value ) ? (int) $meta_value : 0;
	if ( $attachment_id < 1 ) {
		return;
	}
	foreach ( post_ids_with_thumbnail( $attachment_id ) as $post_id ) {
		queue_upsert( $post_id );
	}
}

/**
 * Posts that still store this attachment as their featured image.
 *
 * @return list<int>
 */
function post_ids_with_thumbnail( int $attachment_id ): array {
	global $wpdb;

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
			(string) $attachment_id
		)
	);
	if ( ! is_array( $ids ) ) {
		return [];
	}

	$post_ids = [];
	foreach ( $ids as $id ) {
		$post_id = (int) $id;
		if ( $post_id > 0 ) {
			$post_ids[] = $post_id;
		}
	}
	return $post_ids;
}

function on_before_delete( int $post_id, \WP_Post $post ): void {
	if ( ! is_syncable( $post_id, $post ) ) {
		return;
	}
	queue( $post_id, 'delete' );
}

function flush_pending(): void {
	$flushing = &is_flushing();
	$pending  = &pending_queue();
	if ( $flushing || [] === $pending ) {
		return;
	}

	$flushing = true;
	$batch    = $pending;
	$pending  = [];

	if ( '' === sync_token() ) {
		error_log( 'Site Aggregator post sync skipped: SITE_AGGREGATOR_SYNC_TOKEN is empty.' );
		return;
	}

	foreach ( $batch as $id => $action ) {
		$result = post_json(
			'/api/v1/sync/posts',
			[
				'id'     => (int) $id,
				'action' => $action,
			]
		);
		if ( ! is_array( $result ) || ! isset( $result['id'], $result['action'] ) ) {
			error_log(
				sprintf(
					'Site Aggregator post sync failed for %d (%s).',
					(int) $id,
					$action
				)
			);
		}
	}
}

function queue_upsert( int $post_id, ?\WP_Post $post = null ): void {
	if ( ! is_syncable( $post_id, $post ) ) {
		return;
	}
	queue( $post_id, 'upsert' );
}

function queue( int $post_id, string $action ): void {
	$pending = &pending_queue();
	if ( isset( $pending[ $post_id ] ) && 'delete' === $pending[ $post_id ] ) {
		return;
	}
	$pending[ $post_id ] = $action;
}

/**
 * Published and unpublished posts are synced. Revisions and autosaves are not.
 */
function is_syncable( int $post_id, ?\WP_Post $post = null ): bool {
	if ( $post_id < 1 ) {
		return false;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return false;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return false;
	}
	if ( ! $post instanceof \WP_Post ) {
		$post = get_post( $post_id );
	}
	if ( ! $post instanceof \WP_Post ) {
		return false;
	}
	return 'post' === $post->post_type;
}

/**
 * @param array<string,mixed> $body
 * @return array<string,mixed>|null
 */
function post_json( string $path, array $body ): ?array {
	$headers = [
		'Accept'       => 'application/json',
		'Content-Type' => 'application/json',
	];
	$token   = sync_token();
	if ( '' !== $token ) {
		$headers['Authorization'] = 'Bearer ' . $token;
	}

	$encoded = wp_json_encode( $body );
	if ( ! is_string( $encoded ) ) {
		return null;
	}

	$response = wp_remote_post(
		service_base_url() . $path,
		[
			'timeout' => 5,
			'headers' => $headers,
			'body'    => $encoded,
		]
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		return null;
	}
	return $data;
}

function sync_token(): string {
	if ( defined( 'SITE_AGGREGATOR_SYNC_TOKEN' ) && is_string( SITE_AGGREGATOR_SYNC_TOKEN ) && '' !== SITE_AGGREGATOR_SYNC_TOKEN ) {
		return SITE_AGGREGATOR_SYNC_TOKEN;
	}

	$env = getenv( 'SITE_AGGREGATOR_SYNC_TOKEN' );
	if ( is_string( $env ) && '' !== $env ) {
		return $env;
	}

	return '';
}

function service_base_url(): string {
	if ( defined( 'SITE_AGGREGATOR_SERVICE_URL' ) && is_string( SITE_AGGREGATOR_SERVICE_URL ) && '' !== SITE_AGGREGATOR_SERVICE_URL ) {
		return untrailingslashit( SITE_AGGREGATOR_SERVICE_URL );
	}

	$env = getenv( 'SITE_AGGREGATOR_SERVICE_URL' );
	if ( is_string( $env ) && '' !== $env ) {
		return untrailingslashit( $env );
	}

	return 'http://host.docker.internal:80';
}
