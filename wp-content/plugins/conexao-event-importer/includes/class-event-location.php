<?php
/**
 * Event location normalization and helpers.
 *
 * Normalizes source location strings into:
 *   County → Town/City → Venue → Address
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Location {

	/**
	 * Known Laois towns/cities used for normalization.
	 *
	 * @var array
	 */
	protected $known_towns = array(
		'abbeyleix',
		'ballacolla',
		'ballaghmore',
		'ballickmoyler',
		'ballinakill',
		'ballybrittas',
		'ballyfin',
		'ballylinan',
		'ballyroan',
		'borris-in-ossory',
		'borrisinossory',
		'camps',
		'castletown',
		'clonaslee',
		'coolrain',
		'cullohill',
		'donaghmore',
		'durrow',
		'em',
		'errihill',
		'jamestown',
		'killenard',
		'killeshin',
		'mountmellick',
		'mountrath',
		'new inn',
		'newtown',
		'portarlington',
		'portlaoise',
		'rathdowney',
		'rosenallis',
		'shannon',
		'stradbally',
		'timahoe',
		'vicarstown',
	);

	/**
	 * Known Irish counties for nationwide location derivation.
	 *
	 * Generalized from the original Laois-only behavior so nationwide
	 * sources (e.g. IVVCC) resolve county terms without a second engine.
	 *
	 * @var array Map of lowercase county key => canonical county name.
	 */
	protected $known_counties = array(
		'antrim'     => 'Antrim',
		'armagh'     => 'Armagh',
		'carlow'     => 'Carlow',
		'cavan'      => 'Cavan',
		'clare'      => 'Clare',
		'cork'       => 'Cork',
		'derry'      => 'Derry',
		'donegal'    => 'Donegal',
		'down'       => 'Down',
		'dublin'     => 'Dublin',
		'fermanagh'  => 'Fermanagh',
		'galway'     => 'Galway',
		'kerry'      => 'Kerry',
		'kildare'    => 'Kildare',
		'kilkenny'   => 'Kilkenny',
		'laois'      => 'Laois',
		'leitrim'    => 'Leitrim',
		'limerick'   => 'Limerick',
		'longford'   => 'Longford',
		'louth'      => 'Louth',
		'mayo'       => 'Mayo',
		'meath'      => 'Meath',
		'monaghan'   => 'Monaghan',
		'offaly'     => 'Offaly',
		'roscommon'  => 'Roscommon',
		'sligo'      => 'Sligo',
		'tipperary'  => 'Tipperary',
		'tyrone'     => 'Tyrone',
		'waterford'  => 'Waterford',
		'westmeath'  => 'Westmeath',
		'wexford'    => 'Wexford',
		'wicklow'    => 'Wicklow',
	);

	/**
	 * Nationwide town index (lowercase key => array( display, county )).
	 *
	 * Covers IVVCC-relevant towns plus county towns so venue strings like
	 * "Park Hotel, Dungarvan, Co Waterford" resolve without guessing.
	 *
	 * @var array
	 */
	protected $national_towns = array(
		'abbeyleix'    => array( 'Abbeyleix', 'Laois' ),
		'ballyvourney' => array( 'Ballyvourney', 'Cork' ),
		'blessington'  => array( 'Blessington', 'Wicklow' ),
		'carlow'       => array( 'Carlow', 'Carlow' ),
		'clonskeagh'   => array( 'Clonskeagh', 'Dublin' ),
		'cobh'         => array( 'Cobh', 'Cork' ),
		'cork'         => array( 'Cork', 'Cork' ),
		'dublin'       => array( 'Dublin', 'Dublin' ),
		'dungarvan'    => array( 'Dungarvan', 'Waterford' ),
		'kenmare'      => array( 'Kenmare', 'Kerry' ),
		'kilkenny'     => array( 'Kilkenny', 'Kilkenny' ),
		'portlaoise'   => array( 'Portlaoise', 'Laois' ),
		'russborough'  => array( 'Blessington', 'Wicklow' ),
		'sligo'        => array( 'Sligo', 'Sligo' ),
		'waterford'    => array( 'Waterford', 'Waterford' ),
		'wexford'      => array( 'Wexford', 'Wexford' ),
	);

	/**
	 * Normalize a raw location string into structured parts.
	 *
	 * @param string $raw_location Raw location string from the source.
	 * @return array{county:string, town:string, venue:string, address:string}
	 */
	public function normalize( $raw_location ) {
		$result = array(
			'county'  => '',
			'town'    => '',
			'venue'   => '',
			'address' => '',
		);

		if ( empty( $raw_location ) ) {
			return $result;
		}

		$raw = trim( preg_replace( '/\s+/', ' ', (string) $raw_location ) );

		// County detection: nationwide ("Co X" or bare county name).
		foreach ( $this->known_counties as $key => $name ) {
			if ( preg_match( '/\bco\.?\s*' . preg_quote( $key, '/' ) . '\b/i', $raw ) || preg_match( '/\b' . preg_quote( $key, '/' ) . '\b/i', $raw ) ) {
				$result['county'] = $name;
				break;
			}
		}

		// Town extraction: Laois list first (legacy behavior preserved),
		// then the nationwide index (implies its county when county is empty).
		foreach ( $this->known_towns as $town ) {
			if ( preg_match( '/\b' . preg_quote( $town, '/' ) . '\b/i', $raw ) ) {
				$result['town'] = $this->proper_case( $town );
				break;
			}
		}
		if ( '' === $result['town'] ) {
			foreach ( $this->national_towns as $key => $pair ) {
				if ( preg_match( '/\b' . preg_quote( $key, '/' ) . '\b/i', $raw ) ) {
					$result['town'] = $pair[0];
					if ( '' === $result['county'] ) {
						$result['county'] = $pair[1];
					}
					break;
				}
			}
		}

		// Venue: strip county/town markers to isolate the venue name.
		// A "Co X" marker is always removed. A bare county name is removed
		// only as a trailing comma-separated component ("Dungarvan,
		// Waterford" -> "Dungarvan") — a standalone county/town string
		// ("Dublin", "Sligo Town") stays a venue label, matching the
		// no-guess venue behavior of the original Laois-only logic.
		$venue = $raw;
		foreach ( $this->known_counties as $key => $name ) {
			$venue = preg_replace( '/\bco\.?\s*' . preg_quote( $key, '/' ) . '\b/i', '', $venue );
		}
		if ( '' !== $result['county'] ) {
			$county_key = array_search( $result['county'], $this->known_counties, true );
			$venue      = preg_replace( '/,\s*' . preg_quote( (string) $county_key, '/' ) . '\s*$/i', '', $venue );
		}
		$venue = preg_replace( '/\b' . preg_quote( ( $result['town'] ? strtolower( $result['town'] ) : '' ), '/' ) . '\b/i', '', $venue );
		$venue = trim( preg_replace( '/\s*,\s*|\s+/', ' ', $venue ) );
		$venue = trim( $venue, ' ,-' );

		// Guard for standalone place names ("Dublin", "Kenmare"): when the
		// town/county stripping consumed the whole string, keep the raw
		// value (minus any "Co X" marker) as the venue label instead of
		// storing an empty venue. Bare county-marker strings ("Co Laois")
		// still yield no venue, matching legacy behavior.
		if ( '' === $venue ) {
			$fallback = $raw;
			foreach ( $this->known_counties as $key => $name ) {
				$fallback = preg_replace( '/\bco\.?\s*' . preg_quote( $key, '/' ) . '\b/i', '', $fallback );
			}
			$fallback = trim( preg_replace( '/\s*,\s*|\s+/', ' ', $fallback ) );
			$fallback = trim( $fallback, ' ,-' );
			$venue    = $fallback;
		}

		if ( $venue ) {
			$result['venue'] = $venue;
		}

		// Address: keep the raw string when it looks like a real address.
		// Deterministic signals only (never guessed): a street-like pattern
		// (number + word) or an Irish Eircode. Bare venue/town names are
		// left in the venue field with the address left empty.
		if ( empty( $result['address'] )
			&& ( preg_match( '/\b\d{1,4}\s+[A-Za-z]/', $raw ) || preg_match( Conexao_Event_Address::EIRCODE_REGEX, $raw ) )
		) {
			$result['address'] = Conexao_Event_Address::normalize( $raw );
		}

		return $result;
	}

	/**
	 * Proper-case a town name (handles hyphens).
	 *
	 * @param string $town Lowercase town slug.
	 * @return string
	 */
	protected function proper_case( $town ) {
		$parts = explode( '-', $town );
		$parts = array_map( 'ucwords', $parts );
		return implode( '-', $parts );
	}

	/**
	 * Get all towns currently in use (from the conexao_town taxonomy).
	 *
	 * @return array List of town names (alphabetical).
	 */
	public function get_towns() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'conexao_town',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return wp_list_pluck( $terms, 'name' );
	}

	/**
	 * Get the slug for a town name.
	 *
	 * @param string $town_name Town name (e.g. "Portlaoise").
	 * @return string
	 */
	public function town_slug( $town_name ) {
		return sanitize_title( $town_name );
	}

	/**
	 * Sanitize a town value by stripping Eircode fragments.
	 *
	 * Eventbrite and other sources may supply a locality that concatenates
	 * the town name with an Irish Eircode (e.g. "Ballinamore N41 E8H0",
	 * "Oranmore H91 72H3"). The city/town filter must represent a locality
	 * only, never a postal code. This method:
	 *   - removes any Irish Eircode (routing key + unique id)
	 *   - collapses leftover whitespace/separators
	 *   - returns '' when the value was a standalone Eircode
	 *
	 * Values are otherwise preserved as-is: no fuzzy matching, no
	 * geographic inference. A value consisting only of an Eircode yields
	 * '' so the caller can leave the town classification empty rather
	 * than fabricating a locality.
	 *
	 * @param string $town Raw town value.
	 * @return string Cleaned town name, or '' when no valid locality remains.
	 */
	public function sanitize_town( $town ) {
		$town = trim( (string) $town );
		if ( '' === $town ) {
			return '';
		}

		// Strip Irish Eircode patterns (e.g. "N41 E8H0", "N41E8H0",
		// "H91 72H3"). Case-insensitive; the Eircode regex is defined in
		// Conexao_Event_Address and matches routing key + unique id.
		$cleaned = preg_replace( Conexao_Event_Address::EIRCODE_REGEX, '', $town );

		// Collapse whitespace and separators left after Eircode removal.
		$cleaned = preg_replace( '/\s*,\s*|\s+/', ' ', $cleaned );
		$cleaned = trim( $cleaned, ' ,-.' );

		// A valid town name must contain at least one letter. A value
		// that was a standalone Eircode (e.g. "A92 DF7X." → ".") or
		// that collapses to punctuation/separators only is not a
		// locality. Do not fabricate a town.
		if ( '' === $cleaned || ! preg_match( '/[A-Za-z]/', $cleaned ) ) {
			return '';
		}

		return $cleaned;
	}

	/**
	 * Ensure a town term exists in the conexao_town taxonomy.
	 *
	 * The supplied town name is sanitized first so Eircode fragments are
	 * never stored as town terms. A standalone Eircode (which sanitizes
	 * to '') is rejected and returns 0.
	 *
	 * @param string $town_name Town name.
	 * @return int Term ID (0 on failure).
	 */
	public function ensure_town( $town_name ) {
		$sanitized = $this->sanitize_town( $town_name );
		if ( '' === $sanitized ) {
			return 0;
		}

		$term = term_exists( $sanitized, 'conexao_town' );
		if ( $term ) {
			return (int) $term['term_id'];
		}

		$new = wp_insert_term( $sanitized, 'conexao_town' );
		if ( is_wp_error( $new ) ) {
			return 0;
		}

		return (int) $new['term_id'];
	}

	/**
	 * Ensure a county term exists in the conexao_county taxonomy.
	 *
	 * @param string $county_name County name.
	 * @return int Term ID (0 on failure).
	 */
	public function ensure_county( $county_name ) {
		if ( empty( $county_name ) ) {
			return 0;
		}

		$term = term_exists( $county_name, 'conexao_county' );
		if ( $term ) {
			return (int) $term['term_id'];
		}

		$new = wp_insert_term( $county_name, 'conexao_county' );
		if ( is_wp_error( $new ) ) {
			return 0;
		}

		return (int) $new['term_id'];
	}
}