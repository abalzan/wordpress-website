<?php
/**
 * Automated tests for the Event Runtime / Event Importer separation.
 *
 * Verifies that:
 *  - The Event Runtime plugin provides the production-critical event
 *    behavior (meta registration, conexao_town, _event_status public query
 *    filtering, status admin UI hooks) WITHOUT the importer tooling.
 *  - The public query gate behaves identically with and without tooling
 *    (upcoming/expired/source_not_found/dateless/invalid-date/draft cases).
 *  - Admin UX keeps working against the runtime classes.
 *  - No cron events are introduced by either plugin.
 *  - With tooling loaded, the importer classes exist and retired sources
 *    (Laois County Council, LEO Laois) remain removed.
 *
 * Usage (run from the project root):
 *   # Runtime-only configuration (importer deactivated):
 *   docker compose exec wordpress wp --allow-root plugin activate conexao-event-runtime
 *   docker compose exec wordpress wp --allow-root plugin deactivate conexao-event-importer
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-plugin-separation.php no-tooling
 *
 *   # Runtime + tooling configuration (importer activated):
 *   docker compose exec wordpress wp --allow-root plugin activate conexao-event-importer
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-plugin-separation.php with-tooling
 *
 * The script creates temporary event posts and deletes them at the end.
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$mode = isset( $argv[1] ) ? $argv[1] : '';

if ( ! in_array( $mode, array( 'no-tooling', 'with-tooling' ), true ) ) {
	echo "Usage: php test-plugin-separation.php [no-tooling|with-tooling]\n";
	exit( 1 );
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

/**
 * Create a temporary test event and return its ID.
 *
 * @param string $title    Title.
 * @param array  $meta     Meta key/value pairs.
 * @param string $wp_status WordPress post status.
 * @return int Post ID.
 */
function create_test_event( $title, $meta = array(), $wp_status = 'publish' ) {
	global $test_post_ids;
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'event',
			'post_title'  => '[TEST] ' . $title,
			'post_status' => $wp_status,
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		echo "  FATAL: could not create test event: {$post_id->get_error_message()}\n";
		exit( 1 );
	}
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	$test_post_ids[] = $post_id;
	return $post_id;
}

/**
 * Run a public (frontend-context) event query and return the post IDs.
 *
 * The test script runs under WP-CLI/PHP where is_admin() is false, so the
 * runtime's pre_get_posts filter applies — same as a frontend request.
 *
 * @return int[]
 */
function public_event_query_ids() {
	$query = new WP_Query(
		array(
			'post_type'        => 'event',
			'post_status'      => 'publish',
			'posts_per_page'   => 100,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		)
	);
	return wp_list_pluck( $query->posts, 'ID' );
}

echo "Running event runtime/importer separation tests (mode: {$mode})...\n";
echo 'Site: ' . home_url() . "\n";

// ---------------------------------------------------------------------------
// 1. Runtime availability
// ---------------------------------------------------------------------------
test_section( 'Runtime Availability' );

test_assert( class_exists( 'Conexao_Event_Runtime' ), 'Conexao_Event_Runtime class is loaded' );
test_assert( class_exists( 'Conexao_Event_Status' ), 'Conexao_Event_Status class is loaded' );
test_assert( taxonomy_exists( 'conexao_town' ), 'conexao_town taxonomy is registered' );
test_assert( post_type_exists( 'event' ), 'event post type is registered (Data Model)' );

$registered = get_registered_meta_keys( 'post', 'event' );
test_assert( isset( $registered['_event_status'] ), '_event_status meta is registered' );
test_assert( isset( $registered['_event_date'] ), '_event_date meta is registered' );
test_assert( ! empty( $registered['_event_status']['show_in_rest'] ), '_event_status meta is REST-visible' );

// Status API semantics preserved.
test_assert( Conexao_Event_Status::get_status( PHP_INT_MAX ) === 'published', 'legacy events without status default to published' );
test_assert( array_keys( Conexao_Event_Status::get_statuses() ) === array( 'draft', 'published', 'source_not_found', 'expired', 'rejected' ), 'status set is unchanged' );

// ---------------------------------------------------------------------------
// 2. Tooling dependency direction
// ---------------------------------------------------------------------------
test_section( 'Tooling Dependency Direction' );

if ( 'no-tooling' === $mode ) {
	test_assert( ! class_exists( 'Conexao_Event_Importer' ), 'importer boot class NOT loaded without tooling' );
	test_assert( ! class_exists( 'Conexao_Event_Importer_Engine' ), 'import engine NOT loaded without tooling' );
	test_assert( ! class_exists( 'Conexao_Event_Sources' ), 'source registry NOT loaded without tooling' );
	test_assert( ! class_exists( 'Conexao_Event_Image_Handler' ), 'image handler NOT loaded without tooling' );
	test_assert( ! class_exists( 'Conexao_Import_Settings' ), 'Eventbrite settings NOT loaded without tooling' );
	test_assert( ! class_exists( 'Conexao_Import_Log' ), 'import logs NOT loaded without tooling' );
} else {
	test_assert( class_exists( 'Conexao_Event_Importer' ), 'importer boot class loads with runtime active' );
	test_assert( class_exists( 'Conexao_Event_Importer_Engine' ), 'import engine loads with runtime active' );
	test_assert( class_exists( 'Conexao_Event_Sources' ), 'source registry loads with runtime active' );
	test_assert( class_exists( 'Conexao_Event_Deduplicator' ), 'deduplicator loads with runtime active' );
	test_assert( class_exists( 'Conexao_Event_Date_Filter' ), 'date filter loads with runtime active' );

	// Retired sources remain removed.
	$sources = ( new Conexao_Event_Sources() )->get_all();
	$retired = array( 'laois_council', 'leo_laois', 'local_enterprise_office_laois' );
	foreach ( $retired as $retired_id ) {
		test_assert( ! isset( $sources[ $retired_id ] ), "retired source '{$retired_id}' remains removed" );
	}

	// Past-event filtering still rejects past events (real import + dry-run path).
	$past = Conexao_Event_Date_Filter::evaluate(
		array(
			'start_date' => gmdate( 'Y-m-d', strtotime( '-10 days' ) ),
			'start_time' => '10:00',
		)
	);
	test_assert( Conexao_Event_Date_Filter::PAST === $past['status'], 'past events are still rejected by the import date filter' );

	$future = Conexao_Event_Date_Filter::evaluate(
		array(
			'start_date' => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
			'start_time' => '10:00',
		)
	);
	test_assert( Conexao_Event_Date_Filter::IMPORT === $future['status'], 'future events are still accepted by the import date filter' );
}

// ---------------------------------------------------------------------------
// 3. Public query gate (the production-critical behavior)
// ---------------------------------------------------------------------------
test_section( 'Public Event Query Gate' );

$upcoming = create_test_event( 'Upcoming published', array( '_event_status' => 'published', '_event_date' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ), '_event_time' => '18:00' ) );
$expired  = create_test_event( 'Expired', array( '_event_status' => 'expired', '_event_date' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ) );
$missing  = create_test_event( 'Source not found', array( '_event_status' => 'source_not_found', '_event_date' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ) ) );
$dateless = create_test_event( 'Legacy dateless (no status meta)' ); // No _event_status, no _event_date.
$draft    = create_test_event( 'Draft status', array( '_event_status' => 'draft', '_event_date' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ) ) );
$invalid  = create_test_event( 'Invalid date, published', array( '_event_status' => 'published', '_event_date' => 'not-a-date' ) );
$normal   = create_test_event( 'Normal published', array( '_event_status' => 'published', '_event_date' => gmdate( 'Y-m-d', strtotime( '+1 day' ) ) ) );
$rejected = create_test_event( 'Rejected', array( '_event_status' => 'rejected', '_event_date' => gmdate( 'Y-m-d', strtotime( '+5 days' ) ) ) );

$ids = public_event_query_ids();

test_assert( in_array( $upcoming, $ids, true ), 'published upcoming event is visible' );
test_assert( ! in_array( $expired, $ids, true ), 'expired event is hidden' );
test_assert( ! in_array( $missing, $ids, true ), 'source_not_found event is hidden' );
test_assert( in_array( $dateless, $ids, true ), 'legacy dateless event without status is visible' );
test_assert( ! in_array( $draft, $ids, true ), 'draft-status event is hidden' );
test_assert( in_array( $invalid, $ids, true ), 'invalid-date event with published status is visible (gate is status-based)' );
test_assert( in_array( $normal, $ids, true ), 'normal published event is visible' );
test_assert( ! in_array( $rejected, $ids, true ), 'rejected event is hidden' );

// A query carrying its own OR meta_query must keep the gate ANDed (deduplicator safety).
$query = new WP_Query(
	array(
		'post_type'        => 'event',
		'post_status'      => 'publish',
		'posts_per_page'   => 100,
		'suppress_filters' => false,
		'meta_query'       => array(
			'relation' => 'OR',
			array(
				'key'   => '_event_source',
				'value' => 'does-not-exist',
			),
		),
	)
);
$or_ids = wp_list_pluck( $query->posts, 'ID' );
test_assert( ! in_array( $expired, $or_ids, true ), 'OR meta_query: expired event does not leak through' );

// ---------------------------------------------------------------------------
// 4. Admin UX compatibility (via runtime classes)
// ---------------------------------------------------------------------------
test_section( 'Admin UX Compatibility' );

if ( class_exists( 'Conexao_Admin_Ux_Actions' ) ) {
	test_assert( Conexao_Admin_Ux_Actions::get_status( $expired, 'event' ) === 'expired', 'Admin UX reads event status through the runtime class' );
	Conexao_Admin_Ux_Actions::set_status( $expired, 'event', 'published' );
	test_assert( Conexao_Event_Status::get_status( $expired ) === 'published', 'Admin UX writes event status through the runtime class' );
	Conexao_Admin_Ux_Actions::set_status( $expired, 'event', 'expired' );
	test_assert( Conexao_Event_Status::get_status( $expired ) === 'expired', 'status restored to expired' );
} else {
	test_assert( false, 'Admin UX actions class available' );
}

// ---------------------------------------------------------------------------
// 5. No cron
// ---------------------------------------------------------------------------
test_section( 'No Cron Introduced' );

$cron         = _get_cron_array();
$conexao_cron = array();
foreach ( $cron as $timestamp => $hooks ) {
	foreach ( $hooks as $hook => $events ) {
		if ( false !== strpos( $hook, 'conexao' ) ) {
			$conexao_cron[] = $hook;
		}
	}
}
test_assert(
	empty( $conexao_cron ),
	'no conexao cron hooks scheduled: ' . ( $conexao_cron ? implode( ', ', array_unique( $conexao_cron ) ) : 'none' )
);

// ---------------------------------------------------------------------------
// 6. Legacy expiry for multi-day events
// ---------------------------------------------------------------------------
test_section( 'Multi-Day Legacy Expiry' );

// Multi-day event (no status) that started in the past but ends in the
// future must NOT be expired by the legacy path.
$md_legacy = create_test_event( 'MD legacy active', array(
	'_event_date'      => gmdate( 'Y-m-d', strtotime( '-2 days' ) ),
	'_event_end_date'  => gmdate( 'Y-m-d', strtotime( '+2 days' ) ),
) );

// One-day legacy event in the past SHOULD be expired.
$md_legacy_past = create_test_event( 'MD legacy past', array(
	'_event_date' => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
) );

Conexao_Event_Status::mark_expired_events();

test_assert(
	'expired' !== Conexao_Event_Status::get_status( $md_legacy ),
	'L: multi-day legacy event is NOT expired while end date is still future'
);
test_assert(
	'expired' === Conexao_Event_Status::get_status( $md_legacy_past ),
	'L: one-day legacy event in the past IS still expired'
);

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
test_section( 'Cleanup' );

foreach ( $test_post_ids as $pid ) {
	wp_delete_post( $pid, true );
}
echo 'Deleted ' . count( $test_post_ids ) . " temporary test events.\n";

echo "\n==============================\n";
echo "PASSED: {$passed}  FAILED: {$failed}\n";
echo "==============================\n";
exit( $failed > 0 ? 1 : 0 );
