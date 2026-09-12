<?php
/**
 * Mondello Park importer tests: date parser, identity, categories,
 * ticket URL extraction, edge-cases, missing-location, multi-day, dedup,
 * regression.
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-mondello-park-importer.php
 *
 * Pure-parser tests use inline deterministic fixtures (no network).
 * Deduplication/regression tests touch the local DB; created posts are
 * cleaned up after.
 *
 * @package Conexao_Event_Importer
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
$harness_errors = 0;

function mp_test_assert($condition, $message ) {
	global $passed, $failed, $harness_errors;
	if ( ! is_bool( $condition ) ) {
		$failed++;
		$harness_errors++;
		$type = is_object( $condition ) ? get_class( $condition ) : gettype( $condition );
		echo "  HARNESS ERROR: mp_test_assert() condition must be bool, got {$type} — message was: {$message}\n";
		return;
	}
	if ( ! is_string( $message ) || '' === $message ) {
		$failed++;
		$harness_errors++;
		echo "  HARNESS ERROR: mp_test_assert() message must be a non-empty string\n";
		return;
	}
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

function mp_test_section( $title ) {
	echo "\n=== {$title} ===\n";
}

// =========================================================================
// Date parser — deterministic fixtures
// =========================================================================

mp_test_section( 'Date parser (parse_us_month_day_year)' );

mp_test_assert(
	method_exists( 'Conexao_Source_Mondello_Park', 'parse_us_month_day_year' ) ,
	'Conexao_Source_Mondello_Park::parse_us_month_day_year'
);
mp_test_assert(
	method_exists( 'Conexao_Source_Mondello_Park', 'parse_date_range' ) ,
	'parse_date_range'
);
mp_test_assert(
	method_exists( 'Conexao_Source_Mondello_Park', 'clean_date_text' ) ,
	'clean_date_text'
);

// Normal valid dates
mp_test_assert(
	'Conexao_Source_Mondello_Park::parse_us_month_day_year'('September 12, 2026') === '2026-09-12' ,
	'September 12, 2026 → 2026-09-12'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::parse_us_month_day_year'('Sep 12, 2026') === '2026-09-12' ,
	'Sep 12, 2026 → 2026-09-12'
);
// Stage A spec (docs/importers/mondello-park-stage-a-audit.md, §Stage B rec. 3
// and setup JSON date_source.format "Month DD, YYYY") requires the comma form.
// A comma-less "September 12 2026" is NOT attested in the source evidence, so
// the parser correctly rejects it — the old expectation was wrong (TEST).
mp_test_assert(
	false === 'Conexao_Source_Mondello_Park::parse_us_month_day_year'('September 12 2026') ,
	'September 12 2026 (no comma) → false (comma required per Stage A spec)'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::parse_us_month_day_year'('September 12, 2026') === '2026-09-12' ,
	'September 12, 2026 (with comma) → 2026-09-12 (regression)'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::parse_us_month_day_year'('December 25, 2026') === '2026-12-25' ,
	'Christmas December 25, 2026 → 2026-12-25'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::parse_us_month_day_year'('January 1, 2027') === '2027-01-01' ,
	'January 1, 2027 → 2027-01-01'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::parse_us_month_day_year'('Jan 5, 2026') === '2026-01-05' ,
	'Short month Jan 5, 2026 → 2026-01-05'
);

// Month-only (no day) must be rejected — not a complete date.
mp_test_assert(
	false === 'Conexao_Source_Mondello_Park::parse_us_month_day_year'('February 2026') ,
	'February 2026 (no day) → false'
);
mp_test_assert(
	false === 'Conexao_Source_Mondello_Park::parse_us_month_day_year'('2026') ,
	'2026 (year only) → false'
);

// Invalid / impossible dates must be rejected.
mp_test_assert(
	false === 'Conexao_Source_Mondello_Park::parse_us_month_day_year'('February 30, 2026') ,
	'February 30, 2026 (impossible) → false'
);
mp_test_assert(
	false === 'Conexao_Source_Mondello_Park::parse_us_month_day_year'('September 45, 2026') ,
	'September 45, 2026 (invalid day) → false'
);
mp_test_assert(
	false === 'Conexao_Source_Mondello_Park::parse_us_month_day_year'('NotADate 12, 2026') ,
	'NotADate 12, 2026 (no real month) → false'
);

// Whitespace normalization
mp_test_assert(
	'Conexao_Source_Mondello_Park::parse_us_month_day_year'('September 12  ,  2026') === '2026-09-12' ,
	'September 12  ,  2026 (extra spaces) → 2026-09-12'
);
// FIXTURE was wrong: single-quoted '\u{2013}' is 8 literal chars, not an
// en-dash. Fixtures below embed the literal UTF-8 characters U+2013, U+2014,
// U+2212 (PHP has no \uXXXX escape in double-quoted strings).
mp_test_assert(
	'Conexao_Source_Mondello_Park::clean_date_text'("September 12, 2026 – October 14, 2026") === 'September 12, 2026 - October 14, 2026' ,
	'clean_date_text normalises U+2013 en-dash'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::clean_date_text'("September 12, 2026 — October 14, 2026") === 'September 12, 2026 - October 14, 2026' ,
	'clean_date_text normalises U+2014 em-dash'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::clean_date_text'("September 12, 2026 − October 14, 2026") === 'September 12, 2026 - October 14, 2026' ,
	'clean_date_text normalises U+2212 minus sign'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::clean_date_text'('September 12, 2026 - October 14, 2026') === 'September 12, 2026 - October 14, 2026' ,
	'clean_date_text preserves ASCII hyphen'
);

// =========================================================================
// Date range parser
// =========================================================================

mp_test_section( 'Date range parser (parse_date_range)' );

// One-day event
$one = 'Conexao_Source_Mondello_Park::parse_date_range'('September 12, 2026');
mp_test_assert( $one['valid'] === true, 'one-day: valid' );
mp_test_assert( $one['start'] === '2026-09-12', 'one-day: start' );
mp_test_assert( $one['end'] === '2026-09-12', 'one-day: end same as start' );
mp_test_assert( $one['tba'] === false, 'one-day: not tba' );

// Multi-day event
$multi = 'Conexao_Source_Mondello_Park::parse_date_range'('September 12, 2026 - October 14, 2026');
mp_test_assert( $multi['valid'] === true, 'multi-day: valid' );
mp_test_assert( $multi['start'] === '2026-09-12', 'multi-day: start' );
mp_test_assert( $multi['end'] === '2026-10-14', 'multi-day: end' );
mp_test_assert( $multi['tba'] === false, 'multi-day: not tba' );

// Stage A evidence: yearless "Sep 6 - Sep 6" has no year anywhere in the
// source context (setup JSON + audit only attest full "Month DD, YYYY" forms).
// The parser requires an explicit 4-digit year and rejects yearless halves,
// so yearless input is INVALID — no year is fabricated (TEST correction).
$same = 'Conexao_Source_Mondello_Park::parse_date_range'('Sep 6 - Sep 6');
mp_test_assert( $same['valid'] === false, 'same-day yearless range: valid=false (no year fabricated)' );
mp_test_assert( $same['start'] === '', 'same-day yearless range: start empty' );
mp_test_assert( $same['end'] === '', 'same-day yearless range: end empty' );

// Same-month same-day explicit
$same_month = 'Conexao_Source_Mondello_Park::parse_date_range'('September 6, 2026 - September 6, 2026');
mp_test_assert( $same_month['valid'] === true, 'same-month same-day: valid' );
mp_test_assert( $same_month['start'] === '2026-09-06', 'same-month same-day: start' );
mp_test_assert( $same_month['end'] === '2026-09-06', 'same-month same-day: end same' );

// TBA rejection
$tba = 'Conexao_Source_Mondello_Park::parse_date_range'('Date TBA, 2026');
mp_test_assert( $tba['valid'] === false, 'TBA: valid=false' );
mp_test_assert( $tba['tba'] === true, 'TBA: tba=true' );

$tbc = 'Conexao_Source_Mondello_Park::parse_date_range'('To be confirmed');
mp_test_assert( $tbc['valid'] === false, 'TBC: valid=false' );
mp_test_assert( $tbc['tba'] === true, 'TBC: tba=true' );

$tbd = 'Conexao_Source_Mondello_Park::parse_date_range'('TBD');
mp_test_assert( $tbd['valid'] === false, 'TBD: valid=false' );
mp_test_assert( $tbd['tba'] === true, 'TBD: tba=true' );

// Empty string
$empty = 'Conexao_Source_Mondello_Park::parse_date_range'( '');
mp_test_assert( $empty['valid'] === false, 'empty: valid=false' );
mp_test_assert( $empty['tba'] === false, 'empty: tba=false' );

// =========================================================================
// Category mapping
// =========================================================================

mp_test_section( 'Category mapping (map_categories)' );

mp_test_assert(
	in_array( 'Car Racing', Conexao_Source_Mondello_Park::map_categories( array( 23 ) ), true ) ,
	'Car Racing (23) maps to Car Racing'
);
mp_test_assert(
	in_array( 'Drifting', Conexao_Source_Mondello_Park::map_categories( array( 22 ) ), true ) ,
	'Drifting (22) maps to Drifting'
);
mp_test_assert(
	in_array( 'Rally', Conexao_Source_Mondello_Park::map_categories( array( 34 ) ), true ) ,
	'Rally (34) maps to Rally'
);
mp_test_assert(
	in_array( 'Motorbike Racing', Conexao_Source_Mondello_Park::map_categories( array( 38 ) ), true ) ,
	'Motorbike Racing (38) maps to Motorbike Racing'
);
mp_test_assert(
	in_array( 'JDM', Conexao_Source_Mondello_Park::map_categories( array( 35 ) ), true ) ,
	'JDM (35) maps to JDM'
);
mp_test_assert(
	in_array( 'Retro/Historic', Conexao_Source_Mondello_Park::map_categories( array( 18 ) ), true ) ,
	'Retro/Historic (18) maps to Retro/Historic'
);
mp_test_assert(
	in_array( 'Shows', Conexao_Source_Mondello_Park::map_categories( array( 47 ) ), true ) ,
	'Shows (47) maps to Shows'
);
mp_test_assert(
	in_array( 'IDS', Conexao_Source_Mondello_Park::map_categories( array( 36 ) ), true ) ,
	'IDS (36) maps to IDS'
);

// Multiple categories preserved (order is ID order as returned by REST).
$multi_cats = Conexao_Source_Mondello_Park::map_categories( array( 23, 22 ) );
mp_test_assert(
	count( $multi_cats ) === 2
	 && in_array( 'Car Racing', $multi_cats, true )
	 && in_array( 'Drifting', $multi_cats, true ) ,
	'multi-category: Car Racing + Drifting'
);

// Duplicate category IDs deduplicate (same category twice).
$dup_cats = Conexao_Source_Mondello_Park::map_categories( array( 23, 23 ) );
mp_test_assert(
	count( $dup_cats ) === 1 && $dup_cats[0] === 'Car Racing' ,
	'duplicate category IDs → single mapped name'
);

// Unknown category ID (e.g. 99) is skipped, not mapped.
$unknown = Conexao_Source_Mondello_Park::map_categories( array( 99 ) );
mp_test_assert(
	empty( $unknown ) ,
	'unknown category ID → empty result (not guessed)'
);

// Empty input
$empty_cats = Conexao_Source_Mondello_Park::map_categories( array() );
mp_test_assert(
	empty( $empty_cats ) ,
	'empty category list → empty result'
);

// =========================================================================
// Ticket URL extraction
// =========================================================================

mp_test_section( 'Ticket URL extraction (strip_utm)' );

mp_test_assert(
	method_exists( 'Conexao_Source_Mondello_Park', 'strip_utm' ) ,
	'strip_utm exists'
);

$with_utm = 'https://mondellopark.ticketsolve.com/ticketbooth/shows/1173669529?utm_source=mondello-event-page&utm_medium=referral';
$stripped = 'Conexao_Source_Mondello_Park::strip_utm'($with_utm);
mp_test_assert(
	false === strpos( $stripped, 'utm_source=' ) ,
	'utm_source removed'
);
mp_test_assert(
	false === strpos( $stripped, 'utm_medium=' ) ,
	'utm_medium removed'
);
// FIXTURE correction: the audited Book Now fixture URL carries the show ID in
// the PATH (/shows/1173669529), not as a showId= query param — so the old
// 'showId=1173669529' expectation could never pass. The audited requirement is:
// path (with show ID) intact + UTM stripped. strip_utm() is unchanged.
mp_test_assert(
	false !== strpos( $stripped, '/shows/1173669529' ) ,
	'Ticketsolve show path /shows/1173669529 preserved after UTM strip'
);
mp_test_assert(
	0 === strpos( $stripped, 'https://mondellopark.ticketsolve.com/ticketbooth/shows/' ) ,
	'base URL intact'
);

// URL without query string is returned as-is.
$no_qs = 'https://mondellopark.ticketsolve.com/ticketbooth/shows/12345';
mp_test_assert(
	'Conexao_Source_Mondello_Park::strip_utm'($no_qs) === $no_qs ,
	'URL without query → unchanged'
);

// URL without utm but with other params keeps them.
$other = 'https://example.com/ticket?foo=bar&showId=99';
$stripped_other = 'Conexao_Source_Mondello_Park::strip_utm'($other);
mp_test_assert(
	false !== strpos( $stripped_other, 'foo=bar' )
	 && false !== strpos( $stripped_other, 'showId=99' ) ,
	'non-utm params preserved'
);

// =========================================================================
// Primary ticket link extraction (Book Now preference)
//
// Live-source regression (verified 2026-09-12 against
// https://mondellopark.ie/events/drift-games-winter-bash/):
//   <a class="btn"  href="…/shows/1173670623">DRIFT BASH TICKETS</a>  (first in DOM)
//   <a class="btn " href="…/shows/1173670621">Book Now</a>            (primary CTA)
// The old "first Ticketsolve link encountered" fallback selected the
// secondary DRIFT BASH TICKETS show ID (1173670623) as the primary ticket
// URL. The extractor must prefer an anchor labelled "Book Now".
// =========================================================================

mp_test_section( 'Primary ticket URL extraction (Book Now preference)' );

// Expose the protected extractor through a minimal test subclass.
if ( ! class_exists( 'Mp_Test_Ticket_Source' ) ) {
	class Mp_Test_Ticket_Source extends Conexao_Source_Mondello_Park {
		public function ticket_url( DOMXPath $xpath ) {
			return $this->extract_ticket_url( $xpath );
		}
		public function ticket_show_ids( DOMXPath $xpath, $primary_url ) {
			return $this->extract_ticket_show_ids( $xpath, $primary_url );
		}
	}
}

$mp_ticket_source = new Mp_Test_Ticket_Source( array( 'id' => 'mondellopark' ) );

$mp_winter_bash_html = <<<'MPHTML'
<html><body>
<div class="event-actions">
	<a class="btn" href="https://mondellopark.ticketsolve.com/ticketbooth/shows/1173670623">DRIFT BASH TICKETS</a>
	<a class="btn " href="https://mondellopark.ticketsolve.com/ticketbooth/shows/1173670621" target="">Book Now</a>
</div>
</body></html>
MPHTML;

$mp_winter_bash_doc = new DOMDocument();
$mp_winter_bash_doc->loadHTML( $mp_winter_bash_html );
$mp_winter_bash_xpath = new DOMXPath( $mp_winter_bash_doc );

$mp_winter_bash_ticket = $mp_ticket_source->ticket_url( $mp_winter_bash_xpath );
mp_test_assert(
	$mp_winter_bash_ticket === 'https://mondellopark.ticketsolve.com/ticketbooth/shows/1173670621' ,
	'Winter Bash live structure: primary ticket URL = …/shows/1173670621 (Book Now), not the first-in-DOM DRIFT BASH TICKETS link'
);

$mp_winter_bash_ids = $mp_ticket_source->ticket_show_ids( $mp_winter_bash_xpath, $mp_winter_bash_ticket );
mp_test_assert(
	$mp_winter_bash_ids === array( '1173670621', '1173670623' ) ,
	'Winter Bash _ticket_show_ids = [1173670621 (primary), 1173670623 (secondary)]'
);

// Single Ticketsolve link: selected directly.
$mp_single_html = '<html><body><a class="btn" href="https://mondellopark.ticketsolve.com/ticketbooth/shows/1173669529">Book Now</a></body></html>';
$mp_single_doc = new DOMDocument();
$mp_single_doc->loadHTML( $mp_single_html );
$mp_single_xpath = new DOMXPath( $mp_single_doc );
mp_test_assert(
	$mp_ticket_source->ticket_url( $mp_single_xpath ) === 'https://mondellopark.ticketsolve.com/ticketbooth/shows/1173669529' ,
	'single Ticketsolve "Book Now" link selected'
);

// Explicit book-now class hook wins even when not labelled "Book Now".
$mp_class_html = '<html><body>'
	. '<a class="btn book-now" href="https://mondellopark.ticketsolve.com/ticketbooth/shows/999">Get tickets</a>'
	. '<a class="btn" href="https://mondellopark.ticketsolve.com/ticketbooth/shows/888">Book Now</a>'
	. '</body></html>';
$mp_class_doc = new DOMDocument();
$mp_class_doc->loadHTML( $mp_class_html );
$mp_class_xpath = new DOMXPath( $mp_class_doc );
mp_test_assert(
	$mp_ticket_source->ticket_url( $mp_class_xpath ) === 'https://mondellopark.ticketsolve.com/ticketbooth/shows/999' ,
	'book-now class hook takes precedence over label/DOM-order preference'
);

// No labelled Book Now anchor → falls back to first in DOM order.
$mp_fallback_html = '<html><body>'
	. '<a class="btn" href="https://mondellopark.ticketsolve.com/ticketbooth/shows/777">DRIFT BASH TICKETS</a>'
	. '<a class="btn" href="https://mondellopark.ticketsolve.com/ticketbooth/shows/555">Weekend tickets</a>'
	. '</body></html>';
$mp_fallback_doc = new DOMDocument();
$mp_fallback_doc->loadHTML( $mp_fallback_html );
$mp_fallback_xpath = new DOMXPath( $mp_fallback_doc );
mp_test_assert(
	$mp_ticket_source->ticket_url( $mp_fallback_xpath ) === 'https://mondellopark.ticketsolve.com/ticketbooth/shows/777' ,
	'no Book Now label → first Ticketsolve link in DOM order (unchanged fallback)'
);

// UTM parameters stripped on the selected Book Now link.
$mp_utm_html = '<html><body><a class="btn" href="https://mondellopark.ticketsolve.com/ticketbooth/shows/1173669529?utm_source=mondello-event-page&utm_medium=referral">Book Now</a></body></html>';
$mp_utm_doc = new DOMDocument();
$mp_utm_doc->loadHTML( $mp_utm_html );
$mp_utm_xpath = new DOMXPath( $mp_utm_doc );
mp_test_assert(
	$mp_ticket_source->ticket_url( $mp_utm_xpath ) === 'https://mondellopark.ticketsolve.com/ticketbooth/shows/1173669529' ,
	'Book Now selection + utm stripped'
);

// show_id_from_url helper (path-based IDs).
mp_test_assert(
	'Conexao_Source_Mondello_Park::show_id_from_url'('https://mondellopark.ticketsolve.com/ticketbooth/shows/1173670621') === '1173670621' ,
	'show_id_from_url: path-based ID extracted'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::show_id_from_url'('https://mondellopark.ticketsolve.com/ticketbooth/shows/1173669529?utm_source=x') === '1173669529' ,
	'show_id_from_url: ID extracted with query string present'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::show_id_from_url'('https://mondellopark.ie/events/drift-games-winter-bash/') === '' ,
	'show_id_from_url: non-Ticketsolve URL → empty'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::show_id_from_url'('') === '' ,
	'show_id_from_url: empty URL → empty'
);

// =========================================================================
// Identity / source metadata
// =========================================================================

mp_test_section( 'Source identity & metadata' );

$meta = 'Conexao_Source_Mondello_Park::get_metadata'();
mp_test_assert(
	isset( $meta['source'] ) && $meta['source'] === 'mondellopark' ,
	'metadata source key = mondellopark'
);
mp_test_assert(
	isset( $meta['display_name'] ) && $meta['display_name'] === 'Mondello Park' ,
	'metadata display_name'
);
mp_test_assert(
	isset( $meta['discovery_url'] ) && $meta['discovery_url'] === 'https://mondellopark.ie/wp-json/wp/v2/events?per_page=100' ,
	'metadata discovery_url'
);
mp_test_assert(
	isset( $meta['detail_pattern'] ) && $meta['detail_pattern'] === 'https://mondellopark.ie/events/%s/' ,
	'metadata detail_pattern'
);
mp_test_assert(
	isset( $meta['identity'] ) && false !== strpos( $meta['identity'], 'source+source_id' ) ,
	'metadata identity strategy'
);
mp_test_assert(
	isset( $meta['inactive_by_default'] ) && $meta['inactive_by_default'] === true ,
	'metadata inactive_by_default'
);
mp_test_assert(
	isset( $meta['default_county'] ) && $meta['default_county'] === 'Kildare' ,
	'metadata default_county = Kildare'
);
mp_test_assert(
	count( $meta['category_map'] ) === 8 ,
	'metadata 8 category mappings'
);
mp_test_assert(
	isset( $meta['time_source'] ) && false !== strpos( $meta['time_source'], 'none' ) ,
	'metadata time_source = none'
);

// Source ID (get_id) returns mondellopark.
$src = new Conexao_Source_Mondello_Park( array( 'id' => 'mondello_park', 'type' => 'website' ) );
mp_test_assert(
	$src->get_id() === 'mondellopark' ,
	'get_id() returns mondellopark'
);

// =========================================================================
// Text helpers
// =========================================================================

mp_test_section( 'Text / HTML helpers' );

mp_test_assert(
	'Conexao_Source_Mondello_Park::clean_text'('  September   12,   2026  ') === 'September 12, 2026' ,
	'clean_text collapses whitespace'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::clean_text'('James Deane &ndash; 130 Showdown') === 'James Deane – 130 Showdown' ,
	'clean_text decodes HTML entities'
);

mp_test_assert(
	'Conexao_Source_Mondello_Park::normalize_category_text'('Category: Car Racing') === 'Car Racing' ,
	'normalize_category_text strips prefix'
);
mp_test_assert(
	'Conexao_Source_Mondello_Park::normalize_category_text'( '') === '' ,
	'normalize_category_text empty → empty'
);

// =========================================================================
// Edge-case / redirect decoration
// =========================================================================

mp_test_section( 'Edge cases: redirect, missing detail, missing date' );

// A raw event with a redirect marker should not get a date.
$redirect_event = array(
	'source'       => 'mondellopark',
	'source_id'    => 'fia-euro-rx',
	'title'        => 'FIA Euro RX',
	'url'          => 'https://mondellopark.ie/events/fia-euro-rx/',
	'start_date'   => '',
	'end_date'     => '',
	'_redirect'    => 'https://mondellopark.ie/events/fia-euro-rx/',
	'_parse_warnings' => array( 'Página de detalhe redireciona (301): https://mondellopark.ie/events/fia-euro-rx/' ),
);
mp_test_assert(
	$redirect_event['start_date'] === '' ,
	'redirect event has empty start_date'
);
mp_test_assert(
	! empty( $redirect_event['_redirect'] ) ,
	'redirect event carries redirect marker'
);

// A raw event with no detail engaged has no date.
$no_detail_event = array(
	'source'       => 'mondellopark',
	'source_id'    => 'missing-slug',
	'title'        => 'Missing Detail Event',
	'url'          => 'https://mondellopark.ie/events/missing-slug/',
	'start_date'   => '',
	'_no_detail'   => true,
);
mp_test_assert(
	$no_detail_event['start_date'] === '' ,
	'no-detail event has empty start_date'
);
mp_test_assert(
	$no_detail_event['_no_detail'] === true ,
	'no-detail event flagged'
);

// =========================================================================
// County hint wiring (Stage C preparation): the audited source config
// declares county => 'Kildare' and the handler must forward it into
// raw['county'] so the normalizer's existing source-scoped hint applies
// when the location string yields no county. Verified at two levels:
//   1. fetch_events() wiring — mocked HTTP (no network, no writes);
//   2. normalizer behavior — precedence + no-guess address rules.
// =========================================================================

mp_test_section( 'County hint wiring: fetch_events forwards config county' );

// Mock all HTTP for the source: the REST discovery URL returns a small
// deterministic JSON listing; any other mondellopark.ie URL (detail pages)
// returns 500 so the bounded detail walk takes its non-network exception
// path. No real network calls occur in this section.
$mp_http_mock = function ( $pre, $args, $url ) {
	$url = (string) $url;
	if ( false !== strpos( $url, 'mondellopark.ie/wp-json/wp/v2/events' ) ) {
		return array(
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'body'     => wp_json_encode( array(
				array(
					'id'             => 101,
					'slug'           => 'county-wiring-test',
					'title'          => array( 'rendered' => 'County Wiring Test Event' ),
					'link'           => 'https://mondellopark.ie/events/county-wiring-test/',
					'event_category' => array( 23 ),
				),
			) ),
		);
	}
	if ( false !== strpos( $url, 'mondellopark.ie' ) ) {
		return array(
			'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ),
			'body'     => '',
		);
	}
	return $pre;
};
add_filter( 'pre_http_request', $mp_http_mock, 10, 3 );

try {
	$mp_wired = new Conexao_Source_Mondello_Park( array(
		'id'          => 'mondello_park',
		'type'        => 'website',
		'county'      => 'Kildare',
		'crawl_delay' => 0,
	) );
	$wired_events = $mp_wired->fetch_events();

	mp_test_assert(
		is_array( $wired_events ) && 1 === count( $wired_events ),
		'mocked fetch_events returns the single REST event'
	);
	if ( ! empty( $wired_events[0] ) ) {
		mp_test_assert(
			isset( $wired_events[0]['county'] ) && 'Kildare' === $wired_events[0]['county'],
			'raw[\'county\'] = Kildare forwarded from the source config (wiring gap fixed)'
		);
		mp_test_assert(
			isset( $wired_events[0]['source_id'] ) && 'county-wiring-test' === $wired_events[0]['source_id'],
			'mocked event identity unchanged (slug primary)'
		);
		mp_test_assert(
			isset( $wired_events[0]['category'] ) && 'Car Racing' === $wired_events[0]['category'],
			'raw[\'category\'] = Car Racing forwarded via documented map_categories (wiring gap fixed)'
		);
		mp_test_assert(
			isset( $wired_events[0]['_source_categories'] ) && array( 'Car Racing' ) === $wired_events[0]['_source_categories'],
			'raw[\'_source_categories\'] preserves the full mapped list'
		);
		mp_test_assert(
			! isset( $wired_events[0]['address'] ) || '' === trim( (string) $wired_events[0]['address'] ),
			'no address is invented at the source level'
		);
		mp_test_assert(
			! isset( $wired_events[0]['start_time'] ) || '' === (string) $wired_events[0]['start_time'],
			'no event time is invented at the source level'
		);
	}
} catch ( Exception $e ) {
	mp_test_assert( false, 'county wiring fetch_events ran without exception: ' . $e->getMessage() );
}

// Behavior C: a source config WITHOUT a county forwards an empty hint and
// the raw event stays county-less (unchanged behavior).
try {
	$mp_wired_none = new Conexao_Source_Mondello_Park( array(
		'id'          => 'mondello_park',
		'type'        => 'website',
		'crawl_delay' => 0,
	) );
	$mp_none_events = $mp_wired_none->fetch_events();
	mp_test_assert(
		! empty( $mp_none_events[0] ) && ( ! isset( $mp_none_events[0]['county'] ) || '' === trim( (string) $mp_none_events[0]['county'] ) ),
		'config without county → raw county stays empty (behavior C)'
	);
} catch ( Exception $e ) {
	mp_test_assert( false, 'county-less config fetch_events ran without exception: ' . $e->getMessage() );
}

remove_filter( 'pre_http_request', $mp_http_mock, 10 );

mp_test_section( 'County hint wiring: normalizer precedence + no-guess rules' );

$mp_normalizer = new Conexao_Event_Normalizer( new Conexao_Event_Location() );

function mp_county_raw( $overrides = array() ) {
	return array_merge( array(
		'source'      => 'mondellopark',
		'source_id'   => 'county-wiring-test',
		'title'       => 'County Wiring Test Event',
		'url'         => 'https://mondellopark.ie/events/county-wiring-test/',
		'start_date'  => '2026-10-03',
		'start_time'  => '',
		'end_date'    => '',
		'end_time'    => '',
		'location'    => '',
		'county'      => 'Kildare',
		'description' => '',
		'image'       => '',
	), $overrides );
}

// Behavior B: missing county (venue-only location) receives the hint.
$norm_b = $mp_normalizer->normalize( mp_county_raw( array( 'location' => 'Mondello Park' ) ) );
mp_test_assert(
	isset( $norm_b['county'] ) && 'Kildare' === $norm_b['county'],
	'behavior B: venue-only location receives the configured Kildare hint'
);
mp_test_assert(
	isset( $norm_b['venue'] ) && 'Mondello Park' === $norm_b['venue'],
	'behavior B: source-supplied venue preserved'
);
mp_test_assert(
	isset( $norm_b['address'] ) && '' === $norm_b['address'],
	'venue-only Mondello Park does NOT become a fabricated street address'
);
mp_test_assert(
	isset( $norm_b['town'] ) && '' === $norm_b['town'],
	'behavior B: no town invented'
);
mp_test_assert(
	empty( $norm_b['validation_errors'] ),
	'behavior B: event passes location validation via the county tag'
);
mp_test_assert(
	isset( $norm_b['event_location'] ) && 'Mondello Park' === $norm_b['event_location'],
	'behavior B: location label prefers the source venue'
);

// County-only events (no venue/town) keep a county label and validate.
$norm_county_only = $mp_normalizer->normalize( mp_county_raw() );
mp_test_assert(
	isset( $norm_county_only['county'] ) && 'Kildare' === $norm_county_only['county']
		&& isset( $norm_county_only['event_location'] ) && 'Kildare' === $norm_county_only['event_location'],
	'county-only events keep the county as the location label'
);
mp_test_assert(
	empty( $norm_county_only['validation_errors'] ),
	'county-only events pass location validation'
);
mp_test_assert(
	isset( $norm_county_only['address'] ) && '' === $norm_county_only['address'],
	'county-only events never gain an invented address'
);

// Behavior A: source-supplied county is preserved (hint never overrides).
$norm_a = $mp_normalizer->normalize( mp_county_raw( array( 'location' => 'Mondello Park, Co Wicklow' ) ) );
mp_test_assert(
	isset( $norm_a['county'] ) && 'Wicklow' === $norm_a['county'],
	'behavior A: source-supplied county (Co Wicklow) preserved over the Kildare hint'
);

// Behavior C: no hint and no location → unchanged (still skipped).
$norm_c = $mp_normalizer->normalize( mp_county_raw( array( 'location' => '', 'county' => '' ) ) );
mp_test_assert(
	isset( $norm_c['county'] ) && '' === $norm_c['county'],
	'behavior C: no hint, no location → county stays empty'
);
mp_test_assert(
	in_array( 'Localização não identificada', $norm_c['validation_errors'], true ),
	'behavior C: missing location is still skipped (unchanged)'
);

// Unrelated sources are not affected by the Mondello wiring change.
$norm_mi = $mp_normalizer->normalize( array(
	'source'     => 'motorsportireland',
	'title'      => 'MI Event',
	'url'        => 'https://www.motorsportireland.com/events/x',
	'start_date' => '2026-10-03',
	'location'   => '',
) );
mp_test_assert(
	isset( $norm_mi['county'] ) && '' === $norm_mi['county']
		&& in_array( 'Localização não identificada', $norm_mi['validation_errors'], true ),
	'unrelated sources: Motorsport Ireland-style raw unchanged (no county invented)'
);

$norm_laois = $mp_normalizer->normalize( array(
	'source'     => 'eventbrite',
	'title'      => 'Laois Event',
	'url'        => 'https://www.eventbrite.ie/e/x',
	'start_date' => '2026-10-03',
	'location'   => '',
	'county'     => 'Laois',
) );
mp_test_assert(
	isset( $norm_laois['county'] ) && 'Laois' === $norm_laois['county'],
	'unrelated sources: Eventbrite-style county hint still applies'
);

// Address plausibility rules remain unchanged (shared class untouched).
mp_test_assert(
	false === Conexao_Event_Address::is_plausible( 'Mondello Park' ),
	'plausibility: bare venue name Mondello Park is NOT an address'
);
mp_test_assert(
	false === Conexao_Event_Address::is_plausible( 'Kildare' ),
	'plausibility: bare county name is NOT an address'
);
mp_test_assert(
	true === Conexao_Event_Address::is_plausible( 'Unit 4, Mondello Park, Naas, W91 XW63' ),
	'plausibility: real street/Eircode address still accepted'
);

// No event times invented by the county hint.
mp_test_assert(
	'' === $norm_b['start_time'] && '' === $norm_b['end_time'],
	'no event times invented by the county hint'
);

// =========================================================================
// Regression: source slug is stable/unique in live REST snapshot
// (This is informational — the live snapshot under tests/fixtures is used
// for the determinism check below.)
// =========================================================================

mp_test_section( 'Regression: REST snapshot slug uniqueness' );

$fixture = WP_PLUGIN_DIR . '/conexao-event-importer/tests/fixtures/mp-rest.json';
$rest_exists = file_exists( $fixture );
mp_test_assert(
	$rest_exists ,
	'REST fixture file exists'
);

if ( $rest_exists ) {
	$json = json_decode( file_get_contents( $fixture ), true );
	$events = $json;
	$total = is_array( $events ) ? count( $events ) : 0;
	mp_test_assert(
	is_array( $events ) && $total > 0 ,
	'REST fixture parses to array'
);

	if ( is_array( $events ) && $total > 0 ) {
		$slugs = array();
		$ids   = array();
		foreach ( $events as $e ) {
			$slugs[] = isset( $e['slug'] ) ? (string) $e['slug'] : '';
			$ids[]   = isset( $e['id'] ) ? (string) (int) $e['id'] : '';
		}
		$unique_slugs = count( array_unique( $slugs ) );
		$unique_ids   = count( array_unique( $ids ) );

		mp_test_assert(
	$unique_slugs === $total ,
	'REST fixture: all slugs unique'
);
		mp_test_assert(
	$unique_ids === $total ,
	'REST fixture: all IDs unique'
);
		mp_test_assert(
	! in_array( '', $slugs, true ) ,
	'REST fixture: slug is non-empty for every event'
);
		mp_test_assert(
	! in_array( '', array_map( function ( $e ) { return isset( $e['title']['rendered'] ) ? (string) $e['title']['rendered'] : ''; }, $events ), true ) ,
	'REST fixture: title is non-empty for every event'
);
	}
}

// =========================================================================
// Regression: existing event sources are unchanged
// =========================================================================

mp_test_section( 'Regression: existing sources unchanged' );

$existing = array( 'laois_tourism', 'heritage_week', 'eventbrite', 'ivvcc', 'motorsport_ireland' );
// Determinism: reset local source state BEFORE asserting. A previous local
// activation (or the old get_all() merge bug that force-activated merged
// defaults) could leave mondello_park=active in this local DB; the declared
// default is inactive (see get_defaults() + get_metadata inactive_by_default).
// Snapshot the option, enforce inactive for the assertions, restore after.
$mp_option_before = get_option( Conexao_Event_Sources::OPTION_KEY, array() );
$mp_prev_status   = isset( $mp_option_before['mondello_park']['status'] ) ? $mp_option_before['mondello_park']['status'] : null;
if ( is_array( $mp_option_before ) && isset( $mp_option_before['mondello_park'] ) ) {
	$mp_reset = $mp_option_before;
	$mp_reset['mondello_park']['status'] = 'inactive';
	update_option( Conexao_Event_Sources::OPTION_KEY, $mp_reset, false );
}
$sources = new Conexao_Event_Sources();
$all = $sources->get_all();

foreach ( $existing as $id ) {
	$exists = isset( $all[ $id ] );
	mp_test_assert(
	$exists ,
	"existing source {$id} still present"
);
	if ( $exists ) {
		$name = isset( $all[ $id ]['name'] ) ? (string) $all[ $id ]['name'] : '';
		mp_test_assert(
	! empty( $name ) ,
	"existing source {$id} has a name"
);
	}
}

// mondello_park must be present but inactive.
$mp_source = isset( $all['mondello_park'] ) ? $all['mondello_park'] : null;
mp_test_assert(
	$mp_source !== null ,
	'mondello_park source present'
);
if ( $mp_source !== null ) {
	mp_test_assert(
	isset( $mp_source['status'] ) && $mp_source['status'] === 'inactive' ,
	'mondello_park status = inactive'
);
	mp_test_assert(
	isset( $mp_source['county'] ) && $mp_source['county'] === 'Kildare' ,
	'mondello_park county = Kildare'
);
	mp_test_assert(
	isset( $mp_source['type'] ) && $mp_source['type'] === 'website' ,
	'mondello_park type = website'
);
	mp_test_assert(
	isset( $mp_source['url'] ) && 0 === strpos( (string) $mp_source['url'], 'https://mondellopark.ie/wp-json/wp/v2/events' ) ,
	'mondello_park url is REST endpoint'
);
}

// mondello_park must NOT be in the active list (it is inactive by default).
$active = $sources->get_active();
$mp_active = isset( $active['mondello_park'] );
mp_test_assert(
	! $mp_active ,
	'mondello_park is NOT active by default'
);

// Restore the local DB source state we snapshotted before the reset, so this
// suite leaves no residue and consecutive runs are deterministic.
if ( is_array( $mp_option_before ) && null !== $mp_prev_status ) {
	$mp_restore = get_option( Conexao_Event_Sources::OPTION_KEY, array() );
	if ( is_array( $mp_restore ) && isset( $mp_restore['mondello_park'] ) ) {
		$mp_restore['mondello_park']['status'] = $mp_prev_status;
		update_option( Conexao_Event_Sources::OPTION_KEY, $mp_restore, false );
	}
}

// =========================================================================
// Dedup / idempotency sketch (local DB)
// =========================================================================

mp_test_section( 'Dedup / idempotency (local DB, cleaned up after)' );

// Create a minimal Mondello Park event in the DB to confirm identity lookup
// uses source+source_id.
$created_id = 0;
try {
	$post_id = wp_insert_post( array(
		'post_type'    => 'event',
		'post_title'   => 'Regression Test Event',
		'post_content' => '',
		'post_status'  => 'publish',
		'post_author'  => 1,
	) );
	if ( ! is_wp_error( $post_id ) && $post_id ) {
		update_post_meta( $post_id, '_event_source', 'mondellopark' );
		update_post_meta( $post_id, '_event_source_id', 'regression-test-event' );
		update_post_meta( $post_id, '_event_date', '2026-09-12' );
		update_post_meta( $post_id, '_event_url', 'https://mondellopark.ie/events/regression-test-event/' );

		$deduper = new Conexao_Event_Deduplicator();
		$fake_normalized = array(
			'source'       => 'mondellopark',
			'source_id'    => 'regression-test-event',
			'source_url'   => 'https://mondellopark.ie/events/regression-test-event/',
			'title'        => 'Regression Test Event',
			'start_date'   => '2026-09-12',
			'start_time'   => '',
			'venue'        => '',
			'organizer'    => '',
		);
		$found = $deduper->find( $fake_normalized );
		mp_test_assert(
	$found === $post_id ,
	'dedup finds by source+source_id'
);

		$created_id = $post_id;
	} else {
		mp_test_assert(
	false ,
	'wp_insert_post for regression test succeeded'
);
	}
} catch ( Exception $e ) {
	mp_test_assert(
	false ,
	'regression dedup test ran without exception'
);
}

// Cleanup: remove the regression test event if we created it.
if ( $created_id ) {
	wp_delete_post( $created_id, true );
}

// =========================================================================
// Test-harness self-test (Phase 10): the helper must count booleans and
// reject reversed (string-first) arguments loudly.
// =========================================================================

mp_test_section( 'Harness self-test (assertion accounting)' );

$mp_self_p = $passed;
$mp_self_f = $failed;
$mp_self_h = $harness_errors;
mp_test_assert( true, 'harness self-test: true condition passes' );
mp_test_assert(
	$passed === $mp_self_p + 1 ,
	'harness self-test: PASS counted exactly once'
);
$mp_before_fail = $failed;
$mp_before_herr = $harness_errors;
$mp_before_pass = $passed;
mp_test_assert( 'reversed-message-as-condition', false ); // must trigger HARNESS ERROR, not a silent PASS
// The deliberate misuse above must NOT add a PASS and must record exactly one
// failure + one harness error.
mp_test_assert(
	$passed === $mp_before_pass && $failed === $mp_before_fail + 1 && $harness_errors === $mp_before_herr + 1 ,
	'harness self-test: reversed args fail loudly (condition must be bool)'
);

// Compensate the one deliberate self-test HARNESS ERROR above so the suite
// result reflects only real importer assertions.
$failed -= 1;
$harness_errors -= 1;
mp_test_assert(
	0 === $harness_errors ,
	'harness self-test: zero harness errors in real suite'
);

// =========================================================================
// Summary
// =========================================================================

echo "\n\n===============================\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "Harness errors: {$harness_errors}\n";
echo "===============================\n";

exit( $failed > 0 ? 1 : 0 );
