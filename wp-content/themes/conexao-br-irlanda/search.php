<?php get_header(); ?>
<div class="site-container">
	<div class="blog-layout">
		<main id="primary" class="content-area">
			<header class="page-header">
				<h1 class="page-title"><?php printf( esc_html__( 'Resultados da busca: %s', 'conexao-br-irlanda' ), '<span>' . get_search_query() . '</span>' ); ?></h1>
			</header>
			<?php if ( have_posts() ) : ?>
				<div class="blog-posts-list">
					<?php while ( have_posts() ) : the_post(); ?>
						<article id="post-<?php the_ID(); ?>" <?php post_class( 'blog-post-item' ); ?>>
							<?php if ( has_post_thumbnail() ) : ?><a href="<?php the_permalink(); ?>" class="post-thumbnail"><?php the_post_thumbnail( 'conexao-hero', array( 'loading' => 'lazy' ) ); ?></a><?php endif; ?>
							<div class="entry-content">
								<header class="entry-header">
									<h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
									<div class="entry-meta"><?php conexao_post_meta(); ?></div>
								</header>
								<div class="entry-summary"><?php the_excerpt(); ?></div>
								<a href="<?php the_permalink(); ?>" class="read-more"><?php esc_html_e( 'Ler mais →', 'conexao-br-irlanda' ); ?></a>
							</div>
						</article>
					<?php endwhile; ?>
				</div>
				<?php the_posts_pagination( array( 'mid_size' => 2, 'prev_text' => __( '← Anterior', 'conexao-br-irlanda' ), 'next_text' => __( 'Próximo →', 'conexao-br-irlanda' ) ) ); ?>
			<?php else : get_template_part( 'template-parts/content', 'none' ); endif; ?>
		</main>
		<?php get_sidebar(); ?>
	</div>
</div>
<?php get_footer();