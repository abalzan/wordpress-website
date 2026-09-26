<?php
/**
 * Shared assertion library for the Stage E test harness.
 *
 * This is the SINGLE assertion API for every maintained in-process PHP suite
 * (`tests/lib/assertions.php`, engineering standard §8.2). Suites must not
 * define their own assertion helpers; the pre-Stage-E suite-local helpers
 * (`t_assert`, `test_assert`, `ts_assert`, `s5_assert`, `s7_assert`, `s8_assert`,
 * `s9_assert`, `s32_assert`, `s33_assert`, `s41_assert`, `s45_assert`,
 * `s7m_assert`, `id_assert`, `uuid_assert`, `gate_assert`, `mp_test_assert`,
 * `xl_assert`, `check`, `ok`, `lc_ok`) were all mapped onto this API during the
 * Stage E mechanical migration.
 *
 * ## Historical semantics that are deliberately preserved
 *
 * Every pre-Stage-E suite-local helper had the SAME core behaviour, and this
 * library keeps it bit-for-bit:
 *
 *   - a boolean condition (truthy/falsy, NOT strict) decides pass or fail;
 *   - the pass counter increments on pass, the fail counter on failure;
 *   - output is `  PASS: <message>` / `  FAIL: <message>`;
 *   - a suite exits non-zero when the failure count is above zero.
 *
 * Variants found in the legacy suites and how they are handled:
 *
 *   - `t_strict($expected, $actual, $message)` and `t2_strict(...)` appended
 *     ` — expected X, got Y` to the message on failure. That is exactly what
 *     `assert_equals()` does, so those call sites map 1:1 onto it.
 *   - The `$detail` third argument of the `s5/s6/s7/s7m/s8/s9`-family helpers
 *     and of `ok()/lc_ok()` is the same "extra failure context" affordance, so
 *     `assert_true()` accepts an optional third `$detail` argument.
 *   - `mp_test_assert()` in `test-mondello-park-importer.php` additionally
 *     validated its OWN arguments (the condition had to be a real bool and the
 *     message a non-empty string) and counted such misuse as a harness error
 *     *and* a failure. That guard is a property of that one suite's contract,
 *     not of assertion itself, so it is preserved as a suite-local
 *     precondition check rather than smuggled into the shared API. See the
 *     Stage E report (PHASE 11/12) for the full mapping table.
 *   - `check(string $label, bool $ok): void` had the arguments the other way
 *     round and printed `  PASS  <label>` (two spaces, no colon). Message
 *     formatting differs; the pass/fail decision does not. Migrated call sites
 *     were reordered to `assert_true( $ok, $label )`.
 *   - `lc_ok()` counted into a single `$fail` counter (no pass counter) and
 *     printed `[ OK ]`/`[FAIL]`. `test-reappearing-event-lifecycle.php` was the
 *     only user; it now counts both directions through the shared counters so
 *     it emits the required `N passed, M failed` summary.
 *
 * ## Strictness is NOT changed during migration
 *
 * `assert_true()` is intentionally TRUTHY (`(bool) $condition`), because that
 * is what the legacy helpers did; switching to a strict `true === $condition`
 * would have silently changed the meaning of every existing assertion.
 * `assert_equals()` defaults to LOOSE equality for the same reason: legacy
 * call sites compared loosely. Suites that need strictness already expressed it
 * at the call site (`$expected === $actual` passed as the condition), and those
 * call sites are left untouched.
 *
 * @package Conexao_BR_Test_Harness
 */

if ( ! defined( 'CONEXAO_TESTS_ROOT' ) ) {
	fwrite( STDERR, "conexao: tests/lib/assertions.php loaded outside tests/bootstrap.php\n" );
	exit( 1 );
}

/**
 * Current suite assertion counters.
 *
 * Initialised as globals so the pre-Stage-E suites that read `$passed` /
 * `$failed` directly (for their own summary line) keep working unchanged while
 * being migrated to `test_finish()`.
 *
 * @var int $passed
 * @var int $failed
 */
$GLOBALS['passed'] = isset( $GLOBALS['passed'] ) ? (int) $GLOBALS['passed'] : 0;
$GLOBALS['failed'] = isset( $GLOBALS['failed'] ) ? (int) $GLOBALS['failed'] : 0;

/**
 * Setup hint reported when a data prerequisite is missing (PHASE 76).
 *
 * @var string
 */
$GLOBALS['conexao_test_prerequisite'] = isset( $GLOBALS['conexao_test_prerequisite'] )
	? (string) $GLOBALS['conexao_test_prerequisite']
	: '';

/**
 * Whether any prerequisite failure has been recorded in this suite.
 *
 * @var bool
 */
$GLOBALS['conexao_test_prereq_failed'] = isset( $GLOBALS['conexao_test_prereq_failed'] )
	? (bool) $GLOBALS['conexao_test_prereq_failed']
	: false;

/**
 * Render a value compactly for a failure message.
 *
 * @param mixed $value Value to describe.
 * @return string
 */
function conexao_test_describe( $value ) {
	if ( is_string( $value ) ) {
		return "'" . $value . "'";
	}
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}
	if ( null === $value ) {
		return 'null';
	}
	if ( is_scalar( $value ) ) {
		return (string) $value;
	}
	$encoded = wp_json_encode( $value );
	return false === $encoded ? gettype( $value ) : $encoded;
}

/**
 * Record a passing or failing assertion.
 *
 * Internal counter/print primitive. Every public assertion funnels through it
 * so that counting and output formatting exist exactly once (PHASE 7).
 *
 * @param bool   $condition Truthy when the assertion holds.
 * @param string $message   Assertion label.
 * @param string $detail    Optional extra failure context.
 * @return bool The condition, so callers can chain.
 */
function conexao_test_record( $condition, $message, $detail = '' ) {
	if ( $condition ) {
		$GLOBALS['passed']++;
		echo '  PASS: ' . $message . "\n";
		return true;
	}

	$GLOBALS['failed']++;
	echo '  FAIL: ' . $message;
	echo ( '' !== $detail ? ' — ' . $detail : '' );
	echo "\n";
	return false;
}

/**
 * Assert that a condition is truthy.
 *
 * @param mixed  $condition Condition; evaluated as a boolean (legacy semantics).
 * @param string $message   Assertion label.
 * @param string $detail    Optional extra failure context.
 * @return bool
 */
function assert_true( $condition, $message, $detail = '' ) {
	return conexao_test_record( (bool) $condition, $message, $detail );
}

/**
 * Assert that an actual value equals an expected value.
 *
 * Uses loose equality (`==`) to preserve the comparison semantics the migrated
 * suites relied on. Use `assert_true( $expected === $actual, ... )` when a
 * suite requires identity.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Assertion label.
 * @return bool
 */
function assert_equals( $expected, $actual, $message ) {
	$equal = ( $expected == $actual ); // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- documented legacy comparison semantics.

	return conexao_test_record(
		$equal,
		$message,
		$expected === $actual
			? ''
			: 'expected ' . conexao_test_describe( $expected ) . ', got ' . conexao_test_describe( $actual )
	);
}

/**
 * Presence probe shared by `assert_set()` and `assert_not_set()`.
 *
 * @param array|object|string $container Structure to inspect.
 * @param string|int          $key       Key, property or fragment.
 * @return bool
 */
function assert_set_is_present( $container, $key ) {
	if ( is_array( $container ) || $container instanceof ArrayAccess ) {
		return isset( $container[ $key ] ) || ( is_array( $container ) && array_key_exists( $key, $container ) );
	}
	if ( is_object( $container ) ) {
		return property_exists( $container, (string) $key ) || isset( $container->{$key} );
	}
	if ( is_string( $container ) ) {
		return '' !== (string) $key && false !== strpos( $container, (string) $key );
	}
	return false;
}

/**
 * Assert that a key/property/offset is present in a structure.
 *
 * Supports the value types the repository's suites actually use:
 *  - array / ArrayAccess  → the offset must exist (`array_key_exists`, so a
 *    present-but-`null` value still counts as set, which is what the legacy
 *    `isset()`-style checks asserted);
 *  - object               → the property must exist;
 *  - string               → the key must be present as a substring.
 *
 * @param array|object|string $container Structure to inspect.
 * @param string|int          $key       Key, property or fragment to look for.
 * @param string              $message   Assertion label.
 * @return bool
 */
function assert_set( $container, $key, $message ) {
	$present = assert_set_is_present( $container, $key );

	return conexao_test_record(
		$present,
		$message,
		$present ? '' : conexao_test_describe( $key ) . ' is not set in ' . conexao_test_describe( $container )
	);
}

/**
 * Assert that a key/property is ABSENT from a structure.
 *
 * The natural counterpart of `assert_set()`; migrated call sites that check a
 * structure stayed untouched use this.
 *
 * @param array|object|string $container Structure to inspect.
 * @param string|int          $key       Key, property or fragment that must be absent.
 * @param string              $message   Assertion label.
 * @return bool
 */
function assert_not_set( $container, $key, $message ) {
	$absent = ! assert_set_is_present( $container, $key );

	return conexao_test_record(
		$absent,
		$message,
		$absent ? '' : conexao_test_describe( $key ) . ' is unexpectedly set'
	);
}

/**
 * Containment probe shared by `assert_contains()` and `assert_not_contains()`.
 *
 * @param string|array $needle   Needle.
 * @param string|array $haystack Haystack.
 * @return bool
 */
function assert_contains_is_present( $needle, $haystack ) {
	if ( is_array( $haystack ) ) {
		return in_array( $needle, $haystack, false );
	}
	if ( is_string( $haystack ) ) {
		return is_string( $needle ) && '' !== $needle && false !== strpos( $haystack, $needle );
	}
	return false;
}

/**
 * Assert that a needle is contained in a haystack.
 *
 * @param string|array $needle   Needle; a value for array haystacks.
 * @param string|array $haystack Haystack; a string for substring checks.
 * @param string       $message  Assertion label.
 * @return bool
 */
function assert_contains( $needle, $haystack, $message ) {
	$present = assert_contains_is_present( $needle, $haystack );

	return conexao_test_record(
		$present,
		$message,
		$present ? '' : conexao_test_describe( $needle ) . ' not found in ' . conexao_test_describe( $haystack )
	);
}

/**
 * Assert that a value is NOT contained in a haystack.
 *
 * @param string|array $needle   Needle that must be absent.
 * @param string|array $haystack Haystack to search.
 * @param string       $message  Assertion label.
 * @return bool
 */
function assert_not_contains( $needle, $haystack, $message ) {
	$present = assert_contains_is_present( $needle, $haystack );

	return conexao_test_record(
		! $present,
		$message,
		$present ? '' : 'absent as expected'
	);
}


/**
 * Record an unconditional failure.
 *
 * @param string $message Assertion label.
 * @param string $detail  Optional extra failure context.
 * @return false Always false, so `return assert_fail(...)` reads naturally.
 */
function assert_fail( $message, $detail = '' ) {
	conexao_test_record( false, $message, $detail );
	return false;
}

/**
 * Print a suite section header.
 *
 * Replaces the pre-Stage-E `test_section()` / `t_section()` / `ts_section()` /
 * `mp_test_section()` / `s7_section()` helpers, which were all this one line.
 *
 * @param string $title Section title.
 * @return void
 */
function test_section( $title ) {
	echo "\n=== " . $title . " ===\n";
}

/**
 * Print a suite title.
 *
 * Replaces the many ad-hoc `echo "== <title> =="` banners.
 *
 * @param string $title Suite title.
 * @return void
 */
function test_title( $title ) {
	echo "\n== " . $title . " ==\n";
}

/**
 * Declare the setup hint reported when a data prerequisite is missing.
 *
 * A suite MUST declare its data prerequisites (engineering standard §8.2). A
 * missing prerequisite is a FAILURE with a human-readable hint — never a
 * vacuous pass.
 *
 * @param string $hint Short setup hint, e.g. `seed-leisure`.
 * @return void
 */
function test_prerequisite_hint( $hint ) {
	$GLOBALS['conexao_test_prerequisite'] = (string) $hint;
}

/**
 * Assert that a data prerequisite is satisfied.
 *
 * A missing prerequisite stops the suite immediately: every remaining
 * assertion in the file depends on it, so continuing would only produce
 * cascading failures that hide the real cause. The suite prints
 * `insufficient data: <hint>` (with the setup instruction), records the
 * prerequisite as a failure and exits non-zero — never a vacuous pass.
 *
 * @param bool   $condition Whether the prerequisite is satisfied.
 * @param string $hint      Setup hint (required; see PHASE 76).
 * @param string $message   Assertion label.
 * @param string $detail    Optional setup instruction.
 * @return void
 */
function test_require( $condition, $hint, $message, $detail = '' ) {
	if ( $condition ) {
		conexao_test_record( true, $message );
		return;
	}

	$GLOBALS['conexao_test_prereq_failed'] = true;
	if ( '' === $GLOBALS['conexao_test_prerequisite'] ) {
		test_prerequisite_hint( $hint );
	}

	echo '  insufficient data: ' . $GLOBALS['conexao_test_prerequisite'] . "\n";
	if ( '' !== $detail ) {
		echo '  setup hint: ' . $detail . "\n";
	}
	conexao_test_record( false, $message );

	exit( 1 );
}

/**
 * Print the shared `N passed, M failed` summary and exit.
 *
 * Every maintained suite ends with this call, which is the single place where
 * a suite's exit status is decided (PHASE 7 / PHASE 24).
 *
 * @param string $label Optional suite label for the summary line.
 * @return void
 */
function test_finish( $label = '' ) {
	$passed = (int) $GLOBALS['passed'];
	$failed = (int) $GLOBALS['failed'];

	$prefix = '' !== $label ? $label . ': ' : '';
	echo "\n" . $prefix . $passed . ' passed, ' . $failed . " failed\n";

	if ( $failed > 0 && $GLOBALS['conexao_test_prereq_failed'] ) {
		echo 'insufficient data: ' . $GLOBALS['conexao_test_prerequisite'] . "\n";
	}

	exit( $failed > 0 ? 1 : 0 );
}

