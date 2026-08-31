<?php
/**
 * Eventos Archive Filters Template Part
 *
 * Renders the filter bar for the Eventos (/eventos/) archive — the event
 * CPT ONLY. This is the Events archive's own filter configuration (wired
 * via 'filters' => 'event' in archive.php). Other archives have their own
 * filter templates: Cursos → course-filters.php, Guias → guide-filters.php,
 * Lazer → leisure-filters.php — so event-specific controls (towns, event
 * categories) can never leak into another archive's filter bar.
 *
 * The filter list is dynamically determined from data actually used by
 * published events:
 *  - Localização: conexao_town terms (event-only taxonomy) via ?cidade=.
 *  - Categoria:   conexao_category terms used by events via ?categoria=.
 *
 * @package Conexao_BR_Irlanda
 */

$archive_url  = get_post_type_archive_link( 'event' );
$filter_label = __( 'Eventos', 'conexao-br-irlanda' );

// Collect filter items as arrays of [name, slug, param].
// The 'param' key indicates which query argument the filter uses:
//  'cidade'    for event town filters,
//  'categoria' for event category filters.
$filters = array();

// Town filters (event-only taxonomy).
$town_terms = conexao_get_terms_for_post_type( 'conexao_town', 'event' );

if ( ! is_wp_error( $town_terms ) ) {
	foreach ( $town_terms as $term ) {
		$filters[] = array(
			'name'  => $term->name,
			'slug'  => $term->slug,
			'param' => 'cidade',
		);
	}
}

// Category filters (shared taxonomy, restricted to events).
$category_terms = conexao_get_terms_for_post_type( 'conexao_category', 'event' );

if ( ! is_wp_error( $category_terms ) ) {
	foreach ( $category_terms as $term ) {
		$filters[] = array(
			'name'  => $term->name,
			'slug'  => $term->slug,
			'param' => 'categoria',
		);
	}
}

// Current filter state from the URL.
$current_town     = isset( $_GET['cidade'] ) ? sanitize_text_field( wp_unslash( $_GET['cidade'] ) ) : '';
$current_category = isset( $_GET['categoria'] ) ? sanitize_text_field( wp_unslash( $_GET['categoria'] ) ) : '';

$has_filters = ! empty( $filters );
?>

<?php if ( $has_filters ) : ?>
	<div class="events-filter-bar">
		<span class="events-filter-label"><?php echo esc_html( $filter_label ); ?></span>

		<a class="events-filter-link <?php echo ( empty( $current_town ) && empty( $current_category ) ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( $archive_url ); ?>">
			<?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?>
		</a>

		<?php foreach ( $filters as $filter ) : ?>
			<?php
			$url = add_query_arg( $filter['param'], $filter['slug'], $archive_url );

			// Events can combine cidade and categoria; switching one filter
			// removes the other so a single active filter remains.
			$other_param = ( 'cidade' === $filter['param'] ) ? 'categoria' : 'cidade';
			$url         = remove_query_arg( $other_param, $url );

			if ( 'cidade' === $filter['param'] ) {
				$is_active = ( $current_town === $filter['slug'] ) && empty( $current_category );
			} else {
				$is_active = ( $current_category === $filter['slug'] );
			}
			?>
			<a class="events-filter-link <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $filter['name'] ); ?>
			</a>
		<?php endforeach; ?>
	</div>
<?php endif; ?>
