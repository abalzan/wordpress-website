<?php
/**
 * Stage C2 remediation - post-second-pass verification (read-only).
 *
 * Confirms the C2 success criteria after the runtime/importer fix and the
 * 117-pair reconciliation, following the canonical second pass.
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';

global $wpdb;
$fail = 0;
function vok( $label, $cond, $detail = '' ) {
	global $fail;
	echo ( $cond ? '[ OK ] ' : '[FAIL] ' ) . $label . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
	if ( ! $cond ) {
		$fail++;
	}
}

$pilot = array( 'eventbrite_laois', 'eventbrite_cork', 'eventbrite_dublin', 'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin' );
$ph    = implode( ',', array_fill( 0, count( $pilot ), '%s' ) );

$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT p.ID, pm_src.meta_value AS source, pm_sid.meta_value AS source_id, pm_st.meta_value AS status
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm_src ON pm_src.post_id = p.ID AND pm_src.meta_key = '_event_source'
	 LEFT JOIN {$wpdb->postmeta} pm_sid ON pm_sid.post_id = p.ID AND pm_sid.meta_key = '_event_source_id'
	 LEFT JOIN {$wpdb->postmeta} pm_st  ON pm_st.post_id  = p.ID AND pm_st.meta_key  = '_event_status'
	 WHERE p.post_type = 'event' AND p.post_status = 'publish' AND pm_src.meta_value IN ({$ph})",
	$pilot
) );

$by_ident = array();
$by_url   = array();
$by_source = array();
$status   = array();
$missing_sid = array();
$missing_county = array();
foreach ( $rows as $r ) {
	$pid    = (int) $r->ID;
	$src    = (string) $r->source;
	$sid    = (string) $r->source_id;
	$st     = (string) ( '' !== (string) $r->status ? $r->status : 'published' );
	$url    = (string) get_post_meta( $pid, '_event_url', true );
	$county = wp_get_object_terms( $pid, 'conexao_county', array( 'fields' => 'slugs' ) );
	$by_ident[ $src . '|' . $sid ][] = $pid;
	$by_source[ $src ][] = $pid;
	$status[ $st ] = ( $status[ $st ] ?? 0 ) + 1;
	if ( '' === trim( $sid ) ) { $missing_sid[] = $pid; }
	if ( ! $county || is_wp_error( $county ) ) { $missing_county[] = $pid; }
	if ( '' !== $url ) { $by_url[ $url ][] = $pid; }
}

$dupe_ident = array_filter( $by_ident, fn( $v ) => count( $v ) > 1 );
$dupe_url   = array_filter( $by_url, fn( $v ) => count( $v ) > 1 );

echo "=== C2 POST-REMEDIATION VERIFICATION ===\n";
echo 'pilot total=' . count( $rows ) . ' distinct identities=' . count( $by_ident ) . "\n";
echo 'by source: ' . wp_json_encode( array_map( 'count', $by_source ) ) . "\n";
echo 'by status: ' . wp_json_encode( $status ) . "\n\n";

vok( 'No duplicate (source, source_id) identities', 0 === count( $dupe_ident ), count( $dupe_ident ) ? wp_json_encode( array_slice( $dupe_ident, 0, 5, true ) ) : count( $by_ident ) . ' identities' );
vok( 'No duplicate source URLs', 0 === count( $dupe_url ), count( $dupe_url ) ? wp_json_encode( array_slice( $dupe_url, 0, 5, true ) ) : count( $by_url ) . ' URLs' );
vok( 'Every pilot event carries a _event_source_id', 0 === count( $missing_sid ), count( $missing_sid ) ? 'ids=' . implode( ',', $missing_sid ) : '' );
vok( 'Every pilot event carries a county term', 0 === count( $missing_county ), count( $missing_county ) ? 'ids=' . implode( ',', $missing_county ) : '' );

// Global identity audit (all sources).
$global_dupes = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM (
		SELECT pm.meta_value AS v1, pms.meta_value AS v2 FROM {$wpdb->posts} p
		JOIN {$wpdb->postmeta} pm  ON pm.post_id  = p.ID AND pm.meta_key  = '_event_source_id'
		JOIN {$wpdb->postmeta} pms ON pms.post_id = p.ID AND pms.meta_key = '_event_source'
		WHERE p.post_type='event' AND p.post_status='publish' AND pms.meta_value <> ''
		GROUP BY pm.meta_value, pms.meta_value HAVING COUNT(*) > 1
	) x"
);
vok( 'No duplicate identities across ALL sources', 0 === $global_dupes, 'count=' . $global_dupes );

// Media integrity: attachments never deleted; nothing dangling.
$att_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'" );
$dangling  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} a LEFT JOIN {$wpdb->posts} p ON p.ID=a.post_parent WHERE a.post_type='attachment' AND a.post_parent<>0 AND p.ID IS NULL" );
vok( 'Attachment library intact', $att_total > 0, 'total=' . $att_total );
vok( 'No dangling attachment parents', 0 === $dangling, 'count=' . $dangling );

echo "\nVERIFY: " . ( 0 === $fail ? 'ALL PASS (0 failures)' : $fail . ' FAILURE(S)' ) . "\n";
exit( 0 === $fail ? 0 : 1 );