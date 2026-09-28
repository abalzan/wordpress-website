<?php
/**
 * Stage 4.1 — bilingual REST contract tests.
 *
 * Exercises the whole contract IN-PROCESS through the real WordPress REST
 * server (rest_get_server()->dispatch()), with the process booted in
 * Polylang's REST context (REQUEST_URI under /wp-json/), so Polylang's own
 * REST handler (`PLL_REST_Request::set_language` on rest_pre_dispatch) runs
 * exactly as it does over HTTP:
 *
 *  - Request model: no `lang` keeps the legacy unfiltered behaviour;
 *    `lang=pt` returns the Portuguese collection; `lang=en` returns the
 *    English collection per the B1/B2 policy; any other value is HTTP 400
 *    `conexao_rest_invalid_lang` (never a silent mixed/default fallback).
 *  - Translation metadata: every record carries `conexao_language`
 *    { lang, is_fallback, translations } distinguishing real PT / real EN /
 *    B2 fallback / source-inherited EN.
 *  - Detail endpoints: PT detail, EN real translation, EN B2 fallback,
 *    source-inherited EN, wrong-language requests (404 + recovery pointer),
 *    nonexistent records, hidden events.
 *  - Event hard gates: identity meta unchanged, `_event_status` authoritative
 *    on collections AND details, no duplicate identity per language, no
 *    mutation from GET requests, EN translation is not an export row.
 *  - Lazer hard gates: `_leisure_export_uuid` shared, EN translation resolves,
 *    PT master replaced (not duplicated), external classification preserved.
 *  - Taxonomy contract: translated taxonomies filter per language; shared
 *    county/town terms are language-less and identical in both languages.
 *  - Search: /wp/v2/search membership per language + `search` param on
 *    collections; no language inference from text.
 *  - Cache separation: PT/EN warm-up cannot poison each other; creating or
 *    trashing an EN translation immediately reflects in the EN collection
 *    (B2 replacement set invalidated by the existing save/delete hooks).
 *
 * Fixtures created here are prefixed [S41] and deleted at the end. The pilot
 * dataset (scripts/stage32-*.php) is required.
 *
 * Usage (from the project root):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-stage41-rest-language.php
 *
 * @package conexao-br-irlanda
 */

// Boot WordPress in Polylang's REST context: pll_is_rest_request() inspects
// Boot WordPress in Polylang's REST context: pll_is_rest_request() inspects
// REQUEST_URI (it must contain a wp-json sub-path — a bare '/wp-json/' does
// not match), and the REST context is what registers Polylang's
// rest_pre_dispatch language handler — the exact production wiring.

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed  = 0;
$failed  = 0;
$created = array();

/**
 * Dispatch a GET request through the real REST server.
 *
 * The Polylang current language is set the way a fresh HTTP process starts:
 * Polylang's own `rest_pre_dispatch` handler assigns `PLL()->curlang` from the
 * `lang` parameter when it is present, and otherwise leaves the default
 * language. This helper therefore mirrors that EXACTLY:
 *
 *   - `lang=pt` / `lang=en`  -> curlang is that language object;
 *   - no `lang`              -> curlang is the DEFAULT language object.
 *
 * The previous unconditional reset to the default language was correct for
 * post collections (the theme applies the language clause itself in
 * `rest_{$post_type}_query`) but WRONG for taxonomy collections: Polylang
 * filters REST term queries from `PLL()->curlang`, so forcing the default made
 * an `lang=en` taxonomy request return the Portuguese terms. The production
 * endpoint was correct all along — verified over the wire, `lang=en` returns 20
 * EN terms including `documents` — so this restores parity between the
 * in-process dispatch and the real HTTP contract rather than changing any
 * product behaviour.
 *
 * The reset assigns a language OBJECT, not null. Setting
 * `PLL()->curlang = null` makes Polylang's `locale` filter return null, and
 * WordPress 7.1+ then fatals in WP_Translation_Controller::set_locale()
 * ("Argument #1 ($locale) must be of type string, null given").
 *
 * @param string $route  Route, e.g. /wp/v2/event.
 * @param array  $params Query params.
 * @return WP_REST_Response
 */
function s41_rest_get( string $route, array $params = array() ) {
	$locale_filter = null;

	if ( function_exists( 'PLL' ) && PLL() ) {
		$default  = PLL()->model->get_default_language();
		$fallback = is_object( $default ) ? $default->slug : (string) $default;
		$has_lang = isset( $params['lang'] ) && '' !== (string) $params['lang'];
		// A multi-language value (e.g. "en,pt") is not a term-language request.
		$requested = ( $has_lang && false === strpos( (string) $params['lang'], ',' ) ) ? (string) $params['lang'] : $fallback;

		$langs  = PLL()->model->languages->get_list();
		$object = null;
		foreach ( (array) $langs as $lang ) {
			if ( isset( $lang->slug ) && $requested === $lang->slug ) {
				$object = $lang;
				break;
			}
		}

		if ( $has_lang ) {
			// lang=pt / lang=en: Polylang's rest_pre_dispatch defines the language
			// for the request. This drives BOTH the post collections and the
			// taxonomy collections (an `lang=en` taxonomy request must return the
			// EN terms, which is what production does — verified over the wire).
			PLL()->curlang = $object;
		} else {
			// No `lang`: production leaves NO language defined for the request, so
			// the collections stay UNFILTERED (over the wire: no-`lang`
			// /conexao_category returns all 80 terms, and the event collection
			// returns both the PT master and its EN records).
			//
			// `PLL()->curlang = null` is the faithful representation of that state
			// and is what Polylang itself does — but on WordPress 7.1+ it makes
			// Polylang's `locale` filter return null and WordPress then fatals in
			// WP_Translation_Controller::set_locale(). Supplying a concrete locale
			// for the duration of the dispatch keeps l10n valid while leaving the
			// language UNDEFINED, so no language filter is applied. The filter is
			// removed immediately afterwards and never leaks into another request.
			$locale_filter = static function () {
				return 'en_US';
			};
			add_filter( 'locale', $locale_filter, PHP_INT_MAX );
			PLL()->curlang = null;
			if ( isset( PLL()->terms ) && is_object( PLL()->terms ) && method_exists( PLL()->terms, 'unset_tax_query_lang' ) ) {
				PLL()->terms->unset_tax_query_lang();
			}
		}
	}

	$request = new WP_REST_Request( 'GET', $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$response = rest_get_server()->dispatch( $request );

	if ( $locale_filter ) {
		remove_filter( 'locale', $locale_filter, PHP_INT_MAX );
	}

	return $response;
}

/**
 * Response as array + status, with a stable shape for assertions.
 *
 * @return array{status:int,data:mixed}
 */
function s41_rest_array( string $route, array $params = array() ): array {
	$response = s41_rest_get( $route, $params );

	return array(
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	);
}

/** Ids of a collection response (sorted). */
function s41_ids( $data ): array {
	$ids = array();
	foreach ( (array) $data as $row ) {
		if ( is_array( $row ) && isset( $row['id'] ) ) {
			$ids[] = (int) $row['id'];
		}
	}
	sort( $ids );

	return $ids;
}

/** conexao_language of one row. */
function s41_lang_of( $row ) {
	return is_array( $row ) && isset( $row['conexao_language'] ) && is_array( $row['conexao_language'] ) ? $row['conexao_language'] : null;
}

/** Find one row by id in a collection payload. */
function s41_row( $data, int $id ) {
	foreach ( (array) $data as $row ) {
		if ( is_array( $row ) && (int) ( $row['id'] ?? 0 ) === $id ) {
			return $row;
		}
	}

	return null;
}


/**
 * Membership of specific records in a REST collection, in ONE request.
 *
 * Why this exists: the contract under test is COLLECTION MEMBERSHIP ("does the
 * EN collection contain the real EN translation, and replace the PT master?").
 * That question is only meaningful for a KNOWN, SMALL set of records — the
 * test's own fixtures. Querying a whole collection with a fixed per_page and
 * hoping the fixtures land on page 1 makes the suite pass only against a
 * near-empty database: the local dataset holds 2,239 events and 236 leisure
 * records, so the pilot fixtures are pushed far past the first 100 rows.
 *
 * `include` (post__in) is the REST-native, documented way to address a
 * specific set of records, and it composes with `lang` — the EN replacement
 * rule is still evaluated by the server, so "the PT master is absent from the
 * EN collection" remains a real assertion about the B2 contract. The response
 * is still produced by the real REST controller over the real filters, so no
 * assertion about the API contract is weakened.
 *
 * @param string $route  Route, e.g. /wp/v2/event.
 * @param array  $ids    Record ids to look for.
 * @param array  $params Extra query params (e.g. lang).
 * @return array{status:int,data:mixed,ids:int[]} The raw response plus the ids returned.
 */
function s41_rest_containing( string $route, array $ids, array $params = array() ): array {
	$ids     = array_values( array_unique( array_map( 'intval', $ids ) ) );
	$params['include'] = implode( ',', $ids );
	// per_page must cover the requested set, otherwise the server paginates the
	// answer away. This is an upper bound on the REQUESTED set size, not a
	// widening of the production page size.
	$params['per_page'] = max( 1, count( $ids ) );

	$response = s41_rest_array( $route, $params );

	return array(
		'status' => $response['status'],
		'data'   => $response['data'],
		'ids'    => s41_ids( $response['data'] ),
	);
}

test_prerequisite_hint( 'polylang' );
test_require( function_exists( 'pll_get_post_language' ), 'polylang', 'test prerequisite is available: function_exists( pll_get_post_language )', 'activate the Polylang plugin' );
if ( ! function_exists( 'conexao_rest_language_post_types' ) ) {
	exit( 1 );
}

// ---------------------------------------------------------------------------
// Fixture resolution (pilot dataset). Direct $wpdb lookup on purpose: this
// process is a public (non-CLI, non-admin) context, so the event runtime's
// `_event_status` gate legitimately hides expired/rejected/source_not_found
// events from get_posts() — the test still needs their IDs to assert that
// hiding.
// ---------------------------------------------------------------------------
$fixture = static function ( string $post_type, string $slug ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status <> 'trash' ORDER BY ID ASC LIMIT 1",
			$post_type,
			$slug
		)
	);
};

$ev_pt_master      = $fixture( 'event', 'festa-junina-dublin-2026' );    // PT, has EN translation
$ev_en_translation = $fixture( 'event', 'festa-junina-dublin-2026-en' ); // real EN translation
$ev_en_source      = $fixture( 'event', 'irish-dance-workshop-dublin' ); // source-inherited EN
$ev_pt_b2          = $fixture( 'event', 'feijoada-beneficente-cork' );   // PT, no EN translation
$ev_expired        = $fixture( 'event', 'noite-de-mpb-bray' );           // hidden: expired
$ev_rejected       = $fixture( 'event', 'evento-rejeitado-spam' );       // hidden: rejected
$ev_removed        = $fixture( 'event', 'evento-removido-na-fonte' );    // hidden: source_not_found
$leisure_pt_master = $fixture( 'leisure', 'phoenix-park' );
$leisure_en        = $fixture( 'leisure', 'phoenix-park-en' );
$leisure_b2        = $fixture( 'leisure', 'cliffs-of-moher' );           // PT internal, no EN translation
$leisure_external  = $fixture( 'leisure', 'fota-wildlife-park' );        // PT external, no EN translation
$guide_pt_master   = $fixture( 'guide', 'como-tirar-o-pps-number' );
$guide_en          = $fixture( 'guide', 'how-to-get-a-pps-number' );
$post_pt_master    = $fixture( 'post', 'comunidade-celebra-festa-junina-em-dublin' );
$post_en           = $fixture( 'post', 'community-celebrates-festa-junina-in-dublin' );
$job_pt_master     = $fixture( 'job', 'ajudante-de-cozinha-dublin' );
$job_en            = $fixture( 'job', 'kitchen-assistant-dublin' );
$sponsor_pt_master = $fixture( 'sponsor', 'brasil-market-dublin' );
$sponsor_en        = $fixture( 'sponsor', 'brasil-market-dublin-en' );
$course_pt_master  = $fixture( 'course_provider', 'fetch-courses' );

$fixture_ok = true;
foreach ( array(
	'event PT master'      => $ev_pt_master,
	'event EN translation' => $ev_en_translation,
	'event EN source'      => $ev_en_source,
	'event PT b2'          => $ev_pt_b2,
	'event expired'        => $ev_expired,
	'event rejected'       => $ev_rejected,
	'event removed'        => $ev_removed,
	'leisure PT master'    => $leisure_pt_master,
	'leisure EN'           => $leisure_en,
	'leisure b2'           => $leisure_b2,
	'leisure external'     => $leisure_external,
	'guide PT master'      => $guide_pt_master,
	'guide EN'             => $guide_en,
	'post PT master'       => $post_pt_master,
	'post EN'              => $post_en,
	'job PT master'        => $job_pt_master,
	'job EN'               => $job_en,
	'sponsor PT master'    => $sponsor_pt_master,
	'sponsor EN'           => $sponsor_en,
	'course PT master'     => $course_pt_master,
) as $label => $id ) {
	if ( $id <= 0 ) {
		$fixture_ok = false;
	}
	assert_true( $id > 0, "fixture present: {$label} (#{$id})" );
}

if ( ! $fixture_ok ) {
	exit( 1 );
}

// ---------------------------------------------------------------------------
// 1. Request model: default behaviour, lang=pt, lang=en, invalid values.
// ---------------------------------------------------------------------------

// Collection MEMBERSHIP is asserted against the test's own fixture set, via
// the REST-native `include` parameter (see s41_rest_containing()). The whole
// collection is never fetched: against the real dataset (2,239 events) a fixed
// per_page=100 page would not contain the pilot fixtures, so the assertions
// would silently only hold on a near-empty database. The server still applies
// the real language/replacement/status logic to the requested records.
$event_fixtures = array( $ev_pt_master, $ev_en_translation, $ev_en_source, $ev_pt_b2, $ev_expired, $ev_rejected, $ev_removed );

$default_events = s41_rest_containing( '/wp/v2/event', $event_fixtures );
$pt_events      = s41_rest_containing( '/wp/v2/event', $event_fixtures, array( 'lang' => 'pt' ) );
$en_events      = s41_rest_containing( '/wp/v2/event', $event_fixtures, array( 'lang' => 'en' ) );

assert_true( 200 === $default_events['status'], 'default event collection is 200' );
assert_true( in_array( $ev_pt_master, $default_events['ids'], true ), 'default collection contains the PT master' );
assert_true( in_array( $ev_en_translation, $default_events['ids'], true ), 'default collection contains the EN translation (legacy unfiltered behaviour)' );
assert_true( in_array( $ev_en_source, $default_events['ids'], true ), 'default collection contains the source-inherited EN event' );

$pt_ids = $pt_events['ids'];
$en_ids = $en_events['ids'];

assert_true( in_array( $ev_pt_master, $pt_ids, true ), 'lang=pt contains the PT master' );
assert_true( ! in_array( $ev_en_translation, $pt_ids, true ), 'lang=pt excludes the EN translation' );
assert_true( ! in_array( $ev_en_source, $pt_ids, true ), 'lang=pt excludes the source-inherited EN event' );

assert_true( in_array( $ev_en_translation, $en_ids, true ), 'lang=en contains the real EN translation' );
assert_true( in_array( $ev_en_source, $en_ids, true ), 'lang=en contains the source-inherited EN event' );
assert_true( in_array( $ev_pt_b2, $en_ids, true ), 'lang=en contains the untranslated PT event as B2 fallback' );
assert_true( ! in_array( $ev_pt_master, $en_ids, true ), 'lang=en replaces the PT master that has an EN translation (never duplicated)' );

// Hidden events absent from every public variant.
foreach ( array( 'default' => $default_events, 'pt' => $pt_events, 'en' => $en_events ) as $label => $resp ) {
	$ids = $resp['ids'];
	assert_true( ! in_array( $ev_expired, $ids, true ) && ! in_array( $ev_rejected, $ids, true ) && ! in_array( $ev_removed, $ids, true ), "hidden events (expired/rejected/source_not_found) absent from {$label} collection" );
}

// Invalid language: deterministic 400, never a silent fallback.
foreach ( array( '/wp/v2/event', '/wp/v2/leisure', '/wp/v2/guide', '/wp/v2/posts', '/wp/v2/job', '/wp/v2/course_provider', '/wp/v2/sponsor' ) as $route ) {
	$bad = s41_rest_array( $route, array( 'lang' => 'xx' ) );
	assert_true( 400 === $bad['status'] && is_array( $bad['data'] ) && 'conexao_rest_invalid_lang' === ( $bad['data']['code'] ?? '' ), "{$route}?lang=xx -> 400 conexao_rest_invalid_lang" );
}
$bad_detail = s41_rest_array( '/wp/v2/event/' . $ev_pt_master, array( 'lang' => 'xx' ) );
assert_true( 400 === $bad_detail['status'] && 'conexao_rest_invalid_lang' === ( $bad_detail['data']['code'] ?? '' ), 'detail ?lang=xx -> 400' );
$bad_search = s41_rest_array( '/wp/v2/search', array( 'search' => 'festa', 'lang' => 'de' ) );
assert_true( 400 === $bad_search['status'] && 'conexao_rest_invalid_lang' === ( $bad_search['data']['code'] ?? '' ), 'search ?lang=de -> 400' );
$bad_term = s41_rest_array( '/wp/v2/conexao_county', array( 'lang' => 'fr' ) );
assert_true( 400 === $bad_term['status'] && 'conexao_rest_invalid_lang' === ( $bad_term['data']['code'] ?? '' ), 'term collection ?lang=fr -> 400' );

// ---------------------------------------------------------------------------
// 2. Translation metadata (conexao_language) on collections.
// ---------------------------------------------------------------------------

$pt_master_row = s41_row( $pt_events['data'], $ev_pt_master );
$meta          = s41_lang_of( (array) $pt_master_row );
assert_true( null !== $meta, 'PT master carries conexao_language' );
assert_true( 'pt' === ( $meta['lang'] ?? '' ), 'PT master lang=pt' );
assert_true( false === ( $meta['is_fallback'] ?? true ), 'PT master is_fallback=false under lang=pt' );
assert_true( isset( $meta['translations']['en']['id'] ) && $ev_en_translation === (int) $meta['translations']['en']['id'], 'PT master translations.en points at the EN translation' );
assert_true( isset( $meta['translations']['en']['url'] ) && false !== strpos( (string) $meta['translations']['en']['url'], '/en/eventos/festa-junina-dublin-2026-en/' ), 'PT master translations.en carries the EN permalink' );

$en_row = s41_row( $en_events['data'], $ev_en_translation );
$meta   = s41_lang_of( (array) $en_row );
assert_true( 'en' === ( $meta['lang'] ?? '' ), 'EN translation lang=en' );
assert_true( false === ( $meta['is_fallback'] ?? true ), 'EN translation is_fallback=false under lang=en' );
assert_true( isset( $meta['translations']['pt']['id'] ) && $ev_pt_master === (int) $meta['translations']['pt']['id'], 'EN translation translations.pt points at the PT master' );

$b2_row = s41_row( $en_events['data'], $ev_pt_b2 );
$meta   = s41_lang_of( (array) $b2_row );
assert_true( 'pt' === ( $meta['lang'] ?? '' ), 'B2 fallback record keeps lang=pt (the record language)' );
assert_true( true === ( $meta['is_fallback'] ?? false ), 'B2 fallback is_fallback=true under lang=en' );
assert_true( empty( $meta['translations'] ), 'B2 fallback has no translations' );
assert_true( false !== strpos( (string) ( is_array( $b2_row ) ? ( $b2_row['link'] ?? '' ) : '' ), '/eventos/feijoada-beneficente-cork/' ), 'B2 fallback link stays the PT canonical (no /en/ shell URL)' );

$src_row = s41_row( $en_events['data'], $ev_en_source );
$meta    = s41_lang_of( (array) $src_row );
assert_true( 'en' === ( $meta['lang'] ?? '' ) && false === ( $meta['is_fallback'] ?? true ) && empty( $meta['translations'] ), 'source-inherited EN event: lang=en, no fallback, no translations' );
assert_true( false !== strpos( (string) ( is_array( $src_row ) ? ( $src_row['link'] ?? '' ) : '' ), '/en/eventos/irish-dance-workshop-dublin/' ), 'source-inherited EN link is the EN self-canonical' );

// No duplicate identity inside one EN collection (event export uuid).
$uuids = array();
foreach ( (array) $en_events['data'] as $row ) {
	$uuid = is_array( $row ) && isset( $row['meta'] ) && is_array( $row['meta'] ) ? (string) ( $row['meta']['_event_export_uuid'] ?? '' ) : '';
	if ( '' !== $uuid ) {
		$uuids[] = $uuid;
	}
}
assert_true( count( $uuids ) === count( array_unique( $uuids ) ), 'EN event collection has no duplicate _event_export_uuid (one row per identity)' );

// ---------------------------------------------------------------------------
// 3. Detail endpoints.
// ---------------------------------------------------------------------------

// PT record requested as PT.
$r = s41_rest_array( '/wp/v2/event/' . $ev_pt_master, array( 'lang' => 'pt' ) );
assert_true( 200 === $r['status'] && 'pt' === ( $r['data']['conexao_language']['lang'] ?? '' ) && false === $r['data']['conexao_language']['is_fallback'], 'PT event detail with lang=pt -> 200 pt record' );
assert_true( false !== strpos( (string) $r['data']['link'], '/eventos/festa-junina-dublin-2026/' ), 'PT detail link is the PT permalink' );

// EN real translation requested as EN.
$r = s41_rest_array( '/wp/v2/event/' . $ev_en_translation, array( 'lang' => 'en' ) );
assert_true( 200 === $r['status'] && 'en' === ( $r['data']['conexao_language']['lang'] ?? '' ) && false === $r['data']['conexao_language']['is_fallback'], 'EN translation detail with lang=en -> 200 en record' );
assert_true( false !== strpos( (string) $r['data']['link'], '/en/eventos/festa-junina-dublin-2026-en/' ), 'EN translation detail link is the EN canonical' );

// B2 fallback detail: PT event without EN translation, requested as EN.
$r = s41_rest_array( '/wp/v2/event/' . $ev_pt_b2, array( 'lang' => 'en' ) );
assert_true( 200 === $r['status'], 'B2 event detail with lang=en -> 200' );
assert_true( 'pt' === ( $r['data']['conexao_language']['lang'] ?? '' ) && true === $r['data']['conexao_language']['is_fallback'], 'B2 event detail: lang=pt, is_fallback=true' );
assert_true( false !== strpos( (string) $r['data']['link'], '/eventos/feijoada-beneficente-cork/' ), 'B2 detail link is the PT canonical' );

// Source-inherited EN detail.
$r = s41_rest_array( '/wp/v2/event/' . $ev_en_source, array( 'lang' => 'en' ) );
assert_true( 200 === $r['status'] && 'en' === ( $r['data']['conexao_language']['lang'] ?? '' ) && empty( $r['data']['conexao_language']['translations'] ), 'source-inherited EN event detail -> 200 en, no translations' );

// PT master that HAS an EN translation, requested as EN: 404 + recovery pointer.
$r = s41_rest_array( '/wp/v2/event/' . $ev_pt_master, array( 'lang' => 'en' ) );
assert_true( 404 === $r['status'] && 'conexao_rest_language_unavailable' === ( $r['data']['code'] ?? '' ), 'PT master with EN translation, lang=en -> 404 conexao_rest_language_unavailable' );
assert_true( $ev_en_translation === (int) ( $r['data']['data']['translations']['en']['id'] ?? 0 ), '404 carries the EN translation pointer (id)' );

// EN record requested as PT: 404 + pointer back to PT.
$r = s41_rest_array( '/wp/v2/event/' . $ev_en_translation, array( 'lang' => 'pt' ) );
assert_true( 404 === $r['status'] && 'conexao_rest_language_unavailable' === ( $r['data']['code'] ?? '' ), 'EN translation requested with lang=pt -> 404' );
assert_true( $ev_pt_master === (int) ( $r['data']['data']['translations']['pt']['id'] ?? 0 ), '404 carries the PT master pointer (id)' );

// Source-inherited EN record requested as PT: 404, no PT counterpart exists.
$r = s41_rest_array( '/wp/v2/event/' . $ev_en_source, array( 'lang' => 'pt' ) );
assert_true( 404 === $r['status'] && empty( $r['data']['data']['translations'] ), 'source-inherited EN event with lang=pt -> 404 without translations' );

// B1 content (guide) PT record WITHOUT an EN translation, requested as EN:
// no fallback — 404. This is the B1/B2 boundary, and it must be tested on a
// record the suite OWNS.
//
// The previous version searched the live PT guide collection for an
// untranslated guide. That became unsatisfiable once the B1 EN Guides rollout
// completed (every public PT guide now has a real EN translation — 98/98), so
// the assertion could no longer run at all. Rather than dropping the B1 check,
// a throwaway PT guide is created with NO EN translation, asserted, and deleted
// in the same run. The B1 rule is still proven; it no longer depends on a
// transient state of the site's content.
$guide_b1 = wp_insert_post(
	array(
		'post_type'    => 'guide',
		'post_title'   => '[S41] B1 probe guide (no EN translation)',
		'post_name'    => 's41-b1-probe-guide',
		'post_status'  => 'publish',
		'post_content' => '<p>B1 probe: a Portuguese guide with no English translation.</p>',
	)
);
if ( is_wp_error( $guide_b1 ) || ! $guide_b1 ) {
	assert_true( false, 'B1 probe guide could be created', is_wp_error( $guide_b1 ) ? $guide_b1->get_error_message() : 'insert failed' );
} else {
	$created[] = (int) $guide_b1;
	pll_set_post_language( (int) $guide_b1, 'pt' );

	assert_true(
		0 === (int) pll_get_post( (int) $guide_b1, 'en' ),
		'B1 probe guide has no EN translation (B1 precondition)'
	);
	assert_true(
		! conexao_is_b2_post_type( 'guide' ),
		'guide is a B1 post type: the B2 fallback must never cover it'
	);

	// Collection: a B1 PT guide appears in lang=pt only.
	$probe_pt = s41_rest_containing( '/wp/v2/guide', array( (int) $guide_b1 ), array( 'lang' => 'pt' ) );
	$probe_en = s41_rest_containing( '/wp/v2/guide', array( (int) $guide_b1 ), array( 'lang' => 'en' ) );
	assert_true( in_array( (int) $guide_b1, $probe_pt['ids'], true ), 'B1 guide appears in the PT guide collection' );
	assert_true( ! in_array( (int) $guide_b1, $probe_en['ids'], true ), 'B1 guide never appears in the EN guide collection (no B2 substitution)' );

	// Detail: requested as EN -> 404 with the documented recovery code.
	$r = s41_rest_array( '/wp/v2/guide/' . $guide_b1, array( 'lang' => 'en' ) );
	assert_true( 404 === $r['status'] && 'conexao_rest_language_unavailable' === ( $r['data']['code'] ?? '' ), 'B1 guide (no EN translation) with lang=en -> 404 (never a B2 render)' );

	// Detail in its own language is 200 — the record itself is healthy.
	$r_pt = s41_rest_array( '/wp/v2/guide/' . $guide_b1, array( 'lang' => 'pt' ) );
	assert_true( 200 === $r_pt['status'], 'B1 guide is served normally in its own language (pt)' );

	wp_delete_post( (int) $guide_b1, true );
	$created = array_values( array_diff( $created, array( (int) $guide_b1 ) ) );
}

// Nonexistent record.
$r = s41_rest_array( '/wp/v2/event/999999', array( 'lang' => 'en' ) );
assert_true( 404 === $r['status'] && 'rest_post_invalid_id' === ( $r['data']['code'] ?? '' ), 'nonexistent event -> 404 rest_post_invalid_id' );

// Hidden events: detail must 404 like the front-end single, in every variant.
foreach ( array( 'expired' => $ev_expired, 'rejected' => $ev_rejected, 'source_not_found' => $ev_removed ) as $label => $hidden_id ) {
	$r0 = s41_rest_array( '/wp/v2/event/' . $hidden_id );
	$r1 = s41_rest_array( '/wp/v2/event/' . $hidden_id, array( 'lang' => 'pt' ) );
	$r2 = s41_rest_array( '/wp/v2/event/' . $hidden_id, array( 'lang' => 'en' ) );
	assert_true( 404 === $r0['status'] && 404 === $r1['status'] && 404 === $r2['status'], "hidden event ({$label}) detail -> 404 without lang, with lang=pt and with lang=en" );
	assert_true( 'rest_post_invalid_id' === ( $r2['data']['code'] ?? '' ), "hidden event ({$label}) 404 is indistinguishable from a nonexistent record" );
}

// ---------------------------------------------------------------------------
// 4. Collection membership per post type (the B1/B2 matrix).
// ---------------------------------------------------------------------------

// B2 types: EN = EN records + untranslated PT records; PT masters with an EN
// translation are replaced. B1 types (guide, post): EN = EN records only.
$expect = array(
	// route => [ type, pt_must_have, pt_must_not, en_must_have, en_must_not ]
	'/wp/v2/event'           => array( 'b2', array( $ev_pt_master, $ev_pt_b2 ), array( $ev_en_translation, $ev_en_source ), array( $ev_en_translation, $ev_en_source, $ev_pt_b2 ), array( $ev_pt_master, $ev_expired, $ev_rejected, $ev_removed ) ),
	'/wp/v2/leisure'         => array( 'b2', array( $leisure_pt_master, $leisure_b2, $leisure_external ), array( $leisure_en ), array( $leisure_en, $leisure_b2, $leisure_external ), array( $leisure_pt_master ) ),
	'/wp/v2/guide'           => array( 'b1', array( $guide_pt_master ), array( $guide_en ), array( $guide_en ), array( $guide_pt_master ) ),
	'/wp/v2/posts'           => array( 'b1', array( $post_pt_master ), array( $post_en ), array( $post_en ), array( $post_pt_master ) ),
	'/wp/v2/job'             => array( 'b2', array( $job_pt_master ), array( $job_en ), array( $job_en, $job_pt_master ? 0 : 0 ), array( $job_pt_master ) ),
	// `course_provider` is a pure B2 type: the PT record carries English-only
	// fields, so there is NO EN record and the EN collection serves the PT
	// record itself (see docs/content-model.md and
	// test-en-course-provider-category-filter "zero EN course_provider records
	// exist"). The EN twin asserted by the earlier pilot fixture list was stale.
	'/wp/v2/course_provider' => array( 'b2', array( $course_pt_master ), array(), array( $course_pt_master ), array() ),
	'/wp/v2/sponsor'         => array( 'b2', array( $sponsor_pt_master ), array( $sponsor_en ), array( $sponsor_en ), array( $sponsor_pt_master ) ),
);

foreach ( $expect as $route => $rule ) {
	list( $kind, $pt_have, $pt_not, $en_have, $en_not ) = $rule;

	// Membership is requested for EXACTLY the records this rule talks about
	// (the fixture ids), never for a whole page of a live collection. See
	// s41_rest_containing(): a fixed per_page over the full collection would
	// not contain these fixtures on a populated database.
	$all_ids = array_values(
		array_unique(
			array_filter(
				array_merge( (array) $pt_have, (array) $pt_not, (array) $en_have, (array) $en_not )
			)
		)
	);

	$pt_resp = s41_rest_containing( $route, $all_ids, array( 'lang' => 'pt' ) );
	$en_resp = s41_rest_containing( $route, $all_ids, array( 'lang' => 'en' ) );
	$pt      = $pt_resp['ids'];
	$en      = $en_resp['ids'];
	$rows_en = $en_resp['data'];

	foreach ( array_filter( $pt_have ) as $id ) {
		assert_true( in_array( $id, $pt, true ), "{$route} lang=pt contains #{$id}" );
	}
	foreach ( array_filter( $pt_not ) as $id ) {
		assert_true( ! in_array( $id, $pt, true ), "{$route} lang=pt excludes #{$id}" );
	}
	foreach ( array_filter( $en_have ) as $id ) {
		assert_true( in_array( $id, $en, true ), "{$route} lang=en contains #{$id}" );
	}
	foreach ( array_filter( $en_not ) as $id ) {
		assert_true( ! in_array( $id, $en, true ), "{$route} lang=en excludes #{$id}" );
	}

	// No id may appear twice in one language collection.
	assert_true( count( $pt ) === count( array_unique( $pt ) ), "{$route} lang=pt has no duplicate ids" );
	assert_true( count( $en ) === count( array_unique( $en ) ), "{$route} lang=en has no duplicate ids" );

	// B2 EN collections: every PT row is an explicit fallback, every EN row is not.
	// Asserted over the same fixture-scoped EN payload already fetched above.
	if ( 'b2' === $kind ) {
		$ok   = true;
		foreach ( (array) $rows_en as $row ) {
			$m = s41_lang_of( $row );
			if ( ! $m ) {
				$ok = false;
				break;
			}
			if ( 'pt' === $m['lang'] && true !== $m['is_fallback'] ) {
				$ok = false;
			}
			if ( 'en' === $m['lang'] && false !== $m['is_fallback'] ) {
				$ok = false;
			}
		}
		assert_true( $ok, "{$route} lang=en fallback flags are consistent (pt row => is_fallback, en row => not)" );
	}
}

// `include` (post__in) must compose with the EN replacement rule.
$r = s41_rest_array( '/wp/v2/event', array( 'include' => $ev_pt_master . ',' . $ev_pt_b2, 'lang' => 'en' ) );
assert_true( 200 === $r['status'] && array( $ev_pt_b2 ) === s41_ids( $r['data'] ), 'include + lang=en drops the replaced PT master, keeps the B2 record' );

// Pagination composes with the language filter. The pages are requested over
// a FIXED, KNOWN slice of the collection (the fixtures) so the two pages are
// guaranteed to be disjoint and the assertion does not depend on how many
// events the local database happens to hold. The production page size is
// untouched: per_page stays 2 and the request is a real REST request.
$page_ids = array_values( array_unique( array( $ev_en_translation, $ev_en_source, $ev_pt_b2 ) ) );
$p1 = s41_rest_array( '/wp/v2/event', array( 'include' => implode( ',', $page_ids ), 'per_page' => 2, 'page' => 1, 'lang' => 'en' ) );
$p2 = s41_rest_array( '/wp/v2/event', array( 'include' => implode( ',', $page_ids ), 'per_page' => 2, 'page' => 2, 'lang' => 'en' ) );
$paged = array_merge( s41_ids( $p1['data'] ), s41_ids( $p2['data'] ) );
assert_true( 2 === count( s41_ids( $p1['data'] ) ), 'EN events page 1 has per_page=2 items' );
assert_true( count( $paged ) === count( array_unique( $paged ) ), 'EN events pages 1+2 have no overlap' );
assert_true( 3 === count( $paged ), 'both EN pages together return the three requested EN-side records exactly once', 'paged=' . implode( ',', $paged ) );
assert_true( ! in_array( $ev_pt_master, $paged, true ), 'EN events pagination never surfaces the replaced PT master' );

// ---------------------------------------------------------------------------
// 5. Event hard gates.
// ---------------------------------------------------------------------------

// Identity meta is exposed unchanged and identical between master/translation.
$master_meta = s41_rest_array( '/wp/v2/event/' . $ev_pt_master )['data']['meta'];
$en_meta     = s41_rest_array( '/wp/v2/event/' . $ev_en_translation )['data']['meta'];
assert_true( '' !== (string) ( $master_meta['_event_source'] ?? '' ) && ( $master_meta['_event_source'] ?? null ) === ( $en_meta['_event_source'] ?? null ), '_event_source identical on PT master and EN translation' );
assert_true( '' !== (string) ( $master_meta['_event_source_id'] ?? '' ) && ( $master_meta['_event_source_id'] ?? null ) === ( $en_meta['_event_source_id'] ?? null ), '_event_source_id identical on PT master and EN translation' );
assert_true( '' !== (string) ( $master_meta['_event_export_uuid'] ?? '' ) && ( $master_meta['_event_export_uuid'] ?? null ) === ( $en_meta['_event_export_uuid'] ?? null ), '_event_export_uuid shared by PT master and EN translation' );

// The source-inherited EN event keeps its own identity.
$src_meta = s41_rest_array( '/wp/v2/event/' . $ev_en_source )['data']['meta'];
assert_true( '' !== (string) ( $src_meta['_event_source'] ?? '' ) && '' !== (string) ( $src_meta['_event_source_id'] ?? '' ), 'source-inherited EN event keeps its own _event_source/_event_source_id' );
assert_true( ( $src_meta['_event_export_uuid'] ?? '' ) !== ( $master_meta['_event_export_uuid'] ?? '' ), 'source-inherited EN event has a distinct export uuid (separate identity)' );

// GET requests never mutate event records: snapshot identity meta before/after
// a batch of reads.
$before = array(
	'source'    => get_post_meta( $ev_pt_master, '_event_source', true ),
	'source_id' => get_post_meta( $ev_pt_master, '_event_source_id', true ),
	'uuid'      => get_post_meta( $ev_pt_master, '_event_export_uuid', true ),
	'status'    => get_post_meta( $ev_pt_master, '_event_status', true ),
	'modified'  => get_post_field( 'post_modified', $ev_pt_master ),
);
s41_rest_get( '/wp/v2/event', array( 'per_page' => 100 ) );
s41_rest_get( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'pt' ) );
s41_rest_get( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'en' ) );
s41_rest_get( '/wp/v2/event/' . $ev_pt_master );
s41_rest_get( '/wp/v2/event/' . $ev_en_translation, array( 'lang' => 'en' ) );
$after = array(
	'source'    => get_post_meta( $ev_pt_master, '_event_source', true ),
	'source_id' => get_post_meta( $ev_pt_master, '_event_source_id', true ),
	'uuid'      => get_post_meta( $ev_pt_master, '_event_export_uuid', true ),
	'status'    => get_post_meta( $ev_pt_master, '_event_status', true ),
	'modified'  => get_post_field( 'post_modified', $ev_pt_master ),
);
assert_true( $before === $after, 'GET collection/detail requests never mutate event identity, status or modified date' );

// The event's exported identity is shared by exactly its PT master and its
// linked EN translation — the EN record is never an ADDITIONAL export
// identity row.
//
// Scoped to the fixtures this suite owns. A whole-database count would assert
// that every historical event row in the local DB is pristine, which is not a
// REST contract and is violated by long-standing local data corruption
// (duplicated _event_export_uuid postmeta rows on unrelated posts) that this
// test neither owns nor may mutate. The invariant is still proven strictly:
// the master and the translation share one uuid, and no OTHER event may claim
// that same uuid.
$master_uuid = (string) get_post_meta( $ev_pt_master, '_event_export_uuid', true );
assert_true( '' !== $master_uuid, 'the pilot PT event carries an export uuid' );
assert_true(
	(string) get_post_meta( $ev_en_translation, '_event_export_uuid', true ) === $master_uuid,
	'the EN translation shares the master export uuid (one identity, two languages)'
);

global $wpdb;
$uuid_claimants = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
		WHERE meta_key = '_event_export_uuid' AND meta_value = %s
		ORDER BY post_id",
		$master_uuid
	)
);
$uuid_claimants = array_map( 'intval', (array) $uuid_claimants );
$expected_pair  = array( (int) $ev_pt_master, (int) $ev_en_translation );
sort( $expected_pair );
assert_true(
	$uuid_claimants === $expected_pair,
	'the export uuid is claimed by exactly the master and its EN translation, never by an extra identity row',
	'claimants=' . implode( ',', $uuid_claimants ) . ' expected=' . implode( ',', $expected_pair )
);

// ---------------------------------------------------------------------------
// 6. Lazer hard gates.
// ---------------------------------------------------------------------------

// _leisure_export_uuid is migration identity data (not exposed in the REST
// payload — unchanged since Stage 3.3), so the identity checks are made at
// the data level: the EN translation shares the master's UUID and the
// migration classifier still resolves the same destinations.
$leisure_master_uuid = (string) get_post_meta( $leisure_pt_master, '_leisure_export_uuid', true );
$leisure_en_uuid     = (string) get_post_meta( $leisure_en, '_leisure_export_uuid', true );
assert_true( '' !== $leisure_master_uuid, 'leisure PT master keeps a non-empty _leisure_export_uuid' );
assert_true( $leisure_master_uuid === $leisure_en_uuid, '_leisure_export_uuid identical on PT master and EN translation' );
assert_true( ! array_key_exists( '_leisure_export_uuid', (array) s41_rest_array( '/wp/v2/leisure/' . $leisure_pt_master )['data']['meta'] ), 'REST leisure payload does not newly expose _leisure_export_uuid (identity meta stays internal, unchanged)' );

// Membership scoped to the four Lazer fixtures (see s41_rest_containing()).
$leisure_fixture_ids = array( $leisure_pt_master, $leisure_en, $leisure_b2, $leisure_external );
$en_leisure           = s41_rest_containing( '/wp/v2/leisure', $leisure_fixture_ids, array( 'lang' => 'en' ) );
$en_leisure_ids       = $en_leisure['ids'];
assert_true( in_array( $leisure_en, $en_leisure_ids, true ), 'EN leisure collection resolves the real EN translation' );
assert_true( ! in_array( $leisure_pt_master, $en_leisure_ids, true ), 'EN leisure collection replaces the PT master (not duplicated)' );
assert_true( in_array( $leisure_b2, $en_leisure_ids, true ), 'EN leisure collection keeps the untranslated internal PT record as B2' );
assert_true( in_array( $leisure_external, $en_leisure_ids, true ), 'EN leisure collection keeps the externally classified PT record' );

// External classification is DATA-PRESERVED and the classifier itself is
// untouched by the REST layer (the redirect stays a front-end concern).
//
// The contract implemented by conexao_leisure_external_url() (inc/seo/redirects.php)
// is: a leisure record is EXTERNAL when it declares a valid absolute
// `_leisure_official_website` / `_leisure_discover_ireland` destination, and
// INTERNAL when it declares none, or when it is explicitly flagged
// `_leisure_internal_page` (Phase 3B "keep internal page").
//
// The previous assertions hard-coded which real records fall in each class
// ("cliffs-of-moher stays internal"), which is a mutable property of a content
// record rather than a contract, and it broke as soon as that record gained a
// website. The classifier is therefore exercised on throwaway records the suite
// owns — one external, one internal, one explicitly flagged internal — while
// the real fixtures are checked for data preservation.
assert_true( function_exists( 'conexao_leisure_external_url' ), 'the leisure external-URL classifier exists' );

foreach ( array( $leisure_external, $leisure_b2 ) as $real_id ) {
	$declared = (string) get_post_meta( $real_id, '_leisure_official_website', true );
	if ( '' === $declared ) {
		$declared = (string) get_post_meta( $real_id, '_leisure_discover_ireland', true );
	}
	$flagged_internal = (bool) get_post_meta( $real_id, '_leisure_internal_page', true );
	$expected = ( $flagged_internal || '' === $declared ) ? '' : $declared;
	assert_true(
		(string) conexao_leisure_external_url( $real_id ) === $expected,
		'leisure classification is data-preserved: the classifier returns exactly the destination declared on the record',
		"id={$real_id}"
	);
}

$s41_ext = wp_insert_post(
	array(
		'post_type'    => 'leisure',
		'post_title'   => '[S41] external leisure probe',
		'post_name'    => 's41-external-probe',
		'post_status'  => 'publish',
		'post_content' => '<p>External probe.</p>',
	)
);
$s41_int = wp_insert_post(
	array(
		'post_type'    => 'leisure',
		'post_title'   => '[S41] internal leisure probe',
		'post_name'    => 's41-internal-probe',
		'post_status'  => 'publish',
		'post_content' => '<p>Internal probe.</p>',
	)
);
$s41_flagged = wp_insert_post(
	array(
		'post_type'    => 'leisure',
		'post_title'   => '[S41] flagged-internal leisure probe',
		'post_name'    => 's41-flagged-internal-probe',
		'post_status'  => 'publish',
		'post_content' => '<p>Flagged internal probe.</p>',
	)
);
if ( ! is_wp_error( $s41_ext ) && $s41_ext && ! is_wp_error( $s41_int ) && $s41_int && ! is_wp_error( $s41_flagged ) && $s41_flagged ) {
	$created[] = (int) $s41_ext;
	$created[] = (int) $s41_int;
	$created[] = (int) $s41_flagged;
	foreach ( array( $s41_ext, $s41_int, $s41_flagged ) as $probe ) {
		pll_set_post_language( (int) $probe, 'pt' );
	}
	update_post_meta( (int) $s41_ext, '_leisure_official_website', 'https://example.org/s41-probe' );
	update_post_meta( (int) $s41_flagged, '_leisure_official_website', 'https://example.org/s41-flagged' );
	update_post_meta( (int) $s41_flagged, '_leisure_internal_page', '1' );

	assert_true( 'https://example.org/s41-probe' === conexao_leisure_external_url( (int) $s41_ext ), 'a leisure record declaring a website is classified external at that exact url' );
	assert_true( '' === conexao_leisure_external_url( (int) $s41_int ), 'a leisure record declaring no website is classified internal' );
	assert_true( '' === conexao_leisure_external_url( (int) $s41_flagged ), 'the _leisure_internal_page flag forces internal classification even with a website (Phase 3B)' );

	wp_delete_post( (int) $s41_ext, true );
	wp_delete_post( (int) $s41_int, true );
	wp_delete_post( (int) $s41_flagged, true );
	$created = array_values( array_diff( $created, array( (int) $s41_ext, (int) $s41_int, (int) $s41_flagged ) ) );
} else {
	assert_true( false, 'leisure classifier probe records could be created' );
}

$ext_row      = s41_row( $en_leisure['data'], $leisure_external );
$ext_meta_row = s41_lang_of( (array) $ext_row );
assert_true( null !== $ext_meta_row && 'pt' === $ext_meta_row['lang'] && true === $ext_meta_row['is_fallback'], 'external leisure record appears in EN as an explicit B2 fallback' );

// One leisure identity per language collection (data-level uuid per row id).
$leisure_uuids = array();
foreach ( $en_leisure_ids as $row_id ) {
	$uuid = (string) get_post_meta( $row_id, '_leisure_export_uuid', true );
	if ( '' !== $uuid ) {
		$leisure_uuids[] = $uuid;
	}
}
assert_true( count( $leisure_uuids ) === count( array_unique( $leisure_uuids ) ), 'EN leisure collection has no duplicate _leisure_export_uuid' );

// ---------------------------------------------------------------------------
// 7. Taxonomy / filter contract.
// ---------------------------------------------------------------------------

$cat_en  = s41_rest_array( '/wp/v2/conexao_category', array( 'per_page' => 100, 'lang' => 'en' ) );
$cat_pt  = s41_rest_array( '/wp/v2/conexao_category', array( 'per_page' => 100, 'lang' => 'pt' ) );
$cat_all = s41_rest_array( '/wp/v2/conexao_category', array( 'per_page' => 100 ) );

$cat_en_slugs = array_map( static function ( $t ) { return $t['slug'] ?? ''; }, (array) $cat_en['data'] );
$cat_pt_slugs = array_map( static function ( $t ) { return $t['slug'] ?? ''; }, (array) $cat_pt['data'] );

assert_true( in_array( 'documents', $cat_en_slugs, true ) && in_array( 'finances', $cat_en_slugs, true ) && in_array( 'festivals', $cat_en_slugs, true ) && in_array( 'nature', $cat_en_slugs, true ), 'EN category collection exposes the EN slugs (documents, finances, festivals, nature)' );
assert_true( in_array( 'documentos', $cat_pt_slugs, true ) && in_array( 'financas', $cat_pt_slugs, true ), 'PT category collection exposes the PT slugs (documentos, financas)' );
assert_true( ! in_array( 'documentos', $cat_en_slugs, true ) && ! in_array( 'documents', $cat_pt_slugs, true ), 'translated category collections never mix languages' );
assert_true( count( (array) $cat_all['data'] ) === count( (array) $cat_en['data'] ) + count( (array) $cat_pt['data'] ), 'default (no lang) category collection = PT + EN terms (unchanged legacy shape)' );

// Term metadata: EN term links its PT counterpart; shared terms are language-less.
$doc_en = null;
foreach ( (array) $cat_en['data'] as $t ) {
	if ( 'documents' === ( $t['slug'] ?? '' ) ) {
		$doc_en = $t;
	}
}
assert_true( null !== $doc_en && 'en' === ( $doc_en['conexao_language']['lang'] ?? '' ), 'EN term documents has lang=en' );
assert_true( isset( $doc_en['conexao_language']['translations']['pt']['id'] ), 'EN term documents links its PT translation' );

$county_en = s41_rest_array( '/wp/v2/conexao_county', array( 'per_page' => 100, 'lang' => 'en' ) );
$county_pt = s41_rest_array( '/wp/v2/conexao_county', array( 'per_page' => 100, 'lang' => 'pt' ) );
assert_true( s41_ids( $county_en['data'] ) === s41_ids( $county_pt['data'] ), 'county collection is identical in PT and EN (shared terms)' );
$dublin = null;
foreach ( (array) $county_en['data'] as $t ) {
	if ( 'dublin' === ( $t['slug'] ?? '' ) ) {
		$dublin = $t;
	}
}
assert_true(
	null !== $dublin
	&& is_array( $dublin['conexao_language'] ?? null )
	&& array_key_exists( 'lang', $dublin['conexao_language'] )
	&& null === $dublin['conexao_language']['lang']
	&& empty( $dublin['conexao_language']['translations'] ),
	'shared county dublin: lang=null, no translations (never duplicated/suffixed)'
);

$town_en = s41_rest_array( '/wp/v2/conexao_town', array( 'per_page' => 100, 'lang' => 'en' ) );
$town_pt = s41_rest_array( '/wp/v2/conexao_town', array( 'per_page' => 100, 'lang' => 'pt' ) );
assert_true( s41_ids( $town_en['data'] ) === s41_ids( $town_pt['data'] ), 'town collection is identical in PT and EN (shared terms)' );

// No suffixed/duplicated location terms anywhere.
$all_county_slugs = array_map( static function ( $t ) { return $t['slug'] ?? ''; }, (array) s41_rest_array( '/wp/v2/conexao_county', array( 'per_page' => 100 ) )['data'] );
$suffixed         = array_filter( $all_county_slugs, static function ( $slug ) { return (bool) preg_match( '/-(en|pt)$/', (string) $slug ); } );
assert_true( 0 === count( $suffixed ), 'no -en/-pt suffixed county terms exist' );

// Filtering posts by the language-appropriate term id.
$doc_en_id          = $doc_en ? (int) $doc_en['id'] : 0;
$guides_en_filtered = s41_rest_array( '/wp/v2/guide', array( 'per_page' => 100, 'lang' => 'en', 'conexao_category' => $doc_en_id ) );
assert_true( in_array( $guide_en, s41_ids( $guides_en_filtered['data'] ), true ), 'EN guides filter by EN term documents returns the EN guide' );
assert_true( ! in_array( $guide_pt_master, s41_ids( $guides_en_filtered['data'] ), true ), 'EN guides filter by EN term excludes the PT master (PT terms stay PT)' );

// Shared county filter works the same in both languages.
$dublin_id    = $dublin ? (int) $dublin['id'] : 0;
$ev_en_dublin = s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'en', 'conexao_county' => $dublin_id ) );
$ev_pt_dublin = s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'pt', 'conexao_county' => $dublin_id ) );
assert_true( in_array( $ev_pt_master, s41_ids( $ev_pt_dublin['data'] ), true ), 'PT events filtered by shared county dublin contain the PT Dublin event' );
assert_true( in_array( $ev_en_translation, s41_ids( $ev_en_dublin['data'] ), true ), 'EN events filtered by shared county dublin contain the EN Dublin event' );
assert_true( ! in_array( $ev_pt_master, s41_ids( $ev_en_dublin['data'] ), true ), 'EN events filtered by shared county still replace the translated PT master' );

// ---------------------------------------------------------------------------
// 8. Search contract.
// ---------------------------------------------------------------------------

$search_default = s41_rest_array( '/wp/v2/search', array( 'search' => 'festa', 'per_page' => 100 ) );
$search_en      = s41_rest_array( '/wp/v2/search', array( 'search' => 'festa', 'per_page' => 100, 'lang' => 'en' ) );
$search_pt      = s41_rest_array( '/wp/v2/search', array( 'search' => 'festa', 'per_page' => 100, 'lang' => 'pt' ) );

$search_default_ids = s41_ids( $search_default['data'] );
$search_en_ids      = s41_ids( $search_en['data'] );
$search_pt_ids      = s41_ids( $search_pt['data'] );

assert_true( in_array( $post_pt_master, $search_default_ids, true ) && in_array( $post_en, $search_default_ids, true ), 'default search keeps the legacy mixed membership' );
assert_true( in_array( $post_en, $search_en_ids, true ) && in_array( $ev_en_translation, $search_en_ids, true ), 'EN search returns EN records' );
assert_true( ! in_array( $post_pt_master, $search_en_ids, true ) && ! in_array( $ev_pt_master, $search_en_ids, true ), 'EN search excludes the translated PT masters (replaced, never duplicated)' );
assert_true( ! in_array( $ev_expired, $search_en_ids, true ) && ! in_array( $ev_rejected, $search_en_ids, true ) && ! in_array( $ev_removed, $search_en_ids, true ), 'EN search excludes hidden events' );
assert_true( in_array( $post_pt_master, $search_pt_ids, true ) && in_array( $ev_pt_master, $search_pt_ids, true ), 'PT search returns the PT masters' );
assert_true( ! in_array( $post_en, $search_pt_ids, true ) && ! in_array( $ev_en_translation, $search_pt_ids, true ), 'PT search excludes EN records' );

// Every search result carries conexao_language (no client-side text inference).
$all_flagged = true;
foreach ( (array) $search_en['data'] as $row ) {
	if ( ! is_array( $row ) || ! isset( $row['conexao_language']['lang'] ) ) {
		$all_flagged = false;
	}
}
assert_true( $all_flagged && count( (array) $search_en['data'] ) > 0, 'every search result carries conexao_language.lang' );

// B1-only content (untranslated PT guide) never appears in EN search.
$search_b1     = s41_rest_array( '/wp/v2/search', array( 'search' => 'saúde', 'per_page' => 100, 'lang' => 'en' ) );
$search_b1_ids = s41_ids( $search_b1['data'] );
assert_true( ! in_array( $guide_b1, $search_b1_ids, true ), 'B1-only PT guide never appears in EN search' );

// `search` param on a typed collection composes with the language rule.
$col_search_en = s41_rest_array( '/wp/v2/event', array( 'search' => 'festa', 'per_page' => 100, 'lang' => 'en' ) );
$col_search_pt = s41_rest_array( '/wp/v2/event', array( 'search' => 'festa', 'per_page' => 100, 'lang' => 'pt' ) );
assert_true( in_array( $ev_en_translation, s41_ids( $col_search_en['data'] ), true ) && ! in_array( $ev_pt_master, s41_ids( $col_search_en['data'] ), true ), 'event collection search lang=en: EN record in, PT master out' );
assert_true( in_array( $ev_pt_master, s41_ids( $col_search_pt['data'] ), true ) && ! in_array( $ev_en_translation, s41_ids( $col_search_pt['data'] ), true ), 'event collection search lang=pt: PT record in, EN record out' );

// ---------------------------------------------------------------------------
// 9. Cache separation.
// ---------------------------------------------------------------------------

// Warm EN first, then PT, then EN again: both languages must answer their own
// correct payload regardless of order (no shared response cache exists; the
// only reused cache is the language-independent B2 replacement set, which the
// theme invalidates on save/delete).
$en_first  = s41_ids( s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'en' ) )['data'] );
$pt_warm   = s41_ids( s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'pt' ) )['data'] );
$en_second = s41_ids( s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'en' ) )['data'] );
assert_true( $en_first === $en_second, 'warming PT between two EN reads does not change the EN payload' );
assert_true( in_array( $ev_pt_master, $pt_warm, true ) && ! in_array( $ev_pt_master, $en_second, true ), 'PT warm-up keeps the PT master; EN read still replaces it' );

// Translation lifecycle: create a real EN translation for the B2 event ->
// the EN collection immediately shows the EN record and drops the PT master;
// trash it -> the PT master returns as B2. This proves the relevant language
// variants are invalidated on translation create/delete.
$en_event_new = wp_insert_post(
	array(
		'post_type'    => 'event',
		'post_status'  => 'publish',
		'post_title'   => '[S41] Feijoada Beneficente Cork (EN)',
		'post_name'    => 's41-feijoada-beneficente-cork-en',
		'post_content' => 'Charity feijoada in Cork (EN fixture).',
	)
);
$created[] = $en_event_new;
pll_set_post_language( $en_event_new, 'en' );
pll_save_post_translations( array( 'pt' => $ev_pt_b2, 'en' => $en_event_new ) );
foreach ( array( '_event_source', '_event_source_id', '_event_export_uuid', '_event_status', '_event_date' ) as $copy_key ) {
	update_post_meta( $en_event_new, $copy_key, get_post_meta( $ev_pt_b2, $copy_key, true ) );
}

$en_after_create = s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'en' ) );
$en_create_ids   = s41_ids( $en_after_create['data'] );
assert_true( in_array( $en_event_new, $en_create_ids, true ), 'after creating the EN translation, the EN event collection contains it' );
assert_true( ! in_array( $ev_pt_b2, $en_create_ids, true ), 'after creating the EN translation, the PT master is replaced (no stale B2 fallback)' );

// The new EN record is a translation of the same identity (shared uuid), and
// the collection still has one row per identity.
$new_row  = s41_row( $en_after_create['data'], $en_event_new );
$new_meta = s41_lang_of( (array) $new_row );
assert_true( null !== $new_meta && 'en' === $new_meta['lang'] && false === $new_meta['is_fallback'] && isset( $new_meta['translations']['pt'] ), 'new EN translation reports lang=en, no fallback, PT link' );
$uuids_after = array();
foreach ( (array) $en_after_create['data'] as $row ) {
	$uuid = is_array( $row ) && isset( $row['meta'] ) && is_array( $row['meta'] ) ? (string) ( $row['meta']['_event_export_uuid'] ?? '' ) : '';
	if ( '' !== $uuid ) {
		$uuids_after[] = $uuid;
	}
}
assert_true( count( $uuids_after ) === count( array_unique( $uuids_after ) ), 'EN event collection still has one row per identity after the translation is created' );

wp_trash_post( $en_event_new );
$en_after_trash = s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'en' ) );
$en_trash_ids   = s41_ids( $en_after_trash['data'] );
assert_true( ! in_array( $en_event_new, $en_trash_ids, true ), 'after trashing the EN translation, it leaves the EN collection' );
assert_true( in_array( $ev_pt_b2, $en_trash_ids, true ), 'after trashing the EN translation, the PT master returns exactly once (B2)' );

// PT collection was never affected by the EN translation lifecycle.
$pt_after = s41_ids( s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'pt' ) )['data'] );
assert_true( $pt_warm === $pt_after, 'PT event collection is identical before/after the EN translation lifecycle' );

// ---------------------------------------------------------------------------
// 10. Backward compatibility: requests without `lang` behave exactly as before.
// ---------------------------------------------------------------------------

// No-lang collections return the full unfiltered (mixed) set: the union of
// the PT and EN public memberships.
//
// The three collections are compared over the SAME, KNOWN record set (the
// per-route fixture ids) rather than over three independent pages of the live
// collection. Comparing page-limited sets of a populated collection is not a
// statement about the contract at all: with 2,239 events the first 100 rows of
// the no-lang, lang=pt and lang=en collections are different rows, so
// array_diff() reports spurious "missing"/"extra" ids purely because of
// pagination. Scoping the comparison to a fixed id set asserts the real
// invariant — for the same records, no-lang is the union of PT and EN.
$backcompat_fixtures = array(
	'/wp/v2/event'           => array( $ev_pt_master, $ev_en_translation, $ev_en_source, $ev_pt_b2 ),
	'/wp/v2/leisure'         => array( $leisure_pt_master, $leisure_en, $leisure_b2, $leisure_external ),
	'/wp/v2/guide'           => array( $guide_pt_master, $guide_en ),
	'/wp/v2/posts'           => array( $post_pt_master, $post_en ),
	'/wp/v2/job'             => array( $job_pt_master, $job_en ),
	'/wp/v2/course_provider' => array( $course_pt_master ),
	'/wp/v2/sponsor'         => array( $sponsor_pt_master, $sponsor_en ),
);

foreach ( $backcompat_fixtures as $route => $ids ) {
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	if ( empty( $ids ) ) {
		continue;
	}

	$all = s41_rest_containing( $route, $ids );
	$pt  = s41_rest_containing( $route, $ids, array( 'lang' => 'pt' ) );
	$en  = s41_rest_containing( $route, $ids, array( 'lang' => 'en' ) );

	// Every PT-language record present in the PT collection must be in the
	// default collection, and every EN record in the EN collection must be in
	// the default collection (nothing is removed from the legacy surface).
	$missing = array_diff( array_merge( $pt['ids'], $en['ids'] ), $all['ids'] );
	assert_true( empty( $missing ), "{$route} without lang keeps every PT and EN record (nothing removed)", 'missing=' . implode( ',', $missing ) );

	// The legacy set is exactly the public records of both languages: the
	// default collection must not contain anything outside PT ∪ (EN incl. B2).
	$extra = array_diff( $all['ids'], array_merge( $pt['ids'], $en['ids'] ) );
	assert_true( empty( $extra ), "{$route} without lang adds no records beyond the PT + EN memberships", 'extra=' . implode( ',', $extra ) );
}

// No-lang detail for a PT and an EN record: 200 with accurate metadata (the
// legacy surface never 404s a language mismatch — that rule only applies when
// the caller explicitly requests a language).
$r = s41_rest_array( '/wp/v2/event/' . $ev_pt_master );
assert_true( 200 === $r['status'] && 'pt' === ( $r['data']['conexao_language']['lang'] ?? '' ), 'no-lang detail of a PT record -> 200' );
$r = s41_rest_array( '/wp/v2/event/' . $ev_en_translation );
assert_true( 200 === $r['status'] && 'en' === ( $r['data']['conexao_language']['lang'] ?? '' ), 'no-lang detail of an EN record -> 200 (legacy behaviour preserved)' );

// The only schema difference for legacy consumers is the additive field:
// every pre-existing top-level field is still present.
$row = s41_row( $default_events['data'], $ev_pt_master );
$core_fields = array( 'id', 'date', 'date_gmt', 'guid', 'modified', 'modified_gmt', 'slug', 'status', 'type', 'link', 'title', 'content', 'excerpt', 'author', 'featured_media', 'meta', 'conexao_category', 'conexao_county', 'conexao_tag', 'conexao_town', '_links' );
$missing_fields = array();
foreach ( $core_fields as $field ) {
	if ( ! array_key_exists( $field, (array) $row ) ) {
		$missing_fields[] = $field;
	}
}
assert_true( empty( $missing_fields ), 'legacy event payload keeps every pre-existing field (' . implode( ',', $missing_fields ) . ')' );

// ---------------------------------------------------------------------------
// Cleanup + summary.
// ---------------------------------------------------------------------------
foreach ( $created as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

// Polylang leaves a single-member translation group behind when the other
// member is deleted: dissolve it so event #74 returns to its pre-test state
// (no translation group), keeping the dataset byte-stable for other suites.
if ( function_exists( 'pll_set_post_language' ) ) {
	$leftover_groups = wp_get_object_terms( $ev_pt_b2, 'post_translations' );
	foreach ( (array) $leftover_groups as $group_term ) {
		if ( $group_term instanceof WP_Term ) {
			wp_delete_term( (int) $group_term->term_id, 'post_translations' );
		}
	}
}

test_finish();
