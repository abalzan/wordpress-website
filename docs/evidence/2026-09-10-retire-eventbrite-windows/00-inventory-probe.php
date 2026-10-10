<?php
/**
 * READ-ONLY inventory probe. Performs zero writes.
 * Measures the Eventbrite date-window source footprint (G8 windows).
 */
require_once '/var/www/html/tests/bootstrap.php';

$opt = get_option( 'conexao_event_sources', array() );
$is_arr = is_array( $opt );
echo 'stored_sources=' . ( $is_arr ? count( $opt ) : -1 ) . "\n";

if ( $is_arr ) {
	$windows = array();
	foreach ( $opt as $id => $s ) {
		if ( preg_match( '/^eventbrite_[a-z]+_events_(today|tomorrow|this_week)$/', (string) $id ) ) {
			$windows[] = $id;
		}
	}
	echo 'stored_window_sources=' . count( $windows ) . "\n";
	echo 'stored_window_ids=' . implode( ',', $windows ) . "\n";
}

require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-county-registry.php';
$reg = Conexao_County_Registry::get_all_county_sources();
echo 'registry_county_sources=' . count( $reg ) . "\n";
$rw = array();
foreach ( $reg as $id => $s ) {
	if ( preg_match( '/^eventbrite_[a-z]+_events_(today|tomorrow|this_week)$/', (string) $id ) ) {
		$rw[] = $id;
	}
}
echo 'registry_window_sources=' . count( $rw ) . "\n";

global $wpdb;
$rows = $wpdb->get_results(
	"SELECT pm.meta_value src, COUNT(*) n FROM {$wpdb->postmeta} pm"
	. " JOIN {$wpdb->posts} p ON p.ID = pm.post_id"
	. " WHERE pm.meta_key = '_event_source' GROUP BY pm.meta_value",
	ARRAY_A
);
$total = 0;
foreach ( (array) $rows as $r ) {
	$total += (int) $r['n'];
	echo 'events_from:' . $r['src'] . '=' . $r['n'] . "\n";
}
echo 'events_total=' . $total . "\n";

echo 'events_from_window_sources=' . (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_event_source'"
	. " AND (meta_value LIKE '%events_today' OR meta_value LIKE '%events_tomorrow'"
	. " OR meta_value LIKE '%events_this_week')"
) . "\n";
echo 'event_titles_containing_markers=' . (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title LIKE '%(Today)%'"
	. " OR post_title LIKE '%(Tomorrow)%' OR post_title LIKE '%(This week)%'"
) . "\n";
