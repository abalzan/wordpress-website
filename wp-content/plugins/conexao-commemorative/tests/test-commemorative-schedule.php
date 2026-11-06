<?php
/**
 * Commemorative scheduling suite (architecture proposal r10 §9.2).
 *
 * Every case injects the clock: no assertion depends on the real date (§9.1).
 *
 * Proves, with REAL calls into the engine:
 *   - inclusive start/end boundaries in the site timezone;
 *   - fixed-annual, custom lead-in, Gregorian Easter and one-off resolution;
 *   - the one-off rule NEVER searching adjacent years;
 *   - the explicit `window` rule, including the 31 December wrap (New Year
 *     12-26 → 01-01) with ONE stable occurrence year across midnight;
 *   - the r10 occurrence-year rule for every rule type — never
 *     `$now->format('Y')` for a wrapping window;
 *   - upcoming boundaries (what the scheduler arms);
 *   - the next-occurrence window used by the admin list;
 *   - rejection of 02-29 and malformed month-days.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-commemorative/tests/test-commemorative-schedule.php
 *
 * @package Conexao_Commemorative
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$tz  = new DateTimeZone( 'Europe/Dublin' );
$utc = new DateTimeZone( 'UTC' );

/**
 * Build a fixed clock in Europe/Dublin.
 *
 * @param string $value `Y-m-d H:i:s`.
 * @return DateTimeImmutable
 */
function ce_schedule_clock( $value ) {
	return new DateTimeImmutable( $value, new DateTimeZone( 'Europe/Dublin' ) );
}

/**
 * A fixed-annual event configuration.
 *
 * @param string $date Month-day.
 * @param int    $lead Lead-in days.
 * @param string $key  Event key.
 * @return array
 */
function ce_schedule_fixed( $date, $lead, $key = 'fixed_event' ) {
	return array(
		'event_key'    => $key,
		'rule'         => 'fixed_annual',
		'date'         => $date,
		'lead_in_days' => $lead,
	);
}

test_section( 'Fixed annual: inclusive boundaries (Halloween 10-31, lead 15)' );

$halloween = ce_schedule_fixed( '10-31', 15, 'halloween' );

assert_true( null === Conexao_Commemorative_Schedule::resolve( $halloween, ce_schedule_clock( '2026-10-15 23:59:59' ), $tz ), 'Oct 15 23:59:59 is before the window' );

$start_boundary = Conexao_Commemorative_Schedule::resolve( $halloween, ce_schedule_clock( '2026-10-16 00:00:00' ), $tz );
assert_true( is_array( $start_boundary ), 'Oct 16 00:00:00 is inside the window (start inclusive)' );
assert_equals( '2026-10-16 00:00:00', $start_boundary['start']->format( 'Y-m-d H:i:s' ), 'the start is 15 days before the occurrence at 00:00:00' );
assert_equals( '2026-10-31 23:59:59', $start_boundary['end']->format( 'Y-m-d H:i:s' ), 'the end is the occurrence date at 23:59:59' );
assert_equals( 2026, $start_boundary['occurrence_year'], 'occurrence year is the occurrence calendar year' );

$end_boundary = Conexao_Commemorative_Schedule::resolve( $halloween, ce_schedule_clock( '2026-10-31 23:59:59' ), $tz );
assert_true( is_array( $end_boundary ), 'Oct 31 23:59:59 is inside the window (end inclusive)' );
assert_true( null === Conexao_Commemorative_Schedule::resolve( $halloween, ce_schedule_clock( '2026-11-01 00:00:00' ), $tz ), 'Nov 1 00:00:00 is outside the window' );

test_section( 'Fixed annual: custom lead-in (30 days)' );

$custom_lead = ce_schedule_fixed( '10-31', 30, 'halloween_custom' );

assert_true( null === Conexao_Commemorative_Schedule::resolve( $custom_lead, ce_schedule_clock( '2026-09-30 23:59:59' ), $tz ), 'Sep 30 23:59:59 is before a 30-day lead-in window' );
assert_true( is_array( Conexao_Commemorative_Schedule::resolve( $custom_lead, ce_schedule_clock( '2026-10-01 00:00:00' ), $tz ) ), 'Oct 1 00:00:00 starts a 30-day lead-in window' );

test_section( 'Variable annual: Gregorian Easter (lead 15)' );

$easter_date = Conexao_Commemorative_Schedule::easter_sunday( 2026, $tz );
assert_true( $easter_date instanceof DateTimeImmutable, 'Easter 2026 resolves to a date' );
assert_equals( '2026-04-05', $easter_date->format( 'Y-m-d' ), 'Easter Sunday 2026 is 2026-04-05' );

$easter = array(
	'event_key'    => 'easter',
	'rule'         => 'variable_annual',
	'variable'     => 'easter',
	'lead_in_days' => 15,
);

$easter_window = Conexao_Commemorative_Schedule::resolve( $easter, ce_schedule_clock( '2026-04-05 12:00:00' ), $tz );
assert_true( is_array( $easter_window ), 'Easter Sunday is inside its own window' );
assert_equals( '2026-03-21', $easter_window['start']->format( 'Y-m-d' ), 'the Easter window starts 15 days before Easter Sunday' );
assert_true( is_array( Conexao_Commemorative_Schedule::resolve( $easter, ce_schedule_clock( '2026-03-21 00:00:00' ), $tz ) ), 'the window start day is inclusive' );
assert_true( null === Conexao_Commemorative_Schedule::resolve( $easter, ce_schedule_clock( '2026-03-20 23:59:59' ), $tz ), 'the day before the window start is excluded' );

test_section( 'One-off: the exact stored date only' );

$one_off = array(
	'event_key'    => 'anniversary',
	'rule'         => 'one_off',
	'one_off_date' => '2027-07-04',
	'lead_in_days' => 2,
);

assert_true( is_array( Conexao_Commemorative_Schedule::resolve( $one_off, ce_schedule_clock( '2027-07-02 00:00:00' ), $tz ) ), 'the one-off window starts on the lead-in day' );
assert_true( is_array( Conexao_Commemorative_Schedule::resolve( $one_off, ce_schedule_clock( '2027-07-04 23:59:59' ), $tz ) ), 'the one-off date itself is inside the window' );
assert_true( null === Conexao_Commemorative_Schedule::resolve( $one_off, ce_schedule_clock( '2027-07-05 00:00:00' ), $tz ), 'the day after a one-off is outside the window' );
assert_true( null === Conexao_Commemorative_Schedule::resolve( $one_off, ce_schedule_clock( '2026-07-04 12:00:00' ), $tz ), 'a one-off NEVER resolves in the previous year' );
assert_true( null === Conexao_Commemorative_Schedule::resolve( $one_off, ce_schedule_clock( '2028-07-04 12:00:00' ), $tz ), 'a one-off NEVER resolves in the following year' );

test_section( 'New Year: explicit seven-day window 12-26 → 01-01' );

$new_year = array(
	'event_key'    => 'new_year',
	'rule'         => 'window',
	'window_start' => '12-26',
	'window_end'   => '01-01',
	'lead_in_days' => 0,
);

$new_year_cases = array(
	'2026-12-25 12:00:00' => false,
	'2026-12-26 00:00:00' => true,
	'2026-12-31 23:59:00' => true,
	'2027-01-01 00:00:00' => true,
	'2027-01-01 23:59:59' => true,
	'2027-01-02 00:00:00' => false,
);

foreach ( $new_year_cases as $instant => $expected ) {
	$resolved = Conexao_Commemorative_Schedule::resolve( $new_year, ce_schedule_clock( $instant ), $tz );
	assert_equals( $expected, is_array( $resolved ), sprintf( 'New Year at %s is %s', $instant, $expected ? 'inside' : 'outside' ) . ' the window' );
}

test_section( 'r10 occurrence year: the wrapping window belongs to the year it ENDS in' );

foreach ( array( '2026-12-26 00:00:00', '2026-12-31 23:59:59', '2027-01-01 00:00:00', '2027-01-01 23:59:59' ) as $instant ) {
	$resolved = Conexao_Commemorative_Schedule::resolve( $new_year, ce_schedule_clock( $instant ), $tz );
	assert_set( is_array( $resolved ) ? $resolved : array(), 'occurrence_year', 'New Year resolves an occurrence year at ' . $instant );
	assert_equals( 2027, $resolved['occurrence_year'], 'New Year occurrence year stays 2027 at ' . $instant . ' (including after midnight on 1 January)' );
}

$previous_new_year = Conexao_Commemorative_Schedule::resolve( $new_year, ce_schedule_clock( '2025-12-31 12:00:00' ), $tz );
assert_equals( 2026, $previous_new_year['occurrence_year'], 'the 2025-12-26 → 2026-01-01 window is occurrence year 2026' );

test_section( 'Non-wrapping window: occurrence year is the window year' );

$st_patricks_week = array(
	'event_key'    => 'st_patricks_week',
	'rule'         => 'window',
	'window_start' => '03-10',
	'window_end'   => '03-20',
	'lead_in_days' => 0,
);

$st_patricks_window = Conexao_Commemorative_Schedule::resolve( $st_patricks_week, ce_schedule_clock( '2027-03-17 12:00:00' ), $tz );
assert_true( is_array( $st_patricks_window ), 'a non-wrapping window resolves inside its dates' );
assert_equals( 2027, $st_patricks_window['occurrence_year'], 'a non-wrapping window uses its own year' );

test_section( 'Timezone: the same instant resolves the same local day' );

// 2026-10-15 23:30 UTC is 2026-10-16 00:30 in Europe/Dublin (IST, +01:00).
$utc_instant   = new DateTimeImmutable( '2026-10-15 23:30:00', $utc );
$dublin_window = Conexao_Commemorative_Schedule::resolve( $halloween, $utc_instant, $tz );
assert_true( is_array( $dublin_window ), 'a UTC instant is evaluated in the site timezone (inside the Dublin day)' );
assert_equals( '2026-10-16', $dublin_window['start']->format( 'Y-m-d' ), 'the resolved start is the Dublin calendar day' );

test_section( 'Upcoming boundaries: what the scheduler arms' );

$boundaries = Conexao_Commemorative_Schedule::upcoming_boundaries( $new_year, ce_schedule_clock( '2026-12-01 12:00:00' ), $tz );
assert_true( ! empty( $boundaries ), 'the New Year event exposes upcoming boundaries' );
assert_equals( 'start', $boundaries[0]['type'], 'the first upcoming boundary is the window start' );
assert_equals( '2026-12-26', ( new DateTimeImmutable( '@' . $boundaries[0]['timestamp'] ) )->setTimezone( $tz )->format( 'Y-m-d' ), 'the first boundary is 26 December' );
assert_equals( 'end', $boundaries[1]['type'], 'the next boundary is the window end' );

$halloween_boundaries = Conexao_Commemorative_Schedule::upcoming_boundaries( $halloween, ce_schedule_clock( '2026-10-20 12:00:00' ), $tz );
assert_true( ! empty( $halloween_boundaries ), 'an in-window event still exposes its end boundary' );
assert_equals( 'end', $halloween_boundaries[0]['type'], 'inside a window the next boundary is the end' );

test_section( 'Next window: the admin list shows the NEXT occurrence' );

$next_new_year = Conexao_Commemorative_Schedule::next_window( $new_year, ce_schedule_clock( '2027-06-01 12:00:00' ), $tz );
assert_true( is_array( $next_new_year ), 'the next New Year window resolves from mid-year' );
assert_equals( '2027-12-26', $next_new_year['start']->format( 'Y-m-d' ), 'the next New Year window starts on 26 December 2027' );
assert_equals( '2028-01-01', $next_new_year['end']->format( 'Y-m-d' ), 'the next New Year window ends on 1 January 2028' );
assert_equals( 2028, $next_new_year['occurrence_year'], 'the next window reports the year it ends in (2028)' );
assert_equals( false, $next_new_year['active'], 'a future window is not marked active' );

$active_new_year = Conexao_Commemorative_Schedule::next_window( $new_year, ce_schedule_clock( '2027-01-01 12:00:00' ), $tz );
assert_equals( true, $active_new_year['active'], 'a window containing the clock is marked active' );

test_section( 'Validation: impossible and malformed month-days are rejected' );

assert_true( null === Conexao_Commemorative_Schedule::month_day( '02-29' ), '02-29 is rejected (only exists in leap years)' );
assert_true( null === Conexao_Commemorative_Schedule::month_day( '13-01' ), 'month 13 is rejected' );
assert_true( null === Conexao_Commemorative_Schedule::month_day( '04-31' ), 'an impossible month-day is rejected' );
assert_true( null === Conexao_Commemorative_Schedule::month_day( '10/31' ), 'a malformed value is rejected' );
assert_true( null === Conexao_Commemorative_Schedule::resolve( ce_schedule_fixed( '02-29', 15 ), ce_schedule_clock( '2028-02-29 12:00:00' ), $tz ), 'a 02-29 rule never resolves, even in a leap year' );

test_finish( 'commemorative-schedule' );


