<?php
/**
 * IVVCC (Irish Veteran & Vintage Car Club) event source handler.
 *
 * Fetches and parses upcoming events from:
 * https://www.ivvcc.ie/upcoming-events-calendar/
 *
 * The IVVCC site runs WordPress + Cloudflare + EventON 2.6.16. The WP REST
 * API is Cloudflare-blocked, so this adapter scrapes the public calendar
 * HTML with a normal browser User-Agent (inherited from Conexao_Source_Base)
 * and honors the site's `Crawl-delay: 10` robots signal between requests.
 *
 * Strategy (modelled on Conexao_Source_Heritage_Week):
 *  1. Fetch the calendar page and extract every EventON card
 *     (`eventon_list_event` with `data-event_id` + `data-time` unix range).
 *  2. Collapse duplicate cards by EventON numeric ID.
 *  3. Enrich each event from its canonical detail page
 *     (`https://www.ivvcc.ie/events/{slug}/`, bounded + polite).
 *  4. Normalize into the shared raw event array consumed by the central
 *     importer (normalizer -> date filter -> deduplicator -> upsert).
 *
 * Date authority: ONLY EventON `data-time` unix timestamps converted with
 * the Europe/Dublin timezone. EventON JSON-LD dates are malformed
 * (e.g. "2026-9-19T19-19-00-00") and are NEVER parsed for dates.
 *
 * A failure on one event never stops the rest of the import.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Source_Ivvcc extends Conexao_Source_Base {

	const DEFAULT_URL        = 'https://www.ivvcc.ie/upcoming-events-calendar/';
	const MAX_DETAIL_PAGES   = 40;
	const DEFAULT_CRAWL_DELAY = 10; // Seconds between HTTP requests (robots.txt Crawl-delay).

	/**
	 * Known placeholder times emitted by EventON instead of real times.
	 * Events carrying these are imported as date-only (all-day semantics).
	 *
	 * @var string[]
	 */
	const PLACEHOLDER_TIMES = array( '07:39' );

	/**
	 * EventON fallback end-times that are not real data. The end time is
	 * dropped (start-only) when the source end matches one of these.
	 *
	 * @var string[]
	 */
	const FALLBACK_END_TIMES = array( '23:50' );

	/**
	 * Get the source slug.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'ivvcc';
	}

	/**
	 * Fetch raw upcoming events from the IVVCC calendar.
	 *
	 * Calendar fetch -> card extraction -> duplicate collapse -> bounded,
	 * polite detail enrichment. A failure on one event never stops the rest.
	 *
	 * @return array[]
	 */
	public function fetch_events() {
		$base_url = isset( $this->config['url'] ) && $this->config['url'] ? $this->config['url'] : self::DEFAULT_URL;

		$html = $this->fetch_html( $base_url );
		if ( empty( $html ) ) {
			return array();
		}

		$cards = self::parse_calendar_events( $html, $base_url );

		// Collapse duplicate cards by stable EventON numeric ID. The
		// calendar renders some events twice (month grid + list).
		$seen   = array();
		$unique = array();
		foreach ( $cards as $card ) {
			$sid = isset( $card['source_id'] ) ? (string) $card['source_id'] : '';
			if ( '' !== $sid && isset( $seen[ $sid ] ) ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'info',
					sprintf( 'IVVCC duplicate calendar card ignored (EventON ID %s).', $sid ),
					array( 'url' => $base_url )
				);
				continue;
			}
			$seen[ $sid ] = true;
			$unique[]     = $card;
		}

		$max_detail = isset( $this->config['max_detail_pages'] ) ? (int) $this->config['max_detail_pages'] : self::MAX_DETAIL_PAGES;
		$delay      = isset( $this->config['crawl_delay'] ) ? (int) $this->config['crawl_delay'] : self::DEFAULT_CRAWL_DELAY;

		$detail_fetched = 0;
		foreach ( $unique as &$event ) {
			if ( ! empty( $event['_skip'] ) || empty( $event['url'] ) ) {
				continue;
			}
			if ( $detail_fetched >= $max_detail ) {
				break;
			}
			if ( $detail_fetched > 0 && $delay > 0 ) {
				sleep( $delay );
			}
			$detail_html = $this->fetch_html_or_empty( $event['url'] );
			$detail_fetched++;
			if ( '' === $detail_html ) {
				continue;
			}
			try {
				$detail = self::parse_detail_page( $detail_html, $event['url'], isset( $event['source_id'] ) ? $event['source_id'] : '' );
				$event  = self::merge_detail( $event, $detail );
			} catch ( Exception $e ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'warning',
					sprintf( 'IVVCC detail page could not be parsed for "%s": %s', isset( $event['title'] ) ? $event['title'] : $event['url'], $e->getMessage() ),
					array( 'url' => $event['url'] )
				);
			}
		}
		unset( $event );

		$events = array();
		foreach ( $unique as $event ) {
			if ( ! empty( $event['_warnings'] ) && is_array( $event['_warnings'] ) ) {
				foreach ( $event['_warnings'] as $warning ) {
					Conexao_Import_Log::add(
						$this->get_id(),
						'warning',
						sprintf( 'IVVCC %s — %s', isset( $event['source_id'] ) ? $event['source_id'] : '?', $warning ),
						array( 'url' => isset( $event['url'] ) ? $event['url'] : '' )
					);
				}
			}
			if ( ! empty( $event['_skip'] ) ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'warning',
					sprintf(
						'SKIP: date not firm — "%s" (%s).',
						isset( $event['title'] ) ? $event['title'] : '(untitled)',
						isset( $event['_skip_reason'] ) ? $event['_skip_reason'] : ''
					),
					array( 'url' => isset( $event['url'] ) ? $event['url'] : '' )
				);
				continue;
			}
			unset( $event['_skip'], $event['_skip_reason'], $event['_warnings'] );
			$events[] = $event;
		}

		return $events;
	}
	/**
	/**
	 * Extract ICS cross-check params from a card (secondary verification).
	 *
	 * @param DOMElement $card Card node.
	 * @param DOMXPath $xpath XPath.
	 * @return array
	 */
	public static function extract_ics_params( $card, $xpath ) {
		$out = array( 'event_id' => '', 'sunix' => '', 'eunix' => '' );
		$links = $xpath->query( './/a[contains(@href, "eventon_ics_download")]', $card );
		if ( ! $links || 0 === $links->length ) {
			return $out;
		}
		$href = html_entity_decode( $links->item( 0 )->getAttribute( 'href' ) );
		foreach ( array( 'event_id', 'sunix', 'eunix' ) as $key ) {
			if ( preg_match( '/[?&]' . $key . '=([^&"\']+)/', $href, $mm ) ) {
				$out[ $key ] = $mm[1];
			}
		}
		return $out;
	}

	/**
	 * Blank raw event skeleton.
	 *
	 * @return array
	 */
	public static function blank_event() {
		return array(
			'source' => 'ivvcc', 'title' => '', 'url' => '',
			'start_date' => '', 'start_time' => '', 'end_date' => '',
			'end_time' => '', 'location' => '', 'description' => '',
			'image' => '', 'source_id' => '', 'venue' => '',
			'organizer' => '', 'price' => '', '_warnings' => array(),
		);
	}

	/**
	 * Clean visible text (entities, CF email, whitespace).
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( '[email protected]', '[email protected]' ), '', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/**
	 * Whether an image URL is site chrome (never an event image).
	 *
	 * @param string $src Raw src.
	 * @return bool
	 */
	public static function is_chrome_image( $src ) {
		$low = strtolower( (string) $src );
		foreach ( array( 'fiva', 'logo', 'header', 'footer', 'banner-ads', 'cookie', 'sprite', 'icon', 'blank', 'placeholder', 'data:image' ) as $needle ) {
			if ( false !== strpos( $low, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * DOM parse helper (static, no WP).
	 *
	 * @param string $html HTML.
	 * @return DOMDocument|false
	 */
	public static function parse_html_static( $html ) {
		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$ok = $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		return $ok ? $dom : false;
	}

	/**
	 * Resolve URL helper (static, no WP dependency).
	 *
	 * @param string $url Raw URL.
	 * @param string $base Base URL.
	 * @return string
	 */
	public static function resolve_url_static( $url, $base ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) ) {
			return $url;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return 'https:' . $url;
		}
		$parts = parse_url( $base );
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host = isset( $parts['host'] ) ? $parts['host'] : 'www.ivvcc.ie';
		if ( 0 === strpos( $url, '/' ) ) {
			return $scheme . '://' . $host . $url;
		}
		return rtrim( $base, '/' ) . '/' . ltrim( $url, '/' );
	}

	/**
	 * Map an EventON unix range to importer date/time fields.
	 *
	 * EventON on ivvcc.ie stores wall-clock times encoded as UTC: the
	 * rendered cards and the ICS export show the timestamp's UTC wall time
	 * verbatim (e.g. unix -> T100000Z renders as "10:00 am"). The UTC wall
	 * time is therefore the source's usable local time; converting with an
	 * offset timezone would shift every time by one hour (audit mismatch).
	 * Multi-day supported natively. Placeholder start times (07:39) and the
	 * EventON 23:50 end-of-day fallback are dropped, never invented.
	 *
	 * @param int $start_unix Start unix.
	 * @param int $end_unix End unix.
	 * @return array
	 */
	public static function map_unix_range( $start_unix, $end_unix ) {
		$out = array(
			'start_date' => '', 'start_time' => '',
			'end_date' => '', 'end_time' => '', 'warning' => '',
		);
		if ( $start_unix <= 0 ) {
			return $out;
		}
		// UTC wall time == the time the source itself renders.
		$start = new DateTimeImmutable( '@' . (int) $start_unix );
		$out['start_date'] = $start->format( 'Y-m-d' );
		$stime = $start->format( 'H:i' );
		if ( in_array( $stime, self::PLACEHOLDER_TIMES, true ) ) {
			$out['warning'] = 'placeholder time ' . $stime . ' dropped (all-day)';
		} else {
			$out['start_time'] = $stime;
		}
		if ( $end_unix <= 0 || $end_unix === $start_unix ) {
			return $out;
		}
		if ( $end_unix < $start_unix ) {
			$out['warning'] = trim( $out['warning'] . '; end before start ignored' );
			return $out;
		}
		// Exactly 24h between identical wall times carries no usable time
		// information (e.g. Kingdom "01:00-01:00"): keep the date span,
		// drop the times (all-day semantics).
		$end = new DateTimeImmutable( '@' . (int) $end_unix );
		if ( ( $end_unix - $start_unix ) >= 86400
			&& $start->format( 'H:i' ) === $end->format( 'H:i' )
			&& 0 === ( ( $end_unix - $start_unix ) % 86400 )
		) {
			$out['end_date'] = $end->format( 'Y-m-d' );
			$out['start_time'] = '';
			if ( '' !== $out['warning'] ) {
				$out['warning'] .= '; ';
			}
			$out['warning'] .= 'identical 24h-apart wall times dropped (all-day span)';
			return $out;
		}
		$etime = $end->format( 'H:i' );
		if ( in_array( $etime, self::FALLBACK_END_TIMES, true ) ) {
			if ( '' !== $out['warning'] ) {
				$out['warning'] .= '; ';
			}
			$out['warning'] .= 'fallback end ' . $etime . ' dropped (no end invented)';
			if ( $end->format( 'Y-m-d' ) !== $out['start_date'] ) {
				$out['end_date'] = $end->format( 'Y-m-d' );
			}
			return $out;
		}
		if ( $end->format( 'Y-m-d' ) !== $out['start_date'] ) {
			$out['end_date'] = $end->format( 'Y-m-d' );
			$out['end_time'] = $etime;
			return $out;
		}
		$out['end_time'] = $etime;
		return $out;
	}

	/**
	 * Merge detail enrichment into a calendar event.
	 *
	 * Calendar data-time stays authoritative; detail fills gaps only.
	 *
	 * @param array $event Calendar event.
	 * @param array $detail Detail parse.
	 * @return array
	 */
	public static function merge_detail( $event, $detail ) {
		if ( '' !== $detail['source_id'] ) {
			$event['source_id'] = $detail['source_id'];
		}
		if ( '' === $event['title'] && '' !== $detail['title'] ) {
			$event['title'] = $detail['title'];
		}
		if ( '' !== $detail['subtitle'] ) {
			if ( '' === $event['description'] ) {
				$event['description'] = $detail['subtitle'];
			} elseif ( false === strpos( $event['description'], $detail['subtitle'] ) ) {
				$event['description'] .= "\n\n" . $detail['subtitle'];
			}
		}
		if ( '' === $event['location'] && '' !== $detail['location'] ) {
			$event['location'] = $detail['location'];
		}
		if ( '' === $event['organizer'] && '' !== $detail['organizer'] ) {
			$event['organizer'] = $detail['organizer'];
		}
		if ( '' === $event['price'] && '' !== $detail['price'] ) {
			$event['price'] = $detail['price'];
		}
		if ( '' === $event['image'] && '' !== $detail['image'] ) {
			$event['image'] = $detail['image'];
		}
		if ( ! empty( $detail['_warnings'] ) ) {
			$event['_warnings'] = array_merge( $event['_warnings'], $detail['_warnings'] );
		}
		return $event;
	}

	/**
	 * Parse a detail page (pure/static). JSON-LD dates never used.
	 *
	 * @param string $html Detail HTML.
	 * @param string $url Detail URL.
	 * @param string $source_id EventON ID fallback.
	 * @return array
	 */
	public static function parse_detail_page( $html, $url = '', $source_id = '' ) {
		$detail = array(
			'title' => '', 'subtitle' => '', 'description' => '',
			'location' => '', 'organizer' => '', 'price' => '',
			'image' => '', 'source_id' => (string) $source_id,
			'data_time' => '', '_warnings' => array(),
		);
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return $detail;
		}
		$dom = self::parse_html_static( $html );
		if ( ! $dom ) {
			return $detail;
		}
		$xpath = new DOMXPath( $dom );
		$cards = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " eventon_list_event ")]' );
		$ctx = ( $cards && $cards->length > 0 ) ? $cards->item( 0 ) : $dom->documentElement;
		$sid = '';
		if ( $ctx instanceof DOMElement ) {
			$sid = trim( $ctx->getAttribute( 'data-event_id' ) );
		}
		if ( '' !== $sid && preg_match( '/^\d+$/', $sid ) ) {
			$detail['source_id'] = $sid;
		}
		if ( $ctx instanceof DOMElement ) {
			$dt = trim( $ctx->getAttribute( 'data-time' ) );
			if ( preg_match( '/^\d+-\d+$/', $dt ) ) {
				$detail['data_time'] = $dt;
			}
		}
		$t = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " evcal_event_title ")]', $ctx );
		if ( $t && $t->length > 0 ) {
			$detail['title'] = self::clean_text( $t->item( 0 )->textContent );
		}
		$s = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " evcal_event_subtitle ")]', $ctx );
		if ( $s && $s->length > 0 ) {
			$detail['subtitle'] = self::clean_text( $s->item( 0 )->textContent );
		}
		$l = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " evo_location_name ")]', $ctx );
		if ( $l && $l->length > 0 ) {
			$detail['location'] = self::clean_text( $l->item( 0 )->textContent );
		}
		$o = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " evo_card_organizer_name_t ")]', $ctx );
		if ( $o && $o->length > 0 ) {
			$detail['organizer'] = self::clean_text( $o->item( 0 )->textContent );
		}
		$hay = $detail['subtitle'];
		if ( '' !== $hay && preg_match( '/€\s?\d[\d.,]*/u', $hay, $pm ) ) {
			$detail['price'] = trim( $pm[0] );
		}
		$imgs = $xpath->query( './/img[@src]', $ctx );
		if ( $imgs ) {
			foreach ( $imgs as $img ) {
				$src = trim( $img->getAttribute( 'src' ) );
				if ( '' === $src ) {
					continue;
				}
				if ( self::is_chrome_image( $src ) ) {
					continue;
				}
				$abs = self::resolve_url_static( $src, $url );
				if ( preg_match( '/\.(jpe?g|png|gif|webp)(\?|$)/i', $abs ) && false === strpos( $abs, 'ivvcc.ie/events/' ) ) {
					$detail['image'] = $abs;
					break;
				}
			}
		}
		return $detail;
	}

	/**
	 * Parse one EventON calendar card (pure/static).
	 *
	 * Priority: data-event_id, data-time, card fields, microdata.
	 * JSON-LD dates are never read.
	 *
	 * @param DOMElement $card Card node.
	 * @param DOMXPath $xpath XPath.
	 * @param string $base_url Base URL.
	 * @return array
	 */
	public static function parse_card( $card, $xpath, $base_url ) {
		$event = self::blank_event();
		$sid = trim( $card->getAttribute( 'data-event_id' ) );
		if ( '' !== $sid && preg_match( '/^\d+$/', $sid ) ) {
			$event['source_id'] = $sid;
		}
		$title = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " evcal_event_title ")]', $card );
		if ( $title && $title->length > 0 ) {
			$event['title'] = self::clean_text( $title->item( 0 )->textContent );
		}
		if ( '' === $event['title'] ) {
			$nm = $xpath->query( './/*[@itemprop="name"]', $card );
			if ( $nm && $nm->length > 0 ) {
				$event['title'] = self::clean_text( $nm->item( 0 )->textContent );
			}
		}
		$un = $xpath->query( './/*[@itemprop="url"]', $card );
		if ( $un && $un->length > 0 ) {
			$href = $un->item( 0 )->getAttribute( 'href' );
			if ( '' !== $href ) {
				$event['url'] = self::resolve_url_static( $href, $base_url );
			}
		}
		if ( '' === $event['url'] || false !== strpos( $event['url'], 'upcoming-events-calendar' ) ) {
			$links = $xpath->query( './/a[@href]', $card );
			if ( $links ) {
				foreach ( $links as $link ) {
					$href = $link->getAttribute( 'href' );
					if ( preg_match( '#/events/[^/]+/?$#', $href ) ) {
						$event['url'] = self::resolve_url_static( $href, $base_url );
						break;
					}
				}
			}
		}
		$dt = trim( $card->getAttribute( 'data-time' ) );
		if ( preg_match( '/^(\d+)-(\d+)$/', $dt, $mm ) ) {
			$mapped = self::map_unix_range( (int) $mm[1], (int) $mm[2] );
			$event['start_date'] = $mapped['start_date'];
			$event['start_time'] = $mapped['start_time'];
			$event['end_date'] = $mapped['end_date'];
			$event['end_time'] = $mapped['end_time'];
			if ( '' !== $mapped['warning'] ) {
				$event['_warnings'][] = $mapped['warning'];
			}
		}
		$sub = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " evcal_event_subtitle ")]', $card );
		if ( $sub && $sub->length > 0 ) {
			$st = self::clean_text( $sub->item( 0 )->textContent );
			if ( '' !== $st ) {
				$event['description'] = $st;
			}
		}
		$loc = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " evo_location_name ")]', $card );
		if ( $loc && $loc->length > 0 ) {
			$event['location'] = self::clean_text( $loc->item( 0 )->textContent );
		}
		$org = $xpath->query( './/*[contains(concat(" ", normalize-space(@class), " "), " evo_card_organizer_name_t ")]', $card );
		if ( $org && $org->length > 0 ) {
			$event['organizer'] = self::clean_text( $org->item( 0 )->textContent );
		}
		if ( '' !== $event['description'] && preg_match( '/€\s?\d[\d.,]*/u', $event['description'], $pm ) ) {
			$event['price'] = trim( $pm[0] );
		}
		if ( '' !== $event['organizer'] && preg_match( '/eventbrite/i', $event['organizer'] ) ) {
			$event['description'] = trim( $event['description'] . "\n\nRegistration: " . $event['organizer'] );
		}
		$hay = $event['title'] . ' ' . $event['description'] . ' ' . $event['location'];
		if ( preg_match( '/date\s+to\s+be\s+advised|\bTBA\b|to\s+be\s+confirmed/i', $hay ) ) {
			$event['_skip'] = true;
			$event['_skip_reason'] = 'TBA / date to be advised';
		}
		return $event;
	}

	/**
	 * Parse calendar cards (pure/static, fixture-safe).
	 *
	 * @param string $html Calendar HTML.
	 * @param string $base_url Base URL.
	 * @return array[]
	 */
	public static function parse_calendar_events( $html, $base_url = self::DEFAULT_URL ) {
		$events = array();
		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return $events;
		}
		$dom = self::parse_html_static( $html );
		if ( ! $dom ) {
			return $events;
		}
		$xpath = new DOMXPath( $dom );
		$cards = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " eventon_list_event ")]' );
		if ( ! $cards || 0 === $cards->length ) {
			return $events;
		}
		foreach ( $cards as $card ) {
			$event = self::parse_card( $card, $xpath, $base_url );
			if ( empty( $event['source_id'] ) || empty( $event['title'] ) ) {
				continue;
			}
			$events[] = $event;
		}
		return $events;
	}

// __IVVCC_PART2__
}