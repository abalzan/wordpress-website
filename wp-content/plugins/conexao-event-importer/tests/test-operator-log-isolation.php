<?php
/**
 * Stage 0.5 acceptance suite — operator-log isolation.
 *
 * ## What this suite proves
 *
 * The four operator stores (import log, import history, cleanup status, cleanup
 * history) are redirected to a per-process test option whenever the shared test
 * harness is loaded, and ordinary test execution can never read, write or delete
 * the operator's live rows.
 *
 * The suite exercises the REAL logging classes and the REAL helper API. It makes
 * no external request, imports no event and never writes a production operator
 * option.
 *
 * ## Sections
 *
 *   A — bootstrap contract (suffix, accessors, fail-closed children)
 *   B — real logging writes land in test storage only
 *   C — reset helper, positive
 *   D — reset helper, negative (production key, arbitrary option, unknown store,
 *       foreign process, malformed, non-string, empty, duplicate, mixed)
 *   E — test_options_isolate() refuses the protected stores
 *   F — fatal termination leaves the live store intact and cleans the test row
 *   G — three concurrent children get three distinct stores
 *   H — parent/child identity
 *   I — orphan classification (test-prefixed only)
 *   J — production-path smoke test (class level, NO WordPress, NO database)
 *   K — the live operator options are byte-identical at the end
 *
 * The smoke test in section J is a CLASS-LEVEL proof that a non-test process
 * resolves the production option name. It does not exercise the live WordPress
 * persistence path, and this suite does not claim that it does.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-import-history.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-cleanup.php';

test_prerequisite_hint( 'role:isolated-test-store' );

test_require(
	class_exists( 'Conexao_Import_Log' )
		&& class_exists( 'Conexao_Import_History' )
		&& class_exists( 'Conexao_Event_Cleanup' ),
	'activate-plugin:conexao-event-importer',
	'test prerequisite is available: the importer store classes are loaded',
	'activate the conexao-event-importer plugin'
);

test_require(
	function_exists( 'exec' ) && function_exists( 'proc_open' ) && '' !== (string) PHP_BINARY,
	'fixture:php-cli',
	'test prerequisite is available: child PHP processes can be started',
	'allow exec()/proc_open() and run the suite through the CLI SAPI'
);

// Secondary safeguard: proves the property from inside the suite.
test_require_operator_store_isolation();

$ois_bootstrap = dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
$ois_plugin    = WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';
$ois_log_file  = WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-import-log.php';

/**
 * The RAW stored bytes of one option, or '' when it does not exist.
 *
 * Deliberately read through $wpdb rather than get_option(): the point is to
 * prove the stored bytes did not change, not that the decoded value compares
 * equal.
 *
 * @param string $option_name Option name.
 * @return string
 */
function ois_raw_option( $option_name ) {
	global $wpdb;

	return (string) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
			$option_name
		)
	);
}

/**
 * Raw bytes of the four PRODUCTION operator options.
 *
 * @return array<string,string>
 */
function ois_live_raw_map() {
	return array(
		'import_log'      => ois_raw_option( Conexao_Import_Log::OPTION_KEY ),
		'import_history'  => ois_raw_option( Conexao_Import_History::OPTION_KEY ),
		'cleanup_status'  => ois_raw_option( Conexao_Event_Cleanup::STATUS_OPTION ),
		'cleanup_history' => ois_raw_option( Conexao_Event_Cleanup::HISTORY_OPTION ),
	);
}

/**
 * Run a temporary child PHP script and return its exit status and output.
 *
 * @param string $code Child script body, without the opening `<?php`.
 * @return array{code:int,output:string}
 */
function ois_run_child( $code ) {
	$file = tempnam( sys_get_temp_dir(), 'conexao-stage05-' );

	if ( false === $file ) {
		return array(
			'code'   => -1,
			'output' => 'could not create a temporary child script',
		);
	}

	file_put_contents( $file, "<?php\n" . $code );

	$output = array();
	$status = 0;

	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );

	unlink( $file );

	return array(
		'code'   => (int) $status,
		'output' => implode( "\n", $output ),
	);
}

/**
 * Parse a `KEY=value` marker out of child output.
 *
 * @param string $output Child output.
 * @param string $marker Marker name.
 * @return string Value, or '' when absent.
 */
function ois_marker( $output, $marker ) {
	if ( preg_match( '/^' . preg_quote( $marker, '/' ) . '=(.*)$/m', $output, $matches ) ) {
		return trim( $matches[1] );
	}

	return '';
}

$ois_live_before = ois_live_raw_map();

$ois_parent_key = Conexao_Import_Log::option_key();

// ===========================================================================
// A — bootstrap contract.
// ===========================================================================
test_section( 'A — bootstrap contract' );

$ois_suffix = (string) CONEXAO_TEST_OPTION_SUFFIX;

assert_true( defined( 'CONEXAO_TESTS_ROOT' ), 'A1: the harness marker CONEXAO_TESTS_ROOT is defined' );
assert_true( defined( 'CONEXAO_TEST_OPTION_SUFFIX' ), 'A1: the per-process suffix CONEXAO_TEST_OPTION_SUFFIX is defined' );
assert_true(
	1 === preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', $ois_suffix ),
	'A1: the suffix matches p<pid>_<16 hex> (got ' . $ois_suffix . ')'
);
assert_true(
	0 === strpos( $ois_suffix, 'p' . getmypid() . '_' ),
	'A1: the suffix embeds THIS process id (' . getmypid() . ')'
);

$ois_expected_keys = array(
	'import_log'      => Conexao_Import_Log::TEST_OPTION_PREFIX . $ois_suffix,
	'import_history'  => Conexao_Import_History::TEST_OPTION_PREFIX . $ois_suffix,
	'cleanup_status'  => Conexao_Event_Cleanup::STATUS_TEST_OPTION_PREFIX . $ois_suffix,
	'cleanup_history' => Conexao_Event_Cleanup::HISTORY_TEST_OPTION_PREFIX . $ois_suffix,
);

$ois_resolved_keys = array(
	'import_log'      => Conexao_Import_Log::option_key(),
	'import_history'  => Conexao_Import_History::option_key(),
	'cleanup_status'  => Conexao_Event_Cleanup::status_option(),
	'cleanup_history' => Conexao_Event_Cleanup::history_option(),
);

$ois_production_names = array(
	'import_log'      => Conexao_Import_Log::OPTION_KEY,
	'import_history'  => Conexao_Import_History::OPTION_KEY,
	'cleanup_status'  => Conexao_Event_Cleanup::STATUS_OPTION,
	'cleanup_history' => Conexao_Event_Cleanup::HISTORY_OPTION,
);

foreach ( $ois_expected_keys as $ois_store => $ois_expected_key ) {
	assert_equals( $ois_expected_key, $ois_resolved_keys[ $ois_store ], 'A2: ' . $ois_store . ' resolves to its own test prefix + suffix' );
	assert_true(
		substr( $ois_resolved_keys[ $ois_store ], -strlen( $ois_suffix ) ) === $ois_suffix,
		'A2: ' . $ois_store . ' ends with the current suffix'
	);
	assert_true(
		$ois_resolved_keys[ $ois_store ] !== $ois_production_names[ $ois_store ],
		'A2: ' . $ois_store . ' never resolves to the production option'
	);
}

// A3 — a pre-defined suffix must be refused BEFORE WordPress loads.
$ois_predefine_cases = array(
	'valid'     => $ois_suffix,
	'empty'     => '',
	'malformed' => 'p12_not-a-hex-token',
);

foreach ( $ois_predefine_cases as $ois_case => $ois_predefined ) {
	$ois_result = ois_run_child(
		'define( "CONEXAO_TEST_OPTION_SUFFIX", ' . var_export( $ois_predefined, true ) . " );\n"
		. 'require_once ' . var_export( $ois_bootstrap, true ) . ";\n"
		. "echo \"REACHED=1\\n\";\n"
	);

	assert_true( 0 !== $ois_result['code'], 'A3(' . $ois_case . '): the bootstrap exits non-zero for a pre-defined suffix' );
	assert_true(
		false !== strpos( $ois_result['output'], 'conexao-test-bootstrap:' ),
		'A3(' . $ois_case . '): the failure names the bootstrap (got: ' . trim( $ois_result['output'] ) . ')'
	);
	assert_true( false === strpos( $ois_result['output'], 'REACHED=1' ), 'A3(' . $ois_case . '): the suite body never runs' );
}

assert_true( ois_live_raw_map() === $ois_live_before, 'A3: the refused children wrote nothing to the live options' );

// A4 — the accessor throws when the harness marker is present but the suffix is
// missing or invalid. Proved WITHOUT loading WordPress: only the class file is
// required, with a stubbed ABSPATH.
$ois_no_suffix = ois_run_child(
	"define( 'ABSPATH', '/' );\n"
	. "define( 'CONEXAO_TESTS_ROOT', '/tmp' );\n"
	. 'require_once ' . var_export( $ois_log_file, true ) . ";\n"
	. "try {\n"
	. "\techo 'KEY=' . Conexao_Import_Log::option_key() . \"\\n\";\n"
	. "} catch ( RuntimeException \$e ) {\n"
	. "\techo \"THREW=1\\n\";\n"
	. "}\n"
);

assert_true( 0 === $ois_no_suffix['code'], 'A4: the no-suffix probe process exits cleanly' );
assert_true(
	false !== strpos( $ois_no_suffix['output'], 'THREW=1' ),
	'A4: a harness marker WITHOUT a suffix throws RuntimeException'
);
assert_true(
	false === strpos( $ois_no_suffix['output'], 'KEY=' . Conexao_Import_Log::OPTION_KEY ),
	'A4: and never silently falls back to the production option'
);

$ois_bad_suffix = ois_run_child(
	"define( 'ABSPATH', '/' );\n"
	. "define( 'CONEXAO_TESTS_ROOT', '/tmp' );\n"
	. "define( 'CONEXAO_TEST_OPTION_SUFFIX', 'p12_not-a-hex-token' );\n"
	. 'require_once ' . var_export( $ois_log_file, true ) . ";\n"
	. "try {\n"
	. "\techo 'KEY=' . Conexao_Import_Log::option_key() . \"\\n\";\n"
	. "} catch ( RuntimeException \$e ) {\n"
	. "\techo \"THREW=1\\n\";\n"
	. "}\n"
);

assert_true(
	false !== strpos( $ois_bad_suffix['output'], 'THREW=1' ),
	'A4: a MALFORMED suffix throws RuntimeException'
);

$ois_good_suffix = ois_run_child(
	"define( 'ABSPATH', '/' );\n"
	. "define( 'CONEXAO_TESTS_ROOT', '/tmp' );\n"
	. "define( 'CONEXAO_TEST_OPTION_SUFFIX', 'p123_abcdefabcdefabcd' );\n"
	. 'require_once ' . var_export( $ois_log_file, true ) . ";\n"
	. "try {\n"
	. "\techo 'KEY=' . Conexao_Import_Log::option_key() . \"\\n\";\n"
	. "} catch ( RuntimeException \$e ) {\n"
	. "\techo \"THREW=1\\n\";\n"
	. "}\n"
);

assert_true(
	false !== strpos( $ois_good_suffix['output'], 'KEY=' . Conexao_Import_Log::TEST_OPTION_PREFIX . 'p123_abcdefabcdefabcd' ),
	'A4: a VALID suffix resolves the test-prefixed key'
);
assert_true(
	false === strpos( $ois_good_suffix['output'], 'THREW=1' ),
	'A4: a VALID suffix does not throw'
);

// ===========================================================================
// B — the real logging classes write test storage only.
// ===========================================================================
test_section( 'B — real logging writes land in test storage only' );

// Start from a known state in THIS process's four test rows.
$ois_reset = test_options_reset_test_store( array( 'import_log', 'import_history', 'cleanup_status', 'cleanup_history' ) );
assert_true( $ois_reset['ok'], 'B0: this process\'s four test rows can be reset' );

Conexao_Import_Log::add( 'ois_probe', 'info', 'B: single entry', array( 'run_id' => 'ois_run', 'http_status' => 200 ) );

$ois_log_entries = Conexao_Import_Log::get_all();
assert_equals( 1, count( $ois_log_entries ), 'B1: add() persists exactly one entry in the test log' );
assert_equals( 'B: single entry', $ois_log_entries[0]['message'], 'B1: the entry message is stored' );
assert_equals( 'ois_probe', $ois_log_entries[0]['source'], 'B1: the entry source is stored' );
assert_equals( 200, (int) $ois_log_entries[0]['http_status'], 'B1: the entry http_status is stored' );
assert_true( ois_live_raw_map() === $ois_live_before, 'B1: add() did not touch any live operator option' );

Conexao_Import_Log::begin_run();
Conexao_Import_Log::add( 'ois_probe', 'warning', 'B: buffered one', array( 'run_id' => 'ois_run' ) );
Conexao_Import_Log::add( 'ois_probe', 'error', 'B: buffered two', array( 'run_id' => 'ois_run' ) );
Conexao_Import_Log::end_run();

assert_equals( 3, count( Conexao_Import_Log::get_all() ), 'B2: a managed run flushes into the test log' );
assert_equals( 3, count( Conexao_Import_Log::get_for_run( 'ois_run', 100 ) ), 'B2: the run index resolves the test entries' );
assert_true( ois_live_raw_map() === $ois_live_before, 'B2: the managed run did not touch any live operator option' );

$ois_history_entry = Conexao_Import_History::record(
	'ois_probe',
	array( 'found' => 3, 'new' => 1, 'updated' => 1, 'unchanged' => 1, 'errors' => 0 ),
	'success',
	'B: history record',
	array( 'run_id' => 'ois_run' )
);

assert_equals( 'ois_probe', $ois_history_entry['source'], 'B3: record() returns the stored entry' );
assert_equals( 1, count( Conexao_Import_History::get_all() ), 'B3: the history row holds exactly one record' );
assert_true( ois_live_raw_map() === $ois_live_before, 'B3: record() did not touch any live operator option' );

// The cleanup status/history writers are protected; drive the REAL methods.
$ois_cleanup       = new Conexao_Event_Cleanup();
$ois_cleanup_save  = new ReflectionMethod( $ois_cleanup, 'save_status' );
$ois_cleanup_save->setAccessible( true );
$ois_history_save  = new ReflectionMethod( $ois_cleanup, 'save_history' );
$ois_history_save->setAccessible( true );

$ois_cleanup_result = array(
	'time'             => current_time( 'mysql' ),
	'events_found'     => 4,
	'events_deleted'   => 4,
	'images_evaluated' => 0,
	'images_deleted'   => 0,
	'images_preserved' => 0,
	'errors'           => array(),
);

$ois_cleanup_save->invoke( $ois_cleanup, $ois_cleanup_result );
$ois_history_save->invoke( $ois_cleanup, $ois_cleanup_result );

assert_equals( 4, (int) $ois_cleanup->get_status()['events_found'], 'B4: save_status()/get_status() round-trip in the test store' );
assert_equals( 1, count( $ois_cleanup->get_history() ), 'B4: save_history()/get_history() round-trip in the test store' );
assert_true( ois_live_raw_map() === $ois_live_before, 'B4: the cleanup writers did not touch any live operator option' );

// Every write must be visible in the test rows, and only there.
foreach ( $ois_resolved_keys as $ois_store => $ois_test_key ) {
	assert_equals( $ois_test_key, test_operator_store_option_name( $ois_store ), 'B5: ' . $ois_store . ' resolves consistently' );
	assert_true(
		null !== get_option( $ois_test_key, null ),
		'B5: ' . $ois_store . ' has a row in this process\'s test store'
	);
}

assert_true(
	0 === strpos( $ois_parent_key, Conexao_Import_Log::TEST_OPTION_PREFIX )
		&& $ois_parent_key !== Conexao_Import_Log::OPTION_KEY,
	'B5: the parent\'s resolved log key is test-prefixed and is not the production option'
);

// ===========================================================================
// C — reset helper, positive path.
// ===========================================================================
test_section( 'C — reset helper deletes exactly the permitted current-process keys' );

// Section B left all four test rows populated.
$ois_before_reset = array();
foreach ( $ois_resolved_keys as $ois_store => $ois_test_key ) {
	$ois_before_reset[ $ois_store ] = ois_raw_option( $ois_test_key );
	assert_true( '' !== $ois_before_reset[ $ois_store ], 'C1: ' . $ois_store . ' exists before the reset' );
}

$ois_positive = test_options_reset_test_store( array( 'import_log', 'import_history', 'cleanup_status', 'cleanup_history' ) );

assert_true( $ois_positive['ok'], 'C2: the reset reports ok' );
assert_equals( array(), $ois_positive['rejected'], 'C2: the reset rejected nothing' );
assert_equals( 4, count( $ois_positive['deleted'] ), 'C2: the reset deleted exactly four rows' );

$ois_sorted_expected = $ois_resolved_keys;
$ois_sorted_actual   = $ois_positive['deleted'];
sort( $ois_sorted_expected );
sort( $ois_sorted_actual );

assert_equals( $ois_sorted_expected, $ois_sorted_actual, 'C3: the deleted list is exactly the four resolved test keys' );

foreach ( $ois_resolved_keys as $ois_store => $ois_test_key ) {
	assert_equals( '', ois_raw_option( $ois_test_key ), 'C4: ' . $ois_store . ' test row is gone' );
	assert_true( null === get_option( $ois_test_key, null ), 'C4: ' . $ois_store . ' reads as absent' );
}

assert_true( ois_live_raw_map() === $ois_live_before, 'C5: the positive reset left every live operator option byte-identical' );

// ===========================================================================
// D — reset helper, negative paths. Nothing may be deleted.
// ===========================================================================
test_section( 'D — reset helper refuses production keys, foreign keys and arbitrary options' );

// Seed all four test rows again: they must survive every rejected request.
test_options_write_test_store( 'import_log', array( array( 'message' => 'D sentinel log' ) ) );
test_options_write_test_store( 'import_history', array( array( 'source' => 'd_sentinel' ) ) );
test_options_write_test_store( 'cleanup_status', array( 'events_found' => 1 ) );
test_options_write_test_store( 'cleanup_history', array( array( 'events_found' => 1 ) ) );

$ois_sentinels = array();
foreach ( $ois_resolved_keys as $ois_store => $ois_test_key ) {
	$ois_sentinels[ $ois_store ] = ois_raw_option( $ois_test_key );
	assert_true( '' !== $ois_sentinels[ $ois_store ], 'D0: ' . $ois_store . ' has a sentinel row' );
}

$ois_foreign_key = Conexao_Import_Log::TEST_OPTION_PREFIX . 'p999999_deadbeefdeadbeef';

$ois_negative_cases = array(
	'production key'           => array( 'request' => Conexao_Import_Log::OPTION_KEY, 'reason' => 'production_key' ),
	'cleanup production key'   => array( 'request' => Conexao_Event_Cleanup::HISTORY_OPTION, 'reason' => 'production_key' ),
	'arbitrary option'         => array( 'request' => 'blogname', 'reason' => 'arbitrary_option' ),
	'unknown store key'        => array( 'request' => 'import_logs', 'reason' => 'arbitrary_option' ),
	'foreign process test key' => array( 'request' => $ois_foreign_key, 'reason' => 'foreign_process' ),
	'malformed test key'       => array( 'request' => Conexao_Import_Log::TEST_OPTION_PREFIX, 'reason' => 'malformed' ),
	'non-string request'       => array( 'request' => 12345, 'reason' => 'invalid_type' ),
	'empty request'            => array( 'request' => '', 'reason' => 'empty' ),
);

foreach ( $ois_negative_cases as $ois_label => $ois_case ) {
	$ois_negative = test_options_reset_test_store( array( $ois_case['request'] ) );

	assert_true( ! $ois_negative['ok'], 'D1(' . $ois_label . '): the reset refuses the request' );
	assert_equals( array(), $ois_negative['deleted'], 'D1(' . $ois_label . '): nothing is deleted' );
	assert_equals( 1, count( $ois_negative['rejected'] ), 'D1(' . $ois_label . '): exactly one rejection is reported' );
	assert_equals( $ois_case['reason'], $ois_negative['rejected'][0]['reason'], 'D1(' . $ois_label . '): the reason is correct' );

	foreach ( $ois_resolved_keys as $ois_store => $ois_test_key ) {
		assert_equals( $ois_sentinels[ $ois_store ], ois_raw_option( $ois_test_key ), 'D1(' . $ois_label . '): ' . $ois_store . ' is byte-identical' );
	}
}

$ois_empty_reset = test_options_reset_test_store( array() );
assert_true( ! $ois_empty_reset['ok'], 'D2: an empty request list is refused' );
assert_equals( 'empty', $ois_empty_reset['rejected'][0]['reason'], 'D2: an empty request list reports reason "empty"' );

$ois_duplicate = test_options_reset_test_store( array( 'import_log', 'import_log' ) );
assert_true( ! $ois_duplicate['ok'], 'D3: a duplicate request is refused' );
assert_equals( 'duplicate', $ois_duplicate['rejected'][0]['reason'], 'D3: the duplicate reason is reported' );
assert_equals( array(), $ois_duplicate['deleted'], 'D3: nothing is deleted for a duplicate request' );

// Mixed valid + invalid: the crucial case. One rejection means zero deletions.
$ois_mixed = test_options_reset_test_store(
	array( 'import_log', Conexao_Import_Log::OPTION_KEY, 'cleanup_history' )
);

assert_true( ! $ois_mixed['ok'], 'D4: a mixed valid/invalid request fails' );
assert_equals( array(), $ois_mixed['deleted'], 'D4: a mixed request deletes NOTHING' );
assert_equals( 'production_key', $ois_mixed['rejected'][0]['reason'], 'D4: the invalid entry is reported' );

foreach ( $ois_resolved_keys as $ois_store => $ois_test_key ) {
	assert_equals( $ois_sentinels[ $ois_store ], ois_raw_option( $ois_test_key ), 'D4: ' . $ois_store . ' survives the mixed request unchanged' );
}

assert_true( false !== get_option( 'blogname', false ), 'D5: the unrelated blogname option still exists' );
assert_true( null === get_option( $ois_foreign_key, null ), 'D5: the foreign-process key was never created or deleted' );
assert_true( ois_live_raw_map() === $ois_live_before, 'D5: no negative case touched a live operator option' );

// ===========================================================================
// E — test_options_isolate() refuses the protected stores.
// ===========================================================================
test_section( 'E — test_options_isolate() refuses the protected operator stores' );

/**
 * Run a child that calls test_options_isolate() with one argument expression.
 *
 * @param string $argument_expression PHP expression yielding the argument array.
 * @return array{code:int,output:string}
 */
function ois_isolate_child( $argument_expression, $bootstrap, $plugin ) {
	return ois_run_child(
		'require_once ' . var_export( $bootstrap, true ) . ";\n"
		. 'require_once ' . var_export( $plugin, true ) . ";\n"
		. 'test_options_isolate( ' . $argument_expression . " );\n"
		. "echo \"REACHED=1\\n\";\n"
	);
}

$ois_isolate_cases = array(
	'production constant' => 'array( Conexao_Import_Log::OPTION_KEY )',
	'production literal'  => "array( 'conexao_event_cleanup_history' )",
	'test-prefixed key'   => 'array( Conexao_Import_History::option_key() )',
);

foreach ( $ois_isolate_cases as $ois_label => $ois_expression ) {
	$ois_isolate_result = ois_isolate_child( $ois_expression, $ois_bootstrap, $ois_plugin );

	assert_true( 0 !== $ois_isolate_result['code'], 'E1(' . $ois_label . '): the refusing child exits non-zero' );
	assert_true(
		false !== strpos( $ois_isolate_result['output'], 'refuses the protected operator store' ),
		'E1(' . $ois_label . '): the refusal is named on stderr'
	);
	assert_true( false === strpos( $ois_isolate_result['output'], 'REACHED=1' ), 'E1(' . $ois_label . '): seeding never runs' );
}

// A NON-protected option must still be accepted, or the refusal is over-broad.
$ois_isolate_allowed = ois_isolate_child(
	"array( 'conexao_event_source_health' )",
	$ois_bootstrap,
	$ois_plugin
);

assert_true(
	false !== strpos( $ois_isolate_allowed['output'], 'REACHED=1' ),
	'E2: a non-protected option is still isolatable (the refusal is not over-broad)'
);

assert_true( ois_live_raw_map() === $ois_live_before, 'E3: the refusing children wrote nothing to the live options' );

// ===========================================================================
// F — a fatal child leaves the live store intact and cleans its own row.
// ===========================================================================
test_section( 'F — fatal termination: live store intact, child test row cleaned' );

$ois_fatal_child = ois_run_child(
	'require_once ' . var_export( $ois_bootstrap, true ) . ";\n"
	. 'require_once ' . var_export( $ois_plugin, true ) . ";\n"
	. "\$key = Conexao_Import_Log::option_key();\n"
	. "fwrite( STDOUT, 'KEY=' . \$key . \"\\n\" );\n"
	. "Conexao_Import_Log::add( 'ois_fatal', 'error', 'F: entry before the fatal' );\n"
	. "fflush( STDOUT );\n"
	. "throw new Error( 'ois child fatal (deliberate)' );\n"
);

$ois_fatal_key = ois_marker( $ois_fatal_child['output'], 'KEY' );

assert_true( 0 !== $ois_fatal_child['code'], 'F1: the fatal child exits non-zero' );
assert_true(
	0 === strpos( $ois_fatal_key, Conexao_Import_Log::TEST_OPTION_PREFIX ),
	'F1: the child reported a test-prefixed key (got ' . $ois_fatal_key . ')'
);
assert_true(
	$ois_fatal_key !== Conexao_Import_Log::OPTION_KEY && $ois_fatal_key !== $ois_parent_key,
	'F1: the child key is neither the production option nor the parent key'
);
assert_true(
	null === get_option( $ois_fatal_key, null ),
	'F2: the child\'s test row was removed by its shutdown cleaner'
);
assert_true( ois_live_raw_map() === $ois_live_before, 'F3: the fatal child left every live operator option byte-identical' );

// The parent's own store is untouched by the child's cleaner.
assert_true(
	null !== get_option( $ois_parent_key, null ),
	'F4: the parent\'s test store survives the child\'s shutdown cleaner'
);

// ===========================================================================
// G — three concurrent children get three distinct stores.
// ===========================================================================
test_section( 'G — concurrent children never share a test store' );

$ois_concurrent_script = 'require_once ' . var_export( $ois_bootstrap, true ) . ";\n"
	. 'require_once ' . var_export( $ois_plugin, true ) . ";\n"
	. "\$key = Conexao_Import_Log::option_key();\n"
	. "for ( \$i = 1; \$i <= 3; \$i++ ) {\n"
	. "\tConexao_Import_Log::add( 'ois_concurrent', 'info', 'G entry ' . \$i );\n"
	. "}\n"
	. "echo 'KEY=' . \$key . \"\\n\";\n"
	. "echo 'SUFFIX=' . CONEXAO_TEST_OPTION_SUFFIX . \"\\n\";\n"
	. "echo 'PID=' . getmypid() . \"\\n\";\n"
	. "echo 'COUNT=' . count( Conexao_Import_Log::get_all() ) . \"\\n\";\n";

/**
 * Start a child process without waiting for it.
 *
 * @param string $code Child script body.
 * @return array{handle:resource,file:string,pipes:array}
 */
function ois_start_child( $code ) {
	$file = tempnam( sys_get_temp_dir(), 'conexao-stage05-conc-' );
	file_put_contents( $file, "<?php\n" . $code );

	$descriptors = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$process = proc_open(
		escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ),
		$descriptors,
		$pipes
	);

	return array(
		'process' => $process,
		'file'    => $file,
		'pipes'   => $pipes,
	);
}

$ois_children = array();

for ( $ois_index = 1; $ois_index <= 3; $ois_index++ ) {
	$ois_children[] = ois_start_child( $ois_concurrent_script );
}

$ois_concurrent_keys = array();

foreach ( $ois_children as $ois_child ) {
	$ois_stdout = (string) stream_get_contents( $ois_child['pipes'][1] );
	$ois_stderr = (string) stream_get_contents( $ois_child['pipes'][2] );

	fclose( $ois_child['pipes'][1] );
	fclose( $ois_child['pipes'][2] );

	$ois_exit = proc_close( $ois_child['process'] );
	unlink( $ois_child['file'] );

	$ois_key    = ois_marker( $ois_stdout, 'KEY' );
	$ois_suffix = ois_marker( $ois_stdout, 'SUFFIX' );

	assert_equals( 0, $ois_exit, 'G1: a concurrent child exits 0' . ( '' !== $ois_stderr ? ' (stderr: ' . trim( $ois_stderr ) . ')' : '' ) );
	assert_true( 0 === strpos( $ois_key, Conexao_Import_Log::TEST_OPTION_PREFIX ), 'G1: the child reports a test-prefixed key' );
	assert_equals( 3, (int) ois_marker( $ois_stdout, 'COUNT' ), 'G1: the child saw exactly its own three entries' );
	assert_true( 1 === preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', $ois_suffix ), 'G1: the child reported a valid suffix' );

	$ois_concurrent_keys[] = $ois_key;
}

assert_equals( 3, count( array_unique( $ois_concurrent_keys ) ), 'G2: the three children used three DISTINCT keys' );
assert_equals( 3, count( $ois_concurrent_keys ), 'G2: three child keys were observed' );

foreach ( $ois_concurrent_keys as $ois_child_key ) {
	assert_true(
		0 === strpos( $ois_child_key, Conexao_Import_Log::TEST_OPTION_PREFIX ),
		'G2: every concurrent key is test-prefixed'
	);
}

assert_true( ! in_array( $ois_parent_key, $ois_concurrent_keys, true ), 'G2: no child shared the parent key' );

foreach ( $ois_concurrent_keys as $ois_child_key ) {
	assert_true( null === get_option( $ois_child_key, null ), 'G3: the child key was cleaned at its own shutdown' );
}

assert_true( ois_live_raw_map() === $ois_live_before, 'G4: concurrent children left every live operator option byte-identical' );

// ===========================================================================
// H — parent/child identity.
// ===========================================================================
test_section( 'H — a child never inherits the parent suffix' );

$ois_identity_child = ois_run_child(
	'require_once ' . var_export( $ois_bootstrap, true ) . ";\n"
	. 'require_once ' . var_export( $ois_plugin, true ) . ";\n"
	. "echo 'KEY=' . Conexao_Import_Log::option_key() . \"\\n\";\n"
	. "echo 'SUFFIX=' . CONEXAO_TEST_OPTION_SUFFIX . \"\\n\";\n"
);

$ois_identity_key    = ois_marker( $ois_identity_child['output'], 'KEY' );
$ois_identity_suffix = ois_marker( $ois_identity_child['output'], 'SUFFIX' );

assert_equals( 0, $ois_identity_child['code'], 'H1: the identity child exits 0' );
assert_true( '' !== $ois_identity_key, 'H1: the child reported its key' );
assert_true( $ois_identity_key !== $ois_parent_key, 'H1: the child key differs from the parent key' );
assert_true( $ois_identity_suffix !== $ois_suffix, 'H1: the child suffix differs from the parent suffix' );
assert_true( ! in_array( $ois_identity_key, $ois_concurrent_keys, true ), 'H1: the child key differs from every concurrent key' );

// ===========================================================================
// I — orphan classification: every test-prefixed row is test-prefixed only.
// ===========================================================================
test_section( 'I — orphaned test rows are classified, never mistaken for operator data' );

global $wpdb;

$ois_prefix_list = array(
	'import_log'      => Conexao_Import_Log::TEST_OPTION_PREFIX,
	'import_history'  => Conexao_Import_History::TEST_OPTION_PREFIX,
	'cleanup_status'  => Conexao_Event_Cleanup::STATUS_TEST_OPTION_PREFIX,
	'cleanup_history' => Conexao_Event_Cleanup::HISTORY_TEST_OPTION_PREFIX,
);

$ois_matches = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
		'%' . $wpdb->esc_like( '__test__' ) . '%'
	)
);

$ois_orphans  = array();
$ois_current  = array();
$ois_unclassifiable = array();

foreach ( (array) $ois_matches as $ois_option_name ) {
	$ois_classified = false;

	foreach ( $ois_prefix_list as $ois_store => $ois_prefix ) {
		if ( 0 !== strpos( $ois_option_name, $ois_prefix ) ) {
			continue;
		}

		$ois_classified = true;
		$ois_tail       = substr( $ois_option_name, strlen( $ois_prefix ) );

		if ( 1 !== preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', $ois_tail ) ) {
			$ois_unclassifiable[] = $ois_option_name;
			continue;
		}

		if ( $ois_tail === $ois_suffix ) {
			$ois_current[] = $ois_option_name;
		} else {
			$ois_orphans[] = $ois_option_name;
		}
	}

	if ( ! $ois_classified ) {
		$ois_unclassifiable[] = $ois_option_name;
	}
}

printf(
	"  INFO: %d option(s) match a test prefix — %d belong to this process, %d are orphans, %d are unclassifiable\n",
	count( (array) $ois_matches ),
	count( $ois_current ),
	count( $ois_orphans ),
	count( $ois_unclassifiable )
);

assert_equals( array(), $ois_unclassifiable, 'I1: every test-prefixed row matches a known prefix and a valid suffix' );

foreach ( (array) $ois_matches as $ois_option_name ) {
	foreach ( $ois_production_names as $ois_production_name ) {
		assert_true( $ois_option_name !== $ois_production_name, 'I2: no test-prefixed row is a production operator option' );
	}
}

foreach ( $ois_orphans as $ois_orphan ) {
	assert_true(
		0 === strpos( $ois_orphan, 'conexao_event_' ),
		'I3: every orphan is a conexao_event_* test row (' . $ois_orphan . ')'
	);
}

// ===========================================================================
// J — production-path smoke test.
//
// CLASS-LEVEL proof only: the real Conexao_Import_Log class is loaded WITHOUT
// WordPress and WITHOUT a database connection, with minimal function stubs. It
// proves that a non-test process resolves the PRODUCTION option name and that
// the logging path issues exactly one update_option() for it. It does NOT prove
// the live WordPress persistence path, and this suite does not claim that it
// does.
// ===========================================================================
test_section( 'J — production-path smoke test (class level, no WordPress)' );

$ois_smoke_prelude = "define( 'ABSPATH', '/' );\n"
	. "\$GLOBALS['ois_smoke_updates'] = array();\n"
	. "function current_time( \$type = 'mysql' ) { return '2026-11-14 00:00:00'; }\n"
	. "function sanitize_key( \$key ) { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) \$key ) ); }\n"
	. "function sanitize_text_field( \$value ) { return trim( strip_tags( (string) \$value ) ); }\n"
	. "function get_option( \$key, \$default = false ) { return \$default; }\n"
	. "function update_option( \$key, \$value, \$autoload = null ) { \$GLOBALS['ois_smoke_updates'][] = array( \$key, \$value, \$autoload ); return true; }\n";

$ois_smoke_body = 'require_once ' . var_export( $ois_log_file, true ) . ";\n"
	. "Conexao_Import_Log::add( 'ois_smoke', 'info', 'J: smoke entry', array( 'run_id' => 'ois_smoke_run', 'http_status' => 200 ) );\n"
	. "\$updates = \$GLOBALS['ois_smoke_updates'];\n"
	. "echo 'UPDATES=' . count( \$updates ) . \"\\n\";\n"
	. "echo 'KEY=' . ( isset( \$updates[0] ) ? \$updates[0][0] : '' ) . \"\\n\";\n"
	. "echo 'EXPECTED=' . Conexao_Import_Log::OPTION_KEY . \"\\n\";\n"
	. "echo 'AUTOLOAD=' . ( isset( \$updates[0] ) && false === \$updates[0][2] ? 'false' : 'other' ) . \"\\n\";\n"
	. "echo 'MESSAGE=' . ( isset( \$updates[0][1][0]['message'] ) ? \$updates[0][1][0]['message'] : '' ) . \"\\n\";\n"
	. "echo 'HARNESS=' . ( defined( 'CONEXAO_TESTS_ROOT' ) ? 'defined' : 'absent' ) . \"\\n\";\n"
	. "echo 'SUFFIX=' . ( defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ? 'defined' : 'absent' ) . \"\\n\";\n";

$ois_smoke = ois_run_child( $ois_smoke_prelude . $ois_smoke_body );

assert_equals( 0, $ois_smoke['code'], 'J1: the smoke child exits 0 (got: ' . trim( $ois_smoke['output'] ) . ')' );
assert_equals( '1', ois_marker( $ois_smoke['output'], 'UPDATES' ), 'J1: exactly one update_option() call is made' );
assert_equals( Conexao_Import_Log::OPTION_KEY, ois_marker( $ois_smoke['output'], 'KEY' ), 'J1: the PRODUCTION option key is selected' );
assert_equals( Conexao_Import_Log::OPTION_KEY, ois_marker( $ois_smoke['output'], 'EXPECTED' ), 'J1: the class still exposes the production constant' );
assert_true( false === strpos( ois_marker( $ois_smoke['output'], 'KEY' ), '__test__' ), 'J1: no test-prefixed key is selected' );
assert_equals( 'false', ois_marker( $ois_smoke['output'], 'AUTOLOAD' ), 'J1: the write keeps the documented autoload=false' );
assert_equals( 'J: smoke entry', ois_marker( $ois_smoke['output'], 'MESSAGE' ), 'J1: the real logging path stored the synthetic entry' );
assert_equals( 'absent', ois_marker( $ois_smoke['output'], 'HARNESS' ), 'J1: the child started without the harness marker' );
assert_equals( 'absent', ois_marker( $ois_smoke['output'], 'SUFFIX' ), 'J1: the child started without a test suffix' );

// Negative control: start the SAME child WITH the harness markers. It must
// resolve a test key, so "KEY == OPTION_KEY" would fail.
$ois_smoke_negative = ois_run_child(
	$ois_smoke_prelude
	. "define( 'CONEXAO_TESTS_ROOT', '/tmp' );\n"
	. "define( 'CONEXAO_TEST_OPTION_SUFFIX', 'p321_0123456789abcdef' );\n"
	. $ois_smoke_body
);

assert_equals( '1', ois_marker( $ois_smoke_negative['output'], 'UPDATES' ), 'J2: the harness child still issues one write' );
assert_true(
	0 === strpos( ois_marker( $ois_smoke_negative['output'], 'KEY' ), Conexao_Import_Log::TEST_OPTION_PREFIX ),
	'J2: with the harness markers the SAME code resolves a test-prefixed key'
);
assert_true(
	ois_marker( $ois_smoke_negative['output'], 'KEY' ) !== Conexao_Import_Log::OPTION_KEY,
	'J2: and it is NOT the production key (so the J1 assertion is meaningful)'
);

// Second negative control: the harness marker WITHOUT a suffix must throw.
$ois_smoke_marker_only = ois_run_child(
	$ois_smoke_prelude
	. "define( 'CONEXAO_TESTS_ROOT', '/tmp' );\n"
	. $ois_smoke_body
);

assert_true( 0 !== $ois_smoke_marker_only['code'], 'J3: the marker-only smoke child exits non-zero' );
assert_equals( '', ois_marker( $ois_smoke_marker_only['output'], 'KEY' ), 'J3: no key is reported when the suffix is missing' );
assert_true( false === strpos( $ois_smoke_marker_only['output'], 'UPDATES=1' ), 'J3: no option write is attempted' );

assert_true( ois_live_raw_map() === $ois_live_before, 'J4: the smoke children never touched the live WordPress database' );

// ===========================================================================
// K — the live operator options are byte-identical at the end.
// ===========================================================================
test_section( 'K — the live operator options stayed byte-identical' );

$ois_live_after = ois_live_raw_map();

foreach ( $ois_production_names as $ois_store => $ois_production_name ) {
	assert_true(
		$ois_live_after[ $ois_store ] === $ois_live_before[ $ois_store ],
		'K1: ' . $ois_production_name . ' is byte-identical after every test operation'
	);
	assert_true(
		$ois_production_name !== Conexao_Import_Log::option_key()
			&& $ois_production_name !== Conexao_Import_History::option_key()
			&& $ois_production_name !== Conexao_Event_Cleanup::status_option()
			&& $ois_production_name !== Conexao_Event_Cleanup::history_option(),
		'K2: ' . $ois_production_name . ' is not resolvable by any store accessor in this process'
	);
}

$ois_final_reset = test_options_reset_test_store(
	array( 'import_log', 'import_history', 'cleanup_status', 'cleanup_history' )
);

assert_true( $ois_final_reset['ok'], 'K3: the final reset of this process\'s test stores succeeds' );
assert_equals( array(), $ois_final_reset['rejected'], 'K3: the final reset rejected nothing' );

foreach ( $ois_resolved_keys as $ois_store => $ois_test_key ) {
	assert_equals( '', ois_raw_option( $ois_test_key ), 'K3: ' . $ois_store . ' test row is gone after the final reset' );
}

assert_true( ois_live_raw_map() === $ois_live_before, 'K4: the final reset left every live operator option byte-identical' );

test_finish( 'Operator Log Isolation' );










