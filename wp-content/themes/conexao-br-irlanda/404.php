<?php get_header(); ?>
<div class="site-container">
	<main id="primary" class="content-area">
		<section class="error-404">
			<h1 class="error-code">404</h1>
			<h2><?php esc_html_e( 'Página não encontrada', 'conexao-br-irlanda' ); ?></h2>
			<p><?php esc_html_e( 'Desculpe, mas a página que você procura não existe. Ela pode ter sido movida ou removida.', 'conexao-br-irlanda' ); ?></p>

			<div class="error-404-search">
				<h3><?php esc_html_e( 'Tente buscar pelo que precisa:', 'conexao-br-irlanda' ); ?></h3>
				<?php get_search_form(); ?>
			</div>

			<div class="error-404-links" style="margin-top: 30px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: center;">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="btn btn-green"><?php esc_html_e( 'Voltar para o início', 'conexao-br-irlanda' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/noticias/' ) ); ?>" class="btn btn-primary"><?php esc_html_e( 'Últimas Notícias', 'conexao-br-irlanda' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/guias/' ) ); ?>" class="btn btn-primary"><?php esc_html_e( 'Guias Práticos', 'conexao-br-irlanda' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/eventos/' ) ); ?>" class="btn btn-primary"><?php esc_html_e( 'Eventos', 'conexao-br-irlanda' ); ?></a>
			</div>

			<div class="error-404-popular" style="margin-top: 40px;">
				<h3><?php esc_html_e( 'Guias Populares', 'conexao-br-irlanda' ); ?></h3>
				<ul style="list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: 10px; justify-content: center;">
					<li><a href="<?php echo esc_url( home_url( '/guias-praticos/pps-number/' ) ); ?>"><?php esc_html_e( 'PPS Number', 'conexao-br-irlanda' ); ?></a></li>
					<li><a href="<?php echo esc_url( home_url( '/guias-praticos/medical-card/' ) ); ?>"><?php esc_html_e( 'Medical Card', 'conexao-br-irlanda' ); ?></a></li>
					<li><a href="<?php echo esc_url( home_url( '/guias-praticos/abrir-conta-bancaria/' ) ); ?>"><?php esc_html_e( 'Abrir Conta Bancária', 'conexao-br-irlanda' ); ?></a></li>
					<li><a href="<?php echo esc_url( home_url( '/guias-praticos/alugar-casa/' ) ); ?>"><?php esc_html_e( 'Alugar Casa', 'conexao-br-irlanda' ); ?></a></li>
					<li><a href="<?php echo esc_url( home_url( '/guias-praticos/carteira-de-motorista/' ) ); ?>"><?php esc_html_e( 'Carteira de Motorista', 'conexao-br-irlanda' ); ?></a></li>
				</ul>
			</div>
		</section>
	</main>
</div>
<?php get_footer();