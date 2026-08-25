<?php
/**
 * Event image handler.
 *
 * Downloads external event images (e.g. from Eventbrite's CDN) into the
 * WordPress Media Library using WordPress's native media/attachment APIs,
 * validates the downloaded content for safety, and associates the attachment
 * with the event as both the banner attachment and the featured image.
 *
 * The download performs a browser-like HTTP request (custom User-Agent plus an
 * Eventbrite Referer) because Eventbrite's image CDN blocks default PHP
 * clients / hotlinking. WordPress's generic media_sideload_image() sends the
 * default PHP user-agent, which is why imported Eventbrite images failed to
 * display previously.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Image_Handler {

	/**
	 * Meta key for storing the banner attachment ID.
	 *
	 * @var string
	 */
	const ATTACHMENT_META_KEY = '_event_banner_attachment_id';

	/**
	 * Attachment meta key recording the original external source URL.
	 *
	 * Used to avoid re-downloading the same image when multiple events share
	 * the same Eventbrite image.
	 *
	 * @var string
	 */
	const SOURCE_URL_META_KEY = '_event_source_url';

	/**
	 * Browser-like user agent sent to remote image servers.
	 *
	 * Eventbrite's CDN returns 403/empty content to default PHP clients that do
	 * not look like a real browser. This user agent matches what the Eventbrite
	 * client already uses for the discovery page.
	 *
	 * @var string
	 */
	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	/**
	 * Maximum remote image body size in bytes (25 MB).
	 *
	 * @var int
	 */
	const MAX_IMAGE_SIZE = 26214400;

	/**
	 * Download an external image URL into the WordPress Media Library.
	 *
	 * Uses a secure, validated, browser-like HTTP request, writes the file via
	 * wp_upload_bits(), registers the attachment with wp_insert_attachment(),
	 * and generates standard WordPress sizes with
	 * wp_generate_attachment_metadata(). Returns the attachment ID on success.
	 *
	 * @param string $url     External image URL.
	 * @param int    $post_id Post ID to associate the attachment with.
	 * @param string $title   Optional title for the attachment.
	 * @return int Attachment ID or 0 on failure.
	 */
	public function sideload_image( $url, $post_id, $title = '' ) {
		if ( empty( $url ) ) {
			return 0;
		}

		// Don't re-download if we already have a valid attachment for this URL.
		$existing_id = $this->get_attachment_id_for_url( $url, $post_id );
		if ( $existing_id && wp_attachment_is_image( $existing_id ) ) {
			return $existing_id;
		}

		// Validate the URL before any network I/O.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$this->log( 'error', 'Invalid remote image URL.', $url, $post_id );
			return 0;
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			$this->log( 'error', 'Only http/https image URLs are supported.', $url, $post_id );
			return 0;
		}

		// Ensure the WordPress media helper files are available.
		require_once ABSPATH . 'wp-admin/includes/file.php';  // wp_upload_bits, wp_check_filetype_and_ext.
		require_once ABSPATH . 'wp-admin/includes/image.php'; // wp_generate_attachment_metadata.

		// Download the image with a browser-like request (bypasses CDN hotlink protection).
		$response = wp_remote_get( $url, $this->get_download_args( $url ) );

		if ( is_wp_error( $response ) ) {
			$this->log(
				'error',
				'Failed to download image: ' . $response->get_error_message(),
				$url,
				$post_id
			);
			return 0;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$this->log(
				'error',
				sprintf( 'Image download returned HTTP %d.', $code ),
				$url,
				$post_id
			);
			return 0;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			$this->log( 'error', 'Image download returned an empty body.', $url, $post_id );
			return 0;
		}

		// Safety cap on the downloaded body size.
		if ( strlen( $body ) > self::MAX_IMAGE_SIZE ) {
			$this->log( 'warning', 'Remote image exceeds the maximum size limit.', $url, $post_id );
			return 0;
		}

		// Verify the remote Content-Type header when provided.
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( is_array( $content_type ) ) {
			$content_type = $content_type[0];
		}
		$content_type = strtolower( trim( strtok( (string) $content_type, ';' ) ) );

		// Verify the actual file content via magic bytes (the definitive check).
		$detected = $this->detect_image_type( $body );
		if ( ! $detected ) {
			$this->log(
				'error',
				'Downloaded content is not a supported image (JPEG, PNG, WebP, or GIF).',
				$url,
				$post_id
			);
			return 0;
		}

		// Reject when the server's Content-Type strongly disagrees with the real bytes.
		if ( ! empty( $content_type ) && ! $this->compatible_mimes( $content_type, $detected['mime'] ) ) {
			$this->log(
				'error',
				'Downloaded image content does not match the remote Content-Type.',
				$url,
				$post_id
			);
			return 0;
		}

		// Build a safe, unique filename (source-URL hash avoids duplicates).
		$filename = $this->build_filename( $title, $detected['ext'], $url );

		$up_dir = wp_upload_dir();
		if ( $up_dir['error'] ) {
			$this->log( 'error', 'Uploads directory error: ' . $up_dir['error'], $url, $post_id );
			return 0;
		}
		$filename = wp_unique_filename( $up_dir['path'], $filename );

		// Write the file to the Media Library using the core upload API.
		$upload = wp_upload_bits( $filename, null, $body );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			$message = ! empty( $upload['error'] ) ? $upload['error'] : __( 'Unknown upload error.', 'conexao-event-importer' );
			$this->log( 'error', 'Failed to write image file: ' . $message, $url, $post_id );
			return 0;
		}

		$file_path = $upload['file'];

		// Final security gate: let WordPress core validate extension + MIME.
		$wp_type = wp_check_filetype_and_ext( $file_path, $filename );
		if ( empty( $wp_type['ext'] ) || empty( $wp_type['type'] ) ) {
			@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoDiscouragedPHPFunctions
			$this->log( 'error', 'WordPress core rejected the downloaded image file type.', $url, $post_id );
			return 0;
		}

		$mime = $wp_type['type'];

		$attachment_title = $title ? trim( (string) $title ) : __( 'Event Image', 'conexao-event-importer' );

		// Create the attachment record in the Media Library.
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => sanitize_text_field( $attachment_title ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file_path,
			absint( $post_id )
		);

		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			@unlink( $file_path ); // phpcs:ignore WordPress.PHPFunctions.NotDiscouragedPHPFunctions
			$message = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : __( 'Unknown error.', 'conexao-event-importer' );
			$this->log( 'error', 'Failed to create attachment: ' . $message, $url, $post_id );
			return 0;
		}

		// Generate standard WordPress image sizes + thumbnails.
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		if ( ! empty( $metadata ) && ! is_wp_error( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// Record the original source URL so the same image is never downloaded twice.
		update_post_meta( $attachment_id, self::SOURCE_URL_META_KEY, esc_url_raw( $url ) );

		return (int) $attachment_id;
	}

	/**
	 * Create a Media Library attachment from base64-encoded image data.
	 *
	 * Used by the event import to recreate attachments from the embedded
	 * image bytes in an export file — no external HTTP requests required.
	 * The content is validated via magic bytes before being written, and
	 * WordPress core re-validates the file type after writing.
	 *
	 * @param string $data_base64 Base64-encoded image bytes.
	 * @param int    $post_id     Post ID to associate the attachment with.
	 * @param string $title       Optional title for the attachment.
	 * @param string $filename    Optional original filename (extension is used).
	 * @param string $source_url  Optional original external source URL (recorded as meta).
	 * @return int Attachment ID or 0 on failure.
	 */
	public function create_attachment_from_base64( $data_base64, $post_id, $title = '', $filename = '', $source_url = '' ) {
		if ( empty( $data_base64 ) || ! is_string( $data_base64 ) ) {
			return 0;
		}

		$bytes = base64_decode( $data_base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Portable binary transport in JSON.

		if ( false === $bytes || '' === $bytes ) {
			$this->log( 'error', 'Embedded image data could not be decoded.', '', $post_id );
			return 0;
		}

		if ( strlen( $bytes ) > self::MAX_IMAGE_SIZE ) {
			$this->log( 'warning', 'Embedded image exceeds the maximum size limit.', '', $post_id );
			return 0;
		}

		// Verify the actual file content via magic bytes (the definitive check).
		$detected = $this->detect_image_type( $bytes );
		if ( ! $detected ) {
			$this->log( 'error', 'Embedded image data is not a supported image (JPEG, PNG, WebP, or GIF).', '', $post_id );
			return 0;
		}

		// Ensure the WordPress media helper files are available.
		require_once ABSPATH . 'wp-admin/includes/file.php';  // wp_upload_bits, wp_check_filetype_and_ext.
		require_once ABSPATH . 'wp-admin/includes/image.php'; // wp_generate_attachment_metadata.

		// Build a safe filename. Prefer the exported filename's extension;
		// fall back to the extension detected from the magic bytes.
		$base_name = $filename ? sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ) : '';
		if ( empty( $base_name ) ) {
			$base_name = sanitize_title( $title );
		}
		if ( empty( $base_name ) ) {
			$base_name = 'conexao-event-image';
		}
		if ( $source_url ) {
			$base_name .= '-' . substr( md5( $source_url ), 0, 10 );
		}

		$up_dir = wp_upload_dir();
		if ( $up_dir['error'] ) {
			$this->log( 'error', 'Uploads directory error: ' . $up_dir['error'], '', $post_id );
			return 0;
		}

		$safe_filename = wp_unique_filename( $up_dir['path'], $base_name . '.' . $detected['ext'] );

		// Write the file to the Media Library using the core upload API.
		$upload = wp_upload_bits( $safe_filename, null, $bytes );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			$message = ! empty( $upload['error'] ) ? $upload['error'] : __( 'Unknown upload error.', 'conexao-event-importer' );
			$this->log( 'error', 'Failed to write embedded image file: ' . $message, '', $post_id );
			return 0;
		}

		$file_path = $upload['file'];

		// Final security gate: let WordPress core validate extension + MIME.
		$wp_type = wp_check_filetype_and_ext( $file_path, $safe_filename );
		if ( empty( $wp_type['ext'] ) || empty( $wp_type['type'] ) ) {
			wp_delete_file( $file_path );
			$this->log( 'error', 'WordPress core rejected the embedded image file type.', '', $post_id );
			return 0;
		}

		$attachment_title = $title ? trim( (string) $title ) : __( 'Event Image', 'conexao-event-importer' );

		// Create the attachment record in the Media Library.
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $wp_type['type'],
				'post_title'     => sanitize_text_field( $attachment_title ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file_path,
			absint( $post_id )
		);

		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			$message = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : __( 'Unknown error.', 'conexao-event-importer' );
			$this->log( 'error', 'Failed to create attachment from embedded image: ' . $message, '', $post_id );
			return 0;
		}

		// Generate standard WordPress image sizes + thumbnails.
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		if ( ! empty( $metadata ) && ! is_wp_error( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// Record the original source URL so future imports can match/dedupe.
		if ( $source_url && filter_var( $source_url, FILTER_VALIDATE_URL ) ) {
			update_post_meta( $attachment_id, self::SOURCE_URL_META_KEY, esc_url_raw( $source_url ) );
		}

		return (int) $attachment_id;
	}

	/**
	 * Get the existing attachment ID for a source URL.
	 *
	 * Checks whether this event already has a banner attachment for this URL,
	 * then searches all attachments for the _event_source_url meta.
	 *
	 * @param string $url     Source image URL.
	 * @param int    $post_id Event post ID.
	 * @return int Attachment ID or 0.
	 */
	public function get_attachment_id_for_url( $url, $post_id ) {
		// First check the event's own banner attachment.
		$existing = get_post_meta( $post_id, self::ATTACHMENT_META_KEY, true );
		if ( $existing && wp_attachment_is_image( $existing ) ) {
			if ( $url === get_post_meta( $existing, self::SOURCE_URL_META_KEY, true ) ) {
				return (int) $existing;
			}
		}

		// Search all attachments carrying the same source URL meta.
		$attachments = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'   => self::SOURCE_URL_META_KEY,
						'value' => $url,
					),
				),
			)
		);

		if ( ! empty( $attachments ) ) {
			return (int) $attachments[0];
		}

		return 0;
	}

	/**
	 * Set the banner attachment for an event.
	 *
	 * Sets both the _event_banner_attachment_id meta and the post thumbnail
	 * (featured image) so the event card renders the local WordPress image.
	 *
	 * @param int $post_id       Event post ID.
	 * @param int $attachment_id Attachment ID.
	 */
	public function set_banner_attachment( $post_id, $attachment_id ) {
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::ATTACHMENT_META_KEY, absint( $attachment_id ) );
		set_post_thumbnail( $post_id, absint( $attachment_id ) );
	}

	/**
	 * Get the banner attachment ID for an event.
	 *
	 * @param int $post_id Event post ID.
	 * @return int Attachment ID or 0.
	 */
	public function get_banner_attachment_id( $post_id ) {
		$id = get_post_meta( $post_id, self::ATTACHMENT_META_KEY, true );
		if ( $id && wp_attachment_is_image( $id ) ) {
			return (int) $id;
		}

		// Fall back to the post thumbnail (featured image).
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			return (int) $thumbnail_id;
		}

		return 0;
	}

	/**
	 * Sync a single event's image into the WordPress Media Library.
	 *
	 * Detects whether the event still references an external image URL and,
	 * when it does, downloads it, creates the attachment, and associates it as
	 * the banner + featured image. Events whose banner is already local, and
	 * events without a banner, are skipped. Reused by the admin bulk action.
	 *
	 * @param int $post_id Event post ID.
	 * @return array{status:string, attachment_id:int, message:string}
	 */
	public function sync_event_image( $post_id ) {
		$banner_url = get_post_meta( $post_id, '_event_banner', true );

		if ( empty( $banner_url ) ) {
			return array(
				'status'        => 'skipped',
				'attachment_id' => 0,
				'message'       => __( 'Event has no banner image URL.', 'conexao-event-importer' ),
			);
		}

		// Already served from this WordPress site — nothing to download.
		if ( ! $this->is_external_image_url( $banner_url ) ) {
			return array(
				'status'        => 'skipped',
				'attachment_id' => 0,
				'message'       => __( 'Banner image is already stored locally.', 'conexao-event-importer' ),
			);
		}

		$title         = get_the_title( $post_id );
		$attachment_id = $this->sideload_image( $banner_url, $post_id, $title );

		if ( ! $attachment_id ) {
			return array(
				'status'        => 'failed',
				'attachment_id' => 0,
				'message'       => __( 'Failed to download the external image.', 'conexao-event-importer' ),
			);
		}

		$this->set_banner_attachment( $post_id, $attachment_id );

		return array(
			'status'        => 'ok',
			'attachment_id' => $attachment_id,
			'message'       => __( 'Event image synced into the Media Library.', 'conexao-event-importer' ),
		);
	}

	/**
	 * Determine whether a banner URL points at an external host.
	 *
	 * URLs on the site's own host, the uploads host, or containing
	 * /wp-content/uploads/ are considered local (already imported).
	 *
	 * @param string $url Image URL.
	 * @return bool True when the URL is external and needs importing.
	 */
	public function is_external_image_url( $url ) {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// Any /wp-content/uploads/ URL is a local Media Library file.
		if ( false !== strpos( $url, '/wp-content/uploads/' ) ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! $host ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		$local_hosts = array_filter(
			array(
				! empty( $upload_dir['baseurl'] ) ? strtolower( (string) wp_parse_url( $upload_dir['baseurl'], PHP_URL_HOST ) ) : '',
				strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			)
		);

		return ! in_array( $host, $local_hosts, true );
	}

	/**
	 * Select the best image URL from a set of candidates.
	 *
	 * Given multiple image URLs (e.g., from srcset or different page elements),
	 * select the one most appropriate for an event card banner.
	 *
	 * Prefers images that are:
	 * - From /projects/ path (event images, not logos)
	 * - Higher resolution (wider)
	 * - Not logos or placeholders
	 *
	 * @param string[] $urls Array of candidate image URLs.
	 * @return string Best URL or empty string.
	 */
	public function select_best_image( $urls ) {
		$urls = array_filter( array_unique( array_map( 'trim', $urls ) ) );

		if ( empty( $urls ) ) {
			return '';
		}

		// Filter out logos and known bad paths.
		$filtered = array();
		foreach ( $urls as $url ) {
			if ( empty( $url ) ) {
				continue;
			}
			// Skip logos.
			if ( strpos( $url, '/logos/' ) !== false ) {
				continue;
			}
			// Skip tiny placeholders.
			if ( strpos( $url, 'placeholder' ) !== false || strpos( $url, 'default' ) !== false ) {
				continue;
			}
			$filtered[] = $url;
		}

		if ( empty( $filtered ) ) {
			return '';
		}

		// If only one candidate, use it.
		if ( count( $filtered ) === 1 ) {
			return $filtered[0];
		}

		// Prefer /projects/ path images (Heritage Week event images).
		foreach ( $filtered as $url ) {
			if ( strpos( $url, '/projects/' ) !== false ) {
				return $url;
			}
		}

		// Try to extract width from URL patterns like -740x400.jpg and pick the widest.
		$best_url    = $filtered[0];
		$best_width  = 0;

		foreach ( $filtered as $url ) {
			$width = $this->extract_width_from_url( $url );
			if ( $width > $best_width ) {
				$best_width = $width;
				$best_url   = $url;
			}
		}

		return $best_url;
	}

	/**
	 * Extract the width dimension from an image URL.
	 *
	 * Handles patterns like:
	 * - image-740x400.jpg
	 * - image_740x400.jpg
	 * - image-740w.jpg
	 *
	 * @param string $url Image URL.
	 * @return int Width in pixels or 0 if not found.
	 */
	protected function extract_width_from_url( $url ) {
		// Pattern: -WIDTHxHEIGHT or _WIDTHxHEIGHT before extension.
		if ( preg_match( '/[-_](\d{3,5})x\d{3,5}(?=\.\w{2,5}(?:\?|$))/', $url, $m ) ) {
			return (int) $m[1];
		}

		// Pattern: -WIDTHw (responsive width descriptor in filename).
		if ( preg_match( '/[-_](\d{3,5})w(?=\.\w{2,5}(?:\?|$))/', $url, $m ) ) {
			return (int) $m[1];
		}

		return 0;
	}

	/**
	 * Extract all image URLs from a srcset attribute value.
	 *
	 * @param string $srcset Srcset attribute value.
	 * @return string[] Array of image URLs.
	 */
	public function parse_srcset( $srcset ) {
		$urls = array();

		if ( empty( $srcset ) ) {
			return $urls;
		}

		// srcset format: "url1 1x, url2 2x" or "url1 300w, url2 600w".
		$parts = explode( ',', $srcset );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}

			// Split by whitespace; first token is the URL.
			$tokens = preg_split( '/\s+/', $part );
			if ( ! empty( $tokens[0] ) ) {
				$urls[] = trim( $tokens[0] );
			}
		}

		return $urls;
	}

	/**
	 * Build a safe, unique filename for the imported image.
	 *
	 * The filename is derived from the event title and a short hash of the
	 * source URL, so the same source image never produces a second file.
	 *
	 * @param string $title     Event title.
	 * @param string $extension File extension (without dot).
	 * @param string $url       Source URL.
	 * @return string
	 */
	protected function build_filename( $title, $extension, $url ) {
		$name = sanitize_title( $title );
		$base = $name ? $name : 'conexao-event-image';
		$hash = substr( md5( $url ), 0, 10 );

		return sanitize_file_name( $base . '-' . $hash . '.' . $extension );
	}

	/**
	 * Build the HTTP request arguments for downloading an event image.
	 *
	 * Uses a browser-like User-Agent and, for Eventbrite's CDN, a matching
	 * Referer header to satisfy hotlink protection. Additional filters can
	 * extend the arguments via 'conexao_event_image_download_args'.
	 *
	 * @param string $url Image URL being downloaded.
	 * @return array wp_remote_get() arguments.
	 */
	protected function get_download_args( $url ) {
		$host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$headers = array(
			'Accept'          => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
			'Accept-Language' => 'en-IE,en;q=0.9',
		);

		// Eventbrite's CDN enforces a referer / hotlink check.
		if ( false !== strpos( $host, 'evbuc.com' ) || false !== strpos( $host, 'eventbrite' ) ) {
			$headers['Referer'] = 'https://www.eventbrite.ie/';
		}

		return apply_filters(
			'conexao_event_image_download_args',
			array(
				'timeout'    => 30,
				'redirection' => 5,
				'user-agent' => self::USER_AGENT,
				'headers'    => $headers,
			),
			$url
		);
	}

	/**
	 * Detect the image type from binary content (magic bytes).
	 *
	 * Supports JPEG, PNG, WebP, and GIF. This is the definitive file-type check
	 * before the content is written to the Media Library.
	 *
	 * @param string $body Downloaded binary content.
	 * @return array|null Array with 'ext' and 'mime', or null when unknown.
	 */
	protected function detect_image_type( $body ) {
		if ( ! is_string( $body ) || '' === $body ) {
			return null;
		}

		// JPEG.
		if ( 0 === strpos( $body, "\xFF\xD8\xFF" ) ) {
			return array( 'ext' => 'jpg', 'mime' => 'image/jpeg' );
		}

		// PNG.
		if ( 0 === strpos( $body, "\x89PNG\r\n\x1a\n" ) ) {
			return array( 'ext' => 'png', 'mime' => 'image/png' );
		}

		// GIF87a / GIF89a.
		if ( 0 === strpos( $body, 'GIF87a' ) || 0 === strpos( $body, 'GIF89a' ) ) {
			return array( 'ext' => 'gif', 'mime' => 'image/gif' );
		}

		// WebP (RIFF container + WEBP fourcc).
		if ( 0 === strpos( $body, 'RIFF' ) && strlen( $body ) >= 12 && 'WEBP' === substr( $body, 8, 4 ) ) {
			return array( 'ext' => 'webp', 'mime' => 'image/webp' );
		}

		return null;
	}

	/**
	 * Check whether the server's Content-Type header and the real file bytes
	 * are compatible.
	 *
	 * A missing / generic header (application/octet-stream) is tolerated, as is
	 * an exact match. A strong mismatch (e.g. text/html vs image/jpeg) is a
	 * clear sign the remote server returned an error page, so we reject it.
	 *
	 * @param string $claimed Content-Type header value (lowercase).
	 * @param string $actual  Detected MIME from magic bytes (lowercase).
	 * @return bool
	 */
	protected function compatible_mimes( $claimed, $actual ) {
		$claimed = strtolower( trim( $claimed ) );
		$actual  = strtolower( trim( $actual ) );

		// No header or generic binary — rely on magic bytes.
		if ( '' === $claimed || 'application/octet-stream' === $claimed ) {
			return true;
		}

		if ( $claimed === $actual ) {
			return true;
		}

		// Lenient image/* match: e.g. image/x-png vs image/png.
		if ( 0 === strpos( $claimed, 'image/' ) && 0 === strpos( $actual, 'image/' ) ) {
			$claimed_sub = substr( $claimed, 6 );
			$actual_sub  = substr( $actual, 6 );
			if ( false !== strpos( $claimed_sub, $actual_sub ) || false !== strpos( $actual_sub, $claimed_sub ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Write an entry into the import log, when available.
	 *
	 * @param string $level   Log level (info|warning|error).
	 * @param string $message Human-readable message.
	 * @param string $url     Optional remote URL.
	 * @param int    $post_id Optional event post ID.
	 */
	protected function log( $level, $message, $url = '', $post_id = 0 ) {
		if ( ! class_exists( 'Conexao_Import_Log' ) ) {
			return;
		}

		$context = array();
		if ( $url ) {
			$context['url'] = $url;
		}
		if ( $post_id ) {
			$context['event_id'] = $post_id;
		}

		Conexao_Import_Log::add( 'image_handler', $level, $message, $context );
	}
}