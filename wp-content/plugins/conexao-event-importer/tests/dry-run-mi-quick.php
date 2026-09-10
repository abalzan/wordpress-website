<?php
/**
 * Quick dry-run validation with capped detail enrichment (for fast testing).
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/dry-run-mi-quick.php
 */
$wp_load = dirname(dirname(dirname(dirname(__DIR__)))).'/wp-load.php';
if (file_exists($wp_load)) { require_once $wp_load; } else { require_once '/var/www/html/wp-load.php'; }
require_once WP_PLUGIN_DIR.'/conexao-event-importer/conexao-event-importer.php';

$sources_manager = new Conexao_Event_Sources();
$all = get_option(Conexao_Event_Sources::OPTION_KEY, array());
$prev_status = isset($all['motorsport_ireland']['status']) ? $all['motorsport_ireland']['status'] : 'inactive';

// Enable + cap detail enrichment for a fast but representative run.
$all['motorsport_ireland']['status'] = 'active';
$all['motorsport_ireland']['max_detail_pages'] = 3;
$all['motorsport_ireland']['crawl_delay'] = 1;
update_option(Conexao_Event_Sources::OPTION_KEY, $all, false);

$result = Conexao_Event_Importer::instance()->importer->run_source('motorsport_ireland', true);

// Restore.
$all = get_option(Conexao_Event_Sources::OPTION_KEY, array());
$all['motorsport_ireland']['status'] = $prev_status;
unset($all['motorsport_ireland']['max_detail_pages']);
unset($all['motorsport_ireland']['crawl_delay']);
update_option(Conexao_Event_Sources::OPTION_KEY, $all, false);

echo "MOTORSPORT IRELAND QUICK DRY-RUN (capped detail enrichment)\n";
echo "NO posts/taxonomies/images written\n\n";
echo 'status:    '.($result['status'] ?? '?')."\n";
echo 'found:     '.(int)$result['found']."\n";
echo 'created:   '.(int)$result['created']." (dry-run: reported, not written)\n";
echo 'updated:   '.(int)$result['updated']."\n";
echo 'unchanged: '.(int)$result['unchanged']."\n";
echo 'skipped:   '.(int)$result['skipped']."\n";
echo 'failed:    '.(int)$result['failed']."\n";
echo 'fatal:     '.(!empty($result['fatal_errors']) ? implode('; ', array_column($result['fatal_errors'], 'message')) : 'none')."\n\n";

if (!empty($result['event_results'])) {
    $outcomes = array_count_values(array_column($result['event_results'], 'outcome'));
    echo "Outcome summary:\n";
    foreach ($outcomes as $o => $c) { echo "  $o: $c\n"; }
    echo "\nFirst 15 events:\n";
    foreach (array_slice($result['event_results'], 0, 15) as $er) {
        printf("  %-8s - %s\n", strtoupper($er['outcome']), $er['title']);
    }
}
echo "\nSource status restored to: $prev_status\n";
