<?php
/**
 * Latest posts in the popular box. One or more categories, no rank numbers,
 * and an optional more link at the bottom of the box.
 *
 * @var array    $attributes
 * @var string   $content
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

$count = isset( $attributes['postsToShow'] ) ? (int) $attributes['postsToShow'] : 8;
$count = max( 1, min( 24, $count ) );

$ids  = [];
$seen = [];
$raw  = $attributes['categoryIds'] ?? [];
if ( is_array( $raw ) ) {
	foreach ( $raw as $id ) {
		$id = (int) $id;
		if ( $id > 0 && ! isset( $seen[ $id ] ) ) {
			$seen[ $id ] = true;
			$ids[]       = $id;
		}
	}
}

$categories = [];
if ( $ids ) {
	$found = get_terms(
		[
			'taxonomy'   => 'category',
			'include'    => $ids,
			'hide_empty' => false,
			'orderby'    => 'include',
		]
	);
	if ( $found && ! is_wp_error( $found ) ) {
		$categories = $found;
	}
}

$term_missing = $ids && ! $categories;

$query_args = [
	'post_type'           => 'post',
	'post_status'         => 'publish',
	'posts_per_page'      => $count,
	'ignore_sticky_posts' => true,
	'no_found_rows'       => true,
];

if ( $categories ) {
	$query_args['tax_query'] = [
		[
			'taxonomy' => 'category',
			'field'    => 'term_id',
			'terms'    => array_map( 'intval', wp_list_pluck( $categories, 'term_id' ) ),
			'operator' => 'IN',
		],
	];
}

$query = $term_missing ? null : new WP_Query( $query_args );

$heading = isset( $attributes['heading'] ) ? trim( (string) $attributes['heading'] ) : '';
if ( '' === $heading && 1 === count( $categories ) ) {
	$heading = $categories[0]->name;
}
if ( '' === $heading ) {
	$heading = __( 'HABERLER', 'tr724-news' );
}
if ( function_exists( 'tr724_archive_upper' ) ) {
	$heading = tr724_archive_upper( $heading );
}

$show_more  = ! array_key_exists( 'showMore', $attributes ) || (bool) $attributes['showMore'];
$more_label = isset( $attributes['moreLabel'] ) ? trim( (string) $attributes['moreLabel'] ) : '';
if ( '' === $more_label ) {
	$more_label = __( 'Daha Fazla', 'tr724-news' );
}

$more_url = isset( $attributes['moreUrl'] ) ? trim( (string) $attributes['moreUrl'] ) : '';
if ( '' === $more_url && 1 === count( $categories ) ) {
	$term_link = get_term_link( $categories[0] );
	if ( ! is_wp_error( $term_link ) ) {
		$more_url = $term_link;
	}
}
if ( '' === $more_url ) {
	$posts_page_id = (int) get_option( 'page_for_posts' );
	$more_url      = $posts_page_id > 0 ? (string) get_permalink( $posts_page_id ) : home_url( '/' );
}

$title_id = wp_unique_id( 'post-list-title-' );

echo '<section ' . get_block_wrapper_attributes(
	[
		'class'           => 'post-list',
		'aria-labelledby' => $title_id,
	]
) . '>';
echo '<div class="post-list__box">';
echo '<div class="post-list__head">';
echo '<h2 class="post-list__title" id="' . esc_attr( $title_id ) . '">' . esc_html( $heading ) . '</h2>';
echo '</div>';

if ( ! $query || ! $query->have_posts() ) {
	echo '<p class="post-list__empty">' . esc_html__( 'No posts found.', 'tr724-news' ) . '</p>';
} else {
	echo '<ul class="post-list__list">';
	while ( $query->have_posts() ) {
		$query->the_post();
		$post_id   = get_the_ID();
		$author_id = (int) get_post_field( 'post_author', $post_id );
		$author    = get_the_author_meta( 'display_name', $author_id );
		if ( '' === $author ) {
			$author = get_the_author_meta( 'user_login', $author_id );
		}
		echo '<li class="post-list__item">';
		echo '<a href="' . esc_url( get_permalink( $post_id ) ) . '">';
		echo '<span class="post-list__copy">';
		if ( '' !== $author ) {
			echo '<span class="post-list__author">' . esc_html( $author ) . '</span>';
		}
		echo '<span class="post-list__headline">' . esc_html( get_the_title( $post_id ) ) . '</span>';
		echo '</span></a></li>';
	}
	echo '</ul>';
	wp_reset_postdata();
}

if ( $show_more && '' !== $more_url ) {
	echo '<a class="outline-btn outline-btn--wide" href="' . esc_url( $more_url ) . '">';
	echo esc_html( $more_label ) . ' <span aria-hidden="true">→</span>';
	echo '</a>';
}

echo '</div></section>';
