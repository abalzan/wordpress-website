<?php
/**
 * Stage 4 provider contract tests: configuration, request construction,
 * credentials, error mapping, retries and the ceilings.
 *
 * ## No network, ever
 *
 * The transport is replaced for every case, so this suite cannot reach a
 * vendor even by accident. That is what makes "credential never emitted" and
 * "timeout handled" testable facts rather than hopes.
 *
 * The assertions that matter are the refusals. A provider boundary that accepts
 * a wrong answer is worse than no boundary at all, because it would hand a
 * defective translation to the engine with the appearance of authority.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Provider_Config as Config;
use Conexao_Translation_Automation_Provider_OpenAI as Provider;
use Conexao_Translation_Automation_Provider_Result as Result;

test_title( 'conexao-translation-automation — Stage 4 provider implementation' );

/**
 * A fixed, obviously-fake credential, set and unset around each case.
 *
 * It is shaped like an application password on purpose: `assert_no_secrets()`
 * must refuse it by SHAPE, so a leak would be caught even if the key name
 * changed.
 */
const CONEXAO_S4_FAKE_CREDENTIAL = 'zzzz yyyy xxxx wwww';

/**
 * Build a translation context for a stage/record pair.
 *
 * A `WP_Error` is returned as-is rather than forced to an array, so a test that
 * deliberately builds an invalid context asserts the refusal instead of
 * crashing the suite.
 *
 * @param array $overrides Context overrides.
 * @return array|WP_Error
 */
function conexao_s4_context( array $overrides = array() ) {
	return Result::context(
		array_merge(
			array(
				'stage'         => 'en-guide',
				'identity'      => 'meu-guia',
				'source_digest' => hash( 'sha256', 'pt-source-state' ),
				'post_type'     => 'guide',
				'run_id'        => 'run_s4',
				'fields'        => array( 'post_title', 'post_content' ),
				'mode'          => 'b1',
			),
			$overrides
		)
	);
}

/**
 * A provider response envelope carrying the given translations.
 *
 * @param array $translations The field map to embed.
 * @param string $id          Provider response id.
 * @return string JSON body.
 */
function conexao_s4_body( array $translations, string $id = 'resp_1' ): string {
	return (string) wp_json_encode(
		array(
			'id'      => $id,
			'choices' => array(
				array( 'message' => array( 'content' => (string) wp_json_encode( $translations ) ) ),
			),
		)
	);
}

/**
 * Build a transport result in the EXACT shape `wp_remote_post()` returns.
 *
 * Stage 4 originally returned a flat `{ code, body }`, which is NOT the
 * WordPress contract — the status lives at `response.code`. That mismatch is
 * precisely what let the Stage 5 transport defect pass the whole suite, so
 * since Stage 6 every double in this file returns the real shape.
 *
 * @param int    $code    HTTP status.
 * @param string $body    Response body.
 * @param array  $headers Response headers.
 * @return array
 */
function conexao_s4_wp_response( int $code, string $body = '', array $headers = array() ): array {
	return array(
		'headers'       => $headers,
		'body'          => $body,
		'response'      => array( 'code' => $code, 'message' => '' ),
		'cookies'       => array(),
		'filename'      => null,
		'http_response' => null,
	);
}

/**
 * Install a transport that always answers with the same thing.
 *
 * @param string $answer A body string.
 * @param int    $code   HTTP status.
 * @param int    &$calls Call counter, by reference.
 * @return void
 */
function conexao_s4_transport( string $answer, int $code, int &$calls ): void {
	Provider::set_transport(
		static function () use ( $answer, $code, &$calls ) {
			++$calls;

			return conexao_s4_wp_response( $code, $answer );
		}
	);
}

// ---------------------------------------------------------------------------
// 1. Configuration: non-secret, closed vocabulary, no secret persisted
// ---------------------------------------------------------------------------
test_section( 'Provider configuration' );

$config = Config::configuration();

assert_true( is_array( $config ) && array() !== $config, 'the configuration is a non-empty array' );
assert_equals( 'openai', (string) $config['provider'], 'the provider identifier is declared' );
assert_true( '' !== (string) $config['model'], 'a model identifier is declared' );
assert_true( 0 === strpos( (string) $config['endpoint'], 'https://' ), 'the endpoint is https' );
assert_equals( 'pt', (string) $config['source_lang'], 'the supported source language is pt' );
assert_equals( 'en', (string) $config['target_lang'], 'the supported target language is en' );
assert_true( (int) $config['timeout'] > 0, 'a timeout is declared' );
assert_true( (int) $config['max_attempts'] >= 1, 'a bounded attempt ceiling is declared' );
assert_true( (int) $config['max_request_bytes'] > 0, 'a request size ceiling is declared' );
assert_true( (int) $config['max_requests_run'] > 0, 'a per-run request ceiling is declared' );

// The configuration must be safe to persist and print: no secret-shaped value.
assert_true(
	! is_wp_error( Conexao_Translation_Automation_Result::assert_no_secrets( $config ) ),
	'the non-secret configuration passes the repository secret check, so it is safe to persist'
);

// The configuration names the environment variable but never its value.
assert_equals(
	Config::CREDENTIAL_ENV,
	(string) $config['required_env_var'],
	'the configuration names the credential environment variable'
);
assert_true(
	false === strpos( (string) wp_json_encode( $config ), CONEXAO_S4_FAKE_CREDENTIAL ),
	'the configuration carries no credential value'
);

// Closed vocabulary: an unknown key is refused, not defaulted.
assert_true( Config::is_known_key( 'model' ), 'a defined key is recognised' );
assert_true( ! Config::is_known_key( 'max_attempts_typo' ), 'an undefined key is not recognised' );
assert_true(
	is_wp_error( Config::assert_known_keys( array( 'model' => 'x' ) ) ) === false,
	'a configuration of known keys is accepted'
);
$unknown = Config::assert_known_keys( array( 'model' => 'x', 'unbounded_retry' => true ) );
assert_true( is_wp_error( $unknown ), 'a configuration carrying an UNKNOWN key is refused' );
assert_true(
	false !== strpos( (string) $unknown->get_error_message(), 'unbounded_retry' ),
	'the refusal names the offending key'
);

// ---------------------------------------------------------------------------
// 2. Credential boundary: environment only, and never emitted
// ---------------------------------------------------------------------------
test_section( 'Credential boundary' );

$previous = getenv( Config::CREDENTIAL_ENV );

putenv( Config::CREDENTIAL_ENV );

$missing = Config::credentials();
assert_true( is_wp_error( $missing ), 'a missing credential is REFUSED, not defaulted' );
assert_equals(
	'conexao_automation_provider_no_credential',
	(string) $missing->get_error_code(),
	'the missing-credential failure is a specific, reportable category'
);
assert_true( ! Config::credentials_available(), 'availability reports false without a credential' );

// Blank is refused too: an empty bearer is a 401 at the vendor.
putenv( Config::CREDENTIAL_ENV . '=   ' );
assert_true( is_wp_error( Config::credentials() ), 'a blank credential is refused, not trimmed into an empty bearer' );

putenv( Config::CREDENTIAL_ENV . '=' . CONEXAO_S4_FAKE_CREDENTIAL );
assert_true( Config::credentials_available(), 'availability reports true with a credential' );
assert_equals( CONEXAO_S4_FAKE_CREDENTIAL, (string) Config::credentials(), 'the credential resolves from the environment' );

// A credential MUST NOT be placed where a secret could be persisted, and must
// not survive in the configuration an operator may print.
assert_true(
	! is_wp_error( Conexao_Translation_Automation_Result::assert_no_secrets( Config::configuration() ) ),
	'the configuration remains clean while a credential is present in the environment'
);

// With no credential, translate() refuses BEFORE any request is assembled.
putenv( Config::CREDENTIAL_ENV );

$no_creds_calls = 0;
conexao_s4_transport( conexao_s4_body( array( 'post_title' => 'T', 'post_content' => 'C' ) ), 200, $no_creds_calls );

$refused = Provider::translate(
	array( 'post_title' => 'T', 'post_content' => 'C' ),
	conexao_s4_context()
);

assert_true( is_wp_error( $refused ), 'translate() fails closed without a credential' );
assert_equals( 0, $no_creds_calls, 'no HTTP request is attempted without a credential' );
assert_true(
	false === strpos( (string) wp_json_encode( array( 'm' => (string) $refused->get_error_message() ) ), CONEXAO_S4_FAKE_CREDENTIAL ),
	'the refusal message carries no credential'
);

putenv( Config::CREDENTIAL_ENV . '=' . CONEXAO_S4_FAKE_CREDENTIAL );

// ---------------------------------------------------------------------------
// 3. Request construction: projection-derived, context-bound, no extras
// ---------------------------------------------------------------------------
test_section( 'Request construction' );

$context  = conexao_s4_context();
$payload  = array(
	'post_title'   => 'Guia de imigração',
	'post_content' => '<!-- wp:paragraph --><p>Olá</p><!-- /wp:paragraph -->',
	// NOT requested, and NOT a translation input. Its presence proves the
	// builder narrows to the REQUESTED fields rather than forwarding the record.
	'post_name'    => 'guia-imigracao',
	'_leisure_excerpt_en' => 'a previously generated English value',
);

$request = Provider::build_request( $payload, $context );

assert_true( is_array( $request ), 'a request is assembled' );
assert_equals( Config::MODEL_ID, (string) $request['model'], 'the request names the configured model' );
assert_equals( 0, (int) $request['temperature'], 'the request is temperature 0, for reproducibility' );
assert_equals( 'json_object', (string) $request['response_format']['type'], 'the request demands a JSON object' );

$system = (string) $request['messages'][0]['content'];
$user   = (string) $request['messages'][1]['content'];

assert_true( false !== strpos( $system, 'Preserve all HTML tags' ), 'the HTML policy is stated verbatim in the instruction' );
assert_true( false !== strpos( $system, 'wp:paragraph' ), 'the Gutenberg block-comment rule is stated' );
assert_true( false !== strpos( $system, 'post_title' ), 'the requested field list is stated' );
assert_true( false !== strpos( $system, 'Do not invent a slug' ), 'the instruction forbids inventing a slug' );

$echoed = json_decode( $user, true );

assert_true( is_array( $echoed ), 'the request carries a machine-readable context envelope' );
assert_equals( $context['source_digest'], (string) $echoed['source_digest'], 'the request binds the source digest' );
assert_equals( $context['request_identity'], (string) $echoed['request_identity'], 'the request binds the request identity' );
assert_equals( 'en-guide', (string) $echoed['stage'], 'the request binds the stage' );
assert_equals( 'run_s4', (string) $echoed['run_id'], 'the request binds the run id' );
assert_equals( 'meu-guia', (string) $echoed['source_identity'], 'the request binds the source identity' );
assert_equals( 'en', (string) $echoed['target_lang'], 'the request binds the target language' );

// ONLY the requested fields travel. An unrequested PT field, and a previously
// GENERATED EN value, must both be absent.
assert_equals( array( 'post_title', 'post_content' ), array_keys( (array) $echoed['source'] ), 'only the requested fields are sent' );
assert_true( ! isset( $echoed['source']['post_name'] ), 'the PT slug is never sent as translatable content' );
assert_true( ! isset( $echoed['source']['_leisure_excerpt_en'] ), 'a previously generated EN value is never sent back' );
assert_true( false === strpos( $user, 'previously generated' ), 'no generated English value reaches the provider' );

// Ambiguity is refused rather than guessed at: an empty field set is refused
// by the Stage 3 context builder, and a requested-but-absent field by the
// request builder.
assert_true(
	is_wp_error( conexao_s4_context( array( 'fields' => array() ) ) ),
	'a context requesting no fields is refused at context construction'
);
assert_true(
	is_wp_error( Provider::build_request( array( 'post_title' => 'T' ), conexao_s4_context( array( 'fields' => array( 'post_excerpt' ) ) ) ) ),
	'a request naming a field the projection does not carry is refused'
);

// A context the provider cannot be given at all is also refused.
assert_true(
	is_wp_error( Provider::build_request( array( 'post_title' => 'T' ), array() ) ),
	'a request with no context at all is refused rather than sent unbindable'
);


// ---------------------------------------------------------------------------
// 4. Request identity: deterministic and source-bound
// ---------------------------------------------------------------------------
test_section( 'Request identity' );

$first  = conexao_s4_context();
$second = conexao_s4_context( array( 'run_id' => 'run_a_completely_different_run' ) );

assert_equals(
	(string) $first['request_identity'],
	(string) $second['request_identity'],
	'the request identity is stable for an unchanged source, across runs'
);

$changed = conexao_s4_context( array( 'source_digest' => hash( 'sha256', 'different-pt-state' ) ) );
assert_true(
	(string) $changed['request_identity'] !== (string) $first['request_identity'],
	'the request identity changes when the PT source state changes'
);

$fewer_fields = conexao_s4_context( array( 'fields' => array( 'post_title' ) ) );
assert_true(
	(string) $fewer_fields['request_identity'] !== (string) $first['request_identity'],
	'the request identity changes when the requested field set changes'
);

$other_stage = conexao_s4_context( array( 'stage' => 'en-page' ) );
assert_true(
	(string) $other_stage['request_identity'] !== (string) $first['request_identity'],
	'the request identity changes when the stage changes'
);

// The provider configuration identity participates, and is itself stable.
assert_equals( Config::identity_token(), Config::identity_token(), 'the configuration identity token is deterministic' );
assert_true( '' !== Config::identity_token(), 'the configuration identity token is non-empty' );
assert_true(
	false === strpos( (string) wp_json_encode( Config::identity() ), 'credential' ),
	'the configuration identity excludes anything credential-shaped, so it can never leak a secret into an identity'
);

// ---------------------------------------------------------------------------
// 5. Error classification: the closed vocabulary
// ---------------------------------------------------------------------------
test_section( 'Error classification' );

assert_equals( '', Provider::classify_status( 200 ), 'a 200 is a success' );
assert_equals( '', Provider::classify_status( 201 ), 'a 201 is a success' );

assert_equals( Provider::CATEGORY_RETRYABLE, Provider::classify_status( 429 ), '429 is retryable (rate limited)' );
assert_equals( Provider::CATEGORY_RETRYABLE, Provider::classify_status( 500 ), '500 is retryable' );
assert_equals( Provider::CATEGORY_RETRYABLE, Provider::classify_status( 503 ), '503 is retryable' );
assert_equals( Provider::CATEGORY_RETRYABLE, Provider::classify_status( 408 ), '408 is retryable' );

assert_equals( Provider::CATEGORY_PERMANENT, Provider::classify_status( 400 ), '400 is permanent (bad request)' );
assert_equals( Provider::CATEGORY_PERMANENT, Provider::classify_status( 401 ), '401 is permanent (authentication)' );
assert_equals( Provider::CATEGORY_PERMANENT, Provider::classify_status( 403 ), '403 is permanent (authorisation)' );
assert_equals( Provider::CATEGORY_PERMANENT, Provider::classify_status( 422 ), '422 is permanent (unprocessable)' );

// Anything unrecognised is UNKNOWN, and unknown is a hard failure — never a
// retry and never a success.
assert_equals( Provider::CATEGORY_UNKNOWN, Provider::classify_status( 999 ), 'an unrecognised status is unknown' );
assert_equals( Provider::CATEGORY_UNKNOWN, Provider::classify_status( 302 ), 'a redirect status is unknown, not a success' );


// ---------------------------------------------------------------------------
// 6. Retry policy: bounded, and only for retryable statuses
// ---------------------------------------------------------------------------
test_section( 'Retry policy' );

$payload = array( 'post_title' => 'T', 'post_content' => 'C' );

// A permanent status is attempted EXACTLY once.
$permanent_calls = 0;
conexao_s4_transport( '{"error":"nope"}', 401, $permanent_calls );
$permanent = Provider::translate( $payload, conexao_s4_context() );
assert_equals( 1, $permanent_calls, 'an authentication failure is attempted exactly once — a wrong key stays wrong' );
assert_equals( Provider::CATEGORY_PERMANENT, (string) $permanent['status'], 'an authentication failure is permanent' );
assert_equals( array(), (array) $permanent['translations'], 'a permanent failure carries no translations' );

// An unknown status is attempted exactly once: unknown is a HARD failure.
$unknown_calls = 0;
conexao_s4_transport( 'whatever', 999, $unknown_calls );
$unknown = Provider::translate( $payload, conexao_s4_context() );
assert_equals( 1, $unknown_calls, 'an unrecognised status is attempted exactly once and is never retried' );
assert_equals( Provider::CATEGORY_UNKNOWN, (string) $unknown['status'], 'an unrecognised status is reported as unknown' );
assert_equals( array(), (array) $unknown['translations'], 'an unknown status yields no translations — no partial plan' );

// A retryable status IS retried, up to the ceiling, and not beyond it.
$retry_calls = 0;
conexao_s4_transport( '{"error":"rate limited"}', 429, $retry_calls );
$retried = Provider::translate( $payload, conexao_s4_context() );
assert_equals( (int) Config::MAX_ATTEMPTS, $retry_calls, 'a retryable status is retried up to the exact attempt ceiling' );
assert_true( is_wp_error( $retried ), 'exhausting the attempts is a hard failure, not a success' );
assert_equals(
	'conexao_automation_provider_attempts_exhausted',
	(string) $retried->get_error_code(),
	'the exhaustion failure is specific and says no plan was produced'
);
assert_true(
	false !== strpos( (string) $retried->get_error_message(), 'No translation plan' ),
	'the exhaustion message states explicitly that no plan was produced'
);

// A retryable status that then SUCCEEDS recovers within the ceiling.
$flaky_calls = 0;
Provider::set_transport(
	static function () use ( &$flaky_calls ) {
		++$flaky_calls;

		if ( 1 === $flaky_calls ) {
			return conexao_s4_wp_response( 503, '{"error":"unavailable"}' );
		}

		return conexao_s4_wp_response(
			200,
			conexao_s4_body( array( 'post_title' => 'A title', 'post_content' => 'A body' ) )
		);
	}
);
$recovered = Provider::translate( $payload, conexao_s4_context() );
assert_equals( 2, $flaky_calls, 'a transient failure then a success costs exactly two attempts' );
assert_equals( 'success', (string) $recovered['status'], 'a recovered request reports success' );

// A timeout is a transport failure, and it IS retried, because availability is
// the provider's contract. It is not silently swallowed.
$timeout_calls = 0;
Provider::set_transport(
	static function () use ( &$timeout_calls ) {
		++$timeout_calls;

		return new WP_Error( 'http_request_timeout', 'Connection timed out' );
	}
);
$timed_out = Provider::translate( $payload, conexao_s4_context() );
assert_equals( (int) Config::MAX_ATTEMPTS, $timeout_calls, 'a timeout is retried up to the attempt ceiling' );
assert_true( is_wp_error( $timed_out ), 'a persistent timeout is a hard failure' );
assert_equals(
	'conexao_automation_provider_attempts_exhausted',
	(string) $timed_out->get_error_code(),
	'exhaustion is reported as one reportable category'
);
assert_true(
	false !== strpos( (string) $timed_out->get_error_message(), 'http_request_timeout' ),
	'the underlying transport failure is named in the message for the operator'
);

// A credential refusal is a LOCAL decision and is never retried.
$auth_calls = 0;
Provider::set_transport(
	static function () use ( &$auth_calls ) {
		++$auth_calls;

		return conexao_s4_wp_response( 200, conexao_s4_body( array( 'post_title' => 'T', 'post_content' => 'C' ) ) );
	}
);
putenv( Config::CREDENTIAL_ENV );
Provider::translate( $payload, conexao_s4_context() );
assert_equals( 0, $auth_calls, 'a missing credential short-circuits before any attempt' );
putenv( Config::CREDENTIAL_ENV . '=' . CONEXAO_S4_FAKE_CREDENTIAL );


// ---------------------------------------------------------------------------
// 7. Response parsing: shape, and every way a 200 can still be unusable
// ---------------------------------------------------------------------------
test_section( 'Response parsing and content fixtures' );

/**
 * Run a body through translate() and validate it, returning both verdicts.
 *
 * @param string $body    Provider body.
 * @param array  $ctx     Context.
 * @param int    $payload Payload.
 * @return array{raw:array|WP_Error,validated:array,calls:int}
 */
function conexao_s4_round_trip( string $body, array $ctx, array $payload ) {
	$calls = 0;
	conexao_s4_transport( $body, 200, $calls );

	$raw = Provider::translate( $payload, $ctx );

	return array(
		'raw'       => $raw,
		'validated' => is_array( $raw ) ? Result::validate( $raw, $ctx ) : array( 'ok' => false, 'reason' => 'error' ),
		'calls'     => $calls,
	);
}

$plain = conexao_s4_round_trip(
	conexao_s4_body( array( 'post_title' => 'Immigration guide', 'post_content' => 'An English body.' ) ),
	conexao_s4_context(),
	$payload
);
assert_true( ! empty( $plain['validated']['ok'] ), 'a well-formed single/multi-field response validates' );
assert_equals( 'Immigration guide', (string) $plain['validated']['translations']['post_title'], 'the validated title is preserved' );
assert_true( '' !== (string) $plain['validated']['meta']['result_digest'], 'the validator computes a result digest' );
assert_equals( Config::PROVIDER_ID, (string) $plain['validated']['meta']['provider'], 'the provider identity is recorded' );
assert_equals( Config::MODEL_ID, (string) $plain['validated']['meta']['model'], 'the model identity is recorded' );

// LONG content: the largest realistic case in this repository.
$long_pt = str_repeat( '<!-- wp:paragraph --><p>Parágrafo em português com acentuação: ção, ã, õ, é.</p><!-- /wp:paragraph -->', 200 );
$long_en = str_repeat( '<!-- wp:paragraph --><p>English paragraph with accents: cao, a, o, e.</p><!-- /wp:paragraph -->', 200 );

$long = conexao_s4_round_trip(
	conexao_s4_body( array( 'post_title' => 'Long', 'post_content' => $long_en ) ),
	conexao_s4_context(),
	array( 'post_title' => 'Long', 'post_content' => $long_pt )
);
assert_true( ! empty( $long['validated']['ok'] ), 'long HTML content validates' );
assert_equals( strlen( $long_en ), strlen( (string) $long['validated']['translations']['post_content'] ), 'long content survives intact' );

// HTML + Gutenberg block comments are preserved through parsing.
$html = conexao_s4_round_trip(
	conexao_s4_body(
		array(
			'post_title'   => "Dicas &amp; orienta\u{00E7}\u{00F5}es",
			'post_content' => '<!-- wp:heading --><h2>Comoplies</h2><!-- /wp:heading --><p>Visit <a href="https://exemplo.ie/guia">this page</a>.</p>',
		)
	),
	conexao_s4_context(),
	array( 'post_title' => 'Dicas', 'post_content' => '<p>Olá</p>' )
);
assert_true( ! empty( $html['validated']['ok'] ), 'HTML content validates' );
$html_out = (string) $html['validated']['translations']['post_content'];
assert_true( false !== strpos( $html_out, '<!-- wp:heading -->' ), 'a Gutenberg block comment survives parsing' );
assert_true( false !== strpos( $html_out, 'href="https://exemplo.ie/guia"' ), 'an href is not mangled by parsing' );
assert_true( false !== strpos( (string) $html['validated']['translations']['post_title'], '&amp;' ), 'an HTML entity survives parsing' );

// Unicode and Portuguese diacritics round-trip as UTF-8, not as escapes.
$unicode = conexao_s4_round_trip(
	conexao_s4_body( array( 'post_title' => 'Coração de Studies', 'post_content' => 'Ação — “aspas” e \u{2019}apóstrofo\u{2019}' ) ),
	conexao_s4_context(),
	array( 'post_title' => 'Coração de Estudos', 'post_content' => 'Ação' )
);
assert_equals( 'Coração de Studies', (string) $unicode['validated']['translations']['post_title'], 'UTF-8 diacritics survive unchanged' );
assert_true( false !== strpos( (string) $unicode['validated']['translations']['post_content'], '“aspas”' ), 'typographic quotes survive unchanged' );

// An apostrophe in the content must not break JSON handling.
$apostrophe = conexao_s4_round_trip(
	conexao_s4_body( array( 'post_title' => "Ireland's coast", 'post_content' => "It's fine" ) ),
	conexao_s4_context(),
	array( 'post_title' => "A costa da Irlanda", 'post_content' => 'Está tudo bem' )
);
assert_equals( "Ireland's coast", (string) $apostrophe['validated']['translations']['post_title'], 'an ASCII apostrophe survives' );

// An escaped quote inside the content survives.
$escaped = conexao_s4_round_trip(
	conexao_s4_body( array( 'post_title' => 'He said "yes"', 'post_content' => 'A backslash \\ and a "quote"' ) ),
	conexao_s4_context(),
	array( 'post_title' => 'x', 'post_content' => 'y' )
);
assert_equals( 'He said "yes"', (string) $escaped['validated']['translations']['post_title'], 'an escaped double quote survives' );
assert_true( false !== strpos( (string) $escaped['validated']['translations']['post_content'], '\\' ), 'an escaped backslash survives' );


// ---------------------------------------------------------------------------
// 8. Every way a 200 can still be a refusal
// ---------------------------------------------------------------------------
test_section( 'Invalid responses fail closed' );

/**
 * Assert that a body does NOT validate, and say why it is interesting.
 *
 * @param array  $result  Output of the round trip.
 * @param string $message Assertion label.
 * @return void
 */
function conexao_s4_refused( array $result, string $message ): void {
	assert_true( empty( $result['validated']['ok'] ), $message );
	assert_equals( array(), (array) ( $result['validated']['translations'] ?? array() ), $message . ' — and carries no partial translation' );
}

// An empty optional field is a legal empty string, not a missing field.
$empty_optional = conexao_s4_round_trip(
	conexao_s4_body( array( 'post_title' => 'A title', 'post_content' => '' ) ),
	conexao_s4_context(),
	$payload
);
assert_true( ! empty( $empty_optional['validated']['ok'] ), 'an empty translated field is accepted as a string' );
assert_equals( '', (string) $empty_optional['validated']['translations']['post_content'], 'an empty translated field is preserved as empty' );

// Missing required field.
conexao_s4_refused(
	conexao_s4_round_trip( conexao_s4_body( array( 'post_title' => 'Only a title' ) ), conexao_s4_context(), $payload ),
	'a response missing a requested field is refused'
);

// Wrong type.
conexao_s4_refused(
	conexao_s4_round_trip(
		conexao_s4_body( array( 'post_title' => array( 'nested' => 'array' ), 'post_content' => 'A body' ) ),
		conexao_s4_context(),
		$payload
	),
	'a response whose field is not a string is refused'
);

// Extra key — including one that reads like a WordPress mutation.
conexao_s4_refused(
	conexao_s4_round_trip(
		conexao_s4_body( array( 'post_title' => 'A', 'post_content' => 'B', 'post_status' => 'publish' ) ),
		conexao_s4_context(),
		$payload
	),
	'an extra key is refused'
);

$mutation = conexao_s4_round_trip(
	conexao_s4_body( array( 'post_title' => 'A', 'post_content' => 'B' ) ),
	conexao_s4_context(),
	$payload
);
assert_true( empty( $mutation['validated']['ok'] ) === false, 'the control case validates, so the refusals above are meaningful' );

// Malformed JSON, and a TRUNCATED body (also not valid JSON).
conexao_s4_refused(
	conexao_s4_round_trip( '{"choices":[{"message":{"content":', conexao_s4_context(), $payload ),
	'a malformed JSON body is refused'
);

$truncated = conexao_s4_round_trip(
	conexao_s4_body( array( 'post_title' => 'A title', 'post_content' => 'A body' ) ),
	conexao_s4_context(),
	$payload
);
$calls = 0;
conexao_s4_transport( substr( conexao_s4_body( array( 'post_title' => 'A title', 'post_content' => 'A body' ) ), 0, 40 ), 200, $calls );
$cut = Provider::translate( $payload, conexao_s4_context() );
assert_true( ! empty( $cut ) && 'success' !== (string) $cut['status'], 'a truncated 200 body is not reported as a success' );
assert_equals( Provider::CATEGORY_INVALID, (string) $cut['status'], 'a truncated body is an invalid response' );
unset( $truncated );

// An empty body.
conexao_s4_refused( conexao_s4_round_trip( '', conexao_s4_context(), $payload ), 'an empty 200 body is refused' );

// A 200 whose envelope has no choices.
conexao_s4_refused(
	conexao_s4_round_trip( '{"id":"resp_1","choices":[]}', conexao_s4_context(), $payload ),
	'a 200 with no choices is refused'
);

// A 200 whose message content is prose rather than JSON.
conexao_s4_refused(
	conexao_s4_round_trip(
		'{"id":"resp_1","choices":[{"message":{"content":"Sure! Here is the translation: A guide."}}]}',
		conexao_s4_context(),
		$payload
	),
	'a 200 whose content is prose rather than JSON is refused'
);

// A fenced JSON block is TOLERATED — this is a formatting detail, not a defect.
$fenced = conexao_s4_round_trip(
	'{"id":"r","choices":[{"message":{"content":"```json\n{\"post_title\":\"A title\",\"post_content\":\"A body\"}\n```"}}]}',
	conexao_s4_context(),
	$payload
);
assert_true( ! empty( $fenced['validated']['ok'] ), 'a fenced JSON block is tolerated and validates' );


// ---------------------------------------------------------------------------
// 9. Source binding: the result is tied to the exact request
// ---------------------------------------------------------------------------
test_section( 'Source binding of the result' );

$calls = 0;
conexao_s4_transport( conexao_s4_body( array( 'post_title' => 'A title', 'post_content' => 'A body' ) ), 200, $calls );
$bound = Provider::translate( $payload, conexao_s4_context() );

// The identity on the response comes from the CONTEXT, so a provider that
// answers about something else cannot pass it off.
assert_equals( 'meu-guia', (string) $bound['source_identity'], 'the response identity is the one requested' );
assert_equals( hash( 'sha256', 'pt-source-state' ), (string) $bound['source_digest'], 'the response source digest is the one requested' );
assert_equals( 'en', (string) $bound['target_lang'], 'the response target language is the one requested' );

// A result validated against a DIFFERENT context is refused.
$wrong = Result::validate( $bound, conexao_s4_context( array( 'identity' => 'outro-guia' ) ) );
assert_true( empty( $wrong['ok'] ), 'a result is refused when validated against a different source identity' );
assert_true( false !== strpos( (string) $wrong['reason'], 'identity' ), 'the refusal names the identity mismatch' );

$wrong_digest = Result::validate( $bound, conexao_s4_context( array( 'source_digest' => hash( 'sha256', 'other-state' ) ) ) );
assert_true( empty( $wrong_digest['ok'] ), 'a result is refused when validated against a different source digest' );
assert_true( false !== strpos( (string) $wrong_digest['reason'], 'digest' ), 'the refusal names the digest mismatch' );

$wrong_lang = Result::validate( $bound, conexao_s4_context( array( 'target_lang' => 'fr' ) ) );
assert_true( empty( $wrong_lang['ok'] ), 'a result is refused when validated against a different target language' );

// A provider that SELF-CERTIFIES a result digest is caught: the validator
// computes its own and compares.
$self_signed               = $bound;
$self_signed['result_digest'] = str_repeat( '0', 64 );
$forged = Result::validate( $self_signed, conexao_s4_context() );
assert_true( empty( $forged['ok'] ), 'a provider-asserted result digest is refused when it does not match the translations' );
assert_true( false !== strpos( (string) $forged['reason'], 'result digest' ), 'the refusal names the self-certified digest' );

// The validator, not the provider, decides the result digest.
$honest = Result::validate( $bound, conexao_s4_context() );
$again  = Result::validate( $bound, conexao_s4_context() );
assert_equals(
	(string) $honest['meta']['result_digest'],
	(string) $again['meta']['result_digest'],
	'the result digest is deterministic for the same translations'
);


// ---------------------------------------------------------------------------
// 10. Cost and rate guards
// ---------------------------------------------------------------------------
test_section( 'Cost and rate guards' );

assert_true( (int) Config::MAX_ATTEMPTS >= 1 && (int) Config::MAX_ATTEMPTS <= 10, 'the attempt ceiling is bounded and small' );
assert_true( (int) Config::MAX_REQUESTS_PER_RUN > 0, 'a per-run request ceiling is declared' );
assert_true( (int) Config::MAX_RUN_SECONDS > 0, 'a per-run time budget is declared' );
assert_true(
	Config::BACKOFF_BASE_MS < Config::BACKOFF_MAX_MS,
	'the backoff is capped, so a bounded attempt count still cannot wait forever'
);

// An oversized request is refused BEFORE it is sent.
$oversize = Config::assert_request_within_limits( array( 'filler' => str_repeat( 'x', (int) Config::MAX_REQUEST_BYTES + 10 ) ) );
assert_true( is_wp_error( $oversize ), 'a request above the size ceiling is refused' );
assert_equals(
	'conexao_automation_provider_request_too_large',
	(string) $oversize->get_error_code(),
	'the size refusal is a specific, reportable category'
);

$within = Config::assert_request_within_limits( array( 'filler' => 'small' ) );
assert_true( ! is_wp_error( $within ), 'a request within the ceiling is accepted' );

// The refusal message must not echo the payload it refused.
assert_true(
	false === strpos( (string) $oversize->get_error_message(), 'xxxx' ),
	'the size refusal echoes no payload content'
);

// ---------------------------------------------------------------------------
// 11. The credential is never emitted
// ---------------------------------------------------------------------------
test_section( 'The credential is never emitted' );

$emitted = array();

// Every observable output: a success, a permanent failure, an unknown status,
// an invalid body and an exhausted retry sequence.
$calls = 0;
conexao_s4_transport( conexao_s4_body( array( 'post_title' => 'A title', 'post_content' => 'A body' ) ), 200, $calls );
$emitted['success'] = Provider::translate( $payload, conexao_s4_context() );

$calls = 0;
conexao_s4_transport( '{"e":"x"}', 401, $calls );
$emitted['permanent'] = Provider::translate( $payload, conexao_s4_context() );

$calls = 0;
conexao_s4_transport( 'nope', 999, $calls );
$emitted['unknown'] = Provider::translate( $payload, conexao_s4_context() );

$calls = 0;
conexao_s4_transport( '{', 200, $calls );
$emitted['invalid'] = Provider::translate( $payload, conexao_s4_context() );

$calls = 0;
conexao_s4_transport( 'nope', 429, $calls );
$emitted['exhausted'] = Provider::translate( $payload, conexao_s4_context() );

$emitted['config']        = Config::configuration();
$emitted['identity']      = Config::identity();
$emitted['identity_token'] = Config::identity_token();

$leaks = array();

foreach ( $emitted as $label => $value ) {
	$serialised = is_wp_error( $value )
		? (string) $value->get_error_message()
		: (string) wp_json_encode( $value );

	if ( false !== strpos( $serialised, CONEXAO_S4_FAKE_CREDENTIAL ) ) {
		$leaks[] = $label;
	}

	if ( false !== strpos( $serialised, 'Bearer' ) ) {
		$leaks[] = $label . ' (authorization header)';
	}
}

assert_true( array() === $leaks, 'no observable provider output contains the credential or an authorization header' . ( $leaks ? ': ' . implode( ', ', $leaks ) : '' ) );

// The authorization header IS built — that is the vendor contract — but the
// credential never appears in the request body or any return value.
$seen_header = '';
$seen_body   = '';
Provider::set_transport(
	static function ( $url, $args ) use ( &$seen_header, &$seen_body ) {
		$seen_header = (string) $args['headers']['Authorization'];
		$seen_body   = (string) $args['body'];

		return conexao_s4_wp_response( 200, conexao_s4_body( array( 'post_title' => 'A', 'post_content' => 'B' ) ) );
	}
);
Provider::translate( $payload, conexao_s4_context() );

assert_true( false !== strpos( $seen_header, CONEXAO_S4_FAKE_CREDENTIAL ), 'the credential is sent as a bearer header, which is the vendor contract' );
assert_true( false === strpos( $seen_body, CONEXAO_S4_FAKE_CREDENTIAL ), 'the credential never appears in the request body' );

Provider::set_transport( null );

putenv( Config::CREDENTIAL_ENV );
if ( is_string( $previous ) && '' !== $previous ) {
	putenv( Config::CREDENTIAL_ENV . '=' . $previous );
}

test_finish( 'stage 4 provider implementation' );
