<?php
/**
 * New Year package — dedicated landing-page modules.
 *
 * The composition below is the package's REUSE MAP (r10 §7.12 field 6): every
 * module surfaces EXISTING records by slug and never copies their content. The
 * slugs are the canonical Portuguese records; the helper resolves the current
 * language through Polylang and falls back to the canonical record when a
 * translation is missing (B2 policy).
 *
 * The New Year page therefore survives the event window: it reads pages,
 * guides, places, articles and the shared upcoming-events engine — never the
 * event record's schedule.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce = isset( $args['ce'] ) && is_array( $args['ce'] ) ? $args['ce'] : array();

if ( empty( $ce['package'] ) || 'new_year' !== $ce['package']['slug'] ) {
	return;
}

$ce_locale  = function_exists( 'conexao_current_locale' ) ? conexao_current_locale() : '';
$ce_english = in_array( $ce_locale, array( 'en_US', 'en_GB', 'en' ), true );

$ce_art_uri = get_template_directory_uri() . '/assets/images/presentations/new_year/';

$ce_art_alt = $ce_english
	? __( 'New Year celebration: fireworks over a shared night sky above Rio de Janeiro and Dublin, with champagne glasses and festive ribbons in the foreground.', 'conexao-br-irlanda' )
	: __( 'Celebração de Ano Novo: fogos de artifício sobre um céu noturno compartilhado acima do Rio de Janeiro e de Dublin, com taças de champanhe e fitas festivas ao primeiro plano.', 'conexao-br-irlanda' );

$ce_caption = $ce_english
	? __( 'One sky, two cities: Rio de Janeiro and Dublin welcome the New Year together.', 'conexao-br-irlanda' )
	: __( 'Um céu, duas cidades: o Rio de Janeiro e Dublin recebem o Ano Novo juntos.', 'conexao-br-irlanda' );

$ce_message = $ce_english
	? __( 'Two countries, two cultures, one shared celebration. May the New Year bring new opportunities, meaningful connections, happiness, and prosperity to our Brazilian and Irish communities.', 'conexao-br-irlanda' )
	: __( 'Dois países, duas culturas, uma celebração compartilhada. Que o Ano Novo traga novas oportunidades, conexões significativas, felicidade e prosperidade às nossas comunidades brasileira e irlandesa.', 'conexao-br-irlanda' );

$ce_kicker = $ce_english ? __( 'New Year · Brazil and Ireland', 'conexao-br-irlanda' ) : __( 'Ano Novo · Brasil e Irlanda', 'conexao-br-irlanda' );

$ce_heading = $ce_english ? __( 'Two countries, one new beginning', 'conexao-br-irlanda' ) : __( 'Dois países, um novo começo', 'conexao-br-irlanda' );
?>
<section class="ce-new-year-hero" aria-labelledby="ce-new-year-landing-title">
	<div class="ce-new-year-hero__wrap">
		<figure class="ce-new-year-hero__art">
			<img
				src="<?php echo esc_url( $ce_art_uri . 'new_year-postcard-1120.webp' ); ?>"
				srcset="<?php echo esc_url( $ce_art_uri . 'new_year-postcard-760.webp' ); ?> 760w, <?php echo esc_url( $ce_art_uri . 'new_year-postcard-1120.webp' ); ?> 1120w"
				sizes="(max-width: 820px) calc(100vw - 32px), 700px"
				width="1120"
				height="630"
				alt="<?php echo esc_attr( $ce_art_alt ); ?>"
				loading="lazy"
				decoding="async"
				draggable="false"
			>
			<figcaption class="ce-new-year-hero__caption"><?php echo esc_html( $ce_caption ); ?></figcaption>
		</figure>
		<div class="ce-new-year-hero__body">
			<p class="ce-new-year-hero__kicker"><?php echo esc_html( $ce_kicker ); ?></p>
			<h2 class="ce-new-year-hero__title" id="ce-new-year-landing-title"><?php echo esc_html( $ce_heading ); ?></h2>
			<p class="ce-new-year-hero__message"><?php echo esc_html( $ce_message ); ?></p>
		</div>
	</div>
</section>
