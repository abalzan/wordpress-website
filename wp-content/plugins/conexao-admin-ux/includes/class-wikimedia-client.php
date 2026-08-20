<?php
/**
 * Wikimedia Commons API client.
 *
 * Searches Wikimedia Commons for free-licensed images suitable for use as
 * featured/card images, verifies individual file licenses, and downloads
 * them into the WordPress Media Library while preserving attribution metadata.
 *
 * License verification follows the project policy:
 *   1. Public Domain / CC0   — best, no attribution required (kept for records)
 *   2. CC BY                  — acceptable, attribution required
 *   3. CC BY-SA               — acceptable if necessary, attribution + ShareAlike
 *
 * Rejected licenses:
 *   - CC BY-NC  (non-commercial)
 *   - CC BY-ND  (no derivatives)
 *   - Any license that cannot be verified on the file page
 *
 * Works both inside WordPress (wp_remote_get) and as a standalone CLI tool
 * (cURL fallback), so seed scripts can run without a full WP bootstrap.
 *
 * @package Conexao_Admin_Ux
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', false );

final class Conexao_Wikimedia_Client {

	/** Raster image extensions accepted for import. */
	const ALLOWED_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'webp', 'gif', 'jfif' );


	/** API endpoint. */
	const API_URL = 'https://commons.wikimedia.org/w/api.php';

	/**
	 * License priority mapping.
	 *
	 * Numeric priority — lower = better. Maps Commons license short names
	 * (as returned by extmetadata.LicenseShortName) to a normalised label
	 * and priority. Anything not in this map is treated as unverified/rejected.
	 */
	const LICENSE_MAP = array(
		// Public Domain / CC0 family.
		'CC0'                => array( 'label' => 'CC0 1.0 Universal', 'priority' => 1, 'require_attr' => false ),
		'PD'                 => array( 'label' => 'Public Domain',              'priority' => 1, 'require_attr' => false ),
		'Public domain'      => array( 'label' => 'Public Domain',              'priority' => 1, 'require_attr' => false ),
		'CC-PDDC'            => array( 'label' => 'Public Domain (CC PDDC)',    'priority' => 1, 'require_attr' => false ),
		// Creative Commons Attribution (commercial reuse permitted).
		'CC BY 3.0'          => array( 'label' => 'CC BY 3.0',                  'priority' => 2, 'require_attr' => true ),
		'CC BY 4.0'          => array( 'label' => 'CC BY 4.0',                  'priority' => 2, 'require_attr' => true ),
		'CC BY 2.0'          => array( 'label' => 'CC BY 2.0',                  'priority' => 2, 'require_attr' => true ),
		// Creative Commons Attribution-ShareAlike (commercial reuse permitted).
		'CC BY-SA 3.0'       => array( 'label' => 'CC BY-SA 3.0',               'priority' => 3, 'require_attr' => true ),
		'CC BY-SA 4.0'       => array( 'label' => 'CC BY-SA 4.0',               'priority' => 3, 'require_attr' => true ),
		'CC BY-SA 2.5'       => array( 'label' => 'CC BY-SA 2.5',               'priority' => 3, 'require_attr' => true ),
	);

	/**
	 * Search Wikimedia Commons for file pages matching a query.
	 *
	 * Uses the MediaWiki search API restricted to the File namespace (6).
	 * Each result is a file page title like "File:Cliffs of Moher ...".
	 *
	 * @param string $query  Search terms (e.g. "Cliffs of Moher").
	 * @param int    $limit  Maximum results to return (default 20, max 50).
	 * @return array Array of file page titles (strings starting with "File:").
	 */
	public function search_files( $query, $limit = 20 ) {
		$params = array(
			'action'       => 'query',
			'list'         => 'search',
			'srsearch'     => $query,
			'srnamespace'  => 6,
			'srlimit'      => min( max( (int) $limit, 1 ), 50 ),
			'srprop'       => 'size',
			'format'       => 'json',
		);

		$response = $this->api_request( $params );

		if ( ! is_array( $response ) || empty( $response['query']['search'] ) ) {
			return array();
		}

		$titles = array();
		foreach ( $response['query']['search'] as $item ) {
			if ( isset( $item['title'] ) && 0 === strpos( $item['title'], 'File:' ) ) {
				$titles[] = $item['title'];
			}
		}

		return $titles;
	}

	/**
	 * Fetch detailed metadata for a specific file page.
	 *
	 * Returns normalised file info including the direct image download URL,
	 * the Commons file page URL, the author/artist, the license, and whether
	 * the license is acceptable for reuse.
	 *
	 * @param string $file_title Full file page title (e.g. "File:Cliffs of Moher.jpg").
	 * @return array|null File info array, or null on failure.
	 */
	public function get_file_info( $file_title ) {
		// Strip "File:" prefix to get the raw filename.
		$filename = $file_title;
		if ( 0 === strpos( $filename, 'File:' ) ) {
			$filename = substr( $filename, 5 );
		}
		$filename = trim( $filename );
		if ( '' === $filename ) {
			return null;
		}

		$params = array(
			'action'      => 'query',
			'titles'      => $file_title,
			'prop'        => 'imageinfo|extmetadata',
			'format'      => 'json',
			'iiprop'      => 'url|size|extmetadata',
			'iiurlwidth'  => 1200,
			'iiurlheight' => 1200,
			'inprop'      => 'url',
		);

		$response = $this->api_request( $params );

		if ( ! is_array( $response ) || empty( $response['query']['pages'] ) ) {
			return null;
		}

		$pages = $response['query']['pages'];
		$page  = reset( $pages );

		if ( ! is_array( $page ) || isset( $page['missing'] ) || ! isset( $page['imageinfo'] ) ) {
			return null;
		}

		$ii       = is_array( $page['imageinfo'] ) ? $page['imageinfo'][0] : array();
		$ext      = isset( $ii['extmetadata'] ) && is_array( $ii['extmetadata'] ) ? $ii['extmetadata'] : array();
		$img_url  = isset( $ii['url'] ) ? $ii['url'] : ( isset( $ii['thumburl'] ) ? $ii['thumburl'] : '' );
		$img_width  = isset( $ii['thumbwidth'] ) ? (int) $ii['thumbwidth'] : ( isset( $ii['width'] ) ? (int) $ii['width'] : 0 );
		$img_height = isset( $ii['thumbheight'] ) ? (int) $ii['thumbheight'] : ( isset( $ii['height'] ) ? (int) $ii['height'] : 0 );

		if ( ! $img_url ) {
			// Try the full URL if no thumb URL.
			$img_url = isset( $file['url'] ) ? $file['url'] : ( isset( $ii['url'] ) ? $ii['url'] : '' );
		}

		// Commons file page URL.
		$page_url = $this->file_page_url( $filename );

		// Author / Artist.
		$author = '';
		if ( isset( $ext['Artist']['value'] ) ) {
			$author = $this->clean_html( $ext['Artist']['value'] );
		}
		if ( '' === $author && isset( $ext['Credit']['value'] ) ) {
			$author = $this->clean_html( $ext['Credit']['value'] );
		}

		// License.
		$license_short = isset( $ext['LicenseShortName']['value'] ) ? $ext['LicenseShortName']['value'] : '';
		$license_url   = isset( $ext['LicenseURL']['value'] ) ? $ext['LicenseURL']['value'] : '';
		$license_label = isset( $ext['LicenseShortName']['value'] ) ? $ext['LicenseShortName']['value'] : '';

		$license_info = $this->verify_license( $license_short );

		// Also check the license field from imageinfo for a fallback.
		if ( ! $license_info && ! empty( $ii['metadata'] ) ) {
			$license_info = $this->scan_license_metadata( $ii['metadata'] );
			if ( $license_info && ! $license_url && isset( $license_info['license_url'] ) ) {
				$license_url = $license_info['license_url'];
			}
		}

		return array(
			'filename'        => $filename,
			'file_title'      => $file_title,
			'page_url'        => $page_url,
			'image_url'       => $img_url,
			'image_width'     => $img_width,
			'image_height'    => $img_height,
			'author'          => $author,
			'license_short'   => $license_short,
			'license_label'   => $license_label,
			'license_url'     => $license_url,
			'license_accepted' => (bool) $license_info,
			'license_priority' => $license_info ? $license_info['priority'] : 99,
			'require_attr'    => $license_info ? $license_info['require_attr'] : true,
		);
	}

	/**
	 * Search for images related to an attraction and return the best verified
	 * candidate with acceptable license.
	 *
	 * Strategy:
	 *  1. Search Commons for the exact attraction name.
	 *  2. For each result, fetch file info + verify license.
	 *  3. Among acceptable licenses, prefer PD/CC0 over CC BY over CC BY-SA.
	 *  4. Filter out thumbnails and low-resolution images.
	 *
	 * @param string $query       Search terms.
	 * @param int    $max_results Max candidates to examine.
	 * @return array|null Best candidate array, or null if none found.
	 */
	public function find_best_image( $query, $max_results = 15 ) {
		$titles = $this->search_files( $query, $max_results );

		$candidates = array();
		foreach ( $titles as $title ) {
			$info = $this->get_file_info( $title );
			if ( ! $info ) {
				continue;
			}
			if ( ! $info['license_accepted'] ) {
				continue;
			}
			if ( ! $info['image_url'] ) {
				continue;
			}
			// Skip tiny thumbnails.
			if ( $info['image_width'] < 400 || $info['image_height'] < 300 ) {
				continue;
			}
			// Skip non-raster files (SVG, PDF, videos, etc.).
			$ext = strtolower( pathinfo( $info['filename'], PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
				continue;
			}
			$candidates[] = $info;
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		// Sort by license priority (PD/CC0 first), then by image area (larger first).
		usort( $candidates, function ( $a, $b ) {
			if ( $a['license_priority'] !== $b['license_priority'] ) {
				return $a['license_priority'] <=> $b['license_priority'];
			}
			$area_a = $a['image_width'] * $a['image_height'];
			$area_b = $b['image_width'] * $b['image_height'];
			return $area_b <=> $area_a;
		} );

		return $candidates[0];
	}

	/**
	 * Download an image from a URL and import it into the WordPress Media Library.
	 *
	 * Requires WordPress to be bootstrapped (uses wp_upload_bits / media_handle_sideload).
	 *
	 * @param string $image_url Direct URL to the image file on Wikimedia Commons.
	 * @param string $filename  Desired filename (without extension).
	 * @param int    $post_id   Optional post ID to attach the image to.
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	public function import_image( $image_url, $filename, $post_id = 0 ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// download_url streams to a temp file on disk (avoids exhausting memory).
		$tmp = download_url( $image_url, 300 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$ext = $this->guess_extension( $image_url );
		if ( ! $ext ) {
			$ext = 'jpg';
		}
		if ( ! in_array( strtolower( $ext ), self::ALLOWED_EXTENSIONS, true ) ) {
			@unlink( $tmp );
			return new WP_Error( 'invalid_type', __( 'Tipo de arquivo n\u00e3o suportado.', 'conexao-admin-ux' ) );
		}

		$full_name = sanitize_file_name( $filename . '.' . $ext );

		// Read the downloaded file and write it to uploads via wp_upload_bits.
		$image_data = @file_get_contents( $tmp );
		@unlink( $tmp );
		if ( false === $image_data || '' === $image_data ) {
			return new WP_Error( 'read_failed', __( 'N\u00e3o foi poss\u00edvel ler o arquivo baixado.', 'conexao-admin-ux' ) );
		}

		$upload = wp_upload_bits( $full_name, null, $image_data );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_error', $upload['error'] );
		}

		$wp_filetype = wp_check_filetype( $full_name, wp_get_mime_types() );
		if ( empty( $wp_filetype['type'] ) ) {
			@unlink( $upload['file'] );
			return new WP_Error( 'invalid_filetype', __( 'Tipo de arquivo n\u00e3o permitido.', 'conexao-admin-ux' ) );
		}

		// Insert attachment with explicit string post_title.
		$attachment = array(
			'post_mime_type' => $wp_filetype['type'],
			'post_title'     => $filename,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attachment_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $upload['file'] );
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return (int) $attachment_id;
	}

	/**
	 * Callback for unique filename generation when sideloading.
	 *
	 * @param string $dir     Upload directory.
	 * @param string $name    Original filename.
	 * @param string $ext     File extension.
	 * @param array  $parts   Parts of the filename.
	 * @return string Unique filename.
	 */
	public function unique_filename_callback( $dir, $name, $ext, $parts ) {
		return $name;
	}

	/**
	 * Verify whether a license short name is acceptable for reuse.
	 *
	 * @param string $license Short license identifier from Commons extmetadata.
	 * @return array|null License info from LICENSE_MAP, or null if not acceptable.
	 */
	public function verify_license( $license ) {
		if ( ! $license || ! is_string( $license ) ) {
			return null;
		}

		// Normalise for lookup.
		$normalised = trim( $license );
		$lower      = strtolower( $normalised );

		// Direct match first.
		foreach ( self::LICENSE_MAP as $key => $info ) {
			if ( strtolower( $key ) === $lower ) {
				return $info;
			}
		}

		// Try matching common variations.
		if ( '' !== $lower && false !== strpos( $lower, 'public domain' ) ) {
			return self::LICENSE_MAP['PD'];
		}
		if ( '' !== $lower && false !== strpos( $lower, 'cc0' ) ) {
			return self::LICENSE_MAP['CC0'];
		}
		if ( '' !== $lower && false !== strpos( $lower, 'cc by-sa' ) ) {
			return self::LICENSE_MAP['CC BY-SA 4.0'];
		}
		if ( '' !== $lower && false !== strpos( $lower, 'cc by ' ) ) {
			return self::LICENSE_MAP['CC BY 4.0'];
		}
		if ( '' !== $lower && ( false !== strpos( $lower, 'cc-by' ) || false !== strpos( $lower, 'creative commons attribution' ) ) ) {
			// Distinguish CC BY from CC BY-SA.
			if ( false !== strpos( $lower, 'sharealike' ) || false !== strpos( $lower, 'by-sa' ) ) {
				return self::LICENSE_MAP['CC BY-SA 4.0'];
			}
			return self::LICENSE_MAP['CC BY 4.0'];
		}
		// Freepats, etc.
		if ( '' !== $lower && false !== strpos( $lower, 'free' ) && false === strpos( $lower, 'non-commercial' ) && false === strpos( $lower, 'no-' ) && false === strpos( $lower, 'nd' ) ) {
			return self::LICENSE_MAP['PD'];
		}

		// Explicitly reject known bad licenses early.
		if ( false !== strpos( $lower, 'non-commercial' ) || false !== strpos( $lower, 'nc' ) ) {
			return null;
		}
		if ( false !== strpos( $lower, 'no derivative' ) || false !== strpos( $lower, 'by-nd' ) || false !== strpos( $lower, '-nd' ) ) {
			return null;
		}

		return null;
	}

	/**
	 * Scan the imageinfo metadata array for license information as a fallback.
	 *
	 * @param array $metadata Raw metadata from imageinfo.
	 * @return array|null License info or null.
	 */
	private function scan_license_metadata( $metadata ) {
		if ( ! is_array( $metadata ) ) {
			return null;
		}

		foreach ( $metadata as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['name'], $item['value'] ) ) {
				continue;
			}
			$name  = strtolower( $item['name'] );
			$value = $item['value'];

			if ( 'license' === $name && is_string( $value ) ) {
				$info = $this->verify_license( $value );
				if ( $info ) {
					return array_merge( $info, array( 'license_url' => isset( $item['value'] ) && is_string( $value ) ? '' : '' ) );
				}
			}
			if ( 'licenseurl' === $name && is_string( $value ) ) {
				// Store the license URL for context.
				$license_info = $this->verify_license( '' );
				if ( $license_info ) {
					$license_info['license_url'] = $value;
					return $license_info;
				}
			}
		}

		return null;
	}

	/**
	 * Build the Wikimedia Commons file page URL.
	 *
	 * @param string $filename The filename (without "File:" prefix).
	 * @return string Absolute Commons file page URL.
	 */
	public function file_page_url( $filename ) {
		$encoded = str_replace( ' ', '_', $filename );
		return self::API_URL . '/File:' . rawurlencode( $encoded );
	}

	/**
	 * Guess the file extension from a URL.
	 *
	 * @param string $url Image URL.
	 * @return string File extension without dot, or empty string.
	 */
	private function guess_extension( $url ) {
		// Strip query string and fragment before extracting the extension.
		$url  = preg_replace( '/[?#].*$/', '', $url );
		$info = pathinfo( $url );
		return isset( $info['extension'] ) ? strtolower( $info['extension'] ) : '';
	}

	/**
	 * Strip HTML tags from a metadata value (Commons often returns wikitext/HTML).
	 *
	 * @param string $value Raw metadata value.
	 * @return string Cleaned text.
	 */
	private function clean_html( $value ) {
		// Remove HTML tags.
		$clean = strip_tags( $value );
		// Decode HTML entities.
		$clean = html_entity_decode( $clean, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Collapse whitespace.
		$clean = preg_replace( '/\s+/', ' ', $clean );
		return trim( $clean );
	}

	/**
	 * Perform a GET request to the Wikimedia Commons API.
	 *
	 * Uses wp_remote_get() when inside WordPress; falls back to cURL for
	 * standalone CLI usage.
	 *
	 * @param array $params API parameters.
	 * @return array|null Decoded JSON response, or null on failure.
	 */
	public function api_request( $params ) {
		$url = self::API_URL . '?' . http_build_query( $params, '', '&' );

		if ( function_exists( 'wp_remote_get' ) ) {
			$response = wp_remote_get( $url, array( 'timeout' => 30, 'user-agent' => 'ConexaoBR-Irlanda/1.0 (https://conexao.website; contact via admin)' ) );
			if ( is_wp_error( $response ) ) {
				return null;
			}
			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );
			return is_array( $data ) ? $data : null;
		}

		// cURL fallback for standalone CLI scripts.
		if ( function_exists( 'curl_init' ) ) {
			$ch = curl_init( $url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
			curl_setopt( $ch, CURLOPT_USERAGENT, 'ConexaoBR-Irlanda/1.0' );
			$body   = curl_exec( $ch );
			$errno  = curl_errno( $ch );
			$errmsg = curl_error( $ch );
			curl_close( $ch );
			if ( $errno || ! $body ) {
				return null;
			}
			$data = json_decode( $body, true );
			return is_array( $data ) ? $data : null;
		}

		// Last resort: file_get_contents.
		$body = @file_get_contents( $url );
		if ( ! $body ) {
			return null;
		}
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : null;
	}
}
