<?php
/**
 * Import scheduler.
 *
 * Fully autonomous import pipeline built on WP-Cron:
 *
 *  - A DAILY kickoff event (`conexao_event_import_cron`, 03:00 Europe/Dublin)
 *    checks every active source and queues the ones that are DUE according to
 *    their per-source `import_frequency` (daily sources run daily, weekly
 *    sources roughly once per week).
 *  - Each queued source is processed in its OWN cron tick
 *    (`conexao_event_import_tick`), one source per request, so a slow source
 *    can never blow the PHP time limit for the whole run.
 *  - A transient lock prevents overlapping runs (cron vs manual vs REST).
 *  - Sources that fail with a fatal error are retried up to MAX_RETRIES times
 *    within the same run before being declared failed.
 *  - When the queue empties, the run is finalized: combined history entry,
 *    expiry housekeeping and failure notification email.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_Scheduler {

	/** Daily kickoff cron hook. */
	const CRON_HOOK = 'conexao_event_import_cron';

	/** Chunked per-source tick hook (single events). */
	const TICK_HOOK = 'conexao_event_import_tick';

	/** Option holding the current run state. */
	const RUN_STATE_OPTION = 'conexao_event_import_run';

	/** Transient lock preventing concurrent runs. */
	const LOCK_TRANSIENT = 'conexao_event_import_lock';

	/** Option tracking scheduler migrations (weekly→daily etc.). */
	const VERSION_OPTION = 'conexao_event_importer_scheduler_version';

	/** Current scheduler behaviour version. */
	const SCHEDULER_VERSION = 2;

	/** How long the run lock lives (seconds). Refreshed every tick. */
	const LOCK_TTL = 1800;

	/** Seconds between ticks. */
	const TICK_DELAY = 60;

	/** Fatal-failure retries per source within one run. */
	const MAX_RETRIES = 2;

	/** A run older than this is considered crashed and may be taken over. */
	const STALE_RUN_SECONDS = 7200;

	/** @var Conexao_Event_Importer_Engine|null */
	protected $importer;

	/**
	 * Constructor.
	 *
	 * @param Conexao_Event_Importer_Engine|null $importer Importer engine.
	 */
	public function __construct( $importer = null ) {
		$this->importer = $importer;

		add_action( self::CRON_HOOK, array( $this, 'run_kickoff' ) );
		add_action( self::TICK_HOOK, array( $this, 'run_tick' ) );
		add_filter( 'cron_schedules', array( $this, 'add_recurrences' ) );
	}

	/**
	 * Register custom recurrences.
	 *
	 * @param array $schedules WP cron schedules.
	 * @return array
	 */
	public function add_recurrences( $schedules ) {
		$schedules['conexao_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'conexao-event-importer' ),
		);
		$schedules['conexao_daily'] = array(
			'interval' => DAY_IN_SECONDS,
			'display'  => __( 'Once Daily (03:00)', 'conexao-event-importer' ),
		);
		return $schedules;
	}

	/**
	 * Ensure the daily kickoff cron is scheduled.
	 *
	 * Idempotent, and migrates installs that still carry the legacy weekly
	 * schedule to the new daily schedule.
	 */
	public function maybe_schedule() {
		$version = (int) get_option( self::VERSION_OPTION, 0 );

		if ( $version < self::SCHEDULER_VERSION ) {
			// Migrate: drop whatever recurrence exists and reschedule fresh.
			self::clear_schedule();
			$this->schedule();
			update_option( self::VERSION_OPTION, self::SCHEDULER_VERSION, false );
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$this->schedule();
		}
	}

	/**
	 * Schedule the daily kickoff for the next 03:00 Europe/Dublin.
	 */
	public function schedule() {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}

		add_filter( 'cron_schedules', array( $this, 'add_recurrences' ) );

		$timezone = new DateTimeZone( 'Europe/Dublin' );
		$now      = new DateTime( 'now', $timezone );
		$target   = clone $now;
		$target->modify( 'tomorrow 03:00' );

		wp_schedule_event( $target->getTimestamp(), 'conexao_daily', self::CRON_HOOK );
	}

	/**
	 * Clear the scheduled kickoff and any pending tick.
	 */
	public static function clear_schedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::TICK_HOOK );
	}

	/**
	 * Get the importer engine (lazy).
	 *
	 * @return Conexao_Event_Importer_Engine|null
	 */
	protected function get_importer() {
		if ( ! $this->importer ) {
			$plugin         = Conexao_Event_Importer::instance();
			$this->importer = $plugin->importer;
		}
		return $this->importer;
	}

	/**
	 * Cron kickoff handler: start a chunked run of all due sources.
	 */
	public function run_kickoff() {
		$this->start_run( 'cron' );
	}

	/**
	 * Start a new chunked import run.
	 *
	 * Builds the queue of active sources that are due according to their
	 * configured frequency, acquires the run lock and schedules the first
	 * processing tick.
	 *
	 * @param string $trigger What started the run: cron|rest|cli|manual.
	 * @return array{ok:bool,run_id:string,queued:int,message:string}
	 */
	public function start_run( $trigger = 'cron' ) {
		// Refuse to start while another live run holds the lock.
		if ( $this->is_locked() && ! $this->lock_is_stale() ) {
			Conexao_Import_Log::add(
				'scheduler',
				'warning',
				__( 'Import skipped: another import run is already in progress.', 'conexao-event-importer' ),
				array( 'run_id' => '' )
			);
			return array(
				'ok'      => false,
				'run_id'  => '',
				'queued'  => 0,
				'message' => __( 'Another import run is already in progress.', 'conexao-event-importer' ),
			);
		}

		// Daily housekeeping: expire past events regardless of what is due.
		Conexao_Event_Status::mark_expired_events();

		// Build the queue of due sources.
		$queue = $this->build_due_queue();

		if ( empty( $queue ) ) {
			Conexao_Import_Log::add(
				'scheduler',
				'info',
				__( 'Scheduled import ran: no sources are due right now.', 'conexao-event-importer' ),
				array( 'run_id' => '' )
			);
			return array(
				'ok'      => true,
				'run_id'  => '',
				'queued'  => 0,
				'message' => __( 'No sources are due for import.', 'conexao-event-importer' ),
			);
		}

		$run_id = 'chunk-' . (string) microtime( true );

		$state = array(
			'run_id'  => $run_id,
			'trigger' => sanitize_key( $trigger ),
			'started' => time(),
			'queue'   => $queue,
			'results' => array(),
			'status'  => 'running',
		);

		update_option( self::RUN_STATE_OPTION, $state, false );
		$this->acquire_lock( $run_id );

		Conexao_Import_Log::add(
			'scheduler',
			'info',
			sprintf(
				/* translators: 1: number of sources, 2: trigger */
				__( 'Import run started: %1$d source(s) queued (trigger: %2$s).', 'conexao-event-importer' ),
				count( $queue ),
				$trigger
			),
			array( 'run_id' => $run_id )
		);

		$this->schedule_next_tick();

		return array(
			'ok'      => true,
			'run_id'  => $run_id,
			'queued'  => count( $queue ),
			'message' => sprintf(
				/* translators: %d: number of sources */
				_n( '%d source queued for import.', '%d sources queued for import.', count( $queue ), 'conexao-event-importer' ),
				count( $queue )
			),
		);
	}

	/**
	 * Process ONE queued source, then chain the next tick or finalize.
	 *
	 * Runs inside its own cron request so each source gets a full PHP time
	 * budget and a slow source cannot starve the rest of the run.
	 */
	public function run_tick() {
		$state = $this->get_run_state();

		if ( empty( $state ) || 'running' !== $state['status'] ) {
			return; // Nothing to do (state lost or already finalized).
		}

		// Re-acquire the lock if it expired mid-run (long-running sources).
		if ( ! $this->is_locked() ) {
			$this->acquire_lock( isset( $state['run_id'] ) ? $state['run_id'] : 'tick' );
		}

		$item = array_shift( $state['queue'] );

		if ( empty( $item['id'] ) ) {
			$this->finalize_run( $state );
			return;
		}

		$source_id = $item['id'];
		$attempts  = isset( $item['attempts'] ) ? (int) $item['attempts'] : 0;

		$result = $this->get_importer()->run_source( $source_id );

		// Accumulate the result for the final summary.
		$state['results'][ $source_id ] = $result;

		// Retry fatal failures within the same run (per-event failures are NOT retried).
		$is_fatal = isset( $result['status'] ) && 'failed' === $result['status'];
		if ( $is_fatal && $attempts < self::MAX_RETRIES ) {
			$state['queue'][] = array(
				'id'       => $source_id,
				'attempts' => $attempts + 1,
			);

			Conexao_Import_Log::add(
				$source_id,
				'warning',
				sprintf(
					/* translators: 1: source id, 2: attempt number */
					__( 'Source "%1$s" failed fatally — retry %2$d scheduled later in this run.', 'conexao-event-importer' ),
					$source_id,
					$attempts + 1
				),
				array( 'run_id' => isset( $state['run_id'] ) ? $state['run_id'] : '' )
			);
		}

		update_option( self::RUN_STATE_OPTION, $state, false );

		if ( ! empty( $state['queue'] ) ) {
			$this->schedule_next_tick();
		} else {
			$this->finalize_run( $state );
		}
	}

	/**
	 * Finalize a finished run: combined history entry, notification email,
	 * release the lock and clear the run state.
	 *
	 * @param array $state Run state.
	 */
	protected function finalize_run( $state ) {
		$results = isset( $state['results'] ) && is_array( $state['results'] ) ? $state['results'] : array();

		$combined = $this->combine_results( $results );

		// Record a combined history entry when more than one source ran.
		if ( count( $results ) > 1 ) {
			Conexao_Import_History::record(
				'all',
				$combined,
				$combined['status'],
				'',
				array(
					'run_id'        => isset( $state['run_id'] ) ? $state['run_id'] : '',
					'fatal_errors'  => $combined['fatal_errors'],
					'failed_events' => Conexao_Event_Importer_Engine::extract_failed_events_public( $combined['event_results'] ),
				)
			);
		}

		// Email alert on failures (respects settings).
		$notifier = new Conexao_Import_Notifier();
		$notifier->notify_run_finished( $combined, isset( $state['trigger'] ) ? $state['trigger'] : 'cron' );

		Conexao_Import_Log::add(
			'scheduler',
			'failed' === $combined['status'] ? 'error' : 'info',
			sprintf(
				/* translators: 1: status, 2: number of sources processed */
				__( 'Import run finished: status %1$s across %2$d source(s).', 'conexao-event-importer' ),
				$combined['status'],
				count( $results )
			),
			array( 'run_id' => isset( $state['run_id'] ) ? $state['run_id'] : '' )
		);

		delete_option( self::RUN_STATE_OPTION );
		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Combine per-source result arrays into a single combined result.
	 *
	 * Mirrors the aggregation logic of the synchronous run_all() so history
	 * entries and notifications look identical regardless of trigger.
	 *
	 * @param array $results source_id => stats array.
	 * @return array Combined result.
	 */
	protected function combine_results( $results ) {
		$combined = array(
			'found'         => 0,
			'created'       => 0,
			'updated'       => 0,
			'unchanged'     => 0,
			'duplicates'    => 0,
			'skipped'       => 0,
			'needs_review'  => 0,
			'failed'        => 0,
			'errors'        => 0,
			'new'           => 0,
			'fatal_errors'  => array(),
			'event_results' => array(),
			'status'        => 'success',
			'sources'       => array(),
		);

		foreach ( $results as $source_id => $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$combined['found']        += isset( $result['found'] ) ? (int) $result['found'] : 0;
			$combined['created']      += isset( $result['created'] ) ? (int) $result['created'] : 0;
			$combined['new']          += isset( $result['new'] ) ? (int) $result['new'] : 0;
			$combined['updated']      += isset( $result['updated'] ) ? (int) $result['updated'] : 0;
			$combined['unchanged']    += isset( $result['unchanged'] ) ? (int) $result['unchanged'] : 0;
			$combined['duplicates']   += isset( $result['duplicates'] ) ? (int) $result['duplicates'] : 0;
			$combined['skipped']      += isset( $result['skipped'] ) ? (int) $result['skipped'] : 0;
			$combined['needs_review'] += isset( $result['needs_review'] ) ? (int) $result['needs_review'] : 0;
			$combined['failed']       += isset( $result['failed'] ) ? (int) $result['failed'] : 0;
			$combined['errors']       += isset( $result['errors'] ) ? (int) $result['errors'] : 0;

			if ( ! empty( $result['fatal_errors'] ) && is_array( $result['fatal_errors'] ) ) {
				$combined['fatal_errors'] = array_merge( $combined['fatal_errors'], $result['fatal_errors'] );
			}
			if ( ! empty( $result['event_results'] ) && is_array( $result['event_results'] ) ) {
				$combined['event_results'] = array_merge( $combined['event_results'], $result['event_results'] );
			}

			$combined['sources'][ $source_id ] = $result;

			if ( isset( $result['status'] ) ) {
				if ( 'failed' === $result['status'] ) {
					$combined['status'] = 'failed';
				} elseif ( 'partial' === $result['status'] && 'success' === $combined['status'] ) {
					$combined['status'] = 'partial';
				} elseif ( 'warning' === $result['status'] && 'success' === $combined['status'] ) {
					$combined['status'] = 'warning';
				}
			}
		}

		return $combined;
	}

	/**
	 * Build the queue of active sources that are due for an import.
	 *
	 * A source is due when it has never been checked, or when the time since
	 * its last check exceeds its configured frequency (with a small grace).
	 *
	 * @return array[] Queue items: {id:string, attempts:int}.
	 */
	protected function build_due_queue() {
		if ( ! class_exists( 'Conexao_Event_Sources' ) ) {
			return array();
		}

		$sources = new Conexao_Event_Sources();
		$queue   = array();

		foreach ( $sources->get_active() as $source ) {
			if ( $this->is_source_due( $source ) ) {
				$queue[] = array(
					'id'       => $source['id'],
					'attempts' => 0,
				);
			}
		}

		return $queue;
	}

	/**
	 * Determine whether a source is due for an import based on its frequency.
	 *
	 * @param array $source Source config.
	 * @return bool
	 */
	public function is_source_due( $source ) {
		$frequency = isset( $source['import_frequency'] ) ? $source['import_frequency'] : 'weekly';
		$interval  = ( 'daily' === $frequency ) ? DAY_IN_SECONDS : WEEK_IN_SECONDS;

		// Small grace so "daily" sources don't drift earlier each day.
		$grace    = HOUR_IN_SECONDS;
		$due_after = max( 0, $interval - $grace );

		$last_checked = isset( $source['last_checked'] ) ? (string) $source['last_checked'] : '';
		if ( '' === trim( $last_checked ) ) {
			return true; // Never checked — always due.
		}

		try {
			$dt = DateTime::createFromFormat( 'Y-m-d H:i:s', $last_checked, wp_timezone() );
			if ( ! $dt ) {
				return true; // Unparseable timestamp — treat as due.
			}
			$elapsed = time() - $dt->getTimestamp();
			return $elapsed >= $due_after;
		} catch ( Exception $e ) {
			return true;
		}
	}

	/**
	 * Schedule the next processing tick.
	 */
	protected function schedule_next_tick() {
		if ( ! wp_next_scheduled( self::TICK_HOOK ) ) {
			wp_schedule_single_event( time() + self::TICK_DELAY, self::TICK_HOOK );
			// Nudge WP-Cron so low-traffic sites process ticks promptly.
			spawn_cron();
		}
	}

	/**
	 * Get the current run state.
	 *
	 * @return array Empty array when no run is in progress.
	 */
	public function get_run_state() {
		$state = get_option( self::RUN_STATE_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state ) ) {
			return array();
		}

		// Take over crashed runs (e.g. host restarted mid-run).
		$started = isset( $state['started'] ) ? (int) $state['started'] : 0;
		if ( $started > 0 && ( time() - $started ) > self::STALE_RUN_SECONDS ) {
			return array();
		}

		return $state;
	}

	/**
	 * Whether an import run is currently in progress.
	 *
	 * @return bool
	 */
	public function is_running() {
		$state = $this->get_run_state();
		return ! empty( $state ) && 'running' === ( isset( $state['status'] ) ? $state['status'] : '' );
	}

	/**
	 * Whether the run lock is currently held.
	 *
	 * @return bool
	 */
	public function is_locked() {
		return (bool) get_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Whether the held lock belongs to a dead run (stale takeover allowed).
	 *
	 * @return bool
	 */
	protected function lock_is_stale() {
		$state = get_option( self::RUN_STATE_OPTION, array() );
		if ( ! is_array( $state ) || empty( $state ) ) {
			// Lock without state is orphaned — stale.
			return true;
		}
		$started = isset( $state['started'] ) ? (int) $state['started'] : 0;
		return $started > 0 && ( time() - $started ) > self::STALE_RUN_SECONDS;
	}

	/**
	 * Acquire the run lock.
	 *
	 * @param string $run_id Run identifier stored in the lock.
	 */
	protected function acquire_lock( $run_id ) {
		set_transient( self::LOCK_TRANSIENT, (string) $run_id, self::LOCK_TTL );
	}

	/**
	 * Force-release the lock and drop any run state (manual recovery).
	 */
	public function force_unlock() {
		delete_option( self::RUN_STATE_OPTION );
		delete_transient( self::LOCK_TRANSIENT );
		Conexao_Import_Log::add(
			'scheduler',
			'info',
			__( 'Import run lock released manually.', 'conexao-event-importer' ),
			array( 'run_id' => '' )
		);
	}

	/**
	 * Get the next scheduled kickoff timestamp.
	 *
	 * @return int|false Unix timestamp or false when not scheduled.
	 */
	public function get_next_scheduled() {
		return wp_next_scheduled( self::CRON_HOOK );
	}
}