<?php
require_once '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-cleanup.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-import-settings.php';

$cleanup = new Conexao_Event_Cleanup();
echo "Total events: " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM wp_posts WHERE post_type='event'" ) . "\n";
echo "cleanup_scope: " . var_export(Conexao_Import_Settings::get('cleanup_scope', 'all'), true) . "\n";
echo "today (site): " . current_datetime()->format('Y-m-d H:i:s') . "\n";

$candidates = (new ReflectionMethod($cleanup, 'find_past_events'))->invoke($cleanup);
echo "Eligible expired candidates remaining: " . count($candidates) . "\n";
if (!empty($candidates)) {
    foreach ($candidates as $id) {
        $p = get_post($id);
        echo "  REMAINING #$id | " . esc_html($p->post_title) . " | imported=" . get_post_meta($id,'_event_imported',true) . " | date=" . get_post_meta($id,'_event_date',true) . " | end=" . get_post_meta($id,'_event_end_date',true) . " " . get_post_meta($id,'_event_end_time',true) . "\n";
    }
} else {
    echo "  (none — all eligible expired events purged)\n";
}
