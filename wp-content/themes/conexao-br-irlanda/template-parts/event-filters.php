<?php
/**
 * Event Filters Template Part
 *
 * Renders the filter bar for the Events archive page.
 * Includes location filters (from conexao_town taxonomy) and category filters
 * (from conexao_category taxonomy, e.g., "Treinamento").
 *
 * @package Conexao_BR_Irlanda
 */

// Get all towns from the taxonomy.
$town_terms = get_terms(
	array(
		'taxonomy'   => 'conexao_town',
		'hide_empty' => true,
		'orderby'    => 'name',
		'order'      => 'ASC',
	)
);

// Get all event categories from the taxonomy.
$category_terms = get_terms(
	array(
		'taxonomy'   => 'conexao_category',
		'hide_empty' => true,
		'orderby'    => 'name',
		'order'      => 'ASC',
	)
);

$current_town     = isset( $_GET['cidade'] ) ? sanitize_text_field( wp_unslash( $_GET['cidade'] ) ) : '';
$current_category = isset( $_GET['categoria'] ) ? sanitize_text_field( wp_unslash( $_GET['categoria'] ) ) : '';

// Check if we have any categories to display.
$has_categories = ! is_wp_error( $category_terms ) && ! empty( $category_terms );
$has_towns      = ! is_wp_error( $town_terms ) && ! empty( $town_terms );
?>

<?php if ( $has_towns || $has_categories ) : ?>
	<div class="events-filter-bar">
		<span class="events-filter-label"><?php esc_html_e( 'Eventos', 'conexao-br-irlanda' ); ?></span>

		<a class="events-filter-link <?php echo ( empty( $current_town ) && empty( $current_category ) ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( get_post_type_archive_link( 'event' ) ); ?>">
			<?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?>
		</a>

		<?php if ( $has_towns ) : ?>
			<?php foreach ( $town_terms as $town ) : ?>
				<?php
				$town_slug = $town->slug;
				$url       = add_query_arg( 'cidade', $town_slug, get_post_type_archive_link( 'event' ) );
				// Remove category filter when switching towns.
				$url       = remove_query_arg( 'categoria', $url );
				$is_active = ( $current_town === $town_slug ) && empty( $current_category );
				?>
				<a class="events-filter-link <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
					<?php echo esc_html( $town->name ); ?>
				</a>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( $has_categories ) : ?>
			<?php foreach ( $category_terms as $category ) : ?>
				<?php
				$category_slug = $category->slug;
				$url           = add_query_arg( 'categoria', $category_slug, get_post_type_archive_link( 'event' ) );
				// Remove town filter when switching categories.
				$url           = remove_query_arg( 'cidade', $url );
				$is_active     = ( $current_category === $category_slug );
				?>
				<a class="events-filter-link <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
					<?php echo esc_html( $category->name ); ?>
				</a>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
<?php endif; ?>
