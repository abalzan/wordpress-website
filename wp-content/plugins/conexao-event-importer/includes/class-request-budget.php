<?php
/**
 * Per-run outbound request budget.
 *
 * ## Purpose
 *
 * A single source must not be able to consume the importer's entire request
 * capacity — or unbounded time — within one run. The pre-G4 walks were bounded
 * only by wall-clock time budgets, which bound *duration* but not *request
 * count*: a fast upstream can be asked hundreds of times.
 *
 * ## Accounting
 *
 * The budget counts **outbound requests only**. A response served from the
 * per-run reuse store (G4A) costs nothing, because nothing left the process.
 * This is the correct invariant: the budget exists to limit network pressure, not
 * to limit work.
 *
 * ## Scope
 *
 * The budget is per importer run and is reset by the engine at the start of each
 * `run_source()` / `run_all()`. Nothing is persisted: no option, transient or
 * table row, so there is no cross-run or cross-environment state.
 *
 * ## On exhaustion
 *
 * `consume()` returns false and no request is issued. Callers surface that as
 * the ordinary fetch-failure path, which the G1/G2 machinery already maps to
 * `complete = false` — an **incomplete** enumeration, never a complete one and
 * never an empty success. Events already fetched are preserved, and because the
 * walk is incomplete the engine does not call `mark_missing_events()`.
 *
 * ## Default ceiling
 *
 * The default is chosen from the measured post-G1–G3 runtime baseline, not
 * guessed. See `docs/reports/2026-10-03-event-import-g4.md` §G4C for the numbers
 * and the rationale; the ceiling is comfortably above the largest legitimate
 * observed source and well below a runaway.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static per-run outbound request budget.
 */
class Conexao_Request_Budget {

	/**
	 * Default ceiling on outbound requests per importer run.
	 *
	 * @var int
	 */
	const DEFAULT_LIMIT = 400;

	/**
	 * Requests actually sent to the network this run.
	 *
	 * @var int
	 */
	protected static $used = 0;

	/**
	 * Requests refused because the ceiling was already reached.
	 *
	 * @var int
	 */
	protected static $blocked = 0;

	/**
	 * Whether the budget applies at all this run.
	 *
	 * @var bool
	 */
	protected static $active = false;

	/**
	 * Requests served from the reuse store (not charged).
	 *
	 * @var int
	 */
	protected static $cache_hits = 0;

	/**
	 * Explicit ceiling override for the current run, or null for the default.
	 *
	 * @var int|null
	 */
	protected static $limit = null;

	/**
	 * Start a fresh budget for a new run.
	 *
	 * @param int|null $limit Optional explicit ceiling (tests/diagnostics).
	 * @return void
	 */
	public static function begin_run( $limit = null ) {
		self::$used       = 0;
		self::$blocked    = 0;
		self::$cache_hits = 0;
		self::$active     = ( null === $limit || (int) $limit > 0 );
		self::$limit      = null === $limit ? null : (int) $limit;
	}

	/**
	 * The ceiling currently in force.
	 *
	 * @return int
	 */
	public static function get_limit() {
		$limit = self::$limit;
		return null === $limit
			? (int) apply_filters( 'conexao_event_importer_request_budget', self::DEFAULT_LIMIT )
			: (int) $limit;
	}

	/**
	 * Requests charged so far.
	 *
	 * @return int
	 */
	public static function get_used() {
		return (int) self::$used;
	}

	/**
	 * Requests refused after the ceiling was reached.
	 *
	 * @return int
	 */
	public static function get_blocked() {
		return (int) self::$blocked;
	}

	/**
	 * Whether the budget has been reached.
	 *
	 * @return bool
	 */
	public static function is_exhausted() {
		if ( ! self::$active ) {
			return false;
		}
		return self::$used >= self::get_limit();
	}

	/**
	 * Charge $count outbound requests, if the ceiling allows it.
	 *
	 * @param int $count Number of outbound requests about to be sent.
	 * @return bool True when the requests may proceed, false when blocked.
	 */
	public static function consume( $count = 1 ) {
		$count = max( 0, (int) $count );

		if ( ! self::$active || 0 === $count ) {
			return true;
		}

		$limit = self::get_limit();

		// Never hand out a partial grant: a request that cannot be sent in full
		// is not sent at all, so "exactly N outbound requests" stays exact.
		if ( self::$used + $count > $limit ) {
			self::$blocked += $count;
			return false;
		}

		self::$used += $count;
		return true;
	}

	/**
	 * Record that a request was served from the reuse store.
	 *
	 * @return void
	 */
	public static function record_cache_hit() {
		++self::$cache_hits;
	}

	/**
	 * Current budget state.
	 *
	 * @return array
	 */
	public static function get_stats() {
		return array(
			'limit'      => self::get_limit(),
			'active'     => (bool) self::$active,
			'used'       => (int) self::$used,
			'blocked'    => (int) self::$blocked,
			'cache_hits' => (int) self::$cache_hits,
			'remaining'  => self::$active ? max( 0, self::get_limit() - self::$used ) : null,
			'exhausted'  => self::is_exhausted(),
		);
	}
}