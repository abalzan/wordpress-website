<?php
/**
 * Christmas package — site-wide ambient layer content.
 *
 * The ambient layer is decorative and inert: the shared scheduler creates one star
 * at a time, capped per session, and never runs under reduced motion. This template
 * renders no text and no control — only the marker the package script uses to find
 * its own placement and the asset for the shared motif.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ce = isset( $args['ce'] ) && is_array( $args['ce'] ) ? $args['ce'] : array();

if ( empty( $ce['package'] ) || 'christmas' !== $ce['package']['slug'] ) {
	return;
}
?>
<span
	class="ce-christmas-ambient-marker"
	data-ce-star-asset="<?php echo esc_url( get_template_directory_uri() . '/assets/images/presentations/christmas/decorations/star.svg' ); ?>"
	hidden
></span>
