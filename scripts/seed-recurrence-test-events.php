<?php
/**
 * Seed a safe local test set for the recurring-events query integration.
 *
 * Creates (or removes) the 9 events required by the Step 3 test matrix, all
 * prefixed "[REC-TEST]" so they are easy to find in wp-admin and easy to
 * clean up. Weekdays are computed relative to the CURRENT site-local day,
 * so the set is always verifiable regardless of when it is seeded.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/scripts/seed-recurrence-test-events.php           # seed
 *   docker compose exec wordpress php /var/www/html/scripts/seed-recurrence-test-events.php cleanup   # remove
 */

$wp_load = dirname( __DIR__ ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

const REC_TEST_PREFIX = '[REC-TEST] ';

function rec_test_day( $days ) {
	return Conexao_Event_Query::today()->modify( ( $days >= 0 ? '+' : '' ) . $days . ' day' )->format( 'Y-m-d' );
}

function rec_test_weekday( $days ) {
	return (int) Conexao_Event_Query::today()->modify( ( $days >= 0 ? '+' : '' ) . $days . ' day' )->format( 'N' );
}

function rec_test_weekday_name( $days ) {
	$names = array( 1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
	return $names[ rec_test_weekday( $days ) ];
}

function rec_test_find_all() {
	return get_posts( array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => 100,
		's'              => REC_TEST_PREFIX,
		'fields'         => 'ids',
	) );
}

function rec_test_create( $title, $meta ) {
	$post_id = wp_insert_post( array(
		'post_type'    => 'event',
		'post_title'   => REC_TEST_PREFIX . $title,
		'post_status'  => 'publish',
		'post_content' => 'Local recurring-events test event — safe to delete.',
	), true );
	if ( is_wp_error( $post_id ) ) {
		echo "  ERROR creating {$title}: {$post_id->get_error_message()}\n";
		return null;
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	return $post_id;
}

if ( isset( $argv[1] ) && 'cleanup' === $argv[1] ) {
	$ids = rec_test_find_all();
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
		echo "Deleted {$id}\n";
	}
	Conexao_Event_Query::flush_cache();
	echo 'Removed ' . count( $ids ) . " [REC-TEST] events and flushed the recurrence cache.\n";
	exit( 0 );
}

$events = array(
	'1. One-time future'             => array(
		'_event_date'     => rec_test_day( 12 ),
		'_event_time'     => '19:00',
		'_event_location' => 'Dublin',
	),
	'2. One-time past'               => array(
		'_event_date'     => rec_test_day( -5 ),
		'_event_time'     => '19:00',
		'_event_location' => 'Dublin',
		'_event_status'   => 'published',
	),
	'3. Weekly ' . rec_test_weekday_name( 0 ) . ' active today' => array(
		'_event_date'             => rec_test_day( 0 ),
		'_event_time'             => '18:00',
		'_event_location'         => 'Dublin',
		'_event_recurrence'       => 'weekly',
		'_event_recurrence_days'  => (string) rec_test_weekday( 0 ),
		'_event_recurrence_start' => rec_test_day( 0 ),
		'_event_recurrence_end'   => rec_test_day( 60 ),
	),
	'4. Weekly ' . rec_test_weekday_name( 40 ) . ' not started' => array(
		'_event_date'             => rec_test_day( 40 ),
		'_event_recurrence'       => 'weekly',
		'_event_recurrence_days'  => (string) rec_test_weekday( 40 ),
		'_event_recurrence_start' => rec_test_day( 40 ),
		'_event_recurrence_end'   => rec_test_day( 120 ),
	),
	'5. Weekly ' . rec_test_weekday_name( 0 ) . ' ended' => array(
		'_event_date'             => rec_test_day( -40 ),
		'_event_recurrence'       => 'weekly',
		'_event_recurrence_days'  => (string) rec_test_weekday( 0 ),
		'_event_recurrence_start' => rec_test_day( -40 ),
		'_event_recurrence_end'   => rec_test_day( -2 ),
	),
	'6. Weekly twice (' . rec_test_weekday_name( 0 ) . ' + ' . rec_test_weekday_name( 2 ) . ')' => array(
		'_event_date'             => rec_test_day( 0 ),
		'_event_recurrence'       => 'weekly',
		'_event_recurrence_days'  => rec_test_weekday( 0 ) . ',' . rec_test_weekday( 2 ),
		'_event_recurrence_start' => rec_test_day( 0 ),
	),
	'7. Weekly open-ended ' . rec_test_weekday_name( 0 ) => array(
		'_event_date'             => rec_test_day( -90 ),
		'_event_recurrence'       => 'weekly',
		'_event_recurrence_days'  => (string) rec_test_weekday( 0 ),
		'_event_recurrence_start' => rec_test_day( -90 ),
	),
	'8. Weekly weekday mismatch (' . rec_test_weekday_name( 0 ) . ', started ' . rec_test_weekday_name( -2 ) . ')' => array(
		'_event_date'             => rec_test_day( -2 ),
		'_event_recurrence'       => 'weekly',
		'_event_recurrence_days'  => (string) rec_test_weekday( 0 ),
		'_event_recurrence_start' => rec_test_day( -2 ),
	),
	'9. Normal event (no recurrence meta)' => array(
		'_event_date'     => rec_test_day( 6 ),
		'_event_time'     => '10:00',
		'_event_location' => 'Cork',
	),
);

echo 'Seeding [REC-TEST] events — today: ' . rec_test_day( 0 ) . ' (' . rec_test_weekday_name( 0 ) . ")\n";

foreach ( $events as $title => $meta ) {
	$id = rec_test_create( $title, $meta );
	echo sprintf( "  %s%s => ID %s (date %s)\n", REC_TEST_PREFIX, $title, $id ? $id : 'FAILED', isset( $meta['_event_date'] ) ? $meta['_event_date'] : '-' );
}

// Event 9 must carry no recurrence meta at all — nothing to do, defaults are empty.

Conexao_Event_Query::flush_cache();

echo "\nExpected on /eventos/ (and hero/front-page/404 surfaces):\n";
echo "  IN:  #1 (one-time future), #3 (active today), #6, #7, #8, #9\n";
echo "  OUT: #2 (past one-time), #4 (not started), #5 (ended)\n";
echo "\nExpected /eventos/ order (by next occurrence):\n";
echo "  #3, #6, #8 (today) -> #9 (+6d) -> #1 (+12d)\n";
echo "\nDone. Clean up with: php scripts/seed-recurrence-test-events.php cleanup\n";
