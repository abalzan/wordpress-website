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

		// County detection.
		if ( preg_match( '/\bco\.?\s*laois\b/i', $raw ) || preg_match( '/\blaois\b/i', $raw ) ) {
			$result['county'] = 'Laois';
		}

		// Town extraction.
		foreach ( $this->known_towns as $town ) {
			if ( preg_match( '/\b' . preg_quote( $town, '/' ) . '\b/i', $raw ) ) {
				$result['town'] = $this->proper_case( $town );
				break;
			}
		}

		// Venue: strip county/town markers to isolate the venue name.
		$venue = $raw;
		$venue = preg_replace( '/\bco\.?\s*laois\b/i', '', $venue );
		$venue = preg_replace( '/\blaois\b/i', '', $venue );
		$venue = preg_replace( '/\b' . preg_quote( ( $result['town'] ? strtolower( $result['town'] ) : '' ), '/' ) . '\b/i', '', $venue );
		$venue = trim( preg_replace( '/\s*,\s*|\s+/', ' ', $venue ) );
		$venue = trim( $venue, ' ,-' );

		if ( $venue ) {
			$result['venue'] = $venue;
		}

		// Address: if the raw string contains a street-like pattern, keep it.
		if ( preg_match( '/\b\d{1,4}\s+[A-Za-z]/', $raw ) && empty( $result['address'] ) ) {
			$result['address'] = $raw;
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
	 * Ensure a town term exists in the conexao_town taxonomy.
	 *
	 * @param string $town_name Town name.
	 * @return int Term ID (0 on failure).
	 */
	public function ensure_town( $town_name ) {
		if ( empty( $town_name ) ) {
			return 0;
		}

		$term = term_exists( $town_name, 'conexao_town' );
		if ( $term ) {
			return (int) $term['term_id'];
		}

		$new = wp_insert_term( $town_name, 'conexao_town' );
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