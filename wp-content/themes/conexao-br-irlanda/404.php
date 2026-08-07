<?php get_header(); ?>
<div class="site-container">
	<main id="primary" class="content-area">
		<section class="error-404">
			<h1 class="error-code">404</h1>
			<h2><?php esc_html_e( 'Página não encontrada', 'conexao-br-irlanda' ); ?></h2>
			<p><?php esc_html_e( 'Desculpe, mas a página que você procura não existe. Talvez você possa encontrar o que procura em uma das páginas abaixo.', 'conexao-br-irlanda' ); ?></p>
			<?php get_search_form(); ?>
			<div class="cta-buttons" style="margin-top: 30px;">
				<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="btn btn-green"><?php esc_html_e( 'Voltar para o início', 'conexao-br-irlanda' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/blog/' ) ); ?>" class="btn btn-primary"><?php esc_html_e( 'Ver o blog', 'conexao-br-irlanda' ); ?></a>
			</div>
		</section>
	</main>
</div>
<?php get_footer();