<?php
/**
 * Apoiadores export.
 *
 * Exports all sponsor posts (and their metadata, taxonomies, and the canonical
 * Apoiador image) into a portable JSON file that can be imported into another
 * WordPress installation running the same sponsor structure.
 *
 * Image relationship carried per supporter:
 *
 *   _sponsor_image → Imagem do Apoiador (single portrait artwork used on
 *                    desktop and mobile). Pre-consolidation records still
 *                    holding the old _sponsor_desktop_image /
 *                    _sponsor_mobile_image / _sponsor_logo metas resolve them
 *                    into the canonical image at export time (mobile →
 *                    desktop → legacy logo), so no image is ever lost.
 *
 * The multiple contact/social links ("Contatos" repeater) are carried as a
 * structured per-sponsor "contacts" list of {type, url} rows in stored order.
 *
 * Because the destination site cannot reliably fetch images from the source
 * (and localhost URLs must never leak into production data), the actual image
 * bytes are embedded in the export (base64-encoded). The import side creates
 * real Media Library attachments from this data — no external requests needed.
 * Images are identified by a stable content hash so re-imports and shared
 * assets never duplicate.
 *
 * @package Conexao_Sponsor_Migration
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Sponsor_Exporter {

	/**
	 * Format identifier written into the export manifest.
	 */
	const FORMAT = 'conexao-sponsor-export';

	/**
	 * Export file format version.
	 *
	 * 1.1.0 adds the structured "contacts" collection per sponsor
	 * (multiple contact/social links, see Conexao_Data_Model_Contacts).
	 * Importers remain compatible with 1.0.0 files that lack it.
	 */
	const FORMAT_VERSION = '1.1.0';

	/**
	 * Meta key used to store a stable unique identifier for each sponsor.
	 *
	 * This UUID is generated on export and reused on import so sponsors can be
	 * matched between installations without relying on database IDs (project
	 * rule: local WordPress IDs are never portable migration identifiers).
	 */
	const UUID_META_KEY = '_sponsor_export_uuid';

	/**
	 * Attachment meta key recording the stable content hash of an imported
	 * sponsor image. Used by the importer to reuse existing attachments
	 * instead of creating duplicates on repeated imports.
	 */
	const IMAGE_HASH_META_KEY = '_conexao_import_hash';

	/**
	 * Maximum size of an embedded (base64) image in bytes.
	 *
	 * Images larger than this are exported with their URL only; the import
	 * side falls back to sideloading from the URL when it is reachable.
	 */
	const MAX_EMBEDDED_IMAGE_BYTES = 5242880; // 5 MB raw (~6.7 MB base64).

	/**
	 * Meta keys that are exported/imported for each sponsor.
	 *
	 * System/WordPress meta is intentionally excluded. The canonical image
	 * key ("_sponsor_image") is included so the single-image relationship
	 * survives the migration. The pre-consolidation keys
	 * (_sponsor_desktop_image / _sponsor_mobile_image / _sponsor_logo) are
	 * exported through the structured "images" payload instead.
	 *
	 * @var array
	 */
	protected $export_meta_keys = array(
		'_sponsor_category',
		'_sponsor_type',
		'_sponsor_link',
		'_sponsor_featured',
		'_sponsor_display_order',
		'_sponsor_status',
		'_sponsor_created_date',
		'_sponsor_image',
	);

	/**
	 * Taxonomies that are exported/imported for each sponsor.
	 *
	 * @var array
	 */
	protected $export_taxonomies = array(
		'conexao_category',
		'conexao_county',
	);

	/**
	 * Get the total number of sponsors available for export.
	 *
	 * @return int
	 */
	public function count_sponsors() {
		$query = new WP_Query(
			array(
				'post_type'      => 'sponsor',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Build the full export payload for all sponsors.
	 *
	 * @return array{
	 *   manifest: array,
	 *   sponsors: array
	 * }
	 */
	public function build_export() {
		$sponsors = array();

		$query = new WP_Query(
			array(
				'post_type'      => 'sponsor',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $post ) {
			$sponsors[] = $this->export_sponsor( $post );
		}

		return array(
			'manifest' => array(
				'format'       => self::FORMAT,
				'version'      => self::FORMAT_VERSION,
				'exported_at'  => current_time( 'c' ),
				'source_url'   => home_url(),
				'sponsor_count' => count( $sponsors ),
			),
			'sponsors' => $sponsors,
		);
	}

	/**
	 * Export a single sponsor post into a portable array.
	 *
	 * @param WP_Post $post Sponsor post object.
	 * @return array
	 */
	protected function export_sponsor( $post ) {
		$uuid = get_post_meta( $post->ID, self::UUID_META_KEY, true );
		if ( empty( $uuid ) ) {
			$uuid = $this->generate_uuid();
			update_post_meta( $post->ID, self::UUID_META_KEY, $uuid );
		}

		// Collect the sponsor meta fields.
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

		// The canonical Apoiador image, with the actual bytes embedded so the
		// destination site can create the Media Library attachment locally.
		//
		// Resolution mirrors the front-end/admin fallback chain:
		//   _sponsor_image → _sponsor_mobile_image → _sponsor_desktop_image →
		//   legacy _sponsor_logo
		$canonical_value = get_post_meta( $post->ID, '_sponsor_image', true );
		if ( empty( $canonical_value ) ) {
			$canonical_value = get_post_meta( $post->ID, '_sponsor_mobile_image', true );
		}
		if ( empty( $canonical_value ) ) {
			$canonical_value = get_post_meta( $post->ID, '_sponsor_desktop_image', true );
		}
		if ( empty( $canonical_value ) ) {
			$canonical_value = get_post_meta( $post->ID, '_sponsor_logo', true );
		}

		// Backward compatibility: a record whose artwork lives solely in the
		// WordPress featured image (never managed through the image fields)
		// exports that attachment AS the Imagem do Apoiador.
		if ( empty( $canonical_value ) ) {
			$thumbnail_id = get_post_thumbnail_id( $post->ID );
			if ( $thumbnail_id && wp_attachment_is_image( $thumbnail_id ) ) {
				$canonical_value = (int) $thumbnail_id;
			}
		}

		// Structured contact/social links (repeater). Sponsors saved before
		// this feature simply export an empty list — never a missing key —
		// so a re-import reproduces "no contacts" exactly.
		$contacts = class_exists( 'Conexao_Data_Model_Contacts' )
			? Conexao_Data_Model_Contacts::get( $post->ID )
			: array();

		return array(
			'uuid'     => $uuid,
			'post'     => array(
				'title'    => $post->post_title,
				'content'  => $post->post_content,
				'excerpt'  => $post->post_excerpt,
				'status'   => $post->post_status,
				'slug'     => $post->post_name,
				'date'     => $post->post_date,
				'modified' => $post->post_modified,
			),
			'meta'     => $meta,
			'taxonomies' => $taxonomies,
			'contacts' => $contacts,
			'images'   => array(
				'image' => $this->export_image( $canonical_value ),
			),
		);
	}

	/**
	 * Export one image relationship (attachment ID or legacy URL value).
	 *
	 * @param mixed $value Stored meta value: attachment ID, URL string, or ''.
	 * @return array Image payload ('id' = '' when there is nothing to carry).
	 */
	protected function export_image( $value ) {
		$result = array(
			'id'          => '',
			'url'         => '',
			'alt'         => '',
			'filename'    => '',
			'mime_type'   => '',
			'data_base64' => '',
		);

		if ( empty( $value ) ) {
			return $result;
		}

		$attachment_id = 0;

		if ( is_numeric( $value ) && (int) $value > 0 ) {
			$attachment_id = (int) $value;
		} elseif ( is_string( $value ) && preg_match( '#^https?://#i', $value ) ) {
			// Legacy URL-only value: keep the URL as a fallback reference and
			// try to resolve it to its Media Library attachment.
			$result['url'] = esc_url_raw( $value );
			$attachment_id = (int) attachment_url_to_postid( $value );
		}

		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return $result;
		}

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( $alt ) {
			$result['alt'] = $alt;
		}

		$file = get_attached_file( $attachment_id );
		if ( $file && file_exists( $file ) && is_readable( $file ) ) {
			// Stable content hash: identical artwork (shared logos, re-runs)
			// produces the same id, so imports never duplicate attachments.
			$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
			if ( false !== $bytes ) {
				$result['id'] = md5( $bytes );

				if ( strlen( $bytes ) <= self::MAX_EMBEDDED_IMAGE_BYTES ) {
					$result['data_base64'] = base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Portable binary transport in JSON.
					$result['mime_type']   = get_post_mime_type( $attachment_id );

					$filename = basename( $file );
					if ( $filename ) {
						$result['filename'] = $filename;
					}
				}
			}
		}

		// When the bytes could not be embedded, at least carry the file URL so
		// the importer can attempt a sideload from a reachable source.
		if ( empty( $result['url'] ) ) {
			$result['url'] = wp_get_attachment_url( $attachment_id );
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

		$filename = 'conexao-apoiadores-' . gmdate( 'Y-m-d-His' ) . '.json';

		nocache_headers();

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( wp_json_encode( $payload ) ) );
		header( 'X-Content-Type-Options: nosniff' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}
}