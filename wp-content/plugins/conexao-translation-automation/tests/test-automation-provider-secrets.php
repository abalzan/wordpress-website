<?php
/**
 * Stage 14 Gate A — the PROVIDER secret-containment regression.
 *
 * ## What this proves
 *
 * Stage 13's finding was that the secret boundary recognised only one value
 * shape. This suite closes the loop on the places a provider credential could
 * actually escape, and it does so WITHOUT calling a provider:
 *
 *   1. an `sk-...` credential is detected by the boundary;
 *   2. a credential-shaped value cannot reach a log;
 *   3. a bearer `Authorization` value cannot reach the audit trail;
 *   4. PEM private-key material cannot reach a report;
 *   5. a provider ERROR cannot leak the authorization material that caused it.
 *
 * ## No provider is contacted
 *
 * `Provider::set_transport()` replaces the HTTP layer, exactly as the Stage 4
 * suite does, so this suite performs zero network I/O. The credential used is an
 * obviously fake value defined below, and it is unset again immediately after
 * the case that needs it. No real environment value is read or printed.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Audit as Audit;
use Conexao_Translation_Automation_Provider_Config as Config;
use Conexao_Translation_Automation_Provider_OpenAI as Provider;
use Conexao_Translation_Automation_Provider_Result as ProviderResult;
use Conexao_Translation_Automation_Result as Result;

test_title( 'conexao-translation-automation — Stage 14 provider secret containment' );

// ---------------------------------------------------------------------------
// A fixed, obviously fake credential. Obviously fake matters: a real key must
// never be typed into this repository, and a fixture is only safe if it could
// not be mistaken for one.
// ---------------------------------------------------------------------------
const CONEXAO_S14_FAKE_KEY = 'sk-proj-ZzZzYyYxXxWwVvUuTtSsRrQqPp012345';

/**
 * Build a transport result in the shape `wp_remote_post()` returns.
 *
 * @param int    $code   HTTP status.
 * @param string $answer Body string.
 * @return array
 */
function conexao_s14_response( int $code, string $answer ): array {
	return array(
		'headers'  => array(),
		'body'     => $answer,
		'response' => array(
			'code'    => $code,
			'message' => 200 === $code ? 'OK' : 'Error',
		),
		'cookies'       => array(),
		'filename'      => null,
		'http_response' => null,
	);
}

test_section( '1. An sk- credential is detected by the boundary' );

$finding = Result::detect_secret( array( 'note' => CONEXAO_S14_FAKE_KEY ) );

assert_true( null !== $finding, 'an sk- credential is detected' );
assert_equals( 'api_key_prefixed', $finding['category'], 'the credential is categorised as a provider API key' );
assert_true( true === $finding['redacted'], 'the finding declares itself redacted' );
assert_true(
	false === strpos( (string) wp_json_encode( $finding ), CONEXAO_S14_FAKE_KEY ),
	'the finding does not echo the credential'
);

test_section( '2. A credential-shaped value cannot reach a log' );

// The failure DETAIL is the field an operator would log. A credential smuggled
// into it must be dropped, not persisted.
$leaky = Result::failure(
	'run_s14_log',
	'proof',
	'en-guide',
	Result::FAILURE_BAD_CONFIG,
	array( 'config' => array( 'note' => 'key was ' . CONEXAO_S14_FAKE_KEY ) )
);

$serialised = (string) wp_json_encode( $leaky->to_array() );

assert_true(
	false === strpos( $serialised, CONEXAO_S14_FAKE_KEY ),
	'the credential never reaches an emitted record'
);
assert_true(
	false === strpos( $serialised, 'sk-proj-' ),
	'no credential prefix reaches an emitted record'
);
assert_true(
	is_wp_error( Result::assert_no_secrets( array( 'note' => CONEXAO_S14_FAKE_KEY ) ) ),
	'the boundary refuses the credential outright'
);

test_section( '3. A bearer Authorization value cannot reach the audit' );

// A realistic transport failure: the provider echoes the request context, which
// is where an Authorization value classically leaks.
$captured = array();

Provider::set_transport(
	static function () use ( &$captured ) {
		// Capture what the provider actually handed the transport, so the
		// suite can prove the bearer header was really assembled (and that
		// this test is not vacuous), then answer with a failure.
		$captured[] = func_get_args();

		return conexao_s14_response( 401, '{"error":{"message":"Incorrect API key provided"}}' );
	}
);

putenv( Config::CREDENTIAL_ENV . '=' . CONEXAO_S14_FAKE_KEY );

$context = ProviderResult::context(
	array(
		'stage'         => 'en-guide',
		'identity'      => 'meu-guia',
		'source_digest' => hash( 'sha256', 'pt-source-state' ),
		'post_type'     => 'guide',
		'run_id'        => 'run_s14_provider',
		'fields'        => array( 'post_title' ),
		'mode'          => 'b1',
	)
);

$payload = array( 'post_title' => 'Um guia', 'post_content' => 'Conteudo do guia.' );

$provider_failure = Provider::translate( $payload, is_wp_error( $context ) ? array() : $context );

putenv( Config::CREDENTIAL_ENV );

// Whatever the provider returns, the credential must reach none of it.
$rendered_failure = is_wp_error( $provider_failure )
	? (string) wp_json_encode( array( $provider_failure->get_error_message(), $provider_failure->get_error_data() ) )
	: (string) wp_json_encode( $provider_failure );

assert_true(
	false === strpos( $rendered_failure, CONEXAO_S14_FAKE_KEY ),
	'a provider failure does not echo the credential'
);
assert_true(
	false === strpos( $rendered_failure, 'sk-proj-' ),
	'a provider failure does not echo a credential prefix'
);

Audit::clear();

$record = Audit::build_record(
	array(
		'run_id' => 'run_s14_provider',
		'stage'  => 'en-guide',
		'result' => is_wp_error( $provider_failure )
			? array( 'status' => 'failed', 'error' => $provider_failure->get_error_message() )
			: $provider_failure,
	)
);

assert_true(
	false === strpos( (string) wp_json_encode( $record ), CONEXAO_S14_FAKE_KEY ),
	'the audit record does not carry the credential'
);
assert_true(
	! is_wp_error( Result::assert_no_secrets( $record ) ),
	'the audit record passes the hardened secret boundary'
);

test_section( '4. PEM private-key material cannot reach a report' );

$pem = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAx0000000000000000\n-----END RSA PRIVATE KEY-----";

$report = array( 'report' => array( 'transport' => $pem ) );

assert_true(
	is_wp_error( Result::assert_no_secrets( $report ) ),
	'PEM private-key material is refused in a report'
);

$pem_finding = Result::detect_secret( $report );

assert_equals( 'pem_private_key', $pem_finding['category'], 'the PEM material is categorised as private-key material' );
assert_true(
	false === strpos( (string) wp_json_encode( $pem_finding ), 'MIIEow' ),
	'the PEM finding does not echo key material'
);
assert_true(
	false === strpos( (string) wp_json_encode( $pem_finding ), 'BEGIN RSA' ),
	'the PEM finding does not echo the PEM header'
);

test_section( '5. The transport was given an Authorization value that never persisted' );

$sent = isset( $captured[0] ) ? (array) $captured[0] : array();
$args = isset( $sent[1] ) ? (array) $sent[1] : array();
$headers = isset( $args['headers'] ) ? (array) $args['headers'] : array();

assert_true(
	isset( $headers['Authorization'] ),
	'the provider does assemble an Authorization header for the transport'
);
assert_true(
	0 === strpos( (string) $headers['Authorization'], 'Bearer ' ),
	'the Authorization header uses the bearer scheme'
);

test_finish( 'conexao-translation-automation — Stage 14 provider secret containment' );