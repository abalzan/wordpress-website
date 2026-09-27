<?php
/**
 * Eventos Archive Filters Template Part
 *
 * Renders the filter bar for the Eventos (/eventos/) archive - the event
 * CPT ONLY. Wired via 'filters' => 'event' in archive.php.
 *
 * Stage B (filter UI standardization): the widget markup now follows the
 * SAME filter UX standard as /lazer/ (leisure-filters.php) and the
 * /empregos/ agency directory (employment-opportunities.php):
 *   - Desktop: substantial dropdown triggers ("Localização", "Cidade",
 *     "Categoria") with an active dot indicator and caret, each opening a
 *     real hyperlink menu (role="listbox") anchored below its trigger.
 *     Every option is a real <a> built through conexao_event_filter_url(),
 *     with a checkmark/selected state and aria-selected; the group's
 *     "Todas"/"Todos" option is the reset action for that dimension only.
 *     Long option lists (counties/towns) get a client-side search field
 *     that filters the server-rendered options — purely cosmetic, no
 *     extra request and no change to the underlying links.
 *   - An active-filter chip row where each chip is a real hyperlink that
 *     removes that single value, a "Limpar filtros" action that only
 *     appears while a filter is active, and a lightweight result-count
 *     line (role="status").
 *   - Zero-results state: a dashed empty panel with a "Limpar filtros"
 *     action when filters are active but the archive query found nothing.
 *   - Mobile: a prominent full-width "Filtrar" button (with an
 *     active-filter count badge) opening a modal bottom sheet with plain
 *     always-visible radio fieldsets (search included for long lists).
 *     Every radio change applies instantly by navigating to the
 *     server-rendered filtered URL, so the sheet auto-dismisses and the
 *     user lands on the results. The submit pipeline strips empty
 *     "Todas"/"Todos" values so URLs stay clean; the submit button is the
 *     no-JS fallback. Focus is trapped while the sheet is open and
 *     returned to the "Filtrar" trigger on close.
 *
 * DATA MODEL / URL CONTRACT (unchanged from Stage A):
 *   - Localização: conexao_county terms used by published events, via
 *     ?county= (same convention as /lazer/).
 *   - Cidade: conexao_town terms (event-only taxonomy) via ?cidade=.
 *     When a county is selected the towns are scoped to that county
 *     (County -> City cascade); the cascade is expressed server-side, so
 *     no invalid County + City combination can ever be produced from the
 *     UI (a county radio change applies instantly and re-renders the
 *     scoped town list).
 *   - Categoria: conexao_category terms used by events via ?categoria=.
 *   - All dimensions are single-select and AND-combined:
 *     ?county=laois&cidade=portlaoise = County Laois AND City Portlaoise.
 *   - Every option URL is built through conexao_event_filter_url()
 *     (changing one dimension never drops the others, page cursor resets,
 *     URLs stay shareable/refresh-safe).
 *
 * @package Conexao_BR_Irlanda
 */

$archive_url = get_post_type_archive_link( 'event' );
if ( ! $archive_url ) {
	$archive_url = home_url( '/eventos/' );
}

$current_county   = isset( $_GET['county'] ) ? sanitize_title( wp_unslash( $_GET['county'] ) ) : '';
$current_town     = isset( $_GET['cidade'] ) ? sanitize_title( wp_unslash( $_GET['cidade'] ) ) : '';
$current_category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';

$county_terms = conexao_get_terms_for_post_type( 'conexao_county', 'event' );
if ( is_wp_error( $county_terms ) ) {
	$county_terms = array();
}

// Towns used by published events, scoped to the selected county when one
// is active (County -> City cascade — same helper the query layer relies on).
$town_terms = function_exists( 'conexao_get_event_towns' ) ? conexao_get_event_towns( $current_county ) : conexao_get_terms_for_post_type( 'conexao_town', 'event' );
if ( is_wp_error( $town_terms ) ) {
	$town_terms = array();
}

$category_terms = conexao_get_terms_for_post_type( 'conexao_category', 'event' );
if ( is_wp_error( $category_terms ) ) {
	$category_terms = array();
}

$has_county   = ! empty( $county_terms );
$has_town     = ! empty( $town_terms );
$has_category = ! empty( $category_terms );

if ( ! $has_county && ! $has_town && ! $has_category ) {
	return;
}

// Slug -> name lookups for trigger labels and active filter chips.
$county_by_slug   = array();
$town_by_slug     = array();
$category_by_slug = array();

foreach ( $county_terms as $term ) {
	$county_by_slug[ $term->slug ] = $term->name;
}
foreach ( $town_terms as $term ) {
	$town_by_slug[ $term->slug ] = $term->name;
}
foreach ( $category_terms as $term ) {
	$category_by_slug[ $term->slug ] = $term->name;
}

/**
 * Local helper: the filter URL with the given dimensions overridden.
 *
 * @param array $overrides Dimension overrides ('county', 'cidade', 'categoria').
 * @return string Filtered archive URL preserving all other dimensions.
 */
$event_state_url = function( array $overrides ) use ( $current_county, $current_town, $current_category, $archive_url ) {
	return conexao_event_filter_url(
		array(
			'county'    => isset( $overrides['county'] ) ? $overrides['county'] : $current_county,
			'cidade'    => isset( $overrides['cidade'] ) ? $overrides['cidade'] : $current_town,
			'categoria' => isset( $overrides['categoria'] ) ? $overrides['categoria'] : $current_category,
		),
		$archive_url
	);
};

/**
 * Local helper: the screen-reader trigger label for a single-select
 * dimension ("Filtrar por X" / "Filtrar por X. Filtro ativo: name").
 *
 * @param string $group    Group name ("Localização", "Cidade", "Categoria").
 * @param string $selected Currently selected option name ('' when none).
 * @return string Accessible trigger label.
 */
$event_trigger_aria = function( $group, $selected ) {
	if ( '' === $selected ) {
		return sprintf(
			/* translators: %s: filter group name. */
			__( 'Filtrar por %s', 'conexao-br-irlanda' ),
			$group
		);
	}
	return sprintf(
		/* translators: 1: filter group name, 2: selected option name. */
		__( 'Filtrar por %1$s. Filtro ativo: %2$s', 'conexao-br-irlanda' ),
		$group,
		$selected
	);
};

$has_active_filters = ( '' !== $current_county || '' !== $current_town || '' !== $current_category );
$clear_url          = $archive_url;

// Number of active filters, shown as the badge on the mobile trigger.
$active_filter_count = ( $current_county ? 1 : 0 ) + ( $current_town ? 1 : 0 ) + ( $current_category ? 1 : 0 );

// Resolved display names for the active values (fall back to the slug).
$active_county_name   = ( $current_county && isset( $county_by_slug[ $current_county ] ) ) ? $county_by_slug[ $current_county ] : $current_county;
$active_town_name     = ( $current_town && isset( $town_by_slug[ $current_town ] ) ) ? $town_by_slug[ $current_town ] : $current_town;
$active_category_name = ( $current_category && isset( $category_by_slug[ $current_category ] ) ) ? $category_by_slug[ $current_category ] : $current_category;

// Client-side search is only rendered for long option lists — the same
// threshold the Lazer/Empregos widgets use.
$show_county_search = count( $county_terms ) > 8;
$show_town_search   = count( $town_terms ) > 8;

// Lightweight result summary. The main archive query already computes
// found_posts for pagination, so reading it here costs no extra query.
global $wp_query;
$event_total = ( isset( $wp_query ) && $wp_query instanceof WP_Query ) ? (int) $wp_query->found_posts : 0;
?>
<div class="event-filters" data-event-filters>
	<div class="event-filters-toolbar">
		<?php if ( $has_county ) : ?>
			<div class="event-filters-group">
				<div class="event-filters-dropdown" data-dropdown>
					<button
						type="button"
						class="event-filters-dropdown-trigger<?php echo $current_county ? ' is-selected' : ''; ?>"
						aria-haspopup="listbox"
						aria-expanded="false"
						aria-controls="event-filters-county-panel"
						aria-label="<?php echo esc_attr( $event_trigger_aria( __( 'County', 'conexao-br-irlanda' ), $active_county_name ) ); ?>"
						data-dropdown-trigger>
						<?php if ( $current_county ) : ?>
							<span class="event-filters-dot" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="event-filters-dropdown-label"><?php echo esc_html( $current_county ? $active_county_name : __( 'County', 'conexao-br-irlanda' ) ); ?></span>
						<span class="event-filters-dropdown-caret" aria-hidden="true">▾</span>
					</button>

					<div class="event-filters-dropdown-panel" id="event-filters-county-panel" data-dropdown-panel data-option-scope>
						<?php if ( $show_county_search ) : ?>
							<?php // Client-side search over the server-rendered options —
							      // no extra request, no change to the filter values. ?>
							<input
								type="search"
								class="event-filters-dropdown-search"
								data-option-search
								placeholder="<?php esc_attr_e( 'Procurar county', 'conexao-br-irlanda' ); ?>"
								aria-label="<?php esc_attr_e( 'Procurar county', 'conexao-br-irlanda' ); ?>"
								autocomplete="off">
						<?php endif; ?>
						<div class="event-filters-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'County', 'conexao-br-irlanda' ); ?>" data-option-list>
							<?php // "Todas" is the reset action for THIS dimension only:
							      // it clears ?county= and preserves cidade + categoria
							      // (a town filter stays valid without a county). ?>
							<a class="event-filters-dropdown-link <?php echo '' === $current_county ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo '' === $current_county ? 'true' : 'false'; ?>" href="<?php echo esc_url( $event_state_url( array( 'county' => '' ) ) ); ?>">
								<span class="event-filters-checkmark" aria-hidden="true"><?php echo '' === $current_county ? '✓' : ''; ?></span>
								<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
							</a>
							<?php foreach ( $county_terms as $term ) : ?>
								<?php
								// Selecting a county clears a town that belongs to another
								      // county (towns are scoped server-side), so invalid
								      // County + City combinations can never be built from the UI.
								$county_link_state = array( 'county' => ( $term->slug === $current_county ) ? '' : $term->slug );
								if ( $term->slug !== $current_county ) {
									$county_link_state['cidade'] = '';
								}
								$county_is_active = ( $term->slug === $current_county );
								?>
								<a class="event-filters-dropdown-link <?php echo $county_is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $county_is_active ? 'true' : 'false'; ?>" data-option-item href="<?php echo esc_url( $event_state_url( $county_link_state ) ); ?>">
									<span class="event-filters-checkmark" aria-hidden="true"><?php echo $county_is_active ? '✓' : ''; ?></span>
									<span><?php echo esc_html( $term->name ); ?></span>
								</a>
							<?php endforeach; ?>
						</div>
						<?php if ( $show_county_search ) : ?>
							<p class="event-filters-options-empty" data-option-empty hidden><?php esc_html_e( 'Nenhum county encontrado.', 'conexao-br-irlanda' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $has_town ) : ?>
			<div class="event-filters-group">
				<div class="event-filters-dropdown" data-dropdown>
					<button
						type="button"
						class="event-filters-dropdown-trigger<?php echo $current_town ? ' is-selected' : ''; ?>"
						aria-haspopup="listbox"
						aria-expanded="false"
						aria-controls="event-filters-town-panel"
						aria-label="<?php echo esc_attr( $event_trigger_aria( __( 'Cidade', 'conexao-br-irlanda' ), $active_town_name ) ); ?>"
						data-dropdown-trigger>
						<?php if ( $current_town ) : ?>
							<span class="event-filters-dot" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="event-filters-dropdown-label"><?php echo esc_html( $current_town ? $active_town_name : __( 'Cidade', 'conexao-br-irlanda' ) ); ?></span>
						<span class="event-filters-dropdown-caret" aria-hidden="true">▾</span>
					</button>

					<div class="event-filters-dropdown-panel" id="event-filters-town-panel" data-dropdown-panel data-option-scope>
						<?php if ( $show_town_search ) : ?>
							<input
								type="search"
								class="event-filters-dropdown-search"
								data-option-search
								placeholder="<?php esc_attr_e( 'Procurar cidade', 'conexao-br-irlanda' ); ?>"
								aria-label="<?php esc_attr_e( 'Procurar cidade', 'conexao-br-irlanda' ); ?>"
								autocomplete="off">
						<?php endif; ?>
						<div class="event-filters-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'Cidade', 'conexao-br-irlanda' ); ?>" data-option-list>
							<?php // Town options are already scoped to the selected county
							      // (server-side cascade). "Todas" clears ONLY ?cidade=. ?>
							<a class="event-filters-dropdown-link <?php echo '' === $current_town ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo '' === $current_town ? 'true' : 'false'; ?>" href="<?php echo esc_url( $event_state_url( array( 'cidade' => '' ) ) ); ?>">
								<span class="event-filters-checkmark" aria-hidden="true"><?php echo '' === $current_town ? '✓' : ''; ?></span>
								<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
							</a>
							<?php foreach ( $town_terms as $term ) : ?>
								<?php $town_is_active = ( $term->slug === $current_town ); ?>
								<a class="event-filters-dropdown-link <?php echo $town_is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $town_is_active ? 'true' : 'false'; ?>" data-option-item href="<?php echo esc_url( $event_state_url( array( 'cidade' => $town_is_active ? '' : $term->slug ) ) ); ?>">
									<span class="event-filters-checkmark" aria-hidden="true"><?php echo $town_is_active ? '✓' : ''; ?></span>
									<span><?php echo esc_html( $term->name ); ?></span>
								</a>
							<?php endforeach; ?>
						</div>
						<?php if ( $show_town_search ) : ?>
							<p class="event-filters-options-empty" data-option-empty hidden><?php esc_html_e( 'Nenhuma cidade encontrada.', 'conexao-br-irlanda' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $has_category ) : ?>
			<div class="event-filters-group">
				<div class="event-filters-dropdown" data-dropdown>
					<button
						type="button"
						class="event-filters-dropdown-trigger<?php echo $current_category ? ' is-selected' : ''; ?>"
						aria-haspopup="listbox"
						aria-expanded="false"
						aria-controls="event-filters-category-panel"
						aria-label="<?php echo esc_attr( $event_trigger_aria( __( 'Categoria', 'conexao-br-irlanda' ), $active_category_name ) ); ?>"
						data-dropdown-trigger>
						<?php if ( $current_category ) : ?>
							<span class="event-filters-dot" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="event-filters-dropdown-label"><?php echo esc_html( $current_category ? $active_category_name : __( 'Categoria', 'conexao-br-irlanda' ) ); ?></span>
						<span class="event-filters-dropdown-caret" aria-hidden="true">▾</span>
					</button>

					<div class="event-filters-dropdown-panel" id="event-filters-category-panel" data-dropdown-panel>
						<div class="event-filters-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'Categoria', 'conexao-br-irlanda' ); ?>">
							<a class="event-filters-dropdown-link <?php echo '' === $current_category ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo '' === $current_category ? 'true' : 'false'; ?>" href="<?php echo esc_url( $event_state_url( array( 'categoria' => '' ) ) ); ?>">
								<span class="event-filters-checkmark" aria-hidden="true"><?php echo '' === $current_category ? '✓' : ''; ?></span>
								<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
							</a>
							<?php foreach ( $category_terms as $term ) : ?>
								<?php $category_is_active = ( $term->slug === $current_category ); ?>
								<a class="event-filters-dropdown-link <?php echo $category_is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $category_is_active ? 'true' : 'false'; ?>" href="<?php echo esc_url( $event_state_url( array( 'categoria' => $category_is_active ? '' : $term->slug ) ) ); ?>">
									<span class="event-filters-checkmark" aria-hidden="true"><?php echo $category_is_active ? '✓' : ''; ?></span>
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
		<div class="event-filters-active">
			<span class="event-filters-active-label"><?php esc_html_e( 'Filtros ativos:', 'conexao-br-irlanda' ); ?></span>

			<div class="event-filters-chips">
				<?php // Each chip removes exactly ONE dimension (the Lazer/Empregos
				      // chip semantics): a removed county keeps cidade + categoria
				      // because a town filter stays valid without its county. ?>
				<?php if ( $current_county ) : ?>
					<a class="event-filters-chip" href="<?php echo esc_url( $event_state_url( array( 'county' => '' ) ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_county_name ) ); ?>">
						<span class="event-filters-chip-name"><?php echo esc_html( $active_county_name ); ?></span>
						<span class="event-filters-chip-remove" aria-hidden="true">×</span>
					</a>
				<?php endif; ?>
				<?php if ( $current_town ) : ?>
					<a class="event-filters-chip" href="<?php echo esc_url( $event_state_url( array( 'cidade' => '' ) ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_town_name ) ); ?>">
						<span class="event-filters-chip-name"><?php echo esc_html( $active_town_name ); ?></span>
						<span class="event-filters-chip-remove" aria-hidden="true">×</span>
					</a>
				<?php endif; ?>
				<?php if ( $current_category ) : ?>
					<a class="event-filters-chip" href="<?php echo esc_url( $event_state_url( array( 'categoria' => '' ) ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_category_name ) ); ?>">
						<span class="event-filters-chip-name"><?php echo esc_html( $active_category_name ); ?></span>
						<span class="event-filters-chip-remove" aria-hidden="true">×</span>
					</a>
				<?php endif; ?>
			</div>

			<a class="event-filters-clear" href="<?php echo esc_url( $clear_url ); ?>">
				<?php esc_html_e( 'Limpar filtros', 'conexao-br-irlanda' ); ?>
			</a>
		</div>
	<?php endif; ?>
	<p class="event-filters-count" role="status">
		<?php
		printf(
			/* translators: %s: number of events. */
			_n( '%s evento encontrado', '%s eventos encontrados', $event_total, 'conexao-br-irlanda' ),
			number_format_i18n( $event_total )
		);
		?>
	</p>

	<div class="event-filters-mobile">
		<button
			type="button"
			class="event-filters-mobile-trigger"
			aria-haspopup="dialog"
			aria-expanded="false"
			aria-controls="event-filters-sheet"
			<?php echo $active_filter_count > 0 ? 'aria-label="' . esc_attr( sprintf( __( 'Filtrar (%s filtros ativos)', 'conexao-br-irlanda' ), number_format_i18n( $active_filter_count ) ) ) . '"' : ''; ?>
			data-mobile-trigger>
			<span class="event-filters-mobile-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<line x1="4" y1="6" x2="20" y2="6"></line>
					<line x1="7" y1="12" x2="17" y2="12"></line>
					<line x1="10" y1="18" x2="14" y2="18"></line>
				</svg>
			</span>
			<span class="event-filters-mobile-label"><?php esc_html_e( 'Filtrar', 'conexao-br-irlanda' ); ?></span>
			<?php if ( $active_filter_count > 0 ) : ?>
				<span class="event-filters-mobile-count" aria-hidden="true"><?php echo esc_html( number_format_i18n( $active_filter_count ) ); ?></span>
			<?php endif; ?>
		</button>

		<div class="event-filters-sheet" data-mobile-sheet aria-hidden="true">
			<div class="event-filters-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="event-filters-sheet-title">
				<div class="event-filters-sheet-header">
					<div class="event-filters-sheet-handle" aria-hidden="true"></div>
					<h3 class="event-filters-sheet-title" id="event-filters-sheet-title"><?php esc_html_e( 'Filtros', 'conexao-br-irlanda' ); ?></h3>
					<button type="button" class="event-filters-sheet-close" data-mobile-close aria-label="<?php esc_attr_e( 'Fechar filtros', 'conexao-br-irlanda' ); ?>">
						<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
							<line x1="6" y1="6" x2="18" y2="18"></line>
							<line x1="18" y1="6" x2="6" y2="18"></line>
						</svg>
					</button>
				</div>

				<form method="get" action="<?php echo esc_url( $clear_url ); ?>" class="event-filters-mobile-form" data-mobile-form>
					<div class="event-filters-sheet-body">
						<?php // Always-visible radio fieldsets (no accordions): the full
						      // option set is exposed at once and the state is
						      // server-rendered from the URL, never reset on reopen.
						      // Each radio change applies instantly (navigates), so a
						      // county change re-renders the scoped town list — the
						      // County -> City cascade is preserved on mobile. ?>
						<?php if ( $has_county ) : ?>
							<fieldset class="event-filters-mobile-section" data-option-scope>
								<legend class="event-filters-mobile-legend"><?php esc_html_e( 'County', 'conexao-br-irlanda' ); ?></legend>
								<?php if ( $show_county_search ) : ?>
									<input
										type="search"
										class="event-filters-mobile-search"
										data-option-search
										placeholder="<?php esc_attr_e( 'Procurar county', 'conexao-br-irlanda' ); ?>"
										aria-label="<?php esc_attr_e( 'Procurar county', 'conexao-br-irlanda' ); ?>">
								<?php endif; ?>
								<div class="event-filters-options" data-option-list>
									<label class="event-filters-option">
										<input class="event-filters-radio" type="radio" name="county" value="" <?php checked( '' === $current_county ); ?>>
										<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
									</label>
									<?php foreach ( $county_terms as $term ) : ?>
										<label class="event-filters-option" data-option-item>
											<input class="event-filters-radio" type="radio" name="county" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( $current_county === $term->slug ); ?>>
											<span><?php echo esc_html( $term->name ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
								<?php if ( $show_county_search ) : ?>
									<p class="event-filters-options-empty" data-option-empty hidden><?php esc_html_e( 'Nenhum county encontrado', 'conexao-br-irlanda' ); ?></p>
								<?php endif; ?>
							</fieldset>
						<?php endif; ?>

						<?php if ( $has_town ) : ?>
							<fieldset class="event-filters-mobile-section" data-option-scope>
								<legend class="event-filters-mobile-legend"><?php esc_html_e( 'Cidade', 'conexao-br-irlanda' ); ?></legend>
								<?php if ( $show_town_search ) : ?>
									<input
										type="search"
										class="event-filters-mobile-search"
										data-option-search
										placeholder="<?php esc_attr_e( 'Procurar cidade', 'conexao-br-irlanda' ); ?>"
										aria-label="<?php esc_attr_e( 'Procurar cidade', 'conexao-br-irlanda' ); ?>">
								<?php endif; ?>
								<div class="event-filters-options" data-option-list>
									<label class="event-filters-option">
										<input class="event-filters-radio" type="radio" name="cidade" value="" <?php checked( '' === $current_town ); ?>>
										<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
									</label>
									<?php foreach ( $town_terms as $term ) : ?>
										<label class="event-filters-option" data-option-item>
											<input class="event-filters-radio" type="radio" name="cidade" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( $current_town === $term->slug ); ?>>
											<span><?php echo esc_html( $term->name ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
								<?php if ( $show_town_search ) : ?>
									<p class="event-filters-options-empty" data-option-empty hidden><?php esc_html_e( 'Nenhuma cidade encontrada', 'conexao-br-irlanda' ); ?></p>
								<?php endif; ?>
							</fieldset>
						<?php endif; ?>

						<?php if ( $has_category ) : ?>
							<fieldset class="event-filters-mobile-section">
								<legend class="event-filters-mobile-legend"><?php esc_html_e( 'Categoria', 'conexao-br-irlanda' ); ?></legend>
								<div class="event-filters-options">
									<label class="event-filters-option">
										<input class="event-filters-radio" type="radio" name="categoria" value="" <?php checked( '' === $current_category ); ?>>
										<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
									</label>
									<?php foreach ( $category_terms as $term ) : ?>
										<label class="event-filters-option">
											<input class="event-filters-radio" type="radio" name="categoria" value="<?php echo esc_attr( $term->slug ); ?>" <?php checked( $current_category === $term->slug ); ?>>
											<span><?php echo esc_html( $term->name ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>
						<?php endif; ?>
					</div>

					<div class="event-filters-sheet-footer">
						<a class="event-filters-mobile-clear" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Limpar', 'conexao-br-irlanda' ); ?></a>
						<button type="submit" class="event-filters-apply-button"><?php esc_html_e( 'Mostrar resultados', 'conexao-br-irlanda' ); ?></button>
					</div>
				</form>
			</div>
		</div>
	</div><!-- .event-filters-mobile -->
</div>
