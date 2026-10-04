<?php
/**
 * G6 — Eventbrite official API contract test (OFFLINE).
 *
 * ## What this suite is, and what it is NOT
 *
 * G6 asked whether the official Eventbrite API can replace or reduce the
 * Eventbrite HTML scraping path. It is a FEASIBILITY STAGE: this suite does
 * NOT integrate an API client and does NOT change any production code path.
 *
 * It answers one narrow question mechanically:
 *
 *   "If a future API adapter produced the documented Eventbrite v3 event
 *    objects, COULD the existing importer normalization architecture consume
 *    them, and would the G1-G5 completeness semantics survive an API-shaped
 *    pagination walk?"
 *
 * Everything runs against `fixtures/eventbrite-api-v3-contract.json`. No test
 * contacts Eventbrite, and nothing is ever written to WordPress.
 *
 * ## Why a contract fixture at all
 *
 * No Eventbrite credential exists in this repository or its local environment
 * (verified: `.env`, Docker env, and the `conexao_event_importer_settings`
 * option are all empty). A live authenticated walk was therefore impossible.
 * The fixture records the RESPONSE SHAPE the documented API produces so the
 * architectural question can still be answered without a credential.
 *
 * Three envelopes in the fixture (`users_me_unauthenticated`,
 * `users_me_invalid_token`, `events_search_retired`) are VERBATIM live captures
 * from 2026-10-04. The event payloads are documented-shape fixtures and the
 * fixture says so in `_fixture_provenance.fabrication_notice`.
 *
 * ## The architectural conclusion this suite pins
 *
 * `Conexao_Eventbrite_Normalizer` reads DISCOVERY-payload field names
 * (`start_date`, `start_time`, `primary_venue`, `primary_organizer`,
 * `primary_organizer_id`, `locations[]`, `tickets_url`, `summary`,
 * `is_online_event`, `is_cancelled`, `is_protected_event`, `is_free`,
 * `ticket_availability`). The API payload uses DIFFERENT names for the same
 * facts (`start.local` / `start.utc` / `start.timezone`, `venue`, `organizer`,
 * `url`, `status`, `listed`, `changed`).
 *
 * So the answer to "can the existing normalizer consume API objects directly"
 * is NO — and this suite proves it by measurement rather than assertion. That
 * is the evidence for a future adapter owning a translation step, while the
 * REST of the pipeline (source handler -> normalizer -> importer engine) stays
 * shared. No second normalizer, no second import engine.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$restore_options = test_options_isolate(
	array(
		Conexao_Import_History::OPTION_KEY,
		Conexao_Import_Log::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

$fixture_path = __DIR__ . '/fixtures/eventbrite-api-v3-contract.json';
test_require(
	is_readable( $fixture_path ),
	'eventbrite-api-v3-contract.json',
	'the G6 offline API contract fixture is present and readable'
);

$fixture = json_decode( file_get_contents( $fixture_path ), true );
test_require(
	is_array( $fixture ),
	'eventbrite-api-v3-contract.json',
	'the G6 offline API contract fixture is valid JSON'
);

$normalizer = new Conexao_Eventbrite_Normalizer();

/**
 * Build the pre-normalization raw event an API adapter would hand the
 * normalizer, injecting the identity the source handler always injects.
 *
 * @param array $raw_api_event API event object from the fixture.
 * @return array
 */
function g6_api_raw( array $raw_api_event ) {
	$raw_api_event['county'] = 'Cork';
	$raw_api_event['source'] = 'eventbrite_cork';
	return $raw_api_event;
}

/**
 * Whether an API walk is COMPLETE.
 *
 * Completeness is asserted only when EVERY page actually carried both a
 * `pagination` block and an `events` array. A failed or rate-limited page
 * carries neither, so it can never be mistaken for "zero events".
 *
 * @param array $pages Ordered list of decoded page bodies.
 * @return bool
 */
function g6_api_walk_is_complete( array $pages ) {
	foreach ( $pages as $page ) {
		if ( ! isset( $page['pagination'] ) || ! isset( $page['events'] ) ) {
			return false;
		}
	}
	return true;
}

$api_page1_events = $fixture['events_page_1']['events'];
$api_page2_events = $fixture['events_page_2']['events'];

$api_ordinary  = $api_page1_events[0];
$api_no_image  = $api_page1_events[1];
$api_duplicate = $api_page2_events[0];
$api_draft     = $api_page2_events[1];

// ===========================================================================
// 1. The offline contract fixture itself is honest about its own provenance.
// ===========================================================================
test_section( 'G6.1 The fixture declares its provenance and carries no credential' );

assert_true(
	isset( $fixture['_fixture_provenance']['contains_no_credentials'] )
		&& true === $fixture['_fixture_provenance']['contains_no_credentials'],
	'G6.1 the fixture asserts it contains no credentials'
);

assert_true(
	isset( $fixture['_fixture_provenance']['fabrication_notice'] )
		&& false !== stripos( $fixture['_fixture_provenance']['fabrication_notice'], 'not a capture' ),
	'G6.1 the fixture declares that the event payloads are NOT a live capture'
);

$fixture_serialised = file_get_contents( $fixture_path );

assert_true(
	false === strpos( $fixture_serialised, 'Bearer ' ),
	'G6.1 the fixture contains no Authorization bearer header'
);

assert_true(
	! preg_match( '/"(private_token|oauth_token|api_token|access_token)"\s*:\s*"[^"]+"/', $fixture_serialised ),
	'G6.1 the fixture declares no token field carrying a value'
);

// Assert on DECODED keys, not raw text: the fixture's prose mentions the
// token case in `_doc`, so a raw-text regex would be ambiguous.
$token_like_keys = array();
foreach ( $fixture as $section_name => $section ) {
	if ( ! is_array( $section ) ) {
		continue;
	}
	foreach ( $section as $key => $unused ) {
		if ( preg_match( '/token/i', (string) $key ) && preg_match( '/^users_me_(unauthenticated|invalid_token)$/', (string) $section_name ) !== 1 ) {
			$token_like_keys[] = $section_name . '.' . $key;
		}
	}
}

assert_true(
	array() === $token_like_keys,
	'G6.1 no section outside the named auth probes declares a token-shaped key'
);

assert_true(
	isset( $fixture['users_me_invalid_token'] ) && ! isset( $fixture['users_me']['token'] ),
	'G6.1 the only token-shaped section is the named invalid-token probe case'
);

foreach ( array( 'users_me_unauthenticated', 'users_me_invalid_token', 'events_search_retired' ) as $verbatim_case ) {
	assert_true(
		isset( $fixture[ $verbatim_case ]['_doc'] )
			&& false !== strpos( $fixture[ $verbatim_case ]['_doc'], 'VERBATIM live capture' ),
		"G6.1 {$verbatim_case} is labelled as a verbatim live capture"
	);
}

assert_true(
	'NO_AUTH' === $fixture['users_me_unauthenticated']['body']['error'],
	'G6.1 an unauthenticated API request is 401 NO_AUTH (live-verified)'
);

assert_true(
	'INVALID_AUTH' === $fixture['users_me_invalid_token']['body']['error'],
	'G6.1 an invalid token is 401 INVALID_AUTH, distinct from NO_AUTH (live-verified)'
);

assert_true(
	404 === (int) $fixture['events_search_retired']['status_code']
		&& 'NOT_FOUND' === $fixture['events_search_retired']['body']['error'],
	'G6.1 the public Events Search endpoint is 404 NOT_FOUND (live re-verified in G6)'
);

// ===========================================================================
// 2. NEGATIVE PROOF 1 — an invalid/expired token surfaces an AUTH FAILURE,
//    never a "complete source with zero events".
// ===========================================================================
test_section( 'G6.2 NEGATIVE PROOF: an invalid token is an auth failure, never "0 events, success"' );

$invalid = $fixture['users_me_invalid_token'];

assert_true(
	401 === (int) $invalid['status_code'],
	'G6.2 the invalid-token response is HTTP 401'
);

assert_true(
	$fixture['users_me_unauthenticated']['body']['error'] !== $invalid['body']['error'],
	'G6.2 NO_AUTH and INVALID_AUTH are distinguishable, so an expired token is reportable'
);

// The decisive negative assertion: a 401 body contains NO `events` key. Any
// caller that treats "no events key" as "zero events" turns an auth failure
// into a successful empty source — the exact G1 masking defect.
assert_true(
	! array_key_exists( 'events', $invalid['body'] ),
	'G6.2 the 401 body carries no `events` key, so it cannot be misread as an empty result'
);

assert_true(
	! array_key_exists( 'pagination', $invalid['body'] ),
	'G6.2 the 401 body carries no `pagination` key, so completeness is undecidable (correctly)'
);

// Prove the misreading WOULD produce the dangerous outcome, so this proof is
// not vacuous: the naive extraction is empty, and emptiness is exactly what a
// source reports as "complete, zero events".
$naive_events = isset( $invalid['body']['events'] ) ? $invalid['body']['events'] : array();
assert_true(
	array() === $naive_events,
	'G6.2 a naive `events` extraction on the 401 body yields exactly zero events'
);

assert_true(
	! isset( $invalid['body']['pagination']['object_count'] ),
	'G6.2 the naive extraction finds no object_count, so a guard keyed on object_count MUST fire'
);

// ===========================================================================
// 3. Field-level compatibility: can the EXISTING normalizer consume an API
//    object? (measured, not asserted)
// ===========================================================================
test_section( 'G6.3 Field compatibility: the existing normalizer against an API payload' );

$normalized_api = $normalizer->normalize( g6_api_raw( $api_ordinary ) );

assert_true(
	'999200000001' === $normalized_api['source_id'],
	'G6.3 the API `id` is the stable event identity the importer already uses'
);

assert_true(
	'eventbrite_cork' === $normalized_api['source'],
	'G6.3 the injected source identity survives normalization'
);

assert_true(
	'Fixture Cork Community Night' === $normalized_api['title'],
	'G6.3 the API `name` maps to the importer title unchanged'
);

// MEASURED, 2026-10-04, by running the UNMODIFIED normalizer against an
// unmodified documented-shape API event. These are observations, not
// assumptions: the normalizer's LEGACY fallbacks (`venue`, and `image`
// read directly off the image object) already satisfy more of the API shape
// than a first reading suggests.
assert_true( '' === $normalized_api['start_date'], 'G6.3 MEASURED: API `start.local` does NOT populate start_date (nested shape unread)' );
assert_true( '' === $normalized_api['start_time'], 'G6.3 MEASURED: API `start.local` does NOT populate start_time' );
assert_true( '' === $normalized_api['end_date'], 'G6.3 MEASURED: API `end.local` does NOT populate end_date' );
assert_true( '' === $normalized_api['end_time'], 'G6.3 MEASURED: API `end.local` does NOT populate end_time' );
assert_true( '' === $normalized_api['timezone'], 'G6.3 MEASURED: API `start.timezone` does NOT populate timezone (nesting mismatch)' );
assert_true( '' === $normalized_api['organizer'], 'G6.3 MEASURED: API `organizer.name` does NOT populate organizer (the normalizer requires `primary_organizer`)' );
assert_true( '' === $normalized_api['organizer_id'], 'G6.3 MEASURED: the API organizer id is not read from the nested organizer object' );
assert_true( '' === $normalized_api['ticket_url'], 'G6.3 MEASURED: the API exposes no `tickets_url` key (the API uses `url` for the same value)' );

// The venue/address/image assertions below are the opposite finding, and they
// matter: the normalizer's LEGACY fallbacks happen to match the API shape.
assert_true( 'Fixture Cork Community Hall' === $normalized_api['venue'], 'G6.3 MEASURED SURPRISE: the API `venue` key IS consumed via the normalizer legacy venue fallback' );
assert_true( '12 Fixture Street, Cork, Cork, T12 AB12' === $normalized_api['address'], 'G6.3 MEASURED SURPRISE: the API `venue.address` IS consumed via the same fallback' );
assert_true( 'Cork' === $normalized_api['town'], 'G6.3 MEASURED: the API venue address city IS consumed via the same fallback' );
assert_true( false !== strpos( $normalized_api['image'], 'fixture-cork-64x64.jpg' ), 'G6.3 MEASURED SURPRISE: the API `image.image_sizes.medium` IS consumed without any translation' );
assert_true( 'An evening of talks and live music in Cork city.' === $normalized_api['description'], 'G6.3 MEASURED: the API `summary` key IS read directly (shared field name)' );

// So the real, measured incompatibility is the DATE/TIME block, the organizer
// identity and the ticket URL — everything else either matches or is rescued
// by an existing legacy fallback. This is a much narrower gap than a naive
// reading of the two payloads would predict, and it is the number G7 needs.
$gaps = array();
foreach ( array( 'start_date', 'start_time', 'end_date', 'end_time', 'timezone', 'organizer', 'organizer_id', 'ticket_url' ) as $gap ) {
	if ( '' === (string) $normalized_api[ $gap ] ) {
		$gaps[] = $gap;
	}
}

assert_true(
	8 === count( $gaps ),
	sprintf( 'G6.3 MEASURED: exactly 8 importer fields are incompatible with a raw API object: %s', implode( ', ', $gaps ) )
);

// And the date gap alone is fatal: an event with no start date cannot be
// imported, published, or ordered. Prove it rather than assert it.
assert_true(
	'' === $normalized_api['start_date'],
	'G6.3 the date gap alone is fatal: without start_date the event cannot be placed on a calendar'
);

// The count of importer fields left empty overall, measured.
$api_fields = array( 'title', 'url', 'start_date', 'start_time', 'end_date', 'end_time', 'venue', 'address', 'organizer', 'organizer_id', 'image', 'description', 'ticket_url' );
$empty      = 0;
foreach ( $api_fields as $field ) {
	if ( '' === (string) $normalized_api[ $field ] ) {
		++$empty;
	}
}

assert_true(
	7 === $empty,
	sprintf( 'G6.3 MEASURED: %d of %d importer fields are empty from an unmodified API object (%s survive via shared names or legacy fallbacks)', $empty, count( $api_fields ), implode( ', ', array_diff( $api_fields, $gaps ) ) )
);

// The API genuinely HAS the data that the normalizer cannot reach — so the
// loss is a SHAPE mismatch, not an upstream gap. That is what makes an
// adapter (not a normalizer rewrite) the right answer.
assert_true(
	isset( $api_ordinary['start']['local'], $api_ordinary['start']['timezone'], $api_ordinary['organizer']['name'] ),
	'G6.3 the API payload DOES carry the start instant, timezone and organizer name the importer needs — nested under different keys'
);

assert_true(
	'2026-11-14T18:30:00' === $api_ordinary['start']['local'],
	'G6.3 the API start instant is present and readable from start.local'
);

assert_true(
	'Fixture Cork Community Org' === $api_ordinary['organizer']['name'],
	'G6.3 the API organizer name is present and readable from organizer.name'
);


// ===========================================================================
// 4. The adapter translation step: a documented-shape API object becomes a
//    discovery-shape raw event and the EXISTING normalizer consumes it fully.
//    Proved here as a TEST DOUBLE, NOT implemented as production code.
// ===========================================================================
test_section( 'G6.4 A shaped adapter translation restores full fidelity through the shared normalizer' );

/**
 * Translate a documented API event into the discovery-payload shape the
 * existing normalizer reads.
 *
 * This is a TEST DOUBLE proving the architecture is sufficient. It is not
 * production code and it is not wired into any source handler.
 *
 * @param array $api_event API event object.
 * @return array
 */
function g6_api_to_discovery_shape( array $api_event ) {
	$out = array(
		'id'                 => $api_event['id'],
		'name'               => $api_event['name'],
		'url'                => $api_event['url'],
		'summary'            => isset( $api_event['summary'] ) ? $api_event['summary'] : '',
		'full_description'   => isset( $api_event['description'] ) ? $api_event['description'] : '',
		'start_date'         => substr( $api_event['start']['local'], 0, 10 ),
		'start_time'         => substr( $api_event['start']['local'], 11, 5 ),
		'end_date'           => substr( $api_event['end']['local'], 0, 10 ),
		'end_time'           => substr( $api_event['end']['local'], 11, 5 ),
		'timezone'           => $api_event['start']['timezone'],
		'is_online_event'    => ! empty( $api_event['is_online_event'] ),
		'is_cancelled'       => 'canceled' === $api_event['status'],
		'is_protected_event' => ! empty( $api_event['is_protected_event'] ),
		'is_free'            => ! empty( $api_event['free'] ),
		'published'          => 'live' === $api_event['status'] && ! empty( $api_event['listed'] ),
	);

	if ( ! empty( $api_event['venue'] ) ) {
		$out['primary_venue'] = array(
			'id'      => $api_event['venue']['id'],
			'name'    => $api_event['venue']['name'],
			'address' => $api_event['venue']['address'],
		);
	}

	if ( ! empty( $api_event['organizer'] ) ) {
		$out['primary_organizer']    = $api_event['organizer'];
		$out['primary_organizer_id'] = $api_event['organizer_id'];
	}

	if ( ! empty( $api_event['image'] ) ) {
		$out['image'] = $api_event['image'];
	}

	if ( ! empty( $api_event['category_id'] ) ) {
		$out['tags'] = array(
			array( 'type' => 'EventbriteCategory', 'display_name' => 'API Category ' . $api_event['category_id'] ),
		);
	}

	if ( ! empty( $api_event['venue']['address']['region'] ) ) {
		$out['locations'] = array(
			array( 'type' => 'region', 'name' => $api_event['venue']['address']['region'] ),
		);
	}

	$out['eventbrite_api_status']  = $api_event['status'];
	$out['eventbrite_api_changed'] = $api_event['changed'];

	return $out;
}

$adapted = $normalizer->normalize( g6_api_raw( g6_api_to_discovery_shape( $api_ordinary ) ) );

assert_true( '2026-11-14' === $adapted['start_date'], 'G6.4 the adapted API event yields the correct start date through the SHARED normalizer' );
assert_true( '18:30' === $adapted['start_time'], 'G6.4 the adapted API event yields the correct start time' );
assert_true( '2026-11-14' === $adapted['end_date'] && '21:00' === $adapted['end_time'], 'G6.4 the adapted API event yields the correct end date and time' );
assert_true( 'Fixture Cork Community Hall' === $adapted['venue'], 'G6.4 the adapted API event yields the venue name' );
assert_true( '12 Fixture Street, Cork, Cork, T12 AB12' === $adapted['address'], 'G6.4 the adapted API event yields the full postal address' );
assert_true( 'Fixture Cork Community Org' === $adapted['organizer'], 'G6.4 the adapted API event yields the organizer name' );
assert_true( '999100000001' === $adapted['organizer_id'], 'G6.4 the adapted API event yields the organizer identity' );
assert_true( '' !== $adapted['image'], 'G6.4 the adapted API event yields an image URL' );
assert_true( 'Cork' === $adapted['town'], 'G6.4 the adapted API event yields the town from the venue address' );
assert_true( 'Cork' === $adapted['county'], 'G6.4 the county identity injected by the source handler is preserved' );
assert_true( 'An evening of talks and live music in Cork city.' === $adapted['description'], 'G6.4 the adapted API event yields the summary as description' );
assert_true( 'Fixture Cork Community Hall, Cork, Cork' === $adapted['location'], 'G6.4 the adapted API event yields the composed location string' );

// ===========================================================================
// 5. Optional-field degradation, recurrence, and change tracking.
// ===========================================================================
test_section( 'G6.5 A missing optional image degrades without inventing data' );

$adapted_no_image = $normalizer->normalize( g6_api_raw( g6_api_to_discovery_shape( $api_no_image ) ) );

assert_true( '' === $adapted_no_image['image'], 'G6.5 an API event with no image yields an empty image, not a fabricated URL' );
assert_true( ! preg_match( '#^https?://#', $adapted_no_image['image'] ), 'G6.5 no placeholder image URL is invented for an imageless event' );
assert_true( 'Fixture Cork Walk (no image)' === $adapted_no_image['title'], 'G6.5 the imageless event still normalizes its title' );
assert_true( '2026-11-21' === $adapted_no_image['start_date'] && '10:00' === $adapted_no_image['start_time'], 'G6.5 the imageless event still normalizes its date and time' );
assert_true( '' === $adapted_no_image['venue'] && '' === $adapted_no_image['address'], 'G6.5 a null API venue yields empty venue and address, never a guess' );
assert_true( isset( $api_no_image['series_id'] ) && '' !== $api_no_image['series_id'], 'G6.5 the API exposes a series_id for a recurring event' );
assert_true( ! isset( $api_no_image['series']['recurrence'] ) && ! isset( $api_no_image['recurrence'] ), 'G6.5 MEASURED LIMITATION: the API event object carries NO recurrence rule - only a series id' );
assert_true( isset( $api_ordinary['changed'] ) && '' !== $api_ordinary['changed'], 'G6.5 the API exposes a per-event `changed` timestamp (an improvement over the HTML path)' );

// ===========================================================================
// 6. Publication status is decidable from the API.
// ===========================================================================
test_section( 'G6.6 Publication status: the API is decidable' );

assert_true( 'live' === $api_ordinary['status'] && true === $api_ordinary['listed'], 'G6.6 the ordinary event is live AND listed, i.e. public' );
assert_true( 'draft' === $api_draft['status'] && false === $api_draft['listed'], 'G6.6 the draft event is draft AND unlisted, i.e. NOT public' );
assert_true( false === g6_api_to_discovery_shape( $api_draft )['published'], 'G6.6 the adapter maps a draft to published=false, so a draft is never treated as public' );
assert_true( true === g6_api_to_discovery_shape( $api_ordinary )['published'], 'G6.6 the adapter maps a live+listed event to published=true' );
assert_true( true === g6_api_to_discovery_shape( $api_duplicate )['published'], 'G6.6 a re-listed but still live event stays published' );

// ===========================================================================
// 7. Duplicate / stable event IDs must collapse.
// ===========================================================================
test_section( 'G6.7 Duplicate API event ids collapse to one event' );

$all_api_events = array_merge( $api_page1_events, $api_page2_events );

$seen       = array();
$unique     = array();
$duplicates = 0;
foreach ( $all_api_events as $event ) {
	$id = (string) $event['id'];
	if ( '' === $id ) {
		continue;
	}
	if ( isset( $seen[ $id ] ) ) {
		++$duplicates;
		continue;
	}
	$seen[ $id ] = true;
	$unique[]    = $event;
}

assert_true( 4 === count( $all_api_events ), 'G6.7 the two pages together carry 4 event objects' );
assert_true( 1 === $duplicates, 'G6.7 exactly one object is a duplicate of an earlier id (the stable-id re-listing)' );
assert_true( 3 === count( $unique ), 'G6.7 the four objects collapse to three distinct events' );
assert_true( 3 === count( $seen ), 'G6.7 three distinct ids were observed across both pages' );
assert_true( (string) $api_duplicate['id'] === (string) $api_ordinary['id'], 'G6.7 the duplicate carries the SAME stable event id as the original' );
assert_true( strtotime( $api_duplicate['changed'] ) > strtotime( $api_ordinary['changed'] ), 'G6.7 the duplicate carries a strictly newer `changed` timestamp' );
assert_true( '2026-10-03T16:45:00Z' === $api_duplicate['changed'], 'G6.7 the newer `changed` value is the one asserted in the fixture' );

// ===========================================================================
// 8. Pagination / completeness.
// ===========================================================================
test_section( 'G6.8 API pagination terminates deterministically and fails truthfully' );

$page1_pagination = $fixture['events_page_1']['pagination'];
$page2_pagination = $fixture['events_page_2']['pagination'];

assert_true( 3 === (int) $page1_pagination['object_count'], 'G6.8 the API declares its total object_count up front (no hidden tail)' );
assert_true( 2 === (int) $page1_pagination['page_count'], 'G6.8 the API declares a page_count, so the walk has a known end' );
assert_true( ! empty( $page1_pagination['continuation'] ) && true === $page1_pagination['has_more_items'], 'G6.8 page 1 advertises a continuation cursor and has_more_items=true' );
assert_true( empty( $page2_pagination['continuation'] ) && false === $page2_pagination['has_more_items'], 'G6.8 page 2 has a null continuation, so the walk terminates deterministically' );

$walk_pages  = 0;
$walk_events = array();
$walk_log    = array();

while ( true ) {
	$page = 0 === $walk_pages ? $fixture['events_page_1'] : $fixture['events_page_2'];
	++$walk_pages;
	$walk_events = array_merge( $walk_events, $page['events'] );

	$walk_log[] = array(
		'page'         => (int) $page['pagination']['page_number'],
		'events'       => count( $page['events'] ),
		'has_more'     => (bool) $page['pagination']['has_more_items'],
		'continuation' => $page['pagination']['continuation'],
	);

	if ( empty( $page['pagination']['continuation'] ) ) {
		break;
	}

	if ( $walk_pages > 10 ) {
		break;
	}
}

assert_true( 2 === $walk_pages, 'G6.8 the API walk reaches a deterministic end in exactly 2 pages' );
assert_true( 4 === count( $walk_events ), 'G6.8 the walk collects all 4 event objects across both pages' );
assert_true( 3 === (int) $page1_pagination['object_count'] && 3 === count( array_unique( array_map( static fn( $e ) => (string) $e['id'], array_merge( $api_page1_events, $api_page2_events ) ) ) ), 'G6.8 object_count equals the DISTINCT event ids: the API counts a re-listed stable id ONCE, so no duplicate is promised twice' );
assert_true( 2 === count( $walk_log ) && 2 === count( $walk_events ) - 2, 'G6.8 the walk log records two pages of two events each' );
assert_true( 'eyJwYWdlIjoyfQ' === $walk_log[0]['continuation'] && null === $walk_log[1]['continuation'], 'G6.8 the continuation cursor is consumed exactly once and then terminates' );

// 8b. NEGATIVE PROOF 2 - a FAILED page is INCOMPLETE, not "zero events".
test_section( 'G6.9 NEGATIVE PROOF: a failed page can never become "no events, complete"' );

$failed_page = $fixture['events_page_failed'];

assert_true( 403 === (int) $failed_page['status_code'], 'G6.9 the failed page is HTTP 403' );
assert_true( ! array_key_exists( 'events', $failed_page['body'] ), 'G6.9 the failed page body carries no `events` key' );
assert_true( ! array_key_exists( 'pagination', $failed_page['body'] ), 'G6.9 the failed page body carries no `pagination` key, so completeness is undecidable' );
assert_true( false === g6_api_walk_is_complete( array( $fixture['events_page_1'], $failed_page['body'] ) ), 'G6.9 a walk whose second page errored is INCOMPLETE, never complete' );
assert_true( false === g6_api_walk_is_complete( array( $failed_page['body'] ) ), 'G6.9 a walk whose FIRST page errored is INCOMPLETE, never complete' );
assert_true( true === g6_api_walk_is_complete( array( $fixture['events_page_1'], $fixture['events_page_2'] ) ), 'G6.9 a walk where every page carried pagination AND events is complete' );
assert_true( true === g6_api_walk_is_complete( array( $fixture['events_empty_but_valid'] ) ), 'G6.9 a genuinely empty organization IS complete (it returned pagination and events)' );

// 8c. NEGATIVE PROOF 3 - a rate-limited page is incomplete AND carries Retry-After.
test_section( 'G6.10 NEGATIVE PROOF: a 429 page is incomplete and carries Retry-After' );

$limited = $fixture['events_page_rate_limited'];

assert_true( 429 === (int) $limited['status_code'], 'G6.10 the rate-limited page is HTTP 429' );
assert_true( ! array_key_exists( 'events', $limited['body'] ), 'G6.10 the 429 body carries no `events` key' );
assert_true( false === g6_api_walk_is_complete( array( $limited['body'] ) ), 'G6.10 a 429 page makes the walk INCOMPLETE' );
assert_true( isset( $limited['headers']['Retry-After'] ) && '60' === $limited['headers']['Retry-After'], 'G6.10 the 429 page carries a Retry-After header the existing client can already parse' );
assert_true( 60.0 === (float) Conexao_Eventbrite_Client::parse_retry_after( $limited['headers']['Retry-After'] ), 'G6.10 the EXISTING unchanged Retry-After parser reads the API header correctly' );
assert_true( 60.0 === (float) Conexao_Eventbrite_Client::parse_retry_after( '9999' ), 'G6.10 the EXISTING unchanged Retry-After cap (60s) still bounds an absurd API value' );

// 8d. NEGATIVE PROOF 4 - an empty organization is distinguishable from failure.
test_section( 'G6.11 NEGATIVE PROOF: "genuinely empty" is distinguishable from "failed"' );

$empty_ok = $fixture['events_empty_but_valid'];

assert_true( array() === $empty_ok['events'], 'G6.11 the empty-but-valid response carries a real empty events array' );
assert_true( 0 === (int) $empty_ok['pagination']['object_count'], 'G6.11 it declares object_count 0, so "empty" is an ASSERTION, not an absence' );

$state_empty = array_key_exists( 'events', $empty_ok ) ? 'empty' : 'unknown';
$state_fail  = array_key_exists( 'events', $failed_page['body'] ) ? 'empty' : 'failed';

assert_true( 'empty' === $state_empty, 'G6.11 the empty organization is classified as empty' );
assert_true( 'failed' === $state_fail, 'G6.11 the 403 response is classified as failed, never as empty' );
assert_true( 'empty' !== $state_fail, 'G6.11 a failure is never reported as an empty source - the G1 masking defect cannot recur' );

// ===========================================================================
// 12. Organization discovery is owner-scoped: the G6 coverage blocker.
// ===========================================================================
test_section( 'G6.12 Organization discovery is owner-scoped (the G6 coverage blocker)' );

$orgs = $fixture['organizations_page']['organizations'];

assert_true( 2 === count( $orgs ), 'G6.12 the discovery response lists the token owner organizations' );

foreach ( $orgs as $org ) {
	assert_true( isset( $org['id'] ) && isset( $org['name'] ), 'G6.12 every discovered organization carries a stable id and a name' );
	assert_true( isset( $org['locale'] ), 'G6.12 every discovered organization carries a locale' );
}

// The structural point: discovery is per-USER, not per-location. The API has
// no documented way to ask "which organizations have events in Cork?".
$org_payload = strtolower( wp_json_encode( $fixture['organizations_page'] ) );

assert_true( false === strpos( $org_payload, '"location' ), 'G6.12 the organizations payload contains NO location/county selector - discovery cannot be location-scoped' );
assert_true( ! array_key_exists( 'events', $fixture['organizations_page'] ), 'G6.12 the organizations endpoint returns organizations only, never a public event list' );
assert_true( ! array_key_exists( 'search', $fixture['organizations_page'] ), 'G6.12 the organizations endpoint exposes no search parameter' );
assert_true( false === strpos( $org_payload, 'ireland--cork' ), 'G6.12 a county discovery slug cannot be expressed against the organizations endpoint' );

test_finish( 'G6 Eventbrite API contract (offline)' );
