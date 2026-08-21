<?php
/**
 * Reusable Newsletter ("Sem Spam") Section
 *
 * Shared site-wide section that appears at the bottom of the homepage and
 * all major listing pages (archives, static pages, blog). The description
 * text "Sem spam" (Portuguese for "without spam") gives this section its
 * internal name — the "spam section".
 *
 * Uses the same HTML structure, classes, content, and responsive behaviour
 * as the original front-page.php implementation — extracted into a template
 * part so every page reuses one definition instead of duplicating the markup.
 *
 * @package Conexao_BR_Irlanda
 */
?>

<section class="newsletter-section">
	<div class="site-container">
		<div class="newsletter-content">
			<div class="newsletter-text">
				<h2 class="newsletter-title"><?php esc_html_e( 'Fique por dentro de tudo!', 'conexao-br-irlanda' ); ?></h2>
				<p class="newsletter-description"><?php esc_html_e( 'Receba as últimas notícias, eventos e guias práticos diretamente no seu email. Sem spam, apenas conteúdo relevante para brasileiros na Irlanda.', 'conexao-br-irlanda' ); ?></p>
			</div>
			<div class="newsletter-form">
				<h3 class="newsletter-form-title"><?php esc_html_e( 'Assine nossa newsletter', 'conexao-br-irlanda' ); ?></h3>
				<p class="newsletter-form-subtitle"><?php esc_html_e( 'Junte-se a milhares de brasileiros que já recebem nossas atualizações.', 'conexao-br-irlanda' ); ?></p>
				<form class="newsletter-input-group" action="#" method="post">
					<input type="email" class="newsletter-input" placeholder="<?php esc_attr_e( 'Seu melhor email', 'conexao-br-irlanda' ); ?>" required>
					<button type="submit" class="newsletter-btn"><?php esc_html_e( 'Assinar', 'conexao-br-irlanda' ); ?></button>
				</form>
				<p class="newsletter-privacy"><?php esc_html_e( 'Ao assinar, você concorda com nossa política de privacidade.', 'conexao-br-irlanda' ); ?></p>
			</div>
		</div>
	</div>
</section>
