<?php
/**
 * Stage 6 (Job) configuration for the shared translation-rollout engine.
 *
 * Small declarative consumer of conexao-translation-rollout: stage identity,
 * languages, portable-identity strategy (PT slug), field policy, gate inputs
 * and the WordPress-bound adapter. No lifecycle orchestration lives here.
 *
 * Historical behaviour preserved from includes/apply.php + includes/audit.php:
 * one linked EN job per eligible public PT job, verbatim _job_* layer, shared
 * thumbnail, both-directions pair verification, EN-slug collision gate, PT
 * snapshot gate, Jobs landing page pair verified (Stage 4.5 owns the pages).
 *
 * @package Conexao_Job_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The stage's versioned data manifest, in the shape the engine validates.
 *
 * The authored English copy is NOT re-declared here: this adapter only
 * normalises the historical map (`conexao_job_translation_manifest()`) into
 * the engine's `source_lang` / `target_lang` / `records` contract, keyed by
 * the PT slug (the portable identity).
 *
 * @return array Engine manifest payload.
 */
function conexao_job_translation_engine_manifest(): array {
	$raw     = conexao_job_translation_manifest();
	$records = array();
	foreach ( (array) $raw['jobs'] as $pt_slug => $en ) {
		$records[ (string) $pt_slug ] = array(
			'en_slug'             => (string) ( $en['en_slug'] ?? '' ),
			'en_title'            => (string) ( $en['en_title'] ?? '' ),
			'en_excerpt'          => (string) ( $en['en_excerpt'] ?? '' ),
			'en_content'          => (string) ( $en['en_content'] ?? '' ),
			'en_meta_description' => (string) ( $en['en_meta_description'] ?? '' ),
			'en_meta'             => isset( $en['en_meta'] ) && is_array( $en['en_meta'] ) ? $en['en_meta'] : array(),
		);
	}
	return array(
		'source_lang' => 'pt',
		'target_lang' => 'en',
		'records'     => $records,
	);
}
/**
 * The WordPress-bound primitives the engine orchestrates.
 *
 * Every function here is a thin, stage-specific adapter: lookup, create,
 * repair, link, verify, remove. No planning, counting, snapshot orchestration
 * or gate calculation lives in this file - the engine owns all of it.
 *
 * @return array Adapter callables.
 */
function conexao_job_translation_engine_adapter(): array {
	return array(
		'find_pt'        => static function ( string $stable_key ) {
			$post = get_page_by_path( $stable_key, OBJECT, 'job' );
			if ( ! $post instanceof WP_Post ) {
				return null; }
			return array(
				'id'     => (int) $post->ID,
				'status' => (string) $post->post_status,
			);
		},
		'find_en_for_pt' => static function ( int $pt_id, string $expected_en_slug = '' ) {
			if ( ! function_exists( 'pll_get_post' ) ) {
				return array(
					'en_id'           => 0,
					'en_status'       => 'absent',
					'pair_ok'         => false,
					'en_slug_matches' => true,
				); }
			$en_id = (int) pll_get_post( $pt_id, 'en' );
			if ( $en_id <= 0 || $en_id === $pt_id ) {
				return array(
					'en_id'           => 0,
					'en_status'       => 'absent',
					'pair_ok'         => false,
					'en_slug_matches' => true,
				); }
			$status = (string) get_post_status( $en_id );
			$pair = conexao_job_translation_pair_ok( $pt_id, $en_id );
			$actual = (string) get_post_field( 'post_name', $en_id );
			return array(
				'en_id'           => $en_id,
				'en_status'       => $status,
				'pair_ok'         => $pair,
				'en_slug_matches' => ( '' === $expected_en_slug || $actual === $expected_en_slug ),
			);
		},
		'slug_collision' => static function ( string $en_slug, int $pt_id ): bool {
			if ( '' === $en_slug ) {
				return false; }
			$hit = get_page_by_path( $en_slug, OBJECT, 'job' );
			if ( ! $hit instanceof WP_Post || (int) $hit->ID === $pt_id ) {
				return false; }
			if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_get_post_language' ) ) {
				$hit_lang = (string) pll_get_post_language( (int) $hit->ID, 'slug' );
				$pt_lang = (string) pll_get_post_language( $pt_id, 'slug' );
				if ( '' !== $hit_lang && '' !== $pt_lang && $hit_lang !== $pt_lang ) {
					return false; }
				$back = (int) pll_get_post( (int) $hit->ID, 'pt' === $hit_lang ? 'en' : 'pt' );
				if ( $back === $pt_id || (int) pll_get_post( $pt_id, 'en' ) === (int) $hit->ID ) {
					return false; }
			}
			return true;
		},
		'create_en'      => static function ( int $pt_id, array $row ) {
			$pt = get_post( $pt_id );
			if ( ! $pt instanceof WP_Post ) {
				return new WP_Error( 'conexao_job_missing_pt', 'PT job missing.' ); }
			$en_id = wp_insert_post(
				array(
					'post_type'     => 'job',
					'post_name'     => (string) $row['en_slug'],
					'post_title'    => (string) $row['en_title'],
					'post_content'  => (string) $row['en_content'],
					'post_excerpt'  => (string) $row['en_excerpt'],
					'post_status'   => 'publish',
					'post_date'     => $pt->post_date,
					'post_date_gmt' => $pt->post_date_gmt,
					'post_author'   => (int) $pt->post_author,
					'menu_order'    => (int) $pt->menu_order,
				),
				true
			);
			return is_wp_error( $en_id ) ? $en_id : (int) $en_id;
		},
		'repair_en'      => static function ( int $pt_id, int $en_id, array $row ): bool {
			$en_post = get_post( $en_id );
			if ( $en_post instanceof WP_Post && $en_post->post_name !== (string) $row['en_slug'] ) {
				wp_update_post(
					array(
						'ID'        => $en_id,
						'post_name' => (string) $row['en_slug'],
					)
				);
			}
			return true;
		},
		'link_pair'      => static function ( int $pt_id, int $en_id ): bool {
			if ( ! function_exists( 'pll_set_post_language' ) ) {
				return false; }
			pll_set_post_language( $en_id, 'en' );
			if ( function_exists( 'pll_get_post_language' ) && ! pll_get_post_language( $pt_id ) ) {
				pll_set_post_language( $pt_id, 'pt' ); }
			if ( function_exists( 'pll_save_post_translations' ) ) {
				pll_save_post_translations(
					array(
						'pt' => $pt_id,
						'en' => $en_id,
					)
				); }
			return true;
		},
		'pair_ok'        => static function ( int $pt_id, int $en_id ): bool {
			return conexao_job_translation_pair_ok( $pt_id, $en_id ); },
		'remove_en'      => static function ( int $en_id ): bool {
			return (bool) wp_delete_post( $en_id, true ); },
	);
}
/**
 * The declarative stage configuration.
 *
 * Declares stage identity, languages, the portable-identity strategy, the
 * field mapping callbacks, the gate inputs and the rollback policy. It
 * contains no lifecycle orchestration.
 *
 * @return array Stage configuration.
 */
function conexao_job_translation_engine_config(): array {
	return array(
		'stage'                   => 'job',
		'source_post_type'        => 'job',
		'source_lang'             => 'pt',
		'target_lang'             => 'en',
		'manifest_callback'       => 'conexao_job_translation_engine_manifest',
		'snapshot_callback'       => 'conexao_job_translation_snapshot_job',
		'build_en_args_callback'  => 'conexao_job_translation_engine_manifest',
		'copy_fields_callback'    => static function ( int $pt_id, int $en_id, array $row ): int {
			$copied = conexao_job_translation_copy_fields( $pt_id, $en_id, $row );
			if ( '' !== (string) $row['en_meta_description'] ) {
				update_post_meta( $en_id, 'conexao_meta_description', (string) $row['en_meta_description'] ); }
			return count( $copied ) + 1;
		},
		'verify_landing_callback' => 'conexao_job_translation_verify_jobs_page',
		'extra_gate_callback'     => null,
		'allow_remove'            => false,
		'run_callback'            => static function ( array $args = array() ) {
			return Conexao_Translation_Rollout_Engine::run( conexao_job_translation_engine_config(), conexao_job_translation_engine_adapter(), $args );
		},
	);
}
