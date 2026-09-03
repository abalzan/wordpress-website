<?php
/**
 * Lazer Archive Filters Template Part
 *
 * Renders the /lazer/ filtering UI as a single, lightweight component.
 *
 * Desktop: a content-discovery toolbar under the "Encontre o que fazer"
 * heading — substantial dropdown triggers ("Localização" and "Tipo", roughly
 * 220–280px wide so they align with the attraction grid below) that show
 * the selected value while a filter is active, an active-filter chip row
 * where each chip is a real hyperlink that removes that single filter, a
 * "Limpar filtros" action that only appears while a filter is active, and a
 * lightweight result-count line. Each dropdown is a real hyperlink menu
 * anchored directly below its trigger button, so the URL is always shareable
 * and refresh/back-forward safe. The Localização popover additionally has a
 * client-side search field over its server-rendered options (about 26
 * counties) — cosmetic filtering only, no extra request and no change to
 * the underlying links.
 *
 * Mobile: a prominent full-width "Filtrar" button (with an active-filter
 * count badge when filters are applied) that opens a fixed-position
 * bottom-sheet panel. Inside the sheet every filter group is a plain
 * always-visible radio fieldset — no accordions — so the full option set is
 * exposed at once, the current state is never reset when the sheet reopens,
 * and selecting a radio applies the filter immediately. Selecting ANY
 * filter ALSO closes the sheet in the same gesture — focus returns
 * to the "Filtrar" button and the user lands directly on the filtered
 * results; the sheet can be reopened to stack another filter (selections
 * are preserved). "Mostrar resultados" remains
 * only as a no-JS / fallback action). The sheet is a modal dialog: focus is
 * trapped while open and returned to the "Filtrar" button on close. Because
 * the sheet is an overlay it never pushes the attraction grid around. Above
 * the results, mobile also shows the same
 * active-filter chip row (each chip a real hyperlink that removes that single
 * filter) and "Limpar filtros" as the desktop toolbar; on mobile those taps
 * run through the same instant apply pipeline (in-place swap, focus moves to
 * the next chip / back to the trigger when the tapped control is removed).
 *
 * Filtering remains fully server-side and URL driven (unchanged):
 *   - County filters use the `conexao_county` taxonomy via `?county=`.
 *   - Category filters use the `conexao_category` taxonomy via `?categoria=`.
 * Both are combinable and every desktop option is a real hyperlink (desktop
 * chips/"Limpar filtros" keep their native navigation — only the mobile
 * interactions apply in place); the mobile options are form controls.
 * Browser refresh, back/forward, sharing and direct access to filtered URLs
 * keep working exactly as before.
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
	$current_atributo = '';
if ( isset( $_GET['atributo'] ) ) {
	$atributo_raw = wp_unslash( $_GET['atributo'] );
	if ( is_array( $atributo_raw ) ) {
		$current_atributo = implode( ',', array_map( 'sanitize_title', $atributo_raw ) );
	} else {
		$current_atributo = sanitize_text_field( $atributo_raw );
	}
}

// Collect counties and categories actually used by leisure locations.
$county_terms   = conexao_get_terms_for_post_type( 'conexao_county', 'leisure' );
$category_terms   = conexao_get_terms_for_post_type( 'conexao_category', 'leisure' );
	$attribute_terms  = conexao_get_terms_for_post_type( 'conexao_leisure_attribute', 'leisure' );

$has_county   = ( ! is_wp_error( $county_terms ) && ! empty( $county_terms ) );
$has_category   = ( ! is_wp_error( $category_terms ) && ! empty( $category_terms ) );
	$has_attribute = ( ! is_wp_error( $attribute_terms ) && ! empty( $attribute_terms ) );

if ( ! $has_county && ! $has_category && ! $has_attribute ) {
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

$has_active_filters = ( '' !== $current_county || '' !== $current_category || '' !== $current_atributo );

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

// Labels shown on the desktop dropdown triggers. When no filter is active the
// trigger shows the group name ("Localização", "Tipo"); when a filter is
// selected the trigger shows the chosen value (plus the dot indicator) so the
// current state is visible without opening the menu.
$county_trigger_label   = $current_county ? $active_county_name : __( 'Localização', 'conexao-br-irlanda' );
$category_trigger_label = $current_category ? $active_category_name : __( 'Tipo', 'conexao-br-irlanda' );

// Screen-reader labels for the triggers — the dot indicator is decorative, so
// the active state is also announced as text.
$county_trigger_aria = $current_county
	? sprintf(
		/* translators: %s: selected county name. */
		__( 'Filtrar por Localização. Filtro ativo: %s', 'conexao-br-irlanda' ),
		$active_county_name
	)
	: __( 'Filtrar por Localização', 'conexao-br-irlanda' );

$category_trigger_aria = $current_category
	? sprintf(
		/* translators: %s: selected category name. */
		__( 'Filtrar por Tipo. Filtro ativo: %s', 'conexao-br-irlanda' ),
		$active_category_name
	)
	: __( 'Filtrar por Tipo', 'conexao-br-irlanda' );

// Number of active filters, shown as a small badge on the mobile trigger.
$active_filter_count = ( $current_county ? 1 : 0 ) + ( $current_category ? 1 : 0 );
	if ( $current_atributo ) {
		$active_filter_count += count( array_filter( array_map( 'trim', explode( ',', $current_atributo ) ) ) );
	}

// Lightweight result summary. The main archive query already computes
// found_posts for pagination, so reading it here costs no extra query.
global $wp_query;
$leisure_total = ( isset( $wp_query ) && $wp_query instanceof WP_Query ) ? (int) $wp_query->found_posts : 0;
?>
<div class="leisure-filters" data-leisure-filters>

	<h2 class="leisure-filters-title"><?php esc_html_e( 'Encontre o que fazer', 'conexao-br-irlanda' ); ?></h2>

	<div class="leisure-filter-toolbar">
		<?php if ( $has_county ) : ?>
			<div class="leisure-filter-group">
				<div class="leisure-dropdown" data-dropdown>
					<button
						type="button"
						class="leisure-dropdown-trigger<?php echo $current_county ? ' is-selected' : ''; ?>"
						aria-haspopup="listbox"
						aria-expanded="false"
						aria-controls="leisure-county-panel"
						aria-label="<?php echo esc_attr( $county_trigger_aria ); ?>"
						data-dropdown-trigger>
						<?php if ( $current_county ) : ?>
							<span class="leisure-dot" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="leisure-dropdown-label"><?php echo esc_html( $county_trigger_label ); ?></span>
						<span class="leisure-dropdown-caret" aria-hidden="true">▾</span>
					</button>

					<div class="leisure-dropdown-panel" id="leisure-county-panel" data-dropdown-panel>
						<?php // Client-side search over the server-rendered options —
						      // no extra request, no change to the filter values. ?>
						<input
							type="search"
							class="leisure-dropdown-search"
							data-dropdown-search
							placeholder="<?php esc_attr_e( 'Procurar localização', 'conexao-br-irlanda' ); ?>"
							aria-label="<?php esc_attr_e( 'Procurar localização', 'conexao-br-irlanda' ); ?>"
							autocomplete="off">
						<div class="leisure-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'Condado', 'conexao-br-irlanda' ); ?>">
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
						<p class="leisure-dropdown-empty" data-dropdown-empty hidden><?php esc_html_e( 'Nenhuma localização encontrada.', 'conexao-br-irlanda' ); ?></p>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $has_category ) : ?>
			<div class="leisure-filter-group">
				<div class="leisure-dropdown" data-dropdown>
					<button
						type="button"
						class="leisure-dropdown-trigger<?php echo $current_category ? ' is-selected' : ''; ?>"
						aria-haspopup="listbox"
						aria-expanded="false"
						aria-controls="leisure-category-panel"
						aria-label="<?php echo esc_attr( $category_trigger_aria ); ?>"
						data-dropdown-trigger>
						<?php if ( $current_category ) : ?>
							<span class="leisure-dot" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="leisure-dropdown-label"><?php echo esc_html( $category_trigger_label ); ?></span>
						<span class="leisure-dropdown-caret" aria-hidden="true">▾</span>
					</button>

					<div class="leisure-dropdown-panel" id="leisure-category-panel" data-dropdown-panel>
						<div class="leisure-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'Tipo', 'conexao-br-irlanda' ); ?>">
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
			</div>
		<?php endif; ?>

		<?php if ( $has_attribute ) : ?>
			<div class="leisure-filter-group">
				<div class="leisure-dropdown" data-dropdown>
					<button
						type="button"
						class="leisure-dropdown-trigger<?php echo $current_atributo ? ' is-selected' : ''; ?>"
						aria-haspopup="listbox"
						aria-expanded="false"
						aria-controls="leisure-attribute-panel"
						aria-label="<?php esc_attr_e( 'Filtrar por Características', 'conexao-br-irlanda' ); ?>"
						data-dropdown-trigger>
						<?php if ( $current_atributo ) : ?>
							<span class="leisure-dot" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="leisure-dropdown-label"><?php esc_html_e( 'Características', 'conexao-br-irlanda' ); ?></span>
						<span class="leisure-dropdown-caret" aria-hidden="true">▾</span>
					</button>

					<div class="leisure-dropdown-panel" id="leisure-attribute-panel" data-dropdown-panel>
						<div class="leisure-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'Características', 'conexao-br-irlanda' ); ?>">
							<a class="leisure-dropdown-link <?php echo empty( $current_atributo ) ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo empty( $current_atributo ) ? 'true' : 'false'; ?>" href="<?php
								// Remove only the attribute filter, preserve county + category.
								$base = $archive_url;
								if ( $current_county ) {
									$base = add_query_arg( 'county', $current_county, $base );
								}
								if ( $current_category ) {
									$base = add_query_arg( 'categoria', $current_category, $base );
								}
								echo esc_url( $base );
							?>">
								<span class="leisure-checkmark" aria-hidden="true"><?php echo empty( $current_atributo ) ? '✓' : ''; ?></span>
								<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
							</a>

							<?php foreach ( $attribute_terms as $term ) : ?>
								<?php
								// Toggle this attribute in the comma-separated list.
								$selected_attrs = $current_atributo ? array_filter( array_map( 'trim', explode( ',', $current_atributo ) ) ) : array();
								if ( in_array( $term->slug, $selected_attrs, true ) ) {
									$selected_attrs = array_diff( $selected_attrs, array( $term->slug ) );
								} else {
									$selected_attrs[] = $term->slug;
								}
								$new_atributo = implode( ',', $selected_attrs );
								$url = $archive_url;
								if ( $current_county ) {
									$url = add_query_arg( 'county', $current_county, $url );
								}
								if ( $current_category ) {
									$url = add_query_arg( 'categoria', $current_category, $url );
								}
								if ( '' !== $new_atributo ) {
									$url = add_query_arg( 'atributo', $new_atributo, $url );
								}
								$is_active = in_array( $term->slug, $selected_attrs, true );
								?>
								<a class="leisure-dropdown-link <?php echo $is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>" href="<?php echo esc_url( $url ); ?>">
									<span class="leisure-checkmark" aria-hidden="true"><?php echo $is_active ? '✓' : ''; ?></span>
									<span><?php echo esc_html( $term->name ); ?></span>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
		<?php endif; ?>

	</div>

	<?php if ( $has_active_filters ) : ?>
		<div class="leisure-active-filters" data-leisure-active-filters>
			<span class="leisure-active-filters-label"><?php esc_html_e( 'Filtros ativos:', 'conexao-br-irlanda' ); ?></span>

			<div class="leisure-active-filters-chips">
				<?php if ( $current_county ) : ?>
					<a
						class="leisure-filter-chip"
						href="<?php echo esc_url( $remove_county_url ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: county name. */ __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_county_name ) ); ?>"
					>
						<span class="leisure-filter-chip-name"><?php echo esc_html( $active_county_name ); ?></span>
						<span class="leisure-filter-chip-remove" aria-hidden="true">×</span>
					</a>
				<?php endif; ?>

				<?php if ( $current_category ) : ?>
					<a
						class="leisure-filter-chip"
						href="<?php echo esc_url( $remove_category_url ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: category name. */ __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_category_name ) ); ?>"
					>
						<span class="leisure-filter-chip-name"><?php echo esc_html( $active_category_name ); ?></span>
						<span class="leisure-filter-chip-remove" aria-hidden="true">×</span>
					</a>
				<?php endif; ?>
			</div>

			<a class="leisure-toolbar-clear" href="<?php echo esc_url( $clear_url ); ?>">
				<?php esc_html_e( 'Limpar filtros', 'conexao-br-irlanda' ); ?>
			</a>
		</div>
	<?php endif; ?>

	<?php if ( $leisure_total > 0 ) : ?>
		<p class="leisure-results-count" role="status">
			<?php
			printf(
				/* translators: %s: number of results. */
				_n( '%s opção encontrada', '%s opções encontradas', $leisure_total, 'conexao-br-irlanda' ),
				number_format_i18n( $leisure_total )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( $has_county || $has_category ) : ?>
		<div class="leisure-mobile-filter" data-mobile-filter>
			<button
				type="button"
				class="leisure-mobile-filter-trigger"
				aria-haspopup="dialog"
				aria-expanded="false"
				aria-controls="leisure-mobile-sheet"
				<?php echo $active_filter_count > 0 ? 'aria-label="' . esc_attr( sprintf( /* translators: %s: number of active filters. */ __( 'Filtrar (%s filtros ativos)', 'conexao-br-irlanda' ), number_format_i18n( $active_filter_count ) ) ) . '"' : ''; ?>
				data-mobile-trigger>
				<span class="leisure-mobile-filter-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<line x1="4" y1="6" x2="20" y2="6"></line>
						<line x1="7" y1="12" x2="17" y2="12"></line>
						<line x1="10" y1="18" x2="14" y2="18"></line>
					</svg>
				</span>
				<span class="leisure-mobile-filter-label"><?php esc_html_e( 'Filtrar', 'conexao-br-irlanda' ); ?></span>
				<?php if ( $active_filter_count > 0 ) : ?>
					<span class="leisure-mobile-filter-count" aria-hidden="true"><?php echo esc_html( number_format_i18n( $active_filter_count ) ); ?></span>
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
						<?php if ( $has_county ) :
							// A search field only makes sense for a long list — with a
							// handful of counties it would be unnecessary UI. This mirrors
							// the desktop popover, which always shows search over the same
							// (currently ~26-county) list.
							$show_county_search = count( $county_terms ) > 8;
							?>
							<fieldset class="leisure-mobile-section">
								<legend class="leisure-mobile-section-legend"><?php esc_html_e( 'Localização', 'conexao-br-irlanda' ); ?></legend>
								<?php if ( $show_county_search ) : ?>
									<input
										type="search"
										class="leisure-mobile-search"
										data-option-search
										placeholder="<?php esc_attr_e( 'Procurar localização', 'conexao-br-irlanda' ); ?>"
										aria-label="<?php esc_attr_e( 'Procurar localização', 'conexao-br-irlanda' ); ?>">
								<?php endif; ?>
								<div class="leisure-filter-options" data-option-list>
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
								</div>
								<?php if ( $show_county_search ) : ?>
									<p class="leisure-filter-options-empty" data-option-empty hidden><?php esc_html_e( 'Nenhuma localização encontrada', 'conexao-br-irlanda' ); ?></p>
								<?php endif; ?>
							</fieldset>
						<?php endif; ?>

						<?php if ( $has_category ) : ?>
							<fieldset class="leisure-mobile-section">
								<legend class="leisure-mobile-section-legend"><?php esc_html_e( 'Tipo', 'conexao-br-irlanda' ); ?></legend>
								<div class="leisure-filter-options">
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
								</div>
							</fieldset>
						<?php endif; ?>
					</div>
				<?php if ( $has_attribute ) : ?>
					<fieldset class="leisure-mobile-section">
						<legend class="leisure-mobile-section-legend"><?php esc_html_e( 'Características', 'conexao-br-irlanda' ); ?></legend>
						<div class="leisure-filter-options">
							<?php
							$selected_attrs = $current_atributo ? array_filter( array_map( 'trim', explode( ',', $current_atributo ) ) ) : array();
							foreach ( $attribute_terms as $term ) :
								$opt_id = 'attr-' . sanitize_html_class( $term->slug );
								$checked = in_array( $term->slug, $selected_attrs, true ) ? ' checked="checked"' : '';
							?>
								<label class="leisure-filter-option">
									<input class="leisure-filter-checkbox" type="checkbox" name="atributo[]" value="<?php echo esc_attr( $term->slug ); ?>"<?php echo $checked; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
									<span><?php echo esc_html( $term->name ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
				<?php endif; ?>

					<div class="leisure-mobile-sheet-footer">
						<a class="leisure-mobile-clear" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Limpar', 'conexao-br-irlanda' ); ?></a>
						<button type="submit" class="leisure-apply-button"><?php esc_html_e( 'Mostrar resultados', 'conexao-br-irlanda' ); ?></button>
					</div>
					</form>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>