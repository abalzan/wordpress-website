<?php
/**
 * Read-only probe of tests/lib/option-isolation.php's pure logic.
 * No WordPress, no database, no network: the four store classes are stubbed and
 * delete_option() only records the key it was asked to delete.
 */
define( 'CONEXAO_TESTS_ROOT', '/tmp' );
define( 'CONEXAO_TEST_OPTION_SUFFIX', 'p1234_0123456789abcdef' );
define( 'ABSPATH', '/' );

class Conexao_Import_Log {
	const OPTION_KEY         = 'conexao_event_import_log';
	const TEST_OPTION_PREFIX = 'conexao_event_import_log__test__';
	public static function option_key() { return self::TEST_OPTION_PREFIX . CONEXAO_TEST_OPTION_SUFFIX; }
}
class Conexao_Import_History {
	const OPTION_KEY         = 'conexao_event_import_history';
	const TEST_OPTION_PREFIX = 'conexao_event_import_history__test__';
	public static function option_key() { return self::TEST_OPTION_PREFIX . CONEXAO_TEST_OPTION_SUFFIX; }
}
class Conexao_Event_Cleanup {
	const STATUS_OPTION               = 'conexao_event_cleanup_status';
	const HISTORY_OPTION              = 'conexao_event_cleanup_history';
	const STATUS_TEST_OPTION_PREFIX   = 'conexao_event_cleanup_status__test__';
	const HISTORY_TEST_OPTION_PREFIX  = 'conexao_event_cleanup_history__test__';
	public static function status_option() { return self::STATUS_TEST_OPTION_PREFIX . CONEXAO_TEST_OPTION_SUFFIX; }
	public static function history_option() { return self::HISTORY_TEST_OPTION_PREFIX . CONEXAO_TEST_OPTION_SUFFIX; }
}
$GLOBALS['ois_deleted'] = array();
function delete_option( $key ) { $GLOBALS['ois_deleted'][] = $key; return true; }

require dirname( __DIR__, 3 ) . '/tests/lib/option-isolation.php';

$pass = 0; $fail = 0;
function p( $label, $condition, $detail = '' ) {
	global $pass, $fail;
	if ( $condition ) { $pass++; echo "  PASS: $label\n"; }
	else { $fail++; echo "  FAIL: $label" . ( '' !== $detail ? " ($detail)" : '' ) . "\n"; }
}

$r = test_operator_store_resolve( 'import_log' );
p( 'resolver: symbolic store key -> ok', $r['ok'] && $r['key'] === 'conexao_event_import_log__test__p1234_0123456789abcdef', var_export( $r, true ) );

$r = test_operator_store_resolve( 'conexao_event_import_log' );
p( 'resolver: production literal -> production_key', ! $r['ok'] && 'production_key' === $r['reason'], $r['reason'] );

$r = test_operator_store_resolve( 'conexao_event_cleanup_history' );
p( 'resolver: cleanup production literal -> production_key', 'production_key' === $r['reason'], $r['reason'] );

$r = test_operator_store_resolve( 'blogname' );
p( 'resolver: arbitrary option -> arbitrary_option', 'arbitrary_option' === $r['reason'], $r['reason'] );

$r = test_operator_store_resolve( 'import_logs' );
p( 'resolver: unknown store key -> arbitrary_option', 'arbitrary_option' === $r['reason'], $r['reason'] );

$r = test_operator_store_resolve( 'conexao_event_import_log__test__' );
p( 'resolver: bare prefix -> malformed', 'malformed' === $r['reason'], $r['reason'] );

$r = test_operator_store_resolve( 'conexao_event_import_log__test__p999999_deadbeefdeadbeef' );
p( 'resolver: another process -> foreign_process', 'foreign_process' === $r['reason'], $r['reason'] );

$r = test_operator_store_resolve( 'conexao_event_import_log__test__p1234_0123456789abcdef' );
p( 'resolver: this process test key -> ok', $r['ok'], $r['reason'] );

$r = test_operator_store_resolve( 12345 );
p( 'resolver: non-string -> invalid_type', 'invalid_type' === $r['reason'], $r['reason'] );

$r = test_operator_store_resolve( '' );
p( 'resolver: empty -> empty', 'empty' === $r['reason'], $r['reason'] );

$GLOBALS['ois_deleted'] = array();
$mixed = test_options_reset_test_store( array( 'import_log', 'conexao_event_import_history' ) );
p( 'reset: mixed valid+invalid -> ok=false', ! $mixed['ok'] );
p( 'reset: mixed valid+invalid -> nothing deleted', array() === $GLOBALS['ois_deleted'], implode( ',', $GLOBALS['ois_deleted'] ) );
p( 'reset: mixed valid+invalid -> rejected names production_key', 'production_key' === $mixed['rejected'][0]['reason'], $mixed['rejected'][0]['reason'] );

$dup = test_options_reset_test_store( array( 'import_log', 'import_log' ) );
p( 'reset: duplicate -> ok=false and nothing deleted', ! $dup['ok'] && array() === $GLOBALS['ois_deleted'], $dup['rejected'][0]['reason'] );

$GLOBALS['ois_deleted'] = array();
$good = test_options_reset_test_store( array( 'import_log', 'cleanup_status' ) );
p( 'reset: two valid stores -> ok=true', $good['ok'] );
p( 'reset: two valid stores -> exactly two deletions', 2 === count( $GLOBALS['ois_deleted'] ), count( $GLOBALS['ois_deleted'] ) );
p( 'reset: two valid stores -> deleted list matches', $good['deleted'] === $GLOBALS['ois_deleted'] );

$empty = test_options_reset_test_store( array() );
p( 'reset: empty request -> ok=false (empty)', ! $empty['ok'] && 'empty' === $empty['rejected'][0]['reason'] );

$registry = test_operator_store_registry();
p( 'allowlist: exactly four stores', 4 === count( $registry ) );
p( 'allowlist: symbolic keys', array( 'import_log', 'import_history', 'cleanup_status', 'cleanup_history' ) === array_keys( $registry ) );

echo "\n  $pass passed, $fail failed\n";
exit( $fail > 0 ? 1 : 0 );
