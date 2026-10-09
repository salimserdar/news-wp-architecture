<?php
/**
 * Plugin Name: News Post Sync
 * Description: Sends the current post or image to the Site Aggregator for Meilisearch. The aggregator does not read MySQL. Media sync feeds media search and does not change a post's thumbnail.
 * Version:     1.1.0
 *
 * POST /api/v1/sync/posts
 * POST /api/v1/sync/media
 * Authorization: Bearer SYNC_TOKEN
 * Content-Type: application/json
 * Body limit is 1 MB. Image bytes are not sent. Repeating a call is safe.
 * On the VPS the token is read from /etc/news-wp/aggregator.env. Docker still
 * uses SITE_AGGREGATOR_SYNC_TOKEN and SITE_AGGREGATOR_SERVICE_URL.
 */

namespace News\PostSync;

defined( 'ABSPATH' ) || exit;

const BODY_LIMIT = 1048576;

add_action( 'wp_after_insert_post', __NAMESPACE__ . '\on_after_insert', 20, 2 );
add_action( 'set_object_terms', __NAMESPACE__ . '\on_set_object_terms', 20, 4 );
add_action( 'added_post_meta', __NAMESPACE__ . '\on_thumbnail_meta', 20, 4 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\on_thumbnail_meta', 20, 4 );
add_action( 'delete_post_meta', __NAMESPACE__ . '\on_thumbnail_meta', 20, 4 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\on_thumbnail_meta', 20, 4 );
add_action( 'before_delete_post', __NAMESPACE__ . '\on_before_delete', 20, 2 );
add_action( 'add_attachment', __NAMESPACE__ . '\on_attachment_save', 20 );
add_action( 'edit_attachment', __NAMESPACE__ . '\on_attachment_save', 20 );
add_action( 'attachment_updated', __NAMESPACE__ . '\on_attachment_save', 20 );
add_action( 'added_post_meta', __NAMESPACE__ . '\on_media_meta', 20, 4 );
add_action( 'updated_post_meta', __NAMESPACE__ . '\on_media_meta', 20, 4 );
add_action( 'deleted_post_meta', __NAMESPACE__ . '\on_media_meta', 20, 4 );
add_action( 'delete_attachment', __NAMESPACE__ . '\on_delete_attachment', 20 );
add_action( 'shutdown', __NAMESPACE__ . '\flush_pending', 20 );

/**
 * Post ids waiting for one request on shutdown.
 *
 * @return array<int,string>
 */
function &pending_posts(): array {
	static $pending = [];
	return $pending;
}

/**
 * Attachment ids waiting for one request on shutdown.
 *
 * @return array<int,string>
 */
function &pending_media(): array {
	static $pending = [];
	return $pending;
}

function &is_flushing(): bool {
	static $flushing = false;
	return $flushing;
}

function on_after_insert( int $post_id, \WP_Post $post ): void {
	queue_post_upsert( $post_id, $post );
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
	queue_post_upsert( $object_id );
}

/**
 * Featured-image changes queue a post sync. The thumbnail URL is part of the post document.
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
		queue_post_upsert( (int) $object_id );
		return;
	}

	$attachment_id = is_numeric( $meta_value ) ? (int) $meta_value : 0;
	if ( $attachment_id < 1 ) {
		return;
	}
	foreach ( post_ids_with_thumbnail( $attachment_id ) as $post_id ) {
		queue_post_upsert( $post_id );
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
	$pending = &pending_posts();
	remember( $pending, $post_id, 'delete' );
}

function on_attachment_save( int $attachment_id ): void {
	queue_media_upsert( $attachment_id );
}

/**
 * Alt text and the stored file path are post meta, so a title-only hook would miss them.
 *
 * @param mixed $meta_id
 * @param mixed $object_id
 * @param mixed $meta_value
 */
function on_media_meta( $meta_id, $object_id, string $meta_key, $meta_value = null ): void {
	unset( $meta_id, $meta_value );
	if ( '_wp_attachment_image_alt' !== $meta_key && '_wp_attached_file' !== $meta_key ) {
		return;
	}
	if ( ! is_numeric( $object_id ) || (int) $object_id < 1 ) {
		return;
	}
	queue_media_upsert( (int) $object_id );
}

function on_delete_attachment( int $attachment_id ): void {
	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		return;
	}
	$pending = &pending_media();
	remember( $pending, $attachment_id, 'delete' );
}

function flush_pending(): void {
	$flushing = &is_flushing();
	$posts    = &pending_posts();
	$media    = &pending_media();
	if ( $flushing || ( [] === $posts && [] === $media ) ) {
		return;
	}

	$flushing    = true;
	$post_batch  = $posts;
	$media_batch = $media;
	$posts       = [];
	$media       = [];

	if ( '' === sync_token() ) {
		error_log( 'Site Aggregator sync skipped: sync token is empty.' );
		return;
	}

	foreach ( $post_batch as $id => $action ) {
		send_document( '/api/v1/sync/posts', (int) $id, (string) $action );
	}
	foreach ( $media_batch as $id => $action ) {
		send_document( '/api/v1/sync/media', (int) $id, (string) $action );
	}
}

function send_document( string $path, int $id, string $action ): void {
	$body   = document_body( $path, $id, $action );
	$action = isset( $body['action'] ) && is_string( $body['action'] ) ? $body['action'] : $action;
	$result = post_json( $path, $body );
	if ( ! is_sync_result( $result ) ) {
		error_log(
			sprintf(
				'Site Aggregator sync failed for %s %d (%s).',
				$path,
				$id,
				$action
			)
		);
		return;
	}
	if ( 'upsert' === $action && true !== $result['indexed'] && expects_index( $body ) ) {
		error_log(
			sprintf(
				'Site Aggregator sync did not index %s %d.',
				$path,
				$id
			)
		);
	}
}

/**
 * Documents are built here, after the request's term and meta writes have landed.
 * A missing row becomes a delete so the index does not keep it.
 *
 * @return array<string,mixed>
 */
function document_body( string $path, int $id, string $action ): array {
	if ( 'delete' === $action ) {
		return delete_body( $id );
	}
	$body = '/api/v1/sync/media' === $path ? media_upsert_body( $id ) : post_upsert_body( $id );
	if ( null === $body ) {
		return delete_body( $id );
	}
	return $body;
}

/**
 * @return array{id:int,action:string}
 */
function delete_body( int $id ): array {
	return [
		'id'     => $id,
		'action' => 'delete',
	];
}

/**
 * Status other than publish is still sent. The aggregator removes that id and indexed is false.
 *
 * @return array{action:string,post:array<string,mixed>}|null
 */
function post_upsert_body( int $post_id ): ?array {
	$post = get_post( $post_id );
	if ( ! $post instanceof \WP_Post || ! is_syncable( $post_id, $post ) ) {
		return null;
	}
	return [
		'action' => 'upsert',
		'post'   => post_document( $post ),
	];
}

/**
 * @return array<string,mixed>
 */
function post_document( \WP_Post $post ): array {
	$author_id = (int) $post->post_author;
	$user      = get_userdata( $author_id );
	$link      = get_permalink( $post );

	return [
		'id'         => (int) $post->ID,
		'slug'       => $post->post_name,
		'status'     => $post->post_status,
		'title'      => $post->post_title,
		'content'    => $post->post_content,
		'excerpt'    => $post->post_excerpt,
		'date'       => $post->post_date,
		'modified'   => $post->post_modified,
		'author'     => [
			'id'   => $author_id,
			'name' => ( $user instanceof \WP_User ) ? $user->display_name : '',
		],
		'categories' => term_list( (int) $post->ID, 'category' ),
		'tags'       => term_list( (int) $post->ID, 'post_tag' ),
		'thumbnail'  => thumbnail_url( $post ),
		'link'       => is_string( $link ) ? $link : '',
	];
}

function thumbnail_url( \WP_Post $post ): string {
	$attachment_id = (int) get_post_thumbnail_id( $post );
	if ( $attachment_id < 1 ) {
		return '';
	}
	$url = wp_get_attachment_url( $attachment_id );
	return is_string( $url ) ? $url : '';
}

/**
 * @return list<array{id:int,name:string,slug:string}>
 */
function term_list( int $post_id, string $taxonomy ): array {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! is_array( $terms ) ) {
		return [];
	}

	$list = [];
	foreach ( $terms as $term ) {
		if ( ! $term instanceof \WP_Term || $term->term_id < 1 ) {
			continue;
		}
		$list[] = [
			'id'   => (int) $term->term_id,
			'name' => $term->name,
			'slug' => $term->slug,
		];
	}
	return $list;
}

/**
 * @return array{action:string,media:array<string,mixed>}|null
 */
function media_upsert_body( int $attachment_id ): ?array {
	$post = get_post( $attachment_id );
	if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $post ) ) {
		return null;
	}

	$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
	$media = [
		'id'    => $attachment_id,
		'title' => $post->post_title,
		'date'  => $post->post_date,
		'alt'   => is_string( $alt ) ? $alt : '',
	];
	$file = attached_file( $attachment_id );
	if ( '' !== $file ) {
		$media['file'] = $file;
	}

	return [
		'action' => 'upsert',
		'media'  => $media,
	];
}

/**
 * _wp_attached_file relative to the uploads directory, for example 2024/05/a.jpg.
 * The index prefixes /wp-content/uploads/ itself.
 */
function attached_file( int $attachment_id ): string {
	$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
	if ( ! is_string( $file ) ) {
		return '';
	}
	$file   = str_replace( '\\', '/', trim( $file ) );
	$marker = 'wp-content/uploads/';
	$at     = strpos( $file, $marker );
	if ( false !== $at ) {
		$file = substr( $file, $at + strlen( $marker ) );
	}
	$file = ltrim( $file, '/' );
	if ( '' === $file || str_contains( $file, '..' ) ) {
		return '';
	}
	return $file;
}

function queue_post_upsert( int $post_id, ?\WP_Post $post = null ): void {
	if ( ! is_syncable( $post_id, $post ) ) {
		return;
	}
	$pending = &pending_posts();
	remember( $pending, $post_id, 'upsert' );
}

function queue_media_upsert( int $attachment_id ): void {
	if ( $attachment_id < 1 || ! wp_attachment_is_image( $attachment_id ) ) {
		return;
	}
	$pending = &pending_media();
	remember( $pending, $attachment_id, 'upsert' );
}

/**
 * Delete stays queued. Attachment deletion removes alt and file meta after
 * delete_attachment, and those writes must not turn the delete into an upsert.
 *
 * @param array<int,string> $pending
 */
function remember( array &$pending, int $id, string $action ): void {
	if ( $id < 1 ) {
		return;
	}
	if ( isset( $pending[ $id ] ) && 'delete' === $pending[ $id ] ) {
		return;
	}
	$pending[ $id ] = $action;
}

/**
 * Published and unpublished posts are synced. Revisions, autosaves, and the
 * empty auto-draft shell are not.
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
	if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
		return false;
	}
	return 'auto-draft' !== $post->post_status;
}

/**
 * A published post, and every image upsert, should write a document.
 * Any other status removes the post and indexed is false.
 *
 * @param array<string,mixed> $body
 */
function expects_index( array $body ): bool {
	if ( isset( $body['post'] ) && is_array( $body['post'] ) ) {
		return isset( $body['post']['status'] ) && 'publish' === $body['post']['status'];
	}
	return isset( $body['media'] ) && is_array( $body['media'] );
}

/**
 * @param array<string,mixed>|null $result
 */
function is_sync_result( ?array $result ): bool {
	return is_array( $result )
		&& isset( $result['id'], $result['action'], $result['indexed'] )
		&& is_numeric( $result['id'] )
		&& is_string( $result['action'] );
}

/**
 * @param array<string,mixed> $body
 * @return array<string,mixed>|null
 */
function post_json( string $path, array $body ): ?array {
	$encoded = encode_body( $body );
	if ( ! is_string( $encoded ) ) {
		return null;
	}

	if ( \News\Aggregator\uses_vps() ) {
		$response = \News\Aggregator\request(
			'POST',
			$path,
			[
				'timeout' => 5,
				'body'    => $encoded,
				'headers' => [
					'Content-Type' => 'application/json',
				],
			]
		);
	} else {
		$response = wp_remote_post(
			service_base_url() . $path,
			[
				'timeout' => 5,
				'headers' => [
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . sync_token(),
				],
				'body'    => $encoded,
			]
		);
	}
	if ( is_wp_error( $response ) ) {
		error_log( sprintf( 'Site Aggregator sync %s failed: %s', $path, $response->get_error_message() ) );
		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		$detail = '';
		if ( ! \News\Aggregator\uses_vps() ) {
			$detail = trim( substr( wp_strip_all_tags( (string) wp_remote_retrieve_body( $response ) ), 0, 300 ) );
		}
		error_log(
			sprintf(
				'Site Aggregator sync %s failed: HTTP %d%s',
				$path,
				$code,
				'' === $detail ? '' : ' ' . $detail
			)
		);
		return null;
	}

	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) ) {
		return null;
	}
	return $data;
}

/**
 * Content is shortened until the JSON fits. Other fields are left intact.
 *
 * @param array<string,mixed> $body
 */
function encode_body( array $body ): ?string {
	$encoded = wp_json_encode( $body );
	if ( ! is_string( $encoded ) ) {
		return null;
	}
	if ( strlen( $encoded ) <= BODY_LIMIT ) {
		return $encoded;
	}

	$content = $body['post']['content'] ?? null;
	if ( ! is_string( $content ) || '' === $content ) {
		error_log( 'Site Aggregator sync body exceeds 1 MB.' );
		return null;
	}

	$low  = 0;
	$high = strlen( $content );
	$fit  = null;
	while ( $low <= $high ) {
		$mid                     = intdiv( $low + $high, 2 );
		$body['post']['content'] = mb_strcut( $content, 0, $mid, 'UTF-8' );
		$encoded                 = wp_json_encode( $body );
		if ( ! is_string( $encoded ) ) {
			return null;
		}
		if ( strlen( $encoded ) <= BODY_LIMIT ) {
			$fit = $encoded;
			$low = $mid + 1;
		} else {
			$high = $mid - 1;
		}
	}

	if ( ! is_string( $fit ) ) {
		error_log( 'Site Aggregator sync body exceeds 1 MB.' );
		return null;
	}

	$post_id = isset( $body['post']['id'] ) ? (int) $body['post']['id'] : 0;
	error_log(
		sprintf(
			'Site Aggregator post sync trimmed content for %d to fit the 1 MB body limit.',
			$post_id
		)
	);
	return $fit;
}

function sync_token(): string {
	if ( \News\Aggregator\uses_vps() ) {
		return \News\Aggregator\token( 'sync' );
	}
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
