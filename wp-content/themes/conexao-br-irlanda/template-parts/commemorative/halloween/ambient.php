<?php
/**
 * Halloween package — site-wide ambient layer content.
 *
 * The 20–30 s / 25–35 s mobile navigation-surprise cadence approved in
 * `2026-10-27-halloween-mobile-experience-plan.md` §1/§5 is preserved as an
 * explicit package behaviour (r10 §7.7 exception): the constants live in
 * `assets/js/presentations/halloween/halloween.js`, the energy row and the
 * session cap live in the registry.
 *
 * This template only publishes the marker the package script reads. It renders no
 * text and no focusable node: the ambient layer is decorative.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce = isset( $args['ce'] ) && is_array( $args['ce'] ) ? $args['ce'] : array();

if ( empty( $ce['package'] ) || 'halloween' !== $ce['package']['slug'] ) {
	return;
}
?>
<span
	class="seasonal-halloween-active-marker"
	data-witch-asset="<?php echo esc_url( get_template_directory_uri() . '/assets/images/presentations/halloween/decorations/witch-broom.svg' ); ?>"
	hidden
></span>
