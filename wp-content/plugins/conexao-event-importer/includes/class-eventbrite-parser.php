<?php
/**
 * Eventbrite HTML parser.
 *
 * Extracts `window.__SERVER_DATA__` from Eventbrite's server-rendered HTML
 * and parses it into structured event data.
 *
 * The relevant structure is:
 *   window.__SERVER_DATA__
 *     └── search_data
 *          └── events
 *               ├── results
 *               ├── pagination
 *               ├── aggs
 *               └── promoted_results
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Eventbrite_Parser {

	/**
	 * Parse Eventbrite HTML and extract events + pagination.
	 *
	 * @param string $html Raw HTML from Eventbrite discovery page.
	 * @return array{
	 *   events: array[],
	 *   pagination: array,
	 *   raw: array
	 * }
	 * @throws Exception If the HTML structure is invalid or missing expected data.
	 */
	public function parse( $html ) {
		if ( empty( $html ) ) {
			throw new Exception( 'Eventbrite HTML is empty.' );
		}

		$server_data = $this->extract_server_data( $html );
		if ( null === $server_data ) {
			throw new Exception( 'window.__SERVER_DATA__ not found in Eventbrite HTML.' );
		}

		if ( ! is_array( $server_data ) ) {
			throw new Exception( 'window.__SERVER_DATA__ is not a valid object.' );
		}

		if ( ! isset( $server_data['search_data'] ) || ! is_array( $server_data['search_data'] ) ) {
			throw new Exception( 'search_data not found in __SERVER_DATA__.' );
		}

		if ( ! isset( $server_data['search_data']['events'] ) || ! is_array( $server_data['search_data']['events'] ) ) {
			throw new Exception( 'search_data.events not found in __SERVER_DATA__.' );
		}

		$events_data = $server_data['search_data']['events'];

		if ( ! isset( $events_data['results'] ) || ! is_array( $events_data['results'] ) ) {
			throw new Exception( 'search_data.events.results is not an array.' );
		}

		if ( ! isset( $events_data['pagination'] ) || ! is_array( $events_data['pagination'] ) ) {
			throw new Exception( 'search_data.events.pagination not found.' );
		}

		$events = array();
		foreach ( $events_data['results'] as $raw_event ) {
			if ( ! is_array( $raw_event ) ) {
				continue;
			}
			if ( empty( $raw_event['id'] ) ) {
				continue;
			}
			$events[] = $raw_event;
		}

		return array(
			'events'     => $events,
			'pagination' => $events_data['pagination'],
			'raw'        => $server_data,
		);
	}

	/**
	 * Extract the `window.__SERVER_DATA__` JavaScript object from HTML.
	 *
	 * Handles the common patterns:
	 *   window.__SERVER_DATA__ = {...};
	 *   window.__SERVER_DATA__={...};
	 *   window["__SERVER_DATA__"] = {...};
	 *
	 * @param string $html Raw HTML.
	 * @return array|null Parsed object or null if not found.
	 */
	protected function extract_server_data( $html ) {
		// Primary method: find `window.__SERVER_DATA__ =` anywhere in the HTML
		// and extract the JSON object using a brace-matching approach.
		// This is more robust than regex because the JSON object is large and
		// contains many nested braces, and may not be inside a <script> tag.
		if ( preg_match( '/window\.__SERVER_DATA__\s*=\s*/', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			$start = $match[0][1] + strlen( $match[0][0] );
			$json  = $this->extract_json_object( $html, $start );
			if ( null !== $json ) {
				$data = json_decode( $json, true );
				if ( is_array( $data ) ) {
					return $data;
				}
			}
		}

		// Fallback: try regex patterns for simpler structures.
		$patterns = array(
			'/window\.__SERVER_DATA__\s*=\s*(\{.*?\});?\s*<\/script>/s',
			'/window\[["\']__SERVER_DATA__["\']\]\s*=\s*(\{.*?\});?\s*<\/script>/s',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $html, $matches ) ) {
				$json = $matches[1];
				$data = json_decode( $json, true );
				if ( is_array( $data ) ) {
					return $data;
				}
			}
		}

		return null;
	}

	/**
	 * Extract a balanced JSON object starting at a given offset.
	 *
	 * @param string $html   HTML string.
	 * @param int    $offset Offset where the object starts (at '{').
	 * @return string|null JSON string or null on failure.
	 */
	protected function extract_json_object( $html, $offset ) {
		$length = strlen( $html );
		$depth  = 0;
		$in_str = false;
		$esc    = false;

		for ( $i = $offset; $i < $length; $i++ ) {
			$char = $html[ $i ];

			if ( $in_str ) {
				if ( $esc ) {
					$esc = false;
					continue;
				}
				if ( '\\' === $char ) {
					$esc = true;
					continue;
				}
				if ( '"' === $char ) {
					$in_str = false;
				}
				continue;
			}

			if ( '"' === $char ) {
				$in_str = true;
				continue;
			}

			if ( '{' === $char ) {
				$depth++;
				continue;
			}

			if ( '}' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $html, $offset, $i - $offset + 1 );
				}
			}
		}

		return null;
	}
}