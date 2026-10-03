<?php
require_once '/var/www/html/tests/bootstrap.php';
$log = Conexao_Import_Log::get_all();
$st = array(); $n = 0;
foreach ( $log as $e ) {
	if ( (int) $e['http_status'] > 0 ) { $st[ (int) $e['http_status'] ] = isset($st[(int)$e['http_status']]) ? $st[(int)$e['http_status']] + 1 : 1; }
}
$run = 'all-';
$withrun = 0; $norun = 0;
foreach ( $log as $e ) { if ( '' === trim( (string) $e['run_id'] ) ) { $norun++; } else { $withrun++; } }
echo "LOG_ENTRIES=" . count($log) . "\n";
echo "HTTP_STATUS_IN_LOG=" . json_encode( $st ) . "\n";
echo "WITH_RUN_ID=$withrun\n";
echo "WITHOUT_RUN_ID=$norun\n";
$h = Conexao_Import_History::get_all();
$fail = array(); $inc = 0; $f0s = 0;
foreach ( $h as $e ) {
	if ( 'failed' === $e['status'] ) { $fail[] = $e['source']; }
	if ( isset($e['complete']) && false === $e['complete'] ) { $inc++; }
	if ( 'success' === $e['status'] && 0 === (int) $e['found'] ) { $f0s++; }
}
echo "HISTORY_FAILED=" . count($fail) . "\n";
echo "HISTORY_INCOMPLETE=$inc\n";
echo "HISTORY_FOUND0_SUCCESS=$f0s\n";
echo "FAILED_SOURCES=" . implode( ', ', array_slice($fail, 0, 45) ) . "\n";
