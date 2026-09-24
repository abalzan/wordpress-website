<?php
/**
 * Stage 6 — Job EN translation apply engine.
 *
 * Shared by the admin importer (Tools → EN Job Translations) and the WP-CLI
 * runner (scripts/stage6-job-translate.php).
 *
 * Contract:
 *   - the Portuguese originals are NEVER modified. The only Portuguese write
 *     ever performed is a Polylang language backfill (a record with no
 *     language yet gets the default language so it can be linked) — every PT
 *     job is snapshotted before the run and compared after it;
 *   - exactly ONE linked English translation per Portuguese job;
 *   - idempotent: re-running verifies the EN record it owns instead of
 *     creating a second one, and never touches an EN record that is not in
 *     the manifest;
 *   - the Polylang relationship is verified from BOTH directions before a row
 *     is reported as successful;
 *   - the Jobs landing Page is verified (it must exist in PT and EN, linked),
 *     never created or modified — Stage 4.5 owns it;
 *   - structured `_job_*` meta is copied verbatim (proper nouns, URLs, dates,
 *     controlled values); the featured image is shared.
 *
 * @package Conexao_Job_Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		return array( 'pt_id' => 0, 'en_id' => 0, 'status' => 'missing PT page — run the Stage 4.5 page migration first' );
	}

	$pt_id = (int) $pt_page->ID;
	$en_id = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $pt_id, 'en' ) : 0;

	if ( $en_id <= 0 ) {
		return array( 'pt_id' => $pt_id, 'en_id' => 0, 'status' => 'PT page has no EN translation — run the Stage 4.5 page migration first' );
	}

	if ( 'publish' !== get_post_status( $en_id ) ) {
		return array( 'pt_id' => $pt_id, 'en_id' => $en_id, 'status' => 'EN jobs page is not published' );
	}

	if ( ! conexao_job_translation_pair_ok( $pt_id, $en_id ) ) {
		return array( 'pt_id' => $pt_id, 'en_id' => $en_id, 'status' => 'page pair link broken' );
	}

	return array( 'pt_id' => $pt_id, 'en_id' => $en_id, 'status' => 'verified' );
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

/**
 * Run the Job EN translation (dry-run preview or apply).
 *
 * @param array $args { @type bool $dry_run Report only, no writes. }
 * @return array{summary:array,rows:array,excluded:array,jobs_page:array,audit:array}
 */
function conexao_job_translation_run( array $args = array() ): array {
	$dry_run  = ! empty( $args['dry_run'] );
	$manifest = conexao_job_translation_manifest();

	$summary = array(
		'created'     => 0,
		'updated'     => 0,
		'skipped'     => 0,
		'errors'      => 0,
		'meta_copied' => 0,
		'jobs_page'   => '',
		'pt_changed'  => 0,
	);

	$rows = array();

	if ( ! function_exists( 'pll_get_post' ) || ! function_exists( 'pll_save_post_translations' ) ) {
		return array(
			'summary'   => array_merge( $summary, array( 'errors' => 1, 'jobs_page' => 'Polylang missing' ) ),
			'rows'      => array( array( 'pt_slug' => '', 'pt_id' => 0, 'en_id' => 0, 'en_url' => '', 'action' => 'error', 'message' => 'Polylang is not active' ) ),
			'excluded'  => array(),
			'jobs_page' => array( 'pt_id' => 0, 'en_id' => 0, 'status' => 'Polylang missing' ),
			'audit'     => function_exists( 'conexao_job_translation_audit' ) ? conexao_job_translation_audit() : array(),
		);
	}

	// The Jobs landing Page pair is verified (Stage 4.5 owns it; this plugin
	// never creates or modifies pages).
	$jobs_page            = conexao_job_translation_verify_jobs_page();
	$summary['jobs_page'] = $jobs_page['status'];

	$pt_before = array();

	foreach ( $manifest['jobs'] as $pt_slug => $en ) {
		$pt_slug = (string) $pt_slug;
		$row     = array(
			'pt_slug' => $pt_slug,
			'pt_id'   => 0,
			'en_id'   => 0,
			'en_url'  => '',
			'action'  => 'skipped',
			'message' => '',
		);

		$pt_post = get_page_by_path( $pt_slug, OBJECT, 'job' );

		if ( ! $pt_post instanceof WP_Post ) {
			$row['action']  = 'excluded';
			$row['message'] = 'PT job not present in this site (documented exclusion)';
			++$summary['skipped'];
			$rows[] = $row;
			continue;
		}

		$pt_id = (int) $pt_post->ID;

		if ( 'publish' !== $pt_post->post_status ) {
			$row['pt_id']   = $pt_id;
			$row['action']  = 'excluded';
			$row['message'] = 'PT job is not published — not user-facing';
			++$summary['skipped'];
			$rows[] = $row;
			continue;
		}

		$row['pt_id']          = $pt_id;
		$pt_before[ $pt_slug ] = conexao_job_translation_snapshot_job( $pt_id );

		// A published EN translation already linked to this PT record?
		$en_id = (int) pll_get_post( $pt_id, 'en' );

		if ( $en_id > 0 && $en_id !== $pt_id && 'publish' === get_post_status( $en_id ) ) {
			// Idempotent path: the EN record exists — verify ownership and the
			// relationship, repair only drifted manifest-owned fields.
			$row['en_id'] = $en_id;
			$en_post      = get_post( $en_id );

			if ( ! conexao_job_translation_pair_ok( $pt_id, $en_id ) ) {
				$row['action'] = 'error';
				$row['message'] = 'EN record exists but the pair link is broken';
				++$summary['errors'];
				$rows[] = $row;
				continue;
			}

			if ( ! $dry_run && $en_post instanceof WP_Post && $en_post->post_name !== (string) $en['en_slug'] ) {
				// Slug drift repair (a previous -2 fallback or manual rename).
				wp_update_post( array( 'ID' => $en_id, 'post_name' => (string) $en['en_slug'] ) );
				$row['message'] .= 'slug repaired; ';
			}

			if ( ! $dry_run ) {
				$copied = conexao_job_translation_copy_fields( $pt_id, $en_id, $en );
				if ( ! empty( $en['en_meta_description'] ) ) {
					update_post_meta( $en_id, 'conexao_meta_description', (string) $en['en_meta_description'] );
				}
				$summary['meta_copied'] += count( $copied );
			}

			$row['action']  = 'exists';
			$row['message'] .= 'EN translation already exists and is verified';
			$row['en_url']   = (string) get_permalink( $en_id );
			++$summary['skipped'];
			$rows[] = $row;
			continue;
		}



		// No EN translation yet — one will be created. First the duplicate
		// gate: the EN slug must be free inside the `job` namespace.
		$collision = get_page_by_path( (string) $en['en_slug'], OBJECT, 'job' );

		if ( $collision instanceof WP_Post && (int) $collision->ID !== $pt_id ) {
			$row['action']  = 'error';
			$row['message'] = 'EN slug already used by another job record (#' . (int) $collision->ID . ') — refusing to create a duplicate';
			++$summary['errors'];
			$rows[] = $row;
			continue;
		}

		if ( $dry_run ) {
			$row['action']  = 'would-create';
			$row['message'] = 'EN job would be created, linked and verified';
			++$summary['created'];
			$rows[] = $row;
			continue;
		}

		$en_id = wp_insert_post(
			array(
				'post_type'     => 'job',
				'post_name'     => (string) $en['en_slug'],
				'post_title'    => (string) $en['en_title'],
				'post_content'  => (string) $en['en_content'],
				'post_excerpt'  => (string) $en['en_excerpt'],
				'post_status'   => 'publish',
				'post_date'     => $pt_post->post_date,
				'post_date_gmt' => $pt_post->post_date_gmt,
				'post_author'   => (int) $pt_post->post_author,
				'menu_order'    => (int) $pt_post->menu_order,
			),
			true
		);

		if ( is_wp_error( $en_id ) ) {
			$row['action']  = 'error';
			$row['message'] = 'job create failed: ' . $en_id->get_error_message();
			++$summary['errors'];
			$rows[] = $row;
			continue;
		}

		$en_id = (int) $en_id;

		pll_set_post_language( $en_id, 'en' );
		if ( ! pll_get_post_language( $pt_id ) ) {
			pll_set_post_language( $pt_id, 'pt' );
		}
		pll_save_post_translations( array( 'pt' => $pt_id, 'en' => $en_id ) );

		$copied = conexao_job_translation_copy_fields( $pt_id, $en_id, $en );
		if ( ! empty( $en['en_meta_description'] ) ) {
			update_post_meta( $en_id, 'conexao_meta_description', (string) $en['en_meta_description'] );
		}
		$summary['meta_copied'] += count( $copied );

		if ( ! conexao_job_translation_pair_ok( $pt_id, $en_id ) ) {
			$row['en_id']   = $en_id;
			$row['action']  = 'error';
			$row['message'] = 'translation link verification failed';
			++$summary['errors'];
			$rows[] = $row;
			continue;
		}

		$row['en_id']   = $en_id;
		$row['en_url']  = (string) get_permalink( $en_id );
		$row['action']  = 'created';
		$row['message'] = sprintf( 'EN job created and linked; %d meta field(s) copied', count( $copied ) );
		++$summary['created'];
		$rows[] = $row;
	}


	// PT regression gate — the Portuguese originals must be byte-identical
	// after the run (only a Polylang language backfill is allowed, and only
	// when the record had no language at all).
	foreach ( $pt_before as $pt_slug => $before ) {
		$pt_post = get_page_by_path( (string) $pt_slug, OBJECT, 'job' );

		if ( ! $pt_post instanceof WP_Post ) {
			++$summary['pt_changed'];
			++$summary['errors'];
			$rows[] = array( 'pt_slug' => (string) $pt_slug, 'action' => 'error', 'message' => 'PT SOURCE MISSING AFTER RUN', 'pt_id' => 0, 'en_id' => 0, 'en_url' => '' );
			continue;
		}

		$after = conexao_job_translation_snapshot_job( (int) $pt_post->ID );

		// A language backfill ('' → 'pt') is the only permitted difference.
		if ( '' === $before['language'] ) {
			$before['language'] = $after['language'];
		}

		if ( $after !== $before ) {
			++$summary['pt_changed'];
			++$summary['errors'];
			$rows[] = array( 'pt_slug' => (string) $pt_slug, 'action' => 'error', 'message' => 'PT SOURCE CHANGED — regression gate tripped', 'pt_id' => (int) $pt_post->ID, 'en_id' => 0, 'en_url' => '' );
		}
	}

	if ( ! $dry_run ) {
		// The EN singles are routed through the Polylang language data, which is
		// cached; refresh so /en/empregos/{en-slug}/ is routable immediately.
		if ( function_exists( 'PLL' ) && PLL() && isset( PLL()->model ) ) {
			PLL()->model->clean_languages_cache();
			pll_languages_list();
		}
		flush_rewrite_rules( false );
	}

	return array(
		'summary'   => $summary,
		'rows'      => $rows,
		'excluded'  => $manifest['excluded'],
		'jobs_page' => $jobs_page,
		'audit'     => conexao_job_translation_audit(),
	);
}
