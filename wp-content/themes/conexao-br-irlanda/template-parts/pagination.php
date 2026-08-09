<?php
/**
 * Reusable pagination component.
 *
 * Renders a semantic, accessible, SEO-friendly pagination list using the
 * standard WordPress query pagination data. Works automatically for any
 * paginated archive, taxonomy, search, blog index, or custom query that has
 * pagination available.
 *
 * The component is fully independent of content type, archive slug, taxonomy,
 * or URL. It simply reads the current query's pagination via paginate_links()
 * (type => 'array') and wraps each item in a list item. All styling is scoped
 * under .conexao-pagination in main.css.
 *
 * @package Conexao_BR_Irlanda
 */

// Build the pagination links from the current WordPress query.
$links = paginate_links( array(
	'type'      => 'array',
	'mid_size'  => 2,
	'end_size'  => 1,
	'prev_text' => __( '← Anterior', 'conexao-br-irlanda' ),
	'next_text' => __( 'Próximo →', 'conexao-br-irlanda' ),
) );

// Only render when there is more than one page.
if ( empty( $links ) || count( $links ) < 2 ) {
	return;
}
?>
<nav class="conexao-pagination" aria-label="<?php esc_attr_e( 'Paginação', 'conexao-br-irlanda' ); ?>">
	<ul class="conexao-pagination__list">
		<?php foreach ( $links as $link ) : ?>
			<li class="conexao-pagination__item">
				<?php
				// Output the WordPress-generated markup as-is. It keeps the
				// native crawlable hrefs, .page-numbers classes, and the
				// aria-current="page" marker on the current page.
				echo $link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress-generated markup.
				?>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>