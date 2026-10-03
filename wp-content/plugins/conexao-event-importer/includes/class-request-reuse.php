<?php
/**
 * Per-run GET/HEAD response reuse store.
 *
 * ## What this is
 *
 * A **per-run** memoisation of outbound HTTP *responses* for safe, deterministic
 * fetches. Within a single importer run, two requests identical in every
 * response-relevant respect must produce the same bytes; fetching twice is pure
 * waste.
 *
 * ## What this is NOT
 *
 * - **Not a second HTTP client.** It performs no requests. The existing
 *   transport (`wp_remote_get` inside `Conexao_Source_Base::fetch_html()` and
 *   `Conexao_Eventbrite_Client::fetch_page()`) is unchanged; this class only
 *   decides whether that transport needs to run at all.
 * - **Not a persistent cache.** The store lives in memory for the lifetime of
 *   one run and is reset by the engine at the start of each run. Nothing is
 *   written to options, transients, the database or disk, so a response from a
 *   previous run can never satisfy a later run.
 * - **Not a general memoiser.** Only safe, deterministic GET/HEAD source-fetch
 *   requests are eligible. POST, state-changing requests, requests carrying a
 *   body, and requests with per-request auth context are never cached.
 *
 * ## Cache key
 *
 * The key is built from every response-relevant input the caller passes:
 * method, normalised URL, the response-varying request headers, and the request
 * body. Normalisation is limited to host case and the fragment — neither is sent
 * to the origin — while path, query and scheme case are preserved, so genuinely
 * different targets never collide.
 *
 * ## Correctness
 *
 * A hit returns the *stored response array* exactly as the transport produced
 * it, so it flows through exactly the same downstream code — HTML normalisation,
 * JSON decoding, status handling, exception classification and logging — as a
 * freshly fetched response. A cached response is not a shortcut around the
 * parser: a malformed cached body fails closed exactly as a malformed fresh body
 * does.
 *
 * ## Observability
 *
 * `get_stats()` exposes `hits`, `misses`, `stores` and `bypassed`, so a cache
 * hit (no outbound request) is distinguishable from a miss (outbound request).
 * No per-hit log entry is written.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static per-run response reuse store.
 */
class Conexao_Request_Reuse {

	/**
	 * Cached responses, keyed by the deterministic cache key.
	 *
	 * @var array<string,array>
	 */
	protected static $store = array();

	/**
	 * Whether the store has been initialised for the current run.
	 *
	 * @var bool
	 */
	protected static $initialised = false;

	/**
	 * Counters for observability.
	 *
	 * @var array
	 */
	protected static $stats = array(
		'hits'     => 0,
		'misses'   => 0,
		'stores'   => 0,
		'bypassed' => 0,
	);

	/**
	 * Request headers that can change the response for an otherwise identical
	 * request. These participate in the cache key.
	 *
	 * @var string[]
	 */
	const KEY_HEADERS = array(
		'accept',
		'accept-language',
		'accept-encoding',
		'authorization',
		'cookie',
		'referer',
		'user-agent',
		'x-requested-with',
	);

	/**
	 * Request headers that DISABLE reuse for the request.
	 *
	 * These carry per-request context the importer cannot model (an explicit
	 * Authorization/Cookie, a conditional or ranged fetch). When present the
	 * request is always performed and never stored.
	 *
	 * @var string[]
	 */
	const NO_REUSE_HEADERS = array(
		'authorization',
		'cookie',
		'if-none-match',
		'if-modified-since',
		'range',
	);

	/**
	 * Reset the store and the counters. Called once per importer run.
	 *
	 * @return void
	 */
	public static function begin_run() {
		self::$store       = array();
		self::$initialised = true;
		self::$stats       = array(
			'hits'     => 0,
			'misses'   => 0,
			'stores'   => 0,
			'bypassed' => 0,
		);
	}

	/**
	 * Whether reuse is active for the current scope.
	 *
	 * Reuse is active only once `begin_run()` has run, so a request issued
	 * outside any importer run can never read or write a run's store.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return (bool) apply_filters( 'conexao_request_reuse_enabled', self::$initialised );
	}

	/**
	 * Whether a request may participate in reuse at all.
	 *
	 * Only GET and HEAD are eligible: they are the deterministic source-fetch
	 * verbs the importer uses. A POST, a request carrying a body, or a request
	 * with per-request auth context is never cached.
	 *
	 * @param string $method HTTP method.
	 * @param array  $args   wp_remote_get() arguments.
	 * @return bool
	 */
	public static function is_cacheable( $method, array $args ) {
		$method = strtoupper( trim( (string) $method ) );

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return false;
		}

		// A request body is response-relevant and is never replayed from cache.
		if ( ! empty( $args['body'] ) ) {
			return false;
		}

		// Streaming / ranged requests return different representations.
		if ( ! empty( $args['stream'] ) || ! empty( $args['rlimit'] ) || ! empty( $args['limit_response_size'] ) ) {
			return false;
		}

		foreach ( self::lower_case_headers( $args ) as $name => $value ) {
			if ( in_array( $name, self::NO_REUSE_HEADERS, true ) ) {
				return false;
			}
		}

		return true;
	}
	/**
	 * Build the deterministic cache key for a request.
	 *
	 * Every response-relevant input participates: method, normalised URL, the
	 * response-varying headers, and the body.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Request URL.
	 * @param array  $args   wp_remote_get() arguments.
	 * @return string
	 */
	public static function make_key( $method, $url, array $args ) {
		$headers = array();
		foreach ( self::lower_case_headers( $args ) as $name => $value ) {
			if ( in_array( $name, self::KEY_HEADERS, true ) ) {
				$headers[ $name ] = (string) $value;
			}
		}
		ksort( $headers );

		$parts = array(
			'm' => strtoupper( trim( (string) $method ) ),
			'u' => self::normalize_url( $url ),
			'h' => $headers,
			'b' => isset( $args['body'] ) ? (string) $args['body'] : '',
		);

		return 'conexao-req:' . md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * Look up a stored response.
	 *
	 * @param string $key Cache key.
	 * @return array|null Stored response array, or null on a miss.
	 */
	public static function get( $key ) {
		if ( ! isset( self::$store[ $key ] ) ) {
			++self::$stats['misses'];
			return null;
		}

		++self::$stats['hits'];
		return self::$store[ $key ];
	}

	/**
	 * Store a response for reuse within this run.
	 *
	 * @param string $key      Cache key.
	 * @param array  $response Transport response array.
	 * @return void
	 */
	public static function set( $key, array $response ) {
		self::$store[ $key ] = $response;
		++self::$stats['stores'];
	}

	/**
	 * Record a request that deliberately bypassed reuse.
	 *
	 * @return void
	 */
	public static function record_bypass() {
		++self::$stats['bypassed'];
	}

	/**
	 * Current counters.
	 *
	 * @return array{hits:int,misses:int,stores:int,bypassed:int,entries:int}
	 */
	public static function get_stats() {
		$stats            = self::$stats;
		$stats['entries'] = count( self::$store );
		return $stats;
	}

	/**
	 * Lower-case and normalise the request headers.
	 *
	 * @param array $args wp_remote_get() arguments.
	 * @return array<string,string>
	 */
	protected static function lower_case_headers( array $args ) {
		$headers = array();

		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			foreach ( $args['headers'] as $name => $value ) {
				$headers[ strtolower( trim( (string) $name ) ) ] = is_array( $value ) ? implode( ',', $value ) : (string) $value;
			}
		}

		// user-agent is a top-level arg in the WP HTTP API but a request header
		// in the response contract, so it participates in the key too.
		if ( ! empty( $args['user-agent'] ) ) {
			$headers['user-agent'] = (string) $args['user-agent'];
		}

		return $headers;
	}

	/**
	 * Normalise a URL for the cache key.
	 *
	 * Only host case and the fragment are normalised: neither is transmitted to
	 * the origin, so two URLs differing only there are the same request. Path,
	 * query (including parameter order), scheme case and userinfo are preserved.
	 *
	 * @param string $url Request URL.
	 * @return string
	 */
	protected static function normalize_url( $url ) {
		$url  = trim( (string) $url );
		$hash = strpos( $url, '#' );

		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts ) || empty( $parts['host'] ) ) {
			// Relative or unparseable: use the raw string so distinct values
			// still get distinct keys.
			return $url;
		}

		$normalized = ( isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https' ) . '://';

		if ( ! empty( $parts['user'] ) ) {
			$normalized .= $parts['user'] . ( isset( $parts['pass'] ) ? ':' . $parts['pass'] : '' ) . '@';
		}

		$normalized .= strtolower( $parts['host'] );

		if ( ! empty( $parts['port'] ) ) {
			$normalized .= ':' . (int) $parts['port'];
		}

		$normalized .= isset( $parts['path'] ) ? $parts['path'] : '';

		if ( ! empty( $parts['query'] ) ) {
			$normalized .= '?' . $parts['query'];
		}

		return $normalized;
	}
}