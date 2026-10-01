<?php
/**
 * The bounded batch control plane (Stage 11 — DECLARED, NOT COMMISSIONED).
 *
 * ## Status: specified and locally proven, deliberately NOT reachable
 *
 * This class is the exact production capability Stage 11 §17 requires be
 * defined. It is complete: action name, owning plugin, HTTP method,
 * authentication, capability, nonce, accepted state transitions, approved batch
 * identity and response contract are all decided here and asserted by tests.
 *
 * `register()` is NOT called from the plugin bootstrap (Stage 11 §30), so this
 * plugin adds no batch endpoint and no batch screen. Nothing in production can
 * reach this class through HTTP today. That is the intended end state of Stage
 * 11, not an omission.
 *
 * ## Why explicit actions, and never a `mode` selector
 *
 * There are five actions and each is a SEPARATE, independently authorised
 * human decision:
 *
 * | Action | Authorises | Never authorises |
 * |---|---|---|
 * | `prepare` | composing + reviewing one batch | executing anything |
 * | `approve` | the reviewed batch's digest | composing; executing |
 * | `execute` | running the APPROVED batch | approving it; widening it |
 * | `abort` | requesting a stop at a safe boundary | aborting mid-write |
 * | `clear_emergency_stop` | resuming new work at all | clearing for one record |
 *
 * A generic `mode=apply` was rejected for the same reason `approve_current_queue`
 * and `approve_batch_type` were: a single selector makes an approval survive a
 * change in what it approves. Review and approval stay two separate acts.
 *
 * ## What the caller is NEVER authoritative for (§19)
 *
 * | Caller may supply | Server resolves |
 * |---|---|
 * | the action | the batch, its identities, its digests |
 * | a batch id (server validates the shape) | the environment |
 * | nothing else | size, operation list, plan/approval digests |
 *
 * There is deliberately no request field for batch size, operation list, plan
 * digest, approval digest or environment. Supplying one is not ignored — the
 * accepted-field allow-list makes it a REFUSAL, so a caller cannot believe it
 * constrained anything.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The declared batch control plane.
 */
final class Conexao_Translation_Automation_Batch_Control {

	/**
	 * The exact admin-post action name.
	 *
	 * Declared in the single control-plane registry
	 * (`tests/scripts/verify-stage8-control-plane.py`) so the surface stays one
	 * registry rather than a second inventory.
	 *
	 * @var string
	 */
	const ACTION = 'conexao_translation_automation_batch';

	/**
	 * The nonce action. Distinct from the proof endpoint's, so a nonce minted
	 * for a dry run can never authorise a mutation.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'conexao_translation_automation_batch';

	/**
	 * The capability required for every action.
	 *
	 * @var string
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * The accepted actions. Anything else is refused, never coerced.
	 *
	 * @var string
	 */
	const ACTION_PREPARE    = 'prepare';
	const ACTION_APPROVE    = 'approve';
	const ACTION_EXECUTE    = 'execute';
	const ACTION_ABORT      = 'abort';
	const ACTION_CLEAR_STOP = 'clear_emergency_stop';

	/**
	 * The ONLY request fields this endpoint accepts.
	 *
	 * Anything else — `operations`, `batch_size`, `environment`, `plan_digest`,
	 * `approval_digest`, `mode` — is refused outright. This is the mechanism,
	 * not a convention: the caller has no field through which to be
	 * authoritative for any of them.
	 *
	 * @var array<int,string>
	 */
	const ACCEPTED_FIELDS = array( 'conexao_batch_action', 'conexao_batch_id' );

	/**
	 * Failure categories.
	 *
	 * @var string
	 */
	const FAILURE_METHOD         = 'batch_control_method_not_allowed';
	const FAILURE_NOT_AUTH       = 'batch_control_not_authenticated';
	const FAILURE_CAPABILITY     = 'batch_control_missing_capability';
	const FAILURE_NONCE          = 'batch_control_invalid_nonce';
	const FAILURE_MISSING_ACTION = 'batch_control_missing_action';
	const FAILURE_UNKNOWN_ACTION = 'batch_control_unknown_action';
	const FAILURE_UNKNOWN_FIELD  = 'batch_control_unknown_field';
	const FAILURE_BAD_BATCH_ID   = 'batch_control_bad_batch_id';
	const FAILURE_UNKNOWN_BATCH  = 'batch_control_unknown_batch';
	const FAILURE_NOT_APPROVED   = 'batch_control_not_approved';

	/**
	 * Overridable seams, so the security contract is testable without HTTP.
	 *
	 * @var array<string,callable|null>
	 */
	private static $seams = array();

	/**
	 * Every action this endpoint accepts.
	 *
	 * @return array<int,string>
	 */
	public static function actions(): array {
		return array(
			self::ACTION_PREPARE,
			self::ACTION_APPROVE,
			self::ACTION_EXECUTE,
			self::ACTION_ABORT,
			self::ACTION_CLEAR_STOP,
		);
	}

	/**
	 * Replace a security seam. Test support only.
	 *
	 * @param string $name    Seam name.
	 * @param mixed  $handler Callable, or null to restore the default.
	 * @return void
	 */
	public static function set_seam( string $name, $handler ): void {
		self::$seams[ $name ] = $handler;
	}

	/**
	 * Restore every seam to its default. Test support only.
	 *
	 * @return void
	 */
	public static function reset_seams(): void {
		self::$seams = array();
	}

	/**
	 * Read one seam, falling back to the named default.
	 *
	 * @param string   $name    Seam name.
	 * @param callable $default The real implementation.
	 * @return mixed
	 */
	private static function seam( string $name, callable $default ) {
		return isset( self::$seams[ $name ] ) && is_callable( self::$seams[ $name ] )
			? call_user_func( self::$seams[ $name ] )
			: call_user_func( $default );
	}

	/**
	 * Register the endpoint and its screen.
	 *
	 * NOT CALLED in Stage 11 (§30). Present so the commissioning stage is a
	 * one-line, reviewable change rather than new code.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_http' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
	}

	/**
	 * Is the batch-control surface commissioned?
	 *
	 * @return bool Always false in Stage 11.
	 */
	public static function is_commissioned(): bool {
		return false;
	}

	/**
	 * The action-state contract, as data.
	 *
	 * Kept here (and asserted by tests) so the answer to "what may this action
	 * authorise?" is one table rather than prose spread across methods.
	 *
	 * @return array<string,string>
	 */
	public static function authorises(): array {
		return array(
			self::ACTION_PREPARE    => 'compose_and_review_only',
			self::ACTION_APPROVE    => 'the_reviewed_batch_digest_only',
			self::ACTION_EXECUTE    => 'the_approved_batch_only',
			self::ACTION_ABORT      => 'stop_at_the_next_safe_boundary',
			self::ACTION_CLEAR_STOP => 'new_work_may_start_again',
		);
	}

	/**
	 * Read one request field. Never returns anything else.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	private static function request_field( string $field ): string {
		return isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
	}

	/**
	 * Validate a batch-control request. Performs ZERO writes.
	 *
	 * The whole security contract lives here and is called before any handler,
	 * so the ordering guarantee is structural rather than a convention someone
	 * has to remember when adding a sixth action.
	 *
	 * @param array $request Sanitised request fields.
	 * @return array|WP_Error { action, batch_id } or the refusal.
	 */
	public static function validate_request( array $request ) {
		// 1. POST only. `admin-post.php` is GET-reachable, so this is enforced
		//    explicitly rather than assumed.
		$is_post = self::seam(
			'is_post',
			static function (): bool {
				return isset( $_SERVER['REQUEST_METHOD'] )
					&& 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] );
			}
		);

		if ( true !== $is_post ) {
			return new WP_Error( self::FAILURE_METHOD, 'Batch control accepts POST only.' );
		}

		// 2. Authentication. `admin_post_` is not reachable by an anonymous
		//    caller, and this is asserted rather than assumed.
		$authed = self::seam(
			'is_authenticated',
			static function (): bool {
				return function_exists( 'is_user_logged_in' ) && is_user_logged_in();
			}
		);

		if ( true !== $authed ) {
			return new WP_Error( self::FAILURE_NOT_AUTH, 'Batch control requires an authenticated user.' );
		}

		// 3. Capability. A WIDER claim is refused, not downgraded, so the
		//    mismatch stays visible instead of silently succeeding.
		$capability = self::seam(
			'capability',
			static function (): string {
				return current_user_can( self::CAPABILITY ) ? self::CAPABILITY : '';
			}
		);

		if ( self::CAPABILITY !== (string) $capability ) {
			return new WP_Error( self::FAILURE_CAPABILITY, 'Batch control requires manage_options.' );
		}

		// 4. Nonce, on this endpoint's OWN nonce action.
		$nonce_ok = self::seam(
			'nonce',
			static function (): bool {
				return (bool) wp_verify_nonce( self::request_field( 'conexao_batch_action' ), self::NONCE_ACTION );
			}
		);

		if ( true !== $nonce_ok ) {
			return new WP_Error( self::FAILURE_NONCE, 'A valid batch-control nonce is required.' );
		}

		// 5. Field allow-list. This is what stops a caller supplying an
		//    operation list, a batch size, an environment or any digest.
		foreach ( array_keys( $request ) as $field ) {
			if ( ! in_array( (string) $field, self::ACCEPTED_FIELDS, true ) ) {
				return new WP_Error(
					self::FAILURE_UNKNOWN_FIELD,
					sprintf( 'The batch-control endpoint does not accept the field "%s".', (string) $field )
				);
			}
		}

		// 6. An explicit, known action. There is no default and no coercion.
		$action = isset( $request['conexao_batch_action'] )
			? strtolower( trim( (string) $request['conexao_batch_action'] ) )
			: '';

		if ( '' === $action ) {
			return new WP_Error( self::FAILURE_MISSING_ACTION, 'A batch-control action is required.' );
		}

		if ( ! in_array( $action, self::actions(), true ) ) {
			return new WP_Error(
				self::FAILURE_UNKNOWN_ACTION,
				sprintf( 'The batch-control action "%s" is not one this endpoint performs.', $action )
			);
		}

		// 7. A batch id, shape-validated only. Its identities, size, digests
		//    and environment are resolved FROM STORAGE by the handler.
		$batch_id = isset( $request['conexao_batch_id'] )
			? strtolower( trim( (string) $request['conexao_batch_id'] ) )
			: '';

		if ( '' !== $batch_id && ! preg_match( '/^batch_[a-z0-9]{16}$/', $batch_id ) ) {
			return new WP_Error(
				self::FAILURE_BAD_BATCH_ID,
				'The batch identifier is malformed; resolve the batch by its recorded identifier.'
			);
		}

		return array(
			'action'   => $action,
			'batch_id' => $batch_id,
		);
	}

	/**
	 * Perform one validated batch-control transition. Zero writes in tests.
	 *
	 * The handler is a seam so the security and state contract is proven
	 * without a live HTTP request; production wiring supplies the real one.
	 *
	 * @param array $validated The validated { action, batch_id } pair.
	 * @return array|WP_Error
	 */
	public static function dispatch( array $validated ) {
		$action   = (string) ( $validated['action'] ?? '' );
		$batch_id = (string) ( $validated['batch_id'] ?? '' );

		if ( ! in_array( $action, self::actions(), true ) ) {
			return new WP_Error( self::FAILURE_UNKNOWN_ACTION, 'Unknown batch-control action.' );
		}

		// `clear_emergency_stop` is deliberately independent of any batch: it
		// authorises NEW WORK to start, which is a global decision and must not
		// be reachable by naming a batch.
		if ( self::ACTION_CLEAR_STOP === $action ) {
			$stop_handler = self::seam(
				'clear_stop',
				static function () {
					return new WP_Error(
						self::FAILURE_NOT_APPROVED,
						'Clearing the emergency stop requires the commissioned handler.'
					);
				}
			);

			return is_callable( $stop_handler )
				? $stop_handler()
				: new WP_Error( self::FAILURE_NOT_APPROVED, 'No handler.' );
		}

		// Every other action resolves the batch FROM STORAGE. The caller names
		// it; the server decides what it contains.
		$resolver = self::seam(
			'resolve_batch',
			static function ( string $id ) {
				return '' === $id ? null : Conexao_Translation_Automation_Batch_State::get( $id );
			}
		);

		$stored = is_callable( $resolver ) ? call_user_func( $resolver, $batch_id ) : null;

		if ( ! is_array( $stored ) ) {
			return new WP_Error(
				self::FAILURE_UNKNOWN_BATCH,
				'No such batch is stored; the server resolves the batch, the caller does not describe it.'
			);
		}

		// Re-verify the stored approval from its PERSISTED CONTENTS before any
		// action that could lead to execution. A caller that edited the stored
		// identities in place leaves the stored digests untouched, so the
		// recomputation inside verify() is what actually catches it.
		if ( self::ACTION_EXECUTE === $action ) {
			$verified = Conexao_Translation_Automation_Batch_Approval::verify( $stored );

			if ( is_wp_error( $verified ) ) {
				return new WP_Error( self::FAILURE_NOT_APPROVED, (string) $verified->get_error_message() );
			}
		}

		$handler = self::seam( 'action_' . $action, null );

		if ( ! is_callable( $handler ) ) {
			return new WP_Error(
				self::FAILURE_NOT_APPROVED,
				sprintf( 'The "%s" transition has no commissioned handler.', $action )
			);
		}

		return call_user_func( $handler, $stored );
	}

	/**
	 * The HTTP entry point. Registered only when commissioned.
	 *
	 * @return void
	 */
	public static function handle_http(): void {
		$request = array(
			'conexao_batch_action' => self::request_field( 'conexao_batch_action' ),
			'conexao_batch_id'     => self::request_field( 'conexao_batch_id' ),
		);

		$validated = self::validate_request( $request );

		if ( is_wp_error( $validated ) ) {
			wp_die( esc_html( (string) $validated->get_error_message() ), 403 );
		}

		$outcome = self::dispatch( $validated );

		if ( is_wp_error( $outcome ) ) {
			wp_die( esc_html( (string) $outcome->get_error_message() ), 403 );
		}

		// The response contract is deliberately minimal and non-secret: a
		// status, the action, what it authorised, and the digests the operator
		// must be able to see to verify the decision.
		wp_send_json_success(
			array(
				'status'                => 'ok',
				'action'                => (string) $validated['action'],
				'batch_id'              => (string) $validated['batch_id'],
				'authorises'            => self::authorises()[ (string) $validated['action'] ],
				'state'                 => is_array( $outcome ) ? (string) ( $outcome['state'] ?? '' ) : '',
				'approved_scope_digest' => is_array( $outcome ) ? (string) ( $outcome['approved_scope_digest'] ?? '' ) : '',
				'executed_plan_digest'  => is_array( $outcome ) ? (string) ( $outcome['executed_plan_digest'] ?? '' ) : '',
			)
		);
	}

	/**
	 * The admin screen carrying the nonce. Registered only when commissioned.
	 *
	 * @return void
	 */
	public static function register_page(): void {
		add_submenu_page(
			'tools.php',
			'Translation Automation — Batch Control',
			'Translation Batch Control',
			self::CAPABILITY,
			self::ACTION,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the batch-control screen.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to control batches.', 'conexao-translation-automation' ),
				403
			);
		}

		echo '<div class="wrap"><h1>';
		esc_html_e( 'Translation Batch Control', 'conexao-translation-automation' );
		echo '</h1><p>';
		esc_html_e(
			'Every action below is a separate, digest-bound decision. The batch, its records and its environment are resolved by the server.',
			'conexao-translation-automation'
		);
		echo '</p><form method="post" action="';
		echo esc_url( admin_url( 'admin-post.php' ) );
		echo '">';

		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<p><label for="conexao_batch_id">Batch ID</label> <input type="text" id="conexao_batch_id" name="conexao_batch_id" /></p>';
		echo '<p><select name="conexao_batch_action">';

		foreach ( self::actions() as $action ) {
			printf(
				'<option value="%1$s">%2$s — %3$s</option>',
				esc_attr( $action ),
				esc_html( $action ),
				esc_html( self::authorises()[ $action ] )
			);
		}

		echo '</select></p><p><button type="submit" class="button button-primary">';
		esc_html_e( 'Apply this action', 'conexao-translation-automation' );
		echo '</button></p></form></div>';
	}
}