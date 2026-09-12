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
	 */
	public function fetch_events() {
		$hw_where = $this->get_hw_where();

		$all_events = array();
		$seen_ids   = array();
		$events     = array();
		$started_at = microtime( true );

		foreach ( $hw_where as $where ) {
			$base_url = $this->build_hw_url( $where );

			// Fetch the first page first so we can detect the event year.
			$first_html = $this->fetch_html_or_empty( $base_url );
			if ( empty( $first_html ) ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'error',
					sprintf( 'Failed to fetch Heritage Week listing for where[]=%s: %s', $where, $base_url )
				);
				continue;
			}
			$year = $this->detect_year( $first_html );

			$page_url = $base_url;
			$page     = 1;
			$html     = $first_html;

			while ( $html && $page <= self::MAX_PAGES ) {
				// Time budget check.
				if ( ( microtime( true ) - $started_at ) > self::TIME_BUDGET_SECONDS ) {
					Conexao_Import_Log::add(
						$this->get_id(),
						'warning',
						sprintf(
							/* translators: 1: where[] value, 2: last completed page */
							__( 'Heritage Week import reached its time budget after where[]=%1$s page %2$d — remaining will be picked up by the next run.', 'conexao-event-importer' ),
							$where,
							$page - 1
						)
					);
					break 2; // Exit both the while and foreach loops.
				}

				$dom = $this->parse_html( $html );
				if ( ! $dom ) {
					Conexao_Import_Log::add(
						$this->get_id(),
						'error',
						sprintf( 'Não foi possível analisar o HTML da página de eventos do Heritage Week (where[]=%s, page %d).', $where, $page ),
						array( 'url' => $page_url )
					);
					break;
				}

				$xpath = new DOMXPath( $dom );
				$cards = $xpath->query( '//article[contains(concat(" ", normalize-space(@class), " "), " item-summary ")]' );

				if ( $cards && $cards->length > 0 ) {
					foreach ( $cards as $card ) {
						// Time budget check (per event for detail enrichment).
						if ( ( microtime( true ) - $started_at ) > self::TIME_BUDGET_SECONDS ) {
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
							Conexao_Import_Log::add( $this->get_id(), 'error', 'Erro ao processar evento do Heritage Week: ' . $e->getMessage() );
						}
					}
				}

				$next = $this->get_next_page_url( $xpath, $page_url );
				if ( ! $next ) {
					break;
				}

				$page++;
				$page_url = $next;
				$html     = $this->fetch_html_or_empty( $page_url );
			}
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
			$all_events[] = $event;
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
	 * Find the "Next" pagination URL, if any.
	 *
	 * @param DOMXPath $xpath XPath object.
	 * @param string   $base  Current page URL.
	 * @return string Next page URL or empty string.
	 */
	protected function get_next_page_url( $xpath, $base ) {
		$nav = $xpath->query( '//nav[contains(concat(" ", normalize-space(@class), " "), " paging ")]//a[contains(concat(" ", normalize-space(@class), " "), " btn-paging ")]' );
		if ( $nav && $nav->length > 0 ) {
			$href = $nav->item( 0 )->getAttribute( 'href' );
			if ( $href ) {
				return $this->resolve_url( $href, $base );
			}
		}
		return '';
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
		$response = wp_remote_get(
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
		$urls = array();
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
