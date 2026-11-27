<?php
/**
 * Christmas package — site-wide ambient layer content.
 *
 * The ambient layer is decorative and inert: the shared scheduler creates one
 * decoration at a time, capped per session, and never runs under reduced motion.
 * This template renders no text and no control — only the marker the package
 * script uses to find its own placement and the assets for the available motifs.
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

$ce_assets = array(
	'star'       => get_template_directory_uri() . '/assets/images/presentations/christmas/decorations/star.svg',
	'snowflake'  => get_template_directory_uri() . '/assets/images/presentations/christmas/decorations/snowflake.svg',
	'holly'      => get_template_directory_uri() . '/assets/images/presentations/christmas/decorations/holly.svg',
	'bell'       => get_template_directory_uri() . '/assets/images/presentations/christmas/decorations/christmas-bell.svg',
	'gift'       => get_template_directory_uri() . '/assets/images/presentations/christmas/decorations/christmas-gift.svg',
);
?>
<span
	class="ce-christmas-ambient-marker"
	data-ce-star-asset="<?php echo esc_url( $ce_assets['star'] ); ?>"
	data-ce-snowflake-asset="<?php echo esc_url( $ce_assets['snowflake'] ); ?>"
	data-ce-holly-asset="<?php echo esc_url( $ce_assets['holly'] ); ?>"
	data-ce-bell-asset="<?php echo esc_url( $ce_assets['bell'] ); ?>"
	data-ce-gift-asset="<?php echo esc_url( $ce_assets['gift'] ); ?>"
	hidden
></span>
