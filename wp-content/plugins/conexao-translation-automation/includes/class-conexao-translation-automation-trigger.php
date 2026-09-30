<?php
/**
 * The trigger, and the reconciliation it invokes.
 *
 * ## Trigger model chosen: EXPLICIT INVOCATION, no cron
 *
 * Stage 2 blocker B2 was "WordPress.com cron behaviour under pv is
 * unverified". Stage 3 investigated it and the answer is: **do not depend on
 * it.** The model is deliberately two layers, exactly as the brief requires.
 *
 * | Layer | What it is | What it decides |
 * |---|---|---|
 * | **Wake-up trigger** | a manual/explicit invocation, plus the hook marker | only *whether to attempt a reconciliation* |
 * | **Reconciliation truth** | the full current-vs-persisted inventory diff | *whether anything actually changed* |
 *
 * Consequences that follow from that split:
 *
 *   - a MISSED wake-up is recovered by the next reconciliation, because the
 *     diff reads the whole current inventory rather than trusting the trigger;
 *   - a DUPLICATE wake-up is harmless, because a second diff of an unchanged
 *     inventory yields an empty change set, and the site-wide lock would refuse
 *     an overlapping run regardless;
 *   - therefore pv-cron reliability is no longer on the critical path, and B2
 *     is answered WITHOUT creating a scheduled job.
 *
 * No `wp_schedule_event`, no `wp_schedule_single_event`, no `cron_schedules`
 * and no cron callback exist in this plugin. That is asserted structurally.
 *
 * ## What the trigger may and may not do
 *
 * It may: validate its caller, validate the stage, acquire the SAME site-wide
 * lock Stage 2 built, and invoke the SAME orchestrator in its non-mutating
 * PROOF mode.
 *
 * It may not: reach apply, pass `mode => apply`, call the provider, write an EN
 * record, or call `Conexao_Translation_Rollout_Engine::run()` with
 * `dry_run => false`. `MODE_PROOF` is hard-coded into the invocation below, so
 * there is no parameter a caller could set to change it.
 *
 * ## Missing persisted state never means "translate everything"
 *
 * | Persisted state | What reconciliation does |
 * |---|---|
 * | `ok` | diff, report, and never apply |
 * | `missing` | **refuse to diff**; report `bootstrap_required`. Nothing is translated, and the baseline is NOT written as a side effect |
 * | corrupt / incompatible / unexpected stage / invalid digest | **hard failure**; no diff, no baseline, no translation |
 *
 * Adopting a first baseline is a separate, explicit `bootstrap` action. It is
 * the only thing that writes state without a preceding diff, and it writes the
 * inventory as the ACCEPTED baseline precisely so the next diff compares
 * against reality rather than assuming everything is already translated.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The wake-up trigger and the reconciliation it invokes.
 */
final class Conexao_Translation_Automation_Trigger {

	/**
	 * Reconciliation outcomes.
	 *
	 * @var string
	 */
	const OUTCOME_RECONCILED         = 'reconciled';
	const OUTCOME_NO_CHANGES         = 'no_changes';
	const OUTCOME_BOOTSTRAP_REQUIRED = 'bootstrap_required';
	const OUTCOME_BOOTSTRAPPED       = 'bootstrapped';
	const OUTCOME_LOCKED             = 'locked';
	const OUTCOME_REFUSED            = 'refused';
	const OUTCOME_MANUAL_REQUIRED    = 'manual_intervention_required';

	/**
	 * Failure categories.
	 *
	 * @var string
	 */
	const FAILURE_UNKNOWN_STAGE   = 'unknown_stage';
	const FAILURE_STATE_UNUSABLE  = 'source_state_unusable';
	const FAILURE_AUTHORIZATION   = 'unauthorized';
	const FAILURE_INVALID_REQUEST = 'invalid_request';

	/**
	 * The only reachable entry point: reconcile one stage, non-mutating.
	 *
	 * @param array $request {
	 *     The wake-up request. Nothing is read from a superglobal.
	 *
	 *     @type string $stage       Stage identifier (required).
	 *     @type string $run_id      Optional pinned run id.
	 *     @type string $environment Declared environment (required).
	 *     @type string $trigger     One of the Audit TRIGGER_* constants.
	 *     @type bool   $authorized  The caller's own authorisation decision.
	 *     @type bool   $bootstrap   Adopt the current inventory as the baseline.
	 * }
	 * @return array The reconciliation report (see `reconcile()`).
	 */
	public static function fire( array $request ): array {
		$started_at  = gmdate( 'c' );
		$stage       = isset( $request['stage'] ) ? trim( (string) $request['stage'] ) : '';
		$environment = isset( $request['environment'] ) ? trim( (string) $request['environment'] ) : '';
		$trigger     = isset( $request['trigger'] ) ? trim( (string) $request['trigger'] ) : Conexao_Translation_Automation_Audit::TRIGGER_MANUAL;
		$bootstrap   = ! empty( $request['bootstrap'] );

		$allowed = Conexao_Translation_Automation_Orchestrator::allowed_stage_ids();

		// --- 1. The stage must be allowlisted, BEFORE anything is resolved.
		if ( '' === $stage || ! in_array( $stage, $allowed, true ) ) {
			return self::refused(
				$stage,
				$environment,
				$trigger,
				$started_at,
				self::FAILURE_UNKNOWN_STAGE,
				'' === $stage
					? 'no stage was named.'
					: sprintf( 'stage "%s" is not on the explicit automation allowlist.', $stage )
			);
		}

		// --- 2. The environment must be a KNOWN label. An unrecognised
		// environment is a refusal, never a downgrade to "local".
		if ( ! in_array( $environment, Conexao_Translation_Automation_Environment::KNOWN, true ) ) {
			return self::refused(
				$stage,
				$environment,
				$trigger,
				$started_at,
				self::FAILURE_INVALID_REQUEST,
				sprintf( 'no recognised environment was declared (got "%s").', $environment )
			);
		}

		// --- 3. The lock. Acquired BEFORE the inventory is read, so two
		// triggers can never even begin comparing at once. A held lock is
		// a refusal, not a queue.
		$run_id = self::run_id( $request );

		$lock = Conexao_Translation_Automation_Lock::acquire(
			array(
				'run_id'      => $run_id,
				'stage'       => $stage,
				'environment' => $environment,
			)
		);

		if ( empty( $lock['held'] ) ) {
			$report = self::report(
				$run_id,
				$stage,
				$environment,
				$trigger,
				$started_at,
				self::OUTCOME_LOCKED,
				'',
				array(),
				'',
				'',
				(string) $lock['outcome'],
				false,
				false
			);

			self::audit( $report );

			return $report;
		}

		try {
			return self::reconcile( $run_id, $stage, $environment, $trigger, $started_at, $bootstrap );
		} finally {
			// Released on EVERY path, including every early return.
			Conexao_Translation_Automation_Lock::release( $run_id );
		}
	}

	/**
	 * Reconcile one stage under the held lock. Non-mutating.
	 *
	 * Order is load-bearing:
	 *
	 *     stage config -> profile cross-check -> persisted state
	 *       -> current inventory -> diff -> (bootstrap: adopt baseline)
	 *       -> orchestrator PROOF -> audit
	 *
	 * @param string $run_id      Run identifier.
	 * @param string $stage       Stage identifier.
	 * @param string $environment Declared environment.
	 * @param string $trigger     Trigger kind.
	 * @param string $started_at  ISO start timestamp.
	 * @param bool   $bootstrap   Adopt the current inventory as the baseline.
	 * @return array
	 */
	private static function reconcile( string $run_id, string $stage, string $environment, string $trigger, string $started_at, bool $bootstrap ): array {
		$allowed = Conexao_Translation_Automation_Orchestrator::allowed_stage_ids();

		// --- Stage configuration, resolved through the ENGINE's registry.
		$config = class_exists( 'Conexao_Translation_Rollout_Engine' )
			? Conexao_Translation_Rollout_Engine::get_stage( $stage )
			: null;

		if ( ! is_array( $config ) ) {
			return self::finish(
				$run_id,
				$stage,
				$environment,
				$trigger,
				$started_at,
				self::refused( $stage, $environment, $trigger, $started_at, self::FAILURE_UNKNOWN_STAGE, 'the allowlisted stage is not registered with the shared engine.' )
			);
		}

		// A malformed stage configuration fails CLOSED, before any state is read
		// or written.
		$valid = Conexao_Translation_Rollout_Engine::validate_config( $config );

		if ( is_wp_error( $valid ) ) {
			return self::finish(
				$run_id,
				$stage,
				$environment,
				$trigger,
				$started_at,
				self::refused( $stage, $environment, $trigger, $started_at, self::FAILURE_UNKNOWN_STAGE, sprintf( 'the stage configuration is invalid: %s', $valid->get_error_message() ) )
			);
		}

		$profile = Conexao_Translation_Automation_Digest::assert_profile_matches_config( $config );

		if ( is_wp_error( $profile ) ) {
			return self::finish(
				$run_id,
				$stage,
				$environment,
				$trigger,
				$started_at,
				self::refused( $stage, $environment, $trigger, $started_at, self::FAILURE_UNKNOWN_STAGE, $profile->get_error_message() )
			);
		}

		// --- The persisted state. ONLY `ok` authorises a diff.
		$state = Conexao_Translation_Automation_Source_State::read( $allowed );

		$unusable = array(
			Conexao_Translation_Automation_Source_State::STATUS_CORRUPT,
			Conexao_Translation_Automation_Source_State::STATUS_INCOMPATIBLE,
			Conexao_Translation_Automation_Source_State::STATUS_UNEXPECTED_STAGE,
			Conexao_Translation_Automation_Source_State::STATUS_INVALID,
		);

		if ( in_array( $state['status'], $unusable, true ) ) {
			// Corruption must never escalate into "translate everything", so this
			// is a hard stop with no diff and no baseline write.
			return self::finish(
				$run_id,
				$stage,
				$environment,
				$trigger,
				$started_at,
				self::refused( $stage, $environment, $trigger, $started_at, self::FAILURE_STATE_UNUSABLE, (string) $state['reason'] )
			);
		}

		$bootstrap_required = Conexao_Translation_Automation_Source_State::STATUS_MISSING === $state['status'];

		// --- The current inventory: the source of truth.
		$inventory = Conexao_Translation_Automation_Inventory::build( $config );

		if ( empty( $inventory['ok'] ) ) {
			return self::finish(
				$run_id,
				$stage,
				$environment,
				$trigger,
				$started_at,
				self::refused( $stage, $environment, $trigger, $started_at, self::FAILURE_STATE_UNUSABLE, (string) $inventory['reason'] )
			);
		}

		$current = array( $stage => $inventory['objects'] );

		if ( $bootstrap_required && ! $bootstrap ) {
			// The critical safety path. A missing baseline means this system has
			// never agreed what "already translated" means, so EVERY record
			// would look new. Translating them all would be mass translation
			// triggered by the ABSENCE of data. So: report, write nothing.
			$report                 = self::report(
				$run_id,
				$stage,
				$environment,
				$trigger,
				$started_at,
				self::OUTCOME_BOOTSTRAP_REQUIRED,
				self::FAILURE_STATE_UNUSABLE,
				array(),
				Conexao_Translation_Automation_Source_State::inventory_digest( $current ),
				'',
				'acquired',
				false,
				false
			);
			$report['records_seen'] = count( $inventory['objects'] );

			self::audit( $report );

			return $report;
		}

		if ( $bootstrap ) {
			return self::adopt_baseline( $run_id, $stage, $environment, $trigger, $started_at, $current, $inventory );
		}

		return self::diff_and_report( $run_id, $stage, $environment, $trigger, $started_at, $current, $inventory, $state );
	}

	/**
	 * Diff, run the orchestrator in PROOF mode, and audit. Never mutates.
	 *
	 * @param string $run_id      Run identifier.
	 * @param string $stage       Stage identifier.
	 * @param string $environment Declared environment.
	 * @param string $trigger     Trigger kind.
	 * @param string $started_at  ISO start timestamp.
	 * @param array  $current     Current inventory map.
	 * @param array  $inventory   Output of `Inventory::build()`.
	 * @param array  $state       Output of `Source_State::read()`.
	 * @return array
	 */
	private static function diff_and_report( string $run_id, string $stage, string $environment, string $trigger, string $started_at, array $current, array $inventory, array $state ): array {
		// --- The diff. This is the ONLY thing that decides what changed.
		$persisted = array();

		if ( isset( $state['stages'][ $stage ] ) && is_array( $state['stages'][ $stage ] ) ) {
			$persisted = array( $stage => $state['stages'][ $stage ] );
		}

		$change_set = Conexao_Translation_Automation_Change_Detector::reconcile( $current, $persisted );

		// --- The orchestrator, in PROOF mode. The mode is HARD-CODED here:
		// there is no request parameter a caller could set to turn this
		// into an apply, and `Orchestrator::run()` itself forces
		// `dry_run => true` on this branch.
		$proof = Conexao_Translation_Automation_Orchestrator::run(
			array(
				'mode'        => Conexao_Translation_Automation_Orchestrator::MODE_PROOF,
				'stage'       => $stage,
				'authorized'  => true,
				'capability'  => Conexao_Translation_Automation_Orchestrator::CAPABILITY,
				'environment' => $environment,
				'run_id'      => $run_id,
			)
		);

		$result = $proof->to_array();

		$manual = Conexao_Translation_Automation_Change_Detector::manual_rows( $change_set );
		$work   = Conexao_Translation_Automation_Change_Detector::actionable_count( $change_set );

		$report = self::report(
			$run_id,
			$stage,
			$environment,
			$trigger,
			$started_at,
			$work > 0 ? self::OUTCOME_RECONCILED : self::OUTCOME_NO_CHANGES,
			(string) ( $result['failure'] ?? '' ),
			$result,
			Conexao_Translation_Automation_Source_State::inventory_digest( $current ),
			(string) $change_set['digest'],
			'acquired',
			false,
			false
		);

		$report['counts']        = (array) $change_set['counts'];
		$report['change_set']    = (array) $change_set['changes'];
		$report['actionable']    = $work;
		$report['manual_rows']   = count( $manual );
		$report['skipped']       = (array) $inventory['skipped'];
		$report['records_seen']  = count( $inventory['objects'] );
		$report['engine_result'] = $result;

		self::audit( $report );

		return $report;
	}

	/**
	 * Adopt the current inventory as the ACCEPTED baseline.
	 *
	 * The only write that does not follow a diff, and it is deliberate: an
	 * operator has decided what "already translated" means. It writes the
	 * baseline so the NEXT reconciliation compares against reality instead of
	 * assuming every record is still new.
	 *
	 * It performs NO translation and NO apply.
	 *
	 * @param string $run_id      Run identifier.
	 * @param string $stage       Stage identifier.
	 * @param string $environment Declared environment.
	 * @param string $trigger     Trigger kind.
	 * @param string $started_at  ISO start timestamp.
	 * @param array  $current     Current inventory map.
	 * @param array  $inventory   Output of `Inventory::build()`.
	 * @return array
	 */
	private static function adopt_baseline( string $run_id, string $stage, string $environment, string $trigger, string $started_at, array $current, array $inventory ): array {
		$existing = Conexao_Translation_Automation_Source_State::read(
			Conexao_Translation_Automation_Orchestrator::allowed_stage_ids()
		);

		// Merge into whatever other stages already hold, so bootstrapping one
		// stage never destroys another stage's accepted baseline.
		$stages = ( Conexao_Translation_Automation_Source_State::STATUS_OK === $existing['status'] )
			? (array) $existing['stages']
			: array();

		$stages[ $stage ] = $inventory['objects'];

		$record = Conexao_Translation_Automation_Source_State::build(
			$stages,
			$run_id,
			'bootstrapped'
		);

		$written = Conexao_Translation_Automation_Source_State::write(
			$record,
			Conexao_Translation_Automation_Orchestrator::allowed_stage_ids()
		);

		$report = self::report(
			$run_id,
			$stage,
			$environment,
			Conexao_Translation_Automation_Audit::TRIGGER_BOOTSTRAP,
			$started_at,
			is_wp_error( $written ) ? self::OUTCOME_REFUSED : self::OUTCOME_BOOTSTRAPPED,
			is_wp_error( $written ) ? self::FAILURE_STATE_UNUSABLE : '',
			array(),
			(string) $record['inventory_digest'],
			'',
			'acquired',
			false,
			false
		);

		$report['records_seen'] = count( $inventory['objects'] );
		$report['detail']       = is_wp_error( $written ) ? $written->get_error_message() : '';

		self::audit( $report );

		return $report;
	}

	/**
	 * Build a reconciliation report.
	 *
	 * `mutation_permitted` and `mutation_occurred` are hard-coded `false` at
	 * every call site, and that is not an oversight: nothing in this class can
	 * reach an apply, so a report claiming otherwise would be a lie. The fields
	 * exist because the audit contract requires them, and their constant value
	 * IS the Stage 3 guarantee.
	 *
	 * @param string $run_id           Run identifier.
	 * @param string $stage            Stage identifier.
	 * @param string $environment      Declared environment.
	 * @param string $trigger          Trigger kind.
	 * @param string $started_at       ISO start timestamp.
	 * @param string $outcome          One of the OUTCOME_* constants.
	 * @param string $failure          Failure category, or ''.
	 * @param array  $result           The orchestrator's result array.
	 * @param string $inventory_digest Current inventory digest.
	 * @param string $change_digest    Change-set digest.
	 * @param string $lock_status      Lock outcome.
	 * @param bool   $mutation_permitted Always false at this stage.
	 * @param bool   $mutation_occurred  Always false at this stage.
	 * @return array
	 */
	private static function report( string $run_id, string $stage, string $environment, string $trigger, string $started_at, string $outcome, string $failure, array $result, string $inventory_digest, string $change_digest, string $lock_status, bool $mutation_permitted, bool $mutation_occurred ): array {
		return array(
			'run_id'                  => $run_id,
			'stage'                   => $stage,
			'environment'             => $environment,
			'trigger'                 => $trigger,
			'outcome'                 => $outcome,
			'failure'                 => $failure,
			'ok'                      => '' === $failure,
			'source_inventory_digest' => $inventory_digest,
			'change_set_digest'       => $change_digest,
			'lock_status'             => $lock_status,
			// There is no provider in this repository, so no run can report one.
			'provider_status'         => 'not_implemented',
			'mutation_permitted'      => $mutation_permitted,
			'mutation_occurred'       => $mutation_occurred,
			'dry_run_status'          => (string) ( $result['dry_run_status'] ?? 'not_run' ),
			// Defaults every report carries, so a caller never has to check
			// which branch produced it before reading these.
			'actionable'              => 0,
			'manual_rows'             => 0,
			'counts'                  => array(),
			'change_set'              => array(),
			'skipped'                 => array(),
			'records_seen'            => 0,
			'digests'                 => isset( $result['digests'] ) && is_array( $result['digests'] ) ? $result['digests'] : array(),
			'gate'                    => isset( $result['gate'] ) && is_array( $result['gate'] ) ? $result['gate'] : array(),
			'started_at'              => $started_at,
			'finished_at'             => gmdate( 'c' ),
		);
	}

	/**
	 * Persist the audit record for a report. Never throws.
	 *
	 * Returns the pruned trail. A persistence refusal is NOT propagated to the
	 * caller: a run must not be turned into a failure because its own audit
	 * write was refused, and the refusal cannot be recorded in the very trail
	 * that refused it. The returned array is therefore always an array; the
	 * `audit_persisted` key tells the caller whether the write succeeded.
	 *
	 * @param array $report A report from `report()`.
	 * @return array The pruned trail, with an `audit_persisted` flag.
	 */
	private static function audit( array $report ): array {
		$record = Conexao_Translation_Automation_Audit::build_record(
			array(
				'run_id'           => (string) ( $report['run_id'] ?? '' ),
				'trigger'          => (string) ( $report['trigger'] ?? '' ),
				'stage'            => (string) ( $report['stage'] ?? '' ),
				'environment'      => (string) ( $report['environment'] ?? '' ),
				'result'           => array_merge(
					array(
						'status'         => '' === (string) ( $report['failure'] ?? '' ) ? 'ok' : 'failed',
						'failure'        => (string) ( $report['failure'] ?? '' ),
						'digests'        => (array) ( $report['digests'] ?? array() ),
						'gate'           => (array) ( $report['gate'] ?? array() ),
						'dry_run_status' => (string) ( $report['dry_run_status'] ?? 'not_run' ),
						'lock_status'    => (string) ( $report['lock_status'] ?? '' ),
					),
					(array) ( $report['engine_result'] ?? array() )
				),
				'inventory_digest' => (string) ( $report['source_inventory_digest'] ?? '' ),
				'change_digest'    => (string) ( $report['change_set_digest'] ?? '' ),
				'provider_status'  => (string) ( $report['provider_status'] ?? 'not_implemented' ),
				'manual_rows'      => (int) ( $report['manual_rows'] ?? 0 ),
				'started_at'       => (string) ( $report['started_at'] ?? '' ),
			)
		);

		$stored = Conexao_Translation_Automation_Audit::record( $record );
		$trail  = array();

		if ( ! is_wp_error( $stored ) ) {
			$trail = (array) $stored;
		}

		return array_merge( $trail, array( 'audit_persisted' => ! is_wp_error( $stored ) ) );
	}

	/**
	 * A refusal report. Not yet audited.
	 *
	 * @param string $stage       Stage identifier.
	 * @param string $environment Declared environment.
	 * @param string $trigger     Trigger kind.
	 * @param string $started_at  ISO start timestamp.
	 * @param string $failure     Failure category.
	 * @param string $reason      Explanation.
	 * @return array
	 */
	private static function refused( string $stage, string $environment, string $trigger, string $started_at, string $failure, string $reason ): array {
		$report = self::report(
			self::run_id( array() ),
			$stage,
			$environment,
			$trigger,
			$started_at,
			self::OUTCOME_REFUSED,
			$failure,
			array(),
			'',
			'',
			'not_acquired',
			false,
			false
		);

		$report['ok']     = false;
		$report['detail'] = $reason;

		return $report;
	}

	/**
	 * Stamp a refusal report with the real run id and audit it.
	 *
	 * @param string $run_id      Run identifier.
	 * @param string $stage       Stage identifier.
	 * @param string $environment Declared environment.
	 * @param string $trigger     Trigger kind.
	 * @param string $started_at  ISO start timestamp.
	 * @param array  $report      A refusal report.
	 * @return array
	 */
	private static function finish( string $run_id, string $stage, string $environment, string $trigger, string $started_at, array $report ): array {
		$report['run_id'] = $run_id;
		$report['stage']  = $stage;

		self::audit( $report );

		return $report;
	}

	/**
	 * The run identifier, honouring a pinned one.
	 *
	 * @param array $request The request array.
	 * @return string
	 */
	private static function run_id( array $request ): string {
		$supplied = isset( $request['run_id'] ) ? sanitize_key( (string) $request['run_id'] ) : '';

		if ( '' !== $supplied && preg_match( '/^[a-z0-9_-]{1,64}$/', $supplied ) ) {
			return $supplied;
		}

		return 'run_' . gmdate( 'Ymd\THis' ) . '_' . substr( md5( uniqid( 'conexao', true ) ), 0, 12 );
	}
}
