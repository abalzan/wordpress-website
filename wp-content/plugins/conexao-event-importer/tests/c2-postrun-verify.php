<?php
/**
 * Stage C2 — Post-second-pass verification (idempotency + identity).
 *
 * Compares the live DB against the pre-second-pass baseline and classifies
 * every newly-created pilot event as either:
 *   - DUPLICATE  (another event shares its source+source_id, URL, or content)
 *   - NEW        (a genuinely new event that appeared in the moving feed)
 * Read-only.
 *
 * Usage: php c2-postrun-verify.php [baseline-file]
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';

$pilot = array( 'eventbrite_laois', 'eventbrite_cork', 'eventbrite_dublin', 'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin' );

$bfile = $argv[1] ?? __DIR__ . '/c2-run-pair-baseline.json';
$base  = json_decode( (string) file_get_contents( $bfile ), true );
if ( ! is_array( $base ) ) { echo "FATAL: baseline unreadable\n"; exit( 1 ); }
$base_pilot = array();
foreach ( $base['pilot'] as $e ) { $base_pilot[ (int) $e['id'] ] = $e; }

$fail = 0;
function ok( $label, $cond, $detail = '' ) {
	global $fail;
	echo ( $cond ? '[ OK ] ' : '[FAIL] ' ) . $label . ( $detail ? ' — ' . $detail : '' ) . "\n";
	if ( ! $cond ) { $fail++; }
}

// ---------- Live census (all statuses, SQL to bypass the public gate) ----------
global $wpdb;
$ids = $wpdb->get_col( $wpdb->prepare(
	"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_event_source'
	 WHERE p.post_type='event' AND p.post_status='publish' AND pm.meta_value IN (" . implode( ',', array_fill( 0, count( $pilot ), '%s' ) ) . ')',
	$pilot
) );

$live = array();
foreach ( $ids as $pid ) {
	$live[ (int) $pid ] = array(
		'id'        => (int) $pid,
		'source'    => (string) get_post_meta( $pid, '_event_source', true ),
		'source_id' => (string) get_post_meta( $pid, '_event_source_id', true ),
		'url'       => (string) get_post_meta( $pid, '_event_url', true ),
		'title'     => get_post_field( 'post_title', $pid ),
		'date'      => (string) get_post_meta( $pid, '_event_date', true ),
		'time'      => (string) get_post_meta( $pid, '_event_start_time', true ),
		'uuid'      => (string) get_post_meta( $pid, '_event_export_uuid', true ),
		'county'    => implode( ',', (array) wp_get_object_terms( $pid, 'conexao_county', array( 'fields' => 'slugs' ) ) ),
	);
}

echo "=== C2 POST-SECOND-PASS VERIFICATION ===\n";
echo 'baseline pilot=' . count( $base_pilot ) . " live pilot=" . count( $live ) . "\n\n";

// ---------- 1. Duplicate source identities (the hard definition of a dupe) ----------
$by_ident = array();
foreach ( $live as $e ) { $by_ident[ $e['source'] . '|' . $e['source_id'] ][] = $e['id']; }
$dupe_ident = array_filter( $by_ident, fn( $v ) => count( $v ) > 1 );
ok( 'No duplicate (source, source_id) identities', 0 === count( $dupe_ident ), count( $dupe_ident ) ? wp_json_encode( $dupe_ident ) : count( $by_ident ) . ' identities' );

$by_url = array();
foreach ( $live as $e ) { if ( '' !== $e['url'] ) { $by_url[ $e['url'] ][] = $e['id']; } }
$dupe_url = array_filter( $by_url, fn( $v ) => count( $v ) > 1 );
ok( 'No duplicate source URLs', 0 === count( $dupe_url ), count( $dupe_url ) ? wp_json_encode( array_slice( $dupe_url, 0, 5, true ) ) : count( $by_url ) . ' URLs' );

// ---------- 2. Created / removed ----------
$created = array_diff( array_keys( $live ), array_keys( $base_pilot ) );
$removed = array_diff( array_keys( $base_pilot ), array_keys( $live ) );
echo 'new pilot ids since baseline: ' . count( $created ) . "\n";
echo 'removed pilot ids since baseline: ' . count( $removed ) . "\n";

// Classify each created event.
$new_ids = array();
$dupe_created = array();
foreach ( $created as $id ) {
	$e = $live[ $id ];
	$dup_of = array();
	if ( count( $by_ident[ $e['source'] . '|' . $e['source_id'] ] ) > 1 ) { $dup_of[] = 'identity'; }
	if ( '' !== $e['url'] && count( $by_url[ $e['url'] ] ) > 1 ) { $dup_of[] = 'url'; }
	// Content classifier mirrors Conexao_Event_Deduplicator::find_by_content():
	// exact sanitized title + exact date, and start time compared only when
	// BOTH records carry one (so two same-named sessions at different times
	// are correctly NOT treated as duplicates).
	foreach ( $live as $oid => $o ) {
		if ( $oid === $id ) { continue; }
		if ( sanitize_title( $o['title'] ) !== sanitize_title( $e['title'] ) ) { continue; }
		if ( $o['date'] !== $e['date'] ) { continue; }
		if ( '' !== (string) $o['time'] && '' !== (string) $e['time'] && $o['time'] !== $e['time'] ) { continue; }
		$dup_of[] = 'content:' . $oid;
		break;
	}
	if ( $dup_of ) { $dupe_created[ $id ] = $e['title'] . ' [' . implode( ',', $dup_of ) . ']'; }
	else { $new_ids[ $id ] = $e['title']; }
}
echo "\n-- new (non-duplicate) events from source growth: " . count( $new_ids ) . " --\n";
foreach ( array_slice( $new_ids, 0, 30, true ) as $id => $t ) { echo '   NEW id=' . $id . ' ' . $t . "\n"; }
echo "-- created-but-duplicate events: " . count( $dupe_created ) . " --\n";
foreach ( $dupe_created as $id => $t ) { echo '   DUPE id=' . $id . ' ' . $t . "\n"; }
ok( 'No unexpected duplicate created', 0 === count( $dupe_created ), count( $dupe_created ) ? implode( '; ', $dupe_created ) : '' );
ok( 'No pilot event removed', 0 === count( $removed ), count( $removed ) ? 'ids=' . implode( ',', $removed ) : '' );

// ---------- 3. Identity + UUID stability ----------
$drift = $uuid_drift = array();
$uuid_assigned = 0;
foreach ( $live as $id => $e ) {
	if ( ! isset( $base_pilot[ $id ] ) ) { continue; }
	if ( $base_pilot[ $id ]['source_id'] !== $e['source_id'] ) { $drift[] = $id; }
	$base_uuid = (string) ( $base_pilot[ $id ]['uuid'] ?? '' );
	if ( '' === $base_uuid ) {
		// No UUID at baseline: the export step assigns one by design.
		if ( '' !== (string) $e['uuid'] ) { $uuid_assigned++; }
	} elseif ( $base_uuid !== $e['uuid'] ) {
		// A pre-existing UUID must never change or be lost.
		$uuid_drift[] = $id;
	}
}
ok( 'source_id unchanged for all baseline events', 0 === count( $drift ), count( $drift ) ? 'ids=' . implode( ',', $drift ) : count( $live ) . ' checked' );
ok( 'Pre-existing export UUIDs unchanged (none lost/changed)', 0 === count( $uuid_drift ), count( $uuid_drift ) ? 'ids=' . implode( ',', $uuid_drift ) : ( $uuid_assigned ? $uuid_assigned . ' newly assigned by export' : 'no new UUIDs' ) );

$missing_sid = $bad_src = $bad_county = array();
foreach ( $live as $id => $e ) {
	if ( '' === trim( $e['source_id'] ) ) { $missing_sid[] = $id; }
	if ( ! in_array( $e['source'], $pilot, true ) ) { $bad_src[] = $id; }
	if ( '' === $e['county'] ) { $bad_county[] = $id; }
}
ok( 'All pilot events carry _event_source_id', 0 === count( $missing_sid ), count( $missing_sid ) ? 'ids=' . implode( ',', $missing_sid ) : '' );
ok( 'All pilot events carry a county-specific _event_source', 0 === count( $bad_src ), count( $bad_src ) ? 'ids=' . implode( ',', $bad_src ) : '' );
ok( 'All pilot events carry a county term', 0 === count( $bad_county ), count( $bad_county ) ? 'ids=' . implode( ',', $bad_county ) : '' );

echo "\n";
echo ( 0 === $fail ? "POST-RUN VERIFY: ALL PASS (0 failures)\n" : "POST-RUN VERIFY: $fail FAILURES\n" );
