<?php
/**
 * Eventbrite request pacing and rate-limit policy (Stage G5).
 *
 * ## Why this class exists
 *
 * Before G5 the Eventbrite client had two independent, hard-coded waits: a
 * 1-second polite delay between pages and a 2/4/8-second exponential backoff
 * between retries. Both were fixed constants, and neither knew anything about
 * rate limiting as a *condition*.
 *
 * The measured baseline shows what that produced (see
 * `docs/reports/2026-10-04-eventbrite-rate-limit-strategy.md`):
 *
 *   - a 49-page Cork walk fetched 7 pages, then received HTTP 429;
 *   - the 429 page was retried three times at ~2 s and ~4 s and stayed 429;
 *   - the walk then moved on to the NEXT page, which was also 429;
 *   - and in the full 56-source run, once the first 429 appeared, **24 of 24**
 *     remaining Eventbrite discovery paths returned 429 on every attempt.
 *
 * So the failure mode was not "one page was unlucky". It was: keep asking a
 * rate-limited upstream the same question, one page after another, until the
 * 45-second source budget happens to run out. That is a request storm, and it
 * is exactly what a well-behaved client must not do.
 *
 * ## The policy
 *
 * This class is the single place where outbound Eventbrite timing is decided.
 * Handlers call it; they never call `sleep()` themselves.
 *
 * 1. **Page pacing** — a configurable inter-request delay
 *    (`PAGE_DELAY_SECONDS`), derived from the measured baseline.
 * 2. **Retry pacing** — unchanged semantics: bounded exponential backoff,
 *    overridden by a bounded, hard-capped `Retry-After` when present. This
 *    class does NOT reimplement G4B's parser; it consumes its result.
 * 3. **Rate-limit cooldown** — HTTP 429 enters an explicit *rate-limited*
 *    state. The state is what decides the next step (retry after cooldown, or
 *    stop), so this is a state machine, not an if-statement.
 *
 * ## Rate limiting is source-wide, and that is measured, not assumed
 *
 * The G5 brief requires evidence before a cross-source cooldown. The measured
 * full-run trace is unambiguous: after the first 429, all 24 remaining
 * Eventbrite discovery paths were 100 % 429 on every attempt. Retrying Cork
 * then Donegal then Dublin is therefore not three independent problems — it is
 * one problem asked three times.
 *
 * A **run-scoped shared cooldown** (`begin_run()` / `is_rate_limited()`) is
 * kept for that reason. It is scoped to the in-memory run exactly like
 * `Conexao_Request_Budget` and `Conexao_Request_Reuse`: no option, no
 * transient, no table, no scheduler, nothing that survives the process.
 *
 * ## Determinism
 *
 * Sleep is injectable (`set_sleeper()`) and time is injectable (`set_clock()`),
 * so every timing rule here is asserted directly in tests without a real sleep.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Eventbrite pacing and rate-limit policy.
 */
class Conexao_Eventbrite_Pacing {

	/**
	 * Minimum delay between two outbound Eventbrite requests (seconds).
	 *
	 * ## How this value was chosen
	 *
	 * On the measured G4 baseline Eventbrite began returning 429 after roughly
	 * 36 Eventbrite requests at a median 2.85-second interval: with request
	 * latency around 1.9 s, the old 1-second delay still put the importer near
	 * ~20 requests/minute of sustained traffic against the listing endpoint.
	 *
	 * The brief asks for the *smallest conservative* pacing supported by
	 * evidence. Doubling the inter-request floor from 1 s to 2 s takes the
	 * sustained rate down materially while keeping a 49-page walk inside a
	 * realistic operator session. It is a measured, minimal step — not an
	 * invented "safe" number, and not a multi-minute stall.
	 *
	 * Filterable for deterministic tests.
	 *
	 * @var int
	 */
	const PAGE_DELAY_SECONDS = 2;

	/**
	 * Base cooldown before the first rate-limit retry (seconds).
	 *
	 * Deliberately larger than the G4 general backoff base of 2 s: the G4
	 * measurement showed 2 s and 4 s were both insufficient against a real
	 * Eventbrite 429, so rate limiting gets its own deterministic ladder rather
	 * than reusing the transient-failure schedule.
	 *
	 * @var int
	 */
	const RATE_LIMIT_BASE_SECONDS = 20;

	/**
	 * Hard ceiling on any single rate-limit cooldown (seconds).
	 *
	 * Eventbrite must never be able to dictate the length of the process, so
	 * this bounds both the computed ladder and any `Retry-After` value.
	 *
	 * @var int
	 */
	const MAX_COOLDOWN_SECONDS = 60;

	/**
	 * How many consecutive 429s one page may accumulate before the source stops.
	 *
	 * Tied to the existing `Conexao_Eventbrite_Client::MAX_RETRIES` ceiling of
	 * 3, as the brief requires. A page that burns all three attempts on 429 and
	 * is still 429 will not succeed on a fourth: the client fails the page, and
	 * this policy decides whether the *source* keeps paginating past it.
	 *
	 * @var int
	 */
	const MAX_RATE_LIMIT_RETRIES = 3;

	/**
	 * Sleep abstraction (null in production = real sleep).
	 *
	 * @var callable|null
	 */
	protected $sleeper = null;

	/**
	 * Clock abstraction (null in production = real microtime).
	 *
	 * @var callable|null
	 */
	protected $clock = null;

	/**
	 * Delays this instance applied, in order, with their reason.
	 *
	 * @var array[]
	 */
	protected $applied_delays = array();

	/**
	 * Number of HTTP 429 responses this instance observed.
	 *
	 * @var int
	 */
	protected $rate_limit_hits = 0;

	/**
	 * Total seconds spent cooling down in this instance.
	 *
	 * @var float
	 */
	protected $cooldown_seconds = 0.0;

	/**
	 * Whether this instance entered the rate-limited state.
	 *
	 * @var bool
	 */
	protected $rate_limited = false;

	/**
	 * Whether a `Retry-After` header was present on the last 429.
	 *
	 * @var bool
	 */
	protected $last_retry_after_present = false;

	/**
	 * Whether the last `Retry-After` was reduced by the hard cap.
	 *
	 * @var bool
	 */
	protected $last_retry_after_capped = false;
// -------------------------------------------------------------------------
	// Run-scoped shared state.
	//
	// Static, in-memory, and reset by begin_run(). Exactly the same lifetime
	// discipline as Conexao_Request_Budget / Conexao_Request_Reuse: nothing is
	// persisted, so there is no cross-environment or cross-run leakage.
	// -------------------------------------------------------------------------

	/**
	 * Whether the current run has already been rate limited.
	 *
	 * @var bool
	 */
	protected static $run_rate_limited = false;

	/**
	 * Source that first hit the run-wide rate limit.
	 *
	 * @var string
	 */
	protected static $run_rate_limited_source = '';

	/**
	 * Sources that were skipped because the run was already rate limited.
	 *
	 * @var string[]
	 */
	protected static $run_skipped_sources = array();

	/**
	 * The one pacing policy instance for the current run.
	 *
	 * @var Conexao_Eventbrite_Pacing|null
	 */
	protected static $shared = null;

	/**
	 * The run's shared pacing policy, created on first use.
	 *
	 * Shared so the total cooldown and the rate-limit state are consistent
	 * across every Eventbrite source in one importer run.
	 *
	 * @return Conexao_Eventbrite_Pacing
	 */
	public static function get_shared() {
		if ( ! self::$shared instanceof self ) {
			self::$shared = new self();
		}

		return self::$shared;
	}

	/**
	 * Replace the shared policy (tests inject a deterministic one).
	 *
	 * @param Conexao_Eventbrite_Pacing|null $pacing Policy, or null to reset.
	 * @return void
	 */
	public static function set_shared( $pacing ) {
		self::$shared = $pacing instanceof self ? $pacing : null;
	}

	/**
	 * Open a fresh rate-limit scope for a new run.
	 *
	 * Resets the run flags AND the shared instance's accumulated counters, so
	 * one run can never inherit another run's rate-limit state.
	 *
	 * @return void
	 */
	public static function begin_run() {
		self::$run_rate_limited        = false;
		self::$run_rate_limited_source = '';
		self::$run_skipped_sources    = array();

		self::set_shared( null );
	}

	/**
	 * Whether the current run has already been rate limited.
	 *
	 * @return bool
	 */
	public static function is_rate_limited() {
		return (bool) self::$run_rate_limited;
	}

	/**
	 * The source that first tripped the run-wide rate limit.
	 *
	 * @return string Source ID, or an empty string.
	 */
	public static function get_rate_limited_source() {
		return (string) self::$run_rate_limited_source;
	}

	/**
	 * Eventbrite sources skipped because the run was already rate limited.
	 *
	 * @return string[]
	 */
	public static function get_skipped_sources() {
		return array_values( self::$run_skipped_sources );
	}

	/**
	 * Record that this run hit a rate limit, attributed to $source_id.
	 *
	 * @param string $source_id Source that observed the 429s.
	 * @return void
	 */
	public static function record_run_rate_limit( $source_id ) {
		if ( ! self::$run_rate_limited ) {
			self::$run_rate_limited        = true;
			self::$run_rate_limited_source = (string) $source_id;
		}
	}

	/**
	 * Record that $source_id was skipped without being attempted.
	 *
	 * @param string $source_id Source that was skipped.
	 * @return void
	 */
	public static function record_skipped_source( $source_id ) {
		$source_id = (string) $source_id;

		if ( ! in_array( $source_id, self::$run_skipped_sources, true ) ) {
			self::$run_skipped_sources[] = $source_id;
		}
	}

	/**
	 * Inject a sleeper, so tests assert timing policy without waiting.
	 *
	 * @param callable|null $sleeper Callable(float $seconds): void.
	 * @return void
	 */
	public function set_sleeper( $sleeper ) {
		$this->sleeper = is_callable( $sleeper ) ? $sleeper : null;
	}

	/**
	 * Inject a clock, so tests can reason about budgets deterministically.
	 *
	 * @param callable|null $clock Callable(): float (microtime-like seconds).
	 * @return void
	 */
	public function set_clock( $clock ) {
		$this->clock = is_callable( $clock ) ? $clock : null;
	}

	/**
	 * Current time in seconds, honouring an injected clock.
	 *
	 * Public so a source handler measures its time budget through the same
	 * deterministic clock the pacing policy uses.
	 *
	 * @return float
	 */
	public function now() {
		return null !== $this->clock ? (float) call_user_func( $this->clock ) : microtime( true );
	}

	/**
	 * The inter-request pacing delay (seconds), filterable for tests.
	 *
	 * @return float
	 */
	public function get_page_delay_seconds() {
		return (float) apply_filters(
			'conexao_eventbrite_page_delay_seconds',
			self::PAGE_DELAY_SECONDS
		);
	}

	/**
	 * The cooldown (seconds) before retry $retry_number of a rate-limited page.
	 *
	 * A deterministic, bounded ladder — 20 s, then 40 s — capped at
	 * self::MAX_COOLDOWN_SECONDS. Deliberately NOT unbounded exponential
	 * backoff: the brief forbids letting the process grow without limit, and
	 * the measured data shows a rate-limited page exhausts its retry budget
	 * long before such a ladder could finish.
	 *
	 * @param int        $retry_number 1-based retry counter for this page.
	 * @param float|null $retry_after Parsed, already-capped Retry-After, or null.
	 * @return float Seconds to wait.
	 */
	public function get_cooldown_seconds( $retry_number, $retry_after = null ) {
		$this->last_retry_after_present = ( null !== $retry_after );
		$this->last_retry_after_capped   = false;

		// A `Retry-After` is an explicit upstream instruction: honour it, but
		// only after the hard cap, so Eventbrite cannot dictate our runtime.
		if ( null !== $retry_after ) {
			$requested = (float) $retry_after;
			$capped    = min( $requested, (float) self::MAX_COOLDOWN_SECONDS );

			if ( $requested > self::MAX_COOLDOWN_SECONDS ) {
				$this->last_retry_after_capped = true;
			}

			return $capped;
		}

		$retry_number = max( 1, (int) $retry_number );

		return min(
			self::RATE_LIMIT_BASE_SECONDS * $retry_number,
			(float) self::MAX_COOLDOWN_SECONDS
		);
	}

	/**
	 * Wait the polite inter-request delay between two pages.
	 *
	 * @return float Seconds requested.
	 */
	public function pace_page_request() {
		return $this->sleep_for( $this->get_page_delay_seconds(), 'page_pacing' );
	}

	/**
	 * Enter the rate-limited state and wait the cooldown for retry $retry_number.
	 *
	 * This is the single place a 429 turns into a wait. It records the hit,
	 * marks the run-wide rate-limit state, and applies exactly one bounded
	 * cooldown. It never sleeps more than MAX_COOLDOWN_SECONDS, and never sleeps
	 * at all when $seconds is zero.
	 *
	 * @param int        $retry_number 1-based retry counter for this page.
	 * @param float|null $retry_after Parsed, already-capped Retry-After, or null.
	 * @return float Seconds requested.
	 */
	public function apply_rate_limit_cooldown( $retry_number, $retry_after = null ) {
		++$this->rate_limit_hits;
		$this->rate_limited = true;

		$seconds = $this->get_cooldown_seconds( $retry_number, $retry_after );

		$this->cooldown_seconds += $seconds;

		return $this->sleep_for( $seconds, 'rate_limit_cooldown' );
	}

	/**
	 * Apply a delay, recording it and honouring an injected sleeper.
	 *
	 * @param float  $seconds Delay in seconds.
	 * @param string $reason  Why the delay was applied (for assertions).
	 * @return float Seconds requested.
	 */
	protected function sleep_for( $seconds, $reason ) {
		$seconds = (float) $seconds;

		$this->applied_delays[] = array(
			'reason'  => (string) $reason,
			'seconds' => $seconds,
		);

		if ( $seconds <= 0 ) {
			return 0.0;
		}

		if ( null !== $this->sleeper ) {
			call_user_func( $this->sleeper, $seconds );
			return $seconds;
		}

		sleep( (int) ceil( $seconds ) );

		return $seconds;
	}

	/**
	 * Whether this instance observed a rate limit.
	 *
	 * Distinct from the static run-level is_rate_limited(): this one describes
	 * a single source's walk, the static one the whole importer run.
	 *
	 * @return bool
	 */
	public function was_rate_limited() {
		return (bool) $this->rate_limited;
	}

	/**
	 * Number of 429 responses this instance observed.
	 *
	 * @return int
	 */
	public function get_rate_limit_hits() {
		return (int) $this->rate_limit_hits;
	}

	/**
	 * Total cooldown seconds this instance applied.
	 *
	 * Cooldown consumes NO outbound request budget: it sends nothing.
	 *
	 * @return float
	 */
	public function get_cooldown_seconds_applied() {
		return (float) $this->cooldown_seconds;
	}

	/**
	 * Whether the last observed 429 carried a `Retry-After` header.
	 *
	 * @return bool
	 */
	public function last_retry_after_was_present() {
		return (bool) $this->last_retry_after_present;
	}

	/**
	 * Whether the last observed `Retry-After` was reduced by the hard cap.
	 *
	 * @return bool
	 */
	public function last_retry_after_was_capped() {
		return (bool) $this->last_retry_after_capped;
	}

	/**
	 * Every delay this instance applied, in order.
	 *
	 * @return array[]
	 */
	public function get_applied_delays() {
		return $this->applied_delays;
	}

	/**
	 * The reasons of every applied delay, in order (assertion convenience).
	 *
	 * @return string[]
	 */
	public function get_applied_delay_reasons() {
		return array_column( $this->applied_delays, 'reason' );
	}

	/**
	 * Pacing / rate-limit summary for diagnostics.
	 *
	 * @return array
	 */
	public function get_stats() {
		return array(
			'rate_limited'             => (bool) $this->rate_limited,
			'rate_limit_hits'          => (int) $this->rate_limit_hits,
			'cooldown_seconds'         => (float) $this->cooldown_seconds,
			'page_delay_seconds'       => $this->get_page_delay_seconds(),
			'last_retry_after_present' => (bool) $this->last_retry_after_present,
			'last_retry_after_capped'  => (bool) $this->last_retry_after_capped,
			'applied_delays'           => count( $this->applied_delays ),
		);
	}
}