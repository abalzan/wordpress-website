<?php
/**
 * Core event importer engine.
 *
 * Orchestrates fetching, normalizing, deduplicating, and storing events
 * from configured sources into the central WordPress event database.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Importer_Engine {

	/** @var Conexao_Event_Sources */
	protected $sources;

	/** @var Conexao_Event_Location */
	protected $location;

	/** @var Conexao_Event_Normalizer */
	protected $normalizer;

	/** @var Conexao_Event_Deduplicator */
	protected $deduplicator;

	/** @var Conexao_Event_Image_Handler */
	protected $image_handler;

	/**
	 * Constructor.
	 *
	 * @param Conexao_Event_Sources  $sources  Sources manager.
	 * @param Conexao_Event_Location $location Location normalizer.
	 */
	public function __construct( Conexao_Event_Sources $sources, Conexao_Event_Location $location ) {
		$this->sources        = $sources;
		$this->location       = $location;
		$this->normalizer     = new Conexao_Event_Normalizer( $location );
		$this->deduplicator   = new Conexao_Event_Deduplicator();
		$this->image_handler  = new Conexao_Event_Image_Handler();

		// Hook the "run source" filter used by the admin UI.
		add_filter( 'conexao_event_importer_run_source', array( $this, 'run_source' ), 10, 1 );
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

		// Mark past events as expired.
		Conexao_Event_Status::mark_expired_events();

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
			Conexao_Import_Log::add( $source_id, 'error', 'Nenhum handler registrado para esta fonte.' );
			$this->sources->update_import_stats( $source_id, array(
				'last_import'        => current_time( 'mysql' ),
				'last_import_status' => 'error',
				'last_error'         => 'Nenhum handler registrado.',
			) );
			return $stats;
		}

		try {
			$raw_events = $handler->fetch_events();
		} catch ( Exception $e ) {
			Conexao_Import_Log::add( $source_id, 'error', 'Erro ao buscar eventos: ' . $e->getMessage() );
			$this->sources->update_import_stats( $source_id, array(
				'last_import'        => current_time( 'mysql' ),
				'last_import_status' => 'error',
				'last_error'         => $e->getMessage(),
			) );
			return $stats;
		}

		$stats['found'] = count( $raw_events );

		foreach ( $raw_events as $raw ) {
			$raw['source'] = $source_id;

			$normalized = $this->normalizer->normalize( $raw );

			// Always check for an existing event first, even for needs-review
			// events, to avoid creating duplicates on repeated imports.
			$existing_id = $this->deduplicator->find( $normalized );

			if ( $normalized['needs_review'] ) {
				$stats['needs_review']++;
				$this->upsert_event( $normalized, true, $existing_id );
				continue;
			}

			$result = $this->upsert_event( $normalized, false, $existing_id );

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

		// Mark events from this source that disappeared as "Source Not Found".
		$this->mark_missing_events( $source_id, $raw_events );

		$this->sources->update_import_stats( $source_id, array(
			'last_import'        => current_time( 'mysql' ),
			'last_import_status' => 'success',
			'events_imported'    => $stats['new'],
			'last_error'         => '',
		) );

		Conexao_Import_History::record( $source_id, $stats, 'success' );

		return $stats;
	}

	/**
	 * Get the source handler instance for a source config.
	 *
	 * @param array $source Source config.
	 * @return Conexao_Source_Base|null
	 */
	protected function get_source_handler( $source ) {
		// Check source type first for type-based routing.
		$source_type = isset( $source['type'] ) ? $source['type'] : '';

		// iCalendar/Webcal sources use the generic iCalendar handler.
		if ( 'icalendar' === $source_type ) {
			return new Conexao_Source_ICalendar( $source );
		}

		// Eventbrite sources use the Eventbrite handler.
		if ( 'eventbrite' === $source_type ) {
			return new Conexao_Source_Eventbrite( $source );
		}

		// Fall back to source ID-based routing for legacy/website sources.
		// Normalize the source ID for matching (convert hyphens to underscores).
		$normalized_id = str_replace( '-', '_', $source['id'] );
		
		switch ( $normalized_id ) {
			case 'laois_tourism':
				// If Laois Tourism is configured as iCalendar type, use that handler.
				if ( 'icalendar' === $source_type ) {
					return new Conexao_Source_ICalendar( $source );
				}
				return new Conexao_Source_Laois_Tourism( $source );
			case 'laois_council':
				return new Conexao_Source_Laois_Council( $source );
			case 'leo_laois':
			case 'local_enterprise_office_laois':
				return new Conexao_Source_LEO_Laois( $source );
			case 'heritage_week':
			case 'national_heritage_week':
				return new Conexao_Source_Heritage_Week( $source );
			case 'eventbrite':
				return new Conexao_Source_Eventbrite( $source );
			default:
				// Allow third-party handlers to be registered.
				return apply_filters( 'conexao_event_importer_get_handler', null, $source );
		}
	}

	/**
	 * Create or update an event in the database.
	 *
	 * @param array $normalized   Normalized event data.
	 * @param bool  $needs_review Whether this event needs human review.
	 * @param int   $existing_id  Existing post ID (0 for new).
	 * @return array{action:string, post_id:int}
	 */
	protected function upsert_event( $normalized, $needs_review = false, $existing_id = 0 ) {
		$now = current_time( 'mysql' );

		if ( $existing_id ) {
			$post_id = $existing_id;

			// Check if the event data has actually changed.
			$existing_title  = get_the_title( $post_id );
			$existing_date   = get_post_meta( $post_id, '_event_date', true );
			$existing_time   = get_post_meta( $post_id, '_event_start_time', true );
			$existing_url    = get_post_meta( $post_id, '_event_url', true );
			$existing_venue  = get_post_meta( $post_id, '_event_venue', true );
			$existing_org    = get_post_meta( $post_id, '_event_organizer', true );
			$existing_banner = get_post_meta( $post_id, '_event_banner', true );

			// Check if the banner attachment exists (may be missing for events
			// imported before the image download feature was added).
			$existing_banner_attach = get_post_meta( $post_id, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true );
			$has_attachment = $existing_banner_attach && wp_attachment_is_image( $existing_banner_attach );

			$is_unchanged = (
				$existing_title === $normalized['title'] &&
				$existing_date === $normalized['start_date'] &&
				$existing_time === $normalized['start_time'] &&
				$existing_url === $normalized['source_url'] &&
				$existing_venue === $normalized['venue'] &&
				$existing_org === $normalized['organizer'] &&
				$existing_banner === $normalized['banner']
			);

			if ( $is_unchanged ) {
				// Even if data is unchanged, download the image if we don't have an attachment yet.
				if ( ! empty( $normalized['banner'] ) && ! $has_attachment ) {
					$this->handle_event_image( $post_id, $normalized );
				}

				// Update last checked timestamp only.
				update_post_meta( $post_id, '_event_last_checked', current_time( 'mysql' ) );
				return array( 'action' => 'unchanged', 'post_id' => $post_id );
			}

			$action = 'updated';

			$updated = wp_update_post( array(
				'ID'           => $post_id,
				'post_title'   => $normalized['title'],
				'post_content' => $normalized['description'],
				'post_excerpt' => wp_trim_words( $normalized['description'], 30, '...' ),
				'post_status'  => 'publish',
			) );

			if ( is_wp_error( $updated ) ) {
				Conexao_Import_Log::add( $normalized['source'], 'error', 'Falha ao atualizar evento: ' . $updated->get_error_message(), array( 'title' => $normalized['title'] ) );
				return array( 'action' => 'error', 'post_id' => 0 );
			}
		} else {
			$post_id = wp_insert_post( array(
				'post_type'    => 'event',
				'post_title'   => $normalized['title'],
				'post_content' => $normalized['description'],
				'post_excerpt' => wp_trim_words( $normalized['description'], 30, '...' ),
				'post_status'  => 'publish',
			) );

			if ( is_wp_error( $post_id ) ) {
				Conexao_Import_Log::add( $normalized['source'], 'error', 'Falha ao criar evento: ' . $post_id->get_error_message(), array( 'title' => $normalized['title'] ) );
				return array( 'action' => 'error', 'post_id' => 0 );
			}

			$action = 'created';
		}

		// Save all event meta.
		$this->save_event_meta( $post_id, $normalized );

		// Download banner image into WordPress Media Library and set as featured image.
		$this->handle_event_image( $post_id, $normalized );

		// Save taxonomies (county, town, category).
		$this->save_event_taxonomies( $post_id, $normalized );

		// Set status.
		$status = $needs_review ? Conexao_Event_Status::NEEDS_REVIEW : Conexao_Event_Status::PUBLISHED;
		Conexao_Event_Status::set_status( $post_id, $status );

		// If it was previously "Source Not Found" and reappeared, mark it published.
		$prev_status = get_post_meta( $post_id, '_event_status', true );
		if ( Conexao_Event_Status::SOURCE_NOT_FOUND === $prev_status && ! $needs_review ) {
			Conexao_Event_Status::set_status( $post_id, Conexao_Event_Status::PUBLISHED );
		}

		return array( 'action' => $action, 'post_id' => $post_id );
	}

	/**
	 * Handle event banner image: download into Media Library and set as featured image.
	 *
	 * Downloads the external banner URL into the WordPress Media Library using
	 * media_sideload_image, then sets it as both the _event_banner_attachment_id
	 * meta and the post thumbnail (featured image).
	 *
	 * If the image was already downloaded (same URL), it reuses the existing
	 * attachment. If the download fails, the event keeps its external URL as
	 * a fallback in _event_banner.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $normalized Normalized event data.
	 */
	protected function handle_event_image( $post_id, $normalized ) {
		$banner_url = isset( $normalized['banner'] ) ? $normalized['banner'] : '';

		if ( empty( $banner_url ) ) {
			return;
		}

		// Check if we already have an attachment for this event.
		$existing_attachment = get_post_meta( $post_id, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true );
		if ( $existing_attachment && wp_attachment_is_image( $existing_attachment ) ) {
			$source_url = get_post_meta( $existing_attachment, '_event_source_url', true );
			if ( $source_url === $banner_url ) {
				// Same image already downloaded, nothing to do.
				return;
			}
		}

		// Download the image into the Media Library.
		$title = isset( $normalized['title'] ) ? $normalized['title'] : '';
		$attachment_id = $this->image_handler->sideload_image( $banner_url, $post_id, $title );

		if ( $attachment_id ) {
			// Set as both banner attachment and featured image.
			$this->image_handler->set_banner_attachment( $post_id, $attachment_id );
		}
	}

	/**
	 * Save all event meta fields.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $normalized Normalized event data.
	 */
	protected function save_event_meta( $post_id, $normalized ) {
		$meta = array(
			'_event_date'        => $normalized['start_date'],
			'_event_time'        => $normalized['event_time'],
			'_event_start_time'  => $normalized['start_time'],
			'_event_end_date'    => $normalized['end_date'],
			'_event_end_time'    => $normalized['end_time'],
			'_event_location'    => $normalized['event_location'],
			'_event_venue'       => $normalized['venue'],
			'_event_address'     => $normalized['address'],
			'_event_url'         => $normalized['source_url'],
			'_event_source_url'  => $normalized['source_url'],
			'_event_banner'      => $normalized['banner'],
			'_event_source'      => $normalized['source'],
			'_event_source_id'   => $normalized['source_id'],
			'_event_organizer'   => $normalized['organizer'],
			'_event_price'       => $normalized['price'],
			'_event_imported'    => '1',
			'_event_import_date' => current_time( 'mysql' ),
			'_event_last_checked' => current_time( 'mysql' ),
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		if ( $normalized['needs_review'] && ! empty( $normalized['review_notes'] ) ) {
			update_post_meta( $post_id, '_event_review_note', implode( '; ', $normalized['review_notes'] ) );
		}
	}

	/**
	 * Save taxonomies for an event.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $normalized Normalized event data.
	 */
	protected function save_event_taxonomies( $post_id, $normalized ) {
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
	 * Mark events from a source that are no longer present as "Source Not Found".
	 *
	 * @param string $source_id   Source slug.
	 * @param array  $raw_events  Raw events fetched this run.
	 */
	protected function mark_missing_events( $source_id, $raw_events ) {
		$current_urls = array();
		foreach ( $raw_events as $raw ) {
			if ( ! empty( $raw['url'] ) ) {
				$current_urls[] = $raw['url'];
			}
		}

		if ( empty( $current_urls ) ) {
			return;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_event_source',
						'value' => $source_id,
					),
					array(
						'key'     => '_event_status',
						'compare' => 'NOT IN',
						'value'   => array( 'rejected', 'source_not_found' ),
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$stored_url = get_post_meta( $post_id, '_event_url', true );
			if ( $stored_url && ! in_array( $stored_url, $current_urls, true ) ) {
				// Event disappeared from the source. Mark for review, don't delete.
				Conexao_Event_Status::set_status( $post_id, Conexao_Event_Status::SOURCE_NOT_FOUND );
				update_post_meta( $post_id, '_event_review_note', __( 'Evento não encontrado na fonte na última importação.', 'conexao-event-importer' ) );
			}
		}
	}
}