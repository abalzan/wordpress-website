<?php
require_once '/var/www/html/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';
$full = array();
for ( $i = 0; $i < Conexao_Import_History::MAX_ENTRIES; $i++ ) {
	$full[] = array( 'time' => current_time('mysql'), 'source' => 'filler', 'found' => 1, 'new' => 0,
		'updated' => 0, 'unchanged' => 0, 'duplicates' => 0, 'skipped' => 0, 'errors' => 0,
		'status' => 'success', 'message' => 'filler' );
}
update_option( Conexao_Import_History::OPTION_KEY, $full, false );
$before = count( Conexao_Import_History::get_all() );
Conexao_Import_History::record( 'test_source', array('found'=>10,'new'=>3,'updated'=>2,'unchanged'=>3,'duplicates'=>1,'skipped'=>1,'errors'=>0,'failed'=>0), 'success', 'Test import', array('run_id'=>'test-history-run') );
$after = count( Conexao_Import_History::get_all() );
echo "MAX_ENTRIES=" . Conexao_Import_History::MAX_ENTRIES . "\n";
echo "BEFORE=$before AFTER=$after DELTA=" . ( $after - $before ) . "\n";
echo "CONCLUSION=" . ( ( $after === $before + 1 ) ? "count grows (assertion would pass)" : "count cannot grow -> 'History entry was added' fails on a FULL store" ) . "\n";
