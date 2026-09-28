<?php
/**
 * IVVCC importer tests: parser, location, deduplication, image rules.
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-ivvcc-importer.php
 *
 * Pure-parser tests use inline deterministic fixtures (no network).
 * Deduplication tests touch the local DB; created posts are deleted after.
 *
 * @package Conexao_Event_Importer
 */


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$passed = 0;
$failed = 0;

function ivvcc_card_fixture( $overrides = array() ) {
	$a = array_merge( array(
		'id' => '555047', 'time' => '1789844400-1789923600',
		'title' => 'IVVCC 11th Brass Brigade Run',
		'subtitle' => 'Brass Brigade Run - cars up to 1919',
		'location' => 'Park Hotel, Dungarvan, Co Waterford',
		'organizer' => '',
		'url' => 'https://www.ivvcc.ie/events/ivvcc-11th-brass-brigade-run/',
	), $overrides );
	$org = '' !== $a['organizer'] ? "<span class='evo_card_organizer_name_t'>{$a['organizer']}</span>" : '';
	return "<div class='eventon_list_event evo_eventtop event' data-event_id='{$a['id']}' data-time='{$a['time']}'>"
		. "<div class='evo_event_schema'><a itemprop='url' href='{$a['url']}'></a><span itemprop='name'>{$a['title']}</span>"
		. "<meta itemprop='image' content='{$a['url']}' />"
		. '<script type="application/ld+json">{"@type":"Event","name":"x","startDate":"2026-9-19T19-19-00-00","endDate":"2026-9-20T19-19-00-00"}</script></div>'
		. "<span class='evcal_desc2 evcal_event_title'>{$a['title']}</span>"
		. "<span class='evcal_event_subtitle'>{$a['subtitle']}</span>"
		. "<p class='evo_location_name'>{$a['location']}</p>" . $org . '</div>';
}

function ivvcc_parse_one( $card_html ) {
	$dom = Conexao_Source_Ivvcc::parse_html_static( '<html><body>' . $card_html . '</body></html>' );
	$xp = new DOMXPath( $dom );
	$cards = $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " eventon_list_event ")]' );
	return Conexao_Source_Ivvcc::parse_card( $cards->item( 0 ), $xp, 'https://www.ivvcc.ie/upcoming-events-calendar/' );
}

// 1. One-day event with start+end time.
test_section( '1: one-day event' );
$e = ivvcc_parse_one( ivvcc_card_fixture( array( 'id' => '559933', 'time' => '1790503200-1790512200', 'title' => 'RIAC/IVVCC Cars and Breakfast' ) ) );
assert_true( '559933' === $e['source_id'], 'EventON ID preserved as source_id' );
assert_true( false !== strpos( $e['url'], '/events/' ), 'canonical individual URL kept, not calendar page' );
assert_true( '' !== $e['start_date'] && '' === $e['end_date'], 'one-day event has start date only (' . $e['start_date'] . ')' );
assert_true( '' !== $e['start_time'] && '' !== $e['end_time'], 'one-day keeps real start+end times' );

// 2. Multi-day events.
test_section( '2: multi-day events' );
$e = ivvcc_parse_one( ivvcc_card_fixture( array( 'time' => '1789844400-1789923600' ) ) );
assert_true( '2026-09-19' === $e['start_date'], 'Brass Brigade start 2026-09-19 (got ' . $e['start_date'] . ')' );
assert_true( '2026-09-20' === $e['end_date'], 'Brass Brigade end 2026-09-20 (got ' . $e['end_date'] . ')' );
$e2 = ivvcc_parse_one( ivvcc_card_fixture( array( 'id' => '559452', 'time' => '1788516000-1788717600' ) ) );
assert_true( '2026-09-04' === $e2['start_date'] && '2026-09-06' === $e2['end_date'], 'Garden of Ireland 4 Sep -> 6 Sep' );

// 3. Start-only (equal unix).
test_section( '3: start-only time' );
$e = ivvcc_parse_one( ivvcc_card_fixture( array( 'id' => '1', 'time' => '1789844400-1789844400' ) ) );
assert_true( '' !== $e['start_time'] && '' === $e['end_time'], 'equal unix = start-only, end empty' );

// 4. Placeholder 07:39 dropped.
test_section( '4: placeholder time' );
$e = ivvcc_parse_one( ivvcc_card_fixture( array( 'id' => '656421', 'time' => '1788507540-1788507540', 'title' => 'Baggatonia Festival' ) ) );
assert_true( '' !== $e['start_date'], 'placeholder event keeps valid date' );
assert_true( '' === $e['start_time'] && '' === $e['end_time'], 'placeholder 07:39 not imported as time' );
assert_true( ! empty( $e['_warnings'] ), 'placeholder logs a warning' );

// 5. Fallback 23:50 (UTC wall) end dropped; multi-day 23:50 keeps end date.
test_section( '5: 23:50 fallback end' );
$m = Conexao_Source_Ivvcc::map_unix_range( 1788516000, gmmktime( 23, 50, 0, 9, 4, 2026 ) );
assert_true( '' !== $m['start_time'] && '' === $m['end_time'], 'same-day 23:50 UTC end dropped, start kept' );
$m = Conexao_Source_Ivvcc::map_unix_range( gmmktime( 11, 0, 0, 9, 19, 2026 ), gmmktime( 23, 50, 0, 9, 20, 2026 ) );
assert_true( '2026-09-20' === $m['end_date'] && '' === $m['end_time'], 'multi-day 23:50 end: end date kept, end time dropped' );
$m = Conexao_Source_Ivvcc::map_unix_range( gmmktime( 1, 0, 0, 10, 9, 2026 ), gmmktime( 1, 0, 0, 10, 10, 2026 ) );
assert_true( '2026-10-09' === $m['start_date'] && '2026-10-10' === $m['end_date'] && '' === $m['start_time'] && '' === $m['end_time'], 'identical 24h-apart wall times: all-day span, times dropped' );
$m = Conexao_Source_Ivvcc::map_unix_range( 1788256800, 1790442000 );
assert_true( '10:00' === $m['start_time'] && '17:00' === $m['end_time'] && '2026-09-01' === $m['start_date'] && '2026-09-26' === $m['end_date'], 'Cobh unix range maps to the card-rendered 10:00 am - 5:00 pm wall times' );

// 6. TBA skipped.
test_section( '6: TBA skip' );
$e = ivvcc_parse_one( ivvcc_card_fixture( array( 'id' => '558743', 'time' => '1788256800-1790442000', 'title' => 'Cobh Classic Car Club', 'subtitle' => 'Dick OB memorial - Date to be advised', 'location' => 'Run to Kilmakilloge Harbour' ) ) );
assert_true( ! empty( $e['_skip'] ), 'TBA record flagged SKIP (date not firm)' );
// 7. Locations nationwide.
test_section( '7: locations' );
$loc = new Conexao_Event_Location();
$r = $loc->normalize( 'Park Hotel, Dungarvan, Co Waterford' );
assert_true( 'Waterford' === $r['county'] && 'Dungarvan' === $r['town'], 'venue + town + county Waterford/Dungarvan' );
assert_true( false !== strpos( $r['venue'], 'Park Hotel' ), 'venue preserved verbatim' );
assert_true( '' === $r['address'], 'venue-only has no invented street address' );
$r = $loc->normalize( 'The Goat Bar and Grill, Clonskeagh, Dublin D14 PY56' );
assert_true( 'Dublin' === $r['county'], 'Dublin county detected' );
assert_true( '' !== $r['address'] && false !== strpos( $r['address'], 'D14 PY56' ), 'Eircode address stored' );
$r = $loc->normalize( 'Starting from Russborough House' );
assert_true( '' === $r['address'] && '' !== $r['venue'], 'venue-only Russborough: venue kept, address empty' );
$r = $loc->normalize( 'Sligo Town' );
assert_true( 'Sligo' === $r['town'], 'Sligo town detected' );
$r = $loc->normalize( 'Kenmare' );
assert_true( 'Kenmare' === $r['town'], 'Kenmare detected' );
$r = $loc->normalize( 'Wexford' );
assert_true( 'Wexford' === $r['county'], 'Wexford detected' );
$r = $loc->normalize( 'Carlow' );
assert_true( 'Carlow' === $r['county'], 'Carlow detected' );
$r = $loc->normalize( 'Dungarvan, Co. Waterford' );
assert_true( 'Waterford' === $r['county'], 'Co. marker detected' );
$r = $loc->normalize( 'Church Street, Portlaoise, Co. Laois' );
assert_true( 'Laois' === $r['county'] && 'Portlaoise' === $r['town'], 'Laois legacy behavior preserved' );

// 8. Organizers verbatim.
test_section( '8: organizers' );
$e = ivvcc_parse_one( ivvcc_card_fixture( array( 'organizer' => 'Lar Cummins - Club Secretary, 087 2268752' ) ) );
assert_true( 'Lar Cummins - Club Secretary, 087 2268752' === $e['organizer'], 'affiliate organizer verbatim' );
$e = ivvcc_parse_one( ivvcc_card_fixture( array( 'organizer' => 'Riac Eventbrite or Ivvcc' ) ) );
assert_true( false !== strpos( $e['organizer'], 'Riac' ), 'joint organizer preserved, not forced to IVVCC' );
assert_true( false !== strpos( $e['description'], 'Registration:' ), 'Eventbrite registration text preserved' );

// 9. Price + details later.
test_section( '9: price + details later' );
$e = ivvcc_parse_one( ivvcc_card_fixture( array( 'subtitle' => 'Cars and Breakfast meet up €15. Breakfast at 11 am' ) ) );
assert_true( false !== strpos( $e['price'], '15' ), 'explicit price mapped (got ' . $e['price'] . ')' );
$e = ivvcc_parse_one( ivvcc_card_fixture( array( 'id' => '559757', 'subtitle' => 'Autumn Run - details later' ) ) );
assert_true( empty( $e['_skip'] ) && false !== strpos( $e['description'], 'details later' ), 'details-later imported with subtitle' );

// 10. Identity.
test_section( '10: identity' );
$e = ivvcc_parse_one( ivvcc_card_fixture( array( 'url' => 'https://www.ivvcc.ie/events/muskerry-vintage-club-27/' ) ) );
assert_true( 'https://www.ivvcc.ie/events/muskerry-vintage-club-27/' === $e['url'], 'versioned slug kept as source URL' );
assert_true( '555047' === $e['source_id'], 'stable numeric EventON ID kept' );
// 11. Dedupe semantics (pure).
test_section( '11: dedupe semantics' );
$cal = ivvcc_card_fixture( array( 'id' => '555047' ) ) . ivvcc_card_fixture( array( 'id' => '555047' ) );
$parsed = Conexao_Source_Ivvcc::parse_calendar_events( $cal );
assert_true( 2 === count( $parsed ) && $parsed[0]['source_id'] === $parsed[1]['source_id'], 'duplicate EventON ID parsed twice before collapse' );
$a = ivvcc_parse_one( ivvcc_card_fixture( array( 'id' => '559757', 'url' => 'https://www.ivvcc.ie/events/blessington-vintage-car-and-motorcycle-club-5/' ) ) );
$b = ivvcc_parse_one( ivvcc_card_fixture( array( 'id' => '559758', 'url' => 'https://www.ivvcc.ie/events/blessington-vintage-car-and-motorcycle-club-6/' ) ) );
assert_true( $a['source_id'] !== $b['source_id'] && $a['url'] !== $b['url'], 'yearly repeats with different IDs are different events' );

// 12. JSON-LD dates ignored.
test_section( '12: JSON-LD exclusion' );
$e = ivvcc_parse_one( ivvcc_card_fixture() );
assert_true( '2026-09-19' === $e['start_date'], 'data-time wins over malformed JSON-LD' );

// 13. Images.
test_section( '13: images' );
assert_true( '' === $e['image'], 'missing image = no image (page URL never used)' );
assert_true( Conexao_Source_Ivvcc::is_chrome_image( 'https://www.ivvcc.ie/wp-content/uploads/fiva-logo.png' ), 'FIVA logo is chrome' );
$d = Conexao_Source_Ivvcc::parse_detail_page( '<html><body><div class="eventon_list_event" data-event_id="1"><img src="https://www.ivvcc.ie/events/some-event/" /></div></body></html>', 'https://www.ivvcc.ie/events/some-event/' );
assert_true( '' === $d['image'], 'page URL never treated as event image' );

// 14. Deduplicator against DB.
test_section( '14: deduplicator (DB)' );
$dedup = new Conexao_Event_Deduplicator();
// The probe identity is derived from the run so it can never collide with a
// really imported event. Hard-coded source IDs made this assertion depend on
// whatever the local database happened to contain: a real event carrying
// source_id 559758 made "different ID+URL does not collapse" fail even though
// the deduplicator was correct.
$probe_nonce     = (string) time() . wp_generate_password( 6, false, false );
$probe_source_id = 'test-probe-' . $probe_nonce;
$probe_url       = 'https://www.ivvcc.ie/events/test-probe-' . $probe_nonce . '/';
$probe_title     = 'IVVCC Dedupe Probe ' . $probe_nonce;
$post_id         = wp_insert_post( array( 'post_type' => 'event', 'post_title' => $probe_title, 'post_status' => 'publish' ) );
update_post_meta( $post_id, '_event_source', 'ivvcc' );
update_post_meta( $post_id, '_event_source_id', $probe_source_id );
update_post_meta( $post_id, '_event_date', '2026-09-19' );
update_post_meta( $post_id, '_event_source_url', $probe_url );
update_post_meta( $post_id, '_event_url', $probe_url );
assert_true( (int) $post_id === (int) $dedup->find( array( 'source' => 'ivvcc', 'source_id' => $probe_source_id, 'source_url' => 'https://other.example/x', 'title' => 'Other title', 'start_date' => '2026-01-01' ) ), 'same ivvcc+ID => same event' );
assert_true( (int) $post_id === (int) $dedup->find( array( 'source' => 'ivvcc', 'source_id' => 'other', 'source_url' => $probe_url, 'title' => 'Other', 'start_date' => '2026-01-01' ) ), 'same canonical URL => same event' );
$other = $dedup->find( array( 'source' => 'ivvcc', 'source_id' => $probe_source_id . '-other', 'source_url' => $probe_url . '-other/', 'title' => 'Blessington XYZ ' . $probe_nonce, 'start_date' => '2026-12-13' ) );
assert_true( 0 === (int) $other, 'different ID+URL does not collapse' );

// 15. Update preserves manual fields.
test_section( '15: update preserves manual fields' );
update_post_meta( $post_id, '_event_address', 'Manually curated address, Dungarvan' );
update_post_meta( $post_id, '_event_map_url', 'https://www.google.com/maps/search/?api=1&query=manual' );
$resolved = Conexao_Event_Address::resolve_stored( '', get_post_meta( $post_id, '_event_address', true ) );
assert_true( 'Manually curated address, Dungarvan' === $resolved, 'manual address preserved when source supplies none' );
$map = Conexao_Event_Address::resolve_stored( '', get_post_meta( $post_id, '_event_map_url', true ) );
assert_true( 'https://www.google.com/maps/search/?api=1&query=manual' === $map, 'manual map URL preserved' );
wp_delete_post( $post_id, true );

test_finish();
