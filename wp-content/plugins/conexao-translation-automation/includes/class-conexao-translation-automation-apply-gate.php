<?php
/**
 * The F7 apply gate: "no apply without a preceding PASS dry-run", bound to the
 * exact plan, plus the snapshot-sequencing and apply-state persistence rules.
 *
 * ## What this class is for
 *
 * The shared engine will happily apply a plan. What the engine deliberately
 * does NOT know is whether that plan was ever reviewed, in which environment,
 * under which run, and whether the PT sources still hash to the values the
 * review saw. Those are boundary questions, so they live here, in the thin
 * orchestrator, and the engine stays the sole mutation authority.
 *
 * This class therefore implements NO translation lifecycle. It reads the
 * engine's own dry-run report, decides whether an apply may be *requested*, and
 * never itself creates, updates, links or deletes a record.
 *
 * ## The rule this enforces (F7)
 *
 *     dry-run gate != PASS  ->  NO APPLY
 *
 * FAIL, ERROR, invalid, missing, stale, mismatched and unavailable are ALL
 * "not PASS". There is no partial credit and no "probably fine" path. A PASS is
 * never inferred from the mere existence of a plan, and never inherited from an
 * unrelated earlier run.
 *
 * ## Why the approval is a digest and not a boolean
 *
 * A boolean like `dry_run_passed = true` cannot distinguish plan A from plan B,
 * and therefore cannot stop "review plan A, apply plan B". So the approval is
 * bound to a deterministic digest over everything that defines the work:
 *
 * | Bound field | Why it is in the digest |
 * |---|---|
 * | `manifest_digest` | the authored EN content that would be written |
 * | `plan_digest`     | the exact create/update/skip/conflict decisions |
 * | `snapshot_digest` | the PT state the run compared against |
 * | `stage`           | which post type's records are affected |
 * | `run_id`          | binds the approval to ONE run, never a previous one |
 * | `environment`     | a staging approval is not a production approval |
 * | `config_identity` | plugin + engine + stage configuration versions |
 *
 * Any drift in any of those produces a different digest, and a mismatch fails
 * closed before the engine is asked to write anything.
 *
 * ## Snapshot sequencing
 *
 * The required order is `validated PASS -> persist plan/snapshot identity ->
 * apply`. The identity is persisted FIRST and verified; if that persistence
 * fails there is NO APPLY. Recording a snapshot *after* an apply would be
 * worthless, because it could no longer prove what the run was meant to do.
 *
 * There is deliberately no rollback automation here. Rollback remains the
 * established wp-admin/operator procedure.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The apply authorisation gate.
 */
final class Conexao_Translation_Automation_Apply_Gate {

	/**
	 * Option holding the persisted plan/snapshot identity for a pending apply.
	 *
	 * @var string
	 */
	const STATE_OPTION = 'conexao_translation_automation_apply_state';

	/**
	 * The only accepted dry-run verdict.
	 *
	 * @var string
	 */
	const GATE_PASS = 'PASS';

	/**
	 * Write the persisted apply state. Replaced by tests to inject a
	 * persistence failure without weakening the production write path.
	 *
	 * @var callable|null
	 */
	private static $state_writer = null;

	/**
	 * Test support: replace the state writer.
	 *
	 * Exists so "snapshot persistence fails -> NO APPLY" can be proven
	 * deterministically. Passing null restores the real writer.
	 *
	 * @param callable|null $writer Writer receiving (array $state): bool|WP_Error.
	 * @return void
	 */
	public static function set_state_writer( $writer ): void {
		self::$state_writer = $writer;
	}

	/**
	 * Inspect an engine dry-run report and decide whether an apply may proceed.
	 *
	 * This is a PURE decision: it reads the report, returns a verdict, and
	 * performs no write and no engine call. The orchestrator applies the
	 * verdict; this class never applies anything itself.
	 *
	 * @param array $dry_run The engine's own dry-run report.
	 * @param array $context Binding facts: run_id, stage, environment, config_identity.
	 * @return array {
	 *     @type bool   $permitted True only for a fully valid PASS.
	 *     @type string $failure   Empty when permitted, else the failure category.
	 *     @type string $reason    Human-readable, secret-free explanation.
	 *     @type string $approval  The deterministic approval digest.
	 *     @type array  $digests   The bound digests, for the audit record.
	 * }
	 */
	public static function evaluate( array $dry_run, array $context ): array {
		// --- 1. The report must be a well-formed engine report --------------
		if ( ! isset( $dry_run['gate'], $dry_run['plan'], $dry_run['summary'] ) || ! is_array( $dry_run['gate'] ) ) {
			return self::deny( 'dry_run_invalid', 'the dry-run report is missing or malformed' );
		}

		// --- 2. The verdict. Exactly "PASS", nothing else. -------------------
		$gate = isset( $dry_run['gate']['gate'] ) ? strtoupper( trim( (string) $dry_run['gate']['gate'] ) ) : '';

		if ( self::GATE_PASS !== $gate ) {
			return self::deny(
				'dry_run_not_pass',
				'' === $gate
					? 'the dry-run reported no gate verdict'
					: sprintf( 'the dry-run gate is %s, not PASS', $gate )
			);
		}

		// --- 3. A PASS carrying errors or PT drift is not a safe PASS. ------
		$summary = is_array( $dry_run['summary'] ) ? $dry_run['summary'] : array();
		$errors  = isset( $summary['errors'] ) ? (int) $summary['errors'] : 0;
		$drift   = isset( $summary['pt_changed'] ) ? (int) $summary['pt_changed'] : 0;

		if ( $errors > 0 || $drift > 0 ) {
			return self::deny(
				'dry_run_not_clean',
				sprintf( 'the dry-run reported %d error(s) and %d PT change(s)', $errors, $drift )
			);
		}

		// --- 4. Bind the approval to this exact plan and run. ---------------
		$digests = self::digests( $dry_run, $context );

		if ( null === $digests ) {
			return self::deny( 'dry_run_invalid', 'the dry-run could not be reduced to a stable identity' );
		}

		return array(
			'permitted' => true,
			'failure'   => '',
			'reason'    => 'dry-run PASS and bound to this plan and run',
			'approval'  => self::digest( $digests ),
			'digests'   => $digests,
		);
	}

	/**
	 * Compare a supplied approval against the current dry-run's real identity.
	 *
	 * This is the check that stops "dry-run for plan A, apply plan B". The
	 * caller presents the digest it reviewed; we recompute it from the report
	 * we actually hold and require an exact match.
	 *
	 * @param string $presented Approval digest the caller claims.
	 * @param array  $verdict   Result of self::evaluate().
	 * @return array {
	 *     @type bool   $matched True only on an exact match.
	 *     @type string $failure Empty when matched, else the failure category.
	 *     @type string $reason  Explanation.
	 * }
	 */
	public static function verify_approval( $presented, array $verdict ): array {
		if ( empty( $verdict['permitted'] ) ) {
			return array(
				'matched' => false,
				'failure' => (string) $verdict['failure'],
				'reason'  => (string) $verdict['reason'],
			);
		}

		$presented = strtolower( trim( strval( $presented ) ) );

		if ( '' === $presented ) {
			return array(
				'matched' => false,
				'failure' => 'approval_missing',
				'reason'  => 'no apply approval was supplied for this plan',
			);
		}

		// hash_equals: constant-time, so the comparison leaks nothing by timing.
		if ( ! hash_equals( (string) $verdict['approval'], $presented ) ) {
			return array(
				'matched' => false,
				'failure' => 'approval_mismatch',
				'reason'  => 'the supplied approval does not match this plan, run and environment',
			);
		}

		return array(
			'matched' => true,
			'failure' => '',
			'reason'  => 'the approval matches this plan, run and environment',
		);
	}

	/**
	 * Persist the plan/snapshot identity that authorises a later apply.
	 *
	 * MUST be called after a validated PASS and BEFORE the apply branch is
	 * entered. Any failure means NO APPLY.
	 *
	 * @param array $state Identity record to persist.
	 * @return true|WP_Error True on success.
	 */
	public static function persist_state( array $state ) {
		$clean = Conexao_Translation_Automation_Result::assert_no_secrets( $state );

		if ( is_wp_error( $clean ) ) {
			return new WP_Error(
				'conexao_automation_apply_state_secret',
				'Refusing to persist an apply state carrying a credential-shaped value.'
			);
		}

		if ( is_callable( self::$state_writer ) ) {
			return call_user_func( self::$state_writer, $state );
		}

		// A single option row, written before any content write. This is
		// metadata about an intended run, not content, and it is what makes
		// "we persisted the identity first" a checkable fact.
		$ok = update_option( self::STATE_OPTION, $state, false );

		if ( ! $ok ) {
			return new WP_Error(
				'conexao_automation_apply_state_persist_failed',
				'The plan/snapshot identity could not be persisted; NO APPLY.'
			);
		}

		return true;
	}

	/**
	 * Read the persisted apply state.
	 *
	 * @return array|null
	 */
	public static function read_state() {
		$state = get_option( self::STATE_OPTION, null );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Remove the persisted apply state. Called once an apply has completed.
	 *
	 * @return void
	 */
	public static function clear_state(): void {
		delete_option( self::STATE_OPTION );
	}

	/**
	 * The deterministic digest of everything that defines this run's work.
	 *
	 * @param array $dry_run Engine dry-run report.
	 * @param array $context Binding facts.
	 * @return array|null Bound digests, or null when a required fact is absent.
	 */
	private static function digests( array $dry_run, array $context ) {
		$stage       = isset( $context['stage'] ) ? (string) $context['stage'] : '';
		$run_id      = isset( $context['run_id'] ) ? (string) $context['run_id'] : '';
		$environment = isset( $context['environment'] ) ? (string) $context['environment'] : '';
		$config      = isset( $context['config_identity'] ) ? (string) $context['config_identity'] : '';

		// A missing binding fact is never defaulted. An unbound approval would
		// be an approval of nothing in particular.
		if ( '' === $stage || '' === $run_id || '' === $environment || '' === $config ) {
			return null;
		}

		return array(
			'manifest_digest' => self::digest( $dry_run['manifest'] ?? array() ),
			'plan_digest'     => self::digest( $dry_run['plan'] ?? array() ),
			'snapshot_digest' => self::digest( $dry_run['snapshot'] ?? array() ),
			'stage'           => $stage,
			'run_id'          => $run_id,
			'environment'     => $environment,
			'config_identity' => $config,
		);
	}

	/**
	 * A stable digest of an arbitrary structure.
	 *
	 * Deterministic across processes and machines: array keys are sorted
	 * recursively, so the same plan always yields the same digest and a changed
	 * plan never does.
	 *
	 * @param mixed $value Structure to digest.
	 * @return string
	 */
	public static function digest( $value ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonicalise( $value ) ) );
	}

	/**
	 * Recursively sort map keys so digests are key-order independent.
	 *
	 * Lists keep their order, because in a plan the ORDER of create/update
	 * rows is meaningful and must not be normalised away.
	 *
	 * @param mixed $value Structure to canonicalise.
	 * @return mixed
	 */
	private static function canonicalise( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$out = array();

		foreach ( $value as $key => $item ) {
			$out[ $key ] = self::canonicalise( $item );
		}

		if ( ! self::is_list( $out ) ) {
			ksort( $out );
		}

		return $out;
	}

	/**
	 * Is this array a zero-indexed list?
	 *
	 * @param array $value Candidate.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * A denial verdict.
	 *
	 * @param string $failure Failure category.
	 * @param string $reason  Explanation.
	 * @return array
	 */
	private static function deny( string $failure, string $reason ): array {
		return array(
			'permitted' => false,
			'failure'   => $failure,
			'reason'    => $reason,
			'approval'  => '',
			'digests'   => array(),
		);
	}
}
