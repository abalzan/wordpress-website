<?php
/**
 * Course normalizer: converts raw source data into the WordPress course structure.
 *
 * @package Conexao_Course_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Course_Normalizer {

	/** @var Conexao_Course_Location */
	protected $location;

	public function __construct( Conexao_Course_Location $location ) {
		$this->location = $location;
	}

	/**
	 * Normalize a raw source course into a structured course array.
	 *
	 * @param array $raw Raw course data from a source.
	 * @return array{
	 *   title:string, description:string, short_description:string,
	 *   start_date:string, start_time:string, end_date:string, end_time:string,
	 *   duration:string, county:string, town:string, venue:string,
	 *   address:string, banner:string, category:string, organizer:string,
	 *   price:string, currency:string, booking_url:string, source:string,
	 *   source_id:string, source_url:string, delivery_mode:string,
	 *   needs_review:bool, review_notes:array
	 * }
	 */
	public function normalize( $raw ) {
		$title       = isset( $raw['title'] ) ? trim( (string) $raw['title'] ) : '';
		$description = isset( $raw['description'] ) ? trim( (string) $raw['description'] ) : '';
		$source_url  = isset( $raw['url'] ) ? trim( (string) $raw['url'] ) : '';
		$source_id   = isset( $raw['source_id'] ) ? trim( (string) $raw['source_id'] ) : '';
		$source      = isset( $raw['source'] ) ? trim( (string) $raw['source'] ) : '';
		$banner      = isset( $raw['image'] ) ? trim( (string) $raw['image'] ) : '';

		$start_date = $this->normalize_date( isset( $raw['start_date'] ) ? $raw['start_date'] : '' );
		$end_date   = $this->normalize_date( isset( $raw['end_date'] ) ? $raw['end_date'] : '' );
		$start_time = $this->normalize_time( isset( $raw['start_time'] ) ? $raw['start_time'] : '' );
		$end_time   = $this->normalize_time( isset( $raw['end_time'] ) ? $raw['end_time'] : '' );

		$location = $this->location->normalize( isset( $raw['location'] ) ? $raw['location'] : '' );

		$category   = isset( $raw['category'] ) ? trim( (string) $raw['category'] ) : '';
		$organizer  = isset( $raw['organizer'] ) ? trim( (string) $raw['organizer'] ) : '';
		$price      = isset( $raw['price'] ) ? trim( (string) $raw['price'] ) : '';
		$currency   = isset( $raw['currency'] ) ? trim( (string) $raw['currency'] ) : '';
		$booking_url = isset( $raw['booking_url'] ) ? trim( (string) $raw['booking_url'] ) : '';
		$duration   = isset( $raw['duration'] ) ? trim( (string) $raw['duration'] ) : '';
		$delivery   = isset( $raw['delivery_mode'] ) ? trim( (string) $raw['delivery_mode'] ) : '';
		$short_desc = isset( $raw['short_description'] ) ? trim( (string) $raw['short_description'] ) : '';
		$course_type = isset( $raw['course_type'] ) ? trim( (string) $raw['course_type'] ) : '';

		$needs_review   = false;
		$review_notes   = array();

		if ( empty( $title ) ) {
			$needs_review   = true;
			$review_notes[] = __( 'Título ausente', 'conexao-course-importer' );
		}

		if ( empty( $source_url ) ) {
			$needs_review   = true;
			$review_notes[] = __( 'URL de origem ausente', 'conexao-course-importer' );
		}

		$start_time_combo = $start_time;
		if ( $end_time ) {
			$start_time_combo = $start_time ? $start_time . ' — ' . $end_time : $end_time;
		}

		// Determine the display location: prefer venue, then town, then "Online".
		$event_location = '';
		if ( 'online' === strtolower( $delivery ) ) {
			$event_location = __( 'Online', 'conexao-course-importer' );
		} elseif ( $location['venue'] ) {
			$event_location = $location['venue'];
		} elseif ( $location['town'] ) {
			$event_location = $location['town'];
		}

		return array(
			'title'             => $title,
			'description'       => $description,
			'short_description' => $short_desc,
			'start_date'        => $start_date,
			'start_time'        => $start_time,
			'end_date'          => $end_date,
			'end_time'          => $end_time,
			'duration'          => $duration,
			// Keep the legacy format the theme expects (_course_time).
			'course_time'       => $start_time_combo,
			'county'            => $location['county'],
			'town'              => $location['town'],
			'venue'             => $location['venue'],
			'address'           => $location['address'],
			'course_location'   => $event_location,
			'banner'            => $banner,
			'category'          => $category,
			'course_type'       => $course_type,
			'organizer'         => $organizer,
			'price'             => $price,
			'currency'          => $currency,
			'booking_url'       => $booking_url,
			'delivery_mode'     => $delivery,
			'source'            => $source,
			'source_id'         => $source_id,
			'source_url'        => $source_url,
			'needs_review'      => $needs_review,
			'review_notes'      => $review_notes,
		);
	}

	/**
	 * Normalize a date string into Y-m-d.
	 *
	 * @param string $date Raw date.
	 * @return string Y-m-d or empty string.
	 */
	public function normalize_date( $date ) {
		$date = trim( (string) $date );
		if ( empty( $date ) ) {
			return '';
		}

		// Already Y-m-d.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}

		// d/m/Y or d.m.Y.
		if ( preg_match( '/^(\d{1,2})[\/\.](\d{1,2})[\/\.](\d{4})/', $date, $m ) ) {
			$ts = strtotime( $m[3] . '-' . $m[2] . '-' . $m[1] );
			return $ts ? date( 'Y-m-d', $ts ) : '';
		}

		// ISO 8601 with time.
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})[T ]\d{2}:\d{2}/', $date, $m ) ) {
			return $m[1];
		}

		// Try strtotime for textual dates.
		$ts = strtotime( $date );
		if ( $ts ) {
			return date( 'Y-m-d', $ts );
		}

		return '';
	}

	/**
	 * Normalize a time string into H:i (24h).
	 *
	 * @param string $time Raw time.
	 * @return string H:i or empty string.
	 */
	public function normalize_time( $time ) {
		$time = trim( (string) $time );
		if ( empty( $time ) ) {
			return '';
		}

		// 10:00 AM / 10:00PM / 10:00 am.
		if ( preg_match( '/^(\d{1,2}):(\d{2})\s*([AaPp][Mm])?$/', $time, $m ) ) {
			$hour = (int) $m[1];
			$min  = (int) $m[2];
			$ampm = isset( $m[3] ) ? strtolower( $m[3] ) : '';

			if ( 'pm' === $ampm && $hour < 12 ) {
				$hour += 12;
			} elseif ( 'am' === $ampm && 12 === $hour ) {
				$hour = 0;
			}

			return sprintf( '%02d:%02d', $hour, $min );
		}

		// 10am / 10 pm.
		if ( preg_match( '/^(\d{1,2})(?::(\d{2}))?\s*([AaPp][Mm])$/', $time, $m ) ) {
			$hour = (int) $m[1];
			$min  = isset( $m[2] ) ? (int) $m[2] : 0;
			$ampm = strtolower( $m[3] );

			if ( 'pm' === $ampm && $hour < 12 ) {
				$hour += 12;
			} elseif ( 'am' === $ampm && 12 === $hour ) {
				$hour = 0;
			}

			return sprintf( '%02d:%02d', $hour, $min );
		}

		// 24h already.
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
			return sprintf( '%02d:%02d', (int) $m[1], (int) $m[2] );
		}

		return '';
	}

	/**
	 * Extract a category slug from a raw category string.
	 *
	 * @param string $category Raw category.
	 * @return string Clean category name (or empty).
	 */
	public function clean_category( $category ) {
		$category = trim( (string) $category );
		if ( empty( $category ) ) {
			return '';
		}

		// Strip common prefixes.
		$category = preg_replace( '/^(category|type|tag|tema|área|área temática)\s*[: \-]+/i', '', $category );

		return ucwords( strtolower( trim( $category ) ) );
	}
}