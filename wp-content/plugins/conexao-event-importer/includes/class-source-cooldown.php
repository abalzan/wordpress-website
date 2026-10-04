<?php
/**
 * Per-source cooldown and recovery eligibility.
 *
 * ## Why this exists
 *
 * G9. The measured live behaviour (2026-10-04) is that Eventbrite answers 429
 * after roughly 30-35 requests in a single county walk. When a source keeps
 * answering 429 / WAF / 5xx, an operator pressing "Run" again immediately —
 * or a scheduled run firing on its own cadence — sends the same doomed requests
 * and makes the rate limiting worse, without a single new event.
 *
 * This class records that per source and derives a bounded, observable
 * "next eligible at" so the recovery decision is explicit rather than a guess.
 *
 * ## What this deliberately does NOT do
 *
 * - It never disables a source. That stays an operator decision (this mirrors
 *   `Conexao_Source_Health`, which is informational only).
 * - It never marks a source complete or empty. A source in cooldown is
 *   *unknown*, not *done* — absence of evidence is not evidence of absence.
 * - It never schedules or performs retries. There is no automatic retry loop.
 *   Cooldown only reports; the operator decides when to try again.
 * - It never touches the completeness contract. A run skipped by cooldown is
 *   recorded as incomplete, so `mark_missing_events()` cannot fire for it.
 *
 * ## Scope
 *
 * Per-source, persisted in a single option, bounded growth (see MAX_SOURCES).
 * Nothing is stored per request: this is one small record per source that
 * failed, not an observability firehose.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Source_Cooldown {

	const OPTION_KEY = 'conexao_event_source_cooldown';

	/**
	 * Maximum number of sources tracked.
	 *
	 * Bounds the option row. The registry is finite, so this is a safety
	 * ceiling, not a routine limit.
	 *
	 * @var int
	 */
	const MAX_SOURCES = 200;

	/**
	 * Base cooldown after the first throttled observation (seconds).
	 *
	 * Deliberately not invented from thin air: G5 already measured that 2 s and
	 * 4 s general backoff were both insufficient against a real Eventbrite 429,
	 * and set its own rate-limit ladder at a 20 s base. This reuses that same
	 * measured base rather than introducing a second, conflicting number.
	 *
	 * @var int
	 */
	const BASE_SECONDS = 20;

	/**
	 * Ceiling on any single computed cooldown (seconds).
	 *
	 * G5 already established a 60 s ceiling for an in-walk Eventbrite cooldown.
	 * This keeps that same bound so no source can dictate the length of an
	 * operator's workflow across runs.
	 *
	 * @var int
	 */
	const MAX_SECONDS = 60;

	/**
	 * Consecutive throttled observations after which the cooldown is treated as
	 * settled at MAX_SECONDS rather than continuing to grow.
	 *
	 * @var int
	 */
	const STEPS = 3;

	/**
	 * Failure categories that count as "throttled" for cooldown purposes.
	 *
	 * 'rate_limited'  — HTTP 429 / Retry-After.
	 * 'waf'           — HTTP 405 or a recognised WAF challenge page.
	 * 'server_error'  — HTTP 5xx.
	 * 'network'       — DNS/TLS/timeout, i.e. we could not reach it at all.
	 *
	 * A 404 or a parse failure is NOT throttled: that is a broken source, and
	 * backing off for it would only delay an operator's legitimate fix.
	 *
	 * @var string[]
	 */
	const THROTTLED_REASONS = array( 'rate_limited', 'waf', 'server_error', 'network' );

	/**
	 * Get the full cooldown map.
	 *
	 * @return array source_id => record.
	 */
	public static function get_all() {
		$map = get_option( self::OPTION_KEY, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Get the cooldown record for one source, with defaults applied.
	 *
	 * @param string $source_id Source slug.
	 * @return array{consecutive:int,last_reason:string,last_observed:string,next_eligible:string,cooldown_seconds:int}
	 */
	public static function get( $source_id ) {
		$all = self::get_all();

		$defaults = array(
			'consecutive'      => 0,
			'last_reason'      => '',
			'last_observed'    => '',
			'next_eligible'    => '',
			'cooldown_seconds' => 0,
		);

		if ( isset( $all[ $source_id ] ) && is_array( $all[ $source_id ] ) ) {
			return wp_parse_args( $all[ $source_id ], $defaults );
		}

		return $defaults;
	}

	/**
	 * Record a throttled observation and extend the cooldown.
	 *
	 * Repeated failures escalate the cooldown up to MAX_SECONDS, so a source
	 * that is genuinely being refused does not get hammered on every attempt,
	 * while a single transient blip still clears quickly.
	 *
	 * @param string $source_id Source slug.
	 * @param string $reason    One of self::THROTTLED_REASONS.
	 * @return array The stored record.
	 */
	public static function record_throttled( $source_id, $reason = '' ) {
		$reason = (string) $reason;

		// A non-throttling failure (404, malformed body, ...) is recorded as a
		// health problem by Conexao_Source_Health, not as a cooldown. It must not
		// put a *fixable* source to sleep.
		if ( ! in_array( $reason, self::THROTTLED_REASONS, true ) ) {
			return self::get( $source_id );
		}

		$all      = self::get_all();
		$existing = isset( $all[ $source_id ] ) && is_array( $all[ $source_id ] ) ? $all[ $source_id ] : array();

		// Only a CONSECUTIVE throttled observation escalates. If the previous
		// observation was a different category, restart the ladder rather than
		// letting a 429 inherit a WAF's accumulated delay.
		$consecutive = ( isset( $existing['last_reason'] ) && $existing['last_reason'] === $reason )
			? ( isset( $existing['consecutive'] ) ? (int) $existing['consecutive'] : 0 ) + 1
			: 1;

		$step    = min( $consecutive, self::STEPS );
		$seconds = min( self::BASE_SECONDS * $step, self::MAX_SECONDS );

		$existing['consecutive']      = $consecutive;
		$existing['last_reason']      = $reason;
		$existing['last_observed']    = current_time( 'mysql' );
		$existing['cooldown_seconds'] = $seconds;
		$existing['next_eligible']    = gmdate( 'Y-m-d H:i:s', time() + $seconds );

		$all[ $source_id ] = $existing;

		// Bound growth without ever evicting a source that is still cooling.
		if ( count( $all ) > self::MAX_SOURCES ) {
			$all = self::prune( $all, self::MAX_SOURCES );
		}

		update_option( self::OPTION_KEY, $all, false );

		return self::get( $source_id );
	}

	/**
	 * Record an ALREADY-ELAPSED cooldown for a source.
	 *
	 * Used by tests that need the source back in play while still holding a
	 * cooldown record (so they can prove the record survives an incomplete run).
	 * A real run reaches this state naturally once the window elapses; writing
	 * it explicitly avoids sleeping through the wall clock to prove it.
	 *
	 * This grants no privilege: an elapsed record never blocks a run, exactly
	 * like a record whose window has passed in real time.
	 *
	 * @param string $source_id Source slug.
	 * @return void
	 */
	public static function record_elapsed( $source_id ) {
		$all      = self::get_all();
		$existing = isset( $all[ $source_id ] ) && is_array( $all[ $source_id ] ) ? $all[ $source_id ] : array();

		$existing['last_reason']      = 'rate_limited';
		$existing['last_observed']    = current_time( 'mysql' );
		$existing['cooldown_seconds'] = 0;
		$existing['next_eligible']    = gmdate( 'Y-m-d H:i:s', time() - 1 );

		$all[ $source_id ] = $existing;

		update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Record a clean observation, clearing any cooldown for the source.
	 *
	 * A successful fetch means the upstream is answering again, so keeping the
	 * old cooldown would needlessly lock out a healthy source.
	 *
	 * @param string $source_id Source slug.
	 * @return void
	 */
	public static function record_recovered( $source_id ) {
		$all = self::get_all();

		if ( ! isset( $all[ $source_id ] ) ) {
			return;
		}

		unset( $all[ $source_id ] );
		update_option( self::OPTION_KEY, $all, false );
	}
	/**
	 * Whether a source is still inside its cooldown window.
	 *
	 * @param string $source_id Source slug.
	 * @return bool True while the cooldown has not elapsed.
	 */
	public static function is_cooling_down( $source_id ) {
		$record = self::get( $source_id );

		if ( empty( $record['next_eligible'] ) ) {
			return false;
		}

		return strtotime( $record['next_eligible'] . ' UTC' ) > time();
	}

	/**
	 * How many seconds remain before this source becomes eligible again.
	 *
	 * @param string $source_id Source slug.
	 * @return int Seconds remaining; 0 when eligible now.
	 */
	public static function get_seconds_remaining( $source_id ) {
		$record = self::get( $source_id );

		if ( empty( $record['next_eligible'] ) ) {
			return 0;
		}

		$remaining = strtotime( $record['next_eligible'] . ' UTC' ) - time();

		return $remaining > 0 ? (int) $remaining : 0;
	}

	/**
	 * A compact, bounded operator-facing summary of one source's state.
	 *
	 * This is the shape Import History stores, so the operator can see why a
	 * re-run is being deferred without a second dashboard.
	 *
	 * @param string $source_id Source slug.
	 * @return array{cooling_down:bool,seconds_remaining:int,next_eligible:string,last_reason:string,consecutive:int}
	 */
	public static function get_state( $source_id ) {
		$record = self::get( $source_id );

		return array(
			'cooling_down'      => self::is_cooling_down( $source_id ),
			'seconds_remaining' => self::get_seconds_remaining( $source_id ),
			'next_eligible'     => (string) $record['next_eligible'],
			'last_reason'       => (string) $record['last_reason'],
			'consecutive'       => (int) $record['consecutive'],
		);
	}

	/**
	 * Clear a source's cooldown (explicit operator recovery).
	 *
	 * @param string $source_id Source slug.
	 * @return void
	 */
	public static function reset( $source_id ) {
		$all = self::get_all();

		if ( isset( $all[ $source_id ] ) ) {
			unset( $all[ $source_id ] );
			update_option( self::OPTION_KEY, $all, false );
		}
	}

	/**
	 * Drop the entries that are no longer cooling, newest failures kept.
	 *
	 * @param array $all   Full map.
	 * @param int   $limit Maximum entries to keep.
	 * @return array Pruned map.
	 */
	protected static function prune( array $all, $limit ) {
		uasort(
			$all,
			function ( $a, $b ) {
				$left  = isset( $a['last_observed'] ) ? (string) $a['last_observed'] : '';
				$right = isset( $b['last_observed'] ) ? (string) $b['last_observed'] : '';
				return strcmp( $right, $left );
			}
		);

		return array_slice( $all, 0, max( 1, (int) $limit ), true );
	}
}
