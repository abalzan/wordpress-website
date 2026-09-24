<?php
/**
 * Stage 6 — Job EN translation completeness audit (the completion gate).
 *
 * Deterministic inventory of the Job translation state. The gate is
 * `eligible public PT jobs missing EN === 0` plus zero EN jobs without a PT
 * sibling plus zero duplicate pairs; a manifest entry whose PT job is absent
 * from this site is reported as a documented exclusion (never a silent pass).
 *
 * @package Conexao_Job_Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventory the Job EN translation state.
 *
 * @return array {
 *     @type array $counts        Label => value rows (the printable audit).
 *     @type array $missing       Public PT jobs with no published EN translation.
 *     @type array $en_without_pt Public EN jobs with no PT sibling.
 *     @type array $pairs         Verified PT↔EN job pairs.
 *     @type array $excluded      Manifest entries absent from this site.
 *     @type array $taxonomy      Terms actually used by public PT jobs.
 *     @type bool  $pass          Gate result.
 * }
 */
function conexao_job_translation_audit(): array {
	$manifest = conexao_job_translation_manifest();
	$counts   = array();
	$missing  = array();
	$pairs    = array();

	$active = function_exists( 'pll_get_post' ) && function_exists( 'pll_get_post_language' );

	$pt_ids = array();
	$en_ids = array();

	if ( $active ) {
		$base   = array(
			'post_type'      => 'job',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		);
		$pt_ids = get_posts( array_merge( $base, array( 'lang' => 'pt' ) ) );
		$en_ids = get_posts( array_merge( $base, array( 'lang' => 'en' ) ) );
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

		if ( $en_id > 0 && 'publish' === get_post_status( $en_id ) && conexao_job_translation_pair_ok( (int) $pt_id, $en_id ) ) {
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
	foreach ( $manifest['jobs'] as $pt_slug => $en ) {
		if ( ! get_page_by_path( (string) $pt_slug, OBJECT, 'job' ) ) {
			$excluded[] = array(
				'pt_slug' => (string) $pt_slug,
				'en_slug' => (string) $en['en_slug'],
				'reason'  => 'PT job not present in this site (not part of this install)',
			);
		}
	}

	// Taxonomy terms actually used by the public PT jobs (never translate
	// unused terms merely because they exist in the database).
	$used_terms = array();
	foreach ( $pt_ids as $pt_id ) {
		foreach ( array( 'conexao_category', 'conexao_county', 'conexao_tag' ) as $taxonomy ) {
			$terms = wp_get_post_terms( (int) $pt_id, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$used_terms[ $taxonomy ][ (int) $term->term_id ] = (string) $term->slug;
			}
		}
	}

	$terms_missing = array();
	foreach ( $used_terms as $taxonomy => $terms ) {
		// conexao_county is shared geography (one term for both languages by
		// architecture); only translated taxonomies can be "missing" an EN term.
		if ( 'conexao_county' === $taxonomy ) {
			continue;
		}
		foreach ( $terms as $term_id => $slug ) {
			$en_term = function_exists( 'pll_get_term' ) ? (int) pll_get_term( (int) $term_id, 'en' ) : 0;
			if ( $en_term <= 0 ) {
				$terms_missing[ $taxonomy ][ $term_id ] = $slug;
			}
		}
	}

	$jobs_page = conexao_job_translation_verify_jobs_page();

	$used_count = 0;
	foreach ( $used_terms as $terms ) {
		$used_count += count( $terms );
	}
	$missing_count = 0;
	foreach ( $terms_missing as $terms ) {
		$missing_count += count( $terms );
	}

	$counts['total public PT jobs']               = count( $pt_ids );
	$counts['total public EN jobs']               = count( $en_ids );
	$counts['translated job pairs verified']      = count( $pairs );
	$counts['eligible public PT jobs missing EN'] = count( $missing );
	$counts['EN jobs missing PT translation']     = count( $en_without_pt );
	$counts['jobs excluded (documented)']         = count( $excluded );
	$counts['taxonomy terms used by PT jobs']     = $used_count;
	$counts['taxonomy terms missing EN']          = $missing_count;
	$counts['Jobs page PT ID']                    = $jobs_page['pt_id'];
	$counts['Jobs page EN ID']                    = $jobs_page['en_id'];
	$counts['Jobs page pair']                     = $jobs_page['status'];

	return array(
		'counts'        => $counts,
		'missing'       => $missing,
		'en_without_pt' => $en_without_pt,
		'pairs'         => $pairs,
		'excluded'      => $excluded,
		'taxonomy'      => array( 'used' => $used_terms, 'missing' => $terms_missing ),
		'jobs_page'     => $jobs_page,
		'pass'          => 0 === count( $missing ) && 0 === count( $en_without_pt ) && 0 === $missing_count && 'verified' === $jobs_page['status'],
	);
}
