<?php
// Stage 9 — Guide EN translation audit.
if ( ! defined( 'ABSPATH' ) ) { exit; }
function conexao_guide_translation_audit(): array {
	$manifest = conexao_guide_translation_manifest();
	$counts = array();
	$missing = array();
	$pairs = array();
	$active = function_exists( 'pll_get_post' ) && function_exists( 'pll_get_post_language' );
	$pt_ids = array();
	$en_ids = array();
	if ( $active ) {
		$base = array( 'post_type' => 'guide', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' );
		$pt_ids = get_posts( array_merge( $base, array( 'lang' => 'pt' ) ) );
		$en_ids = get_posts( array_merge( $base, array( 'lang' => 'en' ) ) );
	}
	$en_without_pt = array();
	foreach ( $en_ids as $en_id ) {
		$pt_sibling = (int) pll_get_post( (int) $en_id, 'pt' );
		if ( $pt_sibling <= 0 ) {
			$en_without_pt[] = array( 'id' => (int) $en_id, 'slug' => (string) get_post_field( 'post_name', $en_id ), 'title' => (string) get_post_field( 'post_title', $en_id ) );
		}
	}
	foreach ( $pt_ids as $pt_id ) {
		$slug = (string) get_post_field( 'post_name', $pt_id );
		if ( 'stage32-editorial-source' === $slug ) { continue; }
		$en_id = $active ? (int) pll_get_post( (int) $pt_id, 'en' ) : 0;
		if ( $en_id > 0 && 'publish' === get_post_status( $en_id ) && conexao_guide_translation_pair_ok( (int) $pt_id, $en_id ) ) {
			$pairs[] = array( 'pt_id' => (int) $pt_id, 'pt_slug' => $slug, 'en_id' => $en_id, 'en_slug' => (string) get_post_field( 'post_name', $en_id ), 'pt_url' => (string) get_permalink( (int) $pt_id ), 'en_url' => (string) get_permalink( $en_id ) );
			continue;
		}
		$missing[] = array( 'id' => (int) $pt_id, 'slug' => $slug, 'title' => (string) get_post_field( 'post_title', $pt_id ) );
	}
	$excluded = array();
	foreach ( $manifest as $pt_slug => $en ) {
		if ( ! get_page_by_path( (string) $pt_slug, OBJECT, 'guide' ) ) {
			$excluded[] = array( 'pt_slug' => (string) $pt_slug, 'en_slug' => (string) $en['en_slug'], 'reason' => 'PT guide not present in this site' );
		}
	}
	$used_terms = array();
	foreach ( $pt_ids as $pt_id ) {
		$slug = (string) get_post_field( 'post_name', $pt_id );
		if ( 'stage32-editorial-source' === $slug ) { continue; }
		foreach ( array( 'conexao_category', 'conexao_county', 'conexao_tag' ) as $taxonomy ) {
			$terms = wp_get_post_terms( (int) $pt_id, $taxonomy );
			if ( is_wp_error( $terms ) ) { continue; }
			foreach ( $terms as $term ) { $used_terms[ $taxonomy ][ (int) $term->term_id ] = (string) $term->slug; }
		}
	}
	$terms_missing = array();
	foreach ( $used_terms as $taxonomy => $terms ) {
		if ( 'conexao_county' === $taxonomy ) { continue; }
		foreach ( $terms as $term_id => $slug ) {
			$en_term = function_exists( 'pll_get_term' ) ? (int) pll_get_term( (int) $term_id, 'en' ) : 0;
			if ( $en_term <= 0 ) { $terms_missing[ $taxonomy ][ $term_id ] = $slug; }
		}
	}
	$used_count = 0;
	foreach ( $used_terms as $terms ) { $used_count += count( $terms ); }
	$missing_count = 0;
	foreach ( $terms_missing as $terms ) { $missing_count += count( $terms ); }
	$counts['total public PT guides'] = count( $pt_ids ) - 1;
	$counts['total public EN guides'] = count( $en_ids );
	$counts['translated guide pairs verified'] = count( $pairs );
	$counts['eligible public PT guides missing EN'] = count( $missing );
	$counts['EN guides missing PT translation'] = count( $en_without_pt );
	$counts['guides excluded (documented)'] = count( $excluded );
	$counts['taxonomy terms used by PT guides'] = $used_count;
	$counts['taxonomy terms missing EN'] = $missing_count;
	return array( 'counts' => $counts, 'missing' => $missing, 'en_without_pt' => $en_without_pt, 'pairs' => $pairs, 'excluded' => $excluded, 'taxonomy' => array( 'used' => $used_terms, 'missing' => $terms_missing ), 'pass' => 0 === count( $missing ) && 0 === $missing_count );
}
