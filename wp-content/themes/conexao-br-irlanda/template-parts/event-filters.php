<?php
/**
 * Event Filters Template Part
 *
 * Renders the location filter bar for the Events archive page.
 * Options are generated dynamically from the conexao_town taxonomy.
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

$current_town = isset( $_GET['cidade'] ) ? sanitize_text_field( wp_unslash( $_GET['cidade'] ) ) : '';
?>

<?php if ( ! is_wp_error( $town_terms ) && ! empty( $town_terms ) ) : ?>
	<div class="events-filter-bar">
		<span class="events-filter-label"><?php esc_html_e( 'Eventos', 'conexao-br-irlanda' ); ?></span>

		<a class="events-filter-link <?php echo empty( $current_town ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( get_post_type_archive_link( 'event' ) ); ?>">
			<?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?>
		</a>

		<?php foreach ( $town_terms as $town ) : ?>
			<?php
			$town_slug = $town->slug;
			$url       = add_query_arg( 'cidade', $town_slug, get_post_type_archive_link( 'event' ) );
			$is_active = ( $current_town === $town_slug );
			?>
			<a class="events-filter-link <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<?php echo esc_html( $town->name ); ?>
			</a>
		<?php endforeach; ?>
	</div>
<?php endif; ?>