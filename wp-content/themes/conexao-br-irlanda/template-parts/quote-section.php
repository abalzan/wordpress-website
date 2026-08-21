<?php
/**
 * Reusable Quote Section
 *
 * A motivational closing quote that appears at the bottom of the homepage
 * and all major listing pages, providing a consistent site-wide closing element.
 *
 * Uses the same HTML structure, classes, content, and responsive behaviour
 * as the original front-page.php implementation — extracted into a template
 * part so every page reuses one definition instead of duplicating the markup.
 *
 * @package Conexao_BR_Irlanda
 */
?>

<section class="quote-section">
	<div class="site-container">
		<div class="quote-content">
			<blockquote class="quote-text">"<?php esc_html_e( 'A vida é uma constante oportunidade de recomeçar. Cada dia é uma nova chance de construir algo melhor.', 'conexao-br-irlanda' ); ?>"</blockquote>
			<p class="quote-author">— <?php esc_html_e( 'Mário Sérgio Cortella', 'conexao-br-irlanda' ); ?></p>
		</div>
	</div>
</section>
