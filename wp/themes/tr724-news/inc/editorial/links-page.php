<?php
/**
 * Editorial → Links admin screen.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'tr724_editorial_render_link_field' ) ) {
	/**
	 * One URL or text field.
	 */
	function tr724_editorial_render_link_field( string $key, string $label, string $value, string $type = 'url', string $help = '' ): void {
		$id = 'tr724-link-' . $key;
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
			</th>
			<td>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					class="regular-text"
					id="<?php echo esc_attr( $id ); ?>"
					name="tr724_links[<?php echo esc_attr( $key ); ?>]"
					value="<?php echo esc_attr( $value ); ?>"
					<?php if ( 'url' === $type ) : ?>
						inputmode="url" spellcheck="false"
					<?php endif; ?>
					maxlength="<?php echo esc_attr( 'url' === $type ? '500' : '40' ); ?>"
					autocomplete="off"
				>
				<?php if ( '' !== $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}

if ( ! function_exists( 'tr724_editorial_render_links_page' ) ) {
	function tr724_editorial_render_links_page(): void {
		if ( ! current_user_can( tr724_editorial_links_capability() ) ) {
			wp_die(
				esc_html( tr724_editorial_ui( 'no_permission' ) ),
				'',
				[ 'response' => 403 ]
			);
		}

		$links = tr724_editorial_get_links();
		$value = static function ( string $key ) use ( $links ): string {
			return isset( $links[ $key ] ) ? (string) $links[ $key ] : '';
		};
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( tr724_editorial_ui( 'links_saved' ) ); ?></p>
				</div>
			<?php endif; ?>
			<p class="description"><?php echo esc_html( tr724_editorial_ui( 'links_description' ) ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="tr724_save_links">
				<?php wp_nonce_field( 'tr724_save_links' ); ?>

				<h2><?php echo esc_html( tr724_editorial_ui( 'links_social' ) ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					tr724_editorial_render_link_field( 'facebook', tr724_editorial_ui( 'links_facebook' ), $value( 'facebook' ) );
					tr724_editorial_render_link_field( 'x', tr724_editorial_ui( 'links_x' ), $value( 'x' ) );
					tr724_editorial_render_link_field( 'instagram', tr724_editorial_ui( 'links_instagram' ), $value( 'instagram' ) );
					tr724_editorial_render_link_field( 'youtube', tr724_editorial_ui( 'links_youtube' ), $value( 'youtube' ) );
					?>
				</table>

				<h2><?php echo esc_html( tr724_editorial_ui( 'links_patreon' ) ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					tr724_editorial_render_link_field( 'patreon', tr724_editorial_ui( 'links_patreon_url' ), $value( 'patreon' ) );
					?>
				</table>

				<h2><?php echo esc_html( tr724_editorial_ui( 'links_whatsapp' ) ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					tr724_editorial_render_link_field(
						'whatsapp_phone',
						tr724_editorial_ui( 'links_whatsapp_phone' ),
						$value( 'whatsapp_phone' ),
						'text'
					);
					tr724_editorial_render_link_field(
						'whatsapp_url',
						tr724_editorial_ui( 'links_whatsapp_url' ),
						$value( 'whatsapp_url' ),
						'url',
						tr724_editorial_ui( 'links_whatsapp_url_help' )
					);
					tr724_editorial_render_link_field(
						'whatsapp_channel',
						tr724_editorial_ui( 'links_whatsapp_channel' ),
						$value( 'whatsapp_channel' )
					);
					?>
				</table>

				<h2><?php echo esc_html( tr724_editorial_ui( 'links_apps' ) ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					tr724_editorial_render_link_field( 'ios', tr724_editorial_ui( 'links_ios' ), $value( 'ios' ) );
					tr724_editorial_render_link_field( 'android', tr724_editorial_ui( 'links_android' ), $value( 'android' ) );
					?>
				</table>

				<?php submit_button( tr724_editorial_ui( 'save' ) ); ?>
			</form>
		</div>
		<?php
	}
}
