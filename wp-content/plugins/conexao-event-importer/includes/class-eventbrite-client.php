<?php
/**
 * Eventbrite discovery client.
 *
 * Fetches Eventbrite's public county discovery pages and returns the raw HTML.
 * Implements conservative request handling with retry/backoff for transient
 * failures (429, 500, 503).
 *
 * This client ONLY performs server-side GET requests to the public discovery
 * page. It does NOT use Eventbrite's undocumented internal search API.
 *
 * Each client instance is bound to a source ID so log entries are attributed
 * to the correct county source (independent log streams per source).
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Eventbrite_Client {

	/**
	 * Default discovery URL for Laois events (legacy fallback).
	 *
	 * @var string
	 */
	const DEFAULT_DISCOVERY_URL = 'https://www.eventbrite.ie/d/ireland--laois/all-events/';

	/**
	 * Maximum number of retries per page.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Base delay in seconds for exponential backoff.
	 *
	 * @var int
	 */
	const BASE_BACKOFF_SECONDS = 2;

	/**
	 * Delay between page requests in seconds (1–2s recommended).
	 *
	 * @var int
	 */
	const REQUEST_DELAY_SECONDS = 1;

	/**
	 * Browser-like User-Agent.
	 *
	 * @var string
	 */
	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	/**
	 * Source ID for log attribution.
	 *
	 * @var string
	 */
	protected $source_id;

	/**
	 * Constructor.
	 *
	 * @param string $source_id Source ID for log attribution (e.g. 'eventbrite_laois').
	 */
	public function __construct( $source_id = 'eventbrite' ) {
		$this->source_id = $source_id;
	}

	/**
	 * Last HTTP status observed by the most recent fetch_page() call.
	 *
	 * The client returns an empty string for every failure mode, so the
	 * caller cannot otherwise tell a 429 from a 403 from a dead socket.
	 * Exposing the status lets the source handler raise a
	 * Conexao_Source_Fetch_Exception carrying the real code instead of
	 * guessing (Stage G1: a failed first page is a failed source, never an
	 * empty source).
	 *
	 * @var int
	 */
	protected $last_http_status = 0;

	/**
	 * Get the HTTP status observed by the most recent fetch_page() call.
	 *
	 * @return int HTTP status code, 0 for a network-level failure.
	 */
	public function get_last_http_status() {
		return (int) $this->last_http_status;
	}

	/**
	 * Get the time budget (seconds) for one pagination walk.
	 *
	 * Filterable so a test can drive the truncation branch deterministically.
	 * The default is unchanged, so production behaviour is identical.
	 *
	 * @return float Seconds.
	 */
	public function get_time_budget_seconds() {
		return (float) apply_filters(
			'conexao_eventbrite_time_budget_seconds',
			Conexao_Source_Eventbrite::TIME_BUDGET_SECONDS
		);
	}

	/**
	 * Get the backoff (seconds) before retry attempt $attempt.
	 *
	 * Filterable for deterministic tests; the default reproduces the existing
	 * fixed 2/4/8 second exponential backoff exactly.
	 *
	 * @param int $attempt Current attempt number (1-based).
	 * @return float Seconds.
	 */
	protected function get_backoff_seconds( $attempt ) {
		return (float) apply_filters(
			'conexao_eventbrite_backoff_seconds',
			self::BASE_BACKOFF_SECONDS * pow( 2, $attempt - 1 ),
			$attempt
		);
	}

	/**
	 * Get the polite delay (seconds) between page requests.
	 *
	 * Filterable for deterministic tests; the default is unchanged.
	 *
	 * @return float Seconds.
	 */
	protected function get_request_delay_seconds() {
		return (float) apply_filters(
			'conexao_eventbrite_request_delay_seconds',
			self::REQUEST_DELAY_SECONDS
		);
	}

	/**
	 * Fetch a discovery page.
	 *
	 * @param string $url  Page URL.
	 * @param int    $page Page number (1-based).
	 * @return string HTML body or empty string on failure.
	 */
	public function fetch_page( $url, $page = 1 ) {
		$page_url = $this->build_page_url( $url, $page );

		$this->last_http_status = 0;

		for ( $attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++ ) {
			$response = $this->request_page( $page_url );

			if ( is_wp_error( $response ) ) {
				Conexao_Import_Log::add(
					$this->source_id,
					'error',
					sprintf(
						/* translators: %s: error message */
						__( 'Eventbrite request failed: %s', 'conexao-event-importer' ),
						$response->get_error_message()
					),
					array(
						'url'         => $page_url,
						'attempt'     => $attempt,
						'http_status' => 0,
						'run_id'      => apply_filters( 'conexao_event_importer_current_run_id', '' ),
					)
				);
				$this->backoff( $attempt );
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );

			$this->last_http_status = (int) $code;

			if ( 200 === (int) $code ) {
				return wp_remote_retrieve_body( $response );
			}

			if ( ! self::is_retryable_status( $code ) ) {
				// Not transient: report it and stop. Retrying a 401/403/404
				// turns an authorisation or not-found condition into repeated
				// traffic without ever changing the outcome.
				Conexao_Import_Log::add(
					$this->source_id,
					'error',
					$this->get_non_retryable_message( (int) $code, $page_url ),
					array(
						'url'         => $page_url,
						'http_status' => (int) $code,
						'run_id'      => apply_filters( 'conexao_event_importer_current_run_id', '' ),
					)
				);
				return '';
			}

			// G4B: transient failure. Honour a bounded Retry-After when the
			// upstream sent one, otherwise fall back to the existing
			// exponential backoff. self::MAX_RETRIES remains authoritative, so
			// this is never an unbounded retry.
			$retry_after = self::parse_retry_after(
				wp_remote_retrieve_header( $response, 'retry-after' )
			);

			Conexao_Import_Log::add(
				$this->source_id,
				'warning',
				null === $retry_after
					? sprintf(
						/* translators: %d: HTTP status code */
						__( 'Eventbrite returned HTTP %d. Retrying with a short delay.', 'conexao-event-importer' ),
						$code
					)
					: sprintf(
						/* translators: 1: HTTP status code, 2: seconds to wait */
						__( 'Eventbrite returned HTTP %1$d with Retry-After. Waiting %2$ds before the next attempt (bounded).', 'conexao-event-importer' ),
						$code,
						(int) ceil( $retry_after )
					),
				array(
					'url'         => $page_url,
					'attempt'     => $attempt,
					'http_status' => (int) $code,
					'retry_after' => $retry_after,
					'run_id'      => apply_filters( 'conexao_event_importer_current_run_id', '' ),
				)
			);

			$this->backoff( $attempt, $retry_after );
		}

		Conexao_Import_Log::add(
			$this->source_id,
			'error',
			sprintf(
				/* translators: %d: number of retries */
				__( 'Eventbrite could not be reached after %d attempts. The discovery page may be temporarily unavailable, or Eventbrite may be rate-limiting this site.', 'conexao-event-importer' ),
				self::MAX_RETRIES
			),
			array(
				'url'    => $page_url,
				'run_id' => apply_filters( 'conexao_event_importer_current_run_id', '' ),
			)
		);

		return '';
	}
	/**
	 * Perform one discovery-page request through the reuse-aware transport.
	 *
	 * The response is memoised per run (G4A) and charged against the outbound
	 * request budget (G4C) exactly as a source handler's GET is. Only a 2xx
	 * response is stored, so a failed attempt can never be replayed later as
	 * if it were page content.
	 *
	 * @param string $page_url Fully built page URL.
	 * @return array|WP_Error
	 */
	protected function request_page( $page_url ) {
		$args = array(
			'timeout'    => 30,
			'user-agent' => self::USER_AGENT,
			'headers'    => array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'en-IE,en;q=0.9',
			),
		);

		$key = Conexao_Request_Reuse::make_key( 'GET', $page_url, $args );

		if ( Conexao_Request_Reuse::is_active() ) {
			$cached = Conexao_Request_Reuse::get( $key );
			if ( null !== $cached ) {
				Conexao_Request_Budget::record_cache_hit();
				return $cached;
			}
		}

		if ( ! Conexao_Request_Budget::consume( 1 ) ) {
			return new WP_Error(
				'conexao_request_budget_exhausted',
				sprintf(
					/* translators: %d: request ceiling */
					__( 'The importer outbound request budget of %d requests was exhausted; no further request was sent.', 'conexao-event-importer' ),
					Conexao_Request_Budget::get_limit()
				)
			);
		}

		$response = wp_remote_get( $page_url, $args );

		if ( Conexao_Request_Reuse::is_active() && is_array( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 200 && $code < 300 ) {
				Conexao_Request_Reuse::set( $key, $response );
			}
		}

		return $response;
	}

	/**
	 * The diagnostic message for a non-retryable HTTP response.
	 *
	 * The 401/403/404 wording is preserved from the pre-G4 client: those are
	 * operator-visible explanations of an authentication, WAF or moved-page
	 * refusal, and changing them would change what a maintainer reads.
	 *
	 * @param int    $code     HTTP status code.
	 * @param string $page_url Requested URL.
	 * @return string
	 */
	protected function get_non_retryable_message( $code, $page_url ) {
		switch ( $code ) {
			case 401:
				return __( 'Eventbrite returned 401 Unauthorized. The discovery page may require authentication.', 'conexao-event-importer' );

			case 403:
				return __( 'Eventbrite returned 403 Forbidden. The discovery page refused this request.', 'conexao-event-importer' );

			case 404:
				return sprintf(
					/* translators: %s: URL */
					__( 'Eventbrite returned 404 Not Found for %s. The discovery URL may have changed.', 'conexao-event-importer' ),
					$page_url
				);
		}

		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'Eventbrite returned an unexpected HTTP %d response.', 'conexao-event-importer' ),
			$code
		);
	}

	/**
	 * Build a page URL with the ?page=N parameter.
	 *
	 * @param string $base_url Base discovery URL.
	 * @param int    $page     Page number (1-based).
	 * @return string
	 */
	public function build_page_url( $base_url, $page ) {
		if ( $page <= 1 ) {
			return $base_url;
		}

		$separator = ( false === strpos( $base_url, '?' ) ) ? '?' : '&';
		return $base_url . $separator . 'page=' . (int) $page;
	}

	/**
	 * Maximum delay (seconds) an upstream `Retry-After` may impose.
	 *
	 * The G4 brief requires an explicit maximum: a remote server must never be
	 * able to make the importer sleep for an unbounded period. The cap is
	 * deliberately well above a legitimate rate-limit hint (typical values are
	 * 1–30 s) and far below anything that would stall a run.
	 *
	 * @var int
	 */
	const MAX_RETRY_AFTER_SECONDS = 60;

	/**
	 * HTTP status codes that are retried.
	 *
	 * 429 (rate limited), 500/502/503/504 (server-side failures) are transient
	 * and worth another attempt, bounded by self::MAX_RETRIES. 401, 403 and 404
	 * are NOT here: an authentication failure or a missing resource does not
	 * become true by being asked again, and retrying them converts an
	 * authorisation problem into repeated traffic. 408 keeps its existing
	 * non-retryable policy.
	 *
	 * @var int[]
	 */
	const RETRYABLE_STATUSES = array( 429, 500, 502, 503, 504 );

	/**
	 * Sleep abstraction, so tests observe the delay without waiting for it.
	 *
	 * @var callable|null
	 */
	protected $sleeper = null;

	/**
	 * Delays actually applied this client instance (for assertions).
	 *
	 * @var float[]
	 */
	protected $applied_delays = array();

	/**
	 * Inject a sleeper, for deterministic backoff tests.
	 *
	 * The callable receives the number of seconds the client *would* sleep.
	 * Production never injects one and therefore really sleeps.
	 *
	 * @param callable|null $sleeper Callable(int $seconds): void.
	 * @return void
	 */
	public function set_sleeper( $sleeper ) {
		$this->sleeper = is_callable( $sleeper ) ? $sleeper : null;
	}

	/**
	 * The delays this client applied, in order.
	 *
	 * @return float[]
	 */
	public function get_applied_delays() {
		return $this->applied_delays;
	}

	/**
	 * Parse a `Retry-After` header into a bounded delay in seconds.
	 *
	 * Supports both standard forms the current HTTP layer can parse safely:
	 * delta-seconds ("120") and an HTTP-date. An unparseable, negative or
	 * non-numeric value yields null, so the caller falls back to the existing
	 * exponential backoff. The result is always clamped to
	 * self::MAX_RETRY_AFTER_SECONDS, so an upstream server can never impose an
	 * unbounded sleep.
	 *
	 * @param string $header Raw header value.
	 * @param int    $now    Current unix timestamp (injected for determinism).
	 * @return float|null Seconds to wait, or null when the header is unusable.
	 */
	public static function parse_retry_after( $header, $now = 0 ) {
		$header = trim( (string) $header );

		if ( '' === $header ) {
			return null;
		}

		$now = $now > 0 ? (int) $now : time();

		// Form 1: delta-seconds.
		if ( preg_match( '/^\d+$/', $header ) ) {
			$seconds = (float) $header;
		} else {
			// Form 2: HTTP-date.
			$timestamp = strtotime( $header );
			if ( false === $timestamp ) {
				return null;
			}
			$seconds = (float) ( $timestamp - $now );
		}

		if ( $seconds < 0 ) {
			$seconds = 0.0;
		}

		return min( $seconds, (float) self::MAX_RETRY_AFTER_SECONDS );
	}

	/**
	 * Whether an HTTP status is retried.
	 *
	 * @param int $code HTTP status code.
	 * @return bool
	 */
	public static function is_retryable_status( $code ) {
		return in_array( (int) $code, self::RETRYABLE_STATUSES, true );
	}

	/**
	 * Sleep between requests to be respectful of Eventbrite's servers.
	 */
	public function delay() {
		$seconds = $this->get_request_delay_seconds();
		$this->sleep_for( $seconds );
	}

	/**
	 * Exponential backoff between retries.
	 *
	 * @param int        $attempt     Current attempt number (1-based).
	 * @param float|null $retry_after Parsed, already-capped Retry-After delay.
	 */
	protected function backoff( $attempt, $retry_after = null ) {
		if ( null !== $retry_after ) {
			$this->sleep_for( $retry_after );
			return;
		}

		$this->sleep_for( $this->get_backoff_seconds( $attempt ) );
	}

	/**
	 * Apply a delay, recording it and honouring an injected sleeper.
	 *
	 * @param float $seconds Delay in seconds.
	 * @return void
	 */
	protected function sleep_for( $seconds ) {
		$seconds = (float) $seconds;

		if ( $seconds <= 0 ) {
			return;
		}

		$this->applied_delays[] = $seconds;

		if ( null !== $this->sleeper ) {
			call_user_func( $this->sleeper, $seconds );
			return;
		}

		sleep( (int) ceil( $seconds ) );
	}
}
