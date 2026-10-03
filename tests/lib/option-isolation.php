<?php
/**
 * Option isolation for the Stage E test harness.
 *
 * ## Why this exists
 *
 * Several WordPress options in this repository are OPERATOR STATE, not test
 * fixtures: they are the record a maintainer reads to find out what an import
 * actually did. `conexao_event_import_log` is the clearest case — the Event
 * Import Reliability Investigation measured it going 599 -> 91 -> 0 purely
 * because the canonical test suite (`test-import-log.php`) called
 * `Conexao_Import_Log::clear()` against the live option.
 *
 * A test that destroys operator diagnostics is a defect, not a side effect.
 *
 * ## The contract
 *
 *   $restore = test_options_isolate( array( 'conexao_event_import_log' ) );
 *   ... exercise the code under test freely ...
 *   test_options_restore( $restore );
 *
 * `test_options_isolate()` snapshots the CURRENT value of each named option
 * (including "this option does not exist"), seeds each one empty so the test
 * starts from a known state, and registers a shutdown safety net.
 * `test_options_restore()` puts the snapshots back verbatim.
 *
 * If a suite exits without calling restore() — a fatal, an `exit()` in the
 * middle of an assertion — the shutdown hook still restores, so a crashed test
 * can never leave the operator's history destroyed.
 *
 * @package Conexao_BR_Test_Harness
 */

if ( ! defined( 'CONEXAO_TESTS_ROOT' ) ) {
	fwrite( STDERR, "conexao: tests/lib/option-isolation.php loaded outside tests/bootstrap.php\n" );
	exit( 1 );
}

/**
 * Snapshot and clear the named options, returning a restore token.
 *
 * @param string[] $option_names Option names to isolate.
 * @return array Restore token to pass to test_options_restore().
 */
function test_options_isolate( array $option_names ) {
	$snapshots = array();

	foreach ( $option_names as $option_name ) {
		$snapshots[ $option_name ] = array(
			'existed' => false,
			'value'   => null,
		);

		// get_option() cannot distinguish "absent" from "stored as false", so
		// ask the database directly for existence.
		$all_options = wp_load_alloptions();
		if ( array_key_exists( $option_name, $all_options ) ) {
			$snapshots[ $option_name ]['existed'] = true;
			$snapshots[ $option_name ]['value']   = $all_options[ $option_name ];
		} else {
			$snapshots[ $option_name ]['value'] = get_option( $option_name, null );
			$snapshots[ $option_name ]['existed'] = ( null !== $snapshots[ $option_name ]['value'] );
		}

		// Seed an isolated, empty store for the test.
		update_option( $option_name, array(), false );
	}

	$GLOBALS['conexao_test_option_snapshots'][] = $snapshots;

	// Safety net: a suite that dies before restoring still cannot destroy the
	// operator's state.
	$restore_all = static function () {
		foreach ( (array) $GLOBALS['conexao_test_option_snapshots'] as $pending ) {
			test_options_restore( $pending, true );
		}
		$GLOBALS['conexao_test_option_snapshots'] = array();
	};
	register_shutdown_function( $restore_all );

	return $snapshots;
}

/**
 * Restore options previously isolated by test_options_isolate().
 *
 * @param array $snapshots Restore token.
 * @param bool  $silent    Suppress output when called from the shutdown net.
 * @return void
 */
function test_options_restore( $snapshots, $silent = false ) {
	if ( ! is_array( $snapshots ) ) {
		return;
	}

	foreach ( $snapshots as $option_name => $snapshot ) {
		if ( ! empty( $snapshot['existed'] ) ) {
			update_option( $option_name, $snapshot['value'], false );
		} else {
			// The option did not exist before the test: remove the one the
			// test created, rather than leaving an empty row behind.
			delete_option( $option_name );
		}

		if ( ! $silent ) {
			$restored = ! empty( $snapshot['existed'] )
				? 'restored'
				: 'removed (absent before the test)';
			printf( "  INFO: option %s %s\n", $option_name, $restored );
		}
	}
}
