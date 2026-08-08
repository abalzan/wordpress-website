<?php get_header(); ?>
<?php while ( have_posts() ) : the_post(); ?>
	<header class="single-post-header">
		<div class="site-container">
			<?php
			$categories = get_the_category();
			if ( empty( $categories ) ) {
				$categories = get_the_terms( get_the_ID(), 'conexao_category' );
			}
			if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
				echo '<div class="post-categories">';
				foreach ( $categories as $category ) {
					$cat_link = get_term_link( $category );
					if ( ! is_wp_error( $cat_link ) ) {
						echo '<a href="' . esc_url( $cat_link ) . '" class="hero-category">' . esc_html( $category->name ) . '</a>';
					}
				}
				echo '</div>';
			}
			?>
			<h1 class="entry-title"><?php the_title(); ?></h1>
			<div class="entry-meta">
				<span class="post-author"><?php the_author(); ?></span>
				<?php conexao_post_meta(); ?>
				<span class="reading-time"><?php echo esc_html( conexao_reading_time_text() ); ?></span>
			</div>
		</div>
	</header>
	<div class="site-container">
		<div class="single-post-content">
			<main id="primary" class="content-area">
				<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
					<?php if ( has_post_thumbnail() ) : ?><div class="post-thumbnail"><?php the_post_thumbnail( 'conexao-hero' ); ?></div><?php endif; ?>
					<div class="entry-content">
						<?php the_content(); wp_link_pages( array( 'before' => '<div class="page-links">' . esc_html__( 'Páginas:', 'conexao-br-irlanda' ), 'after' => '</div>' ) ); ?>
					</div>
					<?php conexao_share_buttons(); ?>
					<footer class="entry-footer">
						<?php $tags = get_the_tags(); if ( $tags ) { echo '<div class="post-tags">'; foreach ( $tags as $tag ) { echo '<a href="' . esc_url( get_tag_link( $tag->term_id ) ) . '" class="tag-link">#' . esc_html( $tag->name ) . '</a> '; } echo '</div>'; } ?>
					</footer>
				</article>
				<?php conexao_related_posts(); ?>
				<?php if ( comments_open() || get_comments_number() ) : comments_template(); endif; ?>
			</main>
		</div>
	</div>
<?php endwhile; ?>
<?php get_footer();