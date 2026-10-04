<?php
/**
 * Per-source Eventbrite enumeration checkpoint.
 *
 * ## Why this exists
 *
 * G10. G8/G9 measured the shape of the problem precisely: a live Cork walk
 * reaches 31 pages (618 unique events) before Eventbrite answers HTTP 429, and
 * the listing declares 49 pages. A bounded run therefore never finishes. The
 * pre-G10 walk always restarted at page 1, so a second run re-spent the same
 * 31 requests to re-discover the same 618 events and reached no new page at
 * all. That is the waste this class removes.
 *
 * A checkpoint records the last position in the enumeration whose page was
 * successfully fetched, parsed and handed to the importer. The next eligible
 * run resumes from there instead of from page 1.
 *
 * ## What a checkpoint is NOT
 *
 * It is explicitly NOT a completeness claim. A checkpoint says "we got this far
 * safely", never "this is everything". Completeness remains derived from the
 * walk itself (`is_fetch_complete()`), which accounts for the upstream
 * truncation guard, the time budget, per-page failures and the page limit.
 * Nothing in this class can turn an incomplete enumeration complete, and
 * nothing in it grants `mark_missing_events()` any permission.
 *
 * ## Ordering contract (the load-bearing invariant)
 *
 *     fetch -> validate -> normalize/process -> success confirmed -> persist
 *
 * The checkpoint is advanced only after a page has been fetched, parsed and
 * merged into the accumulated result set, and only ever as a *prefix*: a
 * contiguous run of pages from the resume point. An interruption therefore
 * either reprocesses (idempotent, at-least-once) or resumes safely. It can
 * never skip an unprocessed page, because the checkpoint never runs ahead of
 * confirmed processing.
 *
 * ## Source isolation
 *
 * State is keyed by source id (`eventbrite_cork`, `eventbrite_dublin`, ...), so
 * a Cork interruption cannot leak progress into Dublin and a failed source
 * never inherits another source's position. The map is bounded by
 * self::MAX_SOURCES, exactly like the cooldown registry.
 *
 * ## Invalidation
 *
 * A checkpoint is only valid for the source definition that produced it. The
 * record carries a `fingerprint` derived from the material source config (URL,
 * county, type). Changing the URL, the county or the source type changes the
 * fingerprint, and a mismatched checkpoint is DISCARDED and the enumeration
 * restarts safely at page 1 — never silently reused. A checkpoint that
 * references a page beyond the current walk's declared page count is likewise
 * discarded, because resuming past the end of a smaller listing would walk off
 * the end of the source.
 *
 * ## Scope
 *
 * Progress state only — one small record per source. No response bodies, no
 * HTML, no per-request data. The checkpoint answers "where were we", never
 * "what did we see".
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persisted enumeration progress for paginated sources.
 *
 * See the file docblock above for why this exists, what it deliberately does
 * not claim, and the ordering contract its writers must honour.
 *
 * @package Conexao_Event_Importer
 */
class Conexao_Source_Enumeration_Checkpoint {

	/**
	 * Option key holding the per-source checkpoint map.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'conexao_event_enumeration_checkpoints';

	/**
	 * Maximum number of sources tracked.
	 *
	 * Bounds the option row. The Eventbrite registry is finite, so this is a
	 * safety ceiling, not a routine limit.
	 *
	 * @var int
	 */
	const MAX_SOURCES = 200;

	/**
	 * Version of the record shape.
	 *
	 * A record written under an older or incompatible shape is discarded
	 * rather than reinterpreted, so a schema change can never resume from
	 * state whose meaning it no longer guarantees.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * The record shape with defaults applied.
	 *
	 * @return array
	 */
	protected static function defaults() {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'source_id'      => '',
			'fingerprint'    => '',
			'last_page'      => 0,
			'page_count'     => 0,
			'complete'       => false,
			'last_failure'   => '',
			'updated_at'     => '',
			'runs'           => 0,
		);
	}

	/**
	 * Get the full checkpoint map.
	 *
	 * @return array source_id => record.
	 */
	public static function get_all() {
		$map = get_option( self::OPTION_KEY, array() );

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Read the stored checkpoint for one source.
	 *
	 * @param string $source_id Source slug.
	 * @return array Record with defaults applied.
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
	 * Derive the material identity of a source definition.
	 *
	 * This is the smallest mechanism that actually prevents a stale checkpoint
	 * from carrying across a materially different listing: only the fields that
	 * change WHICH events the listing yields are included. Cosmetic metadata
	 * (display name, status, ordering in the admin list) is deliberately
	 * excluded, so renaming a source does not throw away useful progress.
	 *
	 * @param mixed $config Source config.
	 * @return string Short fingerprint.
	 */
	public static function fingerprint( $config ) {
		$url    = isset( $config['url'] ) ? (string) $config['url'] : '';
		$county = isset( $config['county'] ) ? (string) $config['county'] : '';
		$type   = isset( $config['type'] ) ? (string) $config['type'] : 'eventbrite';

		return substr( md5( $type . '|' . $url . '|' . $county ), 0, 32 );
	}

	/**
	 * Whether a stored checkpoint is usable for this source right now.
	 *
	 * Fails closed. Every one of these cases discards the checkpoint and the
	 * enumeration restarts at page 1:
	 *
	 *   - no checkpoint at all (first run — there is nothing to resume);
	 *   - a checkpoint written under a different source definition;
	 *   - a checkpoint from an incompatible record schema;
	 *   - a checkpoint claiming a position beyond the listing's own declared
	 *     page count, which would walk off the end of the source.
	 *
	 * @param mixed  $record      Stored record (as returned by self::get()).
	 * @param string $fingerprint Fingerprint of the current source definition.
	 * @param int    $page_count  Page count declared by THIS run's page 1.
	 * @return bool True when the record may be used as a resume position.
	 */
	public static function is_usable( $record, $fingerprint, $page_count = 0 ) {
		// Fail closed on anything that is not a well-formed record. This runs
		// against persisted state, so a corrupted or hand-edited option must
		// degrade to "no usable checkpoint", never to a bad resume position.
		if ( ! is_array( $record ) ) {
			return false;
		}

		$schema = isset( $record['schema_version'] ) ? (int) $record['schema_version'] : 0;

		if ( self::SCHEMA_VERSION !== $schema ) {
			return false;
		}

		$stored = isset( $record['fingerprint'] ) ? (string) $record['fingerprint'] : '';

		if ( '' === $stored || $stored !== (string) $fingerprint ) {
			return false;
		}

		$last_page = isset( $record['last_page'] ) ? (int) $record['last_page'] : 0;

		if ( $last_page < 1 ) {
			return false;
		}

		$declared = (int) $page_count;

		if ( $declared > 0 && $last_page > $declared ) {
			return false;
		}

		return true;
	}

	/**
	 * Persist a confirmed enumeration position for one source.
	 *
	 * Callers MUST have fetched, parsed and processed the page first. This is
	 * the write half of the ordering contract; see the class docblock.
	 *
	 * `last_page` never moves backwards, so a short or failed run cannot erase
	 * progress a previous run already confirmed. It only ever advances.
	 *
	 * @param string $source_id Source slug.
	 * @param array  $fields    Record fields to store.
	 * @return array The stored record.
	 */
	public static function record( $source_id, array $fields ) {
		$all      = self::get_all();
		$existing = self::get( $source_id );

		$last_page = isset( $fields['last_page'] ) ? (int) $fields['last_page'] : (int) $existing['last_page'];

		if ( $last_page < (int) $existing['last_page'] ) {
			$last_page = (int) $existing['last_page'];
		}

		$record = array(
			'schema_version' => self::SCHEMA_VERSION,
			'source_id'      => sanitize_key( (string) $source_id ),
			'fingerprint'    => isset( $fields['fingerprint'] ) ? sanitize_text_field( (string) $fields['fingerprint'] ) : (string) $existing['fingerprint'],
			'last_page'      => $last_page,
			'page_count'     => isset( $fields['page_count'] ) ? (int) $fields['page_count'] : (int) $existing['page_count'],
			// Progress state NEVER asserts completeness. `complete` here means
			// "the last run reached the end of this enumeration generation", not
			// "the upstream is fully enumerated" — the upstream truncation guard
			// still owns that claim in the importer.
			'complete'       => ! empty( $fields['complete'] ),
			'last_failure'   => isset( $fields['last_failure'] ) ? sanitize_key( (string) $fields['last_failure'] ) : (string) $existing['last_failure'],
			'updated_at'     => current_time( 'mysql' ),
			'runs'           => isset( $fields['runs'] ) ? (int) $fields['runs'] : (int) $existing['runs'] + 1,
		);

		$all[ $source_id ] = $record;

		if ( count( $all ) > self::MAX_SOURCES ) {
			$all = self::prune( $all, self::MAX_SOURCES );
		}

		update_option( self::OPTION_KEY, $all, false );

		return $record;
	}
	/**
	 * Discard a source's checkpoint.
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
	 * A compact, bounded operator-facing summary of one source's progress.
	 *
	 * This is the shape Import History stores so an operator can distinguish
	 * "partial, checkpoint at page 31, rate limited, resumable" from
	 * "complete", without a second dashboard.
	 *
	 * @param string $source_id Source slug.
	 * @return array{has_checkpoint:bool,last_page:int,page_count:int,complete:bool,last_failure:string,updated_at:string}
	 */
	public static function get_state( $source_id ) {
		$record = self::get( $source_id );

		return array(
			'has_checkpoint' => (int) $record['last_page'] > 0,
			'last_page'      => (int) $record['last_page'],
			'page_count'     => (int) $record['page_count'],
			'complete'       => (bool) $record['complete'],
			'last_failure'   => (string) $record['last_failure'],
			'updated_at'     => (string) $record['updated_at'],
		);
	}

	/**
	 * Drop the oldest entries once the map exceeds its ceiling.
	 *
	 * Unlike the cooldown registry this prunes by least-recently-updated: an
	 * entry is only stale relative to the others, and the newest progress is
	 * the only thing worth keeping.
	 *
	 * @param array $all   Full map.
	 * @param int   $limit Maximum entries to keep.
	 * @return array Pruned map.
	 */
	protected static function prune( array $all, $limit ) {
		uasort(
			$all,
			static function ( $a, $b ) {
				$left  = isset( $a['updated_at'] ) ? (string) $a['updated_at'] : '';
				$right = isset( $b['updated_at'] ) ? (string) $b['updated_at'] : '';
				return strcmp( $right, $left );
			}
		);

		return array_slice( $all, 0, max( 1, (int) $limit ), true );
	}
}
