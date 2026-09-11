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
				<?php
				// Filtered empty state: when an Eventos filter (?county= /
				// ?cidade= / ?categoria=) is active and the query found
				// nothing, use the shared .event-filters-empty treatment
				// (dashed panel + "Limpar filtros" action) from the filter
				// widget, instead of the "nothing published yet" copy.
				$ev_active = ( isset( $_GET['county'] ) && sanitize_title( wp_unslash( $_GET['county'] ) ) !== '' )
					|| ( isset( $_GET['cidade'] ) && sanitize_title( wp_unslash( $_GET['cidade'] ) ) !== '' )
					|| ( isset( $_GET['categoria'] ) && sanitize_title( wp_unslash( $_GET['categoria'] ) ) !== '' );
				if ( $ev_active ) :
					?>
					<div class="event-filters-empty">
						<p class="event-filters-empty-text"><?php esc_html_e( 'Nenhum evento encontrado com esses filtros.', 'conexao-br-irlanda' ); ?></p>
						<a class="event-filters-empty-clear" href="<?php echo esc_url( get_post_type_archive_link( 'event' ) ); ?>"><?php esc_html_e( 'Limpar filtros', 'conexao-br-irlanda' ); ?></a>
					</div>
				<?php else : ?>
					<p><?php esc_html_e( 'Nenhum evento publicado ainda.', 'conexao-br-irlanda' ); ?></p>
					<p><?php esc_html_e( 'Novos eventos da comunidade serão publicados aqui em breve.', 'conexao-br-irlanda' ); ?></p>
				<?php endif; ?>
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
