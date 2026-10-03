<?php
/**
 * The protected production PROOF entry point (Stage 6).
 *
 * ## Why this file exists
 *
 * Stage 5 ended BLOCKED with four separate problems. Two were environmental
 * (no provider quota, no production credential) and two were structural: the
 * provider mis-normalised the real `wp_remote_post()` shape, and — the one
 * this file fixes — **there was no production entry point at all**. Production
 * is WordPress.com: no SSH, no WP-CLI, no filesystem, no database. Anything
 * operated in production must therefore have an admin screen. That is the
 * repository's established convention for content operations
 * (`conexao-blog-translation`, `conexao-event-importer`, `conexao-admin-ux`),
 * and this file follows it exactly.
 *
 * ## The chain, and the order it runs in
 *
 *     admin request
 *       -> is_post()             1. POST only
 *       -> is_authenticated()    2. authentication
 *       -> has_capability()      3. manage_options
 *       -> verify_nonce()        4. nonce
 *       -> validate_request()    5. mode / stage / field / registry
 *       -> Trigger::fire()       6. the EXISTING orchestrator, PROOF only
 *            -> lock -> inventory -> diff -> provider -> validator
 *            -> plan -> dry-run -> gate -> result -> audit
 *       -> safe_result()         7. secret-scrubbed response
 *
 * Steps 1-5 all complete BEFORE the orchestrator is reached, so an unauthorised
 * or malformed caller can never cause a provider call. A structural test
 * counts orchestrator invocations on the test seam and proves the counter does
 * not move for any authorisation failure.
 *
 * ## What this file deliberately does NOT contain
 *
 * | Absent | Why absent, not merely unused |
 * |---|---|
 * | translation logic | the engine owns every lifecycle step; a copy would be a second engine |
 * | a provider call | the provider is reachable only through the orchestrator's plan stage |
 * | `wp_insert_post` / `update_post_meta` / `pll_*` | the trigger never mutates; a structural gate scans for these |
 * | `MODE_APPLY` | the mode vocabulary has one value, so there is no apply branch to escape through |
 * | a REST route, `admin_post_nopriv_*`, `wp_ajax_*` | no unauthenticated surface exists, so none is closed |
 * | cron | `wp_schedule_*` is asserted absent; this is an explicit, operator-driven action |
 *
 * ## Why APPLY is unavailable, structurally
 *
 * The mode is validated against `allowed_modes()`, which contains exactly one
 * value: `proof`. `apply` is not "disabled by a flag" — it is not in the
 * vocabulary, so it is rejected by the same unknown-mode branch as any other
 * unrecognised string. A `?mode=apply` URL, a `mode=apply` POST field and a
 * `dry_run=false` field are all the same unknown value here. The Stage 2
 * apply-safety code still exists for controlled local testing; there is simply
 * no production trigger capable of activating it.
 *
 * ## Why the environment is derived SERVER-SIDE
 *
 * A caller must never be able to name the environment. It is read from
 * WordPress (`wp_get_environment_type()`), never from the request, and passed
 * to the EXISTING `Environment` guard, which requires it to agree with its own
 * independent reading. A browser-supplied `environment=production` is not
 * merely rejected: `environment` is not in the accepted field allow-list at
 * all, so supplying it is itself a refusal.
 *
 * ## Deployment
 *
 * Installing and activating this plugin in production remains a manual operator
 * action. This file adds capability; it does not grant itself any.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The single protected production automation entry point.
 */
final class Conexao_Translation_Automation_Admin_Trigger {

	/**
	 * The capability required. Matches the orchestrator's own constant.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * The `admin-post.php` action name.
	 *
	 * `admin_post_{ACTION}` fires ONLY for authenticated users; the anonymous
	 * twin would be `admin_post_nopriv_{ACTION}`, which is deliberately absent.
	 *
	 * @var string
	 */
	const ACTION = 'conexao_translation_automation_proof';

	/**
	 * The nonce action. Separate from the request action, so this nonce cannot
	 * be replayed against any other endpoint on the site.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'conexao_translation_automation_proof_nonce';

	/**
	 * The admin page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'conexao-translation-automation';

	/**
	 * The transient carrying the last safe result for the screen to render.
	 *
	 * @var string
	 */
	const RESULT_TRANSIENT = 'conexao_translation_automation_last_result';

	/**
	 * Failure categories. Every one of them is fail-closed.
	 *
	 * @var string
	 */
	const FAILURE_NOT_AUTHENTICATED  = 'not_authenticated';
	const FAILURE_MISSING_CAPABILITY = 'missing_capability';
	const FAILURE_INVALID_NONCE      = 'invalid_nonce';
	const FAILURE_WRONG_METHOD       = 'method_not_allowed';
	const FAILURE_MISSING_MODE       = 'missing_mode';
	const FAILURE_UNKNOWN_MODE       = 'unknown_mode';
	const FAILURE_MISSING_STAGE      = 'missing_stage';
	const FAILURE_UNKNOWN_STAGE      = 'unknown_stage';
	const FAILURE_UNKNOWN_FIELD      = 'unknown_field';
	const FAILURE_REGISTRY_DRIFT     = 'registry_mismatch';
	const FAILURE_RESULT_REFUSED     = 'result_refused';
	/**
	 * Overridable authentication signal, for tests.
	 *
	 * @var callable|null
	 */
	private static $auth_check = null;

	/**
	 * Overridable capability signal, for tests.
	 *
	 * @var callable|null
	 */
	private static $capability_check = null;

	/**
	 * Overridable nonce verifier, for tests.
	 *
	 * @var callable|null
	 */
	private static $nonce_check = null;

	/**
	 * Overridable environment reader, for tests.
	 *
	 * @var callable|null
	 */
	private static $environment_reader = null;

	/**
	 * Overridable orchestrator invoker, for tests.
	 *
	 * This is the seam the suite counts: proving the orchestrator is never
	 * reached on an authorisation failure requires observing invocations.
	 *
	 * @var callable|null
	 */
	private static $orchestrator = null;

	/**
	 * Register the entry point.
	 *
	 * Two hooks, both authenticated-only by construction: `admin_post_{ACTION}`
	 * (a POST-only endpoint under `admin-post.php`) and `admin_menu` (which
	 * renders the form carrying the nonce). Nothing fires on a page view.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_http' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
	}

	// -----------------------------------------------------------------------
	// Test seams
	// -----------------------------------------------------------------------

	/**
	 * Replace the authentication signal.
	 *
	 * @param callable|null $check Callable returning bool.
	 * @return void
	 */
	public static function set_auth_check( $check ): void {
		self::$auth_check = $check;
	}

	/**
	 * Replace the capability signal.
	 *
	 * @param callable|null $check Callable returning bool.
	 * @return void
	 */
	public static function set_capability_check( $check ): void {
		self::$capability_check = $check;
	}

	/**
	 * Replace the nonce verifier.
	 *
	 * @param callable|null $check Callable receiving ( action, field ) and
	 *                              returning bool.
	 * @return void
	 */
	public static function set_nonce_check( $check ): void {
		self::$nonce_check = $check;
	}

	/**
	 * Replace the environment reader.
	 *
	 * @param callable|null $reader Callable returning a string.
	 * @return void
	 */
	public static function set_environment_reader( $reader ): void {
		self::$environment_reader = $reader;
	}

	/**
	 * Replace the orchestrator invoker.
	 *
	 * @param callable|null $invoker Callable receiving the request array and
	 *                               returning the trigger report.
	 * @return void
	 */
	public static function set_orchestrator( $invoker ): void {
		self::$orchestrator = $invoker;
	}

	/**
	 * Restore every seam to the real WordPress behaviour.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$auth_check         = null;
		self::$capability_check   = null;
		self::$nonce_check        = null;
		self::$environment_reader = null;
		self::$orchestrator       = null;
	}
	// -----------------------------------------------------------------------
	// The vocabulary
	// -----------------------------------------------------------------------

	/**
	 * The ONLY mode this entry point accepts.
	 *
	 * A one-value vocabulary, not a deny-list. `apply` is absent, so it is
	 * handled by the same unknown-mode branch as `banana`.
	 *
	 * @return array<int,string>
	 */
	public static function allowed_modes(): array {
		return array( Conexao_Translation_Automation_Orchestrator::MODE_PROOF );
	}

	/**
	 * The stages this entry point may drive.
	 *
	 * Delegates to the orchestrator's allowlist, so there is exactly ONE
	 * definition of an automatable stage in the repository.
	 *
	 * @return array<int,string>
	 */
	public static function allowed_stages(): array {
		return Conexao_Translation_Automation_Orchestrator::allowed_stage_ids();
	}

	/**
	 * The request fields this entry point reads. Anything else is refused.
	 *
	 * An allow-list, so a future field cannot be added to the form without
	 * this constant being revisited. Note what is NOT here: there is no
	 * `environment` field and no `dry_run` field, because the environment is
	 * derived server-side and the mode is fixed.
	 *
	 * @return array<int,string>
	 */
	public static function allowed_fields(): array {
		return array( 'conexao_mode', 'conexao_stage', 'action', '_conexao_nonce' );
	}

	// -----------------------------------------------------------------------
	// The chain
	// -----------------------------------------------------------------------

	/**
	 * The single request handler. Runs the whole chain, in order.
	 *
	 * @param array $request The sanitised request.
	 * @return array The safe result.
	 */
	public static function handle_request( array $request = array() ): array {
		// --- 1. Method. `admin-post.php` is reachable by GET, so POST is
		// enforced here rather than assumed.
		if ( ! self::is_post() ) {
			return self::refuse( self::FAILURE_WRONG_METHOD, 'this entry point accepts POST only.' );
		}

		// --- 2. Authentication. Checked before the capability, because an
		// anonymous caller must not be able to learn anything at all.
		if ( ! self::is_authenticated() ) {
			return self::refuse( self::FAILURE_NOT_AUTHENTICATED, 'the request is not authenticated.' );
		}

		// --- 3. Capability.
		if ( ! self::has_capability() ) {
			return self::refuse( self::FAILURE_MISSING_CAPABILITY, 'the authenticated user lacks ' . self::CAPABILITY . '.' );
		}

		// --- 4. Nonce.
		if ( ! self::verify_nonce() ) {
			return self::refuse( self::FAILURE_INVALID_NONCE, 'the request carried no valid nonce.' );
		}

		// --- 5. Input validation. Still BEFORE the orchestrator: a malformed
		// request must never reach the engine or the provider.
		$invalid = self::validate_request( $request );

		if ( '' !== $invalid ) {
			return $invalid;
		}

		// --- 6. The EXISTING orchestrator, in its non-mutating PROOF mode.
		$report = self::invoke( $request );

		return self::safe_result( $report );
	}
	/**
	 * Validate the request shape. Returns a refusal array, or '' when valid.
	 *
	 * @param array $request The request fields.
	 * @return array|string A refusal result, or an empty string when valid.
	 */
	public static function validate_request( array $request ) {
		// An allow-list, so an unrecognised field is a refusal rather than
		// something quietly ignored and later assumed to be harmless.
		foreach ( array_keys( $request ) as $field ) {
			if ( ! in_array( (string) $field, self::allowed_fields(), true ) ) {
				return self::refuse(
					self::FAILURE_UNKNOWN_FIELD,
					sprintf( 'the request carried an unexpected field ("%s").', sanitize_key( (string) $field ) )
				);
			}
		}

		$mode = isset( $request['conexao_mode'] ) ? trim( (string) $request['conexao_mode'] ) : '';

		if ( '' === $mode ) {
			return self::refuse( self::FAILURE_MISSING_MODE, 'no mode was requested.' );
		}

		if ( ! in_array( $mode, self::allowed_modes(), true ) ) {
			// `apply` lands here. It is not "disabled"; it is not a mode.
			return self::refuse(
				self::FAILURE_UNKNOWN_MODE,
				sprintf( 'mode "%s" is not available from this entry point.', sanitize_key( $mode ) )
			);
		}

		$stage = isset( $request['conexao_stage'] ) ? trim( (string) $request['conexao_stage'] ) : '';

		if ( '' === $stage ) {
			return self::refuse( self::FAILURE_MISSING_STAGE, 'no stage was requested.' );
		}

		if ( ! in_array( $stage, self::allowed_stages(), true ) ) {
			return self::refuse(
				self::FAILURE_UNKNOWN_STAGE,
				sprintf( 'stage "%s" is not on the explicit automation allowlist.', sanitize_key( $stage ) )
			);
		}

		// The registry must still agree with the allowlist, or the stage
		// surface changed without this entry point being reviewed. Fail closed.
		$drift = Conexao_Translation_Automation_Orchestrator::assert_registry_consistency();

		if ( null !== $drift ) {
			return self::refuse( self::FAILURE_REGISTRY_DRIFT, (string) $drift );
		}

		return '';
	}

	/**
	 * Invoke the existing trigger, and only the existing trigger.
	 *
	 * There is no second orchestration path: this calls
	 * `Conexao_Translation_Automation_Trigger::fire()` and returns its report.
	 * The `bootstrap` flag is NEVER set from a request, because adopting a
	 * baseline writes state and must stay a separate, deliberate act.
	 *
	 * @param array $request The validated request.
	 * @return array The trigger report.
	 */
	private static function invoke( array $request ): array {
		$call = array(
			'stage'       => (string) $request['conexao_stage'],
			'trigger'     => Conexao_Translation_Automation_Audit::TRIGGER_ADMIN_PROOF,
			// Derived server-side. A browser value is never read.
			'environment' => self::server_environment(),
			'authorized'  => true,
			// Hard-disabled: this entry point cannot adopt a baseline.
			'bootstrap'   => false,
		);

		if ( is_callable( self::$orchestrator ) ) {
			return (array) call_user_func( self::$orchestrator, $call );
		}

		return Conexao_Translation_Automation_Trigger::fire( $call );
	}

	// -----------------------------------------------------------------------
	// Signals
	// -----------------------------------------------------------------------

	/**
	 * Is the request authenticated?
	 *
	 * @return bool
	 */
	public static function is_authenticated(): bool {
		if ( is_callable( self::$auth_check ) ) {
			return (bool) call_user_func( self::$auth_check );
		}

		return is_user_logged_in();
	}

	/**
	 * Does the current user hold the required capability?
	 *
	 * @return bool
	 */
	public static function has_capability(): bool {
		if ( is_callable( self::$capability_check ) ) {
			return (bool) call_user_func( self::$capability_check );
		}

		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Is the nonce valid?
	 *
	 * @return bool
	 */
	public static function verify_nonce(): bool {
		if ( is_callable( self::$nonce_check ) ) {
			return (bool) call_user_func( self::$nonce_check, self::NONCE_ACTION, '_conexao_nonce' );
		}

		return (bool) wp_verify_nonce( self::posted( '_conexao_nonce' ), self::NONCE_ACTION );
	}

	/**
	 * Is the current request a POST?
	 *
	 * @return bool
	 */
	public static function is_post(): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';

		return 'POST' === $method;
	}

	/**
	 * The environment, read from the SERVER and never from the request.
	 *
	 * @return string
	 */
	public static function server_environment(): string {
		if ( is_callable( self::$environment_reader ) ) {
			return trim( (string) call_user_func( self::$environment_reader ) );
		}

		return trim( (string) wp_get_environment_type() );
	}
	/**
	 * A posted field, unslashed.
	 *
	 * The ONLY place in this file that reads a superglobal, so the "no
	 * untrusted input" rule has exactly one enforcement point.
	 *
	 * @param string $key Field name.
	 * @return string
	 */
	private static function posted( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify the nonce before reaching this; `handle_http()` runs the full chain first.
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
	}

	// -----------------------------------------------------------------------
	// Results
	// -----------------------------------------------------------------------

	/**
	 * Project a trigger report onto the safe, structured result.
	 *
	 * ## What is exposed, and why
	 *
	 * run id, mode, stage, status, the two mutation flags, the gate, the
	 * failure category, bounded counts and the digests. Those are what an
	 * operator needs to decide whether to look further. A digest identifies a
	 * state; it is not a secret.
	 *
	 * ## What is NOT exposed, and why
	 *
	 * No credential, no authorization header, no raw environment, no provider
	 * payload, no `$_POST`, no nonce value, no engine report body.
	 * `assert_no_secrets()` runs on the RESULT BOUNDARY, so a leak is refused
	 * rather than rendered, and the inventory/change-set listings are reduced
	 * to COUNTS because a full listing on a proof screen is a bulk content
	 * dump nobody needs to verify a dry run.
	 *
	 * @param array $report The trigger report.
	 * @return array The safe result.
	 */
	public static function safe_result( array $report ): array {
		$result = array(
			'run_id'                  => (string) ( $report['run_id'] ?? '' ),
			// The mode is CONSTANT in the result, never echoed from the report,
			// so a report can never widen what this entry point claims it did.
			'mode'                    => Conexao_Translation_Automation_Orchestrator::MODE_PROOF,
			'stage'                   => (string) ( $report['stage'] ?? '' ),
			'status'                  => (string) ( $report['outcome'] ?? '' ),
			'ok'                      => ! empty( $report['ok'] ),
			'failure'                 => (string) ( $report['failure'] ?? '' ),
			'environment'             => (string) ( $report['environment'] ?? '' ),
			// The two flags that decide whether anything changed. Hard-coded
			// false: this entry point has no code path that could set them true,
			// and reporting the report's own value would only invite drift.
			'mutation_permitted'      => false,
			'mutation_occurred'       => false,
			'lock_status'             => (string) ( $report['lock_status'] ?? '' ),
			'dry_run_status'          => (string) ( $report['dry_run_status'] ?? 'not_run' ),
			'provider_status'         => (string) ( $report['provider_status'] ?? 'not_run' ),
			'gate'                    => self::safe_gate( $report ),
			'actionable'              => (int) ( $report['actionable'] ?? 0 ),
			'manual_rows'             => (int) ( $report['manual_rows'] ?? 0 ),
			'records_seen'            => (int) ( $report['records_seen'] ?? 0 ),
			'source_inventory_digest' => (string) ( $report['source_inventory_digest'] ?? '' ),
			'change_set_digest'       => (string) ( $report['change_set_digest'] ?? '' ),
			'audit_persisted'         => ! empty( $report['audit_persisted'] ),
			'started_at'              => (string) ( $report['started_at'] ?? '' ),
		);

		$scrubbed = Conexao_Translation_Automation_Result::assert_no_secrets( $result );

		if ( is_wp_error( $scrubbed ) ) {
			// Fail closed on the boundary: a result that cannot be proven
			// secret-free is not returned at all.
			return self::refuse( self::FAILURE_RESULT_REFUSED, 'the run result failed the secret-scrubbing check and was not returned.' );
		}

		return $scrubbed;
	}

	/**
	 * The gate, reduced to its verdict.
	 *
	 * @param array $report The trigger report.
	 * @return array
	 */
	private static function safe_gate( array $report ): array {
		$gate = isset( $report['gate'] ) && is_array( $report['gate'] ) ? $report['gate'] : array();

		return array(
			'status'  => (string) ( $gate['status'] ?? '' ),
			'passed'  => ! empty( $gate['passed'] ),
			'message' => (string) ( $gate['message'] ?? '' ),
		);
	}

	/**
	 * A refusal result. Never audited as a run, never mutates.
	 *
	 * @param string $failure Failure category.
	 * @param string $reason  Explanation.
	 * @return array
	 */
	private static function refuse( string $failure, string $reason ): array {
		return array(
			'run_id'             => '',
			'mode'               => Conexao_Translation_Automation_Orchestrator::MODE_PROOF,
			'stage'              => '',
			'status'             => 'refused',
			'ok'                 => false,
			'failure'            => (string) $failure,
			'reason'             => (string) $reason,
			'environment'        => '',
			'mutation_permitted' => false,
			'mutation_occurred'  => false,
			'lock_status'        => 'not_acquired',
			'dry_run_status'     => 'not_run',
			'provider_status'    => 'not_called',
			'gate'               => array(),
			'actionable'         => 0,
			'manual_rows'        => 0,
			'records_seen'       => 0,
			'audit_persisted'    => false,
		);
	}
	// -----------------------------------------------------------------------
	// WordPress wiring
	// -----------------------------------------------------------------------

	/**
	 * The real HTTP entry point: read POST, run the chain, redirect.
	 *
	 * The result is handed to the screen through a short-lived transient
	 * rather than a query string, so no run data — and certainly no nonce or
	 * credential — ever reaches a URL, a browser history or a referrer header.
	 *
	 * @return void
	 */
	public static function handle_http(): void {
		$request = array(
			'conexao_mode'   => self::posted( 'conexao_mode' ),
			'conexao_stage'  => self::posted( 'conexao_stage' ),
			'action'         => self::posted( 'action' ),
			'_conexao_nonce' => self::posted( '_conexao_nonce' ),
		);

		$result = self::handle_request( $request );

		set_transient( self::RESULT_TRANSIENT, $result, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Register the admin page under Tools.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_management_page(
			'PT→EN Translation Automation',
			'Translation Automation',
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the proof screen.
	 *
	 * Renders the last result, then the ONLY control this entry point exposes:
	 * a proof/dry-run button. There is deliberately no apply button, no apply
	 * radio and no apply field anywhere on this form.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! self::has_capability() ) {
			wp_die(
				esc_html( 'You do not have permission to view this page.' ),
				403
			);
		}

		$result = get_transient( self::RESULT_TRANSIENT );

		if ( is_array( $result ) ) {
			delete_transient( self::RESULT_TRANSIENT );
		}

		echo '<div class="wrap"><h1>' . esc_html( 'PT→EN Translation Automation' ) . '</h1>';

		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html( 'Proof / dry-run only.' )
			. '</strong> '
			. esc_html( 'This screen cannot translate, publish or modify any content. Apply is not available here.' )
			. '</p></div>';

		if ( is_array( $result ) ) {
			echo '<h2>' . esc_html( 'Last result' ) . '</h2>';
			echo '<table class="widefat striped"><tbody>';

			foreach ( $result as $key => $value ) {
				if ( is_array( $value ) ) {
					$value = (string) wp_json_encode( $value );
				} elseif ( is_bool( $value ) ) {
					$value = $value ? 'true' : 'false';
				}

				printf(
					'<tr><th style="width:280px">%s</th><td><code>%s</code></td></tr>',
					esc_html( (string) $key ),
					esc_html( (string) $value )
				);
			}

			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html( 'Run a proof (dry run)' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION, '_conexao_nonce' );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';

		echo '<p><label>' . esc_html( 'Stage' ) . ' ';
		echo '<select name="conexao_stage">';

		foreach ( self::allowed_stages() as $stage ) {
			printf( '<option value="%s">%s</option>', esc_attr( $stage ), esc_html( $stage ) );
		}

		echo '</select></label></p>';
		echo '<input type="hidden" name="conexao_mode" value="' . esc_attr( Conexao_Translation_Automation_Orchestrator::MODE_PROOF ) . '" />';
		echo '<p><button class="button button-primary" type="submit">'
			. esc_html( 'Run proof (dry run, no mutation)' )
			. '</button></p>';
		echo '</form></div>';
	}
}
