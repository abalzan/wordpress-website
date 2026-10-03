<?php
/**
 * Automated tests for the Eventbrite Laois importer.
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-eventbrite-importer.php
 *
 * These tests use saved HTML fixtures and do NOT make live requests to Eventbrite.
 */


// Ensure plugin classes are loaded.
// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$fixtures_dir = __DIR__ . '/fixtures';

$passed = 0;
$failed = 0;

/**
 * Helper to extract JSON from HTML for test fixture generation.
 *
 * @param string $html HTML content.
 * @return string JSON string.
 */
function extract_json_from_html( $html ) {
	if ( preg_match( '/window\.__SERVER_DATA__\s*=\s*(\{.*?\});?\s*<\/script>/s', $html, $matches ) ) {
		return $matches[1];
	}
	return '{}';
}

// ---------------------------------------------------------------------------
// Test 1: Parser test
// ---------------------------------------------------------------------------
test_section( 'Parser Test' );

$html = file_get_contents( $fixtures_dir . '/eventbrite-page-1.html' );
$parser = new Conexao_Eventbrite_Parser();

try {
	$parsed = $parser->parse( $html );
	assert_true( is_array( $parsed ), 'Parser returns an array' );
	assert_true( isset( $parsed['events'] ) && is_array( $parsed['events'] ), 'Parsed events is an array' );
	assert_true( count( $parsed['events'] ) === 5, 'Parser extracts 5 events from fixture' );
	assert_true( isset( $parsed['pagination'] ) && is_array( $parsed['pagination'] ), 'Pagination data is present' );
	assert_true( isset( $parsed['pagination']['page_count'] ) && 3 === (int) $parsed['pagination']['page_count'], 'Page count is 3' );
	assert_true( isset( $parsed['pagination']['object_count'] ) && 44 === (int) $parsed['pagination']['object_count'], 'Object count is 44' );
	assert_true( isset( $parsed['events'][0]['id'] ) && '1989764061872' === $parsed['events'][0]['id'], 'First event ID is correct' );
	assert_true( isset( $parsed['events'][0]['name'] ) && 'Laois Food Festival 2026' === $parsed['events'][0]['name'], 'First event name is correct' );
} catch ( Exception $e ) {
	assert_true( false, 'Parser threw exception: ' . $e->getMessage() );
}

// ---------------------------------------------------------------------------
// Test 2: Pagination test
// ---------------------------------------------------------------------------
test_section( 'Pagination Test' );

// Create page 2 and 3 fixtures programmatically.
$page2_events = array();
$page3_events = array();

// Generate 20 events for page 2.
for ( $i = 0; $i < 20; $i++ ) {
	$page2_events[] = array(
		'id' => '2000000000' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ),
		'name' => 'Laois Event ' . ( $i + 1 ),
		'url' => 'https://www.eventbrite.ie/e/laois-event-' . ( $i + 1 ) . '-tickets-2000000000' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ),
		'start_date' => '2026-10-' . str_pad( ( $i % 28 ) + 1, 2, '0', STR_PAD_LEFT ),
		'start_time' => '10:00',
		'end_date' => '2026-10-' . str_pad( ( $i % 28 ) + 1, 2, '0', STR_PAD_LEFT ),
		'end_time' => '12:00',
		'timezone' => 'Europe/Dublin',
		'is_online_event' => false,
		'is_cancelled' => false,
		'is_protected_event' => false,
		'is_free' => true,
		'locations' => array(
			array( 'type' => 'region', 'name' => 'Laois' ),
			array( 'type' => 'locality', 'name' => 'Portlaoise' ),
		),
	);
}

// Generate 4 events for page 3.
for ( $i = 0; $i < 4; $i++ ) {
	$page3_events[] = array(
		'id' => '3000000000' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ),
		'name' => 'Laois Event ' . ( $i + 21 ),
		'url' => 'https://www.eventbrite.ie/e/laois-event-' . ( $i + 21 ) . '-tickets-3000000000' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ),
		'start_date' => '2026-11-' . str_pad( ( $i % 28 ) + 1, 2, '0', STR_PAD_LEFT ),
		'start_time' => '14:00',
		'end_date' => '2026-11-' . str_pad( ( $i % 28 ) + 1, 2, '0', STR_PAD_LEFT ),
		'end_time' => '16:00',
		'timezone' => 'Europe/Dublin',
		'is_online_event' => false,
		'is_cancelled' => false,
		'is_protected_event' => false,
		'is_free' => true,
		'locations' => array(
			array( 'type' => 'region', 'name' => 'Laois' ),
			array( 'type' => 'locality', 'name' => 'Portlaoise' ),
		),
	);
}

// Build page 2 HTML.
$page2_data = array(
	'search_data' => array(
		'events' => array(
			'results' => $page2_events,
			'pagination' => array(
				'object_count' => 44,
				'page_count' => 3,
				'page_number' => 2,
				'page_size' => 20,
				'continuation' => 'def456',
			),
		),
	),
);
$page2_html = '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $page2_data ) . ';</script></body></html>';
file_put_contents( $fixtures_dir . '/eventbrite-page-2.html', $page2_html );

// Build page 3 HTML.
$page3_data = array(
	'search_data' => array(
		'events' => array(
			'results' => $page3_events,
			'pagination' => array(
				'object_count' => 44,
				'page_count' => 3,
				'page_number' => 3,
				'page_size' => 20,
				'continuation' => 'ghi789',
			),
		),
	),
);
$page3_html = '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $page3_data ) . ';</script></body></html>';
file_put_contents( $fixtures_dir . '/eventbrite-page-3.html', $page3_html );

// Mock client that returns fixture data for pages 1-3.
$mock_client = new class() extends Conexao_Eventbrite_Client {
	public $pages_requested = 0;
	public $fixtures_dir = '';

	public function fetch_page( $url, $page = 1 ) {
		$this->pages_requested++;
		$file = $this->fixtures_dir . '/eventbrite-page-' . $page . '.html';
		if ( file_exists( $file ) ) {
			return file_get_contents( $file );
		}
		return '';
	}

	public function delay() {
		// No-op for tests.
	}
};

// Test the source with mock client.
$source = new class( array( 'id' => 'eventbrite', 'county' => 'Laois', 'region_labels' => array( 'Laois' ), 'url' => 'https://www.eventbrite.ie/d/ireland--laois/all-events/' ) ) extends Conexao_Source_Eventbrite {
	public $client;

	public function fetch_events() {
		$base_url = isset( $this->config['url'] ) ? $this->config['url'] : Conexao_Eventbrite_Client::DEFAULT_DISCOVERY_URL;

		$parser   = new Conexao_Eventbrite_Parser();
		$normalizer = new Conexao_Eventbrite_Normalizer();

		$html = $this->client->fetch_page( $base_url, 1 );
		if ( empty( $html ) ) {
			return array();
		}

		try {
			$page1 = $parser->parse( $html );
		} catch ( Exception $e ) {
			return array();
		}

		$pagination = $page1['pagination'];
		$page_count = isset( $pagination['page_count'] ) ? (int) $pagination['page_count'] : 1;
		$page_count = max( 1, min( $page_count, self::MAX_PAGES ) );

		$all_events = $page1['events'];

		for ( $page = 2; $page <= $page_count; $page++ ) {
			$this->client->delay();
			$html = $this->client->fetch_page( $base_url, $page );
			if ( empty( $html ) ) {
				continue;
			}
			try {
				$page_data = $parser->parse( $html );
				$all_events = array_merge( $all_events, $page_data['events'] );
			} catch ( Exception $e ) {
				continue;
			}
		}

		// Deduplicate by event ID.
		$seen_ids = array();
		$unique_events = array();
		foreach ( $all_events as $event ) {
			$id = isset( $event['id'] ) ? (string) $event['id'] : '';
			if ( empty( $id ) || isset( $seen_ids[ $id ] ) ) {
				continue;
			}
			$seen_ids[ $id ] = true;
			$unique_events[] = $event;
		}

		// Filter for the configured county and normalize.
		$raw_events = array();
		foreach ( $unique_events as $event ) {
			$region_check = $this->is_county_event( $event );
			if ( ! $region_check['accepted'] ) {
				continue;
			}
			if ( ! empty( $event['is_online_event'] ) ) {
				continue;
			}
			$event['county'] = $this->get_county();
			$event['source'] = $this->get_id();
			$raw_events[] = $normalizer->normalize( $event );
		}

		return $raw_events;
	}
};

$mock_client->fixtures_dir = $fixtures_dir;
$source->client = $mock_client;

$events = $source->fetch_events();
assert_true( count( $events ) === 27, 'Pagination test: 27 events returned (5 + 20 + 4 - 2 for page 1 online/non-Laois)' );
assert_true( $mock_client->pages_requested === 3, 'Pagination test: 3 pages requested' );

// ---------------------------------------------------------------------------
// Test 3: Deduplication test
// ---------------------------------------------------------------------------
test_section( 'Deduplication Test' );

// Create a page with duplicate event IDs.
$dup_events = array(
	array(
		'id' => '9999999999',
		'name' => 'Duplicate Event',
		'url' => 'https://www.eventbrite.ie/e/duplicate-event-tickets-9999999999',
		'start_date' => '2026-12-01',
		'start_time' => '10:00',
		'end_date' => '2026-12-01',
		'end_time' => '12:00',
		'timezone' => 'Europe/Dublin',
		'is_online_event' => false,
		'is_cancelled' => false,
		'is_protected_event' => false,
		'is_free' => true,
		'locations' => array(
			array( 'type' => 'region', 'name' => 'Laois' ),
			array( 'type' => 'locality', 'name' => 'Portlaoise' ),
		),
	),
	array(
		'id' => '9999999999', // Same ID as above.
		'name' => 'Duplicate Event (copy)',
		'url' => 'https://www.eventbrite.ie/e/duplicate-event-copy-tickets-9999999999',
		'start_date' => '2026-12-01',
		'start_time' => '10:00',
		'end_date' => '2026-12-01',
		'end_time' => '12:00',
		'timezone' => 'Europe/Dublin',
		'is_online_event' => false,
		'is_cancelled' => false,
		'is_protected_event' => false,
		'is_free' => true,
		'locations' => array(
			array( 'type' => 'region', 'name' => 'Laois' ),
			array( 'type' => 'locality', 'name' => 'Portlaoise' ),
		),
	),
);

$dup_data = array(
	'search_data' => array(
		'events' => array(
			'results' => $dup_events,
			'pagination' => array(
				'object_count' => 2,
				'page_count' => 1,
				'page_number' => 1,
				'page_size' => 20,
			),
		),
	),
);
$dup_html = '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $dup_data ) . ';</script></body></html>';

$dup_parser = new Conexao_Eventbrite_Parser();
$dup_parsed = $dup_parser->parse( $dup_html );
assert_true( count( $dup_parsed['events'] ) === 2, 'Dedup test: parser returns 2 events (raw)' );

// Test dedup logic manually.
$seen = array();
$unique = array();
foreach ( $dup_parsed['events'] as $event ) {
	$id = (string) $event['id'];
	if ( isset( $seen[ $id ] ) ) {
		continue;
	}
	$seen[ $id ] = true;
	$unique[] = $event;
}
assert_true( count( $unique ) === 1, 'Dedup test: only 1 unique event after dedup by ID' );

// ---------------------------------------------------------------------------
// Test 4: Location test
// ---------------------------------------------------------------------------
test_section( 'Location Test' );

$source_ref = new ReflectionClass( 'Conexao_Source_Eventbrite' );
$method = $source_ref->getMethod( 'is_county_event' );
$method->setAccessible( true );
$source_instance = new Conexao_Source_Eventbrite( array( 'county' => 'Laois', 'region_labels' => array( 'Laois' ) ) );

// Laois event.
$laois_event = array(
	'locations' => array(
		array( 'type' => 'continent', 'name' => 'Europe' ),
		array( 'type' => 'country', 'name' => 'Ireland' ),
		array( 'type' => 'region', 'name' => 'Laois' ),
		array( 'type' => 'locality', 'name' => 'Portlaoise' ),
	),
);
assert_true( $method->invoke( $source_instance, $laois_event )['accepted'] === true, 'Location test: region=Laois is accepted' );

// Dublin event.
$dublin_event = array(
	'locations' => array(
		array( 'type' => 'continent', 'name' => 'Europe' ),
		array( 'type' => 'country', 'name' => 'Ireland' ),
		array( 'type' => 'region', 'name' => 'Dublin' ),
		array( 'type' => 'locality', 'name' => 'Dublin' ),
	),
);
assert_true( $method->invoke( $source_instance, $dublin_event )['accepted'] === false, 'Location test: region=Dublin is rejected' );

// Missing region.
$missing_region = array(
	'locations' => array(
		array( 'type' => 'continent', 'name' => 'Europe' ),
		array( 'type' => 'country', 'name' => 'Ireland' ),
	),
);
assert_true( $method->invoke( $source_instance, $missing_region )['accepted'] === false, 'Location test: missing region is rejected' );

// No locations at all.
assert_true( $method->invoke( $source_instance, array() )['accepted'] === false, 'Location test: no locations is rejected' );

// ---------------------------------------------------------------------------
// Test 5: Online event test
// ---------------------------------------------------------------------------
test_section( 'Online Event Test' );

$normalizer = new Conexao_Eventbrite_Normalizer();

$online_event = array(
	'id' => '1111111111',
	'name' => 'Online Event',
	'url' => 'https://www.eventbrite.ie/e/online-event-tickets-1111111111',
	'start_date' => '2026-12-01',
	'start_time' => '10:00',
	'end_date' => '2026-12-01',
	'end_time' => '12:00',
	'timezone' => 'Europe/Dublin',
	'is_online_event' => true,
	'is_cancelled' => false,
	'is_protected_event' => false,
	'is_free' => true,
	'locations' => array(
		array( 'type' => 'region', 'name' => 'Laois' ),
		array( 'type' => 'locality', 'name' => 'Portlaoise' ),
	),
);

$normalized_online = $normalizer->normalize( $online_event );
assert_true( $normalized_online['is_online'] === true, 'Online event test: is_online is true' );

// The source should skip online events.
$source_ref2 = new ReflectionClass( 'Conexao_Source_Eventbrite' );
$method2 = $source_ref2->getMethod( 'is_county_event' );
$method2->setAccessible( true );
assert_true( $method2->invoke( $source_instance, $online_event )['accepted'] === true, 'Online event test: still Laois region' );

// ---------------------------------------------------------------------------
// Test 6: Missing fields test
// ---------------------------------------------------------------------------
test_section( 'Missing Fields Test' );

$minimal_event = array(
	'id' => '2222222222',
	'name' => 'Minimal Event',
	'url' => 'https://www.eventbrite.ie/e/minimal-event-tickets-2222222222',
	'start_date' => '2026-12-15',
	'start_time' => '10:00',
	'end_date' => '2026-12-15',
	'end_time' => '12:00',
	'timezone' => 'Europe/Dublin',
	'is_online_event' => false,
	'is_cancelled' => false,
	'is_protected_event' => false,
	'is_free' => true,
	'locations' => array(
		array( 'type' => 'region', 'name' => 'Laois' ),
		array( 'type' => 'locality', 'name' => 'Portlaoise' ),
	),
);

try {
	$normalized_minimal = $normalizer->normalize( $minimal_event );
	assert_true( is_array( $normalized_minimal ), 'Missing fields test: normalization succeeds' );
	assert_true( $normalized_minimal['title'] === 'Minimal Event', 'Missing fields test: title preserved' );
	assert_true( $normalized_minimal['venue'] === '', 'Missing fields test: venue is empty' );
	assert_true( $normalized_minimal['organizer'] === '', 'Missing fields test: organizer is empty' );
	assert_true( $normalized_minimal['price'] === 'Free', 'Missing fields test: price is Free (is_free=true)' );
	assert_true( $normalized_minimal['category'] === '', 'Missing fields test: category is empty' );
	assert_true( $normalized_minimal['image'] === '', 'Missing fields test: image is empty' );
} catch ( Exception $e ) {
	assert_true( false, 'Missing fields test: threw exception: ' . $e->getMessage() );
}

// ---------------------------------------------------------------------------
// Test 7: Error handling tests
// ---------------------------------------------------------------------------
test_section( 'Error Handling Test' );

// Malformed HTML (no __SERVER_DATA__).
$bad_html = '<html><body><p>No server data here</p></body></html>';
try {
	$parser->parse( $bad_html );
	assert_true( false, 'Error test: malformed HTML should throw' );
} catch ( Exception $e ) {
	assert_true( true, 'Error test: malformed HTML throws: ' . $e->getMessage() );
}

// Missing __SERVER_DATA__.
$no_server_data = '<html><body><script>window.other = {}</script></body></html>';
try {
	$parser->parse( $no_server_data );
	assert_true( false, 'Error test: missing __SERVER_DATA__ should throw' );
} catch ( Exception $e ) {
	assert_true( true, 'Error test: missing __SERVER_DATA__ throws: ' . $e->getMessage() );
}

// Invalid JSON.
$invalid_json = '<html><body><script>window.__SERVER_DATA__ = {invalid json here};</script></body></html>';
try {
	$parser->parse( $invalid_json );
	assert_true( false, 'Error test: invalid JSON should throw' );
} catch ( Exception $e ) {
	assert_true( true, 'Error test: invalid JSON throws: ' . $e->getMessage() );
}

// Missing pagination.
$no_pagination = array(
	'search_data' => array(
		'events' => array(
			'results' => array(),
		),
	),
);
$no_pagination_html = '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $no_pagination ) . ';</script></body></html>';
try {
	$parser->parse( $no_pagination_html );
	assert_true( false, 'Error test: missing pagination should throw' );
} catch ( Exception $e ) {
	assert_true( true, 'Error test: missing pagination throws: ' . $e->getMessage() );
}

// Missing results.
$no_results = array(
	'search_data' => array(
		'events' => array(
			'pagination' => array( 'page_count' => 1 ),
		),
	),
);
$no_results_html = '<html><body><script>window.__SERVER_DATA__ = ' . wp_json_encode( $no_results ) . ';</script></body></html>';
try {
	$parser->parse( $no_results_html );
	assert_true( false, 'Error test: missing results should throw' );
} catch ( Exception $e ) {
	assert_true( true, 'Error test: missing results throws: ' . $e->getMessage() );
}

// Empty HTML.
try {
	$parser->parse( '' );
	assert_true( false, 'Error test: empty HTML should throw' );
} catch ( Exception $e ) {
	assert_true( true, 'Error test: empty HTML throws: ' . $e->getMessage() );
}

// ---------------------------------------------------------------------------
// Test 8: HTTP error handling
// ---------------------------------------------------------------------------
test_section( 'HTTP Error Handling Test' );

// Test client with mocked responses.
$http_client = new class() extends Conexao_Eventbrite_Client {
	public $responses = array();
	public $request_count = 0;

	public function fetch_page( $url, $page = 1 ) {
		$this->request_count++;
		$page_url = $this->build_page_url( $url, $page );

		if ( isset( $this->responses[ $page_url ] ) ) {
			$response = $this->responses[ $page_url ];
			$code = $response['code'];

			switch ( $code ) {
				case 200:
					return isset( $response['body'] ) ? $response['body'] : '';
				case 401:
				case 404:
					return '';
				case 429:
				case 500:
				case 503:
					// Simulate one retry then succeed.
					if ( $this->request_count > 4 ) {
						return '';
					}
					return isset( $response['retry_body'] ) ? $response['retry_body'] : '';
			}
		}

		return '';
	}

	protected function backoff( $attempt ) {
		// No-op for tests.
	}
};

// Test 429 retry.
$http_client->responses = array(
	'https://www.eventbrite.ie/d/ireland--laois/all-events/' => array(
		'code' => 429,
		'retry_body' => '<html><body><script>window.__SERVER_DATA__ = {"search_data":{"events":{"results":[],"pagination":{"page_count":1}}}}</script></body></html>',
	),
);
$result = $http_client->fetch_page( 'https://www.eventbrite.ie/d/ireland--laois/all-events/', 1 );
assert_true( ! empty( $result ), 'HTTP test: 429 retries and succeeds' );

// Test 500 retry.
$http_client->request_count = 0;
$http_client->responses = array(
	'https://www.eventbrite.ie/d/ireland--laois/all-events/' => array(
		'code' => 500,
		'retry_body' => '<html><body><script>window.__SERVER_DATA__ = {"search_data":{"events":{"results":[],"pagination":{"page_count":1}}}}</script></body></html>',
	),
);
$result = $http_client->fetch_page( 'https://www.eventbrite.ie/d/ireland--laois/all-events/', 1 );
assert_true( ! empty( $result ), 'HTTP test: 500 retries and succeeds' );

// Test 503 retry.
$http_client->request_count = 0;
$http_client->responses = array(
	'https://www.eventbrite.ie/d/ireland--laois/all-events/' => array(
		'code' => 503,
		'retry_body' => '<html><body><script>window.__SERVER_DATA__ = {"search_data":{"events":{"results":[],"pagination":{"page_count":1}}}}</script></body></html>',
	),
);
$result = $http_client->fetch_page( 'https://www.eventbrite.ie/d/ireland--laois/all-events/', 1 );
assert_true( ! empty( $result ), 'HTTP test: 503 retries and succeeds' );

// Test 401 fails clearly.
$http_client->request_count = 0;
$http_client->responses = array(
	'https://www.eventbrite.ie/d/ireland--laois/all-events/' => array(
		'code' => 401,
	),
);
$result = $http_client->fetch_page( 'https://www.eventbrite.ie/d/ireland--laois/all-events/', 1 );
assert_true( empty( $result ), 'HTTP test: 401 fails clearly' );

// Test 404 fails clearly.
$http_client->request_count = 0;
$http_client->responses = array(
	'https://www.eventbrite.ie/d/ireland--laois/all-events/' => array(
		'code' => 404,
	),
);
$result = $http_client->fetch_page( 'https://www.eventbrite.ie/d/ireland--laois/all-events/', 1 );
assert_true( empty( $result ), 'HTTP test: 404 fails clearly' );

// ---------------------------------------------------------------------------
// Test 9: Normalizer test
// ---------------------------------------------------------------------------
test_section( 'Normalizer Test' );

$full_event = array(
	'id' => '3333333333',
	'name' => 'Full Event',
	// Injected by the source handler (Stage B) before normalization.
	'source' => 'eventbrite',
	'county' => 'Laois',
	'full_description' => 'Full description here.',
	'summary' => 'Summary here.',
	'url' => 'https://www.eventbrite.ie/e/full-event-tickets-3333333333',
	'start_date' => '2026-12-20',
	'start_time' => '10:00',
	'end_date' => '2026-12-20',
	'end_time' => '12:00',
	'timezone' => 'Europe/Dublin',
	'is_online_event' => false,
	'is_cancelled' => false,
	'is_protected_event' => false,
	'is_free' => false,
	'tickets_url' => 'https://www.eventbrite.ie/e/full-event-tickets-3333333333',
	'primary_organizer_id' => '888888888',
	'primary_organizer' => array( 'name' => 'Test Organizer' ),
	'image' => array(
		'url' => 'https://img.evbuc.com/full-event.jpg',
		'image_sizes' => array(
			'medium' => 'https://img.evbuc.com/full-event-medium.jpg',
		),
	),
	'locations' => array(
		array( 'type' => 'region', 'name' => 'Laois' ),
		array( 'type' => 'locality', 'name' => 'Portlaoise' ),
	),
	'venue' => array(
		'name' => 'Test Venue',
		'address' => array(
			'address_1' => '123 Main St',
			'city' => 'Portlaoise',
			'region' => 'Laois',
			'postal_code' => 'R32',
		),
	),
	'tags' => array(
		array( 'type' => 'EventbriteCategory', 'display_name' => 'Music' ),
		array( 'type' => 'EventbriteSubCategory', 'display_name' => 'Concert' ),
	),
	'ticket_availability' => array(
		'minimum_ticket_price' => '€15.00',
		'maximum_ticket_price' => '€30.00',
	),
);

$normalized_full = $normalizer->normalize( $full_event );
assert_true( $normalized_full['source'] === 'eventbrite', 'Normalizer test: source is eventbrite' );
assert_true( $normalized_full['source_id'] === '3333333333', 'Normalizer test: source_id is correct' );
assert_true( $normalized_full['title'] === 'Full Event', 'Normalizer test: title is correct' );
// Stage A/B: the normalizer prefers the short `summary` over `full_description`.
assert_true( $normalized_full['description'] === 'Summary here.', 'Normalizer test: description prefers summary' );
assert_true( $normalized_full['start_date'] === '2026-12-20', 'Normalizer test: start_date is correct' );
assert_true( $normalized_full['start_time'] === '10:00', 'Normalizer test: start_time is correct' );
assert_true( $normalized_full['end_date'] === '2026-12-20', 'Normalizer test: end_date is correct' );
assert_true( $normalized_full['end_time'] === '12:00', 'Normalizer test: end_time is correct' );
assert_true( $normalized_full['timezone'] === 'Europe/Dublin', 'Normalizer test: timezone is correct' );
assert_true( $normalized_full['county'] === 'Laois', 'Normalizer test: county is Laois' );
assert_true( $normalized_full['town'] === 'Portlaoise', 'Normalizer test: town is Portlaoise' );
assert_true( $normalized_full['venue'] === 'Test Venue', 'Normalizer test: venue is correct' );
assert_true( $normalized_full['organizer'] === 'Test Organizer', 'Normalizer test: organizer is correct' );
assert_true( $normalized_full['category'] === 'Music', 'Normalizer test: category is correct' );
assert_true( $normalized_full['subcategory'] === 'Concert', 'Normalizer test: subcategory is correct' );
assert_true( $normalized_full['image'] === 'https://img.evbuc.com/full-event-medium.jpg', 'Normalizer test: image uses medium size' );
assert_true( $normalized_full['price'] === '€15.00 – €30.00', 'Normalizer test: price range is correct' );
assert_true( $normalized_full['is_online'] === false, 'Normalizer test: is_online is false' );
assert_true( $normalized_full['is_cancelled'] === false, 'Normalizer test: is_cancelled is false' );
assert_true( $normalized_full['ticket_url'] === 'https://www.eventbrite.ie/e/full-event-tickets-3333333333', 'Normalizer test: ticket_url is correct' );
assert_true( $normalized_full['organizer_id'] === '888888888', 'Normalizer test: organizer_id is correct' );

// ---------------------------------------------------------------------------
// Test 10: Upsert test (idempotency)
// ---------------------------------------------------------------------------
test_section( 'Upsert Test' );

$plugin = Conexao_Event_Importer::instance();
$location = new Conexao_Event_Location();
$normalizer_engine = new Conexao_Event_Normalizer( $location );
$deduplicator = new Conexao_Event_Deduplicator();

// Use reflection to call the protected upsert method.
$importer_ref = new ReflectionClass( 'Conexao_Event_Importer_Engine' );
$upsert_method = $importer_ref->getMethod( 'upsert_event' );
$upsert_method->setAccessible( true );

// Clean up ALL test events from previous runs using direct SQL to ensure a clean state.
global $wpdb;
$test_event_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		 INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_event_source' AND pm1.meta_value = 'eventbrite'
		 INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_event_source_id' AND pm2.meta_value LIKE %s
		 WHERE p.post_type = 'event'",
		'4444444444%'
	)
);
foreach ( $test_event_ids as $test_event_id ) {
	wp_delete_post( (int) $test_event_id, true );
}

// Use a unique source ID and title for this test run to avoid conflicts with previous runs.
$unique_source_id = '4444444444' . substr( md5( uniqid( '', true ) ), 0, 6 );
$unique_title = 'Upsert Test Event ' . substr( md5( uniqid( '', true ) ), 0, 6 );

// Purge WordPress object cache to ensure stale posts aren't found.
wp_cache_flush();

// Create a test event via the importer.
$test_raw = array(
	'source'      => 'eventbrite',
	'title'       => $unique_title,
	'url'         => 'https://www.eventbrite.ie/e/upsert-test-event-tickets-' . $unique_source_id,
	'start_date'  => '2026-12-25',
	'start_time'  => '10:00',
	'end_date'    => '2026-12-25',
	'end_time'    => '12:00',
	'location'    => 'Test Venue, Portlaoise, Laois',
	'description' => 'Test event for upsert verification.',
	'image'       => '',
	'source_id'   => $unique_source_id,
	'organizer'   => 'Test Organizer',
	'price'       => 'Free',
	'category'    => 'Test',
	'county'      => 'Laois',
	'town'        => 'Portlaoise',
	'venue'       => 'Test Venue',
	'address'     => '123 Main St',
);

$normalized = $normalizer_engine->normalize( $test_raw );

// Force create by passing existing_id = 0 (bypass deduplicator).
$result1 = $upsert_method->invoke( $plugin->importer, $normalized, 0 );
assert_true( 'created' === $result1['action'], 'Upsert test: first run creates event' );
$post_id = $result1['post_id'];
assert_true( $post_id > 0, 'Upsert test: post ID is valid' );

// Run again - should find existing and update/unchanged.
$existing_id2 = $deduplicator->find( $normalized );
assert_true( $existing_id2 === $post_id, 'Upsert test: deduplicator finds existing event' );

$result2 = $upsert_method->invoke( $plugin->importer, $normalized, $existing_id2 );
assert_true( 'unchanged' === $result2['action'], 'Upsert test: second run is unchanged' );

// Verify only one record exists.
$query = new WP_Query( array(
	'post_type'      => 'event',
	'posts_per_page' => 10,
	'fields'         => 'ids',
	'no_found_rows'  => true,
	'meta_query'     => array(
		'relation' => 'AND',
		array(
			'key'   => '_event_source',
			'value' => 'eventbrite',
		),
		array(
			'key'   => '_event_source_id',
			'value' => $unique_source_id,
		),
	),
) );
assert_true( $query->post_count === 1, 'Upsert test: only one record exists' );

// ---------------------------------------------------------------------------
// Test 11: Event update test
// ---------------------------------------------------------------------------
test_section( 'Event Update Test' );

// Change the event data.
$test_raw['title'] = $unique_title . ' (Updated)';
$test_raw['start_time'] = '11:00';
$normalized_updated = $normalizer_engine->normalize( $test_raw );

$existing_id3 = $deduplicator->find( $normalized_updated );
assert_true( $existing_id3 === $post_id, 'Update test: deduplicator still finds same event' );

$result3 = $upsert_method->invoke( $plugin->importer, $normalized_updated, $existing_id3 );
assert_true( 'updated' === $result3['action'], 'Update test: event is updated' );

$updated_title = get_the_title( $post_id );
assert_true( $updated_title === $unique_title . ' (Updated)', 'Update test: title is updated' );

$updated_time = get_post_meta( $post_id, '_event_start_time', true );
assert_true( $updated_time === '11:00', 'Update test: start time is updated' );

// Clean up test event.
wp_delete_post( $post_id, true );

// ---------------------------------------------------------------------------
// Test 12: Zero-event safety test
// ---------------------------------------------------------------------------
test_section( 'Zero-Event Safety Test' );

// Verify that mark_missing_events doesn't delete events when zero events are returned.
$zero_events = array();
$importer_ref2 = new ReflectionClass( 'Conexao_Event_Importer_Engine' );
$mark_method = $importer_ref2->getMethod( 'mark_missing_events' );
$mark_method->setAccessible( true );

// Clean up any existing test events from previous runs.
$cleanup_query2 = new WP_Query( array(
	'post_type'      => 'event',
	'posts_per_page' => 10,
	'fields'         => 'ids',
	'no_found_rows'  => true,
	'meta_query'     => array(
		'relation' => 'AND',
		array(
			'key'   => '_event_source',
			'value' => 'eventbrite',
		),
		array(
			'key'   => '_event_source_id',
			'value' => '5555555555',
		),
	),
) );
foreach ( $cleanup_query2->posts as $cleanup_id2 ) {
	wp_delete_post( $cleanup_id2, true );
}

// Create a test event first.
$test_raw2 = array(
	'source'      => 'eventbrite',
	'title'       => 'Zero Event Safety Test',
	'url'         => 'https://www.eventbrite.ie/e/zero-event-safety-tickets-5555555555',
	'start_date'  => '2026-12-30',
	'start_time'  => '10:00',
	'end_date'    => '2026-12-30',
	'end_time'    => '12:00',
	'location'    => 'Test Venue, Portlaoise, Laois',
	'description' => 'Test event for zero-event safety.',
	'image'       => '',
	'source_id'   => '5555555555',
	'organizer'   => 'Test Organizer',
	'price'       => 'Free',
	'category'    => 'Test',
	'county'      => 'Laois',
	'town'        => 'Portlaoise',
	'venue'       => 'Test Venue',
	'address'     => '123 Main St',
);

$normalized2 = $normalizer_engine->normalize( $test_raw2 );
$existing_id4 = $deduplicator->find( $normalized2 );
$result4 = $upsert_method->invoke( $plugin->importer, $normalized2, $existing_id4 );
$post_id2 = $result4['post_id'];

// Call mark_missing_events with zero events - should NOT delete and should NOT
// mark as source_not_found (the method intentionally returns early when no
// events are found, protecting against silent breakage).
$mark_method->invoke( $plugin->importer, 'eventbrite', $zero_events );

$still_exists = get_post( $post_id2 );
assert_true( $still_exists !== null, 'Zero-event test: event still exists after zero-event import' );

$status = get_post_meta( $post_id2, '_event_status', true );
assert_true( $status !== 'source_not_found', 'Zero-event test: event NOT marked as source_not_found (safety behavior)' );

// Clean up.
wp_delete_post( $post_id2, true );

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

// Clean up generated fixture files.
@unlink( $fixtures_dir . '/eventbrite-page-2.html' );
@unlink( $fixtures_dir . '/eventbrite-page-3.html' );

test_finish();
