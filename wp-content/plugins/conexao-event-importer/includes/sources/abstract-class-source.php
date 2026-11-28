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
	 * How many events the last fetch_events() returned.
	 *
	 * G9. Distinguishes an empty-but-successful enumeration from one that
	 * never received anything, so cooldown recovery is never inferred from a
	 * response that proved nothing.
	 *
	 * @var int
	 */
	protected $fetched_event_count = 0;

	/**
	 * Maximum number of attempts per outbound request (1 initial + retries).
	 *
	 * Mirrors Conexao_Eventbrite_Client::MAX_RETRIES so no second, conflicting
	 * retry policy exists across sources.
	 *
	 * @var int
	 */
	const HTTP_MAX_RETRIES = 3;

	/**
	 * Base delay (seconds) for exponential backoff between retries.
	 * Attempt 1 -> 2s, attempt 2 -> 4s, attempt 3 -> 8s.
	 *
	 * @var int
	 */
	const HTTP_BASE_BACKOFF_SECONDS = 2;

	/**
	 * Minimum delay (seconds) between two outbound requests of the same walk.
	 * Kept deliberately small so a source walk stays reasonably fast while
	 * still reducing the chance of triggering provider rate limiting.
	 *
	 * @var int
	 */
	const HTTP_REQUEST_DELAY_SECONDS = 1;

	/**
	 * Hard cap (seconds) on any server-imposed Retry-After delay, so an
	 * upstream can never force an unbounded sleep.
	 *
	 * @var int
	 */
	const HTTP_MAX_RETRY_AFTER_SECONDS = 60;

	/**
	 * Injectable sleeper for tests. A callable receiving the delay in
	 * seconds, or null to sleep for real.
	 *
	 * @var callable|null
	 */
	protected $http_sleeper = null;

	/**
	 * Microtime of the last outbound request of this walk, used to enforce
	 * the inter-request delay. A fresh handler instance is created per
	 * source run (Conexao_Event_Importer_Engine::get_source_handler), so this
	 * is effectively per-walk and needs no explicit reset.
	 *
	 * @var float
	 */
	protected $http_last_request_time = 0;

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
	 * Whether the last fetch_events() actually returned any events.
	 *
	 * G9. Cooldown recovery uses this to tell "the upstream answered with a
	 * real listing" apart from "we got nothing back". Only the former proves
	 * a throttle has cleared, so a handler that returned zero events must not
	 * be treated as recovered.
	 *
	 * @return bool True when the last enumeration produced at least one event.
	 */
	public function has_fetched_events() {
		return ! empty( $this->fetched_event_count );
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
	 * Narrow a DOM node to an element.
	 *
	 * DOM traversal in these parsers is dynamically typed: `DOMXPath::query()`
	 * returns `DOMNodeList|false`, `DOMNodeList::item()` returns `?DOMNode`,
	 * and iterating a node list may yield a `DOMNameSpaceNode`. None of those
	 * expose the element-only APIs the parsers rely on (`getAttribute()`,
	 * `getElementsByTagName()`), so every attribute read funnels through this
	 * single guard instead of an unchecked call on a wider type.
	 *
	 * A non-element node is never cast, guessed or re-queried: the caller
	 * treats `null` exactly as it treats an element that lacks the attribute,
	 * which is what the previous unchecked call produced in practice (the
	 * attribute was simply absent). Unexpected node types therefore fail
	 * safely instead of raising.
	 *
	 * @param mixed $node Candidate node.
	 * @return DOMElement|null The element, or null for any other node type.
	 */
	protected static function as_element( $node ) {
		return $node instanceof DOMElement ? $node : null;
	}

	/**
	 * Extract the text of a DOM node.
	 *
	 * Accepts `DOMNode|null` because `DOMNodeList::item()` is nullable: the
	 * null case is decided here once rather than at every call site.
	 *
	 * There is deliberately no CSS-selector parameter. PHP's DOM extension
	 * exposes no `querySelector()` on `DOMElement` (only the separate PHP 8.4
	 * `Dom\Element` API has one), so a selector could never be honoured. The
	 * previous signature advertised one and would have raised on an undefined
	 * method had any caller passed it; no caller ever did.
	 *
	 * @param DOMNode|null $node Node to extract from.
	 * @return string Trimmed text, or '' for a non-element / missing node.
	 */
	protected function extract_text( $node ) {
		if ( ! $node instanceof DOMElement ) {
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

	/**
	 * Install an injectable sleeper (tests) or clear it (real sleep).
	 *
	 * @param callable|null $sleeper Receives the delay in seconds.
	 * @return void
	 */
	public function set_http_sleeper( $sleeper ) {
		$this->http_sleeper = is_callable( $sleeper ) ? $sleeper : null;
	}

	/**
	 * Sleep for $seconds, honouring an injected sleeper when present.
	 *
	 * @param float $seconds Seconds to sleep.
	 * @return void
	 */
	protected function sleep_for( $seconds ) {
		$seconds = (float) $seconds;
		if ( $seconds <= 0 ) {
			return;
		}
		if ( null !== $this->http_sleeper ) {
			call_user_func( $this->http_sleeper, $seconds );
			return;
		}
		sleep( (int) ceil( $seconds ) );
	}

	/**
	 * Whether an HTTP status code is a transient, retryable failure.
	 *
	 * 429 and 5xx are retried. 401/403/404/408 are not: retrying an
	 * authorisation or not-found condition only sends more traffic.
	 *
	 * @param int $code HTTP status code.
	 * @return bool
	 */
	public static function is_retryable_status( $code ) {
		return in_array( (int) $code, array( 429, 500, 502, 503, 504 ), true );
	}

	/**
	 * Parse a Retry-After header value into a delay in seconds.
	 *
	 * Accepts both forms: an integer delta-seconds (e.g. "7") or an HTTP date
	 * (e.g. "Wed, 21 Oct 2015 07:28:00 GMT"). The result is capped at
	 * HTTP_MAX_RETRY_AFTER_SECONDS so a server can never impose an unbounded
	 * sleep. Returns null when the value is not usable, in which case the
	 * caller falls back to exponential backoff.
	 *
	 * @param string $header Raw Retry-After header value.
	 * @param int    $now    Reference time (unix seconds). Defaults to now.
	 * @return float|null Delay in seconds, or null when unparseable.
	 */
	public static function parse_retry_after( $header, $now = 0 ) {
		$header = trim( (string) $header );
		if ( '' === $header ) {
			return null;
		}

		$now = 0 === $now ? (int) time() : (int) $now;

		// Delta-seconds form.
		if ( preg_match( '/^\d+$/', $header ) ) {
			return (float) min( (int) $header, self::HTTP_MAX_RETRY_AFTER_SECONDS );
		}

		// HTTP-date form.
		$ts = strtotime( $header );
		if ( false !== $ts ) {
			$delay = (float) max( 0, $ts - $now );
			return (float) min( $delay, self::HTTP_MAX_RETRY_AFTER_SECONDS );
		}

		return null;
	}

	/**
	 * Exponential backoff delay (seconds) for a given attempt number.
	 *
	 * @param int $attempt 1-based attempt number.
	 * @return float Seconds.
	 */
	protected function get_backoff_seconds( $attempt ) {
		return (float) ( self::HTTP_BASE_BACKOFF_SECONDS * pow( 2, max( 0, (int) $attempt - 1 ) ) );
	}

	/**
	 * Apply a backoff delay, honouring an injected sleeper.
	 *
	 * @param int         $attempt     Current attempt number (1-based).
	 * @param float|null  $retry_after Parsed, already-capped Retry-After delay,
	 *                                 or null to use exponential backoff.
	 * @return bool Always true (symmetry with the client pacing API).
	 */
	protected function http_backoff( $attempt, $retry_after = null ) {
		$seconds = null !== $retry_after ? (float) $retry_after : $this->get_backoff_seconds( $attempt );
		$seconds = min( $seconds, (float) self::HTTP_MAX_RETRY_AFTER_SECONDS );
		$this->sleep_for( $seconds );
		return true;
	}

	/**
	 * Apply the polite inter-request delay before an outbound request.
	 *
	 * Shared by the retrying listing fetch and the single-request detail fetch,
	 * so "be polite between the requests of one walk" is decided in exactly one
	 * place. `$this->http_last_request_time` is updated by every fetch path.
	 *
	 * @return void
	 */
	protected function respect_request_delay() {
		if ( $this->http_last_request_time <= 0 ) {
			return;
		}

		$elapsed = microtime( true ) - $this->http_last_request_time;
		$delay   = (float) apply_filters(
			'conexao_event_importer_http_request_delay_seconds',
			self::HTTP_REQUEST_DELAY_SECONDS
		);

		if ( $elapsed < $delay ) {
			$this->sleep_for( $delay - $elapsed );
		}
	}

	/**
	 * Polite, retry-aware HTML fetch.
	 *
	 * Like fetch_html(), but instead of failing on the first non-200 response
	 * it retries retryable statuses (429, 500, 502, 503, 504) up to
	 * HTTP_MAX_RETRIES, honouring Retry-After where present and applying
	 * exponential backoff otherwise. An inter-request delay is applied between
	 * requests of the same walk to reduce the chance of triggering provider
	 * rate limiting.
	 *
	 * Every attempt is logged (url, attempt, http_status, duration,
	 * content-type, a truncated body preview, retry_after) so operators can
	 * see exactly what happened. No secrets are logged.
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args wp_remote_get() arguments.
	 * @return string HTML body on success.
	 * @throws Conexao_Source_Fetch_Exception On transport failure or when all
	 *                                         retries are exhausted.
	 */
	protected function fetch_html_polite( $url, $args = array() ) {
		$default_args = array(
			'timeout'    => 30,
			'user-agent' => $this->get_http_user_agent(),
		);
		$args         = array_merge( $default_args, $args );

		$this->respect_request_delay();

		$max_retries     = (int) apply_filters( 'conexao_event_importer_http_max_retries', self::HTTP_MAX_RETRIES );
		$rate_limit_hits = 0;
		$last_code       = 0;

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			$started                      = microtime( true );
			$response                     = $this->request_get( $url, $args );
			$this->http_last_request_time = microtime( true );
			$duration                     = (float) ( $this->http_last_request_time - $started );

			if ( is_wp_error( $response ) ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'error',
					'Polite fetch transport failure.',
					array(
						'url'         => $url,
						'attempt'     => $attempt,
						'http_status' => 0,
						'reason'      => 'transport_error',
						'duration'    => $duration,
						'run_id'      => $this->get_current_run_id(),
					)
				);
				if ( $attempt >= $max_retries ) {
					$message = 'Falha ao buscar URL: ' . $response->get_error_message();
					throw new Conexao_Source_Fetch_Exception( $message, 0 );
				}
				$this->http_backoff( $attempt );
				continue;
			}

			$code               = (int) wp_remote_retrieve_response_code( $response );
			$last_code          = $code;
			$headers            = wp_remote_retrieve_headers( $response );
			$content_type       = isset( $headers['content-type'] ) ? (string) $headers['content-type'] : '';
			$retry_after_header = isset( $headers['retry-after'] ) ? (string) $headers['retry-after'] : '';
			$retry_after        = '' !== $retry_after_header
				? self::parse_retry_after( $retry_after_header )
				: null;

			$body         = wp_remote_retrieve_body( $response );
			$body_preview = substr( strip_tags( (string) $body ), 0, 200 );

			Conexao_Import_Log::add(
				$this->get_id(),
				'info',
				sprintf( 'Polite fetch attempt %d for URL returned HTTP %d.', $attempt, $code ),
				array(
					'url'                 => $url,
					'attempt'             => $attempt,
					'http_status'         => $code,
					'duration'            => $duration,
					'content_type'        => $content_type,
					'body_preview'        => $body_preview,
					'retry_after'         => $retry_after_header,
					'retry_after_present' => '' !== $retry_after_header ? '1' : '',
					'rate_limit_hits'     => (string) $rate_limit_hits,
					'run_id'              => $this->get_current_run_id(),
				)
			);

			if ( 200 === $code ) {
				return wp_remote_retrieve_body( $response );
			}

			// Not transient: stop and surface as a fetch failure.
			if ( ! self::is_retryable_status( $code ) ) {
				$message = 'Resposta HTTP ' . $code . ' ao buscar URL.';
				throw new Conexao_Source_Fetch_Exception( $message, $code );
			}

			// Retryable (429 / 5xx).
			++$rate_limit_hits;
			if ( 429 === $code ) {
				Conexao_Import_Log::add(
					$this->get_id(),
					'warning',
					sprintf( 'HTTP 429 rate limited on attempt %d; backing off.', $attempt ),
					array(
						'url'                 => $url,
						'attempt'             => $attempt,
						'http_status'         => $code,
						'rate_limit_hits'     => (string) $rate_limit_hits,
						'retry_after'         => $retry_after_header,
						'retry_after_present' => '' !== $retry_after_header ? '1' : '',
						'run_id'              => $this->get_current_run_id(),
					)
				);
			}

			if ( $attempt >= $max_retries ) {
				break;
			}

			$this->http_backoff( $attempt, $retry_after );
		}

		$message = 'Resposta HTTP ' . $last_code . ' ao buscar URL apos ' . (int) $max_retries . ' tentativas.';
		throw new Conexao_Source_Fetch_Exception( $message, $last_code );
	}

	/**
	 * Tolerant polite fetch: returns an empty string instead of throwing.
	 *
	 * Used for optional pages (pagination continuation, detail enrichment)
	 * where a single failed request should not abort the whole source run.
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args Optional extra wp_remote_get args.
	 * @return string HTML body or empty string on failure.
	 */
	protected function fetch_html_polite_or_empty( $url, $args = array() ) {
		try {
			return $this->fetch_html_polite( $url, $args );
		} catch ( Conexao_Source_Fetch_Exception $e ) {
			return '';
		}
	}
}
