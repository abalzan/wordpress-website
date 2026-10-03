<?php
/**
 * Eventbrite county event source handler.
 *
 * Fetches Eventbrite's public county discovery page, parses
 * `window.__SERVER_DATA__`, follows pagination, validates the configured
 * county region, and returns raw events for the central importer.
 *
 * Discovery mechanism: Eventbrite public discovery page / server-rendered HTML.
 * This is NOT an official Eventbrite API integration.
 *
 * The handler is configuration-driven: county, region labels, and discovery URL
 * are read from the source config. One implementation backs many county sources
 * (e.g. eventbrite_laois, eventbrite_cork, eventbrite_dublin).
 *
 * Legacy fallback: when config['id'] is missing, returns 'eventbrite' for
 * backward compatibility with the existing Laois source record.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Source_Eventbrite extends Conexao_Source_Base {

	/**
	 * Maximum number of pages to fetch (safety limit).
	 *
	 * @var int
	 */
	const MAX_PAGES = 50;

	/**
	 * Soft time budget (seconds) for the whole pagination walk.
	 *
	 * When exceeded, the walk stops and the events collected so far are
	 * returned. The next run continues from where deduplication left off.
	 *
	 * @var int
	 */
	const TIME_BUDGET_SECONDS = 45;

	/**
	 * Get the source ID.
	 *
	 * Returns the configured source ID so each county source has an independent
	 * identity (dedup/log/health). Falls back to 'eventbrite' only when the
	 * config ID is missing (legacy compatibility).
	 *
	 * @return string
	 */
	public function get_id() {
		if ( ! empty( $this->config['id'] ) ) {
			return $this->config['id'];
		}
		return 'eventbrite';
	}

	/**
	 * Get the configured county name.
	 *
	 * @return string
	 */
	protected function get_county() {
		return isset( $this->config['county'] ) ? $this->config['county'] : '';
	}

	/**
	 * Get the configured accepted region labels.
	 *
	 * @return array
	 */
	protected function get_region_labels() {
		if ( ! empty( $this->config['region_labels'] ) && is_array( $this->config['region_labels'] ) ) {
			return $this->config['region_labels'];
		}
		return array();
	}

	/**
	 * Fetch raw event listings from Eventbrite county discovery.
	 *
	 * Always uses the HTML discovery scraper. The Eventbrite v3 Events Search
	 * API path has been removed (endpoint shut down, verified 404 in Stage A).
	 *
	 * @return array[]
	 */
	public function fetch_events() {
		return $this->fetch_via_scrape();
	}

	/**
	 * Fetch events by scraping the public discovery page.
	 *
	 * @return array[]
	 * @throws Conexao_Source_Fetch_Exception When the first page cannot be fetched
	 *                                        or parsed: an unenumerable source is a
	 *                                        failed source, never an empty one.
	 */
	protected function fetch_via_scrape() {
		$base_url = isset( $this->config['url'] ) && $this->config['url'] ? $this->config['url'] : Conexao_Eventbrite_Client::DEFAULT_DISCOVERY_URL;

		$client     = new Conexao_Eventbrite_Client( $this->get_id() );
		$parser     = new Conexao_Eventbrite_Parser();
		$normalizer = new Conexao_Eventbrite_Normalizer();

		// Fetch page 1 first to get pagination info. A failure here is FATAL:
		// an unreachable first page means the source could not be enumerated
		// at all, which is NOT the same as an empty calendar. Returning an
		// empty array here would be recorded downstream as `found=0,
		// status=success` (the Stage G1 masking defect). Raise the structured
		// exception instead and let the engine record a failed source.
		$html = $client->fetch_page( $base_url, 1 );
		if ( empty( $html ) ) {
			$status = $client->get_last_http_status();
			$reason = sprintf(
				/* translators: %s: discovery URL */
				__( 'Failed to fetch Eventbrite discovery page: %s', 'conexao-event-importer' ),
				$base_url
			);
			Conexao_Import_Log::add(
				$this->get_id(),
				'error',
				$reason,
				array(
					'url'         => $base_url,
					'http_status' => $status,
					'run_id'      => $this->get_current_run_id(),
				)
			);
			throw new Conexao_Source_Fetch_Exception( esc_html( $reason ), (int) esc_html( (string) $status ) );
		}

		$this->fetch_complete = true;
		$incomplete_reason    = '';

		try {
			$page_data = $parser->parse( $html );
		} catch ( Exception $e ) {
			$reason = sprintf( 'Eventbrite page 1 parse failed: %s', $e->getMessage() );
			Conexao_Import_Log::add(
				$this->get_id(),
				'error',
				$reason,
				array(
					'url'    => $base_url,
					'run_id' => $this->get_current_run_id(),
				)
			);
			// A malformed first page is also "cannot enumerate", never "empty".
			throw new Conexao_Source_Fetch_Exception( esc_html( $reason ), 0 );
		}

		$page_count   = isset( $page_data['pagination']['page_count'] ) ? (int) $page_data['pagination']['page_count'] : 1;
		$object_count = isset( $page_data['pagination']['object_count'] ) ? (int) $page_data['pagination']['object_count'] : 0;

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf( 'Eventbrite: page_count=%d, object_count=%d for %s.', $page_count, $object_count, $base_url ),
			array( 'run_id' => $this->get_current_run_id() )
		);

		$all_events = isset( $page_data['events'] ) ? $page_data['events'] : array();

		// Walk remaining pages.
		$started_at = microtime( true );
		$budget     = $client->get_time_budget_seconds();

		for ( $page = 2; $page <= $page_count; $page++ ) {
			if ( ( microtime( true ) - $started_at ) > $budget ) {
				$this->mark_fetch_incomplete();
				$incomplete_reason = sprintf( 'time budget of %ds expired after page %d/%d', (int) $budget, $page - 1, $page_count );
				Conexao_Import_Log::add(
					$this->get_id(),
					'warning',
					sprintf(
						/* translators: 1: last completed page, 2: total pages */
						__( 'Eventbrite import reached its time budget after page %1$d/%2$d — remaining pages will be picked up by the next run.', 'conexao-event-importer' ),
						$page - 1,
						$page_count
					),
					array( 'run_id' => $this->get_current_run_id() )
				);
				break;
			}

			$client->delay();

			$html = $client->fetch_page( $base_url, $page );
			if ( empty( $html ) ) {
				// Partial-page tolerance is preserved: keep the events already
				// collected. But the enumeration is now INCOMPLETE, so the
				// engine must not treat the surviving subset as the full
				// source listing.
				$this->mark_fetch_incomplete();
				$incomplete_reason = sprintf( 'page %d/%d could not be fetched (HTTP %d)', $page, $page_count, $client->get_last_http_status() );
				Conexao_Import_Log::add(
					$this->get_id(),
					'error',
					sprintf( 'Failed to fetch Eventbrite page %d/%d.', $page, $page_count ),
					array(
						'http_status' => $client->get_last_http_status(),
						'run_id'      => $this->get_current_run_id(),
					)
				);
				continue;
			}

			try {
				$page_data = $parser->parse( $html );
			} catch ( Exception $e ) {
				$this->mark_fetch_incomplete();
				$incomplete_reason = sprintf( 'page %d/%d could not be parsed', $page, $page_count );
				Conexao_Import_Log::add(
					$this->get_id(),
					'error',
					sprintf( 'Eventbrite page %d/%d parse failed: %s', $page, $page_count, $e->getMessage() ),
					array( 'run_id' => $this->get_current_run_id() )
				);
				continue;
			}

			Conexao_Import_Log::add(
				$this->get_id(),
				'info',
				sprintf( 'Fetched page %d/%d, %d events.', $page, $page_count, count( $page_data['events'] ) ),
				array( 'run_id' => $this->get_current_run_id() )
			);

			$all_events = array_merge( $all_events, $page_data['events'] );
		}

		// Reaching MAX_PAGES with pages still outstanding is also incomplete.
		if ( $this->fetch_complete && $page_count > self::MAX_PAGES ) {
			$this->mark_fetch_incomplete();
			$incomplete_reason = sprintf( 'page limit of %d reached with %d pages declared', self::MAX_PAGES, $page_count );
		}

		if ( ! $this->fetch_complete ) {
			Conexao_Import_Log::add(
				$this->get_id(),
				'warning',
				sprintf(
					/* translators: %s: reason the walk is incomplete */
					__( 'Eventbrite enumeration is INCOMPLETE (%s). Events absent from this walk will not be marked source_not_found.', 'conexao-event-importer' ),
					$incomplete_reason
				),
				array( 'run_id' => $this->get_current_run_id() )
			);
		}

		// Deduplicate by event ID.
		$seen_ids      = array();
		$unique_events = array();
		$duplicates    = 0;

		foreach ( $all_events as $event ) {
			$id = isset( $event['id'] ) ? (string) $event['id'] : '';
			if ( empty( $id ) ) {
				continue;
			}
			if ( isset( $seen_ids[ $id ] ) ) {
				++$duplicates;
				continue;
			}
			$seen_ids[ $id ] = true;
			$unique_events[] = $event;
		}

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf( 'Eventbrite: %d total fetched, %d duplicates removed, %d unique.', count( $all_events ), $duplicates, count( $unique_events ) ),
			array( 'run_id' => $this->get_current_run_id() )
		);

		// Filter for configured county region and normalize.
		$raw_events       = array();
		$skipped          = 0;
		$rejected_regions = array();

		foreach ( $unique_events as $event ) {
			// Validate county region.
			$region_check = $this->is_county_event( $event );
			if ( ! $region_check['accepted'] ) {
				++$skipped;
				if ( ! empty( $region_check['observed'] ) ) {
					$rejected_regions[] = $region_check['observed'];
				}
				continue;
			}

			// Skip online events (this is a physical county events calendar).
			if ( ! empty( $event['is_online_event'] ) ) {
				++$skipped;
				continue;
			}

			// Inject county and source identity before normalization.
			$event['county'] = $this->get_county();
			$event['source'] = $this->get_id();

			$raw_events[] = $normalizer->normalize( $event );
		}

		if ( ! empty( $rejected_regions ) ) {
			$region_counts = array_count_values( $rejected_regions );
			arsort( $region_counts );
			$top = array_slice( $region_counts, 0, 5, true );
			Conexao_Import_Log::add(
				$this->get_id(),
				'info',
				sprintf(
					'Eventbrite: rejected regions (top 5): %s',
					implode(
						', ',
						array_map(
							function ( $region, $count ) {
								return $region . ' (' . $count . ')';
							},
							array_keys( $top ),
							$top
						)
					)
				),
				array( 'run_id' => $this->get_current_run_id() )
			);
		}

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf( 'Eventbrite: %d %s events after filtering, %d skipped (non-%s or online).', count( $raw_events ), $this->get_county(), $this->get_county(), $skipped ),
			array( 'run_id' => $this->get_current_run_id() )
		);

		return $raw_events;
	}

	/**
	 * Check if an Eventbrite event belongs to the configured county.
	 *
	 * Validates that the locations array contains a region entry whose name
	 * matches one of the configured accepted region labels. Does NOT search
	 * title or description.
	 *
	 * Default policy: UNKNOWN REGION = REJECT + LOG.
	 * Never silently accept an unknown region.
	 *
	 * @param array $event Raw Eventbrite event.
	 * @return array{accepted:bool, observed:string}
	 */
	protected function is_county_event( $event ) {
		$labels = $this->get_region_labels();

		if ( empty( $event['locations'] ) || ! is_array( $event['locations'] ) ) {
			if ( empty( $labels ) ) {
				// No region labels configured and no location data — accept
				// (defensive: the county source is configured for this provider).
				return array(
					'accepted' => true,
					'observed' => '',
				);
			}
			return array(
				'accepted' => false,
				'observed' => '(no location data)',
			);
		}

		$observed_region = '';

		foreach ( $event['locations'] as $location ) {
			if ( ! is_array( $location ) ) {
				continue;
			}
			if ( isset( $location['type'] ) && 'region' === $location['type'] ) {
				$observed_region = isset( $location['name'] ) ? $location['name'] : '';
				if ( in_array( $observed_region, $labels, true ) ) {
					return array(
						'accepted' => true,
						'observed' => $observed_region,
					);
				}
			}
		}

		return array(
			'accepted' => false,
			'observed' => $observed_region,
		);
	}
}
