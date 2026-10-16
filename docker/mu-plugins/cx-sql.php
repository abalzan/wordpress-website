<?php
define('SAVEQUERIES', true);
ob_start(function($buffer) {
    global $wpdb;
    foreach ((array) $wpdb->queries as $q) {
        $sql = (string)($q[0] ?? '');
        if (strpos($sql, "mt1.meta_key = '_event_status'") !== false && stripos($sql, 'SELECT wp_posts.ID') === 0) {
            $buffer .= "\n<!-- CXSQL: " . esc_html($sql) . " -->\n";
            break;
        }
    }
    return $buffer;
});
