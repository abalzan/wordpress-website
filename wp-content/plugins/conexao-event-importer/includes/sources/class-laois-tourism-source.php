<?php
/**
 * Laois Tourism event source handler.
 *
 * Fetches and parses events from https://laoistourism.ie/events/
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Source_Laois_Tourism extends Conexao_Source_Base {

	/**
	 * Get the source slug.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'laois_tourism';
	}

	/**
	 * Fetch raw event listings from the Laois Tourism events page.
	 *
	 * @return array[]
	 */
	public function fetch_events() {
		$url  = isset( $this->config['url'] ) ? $this->config['url'] : 'https://laoistourism.ie/events/';
		$html = $this->fetch_html( $url );

		if ( empty( $html ) ) {
			return array();
		}

		$dom = $this->parse_html( $html );
		if ( ! $dom ) {
			Conexao_Import_Log::add( $this->get_id(), 'error', 'Não foi possível analisar o HTML da página de eventos.' );
			return array();
		}

		$xpath = new DOMXPath( $dom );

		// Try multiple common event-card container selectors.
		$nodes = $this->find_event_nodes( $xpath );

		if ( empty( $nodes ) ) {
			Conexao_Import_Log::add( $this->get_id(), 'warning', 'Nenhum evento encontrado na página de eventos.' );
			return array();
		}

		$events = array();
		foreach ( $nodes as $node ) {
			$event = $this->extract_event( $node, $xpath, $url );
			if ( ! empty( $event['title'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Find event container nodes using flexible selectors.
	 *
	 * @param DOMXPath $xpath XPath object.
	 * @return DOMNodeList|array<int, never> The first selector that matches,
	 *                                      or an empty array when none match.
	 */
	protected function find_event_nodes( $xpath ) {
		$selectors = array(
			'//article',
			'//div[contains(concat(" ", normalize-space(@class), " "), " event ")]',
			'//div[contains(concat(" ", normalize-space(@class), " "), " event-item ")]',
			'//div[contains(concat(" ", normalize-space(@class), " "), " event-card ")]',
			'//div[contains(concat(" ", normalize-space(@class), " "), " event-listing ")]',
			'//li[contains(concat(" ", normalize-space(@class), " "), " event ")]',
			'//div[contains(concat(" ", normalize-space(@class), " "), " et_pb_event ")]',
		);

		foreach ( $selectors as $selector ) {
			$nodes = $xpath->query( $selector );
			if ( $nodes && $nodes->length > 0 ) {
				return $nodes;
			}
		}

		return array();
	}

	/**
	 * Extract a single event from a DOM node.
	 *
	 * @param DOMNode  $node  Event container node.
	 * @param DOMXPath $xpath XPath object.
	 * @param string   $base  Base URL.
	 * @return array
	 */
	protected function extract_event( $node, $xpath, $base ) {
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
		);

		// Title: try several selectors.
		$title_selectors = array(
			'.//h2 | .//h3 | .//h4 | .//h5',
			'.//a[contains(concat(" ", normalize-space(@class), " "), " title ")]',
			'.//a',
		);
		foreach ( $title_selectors as $selector ) {
			$title_node = $xpath->query( $selector, $node );
			if ( $title_node && $title_node->length > 0 ) {
				$event['title'] = trim( $title_node->item( 0 )->textContent );
				if ( $event['title'] ) {
					break;
				}
			}
		}

		// URL: find the first link.
		$link = $xpath->query( './/a', $node );
		if ( $link && $link->length > 0 ) {
			$href = $link->item( 0 )->getAttribute( 'href' );
			if ( $href ) {
				$event['url'] = $this->resolve_url( $href, $base );
			}
		}

		// Source ID: derive from URL if possible.
		if ( $event['url'] ) {
			$event['source_id'] = md5( $event['url'] );
		}

		// Image: find the first image.
		$img = $xpath->query( './/img', $node );
		if ( $img && $img->length > 0 ) {
			$src = $img->item( 0 )->getAttribute( 'src' );
			if ( $src ) {
				$event['image'] = $this->resolve_url( $src, $base );
			}
		}

		// Description: use the full text content, trimmed.
		$full_text = trim( $node->textContent );
		if ( $event['title'] ) {
			$full_text = str_replace( $event['title'], '', $full_text );
		}
		$event['description'] = trim( preg_replace( '/\s+/', ' ', $full_text ) );

		// Date: look for date patterns in the node text.
		$node_text = $node->textContent;
		if ( preg_match( '/(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})/i', $node_text, $m ) ) {
			$event['start_date'] = $m[1] . ' ' . $m[2] . ' ' . $m[3];
		} elseif ( preg_match( '/(\d{1,2})\s+([A-Za-z]{3})\s+(\d{4})/', $node_text, $m ) ) {
			$event['start_date'] = $m[1] . ' ' . $m[2] . ' ' . $m[3];
		} elseif ( preg_match( '/(\d{4})-(\d{2})-(\d{2})/', $node_text, $m ) ) {
			$event['start_date'] = $m[0];
		}

		// Time: look for time patterns.
		if ( preg_match( '/(\d{1,2}:\d{2})\s*(?:am|pm)?/i', $node_text, $tm ) ) {
			$event['start_time'] = $tm[0];
		}

		// Location: look for "at [venue]" or location-like text.
		if ( preg_match( '/\bat\s+([A-Z][A-Za-z0-9\s\'\-\.]+)/i', $node_text, $lm ) ) {
			$event['location'] = trim( $lm[1] );
		}

		return $event;
	}
}