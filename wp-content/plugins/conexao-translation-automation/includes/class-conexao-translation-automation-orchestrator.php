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
	 * The apply mode. Recognised and structurally reachable, but it can only
	 * ever reach the engine AFTER the lock, the environment guard, the F7
	 * PASS gate, the approval binding and the snapshot persistence have all
	 * succeeded. It is not a shortcut: it is the last step of a chain.
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
	 * ## How a MANIFEST SCOPE is honoured (Stage 10)
	 *
	 * A batch needs the existing chain to run once per bounded operation, not
	 * once per whole stage. So `run()` accepts an OPTIONAL `manifest_scope`: the
	 * portable identities this run may touch.
	 *
	 * It can only ever NARROW. The scope is intersected with the stage's own
	 * manifest, an identity the stage does not declare is a hard refusal, and
	 * an empty intersection is a refusal. There is no path by which a scope
	 * ADDS a record, changes a record's content, or reaches a different stage.
	 *
	 * It is also bound into `config_identity()`, so an approval for scope
	 * `A+B+C` does not authorise scope `A+B+D` and vice versa. That is what
	 * makes the per-operation approval non-substitutable.
	 *
	 * Every other step of the chain is unchanged: lock, environment guard, F7
	 * dry-run, approval verification, snapshot persistence, apply, verify. The
	 * scope decides only HOW MUCH the engine is asked to do.
	 *
	 * @param array $context {
	 *     Invocation context. Nothing is read from a superglobal.
	 *
	 *     @type string $mode       Required. Must be 'proof' or 'apply'.
	 *     @type string $stage      Required. Must be allowlisted.
	 *     @type bool   $authorized Required. The caller's own authorisation
	 *                               decision. When false the run is refused
	 *                               BEFORE the engine is touched.
	 *     @type string $capability Required. Must equal self::CAPABILITY, so a
	 *                               caller cannot assert a wider one.
	 *     @type array  $manifest_scope Optional. Portable identities this run
	 *                               may touch. Narrowing only.
	 * }
	 * @return Conexao_Translation_Automation_Result
	 */
	public static function run( array $context ): Conexao_Translation_Automation_Result {
		// A caller MAY pin the run id, which is what makes a two-step
		// dry-run -> apply flow possible: the approval is bound to the run,
		// so both steps must share one run identity. An unpinned or invalid
		// value falls back to a generated id, and an unpinned run can never
		// be given an approval at all.
		$run_id = self::requested_run_id( $context );
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

		// --- 2. Mode. Fail closed on missing and unknown. -------------------
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

		// --- 3b. Registry consistency assertion ---------------------------
		//
		// The allowlist above is the safety boundary; this assertion is the
		// consistency check. It proves the allowlist still agrees with what the
		// engine actually has registered, so a NEWLY registered stage can never
		// silently become automatable and a removed stage can never linger.
		$consistency = self::assert_registry_consistency();

		if ( null !== $consistency ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_REGISTRY_MISMATCH,
				array( 'reason' => $consistency )
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

		// --- 5b. Optional NARROWING manifest scope (Stage 10). ---------------
		//
		// Applied HERE, after the stage's own configuration is resolved and
		// validated and BEFORE the lock is taken, so an impossible scope costs
		// nothing and starts no work. It can only remove identities; it can
		// never add one, and an identity the stage does not declare is a
		// refusal rather than a filter, so a scope cannot smuggle in a record.
		$scope = self::resolve_scope( $config, $context );

		if ( is_wp_error( $scope ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_BAD_CONFIG,
				array(
					'reason'       => (string) $scope->get_error_message(),
					'scope_failure' => (string) $scope->get_error_code(),
				)
			);
		}

		if ( null !== $scope ) {
			$config = $scope;
		}

		// --- 6. Acquire the site-wide lock BEFORE the engine is touched ----
		//
		// The lock is taken before any engine work so two runs can never even
		// begin a lifecycle concurrently. A refusal here is not an error to
		// work around: the run simply did not happen.
		$lock = Conexao_Translation_Automation_Lock::acquire(
			array(
				'run_id'      => $run_id,
				'stage'       => $stage,
				'environment' => isset( $context['environment'] ) ? (string) $context['environment'] : '',
				'ttl'         => isset( $context['lock_ttl'] ) ? (int) $context['lock_ttl'] : Conexao_Translation_Automation_Lock::DEFAULT_TTL,
			)
		);

		if ( empty( $lock['held'] ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_LOCKED,
				array(
					'lock_outcome' => (string) $lock['outcome'],
					'reason'       => (string) $lock['reason'],
				)
			);
		}

		// From here the lock MUST be released, including on every early return.
		try {
			return self::execute( $config, $run_id, $mode, $stage, $context, $lock );
		} finally {
			Conexao_Translation_Automation_Lock::release( $run_id );
		}
	}

	/**
	 * Run the engine under a held lock, then hand off to the apply branch.
	 *
	 * Split out of run() only so the release is guaranteed by a `finally`
	 * block: every early return in here still releases the lock.
	 *
	 * @param array  $config  Validated stage configuration.
	 * @param string $run_id  Run identifier.
	 * @param string $mode    Requested mode.
	 * @param string $stage   Stage identifier.
	 * @param array  $context Invocation context.
	 * @param array  $lock    The acquired lock.
	 * @return Conexao_Translation_Automation_Result
	 */
	private static function execute( array $config, string $run_id, string $mode, string $stage, array $context, array $lock ): Conexao_Translation_Automation_Result {
		// --- 7. PROOF: force dry_run true; it cannot be overridden ---------
		//
		// The engine's dry-run path runs the real inventory, plan, snapshot
		// and numeric gate while performing zero writes by construction.
		// Nothing here re-implements any of it.
		$dry_run = call_user_func(
			$config['run_callback'],
			array_merge(
				array(
					'dry_run' => true,
					'mode'    => 'run',
				),
				// Stage 11: the approved scope travels to the engine through
				// the argument array, which every real run_callback forwards
				// verbatim. Absent when no scope was requested, so an ordinary
				// run is byte-identical to before.
				self::scope_args( $config )
			)
		);

		if ( is_wp_error( $dry_run ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_ENGINE_ERROR,
				array( 'engine_code' => $dry_run->get_error_code() )
			);
		}

		if ( ! is_array( $dry_run ) || ! isset( $dry_run['summary'], $dry_run['gate'] ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				$mode,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_BAD_ENGINE_RESPONSE,
				array( 'reason' => 'engine returned a response this boundary cannot interpret' )
			);
		}

		// --- 8. PROOF stops here. It never reaches the apply branch. -------
		if ( self::MODE_APPLY !== $mode ) {
			// A proof run publishes the approval digest for THIS run's plan.
			// This is what makes "no apply without a preceding PASS dry-run"
			// usable rather than merely theoretical: the operator runs the dry
			// run, reads the real gate, and hands the very same run the very
			// same approval to apply. Nothing else can produce one.
			$verdict = Conexao_Translation_Automation_Apply_Gate::evaluate(
				$dry_run,
				array(
					'stage'           => $stage,
					'run_id'          => $run_id,
					'environment'     => isset( $context['environment'] ) ? (string) $context['environment'] : '',
					'config_identity' => self::config_identity( $config, self::scope_identities( $context ) ),
				)
			);

			return Conexao_Translation_Automation_Result::success(
				$run_id,
				$stage,
				$dry_run,
				isset( $context['environment'] ) ? (string) $context['environment'] : '',
				(string) $lock['outcome'],
				(string) $verdict['approval']
			);
		}

		return self::apply( $config, $run_id, $stage, $context, $lock, $dry_run );
	}

	/**
	 * The apply branch: F7 dry-run gate, then snapshot persistence, then apply.
	 *
	 * The order below is the whole point of this method and is not
	 * interchangeable:
	 *
	 *     environment guard -> F7 PASS -> approval binding -> snapshot
	 *     persisted -> APPLY
	 *
	 * Every step fails closed. There is no ordering in which a content write
	 * happens before the plan and snapshot identity are known and recorded.
	 *
	 * @param array  $config  Validated stage configuration.
	 * @param string $run_id  Run identifier.
	 * @param string $stage   Stage identifier.
	 * @param array  $context Invocation context.
	 * @param array  $lock    The acquired lock.
	 * @param array  $dry_run The engine's dry-run report for this run.
	 * @return Conexao_Translation_Automation_Result
	 */
	private static function apply( array $config, string $run_id, string $stage, array $context, array $lock, array $dry_run ): Conexao_Translation_Automation_Result {
		// --- A. Environment guard. Before anything that could write. -------
		$env = Conexao_Translation_Automation_Environment::evaluate( $context );

		if ( empty( $env['permitted'] ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				self::MODE_APPLY,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_ENVIRONMENT,
				array( 'reason' => (string) $env['reason'] )
			);
		}

		// --- B. F7: the dry-run must have PASSED, for THIS plan and run. ----
		$binding = array(
			'stage'           => $stage,
			'run_id'          => $run_id,
			'environment'     => (string) $env['environment'],
			'config_identity' => self::config_identity( $config, self::scope_identities( $context ) ),
		);

		$verdict = Conexao_Translation_Automation_Apply_Gate::evaluate( $dry_run, $binding );

		if ( empty( $verdict['permitted'] ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				self::MODE_APPLY,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_DRY_RUN_NOT_PASS,
				array(
					'reason'       => (string) $verdict['reason'],
					'gate_failure' => (string) $verdict['failure'],
				)
			);
		}

		// --- C. The approval must match this exact plan, run and env. -------
		$approval = Conexao_Translation_Automation_Apply_Gate::verify_approval(
			isset( $context['approval'] ) ? $context['approval'] : '',
			$verdict
		);

		if ( empty( $approval['matched'] ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				self::MODE_APPLY,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_APPROVAL_MISMATCH,
				array(
					'reason'       => (string) $approval['reason'],
					'gate_failure' => (string) $approval['failure'],
				)
			);
		}

		// --- D. Persist the plan/snapshot identity BEFORE applying. --------
		$state = Conexao_Translation_Automation_Apply_Gate::persist_state(
			array(
				'run_id'      => $run_id,
				'stage'       => $stage,
				'environment' => (string) $env['environment'],
				'approval'    => (string) $verdict['approval'],
				'digests'     => $verdict['digests'],
				'recorded_at' => time(),
			)
		);

		if ( is_wp_error( $state ) ) {
			// Snapshot persistence failed: NO APPLY. Never "apply and record
			// afterwards", which could no longer prove what the run meant.
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				self::MODE_APPLY,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_SNAPSHOT_PERSIST_FAILED,
				array( 'reason' => 'the plan/snapshot identity could not be persisted; NO APPLY' )
			);
		}

		// --- E. Only now may the engine be asked to write. -----------------
		//
		// Stage 11: the SAME scope that was dry-run is applied here. It is
		// read from the resolved configuration, not from the request, so the
		// apply can never cover a different record set than the proof whose
		// approval this run carries.
		$applied = call_user_func(
			$config['run_callback'],
			array_merge(
				array(
					'dry_run' => false,
					'mode'    => 'run',
				),
				self::scope_args( $config )
			)
		);

		Conexao_Translation_Automation_Apply_Gate::clear_state();

		if ( is_wp_error( $applied ) ) {
			return Conexao_Translation_Automation_Result::failure(
				$run_id,
				self::MODE_APPLY,
				$stage,
				Conexao_Translation_Automation_Result::FAILURE_ENGINE_ERROR,
				array( 'engine_code' => $applied->get_error_code() )
			);
		}

		// The environment travels with the verdict so the audit record states
		// where the mutation actually happened.
		$verdict['environment'] = (string) $env['environment'];

		return Conexao_Translation_Automation_Result::applied( $run_id, $stage, $applied, $verdict );
	}

	/**
	 * The scope identities in force for a run, normalised.
	 *
	 * Returns an empty list when no scope was requested, which is what keeps
	 * the ordinary single-stage approval identity unchanged.
	 *
	 * @param array $context Invocation context.
	 * @return array<int,string>
	 */
	private static function scope_identities( array $context ): array {
		if ( ! isset( $context['manifest_scope'] ) || ! is_array( $context['manifest_scope'] ) ) {
			return array();
		}

		$identities = array();

		foreach ( $context['manifest_scope'] as $identity ) {
			if ( is_string( $identity ) ) {
				$identities[] = trim( $identity );
			}
		}

		return array_values( array_unique( $identities ) );
	}

	/**
	 * Resolve an optional NARROWING manifest scope against the stage's own
	 * manifest.
	 *
	 * Returns `null` when no scope was requested, so the ordinary single-stage
	 * path is byte-for-byte unchanged. Returns a narrowed configuration when a
	 * scope was requested, or a `WP_Error` when the scope is impossible.
	 *
	 * ## Why narrowing, and why the engine is untouched
	 *
	 * The engine's manifest is what it plans from, so bounding a run to a
	 * subset necessarily means handing the engine a smaller manifest. That is
	 * NOT a new lifecycle: the engine still receives an ordinary, valid
	 * manifest callback, still builds its own plan, still takes its own
	 * snapshot, still applies and still verifies. This method only decides
	 * WHICH of the stage's own authored rows are visible.
	 *
	 * ## The four refusals
	 *
	 * | Situation | Why it fails closed |
	 * |---|---|
	 * | an identity the stage does not declare | a scope must not be able to introduce a record |
	 * | the scope is empty | an empty run is a mistake, not a success |
	 * | the scope is larger than the stage's manifest | it could only shrink, so the size is a caller error |
	 * | the scope carries a non-string identity | identity comparison must be exact |
	 *
	 * @param array $config  The stage's validated configuration.
	 * @param array $context Invocation context.
	 * @return array|null|WP_Error The narrowed configuration, null, or a failure.
	 */
	private static function resolve_scope( array $config, array $context ) {
		if ( ! array_key_exists( 'manifest_scope', $context ) ) {
			return null;
		}

		$requested = $context['manifest_scope'];

		if ( ! is_array( $requested ) || array() === $requested ) {
			return new WP_Error(
				'conexao_automation_manifest_scope_empty',
				'A manifest scope must be a non-empty list of portable record identities.'
			);
		}

		$identities = array();

		foreach ( $requested as $identity ) {
			if ( ! is_string( $identity ) || '' === trim( $identity ) ) {
				return new WP_Error(
					'conexao_automation_manifest_scope_identity_invalid',
					'Every manifest-scope entry must be a non-empty string identity.'
				);
			}

			$identities[] = trim( $identity );
		}

		$identities = array_values( array_unique( $identities ) );

		$manifest = call_user_func( $config['manifest_callback'] );
		$records  = is_array( $manifest ) && isset( $manifest['records'] ) && is_array( $manifest['records'] )
			? $manifest['records']
			: array();

		foreach ( $identities as $identity ) {
			if ( ! array_key_exists( $identity, $records ) ) {
				return new WP_Error(
					'conexao_automation_manifest_scope_identity_unknown',
					sprintf(
						'Record identity "%s" is not declared by this stage, so it cannot be scoped to.',
						$identity
					)
				);
			}
		}

		$narrowed = array();

		foreach ( $identities as $identity ) {
			$narrowed[ $identity ] = $records[ $identity ];
		}

		// `manifest_callback` is validated by the engine and always returns an
		// array here, because `$records` was read from it one statement above.
		$scoped_manifest           = $manifest;
		$scoped_manifest['records'] = $narrowed;

		$narrowed_config = $config;

		$narrowed_config['manifest_callback'] = static function () use ( $scoped_manifest ): array {
			return $scoped_manifest;
		};

		// --- Stage 11: carry the scope through the run_callback seam ---------
		//
		// THE FIX. Narrowing `$config['manifest_callback']` above is necessary
		// but NOT sufficient, because every real stage's `run_callback`
		// reconstructs its own configuration from a factory and calls
		// Engine::run() with THAT, discarding whatever this plugin put into the
		// config array. Stage 10's narrowing was therefore dropped at exactly
		// this seam, and the engine always planned the FULL authored manifest.
		//
		// So the scope is now ALSO placed in the argument array that travels
		// through `run_callback` into `Engine::run()`. All four run_callbacks
		// forward `$args` verbatim, so this reaches the engine with no change to
		// any stage, adapter or lifecycle function.
		//
		// This is a deliberate pair. The wrapped manifest keeps the engine's own
		// `validate_config()` contract honest for any caller that reads the
		// config directly, and the `run_scope` key makes the narrowing survive
		// the stage's reconstruction. The engine validates and applies the
		// scope itself; this plugin never narrows the engine's plan.
		$narrowed_config['run_scope'] = $identities;

		return $narrowed_config;
	}

	/**
	 * The run-argument keys that carry an approved scope to the engine.
	 *
	 * Returns an EMPTY array when no scope is in force, which is what keeps the
	 * ordinary single-stage path byte-for-byte unchanged.
	 *
	 * ## Why an engine without scope support is a hard refusal, not a fallback
	 *
	 * If a scope was resolved but the loaded engine exposes no scope capability,
	 * returning an empty array here would mean running the FULL authored manifest
	 * for a batch that was approved for a subset. That is precisely the widening
	 * Model A exists to prevent, so it is refused instead. A caller can never get
	 * a wide run by asking for a narrow one.
	 *
	 * @param array $config The resolved (possibly narrowed) stage configuration.
	 * @return array Argument keys to merge into the run arguments.
	 */
	private static function scope_args( array $config ): array {
		if ( empty( $config['run_scope'] ) || ! is_array( $config['run_scope'] ) ) {
			return array();
		}

		if ( ! method_exists( 'Conexao_Translation_Rollout_Engine', 'narrow_manifest' ) ) {
			// Cannot happen against this repository's engine; proven rather
			// than assumed, because silently widening is the failure mode this
			// whole stage exists to remove.
			throw new RuntimeException(
				'An approved scope was requested but the loaded shared engine supports no scope; refusing rather than running the full stage manifest.'
			);
		}

		return array( 'scope' => array_values( $config['run_scope'] ) );
	}

	/**
	 * The identity of the configuration an approval is bound to.
	 *
	 * Includes the stage's own declared identity, so reconfiguring a stage
	 * invalidates every approval that was granted against the old
	 * configuration. An approval is therefore never transferable across a
	 * configuration change.
	 *
	 * When a manifest scope is in force its digest is included too, so an
	 * approval for one record set never authorises a different one.
	 *
	 * @param array $config Stage configuration.
	 * @param array $scope  Optional portable identities in force.
	 * @return string
	 */
	private static function config_identity( array $config, array $scope = array() ): string {
		return (string) Conexao_Translation_Automation_Apply_Gate::digest(
			array(
				'plugin' => defined( 'CONEXAO_TRANSLATION_AUTOMATION_VERSION' ) ? CONEXAO_TRANSLATION_AUTOMATION_VERSION : '0',
				'engine' => defined( 'CONEXAO_TRANSLATION_ROLLOUT_VERSION' ) ? CONEXAO_TRANSLATION_ROLLOUT_VERSION : '0',
				'stage'  => (string) ( $config['stage'] ?? '' ),
				'source' => (string) ( $config['source_post_type'] ?? '' ),
				'from'   => (string) ( $config['source_lang'] ?? '' ),
				'to'     => (string) ( $config['target_lang'] ?? '' ),
				'remove' => ! empty( $config['allow_remove'] ),
				'scope'  => array_values( $scope ),
			)
		);
	}

	/**
	 * Assert the allowlist and the engine's registry still agree.
	 *
	 * The allowlist is the safety boundary; this is the consistency assertion
	 * on top of it. A disagreement is a NO RUN, because it means the stage
	 * surface changed without the safety review being revisited.
	 *
	 * ## Why only ONE direction is asserted
	 *
	 * The security-relevant direction is "a registered stage that is NOT
	 * allowlisted". That is what would let a newly registered stage become
	 * automatable without anyone reviewing it, so it stops the run.
	 *
	 * The opposite direction — an allowlisted stage the engine does not
	 * currently have — is deliberately NOT treated as registry drift. A stage
	 * that is simply not registered in this environment is already refused
	 * individually with `unknown_stage` when it is requested, and failing the
	 * WHOLE run because an unrelated stage is absent would make the boundary
	 * depend on which other plugins happen to be active. That would be a
	 * false positive that trains operators to ignore a fail-closed gate.
	 *
	 * @return string|null Null when consistent, else a reason string.
	 */
	public static function assert_registry_consistency(): ?string {
		if ( ! class_exists( 'Conexao_Translation_Rollout_Engine' ) ) {
			return null;
		}

		$allowlist  = self::allowed_stages();
		$registered = Conexao_Translation_Rollout_Engine::registered_stages();

		foreach ( $registered as $stage ) {
			if ( in_array( $stage, self::NON_AUTOMATABLE_STAGES, true ) ) {
				continue;
			}

			if ( ! in_array( $stage, $allowlist, true ) ) {
				return sprintf(
					'stage "%s" is registered with the engine but is not on the explicit allowlist',
					$stage
				);
			}
		}

		return null;
	}

	/**
	 * Stages that may legitimately be registered with the shared engine while
	 * remaining deliberately non-automatable.
	 *
	 * These belong to the RETIRED one-shot rollout plugins (Stage 6's `job`
	 * stage and friends), which register with the same engine but must never
	 * be driven by the permanent automation plugin. Naming them explicitly
	 * keeps `assert_registry_consistency()` from flagging them as drift,
	 * without widening the allowlist that actually authorises a run.
	 *
	 * @var array
	 */
	const NON_AUTOMATABLE_STAGES = array(
		'job',
	);

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
	 * APPLY is a valid mode here. It is gated later, by the whole prerequisite
	 * chain, not by this method: refusing it outright would make that chain
	 * untestable, while accepting it here does not weaken anything, because
	 * every later gate fails closed.
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
	 * The run identifier this invocation will use.
	 *
	 * A supplied id is accepted only when it matches the same strict shape the
	 * lock itself accepts, so a caller cannot inject a path, a space or a
	 * credential-shaped string into the audit record or the lock row.
	 *
	 * @param array $context Invocation context.
	 * @return string
	 */
	private static function requested_run_id( array $context ): string {
		$supplied = isset( $context['run_id'] ) ? sanitize_key( (string) $context['run_id'] ) : '';

		if ( '' !== $supplied && preg_match( '/^[a-z0-9_-]{1,64}$/', $supplied ) ) {
			return $supplied;
		}

		return self::run_id();
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
