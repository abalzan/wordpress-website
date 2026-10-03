<?php
require_once '/var/www/html/tests/bootstrap.php';
$label = isset( $argv[1] ) ? $argv[1] : 'snapshot';
$h = Conexao_Import_History::get_all();
$status = array(); $false_empty = 0; $rows = 0; $with_fatal = 0; $incomplete = 0;
foreach ( $h as $e ) {
	$rows++;
	$s = isset($e['status']) ? $e['status'] : '?';
	$status[$s] = isset($status[$s]) ? $status[$s] + 1 : 1;
	if ( 'success' === $s && 0 === (int) $e['found'] ) { $false_empty++; }
	if ( ! empty( $e['fatal_errors'] ) ) { $with_fatal++; }
	if ( isset( $e['complete'] ) && false === $e['complete'] ) { $incomplete++; }
}
$out = array(
	'label' => $label,
	'captured' => current_time( 'mysql' ),
	'rows' => $rows,
	'status_distribution' => $status,
	'rows_found0_and_success' => $false_empty,
	'rows_with_fatal_errors' => $with_fatal,
	'rows_marked_incomplete' => $incomplete,
	'log_entries' => count( Conexao_Import_Log::get_all() ),
	'log_entries_without_run_id' => count( array_filter( Conexao_Import_Log::get_all(), function( $e ) { return '' === trim( (string) $e['run_id'] ); } ) ),
);
echo wp_json_encode( $out, JSON_PRETTY_PRINT ) . "\n";
