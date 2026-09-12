<?php
/**
 * Mondello Park event source handler.
 *
 * Fetches events from Ireland's National Motorsports Campus:
 *   Discovery : https://mondellopark.ie/wp-json/wp/v2/events?per_page=100
 *   Detail    : https://mondellopark.ie/events/{slug}/
 *
 * Stage A audit (docs/importers/mondello-park-stage-a-audit.md) found:
 *   - 29 published events via REST (X-WP-Total: 29, one page at per_page=100).
 *   - no public event-date/time/venue/price/ticket API — dates exist only as
 *     visible HTML text on detail pages (US "Month DD, YYYY" format).
 *   - Ticketsolve (JS SPA) behind Queue-it; primary "Book Now" URLs captured
 *     from detail-page HTML, utm params stripped.
 *   - no schema.org/Event structured data anywhere.
 *
 * Identity strategy (audit + Stage B setup):
 *   Primary   : _event_source_id = stable Mondello Park event slug (audit
 *               confirmed 29/29 unique slugs and 29/29 unique WP IDs).
 *   Fallback  : WordPress REST source post ID (id field) recorded for
 *               reference when slug is empty; never used as primary identity.
 *
 * Date authority: ONLY the visible date paragraph on the detail page.
 *   The REST "date" field is the WP post_modified / publish timestamp and is
 *   NEVER used as the event date.
 *   Single-day   : "September 12, 2026"
 *   Multi-day    : "September 12, 2026 - October 14, 2026"  (same-day range
 *                  collapses to one day, e.g. "Sep 6 - Sep 6").
 *   TBA/TBC      : rejected (no date parsed).
 *
 * Time strategy: event_time_source = none. No machine-readable event time
 *   exists anywhere in the source. _event_start_time / _event_end_time left
 *   empty. Times are NOT inferred from ticketing "doors" / opening text.
 *
 * Status strategy: reuse Conexao_Event_Status; do not invent statuses.
 *   Past events are filtered by the shared Conexao_Event_Date_Filter.
 *
 * Location strategy:
 *   - Source-supplied values only (venue/location text scraped from detail
 *     page). The footer address of Mondello Park is NOT hard-coded into every
 *     event. Where the detail page omits address/town/county/Eircode those
 *     fields are left empty rather than invented.
 *   - The source config carries county => 'Kildare' as a source-scoped hint so
 *     that events whose detail page only surfaces the venue name ("Mondello
 *     Park") still receive a valid county term without inventing an address.
 *   - "Mondello Park" alone is a venue label, not an address — the existing
 *     Conexao_Event_Address::is_plausible() no-guess rule correctly rejects
 *     it as an address candidate, so no special source flag is required.
 *
 * Category mapping (audited, Stage B setup JSON):
 *   car-racing       -> Car Racing
 *   drifting         -> Drifting
 *   rally            -> Rally
 *   motorbike-racing -> Motorbike Racing
 *   jdm              -> JDM
 *   retro-historic   -> Retro/Historic
 *   shows            -> Shows
 *   ids              -> IDS
 *   Unknown term slugs are logged as warnings and the event is still returned
 *   for normalization (the existing normalizer handles unknown categories
 *   gracefully). No events are dropped solely because a category could not be
 *   mapped.
 *
 * Ticket URL: primary "Book Now" Ticketsolve URL (utm params stripped) stored
 *   in the event URL field. Secondary show IDs stored in raw['_ticket_show_ids']
 *   custom meta only — never in a URL field. For Winter Bash two Ticketsolve
 *   show IDs exist; both IDs are logged for reference.
 *
 *   VERIFIED AGAINST LIVE SOURCE (2026-09-12, Stage C pre-write check):
 *   https://mondellopark.ie/events/drift-games-winter-bash/ renders
 *     <a class="btn"   href="…/shows/1173670623">DRIFT BASH TICKETS</a>   (first in DOM)
 *     <a class="btn "  href="…/shows/1173670621">Book Now</a>             (primary CTA)
 *   The earlier "first Ticketsolve link encountered" heuristic therefore
 *   selected the secondary "DRIFT BASH TICKETS" body link (1173670623) as the
 *   primary. The extractor now prefers, in order: (1) an explicit
 *   `book-now` class hook, (2) a Ticketsolve anchor labelled "Book Now",
 *   (3) first Ticketsolve link in DOM order. Winter Bash primary = 1173670621.
 *
 * Content boundary: factual fields only (title, factual dates, venue when
 *   supplied, organizer when supplied, category, ticket URL, canonical Mondello
 *   Park URL, source identity, source attribution). No marketing copy, no
 *   large images, no logos, no PDFs, no ticket terms.
 *
 * HTTP: browser UA + Accept-Language: en-GB; 1.0 s delay between detail fetches;
 *   exponential back-off on 403; never hit tickets.mondellopark.ie or
 *   ticketsolve /api/.
 *
 * Inactive by default — see Conexao_Event_Sources default seed.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Source_Mondello_Park extends Conexao_Source_Base {

	const SOURCE_KEY          = 'mondellopark';
	const DEFAULT_DISCOVERY_URL = 'https://mondellopark.ie/wp-json/wp/v2/events?per_page=100';
	const DETAIL_URL_PATTERN  = 'https://mondellopark.ie/events/%s/';
	const MAX_DETAIL_PAGES    = 60;
	const DEFAULT_CRAWL_DELAY = 1.0;

	public function get_http_user_agent() {
		return apply_filters(
			'conexao_event_importer_http_user_agent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
		);
	}

	public function get_id() {
		return self::SOURCE_KEY;
	}

	/**
	 * Fetch raw events from the Mondello Park REST endpoint.
	 *
	 * @return array[]
	 */
	public function fetch_events() {
		$base_url = isset( $this->config['url'] ) && $this->config['url']
			? $this->config['url']
			: self::DEFAULT_DISCOVERY_URL;

		$response = wp_remote_get( $base_url, array(
			'timeout'    => 30,
			'user-agent' => $this->get_http_user_agent(),
			'headers'    => array(
				'Accept-Language' => 'en-GB',
				'Accept'          => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			Conexao_Import_Log::add(
				$this->get_id(), 'error',
				'Falha ao buscar REST da Mondello Park: ' . $response->get_error_message(),
				array( 'url' => $base_url )
			);
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			Conexao_Import_Log::add(
				$this->get_id(), 'error',
				'Resposta HTTP ' . $code . ' ao buscar REST da Mondello Park.',
				array( 'url' => $base_url, 'http_status' => (int) $code )
			);
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return array();
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			Conexao_Import_Log::add(
				$this->get_id(), 'error',
				'Resposta REST da Mondello Park não é JSON válido.',
				array( 'url' => $base_url )
			);
			return array();
		}

		$events    = array();
		$seen_slugs = array();

		foreach ( $json as $item ) {
			$slug = isset( $item['slug'] ) ? (string) $item['slug'] : '';
			if ( '' === $slug ) {
				continue;
			}

			if ( isset( $seen_slugs[ $slug ] ) ) {
				Conexao_Import_Log::add(
					$this->get_id(), 'info',
					sprintf( 'Mondello Park REST duplicata ignorada (slug %s).', $slug ),
					array( 'url' => $base_url )
				);
				continue;
			}
			$seen_slugs[ $slug ] = true;

			$id        = isset( $item['id'] ) ? (int) $item['id'] : 0;
			$title_raw = isset( $item['title']['rendered'] )
				? (string) $item['title']['rendered']
				: (string) ( $item['title'] ?? '' );
			$title     = html_entity_decode( $title_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$title     = trim( $title );
			$link      = isset( $item['link'] ) ? (string) $item['link'] : '';
			$categories = isset( $item['event_category'] )
				? (array) $item['event_category']
				: array();

			if ( '' === $title ) {
				continue;
			}

			$raw = array(
				'source'                => self::SOURCE_KEY,
				'source_id'             => $slug,
				'wp_rest_id'            => $id ? (string) $id : '',
				'title'                 => $title,
				'url'                   => $link,
				'start_date'            => '',
				'start_time'            => '',
				'end_date'              => '',
				'end_time'              => '',
				'location'              => '',
				// Source-scoped county hint (audited source config: county
				// => 'Kildare'). Forwarded so events whose detail page only
				// surfaces the venue name ("Mondello Park") still receive a
				// valid county term. The normalizer applies this hint ONLY
				// when the location string does not already yield a county,
				// so any source-supplied county is preserved; an empty
				// config value forwards an empty hint and changes nothing.
				// County tag only — no address, town or venue is ever
				// invented from it.
				'county'                => isset( $this->config['county'] ) ? trim( (string) $this->config['county'] ) : '',
				'description'           => '',
				'image'                 => '',
				'_source_categories'    => $categories,
				'_ticket_show_ids'      => array(),
				'_detail_engaged'       => false,
				'_no_detail'            => false,
				'_redirect'             => '',
				'_parse_warnings'       => array(),
			);

			$events[] = $raw;
		}

		Conexao_Import_Log::add(
			$this->get_id(), 'info',
			sprintf(
				'Mondello Park REST: %d eventos descobertos, %d slugs únicos.',
				count( $json ), count( $seen_slugs )
			),
			array( 'url' => $base_url )
		);

		// Bounded detail-page enrichment (authoritative date/location/ticket
		// extraction) — same pattern as the IVVCC detail walk.
		$events = $this->fetch_event_details( $events );

		return $events;
	}

	/**
	 * Enrich raw events from detail pages.
	 *
	 * @param array[] $events Raw events.
	 * @return array[]
	 */
	public function fetch_event_details( $events ) {
		$max_detail = isset( $this->config['max_detail_pages'] )
			? max( 1, (int) $this->config['max_detail_pages'] )
			: self::MAX_DETAIL_PAGES;
		$crawl_delay = isset( $this->config['crawl_delay'] )
			? (float) $this->config['crawl_delay']
			: self::DEFAULT_CRAWL_DELAY;

		$count = 0;
		foreach ( $events as &$event ) {
			if ( $count >= $max_detail ) {
				$event['_parse_warnings'][] = 'Máximo de páginas de detalhe atingido; evento não enriquecido.';
				continue;
			}

			$slug = isset( $event['source_id'] ) ? (string) $event['source_id'] : '';
			if ( '' === $slug ) {
				continue;
			}

			$url = self::detail_url( $slug );
			if ( '' === $url ) {
				continue;
			}

			try {
				$html = $this->fetch_html( $url );
				if ( empty( $html ) ) {
					$event['_no_detail'] = true;
					$event['_parse_warnings'][] = sprintf( 'Página de detalhe vazia: %s', $url );
				} else {
					$this->enrich_from_html( $event, $html, $url );
				}
			} catch ( Conexao_Source_Fetch_Exception $e ) {
				$code = $e->getCode();
				if ( in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
					$event['_redirect'] = $url;
					$event['_parse_warnings'][] = sprintf(
						'Página de detalhe redireciona (%d): %s', $code, $url
					);
				} else {
					$event['_no_detail'] = true;
					$event['_parse_warnings'][] = sprintf(
						'Falha ao buscar detalhe (%d): %s', $code, $url
					);
				}
			} catch ( Exception $e ) {
				$event['_no_detail'] = true;
				$event['_parse_warnings'][] = sprintf(
					'Erro inesperado no detalhe: %s', $e->getMessage()
				);
			}

			$event['_detail_engaged'] = true;
			$count++;

			if ( $crawl_delay > 0 ) {
				usleep( (int) ( $crawl_delay * 1000000 ) );
			}
		}
		unset( $event );

		return $events;
	}

	/**
	 * Build detail URL for a slug.
	 *
	 * @param string $slug
	 * @return string
	 */
	public static function detail_url( $slug ) {
		return sprintf( self::DETAIL_URL_PATTERN, rawurlencode( $slug ) );
	}

	/**
	 * Enrich one event from detail-page HTML.
	 *
	 * @param array  $event
	 * @param string $html
	 * @param string $url
	 */
	protected function enrich_from_html( &$event, $html, $url ) {
		$event['url'] = $url; // canonical detail URL is authoritative

		$dom = $this->parse_html( $html );
		if ( ! $dom ) {
			$event['_parse_warnings'][] = 'HTML da página de detalhe inválido.';
			return;
		}

		$xpath = new DOMXPath( $dom );

		// --- Date paragraph (authoritative event date) ---
		$date_text = $this->extract_date_paragraph( $xpath );
		if ( '' !== $date_text ) {
			$date_text = self::clean_date_text( $date_text );
			$parsed    = self::parse_date_range( $date_text );

			if ( $parsed['valid'] ) {
				$event['start_date'] = $parsed['start'];
				$event['end_date']   = $parsed['end'];
			} elseif ( $parsed['tba'] ) {
				$event['_parse_warnings'][] = 'Data TBA/TBC na página de detalhe — evento sem data válida.';
			} else {
				$event['_parse_warnings'][] = sprintf(
					'Data inválida ou não reconhecida na página de detalhe: %s', $date_text
				);
			}
		} else {
			$event['_parse_warnings'][] = 'Nenhum parágrafo de data encontrado na página de detalhe.';
		}

		// --- Location paragraph ---
		$loc_text = $this->extract_location_paragraph( $xpath );
		if ( '' !== $loc_text ) {
			$event['location'] = self::clean_text( $loc_text );
		}

		// --- Book Now / ticket link ---
		$ticket_link = $this->extract_ticket_url( $xpath );
		if ( '' !== $ticket_link ) {
			$event['url']              = $ticket_link;
			$event['_ticket_show_ids'] = $this->extract_ticket_show_ids( $xpath, $ticket_link );
		}

		if ( ! empty( $event['_parse_warnings'] ) ) {
			Conexao_Import_Log::add(
				$this->get_id(), 'info',
				sprintf(
					'Mondello Park detalhe %s: %s',
					self::slug_for_log( $event ),
					implode( ' | ', $event['_parse_warnings'] )
				),
				array( 'url' => $url )
			);
		}
	}

	/**
	 * Extract date paragraph text.
	 *
	 * @param DOMXPath $xpath
	 * @return string
	 */
	protected function extract_date_paragraph( DOMXPath $xpath ) {
		$nodes = $xpath->query(
			'//p[contains(concat(" ", normalize-space(@class), " "), " event-date ")]' 
		);
		if ( $nodes && $nodes->length > 0 ) {
			return $this->extract_text( $nodes->item( 0 ) );
		}

		// Fallback: paragraph containing month names.
		$fallback = $xpath->query(
			'//p[contains(text(), ",") and (' .
			'contains(text(), "January") or contains(text(), "February") or ' .
			'contains(text(), "March") or contains(text(), "April") or ' .
			'contains(text(), "May") or contains(text(), "June") or ' .
			'contains(text(), "July") or contains(text(), "August") or ' .
			'contains(text(), "September") or contains(text(), "October") or ' .
			'contains(text(), "November") or contains(text(), "December")' .
			')]'
		);
		if ( $fallback && $fallback->length > 0 ) {
			return $this->extract_text( $fallback->item( 0 ) );
		}

		return '';
	}

	/**
	 * Extract location paragraph text.
	 *
	 * @param DOMXPath $xpath
	 * @return string
	 */
	protected function extract_location_paragraph( DOMXPath $xpath ) {
		$nodes = $xpath->query(
			'//p[contains(concat(" ", normalize-space(@class), " "), " event-location ")]' 
		);
		if ( $nodes && $nodes->length > 0 ) {
			return $this->extract_text( $nodes->item( 0 ) );
		}
		return '';
	}

	/**
	 * Extract primary ticket URL from detail page.
	 *
	 * Preference order: (1) `book-now` class hook; (2) Ticketsolve anchor
	 * labelled "Book Now"; (3) first Ticketsolve link in DOM order.
	 *
	 * @param DOMXPath $xpath
	 * @return string
	 */
	protected function extract_ticket_url( DOMXPath $xpath ) {
		$book_now = $xpath->query(
			'//a[contains(concat(" ", normalize-space(@class), " "), " book-now ")]' 
		);
		if ( $book_now && $book_now->length > 0 ) {
			$href = $book_now->item( 0 )->getAttribute( 'href' );
			if ( '' !== $href ) {
				return self::strip_utm( $href );
			}
		}

		$ts_links = $xpath->query(
			'//a[contains(@href, "ticketsolve.com/ticketbooth/shows/")]'
		);
		if ( $ts_links && $ts_links->length > 0 ) {
			// Prefer an explicitly labelled "Book Now" anchor: the live
			// Winter Bash page renders the secondary "DRIFT BASH TICKETS"
			// button before the primary "Book Now" button, so DOM order
			// alone selects the wrong show ID (verified 2026-09-12).
			for ( $i = 0; $i < $ts_links->length; $i++ ) {
				$node  = $ts_links->item( $i );
				$label = strtolower( trim( $this->extract_text( $node ) ) );
				if ( '' !== $label && false !== strpos( $label, 'book now' ) ) {
					$href = $node->getAttribute( 'href' );
					if ( '' !== $href ) {
						return self::strip_utm( $href );
					}
				}
			}

			// Fallback: first Ticketsolve link in DOM order.
			$href = $ts_links->item( 0 )->getAttribute( 'href' );
			if ( '' !== $href ) {
				return self::strip_utm( $href );
			}
		}

		return '';
	}

	/**
	 * Collect all Ticketsolve show IDs referenced by the detail page, primary
	 * first. Show IDs live in the URL path (/ticketbooth/shows/{id}), not in a
	 * showId= query param. Stored in raw['_ticket_show_ids'] for reference
	 * only — never in a URL field.
	 *
	 * @param DOMXPath $xpath
	 * @param string   $primary_url Already-selected primary ticket URL.
	 * @return string[]
	 */
	protected function extract_ticket_show_ids( DOMXPath $xpath, $primary_url ) {
		$ids = array();

		$primary_id = self::show_id_from_url( $primary_url );
		if ( '' !== $primary_id ) {
			$ids[] = $primary_id;
		}

		$ts_links = $xpath->query(
			'//a[contains(@href, "ticketsolve.com/ticketbooth/shows/")]'
		);
		if ( $ts_links ) {
			for ( $i = 0; $i < $ts_links->length; $i++ ) {
				$show_id = self::show_id_from_url( $ts_links->item( $i )->getAttribute( 'href' ) );
				if ( '' !== $show_id && ! in_array( $show_id, $ids, true ) ) {
					$ids[] = $show_id;
				}
			}
		}

		return $ids;
	}

	/**
	 * Extract the Ticketsolve show ID from a ticketbooth URL (path-based).
	 *
	 * @param string $url
	 * @return string Show ID, or '' when not a Ticketsolve show URL.
	 */
	public static function show_id_from_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		if ( preg_match( '#/ticketbooth/shows/(\d+)#', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Strip UTM params from a Ticketsolve URL.
	 *
	 * @param string $url
	 * @return string
	 */
	public static function strip_utm( $url ) {
		$parsed = parse_url( $url );
		if ( ! isset( $parsed['query'] ) ) {
			return $url;
		}
		parse_str( $parsed['query'], $params );
		$keep = array();
		foreach ( $params as $key => $value ) {
			if ( 0 !== strpos( $key, 'utm_' ) ) {
				$keep[ $key ] = $value;
			}
		}
		if ( isset( $params['showId'] ) ) {
			$keep['showId'] = $params['showId'];
		}
		$query = http_build_query( $keep, '', '&', PHP_QUERY_RFC3986 );
		$base  = ( isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '' )
			. ( isset( $parsed['host'] ) ? $parsed['host'] : '' )
			. ( isset( $parsed['path'] ) ? $parsed['path'] : '' );
		return '' === $query ? $base : $base . '?' . $query;
	}

	/**
	 * Convert Mondello Park REST category IDs to Conexão category names.
	 *
	 * @param int[] $category_ids
	 * @return string[]
	 */
	public static function map_categories( $category_ids ) {
		$mapping = array(
			23 => 'Car Racing',
			22 => 'Drifting',
			36 => 'IDS',
			35 => 'JDM',
			38 => 'Motorbike Racing',
			34 => 'Rally',
			18 => 'Retro/Historic',
			47 => 'Shows',
		);

		$mapped = array();
		$seen   = array();

		if ( ! empty( $category_ids ) && is_array( $category_ids ) ) {
			foreach ( $category_ids as $cid ) {
				$cid = (int) $cid;
				if ( isset( $mapping[ $cid ] ) ) {
					$name = $mapping[ $cid ];
					if ( ! isset( $seen[ $name ] ) ) {
						$mapped[]   = $name;
						$seen[ $name ] = true;
					}
				} else {
					Conexao_Import_Log::add(
						self::SOURCE_KEY, 'warning',
						sprintf( 'Categoria desconhecida (ID %d) — ignorada para mapeamento.', $cid ),
						array( 'source' => self::SOURCE_KEY )
					);
				}
			}
		}

		return $mapped;
	}

	/**
	 * Normalize category text from detail page.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function normalize_category_text( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$text = preg_replace( '/^(category|type|categoria|tema|área|área temática)\s*[: \-]+/i', '', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( ucwords( strtolower( $text ) ) );
	}

	/**
	 * Extract text from a DOMElement.
	 *
	 * @param DOMElement $node
	 * @param string     $selector Optional CSS selector (ignored; returns node text).
	 * @return string
	 */
	protected function extract_text( $node, $selector = '' ) {
		if ( ! $node ) {
			return '';
		}
		return self::clean_text( trim( $node->textContent ) );
	}

	/**
	 * Clean raw text.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function clean_text( $text ) {
		$text = trim( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Normalize date paragraph text.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function clean_date_text( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return $text;
		}
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Normalize Unicode dashes to ASCII hyphen.
		$text = str_replace(
			array( "\xC2\xAD", "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x88\x92", "\xE2\x80\x92", "\xE2\x80\x95" ),
			'-',
			$text
		);
		$text = preg_replace( '/\s*-\s*/', ' - ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Parse a date paragraph in US English month-day-year format.
	 *
	 * @param string $text
	 * @return array{valid: bool, tba: bool, start: string, end: string}
	 */
	public static function parse_date_range( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return array( 'valid' => false, 'tba' => false, 'start' => '', 'end' => '' );
		}

		if ( preg_match(
			'/\b(TBA|TBC|TBD|to[\s-]?be[\s-]?confirmed|to[\s-]?be[\s-]?advised|date[\s-]?to[\s-]?be[\s-]?announced)\b/i',
			$text
		) ) {
			return array( 'valid' => false, 'tba' => true, 'start' => '', 'end' => '' );
		}

		$parts = preg_split( '/\s*-\s*/', $text, 2 );
		$start = self::parse_us_month_day_year( $parts[0] );

		if ( false === $start ) {
			return array( 'valid' => false, 'tba' => false, 'start' => '', 'end' => '' );
		}

		if ( ! isset( $parts[1] ) || '' === trim( $parts[1] ) ) {
			return array( 'valid' => true, 'tba' => false, 'start' => $start, 'end' => $start );
		}

		$end = self::parse_us_month_day_year( $parts[1] );
		if ( false === $end ) {
			return array( 'valid' => false, 'tba' => false, 'start' => $start, 'end' => '' );
		}

		if ( $start === $end ) {
			return array( 'valid' => true, 'tba' => false, 'start' => $start, 'end' => $start );
		}

		return array( 'valid' => true, 'tba' => false, 'start' => $start, 'end' => $end );
	}

	/**
	 * Parse a single US English "Month DD, YYYY" date.
	 *
	 * @param string $text
	 * @return string|false
	 */
	public static function parse_us_month_day_year( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return false;
		}

		$text = preg_replace( '/\s*,\s*/', ', ', $text );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		// Require an explicit 4-digit year — reject bare "September 12" etc.
		if ( ! preg_match( '/\b(\d{4})\b/', $text ) ) {
			return false;
		}

		// Match: MonthName DD, YYYY  (with optional comma, single/double-digit day).
		if ( ! preg_match(
			'/^\s*(January|February|March|April|May|June|July|August|September|October|November|December|Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2})(?:,\s*)?(\d{4})\s*$/i',
			$text,
			$m
		) ) {
			return false;
		}

		$month_name = strtolower( $m[1] );
		$day        = (int) $m[2];
		$year       = (int) $m[3];

		$month = self::month_name_to_number( $month_name );
		if ( ! $month ) {
			return false;
		}

		// Validate with the ORIGINAL values (not strtotime's normalization).
		if ( ! checkdate( $month, $day, $year ) ) {
			return false;
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
	}

	/**
	 * Convert a month name (full or abbreviated) to a numeric month (1-12).
	 *
	 * @param string $name Lowercase month name.
	 * @return int|false
	 */
	protected static function month_name_to_number( $name ) {
		$map = array(
			'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
			'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
			'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
			'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4,
			'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
			'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
		);
		$name = strtolower( (string) $name );
		return isset( $map[ $name ] ) ? $map[ $name ] : false;
	}

	/**
	 * Human-readable slug for log messages.
	 *
	 * @param array $event
	 * @return string
	 */
	private static function slug_for_log( $event ) {
		$slug = isset( $event['source_id'] ) ? (string) $event['source_id'] : '';
		if ( '' === $slug ) {
			return isset( $event['title'] ) ? (string) $event['title'] : '(sem-slug)';
		}
		return $slug;
	}

	/**
	 * Get source metadata for documentation / admin display.
	 *
	 * @return array
	 */
	public static function get_metadata() {
		return array(
			'source'                 => self::SOURCE_KEY,
			'display_name'           => 'Mondello Park',
			'description'            => 'Ireland\'s National Motorsports Campus — motocross, car racing, drifting, motorbike racing, JDM, rally, retro/historic and shows.',
			'base_url'               => 'https://mondellopark.ie',
			'discovery_url'          => self::DEFAULT_DISCOVERY_URL,
			'detail_pattern'         => self::DETAIL_URL_PATTERN,
			'identity'               => 'source+source_id (slug primary, WP REST ID fallback)',
			'date_source'            => 'visible HTML text on detail page (US Month DD, YYYY)',
			'time_source'            => 'none (no machine-readable event time)',
			'status_source'          => 'Conexao_Event_Status (reuse; no new statuses)',
			'ticket_url'             => 'Ticketsolve primary Book Now link (utm stripped)',
			'category_map'           => array(
				23 => 'Car Racing',
				22 => 'Drifting',
				36 => 'IDS',
				35 => 'JDM',
				38 => 'Motorbike Racing',
				34 => 'Rally',
				18 => 'Retro/Historic',
				47 => 'Shows',
			),
			'default_county'         => 'Kildare',
			'inactive_by_default'    => true,
			'crawl_delay'            => self::DEFAULT_CRAWL_DELAY,
			'max_detail_pages'       => self::MAX_DETAIL_PAGES,
			'audited_unique_slugs'   => 29,
		);
	}
}
