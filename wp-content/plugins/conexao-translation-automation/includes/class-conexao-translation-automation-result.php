<?php
/**
 * The structured result contract for one automation run.
 *
 * This is the run-log boundary, DEFINED but deliberately NOT YET PERSISTED.
 * Stage 1 returns this shape to its caller and stores nothing; a later stage
 * decides where the durable audit trail lives (and its retention) without
 * having to invent a schema again.
 *
 * ## What a run needs to identify itself
 *
 * A run identifier that is unique per invocation and carries no secret, the
 * mode and stage it was asked for, and whether mutation was permitted and
 * whether any mutation actually happened.
 *
 * ## What must NEVER be stored or returned
 *
 * Credentials. Application passwords, nonces, cookies, auth headers and any
 * environment value that could authenticate a caller. `assert_no_secrets()`
 * is the enforcement point, and the boundary test proves it rejects a
 * credential-shaped payload rather than merely documenting the rule.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * One automation run's structured result.
 */
final class Conexao_Translation_Automation_Result {

	/**
	 * Failure categories. Every fail-closed path maps onto exactly one.
	 *
	 * @var string
	 */
	const FAILURE_NONE                  = '';
	const FAILURE_MISSING_MODE          = 'missing_mode';
	const FAILURE_UNKNOWN_MODE          = 'unknown_mode';
	const FAILURE_APPLY_DISABLED        = 'apply_disabled';
	const FAILURE_UNAUTHORIZED          = 'unauthorized';
	const FAILURE_UNKNOWN_STAGE         = 'unknown_stage';
	const FAILURE_STAGE_NOT_ALLOWLISTED = 'stage_not_allowlisted';
	const FAILURE_REGISTRY_MISMATCH     = 'registry_mismatch';
	const FAILURE_MISSING_ENGINE        = 'missing_engine';
	const FAILURE_MISSING_CALLBACK      = 'missing_callback';
	const FAILURE_BAD_CONFIG            = 'bad_config';
	const FAILURE_ENGINE_ERROR          = 'engine_error';
	const FAILURE_BAD_ENGINE_RESPONSE   = 'bad_engine_response';

	// --- Stage 2: the lock and the F7 apply-safety chain. -----------------
	const FAILURE_LOCKED                  = 'locked';
	const FAILURE_ENVIRONMENT             = 'environment_refused';
	const FAILURE_DRY_RUN_NOT_PASS        = 'dry_run_not_pass';
	const FAILURE_APPROVAL_MISMATCH       = 'approval_mismatch';
	const FAILURE_SNAPSHOT_PERSIST_FAILED = 'snapshot_persist_failed';

	/**
	 * Run identifier (unique per invocation, carries no secret).
	 *
	 * @var string
	 */
	private $run_id;

	/**
	 * Invocation mode actually honoured (proof in Stage 1).
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Stage actually attempted.
	 *
	 * @var string
	 */
	private $stage;

	/**
	 * Failure category, or self::FAILURE_NONE on success.
	 *
	 * @var string
	 */
	private $failure;

	/**
	 * Non-secret diagnostic detail about a FAILURE. Never an engine report.
	 *
	 * Kept separate from `$engine_result` on purpose: a failure diagnostic and
	 * an engine report are different things, and conflating them would let a
	 * caller mistake "here is why it failed" for "here is what the engine said".
	 *
	 * @var array|null
	 */
	private $detail;

	/**
	 * Engine report, exactly as the engine returned it. NULL unless the engine
	 * actually ran and returned a report.
	 *
	 * @var array|null
	 */
	private $engine_result;

	/**
	 * Whether the boundary permitted a mutation for this run.
	 *
	 * @var bool
	 */
	private $mutation_permitted;

	/**
	 * Whether a mutation actually occurred. Proof mode can never be true.
	 *
	 * @var bool
	 */
	private $mutation_occurred;

	/**
	 * Environment label the run was evaluated in, or '' when not evaluated.
	 *
	 * @var string
	 */
	private $environment;

	/**
	 * Lock outcome for this run: acquired, reclaimed_stale, locked, ...
	 *
	 * @var string
	 */
	private $lock_status;

	/**
	 * Whether an apply was permitted for this run.
	 *
	 * @var bool
	 */
	private $apply_permitted;

	/**
	 * Plan/manifest/snapshot digests bound to the approval, when there are any.
	 *
	 * @var array
	 */
	private $digests;

	/**
	 * The approval digest a subsequent apply of THIS run would require.
	 *
	 * Empty when the dry-run did not earn one. Published by a proof run so the
	 * approved plan can be handed to the apply step of the same run.
	 *
	 * @var string
	 */
	private $approval = '';

	/**
	 * Build one result.
	 *
	 * @param string     $run_id             Run identifier.
	 * @param string     $mode               Honoured mode.
	 * @param string     $stage              Stage attempted.
	 * @param string     $failure            Failure category, or '' on success.
	 * @param array|null $engine_result      Engine report, verbatim.
	 * @param array|null $detail             Non-secret failure detail.
	 * @param bool       $mutation_permitted Whether mutation was permitted.
	 * @param bool       $mutation_occurred  Whether a mutation occurred.
	 * @param string     $environment        Environment label.
	 * @param string     $lock_status        Lock outcome.
	 * @param bool       $apply_permitted    Whether apply was permitted.
	 * @param array      $digests            Bound digests.
	 */
	private function __construct(
		string $run_id,
		string $mode,
		string $stage,
		string $failure,
		?array $engine_result,
		?array $detail,
		bool $mutation_permitted,
		bool $mutation_occurred,
		string $environment = '',
		string $lock_status = '',
		bool $apply_permitted = false,
		array $digests = array()
	) {
		$this->run_id             = $run_id;
		$this->mode               = $mode;
		$this->stage              = $stage;
		$this->failure            = $failure;
		$this->engine_result      = $engine_result;
		$this->detail             = $detail;
		$this->mutation_permitted = $mutation_permitted;
		$this->mutation_occurred  = $mutation_occurred;
		$this->environment        = $environment;
		$this->lock_status        = $lock_status;
		$this->apply_permitted    = $apply_permitted;
		$this->digests            = $digests;
	}

	/**
	 * A successful proof run: the engine ran and returned its own report.
	 *
	 * @param string $run_id        Run identifier.
	 * @param string $stage         Stage attempted.
	 * @param array  $engine_result Engine report, verbatim.
	 * @param string $environment   Environment the run was evaluated in.
	 * @param string $lock_status   Outcome of the site-wide lock acquisition.
	 * @param string $approval      Approval digest this run's plan requires to be applied.
	 * @return self
	 */
	public static function success( string $run_id, string $stage, array $engine_result, string $environment = '', string $lock_status = '', string $approval = '' ): self {
		$result           = new self( $run_id, 'proof', $stage, self::FAILURE_NONE, $engine_result, null, false, false, $environment, $lock_status );
		$result->approval = $approval;

		return $result;
	}

	/**
	 * A completed apply: every prerequisite was satisfied and the engine wrote.
	 *
	 * This is the ONLY factory that reports a mutation, and it can only be
	 * reached after the lock, the environment guard, the F7 PASS gate, the
	 * approval binding and the snapshot persistence have all succeeded.
	 *
	 * @param string $run_id        Run identifier.
	 * @param string $stage         Stage applied.
	 * @param array  $engine_result The engine's post-apply report, verbatim.
	 * @param array  $verdict       The F7 verdict that authorised the apply.
	 * @return self
	 */
	public static function applied( string $run_id, string $stage, array $engine_result, array $verdict ): self {
		return new self(
			$run_id,
			'applied',
			$stage,
			self::FAILURE_NONE,
			$engine_result,
			null,
			true,
			true,
			isset( $verdict['environment'] ) ? (string) $verdict['environment'] : '',
			'acquired',
			true,
			isset( $verdict['digests'] ) && is_array( $verdict['digests'] ) ? $verdict['digests'] : array()
		);
	}

	/**
	 * A fail-closed run: no engine report, and no mutation.
	 *
	 * @param string     $run_id  Run identifier.
	 * @param string     $mode    Mode requested, or '' when absent.
	 * @param string     $stage   Stage requested, or '' when absent.
	 * @param string     $failure Failure category.
	 * @param array|null $detail  Optional non-secret diagnostic detail.
	 * @return self
	 */
	public static function failure( string $run_id, string $mode, string $stage, string $failure, ?array $detail = null ): self {
		if ( null !== $detail && is_wp_error( self::assert_no_secrets( $detail ) ) ) {
			// A diagnostic that carries a credential is dropped whole: the
			// failure is still reported, but never with the offending data.
			$detail = null;
		}

		return new self( $run_id, $mode, $stage, $failure, null, $detail, false, false );
	}

	/**
	 * The non-secret diagnostic detail attached to a failed run.
	 *
	 * @return array|null
	 */
	public function detail(): ?array {
		return $this->detail;
	}

	/**
	 * Did the run succeed?
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return self::FAILURE_NONE === $this->failure;
	}

	/**
	 * The numeric gate, surfaced from the engine WITHOUT reinterpreting it.
	 *
	 * @return array Empty when there is no engine report.
	 */
	public function gate(): array {
		if ( ! is_array( $this->engine_result ) || ! isset( $this->engine_result['gate'] ) ) {
			return array();
		}

		return (array) $this->engine_result['gate'];
	}

	/**
	 * The engine's report, verbatim.
	 *
	 * @return array|null
	 */
	public function engine_result(): ?array {
		return $this->engine_result;
	}

	/**
	 * The run's failure category.
	 *
	 * @return string
	 */
	public function failure_category(): string {
		return $this->failure;
	}

	/**
	 * Was a mutation permitted for this run?
	 *
	 * @return bool
	 */
	public function mutation_permitted(): bool {
		return $this->mutation_permitted;
	}

	/**
	 * Did a mutation occur?
	 *
	 * @return bool
	 */
	public function mutation_occurred(): bool {
		return $this->mutation_occurred;
	}

	/**
	 * The auditable record. This is what a later stage persists; Stage 1
	 * returns it and stores nothing.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'run_id'             => $this->run_id,
			'plugin'             => 'conexao-translation-automation',
			'plugin_version'     => defined( 'CONEXAO_TRANSLATION_AUTOMATION_VERSION' )
				? CONEXAO_TRANSLATION_AUTOMATION_VERSION
				: '0.0.0',
			'mode'               => $this->mode,
			'stage'              => $this->stage,
			'status'             => $this->ok() ? 'ok' : 'failed',
			'failure'            => $this->failure,
			'start_state'        => 'preflight',
			'end_state'          => $this->ok() ? 'completed' : 'failed-closed',
			'mutation_permitted' => $this->mutation_permitted,
			'mutation_occurred'  => $this->mutation_occurred,
			// --- The audit boundary (Stage 2). -----------------------------
			// Enough to establish, from the record alone: which run, which
			// stage, in which environment, the lock status, the dry-run
			// verdict, the plan/manifest digests, whether apply was
			// permitted, whether anything mutated, and the failure category.
			'environment'        => $this->environment,
			'lock_status'        => $this->lock_status,
			'apply_permitted'    => $this->apply_permitted,
			'approval'           => $this->approval,
			'dry_run_status'     => $this->dry_run_status(),
			'digests'            => $this->digests,
			'gate'               => $this->gate(),
			'engine_result'      => $this->engine_result,
			'detail'             => $this->detail,
		);
	}

	/**
	 * The dry-run status for the audit record.
	 *
	 * Derived from the engine's own gate, never re-interpreted: it is the
	 * literal verdict, so an auditor never has to trust this plugin's reading
	 * of a PASS.
	 *
	 * @return string One of: not_run, PASS, FAIL, unknown.
	 */
	public function dry_run_status(): string {
		$gate = $this->gate();

		if ( array() === $gate ) {
			return 'not_run';
		}

		$verdict = isset( $gate['gate'] ) ? strtoupper( trim( (string) $gate['gate'] ) ) : '';

		return '' === $verdict ? 'unknown' : $verdict;
	}

	/**
	 * The lock outcome for this run.
	 *
	 * @return string
	 */
	public function lock_status(): string {
		return $this->lock_status;
	}

	/**
	 * The environment this run was evaluated in.
	 *
	 * @return string
	 */
	public function environment(): string {
		return $this->environment;
	}

	/**
	 * The run identifier, so a caller can correlate the record it holds with
	 * the run it just started (and confirm a pinned run id was honoured).
	 *
	 * @return string
	 */
	public function run_id(): string {
		return $this->run_id;
	}

	/**
	 * The approval digest this run's plan would require to be applied.
	 *
	 * @return string Empty when the dry-run did not earn an approval.
	 */
	public function approval(): string {
		return $this->approval;
	}

	/**
	 * Was an apply permitted for this run?
	 *
	 * @return bool
	 */
	public function apply_permitted(): bool {
		return $this->apply_permitted;
	}

	/**
	 * Reject a payload that carries anything credential-shaped.
	 *
	 * Fails closed: the caller must discard the payload rather than log it.
	 * A secret in a log or a report is a leak even when the run itself was
	 * harmless, so this is enforced in code and proven by a test.
	 *
	 * @param mixed $payload Candidate payload.
	 * @return array|WP_Error The payload when clean, WP_Error otherwise.
	 */
	public static function assert_no_secrets( $payload ) {
		foreach ( self::flatten( $payload ) as $entry ) {
			$key = strtolower( (string) $entry['key'] );

			foreach ( self::secret_keys() as $needle ) {
				if ( false !== strpos( $key, $needle ) ) {
					return new WP_Error(
						'conexao_automation_secret_present',
						sprintf( 'Refusing to emit a payload carrying the key "%s".', $entry['key'] )
					);
				}
			}

			if ( '' !== (string) $entry['value'] && self::looks_like_a_credential( (string) $entry['value'] ) ) {
				return new WP_Error(
					'conexao_automation_secret_present',
					'Refusing to emit a payload carrying a credential-shaped value.'
				);
			}
		}

		return $payload;
	}

	/**
	 * Key fragments that must never appear in a run record.
	 *
	 * @return array
	 */
	private static function secret_keys(): array {
		return array(
			'password',
			'passwd',
			'secret',
			'token',
			'nonce',
			'cookie',
			'authorization',
			'api_key',
			'apikey',
			'private_key',
			'credential',
		);
	}

	/**
	 * WordPress application passwords are four space-separated groups of four
	 * characters. Matching the shape is enough to refuse it.
	 *
	 * @param string $value Candidate value.
	 * @return bool
	 */
	private static function looks_like_a_credential( string $value ): bool {
		return 1 === preg_match( '/\b[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\b/', $value );
	}

	/**
	 * Flatten a payload to key/value pairs, including array keys, so a secret
	 * cannot hide inside a nested structure.
	 *
	 * @param mixed $payload Payload.
	 * @return array
	 */
	private static function flatten( $payload ): array {
		if ( ! is_array( $payload ) ) {
			return array(
				array(
					'key'   => '',
					'value' => $payload,
				),
			);
		}

		$out = array();

		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( self::flatten( $value ) as $nested ) {
					$out[] = array(
						'key'   => (string) $key . '.' . $nested['key'],
						'value' => $nested['value'],
					);
				}

				continue;
			}

			$out[] = array(
				'key'   => (string) $key,
				'value' => $value,
			);
		}

		return $out;
	}
}
