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
 * @package Conexao_BR_Irlanda
 */

$agencies = conexao_recruitment_agencies();
if ( empty( $agencies ) ) {
	return;
}
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
	<div class="events-grid empregos-recruitment-agencies-grid">
		<?php foreach ( $agencies as $agency ) :
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
			if ( empty( $website ) ) {
				continue;
			}
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
						<div class="event-card-detail agency-card-wrc">
							<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
							<?php echo esc_html( $wrc ); ?>
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
</section>
