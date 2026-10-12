<?php
/**
 * Regression test — event schedule refresh on re-import (Stage 23).
 *
 * THE DEFECT THIS SUITE LOCKS DOWN
 * --------------------------------
 * `upsert_event()`'s change detection compared the event's START fields
 * (`_event_date`, `_event_start_time`) but not its END fields
 * (`_event_end_date`, `_event_end_time`), and the "unchanged" fast path returns
 * before `save_event_meta()`. A source that corrected or shortened an event's
 * end date was therefore classified `unchanged` and the OLD end date stayed on
 * the record — so `/eventos/` kept listing an event (with a "Hoje" chip) after
 * it had really ended, and `Conexao_Event_Status::get_event_end_timestamp()`
 * used the same stale timestamp.
 *
 * WHAT IS ASSERTED
 * ----------------
 *  - An end-date-only source change is applied to the EXISTING record
 *    (`updated`, same post ID, new `_event_end_date`).
 *  - The runtime consequence follows: the record stops being an upcoming event
 *    the day after its refreshed end date.
 *  - A re-import of identical data is `unchanged` (idempotence preserved).
 *  - The start-date comparison still behaves exactly as before.
 *
 * The suite drives the production `upsert_event()` path through a thin subclass
 * (it is `protected`), so no fixture source, no network and no import run is
 * needed. It creates one event post and deletes it again; no other data is
 * written.
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_title( 'Event schedule refresh on re-import (Stage 23)' );

test_require(
	class_exists( 'Conexao_Event_Importer_Engine' ) && class_exists( 'Conexao_Event_Query' ) && class_exists( 'Conexao_Event_Recurrence' ),
	'activate-conexao-event-runtime-and-importer',
	'the event runtime and the event importer classes are loaded',
	'docker compose exec wordpress wp --allow-root plugin activate conexao-data-model conexao-event-runtime conexao-event-importer'
);

/**
 * Thin subclass exposing the protected upsert path to the suite.
 *
 * Only visibility is widened; no production behaviour is overridden.
 */
class Conexao_Stage23_Engine extends Conexao_Event_Importer_Engine {

	/**
	 * Run the production upsert for a normalized event.
	 *
	 * @param array $normalized  Normalized event data.
	 * @param int   $existing_id Existing record the importer resolved, or 0.
	 * @return array Upsert outcome (`action`, `post_id`).
	 */
	public function stage23_upsert( array $normalized, $existing_id = 0 ) {
		return $this->upsert_event( $normalized, $existing_id );
	}
}

/**
 * Site-local Y-m-d N days from today.
 *
 * @param int $offset Signed day offset.
 * @return string
 */
function stage23_day( $offset ) {
	return Conexao_Event_Query::today()->modify( ( $offset >= 0 ? '+' : '' ) . $offset . ' day' )->format( 'Y-m-d' );
}

/**
 * A complete normalized event payload in the shape the normalizer produces.
 *
 * @param string $source_id  Stable source identity.
 * @param int    $start      Start day offset.
 * @param int    $end        End day offset.
 * @return array
 */
function stage23_payload( $source_id, $start, $end ) {
	return array(
		'title'            => '[STAGE23 SCHEDULE] multi-day event',
		'description'      => 'Synthetic record created by the Stage 23 schedule-refresh suite.',
		'start_date'       => stage23_day( $start ),
		'start_time'       => '10:00',
		'end_date'         => stage23_day( $end ),
		'end_time'         => '18:00',
		'event_time'       => '10:00 — 18:00',
		'county'           => '',
		'town'             => '',
		'venue'            => '',
		'address'          => '',
		'address_status'   => 'missing',
		'event_location'   => '',
		'banner'           => '',
		'category'         => '',
		'organizer'        => '',
		'price'            => '',
		'source'           => 'stage23_refresh_test',
		'source_id'        => $source_id,
		'source_url'       => 'https://example.invalid/stage23/' . $source_id,
		'source_language'  => '',
		'recurrence'       => array(),
		'validation_errors' => array(),
	);
}

/**
 * Whether the runtime classifies an event as upcoming on a simulated day.
 *
 * @param int $event_id Event post ID.
 * @param int $offset   Day offset used as "today".
 * @return bool
 */
function stage23_is_upcoming_on( $event_id, $offset ) {
	$from = new DateTimeImmutable( stage23_day( $offset ), wp_timezone() );
	$set  = Conexao_Event_Query::upcoming_events( $from );

	return isset( $set[ (int) $event_id ] );
}

$stage23_engine    = new Conexao_Stage23_Engine( new Conexao_Event_Sources(), new Conexao_Event_Location() );
$stage23_source_id = 'stage23-' . substr( md5( uniqid( 'stage23', true ) ), 0, 10 );
$stage23_post_id   = 0;

Conexao_Event_Query::flush_cache();
// ---------------------------------------------------------------------------
// 1. First import: a multi-day event, tomorrow .. +3
// ---------------------------------------------------------------------------
test_section( 'First import (start +1, end +3)' );

$stage23_first = $stage23_engine->stage23_upsert( stage23_payload( $stage23_source_id, 1, 3 ) );

assert_equals( 'created', $stage23_first['action'], 'the first import creates the record' );
$stage23_post_id = (int) $stage23_first['post_id'];
assert_true( $stage23_post_id > 0, 'the created record has a post ID' );
assert_equals( stage23_day( 1 ), get_post_meta( $stage23_post_id, '_event_date', true ), 'the stored start date is the source start date' );
assert_equals( stage23_day( 3 ), get_post_meta( $stage23_post_id, '_event_end_date', true ), 'the stored end date is the source end date' );
assert_equals( '18:00', get_post_meta( $stage23_post_id, '_event_end_time', true ), 'the stored end time is the source end time' );
assert_true( stage23_is_upcoming_on( $stage23_post_id, 2 ), 'the record is an upcoming event while it is running (day +2)' );
assert_true( stage23_is_upcoming_on( $stage23_post_id, 3 ), 'the record is an upcoming event on its last day (day +3)' );

// ---------------------------------------------------------------------------
// 2. The source SHORTENS the range: only the end date/time change
// ---------------------------------------------------------------------------
test_section( 'Re-import after an end-date-only source change' );

$stage23_second = $stage23_engine->stage23_upsert( stage23_payload( $stage23_source_id, 1, 1 ), $stage23_post_id );

assert_equals( 'updated', $stage23_second['action'], 'an end-date-only change is detected as an update (not "unchanged")' );
assert_equals( $stage23_post_id, (int) $stage23_second['post_id'], 'the update writes the EXISTING record (identity preserved, no duplicate)' );
assert_equals( stage23_day( 1 ), get_post_meta( $stage23_post_id, '_event_end_date', true ), 'the stored end date is refreshed to the shortened source range' );

// The runtime consequence — the reason this defect mattered.
assert_true( stage23_is_upcoming_on( $stage23_post_id, 1 ), 'the record is still an upcoming event on its (now only) day' );
assert_true( ! stage23_is_upcoming_on( $stage23_post_id, 2 ), 'the record is NO LONGER an upcoming event the day after its refreshed end date' );
assert_true( ! stage23_is_upcoming_on( $stage23_post_id, 3 ), 'the record is NO LONGER an upcoming event on the stale end date' );

// ---------------------------------------------------------------------------
// 3. Idempotence: identical data must not churn or duplicate
// ---------------------------------------------------------------------------
test_section( 'Idempotence' );

$stage23_third = $stage23_engine->stage23_upsert( stage23_payload( $stage23_source_id, 1, 1 ), $stage23_post_id );

assert_equals( 'unchanged', $stage23_third['action'], 're-importing identical data reports "unchanged"' );
assert_equals( stage23_day( 1 ), get_post_meta( $stage23_post_id, '_event_end_date', true ), 'the unchanged fast path leaves the end date untouched' );

$stage23_matches = get_posts(
	array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			'relation' => 'AND',
			array(
				'key'   => '_event_source',
				'value' => 'stage23_refresh_test',
			),
			array(
				'key'   => '_event_source_id',
				'value' => $stage23_source_id,
			),
		),
	)
);
assert_equals( 1, count( $stage23_matches ), 'the source identity still resolves to exactly one record (no duplicate created)' );

// ---------------------------------------------------------------------------
// 4. The start-date comparison is unchanged by this fix
// ---------------------------------------------------------------------------
test_section( 'Start-date change (unchanged behaviour)' );

$stage23_fourth = $stage23_engine->stage23_upsert( stage23_payload( $stage23_source_id, 2, 2 ), $stage23_post_id );

assert_equals( 'updated', $stage23_fourth['action'], 'a start-date change is still detected as an update' );
assert_equals( stage23_day( 2 ), get_post_meta( $stage23_post_id, '_event_date', true ), 'the stored start date follows the source' );
assert_equals( stage23_day( 2 ), get_post_meta( $stage23_post_id, '_event_end_date', true ), 'the stored end date follows the source with it' );

// ---------------------------------------------------------------------------
// Cleanup — the suite removes exactly what it created.
// ---------------------------------------------------------------------------
foreach ( $stage23_matches as $stage23_stale_id ) {
	wp_delete_post( (int) $stage23_stale_id, true );
}
if ( $stage23_post_id ) {
	wp_delete_post( $stage23_post_id, true );
}
Conexao_Event_Query::flush_cache();

assert_true( ! get_post( $stage23_post_id ), 'the suite deleted its own fixture record' );

test_finish( 'event schedule refresh' );

