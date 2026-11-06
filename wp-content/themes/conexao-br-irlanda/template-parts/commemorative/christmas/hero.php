<?php
/**
 * Christmas package — homepage-hero content.
 *
 * "One Christmas, Two Skies": the supplied GPT Images panorama (Brazilian summer
 * evening on the left, Irish winter village on the right, one shared star between
 * them) carries the visual message; ALL copy stays real, localised HTML beside it.
 *
 * Layout contract: the art and the copy are two siblings, never layered, so no
 * sentence is ever placed on top of the photograph (contrast is guaranteed by
 * construction and survives both site themes). The art keeps its intrinsic 16:9 box,
 * so both seasonal worlds stay visible at every width instead of one being cropped
 * away on a phone.
 *
 * One CTA only (r10 §7.12 / brief §5.1): the event record may override it; otherwise
 * it leads to this package's own landing page, and to the shared calendar only when
 * that page cannot be resolved.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce = isset( $args['ce'] ) && is_array( $args['ce'] ) ? $args['ce'] : array();

if ( empty( $ce['event'] ) ) {
	return;
}

$ce_event   = $ce['event'];
$ce_locale  = function_exists( 'conexao_current_locale' ) ? conexao_current_locale() : '';
$ce_english = in_array( $ce_locale, array( 'en_US', 'en_GB', 'en' ), true );

$ce_heading = '' !== $ce_event->heading ? $ce_event->heading : ( $ce_english ? __( 'One Christmas, two skies', 'conexao-br-irlanda' ) : __( 'Um Natal, dois céus', 'conexao-br-irlanda' ) );

$ce_copy = '' !== $ce_event->subheading
	? $ce_event->subheading
	: ( $ce_english
		? __( 'In Brazil the light stays late and the evening arrives slowly; in Ireland the dark comes early and the windows light up. In between, the same star — and the same wish to be close to the people who matter.', 'conexao-br-irlanda' )
		: __( 'No Brasil, a luz fica até tarde e a noite chega devagar; na Irlanda, escurece cedo e são as janelas que acendem. Entre os dois, a mesma estrela — e a mesma vontade de estar perto de quem importa.', 'conexao-br-irlanda' ) );

$ce_landing = function_exists( 'conexao_commemorative_resolve_record' )
	? conexao_commemorative_resolve_record( 'page', $ce_english ? 'christmas' : 'natal' )
	: null;

if ( '' !== (string) $ce_event->cta_url ) {
	$ce_cta_href = (string) $ce_event->cta_url;
} elseif ( $ce_landing instanceof WP_Post ) {
	$ce_cta_href = (string) get_permalink( $ce_landing );
} else {
	$ce_cta_href = conexao_lang_url( '/eventos/' );
}

$ce_cta = '' !== (string) $ce_event->cta_label
	? (string) $ce_event->cta_label
	: ( $ce_landing instanceof WP_Post
		? ( $ce_english ? __( 'Explore both skies', 'conexao-br-irlanda' ) : __( 'Explorar os dois céus', 'conexao-br-irlanda' ) )
		: ( $ce_english ? __( 'See the Christmas calendar', 'conexao-br-irlanda' ) : __( 'Ver a agenda de Natal', 'conexao-br-irlanda' ) ) );

$ce_art_uri = get_template_directory_uri() . '/assets/images/presentations/christmas/two-skies/';

$ce_art_alt = $ce_english
	? __( 'One lit Christmas star above a summer beach in Brazil, on the left, and a snow-covered Irish village with warm windows and a castle on a hill, on the right.', 'conexao-br-irlanda' )
	: __( 'Uma estrela de Natal acesa sobre uma praia de verão no Brasil, à esquerda, e uma vila irlandesa coberta de neve, com janelas iluminadas e um castelo no alto, à direita.', 'conexao-br-irlanda' );

$ce_kicker = $ce_english ? __( 'Christmas · Brazil and Ireland', 'conexao-br-irlanda' ) : __( 'Natal · Brasil e Irlanda', 'conexao-br-irlanda' );
?>
<div class="ce-christmas-hero ce-hero">
	<figure class="ce-christmas-hero__art">
		<img
			src="<?php echo esc_url( $ce_art_uri . 'two-skies-hero-1200.webp' ); ?>"
			srcset="<?php echo esc_url( $ce_art_uri . 'two-skies-hero-840.webp' ); ?> 840w, <?php echo esc_url( $ce_art_uri . 'two-skies-hero-1200.webp' ); ?> 1200w"
			sizes="(max-width: 768px) calc(100vw - 48px), 480px"
			width="1200"
			height="675"
			alt="<?php echo esc_attr( $ce_art_alt ); ?>"
			loading="eager"
			decoding="async"
			fetchpriority="low"
			draggable="false"
		>
	</figure>
	<div class="ce-christmas-hero__body">
		<p class="ce-christmas-hero__kicker">
			<span class="ce-christmas-hero__star" aria-hidden="true">
				<?php
				get_template_part(
					'template-parts/commemorative/christmas/star',
					null,
					array(
						'id'    => 'hero',
						'class' => 'ce-christmas-hero__star-svg',
					)
				);
				?>
			</span>
			<?php echo esc_html( $ce_kicker ); ?>
		</p>
		<h2 class="ce-christmas-hero__title"><?php echo esc_html( $ce_heading ); ?></h2>
		<p class="ce-christmas-hero__copy"><?php echo esc_html( $ce_copy ); ?></p>
		<div class="ce-christmas-hero__actions">
			<a class="btn btn-primary ce-christmas-hero__cta" href="<?php echo esc_url( $ce_cta_href ); ?>">
				<?php echo esc_html( $ce_cta ); ?>
			</a>
		</div>
	</div>
</div>
