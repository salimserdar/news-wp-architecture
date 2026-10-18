<?php
/**
 * Editorial → Manşet admin screen.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_editorial_manset_admin_rows' ) ) {
	/**
	 * Saved posts that still exist, for the editor list.
	 *
	 * @return array<int, array{id: int, title: string, date: string, thumb: string}>
	 */
	function tr724_editorial_manset_admin_rows(): array {
		$ids = tr724_editorial_manset_ids();
		if ( ! $ids ) {
			return [];
		}

		$query = new WP_Query(
			[
				'post_type'              => 'post',
				'post_status'            => [ 'publish', 'draft', 'pending', 'future', 'private' ],
				'post__in'               => $ids,
				'orderby'                => 'post__in',
				'posts_per_page'         => count( $ids ),
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
			]
		);

		$by_id = [];
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$by_id[ (int) $post->ID ] = $post;
		}

		$rows = [];
		foreach ( $ids as $id ) {
			$post = $by_id[ $id ] ?? null;
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$thumb = get_the_post_thumbnail_url( $post, 'thumbnail' );
			$rows[] = [
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'date'  => get_the_date( 'Y-m-d H:i', $post ),
				'thumb' => is_string( $thumb ) ? $thumb : '',
			];
		}

		return $rows;
	}
}

if ( ! function_exists( 'tr724_editorial_render_manset_row' ) ) {
	/**
	 * One selected post. Search adds rows by cloning #tr724-ms-template.
	 *
	 * @param array{id: int, title: string, date: string, thumb: string} $row
	 * @param int                                                        $index 1-based position.
	 */
	function tr724_editorial_render_manset_row( array $row, int $index ): void {
		$id    = (int) $row['id'];
		$title = (string) ( $row['title'] ?? '' );
		$date  = (string) ( $row['date'] ?? '' );
		$thumb = (string) ( $row['thumb'] ?? '' );
		?>
		<li class="tr724-ms-item" data-post-id="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="tr724_manset_posts[<?php echo esc_attr( (string) $id ); ?>]" value="1">
			<span class="tr724-ms-handle dashicons dashicons-menu" title="<?php echo esc_attr( tr724_editorial_ui( 'drag' ) ); ?>" aria-hidden="true"></span>
			<span class="tr724-ms-index" aria-hidden="true"><?php echo esc_html( (string) $index ); ?></span>
			<?php if ( '' !== $thumb ) : ?>
				<img class="tr724-ms-thumb" src="<?php echo esc_url( $thumb ); ?>" alt="" width="48" height="48">
			<?php else : ?>
				<span class="tr724-ms-thumb tr724-ms-thumb--empty dashicons dashicons-format-aside" aria-hidden="true"></span>
			<?php endif; ?>
			<div class="tr724-ms-item-body">
				<div class="tr724-ms-item-head">
					<p class="tr724-ms-post">
						<strong class="tr724-ms-post-title"><?php echo esc_html( $title ); ?></strong>
						<span class="tr724-ms-post-date"><?php echo esc_html( $date ); ?></span>
					</p>
					<div class="tr724-ms-actions">
						<button type="button" class="button-link tr724-ms-up"><?php echo esc_html( tr724_editorial_ui( 'move_up' ) ); ?></button>
						<button type="button" class="button-link tr724-ms-down"><?php echo esc_html( tr724_editorial_ui( 'move_down' ) ); ?></button>
						<button type="button" class="button-link-delete tr724-ms-remove"><?php echo esc_html( tr724_editorial_ui( 'remove' ) ); ?></button>
					</div>
				</div>
			</div>
		</li>
		<?php
	}
}

if ( ! function_exists( 'tr724_editorial_render_manset_page' ) ) {
	function tr724_editorial_render_manset_page(): void {
		if ( ! current_user_can( tr724_editorial_capability() ) ) {
			wp_die(
				esc_html( tr724_editorial_ui( 'no_permission' ) ),
				'',
				[ 'response' => 403 ]
			);
		}

		$rows  = tr724_editorial_manset_admin_rows();
		$order = implode( ',', array_map( static fn( array $row ): int => $row['id'], $rows ) );
		?>
		<div class="wrap tr724-ms">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( tr724_editorial_ui( 'manset_saved' ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['dropped'] ) && '1' === $_GET['dropped'] ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php echo esc_html( tr724_editorial_ui( 'manset_dropped' ) ); ?></p>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php echo esc_html( tr724_editorial_ui( 'manset_description' ) ); ?>
			</p>
			<p class="description">
				<?php echo esc_html( tr724_editorial_ui( 'manset_auto_help' ) ); ?>
			</p>
			<noscript>
				<div class="notice notice-warning">
					<p><?php echo esc_html( tr724_editorial_ui( 'js_required' ) ); ?></p>
				</div>
			</noscript>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tr724-ms-form">
				<input type="hidden" name="action" value="tr724_save_manset">
				<?php wp_nonce_field( 'tr724_save_manset' ); ?>
				<input type="hidden" name="tr724_manset_order" id="tr724-ms-order" value="<?php echo esc_attr( $order ); ?>">

				<div class="tr724-ms-add">
					<label for="tr724-ms-search"><?php echo esc_html( tr724_editorial_ui( 'manset_add' ) ); ?></label>
					<input type="search" class="regular-text" id="tr724-ms-search" placeholder="<?php echo esc_attr( tr724_editorial_ui( 'manset_search' ) ); ?>" autocomplete="off">
					<p class="tr724-ms-status" id="tr724-ms-status" aria-live="polite"></p>
					<ul class="tr724-ms-results" id="tr724-ms-results" hidden></ul>
				</div>

				<p class="tr724-ms-empty" id="tr724-ms-empty" <?php echo $rows ? 'hidden' : ''; ?>>
					<?php echo esc_html( tr724_editorial_ui( 'manset_empty' ) ); ?>
				</p>
				<ul class="tr724-ms-list" id="tr724-ms-list">
					<?php foreach ( $rows as $index => $row ) : ?>
						<?php tr724_editorial_render_manset_row( $row, (int) $index + 1 ); ?>
					<?php endforeach; ?>
				</ul>

				<template id="tr724-ms-template">
					<li class="tr724-ms-item" data-post-id="">
						<input type="hidden" value="1">
						<span class="tr724-ms-handle dashicons dashicons-menu" title="<?php echo esc_attr( tr724_editorial_ui( 'drag' ) ); ?>" aria-hidden="true"></span>
						<span class="tr724-ms-index" aria-hidden="true"></span>
						<span class="tr724-ms-thumb tr724-ms-thumb--empty dashicons dashicons-format-aside" aria-hidden="true"></span>
						<div class="tr724-ms-item-body">
							<div class="tr724-ms-item-head">
								<p class="tr724-ms-post">
									<strong class="tr724-ms-post-title"></strong>
									<span class="tr724-ms-post-date"></span>
								</p>
								<div class="tr724-ms-actions">
									<button type="button" class="button-link tr724-ms-up"><?php echo esc_html( tr724_editorial_ui( 'move_up' ) ); ?></button>
									<button type="button" class="button-link tr724-ms-down"><?php echo esc_html( tr724_editorial_ui( 'move_down' ) ); ?></button>
									<button type="button" class="button-link-delete tr724-ms-remove"><?php echo esc_html( tr724_editorial_ui( 'remove' ) ); ?></button>
								</div>
							</div>
						</div>
					</li>
				</template>

				<?php submit_button( tr724_editorial_ui( 'save' ) ); ?>
			</form>
		</div>
		<?php
	}
}

if ( ! function_exists( 'tr724_editorial_enqueue_manset_assets' ) ) {
	function tr724_editorial_enqueue_manset_assets(): void {
		$css = get_template_directory() . '/inc/editorial/manset-admin.css';
		$js  = get_template_directory() . '/inc/editorial/manset-admin.js';

		wp_enqueue_style(
			'tr724-editorial-manset',
			get_template_directory_uri() . '/inc/editorial/manset-admin.css',
			[],
			is_readable( $css ) ? (string) filemtime( $css ) : null
		);

		wp_enqueue_script(
			'tr724-editorial-manset',
			get_template_directory_uri() . '/inc/editorial/manset-admin.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			is_readable( $js ) ? (string) filemtime( $js ) : null,
			true
		);

		wp_localize_script(
			'tr724-editorial-manset',
			'tr724Manset',
			[
				'restPosts' => esc_url_raw( rest_url( 'wp/v2/posts' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'max'       => tr724_editorial_manset_max(),
				'i18n'      => [
					'searching' => tr724_editorial_ui( 'searching' ),
					'none'      => tr724_editorial_ui( 'manset_none' ),
					'error'     => tr724_editorial_ui( 'manset_search_error' ),
					'add'       => tr724_editorial_ui( 'add' ),
					'added'     => tr724_editorial_ui( 'added' ),
					'limit'     => sprintf(
						tr724_editorial_ui( 'manset_limit' ),
						tr724_editorial_manset_max()
					),
				],
			]
		);
	}
}
