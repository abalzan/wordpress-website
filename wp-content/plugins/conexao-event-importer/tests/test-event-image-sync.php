<?php
/**
 * Automated tests for the Event Image Handler + Sync admin feature.
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-event-image-sync.php
 *
 * These tests mock remote HTTP downloads (no live requests to Eventbrite).
 */


// Ensure plugin classes are loaded.
// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

/**
 * One-pixel valid PNG bytes.
 */
function tiny_png_bytes() {
	return base64_decode(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
	);
}

/**
 * 1x1 JPEG bytes.
 */
function tiny_jpeg_bytes() {
	return base64_decode(
		'/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AVN//2Q=='
	);
}

/**
 * Mock a single HTTP response for wp_remote_get.
 *
 * @param int    $code  HTTP status code.
 * @param string $body  Response body.
 * @param string $type  Content-Type.
 * @param bool   $error Whether to simulate a WP_Error transport failure.
 */
function mock_http_response( $code, $body, $type = 'image/png', $error = false ) {
	if ( $error ) {
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'Connection timed out.' );
			},
			10,
			1
		);
		return;
	}

	$response = array(
		'headers'  => array( 'content-type' => $type ),
		'body'     => $body,
		'response' => array( 'code' => $code, 'message' => $code >= 400 ? 'Error' : 'OK' ),
		'cookies'  => array(),
		'filename' => '',
	);

	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) use ( $response ) {
			return $response;
		},
		10,
		3
	);
	}

	// ---------------------------------------------------------------------------
	// Test 1: is_external_image_url
	// ---------------------------------------------------------------------------
	test_section( 'External URL Detection' );
	$handler = new Conexao_Event_Image_Handler();

	assert_true( $handler->is_external_image_url( 'https://img.evbuc.com/event.jpg' ) === true, 'Eventbrite CDN URL detected as external' );
	assert_true( $handler->is_external_image_url( 'https://www.eventbrite.ie/e/123' ) === true, 'Eventbrite page URL detected as external' );

	$upload = wp_upload_dir();
	$local_url = trailingslashit( $upload['baseurl'] ) . 'event-image.jpg';
	assert_true( $handler->is_external_image_url( $local_url ) === false, 'Local uploads URL detected as local' );

	assert_true( $handler->is_external_image_url( home_url( '/event-page' ) ) === false, 'Local home URL detected as local' );

	assert_true( $handler->is_external_image_url( 'https://example.com/wp-content/uploads/2026/08/x.jpg' ) === false, 'Any /wp-content/uploads/ URL detected as local' );

	assert_true( $handler->is_external_image_url( '' ) === false, 'Empty URL is not external' );
	assert_true( $handler->is_external_image_url( 'javascript:alert(1)' ) === false, 'javascript URL is not external' );

	// ---------------------------------------------------------------------------
	// Test 2: sideload_image with mocked valid PNG
	// ---------------------------------------------------------------------------
	test_section( 'Sideload Valid PNG' );

	// Clear any previous HTTP mocks.
	remove_all_filters( 'pre_http_request' );

	$event_id = wp_insert_post( array( 'post_type' => 'event', 'post_title' => 'Image Sync Test Event', 'post_status' => 'publish' ) );
	assert_true( $event_id > 0, 'Test event created' );

	mock_http_response( 200, tiny_png_bytes(), 'image/png' );

	$url  = 'https://imgb.event.com/event-image-123456.png';
	$att_id = $handler->sideload_image( $url, $event_id, 'Image Sync Test Event' );

	assert_true( $att_id > 0, 'Sideload returns an attachment ID' );
	assert_true( wp_attachment_is_image( $att_id ), 'Attachment is an image' );

	$file = get_attached_file( $att_id );
	assert_true( file_exists( $file ), 'Attachment file exists on disk' );
	assert_true( get_post_meta( $att_id, '_event_source_url', true ) === $url, 'Source URL meta recorded' );

	$meta = wp_get_attachment_metadata( $att_id );
	assert_true( ! empty( $meta ) && isset( $meta['width'] ), 'Attachment metadata (sizes/thumbnails) generated' );

	// ---------------------------------------------------------------------------
	// Test 3: Duplicate download prevention
	// ---------------------------------------------------------------------------
	test_section( 'Duplicate Prevention' );

	remove_all_filters( 'pre_http_request' );

	// Second sideload of the same URL must reuse the attachment, not re-download.
	$downloads = array();
	remove_all_filters( 'pre_http_request' );
	add_filter(
		'pre_http_request',
		function () use ( &$downloads ) {
			$downloads[] = true;
			$response = array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => tiny_png_bytes(),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => '',
			);
			return $response;
		},
		10,
		3
	);

	$att_id2 = $handler->sideload_image( $url, $event_id, 'Image Sync Test Event' );
	assert_true( $att_id2 === $att_id, 'Same attachment returned for the same URL' );
	assert_true( count( $downloads ) === 0, 'No new HTTP request made for a duplicated URL' );

	remove_all_filters( 'pre_http_request' );

	// ---------------------------------------------------------------------------
	// Test 4: sync_event_image sets banner + featured image
	// ---------------------------------------------------------------------------
	test_section( 'sync_event_image Association' );

	// Assign an external banner to the event.
	update_post_meta( $event_id, '_event_banner', $url );

	// Event currently has no banner attachment meta.
	remove_all_filters( 'pre_http_request' );
	mock_http_response( 200, tiny_png_bytes(), 'image/png' );

	$result = $handler->sync_event_image( $event_id );
	assert_true( 'ok' === $result['status'], 'sync_event_image returns ok' );
	assert_true( $result['attachment_id'] > 0, 'sync_event_image returns an attachment ID' );

	$banner_attach = get_post_meta( $event_id, '_event_banner_attachment_id', true );
	assert_true( $banner_attach == $result['attachment_id'], '_event_banner_attachment_id meta set' );

	$thumb = get_post_thumbnail_id( $event_id );
	assert_true( $thumb == $result['attachment_id'], 'Featured image (thumbnail) set to the attachment' );

	// Running again with the same external banner should reuse the attachment.
	remove_all_filters( 'pre_http_request' );
	add_filter(
		'pre_http_request',
		function () {
			return array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => tiny_png_bytes(),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		}
	);
	$result2 = $handler->sync_event_image( $event_id );
	assert_true( 'ok' === $result2['status'], 'Second sync succeeds' );

	// Local banner → skipped.
	update_post_meta( $event_id, '_event_banner', $local_url );
	$result3 = $handler->sync_event_image( $event_id );
	assert_true( 'skipped' === $result3['status'], 'Local banner is skipped without downloading' );

	// ---------------------------------------------------------------------------
	// Test 5: Reject malicious / wrong content
	// ---------------------------------------------------------------------------
	test_section( 'Security: Reject Non-Image Content' );

	// Attempt to sideload HTML / PHP content served as text/html.
	remove_all_filters( 'pre_http_request' );
	mock_http_response( 200, '<html><body>not an image</body></html>', 'text/html' );

	$bad_att = $handler->sideload_image( 'https://evil.example.com/payload.php', $event_id, 'Bad' );
	assert_true( 0 === $bad_att, 'HTML content rejected (no attachment created)' );

	// Files that match magic bytes but disagree with Content-Type.
	remove_all_filters( 'pre_http_request' );
	mock_http_response( 200, tiny_jpeg_bytes(), 'text/html' );

	$bad_att2 = $handler->sideload_image( 'https://example.com/mismatched.jpg', $event_id, 'Mismatch' );
	assert_true( 0 === $bad_att2, 'Content-Type mismatch rejected' );

	// HTTP error status.
	remove_all_filters( 'pre_http_request' );
	mock_http_response( 403, 'Forbidden', 'image/png' );

	$bad_att3 = $handler->sideload_image( 'https://img.event.com/forbidden.png', $event_id, 'Forbidden' );
	assert_true( 0 === $bad_att3, 'HTTP 403 response rejected' );

	// Transport error.
	remove_all_filters( 'pre_http_request' );
	mock_http_response( 0, '', '', true );

	$bad_att4 = $handler->sideload_image( 'https://example.com/timeout.png', $event_id, 'Timeout' );
	assert_true( 0 === $bad_att4, 'WP_Error transport failure rejected' );

	// Arbitrary executable content (PNG magic bytes followed by PHP would be a polyglot — reject via extension/mime check,
	// but magic bytes say PNG, so test that a real PHP file is rejected).
	remove_all_filters( 'pre_http_request' );
	mock_http_response( 200, "<?php echo 'HACKED';", 'application/x-php' );

	$bad_att5 = $handler->sideload_image( 'https://example.com/shell.php', $event_id, 'Shell' );
	assert_true( 0 === $bad_att5, 'PHP payload rejected' );

	// ---------------------------------------------------------------------------
	// Test 6: GIF + WebP support
	// ---------------------------------------------------------------------------
	test_section( 'GIF + WebP Support' );

	$gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
	remove_all_filters( 'pre_http_request' );
	mock_http_response( 200, $gif, 'image/gif' );

	$gif_att = $handler->sideload_image( 'https://example.com/anim.gif', $event_id, 'GIF' );
	assert_true( $gif_att > 0, 'GIF image imported' );
	$gif_path = get_attached_file( $gif_att );
	assert_true( 'gif' === strtolower( pathinfo( $gif_path, PATHINFO_EXTENSION ) ), 'GIF extension is .gif' );

	$webp = "RIFF\xcc\x00\x00\x00WEBPVP8 \x00\x00\x00\x00\x00\x00\x00\x00";
	remove_all_filters( 'pre_http_request' );
	mock_http_response( 200, $webp, 'image/webp' );

	$webp_att = $handler->sideload_image( 'https://example.com/photo.webp', $event_id, 'WebP' );
	assert_true( $webp_att > 0, 'WebP image imported' );

	// ---------------------------------------------------------------------------
	// Test 7: Sync pending count query
	// ---------------------------------------------------------------------------
	test_section( 'Admin Sync Query' );

	// Re-assign the external banner (Test 4 left the local URL in place).
	update_post_meta( $event_id, '_event_banner', $url );

	$sync = new Conexao_Event_Image_Sync_Admin();
	$ref  = new ReflectionClass( $sync );
	$method = $ref->getMethod( 'find_events_with_external_banners' );
	$method->setAccessible( true );

	$found = $method->invoke( $sync, 200 );
	assert_true( in_array( $event_id, $found, true ), 'Test event with external banner is found by the sync query' );

	// ---------------------------------------------------------------------------
	// Cleanup
	// ---------------------------------------------------------------------------
	foreach ( array( $att_id, $att_id2, $gif_att, $webp_att ) as $cleanup_att ) {
		if ( $cleanup_att && $cleanup_att > 0 ) {
			wp_delete_attachment( $cleanup_att, true );
		}
	}
	wp_delete_post( $event_id, true );

	remove_all_filters( 'pre_http_request' );

test_finish();
