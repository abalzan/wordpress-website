<?php
/**
 * Local-only helper: print _event_source distribution for the export tests.
 * Usage: wp --allow-root eval-file tests/export-source-counts.php
 */
global $wpdb;
$rows = $wpdb->get_results(
	"SELECT pm.meta_value AS source, COUNT(*) AS c
	 FROM {$wpdb->posts} p
	 JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
	 WHERE p.post_type = 'event' AND pm.meta_key = '_event_source'
	 GROUP BY pm.meta_value
	 ORDER BY c DESC"
);
foreach ( $rows as $r ) {
	echo $r->source . '=' . $r->c . PHP_EOL;
}
echo 'no_source=' . (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->posts} p
	 WHERE p.post_type = 'event'
	 AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = '_event_source')"
) . PHP_EOL;
