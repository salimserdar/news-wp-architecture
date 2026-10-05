<?php
/**
 * Editorial → Featured Topics admin screen.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_editorial_featured_topics_admin_rows' ) ) {
	/**
	 * Saved topics that still point at a tag, for the editor list.
	 *
	 * @return array<int, array{id: int, name: string}>
	 */
	function tr724_editorial_featured_topics_admin_rows(): array {
		$rows = [];
		foreach ( tr724_editorial_featured_topics_config() as $item ) {
			$term = get_term( (int) $item['tag_id'], 'post_tag' );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$rows[] = [
				'id'   => (int) $term->term_id,
				'name' => $term->name,
			];
		}
		return $rows;
	}
}

if ( ! function_exists( 'tr724_editorial_render_featured_topic_row' ) ) {
	/**
	 * One selected tag. Search adds rows by cloning #tr724-ft-template.
	 *
	 * @param array{id: int, name: string} $row
	 */
	function tr724_editorial_render_featured_topic_row( array $row ): void {
		$id = (int) $row['id'];
		?>
		<li class="tr724-ft-item" data-tag-id="<?php echo esc_attr( (string) $id ); ?>">
			<input type="hidden" name="tr724_featured_topics[<?php echo esc_attr( (string) $id ); ?>]" value="1">
			<span class="tr724-ft-handle dashicons dashicons-menu" title="<?php echo esc_attr( tr724_editorial_ui( 'drag' ) ); ?>" aria-hidden="true"></span>
			<div class="tr724-ft-item-body">
				<div class="tr724-ft-item-head">
					<p class="tr724-ft-tag">
						<span class="tr724-ft-tag-kicker"><?php echo esc_html( tr724_editorial_ui( 'tag' ) ); ?></span>
						<strong class="tr724-ft-tag-name"><?php echo esc_html( $row['name'] ); ?></strong>
					</p>
					<div class="tr724-ft-actions">
						<button type="button" class="button-link tr724-ft-up"><?php echo esc_html( tr724_editorial_ui( 'move_up' ) ); ?></button>
						<button type="button" class="button-link tr724-ft-down"><?php echo esc_html( tr724_editorial_ui( 'move_down' ) ); ?></button>
						<button type="button" class="button-link-delete tr724-ft-remove"><?php echo esc_html( tr724_editorial_ui( 'remove' ) ); ?></button>
					</div>
				</div>
			</div>
		</li>
		<?php
	}
}

if ( ! function_exists( 'tr724_editorial_render_featured_topics_page' ) ) {
	function tr724_editorial_render_featured_topics_page(): void {
		if ( ! current_user_can( tr724_editorial_capability() ) ) {
			wp_die(
				esc_html( tr724_editorial_ui( 'no_permission' ) ),
				'',
				[ 'response' => 403 ]
			);
		}

		$rows  = tr724_editorial_featured_topics_admin_rows();
		$order = implode( ',', array_map( static fn( array $row ): int => $row['id'], $rows ) );
		?>
		<div class="wrap tr724-ft">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( tr724_editorial_ui( 'saved' ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['dropped'] ) && '1' === $_GET['dropped'] ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php echo esc_html( tr724_editorial_ui( 'dropped' ) ); ?></p>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php echo esc_html( tr724_editorial_ui( 'description' ) ); ?>
				<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=post_tag' ) ); ?>"><?php echo esc_html( tr724_editorial_ui( 'manage_tags' ) ); ?></a>
			</p>
			<noscript>
				<div class="notice notice-warning">
					<p><?php echo esc_html( tr724_editorial_ui( 'js_required' ) ); ?></p>
				</div>
			</noscript>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tr724-ft-form">
				<input type="hidden" name="action" value="tr724_save_featured_topics">
				<?php wp_nonce_field( 'tr724_save_featured_topics' ); ?>
				<input type="hidden" name="tr724_featured_topics_order" id="tr724-ft-order" value="<?php echo esc_attr( $order ); ?>">

				<div class="tr724-ft-add">
					<label for="tr724-ft-search"><?php echo esc_html( tr724_editorial_ui( 'add_tag' ) ); ?></label>
					<input type="search" class="regular-text" id="tr724-ft-search" placeholder="<?php echo esc_attr( tr724_editorial_ui( 'search_tags' ) ); ?>" autocomplete="off">
					<p class="tr724-ft-status" id="tr724-ft-status" aria-live="polite"></p>
					<ul class="tr724-ft-results" id="tr724-ft-results" hidden></ul>
				</div>

				<p class="tr724-ft-empty" id="tr724-ft-empty" <?php echo $rows ? 'hidden' : ''; ?>>
					<?php echo esc_html( tr724_editorial_ui( 'empty' ) ); ?>
				</p>
				<ul class="tr724-ft-list" id="tr724-ft-list">
					<?php foreach ( $rows as $row ) : ?>
						<?php tr724_editorial_render_featured_topic_row( $row ); ?>
					<?php endforeach; ?>
				</ul>

				<template id="tr724-ft-template">
					<li class="tr724-ft-item" data-tag-id="">
						<input type="hidden" value="1">
						<span class="tr724-ft-handle dashicons dashicons-menu" title="<?php echo esc_attr( tr724_editorial_ui( 'drag' ) ); ?>" aria-hidden="true"></span>
						<div class="tr724-ft-item-body">
							<div class="tr724-ft-item-head">
								<p class="tr724-ft-tag">
									<span class="tr724-ft-tag-kicker"><?php echo esc_html( tr724_editorial_ui( 'tag' ) ); ?></span>
									<strong class="tr724-ft-tag-name"></strong>
								</p>
								<div class="tr724-ft-actions">
									<button type="button" class="button-link tr724-ft-up"><?php echo esc_html( tr724_editorial_ui( 'move_up' ) ); ?></button>
									<button type="button" class="button-link tr724-ft-down"><?php echo esc_html( tr724_editorial_ui( 'move_down' ) ); ?></button>
									<button type="button" class="button-link-delete tr724-ft-remove"><?php echo esc_html( tr724_editorial_ui( 'remove' ) ); ?></button>
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

if ( ! function_exists( 'tr724_editorial_enqueue_featured_topics_assets' ) ) {
	function tr724_editorial_enqueue_featured_topics_assets(): void {
		$css = get_template_directory() . '/inc/editorial/featured-topics-admin.css';
		$js  = get_template_directory() . '/inc/editorial/featured-topics-admin.js';

		wp_enqueue_style(
			'tr724-editorial-featured-topics',
			get_template_directory_uri() . '/inc/editorial/featured-topics-admin.css',
			[],
			is_readable( $css ) ? (string) filemtime( $css ) : null
		);

		wp_enqueue_script(
			'tr724-editorial-featured-topics',
			get_template_directory_uri() . '/inc/editorial/featured-topics-admin.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			is_readable( $js ) ? (string) filemtime( $js ) : null,
			true
		);

		wp_localize_script(
			'tr724-editorial-featured-topics',
			'tr724FeaturedTopics',
			[
				'restTags' => esc_url_raw( rest_url( 'wp/v2/tags' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'max'      => tr724_editorial_featured_topics_max(),
				'i18n'     => [
					'searching' => tr724_editorial_ui( 'searching' ),
					'none'      => tr724_editorial_ui( 'none' ),
					'error'     => tr724_editorial_ui( 'search_error' ),
					'add'       => tr724_editorial_ui( 'add' ),
					'added'     => tr724_editorial_ui( 'added' ),
					'limit'     => sprintf(
						tr724_editorial_ui( 'limit' ),
						tr724_editorial_featured_topics_max()
					),
				],
			]
		);
	}
}
