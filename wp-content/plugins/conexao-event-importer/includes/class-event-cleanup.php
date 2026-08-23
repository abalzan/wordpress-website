<?php
/**
 * Event cleanup engine.
 *
 * Automatically identifies and deletes past events once per week, including
 * the images/media that belong exclusively to those events. The cleanup is
 * conservative: it only deletes media that was imported by the event importer
 * (identified by the _event_source_url attachment meta) and that is no longer
 * used by any other event, page, post, or WordPress content.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Cleanup {

	/**
	 * Cron hook for the weekly cleanup.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'conexao_event_cleanup_cron';

	/**
	 * Option key storing the last cleanup run summary.
	 *
	 * @var string
	 */
	const STATUS_OPTION = 'conexao_event_cleanup_status';

	/**
	 * Option key storing the cleanup run history.
	 *
	 * @var string
	 */
	const HISTORY_OPTION = 'conexao_event_cleanup_history';

	/**
	 * Meta key on attachments recording the original external source URL.
	 *
	 * This is the reliable relationship identifier used to determine which
	 * media was created by the event importer.
	 *
	 * @var string
	 */
	const ATTACHMENT_SOURCE_META = '_event_source_url';

	/**
	 * Meta key on events storing the banner attachment ID.
	 *
	 * @var string
	 */
	const EVENT_BANNER_META = '_event_banner_attachment_id';

	/**
	 * Maximum number of events to process per run (safety limit).
	 *
	 * @var int
	 */
	const MAX_EVENTS_PER_RUN = 200;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_cleanup' ) );
		add_filter( 'cron_schedules', array( $this, 'add_recurrences' ) );
	}

	/**
	 * Register the weekly recurrence schedule.
	 *
	 * @param array $schedules WP cron schedules.
	 * @return array
	 */
	public function add_recurrences( $schedules ) {
		$schedules['conexao_cleanup_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly (Cleanup)', 'conexao-event-importer' ),
		);
		return $schedules;
	}

	/**
	 * Ensure the cleanup cron is scheduled (idempotent).
	 */
	public function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$this->schedule();
		}
	}

	/**
	 * Schedule the weekly cleanup for next Monday at 03:30 Europe/Dublin.
	 *
	 * Uses a different time than the import (03:00) so the two jobs do not
	 * collide. The schedule is idempotent — it never registers twice.
	 */
	public function schedule() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		add_filter( 'cron_schedules', array( $this, 'add_recurrences' ) );

		$timezone = new DateTimeZone( 'Europe/Dublin' );
		$now      = new DateTime( 'now', $timezone );
		$target   = clone $now;
		$target->modify( 'next monday 03:30' );

		wp_schedule_event( $target->getTimestamp(), 'conexao_cleanup_weekly', self::CRON_HOOK );
	}

	/**
	 * Clear the scheduled cleanup.
	 */
	public static function clear_schedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Run the scheduled cleanup.
	 */
	public function run_scheduled_cleanup() {
		$this->run_cleanup( 'scheduled' );
	}

	/**
	 * Run the cleanup now (manual or scheduled).
	 *
	 * This is the single entry point used by both the scheduled cron and the
	 * admin "Run Cleanup Now" button, so the logic is always identical.
	 *
	 * @param string $trigger 'scheduled' or 'manual'.
	 * @return array Cleanup result summary.
	 */
	public function run_cleanup( $trigger = 'manual' ) {
		$result = array(
			'trigger'          => $trigger,
			'time'             => current_time( 'mysql' ),
			'events_found'     => 0,
			'events_deleted'   => 0,
			'images_evaluated' => 0,
			'images_deleted'   => 0,
			'images_preserved' => 0,
			'errors'           => array(),
		);

		// Find past events.
		$past_event_ids = $this->find_past_events();
		$result['events_found'] = count( $past_event_ids );

		// Process each past event.
		foreach ( $past_event_ids as $event_id ) {
			try {
				$this->delete_event_with_media( $event_id, $result );
			} catch ( Exception $e ) {
				$result['errors'][] = sprintf(
					/* translators: %1$d: Event ID, %2$s: Error message. */
					__( 'Event #%1$d: %2$s', 'conexao-event-importer' ),
					$event_id,
					$e->getMessage()
				);
			}
		}

		// Persist the status and history.
		$this->save_status( $result );
		$this->save_history( $result );

		// Log the run.
		if ( class_exists( 'Conexao_Import_Log' ) ) {
			Conexao_Import_Log::add(
				'cleanup',
				empty( $result['errors'] ) ? 'info' : 'warning',
				sprintf(
					/* translators: %1$d: Events found, %2$d: Events deleted, %3$d: Images deleted, %4$d: Images preserved. */
					__( 'Cleanup run: %1$d events found, %2$d deleted, %3$d images deleted, %4$d preserved.', 'conexao-event-importer' ),
					$result['events_found'],
					$result['events_deleted'],
					$result['images_deleted'],
					$result['images_preserved']
				),
				array(
					'trigger' => $trigger,
					'errors'  => count( $result['errors'] ),
				)
			);
		}

		return $result;
	}

	/**
	 * Find all events whose end date/time has already passed.
	 *
	 * Uses the shared end-timestamp helper from Conexao_Event_Status so the
	 * expiry check and the cleanup always agree on "has this event ended?".
	 *
	 * When the cleanup_scope setting is 'imported_only', manually created
	 * events (no _event_imported meta) are protected from deletion.
	 *
	 * @return int[] Event post IDs.
	 */
	protected function find_past_events() {
		$now = current_datetime();

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

		// Optional scope restriction: only delete importer-created events.
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
				'posts_per_page'         => self::MAX_EVENTS_PER_RUN,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => $meta_query,
			)
		);

		$past_ids = array();

		foreach ( $query->posts as $post_id ) {
			$end_timestamp = Conexao_Event_Status::get_event_end_timestamp( $post_id );

			if ( $end_timestamp && $end_timestamp < $now->getTimestamp() ) {
				$past_ids[] = (int) $post_id;
			}
		}

		return $past_ids;
	}

	/**
	 * Delete a past event and its exclusively-owned media.
	 *
	 * Collects the attachment IDs associated with the event, deletes the event
	 * post, then evaluates each attachment for safe deletion.
	 *
	 * @param int   $event_id Event post ID.
	 * @param array $result   Result array (modified by reference).
	 */
	protected function delete_event_with_media( $event_id, &$result ) {
		// Collect attachment IDs associated with this event.
		$attachment_ids = $this->get_event_attachment_ids( $event_id );

		// Delete the event post (this also removes its featured image reference).
		$deleted = wp_delete_post( $event_id, true );

		if ( ! $deleted ) {
			$result['errors'][] = sprintf(
				/* translators: %d: Event ID. */
				__( 'Failed to delete event #%d.', 'conexao-event-importer' ),
				$event_id
			);
			return;
		}

		$result['events_deleted']++;

		// Evaluate each attachment for safe deletion.
		foreach ( $attachment_ids as $attachment_id ) {
			$result['images_evaluated']++;

			if ( $this->is_attachment_safe_to_delete( $attachment_id ) ) {
				$deleted_attachment = wp_delete_attachment( $attachment_id, true );
				if ( $deleted_attachment ) {
					$result['images_deleted']++;
				} else {
					$result['images_preserved']++;
					$result['errors'][] = sprintf(
						/* translators: %d: Attachment ID. */
						__( 'Failed to delete attachment #%d.', 'conexao-event-importer' ),
						$attachment_id
					);
				}
			} else {
				$result['images_preserved']++;
			}
		}
	}

	/**
	 * Get all attachment IDs associated with an event.
	 *
	 * Checks the _event_banner_attachment_id meta and the post thumbnail.
	 * Deduplicates the result.
	 *
	 * @param int $event_id Event post ID.
	 * @return int[] Attachment IDs.
	 */
	protected function get_event_attachment_ids( $event_id ) {
		$ids = array();

		$banner_id = get_post_meta( $event_id, self::EVENT_BANNER_META, true );
		if ( $banner_id && wp_attachment_is_image( $banner_id ) ) {
			$ids[] = (int) $banner_id;
		}

		$thumbnail_id = get_post_thumbnail_id( $event_id );
		if ( $thumbnail_id && wp_attachment_is_image( $thumbnail_id ) ) {
			$ids[] = (int) $thumbnail_id;
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Determine whether an attachment is safe to delete.
	 *
	 * An attachment is safe to delete ONLY when ALL of the following are true:
	 *  1. It was created by the event importer (has _event_source_url meta).
	 *  2. It is not the featured image of any other post.
	 *  3. It is not referenced by any other event's _event_banner_attachment_id.
	 *  4. It is not referenced in any post/page content.
	 *  5. It is not referenced by any other post meta across the site.
	 *
	 * If there is any uncertainty, the attachment is preserved.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True when safe to delete.
	 */
	protected function is_attachment_safe_to_delete( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		// 1. Must be importer-created (has the source URL meta).
		$source_url = get_post_meta( $attachment_id, self::ATTACHMENT_SOURCE_META, true );
		if ( empty( $source_url ) ) {
			return false;
		}

		// 2. Must not be the featured image of any other post.
		if ( $this->is_featured_image_elsewhere( $attachment_id ) ) {
			return false;
		}

		// 3. Must not be referenced by any other event's banner meta.
		if ( $this->is_event_banner_elsewhere( $attachment_id ) ) {
			return false;
		}

		// 4. Must not be referenced in any post/page content.
		if ( $this->is_referenced_in_content( $attachment_id ) ) {
			return false;
		}

		// 5. Must not be referenced by any other post meta.
		if ( $this->is_referenced_in_meta( $attachment_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether an attachment is the featured image of any other post.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function is_featured_image_elsewhere( $attachment_id ) {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id != %d AND meta_key = '_thumbnail_id' AND meta_value = %d",
				$attachment_id,
				$attachment_id
			)
		);

		return $count > 0;
	}

	/**
	 * Check whether an attachment is referenced by any other event's banner meta.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function is_event_banner_elsewhere( $attachment_id ) {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id != %d AND meta_key = %s AND meta_value = %d",
				$attachment_id,
				self::EVENT_BANNER_META,
				$attachment_id
			)
		);

		return $count > 0;
	}

	/**
	 * Check whether an attachment is referenced in any post/page content.
	 *
	 * Searches post_content for the attachment's URL. This catches images
	 * embedded in posts, pages, or other content types.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function is_referenced_in_content( $attachment_id ) {
		global $wpdb;

		$attachment_url = wp_get_attachment_url( $attachment_id );
		if ( ! $attachment_url ) {
			return false;
		}

		// Also check the file path (for relative references).
		$file = get_attached_file( $attachment_id );
		$file_path = $file ? wp_normalize_path( $file ) : '';

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type != 'attachment' AND post_status IN ('publish', 'draft', 'pending', 'private', 'future') AND post_content LIKE %s",
				'%' . $wpdb->esc_like( $attachment_url ) . '%'
			)
		);

		if ( $count > 0 ) {
			return true;
		}

		// Check for the file path reference as well.
		if ( $file_path ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type != 'attachment' AND post_status IN ('publish', 'draft', 'pending', 'private', 'future') AND post_content LIKE %s",
					'%' . $wpdb->esc_like( $file_path ) . '%'
				)
			);

			if ( $count > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an attachment is referenced by any other post meta.
	 *
	 * Searches all postmeta for the attachment ID or its URL. This catches
	 * custom fields, page builders, and other plugins that store image
	 * references in meta.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function is_referenced_in_meta( $attachment_id ) {
		global $wpdb;

		$attachment_url = wp_get_attachment_url( $attachment_id );
		$file           = get_attached_file( $attachment_id );
		$file_path      = $file ? wp_normalize_path( $file ) : '';

		// Check for the attachment ID in any meta value (excluding our own
		// source URL meta, the event banner meta, and the attachment's own
		// postmeta which contains _wp_attachment_metadata).
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id != %d AND meta_key NOT IN (%s, %s) AND meta_value = %s",
				$attachment_id,
				self::ATTACHMENT_SOURCE_META,
				self::EVENT_BANNER_META,
				(string) $attachment_id
			)
		);

		if ( $count > 0 ) {
			return true;
		}

		// Check for the attachment URL in any meta value.
		if ( $attachment_url ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id != %d AND meta_key NOT IN (%s, %s) AND meta_value LIKE %s",
					$attachment_id,
					self::ATTACHMENT_SOURCE_META,
					self::EVENT_BANNER_META,
					'%' . $wpdb->esc_like( $attachment_url ) . '%'
				)
			);

			if ( $count > 0 ) {
				return true;
			}
		}

		// Check for the file path in any meta value.
		if ( $file_path ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id != %d AND meta_key NOT IN (%s, %s) AND meta_value LIKE %s",
					$attachment_id,
					self::ATTACHMENT_SOURCE_META,
					self::EVENT_BANNER_META,
					'%' . $wpdb->esc_like( $file_path ) . '%'
				)
			);

			if ( $count > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Save the cleanup status for the admin interface.
	 *
	 * @param array $result Cleanup result.
	 */
	protected function save_status( $result ) {
		update_option( self::STATUS_OPTION, $result, false );
	}

	/**
	 * Get the last cleanup status.
	 *
	 * @return array
	 */
	public function get_status() {
		$status = get_option( self::STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	/**
	 * Save a cleanup run to the history log.
	 *
	 * @param array $result Cleanup result.
	 */
	protected function save_history( $result ) {
		$history = $this->get_history();

		// Store only non-sensitive summary data.
		$entry = array(
			'time'             => $result['time'],
			'trigger'          => $result['trigger'],
			'events_found'     => $result['events_found'],
			'events_deleted'   => $result['events_deleted'],
			'images_evaluated' => $result['images_evaluated'],
			'images_deleted'   => $result['images_deleted'],
			'images_preserved' => $result['images_preserved'],
			'error_count'      => count( $result['errors'] ),
		);

		array_unshift( $history, $entry );
		// Keep the last 50 runs.
		$history = array_slice( $history, 0, 50 );

		update_option( self::HISTORY_OPTION, $history, false );
	}

	/**
	 * Get the cleanup run history (newest first).
	 *
	 * @return array
	 */
	public function get_history() {
		$history = get_option( self::HISTORY_OPTION, array() );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Get the next scheduled cleanup timestamp.
	 *
	 * @return int|false Unix timestamp or false when not scheduled.
	 */
	public function get_next_scheduled() {
		return wp_next_scheduled( self::CRON_HOOK );
	}
}