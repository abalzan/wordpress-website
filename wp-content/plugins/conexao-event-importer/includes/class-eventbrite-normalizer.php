<?php
/**
 * Eventbrite event normalizer.
 *
 * Converts raw Eventbrite event data (from `search_data.events.results`)
 * into the application's raw event format expected by the main normalizer.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Eventbrite_Normalizer {

	/**
	 * Normalize a raw Eventbrite event into the application's raw event format.
	 *
	 * @param array $raw Raw Eventbrite event from search results.
	 * @return array Raw event in the format expected by Conexao_Event_Normalizer.
	 */
	public function normalize( $raw ) {
		$event = array(
			'source'      => 'eventbrite',
			'title'       => $this->get( $raw, 'name' ),
			'url'         => $this->get( $raw, 'url' ),
			'start_date'  => '',
			'start_time'  => '',
			'end_date'    => '',
			'end_time'    => '',
			'location'    => '',
			'description' => $this->get_description( $raw ),
			'image'       => $this->get_image_url( $raw ),
			'source_id'   => $this->get( $raw, 'id' ),
			'organizer'   => $this->get_organizer_name( $raw ),
			'price'       => $this->get_price( $raw ),
			'category'    => $this->get_category( $raw ),
			'county'      => 'Laois',
			'town'        => $this->get_location_part( $raw, 'locality' ),
			'venue'       => $this->get_venue_name( $raw ),
			'address'     => $this->get_address( $raw ),
			'is_online'   => ! empty( $raw['is_online_event'] ),
			'timezone'    => $this->get( $raw, 'timezone' ),
			'is_cancelled' => ! empty( $raw['is_cancelled'] ),
			'is_protected' => ! empty( $raw['is_protected_event'] ),
			'ticket_url'  => $this->get( $raw, 'tickets_url' ),
			'organizer_id' => $this->get( $raw, 'primary_organizer_id' ),
			'subcategory' => $this->get_subcategory( $raw ),
		);

		// Parse dates and times.
		$this->parse_dates( $raw, $event );

		// Build location string.
		$event['location'] = $this->build_location_string( $event );

		return $event;
	}

	/**
	 * Get a value from an array, handling missing keys.
	 *
	 * @param array  $data Array to read from.
	 * @param string $key  Key to read.
	 * @return string
	 */
	protected function get( $data, $key ) {
		return isset( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';
	}

	/**
	 * Get the event description, preferring full_description.
	 *
	 * @param array $raw Raw Eventbrite event.
	 * @return string
	 */
	protected function get_description( $raw ) {
		$desc = $this->get( $raw, 'full_description' );
		if ( empty( $desc ) ) {
			$desc = $this->get( $raw, 'summary' );
		}
		return $desc;
	}

	/**
	 * Get the best image URL from Eventbrite's image structure.
	 *
	 * @param array $raw Raw Eventbrite event.
	 * @return string
	 */
	protected function get_image_url( $raw ) {
		if ( empty( $raw['image'] ) || ! is_array( $raw['image'] ) ) {
			return '';
		}

		// Try image_sizes.medium first (good balance of size/quality).
		if ( ! empty( $raw['image']['image_sizes']['medium'] ) ) {
			return $raw['image']['image_sizes']['medium'];
		}

		// Fall back to image.url.
		if ( ! empty( $raw['image']['url'] ) ) {
			return $raw['image']['url'];
		}

		// Try other image_sizes.
		if ( ! empty( $raw['image']['image_sizes'] ) && is_array( $raw['image']['image_sizes'] ) ) {
			foreach ( array( 'large', 'original', 'small' ) as $size ) {
				if ( ! empty( $raw['image']['image_sizes'][ $size ] ) ) {
					return $raw['image']['image_sizes'][ $size ];
				}
			}
		}

		return '';
	}

	/**
	 * Get the organizer name from the raw event.
	 *
	 * @param array $raw Raw Eventbrite event.
	 * @return string
	 */
	protected function get_organizer_name( $raw ) {
		if ( ! empty( $raw['primary_organizer'] ) && is_array( $raw['primary_organizer'] ) ) {
			return $this->get( $raw['primary_organizer'], 'name' );
		}
		return '';
	}

	/**
	 * Get the price string from the raw event.
	 *
	 * @param array $raw Raw Eventbrite event.
	 * @return string
	 */
	protected function get_price( $raw ) {
		if ( ! empty( $raw['is_free'] ) ) {
			return 'Free';
		}
		if ( ! empty( $raw['ticket_availability'] ) && is_array( $raw['ticket_availability'] ) ) {
			$min = isset( $raw['ticket_availability']['minimum_ticket_price'] ) ? $raw['ticket_availability']['minimum_ticket_price'] : '';
			$max = isset( $raw['ticket_availability']['maximum_ticket_price'] ) ? $raw['ticket_availability']['maximum_ticket_price'] : '';
			if ( $min && $max && $min !== $max ) {
				return $min . ' – ' . $max;
			}
			if ( $min ) {
				return $min;
			}
			if ( $max ) {
				return $max;
			}
		}
		return '';
	}

	/**
	 * Get the category from Eventbrite tags.
	 *
	 * @param array $raw Raw Eventbrite event.
	 * @return string
	 */
	protected function get_category( $raw ) {
		if ( empty( $raw['tags'] ) || ! is_array( $raw['tags'] ) ) {
			return '';
		}

		foreach ( $raw['tags'] as $tag ) {
			if ( ! is_array( $tag ) ) {
				continue;
			}
			$type = isset( $tag['type'] ) ? $tag['type'] : '';
			if ( 'EventbriteCategory' === $type ) {
				return isset( $tag['display_name'] ) ? $tag['display_name'] : '';
			}
		}

		return '';
	}

	/**
	 * Get the subcategory from Eventbrite tags.
	 *
	 * @param array $raw Raw Eventbrite event.
	 * @return string
	 */
	protected function get_subcategory( $raw ) {
		if ( empty( $raw['tags'] ) || ! is_array( $raw['tags'] ) ) {
			return '';
		}

		foreach ( $raw['tags'] as $tag ) {
			if ( ! is_array( $tag ) ) {
				continue;
			}
			$type = isset( $tag['type'] ) ? $tag['type'] : '';
			if ( 'EventbriteSubCategory' === $type ) {
				return isset( $tag['display_name'] ) ? $tag['display_name'] : '';
			}
		}

		return '';
	}

	/**
	 * Get a location part from the Eventbrite locations array.
	 *
	 * @param array  $raw  Raw Eventbrite event.
	 * @param string $type Location type (continent|country|region|locality).
	 * @return string
	 */
	protected function get_location_part( $raw, $type ) {
		if ( empty( $raw['locations'] ) || ! is_array( $raw['locations'] ) ) {
			return '';
		}

		foreach ( $raw['locations'] as $location ) {
			if ( ! is_array( $location ) ) {
				continue;
			}
			if ( isset( $location['type'] ) && $type === $location['type'] ) {
				return isset( $location['name'] ) ? $location['name'] : '';
			}
		}

		return '';
	}

	/**
	 * Get the venue name from the raw event.
	 *
	 * @param array $raw Raw Eventbrite event.
	 * @return string
	 */
	protected function get_venue_name( $raw ) {
		if ( ! empty( $raw['venue'] ) && is_array( $raw['venue'] ) ) {
			return $this->get( $raw['venue'], 'name' );
		}
		return '';
	}

	/**
	 * Get the venue address from the raw event.
	 *
	 * @param array $raw Raw Eventbrite event.
	 * @return string
	 */
	protected function get_address( $raw ) {
		if ( ! empty( $raw['venue'] ) && is_array( $raw['venue'] ) ) {
			$address = isset( $raw['venue']['address'] ) ? $raw['venue']['address'] : array();
			if ( is_array( $address ) ) {
				$parts = array();
				foreach ( array( 'address_1', 'address_2', 'city', 'region', 'postal_code' ) as $field ) {
					if ( ! empty( $address[ $field ] ) ) {
						$parts[] = $address[ $field ];
					}
				}
				return implode( ', ', $parts );
			}
		}
		return '';
	}

	/**
	 * Parse Eventbrite date/time fields into the event array.
	 *
	 * Eventbrite provides separate date and time fields. We combine them
	 * into the application's date (Y-m-d) and time (H:i) format.
	 *
	 * @param array $raw   Raw Eventbrite event.
	 * @param array $event Event array (modified by reference).
	 */
	protected function parse_dates( $raw, &$event ) {
		$timezone = ! empty( $event['timezone'] ) ? $event['timezone'] : 'Europe/Dublin';

		// Start date/time.
		$start_date = $this->get( $raw, 'start_date' );
		$start_time = $this->get( $raw, 'start_time' );
		$event['start_date'] = $this->normalize_date( $start_date );
		$event['start_time'] = $this->normalize_time( $start_time );

		// End date/time.
		$end_date = $this->get( $raw, 'end_date' );
		$end_time = $this->get( $raw, 'end_time' );
		$event['end_date'] = $this->normalize_date( $end_date );
		$event['end_time'] = $this->normalize_time( $end_time );

		// If no end date, use start date.
		if ( empty( $event['end_date'] ) && ! empty( $event['start_date'] ) ) {
			$event['end_date'] = $event['start_date'];
		}
	}

	/**
	 * Normalize a date string to Y-m-d.
	 *
	 * @param string $date Raw date.
	 * @return string
	 */
	protected function normalize_date( $date ) {
		$date = trim( (string) $date );
		if ( empty( $date ) ) {
			return '';
		}

		// Already Y-m-d.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		// ISO 8601 with time.
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ]\d{2}:\d{2}/', $date, $m ) ) {
			return $m[1];
		}

		// Try strtotime.
		$ts = strtotime( $date );
		if ( $ts ) {
			return date( 'Y-m-d', $ts );
		}

		return '';
	}

	/**
	 * Normalize a time string to H:i.
	 *
	 * @param string $time Raw time.
	 * @return string
	 */
	protected function normalize_time( $time ) {
		$time = trim( (string) $time );
		if ( empty( $time ) ) {
			return '';
		}

		// Already H:i.
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
			return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		}

		// Try strtotime.
		$ts = strtotime( $time );
		if ( $ts ) {
			return date( 'H:i', $ts );
		}

		return '';
	}

	/**
	 * Build a location string from the normalized event parts.
	 *
	 * @param array $event Normalized event array.
	 * @return string
	 */
	protected function build_location_string( $event ) {
		$parts = array();
		if ( ! empty( $event['venue'] ) ) {
			$parts[] = $event['venue'];
		}
		if ( ! empty( $event['town'] ) ) {
			$parts[] = $event['town'];
		}
		if ( ! empty( $event['county'] ) ) {
			$parts[] = $event['county'];
		}
		return implode( ', ', $parts );
	}
}