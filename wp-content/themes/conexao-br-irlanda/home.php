<?php
/**
 * Blog archive template (home.php)
 *
 * This template is used for the posts archive at /blog/ and renders the
 * Blog as a searchable editorial library:
 *
 *   - A prominent Blog search (the native WordPress `s` query, accent
 *     insensitive via inc/search.php) that stays on /blog/ and searches
 *     Blog articles only.
 *   - A category filter bar over the native `category` taxonomy
 *     (/blog/?categoria=slug), with an active-filter chip and "Limpar filtros".
 *   - A result counter read from the main query's found_posts (free, native).
 *   - The existing article card grid with the infinite-scroll enhancement
 *     and normal crawlable pagination.
 *
 * Search and category are both applied to the main query by
 * conexao_content_archive_query() (pre_get_posts) and can be combined in a
 * single WordPress query, so neither state is ever silently lost and no
 * extra query or front-end engine is involved.
 *
 * @package Conexao_BR_Irlanda
 */

get_header();

global $wp_query;

$blog_url              = conexao_blog_category_filter_url( '' );
$current_blog_category = isset( $_GET['categoria'] ) ? sanitize_title( wp_unslash( $_GET['categoria'] ) ) : '';
$search_term           = get_search_query();

// Category chips carry an active search term so search + category combine
// without state being lost (both are applied to the same main query).
$filtered_base_url = ( '' !== $search_term ) ? add_query_arg( 's', $search_term, $blog_url ) : $blog_url;

$categories = get_categories( array( 'hide_empty' => true, 'orderby' => 'name' ) );

$active_category = null;
if ( $current_blog_category && ! empty( $categories ) ) {
	foreach ( $categories as $category ) {
		if ( $category->slug === $current_blog_category ) {
			$active_category = $category;
			break;
		}
	}
}

$article_count = ( $wp_query instanceof WP_Query ) ? (int) $wp_query->found_posts : 0;
?>
<div class="site-container blog-page">
	<main id="primary" class="content-area">

		<?php get_template_part( 'template-parts/archive', 'header', array(
			'eyebrow'     => __( 'Artigos e Notícias', 'conexao-br-irlanda' ),
			'title'       => __( 'Blog', 'conexao-br-irlanda' ),
			'description' => __( 'Informações, histórias e experiências para brasileiros na Irlanda.', 'conexao-br-irlanda' ),
			'filters'     => '',
		) ); ?>

		<section class="blog-discovery" aria-labelledby="blog-discovery-title">
			<h2 id="blog-discovery-title" class="screen-reader-text"><?php esc_html_e( 'Busca e filtros do blog', 'conexao-br-irlanda' ); ?></h2>

			<div class="blog-toolbar">
				<form role="search" method="get" class="blog-search-form conexao-search-form" action="<?php echo esc_url( $blog_url ); ?>">
					<label class="screen-reader-text" for="blog-search-field"><?php esc_html_e( 'Buscar no blog', 'conexao-br-irlanda' ); ?></label>
					<div class="conexao-search-wrap">
						<svg class="conexao-search-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
							<circle cx="11" cy="11" r="8"></circle>
							<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
						</svg>
						<input type="search" id="blog-search-field" class="search-field blog-search-field" name="s" placeholder="<?php esc_attr_e( 'Buscar no Blog...', 'conexao-br-irlanda' ); ?>" value="<?php echo esc_attr( $search_term ); ?>" autocomplete="off" />
						<button type="submit" class="search-submit"><?php esc_html_e( 'Buscar', 'conexao-br-irlanda' ); ?></button>
					</div>
					<?php if ( $current_blog_category ) : ?>
						<input type="hidden" name="categoria" value="<?php echo esc_attr( $current_blog_category ); ?>" />
					<?php endif; ?>
				</form>

				<?php if ( ! empty( $categories ) ) : ?>
					<nav class="events-filter-bar blog-category-bar" aria-label="<?php esc_attr_e( 'Categorias do blog', 'conexao-br-irlanda' ); ?>">
						<span class="events-filter-label"><?php esc_html_e( 'Categorias', 'conexao-br-irlanda' ); ?></span>
						<a class="events-filter-link<?php echo '' === $current_blog_category ? ' is-active' : ''; ?>" href="<?php echo esc_url( remove_query_arg( 'categoria', $filtered_base_url ) ); ?>"<?php echo '' === $current_blog_category ? ' aria-current="true"' : ''; ?>>
							<?php esc_html_e( 'Todos', 'conexao-br-irlanda' ); ?>
						</a>
						<?php foreach ( $categories as $category ) : ?>
							<?php $is_active = ( $current_blog_category === $category->slug ); ?>
							<a class="events-filter-link<?php echo $is_active ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'categoria', $category->slug, $filtered_base_url ) ); ?>"<?php echo $is_active ? ' aria-current="true"' : ''; ?>>
								<?php echo esc_html( $category->name ); ?>
							</a>
						<?php endforeach; ?>
					</nav>
				<?php endif; ?>
			</div>

			<div class="blog-active-row">
				<?php if ( $active_category ) : ?>
					<span class="blog-active-chip">
						<?php echo esc_html( $active_category->name ); ?>
						<a class="blog-active-chip-remove" href="<?php echo esc_url( remove_query_arg( 'categoria', $filtered_base_url ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remover categoria %s', 'conexao-br-irlanda' ), $active_category->name ) ); ?>">×</a>
					</span>
					<a class="blog-clear-filters" href="<?php echo esc_url( $blog_url ); ?>"><?php esc_html_e( 'Limpar filtros', 'conexao-br-irlanda' ); ?></a>
				<?php endif; ?>
				<span class="blog-results-count" aria-live="polite">
					<?php
					/* translators: %d: number of blog articles in the current view. */
					printf( esc_html( _n( '%d artigo', '%d artigos', $article_count, 'conexao-br-irlanda' ) ), $article_count );
					?>
				</span>
			</div>

			</section>

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
					<?php if ( is_search() ) : ?>
						<h1 class="page-title"><?php esc_html_e( 'Nenhum artigo encontrado.', 'conexao-br-irlanda' ); ?></h1>
					<?php elseif ( $current_blog_category ) : ?>
						<h1 class="page-title"><?php esc_html_e( 'Nenhum artigo nesta categoria.', 'conexao-br-irlanda' ); ?></h1>
					<?php else : ?>
						<h1 class="page-title"><?php esc_html_e( 'Nenhum artigo publicado ainda.', 'conexao-br-irlanda' ); ?></h1>
					<?php endif; ?>
				</header>
				<div class="page-content">
					<?php if ( is_search() ) : ?>
						<p><?php esc_html_e( 'Tente usar outras palavras ou remover o filtro.', 'conexao-br-irlanda' ); ?></p>
						<a class="btn btn-outline-dark" href="<?php echo esc_url( $blog_url ); ?>"><?php esc_html_e( 'Limpar filtros', 'conexao-br-irlanda' ); ?></a>
					<?php elseif ( $current_blog_category ) : ?>
						<p><?php esc_html_e( 'Experimente outra categoria ou consulte todos os artigos do Blog.', 'conexao-br-irlanda' ); ?></p>
						<a class="btn btn-outline-dark" href="<?php echo esc_url( $blog_url ); ?>"><?php esc_html_e( 'Limpar filtros', 'conexao-br-irlanda' ); ?></a>
					<?php else : ?>
						<p><?php esc_html_e( 'Novos artigos serão publicados aqui em breve.', 'conexao-br-irlanda' ); ?></p>
						<?php if ( current_user_can( 'publish_posts' ) ) : ?>
							<p><?php printf( wp_kses( __( 'Pronto para publicar seu primeiro artigo? <a href="%s">Comece aqui</a>.', 'conexao-br-irlanda' ), array( 'a' => array( 'href' => array() ) ) ), esc_url( admin_url( 'post-new.php' ) ) ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>

	</main>
</div>

<?php get_template_part( 'template-parts/newsletter-section' ); ?>
<?php get_template_part( 'template-parts/quote-section' ); ?>

<?php get_footer();