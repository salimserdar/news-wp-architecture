<?php
/**
 * Author grid from wp/html-them/yazarlar.html (.yazarlar).
 * One card per user in the selected role. Defaults to Author.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$section = isset( $attributes['sectionTitle'] ) ? trim( (string) $attributes['sectionTitle'] ) : '';
if ( '' === $section ) {
	$section = __( 'TÜM YAZARLARI', 'tr724-news' );
}

$columns = isset( $attributes['columns'] ) ? (int) $attributes['columns'] : 4;
$columns = max( 1, min( 6, $columns ) );

$role = isset( $attributes['role'] ) ? sanitize_key( (string) $attributes['role'] ) : 'author';
if ( '' === $role || ! get_role( $role ) ) {
	$role = 'author';
}

$authors = get_users(
	[
		'role'    => $role,
		'orderby' => 'display_name',
		'order'   => 'ASC',
	]
);

$title_id = wp_unique_id( 'yazarlar-title-' );

echo '<div ' . get_block_wrapper_attributes(
	[
		'class'           => 'yazarlar',
		'aria-labelledby' => $title_id,
		'style'           => '--yazarlar-columns:' . $columns,
	]
) . '>';
echo '<h2 class="section-title writers__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $section ) . '</h2>';

if ( ! $authors ) {
	echo '<p class="writers__empty">' . esc_html__( 'Yazar bulunamadı.', 'tr724-news' ) . '</p>';
	echo '</div>';
	return;
}

echo '<div class="writers__grid">';
foreach ( $authors as $author ) {
	$user_id = (int) $author->ID;
	$name    = $author->display_name;
	if ( '' === $name ) {
		$name = $author->user_login;
	}
	$url   = get_author_posts_url( $user_id );
	$email = $author->user_email;

	echo '<article class="writer">';
	echo '<a class="writer__avatar" href="' . esc_url( $url ) . '">' . tr724_yazarlar_avatar( $user_id ) . '</a>';
	echo '<div>';
	echo '<a class="writer__name" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
	if ( '' !== $email ) {
		echo '<a class="writer__mail" href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
	}
	echo tr724_yazarlar_social( $user_id );
	echo '</div></article>';
}
echo '</div></div>';
