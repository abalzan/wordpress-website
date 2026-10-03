<?php
/**
 * Document titles and archive heading text
 *
 * The conexao_seo_title() pre_get_document_title filter plus the archive
 * title/description helpers used by archive.php for the section heading and
 * intro copy.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * ---------------------------------------------------------------------------
 * 1. SEO TITLE TEMPLATES
 * ---------------------------------------------------------------------------
 * Dynamic titles for every content type. Uses the WordPress title filter so
 * the browser tab, search results, and social previews all stay consistent.
 */
function conexao_seo_title( $title ) {
	if ( is_feed() ) {
		return $title;
	}

	$site_name = get_bloginfo( 'name' );

	if ( is_front_page() || is_home() ) {
		return $site_name . ' | ' . get_bloginfo( 'description' );
	}

	if ( is_singular( 'guide' ) ) {
		/* translators: %s is the guide title. */
		return single_post_title( '', false ) . ' | ' . __( 'Guia Prático', 'conexao-br-irlanda' ) . ' | ' . $site_name;
	}

	if ( is_singular( 'event' ) ) {
		return single_post_title( '', false ) . ' | ' . __( 'Eventos na Irlanda', 'conexao-br-irlanda' ) . ' | ' . $site_name;
	}

	if ( is_singular( 'job' ) ) {
		$job_location = get_post_meta( get_the_ID(), '_job_location', true );
		/* translators: %s is the job location, e.g. " em Dublin". */
		$location     = $job_location ? sprintf( __( ' em %s', 'conexao-br-irlanda' ), $job_location ) : '';
		return single_post_title( '', false ) . $location . ' | ' . __( 'Empregos', 'conexao-br-irlanda' ) . ' | ' . $site_name;
	}

	if ( is_singular( 'sponsor' ) ) {
		$terms = get_the_terms( get_the_ID(), 'conexao_category' );
		$cat   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : __( 'Apoiadores', 'conexao-br-irlanda' );
		return single_post_title( '', false ) . ' | ' . $cat . ' | ' . $site_name;
	}

	if ( is_singular( 'leisure' ) ) {
		$terms = get_the_terms( get_the_ID(), 'conexao_category' );
		$cat   = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : __( 'Lazer', 'conexao-br-irlanda' );
		return single_post_title( '', false ) . ' | ' . $cat . ' | ' . $site_name;
	}

	if ( is_singular( 'post' ) ) {
		return single_post_title( '', false ) . ' | ' . $site_name;
	}

	if ( is_post_type_archive( 'guide' ) ) {
		return __( 'Guias Práticos', 'conexao-br-irlanda' ) . ' | ' . $site_name;
	}

	if ( is_post_type_archive( 'event' ) ) {
		return __( 'Eventos na Irlanda', 'conexao-br-irlanda' ) . ' | ' . $site_name;
	}

	if ( is_post_type_archive( 'job' ) ) {
		return __( 'Empregos para Brasileiros na Irlanda', 'conexao-br-irlanda' ) . ' | ' . $site_name;
	}

	if ( is_post_type_archive( 'sponsor' ) ) {
		return __( 'Apoiadores na Irlanda', 'conexao-br-irlanda' ) . ' | ' . $site_name;
	}

	if ( is_post_type_archive( 'leisure' ) ) {
		return __( 'Lazer & Turismo na Irlanda', 'conexao-br-irlanda' ) . ' | ' . $site_name;
	}

	if ( is_tax( 'conexao_category' ) ) {
		return single_term_title( '', false ) . ' | ' . $site_name;
	}

	if ( is_tax( 'conexao_county' ) ) {
		return single_term_title( '', false ) . ' | Irlanda | ' . $site_name;
	}

	if ( is_search() ) {
		/* translators: %s is the search query. */
		return sprintf( __( 'Busca: %s', 'conexao-br-irlanda' ), get_search_query() ) . ' | ' . $site_name;
	}

	if ( is_404() ) {
		return __( 'Página não encontrada', 'conexao-br-irlanda' ) . ' | ' . $site_name;
	}

	return $title;
}
add_filter( 'pre_get_document_title', 'conexao_seo_title', 20 );

/**
 * ---------------------------------------------------------------------------
 * 7. BREADCRUMBS
 * ---------------------------------------------------------------------------
 * Returns breadcrumb trail data (name + url) for the current page.
 */
/**
 * Get the Portuguese archive title for the current CPT archive.
 */
function conexao_archive_title() {
	if ( is_post_type_archive( 'guide' ) ) {
		return __( 'Guias Práticos', 'conexao-br-irlanda' );
	}
	if ( is_post_type_archive( 'event' ) ) {
		return __( 'Eventos', 'conexao-br-irlanda' );
	}
	if ( is_post_type_archive( 'job' ) ) {
		return __( 'Empregos', 'conexao-br-irlanda' );
	}
	if ( is_post_type_archive( 'sponsor' ) ) {
		return __( 'Apoiadores', 'conexao-br-irlanda' );
	}
	if ( is_post_type_archive( 'leisure' ) ) {
		return __( 'Lazer', 'conexao-br-irlanda' );
	}
	if ( is_post_type_archive( 'course_provider' ) ) {
		return __( 'Cursos', 'conexao-br-irlanda' );
	}
	if ( is_tax( 'conexao_category' ) || is_tax( 'conexao_county' ) || is_category() || is_tag() ) {
		$term = get_queried_object();
		return $term && isset( $term->name ) ? $term->name : '';
	}

	return wp_strip_all_tags( get_the_archive_title() );
}

/**
 * Get the archive subtitle/description for the current CPT archive.
 */
function conexao_archive_description() {
	if ( is_post_type_archive( 'guide' ) ) {
		return __( 'Guias passo a passo para facilitar sua vida na Irlanda.', 'conexao-br-irlanda' );
	}
	if ( is_post_type_archive( 'event' ) ) {
		return __( 'Encontre eventos, encontros e atividades na Irlanda.', 'conexao-br-irlanda' );
	}
	if ( is_post_type_archive( 'job' ) ) {
		return __( 'Oportunidades de emprego para brasileiros na Irlanda.', 'conexao-br-irlanda' );
	}
	if ( is_post_type_archive( 'sponsor' ) ) {
		return __( 'Conheça os negócios que apoiam a comunidade brasileira na Irlanda.', 'conexao-br-irlanda' );
	}
	if ( is_post_type_archive( 'leisure' ) ) {
		return __( 'Descubra lugares para visitar, natureza, cultura, turismo e coisas para fazer na Irlanda.', 'conexao-br-irlanda' );
	}

	$description = get_the_archive_description();
	return $description ? wp_strip_all_tags( $description ) : '';
}
