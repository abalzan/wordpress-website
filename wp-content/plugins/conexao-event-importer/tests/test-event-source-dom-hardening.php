<?php
/**
 * Event-source DOM hardening.
 *
 * Proves the contract introduced when the importer's DOM/XML parsers were
 * hardened against PHPStan level-5 `method.notFound` / `argument.type`
 * findings:
 *
 *   A. the shared element guard (`Conexao_Source_Base::as_element()`) returns
 *      an element as itself and `null` for every other node type — it never
 *      casts, guesses or re-queries;
 *   B. the text extractors fail safe: an element yields its text, while a text
 *      node, a comment node or `null` yields '';
 *   C. a REAL committed fixture still parses (the guards must not change what a
 *      valid document produces), deterministically across two runs;
 *   D. missing elements produce empty results instead of raising;
 *   E. malformed input is refused safely;
 *   F. the de-duplicated month map still resolves every month, including the
 *      one whose key previously appeared twice.
 *
 * Read-only: no post, term, option, transient or file is written.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

/**
 * Test-only probe over the Mondello source.
 *
 * Exposes the protected element guard, the Mondello text extractor and the
 * month map to this suite. It re-implements none of them — every assertion
 * exercises the shipped implementation.
 */
final class Conexao_Test_Source_Dom_Probe extends Conexao_Source_Mondello_Park {

	/**
	 * Expose the inherited element guard.
	 *
	 * @param mixed $node Candidate node.
	 * @return DOMElement|null
	 */
	public static function probe_element( $node ) {
		return self::as_element( $node );
	}

	/**
	 * Expose the Mondello text extractor (it overrides the base one).
	 *
	 * @param mixed $node Candidate node.
	 * @return string
	 */
	public function probe_text( $node ) {
		return $this->extract_text( $node );
	}

	/**
	 * Expose the month map.
	 *
	 * @param string $name Month name.
	 * @return int|false
	 */
	public static function probe_month( $name ) {
		return self::month_name_to_number( $name );
	}
}

/**
 * Test-only probe over a source that does not override `extract_text()`, so the
 * BASE implementation is what gets exercised.
 */
final class Conexao_Test_Source_Base_Text_Probe extends Conexao_Source_Laois_Tourism {

	/**
	 * Call the inherited (base-class) extractor.
	 *
	 * @param mixed $node Candidate node.
	 * @return string
	 */
	public function probe_base_extract_text( $node ) {
		return $this->extract_text( $node );
	}
}

/**
 * A tiny deterministic document used by sections A, B and D.
 *
 * @return DOMDocument
 */
function conexao_dom_hardening_document() {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<html><body><div id="probe">hello <b>world</b></div><p>plain</p></body></html>' );
	libxml_clear_errors();

// ---------------------------------------------------------------------------
// A. Element narrowing
// ---------------------------------------------------------------------------

test_section( 'A. as_element() narrows and never guesses' );

$dom  = conexao_dom_hardening_document();
$divs = $dom->getElementsByTagName( 'div' );
$div  = $divs->item( 0 );
$note = $dom->createComment( 'note' );

test_require( $div instanceof DOMElement, 'DOM fixture', 'the fixture document contains a div element', 'check section A' );

$text = $div->firstChild;
test_require( $text instanceof DOMText, 'DOM fixture', 'the fixture div starts with a text node', 'check section A' );

assert_true( Conexao_Test_Source_Dom_Probe::probe_element( $div ) === $div, 'A1 an element is returned as itself (identity, not a copy)' );
assert_true( null === Conexao_Test_Source_Dom_Probe::probe_element( $text ), 'A2 a text node is refused (null), never cast to an element' );
assert_true( null === Conexao_Test_Source_Dom_Probe::probe_element( $note ), 'A3 a comment node is refused (null)' );
assert_true( null === Conexao_Test_Source_Dom_Probe::probe_element( null ), 'A4 null stays null' );
assert_true( null === Conexao_Test_Source_Dom_Probe::probe_element( 'not-a-node' ), 'A5 a non-node value is refused (null)' );
assert_true( null === Conexao_Test_Source_Dom_Probe::probe_element( array() ), 'A6 an array is refused (null)' );

// ---------------------------------------------------------------------------
// B. Text extraction fails safe
// ---------------------------------------------------------------------------

test_section( 'B. extract_text() fails safe' );

$probe = new Conexao_Test_Source_Dom_Probe( array() );

assert_contains( 'hello', $probe->probe_text( $div ), 'B1 an element yields its text' );
assert_contains( 'world', $probe->probe_text( $div ), 'B2 nested text is included' );
assert_equals( '', $probe->probe_text( $text ), 'B3 a text node yields no element text' );
assert_equals( '', $probe->probe_text( $note ), 'B4 a comment node yields no element text' );
assert_equals( '', $probe->probe_text( null ), 'B5 a missing node yields an empty string' );

$base_probe = new Conexao_Test_Source_Base_Text_Probe( array() );
$base_text  = trim( preg_replace( '/\s+/', ' ', $base_probe->probe_base_extract_text( $div ) ) );

assert_equals( 'hello world', $base_text, 'B6 the BASE extractor still returns an element\'s text' );
assert_equals( '', $base_probe->probe_base_extract_text( $text ), 'B7 the BASE extractor refuses a text node' );
assert_equals( '', $base_probe->probe_base_extract_text( null ), 'B8 the BASE extractor refuses a missing node' );

assert_contains( 'hello', Conexao_Source_Motorsport_Ireland::extract_visible_text( $div ), 'B9 extract_visible_text() still reads an element' );

$bare_text = $dom->createTextNode( 'bare visible text' );

assert_equals(
	'bare visible text',
	Conexao_Source_Motorsport_Ireland::extract_visible_text( $bare_text ),
	'B10 extract_visible_text() on a non-element node returns its text (never raises)'
);
assert_equals( '', Conexao_Source_Motorsport_Ireland::extract_visible_text( null ), 'B11 extract_visible_text() on null returns an empty string' );


	return $dom;
}


// ---------------------------------------------------------------------------
// C. A real fixture still parses, deterministically
// ---------------------------------------------------------------------------

test_section( 'C. A real fixture still parses (the guards are behaviour-neutral)' );

$fixture = __DIR__ . '/fixtures/mi-listing.html';

test_require( is_readable( $fixture ), 'mi-listing fixture', 'the committed Motorsport Ireland listing fixture is readable', 'check tests/fixtures/mi-listing.html' );

$html  = (string) file_get_contents( $fixture );
$first = Conexao_Source_Motorsport_Ireland::parse_listing_cards( $html, 'https://www.motorsportireland.com/events' );
$again = Conexao_Source_Motorsport_Ireland::parse_listing_cards( $html, 'https://www.motorsportireland.com/events' );

assert_true( is_array( $first ) && count( $first ) > 0, 'C1 the fixture yields at least one card' );
assert_equals( count( $first ), count( $again ), 'C2 two parses of the same fixture yield the same card count' );

$card = $first[0];
assert_true( isset( $card['source_id'] ) && '' !== (string) $card['source_id'], 'C3 a card keeps its stable source_id' );
assert_true( isset( $card['title'] ) && '' !== (string) $card['title'], 'C4 a card keeps its title' );
assert_true( isset( $card['url'] ) && 0 === strpos( (string) $card['url'], 'https://' ), 'C5 a card keeps an absolute URL' );

$missing_ids = 0;
foreach ( $first as $candidate ) {
	if ( ! isset( $candidate['source_id'] ) || '' === (string) $candidate['source_id'] ) {
		$missing_ids++;
	}
}
assert_equals( 0, $missing_ids, 'C6 every parsed card carries a source_id' );

// ---------------------------------------------------------------------------
// D. Missing elements produce empty results
// ---------------------------------------------------------------------------

test_section( 'D. Missing elements produce empty results, never a raise' );

assert_equals( array(), Conexao_Source_Motorsport_Ireland::parse_listing_cards( '<html><body><p>nothing here</p></body></html>' ), 'D1 a card-free document yields no Motorsport cards' );
assert_equals( array(), Conexao_Source_Motorsport_Ireland::parse_listing_cards( '' ), 'D2 empty input yields no Motorsport cards' );
assert_equals( array(), Conexao_Source_Ivvcc::parse_calendar_events( '<html><body><div>nada</div></body></html>' ), 'D3 a card-free document yields no IVVCC cards' );
assert_equals( array(), Conexao_Source_Ivvcc::parse_calendar_events( '' ), 'D4 empty input yields no IVVCC cards' );
assert_equals(
	array( 'start_date' => '', 'start_time' => '', 'end_date' => '', 'end_time' => '' ),
	Conexao_Source_Motorsport_Ireland::parse_jsonld_dates( '<html><body><p>no JSON-LD</p></body></html>' ),
	'D5 a page without JSON-LD yields empty dates'
);

// ---------------------------------------------------------------------------
// E. Malformed input is refused safely
// ---------------------------------------------------------------------------

test_section( 'E. Malformed input is refused safely' );

assert_equals( 0, count( Conexao_Source_Motorsport_Ireland::parse_listing_cards( '<<<not html>>>' ) ), 'E1 broken markup yields no cards' );
assert_equals(
	array( 'start_date' => '', 'start_time' => '', 'end_date' => '', 'end_time' => '' ),
	Conexao_Source_Motorsport_Ireland::parse_jsonld_dates( '<script type="application/ld+json">{not json</script>' ),
	'E2 malformed JSON-LD yields empty dates'
);
assert_equals( array(), Conexao_Source_Mondello_Park::map_categories( 'not-an-array' ), 'E3 a non-array category payload yields no categories' );
assert_equals( array(), Conexao_Source_Mondello_Park::map_categories( null ), 'E4 a null category payload yields no categories' );
assert_true( in_array( 'Car Racing', Conexao_Source_Mondello_Park::map_categories( array( 23 ) ), true ), 'E5 a known category id still maps' );

// ---------------------------------------------------------------------------
// F. The month map resolves every month after the duplicate-key fix
// ---------------------------------------------------------------------------

test_section( 'F. Month map resolves every month (duplicate key removed)' );

$months = array(
	'january'   => 1,
	'february'  => 2,
	'march'     => 3,
	'april'     => 4,
	'may'       => 5,
	'june'      => 6,
	'july'      => 7,
	'august'    => 8,
	'september' => 9,
	'october'   => 10,
	'november'  => 11,
	'december'  => 12,
	'jan'       => 1,
	'feb'       => 2,
	'mar'       => 3,
	'apr'       => 4,
	'jun'       => 6,
	'jul'       => 7,
	'aug'       => 8,
	'sep'       => 9,
	'oct'       => 10,
	'nov'       => 11,
	'dec'       => 12,
);

foreach ( $months as $name => $number ) {
	assert_equals( $number, Conexao_Test_Source_Dom_Probe::probe_month( $name ), 'F month "' . $name . '" resolves to ' . (int) $number );
}

assert_true( false === Conexao_Test_Source_Dom_Probe::probe_month( 'not-a-month' ), 'F an unknown month resolves to false' );

test_finish( 'event-source-dom-hardening' );

test_title( 'Event-source DOM hardening' );
