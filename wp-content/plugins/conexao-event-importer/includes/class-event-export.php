<?php
/**
 * Event export.
 *
 * Exports all event posts (and their metadata, taxonomies, and image
 * references) into a portable JSON file that can be imported into another
 * WordPress installation running the same event structure.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Export {

	/**
	 * Format identifier written into the export manifest.
	 */
	const FORMAT = 'conexao-event-export';

	/**
	 * Export file format version.
	 */
	const FORMAT_VERSION = '1.0.0';

	/**
	 * Meta key used to store a stable unique identifier for each event.
	 *
	 * This UUID is generated on export and reused on import so events can be
	 * matched between installations without relying on database IDs.
	 */
	const UUID_META_KEY = '_event_export_uuid';

	/**
	 * Meta keys that are exported/imported for each event.
	 *
	 * These are the event-specific fields managed by the importer plugin and
	 * consumed by the theme. System/WordPress meta is intentionally excluded.
	 *
	 * @var array
	 */
	protected $export_meta_keys = array(
		'_event_date',
		'_event_time',
		'_event_start_time',
		'_event_end_date',
		'_event_end_time',
		'_event_location',
		'_event_venue',
		'_event_address',
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
		'_event_review_note',
	);

	/**
	 * Taxonomies that are exported/imported for each event.
	 *
	 * @var array
	 */
	protected $export_taxonomies = array(
		'conexao_category',
		'conexao_county',
		'conexao_tag',
		'conexao_town',
	);

	/**
	 * Get the total number of events available for export.
	 *
	 * @return int
	 */
	public function count_events() {
		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Build the full export payload for all events.
	 *
	 * @return array{
	 *   manifest: array,
	 *   events: array
	 * }
	 */
	public function build_export() {
		$events = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $post ) {
			$events[] = $this->export_event( $post );
		}

		return array(
			'manifest' => array(
				'format'       => self::FORMAT,
				'version'      => self::FORMAT_VERSION,
				'exported_at'  => current_time( 'c' ),
				'source_url'   => home_url(),
				'event_count'  => count( $events ),
			),
			'events'   => $events,
		);
	}

	/**
	 * Export a single event post into a portable array.
	 *
	 * @param WP_Post $post Event post object.
	 * @return array
	 */
	protected function export_event( $post ) {
		$uuid = get_post_meta( $post->ID, self::UUID_META_KEY, true );
		if ( empty( $uuid ) ) {
			$uuid = $this->generate_uuid();
			update_post_meta( $post->ID, self::UUID_META_KEY, $uuid );
		}

		// Collect the event meta fields.
		$meta = array();
		foreach ( $this->export_meta_keys as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( '' !== $value && null !== $value && false !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		// Collect taxonomies (term names, not IDs, so they map across installs).
		$taxonomies = array();
		foreach ( $this->export_taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post->ID, $taxonomy );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$taxonomies[ $taxonomy ] = wp_list_pluck( $terms, 'name' );
			} else {
				$taxonomies[ $taxonomy ] = array();
			}
		}

		// Featured image / banner info.
		$featured_image = $this->export_featured_image( $post );

		return array(
			'uuid'          => $uuid,
			'post'          => array(
				'title'    => $post->post_title,
				'content'  => $post->post_content,
				'excerpt'  => $post->post_excerpt,
				'status'   => $post->post_status,
				'slug'     => $post->post_name,
				'date'     => $post->post_date,
				'modified' => $post->post_modified,
			),
			'meta'          => $meta,
			'taxonomies'    => $taxonomies,
			'featured_image' => $featured_image,
		);
	}

	/**
	 * Export the featured image / banner references for an event.
	 *
	 * The external source URL (if known) is the most portable reference because
	 * it can be re-downloaded on the destination site. The local attachment URL
	 * is included as a fallback but is not relied upon.
	 *
	 * @param WP_Post $post Event post object.
	 * @return array
	 */
	protected function export_featured_image( $post ) {
		$result = array(
			'source_url' => '',
			'banner_url' => '',
			'alt'        => '',
		);

		$banner_url = get_post_meta( $post->ID, '_event_banner', true );
		if ( $banner_url ) {
			$result['banner_url'] = $banner_url;
		}

		$attachment_id = get_post_meta( $post->ID, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true );
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			$attachment_id = get_post_thumbnail_id( $post->ID );
		}

		if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
			$source_url = get_post_meta( $attachment_id, '_event_source_url', true );
			if ( $source_url ) {
				$result['source_url'] = $source_url;
			}

			$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( $alt ) {
				$result['alt'] = $alt;
			}
		}

		// Prefer the external source URL as the primary reference.
		if ( empty( $result['source_url'] ) && $banner_url ) {
			$result['source_url'] = $banner_url;
		}

		return $result;
	}

	/**
	 * Generate a UUID v4 string.
	 *
	 * @return string
	 */
	protected function generate_uuid() {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf(
			'%s%s-%s-%s-%s-%s%s%s',
			str_split( bin2hex( $data ), 4 )
		);
	}

	/**
	 * Stream the export file to the browser as a download.
	 *
	 * @return void
	 */
	public function download() {
		$payload = $this->build_export();

		$filename = 'conexao-events-' . gmdate( 'Y-m-d-His' ) . '.json';

		nocache_headers();

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( wp_json_encode( $payload ) ) );
		header( 'X-Content-Type-Options: nosniff' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}
}
