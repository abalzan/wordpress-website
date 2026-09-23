<?php
/**
 * Blog archive template (home.php)
 *
 * This template is used for the posts archive at /blog/
 * It displays published blog posts in a grid layout matching the site's design system.
 *
 * @package Conexao_BR_Irlanda
 */

get_header();
?>

<div class="site-container blog-page">
	<main id="primary" class="content-area">

		<?php
		// STAGE 3.1/3.2 — B2 fallback notice: /en/blog/ renders the Portuguese
		// posts under the English URL (Blog is an approved B2 destination), so
		// it shows the approved English notice above the archive. Emits nothing
		// on the normal Portuguese /blog/ (see inc/polylang.php).
		if ( function_exists( 'conexao_b2_fallback_notice' ) ) {
			conexao_b2_fallback_notice();
		}
		?>

		<?php get_template_part( 'template-parts/archive', 'header', array(
			'eyebrow'     => __( 'Artigos e Notícias', 'conexao-br-irlanda' ),
			'title'       => __( 'Blog', 'conexao-br-irlanda' ),
			'description' => __( 'Informações, dicas e notícias para a comunidade brasileira na Irlanda.', 'conexao-br-irlanda' ),
			'filters'     => '',
		) ); ?>

		<?php
		// Display blog categories filter.
		//
		// Category links filter the Blog archive itself (/blog/?categoria=slug
		// — same pattern as Guias) instead of navigating to the native
		// WordPress /category/{slug}/ archive. The active state follows the
		// ?categoria= parameter, since is_category() is never true on the
		// Blog posts page.
		$categories            = get_categories( array( 'hide_empty' => true, 'orderby' => 'name' ) );
		$current_blog_category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
		if ( ! empty( $categories ) ) :
		?>
		<nav class="events-filter-bar" aria-label="<?php esc_attr_e( 'Categorias do blog', 'conexao-br-irlanda' ); ?>">
			<span class="events-filter-label"><?php esc_html_e( 'Categorias', 'conexao-br-irlanda' ); ?></span>
			<a class="events-filter-link<?php echo '' === $current_blog_category ? ' is-active' : ''; ?>" href="<?php echo esc_url( conexao_blog_category_filter_url( '' ) ); ?>">
				<?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?>
			</a>
			<?php foreach ( $categories as $category ) : ?>
				<a class="events-filter-link<?php echo $current_blog_category === $category->slug ? ' is-active' : ''; ?>" href="<?php echo esc_url( conexao_blog_category_filter_url( $category->slug ) ); ?>">
					<?php echo esc_html( $category->name ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<div class="archive-grid" data-infinite-scroll>
				<?php while ( have_posts() ) : the_post(); ?>
					<article id="post-<?php the_ID(); ?>" <?php post_class( 'archive-card' ); ?>>
						<?php if ( has_post_thumbnail() ) : ?>
							<a href="<?php the_permalink(); ?>" class="archive-card-image" aria-hidden="true" tabindex="-1">
								<?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?>
							</a>
						<?php endif; ?>
						<div class="archive-card-body">
							<?php
							$categories = get_the_category();
							if ( ! empty( $categories ) ) : ?>
								<span class="archive-card-category"><?php echo esc_html( $categories[0]->name ); ?></span>
							<?php endif; ?>
							<h2 class="archive-card-title">
								<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
							</h2>
							<p class="archive-card-excerpt">
								<?php
								$excerpt = get_the_excerpt();
								if ( empty( $excerpt ) ) {
									$excerpt = wp_trim_words( get_the_content(), 25, '...' );
								}
								echo esc_html( $excerpt );
								?>
							</p>
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

			<?php get_template_part( 'template-parts/pagination' ); ?>

		<?php else : ?>
			<section class="no-results not-found">
				<header class="page-header">
					<h1 class="page-title"><?php esc_html_e( 'Nenhum artigo publicado ainda.', 'conexao-br-irlanda' ); ?></h1>
				</header>
				<div class="page-content">
					<p><?php esc_html_e( 'Novos artigos serão publicados aqui em breve.', 'conexao-br-irlanda' ); ?></p>
					<?php if ( current_user_can( 'publish_posts' ) ) : ?>
						<p><?php printf( wp_kses( __( 'Pronto para publicar seu primeiro artigo? <a href="%s">Comece aqui</a>.', 'conexao-br-irlanda' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( admin_url( 'post-new.php' ) ) ); ?></p>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>

	</main>
</div>

<?php get_template_part( 'template-parts/newsletter-section' ); ?>
<?php get_template_part( 'template-parts/quote-section' ); ?>

<?php get_footer();
