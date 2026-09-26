<?php
/**
 * Stage 6 — job-specific field mapping and eligibility helpers.
 *
 * These are the ONLY functions the migrated stage still owns. The generic
 * rollout lifecycle (inventory, dry-run plan, snapshot orchestration, apply
 * traversal, verify/gate framework, result formatting, admin security flow)
 * is owned by the shared engine `conexao-translation-rollout`.
 *
 * Retained stage-specific behaviour, unchanged from the pre-Stage-H
 * includes/apply.php + includes/audit.php:
 *   - conexao_job_translation_snapshot_job()     — the PT field list the
 *     engine snapshots and byte-compares (identity, content, terms, _job_*
 *     meta, thumbnail, language, meta description).
 *   - conexao_job_translation_pair_ok()          — both-directions Polylang
 *     check for a job pair.
 *   - conexao_job_translation_verify_jobs_page() — the Jobs landing page pair
 *     is VERIFIED only (Stage 4.5 owns the pages); it is a gate input.
 *   - conexao_job_translation_copy_fields()      — the verbatim `_job_*` field
 *     layer + shared featured image.
 *
 * @package Conexao_Job_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Immutable identity + content snapshot of a job (PT regression gate).
 *
 * @param int $post_id Post ID.
 * @return array
 */
function conexao_job_translation_snapshot_job( int $post_id ): array {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$job_meta = array();
	foreach ( array_keys( (array) get_post_meta( $post_id ) ) as $key ) {
		if ( 0 === strpos( (string) $key, '_job_' ) ) {
			$job_meta[ (string) $key ] = get_post_meta( $post_id, (string) $key, true );
		}
	}
	ksort( $job_meta );

	$terms = array();
	foreach ( array( 'conexao_category', 'conexao_county', 'conexao_tag' ) as $taxonomy ) {
		$terms[ $taxonomy ] = array_map( 'intval', wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) ) );
	}

	return array(
		'post_name'    => $post->post_name,
		'post_title'   => $post->post_title,
		'post_content' => $post->post_content,
		'post_excerpt' => $post->post_excerpt,
		'post_status'  => $post->post_status,
		'post_date'    => $post->post_date,
		'post_author'  => (int) $post->post_author,
		'menu_order'   => (int) $post->menu_order,
		'thumbnail'    => (int) get_post_thumbnail_id( $post_id ),
		'terms'        => $terms,
		'job_meta'     => $job_meta,
		'language'     => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'slug' ) : '',
		'meta_desc'    => (string) get_post_meta( $post_id, 'conexao_meta_description', true ),
	);
}

/**
 * Both-directions check of a Polylang post relationship.
 *
 * @param int $pt_id Portuguese post ID.
 * @param int $en_id English post ID.
 * @return bool
 */
function conexao_job_translation_pair_ok( int $pt_id, int $en_id ): bool {
	if ( $pt_id <= 0 || $en_id <= 0 || $pt_id === $en_id ) {
		return false;
	}

	return (int) pll_get_post( $pt_id, 'en' ) === $en_id
		&& (int) pll_get_post( $en_id, 'pt' ) === $pt_id;
}

/**
 * Verify the Jobs landing Page pair (read-only — Stage 4.5 owns the pages).
 *
 * @return array{pt_id:int,en_id:int,status:string}
 */
function conexao_job_translation_verify_jobs_page(): array {
	$manifest = conexao_job_translation_manifest();
	$pt_slug  = (string) $manifest['source']['jobs_page_pt_slug'];

	$pt_page = get_page_by_path( $pt_slug, OBJECT, 'page' );

	if ( ! $pt_page instanceof WP_Post ) {
		return array(
			'pt_id'  => 0,
			'en_id'  => 0,
			'status' => 'missing PT page — run the Stage 4.5 page migration first',
		);
	}

	$pt_id = (int) $pt_page->ID;
	$en_id = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $pt_id, 'en' ) : 0;

	if ( $en_id <= 0 ) {
		return array(
			'pt_id'  => $pt_id,
			'en_id'  => 0,
			'status' => 'PT page has no EN translation — run the Stage 4.5 page migration first',
		);
	}

	if ( 'publish' !== get_post_status( $en_id ) ) {
		return array(
			'pt_id'  => $pt_id,
			'en_id'  => $en_id,
			'status' => 'EN jobs page is not published',
		);
	}

	if ( ! conexao_job_translation_pair_ok( $pt_id, $en_id ) ) {
		return array(
			'pt_id'  => $pt_id,
			'en_id'  => $en_id,
			'status' => 'page pair link broken',
		);
	}

	return array(
		'pt_id'  => $pt_id,
		'en_id'  => $en_id,
		'status' => 'verified',
	);
}

/**
 * Copy the verbatim field layer from the PT record to its EN translation.
 *
 * Copies: the shared thumbnail and every `_job_*` meta key actually stored on
 * the PT record (the measured production job stores only empty values, and
 * every structured value — company, location, salary, type, dates, URLs,
 * source, editorial status — is language-neutral by policy). Manifest
 * `en_meta` overrides win when present (text-bearing fields such as
 * `_job_requirements`).
 *
 * @param int   $pt_id   PT job ID.
 * @param int   $en_id   EN job ID.
 * @param array $en      Manifest row.
 * @return string[] Copied meta keys (for the report).
 */
function conexao_job_translation_copy_fields( int $pt_id, int $en_id, array $en ): array {
	$copied = array();

	$thumb = (int) get_post_thumbnail_id( $pt_id );
	if ( $thumb > 0 ) {
		set_post_thumbnail( $en_id, $thumb );
		$copied[] = '_thumbnail_id';
	}

	$en_meta = isset( $en['en_meta'] ) && is_array( $en['en_meta'] ) ? $en['en_meta'] : array();

	foreach ( array_keys( (array) get_post_meta( $pt_id ) ) as $key ) {
		$key = (string) $key;
		if ( 0 !== strpos( $key, '_job_' ) ) {
			continue;
		}
		$value = array_key_exists( $key, $en_meta ) ? (string) $en_meta[ $key ] : get_post_meta( $pt_id, $key, true );
		update_post_meta( $en_id, $key, $value );
		$copied[] = $key;
	}

	// Policy-listed keys that are absent on the PT record stay absent on EN —
	// no empty ghosts are created for fields the site never used.

	return $copied;
}
