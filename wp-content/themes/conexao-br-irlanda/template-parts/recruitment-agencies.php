<?php
/**
 * Recruitment Agencies Section - "Agências de recrutamento" (Empregos landing).
 *
 * Renders curated recruitment agencies as compact cards that reuse the Event
 * card visual language (.event-card*) so the section feels native to the site.
 * Content comes from conexao_recruitment_agencies() — the `recruitment_agency`
 * CPT managed in wp-admin under Empregos → "Agências de Recrutamento".
 *
 * Only published agencies render, ordered by "Ordem de exibição".
 * External links open in a new tab with rel="noopener noreferrer".
 * Phone numbers use tel: links when present.
 *
 * Directory filters (Área de trabalho / Localização / Tipo de contrato) apply
 * ONLY to this section: server-side, URL driven via ?area=/?localizacao=/
 * ?contrato= (same architecture as the /lazer/ ?county=/?categoria= filters —
 * see inc/recruitment-agencies.php). The UI mirrors the Lazer filter widget:
 * desktop hyperlink dropdowns, active-filter chips, a result-count status
 * line and a mobile bottom sheet. The public-sector and Employment Permit
 * sections below are untouched by these filters.
 *
 * @package Conexao_BR_Irlanda
 */

$agencies = conexao_recruitment_agencies();
if ( empty( $agencies ) ) {
	return;
}

// Existing render rule: an agency without a website has no card. The filter
// options, matching and the result count are derived from this same
// displayable list so the controls always describe the visible directory.
$displayable = array();
foreach ( $agencies as $agency ) {
	if ( '' === conexao_recruitment_agency_meta( $agency, '_agency_website' ) ) {
		continue;
	}
	$displayable[] = $agency;
}
if ( empty( $displayable ) ) {
	return;
}

$filters = conexao_recruitment_agency_filter_state( $displayable );
$agency_cards = $filters['agencies'];
$has_active_filters = ( '' !== $filters['area'] || '' !== $filters['location'] || '' !== $filters['contrato'] );

// Every filter URL points back to this section so a filter change (desktop
// link, chip or the mobile form) lands the user on the directory.
$page_url       = get_permalink( get_queried_object_id() );
$section_anchor = '#empregos-agencies-title';

/**
 * Build a filter URL preserving the given state ('' drops the dimension).
 */
$agency_filter_url = static function ( $area, $location, $contrato ) use ( $page_url, $section_anchor ) {
	$args = array();
	if ( '' !== $area ) {
		$args['area'] = $area;
	}
	if ( '' !== $location ) {
		$args['localizacao'] = $location;
	}
	if ( '' !== $contrato ) {
		$args['contrato'] = $contrato;
	}

	return add_query_arg( $args, $page_url ) . $section_anchor;
};

$clear_url = $agency_filter_url( '', '', '' );

// Single-filter removal URLs for the active-filter chips.
$remove_area_url     = $agency_filter_url( '', $filters['location'], $filters['contrato'] );
$remove_location_url = $agency_filter_url( $filters['area'], '', $filters['contrato'] );
$remove_contrato_url = $agency_filter_url( $filters['area'], $filters['location'], '' );

// Resolved display names for the active chips / triggers.
$active_area_name     = $filters['area'] ? $filters['area_options'][ $filters['area'] ] : '';
$active_location_name = $filters['location'] ? $filters['location_options'][ $filters['location'] ] : '';
$active_contrato_name = $filters['contrato'] ? $filters['contrato_options'][ $filters['contrato'] ] : '';

// Trigger labels: group name when inactive, chosen value when active.
$area_trigger_label     = $active_area_name ? $active_area_name : __( 'Área de trabalho', 'conexao-br-irlanda' );
$location_trigger_label = $active_location_name ? $active_location_name : __( 'Localização', 'conexao-br-irlanda' );
$contrato_trigger_label = $active_contrato_name ? $active_contrato_name : __( 'Tipo de contrato', 'conexao-br-irlanda' );

// Screen-reader labels for the triggers — the dot indicator is decorative, so
// the active state is also announced as text.
$area_trigger_aria = $active_area_name
	? sprintf( __( 'Filtrar por Área de trabalho. Filtro ativo: %s', 'conexao-br-irlanda' ), $active_area_name )
	: __( 'Filtrar por Área de trabalho', 'conexao-br-irlanda' );
$location_trigger_aria = $active_location_name
	? sprintf( __( 'Filtrar por Localização. Filtro ativo: %s', 'conexao-br-irlanda' ), $active_location_name )
	: __( 'Filtrar por Localização', 'conexao-br-irlanda' );
$contrato_trigger_aria = $active_contrato_name
	? sprintf( __( 'Filtrar por Tipo de contrato. Filtro ativo: %s', 'conexao-br-irlanda' ), $active_contrato_name )
	: __( 'Filtrar por Tipo de contrato', 'conexao-br-irlanda' );

// Number of active filters, shown as a small badge on the mobile trigger.
$active_filter_count = ( $filters['area'] ? 1 : 0 ) + ( $filters['location'] ? 1 : 0 ) + ( $filters['contrato'] ? 1 : 0 );

// Client-side search only makes sense for a long location list.
$show_location_search = count( $filters['location_options'] ) > 8;

$agency_filter_count = count( $agency_cards );
?>
<section class="empregos-recruitment-agencies" aria-labelledby="empregos-agencies-title">
	<h2 id="empregos-agencies-title" class="empregos-recruitment-agencies-title">
		<?php esc_html_e( 'Agências de recrutamento', 'conexao-br-irlanda' ); ?>
	</h2>
	<p class="empregos-recruitment-agencies-intro">
		<?php esc_html_e( 'Algumas agências de recrutamento trabalham com vagas temporárias e permanentes em áreas como armazém, produção, logística, hotelaria, limpeza, varejo e funções operacionais.', 'conexao-br-irlanda' ); ?>
	</p>
	<div class="empregos-recruitment-agencies-warning">
		<p>
			<strong><?php esc_html_e( 'Atenção:', 'conexao-br-irlanda' ); ?></strong>
			<?php esc_html_e( 'uma agência de recrutamento legítima não deve cobrar de você para encontrar emprego. Desconfie de pedidos de pagamento, dados bancários, criptomoedas, cartões-presente ou promessas de emprego garantido.', 'conexao-br-irlanda' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'Certifique-se também de que tem o direito legal de trabalhar na Irlanda (visto de trabalho, Stamp 1/1G/4, ou cidadania irlandesa/UE).', 'conexao-br-irlanda' ); ?>
		</p>
	</div>
	<div class="agency-filters" data-agency-filters>
		<div class="agency-filters-toolbar">
			<?php if ( ! empty( $filters['area_options'] ) ) : ?>
				<div class="agency-filters-group">
					<div class="agency-filters-dropdown" data-dropdown>
						<button
							type="button"
							class="agency-filters-dropdown-trigger<?php echo $filters['area'] ? ' is-selected' : ''; ?>"
							aria-haspopup="listbox"
							aria-expanded="false"
							aria-controls="empregos-agency-area-panel"
							aria-label="<?php echo esc_attr( $area_trigger_aria ); ?>"
							data-dropdown-trigger>
							<?php if ( $filters['area'] ) : ?>
								<span class="agency-filters-dot" aria-hidden="true"></span>
							<?php endif; ?>
							<span class="agency-filters-dropdown-label"><?php echo esc_html( $area_trigger_label ); ?></span>
							<span class="agency-filters-dropdown-caret" aria-hidden="true">▾</span>
						</button>

						<div class="agency-filters-dropdown-panel" id="empregos-agency-area-panel" data-dropdown-panel>
							<div class="agency-filters-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'Área de trabalho', 'conexao-br-irlanda' ); ?>">
								<a class="agency-filters-dropdown-link <?php echo '' === $filters['area'] ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo '' === $filters['area'] ? 'true' : 'false'; ?>" href="<?php echo esc_url( $agency_filter_url( '', $filters['location'], $filters['contrato'] ) ); ?>">
									<span class="agency-filters-checkmark" aria-hidden="true"><?php echo '' === $filters['area'] ? '✓' : ''; ?></span>
									<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
								</a>
								<?php foreach ( $filters['area_options'] as $area_slug => $area_label ) : ?>
									<?php $area_is_active = ( $filters['area'] === $area_slug ); ?>
									<a class="agency-filters-dropdown-link <?php echo $area_is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $area_is_active ? 'true' : 'false'; ?>" href="<?php echo esc_url( $agency_filter_url( $area_slug, $filters['location'], $filters['contrato'] ) ); ?>">
										<span class="agency-filters-checkmark" aria-hidden="true"><?php echo $area_is_active ? '✓' : ''; ?></span>
										<span><?php echo esc_html( $area_label ); ?></span>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $filters['location_options'] ) ) : ?>
				<div class="agency-filters-group">
					<div class="agency-filters-dropdown" data-dropdown>
						<button
							type="button"
							class="agency-filters-dropdown-trigger<?php echo $filters['location'] ? ' is-selected' : ''; ?>"
							aria-haspopup="listbox"
							aria-expanded="false"
							aria-controls="empregos-agency-location-panel"
							aria-label="<?php echo esc_attr( $location_trigger_aria ); ?>"
							data-dropdown-trigger>
							<?php if ( $filters['location'] ) : ?>
								<span class="agency-filters-dot" aria-hidden="true"></span>
							<?php endif; ?>
							<span class="agency-filters-dropdown-label"><?php echo esc_html( $location_trigger_label ); ?></span>
							<span class="agency-filters-dropdown-caret" aria-hidden="true">▾</span>
						</button>

						<div class="agency-filters-dropdown-panel" id="empregos-agency-location-panel" data-dropdown-panel>
							<?php if ( $show_location_search ) : ?>
								<?php // Client-side search over the server-rendered options —
								      // no extra request, no change to the filter values. ?>
								<input
									type="search"
									class="agency-filters-dropdown-search"
									data-option-search
									placeholder="<?php esc_attr_e( 'Procurar localização', 'conexao-br-irlanda' ); ?>"
									aria-label="<?php esc_attr_e( 'Procurar localização', 'conexao-br-irlanda' ); ?>"
									autocomplete="off">
							<?php endif; ?>
							<div class="agency-filters-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'Localização', 'conexao-br-irlanda' ); ?>" data-option-scope>
								<a class="agency-filters-dropdown-link <?php echo '' === $filters['location'] ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo '' === $filters['location'] ? 'true' : 'false'; ?>" href="<?php echo esc_url( $agency_filter_url( $filters['area'], '', $filters['contrato'] ) ); ?>">
									<span class="agency-filters-checkmark" aria-hidden="true"><?php echo '' === $filters['location'] ? '✓' : ''; ?></span>
									<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
								</a>
								<?php foreach ( $filters['location_options'] as $location_slug => $location_label ) : ?>
									<?php $location_is_active = ( $filters['location'] === $location_slug ); ?>
									<a class="agency-filters-dropdown-link <?php echo $location_is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $location_is_active ? 'true' : 'false'; ?>" data-option-item href="<?php echo esc_url( $agency_filter_url( $filters['area'], $location_slug, $filters['contrato'] ) ); ?>">
										<span class="agency-filters-checkmark" aria-hidden="true"><?php echo $location_is_active ? '✓' : ''; ?></span>
										<span><?php echo esc_html( $location_label ); ?></span>
									</a>
								<?php endforeach; ?>
							</div>
							<?php if ( $show_location_search ) : ?>
								<p class="agency-filters-options-empty" data-option-empty hidden><?php esc_html_e( 'Nenhuma localização encontrada.', 'conexao-br-irlanda' ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $filters['contrato_options'] ) ) : ?>
				<div class="agency-filters-group">
					<div class="agency-filters-dropdown" data-dropdown>
						<button
							type="button"
							class="agency-filters-dropdown-trigger<?php echo $filters['contrato'] ? ' is-selected' : ''; ?>"
							aria-haspopup="listbox"
							aria-expanded="false"
							aria-controls="empregos-agency-contrato-panel"
							aria-label="<?php echo esc_attr( $contrato_trigger_aria ); ?>"
							data-dropdown-trigger>
							<?php if ( $filters['contrato'] ) : ?>
								<span class="agency-filters-dot" aria-hidden="true"></span>
							<?php endif; ?>
							<span class="agency-filters-dropdown-label"><?php echo esc_html( $contrato_trigger_label ); ?></span>
							<span class="agency-filters-dropdown-caret" aria-hidden="true">▾</span>
						</button>

						<div class="agency-filters-dropdown-panel" id="empregos-agency-contrato-panel" data-dropdown-panel>
							<div class="agency-filters-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'Tipo de contrato', 'conexao-br-irlanda' ); ?>">
								<a class="agency-filters-dropdown-link <?php echo '' === $filters['contrato'] ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo '' === $filters['contrato'] ? 'true' : 'false'; ?>" href="<?php echo esc_url( $agency_filter_url( $filters['area'], $filters['location'], '' ) ); ?>">
									<span class="agency-filters-checkmark" aria-hidden="true"><?php echo '' === $filters['contrato'] ? '✓' : ''; ?></span>
									<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
								</a>
								<?php foreach ( $filters['contrato_options'] as $contrato_slug => $contrato_label ) : ?>
									<?php $contrato_is_active = ( $filters['contrato'] === $contrato_slug ); ?>
									<a class="agency-filters-dropdown-link <?php echo $contrato_is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $contrato_is_active ? 'true' : 'false'; ?>" href="<?php echo esc_url( $agency_filter_url( $filters['area'], $filters['location'], $contrato_slug ) ); ?>">
										<span class="agency-filters-checkmark" aria-hidden="true"><?php echo $contrato_is_active ? '✓' : ''; ?></span>
										<span><?php echo esc_html( $contrato_label ); ?></span>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php if ( $has_active_filters ) : ?>
			<div class="agency-filters-active">
				<span class="agency-filters-active-label"><?php esc_html_e( 'Filtros ativos:', 'conexao-br-irlanda' ); ?></span>

				<div class="agency-filters-chips">
					<?php if ( $filters['area'] ) : ?>
						<a class="agency-filters-chip" href="<?php echo esc_url( $remove_area_url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_area_name ) ); ?>">
							<span class="agency-filters-chip-name"><?php echo esc_html( $active_area_name ); ?></span>
							<span class="agency-filters-chip-remove" aria-hidden="true">×</span>
						</a>
					<?php endif; ?>
					<?php if ( $filters['location'] ) : ?>
						<a class="agency-filters-chip" href="<?php echo esc_url( $remove_location_url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_location_name ) ); ?>">
							<span class="agency-filters-chip-name"><?php echo esc_html( $active_location_name ); ?></span>
							<span class="agency-filters-chip-remove" aria-hidden="true">×</span>
						</a>
					<?php endif; ?>
					<?php if ( $filters['contrato'] ) : ?>
						<a class="agency-filters-chip" href="<?php echo esc_url( $remove_contrato_url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_contrato_name ) ); ?>">
							<span class="agency-filters-chip-name"><?php echo esc_html( $active_contrato_name ); ?></span>
							<span class="agency-filters-chip-remove" aria-hidden="true">×</span>
						</a>
					<?php endif; ?>
				</div>

				<a class="agency-filters-clear" href="<?php echo esc_url( $clear_url ); ?>">
					<?php esc_html_e( 'Limpar filtros', 'conexao-br-irlanda' ); ?>
				</a>
			</div>
		<?php endif; ?>
		<p class="agency-filters-count" role="status">
			<?php
			printf(
				/* translators: %s: number of agencies. */
				_n( '%s agência encontrada', '%s agências encontradas', $agency_filter_count, 'conexao-br-irlanda' ),
				number_format_i18n( $agency_filter_count )
			);
			?>
		</p>
		<div class="agency-filters-mobile" data-mobile-filter>
			<button
				type="button"
				class="agency-filters-mobile-trigger"
				aria-haspopup="dialog"
				aria-expanded="false"
				aria-controls="empregos-agency-sheet"
				<?php echo $active_filter_count > 0 ? 'aria-label="' . esc_attr( sprintf( __( 'Filtrar (%s filtros ativos)', 'conexao-br-irlanda' ), number_format_i18n( $active_filter_count ) ) ) . '"' : ''; ?>
				data-mobile-trigger>
				<span class="agency-filters-mobile-icon" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<line x1="4" y1="6" x2="20" y2="6"></line>
						<line x1="7" y1="12" x2="17" y2="12"></line>
						<line x1="10" y1="18" x2="14" y2="18"></line>
					</svg>
				</span>
				<span class="agency-filters-mobile-label"><?php esc_html_e( 'Filtrar', 'conexao-br-irlanda' ); ?></span>
				<?php if ( $active_filter_count > 0 ) : ?>
					<span class="agency-filters-mobile-count" aria-hidden="true"><?php echo esc_html( number_format_i18n( $active_filter_count ) ); ?></span>
				<?php endif; ?>
			</button>
			<div class="agency-filters-sheet" data-mobile-sheet aria-hidden="true">
				<div class="agency-filters-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="empregos-agency-sheet-title">
					<div class="agency-filters-sheet-header">
						<div class="agency-filters-sheet-handle" aria-hidden="true"></div>
						<h3 class="agency-filters-sheet-title" id="empregos-agency-sheet-title"><?php esc_html_e( 'Filtros', 'conexao-br-irlanda' ); ?></h3>
						<button type="button" class="agency-filters-sheet-close" data-mobile-close aria-label="<?php esc_attr_e( 'Fechar filtros', 'conexao-br-irlanda' ); ?>">
							<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
								<line x1="6" y1="6" x2="18" y2="18"></line>
								<line x1="18" y1="6" x2="6" y2="18"></line>
							</svg>
						</button>
					</div>

					<form method="get" action="<?php echo esc_url( $page_url . $section_anchor ); ?>" class="agency-filters-mobile-form" data-mobile-form>
						<div class="agency-filters-sheet-body">
							<?php if ( ! empty( $filters['area_options'] ) ) : ?>
								<fieldset class="agency-filters-mobile-section">
									<legend class="agency-filters-mobile-legend"><?php esc_html_e( 'Área de trabalho', 'conexao-br-irlanda' ); ?></legend>
									<div class="agency-filters-options">
										<label class="agency-filters-option">
											<input class="agency-filters-radio" type="radio" name="area" value="" <?php checked( '' === $filters['area'] ); ?>>
											<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
										</label>
										<?php foreach ( $filters['area_options'] as $area_slug => $area_label ) : ?>
											<label class="agency-filters-option">
												<input class="agency-filters-radio" type="radio" name="area" value="<?php echo esc_attr( $area_slug ); ?>" <?php checked( $filters['area'] === $area_slug ); ?>>
												<span><?php echo esc_html( $area_label ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
								</fieldset>
							<?php endif; ?>

							<?php if ( ! empty( $filters['location_options'] ) ) : ?>
								<fieldset class="agency-filters-mobile-section" data-option-scope>
									<legend class="agency-filters-mobile-legend"><?php esc_html_e( 'Localização', 'conexao-br-irlanda' ); ?></legend>
									<?php if ( $show_location_search ) : ?>
										<input
											type="search"
											class="agency-filters-mobile-search"
											data-option-search
											placeholder="<?php esc_attr_e( 'Procurar localização', 'conexao-br-irlanda' ); ?>"
											aria-label="<?php esc_attr_e( 'Procurar localização', 'conexao-br-irlanda' ); ?>">
									<?php endif; ?>
									<div class="agency-filters-options" data-option-list>
										<label class="agency-filters-option">
											<input class="agency-filters-radio" type="radio" name="localizacao" value="" <?php checked( '' === $filters['location'] ); ?>>
											<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
										</label>
										<?php foreach ( $filters['location_options'] as $location_slug => $location_label ) : ?>
											<label class="agency-filters-option" data-option-item>
												<input class="agency-filters-radio" type="radio" name="localizacao" value="<?php echo esc_attr( $location_slug ); ?>" <?php checked( $filters['location'] === $location_slug ); ?>>
												<span><?php echo esc_html( $location_label ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
									<?php if ( $show_location_search ) : ?>
										<p class="agency-filters-options-empty" data-option-empty hidden><?php esc_html_e( 'Nenhuma localização encontrada', 'conexao-br-irlanda' ); ?></p>
									<?php endif; ?>
								</fieldset>
							<?php endif; ?>

							<?php if ( ! empty( $filters['contrato_options'] ) ) : ?>
								<fieldset class="agency-filters-mobile-section">
									<legend class="agency-filters-mobile-legend"><?php esc_html_e( 'Tipo de contrato', 'conexao-br-irlanda' ); ?></legend>
									<div class="agency-filters-options">
										<label class="agency-filters-option">
											<input class="agency-filters-radio" type="radio" name="contrato" value="" <?php checked( '' === $filters['contrato'] ); ?>>
											<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
										</label>
										<?php foreach ( $filters['contrato_options'] as $contrato_slug => $contrato_label ) : ?>
											<label class="agency-filters-option">
												<input class="agency-filters-radio" type="radio" name="contrato" value="<?php echo esc_attr( $contrato_slug ); ?>" <?php checked( $filters['contrato'] === $contrato_slug ); ?>>
												<span><?php echo esc_html( $contrato_label ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
								</fieldset>
							<?php endif; ?>
						</div>

						<div class="agency-filters-sheet-footer">
							<a class="agency-filters-mobile-clear" href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Limpar', 'conexao-br-irlanda' ); ?></a>
							<button type="submit" class="agency-filters-apply-button"><?php esc_html_e( 'Mostrar resultados', 'conexao-br-irlanda' ); ?></button>
						</div>
					</form>
				</div>
			</div>
		</div>
	</div>
	<?php if ( empty( $agency_cards ) ) : ?>
		<div class="agency-filters-empty">
			<p class="agency-filters-empty-text"><?php esc_html_e( 'Nenhuma agência encontrada com esses filtros.', 'conexao-br-irlanda' ); ?></p>
			<a class="agency-filters-empty-clear" href="<?php echo esc_url( $clear_url ); ?>">
				<?php esc_html_e( 'Limpar filtros', 'conexao-br-irlanda' ); ?>
			</a>
		</div>
	<?php else : ?>
	<div class="events-grid empregos-recruitment-agencies-grid">
		<?php foreach ( $agency_cards as $agency ) :
			$agency_name     = esc_html( $agency->post_title );
			$job_type_labels = conexao_recruitment_agency_job_type_labels( conexao_recruitment_agency_meta( $agency, '_agency_job_types' ) );
			$job_types       = $job_type_labels ? implode( ', ', $job_type_labels ) : '';
			$location        = conexao_recruitment_agency_meta( $agency, '_agency_location' );
			$phone           = conexao_recruitment_agency_meta( $agency, '_agency_phone' );
			$website         = esc_url( conexao_recruitment_agency_meta( $agency, '_agency_website' ) );
			$temp            = (bool) get_post_meta( $agency->ID, '_agency_temporary', true );
			$perm            = (bool) get_post_meta( $agency->ID, '_agency_permanent', true );
			$wrc             = conexao_recruitment_agency_meta( $agency, '_agency_wrc_licence' );
			$tel_uri         = conexao_recruitment_agency_tel_uri( $phone );
			$external_attrs  = ' target="_blank" rel="noopener noreferrer"';
			$new_tab_hint    = esc_attr__( '(abre em nova aba)', 'conexao-br-irlanda' );
		?>
		<article class="event-card agency-card">
			<div class="event-card-body agency-card-body">
				<h3 class="event-card-title agency-card-title">
					<a href="<?php echo esc_url( $website ); ?>" title="<?php echo esc_attr( $new_tab_hint ); ?>"<?php echo $external_attrs; ?>><?php echo $agency_name; ?></a>
				</h3>

				<?php if ( $job_types ) : ?>
					<p class="event-card-excerpt agency-card-job-types"><?php echo esc_html( $job_types ); ?></p>
				<?php endif; ?>

				<div class="agency-card-details">
					<?php if ( $temp || $perm ) : ?>
						<div class="agency-card-flags">
							<?php if ( $temp ) : ?>
								<span class="agency-card-flag agency-card-flag--temp"><?php esc_html_e( 'Temporário', 'conexao-br-irlanda' ); ?></span>
							<?php endif; ?>
							<?php if ( $perm ) : ?>
								<span class="agency-card-flag agency-card-flag--perm"><?php esc_html_e( 'Permanente', 'conexao-br-irlanda' ); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if ( $location ) : ?>
						<div class="event-card-detail agency-card-location">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
							<?php echo esc_html( $location ); ?>
						</div>
					<?php endif; ?>

					<?php if ( $tel_uri && $phone ) : ?>
						<div class="event-card-detail agency-card-phone">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
							<a href="<?php echo esc_url( $tel_uri ); ?>" rel="nofollow"><?php echo esc_html( $phone ); ?></a>
						</div>
					<?php endif; ?>

					<?php if ( $wrc ) : ?>
						<?php /* Licence numbers stay in the admin data only; visitors
						       see a simple verified-licence indicator instead. */ ?>
						<div class="event-card-detail agency-card-wrc">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
							<?php esc_html_e( 'Licenciada', 'conexao-br-irlanda' ); ?>
						</div>
					<?php endif; ?>
				</div>

				<a href="<?php echo esc_url( $website ); ?>" class="event-card-cta agency-card-cta" title="<?php echo esc_attr( $new_tab_hint ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Visitar site de %s', 'conexao-br-irlanda' ), $agency_name ) ); ?>"<?php echo $external_attrs; ?>>
					<?php esc_html_e( 'Visitar site', 'conexao-br-irlanda' ); ?>
					<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
						<line x1="5" y1="12" x2="19" y2="12"></line>
						<polyline points="12 5 19 12 12 19"></polyline>
					</svg>
					<span class="screen-reader-text"><?php esc_html_e( '(abre em nova aba)', 'conexao-br-irlanda' ); ?></span>
				</a>
			</div>
		</article>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
</section>
