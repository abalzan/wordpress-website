<?php
/**
 * Automatic continuation of a resumable enumeration.
 *
 * ## Why this class exists
 *
 * G18. G9 recorded a per-source cooldown and G10 persisted an enumeration
 * checkpoint, so a partial Eventbrite walk could be *resumed from where it
 * stopped* instead of restarting at page 1. What no mechanism did was
 * **come back**. The operator-facing promise in Import History —
 * `page 17 of 49 (time_budget) — resumable` — was a statement about state, not
 * about work in flight, and `Conexao_Source_Cooldown` said so explicitly:
 *
 *     "It never schedules or performs retries. There is no automatic retry
 *      loop. Cooldown only reports; the operator decides when to try again."
 *
 * A resumable job therefore stopped until a human pressed Run. Every observed
 * live record (`eventbrite_cork` at page 17/49, `eventbrite_wexford` at page
 * 10/43, ...) describes exactly that: enough state to resume, and nothing that
 * would resume it. This class supplies the missing transition.
 *
 * ## What it is (and is not)
 *
 * It is a **state machine plus a trigger**, not a new importer, not a new queue
 * and not a new completeness rule:
 *
 *     run starts
 *       -> checkpoint saved                     (G10, unchanged)
 *       -> run becomes incomplete/resumable     (G2B/G10, unchanged)
 *       -> reconcile() reads the resumable predicate
 *       -> continuation record persisted + ONE wp_schedule_single_event
 *       -> cooldown expires                     (server clock, never a browser)
 *       -> run_due() takes the per-source lock
 *       -> run_source() resumes at the checkpoint (G10, unchanged)
 *       -> reconcile() again  ->  repeat
 *       -> enumeration complete  ->  record cleared
 *
 * `resumable`, `scheduled`, `running`, `exhausted` and `failed` are separate,
 * independently observable states. A continuation that cannot be scheduled is
 * recorded as `failed` and logged as an error — never reported as success.
 *
 * ## Scheduling mechanism: the one the repository already has
 *
 * WordPress core WP-Cron. One single event per source
 * (`wp_schedule_single_event( $run_at, HOOK, array( $source_id ) )`), never a
 * recurring schedule, never a job per page, never a busy loop. The record's
 * `run_at` is the authoritative time; the cron event is only the timer.
 *
 * Two triggers consume it, and both are server-side:
 *
 *   1. the WP-Cron event, which fires with no request at all on any host whose
 *      cron path works (a real system cron, or a working loopback); and
 *   2. a `shutdown` fail-safe (`run_due()`), so a host whose WP-Cron cannot
 *      spawn cannot silently strand the job.
 *
 * Neither trigger depends on the Import History page, an open tab, a
 * JavaScript timer, an admin-page AJAX call or an operator click.
 *
 * ## Cooldown
 *
 * 30 minutes by default (`COOLDOWN_SECONDS`), server-side, filterable. The
 * importer sends nothing before it elapses: `run_due()` re-checks the clock
 * against the persisted `run_at` even when its trigger fired early. Repeated
 * *no-progress* attempts grow the delay (30 -> 60 -> 120 min, capped) exactly
 * as the G5 in-walk ladder escalates 20 -> 40 -> 60 s. The in-walk ladder, the
 * request budget and the pacing policy are NOT touched or duplicated here.
 *
 * ## Concurrency
 *
 * One lock per source (`LOCK_PREFIX . $source_id`), taken with the atomic
 * `add_option()`. Cork's lock never blocks Wicklow: there is deliberately no
 * global lock. The lock carries a TTL so a process killed mid-run self-heals
 * rather than wedging the source forever, and a second worker that finds the
 * lock held fails safe (it does nothing) instead of racing the first.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-source continuation state for a resumable enumeration.
 *
 * @package Conexao_Event_Importer
 */
class Conexao_Import_Continuation {

	/**
	 * Option holding the per-source continuation map.
	 *
	 * Autoloaded on purpose, unlike its sibling registries: this is the state
	 * every request has to read once to decide whether any continuation is due,
	 * so an extra query per request would be the wrong trade. The map is bounded
	 * by self::MAX_SOURCES and holds no response data.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'conexao_event_import_continuations';

	/**
	 * Version of the stored record shape.
	 *
	 * A record written under a different shape is discarded rather than
	 * reinterpreted, exactly like the enumeration checkpoint: resuming from
	 * state whose meaning is no longer guaranteed is never safe.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * The WP-Cron hook that fires a continuation.
	 *
	 * @var string
	 */
	const HOOK = 'conexao_event_import_continuation';

	/**
	 * Default delay before a continuation is executed (seconds).
	 *
	 * 30 minutes. The value is not invented: G5 measured that a real Eventbrite
	 * 429 survives a 20-second cooldown and repeats across an entire 56-source
	 * run, so a *run-level* retry has to be an order of magnitude calmer than
	 * the in-walk ladder to be worth anything. The accepted band is 20-30
	 * minutes; the conservative end is the default.
	 *
	 * @var int
	 */
	const COOLDOWN_SECONDS = 1800;

	/**
	 * Ceiling on an escalated delay between attempts (seconds).
	 *
	 * @var int
	 */
	const MAX_BACKOFF_SECONDS = 7200;

	/**
	 * Consecutive attempts that advance the checkpoint no further before the
	 * continuation is declared stalled.
	 *
	 * A stalled source is NOT silently dropped: it becomes an observable
	 * `exhausted` state that Import History shows and the log records as an
	 * error, and an operator re-run that makes progress clears it.
	 *
	 * @var int
	 */
	const MAX_STALLED = 4;

	/**
	 * Absolute ceiling on the number of automatic attempts for one source.
	 *
	 * A runaway backstop, not a routine limit: a source that advances on every
	 * attempt never reaches it, and a source that stops advancing is caught by
	 * self::MAX_STALLED first.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 40;

	/**
	 * Prefix of the per-source lock option.
	 *
	 * @var string
	 */
	const LOCK_PREFIX = 'conexao_event_import_lock_';

	/**
	 * How long a lock is honoured before it is treated as abandoned (seconds).
	 *
	 * Longer than any single run (a run is bounded by its own time budget), so a
	 * live worker keeps its lock, while a worker killed by a restart cannot
	 * wedge its source permanently.
	 *
	 * @var int
	 */
	const LOCK_TTL = 900;

	/**
	 * Maximum number of sources tracked.
	 *
	 * @var int
	 */
	const MAX_SOURCES = 200;

	/**
	 * A continuation has been recorded and a future execution is armed.
	 *
	 * @var string
	 */
	const STATE_SCHEDULED = 'scheduled';

	/**
	 * A worker holds the source lock and is executing this continuation.
	 *
	 * @var string
	 */
	const STATE_RUNNING = 'running';

	/**
	 * The source stopped advancing, or hit the attempt ceiling. Visible, never
	 * silent: no further continuation is armed until an operator run makes
	 * progress.
	 *
	 * @var string
	 */
	const STATE_EXHAUSTED = 'exhausted';

	/**
	 * The continuation could not be armed. An operational failure, surfaced to
	 * the administrator rather than hidden behind a "resumable" label.
	 *
	 * @var string
	 */
	const STATE_FAILED = 'failed';

	/**
	 * Injectable clock (tests only). Null means the server clock.
	 *
	 * @var callable|null
	 */
	protected static $clock = null;

	/**
	 * Injectable runner (tests only). Null means the importer engine.
	 *
	 * @var callable|null
	 */
	protected static $runner = null;

	/**
	 * The stored record shape with defaults applied.
	 *
	 * @return array
	 */
	protected static function defaults() {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'source_id'      => '',
			'state'          => '',
			'run_at'         => 0,
			'scheduled_at'   => 0,
			'last_run_at'    => 0,
			'attempts'       => 0,
			'stalled'        => 0,
			'last_page'      => 0,
			'page_count'     => 0,
			'reason'         => '',
			'run_id'         => '',
			'message'        => '',
		);
	}

	/**
	 * The whole continuation map.
	 *
	 * @return array source_id => record.
	 */
	public static function get_all() {
		$map = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $map ) ) {
			return array();
		}

		// A record from an incompatible schema is discarded rather than
		// reinterpreted, so a future shape change can never resume from state
		// whose meaning it no longer guarantees.
		foreach ( $map as $source_id => $record ) {
			$schema = ( is_array( $record ) && isset( $record['schema_version'] ) )
				? (int) $record['schema_version']
				: 0;

			if ( self::SCHEMA_VERSION !== $schema ) {
				unset( $map[ $source_id ] );
			}
		}

		return $map;
	}

	/**
	 * Read one source's continuation record.
	 *
	 * @param string $source_id Source slug.
	 * @return array Record with defaults applied (state '' when none exists).
	 */
	public static function get( $source_id ) {
		$all      = self::get_all();
		$defaults = self::defaults();

		if ( isset( $all[ $source_id ] ) && is_array( $all[ $source_id ] ) ) {
			return wp_parse_args( $all[ $source_id ], $defaults );
		}

		return $defaults;
	}

	/**
	 * Persist one source's record, pruning the map at its ceiling.
	 *
	 * @param string $source_id Source slug.
	 * @param array  $record    Record to store.
	 * @return void
	 */
	protected static function save( $source_id, array $record ) {
		$all = self::get_all();

		$record['schema_version'] = self::SCHEMA_VERSION;
		$record['source_id']      = sanitize_key( (string) $source_id );

		$all[ $source_id ] = $record;

		if ( count( $all ) > self::MAX_SOURCES ) {
			$all = self::prune( $all, self::MAX_SOURCES );
		}

		// Autoloaded: every request reads this once to find out whether any
		// continuation is due. See the OPTION_KEY docblock.
		update_option( self::OPTION_KEY, $all, true );
	}

	/**
	 * Drop the earliest-due entries once the map exceeds its ceiling.
	 *
	 * @param array $all   Full map.
	 * @param int   $limit Maximum entries to keep.
	 * @return array Pruned map.
	 */
	protected static function prune( array $all, $limit ) {
		uasort(
			$all,
			static function ( $a, $b ) {
				$left  = isset( $a['run_at'] ) ? (int) $a['run_at'] : 0;
				$right = isset( $b['run_at'] ) ? (int) $b['run_at'] : 0;

				return $left - $right;
			}
		);

		return array_slice( $all, 0, max( 1, (int) $limit ), true );
	}

	/**
	 * The current server time.
	 *
	 * Every scheduling decision reads time through here, so a test can advance a
	 * simulated clock instead of sleeping for 30 real minutes, and so the
	 * browser's clock can never influence a cooldown.
	 *
	 * @return int UNIX timestamp.
	 */
	public static function now() {
		if ( is_callable( self::$clock ) ) {
			return (int) call_user_func( self::$clock );
		}

		return time();
	}

	/**
	 * Install a deterministic clock (tests only).
	 *
	 * @param callable|null $clock Returns an integer UNIX timestamp.
	 * @return void
	 */
	public static function set_clock( $clock ) {
		self::$clock = is_callable( $clock ) ? $clock : null;
	}

	/**
	 * Remove the injected clock and return to the server clock.
	 *
	 * @return void
	 */
	public static function reset_clock() {
		self::$clock = null;
	}

	/**
	 * Install a deterministic runner (tests only).
	 *
	 * Mirrors the G5 pacing seam (`Conexao_Eventbrite_Pacing::set_sleeper()`):
	 * the production path is unchanged when nothing is injected.
	 *
	 * @param callable|null $runner Called with one source id.
	 * @return void
	 */
	public static function set_runner( $runner ) {
		self::$runner = is_callable( $runner ) ? $runner : null;
	}

	/**
	 * Remove the injected runner.
	 *
	 * @return void
	 */
	public static function reset_runner() {
		self::$runner = null;
	}

	/**
	 * The callable that actually runs one source.
	 *
	 * @return callable
	 */
	protected static function get_runner() {
		if ( is_callable( self::$runner ) ) {
			return self::$runner;
		}

		return array( __CLASS__, 'default_runner' );
	}

	/**
	 * Run one source through the importer engine.
	 *
	 * The continuation deliberately reuses the ONE import path every other entry
	 * point uses — the same handler, the same checkpoint resume, the same request
	 * budget, the same pacing, the same reconciliation gates. A continuation is
	 * not a second importer, and nothing here may become one.
	 *
	 * @param string $source_id Source slug.
	 * @return void
	 */
	public static function default_runner( $source_id ) {
		if ( ! class_exists( 'Conexao_Event_Importer' ) ) {
			return;
		}

		$plugin = Conexao_Event_Importer::instance();

		if ( ! $plugin || ! isset( $plugin->importer ) ) {
			return;
		}

		$plugin->importer->run_source( $source_id );
	}

	/**
	 * The delay to apply before the next automatic attempt (seconds).
	 *
	 * Escalation mirrors the spirit of the G5 in-walk ladder without touching
	 * it: 30 min, then 60, then 120 and capped there. The delay is a *run-level*
	 * backstop, so it deliberately starts an order of magnitude above the
	 * in-walk 20 s base.
	 *
	 * The delay is therefore bounded below by COOLDOWN_SECONDS itself (the base
	 * is the floor) and above by MAX_BACKOFF_SECONDS, in server-side code,
	 * with no knob that could turn it into an aggressive retry loop.
	 *
	 * @param int $stalled Consecutive no-progress attempts so far.
	 * @return int Seconds.
	 */
	public static function get_cooldown_seconds( $stalled = 0 ) {
		$stalled = max( 0, min( (int) $stalled, 8 ) );

		$seconds = self::COOLDOWN_SECONDS * (int) pow( 2, $stalled );

		if ( $seconds > self::MAX_BACKOFF_SECONDS ) {
			$seconds = self::MAX_BACKOFF_SECONDS;
		}

		return (int) $seconds;
	}

	/**
	 * The per-source cooldown ladder's own next-eligible timestamp.
	 *
	 * Read, never written: `Conexao_Source_Cooldown` remains the single owner of
	 * the in-walk throttle state, and this only makes sure a continuation never
	 * starts a run the run gate would immediately refuse.
	 *
	 * @param string $source_id Source slug.
	 * @return int UNIX timestamp, or 0 when the source has no cooldown record.
	 */
	protected static function cooldown_next_eligible( $source_id ) {
		if ( ! class_exists( 'Conexao_Source_Cooldown' ) ) {
			return 0;
		}

		$record = Conexao_Source_Cooldown::get( $source_id );

		if ( empty( $record['next_eligible'] ) ) {
			return 0;
		}

		$timestamp = strtotime( $record['next_eligible'] . ' UTC' );

		return ( $timestamp > 0 ) ? (int) $timestamp : 0;
	}

	/**
	 * Ensure exactly one pending cron event for a source at the given time.
	 *
	 * @param string $source_id Source slug.
	 * @param int    $run_at    UNIX timestamp to fire at.
	 * @return bool True when an event is armed at $run_at.
	 */
	protected static function arm_cron( $source_id, $run_at ) {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return false;
		}

		$run_at = (int) $run_at;
		$args   = array( $source_id );
		$next   = wp_next_scheduled( self::HOOK, $args );

		if ( false !== $next && (int) $next === $run_at ) {
			return true;
		}

		// Exactly one pending event per source. The clear is what makes a re-arm
		// reliable: WordPress refuses a second event for the same hook+args
		// within ten minutes of an existing one, which would otherwise turn a
		// legitimate re-arm into a silent no-op.
		if ( false !== $next ) {
			wp_clear_scheduled_hook( self::HOOK, $args );
		}

		return (bool) wp_schedule_single_event( $run_at, self::HOOK, $args );
	}

	/**
	 * Remove any pending cron event for a source.
	 *
	 * @param string $source_id Source slug.
	 * @return void
	 */
	protected static function disarm_cron( $source_id ) {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}

		wp_clear_scheduled_hook( self::HOOK, array( $source_id ) );
	}

	/**
	 * Write one continuation lifecycle entry to the import log.
	 *
	 * @param string $source_id Source slug.
	 * @param string $level     info|warning|error.
	 * @param string $message   Human-readable message.
	 * @param array  $context   Structured context.
	 * @return void
	 */
	protected static function log( $source_id, $level, $message, array $context = array() ) {
		if ( ! class_exists( 'Conexao_Import_Log' ) ) {
			return;
		}

		Conexao_Import_Log::add( $source_id, $level, $message, $context );
	}

	/**
	 * Record that a source needs a future execution, and arm it.
	 *
	 * Idempotent by construction, which is what makes duplicate protection a
	 * property of the state rather than of the caller: two simultaneous
	 * resumable events, a repeated checkpoint write, a repeated run or two
	 * browser tabs all reach the same result — ONE pending continuation,
	 * neither duplicated nor postponed.
	 *
	 * @param string $source_id Source slug.
	 * @param array  $fields    Overrides (attempts, stalled, last_page, ...).
	 * @return array The stored record (state 'failed' when arming failed).
	 */
	public static function schedule( $source_id, array $fields = array() ) {
		$existing = self::get( $source_id );

		if ( self::STATE_SCHEDULED === $existing['state'] && (int) $existing['run_at'] > 0 ) {
			// Already pending: keep it exactly as it is. Re-arming here (rather
			// than re-scheduling) also repairs the case where the cron event was
			// lost without the record noticing.
			self::arm_cron( $source_id, (int) $existing['run_at'] );

			return $existing;
		}

		$stalled = isset( $fields['stalled'] ) ? (int) $fields['stalled'] : (int) $existing['stalled'];
		$now     = self::now();
		$run_at  = $now + self::get_cooldown_seconds( $stalled );

		// Compose with the existing per-source cooldown ladder. A continuation
		// must never start a run the run gate would immediately refuse.
		$eligible = self::cooldown_next_eligible( $source_id );

		if ( $eligible > $run_at ) {
			$run_at = $eligible;
		}

		$record = wp_parse_args(
			$fields,
			array(
				'scheduled_at' => $now,
				'attempts'     => (int) $existing['attempts'],
				'stalled'      => $stalled,
				'last_page'    => (int) $existing['last_page'],
				'page_count'   => (int) $existing['page_count'],
				'reason'       => (string) $existing['reason'],
				'last_run_at'  => (int) $existing['last_run_at'],
				'run_id'       => (string) $existing['run_id'],
			)
		);

		$record['state']   = self::STATE_SCHEDULED;
		$record['run_at']  = $run_at;
		$record['message'] = '';

		// Persist BEFORE arming. A crash in between leaves a record whose cron
		// event is missing, which rearm_missing() repairs on the next request.
		// Arming first and crashing would leave an event with no record.
		self::save( $source_id, $record );

		if ( ! self::arm_cron( $source_id, $run_at ) ) {
			$record['state']   = self::STATE_FAILED;
			$record['run_at']  = 0;
			$record['message'] = 'scheduling_failed';

			self::save( $source_id, $record );

			// Fail VISIBLY. The source is resumable and incomplete, and it is NOT
			// complete: the administrator must be able to see that the automatic
			// continuation could not be armed.
			self::log(
				$source_id,
				'error',
				sprintf(
					/* translators: %s: source slug */
					__( 'Could not schedule the continuation for %s: WordPress refused the scheduled event. The source remains INCOMPLETE and resumable, but it will NOT continue on its own until a run succeeds or WP-Cron is fixed. Press Run to resume it manually.', 'conexao-event-importer' ),
					$source_id
				),
				array(
					'reason'      => 'continuation_scheduling_failed',
					'reason_code' => 'continuation_scheduling_failed',
					'run_id'      => (string) $record['run_id'],
				)
			);

			return $record;
		}

		self::log(
			$source_id,
			'info',
			sprintf(
				/* translators: 1: source slug, 2: last confirmed page, 3: total pages, 4: failure class, 5: minutes until the next attempt */
				__( 'Continuation scheduled for %1$s: resumable at page %2$d of %3$d (%4$s). The next automatic attempt runs in about %5$d minute(s), after the cooldown, with no browser involved.', 'conexao-event-importer' ),
				$source_id,
				(int) $record['last_page'],
				(int) $record['page_count'],
				'' !== (string) $record['reason'] ? (string) $record['reason'] : 'incomplete',
				(int) round( ( $run_at - $now ) / 60 )
			),
			array(
				'reason'           => 'continuation_scheduled',
				'reason_code'      => 'continuation_scheduled',
				'run_at'           => $run_at,
				'cooldown_seconds' => $run_at - $now,
				'attempts'         => (int) $record['attempts'],
				'run_id'           => (string) $record['run_id'],
			)
		);

		return $record;
	}

	/**
	 * Drop a source's continuation and disarm its trigger.
	 *
	 * Called when the enumeration is no longer resumable — most importantly when
	 * it completed, which is what makes completion an observable transition
	 * rather than a state the record keeps claiming.
	 *
	 * @param string $source_id Source slug.
	 * @param string $reason    Why it was cleared (for the log).
	 * @return bool True when a record was removed.
	 */
	public static function clear( $source_id, $reason = '' ) {
		$all = self::get_all();
		$had = isset( $all[ $source_id ] );

		if ( $had ) {
			unset( $all[ $source_id ] );
			update_option( self::OPTION_KEY, $all, true );
		}

		self::disarm_cron( $source_id );

		if ( $had ) {
			self::log(
				$source_id,
				'info',
				__( 'Continuation cleared: this source is no longer resumable, so no further automatic attempt is scheduled.', 'conexao-event-importer' ),
				array(
					'reason'      => '' !== $reason ? $reason : 'not_resumable',
					'reason_code' => '' !== $reason ? $reason : 'not_resumable',
				)
			);
		}

		return $had;
	}

	/**
	 * Decide the continuation for a source from its CURRENT persisted state.
	 *
	 * Called once at the end of every real import run (never a dry run), and
	 * again after a continuation worker returns. It is idempotent: everything is
	 * derived from the checkpoint and the record, and the second call finds the
	 * state the first one just wrote — so an attempt is never double-counted.
	 *
	 * @param string $source_id Source slug.
	 * @param array  $stats     The run's stats (reserved; the decision is derived
	 *                          from persisted state, never from the summary).
	 * @return bool|string True when a continuation is pending, 'exhausted' when
	 *                     the stall is terminal, false when nothing is owed.
	 */
	public static function reconcile( $source_id, array $stats = array() ) {
		unset( $stats );

		$record  = self::get( $source_id );
		$knows   = class_exists( 'Conexao_Source_Enumeration_Checkpoint' );
		$check   = $knows ? Conexao_Source_Enumeration_Checkpoint::get( $source_id ) : array();
		$owed    = $knows && Conexao_Source_Enumeration_Checkpoint::is_resumable( $check );

		if ( ! $owed ) {
			// Either the enumeration reached its last page, or there is no
			// progress to resume. Either way this source must not keep a
			// continuation — and, crucially, must not keep advertising one.
			if ( '' !== (string) $record['state'] ) {
				self::clear( $source_id, 'enumeration_not_resumable' );
			}

			return false;
		}

		$page       = (int) $check['last_page'];
		$page_count = (int) $check['page_count'];
		$progress   = ( $page > (int) $record['last_page'] );
		$stalled    = $progress ? 0 : ( (int) $record['stalled'] + 1 );
		$attempts   = (int) $record['attempts'] + 1;

		// An exhausted source stays exhausted while it makes no progress: the
		// stall was real, and silently re-arming would hide it. An operator run
		// that DOES advance the checkpoint clears the state and reschedules.
		if ( self::STATE_EXHAUSTED === $record['state'] && ! $progress ) {
			return 'exhausted';
		}

		if ( $stalled >= self::MAX_STALLED ) {
			return self::declare_exhausted( $source_id, $record, $page, $page_count, $stalled, $attempts, 'stalled' );
		}

		if ( $attempts > self::MAX_ATTEMPTS ) {
			return self::declare_exhausted( $source_id, $record, $page, $page_count, $stalled, $attempts, 'attempt_ceiling' );
		}

		$scheduled = self::schedule(
			$source_id,
			array(
				'attempts'   => $attempts,
				'stalled'    => $stalled,
				'last_page'  => $page,
				'page_count' => $page_count,
				'reason'     => isset( $check['last_failure'] ) ? (string) $check['last_failure'] : '',
			)
		);

		if ( self::STATE_FAILED === $scheduled['state'] ) {
			return 'failed';
		}

		return true;
	}

	/**
	 * Record a terminal, VISIBLE stall.
	 *
	 * The EFFECTIVE counters are stored, not the ones the record arrived with,
	 * so the operator sees the attempt that actually exhausted the budget rather
	 * than the previous one.
	 *
	 * @param string $source_id  Source slug.
	 * @param array  $record     Existing record.
	 * @param int    $page       Last confirmed page.
	 * @param int    $page_count Declared page count.
	 * @param int    $stalled    Effective consecutive no-progress count.
	 * @param int    $attempts   Effective attempt count.
	 * @param string $cause      stalled|attempt_ceiling.
	 * @return string Always STATE_EXHAUSTED, for the caller to return.
	 */
	protected static function declare_exhausted( $source_id, array $record, $page, $page_count, $stalled, $attempts, $cause ) {
		$record['state']      = self::STATE_EXHAUSTED;
		$record['run_at']     = 0;
		$record['last_page']  = (int) $page;
		$record['page_count'] = (int) $page_count;
		$record['stalled']    = (int) $stalled;
		$record['attempts']   = (int) $attempts;
		$record['message']    = (string) $cause;

		self::save( $source_id, $record );
		self::disarm_cron( $source_id );

		self::log(
			$source_id,
			'error',
			sprintf(
				/* translators: 1: source slug, 2: last confirmed page, 3: total pages, 4: number of attempts, 5: cause code */
				__( 'Automatic continuation for %1$s STOPPED at page %2$d of %3$d after %4$d attempt(s) (%5$s). The source is still INCOMPLETE and no further attempt is scheduled: press Run to resume it once the cause is addressed.', 'conexao-event-importer' ),
				$source_id,
				(int) $page,
				(int) $page_count,
				(int) $record['attempts'],
				(string) $cause
			),
			array(
				'reason'      => 'continuation_exhausted',
				'reason_code' => 'continuation_exhausted',
				'cause'       => (string) $cause,
				'attempts'    => (int) $record['attempts'],
				'stalled'     => (int) $record['stalled'],
				'run_id'      => (string) $record['run_id'],
			)
		);

		return self::STATE_EXHAUSTED;
	}

	/**
	 * Execute every continuation whose cooldown has elapsed.
	 *
	 * This is the single execution entry point, called by the WP-Cron event and
	 * by the `shutdown` fail-safe. It enforces three things in one place:
	 *
	 *   1. the cooldown — a trigger that fires early does nothing at all;
	 *   2. single ownership — a source whose lock is held is skipped, so two
	 *      workers can never resume the same source at the same time;
	 *   3. isolation — one source's failure never stops another's continuation.
	 *
	 * @param string $source_id Optional single source; empty means all due.
	 * @return array source_id => outcome (ran|cooling|locked|not-scheduled).
	 */
	public static function run_due( $source_id = '' ) {
		$outcomes = array();
		$map      = self::get_all();

		$targets = ( '' !== (string) $source_id )
			? array( (string) $source_id )
			: array_keys( $map );

		foreach ( $targets as $id ) {
			$record = self::get( $id );

			if ( self::STATE_SCHEDULED !== $record['state'] ) {
				$outcomes[ $id ] = 'not-scheduled';
				continue;
			}

			// Server clock only. A trigger that fires before the cooldown has
			// elapsed sends NOTHING and changes nothing.
			if ( self::now() < (int) $record['run_at'] ) {
				$outcomes[ $id ] = 'cooling';
				continue;
			}

			if ( ! self::acquire_lock( $id ) ) {
				$outcomes[ $id ] = 'locked';
				continue;
			}

			try {
				$record['state']       = self::STATE_RUNNING;
				$record['last_run_at'] = self::now();
				$record['run_at']      = 0;
				self::save( $id, $record );

				self::log(
					$id,
					'info',
					sprintf(
						/* translators: 1: source slug, 2: last confirmed page, 3: total pages */
						__( 'Continuation starting for %1$s: resuming the enumeration from page %2$d of %3$d. No browser is involved.', 'conexao-event-importer' ),
						$id,
						(int) $record['last_page'],
						(int) $record['page_count']
					),
					array(
						'reason'      => 'continuation_started',
						'reason_code' => 'continuation_started',
						'attempts'    => (int) $record['attempts'],
						'run_id'      => (string) $record['run_id'],
					)
				);

				call_user_func( self::get_runner(), $id );
			} catch ( Throwable $e ) {
				// One source's failure must not stop the others, and it must not be
				// reported as success either.
				self::log(
					$id,
					'error',
					sprintf(
						/* translators: 1: source slug, 2: error message */
						__( 'The continuation run for %1$s threw an unexpected error: %2$s', 'conexao-event-importer' ),
						$id,
						$e->getMessage()
					),
					array(
						'reason'      => 'continuation_exception',
						'reason_code' => 'continuation_exception',
					)
				);
			} finally {
				self::release_lock( $id );
			}

			// The engine reconciles inside run_source(). This is the safety net for
			// a runner that does not (and for a run that threw before it could), so
			// a worker can never leave a source stuck in `running`.
			$after = self::get( $id );

			if ( self::STATE_RUNNING === $after['state'] ) {
				self::reconcile( $id );
			}

			$outcomes[ $id ] = 'ran';
		}

		return $outcomes;
	}

	/**
	 * The WP-Cron callback.
	 *
	 * @param string $source_id Source slug the event was armed for.
	 * @return array Outcomes, for observability in tests.
	 */
	public static function handle_cron( $source_id = '' ) {
		return self::run_due( (string) $source_id );
	}

	/**
	 * Re-arm a continuation whose cron event has gone missing.
	 *
	 * The record is the durable half of the pair; the cron event is only the
	 * timer. A WordPress restart, a cleared cron array or an event consumed by
	 * an early fire therefore cannot strand a continuation — the next request
	 * restores the timer from persisted state.
	 *
	 * @return int Number of events re-armed.
	 */
	public static function rearm_missing() {
		$map = self::get_all();

		if ( empty( $map ) || ! function_exists( 'wp_next_scheduled' ) ) {
			return 0;
		}

		$rearmed = 0;

		foreach ( array_keys( $map ) as $id ) {
			$record = self::get( $id );

			if ( self::STATE_SCHEDULED !== $record['state'] || (int) $record['run_at'] <= 0 ) {
				continue;
			}

			if ( false !== wp_next_scheduled( self::HOOK, array( $id ) ) ) {
				continue;
			}

			if ( self::arm_cron( $id, (int) $record['run_at'] ) ) {
				++$rearmed;

				self::log(
					$id,
					'warning',
					__( 'A scheduled continuation was missing its WordPress event (after a restart, a cleared cron schedule, or an early fire). It has been re-armed from the persisted record: the continuation is NOT lost.', 'conexao-event-importer' ),
					array(
						'reason'      => 'continuation_rearmed',
						'reason_code' => 'continuation_rearmed',
					)
				);
			}
		}

		return $rearmed;
	}

	/**
	 * Take a source's execution lock.
	 *
	 * `add_option()` is the atomic primitive used deliberately: it is a single
	 * INSERT against a unique key, so exactly one of two simultaneous workers can
	 * win, with no read-modify-write window between them.
	 *
	 * The lock is PER SOURCE. Cork's lock never blocks Wicklow, and there is
	 * deliberately no global lock in this class.
	 *
	 * @param string $source_id Source slug.
	 * @return bool True when this caller now owns the lock.
	 */
	public static function acquire_lock( $source_id ) {
		$key = self::lock_key( $source_id );
		$now = self::now();

		if ( add_option( $key, (string) $now, '', false ) ) {
			return true;
		}

		$held = (int) get_option( $key, 0 );

		// An abandoned lock (a worker killed by a restart) must not wedge its
		// source forever, so a lock older than LOCK_TTL is taken over.
		if ( $held > 0 && ( $now - $held ) > self::LOCK_TTL ) {
			update_option( $key, (string) $now, false );

			return true;
		}

		return false;
	}

	/**
	 * Release a source's execution lock.
	 *
	 * @param string $source_id Source slug.
	 * @return void
	 */
	public static function release_lock( $source_id ) {
		delete_option( self::lock_key( $source_id ) );
	}

	/**
	 * Whether a source is currently owned by a worker.
	 *
	 * @param string $source_id Source slug.
	 * @return bool
	 */
	public static function is_locked( $source_id ) {
		$held = (int) get_option( self::lock_key( $source_id ), 0 );

		return ( $held > 0 && ( self::now() - $held ) <= self::LOCK_TTL );
	}

	/**
	 * The option name of a source's lock.
	 *
	 * @param string $source_id Source slug.
	 * @return string
	 */
	protected static function lock_key( $source_id ) {
		return self::LOCK_PREFIX . sanitize_key( (string) $source_id );
	}

	/**
	 * The operator-facing state of one source's continuation.
	 *
	 * `phase` is the display truth, and it distinguishes "a future execution
	 * exists" from "enough state exists for a human to resume later" — the
	 * distinction this whole change exists to make:
	 *
	 *   cooling   — scheduled, cooldown not yet elapsed
	 *   due       — scheduled, cooldown elapsed, worker imminent
	 *   running   — a worker holds the lock
	 *   exhausted — stopped, visibly, and NOT scheduled
	 *   failed    — scheduling failed, visibly, and NOT scheduled
	 *   none      — no continuation exists
	 *
	 * @param string $source_id Source slug.
	 * @return array{scheduled:bool,phase:string,run_at:int,seconds_remaining:int,attempts:int,stalled:int,reason:string,page:int,page_count:int,state:string,message:string,locked:bool}
	 */
	public static function get_state( $source_id ) {
		$record = self::get( $source_id );
		$now    = self::now();

		$scheduled = ( self::STATE_SCHEDULED === $record['state'] && (int) $record['run_at'] > 0 );
		$locking   = self::is_locked( $source_id );

		if ( $locking ) {
			$phase = 'running';
		} elseif ( $scheduled ) {
			$phase = ( (int) $record['run_at'] > $now ) ? 'cooling' : 'due';
		} elseif ( self::STATE_EXHAUSTED === $record['state'] ) {
			$phase = 'exhausted';
		} elseif ( self::STATE_FAILED === $record['state'] ) {
			$phase = 'failed';
		} else {
			$phase = 'none';
		}

		return array(
			'scheduled'         => $scheduled,
			'phase'             => $phase,
			'state'             => (string) $record['state'],
			'run_at'            => (int) $record['run_at'],
			'seconds_remaining' => ( $scheduled && (int) $record['run_at'] > $now ) ? ( (int) $record['run_at'] - $now ) : 0,
			'attempts'          => (int) $record['attempts'],
			'stalled'           => (int) $record['stalled'],
			'reason'            => (string) $record['reason'],
			'page'              => (int) $record['last_page'],
			'page_count'        => (int) $record['page_count'],
			'message'           => (string) $record['message'],
			'locked'            => $locking,
		);
	}

	/**
	 * Forget every continuation (tests and an explicit operator reset).
	 *
	 * Unarms every event this class owns, then empties the map. It never touches
	 * the enumeration checkpoint, the cooldown ladder or the import history: this
	 * class owns scheduling state and nothing else.
	 *
	 * @return void
	 */
	public static function reset_all() {
		foreach ( array_keys( self::get_all() ) as $source_id ) {
			self::disarm_cron( $source_id );
			self::release_lock( $source_id );
		}

		update_option( self::OPTION_KEY, array(), true );
	}
}
