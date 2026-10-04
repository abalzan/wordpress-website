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
	 * How many objects this listing can ever expose through its own pagination.
	 *
	 * `page_count × page_size` is the hard ceiling the upstream offers. Anything
	 * beyond it is not reachable through this listing, whatever the walk does.
	 *
	 * @param int $page_count Declared page count.
	 * @param int $page_size  Declared page size (0 when the payload omits it).
	 * @return int Reachable ceiling; 0 when it cannot be derived.
	 */
	protected function reachable_object_ceiling( $page_count, $page_size ) {
		$page_count = (int) $page_count;
		$page_size  = (int) $page_size;

		if ( $page_count <= 0 || $page_size <= 0 ) {
			return 0;
		}

		return $page_count * $page_size;
	}

	/**
	 * Whether the listing declares more objects than its pagination can expose.
	 *
	 * G8. A listing that reports `object_count > page_count × page_size` is
	 * truncating its own results. The measured live evidence is in
	 * fetch_via_scrape(); the short version is that Eventbrite pins
	 * `page_count` at 49 for every county while declaring 1,547 (Cork) to
	 * 5,722 (Cavan) objects.
	 *
	 * When the payload does not state a page size the ceiling is unknowable, and
	 * this returns false — the conservative choice. An unknowable ceiling must
	 * not silently downgrade a legitimately complete small listing.
	 *
	 * @param int $object_count Declared object count.
	 * @param int $page_count   Declared page count.
	 * @param int $page_size    Declared page size.
	 * @return bool True when the listing truncates its own results.
	 */
	protected function is_upstream_truncated( $object_count, $page_count, $page_size ) {
		$object_count = (int) $object_count;
		$ceiling      = $this->reachable_object_ceiling( $page_count, $page_size );

		if ( 0 === $ceiling ) {
			return false;
		}

		// A payload that declares no objects is not evidence of truncation; that
		// case is handled by the first-page fail-closed rules, not here.
		if ( $object_count <= 0 ) {
			return false;
		}

		return $object_count > $ceiling;
	}

	/**
	 * The pacing / rate-limit policy for this walk.
	 *
	 * G5: one policy instance per run, shared across every Eventbrite source,
	 * so the measured source-wide rate limit is cooled down once. Overridable
	 * by a filter so a test can inject a deterministic clock and sleeper.
	 *
	 * @return Conexao_Eventbrite_Pacing
	 */
	protected function get_pacing() {
		$pacing = apply_filters( 'conexao_eventbrite_pacing', null );

		if ( $pacing instanceof Conexao_Eventbrite_Pacing ) {
			return $pacing;
		}

		return Conexao_Eventbrite_Pacing::get_shared();
	}

	/**
	 * Fetch raw event listings from Eventbrite county discovery.
	 *
	 * This is the ONE supported Eventbrite path, and it needs no credential.
	 * It always uses the HTML discovery scraper.
	 *
	 * There is deliberately no client selection here and no token check (G7):
	 *
	 *   - the v3 Events Search API (`/v3/events/search/`) is RETIRED by
	 *     Eventbrite and answers 404 — verified live again in Stage A and in G6;
	 *   - the official API's remaining event collection is
	 *     `/organizations/{id}/events/` with PER-USER discovery, so it can only
	 *     list events you own. A county listing aggregates independent
	 *     third-party organizers (measured: Cork 36, Dublin 74, Carlow 51 in
	 *     60), so no authenticated request can enumerate these sources;
	 *   - G6 therefore returned `NOT VIABLE` and G7 retired the dead
	 *     `eventbrite_api_token` plumbing rather than leaving a flag for a mode
	 *     that never existed.
	 *
	 * See `docs/reports/2026-10-04-eventbrite-api-feasibility.md` and
	 * `docs/reports/2026-10-04-eventbrite-api-retirement.md`. Do not add a
	 * credential-conditional branch here without re-running that measurement.
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
	 * @throws Conexao_Source_Fetch_Exception When the first page cannot be fetched
	 *                                        or parsed: an unenumerable source is a
	 *                                        failed source, never an empty one.
	 */
	protected function fetch_via_scrape() {
		$base_url = isset( $this->config['url'] ) && $this->config['url'] ? $this->config['url'] : Conexao_Eventbrite_Client::DEFAULT_DISCOVERY_URL;

		$parser     = new Conexao_Eventbrite_Parser();
		$normalizer = new Conexao_Eventbrite_Normalizer();

		// G5E: one pacing policy per run, shared by every Eventbrite source, so
		// the measured source-wide rate limit is handled once rather than
		// re-discovered (and re-requested) by each county in turn.
		$pacing = $this->get_pacing();
		$client = new Conexao_Eventbrite_Client( $this->get_id(), $pacing );

		// G5E: when an earlier Eventbrite source in THIS run was already rate
		// limited, do not start another walk. The measured full-run trace shows
		// 24 of 24 subsequent Eventbrite paths answering 429 on every attempt,
		// so walking them would be pure request storm. The source is reported
		// as rate limited (never as empty, never as complete), and the operator
		// retries it individually later (G5G).
		if ( Conexao_Eventbrite_Pacing::is_rate_limited() ) {
			Conexao_Eventbrite_Pacing::record_skipped_source( $this->get_id() );

			$this->mark_fetch_incomplete();

			$reason = sprintf(
				/* translators: 1: source that was rate limited first, 2: cooldown in minutes */
				__( 'Not attempted: Eventbrite already rate-limited source %1$s earlier in this run, so this source was skipped without sending any request. Retry it individually later, after at least %2$d minutes.', 'conexao-event-importer' ),
				Conexao_Eventbrite_Pacing::get_rate_limited_source(),
				(int) ceil( Conexao_Eventbrite_Pacing::MAX_COOLDOWN_SECONDS / 60 )
			);

			Conexao_Import_Log::add(
				$this->get_id(),
				'warning',
				$reason,
				array(
					'reason'      => 'rate_limited',
					'reason_code' => 'rate_limited',
					'skipped'     => true,
					'run_id'      => $this->get_current_run_id(),
				)
			);

			throw new Conexao_Source_Fetch_Exception( esc_html( $reason ), 429 );
		}

		// Fetch page 1 first to get pagination info. A failure here is FATAL:
		// an unreachable first page means the source could not be enumerated
		// at all, which is NOT the same as an empty calendar. Returning an
		// empty array here would be recorded downstream as `found=0,
		// status=success` (the Stage G1 masking defect). Raise the structured
		// exception instead and let the engine record a failed source.
		$html = $client->fetch_page( $base_url, 1 );
		if ( empty( $html ) ) {
			$status = $client->get_last_http_status();
			$reason = sprintf(
				/* translators: %s: discovery URL */
				__( 'Failed to fetch Eventbrite discovery page: %s', 'conexao-event-importer' ),
				$base_url
			);
			Conexao_Import_Log::add(
				$this->get_id(),
				'error',
				$reason,
				array(
					'url'         => $base_url,
					'http_status' => $status,
					'run_id'      => $this->get_current_run_id(),
				)
			);
			throw new Conexao_Source_Fetch_Exception( esc_html( $reason ), (int) esc_html( (string) $status ) );
		}

		$this->fetch_complete = true;
		$incomplete_reason    = '';

		try {
			$page_data = $parser->parse( $html );
		} catch ( Exception $e ) {
			$reason = sprintf( 'Eventbrite page 1 parse failed: %s', $e->getMessage() );
			Conexao_Import_Log::add(
				$this->get_id(),
				'error',
				$reason,
				array(
					'url'    => $base_url,
					'run_id' => $this->get_current_run_id(),
				)
			);
			// A malformed first page is also "cannot enumerate", never "empty".
			throw new Conexao_Source_Fetch_Exception( esc_html( $reason ), 0 );
		}

		$page_count   = isset( $page_data['pagination']['page_count'] ) ? (int) $page_data['pagination']['page_count'] : 1;
		$object_count = isset( $page_data['pagination']['object_count'] ) ? (int) $page_data['pagination']['object_count'] : 0;
		$page_size    = isset( $page_data['pagination']['page_size'] ) ? (int) $page_data['pagination']['page_size'] : 0;

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf( 'Eventbrite: page_count=%d, object_count=%d for %s.', $page_count, $object_count, $base_url ),
			array( 'run_id' => $this->get_current_run_id() )
		);

		$all_events = isset( $page_data['events'] ) ? $page_data['events'] : array();

		/*
		 * G8 — upstream pagination truncation is NOT completeness.
		 *
		 * Measured 2026-10-04 against the live public listing: Eventbrite pins
		 * `page_count` at exactly 49 for every county regardless of volume,
		 * while declaring a much larger `object_count`:
		 *
		 *     Cork   object_count=1547  page_count=49  ->  980 reachable (567 lost)
		 *     Dublin object_count=5162  page_count=49  ->  980 reachable (4182 lost)
		 *     Cavan  object_count=5722  page_count=49  ->  980 reachable (4742 lost)
		 *
		 * A walk that reaches the declared last page has therefore still NOT
		 * enumerated the source: the upstream itself is withholding the rest
		 * behind a pagination cap. Treating that as complete is exactly the
		 * condition under which `mark_missing_events()` is allowed to hide live
		 * events, so it must be refused here.
		 *
		 * This guard is conservative by construction. It can only downgrade
		 * complete -> incomplete, which suppresses a destructive
		 * reconciliation; it can never authorise one. A source whose declared
		 * objects genuinely fit inside its declared pages (a small county, or
		 * a date-window listing) is unaffected and stays complete.
		 */
		if ( $this->is_upstream_truncated( $object_count, $page_count, $page_size ) ) {
			$this->mark_fetch_incomplete();

			$incomplete_reason = sprintf(
				'upstream pagination truncation: %d objects declared but only %d reachable through %d page(s) of %d',
				$object_count,
				$this->reachable_object_ceiling( $page_count, $page_size ),
				(int) $page_count,
				(int) $page_size
			);

			Conexao_Import_Log::add(
				$this->get_id(),
				'warning',
				sprintf(
					/* translators: 1: declared object count, 2: page count, 3: page size */
					__( 'Eventbrite enumeration is INCOMPLETE: this listing declares %1$d objects but its own pagination exposes only %2$d pages of %3$d, so the remaining events are not reachable through this listing. They were left untouched (not marked source_not_found).', 'conexao-event-importer' ),
					(int) $object_count,
					(int) $page_count,
					(int) $page_size
				),
				array(
					'reason'       => 'incomplete_enumeration',
					'reason_code'  => 'upstream_truncated',
					'object_count' => (int) $object_count,
					'page_count'   => (int) $page_count,
					'page_size'    => (int) $page_size,
					'run_id'       => $this->get_current_run_id(),
				)
			);
		}

		// Walk remaining pages.
		//
		// G5: the clock comes from the pacing policy so a test can drive the
		// time-budget branch deterministically (and prove that a cooldown
		// cannot extend the walk indefinitely).
		$started_at = $pacing->now();
		$budget     = $client->get_time_budget_seconds();

		for ( $page = 2; $page <= $page_count; $page++ ) {
			if ( ( $pacing->now() - $started_at ) > $budget ) {
				$this->mark_fetch_incomplete();
				$incomplete_reason = sprintf( 'time budget of %ds expired after page %d/%d', (int) $budget, $page - 1, $page_count );
				Conexao_Import_Log::add(
					$this->get_id(),
					'warning',
					sprintf(
						/* translators: 1: last completed page, 2: total pages */
						__( 'Eventbrite import reached its time budget after page %1$d/%2$d — remaining pages will be picked up by the next run.', 'conexao-event-importer' ),
						$page - 1,
						$page_count
					),
					array( 'run_id' => $this->get_current_run_id() )
				);
				break;
			}

			// G5A: every inter-request wait is decided by the pacing policy.
			$pacing->pace_page_request();

			$html = $client->fetch_page( $base_url, $page );
			if ( empty( $html ) ) {
				// Partial-page tolerance is preserved: keep the events already
				// collected. But the enumeration is now INCOMPLETE, so the
				// engine must not treat the surviving subset as the full
				// source listing.
				$this->mark_fetch_incomplete();

				// ---- G5C: fail fast under repeated 429. ----
				//
				// The pre-G5 loop did `continue` on ANY failed page, so a
				// rate-limited walk kept asking for page N+1, N+2, ... while
				// the upstream was refusing everything. That is the request
				// storm the brief forbids. A page that exhausted its attempt
				// ceiling *because of 429* is evidence the source is being
				// rate limited, so the walk stops here: events already
				// fetched are preserved, the enumeration stays INCOMPLETE, and
				// nothing is marked source_not_found.
				if ( $client->was_rate_limited() ) {
					$incomplete_reason = sprintf(
						'rate limited on page %d/%d (HTTP 429 after %d attempts; %d rate-limit responses)',
						$page,
						$page_count,
						Conexao_Eventbrite_Client::MAX_RETRIES,
						$client->get_rate_limit_hits()
					);

					Conexao_Import_Log::add(
						$this->get_id(),
						'error',
						sprintf(
							/* translators: 1: page number, 2: total pages, 3: first page reached */
							__( 'Eventbrite stopped this source at page %1$d/%2$d because it is rate limiting the importer. %3$d pages were already fetched and are kept; the rest is retried on a later run.', 'conexao-event-importer' ),
							(int) $page,
							(int) $page_count,
							(int) ( $page - 1 )
						),
						array(
							'http_status'      => 429,
							'page'             => (int) $page,
							'reason'           => 'rate_limited',
							'reason_code'      => 'rate_limited',
							'rate_limit_hits'  => $client->get_rate_limit_hits(),
							'cooldown_seconds' => $pacing->get_cooldown_seconds_applied(),
							'run_id'           => $this->get_current_run_id(),
						)
					);

					break;
				}

				$incomplete_reason = sprintf( 'page %d/%d could not be fetched (HTTP %d)', $page, $page_count, $client->get_last_http_status() );
				Conexao_Import_Log::add(
					$this->get_id(),
					'error',
					sprintf( 'Failed to fetch Eventbrite page %d/%d.', $page, $page_count ),
					array(
						'http_status' => $client->get_last_http_status(),
						'reason'      => $client->get_last_failure_reason(),
						'reason_code' => 'upstream_unavailable',
						'run_id'      => $this->get_current_run_id(),
					)
				);
				continue;
			}

			try {
				$page_data = $parser->parse( $html );
			} catch ( Exception $e ) {
				$this->mark_fetch_incomplete();
				$incomplete_reason = sprintf( 'page %d/%d could not be parsed', $page, $page_count );
				Conexao_Import_Log::add(
					$this->get_id(),
					'error',
					sprintf( 'Eventbrite page %d/%d parse failed: %s', $page, $page_count, $e->getMessage() ),
					array( 'run_id' => $this->get_current_run_id() )
				);
				continue;
			}

			Conexao_Import_Log::add(
				$this->get_id(),
				'info',
				sprintf( 'Fetched page %d/%d, %d events.', $page, $page_count, count( $page_data['events'] ) ),
				array( 'run_id' => $this->get_current_run_id() )
			);

			$all_events = array_merge( $all_events, $page_data['events'] );
		}

		// Reaching MAX_PAGES with pages still outstanding is also incomplete.
		if ( $this->fetch_complete && $page_count > self::MAX_PAGES ) {
			$this->mark_fetch_incomplete();
			$incomplete_reason = sprintf( 'page limit of %d reached with %d pages declared', self::MAX_PAGES, $page_count );
		}

		if ( ! $this->fetch_complete ) {
			$rate_limited = ( false !== strpos( $incomplete_reason, 'rate limited' ) );

			Conexao_Import_Log::add(
				$this->get_id(),
				'warning',
				sprintf(
					/* translators: %s: reason the walk is incomplete */
					__( 'Eventbrite enumeration is INCOMPLETE (%s). Events absent from this walk will not be marked source_not_found.', 'conexao-event-importer' ),
					$incomplete_reason
				),
				array(
					'reason'      => $rate_limited ? 'rate_limited' : 'incomplete_enumeration',
					'reason_code' => $rate_limited ? 'rate_limited' : 'incomplete_enumeration',
					'run_id'      => $this->get_current_run_id(),
				)
			);
		}

		// Deduplicate by event ID.
		$seen_ids      = array();
		$unique_events = array();
		$duplicates    = 0;

		foreach ( $all_events as $event ) {
			$id = isset( $event['id'] ) ? (string) $event['id'] : '';
			if ( empty( $id ) ) {
				continue;
			}
			if ( isset( $seen_ids[ $id ] ) ) {
				++$duplicates;
				continue;
			}
			$seen_ids[ $id ] = true;
			$unique_events[] = $event;
		}

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf( 'Eventbrite: %d total fetched, %d duplicates removed, %d unique.', count( $all_events ), $duplicates, count( $unique_events ) ),
			array( 'run_id' => $this->get_current_run_id() )
		);

		// Filter for configured county region and normalize.
		$raw_events       = array();
		$skipped          = 0;
		$rejected_regions = array();

		foreach ( $unique_events as $event ) {
			// Validate county region.
			$region_check = $this->is_county_event( $event );
			if ( ! $region_check['accepted'] ) {
				++$skipped;
				if ( ! empty( $region_check['observed'] ) ) {
					$rejected_regions[] = $region_check['observed'];
				}
				continue;
			}

			// Skip online events (this is a physical county events calendar).
			if ( ! empty( $event['is_online_event'] ) ) {
				++$skipped;
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
					implode(
						', ',
						array_map(
							function ( $region, $count ) {
								return $region . ' (' . $count . ')';
							},
							array_keys( $top ),
							$top
						)
					)
				),
				array( 'run_id' => $this->get_current_run_id() )
			);
		}

		Conexao_Import_Log::add(
			$this->get_id(),
			'info',
			sprintf( 'Eventbrite: %d %s events after filtering, %d skipped (non-%s or online).', count( $raw_events ), $this->get_county(), $this->get_county(), $skipped ),
			array( 'run_id' => $this->get_current_run_id() )
		);

		// G9: record what this enumeration actually produced, so the engine can
		// distinguish a real listing from a response that proved nothing.
		$this->fetched_event_count = count( $raw_events );

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
				return array(
					'accepted' => true,
					'observed' => '',
				);
			}
			return array(
				'accepted' => false,
				'observed' => '(no location data)',
			);
		}

		$observed_region = '';

		foreach ( $event['locations'] as $location ) {
			if ( ! is_array( $location ) ) {
				continue;
			}
			if ( isset( $location['type'] ) && 'region' === $location['type'] ) {
				$observed_region = isset( $location['name'] ) ? $location['name'] : '';
				if ( in_array( $observed_region, $labels, true ) ) {
					return array(
						'accepted' => true,
						'observed' => $observed_region,
					);
				}
			}
		}

		return array(
			'accepted' => false,
			'observed' => $observed_region,
		);
	}
}
