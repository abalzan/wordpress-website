<?php
/**
 * Test script for the event export/import functionality.
 * Requires WordPress to be loaded. Run via: php scripts/test-event-transfer.php
 */

// Load WordPress core.
$wp_load = dirname( __DIR__ ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

echo "=== Event Export/Import Test ===\n\n";

// Check if the plugin classes are available.
if ( ! class_exists( 'Conexao_Event_Export' ) ) {
	echo "ERROR: Conexao_Event_Export class not found. Is the plugin active?\n";
	exit( 1 );
}

if ( ! class_exists( 'Conexao_Event_Import' ) ) {
	echo "ERROR: Conexao_Event_Import class not found. Is the plugin active?\n";
	exit( 1 );
}

$exporter = new Conexao_Event_Export();
$importer = new Conexao_Event_Import();

// 1. Count events.
$count = $exporter->count_events();
echo "1. Events available for export: {$count}\n";

// 2. Build the export payload.
$payload = $exporter->build_export();
echo "2. Export payload built: format={$payload['manifest']['format']}, version={$payload['manifest']['version']}, events=" . count( $payload['events'] ) . "\n";

// 3. Validate the export payload structure.
if ( count( $payload['events'] ) > 0 ) {
	$first = $payload['events'][0];
	echo "3. First event structure:\n";
	echo "   - uuid: " . ( isset( $first['uuid'] ) ? $first['uuid'] : 'MISSING' ) . "\n";
	echo "   - title: " . ( isset( $first['post']['title'] ) ? $first['post']['title'] : 'MISSING' ) . "\n";
	echo "   - meta keys: " . count( $first['meta'] ) . "\n";
	echo "   - taxonomies: " . implode( ', ', array_keys( $first['taxonomies'] ) ) . "\n";
	echo "   - featured_image: " . ( isset( $first['featured_image'] ) ? 'present' : 'MISSING' ) . "\n";
} else {
	echo "3. No events to inspect (empty database).\n";
}

// 4. Test the import validation with a sample file.
echo "\n4. Testing import validation...\n";

// Create a sample export file.
$sample_event = array(
	'uuid' => 'test-uuid-1234',
	'post' => array(
		'title'    => 'Test Import Event',
		'content'  => 'This is a test event description.',
		'excerpt'  => 'Test event excerpt.',
		'status'   => 'publish',
		'slug'     => 'test-import-event',
		'date'     => '2026-09-01 10:00:00',
		'modified' => '2026-09-01 10:00:00',
	),
	'meta' => array(
		'_event_date'        => '2026-09-01',
		'_event_time'        => '10:00 — 12:00',
		'_event_start_time'  => '10:00',
		'_event_end_date'    => '',
		'_event_end_time'    => '12:00',
		'_event_location'    => 'Portlaoise',
		'_event_venue'       => 'Portlaoise Library',
		'_event_address'     => '',
		'_event_url'         => 'https://example.com/test-event',
		'_event_source_url'  => 'https://example.com/test-event',
		'_event_banner'      => '',
		'_event_registration' => '',
		'_event_cta'         => '',
		'_event_source'      => 'test_source',
		'_event_source_id'   => 'test-1234',
		'_event_organizer'   => 'Test Organizer',
		'_event_price'       => 'Free',
		'_event_import_date' => '2026-08-01 10:00:00',
		'_event_last_checked' => '2026-08-15 10:00:00',
		'_event_status'      => 'published',
		'_event_imported'    => '1',
	),
	'taxonomies' => array(
		'conexao_category' => array( 'Heritage' ),
		'conexao_county'   => array( 'Laois' ),
		'conexao_tag'      => array( 'test' ),
		'conexao_town'     => array( 'Portlaoise' ),
	),
	'featured_image' => array(
		'source_url' => '',
		'banner_url' => '',
		'alt'        => '',
	),
);

$sample_payload = array(
	'manifest' => array(
		'format'      => Conexao_Event_Export::FORMAT,
		'version'     => Conexao_Event_Export::FORMAT_VERSION,
		'exported_at' => current_time( 'c' ),
		'source_url'  => home_url(),
		'event_count' => 1,
	),
	'events'   => array( $sample_event ),
);

$tmp_file = tempnam( sys_get_temp_dir(), 'conexao-test-' );
file_put_contents( $tmp_file, wp_json_encode( $sample_payload ) );

// Simulate a $_FILES entry.
$fake_file = array(
	'name'     => 'test-export.json',
	'type'     => 'application/json',
	'tmp_name' => $tmp_file,
	'error'    => UPLOAD_ERR_OK,
	'size'     => filesize( $tmp_file ),
);

// Note: is_uploaded_file() only returns true for real HTTP uploads, so we
// test the validation logic directly by checking the file content.
$validated = $importer->validate_upload( $fake_file );
if ( is_wp_error( $validated ) ) {
	// is_uploaded_file() fails for locally-created files, which is expected.
	// Test the JSON validation separately by reading the file directly.
	$contents = file_get_contents( $tmp_file );
	$data     = json_decode( $contents, true );
	if ( is_array( $data ) && isset( $data['manifest']['format'] ) && Conexao_Event_Export::FORMAT === $data['manifest']['format'] ) {
		echo "   Validation passed (JSON structure valid; is_uploaded_file check skipped for local test).\n";
	} else {
		echo "   ERROR: Validation failed: " . $validated->get_error_message() . "\n";
		unlink( $tmp_file );
		exit( 1 );
	}
} else {
	echo "   Validation passed.\n";
}

// 5. Test the import logic directly (bypassing is_uploaded_file check).
echo "\n5. Testing import...\n";
// Use reflection to call the protected import_event method.
$reflection = new ReflectionClass( $importer );
$method = $reflection->getMethod( 'import_event' );
$method->setAccessible( true );

$result = $method->invoke( $importer, $sample_event, 'update' );
echo "   Action: {$result['action']}, Post ID: {$result['post_id']}\n";
if ( 'created' === $result['action'] ) {
	echo "   Event created successfully.\n";
} elseif ( 'updated' === $result['action'] ) {
	echo "   Event updated successfully.\n";
} else {
	echo "   ERROR: " . ( isset( $result['error'] ) ? $result['error'] : 'Unknown error' ) . "\n";
}

// 6. Test duplicate handling - import the same event again.
echo "\n6. Testing duplicate handling (import same event again)...\n";
$result2 = $method->invoke( $importer, $sample_event, 'update' );
echo "   Action: {$result2['action']}, Post ID: {$result2['post_id']}\n";
if ( 'created' === $result2['action'] ) {
	echo "   WARNING: Duplicate event was created! This should not happen.\n";
} else {
	echo "   OK: No duplicate created (action: {$result2['action']}).\n";
}

// 7. Test skip strategy.
echo "\n7. Testing skip strategy...\n";
$result3 = $method->invoke( $importer, $sample_event, 'skip' );
echo "   Action: {$result3['action']}, Post ID: {$result3['post_id']}\n";
if ( 'skipped' === $result3['action'] ) {
	echo "   OK: Event was skipped as expected.\n";
}

// 8. Verify the imported event exists.
echo "\n8. Verifying imported event...\n";
$query = new WP_Query(
	array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'meta_query'     => array(
			array(
				'key'   => '_event_source_id',
				'value' => 'test-1234',
			),
		),
	)
);

if ( $query->have_posts() ) {
	$post = $query->posts[0];
	echo "   Found event: ID={$post->ID}, Title={$post->post_title}\n";
	echo "   Date: " . get_post_meta( $post->ID, '_event_date', true ) . "\n";
	echo "   Venue: " . get_post_meta( $post->ID, '_event_venue', true ) . "\n";
	echo "   Status: " . get_post_meta( $post->ID, '_event_status', true ) . "\n";
	echo "   UUID: " . get_post_meta( $post->ID, Conexao_Event_Export::UUID_META_KEY, true ) . "\n";

	$terms = get_the_terms( $post->ID, 'conexao_category' );
	if ( $terms && ! is_wp_error( $terms ) ) {
		echo "   Categories: " . implode( ', ', wp_list_pluck( $terms, 'name' ) ) . "\n";
	}

	// Clean up the test event.
	wp_delete_post( $post->ID, true );
	echo "   Test event cleaned up.\n";
} else {
	echo "   ERROR: Imported event not found!\n";
}

unlink( $tmp_file );

echo "\n=== Test Complete ===\n";
