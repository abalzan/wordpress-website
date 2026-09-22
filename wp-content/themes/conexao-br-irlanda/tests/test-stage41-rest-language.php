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
$_SERVER['HTTP_HOST']      = 'localhost:8080';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']    = '/index.php';

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$passed  = 0;
$failed  = 0;
$created = array();

function s41_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

/**
 * Dispatch a GET request through the real REST server.
 *
 * Before dispatching, the Polylang current language is reset the way a fresh
 * HTTP process starts: Polylang's rest_pre_dispatch handler assigns it from
 * the `lang` parameter only when present, so in a long-lived process the
 * previous request's language would otherwise leak into a no-`lang` request.
 *
 * @param string $route  Route, e.g. /wp/v2/event.
 * @param array  $params Query params.
 * @return WP_REST_Response
 */
function s41_rest_get( string $route, array $params = array() ) {
	if ( function_exists( 'PLL' ) && PLL() ) {
		PLL()->curlang = null;
	}

	$request = new WP_REST_Request( 'GET', $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	return rest_get_server()->dispatch( $request );
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

echo "== Stage 4.1 — bilingual REST contract ==\n";

if ( ! function_exists( 'pll_get_post_language' ) ) {
	echo "  SKIP: Polylang is not active in this environment.\n";
	exit( 0 );
}

if ( ! function_exists( 'conexao_rest_language_post_types' ) ) {
	echo "  FAIL: inc/rest-language.php is not loaded.\n";
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
$course_en         = $fixture( 'course_provider', 'fetch-courses-en' );

echo "-- fixtures --\n";
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
	'course EN'            => $course_en,
) as $label => $id ) {
	if ( $id <= 0 ) {
		$fixture_ok = false;
	}
	s41_assert( $id > 0, "fixture present: {$label} (#{$id})" );
}

if ( ! $fixture_ok ) {
	echo "FATAL: the Stage 3.2 pilot dataset is incomplete — run the stage32 seeders first.\n";
	exit( 1 );
}

// ---------------------------------------------------------------------------
// 1. Request model: default behaviour, lang=pt, lang=en, invalid values.
// ---------------------------------------------------------------------------
echo "-- 1. request model --\n";

$default_events = s41_rest_array( '/wp/v2/event', array( 'per_page' => 100 ) );
$pt_events      = s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'pt' ) );
$en_events      = s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'en' ) );

s41_assert( 200 === $default_events['status'], 'default event collection is 200' );
s41_assert( in_array( $ev_pt_master, s41_ids( $default_events['data'] ), true ), 'default collection contains the PT master' );
s41_assert( in_array( $ev_en_translation, s41_ids( $default_events['data'] ), true ), 'default collection contains the EN translation (legacy unfiltered behaviour)' );
s41_assert( in_array( $ev_en_source, s41_ids( $default_events['data'] ), true ), 'default collection contains the source-inherited EN event' );

$pt_ids = s41_ids( $pt_events['data'] );
$en_ids = s41_ids( $en_events['data'] );

s41_assert( in_array( $ev_pt_master, $pt_ids, true ), 'lang=pt contains the PT master' );
s41_assert( ! in_array( $ev_en_translation, $pt_ids, true ), 'lang=pt excludes the EN translation' );
s41_assert( ! in_array( $ev_en_source, $pt_ids, true ), 'lang=pt excludes the source-inherited EN event' );

s41_assert( in_array( $ev_en_translation, $en_ids, true ), 'lang=en contains the real EN translation' );
s41_assert( in_array( $ev_en_source, $en_ids, true ), 'lang=en contains the source-inherited EN event' );
s41_assert( in_array( $ev_pt_b2, $en_ids, true ), 'lang=en contains the untranslated PT event as B2 fallback' );
s41_assert( ! in_array( $ev_pt_master, $en_ids, true ), 'lang=en replaces the PT master that has an EN translation (never duplicated)' );

// Hidden events absent from every public variant.
foreach ( array( 'default' => $default_events, 'pt' => $pt_events, 'en' => $en_events ) as $label => $resp ) {
	$ids = s41_ids( $resp['data'] );
	s41_assert( ! in_array( $ev_expired, $ids, true ) && ! in_array( $ev_rejected, $ids, true ) && ! in_array( $ev_removed, $ids, true ), "hidden events (expired/rejected/source_not_found) absent from {$label} collection" );
}

// Invalid language: deterministic 400, never a silent fallback.
foreach ( array( '/wp/v2/event', '/wp/v2/leisure', '/wp/v2/guide', '/wp/v2/posts', '/wp/v2/job', '/wp/v2/course_provider', '/wp/v2/sponsor' ) as $route ) {
	$bad = s41_rest_array( $route, array( 'lang' => 'xx' ) );
	s41_assert( 400 === $bad['status'] && is_array( $bad['data'] ) && 'conexao_rest_invalid_lang' === ( $bad['data']['code'] ?? '' ), "{$route}?lang=xx -> 400 conexao_rest_invalid_lang" );
}
$bad_detail = s41_rest_array( '/wp/v2/event/' . $ev_pt_master, array( 'lang' => 'xx' ) );
s41_assert( 400 === $bad_detail['status'] && 'conexao_rest_invalid_lang' === ( $bad_detail['data']['code'] ?? '' ), 'detail ?lang=xx -> 400' );
$bad_search = s41_rest_array( '/wp/v2/search', array( 'search' => 'festa', 'lang' => 'de' ) );
s41_assert( 400 === $bad_search['status'] && 'conexao_rest_invalid_lang' === ( $bad_search['data']['code'] ?? '' ), 'search ?lang=de -> 400' );
$bad_term = s41_rest_array( '/wp/v2/conexao_county', array( 'lang' => 'fr' ) );
s41_assert( 400 === $bad_term['status'] && 'conexao_rest_invalid_lang' === ( $bad_term['data']['code'] ?? '' ), 'term collection ?lang=fr -> 400' );

// ---------------------------------------------------------------------------
// 2. Translation metadata (conexao_language) on collections.
// ---------------------------------------------------------------------------
echo "-- 2. translation metadata --\n";

$pt_master_row = s41_row( $pt_events['data'], $ev_pt_master );
$meta          = s41_lang_of( (array) $pt_master_row );
s41_assert( null !== $meta, 'PT master carries conexao_language' );
s41_assert( 'pt' === ( $meta['lang'] ?? '' ), 'PT master lang=pt' );
s41_assert( false === ( $meta['is_fallback'] ?? true ), 'PT master is_fallback=false under lang=pt' );
s41_assert( isset( $meta['translations']['en']['id'] ) && $ev_en_translation === (int) $meta['translations']['en']['id'], 'PT master translations.en points at the EN translation' );
s41_assert( isset( $meta['translations']['en']['url'] ) && false !== strpos( (string) $meta['translations']['en']['url'], '/en/eventos/festa-junina-dublin-2026-en/' ), 'PT master translations.en carries the EN permalink' );

$en_row = s41_row( $en_events['data'], $ev_en_translation );
$meta   = s41_lang_of( (array) $en_row );
s41_assert( 'en' === ( $meta['lang'] ?? '' ), 'EN translation lang=en' );
s41_assert( false === ( $meta['is_fallback'] ?? true ), 'EN translation is_fallback=false under lang=en' );
s41_assert( isset( $meta['translations']['pt']['id'] ) && $ev_pt_master === (int) $meta['translations']['pt']['id'], 'EN translation translations.pt points at the PT master' );

$b2_row = s41_row( $en_events['data'], $ev_pt_b2 );
$meta   = s41_lang_of( (array) $b2_row );
s41_assert( 'pt' === ( $meta['lang'] ?? '' ), 'B2 fallback record keeps lang=pt (the record language)' );
s41_assert( true === ( $meta['is_fallback'] ?? false ), 'B2 fallback is_fallback=true under lang=en' );
s41_assert( empty( $meta['translations'] ), 'B2 fallback has no translations' );
s41_assert( false !== strpos( (string) ( is_array( $b2_row ) ? ( $b2_row['link'] ?? '' ) : '' ), '/eventos/feijoada-beneficente-cork/' ), 'B2 fallback link stays the PT canonical (no /en/ shell URL)' );

$src_row = s41_row( $en_events['data'], $ev_en_source );
$meta    = s41_lang_of( (array) $src_row );
s41_assert( 'en' === ( $meta['lang'] ?? '' ) && false === ( $meta['is_fallback'] ?? true ) && empty( $meta['translations'] ), 'source-inherited EN event: lang=en, no fallback, no translations' );
s41_assert( false !== strpos( (string) ( is_array( $src_row ) ? ( $src_row['link'] ?? '' ) : '' ), '/en/eventos/irish-dance-workshop-dublin/' ), 'source-inherited EN link is the EN self-canonical' );

// No duplicate identity inside one EN collection (event export uuid).
$uuids = array();
foreach ( (array) $en_events['data'] as $row ) {
	$uuid = is_array( $row ) && isset( $row['meta'] ) && is_array( $row['meta'] ) ? (string) ( $row['meta']['_event_export_uuid'] ?? '' ) : '';
	if ( '' !== $uuid ) {
		$uuids[] = $uuid;
	}
}
s41_assert( count( $uuids ) === count( array_unique( $uuids ) ), 'EN event collection has no duplicate _event_export_uuid (one row per identity)' );

// ---------------------------------------------------------------------------
// 3. Detail endpoints.
// ---------------------------------------------------------------------------
echo "-- 3. detail endpoints --\n";

// PT record requested as PT.
$r = s41_rest_array( '/wp/v2/event/' . $ev_pt_master, array( 'lang' => 'pt' ) );
s41_assert( 200 === $r['status'] && 'pt' === ( $r['data']['conexao_language']['lang'] ?? '' ) && false === $r['data']['conexao_language']['is_fallback'], 'PT event detail with lang=pt -> 200 pt record' );
s41_assert( false !== strpos( (string) $r['data']['link'], '/eventos/festa-junina-dublin-2026/' ), 'PT detail link is the PT permalink' );

// EN real translation requested as EN.
$r = s41_rest_array( '/wp/v2/event/' . $ev_en_translation, array( 'lang' => 'en' ) );
s41_assert( 200 === $r['status'] && 'en' === ( $r['data']['conexao_language']['lang'] ?? '' ) && false === $r['data']['conexao_language']['is_fallback'], 'EN translation detail with lang=en -> 200 en record' );
s41_assert( false !== strpos( (string) $r['data']['link'], '/en/eventos/festa-junina-dublin-2026-en/' ), 'EN translation detail link is the EN canonical' );

// B2 fallback detail: PT event without EN translation, requested as EN.
$r = s41_rest_array( '/wp/v2/event/' . $ev_pt_b2, array( 'lang' => 'en' ) );
s41_assert( 200 === $r['status'], 'B2 event detail with lang=en -> 200' );
s41_assert( 'pt' === ( $r['data']['conexao_language']['lang'] ?? '' ) && true === $r['data']['conexao_language']['is_fallback'], 'B2 event detail: lang=pt, is_fallback=true' );
s41_assert( false !== strpos( (string) $r['data']['link'], '/eventos/feijoada-beneficente-cork/' ), 'B2 detail link is the PT canonical' );

// Source-inherited EN detail.
$r = s41_rest_array( '/wp/v2/event/' . $ev_en_source, array( 'lang' => 'en' ) );
s41_assert( 200 === $r['status'] && 'en' === ( $r['data']['conexao_language']['lang'] ?? '' ) && empty( $r['data']['conexao_language']['translations'] ), 'source-inherited EN event detail -> 200 en, no translations' );

// PT master that HAS an EN translation, requested as EN: 404 + recovery pointer.
$r = s41_rest_array( '/wp/v2/event/' . $ev_pt_master, array( 'lang' => 'en' ) );
s41_assert( 404 === $r['status'] && 'conexao_rest_language_unavailable' === ( $r['data']['code'] ?? '' ), 'PT master with EN translation, lang=en -> 404 conexao_rest_language_unavailable' );
s41_assert( $ev_en_translation === (int) ( $r['data']['data']['translations']['en']['id'] ?? 0 ), '404 carries the EN translation pointer (id)' );

// EN record requested as PT: 404 + pointer back to PT.
$r = s41_rest_array( '/wp/v2/event/' . $ev_en_translation, array( 'lang' => 'pt' ) );
s41_assert( 404 === $r['status'] && 'conexao_rest_language_unavailable' === ( $r['data']['code'] ?? '' ), 'EN translation requested with lang=pt -> 404' );
s41_assert( $ev_pt_master === (int) ( $r['data']['data']['translations']['pt']['id'] ?? 0 ), '404 carries the PT master pointer (id)' );

// Source-inherited EN record requested as PT: 404, no PT counterpart exists.
$r = s41_rest_array( '/wp/v2/event/' . $ev_en_source, array( 'lang' => 'pt' ) );
s41_assert( 404 === $r['status'] && empty( $r['data']['data']['translations'] ), 'source-inherited EN event with lang=pt -> 404 without translations' );

// B1 content (guide) PT record without EN translation, requested as EN: no fallback.
$guide_b1 = 0;
foreach ( (array) s41_rest_array( '/wp/v2/guide', array( 'per_page' => 100, 'lang' => 'pt' ) )['data'] as $row ) {
	$m = s41_lang_of( $row );
	if ( $m && empty( $m['translations'] ) ) {
		$guide_b1 = (int) $row['id'];
		break;
	}
}
if ( $guide_b1 > 0 ) {
	$r = s41_rest_array( '/wp/v2/guide/' . $guide_b1, array( 'lang' => 'en' ) );
	s41_assert( 404 === $r['status'] && 'conexao_rest_language_unavailable' === ( $r['data']['code'] ?? '' ), 'B1 guide (no EN translation) with lang=en -> 404 (never a B2 render)' );
} else {
	s41_assert( false, 'no untranslated PT guide found for the B1 detail check' );
}

// Nonexistent record.
$r = s41_rest_array( '/wp/v2/event/999999', array( 'lang' => 'en' ) );
s41_assert( 404 === $r['status'] && 'rest_post_invalid_id' === ( $r['data']['code'] ?? '' ), 'nonexistent event -> 404 rest_post_invalid_id' );

// Hidden events: detail must 404 like the front-end single, in every variant.
foreach ( array( 'expired' => $ev_expired, 'rejected' => $ev_rejected, 'source_not_found' => $ev_removed ) as $label => $hidden_id ) {
	$r0 = s41_rest_array( '/wp/v2/event/' . $hidden_id );
	$r1 = s41_rest_array( '/wp/v2/event/' . $hidden_id, array( 'lang' => 'pt' ) );
	$r2 = s41_rest_array( '/wp/v2/event/' . $hidden_id, array( 'lang' => 'en' ) );
	s41_assert( 404 === $r0['status'] && 404 === $r1['status'] && 404 === $r2['status'], "hidden event ({$label}) detail -> 404 without lang, with lang=pt and with lang=en" );
	s41_assert( 'rest_post_invalid_id' === ( $r2['data']['code'] ?? '' ), "hidden event ({$label}) 404 is indistinguishable from a nonexistent record" );
}

// ---------------------------------------------------------------------------
// 4. Collection membership per post type (the B1/B2 matrix).
// ---------------------------------------------------------------------------
echo "-- 4. collection membership --\n";

// B2 types: EN = EN records + untranslated PT records; PT masters with an EN
// translation are replaced. B1 types (guide, post): EN = EN records only.
$expect = array(
	// route => [ type, pt_must_have, pt_must_not, en_must_have, en_must_not ]
	'/wp/v2/event'           => array( 'b2', array( $ev_pt_master, $ev_pt_b2 ), array( $ev_en_translation, $ev_en_source ), array( $ev_en_translation, $ev_en_source, $ev_pt_b2 ), array( $ev_pt_master, $ev_expired, $ev_rejected, $ev_removed ) ),
	'/wp/v2/leisure'         => array( 'b2', array( $leisure_pt_master, $leisure_b2, $leisure_external ), array( $leisure_en ), array( $leisure_en, $leisure_b2, $leisure_external ), array( $leisure_pt_master ) ),
	'/wp/v2/guide'           => array( 'b1', array( $guide_pt_master ), array( $guide_en ), array( $guide_en ), array( $guide_pt_master ) ),
	'/wp/v2/posts'           => array( 'b1', array( $post_pt_master ), array( $post_en ), array( $post_en ), array( $post_pt_master ) ),
	'/wp/v2/job'             => array( 'b2', array( $job_pt_master ), array( $job_en ), array( $job_en, $job_pt_master ? 0 : 0 ), array( $job_pt_master ) ),
	'/wp/v2/course_provider' => array( 'b2', array( $course_pt_master ), array( $course_en ), array( $course_en ), array( $course_pt_master ) ),
	'/wp/v2/sponsor'         => array( 'b2', array( $sponsor_pt_master ), array( $sponsor_en ), array( $sponsor_en ), array( $sponsor_pt_master ) ),
);

foreach ( $expect as $route => $rule ) {
	list( $kind, $pt_have, $pt_not, $en_have, $en_not ) = $rule;
	$pt = s41_ids( s41_rest_array( $route, array( 'per_page' => 100, 'lang' => 'pt' ) )['data'] );
	$en = s41_ids( s41_rest_array( $route, array( 'per_page' => 100, 'lang' => 'en' ) )['data'] );

	foreach ( array_filter( $pt_have ) as $id ) {
		s41_assert( in_array( $id, $pt, true ), "{$route} lang=pt contains #{$id}" );
	}
	foreach ( array_filter( $pt_not ) as $id ) {
		s41_assert( ! in_array( $id, $pt, true ), "{$route} lang=pt excludes #{$id}" );
	}
	foreach ( array_filter( $en_have ) as $id ) {
		s41_assert( in_array( $id, $en, true ), "{$route} lang=en contains #{$id}" );
	}
	foreach ( array_filter( $en_not ) as $id ) {
		s41_assert( ! in_array( $id, $en, true ), "{$route} lang=en excludes #{$id}" );
	}

	// No id may appear twice in one language collection.
	s41_assert( count( $pt ) === count( array_unique( $pt ) ), "{$route} lang=pt has no duplicate ids" );
	s41_assert( count( $en ) === count( array_unique( $en ) ), "{$route} lang=en has no duplicate ids" );

	// B2 EN collections: every PT row is an explicit fallback, every EN row is not.
	if ( 'b2' === $kind ) {
		$rows = s41_rest_array( $route, array( 'per_page' => 100, 'lang' => 'en' ) )['data'];
		$ok   = true;
		foreach ( (array) $rows as $row ) {
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
		s41_assert( $ok, "{$route} lang=en fallback flags are consistent (pt row => is_fallback, en row => not)" );
	}
}

// `include` (post__in) must compose with the EN replacement rule.
$r = s41_rest_array( '/wp/v2/event', array( 'include' => $ev_pt_master . ',' . $ev_pt_b2, 'lang' => 'en' ) );
s41_assert( 200 === $r['status'] && array( $ev_pt_b2 ) === s41_ids( $r['data'] ), 'include + lang=en drops the replaced PT master, keeps the B2 record' );

// Pagination composes with the language filter.
$p1 = s41_rest_array( '/wp/v2/event', array( 'per_page' => 2, 'page' => 1, 'lang' => 'en' ) );
$p2 = s41_rest_array( '/wp/v2/event', array( 'per_page' => 2, 'page' => 2, 'lang' => 'en' ) );
$paged = array_merge( s41_ids( $p1['data'] ), s41_ids( $p2['data'] ) );
s41_assert( 2 === count( s41_ids( $p1['data'] ) ), 'EN events page 1 has per_page=2 items' );
s41_assert( count( $paged ) === count( array_unique( $paged ) ), 'EN events pages 1+2 have no overlap' );
s41_assert( ! in_array( $ev_pt_master, $paged, true ), 'EN events pagination never surfaces the replaced PT master' );

// ---------------------------------------------------------------------------
// 5. Event hard gates.
// ---------------------------------------------------------------------------
echo "-- 5. event hard gates --\n";

// Identity meta is exposed unchanged and identical between master/translation.
$master_meta = s41_rest_array( '/wp/v2/event/' . $ev_pt_master )['data']['meta'];
$en_meta     = s41_rest_array( '/wp/v2/event/' . $ev_en_translation )['data']['meta'];
s41_assert( '' !== (string) ( $master_meta['_event_source'] ?? '' ) && ( $master_meta['_event_source'] ?? null ) === ( $en_meta['_event_source'] ?? null ), '_event_source identical on PT master and EN translation' );
s41_assert( '' !== (string) ( $master_meta['_event_source_id'] ?? '' ) && ( $master_meta['_event_source_id'] ?? null ) === ( $en_meta['_event_source_id'] ?? null ), '_event_source_id identical on PT master and EN translation' );
s41_assert( '' !== (string) ( $master_meta['_event_export_uuid'] ?? '' ) && ( $master_meta['_event_export_uuid'] ?? null ) === ( $en_meta['_event_export_uuid'] ?? null ), '_event_export_uuid shared by PT master and EN translation' );

// The source-inherited EN event keeps its own identity.
$src_meta = s41_rest_array( '/wp/v2/event/' . $ev_en_source )['data']['meta'];
s41_assert( '' !== (string) ( $src_meta['_event_source'] ?? '' ) && '' !== (string) ( $src_meta['_event_source_id'] ?? '' ), 'source-inherited EN event keeps its own _event_source/_event_source_id' );
s41_assert( ( $src_meta['_event_export_uuid'] ?? '' ) !== ( $master_meta['_event_export_uuid'] ?? '' ), 'source-inherited EN event has a distinct export uuid (separate identity)' );

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
s41_assert( $before === $after, 'GET collection/detail requests never mutate event identity, status or modified date' );

// Exactly one shared master<->translation export uuid pair (the EN translation
// is never an additional export identity row).
$export_rows = array();
foreach ( get_posts( array( 'post_type' => 'event', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) as $event_id ) {
	$uuid = get_post_meta( $event_id, '_event_export_uuid', true );
	if ( '' !== $uuid ) {
		$export_rows[ $uuid ][] = $event_id;
	}
}
$shared = 0;
foreach ( $export_rows as $uuid => $ids ) {
	if ( count( $ids ) > 1 ) {
		$shared++;
	}
}
s41_assert( 1 === $shared, 'exactly one shared master<->translation export uuid pair exists (no extra identity rows)' );

// ---------------------------------------------------------------------------
// 6. Lazer hard gates.
// ---------------------------------------------------------------------------
echo "-- 6. lazer hard gates --\n";

// _leisure_export_uuid is migration identity data (not exposed in the REST
// payload — unchanged since Stage 3.3), so the identity checks are made at
// the data level: the EN translation shares the master's UUID and the
// migration classifier still resolves the same destinations.
$leisure_master_uuid = (string) get_post_meta( $leisure_pt_master, '_leisure_export_uuid', true );
$leisure_en_uuid     = (string) get_post_meta( $leisure_en, '_leisure_export_uuid', true );
s41_assert( '' !== $leisure_master_uuid, 'leisure PT master keeps a non-empty _leisure_export_uuid' );
s41_assert( $leisure_master_uuid === $leisure_en_uuid, '_leisure_export_uuid identical on PT master and EN translation' );
s41_assert( ! array_key_exists( '_leisure_export_uuid', (array) s41_rest_array( '/wp/v2/leisure/' . $leisure_pt_master )['data']['meta'] ), 'REST leisure payload does not newly expose _leisure_export_uuid (identity meta stays internal, unchanged)' );

$en_leisure     = s41_rest_array( '/wp/v2/leisure', array( 'per_page' => 100, 'lang' => 'en' ) );
$en_leisure_ids = s41_ids( $en_leisure['data'] );
s41_assert( in_array( $leisure_en, $en_leisure_ids, true ), 'EN leisure collection resolves the real EN translation' );
s41_assert( ! in_array( $leisure_pt_master, $en_leisure_ids, true ), 'EN leisure collection replaces the PT master (not duplicated)' );
s41_assert( in_array( $leisure_b2, $en_leisure_ids, true ), 'EN leisure collection keeps the untranslated internal PT record as B2' );
s41_assert( in_array( $leisure_external, $en_leisure_ids, true ), 'EN leisure collection keeps the externally classified PT record' );

// External classification is data-preserved and the classifier itself is
// untouched by the REST layer (the redirect stays a front-end concern).
s41_assert( function_exists( 'conexao_leisure_external_url' ) && '' !== conexao_leisure_external_url( $leisure_external ), 'external leisure classification unchanged (fota resolves an external url)' );
s41_assert( function_exists( 'conexao_leisure_external_url' ) && '' === conexao_leisure_external_url( $leisure_b2 ), 'internal leisure classification unchanged (cliffs-of-moher stays internal)' );
$ext_row      = s41_row( $en_leisure['data'], $leisure_external );
$ext_meta_row = s41_lang_of( (array) $ext_row );
s41_assert( null !== $ext_meta_row && 'pt' === $ext_meta_row['lang'] && true === $ext_meta_row['is_fallback'], 'external leisure record appears in EN as an explicit B2 fallback' );

// One leisure identity per language collection (data-level uuid per row id).
$leisure_uuids = array();
foreach ( $en_leisure_ids as $row_id ) {
	$uuid = (string) get_post_meta( $row_id, '_leisure_export_uuid', true );
	if ( '' !== $uuid ) {
		$leisure_uuids[] = $uuid;
	}
}
s41_assert( count( $leisure_uuids ) === count( array_unique( $leisure_uuids ) ), 'EN leisure collection has no duplicate _leisure_export_uuid' );

// ---------------------------------------------------------------------------
// 7. Taxonomy / filter contract.
// ---------------------------------------------------------------------------
echo "-- 7. taxonomy contract --\n";

$cat_en  = s41_rest_array( '/wp/v2/conexao_category', array( 'per_page' => 100, 'lang' => 'en' ) );
$cat_pt  = s41_rest_array( '/wp/v2/conexao_category', array( 'per_page' => 100, 'lang' => 'pt' ) );
$cat_all = s41_rest_array( '/wp/v2/conexao_category', array( 'per_page' => 100 ) );

$cat_en_slugs = array_map( static function ( $t ) { return $t['slug'] ?? ''; }, (array) $cat_en['data'] );
$cat_pt_slugs = array_map( static function ( $t ) { return $t['slug'] ?? ''; }, (array) $cat_pt['data'] );

s41_assert( in_array( 'documents', $cat_en_slugs, true ) && in_array( 'finances', $cat_en_slugs, true ) && in_array( 'festivals', $cat_en_slugs, true ) && in_array( 'nature', $cat_en_slugs, true ), 'EN category collection exposes the EN slugs (documents, finances, festivals, nature)' );
s41_assert( in_array( 'documentos', $cat_pt_slugs, true ) && in_array( 'financas', $cat_pt_slugs, true ), 'PT category collection exposes the PT slugs (documentos, financas)' );
s41_assert( ! in_array( 'documentos', $cat_en_slugs, true ) && ! in_array( 'documents', $cat_pt_slugs, true ), 'translated category collections never mix languages' );
s41_assert( count( (array) $cat_all['data'] ) === count( (array) $cat_en['data'] ) + count( (array) $cat_pt['data'] ), 'default (no lang) category collection = PT + EN terms (unchanged legacy shape)' );

// Term metadata: EN term links its PT counterpart; shared terms are language-less.
$doc_en = null;
foreach ( (array) $cat_en['data'] as $t ) {
	if ( 'documents' === ( $t['slug'] ?? '' ) ) {
		$doc_en = $t;
	}
}
s41_assert( null !== $doc_en && 'en' === ( $doc_en['conexao_language']['lang'] ?? '' ), 'EN term documents has lang=en' );
s41_assert( isset( $doc_en['conexao_language']['translations']['pt']['id'] ), 'EN term documents links its PT translation' );

$county_en = s41_rest_array( '/wp/v2/conexao_county', array( 'per_page' => 100, 'lang' => 'en' ) );
$county_pt = s41_rest_array( '/wp/v2/conexao_county', array( 'per_page' => 100, 'lang' => 'pt' ) );
s41_assert( s41_ids( $county_en['data'] ) === s41_ids( $county_pt['data'] ), 'county collection is identical in PT and EN (shared terms)' );
$dublin = null;
foreach ( (array) $county_en['data'] as $t ) {
	if ( 'dublin' === ( $t['slug'] ?? '' ) ) {
		$dublin = $t;
	}
}
s41_assert(
	null !== $dublin
	&& is_array( $dublin['conexao_language'] ?? null )
	&& array_key_exists( 'lang', $dublin['conexao_language'] )
	&& null === $dublin['conexao_language']['lang']
	&& empty( $dublin['conexao_language']['translations'] ),
	'shared county dublin: lang=null, no translations (never duplicated/suffixed)'
);

$town_en = s41_rest_array( '/wp/v2/conexao_town', array( 'per_page' => 100, 'lang' => 'en' ) );
$town_pt = s41_rest_array( '/wp/v2/conexao_town', array( 'per_page' => 100, 'lang' => 'pt' ) );
s41_assert( s41_ids( $town_en['data'] ) === s41_ids( $town_pt['data'] ), 'town collection is identical in PT and EN (shared terms)' );

// No suffixed/duplicated location terms anywhere.
$all_county_slugs = array_map( static function ( $t ) { return $t['slug'] ?? ''; }, (array) s41_rest_array( '/wp/v2/conexao_county', array( 'per_page' => 100 ) )['data'] );
$suffixed         = array_filter( $all_county_slugs, static function ( $slug ) { return (bool) preg_match( '/-(en|pt)$/', (string) $slug ); } );
s41_assert( 0 === count( $suffixed ), 'no -en/-pt suffixed county terms exist' );

// Filtering posts by the language-appropriate term id.
$doc_en_id          = $doc_en ? (int) $doc_en['id'] : 0;
$guides_en_filtered = s41_rest_array( '/wp/v2/guide', array( 'per_page' => 100, 'lang' => 'en', 'conexao_category' => $doc_en_id ) );
s41_assert( in_array( $guide_en, s41_ids( $guides_en_filtered['data'] ), true ), 'EN guides filter by EN term documents returns the EN guide' );
s41_assert( ! in_array( $guide_pt_master, s41_ids( $guides_en_filtered['data'] ), true ), 'EN guides filter by EN term excludes the PT master (PT terms stay PT)' );

// Shared county filter works the same in both languages.
$dublin_id    = $dublin ? (int) $dublin['id'] : 0;
$ev_en_dublin = s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'en', 'conexao_county' => $dublin_id ) );
$ev_pt_dublin = s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'pt', 'conexao_county' => $dublin_id ) );
s41_assert( in_array( $ev_pt_master, s41_ids( $ev_pt_dublin['data'] ), true ), 'PT events filtered by shared county dublin contain the PT Dublin event' );
s41_assert( in_array( $ev_en_translation, s41_ids( $ev_en_dublin['data'] ), true ), 'EN events filtered by shared county dublin contain the EN Dublin event' );
s41_assert( ! in_array( $ev_pt_master, s41_ids( $ev_en_dublin['data'] ), true ), 'EN events filtered by shared county still replace the translated PT master' );

// ---------------------------------------------------------------------------
// 8. Search contract.
// ---------------------------------------------------------------------------
echo "-- 8. search contract --\n";

$search_default = s41_rest_array( '/wp/v2/search', array( 'search' => 'festa', 'per_page' => 100 ) );
$search_en      = s41_rest_array( '/wp/v2/search', array( 'search' => 'festa', 'per_page' => 100, 'lang' => 'en' ) );
$search_pt      = s41_rest_array( '/wp/v2/search', array( 'search' => 'festa', 'per_page' => 100, 'lang' => 'pt' ) );

$search_default_ids = s41_ids( $search_default['data'] );
$search_en_ids      = s41_ids( $search_en['data'] );
$search_pt_ids      = s41_ids( $search_pt['data'] );

s41_assert( in_array( $post_pt_master, $search_default_ids, true ) && in_array( $post_en, $search_default_ids, true ), 'default search keeps the legacy mixed membership' );
s41_assert( in_array( $post_en, $search_en_ids, true ) && in_array( $ev_en_translation, $search_en_ids, true ), 'EN search returns EN records' );
s41_assert( ! in_array( $post_pt_master, $search_en_ids, true ) && ! in_array( $ev_pt_master, $search_en_ids, true ), 'EN search excludes the translated PT masters (replaced, never duplicated)' );
s41_assert( ! in_array( $ev_expired, $search_en_ids, true ) && ! in_array( $ev_rejected, $search_en_ids, true ) && ! in_array( $ev_removed, $search_en_ids, true ), 'EN search excludes hidden events' );
s41_assert( in_array( $post_pt_master, $search_pt_ids, true ) && in_array( $ev_pt_master, $search_pt_ids, true ), 'PT search returns the PT masters' );
s41_assert( ! in_array( $post_en, $search_pt_ids, true ) && ! in_array( $ev_en_translation, $search_pt_ids, true ), 'PT search excludes EN records' );

// Every search result carries conexao_language (no client-side text inference).
$all_flagged = true;
foreach ( (array) $search_en['data'] as $row ) {
	if ( ! is_array( $row ) || ! isset( $row['conexao_language']['lang'] ) ) {
		$all_flagged = false;
	}
}
s41_assert( $all_flagged && count( (array) $search_en['data'] ) > 0, 'every search result carries conexao_language.lang' );

// B1-only content (untranslated PT guide) never appears in EN search.
$search_b1     = s41_rest_array( '/wp/v2/search', array( 'search' => 'saúde', 'per_page' => 100, 'lang' => 'en' ) );
$search_b1_ids = s41_ids( $search_b1['data'] );
s41_assert( ! in_array( $guide_b1, $search_b1_ids, true ), 'B1-only PT guide never appears in EN search' );

// `search` param on a typed collection composes with the language rule.
$col_search_en = s41_rest_array( '/wp/v2/event', array( 'search' => 'festa', 'per_page' => 100, 'lang' => 'en' ) );
$col_search_pt = s41_rest_array( '/wp/v2/event', array( 'search' => 'festa', 'per_page' => 100, 'lang' => 'pt' ) );
s41_assert( in_array( $ev_en_translation, s41_ids( $col_search_en['data'] ), true ) && ! in_array( $ev_pt_master, s41_ids( $col_search_en['data'] ), true ), 'event collection search lang=en: EN record in, PT master out' );
s41_assert( in_array( $ev_pt_master, s41_ids( $col_search_pt['data'] ), true ) && ! in_array( $ev_en_translation, s41_ids( $col_search_pt['data'] ), true ), 'event collection search lang=pt: PT record in, EN record out' );

// ---------------------------------------------------------------------------
// 9. Cache separation.
// ---------------------------------------------------------------------------
echo "-- 9. cache separation --\n";

// Warm EN first, then PT, then EN again: both languages must answer their own
// correct payload regardless of order (no shared response cache exists; the
// only reused cache is the language-independent B2 replacement set, which the
// theme invalidates on save/delete).
$en_first  = s41_ids( s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'en' ) )['data'] );
$pt_warm   = s41_ids( s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'pt' ) )['data'] );
$en_second = s41_ids( s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'en' ) )['data'] );
s41_assert( $en_first === $en_second, 'warming PT between two EN reads does not change the EN payload' );
s41_assert( in_array( $ev_pt_master, $pt_warm, true ) && ! in_array( $ev_pt_master, $en_second, true ), 'PT warm-up keeps the PT master; EN read still replaces it' );

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
s41_assert( in_array( $en_event_new, $en_create_ids, true ), 'after creating the EN translation, the EN event collection contains it' );
s41_assert( ! in_array( $ev_pt_b2, $en_create_ids, true ), 'after creating the EN translation, the PT master is replaced (no stale B2 fallback)' );

// The new EN record is a translation of the same identity (shared uuid), and
// the collection still has one row per identity.
$new_row  = s41_row( $en_after_create['data'], $en_event_new );
$new_meta = s41_lang_of( (array) $new_row );
s41_assert( null !== $new_meta && 'en' === $new_meta['lang'] && false === $new_meta['is_fallback'] && isset( $new_meta['translations']['pt'] ), 'new EN translation reports lang=en, no fallback, PT link' );
$uuids_after = array();
foreach ( (array) $en_after_create['data'] as $row ) {
	$uuid = is_array( $row ) && isset( $row['meta'] ) && is_array( $row['meta'] ) ? (string) ( $row['meta']['_event_export_uuid'] ?? '' ) : '';
	if ( '' !== $uuid ) {
		$uuids_after[] = $uuid;
	}
}
s41_assert( count( $uuids_after ) === count( array_unique( $uuids_after ) ), 'EN event collection still has one row per identity after the translation is created' );

wp_trash_post( $en_event_new );
$en_after_trash = s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'en' ) );
$en_trash_ids   = s41_ids( $en_after_trash['data'] );
s41_assert( ! in_array( $en_event_new, $en_trash_ids, true ), 'after trashing the EN translation, it leaves the EN collection' );
s41_assert( in_array( $ev_pt_b2, $en_trash_ids, true ), 'after trashing the EN translation, the PT master returns exactly once (B2)' );

// PT collection was never affected by the EN translation lifecycle.
$pt_after = s41_ids( s41_rest_array( '/wp/v2/event', array( 'per_page' => 100, 'lang' => 'pt' ) )['data'] );
s41_assert( $pt_warm === $pt_after, 'PT event collection is identical before/after the EN translation lifecycle' );

// ---------------------------------------------------------------------------
// 10. Backward compatibility: requests without `lang` behave exactly as before.
// ---------------------------------------------------------------------------
echo "-- 10. backward compatibility --\n";

// No-lang collections return the full unfiltered (mixed) set: the union of
// the PT and EN public memberships.
foreach ( array( '/wp/v2/event', '/wp/v2/leisure', '/wp/v2/guide', '/wp/v2/posts', '/wp/v2/job', '/wp/v2/course_provider', '/wp/v2/sponsor' ) as $route ) {
	$all = s41_ids( s41_rest_array( $route, array( 'per_page' => 100 ) )['data'] );
	$pt  = s41_ids( s41_rest_array( $route, array( 'per_page' => 100, 'lang' => 'pt' ) )['data'] );
	$en  = s41_ids( s41_rest_array( $route, array( 'per_page' => 100, 'lang' => 'en' ) )['data'] );

	// Every PT-language record present in the PT collection must be in the
	// default collection, and every EN record in the EN collection must be in
	// the default collection (nothing is removed from the legacy surface).
	$missing = array_diff( array_merge( $pt, $en ), $all );
	s41_assert( empty( $missing ), "{$route} without lang keeps every PT and EN record (nothing removed)" );

	// The legacy set is exactly the public records of both languages: the
	// default collection must not contain anything outside PT ∪ (EN incl. B2).
	$extra = array_diff( $all, array_merge( $pt, $en ) );
	s41_assert( empty( $extra ), "{$route} without lang adds no records beyond the PT + EN memberships" );
}

// No-lang detail for a PT and an EN record: 200 with accurate metadata (the
// legacy surface never 404s a language mismatch — that rule only applies when
// the caller explicitly requests a language).
$r = s41_rest_array( '/wp/v2/event/' . $ev_pt_master );
s41_assert( 200 === $r['status'] && 'pt' === ( $r['data']['conexao_language']['lang'] ?? '' ), 'no-lang detail of a PT record -> 200' );
$r = s41_rest_array( '/wp/v2/event/' . $ev_en_translation );
s41_assert( 200 === $r['status'] && 'en' === ( $r['data']['conexao_language']['lang'] ?? '' ), 'no-lang detail of an EN record -> 200 (legacy behaviour preserved)' );

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
s41_assert( empty( $missing_fields ), 'legacy event payload keeps every pre-existing field (' . implode( ',', $missing_fields ) . ')' );

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

echo "\nstage 4.1 rest-language: {$passed} passed, {$failed} failed\n";
exit( 0 === $failed ? 0 : 1 );

