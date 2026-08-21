<?php
/**
 * Lazer Archive Filters Template Part
 *
 * Renders the /lazer/ filtering UI as a single, lightweight component.
 *
 * Desktop: a compact toolbar with two grouped dropdown triggers ("Condado"
 * and "Tipo") and a "Limpar filtros" action that only appears while a filter
 * is active. Each dropdown is a real hyperlink menu anchored directly below
 * its trigger button, so the URL is always shareable and refresh/back-forward
 * safe. Active filters are indicated by a dot indicator on the trigger rather
 * than a separate chip bar.
 *
 * Mobile: a "Filtros" button that opens a fixed-position bottom-sheet panel
 * containing the same filters as native radio groups, with sticky
 * [Limpar] / [Aplicar filtros] actions. Because the sheet is an overlay it
 * never pushes the attraction grid around.
 *
 * Filtering remains fully server-side and URL driven (unchanged):
 *   - County filters use the `conexao_county` taxonomy via `?county=`.
 *   - Category filters use the `conexao_category` taxonomy via `?categoria=`.
 * Both are combinable and every desktop option is a real hyperlink; the mobile
 * options are form controls. Browser refresh, back/forward, sharing and direct
 * access to filtered URLs keep working exactly as before.
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
$county_terms   = conexao_get_terms_for_post_type( 'conexao_county', 'leisure' );
$category_terms = conexao_get_terms_for_post_type( 'conexao_category', 'leisure' );

$has_county   = ( ! is_wp_error( $county_terms ) && ! empty( $county_terms ) );
$has_category = ( ! is_wp_error( $category_terms ) && ! empty( $category_terms ) );

if ( ! $has_county && ! $has_category ) {
	return;
}

// Slug -> name lookups, used to render the active filter chips.
$county_by_slug   = array();
$category_by_slug = array();

if ( $has_county ) {
	foreach ( $county_terms as $term ) {
		$county_by_slug[ $term->slug ] = $term->name;
	}
}
if ( $has_category ) {
	foreach ( $category_terms as $term ) {
		$category_by_slug[ $term->slug ] = $term->name;
	}
}

$has_active_filters = ( '' !== $current_county || '' !== $current_category );

// URL that clears every filter (the plain archive).
$clear_url = $archive_url;

// URLs that remove a single filter while preserving the other.
$remove_county_url = $archive_url;
if ( $current_category ) {
	$remove_county_url = add_query_arg( 'categoria', $current_category, $archive_url );
}

$remove_category_url = $archive_url;
if ( $current_county ) {
	$remove_category_url = add_query_arg( 'county', $current_county, $archive_url );
}

// Resolved display names for the active chips (falls back to the slug).
$active_county_name   = isset( $county_by_slug[ $current_county ] ) ? $county_by_slug[ $current_county ] : $current_county;
$active_category_name = isset( $category_by_slug[ $current_category ] ) ? $category_by_slug[ $current_category ] : $current_category;

// Labels shown on the desktop dropdown triggers. When a filter is active the
// trigger shows the selected value so the current state is immediately visible.
$county_label   = $active_county_name ? $active_county_name : __( 'Todos', 'conexao-br-irlanda' );
$category_label = $active_category_name ? $active_category_name : __( 'Todos', 'conexao-br-irlanda' );

// Number of active filters, shown as a small badge on the mobile trigger.
$active_filter_count = ( $current_county ? 1 : 0 ) + ( $current_category ? 1 : 0 );
?>
<div class="leisure-filters" data-leisure-filters>

	<div class="leisure-filter-toolbar">
		<?php if ( $has_county ) : ?>
			<div class="leisure-filter-group">
				<span class="leisure-filter-group-label"><?php esc_html_e( 'Condado', 'conexao-br-irlanda' ); ?></span>
				<div class="leisure-dropdown" data-dropdown>
					<button
						type="button"
						class="leisure-dropdown-trigger<?php echo $current_county ? ' is-selected' : ''; ?>"
						aria-haspopup="listbox"
						aria-expanded="false"
						aria-controls="leisure-county-panel"
						data-dropdown-trigger>
						<?php if ( $current_county ) : ?>
							<span class="leisure-dot" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="leisure-dropdown-label"><?php echo esc_html( $county_label ); ?></span>
						<span class="leisure-dropdown-caret" aria-hidden="true">▾</span>
					</button>

					<div class="leisure-dropdown-panel" id="leisure-county-panel" role="listbox" aria-label="<?php esc_attr_e( 'Condado', 'conexao-br-irlanda' ); ?>" data-dropdown-panel>
						<a class="leisure-dropdown-link <?php echo empty( $current_county ) ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo empty( $current_county ) ? 'true' : 'false'; ?>" href="<?php echo esc_url( $current_category ? add_query_arg( 'categoria', $current_category, $archive_url ) : $archive_url ); ?>">
							<span class="leisure-checkmark" aria-hidden="true"><?php echo empty( $current_county ) ? '✓' : ''; ?></span>
							<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
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
							<a class="leisure-dropdown-link <?php echo $is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>" href="<?php echo esc_url( $url ); ?>">
								<span class="leisure-checkmark" aria-hidden="true"><?php echo $is_active ? '✓' : ''; ?></span>
								<span><?php echo esc_html( $term->name ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $has_category ) : ?>
			<div class="leisure-filter-group">
				<span class="leisure-filter-group-label"><?php esc_html_e( 'Tipo', 'conexao-br-irlanda' ); ?></span>
				<div class="leisure-dropdown" data-dropdown>
					<button
						type="button"
						class="leisure-dropdown-trigger<?php echo $current_category ? ' is-selected' : ''; ?>"
						aria-haspopup="listbox"
						aria-expanded="false"
						aria-controls="leisure-category-panel"
						data-dropdown-trigger>
						<?php if ( $current_category ) : ?>
							<span class="leisure-dot" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="leisure-dropdown-label"><?php echo esc_html( $category_label ); ?></span>
						<span class="leisure-dropdown-caret" aria-hidden="true">▾</span>
					</button>

					<div class="leisure-dropdown-panel" id="leisure-category-panel" role="listbox" aria-label="<?php esc_attr_e( 'Tipo', 'conexao-br-irlanda' ); ?>" data-dropdown-panel>
						<a class="leisure-dropdown-link <?php echo empty( $current_category ) ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo empty( $current_category ) ? 'true' : 'false'; ?>" href="<?php echo esc_url( $current_county ? add_query_arg( 'county', $current_county, $archive_url ) : $archive_url ); ?>">
							<span class="leisure-checkmark" aria-hidden="true"><?php echo empty( $current_category ) ? '✓' : ''; ?></span>
							<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
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
							<a class="leisure-dropdown-link <?php echo $is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>" href="<?php echo esc_url( $url ); ?>">
								<span class="leisure-checkmark" aria-hidden="true"><?php echo $is_active ? '✓' : ''; ?></span>
								<span><?php echo esc_html( $term->name ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $has_active_filters ) : ?>
			<a class="leisure-toolbar-clear" href="<?php echo esc_url( $clear_url ); ?>">
				<?php esc_html_e( 'Limpar filtros', 'conexao-br-irlanda' ); ?>
			</a>
		<?php endif; ?>
	</div>

	<?php if ( $has_county || $has_category ) : ?>
		<div class="leisure-mobile-filter" data-mobile-filter>
			<button
				type="button"
				class="leisure-mobile-filter-trigger"
				aria-haspopup="dialog"
				aria-expanded="false"
				aria-controls="leisure-mobile-sheet"
				data-mobile-trigger>
				<span class="leisure-mobile-filter-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<line x1="4" y1="6" x2="20" y2="6"></line>
						<line x1="7" y1="12" x2="17" y2="12"></line>
						<line x1="10" y1="18" x2="14" y2="18"></line>
					</svg>
				</span>
				<?php esc_html_e( 'Filtros', 'conexao-br-irlanda' ); ?>
				<?php if ( $active_filter_count > 0 ) : ?>
					<span class="leisure-mobile-filter-count" aria-hidden="true"><?php echo esc_html( $active_filter_count ); ?></span>
				<?php endif; ?>
			</button>

			<div class="leisure-mobile-sheet" data-mobile-sheet aria-hidden="true">
				<div class="leisure-mobile-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="leisure-mobile-sheet-title">
					<div class="leisure-mobile-sheet-header">
						<div class="leisure-mobile-sheet-handle" aria-hidden="true"></div>
						<h2 class="leisure-mobile-sheet-title" id="leisure-mobile-sheet-title"><?php esc_html_e( 'Filtros', 'conexao-br-irlanda' ); ?></h2>
						<button type="button" class="leisure-mobile-sheet-close" data-mobile-close aria-label="<?php esc_attr_e( 'Fechar filtros', 'conexao-br-irlanda' ); ?>">
							<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
								<line x1="18" y1="6" x2="6" y2="18"></line>
								<line x1="6" y1="6" x2="18" y2="18"></line>
							</svg>
						</button>
					</div>

					<form method="get" action="<?php echo esc_url( $archive_url ); ?>" class="leisure-mobile-form" data-mobile-form>
						<div class="leisure-mobile-sheet-body">
							<?php if ( $has_county ) : ?>
								<details class="leisure-filter-accordion" open>
									<summary class="leisure-filter-accordion-summary">
										<span><?php esc_html_e( 'Condado', 'conexao-br-irlanda' ); ?></span>
										<span class="leisure-accordion-caret" aria-hidden="true">▾</span>
									</summary>
									<fieldset class="leisure-filter-fieldset">
										<label class="leisure-filter-option">
											<input class="leisure-filter-radio" type="radio" name="county" value="" <?php checked( '' === $current_county ); ?>>
											<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
										</label>

										<?php foreach ( $county_terms as $term ) : ?>
											<label class="leisure-filter-option">
												<input class="leisure-filter-radio" type="radio" name="county" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( $current_county === $term->slug ); ?>>
												<span><?php echo esc_html( $term->name ); ?></span>
											</label>
										<?php endforeach; ?>
									</fieldset>
								</details>
							<?php endif; ?>

							<?php if ( $has_category ) : ?>
								<details class="leisure-filter-accordion">
									<summary class="leisure-filter-accordion-summary">
										<span><?php esc_html_e( 'Tipo', 'conexao-br-irlanda' ); ?></span>
										<span class="leisure-accordion-caret" aria-hidden="true">▾</span>
									</summary>
									<fieldset class="leisure-filter-fieldset">
										<label class="leisure-filter-option">
											<input class="leisure-filter-radio" type="radio" name="categoria" value="" <?php checked( '' === $current_category ); ?>>
											<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
										</label>

										<?php foreach ( $category_terms as $term ) : ?>
											<label class="leisure-filter-option">
												<input class="leisure-filter-radio" type="radio" name="categoria" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( $current_category === $term->slug ); ?>>
												<span><?php echo esc_html( $term->name ); ?></span>
											</label>
										<?php endforeach; ?>
									</fieldset>
								</details>
							<?php endif; ?>
						</div>

						<div class="leisure-mobile-sheet-footer">
							<a class="leisure-mobile-clear" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Limpar', 'conexao-br-irlanda' ); ?></a>
							<button type="submit" class="leisure-apply-button"><?php esc_html_e( 'Aplicar filtros', 'conexao-br-irlanda' ); ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>