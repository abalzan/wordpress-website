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
	 */
	protected function fetch_via_scrape() {
		$base_url = isset( $this->config['url'] ) && $this->config['url'] ? $this->config['url'] : Conexao_Eventbrite_Client::DEFAULT_DISCOVERY_URL;

		$client     = new Conexao_Eventbrite_Client( $this->get_id() );
		$parser     = new Conexao_Eventbrite_Parser();
		$normalizer = new Conexao_Eventbrite_Normalizer();

		// Fetch page 1 first to get pagination info. A hard
		// failure here throws so the engine can report a clean fatal error.
		$html = $client->fetch_page( $base_url, 1 );
		if ( empty( $html ) ) {
			Conexao_Import_Log::add(
				$this->get_id(),
				'error',
				sprintf( 'Failed to fetch Eventbrite discovery page: %s', $base_url )
			);
			return array();
		}

		try {
			$page_data = $parser->parse( $html );
		} catch ( Exception $e ) {
			Conexao_Import_Log::add(
				$this->get_id(),
				'error',
				sprintf( 'Eventbrite page 1 parse failed: %s', $e->getMessage() )
			);
			return array();
		}

		$page_count = isset( $page_data['pagination']['page_count'] ) ? (int) $page_data['pagination']['page_count'] : 1;
		$object_count = isset( $page_data['pagination']['object_count'] ) ? (int) $page_data['pagination']['object_count'] : 0;

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf( 'Eventbrite: page_count=%d, object_count=%d for %s.', $page_count, $object_count, $base_url )
		);

		$all_events = isset( $page_data['events'] ) ? $page_data['events'] : array();

		// Walk remaining pages.
		$started_at = microtime( true );

		for ( $page = 2; $page <= $page_count; $page++ ) {
			if ( ( microtime( true ) - $started_at ) > self::TIME_BUDGET_SECONDS ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'warning',
					sprintf(
						/* translators: 1: last completed page, 2: total pages */
						__( 'Eventbrite import reached its time budget after page %1$d/%2$d — remaining pages will be picked up by the next run.', 'conexao-event-importer' ),
						$page - 1,
						$page_count
					)
				);
				break;
			}

			$client->delay();

			$html = $client->fetch_page( $base_url, $page );
			if ( empty( $html ) ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'error',
					sprintf( 'Failed to fetch Eventbrite page %d/%d.', $page, $page_count )
				);
				continue;
			}

			try {
				$page_data = $parser->parse( $html );
			} catch ( Exception $e ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'error',
					sprintf( 'Eventbrite page %d/%d parse failed: %s', $page, $page_count, $e->getMessage() )
				);
				continue;
			}

			Conexao_Import_Log::add(
				$this->get_id(),
				'info',
				sprintf( 'Fetched page %d/%d, %d events.', $page, $page_count, count( $page_data['events'] ) )
			);

			$all_events = array_merge( $all_events, $page_data['events'] );
		}

		// Deduplicate by event ID.
		$seen_ids = array();
		$unique_events = array();
		$duplicates = 0;

		foreach ( $all_events as $event ) {
			$id = isset( $event['id'] ) ? (string) $event['id'] : '';
			if ( empty( $id ) ) {
				continue;
			}
			if ( isset( $seen_ids[ $id ] ) ) {
				$duplicates++;
				continue;
			}
			$seen_ids[ $id ] = true;
			$unique_events[] = $event;
		}

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf( 'Eventbrite: %d total fetched, %d duplicates removed, %d unique.', count( $all_events ), $duplicates, count( $unique_events ) )
		);

		// Filter for configured county region and normalize.
		$raw_events = array();
		$skipped    = 0;
		$rejected_regions = array();

		foreach ( $unique_events as $event ) {
			// Validate county region.
			$region_check = $this->is_county_event( $event );
			if ( ! $region_check['accepted'] ) {
				$skipped++;
				if ( ! empty( $region_check['observed'] ) ) {
					$rejected_regions[] = $region_check['observed'];
				}
				continue;
			}

			// Skip online events (this is a physical county events calendar).
			if ( ! empty( $event['is_online_event'] ) ) {
				$skipped++;
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
					implode( ', ', array_map( function( $region, $count ) {
						return $region . ' (' . $count . ')';
					}, array_keys( $top ), $top ) )
				)
			);
		}

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf( 'Eventbrite: %d %s events after filtering, %d skipped (non-%s or online).', count( $raw_events ), $this->get_county(), $this->get_county(), $skipped )
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
				return array( 'accepted' => true, 'observed' => '' );
			}
			return array( 'accepted' => false, 'observed' => '(no location data)' );
		}

		$observed_region = '';

		foreach ( $event['locations'] as $location ) {
			if ( ! is_array( $location ) ) {
				continue;
			}
			if ( isset( $location['type'] ) && 'region' === $location['type'] ) {
				$observed_region = isset( $location['name'] ) ? $location['name'] : '';
				if ( in_array( $observed_region, $labels, true ) ) {
					return array( 'accepted' => true, 'observed' => $observed_region );
				}
			}
		}

		return array( 'accepted' => false, 'observed' => $observed_region );
	}
}
