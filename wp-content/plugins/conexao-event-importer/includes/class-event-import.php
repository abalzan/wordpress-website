<?php
/**
 * Event import.
 *
 * Imports a portable JSON export file (produced by Conexao_Event_Export) into
 * the current WordPress installation, recreating event posts, metadata,
 * taxonomies, and featured images.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Import {

	/** @var Conexao_Event_Image_Handler */
	protected $image_handler;

	/** @var Conexao_Event_Location */
	protected $location;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->image_handler = new Conexao_Event_Image_Handler();
		$this->location      = new Conexao_Event_Location();
	}

	/**
	 * Validate an uploaded import file.
	 *
	 * @param array $file The $_FILES entry for the upload.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function validate_upload( $file ) {
		if ( ! isset( $file['error'] ) || is_array( $file['error'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'Invalid file upload.', 'conexao-event-importer' ) );
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$messages = array(
				UPLOAD_ERR_INI_SIZE   => __( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'conexao-event-importer' ),
				UPLOAD_ERR_FORM_SIZE  => __( 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the form.', 'conexao-event-importer' ),
				UPLOAD_ERR_PARTIAL    => __( 'The uploaded file was only partially uploaded.', 'conexao-event-importer' ),
				UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'conexao-event-importer' ),
				UPLOAD_ERR_NO_TMP_DIR => __( 'Missing a temporary folder.', 'conexao-event-importer' ),
				UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'conexao-event-importer' ),
				UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the file upload.', 'conexao-event-importer' ),
			);
			$message = isset( $messages[ $file['error'] ] ) ? $messages[ $file['error'] ] : __( 'Unknown upload error.', 'conexao-event-importer' );
			return new WP_Error( 'upload_error', $message );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'The uploaded file could not be read.', 'conexao-event-importer' ) );
		}

		// Check file size (limit to 500 MB — export v1.1 embeds base64 images,
		// which roughly triples the JSON size compared to URL-only exports).
		if ( filesize( $file['tmp_name'] ) > 500 * MB_IN_BYTES ) {
			return new WP_Error( 'file_too_large', __( 'The uploaded file is too large. Maximum size is 64 MB.', 'conexao-event-importer' ) );
		}

		// Check the file extension.
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'json' !== $ext ) {
			return new WP_Error( 'invalid_type', __( 'Only .json export files are supported.', 'conexao-event-importer' ) );
		}

		// Read and validate the JSON content.
		$contents = file_get_contents( $file['tmp_name'] );
		if ( false === $contents ) {
			return new WP_Error( 'read_error', __( 'The uploaded file could not be read.', 'conexao-event-importer' ) );
		}

		$data = json_decode( $contents, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_json', __( 'The uploaded file is not valid JSON.', 'conexao-event-importer' ) );
		}

		// Validate the manifest.
		if ( ! isset( $data['manifest']['format'] ) || Conexao_Event_Export::FORMAT !== $data['manifest']['format'] ) {
			return new WP_Error( 'invalid_format', __( 'This file is not a valid Conexão event export.', 'conexao-event-importer' ) );
		}

		if ( ! isset( $data['events'] ) || ! is_array( $data['events'] ) ) {
			return new WP_Error( 'invalid_format', __( 'The export file contains no event data.', 'conexao-event-importer' ) );
		}

		return true;
	}

	/**
	 * Import events from an uploaded file.
	 *
	 * @param array $file     The $_FILES entry for the upload.
	 * @param array $options  Import options (e.g. duplicate strategy).
	 * @return array{
	 *   imported: int,
	 *   updated: int,
	 *   skipped: int,
	 *   failed: int,
	 *   errors: array
	 * }
	 */
	public function import_file( $file, $options = array() ) {
		$validated = $this->validate_upload( $file );
		if ( is_wp_error( $validated ) ) {
			return array(
				'imported' => 0,
				'updated'  => 0,
				'skipped'  => 0,
				'failed'   => 0,
				'errors'   => array( $validated->get_error_message() ),
			);
		}

		$contents = file_get_contents( $file['tmp_name'] );
		$data     = json_decode( $contents, true );

		$strategy = isset( $options['duplicate_strategy'] ) ? $options['duplicate_strategy'] : 'update';
		if ( ! in_array( $strategy, array( 'update', 'skip' ), true ) ) {
			$strategy = 'update';
		}

		$stats = array(
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
			'errors'   => array(),
		);

		foreach ( $data['events'] as $event ) {
			$result = $this->import_event( $event, $strategy );

			if ( 'created' === $result['action'] ) {
				$stats['imported']++;
			} elseif ( 'updated' === $result['action'] ) {
				$stats['updated']++;
			} elseif ( 'skipped' === $result['action'] ) {
				$stats['skipped']++;
			} else {
				$stats['failed']++;
				if ( ! empty( $result['error'] ) ) {
					$stats['errors'][] = $result['error'];
				}
			}
		}

		return $stats;
	}

	/**
	 * Import a single event from the export payload.
	 *
	 * @param array  $event    Event data from the export file.
	 * @param string $strategy Duplicate strategy: 'update' or 'skip'.
	 * @return array{action:string, post_id:int, error?:string}
	 */
	protected function import_event( $event, $strategy ) {
		// Validate the event payload.
		if ( empty( $event['post']['title'] ) ) {
			return array( 'action' => 'failed', 'post_id' => 0, 'error' => __( 'Event is missing a title.', 'conexao-event-importer' ) );
		}

		$uuid = isset( $event['uuid'] ) ? sanitize_text_field( $event['uuid'] ) : '';

		// Find an existing event.
		$existing_id = $this->find_existing_event( $event, $uuid );

		if ( $existing_id ) {
			if ( 'skip' === $strategy ) {
				return array( 'action' => 'skipped', 'post_id' => $existing_id );
			}

			// Update the existing event.
			$post_id = $this->update_event( $existing_id, $event );
			if ( is_wp_error( $post_id ) ) {
				return array( 'action' => 'failed', 'post_id' => 0, 'error' => $post_id->get_error_message() );
			}

			return array( 'action' => 'updated', 'post_id' => $post_id );
		}

		// Create a new event.
		$post_id = $this->create_event( $event );
		if ( is_wp_error( $post_id ) ) {
			return array( 'action' => 'failed', 'post_id' => 0, 'error' => $post_id->get_error_message() );
		}

		return array( 'action' => 'created', 'post_id' => $post_id );
	}

	/**
	 * Find an existing event that matches the imported event.
	 *
	 * Matching priority:
	 *  1. Export UUID meta (_event_export_uuid)
	 *  2. Source ID (_event_source + _event_source_id)
	 *  3. Source URL (_event_url / _event_source_url)
	 *  4. Content-based (title + date + time + venue)
	 *
	 * @param array  $event Event data from the export file.
	 * @param string $uuid  Export UUID.
	 * @return int Post ID or 0.
	 */
	protected function find_existing_event( $event, $uuid ) {
		// 1. UUID match.
		if ( $uuid ) {
			$by_uuid = $this->find_by_uuid( $uuid );
			if ( $by_uuid ) {
				return $by_uuid;
			}
		}

		$meta = isset( $event['meta'] ) ? $event['meta'] : array();

		// 2. Source ID match.
		$source    = isset( $meta['_event_source'] ) ? $meta['_event_source'] : '';
		$source_id = isset( $meta['_event_source_id'] ) ? $meta['_event_source_id'] : '';
		if ( $source && $source_id ) {
			$by_source = $this->find_by_source( $source, $source_id );
			if ( $by_source ) {
				return $by_source;
			}
		}

		// 3. Source URL match.
		$source_url = isset( $meta['_event_source_url'] ) ? $meta['_event_source_url'] : '';
		if ( empty( $source_url ) ) {
			$source_url = isset( $meta['_event_url'] ) ? $meta['_event_url'] : '';
		}
		if ( $source_url ) {
			$by_url = $this->find_by_url( $source_url );
			if ( $by_url ) {
				return $by_url;
			}
		}

		// 4. Content-based match.
		return $this->find_by_content( $event );
	}

	/**
	 * Find an event by its export UUID.
	 *
	 * @param string $uuid Export UUID.
	 * @return int Post ID or 0.
	 */
	protected function find_by_uuid( $uuid ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'     => array(
					array(
						'key'   => Conexao_Event_Export::UUID_META_KEY,
						'value' => $uuid,
					),
				),
			)
		);

		return $query->have_posts() ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find an event by its source ID.
	 *
	 * @param string $source    Source slug.
	 * @param string $source_id Source event ID.
	 * @return int Post ID or 0.
	 */
	protected function find_by_source( $source, $source_id ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_event_source',
						'value' => $source,
					),
					array(
						'key'   => '_event_source_id',
						'value' => $source_id,
					),
				),
			)
		);

		return $query->have_posts() ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find an event by its source URL.
	 *
	 * @param string $url Source URL.
	 * @return int Post ID or 0.
	 */
	protected function find_by_url( $url ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => '_event_url',
						'value' => $url,
					),
					array(
						'key'   => '_event_source_url',
						'value' => $url,
					),
				),
			)
		);

		return $query->have_posts() ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find an event by its content (title + date + time + venue).
	 *
	 * @param array $event Event data from the export file.
	 * @return int Post ID or 0.
	 */
	protected function find_by_content( $event ) {
		$title = isset( $event['post']['title'] ) ? $event['post']['title'] : '';
		$meta  = isset( $event['meta'] ) ? $event['meta'] : array();

		$date = isset( $meta['_event_date'] ) ? $meta['_event_date'] : '';
		if ( empty( $title ) || empty( $date ) ) {
			return 0;
		}

		$title_slug = sanitize_title( $title );

		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				's'              => $title,
			)
		);

		foreach ( $query->posts as $post_id ) {
			$existing_title = sanitize_title( get_the_title( $post_id ) );
			if ( $existing_title !== $title_slug ) {
				continue;
			}

			$existing_date = get_post_meta( $post_id, '_event_date', true );
			if ( $existing_date !== $date ) {
				continue;
			}

			$time = isset( $meta['_event_start_time'] ) ? $meta['_event_start_time'] : '';
			if ( empty( $time ) ) {
				$time = isset( $meta['_event_time'] ) ? $meta['_event_time'] : '';
				$time = trim( explode( '—', $time )[0] );
			}

			$existing_time = get_post_meta( $post_id, '_event_start_time', true );
			if ( empty( $existing_time ) ) {
				$existing_time = get_post_meta( $post_id, '_event_time', true );
				$existing_time = trim( explode( '—', $existing_time )[0] );
			}

			if ( ! empty( $time ) && ! empty( $existing_time ) && $existing_time !== $time ) {
				continue;
			}

			$venue = isset( $meta['_event_venue'] ) ? $meta['_event_venue'] : '';
			if ( empty( $venue ) ) {
				$venue = isset( $meta['_event_location'] ) ? $meta['_event_location'] : '';
			}

			$existing_venue = get_post_meta( $post_id, '_event_venue', true );
			if ( empty( $existing_venue ) ) {
				$existing_venue = get_post_meta( $post_id, '_event_location', true );
			}

			if ( ! empty( $venue ) && ! empty( $existing_venue ) && strtolower( $existing_venue ) !== strtolower( $venue ) ) {
				continue;
			}

			return (int) $post_id;
		}

		return 0;
	}

	/**
	 * Create a new event post from the export payload.
	 *
	 * @param array $event Event data from the export file.
	 * @return int|WP_Error Post ID or WP_Error.
	 */
	protected function create_event( $event ) {
		$post_data = $this->sanitize_post_data( $event );

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'event',
				'post_title'   => $post_data['title'],
				'post_content' => $post_data['content'],
				'post_excerpt' => $post_data['excerpt'],
				'post_status'  => $post_data['status'],
				'post_name'    => $post_data['slug'],
				'post_date'    => $post_data['date'],
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->populate_event( $post_id, $event );

		return $post_id;
	}

	/**
	 * Update an existing event post from the export payload.
	 *
	 * @param int   $post_id Existing event post ID.
	 * @param array $event   Event data from the export file.
	 * @return int|WP_Error Post ID or WP_Error.
	 */
	protected function update_event( $post_id, $event ) {
		$post_data = $this->sanitize_post_data( $event );

		$updated = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $post_data['title'],
				'post_content' => $post_data['content'],
				'post_excerpt' => $post_data['excerpt'],
				'post_status'  => $post_data['status'],
				'post_name'    => $post_data['slug'],
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->populate_event( $post_id, $event );

		return $post_id;
	}

	/**
	 * Populate meta, taxonomies, and featured image for an event.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $event   Event data from the export file.
	 */
	protected function populate_event( $post_id, $event ) {
		// Save the export UUID so re-imports can be matched.
		if ( ! empty( $event['uuid'] ) ) {
			update_post_meta( $post_id, Conexao_Event_Export::UUID_META_KEY, sanitize_text_field( $event['uuid'] ) );
		}

		// Save event meta.
		$this->save_event_meta( $post_id, $event );

		// Save taxonomies.
		$this->save_event_taxonomies( $post_id, $event );

		// Handle the featured image.
		$this->handle_event_image( $post_id, $event );
	}

	/**
	 * Save all event meta fields from the export payload.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $event   Event data from the export file.
	 */
	protected function save_event_meta( $post_id, $event ) {
		$meta = isset( $event['meta'] ) ? $event['meta'] : array();

		// Allowed meta keys (same as export).
		$allowed = array(
			'_event_date',
			'_event_time',
			'_event_start_time',
			'_event_end_date',
			'_event_end_time',
			'_event_location',
			'_event_venue',
			'_event_address',
			'_event_map_url',
			'_event_url',
			'_event_source_url',
			'_event_banner',
			'_event_registration',
			'_event_cta',
			'_event_source',
			'_event_source_id',
			'_event_organizer',
			'_event_price',
			'_event_import_date',
			'_event_last_checked',
			'_event_status',
			'_event_imported',
		);

		foreach ( $allowed as $key ) {
			if ( isset( $meta[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( (string) $meta[ $key ] ) );
			}
		}

		// Mark as imported.
		update_post_meta( $post_id, '_event_imported', '1' );
	}

	/**
	 * Save taxonomies for an event from the export payload.
	 *
	 * Terms are matched by name (not ID) so they map correctly across
	 * installations. Missing terms are created automatically.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $event   Event data from the export file.
	 */
	protected function save_event_taxonomies( $post_id, $event ) {
		$taxonomies = isset( $event['taxonomies'] ) ? $event['taxonomies'] : array();

		$supported = array(
			'conexao_category',
			'conexao_county',
			'conexao_tag',
			'conexao_town',
		);

		foreach ( $supported as $taxonomy ) {
			$names = isset( $taxonomies[ $taxonomy ] ) ? $taxonomies[ $taxonomy ] : array();
			if ( ! is_array( $names ) ) {
				$names = array();
			}

			$names = array_filter( array_map( 'sanitize_text_field', $names ) );

			if ( empty( $names ) ) {
				wp_set_object_terms( $post_id, array(), $taxonomy );
				continue;
			}

			// Ensure each term exists (by name) and collect term IDs.
			$term_ids = array();
			foreach ( $names as $name ) {
				$term = term_exists( $name, $taxonomy );
				if ( ! $term ) {
					$new_term = wp_insert_term( $name, $taxonomy );
					if ( is_wp_error( $new_term ) ) {
						continue;
					}
					$term = $new_term;
				}
				$term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy );
			}
		}
	}

	/**
	 * Handle the featured image for an imported event.
	 *
	 * Preferred path: create the Media Library attachment from the base64
	 * image data embedded in the export file — no external HTTP requests are
	 * made. This is what makes production imports work even though the
	 * production server cannot reach the original event sources.
	 *
	 * Fallback path (legacy 1.0 exports without embedded data): sideload the
	 * image from its external source URL. This only works when the current
	 * server can reach that URL; failures here do not fail the event import.
	 *
	 * @param int   $post_id Event post ID.
	 * @param array $event   Event data from the export file.
	 */
	protected function handle_event_image( $post_id, $event ) {
		$featured = isset( $event['featured_image'] ) ? $event['featured_image'] : array();
		$meta     = isset( $event['meta'] ) ? $event['meta'] : array();

		// Determine the original external source URL (recorded as attachment meta).
		$source_url = isset( $featured['source_url'] ) ? $featured['source_url'] : '';
		if ( empty( $source_url ) && ! empty( $meta['_event_banner'] ) && ! $this->is_localhost_url( $meta['_event_banner'] ) ) {
			$source_url = $meta['_event_banner'];
		}

		$title = isset( $event['post']['title'] ) ? $event['post']['title'] : '';

		// 1. Preferred: embedded base64 image data (export format >= 1.1.0).
		$data_base64 = isset( $featured['data_base64'] ) ? $featured['data_base64'] : '';
		if ( ! empty( $data_base64 ) ) {
			$filename    = isset( $featured['filename'] ) ? $featured['filename'] : '';
			$attachment_id = $this->image_handler->create_attachment_from_base64(
				$data_base64,
				$post_id,
				$title,
				$filename,
				$source_url
			);

			if ( $attachment_id ) {
				$this->image_handler->set_banner_attachment( $post_id, $attachment_id );

				// Restore the alt text if provided.
				if ( ! empty( $featured['alt'] ) ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $featured['alt'] ) );
				}
				return;
			}

			// Embedded data failed validation — fall through to the URL path.
		}

		// 2. Fallback: sideload from the external source URL (legacy exports).
		$banner_url = isset( $featured['banner_url'] ) ? $featured['banner_url'] : '';
		if ( empty( $source_url ) && $banner_url ) {
			$source_url = $banner_url;
		}

		if ( empty( $source_url ) ) {
			return;
		}

		// Skip localhost URLs — the destination site cannot reach the local Docker env.
		if ( $this->is_localhost_url( $source_url ) ) {
			return;
		}

		$attachment_id = $this->image_handler->sideload_image( $source_url, $post_id, $title );

		if ( $attachment_id ) {
			$this->image_handler->set_banner_attachment( $post_id, $attachment_id );

			// Restore the alt text if provided.
			if ( ! empty( $featured['alt'] ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $featured['alt'] ) );
			}
		}
	}

	/**
	 * Check whether a URL points to a localhost / local Docker environment.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	protected function is_localhost_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		$host = strtolower( $host );

		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
			|| 0 === strpos( $host, '192.168.' )
			|| 0 === strpos( $host, '10.' )
			|| 0 === strpos( $host, '172.' );
	}

	/**
	 * Sanitize the post data from an export payload.
	 *
	 * @param array $event Event data from the export file.
	 * @return array{title:string, content:string, excerpt:string, status:string, slug:string, date:string}
	 */
	protected function sanitize_post_data( $event ) {
		$post = isset( $event['post'] ) ? $event['post'] : array();

		$title   = isset( $post['title'] ) ? sanitize_text_field( (string) $post['title'] ) : '';
		$content = isset( $post['content'] ) ? wp_kses_post( (string) $post['content'] ) : '';
		$excerpt = isset( $post['excerpt'] ) ? sanitize_text_field( (string) $post['excerpt'] ) : '';

		// Map the event status to a valid WordPress post status.
		$status = isset( $post['status'] ) ? sanitize_key( $post['status'] ) : 'publish';
		$valid_statuses = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			$status = 'publish';
		}

		$slug = isset( $post['slug'] ) ? sanitize_title( $post['slug'] ) : '';
		$date = isset( $post['date'] ) ? sanitize_text_field( (string) $post['date'] ) : '';

		return array(
			'title'   => $title,
			'content' => $content,
			'excerpt' => $excerpt,
			'status'  => $status,
			'slug'    => $slug,
			'date'    => $date,
		);
	}
}
