<?php
/**
 * Stage 7 — EN Leisure description rollout engine.
 *
 * Modes:
 *   preview — compute the actions, change nothing;
 *   apply   — write `_leisure_excerpt_en` where it differs, refuse on PT drift;
 *   remove  — delete `_leisure_excerpt_en` (rollback: B2 fallback re-engages).
 *
 * Invariants enforced on EVERY processed record:
 *   - only `_leisure_excerpt_en` is written (no post fields, no other meta);
 *   - `_leisure_uuid` / `_leisure_export_uuid` never written (read for the
 *     audit trail and compared before/after);
 *   - post_excerpt / post_title / post_content compared before/after — the
 *     Portuguese sources must be byte-identical after the run.
 *
 * @package Conexao_Leisure_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load the embedded Stage 7 manifest.
 *
 * @return array[]|null Entries (slug, title, pt_excerpt, en_excerpt) or null.
 */
function conexao_leisure_translation_manifest() {
	static $manifest = null;
	static $loaded   = false;

	if ( $loaded ) {
		return $manifest;
	}
	$loaded = true;

	$file = CONEXAO_LEISURE_TRANSLATION_DIR . 'data/stage7-leisure-descriptions.json';
	if ( ! is_readable( $file ) ) {
		return null;
	}

	$decoded = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $decoded ) || empty( $decoded['entries'] ) || ! is_array( $decoded['entries'] ) ) {
		return null;
	}

	$manifest = array();
	foreach ( $decoded['entries'] as $entry ) {
		if ( empty( $entry['slug'] ) || empty( $entry['en_excerpt'] ) ) {
			continue;
		}
		$manifest[] = array(
			'slug'       => (string) $entry['slug'],
			'title'      => isset( $entry['title'] ) ? (string) $entry['title'] : '',
			'pt_excerpt' => isset( $entry['pt_excerpt'] ) ? (string) $entry['pt_excerpt'] : '',
			'en_excerpt' => trim( (string) $entry['en_excerpt'] ),
		);
	}

	return $manifest ?: null;
}

/**
 * Normalize a title for the manifest-vs-record verification (REST-rendered
 * entities like &#038; decode to the stored character; whitespace collapses).
 *
 * @param string $title Title.
 * @return string
 */
function conexao_leisure_translation_normalize_title( $title ) {
	$title = html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' );
	$title = preg_replace( '/\s+/u', ' ', $title );
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $title ) ) : strtolower( trim( $title ) );
}

/**
 * Find the leisure record for a manifest slug (language-unfiltered: the
 * record is Portuguese and Polylang filters unscoped get_posts queries).
 *
 * @param string $slug Slug.
 * @return WP_Post|null
 */
function conexao_leisure_translation_find_record( $slug ) {
	$posts = get_posts(
		array(
			'post_type'        => 'leisure',
			'name'             => $slug,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'no_found_rows'    => true,
			'lang'             => '',
			'suppress_filters' => true,
		)
	);

	return empty( $posts ) ? null : $posts[0];
}

/**
 * Run the rollout.
 *
 * @param string $mode 'preview' | 'apply' | 'remove'.
 * @return array {summary: array, rows: array[]}
 */
function conexao_leisure_translation_run( $mode = 'preview' ) {
	$manifest = conexao_leisure_translation_manifest();

	$summary = array(
		'entries'            => 0,
		'applied'            => 0,
		'removed'            => 0,
		'skipped_identical'  => 0,
		'refused'            => 0,
		'errors'             => 0,
		'pt_changed'         => 0,
		'uuid_changed'       => 0,
		'duplicates_created' => 0,
	);
	$rows = array();

	if ( null === $manifest ) {
		return array( 'summary' => $summary, 'rows' => $rows );
	}

	$is_apply  = ( 'apply' === $mode );
	$is_remove = ( 'remove' === $mode );

	foreach ( $manifest as $entry ) {
		++$summary['entries'];
		$slug = $entry['slug'];

		$post = conexao_leisure_translation_find_record( $slug );
		if ( ! $post ) {
			++$summary['errors'];
			$rows[] = array( 'slug' => $slug, 'id' => 0, 'action' => 'error', 'message' => 'no leisure record with this slug' );
			continue;
		}

		$post_id = (int) $post->ID;

		if ( 'publish' !== $post->post_status ) {
			++$summary['errors'];
			$rows[] = array( 'slug' => $slug, 'id' => $post_id, 'action' => 'error', 'message' => 'record is not published (status: ' . $post->post_status . ')' );
			continue;
		}

		if ( '' !== $entry['title'] && conexao_leisure_translation_normalize_title( $entry['title'] ) !== conexao_leisure_translation_normalize_title( $post->post_title ) ) {
			++$summary['errors'];
			$rows[] = array( 'slug' => $slug, 'id' => $post_id, 'action' => 'error', 'message' => 'title mismatch (manifest: ' . $entry['title'] . ' / record: ' . $post->post_title . ')' );
			continue;
		}

		// Snapshot the Portuguese identity/content fields (never written).
		$before = array(
			'post_excerpt'        => (string) $post->post_excerpt,
			'post_title'          => (string) $post->post_title,
			'post_content'        => (string) $post->post_content,
			'_leisure_uuid'       => (string) get_post_meta( $post_id, '_leisure_uuid', true ),
			'_leisure_export_uuid' => (string) get_post_meta( $post_id, '_leisure_export_uuid', true ),
		);

		$current_en = trim( (string) get_post_meta( $post_id, CONEXAO_LEISURE_TRANSLATION_META, true ) );

		if ( $is_remove ) {
			if ( '' === $current_en ) {
				$rows[] = array( 'slug' => $slug, 'id' => $post_id, 'action' => 'skip', 'message' => 'no EN description stored' );
				continue;
			}
			if ( $is_apply ) {
				delete_post_meta( $post_id, CONEXAO_LEISURE_TRANSLATION_META );
			}
			++$summary['removed'];
			$rows[] = array( 'slug' => $slug, 'id' => $post_id, 'action' => $is_apply ? 'removed' : 'would-remove', 'message' => '' );
			continue;
		}

		// PT-drift guard: the translation was authored against a specific
		// Portuguese description. If the record's excerpt has changed since,
		// refuse — a stale translation must never silently land.
		if ( '' !== $entry['pt_excerpt'] && trim( (string) $post->post_excerpt ) !== trim( $entry['pt_excerpt'] ) ) {
			++$summary['refused'];
			$rows[] = array(
				'slug'    => $slug,
				'id'      => $post_id,
				'action'  => 'refused-pt-drift',
				'message' => 'the Portuguese excerpt no longer matches the manifest source — re-author the translation',
			);
			continue;
		}

		if ( $current_en === $entry['en_excerpt'] ) {
			++$summary['skipped_identical'];
			$rows[] = array( 'slug' => $slug, 'id' => $post_id, 'action' => 'skip', 'message' => 'EN description already stored (identical)' );
			continue;
		}

		if ( $is_apply ) {
			$stored = update_post_meta( $post_id, CONEXAO_LEISURE_TRANSLATION_META, $entry['en_excerpt'] );
			if ( false === $stored && $entry['en_excerpt'] !== get_post_meta( $post_id, CONEXAO_LEISURE_TRANSLATION_META, true ) ) {
				++$summary['errors'];
				$rows[] = array( 'slug' => $slug, 'id' => $post_id, 'action' => 'error', 'message' => 'update_post_meta failed' );
				continue;
			}
		}

		++$summary['applied'];
		$rows[] = array(
			'slug'    => $slug,
			'id'      => $post_id,
			'action'  => $is_apply ? 'applied' : 'would-apply',
			'message' => $current_en ? ( $is_apply ? 'updated (an older EN value was replaced)' : 'would update (an older EN value exists)' ) : '',
		);

		// Post-apply invariant check: PT sources + UUID fields untouched.
		if ( $is_apply ) {
			$after_post = get_post( $post_id );
			$after      = array(
				'post_excerpt'        => (string) $after_post->post_excerpt,
				'post_title'          => (string) $after_post->post_title,
				'post_content'        => (string) $after_post->post_content,
				'_leisure_uuid'       => (string) get_post_meta( $post_id, '_leisure_uuid', true ),
				'_leisure_export_uuid' => (string) get_post_meta( $post_id, '_leisure_export_uuid', true ),
			);
			foreach ( $before as $field => $value ) {
				if ( $value !== $after[ $field ] ) {
					if ( 0 === strpos( $field, '_leisure_' ) ) {
						++$summary['uuid_changed'];
					} else {
						++$summary['pt_changed'];
					}
				}
			}
		}
	}

	return array( 'summary' => $summary, 'rows' => $rows );
}

