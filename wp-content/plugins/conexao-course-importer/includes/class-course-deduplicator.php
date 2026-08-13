<?php
/**
 * Course duplicate detection.
 *
 * Prevents the same course from being imported multiple times by comparing
 * source ID, source URL, and content-based matching.
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Course_Deduplicator {

	/**
	 * Find an existing course that matches the normalized course data.
	 *
	 * @param array $normalized Normalized course data.
	 * @return int Post ID of the matching course, or 0 if none found.
	 */
	public function find( $normalized ) {
		// 1. Strongest match: source course ID.
		if ( ! empty( $normalized['source_id'] ) && ! empty( $normalized['source'] ) ) {
			$by_source = $this->find_by_source( $normalized['source'], $normalized['source_id'] );
			if ( $by_source ) {
				return $by_source;
			}
		}

		// 2. Source URL match.
		if ( ! empty( $normalized['source_url'] ) ) {
			$by_url = $this->find_by_url( $normalized['source_url'] );
			if ( $by_url ) {
				return $by_url;
			}
		}

		// 3. Content-based match: title + date + town + venue + organizer.
		return $this->find_by_content( $normalized );
	}

	/**
	 * Find a course by its source ID.
	 *
	 * @param string $source    Source slug.
	 * @param string $source_id Source course ID.
	 * @return int Post ID or 0.
	 */
	protected function find_by_source( $source, $source_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'course',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_course_source',
						'value' => $source,
					),
					array(
						'key'   => '_course_source_id',
						'value' => $source_id,
					),
				),
			)
		);

		return $query->have_posts() ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find a course by its original source URL.
	 *
	 * @param string $url Source URL.
	 * @return int Post ID or 0.
	 */
	protected function find_by_url( $url ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'course',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => '_course_url',
						'value' => $url,
					),
					array(
						'key'   => '_course_source_url',
						'value' => $url,
					),
				),
			)
		);

		return $query->have_posts() ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find a course by its content (title + date + town + venue + organizer).
	 *
	 * @param array $normalized Normalized course data.
	 * @return int Post ID or 0.
	 */
	protected function find_by_content( $normalized ) {
		if ( empty( $normalized['title'] ) ) {
			return 0;
		}

		$title = sanitize_title( $normalized['title'] );

		$query = new WP_Query(
			array(
				'post_type'      => 'course',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				's'              => $normalized['title'],
			)
		);

		foreach ( $query->posts as $post_id ) {
			$existing_title = sanitize_title( get_the_title( $post_id ) );
			if ( $existing_title !== $title ) {
				continue;
			}

			// If we have a start date, check it matches.
			if ( ! empty( $normalized['start_date'] ) ) {
				$date = get_post_meta( $post_id, '_course_date', true );
				if ( $date && $date !== $normalized['start_date'] ) {
					continue;
				}
			}

			// Check town/venue if available.
			$venue = get_post_meta( $post_id, '_course_venue', true );
			if ( empty( $venue ) ) {
				$venue = get_post_meta( $post_id, '_course_location', true );
			}
			if ( ! empty( $normalized['venue'] ) && ! empty( $venue ) && strtolower( $venue ) !== strtolower( $normalized['venue'] ) ) {
				continue;
			}

			$organizer = get_post_meta( $post_id, '_course_organizer', true );
			if ( ! empty( $normalized['organizer'] ) && ! empty( $organizer ) && strtolower( $organizer ) !== strtolower( $normalized['organizer'] ) ) {
				continue;
			}

			return (int) $post_id;
		}

		return 0;
	}
}