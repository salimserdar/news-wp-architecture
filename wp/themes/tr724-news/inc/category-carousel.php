<?php
/**
 * Category archive carousel. Each category can show the latest posts
 * above the story cards on page 1. Later pages stay a card list.
 */

defined( 'ABSPATH' ) || exit;

const TR724_CATEGORY_CAROUSEL_DEFAULT = 5;
const TR724_CATEGORY_CAROUSEL_MAX     = 15;

if ( ! function_exists( 'tr724_category_carousel_enabled' ) ) {
	function tr724_category_carousel_enabled( int $term_id ): bool {
		return '1' === (string) get_term_meta( $term_id, 'tr724_show_carousel', true );
	}
}

if ( ! function_exists( 'tr724_category_carousel_count' ) ) {
	function tr724_category_carousel_count( int $term_id ): int {
		$count = (int) get_term_meta( $term_id, 'tr724_carousel_count', true );
		if ( $count < 1 ) {
			$count = TR724_CATEGORY_CAROUSEL_DEFAULT;
		}
		return min( TR724_CATEGORY_CAROUSEL_MAX, $count );
	}
}

if ( ! function_exists( 'tr724_category_carousel_post_ids' ) ) {
	/**
	 * Latest post IDs reserved for the carousel. Empty when the category has it off.
	 *
	 * @return array<int, int>
	 */
	function tr724_category_carousel_post_ids( int $term_id ): array {
		static $cache = [];

		if ( $term_id <= 0 ) {
			return [];
		}
		if ( array_key_exists( $term_id, $cache ) ) {
			return $cache[ $term_id ];
		}
		if ( ! tr724_category_carousel_enabled( $term_id ) ) {
			$cache[ $term_id ] = [];
			return [];
		}

		$ids = get_posts(
			[
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => tr724_category_carousel_count( $term_id ),
				'cat'                 => $term_id,
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'orderby'             => 'date',
				'order'               => 'DESC',
			]
		);

		$cache[ $term_id ] = array_map( 'intval', $ids );
		return $cache[ $term_id ];
	}
}

if ( ! function_exists( 'tr724_category_carousel_should_show' ) ) {
	/**
	 * Page 1 of a category that has the carousel on and at least one post.
	 */
	function tr724_category_carousel_should_show( ?WP_Term $term ): bool {
		if ( ! $term instanceof WP_Term || 'category' !== $term->taxonomy ) {
			return false;
		}
		if ( max( 1, (int) get_query_var( 'paged' ) ) > 1 ) {
			return false;
		}
		return (bool) tr724_category_carousel_post_ids( (int) $term->term_id );
	}
}

add_action( 'init', static function (): void {
	$auth = static function (): bool {
		return current_user_can( 'manage_categories' );
	};

	register_term_meta(
		'category',
		'tr724_show_carousel',
		[
			'type'              => 'string',
			'single'            => true,
			'show_in_rest'      => false,
			'auth_callback'     => $auth,
			'sanitize_callback' => static function ( mixed $value ): string {
				return ( '1' === (string) $value || true === $value ) ? '1' : '0';
			},
		]
	);

	register_term_meta(
		'category',
		'tr724_carousel_count',
		[
			'type'              => 'integer',
			'single'            => true,
			'show_in_rest'      => false,
			'auth_callback'     => $auth,
			'sanitize_callback' => static function ( mixed $value ): int {
				$count = absint( $value );
				if ( $count < 1 ) {
					return TR724_CATEGORY_CAROUSEL_DEFAULT;
				}
				return min( TR724_CATEGORY_CAROUSEL_MAX, $count );
			},
		]
	);
} );

if ( ! function_exists( 'tr724_category_carousel_fields' ) ) {
	/**
	 * Shared inputs for the add and edit category screens.
	 */
	function tr724_category_carousel_fields( int $term_id ): void {
		$enabled = $term_id > 0 && tr724_category_carousel_enabled( $term_id );
		$count   = $term_id > 0 ? tr724_category_carousel_count( $term_id ) : TR724_CATEGORY_CAROUSEL_DEFAULT;
		wp_nonce_field( 'tr724_category_carousel', 'tr724_category_carousel_nonce' );
		?>
		<label>
			<input type="checkbox" name="tr724_show_carousel" value="1" <?php checked( $enabled ); ?>>
			<?php esc_html_e( 'Show carousel', 'tr724-news' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Shows the latest posts above the story cards on the first page of this category.', 'tr724-news' ); ?>
		</p>
		<p>
			<label for="tr724-carousel-count"><?php esc_html_e( 'Posts in carousel', 'tr724-news' ); ?></label>
			<input
				type="number"
				name="tr724_carousel_count"
				id="tr724-carousel-count"
				min="1"
				max="<?php echo esc_attr( (string) TR724_CATEGORY_CAROUSEL_MAX ); ?>"
				step="1"
				value="<?php echo esc_attr( (string) $count ); ?>"
			>
		</p>
		<?php
	}
}

add_action( 'category_add_form_fields', static function (): void {
	echo '<div class="form-field">';
	tr724_category_carousel_fields( 0 );
	echo '</div>';
} );

add_action( 'category_edit_form_fields', static function ( WP_Term $term ): void {
	echo '<tr class="form-field"><th scope="row">';
	esc_html_e( 'News carousel', 'tr724-news' );
	echo '</th><td>';
	tr724_category_carousel_fields( (int) $term->term_id );
	echo '</td></tr>';
} );

add_action( 'created_category', 'tr724_category_carousel_save' );
add_action( 'edited_category', 'tr724_category_carousel_save' );

if ( ! function_exists( 'tr724_category_carousel_save' ) ) {
	function tr724_category_carousel_save( int $term_id ): void {
		if ( ! isset( $_POST['tr724_category_carousel_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['tr724_category_carousel_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'tr724_category_carousel' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$show = isset( $_POST['tr724_show_carousel'] ) && '1' === (string) wp_unslash( $_POST['tr724_show_carousel'] );
		if ( $show ) {
			update_term_meta( $term_id, 'tr724_show_carousel', '1' );
		} else {
			delete_term_meta( $term_id, 'tr724_show_carousel' );
		}

		$raw   = isset( $_POST['tr724_carousel_count'] ) ? wp_unslash( $_POST['tr724_carousel_count'] ) : TR724_CATEGORY_CAROUSEL_DEFAULT;
		$count = absint( $raw );
		if ( $count < 1 ) {
			$count = TR724_CATEGORY_CAROUSEL_DEFAULT;
		}
		update_term_meta( $term_id, 'tr724_carousel_count', min( TR724_CATEGORY_CAROUSEL_MAX, $count ) );
	}
}

add_action( 'pre_get_posts', static function ( WP_Query $query ): void {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_category() ) {
		return;
	}

	$term = $query->get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return;
	}

	$ids = tr724_category_carousel_post_ids( (int) $term->term_id );
	if ( ! $ids ) {
		return;
	}

	$existing = $query->get( 'post__not_in' );
	if ( ! is_array( $existing ) ) {
		$existing = [];
	}
	$query->set(
		'post__not_in',
		array_values( array_unique( array_merge( array_map( 'intval', $existing ), $ids ) ) )
	);
} );

if ( ! function_exists( 'tr724_render_category_carousel' ) ) {
	function tr724_render_category_carousel( WP_Term $term ): void {
		if ( ! tr724_category_carousel_should_show( $term ) ) {
			return;
		}

		$ids = tr724_category_carousel_post_ids( (int) $term->term_id );
		if ( ! $ids ) {
			return;
		}

		$query = new WP_Query(
			[
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'post__in'            => $ids,
				'orderby'             => 'post__in',
				'posts_per_page'      => count( $ids ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			]
		);

		if ( ! $query->have_posts() ) {
			wp_reset_postdata();
			return;
		}

		update_post_thumbnail_cache( $query );

		$extra_ids = [];
		foreach ( $query->posts as $carousel_post ) {
			$extra_id = tr724_additional_image_id( (int) $carousel_post->ID );
			if ( $extra_id > 0 ) {
				$extra_ids[] = $extra_id;
			}
		}
		if ( $extra_ids ) {
			_prime_post_caches( $extra_ids, false, true );
		}

		$prev_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 6.5 9 12l5.5 5.5" /></svg>';
		$next_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 6.5 15 12l-5.5 5.5" /></svg>';

		echo '<section class="category-carousel" data-tr724-category-carousel="1" aria-label="' . esc_attr( $term->name ) . '">';
		echo '<div class="swiper category-carousel__swiper">';
		echo '<div class="swiper-wrapper">';

		$index = 0;
		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id = (int) get_the_ID();

			$thumb_id = tr724_additional_image_id( $post_id );
			if ( $thumb_id <= 0 ) {
				$thumb_id = (int) get_post_thumbnail_id( $post_id );
			}
			$extra_title = tr724_additional_title( $post_id );
			$upper_title = tr724_upper_title( $post_id );
			$slide_title = '' !== $extra_title ? $extra_title : get_the_title( $post_id );
			$hide_title  = tr724_hide_title( $post_id );
			$slide_label = ( ! $hide_title && '' !== $upper_title ) ? $upper_title . ' ' . $slide_title : $slide_title;
			$image       = '';
			if ( $thumb_id ) {
				$image = wp_get_attachment_image(
					$thumb_id,
					'large',
					false,
					[
						'alt'           => $slide_title,
						'loading'       => 0 === $index ? 'eager' : 'lazy',
						'fetchpriority' => 0 === $index ? 'high' : 'low',
						'decoding'      => 'async',
					]
				);
			}

			echo '<div class="swiper-slide">';
			echo '<a class="category-carousel__slide" href="' . esc_url( get_permalink( $post_id ) ) . '" aria-label="' . esc_attr( $slide_label ) . '">';
			echo $image;
			if ( ! $hide_title ) {
				echo '<span class="category-carousel__shade" aria-hidden="true"></span>';
				echo '<span class="category-carousel__copy">';
				if ( '' !== $upper_title ) {
					echo '<span class="category-carousel__eyebrow">' . esc_html( $upper_title ) . '</span>';
				}
				echo '<h2 class="category-carousel__title">' . esc_html( $slide_title ) . '</h2>';
				echo '</span>';
			}
			echo '</a></div>';
			++$index;
		}

		echo '</div>';
		echo '<button class="category-carousel__nav category-carousel__prev" type="button" aria-label="' . esc_attr__( 'Önceki haber', 'tr724-news' ) . '">' . $prev_icon . '</button>';
		echo '<button class="category-carousel__nav category-carousel__next" type="button" aria-label="' . esc_attr__( 'Sonraki haber', 'tr724-news' ) . '">' . $next_icon . '</button>';
		echo '</div>';
		echo '<div class="category-carousel__pager"></div>';
		echo '</section>';

		wp_reset_postdata();
	}
}
