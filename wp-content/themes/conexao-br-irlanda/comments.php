<?php if ( post_password_required() ) { return; } ?>
<div id="comments" class="comments-area">
	<?php if ( have_comments() ) : ?>
		<h2 class="comments-title">
			<?php
			$conexao_comment_count = get_comments_number();
			if ( '1' === $conexao_comment_count ) {
				printf( esc_html__( 'Um comentário em &ldquo;%1$s&rdquo;', 'conexao-br-irlanda' ), '<span>' . wp_kses_post( get_the_title() ) . '</span>' );
			} else {
				printf( esc_html( _n( '%1$s comentário em &ldquo;%2$s&rdquo;', '%1$s comentários em &ldquo;%2$s&rdquo;', $conexao_comment_count, 'conexao-br-irlanda' ) ), number_format_i18n( $conexao_comment_count ), '<span>' . wp_kses_post( get_the_title() ) . '</span>' );
			}
			?>
		</h2>
		<ol class="comment-list">
			<?php wp_list_comments( array( 'style' => 'ol', 'short_ping' => true, 'avatar_size' => 50 ) ); ?>
		</ol>
		<?php the_comments_pagination( array( 'prev_text' => __( '← Anteriores', 'conexao-br-irlanda' ), 'next_text' => __( 'Próximos →', 'conexao-br-irlanda' ) ) ); ?>
	<?php endif; ?>
	<?php if ( ! comments_open() && get_comments_number() && post_type_supports( get_post_type(), 'comments' ) ) : ?>
		<p class="no-comments"><?php esc_html_e( 'Os comentários estão fechados.', 'conexao-br-irlanda' ); ?></p>
	<?php endif; ?>
	<?php comment_form(); ?>
</div>