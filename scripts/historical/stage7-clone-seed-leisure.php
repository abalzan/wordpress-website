<?php
/**
 * Stage 7 — seed the validation clone with the production leisure dataset.
 *
 * CLONE-ONLY tool (never run against production/staging with real data).
 * Creates one `leisure` post per published production record captured in
 * stage7-work/source/production-leisure-rest-all.json, using the RESOLVED
 * raw excerpt from the Stage 7 inventory (stage7-work/leisure-description-
 * inventory.json — wptexturize artifacts undone, validated 289/289 against
 * the production card HTML).
 *
 * Faithful to the real dataset where the stage depends on it:
 *   - slug, title, publish status, post_excerpt (resolved PT description),
 *     post_date / post_date_gmt (archive order = date DESC);
 *   - all REST-exposed `_leisure_*` meta;
 *   - taxonomy terms by NAME (conexao_category [PT language], conexao_county,
 *     conexao_leisure_attribute — county/attribute are not Polylang-translated);
 *   - `_leisure_uuid` + `_leisure_export_uuid` (synthetic, DETERMINISTIC per
 *     record — stable across re-runs so the UUID-preservation gates are
 *     measurable in the clone; the real production values are not
 *     REST-exposed and are never touched by Stage 7);
 *   - Polylang post language 'pt'.
 *
 * Not seeded (out of scope for a card-description stage): featured media
 * (cards render the neutral "Image pending" state before AND after).
 *
 * Idempotent: existing slugs are skipped, never modified.
 *
 * Usage (clone, via wp-cli):
 *   php wp-cli.phar eval-file scripts/stage7-clone-seed-leisure.php --allow-root --url=http://127.0.0.1:8765
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__ ) . '/wp-load.php';
}

$work     = dirname( __DIR__ ) . '/stage7-work';
$source   = $work . '/source/production-leisure-rest-all.json';
$inv_file = $work . '/leisure-description-inventory.json';

$records    = json_decode( (string) file_get_contents( $source ), true );
$inventory  = json_decode( (string) file_get_contents( $inv_file ), true );
$inv_by_id  = array();
foreach ( (array) ( $inventory['records'] ?? array() ) as $item ) {
	$inv_by_id[ (int) $item['id'] ] = $item;
}

if ( ! is_array( $records ) || empty( $inv_by_id ) ) {
	echo "ERROR: source captures missing (run the Stage 7 capture + inventory first).\n";
	return;
}

/**
 * Deterministic pseudo-UUID (v4-shaped) for a stable clone identity.
 */
function s7_seed_uuid( $slug ) {
	$md5 = md5( 'stage7-clone:' . $slug );
	return sprintf(
		'%s-%s-%s-%s-%s',
		substr( $md5, 0, 8 ),
		substr( $md5, 8, 4 ),
		substr( $md5, 12, 4 ),
		substr( $md5, 16, 4 ),
		substr( $md5, 20, 12 )
	);
}

$tax_language = array(
	'conexao_category'          => 'pt', // Polylang-translated taxonomy.
	'conexao_county'            => '',   // proper nouns, no language.
	'conexao_leisure_attribute' => '',   // not Polylang-translated.
);

$created = 0;
$skipped = 0;
$errors  = 0;

echo '== Stage 7 clone seed: ' . count( $records ) . " production records ==\n";

foreach ( $records as $record ) {
	$slug = (string) $record['slug'];
	$id   = (int) $record['id'];

	$existing = get_posts(
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
	if ( ! empty( $existing ) ) {
		++$skipped;
		continue;
	}

	$inv = $inv_by_id[ $id ] ?? null;
	if ( ! $inv ) {
		++$errors;
		echo "  ERROR: no inventory entry for #{$id} ({$slug})\n";
		continue;
	}

	$date = str_replace( 'T', ' ', (string) $record['date'] );
	$gmt  = str_replace( 'T', ' ', (string) ( $record['date_gmt'] ?? $record['date'] ) );

	$post_id = wp_insert_post(
		array(
			'post_type'     => 'leisure',
			'post_status'   => 'publish',
			'post_title'    => (string) $record['title']['rendered'],
			'post_name'     => $slug,
			'post_content'  => (string) $record['content']['rendered'],
			'post_excerpt'  => (string) $inv['pt_excerpt_raw'],
			'post_date'     => $date,
			'post_date_gmt' => $gmt,
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		++$errors;
		echo "  ERROR inserting {$slug}: " . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'unknown' ) . "\n";
		continue;
	}

	// Meta (REST-exposed subset, all non-empty values).
	foreach ( (array) ( $record['meta'] ?? array() ) as $key => $value ) {
		if ( 0 === strpos( $key, '_leisure_' ) && '' !== (string) $value ) {
			update_post_meta( $post_id, $key, (string) $value );
		}
	}

	// Deterministic identity meta (never written by Stage 7).
	update_post_meta( $post_id, '_leisure_uuid', s7_seed_uuid( $slug ) );
	update_post_meta( $post_id, '_leisure_export_uuid', s7_seed_uuid( $slug ) );

	// Taxonomies by NAME (language-aware creation for translated ones).
	foreach ( $inv['taxonomies'] as $taxonomy => $names ) {
		foreach ( $names as $name ) {
			$term = get_term_by( 'name', $name, $taxonomy, ARRAY_A );
			if ( ! $term ) {
				$inserted = wp_insert_term( $name, $taxonomy );
				if ( is_wp_error( $inserted ) ) {
					continue;
				}
				$term_id = (int) $inserted['term_id'];
				$lang    = $tax_language[ $taxonomy ] ?? '';
				if ( '' !== $lang && function_exists( 'pll_set_term_language' ) ) {
					pll_set_term_language( $term_id, $lang );
				}
			}
			wp_set_object_terms( $post_id, $name, $taxonomy, true );
		}
	}

	// Polylang: the record is Portuguese.
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $post_id, 'pt' );
	}

	++$created;
}

echo "created: {$created}, skipped (already present): {$skipped}, errors: {$errors}\n";
echo 'clone leisure population now: ' . count(
	get_posts(
		array(
			'post_type'        => 'leisure',
			'post_status'      => 'any',
			'numberposts'      => -1,
			'lang'             => '',
			'suppress_filters' => true,
			'no_found_rows'    => true,
			'fields'           => 'ids',
		)
	)
) . "\n";
