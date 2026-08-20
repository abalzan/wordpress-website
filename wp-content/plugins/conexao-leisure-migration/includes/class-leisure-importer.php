<?php
/**
 * Lazer (leisure) importer.
 *
 * Imports a portable ZIP package (produced by Conexao_Lazer_Exporter) into
 * the current WordPress installation, recreating leisure posts, metadata,
 * taxonomies and Media Library attachments from the actual image files
 * contained in the package.
 *
 * The importer is idempotent: records are matched by their stable export UUID,
 * then by slug, then by title, so re-importing never creates duplicates.
 * Images are matched by their stable image ID and content hash, so existing
 * attachments are reused rather than duplicated.
 *
 * The importer only ever affects the `leisure` post type, its taxonomies and
 * the Media Library attachments it creates for leisure images.
 *
 * @package Conexao_Lazer_Migration
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Lazer_Importer {

	protected $allowed_meta_keys = array(
		'_leisure_county',
		'_leisure_town',
		'_leisure_address',
		'_leisure_website',
		'_leisure_official_website',
		'_leisure_discover_ireland',
		'_leisure_map_url',
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
		'_leisure_image_attachment_id',
		'_leisure_image_source',
		'_leisure_image_source_url',
		'_leisure_image_author',
		'_leisure_image_license',
		'_leisure_image_attribution',
		'_leisure_image_alt_text',
	);

	protected $supported_taxonomies = array(
		'conexao_category',
		'conexao_county',
		'conexao_tag',
	);

	const MAX_PACKAGE_SIZE = 100 * MB_IN_BYTES;
	const MAX_IMAGE_SIZE   = 25 * MB_IN_BYTES;

	/**
	 * Validate an uploaded/prepared import file.
	 *
	 * Accepts either a $_FILES entry or a file path. Returns true on success
	 * or a WP_Error describing the problem. No data is written during
	 * validation.
	 *
	 * @param array|string $file $_FILES entry or absolute path to a ZIP file.
	 * @return true|WP_Error
	 */
	public function validate_upload( $file ) {
		if ( is_string( $file ) ) {
			return $this->validate_file_path( $file );
		}

		if ( ! isset( $file['error'] ) || is_array( $file['error'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'Invalid file upload.', 'conexao-leisure-migration' ) );
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$messages = array(
				UPLOAD_ERR_INI_SIZE   => __( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'conexao-leisure-migration' ),
				UPLOAD_ERR_FORM_SIZE  => __( 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the form.', 'conexao-leisure-migration' ),
				UPLOAD_ERR_PARTIAL    => __( 'The uploaded file was only partially uploaded.', 'conexao-leisure-migration' ),
				UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'conexao-leisure-migration' ),
				UPLOAD_ERR_NO_TMP_DIR => __( 'Missing a temporary folder.', 'conexao-leisure-migration' ),
				UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'conexao-leisure-migration' ),
				UPLOAD_ERR_EXTENSION  => __( 'A PHP extension stopped the file upload.', 'conexao-leisure-migration' ),
			);
			$message = isset( $messages[ $file['error'] ] ) ? $messages[ $file['error'] ] : __( 'Unknown upload error.', 'conexao-leisure-migration' );
			return new WP_Error( 'upload_error', $message );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'The uploaded file could not be read.', 'conexao-leisure-migration' ) );
		}

		if ( filesize( $file['tmp_name'] ) > self::MAX_PACKAGE_SIZE ) {
			return new WP_Error( 'file_too_large', __( 'The uploaded file is too large. Maximum size is 100 MB.', 'conexao-leisure-migration' ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'zip' !== $ext ) {
			return new WP_Error( 'invalid_type', __( 'Only .zip export packages are supported.', 'conexao-leisure-migration' ) );
		}

		return $this->validate_zip( $file['tmp_name'] );
	}

	protected function validate_file_path( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'file_not_found', __( 'The export file could not be found or read.', 'conexao-leisure-migration' ) );
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'zip' !== $ext ) {
			return new WP_Error( 'invalid_type', __( 'Only .zip export packages are supported.', 'conexao-leisure-migration' ) );
		}

		return $this->validate_zip( $path );
	}

	/**
	 * Validate a ZIP package: structure, data.json, and image files.
	 *
	 * @param string $zip_path Absolute path to the ZIP.
	 * @return true|WP_Error
	 */
	protected function validate_zip( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'The ZipArchive PHP extension is required to import the export package.', 'conexao-leisure-migration' ) );
		}

		$zip = new ZipArchive();
		$res = $zip->open( $zip_path );
		if ( true !== $res ) {
			return new WP_Error( 'zip_open_failed', __( 'The uploaded file is not a valid ZIP archive.', 'conexao-leisure-migration' ) );
		}

		// Locate data.json (either at root or inside lazer-export/).
		$data_index = $this->find_zip_entry( $zip, 'data.json' );
		if ( false === $data_index ) {
			$zip->close();
			return new WP_Error( 'invalid_format', __( 'The ZIP package does not contain a data.json file.', 'conexao-leisure-migration' ) );
		}

		$contents = $zip->getFromIndex( $data_index );
		$zip->close();

		if ( false === $contents ) {
			return new WP_Error( 'read_error', __( 'Could not read data.json from the ZIP package.', 'conexao-leisure-migration' ) );
		}

		return $this->validate_json_contents( $contents );
	}

	/**
	 * Find a file entry in a ZIP by basename.
	 *
	 * @param ZipArchive $zip      Open ZIP archive.
	 * @param string     $basename File basename to find.
	 * @return int|false Index of the entry, or false.
	 */
	protected function find_zip_entry( $zip, $basename ) {
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( basename( $name ) === $basename ) {
				return $i;
			}
		}
		return false;
	}

	/**
	 * Validate the JSON manifest + item structure of an export payload.
	 *
	 * @param string|false $contents Raw file contents.
	 * @return true|WP_Error
	 */
	protected function validate_json_contents( $contents ) {
		if ( false === $contents ) {
			return new WP_Error( 'read_error', __( 'The uploaded file could not be read.', 'conexao-leisure-migration' ) );
		}

		$data = json_decode( $contents, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_json', __( 'The uploaded file is not valid JSON.', 'conexao-leisure-migration' ) );
		}

		if ( ! isset( $data['manifest']['format'] ) || Conexao_Lazer_Exporter::FORMAT !== $data['manifest']['format'] ) {
			return new WP_Error( 'invalid_format', __( 'This file is not a valid Conexão Lazer export.', 'conexao-leisure-migration' ) );
		}

		if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return new WP_Error( 'invalid_format', __( 'The export file contains no Lazer item data.', 'conexao-leisure-migration' ) );
		}

		return true;
	}

	/**
	 * Extract a ZIP package to a temp directory and return the data payload.
	 *
	 * @param string $zip_path Absolute path to the ZIP.
	 * @return array{data:array, images_dir:string, tmp_dir:string}|WP_Error
	 */
	protected function extract_package( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'The ZipArchive PHP extension is required to import the export package.', 'conexao-leisure-migration' ) );
		}

		$zip = new ZipArchive();
		$res = $zip->open( $zip_path );
		if ( true !== $res ) {
			return new WP_Error( 'zip_open_failed', __( 'The uploaded file is not a valid ZIP archive.', 'conexao-leisure-migration' ) );
		}

		$tmp_dir = wp_tempnam( 'conexao-lazer-import' );
		if ( file_exists( $tmp_dir ) ) {
			@unlink( $tmp_dir );
		}
		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			$zip->close();
			return new WP_Error( 'tmp_mkdir_failed', __( 'Could not create the import temp directory.', 'conexao-leisure-migration' ) );
		}

		$zip->extractTo( $tmp_dir );
		$zip->close();

		// Locate data.json.
		$data_path = $this->find_file_recursive( $tmp_dir, 'data.json' );
		if ( ! $data_path ) {
			$this->remove_directory( $tmp_dir );
			return new WP_Error( 'invalid_format', __( 'The ZIP package does not contain a data.json file.', 'conexao-leisure-migration' ) );
		}

		$contents = file_get_contents( $data_path );
		$data     = json_decode( $contents, true );
		if ( ! is_array( $data ) ) {
			$this->remove_directory( $tmp_dir );
			return new WP_Error( 'invalid_json', __( 'data.json is not valid JSON.', 'conexao-leisure-migration' ) );
		}

		// Locate the images directory.
		$images_dir = $this->find_dir_recursive( $tmp_dir, 'images' );
		if ( ! $images_dir ) {
			$images_dir = trailingslashit( $tmp_dir );
		}

		return array(
			'data'       => $data,
			'images_dir' => $images_dir,
			'tmp_dir'    => $tmp_dir,
		);
	}

	/**
	 * Recursively find a file by basename.
	 *
	 * @param string $dir      Directory to search.
	 * @param string $basename Basename to find.
	 * @return string|false Absolute path, or false.
	 */
	protected function find_file_recursive( $dir, $basename ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getBasename() === $basename ) {
				return $file->getPathname();
			}
		}
		return false;
	}

	/**
	 * Recursively find a directory by basename.
	 *
	 * @param string $dir      Directory to search.
	 * @param string $basename Basename to find.
	 * @return string|false Absolute path, or false.
	 */
	protected function find_dir_recursive( $dir, $basename ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $item ) {
			if ( $item->isDir() && $item->getBasename() === $basename ) {
				return $item->getPathname();
			}
		}
		return false;
	}

	/**
	 * Read an export payload from a $_FILES entry or a file path.
	 *
	 * @param array|string $file $_FILES entry or absolute path.
	 * @return array Export data array.
	 */
	public function read_data( $file ) {
		$path = is_string( $file ) ? $file : $file['tmp_name'];
		$extracted = $this->extract_package( $path );
		if ( is_wp_error( $extracted ) ) {
			return array();
		}
		return $extracted['data'];
	}

	/**
	 * Import leisure items from an uploaded ZIP or path.
	 *
	 * @param array|string $file     $_FILES entry or absolute path.
	 * @param array        $options  Import options: dry_run (bool), update (bool).
	 * @return array
	 */
	public function import_file( $file, $options = array() ) {
		$validated = $this->validate_upload( $file );
		if ( is_wp_error( $validated ) ) {
			return $this->empty_stats( array( $validated->get_error_message() ) );
		}

		$path = is_string( $file ) ? $file : $file['tmp_name'];
		$extracted = $this->extract_package( $path );
		if ( is_wp_error( $extracted ) ) {
			return $this->empty_stats( array( $extracted->get_error_message() ) );
		}

		$data       = $extracted['data'];
		$images_dir = $extracted['images_dir'];
		$tmp_dir    = $extracted['tmp_dir'];

		$dry_run = ! empty( $options['dry_run'] );
		$update  = ! isset( $options['update'] ) || ! empty( $options['update'] );

		$stats = $this->empty_stats( array() );
		$stats['dry_run'] = $dry_run;
		$stats['found']   = count( $data['items'] );

		foreach ( $data['items'] as $item ) {
			$result = $this->import_item( $item, $images_dir, $dry_run, $update, $stats );

			if ( 'created' === $result['action'] ) {
				$stats['created']++;
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

			$stats['images_imported']    += ! empty( $result['images'] ) ? (int) $result['images'] : 0;
			$stats['images_reused']      += ! empty( $result['images_reused'] ) ? (int) $result['images_reused'] : 0;
			$stats['images_missing']     += ! empty( $result['images_missing'] ) ? (int) $result['images_missing'] : 0;
			$stats['taxonomies_created'] += ! empty( $result['tax_created'] ) ? (int) $result['tax_created'] : 0;
			$stats['taxonomies_matched'] += ! empty( $result['tax_matched'] ) ? (int) $result['tax_matched'] : 0;

			$stats['items'][] = $result;
		}

		if ( ! $dry_run ) {
			$stats['verification'] = $this->verify_import( count( $data['items'] ) );
		}

		// Clean up the temp directory.
		$this->remove_directory( $tmp_dir );

		return $stats;
	}

	protected function empty_stats( $errors ) {
		return array(
			'dry_run'            => false,
			'found'              => 0,
			'created'            => 0,
			'updated'            => 0,
			'skipped'            => 0,
			'failed'             => 0,
			'images_imported'    => 0,
			'images_reused'      => 0,
			'images_missing'     => 0,
			'taxonomies_created' => 0,
			'taxonomies_matched' => 0,
			'items'              => array(),
			'errors'             => $errors,
			'verification'       => null,
		);
	}

	protected function import_item( $item, $images_dir, $dry_run, $update, &$stats ) {
		$post  = isset( $item['post'] ) ? $item['post'] : array();
		$title = isset( $post['title'] ) ? trim( (string) $post['title'] ) : '';
		$slug  = isset( $post['slug'] ) ? sanitize_title( $post['slug'] ) : '';

		if ( empty( $title ) && empty( $slug ) ) {
			return array(
				'action'  => 'failed',
				'post_id' => 0,
				'error'   => __( 'Item is missing both a title and a slug.', 'conexao-leisure-migration' ),
				'title'   => $title,
			);
		}

		$uuid = isset( $item['uuid'] ) ? sanitize_text_field( $item['uuid'] ) : '';

		$existing_id = $this->find_existing_item( $item, $uuid, $slug, $title );

		if ( $existing_id ) {
			if ( ! $update ) {
				return array(
					'action'  => 'skipped',
					'post_id' => $existing_id,
					'title'   => $title,
				);
			}

			if ( $dry_run ) {
				$run = $this->simulate_update( $existing_id, $item, $images_dir, $title );
			} else {
				$run = $this->update_item( $existing_id, $item, $images_dir, $title, $stats );
			}

			if ( is_wp_error( $run ) ) {
				return array(
					'action'  => 'failed',
					'post_id' => $existing_id,
					'error'   => $run->get_error_message(),
					'title'   => $title,
				);
			}

			return array(
				'action'         => 'updated',
				'post_id'        => $existing_id,
				'title'          => $title,
				'images'         => $run['images'],
				'images_reused'  => $run['images_reused'],
				'images_missing' => $run['images_missing'],
				'tax_created'    => $run['tax_created'],
				'tax_matched'    => $run['tax_matched'],
			);
		}

		if ( $dry_run ) {
			$run = $this->simulate_create( $item, $images_dir, $title );
		} else {
			$run = $this->create_item( $item, $images_dir, $title, $stats );
		}

		if ( is_wp_error( $run ) ) {
			return array(
				'action'  => 'failed',
				'post_id' => 0,
				'error'   => $run->get_error_message(),
				'title'   => $title,
			);
		}

		return array(
			'action'         => 'created',
			'post_id'        => $run['post_id'],
			'title'          => $title,
			'images'         => $run['images'],
			'images_reused'  => $run['images_reused'],
			'images_missing' => $run['images_missing'],
			'tax_created'    => $run['tax_created'],
			'tax_matched'    => $run['tax_matched'],
		);
	}

	protected function simulate_create( $item, $images_dir, $title ) {
		$tax = $this->preview_taxonomies( $item );
		$img = $this->preview_image( $item, $images_dir );

		return array(
			'post_id'        => 0,
			'images'         => $img['import'],
			'images_reused'  => $img['reuse'],
			'images_missing' => $img['missing'],
			'tax_created'    => $tax['created'],
			'tax_matched'    => $tax['matched'],
			'is_preview'     => true,
			'title'          => $title,
		);
	}

	protected function simulate_update( $existing_id, $item, $images_dir, $title ) {
		$tax = $this->preview_taxonomies( $item );
		$img = $this->preview_image( $item, $images_dir );

		return array(
			'post_id'        => $existing_id,
			'images'         => $img['import'],
			'images_reused'  => $img['reuse'],
			'images_missing' => $img['missing'],
			'tax_created'    => $tax['created'],
			'tax_matched'    => $tax['matched'],
			'is_preview'     => true,
			'title'          => $title,
		);
	}

	/**
	 * Preview: determine whether an image would be imported, reused, or missing.
	 *
	 * @param array  $item       Item data.
	 * @param string $images_dir Extracted images directory.
	 * @return array{import:int, reuse:int, missing:int}
	 */
	protected function preview_image( $item, $images_dir ) {
		$image_data = isset( $item['image_data'] ) ? $item['image_data'] : array();
		$filename   = isset( $image_data['filename'] ) ? $image_data['filename'] : '';

		if ( empty( $filename ) ) {
			return array( 'import' => 0, 'reuse' => 0, 'missing' => 0 );
		}

		$file_path = $this->resolve_image_path( $images_dir, $filename );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return array( 'import' => 0, 'reuse' => 0, 'missing' => 1 );
		}

		// If the image ID already exists on an attachment, it will be reused.
		$image_id = isset( $image_data['id'] ) ? $image_data['id'] : '';
		if ( $image_id && $this->find_attachment_by_image_id( $image_id ) ) {
			return array( 'import' => 0, 'reuse' => 1, 'missing' => 0 );
		}

		return array( 'import' => 1, 'reuse' => 0, 'missing' => 0 );
	}

	protected function preview_taxonomies( $item ) {
		$taxonomies = isset( $item['taxonomies'] ) ? $item['taxonomies'] : array();
		$created = 0;
		$matched = 0;

		foreach ( $this->supported_taxonomies as $taxonomy ) {
			$names = isset( $taxonomies[ $taxonomy ] ) ? $taxonomies[ $taxonomy ] : array();
			if ( ! is_array( $names ) ) {
				continue;
			}

			if ( ! taxonomy_exists( $taxonomy ) ) {
				$created += count( array_filter( array_map( 'trim', $names ) ) );
				continue;
			}

			foreach ( array_map( 'trim', $names ) as $name ) {
				if ( '' === $name ) {
					continue;
				}
				if ( term_exists( $name, $taxonomy ) ) {
					$matched++;
				} else {
					$created++;
				}
			}
		}

		return array( 'created' => $created, 'matched' => $matched );
	}

	protected function find_existing_item( $item, $uuid, $slug, $title ) {
		if ( $uuid ) {
			$by_uuid = $this->find_by_uuid( $uuid );
			if ( $by_uuid ) {
				return $by_uuid;
			}
		}

		if ( $slug ) {
			$by_slug = $this->find_by_slug( $slug );
			if ( $by_slug ) {
				return $by_slug;
			}
		}

		if ( $title ) {
			return $this->find_by_title( $title );
		}

		return 0;
	}

	protected function find_by_uuid( $uuid ) {
		$query = new WP_Query( array(
			'post_type'      => 'leisure',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'     => array(
				array(
					'key'   => Conexao_Lazer_Exporter::UUID_META_KEY,
					'value' => $uuid,
				),
			),
		) );

		return $query->have_posts() ? (int) $query->posts[0] : 0;
	}

	protected function find_by_slug( $slug ) {
		$post = get_page_by_path( $slug, OBJECT, 'leisure' );
		return $post ? (int) $post->ID : 0;
	}

	protected function find_by_title( $title ) {
		$query = new WP_Query( array(
			'post_type'      => 'leisure',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			's'              => $title,
		) );

		foreach ( $query->posts as $post_id ) {
			if ( get_the_title( $post_id ) === $title ) {
				return (int) $post_id;
			}
		}

		return 0;
	}

	protected function create_item( $item, $images_dir, $title, &$stats ) {
		$post_data = $this->sanitize_post_data( $item );

		$post_id = wp_insert_post( array(
			'post_type'    => 'leisure',
			'post_title'   => $post_data['title'],
			'post_content' => $post_data['content'],
			'post_excerpt' => $post_data['excerpt'],
			'post_status'  => $post_data['status'],
			'post_name'    => $post_data['slug'],
			'post_date'    => $post_data['date'],
		), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$result = $this->populate_item( $post_id, $item, $images_dir, $stats );

		return array_merge( array( 'post_id' => (int) $post_id ), $result );
	}

	protected function update_item( $post_id, $item, $images_dir, $title, &$stats ) {
		$post_data = $this->sanitize_post_data( $item );

		$updated = wp_update_post( array(
			'ID'           => $post_id,
			'post_title'   => $post_data['title'],
			'post_content' => $post_data['content'],
			'post_excerpt' => $post_data['excerpt'],
			'post_status'  => $post_data['status'],
			'post_name'    => $post_data['slug'],
		), true );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$result = $this->populate_item( $post_id, $item, $images_dir, $stats );

		return array_merge( array( 'post_id' => (int) $post_id ), $result );
	}

	protected function populate_item( $post_id, $item, $images_dir, &$stats ) {
		if ( ! empty( $item['uuid'] ) ) {
			update_post_meta( $post_id, Conexao_Lazer_Exporter::UUID_META_KEY, sanitize_text_field( $item['uuid'] ) );
		}

		$this->save_item_meta( $post_id, $item );

		$tax = $this->save_item_taxonomies( $post_id, $item );
		$stats['taxonomies_created'] += $tax['created'];
		$stats['taxonomies_matched'] += $tax['matched'];

		$img = $this->handle_item_image( $post_id, $item, $images_dir );
		$stats['images_imported'] += $img['import'];
		$stats['images_reused']   += $img['reuse'];
		$stats['images_missing']  += $img['missing'];

		return array(
			'images'         => $img['import'],
			'images_reused'  => $img['reuse'],
			'images_missing' => $img['missing'],
			'tax_created'    => $tax['created'],
			'tax_matched'    => $tax['matched'],
		);
	}

	protected function save_item_meta( $post_id, $item ) {
		$meta = isset( $item['meta'] ) ? $item['meta'] : array();

		foreach ( $this->allowed_meta_keys as $key ) {
			if ( array_key_exists( $key, $meta ) ) {
				$value = $meta[ $key ];
				$this->sanitize_and_save_meta( $post_id, $key, $value );
			}
		}
	}

	protected function sanitize_and_save_meta( $post_id, $key, $value ) {
		switch ( $key ) {
			case '_leisure_image_attachment_id':
			case '_leisure_feature':
			case '_leisure_family':
			case '_leisure_accessibility':
			case '_leisure_pet_friendly':
			case '_leisure_indoor':
			case '_leisure_outdoor':
			case '_leisure_parking':
			case '_leisure_booking':
				$value = $this->normalize_boolean_meta( $value );
				break;
			default:
				$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}

		if ( '' === $value || null === $value ) {
			delete_post_meta( $post_id, $key );
			return;
		}

		update_post_meta( $post_id, $key, $value );
	}

	protected function normalize_boolean_meta( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '';
		}
		if ( is_numeric( $value ) ) {
			return (int) $value ? '1' : '';
		}
		$value = strtolower( (string) $value );
		return in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ? '1' : '';
	}

	protected function save_item_taxonomies( $post_id, $item ) {
		$taxonomies = isset( $item['taxonomies'] ) ? $item['taxonomies'] : array();
		$created = 0;
		$matched = 0;

		foreach ( $this->supported_taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$names = isset( $taxonomies[ $taxonomy ] ) ? $taxonomies[ $taxonomy ] : array();
			if ( ! is_array( $names ) ) {
				$names = array();
			}
			$names = array_values( array_filter( array_map( 'trim', $names ) ) );

			if ( empty( $names ) ) {
				wp_set_object_terms( $post_id, array(), $taxonomy );
				continue;
			}

			$term_ids = array();
			foreach ( $names as $name ) {
				$term = term_exists( $name, $taxonomy );
				if ( ! $term ) {
					$new_term = wp_insert_term( $name, $taxonomy );
					if ( is_wp_error( $new_term ) ) {
						continue;
					}
					$term = $new_term;
					$created++;
				} else {
					$matched++;
				}
				$term_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy );
			}
		}

		return array( 'created' => $created, 'matched' => $matched );
	}

	/**
	 * Handle the featured image for an imported leisure post.
	 *
	 * The image file is read from the extracted package and imported into the
	 * WordPress Media Library. If the same image ID already exists on an
	 * attachment, that attachment is reused (idempotent).
	 *
	 * @param int    $post_id    Leisure post ID.
	 * @param array  $item       Item data.
	 * @param string $images_dir Extracted images directory.
	 * @return array{import:int, reuse:int, missing:int}
	 */
	protected function handle_item_image( $post_id, $item, $images_dir ) {
		$image_data = isset( $item['image_data'] ) ? $item['image_data'] : array();
		$filename   = isset( $image_data['filename'] ) ? $image_data['filename'] : '';

		if ( empty( $filename ) ) {
			return array( 'import' => 0, 'reuse' => 0, 'missing' => 0 );
		}

		$file_path = $this->resolve_image_path( $images_dir, $filename );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return array( 'import' => 0, 'reuse' => 0, 'missing' => 1 );
		}

		// Validate the image file.
		$validated = $this->validate_image_file( $file_path );
		if ( is_wp_error( $validated ) ) {
			return array( 'import' => 0, 'reuse' => 0, 'missing' => 1 );
		}

		$image_id = isset( $image_data['id'] ) ? $image_data['id'] : '';

		// Reuse an existing attachment with the same stable image ID.
		if ( $image_id ) {
			$existing = $this->find_attachment_by_image_id( $image_id );
			if ( $existing ) {
				$this->assign_attachment( $post_id, $existing, $item, $image_data );
				return array( 'import' => 0, 'reuse' => 1, 'missing' => 0 );
			}
		}

		// Import the file into the Media Library.
		$attachment_id = $this->import_image_file( $file_path, $post_id, $item, $image_data );
		if ( ! $attachment_id ) {
			return array( 'import' => 0, 'reuse' => 0, 'missing' => 1 );
		}

		$this->assign_attachment( $post_id, $attachment_id, $item, $image_data );

		return array( 'import' => 1, 'reuse' => 0, 'missing' => 0 );
	}

	/**
	 * Resolve a package-relative image path to an absolute path.
	 *
	 * @param string $images_dir Extracted images directory.
	 * @param string $filename   Package-relative filename (e.g. "images/001-x.jpg").
	 * @return string|false Absolute path, or false.
	 */
	protected function resolve_image_path( $images_dir, $filename ) {
		$filename = ltrim( $filename, '/' );
		$basename = basename( $filename );

		// If the filename already points into the images dir, use it directly.
		$candidate = trailingslashit( $images_dir ) . $basename;
		if ( file_exists( $candidate ) ) {
			return $candidate;
		}

		// Otherwise search recursively.
		$found = $this->find_file_recursive( $images_dir, $basename );
		return $found ? $found : false;
	}

	/**
	 * Validate an image file before importing.
	 *
	 * @param string $file_path Absolute path.
	 * @return true|WP_Error
	 */
	protected function validate_image_file( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'image_unreadable', __( 'Image file could not be read.', 'conexao-leisure-migration' ) );
		}

		if ( filesize( $file_path ) > self::MAX_IMAGE_SIZE ) {
			return new WP_Error( 'image_too_large', __( 'Image file exceeds the maximum size.', 'conexao-leisure-migration' ) );
		}

		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$allowed = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif' );
		if ( ! in_array( $ext, $allowed, true ) ) {
			return new WP_Error( 'image_bad_ext', __( 'Image file has an unsupported extension.', 'conexao-leisure-migration' ) );
		}

		// Verify magic bytes.
		$handle = fopen( $file_path, 'rb' );
		if ( ! $handle ) {
			return new WP_Error( 'image_read_failed', __( 'Could not open image file.', 'conexao-leisure-migration' ) );
		}
		$head = fread( $handle, 16 );
		fclose( $handle );

		$valid = false;
		if ( 0 === strpos( $head, "\xFF\xD8\xFF" ) ) {
			$valid = true;
		} elseif ( 0 === strpos( $head, "\x89PNG\r\n\x1a\n" ) ) {
			$valid = true;
		} elseif ( 0 === strpos( $head, 'GIF87a' ) || 0 === strpos( $head, 'GIF89a' ) ) {
			$valid = true;
		} elseif ( 0 === strpos( $head, 'RIFF' ) && strlen( $head ) >= 12 && 'WEBP' === substr( $head, 8, 4 ) ) {
			$valid = true;
		}

		if ( ! $valid ) {
			return new WP_Error( 'image_invalid', __( 'Image file contents are not a valid image.', 'conexao-leisure-migration' ) );
		}

		return true;
	}

	/**
	 * Find an existing attachment by its stable image ID.
	 *
	 * @param string $image_id Stable image identifier.
	 * @return int Attachment ID or 0.
	 */
	protected function find_attachment_by_image_id( $image_id ) {
		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'     => array(
				array(
					'key'   => Conexao_Lazer_Exporter::IMAGE_UUID_META_KEY,
					'value' => $image_id,
				),
			),
		) );

		return $query->have_posts() ? (int) $query->posts[0] : 0;
	}

	/**
	 * Import an image file from the package into the Media Library.
	 *
	 * @param string $file_path  Absolute path to the image file.
	 * @param int    $post_id    Leisure post ID.
	 * @param array  $item       Item data.
	 * @param array  $image_data Image metadata.
	 * @return int Attachment ID or 0.
	 */
	protected function import_image_file( $file_path, $post_id, $item, $image_data ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
		$ext = $ext ? $ext : 'jpg';

		$title = isset( $image_data['title'] ) && $image_data['title']
			? $image_data['title']
			: ( isset( $item['post']['title'] ) ? $item['post']['title'] : 'Lazer image' );

		$filename = sanitize_file_name( $title . '.' . $ext );
		$filename = wp_unique_filename( wp_upload_dir()['path'], $filename );

		$upload = wp_upload_bits( $filename, null, file_get_contents( $file_path ) );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return 0;
		}

		$file_path_uploaded = $upload['file'];
		$wp_type = wp_check_filetype_and_ext( $file_path_uploaded, $filename );
		if ( empty( $wp_type['ext'] ) || empty( $wp_type['type'] ) ) {
			@unlink( $file_path_uploaded );
			return 0;
		}

		$attachment_id = wp_insert_attachment( array(
			'post_mime_type' => $wp_type['type'],
			'post_title'     => sanitize_text_field( $title ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		), $file_path_uploaded, absint( $post_id ) );

		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			@unlink( $file_path_uploaded );
			return 0;
		}

		$attachment_id = (int) $attachment_id;

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path_uploaded );
		if ( ! empty( $metadata ) && ! is_wp_error( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		// Store stable image identifiers.
		if ( ! empty( $image_data['id'] ) ) {
			update_post_meta( $attachment_id, Conexao_Lazer_Exporter::IMAGE_UUID_META_KEY, sanitize_text_field( $image_data['id'] ) );
		}
		if ( ! empty( $image_data['md5'] ) ) {
			update_post_meta( $attachment_id, Conexao_Lazer_Exporter::IMAGE_HASH_META_KEY, sanitize_text_field( $image_data['md5'] ) );
		}

		return $attachment_id;
	}

	/**
	 * Assign an attachment to a leisure post and restore image metadata.
	 *
	 * @param int    $post_id    Leisure post ID.
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $item       Item data.
	 * @param array  $image_data Image metadata.
	 */
	protected function assign_attachment( $post_id, $attachment_id, $item, $image_data ) {
		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, '_leisure_image_attachment_id', $attachment_id );

		// Restore alt text.
		$alt = isset( $image_data['alt'] ) ? $image_data['alt'] : '';
		if ( empty( $alt ) && ! empty( $item['meta']['_leisure_image_alt_text'] ) ) {
			$alt = $item['meta']['_leisure_image_alt_text'];
		}
		if ( $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $alt ) );
		}

		// Restore source/licensing metadata on the attachment.
		if ( ! empty( $image_data['source'] ) ) {
			update_post_meta( $attachment_id, '_leisure_image_source', sanitize_text_field( $image_data['source'] ) );
		}
		if ( ! empty( $image_data['source_url'] ) ) {
			update_post_meta( $attachment_id, '_leisure_image_source_url', esc_url_raw( $image_data['source_url'] ) );
		}
		if ( ! empty( $image_data['author'] ) ) {
			update_post_meta( $attachment_id, '_leisure_image_author', sanitize_text_field( $image_data['author'] ) );
		}
		if ( ! empty( $image_data['license'] ) ) {
			update_post_meta( $attachment_id, '_leisure_image_license', sanitize_text_field( $image_data['license'] ) );
		}
		if ( ! empty( $image_data['attribution'] ) ) {
			update_post_meta( $attachment_id, '_leisure_image_attribution', sanitize_textarea_field( $image_data['attribution'] ) );
		}

		// Set the image status to 'local' — the image is now a local Media
		// Library attachment.
		update_post_meta( $post_id, '_leisure_image_status', 'local' );
	}

	protected function sanitize_post_data( $item ) {
		$post = isset( $item['post'] ) ? $item['post'] : array();

		$title   = isset( $post['title'] ) ? sanitize_text_field( (string) $post['title'] ) : '';
		$content = isset( $post['content'] ) ? wp_kses_post( (string) $post['content'] ) : '';
		$excerpt = isset( $post['excerpt'] ) ? sanitize_text_field( (string) $post['excerpt'] ) : '';

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

	public function verify_import( $expected = 0 ) {
		$counts = array(
			'leisure_total'     => 0,
			'leisure_published' => 0,
			'leisure_draft'     => 0,
			'with_thumbnail'    => 0,
			'with_uuid'         => 0,
			'category_terms'    => 0,
			'county_terms'      => 0,
			'tag_terms'         => 0,
			'official_website'  => 0,
			'discover_ireland'  => 0,
			'expected'          => (int) $expected,
		);

		$query = new WP_Query( array(
			'post_type'      => 'leisure',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		$counts['leisure_total'] = count( $query->posts );

		foreach ( $query->posts as $post_id ) {
			$status = get_post_status( $post_id );
			if ( 'publish' === $status ) {
				$counts['leisure_published']++;
			} elseif ( 'draft' === $status ) {
				$counts['leisure_draft']++;
			}

			if ( has_post_thumbnail( $post_id ) ) {
				$counts['with_thumbnail']++;
			}
			if ( get_post_meta( $post_id, Conexao_Lazer_Exporter::UUID_META_KEY, true ) ) {
				$counts['with_uuid']++;
			}
			if ( get_post_meta( $post_id, '_leisure_official_website', true ) ) {
				$counts['official_website']++;
			}
			if ( get_post_meta( $post_id, '_leisure_discover_ireland', true ) ) {
				$counts['discover_ireland']++;
			}
		}

		foreach ( array( 'conexao_category' => 'category_terms', 'conexao_county' => 'county_terms', 'conexao_tag' => 'tag_terms' ) as $tax => $key ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			if ( ! is_wp_error( $terms ) ) {
				$counts[ $key ] = count( $terms );
			}
		}

		return $counts;
	}

	protected function remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->remove_directory( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}
}