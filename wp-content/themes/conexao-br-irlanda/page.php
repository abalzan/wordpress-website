<?php get_header(); ?>

<?php
/*
 * Standardised static page header configuration.
 *
 * Static pages reuse the same unified .page-header component used across the
 * archive/listing pages (template-parts/archive-header.php), so every page
 * header renders the canonical structure: optional section-eyebrow, h1 page
 * title, optional description. Only a minimal eyebrow map is defined here;
 * any other static page simply renders its existing title without an eyebrow.
 */
$page_slug  = get_post_field( 'post_name', get_the_ID() );
$eyebrows   = array(
	'contato'   => _x( 'Fale Conosco', 'page header eyebrow', 'conexao-br-irlanda' ),
	'sobre-nos' => _x( 'Conheça a Conexão BR', 'page header eyebrow', 'conexao-br-irlanda' ),
	'irlanda'   => _x( 'Viver na Irlanda', 'page header eyebrow', 'conexao-br-irlanda' ),
);
$page_eyebrow = isset( $eyebrows[ $page_slug ] ) ? $eyebrows[ $page_slug ] : '';
?>

<div class="site-container">
	<main id="primary" class="content-area">
		<?php
		// STAGE 3.1/3.2 — B2 fallback notice: an allowlisted page rendered
		// under /en/ shows the approved English notice above the PT body.
		// Emits nothing on normal PT/EN pages (see inc/polylang.php).
		if ( function_exists( 'conexao_b2_fallback_notice' ) ) {
			conexao_b2_fallback_notice();
		}
		?>
		<?php while ( have_posts() ) : the_post(); ?>
			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
				<?php
				get_template_part(
					'template-parts/archive',
					'header',
					array(
						'eyebrow'     => $page_eyebrow,
						'title'       => get_the_title(),
						'description' => '',
						'filters'     => '',
					)
				);
				?>
				<div class="entry-content">
					<?php the_content(); wp_link_pages( array( 'before' => '<div class="page-links">' . esc_html__( 'Páginas:', 'conexao-br-irlanda' ), 'after' => '</div>' ) ); ?>
				</div>
			</article>
			<?php if ( comments_open() || get_comments_number() ) : comments_template(); endif; ?>
		<?php endwhile; ?>
	</main>
</div>

<?php get_template_part( 'template-parts/newsletter-section' ); ?>
<?php get_template_part( 'template-parts/quote-section' ); ?>

<?php get_footer();