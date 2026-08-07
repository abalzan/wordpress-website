<section class="no-results not-found">
	<header class="page-header">
		<h1 class="page-title"><?php esc_html_e( 'Nada encontrado', 'conexao-br-irlanda' ); ?></h1>
	</header>
	<div class="page-content">
		<?php if ( is_home() && current_user_can( 'publish_posts' ) ) : ?>
			<p><?php printf( wp_kses( __( 'Pronto para publicar seu primeiro post? <a href="%s">Comece aqui</a>.', 'conexao-br-irlanda' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( admin_url( 'post-new.php' ) ) ); ?></p>
		<?php elseif ( is_search() ) : ?>
			<p><?php esc_html_e( 'Desculpe, mas nada corresponde aos seus termos de busca. Tente novamente com palavras-chave diferentes.', 'conexao-br-irlanda' ); ?></p>
			<?php get_search_form(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Parece que não conseguimos encontrar o que você procura. Talvez a busca possa ajudar.', 'conexao-br-irlanda' ); ?></p>
			<?php get_search_form(); ?>
		<?php endif; ?>
	</div>
</section>