<?php
/**
 * Structured import result tracking.
 *
 * Centralizes per-event outcome tracking so the admin can see exactly what
 * happened during an import run: created, updated, unchanged, skipped,
 * and failed events with human-readable reasons.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Import_Result {

	/** @var string Source slug. */
	protected $source_id;

	/** @var string Source display name. */
	protected $source_name;

	/** @var array Event-level outcomes keyed by event title. */
	protected $events = array();

	/** @var array Aggregate counters. */
	protected $counts = array(
		'found'                => 0,
		'created'              => 0,
		'updated'              => 0,
		'unchanged'            => 0,
		'duplicates'           => 0,
		'skipped'              => 0,
		'skipped_past'         => 0,
		'skipped_invalid_date' => 0,
		'failed'               => 0,
	);

	/** @var array Global/fatal error messages that stopped the source import. */
	protected $fatal_errors = array();

	/** @var string Overall run status: success|warning|partial|failed */
	protected $status = 'success';

	/** @var int Unique run ID (microtime). */
	protected $run_id;

	/**
	 * Constructor.
	 *
	 * @param string $source_id   Source slug.
	 * @param string $source_name Source display name.
	 */
	public function __construct( $source_id = '', $source_name = '' ) {
		$this->source_id   = $source_id;
		$this->source_name = $source_name;
		$this->run_id      = (string) microtime( true );
	}

	/**
	 * Get the run ID.
	 *
	 * @return string
	 */
	public function get_run_id() {
		return $this->run_id;
	}

	/**
	 * Get the source slug.
	 *
	 * @return string
	 */
	public function get_source_id() {
		return $this->source_id;
	}

	/**
	 * Get the source display name.
	 *
	 * @return string
	 */
	public function get_source_name() {
		return $this->source_name;
	}

	/**
	 * Set the number of events found/fetched from the source.
	 *
	 * @param int $count Number of raw events found.
	 */
	public function set_found( $count ) {
		$this->counts['found'] = max( 0, (int) $count );
	}

	/**
	 * Record an event that was created.
	 *
	 * @param string $event_title Event title.
	 * @param int    $post_id     WordPress post ID.
	 */
	public function add_created( $event_title, $post_id = 0 ) {
		$this->counts['created']++;
		$this->events[] = array(
			'title'    => $event_title,
			'post_id'  => (int) $post_id,
			'outcome'  => 'created',
			'message'  => __( 'Event created.', 'conexao-event-importer' ),
			'severity' => 'success',
		);
	}

	/**
	 * Record an event that was updated.
	 *
	 * @param string $event_title Event title.
	 * @param int    $post_id     WordPress post ID.
	 */
	public function add_updated( $event_title, $post_id = 0 ) {
		$this->counts['updated']++;
		$this->events[] = array(
			'title'    => $event_title,
			'post_id'  => (int) $post_id,
			'outcome'  => 'updated',
			'message'  => __( 'Event updated with new data.', 'conexao-event-importer' ),
			'severity' => 'success',
		);
	}

	/**
	 * Record an event that was unchanged.
	 *
	 * @param string $event_title Event title.
	 * @param int    $post_id     WordPress post ID.
	 */
	public function add_unchanged( $event_title, $post_id = 0 ) {
		$this->counts['unchanged']++;
		$this->events[] = array(
			'title'    => $event_title,
			'post_id'  => (int) $post_id,
			'outcome'  => 'unchanged',
			'message'  => __( 'Event data has not changed.', 'conexao-event-importer' ),
			'severity' => 'success',
		);
	}

	/**
	 * Record an event that was a duplicate (already existed under another URL/ID).
	 *
	 * @param string $event_title Event title.
	 * @param int    $post_id     WordPress post ID.
	 */
	public function add_duplicate( $event_title, $post_id = 0 ) {
		$this->counts['duplicates']++;
		$this->events[] = array(
			'title'    => $event_title,
			'post_id'  => (int) $post_id,
			'outcome'  => 'duplicate',
			'message'  => __( 'Duplicate event skipped (already exists).', 'conexao-event-importer' ),
			'severity' => 'warning',
		);
		$this->maybe_warn();
	}

	/**
	 * Record an event that was skipped (missing critical data, online-only, etc.).
	 *
	 * @param string $event_title Event title.
	 * @param string $reason      Human-readable skip reason.
	 */
	public function add_skipped( $event_title, $reason = '' ) {
		$this->counts['skipped']++;
		$this->events[] = array(
			'title'    => $event_title,
			'post_id'  => 0,
			'outcome'  => 'skipped',
			'message'  => $reason ? $reason : __( 'Event skipped.', 'conexao-event-importer' ),
			'severity' => 'warning',
		);
		$this->maybe_warn();
	}

	/**
	 * Record an event skipped because its relevant date/time has already
	 * passed — the event has ended, so it is never created or updated.
	 *
	 * Also increments the generic skipped counter so legacy consumers keep
	 * seeing consistent totals.
	 *
	 * @param string $event_title Event title.
	 * @param string $reason      Human-readable skip reason.
	 */
	public function add_skipped_past( $event_title, $reason = '' ) {
		$this->counts['skipped_past']++;
		$this->add_skipped( $event_title, $reason );
	}

	/**
	 * Record an event skipped because its date could not be evaluated
	 * (missing required date, invalid format, or unparseable value).
	 *
	 * Also increments the generic skipped counter so legacy consumers keep
	 * seeing consistent totals.
	 *
	 * @param string $event_title Event title.
	 * @param string $reason      Human-readable skip reason.
	 */
	public function add_skipped_invalid_date( $event_title, $reason = '' ) {
		$this->counts['skipped_invalid_date']++;
		$this->add_skipped( $event_title, $reason );
	}

	/**
	 * Record a per-event failure.
	 *
	 * The import continues processing other events when this is called.
	 *
	 * @param string $event_title Event title.
	 * @param string $reason      Human-readable failure reason.
	 * @param string $technical   Optional technical detail for debugging (in details).
	 */
	public function add_failed( $event_title, $reason = '', $technical = '' ) {
		$this->counts['failed']++;
		$this->events[] = array(
			'title'    => $event_title,
			'post_id'  => 0,
			'outcome'  => 'failed',
			'message'  => $reason ? $reason : __( 'Event import failed.', 'conexao-event-importer' ),
			'technical' => $technical,
			'severity' => 'error',
		);
		$this->status = 'partial';
	}

	/**
	 * Record a global/fatal error that prevented an entire source from importing.
	 *
	 * @param string $message    Human-readable error message.
	 * @param string $technical  Optional technical detail for debugging.
	 */
	public function add_fatal_error( $message, $technical = '' ) {
		$this->fatal_errors[] = array(
			'message'   => $message,
			'technical' => $technical,
		);
		$this->status = 'failed';
	}

	/**
	 * Check whether the run had a fatal/global error.
	 *
	 * @return bool
	 */
	public function has_fatal_error() {
		return 'failed' === $this->status;
	}

	/**
	 * Get the overall run status.
	 *
	 * @return string success|warning|partial|failed
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Get the list of event outcomes.
	 *
	 * @return array
	 */
	public function get_events() {
		return $this->events;
	}

	/**
	 * Get only the failed events.
	 *
	 * @return array
	 */
	public function get_failed_events() {
		return array_values(
			array_filter(
				$this->events,
				function ( $event ) {
					return 'failed' === $event['outcome'];
				}
			)
		);
	}

	/**
	 * Get only the skipped events.
	 *
	 * @return array
	 */
	public function get_skipped_events() {
		return array_values(
			array_filter(
				$this->events,
				function ( $event ) {
					return 'skipped' === $event['outcome'] || 'duplicate' === $event['outcome'];
				}
			)
		);
	}

	/**
	 * Get aggregate counters.
	 *
	 * @return array
	 */
	public function get_counts() {
		return $this->counts;
	}

	/**
	 * Get the per-source aggregate stats (legacy "stats array" format).
	 *
	 * Keeps backward compatibility with the existing run_source() return type
	 * while adding the new counts used by the improved UI.
	 *
	 * @return array
	 */
	public function get_stats() {
		return array_merge(
			$this->counts,
			array(
				'found'         => $this->counts['found'],
				'new'           => $this->counts['created'],
				'errors'        => $this->counts['failed'],
				'status'        => $this->get_status(),
				'fatal_errors'  => $this->fatal_errors,
				'event_results' => $this->events,
			)
		);
	}

	/**
	 * Get the fatal errors.
	 *
	 * @return array
	 */
	public function get_fatal_errors() {
		return $this->fatal_errors;
	}

	/**
	 * Export the result as an array (used by history / logging).
	 *
	 * @return array
	 */
	public function to_array() {
		return $this->get_stats();
	}

	/**
	 * Whether any events were recorded at all.
	 *
	 * @return bool
	 */
	public function has_events() {
		return $this->counts['found'] > 0 || ! empty( $this->events );
	}

	/**
	 * Upgrade the status to warning when a non-fatal warning occurs.
	 */
	protected function maybe_warn() {
		if ( 'success' === $this->status ) {
			$this->status = 'warning';
		}
	}
}