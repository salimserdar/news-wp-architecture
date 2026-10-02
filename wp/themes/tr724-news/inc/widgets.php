<?php
/**
 * Sidebars for single posts and category archives, plus the Ads widget.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'widgets_init', static function (): void {
	$sidebar = [
		'before_widget' => '<section id="%1$s" class="widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h2 class="widget__title">',
		'after_title'   => '</h2>',
	];

	register_sidebar(
		array_merge(
			$sidebar,
			[
				'name'        => __( 'Single post', 'tr724-news' ),
				'id'          => 'single-post',
				'description' => __( 'Shown beside the article on single posts.', 'tr724-news' ),
			]
		)
	);

	register_sidebar(
		array_merge(
			$sidebar,
			[
				'name'        => __( 'Category', 'tr724-news' ),
				'id'          => 'category',
				'description' => __( 'Shown beside the story grid on category archives.', 'tr724-news' ),
			]
		)
	);

	register_widget( 'TR724_Ads_Widget' );
} );

/**
 * Image ad, Google AdSense, or custom ad code. Same fields as the category block.
 */
class TR724_Ads_Widget extends WP_Widget {
	public function __construct() {
		parent::__construct(
			'tr724_ads',
			__( 'Ads', 'tr724-news' ),
			[
				'description'                 => __( 'Static image, Google AdSense, or custom ad code.', 'tr724-news' ),
				'classname'                   => 'widget_tr724_ads',
				'customize_selective_refresh' => true,
			]
		);

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
	}

	/**
	 * @param string $hook Current admin screen.
	 */
	public function enqueue_admin( string $hook ): void {
		if ( ! in_array( $hook, [ 'widgets.php', 'customize.php' ], true ) ) {
			return;
		}

		wp_enqueue_media();

		$path = get_template_directory() . '/assets/js/ads-widget.js';
		wp_enqueue_script(
			'tr724-ads-widget',
			get_template_directory_uri() . '/assets/js/ads-widget.js',
			[ 'media-editor' ],
			is_readable( $path ) ? (string) filemtime( $path ) : null,
			true
		);
	}

	/**
	 * @param array $args     Sidebar wrapper tags.
	 * @param array $instance Saved settings.
	 */
	public function widget( $args, $instance ): void {
		$markup = tr724_category_ad_markup( is_array( $instance ) ? $instance : [] );
		if ( '' === $markup ) {
			return;
		}

		echo $args['before_widget'];
		echo $markup;
		echo $args['after_widget'];
	}

	/**
	 * @param array $new_instance Values from the form.
	 * @param array $old_instance Previously saved values.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		$mode = isset( $new_instance['adMode'] ) ? (string) $new_instance['adMode'] : 'none';
		if ( ! in_array( $mode, [ 'none', 'image', 'google', 'html' ], true ) ) {
			$mode = 'none';
		}

		$format = isset( $new_instance['adFormat'] ) ? (string) $new_instance['adFormat'] : 'auto';
		if ( ! in_array( $format, [ 'auto', 'vertical', 'rectangle', 'horizontal' ], true ) ) {
			$format = 'auto';
		}

		$html = isset( $new_instance['adHtml'] ) ? (string) $new_instance['adHtml'] : '';
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$html = wp_kses_post( $html );
		}

		return [
			'adMode'    => $mode,
			'adImageId' => isset( $new_instance['adImageId'] ) ? absint( $new_instance['adImageId'] ) : 0,
			'adUrl'     => isset( $new_instance['adUrl'] ) ? esc_url_raw( $new_instance['adUrl'] ) : '',
			'adLabel'   => isset( $new_instance['adLabel'] ) ? sanitize_text_field( $new_instance['adLabel'] ) : '',
			'adClient'  => isset( $new_instance['adClient'] ) ? sanitize_text_field( $new_instance['adClient'] ) : '',
			'adSlot'    => isset( $new_instance['adSlot'] ) ? sanitize_text_field( $new_instance['adSlot'] ) : '',
			'adFormat'  => $format,
			'adHtml'    => $html,
		];
	}

	/**
	 * @param array $instance Saved settings.
	 */
	public function form( $instance ): void {
		$instance = wp_parse_args(
			is_array( $instance ) ? $instance : [],
			[
				'adMode'    => 'none',
				'adImageId' => 0,
				'adUrl'     => '',
				'adLabel'   => 'reklam',
				'adClient'  => '',
				'adSlot'    => '',
				'adFormat'  => 'auto',
				'adHtml'    => '',
			]
		);

		$mode     = (string) $instance['adMode'];
		$image_id = (int) $instance['adImageId'];
		$preview  = $image_id > 0 ? wp_get_attachment_image(
			$image_id,
			'medium',
			false,
			[
				'alt'   => '',
				'style' => 'display:block;max-width:100%;height:auto',
			]
		) : '';
		?>
		<div
			class="tr724-ads-widget"
			data-media-title="<?php echo esc_attr__( 'Select image', 'tr724-news' ); ?>"
			data-media-button="<?php echo esc_attr__( 'Use image', 'tr724-news' ); ?>"
		>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'adMode' ) ); ?>"><?php esc_html_e( 'Ad', 'tr724-news' ); ?></label>
				<select
					class="widefat tr724-ads-mode"
					id="<?php echo esc_attr( $this->get_field_id( 'adMode' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'adMode' ) ); ?>"
				>
					<option value="none" <?php selected( $mode, 'none' ); ?>><?php esc_html_e( 'None', 'tr724-news' ); ?></option>
					<option value="image" <?php selected( $mode, 'image' ); ?>><?php esc_html_e( 'Image', 'tr724-news' ); ?></option>
					<option value="google" <?php selected( $mode, 'google' ); ?>><?php esc_html_e( 'Google AdSense', 'tr724-news' ); ?></option>
					<option value="html" <?php selected( $mode, 'html' ); ?>><?php esc_html_e( 'Custom code', 'tr724-news' ); ?></option>
				</select>
			</p>

			<div data-tr724-ad-mode="image" <?php echo 'image' === $mode ? '' : 'hidden'; ?>>
				<p>
					<input
						class="tr724-ads-image-id"
						type="hidden"
						id="<?php echo esc_attr( $this->get_field_id( 'adImageId' ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( 'adImageId' ) ); ?>"
						value="<?php echo esc_attr( (string) $image_id ); ?>"
					/>
					<button
						type="button"
						class="button tr724-ads-select"
						data-select="<?php echo esc_attr__( 'Select image', 'tr724-news' ); ?>"
						data-replace="<?php echo esc_attr__( 'Replace image', 'tr724-news' ); ?>"
					>
						<?php echo $image_id > 0 ? esc_html__( 'Replace image', 'tr724-news' ) : esc_html__( 'Select image', 'tr724-news' ); ?>
					</button>
					<button type="button" class="button-link tr724-ads-remove" <?php echo $image_id > 0 ? '' : 'hidden'; ?>>
						<?php esc_html_e( 'Remove image', 'tr724-news' ); ?>
					</button>
				</p>
				<div class="tr724-ads-preview"><?php echo $preview; ?></div>
				<p>
					<label for="<?php echo esc_attr( $this->get_field_id( 'adUrl' ) ); ?>"><?php esc_html_e( 'Link', 'tr724-news' ); ?></label>
					<input
						class="widefat"
						type="url"
						id="<?php echo esc_attr( $this->get_field_id( 'adUrl' ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( 'adUrl' ) ); ?>"
						value="<?php echo esc_attr( (string) $instance['adUrl'] ); ?>"
					/>
					<span class="description"><?php esc_html_e( 'Where the image ad goes. Leave empty to show the image without a link.', 'tr724-news' ); ?></span>
				</p>
			</div>

			<div data-tr724-ad-mode="google" <?php echo 'google' === $mode ? '' : 'hidden'; ?>>
				<p>
					<label for="<?php echo esc_attr( $this->get_field_id( 'adClient' ) ); ?>"><?php esc_html_e( 'Publisher ID', 'tr724-news' ); ?></label>
					<input
						class="widefat"
						type="text"
						id="<?php echo esc_attr( $this->get_field_id( 'adClient' ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( 'adClient' ) ); ?>"
						value="<?php echo esc_attr( (string) $instance['adClient'] ); ?>"
					/>
					<span class="description"><?php esc_html_e( 'AdSense client id, such as ca-pub-1234567890123456.', 'tr724-news' ); ?></span>
				</p>
				<p>
					<label for="<?php echo esc_attr( $this->get_field_id( 'adSlot' ) ); ?>"><?php esc_html_e( 'Ad slot', 'tr724-news' ); ?></label>
					<input
						class="widefat"
						type="text"
						id="<?php echo esc_attr( $this->get_field_id( 'adSlot' ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( 'adSlot' ) ); ?>"
						value="<?php echo esc_attr( (string) $instance['adSlot'] ); ?>"
					/>
					<span class="description"><?php esc_html_e( 'The data-ad-slot number from the AdSense unit.', 'tr724-news' ); ?></span>
				</p>
				<p>
					<label for="<?php echo esc_attr( $this->get_field_id( 'adFormat' ) ); ?>"><?php esc_html_e( 'Format', 'tr724-news' ); ?></label>
					<select
						class="widefat"
						id="<?php echo esc_attr( $this->get_field_id( 'adFormat' ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( 'adFormat' ) ); ?>"
					>
						<option value="auto" <?php selected( (string) $instance['adFormat'], 'auto' ); ?>><?php esc_html_e( 'Auto', 'tr724-news' ); ?></option>
						<option value="vertical" <?php selected( (string) $instance['adFormat'], 'vertical' ); ?>><?php esc_html_e( 'Vertical', 'tr724-news' ); ?></option>
						<option value="rectangle" <?php selected( (string) $instance['adFormat'], 'rectangle' ); ?>><?php esc_html_e( 'Rectangle', 'tr724-news' ); ?></option>
						<option value="horizontal" <?php selected( (string) $instance['adFormat'], 'horizontal' ); ?>><?php esc_html_e( 'Horizontal', 'tr724-news' ); ?></option>
					</select>
				</p>
			</div>

			<div data-tr724-ad-mode="html" <?php echo 'html' === $mode ? '' : 'hidden'; ?>>
				<p>
					<label for="<?php echo esc_attr( $this->get_field_id( 'adHtml' ) ); ?>"><?php esc_html_e( 'Ad code', 'tr724-news' ); ?></label>
					<textarea
						class="widefat"
						rows="8"
						id="<?php echo esc_attr( $this->get_field_id( 'adHtml' ) ); ?>"
						name="<?php echo esc_attr( $this->get_field_name( 'adHtml' ) ); ?>"
					><?php echo esc_textarea( (string) $instance['adHtml'] ); ?></textarea>
					<span class="description"><?php esc_html_e( 'Paste a Google ad snippet or any other ad markup. Saving script tags requires a user who can post unfiltered HTML.', 'tr724-news' ); ?></span>
				</p>
			</div>

			<p data-tr724-ad-label <?php echo 'none' === $mode ? 'hidden' : ''; ?>>
				<label for="<?php echo esc_attr( $this->get_field_id( 'adLabel' ) ); ?>"><?php esc_html_e( 'Label', 'tr724-news' ); ?></label>
				<input
					class="widefat"
					type="text"
					id="<?php echo esc_attr( $this->get_field_id( 'adLabel' ) ); ?>"
					name="<?php echo esc_attr( $this->get_field_name( 'adLabel' ) ); ?>"
					value="<?php echo esc_attr( (string) $instance['adLabel'] ); ?>"
				/>
				<span class="description"><?php esc_html_e( 'Small label under the ad. Leave empty to hide it.', 'tr724-news' ); ?></span>
			</p>
		</div>
		<?php
	}
}
