<?php
/**
 * Probe of the REAL store accessors' two branches. No WordPress, no database:
 * only ABSPATH is defined, because option_key()/status_option()/history_option()
 * are pure resolution functions.
 *
 * Mode is selected by argv[1]:
 *   production          neither harness marker defined
 *   test:<suffix>       both markers defined with a valid suffix
 *   nosuffix            CONEXAO_TESTS_ROOT only
 *   badsuffix           harness + a malformed suffix
 */
$mode = isset( $argv[1] ) ? (string) $argv[1] : 'production';

define( 'ABSPATH', '/' );

if ( 'production' !== $mode ) {
	define( 'CONEXAO_TESTS_ROOT', '/tmp' );
	if ( 'nosuffix' !== $mode ) {
		define( 'CONEXAO_TEST_OPTION_SUFFIX', 'badsuffix' === $mode ? 'nope' : substr( $mode, 5 ) );
	}
}

require dirname( __DIR__, 3 ) . '/wp-content/plugins/conexao-event-importer/includes/class-import-log.php';
require dirname( __DIR__, 3 ) . '/wp-content/plugins/conexao-event-importer/includes/class-import-history.php';
require dirname( __DIR__, 3 ) . '/wp-content/plugins/conexao-event-importer/includes/class-event-cleanup.php';

$accessors = array(
	'import_log'      => array( 'Conexao_Import_Log', 'option_key' ),
	'import_history'  => array( 'Conexao_Import_History', 'option_key' ),
	'cleanup_status'  => array( 'Conexao_Event_Cleanup', 'status_option' ),
	'cleanup_history' => array( 'Conexao_Event_Cleanup', 'history_option' ),
);

foreach ( $accessors as $label => $callable ) {
	try {
		$key = call_user_func( $callable );
		echo $label . '=' . $key . "\n";
	} catch ( RuntimeException $e ) {
		echo $label . '=THREW (' . $e->getMessage() . ")\n";
	}
}
