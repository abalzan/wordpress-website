<?php
/**
 * Employment Permit Employers Section — "Empresas com histórico de
 * Employment Permits" (Empregos landing).
 *
 * Renders EMPLOYERS (never recruitment agencies) as compact cards that
 * reuse the Event card visual language (.event-card*), below the
 * recruitment-agencies section. Content comes from
 * conexao_permit_employers() — the `permit_employer` CPT managed in
 * wp-admin under Empregos → "Empregadores — Employment Permits".
 *
 * Wording rules (do not weaken):
 * - The ✓ indicator means VERIFIED HISTORICAL permit evidence in the
 *   official Department of Enterprise statistics. It never means the
 *   employer is currently sponsoring; the shared explanation note carries
 *   that message so cards stay compact.
 * - No "sponsor/sponsorship/patrocinar" terminology anywhere.
 * - No salary thresholds, quotas, ratings or logos.
 * - Records with the 'exception' status render in the "Importante" block
 *   (e.g. Nua Healthcare) with their current-position statement.
 * - Records with the 'unverified' status render as plain employer
 *   entries with NO permit indicator (e.g. Kepak pending validation).
 *
 * External links open in a new tab with rel="noopener noreferrer".
 *
 * @package Conexao_BR_Irlanda
 */

$permit_employers = conexao_permit_employers();
if ( empty( $permit_employers ) ) {
	return;
}

$verified_employers  = array();
$exception_employers = array();
foreach ( $permit_employers as $employer ) {
	$status = conexao_permit_employer_status( $employer );
	if ( 'exception' === $status ) {
		$exception_employers[] = $employer;
		continue;
	}
	// 'verified' AND 'unverified' entries render inside the main grid —
	// the difference is only whether the permit indicator shows.
	$verified_employers[] = $employer;
}

$new_tab_hint = esc_attr__( '(abre em nova aba)', 'conexao-br-irlanda' );
?>
<section class="empregos-permit-employers" aria-labelledby="empregos-permit-employers-title">
	<h2 id="empregos-permit-employers-title" class="empregos-permit-employers-title">
		<?php esc_html_e( 'Empresas com histórico de Employment Permits', 'conexao-br-irlanda' ); ?>
	</h2>

	<p class="empregos-permit-employers-intro">
		<?php esc_html_e( 'Algumas empresas na Irlanda constam nos dados oficiais do Department of Enterprise como empregadores que já utilizaram o sistema de Employment Permits. Isso não significa que as vagas atuais ofereçam sponsorship: a possibilidade de obter um permit depende da função, do salário, do empregador e das regras vigentes.', 'conexao-br-irlanda' ); ?>
	</p>

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

	<?php if ( ! empty( $verified_employers ) ) : ?>
		<div class="empregos-permit-employers-note">
			<p>
				<span class="empregos-permit-indicator empregos-permit-indicator--verified" aria-hidden="true">✓ <?php esc_html_e( 'Histórico de Employment Permits', 'conexao-br-irlanda' ); ?></span>
				<?php esc_html_e( 'Há histórico oficial de Employment Permits para este empregador nos dados oficiais do Department of Enterprise — um registro do passado, não uma promessa de sponsorship atual. Algumas funções podem ser elegíveis para Employment Permit, dependendo dos requisitos da vaga e das regras vigentes.', 'conexao-br-irlanda' ); ?>
				<a href="https://enterprise.gov.ie/en/what-we-do/workplace-and-skills/employment-permits/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Consultar as regras oficiais vigentes', 'conexao-br-irlanda' ); ?><span class="screen-reader-text"> <?php echo esc_html( $new_tab_hint ); ?></span></a>
			</p>
		</div>

		<div class="events-grid empregos-permit-employers-grid">
			<?php foreach ( $verified_employers as $employer ) : ?>
				<?php
				$employer_name = esc_html( $employer->post_title );
				$sector        = conexao_permit_employer_meta( $employer, '_employer_sector' );
				$roles         = conexao_permit_employer_roles( $employer );
				$roles_text    = $roles ? implode( ', ', $roles ) : '';
				$location      = conexao_permit_employer_meta( $employer, '_employer_location' );
				$website       = esc_url( conexao_permit_employer_meta( $employer, '_employer_official_website' ) );
				$careers       = esc_url( conexao_permit_employer_meta( $employer, '_employer_careers_url' ) );
				$status        = conexao_permit_employer_status( $employer );
				$years         = conexao_permit_employer_meta( $employer, '_employer_evidence_years' );
				$is_verified   = ( 'verified' === $status );
				if ( empty( $website ) ) {
					continue;
				}
				?>
				<article class="event-card employer-card">
					<div class="event-card-body employer-card-body">
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
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $exception_employers ) ) : ?>
		<div class="empregos-permit-exceptions">
			<h3 class="empregos-permit-exceptions-title"><?php esc_html_e( 'Importante', 'conexao-br-irlanda' ); ?></h3>
			<?php foreach ( $exception_employers as $employer ) : ?>
				<?php
				if ( 'exception' !== conexao_permit_employer_status( $employer ) ) {
					continue;
				}
				$employer_name = esc_html( $employer->post_title );
				$sector        = conexao_permit_employer_meta( $employer, '_employer_sector' );
				$location      = conexao_permit_employer_meta( $employer, '_employer_location' );
				$website       = esc_url( conexao_permit_employer_meta( $employer, '_employer_official_website' ) );
				$years         = conexao_permit_employer_meta( $employer, '_employer_evidence_years' );
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
