<?php
/**
 * Per-field enrichment state for a normalized event payload (Stage 2 / P5).
 *
 * ## Why this exists
 *
 * A handler that enriches an event from a DETAIL page can end up with a
 * listing-only event when that detail request fails. Before this model the
 * engine could not tell the difference between
 *
 *   - "the source was retrieved and this field is legitimately empty", and
 *   - "the detail request failed, so this field's value is unknown",
 *
 * so it wrote the incoming (empty) value over good stored data — erasing a
 * description, venue or address that a previous successful run had imported.
 *
 * The state is carried on the normalized payload (`enrichment` key) and is
 * ADDITIVE: a handler that does not declare it (Eventbrite, iCalendar, the
 * listing-only sources) produces exactly the payload it always produced, and
 * the write path keeps writing incoming values for it.
 *
 * ## States
 *
 * | State | Meaning | Write path |
 * |---|---|---|
 * | `populated` | Source retrieved, field has a value | write the incoming value |
 * | `empty` | Source retrieved; its documented contract declares the field legitimately empty | write the empty value (a genuine clear) |
 * | `unavailable` | Retrieval failed, page/key/element unavailable — value unknown | PRESERVE the stored value |
 * | `not_evaluated` | Enrichment not attempted for this field | PRESERVE the stored value |
 *
 * `unavailable` and `not_evaluated` are the only states that preserve. A
 * field that is absent from the map is treated as "legacy / unspecified" and
 * written exactly as before, which is what keeps every non-enriching handler
 * byte-for-byte unchanged.
 *
 * State lives in the in-memory payload only. No post meta key is added to
 * persist it, so no existing reader of event meta changes.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-field enrichment state for one normalized event payload (Stage 2 / P5).
 */
class Conexao_Event_Enrichment_State {

	/** Source retrieved the field with a value. */
	const POPULATED = 'populated';

	/** Source retrieved the field and its contract declares it legitimately empty. */
	const EMPTY_VALUE = 'empty';

	/** Retrieval failed or the value is unknown; preserve what is stored. */
	const UNAVAILABLE = 'unavailable';

	/** Enrichment was not attempted; preserve what is stored. */
	const NOT_EVALUATED = 'not_evaluated';

	/**
	 * Canonical normalized fields a detail-fetch enrichment can own.
	 *
	 * Deliberately limited to fields the write path persists and that a
	 * detail page can supply. Taxonomy (`category`) is excluded: an empty
	 * category is already a no-op for term assignment, so it cannot erase.
	 *
	 * @return string[]
	 */
	public static function fields() {
		return array( 'description', 'venue', 'address', 'location', 'organizer', 'banner' );
	}

	/**
	 * All supported states.
	 *
	 * @return string[]
	 */
	public static function states() {
		return array( self::POPULATED, self::EMPTY_VALUE, self::UNAVAILABLE, self::NOT_EVALUATED );
	}

	/**
	 * Normalize one state value.
	 *
	 * @param mixed $state Candidate state.
	 * @return string Valid state, or '' when unknown.
	 */
	public static function sanitize( $state ) {
		$state = is_string( $state ) ? strtolower( trim( $state ) ) : '';
		return in_array( $state, self::states(), true ) ? $state : '';
	}

	/**
	 * Sanitize a handler-declared field => state map.
	 *
	 * Unknown fields and unknown states are dropped, so a handler typo can
	 * never silently suppress a write: an absent entry means "legacy write".
	 *
	 * @param mixed $map Raw map.
	 * @return array<string,string>
	 */
	public static function sanitize_map( $map ) {
		if ( ! is_array( $map ) ) {
			return array();
		}

		$clean = array();

		foreach ( self::fields() as $field ) {
			if ( ! array_key_exists( $field, $map ) ) {
				continue;
			}

			$state = self::sanitize( $map[ $field ] );

			if ( '' !== $state ) {
				$clean[ $field ] = $state;
			}
		}

		return $clean;
	}

	/**
	 * A complete map that marks every field unavailable.
	 *
	 * Used when a detail fetch failed: nothing about the detail-owned fields
	 * is known, so every stored value must be preserved.
	 *
	 * @return array<string,string>
	 */
	public static function all_unavailable() {
		return array_fill_keys( self::fields(), self::UNAVAILABLE );
	}

	/**
	 * A complete map that marks every field not evaluated.
	 *
	 * Used when enrichment was never attempted (no detail URL).
	 *
	 * @return array<string,string>
	 */
	public static function all_not_evaluated() {
		return array_fill_keys( self::fields(), self::NOT_EVALUATED );
	}

	/**
	 * Whether the stored value must be preserved for a field.
	 *
	 * @param mixed  $map   Sanitized field => state map (any value is tolerated).
	 * @param string $field Normalized field.
	 * @return bool True when the incoming value must not overwrite storage.
	 */
	public static function should_preserve( $map, $field ) {
		if ( ! is_array( $map ) || ! isset( $map[ $field ] ) ) {
			// Legacy / unspecified: no enrichment contract, write as before.
			return false;
		}

		return in_array( $map[ $field ], array( self::UNAVAILABLE, self::NOT_EVALUATED ), true );
	}
}
