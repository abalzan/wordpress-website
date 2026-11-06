<?php
/**
 * Commemorative event scheduling: window resolution and occurrence identity.
 *
 * Pure date mathematics. No WordPress queries, no meta writes, no rendering:
 * every method takes the event configuration as an array and an INJECTED clock,
 * so every scheduling claim is reproducible in a test (§4.2, §9.1).
 *
 * Rules (§4.1):
 *   fixed_annual    — occurrence MM-DD each year, minus the lead-in days
 *   variable_annual — computed occurrence (Easter), minus the lead-in days
 *   one_off         — one exact stored date, never searching adjacent years
 *   window          — explicit `_ce_window_start` → `_ce_window_end`, which may
 *                     wrap across 31 December (New Year 12-26 → 01-01)
 *
 * Occurrence year (§4.3, r10): the manual-preference key is the OCCURRENCE year,
 * never `$now->format('Y')`. For the annual rules it is the calendar year of the
 * calculated occurrence; for `one_off` the stored date's year; for a
 * non-wrapping window the window year; and for a wrapping window the year in
 * which the window ENDS — so New Year 2026-12-26 → 2027-01-01 stays occurrence
 * year 2027 across midnight on 1 January.
 *
 * @package Conexao_Commemorative
 */

defined( 'ABSPATH' ) || exit;

/**
 * Window resolution and occurrence-year identity.
 */
final class Conexao_Commemorative_Schedule {

	/**
	 * Candidate years evaluated before the clock's year for annual rules.
	 *
	 * A lead-in of at most 120 days can only reach into the previous year.
	 */
	const BACK_YEARS = 1;

	/**
	 * Upcoming-window horizon in years, after the clock's year.
	 */
	const FORWARD_YEARS = 2;

	/**
	 * The site timezone.
	 *
	 * `wp_timezone()` is authoritative. The fallback is `Europe/Dublin` (this
	 * site publishes Irish dates), correcting the legacy `Europe/Lisbon`
	 * fallback (W14).
	 *
	 * @return DateTimeZone
	 */
	public static function timezone() {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		$name = function_exists( 'get_option' ) ? get_option( 'timezone_string' ) : '';

		if ( is_string( $name ) && '' !== $name ) {
			try {
				return new DateTimeZone( $name );
			} catch ( Exception $e ) {
				unset( $e );
			}
		}

		return new DateTimeZone( 'Europe/Dublin' );
	}

	/**
	 * Normalise an injected clock into the site timezone.
	 *
	 * @param DateTimeInterface|null $now Injected clock ("now" when null).
	 * @param DateTimeZone|null      $tz  Site timezone.
	 * @return DateTimeImmutable
	 */
	public static function clock( $now = null, $tz = null ) {
		$tz = $tz instanceof DateTimeZone ? $tz : self::timezone();

		if ( $now instanceof DateTimeInterface ) {
			// Preserve the INSTANT (not the wall clock): a UTC test clock must
			// resolve the same local day as the equivalent Dublin instant.
			$instant = new DateTimeImmutable( '@' . $now->getTimestamp() );

			return $instant->setTimezone( $tz );
		}

		return new DateTimeImmutable( 'now', $tz );
	}

	/**
	 * Parse a validated `MM-DD` into `array( month, day )`.
	 *
	 * @param mixed $value Stored value.
	 * @return array{0:int,1:int}|null
	 */
	public static function month_day( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{2})-(\d{2})$/', $value, $m ) ) {
			return null;
		}

		$month = (int) $m[1];
		$day   = (int) $m[2];

		if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
			return null;
		}

		if ( 2 === $month && 29 === $day ) {
			return null;
		}

		// Reject impossible month-days (04-31, 06-31 …) the same way the data
		// model's sanitizer does: a leap-year round-trip must match exactly.
		$probe = DateTimeImmutable::createFromFormat(
			'!m-d-Y',
			sprintf( '%02d-%02d-2024', $month, $day ),
			new DateTimeZone( 'UTC' )
		);

		if ( ! $probe || $probe->format( 'm-d' ) !== sprintf( '%02d-%02d', $month, $day ) ) {
			return null;
		}

		return array( $month, $day );
	}

	/**
	 * Build a midnight DateTimeImmutable for a month-day in a year.
	 *
	 * @param int                $year Year.
	 * @param array{0:int,1:int} $md   Month and day.
	 * @param DateTimeZone       $tz   Timezone.
	 * @return DateTimeImmutable|null
	 */
	private static function day_in_year( $year, array $md, DateTimeZone $tz ) {
		$date = DateTimeImmutable::createFromFormat(
			'!Y-n-j',
			sprintf( '%04d-%d-%d', (int) $year, $md[0], $md[1] ),
			$tz
		);

		if ( ! $date || (int) $date->format( 'n' ) !== $md[0] || (int) $date->format( 'j' ) !== $md[1] ) {
			return null;
		}

		return $date->setTime( 0, 0, 0 );
	}

	/**
	 * The Anonymous Gregorian (Meeus/Jones/Butcher) Easter Sunday.
	 *
	 * Reused unchanged from the legacy engine (§8.1) — the computation was
	 * already correct and covered by a green suite.
	 *
	 * @param int          $year Year.
	 * @param DateTimeZone $tz   Timezone.
	 * @return DateTimeImmutable|null
	 */
	public static function easter_sunday( $year, DateTimeZone $tz ) {
		$year = (int) $year;

		$a     = $year % 19;
		$b     = intdiv( $year, 100 );
		$c     = $year % 100;
		$d     = intdiv( $b, 4 );
		$e     = $b % 4;
		$f     = intdiv( $b + 8, 25 );
		$g     = intdiv( $b - $f + 1, 3 );
		$h     = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i     = intdiv( $c, 4 );
		$k     = $c % 4;
		$l     = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m     = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

		$date = DateTimeImmutable::createFromFormat(
			'!Y-n-j',
			sprintf( '%04d-%d-%d', $year, $month, $day ),
			$tz
		);

		return $date ? $date->setTime( 0, 0, 0 ) : null;
	}

	/**
	 * The occurrence date for an annual rule in a given year.
	 *
	 * @param array        $event Event configuration.
	 * @param int          $year  Calendar year.
	 * @param DateTimeZone $tz    Timezone.
	 * @return DateTimeImmutable|null
	 */
	private static function annual_occurrence( array $event, $year, DateTimeZone $tz ) {
		$rule = isset( $event['rule'] ) ? (string) $event['rule'] : 'fixed_annual';

		if ( 'variable_annual' === $rule ) {
			$variable = isset( $event['variable'] ) ? (string) $event['variable'] : 'easter';

			return 'easter' === $variable ? self::easter_sunday( $year, $tz ) : null;
		}

		$md = self::month_day( isset( $event['date'] ) ? $event['date'] : '' );

		return $md ? self::day_in_year( $year, $md, $tz ) : null;
	}

	/**
	 * Build an annual-rule window from an occurrence date.
	 *
	 * Start is inclusive at 00:00:00, end inclusive at 23:59:59 (§4.2).
	 *
	 * @param DateTimeImmutable $occurrence Occurrence date (midnight).
	 * @param int               $lead       Lead-in days.
	 * @return array{start:DateTimeImmutable,end:DateTimeImmutable}
	 */
	private static function annual_window( DateTimeImmutable $occurrence, $lead ) {
		$lead  = max( 0, (int) $lead );
		$start = $occurrence->modify( '-' . $lead . ' days' )->setTime( 0, 0, 0 );
		$end   = $occurrence->setTime( 23, 59, 59 );

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Whether the clock is inside a window (boundaries inclusive, §4.2).
	 *
	 * @param DateTimeImmutable $now   Clock.
	 * @param DateTimeImmutable $start Start.
	 * @param DateTimeImmutable $end   End.
	 * @return bool
	 */
	private static function contains( DateTimeImmutable $now, DateTimeImmutable $start, DateTimeImmutable $end ) {
		return $now >= $start && $now <= $end;
	}

	/**
	 * Resolve the occurrence/window that contains the clock.
	 *
	 * @param array                  $event Event configuration.
	 * @param DateTimeInterface|null $now   Injected clock.
	 * @param DateTimeZone|null      $tz    Site timezone.
	 * @return array{start:DateTimeImmutable,end:DateTimeImmutable,occurrence_year:int,rule:string,occurrence:DateTimeImmutable|null}|null
	 */
	public static function resolve( array $event, $now = null, $tz = null ) {
		$tz   = $tz instanceof DateTimeZone ? $tz : self::timezone();
		$now  = self::clock( $now, $tz );
		$rule = isset( $event['rule'] ) ? (string) $event['rule'] : 'fixed_annual';
		$year = (int) $now->format( 'Y' );

		if ( 'one_off' === $rule ) {
			return self::resolve_one_off( $event, $now, $tz );
		}

		if ( 'window' === $rule ) {
			return self::resolve_window( $event, $now, $tz );
		}

		for ( $candidate = $year - self::BACK_YEARS; $candidate <= $year + 1; $candidate++ ) {
			$occurrence = self::annual_occurrence( $event, $candidate, $tz );

			if ( ! $occurrence ) {
				continue;
			}

			$window = self::annual_window( $occurrence, isset( $event['lead_in_days'] ) ? $event['lead_in_days'] : 0 );

			if ( self::contains( $now, $window['start'], $window['end'] ) ) {
				return array(
					'start'           => $window['start'],
					'end'             => $window['end'],
					'occurrence_year' => $candidate,
					'rule'            => $rule,
					'occurrence'      => $occurrence,
				);
			}
		}

		return null;
	}

	/**
	 * Resolve a `one_off` event: its exact stored date only (never adjacent years).
	 *
	 * @param array             $event Event configuration.
	 * @param DateTimeImmutable $now   Clock.
	 * @param DateTimeZone      $tz    Timezone.
	 * @return array|null
	 */
	private static function resolve_one_off( array $event, DateTimeImmutable $now, DateTimeZone $tz ) {
		$raw = isset( $event['one_off_date'] ) ? (string) $event['one_off_date'] : '';

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return null;
		}

		$occurrence = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, $tz );

		if ( ! $occurrence ) {
			return null;
		}

		$window = self::annual_window( $occurrence, isset( $event['lead_in_days'] ) ? $event['lead_in_days'] : 0 );

		if ( ! self::contains( $now, $window['start'], $window['end'] ) ) {
			return null;
		}

		return array(
			'start'           => $window['start'],
			'end'             => $window['end'],
			'occurrence_year' => (int) $occurrence->format( 'Y' ),
			'rule'            => 'one_off',
			'occurrence'      => $occurrence,
		);
	}

	/**
	 * Resolve an explicit `window` rule, including the 31 December wrap.
	 *
	 * @param array             $event Event configuration.
	 * @param DateTimeImmutable $now   Clock.
	 * @param DateTimeZone      $tz    Timezone.
	 * @return array|null
	 */
	private static function resolve_window( array $event, DateTimeImmutable $now, DateTimeZone $tz ) {
		$start_md = self::month_day( isset( $event['window_start'] ) ? $event['window_start'] : '' );
		$end_md   = self::month_day( isset( $event['window_end'] ) ? $event['window_end'] : '' );

		if ( ! $start_md || ! $end_md ) {
			return null;
		}

		$year    = (int) $now->format( 'Y' );
		$wrapped = self::wraps( $start_md, $end_md );

		for ( $candidate = $year - self::BACK_YEARS; $candidate <= $year + 1; $candidate++ ) {
			$window = self::window_for_year( $candidate, $start_md, $end_md, $wrapped, $tz );

			if ( ! $window || ! self::contains( $now, $window['start'], $window['end'] ) ) {
				continue;
			}

			return array(
				'start'           => $window['start'],
				'end'             => $window['end'],
				// A wrapping window belongs to the year in which it ENDS.
				'occurrence_year' => $wrapped ? $candidate + 1 : $candidate,
				'rule'            => 'window',
				'occurrence'      => null,
			);
		}

		return null;
	}

	/**
	 * Whether a `window` rule spans two calendar years.
	 *
	 * @param array{0:int,1:int} $start_md Start month-day.
	 * @param array{0:int,1:int} $end_md   End month-day.
	 * @return bool
	 */
	private static function wraps( array $start_md, array $end_md ) {
		return sprintf( '%02d-%02d', $start_md[0], $start_md[1] ) > sprintf( '%02d-%02d', $end_md[0], $end_md[1] );
	}

	/**
	 * Build the concrete window for one candidate year.
	 *
	 * @param int                $year     Candidate window year.
	 * @param array{0:int,1:int} $start_md Start month-day.
	 * @param array{0:int,1:int} $end_md   End month-day.
	 * @param bool               $wrapped  Whether the window crosses 31 December.
	 * @param DateTimeZone       $tz       Timezone.
	 * @return array{start:DateTimeImmutable,end:DateTimeImmutable}|null
	 */
	private static function window_for_year( $year, array $start_md, array $end_md, $wrapped, DateTimeZone $tz ) {
		$start_day = self::day_in_year( $year, $start_md, $tz );
		$end_day   = self::day_in_year( $wrapped ? $year + 1 : $year, $end_md, $tz );

		if ( ! $start_day || ! $end_day ) {
			return null;
		}

		return array(
			'start' => $start_day->setTime( 0, 0, 0 ),
			'end'   => $end_day->setTime( 23, 59, 59 ),
		);
	}

	/**
	 * The window that applies to the clock, or the next upcoming one.
	 *
	 * Used by the admin list ("Janela calculada" shows the NEXT occurrence) and
	 * by the diagnostics box. When a window already contains the clock it is
	 * returned with `active = true`.
	 *
	 * @param array                  $event Event configuration.
	 * @param DateTimeInterface|null $now   Injected clock.
	 * @param DateTimeZone|null      $tz    Site timezone.
	 * @return array{start:DateTimeImmutable,end:DateTimeImmutable,occurrence_year:int,rule:string,active:bool}|null
	 */
	public static function next_window( array $event, $now = null, $tz = null ) {
		$tz   = $tz instanceof DateTimeZone ? $tz : self::timezone();
		$now  = self::clock( $now, $tz );
		$rule = isset( $event['rule'] ) ? (string) $event['rule'] : 'fixed_annual';

		$active = self::resolve( $event, $now, $tz );

		if ( $active ) {
			$active['active'] = true;

			return $active;
		}

		$year = (int) $now->format( 'Y' );

		if ( 'one_off' === $rule ) {
			$raw = isset( $event['one_off_date'] ) ? (string) $event['one_off_date'] : '';

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
				return null;
			}

			$occurrence = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, $tz );

			if ( ! $occurrence || $occurrence->setTime( 23, 59, 59 ) < $now ) {
				return null;
			}

			$window = self::annual_window( $occurrence, isset( $event['lead_in_days'] ) ? $event['lead_in_days'] : 0 );

			return array(
				'start'           => $window['start'],
				'end'             => $window['end'],
				'occurrence_year' => (int) $occurrence->format( 'Y' ),
				'rule'            => 'one_off',
				'active'          => false,
			);
		}

		if ( 'window' === $rule ) {
			$start_md = self::month_day( isset( $event['window_start'] ) ? $event['window_start'] : '' );
			$end_md   = self::month_day( isset( $event['window_end'] ) ? $event['window_end'] : '' );

			if ( ! $start_md || ! $end_md ) {
				return null;
			}

			$wrapped = self::wraps( $start_md, $end_md );

			for ( $y = $year - 1; $y <= $year + self::FORWARD_YEARS; $y++ ) {
				$window = self::window_for_year( $y, $start_md, $end_md, $wrapped, $tz );

				if ( ! $window || $window['end'] < $now ) {
					continue;
				}

				return array(
					'start'           => $window['start'],
					'end'             => $window['end'],
					'occurrence_year' => $wrapped ? $y + 1 : $y,
					'rule'            => 'window',
					'active'          => false,
				);
			}

			return null;
		}

		for ( $y = $year - 1; $y <= $year + self::FORWARD_YEARS; $y++ ) {
			$occurrence = self::annual_occurrence( $event, $y, $tz );

			if ( ! $occurrence ) {
				continue;
			}

			$window = self::annual_window( $occurrence, isset( $event['lead_in_days'] ) ? $event['lead_in_days'] : 0 );

			if ( $window['end'] < $now ) {
				continue;
			}

			return array(
				'start'           => $window['start'],
				'end'             => $window['end'],
				'occurrence_year' => $y,
				'rule'            => $rule,
				'active'          => false,
			);
		}

		return null;
	}

	/**
	 * Every upcoming boundary (window start or end) after the clock.
	 *
	 * The scheduler arms ONE single event at the earliest of these (§4.6).
	 *
	 * @param array                  $event Event configuration.
	 * @param DateTimeInterface|null $now   Injected clock.
	 * @param DateTimeZone|null      $tz    Site timezone.
	 * @return array<int,array{timestamp:int,type:string,datetime:DateTimeImmutable}>
	 */
	public static function upcoming_boundaries( array $event, $now = null, $tz = null ) {
		$tz   = $tz instanceof DateTimeZone ? $tz : self::timezone();
		$now  = self::clock( $now, $tz );
		$rule = isset( $event['rule'] ) ? (string) $event['rule'] : 'fixed_annual';
		$year = (int) $now->format( 'Y' );
		$out  = array();

		if ( 'one_off' === $rule ) {
			$raw = isset( $event['one_off_date'] ) ? (string) $event['one_off_date'] : '';

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
				return array();
			}

			$occurrence = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw, $tz );

			if ( ! $occurrence ) {
				return array();
			}

			$window = self::annual_window( $occurrence, isset( $event['lead_in_days'] ) ? $event['lead_in_days'] : 0 );

			return self::collect( array(), $window['start'], $window['end'], $now );
		}

		if ( 'window' === $rule ) {
			$start_md = self::month_day( isset( $event['window_start'] ) ? $event['window_start'] : '' );
			$end_md   = self::month_day( isset( $event['window_end'] ) ? $event['window_end'] : '' );

			if ( ! $start_md || ! $end_md ) {
				return array();
			}

			$wrapped = self::wraps( $start_md, $end_md );

			for ( $y = $year - 1; $y <= $year + self::FORWARD_YEARS; $y++ ) {
				$window = self::window_for_year( $y, $start_md, $end_md, $wrapped, $tz );
				if ( $window ) {
					$out = self::collect( $out, $window['start'], $window['end'], $now );
				}
			}

			return self::sort_boundaries( $out );
		}

		for ( $y = $year - 1; $y <= $year + self::FORWARD_YEARS; $y++ ) {
			$occurrence = self::annual_occurrence( $event, $y, $tz );

			if ( ! $occurrence ) {
				continue;
			}

			$window = self::annual_window( $occurrence, isset( $event['lead_in_days'] ) ? $event['lead_in_days'] : 0 );
			$out    = self::collect( $out, $window['start'], $window['end'], $now );
		}

		return self::sort_boundaries( $out );
	}

	/**
	 * Append the boundaries that are still in the future.
	 *
	 * @param array             $out   Accumulator.
	 * @param DateTimeImmutable $start Window start.
	 * @param DateTimeImmutable $end   Window end.
	 * @param DateTimeImmutable $now   Clock.
	 * @return array
	 */
	private static function collect( array $out, DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $now ) {
		if ( $start > $now ) {
			$out[] = array(
				'timestamp' => $start->getTimestamp(),
				'type'      => 'start',
				'datetime'  => $start,
			);
		}

		if ( $end > $now ) {
			$out[] = array(
				'timestamp' => $end->getTimestamp(),
				'type'      => 'end',
				'datetime'  => $end,
			);
		}

		return $out;
	}

	/**
	 * Sort boundaries by timestamp and de-duplicate identical instants.
	 *
	 * @param array $boundaries Boundaries.
	 * @return array
	 */
	private static function sort_boundaries( array $boundaries ) {
		usort(
			$boundaries,
			static function ( $a, $b ) {
				return $a['timestamp'] <=> $b['timestamp'];
			}
		);

		$unique = array();
		$seen   = array();

		foreach ( $boundaries as $boundary ) {
			$key = $boundary['timestamp'] . '|' . $boundary['type'];

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $boundary;
		}

		return $unique;
	}
}
