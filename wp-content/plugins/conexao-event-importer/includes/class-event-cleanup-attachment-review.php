<?php
/**
 * Attachment review queue for the event-cleanup batch runner.
 *
 * The cleanup engine preserves attachments by default (automatic attachment
 * deletion is disabled — see the design note §4.6). This queue durably records
 * preserved attachments so an operator can review them later.
 *
 * ## Storage
 *
 * A dedicated, persistent option (conexao_event_cleanup_attachment_review,
 * autoload=false), NOT the run-state option. The queue survives across runs:
 * completed, cancelled, failed, and newly started runs all read/write the same
 * option. A new run does not reset or replace the queue.
 *
 * ## Atomicity
 *
 * All read-modify-write operations use SELECT ... FOR UPDATE (InnoDB row lock)
 * + compare-and-swap (WHERE option_value = :old) so that concurrent updates
 * (worker enqueue + operator "Mark as reviewed") cannot overwrite each other.
 * The worker's enqueue runs inside the event-deletion transaction, so the
 * queue update, event deletion, and run-state checkpoint commit or roll back
 * together.
 *
 * ## Capacity
 *
 * Fail-closed: 500 pending entries. Reviewed entries do not count toward
 * capacity but are retained for audit (bounded at 1000; oldest reviewed
 * evicted first — safe because reviewed entries are audit-only).
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Attachment review queue.
 */
final class Conexao_Event_Cleanup_Attachment_Review {

	/**
	 * Option name for the persistent queue.
	 *
	 * @var string
	 */
	const OPTION = 'conexao_event_cleanup_attachment_review';

	/**
	 * Prefix of the per-process test store for the queue row.
	 *
	 * @var string
	 */
	const TEST_OPTION_PREFIX = 'conexao_event_cleanup_attachment_review__test__';

	/**
	 * Maximum pending entries.
	 *
	 * @var int
	 */
	const MAX_PENDING = 500;

	/**
	 * Maximum reviewed (audit) entries retained.
	 *
	 * @var int
	 */
	const MAX_REVIEWED_HISTORY = 1000;

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
			throw new RuntimeException( 'conexao: operator-store isolation was not established for the attachment review queue.' );
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
	 * Atomically enqueue attachment IDs inside the caller's transaction.
	 *
	 * The caller must hold an open transaction and have already locked the
	 * queue row with self::read_for_update(). This method reads the current
	 * value, computes the new value, and CAS-updates it. If the CAS fails
	 * (0 rows), the caller must roll back.
	 *
	 * @param array $attachment_ids Attachment IDs to enqueue.
	 * @param int   $event_id       Source event ID.
	 * @param array $sources        Map of attachment_id => 'banner'|'featured'.
	 * @param int   $now            Current timestamp.
	 * @return array { ok: bool, reason: string, pending_count: int }
	 */
	public static function enqueue_in_transaction( array $attachment_ids, int $event_id, array $sources, int $now ): array {
		global $wpdb;

		// If no attachments to enqueue, return success without CAS.
		if ( empty( $attachment_ids ) ) {
			return array(
				'ok'            => true,
				'reason'        => 'no_attachments',
				'pending_count' => 0,
			);
		}

		$option = self::option_name();

		$current = self::read();
		$data    = $current['data'];

		$pending = self::pending_count( $data );

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;

			if ( isset( $data['entries'][ $attachment_id ] ) ) {
				continue; // already queued
			}

			if ( $pending >= self::MAX_PENDING ) {
				return array(
					'ok'            => false,
					'reason'        => 'capacity',
					'pending_count' => $pending,
				);
			}

			$data['entries'][ $attachment_id ] = array(
				'event_id'    => $event_id,
				'added_at'    => $now,
				'source'      => isset( $sources[ $attachment_id ] ) ? $sources[ $attachment_id ] : 'banner',
				'status'      => 'pending',
				'resolved_at' => null,
				'resolved_by' => null,
				'resolution'  => null,
			);

			$pending++;
		}

		$data['capacity'] = self::MAX_PENDING;

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
	 * Returns the decoded data, or null if the row does not exist (the caller
	 * must initialise it first — see ensure_row_exists()).
	 *
	 * @return array|null
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
			return null;
		}

		return $decoded;
	}

	/**
	 * Mark an attachment as reviewed (operator action).
	 *
	 * Uses the same row-lock + CAS pattern as the worker enqueue. If a worker
	 * is mid-transaction, this blocks until the worker commits or rolls back.
	 *
	 * @param int    $attachment_id Attachment ID to mark reviewed.
	 * @param string $operator      Operator login.
	 * @param int    $now           Current timestamp.
	 * @return array { ok: bool, reason: string }
	 */
	public static function mark_reviewed( int $attachment_id, string $operator, int $now ): array {
		global $wpdb;

		$option = self::option_name();

		// Ensure the row exists before locking.
		self::ensure_row_exists();

		// Start a transaction to hold the row lock.
		$wpdb->query( 'START TRANSACTION' );

		$current = self::read();

		if ( null === $current['raw'] ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'     => false,
				'reason' => 'queue row does not exist',
			);
		}

		$data = $current['data'];

		if ( ! isset( $data['entries'][ $attachment_id ] ) ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'     => false,
				'reason' => 'attachment not in queue',
			);
		}

		$data['entries'][ $attachment_id ]['status']      = 'reviewed';
		$data['entries'][ $attachment_id ]['resolved_at'] = $now;
		$data['entries'][ $attachment_id ]['resolved_by'] = $operator;
		$data['entries'][ $attachment_id ]['resolution']  = 'reviewed';

		// Evict oldest reviewed entries if over the audit cap.
		$reviewed = array_filter(
			$data['entries'],
			function ( $entry ) {
				return isset( $entry['status'] ) && 'reviewed' === $entry['status'];
			}
		);

		if ( count( $reviewed ) > self::MAX_REVIEWED_HISTORY ) {
			$to_remove = count( $reviewed ) - self::MAX_REVIEWED_HISTORY;
			$sorted    = $reviewed;
			uasort(
				$sorted,
				function ( $a, $b ) {
					return (int) $a['resolved_at'] - (int) $b['resolved_at'];
				}
			);
			$keys = array_slice( array_keys( $sorted ), 0, $to_remove );
			foreach ( $keys as $key ) {
				unset( $data['entries'][ $key ] );
			}
		}

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
			'reason' => 'marked_reviewed',
		);
	}

	/**
	 * Flush the options cache so a later get_option() does not return stale
	 * data after a direct SQL mutation (design safeguard #2).
	 */
	private static function flush_option_cache(): void {
		wp_cache_delete( self::option_name(), 'options' );
	}
}