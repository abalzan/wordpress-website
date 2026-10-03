<?php
/**
 * Shared contract for the Stage L permanent invariant gates.
 *
 * Engineering standard §6.3 (permanent invariant tests) and §8.2 (permanent
 * content invariants run in the default suite). Stage L turns those rules into
 * standing, fail-closed gates. This file is the ONE place that implements the
 * gate contract, so every gate reports the same machine-readable shape and the
 * aggregate (scripts/verify-permanent-gates.py) never has to parse prose.
 *
 * ## The contract
 *
 * A gate is a normal in-process PHP suite that requires tests/bootstrap.php and
 * uses tests/lib/assertions.php. On top of that it:
 *
 *   1. declares its identity and its data prerequisites (`conexao_gate_open()`),
 *   2. records named VIOLATIONS, not just booleans (`conexao_gate_violation()`),
 *   3. classifies each violation against the recorded Stage L baseline
 *      (`conexao_gate_close()`),
 *   4. prints a deterministic `N passed, M failed` summary PLUS one
 *      `GATE-RESULT {...}` JSON line for the aggregate, and
 *   5. exits non-zero when any assertion OR any violation failed.
 *
 * ## Baseline classification is REPORTING ONLY
 *
 * `tests/baseline/permanent-gates.json` records the violations that already
 * existed when Stage L started. Its ONLY purpose is to let the gate and the
 * report say "this is pre-existing repository/data debt" instead of "this is a
 * Stage L regression".
 *
 * A baseline entry NEVER suppresses a failure. A violation that matches the
 * baseline still fails, still prints FAIL and still exits non-zero, exactly
 * like a new one. That is deliberate: engineering standard §6.3 requires the
 * gate to be blocking, and the Stage L rules forbid converting a blocking
 * invariant into a warning or buying a green CI run with an allowlist.
 *
 * @package Conexao_BR_Test_Harness
 */

if ( ! defined( 'CONEXAO_TESTS_ROOT' ) ) {
	fwrite( STDERR, "conexao: tests/lib/permanent-gates.php loaded outside tests/bootstrap.php\n" );
	exit( 1 );
}

/**
 * Current gate state.
 *
 * @var array
 */
$GLOBALS['conexao_gate'] = array(
	'id'          => '',
	'title'       => '',
	'started'     => false,
	'violations'  => array(),
	'prereqs'     => array(),
	'allowlisted' => array(),
);

/**
 * Begin a permanent gate.
 *
 * Declares the gate identity and its data prerequisites BEFORE any work, so a
 * missing prerequisite is reported as a prerequisite failure rather than
 * silently producing a zero-violation pass (engineering standard §8.2: fail
 * loudly, never pass vacuously).
 *
 * @param string   $id            Stable gate id, e.g. `taxonomy_policy`.
 * @param string   $title         Human title printed as a section header.
 * @param string[] $prerequisites Data prerequisites this gate needs.
 * @return void
 */
function conexao_gate_open( $id, $title, array $prerequisites = array() ) {
	$GLOBALS['conexao_gate']['id']          = (string) $id;
	$GLOBALS['conexao_gate']['title']       = (string) $title;
	$GLOBALS['conexao_gate']['started']     = true;
	$GLOBALS['conexao_gate']['prereqs']     = array_values( $prerequisites );
	$GLOBALS['conexao_gate']['violations']  = array();
	$GLOBALS['conexao_gate']['allowlisted'] = array();

	echo "\n== PERMANENT GATE: {$id} ==\n";
	echo $title . "\n";
	if ( ! empty( $prerequisites ) ) {
		echo '  prerequisites: ' . implode( ', ', $prerequisites ) . "\n";
	}
}

/**
 * Record a data prerequisite for this gate.
 *
 * Mirrors the Stage E `test_require()` semantics: a missing prerequisite is a
 * FAILURE with an `insufficient data:` line, never a vacuous pass.
 *
 * @param bool   $condition Whether the prerequisite is satisfied.
 * @param string $hint      Setup hint.
 * @param string $message   Assertion label.
 * @param string $detail    Optional setup instruction.
 * @return void
 */
function conexao_gate_require( $condition, $hint, $message, $detail = '' ) {
	test_require( $condition, $hint, $message, $detail );
}

/**
 * Record a NAMED violation of the invariant this gate enforces.
 *
 * A violation is a countable, named, stable-keyed finding
 * (`taxonomy:conexao_town:suffixed`, `post_type:guide:missing_en`, ...). Named
 * keys are what make the baseline classification and the `gate.json` aggregate
 * meaningful: two runs are comparable because they report the same ids.
 *
 * The violation ALWAYS fails. There is no severity, no warning level and no
 * suppression path.
 *
 * @param string $key    Stable violation key (must survive across runs).
 * @param int    $count  How many occurrences.
 * @param string $label  Human description of the invariant broken.
 * @param array  $detail Optional structured detail (samples, ids, ...).
 * @return void
 */
function conexao_gate_violation( $key, $count, $label, array $detail = array() ) {
	$count = (int) $count;

	$GLOBALS['conexao_gate']['violations'][ (string) $key ] = array(
		'key'    => (string) $key,
		'count'  => $count,
		'label'  => (string) $label,
		'detail' => $detail,
	);

	// A zero-count recording is still asserted (it proves the check RAN over a
	// real population). A non-zero count is the failure.
	assert_true(
		0 === $count,
		$label,
		$count . ' violation(s)' . ( ! empty( $detail ) ? ': ' . wp_json_encode( $detail ) : '' )
	);
}

/**
 * Record an explicitly allowlisted / policy-exempt record.
 *
 * Every entry MUST be reported (Stage L rule 18). Allowlisting is only legal
 * where the engineering standard permits it and the allowlist corresponds to a
 * documented intentional policy — in this repository that is the B2 fallback
 * policy (conexao_b2_post_types() / conexao_b2_page_allowlist()) and the
 * documented pre-existing editorial fixtures.

/**
 * Record an explicitly allowlisted / policy-exempt record.
 *
 * Every entry MUST be reported (Stage L rule 18). Allowlisting is only legal
 * where the engineering standard permits it and the allowlist corresponds to a
 * documented intentional policy — in this repository that is the B2 fallback
 * policy (conexao_b2_post_types() / conexao_b2_page_allowlist()) and the
 * documented pre-existing editorial fixtures.
 *
 * @param string $key   Stable allowlist key.
 * @param string $label Human description of the policy that exempts it.
 * @return void
 */
function conexao_gate_allowlisted( $key, $label ) {
	$GLOBALS['conexao_gate']['allowlisted'][ (string) $key ] = array(
		'key'   => (string) $key,
		'label' => (string) $label,
	);
}

/**
 * Load the recorded Stage L baseline, if present.
 *
 * The baseline is documentation of pre-existing debt, never a suppression
 * list. A missing baseline file is not an error: every violation is then simply
 * classified as `new` (the conservative direction).
 *
 * @return array Map of gate id => list of baseline violation keys.
 */
function conexao_gate_baseline() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$path  = CONEXAO_TESTS_ROOT . '/baseline/permanent-gates.json';
	$cache = array();

	if ( ! is_readable( $path ) ) {
		return $cache;
	}

	$decoded = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $decoded ) || ! isset( $decoded['gates'] ) || ! is_array( $decoded['gates'] ) ) {
		return $cache;
	}

	foreach ( $decoded['gates'] as $gate_id => $keys ) {
		$cache[ (string) $gate_id ] = is_array( $keys ) ? array_map( 'strval', $keys ) : array();
	}

	return $cache;
}

/**
 * Classify this gate's violations against the recorded baseline.
 *
 * @return array {
 *     @type array $violations   Every recorded violation.
 *     @type int   $pre_existing Violations whose key is in the baseline.
 *     @type int   $new          Violations NOT in the baseline.
 *     @type int   $total        Sum of violation counts.
 * }
 */
function conexao_gate_classify() {
	$baseline = conexao_gate_baseline();
	$id       = $GLOBALS['conexao_gate']['id'];
	$known    = isset( $baseline[ $id ] ) ? $baseline[ $id ] : array();

	$pre_existing = 0;
	$new          = 0;
	$total        = 0;

	foreach ( $GLOBALS['conexao_gate']['violations'] as $key => $violation ) {
		$count = (int) $violation['count'];
		$total += $count;

		if ( $count > 0 && in_array( (string) $key, $known, true ) ) {
			$pre_existing += $count;
		} elseif ( $count > 0 ) {
			$new += $count;
		}
	}

	return array(
		'violations'   => array_values( $GLOBALS['conexao_gate']['violations'] ),
		'pre_existing' => $pre_existing,
		'new'          => $new,
		'total'        => $total,
	);
}

/**
 * Finish a permanent gate: print the summary, emit `GATE-RESULT` and exit.
 *
 * The exit status is decided in exactly one place, so a gate can never print a
 * green summary and exit zero. `GATE-RESULT` is the single line the Stage L
 * aggregate parses to build `gate.json` from REAL execution output.
 *
 * @return void
 */
function conexao_gate_close() {
	if ( ! $GLOBALS['conexao_gate']['started'] ) {
		fwrite( STDERR, "conexao-gate: close() called without open()\n" );
		exit( 1 );
	}

	$class = conexao_gate_classify();

	// A B2 population can legitimately be thousands of records. The COUNT is
	// the reported fact (Stage L rule 18: every allowlisted category must be
	// reported); the full list would drown the summary and the gate.json, so
	// only a deterministic sample is printed and the total is always shown.
	$allowlisted_all   = array();
	foreach ( $GLOBALS['conexao_gate']['allowlisted'] as $entry ) {
		$allowlisted_all[] = $entry['key'];
	}
	$allowlisted_count = count( $allowlisted_all );
	$allowlisted       = array_slice( $allowlisted_all, 0, 20 );

	$status = ( (int) $GLOBALS['failed'] > 0 || $class['total'] > 0 ) ? 'fail' : 'pass';

	// Human summary — the same `N passed, M failed` line every suite prints.
	$passed = (int) $GLOBALS['passed'];
	$failed = (int) $GLOBALS['failed'];
	echo "\n" . $passed . ' passed, ' . $failed . " failed\n";

	if ( $class['total'] > 0 ) {
		echo '  violations: ' . $class['total']
			. ' (' . $class['pre_existing'] . ' pre-existing, ' . $class['new'] . " new)\n";
		foreach ( $class['violations'] as $violation ) {
			if ( $violation['count'] > 0 ) {
				printf(
					"    - %s: %d (%s)\n",
					$violation['key'],
					(int) $violation['count'],
					$violation['label']
				);
			}
		}
	}

	if ( $allowlisted_count > 0 ) {
		echo '  allowlisted: ' . $allowlisted_count . " (by documented policy)\n";
		if ( $allowlisted_count > count( $allowlisted ) ) {
			echo '    first ' . count( $allowlisted ) . ': ' . implode( ', ', $allowlisted ) . "\n";
		} else {
			echo '    ' . implode( ', ', $allowlisted ) . "\n";
		}
	}

	// Machine-readable line for the Stage L aggregate.
	$payload = array(
		'gate'           => $GLOBALS['conexao_gate']['id'],
		'status'         => $status,
		'passed'         => $passed,
		'failed'         => $failed,
		'violations'     => $class['total'],
		'pre_existing'   => $class['pre_existing'],
		'new'            => $class['new'],
		'prerequisites'  => $GLOBALS['conexao_gate']['prereqs'],
		'allowlisted'    => $allowlisted,
		'allowlisted_count' => $allowlisted_count,
		'details'        => $class['violations'],
	);
	echo 'GATE-RESULT ' . wp_json_encode( $payload ) . "\n";

	exit( $failed > 0 || $class['total'] > 0 ? 1 : 0 );
}
