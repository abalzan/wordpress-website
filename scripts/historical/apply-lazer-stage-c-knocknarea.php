<?php
/**
 * Lazer expansion — Stage C: apply the approved Knocknarea improvements.
 *
 * EXISTING — DATA IMPROVEMENT (docs/importers/lazer-expansion-stage-b-report.md §6).
 * Exactly the 4 approved improvements, nothing else:
 *   1. `_leisure_town` 'Sligo' → 'Strandhill'
 *   2. Discover Ireland URL (located + verified 2026-10-09):
 *      https://www.discoverireland.ie/sligo/queen-maeve-trail
 *      (original Stage B state: empty; both DI candidates verified live;
 *      the exact-name match is used)
 *   3. 'Estacionamento' attribute (DI: "Free car parking" tag + car park on
 *      the trail) + `_leisure_parking` meta
 *   4. Deterministic map: `_leisure_map_url` stays empty — the map link is
 *      derived at render time from title + town + county, which now resolves
 *      to "Queen Maeve's Trail (Knocknarea), Strandhill, Co. Sligo, Ireland".
 *
 * Because the record must keep its internal /lazer/{slug}/ page (single-page
 * behavior unchanged, per Stage C §22), the new Discover Ireland URL is set
 * together with the Phase 3B `_leisure_internal_page` flag: the DI link
 * becomes a display-only "Ver no Discover Ireland" reference and the record
 * stays internal for every consumer of conexao_leisure_external_url().
 *
 * Idempotent: a second run reports zero changes. Unrelated fields are never
 * touched. LOCAL ONLY — production WordPress is not touched by Stage C.
 *
 * Run via:
 *   wp eval-file scripts/apply-lazer-stage-c-knocknarea.php --allow-root
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'WP_USE_THEMES', false );
	$dir = dirname( __FILE__ );
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
	if ( ! defined( 'ABSPATH' ) ) {
		fwrite( STDERR, "Unable to locate wp-load.php\n" );
		exit( 1 );
	}
}

$slug   = 'queen-maeves-trail-knocknarea';
$di_url = 'https://www.discoverireland.ie/sligo/queen-maeve-trail';

$posts = get_posts( array(
	'post_type'   => 'leisure',
	'name'        => $slug,
	'numberposts' => 1,
	'post_status' => 'any',
) );

if ( ! $posts ) {
	fwrite( STDERR, "ERROR: existing record '{$slug}' not found — aborting.\n" );
	exit( 1 );
}

$post = $posts[0];

// Identity guard: must be the Knocknarea/Queen Maeve record in Sligo.
$county = wp_get_post_terms( $post->ID, 'conexao_county', array( 'fields' => 'names' ) );
if ( 'Sligo' !== ( $county[0] ?? '' ) || false === stripos( $post->post_title, 'Knocknarea' ) ) {
	fwrite( STDERR, "ERROR: identity check failed for '{$slug}' (title: {$post->post_title}; county: " . ( $county[0] ?? '' ) . ") — aborting.\n" );
	exit( 1 );
}

$before = array(
	'_leisure_town'             => (string) get_post_meta( $post->ID, '_leisure_town', true ),
	'_leisure_discover_ireland' => (string) get_post_meta( $post->ID, '_leisure_discover_ireland', true ),
	'_leisure_internal_page'    => (string) get_post_meta( $post->ID, '_leisure_internal_page', true ),
	'_leisure_parking'          => (string) get_post_meta( $post->ID, '_leisure_parking', true ),
	'attr_estacionamento'       => has_term( 'Estacionamento', 'conexao_leisure_attribute', $post->ID ),
);

$changed = array();

// 1. Town → Strandhill.
if ( 'Strandhill' !== $before['_leisure_town'] ) {
	update_post_meta( $post->ID, '_leisure_town', 'Strandhill' );
	$changed[] = '_leisure_town: [' . $before['_leisure_town'] . '] -> [Strandhill]';
}

// 2. Discover Ireland URL (exact-name page verified 2026-10-09).
if ( $di_url !== $before['_leisure_discover_ireland'] ) {
	update_post_meta( $post->ID, '_leisure_discover_ireland', $di_url );
	$changed[] = '_leisure_discover_ireland: [' . $before['_leisure_discover_ireland'] . '] -> [' . $di_url . ']';
}

// Keep the internal page: the Phase 3B flag makes the new DI URL a
// display-only reference instead of an external redirect target.
if ( '1' !== $before['_leisure_internal_page'] ) {
	update_post_meta( $post->ID, '_leisure_internal_page', '1' );
	$changed[] = "_leisure_internal_page: [{$before['_leisure_internal_page']}] -> [1] (single page preserved; DI link display-only)";
}

// 3. Parking attribute + legacy meta.
$parking_term = term_exists( 'Estacionamento', 'conexao_leisure_attribute' );
if ( $parking_term && ! is_wp_error( $parking_term ) && ! has_term( 'Estacionamento', 'conexao_leisure_attribute', $post->ID ) ) {
	wp_set_object_terms( $post->ID, (int) $parking_term['term_id'], 'conexao_leisure_attribute', true );
	$changed[] = 'attribute term: +Estacionamento';
}
if ( '1' !== $before['_leisure_parking'] ) {
	update_post_meta( $post->ID, '_leisure_parking', '1' );
	$changed[] = '_leisure_parking: [' . $before['_leisure_parking'] . '] -> [1]';
}

// 4. Map: no _leisure_map_url is stored; the deterministic render-time
// derivation now uses town Strandhill. Verify it resolves:
$map_url = function_exists( 'conexao_leisure_map_url' ) ? conexao_leisure_map_url( $post->ID ) : '';
if ( ! $map_url ) {
	fwrite( STDERR, "WARNING: deterministic map URL could not be derived (theme helper unavailable or insufficient data).\n" );
}

$after = array(
	'_leisure_town'             => (string) get_post_meta( $post->ID, '_leisure_town', true ),
	'_leisure_discover_ireland' => (string) get_post_meta( $post->ID, '_leisure_discover_ireland', true ),
	'_leisure_internal_page'    => (string) get_post_meta( $post->ID, '_leisure_internal_page', true ),
	'_leisure_parking'          => (string) get_post_meta( $post->ID, '_leisure_parking', true ),
	'attr_estacionamento'       => has_term( 'Estacionamento', 'conexao_leisure_attribute', $post->ID ),
	'map_url_derived'           => $map_url,
	'external_class'            => function_exists( 'conexao_leisure_external_url' ) ? conexao_leisure_external_url( $post->ID ) : 'helper-unavailable',
);

echo "=== Stage C: Knocknarea ({$slug}, ID {$post->ID}) ===\n";
echo 'Applied changes: ' . count( $changed ) . "\n";
foreach ( $changed as $c ) {
	echo "  {$c}\n";
}
if ( empty( $changed ) ) {
	echo "  (idempotent re-run: nothing to change)\n";
}
echo "\nBefore: " . json_encode( $before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
echo 'After:  ' . json_encode( $after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";

$report = array(
	'ran_at'  => current_time( 'c' ),
	'slug'    => $slug,
	'post_id' => $post->ID,
	'changes' => $changed,
	'before'  => $before,
	'after'   => $after,
);
file_put_contents( '/tmp/lazer-stage-c-knocknarea-report.json', json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
echo "\nReport saved to /tmp/lazer-stage-c-knocknarea-report.json\n";
