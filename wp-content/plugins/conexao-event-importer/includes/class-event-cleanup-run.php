<?php
/**
 * Event-cleanup batch runner.
 *
 * Wraps the existing Conexao_Event_Cleanup engine in a bounded, resumable
 * batch process with a durable keyset cursor, row-lock fencing, per-record
 * failure handling, and a persistent attachment-review queue.
 *
 * ## Design
 *
 * See docs/evidence/2026-11-20-event-cleanup-scalability/03-design-decision.md
 * (rev. 12, approved). Key points:
 *
 * - Event eligibility and event-post deletion semantics are UNCHANGED.
 * - Automatic attachment deletion is DISABLED; attachments are preserved by
 *   default and recorded in the attachment-review queue.
 * - The cursor is the last processed ID; the next query uses ID < cursor_id.
 * - Each event's delete + queue enqueue + run-state checkpoint commit or roll
 *   back together (one transaction, row lock on the lock option).
 * - The lock uses INSERT IGNORE + CAS + SELECT ... FOR UPDATE fencing.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Batch runner for the event cleanup.
 */
final class Conexao_Event_Cleanup_Run {

	/**
	 * Option key storing the current run state.
	 *
	 * @var string
	 */
	const RUN_OPTION = 'conexao_event_cleanup_run';

	/**
	 * Prefix of the per-process test store for the run row.
	 *
	 * @var string
	 */
	const RUN_TEST_OPTION_PREFIX = 'conexao_event_cleanup_run__test__';

	/**
	 * Default maximum events to delete per batch.
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_EVENTS = 25;

	/**
	 * Default maximum seconds per batch.
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_TIME_SECONDS = 20;

	/**
	 * Default scan window (rows examined per batch).
	 *
	 * @var int
	 */
	const DEFAULT_SCAN_WINDOW = 200;

	/**
	 * Maximum failed events tracked.
	 *
	 * @var int
	 */
	const MAX_FAILED_TRACKED = 500;

	/**
	 * Maximum final failed events tracked.
	 *
	 * @var int
	 */
	const MAX_FINAL_TRACKED = 1000;

	/**
	 * Maximum retry attempts per event.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Run status: in progress.
	 *
	 * @var string
	 */
	const STATUS_RUNNING = 'running';

	/**
	 * Run status: completed.
	 *
	 * @var string
	 */
	const STATUS_COMPLETED = 'completed';

	/**
	 * Run status: completed with errors.
	 *
	 * @var string
	 */
	const STATUS_COMPLETED_WITH_ERRORS = 'completed_with_errors';

	/**
	 * Run status: cancelled.
	 *
	 * @var string
	 */
	const STATUS_CANCELLED = 'cancelled';

	/**
	 * Run status: failed.
	 *
	 * @var string
	 */
	const STATUS_FAILED = 'failed';

	/**
	 * Run status: paused (capacity).
	 *
	 * @var string
	 */
	const STATUS_PAUSED_CAPACITY = 'paused_capacity';

	/**
	 * Run status: paused (attachment review queue full).
	 *
	 * @var string
	 */
	const STATUS_PAUSED_CAPACITY_REVIEW = 'paused_capacity_review';

	/**
	 * Resolve the run-state option name (production vs per-process test store).
	 *
	 * @throws RuntimeException When the harness is loaded without a valid suffix.
	 * @return string Option name.
	 */
	public static function run_option(): string {
		if ( ! defined( 'CONEXAO_TESTS_ROOT' ) && ! defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
			return self::RUN_OPTION;
		}

		$suffix = '';

		if ( defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
			$suffix = strtolower( (string) CONEXAO_TEST_OPTION_SUFFIX );
		}

		if ( ! preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', $suffix ) ) {
			throw new RuntimeException( 'conexao: operator-store isolation was not established for the cleanup run store.' );
		}

		return self::RUN_TEST_OPTION_PREFIX . $suffix;
	}

	/**
	 * The cleanup engine (for eligibility + deletion).
	 *
	 * @var Conexao_Event_Cleanup
	 */
	private $engine;

	/**
	 * Constructor.
	 *
	 * @param Conexao_Event_Cleanup $engine Cleanup engine.
	 */
	public function __construct( Conexao_Event_Cleanup $engine ) {
		$this->engine = $engine;
	}

	/**
	 * Start a new run (or resume an existing one).
	 *
	 * @param array $args {
	 *     @type int $batch_events       Max events per batch.
	 *     @type int $batch_time_seconds Max seconds per batch.
	 *     @type int $scan_window        Rows examined per batch.
	 * }
	 * @return array { ok: bool, run_id: string, status: string, reason: string }
	 */
	public function start( array $args = array() ): array {
		global $wpdb;

		$batch_events       = isset( $args['batch_events'] ) ? (int) $args['batch_events'] : self::DEFAULT_BATCH_EVENTS;
		$batch_time_seconds = isset( $args['batch_time_seconds'] ) ? (int) $args['batch_time_seconds'] : self::DEFAULT_BATCH_TIME_SECONDS;
		$scan_window        = isset( $args['scan_window'] ) ? (int) $args['scan_window'] : self::DEFAULT_SCAN_WINDOW;

		// Ensure the lock row and queue row exist before the destructive transaction.
		Conexao_Event_Cleanup_Lock::ensure_row_exists();
		Conexao_Event_Cleanup_Attachment_Review::ensure_row_exists();

		// Check for an existing run-state (resume).
		$run_state = $this->read_run_state();

		if ( ! empty( $run_state ) && isset( $run_state['run_id'] ) ) {
			// Resume: verify the lock is still ours.
			$lock = Conexao_Event_Cleanup_Lock::acquire(
				array(
					'run_id' => $run_state['run_id'],
					'ttl'    => max( 60, 2 * $batch_time_seconds ),
				)
			);

			if ( ! $lock['held'] ) {
				// Lock was reclaimed by another worker; mark the run as failed.
				$run_state['status'] = self::STATUS_FAILED;
				$run_state['updated'] = time();
				$this->write_run_state( $run_state );

				return array(
					'ok'      => false,
					'run_id'  => $run_state['run_id'],
					'status'  => self::STATUS_FAILED,
					'reason'  => $lock['reason'],
					'outcome' => $lock['outcome'],
				);
			}

			// Update owner + fence.
			$run_state['owner_lock_id']    = $run_state['run_id'];
			$run_state['fence_token']      = $lock['record']['fence_token'];
			$run_state['lease_expires_at'] = time() + max( 60, 2 * $batch_time_seconds );
			$run_state['status']           = self::STATUS_RUNNING;
			$run_state['updated']          = time();
			$this->write_run_state( $run_state );

			// Process one batch.
			$batch_result = $this->process_batch( $batch_events, $batch_time_seconds, $scan_window );

			return array(
				'ok'           => true,
				'run_id'       => $run_state['run_id'],
				'status'       => $batch_result['status'],
				'reason'       => $batch_result['reason'],
				'batch_result' => $batch_result,
			);
		}

		// No existing run: start a new one.
		$run_id = 'cleanup_' . wp_generate_password( 12, false, false );
		$lock   = Conexao_Event_Cleanup_Lock::acquire(
			array(
				'run_id' => $run_id,
				'ttl'    => max( 60, 2 * $batch_time_seconds ),
			)
		);

		if ( ! $lock['held'] ) {
			return array(
				'ok'      => false,
				'run_id'  => '',
				'status'  => '',
				'reason'  => $lock['reason'],
				'outcome' => $lock['outcome'],
			);
		}

		// Initialise run-state.
		$run_state = array(
			'run_id'         => $run_id,
			'status'         => self::STATUS_RUNNING,
			'created'        => time(),
			'updated'        => time(),
			'cursor_id'      => PHP_INT_MAX,
			'last_scanned_id' => 0,
			'events_deleted' => 0,
			'images_deleted' => 0,
			'images_preserved' => 0,
			'errors'         => array(),
			'failed_events'  => array(),
			'failed_events_final' => array(),
			'batch_count'    => 0,
			'last_batch_events_deleted' => 0,
			'last_batch_time_seconds'   => 0.0,
			'owner_lock_id'  => $run_id,
			'fence_token'    => $lock['record']['fence_token'],
			'lease_expires_at' => time() + max( 60, 2 * $batch_time_seconds ),
		);

		$this->write_run_state( $run_state );

		// Process one batch.
		$batch_result = $this->process_batch( $batch_events, $batch_time_seconds, $scan_window );

		return array(
			'ok'           => true,
			'run_id'       => $run_state['run_id'],
			'status'       => $batch_result['status'],
			'reason'       => $batch_result['reason'],
			'batch_result' => $batch_result,
		);
	}

	/**
	 * Process one batch.
	 *
	 * @param int $batch_events       Max events to delete.
	 * @param int $batch_time_seconds Max seconds.
	 * @param int $scan_window        Rows examined.
	 * @return array { status: string, reason: string, events_deleted: int, ... }
	 */
	public function process_batch( int $batch_events, int $batch_time_seconds, int $scan_window ): array {
		global $wpdb;

		$run_state = $this->read_run_state();

		if ( empty( $run_state ) ) {
			return array(
				'status' => self::STATUS_FAILED,
				'reason' => 'run state not found',
			);
		}

		// Check for cancellation.
		if ( self::STATUS_CANCELLED === $run_state['status'] ) {
			return array(
				'status' => self::STATUS_CANCELLED,
				'reason' => 'run cancelled',
			);
		}

		$batch_start   = microtime( true );
		$events_deleted = 0;
		$last_deleted_id = 0;
		$last_committed_id = 0;
		$last_scanned_id = 0;
		$stop_reason = '';

		// Fetch the scan window (keyset, read-only).
		$candidate_ids = $this->fetch_candidate_ids( (int) $run_state['cursor_id'], $scan_window );

		if ( empty( $candidate_ids ) ) {
			// No more candidates; the run is complete.
			$run_state['status'] = ( empty( $run_state['failed_events_final'] ) )
				? self::STATUS_COMPLETED
				: self::STATUS_COMPLETED_WITH_ERRORS;
			$run_state['updated'] = time();
			$this->write_run_state( $run_state );

			return array(
				'status' => $run_state['status'],
				'reason' => 'no more candidates',
			);
		}

		$last_scanned_id = (int) end( $candidate_ids );

		foreach ( $candidate_ids as $candidate_id ) {
			$candidate_id = (int) $candidate_id;

			// Check time budget.
			if ( ( microtime( true ) - $batch_start ) >= $batch_time_seconds ) {
				$stop_reason = 'time_budget';
				break;
			}

			// Check event-count budget.
			if ( $events_deleted >= $batch_events ) {
				$stop_reason = 'event_count';
				break;
			}

			// Apply the unchanged eligibility predicate.
			if ( ! $this->is_eligible( $candidate_id ) ) {
				// Ineligible: skip, cursor advances past it.
				$last_committed_id = $candidate_id;
				continue;
			}

			// Already gone (idempotent no-op).
			if ( null === get_post( $candidate_id ) ) {
				$last_committed_id = $candidate_id;
				continue;
			}

			// Check failed_events_final: skip if exhausted retries.
			if ( isset( $run_state['failed_events_final'][ $candidate_id ] ) ) {
				// Record "skipped: exhausted retries" and move on.
				$last_committed_id = $candidate_id;
				continue;
			}

			// Attempt deletion inside a transaction.
			$delete_result = $this->delete_event_transactional( $candidate_id, $run_state );

			if ( $delete_result['ok'] ) {
				$events_deleted++;
				$last_deleted_id     = $candidate_id;
				$last_committed_id   = $candidate_id;
				$run_state['events_deleted']++;
				$run_state['images_preserved'] += $delete_result['images_preserved'];
			} elseif ( 'queue_capacity' === $delete_result['reason'] ) {
				// The attachment-review queue is full. The event was NOT
				// deleted (the transaction rolled back). Pause the run so
				// the operator can resolve pending reviews; the cursor
				// does NOT advance past this event, so it is retried on
				// resume. No failure is recorded — this is a capacity
				// pause, not an event failure.
				$stop_reason = 'capacity_review';
				break;
			} else {
				// Failed: record, increment attempts, cursor advances.
				$this->record_failure( $run_state, $candidate_id, $delete_result['reason'] );
				$last_committed_id = $candidate_id;

				// Check capacity.
				if ( count( $run_state['failed_events'] ) >= self::MAX_FAILED_TRACKED ) {
					$stop_reason = 'failed_capacity';
					break;
				}
			}
		}

		// Determine the cursor value per the approved boundary semantics.
		if ( 'event_count' === $stop_reason ) {
			$run_state['cursor_id'] = $last_deleted_id;
		} elseif ( 'time_budget' === $stop_reason ) {
			$run_state['cursor_id'] = $last_committed_id;
		} elseif ( 'failed_capacity' === $stop_reason ) {
			$run_state['cursor_id'] = $last_committed_id;
		} elseif ( 'capacity_review' === $stop_reason ) {
			// The queue-full event was NOT deleted and its transaction
			// rolled back. The cursor must stay ABOVE it so the next
			// query re-fetches it. last_committed_id is the last
			// successfully committed event (not the current one).
			$run_state['cursor_id'] = $last_committed_id;
		} else {
			// Whole window dispositioned.
			$run_state['cursor_id'] = $last_scanned_id;
		}

		$run_state['last_scanned_id'] = $last_scanned_id;
		$run_state['batch_count']++;
		$run_state['last_batch_events_deleted'] = $events_deleted;
		$run_state['last_batch_time_seconds']   = microtime( true ) - $batch_start;
		$run_state['updated']                   = time();

		// Determine status.
		if ( 'failed_capacity' === $stop_reason ) {
			$run_state['status'] = self::STATUS_PAUSED_CAPACITY;
		} elseif ( 'capacity_review' === $stop_reason ) {
			$run_state['status'] = self::STATUS_PAUSED_CAPACITY_REVIEW;
		} else {
			// If we stopped early (event_count or time_budget), the run is still running.
			// If we processed the whole window and there are no more candidates, it's complete.
			$has_more = ( 'event_count' === $stop_reason || 'time_budget' === $stop_reason );

			if ( $has_more ) {
				$run_state['status'] = self::STATUS_RUNNING;
			} else {
				$run_state['status'] = ( empty( $run_state['failed_events_final'] ) )
					? self::STATUS_COMPLETED
					: self::STATUS_COMPLETED_WITH_ERRORS;
			}
		}

		$this->write_run_state( $run_state );

		// Release the lock if the run is terminal.
		if ( in_array( $run_state['status'], array( self::STATUS_COMPLETED, self::STATUS_COMPLETED_WITH_ERRORS, self::STATUS_CANCELLED, self::STATUS_FAILED ), true ) ) {
			Conexao_Event_Cleanup_Lock::release( $run_state['run_id'] );
		}

		return array(
			'status'         => $run_state['status'],
			'reason'         => $stop_reason,
			'events_deleted' => $events_deleted,
			'last_deleted_id' => $last_deleted_id,
			'last_committed_id' => $last_committed_id,
			'last_scanned_id' => $last_scanned_id,
			'cursor_id'      => $run_state['cursor_id'],
		);
	}

	/**
	 * Fetch candidate IDs (keyset, read-only).
	 *
	 * @param int $cursor_id    Exclusive upper bound.
	 * @param int $scan_window  Max rows.
	 * @return int[] Candidate IDs in ID DESC order.
	 */
	private function fetch_candidate_ids( int $cursor_id, int $scan_window ): array {
		global $wpdb;

		// Build the meta_query for _event_date EXISTS != ''.
		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => '_event_date',
				'compare' => 'EXISTS',
			),
			array(
				'key'     => '_event_date',
				'value'   => '',
				'compare' => '!=',
			),
		);

		if ( class_exists( 'Conexao_Import_Settings' ) && 'imported_only' === Conexao_Import_Settings::get( 'cleanup_scope', 'all' ) ) {
			$meta_query[] = array(
				'key'   => '_event_imported',
				'value' => '1',
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'event',
				'post_status'            => 'any',
				'posts_per_page'         => $scan_window,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'lang'                   => '',
				'suppress_filters'       => true,
				'meta_query'             => $meta_query,
				'orderby'                => array( 'ID' => 'DESC' ),
				'post__not_in'           => array(),
			)
		);

		// Apply the keyset cursor manually (WP_Query does not support ID < cursor directly).
		$ids = array();
		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id < $cursor_id ) {
				$ids[] = $post_id;
			}
		}

		return $ids;
	}

	/**
	 * Apply the unchanged eligibility predicate for a single event.
	 *
	 * This mirrors the logic in Conexao_Event_Cleanup::find_past_events()
	 * but for a single event ID, avoiding the O(n) cost of calling
	 * find_past_events() per candidate.
	 *
	 * @param int $event_id Event ID.
	 * @return bool
	 */
	private function is_eligible( int $event_id ): bool {
		// Use the shared end-timestamp helper (same as find_past_events).
		$end_timestamp = Conexao_Event_Status::get_event_end_timestamp( $event_id );

		if ( ! $end_timestamp ) {
			return false;
		}

		$now = current_datetime();
		$now_ts = $now->getTimestamp();

		// Weekly recurrence: an open-ended series, or one whose
		// _event_recurrence_end is still in the future, keeps occurring and
		// is therefore not "past" even when its first occurrence
		// (_event_date) is long behind us.
		if ( 'weekly' === get_post_meta( $event_id, '_event_recurrence', true ) ) {
			$recurrence_end = get_post_meta( $event_id, '_event_recurrence_end', true );
			if ( ! is_string( $recurrence_end ) || '' === $recurrence_end ) {
				return false; // open-ended weekly series: never ends
			}
			$today_str = $now->format( 'Y-m-d' );
			if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $recurrence_end )
				&& checkdate( (int) substr( $recurrence_end, 5, 2 ), (int) substr( $recurrence_end, 8, 2 ), (int) substr( $recurrence_end, 0, 4 ) )
				&& $recurrence_end >= $today_str ) {
				return false; // series still has future occurrences
			}
		}

		// Ownership scope: when cleanup_scope = imported_only, a manual event
		// (no _event_imported = 1) is NOT eligible (design §2.1 rule 5).
		if ( class_exists( 'Conexao_Import_Settings' )
			&& 'imported_only' === Conexao_Import_Settings::get( 'cleanup_scope', 'all' )
			&& '1' !== (string) get_post_meta( $event_id, '_event_imported', true ) ) {
			return false;
		}

		return $end_timestamp < $now_ts;
	}

	/**
	 * Delete an event inside a transaction (fencing + queue enqueue + run-state checkpoint).
	 *
	 * @param int   $event_id  Event ID.
	 * @param array $run_state Run state (by reference).
	 * @return array { ok: bool, reason: string, images_preserved: int }
	 */
	private function delete_event_transactional( int $event_id, array &$run_state ): array {
		global $wpdb;

		$option = Conexao_Event_Cleanup_Attachment_Review::option_name();

		// Start the transaction.
		$wpdb->query( 'START TRANSACTION' );

		// Step 1: Lock the lock row and verify fence.
		$lock_raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
				Conexao_Event_Cleanup_Lock::OPTION
			)
		);

		if ( null === $lock_raw ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'               => false,
				'reason'           => 'lock_row_missing',
				'images_preserved' => 0,
			);
		}

		$lock_record = Conexao_Event_Cleanup_Lock::decode( $lock_raw );

		if ( null === $lock_record ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'               => false,
				'reason'           => 'lock_malformed',
				'images_preserved' => 0,
			);
		}

		if ( $lock_record['run_id'] !== $run_state['run_id'] || $lock_record['fence_token'] !== $run_state['fence_token'] ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'               => false,
				'reason'           => 'fence_check_failed',
				'images_preserved' => 0,
			);
		}

		// Step 2: Renew the lock's lease (CAS on the lock row, design §4.3
		// Step 3). The UPDATE is scoped to the exact bytes read under
		// FOR UPDATE above, so a concurrent reclaim that changed the row
		// makes this affect 0 rows and we fail closed. On success the lease
		// is extended to now + ttl with the SAME fence token, so this worker
		// still owns the lock at COMMIT.
		$renewed = Conexao_Event_Cleanup_Lock::renew_in_transaction( $lock_raw, time() );

		if ( ! $renewed ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'               => false,
				'reason'           => 'lock_renew_failed',
				'images_preserved' => 0,
			);
		}

		// Step 3: Enqueue attachments into the review queue (inside the transaction).
		$attachment_ids = $this->get_event_attachment_ids( $event_id );
		$sources        = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$sources[ $attachment_id ] = 'banner'; // simplified; could be 'featured'
		}

		$enqueue_result = Conexao_Event_Cleanup_Attachment_Review::enqueue_in_transaction(
			$attachment_ids,
			$event_id,
			$sources,
			time()
		);

		if ( ! $enqueue_result['ok'] ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'               => false,
				'reason'           => 'queue_' . $enqueue_result['reason'],
				'images_preserved' => 0,
			);
		}

		// Step 4: Delete the event post.
		$deleted = wp_delete_post( $event_id, true );

		// Verify the row is gone.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $event_id ) );

		if ( ! $deleted || null !== $row ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'               => false,
				'reason'           => 'delete_failed',
				'images_preserved' => 0,
			);
		}

		// Step 5: Update run-state (cursor, counts).
		$run_state['updated'] = time();

		// Commit.
		$wpdb->query( 'COMMIT' );

		return array(
			'ok'               => true,
			'reason'           => 'deleted',
			'images_preserved' => count( $attachment_ids ),
		);
	}

	/**
	 * Get the attachment IDs for an event (banner + featured).
	 *
	 * @param int $event_id Event ID.
	 * @return int[]
	 */
	private function get_event_attachment_ids( int $event_id ): array {
		$attachment_ids = array();

		$banner_attach = get_post_meta( $event_id, Conexao_Event_Cleanup::EVENT_BANNER_META, true );
		if ( $banner_attach && wp_attachment_is_image( $banner_attach ) ) {
			$attachment_ids[] = (int) $banner_attach;
		}

		$thumb_id = get_post_thumbnail_id( $event_id );
		if ( $thumb_id && wp_attachment_is_image( $thumb_id ) && ! in_array( $thumb_id, $attachment_ids, true ) ) {
			$attachment_ids[] = (int) $thumb_id;
		}

		return $attachment_ids;
	}

	/**
	 * Record a failure for an event.
	 *
	 * @param array $run_state Run state (by reference).
	 * @param int   $event_id  Event ID.
	 * @param string $reason   Failure reason.
	 */
	private function record_failure( array &$run_state, int $event_id, string $reason ): void {
		if ( ! isset( $run_state['failed_events'][ $event_id ] ) ) {
			$run_state['failed_events'][ $event_id ] = array(
				'attempts'        => 0,
				'last_reason'     => '',
				'last_attempt_at' => 0,
				'first_failed_at' => time(),
			);
		}

		$run_state['failed_events'][ $event_id ]['attempts']++;
		$run_state['failed_events'][ $event_id ]['last_reason']     = $reason;
		$run_state['failed_events'][ $event_id ]['last_attempt_at'] = time();

		if ( $run_state['failed_events'][ $event_id ]['attempts'] >= self::MAX_ATTEMPTS ) {
			// Fail-closed capacity: never silently discard a final failure
			// record. If the final history is full, the event stays in
			// failed_events (still actionable) and the run pauses at the
			// failed_events capacity gate on the next iteration. The
			// operator must resolve/export or raise the cap (approval
			// required) — the record is never dropped.
			if ( count( $run_state['failed_events_final'] ) >= self::MAX_FINAL_TRACKED ) {
				$run_state['failed_events'][ $event_id ]['last_reason'] =
					$reason . ' (final history full; event retained in failed_events for operator resolution)';
				return;
			}

			$run_state['failed_events_final'][ $event_id ] = $run_state['failed_events'][ $event_id ];
			unset( $run_state['failed_events'][ $event_id ] );
		}
	}

	/**
	 * Read the run-state.
	 *
	 * @return array
	 */
	public function read_run_state(): array {
		$option = self::run_option();
		$state  = get_option( $option, array() );

		return is_array( $state ) ? $state : array();
	}

	/**
	 * Write the run-state.
	 *
	 * @param array $state Run state.
	 */
	public function write_run_state( array $state ): void {
		$option = self::run_option();
		update_option( $option, $state, false );
	}

	/**
	 * Cancel the current run.
	 *
	 * @return array { ok: bool, reason: string }
	 */
	public function cancel(): array {
		$run_state = $this->read_run_state();

		if ( empty( $run_state ) ) {
			return array(
				'ok'     => false,
				'reason' => 'no run in progress',
			);
		}

		$run_state['status']  = self::STATUS_CANCELLED;
		$run_state['updated'] = time();
		$this->write_run_state( $run_state );

		Conexao_Event_Cleanup_Lock::release( $run_state['run_id'] );

		return array(
			'ok'     => true,
			'reason' => 'cancelled',
		);
	}
}