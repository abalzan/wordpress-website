<?php get_header(); ?>

<?php
$is_event_archive  = is_post_type_archive( 'event' );
$is_course_archive = is_post_type_archive( 'course_provider' );
?>

<div class="<?php echo $is_event_archive ? 'site-container events-page' : ( $is_course_archive ? 'site-container site-container--wide courses-page' : 'site-container' ); ?>">
	<main id="primary" class="content-area">

		<?php if ( $is_event_archive ) : ?>
			<header class="events-page-header">
				<span class="section-eyebrow"><?php esc_html_e( 'Agenda da Comunidade', 'conexao-br-irlanda' ); ?></span>
				<h1 class="events-page-title"><?php esc_html_e( 'Eventos', 'conexao-br-irlanda' ); ?></h1>
				<p class="events-page-description"><?php esc_html_e( 'Encontre eventos, encontros e atividades da comunidade brasileira na Irlanda.', 'conexao-br-irlanda' ); ?></p>
			</header>
			<?php get_template_part( 'template-parts/event', 'filters' ); ?>
		<?php elseif ( $is_course_archive ) : ?>
			<header class="events-page-header">
				<span class="section-eyebrow"><?php esc_html_e( 'Aprendizagem e Formação', 'conexao-br-irlanda' ); ?></span>
				<h1 class="events-page-title"><?php esc_html_e( 'Cursos', 'conexao-br-irlanda' ); ?></h1>
				<p class="events-page-description"><?php esc_html_e( 'Encontre cursos, formações e oportunidades de aprendizagem na Irlanda.', 'conexao-br-irlanda' ); ?></p>
			</header>
		<?php get_template_part( 'template-parts/event', 'filters' ); ?>
		<?php else : ?>
			<header class="archive-header">
				<h1 class="archive-title"><?php echo esc_html( conexao_archive_title() ); ?></h1>
				<?php $archive_desc = conexao_archive_description(); ?>
				<?php if ( $archive_desc ) : ?>
					<p class="archive-description"><?php echo esc_html( $archive_desc ); ?></p>
				<?php endif; ?>
			</header>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<div class="<?php echo ( $is_event_archive || $is_course_archive ) ? 'events-grid' : 'archive-grid'; ?>">
				<?php while ( have_posts() ) : the_post(); ?>
					<?php if ( $is_event_archive ) : ?>
						<?php get_template_part( 'template-parts/event', 'card' ); ?>
				<?php elseif ( $is_course_archive ) : ?>
					<?php get_template_part( 'template-parts/provider', 'card' ); ?>
					<?php else : ?>
						<?php
						// For sponsors, check if there's an external link to make the card clickable.
						$is_sponsor = 'sponsor' === get_post_type();
						$sponsor_link = $is_sponsor ? get_post_meta( get_the_ID(), '_sponsor_link', true ) : '';
						$card_link = $sponsor_link ? esc_url( $sponsor_link ) : get_permalink();
						$card_target = $sponsor_link ? ' target="_blank"' : '';
						$card_rel = $sponsor_link ? ' rel="noopener noreferrer"' : '';
						$card_classes = 'archive-card' . ( $sponsor_link ? ' archive-card--clickable' : '' );
						?>
						<article id="post-<?php the_ID(); ?>" <?php post_class( $card_classes ); ?>>
							<?php if ( has_post_thumbnail() ) : ?>
								<a href="<?php echo esc_url( $card_link ); ?>" class="archive-card-image" aria-hidden="true" tabindex="-1"<?php echo $card_target . $card_rel; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>>
									<?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?>
								</a>
							<?php endif; ?>
							<div class="archive-card-body">
								<?php
								$cats = get_the_terms( get_the_ID(), 'conexao_category' );
								if ( $cats && ! is_wp_error( $cats ) ) : ?>
									<span class="archive-card-category"><?php echo esc_html( $cats[0]->name ); ?></span>
								<?php endif; ?>
								<h2 class="archive-card-title"><a href="<?php echo esc_url( $card_link ); ?>"<?php echo $card_target . $card_rel; /* phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped */ ?>><?php the_title(); ?></a></h2>
								<p class="archive-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18, '...' ) ); ?></p>
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
							</div>
						</article>
					<?php endif; ?>
				<?php endwhile; ?>
			</div>

			<?php get_template_part( 'template-parts/pagination' ); ?>

		<?php else : ?>
			<?php get_template_part( 'template-parts/content', 'none' ); ?>
		<?php endif; ?>

	</main>
</div>

<?php get_footer();