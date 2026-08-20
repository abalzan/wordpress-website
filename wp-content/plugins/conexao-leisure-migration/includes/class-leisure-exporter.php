<?php
/**
 * Lazer (leisure) exporter.
 *
 * Exports every `leisure` post, with all its metadata, taxonomies, featured
 * image references and image source/attribution fields, into a portable JSON
 * file that can be imported into another WordPress installation running the
 * same (or a compatible) leisure structure.
 *
 * The export only ever touches the `leisure` post type — no other content is
 * read or included.
 *
 * @package Conexao_Lazer_Migration
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Lazer_Exporter {

	/**
	 * Format identifier written into the export manifest.
	 */
	const FORMAT = 'conexao-lazer-export';

	/**
	 * Export file format version.
	 */
	const FORMAT_VERSION = '1.0';

	/**
	 * Meta key used to store a stable unique identifier for each leisure post.
	 *
	 * This UUID is generated on export and reused on import so records can be
	 * matched between installations without relying on database IDs.
	 */
	const UUID_META_KEY = '_leisure_export_uuid';

	/**
	 * Meta keys that are exported/imported for each leisure item.
	 *
	 * These are the leisure-specific fields managed by the data model and
	 * consumed by the theme. System/WordPress meta is intentionally excluded.
	 *
	 * @var array
	 */
	protected $export_meta_keys = array(
		// Location / contact.
		'_leisure_county',
		'_leisure_town',
		'_leisure_address',
		'_leisure_website',             // Legacy official website URL.
		'_leisure_official_website',    // Official website URL (primary external destination).
		'_leisure_discover_ireland',    // Discover Ireland reference URL (fallback).
		'_leisure_map_url',

		// Attributes.
		'_leisure_feature',
		'_leisure_free',
		'_leisure_family',
		'_leisure_accessibility',
		'_leisure_pet_friendly',
		'_leisure_indoor',
		'_leisure_outdoor',
		'_leisure_parking',
		'_leisure_booking',
		'_leisure_duration',
		'_leisure_best_time',

		// Image / source fields.
		'_leisure_image_attachment_id', // Local Media Library attachment ID.
		'_leisure_image_external_url',  // External licensed image URL.
		'_leisure_image_source',        // Source label (Discover Ireland, Wikimedia Commons, etc.).
		'_leisure_image_source_url',    // Source page URL (Commons file page / Discover Ireland ref).
		'_leisure_image_author',
		'_leisure_image_license',
		'_leisure_image_attribution',
		'_leisure_image_alt_text',
		'_leisure_image_status',        // none | pending | local | external.
	);

	/**
	 * Taxonomies that are exported/imported for each leisure item.
	 *
	 * @var array
	 */
	protected $export_taxonomies = array(
		'conexao_category',
		'conexao_county',
		'conexao_tag',
	);

	/**
	 * Get the total number of leisure posts available for export.
	 *
	 * @return int
	 */
	public function count_items() {
		$query = new WP_Query(
			array(
				'post_type'      => 'leisure',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Build the full export payload for all leisure posts.
	 *
	 * @return array{manifest:array, items:array}
	 */
	public function build_export() {
		$items = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'leisure',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $post ) {
			$items[] = $this->export_item( $post );
		}

		return array(
			'manifest' => array(
				'format'       => self::FORMAT,
				'version'      => self::FORMAT_VERSION,
				'post_type'    => 'leisure',
				'exported_at'  => current_time( 'c' ),
				'source_url'   => home_url(),
				'item_count'   => count( $items ),
			),
			'items'   => $items,
		);
	}

	/**
	 * Export a single leisure post into a portable array.
	 *
	 * @param WP_Post $post Leisure post object.
	 * @return array
	 */
	protected function export_item( $post ) {
		$uuid = get_post_meta( $post->ID, self::UUID_META_KEY, true );
		if ( empty( $uuid ) ) {
			$uuid = $this->generate_uuid();
			update_post_meta( $post->ID, self::UUID_META_KEY, $uuid );
		}

		// Collect the leisure meta fields.
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

		// Featured image / media references.
		$featured_image = $this->export_featured_image( $post );

		return array(
			'uuid'           => $uuid,
			'post'           => array(
				'title'    => $post->post_title,
				'content'  => $post->post_content,
				'excerpt'  => $post->post_excerpt,
				'status'   => $post->post_status,
				'slug'     => $post->post_name,
				'date'     => $post->post_date,
				'modified' => $post->post_modified,
			),
			'meta'           => $meta,
			'taxonomies'     => $taxonomies,
			'featured_image' => $featured_image,
		);
	}

	/**
	 * Export the featured image / media references for a leisure post.
	 *
	 * For a local Media Library image we export the attachment GUID/URL and
	 * the localhost URL (as a hint). The importer prefers a reachable
	 * (non-localhost) URL when downloading; the localhost URL is never used
	 * on the destination side.
	 *
	 * The external licensed image URL (e.g. a Wikimedia Commons URL that the
	 * site is licensed to hotlink) is exported separately via the meta
	 * `_leisure_image_external_url`; it is preserved, not downloaded, so the
	 * licensing model is kept intact.
	 *
	 * @param WP_Post $post Leisure post object.
	 * @return array
	 */
	protected function export_featured_image( $post ) {
		$result = array(
			'local_attachment_id' => 0,
			'local_url'           => '',
			'localhost_hint'      => '',
			'thumbnail_url'       => '',
			'alt'                 => '',
		);

		$attachment_id = (int) get_post_meta( $post->ID, '_leisure_image_attachment_id', true );
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			$attachment_id = (int) get_post_thumbnail_id( $post->ID );
		}

		if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
			$result['local_attachment_id'] = $attachment_id;

			$url = wp_get_attachment_image_url( $attachment_id, 'full' );
			if ( $url ) {
				$result['local_url'] = $url;
			}

			// Provide the raw GUID as the localhost hint (often the Docker URL).
			$guid = get_the_guid( $attachment_id );
			if ( $guid && filter_var( $guid, FILTER_VALIDATE_URL ) ) {
				$result['localhost_hint'] = $guid;
			}

			$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( $alt ) {
				$result['alt'] = $alt;
			}

			$thumbnail_url = get_the_post_thumbnail_url( $post->ID, 'full' );
			if ( $thumbnail_url ) {
				$result['thumbnail_url'] = $thumbnail_url;
			}
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

		$filename = 'conexao-lazer-' . gmdate( 'Y-m-d-His' ) . '.json';

		nocache_headers();

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( wp_json_encode( $payload ) ) );
		header( 'X-Content-Type-Options: nosniff' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}