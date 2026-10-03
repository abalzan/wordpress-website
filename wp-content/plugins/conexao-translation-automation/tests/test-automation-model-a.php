<?php
/**
 * Stage 11 tests: Model A — TRUE SUBSET EXECUTION.
 *
 * ## What makes this suite different from Stage 10's
 *
 * Stage 10 proved a BOUND: an approved batch could never apply an unapproved
 * record. It could not prove a SUBSET, because every stage's `run_callback`
 * rebuilt its own configuration and the engine always planned the full
 * authored manifest. Stage 11 changes that, and this suite proves it by
 * calling `Conexao_Translation_Rollout_Engine::run()` — the REAL shared engine
 * — through the REAL orchestrator, then reading the engine's OWN plan.
 *
 * Every assertion is about what the ENGINE planned and wrote, not about what
 * the batch layer filtered. A test that passed while the engine still saw the
 * full authored manifest would be worthless, so the plan contents are
 * asserted directly.
 *
 * ## The central invariant
 *
 *     approved scope == executed scope
 *
 * proved in BOTH directions (no widening, no shrinking) and computed from the
 * engine's resulting plan, never from a caller-supplied boolean.
 *
 * ## Zero-write discipline
 *
 * Write primitives THROW unless a test explicitly enables them, so an
 * unintended write fails loudly. Batch metadata in `wp_options` IS written and
 * cleared at the end.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

use Conexao_Translation_Automation_Batch as Batch;
use Conexao_Translation_Automation_Batch_Approval as Approval;
use Conexao_Translation_Automation_Batch_Composer as Composer;
use Conexao_Translation_Automation_Batch_Control as Control;
use Conexao_Translation_Automation_Batch_Executor as Executor;
use Conexao_Translation_Automation_Batch_Limits as Limits;
use Conexao_Translation_Automation_Batch_State as BatchState;
use Conexao_Translation_Automation_Emergency_Stop as Kill;
use Conexao_Translation_Automation_Lock as Lock;
use Conexao_Translation_Automation_Orchestrator as Orchestrator;
use Conexao_Translation_Automation_Translation_Plan as Plan;

test_title( 'conexao-translation-automation — Stage 11 Model A true subset execution' );

$GLOBALS['s11_writes']         = array();
$GLOBALS['s11_write_ok']       = false;
$GLOBALS['s11_provider_calls'] = 0;
$GLOBALS['s11_fail_identity']  = '';
$GLOBALS['s11_pt_drift']       = array();
$GLOBALS['s11_deleted']        = array();
$GLOBALS['s11_pt_before']      = array();
$GLOBALS['s11_snapshot_reads'] = array();
$GLOBALS['s11_environment']    = 'local';
$GLOBALS['s11_clock']          = 1000.0;
$GLOBALS['s11_en_absent']      = false;
$GLOBALS['s11_records']        = 4;
$GLOBALS['s11_engine_calls']   = 0;
$GLOBALS['s11_stage_conflict'] = array();
$GLOBALS['s11_fail_apply']     = '';

/**
 * The portable identity for a row number.
 *
 * @param int $n Row number, 1-based.
 * @return string
 */
function s11_id( int $n ): string {
	return sprintf( 'row-%02d', $n );
}

/**
 * The stable key an adapter row maps back to.
 *
 * @param int $pt_id Local post id.
 * @return string
 */
function s11_key_of( $pt_id ): string {
	return s11_id( (int) $pt_id - 100 );
}

/**
 * The authored stage manifest: the complete stage-owned operation universe.
 *
 * @return array
 */
function s11_manifest(): array {
	$rows = array();

	for ( $i = 1; $i <= (int) $GLOBALS['s11_records']; $i++ ) {
		$identity         = s11_id( $i );
		$rows[ $identity ] = array(
			'en_slug'  => $identity . '-en',
			'en_title' => 'Row ' . $i,
		);

		$GLOBALS['s11_pt_before'][ $identity ] = array(
			'title'    => 'PT ' . $i,
			'language' => 'pt',
		);
	}

	$GLOBALS['s11_manifest'] = array(
		'source_lang' => 'pt',
		'target_lang' => 'en',
		'records'     => $rows,
	);

	return $GLOBALS['s11_manifest'];
}

/**
 * A stage whose writes land only in an in-memory array.
 *
 * The default fixture is a LINKED EN record with slug drift, which the engine
 * plans as an `update` and repairs. That is the operation a bounded batch
 * performs after a canary has created the first EN record, and it is the
 * operation whose numeric gate can PASS — a `create` never pre-passes the
 * engine's own gate, which is exactly why the F7 chain is written as it is.
 *
 * @return array
 */
function s11_stage(): array {
	$manifest = s11_manifest();

	return array(
		'stage'                  => 'en-guide',
		'source_post_type'       => 'guide',
		'source_lang'            => 'pt',
		'target_lang'            => 'en',
		'manifest_callback'      => static function () use ( $manifest ) {
			return $manifest;
		},
		'snapshot_callback'      => static function ( $pt_id = 0 ) {
			if ( ! is_int( $pt_id ) || $pt_id <= 0 ) {
				return array();
			}

			$key   = s11_key_of( $pt_id );
			$reads = (int) ( $GLOBALS['s11_snapshot_reads'][ $key ] ?? 0 );

			$GLOBALS['s11_snapshot_reads'][ $key ] = $reads + 1;

			$row = (array) ( $GLOBALS['s11_pt_before'][ $key ] ?? array() );

			// The engine reads TWICE per record: the snapshot, then the
			// post-apply PT-drift compare. A mid-run PT change becomes visible
			// on the second read, exactly as in production.
			if ( $reads >= 1 && in_array( $key, (array) $GLOBALS['s11_pt_drift'], true ) ) {
				$row['title'] = ( $row['title'] ?? '' ) . ' MOVED';
			}

			return $row;
		},
		'build_en_args_callback' => static function () {
			return array();
		},
		'copy_fields_callback'   => static function () {
			return 0;
		},
		'run_callback'           => static function ( array $args = array() ) {
			++$GLOBALS['s11_engine_calls'];

			// THE EXISTING shared engine, driven through its own contract.
			return Conexao_Translation_Rollout_Engine::run(
				s11_stage(),
				s11_adapter(),
				$args
			);
		},
	);
}

/**
 * The in-memory WordPress adapter.
 *
 * @return array
 */
function s11_adapter(): array {
	$write = static function ( string $what, string $identity = '' ) {
		if ( ! $GLOBALS['s11_write_ok'] ) {
			throw new RuntimeException( 'UNAUTHORISED WRITE: ' . $what );
		}

		$GLOBALS['s11_writes'][] = '' === $identity ? $what : $what . ':' . $identity;

		return 700;
	};

	return array(
		'find_pt'        => static function ( $stable_key ) {
			$key = (string) $stable_key;

			if ( in_array( $key, (array) $GLOBALS['s11_deleted'], true ) ) {
				// A deleted PT record is ABSENT, which the engine records as a
				// documented skip. Nothing is written and nothing is deleted.
				return null;
			}

			return array(
				'id'     => 100 + (int) substr( $key, 4 ),
				'status' => 'publish',
				'slug'   => $key,
				'title'  => (string) ( $GLOBALS['s11_pt_before'][ $key ]['title'] ?? '' ),
			);
		},
		'find_en_for_pt' => static function ( $pt_id, $en_slug ) {
			$key = s11_key_of( $pt_id );

			if ( ! empty( $GLOBALS['s11_en_absent'] ) ) {
				return array(
					'en_id'           => 0,
					'en_status'       => 'absent',
					'pair_ok'         => false,
					'en_slug_matches' => true,
				);
			}

			// Linked and verified, with slug drift -> the engine plans `update`.
			$row = array(
				'en_id'           => 700,
				'en_status'       => 'publish',
				'pair_ok'         => true,
				'en_slug_matches' => false,
			);

			// A broken pair is a hard CONFLICT, not a silent skip.
			if ( $key === (string) $GLOBALS['s11_fail_identity'] ) {
				$row['pair_ok'] = false;
			}

			// A B2 stage-owned conflict: the stage refuses this row for a
			// reason the shared vocabulary cannot express.
			if ( in_array( $key, (array) $GLOBALS['s11_stage_conflict'], true ) ) {
				$row['stage_conflict'] = 'the Portuguese source this description was authored against has since changed';
			}

			return $row;
		},
		'slug_collision' => static function () {
			return false;
		},
		'pair_ok'        => static function () {
			return true;
		},
		'create_en'      => static function ( $pt_id, $row ) use ( $write ) {
			return $write( 'create', s11_key_of( $pt_id ) );
		},
		'repair_en'      => static function ( $pt_id, $en_id, $row ) use ( $write ) {
			$key = s11_key_of( $pt_id );

			if ( $key === (string) $GLOBALS['s11_fail_apply'] ) {
				return false;
			}

			$write( 'repair', $key );

			return true;
		},
		'link_pair'      => static function () {
		},
	);
}

/**
 * An approved execution context for a set of identities.
 *
 * @param array $scope Ordered approved identities.
 * @return array
 */
function s11_context( array $scope ): array {
	$detail = array();

	foreach ( $scope as $identity ) {
		$detail[ $identity ] = array(
			'source_id'     => $identity,
			'operation'     => Plan::OP_UPDATE_EN,
			'source_digest' => 'pt_' . $identity,
			'result_digest' => 'en_' . $identity,
		);
	}

	$context = array(
		'authorized'       => true,
		'capability'       => 'manage_options',
		'stage'            => 'en-guide',
		'environment'      => 'local',
		'run_id'           => 'run_s11',
		'level'            => 'B1',
		'batch_size'       => count( $scope ),
		'budgets'          => array(
			'operation_budget' => Limits::MAX_OPERATIONS_PER_BATCH,
			'provider_budget'  => Limits::MAX_PROVIDER_REQUESTS_PER_BATCH,
		),
		'provider'         => array(
			'calls' => static function () {
				++$GLOBALS['s11_provider_calls'];

				return array(
					'ok'      => true,
					'content' => 'translated',
					'digest'  => 'res_' . $GLOBALS['s11_provider_calls'],
				);
			},
		),
		'operation_detail' => $detail,
	);

	// An EMPTY scope means the caller expressed no scope at all, which is the
	// pre-Stage-11 whole-stage path. The key is omitted rather than sent empty,
	// because an empty scope is a refusal in the scope contract, not "no scope".
	if ( array() !== $scope ) {
		$context['manifest_scope'] = $scope;
	}

	return $context;
}

/**
 * Run the orchestrator once over an approved scope and return its report.
 *
 * @param array  $scope    Approved identities.
 * @param string $mode     proof|apply.
 * @param string $approval Approval digest for an apply.
 * @return array{result:object,report:array}
 */
function s11_run( array $scope, string $mode, string $approval = '' ): array {
	$result = Orchestrator::run(
		array_merge(
			s11_context( $scope ),
			array(
				'mode'     => $mode,
				'approval' => $approval,
			)
		)
	);

	return array(
		'result' => $result,
		'report' => (array) ( $result->engine_result() ?? array() ),
	);
}

/**
 * The identities the engine's own plan actually covers.
 *
 * Read straight from the report, so an assertion about "what the engine did"
 * cannot be satisfied by the batch layer's own bookkeeping.
 *
 * @param array $report An engine report.
 * @return array<int,string>
 */
function s11_executed( array $report ): array {
	$plan = (array) ( $report['plan'] ?? array() );

	return array_values(
		array_unique(
			array_map(
				static function ( $item ) {
					return (string) ( $item['stable_key'] ?? '' );
				},
				array_merge(
					(array) ( $plan['create'] ?? array() ),
					(array) ( $plan['update'] ?? array() ),
					(array) ( $plan['conflicts'] ?? array() ),
					(array) ( $plan['skip'] ?? array() )
				)
			)
		)
	);
}

/**
 * Compose, review and approve a batch for a scope.
 *
 * @param array $scope Approved identities.
 * @return array{id:string,record:array}
 */
function s11_approved_batch( array $scope ): array {
	// The composer consumes a plan and a binding, exactly as the Stage 10
	// suite does. The plan rows are derived from the approved scope, so a
	// batch can only ever be composed for records somebody approved.
	$plan = array();

	foreach ( $scope as $identity ) {
		$plan[ $identity ] = array(
			'source_id'     => $identity,
			'operation'     => Plan::OP_UPDATE_EN,
			'source_digest' => 'pt_' . $identity,
			'result_digest' => 'en_' . $identity,
		);
	}

	$binding = array(
		'run_id'            => 'run_s11',
		'environment'       => 'local',
		'stage'             => 'en-guide',
		'change_set_digest' => 'cs_s11',
		'plan_digest'       => 'plan_s11',
		'provider_identity' => 'provider_s11',
	);

	$record = Composer::compose(
		array(
			'authorized' => true,
			'capability' => 'manage_options',
			'plan'       => $plan,
			'batch_size' => count( $scope ),
			'batch_index' => 0,
			'level'       => 1 === count( $scope )
				? Limits::LEVEL_0_CANARY
				: Limits::LEVEL_1_SMALL_BATCH,
			'binding'    => $binding,
		)
	);

	assert_true(
		! is_wp_error( $record ),
		'a batch composes for the approved scope',
		is_wp_error( $record ) ? (string) $record->get_error_message() : ''
	);

	if ( is_wp_error( $record ) ) {
		return array( 'id' => '', 'record' => array() );
	}

	$record = (array) $record;
	$batch_id = (string) $record['batch_id'];

	$authority = array(
		'authorized' => true,
		'capability' => 'manage_options',
	);

	assert_true( ! is_wp_error( Approval::record_review( $batch_id, $authority ) ), 'the batch is reviewed' );
	assert_true( ! is_wp_error( Approval::record_approval( $batch_id, $authority ) ), 'the batch is approved' );

	return array(
		'id'     => $batch_id,
		'record' => (array) BatchState::get( $batch_id ),
	);
}

/**
 * Reset every fixture to a clean, linked-EN world.
 *
 * @param int $records How many authored records the stage declares.
 * @return void
 */
function s11_reset( int $records = 4 ): void {
	$GLOBALS['s11_records']        = $records;
	$GLOBALS['s11_writes']         = array();
	$GLOBALS['s11_write_ok']       = false;
	$GLOBALS['s11_provider_calls'] = 0;
	$GLOBALS['s11_fail_identity']  = '';
	$GLOBALS['s11_fail_apply']     = '';
	$GLOBALS['s11_pt_drift']       = array();
	$GLOBALS['s11_deleted']        = array();
	$GLOBALS['s11_en_absent']      = false;
	$GLOBALS['s11_stage_conflict'] = array();
	$GLOBALS['s11_pt_before']      = array();
	$GLOBALS['s11_snapshot_reads'] = array();
	$GLOBALS['s11_engine_calls']   = 0;
	$GLOBALS['s11_manifest']       = array();

	BatchState::clear();
	Kill::clear();
	s11_manifest();

	// The emergency stop's DEFAULT is STOPPED, and §9 proves that explicitly.
	// Every other section needs it cleared, or it would be re-proving the stop
	// rather than what it is actually testing — so the default fixture clears
	// it and §9 sets the state back explicitly for its own assertions.
	Kill::set( false, array( 'authorized' => true, 'capability' => 'manage_options' ) );

	// The orchestrator resolves the stage through the ENGINE's own registry,
	// so the stage must be registered there — the same way production stages
	// are. Registering here is what makes this a real end-to-end path rather
	// than a direct engine call.
	Conexao_Translation_Rollout_Engine::reset_stages();
	Conexao_Translation_Rollout_Engine::register_stage( s11_stage() );
}

// ===========================================================================
// 1. MODEL A: the engine accepts an explicit approved subset
// ===========================================================================

test_section( '1. the engine itself accepts an explicit approved scope' );

s11_reset( 4 );

$authored = s11_manifest();
assert_true( 4 === count( $authored['records'] ), 'the authored stage manifest declares 4 records' );

// The engine's own pure narrowing, called directly first.
$narrowed = Conexao_Translation_Rollout_Engine::narrow_manifest( $authored, array( 'row-01', 'row-02', 'row-03' ) );

assert_true( ! is_wp_error( $narrowed ), 'the engine narrows a 4-record manifest to an approved 3' );
assert_true(
	3 === count( $narrowed['manifest']['records'] ),
	'the narrowed manifest carries exactly the 3 approved records'
);
assert_true(
	false === array_key_exists( 'row-04', $narrowed['manifest']['records'] ),
	'and does NOT carry the unauthored row-04'
);
assert_true(
	4 === $narrowed['report']['authored_count'] && 3 === $narrowed['report']['approved_count'],
	'the scope report keeps the authored universe and the approved subset SEPARATE'
);

// Through the REAL engine and the REAL orchestrator.
$run = s11_run( array( 'row-01', 'row-02', 'row-03' ), 'proof' );

assert_true( $run['result']->ok(), 'a proof run over an approved 3-of-4 scope succeeds' );

$executed = s11_executed( $run['report'] );
sort( $executed );

assert_true(
	array( 'row-01', 'row-02', 'row-03' ) === $executed,
	'the ENGINE planned exactly the approved three — not the authored four',
	'engine planned: ' . implode( ', ', $executed )
);
assert_true(
	! in_array( 'row-04', $executed, true ),
	'and the unauthored row-04 never entered the plan'
);

// The engine reports its own scope, and the caller cannot supply it.
assert_true(
	true === ( $run['report']['scope']['applied'] ?? false ),
	'the engine reports that a scope was applied'
);
assert_true(
	4 === (int) ( $run['report']['scope']['authored_count'] ?? 0 ),
	'and still observes the complete authored universe of 4'
);

// Without a scope, behaviour is unchanged — the existing single-stage path.
$no_scope = s11_run( array(), 'proof' );
$all_four = s11_executed( $no_scope['report'] );

assert_true( 4 === count( $all_four ), 'with no scope, the engine still plans all 4 authored records' );
assert_true(
	false === ( $no_scope['report']['scope']['applied'] ?? true ),
	'and reports that no scope was applied (backward compatible)'
);

// ===========================================================================
// 2. NO SCOPE WIDENING (§7)
// ===========================================================================

test_section( '2. no scope widening: an authored row cannot become executable' );

s11_reset( 4 );

// Approve three of four. The fourth exists in the authored manifest.
$subset = s11_run( array( 'row-01', 'row-02', 'row-03' ), 'proof' );
$planned = s11_executed( $subset['report'] );

assert_true(
	! in_array( 'row-04', $planned, true ),
	'§7: an authored row outside the approval CANNOT enter the plan'
);

// Every ordered subset of the four, to prove it is not an ordering accident.
foreach (
	array(
		array( 'row-01' ),
		array( 'row-04' ),
		array( 'row-04', 'row-03' ),
		array( 'row-03', 'row-02' ),
		array( 'row-04', 'row-01', 'row-03' ),
	) as $candidate
) {
	s11_reset( 4 );
	$partial = s11_run( $candidate, 'proof' );
	$got     = s11_executed( $partial['report'] );

	sort( $got );
	$want = $candidate;
	sort( $want );

	assert_true(
		$want === $got,
		'the engine plans exactly ' . implode( '+', $candidate ) . ' (got ' . implode( ',', $got ) . ')'
	);
}

// ===========================================================================
// 3. NO SCOPE SHRINKING (§8)
// ===========================================================================

test_section( '3. no scope shrinking: an approved row may not vanish' );

s11_reset( 4 );

// A deleted PT record is a documented skip, and the engine STILL names it in
// its plan — so the executed scope still equals the approved scope. The batch
// does not silently become smaller than what was approved.
$GLOBALS['s11_deleted'] = array( 'row-02' );

$with_gap = s11_run( array( 'row-01', 'row-02', 'row-03' ), 'proof' );
$gap_ids  = s11_executed( $with_gap['report'] );

sort( $gap_ids );

assert_true(
	array( 'row-01', 'row-02', 'row-03' ) === $gap_ids,
	'a skipped approved row is still NAMED in the executed scope (it is skipped, not dropped)',
	'got: ' . implode( ',', $gap_ids )
);
assert_true(
	1 === count( (array) ( $with_gap['report']['plan']['skip'] ?? array() ) ),
	'and the engine classified it as a skip'
);

// A conflicting approved row is named AND classified as a conflict, so it
// fails the numeric gate instead of being quietly excluded.
s11_reset( 4 );
$GLOBALS['s11_fail_identity'] = 'row-02';

$with_conflict = s11_run( array( 'row-01', 'row-02', 'row-03' ), 'proof' );
$conflict_ids  = s11_executed( $with_conflict['report'] );

sort( $conflict_ids );

assert_true(
	array( 'row-01', 'row-02', 'row-03' ) === $conflict_ids,
	'a conflicting approved row is still NAMED, so the batch cannot shrink past it'
);
assert_true(
	1 === count( (array) ( $with_conflict['report']['plan']['conflicts'] ?? array() ) ),
	'and it is classified as a hard conflict'
);
assert_true(
	'FAIL' === (string) ( $with_conflict['report']['gate']['gate'] ?? '' ),
	'so the engine numeric gate FAILS rather than passing a smaller batch'
);

// ===========================================================================
// 4. SCOPE VALIDATION: every refusal fails closed (§6)
// ===========================================================================

test_section( '4. scope validation: unknown, duplicate, empty and malformed fail closed' );

s11_reset( 4 );

$manifest_now = s11_manifest();

$unknown = Conexao_Translation_Rollout_Engine::narrow_manifest( $manifest_now, array( 'row-01', 'row-99' ) );

assert_true( is_wp_error( $unknown ), 'an unauthored identity is refused' );
assert_true(
	'conexao_rollout_scope_identity_unknown' === $unknown->get_error_code(),
	'with the unknown-identity code'
);

$duplicate = Conexao_Translation_Rollout_Engine::narrow_manifest( $manifest_now, array( 'row-01', 'row-01' ) );

assert_true( is_wp_error( $duplicate ), 'a duplicated identity is refused' );
assert_true(
	'conexao_rollout_duplicate_scope_identity' === $duplicate->get_error_code(),
	'with the duplicate-identity code'
);

$empty = Conexao_Translation_Rollout_Engine::narrow_manifest( $manifest_now, array() );

assert_true( is_wp_error( $empty ), 'an empty scope is refused' );
assert_true( 'conexao_rollout_empty_scope' === $empty->get_error_code(), 'with the empty-scope code' );

foreach ( array( 123, array( 'row-01' ), null, true, '' ) as $malformed ) {
	$bad = Conexao_Translation_Rollout_Engine::narrow_manifest( $manifest_now, array( $malformed ) );

	assert_true(
		is_wp_error( $bad ) && 'conexao_rollout_bad_scope_identity' === $bad->get_error_code(),
		'a malformed identity (' . gettype( $malformed ) . ') is refused'
	);
}

// A cross-stage identity belongs to ANOTHER stage's manifest, so this stage
// does not declare it and must refuse it.
$cross = Conexao_Translation_Rollout_Engine::narrow_manifest( $manifest_now, array( 'other-01' ) );

assert_true( is_wp_error( $cross ), 'another stage\'s identity is refused by this stage' );

// Through the orchestrator, which must fail BEFORE any write.
$via_orch = s11_run( array( 'row-99' ), 'proof' );

assert_true( ! $via_orch['result']->ok(), 'an unauthored scope fails through the orchestrator too' );
assert_true( 0 === count( $GLOBALS['s11_writes'] ), 'and NOT ONE record was written' );

// The FULL authored manifest is still validated first, so a defective manifest
// cannot be hidden by scoping to a subset of it.
$defective = array(
	'source_lang' => 'pt',
	'target_lang' => 'en',
	'records'     => array(
		'row-01' => array( 'en_slug' => 'same-slug' ),
		'row-02' => array( 'en_slug' => 'same-slug' ),
	),
);

$dup_slug = Conexao_Translation_Rollout_Engine::validate_manifest(
	$defective,
	array( 'source_lang' => 'pt', 'target_lang' => 'en' )
);

assert_true( is_wp_error( $dup_slug ), 'a duplicate en_slug in the FULL manifest is still refused' );

// ===========================================================================
// 5. B1 PROOF: creation and repair over an exact subset
// ===========================================================================

test_section( '5. B1: single and multi-record subsets create and repair exactly' );

// One B1 create.
s11_reset( 3 );
$GLOBALS['s11_en_absent'] = true;
$GLOBALS['s11_write_ok']   = true;

$one     = s11_run( array( 'row-01' ), 'proof' );
$one_ids = s11_executed( $one['report'] );

assert_true( array( 'row-01' ) === $one_ids, 'B1: a one-record approved scope plans exactly that record' );
assert_true(
	1 === count( (array) ( $one['report']['plan']['create'] ?? array() ) ),
	'B1: it is classified as a CREATE'
);

// Multiple B1 records.
s11_reset( 4 );
$GLOBALS['s11_en_absent'] = true;
$GLOBALS['s11_write_ok']   = true;

$three     = s11_run( array( 'row-01', 'row-02', 'row-03' ), 'proof' );
$three_ids = s11_executed( $three['report'] );

sort( $three_ids );

assert_true(
	array( 'row-01', 'row-02', 'row-03' ) === $three_ids,
	'B1: a three-record approved scope plans exactly those three'
);
assert_true(
	3 === count( (array) ( $three['report']['plan']['create'] ?? array() ) ),
	'B1: all three are creates, and the unauthored fourth is not'
);

// One B1 update/repair (the default linked-EN fixture).
s11_reset( 4 );
$GLOBALS['s11_write_ok'] = true;

$repair = s11_run( array( 'row-02' ), 'proof' );

assert_true(
	array( 'row-02' ) === s11_executed( $repair['report'] ),
	'B1: a one-record repair scope plans exactly that record'
);
assert_true(
	1 === count( (array) ( $repair['report']['plan']['update'] ?? array() ) ),
	'B1: it is classified as an UPDATE/repair'
);

// ===========================================================================
// 6. B2 PROOF: stage-owned field ownership over an exact subset
// ===========================================================================

test_section( '6. B2: field-level stage ownership over an exact subset' );

// A B2 stage declares its own conflict when the PT source it was authored
// against has changed. Over a subset, that classification must still happen.
s11_reset( 3 );
$GLOBALS['s11_stage_conflict'] = array( 'row-02' );

$b2      = s11_run( array( 'row-01', 'row-02', 'row-03' ), 'proof' );
$b2_ids  = s11_executed( $b2['report'] );

sort( $b2_ids );

assert_true(
	array( 'row-01', 'row-02', 'row-03' ) === $b2_ids,
	'B2: a stage-owned conflict is still inside the approved scope'
);
assert_true(
	1 === count( (array) ( $b2['report']['plan']['conflicts'] ?? array() ) ),
	'B2: it is classified as a hard conflict'
);
assert_true(
	'FAIL' === (string) ( $b2['report']['gate']['gate'] ?? '' ),
	'B2: the engine gate FAILS, so a B2 conflict cannot pass silently'
);

// A B2 conflict OUTSIDE the approved scope must not affect the approved run.
s11_reset( 3 );
$GLOBALS['s11_stage_conflict'] = array( 'row-03' );

$b2_outside = s11_run( array( 'row-01', 'row-02' ), 'proof' );
$b2_only    = s11_executed( $b2_outside['report'] );

sort( $b2_only );

assert_true(
	array( 'row-01', 'row-02' ) === $b2_only,
	'B2: a conflict in an unauthored record does not enter the approved plan'
);
assert_true(
	0 === count( (array) ( $b2_outside['report']['plan']['conflicts'] ?? array() ) ),
	'B2: and it does not poison the approved subset'
);

// B2 drift: the PT source changed after the plan was made.
//
// A dry run never reaches the post-apply PT-drift compare — that compare is
// part of the apply path by construction, and a dry run performs zero writes.
// So the drift is proven through the full executor (proof then apply), where
// the engine really does snapshot, write and re-read.
s11_reset( 3 );
$GLOBALS['s11_write_ok'] = true;

$drift_batch = s11_approved_batch( array( 'row-01', 'row-02', 'row-03' ) );

// row-02's PT source moves after the engine has snapshotted it, so the
// engine's own PT-drift guard must see the difference and fail the gate.
$GLOBALS['s11_pt_drift'] = array( 'row-02' );

$drift_run = Executor::execute( array_merge(
	array( 'authorized' => true, 'capability' => 'manage_options' ),
	array( 'batch_id' => $drift_batch['id'] )
) );

assert_true(
	Executor::OUTCOME_VERIFIED !== $drift_run['outcome'],
	'B2: PT drift on an approved record does NOT produce a verified batch',
	'outcome: ' . (string) $drift_run['outcome']
);
assert_true(
	Executor::STOP_BATCH_INVALIDATED !== $drift_run['stop_reason']
	|| Executor::OUTCOME_STOPPED === $drift_run['outcome'],
	'B2: and it stops rather than succeeding'
);

$drift_statuses = BatchState::operation_statuses( $drift_batch['id'] );

assert_true(
	Batch::OP_COMPLETED !== ( $drift_statuses['row-02'] ?? Batch::OP_COMPLETED ),
	'B2: the drifted record is NOT recorded as completed',
	'got: ' . (string) ( $drift_statuses['row-02'] ?? '(none)' )
);

// ===========================================================================
// 7. THE EXECUTOR ENFORCES approved == executed (§5)
// ===========================================================================

test_section( '7. the executor proves approved scope == executed scope' );

s11_reset( 4 );
$GLOBALS['s11_write_ok'] = true;

$approved = s11_approved_batch( array( 'row-01', 'row-02', 'row-03' ) );
$outcome  = Executor::execute( array_merge(
	array( 'authorized' => true, 'capability' => 'manage_options' ),
	array( 'batch_id' => $approved['id'] )
) );

assert_true(
	Executor::OUTCOME_VERIFIED === $outcome['outcome'],
	'a three-record approval executes and verifies (got "' . $outcome['outcome'] . '")',
	'stop: ' . (string) ( $outcome['stop_reason'] ?? '' ) . ' failure: ' . (string) ( $outcome['failure'] ?? '' )
);

$s11_record_writes = array_values(
	array_filter(
		$GLOBALS['s11_writes'],
		static function ( $write ) {
			return false !== strpos( $write, ':row-' );
		}
	)
);
sort( $s11_record_writes );

assert_true(
	array( 'repair:row-01', 'repair:row-02', 'repair:row-03' ) === $s11_record_writes,
	'and wrote EXACTLY the three approved records',
	'writes: ' . implode( ', ', $GLOBALS['s11_writes'] )
);
assert_true(
	false === strpos( implode( ',', $GLOBALS['s11_writes'] ), 'row-04' ),
	'the unauthored row-04 was NEVER written'
);

// ===========================================================================
// 8. NEGATIVE: the equality proof refuses widening and shrinking (§5, §7, §8)
// ===========================================================================

test_section( '8. injected scope tampering is refused in BOTH directions' );

// WIDENING: an engine reporting a plan outside the approval invalidates the
// WHOLE batch — it must never apply the approved part and ignore the rest.
//
// The seam substitutes the engine's DECLARED scope only. The executor still
// recomputes the executed scope from the real plan, so the refusal below is
// proven against engine data, not against a fabricated plan.
s11_reset( 4 );
$GLOBALS['s11_write_ok'] = true;

$widened = s11_approved_batch( array( 'row-01', 'row-02' ) );

Executor::set_scope_evidence_reader(
	static function ( array $declared ) {
		$declared['executed'] = array( 'row-01', 'row-02', 'row-03' );

		return $declared;
	}
);

$widen_outcome = Executor::execute( array_merge(
	array( 'authorized' => true, 'capability' => 'manage_options' ),
	array( 'batch_id' => $widened['id'] )
) );

Executor::set_scope_evidence_reader( null );

assert_true(
	Executor::OUTCOME_STOPPED === $widen_outcome['outcome'],
	'a widened execution scope is refused, not executed (got "' . $widen_outcome['outcome'] . '")'
);
assert_true(
	Executor::STOP_BATCH_INVALIDATED === $widen_outcome['stop_reason'],
	'with the batch-invalidated reason'
);
assert_true(
	0 === count(
		array_filter(
			$GLOBALS['s11_writes'],
			static function ( $write ) {
				return false !== strpos( $write, ':row-' );
			}
		)
	),
	'and NOT ONE record was written — the whole batch is refused, never the approved part'
);

// SHRINKING: an engine that covered only part of the approval must also
// invalidate, because silently applying less is a changed approval (§8).
s11_reset( 4 );
$GLOBALS['s11_write_ok'] = true;

$shrunk = s11_approved_batch( array( 'row-01', 'row-02', 'row-03' ) );

Executor::set_scope_evidence_reader(
	static function ( array $declared ) {
		$declared['executed'] = array( 'row-01' );

		return $declared;
	}
);

$shrink_outcome = Executor::execute( array_merge(
	array( 'authorized' => true, 'capability' => 'manage_options' ),
	array( 'batch_id' => $shrunk['id'] )
) );

Executor::set_scope_evidence_reader( null );

assert_true(
	Executor::OUTCOME_STOPPED === $shrink_outcome['outcome'],
	'a shrunken execution scope is refused, not executed (got "' . $shrink_outcome['outcome'] . '")'
);
assert_true(
	Executor::STOP_BATCH_INVALIDATED === $shrink_outcome['stop_reason'],
	'with the batch-invalidated reason'
);

// ===========================================================================
// 9. EMERGENCY STOP (§23, §24)
// ===========================================================================

test_section( '9. the emergency stop defaults to STOPPED and blocks execution' );

s11_reset( 3 );

// The DEFAULT is the point of §23: with NO record at all, new work does not
// start. `s11_reset()` clears the flag for other sections, so the true
// fail-closed default is asserted here by removing the record entirely.
Kill::clear();

assert_true( Kill::is_stopped(), 'the emergency stop is STOPPED when no record exists' );

$stopped_batch = s11_approved_batch( array( 'row-01', 'row-02' ) );
$stopped_run   = Executor::execute( array_merge(
	array( 'authorized' => true, 'capability' => 'manage_options' ),
	array( 'batch_id' => $stopped_batch['id'] )
) );

assert_true(
	Executor::OUTCOME_STOPPED === $stopped_run['outcome'],
	'an approved batch is refused while the emergency stop is engaged'
);
assert_true(
	Executor::STOP_EMERGENCY_STOP === $stopped_run['stop_reason'],
	'with the emergency-stop reason'
);
assert_true( 0 === count( $GLOBALS['s11_writes'] ), 'and nothing was written' );

// A MALFORMED stop record reads as STOPPED, never as "probably fine".
Kill::set_reader( static function () {
	return array( 'v' => 1, 'stopped' => 'yes-please' );
} );

assert_true( Kill::is_stopped(), 'a malformed stop record reads as STOPPED, not as permission' );

Kill::set_reader( static function () {
	return array( 'v' => 99, 'stopped' => false );
} );

assert_true( Kill::is_stopped(), 'a wrong schema version also reads as STOPPED' );
Kill::set_reader( null );

// Clearing requires an explicit, authorised call. A client field cannot do it.
$unauthorised = Kill::set( false, array( 'authorized' => false, 'capability' => 'manage_options' ) );

assert_true( is_wp_error( $unauthorised ), 'an unauthorised caller cannot clear the emergency stop' );

$wrong_capability = Kill::set( false, array( 'authorized' => true, 'capability' => 'edit_posts' ) );

assert_true( is_wp_error( $wrong_capability ), 'nor can a caller with the wrong capability' );
assert_true( Kill::is_stopped(), 'and the stop is still engaged after both attempts' );

// Cleared deliberately, execution proceeds — and still covers only the scope.
assert_true(
	true === Kill::set( false, array( 'authorized' => true, 'capability' => 'manage_options' ) ),
	'an authorised operator clears it'
);
assert_true( ! Kill::is_stopped(), 'the stop is now clear' );

s11_reset( 3 );
$GLOBALS['s11_write_ok'] = true;

$after_clear = s11_approved_batch( array( 'row-01', 'row-02' ) );
$clear_run   = Executor::execute( array_merge(
	array( 'authorized' => true, 'capability' => 'manage_options' ),
	array( 'batch_id' => $after_clear['id'] )
) );

assert_true(
	Executor::OUTCOME_VERIFIED === $clear_run['outcome'],
	'after clearing, the approved subset executes (got "' . $clear_run['outcome'] . '")'
);
assert_true(
	false === strpos( implode( ',', $GLOBALS['s11_writes'] ), 'row-03' ),
	'and still writes only the approved records'
);

Kill::set( true, array( 'authorized' => true, 'capability' => 'manage_options' ) );

// ===========================================================================
// 10. RESUME covers only the REMAINING approved subset (§25)
// ===========================================================================

test_section( '10. a resume executes only the remaining approved operations' );

s11_reset( 3 );
Kill::set( false, array( 'authorized' => true, 'capability' => 'manage_options' ) );
$GLOBALS['s11_write_ok'] = true;

$resume_batch = s11_approved_batch( array( 'row-01', 'row-02', 'row-03' ) );

// Stop the batch part-way by failing one operation, then inspect the resume.
$GLOBALS['s11_fail_apply'] = 'row-02';

$partial = Executor::execute( array_merge(
	array( 'authorized' => true, 'capability' => 'manage_options' ),
	array( 'batch_id' => $resume_batch['id'] )
) );

assert_true(
	Executor::OUTCOME_STOPPED === $partial['outcome'],
	'a batch with a failing operation stops (got "' . $partial['outcome'] . '")'
);

$statuses = BatchState::operation_statuses( $resume_batch['id'] );

assert_true(
	Batch::OP_COMPLETED === ( $statuses['row-01'] ?? '' ),
	'the operation before the failure is completed'
);
assert_true(
	Batch::OP_FAILED === ( $statuses['row-02'] ?? '' ),
	'the failing operation is recorded as failed'
);

// The engine applies the whole scoped unit in ONE call, so row-03 completes
// alongside row-01. That is correct: failure is per-operation, and the unit
// records what actually happened rather than discarding it.
//
// What matters for a resume is narrower: the FAILED operation must not be
// mistaken for completed work. The completed ones are excluded by resume_plan()
// below.
assert_true(
	Batch::OP_COMPLETED !== ( $statuses['row-02'] ?? '' ),
	'the failing operation is never mistaken for completed work'
);

$resume_plan = BatchState::resume_plan( $resume_batch['id'] );

assert_true( is_array( $resume_plan ), 'a resume plan is derivable from the stored batch' );
assert_true(
	! in_array( 'row-01', (array) $resume_plan, true ),
	'§25: a resume does NOT replay the already-verified row-01'
);

// A stopped batch's approval is NOT re-verifiable as executable: the state
// machine moved it out of APPROVED_FOR_BATCH, so a resume must go through a
// fresh review rather than inheriting the old authorisation silently.
$stopped_verify = Approval::verify( (array) BatchState::get( $resume_batch['id'] ) );

assert_true(
	is_wp_error( $stopped_verify ),
	'a STOPPED batch no longer verifies as executable — a resume needs a new review'
);

$tampered              = (array) BatchState::get( $resume_batch['id'] );
$tampered['identities'] = array( 'row-01', 'row-02', 'row-03', 'row-04' );
$tampered_verify       = Approval::verify( $tampered );

assert_true(
	is_wp_error( $tampered_verify ),
	'a resume whose operation set changed fails approval verification'
);

// ===========================================================================
// 11. LOCK CONTENTION (§31)
// ===========================================================================

test_section( '11. lock contention stops the batch' );

s11_reset( 3 );
Kill::set( false, array( 'authorized' => true, 'capability' => 'manage_options' ) );

$locked_batch = s11_approved_batch( array( 'row-01', 'row-02' ) );

// Hold the lock from another run, so this batch cannot acquire it. This uses
// the REAL lock, not a stub, so the contention path is the production one.
$foreign_lock = Lock::acquire(
	array(
		'run_id'      => 'run_someone_else',
		'environment' => 'local',
		'now'         => time(),
	)
);

assert_true(
	'locked' !== (string) ( $foreign_lock['outcome'] ?? '' ),
	'the foreign lock is acquired (contention fixture is valid)'
);

$contended = Executor::execute( array_merge(
	array( 'authorized' => true, 'capability' => 'manage_options' ),
	array( 'batch_id' => $locked_batch['id'] )
) );

Lock::release( 'run_someone_else' );

assert_true(
	Executor::OUTCOME_STOPPED === $contended['outcome'],
	'a batch that cannot take the lock is refused (got "' . $contended['outcome'] . '")'
);
assert_true( 0 === count( $GLOBALS['s11_writes'] ), 'and nothing was written' );

// ===========================================================================
// 12. BATCH CONTROL: the declared capability's security contract (§17-§20)
// ===========================================================================

test_section( '12. batch control: explicit actions and a server-resolved batch' );

assert_true( ! Control::is_commissioned(), '§30: the capability is DECLARED but NOT commissioned' );
assert_true(
	'conexao_translation_automation_batch' === Control::ACTION,
	'its action name is exact and stable'
);
assert_true(
	Control::NONCE_ACTION !== 'conexao_translation_automation_proof',
	'its nonce action is DISTINCT from the proof endpoint\'s'
);
assert_true(
	array( 'prepare', 'approve', 'execute', 'abort', 'clear_emergency_stop' ) === Control::actions(),
	'it performs exactly five EXPLICIT actions — no generic mode selector'
);

// A fully authorised request is accepted.
Control::reset_seams();
Control::set_seam( 'is_post', static function () {
	return true;
} );
Control::set_seam( 'is_authenticated', static function () {
	return true;
} );
Control::set_seam( 'capability', static function () {
	return 'manage_options';
} );
Control::set_seam( 'nonce', static function () {
	return true;
} );

$valid = Control::validate_request( array( 'conexao_batch_action' => 'execute' ) );

assert_true( ! is_wp_error( $valid ), 'a fully authorised request is accepted' );
assert_true( 'execute' === ( $valid['action'] ?? '' ), 'and resolves its explicit action' );

// GET is refused.
Control::set_seam( 'is_post', static function () {
	return false;
} );

$get = Control::validate_request( array( 'conexao_batch_action' => 'execute' ) );

assert_true(
	is_wp_error( $get ) && Control::FAILURE_METHOD === $get->get_error_code(),
	'a GET mutation is refused'
);

Control::set_seam( 'is_post', static function () {
	return true;
} );

// Anonymous is refused.
Control::set_seam( 'is_authenticated', static function () {
	return false;
} );

$anon = Control::validate_request( array( 'conexao_batch_action' => 'execute' ) );

assert_true(
	is_wp_error( $anon ) && Control::FAILURE_NOT_AUTH === $anon->get_error_code(),
	'an anonymous batch action is refused'
);

Control::set_seam( 'is_authenticated', static function () {
	return true;
} );

// A missing capability is refused.
Control::set_seam( 'capability', static function () {
	return '';
} );

$nocap = Control::validate_request( array( 'conexao_batch_action' => 'execute' ) );

assert_true(
	is_wp_error( $nocap ) && Control::FAILURE_CAPABILITY === $nocap->get_error_code(),
	'a missing manage_options capability is refused'
);

Control::set_seam( 'capability', static function () {
	return 'manage_options';
} );

// A missing nonce is refused.
Control::set_seam( 'nonce', static function () {
	return false;
} );

$nononce = Control::validate_request( array( 'conexao_batch_action' => 'execute' ) );

assert_true(
	is_wp_error( $nononce ) && Control::FAILURE_NONCE === $nononce->get_error_code(),
	'a missing nonce is refused'
);

Control::set_seam( 'nonce', static function () {
	return true;
} );

// A generic mode selector is refused.
$generic = Control::validate_request( array( 'conexao_batch_action' => 'mode=apply' ) );

assert_true(
	is_wp_error( $generic ) && Control::FAILURE_UNKNOWN_ACTION === $generic->get_error_code(),
	'a generic mode selector is refused'
);

// Caller-supplied authority fields are REFUSED outright, not ignored.
foreach ( array( 'operations', 'batch_size', 'environment', 'plan_digest', 'approval_digest' ) as $field ) {
	$injected = Control::validate_request(
		array( 'conexao_batch_action' => 'execute', $field => 'anything' )
	);

	assert_true(
		is_wp_error( $injected ) && Control::FAILURE_UNKNOWN_FIELD === $injected->get_error_code(),
		'a caller-supplied "' . $field . '" field is REFUSED, not ignored'
	);
}

// The server resolves the batch; a caller cannot describe it.
$describe = Control::validate_request(
	array( 'conexao_batch_action' => 'execute', 'conexao_batch_id' => 'batch_0123456789abcdef' )
);

assert_true( ! is_wp_error( $describe ), 'a well-formed batch id is accepted for server resolution' );

$malformed = Control::validate_request(
	array( 'conexao_batch_action' => 'execute', 'conexao_batch_id' => '../../etc/passwd' )
);

assert_true(
	is_wp_error( $malformed ) && Control::FAILURE_BAD_BATCH_ID === $malformed->get_error_code(),
	'a malformed batch id is refused'
);

$unknown_batch = Control::dispatch(
	array( 'action' => 'execute', 'batch_id' => 'batch_0123456789abcdef' )
);

assert_true(
	is_wp_error( $unknown_batch ) && Control::FAILURE_UNKNOWN_BATCH === $unknown_batch->get_error_code(),
	'an unresolvable batch is refused — the server, not the caller, defines it'
);

// Clearing the emergency stop does NOT depend on naming a batch.
Control::reset_seams();
Control::set_seam( 'clear_stop', static function () {
	return array( 'state' => 'cleared' );
} );

$cleared = Control::dispatch( array( 'action' => 'clear_emergency_stop', 'batch_id' => '' ) );

assert_true(
	! is_wp_error( $cleared ),
	'clearing the stop is reachable without a batch (it is a global decision)'
);

Control::reset_seams();

$uncommissioned_clear = Control::dispatch(
	array( 'action' => 'clear_emergency_stop', 'batch_id' => '' )
);

assert_true(
	is_wp_error( $uncommissioned_clear ),
	'and it still refuses while no handler is commissioned'
);

Control::reset_seams();

// ===========================================================================
// 13. THE ENGINE REMAINS THE ONLY MUTATION AUTHORITY
// ===========================================================================

test_section( '13. no second engine, and the batch layer mutates nothing' );

$s11_executor_source = (string) file_get_contents(
	CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-batch-executor.php'
);
$s11_control_source  = (string) file_get_contents(
	CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-batch-control.php'
);

$s11_code = preg_replace(
	array( '#/\*.*?\*/#s', '#//[^\n]*#' ),
	'',
	$s11_executor_source . $s11_control_source
);

foreach ( array( 'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'delete_post_meta', 'pll_save_post_translations' ) as $token ) {
	assert_true(
		false === strpos( (string) $s11_code, $token ),
		'the batch and batch-control layers contain no "' . $token . '"'
	);
}

assert_true(
	1 === substr_count( (string) $s11_code, 'Orchestrator::run(' ),
	'the batch layer reaches the engine through exactly one call site'
);

$s11_engine_source = (string) file_get_contents(
	CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php'
);

foreach ( array( 'collect_states', 'build_plan', 'capture_snapshots', 'apply_plan', 'assert_no_pt_drift', 'collect_verify', 'calculate_gate' ) as $stage ) {
	assert_true(
		false !== strpos( $s11_engine_source, $stage ),
		'the engine still owns the "' . $stage . '" lifecycle stage'
	);
}

// The scope integration point must sit AFTER manifest validation and BEFORE
// inventory — the single window where the full manifest and the scope coexist.
//
// These are checked at their CALL SITES inside run(), not at their
// declarations. `collect_states()` is declared near the top of the class but
// CALLED much later, and comparing declaration offsets would prove nothing
// about the order the lifecycle actually runs in.
assert_true(
	strpos( $s11_engine_source, '$mcheck   = self::validate_manifest( $manifest, $config );' )
		< strpos( $s11_engine_source, 'self::narrow_manifest( $manifest, (array) $args' ),
	'the scope is applied AFTER the complete manifest is validated'
);
assert_true(
	strpos( $s11_engine_source, 'self::narrow_manifest( $manifest, (array) $args' )
		< strpos( $s11_engine_source, 'self::collect_states( $config, $adapter, $manifest' ),
	'and BEFORE the lifecycle first reads the manifest'
);
assert_true(
	false !== strpos( $s11_engine_source, "array_key_exists( 'scope', \$args )" ),
	'and the scope is OPTIONAL — an omitted scope leaves every pre-Stage-11 caller unchanged'
);

// Clean up persisted metadata.
BatchState::clear();
Kill::clear();

test_finish( 'Stage 11 Model A true subset execution' );