<section class="no-results not-found">
	<header class="page-header">
		<h1 class="page-title"><?php esc_html_e( 'Nada encontrado', 'conexao-br-irlanda' ); ?></h1>
	</header>
	<div class="page-content">
		<?php if ( is_home() && current_user_can( 'publish_posts' ) ) : ?>
			<p><?php printf( wp_kses( __( 'Pronto para publicar seu primeiro post? <a href="%s">Comece aqui</a>.', 'conexao-br-irlanda' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( admin_url( 'post-new.php' ) ) ); ?></p>
		<?php elseif ( is_search() ) : ?>
			<p><?php esc_html_e( 'Desculpe, mas nada corresponde aos seus termos de busca. Tente novamente com palavras-chave diferentes.', 'conexao-br-irlanda' ); ?></p>
			<button type="button" class="btn btn-outline-dark conexao-noresult-search-toggle"><?php esc_html_e( 'Nova busca', 'conexao-br-irlanda' ); ?></button>
		<?php else : ?>
			<?php if ( is_post_type_archive( 'guide' ) ) : ?>
				<p><?php esc_html_e( 'Nenhum guia publicado ainda.', 'conexao-br-irlanda' ); ?></p>
				<p><?php esc_html_e( 'Novos guias práticos serão publicados aqui em breve.', 'conexao-br-irlanda' ); ?></p>
			<?php elseif ( is_post_type_archive( 'event' ) ) : ?>
				<p><?php esc_html_e( 'Nenhum evento publicado ainda.', 'conexao-br-irlanda' ); ?></p>
				<p><?php esc_html_e( 'Novos eventos da comunidade serão publicados aqui em breve.', 'conexao-br-irlanda' ); ?></p>
			<?php elseif ( is_post_type_archive( 'job' ) ) : ?>
				<p><?php esc_html_e( 'Nenhuma vaga de emprego publicada ainda.', 'conexao-br-irlanda' ); ?></p>
				<p><?php esc_html_e( 'Novas oportunidades serão publicadas aqui em breve.', 'conexao-br-irlanda' ); ?></p>
			<?php elseif ( is_post_type_archive( 'sponsor' ) ) : ?>
				<p><?php esc_html_e( 'Nenhum apoiador cadastrado ainda.', 'conexao-br-irlanda' ); ?></p>
				<p><?php esc_html_e( 'Em breve, novos negócios estarão apoiando nossa comunidade.', 'conexao-br-irlanda' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Parece que não conseguimos encontrar o que você procura. Talvez a busca possa ajudar.', 'conexao-br-irlanda' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</section>
