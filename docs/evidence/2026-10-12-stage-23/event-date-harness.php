<?php
/**
 * Stage 23 investigation harness — the event date layer WITHOUT WordPress.
 *
 * WHY THIS EXISTS
 * ---------------
 * The reported symptom ("/eventos/ keeps listing events that should no longer be
 * classified as happening today") had to be attributed to a layer before any fix
 * was chosen. The in-process suites need a real WordPress, so this harness
 * exercises the REAL production classes (`Conexao_Event_Query`,
 * `Conexao_Event_Recurrence`) against a REAL SQL engine (SQLite, so the plugin's
 * own SQL text runs unmodified) with a CONTROLLED clock
 * (`upcoming_events( $from )`), and asserts the date-layer invariants day by day.
 *
 * It is evidence, not a shipped test: it needs no WordPress, so it is not part of
 * `./scripts/run-tests.sh` (whose in-process layer requires one). The permanent
 * regression coverage for this area lives in the plugin suites
 * (`conexao-event-runtime/tests/test-event-date-transitions.php` and friends).
 *
 * USAGE (any PHP >= 8.0 with pdo_sqlite):
 *   php docs/evidence/2026-10-12-stage-23/event-date-harness.php [repo-root]
 *
 * Exit code 0 = every invariant held, 1 = a violation (printed as FAIL).
 */

$repo_root = $argv[1] ?? getenv( 'CONEXAO_REPO_ROOT' ) ?: dirname( __DIR__, 3 );

require_once __DIR__ . '/harness-wp-stubs.php';

$passed = 0;
$failed = 0;

/**
 * Record one harness assertion.
 *
 * @param bool   $condition Whether the invariant held.
 * @param string $message   Assertion label.
 * @param string $detail    Optional failure context.
 * @return bool
 */
function check( $condition, $message, $detail = '' ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: $message\n";
		return true;
	}
	$failed++;
	echo "  FAIL: $message" . ( '' !== $detail ? " — $detail" : '' ) . "\n";
	return false;
}

/**
 * Print a harness section header.
 *
 * @param string $title Section title.
 * @return void
 */
function section( $title ) {
	echo "\n=== $title ===\n";
}

$pdo = new PDO( 'sqlite::memory:' );
$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
$pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT)' );
$pdo->exec( 'CREATE TABLE wp_postmeta (post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
$GLOBALS['wpdb'] = new Harness_Wpdb( $pdo );
global $wpdb;

require_once $repo_root . '/wp-content/plugins/conexao-event-runtime/includes/class-event-recurrence.php';
require_once $repo_root . '/wp-content/plugins/conexao-event-runtime/includes/class-event-query.php';

$ANCHOR = '2026-03-11'; // A Wednesday — a fixed anchor, never "now".

/**
 * Y-m-d N days from the anchor, in the site timezone.
 *
 * @param string $anchor Anchor date.
 * @param int    $offset Signed day offset.
 * @return string
 */
function d( $anchor, $offset ) {
	return ( new DateTimeImmutable( $anchor, wp_timezone() ) )->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' day' )->format( 'Y-m-d' );
}

/**
 * ISO weekday number N days from the anchor.
 *
 * @param string $anchor Anchor date.
 * @param int    $offset Signed day offset.
 * @return int
 */
function wd( $anchor, $offset ) {
	return (int) ( new DateTimeImmutable( $anchor, wp_timezone() ) )->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' day' )->format( 'N' );
}

/**
 * Insert a fixture event record.
 *
 * @param int    $id     Post ID.
 * @param array  $meta   Meta key => value.
 * @param string $status post_status.
 * @return void
 */
function mk_event( $id, array $meta, $status = 'publish' ) {
	global $wpdb;
	$wpdb->pdo->prepare( 'INSERT INTO ' . $wpdb->posts . ' (ID, post_type, post_status) VALUES (?,?,?)' )
		->execute( array( $id, 'event', $status ) );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
}

/**
 * Insert a weekly-series fixture.
 *
 * @param int      $id             Post ID.
 * @param string   $anchor         Anchor date.
 * @param int      $start_offset   Series start offset (days).
 * @param int|null $end_offset     Series end offset, or null for open-ended.
 * @param int      $weekday_offset Offset whose weekday the series repeats on.
 * @param array    $extra          Extra meta.
 * @return void
 */
function weekly( $id, $anchor, $start_offset, $end_offset, $weekday_offset = 0, $extra = array() ) {
	mk_event(
		$id,
		array_merge(
			array(
				'_event_date'             => d( $anchor, $start_offset ),
				'_event_recurrence'       => 'weekly',
				'_event_recurrence_days'  => (string) wd( $anchor, $weekday_offset ),
				'_event_recurrence_start' => d( $anchor, $start_offset ),
				'_event_recurrence_end'   => ( null === $end_offset ) ? '' : d( $anchor, $end_offset ),
			),
			$extra
		)
	);
}

/**
 * Ordered event IDs the query returns for a simulated "today".
 *
 * @param string $anchor Anchor date.
 * @param int    $offset Offset of the simulated day.
 * @return int[]
 */
function ids_on( $anchor, $offset ) {
	$from = new DateTimeImmutable( d( $anchor, $offset ), wp_timezone() );
	$set  = Conexao_Event_Query::upcoming_events( $from );
	$ids  = array_map( 'intval', array_keys( $set ) );
	sort( $ids );
	return $ids;
}

/**
 * Whether an event is listed on a simulated day.
 *
 * @param string $anchor Anchor date.
 * @param int    $offset Offset of the simulated day.
 * @param int    $id     Event post ID.
 * @return bool
 */
function has( $anchor, $offset, $id ) {
	return in_array( (int) $id, ids_on( $anchor, $offset ), true );
}

/**
 * The next occurrence date the query classifies an event with on a simulated day.
 *
 * @param string $anchor Anchor date.
 * @param int    $offset Offset of the simulated day.
 * @param int    $id     Event post ID.
 * @return string|null
 */
function occ_on( $anchor, $offset, $id ) {
	$from = new DateTimeImmutable( d( $anchor, $offset ), wp_timezone() );
	$set  = Conexao_Event_Query::upcoming_events( $from );
	return isset( $set[ (int) $id ] ) ? $set[ (int) $id ] : null;
}

echo 'Stage 23 event-date harness — repo: ' . $repo_root . ' — tz: ' . wp_timezone()->getName() . ' — anchor: ' . $ANCHOR . "\n";
/* ------------------------------------------------------------------ *
 * 1. The reported symptom, day by day.
 * ------------------------------------------------------------------ */
section( '1. Day transitions (controlled clock, fixture records untouched)' );

mk_event( 101, array( '_event_date' => d( $ANCHOR, 0 ), '_event_end_date' => d( $ANCHOR, 0 ) ) );     // single-day today
mk_event( 102, array( '_event_date' => d( $ANCHOR, -1 ), '_event_end_date' => d( $ANCHOR, -1 ) ) );  // ended yesterday
mk_event( 103, array( '_event_date' => d( $ANCHOR, 1 ), '_event_end_date' => d( $ANCHOR, 1 ) ) );    // tomorrow
mk_event( 104, array( '_event_date' => d( $ANCHOR, -1 ), '_event_end_date' => d( $ANCHOR, 1 ) ) );   // ongoing -1..+1
mk_event( 105, array( '_event_date' => d( $ANCHOR, -3 ), '_event_end_date' => d( $ANCHOR, -1 ) ) );  // long, ended yesterday
mk_event( 116, array( '_event_date' => d( $ANCHOR, 2 ) ) );                                          // future, no end date
mk_event( 117, array( '_event_date' => d( $ANCHOR, 2 ), '_event_end_date' => d( $ANCHOR, 4 ) ) );    // future range

check( has( $ANCHOR, 0, 101 ), 'T1 single-day event dated today appears today' );
check( ! has( $ANCHOR, 1, 101 ), 'T1 the same record does NOT appear the next day' );
check( ! has( $ANCHOR, 0, 102 ), 'T2 event that ended yesterday is excluded today' );
check( ! has( $ANCHOR, 0, 105 ), 'T2 long multi-day event that ended yesterday is excluded today' );
// The archive lists UPCOMING events, so tomorrow's event is legitimately present
// today; what must change at midnight is its CLASSIFICATION, not its membership.
check( has( $ANCHOR, 0, 103 ) && has( $ANCHOR, 1, 103 ), "T3 tomorrow's event is upcoming today and still listed tomorrow" );
check( d( $ANCHOR, 1 ) === occ_on( $ANCHOR, 0, 103 ), "T3 tomorrow's event is classified as tomorrow today" );
check( d( $ANCHOR, 1 ) === occ_on( $ANCHOR, 1, 103 ), "T3 the same record is classified as TODAY on the next day" );
check( null === occ_on( $ANCHOR, 2, 103 ), "T3 it is no longer classified as an upcoming event two days later" );
check( has( $ANCHOR, -1, 104 ) && has( $ANCHOR, 0, 104 ) && has( $ANCHOR, 1, 104 ), 'T4 ongoing multi-day event stays visible every day it runs' );
check( ! has( $ANCHOR, 2, 104 ), 'T4 it disappears the day after its end date' );
check( has( $ANCHOR, 0, 116 ) && has( $ANCHOR, 2, 116 ), 'upcoming event with no end date stays until its own day' );
check( ! has( $ANCHOR, 3, 116 ), 'upcoming event with no end date is gone the day after' );
check( has( $ANCHOR, 1, 117 ) && has( $ANCHOR, 4, 117 ), 'future multi-day range is visible across its span' );
check( ! has( $ANCHOR, 5, 117 ), 'future multi-day range is gone the day after its end date' );

/* ------------------------------------------------------------------ *
 * 2. Status gate and post status.
 * ------------------------------------------------------------------ */
section( '2. Status gate / post status' );

mk_event( 113, array( '_event_date' => d( $ANCHOR, 5 ), '_event_status' => 'expired' ) );
mk_event( 114, array( '_event_date' => d( $ANCHOR, 5 ) ), 'draft' );
mk_event( 115, array( '_event_date' => d( $ANCHOR, 5 ) ) ); // legacy: no status meta
check( ! has( $ANCHOR, 0, 113 ), 'status=expired is hidden regardless of its date' );
check( ! has( $ANCHOR, 0, 114 ), 'a draft record is hidden' );
check( has( $ANCHOR, 0, 115 ), 'a legacy record with no status meta stays visible' );

/* ------------------------------------------------------------------ *
 * 3. Weekly series windows.
 * ------------------------------------------------------------------ */
section( '3. Weekly series: candidate window' );

weekly( 106, $ANCHOR, -14, 21 );    // active, well beyond the window
weekly( 107, $ANCHOR, -14, 3 );     // ends inside the 7-day window
weekly( 108, $ANCHOR, -14, 0 );     // ends TODAY
weekly( 109, $ANCHOR, 3, 30 );      // starts inside the window
weekly( 110, $ANCHOR, 10, 40 );     // starts beyond the window
weekly( 111, $ANCHOR, -14, null );  // open-ended
weekly( 112, $ANCHOR, -28, -7 );    // ended
mk_event( 118, array( '_event_date' => d( $ANCHOR, -14 ), '_event_recurrence' => 'weekly', '_event_recurrence_days' => (string) wd( $ANCHOR, 0 ), '_event_recurrence_start' => d( $ANCHOR, -14 ) ) );

check( has( $ANCHOR, 0, 106 ), 'active series (end beyond the window) is listed' );
check( has( $ANCHOR, 0, 111 ), 'open-ended series is listed' );
check( ! has( $ANCHOR, 0, 110 ), 'series starting beyond the window is not listed yet' );
check( ! has( $ANCHOR, 0, 112 ), 'series that has ended is not listed' );
check( has( $ANCHOR, 0, 107 ), 'series ENDING INSIDE the window is listed (Stage 23 boundary fix)' );
check( has( $ANCHOR, 0, 108 ), 'series ending TODAY is listed on its occurrence day' );
check( ! has( $ANCHOR, 4, 108 ), 'series that ended is gone afterwards' );
check( has( $ANCHOR, 3, 109 ), 'series starting inside the window becomes visible' );
check( has( $ANCHOR, 0, 118 ), 'weekly rule with no end meta (open-ended) is listed' );

/* ------------------------------------------------------------------ *
 * 4. Timezone boundary (UTC vs site-local) and DST.
 * ------------------------------------------------------------------ */
section( '4. Timezone boundaries' );

$utc_midnight = new DateTimeImmutable( '2026-06-30 23:30:00', new DateTimeZone( 'UTC' ) );
$dublin       = $utc_midnight->setTimezone( wp_timezone() );
check( '2026-07-01' === $dublin->format( 'Y-m-d' ), 'the same instant is a different calendar date in UTC and Europe/Dublin', 'utc=2026-06-30 dublin=' . $dublin->format( 'Y-m-d' ) );
mk_event( 120, array( '_event_date' => '2026-07-01' ) );
check( has( '2026-07-01', 0, 120 ), 'a date-only event is "today" on its site-local date' );
check( Conexao_Event_Recurrence::occurs_on_date( 120, $utc_midnight ), "the UTC instant 2026-06-30T23:30Z is that event's day in the site timezone" );
check( ! Conexao_Event_Recurrence::occurs_on_date( 120, new DateTimeImmutable( "2026-06-30 12:00:00", new DateTimeZone( "UTC" ) ) ), "the same UTC date at midday is NOT that event's day (UTC and local are not mixed)" );
check( ! has( '2026-07-02', 0, 120 ), 'it is not "today" the day after' );

$before_dst = new DateTimeImmutable( '2026-10-24', wp_timezone() );
$after_dst  = $before_dst->modify( '+1 day' );
check( '2026-10-25' === $after_dst->format( 'Y-m-d' ), 'the local day after 24 Oct 2026 is 25 Oct (DST end)' );
/* ------------------------------------------------------------------ *
 * 5. The cache layer: day-keyed, midnight-bounded, language-scoped.
 * ------------------------------------------------------------------ */
section( '5. Cache layer' );

$today_key     = 'conexao_event_upcoming_' . Conexao_Event_Query::today()->format( 'Ymd' ) . '_pt';
$yesterday_key = 'conexao_event_upcoming_' . Conexao_Event_Query::today()->modify( '-1 day' )->format( 'Ymd' ) . '_pt';
$tomorrow_key  = 'conexao_event_upcoming_' . Conexao_Event_Query::today()->modify( '+1 day' )->format( 'Ymd' ) . '_pt';

Conexao_Event_Query::flush_cache();
$cold = Conexao_Event_Query::upcoming_events();
check( isset( $GLOBALS['HARNESS_TRANSIENTS'][ $today_key ] ), 'the transient key carries the site-local date and language', $today_key );
$ttl         = $GLOBALS['HARNESS_TRANSIENTS'][ $today_key ]['expires'] - time();
$to_midnight = Conexao_Event_Query::today()->modify( '+1 day' )->getTimestamp() - time();
check( abs( $ttl - $to_midnight ) <= 2, 'the TTL ends at the next local midnight', "ttl=$ttl midnight=$to_midnight" );

$GLOBALS['HARNESS_TRANSIENTS'][ $yesterday_key ] = array( 'value' => array( 999 => 'stale' ), 'expires' => time() + 86400 );
$with_stale = Conexao_Event_Query::upcoming_events();
check( ! isset( $with_stale[999] ), 'T5/T6 a stale previous-day entry is never served to a later day' );
check( $with_stale === $cold, 'the cached read equals the uncached evaluation for the same day' );

$GLOBALS['HARNESS_TRANSIENTS'] = array(
	$yesterday_key        => array( 'value' => array(), 'expires' => null ),
	$today_key            => array( 'value' => array(), 'expires' => null ),
	$tomorrow_key         => array( 'value' => array(), 'expires' => null ),
	'conexao_event_upcoming_' . Conexao_Event_Query::today()->format( 'Ymd' ) . '_en' => array( 'value' => array(), 'expires' => null ),
	'unrelated_transient' => array( 'value' => array(), 'expires' => null ),
);
Conexao_Event_Query::flush_cache();
check( array_keys( $GLOBALS['HARNESS_TRANSIENTS'] ) === array( 'unrelated_transient' ), 'flush_cache() clears yesterday/today/tomorrow for every language and nothing else', implode( ',', array_keys( $GLOBALS['HARNESS_TRANSIENTS'] ) ) );

$before = count( $GLOBALS['HARNESS_TRANSIENTS'] );
Conexao_Event_Query::upcoming_events( new DateTimeImmutable( d( $ANCHOR, 1 ), wp_timezone() ) );
check( count( $GLOBALS['HARNESS_TRANSIENTS'] ) === $before, 'the explicit-clock evaluation writes no cache entry' );

/* ------------------------------------------------------------------ *
 * 6. Deterministic day-by-day scan for the record.
 * ------------------------------------------------------------------ */
section( '6. Day-by-day scan (anchor -3 .. +32)' );
for ( $i = -3; $i <= 32; $i++ ) {
	printf( "  today=%s  %s\n", d( $ANCHOR, $i ), implode( ' ', ids_on( $ANCHOR, $i ) ) );
}

echo "\n" . $passed . ' passed, ' . $failed . " failed\n";
exit( $failed > 0 ? 1 : 0 );

check( '00' === $before_dst->format( 'H' ) && '00' === $before_dst->format( 'i' ), 'a local "today" is always local midnight' );

