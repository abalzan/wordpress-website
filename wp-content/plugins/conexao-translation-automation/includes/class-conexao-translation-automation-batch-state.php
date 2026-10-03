<?php
/**
 * The batch state machine and its persisted store (Stage 10).
 *
 * ## Why the state machine is explicit
 *
 * A batch is a thing that moves through states, and every transition is a
 * decision someone or something made. If transitions were implicit — a flag
 * here, a status column there — then "how did this batch get to EXECUTING"
 * would not be answerable, and a caller could move a batch anywhere by
 * supplying a status string. So the legal transitions are DECLARED here as a
 * table, an undeclared transition is a hard failure, and no request parameter
 * is ever read as a state.
 *
 * ## The table, and the one transition that is deliberately absent
 *
 * ```
 *   REVIEW_REQUIRED    -> REVIEWED | EXPIRED
 *   REVIEWED           -> APPROVED_FOR_BATCH | REVIEW_REQUIRED | EXPIRED
 *   APPROVED_FOR_BATCH -> EXECUTING | ABORTED | EXPIRED
 *   EXECUTING          -> VERIFIED | FAILED | STOPPED | ABORTED
 *   VERIFIED           -> EXPIRED
 *   FAILED             -> REVIEW_REQUIRED | EXPIRED
 *   STOPPED            -> REVIEW_REQUIRED | EXPIRED
 *   ABORTED            -> REVIEW_REQUIRED | EXPIRED
 *   EXPIRED            -> (terminal)
 * ```
 *
 * There is NO `VERIFIED -> EXECUTING` edge. A verified batch cannot begin
 * another execution, so a verified batch can never become the "previous batch"
 * of an automatic second batch. The only way to execute more work is for a
 * human to compose a NEW batch, which starts again at `REVIEW_REQUIRED` and
 * needs its own review, its own approval and its own digest.
 *
 * ## Per-operation status is persisted, so a resume is not a blind restart
 *
 * Each operation carries one of `pending`, `completed`, `failed`,
 * `verification_failed` or `manual_intervention_required`. A resume skips the
 * completed ones, refuses to touch the ones that need a human, and re-attempts
 * only what genuinely did not finish. It is not a second idempotence
 * mechanism: the engine's own source-state protection and plan-level
 * idempotence still decide whether a record actually needs writing, and this
 * store only records what was OBSERVED.
 *
 * @package Conexao_Translation_Automation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The batch state machine and the persisted batch store.
 */
final class Conexao_Translation_Automation_Batch_State {

	/**
	 * The options row holding the batch store.
	 *
	 * @var string
	 */
	const OPTION = 'conexao_translation_automation_batches';

	/**
	 * The options row holding the per-batch operator abort flags.
	 *
	 * Separate from the batch store so recording an abort can never rewrite or
	 * corrupt the audit state of a batch, which is what an operator needs when
	 * they stop a run in flight and then want to see what it had done.
	 *
	 * @var string
	 */
	const ABORT_OPTION = 'conexao_translation_automation_batch_abort';

	/**
	 * The stored-record schema version.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * The legal transitions, as an explicit table.
	 *
	 * @var array<string,array<int,string>>
	 */
	const TRANSITIONS = array(
		Conexao_Translation_Automation_Batch::REVIEW_REQUIRED => array(
			Conexao_Translation_Automation_Batch::REVIEWED,
			Conexao_Translation_Automation_Batch::EXPIRED,
		),
		Conexao_Translation_Automation_Batch::REVIEWED  => array(
			Conexao_Translation_Automation_Batch::APPROVED_FOR_BATCH,
			Conexao_Translation_Automation_Batch::REVIEW_REQUIRED,
			Conexao_Translation_Automation_Batch::EXPIRED,
		),
		Conexao_Translation_Automation_Batch::APPROVED_FOR_BATCH => array(
			Conexao_Translation_Automation_Batch::EXECUTING,
			Conexao_Translation_Automation_Batch::ABORTED,
			Conexao_Translation_Automation_Batch::EXPIRED,
		),
		Conexao_Translation_Automation_Batch::EXECUTING => array(
			Conexao_Translation_Automation_Batch::VERIFIED,
			Conexao_Translation_Automation_Batch::FAILED,
			Conexao_Translation_Automation_Batch::STOPPED,
			Conexao_Translation_Automation_Batch::ABORTED,
		),
		Conexao_Translation_Automation_Batch::VERIFIED  => array(
			Conexao_Translation_Automation_Batch::EXPIRED,
		),
		Conexao_Translation_Automation_Batch::FAILED    => array(
			Conexao_Translation_Automation_Batch::REVIEW_REQUIRED,
			Conexao_Translation_Automation_Batch::EXPIRED,
		),
		Conexao_Translation_Automation_Batch::STOPPED   => array(
			Conexao_Translation_Automation_Batch::REVIEW_REQUIRED,
			Conexao_Translation_Automation_Batch::EXPIRED,
		),
		Conexao_Translation_Automation_Batch::ABORTED   => array(
			Conexao_Translation_Automation_Batch::REVIEW_REQUIRED,
			Conexao_Translation_Automation_Batch::EXPIRED,
		),
		Conexao_Translation_Automation_Batch::EXPIRED   => array(),
	);

	/**
	 * Failure categories this class can return.
	 *
	 * @var string
	 */
	const FAILURE_UNKNOWN_STATE     = 'unknown_batch_state';
	const FAILURE_UNKNOWN_TARGET    = 'illegal_batch_transition';
	const FAILURE_NOT_A_BATCH_STATE = 'not_a_batch_state';
	const FAILURE_STORE_WRITE       = 'batch_store_write_failed';
	const FAILURE_UNKNOWN_BATCH     = 'unknown_batch';
	const FAILURE_ABORTED           = 'batch_aborted';
	const FAILURE_EXPIRED           = 'batch_expired';

	/**
	 * Every state this machine knows.
	 *
	 * @return array<int,string>
	 */
	public static function states(): array {
		return array_keys( self::TRANSITIONS );
	}

	/**
	 * Is this a state the machine knows?
	 *
	 * @param string $state Candidate state.
	 * @return bool
	 */
	public static function is_state( string $state ): bool {
		return array_key_exists( $state, self::TRANSITIONS );
	}

	/**
	 * May this batch move from one state to another?
	 *
	 * An unknown state on EITHER side is a hard failure, never a permissive
	 * "yes". So a caller cannot reach EXECUTING by inventing a state name.
	 *
	 * @param string $from Current state.
	 * @param string $to   Requested state.
	 * @return bool
	 */
	public static function can_transition( string $from, string $to ): bool {
		if ( ! self::is_state( $from ) || ! self::is_state( $to ) ) {
			return false;
		}

		return in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/**
	 * The legal successors of a state.
	 *
	 * @param string $state Current state.
	 * @return array<int,string>
	 */
	public static function successors( string $state ): array {
		return self::is_state( $state ) ? self::TRANSITIONS[ $state ] : array();
	}

	/**
	 * Assert a transition, returning a failure category when it is illegal.
	 *
	 * @param string $from Current state.
	 * @param string $to   Requested state.
	 * @return true|WP_Error
	 */
	public static function assert_transition( string $from, string $to ) {
		if ( ! self::is_state( $from ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNKNOWN_STATE,
				sprintf( '"%s" is not a batch state this machine knows.', $from )
			);
		}

		if ( ! self::is_state( $to ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_NOT_A_BATCH_STATE,
				sprintf( '"%s" is not a batch state.', $to )
			);
		}

		if ( ! self::can_transition( $from, $to ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNKNOWN_TARGET,
				sprintf( 'A batch cannot move from %s to %s.', $from, $to )
			);
		}

		return true;
	}

	/**
	 * The abort flag. An operator sets it; the executor honours it.
	 *
	 * Replaced by tests to inject a stop without an operator. Passing null
	 * restores the real reader.
	 *
	 * @var callable|null
	 */
	private static $abort_reader = null;

	/**
	 * The store writer. Replaced by tests to inject a persistence failure.
	 *
	 * @var callable|null
	 */
	private static $writer = null;

	/**
	 * Test support: replace the abort-flag reader.
	 *
	 * @param callable|null $reader Callable returning bool.
	 * @return void
	 */
	public static function set_abort_reader( $reader ): void {
		self::$abort_reader = $reader;
	}

	/**
	 * Test support: replace the store writer.
	 *
	 * @param callable|null $writer Callable receiving (array $batches): bool|WP_Error.
	 * @return void
	 */
	public static function set_writer( $writer ): void {
		self::$writer = $writer;
	}

	/**
	 * Is an operator abort recorded for this batch?
	 *
	 * The flag is checked BEFORE each new operation, never during one, so an
	 * abort cannot interrupt a mutation in progress. The semantic is
	 * "finish the current safe unit, then stop", which is the only safe
	 * reading of an abort in a runtime with no safe preemption point.
	 *
	 * @param string $batch_id Batch identifier.
	 * @return bool
	 */
	public static function is_aborted( string $batch_id ): bool {
		if ( is_callable( self::$abort_reader ) ) {
			return true === call_user_func( self::$abort_reader, $batch_id );
		}

		$flags = get_option( self::ABORT_OPTION, array() );

		return is_array( $flags ) && ! empty( $flags[ $batch_id ] );
	}

	/**
	 * Record an operator abort for a batch.
	 *
	 * Authorisation is the caller's responsibility and is checked by
	 * `Batch_Approval` before this is reached; this method only records the
	 * fact. It never deletes or rewrites audit state, and it never touches the
	 * lock, so it cannot release a lock another run is holding.
	 *
	 * @param string $batch_id Batch identifier.
	 * @return void
	 */
	public static function request_abort( string $batch_id ) {
		$flags = get_option( self::ABORT_OPTION, array() );
		$flags = is_array( $flags ) ? $flags : array();

		$flags[ $batch_id ] = gmdate( 'c' );

		update_option( self::ABORT_OPTION, $flags, false );
	}

	/**
	 * Clear the abort flag for a batch, so a NEW explicitly authorised
	 * invocation can start from a clean slate.
	 *
	 * @param string $batch_id Batch identifier.
	 * @return void
	 */
	public static function clear_abort( string $batch_id ) {
		$flags = get_option( self::ABORT_OPTION, array() );

		if ( is_array( $flags ) && isset( $flags[ $batch_id ] ) ) {
			unset( $flags[ $batch_id ] );
			update_option( self::ABORT_OPTION, $flags, false );
		}
	}

	/**
	 * The persisted batch store, tolerating corruption.
	 *
	 * A corrupt store reads as EMPTY rather than as an error, because an
	 * unreadable store must not stop the site. It is still surfaced by
	 * `read_state()`.
	 *
	 * @return array<string,array> Batch id => record.
	 */
	public static function read(): array {
		$raw = get_option( self::OPTION, array() );

		if ( ! is_array( $raw ) ) {
			$raw = maybe_unserialize( $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( $raw as $batch_id => $record ) {
			if ( is_array( $record ) && isset( $record['batch_id'] ) && (int) ( $record['v'] ?? 0 ) === self::SCHEMA_VERSION ) {
				$clean[ (string) $batch_id ] = $record;
			}
		}

		return $clean;
	}

	/**
	 * The health of the stored batches.
	 *
	 * @return array{ok:bool,reason:string,count:int}
	 */
	public static function read_state(): array {
		$raw = get_option( self::OPTION, null );

		if ( null === $raw ) {
			return array(
				'ok'     => true,
				'reason' => 'no batch has been stored yet',
				'count'  => 0,
			);
		}

		if ( ! is_array( $raw ) && ! is_array( maybe_unserialize( $raw ) ) ) {
			return array(
				'ok'     => false,
				'reason' => 'the stored batch state is unreadable and was ignored',
				'count'  => 0,
			);
		}

		return array(
			'ok'     => true,
			'reason' => '',
			'count'  => count( self::read() ),
		);
	}

	/**
	 * One stored batch record, or null.
	 *
	 * @param string $batch_id Batch identifier.
	 * @return array|null
	 */
	public static function get( string $batch_id ): ?array {
		$store = self::read();

		return isset( $store[ $batch_id ] ) ? $store[ $batch_id ] : null;
	}

	/**
	 * Persist one batch record, enforcing the secret rule.
	 *
	 * @param array $record A record from `Batch::to_record()`.
	 * @return true|WP_Error
	 */
	public static function save( array $record ) {
		if ( empty( $record['batch_id'] ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_STORE_WRITE,
				'A batch record without a batch id cannot be stored.'
			);
		}

		$clean = Conexao_Translation_Automation_Result::assert_no_secrets( $record );

		if ( is_wp_error( $clean ) ) {
			return new WP_Error(
				'conexao_automation_batch_record_secret',
				'Refusing to store a batch record carrying a credential-shaped value.'
			);
		}

		$store                                 = self::read();
		$store[ (string) $record['batch_id'] ] = $clean;

		if ( is_callable( self::$writer ) ) {
			$written = call_user_func( self::$writer, $store );

			return true === $written ? true : new WP_Error(
				'conexao_automation_' . self::FAILURE_STORE_WRITE,
				'The batch store refused the write.'
			);
		}

		// autoload = false: a batch record is read by an operator screen and a
		// resume, never by the front end of every request.
		$ok = update_option( self::OPTION, $store, false );

		if ( false === $ok && self::OPTION !== get_option( self::OPTION, false ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_STORE_WRITE,
				'The batch store could not be persisted.'
			);
		}

		return true;
	}

	/**
	 * Move a stored batch to a new state, if the transition is legal.
	 *
	 * This is the ONLY way a batch changes state, which is what makes the
	 * state table the authority. An illegal transition is refused and the
	 * stored state is left exactly as it was.
	 *
	 * @param string $batch_id Batch identifier.
	 * @param string $to       Requested state.
	 * @return array|WP_Error The updated record, or a failure.
	 */
	public static function transition( string $batch_id, string $to ) {
		$record = self::get( $batch_id );

		if ( null === $record ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNKNOWN_BATCH,
				sprintf( 'No stored batch "%s".', $batch_id )
			);
		}

		$from = (string) ( $record['state'] ?? '' );
		$edge = self::assert_transition( $from, $to );

		if ( is_wp_error( $edge ) ) {
			return $edge;
		}

		$record['state']         = $to;
		$record['state_changed'] = gmdate( 'c' );
		$record['state_from']    = $from;

		$saved = self::save( $record );

		return is_wp_error( $saved ) ? $saved : $record;
	}

	/**
	 * Record the observed status of one operation.
	 *
	 * An operation outside the approved identity set is REFUSED. That is the
	 * check that makes "an approval for A+B+C cannot execute A+B+D" true at
	 * the executor, not merely at composition time.
	 *
	 * @param string $batch_id Batch identifier.
	 * @param string $identity Portable source identity.
	 * @param string $status   One of the `Batch::OP_*` constants.
	 * @param array  $detail   Optional non-secret detail.
	 * @return array|WP_Error The updated record, or a failure.
	 */
	public static function record_operation( string $batch_id, string $identity, string $status, array $detail = array() ) {
		$record = self::get( $batch_id );

		if ( null === $record ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNKNOWN_BATCH,
				sprintf( 'No stored batch "%s".', $batch_id )
			);
		}

		$identities = array_map( 'strval', (array) ( $record['identities'] ?? array() ) );

		if ( ! in_array( $identity, $identities, true ) ) {
			return new WP_Error(
				'conexao_automation_operation_outside_batch',
				sprintf(
					'Record "%s" is not in the approved batch; an approval cannot be widened at execution time.',
					$identity
				)
			);
		}

		$known = array(
			Conexao_Translation_Automation_Batch::OP_PENDING,
			Conexao_Translation_Automation_Batch::OP_COMPLETED,
			Conexao_Translation_Automation_Batch::OP_FAILED,
			Conexao_Translation_Automation_Batch::OP_VERIFICATION_FAILED,
			Conexao_Translation_Automation_Batch::OP_MANUAL_INTERVENTION,
		);

		if ( ! in_array( $status, $known, true ) ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_NOT_A_BATCH_STATE,
				sprintf( '"%s" is not a per-operation status.', $status )
			);
		}

		$clean = Conexao_Translation_Automation_Result::assert_no_secrets( $detail );

		if ( is_wp_error( $clean ) ) {
			return new WP_Error(
				'conexao_automation_batch_detail_secret',
				'Refusing to store per-operation detail carrying a credential-shaped value.'
			);
		}

		$operations = isset( $record['operations_log'] ) && is_array( $record['operations_log'] )
			? $record['operations_log']
			: array();

		$operations[ $identity ] = array(
			'status'     => $status,
			'updated_at' => gmdate( 'c' ),
			'detail'     => $clean,
		);

		$record['operations_log'] = $operations;

		$saved = self::save( $record );

		return is_wp_error( $saved ) ? $saved : $record;
	}

	/**
	 * The per-operation status map, with every identity present.
	 *
	 * An identity with no recorded status reads as `pending`, so "unattempted"
	 * and "never recorded" cannot be confused with "completed".
	 *
	 * @param string $batch_id Batch identifier.
	 * @return array<string,string>|WP_Error
	 */
	public static function operation_statuses( string $batch_id ) {
		$record = self::get( $batch_id );

		if ( null === $record ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNKNOWN_BATCH,
				sprintf( 'No stored batch "%s".', $batch_id )
			);
		}

		$log  = isset( $record['operations_log'] ) && is_array( $record['operations_log'] ) ? $record['operations_log'] : array();
		$seen = array();

		foreach ( array_map( 'strval', (array) ( $record['identities'] ?? array() ) ) as $identity ) {
			$seen[ $identity ] = isset( $log[ $identity ]['status'] )
				? (string) $log[ $identity ]['status']
				: Conexao_Translation_Automation_Batch::OP_PENDING;
		}

		return $seen;
	}

	/**
	 * The operations a RESUME would still attempt.
	 *
	 * Completed operations are never included, so a resume cannot silently
	 * reapply already verified work. Operations needing a human are reported
	 * separately rather than retried.
	 *
	 * @param string $batch_id Batch identifier.
	 * @return array{retry:array<int,string>,manual:array<int,string>,completed:array<int,string>}|WP_Error
	 */
	public static function resume_plan( string $batch_id ) {
		$statuses = self::operation_statuses( $batch_id );

		if ( is_wp_error( $statuses ) ) {
			return $statuses;
		}

		$retry     = array();
		$manual    = array();
		$completed = array();

		foreach ( $statuses as $identity => $status ) {
			if ( Conexao_Translation_Automation_Batch::OP_COMPLETED === $status ) {
				$completed[] = $identity;
			} elseif ( Conexao_Translation_Automation_Batch::OP_MANUAL_INTERVENTION === $status ) {
				$manual[] = $identity;
			} else {
				$retry[] = $identity;
			}
		}

		return array(
			'retry'     => $retry,
			'manual'    => $manual,
			'completed' => $completed,
		);
	}

	/**
	 * Link a batch to the one it follows, and the one it resumes.
	 *
	 * Recorded on the record for the audit trail. A `previous_batch_id` is
	 * evidence for an expansion request, never an authorisation to continue.
	 *
	 * @param string $batch_id  Batch identifier.
	 * @param string $previous  The batch this one follows, or ''.
	 * @param string $resumed   The batch this one resumes, or ''.
	 * @return array|WP_Error
	 */
	public static function link( string $batch_id, string $previous, string $resumed ) {
		$record = self::get( $batch_id );

		if ( null === $record ) {
			return new WP_Error(
				'conexao_automation_' . self::FAILURE_UNKNOWN_BATCH,
				sprintf( 'No stored batch "%s".', $batch_id )
			);
		}

		if ( '' !== $previous ) {
			$record['previous_batch_id'] = $previous;
		}

		if ( '' !== $resumed ) {
			$record['resumed_from_batch_id'] = $resumed;
		}

		$saved = self::save( $record );

		return is_wp_error( $saved ) ? $saved : $record;
	}

	/**
	 * Forget every stored batch. Test support and the documented reset.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
		delete_option( self::ABORT_OPTION );
	}
}
