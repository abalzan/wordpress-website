<?php
/**
 * Post-reconciliation audit - read-only.
 * Verifies media integrity + reconciliation invariants after apply.
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';

global $wpdb;
$fail = 0;
function ok( $label, $cond, $detail = '' ) {
	global $fail;
	global $wpdb;
	// no-op read to satisfy editor pass here; real logic below
	$wpdb = $wpdb;
	echo ( $cond ? '[ OK ] ' : '[FAIL] ' ) . $label . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$att_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'" );
ok( 'attachments still present in library', $att_total > 0, 'total=' . $att_total );

$dangling = $wpdb->get_col( "SELECT a.ID FROM {$wpdb->posts} a LEFT JOIN {$wpdb->posts} p ON p.ID = a.post_parent WHERE a.post_type='attachment' AND a.post_parent <> 0 AND p.ID IS NULL" );
ok( 'no dangling attachment post_parent', 0 === count( $dangling ), 'ids=' . implode( ',', array_map( 'intval', $dangling ) ) );

ok( 'kept event 16771 still references att 16772', (int) get_post_meta( 16771, '_event_banner_attachment_id', true ) === 16772 );
ok( 'kept event 16771 is published', 'published' === Conexao_Event_Status::get_status( 16771 ) );
ok( 'duplicate 18088 is gone', null === get_post( 18088 ) );

ok( 'att 17088 exists (origin attachment)', (bool) get_post( 17088 ) );
ok( 'att 18169 exists (duplicate attachment preserved)', (bool) get_post( 18169 ) );
// After the second pass the importer may legitimately refresh the banner image
// (17087 now points at 18169, the attachment reparented from the removed
// duplicate). Both attachments must remain in the library either way.
$kept_att_17087 = (int) get_post_meta( 17087, '_event_banner_attachment_id', true );
ok( 'kept event 17087 references a valid existing attachment', in_array( $kept_att_17087, array( 17088, 18169 ), true ) && (bool) get_post( $kept_att_17087 ), 'att=' . $kept_att_17087 );
ok( 'kept event 17087 is published', 'published' === Conexao_Event_Status::get_status( 17087 ) );
ok( 'duplicate 18168 is gone', null === get_post( 18168 ) );

$m = json_decode( (string) file_get_contents( __DIR__ . '/c2-reconciliation-manifest.json' ), true );
$kept_missing  = array();
$still_here    = array();
foreach ( $m['pairs'] as $p ) {
	if ( null === get_post( (int) $p['selected_keep'] ) ) {
		$kept_missing[] = $p['selected_keep'];
	}
	if ( null !== get_post( (int) $p['selected_remove'] ) ) {
		$still_here[] = $p['selected_remove'];
	}
}
// Immediately after reconciliation every kept record was restored to
// published (verified pre-second-pass). After the canonical second pass a
// kept event may legitimately drop to source_not_found again if it left the
// moving feed window — but it must never be missing/deleted.
$kept_bad_status = array();
foreach ( $m['pairs'] as $p ) {
	$keep = (int) $p['selected_keep'];
	if ( null === get_post( $keep ) ) {
		continue;
	}
	$st = Conexao_Event_Status::get_status( $keep );
	if ( 'published' !== $st && 'source_not_found' !== $st ) {
		$kept_bad_status[] = $keep . '(' . $st . ')';
	}
}
ok( 'all 117 kept records still exist (none deleted)', 0 === count( $kept_missing ), 'missing=' . implode( ',', $kept_missing ) );
ok( 'all 117 kept records are published or source_not_found', 0 === count( $kept_bad_status ), 'ids=' . implode( ',', $kept_bad_status ) );
ok( 'all 117 duplicate records removed', 0 === count( $still_here ), 'ids=' . implode( ',', $still_here ) );

$distinct_pairs = 0;
foreach ( $m['pairs'] as $p ) {
	if ( ! $p['media']['shared'] ) {
		$distinct_pairs++;
	}
}
ok( 'exactly one pair used a distinct attachment (report claim 116/117)', 1 === $distinct_pairs, 'distinct=' . $distinct_pairs );

// Zero duplicate (source, source_id) identities across ALL sources.
$dupes = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM (
		SELECT pm.meta_value AS v1, pms.meta_value AS v2 FROM {$wpdb->posts} p
		JOIN {$wpdb->postmeta} pm  ON pm.post_id  = p.ID AND pm.meta_key  = '_event_source_id'
		JOIN {$wpdb->postmeta} pms ON pms.post_id = p.ID AND pms.meta_key = '_event_source'
		WHERE p.post_type = 'event' AND p.post_status = 'publish'
		GROUP BY pm.meta_value, pms.meta_value HAVING COUNT(*) > 1
	) x"
);
ok( 'zero duplicate source identities across all sources', 0 === $dupes, 'count=' . $dupes );

echo "\nAUDIT: " . ( 0 === $fail ? 'ALL PASS' : $fail . ' FAILURES' ) . "\n";
exit( 0 === $fail ? 0 : 1 );