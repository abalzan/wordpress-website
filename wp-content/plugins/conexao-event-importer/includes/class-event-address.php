<?php
/**
 * Address normalization, validation and map-URL helpers for imported events.
 *
 * Responsibilities:
 *  - Normalize source-supplied address strings (whitespace, line breaks,
 *    HTML entities, duplicate separators) into a human-readable Irish address.
 *  - Validate address candidates so that bare place/venue names ("Dublin",
 *    "Croke Park") are never stored as addresses. The importer never guesses:
 *    an address is stored only when the source explicitly supplied one.
 *  - Compose structured addresses (JSON-LD PostalAddress, Eventbrite
 *    venue.address fields) from their supplied parts.
 *  - Build a deterministic Google Maps search URL (no API key, no geocoding,
 *    no remote requests) using the same scheme as the theme's leisure map
 *    helper.
 *
 * This class is static-only and side-effect free so it is unit-testable.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Address {

	/**
	 * Irish Eircode pattern (routing key + unique id), e.g. D03 P0K7, R32 XW63.
	 *
	 * @var string
	 */
	const EIRCODE_REGEX = '/\b[A-Z]\d{2}\s?[A-Z0-9]{4}\b/i';

	/**
	 * Maximum plausible address length in characters.
	 *
	 * @var int
	 */
	const MAX_LENGTH = 300;

	/**
	 * Normalize a raw address string.
	 *
	 * Preserves the supplied content (no translation, no removal of
	 * apartment/unit information, no alteration of postal codes) while:
	 *  - decoding HTML entities,
	 *  - converting <br> and block-element boundaries into ", " separators,
	 *  - stripping any remaining markup,
	 *  - collapsing whitespace and line breaks,
	 *  - collapsing duplicate separators,
	 *  - trimming leading/trailing whitespace and stray separators.
	 *
	 * @param string $value Raw address value.
	 * @return string Normalized address (possibly empty).
	 */
	public static function normalize( $value ) {
		$value = (string) $value;

		if ( '' === trim( $value ) ) {
			return '';
		}

		// Decode HTML entities before working with the text.
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Non-breaking spaces are whitespace, not content.
		$value = str_replace( "\xC2\xA0", ' ', $value );

		// Line breaks and block boundaries become separators so a
		// multi-line address stays readable as a single meta string.
		$value = preg_replace( '/<br\s*\/?>/i', ', ', $value );
		$value = preg_replace( '/<\/(?:p|div|li|address)>/i', ', ', $value );

		// Strip any remaining markup (security: the address is plain data).
		$value = wp_strip_all_tags( $value );

		// Collapse all whitespace, including newlines and tabs.
		$value = preg_replace( '/\s+/u', ' ', $value );

		// Collapse obviously duplicate separators.
		$value = preg_replace( '/(?:\s*,\s*){2,}/', ', ', $value );
		$value = preg_replace( '/\s*;\s*(?:\s*;\s*)+/', '; ', $value );

		// Trim whitespace and stray leading/trailing separators.
		$value = trim( $value, " \t\n\r\0\x0B,;" );

		// Final sanitization for storage.
		return sanitize_text_field( $value );
	}

	/**
	 * Whether a normalized string is plausible as a physical address.
	 *
	 * Rejects obviously non-address values (URLs, emails, markup remnants,
	 * values without letters) and enforces the no-guess rule: a single-part
	 * string with no street number, no Eircode and no county marker is a
	 * place/venue name ("Dublin", "Croke Park"), not an address.
	 *
	 * @param string $value Normalized candidate.
	 * @return bool
	 */
	public static function is_plausible( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return false;
		}

		if ( mb_strlen( $value ) < 4 || mb_strlen( $value ) > self::MAX_LENGTH ) {
			return false;
		}

		// Must contain at least one letter.
		if ( ! preg_match( '/\p{L}/u', $value ) ) {
			return false;
		}

		// Reject URLs, scripts, emails and markup/code remnants.
		if ( preg_match( '/https?:\/\/|javascript:|www\.|<|>|\[|\]|@|\(|\)/i', $value ) ) {
			return false;
		}

		// No-guess rule: multi-part strings, street numbers, Eircodes or
		// county markers indicate a real address; anything else is treated
		// as a venue/place label and must not be stored as an address.
		$has_eircode       = (bool) preg_match( self::EIRCODE_REGEX, $value );
		$has_number        = (bool) preg_match( '/\d/', $value );
		$has_county_marker = (bool) preg_match( '/\bco\.?\s/i', $value );
		$parts             = array_filter( array_map( 'trim', explode( ',', $value ) ) );

		if ( count( $parts ) < 2 && ! $has_eircode && ! $has_number && ! $has_county_marker ) {
			return false;
		}

		return true;
	}

	/**
	 * Compose an address from structured parts (already supplied by the
	 * source), e.g. JSON-LD PostalAddress or Eventbrite venue.address fields.
	 *
	 * Only supplied parts are used, in the given order. Consecutive duplicate
	 * parts (e.g. locality == region) collapse to one. This is composition of
	 * source data, not guessing: parts the source did not supply are skipped.
	 *
	 * @param array $parts Ordered address parts (strings, may be empty).
	 * @return string Composed address (possibly empty).
	 */
	public static function compose( $parts ) {
		$clean = array();

		foreach ( (array) $parts as $part ) {
			$part = self::normalize( $part );
			if ( '' === $part ) {
				continue;
			}
			// Collapse consecutive duplicates.
			if ( ! empty( $clean ) && 0 === strcasecmp( end( $clean ), $part ) ) {
				continue;
			}
			$clean[] = $part;
		}

		return implode( ', ', $clean );
	}

	/**
	 * Decide the address to store for an event.
	 *
	 * Import priority (never guessed):
	 *   1. incoming (source-supplied, normalized) address,
	 *   2. the existing stored address when the source supplies none —
	 *      this preserves manually corrected addresses and earlier trusted
	 *      data instead of wiping them,
	 *   3. empty.
	 *
	 * @param string $incoming Normalized incoming address ('' when absent).
	 * @param string $existing Currently stored address.
	 * @return string The address that should be stored.
	 */
	public static function resolve_stored( $incoming, $existing ) {
		$incoming = trim( (string) $incoming );
		$existing = trim( (string) $existing );

		if ( '' !== $incoming ) {
			return $incoming;
		}

		return $existing;
	}

	/**
	 * Build the map query for an event from the strongest available
	 * location data.
	 *
	 * Priority:
	 *   1. full address,
	 *   2. venue + existing location label,
	 *   3. existing location label,
	 *   4. '' (no query — no map URL is stored).
	 *
	 * @param string $address        Normalized address.
	 * @param string $venue          Venue name.
	 * @param string $event_location Existing location label (_event_location).
	 * @return string Map query string ('' when nothing reliable exists).
	 */
	public static function map_query( $address, $venue, $event_location ) {
		$address        = trim( (string) $address );
		$venue          = trim( (string) $venue );
		$event_location = trim( (string) $event_location );

		if ( '' !== $address ) {
			return $address;
		}

		if ( '' !== $venue ) {
			return '' !== $event_location ? $venue . ', ' . $event_location : $venue;
		}

		return $event_location;
	}

	/**
	 * Build a deterministic Google Maps search URL for a query.
	 *
	 * A map search URL only — no Google Maps APIs, no geocoding, no API key,
	 * no embeds, no remote requests. Same scheme as the theme's leisure map
	 * helper (`conexao_leisure_map_url()`).
	 *
	 * @param string $query Map query (non-empty).
	 * @return string URL, or '' when the query is empty.
	 */
	public static function map_url( $query ) {
		$query = trim( (string) $query );

		if ( '' === $query ) {
			return '';
		}

		return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query );
	}
}