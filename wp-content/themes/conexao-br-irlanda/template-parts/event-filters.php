<?php
/**
 * Archive Filters Template Part
 *
 * Renders the filter bar for the Eventos, Cursos and Guias archive pages.
 *
 * The filter list is dynamically determined per content type:
 *  - Eventos: conexao_town terms + conexao_category terms used by Events.
 *  - Cursos:  _provider_category meta values from published course providers.
 *  - Guias:   conexao_category terms used by Guides.
 *
 * This ensures each archive only shows filters that are relevant to its own
 * content type — shared taxonomies never leak categories from one post type
 * into another's filter bar.
 *
 * @package Conexao_BR_Irlanda
 */

// Determine the current content type from the main query.
$current_post_type = get_query_var( 'post_type' );

if ( is_array( $current_post_type ) ) {
	$current_post_type = reset( $current_post_type );
}

// Resolve the archive base URL and filter label for the current content type.
if ( 'event' === $current_post_type ) {
	$archive_url  = get_post_type_archive_link( 'event' );
	$filter_label = __( 'Eventos', 'conexao-br-irlanda' );
} elseif ( 'course_provider' === $current_post_type ) {
	$archive_url  = get_post_type_archive_link( 'course_provider' );
	$filter_label = __( 'Cursos', 'conexao-br-irlanda' );
} else {
	// Guides (or any other CPT that uses conexao_category).
	$archive_url  = conexao_get_guides_archive_url();
	$filter_label = __( 'Guias', 'conexao-br-irlanda' );
}

// Collect filter items as arrays of [name, slug, param].
// The 'param' key indicates which query argument the filter uses:
//  'cidade'    for event town filters,
//  'categoria' for event/guide category filters and course provider filters.
$filters = array();

if ( 'event' === $current_post_type ) {
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
} elseif ( 'course_provider' === $current_post_type ) {
	// Provider categories (meta-based, not taxonomy).
	$provider_categories = conexao_get_provider_categories();

	foreach ( $provider_categories as $cat ) {
		$filters[] = array(
			'name'  => $cat['name'],
			'slug'  => $cat['slug'],
			'param' => 'categoria',
		);
	}
} else {
	// Guides: category filters (shared taxonomy, restricted to guides).
	$category_terms = conexao_get_terms_for_post_type( 'conexao_category', 'guide' );

	if ( ! is_wp_error( $category_terms ) ) {
		foreach ( $category_terms as $term ) {
			$filters[] = array(
				'name'  => $term->name,
				'slug'  => $term->slug,
				'param' => 'categoria',
			);
		}
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

			// Remove the other filter param when switching (events only have
			// both cidade and categoria; for courses/guides this is a no-op).
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