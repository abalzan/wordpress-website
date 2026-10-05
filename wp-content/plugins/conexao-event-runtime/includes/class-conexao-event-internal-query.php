<?php
/**
 * Internal reconciliation query context for event imports.
 *
 * @package Conexao_Event_Runtime
 */

defined( 'ABSPATH' ) || exit;

/**
 * Narrow, token-guarded exemption scope for importer identity lookups.
 *
 * `Conexao_Event_Runtime::filter_public_event_queries()` is a *public
 * visibility boundary*: it excludes `source_not_found` (and draft / expired /
 * rejected) events from every frontend event query. That rule is correct and
 * must not be weakened.
 *
 * The local importer, however, must be able to SEE a `source_not_found` record
 * when it resolves a source identity, so a reappearing event is matched and
 * restored (class-event-importer.php upsert_event() -> the restore branch)
 * instead of being inserted a second time as a duplicate.
 *
 * This class is the narrow, explicit, fail-closed bridge for that need. The
 * importer wraps its own identity lookup in `run()`; every WP_Query built
 * inside that scope carries the sentinel below, and the public filter skips
 * only those queries.
 *
 * Why this is fail-closed and not a bypass a frontend request can trigger:
 *
 *  1. The sentinel is a random per-process token generated in PHP. It is never
 *     derived from a query string, header, cookie or any request input, so no
 *     public HTTP request can predict or supply it.
 *  2. The token must ALSO match a private static scope that only the importer
 *     can open, through `run()`. A query that merely happens to carry a
 *     correctly-shaped argument is still gated, because the scope is closed.
 *  3. The scope is strictly nested and always closed by a `finally` block, so
 *     a thrown exception cannot leak an open scope to later public queries.
 *  4. `filter_public_event_queries()` remains the boundary; this only narrows
 *     its exemption to queries the importer itself constructed, in-process.
 *
 * A public request therefore cannot reach `source_not_found` through this path
 * (proved by test-source-not-found-reconciliation.php, Test 8).
 */
class Conexao_Event_Internal_Query {

	/**
	 * WP_Query argument the importer sets on its own identity lookups.
	 *
	 * Namespaced so it cannot collide with a core or theme query var.
	 */
	const QUERY_ARG = 'conexao_internal_reconciliation';

	/**
	 * Per-process random token a query must carry to be exempt.
	 *
	 * @var string
	 */
	private static $token = '';

	/**
	 * Nesting depth of the open internal scope (0 = closed).
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * The private per-process token, generated once and never exposed to input.
	 *
	 * @return string
	 */
	private static function token() {
		if ( '' === self::$token ) {
			self::$token = wp_generate_password( 32, false, false );
		}

		return self::$token;
	}

	/**
	 * Run a callback inside the importer-internal reconciliation scope.
	 *
	 * Every WP_Query constructed by the callback is marked with the sentinel,
	 * so the public filter exempts exactly those queries. The scope is always
	 * closed, including on an exception.
	 *
	 * @param callable $callback Reconciliation work to run.
	 * @return mixed The callback's return value.
	 */
	public static function run( $callback ) {
		self::$depth++;

		try {
			return call_user_func( $callback );
		} finally {
			// Clamped so a stray extra close can never drive the depth
			// negative and leave the scope permanently disabled (fail-closed).
			self::$depth = self::$depth > 0 ? self::$depth - 1 : 0;
		}
	}

	/**
	 * Is an importer-internal reconciliation scope currently open?
	 *
	 * @return bool
	 */
	public static function is_open() {
		return self::$depth > 0;
	}

	/**
	 * Mark a WP_Query argument set as importer-internal reconciliation.
	 *
	 * @param array $args Existing WP_Query arguments.
	 * @return array Arguments carrying the sentinel.
	 */
	public static function mark_query_args( array $args ) {
		$args[ self::QUERY_ARG ] = self::token();

		return $args;
	}

	/**
	 * Is this query an importer-internal reconciliation query?
	 *
	 * Both conditions must hold: the internal scope is open AND the query
	 * carries this process's private token. Either alone is not enough, which
	 * is what keeps the exemption unreachable from a public request.
	 *
	 * @param mixed $query Query under inspection (tolerates a non-WP_Query).
	 * @return bool
	 */
	public static function is_reconciliation_query( $query ) {
		if ( ! self::is_open() ) {
			return false;
		}

		if ( ! $query instanceof WP_Query ) {
			return false;
		}

		$supplied = $query->get( self::QUERY_ARG );

		if ( ! is_string( $supplied ) || '' === $supplied ) {
			return false;
		}

		return hash_equals( self::token(), $supplied );
	}
}
