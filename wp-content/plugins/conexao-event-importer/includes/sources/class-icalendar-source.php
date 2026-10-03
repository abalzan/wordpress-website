<?php
/**
 * iCalendar/Webcal event source handler.
 *
 * Fetches and parses events from iCalendar (.ics) feeds.
 * Supports webcal:// and https:// protocols.
 *
 * @package Conexao_Event_Importer
 */

defined( 'ABSPATH' ) || exit;

class Conexao_Source_ICalendar extends Conexao_Source_Base {

	/**
	 * Get the source slug.
	 *
	 * @return string
	 */
	public function get_id() {
		return isset( $this->config['id'] ) ? $this->config['id'] : 'icalendar';
	}

	/**
	 * Fetch raw event listings from the iCalendar feed.
	 *
	 * @return array[]
	 */
	public function fetch_events() {
		// Check if we have uploaded ICS content to use instead of fetching from URL.
		$ical_data = $this->get_uploaded_ics_content();

		if ( empty( $ical_data ) ) {
			// Fall back to fetching from URL.
			$url = $this->get_feed_url();

			if ( empty( $url ) ) {
				Conexao_Import_Log::add( $this->get_id(), 'error', 'No ICS file uploaded and no feed URL configured.' );
				return array();
			}

			$ical_data = $this->fetch_ical( $url );
		}

		if ( empty( $ical_data ) ) {
			Conexao_Import_Log::add( $this->get_id(), 'error', 'No iCalendar data available. Upload an ICS file or check the feed URL.' );
			return array();
		}

		$events = $this->parse_ical( $ical_data );

		if ( empty( $events ) ) {
			Conexao_Import_Log::add( $this->get_id(), 'warning', 'iCalendar data was parsed but no events were found. The file may be empty or in an unsupported format.' );
		}

		return $events;
	}

	/**
	 * Get uploaded ICS content if available.
	 *
	 * @return string ICS content or empty string.
	 */
	protected function get_uploaded_ics_content() {
		if ( ! empty( $this->config['ics_content'] ) ) {
			return $this->config['ics_content'];
		}
		return '';
	}

	/**
	 * Get the feed URL, normalizing webcal:// to https://
	 *
	 * @return string
	 */
	protected function get_feed_url() {
		$url = isset( $this->config['url'] ) ? $this->config['url'] : '';

		if ( empty( $url ) ) {
			return '';
		}

		// Normalize webcal:// to https://
		if ( 0 === strpos( $url, 'webcal://' ) ) {
			$url = 'https://' . substr( $url, 9 );
		}

		return $url;
	}

	/**
	 * Fetch the iCalendar feed data.
	 *
	 * Delegates to the structured base fetcher, which throws a
	 * Conexao_Source_Fetch_Exception on transport/HTTP failures so the
	 * import engine reports a clean fatal error instead of an ambiguous
	 * "no events" result.
	 *
	 * @param string $url Feed URL.
	 * @return string iCalendar data.
	 * @throws Conexao_Source_Fetch_Exception On any transport or HTTP failure.
	 */
	protected function fetch_ical( $url ) {
		return $this->fetch_html(
			$url,
			array(
				'headers' => array(
					'Accept' => 'text/calendar, application/calendar+json, */*',
				),
			)
		);
	}

	/**
	 * Parse iCalendar data and extract events.
	 *
	 * @param string $ical_data Raw iCalendar data.
	 * @return array[] List of event arrays.
	 */
	protected function parse_ical( $ical_data ) {
		$events = array();

		// Normalize line endings (iCal uses CRLF or folded lines).
		$ical_data = $this->unfold_ical( $ical_data );

		// Extract VEVENT blocks.
		$vevents = $this->extract_vevents( $ical_data );

		foreach ( $vevents as $vevent ) {
			$event = $this->parse_vevent( $vevent );
			if ( ! empty( $event['title'] ) ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Unfold iCalendar data (handle line continuation).
	 *
	 * iCalendar lines can be folded by inserting a CRLF followed by a single
	 * whitespace character. This method unfolds such lines.
	 *
	 * @param string $data Raw iCalendar data.
	 * @return string Unfolded data.
	 */
	protected function unfold_ical( $data ) {
		// Normalize all line endings to LF.
		$data = str_replace( array( "\r\n", "\r" ), "\n", $data );

		// Unfold lines: a line starting with a space or tab continues the previous line.
		$data = preg_replace( "/\n[ \t]/", '', $data );

		return $data;
	}

	/**
	 * Extract VEVENT blocks from iCalendar data.
	 *
	 * @param string $data Unfolded iCalendar data.
	 * @return array[] List of VEVENT property arrays.
	 */
	protected function extract_vevents( $data ) {
		$vevents = array();
		$lines   = explode( "\n", $data );
		$in_vevent = false;
		$current   = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( empty( $line ) ) {
				continue;
			}

			if ( 'BEGIN:VEVENT' === $line ) {
				$in_vevent = true;
				$current   = array();
				continue;
			}

			if ( 'END:VEVENT' === $line ) {
				$in_vevent = false;
				if ( ! empty( $current ) ) {
					$vevents[] = $current;
				}
				continue;
			}

			if ( $in_vevent ) {
				// Parse property: NAME;PARAMS:VALUE or NAME:VALUE
				if ( preg_match( '/^([A-Z0-9-]+)([^:]*):(.*)$/i', $line, $matches ) ) {
					$prop_name = strtoupper( $matches[1] );
					$prop_params = $matches[2];
					$prop_value = $matches[3];

					// Store the property (some properties may appear multiple times).
					if ( ! isset( $current[ $prop_name ] ) ) {
						$current[ $prop_name ] = array();
					}
					$current[ $prop_name ][] = array(
						'value'  => $prop_value,
						'params' => $prop_params,
					);
				}
			}
		}

		return $vevents;
	}

	/**
	 * Parse a single VEVENT into an event array.
	 *
	 * @param array $vevent VEVENT properties.
	 * @return array Event data.
	 */
	protected function parse_vevent( $vevent ) {
		$event = array(
			'source'      => $this->get_id(),
			'title'       => '',
			'url'         => '',
			'start_date'  => '',
			'start_time'  => '',
			'end_date'    => '',
			'end_time'    => '',
			'location'    => '',
			'description' => '',
			'image'       => '',
			'source_id'   => '',
			'organizer'   => '',
			// Whether DTSTART/DTEND are VALUE=DATE all-day values. Needed so
			// the Laois series adapter can exclude all-day events, which never
			// participate in the collapsed-weekly-series convention.
			'all_day'     => false,
		);

		// Title (SUMMARY).
		$event['title'] = $this->get_property_value( $vevent, 'SUMMARY' );

		// Description.
		$event['description'] = $this->get_property_value( $vevent, 'DESCRIPTION' );

		// Unescape iCal text (\\, \;, \,, \n).
		$event['title'] = $this->unescape_ical_text( $event['title'] );
		$event['description'] = $this->unescape_ical_text( $event['description'] );

		// Convert newlines in description.
		$event['description'] = str_replace( '\n', "\n", $event['description'] );

		// Location.
		$event['location'] = $this->get_property_value( $vevent, 'LOCATION' );
		$event['location'] = $this->unescape_ical_text( $event['location'] );

		// Start date/time.
		$dtstart = $this->get_property( $vevent, 'DTSTART' );
		if ( $dtstart ) {
			$dt = $this->parse_ical_datetime( $dtstart['value'], $dtstart['params'] );
			$event['start_date'] = $dt['date'];
			$event['start_time'] = $dt['time'];
			$event['all_day']     = $dt['all_day'];
		}

		// End date/time.
		$dtend = $this->get_property( $vevent, 'DTEND' );
		if ( $dtend ) {
			$dt = $this->parse_ical_datetime( $dtend['value'], $dtend['params'] );
			$event['end_date'] = $dt['date'];
			$event['end_time'] = $dt['time'];
			$event['all_day'] = $event['all_day'] || $dt['all_day'];
		}

		// If no end date, use start date.
		if ( empty( $event['end_date'] ) && ! empty( $event['start_date'] ) ) {
			$event['end_date'] = $event['start_date'];
		}

		/*
		 * Recurrence resolution.
		 *
		 * Two independent, ordered mechanisms — the STANDARD one always wins:
		 *
		 *  1. An explicit RRULE (RFC 5545). Read generically for every ICS
		 *     source. A rule outside the representable subset yields null and
		 *     the event simply stays one-time (its DTSTART/DTEND still
		 *     describe a valid single event).
		 *
		 *  2. A SOURCE-SPECIFIC collapsed-series adapter, consulted only for
		 *     the source ids that declare one. Laois Tourism is the only
		 *     current example: it publishes no RRULE at all and encodes a
		 *     weekly course as DTSTART=first occurrence + DTEND=last
		 *     occurrence. See Conexao_Laois_Tourism_Series for the measured
		 *     basis of that rule.
		 *
		 * The resulting `recurrence` key is a DESCRIPTION (weekdays + window),
		 * never a list of occurrence posts. Conexao_Event_Recurrence in the
		 * event runtime stays the single evaluator of "occurs on date X", so
		 * one canonical event post is stored and no second recurrence engine
		 * exists.
		 */
		$recurrence = $this->resolve_rrule_recurrence( $vevent, $event );

		if ( null === $recurrence ) {
			$recurrence = $this->resolve_series_adapter_recurrence( $event );
		}

		if ( null !== $recurrence ) {
			$event['recurrence'] = $recurrence;
		}

		// Original event URL.
		$event['url'] = $this->get_property_value( $vevent, 'URL' );

		// If no URL in the event, try to construct one from the source.
		if ( empty( $event['url'] ) && ! empty( $this->config['url'] ) ) {
			// Use the source URL as fallback.
			$event['url'] = isset( $this->config['source_url'] ) ? $this->config['source_url'] : $this->config['url'];
		}

		// Unique identifier (UID).
		$uid = $this->get_property_value( $vevent, 'UID' );
		if ( ! empty( $uid ) ) {
			$event['source_id'] = $uid;
		} elseif ( ! empty( $event['url'] ) ) {
			// Fallback: derive from URL.
			$event['source_id'] = md5( $event['url'] );
		} else {
			// Fallback: derive from title + date.
			$event['source_id'] = md5( $event['title'] . $event['start_date'] . $event['start_time'] );
		}

		// Organizer.
		$organizer = $this->get_property( $vevent, 'ORGANIZER' );
		if ( $organizer ) {
			$org_value = $organizer['value'];
			// Extract CN (common name) from params if available.
			if ( preg_match( '/CN="([^"]+)"/i', $organizer['params'], $cn_match ) ) {
				$event['organizer'] = $cn_match[1];
			} elseif ( preg_match( '/CN=([^;:]+)/i', $organizer['params'], $cn_match ) ) {
				$event['organizer'] = $cn_match[1];
			} else {
				// Try to extract name from mailto: URI.
				if ( preg_match( '/mailto:([^@]+)@/i', $org_value, $email_match ) ) {
					$event['organizer'] = $email_match[1];
				} else {
					$event['organizer'] = $org_value;
				}
			}
		}

		// Image/attachment.
		$attach = $this->get_property( $vevent, 'ATTACH' );
		if ( $attach ) {
			$attach_value = $attach['value'];
			// Check if it's a URL (FMTTYPE parameter or direct URL).
			if ( preg_match( '/^https?:\/\//i', $attach_value ) ) {
				$event['image'] = $attach_value;
			} elseif ( preg_match( '/FMTTYPE=image\/[^;:]+/i', $attach['params'] ) ) {
				// Could be a data URI or external URL.
				if ( preg_match( '/^https?:\/\//i', $attach_value ) ) {
					$event['image'] = $attach_value;
				}
			}
		}

		// Also check for X- properties that might contain images.
		$image_url = $this->get_property_value( $vevent, 'X-IMAGE' );
		if ( empty( $event['image'] ) && ! empty( $image_url ) && preg_match( '/^https?:\/\//i', $image_url ) ) {
			$event['image'] = $image_url;
		}

		// Categories: CATEGORIES is a comma-separated list; the first entry
		// becomes the primary event category (e.g. "Heritage Week,Member").
		$categories = $this->get_property_value( $vevent, 'CATEGORIES' );
		if ( ! empty( $categories ) ) {
			$parts              = explode( ',', $categories );
			$event['category']  = trim( $parts[0] );
		}

		// Per-source taxonomy hints configured on the source (county/category
		// fields). County-scoped feeds often omit LOCATION entirely; the hint
		// lets the normalizer tag the county and auto-publish.
		if ( empty( $event['category'] ) && ! empty( $this->config['category'] ) ) {
			$event['category'] = $this->config['category'];
		}
		if ( ! empty( $this->config['county'] ) ) {
			$event['county'] = $this->config['county'];
		}

		return $event;
	}

	/**
	 * Get the source-specific collapsed-series adapter for this source.
	 *
	 * A registry rather than a hard-coded branch, so adding another feed with
	 * its own encoding convention is a one-line change and no other source can
	 * ever be affected by someone else's rule.
	 *
	 * @return string|null Adapter class name, or null when this source has no
	 *                     source-specific convention.
	 */
	protected function resolve_series_adapter() {
		$adapters = array(
			// Laois Tourism encodes weekly courses as one VEVENT spanning
			// first..last occurrence, with no RRULE.
			Conexao_Laois_Tourism_Series::SOURCE_ID => 'Conexao_Laois_Tourism_Series',
		);

		$id = (string) $this->get_id();

		if ( ! isset( $adapters[ $id ] ) ) {
			return null;
		}

		$class = $adapters[ $id ];

		return class_exists( $class ) ? $class : null;
	}

	/**
	 * Resolve a recurrence description from an explicit RRULE, if present.
	 *
	 * Generic RFC 5545 handling applied to EVERY ICS source. Returns null when
	 * the VEVENT carries no RRULE, or when the rule is outside the subset the
	 * event data model can represent (non-weekly FREQ, INTERVAL > 1, ordinal
	 * BYDAY tokens) — in which case the event stays one-time.
	 *
	 * @param array $vevent VEVENT properties.
	 * @param array $event  Parsed event so far (for DTSTART/DTEND dates).
	 * @return array|null Recurrence description, or null.
	 */
	protected function resolve_rrule_recurrence( $vevent, array $event ) {
		$prop = $this->get_property( $vevent, 'RRULE' );

		if ( ! $prop ) {
			return null;
		}

		$parsed = Conexao_ICS_Recurrence::parse(
			$prop['value'],
			isset( $event['start_date'] ) ? $event['start_date'] : '',
			isset( $event['end_date'] ) ? $event['end_date'] : ''
		);

		if ( null === $parsed ) {
			return null;
		}

		/*
		 * COUNT-bounded rules carry an occurrence count rather than an end
		 * date. Deriving the last occurrence date from the count keeps the
		 * stored window exact, and is only ever done for a weekly rule, where
		 * "count occurrences from DTSTART" is unambiguous.
		 */
		if ( 'COUNT' === $parsed['end_source'] && $parsed['count'] > 0 && ! empty( $parsed['days'] ) ) {
			$parsed['end'] = Conexao_ICS_Recurrence::last_occurrence_date(
				$event['start_date'],
				$parsed['days'],
				$parsed['count']
			);
		}

		$parsed['source_rule'] = 'rrule';
		$parsed['type']       = 'weekly';
		$parsed['start']      = $event['start_date'];
		$parsed['occurrences'] = Conexao_ICS_Recurrence::occurrence_dates(
			$event['start_date'],
			$parsed['days'],
			$parsed['end']
		);

		return $parsed;
	}

	/**
	 * Resolve a recurrence description from a source-specific adapter.
	 *
	 * Consulted only after (and never instead of) the standard RRULE path, and
	 * only for sources that declare an adapter. This is the isolation boundary
	 * that keeps a source-specific encoding convention from leaking into
	 * standard ICS semantics for any other feed.
	 *
	 * @param array $event Parsed event.
	 * @return array|null Recurrence description, or null.
	 */
	protected function resolve_series_adapter_recurrence( array $event ) {
		$adapter = $this->resolve_series_adapter();

		if ( null === $adapter ) {
			return null;
		}

		return call_user_func( array( $adapter, 'detect' ), $event );
	}

	/**
	 * Get a property value from a VEVENT.
	 *
	 * @param array  $vevent    VEVENT properties.
	 * @param string $prop_name Property name.
	 * @return string Property value or empty string.
	 */
	protected function get_property_value( $vevent, $prop_name ) {
		$prop = $this->get_property( $vevent, $prop_name );
		return $prop ? $prop['value'] : '';
	}

	/**
	 * Get a property (with params) from a VEVENT.
	 *
	 * @param array  $vevent    VEVENT properties.
	 * @param string $prop_name Property name.
	 * @return array|null Property array with 'value' and 'params', or null.
	 */
	protected function get_property( $vevent, $prop_name ) {
		if ( isset( $vevent[ $prop_name ] ) && ! empty( $vevent[ $prop_name ] ) ) {
			return $vevent[ $prop_name ][0];
		}
		return null;
	}

	/**
	 * Parse an iCalendar datetime value.
	 *
	 * Supports:
	 *  - 20260315 (date only)
	 *  - 20260315T100000 (local time)
	 *  - 20260315T100000Z (UTC)
	 *  - With TZID parameter
	 *
	 * @param string $value  Datetime value.
	 * @param string $params Property parameters (may contain TZID).
	 * @return array{date:string, time:string, all_day:bool} `all_day` is true
	 *                for VALUE=DATE values (RFC 5545 3.3.4).
	 */
	protected function parse_ical_datetime( $value, $params = '' ) {
		$result = array(
			'date'    => '',
			'time'    => '',
			'all_day' => false,
		);

		$value = trim( $value );

		if ( empty( $value ) ) {
			return $result;
		}

		// Date only: 20260315 or 20260315T...
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})(?:T(\d{2})(\d{2})(\d{2})(Z)?)?$/', $value, $matches ) ) {
			$result['date'] = $matches[1] . '-' . $matches[2] . '-' . $matches[3];

			if ( isset( $matches[4] ) && isset( $matches[5] ) ) {
				$hour = (int) $matches[4];
				$min  = (int) $matches[5];

				// If UTC (Z suffix), convert to Dublin time.
				if ( isset( $matches[7] ) && 'Z' === $matches[7] ) {
					$timestamp = mktime( $hour, $min, 0, (int) $matches[2], (int) $matches[3], (int) $matches[1] );
					if ( $timestamp ) {
						// Convert to Europe/Dublin.
						$dt = new DateTime( '@' . $timestamp );
						$dt->setTimezone( new DateTimeZone( 'Europe/Dublin' ) );
						$result['time'] = $dt->format( 'H:i' );
					}
				} else {
					$result['time'] = sprintf( '%02d:%02d', $hour, $min );
				}
			}
		}

		/*
		 * VALUE=DATE marks an all-day event (RFC 5545 3.3.4): a DATE with no
		 * time component. `all_day` is derived from the value itself rather
		 * than only from the parameter, so a feed that omits VALUE=DATE but
		 * sends a bare YYYYMMDD is still recognised as all-day.
		 */
		$result['all_day'] = ( false === strpos( $value, 'T' ) )
			|| ( false !== stripos( $params, 'VALUE=DATE' ) );

		return $result;
	}

	/**
	 * Unescape iCalendar text values.
	 *
	 * @param string $text Escaped text.
	 * @return string Unescaped text.
	 */
	protected function unescape_ical_text( $text ) {
		// iCal escapes: \\ \; \, \n (and \N).
		$text = str_replace(
			array( '\\\\', '\\;', '\\,', '\\n', '\\N' ),
			array( '\\', ';', ',', "\n", "\n" ),
			$text
		);

		return $text;
	}
}