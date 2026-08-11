<?php
/**
 * Event status management.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Status {

	const DRAFT            = 'draft';
	const NEEDS_REVIEW     = 'needs_review';
	const PUBLISHED        = 'published';
	const SOURCE_NOT_FOUND = 'source_not_found';
	const EXPIRED          = 'expired';
	const REJECTED         = 'rejected';

	/**
	 * Get all supported statuses with labels.
	 *
	 * @return array
	 */
	public static function get_statuses() {
		return array(
			self::DRAFT            => __( 'Draft', 'conexao-event-importer' ),
			self::NEEDS_REVIEW     => __( 'Needs Review', 'conexao-event-importer' ),
			self::PUBLISHED        => __( 'Published', 'conexao-event-importer' ),
			self::SOURCE_NOT_FOUND => __( 'Source Not Found', 'conexao-event-importer' ),
			self::EXPIRED          => __( 'Expired', 'conexao-event-importer' ),
			self::REJECTED         => __( 'Rejected', 'conexao-event-importer' ),
		);
	}

	/**
	 * Persist the event status on a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status key.
	 */
	public static function set_status( $post_id, $status ) {
		$statuses = self::get_statuses();
		if ( ! isset( $statuses[ $status ] ) ) {
			return;
		}
		update_post_meta( $post_id, '_event_status', $status );
	}

	/**
	 * Get the current status of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_status( $post_id ) {
		$status = get_post_meta( $post_id, '_event_status', true );
		if ( empty( $status ) ) {
			return self::PUBLISHED; // Legacy events without a status are live.
		}
		return $status;
	}

	/**
	 * Automatically mark past events as expired.
	 *
	 * Runs on a schedule and whenever an import finishes, so historical data
	 * is preserved but expired events stop appearing on public pages.
	 */
	public static function mark_expired_events() {
		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_event_date',
						'value'   => current_time( 'Y-m-d' ),
						'compare' => '<',
						'type'    => 'DATE',
					),
					array(
						'key'     => '_event_status',
						'compare' => 'NOT EXISTS',
					),
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $post_id ) {
			update_post_meta( $post_id, '_event_status', self::EXPIRED );
		}
	}
}