<?php
require_once '/var/www/html/tests/bootstrap.php';
$h = Conexao_Import_History::get_for_source( 'heritage_week_wicklow', 1 );
if ( $h ) {
	echo "status={$h[0]['status']} found={$h[0]['found']} message={$h[0]['message']}\n";
	foreach ( (array) $h[0]['fatal_errors'] as $f ) { echo "  fatal: {$f['message']} | {$f['technical']}\n"; }
	echo "complete=" . ( isset($h[0]['complete']) ? var_export($h[0]['complete'], true) : 'n/a' ) . "\n";
}
$log = Conexao_Import_Log::get_all();
$oldest_new = 0;
foreach ( $log as $e ) { if ( '' === trim( (string) $e['run_id'] ) ) { $oldest_new++; } }
echo "ENTRIES_WITHOUT_RUN_ID=$oldest_new\n";
// show 3 of them
$n=0; foreach ( $log as $e ) { if ( '' === trim( (string) $e['run_id'] ) && $n < 3 ) { echo "  legacy: [{$e['source']}] {$e['message']}\n"; $n++; } }
