<?php
/**
 * Nightly image-sync sweeper.
 *
 * Automatically localizes external event banner images into the Media
 * Library. New imports already sideload images inline; this sweeper catches
 * anything left over (legacy events, images that failed transiently) so no
 * manual "Sync Event Images" clicks are needed.
 *
 * Processes a small batch per night (configurable, default 25) to stay well
 * within execution limits; large backlogs drain over successive nights.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Image_Sync_Scheduler {

	/** Cron hook for the nightly sweeper. */
	const CRON_HOOK = 'conexao_event_image_sync_cron';

	/** @var Conexao_Event_Image_Handler */
	protected $image_handler;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->image_handler = new Conexao_Event_Image_Handler();

		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_sync' ) );
	}

	/**
	 * Ensure the sweeper cron is scheduled (idempotent).
	 */
	public function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$this->schedule();
		}
	}

	/**
	 * Schedule the sweeper for the next 04:00 Europe/Dublin (daily).
	 *
	 * Runs after the import kickoff window (03:00) so freshly imported events
	 * are not immediately re-processed, and before business hours.
	 */
	public function schedule() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		$timezone = new DateTimeZone( 'Europe/Dublin' );
		$now      = new DateTime( 'now', $timezone );
		$target   = clone $now;
		$target->modify( 'tomorrow 04:00' );

		wp_schedule_event( $target->getTimestamp(), 'daily', self::CRON_HOOK );
	}

	/**
	 * Clear the scheduled sweeper.
	 */
	public static function clear_schedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Cron handler.
	 */
	public function run_scheduled_sync() {
		$this->run_sync();
	}

	/**
	 * Sync one batch of external-banner events into the Media Library.
	 *
	 * @param int|null $batch_size Override the configured batch size.
	 * @return array{synced:int,failed:int,skipped:int}
	 */
	public function run_sync( $batch_size = null ) {
		if ( null === $batch_size ) {
			$batch_size = (int) Conexao_Import_Settings::get( 'image_sync_batch', 25 );
		}
		$batch_size = max( 1, min( 200, $batch_size ) );

		$event_ids = $this->find_events_with_external_banners( $batch_size );

		$synced  = 0;
		$failed  = 0;
		$skipped = 0;

		foreach ( $event_ids as $post_id ) {
			$result = $this->image_handler->sync_event_image( $post_id );

			switch ( $result['status'] ) {
				case 'ok':
					$synced++;
					break;
				case 'failed':
					$failed++;
					break;
				default:
					$skipped++;
					break;
			}
		}

		if ( class_exists( 'Conexao_Import_Log' ) && ! empty( $event_ids ) ) {
			Conexao_Import_Log::add(
				'image_sync',
				$failed > 0 ? 'warning' : 'info',
				sprintf(
					/* translators: 1: number of candidates, 2: synced, 3: failed, 4: skipped */
					__( 'Nightly image sync: %1$d candidate event(s), %2$d synced, %3$d failed, %4$d skipped.', 'conexao-event-importer' ),
					count( $event_ids ),
					$synced,
					$failed,
					$skipped
				),
				array()
			);
		}

		return array(
			'synced'  => $synced,
			'failed'  => $failed,
			'skipped' => $skipped,
		);
	}

	/**
	 * Find events whose banner still points at an external URL.
	 *
	 * Mirrors the admin bulk-sync finder: candidates have an _event_banner
	 * meta value containing http(s), and the precise external/local check is
	 * done per event via the image handler.
	 *
	 * @param int $limit Maximum number of events to return.
	 * @return int[] Event post IDs.
	 */
	protected function find_events_with_external_banners( $limit = 25 ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'event',
				'post_status'            => 'any',
				'posts_per_page'         => max( 1, absint( $limit ) ) * 2, // Extra headroom for filtering.
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => '_event_banner',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_event_banner',
						'value'   => 'http',
						'compare' => 'LIKE',
					),
				),
			)
		);

		$external_ids = array();

		foreach ( $query->posts as $post_id ) {
			$banner_url = get_post_meta( $post_id, '_event_banner', true );

			if ( empty( $banner_url ) || ! $this->image_handler->is_external_image_url( $banner_url ) ) {
				continue;
			}

			// Skip events that already have a valid local attachment.
			$attachment_id = get_post_meta( $post_id, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true );
			if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
				continue;
			}

			$external_ids[] = (int) $post_id;

			if ( count( $external_ids ) >= $limit ) {
				break;
			}
		}

		return $external_ids;
	}
}