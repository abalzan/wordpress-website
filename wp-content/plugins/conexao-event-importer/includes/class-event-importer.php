<?php
/**
 * Core event importer engine.
 *
 * Orchestrates fetching, normalizing, deduplicating, and storing events
 * from configured sources into the central WordPress event database.
 *
 * Error handling: each event is processed inside its own try/catch so a
 * single failing event never aborts the remaining events in the source.
 * Global failures (API unreachable, malformed source data, etc.) are
 * reported as fatal errors while per-event failures are reported with
 * the event title and a human-readable reason.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Importer_Engine {

	/** @var Conexao_Event_Sources */
	protected $sources;

	/** @var Conexao_Event_Location */
	protected $location;

	/** @var Conexao_Event_Normalizer */
	protected $normalizer;

	/** @var Conexao_Event_Deduplicator */
	protected $deduplicator;

	/** @var Conexao_Event_Image_Handler */
	protected $image_handler;

	/** @var string Current import run ID (set per run_source call). */
	protected $current_run_id = '';

	/**
	 * Constructor.
	 *
	 * @param Conexao_Event_Sources  $sources  Sources manager.
	 * @param Conexao_Event_Location $location Location normalizer.
	 */
	public function __construct( Conexao_Event_Sources $sources, Conexao_Event_Location $location ) {
		$this->sources        = $sources;
		$this->location       = $location;
		$this->normalizer     = new Conexao_Event_Normalizer( $location );
		$this->deduplicator   = new Conexao_Event_Deduplicator();
		$this->image_handler  = new Conexao_Event_Image_Handler();

		// Hook the "run source" filter used by the admin UI.
		add_filter( 'conexao_event_importer_run_source', array( $this, 'run_source' ), 10, 1 );

		// Hook the "run all" filter used by the admin dashboard.
		add_filter( 'conexao_event_importer_run_all', array( $this, 'run_all' ), 10, 1 );

		// Expose the current import run ID for structured log correlation.
		add_filter( 'conexao_event_importer_current_run_id', array( $this, 'get_current_run_id' ) );
	}

	/**
	 * Get the current import run ID.
	 *
	 * @return string
	 */
	public function get_current_run_id() {
		return $this->current_run_id;
	}

	/**
	 * Run a full import across all active sources.
	 *
	 * Returns a combined result array with per-source stats, event outcomes,
	 * and any fatal errors. Individual source failures do not stop other sources.
	 *
	 * @param array $args Optional arguments:
	 *   - dry_run   (bool)  Fetch/normalize/classify without writing anything.
	 *   - source_ids (array) Restrict the run to these source slugs.
	 * @return array Combined result array.
	 */
	public function run_all( $args = array() ) {
		$args    = wp_parse_args( is_array( $args ) ? $args : array(), array( 'dry_run' => false, 'source_ids' => array() ) );
		$dry_run = ! empty( $args['dry_run'] );

		// Start a fresh run ID for the combined run.
		$this->current_run_id = 'all-' . (string) microtime( true );

		$combined = array(
			'found'                => 0,
			'created'              => 0,
			'updated'              => 0,
			'unchanged'            => 0,
			'duplicates'           => 0,
			'skipped'              => 0,
			'skipped_past'         => 0,
			'skipped_invalid_date' => 0,
			'needs_review'         => 0,
			'failed'               => 0,
			'errors'               => 0,
			'new'                  => 0,
			'fatal_errors'         => array(),
			'event_results'        => array(),
			'status'               => 'success',
			'sources'              => array(),
			'dry_run'              => $dry_run,
		);

		$active_sources = $this->sources->get_active();

		// Optionally restrict to a subset of sources.
		if ( ! empty( $args['source_ids'] ) && is_array( $args['source_ids'] ) ) {
			$allowed        = array_map( 'strval', $args['source_ids'] );
			$active_sources = array_filter(
				$active_sources,
				function ( $source ) use ( $allowed ) {
					return isset( $source['id'] ) && in_array( $source['id'], $allowed, true );
				}
			);
		}

		// Guard against no active sources.
		if ( empty( $active_sources ) ) {
			$combined['status'] = 'warning';
			$combined['fatal_errors'][] = array(
				'message'   => __( 'No active event sources are configured. Enable at least one source to import events.', 'conexao-event-importer' ),
				'technical' => '',
			);
			return $combined;
		}

		foreach ( $active_sources as $source ) {
			$result = $this->run_source( $source['id'], $dry_run );

			// Aggregate counters.
			$combined['found']        += isset( $result['found'] ) ? (int) $result['found'] : 0;
			$combined['created']      += isset( $result['created'] ) ? (int) $result['created'] : 0;
			$combined['new']          += isset( $result['new'] ) ? (int) $result['new'] : 0;
			$combined['updated']      += isset( $result['updated'] ) ? (int) $result['updated'] : 0;
			$combined['unchanged']    += isset( $result['unchanged'] ) ? (int) $result['unchanged'] : 0;
			$combined['duplicates']           += isset( $result['duplicates'] ) ? (int) $result['duplicates'] : 0;
			$combined['skipped']              += isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
			$combined['skipped_past']         += isset( $result['skipped_past'] ) ? (int) $result['skipped_past'] : 0;
			$combined['skipped_invalid_date'] += isset( $result['skipped_invalid_date'] ) ? (int) $result['skipped_invalid_date'] : 0;
			$combined['needs_review']         += isset( $result['needs_review'] ) ? (int) $result['needs_review'] : 0;
			$combined['failed']       += isset( $result['failed'] ) ? (int) $result['failed'] : 0;
			$combined['errors']       += isset( $result['errors'] ) ? (int) $result['errors'] : 0;

			// Merge fatal errors.
			if ( ! empty( $result['fatal_errors'] ) && is_array( $result['fatal_errors'] ) ) {
				$combined['fatal_errors'] = array_merge( $combined['fatal_errors'], $result['fatal_errors'] );
			}

			// Merge event results.
			if ( ! empty( $result['event_results'] ) && is_array( $result['event_results'] ) ) {
				$combined['event_results'] = array_merge( $combined['event_results'], $result['event_results'] );
			}

			// Merge per-source stats.
			$combined['sources'][ $source['id'] ] = $result;

			// Derive combined status.
			if ( 'failed' === $result['status'] ) {
				if ( 'failed' !== $combined['status'] ) {
					$combined['status'] = 'failed';
				}
			} elseif ( 'partial' === $result['status'] ) {
				if ( 'success' === $combined['status'] ) {
					$combined['status'] = 'partial';
				}
			} elseif ( 'warning' === $result['status'] ) {
				if ( 'success' === $combined['status'] ) {
					$combined['status'] = 'warning';
				}
			}
		}

		// Mark past events as expired.
		Conexao_Event_Status::mark_expired_events();

		// Record a combined history entry when more than one source ran.
		if ( count( $combined['sources'] ) > 1 ) {
			Conexao_Import_History::record(
				'all',
				$combined,
				$combined['status'],
				'',
				array(
					'run_id'        => $this->current_run_id,
					'fatal_errors'  => $combined['fatal_errors'],
					'failed_events' => self::extract_failed_events( $combined['event_results'] ),
				)
			);
		}

		return $combined;
	}

	/**
	 * Run an import for a single source.
	 *
	 * Returns a stats array that includes both the legacy keys
	 * (found, new, updated, unchanged, duplicates, needs_review, errors)
	 * and the new keys (created, skipped, failed, status, fatal_errors,
	 * event_results).
	 *
	 * Per-event failures are isolated: one event failing does not stop the
	 * remaining events. Global failures (unreachable API, invalid source data)
	 * stop the source and report a fatal error.
	 *
	 * @param string $source_id Source slug.
	 * @param bool   $dry_run   When true, fetch/normalize/classify without
	 *                          writing anything to the database.
	 * @return array Stats for this source.
	 */
	public function run_source( $source_id, $dry_run = false ) {
		// Start a fresh run ID for log correlation.
		$this->current_run_id = (string) microtime( true );

		$source = $this->sources->get( $source_id );
		if ( ! $source ) {
			return array(
				'found'         => 0,
				'new'           => 0,
				'created'       => 0,
				'updated'       => 0,
				'unchanged'     => 0,
				'duplicates'    => 0,
				'skipped'       => 0,
				'needs_review'  => 0,
				'errors'        => 0,
				'failed'        => 0,
				'status'        => 'failed',
				'fatal_errors'  => array( array( 'message' => __( 'Event source not found.', 'conexao-event-importer' ), 'technical' => '' ) ),
				'event_results' => array(),
			);
		}

		if ( 'active' !== $source['status'] ) {
			return array(
				'found'         => 0,
				'new'           => 0,
				'created'       => 0,
				'updated'       => 0,
				'unchanged'     => 0,
				'duplicates'    => 0,
				'skipped'       => 0,
				'needs_review'  => 0,
				'errors'        => 0,
				'failed'        => 0,
				'status'        => 'warning',
				'fatal_errors'  => array(),
				'event_results' => array(),
			);
		}

		$result = new Conexao_Import_Result( $source_id, $source['name'] );

		// Log the start of the run for lifecycle tracking.
		Conexao_Import_Log::add(
			$source_id,
			'info',
			sprintf(
				/* translators: %s: source name */
				__( 'Import started for %s.', 'conexao-event-importer' ),
				$source['name']
			),
			array( 'run_id' => $this->current_run_id )
		);

		$handler = $this->get_source_handler( $source );
		if ( ! $handler ) {
			$message = __( 'No import handler is registered for this event source.', 'conexao-event-importer' );
			Conexao_Import_Log::add( $source_id, 'error', $message, array( 'run_id' => $this->current_run_id ) );
			$result->add_fatal_error( $message );
			$this->update_source_stats_after_run( $source_id, $result );
			Conexao_Import_History::record( $source_id, $result->get_stats(), $result->get_status(), $message, array(
				'run_id'        => $this->current_run_id,
				'fatal_errors'  => $result->get_fatal_errors(),
				'failed_events' => $result->get_failed_events(),
			) );
			return $result->get_stats();
		}

		try {
			$raw_events = $handler->fetch_events();
		} catch ( Conexao_Source_Fetch_Exception $e ) {
			// Structured transport/HTTP failure — a clean fatal error, not an
			// ambiguous "no events" result.
			$message = sprintf(
				/* translators: %s: source name */
				__( 'Failed to fetch events from %1$s. HTTP status: %2$d.', 'conexao-event-importer' ),
				$source['name'],
				$e->get_http_status()
			);
			Conexao_Import_Log::add( $source_id, 'error', $message, array(
				'run_id'      => $this->current_run_id,
				'http_status' => $e->get_http_status(),
			) );
			$result->add_fatal_error( $message, $e->getMessage() );
			$this->update_source_stats_after_run( $source_id, $result );
			Conexao_Import_History::record( $source_id, $result->get_stats(), $result->get_status(), $message, array(
				'run_id'        => $this->current_run_id,
				'fatal_errors'  => $result->get_fatal_errors(),
				'failed_events' => $result->get_failed_events(),
			) );
			return $result->get_stats();
		} catch ( Exception $e ) {
			$message = sprintf(
				/* translators: %s: source name */
				__( 'Failed to fetch events from %s. The source may be temporarily unavailable.', 'conexao-event-importer' ),
				$source['name']
			);
			Conexao_Import_Log::add( $source_id, 'error', $message, array(
				'run_id' => $this->current_run_id,
			) );
			$result->add_fatal_error( $message, $e->getMessage() );
			$this->update_source_stats_after_run( $source_id, $result );
			Conexao_Import_History::record( $source_id, $result->get_stats(), $result->get_status(), $message, array(
				'run_id'        => $this->current_run_id,
				'fatal_errors'  => $result->get_fatal_errors(),
				'failed_events' => $result->get_failed_events(),
			) );
			return $result->get_stats();
		}

		if ( ! is_array( $raw_events ) ) {
			$message = sprintf(
				/* translators: %s: source name */
				__( 'The %s source returned an invalid response. No events were imported.', 'conexao-event-importer' ),
				$source['name']
			);
			Conexao_Import_Log::add( $source_id, 'error', $message, array( 'run_id' => $this->current_run_id ) );
			$result->add_fatal_error( $message );
			$this->update_source_stats_after_run( $source_id, $result );
			Conexao_Import_History::record( $source_id, $result->get_stats(), $result->get_status(), $message, array(
				'run_id'        => $this->current_run_id,
				'fatal_errors'  => $result->get_fatal_errors(),
				'failed_events' => $result->get_failed_events(),
			) );
			return $result->get_stats();
		}

		$result->set_found( count( $raw_events ) );

		if ( $dry_run ) {
			return $this->dry_run_source( $source_id, $source, $raw_events );
		}

		foreach ( $raw_events as $raw ) {
			$raw['source'] = $source_id;

			$event_title = isset( $raw['title'] ) ? trim( (string) $raw['title'] ) : '';
			$event_id    = isset( $raw['source_id'] ) ? trim( (string) $raw['source_id'] ) : '';

			// Per-event error isolation: one failing event must not abort the rest.
			try {
				$normalized = $this->normalizer->normalize( $raw );

				// Past-event filter: never create or update events whose
				// relevant date/time has already passed. Runs before
				// deduplication/upsert so expired source events never touch
				// WordPress. Previously imported posts are left untouched —
				// the separate cleanup process remains responsible for them.
				$date_check = Conexao_Event_Date_Filter::evaluate( $normalized );

				if ( Conexao_Event_Date_Filter::INVALID === $date_check['status'] ) {
					$reason = __( 'Skipped: the event date could not be evaluated (missing, malformed, or unparseable date).', 'conexao-event-importer' );
					$result->add_skipped_invalid_date( $normalized['title'], $reason );
					Conexao_Import_Log::add(
						$source_id,
						'warning',
						sprintf(
							/* translators: %s: event title */
							__( 'Event skipped (invalid date): %s', 'conexao-event-importer' ),
							$normalized['title']
						),
						array(
							'run_id'      => $this->current_run_id,
							'event_id'    => $event_id,
							'event_title' => $normalized['title'],
						)
					);
					continue;
				}

				if ( Conexao_Event_Date_Filter::PAST === $date_check['status'] ) {
					$reason = sprintf(
						/* translators: %s: event end date/time */
						__( 'Skipped: the event has already ended (%s).', 'conexao-event-importer' ),
						$date_check['cutoff_label']
					);
					$result->add_skipped_past( $normalized['title'], $reason );
					Conexao_Import_Log::add(
						$source_id,
						'info',
						sprintf(
							/* translators: 1: event title, 2: event end date/time */
							__( 'Event skipped (past): %1$s — ended %2$s.', 'conexao-event-importer' ),
							$normalized['title'],
							$date_check['cutoff_label']
						),
						array(
							'run_id'      => $this->current_run_id,
							'event_id'    => $event_id,
							'event_title' => $normalized['title'],
						)
					);
					continue;
				}

				// Always check for an existing event first, even for needs-review
				// events, to avoid creating duplicates on repeated imports.
				$existing_id = $this->deduplicator->find( $normalized );

				if ( $normalized['needs_review'] ) {
					$result->add_needs_review( $normalized['title'], $normalized['review_notes'], $existing_id );
					$upsert = $this->upsert_event( $normalized, true, $existing_id );
					if ( 'error' === $upsert['action'] ) {
						$result->add_failed(
							$normalized['title'],
							__( 'The event could not be saved to WordPress.', 'conexao-event-importer' ),
							isset( $upsert['error'] ) ? $upsert['error'] : ''
						);
					}
					continue;
				}

				$upsert = $this->upsert_event( $normalized, false, $existing_id );

				switch ( $upsert['action'] ) {
					case 'created':
						$result->add_created( $normalized['title'], $upsert['post_id'] );
						Conexao_Import_Log::add(
							$source_id,
							'info',
							sprintf(
								/* translators: %s: event title */
								__( 'Event created: %s', 'conexao-event-importer' ),
								$normalized['title']
							),
							array(
								'run_id'      => $this->current_run_id,
								'event_id'    => $event_id,
								'event_title' => $normalized['title'],
								'post_id'     => isset( $upsert['post_id'] ) ? (int) $upsert['post_id'] : 0,
							)
						);
						break;
					case 'updated':
						$result->add_updated( $normalized['title'], $upsert['post_id'] );
						Conexao_Import_Log::add(
							$source_id,
							'info',
							sprintf(
								/* translators: %s: event title */
								__( 'Event updated: %s', 'conexao-event-importer' ),
								$normalized['title']
							),
							array(
								'run_id'      => $this->current_run_id,
								'event_id'    => $event_id,
								'event_title' => $normalized['title'],
								'post_id'     => isset( $upsert['post_id'] ) ? (int) $upsert['post_id'] : 0,
							)
						);
						break;
					case 'unchanged':
						$result->add_unchanged( $normalized['title'], $upsert['post_id'] );
						break;
					case 'duplicate':
						$result->add_duplicate( $normalized['title'], $upsert['post_id'] );
						break;
					case 'skipped':
						$result->add_skipped( $normalized['title'], isset( $upsert['reason'] ) ? $upsert['reason'] : '' );
						Conexao_Import_Log::add(
							$source_id,
							'warning',
							sprintf(
								/* translators: 1: event title, 2: skip reason */
								__( 'Event skipped: %1$s. %2$s', 'conexao-event-importer' ),
								$normalized['title'],
								isset( $upsert['reason'] ) ? $upsert['reason'] : ''
							),
							array(
								'run_id'      => $this->current_run_id,
								'event_id'    => $event_id,
								'event_title' => $normalized['title'],
							)
						);
						break;
					case 'error':
					default:
						$reason = isset( $upsert['error'] ) ? $upsert['error'] : __( 'The event could not be saved to WordPress.', 'conexao-event-importer' );
						$result->add_failed( $normalized['title'], $reason );
						Conexao_Import_Log::add(
							$source_id,
							'error',
							sprintf(
								/* translators: 1: event title, 2: error message */
								__( 'Failed to import event: %1$s. %2$s', 'conexao-event-importer' ),
								$normalized['title'],
								$reason
							),
							array(
								'run_id'       => $this->current_run_id,
								'event_id'     => $event_id,
								'event_title'  => $normalized['title'],
							)
						);
						break;
				}
			} catch ( Exception $e ) {
				// Unexpected per-event failure. Log it, count it, continue.
				$title = $event_title ? $event_title : __( '(unknown event)', 'conexao-event-importer' );
				$result->add_failed(
					$title,
					__( 'An unexpected error interrupted this event.', 'conexao-event-importer' ),
					$e->getMessage()
				);
				Conexao_Import_Log::add(
					$source_id,
					'error',
					sprintf(
						/* translators: 1: event title, 2: error message */
						__( 'Unexpected error importing "%1$s": %2$s', 'conexao-event-importer' ),
						$title,
						$e->getMessage()
					),
					array(
						'run_id'      => $this->current_run_id,
						'event_id'    => $event_id,
						'event_title' => $title,
					)
				);
			}
		}

		// Mark events from this source that disappeared as "Source Not Found".
		$this->mark_missing_events( $source_id, $raw_events );

		// Update the source's last-import metadata.
		$this->update_source_stats_after_run( $source_id, $result );

		// Build a human-readable summary message.
		$message = $this->build_summary_message( $result );

		// Record the history entry with failure details.
		Conexao_Import_History::record( $source_id, $result->get_stats(), $result->get_status(), $message, array(
			'run_id'        => $this->current_run_id,
			'fatal_errors'  => $result->get_fatal_errors(),
			'failed_events' => $result->get_failed_events(),
		) );

		// Log the run summary (info level, one line, not huge).
		$counts = $result->get_counts();
		Conexao_Import_Log::add(
			$source_id,
			'info',
			sprintf(
				/* translators: 1: source name, 2: created count, 3: updated count, 4: total skipped, 5: skipped as past, 6: skipped with invalid date, 7: failed count */
				__( 'Import of %1$s completed: %2$d created, %3$d updated, %4$d skipped (%5$d past, %6$d invalid date), %7$d failed.', 'conexao-event-importer' ),
				$source['name'],
				(int) $counts['created'],
				(int) $counts['updated'],
				(int) $counts['skipped'],
				(int) $counts['skipped_past'],
				(int) $counts['skipped_invalid_date'],
				(int) $counts['failed']
			),
			array( 'run_id' => $this->current_run_id )
		);

		return $result->get_stats();
	}

	/**
	 * Classify a fetched batch of raw events without writing anything.
	 *
	 * Used by --dry-run: fetches and normalizes exactly like a real run,
	 * then reports what WOULD happen (create / update / needs review) based
	 * on the deduplicator — no posts, meta or images are touched.
	 *
	 * @param string $source_id  Source slug.
	 * @param array  $source     Source config.
	 * @param array  $raw_events Raw events from the handler.
	 * @return array Stats for this source.
	 */
	protected function dry_run_source( $source_id, $source, $raw_events ) {
		$result = new Conexao_Import_Result( $source_id, $source['name'] );
		$result->set_found( count( $raw_events ) );

		foreach ( $raw_events as $raw ) {
			$raw['source'] = $source_id;

			try {
				$normalized = $this->normalizer->normalize( $raw );

				// Past-event filter (dry-run): report past/invalid-date events
				// as skipped without touching anything.
				$date_check = Conexao_Event_Date_Filter::evaluate( $normalized );

				if ( Conexao_Event_Date_Filter::INVALID === $date_check['status'] ) {
					$result->add_skipped_invalid_date(
						$normalized['title'],
						__( 'Would skip: the event date could not be evaluated (missing, malformed, or unparseable date).', 'conexao-event-importer' )
					);
					continue;
				}

				if ( Conexao_Event_Date_Filter::PAST === $date_check['status'] ) {
					$result->add_skipped_past(
						$normalized['title'],
						sprintf(
							/* translators: %s: event end date/time */
							__( 'Would skip: the event has already ended (%s).', 'conexao-event-importer' ),
							$date_check['cutoff_label']
						)
					);
					continue;
				}

				if ( $normalized['needs_review'] ) {
					$result->add_needs_review( $normalized['title'], $normalized['review_notes'], 0 );
					continue;
				}

				$existing_id = $this->deduplicator->find( $normalized );

				if ( $existing_id ) {
					// Cannot know "unchanged" without reading all stored fields;
					// report as would-update for transparency.
					$result->add_updated( $normalized['title'] . ' (would update)', $existing_id );
				} else {
					$result->add_created( $normalized['title'] . ' (would create)', 0 );
				}
			} catch ( Exception $e ) {
				$result->add_failed(
					isset( $raw['title'] ) ? (string) $raw['title'] : '',
					__( 'An unexpected error interrupted this event.', 'conexao-event-importer' ),
					$e->getMessage()
				);
			}
		}

		$stats                 = $result->get_stats();
		$stats['dry_run']      = true;
		$stats['source_name']  = isset( $source['name'] ) ? $source['name'] : $source_id;

		Conexao_Import_Log::add(
			$source_id,
			'info',
			sprintf(
				/* translators: 1: source name, 2: found count, 3: would-create count, 4: would-update count, 5: review count, 6: would-skip count */
				__( 'Dry-run of %1$s completed: %2$d found, %3$d would be created, %4$d would be updated, %5$d need review, %6$d would be skipped (past or invalid date). Nothing was written.', 'conexao-event-importer' ),
				isset( $source['name'] ) ? $source['name'] : $source_id,
				count( $raw_events ),
				(int) $stats['created'],
				(int) $stats['updated'],
				(int) $stats['needs_review'],
				(int) $stats['skipped']
			),
			array( 'run_id' => $this->current_run_id )
		);

		return $stats;
	}

	/**
	 * Update the source's last-import metadata after a run.
	 *
	 * Also feeds the per-source health tracker (consecutive failures,
	 * auto-disable) used by the automation layer.
	 *
	 * @param string                $source_id Source slug.
	 * @param Conexao_Import_Result $result    Import result.
	 */
	protected function update_source_stats_after_run( $source_id, Conexao_Import_Result $result ) {
		$status = $result->get_status();

		// Map the new statuses to the legacy two-state (success|error) used in
		// the sources option, while keeping the richer status in the history.
		$legacy_status = ( 'failed' === $status ) ? 'error' : 'success';

		$stats = array(
			'last_import'        => current_time( 'mysql' ),
			'last_import_status' => $legacy_status,
			'events_imported'    => $result->get_counts()['created'],
			'last_error'         => '',
		);

		// Set the last_error message on failures.
		if ( 'failed' === $status ) {
			$fatal = $result->get_fatal_errors();
			if ( ! empty( $fatal ) ) {
				$stats['last_error'] = $fatal[0]['message'];
			} elseif ( $result->get_counts()['failed'] > 0 ) {
				$stats['last_error'] = sprintf(
					/* translators: %d: number of failed events */
					__( '%d event(s) failed to import.', 'conexao-event-importer' ),
					$result->get_counts()['failed']
				);
			}
		} elseif ( 'partial' === $status ) {
			$stats['last_import_status'] = 'success'; // Partial is still a completed run.
			$stats['last_error'] = sprintf(
				/* translators: %d: number of failed events */
				__( '%d event(s) failed but the rest were imported.', 'conexao-event-importer' ),
				$result->get_counts()['failed']
			);
		} elseif ( 'warning' === $status ) {
			$stats['last_import_status'] = 'success';
		}

		$this->sources->update_import_stats( $source_id, $stats );

		// Feed the health tracker: record the outcome for admin visibility.
		// Sources are never auto-disabled — an administrator decides.
		if ( class_exists( 'Conexao_Source_Health' ) ) {
			if ( 'failed' === $status ) {
				$reason = ! empty( $stats['last_error'] ) ? $stats['last_error'] : __( 'Unknown import failure.', 'conexao-event-importer' );
				Conexao_Source_Health::record_failure( $source_id, $reason );
			} else {
				Conexao_Source_Health::record_success( $source_id );
			}
		}
	}

	/**
	 * Build a human-readable summary message for an import result.
	 *
	 * @param Conexao_Import_Result $result Import result.
	 * @return string
	 */
	protected function build_summary_message( Conexao_Import_Result $result ) {
		$counts = $result->get_counts();

		if ( 'failed' === $result->get_status() ) {
			$fatal = $result->get_fatal_errors();
			if ( ! empty( $fatal ) ) {
				return $fatal[0]['message'];
			}
			return __( 'Import failed. See details below.', 'conexao-event-importer' );
		}

		$parts   = array();
		$parts[] = sprintf(
			/* translators: %d: number of events found */
			__( '%d events found', 'conexao-event-importer' ),
			$counts['found']
		);
		if ( $counts['created'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of events created */
				__( '%d created', 'conexao-event-importer' ),
				$counts['created']
			);
		}
		if ( $counts['updated'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of events updated */
				__( '%d updated', 'conexao-event-importer' ),
				$counts['updated']
			);
		}
		if ( $counts['unchanged'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of unchanged events */
				__( '%d unchanged', 'conexao-event-importer' ),
				$counts['unchanged']
			);
		}
		if ( $counts['skipped'] > 0 ) {
			$skipped_past    = isset( $counts['skipped_past'] ) ? (int) $counts['skipped_past'] : 0;
			$skipped_invalid = isset( $counts['skipped_invalid_date'] ) ? (int) $counts['skipped_invalid_date'] : 0;

			if ( $skipped_past > 0 || $skipped_invalid > 0 ) {
				$parts[] = sprintf(
					/* translators: 1: total skipped, 2: skipped because already ended, 3: skipped with invalid dates */
					__( '%1$d skipped (%2$d past, %3$d invalid date)', 'conexao-event-importer' ),
					$counts['skipped'],
					$skipped_past,
					$skipped_invalid
				);
			} else {
				$parts[] = sprintf(
					/* translators: %d: number of skipped events */
					__( '%d skipped', 'conexao-event-importer' ),
					$counts['skipped']
				);
			}
		}
		if ( $counts['needs_review'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of events needing review */
				__( '%d need review', 'conexao-event-importer' ),
				$counts['needs_review']
			);
		}
		if ( $counts['failed'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of failed events */
				__( '%d failed', 'conexao-event-importer' ),
				$counts['failed']
			);
		}

		return implode( ', ', $parts );
	}

	/**
	 * Public wrapper around extract_failed_events() for other components
	 * (scheduler finalize, CLI) that need the same bounded extraction.
	 *
	 * @param array $event_results Event result list.
	 * @return array
	 */
	public static function extract_failed_events_public( $event_results ) {
		return self::extract_failed_events( $event_results );
	}

	/**
	 * Extract failed events from a list of event results.
	 *
	 * @param array $event_results Event result list.
	 * @return array
	 */
	protected static function extract_failed_events( $event_results ) {
		$failed = array();
		foreach ( $event_results as $event ) {
			if ( isset( $event['outcome'] ) && 'failed' === $event['outcome'] ) {
				$failed[] = $event;
			}
		}
		return array_slice( $failed, 0, 100 );
	}

	/**
	 * Get the source handler instance for a source config.
	 *
	 * @param array $source Source config.
	 * @return Conexao_Source_Base|null
	 */
	protected function get_source_handler( $source ) {
		// Check source type first for type-based routing.
		$source_type = isset( $source['type'] ) ? $source['type'] : '';

		// iCalendar/Webcal sources use the generic iCalendar handler.
		if ( 'icalendar' === $source_type ) {
			return new Conexao_Source_ICalendar( $source );
		}

		// Eventbrite sources use the Eventbrite handler.
		if ( 'eventbrite' === $source_type ) {
			return new Conexao_Source_Eventbrite( $source );
		}

		// Fall back to source ID-based routing for legacy/website sources.
		// Normalize the source ID for matching (convert hyphens to underscores).
		$normalized_id = str_replace( '-', '_', $source['id'] );

		switch ( $normalized_id ) {
			case 'laois_tourism':
				// If Laois Tourism is configured as iCalendar type, use that handler.
				if ( 'icalendar' === $source_type ) {
					return new Conexao_Source_ICalendar( $source );
				}
				return new Conexao_Source_Laois_Tourism( $source );
			case 'heritage_week':
			case 'national_heritage_week':
				return new Conexao_Source_Heritage_Week( $source );
			case 'eventbrite':
				return new Conexao_Source_Eventbrite( $source );
			default:
				// Allow third-party handlers to be registered.
				return apply_filters( 'conexao_event_importer_get_handler', null, $source );
		}
	}

	/**
	 * Create or update an event in the database.
	 *
	 * @param array $normalized   Normalized event data.
	 * @param bool  $needs_review Whether this event needs human review.
	 * @param int   $existing_id  Existing post ID (0 for new).
	 * @return array{action:string, post_id:int, error?:string, reason?:string}
	 */
	protected function upsert_event( $normalized, $needs_review = false, $existing_id = 0 ) {
		$now = current_time( 'mysql' );

		if ( $existing_id ) {
			$post_id = $existing_id;

			// Check if the event data has actually changed.
			$existing_title  = get_the_title( $post_id );
			$existing_date   = get_post_meta( $post_id, '_event_date', true );
			$existing_time   = get_post_meta( $post_id, '_event_start_time', true );
			$existing_url    = get_post_meta( $post_id, '_event_url', true );
			$existing_venue  = get_post_meta( $post_id, '_event_venue', true );
			$existing_org    = get_post_meta( $post_id, '_event_organizer', true );
			$existing_banner = get_post_meta( $post_id, '_event_banner', true );

			// Check if the banner attachment exists (may be missing for events
			// imported before the image download feature was added).
			$existing_banner_attach = get_post_meta( $post_id, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true );
			$has_attachment = $existing_banner_attach && wp_attachment_is_image( $existing_banner_attach );

			$is_unchanged = (
				$existing_title === $normalized['title'] &&
				$existing_date === $normalized['start_date'] &&
				$existing_time === $normalized['start_time'] &&
				$existing_url === $normalized['source_url'] &&
				$existing_venue === $normalized['venue'] &&
				$existing_org === $normalized['organizer'] &&
				$existing_banner === $normalized['banner']
			);

			if ( $is_unchanged ) {
				// Even if data is unchanged, download the image if we don't have an attachment yet.
				if ( ! empty( $normalized['banner'] ) && ! $has_attachment ) {
					$this->handle_event_image( $post_id, $normalized );
				}

				// Update last checked timestamp only.
				update_post_meta( $post_id, '_event_last_checked', current_time( 'mysql' ) );
				return array( 'action' => 'unchanged', 'post_id' => $post_id );
			}

			$action = 'updated';

			$updated = wp_update_post( array(
				'ID'           => $post_id,
				'post_title'   => $normalized['title'],
				'post_content' => $normalized['description'],
				'post_excerpt' => wp_trim_words( $normalized['description'], 30, '...' ),
				'post_status'  => 'publish',
			) );

			if ( is_wp_error( $updated ) ) {
				Conexao_Import_Log::add( $normalized['source'], 'error', 'Falha ao atualizar evento: ' . $updated->get_error_message(), array( 'title' => $normalized['title'], 'run_id' => $this->current_run_id ) );
				return array( 'action' => 'error', 'post_id' => 0, 'error' => $updated->get_error_message() );
			}
		} else {
			$post_id = wp_insert_post( array(
				'post_type'    => 'event',
				'post_title'   => $normalized['title'],
				'post_content' => $normalized['description'],
				'post_excerpt' => wp_trim_words( $normalized['description'], 30, '...' ),
				'post_status'  => 'publish',
			) );

			if ( is_wp_error( $post_id ) ) {
				Conexao_Import_Log::add( $normalized['source'], 'error', 'Falha ao criar evento: ' . $post_id->get_error_message(), array( 'title' => $normalized['title'], 'run_id' => $this->current_run_id ) );
				return array( 'action' => 'error', 'post_id' => 0, 'error' => $post_id->get_error_message() );
			}

			$action = 'created';
		}

		// Save all event meta.
		$this->save_event_meta( $post_id, $normalized );

		// Download banner image into WordPress Media Library and set as featured image.
		// Failures here do NOT fail the event import — the external URL is kept as a fallback.
		$this->handle_event_image( $post_id, $normalized );

		// Save taxonomies (county, town, category). Failures should not fail the event.
		try {
			$this->save_event_taxonomies( $post_id, $normalized );
		} catch ( Exception $e ) {
			Conexao_Import_Log::add(
				$normalized['source'],
				'warning',
				sprintf(
					/* translators: 1: event title, 2: error message */
					__( 'The event "%1$s" was imported but taxonomy assignment failed: %2$s', 'conexao-event-importer' ),
					$normalized['title'],
					$e->getMessage()
				),
				array(
					'run_id'      => $this->current_run_id,
					'event_title' => $normalized['title'],
				)
			);
		}

		// Set status.
		$status = $needs_review ? Conexao_Event_Status::NEEDS_REVIEW : Conexao_Event_Status::PUBLISHED;
		Conexao_Event_Status::set_status( $post_id, $status );

		// If it was previously "Source Not Found" and reappeared, mark it published.
		$prev_status = get_post_meta( $post_id, '_event_status', true );
		if ( Conexao_Event_Status::SOURCE_NOT_FOUND === $prev_status && ! $needs_review ) {
			Conexao_Event_Status::set_status( $post_id, Conexao_Event_Status::PUBLISHED );
		}

		return array( 'action' => $action, 'post_id' => $post_id );
	}

	/**
	 * Handle event banner image: download into Media Library and set as featured image.
	 *
	 * Downloads the external banner URL into the WordPress Media Library using
	 * media_sideload_image, then sets it as both the _event_banner_attachment_id
	 * meta and the post thumbnail (featured image).
	 *
	 * If the image was already downloaded (same URL), it reuses the existing
	 * attachment. If the download fails, the event keeps its external URL as
	 * a fallback in _event_banner.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $normalized Normalized event data.
	 */
	protected function handle_event_image( $post_id, $normalized ) {
		$banner_url = isset( $normalized['banner'] ) ? $normalized['banner'] : '';

		if ( empty( $banner_url ) ) {
			return;
		}

		// Check if we already have an attachment for this event.
		$existing_attachment = get_post_meta( $post_id, Conexao_Event_Image_Handler::ATTACHMENT_META_KEY, true );
		if ( $existing_attachment && wp_attachment_is_image( $existing_attachment ) ) {
			$source_url = get_post_meta( $existing_attachment, '_event_source_url', true );
			if ( $source_url === $banner_url ) {
				// Same image already downloaded, nothing to do.
				return;
			}
		}

		// Download the image into the Media Library.
		$title = isset( $normalized['title'] ) ? $normalized['title'] : '';
		$attachment_id = $this->image_handler->sideload_image( $banner_url, $post_id, $title );

		if ( $attachment_id ) {
			// Set as both banner attachment and featured image.
			$this->image_handler->set_banner_attachment( $post_id, $attachment_id );
		} else {
			// Download failed; the external URL remains in _event_banner as fallback.
			Conexao_Import_Log::add(
				$normalized['source'],
				'warning',
				sprintf(
					/* translators: 1: event title */
					__( 'The image for "%1$s" could not be downloaded. The external Eventbrite image URL will be used instead.', 'conexao-event-importer' ),
					$normalized['title']
				),
				array(
					'run_id'      => $this->current_run_id,
					'event_title' => $normalized['title'],
					'url'         => $banner_url,
				)
			);
		}
	}

	/**
	 * Save all event meta fields.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $normalized Normalized event data.
	 */
	protected function save_event_meta( $post_id, $normalized ) {
		$meta = array(
			'_event_date'        => $normalized['start_date'],
			'_event_time'        => $normalized['event_time'],
			'_event_start_time'  => $normalized['start_time'],
			'_event_end_date'    => $normalized['end_date'],
			'_event_end_time'    => $normalized['end_time'],
			'_event_location'    => $normalized['event_location'],
			'_event_venue'       => $normalized['venue'],
			'_event_address'     => $normalized['address'],
			'_event_url'         => $normalized['source_url'],
			'_event_source_url'  => $normalized['source_url'],
			'_event_banner'      => $normalized['banner'],
			'_event_source'      => $normalized['source'],
			'_event_source_id'   => $normalized['source_id'],
			'_event_organizer'   => $normalized['organizer'],
			'_event_price'       => $normalized['price'],
			'_event_imported'    => '1',
			'_event_import_date' => current_time( 'mysql' ),
			'_event_last_checked' => current_time( 'mysql' ),
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		if ( $normalized['needs_review'] && ! empty( $normalized['review_notes'] ) ) {
			update_post_meta( $post_id, '_event_review_note', implode( '; ', $normalized['review_notes'] ) );
		}
	}

	/**
	 * Save taxonomies for an event.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $normalized Normalized event data.
	 */
	protected function save_event_taxonomies( $post_id, $normalized ) {
		// County.
		if ( ! empty( $normalized['county'] ) ) {
			$county_id = $this->location->ensure_county( $normalized['county'] );
			if ( $county_id ) {
				wp_set_object_terms( $post_id, array( $county_id ), 'conexao_county' );
			}
		}

		// Town.
		if ( ! empty( $normalized['town'] ) ) {
			$town_id = $this->location->ensure_town( $normalized['town'] );
			if ( $town_id ) {
				wp_set_object_terms( $post_id, array( $town_id ), 'conexao_town' );
			}
		}

		// Category.
		if ( ! empty( $normalized['category'] ) ) {
			$category = $this->normalizer->clean_category( $normalized['category'] );
			if ( $category ) {
				$term = term_exists( $category, 'conexao_category' );
				if ( ! $term ) {
					$new_term = wp_insert_term( $category, 'conexao_category' );
					if ( ! is_wp_error( $new_term ) ) {
						$term = $new_term;
					}
				}
				if ( $term && ! is_wp_error( $term ) ) {
					$term_id = is_array( $term ) ? $term['term_id'] : $term;
					wp_set_object_terms( $post_id, array( (int) $term_id ), 'conexao_category' );
				}
			}
		}
	}

	/**
	 * Mark events from a source that are no longer present as "Source Not Found".
	 *
	 * @param string $source_id   Source slug.
	 * @param array  $raw_events  Raw events fetched this run.
	 */
	protected function mark_missing_events( $source_id, $raw_events ) {
		$current_urls = array();
		foreach ( $raw_events as $raw ) {
			if ( ! empty( $raw['url'] ) ) {
				$current_urls[] = $raw['url'];
			}
		}

		if ( empty( $current_urls ) ) {
			return;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'event',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_event_source',
						'value' => $source_id,
					),
					array(
						'key'     => '_event_status',
						'compare' => 'NOT IN',
						'value'   => array( 'rejected', 'source_not_found' ),
					),
				),
			)
		);

		foreach ( $query->posts as $post_id ) {
			$stored_url = get_post_meta( $post_id, '_event_url', true );
			if ( $stored_url && ! in_array( $stored_url, $current_urls, true ) ) {
				// Event disappeared from the source. Mark for review, don't delete.
				Conexao_Event_Status::set_status( $post_id, Conexao_Event_Status::SOURCE_NOT_FOUND );
				update_post_meta( $post_id, '_event_review_note', __( 'Evento não encontrado na fonte na última importação.', 'conexao-event-importer' ) );
			}
		}
	}
}