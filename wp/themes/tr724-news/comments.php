<?php
/**
 * Comments on a single post. Name and email only; no website field.
 */

defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
	return;
}

$commenter = wp_get_current_commenter();
$req       = (bool) get_option( 'require_name_email' );
$html_req  = $req ? ' required' : '';

$fields = [
	'author' => sprintf(
		'<p class="comment-form-author"><label for="author">%1$s</label><input id="author" name="author" type="text" value="%2$s" maxlength="245" autocomplete="name"%3$s></p>',
		esc_html__( 'Ad', 'tr724-news' ),
		esc_attr( $commenter['comment_author'] ),
		$html_req
	),
	'email'  => sprintf(
		'<p class="comment-form-email"><label for="email">%1$s</label><input id="email" name="email" type="email" value="%2$s" maxlength="100" autocomplete="email"%3$s></p>',
		esc_html__( 'Email', 'tr724-news' ),
		esc_attr( $commenter['comment_author_email'] ),
		$html_req
	),
	// Core adds the cookie checkbox back unless this key is present.
	'cookies' => '',
];
?>
<section class="post__comments" id="comments">
	<?php if ( have_comments() ) : ?>
		<ol class="post__comment-list">
			<?php
			wp_list_comments(
				[
					'style'       => 'ol',
					'short_ping'  => true,
					'avatar_size' => 0,
				]
			);
			?>
		</ol>
	<?php endif; ?>

	<?php
	comment_form(
		[
			'title_reply'          => __( 'Yorum Yap', 'tr724-news' ),
			'title_reply_to'       => __( '%s için yanıt', 'tr724-news' ),
			'title_reply_before'   => '<h2 id="reply-title" class="post__comments-title">',
			'title_reply_after'    => '</h2>',
			'cancel_reply_before'  => '',
			'cancel_reply_after'   => '',
			'cancel_reply_link'    => __( 'Vazgeç', 'tr724-news' ),
			'comment_notes_before' => '',
			'comment_notes_after'  => '',
			'fields'               => $fields,
			'comment_field'        => '<p class="comment-form-comment"><label class="screen-reader-text" for="comment">' . esc_html__( 'Yorum', 'tr724-news' ) . '</label><textarea id="comment" name="comment" cols="45" rows="5" maxlength="65525" required></textarea></p>',
			'class_container'      => 'post__comment-respond',
			'class_form'           => 'post__comment-form',
			'class_submit'         => 'post__comment-submit',
			'name_submit'          => 'submit',
			'label_submit'         => __( 'Gönder', 'tr724-news' ),
			'submit_button'        => '<button name="%1$s" type="submit" id="%2$s" class="%3$s">%4$s <span aria-hidden="true">→</span></button>',
			'submit_field'         => '<p class="form-submit">%1$s %2$s</p>',
			'logged_in_as'         => '',
		]
	);
	?>
</section>
