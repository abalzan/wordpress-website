<?php
/**
 * Template Name: Commemorative Landing Page
 *
 * The opt-in landing-page template (r10 §7.5, §7.13). A page becomes a
 * commemorative landing page only when it carries BOTH this template and the
 * `_ce_landing_package` meta naming a registered package; the dispatcher then
 * renders that package's `landing_page` placement.
 *
 * If either opt-in is missing the template degrades to an ordinary page instead
 * of rendering an empty shell — the route never becomes a blank page by accident,
 * and the `commemorative_event` CPT is never exposed.
 *
 * @package Conexao_BR_Irlanda
 */

get_header();
?>
<main id="primary" class="site-main">
	<?php
	while ( have_posts() ) :
		the_post();

		$conexao_landing_rendered = function_exists( 'conexao_commemorative_render_placement' )
			&& conexao_commemorative_render_placement( 'landing_page' );

		if ( ! $conexao_landing_rendered ) :
			?>
			<div class="site-container page-content">
				<h1 class="page-title"><?php the_title(); ?></h1>
				<div class="entry-content"><?php the_content(); ?></div>
			</div>
			<?php
		endif;
	endwhile;
	?>
</main>
<?php
get_footer();
