<?php
/**
 * Local Enterprise Office — Laois event source handler.
 *
 * Fetches and parses training events from the LEO Laois online bookings page.
 * https://www.localenterprise.ie/laois/training-events/online-bookings/
 *
 * The LEO website uses an ASP.NET web service (EventService.asmx) to load
 * events dynamically via AJAX. This handler calls the SearchEvents API
 * endpoint directly to get the event data.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Source_LEO_Laois extends Conexao_Source_Base {

	/**
	 * Base URL for LEO Laois training events.
	 *
	 * @var string
	 */
	protected $base_url = 'https://www.localenterprise.ie/laois/training-events/online-bookings/';

	/**
	 * API endpoint for searching events.
	 *
	 * @var string
	 */
	protected $api_url = 'https://www.localenterprise.ie/Laois/WebServices/EventService.asmx/SearchEvents';

	/**
	 * Get the source slug.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'leo_laois';
	}

	/**
	 * Get the default category for events from this source.
	 *
	 * @return string
	 */
	protected function get_default_category() {
		return isset( $this->config['category'] ) ? $this->config['category'] : 'Treinamento';
	}

	/**
	 * Fetch raw event listings from the LEO Laois online bookings API.
	 *
	 * The LEO website uses an ASP.NET web service to load events dynamically.
	 * We call the SearchEvents endpoint directly to get the event HTML.
	 *
	 * @return array[]
	 */
	public function fetch_events() {
		$url = isset( $this->config['url'] ) ? $this->config['url'] : $this->base_url;

		// Call the API to get events.
		$events_html = $this->fetch_events_from_api();

		if ( empty( $events_html ) ) {
			Conexao_Import_Log::add( $this->get_id(), 'warning', 'Nenhum evento encontrado na página do LEO Laois.' );
			return array();
		}

		// Parse the HTML response to extract events.
		$events = $this->parse_events_html( $events_html, $url );

		if ( empty( $events ) ) {
			Conexao_Import_Log::add( $this->get_id(), 'warning', 'Não foi possível extrair eventos da resposta da API.' );
		}

		return $events;
	}

	/**
	 * Fetch events HTML from the LEO API.
	 *
	 * @return string HTML containing event data, or empty string on failure.
	 */
	protected function fetch_events_from_api() {
		// Prepare the request body.
		$body = wp_json_encode( array(
			'req' => array(
				'Category' => '',
				'DateFrom' => '',
				'DateTo'   => '',
			),
		) );

		$response = wp_remote_post(
			$this->api_url,
			array(
				'timeout'    => 30,
				'user-agent' => 'Mozilla/5.0 (compatible; ConexaoEventImporter/1.0; +https://conexaobrirlanda.ie)',
				'headers'    => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'       => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			Conexao_Import_Log::add( $this->get_id(), 'error', 'Falha ao buscar API do LEO: ' . $response->get_error_message() );
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			Conexao_Import_Log::add( $this->get_id(), 'error', 'Resposta HTTP ' . $code . ' da API do LEO.' );
			return '';
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! $data || ! isset( $data['d'] ) ) {
			Conexao_Import_Log::add( $this->get_id(), 'error', 'Resposta inválida da API do LEO.' );
			return '';
		}

		// The API returns a JSON string inside the 'd' property.
		$inner_data = json_decode( $data['d'], true );

		if ( ! $inner_data || ! isset( $inner_data['IsSuccess'] ) || ! $inner_data['IsSuccess'] ) {
			$error = isset( $inner_data['ErrorText'] ) ? $inner_data['ErrorText'] : 'Unknown error';
			Conexao_Import_Log::add( $this->get_id(), 'error', 'API do LEO retornou erro: ' . $error );
			return '';
		}

		return isset( $inner_data['Html'] ) ? $inner_data['Html'] : '';
	}

	/**
	 * Parse the events HTML returned by the API.
	 *
	 * @param string $html HTML containing event data.
	 * @param string $url  Base URL for resolving relative paths.
	 * @return array[]
	 */
	protected function parse_events_html( $html, $url ) {
		$events = array();

		if ( empty( $html ) ) {
			return $events;
		}

		$dom = $this->parse_html( $html );
		if ( ! $dom ) {
			return $events;
		}

		$xpath = new DOMXPath( $dom );

		// Find all event containers.
		$event_nodes = $xpath->query( '//div[contains(@class, "event")]' );

		if ( ! $event_nodes || 0 === $event_nodes->length ) {
			return $events;
		}

		foreach ( $event_nodes as $node ) {
			$event = $this->extract_event_from_node( $node, $xpath, $url );
			if ( ! empty( $event['title'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Extract a single event from a DOM node.
	 *
	 * @param DOMNode  $node  Event container node.
	 * @param DOMXPath $xpath XPath object.
	 * @param string   $base  Base URL.
	 * @return array
	 */
	protected function extract_event_from_node( $node, $xpath, $base ) {
		$event = array(
			'source'      => $this->get_id(),
			'title'       => '',
			'url'         => '',
			'start_date'  => '',
			'start_time'  => '',
			'end_date'    => '',
			'end_time'    => '',
			'location'    => '',
			'description' => '',
			'image'       => '',
			'source_id'   => '',
			'category'    => $this->get_default_category(),
			'organizer'   => 'Local Enterprise Office — Laois',
			'price'       => '',
		);

		// Title and URL: find the event title link.
		$title_node = $xpath->query( './/h3[contains(@class, "eventTitle")]//a', $node );
		if ( $title_node && $title_node->length > 0 ) {
			$event['title'] = trim( $title_node->item( 0 )->textContent );
			$href = $title_node->item( 0 )->getAttribute( 'href' );
			if ( $href ) {
				$event['url'] = $this->resolve_url( $href, $base );
			}
		}

		// Source ID: derive from URL.
		if ( $event['url'] ) {
			$event['source_id'] = md5( $event['url'] );
		}

		// Extract event details from the eventInfo section.
		$info_nodes = $xpath->query( './/div[contains(@class, "eventInfo")]//div[contains(@class, "item")]', $node );
		if ( $info_nodes ) {
			foreach ( $info_nodes as $info_node ) {
				$label_node = $xpath->query( './/div[contains(@class, "lbl")]', $info_node );
				$value_node = $xpath->query( './/div[contains(@class, "value")]', $info_node );

				if ( $label_node && $label_node->length > 0 && $value_node && $value_node->length > 0 ) {
					$label = trim( $label_node->item( 0 )->textContent );
					$value = trim( $value_node->item( 0 )->textContent );

					// Remove trailing colon from label.
					$label = rtrim( $label, ':' );

					switch ( strtolower( $label ) ) {
						case 'venue':
							$event['location'] = $value;
							break;
						case 'date':
							$event['start_date'] = $this->parse_leo_date( $value );
							break;
						case 'time':
							$this->parse_leo_time( $value, $event );
							break;
					}
				}
			}
		}

		// Description: extract from the description/summary section.
		$summary_node = $xpath->query( './/div[contains(@class, "summary")]//p', $node );
		if ( $summary_node && $summary_node->length > 0 ) {
			$event['description'] = trim( $summary_node->item( 0 )->textContent );
		}

		// Category: extract from the description section.
		$category_nodes = $xpath->query( './/div[contains(@class, "description")]//div[contains(@class, "item")]', $node );
		if ( $category_nodes ) {
			foreach ( $category_nodes as $cat_node ) {
				$label_node = $xpath->query( './/div[contains(@class, "lbl")]', $cat_node );
				$value_node = $xpath->query( './/div[contains(@class, "value")]', $cat_node );

				if ( $label_node && $label_node->length > 0 && $value_node && $value_node->length > 0 ) {
					$label = trim( $label_node->item( 0 )->textContent );
					$label = rtrim( $label, ':' );

					if ( 'category' === strtolower( $label ) ) {
						// Use the LEO category as a sub-category tag.
						$leo_category = trim( $value_node->item( 0 )->textContent );
						if ( $leo_category ) {
							// Append LEO category to description for context.
							if ( $event['description'] ) {
								$event['description'] .= ' [' . $leo_category . ']';
							} else {
								$event['description'] = '[' . $leo_category . ']';
							}
						}
					}
				}
			}
		}

		// Price: extract from the booking section.
		$price_node = $xpath->query( './/div[contains(@class, "booking")]//span[contains(@class, "totalPrice")]', $node );
		if ( $price_node && $price_node->length > 0 ) {
			$event['price'] = trim( $price_node->item( 0 )->textContent );
		}

		return $event;
	}

	/**
	 * Parse a date string from the LEO format (dd/mm/yyyy) to Y-m-d.
	 *
	 * @param string $date Date string in dd/mm/yyyy format.
	 * @return string Date in Y-m-d format, or empty string.
	 */
	protected function parse_leo_date( $date ) {
		$date = trim( $date );
		if ( empty( $date ) ) {
			return '';
		}

		// Try dd/mm/yyyy format.
		if ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $m ) ) {
			return sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
		}

		// Try strtotime as fallback.
		$ts = strtotime( $date );
		if ( $ts ) {
			return date( 'Y-m-d', $ts );
		}

		return '';
	}

	/**
	 * Parse a time string from the LEO format and update the event array.
	 *
	 * @param string $time  Time string (e.g., "10am - 1pm" or "9:30am - 4:30pm").
	 * @param array  $event Event array to update.
	 */
	protected function parse_leo_time( $time, &$event ) {
		$time = trim( $time );
		if ( empty( $time ) ) {
			return;
		}

		// Try to match time range like "10am - 1pm" or "9:30am - 4:30pm".
		if ( preg_match( '/(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\s*[-–]\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i', $time, $m ) ) {
			$event['start_time'] = $this->normalize_time_string( $m[1] );
			$event['end_time']   = $this->normalize_time_string( $m[2] );
		}
	}

	/**
	 * Normalize a time string to H:i format.
	 *
	 * @param string $time Time string.
	 * @return string Time in H:i format.
	 */
	protected function normalize_time_string( $time ) {
		$time = trim( strtolower( $time ) );

		// Try to parse with strtotime.
		$ts = strtotime( $time );
		if ( $ts ) {
			return date( 'H:i', $ts );
		}

		return '';
	}
}