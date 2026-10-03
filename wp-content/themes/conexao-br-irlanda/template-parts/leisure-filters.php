<?php
/**
 * Lazer Archive Filters Template Part
 *
 * Renders the /lazer/ filtering UI as a single, lightweight component.
 *
 * Desktop: a content-discovery toolbar under the "Encontre o que fazer"
 * heading — substantial dropdown triggers ("Localização", "Tipo" and
 * "Características", roughly 220–280px wide so they align with the
 * attraction grid below) that show the selection state while a filter is
 * active (the multi-select triggers collapse to the single selected label
 * or a "N selecionados" count; the full selection stays in the trigger's
 * aria-label), an active-filter chip row where each chip is a real
 * hyperlink that removes that single value, a "Limpar filtros" action that
 * only appears while a filter is active, and a lightweight result-count
 * line. Each dropdown is a real hyperlink menu anchored directly below its
 * trigger button, so the URL is always shareable and refresh/back-forward
 * safe. Every option is a plain navigational <a> (native link role — NOT a
 * listbox option): the dropdown is a disclosure button (aria-expanded +
 * aria-controls) over a named group of hyperlinks, and the active option is
 * marked with aria-current="true", the same convention already used by
 * guide-filters.php / course-filters.php. Tipo and Características are true
 * multi-select dimensions: every option link toggles its slug inside the
 * shared filter-state snapshot, selections accumulate across open/close
 * cycles, and the group label ("Todos" / "Todas") is a reset action that
 * clears ONLY its own dimension. All option URLs are built through the
 * shared conexao_leisure_filter_url() helper in functions.php, so changing
 * one filter never drops the others and every filter change resets to
 * page 1. The Localização popover additionally has a
 * client-side search field over its server-rendered options (about 26
 * counties) — cosmetic filtering only, no extra request and no change to
 * the underlying links.
 *
 * Mobile: a prominent full-width "Filtrar" button (with an active-filter
 * count badge when filters are applied) that opens a fixed-position
 * bottom-sheet panel. Inside the sheet every filter group is a plain
 * always-visible fieldset — no accordions — so the full option set is
 * exposed at once and the current state is never reset when the sheet
 * reopens. Localização keeps its single-select radios (instant apply).
 * Tipo and Características are multi-select checkbox groups: selections
 * accumulate while the sheet stays open and nothing is applied until
 * "Mostrar resultados" (which submits the whole selection state at once);
 * each section's nameless "Todos"/"Todas" checkbox is the reset action
 * for that section only. The sheet is a modal dialog: focus is trapped
 * while open and returned to the "Filtrar" button on close. Because
 * the sheet is an overlay it never pushes the attraction grid around. Above
 * the results, mobile also shows the same
 * active-filter chip row (each chip a real hyperlink that removes that single
 * filter) and "Limpar filtros" as the desktop toolbar; on mobile those taps
 * run through the same instant apply pipeline (in-place swap, focus moves to
 * the next chip / back to the trigger when the tapped control is removed).
 *
 * Filtering remains fully server-side and URL driven:
 *   - County filters use the `conexao_county` taxonomy via `?county=` (single).
 *   - Tipo uses the `conexao_category` taxonomy via `?categoria=` — a single
 *     slug (legacy URLs) or a comma-separated OR list (`?categoria=a,b`).
 *   - Características uses the `conexao_leisure_attribute` taxonomy via
 *     `?atributo=` — same comma-separated OR semantics.
 * Dimensions are AND-combined. Every desktop option is a real hyperlink
 * (desktop chips/"Limpar filtros" keep their native navigation — only the
 * mobile interactions apply in place); the mobile options are form controls.
 * Browser refresh, back/forward, sharing and direct access to filtered URLs
 * keep working exactly as before.
 *
 * @package Conexao_BR_Irlanda
 */

$archive_url = get_post_type_archive_link( 'leisure' );
if ( ! $archive_url ) {
	$archive_url = home_url( '/lazer/' );
}

// Current filter state from the URL. Tipo and Características are
// multi-select dimensions: each may carry a comma-separated slug list
// (?categoria=natureza,cultura / ?atributo=exterior,familias) or an array
// of slugs (mobile checkbox groups). Both are normalized by the shared
// helpers in functions.php (sanitize_title, de-duplicated, order preserved).
$current_county     = isset( $_GET['county'] ) ? sanitize_title( wp_unslash( $_GET['county'] ) ) : '';
$current_categories = conexao_leisure_query_slugs( 'categoria' );
$current_attributes = conexao_leisure_query_slugs( 'atributo' );

// Snapshot of the active state. Every option URL below is built by
// overriding exactly ONE dimension of this snapshot through the shared
// conexao_leisure_filter_url() builder, so changing one filter can never
// drop the others and every URL is generated from the clean archive link
// (no pagina param — any filter change resets to page 1).
$filter_state = array(
	'county'    => $current_county,
	'categoria' => $current_categories,
	'atributo'  => $current_attributes,
);

/**
 * Local helper: the filter URL with the given dimensions overridden.
 *
 * @param array $overrides Dimension overrides ('county', 'categoria', 'atributo').
 * @return string Filtered archive URL preserving all other dimensions.
 */
$leisure_state_url = function( array $overrides ) use ( $filter_state, $archive_url ) {
	return conexao_leisure_filter_url( array_merge( $filter_state, $overrides ), $archive_url );
};

/**
 * Local helper: toggle one slug inside a multi-select dimension (add when
 * absent, remove when present) — the URL semantics of the desktop dropdown
 * links and the mobile checkboxes.
 *
 * @param string[] $slugs Currently selected slugs.
 * @param string   $slug  Slug to toggle.
 * @return string[] New selection.
 */
$leisure_toggle_slug = function( array $slugs, $slug ) {
	if ( in_array( $slug, $slugs, true ) ) {
		return array_values( array_diff( $slugs, array( $slug ) ) );
	}
	return array_merge( $slugs, array( $slug ) );
};

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

// Slug -> name lookups, used for trigger labels and the active filter chips.
$county_by_slug    = array();
$category_by_slug  = array();
$attribute_by_slug = array();

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
if ( $has_attribute ) {
	foreach ( $attribute_terms as $term ) {
		$attribute_by_slug[ $term->slug ] = $term->name;
	}
}

$has_active_filters = ( '' !== $current_county || ! empty( $current_categories ) || ! empty( $current_attributes ) );

// URL that clears every filter (the plain archive).
$clear_url = $archive_url;

// Resolved display name for a slug inside a dimension (falls back to the slug).
$leisure_slug_name = function( $slug, array $by_slug ) {
	return isset( $by_slug[ $slug ] ) ? $by_slug[ $slug ] : $slug;
};

// Labels shown on the desktop dropdown triggers. When no filter is active the
// trigger shows the group name ("Localização", "Tipo", "Características").
// The multi-select dimensions show a single selection's label where practical
// and a concise count for several selections ("3 selecionados") — the full
// selection is always announced by the trigger's aria-label below.
$county_trigger_label = $current_county ? $leisure_slug_name( $current_county, $county_by_slug ) : __( 'County', 'conexao-br-irlanda' );

/**
 * Local helper: the closed-dropdown trigger label for a multi-select
 * dimension (none → group name, one → the option's label, several → count).
 *
 * @param string[] $slugs   Selected slugs.
 * @param array    $by_slug Slug → name lookup.
 * @param string   $empty   Group label shown when nothing is selected.
 * @return string Trigger label.
 */
$leisure_multi_trigger_label = function( array $slugs, array $by_slug, $empty ) use ( $leisure_slug_name ) {
	$count = count( $slugs );
	if ( 0 === $count ) {
		return $empty;
	}
	if ( 1 === $count ) {
		return $leisure_slug_name( $slugs[0], $by_slug );
	}
	return sprintf(
		/* translators: %s: number of selected filter options. */
		__( '%s selecionados', 'conexao-br-irlanda' ),
		number_format_i18n( $count )
	);
};

$category_trigger_label  = $leisure_multi_trigger_label( $current_categories, $category_by_slug, __( 'Tipo', 'conexao-br-irlanda' ) );
$attribute_trigger_label = $leisure_multi_trigger_label( $current_attributes, $attribute_by_slug, __( 'Características', 'conexao-br-irlanda' ) );

// Screen-reader labels for the triggers — the dot indicator is decorative, so
// the active state is also announced as text. For the multi-select dimensions
// the FULL selection is spelled out here (the visible label collapses to a
// count), keeping the state programmatically available.
$county_trigger_aria = $current_county
	? sprintf(
		/* translators: %s: selected county name. */
		__( 'Filtrar por County. Filtro ativo: %s', 'conexao-br-irlanda' ),
		$leisure_slug_name( $current_county, $county_by_slug )
	)
	: __( 'Filtrar por County', 'conexao-br-irlanda' );

/**
 * Local helper: the screen-reader trigger label for a multi-select dimension.
 *
 * @param string[] $slugs   Selected slugs.
 * @param array    $by_slug Slug → name lookup.
 * @param string   $empty   Label when nothing is selected.
 * @param string   $group   Group name ("Tipo", "Características").
 * @return string Accessible trigger label.
 */
$leisure_multi_trigger_aria = function( array $slugs, array $by_slug, $empty, $group ) use ( $leisure_slug_name ) {
	if ( empty( $slugs ) ) {
		return sprintf(
			/* translators: %s: filter group name. */
			__( 'Filtrar por %s', 'conexao-br-irlanda' ),
			$group
		);
	}
	$names = array_map( function( $slug ) use ( $by_slug, $leisure_slug_name ) {
		return $leisure_slug_name( $slug, $by_slug );
	}, $slugs );

	return sprintf(
		/* translators: 1: filter group name, 2: comma-separated list of selected option names. */
		__( 'Filtrar por %1$s. Filtros ativos: %2$s', 'conexao-br-irlanda' ),
		$group,
		implode( ', ', $names )
	);
};

$category_trigger_aria  = $leisure_multi_trigger_aria( $current_categories, $category_by_slug, __( 'Tipo', 'conexao-br-irlanda' ), __( 'Tipo', 'conexao-br-irlanda' ) );
$attribute_trigger_aria = $leisure_multi_trigger_aria( $current_attributes, $attribute_by_slug, __( 'Características', 'conexao-br-irlanda' ), __( 'Características', 'conexao-br-irlanda' ) );

// Number of active filters, shown as a small badge on the mobile trigger.
// Each selected value counts individually (county = 1, every selected
// category/attribute = 1).
$active_filter_count = ( $current_county ? 1 : 0 ) + count( $current_categories ) + count( $current_attributes );

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
							placeholder="<?php esc_attr_e( 'Procurar county', 'conexao-br-irlanda' ); ?>"
							aria-label="<?php esc_attr_e( 'Procurar county', 'conexao-br-irlanda' ); ?>"
							autocomplete="off">
						<div class="leisure-dropdown-list" aria-label="<?php esc_attr_e( 'Condado', 'conexao-br-irlanda' ); ?>">
							<?php // Localização stays single-select; its option URLs are built from the shared
							      // state snapshot, so switching/clearing it preserves Tipo and Características. ?>
							<a class="leisure-dropdown-link <?php echo empty( $current_county ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( $leisure_state_url( array( 'county' => '' ) ) ); ?>"<?php echo empty( $current_county ) ? ' aria-current="true"' : ''; ?>>
								<span class="leisure-checkmark" aria-hidden="true"><?php echo empty( $current_county ) ? '✓' : ''; ?></span>
								<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
							</a>

							<?php foreach ( $county_terms as $term ) : ?>
								<?php $is_active = ( $current_county === $term->slug ); ?>
								<a class="leisure-dropdown-link <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $leisure_state_url( array( 'county' => $term->slug ) ) ); ?>"<?php echo $is_active ? ' aria-current="true"' : ''; ?>>
									<span class="leisure-checkmark" aria-hidden="true"><?php echo $is_active ? '✓' : ''; ?></span>
									<span><?php echo esc_html( $term->name ); ?></span>
								</a>
							<?php endforeach; ?>
						</div>
						<p class="leisure-dropdown-empty" data-dropdown-empty hidden><?php esc_html_e( 'Nenhum county encontrado.', 'conexao-br-irlanda' ); ?></p>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $has_category ) : ?>
			<div class="leisure-filter-group">
				<div class="leisure-dropdown" data-dropdown>
					<button
						type="button"
						class="leisure-dropdown-trigger<?php echo ! empty( $current_categories ) ? ' is-selected' : ''; ?>"
						aria-expanded="false"
						aria-controls="leisure-category-panel"
						aria-label="<?php echo esc_attr( $category_trigger_aria ); ?>"
						data-dropdown-trigger>
						<?php if ( ! empty( $current_categories ) ) : ?>
							<span class="leisure-dot" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="leisure-dropdown-label"><?php echo esc_html( $category_trigger_label ); ?></span>
						<span class="leisure-dropdown-caret" aria-hidden="true">▾</span>
					</button>

					<div class="leisure-dropdown-panel" id="leisure-category-panel" data-dropdown-panel>
						<?php // Tipo is a multi-select listbox: each option is a real hyperlink
							  // that toggles its slug inside the shared state snapshot, so
							  // selections accumulate across open/close cycles and every URL
							  // stays shareable. "Todos" is the reset action for this dimension
							  // only — it clears categoria and never touches county/atributo. ?>
						<div class="leisure-dropdown-list" aria-label="<?php esc_attr_e( 'Tipo', 'conexao-br-irlanda' ); ?>">
							<a class="leisure-dropdown-link <?php echo empty( $current_categories ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( $leisure_state_url( array( 'categoria' => array() ) ) ); ?>"<?php echo empty( $current_categories ) ? ' aria-current="true"' : ''; ?>>
								<span class="leisure-checkmark" aria-hidden="true"><?php echo empty( $current_categories ) ? '✓' : ''; ?></span>
								<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
							</a>

							<?php foreach ( $category_terms as $term ) : ?>
								<?php
								$new_categories = $leisure_toggle_slug( $current_categories, $term->slug );
								$is_active      = in_array( $term->slug, $current_categories, true );
								?>
								<a class="leisure-dropdown-link <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $leisure_state_url( array( 'categoria' => $new_categories ) ) ); ?>"<?php echo $is_active ? ' aria-current="true"' : ''; ?>>
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
						class="leisure-dropdown-trigger<?php echo ! empty( $current_attributes ) ? ' is-selected' : ''; ?>"
						aria-expanded="false"
						aria-controls="leisure-attribute-panel"
						aria-label="<?php echo esc_attr( $attribute_trigger_aria ); ?>"
						data-dropdown-trigger>
						<?php if ( ! empty( $current_attributes ) ) : ?>
							<span class="leisure-dot" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="leisure-dropdown-label"><?php echo esc_html( $attribute_trigger_label ); ?></span>
						<span class="leisure-dropdown-caret" aria-hidden="true">▾</span>
					</button>

					<div class="leisure-dropdown-panel" id="leisure-attribute-panel" data-dropdown-panel>
						<?php // Características uses the exact same multi-select model as Tipo:
							  // toggle hyperlinks over the existing conexao_leisure_attribute
							  // vocabulary, "Todas" as the
							  // reset action for this dimension only (clears atributo,
							  // preserves county + categoria). ?>
						<div class="leisure-dropdown-list" aria-label="<?php esc_attr_e( 'Características', 'conexao-br-irlanda' ); ?>">
							<a class="leisure-dropdown-link <?php echo empty( $current_attributes ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( $leisure_state_url( array( 'atributo' => array() ) ) ); ?>"<?php echo empty( $current_attributes ) ? ' aria-current="true"' : ''; ?>>
								<span class="leisure-checkmark" aria-hidden="true"><?php echo empty( $current_attributes ) ? '✓' : ''; ?></span>
								<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
							</a>

							<?php foreach ( $attribute_terms as $term ) : ?>
								<?php
								$new_attributes = $leisure_toggle_slug( $current_attributes, $term->slug );
								$is_active      = in_array( $term->slug, $current_attributes, true );
								?>
								<a class="leisure-dropdown-link <?php echo $is_active ? 'is-active' : ''; ?>" href="<?php echo esc_url( $leisure_state_url( array( 'atributo' => $new_attributes ) ) ); ?>"<?php echo $is_active ? ' aria-current="true"' : ''; ?>>
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
					<?php $active_county_name = $leisure_slug_name( $current_county, $county_by_slug ); ?>
					<a
						class="leisure-filter-chip"
						href="<?php echo esc_url( $leisure_state_url( array( 'county' => '' ) ) ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: county name. */ __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_county_name ) ); ?>"
					>
						<span class="leisure-filter-chip-name"><?php echo esc_html( $active_county_name ); ?></span>
						<span class="leisure-filter-chip-remove" aria-hidden="true">×</span>
					</a>
				<?php endif; ?>

				<?php // One chip per selected value in each multi-select dimension —
				      // removing a chip drops just that slug and preserves everything else. ?>
				<?php foreach ( $current_categories as $category_slug ) : ?>
					<?php $active_category_name = $leisure_slug_name( $category_slug, $category_by_slug ); ?>
					<a
						class="leisure-filter-chip"
						href="<?php echo esc_url( $leisure_state_url( array( 'categoria' => array_values( array_diff( $current_categories, array( $category_slug ) ) ) ) ) ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: category name. */ __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_category_name ) ); ?>"
					>
						<span class="leisure-filter-chip-name"><?php echo esc_html( $active_category_name ); ?></span>
						<span class="leisure-filter-chip-remove" aria-hidden="true">×</span>
					</a>
				<?php endforeach; ?>

				<?php foreach ( $current_attributes as $attribute_slug ) : ?>
					<?php $active_attribute_name = $leisure_slug_name( $attribute_slug, $attribute_by_slug ); ?>
					<a
						class="leisure-filter-chip"
						href="<?php echo esc_url( $leisure_state_url( array( 'atributo' => array_values( array_diff( $current_attributes, array( $attribute_slug ) ) ) ) ) ); ?>"
						aria-label="<?php echo esc_attr( sprintf( /* translators: %s: attribute name. */ __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_attribute_name ) ); ?>"
					>
						<span class="leisure-filter-chip-name"><?php echo esc_html( $active_attribute_name ); ?></span>
						<span class="leisure-filter-chip-remove" aria-hidden="true">×</span>
					</a>
				<?php endforeach; ?>
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
								<legend class="leisure-mobile-section-legend"><?php esc_html_e( 'County', 'conexao-br-irlanda' ); ?></legend>
								<?php if ( $show_county_search ) : ?>
									<input
										type="search"
										class="leisure-mobile-search"
										data-option-search
										placeholder="<?php esc_attr_e( 'Procurar county', 'conexao-br-irlanda' ); ?>"
										aria-label="<?php esc_attr_e( 'Procurar county', 'conexao-br-irlanda' ); ?>">
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
									<p class="leisure-filter-options-empty" data-option-empty hidden><?php esc_html_e( 'Nenhum county encontrado', 'conexao-br-irlanda' ); ?></p>
								<?php endif; ?>
							</fieldset>
						<?php endif; ?>

						<?php if ( $has_category ) : ?>
							<fieldset class="leisure-mobile-section">
								<legend class="leisure-mobile-section-legend"><?php esc_html_e( 'Tipo', 'conexao-br-irlanda' ); ?></legend>
									<?php // Multi-select checkbox group — the same semantics as the desktop
									      // dropdown. The nameless "Todos" checkbox is the section reset:
									      // checking it clears every selection below (handled in main.js);
									      // it carries no name/value, so it is never submitted. ?>
								<div class="leisure-filter-options">
									<label class="leisure-filter-option">
										<input class="leisure-filter-checkbox" type="checkbox" data-filter-clear <?php checked( empty( $current_categories ) ); ?>>
										<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
									</label>

									<?php foreach ( $category_terms as $term ) : ?>
										<label class="leisure-filter-option">
											<input class="leisure-filter-checkbox" type="checkbox" name="categoria[]" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( in_array( $term->slug, $current_categories, true ) ); ?>>
											<span><?php echo esc_html( $term->name ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>
						<?php endif; ?>
							<?php if ( $has_attribute ) : ?>
								<fieldset class="leisure-mobile-section">
									<legend class="leisure-mobile-section-legend"><?php esc_html_e( 'Características', 'conexao-br-irlanda' ); ?></legend>
									<?php // Same multi-select model as the Tipo section: checkbox group
									      // (name="atributo[]") plus the nameless "Todas" section reset. ?>
									<div class="leisure-filter-options">
										<label class="leisure-filter-option">
											<input class="leisure-filter-checkbox" type="checkbox" data-filter-clear <?php checked( empty( $current_attributes ) ); ?>>
											<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
										</label>

										<?php foreach ( $attribute_terms as $term ) : ?>
											<label class="leisure-filter-option">
												<input class="leisure-filter-checkbox" type="checkbox" name="atributo[]" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( in_array( $term->slug, $current_attributes, true ) ); ?>>
												<span><?php echo esc_html( $term->name ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
								</fieldset>
							<?php endif; ?>
						</div>

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
