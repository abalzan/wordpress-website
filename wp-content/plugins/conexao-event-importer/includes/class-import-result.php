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

	/**
	 * Explanation recorded for an incomplete source enumeration.
	 *
	 * @var string
	 */
	protected $incomplete_enumeration_note = '';

	/**
	 * Machine-readable cause of the incomplete enumeration.
	 *
	 * Kept separate from the human note so Import History and source health can
	 * say WHY the walk stopped (rate-limit cooldown, request budget, time
	 * budget, page cap, upstream fetch failure, checkpoint resume) without
	 * parsing prose. Empty means "incomplete, cause not classified".
	 *
	 * @var string
	 */
	protected $incomplete_enumeration_reason = '';

	/**
	 * Whether the source listing was only partially enumerated.
	 *
	 * @var bool
	 */
	protected $incomplete_enumeration = false;

	/** @var string Overall run status: success|warning|partial|rate_limited|failed */
	protected $status = 'success';

	/** @var bool Whether this source was skipped without an outbound request. */
	protected $source_skipped = false;

	/** @var string Why the source was skipped or deferred. */
	protected $source_skip_reason = '';

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
		++$this->counts['created'];
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
		++$this->counts['updated'];
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
		++$this->counts['unchanged'];
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
		++$this->counts['duplicates'];
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
		++$this->counts['skipped'];
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
		++$this->counts['skipped_past'];
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
		++$this->counts['skipped_invalid_date'];
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
		++$this->counts['failed'];
		$this->events[] = array(
			'title'     => $event_title,
			'post_id'   => 0,
			'outcome'   => 'failed',
			'message'   => $reason ? $reason : __( 'Event import failed.', 'conexao-event-importer' ),
			'technical' => $technical,
			'severity'  => 'error',
		);
		$this->status   = 'partial';
	}

	/**
	 * Record that the source listing was only partially enumerated.
	 *
	 * This is NOT a failure: every event the walk did fetch is still imported.
	 * It is a truthfulness signal — the run must not present a truncated result
	 * as a complete one, and it must not mark absent events as source_not_found
	 * (see mark_missing_events()).
	 *
	 * ## Stage 3 (P12): incomplete is `partial`, never `warning`
	 *
	 * Before Stage 3 this only ran `maybe_warn()`, so a source that stopped at
	 * page 13 of 49 because its time budget ran out was recorded as `warning` —
	 * the same status a completed run with one skipped event received. The
	 * operator could not tell "finished, with a caveat" from "did not finish".
	 *
	 * An incomplete enumeration now sets `partial`: the walk did not retrieve
	 * the intended event set. It never downgrades a `failed` or `rate_limited`
	 * run, because those carry a stronger, more specific meaning that the parent
	 * aggregation depends on.
	 *
	 * A genuinely non-fatal caveat on a COMPLETED walk is `add_warning()`.
	 *
	 * @param string $message     Human-readable explanation.
	 * @param string $reason_code Machine-readable cause (time_budget, page_cap,
	 *                            request_budget, ...). Empty leaves any cause
	 *                            already recorded.
	 */
	public function add_incomplete_enumeration( $message = '', $reason_code = '' ) {
		$this->incomplete_enumeration = true;
		if ( '' !== trim( (string) $message ) ) {
			$this->incomplete_enumeration_note = (string) $message;
		}
		if ( '' !== trim( (string) $reason_code ) ) {
			$this->incomplete_enumeration_reason = sanitize_key( (string) $reason_code );
		}
		if ( in_array( $this->status, array( 'success', 'warning' ), true ) ) {
			$this->status = 'partial';
		}
	}

	/**
	 * Record a non-fatal caveat on a run that DID finish its enumeration.
	 *
	 * The distinction from `add_incomplete_enumeration()` is the whole point of
	 * Stage 3's status model: a completed walk with a caveat is `warning`, a
	 * walk that stopped early is `partial`. Never downgrades partial, rate
	 * limited or failed.
	 *
	 * @param string $message Human-readable caveat.
	 */
	public function add_warning( $message = '' ) {
		if ( '' !== trim( (string) $message ) ) {
			$this->incomplete_enumeration_note = (string) $message;
		}
		$this->maybe_warn();
	}

	/**
	 * The machine-readable cause of an incomplete enumeration.
	 *
	 * @return string
	 */
	public function get_incomplete_enumeration_reason() {
		return (string) $this->incomplete_enumeration_reason;
	}

	/**
	 * The recorded explanation for an incomplete source enumeration.
	 *
	 * @return string
	 */
	public function get_incomplete_enumeration_note() {
		return $this->incomplete_enumeration_note;
	}

	/**
	 * Whether the source listing was only partially enumerated.
	 *
	 * @return bool
	 */
	public function is_enumeration_incomplete() {
		return (bool) $this->incomplete_enumeration;
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
		$this->status         = 'failed';
	}

	/**
	 * Record a rate-limited or deferred source without misclassifying it as a
	 * completed empty source or a request-budget failure.
	 *
	 * Stage 3 (P12): the rate-limit outcome also records a machine-readable
	 * cause, so Import History and the Event Sources screen can say
	 * "rate-limit cooldown" instead of only "rate_limited". The value stays in
	 * the vocabulary the client already emits (`rate_limited`,
	 * `rate_limited_deferred`), so no new status word is introduced.
	 *
	 * @param string $message     Human-readable reason.
	 * @param bool   $skipped     Whether no request was sent for this source.
	 * @param string $reason_code Machine-readable cause; defaults to
	 *                            `rate_limited`.
	 * @return void
	 */
	public function mark_rate_limited( $message = '', $skipped = false, $reason_code = 'rate_limited' ) {
		$this->status         = 'rate_limited';
		$this->incomplete_enumeration = true;
		$this->source_skipped = (bool) $skipped;
		$this->source_skip_reason = (string) $message;
		$this->incomplete_enumeration_reason = sanitize_key( '' !== trim( (string) $reason_code ) ? $reason_code : 'rate_limited' );
		if ( '' !== trim( (string) $message ) ) {
			$this->incomplete_enumeration_note = (string) $message;
		}
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
	 * ## Vocabulary (Stage 3 / P12 — verified against Stage 1, not replaced)
	 *
	 *   success        — enumerated and imported without a recorded issue
	 *   warning        — COMPLETED enumeration with a non-fatal caveat (a
	 *                    skipped past event, an enrichment failure, a resumed
	 *                    subset whose pages were already confirmed)
	 *   partial        — did NOT complete the intended work: an incomplete
	 *                    enumeration, or per-event failures, or both. Never a
	 *                    `warning`, because the retrieval itself fell short.
	 *   rate_limited   — the shared Eventbrite breaker (P1) stopped planned work
	 *   failed         — a fatal error prevented the source importing at all
	 *
	 * Parent aggregation (`Conexao_Event_Importer_Engine::aggregate_parent_status()`)
	 * maps these to `failed > rate_limited > partial > completed_with_errors >
	 * completed` and is unchanged by Stage 3.
	 *
	 * @return string success|warning|partial|rate_limited|failed
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
				// Truthfulness flag: false means the returned event list is a
				// SUBSET of the real source listing, not the whole thing.
				'complete'      => $this->incomplete_enumeration ? false : true,
				// Stage 3 (P12): WHY it is incomplete, as a stable code, so the
				// reader never has to infer the cause from the status word or
				// parse the message.
				'incomplete_reason' => $this->incomplete_enumeration_reason,
				'event_results'   => $this->events,
				'source_skipped'  => $this->source_skipped,
				'source_skip_reason' => $this->source_skip_reason,
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
