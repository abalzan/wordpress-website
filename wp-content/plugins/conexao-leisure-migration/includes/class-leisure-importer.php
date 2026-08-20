<?php
/**
 * Lazer (leisure) importer.
 *
 * Imports a portable JSON export file (produced by Conexao_Lazer_Exporter)
 * into the current WordPress installation, recreating leisure posts, metadata,
 * taxonomies and featured images.
 *
 * The importer is safe to run repeatedly: records are matched by their stable
 * export UUID, then by slug, then by title, so re-importing never creates
 * duplicates. It only ever affects the `leisure` post type, its taxonomies
 * and the Media Library attachments created for leisure images.
 *
 * @package Conexao_Lazer_Migration
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Lazer_Importer {

	/**
	 * Allowed meta keys (mirrors the exporter list).
	 *
	 * @var array
	 */
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
		'_leisure_image_external_url',
		'_leisure_image_source',
		'_leisure_image_source_url',
		'_leisure_image_author',
		'_leisure_image_license',
		'_leisure_image_attribution',
		'_leisure_image_alt_text',
		'_leisure_image_status',
	);

	/**
	 * Taxonomies that are imported for each leisure item.
	 *
	 * @var array
	 */
	protected $supported_taxonomies = array(
		'conexao_category',
		'conexao_county',
		'conexao_tag',
	);

	/**
	 * Validate an uploaded/prepared import file.
	 *
	 * Accepts either a $_FILES entry or a file path. Returns true on success
	 * or a WP_Error describing the problem. No data is written during
	 * validation.
	 *
	 * @param array|string $file $_FILES entry or absolute path to a JSON file.
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

		// Check file size (limit to 25 MB).
		if ( filesize( $file['tmp_name'] ) > 25 * MB_IN_BYTES ) {
			return new WP_Error( 'file_too_large', __( 'The uploaded file is too large. Maximum size is 25 MB.', 'conexao-leisure-migration' ) );
		}

		// Check the file extension.
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'json' !== $ext ) {
			return new WP_Error( 'invalid_type', __( 'Only .json export files are supported.', 'conexao-leisure-migration' ) );
		}

		return $this->validate_json_contents( file_get_contents( $file['tmp_name'] ) );
	}

	/**
	 * Validate a JSON file given its path (used by the CLI script).
	 *
	 * @param string $path Absolute path to a JSON file.
	 * @return true|WP_Error
	 */
	protected function validate_file_path( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'file_not_found', __( 'The export file could not be found or read.', 'conexao-leisure-migration' ) );
		}

		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'json' !== $ext ) {
			return new WP_Error( 'invalid_type', __( 'Only .json export files are supported.', 'conexao-leisure-migration' ) );
		}

		return $this->validate_json_contents( file_get_contents( $path ) );
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
	 * Read an export payload from a $_FILES entry or a file path.
	 *
	 * @param array|string $file $_FILES entry or absolute path.
	 * @return array Export data array.
	 */
	public function read_data( $file ) {
		$contents = is_string( $file ) ? file_get_contents( $file ) : file_get_contents( $file['tmp_name'] );
		$data     = json_decode( $contents, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Import leisure items from an uploaded file or path.
	 *
	 * @param array|string $file     $_FILES entry or absolute path.
	 * @param array        $options  Import options: dry_run (bool), update (bool).
	 * @return array{
	 *   dry_run: bool,
	 *   found: int,
	 *   created: int,
	 *   updated: int,
	 *   skipped: int,
	 *   failed: int,
	 *   images_imported: int,
	 *   taxonomies_created: int,
	 *   taxonomies_matched: int,
	 *   items: array,
	 *   errors: array
	 * }
	 */
	public function import_file( $file, $options = array() ) {
		$validated = $this->validate_upload( $file );
		if ( is_wp_error( $validated ) ) {
			return $this->empty_stats( array( $validated->get_error_message() ) );
		}

		$data    = $this->read_data( $file );
		$dry_run = ! empty( $options['dry_run'] );
		$update  = ! isset( $options['update'] ) || ! empty( $options['update'] );

		$stats = $this->empty_stats( array() );
		$stats['dry_run'] = $dry_run;
		$stats['found']   = count( $data['items'] );

		foreach ( $data['items'] as $item ) {
			$result = $this->import_item( $item, $dry_run, $update, $stats );

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

			// Aggregate image / taxonomy preview counts (dry-run) or actuals.
			$stats['images_imported']    += ! empty( $result['images'] ) ? (int) $result['images'] : 0;
			$stats['taxonomies_created'] += ! empty( $result['tax_created'] ) ? (int) $result['tax_created'] : 0;
			$stats['taxonomies_matched'] += ! empty( $result['tax_matched'] ) ? (int) $result['tax_matched'] : 0;

			$stats['items'][] = $result;
		}

		if ( ! $dry_run ) {
			$stats['verification'] = $this->verify_import( count( $data['items'] ) );
		}

		return $stats;
	}

	/**
	 * Build an initial empty stats array.
	 *
	 * @param array $errors Initial errors.
	 * @return array
	 */
	protected function empty_stats( $errors ) {
		return array(
			'dry_run'             => false,
			'found'               => 0,
			'created'             => 0,
			'updated'             => 0,
			'skipped'             => 0,
			'failed'              => 0,
			'images_imported'     => 0,
			'taxonomies_created'  => 0,
			'taxonomies_matched'  => 0,
			'items'               => array(),
			'errors'              => $errors,
			'verification'        => null,
		);
	}

	/**
	 * Import a single leisure item from the export payload.
	 *
	 * @param array $item    Item data from the export file.
	 * @param bool  $dry_run Whether to only preview without writing.
	 * @param bool  $update  Whether to update existing records.
	 * @param array $stats   Running stats (mutable, for image/taxonomy counters).
	 * @return array{action:string, post_id:int, error?:string, title?:string, images?:int, tax_created?:int, tax_matched?:int}
	 */
	protected function import_item( $item, $dry_run, $update, &$stats ) {
		$post = isset( $item['post'] ) ? $item['post'] : array();
		$title = isset( $post['title'] ) ? trim( (string) $post['title'] ) : '';
		$slug  = isset( $post['slug'] ) ? sanitize_title( $post['slug'] ) : '';

		if ( empty( $title ) && empty( $slug ) ) {
			return array(
				'action' => 'failed',
				'post_id' => 0,
				'error'  => __( 'Item is missing both a title and a slug.', 'conexao-leisure-migration' ),
				'title'  => $title,
			);
		}

		$uuid = isset( $item['uuid'] ) ? sanitize_text_field( $item['uuid'] ) : '';

		// Find an existing leisure post.
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
				// Preview the update.
				$run = $this->simulate_update( $existing_id, $item, $title );
			} else {
				$run = $this->update_item( $existing_id, $item, $title, $stats );
			}

			if ( is_wp_error( $run ) ) {
				return array(
					'action' => 'failed',
					'post_id' => $existing_id,
					'error'  => $run->get_error_message(),
					'title'  => $title,
				);
			}

			return array(
				'action'         => 'updated',
				'post_id'        => $existing_id,
				'title'          => $title,
				'images'         => $run['images'],
				'tax_created'    => $run['tax_created'],
				'tax_matched'    => $run['tax_matched'],
			);
		}

		if ( $dry_run ) {
			$run = $this->simulate_create( $item, $title );
		} else {
			$run = $this->create_item( $item, $title, $stats );
		}

		if ( is_wp_error( $run ) ) {
			return array(
				'action' => 'failed',
				'post_id' => 0,
				'error'  => $run->get_error_message(),
				'title'  => $title,
			);
		}

		return array(
			'action'         => 'created',
			'post_id'        => $run['post_id'],
			'title'          => $title,
			'images'         => $run['images'],
			'tax_created'    => $run['tax_created'],
			'tax_matched'    => $run['tax_matched'],
		);
	}

	/**
	 * Simulate creating an item (dry-run) without writing anything.
	 *
	 * @param array  $item  Item data.
	 * @param string $title Item title.
	 * @return array
	 */
	protected function simulate_create( $item, $title ) {
		$tax = $this->preview_taxonomies( $item );

		return array(
			'post_id'     => 0,
			'images'      => $this->preview_image( $item ),
			'tax_created' => $tax['created'],
			'tax_matched' => $tax['matched'],
			'is_preview'  => true,
			'title'       => $title,
		);
	}

	/**
	 * Simulate updating an item (dry-run) without writing anything.
	 *
	 * @param int    $existing_id Existing post ID.
	 * @param array  $item        Item data.
	 * @param string $title       Item title.
	 * @return array
	 */
	protected function simulate_update( $existing_id, $item, $title ) {
		$tax = $this->preview_taxonomies( $item );

		return array(
			'post_id'     => $existing_id,
			'images'      => $this->preview_image( $item ),
			'tax_created' => $tax['created'],
			'tax_matched' => $tax['matched'],
			'is_preview'  => true,
			'title'       => $title,
		);
	}

	/**
	 * Preview: estimate whether an image would be imported and attachments.
	 *
	 * @param array $item Item data.
	 * @return int 1 if images would be imported, else 0.
	 */
	protected function preview_image( $item ) {
		$meta          = isset( $item['meta'] ) ? $item['meta'] : array();
		$featured      = isset( $item['featured_image'] ) ? $item['featured_image'] : array();

		// Only items that actually have a local Media Library attachment can
		// have their image imported. The _leisure_image_status label is not
		// authoritative — a 'pending' item may still carry a real thumbnail.
		if ( empty( $featured['local_attachment_id'] ) && empty( $featured['local_url'] ) ) {
			return 0;
		}

		$url = $this->best_download_url( $featured, $meta );
		return $url ? 1 : 0;
	}

	/**
	 * Preview: count how many taxonomy terms would be created.
	 *
	 * @param array $item Item data.
	 * @return array{created:int, matched:int}
	 */
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
				// Taxonomy missing on destination — count names as would-be created.
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

	/**
	 * Find an existing leisure post that matches the imported item.
	 *
	 * Matching priority:
	 *  1. Export UUID meta (_leisure_export_uuid)
	 *  2. Slug (post_name) — leisure slugs are stable and unique
	 *  3. Title (exact)
	 *
	 * @param array  $item  Item data.
	 * @param string $uuid  Export UUID.
	 * @param string $slug  Legible slug.
	 * @param string $title Title.
	 * @return int Post ID or 0.
	 */
	protected function find_existing_item( $item, $uuid, $slug, $title ) {
		// 1. UUID match.
		if ( $uuid ) {
			$by_uuid = $this->find_by_uuid( $uuid );
			if ( $by_uuid ) {
				return $by_uuid;
			}
		}

		// 2. Slug match.
		if ( $slug ) {
			$by_slug = $this->find_by_slug( $slug );
			if ( $by_slug ) {
				return $by_slug;
			}
		}

		// 3. Title match.
		if ( $title ) {
			return $this->find_by_title( $title );
		}

		return 0;
	}

	/**
	 * Find a leisure post by its export UUID.
	 *
	 * @param string $uuid Export UUID.
	 * @return int Post ID or 0.
	 */
	protected function find_by_uuid( $uuid ) {
		$query = new WP_Query(
			array(
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
			)
		);

		return $query->have_posts() ? (int) $query->posts[0] : 0;
	}

	/**
	 * Find a leisure post by its slug.
	 *
	 * @param string $slug Post slug.
	 * @return int Post ID or 0.
	 */
	protected function find_by_slug( $slug ) {
		$post = get_page_by_path( $slug, OBJECT, 'leisure' );
		return $post ? (int) $post->ID : 0;
	}

	/**
	 * Find a leisure post by its exact title.
	 *
	 * @param string $title Title.
	 * @return int Post ID or 0.
	 */
	protected function find_by_title( $title ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'leisure',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				's'              => $title,
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => 'title_match', 'compare' => 'NOT EXISTS' ), // placeholder to allow later filtering.
				),
			)
		);

		// Narrow by exact title match.
		foreach ( $query->posts as $post_id ) {
			if ( get_the_title( $post_id ) === $title ) {
				return (int) $post_id;
			}
		}

		return 0;
	}

	/**
	 * Create a new leisure post from the export payload.
	 *
	 * @param array  $item  Item data.
	 * @param string $title Item title.
	 * @param array  $stats Running stats.
	 * @return array|WP_Error {post_id, images, tax_created, tax_matched} or WP_Error.
	 */
	protected function create_item( $item, $title, &$stats ) {
		$post_data = $this->sanitize_post_data( $item );

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'leisure',
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

		$result = $this->populate_item( $post_id, $item, $stats );

		return array_merge( array( 'post_id' => (int) $post_id ), $result );
	}

	/**
	 * Update an existing leisure post from the export payload.
	 *
	 * @param int    $post_id Existing leisure post ID.
	 * @param array  $item    Item data.
	 * @param string $title   Item title.
	 * @param array  $stats   Running stats.
	 * @return array|WP_Error {post_id, images, tax_created, tax_matched} or WP_Error.
	 */
	protected function update_item( $post_id, $item, $title, &$stats ) {
		$post_data = $this->sanitize_post_data( $item );

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

		$result = $this->populate_item( $post_id, $item, $stats );

		return array_merge( array( 'post_id' => (int) $post_id ), $result );
	}

	/**
	 * Populate meta, taxonomies, and featured image for a leisure post.
	 *
	 * @param int   $post_id Leisure post ID.
	 * @param array $item    Item data.
	 * @param array $stats   Running stats.
	 * @return array{images:int, tax_created:int, tax_matched:int}
	 */
	protected function populate_item( $post_id, $item, &$stats ) {
		// Save the export UUID so re-imports can be matched.
		if ( ! empty( $item['uuid'] ) ) {
			update_post_meta( $post_id, Conexao_Lazer_Exporter::UUID_META_KEY, sanitize_text_field( $item['uuid'] ) );
		}

		// Save item meta.
		$this->save_item_meta( $post_id, $item );

		// Save taxonomies.
		$tax = $this->save_item_taxonomies( $post_id, $item );
		$stats['taxonomies_created'] += $tax['created'];
		$stats['taxonomies_matched'] += $tax['matched'];

		// Handle the featured image.
		$images = $this->handle_item_image( $post_id, $item );
		$stats['images_imported'] += $images;

		return array(
			'images'      => $images,
			'tax_created' => $tax['created'],
			'tax_matched' => $tax['matched'],
		);
	}

	/**
	 * Save all leisure meta fields from the export payload.
	 *
	 * @param int   $post_id Leisure post ID.
	 * @param array $item    Item data.
	 */
	protected function save_item_meta( $post_id, $item ) {
		$meta = isset( $item['meta'] ) ? $item['meta'] : array();

		foreach ( $this->allowed_meta_keys as $key ) {
			if ( array_key_exists( $key, $meta ) ) {
				$value = $meta[ $key ];
				$this->sanitize_and_save_meta( $post_id, $key, $value );
			}
		}
	}

	/**
	 * Sanitize a meta value by key type and save it.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Raw value.
	 */
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

	/**
	 * Normalize a boolean-ish meta value to '1' or ''.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
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

	/**
	 * Save taxonomies for a leisure post from the export payload.
	 *
	 * Terms are matched by name (not ID) so they map correctly across
	 * installations. Missing terms are created automatically.
	 *
	 * @param int   $post_id Leisure post ID.
	 * @param array $item    Item data.
	 * @return array{created:int, matched:int}
	 */
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
	 * Only local-images (status 'local') are downloaded into the Media Library.
	 * External licensed images (Wikimedia Commons etc.) are preserved as meta
	 * but never re-downloaded, so the licensing/source model is kept intact.
	 *
	 * Localhost URLs cannot be reached from the destination site and are never
	 * used; the item keeps its 'pending' image status when no reachable local
	 * URL is available.
	 *
	 * @param int   $post_id Leisure post ID.
	 * @param array $item    Item data.
	 * @return int 1 if an image was imported, else 0.
	 */
	protected function handle_item_image( $post_id, $item ) {
		$meta     = isset( $item['meta'] ) ? $item['meta'] : array();
		$featured = isset( $item['featured_image'] ) ? $item['featured_image'] : array();

		// Only items that actually have a local Media Library attachment can
		// have their image imported. A 'pending' item may still carry a real
		// featured image, so we check for the attachment, not the status label.
		if ( empty( $featured['local_attachment_id'] ) && empty( $featured['local_url'] ) ) {
			return 0;
		}

		$url = $this->best_download_url( $featured, $meta );
		if ( empty( $url ) ) {
			// No reachable local image — keep 'pending' so the editor can fix it.
			$this->set_image_status( $post_id, 'pending' );
			return 0;
		}

		$title        = isset( $item['post']['title'] ) ? $item['post']['title'] : 'Lazer image';
		$attachment_id = $this->sideload_image( $url, $post_id, $title );

		if ( ! $attachment_id ) {
			$this->set_image_status( $post_id, 'pending' );
			return 0;
		}

		// Restore alt text if provided.
		$alt = isset( $featured['alt'] ) ? $featured['alt'] : '';
		if ( empty( $alt ) && ! empty( $meta['_leisure_image_alt_text'] ) ) {
			$alt = $meta['_leisure_image_alt_text'];
		}
		if ( $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $alt ) );
		}

		// Associate the attachment: featured image + leisure image meta.
		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, '_leisure_image_attachment_id', $attachment_id );
		$this->set_image_status( $post_id, 'local' );

		return 1;
	}

	/**
	 * Choose the best reachable URL to download for a local image.
	 *
	 * Preferred order:
	 *  1. Non-localhost attachment local_url.
	 *  2. Non-localhost thumbnail_url.
	 *  3. Anything else that is a valid, non-localhost http(s) URL.
	 *
	 * Localhost / Docker URLs are never returned (the destination cannot reach them).
	 *
	 * @param array $featured Featured image export data.
	 * @param array $meta     Item meta.
	 * @return string Best URL, or ''.
	 */
	protected function best_download_url( $featured, $meta ) {
		$candidates = array();

		if ( ! empty( $featured['local_url'] ) ) {
			$candidates[] = $featured['local_url'];
		}
		if ( ! empty( $featured['thumbnail_url'] ) ) {
			$candidates[] = $featured['thumbnail_url'];
		}
		if ( ! empty( $featured['localhost_hint'] ) ) {
			$candidates[] = $featured['localhost_hint'];
		}

		// Also consider the external licensed image URL if a local image is set
		// on this item (safety net, e.g. when localhost can't be reached).
		if ( ! empty( $meta['_leisure_image_external_url'] ) ) {
			$candidates[] = $meta['_leisure_image_external_url'];
		}

		foreach ( $candidates as $candidate ) {
			if ( empty( $candidate ) || ! filter_var( $candidate, FILTER_VALIDATE_URL ) ) {
				continue;
			}
			$scheme = strtolower( (string) wp_parse_url( $candidate, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				continue;
			}
			if ( $this->is_localhost_url( $candidate ) ) {
				continue;
			}

			return $candidate;
		}

		return '';
	}

	/**
	 * Set the image status meta for a leisure post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Status key.
	 */
	protected function set_image_status( $post_id, $status ) {
		$valid = array( 'none', 'pending', 'local', 'external' );
		if ( ! in_array( $status, $valid, true ) ) {
			return;
		}
		update_post_meta( $post_id, '_leisure_image_status', $status );
	}

	/**
	 * Check whether a URL points to a localhost / local Docker environment.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public function is_localhost_url( $url ) {
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
	 * Sideload an image URL into the WordPress Media Library.
	 *
	 * Adapted from the event image handler so the migration plugin is
	 * self-contained and does not create a dependency on the event plugin.
	 *
	 * @param string $url     Image URL.
	 * @param int    $post_id Leisure post ID.
	 * @param string $title   Attachment title.
	 * @return int Attachment ID or 0.
	 */
	protected function sideload_image( $url, $post_id, $title = '' ) {
		if ( empty( $url ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 5,
				'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			)
		);

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return 0;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || '' === $body ) {
			return 0;
		}

		if ( strlen( $body ) > 25 * MB_IN_BYTES ) {
			return 0;
		}

		$detected = $this->detect_image_type( $body );
		if ( ! $detected ) {
			return 0;
		}

		$filename = $this->build_filename( $title, $detected['ext'], $url );

		$upload = wp_upload_bits( $filename, null, $body );
		if ( ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return 0;
		}

		$file_path = $upload['file'];
		$wp_type   = wp_check_filetype_and_ext( $file_path, $filename );
		if ( empty( $wp_type['ext'] ) || empty( $wp_type['type'] ) ) {
			@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoDiscouragedPHPFunctions
			return 0;
		}

		$attachment_title = $title ? trim( (string) $title ) : __( 'Lazer image', 'conexao-leisure-migration' );

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
			@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoDiscouragedPHPFunctions
			return 0;
		}

		$attachment_id = (int) $attachment_id;

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		if ( ! empty( $metadata ) && ! is_wp_error( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return $attachment_id;
	}

	/**
	 * Detect the image type from binary content (magic bytes).
	 *
	 * @param string $body Downloaded content.
	 * @return array|null Array with 'ext' and 'mime', or null.
	 */
	protected function detect_image_type( $body ) {
		if ( ! is_string( $body ) || '' === $body ) {
			return null;
		}

		if ( 0 === strpos( $body, "\xFF\xD8\xFF" ) ) {
			return array( 'ext' => 'jpg', 'mime' => 'image/jpeg' );
		}
		if ( 0 === strpos( $body, "\x89PNG\r\n\x1a\n" ) ) {
			return array( 'ext' => 'png', 'mime' => 'image/png' );
		}
		if ( 0 === strpos( $body, 'GIF87a' ) || 0 === strpos( $body, 'GIF89a' ) ) {
			return array( 'ext' => 'gif', 'mime' => 'image/gif' );
		}
		if ( 0 === strpos( $body, 'RIFF' ) && strlen( $body ) >= 12 && 'WEBP' === substr( $body, 8, 4 ) ) {
			return array( 'ext' => 'webp', 'mime' => 'image/webp' );
		}

		return null;
	}

	/**
	 * Build a safe, unique filename for the imported image.
	 *
	 * @param string $title     Attachment title.
	 * @param string $extension File extension.
	 * @param string $url       Source URL.
	 * @return string
	 */
	protected function build_filename( $title, $extension, $url ) {
		$name = sanitize_title( $title );
		$base = $name ? $name : 'conexao-lazer-image';
		$hash = substr( md5( $url ), 0, 10 );

		return sanitize_file_name( $base . '-' . $hash . '.' . $extension );
	}

	/**
	 * Sanitize the post data from an export payload.
	 *
	 * @param array $item Item data.
	 * @return array{title:string, content:string, excerpt:string, status:string, slug:string, date:string}
	 */
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

	/**
	 * Post-import verification.
	 *
	 * Counts leisure posts, taxonomies, featured images and reports meta /
	 * status / slug figures so the user can confirm the migration succeeded.
	 *
	 * @param int $expected Expected item count (from manifest).
	 * @return array
	 */
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

		$query = new WP_Query(
			array(
				'post_type'      => 'leisure',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

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
}