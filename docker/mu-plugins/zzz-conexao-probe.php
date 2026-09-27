<?php
/**
 * TEMPORARY diagnostic probe (Phase 1/2 investigation). Delete after use.
 */
add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['conexao_probe'] ) ) {
		return;
	}
	$out = array();
	$out['requested_lang']  = conexao_requested_language_slug();
	$out['current_lang']    = function_exists('pll_current_language') ? pll_current_language('slug') : 'n/a';

	$base = array( 'post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false );
	$out['get_posts_default']       = count( get_posts( $base ) );
	$out['get_posts_lang_en_pt']    = count( get_posts( array_merge( $base, array( 'lang' => 'en,pt' ) ) ) );
	$out['helper_county_terms']     = count( conexao_get_terms_for_post_type( 'conexao_county', 'event' ) );
	$out['helper_town_terms']      = count( conexao_get_event_towns() );
	$out['helper_category_terms']   = count( conexao_get_terms_for_post_type( 'conexao_category', 'event' ) );
	$out['is_b2_event']            = conexao_is_b2_post_type( 'event' );
	$out['archive_url']            = get_post_type_archive_link( 'event' );
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $out );
	exit;
}, 1 );
