<?php
/**
 * One-off script to run the event import and report results.
 * Requires WordPress to be loaded and the plugin active.
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

$plugin = Conexao_Event_Importer::instance();
$stats  = $plugin->importer->run_all();

echo "=== Import Results ===\n";
echo "Found:         {$stats['found']}\n";
echo "New:           {$stats['new']}\n";
echo "Updated:       {$stats['updated']}\n";
echo "Duplicates:    {$stats['duplicates']}\n";
echo "Errors:        {$stats['errors']}\n";

echo "\n=== Sources ===\n";
foreach ( $plugin->sources->get_all() as $source ) {
	echo "- {$source['name']}: status={$source['status']}, last_import={$source['last_import']}, events={$source['events_imported']}\n";
	if ( ! empty( $source['last_error'] ) ) {
		echo "  ERROR: {$source['last_error']}\n";
	}
}

echo "\n=== Import Log (last 10) ===\n";
$log = Conexao_Import_Log::get_all();
foreach ( array_slice( $log, 0, 10 ) as $entry ) {
	echo "[{$entry['time']}] [{$entry['level']}] [{$entry['source']}] {$entry['message']}\n";
}

echo "\n=== Import History (last 5) ===\n";
$history = Conexao_Import_History::get_all();
foreach ( array_slice( $history, 0, 5 ) as $entry ) {
	echo "[{$entry['time']}] [{$entry['source']}] found={$entry['found']} new={$entry['new']} updated={$entry['updated']} dups={$entry['duplicates']} skipped={$entry['skipped']} errors={$entry['errors']} status={$entry['status']}\n";
}

echo "\n=== Events in DB ===\n";
$events = new WP_Query( array(
	'post_type'      => 'event',
	'posts_per_page' => 20,
	'no_found_rows'  => true,
	'update_post_meta_cache' => false,
	'update_post_term_cache' => false,
) );
echo "Total events: {$events->found_posts}\n";
while ( $events->have_posts() ) : $events->the_post();
	$status = get_post_meta( get_the_ID(), '_event_status', true ) ? get_post_meta( get_the_ID(), '_event_status', true ) : '(none)';
	$source = get_post_meta( get_the_ID(), '_event_source', true ) ? get_post_meta( get_the_ID(), '_event_source', true ) : 'manual';
	$date   = get_post_meta( get_the_ID(), '_event_date', true );
	echo "- ID={get_the_ID()} | {$source} | {$status} | {$date} | " . get_the_title() . "\n";
endwhile;
wp_reset_postdata();