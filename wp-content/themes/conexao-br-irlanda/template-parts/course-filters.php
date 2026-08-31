<?php
/**
 * Cursos Archive Filters Template Part
 *
 * Renders the filter bar for the Cursos (/cursos/) archive — the
 * course_provider CPT. This is the Courses archive's OWN filter
 * configuration (wired via 'filters' => 'course' in archive.php); it never
 * renders Event-specific controls (towns, event dates, event categories).
 *
 * Only filters backed by real Course data are offered:
 *  - Categoria: the _provider_category post meta of published course
 *    providers, discovered dynamically via conexao_get_provider_categories()
 *    so empty/irrelevant categories never appear.
 *
 * Location is intentionally NOT filterable: _provider_location is a
 * free-text display field with no dedicated query support. When the Course
 * data model gains a structured, consistently populated field, a filter can
 * be added here without touching the Event configuration.
 *
 * Filtering is fully server-side and URL driven (?categoria=<slug>) via
 * conexao_content_archive_query(). Filtered URLs keep working on refresh,
 * back/forward and sharing; the "Carregar mais" pagination on /cursos/
 * reads its next-page URLs from the same main query, so filters are
 * preserved across pages.
 *
 * Markup reuses the shared .events-filter-bar / .providers-filter-bar
 * design-system styles (light + dark mode) — no page-specific CSS.
 *
 * @package Conexao_BR_Irlanda
 */

$archive_url = get_post_type_archive_link( 'course_provider' );
if ( ! $archive_url ) {
	$archive_url = home_url( '/cursos/' );
}

// Current filter state from the URL (?categoria=<provider category slug>).
$current_category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';

// Provider categories actually used by published course providers (cached).
$provider_categories = conexao_get_provider_categories();

// No categories with published providers → no data-supported filters.
// Render nothing rather than an empty or invented filter bar.
if ( empty( $provider_categories ) ) {
	return;
}
?>
<nav class="events-filter-bar providers-filter-bar" aria-label="<?php esc_attr_e( 'Filtrar cursos por categoria', 'conexao-br-irlanda' ); ?>">
	<span class="events-filter-label"><?php esc_html_e( 'Cursos', 'conexao-br-irlanda' ); ?></span>

	<a class="events-filter-link <?php echo empty( $current_category ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( $archive_url ); ?>"<?php echo empty( $current_category ) ? ' aria-current="true"' : ''; ?>>
		<?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?>
	</a>

	<?php foreach ( $provider_categories as $cat ) : ?>
		<?php
		$url       = add_query_arg( 'categoria', $cat['slug'], $archive_url );
		$is_active = ( $current_category === $cat['slug'] );
		?>
		<a class="events-filter-link <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $is_active ? ' aria-current="true"' : ''; ?>>
			<?php echo esc_html( $cat['name'] ); ?>
		</a>
	<?php endforeach; ?>
</nav>
