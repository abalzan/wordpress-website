<?php
/**
 * Lazer (leisure) exporter.
 *
 * Exports every `leisure` post as a self-contained ZIP package containing:
 *
 *   lazer-export-YYYY-MM-DD.zip
 *   ├── data.json                      (manifest + all Lazer items + image metadata)
 *   └── images/
 *       ├── 001-charles-fort.jpg
 *       ├── 002-blarney-castle.jpg
 *       └── ...
 *
 * The actual image files are copied from the local WordPress Media Library
 * into the package. The production importer restores these files as local
 * Media Library attachments, so the frontend never depends on Wikimedia
 * Commons (or any other external host) for image delivery.
 *
 * Wikimedia Commons information is preserved only as source/licensing
 * metadata (file page URL, author, license, attribution) — never as the
 * production image URL.
 *
 * @package Conexao_Lazer_Migration
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Lazer_Exporter {

	const FORMAT            = 'conexao-lazer-export';
	const FORMAT_VERSION    = '2.0';
	const UUID_META_KEY     = '_leisure_export_uuid';
	const IMAGE_UUID_META_KEY = '_leisure_image_export_uuid';
	const IMAGE_HASH_META_KEY = '_leisure_image_export_hash';
	const MAX_IMAGE_SIZE    = 25 * MB_IN_BYTES;

	protected $export_meta_keys = array(
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

	protected $export_taxonomies = array(
		'conexao_category',
		'conexao_county',
		'conexao_tag',
	);

	public function count_items() {
		$query = new WP_Query( array(
			'post_type'      => 'leisure',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		) );
		return (int) $query->found_posts;
	}

	public function get_export_post_ids() {
		$query = new WP_Query( array(
			'post_type'      => 'leisure',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		return array_map( 'intval', $query->posts );
	}

	public function build_export() {
		$items    = array();
		$post_ids = $this->get_export_post_ids();
		$sequence = 0;

		foreach ( $post_ids as $post_id ) {
			$sequence++;
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$items[] = $this->export_item( $post, $sequence );
		}

		return array(
			'manifest' => array(
				'format'      => self::FORMAT,
				'version'     => self::FORMAT_VERSION,
				'post_type'   => 'leisure',
				'exported_at' => current_time( 'c' ),
				'source_url'  => home_url(),
				'item_count'  => count( $items ),
				'packaged'    => true,
				'package'     => array(
					'images_dir' => 'images',
					'data_file'  => 'data.json',
				),
			),
			'items'   => $items,
		);
	}

	protected function export_item( $post, $sequence ) {
		$uuid = get_post_meta( $post->ID, self::UUID_META_KEY, true );
		if ( empty( $uuid ) ) {
			$uuid = $this->generate_uuid();
			update_post_meta( $post->ID, self::UUID_META_KEY, $uuid );
		}

		$meta = array();
		foreach ( $this->export_meta_keys as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( '' !== $value && null !== $value && false !== $value ) {
				$meta[ $key ] = $value;
			}
		}

		$taxonomies = array();
		foreach ( $this->export_taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post->ID, $taxonomy );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$taxonomies[ $taxonomy ] = wp_list_pluck( $terms, 'name' );
			} else {
				$taxonomies[ $taxonomy ] = array();
			}
		}

		$image = $this->export_image_metadata( $post, $sequence );

		return array(
			'uuid'       => $uuid,
			'id'         => $uuid,
			'post'       => array(
				'title'    => $post->post_title,
				'content'  => $post->post_content,
				'excerpt'  => $post->post_excerpt,
				'status'   => $post->post_status,
				'slug'     => $post->post_name,
				'date'     => $post->post_date,
				'modified' => $post->post_modified,
			),
			'meta'       => $meta,
			'taxonomies' => $taxonomies,
			'image_data' => $image,
			'image'      => array(
				'id'       => $image['id'],
				'filename' => 'images/' . $image['filename'],
				'original' => $image['original_filename'],
				'md5'      => $image['md5'],
			),
		);
	}

	protected function export_image_metadata( $post, $sequence ) {
		$result = array(
			'id'                => '',
			'filename'          => '',
			'original_filename' => '',
			'mime'              => '',
			'md5'               => '',
			'alt'               => '',
			'title'             => '',
			'caption'           => '',
			'source'            => get_post_meta( $post->ID, '_leisure_image_source', true ),
			'source_url'        => get_post_meta( $post->ID, '_leisure_image_source_url', true ),
			'author'            => get_post_meta( $post->ID, '_leisure_image_author', true ),
			'license'           => get_post_meta( $post->ID, '_leisure_image_license', true ),
			'attribution'       => get_post_meta( $post->ID, '_leisure_image_attribution', true ),
			'external_url'      => '',
		);

		$attachment_id = $this->get_attachment_for_post( $post->ID );
		if ( ! $attachment_id ) {
			return $result;
		}

		$file = $this->attachment_file( $attachment_id );
		if ( ! $file ) {
			return $result;
		}

		$slug        = sanitize_title( $post->post_title );
		$slug        = $slug ? $slug : 'lazer-image';
		$content_md5 = md5_file( $file );

		$result['id']  = 'lazer-image-' . substr( md5( $slug . '|' . $content_md5 ), 0, 16 );
		$result['md5'] = $content_md5;

		$ext                = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		$ext                = $ext ? $ext : $this->extension_from_mime( $attachment_id );
		$result['filename'] = sprintf( '%03d-%s.%s', $sequence, $slug, $ext );

		$result['original_filename'] = wp_basename( $file );
		$result['mime']              = (string) get_post_mime_type( $attachment_id );
		$result['title']             = get_the_title( $attachment_id );

		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( empty( $alt ) ) {
			$alt = get_post_meta( $post->ID, '_leisure_image_alt_text', true );
		}
		$result['alt'] = $alt ? $alt : $post->post_title;

		$external = get_post_meta( $post->ID, '_leisure_image_external_url', true );
		if ( $external ) {
			$result['external_url'] = $external;
		}

		update_post_meta( $attachment_id, self::IMAGE_UUID_META_KEY, $result['id'] );
		update_post_meta( $attachment_id, self::IMAGE_HASH_META_KEY, $result['md5'] );

		return $result;
	}

	public function get_attachment_for_post( $post_id ) {
		$attachment_id = (int) get_post_meta( $post_id, '_leisure_image_attachment_id', true );
		if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
			return $attachment_id;
		}

		$thumbnail = (int) get_post_thumbnail_id( $post_id );
		if ( $thumbnail && wp_attachment_is_image( $thumbnail ) ) {
			return $thumbnail;
		}

		return 0;
	}

	public function attachment_file( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
			return '';
		}
		if ( filesize( $file ) > self::MAX_IMAGE_SIZE ) {
			return '';
		}
		return $file;
	}

	public function copy_attachment_to( $attachment_id, $dest_dir, $dest_name ) {
		$file = $this->attachment_file( $attachment_id );
		if ( ! $file ) {
			return false;
		}
		$dest = trailingslashit( $dest_dir ) . sanitize_file_name( $dest_name );
		return copy( $file, $dest );
	}

	public function build_package( $dest_path ) {
		$payload  = $this->build_export();
		$post_ids = $this->get_export_post_ids();

		$tmp_dir = wp_tempnam( 'conexao-lazer-build' );
		if ( file_exists( $tmp_dir ) ) {
			@unlink( $tmp_dir );
		}
		if ( ! wp_mkdir_p( $tmp_dir ) ) {
			return new WP_Error( 'tmp_mkdir_failed', __( 'Could not create the export package temp directory.', 'conexao-leisure-migration' ) );
		}

		$base       = trailingslashit( $tmp_dir ) . 'lazer-export';
		$images_dir = $base . '/images';

		if ( ! wp_mkdir_p( $images_dir ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Could not create the export package images directory.', 'conexao-leisure-migration' ) );
		}

		$image_count = 0;
		foreach ( $post_ids as $index => $post_id ) {
			$post          = get_post( $post_id );
			$attachment_id = $this->get_attachment_for_post( $post_id );
			$item_key      = null;

			foreach ( $payload['items'] as $key => $item ) {
				if ( $post && isset( $item['post']['slug'] ) && $item['post']['slug'] === $post->post_name ) {
					$item_key = $key;
					break;
				}
			}

			if ( null === $item_key || ! $attachment_id ) {
				continue;
			}

			$filename = isset( $payload['items'][ $item_key ]['image_data']['filename'] )
				? $payload['items'][ $item_key ]['image_data']['filename']
				: '';

			if ( $filename && $this->copy_attachment_to( $attachment_id, $images_dir, $filename ) ) {
				$payload['items'][ $item_key ]['image']['filename']      = 'images/' . $filename;
				$payload['items'][ $item_key ]['image_data']['filename'] = 'images/' . $filename;
				$image_count++;
			}
		}

		$payload['manifest']['package']['image_count'] = $image_count;

		$data_json   = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$json_written = file_put_contents( $base . '/data.json', $data_json );
		if ( false === $json_written ) {
			return new WP_Error( 'data_write_failed', __( 'Could not write data.json in the export package.', 'conexao-leisure-migration' ) );
		}

		$created = $this->create_zip( $dest_path, $base );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$this->remove_directory( $tmp_dir );

		return $dest_path;
	}

	protected function create_zip( $zip_path, $root ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', __( 'The ZipArchive PHP extension is required to build the export package.', 'conexao-leisure-migration' ) );
		}

		$zip = new ZipArchive();
		$res = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		if ( true !== $res ) {
			return new WP_Error( 'zip_open_failed', __( 'Could not create the export ZIP archive.', 'conexao-leisure-migration' ) );
		}

		$root     = untrailingslashit( $root );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() || ! $file->isFile() ) {
				continue;
			}
			$local = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) ), '/' );
			$zip->addFile( $file->getPathname(), 'lazer-export/' . $local );
		}

		$zip->close();
		return true;
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

	protected function extension_from_mime( $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );
		foreach ( wp_get_mime_types() as $ext => $mime_patterns ) {
			if ( $mime === $mime_patterns ) {
				$parts = explode( '|', $ext );
				return strtolower( $parts[0] );
			}
		}
		return 'jpg';
	}

	protected function generate_uuid() {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf(
			'%s%s-%s-%s-%s-%s%s%s',
			str_split( bin2hex( $data ), 4 )
		);
	}

	public function download() {
		$dest = wp_tempnam( 'conexao-lazer-export' );
		if ( file_exists( $dest ) ) {
			@unlink( $dest );
		}

		$package = $this->build_package( $dest );
		if ( is_wp_error( $package ) ) {
			wp_die( esc_html( $package->get_error_message() ) );
		}

		$filename = 'lazer-export-' . gmdate( 'Y-m-d' ) . '.zip';

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (int) filesize( $package ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $package );
		@unlink( $package );
		exit;
	}
}