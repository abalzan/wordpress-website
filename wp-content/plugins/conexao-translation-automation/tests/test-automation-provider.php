<?php
/**
 * Stage 3 provider tests: the interface, the request identity, and the
 * fail-closed validator.
 *
 * ## No network, ever
 *
 * There is NO provider implementation in this repository, so this suite cannot
 * make a network call even by accident — there is no client to make one. Every
 * response below is a hand-built array standing in for a provider's answer,
 * which is exactly how an untrusted answer arrives in production.
 *
 * The assertions that matter are the refusals. A provider boundary that
 * accepts a wrong answer is worse than no boundary at all, because it would
 * hand a defective translation to the engine with the appearance of authority.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Provider_Interface as ProviderInterface;
use Conexao_Translation_Automation_Provider_Result as ProviderResult;

test_title( 'conexao-translation-automation — Stage 3 provider boundary' );

// ---------------------------------------------------------------------------
// 1. There is an interface and NO implementation
// ---------------------------------------------------------------------------
test_section( 'An interface, and no implementation' );

assert_true( interface_exists( 'Conexao_Translation_Automation_Provider_Interface' ), 'the provider contract exists as an interface' );

foreach ( array( 'provider_id', 'model_id', 'translate' ) as $method ) {
	assert_true( method_exists( 'Conexao_Translation_Automation_Provider_Interface', $method ), sprintf( 'the provider contract declares %s()', $method ) );
}

// NOTHING in the repository implements it.
$implementations = array();

foreach ( get_declared_classes() as $declared ) {
	if ( in_array( 'Conexao_Translation_Automation_Provider_Interface', class_implements( $declared ) ?: array(), true ) ) {
		$implementations[] = $declared;
	}
}

// STAGE 4 SUPERSESSION. Stage 3 asserted "NOBODY implements the contract".
// Stage 4 was authorised to add exactly ONE implementation, so the assertion
// becomes narrower and stronger: exactly one, and it is the designated provider.
// The trust boundary below is unchanged and still asserted: the provider is a
// DIFFERENT class from the validator, so it can never grade its own homework.
assert_equals(
	array( 'Conexao_Translation_Automation_Provider_OpenAI' ),
	$implementations,
	'exactly one class implements the provider contract, and it is the Stage 4 provider' . ( $implementations ? ': ' . implode( ', ', $implementations ) : '' )
);

// The validator is separate from the untrusted party, so a provider could
// never grade its own homework.
assert_true( class_exists( 'Conexao_Translation_Automation_Provider_Result' ), 'the validator is its own class, not part of the interface' );
assert_true(
	false === in_array( 'Conexao_Translation_Automation_Provider_Result', class_implements( 'Conexao_Translation_Automation_Provider_Interface' ) ?: array(), true ),
	'the validator does not participate in the provider contract'
);

// ---------------------------------------------------------------------------
// 2. The translation context, and the deterministic request identity
// ---------------------------------------------------------------------------
test_section( 'Translation context and request identity' );

$digest  = hash( 'sha256', 'pt-source' );
$context = ProviderResult::context(
	array(
		'stage'         => 'en-guide',
		'identity'      => 'my-guide',
		'source_digest' => $digest,
		'post_type'     => 'guide',
		'run_id'        => 'run_abc',
		'fields'        => array( 'post_title', 'post_content' ),
		'mode'          => 'b1',
	)
);

assert_true( is_array( $context ), 'a complete context is built' );
assert_equals( 'en', (string) $context['target_lang'], 'the context defaults to the pt->en program' );
assert_equals( 'pt', (string) $context['source_lang'], 'the context records the source language' );
assert_equals( 'my-guide', (string) $context['identity'], 'the context carries the source identity' );
assert_equals( $digest, (string) $context['source_digest'], 'the context carries the source digest' );
assert_equals( 'b1', (string) $context['mode'], 'the context carries the B1/B2 strategy' );
assert_true( 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $context['request_identity'] ), 'the context carries a request identity' );

// An incomplete context is refused.
foreach ( array( 'stage', 'identity', 'source_digest', 'post_type', 'run_id' ) as $missing ) {
	$args = array(
		'stage'         => 'en-guide',
		'identity'      => 'my-guide',
		'source_digest' => $digest,
		'post_type'     => 'guide',
		'run_id'        => 'run_abc',
		'fields'        => array( 'post_title' ),
	);
	unset( $args[ $missing ] );

	assert_true( is_wp_error( ProviderResult::context( $args ) ), sprintf( 'a context missing "%s" is refused', $missing ) );
}

assert_true(
	is_wp_error( ProviderResult::context( array_merge( (array) $context, array( 'fields' => array() ) ) ) ),
	'a context requesting no fields is refused'
);

assert_true(
	is_wp_error( ProviderResult::context( array( 'stage' => 's', 'identity' => 'i', 'source_digest' => 'nope', 'post_type' => 'guide', 'run_id' => 'r', 'fields' => array( 'x' ) ) ) ),
	'a context carrying an impossible source digest is refused'
);

// ---------------------------------------------------------------------------
test_section( 'An interface, and no implementation' );

assert_true( interface_exists( 'Conexao_Translation_Automation_Provider_Interface' ), 'the provider contract exists as an interface' );

foreach ( array( 'provider_id', 'model_id', 'translate' ) as $method ) {
	assert_true( method_exists( 'Conexao_Translation_Automation_Provider_Interface', $method ), sprintf( 'the provider contract declares %s()', $method ) );
}

// NOTHING in the repository implements it.
$implementations = array();

foreach ( get_declared_classes() as $declared ) {
	if ( in_array( 'Conexao_Translation_Automation_Provider_Interface', class_implements( $declared ) ?: array(), true ) ) {
		$implementations[] = $declared;
	}
}

// STAGE 4 SUPERSESSION. Stage 3 asserted "NOBODY implements the contract".
// Stage 4 was authorised to add exactly ONE implementation, so the assertion
// becomes narrower and stronger: exactly one, and it is the designated provider.
// The trust boundary below is unchanged and still asserted: the provider is a
// DIFFERENT class from the validator, so it can never grade its own homework.
assert_equals(
	array( 'Conexao_Translation_Automation_Provider_OpenAI' ),
	$implementations,
	'exactly one class implements the provider contract, and it is the Stage 4 provider' . ( $implementations ? ': ' . implode( ', ', $implementations ) : '' )
);

// The validator is separate from the untrusted party, so a provider could
// never grade its own homework.
assert_true( class_exists( 'Conexao_Translation_Automation_Provider_Result' ), 'the validator is its own class, not part of the interface' );
assert_true(
	false === in_array( 'Conexao_Translation_Automation_Provider_Result', class_implements( 'Conexao_Translation_Automation_Provider_Interface' ) ?: array(), true ),
	'the validator does not participate in the provider contract'
);

// ---------------------------------------------------------------------------
// 2. The translation context, and the deterministic request identity
// ---------------------------------------------------------------------------
test_section( 'Translation context and request identity' );

$digest  = hash( 'sha256', 'pt-source' );
$context = ProviderResult::context(
	array(
		'stage'         => 'en-guide',
		'identity'      => 'my-guide',
		'source_digest' => $digest,
		'post_type'     => 'guide',
		'run_id'        => 'run_abc',
		'fields'        => array( 'post_title', 'post_content' ),
		'mode'          => 'b1',
	)
);

assert_true( is_array( $context ), 'a complete context is built' );
assert_equals( 'en', (string) $context['target_lang'], 'the context defaults to the pt->en program' );
assert_equals( 'pt', (string) $context['source_lang'], 'the context records the source language' );
assert_equals( 'my-guide', (string) $context['identity'], 'the context carries the source identity' );
assert_equals( $digest, (string) $context['source_digest'], 'the context carries the source digest' );
assert_equals( 'b1', (string) $context['mode'], 'the context carries the B1/B2 strategy' );
assert_true( 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $context['request_identity'] ), 'the context carries a request identity' );

// An incomplete context is refused.
foreach ( array( 'stage', 'identity', 'source_digest', 'post_type', 'run_id' ) as $missing ) {
	$args = array(
		'stage'         => 'en-guide',
		'identity'      => 'my-guide',
		'source_digest' => $digest,
		'post_type'     => 'guide',
		'run_id'        => 'run_abc',
		'fields'        => array( 'post_title' ),
	);
	unset( $args[ $missing ] );

	assert_true( is_wp_error( ProviderResult::context( $args ) ), sprintf( 'a context missing "%s" is refused', $missing ) );
}

assert_true(
	is_wp_error( ProviderResult::context( array_merge( (array) $context, array( 'fields' => array() ) ) ) ),
	'a context requesting no fields is refused'
);

assert_true(
	is_wp_error( ProviderResult::context( array( 'stage' => 's', 'identity' => 'i', 'source_digest' => 'nope', 'post_type' => 'guide', 'run_id' => 'r', 'fields' => array( 'x' ) ) ) ),
	'a context carrying an impossible source digest is refused'
);

// REQUEST IDENTITY is a property of the WORK, not of the moment it was asked.
$identity_a = ProviderResult::request_identity( $context );
$identity_b = ProviderResult::request_identity( array_merge( $context, array( 'run_id' => 'run_completely_different' ) ) );

assert_true( $identity_a === $identity_b, 'the request identity is stable across runs over the same source' );

assert_true(
	$identity_a !== ProviderResult::request_identity( array_merge( $context, array( 'source_digest' => hash( 'sha256', 'other' ) ) ) ),
	'a different source digest yields a different request identity'
);
assert_true(
	$identity_a !== ProviderResult::request_identity( array_merge( $context, array( 'stage' => 'en-post' ) ) ),
	'a different stage yields a different request identity'
);
assert_true(
	$identity_a !== ProviderResult::request_identity( array_merge( $context, array( 'fields' => array( 'post_title' ) ) ) ),
	'a different requested field set yields a different request identity'
);

// The same source + stage + configuration is the SAME logical target, which is
// what lets the layer recognise "nothing changed, do nothing".
$rebuilt = ProviderResult::context(
	array(
		'stage'         => 'en-guide',
		'identity'      => 'my-guide',
		'source_digest' => $digest,
		'post_type'     => 'guide',
		'run_id'        => 'run_zzz',
		'fields'        => array( 'post_title', 'post_content' ),
		'mode'          => 'b1',
	)
);

assert_true(
	(string) $rebuilt['request_identity'] === (string) $context['request_identity'],
	'the same PT source digest + stage + configuration is the SAME logical translation target'
);

// ---------------------------------------------------------------------------
// 3. A valid response is accepted
// ---------------------------------------------------------------------------
test_section( 'A valid response is accepted' );

/**
 * A well-formed provider success response.
 *
 * @param array $context The request context.
 * @param array $overrides Keys to replace.
 * @return array
 */
function conexao_s3_response( array $context, array $overrides = array() ): array {
	return array_merge(
		array(
			'status'          => 'success',
			'provider'        => 'test-double',
			'model'           => 'test-model-1',
			'request_id'      => 'req-0001',
			'source_identity' => (string) $context['identity'],
			'source_digest'   => (string) $context['source_digest'],
			'target_lang'     => (string) $context['target_lang'],
			'translations'    => array(
				'post_title'   => 'An English title',
				'post_content' => 'An English body',
			),
		),
		$overrides
	);
}

$valid = ProviderResult::validate( conexao_s3_response( $context ), $context );

assert_true( ! empty( $valid['ok'] ), 'a well-formed response is accepted' );
assert_equals( ProviderResult::STATUS_SUCCESS, (string) $valid['status'], 'the accepted result reports success' );
assert_equals( 'An English title', (string) $valid['translations']['post_title'], 'the validated translation is returned' );
assert_equals( 'test-double', (string) $valid['meta']['provider'], 'the result records the provider identity' );
assert_equals( 'test-model-1', (string) $valid['meta']['model'], 'the result records the model identity' );
assert_equals( 'req-0001', (string) $valid['meta']['request_id'], 'the result records the provider request id' );
assert_true( 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $valid['meta']['result_digest'] ), 'the result carries a result digest computed by the validator' );

// The validator computes the result digest itself, so a provider cannot assert
// its own. A matching assertion is accepted...
$declared = conexao_s3_response( $context );
$declared['result_digest'] = $valid['meta']['result_digest'];
assert_true( ! empty( ProviderResult::validate( $declared, $context )['ok'] ), 'a provider may assert a result digest that matches its own translations' );

// ...and a mismatched one is refused.
$declared['result_digest'] = hash( 'sha256', 'a lie' );
assert_true( empty( ProviderResult::validate( $declared, $context )['ok'] ), 'a provider asserting a wrong result digest is refused' );

// ---------------------------------------------------------------------------
// 4. Every malformed answer fails closed
// ---------------------------------------------------------------------------
test_section( 'Malformed and unknown responses fail closed' );

/**
 * Assert that a response is refused as a hard record failure.
 *
 * @param mixed $response The candidate response.
 * @param array $context  The request context.
 * @param string $label   Assertion label.
 * @return void
 */
function conexao_s3_refuses( $response, array $context, string $label ): void {
	$result = ProviderResult::validate( $response, $context );

	assert_true(
		empty( $result['ok'] ),
		$label . ' is REFUSED'
	);
	assert_equals(
		array(),
		(array) $result['translations'],
		$label . ' yields NO translations (never partial acceptance)'
	);
	assert_true(
		ProviderResult::FAILURE_UNKNOWN_RESPONSE === (string) $result['status'],
		$label . ' is a hard record failure, not a retryable "maybe later"'
	);
	assert_true( '' !== (string) $result['reason'], $label . ' explains why it was refused' );
}

// Not a response at all.
conexao_s3_refuses( 'a bare string', $context, 'a string response' );
conexao_s3_refuses( 42, $context, 'an integer response' );
conexao_s3_refuses( null, $context, 'a null response' );
conexao_s3_refuses( array(), $context, 'an empty response' );
conexao_s3_refuses( new WP_Error( 'http', 'timeout' ), $context, 'a WP_Error response' );

// Malformed JSON: the decoded value of a broken payload is `null`.
conexao_s3_refuses( json_decode( '{"status":"success",', true ), $context, 'a malformed JSON payload' );

// An UNKNOWN status. This is the headline rule.
foreach ( array( 'ok', 'SUCCESSFUL', 'done', 'maybe', 'queued', '' ) as $status ) {
	conexao_s3_refuses(
		conexao_s3_response( $context, array( 'status' => $status ) ),
		$context,
		sprintf( 'the unknown status "%s"', $status )
	);
}

// A missing status.
$no_status = conexao_s3_response( $context );
unset( $no_status['status'] );
conexao_s3_refuses( $no_status, $context, 'a response with no status' );

// A MISSING translated field (a partial response).
$partial = conexao_s3_response( $context );
unset( $partial['translations']['post_content'] );
conexao_s3_refuses( $partial, $context, 'a partial response missing a requested field' );

// A WRONG field type.
foreach ( array( 123, array( 'nested' ), null, true ) as $wrong ) {
	conexao_s3_refuses(
		conexao_s3_response( $context, array( 'translations' => array( 'post_title' => 'T', 'post_content' => $wrong ) ) ),
		$context,
		sprintf( 'a non-string translation (%s)', gettype( $wrong ) )
	);
}

// An unrequested extra field.
conexao_s3_refuses(
	conexao_s3_response( $context, array( 'translations' => array( 'post_title' => 'T', 'post_content' => 'C', 'post_excerpt' => 'E' ) ) ),
	$context,
	'an unrequested extra translated field'
);

// A MISSING source identity.
conexao_s3_refuses( conexao_s3_response( $context, array( 'source_identity' => '' ) ), $context, 'an empty source identity' );

$no_identity = conexao_s3_response( $context );
unset( $no_identity['source_identity'] );
conexao_s3_refuses( $no_identity, $context, 'a response with no source identity' );

// A MISMATCHED source identity.
conexao_s3_refuses( conexao_s3_response( $context, array( 'source_identity' => 'someone-elses-guide' ) ), $context, 'a source identity for a different record' );

// A MISMATCHED source digest: the single most important check.
conexao_s3_refuses(
	conexao_s3_response( $context, array( 'source_digest' => hash( 'sha256', 'a different pt source' ) ) ),
	$context,
	'a source digest that does not match the request'
);

$no_digest = conexao_s3_response( $context );
unset( $no_digest['source_digest'] );
conexao_s3_refuses( $no_digest, $context, 'a response with no source digest' );

// An UNEXPECTED language.
conexao_s3_refuses( conexao_s3_response( $context, array( 'target_lang' => 'fr' ) ), $context, 'an unexpected target language' );
conexao_s3_refuses( conexao_s3_response( $context, array( 'target_lang' => 'pt' ) ), $context, 'a target language equal to the source' );

$no_lang = conexao_s3_response( $context );
unset( $no_lang['target_lang'] );
conexao_s3_refuses( $no_lang, $context, 'a response with no target language' );

// A missing provider / model / request identity.
foreach ( array( 'provider', 'model', 'request_id' ) as $key ) {
	conexao_s3_refuses( conexao_s3_response( $context, array( $key => '' ) ), $context, sprintf( 'an empty %s', $key ) );
}

// No translations map at all.
conexao_s3_refuses( conexao_s3_response( $context, array( 'translations' => 'not a map' ) ), $context, 'a non-array translations map' );

// A provider trying to describe a WORDPRESS WRITE. This is an injection
// attempt, not a translation, and it must never be honoured.
$mutations = array( 'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'update_post_meta', 'delete_post_meta', 'wp_insert_term', 'pll_set_post_language', 'post_status', 'trash', 'delete', 'mode', 'dry_run' );

foreach ( $mutations as $mutation ) {
	$response              = conexao_s3_response( $context );
	$response[ $mutation ] = 'wp_insert_post( array( "post_title" => "owned" ) )';

	$result = ProviderResult::validate( $response, $context );

	assert_true( empty( $result['ok'] ), sprintf( 'a response carrying the mutation instruction "%s" is REFUSED', $mutation ) );
	assert_true(
		false !== strpos( (string) $result['reason'], 'mutation instruction' ),
		sprintf( 'the refusal for "%s" names it as a mutation instruction', $mutation )
	);
}

// An unknown, non-mutating key is still refused: the allowlist is an allowlist.
$response          = conexao_s3_response( $context );
$response['notes'] = 'some provider-specific extra field';
conexao_s3_refuses( $response, $context, 'an unsupported extra key' );

// ---------------------------------------------------------------------------
// 5. Declared failures
// ---------------------------------------------------------------------------
test_section( 'Retryable and permanent failures are reported as declared' );

$retryable = ProviderResult::validate(
	conexao_s3_response( $context, array( 'status' => 'retryable_failure' ) ),
	$context
);

assert_true( empty( $retryable['ok'] ), 'a declared retryable failure is not a success' );
assert_equals( ProviderResult::STATUS_RETRYABLE_FAILURE, (string) $retryable['status'], 'a retryable failure preserves its status' );
assert_equals( array(), (array) $retryable['translations'], 'a retryable failure yields no translations' );

$permanent = ProviderResult::validate(
	conexao_s3_response( $context, array( 'status' => 'permanent_failure' ) ),
	$context
);

assert_true( empty( $permanent['ok'] ), 'a declared permanent failure is not a success' );
assert_equals( ProviderResult::STATUS_PERMANENT_FAILURE, (string) $permanent['status'], 'a permanent failure preserves its status' );

// Both preserve the provider identity, so an operator can see WHO failed.
assert_equals( 'test-double', (string) $permanent['meta']['provider'], 'a declared failure still records the provider identity' );

// Exactly three statuses are known. Anything else is unknown and fails closed.
$statuses = ProviderResult::known_statuses();
assert_equals( 3, count( $statuses ), 'exactly three provider statuses are recognised' );
assert_true( in_array( 'success', $statuses, true ), 'success is a known status' );
assert_true( in_array( 'retryable_failure', $statuses, true ), 'retryable_failure is a known status' );
assert_true( in_array( 'permanent_failure', $statuses, true ), 'permanent_failure is a known status' );

// ---------------------------------------------------------------------------
// 6. Idempotence: re-requesting an unchanged source is the same target
// ---------------------------------------------------------------------------
test_section( 'Provider determinism and idempotence' );

// The contract does NOT require identical provider wording, because a future
// provider may be non-deterministic. What it requires is a deterministic
// REQUEST IDENTITY.
$first  = ProviderResult::validate( conexao_s3_response( $context, array( 'request_id' => 'req-A' ) ), $context );
$second = ProviderResult::validate( conexao_s3_response( $context, array( 'request_id' => 'req-B' ) ), $context );

assert_true(
	(string) $first['meta']['request_identity'] === (string) $second['meta']['request_identity'],
	're-requesting an unchanged source yields the SAME request identity'
);

// The RESULT digest is stable when the translations are identical, even
// though the provider request id differed.
assert_true(
	(string) $first['meta']['result_digest'] === (string) $second['meta']['result_digest'],
	'identical translations yield the SAME result digest across independent requests'
);

// Different wording for the same source is still the same REQUEST, but a
// different RESULT — which is exactly how the layer can tell "new source" from
// "provider reworded an unchanged source".
$reworded = ProviderResult::validate(
	conexao_s3_response( $context, array( 'translations' => array( 'post_title' => 'A different title', 'post_content' => 'An English body' ) ) ),
	$context
);

assert_true(
	(string) $reworded['meta']['request_identity'] === (string) $first['meta']['request_identity'],
	'rewording does not change the request identity (the SOURCE did not change)'
);
assert_true(
	(string) $reworded['meta']['result_digest'] !== (string) $first['meta']['result_digest'],
	'rewording DOES change the result digest (the OUTPUT did change)'
);

test_finish( 'stage 3 provider boundary' );
