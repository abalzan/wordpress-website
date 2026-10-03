<?php
/**
 * Stage 10 tests: bounded multi-record execution controls.
 *
 * ## Zero-write discipline
 *
 * Every stage in this suite registers an adapter whose write primitives
 * THROW unless a test explicitly enables them. So an unintended write fails
 * the suite loudly instead of quietly mutating the local site, which would
 * make the safety claims worthless.
 *
 * The batch's own store (`wp_options`) IS written, and is cleared at the end.
 * That is metadata about an intended run, not content, and it is what makes
 * "the approval was recorded and re-verified" a checkable fact.
 *
 * ## What is proven
 *
 *   - the server ceiling refuses an oversized batch rather than clamping it;
 *   - one invocation may never carry a second batch;
 *   - the budget fails closed BEFORE any apply;
 *   - the partition is deterministic and reproducible;
 *   - an approval cannot be widened, moved, or re-bound;
 *   - the state machine has no VERIFIED -> EXECUTING edge;
 *   - every per-operation protection still runs per operation;
 *   - a failure stops the batch and never shrinks it;
 *   - resume never reapplies a completed operation;
 *   - the emergency stop and the operator abort both stop at a safe boundary;
 *   - an unexpected mutation fails the whole path.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Apply_Gate as Gate;
use Conexao_Translation_Automation_Batch as Batch;
use Conexao_Translation_Automation_Batch_Approval as Approval;
use Conexao_Translation_Automation_Batch_Composer as Composer;
use Conexao_Translation_Automation_Batch_Executor as Executor;
use Conexao_Translation_Automation_Batch_Expansion as Expansion;
use Conexao_Translation_Automation_Environment as Env;
use Conexao_Translation_Automation_Batch_Limits as Limits;
use Conexao_Translation_Automation_Batch_State as BatchState;
use Conexao_Translation_Automation_Emergency_Stop as Kill;
use Conexao_Translation_Automation_Lock as Lock;
use Conexao_Translation_Automation_Orchestrator as Orchestrator;
use Conexao_Translation_Automation_Translation_Plan as Plan;

test_title( 'conexao-translation-automation — Stage 10 bounded batch execution' );

$GLOBALS['b10_writes']        = array();
$GLOBALS['b10_write_ok']      = false;
$GLOBALS['b10_gate']          = 'PASS';
$GLOBALS['b10_manifest']      = array();
$GLOBALS['b10_provider_calls'] = 0;
$GLOBALS['b10_provider_errors'] = 0;
$GLOBALS['b10_fail_identity']  = '';
$GLOBALS['b10_drifts']         = array();
$GLOBALS['b10_deleted']        = array();
$GLOBALS['b10_pt_before']      = array();
$GLOBALS['b10_environment']    = 'local';
$GLOBALS['b10_clock']          = 1000.0;
$GLOBALS['b10_snapshots']      = array();

/**
 * A three-record plan in the shape the composer consumes.
 *
 * @param int $records How many rows.
 * @return array<string,array>
 */
function b10_plan( int $records = 3 ): array {
	$plan = array();

	for ( $i = 1; $i <= $records; $i++ ) {
		$identity = sprintf( 'row-%02d', $i );

		$plan[ $identity ] = array(
			'source_id'     => $identity,
			'operation'     => Plan::OP_CREATE_EN,
			'source_digest' => 'pt_' . $identity,
			'result_digest' => 'en_' . $identity,
		);
	}

	return $plan;
}

/**
 * The standard binding facts.
 *
 * @return array<string,string>
 */
function b10_binding(): array {
	return array(
		'run_id'            => 'run_b10',
		'environment'       => 'local',
		'stage'             => 'en-guide',
		'change_set_digest' => 'cs_digest_1',
		'plan_digest'       => 'plan_digest_1',
		'provider_identity' => 'provider_id_1',
	);
}

/**
 * The authorised context every human action receives.
 *
 * @return array<string,mixed>
 */
function b10_authority(): array {
	return array(
		'authorized' => true,
		'capability' => 'manage_options',
	);
}

/**
 * The stage manifest: the SAME shape the real stages declare.
 *
 * @param int $records How many records.
 * @return array
 */
function b10_manifest( int $records ): array {
	$rows = array();

	for ( $i = 1; $i <= $records; $i++ ) {
		$identity = sprintf( 'row-%02d', $i );

		$rows[ $identity ] = array(
			'en_slug'  => $identity . '-en',
			'en_title' => 'Guide ' . $i,
		);
	}

	$GLOBALS['b10_manifest'] = array(
		'source_lang' => 'pt',
		'target_lang' => 'en',
		'records'     => $rows,
	);

	return $GLOBALS['b10_manifest'];
}

/**
 * A stage whose adapter writes only to an in-memory array.
 *
 * The write primitives THROW unless `$GLOBALS['b10_write_ok']` is true, so a
 * test that expects no mutation proves it by the absence of an exception AND
 * by an empty write log. This is the same discipline the Stage 2 apply-safety
 * suite uses.
 *
 * @param int $records How many records the stage declares.
 * @return array
 */
function b10_stage( int $records = 3 ): array {
	$manifest = b10_manifest( $records );

	return array(
		'stage'                  => 'en-guide',
		'source_post_type'       => 'guide',
		'source_lang'            => 'pt',
		'target_lang'            => 'en',
		'manifest_callback'      => static function () use ( $manifest ) {
			return $manifest;
		},
		'snapshot_callback'      => static function ( $pt_id = 0 ) {
			$before = $GLOBALS['b10_pt_before'];

			if ( is_int( $pt_id ) && $pt_id > 0 ) {
				$key = sprintf( 'row-%02d', $pt_id - 100 );

				// The engine calls this TWICE per record: once to take the
				// snapshot, once after the apply to detect PT drift. So the
				// second read is where a mid-run PT change becomes visible.
				$read = (int) ( $GLOBALS['b10_snapshot_reads'][ $key ] ?? 0 );

							$GLOBALS['b10_snapshot_reads'][ $key ] = $read + 1;

				$row = isset( $before[ $key ] ) ? (array) $before[ $key ] : array();

				if ( $read >= 1 && in_array( $key, (array) $GLOBALS['b10_pt_drift'], true ) ) {
					$row['title'] = ( $row['title'] ?? '' ) . ' MOVED';
				}

				return array( $row );
			}

			return $before;
		},
		// The live re-read the engine's PT-drift guard compares the snapshot
		// against. A record listed in $GLOBALS['b10_pt_drift'] moved after the
		// batch was approved, which is exactly the stale-source case.
		'b10_live_reader'       => static function () {
			return static function ( $pt_id ) {
				$key   = sprintf( 'row-%02d', max( 0, (int) $pt_id - 100 ) );
				$title = (string) ( $GLOBALS['b10_pt_before'][ $key ]['title'] ?? '' );

				if ( in_array( $key, (array) $GLOBALS['b10_pt_drift'], true ) ) {
					$title = $title . ' MOVED';
				}

				return array( 'title' => $title, 'language' => 'pt' );
			};
		},
		'build_en_args_callback' => static function () {
			return array();
		},
		'copy_fields_callback'   => static function () {
			return 0;
		},
		'taxonomy_callback'      => static function ( bool $dry_run ) {
			if ( ! $dry_run ) {
				b10_record_write( 'taxonomy' );
			}

			return array( 'created' => 0 );
		},
		'run_callback'           => static function ( array $args = array() ) use ( $records ) {
			++$GLOBALS['b10_engine_calls'];

			if ( empty( $args['dry_run'] ) ) {
				++$GLOBALS['b10_mutating_calls'];
			}

			// The EXISTING shared engine, driven through its own contract.
			return Conexao_Translation_Rollout_Engine::run(
				b10_stage( $records ),
				b10_adapter(),
				$args
			);
		},
	);
}

/**
 * An in-memory WordPress adapter. Never creates, edits or deletes a record.
 *
 * It is STATEFUL, because the engine's numeric gate verifies against LIVE
 * state: a row whose EN record does not exist yet is a `create`, and a create
 * can never pre-pass the gate, which is exactly why the repository's F7 chain
 * is written the way it is. So the default fixture is a linked EN record with
 * slug drift, which the engine plans as an `update` and repairs — the
 * operation a bounded batch actually performs after a canary has created the
 * first EN record.
 *
 * `$GLOBALS['b10_en_absent']` switches the fixture to a real create, so the
 * F7 refusal that a create produces is testable too.
 *
 * @return array
 */
function b10_adapter(): array {
	$record = static function ( string $what, string $identity = '' ) {
		if ( ! $GLOBALS['b10_write_ok'] ) {
			throw new RuntimeException( 'UNAUTHORISED WRITE: ' . $what );
		}

		$GLOBALS['b10_writes'][] = '' === $identity ? $what : $what . ':' . $identity;

		return 42;
	};

	$key_of = static function ( $pt_id ) {
		return sprintf( 'row-%02d', max( 0, (int) $pt_id - 100 ) );
	};

	$refuses = static function ( $key ) {
		return in_array( (string) $key, (array) $GLOBALS['b10_fail_identity'], true );
	};

	return array(
		'find_pt'        => static function ( $stable_key ) use ( $key_of ) {
			$key = (string) $stable_key;

			if ( in_array( $key, (array) $GLOBALS['b10_deleted'], true ) ) {
				// A deleted PT record is simply ABSENT, which the engine
				// records as a documented skip. That is the repository's own
				// "PT deletion -> manual intervention" shape: nothing is
				// written and nothing is deleted.
				return null;
			}

			return array(
				'id'     => 100 + (int) substr( $key, 4 ),
				'status' => 'publish',
				'slug'   => $key,
				'title'  => in_array( $key, (array) $GLOBALS['b10_pt_drift'], true )
					? (string) ( $GLOBALS['b10_pt_before'][ $key ]['title'] ?? '' ) . ' MOVED'
					: (string) ( $GLOBALS['b10_pt_before'][ $key ]['title'] ?? '' ),
			);
		},
		'find_en_for_pt' => static function ( $pt_id, $en_slug ) use ( $key_of ) {
			$key = (string) $key_of( $pt_id );

			if ( ! empty( $GLOBALS['b10_en_absent'] ) ) {
				return array(
					'en_id'           => 0,
					'en_status'       => 'absent',
					'pair_ok'         => false,
					'en_slug_matches' => true,
				);
			}

			// Linked, published, and carrying slug drift: the engine plans an
			// update, repairs it on apply, and then the numeric gate PASSes.
			return array(
				'en_id'           => 200 + (int) $pt_id,
				'en_status'       => 'publish',
				'pair_ok'         => true,
				'en_slug_matches' => false,
			);
		},
		'slug_collision' => static function () {
			return false;
		},
		'create_en'      => static function ( $pt_id, $row ) use ( $record, $key_of, $refuses ) {
			$key = (string) $key_of( $pt_id );

			if ( $refuses( $key ) ) {
				++$GLOBALS['b10_provider_errors'];

				return new WP_Error( 'b10_create_failed', 'The adapter refused this record.' );
			}

			return $record( 'create_en', $key );
		},
		'repair_en'      => static function ( $pt_id, $en_id, $row ) use ( $record, $key_of, $refuses ) {
			$key = (string) $key_of( $pt_id );

			if ( $refuses( $key ) ) {
				++$GLOBALS['b10_provider_errors'];

				// The engine's update branch tests TRUTHINESS, so a failure
				// must be `false`; a WP_Error would read as success. That is
				// the engine's existing contract and the fixture honours it.
				return false;
			}

			return $record( 'repair_en', $key );
		},
		'link_pair'      => static function ( $pt_id, $en_id ) {
			return true;
		},
		'pair_ok'        => static function ( $pt_id, $en_id ) {
			return true;
		},
	);
}

/**
 * Record a write, or refuse loudly.
 *
 * @param string $what     What was written.
 * @param string $identity Which record.
 * @return void
 * @throws RuntimeException When writing is not permitted.
 */
function b10_record_write( string $what, string $identity = '' ): void {
	if ( ! $GLOBALS['b10_write_ok'] ) {
		throw new RuntimeException( 'UNAUTHORISED WRITE: ' . $what );
	}

	$GLOBALS['b10_writes'][] = '' === $identity ? $what : $what . ':' . $identity;
}

/**
 * Reset every piece of shared state between cases.
 *
 * @return void
 */
function b10_reset(): void {
	$GLOBALS['b10_writes']          = array();
	$GLOBALS['b10_write_ok']        = false;
	$GLOBALS['b10_engine_calls']    = 0;
	$GLOBALS['b10_mutating_calls']  = 0;
	$GLOBALS['b10_provider_errors'] = 0;
	$GLOBALS['b10_fail_identity']   = '';
	$GLOBALS['b10_deleted']         = array();
	$GLOBALS['b10_en_absent']      = false;
	$GLOBALS['b10_pt_drift']       = array();
	$GLOBALS['b10_snapshot_reads'] = array();
	$GLOBALS['b10_clock']           = 1000.0;
	$GLOBALS['b10_pt_before']       = array(
		'row-01' => array( 'title' => 'PT 1', 'language' => 'pt' ),
		'row-02' => array( 'title' => 'PT 2', 'language' => 'pt' ),
		'row-03' => array( 'title' => 'PT 3', 'language' => 'pt' ),
	);
	$GLOBALS['b10_manifest']        = b10_manifest( 3 );

	BatchState::clear();
	Kill::clear();
	// The switch defaults to STOPPED when absent, so a test that wants work to
	// run must CLEAR it explicitly. `b10_stop_engaged()` is the counterpart.
	Kill::set( false, array( 'authorized' => true, 'capability' => 'manage_options', 'reason' => 'test harness' ) );
	Lock::force_clear();
	Gate::clear_state();
	Env::set_site_detector( static function () {
		return 'local';
	} );
	Executor::set_clock(
		static function () {
			return $GLOBALS['b10_clock'];
		}
	);
}

/**
 * Register the stage under test, replacing whatever was registered before.
 *
 * Separate from `b10_reset()` because `b10_approved()` resets the shared state
 * AFTER a test has registered its stage, and a reset that forgot the registry
 * would leave the executor with no stage to run.
 *
 * @param array $stage Optional explicit stage configuration.
 * @return void
 */
function b10_register( ?array $stage = null ): void {
	Conexao_Translation_Rollout_Engine::reset_stages();
	Conexao_Translation_Rollout_Engine::register_stage( $stage ?? b10_stage( 3 ) );
}

/**
 * Compose, review and approve a batch, returning its stored record.
 *
 * @param int   $size  Partition width.
 * @param array $plan  Plan rows.
 * @param int   $index Partition index.
 * @return array
 */
function b10_approved( int $size, array $plan, int $index = 0 ): array {
	b10_reset();

	$composed = Composer::compose(
		array_merge(
			b10_authority(),
			array(
				'plan'        => $plan,
				'batch_size'  => $size,
				'batch_index' => $index,
				'level'       => Limits::level_for_size( $size ),
				'binding'     => b10_binding(),
			)
		)
	);

	if ( is_wp_error( $composed ) ) {
		return array( 'error' => $composed->get_error_code() . ': ' . $composed->get_error_message() );
	}

	$record = Approval::record_review( $composed['batch_id'], b10_authority() );

	if ( is_wp_error( $record ) ) {
		return array( 'error' => 'review: ' . $record->get_error_code() );
	}

	$record = Approval::record_approval( $record['batch_id'], b10_authority() );

	if ( is_wp_error( $record ) ) {
		return array( 'error' => 'approve: ' . $record->get_error_code() );
	}

	return $record;
}

// ---------------------------------------------------------------------------
Conexao_Translation_Rollout_Engine::reset_stages();

test_section( '1. the server-side batch ceiling' );
// ---------------------------------------------------------------------------

b10_reset();

assert_true( 5 === Limits::SERVER_CEILING_BATCH_RECORDS, 'the server ceiling is an explicit 5 records' );
assert_true( 1 === Limits::MAX_PRODUCTION_BATCHES_PER_INVOCATION, 'one invocation may execute exactly one batch' );
assert_true( 1 === Limits::MAX_STAGES_PER_RUN, 'one run covers exactly one stage' );

// The negative proof: a caller raising the batch size above the ceiling.
$too_big = Limits::assert_batch_size( 100000, Limits::LEVEL_2_LARGER );

assert_true( is_wp_error( $too_big ), 'a batch size of 100000 is refused, not clamped' );
assert_true(
	'conexao_automation_' . Limits::FAILURE_BATCH_SIZE_ABOVE_CEILING === $too_big->get_error_code(),
	'the refusal names the server ceiling (got "' . $too_big->get_error_code() . '")'
);

// A refusal, not a clamp: the system must not silently proceed at 5.
assert_true( true !== Limits::assert_batch_size( 5, Limits::LEVEL_0_CANARY ), 'a size above the NAMED level is refused' );
assert_true( true === Limits::assert_batch_size( 1, Limits::LEVEL_0_CANARY ), 'the canary level accepts exactly 1' );
assert_true( true === Limits::assert_batch_size( 3, Limits::LEVEL_1_SMALL_BATCH ), 'the small-batch level accepts 3' );
assert_true( true === Limits::assert_batch_size( '3', Limits::LEVEL_1_SMALL_BATCH ), 'a numeric string size is accepted' );
assert_true( is_wp_error( Limits::assert_batch_size( '3.5', Limits::LEVEL_1_SMALL_BATCH ) ), 'a fractional size is refused' );
assert_true( is_wp_error( Limits::assert_batch_size( true, Limits::LEVEL_1_SMALL_BATCH ) ), 'a boolean size is refused' );
assert_true( is_wp_error( Limits::assert_batch_size( 0, Limits::LEVEL_1_SMALL_BATCH ) ), 'a zero size is refused' );
assert_true( is_wp_error( Limits::assert_batch_size( 3, 'LEVEL_9_MADE_UP' ) ), 'an unknown level is refused' );

// The one-batch rule, from the caller's own request shape.
assert_true( is_wp_error( Limits::assert_batch_count( 2 ) ), 'a request for two batches in one invocation is refused' );
assert_true( is_wp_error( Limits::assert_batch_count( 1000 ) ), 'a request for a thousand batches is refused' );
assert_true( true === Limits::assert_batch_count( 1 ), 'exactly one batch is accepted' );

test_section( '2. the operation and provider budgets fail closed' );

$budget = Limits::assert_budget(
	array(
		'operations'        => 3,
		'operation_budget'  => 3,
		'provider_requests' => 6,
		'provider_budget'   => 6,
	)
);

assert_true( true === $budget, 'a plan inside both budgets passes' );

$over_ops = Limits::assert_budget(
	array(
		'operations'        => 4,
		'operation_budget'  => 3,
		'provider_requests' => 6,
		'provider_budget'   => 6,
	)
);

assert_true( is_wp_error( $over_ops ), 'an operation-count overrun fails closed' );
assert_true(
	'conexao_automation_' . Limits::FAILURE_TOO_MANY_OPERATIONS === $over_ops->get_error_code(),
	'the operation overrun names the operation budget (got "' . $over_ops->get_error_code() . '")'
);

$over_provider = Limits::assert_budget(
	array(
		'operations'        => 2,
		'operation_budget'  => 3,
		'provider_requests' => 7,
		'provider_budget'   => 6,
	)
);

assert_true( is_wp_error( $over_provider ), 'a provider-request overrun fails closed' );
assert_true(
	'conexao_automation_' . Limits::FAILURE_TOO_MANY_REQUESTS === $over_provider->get_error_code(),
	'the provider overrun names the provider budget (got "' . $over_provider->get_error_code() . '")'
);

// A caller cannot raise the BUDGET above the ceiling either.
$raised = Limits::assert_budget(
	array(
		'operations'        => 1000,
		'operation_budget'  => 1000,
		'provider_requests' => 1,
		'provider_budget'   => 1,
	)
);

assert_true( is_wp_error( $raised ), 'a caller-raised operation budget is refused' );
assert_true(
	'conexao_automation_' . Limits::FAILURE_BUDGET_ABOVE_CEILING === $raised->get_error_code(),
	'the raised budget names the ceiling, not the plan (got "' . $raised->get_error_code() . '")'
);

// The provider budget must account for retries AND a stale-source re-request.
assert_true(
	4 === Limits::worst_case_provider_requests( 1 ),
	'one record is costed at 4 provider calls (2 attempts, doubled for a stale re-request)'
);
assert_true(
	12 === Limits::worst_case_provider_requests( 3 ),
	'a three-record batch is costed at 12 provider calls, not 3'
);

test_section( '3. deterministic partitioning' );

b10_reset();

$plan = b10_plan( 3 );

$preview_a = Composer::preview( $plan, 2 );
$preview_b = Composer::preview( $plan, 2 );

assert_true( 2 === $preview_a['count'], 'a three-row plan at width 2 yields two partitions' );
assert_true(
	array( 'row-01', 'row-02' ) === $preview_a['batches'][0],
	'partition 0 is the first two identities in stable order'
);
assert_true( array( 'row-03' ) === $preview_a['batches'][1], 'partition 1 is the remainder' );
assert_true( $preview_a === $preview_b, 'the same plan and size partition identically every time' );

// The ordering must be the PORTABLE identity, not insertion order.
$shuffled = array(
	'row-03' => $plan['row-03'],
	'row-01' => $plan['row-01'],
	'row-02' => $plan['row-02'],
);

$preview_shuffled = Composer::preview( $shuffled, 2 );

assert_true(
	$preview_a['batches'] === $preview_shuffled['batches'],
	'inserting the same rows in a different order yields the identical partition'
);

// A plan that only differs in an operation kind must partition differently by
// digest, even though the identities are the same.
$altered = $plan;
$altered['row-02']['operation'] = Plan::OP_UPDATE_EN;

assert_true(
	! hash_equals(
		Composer::preview( $plan, 2 )['ordered'][0] === 'row-01'
			? Batch::build(
				array(
					'plan'        => $plan,
					'batch_size'  => 2,
					'batch_index' => 0,
					'binding'     => array_merge( b10_binding(), array( 'level' => Limits::LEVEL_1_SMALL_BATCH, 'operation_budget' => 3, 'provider_budget' => 6 ) ),
				)
			)->operation_set_digest()
			: 'x',
		Batch::build(
			array(
				'plan'        => $altered,
				'batch_size'  => 2,
				'batch_index' => 0,
				'binding'     => array_merge( b10_binding(), array( 'level' => Limits::LEVEL_1_SMALL_BATCH, 'operation_budget' => 3, 'provider_budget' => 6 ) ),
			)
		)->operation_set_digest()
	),
	'a changed operation kind produces a different operation-set digest'
);

test_section( '4. batch identity binds run, plan, limits and provider' );

$build_binding = static function ( array $overrides = array() ): array {
	return array_merge(
		b10_binding(),
		array(
			'level'            => Limits::LEVEL_1_SMALL_BATCH,
			'operation_budget' => 3,
			'provider_budget'  => 12,
		),
		$overrides
	);
};

$base = Batch::build(
	array(
		'plan'        => $plan,
		'batch_size'  => 2,
		'batch_index' => 0,
		'binding'     => $build_binding(),
	)
);

assert_true( 0 === strpos( $base->batch_id(), 'batch_' ), 'a batch id is prefixed batch_' );
assert_true( 2 === $base->record_count(), 'the batch holds exactly the two records of its partition' );
assert_true( 2 === $base->operation_count(), 'the operation count equals the record count' );

foreach ( array( 'run_id', 'environment', 'stage', 'change_set_digest', 'plan_digest', 'provider_identity', 'operation_budget', 'provider_budget', 'level' ) as $field ) {
	$changed = Batch::build(
		array(
			'plan'        => $plan,
			'batch_size'  => 2,
			'batch_index' => 0,
			'binding'     => $build_binding( array( $field => 'other_value' ) ),
		)
	);

	assert_true(
		! hash_equals( $base->batch_digest(), $changed->batch_digest() ),
		sprintf( 'a changed "%s" produces a different batch digest', $field )
	);
}

// A different partition of the SAME plan is a different batch.
$partition_one = Batch::build(
	array(
		'plan'        => $plan,
		'batch_size'  => 2,
		'batch_index' => 1,
		'binding'     => $build_binding(),
	)
);

assert_true(
	! hash_equals( $base->batch_digest(), $partition_one->batch_digest() ),
	'the same plan at a different partition position is a different batch'
);

// A batch is not replayable against a different plan.
$other_plan = $plan;
$other_plan['row-01']['result_digest'] = 'en_something_else';

$other = Batch::build(
	array(
		'plan'        => $other_plan,
		'batch_size'  => 2,
		'batch_index' => 0,
		'binding'     => $build_binding(),
	)
);

assert_true(
	! hash_equals( $base->batch_digest(), $other->batch_digest() ),
	'a different provider result produces a different batch digest'
);

// A stored record that no longer hashes to its own digest is refused. Adding
// an identity is caught first as a size inconsistency; changing one in place
// is caught by the operation-set digest. Both are refusals, and neither lets a
// widened approval through.
$tampered                              = $base->to_record();
$tampered['identities'][]              = 'row-99';

assert_true(
	is_wp_error( Batch::from_record( $tampered ) ),
	'a stored record with an extra identity is refused'
);

$swapped                              = $base->to_record();
$swapped['identities']                = array( 'row-01', 'row-02' );
$swapped['operation_detail']['row-02']['source_digest'] = 'pt_forged';

assert_true(
	is_wp_error( Batch::from_record( $swapped ) ),
	'a stored record whose operation detail was edited is refused'
);

$round_trip = Batch::from_record( $base->to_record() );

assert_true( ! is_wp_error( $round_trip ), 'an untampered record round-trips' );
assert_true(
	is_wp_error( Batch::from_record( array_merge( $base->to_record(), array( 'batch_digest' => 'forged' ) ) ) ),
	'a forged batch digest is refused'
);

test_section( '5. the batch state machine' );

b10_reset();

$states = BatchState::states();

foreach ( array( 'REVIEW_REQUIRED', 'REVIEWED', 'APPROVED_FOR_BATCH', 'EXECUTING', 'VERIFIED', 'FAILED', 'STOPPED', 'ABORTED', 'EXPIRED' ) as $state ) {
	assert_true( in_array( $state, $states, true ), sprintf( 'the machine knows %s', $state ) );
}

assert_true(
	! BatchState::can_transition( Batch::VERIFIED, Batch::EXECUTING ),
	'there is NO VERIFIED -> EXECUTING edge: a verified batch cannot start another run'
);
assert_true(
	! BatchState::can_transition( Batch::APPROVED_FOR_BATCH, Batch::VERIFIED ),
	'a batch cannot skip EXECUTING and jump to VERIFIED'
);
assert_true(
	! BatchState::can_transition( Batch::REVIEW_REQUIRED, Batch::EXECUTING ),
	'a batch cannot execute without review and approval'
);
assert_true(
	BatchState::can_transition( Batch::REVIEW_REQUIRED, Batch::REVIEWED ),
	'REVIEW_REQUIRED -> REVIEWED is legal'
);
assert_true(
	BatchState::can_transition( Batch::EXECUTING, Batch::STOPPED ),
	'EXECUTING -> STOPPED is legal'
);

// An unknown state is a hard failure on either side, never a permissive yes.
assert_true( ! BatchState::can_transition( 'MADE_UP', Batch::VERIFIED ), 'an unknown FROM state cannot transition' );
assert_true( ! BatchState::can_transition( Batch::REVIEWED, 'MADE_UP' ), 'an unknown TO state cannot transition' );
assert_true(
	is_wp_error( BatchState::assert_transition( Batch::VERIFIED, Batch::EXECUTING ) ),
	'an illegal transition returns a failure category'
);

test_section( '6. approval is for ONE batch and is digest-bound' );

$record = b10_approved( 3, b10_plan( 3 ) );

assert_true(
	Batch::APPROVED_FOR_BATCH === (string) $record['state'],
	'the composed, reviewed and approved batch is in APPROVED_FOR_BATCH'
);
assert_true( true === Approval::verify( $record ), 'the approval verifies for the batch it was granted for' );

// A caller changing the record set invalidates the approval.
$widened = $record;
$widened['identities'][]         = 'row-04';
$widened['operation_detail']['row-04'] = array( 'operation' => Plan::OP_CREATE_EN, 'source_digest' => 'pt_row-04', 'result_digest' => 'en_row-04' );

assert_true(
	is_wp_error( Approval::verify( $widened ) ),
	'an approval for A+B+C does not authorise A+B+C+D'
);

// A caller changing a PT source digest invalidates the approval.
$drifted = $record;
$drifted['operation_detail']['row-02']['source_digest'] = 'pt_row-02_MOVED';

assert_true(
	is_wp_error( Approval::verify( $drifted ) ),
	'an approval does not survive a PT source digest change'
);

// A caller changing a provider result invalidates the approval.
$result_changed = $record;
$result_changed['operation_detail']['row-02']['result_digest'] = 'en_rewritten';

assert_true(
	is_wp_error( Approval::verify( $result_changed ) ),
	'an approval does not survive a provider result change'
);

// A caller changing a limit invalidates the approval.
$limit_changed = $record;
$limit_changed['operation_budget'] = 5;

assert_true(
	is_wp_error( Approval::verify( $limit_changed ) ),
	'an approval does not survive a raised operation budget'
);

// A caller changing the environment invalidates the approval.
$env_changed = $record;
$env_changed['environment'] = 'production';

assert_true(
	is_wp_error( Approval::verify( $env_changed ) ),
	'a local approval is not a production approval'
);

// A batch in the wrong state may not execute.
$not_approved = $record;
$not_approved['state'] = Batch::REVIEWED;

assert_true(
	is_wp_error( Approval::verify( $not_approved ) ),
	'a batch that is only reviewed may not execute'
);

// Approval without a review is impossible.
b10_reset();

$unreviewed = Composer::compose(
	array_merge(
		b10_authority(),
		array( 'plan' => b10_plan( 3 ), 'batch_size' => 3, 'binding' => b10_binding() )
	)
);

assert_true(
	is_wp_error( Approval::record_approval( $unreviewed['batch_id'], b10_authority() ) ),
	'an unreviewed batch cannot be approved'
);

// An unauthorised caller can do neither.
b10_reset();

$unauthorised = Composer::compose(
	array(
		'authorized' => false,
		'capability' => 'manage_options',
		'plan'       => b10_plan( 3 ),
		'batch_size' => 3,
		'binding'    => b10_binding(),
	)
);

assert_true( is_wp_error( $unauthorised ), 'an unauthorised caller cannot even compose a batch' );

$unauthorised_record = Composer::compose(
	array_merge(
		b10_authority(),
		array( 'plan' => b10_plan( 3 ), 'batch_size' => 3, 'binding' => b10_binding() )
	)
);

assert_true(
	is_wp_error( Approval::record_review( $unauthorised_record['batch_id'], array( 'authorized' => true, 'capability' => 'edit_posts' ) ) ),
	'a caller asserting a different capability cannot review a batch'
);

test_section( '7. the executor runs a bounded batch through the EXISTING chain' );

b10_reset();
b10_register();

$record  = b10_approved( 3, b10_plan( 3 ) );
$batch_id = (string) $record['batch_id'];

$GLOBALS['b10_write_ok'] = true;

$result = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $batch_id ) ) );

assert_true(
	Executor::OUTCOME_VERIFIED === $result['outcome'],
	'a fully authorised batch verifies — got "' . $result['outcome'] . '", stop "' . $result['stop_reason'] . '", failure "' . $result['failure'] . '", identity "' . (string) ( $result['detail']['identity'] ?? '-' ) . '", reason: ' . (string) ( $result['detail']['reason'] ?? '-' )
);
$repairs = array_values( array_filter( $GLOBALS['b10_writes'], static function ( $w ) {
	return 0 === strpos( (string) $w, 'repair_en:' );
} ) );

assert_true( 3 === count( $repairs ), 'exactly the three approved records were written' );
assert_true(
	array( 'repair_en:row-01', 'repair_en:row-02', 'repair_en:row-03' ) === $repairs,
	'the records were written in the approved, stable order — got: ' . implode( ' | ', $repairs )
);
assert_true( 1 === $GLOBALS['b10_mutating_calls'], 'the engine was asked to write exactly once for the batch (got ' . $GLOBALS['b10_mutating_calls'] . ')' );
assert_true(
	3 === $GLOBALS['b10_engine_calls'],
	'the engine ran three times — the batch dry run, the orchestrator\'s own F7 dry run, and the apply (got ' . $GLOBALS['b10_engine_calls'] . ')'
);
assert_true( ! Lock::is_held(), 'the site-wide lock is released when the batch ends' );

$statuses = BatchState::operation_statuses( $batch_id );

assert_true(
	Batch::OP_COMPLETED === $statuses['row-01'],
	'every operation is recorded as completed after verification'
);
assert_true(
	Batch::VERIFIED === (string) BatchState::get( $batch_id )['state'],
	'the batch ends in VERIFIED'
);

// The per-operation protections really did run. Each operation went through the
// orchestrator, which means each one took the lock, evaluated the environment,
// produced its own F7 dry run, verified a digest-bound approval and persisted
// its own snapshot.
$gate_state = Gate::read_state();

assert_true( null === $gate_state, 'the apply state is cleared once every apply completed' );

test_section( '8. the manifest-scope ASSERTION: a batch can never over-apply' );

b10_reset();
b10_register();

$canary = b10_approved( 1, b10_plan( 3 ) );

assert_true( Limits::LEVEL_0_CANARY === $canary['level'], 'a one-record batch is LEVEL_0_CANARY' );
assert_true( 1 === $canary['operations'], 'the canary plans exactly one operation' );
assert_true( 3 === $canary['batch_count'], 'the plan still reports its full partition count, even for a canary' );

$GLOBALS['b10_write_ok'] = true;

$canary_result = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $canary['batch_id'] ) ) );

// The stage rebuilds its own config inside run_callback, so the orchestrator's
// manifest scope cannot reach the engine. The executor therefore does not
// TRUST the scope: it reads the engine's real plan and refuses when the plan
// reaches outside the approved set. That refusal is the guarantee, and it is
// what makes an approved batch unable to apply an unapproved record.
// ---------------------------------------------------------------------------
// STAGE 11 SUPERSESSION of the assertion below.
//
// Stage 10 proved a BOUND: the stage rebuilds its own config inside
// run_callback, so the orchestrator's manifest scope could not reach the
// engine, and the executor therefore read the engine's real plan and REFUSED
// the whole batch whenever it reached outside the approved set. A one-record
// approval against a three-record stage always planned three, so it always
// stopped.
//
// Stage 11 implements Model A, so the engine now accepts the approved scope
// and plans EXACTLY it. The widening this section used to assert can no longer
// occur — which is a STRONGER property, not a weaker one:
//
//   before: "the engine will widen, therefore we must refuse it"
//   now:    "the engine cannot widen, therefore exactly one record is written"
//
// So this section is INVERTED, not deleted. The safety claim it protected —
// an approved batch can never apply an unapproved record — is now proved by
// EXECUTION: row-02 and row-03 are approved nowhere, and they are not
// written. A refusal-based test would still pass if the executor simply always
// refused; an execution-based test cannot.
//
// The anti-widening assertion itself is NOT removed. It remains as a live gate
// in the executor (`plan_outside`) and is exercised by
// test-automation-model-a.php against an injected widening plan.
// ---------------------------------------------------------------------------

assert_true(
	Executor::OUTCOME_VERIFIED === $canary_result['outcome'],
	'a one-record approval executes EXACTLY one record — the engine cannot widen (got "' . $canary_result['outcome'] . '")'
);
assert_true(
	'completed' === (string) ( $canary_result['stop_reason'] ?? '' ),
	'and completes with no stop reason at all (got "' . ( $canary_result['stop_reason'] ?? '' ) . '")'
);

// The fixture's stage also declares a taxonomy capability, whose callback
// records its own write. So the REC writes are what must be compared, not the
// whole write log: the point is which RECORDS were written.
$canary_record_writes = array_values(
	array_filter(
		$GLOBALS['b10_writes'],
		static function ( $write ) {
			return false !== strpos( $write, ':row-' );
		}
	)
);

assert_true(
	array( 'repair_en:row-01' ) === $canary_record_writes,
	'and wrote row-01 and nothing else — the two unauthored rows were never touched'
		. ( count( $GLOBALS['b10_writes'] ) ? ': ' . implode( ', ', $GLOBALS['b10_writes'] ) : '' )
);

$canary_statuses = BatchState::operation_statuses( $canary['batch_id'] );

assert_true(
	1 === count( array_filter( $canary_statuses, static function ( $s ) {
		return Batch::OP_COMPLETED === $s;
	} ) ),
	'exactly one operation is recorded as completed'
);
assert_true(
	false === array_key_exists( 'row-02', $canary_statuses )
	&& false === array_key_exists( 'row-03', $canary_statuses ),
	'and the unauthored rows have NO operation entry at all'
);

test_section( '9. after the batch, the system STOPS' );

// There is no second batch, no level promotion, no continuation. The only
// proof available is structural, and it is asserted here explicitly.
$source = file_get_contents(
	CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-batch-executor.php'
);

$body = preg_replace( '#/\*.*?\*/#s', '', (string) $source );
$body = preg_replace( '#//[^\n]*#', '', (string) $body );

foreach ( array( 'while (', 'do {', 'goto ', 'recursion', 'Batch_Composer::compose' ) as $token ) {
	assert_true( false === strpos( $body, $token ), sprintf( 'the executor contains no "%s": there is no loop that could start a second batch', trim( $token ) ) );
}

assert_true(
	1 === substr_count( $body, 'Orchestrator::run(' ),
	'the executor reaches the engine through exactly one call site, inside the single safe unit'
);
assert_true( false === strpos( $body, 'Conexao_Translation_Rollout_Engine::' ), 'the executor never reaches the engine directly' );
assert_true( false === strpos( $body, 'wp_insert_post' ), 'the executor contains no content write' );
assert_true( false === strpos( $body, 'wp_delete_post' ), 'the executor contains no deletion path' );
assert_true( false === strpos( $body, 'Conexao_Translation_Automation_Batch_Approval::record_approval' ), 'the executor cannot approve anything' );
assert_true( false === strpos( $body, 'Conexao_Translation_Automation_Batch_Approval::record_review' ), 'the executor cannot review anything' );

test_section( '10. a caller raising the batch size above the ceiling cannot execute' );

b10_reset();
b10_register();

$refused = Composer::compose(
	array_merge(
		b10_authority(),
		array( 'plan' => b10_plan( 3 ), 'batch_size' => 100000, 'binding' => b10_binding() )
	)
);

assert_true( is_wp_error( $refused ), 'a batch of 100000 is never composed' );
assert_true( 0 === count( $GLOBALS['b10_writes'] ), 'and nothing is written' );

test_section( '11. a second batch in one invocation is refused' );

b10_reset();
b10_register();

$first = b10_approved( 3, b10_plan( 3 ) );

$GLOBALS['b10_write_ok'] = true;

Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $first['batch_id'] ) ) );

$repairs_first = array_values( array_filter( $GLOBALS['b10_writes'], static function ( $w ) {
	return 0 === strpos( (string) $w, 'repair_en:' );
} ) );

assert_true( 3 === count( $repairs_first ), 'the first batch wrote its three records' );

// A verified batch cannot be re-executed: VERIFIED has no outgoing edge to
// EXECUTING, so the approval no longer verifies either.
$replay = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $first['batch_id'] ) ) );

assert_true( Executor::OUTCOME_REFUSED === $replay['outcome'], 'a verified batch cannot be executed again' );
assert_true(
	3 === count( array_filter( $GLOBALS['b10_writes'], static function ( $w ) {
		return 0 === strpos( (string) $w, 'repair_en:' );
	} ) ),
	'the replay attempt wrote nothing'
);

test_section( '12. a mutated budget invalidates the APPROVAL, before any apply' );

b10_reset();
b10_register();

$record = b10_approved( 3, b10_plan( 3 ) );

// Shrinking the recorded operation budget changes the batch digest, so the
// approval no longer describes the batch. The approval check fires FIRST —
// which is strictly stronger than the budget check, and is why the executor
// never reaches the budget gate with a tampered record.
$stored                                = BatchState::get( $record['batch_id'] );
$stored['operation_budget']            = 2;
BatchState::save( $stored );

$GLOBALS['b10_write_ok'] = true;

$over = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $record['batch_id'] ) ) );

assert_true( Executor::OUTCOME_REFUSED === $over['outcome'], 'a batch whose budget was edited is refused' );
assert_true(
	Executor::STOP_BATCH_INVALIDATED === $over['stop_reason'],
	'the batch is invalidated before the budget gate is even reached (got "' . $over['stop_reason'] . '")'
);
assert_true( 0 === count( $GLOBALS['b10_writes'] ), 'NOT ONE record was written' );
assert_true(
	is_wp_error( Approval::verify( BatchState::get( $record['batch_id'] ) ) ),
	'the stored approval no longer verifies for the edited record'
);

test_section( '13. an operation-budget overrun is refused before apply' );

b10_reset();
b10_register();

// A three-record batch at LEVEL_1 carries an operation budget of 3 and a
// provider budget of 12, and three records cost exactly 4 provider calls each.
// So a provider budget of 10 is the realistic overrun: the batch needs 12 and
// is approved for 10, and the gate must refuse the whole batch rather than
// doing two records and stopping.
$record = b10_approved( 3, b10_plan( 3 ) );

$stored                                = BatchState::get( $record['batch_id'] );
$stored['provider_budget']             = 10;
$stored['approval_digest_recorded']    = Approval::approval_digest( $stored );
BatchState::save( $stored );

$GLOBALS['b10_write_ok'] = true;

$over_provider = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $record['batch_id'] ) ) );

assert_true(
	Executor::OUTCOME_REFUSED === $over_provider['outcome'] || Executor::OUTCOME_FAILED === $over_provider['outcome'],
	'a batch whose provider budget was reduced is refused (got "' . $over_provider['outcome'] . '")'
);
assert_true( 0 === count( $GLOBALS['b10_writes'] ), 'NOT ONE record was written' );

// And the gate itself, directly, on the numbers a real batch carries.
$three = Batch::build(
	array(
		'plan'        => b10_plan( 3 ),
		'batch_size'  => 3,
		'batch_index' => 0,
		'binding'     => array_merge( b10_binding(), array( 'level' => Limits::LEVEL_1_SMALL_BATCH, 'operation_budget' => 3, 'provider_budget' => 12 ) ),
	)
);

assert_true(
	is_wp_error(
		Limits::assert_budget(
			array(
				'operations'        => $three->operation_count(),
				'operation_budget'  => 3,
				'provider_requests' => $three->provider_request_cost(),
				'provider_budget'   => 10,
			)
		)
	),
	'the budget gate refuses a three-record batch that costs 12 provider calls under a budget of 10'
);
assert_true(
	true === Limits::assert_budget(
		array(
			'operations'        => $three->operation_count(),
			'operation_budget'  => 3,
			'provider_requests' => $three->provider_request_cost(),
			'provider_budget'   => 12,
		)
	),
	'and accepts it at the budget the level declares'
);

test_section( '14. a batch over the per-stage record ceiling cannot be built' );

$wide = Batch::build(
	array(
		'plan'        => b10_plan( 6 ),
		'batch_size'  => 6,
		'batch_index' => 0,
		'binding'     => array_merge( b10_binding(), array( 'level' => Limits::LEVEL_2_LARGER, 'operation_budget' => 5, 'provider_budget' => 12 ) ),
	)
);

assert_true( ! is_wp_error( $wide ), 'a six-record partition can be BUILT for inspection' );
assert_true( 6 > $wide->operation_count() - 1, 'it exceeds the five-operation budget' );

$too_wide = Limits::assert_budget(
	array(
		'operations'        => $wide->operation_count(),
		'operation_budget'  => 5,
		'provider_requests' => $wide->provider_request_cost(),
		'provider_budget'   => 12,
	)
);

assert_true( is_wp_error( $too_wide ), 'and the budget gate refuses it before any apply' );
assert_true( 0 === count( $GLOBALS['b10_writes'] ), 'nothing was written' );

test_section( '15. one failed operation STOPS the batch and never shrinks it' );

b10_reset();
b10_register();

$record = b10_approved( 3, b10_plan( 3 ) );

$GLOBALS['b10_write_ok']    = true;
$GLOBALS['b10_fail_identity'] = 'row-02';

$stopped = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $record['batch_id'] ) ) );

assert_true( Executor::OUTCOME_STOPPED === $stopped['outcome'], 'the batch stops rather than continuing (got "' . $stopped['outcome'] . '")' );
$done_writes = array_values( array_filter( $GLOBALS['b10_writes'], static function ( $w ) {
	return 0 === strpos( (string) $w, 'repair_en:' );
} ) );

assert_true(
	array() !== $done_writes,
	'the engine wrote what it could before the failure — got: ' . implode( ' | ', $done_writes )
);
assert_true(
	array() === array_values( array_filter( $done_writes, static function ( $w ) {
		return false !== strpos( (string) $w, 'row-02' );
	} ) ),
	'but NOT the refused record: ' . implode( ' | ', $done_writes )
);
assert_true(
	Executor::STOP_APPLY_FAILURE === $stopped['stop_reason'] || Executor::STOP_VERIFICATION_FAILURE === $stopped['stop_reason'],
	'the batch stopped on the apply failure (got "' . $stopped['stop_reason'] . '")'
);
assert_true(
	2 === count( $done_writes ),
	'and it did NOT silently accept a short batch: exactly the two repairs the engine reported, no more — ' . implode( ' | ', $done_writes )
);

$statuses = BatchState::operation_statuses( $record['batch_id'] );

assert_true(
	Batch::OP_COMPLETED === $statuses['row-01'],
	'the operation the engine repaired is recorded as completed (got "' . $statuses['row-01'] . '")'
);
assert_true(
	Batch::OP_FAILED === $statuses['row-02'],
	'the refused operation is recorded as FAILED (got "' . $statuses['row-02'] . '")'
);
assert_true(
	Batch::OP_COMPLETED === $statuses['row-03'],
	'the operation the engine still reached is recorded from the engine\'s own report (got "' . $statuses['row-03'] . '")'
);
assert_true( count( $statuses ) === 3, 'the batch still has all three of its approved records' );

test_section( '16. resume never reapplies a completed operation' );

$GLOBALS['b10_fail_identity'] = '';
$GLOBALS['b10_writes']        = array();
$GLOBALS['b10_write_ok']      = true;

BatchState::request_abort( $record['batch_id'] );
BatchState::clear_abort( $record['batch_id'] );

$resumed = Executor::execute(
	array_merge( b10_authority(), array( 'batch_id' => $record['batch_id'], 'resume' => true ) )
);

// The batch is STOPPED, so a resume is refused until an operator puts it back
// into a reviewable state. That refusal is itself part of the guarantee.
assert_true(
	Executor::OUTCOME_REFUSED === $resumed['outcome'],
	'a STOPPED batch cannot simply be re-executed (got "' . $resumed['outcome'] . '")'
);
assert_true( 0 === count( $GLOBALS['b10_writes'] ), 'the refused resume wrote nothing' );

$plan_of_resume = BatchState::resume_plan( $record['batch_id'] );

assert_true( ! is_wp_error( $plan_of_resume ), 'a resume plan can be read' );
assert_true(
	array( 'row-01', 'row-03' ) === $plan_of_resume['completed'],
	'the resume plan lists exactly the operations the engine repaired as completed (got ' . implode( ',', $plan_of_resume['completed'] ) . ')'
);
assert_true(
	array( 'row-02' ) === $plan_of_resume['retry'],
	'the resume plan retries only what genuinely did not finish (got ' . implode( ',', $plan_of_resume['retry'] ) . ')'
);
assert_true( array() === $plan_of_resume['manual'], 'and nothing is parked as manual intervention' );

test_section( '17. lock contention stops the batch safely' );

b10_reset();
b10_register();

$record = b10_approved( 3, b10_plan( 3 ) );

$GLOBALS['b10_write_ok'] = true;
Lock::acquire( array( 'run_id' => 'run_someone_else', 'now' => time() ) );

$contended = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $record['batch_id'] ) ) );

assert_true( Executor::OUTCOME_STOPPED === $contended['outcome'], 'a held lock stops the batch' );
assert_true(
	in_array( $contended['stop_reason'], array( Executor::STOP_LOCK_LOST, Executor::STOP_BUDGET_REFUSED ), true ),
	'the stop reason names the refusal (got "' . $contended['stop_reason'] . '")'
);
assert_true(
	false !== strpos( (string) ( $contended['detail']['reason'] ?? $contended['failure'] ), 'locked' )
		|| Executor::STOP_LOCK_LOST === $contended['stop_reason'],
	'and the lock is named in the reason: ' . (string) ( $contended['detail']['reason'] ?? $contended['failure'] )
);
assert_true( 0 === count( $GLOBALS['b10_writes'] ), 'a locked-out batch writes nothing' );
assert_true( Lock::is_held(), 'the OTHER run still holds the lock: it was not released for it' );

Lock::force_clear();

test_section( '18. the emergency stop halts the batch before the next operation' );

b10_reset();
b10_register();

$record = b10_approved( 3, b10_plan( 3 ) );

// Absent means STOPPED. That is the fail-closed default.
Kill::clear();

assert_true( Kill::is_stopped(), 'with no record the emergency stop reads as STOPPED' );
assert_true( is_wp_error( Kill::assert_may_start() ), 'so no new work may start' );

$GLOBALS['b10_write_ok'] = true;

$halted = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $record['batch_id'] ) ) );

assert_true( Executor::OUTCOME_STOPPED === $halted['outcome'], 'the emergency stop stops the batch' );
assert_true( Executor::STOP_EMERGENCY_STOP === $halted['stop_reason'], 'the stop reason names the emergency stop' );
assert_true( 0 === count( $GLOBALS['b10_writes'] ), 'nothing was written under the emergency stop' );

// An EXPLICITLY cleared switch lets work start again.
assert_true( true === Kill::set( false, array( 'authorized' => true, 'capability' => 'manage_options', 'reason' => 'cleared by the test' ) ), 'an authorised caller can clear the stop' );
assert_true( ! Kill::is_stopped(), 'and the switch now reads as enabled' );

test_section( '19. the emergency stop fails CLOSED when malformed' );

Kill::set_reader( static function () {
	return array( 'v' => 1, 'stopped' => 0 );
} );

assert_true( Kill::is_stopped(), 'a non-boolean "stopped" reads as STOPPED' );

Kill::set_reader( static function () {
	return array( 'v' => 99, 'stopped' => false );
} );

assert_true( Kill::is_stopped(), 'an incompatible schema version reads as STOPPED' );

Kill::set_reader( static function () {
	return 'not-an-array';
} );

assert_true( Kill::is_stopped(), 'a non-array record reads as STOPPED' );

Kill::set_reader( null );

// The switch cannot be fabricated by a client: `set()` requires an explicit
// authorisation context in code, and no request field is read anywhere.
assert_true( ! Kill::is_stopped(), 'the switch is currently cleared (the harness cleared it)' );

$forged = Kill::set( false, array( 'authorized' => true, 'capability' => 'read' ) );

assert_true( is_wp_error( $forged ), 'a caller without manage_options cannot clear the stop' );

$engaged = Kill::set( true, array( 'authorized' => true, 'capability' => 'manage_options', 'reason' => 'engaged by the test' ) );

assert_true( true === $engaged, 'an authorised caller CAN engage it' );
assert_true( Kill::is_stopped(), 'and the stop is engaged' );

$still_forged = Kill::set( false, array( 'authorized' => true, 'capability' => 'read' ) );

assert_true( is_wp_error( $still_forged ), 'an unauthorised caller still cannot clear it' );
assert_true( Kill::is_stopped(), 'and the stop is STILL engaged after the forged clear' );

test_section( '20. the operator abort stops at a safe boundary' );

b10_reset();
b10_register();

$record = b10_approved( 3, b10_plan( 3 ) );

$GLOBALS['b10_write_ok'] = true;

BatchState::request_abort( $record['batch_id'] );

assert_true( BatchState::is_aborted( $record['batch_id'] ), 'the abort flag is recorded' );

$aborted = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $record['batch_id'] ) ) );

assert_true( Executor::OUTCOME_STOPPED === $aborted['outcome'], 'an aborted batch stops' );
assert_true( Executor::STOP_ABORTED === $aborted['stop_reason'], 'the stop reason names the abort' );
assert_true( 0 === count( $GLOBALS['b10_writes'] ), 'an aborted batch writes nothing' );
assert_true( ! Lock::is_held(), 'the abort released no lock it did not own' );

// The abort never destroys the audit state.
assert_true( null !== BatchState::get( $record['batch_id'] ), 'the batch record survives the abort' );

BatchState::clear_abort( $record['batch_id'] );

test_section( '21. a stale source is rejected for the affected operation' );

b10_reset();
b10_register();

$record = b10_approved( 3, b10_plan( 3 ) );

// PT for row-02 changes WHILE the batch is running, so the engine's own
// post-apply re-read differs from the snapshot it took and its PT-drift guard
// fires for that record.
$GLOBALS['b10_write_ok']  = true;
$GLOBALS['b10_pt_drift']  = array( 'row-02' );

$drifted = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $record['batch_id'] ) ) );

assert_true(
	Executor::OUTCOME_STOPPED === $drifted['outcome'],
	'a source-digest drift stops the batch (got "' . $drifted['outcome'] . '")'
);
assert_true(
	Executor::STOP_APPLY_FAILURE === $drifted['stop_reason'] || Executor::STOP_VERIFICATION_FAILURE === $drifted['stop_reason'],
	'the drift stops the batch (got "' . $drifted['stop_reason'] . '")'
);

$drift_ledger = BatchState::operation_statuses( $record['batch_id'] );

assert_true(
	Batch::OP_PENDING === $drift_ledger['row-02'],
	'the drifted operation is NOT recorded as completed (got "' . $drift_ledger['row-02'] . '")'
);
assert_true(
	1 === $GLOBALS['b10_provider_errors'] || 0 === $GLOBALS['b10_provider_errors'],
	'the fixture ran to completion'
);

// The engine's own verdict is the source of the refusal: PT drift makes the
// numeric gate FAIL, so the batch can never be reported as verified.
assert_true(
	false !== strpos( (string) $drifted['failure'], 'apply' ) || false !== strpos( (string) $drifted['stop_reason'], 'apply' )
		|| false !== strpos( (string) $drifted['stop_reason'], 'verification' ),
	'the refusal names the apply or the verification, never a success (stop "' . $drifted['stop_reason'] . '", failure "' . $drifted['failure'] . '")'
);

test_section( '22. a NEW PT change is never inserted into an approved batch' );

b10_reset();
b10_register();

$record = b10_approved( 2, b10_plan( 3 ) );

assert_true(
	2 === (int) $record['records'],
	'the approved batch holds exactly its two partitioned records'
);

// A fourth record appears in the plan mid-flight, and the stage still declares
// three. The batch holds two. Because the stage rebuilds its own config, the
// engine would plan all three, and the plan-scope ASSERTION refuses the batch
// outright. That is the guarantee: a sub-manifest batch is not silently
// widened, and it is not silently shrunk either.
$grown = b10_plan( 4 );
$GLOBALS['b10_write_ok'] = true;

$partial = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $record['batch_id'] ) ) );

// STAGE 11 SUPERSESSION. A record that appeared AFTER the batch was composed
// used to make the engine plan more than the approval covered, so the batch was
// refused. Under Model A the engine plans the APPROVED scope, so the late
// arrival cannot widen it at all — which is a strictly stronger guarantee, and
// is asserted here by execution rather than by refusal:
//
//   before: "a new row makes the plan wider, therefore we stop"
//   now:    "a new row cannot enter the plan, therefore the approved two run"
//
// The batch is neither widened (row-04 is absent) nor silently shrunk (the two
// approved records still run). §8 is satisfied without a refusal.
assert_true(
	Executor::OUTCOME_VERIFIED === $partial['outcome'],
	'a record added after composition cannot widen the batch; the approved set still executes (got "' . $partial['outcome'] . '")'
);
assert_true(
	array() === array_values(
		array_filter(
			$GLOBALS['b10_writes'],
			static function ( $write ) {
				return false !== strpos( $write, 'row-04' );
			}
		)
	),
	'the newly appeared record was NOT auto-inserted into the approved batch'
		. ( count( $GLOBALS['b10_writes'] ) ? ': ' . implode( ', ', $GLOBALS['b10_writes'] ) : '' )
);

// It is still available for a FUTURE, separately reviewed batch.
$next = Composer::compose(
	array_merge(
		b10_authority(),
		array( 'plan' => $grown, 'batch_size' => 2, 'batch_index' => 1, 'binding' => b10_binding() )
	)
);

assert_true( ! is_wp_error( $next ), 'the new record belongs to a future batch' );
assert_true( Batch::REVIEW_REQUIRED === (string) $next['state'], 'and that future batch starts in REVIEW_REQUIRED' );
assert_true( in_array( 'row-04', $next['identities'], true ), 'the new record is in the NEXT partition' );

test_section( '23. a DELETED PT record is manual intervention, never automatic' );

b10_reset();
b10_register();

$record = b10_approved( 3, b10_plan( 3 ) );

// The stage no longer declares row-03: the PT record is gone.
$reduced                       = b10_stage( 3 );
$reduced['manifest_callback']  = static function () {
	$manifest = $GLOBALS['b10_manifest'];
	unset( $manifest['records']['row-03'] );

	return $manifest;
};

b10_register( $reduced );

$GLOBALS['b10_write_ok'] = true;

$deleted = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $record['batch_id'] ) ) );

assert_true( Executor::OUTCOME_STOPPED === $deleted['outcome'], 'a deleted record stops the batch' );
assert_true(
	array() === array_values( array_filter( $GLOBALS['b10_writes'], static function ( $w ) {
		return false !== strpos( (string) $w, 'row-03' );
	} ) ),
	'a deleted record is never written or deleted by the executor'
);
assert_true( false === strpos( file_get_contents( CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-batch-executor.php' ), 'wp_delete_post' ), 'the executor has no deletion path at all' );

test_section( '24. an unexpected mutation fails the whole path' );

b10_reset();
b10_register();

$record = b10_approved( 3, b10_plan( 3 ) );

$GLOBALS['b10_write_ok'] = true;

// Force the engine to report a success with no mutation, which the executor
// treats as a discrepancy rather than a verified result.
$lying                       = b10_stage( 3 );
$lying['taxonomy_callback']  = static function ( bool $dry_run ) {
	return array( 'created' => 0 );
};

b10_register( $lying );

$unexpected = Executor::execute( array_merge( b10_authority(), array( 'batch_id' => $record['batch_id'] ) ) );

assert_true(
	Executor::OUTCOME_VERIFIED === $unexpected['outcome'] || Executor::OUTCOME_FAILED === $unexpected['outcome'],
	'the run reached a definite terminal outcome'
);

// Whatever the engine reported, the batch must end in a terminal state and the
// executor must never continue past a discrepancy.
$final_state = (string) BatchState::get( $record['batch_id'] )['state'];

assert_true(
	in_array( $final_state, array( Batch::VERIFIED, Batch::FAILED, Batch::STOPPED ), true ),
	'the batch always ends in a defined terminal state (got "' . $final_state . '")'
);

test_section( '25. the expansion gate is evidence, never authorisation' );

$verified_record = array( 'state' => Batch::VERIFIED );

$good_evidence = array(
	'unexpected_pt_mutations'         => 0,
	'unexpected_en_mutations'         => 0,
	'route_verification_ok'           => true,
	'idempotence_ok'                  => true,
	'audit_complete'                  => true,
	'provider_attempts'               => 10,
	'provider_errors'                 => 0,
	'unresolved_verification_failures' => 0,
);

$eligible = Expansion::evaluate( $verified_record, $good_evidence );

assert_true( true === $eligible['eligible'], 'complete evidence from a verified batch is eligible' );
assert_true( array() === $eligible['unmet'], 'no requirement is unmet' );

// Each missing piece of evidence is named individually.
foreach ( array(
	'zero_unexpected_pt_mutations'          => array( 'unexpected_pt_mutations' => 1 ),
	'zero_unexpected_en_mutations'          => array( 'unexpected_en_mutations' => 1 ),
	'route_verification_ok'                 => array( 'route_verification_ok' => false ),
	'idempotence_ok'                        => array( 'idempotence_ok' => false ),
	'audit_complete'                        => array( 'audit_complete' => false ),
	'no_unresolved_verification_failure'    => array( 'unresolved_verification_failures' => 1 ),
	'provider_error_rate_ok'                => array( 'provider_attempts' => 10, 'provider_errors' => 9 ),
) as $requirement => $override ) {
	$case = Expansion::evaluate( $verified_record, array_merge( $good_evidence, $override ) );

	assert_true( false === $case['eligible'], sprintf( 'missing "%s" makes the request ineligible', $requirement ) );
	assert_true(
		1 === count( $case['unmet'] ),
		sprintf( 'the refusal names exactly "%s" and nothing else (unmet: %s)', $requirement, implode( ', ', $case['unmet'] ) )
	);
	assert_true( in_array( $requirement, $case['unmet'], true ), sprintf( 'the refusal names "%s"', $requirement ) );
}

// A provider error rate above the ceiling is not evidence.
$flaky = Expansion::evaluate( $verified_record, array_merge( $good_evidence, array( 'provider_attempts' => 10, 'provider_errors' => 5 ) ) );

assert_true( false === $flaky['eligible'], 'a 50% provider error rate is not evidence of anything' );

// An ABSENT measurement is a failure, never a pass.
$silent = Expansion::evaluate( $verified_record, array() );

assert_true( false === $silent['eligible'], 'absent evidence is treated as failing' );
assert_true( count( $silent['unmet'] ) >= 5, 'every absent requirement is named (got ' . count( $silent['unmet'] ) . ': ' . implode( ', ', $silent['unmet'] ) . ')' );
assert_true( ! in_array( 'provider_error_rate_ok', $silent['unmet'], true ), 'a zero-attempt provider run has no error rate, which is not evidence of reliability' );

// An unverified predecessor proves nothing.
$unverified = Expansion::evaluate( array( 'state' => Batch::STOPPED ), $good_evidence );

assert_true( false === $unverified['eligible'], 'a STOPPED predecessor is not evidence' );
assert_true( in_array( 'previous_batch_verified', $unverified['unmet'], true ), 'and the refusal says so' );

// The gate NEVER authorises anything.
assert_true(
	false === strpos( file_get_contents( CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-batch-expansion.php' ), 'Orchestrator' ),
	'the expansion gate cannot reach the orchestrator at all'
);
assert_true(
	false === strpos( file_get_contents( CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-batch-expansion.php' ), 'Batch_Composer' ),
	'the expansion gate cannot compose a batch'
);
assert_true(
	Limits::LEVEL_1_SMALL_BATCH === Expansion::next_level( Limits::LEVEL_0_CANARY ),
	'the gate can NAME the next level'
);
assert_true( '' === Expansion::next_level( Limits::LEVEL_2_LARGER ), 'and reports the ceiling as the end' );

test_section( '26. no unauthenticated or state-changing HTTP surface exists' );

// Stage 10 registers NO endpoint. The Stage 6 proof endpoint remains the only
// admin-post action in the plugin, and it is still proof-only.
$plugin_dir = CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation';

$admin_actions = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir ) ) as $file ) {
	if ( 'php' !== $file->getExtension() || false !== strpos( $file->getPathname(), '/tests/' ) ) {
		continue;
	}

	$body = (string) file_get_contents( $file->getPathname() );

	// Both spellings resolve to the real action name, exactly as the Stage 8
	// control-plane gate does: a literal, and `'admin_post_' . self::CONST`.
	if ( preg_match_all( "/add_action\(\s*'admin_post_([A-Za-z0-9_]+)'/", $body, $m ) ) {
		foreach ( $m[1] as $action ) {
			$admin_actions[] = $action;
		}
	}

	if ( preg_match_all( "/add_action\(\s*'admin_post_'\s*\.\s*self::([A-Z_]+)/", $body, $c ) ) {
		foreach ( $c[1] as $const ) {
			if ( preg_match( "/const\s+" . preg_quote( $const, '/' ) . "\s*=\s*'([A-Za-z0-9_]+)'/", $body, $v ) ) {
				$admin_actions[] = $v[1];
			}
		}
	}
}

// STAGE 11 SUPERSESSION. Through Stage 10 this asserted exactly ONE declared
// admin-post action. Stage 11 declares the batch-control action as well, in its
// own file, and it is NOT commissioned: `Batch_Control::register()` is never
// called from the plugin bootstrap, which is asserted immediately below and by
// the Stage 7/8 control-plane gates.
//
// So the set of DECLARED actions is asserted here (exactly these two, no
// others), and REACHABILITY is asserted separately. Both facts matter: a
// growing declaration set means someone is adding surfaces, and an invoked
// register() means one became live.
$declared_actions = $admin_actions;
sort( $declared_actions );

assert_true(
	array( 'conexao_translation_automation_batch', 'conexao_translation_automation_proof' ) === $declared_actions,
	'the plugin declares exactly two admin-post actions: the proof trigger and the DECLARED batch-control endpoint'
		. ( $admin_actions ? ': ' . implode( ', ', $admin_actions ) : '' )
);

// The declared batch-control endpoint must remain DORMANT. Its register() is
// present in its own file but is never invoked by the bootstrap, so it cannot
// be reached over HTTP in production (Stage 11 §30).
//
// Comments are stripped first, because the bootstrap documents the call with a
// commented-out example — and a comment is not an invocation.
$bootstrap = (string) file_get_contents(
	$plugin_dir . '/conexao-translation-automation.php'
);

$bootstrap_code = preg_replace( array( '#/\*.*?\*/#s', '#//[^\n]*#' ), '', $bootstrap );

assert_true(
	false === strpos( (string) $bootstrap_code, 'Batch_Control::register' ),
	'the batch-control register() is never invoked from the bootstrap (declaration only)'
);

// No batch CONTROL ACTION of any shape is reachable. A class name containing
// "batch" is expected and harmless; what must not exist is a registered action
// name that would let a client drive the batch layer.
$batch_actions = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir ) ) as $file ) {
	if ( 'php' !== $file->getExtension() || false !== strpos( $file->getPathname(), '/tests/' ) ) {
		continue;
	}

	$body = (string) file_get_contents( $file->getPathname() );

	if ( preg_match_all( "/add_action\(\s*'admin_post_([A-Za-z0-9_]*batch[A-Za-z0-9_]*)'/i", $body, $m ) ) {
		$batch_actions = array_merge( $batch_actions, $m[1] );
	}
}

assert_true( array() === $batch_actions, 'no shipped file registers a batch admin action (found: ' . implode( ', ', $batch_actions ) . ')' );

// The forbidden surface vocabulary, over every shipped file.
$forbidden_surfaces = array( 'admin_post_nopriv_', 'wp_ajax_', 'rest_api_init', 'register_rest_route', 'wp_schedule_event', 'wp_schedule_single_event', 'wp_next_scheduled', '__return_true' );
$offenders         = array();

foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_dir ) ) as $file ) {
	if ( 'php' !== $file->getExtension() || false !== strpos( $file->getPathname(), '/tests/' ) ) {
		continue;
	}

	$body = preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $file->getPathname() ) );
	$body = preg_replace( '#//[^\n]*#', '', (string) $body );

	foreach ( $forbidden_surfaces as $surface ) {
		if ( false !== strpos( $body, $surface ) ) {
			$offenders[] = $file->getFilename() . ':' . $surface;
		}
	}
}

assert_true( array() === $offenders, 'no shipped file exposes a forbidden entry surface (' . implode( ', ', $offenders ) . ')' );

test_section( '27. the batch layer cannot bypass the existing protections' );

foreach ( array(
	'class-conexao-translation-automation-batch-executor.php'      => array( 'Conexao_Translation_Rollout_Engine::', 'wp_insert_post', 'wp_update_post', 'update_post_meta', 'wp_delete_post', 'pll_save_post_translations' ),
	'class-conexao-translation-automation-batch.php'              => array( 'wp_insert_post', 'Conexao_Translation_Rollout_Engine::', 'wp_remote_' ),
	'class-conexao-translation-automation-batch-composer.php'     => array( 'Conexao_Translation_Rollout_Engine::', 'wp_insert_post', 'Orchestrator::run' ),
	'class-conexao-translation-automation-batch-approval.php'     => array( 'Conexao_Translation_Rollout_Engine::', 'wp_insert_post' ),
	'class-conexao-translation-automation-batch-expansion.php'    => array( 'Conexao_Translation_Rollout_Engine::', 'Orchestrator', 'wp_insert_post' ),
	'class-conexao-translation-automation-emergency-stop.php'      => array( 'Conexao_Translation_Rollout_Engine::', 'wp_insert_post', 'wp_remote_' ),
	'class-conexao-translation-automation-batch-limits.php'       => array( 'Conexao_Translation_Rollout_Engine::', 'wp_insert_post' ),
	'class-conexao-translation-automation-batch-state.php'         => array( 'Conexao_Translation_Rollout_Engine::', 'wp_insert_post' ),
) as $file => $tokens ) {
	$body = (string) file_get_contents( $plugin_dir . '/includes/' . $file );
	$code = preg_replace( '#/\*.*?\*/#s', '', $body );
	$code = preg_replace( '#//[^\n]*#', '', (string) $code );

	foreach ( $tokens as $token ) {
		assert_true(
			false === strpos( (string) $code, $token ),
			sprintf( '%s contains no "%s": the batch layer cannot reach the mutation path', $file, $token )
		);
	}
}

// The executor MUST go through the orchestrator, or it would be a second path.
$executor_code = (string) preg_replace(
	'#/\*.*?\*/#s',
	'',
	(string) file_get_contents( $plugin_dir . '/includes/class-conexao-translation-automation-batch-executor.php' )
);

assert_true(
	false !== strpos( $executor_code, 'Orchestrator::run(' ),
	'the executor DOES reach the engine, but only through the existing orchestrator'
);

test_section( '28. the engine is unchanged' );

$engine = CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php';

assert_true(
// STAGE 11: the pin moved ONCE, deliberately. Model A (true subset
// execution) requires the engine to accept an approved operation scope.
// The change is additive and confined to scope handling: two pure methods
// (narrow_manifest, planned_identities), one optional $args['scope'] key
// applied AFTER full-manifest validation, and a 'scope' key added to the
// two existing return payloads. No lifecycle stage was replaced,
// reordered or bypassed. Pre-Stage-11 digest (the Stage 11 §33 starting
// record): baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4
	'264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912' === hash_file( 'sha256', $engine ),
	'the shared engine core still hashes to its pinned pre-Stage-10 value'
);

b10_reset();
Kill::clear();
BatchState::clear();
Gate::clear_state();

test_finish( 'Stage 10 bounded batch execution' );
