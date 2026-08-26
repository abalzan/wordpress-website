<?php
/**
 * Event normalizer: converts raw source data into the WordPress event structure.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Event_Normalizer {

	/** @var Conexao_Event_Location */
	protected $location;

	public function __construct( Conexao_Event_Location $location ) {
		$this->location = $location;
	}

	/**
	 * Normalize a raw source event into a structured event array.
	 *
	 * @param array $raw Raw event data from a source.
	 * @return array{
	 *   title:string, description:string, start_date:string, start_time:string,
	 *   end_date:string, end_time:string, county:string, town:string, venue:string,
	 *   address:string, banner:string, category:string, organizer:string, price:string,
	 *   source:string, source_id:string, source_url:string,
	 *   validation_errors:array
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

		// Per-source county hint: sources scoped to a single county (e.g.
		// 'county' => 'Laois' in the source config) can guarantee the county
		// even when the raw location string omits it or is empty entirely.
		if ( empty( $location['county'] ) && ! empty( $raw['county'] ) ) {
			$location['county'] = trim( (string) $raw['county'] );
		}

		$category = isset( $raw['category'] ) ? trim( (string) $raw['category'] ) : '';
		$organizer = isset( $raw['organizer'] ) ? trim( (string) $raw['organizer'] ) : '';
		$price     = isset( $raw['price'] ) ? trim( (string) $raw['price'] ) : '';

		// Required-field validation. Events failing these checks are skipped
		// by the importer — they are never created as posts.
		$validation_errors = array();

		if ( empty( $title ) ) {
			$validation_errors[] = __( 'Título ausente', 'conexao-event-importer' );
		}

		if ( empty( $start_date ) ) {
			$validation_errors[] = __( 'Data de início ausente', 'conexao-event-importer' );
		}

		if ( empty( $source_url ) ) {
			$validation_errors[] = __( 'URL de origem ausente', 'conexao-event-importer' );
		}

		// County-level identification is sufficient:
		// feeds scoped to one county (Laois Tourism, Heritage Week) often
		// omit precise venues, and the county tag keeps them filterable.
		if ( empty( $location['town'] ) && empty( $location['venue'] ) && empty( $location['county'] ) ) {
			$validation_errors[] = __( 'Localização não identificada', 'conexao-event-importer' );
		}

		$start_time_combo = $start_time;
		if ( $end_time ) {
			$start_time_combo = $start_time ? $start_time . ' — ' . $end_time : $end_time;
		}

		return array(
			'title'        => $title,
			'description'  => $description,
			'start_date'   => $start_date,
			'start_time'   => $start_time,
			'end_date'     => $end_date,
			'end_time'     => $end_time,
			// Keep the legacy format the theme expects (_event_time).
			'event_time'   => $start_time_combo,
			'county'       => $location['county'],
			'town'         => $location['town'],
			'venue'        => $location['venue'],
			'address'      => $location['address'],
			// The theme's _event_location meta shows in cards; prefer venue,
			// then town, then county so county-scoped events still show a label.
			'event_location' => $location['venue'] ? $location['venue'] : ( $location['town'] ? $location['town'] : $location['county'] ),
			'banner'       => $banner,
			'category'     => $category,
			'organizer'    => $organizer,
			'price'        => $price,
			'source'            => $source,
			'source_id'         => $source_id,
			'source_url'        => $source_url,
			'validation_errors' => $validation_errors,
		);
	}

	/**
	 * Normalize a date string into Y-m-d.
	 *
	 * Accepts:
	 *  - Y-m-d
	 *  - d/m/Y
	 *  - d.m.Y
	 *  - "12 March 2026"
	 *  - "12th March 2026"
	 *  - "March 12, 2026"
	 *  - ISO 8601 / RFC 3339 with time
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
	 * Accepts:
	 *  - 10:00
	 *  - 10:00 AM
	 *  - 10am
	 *  - 10:00am
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

		// 10am / 10 pm / 10:00am.
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

		// Strip common prefixes like "Category: ".
		$category = preg_replace( '/^(category|type|tag|tema|área|área temática)\s*[: \-]+/i', '', $category );

		// Capitalize first letter of each word.
		return ucwords( strtolower( trim( $category ) ) );
	}
}