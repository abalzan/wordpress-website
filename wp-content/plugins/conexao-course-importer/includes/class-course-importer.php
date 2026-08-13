<?php
/**
 * Core course importer engine.
 *
 * Orchestrates fetching, normalizing, deduplicating, and storing courses
 * from configured sources into the central WordPress course database.
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Course_Importer_Engine {

	/** @var Conexao_Course_Sources */
	protected $sources;

	/** @var Conexao_Course_Location */
	protected $location;

	/** @var Conexao_Course_Normalizer */
	protected $normalizer;

	/** @var Conexao_Course_Deduplicator */
	protected $deduplicator;

	/** @var Conexao_Course_Image_Handler */
	protected $image_handler;

	/**
	 * Constructor.
	 *
	 * @param Conexao_Course_Sources  $sources  Sources manager.
	 * @param Conexao_Course_Location $location Location normalizer.
	 */
	public function __construct( Conexao_Course_Sources $sources, Conexao_Course_Location $location ) {
		$this->sources       = $sources;
		$this->location      = $location;
		$this->normalizer    = new Conexao_Course_Normalizer( $location );
		$this->deduplicator  = new Conexao_Course_Deduplicator();
		$this->image_handler = new Conexao_Course_Image_Handler();

		// Hook the "run source" filter used by the admin UI.
		add_filter( 'conexao_course_importer_run_source', array( $this, 'run_source' ), 10, 1 );
	}

	/**
	 * Run a full import across all active sources.
	 *
	 * @return array Aggregate stats.
	 */
	public function run_all() {
		$stats = array(
			'found'         => 0,
			'new'           => 0,
			'updated'       => 0,
			'unchanged'     => 0,
			'duplicates'    => 0,
			'needs_review'  => 0,
			'errors'        => 0,
		);

		foreach ( $this->sources->get_active() as $source ) {
			$result = $this->run_source( $source['id'] );
			foreach ( $stats as $key => $value ) {
				$stats[ $key ] += isset( $result[ $key ] ) ? $result[ $key ] : 0;
			}
		}

		// Mark past courses as archived.
		Conexao_Course_Status::mark_archived_courses();

		return $stats;
	}

	/**
	 * Run an import for a single source.
	 *
	 * @param string $source_id Source slug.
	 * @return array Stats for this source.
	 */
	public function run_source( $source_id ) {
		$stats = array(
			'found'        => 0,
			'new'          => 0,
			'updated'      => 0,
			'unchanged'    => 0,
			'duplicates'   => 0,
			'needs_review' => 0,
			'errors'       => 0,
		);

		$source = $this->sources->get( $source_id );
		if ( ! $source || 'active' !== $source['status'] ) {
			return $stats;
		}

		$handler = $this->get_source_handler( $source );
		if ( ! $handler ) {
			$this->sources->update_import_stats( $source_id, array(
				'last_import'        => current_time( 'mysql' ),
				'last_import_status' => 'error',
				'last_error'         => 'Nenhum handler registrado.',
			) );
			return $stats;
		}

		try {
			$raw_courses = $handler->fetch_courses();
		} catch ( Exception $e ) {
			$this->sources->update_import_stats( $source_id, array(
				'last_import'        => current_time( 'mysql' ),
				'last_import_status' => 'error',
				'last_error'         => $e->getMessage(),
			) );
			return $stats;
		}

		$stats['found'] = count( $raw_courses );

		foreach ( $raw_courses as $raw ) {
			$raw['source'] = $source_id;

			$normalized = $this->normalizer->normalize( $raw );

			// Always check for an existing course first.
			$existing_id = $this->deduplicator->find( $normalized );

			if ( $normalized['needs_review'] ) {
				$stats['needs_review']++;
				$this->upsert_course( $normalized, true, $existing_id );
				continue;
			}

			$result = $this->upsert_course( $normalized, false, $existing_id );

			if ( 'created' === $result['action'] ) {
				$stats['new']++;
			} elseif ( 'updated' === $result['action'] ) {
				$stats['updated']++;
			} elseif ( 'unchanged' === $result['action'] ) {
				$stats['unchanged']++;
			} elseif ( 'duplicate' === $result['action'] ) {
				$stats['duplicates']++;
			}
		}

		// Mark courses from this source that disappeared.
		$this->mark_missing_courses( $source_id, $raw_courses );

		$this->sources->update_import_stats( $source_id, array(
			'last_import'        => current_time( 'mysql' ),
			'last_import_status' => 'success',
			'courses_imported'   => $stats['new'],
			'last_error'         => '',
		) );

		$this->record_history( $source_id, $stats );

		return $stats;
	}

	/**
	 * Get the source handler instance for a source config.
	 *
	 * @param array $source Source config.
	 * @return Conexao_Course_Source_Base|null
	 */
	protected function get_source_handler( $source ) {
		$source_type = isset( $source['type'] ) ? $source['type'] : '';

		switch ( $source_type ) {
			case 'website':
			case '':
				return new Conexao_Course_Website_Source( $source );
			default:
				// Allow third-party handlers to be registered.
				return apply_filters( 'conexao_course_importer_get_handler', null, $source );
		}
	}

	/**
	 * Create or update a course in the database.
	 *
	 * @param array $normalized   Normalized course data.
	 * @param bool  $needs_review Whether this course needs human review.
	 * @param int   $existing_id  Existing post ID (0 for new).
	 * @return array{action:string, post_id:int}
	 */
	protected function upsert_course( $normalized, $needs_review = false, $existing_id = 0 ) {
		if ( $existing_id ) {
			$post_id = $existing_id;

			// Check if the course data has actually changed.
			$existing_title  = get_the_title( $post_id );
			$existing_date   = get_post_meta( $post_id, '_course_date', true );
			$existing_url    = get_post_meta( $post_id, '_course_url', true );
			$existing_venue  = get_post_meta( $post_id, '_course_venue', true );
			$existing_org    = get_post_meta( $post_id, '_course_organizer', true );
			$existing_banner = get_post_meta( $post_id, '_course_banner', true );

			$existing_banner_attach = get_post_meta( $post_id, Conexao_Course_Image_Handler::ATTACHMENT_META_KEY, true );
			$has_attachment = $existing_banner_attach && wp_attachment_is_image( $existing_banner_attach );

			$is_unchanged = (
				$existing_title === $normalized['title'] &&
				$existing_date === $normalized['start_date'] &&
				$existing_url === $normalized['source_url'] &&
				$existing_venue === $normalized['venue'] &&
				$existing_org === $normalized['organizer'] &&
				$existing_banner === $normalized['banner']
			);

			if ( $is_unchanged ) {
				if ( ! empty( $normalized['banner'] ) && ! $has_attachment ) {
					$this->handle_course_image( $post_id, $normalized );
				}
				update_post_meta( $post_id, '_course_last_checked', current_time( 'mysql' ) );
				return array( 'action' => 'unchanged', 'post_id' => $post_id );
			}

			$action = 'updated';

			$updated = wp_update_post( array(
				'ID'           => $post_id,
				'post_title'   => $normalized['title'],
				'post_content' => $normalized['description'],
				'post_excerpt' => ! empty( $normalized['short_description'] )
					? $normalized['short_description']
					: wp_trim_words( $normalized['description'], 30, '...' ),
				'post_status'  => 'publish',
			) );

			if ( is_wp_error( $updated ) ) {
				return array( 'action' => 'error', 'post_id' => 0 );
			}
		} else {
			$post_id = wp_insert_post( array(
				'post_type'    => 'course',
				'post_title'   => $normalized['title'],
				'post_content' => $normalized['description'],
				'post_excerpt' => ! empty( $normalized['short_description'] )
					? $normalized['short_description']
					: wp_trim_words( $normalized['description'], 30, '...' ),
				'post_status'  => 'publish',
			) );

			if ( is_wp_error( $post_id ) ) {
				return array( 'action' => 'error', 'post_id' => 0 );
			}

			$action = 'created';
		}

		// Save all course meta.
		$this->save_course_meta( $post_id, $normalized );

		// Download banner image.
		$this->handle_course_image( $post_id, $normalized );

		// Save taxonomies.
		$this->save_course_taxonomies( $post_id, $normalized );

		// Set status.
		$status = $needs_review ? Conexao_Course_Status::NEEDS_REVIEW : Conexao_Course_Status::PUBLISHED;
		Conexao_Course_Status::set_status( $post_id, $status );

		// If it was previously "Source Not Found" and reappeared, mark it published.
		$prev_status = get_post_meta( $post_id, '_course_status', true );
		if ( Conexao_Course_Status::SOURCE_NOT_FOUND === $prev_status && ! $needs_review ) {
			Conexao_Course_Status::set_status( $post_id, Conexao_Course_Status::PUBLISHED );
		}

		return array( 'action' => $action, 'post_id' => $post_id );
	}

	/**
	 * Handle course banner image.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $normalized Normalized course data.
	 */
	protected function handle_course_image( $post_id, $normalized ) {
		$banner_url = isset( $normalized['banner'] ) ? $normalized['banner'] : '';

		if ( empty( $banner_url ) ) {
			return;
		}

		$existing_attachment = get_post_meta( $post_id, Conexao_Course_Image_Handler::ATTACHMENT_META_KEY, true );
		if ( $existing_attachment && wp_attachment_is_image( $existing_attachment ) ) {
			$source_url = get_post_meta( $existing_attachment, '_course_source_url', true );
			if ( $source_url === $banner_url ) {
				return;
			}
		}

		$title = isset( $normalized['title'] ) ? $normalized['title'] : '';
		$attachment_id = $this->image_handler->sideload_image( $banner_url, $post_id, $title );

		if ( $attachment_id ) {
			$this->image_handler->set_banner_attachment( $post_id, $attachment_id );
		}
	}

	/**
	 * Save all course meta fields.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $normalized Normalized course data.
	 */
	protected function save_course_meta( $post_id, $normalized ) {
		$meta = array(
			'_course_date'         => $normalized['start_date'],
			'_course_time'         => $normalized['course_time'],
			'_course_start_time'   => $normalized['start_time'],
			'_course_end_date'     => $normalized['end_date'],
			'_course_end_time'     => $normalized['end_time'],
			'_course_duration'     => $normalized['duration'],
			'_course_location'     => $normalized['course_location'],
			'_course_venue'        => $normalized['venue'],
			'_course_address'      => $normalized['address'],
			'_course_town'         => $normalized['town'],
			'_course_county'       => $normalized['county'],
			'_course_url'          => $normalized['source_url'],
			'_course_source_url'   => $normalized['source_url'],
			'_course_banner'       => $normalized['banner'],
			'_course_source'       => $normalized['source'],
			'_course_source_id'    => $normalized['source_id'],
			'_course_organizer'    => $normalized['organizer'],
			'_course_price'        => $normalized['price'],
			'_course_currency'     => $normalized['currency'],
			'_course_booking_url'  => $normalized['booking_url'],
			'_course_category'     => $normalized['category'],
			'_course_type'         => $normalized['course_type'],
			'_course_delivery_mode' => $normalized['delivery_mode'],
			'_course_imported'     => '1',
			'_course_import_date'  => current_time( 'mysql' ),
			'_course_last_checked' => current_time( 'mysql' ),
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		if ( $normalized['needs_review'] && ! empty( $normalized['review_notes'] ) ) {
			update_post_meta( $post_id, '_course_review_note', implode( '; ', $normalized['review_notes'] ) );
		}
	}

	/**
	 * Save taxonomies for a course.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $normalized Normalized course data.
	 */
	protected function save_course_taxonomies( $post_id, $normalized ) {
		// County.
		if ( ! empty( $normalized['county'] ) ) {
			$county_id = $this->location->ensure_county( $normalized['county'] );
			if ( $county_id ) {
				wp_set_object_terms( $post_id, array( $county_id ), 'conexao_county' );
			}
		}

		// Town.
		if ( ! empty( $normalized['town'] ) ) {
			$town_id = $this->location->ensure_town( $normalized['town'] );
			if ( $town_id ) {
				wp_set_object_terms( $post_id, array( $town_id ), 'conexao_town' );
			}
		}

		// Category.
		if ( ! empty( $normalized['category'] ) ) {
			$category = $this->normalizer->clean_category( $normalized['category'] );
			if ( $category ) {
				$term = term_exists( $category, 'conexao_category' );
				if ( ! $term ) {
					$new_term = wp_insert_term( $category, 'conexao_category' );
					if ( ! is_wp_error( $new_term ) ) {
						$term = $new_term;
					}
				}
				if ( $term && ! is_wp_error( $term ) ) {
					$term_id = is_array( $term ) ? $term['term_id'] : $term;
					wp_set_object_terms( $post_id, array( (int) $term_id ), 'conexao_category' );
				}
			}
		}
	}

	/**
	 * Mark courses from a source that are no longer present.
	 *
	 * @param string $source_id   Source slug.
	 * @param array  $raw_courses Raw courses fetched this run.
	 */
	protected function mark_missing_courses( $source_id, $raw_courses ) {
		$current_urls = array();
		foreach ( $raw_courses as $raw ) {
			if ( ! empty( $raw['url'] ) ) {
				$current_urls[] = $raw['url'];
			}
		}

		if ( empty( $current_urls ) ) {
			return;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'course',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_course_source',
						'value' => $source_id,
					),
					array(
						'key'     => '_course_status',
						'compare' => 'NOT IN',
						'value'   => array( 'rejected', 'source_not_found', 'archived' ),
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$stored_url = get_post_meta( $post_id, '_course_url', true );
			if ( $stored_url && ! in_array( $stored_url, $current_urls, true ) ) {
				Conexao_Course_Status::set_status( $post_id, Conexao_Course_Status::SOURCE_NOT_FOUND );
				update_post_meta( $post_id, '_course_review_note', __( 'Curso não encontrado na fonte na última importação.', 'conexao-course-importer' ) );
			}
		}
	}

	/**
	 * Record import history.
	 *
	 * Uses the same option key pattern as the event importer but with a
	 * course-specific suffix to keep histories separate.
	 *
	 * @param string $source_id Source slug.
	 * @param array  $stats     Stats.
	 */
	protected function record_history( $source_id, $stats ) {
		$option_key = 'conexao_course_import_history';
		$history = get_option( $option_key, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$entry = array(
			'time'          => current_time( 'mysql' ),
			'source'        => $source_id,
			'found'         => isset( $stats['found'] ) ? (int) $stats['found'] : 0,
			'new'           => isset( $stats['new'] ) ? (int) $stats['new'] : 0,
			'updated'       => isset( $stats['updated'] ) ? (int) $stats['updated'] : 0,
			'unchanged'     => isset( $stats['unchanged'] ) ? (int) $stats['unchanged'] : 0,
			'duplicates'    => isset( $stats['duplicates'] ) ? (int) $stats['duplicates'] : 0,
			'needs_review'  => isset( $stats['needs_review'] ) ? (int) $stats['needs_review'] : 0,
			'errors'        => isset( $stats['errors'] ) ? (int) $stats['errors'] : 0,
			'status'        => 'success',
		);

		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, 100 );

		update_option( $option_key, $history, false );
	}
}