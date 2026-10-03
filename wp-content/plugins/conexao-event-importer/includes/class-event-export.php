<?php
/**
 * Event export.
 *
 * Exports all event posts (and their metadata, taxonomies, and image
 * references) into a portable JSON file that can be imported into another
 * WordPress installation running the same event structure.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Export {

	/**
	 * Format identifier written into the export manifest.
	 */
	const FORMAT = 'conexao-event-export';

	/**
	 * Export file format version.
	 *
	 * 1.1.0 adds embedded base64 image data (data_base64, filename,
	 * mime_type) so the destination site can create Media Library
	 * attachments without fetching anything from external sources.
	 */
	const FORMAT_VERSION = '1.1.0';

	/**
	 * Meta key used to store a stable unique identifier for each event.
	 *
	 * This UUID is generated on export and reused on import so events can be
	 * matched between installations without relying on database IDs.
	 */
	const UUID_META_KEY = '_event_export_uuid';

	/**
	 * Maximum size of an embedded (base64) image in bytes.
	 *
	 * Images larger than this are exported with their URLs only; the import
	 * side falls back to sideloading from the source URL when possible.
	 */
	const MAX_EMBEDDED_IMAGE_BYTES = 5242880; // 5 MB raw (~6.7 MB base64).

	/**
	 * Minimum accepted part size (events per part) for multipart exports.
	 */
	const MIN_PART_SIZE = 50;

	/**
	 * Maximum accepted part size (events per part) for multipart exports.
	 */
	const MAX_PART_SIZE = 500;

	/**
	 * Default part size (events per part) for multipart exports.
	 */
	const DEFAULT_PART_SIZE = 250;

	/**
	 * Meta keys that are exported/imported for each event.
	 *
	 * These are the event-specific fields managed by the importer plugin and
	 * consumed by the theme. System/WordPress meta is intentionally excluded.
	 *
	 * @var array
	 */
	protected $export_meta_keys = array(
		'_event_date',
		'_event_time',
		'_event_start_time',
		'_event_end_date',
		'_event_end_time',
		'_event_location',
		'_event_venue',
		'_event_address',
		'_event_map_url',
		'_event_url',
		'_event_source_url',
		'_event_banner',
		'_event_registration',
		'_event_cta',
		'_event_source',
		'_event_source_id',
		'_event_organizer',
		'_event_price',
		'_event_import_date',
		'_event_last_checked',
		'_event_status',
		'_event_imported',
		// Recurrence group: the series window + weekdays that make the
		// date/archive layer match ACTUAL occurrence dates. Exported as a unit
		// so a production record behaves identically to the local canonical
		// event. All four keys are optional — a one-time event simply has none.
		'_event_recurrence',
		'_event_recurrence_days',
		'_event_recurrence_start',
		'_event_recurrence_end',
		// Stage 3.2 — source-language classification, when classified.
		// Additive: absent on legacy records, exported as meta AND surfaced
		// as the top-level "lang" field (see export_event()).
		'_event_source_language',
	);

	/**
	 * Taxonomies that are exported/imported for each event.
	 *
	 * @var array
	 */
	protected $export_taxonomies = array(
		'conexao_category',
		'conexao_county',
		'conexao_tag',
		'conexao_town',
	);

	/**
	 * Get the total number of events available for export.
	 *
	 * @return int
	 */
	public function count_events() {
		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Build the WP_Query arguments + recorded filters for an export scope.
	 *
	 * Shared by {@see build_export()} (one-shot array) and
	 * {@see stream_export_to_file()} (memory-safe streaming) so both paths run
	 * the identical query, ordering, and filters.
	 *
	 * @param array $args Optional scope arguments (sources / after).
	 * @return array{query_args: array, filters: array}
	 */
	protected function build_query_args( $args = array() ) {
		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'sources' => array(),
				'after'   => '',
			)
		);

		$query_args = array(
			'post_type'      => 'event',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		);

		// Stage 3.2 — the export set is exactly the importer-owned records.
		// English translations are linked editorial records that SHARE the
		// identity meta; exporting them would duplicate the identity in the
		// package. Constrain to the import language deterministically (the
		// ambient admin/CLI language can vary). No-op when Polylang is
		// inactive (single-language export, same as before).
		if ( class_exists( 'Conexao_Event_Importer_Language_Guard' ) ) {
			$import_language = Conexao_Event_Importer_Language_Guard::import_language();
			if ( '' !== $import_language ) {
				$query_args['lang'] = $import_language;
			}
		}

		$meta_query = array( 'relation' => 'AND' );
		$filters    = array();

		if ( ! empty( $args['sources'] ) && is_array( $args['sources'] ) ) {
			$sources     = array_values( array_map( 'sanitize_key', $args['sources'] ) );
			$meta_query[] = array(
				'key'     => '_event_source',
				'value'   => $sources,
				'compare' => 'IN',
			);
			$filters['sources'] = $sources;
		}

		$after = trim( (string) $args['after'] );
		if ( '' !== $after && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $after ) ) {
			$meta_query[]   = array(
				'key'     => '_event_date',
				'value'   => $after,
				'compare' => '>=',
				'type'    => 'DATE',
			);
			$filters['after'] = $after;
		}

		if ( count( $meta_query ) > 1 ) {
			$query_args['meta_query'] = $meta_query;
		}

		return array(
			'query_args' => $query_args,
			'filters'    => $filters,
		);
	}

	/**
	 * Build the full export payload for all events.
	 *
	 * Optional scoping (backward compatible — with no args the behavior is
	 * identical to previous versions and the JSON format is unchanged):
	 *
	 *   - `sources` (string[]): restrict the export to events whose
	 *     `_event_source` meta is one of these source slugs.
	 *   - `after`   (string): Y-m-d cutoff — only events whose `_event_date`
	 *     is on or after this date are included.
	 *
	 * Applied filters are recorded in the manifest under `filters` so the
	 * receiving side can see the scope of the package.
	 *
	 * Note: this method holds the complete payload in memory and is only
	 * suitable for small scopes. The admin download path uses the memory-safe
	 * {@see stream_export_to_file()} instead.
	 *
	 * @param array $args Optional scope arguments (see above).
	 * @return array{
	 *   manifest: array,
	 *   events: array
	 * }
	 */
	public function build_export( $args = array() ) {
		$parsed     = $this->build_query_args( $args );
		$query_args = $parsed['query_args'];
		$filters    = $parsed['filters'];

		$query = new WP_Query( $query_args );

		$events = array();

		foreach ( $query->posts as $post ) {
			$events[] = $this->export_event( $post );
		}

		$manifest = $this->build_manifest( count( $events ), $filters );

		return array(
			'manifest' => $manifest,
			'events'   => $events,
		);
	}

	/**
	 * Build the export manifest.
	 *
	 * @param int   $event_count Number of events in the export.
	 * @param array $filters     Recorded scope filters.
	 * @return array
	 */
	protected function build_manifest( $event_count, $filters = array() ) {
		$manifest = array(
			'format'       => self::FORMAT,
			'version'      => self::FORMAT_VERSION,
			'exported_at'  => current_time( 'c' ),
			'source_url'   => home_url(),
			'event_count'  => (int) $event_count,
		);

		if ( ! empty( $filters ) ) {
			$manifest['filters'] = $filters;
		}

		return $manifest;
	}

	/**
	 * Stream an export to a file on disk, memory-safely.
	 *
	 * Produces the v1.1.0 contract (same manifest, same per-event
	 * export_event() representation, same ID-ASC ordering as build_export())
	 * but never holds the complete payload in memory: events are serialized
	 * one at a time and appended to the file. This is the same chunked
	 * strategy as the validated Stage D driver
	 * (tests/stage-d-export-chunked.php); no second export format is created.
	 *
	 * @param string $path Destination file path.
	 * @param array  $args Optional scope arguments (see build_export()).
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function stream_export_to_file( $path, $args = array() ) {
		$parsed = $this->build_query_args( $args );

		// Counting query over the same scope, for the manifest. The count is
		// cross-checked against the number of events actually written.
		$count_query = new WP_Query(
			array_merge(
				$parsed['query_args'],
				array(
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				)
			)
		);
		$expected_count = (int) $count_query->found_posts;

		$fh = @fopen( $path, 'w' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fopen failure is handled explicitly.
		if ( false === $fh ) {
			return new WP_Error(
				'conexao_export_open_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Could not open the temporary export file for writing: %s', 'conexao-event-importer' ),
					$path
				)
			);
		}

		$manifest = $this->build_manifest( $expected_count, $parsed['filters'] );

		$header = '{"manifest":' . wp_json_encode( $manifest ) . ',"events":[';
		if ( false === $header || false === fwrite( $fh, $header ) ) {
			fclose( $fh );
			return new WP_Error( 'conexao_export_write_failed', __( 'Could not write the export manifest to the temporary file.', 'conexao-event-importer' ) );
		}

		$per_page = 100;
		$page     = 1;
		$written  = 0;
		$first    = true;

		while ( true ) {
			$paged_query = new WP_Query(
				array_merge(
					$parsed['query_args'],
					array(
						'posts_per_page' => $per_page,
						'paged'          => $page,
						'no_found_rows'  => true,
					)
				)
			);

			$posts = $paged_query->posts;
			wp_reset_postdata();
			unset( $paged_query );

			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {
				$event = $this->export_event( $post );
				$chunk = wp_json_encode( $event );

				if ( false === $chunk ) {
					fclose( $fh );
					unset( $event );
					return new WP_Error(
						'conexao_export_encode_failed',
						sprintf(
							/* translators: %d: event post ID */
							__( 'Could not JSON-encode event #%d; export aborted.', 'conexao-event-importer' ),
							(int) $post->ID
						)
					);
				}

				if ( false === fwrite( $fh, ( $first ? '' : ',' ) . $chunk ) ) {
					fclose( $fh );
					unset( $event, $chunk );
					return new WP_Error( 'conexao_export_write_failed', __( 'Could not write an event to the temporary export file.', 'conexao-event-importer' ) );
				}

				$first = false;
				$written++;
				unset( $event, $chunk );
			}

			if ( count( $posts ) < $per_page ) {
				break;
			}

			// Guard against the dataset changing between the count query and
			// the paginated walk (extra pages beyond the expected count).
			if ( $written >= $expected_count ) {
				break;
			}

			$page++;

			// Periodically release accumulated object-cache entries so long
			// exports stay flat in memory (same strategy as the Stage D
			// chunked driver).
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		if ( false === fwrite( $fh, ']}' ) ) {
			fclose( $fh );
			return new WP_Error( 'conexao_export_write_failed', __( 'Could not finish writing the temporary export file.', 'conexao-event-importer' ) );
		}

		if ( false === fflush( $fh ) || false === fclose( $fh ) ) {
			return new WP_Error( 'conexao_export_write_failed', __( 'Could not flush the temporary export file to disk.', 'conexao-event-importer' ) );
		}

		if ( $written !== $expected_count ) {
			return new WP_Error(
				'conexao_export_count_mismatch',
				sprintf(
					/* translators: 1: expected event count, 2: written event count */
					__( 'Export aborted: expected %1$d events but wrote %2$d. The dataset changed during the export; please retry.', 'conexao-event-importer' ),
					$expected_count,
					$written
				)
			);
		}

		return true;
	}

	/**
	 * Export the selected events as several independent, complete v1.1.0
	 * export files (multipart export), memory-safely.
	 *
	 * Partitioning is deterministic: the selected events are snapshotted in
	 * post ID ASC order (the existing export ordering) and cut into
	 * consecutive slices of $part_size events. The same dataset and part
	 * size always produce the same Event-to-part assignment, and no event
	 * appears in more than one part.
	 *
	 * Every part is a complete, independently importable v1.1.0 export
	 * document (identical schema to the single-file export) with its own
	 * manifest. A machine-readable multipart manifest
	 * (stage-d-rollout-manifest.json) is written alongside the parts only
	 * after ALL parts have been generated successfully — a failed part
	 * aborts the run and reports which parts were completed.
	 *
	 * @param string $dir       Destination directory (created if missing).
	 * @param int    $part_size Events per part (50–500; default 250).
	 * @param array  $args      Optional scope arguments (see build_export()).
	 * @return array|WP_Error Summary on success, WP_Error on failure.
	 */
	public function export_multipart( $dir, $part_size = self::DEFAULT_PART_SIZE, $args = array() ) {
		set_time_limit( 0 );

		$part_size = (int) $part_size;
		if ( $part_size < self::MIN_PART_SIZE || $part_size > self::MAX_PART_SIZE ) {
			return new WP_Error(
				'conexao_export_invalid_part_size',
				sprintf(
					/* translators: 1: minimum part size, 2: maximum part size */
					__( 'Invalid part size: choose between %1$d and %2$d events per part.', 'conexao-event-importer' ),
					self::MIN_PART_SIZE,
					self::MAX_PART_SIZE
				)
			);
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'conexao_export_dir_failed',
				sprintf(
					/* translators: %s: directory path */
					__( 'Could not create the export directory: %s', 'conexao-event-importer' ),
					$dir
				)
			);
		}

		$parsed = $this->build_query_args( $args );

		// Deterministic snapshot of the complete ordered selection (memory
		// bounded: only post IDs are held).
		$ids_query = new WP_Query(
			array_merge(
				$parsed['query_args'],
				array(
					'fields'        => 'ids',
					'no_found_rows' => true,
				)
			)
		);
		$all_ids = array_map( 'intval', $ids_query->posts );
		wp_reset_postdata();
		unset( $ids_query );

		$total      = count( $all_ids );
		$part_count = max( 1, (int) ceil( $total / $part_size ) );

		$parts         = array();
		$uuids         = array();
		$uuid_seen     = array();
		$identity_seen = array();
		$url_seen      = array();
		$source_counts = array();
		$totals        = array(
			'events'         => 0,
			'images'         => 0,
			'uuid_dups'      => 0,
			'identity_dups'  => 0,
			'url_dups'       => 0,
			'localhost_hits' => 0,
			'missing_uuid'   => 0,
			'missing_srcid'  => 0,
		);

		for ( $i = 0; $i < $part_count; $i++ ) {
			$ids      = array_slice( $all_ids, $i * $part_size, $part_size );
			$filename = sprintf( 'stage-d-rollout-part-%02d.json', $i + 1 );
			$path     = trailingslashit( $dir ) . $filename;

			$part = $this->write_part( $path, $ids, $parsed['filters'] );

			if ( is_wp_error( $part ) ) {
				return new WP_Error(
					'conexao_export_part_failed',
					sprintf(
						/* translators: 1: part number, 2: failure reason */
						__( 'Part %1$d failed: %2$s. The run was aborted; parts generated before the failure remain on disk.', 'conexao-event-importer' ),
						$i + 1,
						$part->get_error_message()
					),
					array(
						'failed_part'     => $i + 1,
						'completed_parts' => $parts,
						'directory'       => $dir,
					)
				);
			}

			// Cross-part accounting (bounded: small per-part lists).
			foreach ( $part['uuids'] as $uuid ) {
				$uuids[] = $uuid;
				if ( isset( $uuid_seen[ $uuid ] ) ) {
					$totals['uuid_dups']++;
				} else {
					$uuid_seen[ $uuid ] = true;
				}
			}
			foreach ( $part['identities'] as $identity ) {
				if ( '' === $identity ) {
					$totals['missing_srcid']++;
					continue;
				}
				if ( isset( $identity_seen[ $identity ] ) ) {
					$totals['identity_dups']++;
				} else {
					$identity_seen[ $identity ] = true;
				}
			}
			foreach ( $part['urls'] as $url ) {
				if ( isset( $url_seen[ $url ] ) ) {
					$totals['url_dups']++;
				} else {
					$url_seen[ $url ] = true;
				}
			}

			$totals['events']         += $part['event_count'];
			$totals['images']         += $part['image_count'];
			$totals['localhost_hits'] += $part['localhost_hits'];
			$totals['missing_uuid']   += $part['missing_uuid'];
			foreach ( $part['source_counts'] as $source => $count ) {
				$source_counts[ $source ] = ( isset( $source_counts[ $source ] ) ? $source_counts[ $source ] : 0 ) + $count;
			}

			$parts[] = array(
				'part_number' => $i + 1,
				'filename'    => $filename,
				'event_count' => $part['event_count'],
				'image_count' => $part['image_count'],
				'uuid_count'  => count( $part['uuids'] ),
				'bytes'       => $part['bytes'],
				'sha256'      => hash_file( 'sha256', $path ),
				'first_uuid'  => $part['first_uuid'],
				'last_uuid'   => $part['last_uuid'],
			);

			unset( $part );
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		ksort( $source_counts );

		$validation = array(
			'schema_version_ok'     => true,
			'no_missing_uuid'       => 0 === $totals['missing_uuid'],
			'no_duplicate_uuid'     => 0 === $totals['uuid_dups'],
			'no_duplicate_identity' => 0 === $totals['identity_dups'],
			'no_duplicate_url'      => 0 === $totals['url_dups'],
			'no_localhost_urls'     => 0 === $totals['localhost_hits'],
			'event_count_matches'   => $totals['events'] === $total,
			'part_counts_match'     => $total === array_sum( wp_list_pluck( $parts, 'event_count' ) ),
		);

		$manifest = array(
			'schema'                  => self::FORMAT,
			'schema_version'          => self::FORMAT_VERSION,
			'export_mode'             => 'multipart',
			'exported_at'             => current_time( 'c' ),
			'source_url'              => home_url(),
			'part_size'               => $part_size,
			'part_count'              => count( $parts ),
			'total_event_count'       => $totals['events'],
			'total_image_count'       => $totals['images'],
			'parts'                   => $parts,
			'uuids'                   => $uuids,
			'duplicate_count'         => array(
				'uuid'     => $totals['uuid_dups'],
				'identity' => $totals['identity_dups'],
				'url'      => $totals['url_dups'],
				'total'    => $totals['uuid_dups'] + $totals['identity_dups'] + $totals['url_dups'],
			),
			'missing_uuid_count'      => $totals['missing_uuid'],
			'missing_source_id_count' => $totals['missing_srcid'],
			'source_breakdown'        => $source_counts,
			'validation'              => $validation,
			'validation_result'       => count( array_filter( $validation ) ) === count( $validation ) ? 'PASS' : 'FAIL',
		);

		if ( ! empty( $parsed['filters'] ) ) {
			$manifest['filters'] = $parsed['filters'];
		}

		$manifest_path = trailingslashit( $dir ) . 'stage-d-rollout-manifest.json';
		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $manifest_json || false === file_put_contents( $manifest_path, $manifest_json ) ) {
			return new WP_Error(
				'conexao_export_manifest_failed',
				sprintf(
					/* translators: %s: manifest path */
					__( 'All parts were generated but the manifest could not be written: %s', 'conexao-event-importer' ),
					$manifest_path
				),
				array( 'parts' => $parts, 'directory' => $dir )
			);
		}

		return array(
			'dir'               => $dir,
			'part_size'         => $part_size,
			'total_events'      => $total,
			'part_count'        => count( $parts ),
			'manifest_path'     => $manifest_path,
			'manifest'          => $manifest,
			'validation_result' => $manifest['validation_result'],
			'peak_mem_bytes'    => memory_get_peak_usage( true ),
		);
	}

	/**
	 * Write one multipart part: a complete, independently importable v1.1.0
	 * export document containing exactly the given event IDs (post ID ASC).
	 *
	 * The part is streamed event-by-event with export_event() — the full
	 * payload is never held in memory.
	 *
	 * @param string $path    Destination file path.
	 * @param array  $ids     Event post IDs for this part (ID ASC).
	 * @param array  $filters Recorded scope filters for the manifest.
	 * @return array|WP_Error Part statistics on success, WP_Error on failure.
	 */
	protected function write_part( $path, $ids, $filters ) {
		$expected = count( $ids );

		$posts = array();
		if ( ! empty( $ids ) ) {
			$query = new WP_Query(
				array(
					'post_type'      => 'event',
					'post_status'    => 'any',
					'post__in'       => $ids,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
				)
			);
			$posts = $query->posts;
			wp_reset_postdata();
			unset( $query );
		}

		if ( count( $posts ) !== $expected ) {
			return new WP_Error(
				'conexao_export_count_mismatch',
				sprintf(
					/* translators: 1: expected event count, 2: found event count */
					__( 'Export aborted: expected %1$d events in this part but found %2$d. The dataset changed during the export; please retry.', 'conexao-event-importer' ),
					$expected,
					count( $posts )
				)
			);
		}

		$fh = @fopen( $path, 'w' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fopen failure is handled explicitly.
		if ( false === $fh ) {
			return new WP_Error(
				'conexao_export_open_failed',
				sprintf(
					/* translators: %s: file path */
					__( 'Could not open the part file for writing: %s', 'conexao-event-importer' ),
					$path
				)
			);
		}

		$manifest = $this->build_manifest( $expected, $filters );
		$header   = '{"manifest":' . wp_json_encode( $manifest ) . ',"events":[';
		if ( false === $header || false === fwrite( $fh, $header ) ) {
			fclose( $fh );
			return new WP_Error( 'conexao_export_write_failed', __( 'Could not write the part manifest.', 'conexao-event-importer' ) );
		}

		$stats = array(
			'bytes'          => 0,
			'event_count'    => 0,
			'image_count'    => 0,
			'first_uuid'     => '',
			'last_uuid'      => '',
			'uuids'          => array(),
			'identities'     => array(),
			'urls'           => array(),
			'localhost_hits' => 0,
			'missing_uuid'   => 0,
			'missing_srcid'  => 0,
			'source_counts'  => array(),
		);
		$first = true;

		foreach ( $posts as $post ) {
			$event = $this->export_event( $post );
			$chunk = wp_json_encode( $event );

			if ( false === $chunk ) {
				fclose( $fh );
				unset( $event );
				return new WP_Error(
					'conexao_export_encode_failed',
					sprintf(
						/* translators: %d: event post ID */
						__( 'Could not JSON-encode event #%d; export aborted.', 'conexao-event-importer' ),
						(int) $post->ID
					)
				);
			}

			if ( false === fwrite( $fh, ( $first ? '' : ',' ) . $chunk ) ) {
				fclose( $fh );
				unset( $event, $chunk );
				return new WP_Error( 'conexao_export_write_failed', __( 'Could not write an event to the part file.', 'conexao-event-importer' ) );
			}
			$first = false;

			$meta = isset( $event['meta'] ) && is_array( $event['meta'] ) ? $event['meta'] : array();
			$fi   = isset( $event['featured_image'] ) && is_array( $event['featured_image'] ) ? $event['featured_image'] : array();

			$uuid = isset( $event['uuid'] ) ? (string) $event['uuid'] : '';
			if ( '' === $uuid ) {
				$stats['missing_uuid']++;
			} else {
				if ( '' === $stats['first_uuid'] ) {
					$stats['first_uuid'] = $uuid;
				}
				$stats['last_uuid'] = $uuid;
				$stats['uuids'][]   = $uuid;
			}

			$src = isset( $meta['_event_source'] ) ? (string) $meta['_event_source'] : '';
			$sid = isset( $meta['_event_source_id'] ) ? (string) $meta['_event_source_id'] : '';
			$stats['identities'][] = ( '' !== $sid ) ? $src . '|' . $sid : '';
			if ( '' !== $src ) {
				$stats['source_counts'][ $src ] = ( isset( $stats['source_counts'][ $src ] ) ? $stats['source_counts'][ $src ] : 0 ) + 1;
			}

			foreach ( array( '_event_url', '_event_source_url', '_event_map_url', '_event_banner' ) as $uk ) {
				if ( ! empty( $meta[ $uk ] ) && preg_match( '#https?://(localhost|127\.0\.0\.1)#i', (string) $meta[ $uk ] ) ) {
					$stats['localhost_hits']++;
				}
			}
			if ( ! empty( $fi['source_url'] ) && preg_match( '#https?://(localhost|127\.0\.0\.1)#i', (string) $fi['source_url'] ) ) {
				$stats['localhost_hits']++;
			}

			if ( ! empty( $fi['data_base64'] ) ) {
				$stats['image_count']++;
			}
			if ( ! empty( $meta['_event_url'] ) ) {
				$stats['urls'][] = (string) $meta['_event_url'];
			}

			$stats['event_count']++;
			unset( $event, $chunk );
		}

		if ( false === fwrite( $fh, ']}' ) ) {
			fclose( $fh );
			return new WP_Error( 'conexao_export_write_failed', __( 'Could not finish writing the part file.', 'conexao-event-importer' ) );
		}

		if ( false === fflush( $fh ) || false === fclose( $fh ) ) {
			return new WP_Error( 'conexao_export_write_failed', __( 'Could not flush the part file to disk.', 'conexao-event-importer' ) );
		}

		$stats['bytes'] = filesize( $path );

		return $stats;
	}

	/**
	 * Export a single event post into a portable array.
	 *
	 * @param WP_Post $post Event post object.
	 * @return array
	 */
	protected function export_event( $post ) {
		$uuid = get_post_meta( $post->ID, self::UUID_META_KEY, true );
		if ( empty( $uuid ) ) {
			$uuid = $this->generate_uuid();
			update_post_meta( $post->ID, self::UUID_META_KEY, $uuid );
		}

		// Collect the event meta fields.
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

		// Featured image / banner info.
		$featured_image = $this->export_featured_image( $post );

		// Stage 3.2 — additive source-language field. One of
		// pt|en|other|unknown (Conexao_Event_Source_Language). 'unknown'
		// covers every record with no explicit classification (legacy
		// imports, manual events, sources without a language signal).
		// Read-only: the export never mutates event content or meta beyond
		// the pre-existing UUID provisioning above.
		$lang = class_exists( 'Conexao_Event_Source_Language' )
			? Conexao_Event_Source_Language::export_value( get_post_meta( $post->ID, Conexao_Event_Source_Language::META_KEY, true ) )
			: 'unknown';

		return array(
			'uuid'          => $uuid,
			'lang'          => $lang,
			'post'          => array(
				'title'    => $post->post_title,
				'content'  => $post->post_content,
				'excerpt'  => $post->post_excerpt,
				'status'   => $post->post_status,
				'slug'     => $post->post_name,
				'date'     => $post->post_date,
				'modified' => $post->post_modified,
			),
			'meta'          => $meta,
			'taxonomies'    => $taxonomies,
			'featured_image' => $featured_image,
		);
	}

	/**
	 * Export the featured image / banner references for an event.
	 *
	 * Because the production site cannot reliably fetch images from the
	 * original external sources, the image bytes are embedded in the export
	 * (base64-encoded) alongside the metadata. The import side creates a real
	 * Media Library attachment from this data — no external requests needed.
	 *
	 * @param WP_Post $post Event post object.
	 * @return array
	 */
	protected function export_featured_image( $post ) {
		$result = array(
			'source_url'   => '',
			'banner_url'   => '',
			'alt'          => '',
			'filename'     => '',
			'mime_type'    => '',
			'data_base64'  => '',
		);

		$banner_url = get_post_meta( $post->ID, '_event_banner', true );
		if ( $banner_url ) {
			$result['banner_url'] = $banner_url;
		}

		$attachment_id = get_post_meta( $post->ID, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true );
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			$attachment_id = get_post_thumbnail_id( $post->ID );
		}

		if ( $attachment_id && wp_attachment_is_image( $attachment_id ) ) {
			$source_url = get_post_meta( $attachment_id, '_event_source_url', true );
			if ( $source_url ) {
				$result['source_url'] = $source_url;
			}

			$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( $alt ) {
				$result['alt'] = $alt;
			}

			// Embed the actual image bytes so the destination site can create
			// the attachment locally without contacting any external source.
			$file = get_attached_file( $attachment_id );
			if ( $file && file_exists( $file ) && is_readable( $file ) ) {
				$bytes = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read.
				if ( false !== $bytes && strlen( $bytes ) <= self::MAX_EMBEDDED_IMAGE_BYTES ) {
					$result['data_base64'] = base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Portable binary transport in JSON.
					$result['mime_type']   = get_post_mime_type( $attachment_id );

					// Preserve the original filename when available.
					$filename = basename( $file );
					if ( $filename ) {
						$result['filename'] = $filename;
					}
				}
			}
		}

		// Prefer the external source URL as the primary reference.
		if ( empty( $result['source_url'] ) && $banner_url ) {
			$result['source_url'] = $banner_url;
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
	 * Memory-safe: the export is written incrementally to a temporary file
	 * (same chunked strategy as the validated Stage D driver) and then sent
	 * with readfile(), which streams the file without loading it into memory.
	 * The full payload is never held in memory and wp_json_encode() is never
	 * called on the complete dataset.
	 *
	 * Failures produce a clear admin error instead of a partial download.
	 *
	 * @return void
	 */
	public function download() {
		$temp = function_exists( 'wp_tempnam' )
			? wp_tempnam( 'conexao-events-export' )
			: tempnam( get_temp_dir(), 'conexao-events-' );

		if ( ! $temp ) {
			wp_die( esc_html__( 'Event export failed: could not create a temporary file.', 'conexao-event-importer' ) );
		}

		$result = $this->stream_export_to_file( $temp );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $temp );
			wp_die( esc_html( sprintf(
				/* translators: %s: failure reason */
				__( 'Event export failed: %s', 'conexao-event-importer' ),
				$result->get_error_message()
			) ) );
		}

		$size = filesize( $temp );
		if ( false === $size || $size <= 0 ) {
			wp_delete_file( $temp );
			wp_die( esc_html__( 'Event export failed: the temporary export file is empty.', 'conexao-event-importer' ) );
		}

		// Cheap integrity check without loading the file: the stream writer
		// always terminates the document with ']}'.
		$tail = '';
		$tfh  = fopen( $temp, 'r' );
		if ( $tfh ) {
			fseek( $tfh, -2, SEEK_END );
			$tail = (string) fread( $tfh, 2 );
			fclose( $tfh );
		}
		if ( ']}' !== $tail ) {
			wp_delete_file( $temp );
			wp_die( esc_html__( 'Event export failed: the temporary export file is incomplete.', 'conexao-event-importer' ) );
		}

		$filename = 'conexao-events-' . gmdate( 'Y-m-d-His' ) . '.json';

		nocache_headers();

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );

		// Remove the temp file when the request ends (the client may abort
		// the download before the script returns).
		register_shutdown_function(
			static function () use ( $temp ) {
				if ( file_exists( $temp ) ) {
					wp_delete_file( $temp );
				}
			}
		);

		readfile( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Streams the file to the output buffer in chunks.
		exit;
	}
}
