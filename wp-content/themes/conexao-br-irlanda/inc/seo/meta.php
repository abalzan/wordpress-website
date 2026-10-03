<?php
/**
 * Meta descriptions and the resolved page language
 *
 * The meta description output and the inLanguage resolver shared by the
 * Open Graph and schema.org layers.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ---------------------------------------------------------------------------
 * 2. META DESCRIPTION TEMPLATES
 * ---------------------------------------------------------------------------
 * Uses a manually entered SEO description when available, otherwise falls
 * back to a dynamic template. Kept around 150-160 characters.
 */
function conexao_seo_meta_description() {
	$description = '';

	// Manual override (editor-entered) takes priority.
	if ( is_singular() ) {
		$manual = get_post_meta( get_the_ID(), 'conexao_meta_description', true );
		if ( $manual ) {
			$description = $manual;
		} elseif ( has_excerpt() ) {
			$description = get_the_excerpt();
		}
	}

	// Dynamic templates per content type.
	if ( ! $description ) {
		if ( is_front_page() || is_home() ) {
			$description = get_bloginfo( 'description' );
		} elseif ( is_singular( 'guide' ) ) {
			/* translators: %s is the guide title. */
			$description = sprintf( __( 'Guia prático: %s. Passo a passo completo para brasileiros na Irlanda.', 'conexao-br-irlanda' ), get_the_title() );
		} elseif ( is_singular( 'event' ) ) {
			/* translators: %s is the event title. */
			$description = sprintf( __( 'Evento: %s. Participe de eventos e atividades na Irlanda.', 'conexao-br-irlanda' ), get_the_title() );
		} elseif ( is_singular( 'job' ) ) {
			/* translators: %s is the job title. */
			$description = sprintf( __( 'Vaga de emprego: %s. Oportunidade para brasileiros na Irlanda.', 'conexao-br-irlanda' ), get_the_title() );
		} elseif ( is_singular( 'sponsor' ) ) {
			/* translators: %s is the sponsor name. */
			$description = sprintf( __( 'Apoiador: %s. Conheça quem apoia e fortalece a comunidade brasileira na Irlanda.', 'conexao-br-irlanda' ), get_the_title() );
		} elseif ( is_singular( 'leisure' ) ) {
			/* translators: %s is the destination name. */
			$description = sprintf( __( 'Lazer e turismo: %s. Descubra este local incrível para visitar na Irlanda.', 'conexao-br-irlanda' ), get_the_title() );
		} elseif ( is_post_type_archive( 'guide' ) ) {
			$description = __( 'Guias práticos completos para brasileiros na Irlanda. PPS Number, Medical Card, moradia, emprego e mais.', 'conexao-br-irlanda' );
		} elseif ( is_post_type_archive( 'event' ) ) {
			$description = __( 'Eventos, encontros e atividades na Irlanda. Agenda cultural e networking.', 'conexao-br-irlanda' );
		} elseif ( is_post_type_archive( 'job' ) ) {
			$description = __( 'Vagas de emprego para brasileiros na Irlanda. Oportunidades em saúde, TI, construção e mais.', 'conexao-br-irlanda' );
		} elseif ( is_post_type_archive( 'sponsor' ) ) {
			$description = __( 'Conheça as organizações e empresas que apoiam a comunidade brasileira na Irlanda.', 'conexao-br-irlanda' );
		} elseif ( is_post_type_archive( 'leisure' ) ) {
			$description = __( 'Descubra lugares para visitar, natureza, cultura, turismo e coisas para fazer na Irlanda. Guia de lazer por condado.', 'conexao-br-irlanda' );
		} elseif ( is_tax( 'conexao_category' ) ) {
			/* translators: %s is the category name. */
			$description = sprintf( __( 'Conteúdo sobre %s para brasileiros na Irlanda. Guias e recursos úteis.', 'conexao-br-irlanda' ), single_term_title( '', false ) );
		} elseif ( is_tax( 'conexao_county' ) ) {
			/* translators: %s is the county name (a proper noun — never translated). */
			$description = sprintf( __( 'Guia sobre %s na Irlanda. Eventos, apoiadores, guias e empregos para brasileiros.', 'conexao-br-irlanda' ), single_term_title( '', false ) );
		} elseif ( is_404() ) {
			$description = __( 'Página não encontrada. Explore guias e eventos para brasileiros na Irlanda.', 'conexao-br-irlanda' );
		}
	}

	// Trim to ~160 chars.
	if ( $description ) {
		$description = wp_strip_all_tags( $description );
		$description = mb_substr( $description, 0, 160 );
		if ( mb_strlen( $description ) >= 160 ) {
			$description = mb_substr( $description, 0, 157 ) . '...';
		}
		echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
	}
}
add_action( 'wp_head', 'conexao_seo_meta_description', 5 );

/**
 * BCP-47 language tag for the current request (schema.org `inLanguage`).
 *
 * Reads the Stage 1 locale abstraction, so it follows the active Polylang
 * language automatically ("pt-BR" on Portuguese requests, "en-US" on
 * English ones) with no extra language logic in the SEO layer.
 *
 * @return string
 */
function conexao_seo_in_language(): string {
	return str_replace( '_', '-', conexao_current_locale() );
}
