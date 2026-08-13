<?php
/**
 * Course image handler.
 *
 * Downloads external course images into the WordPress Media Library
 * and manages attachment IDs for course banners.
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Course_Image_Handler {

	/**
	 * Meta key for storing the banner attachment ID.
	 */
	const ATTACHMENT_META_KEY = '_course_banner_attachment_id';

	/**
	 * Download an external image URL into the WordPress Media Library.
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

		// Ensure we have the required WordPress functions.
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Validate the URL before attempting download.
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return 0;
		}

		// Download the image.
		$attachment_id = media_sideload_image( $url, $post_id, $title, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}

		if ( ! $attachment_id || ! is_numeric( $attachment_id ) ) {
			return 0;
		}

		// Store the source URL so we can avoid re-downloading the same image.
		update_post_meta( $attachment_id, '_course_source_url', $url );

		return (int) $attachment_id;
	}

	/**
	 * Get an existing attachment ID for a source URL.
	 *
	 * @param string $url     Source image URL.
	 * @param int    $post_id Course post ID.
	 * @return int Attachment ID or 0.
	 */
	public function get_attachment_id_for_url( $url, $post_id ) {
		// First check if the course already has a banner attachment.
		$existing = get_post_meta( $post_id, self::ATTACHMENT_META_KEY, true );
		if ( $existing && wp_attachment_is_image( $existing ) ) {
			$source_url = get_post_meta( $existing, '_course_source_url', true );
			if ( $source_url === $url ) {
				return (int) $existing;
			}
		}

		// Search for an existing attachment with this source URL.
		$attachments = get_posts( array(
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
			'numberposts' => 1,
			'meta_query'  => array(
				array(
					'key'   => '_course_source_url',
					'value' => $url,
				),
			),
			'fields'      => 'ids',
		) );

		if ( ! empty( $attachments ) ) {
			return (int) $attachments[0];
		}

		return 0;
	}

	/**
	 * Set the banner attachment for a course.
	 *
	 * Sets both the _course_banner_attachment_id meta and the post thumbnail.
	 *
	 * @param int $post_id       Course post ID.
	 * @param int $attachment_id Attachment ID.
	 */
	public function set_banner_attachment( $post_id, $attachment_id ) {
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::ATTACHMENT_META_KEY, $attachment_id );
		set_post_thumbnail( $post_id, $attachment_id );
	}

	/**
	 * Get the banner attachment ID for a course.
	 *
	 * @param int $post_id Course post ID.
	 * @return int Attachment ID or 0.
	 */
	public function get_banner_attachment_id( $post_id ) {
		$id = get_post_meta( $post_id, self::ATTACHMENT_META_KEY, true );
		if ( $id && wp_attachment_is_image( $id ) ) {
			return (int) $id;
		}

		// Fall back to the post thumbnail.
		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( $thumbnail_id ) {
			return (int) $thumbnail_id;
		}

		return 0;
	}

	/**
	 * Select the best image URL from a set of candidates.
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

		if ( count( $filtered ) === 1 ) {
			return $filtered[0];
		}

		// Try to extract width from URL patterns and pick the widest.
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
	 * @param string $url Image URL.
	 * @return int Width in pixels or 0 if not found.
	 */
	protected function extract_width_from_url( $url ) {
		if ( preg_match( '/[-_](\d{3,5})x\d{3,5}(?=\.\w{2,5}(?:\?|$))/', $url, $m ) ) {
			return (int) $m[1];
		}

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

		$parts = explode( ',', $srcset );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}

			$tokens = preg_split( '/\s+/', $part );
			if ( ! empty( $tokens[0] ) ) {
				$urls[] = trim( $tokens[0] );
			}
		}

		return $urls;
	}
}