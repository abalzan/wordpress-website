<?php
/**
 * LIVE provider smoke test — OPTIONAL, OFFLINE-SAFE BY DEFAULT.
 *
 * ## This file makes a real external call to a paid provider
 *
 * It is deliberately NOT named `test-*.php`, so `./scripts/run-tests.sh` never
 * discovers or runs it. It runs only when a human asks for it, on purpose.
 *
 * ## The four conditions, all of which must hold
 *
 *   1. `CONEXAO_LIVE_PROVIDER_SMOKE=1` — explicit local authorisation.
 *   2. The provider credential is present in the environment.
 *   3. `CONEXAO_TEST_LIVE_PROVIDER=1` — a second, separate acknowledgement.
 *   4. The WordPress environment is NOT production.
 *
 * Absent any of them it prints `SKIPPED` and exits 0. A skipped smoke test is
 * not a failure; a smoke test that runs unexpectedly is a hazard.
 *
 * ## What it will never do
 *
 *   - touch WordPress content: it calls the provider DIRECTLY, with a fixed
 *     literal fixture, and reads no post, writes no post;
 *   - return a credential in its output: it prints the provider's STATUS and
 *     the validated field count, never the credential and never the raw
 *     response body;
 *   - run as part of the suite.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Provider_Config as Config;
use Conexao_Translation_Automation_Provider_OpenAI as Provider;
use Conexao_Translation_Automation_Provider_Result as Result;

echo "\n== LIVE provider smoke test (real external call) ==\n";

/**
 * Refuse to run, loudly and cleanly.
 *
 * @param string $reason Why it is not running.
 * @return void
 */
function conexao_live_skip( string $reason ): void {
	echo 'SKIPPED: ' . $reason . "\n";
	echo "No provider call was made. No WordPress content was read or written.\n";
	exit( 0 );
}

if ( '1' !== (string) getenv( 'CONEXAO_LIVE_PROVIDER_SMOKE' ) ) {
	conexao_live_skip( 'not explicitly authorised (set CONEXAO_LIVE_PROVIDER_SMOKE=1 to allow a live external call)' );
}

if ( '1' !== (string) getenv( 'CONEXAO_TEST_LIVE_PROVIDER' ) ) {
	conexao_live_skip( 'the second acknowledgement (CONEXAO_TEST_LIVE_PROVIDER=1) is absent' );
}

$environment = (string) wp_get_environment_type();

if ( 'production' === $environment ) {
	conexao_live_skip( 'refusing to run against a production environment' );
}

if ( ! Config::credentials_available() ) {
	conexao_live_skip( sprintf( 'no provider credential in %s', Config::CREDENTIAL_ENV ) );
}

echo 'environment: ' . ( '' === $environment ? '(unreported)' : $environment ) . "\n";
echo 'provider:    ' . Config::PROVIDER_ID . ' / ' . Config::MODEL_ID . "\n";
echo "WARNING:     this performs ONE real, billable request to an external provider.\n";

// A FIXED, non-production fixture. No WordPress content is read, so there is
// nothing here that could be a client record, a private field or a credential.
$fixture = array(
	'post_title'   => 'Guia de imigração para a Irlanda',
	'post_content' => '<!-- wp:paragraph --><p>Este guia explica os principais passos para obter um visto para a Irlanda.</p><!-- /wp:paragraph -->',
);

$context = Result::context(
	array(
		'stage'         => 'en-guide',
		'identity'      => 'live-smoke-fixture',
		'source_digest' => hash( 'sha256', 'live-smoke-fixture-source' ),
		'post_type'     => 'guide',
		'run_id'        => 'live_smoke',
		'fields'        => array( 'post_title', 'post_content' ),
		'mode'          => 'b1',
	)
);

if ( is_wp_error( $context ) ) {
	echo 'RESULT: could not build a translation context: ' . $context->get_error_message() . "\n";
	exit( 1 );
}

$raw = Provider::translate( $fixture, $context );

if ( is_wp_error( $raw ) ) {
	// The message never contains the credential: the provider is written so
	// that no returned error echoes the request or its headers.
	echo 'RESULT: transport or provider failure (' . $raw->get_error_code() . ").\n";
	echo 'DETAIL: ' . $raw->get_error_message() . "\n";
	exit( 1 );
}

echo 'provider status: ' . (string) $raw['status'] . "\n";

$validated = Result::validate( $raw, $context );

if ( empty( $validated['ok'] ) ) {
	echo 'RESULT: FAILED VALIDATION — ' . $validated['reason'] . "\n";
	exit( 1 );
}

echo "RESULT: OK\n";
echo 'validated fields: ' . implode( ', ', array_keys( (array) $validated['translations'] ) ) . "\n";
echo 'result digest:     ' . substr( (string) $validated['meta']['result_digest'], 0, 16 ) . "\n";
echo 'request identity:  ' . substr( (string) $validated['meta']['request_identity'], 0, 16 ) . "\n";

// The English is printed, because a human asked to see whether the provider
// actually produces usable English. It is printed WITHOUT the credential.
echo "\n-- returned English (for human judgement, NOT a publication approval) --\n";
echo 'title:   ' . (string) $validated['translations']['post_title'] . "\n";
echo "content: " . (string) $validated['translations']['post_content'] . "\n";
echo "\nReview status if this became a plan row: REVIEW_REQUIRED\n";
echo "No WordPress content was read or written by this test.\n";

exit( 0 );
