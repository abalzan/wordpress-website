<?php
/**
 * One-off script to run the Eventbrite Laois import and report results.
 * Requires WordPress to be loaded and the plugin active.
 *
 * Usage: docker compose exec wordpress php /var/www/html/scripts/run-eventbrite-import.php
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

$plugin = Conexao_Event_Importer::instance();
$stats  = $plugin->importer->run_source( 'eventbrite' );

echo "=== Eventbrite Import Results ===\n";
echo "Found:         {$stats['found']}\n";
echo "New:           {$stats['new']}\n";
echo "Updated:       {$stats['updated']}\n";
echo "Unchanged:     {$stats['unchanged']}\n";
echo "Duplicates:    {$stats['duplicates']}\n";
echo "Errors:        {$stats['errors']}\n";

echo "\n=== Eventbrite Source ===\n";
$source = $plugin->sources->get( 'eventbrite' );
if ( $source ) {
	echo "Name:          {$source['name']}\n";
	echo "Status:        {$source['status']}\n";
	echo "Last Import:   {$source['last_import']}\n";
	echo "Events:        {$source['events_imported']}\n";
	if ( ! empty( $source['last_error'] ) ) {
		echo "ERROR:         {$source['last_error']}\n";
	}
}

echo "\n=== Eventbrite Import Log (last 10) ===\n";
$log = Conexao_Import_Log::get_for_source( 'eventbrite', 10 );
foreach ( $log as $entry ) {
	echo "[{$entry['time']}] [{$entry['level']}] {$entry['message']}\n";
}

echo "\n=== Eventbrite Import History (last 5) ===\n";
$history = Conexao_Import_History::get_for_source( 'eventbrite', 5 );
foreach ( $history as $entry ) {
	echo "[{$entry['time']}] found={$entry['found']} new={$entry['new']} updated={$entry['updated']} dups={$entry['duplicates']} skipped={$entry['skipped']} errors={$entry['errors']} status={$entry['status']}\n";
}