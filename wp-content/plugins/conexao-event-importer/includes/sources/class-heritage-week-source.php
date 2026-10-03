<?php
/**
 * National Heritage Week county source handler.
 *
 * Fetches and parses events from the Heritage Week website for a configured
 * county. The handler is configuration-driven: county and `where[]` filter
 * values are read from the source config.
 *
 * One implementation backs many county sources (e.g. heritage_week_laois,
 * heritage_week_cork, heritage_week_dublin). Multiple `where[]` values are
 * walked within a single source registration (relevant for Galway, Dublin).
 *
 * Legacy fallback: when config['id'] is missing, returns 'heritage_week' for
 * backward compatibility with the existing Laois source record.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Source_Heritage_Week extends Conexao_Source_Base {

	const DEFAULT_URL = 'https://www.heritageweek.ie/event-listings?q=&where%5B%5D=laois';
	const MAX_PAGES   = 50;

	/**
	 * Soft time budget (seconds) for the whole source walk.
	 *
	 * Recommended by Stage A audit (§13.6) to prevent unbounded detail-page
	 * enrichment from stalling large-county imports.
	 *
	 * @var int
	 */
	const TIME_BUDGET_SECONDS = 120;

	/**
	 * Whether the last pagination step rejected a forward link.
	 *
	 * True when the listing still advertised a forward destination that
	 * resolved to the current page or to an already-visited one. The walk then
	 * stopped WITHOUT reaching the end of the source, which is an incomplete
	 * enumeration rather than a legitimately finished one.
	 *
	 * @var bool
	 */
	protected $forward_link_blocked = false;

	/**
	 * Get the source ID.
	 *
	 * Returns the configured source ID so each county source has an independent
	 * identity (dedup/log/health). Falls back to 'heritage_week' only when the
	 * config ID is missing (legacy compatibility).
	 *
	 * @return string
	 */
	public function get_id() {
		if ( ! empty( $this->config['id'] ) ) {
			return $this->config['id'];
		}
		return 'heritage_week';
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
	 * Get the soft time budget (seconds) for one source walk.
	 *
	 * Filterable so a test can drive the truncation branch deterministically.
	 * The default is the class constant, so production behaviour is unchanged.
	 *
	 * @return float Seconds.
	 */
	protected function get_time_budget_seconds() {
		return (float) apply_filters(
			'conexao_heritage_week_time_budget_seconds',
			self::TIME_BUDGET_SECONDS
		);
	}

	/**
	 * Get the configured Heritage Week where[] filter values.
	 *
	 * @return array
	 */
	protected function get_hw_where() {
		if ( ! empty( $this->config['hw_where'] ) && is_array( $this->config['hw_where'] ) ) {
			return $this->config['hw_where'];
		}
		// Fallback: single where[] from the URL.
		return array( 'laois' );
	}

	/**
	 * Build a Heritage Week listing URL for a given where[] value.
	 *
	 * @param string $where The where[] filter value.
	 * @return string
	 */
	protected function build_hw_url( $where ) {
		return 'https://www.heritageweek.ie/event-listings?q=&where%5B%5D=' . urlencode( $where );
	}

	/**
	 * Fetch raw event listings from the Heritage Week county listing.
	 *
	 * Walks all configured where[] filter values and all pagination pages,
	 * and enriches each event from its detail page. A failure on one event
	 * never stops the rest of the import.
	 *
	 * @return array[]
	 * @throws Conexao_Source_Fetch_Exception When the first listing page cannot be
	 *                                        fetched: an unenumerable source is a
	 *                                        failed source, never an empty one.
	 */
	public function fetch_events() {
		$hw_where = $this->get_hw_where();

		$all_events = array();
		$seen_ids   = array();
		$events     = array();
		$started_at = microtime( true );
		$budget     = $this->get_time_budget_seconds();

		// URLs already fetched during THIS run. Pagination must never revisit
		// one: the Heritage Week nav exposes "Previous" before "Next" on
		// page 2+, so a naive "first anchor" walk ping-pongs p1 <-> p2 for
		// MAX_PAGES iterations (Stage G2A).
		$visited          = array();
		$termination_note = '';

		$this->fetch_complete = true;

		foreach ( $hw_where as $where ) {
			$base_url = $this->build_hw_url( $where );

			// Fetch the first page with the STRICT fetcher so an unreachable
			// listing becomes a failed source, never an empty one. Swallowing
			// this failure is what produced `found=0, status=success` rows for
			// 22 of 26 Heritage Week sources (Stage G1).
			try {
				$first_html = $this->fetch_html( $base_url );
			} catch ( Conexao_Source_Fetch_Exception $e ) {
				throw new Conexao_Source_Fetch_Exception(
					esc_html(
						sprintf(
							'Failed to fetch Heritage Week listing for where[]=%s: %s',
							esc_html( $where ),
							esc_html( $e->getMessage() )
						)
					),
					(int) esc_html( (string) $e->get_http_status() )
				);
			}

			$year = $this->detect_year( $first_html );

			$page_url = $base_url;
			$page     = 1;
			$html     = $first_html;

			$visited[ $this->normalize_pagination_url( $page_url ) ] = true;

			while ( $html && $page <= self::MAX_PAGES ) {
				// Time budget check.
				if ( ( microtime( true ) - $started_at ) > $budget ) {
					$this->mark_fetch_incomplete();
					$termination_note = sprintf( 'time budget of %ds expired (where[]=%s page %d)', (int) $budget, $where, $page - 1 );
					Conexao_Import_Log::add(
						$this->get_id(),
						'warning',
						sprintf(
							/* translators: 1: where[] value, 2: last completed page */
							__( 'Heritage Week import reached its time budget after where[]=%1$s page %2$d — remaining will be picked up by the next run.', 'conexao-event-importer' ),
							$where,
							$page - 1
						),
						array( 'run_id' => $this->get_current_run_id() )
					);
					break 2; // Exit both the while and foreach loops.
				}

				$dom = $this->parse_html( $html );
				if ( ! $dom ) {
					$this->mark_fetch_incomplete();
					$termination_note = sprintf( 'page %d could not be parsed (where[]=%s)', $page, $where );
					Conexao_Import_Log::add(
						$this->get_id(),
						'error',
						sprintf( 'Não foi possível analisar o HTML da página de eventos do Heritage Week (where[]=%s, page %d).', $where, $page ),
						array(
							'url'    => $page_url,
							'run_id' => $this->get_current_run_id(),
						)
					);
					break;
				}

				$xpath = new DOMXPath( $dom );
				$cards = $xpath->query( '//article[contains(concat(" ", normalize-space(@class), " "), " item-summary ")]' );

				if ( $cards && $cards->length > 0 ) {
					foreach ( $cards as $card ) {
						// Time budget check (per event for detail enrichment).
						if ( ( microtime( true ) - $started_at ) > $budget ) {
							$this->mark_fetch_incomplete();
							$termination_note = sprintf( 'time budget of %ds expired during detail enrichment (where[]=%s)', (int) $budget, $where );
							break 3; // Exit all loops.
						}

						try {
							$event = $this->extract_listing_event( $card, $xpath, $page_url, $year );
							if ( empty( $event['title'] ) || empty( $event['url'] ) ) {
								continue;
							}
							$event    = $this->enrich_event( $event );
							$events[] = $event;
						} catch ( Exception $e ) {
							Conexao_Import_Log::add(
								$this->get_id(),
								'error',
								'Erro ao processar evento do Heritage Week: ' . $e->getMessage(),
								array( 'run_id' => $this->get_current_run_id() )
							);
						}
					}
				}

				$next = $this->get_next_page_url( $xpath, $page_url, $visited );
				if ( ! $next ) {
					// No forward destination left. If the listing still
					// advertised one that resolved to the current or an already
					// visited page, the enumeration did NOT reach the end of the
					// source, so it must not be reported as complete.
					if ( $this->forward_link_blocked ) {
						$this->mark_fetch_incomplete();
						$termination_note = sprintf( 'the forward pagination link resolved to an already-visited page (where[]=%s page %d)', $where, $page );
						Conexao_Import_Log::add(
							$this->get_id(),
							'warning',
							sprintf( 'Heritage Week pagination stopped: the next link pointed at an already-visited page (%s).', $page_url ),
							array(
								'url'    => $page_url,
								'run_id' => $this->get_current_run_id(),
							)
						);
					}
					break;
				}

				if ( isset( $visited[ $this->normalize_pagination_url( $next ) ] ) ) {
					// A "next" destination already fetched in this run. The walk
					// cannot progress, so stop rather than loop.
					$this->mark_fetch_incomplete();
					$termination_note = sprintf( 'pagination pointed at an already-visited URL (%s)', $next );
					Conexao_Import_Log::add(
						$this->get_id(),
						'warning',
						sprintf( 'Heritage Week pagination stopped: the next link resolved to an already-visited URL (%s).', $next ),
						array(
							'url'    => $next,
							'run_id' => $this->get_current_run_id(),
						)
					);
					break;
				}

				++$page;
				$page_url = $next;
				$visited[ $this->normalize_pagination_url( $next ) ] = true;
				$html = $this->fetch_html_or_empty( $page_url );

				if ( ! $html ) {
					// Later-page tolerance is preserved (keep what we already
					// collected), but the enumeration is now incomplete.
					$this->mark_fetch_incomplete();
					$termination_note = sprintf( 'page %d could not be fetched (%s)', $page, $page_url );
					break;
				}
			}

			// The while loop can also exit on the page limit while the listing
			// still advertises more pages.
			if ( $this->fetch_complete && $page > self::MAX_PAGES ) {
				$this->mark_fetch_incomplete();
				$termination_note = sprintf( 'page limit of %d reached (where[]=%s)', self::MAX_PAGES, $where );
			}
		}

		if ( ! $this->fetch_complete ) {
			Conexao_Import_Log::add(
				$this->get_id(),
				'warning',
				sprintf(
					'Heritage Week enumeration is INCOMPLETE (%s). Events absent from this walk will not be marked source_not_found.',
					$termination_note
				),
				array( 'run_id' => $this->get_current_run_id() )
			);
		}

		// Deduplicate across where[] values by source_id.
		foreach ( $events as $event ) {
			$id = isset( $event['source_id'] ) ? (string) $event['source_id'] : '';
			if ( empty( $id ) ) {
				$all_events[] = $event;
				continue;
			}
			if ( isset( $seen_ids[ $id ] ) ) {
				continue;
			}
			$seen_ids[ $id ] = true;
			$all_events[]    = $event;
		}

		return $all_events;
	}

	/**
	 * Detect the event year from the page title (e.g. "2026 Events").
	 *
	 * @param string $html Page HTML.
	 * @return string 4-digit year.
	 */
	protected function detect_year( $html ) {
		if ( preg_match( '/<title>([^<]*)<\/title>/i', $html, $m ) ) {
			$title = $m[1];
			if ( preg_match( '/(\d{4})\s+Events/i', $title, $ym ) ) {
				return $ym[1];
			}
			if ( preg_match( '/(\d{4})/', $title, $ym ) ) {
				return $ym[1];
			}
		}
		return date( 'Y' );
	}

	/**
	 * Extract a single event from a listing card.
	 *
	 * @param DOMElement $card  Event card node.
	 * @param DOMXPath   $xpath XPath object.
	 * @param string     $base  Base URL.
	 * @param string     $year  Event year.
	 * @return array
	 */
	protected function extract_listing_event( $card, $xpath, $base, $year ) {
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
			'organizer'   => '',
			'category'    => ! empty( $this->config['category'] ) ? $this->config['category'] : 'Heritage',
			'county'      => $this->get_county(),
		);

		// Native Heritage Week event ID (strong dedup identifier).
		$data_id = $card->getAttribute( 'data-id' );
		if ( $data_id ) {
			$event['source_id'] = trim( $data_id );
		}

		// Title.
		$title_node = $xpath->query( './/h3[contains(concat(" ", normalize-space(@class), " "), " title ")]', $card );
		if ( $title_node && $title_node->length > 0 ) {
			$event['title'] = trim( $title_node->item( 0 )->textContent );
		}

		// Original event URL.
		$link = $xpath->query( './/a[contains(concat(" ", normalize-space(@class), " "), " link-block ")]', $card );
		if ( $link && $link->length > 0 ) {
			$href = $link->item( 0 )->getAttribute( 'href' );
			if ( $href ) {
				$event['url'] = $this->resolve_url( $href, $base );
			}
		}

		// Image: collect all candidate URLs and pick the best one.
		// Heritage Week listing cards may have:
		// - background-image on figure.portrait (typically 740x400)
		// - img src (typically 370x200)
		// - img srcset with multiple resolutions
		// - data-src or data-original for lazy-loaded images
		// We collect all candidates and let the image handler pick the best.
		$image_candidates = array();

		// 1. Background-image from figure.portrait (usually highest quality on listing).
		$figure = $xpath->query( './/figure[contains(concat(" ", normalize-space(@class), " "), " portrait ")]', $card );
		if ( $figure && $figure->length > 0 ) {
			$style = $figure->item( 0 )->getAttribute( 'style' );
			if ( preg_match( '/background-image:\s*url\(([^)]+)\)/i', $style, $m ) ) {
				$candidate = $this->resolve_url( trim( $m[1], '"\'' ), $base );
				if ( strpos( $candidate, '/logos/' ) === false ) {
					$image_candidates[] = $candidate;
				}
			}
		}

		// 2. All img elements in the card — check src, srcset, data-src, data-original.
		$imgs = $xpath->query( './/img', $card );
		if ( $imgs ) {
			foreach ( $imgs as $img_node ) {
				// src attribute.
				$src = $img_node->getAttribute( 'src' );
				if ( $src ) {
					$candidate = $this->resolve_url( $src, $base );
					if ( strpos( $candidate, '/logos/' ) === false ) {
						$image_candidates[] = $candidate;
					}
				}

				// srcset attribute — may contain higher resolution variants.
				$srcset = $img_node->getAttribute( 'srcset' );
				if ( $srcset ) {
					foreach ( $this->extract_srcset_urls( $srcset ) as $srcset_url ) {
						$candidate = $this->resolve_url( $srcset_url, $base );
						if ( strpos( $candidate, '/logos/' ) === false ) {
							$image_candidates[] = $candidate;
						}
					}
				}

				// data-src (lazy loading).
				$data_src = $img_node->getAttribute( 'data-src' );
				if ( $data_src ) {
					$candidate = $this->resolve_url( $data_src, $base );
					if ( strpos( $candidate, '/logos/' ) === false ) {
						$image_candidates[] = $candidate;
					}
				}

				// data-original (lazy loading).
				$data_original = $img_node->getAttribute( 'data-original' );
				if ( $data_original ) {
					$candidate = $this->resolve_url( $data_original, $base );
					if ( strpos( $candidate, '/logos/' ) === false ) {
						$image_candidates[] = $candidate;
					}
				}
			}
		}

		// Select the best image from all candidates.
		if ( ! empty( $image_candidates ) ) {
			$event['image'] = $this->select_best_image_url( $image_candidates );
		}

		// Details list: organiser (bold), location parts, date/time line.
		$details        = $xpath->query( './/ul[contains(concat(" ", normalize-space(@class), " "), " list-details ")]/li', $card );
		$location_parts = array();
		if ( $details && $details->length > 0 ) {
			foreach ( $details as $li ) {
				$text = trim( $li->textContent );
				if ( '' === $text ) {
					continue;
				}

				// Organiser is wrapped in <strong>.
				$strong = $xpath->query( './/strong', $li );
				if ( $strong && $strong->length > 0 ) {
					$event['organizer'] = trim( $strong->item( 0 )->textContent );
					continue;
				}

				// Date/time line.
				if ( preg_match( '/\d{1,2}\s+(January|February|March|April|May|June|July|August|September|October|November|December)/i', $text ) ) {
					$this->parse_dates_times( $text, $year, $event );
					continue;
				}

				$location_parts[] = $text;
			}
		}

		if ( ! empty( $location_parts ) ) {
			$event['location'] = implode( ', ', $location_parts );
		}

		return $event;
	}

	/**
	 * Parse dates and times from a listing date line into the event array.
	 *
	 * Handles single and multi-session lines (e.g. "18 August, 10am - 1pm
	 * <br />18 August, 2pm - 7:30pm"). The first occurrence becomes the
	 * start, the last occurrence becomes the end.
	 *
	 * @param string $text  Date/time text.
	 * @param string $year  Event year.
	 * @param array  $event Event array (modified by reference).
	 */
	protected function parse_dates_times( $text, $year, &$event ) {
		$dates = array();
		if ( preg_match_all( '/(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)/i', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$dates[] = $match[1] . ' ' . $match[2] . ' ' . $year;
			}
		}

		$times = array();
		if ( preg_match_all( '/(\d{1,2})(?::(\d{2}))?\s*(am|pm)/i', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$times[] = $match[0];
			}
		}

		if ( ! empty( $dates ) ) {
			$event['start_date'] = $dates[0];
			$event['end_date']   = $dates[ count( $dates ) - 1 ];
		}
		if ( ! empty( $times ) ) {
			$event['start_time'] = $times[0];
			$event['end_time']   = $times[ count( $times ) - 1 ];
		}
	}

	/**
	 * Find the forward "Next" pagination URL, if any.
	 *
	 * The Heritage Week navigation renders "Previous" BEFORE "Next" from
	 * page 2 onwards. Selecting `nav->item(0)` therefore walks BACKWARDS on
	 * every page after the first, oscillating p1 <-> p2 until the page limit
	 * (Stage G2A, measured live: 651 requests / 25 unique URLs for Wicklow).
	 *
	 * Selection is deterministic and forward-only:
	 *
	 *   1. Prefer the anchor whose visible label says "Next".
	 *   2. Otherwise take the first anchor whose resolved URL differs from
	 *      the current page and has not already been visited this run.
	 *   3. Reject the current URL, an empty/invalid href, and any already
	 *      visited URL.
	 *
	 * @param DOMXPath $xpath   XPath object for the current page.
	 * @param string   $base    Current page URL.
	 * @param array    $visited Map of URLs already fetched this run (by reference).
	 * @return string Next page URL, or an empty string when there is none.
	 */
	protected function get_next_page_url( $xpath, $base, &$visited = array() ) {
		$nav_xpath = '//nav[contains(concat(" ", normalize-space(@class), " "), " paging ")]'
			. '//a[contains(concat(" ", normalize-space(@class), " "), " btn-paging ")]';

		$base_normalized = $this->normalize_pagination_url( $base );

		// Anchors the page labels as backwards. A "Previous" link that points
		// at an already-visited page is the NORMAL end of a listing, not a
		// pagination fault, so it must never mark the walk incomplete.
		$backwards = $this->collect_pagination_targets(
			$xpath,
			$nav_xpath . '[contains(translate(normalize-space(.), "PREVIOUS", "previous"), "prev")]',
			$base
		);

		// 1. Prefer the anchor the page itself labels as the forward link. The
		//    label is matched IN XPath, so no DOM property access is needed.
		$labelled_next = $xpath->query(
			$nav_xpath . '[contains(translate(normalize-space(.), "NEXT", "next"), "next")]'
		);
		if ( $labelled_next && $labelled_next->length > 0 ) {
			$candidate = $this->first_usable_forward_url( $labelled_next, $base, $visited );
			if ( '' !== $candidate ) {
				$this->forward_link_blocked = false;
				return $candidate;
			}

			// A forward link exists but every one of them is unusable: the
			// listing advertised more pages than we could reach.
			$this->forward_link_blocked = true;
			return '';
		}
		// 2. Otherwise fall back to structural forward pagination: the Heritage
		//    Week listing numbers pages as /event-listings/pN, so a destination
		//    whose page number is GREATER than the current page is forward.
		$current_page = $this->extract_page_number( $base );
		$nav          = $xpath->query( $nav_xpath );
		if ( ! $nav || 0 === $nav->length ) {
			$this->forward_link_blocked = false;
			return '';
		}

		$blocked_forward = false;

		foreach ( $nav as $anchor ) {
			$href = trim( (string) $anchor->getAttribute( 'href' ) );
			if ( '' === $href ) {
				continue;
			}

			// $absolute is what we fetch; $resolved is what we compare.
			$absolute = $this->resolve_url( $href, $base );
			$resolved = $this->normalize_pagination_url( $absolute );
			if ( '' === $resolved ) {
				continue;
			}

			// Only a strictly forward page number is a valid fallback.
			if ( $this->extract_page_number( $absolute ) > $current_page
				&& $resolved !== $base_normalized
				&& ! isset( $visited[ $resolved ] ) ) {
				$this->forward_link_blocked = false;
				return $absolute;
			}

			// An unusable destination only signals a broken walk when the page
			// did not label it as "Previous".
			if ( ! isset( $backwards[ $resolved ] ) ) {
				$blocked_forward = true;
			}
		}

		$this->forward_link_blocked = $blocked_forward;

		return '';
	}

	/**
	 * Collect the normalized URLs a set of pagination anchors points at.
	 *
	 * @param DOMXPath $xpath      XPath object for the current page.
	 * @param string   $xpath_expr Absolute XPath expression.
	 * @param string   $base       Current page URL (for resolving relative hrefs).
	 * @return array Map of normalized URL => true.
	 */
	protected function collect_pagination_targets( $xpath, $xpath_expr, $base ) {
		$targets = array();

		$nodes = $xpath->query( $xpath_expr );
		if ( ! $nodes || 0 === $nodes->length ) {
			return $targets;
		}

		foreach ( $nodes as $anchor ) {
			$href = trim( (string) $anchor->getAttribute( 'href' ) );
			if ( '' === $href ) {
				continue;
			}

			$resolved = $this->normalize_pagination_url( $this->resolve_url( $href, $base ) );
			if ( '' !== $resolved ) {
				$targets[ $resolved ] = true;
			}
		}

		return $targets;
	}

	/**
	 * Return the first usable forward URL from a node list.
	 *
	 * @param DOMNodeList $nodes   Candidate anchors.
	 * @param string      $base    Current page URL.
	 * @param array       $visited Map of URLs already fetched this run (by reference).
	 * @return string Absolute forward URL, or an empty string when none is usable.
	 */
	protected function first_usable_forward_url( $nodes, $base, &$visited ) {
		$base_normalized = $this->normalize_pagination_url( $base );

		foreach ( $nodes as $anchor ) {
			$href = trim( (string) $anchor->getAttribute( 'href' ) );
			if ( '' === $href ) {
				continue;
			}

			$absolute = $this->resolve_url( $href, $base );
			$resolved = $this->normalize_pagination_url( $absolute );
			if ( '' === $resolved ) {
				continue;
			}

			// A labelled "Next" that points at the current page or an already
			// visited one is not usable: following it would loop the walk.
			if ( $resolved === $base_normalized || isset( $visited[ $resolved ] ) ) {
				continue;
			}

			return $absolute;
		}

		return '';
	}


	/**
	 * Extract the listing page number from a Heritage Week pagination URL.
	 *
	 * The listing numbers pages as /event-listings/pN; the unnumbered listing
	 * URL is page 1.
	 *
	 * @param string $url Absolute or relative pagination URL.
	 * @return int Page number (1 when the URL carries none).
	 */
	protected function extract_page_number( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( preg_match( '#/p(\d+)(?:/|$)#', $path, $m ) ) {
			return (int) $m[1];
		}
		return 1;
	}

	/**
	 * Normalize a pagination URL for equality comparison.
	 *
	 * Two URLs that differ only by a trailing slash, a fragment, or the order
	 * of the query string must compare as equal, otherwise a "new" next link
	 * can silently re-point at the page just fetched.
	 *
	 * @param string $url Raw URL.
	 * @return string Comparable URL, or an empty string when unusable.
	 */
	protected function normalize_pagination_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		// Drop the fragment: it never changes which page is served.
		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$path  = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			$params = array();
			parse_str( $parts['query'], $params );
			if ( ! empty( $params ) ) {
				ksort( $params );
				$query = '?' . http_build_query( $params );
			}
		}

		return strtolower( $parts['host'] ) . $path . $query;
	}

	/**
	 * Enrich a listing event with data from its individual detail page.
	 *
	 * Falls back to the listing data if the detail page cannot be fetched.
	 *
	 * @param array $event Raw event from the listing.
	 * @return array
	 */
	protected function enrich_event( $event ) {
		if ( empty( $event['url'] ) ) {
			return $event;
		}

		$html = $this->fetch_detail_html( $event['url'] );
		if ( empty( $html ) ) {
			return $event;
		}

		$dom = $this->parse_html( $html );
		if ( ! $dom ) {
			return $event;
		}

		$xpath = new DOMXPath( $dom );

		// JSON-LD structured data: schema.org/Event.location is the strongest
		// location representation on the detail page. Only Event.location is
		// read, so organizer/contact addresses on the page are never used.
		// Values found here take precedence over the labelled details list.
		$jsonld_location = ( new Conexao_Event_Jsonld_Location() )->extract( $html );
		if ( ! empty( $jsonld_location['venue'] ) ) {
			$event['venue'] = $jsonld_location['venue'];
		}
		if ( ! empty( $jsonld_location['address'] ) ) {
			$event['address'] = $jsonld_location['address'];
		}

		// Description.
		$desc = $xpath->query( '//div[contains(concat(" ", normalize-space(@class), " "), " text-block ")]' );
		if ( $desc && $desc->length > 0 ) {
			$event['description'] = trim( preg_replace( '/\s+/', ' ', $desc->item( 0 )->textContent ) );
		}

		// Full location details.
		$loc       = $xpath->query( '//ul[contains(concat(" ", normalize-space(@class), " "), " event-dates ")]/li' );
		$loc_parts = array();
		if ( $loc && $loc->length > 0 ) {
			foreach ( $loc as $li ) {
				$t = trim( $li->textContent );
				if ( $t ) {
					$loc_parts[] = $t;
				}
			}
		}
		if ( ! empty( $loc_parts ) ) {
			$event['location'] = implode( ', ', $loc_parts );
		}

		// Event type.
		$type = $xpath->query( '//h3[contains(normalize-space(.), "Event Type")]/following-sibling::ul[1]//a' );
		if ( $type && $type->length > 0 ) {
			$event['event_type'] = trim( $type->item( 0 )->textContent );
		}

		// Organiser from "Further Information".
		$org = $xpath->query( '//h3[contains(normalize-space(.), "Further Information")]/following-sibling::p[1]' );
		if ( $org && $org->length > 0 ) {
			$event['organizer'] = trim( $org->item( 0 )->textContent );
		}

		// Higher-resolution image from detail page.
		// Collect all candidate URLs from the detail page and pick the best one.
		// Only override the listing image if we find a better one.
		$detail_candidates = array();

		// 1. Images inside figure.photo (main event photo on detail page).
		$imgs = $xpath->query( '//figure[contains(concat(" ", normalize-space(@class), " "), " photo ")]//img' );
		if ( $imgs ) {
			foreach ( $imgs as $img_node ) {
				$this->collect_img_candidates( $img_node, $event['url'], $detail_candidates );
			}
		}

		// 2. Also check for background-image styles on figures (some detail pages use them).
		$figures = $xpath->query( '//figure[contains(concat(" ", normalize-space(@class), " "), " photo ")]' );
		if ( $figures ) {
			foreach ( $figures as $fig ) {
				$style = $fig->getAttribute( 'style' );
				if ( preg_match( '/background-image:\s*url\(([^)]+)\)/i', $style, $m ) ) {
					$candidate = $this->resolve_url( trim( $m[1], '"\'' ), $event['url'] );
					if ( strpos( $candidate, '/logos/' ) === false ) {
						$detail_candidates[] = $candidate;
					}
				}
			}
		}

		// 3. Any img with srcset in the main content area.
		$all_imgs = $xpath->query( '//img[@srcset]' );
		if ( $all_imgs ) {
			foreach ( $all_imgs as $img_node ) {
				$this->collect_img_candidates( $img_node, $event['url'], $detail_candidates );
			}
		}

		if ( ! empty( $detail_candidates ) ) {
			$best_detail = $this->select_best_image_url( $detail_candidates );
			if ( $best_detail ) {
				// Only override if the detail image is likely better.
				// If we already have a listing image, compare dimensions from URLs.
				if ( empty( $event['image'] ) ) {
					$event['image'] = $best_detail;
				} else {
					$listing_width = $this->extract_width_from_url( $event['image'] );
					$detail_width  = $this->extract_width_from_url( $best_detail );
					// Use detail image if it's wider, or if we couldn't determine listing width.
					if ( $detail_width > $listing_width || ( 0 === $listing_width && $detail_width > 0 ) ) {
						$event['image'] = $best_detail;
					}
				}
			}
		}

		return $event;
	}

	/**
	 * Fetch a detail page without logging errors (used for enrichment).
	 *
	 * @param string $url URL to fetch.
	 * @return string HTML body or empty string on failure.
	 */
	protected function fetch_detail_html( $url ) {
		$response = $this->request_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => $this->get_http_user_agent(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return '';
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Collect image URL candidates from an img element.
	 *
	 * Checks src, srcset, data-src, and data-original attributes.
	 *
	 * @param DOMElement $img_node   Img DOM element.
	 * @param string     $base_url   Base URL for resolving relative URLs.
	 * @param array      $candidates Candidates array (modified by reference).
	 */
	protected function collect_img_candidates( $img_node, $base_url, &$candidates ) {
		// src attribute.
		$src = $img_node->getAttribute( 'src' );
		if ( $src ) {
			$candidate = $this->resolve_url( $src, $base_url );
			if ( strpos( $candidate, '/logos/' ) === false ) {
				$candidates[] = $candidate;
			}
		}

		// srcset attribute — may contain higher resolution variants.
		$srcset = $img_node->getAttribute( 'srcset' );
		if ( $srcset ) {
			foreach ( $this->extract_srcset_urls( $srcset ) as $srcset_url ) {
				$candidate = $this->resolve_url( $srcset_url, $base_url );
				if ( strpos( $candidate, '/logos/' ) === false ) {
					$candidates[] = $candidate;
				}
			}
		}

		// data-src (lazy loading).
		$data_src = $img_node->getAttribute( 'data-src' );
		if ( $data_src ) {
			$candidate = $this->resolve_url( $data_src, $base_url );
			if ( strpos( $candidate, '/logos/' ) === false ) {
				$candidates[] = $candidate;
			}
		}

		// data-original (lazy loading).
		$data_original = $img_node->getAttribute( 'data-original' );
		if ( $data_original ) {
			$candidate = $this->resolve_url( $data_original, $base_url );
			if ( strpos( $candidate, '/logos/' ) === false ) {
				$candidates[] = $candidate;
			}
		}
	}

	/**
	 * Extract all image URLs from a srcset attribute value.
	 *
	 * @param string $srcset Srcset attribute value.
	 * @return string[] Array of image URLs.
	 */
	protected function extract_srcset_urls( $srcset ) {
		$urls  = array();
		$parts = explode( ',', $srcset );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( empty( $part ) ) {
				continue;
			}
			$tokens = preg_split( '/\s+/', $part );
			if ( ! empty( $tokens[0] ) ) {
				$urls[] = trim( $tokens[0] );
			}
		}
		return $urls;
	}

	/**
	 * Select the best image URL from a set of candidates.
	 *
	 * Prefers:
	 * - /projects/ path images (Heritage Week event images)
	 * - Higher resolution (wider) images
	 * - Non-logo, non-placeholder images
	 *
	 * @param string[] $urls Array of candidate image URLs.
	 * @return string Best URL or empty string.
	 */
	protected function select_best_image_url( $urls ) {
		$urls = array_filter( array_unique( array_map( 'trim', $urls ) ) );
		if ( empty( $urls ) ) {
			return '';
		}

		// Filter out logos and placeholders.
		$filtered = array();
		foreach ( $urls as $url ) {
			if ( empty( $url ) ) {
				continue;
			}
			if ( strpos( $url, '/logos/' ) !== false ) {
				continue;
			}
			if ( strpos( $url, 'placeholder' ) !== false || strpos( $url, 'default' ) !== false ) {
				continue;
			}
			$filtered[] = $url;
		}

		if ( empty( $filtered ) ) {
			return '';
		}

		if ( count( $filtered ) === 1 ) {
			return $filtered[0];
		}

		// Prefer /projects/ path images (Heritage Week event images).
		foreach ( $filtered as $url ) {
			if ( strpos( $url, '/projects/' ) !== false ) {
				return $url;
			}
		}

		// Pick the widest image based on URL dimension patterns.
		$best_url   = $filtered[0];
		$best_width = 0;
		foreach ( $filtered as $url ) {
			$width = $this->extract_width_from_url( $url );
			if ( $width > $best_width ) {
				$best_width = $width;
				$best_url   = $url;
			}
		}

		return $best_url;
	}

	/**
	 * Extract the width dimension from an image URL.
	 *
	 * Handles patterns like:
	 * - image-740x400.jpg
	 * - image_740x400.jpg
	 * - image-740w.jpg
	 *
	 * @param string $url Image URL.
	 * @return int Width in pixels or 0 if not found.
	 */
	protected function extract_width_from_url( $url ) {
		// Pattern: -WIDTHxHEIGHT or _WIDTHxHEIGHT before extension.
		if ( preg_match( '/[-_](\d{3,5})x\d{3,5}(?=\.\w{2,5}(?:\?|$))/', $url, $m ) ) {
			return (int) $m[1];
		}
		// Pattern: -WIDTHw (responsive width descriptor in filename).
		if ( preg_match( '/[-_](\d{3,5})w(?=\.\w{2,5}(?:\?|$))/', $url, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}
}
