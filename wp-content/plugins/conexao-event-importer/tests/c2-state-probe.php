<?php
/**
 * Stage C2 — full DB state probe (ungated, read-only).
 * Prints pilot-source census by status, image stats, and source registry.
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';

$pilot = array( 'eventbrite_laois', 'eventbrite_cork', 'eventbrite_dublin', 'heritage_week_laois', 'heritage_week_cork', 'heritage_week_dublin' );

global $wpdb;
echo "== ALL events by source (SQL, publish) ==\n";
foreach ( $wpdb->get_results( "SELECT pm.meta_value src, COUNT(*) c FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_event_source' WHERE p.post_type='event' AND p.post_status='publish' GROUP BY pm.meta_value ORDER BY c DESC" ) as $x ) {
	echo '  ' . str_pad( $x->src, 24 ) . ' = ' . $x->c . "\n";
}

$ids = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_event_source' WHERE p.post_type='event' AND p.post_status='publish' AND pm.meta_value IN (" . implode( ',', array_fill( 0, count( $pilot ), '%s' ) ) . ')', $pilot ) );
echo "\n== Pilot events (ungated): " . count( $ids ) . " ==\n";

$today      = current_time( 'Y-m-d' );
$by_status  = array();
$by_county  = array();
$with_img   = 0;
$with_uuid  = 0;
$past       = 0;
$future     = 0;
$byte_total = 0;
$big        = 0;
$maxbytes   = 0;
foreach ( $ids as $pid ) {
	$st              = (string) get_post_meta( $pid, '_event_status', true );
	$by_status[ $st ] = ( $by_status[ $st ] ?? 0 ) + 1;
	$c               = wp_get_object_terms( $pid, 'conexao_county', array( 'fields' => 'slugs' ) );
	$ckey            = ( $c && ! is_wp_error( $c ) ) ? implode( ',', $c ) : '(none)';
	$by_county[ $ckey ] = ( $by_county[ $ckey ] ?? 0 ) + 1;
	$d               = (string) get_post_meta( $pid, '_event_date', true );
	if ( '' !== $d && $d < $today ) { $past++; } else { $future++; }
	if ( '' !== (string) get_post_meta( $pid, '_event_export_uuid', true ) ) { $with_uuid++; }
	$att = (int) get_post_meta( $pid, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true );
	if ( $att && wp_attachment_is_image( $att ) ) {
		$with_img++;
		$f = get_attached_file( $att );
		if ( $f && file_exists( $f ) ) {
			$s = filesize( $f );
			$byte_total += $s;
			if ( $s > $maxbytes ) { $maxbytes = $s; }
			if ( $s > 5242880 ) { $big++; }
		}
	}
}
echo '  by _event_status: ' . wp_json_encode( $by_status ) . "\n";
echo '  by county: ' . wp_json_encode( $by_county ) . "\n";
echo "  dates: past=$past future_or_today=$future\n";
echo "  with embedded image=$with_img, with export_uuid=$with_uuid\n";
echo '  image bytes total=' . round( $byte_total / 1048576, 1 ) . ' MB, max single=' . round( $maxbytes / 1048576, 2 ) . ' MB, over_5MB=' . $big . "\n";

echo "\n== Source registry ==\n";
$sm = new Conexao_Event_Sources();
$allsrc = method_exists( $sm, 'get_all' ) ? $sm->get_all() : (array) get_option( 'conexao_event_sources', array() );
if ( isset( $allsrc['sources'] ) ) { $allsrc = $allsrc['sources']; }
$act = 0; $ina = 0;
foreach ( $allsrc as $s ) {
	if ( ! is_array( $s ) ) { continue; }
	if ( ! empty( $s['active'] ) ) { $act++; } else { $ina++; }
	if ( in_array( $s['id'] ?? '', $pilot, true ) ) {
		echo '  PILOT ' . $s['id'] . ' type=' . ( $s['type'] ?? '?' ) . ' county=' . ( $s['county'] ?? '?' ) . ' active=' . ( ! empty( $s['active'] ) ? 'yes' : 'no' ) . "\n";
	}
}
echo "  total=" . count( $allsrc ) . " active=$act inactive=$ina\n";
echo "MEMORY limit=" . ini_get( 'memory_limit' ) . "\n";
