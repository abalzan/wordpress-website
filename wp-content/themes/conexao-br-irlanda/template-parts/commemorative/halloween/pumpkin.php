<?php
/**
 * Halloween package — the signature carved-pumpkin motif.
 *
 * Extracted so the pumpkin identity is defined ONCE and reused by every
 * Halloween placement (hero, ambient, landing header). The artwork is the visual
 * anchor of the package (r10 §7.6 addendum); supporting figures never compete
 * with it.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce_pumpkin_class = isset( $args['class'] ) ? trim( 'seasonal-halloween-pumpkin ' . $args['class'] ) : 'seasonal-halloween-pumpkin';
?>
<svg class="<?php echo esc_attr( $ce_pumpkin_class ); ?>" viewBox="0 0 96 96" aria-hidden="true" focusable="false">
	<defs>
		<radialGradient id="seasonal-pumpkin-fill" cx="38%" cy="28%" r="74%">
			<stop offset="0" stop-color="var(--halloween-pumpkin-highlight, #ffbf69)" />
			<stop offset=".48" stop-color="var(--halloween-pumpkin-mid, #ed7929)" />
			<stop offset="1" stop-color="var(--halloween-pumpkin-shadow, #8f341e)" />
		</radialGradient>
		<linearGradient id="seasonal-pumpkin-stem" x1="0" x2="1">
			<stop offset="0" stop-color="var(--halloween-pumpkin-stem-light, #4d512d)" />
			<stop offset="1" stop-color="var(--halloween-pumpkin-stem-dark, #8c7b3d)" />
		</linearGradient>
	</defs>
	<ellipse cx="48" cy="84" rx="31" ry="6" fill="var(--halloween-pumpkin-mid, #ed7929)" opacity=".2" />
	<path d="M48 27c-7-4-8-11-5-18 4 4 8 5 12 3-1 7-1 11 4 15" fill="url(#seasonal-pumpkin-stem)" stroke="var(--halloween-pumpkin-stem-ink, #3f4528)" stroke-width="2" stroke-linejoin="round" />
	<path d="M48 25c-9-7-22-4-30 4-11 11-11 32-3 45 8 12 20 14 33 7 13 7 25 5 33-7 8-13 8-34-3-45-8-8-21-11-30-4z" fill="url(#seasonal-pumpkin-fill)" stroke="var(--halloween-pumpkin-ink, #8a351b)" stroke-width="2" />
	<path d="M48 26c-8 9-9 42 0 55m0-55c9 9 10 42 0 55M32 27c-6 12-7 36 1 51m31-51c6 12 7 36-1 51" fill="none" stroke="var(--halloween-pumpkin-line, #8e3a21)" stroke-width="3" opacity=".48" />
	<path d="m26 43 15 4-10 12zm44 0-15 4 10 12z" fill="var(--halloween-pumpkin-face, #ffd16f)" stroke="var(--halloween-pumpkin-ink, #8a351b)" stroke-width="2" stroke-linejoin="round" />
	<path d="M29 64c5 3 10 3 15 0l4 6 4-6c5 3 10 3 15 0-2 12-9 17-19 17S31 76 29 64z" fill="var(--halloween-pumpkin-face, #ffd16f)" stroke="var(--halloween-pumpkin-ink, #8a351b)" stroke-width="2" stroke-linejoin="round" />
	<path d="M45 66h6m-4 7h4m-7 6h5" stroke="var(--halloween-pumpkin-line, #8e3a21)" stroke-width="2" stroke-linecap="round" opacity=".75" />
</svg>
