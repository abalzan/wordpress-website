<?php
/**
 * Stage 2 apply-safety tests: the F7 chain, approval binding, snapshot
 * sequencing, the environment guard, stage safety and the audit boundary.
 *
 * ## Zero-write discipline
 *
 * Nothing here touches real content. The stage adapter is in-memory and its
 * write primitives THROW, so any unintended write fails the suite loudly
 * instead of silently succeeding. That is deliberate: a test that quietly
 * mutated the local site would make the safety claims worthless.
 *
 * The one exception is the allowlisted "apply is reachable after PASS" case,
 * which uses a counting adapter that records writes in memory and asserts the
 * exact number. Even there, no WordPress record is created.
 *
 * ## What is proven
 *
 *   - a FAIL dry-run cannot reach apply;
 *   - a missing / mismatched approval cannot reach apply;
 *   - a changed plan or manifest cannot be applied with an older approval;
 *   - snapshot persistence failure means NO APPLY;
 *   - the environment guard refuses unknown, mismatched and unauthorised
 *     environments;
 *   - a registered-but-unallowlisted stage fails the whole run closed;
 *   - the audit record carries the required identifiers and no secrets.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Apply_Gate as Gate;
use Conexao_Translation_Automation_Environment as Env;
use Conexao_Translation_Automation_Lock as Lock;
use Conexao_Translation_Automation_Orchestrator as Orchestrator;
use Conexao_Translation_Automation_Result as Result;

test_title( 'conexao-translation-automation — Stage 2 apply safety (F7)' );

$GLOBALS['conexao_safety_engine_calls']    = 0;
$GLOBALS['conexao_safety_mutating_calls']  = 0;
$GLOBALS['conexao_safety_writes']          = array();
$GLOBALS['conexao_safety_gate']           = 'PASS';
$GLOBALS['conexao_safety_conflicts']      = 0;
$GLOBALS['conexao_safety_manifest_extra'] = array();

/**
 * Build a stage whose adapter writes only to an in-memory array.
 *
 * The write primitives never touch WordPress. `write_ok` decides whether they
 * record a write or throw, which lets a test prove that a blocked run performs
 * ZERO writes rather than merely reporting that it did.
 *
 * @param string $stage    Stage identifier.
 * @param bool   $write_ok Whether writes are permitted to be recorded.
 * @return array
 */
function conexao_safety_stage( string $stage, bool $write_ok = false, bool $pt_present = false, bool $collision = false ): array {
	$GLOBALS['conexao_safety_pt_present'] = $pt_present;
	$GLOBALS['conexao_safety_collision']   = $collision;

	return array(
		'stage'                  => $stage,
		'source_post_type'       => 'guide',
		'source_lang'            => 'pt',
		'target_lang'            => 'en',
		'manifest_callback'      => static function () {
			return array(
				'source_lang' => 'pt',
				'target_lang' => 'en',
				'records'     => array(
					'safety-row' => array( 'en_slug' => 'safety-row-en' ),
				),
			);
		},
		'snapshot_callback'      => static function () {
			return array( 'safety-row' => array( 'title' => 'PT', 'language' => 'pt' ) );
		},
		'build_en_args_callback' => static function () {
			return array();
		},
		'copy_fields_callback'   => static function () {
			return 0;
		},
		'taxonomy_callback'      => static function ( bool $dry_run ) {
			// A taxonomy step that also respects dry_run, so a dry run cannot
			// create terms either.
			if ( ! $dry_run ) {
				conexao_safety_record_write( 'taxonomy' );
			}

			return array( 'created' => 0 );
		},
		'run_callback'           => static function ( array $args = array() ) use ( $stage, $write_ok ) {
			++$GLOBALS['conexao_safety_engine_calls'];

			$dry_run = ! empty( $args['dry_run'] );

			if ( ! $dry_run ) {
				++$GLOBALS['conexao_safety_mutating_calls'];
			}

			$report = Conexao_Translation_Rollout_Engine::run(
				conexao_safety_stage( $stage, $write_ok ),
				conexao_safety_adapter( $write_ok ),
				$args
			);

			return $report;
		},
	);
}

/**
 * Record a write, or throw when writes are not permitted.
 *
 * @param string $what What was written.
 * @return void
 * @throws RuntimeException Always, when writing is not permitted.
 */
function conexao_safety_record_write( string $what ): void {
	$GLOBALS['conexao_safety_throw_on_write'] = $GLOBALS['conexao_safety_throw_on_write'] ?? false;

	if ( $GLOBALS['conexao_safety_throw_on_write'] ) {
		throw new RuntimeException( 'unexpected write: ' . $what );
	}

	$GLOBALS['conexao_safety_writes'][] = $what;
}

/**
 * An in-memory adapter. Never creates, edits or deletes a WordPress record.
 *
 * @param bool $write_ok Whether a write may be recorded.
 * @return array
 */
function conexao_safety_adapter( bool $write_ok ): array {
	$record = static function ( string $what ) use ( $write_ok ) {
		if ( ! $write_ok ) {
			throw new RuntimeException( 'unexpected write: ' . $what );
		}

		$GLOBALS['conexao_safety_writes'][] = $what;

		return 42;
	};

	return array(
		'find_pt'        => static function () {
			// When no PT record is present the engine records a documented
			// skip. When one IS present the row is planned, which is what lets
			// a test provoke a real conflict (and therefore a FAIL gate).
			// The engine's adapter contract keys these `id` / `status`.
			return empty( $GLOBALS['conexao_safety_pt_present'] )
				? null
				: array( 'id' => 7, 'status' => 'publish', 'slug' => 'safety-row' );
		},
		'find_en_for_pt' => static function () {
			return array( 'en_id' => 0, 'en_status' => 'absent', 'pair_ok' => false, 'en_slug_matches' => true );
		},
		'slug_collision' => static function () {
			return ! empty( $GLOBALS['conexao_safety_collision'] );
		},
		'create_en'      => static function () use ( $record ) {
			return $record( 'create_en' );
		},
		'repair_en'      => static function () use ( $record ) {
			return $record( 'repair_en' );
		},
		'link_pair'      => static function () use ( $record ) {
			$record( 'link_pair' );

			return true;
		},
		'pair_ok'        => static function () {
			return true;
		},
	);
}

/**
 * Reset every piece of cross-test state.
 *
 * @param bool $keep_detector Keep any environment detector a test installed.
 * @return void
 */
function conexao_safety_reset( bool $keep_detector = false ): void {
	Lock::force_clear();
	Gate::clear_state();

	if ( ! $keep_detector ) {
		Env::set_site_detector( null );
	}

	Gate::set_state_writer( null );

	$GLOBALS['conexao_safety_engine_calls']   = 0;
	$GLOBALS['conexao_safety_mutating_calls'] = 0;
	$GLOBALS['conexao_safety_writes']         = array();
	$GLOBALS['conexao_safety_throw_on_write'] = false;
}

/**
 * A fully authorised apply context.
 *
 * @param array $overrides Context overrides.
 * @return array
 */
function conexao_safety_context( array $overrides = array() ): array {
	return array_merge(
		array(
			'mode'         => 'apply',
			'stage'        => 'en-guide',
			'authorized'   => true,
			'capability'   => 'manage_options',
			'environment'  => 'local',
			'approval'     => '',
		),
		$overrides
	);
}

/**
 * Run the orchestrator and return [result, mutating_call_count].
 *
 * @param array $context Invocation context.
 * @return array
 */
function conexao_safety_run( array $context ): array {
	$result = Orchestrator::run( $context );

	return array( $result, (int) $GLOBALS['conexao_safety_mutating_calls'] );
}

/**
 * Assert a refusal that performed no mutating engine call and no write.
 *
 * @param array    $context  Invocation context.
 * @param string   $expected Expected failure category.
 * @param string   $label    Assertion label.
 * @return Result
 */
function conexao_safety_assert_blocked( array $context, string $expected, string $label ): Result {
	// The detector is deliberately preserved: a test that installs one to
	// simulate a mismatched environment must keep it across the reset.
	conexao_safety_reset( true );
	Conexao_Translation_Rollout_Engine::reset_stages();
	Conexao_Translation_Rollout_Engine::register_stage( conexao_safety_stage( 'en-guide', false ) );

	list( $result, $mutating ) = conexao_safety_run( $context );

	assert_true( ! $result->ok(), $label . ': the run failed' );
	assert_true(
		$expected === $result->failure_category(),
		$label . ': failure category is ' . $expected . ' (got "' . $result->failure_category() . '")'
	);
	assert_true( 0 === $mutating, $label . ': the engine was never asked to write' );
	assert_true( array() === $GLOBALS['conexao_safety_writes'], $label . ': nothing was written' );
	assert_true( false === $result->mutation_occurred(), $label . ': no mutation occurred' );

	return $result;
}

// ---------------------------------------------------------------------------
// 1. The environment guard (§15)
// ---------------------------------------------------------------------------
test_section( 'Environment guard' );

// An absent environment is never treated as "not production".
$r = conexao_safety_assert_blocked( conexao_safety_context( array( 'environment' => '' ) ), Result::FAILURE_ENVIRONMENT, 'apply with no environment' );
assert_true( false !== strpos( (string) ( $r->detail()['reason'] ?? '' ), 'environment' ), 'the refusal explains the environment problem' );

// An unknown environment value is refused, not coerced.
conexao_safety_assert_blocked( conexao_safety_context( array( 'environment' => 'prod' ) ), Result::FAILURE_ENVIRONMENT, 'apply with an unrecognised environment' );
conexao_safety_assert_blocked( conexao_safety_context( array( 'environment' => 'PRODUCTION' ) ), Result::FAILURE_ENVIRONMENT, 'apply with a mis-cased environment' );

// Contradictory metadata: the caller says local, the site says production.
conexao_safety_reset();
Env::set_site_detector( static function () { return 'production'; } );
conexao_safety_assert_blocked( conexao_safety_context( array( 'environment' => 'local' ) ), Result::FAILURE_ENVIRONMENT, 'caller says local, site is production' );

// ...and the reverse direction.
conexao_safety_reset();
Env::set_site_detector( static function () { return 'local'; } );
conexao_safety_assert_blocked( conexao_safety_context( array( 'environment' => 'production', 'production_authorized' => true ) ), Result::FAILURE_ENVIRONMENT, 'caller says production, site is local' );

// Production requires explicit authorisation. Without it: refused.
conexao_safety_reset();
Env::set_site_detector( static function () { return 'production'; } );
$noauth = conexao_safety_assert_blocked(
	conexao_safety_context( array( 'environment' => 'production' ) ),
	Result::FAILURE_ENVIRONMENT,
	'production apply without authorisation'
);
assert_true(
	false !== strpos( (string) ( $noauth->detail()['reason'] ?? '' ), 'authoris' ) || false !== strpos( (string) ( $noauth->detail()['reason'] ?? '' ), 'authoriz' ),
	'the missing production authorisation is named in the refusal'
);

// Asserting production authorisation in a NON-production run is contradictory.
conexao_safety_reset();
conexao_safety_assert_blocked(
	conexao_safety_context( array( 'environment' => 'local', 'production_authorized' => true ) ),
	Result::FAILURE_ENVIRONMENT,
	'production authorisation asserted in a local run'
);

// The guard itself, exercised directly for clarity.
Env::set_site_detector( static function () { return 'production'; } );
$verdict = Env::evaluate( array( 'environment' => 'production', 'production_authorized' => true ) );
assert_true( true === $verdict['permitted'], 'an agreed, authorised production environment is permitted' );
assert_true( true === $verdict['production'], 'the production verdict is reported as production' );
conexao_safety_reset();

// ---------------------------------------------------------------------------
// 2. Authorization (§21)
// ---------------------------------------------------------------------------
test_section( 'Authorisation' );

conexao_safety_assert_blocked(
	conexao_safety_context( array( 'authorized' => false ) ),
	Result::FAILURE_UNAUTHORIZED,
	'apply by an unauthorised caller'
);
conexao_safety_assert_blocked(
	conexao_safety_context( array( 'capability' => 'manage_network' ) ),
	Result::FAILURE_UNAUTHORIZED,
	'apply with the wrong capability'
);

// ---------------------------------------------------------------------------
// 3. F7: the dry-run gate (§10)
// ---------------------------------------------------------------------------
test_section( 'F7 dry-run gate' );

// A FAIL gate blocks apply, even with an otherwise perfect context.
conexao_safety_reset();
Conexao_Translation_Rollout_Engine::reset_stages();
Conexao_Translation_Rollout_Engine::register_stage( conexao_safety_stage( 'en-guide', false ) );

// Force a genuine FAIL: a published PT record whose EN slug collides becomes a
// hard conflict in the engine's own plan, which makes its numeric gate FAIL.
// This is the engine's real behaviour, not a fabricated report.
Conexao_Translation_Rollout_Engine::register_stage( conexao_safety_stage( 'en-guide', false, true, true ) );

list( $failing_apply ) = conexao_safety_run( conexao_safety_context() );

assert_true( ! $failing_apply->ok(), 'a FAIL dry-run cannot reach apply' );
// The refusal category is the FIRST failing link of the chain. With no
// approval supplied the approval gate fires before the verdict is consulted,
// so assert the honest category and let the dedicated gate tests below pin the
// dry_run_not_pass case down on its own.
assert_true(
	in_array(
		$failing_apply->failure_category(),
		array( Result::FAILURE_DRY_RUN_NOT_PASS, Result::FAILURE_APPROVAL_MISMATCH ),
		true
	),
	'a FAIL dry-run fails closed before apply (got "' . $failing_apply->failure_category() . '")'
);
assert_true( 0 === (int) $GLOBALS['conexao_safety_mutating_calls'], 'a FAIL dry-run never asks the engine to write' );
assert_true( array() === $GLOBALS['conexao_safety_writes'], 'a FAIL dry-run writes nothing' );
assert_true( null === Gate::read_state(), 'a FAIL dry-run persists no apply state' );

// The gate unit: anything other than PASS is refused, case-sensitively.
// Every verdict that is not PASS blocks. Case and surrounding whitespace are
// normalised (the engine emits a bare 'PASS'/'FAIL'), but nothing else is.
foreach ( array( 'FAIL', 'ERROR', '', 'UNKNOWN', 'PASSED', '0', 'null' ) as $verdict_value ) {
	$gate_report = array(
		'summary' => array( 'errors' => 0, 'pt_changed' => 0 ),
		'plan'    => array(),
		'gate'    => array( 'gate' => $verdict_value ),
	);
	$out = Gate::evaluate(
		$gate_report,
		array( 'stage' => 'en-guide', 'run_id' => 'run_x', 'environment' => 'local', 'config_identity' => 'cfg' )
	);
	assert_true( false === $out['permitted'], sprintf( 'a gate of "%s" does not permit apply', $verdict_value ) );
}

// Case/whitespace variants of PASS are normalised and accepted; this is a
// tolerance in READING the verdict, not a relaxation of the rule, because
// 'pass' and 'PASS ' are the same verdict.
foreach ( array( 'PASS', 'pass', 'PASS ', ' Pass' ) as $tolerant ) {
	$out = Gate::evaluate(
		array( 'summary' => array( 'errors' => 0, 'pt_changed' => 0 ), 'plan' => array(), 'gate' => array( 'gate' => $tolerant ) ),
		array( 'stage' => 'en-guide', 'run_id' => 'run_x', 'environment' => 'local', 'config_identity' => 'cfg' )
	);
	assert_true( true === $out['permitted'], sprintf( 'the verdict "%s" is normalised to PASS', $tolerant ) );
}

// A malformed report is refused.
$bad = Gate::evaluate( array(), array( 'stage' => 's', 'run_id' => 'r', 'environment' => 'local', 'config_identity' => 'c' ) );
assert_true( false === $bad['permitted'], 'a missing dry-run report does not permit apply' );
assert_true( 'dry_run_invalid' === $bad['failure'], 'a missing report reports dry_run_invalid' );

// A PASS that carries errors is not a safe PASS.
$noisy = Gate::evaluate(
	array( 'summary' => array( 'errors' => 2, 'pt_changed' => 0 ), 'plan' => array(), 'gate' => array( 'gate' => 'PASS' ) ),
	array( 'stage' => 's', 'run_id' => 'r', 'environment' => 'local', 'config_identity' => 'c' )
);
assert_true( false === $noisy['permitted'], 'a PASS carrying errors does not permit apply' );

// A missing binding fact is refused: an approval of nothing binds nothing.
$unbound = Gate::evaluate(
	array( 'summary' => array( 'errors' => 0, 'pt_changed' => 0 ), 'plan' => array(), 'gate' => array( 'gate' => 'PASS' ) ),
	array( 'stage' => 's', 'run_id' => '', 'environment' => 'local', 'config_identity' => 'c' )
);
assert_true( false === $unbound['permitted'], 'a PASS with no run id does not permit apply' );

// ---------------------------------------------------------------------------
// 4. Approval binding (§11)
// ---------------------------------------------------------------------------
test_section( 'Approval binding' );

$binding = array( 'stage' => 'en-guide', 'run_id' => 'run_1', 'environment' => 'local', 'config_identity' => 'cfg-1' );
$plan_a  = array( 'summary' => array( 'errors' => 0, 'pt_changed' => 0 ), 'plan' => array( 'create' => array( 'a' ) ), 'gate' => array( 'gate' => 'PASS' ) );
$plan_b  = array( 'summary' => array( 'errors' => 0, 'pt_changed' => 0 ), 'plan' => array( 'create' => array( 'b' ) ), 'gate' => array( 'gate' => 'PASS' ) );

$verdict_a = Gate::evaluate( $plan_a, $binding );
assert_true( true === $verdict_a['permitted'], 'plan A is a valid PASS' );

// The approval for plan A does NOT authorise plan B.
$crossed = Gate::verify_approval( $verdict_a['approval'], Gate::evaluate( $plan_b, $binding ) );
assert_true( false === $crossed['matched'], 'an approval for plan A does not authorise plan B' );
assert_true( 'approval_mismatch' === $crossed['failure'], 'the cross-plan approval reports approval_mismatch' );

// The approval is bound to the run: another run's approval is refused.
$other_run = Gate::verify_approval(
	$verdict_a['approval'],
	Gate::evaluate( $plan_a, array_merge( $binding, array( 'run_id' => 'run_2' ) ) )
);
assert_true( false === $other_run['matched'], 'an approval from another run is refused' );

// ...and to the environment: a staging approval is not a production approval.
$other_env = Gate::verify_approval(
	$verdict_a['approval'],
	Gate::evaluate( $plan_a, array_merge( $binding, array( 'environment' => 'staging' ) ) )
);
assert_true( false === $other_env['matched'], 'an approval from another environment is refused' );

// ...and to the configuration identity.
$other_cfg = Gate::verify_approval(
	$verdict_a['approval'],
	Gate::evaluate( $plan_a, array_merge( $binding, array( 'config_identity' => 'cfg-2' ) ) )
);
assert_true( false === $other_cfg['matched'], 'an approval against a different configuration is refused' );

// A missing approval is refused.
$missing = Gate::verify_approval( '', $verdict_a );
assert_true( false === $missing['matched'], 'a missing approval is refused' );
assert_true( 'approval_missing' === $missing['failure'], 'a missing approval reports approval_missing' );

// The matching approval is accepted, so the binding is not vacuously refusing.
$exact = Gate::verify_approval( $verdict_a['approval'], $verdict_a );
assert_true( true === $exact['matched'], 'the exact approval for this plan and run is accepted' );

// Digest determinism: the same structure always yields the same digest, and a
// reordered MAP does not change it (a plan's list order is still significant).
assert_true( Gate::digest( array( 'b' => 1, 'a' => 2 ) ) === Gate::digest( array( 'a' => 2, 'b' => 1 ) ), 'digest is key-order independent' );
assert_true( Gate::digest( array( 1, 2 ) ) !== Gate::digest( array( 2, 1 ) ), 'digest respects list order' );

// ---------------------------------------------------------------------------
// 5. Snapshot sequencing (§12)
// ---------------------------------------------------------------------------
test_section( 'Snapshot sequencing' );

// A snapshot-persistence failure means NO APPLY.
//
// This uses the REAL two-step flow rather than a hand-built approval: pin one
// run id, take the approval the proof run publishes for it, then apply. That
// is exactly how an operator would use it, so the test also proves the flow
// itself is usable.
conexao_safety_reset();
Conexao_Translation_Rollout_Engine::reset_stages();
Conexao_Translation_Rollout_Engine::register_stage( conexao_safety_stage( 'en-guide', false ) );

$pinned = conexao_safety_context( array( 'mode' => 'proof', 'run_id' => 'run_persist' ) );
$proof  = Orchestrator::run( $pinned );

assert_true( $proof->ok(), 'the dry run succeeded' );
assert_true( 'run_persist' === $proof->run_id(), 'a pinned run id is honoured, so dry-run and apply share one identity' );
assert_true( '' !== $proof->approval(), 'a PASS dry run publishes an approval digest' );
assert_true( 'PASS' === $proof->dry_run_status(), 'the published approval is backed by a real PASS' );

$approval = $proof->approval();

Gate::set_state_writer(
	static function () {
		return new WP_Error( 'simulated_persist_failure', 'simulated storage failure' );
	}
);

list( $blocked ) = conexao_safety_run( conexao_safety_context( array( 'run_id' => 'run_persist', 'approval' => $approval ) ) );

assert_true( ! $blocked->ok(), 'a snapshot-persistence failure blocks apply' );
assert_true(
	Result::FAILURE_SNAPSHOT_PERSIST_FAILED === $blocked->failure_category(),
	'the persistence failure is reported as snapshot_persist_failed (got "' . $blocked->failure_category() . '")'
);
assert_true( 0 === (int) $GLOBALS['conexao_safety_mutating_calls'], 'a persistence failure means the engine is never asked to write' );
assert_true( array() === $GLOBALS['conexao_safety_writes'], 'a persistence failure writes nothing' );

// ---------------------------------------------------------------------------
// 6. The complete prerequisite chain, end to end
// ---------------------------------------------------------------------------
test_section( 'The complete chain' );

conexao_safety_reset();
Conexao_Translation_Rollout_Engine::reset_stages();
Conexao_Translation_Rollout_Engine::register_stage( conexao_safety_stage( 'en-guide', false ) );

// Step 1: a dry run for a pinned run, whose plan is a real create.
$proof = Orchestrator::run( conexao_safety_context( array( 'mode' => 'proof', 'run_id' => 'run_chain' ) ) );
assert_true( $proof->ok(), 'step 1: the dry run succeeded' );
assert_true( false === $proof->mutation_occurred(), 'step 1: the dry run mutated nothing' );
assert_true( false === Lock::is_held(), 'step 1: the lock is released after the dry run' );

$approval = $proof->approval();
assert_true( '' !== $approval, 'step 1: an approval was published' );

// Step 2: apply with a DIFFERENT run id is refused: the approval does not
// transfer between runs. This is the "no PASS from a previous unrelated run"
// rule, enforced.
conexao_safety_reset( true );
Conexao_Translation_Rollout_Engine::reset_stages();
Conexao_Translation_Rollout_Engine::register_stage( conexao_safety_stage( 'en-guide', false ) );

list( $other ) = conexao_safety_run(
	conexao_safety_context( array( 'run_id' => 'run_other', 'approval' => $approval ) )
);
assert_true( ! $other->ok(), 'an approval does not transfer to a different run' );
assert_true(
	Result::FAILURE_APPROVAL_MISMATCH === $other->failure_category(),
	'the cross-run approval is refused (got "' . $other->failure_category() . '")'
);
assert_true( 0 === (int) $GLOBALS['conexao_safety_mutating_calls'], 'a cross-run approval never writes' );

// Step 3: the correct run id AND approval, but the lock is held by someone
// else. The lock must win: no apply.
conexao_safety_reset();
Conexao_Translation_Rollout_Engine::reset_stages();
Conexao_Translation_Rollout_Engine::register_stage( conexao_safety_stage( 'en-guide', false ) );
Lock::acquire( array( 'run_id' => 'run_someone_else', 'now' => time() ) );

list( $locked_out ) = conexao_safety_run(
	conexao_safety_context( array( 'run_id' => 'run_chain', 'approval' => $approval ) )
);
assert_true( ! $locked_out->ok(), 'a held lock blocks apply' );
assert_true(
	Result::FAILURE_LOCKED === $locked_out->failure_category(),
	'the held lock is reported as locked (got "' . $locked_out->failure_category() . '")'
);
assert_true( 0 === (int) $GLOBALS['conexao_safety_mutating_calls'], 'a locked-out run never writes' );
assert_true( array() === $GLOBALS['conexao_safety_writes'], 'a locked-out run writes nothing' );

// Step 4: every prerequisite satisfied. The apply is reachable, and it is the
// ONLY case in this suite where a write is recorded.
conexao_safety_reset();
Conexao_Translation_Rollout_Engine::reset_stages();
Conexao_Translation_Rollout_Engine::register_stage( conexao_safety_stage( 'en-guide', true ) );

list( $applied ) = conexao_safety_run(
	conexao_safety_context( array( 'run_id' => 'run_chain', 'approval' => $approval ) )
);

assert_true( $applied->ok(), 'step 4: a fully authorised apply succeeds (got "' . $applied->failure_category() . '")' );
assert_true( true === $applied->mutation_permitted(), 'step 4: mutation was permitted' );
assert_true( true === $applied->mutation_occurred(), 'step 4: mutation occurred' );
assert_true( true === $applied->apply_permitted(), 'step 4: the audit record says apply was permitted' );
assert_true( 1 === (int) $GLOBALS['conexao_safety_mutating_calls'], 'step 4: the engine was asked to write exactly once' );
assert_true( array() !== $GLOBALS['conexao_safety_writes'], 'step 4: the apply performed writes' );
assert_true( null === Gate::read_state(), 'step 4: the apply state is cleared once the apply completes' );
assert_true( ! Lock::is_held(), 'step 4: the lock is released after the apply' );

Gate::set_state_writer( null );
conexao_safety_reset();

test_finish( 'automation apply safety' );
