<?php
/**
 * The server-side emergency stop (Stage 10).
 *
 * ## What it is, and what it is not
 *
 * A single server-side flag that prevents any FURTHER operation from starting.
 * It is a brake, not a scalpel: it is checked BEFORE each new operation, so it
 * can never interrupt a mutation in progress and can never bypass
 * verification for an operation already under way. The operator who wants an
 * operation itself cut short aborts the batch, and even then the executor
 * finishes the current safe unit first.
 *
 * ## Fail closed, and fail closed on MALFORMED too
 *
 * The flag is stored as a small record. The rule is deliberately blunt:
 *
 * | Stored value | Reading |
 * |---|---|
 * | absent | **STOPPED** — the default is that new work does not start |
 * | `stopped = false`, well formed | enabled — operations may be started |
 * | `stopped = true`, well formed | STOPPED |
 * | anything else, or a wrong schema version | **STOPPED** — malformed is not "probably fine" |
 *
 * So a corrupted row, a truncated write or an unexpected shape cannot be read
 * as "the switch is off". A malformed safety control is a stop, never a
 * permission.
 *
 * ## The browser cannot fabricate it
 *
 * The value lives in `wp_options` and is written only through this class,
 * which requires an explicit authorisation context in code. No request
 * parameter is ever read as the flag, and no admin screen is registered by
 * Stage 10. A client cannot assert "the emergency stop is off" because there
 * is no client-supplied input to this class at all.
 *
 * ## It does not depend on provider behaviour
 *
 * The flag is read from the database. It does not consult the provider, does
 * not require a network round trip, and works identically when the provider is
 * unreachable — which is exactly when an operator most needs it.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The emergency-stop kill switch.
 */
final class Conexao_Translation_Automation_Emergency_Stop {

	/**
	 * The options row holding the flag.
	 *
	 * @var string
	 */
	const OPTION = 'conexao_translation_automation_emergency_stop';

	/**
	 * The record schema version this class writes and understands.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Failure categories.
	 *
	 * @var string
	 */
	const FAILURE_STOPPED      = 'emergency_stop_engaged';
	const FAILURE_MALFORMED    = 'emergency_stop_malformed';
	const FAILURE_UNAUTHORIZED = 'unauthorized';

	/**
	 * Overridable reader, so tests can inject a flag without touching storage.
	 *
	 * @var callable|null
	 */
	private static $reader = null;

	/**
	 * Test support: replace the reader. Null restores the real one.
	 *
	 * @param callable|null $reader Callable returning the raw stored value.
	 * @return void
	 */
	public static function set_reader( $reader ): void {
		self::$reader = $reader;
	}

	/**
	 * The raw stored value.
	 *
	 * @return mixed
	 */
	private static function raw() {
		if ( is_callable( self::$reader ) ) {
			return call_user_func( self::$reader );
		}

		return get_option( self::OPTION, null );
	}

	/**
	 * The current state, as a verdict.
	 *
	 * @return array{stopped:bool,failure:string,reason:string,state:string}
	 */
	public static function evaluate(): array {
		$raw = self::raw();

		// Absent means STOPPED. Bulk translation is OFF by default, and
		// enabling operation is an explicit configuration act, not the
		// consequence of an option never having been written.
		if ( null === $raw ) {
			return array(
				'stopped' => true,
				'failure' => self::FAILURE_STOPPED,
				'reason'  => 'no emergency-stop record exists; new work does not start until one does',
				'state'   => 'absent',
			);
		}

		if ( is_string( $raw ) ) {
			$raw = maybe_unserialize( $raw );
		}

		if ( ! is_array( $raw ) || ! isset( $raw['v'] ) || ! array_key_exists( 'stopped', $raw ) ) {
			return array(
				'stopped' => true,
				'failure' => self::FAILURE_MALFORMED,
				'reason'  => 'the emergency-stop record is malformed; a malformed safety control reads as STOPPED',
				'state'   => 'malformed',
			);
		}

		if ( self::SCHEMA_VERSION !== (int) $raw['v'] ) {
			return array(
				'stopped' => true,
				'failure' => self::FAILURE_MALFORMED,
				'reason'  => sprintf(
					'the emergency-stop record declares schema %s; this build understands %d. Reading it as STOPPED.',
					wp_json_encode( $raw['v'] ),
					self::SCHEMA_VERSION
				),
				'state'   => 'incompatible',
			);
		}

		// A non-boolean `stopped` is malformed, not truthy. `stopped = 0` written
		// by a careless writer must not be read as "explicitly enabled".
		if ( ! is_bool( $raw['stopped'] ) ) {
			return array(
				'stopped' => true,
				'failure' => self::FAILURE_MALFORMED,
				'reason'  => 'the emergency-stop record carries a non-boolean "stopped" value; reading it as STOPPED',
				'state'   => 'malformed',
			);
		}

		if ( true === $raw['stopped'] ) {
			return array(
				'stopped' => true,
				'failure' => self::FAILURE_STOPPED,
				'reason'  => (string) ( $raw['reason'] ?? 'the emergency stop is engaged' ),
				'state'   => 'stopped',
			);
		}

		return array(
			'stopped' => false,
			'failure' => '',
			'reason'  => (string) ( $raw['reason'] ?? 'the emergency stop is explicitly cleared' ),
			'state'   => 'enabled',
		);
	}

	/**
	 * May a new operation start right now?
	 *
	 * @return true|WP_Error
	 */
	public static function assert_may_start() {
		$verdict = self::evaluate();

		if ( $verdict['stopped'] ) {
			return new WP_Error( 'conexao_automation_' . $verdict['failure'], $verdict['reason'] );
		}

		return true;
	}

	/**
	 * Is the switch currently stopping new work?
	 *
	 * @return bool
	 */
	public static function is_stopped(): bool {
		$verdict = self::evaluate();

		return (bool) $verdict['stopped'];
	}

	/**
	 * Set the switch, with an explicit authorisation requirement.
	 *
	 * There is deliberately no public endpoint, no REST route and no AJAX
	 * handler for this. An operator reaches it through an authorised admin
	 * capability; Stage 10 does not register one, so the only reachable caller
	 * today is a trusted in-process one.
	 *
	 * @param bool  $stopped True to stop new work, false to clear it.
	 * @param array $context The invocation context. {
	 *     @type bool   $authorized The caller's own authorisation decision.
	 *     @type string $capability Must equal 'manage_options'.
	 *     @type string $reason      Human-readable, non-secret explanation.
	 * }
	 * @return true|WP_Error
	 */
	public static function set( bool $stopped, array $context ) {
		if ( empty( $context['authorized'] ) || true !== $context['authorized'] ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNAUTHORIZED,
				'The caller is not authorised to move the emergency stop.'
			);
		}

		$capability = isset( $context['capability'] ) ? trim( (string) $context['capability'] ) : '';

		if ( 'manage_options' !== $capability ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNAUTHORIZED,
				'The capability assertion does not match the required capability.'
			);
		}

		$record = array(
			'v'          => self::SCHEMA_VERSION,
			'stopped'    => $stopped,
			'reason'     => (string) ( $context['reason'] ?? '' ),
			'changed_at' => gmdate( 'c' ),
		);

		$clean = Conexao_Translation_Automation_Result::assert_no_secrets( $record );

		if ( is_wp_error( $clean ) ) {
			return new WP_Error(
				'conexao_automation_emergency_stop_secret',
				'Refusing to store an emergency-stop record carrying a credential-shaped value.'
			);
		}

		update_option( self::OPTION, $clean, false );

		return true;
	}

	/**
	 * Forget the record, returning the switch to its fail-closed default.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
