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
 * Three facts are recorded per option, because "restore verbatim" has to mean
 * the option as it was: whether it existed, its VALUE, and whether it was
 * autoloaded. The third one matters — an option that is deliberately autoloaded
 * (read on every request) must not come back as a non-autoloaded one, or a
 * documented performance property would be silently changed by running a test.
 *
 * @param string[] $option_names Option names to isolate.
 * @return array Restore token to pass to test_options_restore().
 */
function test_options_isolate( array $option_names ) {
	$snapshots = array();

	foreach ( $option_names as $option_name ) {
		$snapshots[ $option_name ] = array(
			'existed'  => false,
			'value'    => null,
			'autoload' => false,
		);

		// get_option() cannot distinguish "absent" from "stored as false", so
		// ask the database directly for existence.
		//
		// `wp_load_alloptions()` returns each value in its RAW storage form —
		// a serialised string for anything that is not a scalar — while
		// `get_option()` applies `maybe_unserialize()` before returning. The
		// snapshot only ever held the raw form, so restoring it verbatim wrote
		// the SERIALISED STRING back through `update_option()`, which
		// serialised it a second time. The option then read back as a string
		// rather than the array it was, and every consumer saw a malformed
		// value.
		//
		// Measured: an autoloaded `array( 'a' => 1 )` came back from
		// `wp_load_alloptions()` as `'a:1:{s:1:"a";i:1;}'`, and writing that
		// back left the option holding `s:19:"a:1:{s:1:"a";i:1;}";`.
		//
		// Only AUTOLOADED options were affected (a non-autoloaded option is not
		// in `alloptions` and fell through to `get_option()` below), which is
		// why the defect stayed hidden until an autoloaded option was
		// isolated.
		//
		// Membership of `alloptions` is ALSO the autoload flag, which is why it
		// is recorded here and restored below.
		$all_options = wp_load_alloptions();
		if ( array_key_exists( $option_name, $all_options ) ) {
			$snapshots[ $option_name ]['existed']  = true;
			$snapshots[ $option_name ]['value']    = maybe_unserialize( $all_options[ $option_name ] );
			$snapshots[ $option_name ]['autoload'] = true;
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
			// Restore the VALUE and its autoload flag: an isolated option must
			// come back as the option it was, not merely with the same value.
			// Absent flag (older callers) means "restore as non-autoloaded",
			// which is what every option in this repository uses except the
			// one that documents otherwise.
			$autoload = ! empty( $snapshot['autoload'] );

			update_option( $option_name, $snapshot['value'], $autoload ? 'yes' : 'no' );

			/*
			 * `update_option()` short-circuits when the new value equals the
			 * stored one, and in that path it does NOT necessarily update the
			 * autoload flag — measured on this install:
			 *
			 *     update_option( $key, array(), true )   -> autoload stays 'off'
			 *     wp_set_option_autoload( $key, true )   -> autoload 'on'
			 *
			 * The value-equality case is the COMMON one (a suite that seeds an
			 * empty store and restores an empty store), so without this the
			 * seed's own `autoload => false` would win and an option documented
			 * as autoloaded would silently stop being autoloaded after any test
			 * run. `wp_set_option_autoload()` exists from WordPress 6.6; on an
			 * older install the flag is left as `update_option()` set it.
			 */
			if ( $autoload && function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( $option_name, true );
			}
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
