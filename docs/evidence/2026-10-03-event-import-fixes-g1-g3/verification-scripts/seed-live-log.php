<?php
require_once '/var/www/html/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';
$e = array();
for ( $i = 0; $i < 40; $i++ ) {
	$e[] = array( 'time' => current_time( 'mysql' ), 'source' => 'eventbrite_carlow', 'level' => 'error',
		'message' => 'OPERATOR DIAGNOSTIC ' . $i, 'run_id' => 'operator-run', 'event_id' => '',
		'event_title' => '', 'http_status' => 429, 'context' => array() );
}
update_option( Conexao_Import_Log::OPTION_KEY, $e, false );
echo "SEEDED=" . count( Conexao_Import_Log::get_all() ) . "\n";
