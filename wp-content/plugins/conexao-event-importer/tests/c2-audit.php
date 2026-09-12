<?php
/**
 * Stage C2 — Post-run audit (phase 1).
 *
 * Compares the post-second-run DB state against the c2-baseline.json
 * snapshot and validates: duplicate identities, identity/UUID stability,
 * unchanged-vs-changed content hashing, source/county deltas, town and
 * category term creation, and the media delta.
 *
 * Usage: php c2-audit.php [baseline-file]
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';

$baseline_file = empty( $argv[1] ) ? __DIR__ . '/c2-baseline.json' : $argv[1];
$baseline = json_decode( (string) file_get_contents( $baseline_file ), true );
if ( ! is_array( $baseline ) ) {
	echo "FATAL: baseline not readable\n";
	exit( 1 );
}

$pilot_sources = array(
	'eventbrite_laois', 'eventbrite_cork', 'eventbrite_dublin',
	'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin',
);

$fail = 0;
function ok( $label, $cond, $detail = '' ) {
	global $fail;
	echo ( $cond ? '[ OK ] ' : '[FAIL] ' ) . $label . ( $detail ? ' — ' . $detail : '' ) . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

// ---------- Rebuild the live snapshot (same shape as baseline) ----------
$all = get_posts(
	array( 'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' )
);
$live = array();
$by_source = array();
$by_county = array();
$by_town = array();
$no_county_ids = array();
$no_town_ids = array();

foreach ( $all as $pid ) {
	$source = get_post_meta( $pid, '_event_source', true );
	$src_id = get_post_meta( $pid, '_event_source_id', true );
	$source = '' === $source ? '(empty)' : $source;
	$by_source[ $source ] = ( $by_source[ $source ] ?? 0 ) + 1;
	$county_terms = wp_get_object_terms( $pid, 'conexao_county', array( 'fields' => 'slugs' ) );
	$county = ( $county_terms && ! is_wp_error( $county_terms ) ) ? implode( ',', $county_terms ) : '';
	$town_terms = wp_get_object_terms( $pid, 'conexao_town', array( 'fields' => 'slugs' ) );
	$town = ( $town_terms && ! is_wp_error( $town_terms ) ) ? implode( ',', $town_terms ) : '';
	if ( '' === $county ) { $no_county_ids[] = $pid; }
	else { foreach ( explode( ',', $county ) as $c ) { $by_county[ $c ] = ( $by_county[ $c ] ?? 0 ) + 1; } }
	if ( '' === $town ) { $no_town_ids[] = $pid; }
	else { foreach ( explode( ',', $town ) as $t ) { $by_town[ $t ] = ( $by_town[ $t ] ?? 0 ) + 1; } }

	$mk = array( '_event_date', '_event_start_time', '_event_end_date', '_event_end_time', '_event_venue', '_event_address', '_event_url', '_event_source_url', '_event_banner', '_event_status', '_event_export_uuid' );
	$meta = array();
	foreach ( $mk as $k ) { $meta[ $k ] = (string) get_post_meta( $pid, $k, true ); }
	$content = (string) get_post_field( 'post_content', $pid );

	$live[ $pid ] = array(
		'id' => $pid,
		'status' => get_post_status( $pid ),
		'source' => $source,
		'source_id' => $src_id,
		'title' => get_the_title( $pid ),
		'county' => $county,
		'town' => $town,
		'meta' => $meta,
		'content_len' => strlen( $content ),
		'hash' => md5( get_the_title( $pid ) . '|' . $content . '|' . $meta['_event_date'] . '|' . $meta['_event_start_time'] . '|' . $meta['_event_end_date'] . '|' . $meta['_event_end_time'] . '|' . $meta['_event_venue'] . '|' . $meta['_event_address'] . '|' . $meta['_event_url'] . '|' . $src_id ),
		'attachment_id' => (int) get_post_meta( $pid, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true ),
	);
}

echo "=== C2 POST-RUN AUDIT (phase 1) ===\n\n";


// ---------- 1. Duplicate source identities ----------
$ident = array();
foreach ( $live as $e ) {
	if ( in_array( $e['source'], $pilot_sources, true ) ) {
		$ident[ $e['source'] . '|' . $e['source_id'] ][] = $e['id'];
	}
}
$dupes = array_filter( $ident, function ( $ids ) { return count( $ids ) > 1; } );
ok( 'No duplicate pilot source identities', 0 === count( $dupes ), count( $dupes ) ? wp_json_encode( $dupes ) : 'checked ' . count( $ident ) . ' identities' );

// Duplicate URLs within pilot events.
$url_map = array();
foreach ( $live as $e ) {
	if ( in_array( $e['source'], $pilot_sources, true ) && ! empty( $e['meta']['_event_url'] ) ) {
		$url_map[ $e['meta']['_event_url'] ][] = $e['id'];
	}
}
$url_dupes = array_filter( $url_map, function ( $ids ) { return count( $ids ) > 1; } );
ok( 'No duplicate pilot event URLs', 0 === count( $url_dupes ), count( $url_dupes ) ? wp_json_encode( array_slice( $url_dupes, 0, 5, true ) ) : 'checked ' . count( $url_map ) . ' URLs' );

// ---------- 2. Identity stability vs baseline ----------
$base_pilot = array();
foreach ( $baseline['pilot'] as $e ) { $base_pilot[ $e['id'] ] = $e; }
$live_pilot = array();
foreach ( $live as $e ) { if ( in_array( $e['source'], $pilot_sources, true ) ) { $live_pilot[ $e['id'] ] = $e; } }

$created = array_diff( array_keys( $live_pilot ), array_keys( $base_pilot ) );
$removed = array_diff( array_keys( $base_pilot ), array_keys( $live_pilot ) );
ok( 'No new pilot events created on second run', 0 === count( $created ), count( $created ) ? 'ids=' . implode( ',', $created ) : '' );
ok( 'No pilot events removed', 0 === count( $removed ), count( $removed ) ? 'ids=' . implode( ',', $removed ) : '' );

$identity_drift = $uuid_drift = array();
foreach ( $live_pilot as $id => $e ) {
	if ( isset( $base_pilot[ $id ] ) ) {
		if ( $base_pilot[ $id ]['source_id'] !== $e['source_id'] ) { $identity_drift[] = $id; }
		if ( ( $base_pilot[ $id ]['uuid'] ?? '' ) !== $e['meta']['_event_export_uuid'] ) { $uuid_drift[] = $id; }
	}
}
ok( 'source_id stable between runs', 0 === count( $identity_drift ), count( $identity_drift ) ? 'ids=' . implode( ',', $identity_drift ) : 'all ' . count( $live_pilot ) . ' pilot events' );
ok( 'export UUID stable between runs', 0 === count( $uuid_drift ), count( $uuid_drift ) ? 'ids=' . implode( ',', $uuid_drift ) : '' );

// Every pilot event must have a source_id and the county-specific source key.
$missing_sid = $bad_source = array();
foreach ( $live_pilot as $id => $e ) {
	if ( '' === trim( (string) $e['source_id'] ) ) { $missing_sid[] = $id; }
	if ( ! in_array( $e['source'], $pilot_sources, true ) ) { $bad_source[] = $id; }
}
ok( 'All pilot events carry _event_source_id', 0 === count( $missing_sid ), count( $missing_sid ) ? 'ids=' . implode( ',', $missing_sid ) : '' );
ok( 'All pilot events carry county-specific _event_source', 0 === count( $bad_source ), count( $bad_source ) ? 'ids=' . implode( ',', $bad_source ) : '' );

// ---------- 3. Unchanged vs changed (content hash) ----------
$changed = array();
foreach ( $live_pilot as $id => $e ) {
	if ( isset( $base_pilot[ $id ] ) && $base_pilot[ $id ]['hash'] !== $e['hash'] ) {
		$diff = array();
		$b = $base_pilot[ $id ];
		if ( $b['title'] !== $e['title'] ) { $diff[] = 'title'; }
		foreach ( array( '_event_date', '_event_start_time', '_event_end_date', '_event_end_time', '_event_venue', '_event_address', '_event_url', '_event_banner' ) as $f ) {
			if ( ( $b['meta'][ $f ] ?? '' ) !== ( $e['meta'][ $f ] ?? '' ) ) { $diff[] = $f; }
		}
		if ( $b['county'] !== $e['county'] ) { $diff[] = 'county'; }
		if ( $b['town'] !== $e['town'] ) { $diff[] = 'town'; }
		if ( $b['status'] !== $e['status'] ) { $diff[] = 'post_status'; }
		if ( ! $diff ) { $diff[] = 'description/content'; }
		$changed[] = $id . ' ' . $e['title'] . ' [' . implode( ', ', $diff ) . ']';
	}
}
echo 'changed pilot events since baseline: ' . count( $changed ) . "\n";
foreach ( array_slice( $changed, 0, 40 ) as $c ) { echo '  CHANGED: ' . $c . "\n"; }

// ---------- 4. Location + term deltas ----------
ok( 'Total event count matches baseline (no new/deleted)', count( $all ) === (int) $baseline['total_events'], 'baseline=' . $baseline['total_events'] . ' now=' . count( $all ) );

foreach ( $baseline['by_source'] as $s => $n ) {
	$now = $by_source[ $s ] ?? 0;
	ok( "Source count stable: $s", $now === (int) $n, "baseline=$n now=$now" );
}
foreach ( $by_source as $s => $n ) {
	if ( ! isset( $baseline['by_source'][ $s ] ) ) {
		ok( "No NEW source keys appeared: $s", false, "now=$n" );
	}
}

// County deltas (existing events must not lose their county).
foreach ( $baseline['by_county'] as $s => $n ) {
	$now = $by_county[ $s ] ?? 0;
	if ( $now !== (int) $n ) { echo "  COUNTY SHIFT: $s baseline=$n now=$now\n"; }
}
$lost_county = array();
foreach ( $base_pilot as $id => $b ) {
	if ( isset( $live_pilot[ $id ] ) && '' !== $b['county'] && '' === $live_pilot[ $id ]['county'] ) { $lost_county[] = $id; }
}
ok( 'No pilot event lost its county', 0 === count( $lost_county ), count( $lost_county ) ? 'ids=' . implode( ',', $lost_county ) : '' );

// Town term set — no fabricated towns.
$towns_now = array();
foreach ( get_terms( array( 'taxonomy' => 'conexao_town', 'hide_empty' => false ) ) as $t ) { $towns_now[ $t->slug ] = (int) $t->count; }
$new_towns = array_diff_key( $towns_now, $baseline['towns'] );
ok( 'No new town terms created', 0 === count( $new_towns ), count( $new_towns ) ? 'new=' . implode( ',', array_keys( $new_towns ) ) : 'town_terms=' . count( $towns_now ) );

// Categories — no unexpected category terms.
$cats_now = array();
foreach ( get_terms( array( 'taxonomy' => 'conexao_category', 'hide_empty' => false ) ) as $t ) { $cats_now[ $t->slug ] = (int) $t->count; }
$new_cats = array_diff_key( $cats_now, $baseline['categories'] );
ok( 'No new category terms created', 0 === count( $new_cats ), count( $new_cats ) ? 'new=' . implode( ',', array_keys( $new_cats ) ) : 'category_terms=' . count( $cats_now ) );
foreach ( $cats_now as $slug => $cnt ) {
	$b = ( $baseline['categories'][ $slug ]['count'] ?? null );
	if ( null !== $b && $b !== $cnt ) { echo "  CATEGORY COUNT SHIFT: $slug $b -> $cnt\n"; }
}

// ---------- 5. Media delta ----------
$att_total = (int) wp_count_posts( 'attachment' )->inherit;
$att_max = 0;
foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'DESC', 'fields' => 'ids' ) ) as $a ) { $att_max = (int) $a; }
ok( 'No new attachments since baseline', $att_total === (int) $baseline['media']['total_attachments'] && $att_max <= (int) $baseline['media']['max_attachment_id'], "baseline_total={$baseline['media']['total_attachments']} now=$att_total baseline_max={$baseline['media']['max_attachment_id']} max=$att_max" );

$banner_changed = array();
foreach ( $live_pilot as $id => $e ) {
	if ( isset( $base_pilot[ $id ] ) && ( $base_pilot[ $id ]['attachment_id'] ?? 0 ) !== $e['attachment_id'] ) {
		$banner_changed[] = $id . ' (' . ( $base_pilot[ $id ]['attachment_id'] ?? 0 ) . ' -> ' . $e['attachment_id'] . ')';
	}
}
ok( 'No pilot event changed its image attachment', 0 === count( $banner_changed ), count( $banner_changed ) ? implode( '; ', $banner_changed ) : '' );

echo "\n";
echo ( 0 === $fail ? "AUDIT PHASE 1: ALL PASS (0 failures)\n" : "AUDIT PHASE 1: $fail FAILURES\n" );

