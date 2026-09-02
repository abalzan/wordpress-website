<?php
/**
 * Unified Employment Opportunities Directory — /empregos/.
 *
 * ONE coherent directory for every employment resource on the page,
 * rendered as a single grid with a single shared filter system:
 *
 *   - agency          — recruitment agencies (`recruitment_agency` CPT)
 *   - public_sector   — official public-sector recruitment portals
 *                       (never described as agencies)
 *   - permit_history  — employers with verified HISTORICAL Employment
 *                       Permit evidence in the official DETE statistics
 *                       (never presented as current sponsorship)
 *
 * Data comes from inc/employment-opportunities.php (the shared model
 * layer), which reuses the existing per-type data helpers — no duplicated
 * data, no invented attributes.
 *
 * Filters extend the existing recruitment-agency filter architecture
 * (desktop hyperlink dropdowns + mobile bottom sheet, server-side and URL
 * driven, AND logic between dimensions):
 *
 *   ?tipo=agency|public_sector|permit_history — "Tipo de oportunidade"
 *     ('permit_history' matches the structured has_permit_history flag,
 *     never a visible card label)
 *   ?area= / ?localizacao= / ?contrato= — the existing agency dimensions,
 *     unchanged and backward compatible. They apply only where structured
 *     data exists (the agencies); when a single non-agency type is
 *     selected they cannot match anything and their controls are hidden
 *     (active values still show as removable chips).
 *
 * Wording rules (do not weaken):
 * - The recruitment-agency warning stays associated with agency resources.
 * - "Histórico de Employment Permits" means historical DETE evidence only;
 *   it never implies current sponsorship. No "sponsor/sponsorship"
 *   terminology is introduced anywhere.
 * - Exception employers (e.g. a current-position statement) render ONLY in
 *   the "Importante" block below the grid, never as directory cards.
 *
 * Cards reuse the Event card visual language (.event-card*) with narrow
 * type modifiers (.agency-card / .public-sector-card /
 * .permit-employer-card) and a small non-color type label so resource
 * types stay understandable without relying on color alone.
 *
 * @package Conexao_BR_Irlanda
 */

$state = conexao_employment_opportunities_filter_state();

// Cards actually rendered. Render rules carried over from the previous
// sections: a resource without an external URL has no usable card, and
// 'exception' permit employers are presented only in the "Importante"
// block below the grid (their current-position statement must stay
// prominent, and they are NOT presented as directory entries).
$opportunities = array();
foreach ( $state['opportunities'] as $item ) {
	if ( '' === trim( (string) $item['url'] ) ) {
		continue;
	}
	if ( 'permit_history' === $item['resource_type'] && 'exception' === ( $item['meta']['permit_status'] ?? '' ) ) {
		continue;
	}
	$opportunities[] = $item;
}

$has_active_filters = ( '' !== $state['tipo'] || '' !== $state['area'] || '' !== $state['location'] || '' !== $state['contrato'] );

// Every filter URL points back to this section so a filter change (desktop
// link, chip or the mobile form) lands the user on the directory.
$page_url       = get_permalink( get_queried_object_id() );
$section_anchor = '#empregos-opportunities-title';

/**
 * Build a filter URL preserving the given state ('' drops the dimension).
 */
$opportunity_filter_url = static function ( $tipo, $area, $location, $contrato ) use ( $page_url, $section_anchor ) {
	$args = array();
	if ( '' !== $tipo ) {
		$args['tipo'] = $tipo;
	}
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
$clear_url = $opportunity_filter_url( '', '', '', '' );

// Single-filter removal URLs for the active-filter chips.
$remove_tipo_url     = $opportunity_filter_url( '', $state['area'], $state['location'], $state['contrato'] );
$remove_area_url     = $opportunity_filter_url( $state['tipo'], '', $state['location'], $state['contrato'] );
$remove_location_url = $opportunity_filter_url( $state['tipo'], $state['area'], '', $state['contrato'] );
$remove_contrato_url = $opportunity_filter_url( $state['tipo'], $state['area'], $state['location'], '' );

// Resolved display names for the active chips / triggers.
$active_tipo_name     = $state['tipo'] ? $state['tipo_options'][ $state['tipo'] ] : '';
$active_area_name     = $state['area'] ? $state['area_options'][ $state['area'] ] : '';
$active_location_name = $state['location'] ? $state['location_options'][ $state['location'] ] : '';
$active_contrato_name = $state['contrato'] ? $state['contrato_options'][ $state['contrato'] ] : '';

// Trigger labels: group name when inactive, chosen value when active.
$tipo_trigger_label     = $active_tipo_name ? $active_tipo_name : __( 'Tipo de oportunidade', 'conexao-br-irlanda' );
$area_trigger_label     = $active_area_name ? $active_area_name : __( 'Área de trabalho', 'conexao-br-irlanda' );
$location_trigger_label = $active_location_name ? $active_location_name : __( 'Localização', 'conexao-br-irlanda' );
$contrato_trigger_label = $active_contrato_name ? $active_contrato_name : __( 'Tipo de contrato', 'conexao-br-irlanda' );

// Screen-reader labels for the triggers — the dot indicator is decorative, so
// the active state is also announced as text.
$tipo_trigger_aria = $active_tipo_name
	? sprintf( __( 'Filtrar por Tipo de oportunidade. Filtro ativo: %s', 'conexao-br-irlanda' ), $active_tipo_name )
	: __( 'Filtrar por Tipo de oportunidade', 'conexao-br-irlanda' );
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
$active_filter_count = ( $state['tipo'] ? 1 : 0 ) + ( $state['area'] ? 1 : 0 ) + ( $state['location'] ? 1 : 0 ) + ( $state['contrato'] ? 1 : 0 );

// Client-side search only makes sense for a long location list.
$show_location_search = count( $state['location_options'] ) > 8;

// Área/Localização/Contrato apply only where structured data exists — the
// agencies. When a single non-agency type is selected these dimensions can
// never match, so their dropdowns/fieldsets are hidden instead of showing
// controls that cannot affect the result. Active values still render as
// removable chips below.
$show_dimension_filters = ( '' === $state['tipo'] || 'agency' === $state['tipo'] );

$opportunity_count = count( $opportunities );

// Small, non-color type label shown on every card so the resource type
// stays understandable in the unified grid (permit-history cards keep the
// structured ✓ indicator as their evidence marker — the card label is
// "Empregador", since unverified employers carry no permit evidence).
$card_type_labels = array(
	'agency'         => __( 'Agência de recrutamento', 'conexao-br-irlanda' ),
	'public_sector'  => __( 'Setor público', 'conexao-br-irlanda' ),
	'permit_history' => __( 'Empregador', 'conexao-br-irlanda' ),
);

$new_tab_hint = esc_attr__( '(abre em nova aba)', 'conexao-br-irlanda' );

// "Importante" block (exception employers): context, not filtered results —
// derived from the full collection and shown whenever the permit-history
// type is not filtered out by ?tipo=.
$exception_employers = array();
if ( '' === $state['tipo'] || 'permit_history' === $state['tipo'] ) {
	foreach ( conexao_employment_opportunities( array( 'permit_history' ) ) as $item ) {
		if ( 'exception' === ( $item['meta']['permit_status'] ?? '' ) ) {
			$exception_employers[] = $item;
		}
	}
}
?>
<section class="empregos-opportunities" aria-labelledby="empregos-opportunities-title">
	<span id="empregos-agencies-title"></span>
	<span id="empregos-public-sector-title"></span>
	<span id="empregos-permit-employers-title"></span>
	<h2 id="empregos-opportunities-title" class="empregos-opportunities-title">
		<?php esc_html_e( 'Oportunidades de emprego', 'conexao-br-irlanda' ); ?>

	<div class="empregos-opportunities-context">
		<p class="empregos-opportunities-context-agencies">
			<?php esc_html_e( 'Nas agências de recrutamento: algumas trabalham com vagas temporárias e permanentes em áreas como armazém, produção, logística, hotelaria, limpeza, varejo e funções operacionais.', 'conexao-br-irlanda' ); ?>
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
		<div class="empregos-permit-employers-warning">
			<p>
				<?php esc_html_e( '⚠️ Nenhuma empresa ou agência pode garantir a aprovação de um Employment Permit. A elegibilidade depende da vaga, do empregador, da remuneração, das qualificações e das regras vigentes na Irlanda.', 'conexao-br-irlanda' ); ?>
			</p>
		</div>
		<div class="empregos-permit-employers-warning">
			<p>
				<?php esc_html_e( '⚠️ Nunca pague a intermediários por uma promessa de emprego, visto ou Employment Permit. Desconfie de ofertas que garantam "visto fácil" ou aprovação garantida.', 'conexao-br-irlanda' ); ?>
			</p>
		</div>
		<div class="empregos-permit-employers-note">
			<p>
				<span class="empregos-permit-indicator empregos-permit-indicator--verified" aria-hidden="true">✓ <?php esc_html_e( 'Histórico de Employment Permits', 'conexao-br-irlanda' ); ?></span>
				<?php esc_html_e( 'Há histórico oficial de Employment Permits para este empregador nos dados oficiais do Department of Enterprise — um registro do passado, não uma promessa de sponsorship atual. Algumas funções podem ser elegíveis para Employment Permit, dependendo dos requisitos da vaga e das regras vigentes.', 'conexao-br-irlanda' ); ?>
				<a href="https://enterprise.gov.ie/en/what-we-do/workplace-and-skills/employment-permits/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Consultar as regras oficiais vigentes', 'conexao-br-irlanda' ); ?><span class="screen-reader-text"> <?php echo esc_html( $new_tab_hint ); ?></span></a>
			</p>
		</div>
	</div>

	</h2>
	<p class="empregos-opportunities-intro">
		<?php esc_html_e( 'Um diretório único com três caminhos diferentes: agências de recrutamento, portais oficiais do setor público e empresas com histórico de Employment Permits. Use os filtros para encontrar o que faz sentido para o seu perfil.', 'conexao-br-irlanda' ); ?>
	</p>

	<div class="agency-filters" data-agency-filters>
		<div class="agency-filters-toolbar">
			<div class="agency-filters-group">
				<div class="agency-filters-dropdown" data-dropdown>
					<button
						type="button"
						class="agency-filters-dropdown-trigger<?php echo $state['tipo'] ? ' is-selected' : ''; ?>"
						aria-haspopup="listbox"
						aria-expanded="false"
						aria-controls="empregos-opportunities-tipo-panel"
						aria-label="<?php echo esc_attr( $tipo_trigger_aria ); ?>"
						data-dropdown-trigger>
						<?php if ( $state['tipo'] ) : ?>
							<span class="agency-filters-dot" aria-hidden="true"></span>
						<?php endif; ?>
						<span class="agency-filters-dropdown-label"><?php echo esc_html( $tipo_trigger_label ); ?></span>
						<span class="agency-filters-dropdown-caret" aria-hidden="true">▾</span>
					</button>

					<div class="agency-filters-dropdown-panel" id="empregos-opportunities-tipo-panel" data-dropdown-panel>
						<div class="agency-filters-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'Tipo de oportunidade', 'conexao-br-irlanda' ); ?>">
							<a class="agency-filters-dropdown-link <?php echo '' === $state['tipo'] ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo '' === $state['tipo'] ? 'true' : 'false'; ?>" href="<?php echo esc_url( $remove_tipo_url ); ?>">
								<span class="agency-filters-checkmark" aria-hidden="true"><?php echo '' === $state['tipo'] ? '✓' : ''; ?></span>
								<span><?php echo esc_html( $state['tipo_options'][''] ); ?></span>
							</a>
							<?php foreach ( $state['tipo_options'] as $tipo_slug => $tipo_label ) : ?>
								<?php if ( '' === $tipo_slug ) { continue; } ?>
								<?php $tipo_is_active = ( $state['tipo'] === $tipo_slug ); ?>
								<a class="agency-filters-dropdown-link <?php echo $tipo_is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $tipo_is_active ? 'true' : 'false'; ?>" href="<?php echo esc_url( $opportunity_filter_url( $tipo_slug, $state['area'], $state['location'], $state['contrato'] ) ); ?>">
									<span class="agency-filters-checkmark" aria-hidden="true"><?php echo $tipo_is_active ? '✓' : ''; ?></span>
									<span><?php echo esc_html( $tipo_label ); ?></span>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
			</div>
			<?php if ( $show_dimension_filters && ! empty( $state['area_options'] ) ) : ?>
				<div class="agency-filters-group">
					<div class="agency-filters-dropdown" data-dropdown>
						<button
							type="button"
							class="agency-filters-dropdown-trigger<?php echo $state['area'] ? ' is-selected' : ''; ?>"
							aria-haspopup="listbox"
							aria-expanded="false"
							aria-controls="empregos-opportunities-area-panel"
							aria-label="<?php echo esc_attr( $area_trigger_aria ); ?>"
							data-dropdown-trigger>
							<?php if ( $state['area'] ) : ?>
								<span class="agency-filters-dot" aria-hidden="true"></span>
							<?php endif; ?>
							<span class="agency-filters-dropdown-label"><?php echo esc_html( $area_trigger_label ); ?></span>
							<span class="agency-filters-dropdown-caret" aria-hidden="true">▾</span>
						</button>

						<div class="agency-filters-dropdown-panel" id="empregos-opportunities-area-panel" data-dropdown-panel>
							<div class="agency-filters-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'Área de trabalho', 'conexao-br-irlanda' ); ?>">
								<a class="agency-filters-dropdown-link <?php echo '' === $state['area'] ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo '' === $state['area'] ? 'true' : 'false'; ?>" href="<?php echo esc_url( $remove_area_url ); ?>">
									<span class="agency-filters-checkmark" aria-hidden="true"><?php echo '' === $state['area'] ? '✓' : ''; ?></span>
									<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
								</a>
								<?php foreach ( $state['area_options'] as $area_slug => $area_label ) : ?>
									<?php $area_is_active = ( $state['area'] === $area_slug ); ?>
									<a class="agency-filters-dropdown-link <?php echo $area_is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $area_is_active ? 'true' : 'false'; ?>" href="<?php echo esc_url( $opportunity_filter_url( $state['tipo'], $area_slug, $state['location'], $state['contrato'] ) ); ?>">
										<span class="agency-filters-checkmark" aria-hidden="true"><?php echo $area_is_active ? '✓' : ''; ?></span>
										<span><?php echo esc_html( $area_label ); ?></span>
									</a>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $show_dimension_filters && ! empty( $state['location_options'] ) ) : ?>
				<div class="agency-filters-group">
					<div class="agency-filters-dropdown" data-dropdown>
						<button
							type="button"
							class="agency-filters-dropdown-trigger<?php echo $state['location'] ? ' is-selected' : ''; ?>"
							aria-haspopup="listbox"
							aria-expanded="false"
							aria-controls="empregos-opportunities-location-panel"
							aria-label="<?php echo esc_attr( $location_trigger_aria ); ?>"
							data-dropdown-trigger>
							<?php if ( $state['location'] ) : ?>
								<span class="agency-filters-dot" aria-hidden="true"></span>
							<?php endif; ?>
							<span class="agency-filters-dropdown-label"><?php echo esc_html( $location_trigger_label ); ?></span>
							<span class="agency-filters-dropdown-caret" aria-hidden="true">▾</span>
						</button>

						<div class="agency-filters-dropdown-panel" id="empregos-opportunities-location-panel" data-dropdown-panel>
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
								<a class="agency-filters-dropdown-link <?php echo '' === $state['location'] ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo '' === $state['location'] ? 'true' : 'false'; ?>" href="<?php echo esc_url( $remove_location_url ); ?>">
									<span class="agency-filters-checkmark" aria-hidden="true"><?php echo '' === $state['location'] ? '✓' : ''; ?></span>
									<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
								</a>
								<?php foreach ( $state['location_options'] as $location_slug => $location_label ) : ?>
									<?php $location_is_active = ( $state['location'] === $location_slug ); ?>
									<a class="agency-filters-dropdown-link <?php echo $location_is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $location_is_active ? 'true' : 'false'; ?>" data-option-item href="<?php echo esc_url( $opportunity_filter_url( $state['tipo'], $state['area'], $location_slug, $state['contrato'] ) ); ?>">
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
			<?php if ( $show_dimension_filters && ! empty( $state['contrato_options'] ) ) : ?>
				<div class="agency-filters-group">
					<div class="agency-filters-dropdown" data-dropdown>
						<button
							type="button"
							class="agency-filters-dropdown-trigger<?php echo $state['contrato'] ? ' is-selected' : ''; ?>"
							aria-haspopup="listbox"
							aria-expanded="false"
							aria-controls="empregos-opportunities-contrato-panel"
							aria-label="<?php echo esc_attr( $contrato_trigger_aria ); ?>"
							data-dropdown-trigger>
							<?php if ( $state['contrato'] ) : ?>
								<span class="agency-filters-dot" aria-hidden="true"></span>
							<?php endif; ?>
							<span class="agency-filters-dropdown-label"><?php echo esc_html( $contrato_trigger_label ); ?></span>
							<span class="agency-filters-dropdown-caret" aria-hidden="true">▾</span>
						</button>

						<div class="agency-filters-dropdown-panel" id="empregos-opportunities-contrato-panel" data-dropdown-panel>
							<div class="agency-filters-dropdown-list" role="listbox" aria-label="<?php esc_attr_e( 'Tipo de contrato', 'conexao-br-irlanda' ); ?>">
								<a class="agency-filters-dropdown-link <?php echo '' === $state['contrato'] ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo '' === $state['contrato'] ? 'true' : 'false'; ?>" href="<?php echo esc_url( $remove_contrato_url ); ?>">
									<span class="agency-filters-checkmark" aria-hidden="true"><?php echo '' === $state['contrato'] ? '✓' : ''; ?></span>
									<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
								</a>
								<?php foreach ( $state['contrato_options'] as $contrato_slug => $contrato_label ) : ?>
									<?php $contrato_is_active = ( $state['contrato'] === $contrato_slug ); ?>
									<a class="agency-filters-dropdown-link <?php echo $contrato_is_active ? 'is-active' : ''; ?>" role="option" aria-selected="<?php echo $contrato_is_active ? 'true' : 'false'; ?>" href="<?php echo esc_url( $opportunity_filter_url( $state['tipo'], $state['area'], $state['location'], $contrato_slug ) ); ?>">
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
					<?php if ( $state['tipo'] ) : ?>
						<a class="agency-filters-chip" href="<?php echo esc_url( $remove_tipo_url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_tipo_name ) ); ?>">
							<span class="agency-filters-chip-name"><?php echo esc_html( $active_tipo_name ); ?></span>
							<span class="agency-filters-chip-remove" aria-hidden="true">×</span>
						</a>
					<?php endif; ?>
					<?php if ( $state['area'] ) : ?>
						<a class="agency-filters-chip" href="<?php echo esc_url( $remove_area_url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_area_name ) ); ?>">
							<span class="agency-filters-chip-name"><?php echo esc_html( $active_area_name ); ?></span>
							<span class="agency-filters-chip-remove" aria-hidden="true">×</span>
						</a>
					<?php endif; ?>
					<?php if ( $state['location'] ) : ?>
						<a class="agency-filters-chip" href="<?php echo esc_url( $remove_location_url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remover filtro: %s', 'conexao-br-irlanda' ), $active_location_name ) ); ?>">
							<span class="agency-filters-chip-name"><?php echo esc_html( $active_location_name ); ?></span>
							<span class="agency-filters-chip-remove" aria-hidden="true">×</span>
						</a>
					<?php endif; ?>
					<?php if ( $state['contrato'] ) : ?>
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
				/* translators: %s: number of opportunities. */
				_n( '%s oportunidade encontrada', '%s oportunidades encontradas', $opportunity_count, 'conexao-br-irlanda' ),
				number_format_i18n( $opportunity_count )
			);
			?>
		</p>
		<div class="agency-filters-mobile" data-mobile-filter>
			<button
				type="button"
				class="agency-filters-mobile-trigger"
				aria-haspopup="dialog"
				aria-expanded="false"
				aria-controls="empregos-opportunities-sheet"
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
				<div class="agency-filters-sheet-panel" role="dialog" aria-modal="true" aria-labelledby="empregos-opportunities-sheet-title">
					<div class="agency-filters-sheet-header">
						<div class="agency-filters-sheet-handle" aria-hidden="true"></div>
						<h3 class="agency-filters-sheet-title" id="empregos-opportunities-sheet-title"><?php esc_html_e( 'Filtros', 'conexao-br-irlanda' ); ?></h3>
						<button type="button" class="agency-filters-sheet-close" data-mobile-close aria-label="<?php esc_attr_e( 'Fechar filtros', 'conexao-br-irlanda' ); ?>">
							<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
								<line x1="6" y1="6" x2="18" y2="18"></line>
								<line x1="18" y1="6" x2="6" y2="18"></line>
							</svg>
						</button>
					</div>

					<form method="get" action="<?php echo esc_url( $page_url . $section_anchor ); ?>" class="agency-filters-mobile-form" data-mobile-form>
						<div class="agency-filters-sheet-body">
							<fieldset class="agency-filters-mobile-section">
								<legend class="agency-filters-mobile-legend"><?php esc_html_e( 'Tipo de oportunidade', 'conexao-br-irlanda' ); ?></legend>
								<div class="agency-filters-options">
									<?php foreach ( $state['tipo_options'] as $tipo_slug => $tipo_label ) : ?>
										<label class="agency-filters-option">
											<input class="agency-filters-radio" type="radio" name="tipo" value="<?php echo esc_attr( $tipo_slug ); ?>" <?php checked( $state['tipo'] === $tipo_slug ); ?>>
											<span><?php echo esc_html( $tipo_label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>


							<?php if ( $show_dimension_filters && ! empty( $state['area_options'] ) ) : ?>
								<fieldset class="agency-filters-mobile-section">
									<legend class="agency-filters-mobile-legend"><?php esc_html_e( 'Área de trabalho', 'conexao-br-irlanda' ); ?></legend>
									<div class="agency-filters-options">
										<label class="agency-filters-option">
											<input class="agency-filters-radio" type="radio" name="area" value="" <?php checked( '' === $state['area'] ); ?>>
											<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
										</label>
										<?php foreach ( $state['area_options'] as $area_slug => $area_label ) : ?>
											<label class="agency-filters-option">
												<input class="agency-filters-radio" type="radio" name="area" value="<?php echo esc_attr( $area_slug ); ?>" <?php checked( $state['area'] === $area_slug ); ?>>
												<span><?php echo esc_html( $area_label ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
								</fieldset>
							<?php endif; ?>

							<?php if ( $show_dimension_filters && ! empty( $state['location_options'] ) ) : ?>
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
											<input class="agency-filters-radio" type="radio" name="localizacao" value="" <?php checked( '' === $state['location'] ); ?>>
											<span><?php esc_html_e( 'Todas', 'conexao-br-irlanda' ); ?></span>
										</label>
										<?php foreach ( $state['location_options'] as $location_slug => $location_label ) : ?>
											<label class="agency-filters-option" data-option-item>
												<input class="agency-filters-radio" type="radio" name="localizacao" value="<?php echo esc_attr( $location_slug ); ?>" <?php checked( $state['location'] === $location_slug ); ?>>
												<span><?php echo esc_html( $location_label ); ?></span>
											</label>
										<?php endforeach; ?>
									</div>
									<?php if ( $show_location_search ) : ?>
										<p class="agency-filters-options-empty" data-option-empty hidden><?php esc_html_e( 'Nenhuma localização encontrada', 'conexao-br-irlanda' ); ?></p>
									<?php endif; ?>
								</fieldset>
							<?php endif; ?>


							<?php if ( $show_dimension_filters && ! empty( $state['contrato_options'] ) ) : ?>
								<fieldset class="agency-filters-mobile-section">
									<legend class="agency-filters-mobile-legend"><?php esc_html_e( 'Tipo de contrato', 'conexao-br-irlanda' ); ?></legend>
									<div class="agency-filters-options">
										<label class="agency-filters-option">
											<input class="agency-filters-radio" type="radio" name="contrato" value="" <?php checked( '' === $state['contrato'] ); ?>>
											<span><?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?></span>
										</label>
										<?php foreach ( $state['contrato_options'] as $contrato_slug => $contrato_label ) : ?>
											<label class="agency-filters-option">
												<input class="agency-filters-radio" type="radio" name="contrato" value="<?php echo esc_attr( $contrato_slug ); ?>" <?php checked( $state['contrato'] === $contrato_slug ); ?>>
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

	<?php if ( empty( $opportunities ) ) : ?>
		<div class="agency-filters-empty">
			<p class="agency-filters-empty-text"><?php esc_html_e( 'Nenhuma oportunidade encontrada com esses filtros.', 'conexao-br-irlanda' ); ?></p>
			<a class="agency-filters-empty-clear" href="<?php echo esc_url( $clear_url ); ?>">
				<?php esc_html_e( 'Limpar filtros', 'conexao-br-irlanda' ); ?>
			</a>
		</div>
	<?php else : ?>
	<div class="events-grid empregos-opportunities-grid">
		<?php foreach ( $opportunities as $item ) : ?>
			<?php if ( 'agency' === $item['resource_type'] ) : ?>
				<?php
				$agency          = $item['_source'];
				$agency_name     = esc_html( $item['title'] );
				$job_type_labels = conexao_recruitment_agency_job_type_labels( conexao_recruitment_agency_meta( $agency, '_agency_job_types' ) );
				$job_types       = $job_type_labels ? implode( ', ', $job_type_labels ) : '';
				$location        = conexao_recruitment_agency_meta( $agency, '_agency_location' );
				$phone           = conexao_recruitment_agency_meta( $agency, '_agency_phone' );
				$website         = esc_url( $item['url'] );
				$temp            = in_array( 'temporario', (array) $item['contract_types'], true );
				$perm            = in_array( 'permanente', (array) $item['contract_types'], true );
				$wrc             = conexao_recruitment_agency_meta( $agency, '_agency_wrc_licence' );
				$tel_uri         = conexao_recruitment_agency_tel_uri( $phone );
				$external_attrs  = ' target="_blank" rel="noopener noreferrer"';
				?>
				<article class="event-card agency-card">
					<div class="event-card-body agency-card-body">
						<p class="opportunity-card-type"><?php echo esc_html( $card_type_labels['agency'] ); ?></p>
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
			<?php elseif ( 'public_sector' === $item['resource_type'] ) : ?>

				<article class="event-card public-sector-card">
					<div class="event-card-body public-sector-card-body">
						<p class="opportunity-card-type"><?php echo esc_html( $card_type_labels['public_sector'] ); ?></p>
						<h3 class="event-card-title public-sector-card-title">
							<a href="<?php echo esc_url( $item['url'] ); ?>" title="<?php echo esc_attr( $new_tab_hint ); ?>" target="_blank" rel="noopener noreferrer">
								<?php echo esc_html( $item['title'] ); ?>
							</a>
						</h3>

						<p class="event-card-excerpt public-sector-card-description">
							<?php echo esc_html( $item['description'] ); ?>
						</p>

						<a
							href="<?php echo esc_url( $item['url'] ); ?>"
							class="event-card-cta public-sector-card-cta"
							title="<?php echo esc_attr( $new_tab_hint ); ?>"
							aria-label="<?php echo esc_attr( sprintf( __( 'Ver vagas em %s', 'conexao-br-irlanda' ), $item['title'] ) ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						>
							<?php esc_html_e( 'Ver vagas', 'conexao-br-irlanda' ); ?>
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
								<line x1="5" y1="12" x2="19" y2="12"></line>
								<polyline points="12 5 19 12 12 19"></polyline>
							</svg>
							<span class="screen-reader-text"><?php echo esc_html( $new_tab_hint ); ?></span>
						</a>
					</div>
				</article>
			<?php else : ?>
				<?php
				$employer_name = esc_html( $item['title'] );
				$sector        = (string) ( $item['meta']['sector'] ?? '' );
				$roles         = (array) ( $item['meta']['roles'] ?? array() );
				$roles_text    = $roles ? implode( ', ', $roles ) : '';
				$location      = (string) ( $item['meta']['location_display'] ?? '' );
				$website       = esc_url( $item['url'] );
				$careers       = esc_url( (string) ( $item['meta']['careers_url'] ?? '' ) );
				$is_verified   = ! empty( $item['meta']['is_verified'] );
				$years         = (string) ( $item['meta']['evidence_years'] ?? '' );
				?>
				<article class="event-card permit-employer-card employer-card">
					<div class="event-card-body employer-card-body">
						<p class="opportunity-card-type"><?php echo esc_html( $card_type_labels['permit_history'] ); ?></p>
						<h3 class="event-card-title employer-card-title">
							<a href="<?php echo esc_url( $website ); ?>" title="<?php echo esc_attr( $new_tab_hint ); ?>" target="_blank" rel="noopener noreferrer"><?php echo $employer_name; ?></a>
						</h3>

						<?php if ( $is_verified ) : ?>
							<div class="employer-card-permit">
								<span class="empregos-permit-indicator empregos-permit-indicator--verified">✓ <?php esc_html_e( 'Histórico de Employment Permits', 'conexao-br-irlanda' ); ?></span>
								<?php if ( $years ) : ?>
									<span class="employer-card-permit-years"><?php echo esc_html( sprintf( __( 'registro: %s', 'conexao-br-irlanda' ), $years ) ); ?></span>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<?php if ( $sector ) : ?>
							<p class="event-card-excerpt employer-card-sector"><?php echo esc_html( $sector ); ?></p>
						<?php endif; ?>


						<div class="agency-card-details">
							<?php if ( $roles_text ) : ?>
								<div class="event-card-detail employer-card-roles">
									<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
									<?php echo esc_html( $roles_text ); ?>
								</div>
							<?php endif; ?>

							<?php if ( $location ) : ?>
								<div class="event-card-detail employer-card-location">
									<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
									<?php echo esc_html( $location ); ?>
								</div>
							<?php endif; ?>
						</div>

						<div class="employer-card-links">
							<a href="<?php echo esc_url( $website ); ?>" class="event-card-cta employer-card-cta" title="<?php echo esc_attr( $new_tab_hint ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Visitar site de %s', 'conexao-br-irlanda' ), $employer_name ) ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'Visitar site', 'conexao-br-irlanda' ); ?>
								<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
									<line x1="5" y1="12" x2="19" y2="12"></line>
									<polyline points="12 5 19 12 12 19"></polyline>
								</svg>
								<span class="screen-reader-text"><?php echo esc_html( $new_tab_hint ); ?></span>
							</a>
							<?php if ( $careers ) : ?>
								<a href="<?php echo esc_url( $careers ); ?>" class="event-card-cta employer-card-cta employer-card-cta--secondary" title="<?php echo esc_attr( $new_tab_hint ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Ver vagas no site de %s', 'conexao-br-irlanda' ), $employer_name ) ); ?>" target="_blank" rel="noopener noreferrer">
									<?php esc_html_e( 'Vagas', 'conexao-br-irlanda' ); ?>
									<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
										<line x1="5" y1="12" x2="19" y2="12"></line>
										<polyline points="12 5 19 12 12 19"></polyline>
									</svg>
									<span class="screen-reader-text"><?php echo esc_html( $new_tab_hint ); ?></span>
								</a>
							<?php endif; ?>
						</div>
					</div>
				</article>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>

	<?php if ( ! empty( $exception_employers ) ) : ?>
		<div class="empregos-permit-exceptions">
			<h3 class="empregos-permit-exceptions-title"><?php esc_html_e( 'Importante', 'conexao-br-irlanda' ); ?></h3>
			<?php foreach ( $exception_employers as $item ) : ?>
				<?php
				$employer_name = esc_html( $item['title'] );
				$sector        = (string) ( $item['meta']['sector'] ?? '' );
				$location      = (string) ( $item['meta']['location_display'] ?? '' );
				$website       = esc_url( (string) $item['url'] );
				$years         = (string) ( $item['meta']['evidence_years'] ?? '' );
				?>
				<div class="empregos-permit-exceptions-item">
					<p class="empregos-permit-exceptions-employer">
						<?php if ( $website ) : ?>
							<a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer"><?php echo $employer_name; ?></a><span class="screen-reader-text"> <?php echo esc_html( $new_tab_hint ); ?></span>
						<?php else : ?>
							<?php echo $employer_name; ?>
						<?php endif; ?>
						<?php if ( $sector ) : ?>
							<span class="empregos-permit-exceptions-meta"> — <?php echo esc_html( $sector ); ?><?php echo $location ? ', ' . esc_html( $location ) : ''; ?></span>
						<?php endif; ?>
					</p>
					<p class="empregos-permit-exceptions-note">
						<?php
						echo esc_html( sprintf(
							/* translators: %s: years with official records, e.g. "2023–2025". */
							__( 'Aparece nos dados oficiais de Employment Permits (%s), mas, segundo informação oficial da empresa, NÃO está atualmente patrocinando Employment Permits para recrutamento internacional.', 'conexao-br-irlanda' ),
							$years ? $years : __( 'anos anteriores', 'conexao-br-irlanda' )
						) );
						?>
					</p>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>

