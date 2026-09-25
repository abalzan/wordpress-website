<?php
/**
 * Template Name: Empregos — Página de Difusão
 *
 * Jobs Landing Page at /empregos/.
 *
 * A clean information hub for the Empregos section:
 *
 *   Empregos
 *     ↳ Portrait image         (featured image, Instagram-style 9:16)
 *     ↳ Jobs information       (editable page body via wp-admin)
 *     ↳ Instagram / county guidance
 *     ↳ [ Ver vagas no Instagram ] (optional CTA linked to the _empregos_link
 *                               field; hidden entirely when no link is configured)
 *
 * Everything here is driven by the standard WordPress page fields (title,
 * featured image, body content) plus one minimal CTA-link field. On desktop the
 * portrait sits beside the text in a two-column layout; on mobile it stacks into
 * a single natural column. Dark mode reuses the existing design-system tokens.
 *
 * @package Conexao_BR_Irlanda
 */

get_header();
?>

<div class="site-container">
	<main id="primary" class="content-area empregos-landing-page">

		<?php while ( have_posts() ) : the_post(); ?>

			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

				<header class="page-header">
					<span class="section-eyebrow"><?php esc_html_e( 'Oportunidades', 'conexao-br-irlanda' ); ?></span>
					<h1 class="entry-title"><?php the_title(); ?></h1>
				</header>

				<div class="empregos-landing">

					<?php if ( has_post_thumbnail() ) : ?>
						<div class="empregos-landing-media">
							<div class="empregos-portrait">
								<?php
								// Instagram-style portrait artwork (soft crop):
								// proportions preserved, never cropped or distorted.
								the_post_thumbnail(
									'conexao-job-portrait',
									array(
										'class'   => 'empregos-portrait-img',
										'loading' => 'eager',
									)
								);
								?>
							</div>
						</div>
					<?php endif; ?>

					<div class="empregos-landing-body">
						<div class="entry-content">
							<?php
							the_content();
							wp_link_pages(
								array(
									'before' => '<div class="page-links">' . esc_html__( 'Páginas:', 'conexao-br-irlanda' ),
									'after'  => '</div>',
								)
							);
							?>
						</div>

						<?php
						$empregos_cta = conexao_empregos_link();
						if ( $empregos_cta ) :
							$is_external = 0 === strpos( $empregos_cta, 'http' );
							?>
							<div class="empregos-landing-cta">
								<a
									class="btn btn-primary"
									href="<?php echo esc_url( $empregos_cta ); ?>"
									<?php if ( $is_external ) : ?>
										target="_blank" rel="noopener noreferrer"
									<?php endif; ?>
								>
									<?php esc_html_e( 'Ver vagas no Instagram', 'conexao-br-irlanda' ); ?>
									<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
										<line x1="5" y1="12" x2="19" y2="12"></line>
										<polyline points="12 5 19 12 12 19"></polyline>
									</svg>
								</a>
							</div>
						<?php endif; ?>
					</div>

				</div>

			</article>

			<?php if ( comments_open() || get_comments_number() ) : comments_template(); endif; ?>

		<?php endwhile; ?>

		<?php
		// "Onde procurar emprego" (external job-search sites) is INTENTIONALLY
		// not rendered on /empregos/ for now — the page goes straight from the
		// Instagram/vacancies landing content to the recruitment agencies.
		// Nothing was deleted: the reusable component lives at
		// template-parts/job-resources.php, the data in inc/job-resources.php
		// (conexao_job_resources option, editable under Empregos →
		// "Onde procurar emprego" in wp-admin) and the styles in main.css.
		// To bring the section back, restore:
		//   get_template_part( 'template-parts/job-resources' );
		?>

		<?php
		// "Vagas" / "Openings" preview section — INTENTIONALLY not rendered on
		// the Jobs landing page any more. This template renders BOTH languages
		// (/empregos/ and /en/jobs/), so the removal covers Portuguese and
		// English alike: the page now goes straight from the Instagram /
		// vacancies landing content to the unified employment opportunities
		// directory below.
		//
		// The removal is RENDERING-ONLY — nothing was deleted:
		//   - the job records themselves are untouched in the database;
		//   - the language-aware listing query is retained at
		//     inc/empregos-landing.php → conexao_empregos_current_jobs() (PT
		//     jobs on /empregos/, EN jobs on /en/jobs/, plus the approved B2 set
		//     while a job remains untranslated) and is still exercised by
		//     tests/test-job-en-translation.php;
		//   - the section styles are retained in main.css (.empregos-jobs*).
		// To bring the section back, restore the rendering block — the shared
		// .archive-grid/.archive-card cards, newest first, hidden when the query
		// returns no published job. See the git history of this file or
		// CONEXAO_BR_ENGLISH_JOBS_TRANSLATION_REPORT.md (Stage 6).
		?>

		<?php
		// "Oportunidades de emprego" — the UNIFIED employment opportunities
		// directory: recruitment agencies, official public-sector recruitment
		// portals and Employment Permit-history employers in ONE shared,
		// filterable grid (Tipo de oportunidade / Área / Localização /
		// Tipo de contrato, server-side via ?tipo=/?area=/?localizacao=/
		// ?contrato=). Replaces the three former separate sections.
		// Instagram remains the primary current-vacancy CTA above. Data and
		// filter logic live in inc/employment-opportunities.php +
		// inc/recruitment-agencies.php + inc/permit-employers.php.
		get_template_part( 'template-parts/employment-opportunities' );
		?>

	</main>
</div>

<?php get_template_part( 'template-parts/newsletter-section' ); ?>
<?php get_template_part( 'template-parts/quote-section' ); ?>

<?php get_footer();