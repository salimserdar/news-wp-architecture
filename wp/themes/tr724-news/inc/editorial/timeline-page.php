<?php
/**
 * Editorial → Timeline (Yayın Akışı) admin screen.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_editorial_render_timeline_row' ) ) {
	/**
	 * One program. The add button clones #tr724-tl-template, which passes a null index.
	 *
	 * @param array{time?: string, name?: string, url?: string, hidden?: bool} $row
	 */
	function tr724_editorial_render_timeline_row( ?int $index, array $row ): void {
		$time   = isset( $row['time'] ) ? (string) $row['time'] : '';
		$name   = isset( $row['name'] ) ? (string) $row['name'] : '';
		$url    = isset( $row['url'] ) ? (string) $row['url'] : '';
		$hidden = ! empty( $row['hidden'] );

		$field_name = static function ( string $field ) use ( $index ): string {
			if ( null === $index ) {
				return '';
			}
			return 'tr724_timeline_programs[' . $index . '][' . $field . ']';
		};
		?>
		<li class="tr724-tl-item<?php echo $hidden ? ' is-hidden' : ''; ?>">
			<label>
				<span><?php echo esc_html( tr724_editorial_ui( 'timeline_time' ) ); ?></span>
				<input type="time" data-name="time" <?php echo '' !== $field_name( 'time' ) ? 'name="' . esc_attr( $field_name( 'time' ) ) . '"' : ''; ?> value="<?php echo esc_attr( $time ); ?>" step="60" autocomplete="off">
			</label>
			<label>
				<span><?php echo esc_html( tr724_editorial_ui( 'timeline_name' ) ); ?></span>
				<input type="text" class="regular-text" data-name="name" <?php echo '' !== $field_name( 'name' ) ? 'name="' . esc_attr( $field_name( 'name' ) ) . '"' : ''; ?> value="<?php echo esc_attr( $name ); ?>" maxlength="120" autocomplete="off">
			</label>
			<label>
				<span><?php echo esc_html( tr724_editorial_ui( 'timeline_url' ) ); ?></span>
				<input type="text" class="regular-text" data-name="url" inputmode="url" spellcheck="false" <?php echo '' !== $field_name( 'url' ) ? 'name="' . esc_attr( $field_name( 'url' ) ) . '"' : ''; ?> value="<?php echo esc_attr( $url ); ?>" placeholder="<?php echo esc_attr( tr724_editorial_ui( 'timeline_url_placeholder' ) ); ?>" maxlength="300" autocomplete="off">
			</label>
			<label class="tr724-tl-hide">
				<input type="checkbox" data-name="hidden" value="1" <?php checked( $hidden ); ?> <?php echo '' !== $field_name( 'hidden' ) ? 'name="' . esc_attr( $field_name( 'hidden' ) ) . '"' : ''; ?>>
				<span><?php echo esc_html( tr724_editorial_ui( 'timeline_hide' ) ); ?></span>
			</label>
			<button type="button" class="button-link-delete tr724-tl-remove"><?php echo esc_html( tr724_editorial_ui( 'remove' ) ); ?></button>
		</li>
		<?php
	}
}

if ( ! function_exists( 'tr724_editorial_render_timeline_page' ) ) {
	function tr724_editorial_render_timeline_page(): void {
		if ( ! current_user_can( tr724_editorial_capability() ) ) {
			wp_die(
				esc_html( tr724_editorial_ui( 'no_permission' ) ),
				'',
				[ 'response' => 403 ]
			);
		}

		$rows = tr724_editorial_timeline_config();
		?>
		<div class="wrap tr724-tl">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( tr724_editorial_ui( 'timeline_saved' ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['dropped'] ) && '1' === $_GET['dropped'] ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php echo esc_html( tr724_editorial_ui( 'timeline_dropped' ) ); ?></p>
				</div>
			<?php endif; ?>
			<p class="description"><?php echo esc_html( tr724_editorial_ui( 'timeline_description' ) ); ?></p>
			<p class="description"><?php echo esc_html( tr724_editorial_ui( 'timeline_order' ) ); ?></p>
			<p class="description"><?php echo esc_html( tr724_editorial_ui( 'timeline_hide_help' ) ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tr724-tl-form" autocomplete="off">
				<input type="hidden" name="action" value="tr724_save_timeline">
				<?php wp_nonce_field( 'tr724_save_timeline' ); ?>

				<p class="tr724-tl-empty" id="tr724-tl-empty" <?php echo $rows ? 'hidden' : ''; ?>>
					<?php echo esc_html( tr724_editorial_ui( 'timeline_empty' ) ); ?>
				</p>
				<ul class="tr724-tl-list" id="tr724-tl-list">
					<?php foreach ( $rows as $index => $row ) : ?>
						<?php tr724_editorial_render_timeline_row( (int) $index, $row ); ?>
					<?php endforeach; ?>
				</ul>

				<template id="tr724-tl-template">
					<?php tr724_editorial_render_timeline_row( null, [] ); ?>
				</template>

				<p>
					<button type="button" class="button" id="tr724-tl-add"><?php echo esc_html( tr724_editorial_ui( 'timeline_add' ) ); ?></button>
				</p>

				<?php submit_button( tr724_editorial_ui( 'save' ) ); ?>
			</form>
		</div>
		<?php
	}
}

if ( ! function_exists( 'tr724_editorial_enqueue_timeline_assets' ) ) {
	function tr724_editorial_enqueue_timeline_assets(): void {
		$css = get_template_directory() . '/inc/editorial/timeline-admin.css';
		$js  = get_template_directory() . '/inc/editorial/timeline-admin.js';

		wp_enqueue_style(
			'tr724-editorial-timeline',
			get_template_directory_uri() . '/inc/editorial/timeline-admin.css',
			[],
			is_readable( $css ) ? (string) filemtime( $css ) : null
		);

		wp_enqueue_script(
			'tr724-editorial-timeline',
			get_template_directory_uri() . '/inc/editorial/timeline-admin.js',
			[],
			is_readable( $js ) ? (string) filemtime( $js ) : null,
			true
		);

		wp_localize_script(
			'tr724-editorial-timeline',
			'tr724Timeline',
			[
				'max'  => tr724_editorial_timeline_max(),
				'i18n' => [
					'limit' => sprintf(
						tr724_editorial_ui( 'timeline_limit' ),
						tr724_editorial_timeline_max()
					),
				],
			]
		);
	}
}
