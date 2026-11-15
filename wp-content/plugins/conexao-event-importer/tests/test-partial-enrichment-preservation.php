<?php
/**
 * Stage 2 / P5 — partial-enrichment state and field-level data preservation.
 *
 * ## The defect this locks down
 *
 * Heritage Week enriches an event from its detail page. When that detail
 * request failed, the handler returned a listing-only event: `description`,
 * `venue` and `address` were empty strings. The engine then wrote those empty
 * values over the stored record, erasing data a previous successful run had
 * imported.
 *
 * ## The model
 *
 * The handler declares a per-field enrichment state on the raw event
 * (`_enrichment`), the normalizer forwards it as `enrichment`, and the write
 * path replaces an incoming value with the stored one when that field is
 * `unavailable` or `not_evaluated`:
 *
 *   populated      -> write the incoming value (a correction is applied)
 *   empty          -> write the empty value   (a genuine clear is applied)
 *   unavailable    -> preserve the stored value
 *   not_evaluated  -> preserve the stored value
 *
 * A field absent from the map keeps the legacy behaviour (written as before),
 * which is what leaves Eventbrite and every listing-only source unchanged.
 *
 * ## What this suite proves
 *
 * 1. a failed detail fetch preserves description, venue, address and organiser;
 * 2. a successful detail fetch still applies a correction;
 * 3. a successful detail fetch with a PRESENT but empty description container
 *    genuinely clears the description (the documented emptiness contract);
 * 4. an ABSENT description container is not a clear — the value is preserved;
 * 5. field-level preservation: one field updated, another preserved, same run;
 * 6. new-event creation still writes incoming values normally;
 * 7. a payload with no enrichment contract (Eventbrite) still writes/clears
 *    exactly as before.
 *
 * No live provider is contacted; every request uses the `pre_http_request`
 * double and the bootstrap blocks external HTTP.
 *
 * @package Conexao_Event_Importer
 */

// Shared test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_require_operator_store_isolation();

$restore_options = test_options_isolate(
	array(
		Conexao_Import_Continuation::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

$GLOBALS['s2p_responses'] = array();
$GLOBALS['s2p_requests']  = array();
$GLOBALS['s2p_double_on'] = false;

add_filter( 'pre_http_request', 's2p_http_double', 10, 3 );

/**
 * The `pre_http_request` filter callback (exact-URL matching).
 *
 * @param false|array|WP_Error $preempt Short-circuit value.
 * @param array                $args    Request args.
 * @param string               $url     Request URL.
 * @return array|WP_Error
 */
function s2p_http_double( $preempt, $args, $url ) {
	if ( empty( $GLOBALS['s2p_double_on'] ) ) {
		return $preempt;
	}

	$GLOBALS['s2p_requests'][] = $url;

	if ( ! isset( $GLOBALS['s2p_responses'][ $url ] ) ) {
		return new WP_Error( 's2p_unmocked_url', 'No HTTP double registered for ' . $url );
	}

	$response = $GLOBALS['s2p_responses'][ $url ];

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	return array(
		'response' => array( 'code' => (int) $response['code'] ),
		'body'     => isset( $response['body'] ) ? $response['body'] : '',
		'headers'  => array(),
		'cookies'  => array(),
	);
}

/**
 * Install the HTTP double for a map of exact URL => response.
 *
 * @param array $responses URL => array( code, body ) or WP_Error.
 * @return void
 */
function s2p_install_http( array $responses ) {
	$GLOBALS['s2p_responses'] = $responses;
	$GLOBALS['s2p_requests']  = array();
	$GLOBALS['s2p_double_on'] = true;

	Conexao_Request_Reuse::begin_run();
	Conexao_Request_Budget::begin_run( 50 );
}

/**
 * Remove the HTTP double.
 *
 * @return void
 */
function s2p_remove_http() {
	$GLOBALS['s2p_double_on'] = false;
}

test_title( 'Stage 2 — partial enrichment state and field-level preservation (P5)' );

// ===========================================================================
// Fixtures
// ===========================================================================

$plugin        = Conexao_Event_Importer::instance();
$s2p_source_id = 's2c_hw_partial';
$s2p_listing   = 'https://www.heritageweek.ie/event-listings?q=&where%5B%5D=laois';
$s2p_detail_1  = 'https://hw.test/e/88001';
$s2p_detail_2  = 'https://hw.test/e/88002';

/**
 * Build one Heritage Week listing card.
 *
 * @param string $id         Native Heritage Week data-id.
 * @param string $title      Event title.
 * @param string $detail_url Absolute detail URL.
 * @param string $date_line  Listing date line.
 * @param string $venue      Listing location line.
 * @return string
 */
function s2p_card( $id, $title, $detail_url, $date_line = '12 December 2026', $venue = 'Card Venue' ) {
	return sprintf(
		'<article class="item-summary" data-id="%1$s"><h3 class="title">%2$s</h3>'
		. '<a class="link-block" href="%3$s">Read more</a>'
		. '<ul class="list-details"><li>%4$s</li><li>%5$s</li></ul></article>',
		esc_attr( $id ),
		esc_html( $title ),
		esc_attr( $detail_url ),
		esc_html( $date_line ),
		esc_html( $venue )
	);
}

/**
 * Wrap listing cards into a Heritage Week listing page.
 *
 * @param string[] $cards Card HTML.
 * @return string
 */
function s2p_listing_html( array $cards ) {
	return '<html><head><title>2026 Events</title></head><body>' . implode( '', $cards ) . '</body></html>';
}

/**
 * Register the probe Heritage Week source.
 *
 * @return string Source ID.
 */
function s2p_register_source() {
	$plugin = Conexao_Event_Importer::instance();
	$plugin->sources->save(
		array(
			'id'      => 's2c_hw_partial',
			'name'    => 'S2C Heritage partial probe',
			'url'     => 'https://www.heritageweek.ie/event-listings',
			'type'    => 'heritage_week',
			'county'  => 'Laois',
			'hw_where' => array( 'laois' ),
			'status'  => 'active',
		)
	);

	return 's2c_hw_partial';
}

/**
 * Create the stored event the run will match (source + source_id identity).
 *
 * @param array $overrides Meta overrides.
 * @return int Post ID.
 */
function s2p_create_stored_event( array $overrides = array() ) {
	$values = array_merge(
		array(
			'source'      => 's2c_hw_partial',
			'source_id'   => '88001',
			'url'         => 'https://hw.test/e/88001',
			'title'       => 'S2C Heritage event',
			'date'        => '2026-12-12',
			'venue'       => 'Grand Hall',
			'location'    => 'Grand Hall',
			'address'     => '1 Main Street, Portlaoise',
			'organizer'   => 'Portlaoise Heritage Society',
			'description' => 'Original stored description',
		),
		$overrides
	);

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'event',
			'post_status'  => 'publish',
			'post_title'   => $values['title'],
			'post_content' => $values['description'],
		)
	);

	update_post_meta( $post_id, '_event_source', $values['source'] );
	update_post_meta( $post_id, '_event_source_id', $values['source_id'] );
	update_post_meta( $post_id, '_event_url', $values['url'] );
	update_post_meta( $post_id, '_event_date', $values['date'] );
	update_post_meta( $post_id, '_event_start_time', '' );
	update_post_meta( $post_id, '_event_venue', $values['venue'] );
	update_post_meta( $post_id, '_event_location', $values['location'] );
	update_post_meta( $post_id, '_event_address', $values['address'] );
	update_post_meta( $post_id, '_event_organizer', $values['organizer'] );
	update_post_meta( $post_id, '_event_banner', '' );
	Conexao_Event_Status::set_status( $post_id, Conexao_Event_Status::PUBLISHED );

	return $post_id;
}

/**
 * Delete every event owned by the probe source.
 *
 * @return void
 */
function s2p_cleanup_events() {
	$ids = get_posts(
		array(
			'post_type'      => 'event',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'   => '_event_source',
					'value' => 's2c_hw_partial',
				),
			),
		)
	);

	foreach ( $ids as $id ) {
		wp_delete_post( (int) $id, true );
	}
}

$s2p_sources_before = $plugin->sources->get( $s2p_source_id );
s2p_register_source();


// ===========================================================================
// 1 — a failed detail fetch cannot erase stored data.
// ===========================================================================
test_section( 'P5: a failed detail fetch preserves the stored description, venue, address and organiser' );

s2p_cleanup_events();
$stored_id = s2p_create_stored_event();

s2p_install_http(
	array(
		$s2p_listing  => array( 'code' => 200, 'body' => s2p_listing_html( array( s2p_card( '88001', 'S2C Heritage event', $s2p_detail_1 ) ) ) ),
		$s2p_detail_1 => new WP_Error( 's2p_detail_down', 'detail request timed out' ),
	)
);

$result = $plugin->importer->run_source( $s2p_source_id, false );
s2p_remove_http();

$stored_post = get_post( $stored_id );

assert_equals( 1, (int) $result['unchanged'], 'P5: with every detail-owned field preserved the event is reported unchanged (no churn write)' );
assert_equals( 'Original stored description', $stored_post->post_content, 'P5: a failed detail fetch does NOT erase the stored description' );
assert_equals( 'Grand Hall', get_post_meta( $stored_id, '_event_venue', true ), 'P5: a failed detail fetch does NOT erase the stored venue' );
assert_equals( '1 Main Street, Portlaoise', get_post_meta( $stored_id, '_event_address', true ), 'P5: a failed detail fetch does NOT erase the stored address' );
assert_equals( 'Portlaoise Heritage Society', get_post_meta( $stored_id, '_event_organizer', true ), 'P5: a failed detail fetch does NOT erase the stored organiser' );
assert_equals( 'Grand Hall', get_post_meta( $stored_id, '_event_location', true ), 'P5: a failed detail fetch does NOT erase the stored location label' );

// The preservation is recorded, naming the fields, so an operator can tell a
// partial enrichment from a clean one.
$preservation_logged = false;
foreach ( (array) Conexao_Import_Log::get_for_source( $s2p_source_id, 100 ) as $entry ) {
	$message = isset( $entry['message'] ) ? (string) $entry['message'] : '';

	if ( false !== strpos( $message, 'preserved' ) && false !== strpos( $message, 'description' ) && false !== strpos( $message, 'venue' ) ) {
		$preservation_logged = true;
		break;
	}
}

assert_true( $preservation_logged, 'P5: the enrichment failure is logged with the preserved field names' );

// ===========================================================================
// 2 — a successful detail fetch still applies a correction.
// ===========================================================================
test_section( 'P5: a successful detail fetch still updates and corrects stored data' );

s2p_cleanup_events();
$stored_id = s2p_create_stored_event();

$jsonld = '<script type="application/ld+json">' . wp_json_encode(
	array(
		'@context' => 'https://schema.org',
		'@type'    => 'Event',
		'name'     => 'S2C Heritage event',
		'location' => array(
			'@type'   => 'Place',
			'name'    => 'New Venue',
			'address' => array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => '2 New Street',
				'addressLocality' => 'Portlaoise',
				'addressRegion'   => 'Laois',
			),
		),
	)
) . '</script>';

s2p_install_http(
	array(
		$s2p_listing  => array( 'code' => 200, 'body' => s2p_listing_html( array( s2p_card( '88001', 'S2C Heritage event', $s2p_detail_1 ) ) ) ),
		$s2p_detail_1 => array(
			'code' => 200,
			'body' => '<html><body><div class="text-block">Corrected description</div>' . $jsonld . '</body></html>',
		),
	)
);

$result = $plugin->importer->run_source( $s2p_source_id, false );
s2p_remove_http();

assert_equals( 1, (int) $result['updated'], 'P5: a successful detail fetch reports an update' );
assert_equals( 'Corrected description', get_post_field( 'post_content', $stored_id ), 'P5: a successful retrieval updates the description' );
assert_equals( 'New Venue', get_post_meta( $stored_id, '_event_venue', true ), 'P5: a successful retrieval updates the venue' );
assert_true(
	false !== strpos( (string) get_post_meta( $stored_id, '_event_address', true ), '2 New Street' ),
	'P5: a successful retrieval updates the address',
	'address=' . (string) get_post_meta( $stored_id, '_event_address', true )
);


// ===========================================================================
// 3 — a PRESENT but empty description container is a genuine clear.
// ===========================================================================
test_section( 'P5: a retrieved page whose description container is present and empty genuinely clears the description' );

s2p_cleanup_events();
$stored_id = s2p_create_stored_event();

s2p_install_http(
	array(
		$s2p_listing  => array( 'code' => 200, 'body' => s2p_listing_html( array( s2p_card( '88001', 'S2C Heritage event', $s2p_detail_1 ) ) ) ),
		// The description container EXISTS and is blank: the documented
		// Heritage Week contract says that is an intentional empty value.
		$s2p_detail_1 => array( 'code' => 200, 'body' => '<html><body><div class="text-block">   </div></body></html>' ),
	)
);

$result = $plugin->importer->run_source( $s2p_source_id, false );
s2p_remove_http();

assert_equals( '', (string) get_post_field( 'post_content', $stored_id ), 'P5: a genuinely empty source field still clears the stored description' );
assert_equals( 'Grand Hall', get_post_meta( $stored_id, '_event_venue', true ), 'P5: the same run preserved the venue (field-level, not event-level)' );

// ===========================================================================
// 4 — an ABSENT element is not a clear.
// ===========================================================================
test_section( 'P5: an omitted HTML element is NOT treated as a genuine clear' );

s2p_cleanup_events();
$stored_id = s2p_create_stored_event();

s2p_install_http(
	array(
		$s2p_listing  => array( 'code' => 200, 'body' => s2p_listing_html( array( s2p_card( '88001', 'S2C Heritage event', $s2p_detail_1 ) ) ) ),
		// No text-block element and no JSON-LD: the page tells us nothing.
		$s2p_detail_1 => array( 'code' => 200, 'body' => '<html><body><section class="event-detail">No description block here.</section></body></html>' ),
	)
);

$result = $plugin->importer->run_source( $s2p_source_id, false );
s2p_remove_http();

assert_equals( 'Original stored description', (string) get_post_field( 'post_content', $stored_id ), 'P5: an absent description container preserves the stored description' );
assert_equals( 'Grand Hall', get_post_meta( $stored_id, '_event_venue', true ), 'P5: an absent venue element preserves the stored venue' );
assert_equals( '1 Main Street, Portlaoise', get_post_meta( $stored_id, '_event_address', true ), 'P5: an absent address element preserves the stored address' );


// ===========================================================================
// 5 — field-level preservation in a single run.
// ===========================================================================
test_section( 'P5: one field is updated while another is preserved in the same run' );

s2p_cleanup_events();
$stored_id = s2p_create_stored_event();

s2p_install_http(
	array(
		$s2p_listing  => array( 'code' => 200, 'body' => s2p_listing_html( array( s2p_card( '88001', 'S2C Heritage event', $s2p_detail_1 ) ) ) ),
		// Description retrieved (populated); no Event.location JSON-LD, so the
		// venue/address are unavailable and must be preserved.
		$s2p_detail_1 => array( 'code' => 200, 'body' => '<html><body><div class="text-block">Venue-run description</div></body></html>' ),
	)
);

$result = $plugin->importer->run_source( $s2p_source_id, false );
s2p_remove_http();

assert_equals( 1, (int) $result['updated'], 'P5: the partial enrichment is an update, not a skip' );
assert_equals( 'Venue-run description', (string) get_post_field( 'post_content', $stored_id ), 'P5: the retrieved field (description) is written' );
assert_equals( 'Grand Hall', get_post_meta( $stored_id, '_event_venue', true ), 'P5: the unavailable field (venue) is preserved in the SAME run' );
assert_equals( '1 Main Street, Portlaoise', get_post_meta( $stored_id, '_event_address', true ), 'P5: the unavailable field (address) is preserved in the SAME run' );

// ===========================================================================
// 6 — new-event creation still writes incoming values.
// ===========================================================================
test_section( 'P5: creating a new event writes incoming values normally (nothing to preserve)' );

s2p_cleanup_events();

s2p_install_http(
	array(
		$s2p_listing  => array( 'code' => 200, 'body' => s2p_listing_html( array( s2p_card( '88002', 'S2C brand new event', $s2p_detail_2 ) ) ) ),
		$s2p_detail_2 => new WP_Error( 's2p_detail_down', 'detail request timed out' ),
	)
);

$result = $plugin->importer->run_source( $s2p_source_id, false );
s2p_remove_http();

$created_ids = get_posts(
	array(
		'post_type'      => 'event',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'   => '_event_source',
				'value' => 's2c_hw_partial',
			),
			array(
				'key'   => '_event_source_id',
				'value' => '88002',
			),
		),
	)
);

assert_equals( 1, (int) $result['created'], 'P5: a new event is created even when its detail fetch fails' );
assert_equals( 1, count( $created_ids ), 'P5: exactly one new record was created' );

$created_id = (int) $created_ids[0];

assert_equals( 'S2C brand new event', get_post_field( 'post_title', $created_id ), 'P5: the created event carries the listing title' );
assert_equals( 'Card Venue', get_post_meta( $created_id, '_event_venue', true ), 'P5: creation writes the incoming listing venue (no stored value to preserve)' );
assert_equals( '', (string) get_post_field( 'post_content', $created_id ), 'P5: creation writes the incoming (empty) description' );


// ===========================================================================
// 7 — a payload with no enrichment contract keeps the legacy write path.
// ===========================================================================
test_section( 'P5: a payload without an enrichment contract (Eventbrite) still writes incoming values' );

s2p_cleanup_events();
$stored_id = s2p_create_stored_event();

$normalizer_property = new ReflectionProperty( 'Conexao_Event_Importer_Engine', 'normalizer' );
$normalizer_property->setAccessible( true );
$normalizer = $normalizer_property->getValue( $plugin->importer );

$normalized = $normalizer->normalize(
	array(
		'source'      => 's2c_hw_partial',
		'source_id'   => '88001',
		'title'       => 'S2C Heritage event',
		'url'         => 'https://hw.test/e/88001',
		'start_date'  => '2026-12-12',
		'location'    => 'Grand Hall',
		'venue'       => 'Grand Hall',
		'description' => '',
	)
);

assert_true( empty( $normalized['enrichment'] ), 'P5: a handler that declares no enrichment produces no enrichment key' );

$upsert = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'upsert_event' );
$upsert->setAccessible( true );
$outcome = $upsert->invoke( $plugin->importer, $normalized, $stored_id );

assert_equals( 'updated', $outcome['action'], 'P5: the legacy payload still produces a normal update' );
assert_equals( '', (string) get_post_field( 'post_content', $stored_id ), 'P5: without an enrichment contract an empty incoming description still clears (legacy behaviour unchanged)' );

// ===========================================================================
// Cleanup
// ===========================================================================
s2p_cleanup_events();
$plugin->sources->delete( $s2p_source_id );

if ( ! empty( $s2p_sources_before ) ) {
	$plugin->sources->save( $s2p_sources_before );
}

test_options_restore( $restore_options );
$GLOBALS['conexao_test_option_snapshots'] = array();

test_finish( 'stage2-partial-enrichment-preservation' );

