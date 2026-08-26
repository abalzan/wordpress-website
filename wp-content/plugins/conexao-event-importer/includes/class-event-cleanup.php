<?php
/**
 * Event cleanup engine.
 *
 * Manually deletes past events and their exclusively-owned images. The cleanup
 * is conservative: it only deletes media that was imported by the event
 * importer (identified by the _event_source_url attachment meta) and that is
 * no longer used by any other event, page, post, or WordPress content.
 *
 * Cleanup is triggered manually from the admin "Run Cleanup Now" button or
 * from WP-CLI. There is no scheduled/cron-based cleanup.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Cleanup {

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
	 * Run the cleanup now (manual trigger only).
	 *
	 * Finds all past events (end date/time already passed), deletes them,
	 * and removes their exclusively-owned images.
	 *
	 * @return array Cleanup result summary.
	 */
	public function run_cleanup() {
		$result = array(
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

			// Events with no end date are not cleaned up by the time-based
			// rule.  They may still be cleaned up if an explicit end date
			// exists; otherwise, skip (manual review territory).
			if ( ! $end_timestamp ) {
				continue;
			}

			if ( $end_timestamp < $now->getTimestamp() ) {
				$past_ids[] = (int) $post_id;
			}
		}

		return $past_ids;
	}

	/**
	 * Delete a single event and its exclusively-owned images.
	 *
	 * @param int   $event_id Event post ID.
	 * @param array $result   Result array (passed by reference for counters).
	 */
	protected function delete_event_with_media( $event_id, &$result ) {
		// Collect the attachment IDs referenced by this event before deletion.
		$attachment_ids = array();

		$banner_attach = get_post_meta( $event_id, self::EVENT_BANNER_META, true );
		if ( $banner_attach && wp_attachment_is_image( $banner_attach ) ) {
			$attachment_ids[] = (int) $banner_attach;
		}

		$thumb_id = get_post_thumbnail_id( $event_id );
		if ( $thumb_id && wp_attachment_is_image( $thumb_id ) && ! in_array( $thumb_id, $attachment_ids, true ) ) {
			$attachment_ids[] = (int) $thumb_id;
		}

		// Delete the event post.
		$deleted = wp_delete_post( $event_id, false );

		if ( ! $deleted ) {
			throw new Exception( __( 'Could not delete the event post.', 'conexao-event-importer' ) );
		}

		$result['events_deleted']++;

		// Clean up exclusively-owned images.
		foreach ( $attachment_ids as $attachment_id ) {
			$result['images_evaluated']++;
			if ( $this->is_exclusively_owned( $attachment_id ) ) {
				wp_delete_attachment( $attachment_id, true );
				$result['images_deleted']++;
			} else {
				$result['images_preserved']++;
			}
		}
	}

	/**
	 * Determine whether an attachment is safe to delete.
	 *
	 * An attachment is exclusively owned when ALL of the following are true:
	 * 1. It was imported by the event importer (_event_source_url meta exists).
	 * 2. It is not the featured image of any other post.
	 * 3. It is not referenced by any other event's banner meta.
	 * 4. It is not referenced in any post/page content.
	 * 5. It is not referenced by any other post meta.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function is_exclusively_owned( $attachment_id ) {
		// 1. Must have been created by the event importer.
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
}