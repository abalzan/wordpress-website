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

use Conexao_Translation_Automation_Batch_Approval as Approval;
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

	return array(
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
		'manifest_scope'   => $scope,
		'operation_detail' => $detail,
	);
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
	$batch = Conexao_Translation_Automation_Batch_Composer::compose( s11_context( $scope ) );

	assert_true( ! is_wp_error( $batch ), 'a batch composes for the approved scope', (string) ( is_wp_error( $batch ) ? $batch->get_error_message() : '' ) );

	$batch_id  = $batch->batch_id();
	$authority = array(
		'authorized' => true,
		'capability' => 'manage_options',
	);

	assert_true( ! is_wp_error( Approval::record_review( $batch_id, $authority ) ), 'the batch is reviewed' );
	assert_true( ! is_wp_error( Approval::record_approval( $batch_id, $authority ) ), 'the batch is approved' );

	$record = BatchState::get( $batch_id );

	return array(
		'id'     => $batch_id,
		'record' => (array) $record,
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
}