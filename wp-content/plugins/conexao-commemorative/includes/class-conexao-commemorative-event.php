<?php
/**
 * The selected commemorative event, as of one request.
 *
 * Returned by `conexao_commemorative_active()`. A read-only value object: it
 * carries the resolved window, the occurrence year and the localised copy, and
 * nothing that could be written back to the database.
 *
 * @package Conexao_Commemorative
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolved event value object.
 */
final class Conexao_Commemorative_Event {

	/**
	 * Event record ID (the record that produced this object).
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Canonical (default-language) record ID.
	 *
	 * @var int
	 */
	public $canonical_id;

	/**
	 * Stable logical identity (`_ce_event_key`).
	 *
	 * @var string
	 */
	public $event_key;

	/**
	 * Localised event name.
	 *
	 * @var string
	 */
	public $title;

	/**
	 * Localised heading override (empty = the package default).
	 *
	 * @var string
	 */
	public $heading;

	/**
	 * Localised subheading override.
	 *
	 * @var string
	 */
	public $subheading;

	/**
	 * Localised call-to-action label.
	 *
	 * @var string
	 */
	public $cta_label;

	/**
	 * Localised call-to-action URL.
	 *
	 * @var string
	 */
	public $cta_url;

	/**
	 * Experience-package slug.
	 *
	 * @var string
	 */
	public $presentation;

	/**
	 * Global priority used for conflicts.
	 *
	 * @var int
	 */
	public $priority;

	/**
	 * Resolved occurrence year (not the calendar year).
	 *
	 * @var int|null
	 */
	public $occurrence_year;

	/**
	 * Resolved window start (site timezone).
	 *
	 * @var string
	 */
	public $window_start;

	/**
	 * Resolved window end (site timezone).
	 *
	 * @var string
	 */
	public $window_end;

	/**
	 * Resolved window start as a Unix timestamp.
	 *
	 * @var int|null
	 */
	public $window_start_ts;

	/**
	 * Resolved window end as a Unix timestamp.
	 *
	 * @var int|null
	 */
	public $window_end_ts;

	/**
	 * Polylang language slug of the canonical record.
	 *
	 * @var string
	 */
	public $language;

	/**
	 * Whether the copy fell back to the canonical (PT) record.
	 *
	 * @var bool
	 */
	public $fallback;

	/**
	 * Whether this object came from an authorised preview.
	 *
	 * @var bool
	 */
	public $preview;

	/**
	 * Build the object from an evaluation plus localised content.
	 *
	 * @param array $evaluation Evaluation row (event + window + occurrence year).
	 * @param array $content    Localised content.
	 * @param bool  $preview    Whether this is an authorised preview.
	 */
	public function __construct( array $evaluation, array $content, $preview = false ) {
		$event  = isset( $evaluation['event'] ) ? (array) $evaluation['event'] : array();
		$window = isset( $evaluation['window'] ) && is_array( $evaluation['window'] ) ? $evaluation['window'] : null;

		$this->id              = isset( $event['id'] ) ? (int) $event['id'] : 0;
		$this->canonical_id    = isset( $event['canonical_id'] ) ? (int) $event['canonical_id'] : $this->id;
		$this->event_key       = isset( $event['event_key'] ) ? (string) $event['event_key'] : '';
		$this->title           = isset( $content['title'] ) ? (string) $content['title'] : '';
		$this->heading         = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$this->subheading      = isset( $content['subheading'] ) ? (string) $content['subheading'] : '';
		$this->cta_label       = isset( $content['cta_label'] ) ? (string) $content['cta_label'] : '';
		$this->cta_url         = isset( $content['cta_url'] ) ? (string) $content['cta_url'] : '';
		$this->presentation    = isset( $event['presentation'] ) ? (string) $event['presentation'] : '';
		$this->priority        = isset( $event['priority'] ) ? (int) $event['priority'] : 0;
		$this->occurrence_year = isset( $evaluation['occurrence_year'] ) ? (int) $evaluation['occurrence_year'] : null;
		$this->language        = isset( $event['language'] ) ? (string) $event['language'] : '';
		$this->fallback        = ! empty( $content['fallback'] );
		$this->preview         = (bool) $preview;

		$this->window_start    = $window ? $window['start']->format( 'Y-m-d H:i:s' ) : '';
		$this->window_end      = $window ? $window['end']->format( 'Y-m-d H:i:s' ) : '';
		$this->window_start_ts = $window ? (int) $window['start']->getTimestamp() : null;
		$this->window_end_ts   = $window ? (int) $window['end']->getTimestamp() : null;
	}

	/**
	 * Array form (templates and diagnostics).
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'id'              => $this->id,
			'canonical_id'    => $this->canonical_id,
			'event_key'       => $this->event_key,
			'title'           => $this->title,
			'heading'         => $this->heading,
			'subheading'      => $this->subheading,
			'cta_label'       => $this->cta_label,
			'cta_url'         => $this->cta_url,
			'presentation'    => $this->presentation,
			'priority'        => $this->priority,
			'occurrence_year' => $this->occurrence_year,
			'window_start'    => $this->window_start,
			'window_end'      => $this->window_end,
			'language'        => $this->language,
			'fallback'        => $this->fallback,
			'preview'         => $this->preview,
		);
	}
}
