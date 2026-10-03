<?php
/**
 * Abstract event source handler.
 *
 * Defines the contract every source must implement so new sources can be
 * added later without rebuilding the system.
 *
 * HTTP fetching is structured: fetch_html() throws a
 * Conexao_Source_Fetch_Exception on network errors or non-200 responses so
 * the import engine can report a clean fatal error instead of guessing from
 * log entries. Sources that must tolerate individual page failures (e.g.
 * pagination) can use fetch_html_or_empty().
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

abstract class Conexao_Source_Base {

	/** @var array Source config. */
	protected $config;

	/**
	 * Whether the last fetch_events() call enumerated the WHOLE source.
	 *
	 * True means "this list is the complete upstream enumeration". False means
	 * the walk stopped early (time budget, a failed page, a pagination cycle)
	 * and the returned events are a SUBSET.
	 *
	 * The engine reads this through is_fetch_complete() and refuses to run
	 * mark_missing_events() when it is false, because absence from an
	 * incomplete walk is not evidence that an event disappeared upstream
	 * (Stage G2: incomplete enumeration must never hide a live event).
	 *
	 * @var bool
	 */
	protected $fetch_complete = true;

	/**
	 * Get the current import run ID, if a run is in progress.
	 *
	 * The engine publishes it through the existing
	 * `conexao_event_importer_current_run_id` filter, so every source handler
	 * can correlate its diagnostic entries with the run that produced them
	 * without inventing a second correlation identifier (Stage G3B).
	 *
	 * @return string Run ID, or an empty string outside a run.
	 */
	protected function get_current_run_id() {
		return (string) apply_filters( 'conexao_event_importer_current_run_id', '' );
	}

	/**
	 * Whether the last fetch_events() enumerated the whole source.
	 *
	 * @return bool True when the enumeration is complete.
	 */
	public function is_fetch_complete() {
		return (bool) $this->fetch_complete;
	}

	/**
	 * Mark the current enumeration as incomplete.
	 *
	 * @return void
	 */
	protected function mark_fetch_incomplete() {
		$this->fetch_complete = false;
	}

	/**
	 * Constructor.
	 *
	 * @param array $config Source configuration from Event Sources.
	 */
	public function __construct( $config ) {
		$this->config = $config;
	}

	/**
	 * Get the source slug (e.g. "laois_tourism").
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Fetch raw event listings from the source.
	 *
	 * @return array[] List of raw event arrays. Each must include:
	 *   - title       (string)
	 *   - url         (string, original source URL)
	 *   - start_date  (string, raw date)
	 *   - start_time  (string, optional raw time)
	 *   - end_date    (string, optional raw date)
	 *   - end_time    (string, optional raw time)
	 *   - location    (string, optional raw location)
	 *   - description (string, optional)
	 *   - image       (string, optional banner URL)
	 *   - source_id   (string, optional source event ID)
	 */
	abstract public function fetch_events();

	/**
	 * Get the HTTP User-Agent used for source requests.
	 *
	 * Several sources (e.g. laoistourism.ie behind Cloudflare) reject
	 * generic/bot-like user agents with 403, so the default mimics a real
	 * browser. Override via the 'conexao_event_importer_http_user_agent'
	 * filter when a source needs something specific.
	 *
	 * @return string
	 */
	protected function get_http_user_agent() {
		return apply_filters(
			'conexao_event_importer_http_user_agent',
			'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
		);
	}

	/**
	 * Perform a reuse-aware GET.
	 *
	 * This is the single seam through which every source handler performs an
	 * outbound GET. It applies, in order:
	 *
	 * 1. the per-run outbound **request budget** (G4C) — when the ceiling is
	 *    reached no request leaves the process;
	 * 2. the per-run **response reuse** store (G4A) — an identical request
	 *    already answered in this run returns its stored response instead of
	 *    going out to the network again;
	 * 3. the real transport, `wp_remote_get()`, unchanged.
	 *
	 * The returned value is always in the native `wp_remote_get()` shape: an
	 * `array` response or a `WP_Error`. A cache hit returns the stored response
	 * array byte-for-byte, so every downstream behaviour — status handling,
	 * exception classification, logging, parsing — is identical to a fresh
	 * fetch. Nothing here swallows a failure.
	 *
	 * Only 2xx responses are stored. A failure response is never memoised, so a
	 * transient 429/5xx inside one run cannot be replayed as if it were the
	 * page's real content, and a malformed 200 body is reused exactly as it
	 * would have been fetched — failing closed at the parser.
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args wp_remote_get() arguments.
	 * @return array|WP_Error
	 */
	protected function request_get( $url, array $args ) {
		$method = isset( $args['method'] ) ? (string) $args['method'] : 'GET';

		$cacheable = Conexao_Request_Reuse::is_active()
			&& Conexao_Request_Reuse::is_cacheable( $method, $args );

		$key = $cacheable ? Conexao_Request_Reuse::make_key( $method, $url, $args ) : '';

		if ( $cacheable ) {
			$cached = Conexao_Request_Reuse::get( $key );
			if ( null !== $cached ) {
				// Observability only: a reused response is NOT charged to the
				// budget, because no request left the process. This is what
				// makes "cache hits do not consume outbound budget" testable.
				Conexao_Request_Budget::record_cache_hit();
				return $cached;
			}
		} else {
			Conexao_Request_Reuse::record_bypass();
		}

		// The budget accounts for OUTBOUND requests only, so it is charged here
		// — after the cache lookup — never for a hit.
		if ( ! Conexao_Request_Budget::consume( 1 ) ) {
			return new WP_Error(
				'conexao_request_budget_exhausted',
				sprintf(
					/* translators: 1: request ceiling, 2: requests already made */
					__( 'The importer outbound request budget of %1$d requests was exhausted after %2$d requests; no further request was sent.', 'conexao-event-importer' ),
					Conexao_Request_Budget::get_limit(),
					Conexao_Request_Budget::get_used()
				)
			);
		}

		$response = wp_remote_get( $url, $args );

		if ( $cacheable && is_array( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				Conexao_Request_Reuse::set( $key, $response );
			}
		}

		return $response;
	}

	/**
	 * Fetch the HTML of a URL, throwing on transport/HTTP failure.
	 *
	 * Network errors and non-200 status codes raise a
	 * Conexao_Source_Fetch_Exception (after logging) so callers can
	 * distinguish "source down" from "no events found".
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args Optional extra wp_remote_get args (headers etc).
	 * @return string HTML body.
	 * @throws Conexao_Source_Fetch_Exception On any transport or HTTP failure.
	 */
	protected function fetch_html( $url, $args = array() ) {
		$default_args = array(
			'timeout'    => 30,
			'user-agent' => $this->get_http_user_agent(),
		);

		$response = $this->request_get( $url, array_merge( $default_args, $args ) );

		if ( is_wp_error( $response ) ) {
			$message = 'Falha ao buscar URL: ' . $response->get_error_message();
			Conexao_Import_Log::add(
				$this->get_id(),
				'error',
				$message,
				array(
					'url'         => $url,
					'http_status' => 0,
					'run_id'      => $this->get_current_run_id(),
				)
			);
			throw new Conexao_Source_Fetch_Exception( $message, 0 );
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $code ) {
			$message = 'Resposta HTTP ' . $code . ' ao buscar URL.';
			Conexao_Import_Log::add(
				$this->get_id(),
				'error',
				$message,
				array(
					'url'         => $url,
					'http_status' => (int) $code,
					'run_id'      => $this->get_current_run_id(),
				)
			);
			throw new Conexao_Source_Fetch_Exception( $message, (int) $code );
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Tolerant fetch: returns an empty string instead of throwing.
	 *
	 * Use for optional pages (pagination continuation, detail enrichment)
	 * where a single failed request should not abort the whole source run.
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args Optional extra wp_remote_get args.
	 * @return string HTML body or empty string on failure.
	 */
	protected function fetch_html_or_empty( $url, $args = array() ) {
		try {
			return $this->fetch_html( $url, $args );
		} catch ( Conexao_Source_Fetch_Exception $e ) {
			return '';
		}
	}

	/**
	 * Parse HTML into a DOMDocument.
	 *
	 * @param string $html Raw HTML.
	 * @return DOMDocument|false
	 */
	protected function parse_html( $html ) {
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
	 * Extract text from a DOMElement.
	 *
	 * @param DOMElement $node     Node to extract from.
	 * @param string     $selector CSS selector (basic).
	 * @return string
	 */
	protected function extract_text( $node, $selector = '' ) {
		if ( $selector ) {
			$found = $node->querySelector( $selector );
			if ( $found ) {
				return trim( $found->textContent );
			}
			return '';
		}
		return trim( $node->textContent );
	}

	/**
	 * Resolve a possibly-relative URL to an absolute URL.
	 *
	 * @param string $url  Raw URL.
	 * @param string $base Base URL.
	 * @return string
	 */
	protected function resolve_url( $url, $base ) {
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
