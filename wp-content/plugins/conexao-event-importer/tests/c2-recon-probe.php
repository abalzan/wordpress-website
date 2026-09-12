<?php
/**
 * Stage C2 remediation — duplicate identity probe (read-only).
 *
 * Ungated census of the six-pilot event set + exact enumeration of
 * duplicate (source, source_id) identities with full pair detail.
 *
 * Usage: php c2-recon-probe.php
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';

global $wpdb;

$pilot = array(
	'eventbrite_laois',
	'eventbrite_cork',
	'eventbrite_dublin',
	'heritage_week_laois',
	'heritage_week_cork',
	'heritage_week_dublin',
);

// ---------- Census: all event posts (post_status=publish, any _event_status) ----------
$rows = $wpdb->get_results( $wpdb->prepare(
	"SELECT p.ID, pm_src.meta_value AS source, pm_sid.meta_value AS source_id, pm_st.meta_value AS status, p.post_date
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm_src ON pm_src.post_id = p.ID AND pm_src.meta_key = '_event_source'
	 LEFT JOIN {$wpdb->postmeta} pm_sid ON pm_sid.post_id = p.ID AND pm_sid.meta_key = '_event_source_id'
	 LEFT JOIN {$wpdb->postmeta} pm_st  ON pm_st.post_id  = p.ID AND pm_st.meta_key  = '_event_status'
	 WHERE p.post_type = 'event' AND p.post_status = 'publish'
	   AND pm_src.meta_value IN (" . implode( ',', array_fill( 0, count( $pilot ), '%s' ) ) . ')',
	$pilot
) );

$all = array();
foreach ( $rows as $r ) {
	$all[ (int) $r->ID ] = array(
		'id'        => (int) $r->ID,
		'source'    => (string) $r->source,
		'source_id' => (string) $r->source_id,
		'status'    => (string) ( $r->status ?: 'published' ), // legacy default
		'post_date' => $r->post_date,
		'title'     => (string) get_post_field( 'post_title', $r->ID ),
		'url'       => (string) get_post_meta( $r->ID, '_event_url', true ),
		'attach'    => (int) get_post_meta( $r->ID, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true ),
		'date'      => (string) get_post_meta( $r->ID, '_event_date', true ),
		'uuid'      => (string) get_post_meta( $r->ID, '_event_export_uuid', true ),
	);
}

echo "=== C2 RECON PROBE ===\n";
echo "pilot_events_total=" . count( $all ) . "\n";

$by_status = array();
$by_source = array();
foreach ( $all as $e ) {
	$by_status[ $e['status'] ] = ( $by_status[ $e['status'] ] ?? 0 ) + 1;
	$by_source[ $e['source'] ] = ( $by_source[ $e['source'] ] ?? 0 ) + 1;
}
echo 'by_status=' . wp_json_encode( $by_status ) . "\n";
echo 'by_source=' . wp_json_encode( $by_source ) . "\n";

// ---------- Duplicate identities ----------
$by_ident = array();
foreach ( $all as $e ) {
	$by_ident[ $e['source'] . '|' . $e['source_id'] ][] = $e;
}
$dupes = array_filter( $by_ident, fn( $v ) => count( $v ) > 1 );
echo 'duplicate_identities=' . count( $dupes ) . "\n" ;

$pairs = array();
foreach ( $dupes as $key => $members ) {
	// Sort by post_date ASC so the older record is [0].
	usort( $members, fn( $a, $b ) => $a['post_date'] <=> $b['post_date'] );
	$pairs[ $key ] = $members;
}

echo "\n-- duplicate pairs detail --\n";
foreach ( $pairs as $key => $members ) {
	foreach ( $members as $i => $m ) {
		echo ($i === 0 ? 'ORIG' : 'DUP ') . " id={$m['id']} status={$m['status']} date={$m['post_date']} sid={$m['source_id']} url=" . substr( $m['url'], 0, 90 ) . " attach={$m['attach']} uuid={$m['uuid']}\n";
	}
	echo "---\n";
}

// Count how many older records are SNF and newer are published.
$snf_old = 0; $pub_new = 0; $anomal = 0;
foreach ( $pairs as $members ) {
	if ( 'source_not_found' === $members[0]['status'] ) { $snf_old++; } else { $anomal++; echo "ANOMAL: origin not SNF: " . $members[0]['id'] . ' status=' . $members[0]['status'] . "\n"; }
	if ( 'published' === $members[1]['status'] ) { $pub_new++; } else { echo "ANOMAL: newer not published: " . $members[1]['id'] . ' status=' . $members[1]['status'] . "\n"; }
}
echo "\norigin_SNF={$snf_old} new_published={$pub_new} anomalies={$anomal}\n";

// ---------- Duplicate URLs ----------
$by_url = array();
foreach ( $all as $e ) {
	if ( '' !== $e['url'] ) {
		$by_url[ $e['url'] ][] = $e['id'];
	}
}
$dupe_url = array_filter( $by_url, fn( $v ) => count( $v ) > 1 );
echo "\nduplicate_source_urls=" . count( $dupe_url ) . "\n";
foreach ( array_slice( $dupe_url, 0, 10, true ) as $u => $ids ) {
	echo "  " . substr( $u, 0, 90 ) . ' ids=' . implode( ',', $ids ) . "\n";
}

// ---------- Hard summary ----------
echo "\n== SUMMARY ==\n";
echo 'cork_events=' . ( $by_source['eventbrite_cork'] ?? 0 ) . "\n";
echo 'eventbrite_cork_published=' . ( isset( $all ) ? count( array_filter( $all, fn( $e ) => 'eventbrite_cork' === $e['source'] && 'published' === $e['status'] ) ) : 0 ) . "\n";
echo 'eventbrite_cork_snf=' . count( array_filter( $all, fn( $e ) => 'eventbrite_cork' === $e['source'] && 'source_not_found' === $e['status'] ) ) . "\n";
echo 'distinct_identities=' . count( $by_ident ) . "\n";