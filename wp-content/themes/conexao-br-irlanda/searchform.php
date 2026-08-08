<?php
/**
 * Custom search form template.
 *
 * Renders a consistent, design-system-styled search form whenever
 * get_search_form() is called (e.g. 404 page).
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$conexao_search_query = get_search_query();
?>
<form role="search" method="get" class="search-form conexao-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="screen-reader-text" for="conexao-search-field"><?php esc_html_e( 'Pesquisar', 'conexao-br-irlanda' ); ?></label>
	<div class="conexao-search-wrap">
		<svg class="conexao-search-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
			<circle cx="11" cy="11" r="8"></circle>
			<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
		</svg>
		<input id="conexao-search-field" type="search" class="search-field" placeholder="<?php esc_attr_e( 'Buscar...', 'conexao-br-irlanda' ); ?>" value="<?php echo esc_attr( $conexao_search_query ); ?>" name="s" />
		<button type="submit" class="search-submit"><?php esc_html_e( 'Buscar', 'conexao-br-irlanda' ); ?></button>
	</div>
</form>