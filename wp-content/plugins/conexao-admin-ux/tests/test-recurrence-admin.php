<?php
/**
 * Tests for the Step 2 admin event recurrence editor.
 *
 * Verifies that the recurrence fields render, save, validate, clear, and
 * survive round-trips correctly. Tests are run against the live API
 * (Conexao_Admin_Ux_Fields) so they exercise the same code paths the
 * admin editor uses.
 *
 * The script creates temporary event posts and deletes them at the end;
 * it writes no other data and runs in the current plugin configuration.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-admin-ux/tests/test-recurrence-admin.php
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$passed        = 0;
$failed        = 0;
$test_post_ids = array();

function test_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

function test_section( $title ) {
	echo "\n=== {$title} ===\n";
}

function create_test_event( $title, $meta = array() ) {
	global $test_post_ids;
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'event',
			'post_title'  => '[TEST] ' . $title,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		echo "  FATAL: could not create test event: {$post_id->get_error_message()}\n";
		return 0;
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	$test_post_ids[] = $post_id;
	return $post_id;
}

function cleanup_test_events() {
	global $test_post_ids;
	foreach ( $test_post_ids as $pid ) {
		wp_delete_post( $pid, true );
	}
	$test_post_ids = array();
}

// -------------------------------------------------------------------------
// 1. Sanitize weekdays
// -------------------------------------------------------------------------
test_section( 'sanitize_weekdays' );

$result = Conexao_Admin_Ux_Fields::sanitize_weekdays( '' );
test_assert( $result === array(), 'Empty string returns empty array' );

$result = Conexao_Admin_Ux_Fields::sanitize_weekdays( array() );
test_assert( $result === array(), 'Empty array returns empty array' );

$result = Conexao_Admin_Ux_Fields::sanitize_weekdays( '3' );
test_assert( $result === array( 3 ), 'Single weekday 3 (Qua) returns [3]' );

$result = Conexao_Admin_Ux_Fields::sanitize_weekdays( array( '1', '3' ) );
test_assert( $result === array( 1, 3 ), 'Mon+Wed returns [1, 3]' );

$result = Conexao_Admin_Ux_Fields::sanitize_weekdays( array( '7', '1', '3' ) );
test_assert( $result === array( 1, 3, 7 ), 'Sun+Mon+Wed returns [1, 3, 7] (ascending)' );

$result = Conexao_Admin_Ux_Fields::sanitize_weekdays( array( '3', '3', '3' ) );
test_assert( $result === array( 3 ), 'Duplicate Qua returns [3]' );

$result = Conexao_Admin_Ux_Fields::sanitize_weekdays( array( '0', '8', '-1' ) );
test_assert( $result === array(), '0, 8, -1 all rejected' );

$result = Conexao_Admin_Ux_Fields::sanitize_weekdays( array( 'abc', 'foo', '' ) );
test_assert( $result === array(), 'Non-numeric tokens rejected' );

$result = Conexao_Admin_Ux_Fields::sanitize_weekdays( '1,3,5' );
test_assert( $result === array( 1, 3, 5 ), 'CSV "1,3,5" returns [1, 3, 5]' );

$result = Conexao_Admin_Ux_Fields::sanitize_weekdays( '1, 3, 7' );
test_assert( $result === array( 1, 3, 7 ), 'CSV with spaces returns [1, 3, 7]' );

$result = Conexao_Admin_Ux_Fields::sanitize_weekdays( '1,foo,8,3' );
test_assert( $result === array( 1, 3 ), 'CSV with invalid tokens drops them' );

// -------------------------------------------------------------------------
// 2. Validate recurrence — one-time
// -------------------------------------------------------------------------
test_section( 'validate_recurrence — one-time' );

$data = array( 'conexao_fields' => array( 'event_recurrence' => '' ) );
$r = Conexao_Admin_Ux_Fields::validate_recurrence( $data );
test_assert( empty( $r['errors'] ), 'Evento único: no errors' );
test_assert( empty( $r['warnings'] ), 'Evento único: no warnings' );

$r = Conexao_Admin_Ux_Fields::validate_recurrence( array() );
test_assert( empty( $r['errors'] ), 'No recurrence field: no errors' );

$r = Conexao_Admin_Ux_Fields::validate_recurrence( array( 'conexao_fields' => array( 'event_recurrence' => 'monthly' ) ) );
test_assert( empty( $r['errors'] ), 'Unknown value treated as one-time: no errors' );

// -------------------------------------------------------------------------
// 3. Validate recurrence — zero weekdays (blocking)
// -------------------------------------------------------------------------
test_section( 'validate_recurrence — zero weekdays' );

$data = array(
	'conexao_fields' => array(
		'event_recurrence' => 'weekly',
		'event_recurrence_days' => array(),
	),
);
$r = Conexao_Admin_Ux_Fields::validate_recurrence( $data );
test_assert( ! empty( $r['errors'] ), 'Zero weekdays: blocking error' );

// -------------------------------------------------------------------------
// 4. Validate recurrence — end before start (blocking)
// -------------------------------------------------------------------------
test_section( 'validate_recurrence — end before start' );

$data = array(
	'conexao_fields' => array(
		'event_recurrence' => 'weekly',
		'event_recurrence_days' => array( '3' ),
		'event_recurrence_start' => '2026-06-15',
		'event_recurrence_end' => '2026-06-08',
	),
);
$r = Conexao_Admin_Ux_Fields::validate_recurrence( $data );
test_assert( ! empty( $r['errors'] ), 'End before start: blocking error' );

// -------------------------------------------------------------------------
// 5. Validate recurrence — start not on weekday (warning)
// -------------------------------------------------------------------------
test_section( 'validate_recurrence — start not on weekday' );

$data = array(
	'conexao_fields' => array(
		'event_recurrence' => 'weekly',
		'event_recurrence_days' => array( '3' ),
		'event_recurrence_start' => '2026-06-15', // Monday, but we selected Wednesday
	),
);
$r = Conexao_Admin_Ux_Fields::validate_recurrence( $data );
test_assert( empty( $r['errors'] ), 'Start not on weekday: no blocking errors' );
test_assert( ! empty( $r['warnings'] ), 'Start not on weekday: warning present' );

// Start ON selected weekday — no warning.
$data = array(
	'conexao_fields' => array(
		'event_recurrence' => 'weekly',
		'event_recurrence_days' => array( '1', '3' ),
		'event_recurrence_start' => '2026-06-15', // Monday (1)
	),
);
$r = Conexao_Admin_Ux_Fields::validate_recurrence( $data );
test_assert( empty( $r['errors'] ), 'Start on Mon with Mon+Wed: no errors' );
test_assert( empty( $r['warnings'] ), 'Start on Mon with Mon+Wed: no warnings' );

// -------------------------------------------------------------------------
// 6. Validate recurrence — valid weekly
// -------------------------------------------------------------------------
test_section( 'validate_recurrence — valid weekly' );

$data = array(
	'conexao_fields' => array(
		'event_recurrence' => 'weekly',
		'event_recurrence_days' => array( '3' ),
		'event_recurrence_start' => '2026-06-17', // Wednesday (3)
		'event_recurrence_end' => '',
	),
);
$r = Conexao_Admin_Ux_Fields::validate_recurrence( $data );
test_assert( empty( $r['errors'] ), 'Valid weekly: no errors' );
test_assert( empty( $r['warnings'] ), 'Valid weekly: no warnings' );

$data = array(
	'conexao_fields' => array(
		'event_recurrence' => 'weekly',
		'event_recurrence_days' => array( '1' ),
		'event_recurrence_start' => '',
		'event_recurrence_end' => '',
	),
);
$r = Conexao_Admin_Ux_Fields::validate_recurrence( $data );
test_assert( empty( $r['errors'] ), 'Blank start+end + valid weekday: no errors' );

// -------------------------------------------------------------------------
// 7. Save recurrence — one-time clears group
// -------------------------------------------------------------------------
test_section( 'save_event_recurrence — one-time clears group' );

$pid = create_test_event( 'Clear recurrence test' );
update_post_meta( $pid, '_event_recurrence', 'weekly' );
update_post_meta( $pid, '_event_recurrence_days', '1,3' );
update_post_meta( $pid, '_event_recurrence_start', '2026-06-15' );
update_post_meta( $pid, '_event_recurrence_end', '2026-12-31' );
Conexao_Admin_Ux_Fields::save_event_recurrence( $pid, array( 'event_recurrence' => '' ) );

test_assert( '' === get_post_meta( $pid, '_event_recurrence', true ), '_event_recurrence cleared' );
test_assert( '' === get_post_meta( $pid, '_event_recurrence_days', true ), '_event_recurrence_days cleared' );
test_assert( '' === get_post_meta( $pid, '_event_recurrence_start', true ), '_event_recurrence_start cleared' );
test_assert( '' === get_post_meta( $pid, '_event_recurrence_end', true ), '_event_recurrence_end cleared' );

// -------------------------------------------------------------------------
// 8. Save recurrence — weekly valid
// -------------------------------------------------------------------------
test_section( 'save_event_recurrence — weekly valid' );

$pid = create_test_event( 'Save weekly valid' );
Conexao_Admin_Ux_Fields::save_event_recurrence( $pid, array(
	'event_recurrence' => 'weekly',
	'event_recurrence_days' => array( '1', '3' ),
	'event_recurrence_start' => '2026-06-15',
	'event_recurrence_end' => '2026-12-31',
) );

test_assert( 'weekly' === get_post_meta( $pid, '_event_recurrence', true ), '_event_recurrence = weekly' );
test_assert( '1,3' === get_post_meta( $pid, '_event_recurrence_days', true ), '_event_recurrence_days = 1,3' );
test_assert( '2026-06-15' === get_post_meta( $pid, '_event_recurrence_start', true ), '_event_recurrence_start = 2026-06-15' );
test_assert( '2026-12-31' === get_post_meta( $pid, '_event_recurrence_end', true ), '_event_recurrence_end = 2026-12-31' );

// -------------------------------------------------------------------------
// 9. Save recurrence — blank start/end = delete meta
// -------------------------------------------------------------------------
test_section( 'save_event_recurrence — blank start/end' );

$pid = create_test_event( 'Blank start/end' );
Conexao_Admin_Ux_Fields::save_event_recurrence( $pid, array(
	'event_recurrence' => 'weekly',
	'event_recurrence_days' => array( '3' ),
	'event_recurrence_start' => '',
	'event_recurrence_end' => '',
) );

test_assert( 'weekly' === get_post_meta( $pid, '_event_recurrence', true ), '_event_recurrence = weekly' );
test_assert( '3' === get_post_meta( $pid, '_event_recurrence_days', true ), '_event_recurrence_days = 3' );
test_assert( '' === get_post_meta( $pid, '_event_recurrence_start', true ), '_event_recurrence_start not stored' );
test_assert( '' === get_post_meta( $pid, '_event_recurrence_end', true ), '_event_recurrence_end not stored' );

// -------------------------------------------------------------------------
// 10. Save recurrence — invalid refused
// -------------------------------------------------------------------------
test_section( 'save_event_recurrence — invalid refused' );

$pid = create_test_event( 'Invalid refused' );
update_post_meta( $pid, '_event_recurrence', 'weekly' );
update_post_meta( $pid, '_event_recurrence_days', '3' );

// Try zero weekdays.
Conexao_Admin_Ux_Fields::save_event_recurrence( $pid, array(
	'event_recurrence' => 'weekly',
	'event_recurrence_days' => array(),
) );
test_assert( 'weekly' === get_post_meta( $pid, '_event_recurrence', true ), 'Zero weekdays: previous rule survives' );
test_assert( '3' === get_post_meta( $pid, '_event_recurrence_days', true ), 'Zero weekdays: previous days survive' );

// -------------------------------------------------------------------------
// 11. Full round-trip via Fields::save()
// -------------------------------------------------------------------------
test_section( 'Full round-trip via Fields::save()' );

$pid = create_test_event( 'Round-trip weekly' );
$config = Conexao_Admin_Ux_Config::get( 'event' );
Conexao_Admin_Ux_Fields::save( $pid, $config, array(
	'conexao_fields' => array(
		'event_recurrence' => 'weekly',
		'event_recurrence_days' => array( '2', '4' ),
		'event_recurrence_start' => '2026-07-01',
		'event_recurrence_end' => '2026-12-31',
	),
) );

test_assert( 'weekly' === get_post_meta( $pid, '_event_recurrence', true ), '_event_recurrence = weekly' );
test_assert( '2,4' === get_post_meta( $pid, '_event_recurrence_days', true ), '_event_recurrence_days = 2,4' );
test_assert( '2026-07-01' === get_post_meta( $pid, '_event_recurrence_start', true ), '_event_recurrence_start = 2026-07-01' );
test_assert( '2026-12-31' === get_post_meta( $pid, '_event_recurrence_end', true ), '_event_recurrence_end = 2026-12-31' );

if ( class_exists( 'Conexao_Event_Recurrence' ) ) {
	$from = new DateTimeImmutable( '2026-07-01', wp_timezone() );
	$next = Conexao_Event_Recurrence::next_occurrence( $pid, $from );
	// 2026-07-01 is Wed, we selected Tue(2)+Thu(4) → next is 2026-07-02 (Thu).
	test_assert( null !== $next, 'next_occurrence() returns non-null' );
	if ( null !== $next ) {
		test_assert( '2026-07-02' === $next->format( 'Y-m-d' ), 'Next occurrence is 2026-07-02 (Thu)' );
	}
}

// -------------------------------------------------------------------------
// 12. Toggle recurring → one-time clears
// -------------------------------------------------------------------------
test_section( 'Toggle recurring → one-time clears metadata' );

$pid = create_test_event( 'Toggle to one-time' );
update_post_meta( $pid, '_event_recurrence', 'weekly' );
update_post_meta( $pid, '_event_recurrence_days', '3' );
update_post_meta( $pid, '_event_recurrence_start', '2026-06-17' );
update_post_meta( $pid, '_event_recurrence_end', '2026-12-31' );
Conexao_Admin_Ux_Fields::save( $pid, $config, array(
	'conexao_fields' => array( 'event_recurrence' => '' ),
) );

test_assert( '' === get_post_meta( $pid, '_event_recurrence', true ), 'Toggle: _event_recurrence cleared' );
test_assert( '' === get_post_meta( $pid, '_event_recurrence_days', true ), 'Toggle: _event_recurrence_days cleared' );
test_assert( '' === get_post_meta( $pid, '_event_recurrence_start', true ), 'Toggle: _event_recurrence_start cleared' );
test_assert( '' === get_post_meta( $pid, '_event_recurrence_end', true ), 'Toggle: _event_recurrence_end cleared' );

// -------------------------------------------------------------------------
// 13. Backward compatibility — legacy events
// -------------------------------------------------------------------------
test_section( 'Backward compatibility — no recurrence metadata' );

$pid = create_test_event( 'Legacy no recurrence' );
update_post_meta( $pid, '_event_date', '2026-06-15' );
Conexao_Admin_Ux_Fields::save( $pid, $config, array(
	'conexao_fields' => array(
		'event_title' => 'Legacy no recurrence',
		'event_date'  => '2026-06-15',
	),
) );

test_assert( '' === get_post_meta( $pid, '_event_recurrence', true ), 'Legacy: _event_recurrence still empty' );
test_assert( '' === get_post_meta( $pid, '_event_recurrence_days', true ), 'Legacy: _event_recurrence_days still empty' );

if ( class_exists( 'Conexao_Event_Recurrence' ) ) {
	$from = new DateTimeImmutable( '2026-06-15', wp_timezone() );
	$next = Conexao_Event_Recurrence::next_occurrence( $pid, $from );
	test_assert( null !== $next, 'Legacy: next_occurrence() returns non-null' );
	if ( null !== $next ) {
		test_assert( '2026-06-15' === $next->format( 'Y-m-d' ), 'Legacy: next occurrence is event date' );
	}
}

// -------------------------------------------------------------------------
// 14. REST meta verification
// -------------------------------------------------------------------------
test_section( 'REST meta representation' );

$pid = create_test_event( 'REST meta check' );
update_post_meta( $pid, '_event_recurrence', 'weekly' );
update_post_meta( $pid, '_event_recurrence_days', '1,3,5' );
update_post_meta( $pid, '_event_recurrence_start', '2026-06-15' );
update_post_meta( $pid, '_event_recurrence_end', '2026-12-31' );

$meta = get_post_meta( $pid );
test_assert( 'weekly' === $meta['_event_recurrence'][0], '_event_recurrence = weekly' );
test_assert( '1,3,5' === $meta['_event_recurrence_days'][0], '_event_recurrence_days = 1,3,5' );
test_assert( '2026-06-15' === $meta['_event_recurrence_start'][0], '_event_recurrence_start = 2026-06-15' );
test_assert( '2026-12-31' === $meta['_event_recurrence_end'][0], '_event_recurrence_end = 2026-12-31' );

// -------------------------------------------------------------------------
// 15. Reopen saved event
// -------------------------------------------------------------------------
test_section( 'Reopen verification' );

$pid = create_test_event( 'Reopen check' );
update_post_meta( $pid, '_event_recurrence', 'weekly' );
update_post_meta( $pid, '_event_recurrence_days', '2,4' );
update_post_meta( $pid, '_event_recurrence_start', '2026-07-07' );
update_post_meta( $pid, '_event_recurrence_end', '' );

$post = get_post( $pid );
foreach ( Conexao_Admin_Ux_Fields::collect_fields( $config ) as $field ) {
	$value = Conexao_Admin_Ux_Fields::get_value( $post, $field );
	if ( '_event_recurrence' === $field['key'] ) {
		test_assert( 'weekly' === $value, 'Reopen: _event_recurrence = weekly' );
	} elseif ( '_event_recurrence_days' === $field['key'] ) {
		test_assert( '2,4' === $value, 'Reopen: _event_recurrence_days = 2,4' );
	} elseif ( '_event_recurrence_start' === $field['key'] ) {
		test_assert( '2026-07-07' === $value, 'Reopen: _event_recurrence_start = 2026-07-07' );
	} elseif ( '_event_recurrence_end' === $field['key'] ) {
		test_assert( '' === $value, 'Reopen: _event_recurrence_end = "" (open-ended)' );
	}
}

// -------------------------------------------------------------------------
// Cleanup
// -------------------------------------------------------------------------
cleanup_test_events();

echo "\n===== RESULTS =====\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Total:  " . ( $passed + $failed ) . "\n\n";

exit( $failed > 0 ? 1 : 0 );
