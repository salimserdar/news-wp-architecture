<?php
/**
 * Authors rail. One row per user with the Author role, showing that user's latest post.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$count = isset( $attributes['postsToShow'] ) ? (int) $attributes['postsToShow'] : 8;
$count = max( 1, min( 30, $count ) );

$heading = isset( $attributes['heading'] ) ? trim( (string) $attributes['heading'] ) : '';
if ( '' === $heading ) {
	$heading = __( 'YAZARLAR', 'tr724-news' );
}

$heading_url  = isset( $attributes['headingUrl'] ) ? trim( (string) $attributes['headingUrl'] ) : '';
$heading_html = esc_html( $heading );
if ( '' !== $heading_url ) {
	$heading_html = '<a class="authors__title-link" href="' . esc_url( $heading_url ) . '">' . $heading_html . '</a>';
}

$author_ids = get_users(
	[
		'role'    => 'author',
		'fields'  => 'ID',
		'orderby' => 'display_name',
		'order'   => 'ASC',
	]
);
$author_ids = array_values( array_filter( array_map( 'absint', (array) $author_ids ) ) );

$post_ids = [];
if ( $author_ids ) {
	global $wpdb;

	$placeholders = implode( ',', array_fill( 0, count( $author_ids ), '%d' ) );
	$sql          = "SELECT MAX(p.ID) AS ID
		FROM {$wpdb->posts} p
		INNER JOIN (
			SELECT post_author, MAX(post_date) AS max_date
			FROM {$wpdb->posts}
			WHERE post_type = 'post'
				AND post_status = 'publish'
				AND post_author IN ($placeholders)
			GROUP BY post_author
		) latest ON p.post_author = latest.post_author AND p.post_date = latest.max_date
		WHERE p.post_type = 'post'
			AND p.post_status = 'publish'
		GROUP BY p.post_author
		ORDER BY MAX(latest.max_date) DESC
		LIMIT %d";

	$post_ids = array_map(
		'absint',
		$wpdb->get_col( $wpdb->prepare( $sql, array_merge( $author_ids, [ $count ] ) ) )
	);
	$post_ids = array_values( array_filter( $post_ids ) );
}

if ( ! $post_ids ) {
	echo '<section ' . get_block_wrapper_attributes(
		[
			'class' => 'authors authors--empty',
		]
	) . '>';
	echo '<h2 class="authors__title">' . $heading_html . '</h2>';
	echo '<p class="authors__empty">' . esc_html__( 'Yazar yazısı bulunamadı.', 'tr724-news' ) . '</p>';
	echo '</section>';
	return;
}

$query = new WP_Query(
	[
		'post_type'           => 'post',
		'post_status'         => 'publish',
		'post__in'            => $post_ids,
		'orderby'             => 'post__in',
		'posts_per_page'      => count( $post_ids ),
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
	]
);

if ( ! $query->have_posts() ) {
	echo '<section ' . get_block_wrapper_attributes(
		[
			'class' => 'authors authors--empty',
		]
	) . '>';
	echo '<h2 class="authors__title">' . $heading_html . '</h2>';
	echo '<p class="authors__empty">' . esc_html__( 'Yazar yazısı bulunamadı.', 'tr724-news' ) . '</p>';
	echo '</section>';
	wp_reset_postdata();
	return;
}

$title_id  = wp_unique_id( 'authors-title-' );
$down_icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>';
$up_icon   = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6" /></svg>';

echo '<section ' . get_block_wrapper_attributes(
	[
		'class'              => 'authors',
		'data-tr724-authors' => '1',
		'aria-labelledby'    => $title_id,
	]
) . '>';
echo '<h2 class="authors__title" id="' . esc_attr( $title_id ) . '">' . $heading_html . '</h2>';
echo '<div class="authors__viewport">';
echo '<ul class="authors__list">';

while ( $query->have_posts() ) {
	$query->the_post();
	$post_id   = get_the_ID();
	$author_id = (int) get_post_field( 'post_author', $post_id );
	$name      = get_the_author_meta( 'display_name', $author_id );
	if ( '' === $name ) {
		$name = get_the_author_meta( 'user_login', $author_id );
	}

	$attachment_id = (int) get_user_meta( $author_id, 'wp_user_avatar', true );
	if ( $attachment_id <= 0 ) {
		$attachment_id = (int) get_user_meta( $author_id, 'yazar_photo', true );
	}

	$avatar = '';
	if ( $attachment_id > 0 ) {
		$avatar = wp_get_attachment_image(
			$attachment_id,
			'thumbnail',
			false,
			[
				'alt'      => '',
				'loading'  => 'lazy',
				'decoding' => 'async',
			]
		);
	}
	if ( '' === $avatar ) {
		$avatar = get_avatar(
			$author_id,
			96,
			'',
			'',
			[
				'loading'  => 'lazy',
				'decoding' => 'async',
			]
		);
	}

	echo '<li>';
	echo '<a class="author" href="' . esc_url( get_permalink( $post_id ) ) . '">';
	echo '<span class="author__avatar">' . $avatar . '</span>';
	echo '<span class="author__copy">';
	echo '<span class="author__name">' . esc_html( $name ) . '</span>';
	echo '<span class="author__title">' . esc_html( get_the_title( $post_id ) ) . '</span>';
	echo '</span></a></li>';
}

echo '</ul></div>';
echo '<div class="authors__controls">';
echo '<button class="authors__down" type="button" aria-label="' . esc_attr__( 'Sonraki yazarlar', 'tr724-news' ) . '">' . $down_icon . '</button>';
echo '<button class="authors__up" type="button" aria-label="' . esc_attr__( 'Önceki yazarlar', 'tr724-news' ) . '">' . $up_icon . '</button>';
echo '</div></section>';

wp_reset_postdata();
