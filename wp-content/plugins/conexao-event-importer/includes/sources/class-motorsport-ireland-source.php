<?php
/**
 * Motorsport Ireland event source handler.
 *
 * Fetches and parses upcoming events from the Motorsport Ireland master
 * calendar: https://www.motorsportireland.com/events
 *
 * The site runs Squarespace 6 (no public API). All events render on a single
 * listing page (no pagination, no infinite scroll, no AJAX). Each card
 * carries a stable hexadecimal UID in its `id="item-{hex}"` attribute that
 * matches the per-event ICS UID - this is the primary source identity.
 *
 * Strategy (modelled on Conexao_Source_Heritage_Week):
 *  1. Fetch the master events page and extract every upcoming card
 *     (`article.eventlist-event--upcoming`).
 *  2. Collapse duplicate cards by stable hex UID.
 *  3. Enrich each event from its canonical detail page
 *     (`/events/{slug}`) to read the authoritative JSON-LD date/time
 *     (IST-aware ISO) and full description.
 *  4. Normalize into the shared raw event array consumed by the central
 *     importer (normalizer -> date filter -> dedup -> upsert).
 *
 * Date authority: ONLY the JSON-LD `startDate`/`endDate` (with explicit IST
 * offset, e.g. `2026-09-13T13:00:00+0100`) is parsed for dates. Display text
 * is never parsed for dates. The wall-clock event time is preserved as-is -
 * it is NOT reinterpreted as UTC.
 *
 * Cancelled events (`(CANCELLED)` title suffix) are skipped entirely.
 * Rescheduled events (`(Rescheduled)` suffix) have the suffix stripped and
 * are imported with the JSON-LD date (which reflects the new date).
 *
 * No location data or event-specific images exist at the source - those
 * fields are left empty rather than invented.
 *
 * A failure on one event never stops the rest of the import.
 *
 * @package Conexao_Event_Importer
 */

defined( "ABSPATH" ) || exit;

class Conexao_Source_Motorsport_Ireland extends Conexao_Source_Base {

	const DEFAULT_URL         = "https://www.motorsportireland.com/events";
	const MAX_DETAIL_PAGES    = 60;
	const DEFAULT_CRAWL_DELAY = 2; // Seconds between detail-page requests.

	/**
	 * Title suffixes that mark a cancelled event. Cancelled events skipped.
	 *
	 * @var string[]
	 */
	const CANCELLED_SUFFIXES = array( "(CANCELLED)", "(CANCELLED )" );

	/**
	 * Title suffixes that mark a rescheduled event. Suffix is stripped.
	 *
	 * @var string[]
	 */
	const RESCHEDULED_SUFFIXES = array( "(RESCHEDULED)", "(RESCHEDULED )", "(Rescheduled)" );

	/**
	 * Get the source slug.
	 *
	 * @return string
	 */
	public function get_id() {
		return "motorsport_ireland";
	}

	/**
	 * Fetch raw upcoming events from the Motorsport Ireland master calendar.
	 *
	 * @return array[]
	 */
	public function fetch_events() {
		$base_url = isset( $this->config['url'] ) && $this->config['url'] ? $this->config['url'] : self::DEFAULT_URL;

		$html = $this->fetch_html( $base_url );
		if ( empty( $html ) ) {
			return array();
		}

		$cards = self::parse_listing_cards( $html, $base_url );

		// Collapse duplicate cards by stable hex UID.
		$seen   = array();
		$unique = array();
		foreach ( $cards as $card ) {
			$sid = isset( $card['source_id'] ) ? (string) $card['source_id'] : '';
			if ( '' !== $sid && isset( $seen[ $sid ] ) ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'info',
					sprintf( 'Motorsport Ireland duplicate listing card ignored (UID %s).', $sid ),
					array( 'url' => $base_url )
				);
				continue;
			}
			$seen[ $sid ] = true;
			$unique[]     = $card;
		}

		$max_detail  = isset( $this->config['max_detail_pages'] ) ? (int) $this->config['max_detail_pages'] : self::MAX_DETAIL_PAGES;
		$crawl_delay = isset( $this->config['crawl_delay'] ) ? (int) $this->config['crawl_delay'] : self::DEFAULT_CRAWL_DELAY;
		if ( $crawl_delay < 1 ) {
			$crawl_delay = self::DEFAULT_CRAWL_DELAY;
		}

		$events         = array();
		$detail_fetches = 0;

		foreach ( $unique as $card ) {
			try {
				if ( empty( $card['source_id'] ) || empty( $card['url'] ) ) {
					continue;
				}

				// Cancelled events are dropped here (IVVCC pattern):
				// logged and never returned to the engine, so they can
				// never be imported.
				if ( ! empty( $card['_skip'] ) ) {
					Conexao_Import_Log::add(
						$this->get_id(),
						'info',
						sprintf(
							'Evento cancelado ignorado: %1$s. %2$s',
							isset( $card['title'] ) ? $card['title'] : '',
							isset( $card['_skip_reason'] ) ? $card['_skip_reason'] : ''
						),
						array( 'source_id' => isset( $card['source_id'] ) ? $card['source_id'] : '' )
					);
					continue;
				}

				if ( $detail_fetches < $max_detail ) {
					$card = $this->enrich_from_detail( $card );
					$detail_fetches++;
					if ( $detail_fetches < $max_detail ) {
						sleep( $crawl_delay );
					}
				}

				$events[] = $card;
			} catch ( Exception $e ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'error',
					'Erro ao processar evento do Motorsport Ireland: ' . $e->getMessage(),
					array( 'source_id' => isset( $card['source_id'] ) ? $card['source_id'] : '' )
				);
			}
		}

		return $events;
	}

	/**
	 * Enrich a listing card from its canonical detail page.
	 *
	 * @param array $event Listing card event.
	 * @return array Enriched event (or original on failure).
	 */
	protected function enrich_from_detail( $event ) {
		$url = isset( $event['url'] ) ? $event['url'] : '';
		if ( empty( $url ) ) {
			return $event;
		}

		$html = $this->fetch_html_or_empty( $url );
		if ( empty( $html ) ) {
			return $event;
		}

		// JSON-LD dates are authoritative (IST-aware ISO).
		$jsonld = self::parse_jsonld_dates( $html );
		if ( '' !== $jsonld['start_date'] ) {
			$event['start_date'] = $jsonld['start_date'];
			$event['start_time'] = $jsonld['start_time'];
		}
		if ( '' !== $jsonld['end_date'] ) {
			$event['end_date'] = $jsonld['end_date'];
			$event['end_time'] = $jsonld['end_time'];
		}

		$description = self::parse_detail_description( $html );
		if ( '' !== $description ) {
			$event['description'] = $description;
		}

		return $event;
	}


	// ------------------------------------------------------------------
	// Pure/static parse helpers - fixture-safe.
	// ------------------------------------------------------------------

	/**
	 * Parse upcoming event cards from the master listing page.
	 *
	 * @param string $html      Listing page HTML.
	 * @param string $base_url  Base URL for resolving relative links.
	 * @return array[] Array of raw event arrays.
	 */
	public static function parse_listing_cards( $html, $base_url = self::DEFAULT_URL ) {
		$events = array();
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return $events;
		}

		$dom = self::parse_html_static( $html );
		if ( ! $dom ) {
			return $events;
		}

		$xpath = new DOMXPath( $dom );
		$cards  = $xpath->query( '//article[contains(concat(" ", normalize-space(@class), " "), " eventlist-event--upcoming ")]' );

		if ( ! $cards || 0 === $cards->length ) {
			$cards = $xpath->query( '//article[contains(concat(" ", normalize-space(@class), " "), " eventlist-event ") and not(contains(concat(" ", normalize-space(@class), " "), " eventlist-event--past "))]' );
		}

		if ( ! $cards || 0 === $cards->length ) {
			return $events;
		}

		foreach ( $cards as $card ) {
			$event = self::parse_listing_card( $card, $xpath, $base_url );
			if ( empty( $event ) ) {
				continue;
			}
			if ( empty( $event['source_id'] ) || empty( $event['title'] ) ) {
				continue;
			}
			$events[] = $event;
		}

		return $events;
	}


	/**
	 * Parse a single listing card into a raw event array.
	 *
	 * @param DOMElement $card      Card node.
	 * @param DOMXPath   $xpath     XPath bound to the document.
	 * @param string     $base_url  Base URL for resolving relative links.
	 * @return array|null Raw event array or null on failure.
	 */
	public static function parse_listing_card( $card, $xpath, $base_url = self::DEFAULT_URL ) {
		$event = array(
			'source'       => 'motorsport_ireland',
			'title'        => '',
			'url'          => '',
			'source_id'    => '',
			'start_date'   => '',
			'start_time'   => '',
			'end_date'     => '',
			'end_time'     => '',
			'location'     => '',
			'description'  => '',
			'image'        => '',
			'organizer'    => '',
			'category'     => '',
			'price'        => '',
			// Motorsport Ireland publishes NO location data (listing card,
			// detail page and JSON-LD all lack it — audit §2.7). The unknown
			// state is preserved rather than manufacturing a value; this
			// opt-in flag tells the normalizer that an empty location is
			// valid data for this source (additive; other sources unaffected).
			'location_optional' => true,
			'_skip'        => false,
			'_skip_reason' => '',
			'_is_multiday' => false,
			'_rescheduled' => false,
		);

		// Stable hex UID from id="item-{hex}".
		// The UID may be on the article element itself OR on a descendant
		// div[data-type="item"] (Squarespace renders it inside the
		// eventlist-description on the live site).
		$id_attr = $card->getAttribute( 'id' );
		if ( preg_match( '/^item-([0-9a-fA-F]+)$/', $id_attr, $m ) ) {
			$event['source_id'] = $m[1];
		} else {
			$uid_nodes = $xpath->query( './/*[starts-with(@id, "item-")]', $card );
			if ( $uid_nodes && $uid_nodes->length > 0 ) {
				$uid_attr = $uid_nodes->item( 0 )->getAttribute( 'id' );
				if ( preg_match( '/^item-([0-9a-fA-F]+)$/', $uid_attr, $m ) ) {
					$event['source_id'] = $m[1];
				}
			}
		}

		// Title + canonical URL from the eventlist-title link.
		$title_nodes = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " eventlist-title ")]', $card );
		if ( $title_nodes && $title_nodes->length > 0 ) {
			$title_node = $title_nodes->item( 0 );
			$link       = $xpath->query( './/a[@href]', $title_node );
			if ( $link && $link->length > 0 ) {
				$event['url']   = self::resolve_url_static( $link->item( 0 )->getAttribute( 'href' ), $base_url );
				$event['title'] = self::clean_text( $link->item( 0 )->textContent );
			}
			if ( '' === $event['title'] ) {
				$event['title'] = self::clean_text( $title_node->textContent );
			}
		}

		// Fallback URL: first /events/ link inside the card.
		if ( '' === $event['url'] ) {
			$any_link = $xpath->query( './/a[@href]', $card );
			if ( $any_link && $any_link->length > 0 ) {
				foreach ( $any_link as $a ) {
					$href = $a->getAttribute( 'href' );
					if ( preg_match( '#/events/[^/]+#', $href ) ) {
						$event['url'] = self::resolve_url_static( $href, $base_url );
						break;
					}
				}
			}
		}

		// HTML datetime fallback (used only when JSON-LD is unavailable).
		$time_nodes = $xpath->query( './/time[@datetime]', $card );
		if ( $time_nodes && $time_nodes->length > 0 ) {
			$dt     = $time_nodes->item( 0 )->getAttribute( 'datetime' );
			$parsed = self::parse_html_datetime( $dt );
			if ( '' !== $parsed['date'] ) {
				$event['start_date'] = $parsed['date'];
				$event['start_time'] = $parsed['time'];
			}
		}

		// Multi-day modifier detection.
		$classes = ' ' . $card->getAttribute( 'class' ) . ' ';
		if ( preg_match( '/eventlist-event--multiday/', $classes ) ) {
			$event['_is_multiday'] = true;
		}

		// Categories: eventlist-cats links (club + type).
		$cat_nodes = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " eventlist-cats ")]', $card );
		$cat_links = null;
		if ( $cat_nodes && $cat_nodes->length > 0 ) {
			$cat_links = $xpath->query( './/a[@href]', $cat_nodes->item( 0 ) );
		}
		$cats = array();
		if ( $cat_links ) {
			foreach ( $cat_links as $a ) {
				$cats[] = self::clean_text( $a->textContent );
			}
		}
		if ( count( $cats ) >= 2 ) {
			// Two-link cards: first = affiliated club, second = event type
			// (audit §30.3 positional mapping).
			$event['organizer'] = $cats[0];
			$event['category']  = $cats[1];
		} elseif ( 1 === count( $cats ) && '' !== $cats[0] ) {
			// Single-link cards carry the event type only (observed live:
			// the sole link is always a discipline, e.g. "Rally"/"Karting"
			// — a club never appears alone on this source). A discipline is
			// never mis-attributed as the organizer.
			$event['category'] = $cats[0];
		}

		// eventlist-description sometimes holds rescheduling notes.
		$desc_nodes = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " eventlist-description ")]', $card );
		if ( $desc_nodes && $desc_nodes->length > 0 ) {
			$desc_text = self::clean_text( $desc_nodes->item( 0 )->textContent );
			if ( '' !== $desc_text ) {
				$event['description'] = $desc_text;
			}
		}

		// Apply title-suffix rules (cancellation / rescheduling).
		$event = self::apply_title_suffix_rules( $event );

		return $event;
	}


	/**
	 * Apply cancellation / rescheduling rules based on title suffixes.
	 *
	 * - (CANCELLED)  -> mark skipped (never imported).
	 * - (Rescheduled) -> strip suffix, keep JSON-LD date as-is.
	 *
	 * @param array $event Raw event array.
	 * @return array Modified event array.
	 */
	public static function apply_title_suffix_rules( $event ) {
		$title = isset( $event['title'] ) ? $event['title'] : '';
		if ( '' === $title ) {
			return $event;
		}

		foreach ( self::CANCELLED_SUFFIXES as $suffix ) {
			if ( preg_match( '/\s*' . preg_quote( $suffix, '/' ) . '\s*$/i', $title ) ) {
				$event['_skip']        = true;
				$event['_skip_reason'] = 'Cancelled event (' . trim( $suffix, ' ') . ')';
				return $event;
			}
		}

		foreach ( self::RESCHEDULED_SUFFIXES as $suffix ) {
			if ( preg_match( '/\s*' . preg_quote( $suffix, '/' ) . '\s*$/i', $title ) ) {
				$event['title']        = trim( preg_replace( '/\s*' . preg_quote( $suffix, '/' ) . '\s*$/i', '', $title ) );
				$event['_rescheduled'] = true;
				break;
			}
		}

		return $event;
	}

	/**
	 * Parse the authoritative JSON-LD dates from an event detail page.
	 *
	 * The JSON-LD `startDate`/`endDate` carry an explicit IST offset
	 * (e.g. `2026-09-13T13:00:00+0100`). The wall-clock event time is
	 * preserved as-is - it is NOT reinterpreted as UTC.
	 *
	 * @param string $html Detail page HTML.
	 * @return array{start_date:string, start_time:string, end_date:string, end_time:string}
	 */
	public static function parse_jsonld_dates( $html ) {
		$result = array(
			'start_date' => '',
			'start_time' => '',
			'end_date'   => '',
			'end_time'   => '',
		);

		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return $result;
		}

		if ( ! preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return $result;
		}

		foreach ( $matches[1] as $json ) {
			$data = json_decode( trim( (string) $json ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$event_data = self::find_jsonld_event( $data );
			if ( ! $event_data ) {
				continue;
			}

			$start = isset( $event_data['startDate'] ) ? (string) $event_data['startDate'] : '';
			$end   = isset( $event_data['endDate'] ) ? (string) $event_data['endDate'] : '';

			if ( '' !== $start ) {
				$parsed                = self::parse_ist_iso_datetime( $start );
				$result['start_date']  = $parsed['date'];
				$result['start_time']  = $parsed['time'];
			}
			if ( '' !== $end ) {
				$parsed              = self::parse_ist_iso_datetime( $end );
				$result['end_date']  = $parsed['date'];
				$result['end_time']  = $parsed['time'];
			}

			if ( '' !== $result['start_date'] ) {
				return $result;
			}
		}

		return $result;
	}

	/**
	 * Find the first schema.org/Event object in decoded JSON-LD data.
	 *
	 * @param array $data Decoded JSON-LD data.
	 * @return array|null Event object or null.
	 */
	protected static function find_jsonld_event( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}

		if ( isset( $data['@type'] ) && self::is_event_type( $data['@type'] ) ) {
			return $data;
		}

		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $node ) {
				if ( is_array( $node ) && isset( $node['@type'] ) && self::is_event_type( $node['@type'] ) ) {
					return $node;
				}
			}
		}

		foreach ( $data as $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) && self::is_event_type( $node['@type'] ) ) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * Check whether a JSON-LD @type value represents a schema.org Event.
	 *
	 * @param mixed $type Type value (string or array).
	 * @return bool
	 */
	protected static function is_event_type( $type ) {
		foreach ( (array) $type as $t ) {
			$t = strtolower( (string) $t );
			if ( 'event' === $t || preg_match( '/event$/', $t ) ) {
				return true;
			}
		}
		return false;
	}


	/**
	 * Parse an IST-aware ISO 8601 datetime into wall-clock date + time.
	 *
	 * Handles formats like:
	 *   - `2026-09-13T13:00:00+0100`
	 *   - `2026-09-13T13:00:00+01:00`
	 *   - `2026-09-13T13:00:00Z`
	 *   - `2026-09-13T13:00`
	 *
	 * The wall-clock time is preserved as represented - it is NOT
	 * reinterpreted as UTC.
	 *
	 * @param string $iso Raw ISO datetime.
	 * @return array{date:string, time:string} Y-m-d and H:i (or '' on failure).
	 */
	public static function parse_ist_iso_datetime( $iso ) {
		$iso = trim( (string) $iso );
		if ( '' === $iso ) {
			return array( 'date' => '', 'time' => '' );
		}

		$patterns = array(
			'/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?([+-]\d{2}:?\d{2}|Z)?$/',
			'/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(:\d{2})?$/',
			'/^(\d{4}-\d{2}-\d{2})$/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $iso, $m ) ) {
				$date = $m[1];
				$time = isset( $m[2] ) ? $m[2] : '';
				if ( '' !== $time && preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
					return array( 'date' => $date, 'time' => $time );
				}
				return array( 'date' => $date, 'time' => '' );
			}
		}

		// Last resort: strtotime (respects offset).
		$ts = strtotime( $iso );
		if ( $ts ) {
			return array(
				'date' => gmdate( 'Y-m-d', $ts ),
				'time' => gmdate( 'H:i', $ts ),
			);
		}

		return array( 'date' => '', 'time' => '' );
	}

	/**
	 * Parse an HTML `datetime` attribute (implicit IST, no offset).
	 *
	 * @param string $dt datetime attribute value.
	 * @return array{date:string, time:string}
	 */
	protected static function parse_html_datetime( $dt ) {
		$dt = trim( (string) $dt );
		if ( '' === $dt ) {
			return array( 'date' => '', 'time' => '' );
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $dt, $m ) ) {
			return array( 'date' => $m[1], 'time' => $m[2] );
		}
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})$/', $dt, $m ) ) {
			return array( 'date' => $m[1], 'time' => '' );
		}
		return array( 'date' => '', 'time' => '' );
	}

	/**
	 * Parse the full event description from a detail page.
	 *
	 * @param string $html Detail page HTML.
	 * @return string Cleaned description or ''.
	 */
	public static function parse_detail_description( $html ) {
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return '';
		}

		$dom = self::parse_html_static( $html );
		if ( ! $dom ) {
			return '';
		}

		$xpath   = new DOMXPath( $dom );
		$content = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " eventitem-column-content ")]' );

		if ( $content && $content->length > 0 ) {
			$text = self::clean_text( $content->item( 0 )->textContent );
			// The detail content column often contains only the category
			// meta line ("Posted In: Club, Type") — that is taxonomy data,
			// not a description (already captured from the card). Never
			// import it as body text.
			if ( preg_match( '/^Posted In:/i', $text ) ) {
				return '';
			}
			return $text;
		}

		return '';
	}


	/**
	 * Parse HTML into a DOMDocument (static, fixture-safe).
	 *
	 * @param string $html Raw HTML.
	 * @return DOMDocument|false
	 */
	public static function parse_html_static( $html ) {
		if ( empty( $html ) ) {
			return false;
		}
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		return $dom;
	}

	/**
	 * Clean raw text content (collapse whitespace, trim).
	 *
	 * @param string $text Raw text.
	 * @return string Cleaned text.
	 */
	public static function clean_text( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Resolve a possibly-relative URL to an absolute URL (static).
	 *
	 * @param string $url  Raw URL.
	 * @param string $base Base URL.
	 * @return string
	 */
	public static function resolve_url_static( $url, $base ) {
		$url = trim( $url );
		if ( empty( $url ) ) {
			return '';
		}
		if ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) ) {
			return $url;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return 'https:' . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$parts = wp_parse_url( $base );
			return ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . ( isset( $parts['host'] ) ? $parts['host'] : '' ) . $url;
		}
		return rtrim( $base, '/' ) . '/' . ltrim( $url, '/' );
	}
}
