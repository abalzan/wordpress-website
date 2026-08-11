<?php
/**
 * Template Name: Landing Page (SEO)
 *
 * Used for category and county landing pages. Preserves the existing page
 * content (H1, intro) and appends dynamic, relevant content sections:
 * guides, news, businesses, events, and related categories.
 *
 * @package Conexao_BR_Irlanda
 */

get_header();

// Determine which taxonomy term this page maps to, based on its slug.
$page_slug = get_post_field( 'post_name', get_the_ID() );

// Map page slug to a conexao_category term slug (Portuguese-friendly).
$category_slug_map = array(
	'moradia'    => 'moradia',
	'saude'      => 'saude',
	'familia'    => 'familia',
	'transporte' => 'transporte',
	'financas'   => 'financas',
	'beneficios' => 'beneficios',
	'educacao'   => 'educacao',
	'documentos' => 'documentos',
	'onde-comer' => 'onde-comer',
	'empregos'   => 'empregos',
	'lazer'      => 'lazer',
	'turismo'    => 'turismo',
	'compras'    => 'compras',
	'negocios'   => 'negocios',
	'servicos'   => 'servicos',
	'voluntariado' => 'voluntariado',
);

// County slugs (page slug -> county term slug).
$county_slug_map = array(
	'laois'     => 'laois',
	'dublin'    => 'dublin',
	'cork'      => 'cork',
	'galway'    => 'galway',
	'limerick'  => 'limerick',
	'kildare'   => 'kildare',
	'meath'     => 'meath',
	'wicklow'   => 'wicklow',
	'waterford' => 'waterford',
);

$is_category_page = isset( $category_slug_map[ $page_slug ] );
$is_county_page   = isset( $county_slug_map[ $page_slug ] );

// Resolve the term object for the mapped slug.
$term = null;
if ( $is_category_page ) {
	$term = get_term_by( 'slug', $category_slug_map[ $page_slug ], 'conexao_category' );
} elseif ( $is_county_page ) {
	$term = get_term_by( 'slug', $county_slug_map[ $page_slug ], 'conexao_county' );
}
?>

<div class="site-container">
	<main id="primary" class="content-area">
		<?php while ( have_posts() ) : the_post(); ?>
			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
				<header class="page-header">
					<h1 class="entry-title"><?php the_title(); ?></h1>
				</header>

				<div class="entry-content">
					<?php the_content(); ?>
				</div>
			</article>

			<?php if ( $term && ! is_wp_error( $term ) ) : ?>

				<?php
				// --- Relevant Guides ---
				$guides = new WP_Query( array(
					'post_type'      => 'guide',
					'posts_per_page' => 4,
					'tax_query'      => array(
						array(
							'taxonomy' => $term->taxonomy,
							'field'    => 'term_id',
							'terms'    => $term->term_id,
						),
					),
					'no_found_rows'  => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				) );
				if ( $guides->have_posts() ) : ?>
					<section class="landing-section">
						<h2 class="landing-section-title"><?php esc_html_e( 'Guias Práticos', 'conexao-br-irlanda' ); ?></h2>
						<div class="landing-grid">
							<?php while ( $guides->have_posts() ) : $guides->the_post(); ?>
								<article class="landing-card">
									<?php if ( has_post_thumbnail() ) : ?>
										<a href="<?php the_permalink(); ?>" class="landing-card-thumb"><?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?></a>
									<?php endif; ?>
									<h3 class="landing-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
									<p class="landing-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 15, '...' ) ); ?></p>
								</article>
							<?php endwhile; wp_reset_postdata(); ?>
						</div>
					</section>
				<?php endif; ?>

				<?php
				// --- Relevant News ---
				$news = new WP_Query( array(
					'post_type'      => 'news',
					'posts_per_page' => 4,
					'tax_query'      => array(
						array(
							'taxonomy' => $term->taxonomy,
							'field'    => 'term_id',
							'terms'    => $term->term_id,
						),
					),
					'no_found_rows'  => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				) );
				if ( $news->have_posts() ) : ?>
					<section class="landing-section">
						<h2 class="landing-section-title"><?php esc_html_e( 'Notícias Relacionadas', 'conexao-br-irlanda' ); ?></h2>
						<div class="landing-grid">
							<?php while ( $news->have_posts() ) : $news->the_post(); ?>
								<article class="landing-card">
									<?php if ( has_post_thumbnail() ) : ?>
										<a href="<?php the_permalink(); ?>" class="landing-card-thumb"><?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?></a>
									<?php endif; ?>
									<h3 class="landing-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
									<p class="landing-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 15, '...' ) ); ?></p>
								</article>
							<?php endwhile; wp_reset_postdata(); ?>
						</div>
					</section>
				<?php endif; ?>

				<?php
				// --- Relevant Apoiadores ---
				$sponsors = new WP_Query( array(
					'post_type'      => 'sponsor',
					'posts_per_page' => 4,
					'tax_query'      => array(
						array(
							'taxonomy' => $term->taxonomy,
							'field'    => 'term_id',
							'terms'    => $term->term_id,
						),
					),
					'no_found_rows'  => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				) );
				if ( $sponsors->have_posts() ) : ?>
					<section class="landing-section">
						<h2 class="landing-section-title"><?php esc_html_e( 'Apoiadores', 'conexao-br-irlanda' ); ?></h2>
						<div class="landing-grid">
							<?php while ( $sponsors->have_posts() ) : $sponsors->the_post(); ?>
								<article class="landing-card">
									<?php if ( has_post_thumbnail() ) : ?>
										<a href="<?php the_permalink(); ?>" class="landing-card-thumb"><?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?></a>
									<?php endif; ?>
									<h3 class="landing-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
									<p class="landing-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 15, '...' ) ); ?></p>
								</article>
							<?php endwhile; wp_reset_postdata(); ?>
						</div>
					</section>
				<?php endif; ?>

				<?php
				// --- Relevant Events (county pages only) ---
				if ( $is_county_page ) :
					$events = new WP_Query( array(
						'post_type'      => 'event',
						'posts_per_page' => 3,
						'tax_query'      => array(
							array(
								'taxonomy' => 'conexao_county',
								'field'    => 'term_id',
								'terms'    => $term->term_id,
							),
						),
						'meta_key'       => '_event_date',
						'meta_value'     => current_time( 'Y-m-d' ),
						'meta_compare'   => '>=',
						'meta_type'      => 'DATE',
						'orderby'        => 'meta_value',
						'order'          => 'ASC',
						'no_found_rows'  => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					) );
					if ( $events->have_posts() ) : ?>
						<section class="landing-section">
							<h2 class="landing-section-title"><?php esc_html_e( 'Próximos Eventos', 'conexao-br-irlanda' ); ?></h2>
							<div class="landing-grid">
								<?php while ( $events->have_posts() ) : $events->the_post(); ?>
									<article class="landing-card">
										<?php if ( has_post_thumbnail() ) : ?>
											<a href="<?php the_permalink(); ?>" class="landing-card-thumb"><?php the_post_thumbnail( 'conexao-card', array( 'loading' => 'lazy' ) ); ?></a>
										<?php endif; ?>
										<h3 class="landing-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
										<p class="landing-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 15, '...' ) ); ?></p>
									</article>
								<?php endwhile; wp_reset_postdata(); ?>
							</div>
						</section>
					<?php endif; ?>
				<?php endif; ?>

				<?php
				// --- Related Categories (category pages only) ---
				if ( $is_category_page ) :
					$related_categories = get_terms( array(
						'taxonomy'   => 'conexao_category',
						'hide_empty' => true,
						'exclude'    => array( $term->term_id ),
						'number'     => 6,
					) );
					if ( ! is_wp_error( $related_categories ) && ! empty( $related_categories ) ) : ?>
						<section class="landing-section">
							<h2 class="landing-section-title"><?php esc_html_e( 'Categorias Relacionadas', 'conexao-br-irlanda' ); ?></h2>
							<div class="landing-tags">
								<?php foreach ( $related_categories as $cat ) : ?>
									<a href="<?php echo esc_url( get_term_link( $cat ) ); ?>" class="landing-tag"><?php echo esc_html( $cat->name ); ?></a>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endif; ?>
				<?php endif; ?>

			<?php endif; ?>

			<?php if ( comments_open() || get_comments_number() ) : comments_template(); endif; ?>
		<?php endwhile; ?>
	</main>
</div>
<?php get_footer();