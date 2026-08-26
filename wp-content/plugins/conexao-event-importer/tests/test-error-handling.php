<?php
/**
 * Automated tests for the improved import error handling.
 *
 * Tests per-event error isolation, global/fatal error handling, structured
 * result counting, and import summaries.
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-error-handling.php
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

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

echo "Running error handling tests...\n";

// ---------------------------------------------------------------------------
// Test 1: Import_Result class
// ---------------------------------------------------------------------------
test_section( 'Import_Result Class' );

$result = new Conexao_Import_Result( 'test_source', 'Test Source' );
test_assert( 'success' === $result->get_status(), 'Result starts as success' );
test_assert( 0 === $result->get_counts()['created'], 'Created count starts at 0' );
test_assert( 0 === $result->get_counts()['failed'], 'Failed count starts at 0' );

$result->set_found( 5 );
test_assert( 5 === $result->get_counts()['found'], 'Found count set correctly' );

$result->add_created( 'Event A', 100 );
$result->add_updated( 'Event B', 101 );
$result->add_unchanged( 'Event C', 102 );
$result->add_skipped( 'Event D', 'Online only event.' );

test_assert( 1 === $result->get_counts()['created'], 'Created count increments' );
test_assert( 1 === $result->get_counts()['updated'], 'Updated count increments' );
test_assert( 1 === $result->get_counts()['unchanged'], 'Unchanged count increments' );
test_assert( 1 === $result->get_counts()['skipped'], 'Skipped count increments' );
test_assert( ! method_exists( $result, 'add_needs_review' ), 'No needs-review outcome exists anymore' );
test_assert( 'warning' === $result->get_status(), 'Warnings upgrade status to warning' );
test_assert( 4 === count( $result->get_events() ), 'Events list has 4 entries' );

// ---------------------------------------------------------------------------
// Test 2: Per-event failure does not stop the import
// ---------------------------------------------------------------------------
test_section( 'Per-Event Error Isolation' );

$result2 = new Conexao_Import_Result( 'test_source', 'Test Source' );
$result2->add_failed( 'Broken Event', 'Database error' );
$result2->add_created( 'Good Event', 200 );

test_assert( 'partial' === $result2->get_status(), 'Per-event failure sets status to partial' );
test_assert( 1 === $result2->get_counts()['failed'], 'Failed count increments' );
test_assert( 1 === $result2->get_counts()['created'], 'Import continued processing other events' );
test_assert( 1 === count( $result2->get_failed_events() ), 'get_failed_events returns the failed event' );
test_assert( 'Broken Event' === $result2->get_failed_events()[0]['title'], 'Failed event title is correct' );

// ---------------------------------------------------------------------------
// Test 3: Fatal errors
// ---------------------------------------------------------------------------
test_section( 'Fatal Error Handling' );

$result3 = new Conexao_Import_Result( 'test_source', 'Test Source' );
$result3->add_fatal_error( 'Eventbrite could not be reached.', 'HTTP 503' );
test_assert( 'failed' === $result3->get_status(), 'Fatal error sets status to failed' );
test_assert( $result3->has_fatal_error(), 'has_fatal_error returns true' );
test_assert( 1 === count( $result3->get_fatal_errors() ), 'Fatal errors list has 1 entry' );

// ---------------------------------------------------------------------------
// Test 4: Import_Result -> stats array backward compatibility
// ---------------------------------------------------------------------------
test_section( 'Stats Array Backward Compatibility' );

$result4 = new Conexao_Import_Result( 'eventbrite', 'Eventbrite' );
$result4->set_found( 10 );
$result4->add_created( 'Evt 1', 1 );
$result4->add_created( 'Evt 2', 2 );
$result4->add_updated( 'Evt 3', 3 );
$result4->add_failed( 'Evt 4', 'DB error' );

$stats = $result4->get_stats();
test_assert( isset( $stats['found'] ) && 10 === $stats['found'], 'Stats has found=10' );
test_assert( isset( $stats['new'] ) && 2 === $stats['new'], 'Stats has legacy new=2' );
test_assert( isset( $stats['created'] ) && 2 === $stats['created'], 'Stats has new created=2' );
test_assert( isset( $stats['errors'] ) && 1 === $stats['errors'], 'Stats has legacy errors=1' );
test_assert( isset( $stats['failed'] ) && 1 === $stats['failed'], 'Stats has new failed=1' );
test_assert( isset( $stats['status'] ) && 'partial' === $stats['status'], 'Stats has status=partial' );
test_assert( isset( $stats['event_results'] ) && 4 === count( $stats['event_results'] ), 'Stats has event_results' );
test_assert( isset( $stats['fatal_errors'] ) && is_array( $stats['fatal_errors'] ), 'Stats has fatal_errors array' );

// ---------------------------------------------------------------------------
// Test 5: Import Log structured entries
// ---------------------------------------------------------------------------
test_section( 'Structured Logging' );

// sanitize_key() strips dots and converts hyphens, so use a clean run ID.
$run_id = 'test_run_' . (int) microtime( true );
Conexao_Import_Log::add( 'eventbrite', 'error', 'Test error message', array(
	'run_id'       => $run_id,
	'event_id'     => '12345',
	'event_title'  => 'Test Event',
	'http_status'  => 429,
	'token'        => 'should-not-be-logged',
	'api_key'      => 'secret-key',
	'url'          => 'https://example.com',
) );

$all_logs = Conexao_Import_Log::get_all();
test_assert( count( $all_logs ) > 0, 'Log entries exist' );

$latest = $all_logs[0];
test_assert( 'eventbrite' === $latest['source'], 'Log has correct source' );
test_assert( 'error' === $latest['level'], 'Log has correct level' );
test_assert( $run_id === $latest['run_id'], 'Log has run_id' );
test_assert( '12345' === $latest['event_id'], 'Log has event_id' );
test_assert( 'Test Event' === $latest['event_title'], 'Log has event_title' );
test_assert( 429 === $latest['http_status'], 'Log has http_status=429' );
test_assert( ! isset( $latest['token'] ) || empty( $latest['token'] ), 'Log does not contain token' );
test_assert( ! isset( $latest['api_key'] ) || empty( $latest['api_key'] ), 'Log does not contain api_key' );
test_assert( isset( $latest['context']['url'] ), 'Log keeps safe URL context' );

// Test get_for_run
$run_logs = Conexao_Import_Log::get_for_run( $run_id );
test_assert( count( $run_logs ) >= 1, 'get_for_run returns entries' );
test_assert( $run_logs[0]['run_id'] === $run_id, 'get_for_run filters by run_id' );

// ---------------------------------------------------------------------------
// Test 6: Import History with new fields
// ---------------------------------------------------------------------------
test_section( 'Import History' );

$stats = array(
	'found'        => 10,
	'new'          => 3,
	'updated'      => 2,
	'unchanged'    => 3,
	'duplicates'   => 1,
	'skipped'      => 1,
	'errors'       => 0,
	'failed'       => 0,
);

$history = Conexao_Import_History::get_all();
$history_count = count( $history );

$entry = Conexao_Import_History::record( 'test_source', $stats, 'success', 'Test import', array(
	'run_id' => 'test-history-run',
) );

test_assert( 'success' === $entry['status'], 'History entry has success status' );
test_assert( 1 === $entry['skipped'], 'History entry has skipped count' );
test_assert( 'test-history-run' === $entry['run_id'], 'History entry has run_id' );

$history = Conexao_Import_History::get_all();
test_assert( count( $history ) === $history_count + 1, 'History entry was added' );

// ---------------------------------------------------------------------------
// Test 7: run_source with invalid source ID returns error status
// ---------------------------------------------------------------------------
test_section( 'run_source Error Paths' );

$plugin = Conexao_Event_Importer::instance();
$result = $plugin->importer->run_source( 'non_existent_source' );

test_assert( is_array( $result ), 'run_source returns an array' );
test_assert( 'failed' === $result['status'], 'Unknown source returns failed status' );
test_assert( isset( $result['fatal_errors'] ) && count( $result['fatal_errors'] ) > 0, 'Unknown source has fatal errors' );
test_assert( 'Event source not found.' === $result['fatal_errors'][0]['message'], 'Unknown source fatal error has correct message' );

// ---------------------------------------------------------------------------
// Test 8: run_source with inactive source returns warning
// ---------------------------------------------------------------------------
test_section( 'Inactive Source' );

// Create a temporary inactive source.
$plugin->sources->save( array(
	'id'     => 'test_inactive',
	'name'   => 'Test Inactive Source',
	'url'    => 'https://example.com',
	'type'   => 'website',
	'status' => 'inactive',
) );

$result = $plugin->importer->run_source( 'test_inactive' );
test_assert( 'warning' === $result['status'], 'Inactive source returns warning status' );

// Cleanup test source.
$plugin->sources->delete( 'test_inactive' );

// ---------------------------------------------------------------------------
// Test 9: Import result notice rendering (output capture)
// ---------------------------------------------------------------------------
test_section( '9: Admin Result Notice' );

$sources = $plugin->sources;

// Use a mock notice renderer method
try {
	$ref = new ReflectionClass( 'Conexao_Event_Sources' );
	$method = $ref->getMethod( 'render_import_result_notice' );
	$method->setAccessible( true );

	ob_start();
	$method->invoke( $sources, array(
		'status'        => 'partial',
		'created'       => 3,
		'updated'       => 2,
		'unchanged'     => 5,
		'duplicates'    => 0,
		'skipped'       => 1,
		'failed'        => 1,
		'errors'        => 1,
		'fatal_errors'  => array(),
		'event_results' => array(
			array( 'title' => 'Bad Event', 'outcome' => 'failed', 'message' => 'Database error', 'technical' => 'SQLSTATE[HY000]' ),
		),
	), 'test_source' );
	$output = ob_get_clean();

	test_assert( false !== strpos( $output, 'Import completed with errors' ), 'Notice shows warning title' );
	test_assert( false !== strpos( $output, '3 created' ), 'Notice shows created count' );
	test_assert( false !== strpos( $output, '2 updated' ), 'Notice shows updated count' );
	test_assert( false !== strpos( $output, '1 failed' ), 'Notice shows failed count' );
	test_assert( false !== strpos( $output, 'Bad Event' ), 'Notice shows failed event title' );
	test_assert( false !== strpos( $output, 'Database error' ), 'Notice shows failure reason' );
	test_assert( false !== strpos( $output, 'notice-warning' ), 'Notice uses warning CSS class' );
} catch ( Exception $e ) {
	test_assert( false, 'Exception rendering notice: ' . $e->getMessage() );
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n========================================\n";
echo "Error Handling Test Results: {$passed} passed, {$failed} failed\n";
echo "========================================\n";

exit( $failed > 0 ? 1 : 0 );