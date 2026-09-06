<?php
/**
 * Eventbrite Laois event source handler.
 *
 * Fetches Eventbrite's public Laois discovery page, parses
 * `window.__SERVER_DATA__`, follows pagination, validates Laois region,
 * and returns raw events for the central importer.
 *
 * Discovery mechanism: Eventbrite public discovery page / server-rendered HTML.
 * This is NOT an official Eventbrite API integration.
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

	/** Events Search API endpoint (official v3 API). */
	const API_SEARCH_URL = 'https://www.eventbriteapi.com/v3/events/search/';

	/** Default radius for the Laois region search. */
	const API_LOCATION = 'Laois, Ireland';

	/** Default radius for the Laois region search. */
	const API_RADIUS_KM = 40;

	/**
	 * Get the source slug.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'eventbrite';
	}

	/**
	 * Whether the official Eventbrite API should be used.
	 *
	 * The HTML discovery scraper only works from IPs Eventbrite tolerates
	 * (residential). Datacenter hosts such as WordPress.com receive HTTP 405,
	 * so a personal OAuth token switches this source to the official API.
	 *
	 * @return bool
	 */
	protected function use_api() {
		return '' !== trim( (string) Conexao_Import_Settings::get( 'eventbrite_api_token', '' ) );
	}

	/**
	 * Fetch raw event listings from Eventbrite Laois discovery.
	 *
	 * Uses the official v3 Events Search API when a token is configured;
	 * otherwise falls back to scraping the public discovery page.
	 *
	 * @return array[]
	 */
	public function fetch_events() {
		if ( $this->use_api() ) {
			return $this->fetch_via_api();
		}

		return $this->fetch_via_scrape();
	}

	/**
	 * Fetch events through the official Eventbrite v3 API.
	 *
	 * @return array[]
	 */
	protected function fetch_via_api() {
		$token     = trim( (string) Conexao_Import_Settings::get( 'eventbrite_api_token', '' ) );
		$started_at = microtime( true );
		$page      = 1;
		$all       = array();

		while ( $page <= self::MAX_PAGES ) {
			if ( ( microtime( true ) - $started_at ) > self::TIME_BUDGET_SECONDS ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'warning',
					sprintf(
						/* translators: %d: last completed page */
						__( 'Eventbrite API import reached its time budget after page %1$d — remaining pages will be picked up by the next run.', 'conexao-event-importer' ),
						$page - 1
					)
				);
				break;
			}

			$api_url = add_query_arg(
				array(
					'page'             => $page,
					'sort_by'          => 'date',
					'expand'           => 'venue,logo',
					'location.address' => self::API_LOCATION,
					'location.within'  => self::API_RADIUS_KM . 'km',
				),
				self::API_SEARCH_URL
			);

			$response = wp_remote_get(
				$api_url,
				array(
					'timeout'    => 30,
					'user-agent' => $this->get_http_user_agent(),
					'headers'    => array(
						'Authorization' => 'Bearer ' . $token,
						'Accept'        => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new Conexao_Source_Fetch_Exception(
					__( 'Eventbrite API request failed: ', 'conexao-event-importer' ) . $response->get_error_message(),
					0
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				throw new Conexao_Source_Fetch_Exception(
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Eventbrite API returned HTTP %d. Check that the API token in Import Settings is valid.', 'conexao-event-importer' ),
						$code
					),
					$code
				);
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) || empty( $data['events'] ) || ! is_array( $data['events'] ) ) {
				break; // No more events.
			}

			foreach ( $data['events'] as $event ) {
				$mapped = $this->map_api_event( $event );
				if ( null !== $mapped ) {
					$all[] = $mapped;
				}
			}

			// Pagination: stop when the API reports no more pages.
			$page_count = isset( $data['pagination']['page_count'] ) ? (int) $data['pagination']['page_count'] : 1;
			if ( $page >= $page_count ) {
				break;
			}
			$page++;
		}

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf(
				/* translators: %d: number of events */
				__( 'Eventbrite API import collected %d event(s).', 'conexao-event-importer' ),
				count( $all )
			)
		);

		return $all;
	}

	/**
	 * Map one Eventbrite API event object to the raw event format.
	 *
	 * Returns null for events that must be skipped (online-only).
	 *
	 * @param array $event API event object.
	 * @return array|null
	 */
	protected function map_api_event( $event ) {
		if ( empty( $event['id'] ) || ! empty( $event['is_online_event'] ) ) {
			return null;
		}

		$title = isset( $event['name']['text'] ) ? trim( (string) $event['name']['text'] ) : '';
		if ( '' === $title ) {
			return null;
		}

		$start_date = '';
		$start_time = '';
		if ( ! empty( $event['start']['local'] ) ) {
			$parts      = explode( 'T', (string) $event['start']['local'] );
			$start_date = isset( $parts[0] ) ? $parts[0] : '';
			$start_time = isset( $parts[1] ) ? substr( $parts[1], 0, 5 ) : '';
		}

		$end_date = '';
		$end_time = '';
		if ( ! empty( $event['end']['local'] ) ) {
			$parts    = explode( 'T', (string) $event['end']['local'] );
			$end_date = isset( $parts[0] ) ? $parts[0] : '';
			$end_time = isset( $parts[1] ) ? substr( $parts[1], 0, 5 ) : '';
		}
		if ( '' === $end_date && '' !== $start_date ) {
			$end_date = $start_date;
		}

		// Location: venue name + display address when expanded.
		$location   = '';
		$venue_name = '';
		$town       = '';
		$address    = '';

		if ( ! empty( $event['venue'] ) && is_array( $event['venue'] ) ) {
			$venue_parts = array();
			if ( ! empty( $event['venue']['name'] ) ) {
				$venue_name  = trim( (string) $event['venue']['name'] );
				$venue_parts[] = $venue_name;
			}
			if ( ! empty( $event['venue']['address']['localized_address_display'] ) ) {
				$venue_parts[] = $event['venue']['address']['localized_address_display'];
			}
			$location = implode( ', ', $venue_parts );

			// Structured address parts supplied by the API (expand=venue).
			// Composed from supplied fields only — never guessed.
			$address_fields = isset( $event['venue']['address'] ) && is_array( $event['venue']['address'] ) ? $event['venue']['address'] : array();
			if ( ! empty( $address_fields ) ) {
				$address = Conexao_Event_Address::compose(
					array(
						isset( $address_fields['address_1'] ) ? $address_fields['address_1'] : '',
						isset( $address_fields['address_2'] ) ? $address_fields['address_2'] : '',
						isset( $address_fields['city'] ) ? $address_fields['city'] : '',
						isset( $address_fields['region'] ) ? $address_fields['region'] : '',
						isset( $address_fields['postal_code'] ) ? $address_fields['postal_code'] : '',
					)
				);
			}

			if ( empty( $town ) && ! empty( $address_fields['city'] ) ) {
				$town = trim( (string) $address_fields['city'] );
			}
		}

		return array(
			'source'      => $this->get_id(),
			'title'       => $title,
			'url'         => isset( $event['url'] ) ? (string) $event['url'] : '',
			'start_date'  => $start_date,
			'start_time'  => $start_time,
			'end_date'    => $end_date,
			'end_time'    => $end_time,
			'location'    => $location,
			'venue'       => $venue_name,
			'town'        => $town,
			'address'     => $address,
			'description' => isset( $event['description']['text'] ) ? trim( (string) $event['description']['text'] ) : '',
			'image'       => isset( $event['logo']['url'] ) ? (string) $event['logo']['url'] : '',
			'source_id'   => (string) $event['id'],
			'county'      => isset( $this->config['county'] ) ? $this->config['county'] : '',
		);
	}

	/**
	 * Fetch raw event listings by scraping the public discovery page.
	 *
	 * Only viable from IPs Eventbrite does not block (residential).
	 *
	 * @return array[]
	 */
	protected function fetch_via_scrape() {
		$base_url = isset( $this->config['url'] ) && $this->config['url'] ? $this->config['url'] : Conexao_Eventbrite_Client::DEFAULT_DISCOVERY_URL;

		$client   = new Conexao_Eventbrite_Client();
		$parser   = new Conexao_Eventbrite_Parser();
		$normalizer = new Conexao_Eventbrite_Normalizer();

		// Fetch page 1 first to get pagination info. A hard failure here is a
		// fatal source error, not "no events" — throw so the engine reports it.
		$html = $client->fetch_page( $base_url, 1 );
		if ( empty( $html ) ) {
			throw new Conexao_Source_Fetch_Exception(
				__( 'The Eventbrite discovery page could not be fetched after retries. Eventbrite may be rate-limiting this site or the URL may have changed.', 'conexao-event-importer' ),
				0
			);
		}

		try {
			$page1 = $parser->parse( $html );
		} catch ( Exception $e ) {
			Conexao_Import_Log::add( $this->get_id(), 'error', 'Eventbrite parse failed: ' . $e->getMessage() );
			return array();
		}

		$pagination = $page1['pagination'];
		$page_count = isset( $pagination['page_count'] ) ? (int) $pagination['page_count'] : 1;
		$page_count = max( 1, min( $page_count, self::MAX_PAGES ) );

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf( 'Eventbrite import started. Page 1/%d fetched, %d events.', $page_count, count( $page1['events'] ) )
		);

		$all_events   = $page1['events'];
		$started_at   = microtime( true );

		// Fetch remaining pages within a soft time budget so a very large
		// result set cannot exceed the PHP execution window of a cron tick.
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

		// Filter for Laois region and normalize.
		$raw_events = array();
		$skipped = 0;

		foreach ( $unique_events as $event ) {
			// Validate Laois region.
			if ( ! $this->is_laois_event( $event ) ) {
				$skipped++;
				continue;
			}

			// Skip online events (this is a physical Laois events calendar).
			if ( ! empty( $event['is_online_event'] ) ) {
				$skipped++;
				continue;
			}

			$raw_events[] = $normalizer->normalize( $event );
		}

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf( 'Eventbrite: %d Laois events after filtering, %d skipped (non-Laois or online).', count( $raw_events ), $skipped )
		);

		return $raw_events;
	}

	/**
	 * Check if an Eventbrite event is in Laois.
	 *
	 * Validates that the locations array contains a region entry with
	 * name "Laois". Does NOT search title or description.
	 *
	 * @param array $event Raw Eventbrite event.
	 * @return bool
	 */
	protected function is_laois_event( $event ) {
		if ( empty( $event['locations'] ) || ! is_array( $event['locations'] ) ) {
			return false;
		}

		foreach ( $event['locations'] as $location ) {
			if ( ! is_array( $location ) ) {
				continue;
			}
			if ( isset( $location['type'] ) && 'region' === $location['type'] ) {
				if ( isset( $location['name'] ) && 'Laois' === $location['name'] ) {
					return true;
				}
			}
		}

		return false;
	}
}