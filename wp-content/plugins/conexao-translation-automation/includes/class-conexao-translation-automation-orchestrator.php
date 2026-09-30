<?php
/**
 * The permanent automation boundary: a THIN orchestrator over the EXISTING
 * shared translation engine.
 *
 * ## The ownership rule this file exists to honour
 *
 * `Conexao_Translation_Rollout_Engine` (owned by `conexao-translation-rollout`)
 * is the ONLY mutation authority. It owns inventory, manifest validation, the
 * dry-run plan, snapshots, apply, PT-drift detection, verification and the
 * numeric gate. This file owns NONE of that: it does not read a manifest, does
 * not build an adapter, does not interpret a plan and does not recompute a
 * gate. It resolves the engine, hands it the stage's own `run_callback` and
 * returns whatever the engine produced.
 *
 * Concretely, the only lifecycle call here is
 * `call_user_func( $config['run_callback'], $args )` — the stage's own declared
 * entry point, which the engine already validates. There is therefore no
 * second code path that could mutate content, and no duplicated lifecycle.
 *
 * ## Why the stages are allowlisted (Stage 0 control S6)
 *
 * A registered stage is not automatically a safe automation target. This
 * plugin is a permanent, always-loaded production component, so the set of
 * stages it may drive is an EXPLICIT, code-level allowlist rather than
 * "whatever happens to be registered". A stage that is not named here cannot
 * be reached, even if some other plugin registers it.
 *
 * ## Why APPLY is hard-disabled in Stage 1 (control F7)
 *
 * `MODE_APPLY` is a *recognised* mode that always fails closed. There is no
 * default mode, no fallback and no coercion: a missing or unknown mode is a
 * failure, never an apply. The Stage 2+ apply gate is a separate, deliberate
 * implementation — it must add the "no apply without a preceding PASS dry-run"
 * rule and a concurrency lock, neither of which exists yet.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin orchestrator over the shared translation-rollout engine.
 */
final class Conexao_Translation_Automation_Orchestrator {

	/**
	 * Capability a caller must hold. Checked here, not trusted from a request.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * The ONLY mode this implementation honours: a non-mutating proof run.
	 *
	 * @var string
	 */
	const MODE_PROOF = 'proof';

	/**
	 * Recognised so it can be refused EXPLICITLY. Never executable in Stage 1.
	 *
	 * @var string
	 */
	const MODE_APPLY = 'apply';

	/**
	 * The only modes that may appear in a request at all.
	 *
	 * Anything outside this list is an unknown mode and fails closed; it is
	 * never coerced into apply, and never into proof.
	 *
	 * @return array
	 */
	private static function known_modes(): array {
		return array( self::MODE_PROOF, self::MODE_APPLY );
	}

	/**
	 * The explicit stage allowlist (Stage 0 control S6).
	 *
	 * Deliberately narrow: these are the seven EN stages the repository's own
	 * `conexao-en-translation` plugin registers. The list is a hard boundary —
	 * `is_stage_allowed()` is the only way past it.
	 *
	 * @return array
	 */
	private static function allowed_stages(): array {
		return array(
			'en-guide',
			'en-page',
			'en-post',
			'en-blog-page',
			'en-jobs-page',
			'en-leisure-description',
			'en-course-provider-description',
		);
	}

	/**
	 * Run one invocation: preflight, delegate to the engine, return the result.
	 *
	 * Every failure path returns a failed `Conexao_Translation_Automation_
	 * Result` with `mutation_permitted = false`. There is no partial success
	 * and no silent degradation into a mutating mode.
	 *
	 * @param array $context {
	 *     Invocation context. Nothing is read from a superglobal.
	 *
	 *     @type string $mode       Required. Must be 'proof' (Stage 1).
	 *     @type string $stage      Required. Must be allowlisted.
	 *     @type bool   $authorized Required. The caller's own authorisation
	 *                               decision. When false the run is refused
	 *                               BEFORE the engine is touched.
	 *     @type string $capability Required. Must equal self::CAPABILITY, so a
	 *                               caller cannot assert a wider one.
	 * }
	 * @return Conexao_Translation_Automation_Result
	 */
	public static function run( array $context ): Conexao_Translation_Automation_Result {
		$run_id = self::run_id();
		$mode   = isset( $context['mode'] ) && is_string( $context['mode'] ) ? trim( $context['mode'] ) : '';
		$stage  = isset( $context['stage'] ) && is_string( $context['stage'] ) ? trim( $context['stage'] ) : '';

		// --- 1. Authorisation. Checked FIRST, before anything is resolved. ---
		//
		// An unauthorised caller must not be able to learn whether the engine
		// is even loaded, so this precedes the dependency resolution below.
		$denied = self::authorise( $context );

		if ( null !== $denied ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_UNAUTHORIZED,
				array( 'reason' => $denied )
			);
		}

		// --- 2. Mode. Fail closed on missing, unknown and apply. ------------
		$mode_error = self::validate_mode( $mode );

		if ( null !== $mode_error ) {
			return Conexao_Translation_Automation_Result::failure( $run_id, $mode, $stage, $mode_error );
		}

		// --- 3. Stage allowlist (S6) ---------------------------------------
		if ( '' === $stage ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_UNKNOWN_STAGE
			);
		}

		if ( ! self::is_stage_allowed( $stage ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_STAGE_NOT_ALLOWLISTED
			);
		}

		// --- 4. Dependency: the shared engine must be loadable ------------
		if ( ! class_exists( 'Conexao_Translation_Rollout_Engine' ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_MISSING_ENGINE
			);
		}

		// --- 5. Resolve the stage's OWN configuration from the engine ------
		//
		// The engine remains the registry of stage configurations. This plugin
		// neither defines a stage nor supplies a manifest, adapter or callback:
		// it only reads what the stage already registered.
		$config = Conexao_Translation_Rollout_Engine::get_stage( $stage );

		if ( ! is_array( $config ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_UNKNOWN_STAGE,
				array( 'reason' => 'allowlisted stage is not registered with the engine' )
			);
		}

		$valid = Conexao_Translation_Rollout_Engine::validate_config( $config );

		if ( is_wp_error( $valid ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_BAD_CONFIG,
				array( 'engine_code' => $valid->get_error_code() )
			);
		}

		if ( empty( $config['run_callback'] ) || ! is_callable( $config['run_callback'] ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_MISSING_CALLBACK,
				array( 'reason' => 'stage declares no callable run_callback' )
			);
		}

		// --- 6. Delegate. dry_run is FORCED TRUE and cannot be overridden ---
		//
		// This is the whole proof: the engine's own dry-run path runs the real
		// inventory, plan, snapshot and numeric gate while performing zero
		// writes by construction (engine line 813). Nothing here re-implements
		// any of it.
		$report = call_user_func(
			$config['run_callback'],
			array(
				'dry_run' => true,
				'mode'    => 'run',
			)
		);

		// --- 7. An engine error or an unexpected shape both fail closed ----
		if ( is_wp_error( $report ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_ENGINE_ERROR,
				array( 'engine_code' => $report->get_error_code() )
			);
		}

		if ( ! is_array( $report ) || ! isset( $report['summary'], $report['gate'] ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_BAD_ENGINE_RESPONSE,
				array( 'reason' => 'engine returned a response this boundary cannot interpret' )
			);
		}

		return Conexao_Translation_Automation_Result::success( $run_id, $stage, $report );
	}
	/**
	 * Authorisation. Returns null when authorised, or a reason when refused.
	 *
	 * @param array $context Invocation context.
	 * @return string|null
	 */
	private static function authorise( array $context ): ?string {
		if ( empty( $context['authorized'] ) || true !== $context['authorized'] ) {
			return 'caller is not authorised';
		}

		$capability = isset( $context['capability'] ) && is_string( $context['capability'] )
			? trim( $context['capability'] )
			: '';

		if ( self::CAPABILITY !== $capability ) {
			// A caller asserting a wider capability is refused rather than
			// downgraded, so the failure is visible instead of silent.
			return 'capability assertion does not match the required capability';
		}

		return null;
	}

	/**
	 * Validate the mode. Returns null when valid, or a failure category.
	 *
	 * @param string $mode Requested mode.
	 * @return string|null
	 */
	private static function validate_mode( string $mode ): ?string {
		if ( '' === $mode ) {
			// There is NO default. A missing mode is a failure, never an apply.
			return Conexao_Translation_Automation_Result::FAILURE_MISSING_MODE;
		}

		if ( ! in_array( $mode, self::known_modes(), true ) ) {
			// An unknown mode is a failure, never coerced to apply or proof.
			return Conexao_Translation_Automation_Result::FAILURE_UNKNOWN_MODE;
		}

		if ( self::MODE_APPLY === $mode ) {
			// Recognised, deliberately not implemented in Stage 1.
			return Conexao_Translation_Automation_Result::FAILURE_APPLY_DISABLED;
		}

		return null;
	}

	/**
	 * Is this stage on the explicit allowlist? (Stage 0 control S6.)
	 *
	 * @param string $stage Stage identifier.
	 * @return bool
	 */
	public static function is_stage_allowed( string $stage ): bool {
		return in_array( $stage, self::allowed_stages(), true );
	}

	/**
	 * The allowlist, for callers and tests that need to display it.
	 *
	 * @return array
	 */
	public static function allowed_stage_ids(): array {
		return self::allowed_stages();
	}

	/**
	 * A run identifier: unique per invocation, carrying no secret.
	 *
	 * Deliberately not derived from a credential, a post ID or a request
	 * fingerprint — it identifies a RUN, not an actor.
	 *
	 * @return string
	 */
	private static function run_id(): string {
		return 'run_' . gmdate( 'Ymd\THis' ) . '_' . substr( md5( uniqid( 'conexao', true ) ), 0, 12 );
	}
}
