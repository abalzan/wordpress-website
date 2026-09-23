<?php
/**
 * Stage 5 — Blog EN translation completeness audit (the completion gate).
 *
 * Deterministic inventory of the Blog translation state. The gate is
 * `eligible public PT posts missing EN === 0` plus zero EN posts without a PT
 * sibling; a manifest entry whose PT post is absent from this site is reported
 * as a documented exclusion (never as a silent pass).
 *
 * @package Conexao_Blog_Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventory the Blog EN translation state.
 *
 * @return array {
 *     @type array  $counts        Label => value rows (the printable audit).
 *     @type array  $missing       Public PT posts with no published EN translation.
 *     @type array  $en_without_pt Public EN posts with no PT sibling.
 *     @type array  $pairs         Verified PT↔EN post pairs.
 *     @type array  $excluded      Manifest entries absent from this site.
 *     @type bool   $pass          Gate result.
 * }
 */
function conexao_blog_translation_audit(): array {
	$manifest = conexao_blog_translation_manifest();
	$counts   = array();
	$missing  = array();
	$pairs    = array();

	$active = conexao_polylang_active();

	$pt_ids = array();
	$en_ids = array();

	if ( $active ) {
		$pt_ids = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'lang'           => 'pt',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$en_ids = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'lang'           => 'en',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	}

	$en_without_pt = array();

	foreach ( $en_ids as $en_id ) {
		$pt_sibling = (int) pll_get_post( (int) $en_id, 'pt' );
		if ( $pt_sibling <= 0 ) {
			$en_without_pt[] = array(
				'id'    => (int) $en_id,
				'slug'  => (string) get_post_field( 'post_name', $en_id ),
				'title' => (string) get_post_field( 'post_title', $en_id ),
			);
		}
	}

	foreach ( $pt_ids as $pt_id ) {
		$en_id = $active ? (int) pll_get_post( (int) $pt_id, 'en' ) : 0;

		if ( $en_id > 0 && conexao_blog_translation_pair_ok( (int) $pt_id, $en_id ) ) {
			$pairs[] = array(
				'pt_id'   => (int) $pt_id,
				'pt_slug' => (string) get_post_field( 'post_name', $pt_id ),
				'en_id'   => $en_id,
				'en_slug' => (string) get_post_field( 'post_name', $en_id ),
				'pt_url'  => (string) get_permalink( (int) $pt_id ),
				'en_url'  => (string) get_permalink( $en_id ),
			);
			continue;
		}

		$missing[] = array(
			'id'    => (int) $pt_id,
			'slug'  => (string) get_post_field( 'post_name', $pt_id ),
			'title' => (string) get_post_field( 'post_title', $pt_id ),
		);
	}

	$excluded = array();
	foreach ( $manifest['posts'] as $pt_slug => $en ) {
		if ( ! get_page_by_path( (string) $pt_slug, OBJECT, 'post' ) ) {
			$excluded[] = array(
				'pt_slug' => (string) $pt_slug,
				'en_slug' => (string) $en['en_slug'],
				'reason'  => 'PT post not present in this site (not part of this Blog install)',
			);
		}
	}

	$posts_page_pt = (int) get_option( 'page_for_posts' );
	$posts_page_en = $posts_page_pt > 0 && $active ? (int) pll_get_post( $posts_page_pt, 'en' ) : 0;
	$posts_page_ok = $posts_page_pt > 0 && $posts_page_en > 0 && conexao_blog_translation_pair_ok( $posts_page_pt, $posts_page_en );

	$categories = conexao_blog_translation_term_audit( 'category', $pt_ids );
	$tags       = conexao_blog_translation_term_audit( 'post_tag', $pt_ids );

	$counts['total public PT Blog posts']          = count( $pt_ids );
	$counts['total public EN Blog posts']          = count( $en_ids );
	$counts['translated post pairs verified']      = count( $pairs );
	$counts['eligible public PT posts missing EN'] = count( $missing );
	$counts['EN posts missing PT translation']     = count( $en_without_pt );
	$counts['posts excluded (documented)']         = count( $excluded );
	$counts['categories used by Blog posts']       = $categories['used'];
	$counts['categories translated (linked EN)']   = $categories['translated'];
	$counts['categories missing EN term']          = count( $categories['missing'] );
	$counts['tags used by Blog posts']             = $tags['used'];
	$counts['tags translated (linked EN)']         = $tags['translated'];
	$counts['Posts Page PT ID']                    = $posts_page_pt;
	$counts['Posts Page EN ID']                    = $posts_page_en;
	$counts['posts page translation verified']     = $posts_page_ok ? 'yes' : 'no';

	return array(
		'counts'        => $counts,
		'missing'       => $missing,
		'en_without_pt' => $en_without_pt,
		'pairs'         => $pairs,
		'excluded'      => $excluded,
		'categories'    => $categories,
		'tags'          => $tags,
		'pass'          => 0 === count( $missing ) && 0 === count( $en_without_pt ) && 0 === count( $categories['missing'] ),
	);
}

/**
 * Term-level translation audit for the terms actually used by public PT posts.
 *
 * Never translates (or reports on) unused terms merely because they exist.
 *
 * @param string $taxonomy Taxonomy name.
 * @param int[]  $pt_ids   Public PT post IDs.
 * @return array{used:int,translated:int,missing:array<int,string>}
 */
function conexao_blog_translation_term_audit( string $taxonomy, array $pt_ids ): array {
	$used       = array();
	$translated = 0;
	$missing    = array();

	foreach ( $pt_ids as $pt_id ) {
		$terms = wp_get_post_terms( (int) $pt_id, $taxonomy );

		if ( is_wp_error( $terms ) ) {
			continue;
		}

		foreach ( $terms as $term ) {
			$used[ (int) $term->term_id ] = (string) $term->slug;
		}
	}

	foreach ( $used as $term_id => $slug ) {
		$en_term = function_exists( 'pll_get_term' ) ? (int) pll_get_term( (int) $term_id, 'en' ) : 0;

		if ( $en_term > 0 ) {
			++$translated;
			continue;
		}

		$missing[ $term_id ] = $slug;
	}

	return array(
		'used'       => count( $used ),
		'translated' => $translated,
		'missing'    => $missing,
	);
}
