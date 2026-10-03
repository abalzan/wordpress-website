<?php
/**
 * Stage 6 transport regression: the REAL `wp_remote_post()` response shape.
 *
 * ## Why this suite exists at all
 *
 * Stage 5 ended BLOCKED because `Provider_OpenAI::normalise()` read a
 * top-level `code`, while the real `wp_remote_post()` return value carries the
 * status at `response.code`. Every Stage 4 test double returned the flat
 * `{ code, body }` shape, so the suite agreed with the defect instead of
 * contradicting it: the production transport path could only ever produce
 * `bad_transport`, and the tests still passed.
 *
 * This suite is the fixture the Stage 4 suite should have had. Its rule is
 * absolute: a transport double MUST return what `wp_remote_post()` returns.
 * There is no flat-shape helper in this file, by design.
 *
 * ## The defect this must fail against
 *
 *   real wp_remote_post() shape + a VALID body
 *     -> Stage 5: `conexao_automation_provider_bad_transport`  (WRONG)
 *     -> Stage 6: a valid normalised response                (RIGHT)
 *
 * The test below asserts the ACTUAL HTTP code, the ACTUAL body, the ACTUAL
 * provider parsing result and the ACTUAL validated translation — not merely
 * that the string `bad_transport` is absent. A negative-only assertion would
 * pass against a provider that failed for an entirely different reason.
 *
 * ## No network, ever
 *
 * Every case replaces the transport, so this suite cannot reach a vendor even
 * by accident.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Provider_Config as Config;
use Conexao_Translation_Automation_Provider_OpenAI as Provider;
use Conexao_Translation_Automation_Provider_Result as Result;

test_title( 'conexao-translation-automation — Stage 6 transport regression (real wp_remote_post shape)' );

const CONEXAO_S6_FAKE_CREDENTIAL = 'zzzz yyyy xxxx wwww';

/**
 * Build a response in the EXACT shape `wp_remote_post()` returns.
 *
 * This is the only transport-result builder in this file. It carries every
 * key WordPress supplies, so a test that omits one is exercising a genuinely
 * malformed transport rather than an abbreviated fixture.
 *
 * @param int    $code      HTTP status.
 * @param string $body      Response body.
 * @param array  $overrides Keys to add, or null to remove.
 * @return array
 */
function conexao_s6_wp_response( int $code, string $body = '', array $overrides = array() ): array {
	$response = array(
		'headers'       => array(
			'content-type' => 'application/json',
			'x-request-id' => 'req_fixture_0001',
		),
		'body'          => $body,
		'response'      => array(
			'code'    => $code,
			'message' => conexao_s6_status_message( $code ),
		),
		'cookies'       => array(),
		'filename'      => null,
		'http_response' => null,
	);

	foreach ( $overrides as $key => $value ) {
		if ( null === $value ) {
			unset( $response[ $key ] );

			continue;
		}

		$response[ $key ] = $value;
	}

	return $response;
}

/**
 * The HTTP reason phrase, as `response.message` carries it.
 *
 * @param int $code HTTP status.
 * @return string
 */
function conexao_s6_status_message( int $code ): string {
	$map = array(
		200 => 'OK',
		400 => 'Bad Request',
		401 => 'Unauthorized',
		403 => 'Forbidden',
		429 => 'Too Many Requests',
		500 => 'Internal Server Error',
		503 => 'Service Unavailable',
	);

	return isset( $map[ $code ] ) ? $map[ $code ] : 'Unknown Status';
}

/**
 * A provider success envelope carrying the given field map.
 *
 * @param array  $translations The field map to embed.
 * @param string $id           Provider response id.
 * @return string JSON body.
 */
function conexao_s6_success_body( array $translations, string $id = 'resp_s6' ): string {
	return (string) wp_json_encode(
		array(
			'id'      => $id,
			'model'   => 'gpt-4o-mini',
			'choices' => array(
				array( 'message' => array( 'content' => (string) wp_json_encode( $translations ) ) ),
			),
		)
	);
}

/**
 * Install a transport returning one fixed REAL-shaped result.
 *
 * @param mixed $answer The `wp_remote_post()` result, or a WP_Error.
 * @param int   &$calls Call counter, by reference.
 * @return void
 */
function conexao_s6_transport( $answer, int &$calls ): void {
	Provider::set_transport(
		static function () use ( $answer, &$calls ) {
			++$calls;

			return $answer;
		}
	);
}

/**
 * A deterministic translation context.
 *
 * @param array $overrides Context overrides.
 * @return array
 */
function conexao_s6_context( array $overrides = array() ): array {
	$context = Result::context(
		array_merge(
			array(
				'stage'         => 'en-guide',
				'identity'      => 'guia-de-imigracao',
				'source_digest' => hash( 'sha256', 'stage-6-pt-source-state' ),
				'post_type'     => 'guide',
				'run_id'        => 'run_s6_transport',
				'fields'        => array( 'post_title', 'post_content' ),
				'mode'          => 'b1',
			),
			$overrides
		)
	);

	return is_wp_error( $context ) ? array() : $context;
}

// The PT source, and the English a correct provider returns for it.
$s6_payload = array(
	'post_title'   => 'Guia de imigração para a Irlanda',
	'post_content' => '<!-- wp:paragraph --><p>Este guia explica os principais passos.</p><!-- /wp:paragraph -->',
);

$s6_en = array(
	'post_title'   => 'Immigration guide for Ireland',
	'post_content' => '<!-- wp:paragraph --><p>This guide explains the main steps.</p><!-- /wp:paragraph -->',
);

$context = conexao_s6_context();

putenv( Config::CREDENTIAL_ENV . '=' . CONEXAO_S6_FAKE_CREDENTIAL );
$previous = getenv( Config::CREDENTIAL_ENV );

// ---------------------------------------------------------------------------
// 1. THE REGRESSION. Real shape + valid body must normalise correctly.
// ---------------------------------------------------------------------------
test_section( 'REGRESSION: a real WordPress transport result is normalised, not refused' );

$calls = 0;
$body  = conexao_s6_success_body( $s6_en );
conexao_s6_transport( conexao_s6_wp_response( 200, $body ), $calls );

$regression = Provider::translate( $s6_payload, $context );

// The Stage 5 implementation returned a WP_Error
// `conexao_automation_provider_bad_transport` for exactly this input.
assert_true(
	! is_wp_error( $regression ),
	'a real transport result shape is NOT refused as a transport defect'
		. ( is_wp_error( $regression ) ? ' (got ' . $regression->get_error_code() . ')' : '' )
);

if ( is_wp_error( $regression ) ) {
	assert_fail( 'the regression case returned a usable provider response', (string) $regression->get_error_message() );
} else {
	// Assert the ACTUAL parsed values, not merely the absence of a status.
	assert_equals( 'success', (string) $regression['status'], 'the normalised 200 is reported as a success' );
	assert_equals( $s6_en['post_title'], (string) $regression['translations']['post_title'], 'the parsed title is the exact English the provider returned' );
	assert_equals( $s6_en['post_content'], (string) $regression['translations']['post_content'], 'the parsed body is the exact English the provider returned' );
	assert_equals( 'resp_s6', (string) $regression['request_id'], 'the provider request id survives normalisation' );
	// The identity is echoed from the CONTEXT, never from the provider.
	assert_equals( 'guia-de-imigracao', (string) $regression['source_identity'], 'the echoed identity comes from the request context' );
	assert_equals( 1, $calls, 'a successful real-shape response costs exactly one provider call' );

	// And the whole pipeline: normalised -> decoded -> VALIDATED.
	$validated = Result::validate( $regression, $context );

	assert_true( ! empty( $validated['ok'] ), 'the normalised real-shape response passes the Stage 4 validator' );
	assert_equals(
		$s6_en['post_title'],
		(string) ( $validated['translations']['post_title'] ?? '' ),
		'the validated translation carries the exact English the provider returned'
	);
}

// ---------------------------------------------------------------------------
// 2. The exact compatibility matrix required by the Stage 6 brief.
// ---------------------------------------------------------------------------
test_section( 'Transport compatibility matrix (exact WordPress shape)' );

// Real shape, 200 -> normalised success.
$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 200, conexao_s6_success_body( $s6_en ) ), $calls );
$ok = Provider::translate( $s6_payload, $context );
assert_true( ! is_wp_error( $ok ) && 'success' === (string) $ok['status'], '200 in the real shape normalises to a success' );

// Real shape, 429 insufficient_quota -> quota refusal, ONE call, NOT retried.
$quota_body = (string) wp_json_encode(
	array(
		'error' => array(
			'message' => 'You exceeded your current quota.',
			'type'    => 'insufficient_quota',
			'code'    => 'insufficient_quota',
		),
	)
);

$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 429, $quota_body ), $calls );
$quota = Provider::translate( $s6_payload, $context );

assert_true( is_wp_error( $quota ), 'a 429 insufficient_quota is refused' );
assert_equals(
	'conexao_automation_provider_insufficient_quota',
	is_wp_error( $quota ) ? (string) $quota->get_error_code() : '',
	'a 429 insufficient_quota is classified as quota exhaustion, not a transport defect'
);
assert_equals( 1, $calls, 'an exhausted quota is NOT retried (exactly one provider call)' );

// A 429 with a DIFFERENT error code is a transient rate limit: still retryable.
$rate_body = (string) wp_json_encode( array( 'error' => array( 'code' => 'rate_limit_exceeded', 'type' => 'requests' ) ) );

$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 429, $rate_body ), $calls );
$rate = Provider::translate( $s6_payload, $context );
assert_true( is_wp_error( $rate ), 'a transient 429 rate limit is still refused after exhausting attempts' );
assert_equals(
	'conexao_automation_provider_attempts_exhausted',
	is_wp_error( $rate ) ? (string) $rate->get_error_code() : '',
	'a transient 429 rate limit IS retried (attempts exhausted), unlike quota exhaustion'
);
assert_true( $calls > 1, 'a transient rate limit consumes more than one attempt' );

// Real shape, 401 -> auth failure, NOT retried.
//
// A completed HTTP exchange returns a Stage 3 ENVELOPE carrying a failure
// status, not a WP_Error. That is the Stage 4 contract and Stage 6 does not
// change it: only a transport-level defect (no HTTP exchange at all) is a
// WP_Error. What matters here is the CATEGORY and the call count.
$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 401, '{"error":{"code":"invalid_api_key"}}' ), $calls );
$auth = Provider::translate( $s6_payload, $context );
assert_true( ! is_wp_error( $auth ), 'a 401 completes the HTTP exchange and returns an envelope' );
assert_equals( Provider::CATEGORY_PERMANENT, (string) $auth['status'], 'a 401 is a permanent failure' );
assert_equals( array(), (array) $auth['translations'], 'a 401 failure carries no translations at all' );
assert_equals( 1, $calls, 'a 401 is never retried (a wrong key stays wrong)' );

// Real shape, 403 -> auth failure, NOT retried.
$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 403, '{"error":{"code":"forbidden"}}' ), $calls );
$forbidden = Provider::translate( $s6_payload, $context );
assert_true( ! is_wp_error( $forbidden ), 'a 403 completes the HTTP exchange and returns an envelope' );
assert_equals( Provider::CATEGORY_PERMANENT, (string) $forbidden['status'], 'a 403 is a permanent failure' );
assert_equals( 1, $calls, 'a 403 is never retried' );

// Real shape, 400 -> permanent failure, NOT retried.
$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 400, '{"error":{"code":"invalid_request"}}' ), $calls );
$bad = Provider::translate( $s6_payload, $context );
assert_true( ! is_wp_error( $bad ), 'a 400 completes the HTTP exchange and returns an envelope' );
assert_equals( Provider::CATEGORY_PERMANENT, (string) $bad['status'], 'a 400 is a permanent failure' );
assert_equals( 1, $calls, 'a 400 is never retried' );

// Real shape, 500 and 503 -> retryable.
foreach ( array( 500, 503 ) as $retryable ) {
	$calls = 0;
	conexao_s6_transport( conexao_s6_wp_response( $retryable, '{"error":{"code":"server_error"}}' ), $calls );
	$upstream = Provider::translate( $s6_payload, $context );

	assert_true( is_wp_error( $upstream ), sprintf( 'a %d is refused', $retryable ) );
	assert_equals(
		'conexao_automation_provider_attempts_exhausted',
		is_wp_error( $upstream ) ? (string) $upstream->get_error_code() : '',
		sprintf( 'a %d is classified as a retryable upstream failure', $retryable )
	);
	assert_true( $calls > 1, sprintf( 'a %d is retried', $retryable ) );
}

// A retryable WP_Error (no HTTP exchange at all) is retried, then exhausted.
$calls = 0;
conexao_s6_transport( new WP_Error( 'http_request_failed', 'cURL error 28' ), $calls );
$transport = Provider::translate( $s6_payload, $context );
assert_true( is_wp_error( $transport ), 'a retryable WP_Error transport failure is surfaced' );
assert_equals(
	'conexao_automation_provider_attempts_exhausted',
	is_wp_error( $transport ) ? (string) $transport->get_error_code() : '',
	'a retryable transport error is retried and then reported as attempts exhausted'
);
assert_true( $calls > 1, 'a retryable transport error consumes more than one attempt' );

// A NON-retryable WP_Error is passed through untouched, on the first attempt.
$calls = 0;
conexao_s6_transport( new WP_Error( 'http_request_not_executed', 'blocked by policy' ), $calls );
$blocked = Provider::translate( $s6_payload, $context );
assert_true( is_wp_error( $blocked ), 'a non-retryable WP_Error transport failure is surfaced' );
assert_equals(
	'http_request_not_executed',
	is_wp_error( $blocked ) ? (string) $blocked->get_error_code() : '',
	'a non-retryable WP_Error is passed through with its own code intact'
);
assert_equals( 1, $calls, 'a non-retryable transport error is not retried' );

// Missing `response` -> malformed transport.
$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 200, conexao_s6_success_body( $s6_en ), array( 'response' => null ) ), $calls );
$no_envelope = Provider::translate( $s6_payload, $context );
assert_true( is_wp_error( $no_envelope ), 'a result with no `response` key is a malformed transport' );
assert_equals(
	'conexao_automation_provider_missing_response',
	is_wp_error( $no_envelope ) ? (string) $no_envelope->get_error_code() : '',
	'a missing `response` has its own specific failure category'
);

// Missing `response.code` -> malformed transport.
$calls = 0;
conexao_s6_transport(
	conexao_s6_wp_response( 200, conexao_s6_success_body( $s6_en ), array( 'response' => array( 'message' => 'OK' ) ) ),
	$calls
);
$no_status = Provider::translate( $s6_payload, $context );
assert_true( is_wp_error( $no_status ), 'a result with no `response.code` is a malformed transport' );
assert_equals(
	'conexao_automation_provider_missing_status',
	is_wp_error( $no_status ) ? (string) $no_status->get_error_code() : '',
	'a missing `response.code` has its own specific failure category'
);

// A non-numeric `response.code` is refused rather than cast.
$calls = 0;
conexao_s6_transport(
	conexao_s6_wp_response( 200, conexao_s6_success_body( $s6_en ), array( 'response' => array( 'code' => array( 200 ), 'message' => 'OK' ) ) ),
	$calls
);
$bad_status = Provider::translate( $s6_payload, $context );
assert_true( is_wp_error( $bad_status ), 'a non-numeric `response.code` is refused rather than cast' );

// Missing body -> malformed transport.
$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 200, '', array( 'body' => null ) ), $calls );
$no_body = Provider::translate( $s6_payload, $context );
assert_true( is_wp_error( $no_body ), 'a result with no body is a malformed transport' );
assert_equals(
	'conexao_automation_provider_missing_body',
	is_wp_error( $no_body ) ? (string) $no_body->get_error_code() : '',
	'a missing body has its own specific failure category'
);

// An EMPTY body is a different defect from a MISSING body: the transport was
// well-formed, so this is an INVALID RESPONSE envelope, not a transport error.
$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 200, '' ), $calls );
$empty = Provider::translate( $s6_payload, $context );
assert_true( ! is_wp_error( $empty ), 'an empty 200 body completes the exchange and returns an envelope' );
assert_equals( Provider::CATEGORY_INVALID, (string) $empty['status'], 'an empty body is an invalid provider response' );
assert_equals( array(), (array) $empty['translations'], 'an empty body carries no translations' );

// Invalid JSON -> invalid provider response (not a transport defect).
$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 200, '{not json' ), $calls );
$bad_json = Provider::translate( $s6_payload, $context );
assert_true( ! is_wp_error( $bad_json ), 'an invalid JSON body completes the exchange and returns an envelope' );
assert_equals( Provider::CATEGORY_INVALID, (string) $bad_json['status'], 'invalid JSON is an invalid provider response' );

// A TRUNCATED body is also not valid JSON, so it lands in the same refusal
// and is never half-parsed.
$calls = 0;
conexao_s6_transport(
	conexao_s6_wp_response( 200, substr( conexao_s6_success_body( $s6_en ), 0, 40 ) ),
	$calls
);
$cut = Provider::translate( $s6_payload, $context );
assert_true( ! is_wp_error( $cut ), 'a truncated 200 body completes the exchange and returns an envelope' );
assert_equals( Provider::CATEGORY_INVALID, (string) $cut['status'], 'a truncated body is an invalid provider response' );

// A non-array, non-WP_Error transport result -> malformed transport.
$calls = 0;
conexao_s6_transport( 'not a response', $calls );
$garbage = Provider::translate( $s6_payload, $context );
assert_true( is_wp_error( $garbage ), 'a scalar transport result is a malformed transport' );
assert_equals(
	'conexao_automation_provider_bad_transport',
	is_wp_error( $garbage ) ? (string) $garbage->get_error_code() : '',
	'a scalar transport result is refused as a bad transport'
);

// ---------------------------------------------------------------------------
// 3. The Stage 5 flat shape is a malformed transport, not a shortcut.
// ---------------------------------------------------------------------------
test_section( 'The flat Stage 4 test double is no longer a valid transport result' );

$calls = 0;
conexao_s6_transport( array( 'code' => 200, 'body' => conexao_s6_success_body( $s6_en ) ), $calls );
$flat = Provider::translate( $s6_payload, $context );

assert_true( is_wp_error( $flat ), 'a flat { code, body } result is no longer accepted as a transport result' );
assert_equals(
	'conexao_automation_provider_missing_response',
	is_wp_error( $flat ) ? (string) $flat->get_error_code() : '',
	'the flat double is refused specifically because it carries no WordPress response envelope'
);

// ---------------------------------------------------------------------------
// 4. Validation is NOT weakened by the transport fix.
// ---------------------------------------------------------------------------
test_section( 'The transport fix did not weaken provider response validation' );

// The right transport shape with an INCOMPLETE payload still gets refused.
$calls = 0;
conexao_s6_transport(
	conexao_s6_wp_response( 200, conexao_s6_success_body( array( 'post_title' => 'A title' ) ) ),
	$calls
);
$incomplete = Provider::translate( $s6_payload, $context );
assert_true( ! is_wp_error( $incomplete ), 'a well-formed transport carrying an incomplete payload parses normally' );
assert_true( empty( Result::validate( $incomplete, $context )['ok'] ), 'an incomplete field map is still refused by the validator' );

// A wrong source identity is still refused.
$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 200, conexao_s6_success_body( $s6_en ) ), $calls );
$other = Result::validate(
	Provider::translate( $s6_payload, $context ),
	conexao_s6_context( array( 'identity' => 'a-different-guide' ) )
);
assert_true( empty( $other['ok'] ), 'a result bound to a different source identity is still refused' );

// A wrong source digest is still refused.
$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 200, conexao_s6_success_body( $s6_en ) ), $calls );
$drift = Result::validate(
	Provider::translate( $s6_payload, $context ),
	conexao_s6_context( array( 'source_digest' => hash( 'sha256', 'a-different-source' ) ) )
);
assert_true( empty( $drift['ok'] ), 'a result bound to a different source digest is still refused' );

// An extra key (a mutation instruction) is still refused.
$calls = 0;
conexao_s6_transport(
	conexao_s6_wp_response(
		200,
		conexao_s6_success_body(
			array(
				'post_title'   => 'Immigration guide for Ireland',
				'post_content' => 'This guide explains the main steps.',
				'post_status'  => 'publish',
			)
		)
	),
	$calls
);
$extra = Result::validate( Provider::translate( $s6_payload, $context ), $context );
assert_true( empty( $extra['ok'] ), 'an extra key in the provider payload is still refused' );

// ---------------------------------------------------------------------------
// 5. The credential is still never emitted.
// ---------------------------------------------------------------------------
test_section( 'The real-shape transport still never emits the credential' );

$calls = 0;
conexao_s6_transport( conexao_s6_wp_response( 200, conexao_s6_success_body( $s6_en ) ), $calls );
$secret_free = Provider::translate( $s6_payload, $context );
$serialised = is_wp_error( $secret_free ) ? (string) $secret_free->get_error_message() : (string) wp_json_encode( $secret_free );

assert_true( false === strpos( $serialised, CONEXAO_S6_FAKE_CREDENTIAL ), 'no real-shape output contains the credential' );
assert_true( false === strpos( $serialised, 'Bearer' ), 'no real-shape output contains an authorization header' );

// A 401 body that echoes the key must not have the key echoed back.
$calls = 0;
conexao_s6_transport(
	conexao_s6_wp_response( 401, (string) wp_json_encode( array( 'error' => array( 'message' => 'bad key ' . CONEXAO_S6_FAKE_CREDENTIAL ) ) ) ),
	$calls
);
$echoed = Provider::translate( $s6_payload, $context );
$echoed_text = is_wp_error( $echoed ) ? (string) $echoed->get_error_message() : (string) wp_json_encode( $echoed );
assert_true( false === strpos( $echoed_text, CONEXAO_S6_FAKE_CREDENTIAL ), 'an upstream error body echoing the credential is never surfaced' );

Provider::set_transport( null );

putenv( Config::CREDENTIAL_ENV );
if ( is_string( $previous ) && '' !== $previous ) {
	putenv( Config::CREDENTIAL_ENV . '=' . $previous );
}

test_finish( 'stage 6 transport regression' );