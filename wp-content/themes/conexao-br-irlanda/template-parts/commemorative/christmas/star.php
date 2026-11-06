<?php
/**
 * Christmas package — the shared star motif (code-drawn SVG).
 *
 * "One Christmas, Two Skies" has ONE recurring motif: a single luminous star whose
 * light travels between a Brazilian summer evening and an Irish winter night. It is
 * drawn here as vector geometry (never cut out of the supplied panorama, never a
 * raster star), so it stays crisp at every size, costs no network request and can be
 * scaled down to a 16 px inline glyph.
 *
 * The supplied artwork contains no standalone transparent star and no isolated
 * ornaments; this file is therefore the deliberate, code-drawn substitute the brief
 * asks for. `decorations/star.svg` is the same geometry as a standalone file for the
 * ambient scheduler, which appends its decoration to <body>.
 *
 * Usage:
 *   get_template_part( 'template-parts/commemorative/christmas/star', null, array(
 *       'id'      => 'hero',             // unique per page: gradient ids must not collide
 *       'variant' => 'bridge',           // 'star' (default) or 'bridge' (star + light trails)
 *       'class'   => 'my-class',         // optional class on the <svg>
 *       'label'   => '',                 // a non-empty value makes the SVG informative
 *   ) );
 *
 * Decorative by default: `aria-hidden="true"` and `focusable="false"`, so the motif is
 * never announced and never reachable by keyboard. Pass a `label` only when the star
 * carries meaning that the surrounding copy does not.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce_star = isset( $args ) && is_array( $args ) ? $args : array();

$ce_star_id      = isset( $ce_star['id'] ) ? sanitize_html_class( (string) $ce_star['id'] ) : 'default';
$ce_star_variant = isset( $ce_star['variant'] ) && 'bridge' === $ce_star['variant'] ? 'bridge' : 'star';
$ce_star_class   = isset( $ce_star['class'] ) ? (string) $ce_star['class'] : '';
$ce_star_label   = isset( $ce_star['label'] ) ? (string) $ce_star['label'] : '';

$ce_star_glow_id = 'ce-xmas-star-glow-' . $ce_star_id;
$ce_star_core_id = 'ce-xmas-star-core-' . $ce_star_id;

// The eight-point geometry: two long verticals, two long horizontals and four short
// diagonals, so the star reads as a star (not a diamond) even at 16 px.
$ce_star_path = 'M60 2 L65.5 39.5 L96 20 L76.5 46 L114 51 L82 60 L96 92 L60 71.5 L24 92 L38 60 L6 51 L43.5 46 L24 20 L54.5 39.5 Z';
?>
<svg
	<?php if ( 'bridge' === $ce_star_variant ) : ?>
		viewBox="0 0 480 120"
	<?php else : ?>
		viewBox="0 0 120 120"
	<?php endif; ?>
	class="ce-xmas-star<?php echo '' !== $ce_star_class ? ' ' . esc_attr( $ce_star_class ) : ''; ?>"
	<?php if ( '' !== $ce_star_label ) : ?>
		role="img"
		aria-label="<?php echo esc_attr( $ce_star_label ); ?>"
	<?php else : ?>
		aria-hidden="true"
	<?php endif; ?>
	focusable="false"
	preserveAspectRatio="xMidYMid meet"
>
	<defs>
		<radialGradient id="<?php echo esc_attr( $ce_star_glow_id ); ?>" cx="50%" cy="50%" r="50%">
			<stop offset="0%" stop-color="#fff6d8" stop-opacity="0.9"/>
			<stop offset="55%" stop-color="#e8c273" stop-opacity="0.22"/>
			<stop offset="100%" stop-color="#e8c273" stop-opacity="0"/>
		</radialGradient>
		<linearGradient id="<?php echo esc_attr( $ce_star_core_id ); ?>" x1="0" y1="0" x2="0" y2="1">
			<stop offset="0%" stop-color="#fffdf6"/>
			<stop offset="60%" stop-color="#f2d38a"/>
			<stop offset="100%" stop-color="#d9a441"/>
		</linearGradient>
	</defs>
	<?php if ( 'bridge' === $ce_star_variant ) : ?>
		<?php // The light trails: the star's light reaching BOTH skies, symmetrically. ?>
		<path class="ce-xmas-star__trail" d="M207 58 C 152 40, 82 34, 2 40" />
		<path class="ce-xmas-star__trail" d="M273 58 C 328 40, 398 34, 478 40" />
		<path class="ce-xmas-star__trail ce-xmas-star__trail--faint" d="M205 72 C 150 86, 72 96, 4 104" />
		<path class="ce-xmas-star__trail ce-xmas-star__trail--faint" d="M275 72 C 330 86, 408 96, 476 104" />
		<g class="ce-xmas-star__sparks">
			<circle cx="62" cy="38" r="2"/>
			<circle cx="418" cy="38" r="2"/>
			<circle cx="130" cy="45" r="1.3"/>
			<circle cx="350" cy="45" r="1.3"/>
			<circle cx="40" cy="99" r="1.3"/>
			<circle cx="440" cy="99" r="1.3"/>
		</g>
		<g transform="translate(207 25) scale(0.55)">
	<?php endif; ?>
	<circle cx="60" cy="60" r="58" class="ce-xmas-star__glow" fill="url(#<?php echo esc_attr( $ce_star_glow_id ); ?>)"/>
	<path class="ce-xmas-star__body" d="<?php echo esc_attr( $ce_star_path ); ?>" fill="url(#<?php echo esc_attr( $ce_star_core_id ); ?>)"/>
	<circle cx="60" cy="60" r="5" class="ce-xmas-star__core"/>
	<?php if ( 'bridge' === $ce_star_variant ) : ?>
		</g>
	<?php endif; ?>
</svg>
