<?php
/**
 * Weekly course import scheduler.
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Course_Import_Scheduler {

	const CRON_HOOK = 'conexao_course_import_cron';

	/** @var Conexao_Course_Importer_Engine|null */
	protected $importer;

	/**
	 * Constructor.
	 *
	 * @param Conexao_Course_Importer_Engine|null $importer Importer engine.
	 */
	public function __construct( $importer = null ) {
		$this->importer = $importer;

		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_import' ) );
		add_filter( 'cron_schedules', array( $this, 'add_weekly_recurrence' ) );
	}

	/**
	 * Ensure the cron job is scheduled.
	 */
	public function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			$this->schedule();
		}
	}

	/**
	 * Register a custom weekly recurrence.
	 *
	 * @param array $schedules WP cron schedules.
	 * @return array
	 */
	public function add_weekly_recurrence( $schedules ) {
		$schedules['conexao_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once Weekly (Mondays 03:00)', 'conexao-course-importer' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the import for next Monday at 03:00 Europe/Dublin.
	 */
	public function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			add_filter( 'cron_schedules', array( $this, 'add_weekly_recurrence' ) );

			$timezone = new DateTimeZone( 'Europe/Dublin' );
			$now      = new DateTime( 'now', $timezone );
			$target   = clone $now;
			$target->modify( 'next monday 03:00' );

			wp_schedule_event( $target->getTimestamp(), 'conexao_weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the scheduled import.
	 */
	public static function clear_schedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Run the scheduled import.
	 */
	public function run_scheduled_import() {
		if ( ! $this->importer ) {
			$plugin       = Conexao_Course_Importer::instance();
			$this->importer = $plugin->importer;
		}

		if ( $this->importer ) {
			$this->importer->run_all();
		}
	}
}