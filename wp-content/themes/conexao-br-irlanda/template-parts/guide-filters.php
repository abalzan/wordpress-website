<?php
/**
 * Guias Archive Filters Template Part
 *
 * Renders the filter bar for the Guias (/guias/) archive — the guide CPT.
 * This is the Guides archive's OWN filter configuration (wired via
 * 'filters' => 'guide' in archive.php); it never renders Event-specific
 * controls (towns, event dates, event categories).
 *
 * Only filters backed by real Guide data are offered:
 *  - Categoria: conexao_category terms actually used by published guides,
 *    via conexao_get_terms_for_post_type() so shared-taxonomy terms used
 *    only by other post types never leak into the Guias filter bar.
 *
 * Filtering is fully server-side and URL driven (?categoria=<slug>) via
 * conexao_content_archive_query(). Markup reuses the shared
 * .events-filter-bar design-system styles (light + dark mode).
 *
 * @package Conexao_BR_Irlanda
 */

$archive_url = conexao_get_guides_archive_url();

// Current filter state from the URL (?categoria=<category slug>).
$current_category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';

// Category terms actually used by published guides (cached).
$category_terms = conexao_get_terms_for_post_type( 'conexao_category', 'guide' );

// No categories with published guides → no data-supported filters.
if ( is_wp_error( $category_terms ) || empty( $category_terms ) ) {
	return;
}
?>
<nav class="events-filter-bar" aria-label="<?php esc_attr_e( 'Filtrar guias por categoria', 'conexao-br-irlanda' ); ?>">
	<span class="events-filter-label"><?php esc_html_e( 'Guias', 'conexao-br-irlanda' ); ?></span>

	<a class="events-filter-link <?php echo empty( $current_category ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( $archive_url ); ?>"<?php echo empty( $current_category ) ? ' aria-current="true"' : ''; ?>>
		<?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?>
	</a>

	<?php foreach ( $category_terms as $term ) : ?>
		<?php
		$url       = add_query_arg( 'categoria', $term->slug, $archive_url );
		$is_active = ( $current_category === $term->slug );
		?>
		<a class="events-filter-link <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $is_active ? ' aria-current="true"' : ''; ?>>
			<?php echo esc_html( $term->name ); ?>
		</a>
	<?php endforeach; ?>
</nav>
