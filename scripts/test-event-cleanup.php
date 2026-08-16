<?php
/**
 * Test the Event Cleanup system.
 *
 * Verifies:
 *  - Past events are detected using end date/time
 *  - Future events are never deleted
 *  - Importer-created images are deleted when exclusively owned
 *  - Shared images are preserved
 *  - Manually uploaded images are preserved
 *  - Cleanup is idempotent (safe to run repeatedly)
 *
 * Usage: docker compose exec wordpress php /var/www/html/scripts/test-event-cleanup.php
 */

$wp_load = '/var/www/html/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found. Run inside the WordPress container.\n" );
	exit( 1 );
}

require_once $wp_load;

echo "=== Event Cleanup Test ===\n\n";

// Set a current user so current_user_can() works from CLI.
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( empty( $admin ) ) {
	$admin_id = wp_create_user( 'testadmin', 'testpass123', 'testadmin@example.com' );
	$user     = new WP_User( $admin_id );
	$user->set_role( 'administrator' );
	wp_set_current_user( $admin_id );
} else {
	wp_set_current_user( $admin[0]->ID );
}
echo "Current user: " . wp_get_current_user()->user_login . "\n\n";

$pass = 0;
$fail = 0;

function check( $label, $condition ) {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		echo "  ✓ {$label}\n";
	} else {
		$fail++;
		echo "  ✗ {$label}\n";
	}
}

// Ensure the cleanup class is loaded.
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-cleanup.php';
$cleanup = new Conexao_Event_Cleanup();

// Helper: create a test event with a past date.
function create_test_event( $title, $date, $end_date = '', $end_time = '' ) {
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'event',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => '<p>Test content.</p>',
		)
	);

	update_post_meta( $post_id, '_event_date', $date );
	update_post_meta( $post_id, '_event_start_time', '10:00' );
	update_post_meta( $post_id, '_event_end_date', $end_date );
	update_post_meta( $post_id, '_event_end_time', $end_time );
	update_post_meta( $post_id, '_event_status', 'published' );
	update_post_meta( $post_id, '_event_imported', '1' );

	return $post_id;
}

// Helper: create a test attachment that mimics an importer-created image.
function create_importer_attachment( $title, $source_url ) {
	$upload_dir = wp_upload_dir();
	$filename   = sanitize_file_name( sanitize_title( $title ) . '-' . substr( md5( $source_url ), 0, 10 ) . '.jpg' );
	$file_path  = $upload_dir['path'] . '/' . $filename;

	// Create a minimal valid JPEG file.
	$jpeg = "\xFF\xD8\xFF\xE0" . "\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . "\xFF\xD9";
	file_put_contents( $file_path, $jpeg );

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$file_path
	);

	update_post_meta( $attachment_id, '_event_source_url', $source_url );

	return $attachment_id;
}

// Helper: create a manually uploaded attachment (no source URL meta).
function create_manual_attachment( $title ) {
	$upload_dir = wp_upload_dir();
	$filename   = sanitize_file_name( sanitize_title( $title ) . '-' . uniqid() . '.jpg' );
	$file_path  = $upload_dir['path'] . '/' . $filename;

	$jpeg = "\xFF\xD8\xFF\xE0" . "\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00" . "\xFF\xD9";
	file_put_contents( $file_path, $jpeg );

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$file_path
	);

	return $attachment_id;
}

// Clean up any leftover test events from previous runs.
$old_events = get_posts(
	array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		's'              => 'Cleanup Test',
	)
);
foreach ( $old_events as $old_id ) {
	wp_delete_post( $old_id, true );
}

// 1. Create a past event (ended yesterday) with an importer-created image.
$past_event_id = create_test_event( 'Cleanup Test Past Event', date( 'Y-m-d', strtotime( '-2 days' ) ) );
$past_attachment_id = create_importer_attachment( 'Cleanup Test Past Image', 'https://example.com/past-event-image.jpg' );
update_post_meta( $past_event_id, '_event_banner_attachment_id', $past_attachment_id );
set_post_thumbnail( $past_event_id, $past_attachment_id );

// 2. Create a past event with an end date that already passed.
$past_end_event_id = create_test_event(
	'Cleanup Test Past End Date',
	date( 'Y-m-d', strtotime( '-5 days' ) ),
	date( 'Y-m-d', strtotime( '-3 days' ) ),
	'18:00'
);

// 3. Create a future event (should never be deleted).
$future_event_id = create_test_event( 'Cleanup Test Future Event', date( 'Y-m-d', strtotime( '+30 days' ) ) );
$future_attachment_id = create_importer_attachment( 'Cleanup Test Future Image', 'https://example.com/future-event-image.jpg' );
update_post_meta( $future_event_id, '_event_banner_attachment_id', $future_attachment_id );
set_post_thumbnail( $future_event_id, $future_attachment_id );

// 4. Create a past event with a shared image (used by another event).
$shared_attachment_id = create_importer_attachment( 'Cleanup Test Shared Image', 'https://example.com/shared-image.jpg' );
$shared_event_1 = create_test_event( 'Cleanup Test Shared Event 1', date( 'Y-m-d', strtotime( '-1 day' ) ) );
$shared_event_2 = create_test_event( 'Cleanup Test Shared Event 2', date( 'Y-m-d', strtotime( '+10 days' ) ) );
update_post_meta( $shared_event_1, '_event_banner_attachment_id', $shared_attachment_id );
update_post_meta( $shared_event_2, '_event_banner_attachment_id', $shared_attachment_id );
set_post_thumbnail( $shared_event_1, $shared_attachment_id );
set_post_thumbnail( $shared_event_2, $shared_attachment_id );

// 5. Create a past event with a manually uploaded image (no source URL).
$manual_attachment_id = create_manual_attachment( 'Cleanup Test Manual Image' );
$manual_event_id = create_test_event( 'Cleanup Test Manual Event', date( 'Y-m-d', strtotime( '-4 days' ) ) );
update_post_meta( $manual_event_id, '_event_banner_attachment_id', $manual_attachment_id );
set_post_thumbnail( $manual_event_id, $manual_attachment_id );

// 6. Create a past event with an importer image that is used in a page content.
$content_attachment_id = create_importer_attachment( 'Cleanup Test Content Image', 'https://example.com/content-image.jpg' );
$content_event_id = create_test_event( 'Cleanup Test Content Event', date( 'Y-m-d', strtotime( '-6 days' ) ) );
update_post_meta( $content_event_id, '_event_banner_attachment_id', $content_attachment_id );
set_post_thumbnail( $content_event_id, $content_attachment_id );

// Create a page that references the content image.
$content_url = wp_get_attachment_url( $content_attachment_id );
$page_id = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Cleanup Test Page',
		'post_content' => '<img src="' . esc_url( $content_url ) . '" alt="test">',
	)
);

echo "Setup complete. Running cleanup...\n\n";

// Run the cleanup.
$result = $cleanup->run_cleanup( 'manual' );

echo "Cleanup result:\n";
echo "  Events found: {$result['events_found']}\n";
echo "  Events deleted: {$result['events_deleted']}\n";
echo "  Images evaluated: {$result['images_evaluated']}\n";
echo "  Images deleted: {$result['images_deleted']}\n";
echo "  Images preserved: {$result['images_preserved']}\n";
echo "  Errors: " . count( $result['errors'] ) . "\n\n";

// Verify results.
check( 'Past event deleted', null === get_post( $past_event_id ) );
check( 'Past event with end date deleted', null === get_post( $past_end_event_id ) );
check( 'Future event preserved', null !== get_post( $future_event_id ) );
check( 'Shared event 1 deleted', null === get_post( $shared_event_1 ) );
check( 'Shared event 2 preserved', null !== get_post( $shared_event_2 ) );
check( 'Manual event deleted', null === get_post( $manual_event_id ) );
check( 'Content event deleted', null === get_post( $content_event_id ) );

// Image checks.
check( 'Past event importer image deleted', null === get_post( $past_attachment_id ) );
check( 'Future event image preserved', null !== get_post( $future_attachment_id ) );
check( 'Shared image preserved (still used by future event)', null !== get_post( $shared_attachment_id ) );
check( 'Manual image preserved (not importer-created)', null !== get_post( $manual_attachment_id ) );
check( 'Content-referenced image preserved', null !== get_post( $content_attachment_id ) );

// Run cleanup again to verify idempotency.
$result2 = $cleanup->run_cleanup( 'manual' );
check( 'Second run finds no events', 0 === $result2['events_found'] );
check( 'Second run deletes no events', 0 === $result2['events_deleted'] );
check( 'Second run has no errors', 0 === count( $result2['errors'] ) );

// Verify status and history are stored.
$status = $cleanup->get_status();
check( 'Status stored', ! empty( $status['time'] ) );
check( 'Status has events_found', isset( $status['events_found'] ) );
check( 'Status has events_deleted', isset( $status['events_deleted'] ) );
check( 'Status has images_deleted', isset( $status['images_deleted'] ) );
check( 'Status has images_preserved', isset( $status['images_preserved'] ) );

$history = $cleanup->get_history();
check( 'History recorded', count( $history ) >= 2 );

// Clean up remaining test data.
wp_delete_post( $future_event_id, true );
wp_delete_post( $shared_event_2, true );
wp_delete_post( $page_id, true );
wp_delete_attachment( $future_attachment_id, true );
wp_delete_attachment( $shared_attachment_id, true );
wp_delete_attachment( $manual_attachment_id, true );
wp_delete_attachment( $content_attachment_id, true );

// Clear test cleanup status/history.
delete_option( 'conexao_event_cleanup_status' );
delete_option( 'conexao_event_cleanup_history' );

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit( $fail > 0 ? 1 : 0 );