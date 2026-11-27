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
 * The four protected operator stores — the single allowlist.
 *
 * Each entry names the owning class, the accessor that resolves the option name
 * for the current process, and the constants holding the production name and
 * the test prefix. Nothing else in the harness enumerates these stores.
 *
 * @return array<string,array{class:string,method:string,production:string,prefix:string}>
 */
function test_operator_store_registry() {
	return array(
		'import_log'      => array(
			'class'      => 'Conexao_Import_Log',
			'method'     => 'option_key',
			'production' => 'OPTION_KEY',
			'prefix'     => 'TEST_OPTION_PREFIX',
		),
		'import_history'  => array(
			'class'      => 'Conexao_Import_History',
			'method'     => 'option_key',
			'production' => 'OPTION_KEY',
			'prefix'     => 'TEST_OPTION_PREFIX',
		),
		'cleanup_status'  => array(
			'class'      => 'Conexao_Event_Cleanup',
			'method'     => 'status_option',
			'production' => 'STATUS_OPTION',
			'prefix'     => 'STATUS_TEST_OPTION_PREFIX',
		),
		'cleanup_history' => array(
			'class'      => 'Conexao_Event_Cleanup',
			'method'     => 'history_option',
			'production' => 'HISTORY_OPTION',
			'prefix'     => 'HISTORY_TEST_OPTION_PREFIX',
		),
	);
}

/**
 * The validated per-process suffix, or '' when the harness did not set one.
 *
 * @return string
 */
function test_operator_store_suffix() {
	if ( ! defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
		return '';
	}

	$suffix = strtolower( (string) CONEXAO_TEST_OPTION_SUFFIX );

	return preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', $suffix ) ? $suffix : '';
}

/**
 * Resolve an operator-store request WITHOUT side effects.
 *
 * Accepted requests, in evaluation order:
 *
 *   1. a symbolic store key from test_operator_store_registry() ('import_log', …);
 *   2. this process's own test key for one of the four stores;
 *   3. nothing else.
 *
 * Rejected requests, each with a closed-vocabulary reason:
 *
 *   invalid_type       — not a string
 *   empty              — an empty string
 *   store_unavailable  — the owning class is not loaded, so it cannot be resolved
 *   production_key     — the production option name (literal or constant value)
 *   malformed          — a test prefix with no valid suffix
 *   foreign_process    — another process's test key
 *   arbitrary_option   — any other option name (e.g. blogname) or unknown store key
 *
 * Never deletes, never writes, never exits.
 *
 * @param mixed $request Symbolic store key or option name.
 * @return array{ok:bool,store:string,key:string,reason:string}
 */
function test_operator_store_resolve( $request ) {
	$result = array(
		'ok'     => false,
		'store'  => '',
		'key'    => '',
		'reason' => 'invalid_type',
	);

	if ( ! is_string( $request ) ) {
		return $result;
	}

	$request = trim( $request );

	if ( '' === $request ) {
		$result['reason'] = 'empty';
		return $result;
	}

	$registry = test_operator_store_registry();
	$suffix   = test_operator_store_suffix();

	if ( isset( $registry[ $request ] ) ) {
		$store           = $registry[ $request ];
		$result['store'] = $request;

		if ( ! class_exists( $store['class'] ) ) {
			$result['reason'] = 'store_unavailable';
			return $result;
		}

		try {
			$key = call_user_func( array( $store['class'], $store['method'] ) );
		} catch ( Throwable $e ) {
			$result['reason'] = 'store_unavailable';
			return $result;
		}

		$prefix     = defined( $store['class'] . '::' . $store['prefix'] ) ? constant( $store['class'] . '::' . $store['prefix'] ) : '';
		$production = defined( $store['class'] . '::' . $store['production'] ) ? constant( $store['class'] . '::' . $store['production'] ) : '';

		$result['key'] = is_string( $key ) ? $key : '';

		if ( '' === $suffix || '' === $prefix || '' === $production || $key !== $prefix . $suffix ) {
			$result['reason'] = 'malformed';
			return $result;
		}

		$result['ok']     = true;
		$result['reason'] = 'ok';
		return $result;
	}

	// A literal option name. Build the production/prefix tables once.
	$productions = array();
	$prefixes    = array();

	foreach ( $registry as $store_key => $store ) {
		if ( defined( $store['class'] . '::' . $store['production'] ) ) {
			$productions[ constant( $store['class'] . '::' . $store['production'] ) ] = $store_key;
		}
		if ( defined( $store['class'] . '::' . $store['prefix'] ) ) {
			$prefixes[ constant( $store['class'] . '::' . $store['prefix'] ) ] = $store_key;
		}
	}

	if ( isset( $productions[ $request ] ) ) {
		$result['store']  = $productions[ $request ];
		$result['reason'] = 'production_key';
		return $result;
	}

	foreach ( $prefixes as $prefix => $store_key ) {
		if ( $request === $prefix ) {
			$result['store']  = $store_key;
			$result['reason'] = 'malformed';
			return $result;
		}

		if ( 0 !== strpos( $request, $prefix ) ) {
			continue;
		}

		$result['store']  = $store_key;
		$result['key']    = $request;
		$candidate_suffix = substr( $request, strlen( $prefix ) );

		if ( '' !== $suffix && $candidate_suffix === $suffix ) {
			$result['ok']     = true;
			$result['reason'] = 'ok';
			return $result;
		}

		$result['reason'] = 'foreign_process';
		return $result;
	}

	$result['reason'] = 'arbitrary_option';
	return $result;
}

/**
 * Resolve a symbolic store key to this process's test option name, or exit.
 *
 * Wraps test_operator_store_resolve() with test_require(), so an unknown store
 * or a production key is a hard failure and never a silent write.
 *
 * @param string $store Symbolic store key ('import_log', 'import_history',
 *                      'cleanup_status', 'cleanup_history').
 * @return string Test option name.
 */
function test_operator_store_option_name( $store ) {
	test_prerequisite_hint( 'role:isolated-test-store' );

	$resolved = test_operator_store_resolve( $store );

	test_require(
		$resolved['ok'],
		'role:isolated-test-store',
		'the current process has its own isolated test store for "' . (string) $store . '"',
		'load tests/bootstrap.php (which establishes the per-process suffix) before touching an operator store'
	);

	return $resolved['key'];
}

/**
 * Write a value to this process's test store for a symbolic store key.
 *
 * This is the ONLY sanctioned way for a suite to seed one of the four operator
 * stores. The key is resolved and validated first, so a caller cannot aim it at
 * the production option.
 *
 * @param string $store Symbolic store key.
 * @param mixed  $value Value to store.
 * @return string The test option name that was written.
 */
function test_options_write_test_store( $store, $value ) {
	$key = test_operator_store_option_name( $store );

	update_option( $key, $value, false );

	return $key;
}

/**
 * Delete this process's test stores for the requested keys — and nothing else.
 *
 * Validate everything, then delete. A request that contains ONE rejected entry
 * deletes NOTHING, so a mixed valid/invalid call cannot partially succeed.
 *
 * @param array $requests Symbolic store keys and/or this process's test option names.
 * @return array{ok:bool,deleted:string[],absent:string[],rejected:array<int,array{key:string,reason:string}>}
 */
function test_options_reset_test_store( array $requests ) {
	$result = array(
		'ok'       => false,
		'deleted'  => array(),
		'absent'   => array(),
		'rejected' => array(),
	);

	if ( empty( $requests ) ) {
		$result['rejected'][] = array(
			'key'    => '',
			'reason' => 'empty',
		);
		return $result;
	}

	$keys = array();
	$seen = array();

	foreach ( $requests as $request ) {
		$resolved = test_operator_store_resolve( $request );

		if ( ! $resolved['ok'] ) {
			$result['rejected'][] = array(
				'key'    => is_string( $request ) ? $request : '',
				'reason' => $resolved['reason'],
			);
			continue;
		}

		if ( isset( $seen[ $resolved['key'] ] ) ) {
			$result['rejected'][] = array(
				'key'    => $resolved['key'],
				'reason' => 'duplicate',
			);
			continue;
		}

		$seen[ $resolved['key'] ] = true;
		$keys[]                   = $resolved['key'];
	}

	// Validate-all-then-delete: one rejection means zero deletions.
	if ( ! empty( $result['rejected'] ) ) {
		return $result;
	}

	foreach ( $keys as $key ) {
		if ( delete_option( $key ) ) {
			$result['deleted'][] = $key;
		} else {
			$result['absent'][] = $key;
		}
	}

	$result['ok'] = true;

	return $result;
}

/**
 * Secondary safeguard called at the top of every store-touching suite.
 *
 * The bootstrap already establishes and verifies isolation before a suite body
 * runs; this proves the property again from inside the suite, so an entry point
 * that bypasses the bootstrap cannot fail silently.
 *
 * @return void
 */
function test_require_operator_store_isolation() {
	test_prerequisite_hint( 'role:isolated-test-store' );

	test_require(
		defined( 'CONEXAO_TESTS_ROOT' ),
		'role:isolated-test-store',
		'the shared test harness is loaded (CONEXAO_TESTS_ROOT is defined)',
		'require tests/bootstrap.php at the top of the suite'
	);

	$suffix = test_operator_store_suffix();

	test_require(
		defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) && '' !== $suffix,
		'role:isolated-test-store',
		'the per-process isolation suffix is defined and valid',
		'load tests/bootstrap.php (which establishes the suffix before wp-load.php)'
	);

	foreach ( test_operator_store_registry() as $store_key => $store ) {
		test_require(
			class_exists( $store['class'] ),
			'role:isolated-test-store',
			'the operator store class ' . $store['class'] . ' is loaded',
			'activate the conexao-event-importer plugin'
		);

		$resolved = test_operator_store_resolve( $store_key );

		test_require(
			$resolved['ok'] && '' !== $resolved['key'],
			'role:isolated-test-store',
			'the "' . $store_key . '" store resolves to this process\'s own test option',
			'load tests/bootstrap.php before touching an operator store'
		);

		$production = defined( $store['class'] . '::' . $store['production'] )
			? constant( $store['class'] . '::' . $store['production'] )
			: '';

		$prefix = defined( $store['class'] . '::' . $store['prefix'] )
			? constant( $store['class'] . '::' . $store['prefix'] )
			: '';

		test_require(
			'' !== $prefix
				&& 0 === strpos( $resolved['key'], $prefix )
				&& substr( $resolved['key'], -strlen( $suffix ) ) === $suffix
				&& $resolved['key'] !== $production,
			'role:isolated-test-store',
			'the "' . $store_key . '" store is test-prefixed, carries the current suffix and is not the production option',
			'load tests/bootstrap.php before touching an operator store'
		);
	}
}

/**
 * Whether a name resolves to one of the four protected operator stores.
 *
 * Used by test_options_isolate()/test_options_restore() to REFUSE the stores
 * rather than snapshot them: after the storage seam a snapshot of a test store
 * is meaningless, and a snapshot of a production store is the original defect.
 *
 * @param mixed $option_name Option name.
 * @return string '' when the name is not protected, else the rejection reason.
 */
function test_operator_store_protection_reason( $option_name ) {
	$resolved = test_operator_store_resolve( $option_name );

	if ( 'ok' === $resolved['reason'] ) {
		return 'isolated_test_store';
	}

	if ( in_array( $resolved['reason'], array( 'production_key', 'malformed', 'foreign_process' ), true ) ) {
		return $resolved['reason'];
	}

	return '';
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
	/*
	 * Refuse the four protected operator stores BEFORE any snapshot or seed.
	 *
	 * They are redirected to a per-process test row by their accessors, so
	 * snapshotting them would snapshot the PRODUCTION row and seed it empty —
	 * the exact defect this module exists to prevent. The check runs before
	 * wp_load_alloptions() and before the first update_option().
	 */
	foreach ( $option_names as $option_name ) {
		$protection = test_operator_store_protection_reason( $option_name );

		if ( '' === $protection ) {
			continue;
		}

		fwrite(
			STDERR,
			sprintf(
				"conexao: test_options_isolate() refuses the protected operator store %s (%s). "
				. "These stores are redirected to a per-process test option by their accessors; "
				. "use test_options_write_test_store() and test_options_reset_test_store().\n",
				is_string( $option_name ) ? $option_name : '(non-string)',
				$protection
			)
		);
		exit( 1 );
	}

	$snapshots = array();

	// Load all autoloaded options ONCE, outside the per-option loop. Calling
	// wp_load_alloptions() once per option name (as the old code did) means a
	// suite isolating N options pays N full options-table scans. A single load
	// up front is the same data for every option and is what makes isolation
	// cheap even when many options are named.
	$all_options = wp_load_alloptions();

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
		/*
		 * Defence in depth: a stale external caller must never be able to write
		 * a snapshot back over a protected operator store. The key is skipped
		 * BEFORE update_option()/delete_option() is reached.
		 */
		$protection = test_operator_store_protection_reason( $option_name );

		if ( '' !== $protection ) {
			if ( ! $silent ) {
				printf( "  INFO: refusing to restore protected operator store %s (%s)\n", $option_name, $protection );
			}
			continue;
		}

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
