<?php get_header(); ?>

<div class="site-container">
	<main id="primary" class="content-area">

		<header class="archive-header">
			<h1 class="archive-title"><?php echo esc_html( conexao_archive_title() ); ?></h1>
			<?php $archive_desc = conexao_archive_description(); ?>
			<?php if ( $archive_desc ) : ?>
				<p class="archive-description"><?php echo esc_html( $archive_desc ); ?></p>
			<?php endif; ?>
		</header>

		<?php if ( have_posts() ) : ?>
			<div class="archive-grid">
				<?php while ( have_posts() ) : the_post(); ?>
					<article id="post-<?php the_ID(); ?>" <?php post_class( 'archive-card' ); ?>>
						<?php if ( has_post_thumbnail() ) : ?>
							<a href="<?php the_permalink(); ?>" class="archive-card-image" aria-hidden="true" tabindex="-1">
								<?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?>
							</a>
						<?php endif; ?>
						<div class="archive-card-body">
							<?php
							$cats = get_the_terms( get_the_ID(), 'conexao_category' );
							if ( $cats && ! is_wp_error( $cats ) ) : ?>
								<span class="archive-card-category"><?php echo esc_html( $cats[0]->name ); ?></span>
							<?php endif; ?>
							<h2 class="archive-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
							<p class="archive-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18, '...' ) ); ?></p>
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
						</div>
					</article>
				<?php endwhile; ?>
			</div>

			<?php the_posts_pagination( array(
				'mid_size'  => 2,
				'prev_text' => __( '← Anterior', 'conexao-br-irlanda' ),
				'next_text' => __( 'Próximo →', 'conexao-br-irlanda' ),
			) ); ?>

		<?php else : ?>
			<?php get_template_part( 'template-parts/content', 'none' ); ?>
		<?php endif; ?>

	</main>
</div>

<?php get_footer();