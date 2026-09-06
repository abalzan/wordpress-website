<?php
/**
 * JSON-LD (schema.org/Event) location extractor.
 *
 * Extracts the event venue and address from schema.org/Event structured data
 * embedded in a source page (`<script type="application/ld+json">`).
 *
 * Disambiguation rule: ONLY `Event.location` is read. Organizer, contact and
 * ticket-office addresses (typically `Organization.address` or unrelated
 * `PostalAddress` objects on the same page) are structurally rejected because
 * they are not part of `Event.location`.
 *
 * No-guess rule: text locations that are not plausible addresses (bare venue
 * or town names such as "Dublin" or "Croke Park") are never returned as an
 * address. A `Place` without a street/postal component yields the venue name
 * only.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Jsonld_Location {

	/**
	 * Extract the event venue and address from JSON-LD Event data.
	 *
	 * @param string $html Raw page HTML.
	 * @return array{venue:string, address:string} Extracted values ('' when absent).
	 */
	public function extract( $html ) {
		$result = array(
			'venue'   => '',
			'address' => '',
		);

		if ( ! is_string( $html ) || '' === trim( $html ) ) {
			return $result;
		}

		if ( ! preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches ) ) {
			return $result;
		}

		foreach ( $matches[1] as $json ) {
			$data = json_decode( trim( (string) $json ), true );

			if ( ! is_array( $data ) ) {
				// Malformed JSON-LD block — skip it, never guess from it.
				continue;
			}

			foreach ( $this->iter_objects( $data ) as $object ) {
				if ( ! $this->is_event( $object ) ) {
					continue;
				}

				$found = $this->resolve_location( isset( $object['location'] ) ? $object['location'] : null );

				if ( '' !== $found['venue'] && '' !== $found['address'] ) {
					return $found;
				}

				if ( '' !== $found['venue'] && '' === $result['venue'] ) {
					$result['venue'] = $found['venue'];
				}
				if ( '' !== $found['address'] && '' === $result['address'] ) {
					$result['address'] = $found['address'];
				}
			}

			if ( '' !== $result['venue'] && '' !== $result['address'] ) {
				return $result;
			}
		}

		return $result;
	}

	/**
	 * Iterate over every associative object in a decoded JSON-LD document,
	 * including nested structures and `@graph` arrays.
	 *
	 * @param array $data Decoded JSON-LD data.
	 * @return \Generator|array
	 */
	protected function iter_objects( $data ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		// List-style documents (e.g. an array of Event objects).
		if ( ! isset( $data['@type'] ) && ! isset( $data['@context'] ) && ! isset( $data['@graph'] ) ) {
			$is_list = true;
			foreach ( array_keys( $data ) as $key ) {
				if ( ! is_int( $key ) ) {
					$is_list = false;
					break;
				}
			}
			if ( $is_list ) {
				foreach ( $data as $item ) {
					if ( is_array( $item ) ) {
						yield from $this->iter_objects( $item );
					}
				}
				return;
			}
		}

		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $node ) {
				if ( is_array( $node ) ) {
					yield from $this->iter_objects( $node );
				}
			}
			return;
		}

		if ( isset( $data['@type'] ) ) {
			yield $data;

			// Still walk children for nested objects (e.g. @graph inside
			// another structure). Event.location itself is handled by
			// resolve_location, so nested Place objects don't leak in here.
			foreach ( $data as $value ) {
				if ( is_array( $value ) ) {
					yield from $this->iter_objects( $value );
				}
			}
		}
	}

	/**
	 * Whether an object is a schema.org/Event (or a subtype such as
	 * BusinessEvent, MusicEvent, Festival, ...).
	 *
	 * @param array $object JSON-LD object.
	 * @return bool
	 */
	protected function is_event( $object ) {
		$types = isset( $object['@type'] ) ? $object['@type'] : array();

		foreach ( (array) $types as $type ) {
			$type = strtolower( (string) $type );
			if ( 'event' === $type || preg_match( '/event$/', $type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve `Event.location` into venue + address.
	 *
	 * Supported forms:
	 *  - Text: plausible text → address.
	 *  - Place/Place-like object:
	 *      - `name` → venue.
	 *      - `address` as Text: plausible text → address.
	 *      - `address` as PostalAddress object: composed from the supplied
	 *        streetAddress/addressLocality/addressRegion/postalCode/
	 *        addressCountry parts — only when a street or postal component
	 *        exists (locality/region alone is not a street address).
	 *
	 * @param mixed $location Event.location value.
	 * @return array{venue:string, address:string}
	 */
	protected function resolve_location( $location ) {
		$result = array(
			'venue'   => '',
			'address' => '',
		);

		if ( is_string( $location ) ) {
			$normalized = Conexao_Event_Address::normalize( $location );
			if ( '' !== $normalized && Conexao_Event_Address::is_plausible( $normalized ) ) {
				$result['address'] = $normalized;
			}
			return $result;
		}

		if ( ! is_array( $location ) ) {
			// Malformed location (number, bool, ...) — reject, never guess.
			return $result;
		}

		// Multiple locations (array of Places): use the first resolvable one.
		if ( isset( $location[0] ) ) {
			foreach ( (array) $location as $item ) {
				$found = $this->resolve_location( $item );
				if ( '' !== $found['venue'] || '' !== $found['address'] ) {
					return $found;
				}
			}
			return $result;
		}

		// Place-like object.
		$venue = isset( $location['name'] ) ? Conexao_Event_Address::normalize( $location['name'] ) : '';
		if ( '' !== $venue && mb_strlen( $venue ) <= 120 && ! preg_match( '/https?:\/\/|javascript:|<|>|\[|\]|@/i', $venue ) ) {
			$result['venue'] = $venue;
		}

		$address = isset( $location['address'] ) ? $location['address'] : null;

		if ( is_string( $address ) ) {
			$normalized = Conexao_Event_Address::normalize( $address );
			if ( '' !== $normalized && Conexao_Event_Address::is_plausible( $normalized ) ) {
				$result['address'] = $normalized;
			}
		} elseif ( is_array( $address ) ) {
			$street   = isset( $address['streetAddress'] ) ? (string) $address['streetAddress'] : '';
			$postal   = isset( $address['postalCode'] ) ? (string) $address['postalCode'] : '';
			$locality = isset( $address['addressLocality'] ) ? (string) $address['addressLocality'] : '';
			$region   = isset( $address['addressRegion'] ) ? (string) $address['addressRegion'] : '';
			$country  = isset( $address['addressCountry'] ) ? (string) $address['addressCountry'] : '';

			// Require a street or postal component — a bare locality/region
			// pair ("Dublin, Leinster") is not stored as a street address.
			if ( '' !== trim( $street ) || '' !== trim( $postal ) ) {
				$composed = Conexao_Event_Address::compose(
					array( $street, $locality, $region, $postal, $country )
				);
				if ( '' !== $composed ) {
					$result['address'] = $composed;
				}
			}
		}

		return $result;
	}
}