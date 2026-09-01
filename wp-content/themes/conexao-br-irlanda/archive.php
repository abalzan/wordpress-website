<?php get_header(); ?>

<?php
$is_event_archive   = is_post_type_archive( 'event' );
$is_course_archive  = is_post_type_archive( 'course_provider' );
$is_guide_archive   = is_post_type_archive( 'guide' );
$is_leisure_archive = is_post_type_archive( 'leisure' );
$is_job_archive     = is_post_type_archive( 'job' );
$is_sponsor_archive = is_post_type_archive( 'sponsor' );

/*
 * Determine the site-container class (controls grid/card layout CSS).
 */
$container_class = 'site-container';
if ( $is_event_archive ) {
	$container_class .= ' events-page';
} elseif ( $is_course_archive ) {
	$container_class .= ' courses-page';
} elseif ( $is_leisure_archive ) {
	$container_class .= ' leisure-page';
}

/*
 * Standardised archive header configuration.
 *
 * Each archive passes its own eyebrow, title, description, and optional
 * filter template to the shared template-parts/archive-header.php component.
 * This ensures all archive pages render the same .page-header structure
 * while preserving the existing eyebrow text, titles, descriptions, and
 * page-specific filter bars.
 */
$archive_header = array();

if ( $is_event_archive ) {
	$archive_header = array(
		'eyebrow'     => _x( 'Agenda da Comunidade', 'archive eyebrow', 'conexao-br-irlanda' ),
		'title'       => _x( 'Eventos', 'archive page title', 'conexao-br-irlanda' ),
		'description' => _x( 'Encontre eventos, encontros e atividades da comunidade brasileira na Irlanda.', 'archive description', 'conexao-br-irlanda' ),
		'filters'     => 'event',
	);
} elseif ( $is_course_archive ) {
	$archive_header = array(
		'eyebrow'     => _x( 'Aprendizagem e Formação', 'archive eyebrow', 'conexao-br-irlanda' ),
		'title'       => _x( 'Cursos', 'archive page title', 'conexao-br-irlanda' ),
		'description' => _x( 'Encontre cursos, formações e oportunidades de aprendizagem na Irlanda.', 'archive description', 'conexao-br-irlanda' ),
		'filters'     => 'course',
	);
} elseif ( $is_guide_archive ) {
	$archive_header = array(
		'eyebrow'     => _x( 'Informação e Guias', 'archive eyebrow', 'conexao-br-irlanda' ),
		'title'       => conexao_archive_title(),
		'description' => conexao_archive_description(),
		'filters'     => 'guide',
	);
} elseif ( $is_leisure_archive ) {
	$archive_header = array(
		'eyebrow'     => _x( 'Lazer & Turismo', 'archive eyebrow', 'conexao-br-irlanda' ),
		'title'       => _x( 'Lazer', 'archive page title', 'conexao-br-irlanda' ),
		'description' => _x( 'Descubra lugares para visitar, natureza, cultura, turismo e coisas para fazer na Irlanda.', 'archive description', 'conexao-br-irlanda' ),
		'filters'     => 'leisure',
	);
} elseif ( $is_job_archive ) {
	$archive_header = array(
		'eyebrow'     => _x( 'Oportunidades', 'archive eyebrow', 'conexao-br-irlanda' ),
		'title'       => conexao_archive_title(),
		'description' => conexao_archive_description(),
		'filters'     => '',
	);
} elseif ( $is_sponsor_archive ) {
	$archive_header = array(
		// The eyebrow complements the h1 ("Apoiadores") instead of
		// repeating it — it frames the page as a partner showcase and
		// carries the "why they matter" message above the cards.
		'eyebrow'     => _x( 'Parceiros da Comunidade', 'archive eyebrow', 'conexao-br-irlanda' ),
		'title'       => conexao_archive_title(),
		'description' => conexao_archive_description(),
		'filters'     => '',
	);
} else {
	/* Generic fallback for any other CPT archive. */
	$archive_header = array(
		'eyebrow'     => '',
		'title'       => conexao_archive_title(),
		'description' => conexao_archive_description(),
		'filters'     => '',
	);
}
?>

<div class="<?php echo esc_attr( $container_class ); ?>">
	<main id="primary" class="content-area">

		<?php get_template_part( 'template-parts/archive', 'header', $archive_header ); ?>

		<?php // Leisure only: stable wrapper the instant mobile filtering
		      // swaps in place (grid + pagination / empty state). It wraps
		      // BOTH the posts grid and the no-results empty state below, so
		      // every server render of /lazer/ exposes a stable
		      // [data-leisure-results] node for the fetch+swap pipeline. ?>
		<?php if ( $is_leisure_archive ) : ?>
		<div class="leisure-results" data-leisure-results>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<?php
			/*
			 * Progressive enhancements per archive:
			 *
			 * - Automatic infinite scroll (Blog/Guias/Lazer + category
			 *   archives): tagged `data-infinite-scroll`.
			 * - Manual "Carregar mais" button (Eventos/Cursos): tagged
			 *   `data-load-more` — the next batch is fetched ONLY on an
			 *   explicit user click, never automatically.
			 *
			 * Both enhancements read the next-page URL from the shared
			 * pagination component below, so filters (?cidade=,
			 * ?categoria=, ?county=) and ordering are preserved by the
			 * existing main-query logic. Without JS, normal pagination
			 * still renders and works.
			 */
			$infinite_scroll = $is_guide_archive || $is_leisure_archive || is_category();
			$load_more       = $is_event_archive || $is_course_archive;
			?>
			<div class="<?php echo ( $is_event_archive || $is_course_archive || $is_leisure_archive ) ? 'events-grid' : 'archive-grid'; ?>"<?php echo $infinite_scroll ? ' data-infinite-scroll' : ''; ?><?php echo $load_more ? ' data-load-more' : ''; ?>>
				<?php while ( have_posts() ) : the_post(); ?>
					<?php if ( $is_event_archive ) : ?>
						<?php get_template_part( 'template-parts/event', 'card' ); ?>
					<?php elseif ( $is_course_archive ) : ?>
						<?php get_template_part( 'template-parts/provider', 'card' ); ?>
					<?php elseif ( $is_leisure_archive ) : ?>
						<?php get_template_part( 'template-parts/leisure', 'card' ); ?>
					<?php else : ?>
						<?php
						// Sponsors navigate INTERNALLY: the card opens the Apoiador
						// detail page, where the full description and every
						// configured contact channel live. The official website
						// remains available there as a "Website" contact button —
						// no external jump straight from the directory card.
						$is_sponsor  = 'sponsor' === get_post_type();
						$card_link   = get_permalink();
						$card_target = '';
						$card_rel    = '';
						$card_classes = 'archive-card' . ( $is_sponsor ? ' archive-card--clickable' : '' );
						?>
						<article id="post-<?php the_ID(); ?>" <?php post_class( $card_classes ); ?>>
							<?php if ( $is_sponsor && conexao_sponsor_carousel_image( get_the_ID(), get_the_title() ) ) :
								// Canonical "Imagem do Apoiador" (single portrait asset,
								// same as the Hero carousel) rendered uncropped and responsive;
								// falls back to the featured thumbnail below when absent.
								?>
								<a href="<?php echo esc_url( $card_link ); ?>" class="archive-card-image" aria-hidden="true" tabindex="-1">
									<?php echo conexao_sponsor_carousel_image( get_the_ID(), get_the_title() ); // phpcs:ignore WordPress.Security.EscapeOutput -- safe HTML built in functions.php. ?>
								</a>
							<?php elseif ( has_post_thumbnail() ) : ?>
								<a href="<?php echo esc_url( $card_link ); ?>" class="archive-card-image" aria-hidden="true" tabindex="-1">
									<?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?>
								</a>
							<?php endif; ?>
							<div class="archive-card-body">
								<?php
								$cats = get_the_terms( get_the_ID(), 'conexao_category' );
								if ( $cats && ! is_wp_error( $cats ) ) : ?>
									<span class="archive-card-category"><?php echo esc_html( $cats[0]->name ); ?></span>
								<?php endif; ?>
								<h2 class="archive-card-title"><a href="<?php echo esc_url( $card_link ); ?>"><?php the_title(); ?></a></h2>
								<?php $card_excerpt = trim( wp_trim_words( get_the_excerpt(), 18, '...' ) ); ?>
								<?php if ( '' !== $card_excerpt ) : ?>
									<p class="archive-card-excerpt"><?php echo esc_html( $card_excerpt ); ?></p>
								<?php endif; ?>
								<?php if ( ! $is_sponsor ) : ?>
								<div class="archive-card-meta">
									<span>
										<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
											<circle cx="12" cy="12" r="10"></circle>
											<polyline points="12 6 12 12 16 14"></polyline>
										</svg>
										<?php echo esc_html( get_the_date() ); ?>
									</span>
									<span>
										<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2">
											<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path>
											<path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>
										</svg>
										<?php echo esc_html( conexao_reading_time_text() ); ?>
									</span>
								</div>
								<?php endif; ?>
								<?php if ( $is_sponsor ) : ?>
									<?php // Decorative affordance: the whole card (and the title link)
									// navigates to the Apoiador detail page; the title link
									// already carries the accessible name, so this hint is
									// intentionally aria-hidden (not meaningful content). ?>
									<span class="archive-card-cta" aria-hidden="true"><?php esc_html_e( 'Conhecer o Apoiador', 'conexao-br-irlanda' ); ?></span>
								<?php endif; ?>
							</div>
						</article>
					<?php endif; ?>
				<?php endwhile; ?>
			</div>

			<?php get_template_part( 'template-parts/pagination' ); ?>

		<?php else : ?>
			<?php get_template_part( 'template-parts/content', 'none' ); ?>
		<?php endif; ?>
		<?php if ( $is_leisure_archive ) : ?>
		</div>
		<?php endif; ?>

	</main>
</div>

<?php get_template_part( 'template-parts/newsletter-section' ); ?>
<?php get_template_part( 'template-parts/quote-section' ); ?>

<?php get_footer();
