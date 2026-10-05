<?php
require_once '/var/www/html/tests/bootstrap.php';
global $wpdb;
$dup = $wpdb->get_results("SELECT pm.meta_value sid, COUNT(*) n FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id AND p.post_type='event' WHERE pm.meta_key='_event_source_id' GROUP BY pm.meta_value HAVING COUNT(*)>1");
$snf = $wpdb->get_var("SELECT COUNT(DISTINCT pm.post_id) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID=pm.post_id AND p.post_type='event' WHERE pm.meta_key='_event_status' AND pm.meta_value='source_not_found'");
$total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='event'");
echo "events_total=$total\nsnf_rows=$snf\ndup_native_ids=" . count($dup) . "\n";
