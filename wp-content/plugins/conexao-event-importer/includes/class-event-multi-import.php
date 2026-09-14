<?php
/**
 * Multi-file event import orchestrator.
 *
 * Thin orchestration layer over the existing single-file importer
 * (Conexao_Event_Import). Processes multiple v1.1.0 export files
 * sequentially, one decoded payload at a time, so the batch stays
 * within the PHP memory limit. The existing single-file importer remains
 * the source of truth for Event identity, deduplication, and lifecycle.
 *
 * No cron, no background processing, no external fetching. The batch runs
 * synchronously inside the administrator's explicit POST request and
 * stops on the first failed file, marking subsequent files as
 * not-processed.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Multi_Import {

	const SUPPORTED_VERSION = '1.1.0';

	const MULTIPART_MODE = 'multipart';

	/** @var Conexao_Event_Import */
	protected $importer;

	public function __construct() {
		$this->importer = new Conexao_Event_Import();
	}

	/**
	 * Run a multi-file import batch.
	 *
	 * @param array $raw_files The $_FILES['conexao_import_files'] array.
	 * @param array $options   Import options (duplicate_strategy).
	 * @return array Batch report.
	 */
	public function run_batch( $raw_files, $options = array() ) {
		$batch_start    = microtime( true );
		$batch_peak_mem = 0;

		$strategy = isset( $options['duplicate_strategy'] ) ? $options['duplicate_strategy'] : 'update';
		if ( ! in_array( $strategy, array( 'update', 'skip' ), true ) ) {
			$strategy = 'update';
		}

		$report = array(
			'status'            => 'complete',
			'files'             => array(),
			'totals'            => $this->empty_totals(),
			'duration'          => 0.0,
			'peak_memory_bytes' => 0,
			'manifest'          => null,
			'manifest_error'    => null,
		);

		$files = $this->normalize_uploaded_files( $raw_files );
		if ( empty( $files ) ) {
			$report['status'] = 'no_files';
			$report['totals']['errors'][] = __( 'No files were uploaded.', 'conexao-event-importer' );
			return $report;
		}

		$manifest_raw = $this->extract_manifest( $files );

		$manifest = null;
		if ( is_array( $manifest_raw ) ) {
			$manifest = $this->parse_and_validate_manifest( $manifest_raw );
			if ( is_wp_error( $manifest ) ) {
				$report['status']         = 'manifest_error';
				$report['manifest_error'] = $manifest->get_error_message();
				$report['duration']       = round( microtime( true ) - $batch_start, 3 );
				return $report;
			}
			$report['manifest'] = $manifest;
		}

		$files = $this->order_files( $files, $manifest );
		$stopped = false;

		foreach ( $files as $file ) {
			$file_report = $this->process_single_file( $file, $strategy, $manifest, $stopped );
			$report['files'][] = $file_report;
			$this->accumulate_totals( $report['totals'], $file_report );

			if ( false === $stopped && 'failed' === $file_report['status'] ) {
				$stopped = true;
			}

			unset( $file_report );
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		$report['totals']['files']               = count( $files );
		$report['totals']['files_success']       = 0;
		$report['totals']['files_failed']        = 0;
		$report['totals']['files_not_processed'] = 0;

		foreach ( $report['files'] as $fr ) {
			if ( 'success' === $fr['status'] ) {
				$report['totals']['files_success']++;
			} elseif ( 'not_processed' === $fr['status'] ) {
				$report['totals']['files_not_processed']++;
			} else {
				$report['totals']['files_failed']++;
			}
		}

		if ( $stopped ) {
			$report['status'] = 'stopped';
		}

		$report['duration']          = round( microtime( true ) - $batch_start, 3 );
		$report['peak_memory_bytes'] = $batch_peak_mem;

		return $report;
	}

	/**
	 * Process a single file within the batch.
	 *
	 * @param array      $file      File entry.
	 * @param string     $strategy  Duplicate strategy.
	 * @param array|null $manifest  Optional manifest.
	 * @param bool       $stopped   Whether processing has stopped.
	 * @return array File report.
	 */
	protected function process_single_file( $file, $strategy, $manifest, $stopped ) {
		$file_start     = microtime( true );
		$part_number    = $this->extract_part_number( $file['name'], $manifest );
		$manifest_entry = is_array( $manifest ) ? $this->find_manifest_entry( $manifest, $file['name'] ) : null;

		$file_report = array(
			'filename'          => $file['name'],
			'part_number'       => $part_number,
			'status'            => 'pending',
			'discovered'        => 0,
			'imported'          => 0,
			'updated'           => 0,
			'skipped'           => 0,
			'failed'            => 0,
			'media_discovered'  => 0,
			'errors'            => array(),
			'duration'          => 0.0,
			'peak_memory_bytes' => 0,
		);

		if ( $stopped ) {
			$file_report['status'] = 'not_processed';
			return $file_report;
		}

		$schema_check = $this->validate_file_schema( $file );
		if ( is_wp_error( $schema_check ) ) {
			$file_report['status']   = 'failed';
			$file_report['errors'][] = $schema_check->get_error_message();
			$file_report['duration'] = round( microtime( true ) - $file_start, 3 );
			return $file_report;
		}

		$file_report['discovered']       = $schema_check['event_count'];
		$file_report['media_discovered'] = $schema_check['image_count'];

		if ( is_array( $manifest_entry ) && ! empty( $manifest_entry['sha256'] ) ) {
			$hash = hash_file( 'sha256', $file['tmp_name'] );
			if ( ! is_string( $hash ) || ! hash_equals( (string) $manifest_entry['sha256'], $hash ) ) {
				$file_report['status']   = 'failed';
				$file_report['errors'][] = sprintf(
					/* translators: %s: file name */
					__( 'Hash verification failed for %s: the file does not match the manifest. Import blocked.', 'conexao-event-importer' ),
					$file['name']
				);
				$file_report['duration'] = round( microtime( true ) - $file_start, 3 );
				return $file_report;
			}
		}

		$stats = $this->importer->import_file( $file, array( 'duplicate_strategy' => $strategy ) );

		$file_report['imported'] = $stats['imported'];
		$file_report['updated']  = $stats['updated'];
		$file_report['skipped']  = $stats['skipped'];
		$file_report['failed']   = $stats['failed'];
		$file_report['errors']   = array_merge( $file_report['errors'], $stats['errors'] );

		if ( ! empty( $stats['errors'] ) || $stats['failed'] > 0 ) {
			$file_report['status'] = 'failed';
		} else {
			$file_report['status'] = 'success';
		}

		$file_report['duration']          = round( microtime( true ) - $file_start, 3 );
		$file_report['peak_memory_bytes'] = memory_get_peak_usage( true );

		return $file_report;
	}

	/**
	 * Normalize PHP's multi-file $_FILES structure into a list of files.
	 *
	 * @param array $raw The $_FILES entry.
	 * @return array<int, array> List of individual file entries.
	 */
	protected function normalize_uploaded_files( $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['name'] ) ) {
			return array();
		}

		if ( is_string( $raw['name'] ) ) {
			return array( $raw );
		}

		$files = array();
		$count = count( $raw['name'] );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( empty( $raw['name'][ $i ] ) ) {
				continue;
			}
			$files[] = array(
				'name'     => $raw['name'][ $i ],
				'type'     => isset( $raw['type'][ $i ] ) ? $raw['type'][ $i ] : '',
				'tmp_name' => isset( $raw['tmp_name'][ $i ] ) ? $raw['tmp_name'][ $i ] : '',
				'error'    => isset( $raw['error'][ $i ] ) ? (int) $raw['error'][ $i ] : UPLOAD_ERR_NO_FILE,
				'size'      => isset( $raw['size'][ $i ] ) ? (int) $raw['size'][ $i ] : 0,
			);
		}

		return $files;
	}

	/**
	 * Separate a manifest file from the list of export parts.
	 *
	 * @param array &$files File list (modified by reference).
	 * @return array|null Decoded manifest, or null.
	 */
	protected function extract_manifest( &$files ) {
		foreach ( $files as $index => $file ) {
			$name = isset( $file['name'] ) ? $file['name'] : '';
			if ( ! is_string( $name ) ) {
				continue;
			}
			if ( false !== strpos( $name, 'manifest' ) && 'json' === strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
				$tmp = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
				unset( $files[ $index ] );
				$files = array_values( $files );
				if ( empty( $tmp ) || ! file_exists( $tmp ) ) {
					return null;
				}
				$contents = file_get_contents( $tmp );
				if ( false === $contents ) {
					return null;
				}
				$decoded = json_decode( $contents, true );
				unset( $contents );
				return is_array( $decoded ) ? $decoded : null;
			}
		}
		return null;
	}

	/**
	 * Validate the structure of a multipart manifest.
	 *
	 * @param array $manifest Decoded manifest.
	 * @return array|WP_Error Validated manifest, or WP_Error.
	 */
	protected function parse_and_validate_manifest( $manifest ) {
		if ( ! isset( $manifest['schema'] ) || Conexao_Event_Export::FORMAT !== $manifest['schema'] ) {
			return new WP_Error(
				'conexao_multi_invalid_manifest',
				__( 'The uploaded manifest is not a Conexão event export manifest.', 'conexao-event-importer' )
			);
		}

		if ( ! isset( $manifest['schema_version'] ) || self::SUPPORTED_VERSION !== $manifest['schema_version'] ) {
			return new WP_Error(
				'conexao_multi_unsupported_manifest_version',
				sprintf(
					/* translators: 1: manifest version, 2: expected version */
					__( 'The manifest schema version (%1$s) is not supported. Expected %2$s.', 'conexao-event-importer' ),
					isset( $manifest['schema_version'] ) ? $manifest['schema_version'] : '(none)',
					self::SUPPORTED_VERSION
				)
			);
		}

		if ( self::MULTIPART_MODE !== $manifest['export_mode'] ) {
			return new WP_Error(
				'conexao_multi_not_multipart',
				__( 'The manifest is not a multipart export manifest.', 'conexao-event-importer' )
			);
		}

		if ( ! isset( $manifest['parts'] ) || ! is_array( $manifest['parts'] ) || empty( $manifest['parts'] ) ) {
			return new WP_Error(
				'conexao_multi_no_parts',
				__( 'The manifest does not list any parts.', 'conexao-event-importer' )
			);
		}

		$part_numbers = array();
		foreach ( $manifest['parts'] as $part ) {
			$pn = isset( $part['part_number'] ) ? (int) $part['part_number'] : 0;
			if ( isset( $part_numbers[ $pn ] ) ) {
				return new WP_Error(
					'conexao_multi_duplicate_part',
					sprintf(
						/* translators: %d: part number */
						__( 'The manifest lists part %d more than once.', 'conexao-event-importer' ),
						$pn
					)
				);
			}
			$part_numbers[ $pn ] = true;
		}

		ksort( $part_numbers );
		$expected = 1;
		foreach ( array_keys( $part_numbers ) as $pn ) {
			if ( $pn !== $expected ) {
				return new WP_Error(
					'conexao_multi_missing_part',
					sprintf(
						/* translators: 1: expected part number, 2: found part number */
						__( 'The manifest is missing part %1$d (found %2$d). Parts must be contiguous from 1.', 'conexao-event-importer' ),
						$expected,
						$pn
					)
				);
			}
			$expected++;
		}

		return $manifest;
	}

	/**
	 * Order files for import.
	 *
	 * @param array      $files    File list.
	 * @param array|null $manifest Optional manifest.
	 * @return array Ordered file list.
	 */
	protected function order_files( $files, $manifest = null ) {
		if ( is_array( $manifest ) && ! empty( $manifest['parts'] ) ) {
			$order_map = array();
			foreach ( $manifest['parts'] as $part ) {
				if ( ! empty( $part['filename'] ) && isset( $part['part_number'] ) ) {
					$order_map[ $part['filename'] ] = (int) $part['part_number'];
				}
			}

			$ordered = array();
			$extra   = array();

			foreach ( $files as $file ) {
				if ( isset( $order_map[ $file['name'] ] ) ) {
					$ordered[ $order_map[ $file['name'] ] ] = $file;
				} else {
					$extra[] = $file;
				}
			}

			ksort( $ordered );
			return array_merge( array_values( $ordered ), $extra );
		}

		usort(
			$files,
			function ( $a, $b ) {
				$pa = $this->parse_part_from_filename( $a['name'] );
				$pb = $this->parse_part_from_filename( $b['name'] );
				if ( $pa !== null && $pb !== null ) {
					return $pa - $pb;
				}
				if ( $pa !== null ) {
					return -1;
				}
				if ( $pb !== null ) {
					return 1;
				}
				return strcmp( $a['name'], $b['name'] );
			}
		);

		return $files;
	}

	/**
	 * Parse a part number from a filename like "stage-d-rollout-part-02.json".
	 *
	 * @param string $filename Filename.
	 * @return int|null Part number, or null.
	 */
	protected function parse_part_from_filename( $filename ) {
		if ( ! is_string( $filename ) ) {
			return null;
		}
		if ( preg_match( '/part-(\d+)/i', $filename, $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * Determine the part number for a file.
	 *
	 * @param string     $filename File name.
	 * @param array|null $manifest Optional manifest.
	 * @return int|null Part number, or null.
	 */
	protected function extract_part_number( $filename, $manifest ) {
		if ( is_array( $manifest ) ) {
			$entry = $this->find_manifest_entry( $manifest, $filename );
			if ( is_array( $entry ) && isset( $entry['part_number'] ) ) {
				return (int) $entry['part_number'];
			}
		}
		return $this->parse_part_from_filename( $filename );
	}

	/**
	 * Find the manifest entry for a given filename.
	 *
	 * @param array  $manifest Validated manifest.
	 * @param string $filename File name.
	 * @return array|null Entry, or null.
	 */
	protected function find_manifest_entry( $manifest, $filename ) {
		if ( ! is_array( $manifest ) || empty( $manifest['parts'] ) ) {
			return null;
		}
		foreach ( $manifest['parts'] as $part ) {
			if ( isset( $part['filename'] ) && $part['filename'] === $filename ) {
				return $part;
			}
		}
		return null;
	}

	/**
	 * Lightweight schema validation for a single export file.
	 *
	 * Reads and decodes the file only far enough to confirm it is a
	 * valid v1.1.0 export with event records, and to count events and
	 * featured images. Does not write anything.
	 *
	 * @param array $file File entry (tmp_name, name).
	 * @return array|WP_Error array{event_count:int, image_count:int} on success.
	 */
	protected function validate_file_schema( $file ) {
		$name = isset( $file['name'] ) ? $file['name'] : '';

		if ( empty( $file['tmp_name'] ) || ! file_exists( $file['tmp_name'] ) ) {
			return new WP_Error(
				'conexao_multi_no_tmp',
				sprintf(
					/* translators: %s: file name */
					__( 'The uploaded file %s could not be read (no temporary file).', 'conexao-event-importer' ),
					$name
				)
			);
		}

		$size = filesize( $file['tmp_name'] );
		if ( false === $size || 0 === $size ) {
			return new WP_Error(
				'conexao_multi_empty',
				sprintf(
					/* translators: %s: file name */
					__( 'The uploaded file %s is empty.', 'conexao-event-importer' ),
					$name
				)
			);
		}

		// HTML error response check.
		$fh = @fopen( $file['tmp_name'], 'r' );
		if ( false === $fh ) {
			return new WP_Error(
				'conexao_multi_read_error',
				sprintf(
					/* translators: %s: file name */
					__( 'The uploaded file %s could not be read.', 'conexao-event-importer' ),
					$name
				)
			);
		}
		$head = fread( $fh, 256 );
		fclose( $fh );
		$trimmed = ltrim( (string) $head );
		if ( '' !== $trimmed && '<' === $trimmed[0] ) {
			return new WP_Error(
				'conexao_multi_html_response',
				sprintf(
					/* translators: %s: file name */
					__( 'The uploaded file %s appears to be an HTML error response, not a JSON export.', 'conexao-event-importer' ),
					$name
				)
			);
		}

		$contents = file_get_contents( $file['tmp_name'] );
		if ( false === $contents ) {
			return new WP_Error(
				'conexao_multi_read_error',
				sprintf(
					/* translators: %s: file name */
					__( 'The uploaded file %s could not be read.', 'conexao-event-importer' ),
					$name
				)
			);
		}

		$data = json_decode( $contents, true );
		unset( $contents );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'conexao_multi_invalid_json',
				sprintf(
					/* translators: %s: file name */
					__( 'The uploaded file %s is not valid JSON.', 'conexao-event-importer' ),
					$name
				)
			);
		}

		$manifest = isset( $data['manifest'] ) ? $data['manifest'] : array();

		if ( ! isset( $manifest['format'] ) || Conexao_Event_Export::FORMAT !== $manifest['format'] ) {
			return new WP_Error(
				'conexao_multi_invalid_format',
				sprintf(
					/* translators: %s: file name */
					__( 'The uploaded file %s is not a valid Conexão event export.', 'conexao-event-importer' ),
					$name
				)
			);
		}

		if ( ! isset( $manifest['version'] ) || self::SUPPORTED_VERSION !== $manifest['version'] ) {
			return new WP_Error(
				'conexao_multi_unsupported_version',
				sprintf(
					/* translators: 1: file name, 2: file version, 3: expected version */
					__( 'The uploaded file %1$s uses export version %2$s, which is not supported. Expected %3$s.', 'conexao-event-importer' ),
					$name,
					isset( $manifest['version'] ) ? $manifest['version'] : '(none)',
					self::SUPPORTED_VERSION
				)
			);
		}

		if ( ! isset( $data['events'] ) || ! is_array( $data['events'] ) ) {
			return new WP_Error(
				'conexao_multi_no_events',
				sprintf(
					/* translators: %s: file name */
					__( 'The uploaded file %s contains no event data.', 'conexao-event-importer' ),
					$name
				)
			);
		}

		$event_count = count( $data['events'] );
		if ( 0 === $event_count ) {
			return new WP_Error(
				'conexao_multi_empty_events',
				sprintf(
					/* translators: %s: file name */
					__( 'The uploaded file %s contains an empty event list.', 'conexao-event-importer' ),
					$name
				)
			);
		}

		$image_count = 0;
		foreach ( $data['events'] as $event ) {
			$featured = isset( $event['featured'] ) ? $event['featured'] : array();
			if ( ! empty( $featured['data_base64'] ) || ! empty( $featured['banner_url'] ) || ! empty( $featured['source_url'] ) ) {
				$image_count++;
			}
		}

		unset( $data );

		return array(
			'event_count' => $event_count,
			'image_count' => $image_count,
		);
	}

	/**
	 * Return an empty totals structure.
	 *
	 * @return array
	 */
	protected function empty_totals() {
		return array(
			'files'               => 0,
			'files_success'       => 0,
			'files_failed'        => 0,
			'files_not_processed' => 0,
			'discovered'          => 0,
			'imported'            => 0,
			'updated'             => 0,
			'skipped'             => 0,
			'failed'              => 0,
			'media_discovered'    => 0,
			'errors'              => array(),
		);
	}

	/**
	 * Accumulate per-file stats into the batch totals.
	 *
	 * @param array $totals Batch totals (modified by reference).
	 * @param array $file   Per-file report.
	 */
	protected function accumulate_totals( &$totals, $file ) {
		$totals['discovered']       += $file['discovered'];
		$totals['imported']         += $file['imported'];
		$totals['updated']          += $file['updated'];
		$totals['skipped']          += $file['skipped'];
		$totals['failed']           += $file['failed'];
		$totals['media_discovered'] += $file['media_discovered'];
		foreach ( $file['errors'] as $err ) {
			$totals['errors'][] = sprintf( '%s: %s', $file['name'], $err );
		}
	}
}
