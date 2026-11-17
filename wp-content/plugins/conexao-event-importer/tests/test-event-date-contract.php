<?php
/**
 * Stage 4 — the date-only write contract for `_event_date` / `_event_end_date`.
 *
 * ## What this locks down
 *
 * `_event_date` and `_event_end_date` are contractually date-only `Y-m-d`
 * values. Stage 3.5 found 20 CI-fixture rows carrying a full `Y-m-d H:i:s`
 * datetime because the fixture seeder wrote one directly. Stage 4 fixes the
 * seeder and adds a defensive write-path guard so a datetime-shaped value can
 * never be silently persisted again.
 *
 * This suite proves the guard's pure contract (`Conexao_Event_Date_Contract`)
 * and the seeder's date-only output at the fixture-writing boundary:
 *
 *   - a valid `Y-m-d` value passes through unchanged;
 *   - a valid datetime-shaped value (`Y-m-d H:i:s` or `Y-m-dTH:i:s`) is
 *     deterministically reduced to its leading `Y-m-d` segment;
 *   - a malformed / ambiguous value returns an empty string (never a datetime);
 *   - the seeder's `_event_date` expression yields a date-only value.
 *
 * No database write and no network request is performed: the contract is a
 * pure string function, and the seeder check evaluates the same date
 * expression the seeder uses without bootstrapping WordPress.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

// ---------------------------------------------------------------------------
// 1. The pure date-only contract.
// ---------------------------------------------------------------------------

test_section( '1: valid Y-m-d passes through unchanged' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13' ), 'plain Y-m-d unchanged' );
assert_equals( '2026-01-01', Conexao_Event_Date_Contract::date_only( '2026-01-01' ), 'Y-m-d at year boundary unchanged' );

test_section( '2: datetime-shaped values reduce to the leading date' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13 00:00:00' ), 'Y-m-d H:i:s reduced to date' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13 06:00:00' ), 'Y-m-d H:i:s with non-midnight time reduced' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13T00:00:00' ), 'ISO 8601 T separator reduced' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13 19:00' ), 'Y-m-d H:i reduced' );

test_section( '2b: offset-bearing timestamps reduce to the leading local date (no UTC conversion)' );
// The contract is a pure string operation on the leading date segment. It
// performs NO timezone conversion: an offset-bearing timestamp keeps its
// local calendar date. This is the conservative default per the Stage 4
// correction task §6.2: preserve the source's event-local calendar date when
// the offset is present, rather than converting to UTC (which would shift
// the date across a calendar-day boundary for late/early UTC times).
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13T23:59:59+0100' ), 'positive offset, late UTC day: local date preserved' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13T00:30:00+0100' ), 'positive offset, early UTC day: local date preserved' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13T00:30:00-0500' ), 'negative offset: local date preserved' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13T23:59:59Z' ), 'UTC Z suffix: local date preserved' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13T23:59:59.123456Z' ), 'fractional seconds: local date preserved' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13T19:00:00+01:00' ), 'ISO 8601 with colon offset: local date preserved' );

test_section( '2c: leap day and invalid calendar dates' );
assert_equals( '2028-02-29', Conexao_Event_Date_Contract::date_only( '2028-02-29' ), 'valid leap day (2028 is a leap year)' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '2026-02-29' ), 'invalid leap day (2026 is not a leap year)' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '2026-02-30' ), 'impossible date (Feb 30) rejected' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '2026-04-31' ), 'impossible date (Apr 31) rejected' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '2026-00-15' ), 'month 00 rejected' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '2026-13-15' ), 'month 13 rejected' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '2026-01-00' ), 'day 00 rejected' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '2026-01-32' ), 'day 32 rejected' );

test_section( '2d: malformed datetime suffixes and trailing junk' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '2026-11-13T' ), 'trailing T with no time rejected' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13  ' ), 'trailing whitespace trimmed, date extracted' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '2026-11-13junk' ), 'trailing junk (no time separator) rejected' );
// The datetime pattern matches the leading `Y-m-d HH:MM` and ignores any
// trailing suffix after the time component. This is the documented contract:
// the leading date segment is extracted deterministically.
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13T19:00junk' ), 'trailing junk after time: leading date extracted' );
assert_equals( '2026-11-13', Conexao_Event_Date_Contract::date_only( '2026-11-13 19:00:00junk' ), 'trailing junk after seconds: leading date extracted' );

test_section( '3: malformed / ambiguous values return empty (never a datetime)' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '' ), 'empty string stays empty' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '   ' ), 'whitespace-only stays empty' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( 'not-a-date' ), 'garbage rejected' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '13/11/2026' ), 'non-ISO d/m/Y rejected (no datetime emitted)' );
assert_equals( '', Conexao_Event_Date_Contract::date_only( '2026-13-40' ), 'impossible Y-m-d rejected' );

test_section( '4: is_date_only validates real calendar dates' );
assert_true( Conexao_Event_Date_Contract::is_date_only( '2026-11-13' ), 'valid date accepted' );
assert_true( ! Conexao_Event_Date_Contract::is_date_only( '2026-11-13 00:00:00' ), 'datetime rejected' );
assert_true( ! Conexao_Event_Date_Contract::is_date_only( '2026-02-30' ), 'impossible date rejected' );

// ---------------------------------------------------------------------------
// 5. The seeder's `_event_date` expression is date-only.
// ---------------------------------------------------------------------------

test_section( '5: seeder _event_date expression yields date-only' );
// Reproduce the exact expression the seeder now uses: a full datetime for
// `post_date`, reduced to its date-only prefix for `_event_date`.
$seed_date       = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'Y-m-d' ) . ' +0 days' ) );
$seed_event_date = gmdate( 'Y-m-d', strtotime( $seed_date ) );
assert_true( (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $seed_event_date ), '_event_date is date-only Y-m-d' );
assert_true( Conexao_Event_Date_Contract::is_date_only( $seed_event_date ), '_event_date is a real calendar date' );
assert_true( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $seed_event_date ), '_event_date carries no time component' );

// The seeder source must no longer assign a datetime directly to `_event_date`.
$seeder = (string) file_get_contents( dirname( __DIR__, 4 ) . '/scripts/bootstrap-ci-fixtures.php' );
assert_true(
	1 === preg_match( '/\$pt_meta\[\'_event_date\'\]\s*=\s*gmdate\(\s*\'Y-m-d\'\s*,\s*strtotime\(\s*\$date\s*\)\s*\)/', $seeder ),
	'seeder assigns _event_date via gmdate(Y-m-d, ...) not a raw datetime'
);

test_finish( 'date-contract' );

exit( $failed > 0 ? 1 : 0 );