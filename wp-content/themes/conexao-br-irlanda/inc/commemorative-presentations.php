<?php
/**
 * Commemorative experience-package registry (theme-owned).
 *
 * Architecture proposal r10 §7.4: the THEME owns the registry and the plugin
 * only ever sees package KEYS. The engine validates `_ce_presentation` against
 * this list and populates the admin selector from it; it never resolves a
 * template path, a placement name, a CSS/JS handle or an asset file.
 *
 * Stage scope: this file registers the six package identities that the seed
 * migration maps the existing occasions onto, plus the private-preview shell.
 * Placement maps (templates, styles, scripts, ambient options) are added by the
 * presentation-dispatch stage; until then a package key is a valid, known
 * identity that renders nothing on its own.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the commemorative experience packages.
 *
 * @param mixed $packages Existing packages (a filter may return anything).
 * @return array
 */
function conexao_commemorative_register_presentations( $packages ) {
	if ( ! is_array( $packages ) ) {
		$packages = array();
	}

	$definitions = array(
		'halloween'       => __( 'Experiência de Halloween', 'conexao-br-irlanda' ),
		'christmas'       => __( 'Experiência de Natal', 'conexao-br-irlanda' ),
		'new_year'        => __( 'Experiência de Ano Novo', 'conexao-br-irlanda' ),
		'st_patricks_day' => __( 'Experiência do Dia de São Patrício', 'conexao-br-irlanda' ),
		'valentines_day'  => __( 'Experiência do Dia dos Namorados', 'conexao-br-irlanda' ),
		'easter'          => __( 'Experiência de Páscoa', 'conexao-br-irlanda' ),
	);

	foreach ( $definitions as $slug => $label ) {
		if ( isset( $packages[ $slug ] ) ) {
			continue;
		}

		$packages[ $slug ] = array(
			'label'      => $label,
			'placements' => array(),
		);
	}

	return $packages;
}
add_filter( 'conexao_commemorative_presentations', 'conexao_commemorative_register_presentations' );

/**
 * Print the non-dismissible private-preview banner.
 *
 * The ENGINE owns the preview authorisation; the THEME owns this markup (§2.2:
 * the engine never renders markup). The banner plus the `data-preview`
 * attribute make it impossible to mistake a preview for public activation.
 *
 * @return void
 */
function conexao_commemorative_preview_banner() {
	if ( ! function_exists( 'conexao_commemorative_is_preview' ) || ! conexao_commemorative_is_preview() ) {
		return;
	}

	$event = function_exists( 'conexao_commemorative_active' ) ? conexao_commemorative_active() : null;
	$key   = $event ? $event->event_key : '';

	printf(
		'<div class="conexao-preview-banner" data-preview="1" role="status"><strong>%1$s</strong> %2$s</div>',
		esc_html__( 'Pré-visualização', 'conexao-br-irlanda' ),
		esc_html(
			sprintf(
				/* translators: %s: event key. */
				__( 'Você está vendo uma pré-visualização privada do evento "%s". Esta página não está visível para o público.', 'conexao-br-irlanda' ),
				'' !== $key ? $key : '—'
			)
		)
	);
}
add_action( 'wp_body_open', 'conexao_commemorative_preview_banner', 1 );
