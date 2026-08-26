<?php
/**
 * Apoiadores import.
 *
 * Imports a sponsor export file (JSON with embedded base64 image bytes) into
 * this installation. Existing sponsors are matched by their stable export
 * UUID, then slug, then title — never by local database IDs. Both responsive
 * carousel image relationships (Imagem Desktop / Imagem Mobile) and the
 * legacy logo are recreated as local Media Library attachments and assigned
 * to the correct Apoiador. The structured contact/social links ("Contatos"
 * repeater, export format 1.1.0+) are restored per sponsor; legacy 1.0.0
 * exports without contacts leave any existing contacts untouched.
 *
 * @package Conexao_Sponsor_Migration
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Sponsor_Importer {

	/**
	 * Import a sponsor export file.
	 *
	 * @param string $file    Path to the uploaded JSON file.
	 * @param array  $options {
	 *     @type bool   $dry_run            Preview only — write nothing.
	 *     @type string $duplicate_strategy 'update' or 'skip'.
	 * }
	 * @return array Stats: imported/updated/skipped/failed/images_imported/
	 *               images_reused/errors.
	 */
	public function import_file( $file, $options = array() ) {
		$dry_run  = ! empty( $options['dry_run'] );
		$strategy = isset( $options['duplicate_strategy'] ) ? sanitize_key( $options['duplicate_strategy'] ) : 'update';
		if ( ! in_array( $strategy, array( 'update', 'skip' ), true ) ) {
			$strategy = 'update';
		}

		$stats = array(
			'imported'       => 0,
			'updated'        => 0,
			'skipped'        => 0,
			'failed'         => 0,
			'images_imported' => 0,
			'images_reused'  => 0,
			'errors'         => array(),
		);

		$payload = $this->validate_upload( $file );
		if ( is_wp_error( $payload ) ) {
			$stats['errors'][] = $payload->get_error_message();
			return $stats;
		}

		foreach ( $payload['sponsors'] as $sponsor ) {
			try {
				$result = $this->import_sponsor( $sponsor, $strategy, $dry_run, $stats );

				switch ( $result ) {
					case 'created':
						$stats['imported']++;
						break;
					case 'updated':
						$stats['updated']++;
						break;
					case 'skipped':
						$stats['skipped']++;
						break;
					default:
						$stats['failed']++;
						break;
				}
			} catch ( Exception $e ) {
				$stats['failed']++;
				$stats['errors'][] = sprintf(
					/* translators: %1$s: sponsor title, %2$s: error message */
					__( 'Falha ao importar "%1$s": %2$s', 'conexao-sponsor-migration' ),
					isset( $sponsor['post']['title'] ) ? (string) $sponsor['post']['title'] : '?',
					$e->getMessage()
				);
			}
		}

		return $stats;
	}

	/**
	 * Validate an uploaded export file and return its decoded payload.
	 *
	 * @param string $file Path to the uploaded file.
	 * @return array|WP_Error Decoded payload or error.
	 */
	public function validate_upload( $file ) {
		if ( empty( $file ) || ! file_exists( $file ) || ! is_readable( $file ) ) {
			return new WP_Error( 'conexao_sponsor_import', __( 'O arquivo de exportação não pôde ser lido.', 'conexao-sponsor-migration' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local upload for validation.
		$raw = file_get_contents( $file );
		if ( false === $raw || '' === trim( $raw ) ) {
			return new WP_Error( 'conexao_sponsor_import', __( 'O arquivo de exportação está vazio.', 'conexao-sponsor-migration' ) );
		}

		$payload = json_decode( $raw, true );
		if ( null === $payload && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'conexao_sponsor_import', __( 'O arquivo não é um JSON válido.', 'conexao-sponsor-migration' ) );
		}

		if ( empty( $payload['manifest']['format'] ) || Conexao_Sponsor_Exporter::FORMAT !== $payload['manifest']['format'] ) {
			return new WP_Error( 'conexao_sponsor_import', __( 'Formato inválido: este arquivo não é uma exportação de Apoiadores.', 'conexao-sponsor-migration' ) );
		}

		if ( empty( $payload['sponsors'] ) || ! is_array( $payload['sponsors'] ) ) {
			return new WP_Error( 'conexao_sponsor_import', __( 'A exportação não contém apoiadores.', 'conexao-sponsor-migration' ) );
		}

		return $payload;
	}

	/**
	 * Import one sponsor record.
	 *
	 * @param array $sponsor   Exported sponsor payload.
	 * @param string $strategy Duplicate strategy ('update'|'skip').
	 * @param bool  $dry_run   Preview only.
	 * @param array $stats     Running stats (image counters updated by ref).
	 * @return string 'created'|'updated'|'skipped'|'failed'.
	 */
	protected function import_sponsor( $sponsor, $strategy, $dry_run, &$stats ) {
		if ( empty( $sponsor['post']['title'] ) ) {
			throw new Exception( __( 'Registro sem título.', 'conexao-sponsor-migration' ) );
		}

		$uuid      = isset( $sponsor['uuid'] ) ? sanitize_text_field( $sponsor['uuid'] ) : '';
		$existing  = $this->find_existing_sponsor( $sponsor, $uuid );

		if ( $existing && 'skip' === $strategy ) {
			return 'skipped';
		}

		if ( $dry_run ) {
			// Count the images the real run would create/reuse so the preview
			// numbers match the confirmed run.
			foreach ( array( 'desktop', 'mobile', 'legacy_logo' ) as $role ) {
				$image = isset( $sponsor['images'][ $role ] ) ? $sponsor['images'][ $role ] : array();
				if ( empty( $image['id'] ) ) {
					continue;
				}
				if ( $this->find_attachment_by_hash( $image['id'] ) ) {
					$stats['images_reused']++;
				} else {
					$stats['images_imported']++;
				}
			}
			return $existing ? 'updated' : 'created';
		}

		$post_data = array(
			'post_title'   => sanitize_text_field( $sponsor['post']['title'] ),
			'post_content' => wp_kses_post( isset( $sponsor['post']['content'] ) ? $sponsor['post']['content'] : '' ),
			'post_excerpt' => wp_kses_post( isset( $sponsor['post']['excerpt'] ) ? $sponsor['post']['excerpt'] : '' ),
			'post_type'    => 'sponsor',
			'post_status'  => ( isset( $sponsor['post']['status'] ) && 'publish' === $sponsor['post']['status'] ) ? 'publish' : 'draft',
		);

		if ( $existing ) {
			$post_id = $existing->ID;
			wp_update_post( array_merge( array( 'ID' => $post_id ), $post_data ) );
		} else {
			$post_id = wp_insert_post( $post_data );
			if ( ! $post_id || is_wp_error( $post_id ) ) {
				throw new Exception( __( 'Não foi possível criar o post do apoiador.', 'conexao-sponsor-migration' ) );
			}

			// Preserve the exported slug so old single URLs keep resolving.
			if ( ! empty( $sponsor['post']['slug'] ) ) {
				wp_update_post( array(
					'ID'        => $post_id,
					'post_name' => sanitize_title( $sponsor['post']['slug'] ),
				) );
			}

			if ( $uuid ) {
				update_post_meta( $post_id, Conexao_Sponsor_Exporter::UUID_META_KEY, $uuid );
			}
		}

		$this->save_meta( $post_id, $sponsor );
		$this->save_taxonomies( $post_id, $sponsor );
		$this->handle_images( $post_id, $sponsor, $stats );
		$this->save_contacts( $post_id, $sponsor );

		return $existing ? 'updated' : 'created';
	}

	/**
	 * Save the exported contact/social links onto the imported sponsor.
	 *
	 * The "contacts" key carries an ordered list of {type, url} rows and is
	 * sanitized through Conexao_Data_Model_Contacts before storage — the same
	 * rules the admin editor applies. Semantics:
	 *
	 * - Key present (any 1.1.0+ export): rows replace the existing contacts,
	 *   including an empty list (an explicit "no contacts" on the source).
	 * - Key absent (legacy 1.0.0 export): existing contacts are left
	 *   untouched so re-importing old files never destroys newer data.
	 *
	 * @param int   $post_id Sponsor post ID.
	 * @param array $sponsor Exported sponsor payload.
	 */
	protected function save_contacts( $post_id, $sponsor ) {
		if ( ! array_key_exists( 'contacts', $sponsor ) ) {
			return;
		}

		if ( ! class_exists( 'Conexao_Data_Model_Contacts' ) ) {
			return;
		}

		$rows = is_array( $sponsor['contacts'] ) ? $sponsor['contacts'] : array();

		Conexao_Data_Model_Contacts::update( $post_id, $rows );
	}

	/**
	 * Find an existing sponsor matching this export record.
	 *
	 * Matching order: stable export UUID → slug → title. Local IDs are never
	 * used as portable identifiers (project rule).
	 *
	 * @param array  $sponsor Exported sponsor payload.
	 * @param string $uuid    Stable export UUID.
	 * @return WP_Post|null
	 */
	protected function find_existing_sponsor( $sponsor, $uuid ) {
		if ( $uuid ) {
			$found = get_posts( array(
				'post_type'              => 'sponsor',
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'all',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_key'               => Conexao_Sponsor_Exporter::UUID_META_KEY,
				'meta_value'             => $uuid,
			) );
			if ( ! empty( $found ) ) {
				return $found[0];
			}
		}

		if ( ! empty( $sponsor['post']['slug'] ) ) {
			$found = get_posts( array(
				'post_type'              => 'sponsor',
				'post_status'            => 'any',
				'name'                   => sanitize_title( $sponsor['post']['slug'] ),
				'posts_per_page'         => 1,
				'fields'                 => 'all',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			) );
			if ( ! empty( $found ) ) {
				return $found[0];
			}
		}

		if ( ! empty( $sponsor['post']['title'] ) ) {
			$found = get_posts( array(
				'post_type'              => 'sponsor',
				'post_status'            => 'any',
				'title'                  => $sponsor['post']['title'],
				'posts_per_page'         => 1,
				'fields'                 => 'all',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			) );
			if ( ! empty( $found ) ) {
				return $found[0];
			}
		}

		return null;
	}

	/**
	 * Save the exported meta onto the imported sponsor post.
	 *
	 * Image meta keys are handled separately by handle_images() (they must
	 * point at LOCAL attachment IDs), so they are skipped here.
	 *
	 * @param int   $post_id Sponsor post ID.
	 * @param array $sponsor Exported sponsor payload.
	 */
	protected function save_meta( $post_id, $sponsor ) {
		$image_keys = array( '_sponsor_logo', '_sponsor_desktop_image', '_sponsor_mobile_image' );
		$meta       = isset( $sponsor['meta'] ) && is_array( $sponsor['meta'] ) ? $sponsor['meta'] : array();

		foreach ( $meta as $key => $value ) {
			if ( in_array( $key, $image_keys, true ) ) {
				continue;
			}
			update_post_meta( $post_id, $key, sanitize_text_field( (string) $value ) );
		}
	}

	/**
	 * Save the exported taxonomy terms (matched by name).
	 *
	 * @param int   $post_id Sponsor post ID.
	 * @param array $sponsor Exported sponsor payload.
	 */
	protected function save_taxonomies( $post_id, $sponsor ) {
		$taxonomies = isset( $sponsor['taxonomies'] ) && is_array( $sponsor['taxonomies'] ) ? $sponsor['taxonomies'] : array();

		foreach ( $taxonomies as $taxonomy => $term_names ) {
			if ( ! taxonomy_exists( $taxonomy ) || ! is_array( $term_names ) ) {
				continue;
			}

			$term_ids = array();
			foreach ( $term_names as $name ) {
				$name = sanitize_text_field( (string) $name );
				if ( '' === $name ) {
					continue;
				}

				$term = term_exists( $name, $taxonomy );
				if ( ! $term ) {
					$term = wp_insert_term( $name, $taxonomy );
				}
				if ( $term && ! is_wp_error( $term ) ) {
					$term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
				}
			}

			wp_set_object_terms( $post_id, $term_ids, $taxonomy );
		}
	}

	/**
	 * Recreate both responsive carousel images + the legacy logo as local
	 * Media Library attachments and assign them to the sponsor.
	 *
	 * Embedded bytes are the primary source; when absent, a reachable URL is
	 * sideloaded. Localhost URLs are never fetched (project rule: no localhost
	 * URLs in production data). The featured image is set to the effective
	 * desktop artwork (desktop → mobile → legacy logo), mirroring the admin
	 * editor's sync behavior.
	 *
	 * @param int   $post_id Sponsor post ID.
	 * @param array $sponsor Exported sponsor payload.
	 * @param array $stats   Running stats (counters updated by ref).
	 */
	protected function handle_images( $post_id, $sponsor, &$stats ) {
		$roles = array( 'desktop', 'mobile', 'legacy_logo' );
		$keys  = array(
			'desktop'     => '_sponsor_desktop_image',
			'mobile'      => '_sponsor_mobile_image',
			'legacy_logo' => '_sponsor_logo',
		);

		$resolved = array();

		foreach ( $roles as $role ) {
			$image = isset( $sponsor['images'][ $role ] ) && is_array( $sponsor['images'][ $role ] )
				? $sponsor['images'][ $role ]
				: array();

			$attachment_id = 0;

			if ( ! empty( $image['id'] ) ) {
				$attachment_id = $this->ensure_attachment( $image, $post_id, $stats );
			} elseif ( ! empty( $image['url'] ) ) {
				// Legacy URL-only value: resolve against this installation's
				// Media Library first, then attempt a sideload from a
				// non-local URL.
				$attachment_id = (int) attachment_url_to_postid( $image['url'] );
				if ( ! $attachment_id && ! $this->is_local_url( $image['url'] ) ) {
					$attachment_id = $this->sideload_image( $image['url'], $post_id, $stats );
				}
			}

			$resolved[ $role ] = $attachment_id;

			if ( $attachment_id ) {
				update_post_meta( $post_id, $keys[ $role ], $attachment_id );
				if ( ! empty( $image['alt'] ) ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $image['alt'] ) );
				}
			} else {
				delete_post_meta( $post_id, $keys[ $role ] );
			}
		}

		// Featured image mirrors the front-end fallback chain:
		// desktop → mobile → legacy logo.
		$effective = $resolved['desktop'] ? $resolved['desktop']
			: ( $resolved['mobile'] ? $resolved['mobile'] : $resolved['legacy_logo'] );

		if ( $effective ) {
			set_post_thumbnail( $post_id, $effective );
		} else {
			delete_post_thumbnail( $post_id );
		}
	}

	/**
	 * Ensure a Media Library attachment exists for an embedded image payload.
	 *
	 * Reuses an existing attachment carrying the same content hash; otherwise
	 * creates one from the base64 bytes (or sideloads from the URL when the
	 * bytes were too large to embed).
	 *
	 * @param array $image Exported image payload.
	 * @param int   $post_id Parent sponsor post ID.
	 * @param array $stats   Running stats (counters updated by ref).
	 * @return int Attachment ID or 0 on failure.
	 */
	protected function ensure_attachment( $image, $post_id, &$stats ) {
		$hash = sanitize_text_field( (string) $image['id'] );

		$existing = $this->find_attachment_by_hash( $hash );
		if ( $existing ) {
			$stats['images_reused']++;
			return $existing;
		}

		$attachment_id = 0;

		if ( ! empty( $image['data_base64'] ) ) {
			$attachment_id = $this->create_attachment_from_base64(
				$image['data_base64'],
				$post_id,
				isset( $image['filename'] ) ? $image['filename'] : '',
				isset( $image['mime_type'] ) ? $image['mime_type'] : '',
				$hash
			);
		} elseif ( ! empty( $image['url'] ) && ! $this->is_local_url( $image['url'] ) ) {
			$attachment_id = $this->sideload_image( $image['url'], $post_id, $stats, $hash );
		}

		if ( $attachment_id ) {
			$stats['images_imported']++;
		}

		return $attachment_id;
	}

	/**
	 * Find an attachment previously imported with the same content hash.
	 *
	 * @param string $hash Stable content hash.
	 * @return int Attachment ID or 0.
	 */
	protected function find_attachment_by_hash( $hash ) {
		$found = get_posts( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => array(
				array(
					'key'   => Conexao_Sponsor_Exporter::IMAGE_HASH_META_KEY,
					'value' => $hash,
				),
			),
		) );

		return ! empty( $found ) ? (int) $found[0] : 0;
	}

	/**
	 * Create a Media Library attachment from base64-encoded image bytes.
	 *
	 * The content is validated via magic bytes before being written, and
	 * WordPress core re-validates the file type after writing.
	 *
	 * @param string $data_base64 Base64-encoded image bytes.
	 * @param int    $post_id     Parent sponsor post ID.
	 * @param string $filename    Original filename (extension is used).
	 * @param string $mime_type   Exported MIME type (verified against bytes).
	 * @param string $hash        Stable content hash recorded as attachment meta.
	 * @return int Attachment ID or 0 on failure.
	 */
	protected function create_attachment_from_base64( $data_base64, $post_id, $filename, $mime_type, $hash ) {
		$bytes = base64_decode( $data_base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Portable binary transport in JSON.

		if ( false === $bytes || '' === $bytes ) {
			return 0;
		}

		$detected = $this->detect_image_type( $bytes );
		if ( ! $detected ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';  // wp_upload_bits, wp_check_filetype_and_ext.
		require_once ABSPATH . 'wp-admin/includes/image.php'; // wp_generate_attachment_metadata.

		$base_name = $filename ? sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ) : 'apoiador-imagem';
		if ( '' === $base_name ) {
			$base_name = 'apoiador-imagem';
		}

		$up_dir = wp_upload_dir();
		if ( $up_dir['error'] ) {
			return 0;
		}

		$safe_filename = wp_unique_filename( $up_dir['path'], $base_name . '.' . $detected['ext'] );

		$upload = wp_upload_bits( $safe_filename, null, $bytes );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return 0;
		}

		$file_path = $upload['file'];

		$wp_type = wp_check_filetype_and_ext( $file_path, $safe_filename );
		if ( empty( $wp_type['ext'] ) || empty( $wp_type['type'] ) ) {
			wp_delete_file( $file_path );
			return 0;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $wp_type['type'],
				'post_title'     => sanitize_text_field( $base_name ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$file_path,
			absint( $post_id )
		);

		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file_path );
			return 0;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		if ( ! empty( $metadata ) && ! is_wp_error( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		update_post_meta( $attachment_id, Conexao_Sponsor_Exporter::IMAGE_HASH_META_KEY, $hash );

		return (int) $attachment_id;
	}

	/**
	 * Download an image URL into the Media Library (fallback path for images
	 * whose bytes could not be embedded in the export).
	 *
	 * @param string $url    Source image URL (never localhost).
	 * @param int    $post_id Parent sponsor post ID.
	 * @param array  $stats  Running stats (unused here, kept for symmetry).
	 * @param string $hash   Optional content hash to record.
	 * @return int Attachment ID or 0 on failure.
	 */
	protected function sideload_image( $url, $post_id, &$stats, $hash = '' ) {
		unset( $stats );

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) || $this->is_local_url( $url ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$detected = $this->detect_image_type( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Validating downloaded temp file.

		$name = sanitize_file_name( wp_basename( parse_url( $url, PHP_URL_PATH ) ?: 'apoiador-imagem' ) ); // phpcs:ignore WordPress.PHP.DisallowShortTernary.Found -- Intentional default.
		if ( $detected && ! preg_match( '/\.' . preg_quote( $detected['ext'], '/' ) . '$/i', $name ) ) {
			$name .= '.' . $detected['ext'];
		}

		$file_array = array(
			'name'     => $name ? $name : 'apoiador-imagem.jpg',
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, absint( $post_id ) );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}

		if ( $hash ) {
			update_post_meta( $attachment_id, Conexao_Sponsor_Exporter::IMAGE_HASH_META_KEY, sanitize_text_field( $hash ) );
		}

		return (int) $attachment_id;
	}

	/**
	 * Whether a URL points at this installation (or any localhost host).
	 *
	 * Project rule: no localhost URLs in production data — such URLs are never
	 * fetched during import.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	protected function is_local_url( $url ) {
		if ( false !== strpos( $url, '/wp-content/uploads/' ) ) {
			return true;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! $host ) {
			return true;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		$local_hosts = array_filter( array(
			strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
		) );

		return in_array( $host, $local_hosts, true );
	}

	/**
	 * Detect the image type from binary content (magic bytes).
	 *
	 * @param string $bytes Binary content.
	 * @return array|null Array with 'ext' and 'mime', or null when unknown.
	 */
	protected function detect_image_type( $bytes ) {
		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return null;
		}

		if ( 0 === strpos( $bytes, "\xFF\xD8\xFF" ) ) {
			return array( 'ext' => 'jpg', 'mime' => 'image/jpeg' );
		}

		if ( 0 === strpos( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			return array( 'ext' => 'png', 'mime' => 'image/png' );
		}

		if ( 0 === strpos( $bytes, 'GIF87a' ) || 0 === strpos( $bytes, 'GIF89a' ) ) {
			return array( 'ext' => 'gif', 'mime' => 'image/gif' );
		}

		if ( 0 === strpos( $bytes, 'RIFF' ) && strlen( $bytes ) >= 12 && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return array( 'ext' => 'webp', 'mime' => 'image/webp' );
		}

		return null;
	}
}