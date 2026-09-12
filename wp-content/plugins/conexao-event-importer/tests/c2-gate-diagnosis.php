<?php
/**
 * Stage C2 — Root-cause diagnosis: does the Event Runtime public gate hide
 * source_not_found events from the importer's deduplicator in a CLI context?
 *
 * For a known duplicate pair (old source_not_found record + new published
 * record sharing the same source + source_id) it runs find_by_source /
 * find_by_url with and without the runtime pre_get_posts gate and prints the
 * match result. Read-only.
 */
set_time_limit( 0 );
require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

global $wpdb;
$pair = $wpdb->get_row( "SELECT pm.meta_value sid, GROUP_CONCAT(p.ID) ids
	FROM {$wpdb->posts} p
	JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_event_source_id'
	JOIN {$wpdb->postmeta} pms ON pms.post_id=p.ID AND pms.meta_key='_event_source' AND pms.meta_value='eventbrite_cork'
	WHERE p.post_type='event' AND p.post_status='publish'
	GROUP BY pm.meta_value HAVING COUNT(*)>1 LIMIT 1" );
if ( ! $pair ) { echo "no duplicate pair found\n"; exit; }
$ids = array_map( 'intval', explode( ',', $pair->ids ) );
echo 'source_id=' . $pair->sid . " ids=" . implode( ',', $ids ) . "\n";
foreach ( $ids as $id ) {
	echo '  id=' . $id . ' status=' . get_post_meta( $id, '_event_status', true ) . ' url=' . get_post_meta( $id, '_event_url', true ) . "\n";
}
$url = (string) get_post_meta( $ids[0], '_event_url', true );

$norm = array( 'source' => 'eventbrite_cork', 'source_id' => $pair->sid, 'source_url' => $url, 'title' => '', 'start_date' => '' );

$dedup = new Conexao_Event_Deduplicator();

// ---- Direct proof: does the gate hide a source_not_found record? ----
// Pick a source_not_found cork source_id that has NO published twin.
$snf_sid = $wpdb->get_var( "SELECT pm.meta_value sid
	FROM {$wpdb->posts} p
	JOIN {$wpdb->postmeta} pm  ON pm.post_id=p.ID  AND pm.meta_key='_event_source_id'
	JOIN {$wpdb->postmeta} pms ON pms.post_id=p.ID AND pms.meta_key='_event_source'   AND pms.meta_value='eventbrite_cork'
	JOIN {$wpdb->postmeta} pst ON pst.post_id=p.ID AND pst.meta_key='_event_status'   AND pst.meta_value='source_not_found'
	WHERE p.post_type='event' AND p.post_status='publish'
	  AND pm.meta_value NOT IN (
	    SELECT pm2.meta_value FROM {$wpdb->posts} p2
	    JOIN {$wpdb->postmeta} pm2  ON pm2.post_id=p2.ID  AND pm2.meta_key='_event_source_id'
	    JOIN {$wpdb->postmeta} pst2 ON pst2.post_id=p2.ID AND pst2.meta_key='_event_status' AND pst2.meta_value='published'
	    WHERE p2.post_type='event')
	LIMIT 1" );
echo "\nsource_not_found-only source_id: " . var_export( $snf_sid, true ) . "\n";

$qcount = function ( $sid ) {
	$q = new WP_Query( array( 'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
		'meta_query' => array( array( 'key' => '_event_source_id', 'value' => $sid ) ) ) );
	return $q->posts;
};
if ( $snf_sid ) {
	echo '  WITH gate  : WP_Query ids=' . wp_json_encode( $qcount( $snf_sid ) ) . "\n";
}

$removed = false;
global $wp_filter;
if ( isset( $wp_filter['pre_get_posts'] ) ) {
	foreach ( $wp_filter['pre_get_posts']->callbacks as $prio => $cbs ) {
		foreach ( $cbs as $key => $cb ) {
			if ( is_array( $cb['function'] ) && is_object( $cb['function'][0] ) && 'Conexao_Event_Runtime' === get_class( $cb['function'][0] ) ) {
				remove_action( 'pre_get_posts', $cb['function'], $prio );
				$removed = true;
			}
		}
	}
}
echo "-- gate removed (" . ( $removed ? 'yes' : 'NO' ) . ") --\n";
if ( $snf_sid ) {
	echo '  NO gate    : WP_Query ids=' . wp_json_encode( $qcount( $snf_sid ) ) . "\n";
	$m2 = new ReflectionMethod( $dedup, 'find_by_source' ); $m2->setAccessible( true );
	echo '  find_by_source(no gate) -> ' . var_export( $m2->invoke( $dedup, 'eventbrite_cork', $snf_sid ), true ) . "\n";
}

