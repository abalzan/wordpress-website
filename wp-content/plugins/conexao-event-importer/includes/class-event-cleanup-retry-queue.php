<?php
/**
 * Retry queue for the event-cleanup batch runner.
 *
 * Durably records failed event IDs that an operator has explicitly requested
 * to retry. The queue survives across runs: completed, cancelled, failed,
 * and newly started runs all read/write the same option.
 *
 * ## Storage
 *
 * A dedicated, persistent option (conexao_event_cleanup_retry_queue,
 * autoload=false), NOT the run-state option.
 *
 * ## Atomicity
 *
 * All read-modify-write operations use SELECT ... FOR UPDATE (InnoDB row lock)
 * + compare-and-swap (WHERE option_value = :old) so that concurrent updates
 * (worker dequeue + operator "Retry") cannot overwrite each other.
 *
 * ## Capacity
 *
 * Fail-closed: 500 pending entries. Processed entries are removed on success,
 * updated on failure. No eviction.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Retry queue for failed events.
 */
final class Conexao_Event_Cleanup_Retry_Queue {

	/**
	 * Option name for the persistent queue.
	 *
	 * @var string
	 */
	const OPTION = 'conexao_event_cleanup_retry_queue';

	/**
	 * Prefix of the per-process test store for the queue row.
	 *
	 * @var string
	 */
	const TEST_OPTION_PREFIX = 'conexao_event_cleanup_retry_queue__test__';

	/**
	 * Maximum pending entries.
	 *
	 * @var int
	 */
	const MAX_PENDING = 500;

	/**
	 * Resolve the option name (production vs per-process test store).
	 *
	 * @throws RuntimeException When the harness is loaded without a valid suffix.
	 * @return string Option name.
	 */
	public static function option_name(): string {
		if ( ! defined( 'CONEXAO_TESTS_ROOT' ) && ! defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
			return self::OPTION;
		}

		$suffix = '';

		if ( defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
			$suffix = strtolower( (string) CONEXAO_TEST_OPTION_SUFFIX );
		}

		if ( ! preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', $suffix ) ) {
			throw new RuntimeException( 'conexao: operator-store isolation was not established for the retry queue.' );
		}

		return self::TEST_OPTION_PREFIX . $suffix;
	}

	/**
	 * Initialise the queue option row so SELECT ... FOR UPDATE can lock it.
	 *
	 * Must be called before the destructive worker transaction (design
	 * safeguard #1). Uses INSERT IGNORE so it is safe to call repeatedly.
	 *
	 * @return bool True when the row exists after the call.
	 */
	public static function ensure_row_exists(): bool {
		global $wpdb;

		$option = self::option_name();

		$placeholder = (string) wp_json_encode(
			array(
				'entries'        => array(),
				'capacity'       => self::MAX_PENDING,
				'overflow_count' => 0,
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )",
				$option,
				$placeholder
			)
		);

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
				$option
			)
		);

		return (int) $exists > 0;
	}

	/**
	 * Read the current queue (as stored bytes + decoded array).
	 *
	 * Reads the table directly so the bytes are the exact stored value (the
	 * CAS compares against these).
	 *
	 * @return array { raw: string|null, data: array }
	 */
	public static function read(): array {
		global $wpdb;

		$option = self::option_name();

		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option
			)
		);

		if ( null === $raw ) {
			return array(
				'raw'  => null,
				'data' => array(
					'entries'        => array(),
					'capacity'       => self::MAX_PENDING,
					'overflow_count' => 0,
				),
			);
		}

		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['entries'] ) || ! is_array( $decoded['entries'] ) ) {
			return array(
				'raw'  => (string) $raw,
				'data' => array(
					'entries'        => array(),
					'capacity'       => self::MAX_PENDING,
					'overflow_count' => 0,
				),
			);
		}

		return array(
			'raw'  => (string) $raw,
			'data' => $decoded,
		);
	}

	/**
	 * Count pending entries.
	 *
	 * @param array $data Queue data.
	 * @return int
	 */
	public static function pending_count( array $data ): int {
		$count = 0;

		foreach ( $data['entries'] as $entry ) {
			if ( isset( $entry['status'] ) && 'pending' === $entry['status'] ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Atomically enqueue an event ID for retry inside the caller's transaction.
	 *
	 * The caller must hold an open transaction and have already locked the
	 * queue row with self::read_for_update(). This method reads the current
	 * value, computes the new value, and CAS-updates it. If the CAS fails
	 * (0 rows), the caller must roll back.
	 *
	 * @param int   $event_id       Event ID to enqueue for retry.
	 * @param array $failure_record The failure record from failed_events/failed_events_final.
	 * @param int   $now            Current timestamp.
	 * @param int   $capacity       Optional capacity override for testing (defaults to MAX_PENDING).
	 * @return array { ok: bool, reason: string, pending_count: int }
	 */
	public static function enqueue_in_transaction( int $event_id, array $failure_record, int $now, int $capacity = null ): array {
		global $wpdb;

		$option = self::option_name();

		$current = self::read();
		$data    = $current['data'];

		$pending = self::pending_count( $data );
		$max_pending = $capacity ?? self::MAX_PENDING;

		$event_id = (int) $event_id;

		if ( isset( $data['entries'][ $event_id ] ) ) {
			// Already queued - update the attempt info but don't double-count
			$data['entries'][ $event_id ]['last_retry_at'] = $now;
			$data['entries'][ $event_id ]['retry_reason']  = $failure_record['last_reason'] ?? '';
			$data['entries'][ $event_id ]['attempts']      = $failure_record['attempts'] ?? 0;
		} else {
			if ( $pending >= $max_pending ) {
				return array(
					'ok'            => false,
					'reason'        => 'capacity',
					'pending_count' => $pending,
				);
			}

			$data['entries'][ $event_id ] = array(
				'event_id'       => $event_id,
				'added_at'       => $now,
				'last_retry_at'  => $now,
				'retry_reason'   => $failure_record['last_reason'] ?? '',
				'attempts'       => $failure_record['attempts'] ?? 0,
				'first_failed_at'=> $failure_record['first_failed_at'] ?? $now,
				'status'         => 'pending',
			);

			$pending++;
		}

		$data['capacity'] = $max_pending;

		$new_raw = (string) wp_json_encode( $data );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_raw,
				$option,
				$current['raw']
			)
		);

		if ( false === $affected || 1 !== (int) $affected ) {
			return array(
				'ok'            => false,
				'reason'        => 'cas_conflict',
				'pending_count' => $pending,
			);
		}

		self::flush_option_cache();

		return array(
			'ok'            => true,
			'reason'        => 'enqueued',
			'pending_count' => $pending,
		);
	}

	/**
	 * Read the queue row under FOR UPDATE (caller must hold a transaction).
	 *
	 * Returns the decoded data AND the raw bytes (for CAS comparison), or null if the row does not exist.
	 *
	 * @return array|null { raw: string, data: array }
	 */
	public static function read_for_update(): ?array {
		global $wpdb;

		$option = self::option_name();

		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
				$option
			)
		);

		if ( null === $raw ) {
			return null;
		}

		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['entries'] ) || ! is_array( $decoded['entries'] ) ) {
			return array(
				'raw'  => (string) $raw,
				'data' => array(
					'entries'        => array(),
					'capacity'       => self::MAX_PENDING,
					'overflow_count' => 0,
				),
			);
		}

		return array(
			'raw'  => (string) $raw,
			'data' => $decoded,
		);
	}

	/**
	 * Atomically mark an event as successfully retried (remove from queue).
	 *
	 * Uses the same row-lock + CAS pattern as enqueue.
	 *
	 * @param int $event_id Event ID.
	 * @param int $now      Current timestamp.
	 * @return array { ok: bool, reason: string }
	 */
	public static function mark_retried( int $event_id, int $now ): array {
		global $wpdb;

		$option = self::option_name();

		// Start transaction.
		$wpdb->query( 'START TRANSACTION' );

		// Lock the queue row.
		$current = self::read_for_update();

		if ( null === $current ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'     => false,
				'reason' => 'queue_row_missing',
			);
		}

		if ( ! isset( $current['data']['entries'][ $event_id ] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'     => false,
				'reason' => 'not_in_queue',
			);
		}

		// Remove the entry.
		unset( $current['data']['entries'][ $event_id ] );

		$new_raw = (string) wp_json_encode( $current['data'] );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_raw,
				$option,
				$current['raw']
			)
		);

		if ( false === $affected || 1 !== (int) $affected ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'     => false,
				'reason' => 'cas_conflict',
			);
		}

		$wpdb->query( 'COMMIT' );
		self::flush_option_cache();

		return array(
			'ok'     => true,
			'reason' => 'retried',
		);
	}

	/**
	 * Atomically update a retry entry after a failed retry attempt.
	 *
	 * @param int   $event_id Event ID.
	 * @param int   $attempts New attempt count.
	 * @param string $reason   Failure reason.
	 * @param int   $now      Current timestamp.
	 * @return array { ok: bool, reason: string }
	 */
	public static function update_after_failure( int $event_id, int $attempts, string $reason, int $now ): array {
		global $wpdb;

		$option = self::option_name();

		$wpdb->query( 'START TRANSACTION' );

		$current = self::read_for_update();

		if ( null === $current ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'     => false,
				'reason' => 'queue_row_missing',
			);
		}

		if ( ! isset( $current['data']['entries'][ $event_id ] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'     => false,
				'reason' => 'not_in_queue',
			);
		}

		$current['data']['entries'][ $event_id ]['attempts']      = $attempts;
		$current['data']['entries'][ $event_id ]['last_retry_at'] = $now;
		$current['data']['entries'][ $event_id ]['retry_reason']  = $reason;

		$new_raw = (string) wp_json_encode( $current['data'] );

		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_raw,
				$option,
				$current['raw']
			)
		);

		if ( false === $affected || 1 !== (int) $affected ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'     => false,
				'reason' => 'cas_conflict',
			);
		}

		$wpdb->query( 'COMMIT' );
		self::flush_option_cache();

		return array(
			'ok'     => true,
			'reason' => 'updated',
		);
	}

	/**
	 * Flush the option cache for this queue.
	 */
	public static function flush_option_cache(): void {
		wp_cache_delete( self::option_name(), 'options' );
	}
}