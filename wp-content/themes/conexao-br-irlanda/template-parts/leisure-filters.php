<?php
/**
 * Lazer Archive Filters Template Part
 *
 * Renders the county and category filter bars for the /lazer/ directory.
 * Users can combine a county and a category filter (e.g. Wicklow + Natureza).
 *
 * County filters use the `conexao_county` taxonomy via the `?county=` query arg
 * and category filters use the `conexao_category` taxonomy via `?categoria=`.
 * Because both are taxonomy-based and share the `conexao_category` taxonomy
 * with other content types, they are restricted to terms actually used by
 * published leisure locations so irrelevant filters never appear.
 *
 * @package Conexao_BR_Irlanda
 */

$archive_url = get_post_type_archive_link( 'leisure' );
if ( ! $archive_url ) {
	$archive_url = home_url( '/lazer/' );
}

// Current filter state from the URL.
$current_county   = isset( $_GET['county'] ) ? sanitize_title( wp_unslash( $_GET['county'] ) ) : '';
$current_category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';

// Collect counties and categories actually used by leisure locations.
$county_terms = conexao_get_terms_for_post_type( 'conexao_county', 'leisure' );
$category_terms = conexao_get_terms_for_post_type( 'conexao_category', 'leisure' );

$has_filters = ( ! is_wp_error( $county_terms ) && ! empty( $county_terms ) )
	|| ( ! is_wp_error( $category_terms ) && ! empty( $category_terms ) );

if ( ! $has_filters ) {
	return;
}
?>

<?php if ( ! is_wp_error( $county_terms ) && ! empty( $county_terms ) ) : ?>
	<div class="events-filter-bar leisure-filter-bar">
		<span class="events-filter-label"><?php esc_html_e( 'Condado', 'conexao-br-irlanda' ); ?></span>

		<a class="events-filter-link <?php echo empty( $current_county ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( $current_category ? add_query_arg( 'categoria', $current_category, $archive_url ) : $archive_url ); ?>">
			<?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?>
		</a>

		<?php foreach ( $county_terms as $term ) : ?>
			<?php
			$url = add_query_arg( 'county', $term->slug, $archive_url );
			// Preserve the category filter when switching counties.
			if ( $current_category ) {
				$url = add_query_arg( 'categoria', $current_category, $url );
			}
			$is_active = ( $current_county === $term->slug );
			?>
			<a class="events-filter-link <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $term->name ); ?>
			</a>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

<?php if ( ! is_wp_error( $category_terms ) && ! empty( $category_terms ) ) : ?>
	<div class="events-filter-bar leisure-filter-bar leisure-filter-bar--category">
		<span class="events-filter-label"><?php esc_html_e( 'Tipo', 'conexao-br-irlanda' ); ?></span>

		<a class="events-filter-link <?php echo empty( $current_category ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( $current_county ? add_query_arg( 'county', $current_county, $archive_url ) : $archive_url ); ?>">
			<?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?>
		</a>

		<?php foreach ( $category_terms as $term ) : ?>
			<?php
			$url = add_query_arg( 'categoria', $term->slug, $archive_url );
			// Preserve the county filter when switching categories.
			if ( $current_county ) {
				$url = add_query_arg( 'county', $current_county, $url );
			}
			$is_active = ( $current_category === $term->slug );
			?>
			<a class="events-filter-link <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $term->name ); ?>
			</a>
		<?php endforeach; ?>
	</div>
<?php endif; ?>