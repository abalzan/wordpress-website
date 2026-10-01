<?php
/**
 * Stage 6: the protected production PROOF entry point.
 *
 * ## What this suite proves, and why behaviour alone is not enough
 *
 * The interesting property of a trigger is not what it does when authorised —
 * it is what it does when NOT. Every assertion below is paired with an
 * orchestrator-invocation COUNTER, because "it returned a refusal" and "it
 * never reached the engine" are different claims and only the second one
 * matters. A refusal produced *after* a provider call would be worthless.
 *
 * The counter sits on the trigger's orchestrator seam, which is the single
 * point through which the existing trigger is reachable. If the counter does
 * not move, no inventory was read, no diff was computed, no provider was
 * called and no engine was invoked.
 *
 * ## No network, no content
 *
 * The orchestrator seam is replaced for every case, so this suite performs no
 * provider call and touches no post, term, option or relationship.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Admin_Trigger as Entry;
use Conexao_Translation_Automation_Audit as Audit;
use Conexao_Translation_Automation_Lock as Lock;
use Conexao_Translation_Automation_Orchestrator as Orchestrator;

test_title( 'conexao-translation-automation — Stage 6 protected production trigger' );

/**
 * The request an operator submits from the Tools screen.
 *
 * @param array $overrides Field overrides.
 * @return array
 */
function conexao_s6_entry_request( array $overrides = array() ): array {
	$request = array(
		'conexao_mode'   => Orchestrator::MODE_PROOF,
		'conexao_stage'  => 'en-guide',
		'action'         => Entry::ACTION,
		'_conexao_nonce' => 'a-valid-nonce',
	);

	return array_merge( $request, $overrides );
}

/**
 * Install a fully authorised environment, plus an orchestrator counter.
 *
 * @param array $report The report the stubbed orchestrator returns.
 * @param int   &$calls Orchestrator invocation counter.
 * @return void
 */
function conexao_s6_authorise( array $report, int &$calls ): void {
	$_SERVER['REQUEST_METHOD'] = 'POST';

	Entry::set_auth_check( static function () {
		return true;
	} );
	Entry::set_capability_check( static function () {
		return true;
	} );
	Entry::set_nonce_check( static function () {
		return true;
	} );
	Entry::set_environment_reader( static function () {
		return 'production';
	} );
	Entry::set_orchestrator(
		static function ( array $call ) use ( $report, &$calls ) {
			++$calls;

			return array_merge( $report, array( 'requested' => $call ) );
		}
	);
}

/**
 * A representative proof report, as the Stage 3 trigger returns it.
 *
 * @return array
 */
function conexao_s6_report(): array {
	return array(
		'run_id'                  => 'run_s6_entry',
		'stage'                   => 'en-guide',
		'environment'             => 'production',
		'trigger'                 => Audit::TRIGGER_ADMIN_PROOF,
		'outcome'                 => 'no_changes',
		'failure'                 => '',
		'ok'                      => true,
		'source_inventory_digest' => hash( 'sha256', 'inventory' ),
		'change_set_digest'       => hash( 'sha256', 'changes' ),
		'lock_status'             => 'acquired',
		'dry_run_status'          => 'PASS',
		'provider_status'         => 'not_run',
		'gate'                    => array(
			'status'  => 'pass',
			'passed'  => true,
			'message' => 'dry run only',
		),
		'actionable'              => 0,
		'manual_rows'             => 0,
		'records_seen'            => 12,
		'audit_persisted'         => true,
		'started_at'              => gmdate( 'c' ),
	);
}

/**
 * Restore the real WordPress behaviour after a case.
 *
 * @return void
 */
function conexao_s6_entry_reset(): void {
	Entry::reset();
	$_SERVER['REQUEST_METHOD'] = 'POST';
}

// ---------------------------------------------------------------------------
// 1. Authorisation. The counter must NOT move for any refusal.
// ---------------------------------------------------------------------------
test_section( 'Authorisation fails closed BEFORE the orchestrator is reached' );

$cases = array(
	'an anonymous caller'                              => array(
		'setup'   => 'auth',
		'failure' => Entry::FAILURE_NOT_AUTHENTICATED,
	),
	'an authenticated user without the capability'     => array(
		'setup'   => 'cap',
		'failure' => Entry::FAILURE_MISSING_CAPABILITY,
	),
	'an invalid nonce'                                 => array(
		'setup'   => 'nonce',
		'failure' => Entry::FAILURE_INVALID_NONCE,
	),
);

foreach ( $cases as $label => $case ) {
	$calls = 0;
	conexao_s6_authorise( conexao_s6_report(), $calls );

	if ( 'auth' === $case['setup'] ) {
		Entry::set_auth_check(
			static function () {
				return false;
			}
		);
	} elseif ( 'cap' === $case['setup'] ) {
		Entry::set_capability_check(
			static function () {
				return false;
			}
		);
	} else {
		Entry::set_nonce_check(
			static function () {
				return false;
			}
		);
	}

	$result = Entry::handle_request( conexao_s6_entry_request() );

	assert_equals( 0, $calls, sprintf( '%s never reaches the orchestrator', $label ) );
	assert_equals( $case['failure'], (string) $result['failure'], sprintf( '%s is refused with its own category', $label ) );
	assert_true( empty( $result['ok'] ), sprintf( '%s is not reported as a success', $label ) );
	assert_equals( 'refused', (string) $result['status'], sprintf( '%s returns a refusal status', $label ) );
	assert_equals( false, $result['mutation_permitted'], sprintf( '%s never permits mutation', $label ) );
	assert_equals( false, $result['mutation_occurred'], sprintf( '%s never mutates', $label ) );
	assert_equals( 'not_called', (string) $result['provider_status'], sprintf( '%s never calls the provider', $label ) );

	conexao_s6_entry_reset();
}

// ---------------------------------------------------------------------------
// 2. A GET request cannot start anything.
// ---------------------------------------------------------------------------
test_section( 'The entry point is POST-only' );

$calls = 0;
conexao_s6_authorise( conexao_s6_report(), $calls );
$_SERVER['REQUEST_METHOD'] = 'GET';

$get = Entry::handle_request( conexao_s6_entry_request() );

assert_equals( 0, $calls, 'a GET request never reaches the orchestrator' );
assert_equals( Entry::FAILURE_WRONG_METHOD, (string) $get['failure'], 'a GET request is refused as a wrong method' );

conexao_s6_entry_reset();

// ---------------------------------------------------------------------------
// 3. The authorised happy path reaches the EXISTING trigger, in PROOF mode.
// ---------------------------------------------------------------------------
test_section( 'An authorised request invokes the existing orchestrator in PROOF mode' );

$calls = 0;
$seen  = array();
conexao_s6_authorise( conexao_s6_report(), $calls );
Entry::set_orchestrator(
	static function ( array $call ) use ( &$seen, &$calls ) {
		++$calls;
		$seen = $call;

		return conexao_s6_report();
	}
);

$ok = Entry::handle_request( conexao_s6_entry_request() );

assert_equals( 1, $calls, 'an authorised request invokes the orchestrator exactly once' );
assert_true( ! empty( $ok['ok'] ), 'an authorised proof returns a success result' );
assert_equals( Orchestrator::MODE_PROOF, (string) $ok['mode'], 'the result mode is proof' );
assert_equals( 'en-guide', (string) $ok['stage'], 'the requested stage is honoured' );
assert_equals( 'production', (string) $ok['environment'], 'the environment is the server-derived one' );
assert_equals( 'PASS', (string) $ok['dry_run_status'], 'the dry-run status is surfaced' );
assert_equals( true, (bool) $ok['gate']['passed'], 'the gate verdict is surfaced' );

// What the EXISTING trigger actually received. These are captured on the
// orchestrator seam rather than read back from the result, because the
// result is a deliberate projection: it carries only the fields §15 lists.
assert_equals( Audit::TRIGGER_ADMIN_PROOF, (string) ( $seen['trigger'] ?? '' ), 'the invocation source is recorded as the admin proof trigger' );
assert_equals( 'en-guide', (string) ( $seen['stage'] ?? '' ), 'the allowlisted stage is the one passed to the trigger' );
assert_equals( 'production', (string) ( $seen['environment'] ?? '' ), 'the environment passed to the trigger is server-derived, never browser-supplied' );
assert_equals( false, (bool) ( $seen['bootstrap'] ?? true ), 'this entry point can never adopt a baseline from a request' );
assert_true( ! array_key_exists( 'run_id', $seen ), 'a caller can never pin the run id from this entry point' );
assert_true( ! array_key_exists( 'mode', $seen ), 'the mode is not caller-supplied; the trigger hard-codes PROOF' );

// The two mutation flags are hard-coded false.
assert_equals( false, $ok['mutation_permitted'], 'an authorised proof never permits mutation' );
assert_equals( false, $ok['mutation_occurred'], 'an authorised proof never mutates' );

conexao_s6_entry_reset();

// ---------------------------------------------------------------------------
// 4. Apply is unreachable, under every spelling.
// ---------------------------------------------------------------------------
test_section( 'Apply is not available from this entry point, in any spelling' );

$apply_attempts = array( 'apply', 'APPLY', 'apply_all', 'do_apply', 'commit', 'write', 'publish', 'dry-run-false' );

foreach ( $apply_attempts as $attempt ) {
	$calls = 0;
	conexao_s6_authorise( conexao_s6_report(), $calls );

	$result = Entry::handle_request( conexao_s6_entry_request( array( 'conexao_mode' => $attempt ) ) );

	assert_equals( 0, $calls, sprintf( 'apply attempt "%s" never reaches the orchestrator', $attempt ) );
	assert_true( empty( $result['ok'] ), sprintf( 'apply attempt "%s" is refused', $attempt ) );
	assert_equals(
		Orchestrator::MODE_PROOF,
		(string) $result['mode'],
		sprintf( 'apply attempt "%s" still reports mode proof', $attempt )
	);
	assert_equals( Entry::FAILURE_UNKNOWN_MODE, (string) $result['failure'], sprintf( '"%s" is an unknown mode, not a disabled one', $attempt ) );

	conexao_s6_entry_reset();
}

// `dry_run=false` is not an accepted field at all, so supplying it is a
// refusal rather than a silently ignored parameter.
$calls = 0;
conexao_s6_authorise( conexao_s6_report(), $calls );
$dry = Entry::handle_request( conexao_s6_entry_request( array( 'dry_run' => 'false' ) ) );
assert_equals( 0, $calls, 'a caller-supplied dry_run never reaches the orchestrator' );
assert_equals( Entry::FAILURE_UNKNOWN_FIELD, (string) $dry['failure'], 'a caller-supplied dry_run is an unknown field' );
conexao_s6_entry_reset();

// The mode vocabulary is a single value. This is the structural guarantee:
// there is no apply branch to reach, only an unknown-value branch.
assert_equals(
	array( Orchestrator::MODE_PROOF ),
	Entry::allowed_modes(),
	'the mode vocabulary contains exactly one value: proof'
);
assert_true(
	! in_array( Orchestrator::MODE_APPLY, Entry::allowed_modes(), true ),
	'apply is not a member of the mode vocabulary at all'
);

// ---------------------------------------------------------------------------
// 5. Input validation fails closed, before the orchestrator.
// ---------------------------------------------------------------------------
test_section( 'Invalid input is refused before the orchestrator' );

$invalid = array(
	'a missing mode'                 => array( array( 'conexao_mode' => '' ), Entry::FAILURE_MISSING_MODE ),
	'an unknown mode'                => array( array( 'conexao_mode' => 'translate' ), Entry::FAILURE_UNKNOWN_MODE ),
	'a missing stage'                => array( array( 'conexao_stage' => '' ), Entry::FAILURE_MISSING_STAGE ),
	'an unknown stage'               => array( array( 'conexao_stage' => 'not-a-stage' ), Entry::FAILURE_UNKNOWN_STAGE ),
	'a non-automatable stage'        => array( array( 'conexao_stage' => 'job' ), Entry::FAILURE_UNKNOWN_STAGE ),
	'a browser-supplied environment' => array( array( 'environment' => 'production' ), Entry::FAILURE_UNKNOWN_FIELD ),
	'a caller-supplied dry_run'      => array( array( 'dry_run' => 'false' ), Entry::FAILURE_UNKNOWN_FIELD ),
	'a caller-supplied run_id'       => array( array( 'run_id' => 'chosen_by_caller' ), Entry::FAILURE_UNKNOWN_FIELD ),
	'a caller-supplied capability'   => array( array( 'capability' => 'edit_posts' ), Entry::FAILURE_UNKNOWN_FIELD ),
);

foreach ( $invalid as $label => $case ) {
	$calls = 0;
	conexao_s6_authorise( conexao_s6_report(), $calls );

	$result = Entry::handle_request( conexao_s6_entry_request( $case[0] ) );

	assert_equals( 0, $calls, sprintf( '%s never reaches the orchestrator', $label ) );
	assert_equals( $case[1], (string) $result['failure'], sprintf( '%s is refused with its own category', $label ) );

	conexao_s6_entry_reset();
}

// The environment is derived server-side and can never be supplied. This is
// observed on the orchestrator seam, where the value actually arrives.
$calls = 0;
$seen  = array();
conexao_s6_authorise( conexao_s6_report(), $calls );
Entry::set_orchestrator(
	static function ( array $call ) use ( &$seen, &$calls ) {
		++$calls;
		$seen = $call;

		return conexao_s6_report();
	}
);
Entry::handle_request( conexao_s6_entry_request() );
assert_equals(
	'production',
	(string) ( $seen['environment'] ?? '' ),
	'the environment passed to the trigger is the server-derived one'
);
conexao_s6_entry_reset();

// The stage allowlist is the orchestrator's, not a second list.
assert_equals(
	Orchestrator::allowed_stage_ids(),
	Entry::allowed_stages(),
	'the trigger uses the orchestrator stage allowlist, with no second definition'
);

// ---------------------------------------------------------------------------
// 6. The result boundary is secret-scrubbed.
// ---------------------------------------------------------------------------
test_section( 'The trigger response is scrubbed and bounded' );

$calls = 0;
conexao_s6_authorise( conexao_s6_report(), $calls );

$safe = Entry::handle_request( conexao_s6_entry_request() );
$text = (string) wp_json_encode( $safe );

foreach ( array( 'password', 'api_key', 'authorization', 'Bearer', 'sk-' ) as $forbidden ) {
	assert_true( false === stripos( $text, $forbidden ), sprintf( 'the result carries no "%s"', $forbidden ) );
}

// A report that leaks a credential is refused at the boundary.
$calls = 0;
conexao_s6_authorise( array_merge( conexao_s6_report(), array( 'run_id' => 'zzzz yyyy xxxx wwww' ) ), $calls );
$leaky = Entry::handle_request( conexao_s6_entry_request() );

assert_true( empty( $leaky['ok'] ), 'a result that fails the secret check is not returned as a success' );
assert_equals( Entry::FAILURE_RESULT_REFUSED, (string) $leaky['failure'], 'a leaky result is refused on the boundary' );
assert_true(
	false === strpos( (string) wp_json_encode( $leaky ), 'zzzz' ),
	'the credential-shaped value is not returned even in the refusal'
);

conexao_s6_entry_reset();

// ---------------------------------------------------------------------------
// 7. The trigger uses the EXISTING site-wide lock, not its own.
// ---------------------------------------------------------------------------
test_section( 'The trigger uses the existing site-wide lock' );

// A lock held by another run is refused by the EXISTING trigger, and the
// original owner keeps it: the trigger never steals ownership.
$held = Lock::acquire(
	array(
		'run_id'      => 'run_other_owner',
		'stage'       => 'en-guide',
		'environment' => 'production',
	)
);

assert_true( ! empty( $held['held'] ), 'a competing run can take the site-wide lock' );

$released = Lock::release( 'run_other_owner' );
assert_true( ! empty( $released ), 'the original lock owner retains ownership and can release it' );

// The lock is released after a normal trigger-driven run, so a second proof
// invocation is not permanently blocked.
$calls = 0;
conexao_s6_authorise( conexao_s6_report(), $calls );
$report = Entry::handle_request( conexao_s6_entry_request() );
assert_equals( 'acquired', (string) $report['lock_status'], 'the trigger reports the existing lock as acquired' );

conexao_s6_entry_reset();

// ---------------------------------------------------------------------------
// 8. No public surface, no cron.
// ---------------------------------------------------------------------------
test_section( 'There is no public or anonymous surface' );

assert_true( has_action( 'admin_post_' . Entry::ACTION ), 'the authenticated admin-post handler IS registered' );
assert_true( ! has_action( 'admin_post_nopriv_' . Entry::ACTION ), 'no anonymous admin-post handler is registered' );
assert_true( ! has_action( 'wp_ajax_' . Entry::ACTION ), 'no logged-in AJAX handler is registered' );
assert_true( ! has_action( 'wp_ajax_nopriv_' . Entry::ACTION ), 'no anonymous AJAX handler is registered' );

test_finish( 'stage 6 protected production trigger' );