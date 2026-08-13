<?php
/**
 * Course status management.
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Course_Status {

	const DRAFT            = 'draft';
	const NEEDS_REVIEW     = 'needs_review';
	const PUBLISHED        = 'published';
	const SOURCE_NOT_FOUND = 'source_not_found';
	const ARCHIVED         = 'archived';
	const REJECTED         = 'rejected';

	/**
	 * Get all supported statuses with labels.
	 *
	 * @return array
	 */
	public static function get_statuses() {
		return array(
			self::DRAFT            => __( 'Draft', 'conexao-course-importer' ),
			self::NEEDS_REVIEW     => __( 'Needs Review', 'conexao-course-importer' ),
			self::PUBLISHED        => __( 'Published', 'conexao-course-importer' ),
			self::SOURCE_NOT_FOUND => __( 'Source Not Found', 'conexao-course-importer' ),
			self::ARCHIVED         => __( 'Archived', 'conexao-course-importer' ),
			self::REJECTED         => __( 'Rejected', 'conexao-course-importer' ),
		);
	}

	/**
	 * Persist the course status on a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status key.
	 */
	public static function set_status( $post_id, $status ) {
		$statuses = self::get_statuses();
		if ( ! isset( $statuses[ $status ] ) ) {
			return;
		}
		update_post_meta( $post_id, '_course_status', $status );
	}

	/**
	 * Get the current status of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_status( $post_id ) {
		$status = get_post_meta( $post_id, '_course_status', true );
		if ( empty( $status ) ) {
			return self::PUBLISHED; // Legacy courses without a status are live.
		}
		return $status;
	}

	/**
	 * Automatically mark past courses as archived.
	 *
	 * Courses with an end date in the past that don't already have a status
	 * are marked as archived so they stop appearing on public pages but
	 * remain in the database as historical data.
	 */
	public static function mark_archived_courses() {
		$query = new WP_Query(
			array(
				'post_type'      => 'course',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_course_end_date',
						'value'   => current_time( 'Y-m-d' ),
						'compare' => '<',
						'type'    => 'DATE',
					),
					array(
						'key'     => '_course_status',
						'compare' => 'NOT EXISTS',
					),
				),
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $post_id ) {
			update_post_meta( $post_id, '_course_status', self::ARCHIVED );
		}
	}
}