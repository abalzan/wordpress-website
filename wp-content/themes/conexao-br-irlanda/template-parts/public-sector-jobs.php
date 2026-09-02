<?php
/**
 * Public-Sector Recruitment Section — "Trabalhe no setor público" (Empregos landing).
 *
 * A small, static list of OFFICIAL public-sector recruitment portals,
 * rendered between the recruitment agencies and the Employment Permit
 * employers sections. These are official recruitment portals — they are
 * NOT recruitment agencies and must never be described as one.
 *
 * Wording rules (do not weaken):
 * - Publicjobs.ie is the centralised recruitment provider for the Civil
 *   Service and also runs certain campaigns for Local Authorities, HSE,
 *   An Garda Síochána and other public-sector bodies. It is never
 *   described as holding every public-sector vacancy.
 * - HSE Jobs is the official HSE jobs portal.
 * - Local Government Jobs is the official portal of the Irish local
 *   authorities (verified 2026-09): https://www.localgovernmentjobs.ie/
 *
 * The data is intentionally static (three high-value official entry
 * points) — no CPT, no admin UI, no images, no JS. Cards reuse the
 * Event card visual language (.event-card*) exactly like the agencies
 * and permit-employers sections. External links open in a new tab with
 * rel="noopener noreferrer".
 *
 * @package Conexao_BR_Irlanda
 */

$public_sector_jobs = array(
	array(
		'name'        => 'Publicjobs.ie',
		'description' => __( 'Portal oficial para recrutamento do Civil Service e outras oportunidades no setor público irlandês.', 'conexao-br-irlanda' ),
		'url'         => 'https://publicjobs.ie/en/',
		'cta'         => __( 'Ver vagas', 'conexao-br-irlanda' ),
	),
	array(
		'name'        => 'HSE Jobs',
		'description' => __( 'Vagas no HSE em áreas de saúde, administração, serviços de apoio e outras funções.', 'conexao-br-irlanda' ),
		'url'         => 'https://about.hse.ie/jobs/',
		'cta'         => __( 'Ver vagas', 'conexao-br-irlanda' ),
	),
	array(
		'name'        => 'Local Government Jobs',
		'description' => __( 'Oportunidades de emprego nas autoridades locais da Irlanda.', 'conexao-br-irlanda' ),
		'url'         => 'https://www.localgovernmentjobs.ie/',
		'cta'         => __( 'Ver vagas', 'conexao-br-irlanda' ),
	),
);

if ( empty( $public_sector_jobs ) ) {
	return;
}

$new_tab_hint = esc_attr__( '(abre em nova aba)', 'conexao-br-irlanda' );
?>
<section class="empregos-public-sector" aria-labelledby="empregos-public-sector-title">
	<h2 id="empregos-public-sector-title" class="empregos-public-sector-title">
		<?php esc_html_e( 'Trabalhe no setor público', 'conexao-br-irlanda' ); ?>
	</h2>

	<p class="empregos-public-sector-intro">
		<?php esc_html_e( 'O setor público irlandês também oferece oportunidades em áreas como administração, saúde, serviços locais, educação, atendimento e funções de apoio. Consulte os portais oficiais para ver vagas e processos seletivos disponíveis.', 'conexao-br-irlanda' ); ?>
	</p>

	<div class="events-grid empregos-public-sector-grid">
		<?php foreach ( $public_sector_jobs as $public_sector_job ) : ?>
			<article class="event-card public-sector-card">
				<div class="event-card-body public-sector-card-body">
					<h3 class="event-card-title public-sector-card-title">
						<a href="<?php echo esc_url( $public_sector_job['url'] ); ?>" title="<?php echo esc_attr( $new_tab_hint ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $public_sector_job['name'] ); ?>
						</a>
					</h3>

					<p class="event-card-excerpt public-sector-card-description">
						<?php echo esc_html( $public_sector_job['description'] ); ?>
					</p>

					<a
						href="<?php echo esc_url( $public_sector_job['url'] ); ?>"
						class="event-card-cta public-sector-card-cta"
						title="<?php echo esc_attr( $new_tab_hint ); ?>"
						aria-label="<?php echo esc_attr( sprintf( __( 'Ver vagas em %s', 'conexao-br-irlanda' ), $public_sector_job['name'] ) ); ?>"
						target="_blank"
						rel="noopener noreferrer"
					>
						<?php echo esc_html( $public_sector_job['cta'] ); ?>
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
							<line x1="5" y1="12" x2="19" y2="12"></line>
							<polyline points="12 5 19 12 12 19"></polyline>
						</svg>
						<span class="screen-reader-text"><?php echo esc_html( $new_tab_hint ); ?></span>
					</a>
				</div>
			</article>
		<?php endforeach; ?>
	</div>
</section>