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
		// "Vagas" — the real Job CPT records, in the current language
		// (Stage 6). The PT page lists the PT jobs; the EN page lists the EN
		// jobs (plus, while one remains untranslated, its PT original as the
		// approved B2 fallback set — never both languages of one identity).
		// Cards reuse the shared archive-card markup and design tokens; every
		// card opens the job detail in the card's own language. Hidden entirely
		// when no published job exists (the existing empty-state convention).
		$conexao_empregos_jobs = function_exists( 'conexao_empregos_current_jobs' ) ? conexao_empregos_current_jobs() : array();
		if ( ! empty( $conexao_empregos_jobs ) ) :
			?>
			<section class="empregos-jobs" aria-labelledby="empregos-jobs-title">
				<h2 id="empregos-jobs-title" class="empregos-jobs-title"><?php esc_html_e( 'Vagas', 'conexao-br-irlanda' ); ?></h2>
				<div class="archive-grid">
					<?php
					foreach ( $conexao_empregos_jobs as $conexao_empregos_job_id ) :
						$conexao_empregos_job = get_post( (int) $conexao_empregos_job_id );
						if ( ! $conexao_empregos_job instanceof WP_Post ) {
							continue;
						}
						$post = $conexao_empregos_job; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- template loop.
						setup_postdata( $post );
						$conexao_job_link = get_permalink();
						?>
						<article id="post-<?php the_ID(); ?>" <?php post_class( 'archive-card' ); ?>>
							<?php if ( has_post_thumbnail() ) : ?>
								<a href="<?php echo esc_url( $conexao_job_link ); ?>" class="archive-card-image" aria-hidden="true" tabindex="-1">
									<?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?>
								</a>
							<?php endif; ?>
							<div class="archive-card-body">
								<h3 class="archive-card-title"><a href="<?php echo esc_url( $conexao_job_link ); ?>"><?php the_title(); ?></a></h3>
								<?php $conexao_job_excerpt = trim( wp_trim_words( get_the_excerpt(), 18, '...' ) ); ?>
								<?php if ( '' !== $conexao_job_excerpt ) : ?>
									<p class="archive-card-excerpt"><?php echo esc_html( $conexao_job_excerpt ); ?></p>
								<?php endif; ?>
								<div class="archive-card-meta">
									<span>
										<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
											<circle cx="12" cy="12" r="10"></circle>
											<polyline points="12 6 12 12 16 14"></polyline>
										</svg>
										<?php echo esc_html( get_the_date() ); ?>
									</span>
									<span>
										<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
											<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
											<path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
										</svg>
										<?php echo esc_html( conexao_reading_time_text() ); ?>
									</span>
								</div>
							</div>
						</article>
						<?php
					endforeach;
					wp_reset_postdata();
					?>
				</div>
			</section>
		<?php endif; ?>

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