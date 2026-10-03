<?php
/**
 * Stage 14 Gate A — the secret-scan contract.
 *
 * ## Why this suite exists
 *
 * Stage 13 closed with a security defect: `assert_no_secrets()` recognised only
 * the four-group WordPress application-password shape, so a provider key
 * (`sk-...`), PEM private-key material and an `Authorization` value were all
 * emitted as though they were clean. Stage 14 fixes it. This suite makes the fix
 * permanent, and it is deliberately split into the two halves a scanner can get
 * wrong:
 *
 *   1. NEGATIVE — real secret shapes MUST be refused (a detector that never fires
 *      asserts nothing);
 *   2. POSITIVE — ordinary content MUST NOT be refused (a detector that fires on
 *      everything trains an operator to ignore the boundary, which is how the
 *      four-group shape became dangerous in the first place).
 *
 * ## One corpus, two runtimes
 *
 * The cases live in `tests/fixtures/secret-scan-corpus.json` and are NOT written
 * out again here. `tests/scripts/verify-stage14-secret-scan.py` reads the SAME
 * file, so the PHP runtime boundary and the Python artifact gate cannot drift:
 * agreement is checked, not maintained by hand.
 *
 * ## No real credential is used
 *
 * Every fixture is an obviously fake value invented for this suite. No provider
 * is called, no environment variable is read, and no WordPress write occurs.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Result as Result;

test_title( 'conexao-translation-automation — Stage 14 secret-scan hardening' );

/**
 * The canonical corpus. One source of truth for both scanners.
 *
 * @return array
 */
function conexao_s14_corpus(): array {
	static $cases = null;

	if ( null === $cases ) {
		$path  = CONEXAO_TESTS_WP_ROOT . '/tests/fixtures/secret-scan-corpus.json';
		$raw   = json_decode( (string) file_get_contents( $path ), true );
		$cases = is_array( $raw ) && isset( $raw['cases'] ) ? $raw['cases'] : array();
	}

	return $cases;
}

$CORPUS = conexao_s14_corpus();

assert_true(
		20 <= count( $CORPUS ),
		'the canonical secret-scan corpus is readable and carries its cases'
	);

test_section( '1. Negative: every injected secret is refused, redacted' );

foreach ( $CORPUS as $case ) {
	if ( 'detect' !== $case['expect'] ) {
		continue;
	}

	// Wrapped in an ORDINARY key, so the refusal must come from the VALUE shape
	// and not from a telling key name.
	$finding = Result::detect_secret( array( 'note' => $case['payload'] ) );

	assert_true(
		null !== $finding,
		sprintf( 'the %s (%s) is refused by shape', $case['label'], $case['id'] )
	);

	assert_equals(
		$case['category'],
		$finding['category'],
		sprintf( 'the %s must be categorised correctly', $case['id'] )
	);

	// The refusal must be safe to log: category, location, reason, redacted.
	assert_true(
		true === $finding['redacted'],
		sprintf( 'the %s finding did not declare itself redacted', $case['id'] )
	);

	assert_true(
		isset( $finding['location'], $finding['reason'] ),
		sprintf( 'the %s finding lacked a location or a reason', $case['id'] )
	);

	// The value must not appear anywhere in the emitted report.
	$rendered = wp_json_encode( $finding );

	assert_true(
		false === strpos( (string) $rendered, $case['payload'] ),
		sprintf( 'the %s finding LEAKED its own value', $case['id'] )
	);
}

test_section( '2. Positive: legitimate content is not a false positive' );

foreach ( $CORPUS as $case ) {
	if ( 'allow' !== $case['expect'] ) {
		continue;
	}

	$finding = Result::detect_secret( array( 'note' => $case['payload'] ) );

	assert_true(
		null === $finding,
		sprintf( 'the %s (%s) is not a false positive', $case['label'], $case['id'] )
	);
}

test_section( '3. The detection report leaks nothing identifying' );

$SECRET = 'sk-proj-ZzZzYyYxXxWwVvUuTtSsRrQqPp012345';

// Every plausible re-encoding of the secret is absent from the report.
$finding = Result::detect_secret( array( 'note' => $SECRET ) );
$rendered = (string) wp_json_encode( $finding );

$leaks = array(
	'the value itself'         => $SECRET,
	'the first 8 characters'    => substr( $SECRET, 0, 8 ),
	'the last 8 characters'     => substr( $SECRET, -8 ),
	'the middle 16 characters'  => substr( $SECRET, 10, 16 ),
	'the length as a number'    => (string) strlen( $SECRET ),
	'a sha256 of the value'     => hash( 'sha256', $SECRET ),
	'a sha1 of the value'       => hash( 'sha1', $SECRET ),
	'a base64 of the value'     => base64_encode( $SECRET ),
);

foreach ( $leaks as $label => $needle ) {
	assert_true(
		false === strpos( $rendered, $needle ),
		sprintf( 'the detection report discloses no %s', $label )
	);
}

test_section( '4. Detection reaches every documented container' );

// A secret must be found wherever it hides: nested arrays, objects converted to
// arrays, exception messages, audit metadata and provider result structures.
$CONTAINERS = array(
	'nested array'               => array( 'outer' => array( 'inner' => array( 'deeper' => $SECRET ) ) ),
	'object converted to array'  => json_decode( wp_json_encode( array( 'provider' => array( 'key' => $SECRET ) ) ) ),
	'object, not converted'      => (object) array( 'provider' => (object) array( 'key' => $SECRET ) ),
	'an exception message'       => array( 'exception' => array( 'message' => 'call failed for ' . $SECRET ) ),
	'a provider result'          => array( 'result' => array( 'choices' => array( array( 'text' => $SECRET ) ) ) ),
	'a list element'             => array( 'items' => array( 'harmless', $SECRET ) ),
	'an integer-keyed list'      => array( 0 => $SECRET ),
	'a bare scalar string'       => $SECRET,
);

foreach ( $CONTAINERS as $label => $payload ) {
	$finding = Result::detect_secret( $payload );

	assert_true(
		null !== $finding,
		sprintf( 'a secret is found in %s', $label )
	);

	assert_true(
		false === strpos( (string) wp_json_encode( $finding ), $SECRET ),
		sprintf( 'the value stays redacted from %s', $label )
	);
}

// The reported LOCATION must point at the container, proving the walk descends.
$nested = Result::detect_secret( array( 'outer' => array( 'inner' => array( 'deeper' => $SECRET ) ) ) );

assert_true(
		false !== strpos( (string) $nested['location'], 'deeper' ),
		'the location did not point at the nested leaf'
	);

test_section( '5. Every pre-existing rule is preserved (no regression)' );

// The Stage 2 rules, asserted individually. Stage 14 ADDS categories; it must not
// remove or weaken any rule that already existed.
$PRESERVED_KEYS = array(
	'password',
	'passwd',
	'secret',
	'token',
	'nonce',
	'cookie',
	'authorization',
	'api_key',
	'apikey',
	'private_key',
	'credential',
);

foreach ( $PRESERVED_KEYS as $needle ) {
	assert_true(
		is_wp_error( Result::assert_no_secrets( array( 'ctx' => array( $needle . '_x' => 'harmless' ) ) ) ),
		sprintf( 'the pre-existing key rule for "%s" still fires', $needle )
	);
}

// The four-group application-password shape, in its ORIGINAL form.
assert_true(
		is_wp_error( Result::assert_no_secrets( array( 'note' => 'value abcd efgh ijkl mnop' ) ) ),
		'the four-group application-password shape is still detected'
	);

assert_true(
		! is_wp_error( Result::assert_no_secrets( array( 'run_id' => 'run_1', 'stage' => 'en-guide' ) ) ),
		'a clean run record is still accepted'
	);

test_section( '6. assert_no_secrets() refuses without printing the value' );

$error = Result::assert_no_secrets( array( 'note' => $SECRET ) );

assert_true(
		is_wp_error( $error ),
		'assert_no_secrets() refuses the secret'
	);

$rendered_error = (string) wp_json_encode( array( $error->get_error_message(), $error->get_error_data() ) );

assert_true(
		false === strpos( $rendered_error, $SECRET ),
		'the WP_Error does not leak the value'
	);

assert_true(
		false === strpos( $rendered_error, substr( $SECRET, 0, 8 ) ),
		'the WP_Error does not leak a value prefix'
	);

test_finish( 'conexao-translation-automation — Stage 14 secret-scan hardening' );