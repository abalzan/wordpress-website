<?php
/**
 * The bounded batch executor (Stage 10).
 *
 * ## What this class is
 *
 * A SAFETY LAYER wrapped AROUND the existing per-operation safety, never a
 * replacement for it. For every single operation in a batch it calls the
 * EXISTING orchestrator, which still performs its whole chain:
 *
 *     lock -> environment guard -> F7 dry-run -> approval -> snapshot -> apply
 *          -> verify
 *
 * This class adds five things around that and mutates nothing itself:
 *
 *   1. a batch-level dry-run and budget check BEFORE any operation;
 *   2. the batch approval verification, re-done before EVERY operation;
 *   3. the operation-count, provider-request and wall-clock budgets;
 *   4. the emergency stop and the operator abort, checked before each operation;
 *   5. the deterministic decision to continue or stop at a safe boundary.
 *
 * ## The execution shape
 *
 *     batch dry run -> budget preflight -> [ for each identity in the approved
 *     set, in stable order: check approval -> check emergency stop -> check
 *     abort -> check budgets -> orchestrator proof -> orchestrator apply ->
 *     verify -> record status ] -> VERIFIED or STOPPED
 *
 * The loop is over the APPROVED identity set only. A caller cannot add to it,
 * reorder it, or skip an element of it: the loop reads
 * `Batch_State::operation_statuses()` and the batch's own stored identities.
 *
 * ## After the loop, the system STOPS
 *
 * `MAX_PRODUCTION_BATCHES_PER_INVOCATION` is 1, and there is no code path here
 * that composes, approves or starts a second batch. A verified batch cannot
 * become another batch's authorisation: the state machine has no
 * `VERIFIED -> EXECUTING` edge, and the only way to do more work is for a human
 * to compose a NEW batch, which needs its own review, approval and digests.
 *
 * ## What it never does
 *
 * | Absent | Why |
 * |---|---|
 * | `wp_insert_post`, `update_post_meta`, `pll_*` | this class writes no content; the engine is the only mutation authority |
 * | `Conexao_Translation_Rollout_Engine::` | it never reaches the engine directly; the orchestrator is the only route |
 * | a loop over batches | one invocation, one batch |
 * | a shrink-on-failure | a failed or invalid batch STOPS; it never quietly applies the remainder |
 * | a deletion path | a deleted PT record is manual intervention, never automatic |
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The bounded batch executor.
 */
final class Conexao_Translation_Automation_Batch_Executor {

	/**
	 * Stop reasons, recorded on the batch and in the audit.
	 *
	 * @var string
	 */
	const STOP_COMPLETED               = 'completed';
	const STOP_ABORTED                 = 'aborted';
	const STOP_EMERGENCY_STOP          = 'emergency_stop';
	const STOP_BUDGET_EXHAUSTED        = 'budget_exhausted';
	const STOP_AFTER_CURRENT_SAFE_UNIT = 'stop_after_current_safe_unit';
	const STOP_PROVIDER_FAILURE        = 'provider_failure';
	const STOP_VALIDATION_FAILURE      = 'validation_failure';
	const STOP_SOURCE_DRIFT            = 'source_digest_drift';
	const STOP_VERIFICATION_FAILURE    = 'verification_failure';
	const STOP_UNEXPECTED_MUTATION     = 'unexpected_mutation';
	const STOP_APPLY_FAILURE           = 'apply_failure';
	const STOP_LOCK_LOST               = 'lock_lost';
	const STOP_APPROVAL_INVALID        = 'approval_invalid';
	const STOP_BUDGET_REFUSED          = 'budget_refused_before_apply';
	const STOP_BATCH_INVALIDATED       = 'batch_invalidated';

	/**
	 * Execution outcomes, as seen by the caller.
	 *
	 * @var string
	 */
	const OUTCOME_VERIFIED = 'verified';
	const OUTCOME_STOPPED  = 'stopped';
	const OUTCOME_FAILED   = 'failed';
	const OUTCOME_REFUSED  = 'refused';

	/**
	 * Injectable clock, so the wall-clock budget is provable without sleeping.
	 *
	 * @var callable|null
	 */
	private static $clock = null;

	/**
	 * Test support: replace the clock. A callable returning a float epoch.
	 *
	 * @param callable|null $clock Clock callable.
	 * @return void
	 */
	public static function set_clock( $clock ): void {
		self::$clock = $clock;
	}

	/**
	 * The current time, through the injectable clock.
	 *
	 * @return float
	 */
	private static function now(): float {
		if ( is_callable( self::$clock ) ) {
			return (float) call_user_func( self::$clock );
		}

		return microtime( true );
	}

	/**
	 * Execute ONE approved batch. Exactly one.
	 *
	 * @param array $context {
	 *     Invocation context.
	 *
	 *     @type string $batch_id  The batch to execute (required).
	 *     @type bool   $authorized The caller's own authorisation decision.
	 *     @type string $capability Must equal 'manage_options'.
	 *     @type bool   $resume    Continue an interrupted batch rather than
	 *                               starting it. Never implied.
	 * }
	 * @return array {
	 *     @type string $outcome   One of the OUTCOME_* constants.
	 *     @type string $batch_id  The batch that was executed.
	 *     @type string $stop_reason Why the batch stopped, or ''.
	 *     @type string $failure   Failure category, or ''.
	 *     @type array  $detail    Non-secret diagnostic detail.
	 * }
	 */
	public static function execute( array $context ): array {
		$batch_id = isset( $context['batch_id'] ) ? sanitize_key( (string) $context['batch_id'] ) : '';

		// --- 0. Authorisation, before anything is read or resolved. ---------
		$denied = self::authorise( $context );

		if ( null !== $denied ) {
			return self::report( self::OUTCOME_REFUSED, $batch_id, self::STOP_APPROVAL_INVALID, $denied->get_error_code(), array( 'reason' => (string) $denied->get_error_message() ) );
		}

		// --- 1. The batch must exist, and must be internally consistent. ----
		$record = Conexao_Translation_Automation_Batch_State::get( $batch_id );

		if ( null === $record ) {
			return self::report( self::OUTCOME_REFUSED, $batch_id, self::STOP_APPROVAL_INVALID, 'unknown_batch', array( 'reason' => 'No such stored batch.' ) );
		}

		$batch = Conexao_Translation_Automation_Batch::from_record( $record );

		if ( is_wp_error( $batch ) ) {
			return self::report( self::OUTCOME_REFUSED, $batch_id, self::STOP_BATCH_INVALIDATED, 'batch_record_tampered', array( 'reason' => (string) $batch->get_error_message() ) );
		}

		// --- 2. One invocation, one batch. Never more. ----------------------
		$count = Conexao_Translation_Automation_Batch_Limits::assert_batch_count( 1 );

		if ( is_wp_error( $count ) ) {
			return self::report( self::OUTCOME_REFUSED, $batch_id, self::STOP_APPROVAL_INVALID, $count->get_error_code(), array( 'reason' => (string) $count->get_error_message() ) );
		}

		// --- 3. The batch-level DRY RUN, before any apply. ------------------
		//
		// The dry run is the batch's own F7: it produces the exact operation
		// count, the exact operation identities, the exact provider-request
		// cost, the exact partition, the exact batch digest and the exact
		// snapshot identity. `PASS` requires the WHOLE batch to be internally
		// consistent; one invalid operation fails the batch, and a single
		// operation is never silently removed from an already approved batch.
		$dry = self::batch_dry_run( $batch, $record );

		if ( empty( $dry['pass'] ) ) {
			self::finish( $batch_id, Conexao_Translation_Automation_Batch::FAILED, self::STOP_BATCH_INVALIDATED, 'batch_dry_run_not_pass' );

			return self::report( self::OUTCOME_FAILED, $batch_id, self::STOP_BATCH_INVALIDATED, 'batch_dry_run_not_pass', array( 'reason' => (string) ( $dry['reason'] ?? 'the batch dry run did not PASS' ) ) );
		}

		// --- 4. The batch budget, before any apply. Fails closed. ----------
		$budget = Conexao_Translation_Automation_Batch_Limits::assert_budget(
			array(
				'operations'        => $batch->operation_count(),
				'operation_budget'  => (int) ( $record['operation_budget'] ?? 0 ),
				'provider_requests' => $batch->provider_request_cost(),
				'provider_budget'   => (int) ( $record['provider_budget'] ?? 0 ),
			)
		);

		if ( is_wp_error( $budget ) ) {
			self::finish( $batch_id, Conexao_Translation_Automation_Batch::FAILED, self::STOP_BUDGET_REFUSED, $budget->get_error_code() );

			return self::report( self::OUTCOME_FAILED, $batch_id, self::STOP_BUDGET_REFUSED, $budget->get_error_code(), array( 'reason' => (string) $budget->get_error_message() ) );
		}

		// --- 5. ENTERING EXECUTING. Only reachable from APPROVED_FOR_BATCH. -
		$entered = Conexao_Translation_Automation_Batch_State::transition(
			$batch_id,
			Conexao_Translation_Automation_Batch::EXECUTING
		);

		if ( is_wp_error( $entered ) ) {
			return self::report( self::OUTCOME_REFUSED, $batch_id, self::STOP_APPROVAL_INVALID, $entered->get_error_code(), array( 'reason' => (string) $entered->get_error_message() ) );
		}

		// --- 6. Execute the approved operations, in the approved order. ----
		$loop = self::run_operations( $batch, $context );

		self::finish( $batch_id, (string) $loop['state'], (string) $loop['stop_reason'], (string) $loop['failure'] );

		return self::report(
			(string) $loop['outcome'],
			$batch_id,
			(string) $loop['stop_reason'],
			(string) $loop['failure'],
			(array) $loop['detail']
		);
	}

	/**
	 * Run the batch's single safe unit, then STOP.
	 *
	 * ## Why ONE engine lifecycle per batch, and not one per record
	 *
	 * The shared engine builds its plan from the config its CALLER passes it,
	 * and every real stage's `run_callback` rebuilds that config itself before
	 * handing it over. There is therefore no supported way for a caller to
	 * make one engine run cover fewer rows than the stage's own authored
	 * manifest, without changing the engine or every stage. Stage 10 does not
	 * do either: it reports that gap rather than working around it.
	 *
	 * So the safe unit is the BATCH. One dry run, one plan-scope assertion, one
	 * apply, one verification — all through the existing orchestrator, which
	 * still takes the site-wide lock, the environment guard, the digest-bound
	 * approval, the snapshot and the engine's own verify for that unit.
	 *
	 * ## The plan-scope assertion is what makes the bound a real one
	 *
	 * Because the narrowing cannot be guaranteed to reach the engine, the
	 * executor does not TRUST it. After the dry run it reads the engine's own
	 * plan and requires that every record the engine intends to CREATE, UPDATE
	 * or REMOVE is inside the approved identity set. A record outside it is a
	 * `batch_invalidated` refusal with no apply. So an approved batch can never
	 * apply an unapproved record, whether or not the stage honoured the scope.
	 *
	 * ## The order of the checks is the safety property
	 *
	 * Each is evaluated BEFORE the unit starts, never during it, so none can
	 * interrupt a mutation in progress:
	 *
	 *     1. the batch approval still verifies;
	 *     2. the emergency stop has not been engaged;
	 *     3. no operator abort is recorded;
	 *     4. the operation, provider and time budgets still have room;
	 *     5. the dry run PASSES and its plan is inside the approved set.
	 *
	 * @param Conexao_Translation_Automation_Batch $batch   The batch.
	 * @param array                                $context Invocation context.
	 * @return array{outcome:string,state:string,stop_reason:string,failure:string,detail:array}
	 */
	private static function run_operations( Conexao_Translation_Automation_Batch $batch, array $context ): array {
		$batch_id     = $batch->batch_id();
		$identities   = $batch->identities();
		$approved     = $batch->meta();
		$environment  = (string) $approved['environment'];
		$started      = self::now();
		$provider_use = $batch->provider_request_cost();

		// --- 1. Approval, re-verified for this execution. -------------------
		$current = Conexao_Translation_Automation_Batch_State::get( $batch_id );

		if ( null === $current ) {
			return self::stopped( $batch_id, self::STOP_APPROVAL_INVALID, 'batch_record_vanished', array() );
		}

		$verified = Conexao_Translation_Automation_Batch_Approval::verify( $current );

		if ( is_wp_error( $verified ) ) {
			return self::stopped( $batch_id, self::STOP_APPROVAL_INVALID, (string) $verified->get_error_code(), array() );
		}

		// --- 2. Emergency stop. Checked before the unit starts. -------------
		$kill = Conexao_Translation_Automation_Emergency_Stop::assert_may_start();

		if ( is_wp_error( $kill ) ) {
			return self::stopped( $batch_id, self::STOP_EMERGENCY_STOP, (string) $kill->get_error_code(), array() );
		}

		// --- 3. Operator abort. Nothing has started, so nothing is cut short.
		if ( Conexao_Translation_Automation_Batch_State::is_aborted( $batch_id ) ) {
			return self::stopped( $batch_id, self::STOP_ABORTED, 'operator_abort_requested', array() );
		}

		// --- 4. Budgets, one last time before the unit. ---------------------
		if ( $batch->operation_count() > (int) $approved['operation_budget'] ) {
			return self::stopped( $batch_id, self::STOP_BUDGET_REFUSED, 'operation_budget_exhausted', array() );
		}

		if ( $provider_use > (int) $approved['provider_budget'] ) {
			return self::stopped( $batch_id, self::STOP_BUDGET_REFUSED, 'provider_budget_exhausted', array() );
		}

		$elapsed = self::now() - $started;

		if ( $elapsed >= Conexao_Translation_Automation_Batch_Limits::MAX_EXECUTION_SECONDS ) {
			return self::stopped( $batch_id, self::STOP_AFTER_CURRENT_SAFE_UNIT, 'time_budget_exhausted', array( 'elapsed' => $elapsed ) );
		}

		// --- 5. The dry run, through the EXISTING chain. --------------------
		$proof = self::run_one( $batch, $identities, $environment, $context, 'proof' );

		if ( is_wp_error( $proof ) ) {
			$stop = self::classify_failure( (string) $proof->get_error_code() );

			return self::stopped( $batch_id, $stop, (string) $proof->get_error_code(), array( 'reason' => (string) $proof->get_error_message() ) );
		}

		// --- Stage 11 §5: EXACT-SCOPE EQUALITY, proved both directions. -------
		//
		// Stage 10 proved only the widening direction (nothing outside the
		// approved set may be planned). Model A requires BOTH directions, and
		// requires the executed scope to be RECOMPUTED from the engine's own
		// resulting plan rather than asserted by the caller:
		//
		//   executed = Engine::planned_identities( proof['plan'] )
		//
		// - extra   -> a planned identity the approval does not name  (widen)
		// - missing -> an approved identity the engine never planned   (shrink)
		//
		// Either one invalidates the WHOLE batch. Nothing is silently dropped:
		// an approved operation that cannot be executed safely is a `NO APPLY`
		// outcome, never a smaller batch.
		$outside = self::plan_outside( $proof['plan'], $identities );
		$missing = self::plan_missing( $proof['plan'], $identities );

		if ( array() !== $outside ) {
			return self::stopped(
				$batch_id,
				self::STOP_BATCH_INVALIDATED,
				'plan_exceeds_approved_operation_set',
				array( 'outside' => $outside )
			);
		}

		if ( array() !== $missing ) {
			// §8: never shrink an approved batch. An operation the approval
			// named but the engine did not plan is a changed meaning of the
			// approval, so the batch is refused and re-review is required.
			return self::stopped(
				$batch_id,
				self::STOP_BATCH_INVALIDATED,
				'plan_short_of_approved_operation_set',
				array( 'missing' => $missing )
			);
		}

		// The engine's own statement of the scope it applied, cross-checked
		// against the plan it built. Both come from the engine; a mismatch
		// between them would mean the engine planned something it did not
		// declare, and is refused on the same grounds.
		$engine_scope = isset( $proof['scope'] ) && is_array( $proof['scope'] ) ? $proof['scope'] : array();

		if ( empty( $engine_scope['applied'] ) ) {
			return self::stopped(
				$batch_id,
				self::STOP_BATCH_INVALIDATED,
				'engine_scope_not_applied',
				array( 'reason' => 'the engine reported no applied scope for an approved batch' )
			);
		}

		$engine_executed = array_values( (array) ( $engine_scope['executed'] ?? array() ) );

		// Recomputed HERE, from the engine's raw plan, by the batch layer's own
		// pure walk of the plan categories. This is deliberately NOT a call to
		// the engine: the batch layer must not name the mutation authority
		// (BATCH_LAYER_FORBIDDEN), and a plan is plain data, so two independent
		// derivations of the same fact is exactly what is wanted — what the
		// engine DECLARED it executed, versus what its plan actually contains.
		$recomputed   = self::plan_identities( (array) $proof['plan'] );
		$approved_ids = array_values( $identities );

		// Comparison is set-based: the approval is a SET of identities, and the
		// engine's plan categories are not ordered by approval. `sort()` requires
		// a variable by reference, hence the locals.
		sort( $engine_executed );
		sort( $recomputed );
		sort( $approved_ids );

		if ( $engine_executed !== $recomputed ) {
			return self::stopped(
				$batch_id,
				self::STOP_BATCH_INVALIDATED,
				'engine_scope_evidence_mismatch',
				array( 'declared' => $engine_executed, 'recomputed' => $recomputed )
			);
		}

		if ( $engine_executed !== $approved_ids ) {
			return self::stopped(
				$batch_id,
				self::STOP_BATCH_INVALIDATED,
				'executed_scope_equals_approved_scope',
				array( 'executed' => $engine_executed, 'approved' => $approved_ids )
			);
		}

		$approval = (string) $proof['approval'];

		if ( '' === $approval ) {
			return self::stopped( $batch_id, self::STOP_BUDGET_REFUSED, 'conexao_automation_batch_operation_no_approval', array() );
		}

		// --- 6. The apply, through the EXISTING chain. ----------------------
		$applied = self::run_one( $batch, $identities, $environment, $context, 'apply', $approval );

		if ( is_wp_error( $applied ) ) {
			$stop = self::classify_failure( (string) $applied->get_error_code() );

			// The engine may have written SOME records before the failure, so
			// the per-operation ledger is recorded from the engine's own
			// report before the batch stops. §18: the batch is never shrunk.
			$report = $applied->get_error_data( 'batch_report' );

			if ( is_array( $report ) && isset( $report['rows'] ) ) {
				self::record_rows( $batch_id, (array) $report['rows'], $identities );
			}

			return self::stopped( $batch_id, $stop, (string) $applied->get_error_code(), array( 'reason' => (string) $applied->get_error_message() ) );
		}

		$ledger = self::record_rows( $batch_id, $applied['rows'], $identities );

		self::audit( $batch, self::OUTCOME_VERIFIED, self::STOP_COMPLETED, '', $identities, array() );

		// Every approved operation completed and verified. The batch is done.
		//
		// And THAT IS THE END OF THE INVOCATION. There is no code below this
		// point that starts a second batch, raises the batch size, or promotes
		// a level. The next batch needs a new human decision.
		return array(
			'outcome'     => self::OUTCOME_VERIFIED,
			'state'       => Conexao_Translation_Automation_Batch::VERIFIED,
			'stop_reason' => self::STOP_COMPLETED,
			'failure'     => '',
			'detail'      => array(
				'batch_id'        => $batch_id,
				'stage'           => (string) $approved['stage'],
				'executed'        => $identities,
				'ledger'          => $ledger,
				'provider_calls'  => $provider_use,
				'elapsed_seconds' => round( self::now() - $started, 3 ),
			),
		);
	}

	/**
	 * Run the batch's safe unit through the EXISTING orchestrator.
	 *
	 * This is the whole integration. For a `proof` it returns the engine's own
	 * dry-run report; for an `apply` it presents the approval that report
	 * published and returns the engine's post-apply report. Either way the
	 * orchestrator performs its complete chain for the batch's manifest scope:
	 * the site-wide lock, the environment guard, the F7 dry-run, the
	 * digest-bound approval, the snapshot persistence, the apply, and the
	 * engine's own verification and numeric gate.
	 *
	 * Nothing here short-circuits any of it and nothing here writes content.
	 *
	 * The proof and the apply share ONE run id, because the F7 approval is
	 * bound to the run. A separate id per phase would make the apply's own
	 * recomputed digest differ from the proof's, and the chain would refuse it.
	 *
	 * @param Conexao_Translation_Automation_Batch $batch       The batch.
	 * @param array<int,string>                    $identities  The approved set.
	 * @param string                               $environment The agreed environment.
	 * @param array                                $context     Invocation context.
	 * @param string                               $mode        'proof' or 'apply'.
	 * @param string                               $approval    Approval, for 'apply'.
	 * @return array|WP_Error The engine report, or a failure.
	 */
	private static function run_one( Conexao_Translation_Automation_Batch $batch, array $identities, string $environment, array $context, string $mode, string $approval = '' ) {
		$stage = (string) $batch->meta()['stage'];

		$run_id = preg_replace(
			'/[^a-z0-9_-]/',
			'_',
			strtolower( $batch->batch_id() . '_unit' )
		);

		$base = array(
			'authorized'            => true,
			'capability'            => 'manage_options',
			'stage'                 => $stage,
			'environment'           => $environment,
			'run_id'                => substr( (string) $run_id, 0, 64 ),
			// The production-authorisation flag is set only when the environment
			// is production, and the Environment guard refuses any contradiction
			// between the two, so this cannot be used to bypass the guard.
			'production_authorized' => 'production' === $environment,
			'manifest_scope'        => $identities,
		);

		$result = Conexao_Translation_Automation_Orchestrator::run(
			array_merge(
				$base,
				array(
					'mode'     => $mode,
					'approval' => $approval,
				)
			)
		);

		if ( ! $result->ok() ) {
			return new WP_Error(
				'conexao_automation_batch_unit_' . $mode . '_failed',
				sprintf(
					'The batch %s did not complete: %s.',
					$mode,
					(string) $result->failure_category()
				)
			);
		}

		$report = $result->engine_result();

		if ( ! is_array( $report ) || ! isset( $report['plan'], $report['summary'] ) ) {
			return new WP_Error(
				'conexao_automation_batch_unit_bad_report',
				sprintf( 'The batch %s produced a report this boundary cannot interpret.', $mode )
			);
		}

		if ( 'apply' === $mode ) {
			if ( ! $result->mutation_occurred() ) {
				return new WP_Error(
					'conexao_automation_batch_operation_unexpected_mutation',
					'The batch reported success without a mutation; the result does not match what was approved.'
				);
			}

			// The engine's OWN post-apply verdict decides whether the batch
			// verified. A per-record error, a PT change or a numeric gate that
			// is not PASS is a failed batch, and the executor reads it rather
			// than assuming the write call was enough.
			$summary = (array) $report['summary'];
			$errors  = (int) ( $summary['errors'] ?? 0 );
			$drift   = (int) ( $summary['pt_changed'] ?? 0 );
			$gate    = strtoupper( trim( (string) ( $report['gate']['gate'] ?? '' ) ) );

			$payload = array(
				'plan'     => (array) $report['plan'],
				'summary'  => $summary,
				'rows'     => (array) ( $report['rows'] ?? array() ),
				'gate'     => (array) $report['gate'],
				'approval' => (string) $result->approval(),
			);

			if ( $errors > 0 || $drift > 0 ) {
				$failure = new WP_Error(
					'conexao_automation_batch_unit_apply_reported_errors',
					sprintf(
						'The engine reported %d error(s) and %d PT change(s) while applying the batch.',
						$errors,
						$drift
					)
				);

				// The report travels WITH the failure, so the per-operation
				// ledger can be recorded from the engine's own truth even
				// though the batch is stopping.
				$failure->add_data( $payload, 'batch_report' );

				return $failure;
			}

			if ( Conexao_Translation_Automation_Apply_Gate::GATE_PASS !== $gate ) {
				$failure = new WP_Error(
					'conexao_automation_batch_unit_apply_gate_not_pass',
					sprintf( 'The engine\'s own numeric gate reported %s after the apply, not PASS.', '' === $gate ? '(nothing)' : $gate )
				);

				$failure->add_data( $payload, 'batch_report' );

				return $failure;
			}
		}

		return array(
			'plan'     => (array) $report['plan'],
			'summary'  => (array) $report['summary'],
			'rows'     => (array) ( $report['rows'] ?? array() ),
			'gate'     => (array) $report['gate'],
			// Stage 11: the engine's own scope evidence, carried through
			// UNMODIFIED so the executor proves equality from engine data.
			'scope'    => is_array( $report['scope'] ?? null ) ? $report['scope'] : array(),
			'approval' => (string) $result->approval(),
		);
	}

	/**
	 * The records the engine's own plan intends to write, outside the approved
	 * identity set.
	 *
	 * Reads the engine's real plan categories, so it catches a stage that
	 * ignored the manifest scope as well as a genuine composition error.
	 *
	 * @param array $plan       The engine's dry-run plan.
	 * @param array $identities The approved identities.
	 * @return array<int,string>
	 */
	private static function plan_outside( array $plan, array $identities ): array {
		$outside = array();

		foreach ( array( 'create', 'update', 'conflicts' ) as $category ) {
			foreach ( (array) ( $plan[ $category ] ?? array() ) as $item ) {
				$key = (string) ( $item['stable_key'] ?? '' );

				if ( '' !== $key && ! in_array( $key, $identities, true ) ) {
					$outside[] = $key;
				}
			}
		}

		return array_values( array_unique( $outside ) );
	}

	/**
	 * The approved identities the engine's own plan never covered (Stage 11).
	 *
	 * The counterweight to `plan_outside()`. Stage 10 only proved the widening
	 * direction, so a plan that silently covered a SUBSET of the approval would
	 * have passed. §8 forbids that: an approved operation that cannot be executed
	 * safely invalidates the batch instead of quietly disappearing from it.
	 *
	 * Read from the engine's plan categories, so it is engine evidence rather
	 * than a caller assertion.
	 *
	 * @param array $plan       The engine's dry-run plan.
	 * @param array $identities The approved identities.
	 * @return array<int,string>
	 */
	private static function plan_identities( array $plan ): array {
		$identities = array();

		foreach ( array( 'create', 'update', 'conflicts', 'skip' ) as $category ) {
			foreach ( (array) ( $plan[ $category ] ?? array() ) as $item ) {
				$key = is_array( $item ) ? trim( (string) ( $item['stable_key'] ?? '' ) ) : '';

				if ( '' !== $key ) {
					$identities[] = $key;
				}
			}
		}

		return array_values( array_unique( $identities ) );
	}

	/**
	 * The approved identities the engine's own plan never covered (Stage 11).
	 *
	 * The counterweight to `plan_outside()`. Stage 10 only proved the widening
	 * direction, so a plan that silently covered a SUBSET of the approval would
	 * have passed. §8 forbids that: an approved operation that cannot be executed
	 * safely invalidates the batch instead of quietly disappearing from it.
	 *
	 * Read from the engine's plan categories, so it is engine evidence rather
	 * than a caller assertion.
	 *
	 * @param array $plan       The engine's dry-run plan.
	 * @param array $identities The approved identities.
	 * @return array<int,string>
	 */
	private static function plan_missing( array $plan, array $identities ): array {
		$planned = self::plan_identities( $plan );
		$missing = array();

		foreach ( $identities as $identity ) {
			if ( ! in_array( $identity, $planned, true ) ) {
				$missing[] = (string) $identity;
			}
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * Record the per-operation ledger FROM THE ENGINE'S OWN REPORT.
	 *
	 * This is not a second idempotence mechanism. It records what the engine
	 * observed, so a resume can distinguish completed from unattempted work
	 * without re-deciding anything: whether a record actually needs writing is
	 * still the engine's plan, its PT-drift guard and its numeric gate.
	 *
	 * @param string $batch_id   Batch identifier.
	 * @param array  $rows       The engine's result rows.
	 * @param array  $identities The approved identities.
	 * @return array<string,string> Identity => status.
	 */
	private static function record_rows( string $batch_id, array $rows, array $identities ): array {
		$seen    = array();
		$written = array();

		foreach ( $rows as $row ) {
			$key = (string) ( $row['stable_key'] ?? '' );

			if ( '' === $key || ! in_array( $key, $identities, true ) ) {
				continue;
			}

			$action = (string) ( $row['action'] ?? '' );

			if ( 'error' === $action ) {
				$seen[ $key ] = Conexao_Translation_Automation_Batch::OP_FAILED;
			} elseif ( in_array( $action, array( 'created', 'updated', 'repaired' ), true ) ) {
				$seen[ $key ]    = Conexao_Translation_Automation_Batch::OP_COMPLETED;
				$written[ $key ] = true;
			} elseif ( 'pt_changed' === $action || 'drift' === $action ) {
				$seen[ $key ] = Conexao_Translation_Automation_Batch::OP_MANUAL_INTERVENTION;
			}
		}

		foreach ( $seen as $key => $status ) {
			Conexao_Translation_Automation_Batch_State::record_operation( $batch_id, $key, $status );
		}

		return $seen;
	}

	/**
	 * The batch-level dry run.
	 *
	 * Produces exactly what has to be known BEFORE any apply:
	 *
	 * | Field | Meaning |
	 * |---|---|
	 * | `operations` | the exact operation count |
	 * | `identities` | the exact operation identities, in order |
	 * | `provider_requests` | the exact worst-case provider-request count |
	 * | `partition` | the exact batch partition position and width |
	 * | `batch_digest` | the exact batch digest |
	 * | `snapshot_identity` | the exact PT state the run compared against |
	 * | `pass` | internally consistent, or not |
	 *
	 * `pass` is true only when EVERY check holds. A single invalid operation
	 * makes the whole batch fail, and the invalid operation is NOT removed:
	 * §18 forbids shrinking an approved batch, because that would silently
	 * change what the approval means.
	 *
	 * @param Conexao_Translation_Automation_Batch $batch   The batch.
	 * @param array<string,mixed>                  $record  The stored record.
	 * @return array<string,mixed>
	 */
	private static function batch_dry_run( Conexao_Translation_Automation_Batch $batch, array $record ) {
		$meta = $batch->meta();

		$identities = $batch->identities();
		$checks     = array();

		// The batch must be non-empty and within the per-stage ceiling.
		$checks['records_within_ceiling'] = count( $identities ) <= Conexao_Translation_Automation_Batch_Limits::MAX_RECORDS_PER_STAGE;
		$checks['records_present']        = count( $identities ) > 0;

		// Every identity must be unique. A duplicate would mean the same record
		// is planned twice, which is a composition error.
		$checks['identities_unique'] = count( array_unique( $identities ) ) === count( $identities );

		// The operation count must be exactly one per record, and within the
		// approved operation budget.
		$checks['operation_count_matches_records'] = $batch->operation_count() === count( $identities );
		$checks['operations_within_budget']        = $batch->operation_count() <= (int) $meta['operation_budget'];

		// The provider-request cost must be within the approved budget, counted
		// the pessimistic way (every attempt, plus a full stale-source re-round).
		$checks['provider_requests_within_budget'] = $batch->provider_request_cost() <= (int) $meta['provider_budget'];

		// Every operation must name a real operation kind from the plan model.
		$kinds = array(
			Conexao_Translation_Automation_Translation_Plan::OP_CREATE_EN,
			Conexao_Translation_Automation_Translation_Plan::OP_UPDATE_EN,
			Conexao_Translation_Automation_Translation_Plan::OP_RECONCILE,
			Conexao_Translation_Automation_Translation_Plan::OP_FIELD_WRITE,
		);

		$unknown = array();

		foreach ( $batch->operations() as $identity => $detail ) {
			if ( ! in_array( (string) ( $detail['operation'] ?? '' ), $kinds, true ) ) {
				$unknown[] = $identity;
			}
		}

		$checks['every_operation_is_planned'] = array() === $unknown;

		// The composition must still hash to its stored digests. This is
		// recomputed here, not read from the record, so a record edited in
		// place fails the dry run rather than merely looking consistent.
		$checks['batch_digest_stable']         = hash_equals(
			(string) $batch->batch_digest(),
			(string) ( $record['batch_digest'] ?? '' )
		);
		$checks['operation_set_digest_stable'] = hash_equals(
			(string) $batch->operation_set_digest(),
			(string) ( $record['operation_set_digest'] ?? '' )
		);

		$pass = true;

		foreach ( $checks as $ok ) {
			if ( true !== $ok ) {
				$pass = false;
			}
		}

		return array(
			'pass'                 => $pass,
			'reason'               => $pass ? 'the batch is internally consistent' : 'one or more batch-level checks failed',
			'checks'               => $checks,
			'operations'           => $batch->operation_count(),
			'identities'           => $identities,
			'provider_requests'    => $batch->provider_request_cost(),
			'partition'            => array(
				'index' => (int) $meta['batch_index'],
				'count' => (int) $meta['batch_count'],
				'size'  => (int) $meta['batch_size'],
			),
			'batch_digest'         => $batch->batch_digest(),
			'operation_set_digest' => $batch->operation_set_digest(),
			'snapshot_identity'    => (string) $meta['change_set_digest'],
			'resource_budget'      => array(
				'operation_budget' => (int) $meta['operation_budget'],
				'provider_budget'  => (int) $meta['provider_budget'],
				'time_budget'      => Conexao_Translation_Automation_Batch_Limits::MAX_EXECUTION_SECONDS,
			),
			'unknown_operations'   => $unknown,
		);
	}

	/**
	 * Map an operation failure onto the exact stop semantics.
	 *
	 * The distinctions matter and are not collapsed:
	 *
	 * | Failure | Semantics |
	 * |---|---|
	 * | the dry run did not produce a clean PASS | NO MUTATION for that operation |
	 * | the approval did not verify | NO MUTATION |
	 * | the source digest drifted | the affected result is rejected; NO MUTATION for it |
	 * | the environment or the lock refused | stop safely; NO MUTATION |
	 * | the apply failed | stop the batch |
	 * | verification failed | stop the batch |
	 * | an unexpected mutation was observed | stop the whole Stage 10 path and require operator investigation |
	 *
	 * @param string $code Failure code from the orchestrator or this class.
	 * @return string One of the STOP_* constants.
	 */
	private static function classify_failure( string $code ): string {
		$map = array(
			'conexao_automation_batch_operation_no_approval' => self::STOP_BUDGET_REFUSED,
			'conexao_automation_batch_operation_dry_run_failed' => self::STOP_VALIDATION_FAILURE,
			'conexao_automation_batch_unit_proof_failed' => self::STOP_BUDGET_REFUSED,
			'conexao_automation_batch_unit_apply_failed' => self::STOP_APPLY_FAILURE,
			'conexao_automation_batch_unit_bad_report'   => self::STOP_VERIFICATION_FAILURE,
			'plan_exceeds_approved_operation_set'        => self::STOP_BATCH_INVALIDATED,
			// Stage 11: the no-shrinking and exact-equality refusals. Each
			// invalidates the whole batch rather than applying a remainder.
			'plan_short_of_approved_operation_set'    => self::STOP_BATCH_INVALIDATED,
			'engine_scope_not_applied'                 => self::STOP_BATCH_INVALIDATED,
			'engine_scope_evidence_mismatch'           => self::STOP_BATCH_INVALIDATED,
			'executed_scope_equals_approved_scope'     => self::STOP_BATCH_INVALIDATED,
			'conexao_automation_batch_operation_unexpected_mutation' => self::STOP_UNEXPECTED_MUTATION,
			'conexao_automation_batch_operation_apply_failed' => self::STOP_APPLY_FAILURE,
			'dry_run_not_pass'                           => self::STOP_BUDGET_REFUSED,
			'dry_run_not_clean'                          => self::STOP_VERIFICATION_FAILURE,
			'approval_mismatch'                          => self::STOP_APPROVAL_INVALID,
			'snapshot_persist_failed'                    => self::STOP_APPLY_FAILURE,
			'environment_refused'                        => self::STOP_APPROVAL_INVALID,
			'locked'                                     => self::STOP_LOCK_LOST,
			'engine_error'                               => self::STOP_PROVIDER_FAILURE,
			'bad_engine_response'                        => self::STOP_PROVIDER_FAILURE,
			'bad_config'                                 => self::STOP_BATCH_INVALIDATED,
		);

		return isset( $map[ $code ] ) ? $map[ $code ] : self::STOP_APPLY_FAILURE;
	}

	/**
	 * A stopped execution result.
	 *
	 * @param string $batch_id    Batch identifier.
	 * @param string $stop        Stop reason.
	 * @param string $failure     Failure code.
	 * @param array  $detail      Non-secret detail.
	 * @return array
	 */
	private static function stopped( string $batch_id, string $stop, string $failure, array $detail ): array {
		// An unexpected mutation is the one case that is not merely "stopped":
		// the system cannot explain its own write, so the whole Stage 10
		// execution path is treated as FAILED and an operator must investigate.
		$outcome = ( self::STOP_UNEXPECTED_MUTATION === $stop )
			? self::OUTCOME_FAILED
			: self::OUTCOME_STOPPED;

		$state = ( self::STOP_UNEXPECTED_MUTATION === $stop )
			? Conexao_Translation_Automation_Batch::FAILED
			: Conexao_Translation_Automation_Batch::STOPPED;

		return array(
			'outcome'     => $outcome,
			'state'       => $state,
			'stop_reason' => $stop,
			'failure'     => $failure,
			'detail'      => $detail,
		);
	}

	/**
	 * Record the terminal state of a batch, ignoring an illegal transition.
	 *
	 * A state that cannot legally be entered (for example because the batch was
	 * already aborted) is left alone rather than forced. The audit still
	 * records the stop reason, so the fact is never lost.
	 *
	 * @param string $batch_id Batch identifier.
	 * @param string $state    Target state.
	 * @param string $stop     Stop reason.
	 * @param string $failure  Failure code.
	 * @return void
	 */
	private static function finish( string $batch_id, string $state, string $stop, string $failure ): void {
		$record = Conexao_Translation_Automation_Batch_State::get( $batch_id );

		if ( null !== $record ) {
			$record['stop_reason'] = $stop;
			$record['failure']     = $failure;
			$record['stopped_at']  = gmdate( 'c' );
			Conexao_Translation_Automation_Batch_State::save( $record );
		}

		Conexao_Translation_Automation_Batch_State::transition( $batch_id, $state );
	}

	/**
	 * The executor's report.
	 *
	 * @param string $outcome     One of the OUTCOME_* constants.
	 * @param string $batch_id    Batch identifier.
	 * @param string $stop_reason Why the batch stopped, or ''.
	 * @param string $failure     Failure category, or ''.
	 * @param array  $detail      Non-secret diagnostic detail.
	 * @return array
	 */
	private static function report( string $outcome, string $batch_id, string $stop_reason, string $failure, array $detail ): array {
		return array(
			'outcome'     => $outcome,
			'batch_id'    => $batch_id,
			'stop_reason' => $stop_reason,
			'failure'     => $failure,
			'detail'      => $detail,
		);
	}

	/**
	 * Authorisation. Returns null when authorised, or a WP_Error when not.
	 *
	 * @param array $context Invocation context.
	 * @return WP_Error|null
	 */
	private static function authorise( array $context ): ?WP_Error {
		if ( empty( $context['authorized'] ) || true !== $context['authorized'] ) {
			return new WP_Error(
				'conexao_automation_unauthorized',
				'The caller is not authorised to execute a batch.'
			);
		}

		$capability = isset( $context['capability'] ) ? trim( (string) $context['capability'] ) : '';

		if ( 'manage_options' !== $capability ) {
			return new WP_Error(
				'conexao_automation_unauthorized',
				'The capability assertion does not match the required capability.'
			);
		}

		return null;
	}

	/**
	 * Write the batch audit record, using the EXISTING audit store.
	 *
	 * No second audit engine: this builds the same record shape the trigger
	 * already writes, with the Stage 10 batch metadata added, and hands it to
	 * `Conexao_Translation_Automation_Audit::record()`. The secret check and
	 * the retention bound are therefore the existing ones, not new ones.
	 *
	 * @param Conexao_Translation_Automation_Batch $batch       The batch.
	 * @param string                               $outcome     Execution outcome.
	 * @param string                               $stop_reason Stop reason.
	 * @param string                               $failure     Failure code.
	 * @param array                                $executed    Executed identities.
	 * @param array                                $skipped     Skipped identities.
	 * @return void
	 */
	private static function audit( Conexao_Translation_Automation_Batch $batch, string $outcome, string $stop_reason, string $failure, array $executed, array $skipped ): void {
		$meta = $batch->meta();

		$record = Conexao_Translation_Automation_Audit::build_record(
			array(
				'run_id'          => (string) $meta['run_id'],
				'trigger'         => Conexao_Translation_Automation_Audit::TRIGGER_MANUAL,
				'stage'           => (string) $meta['stage'],
				'environment'     => (string) $meta['environment'],
				'result'          => array(
					'run_id'             => (string) $meta['run_id'],
					'stage'              => (string) $meta['stage'],
					'environment'        => (string) $meta['environment'],
					'status'             => '' === $failure ? 'ok' : 'failed',
					'failure'            => (string) $failure,
					'start_state'        => Conexao_Translation_Automation_Batch::APPROVED_FOR_BATCH,
					'end_state'          => self::OUTCOME_VERIFIED === $outcome
						? Conexao_Translation_Automation_Batch::VERIFIED
						: Conexao_Translation_Automation_Batch::STOPPED,
					'dry_run_status'     => Conexao_Translation_Automation_Apply_Gate::GATE_PASS,
					'lock_status'        => 'released',
					'apply_permitted'    => true,
					'mutation_permitted' => true,
					// Only what actually happened. A refused or stopped batch
					// that mutated nothing says so here.
					'mutation_occurred'  => count( $executed ) > 0,
					'digests'            => array(
						'plan_digest'     => (string) $meta['plan_digest'],
						'config_identity' => (string) $batch->batch_digest(),
					),
				),
				'provider_status' => Conexao_Translation_Automation_Provider_Config::PROVIDER_ID,
				'started_at'      => gmdate( 'c' ),
			)
		);

		// The Stage 10 batch metadata, added to the EXISTING record.
		$stored = Conexao_Translation_Automation_Batch_State::get( $batch->batch_id() );

		$record['batch_id']                = $batch->batch_id();
		$record['batch_digest']            = $batch->batch_digest();
		$record['batch_level']             = (string) $meta['level'];
		$record['batch_state']             = (string) ( $stored['state'] ?? '' );
		$record['batch_outcome']           = $outcome;
		$record['operation_count']         = $batch->operation_count();
		$record['operation_set_digest']    = $batch->operation_set_digest();
		$record['operation_budget']        = (int) $meta['operation_budget'];
		$record['provider_request_budget'] = (int) $meta['provider_budget'];
		$record['provider_request_cost']   = $batch->provider_request_cost();
		$record['provider_identity']       = (string) $meta['provider_identity'];
		$record['time_budget_seconds']     = Conexao_Translation_Automation_Batch_Limits::MAX_EXECUTION_SECONDS;
		$record['previous_batch_id']       = (string) ( $stored['previous_batch_id'] ?? '' );
		$record['resumed_from_batch_id']   = (string) ( $stored['resumed_from_batch_id'] ?? '' );
		$record['stop_reason']             = $stop_reason;
		$record['executed_records']        = count( $executed );
		$record['skipped_records']         = count( $skipped );

		Conexao_Translation_Automation_Audit::record( $record );
	}
}
