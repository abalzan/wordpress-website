<?php
/**
 * Stage 3.2 — bilingual rollout focus tests.
 *
 * Covers the Stage 3.2 deliverables at the logic level (the HTTP matrix in
 * the stage report covers the rendering level):
 *
 *  - English static front page: pll_home_url('en') is the bare /en/ URL and
 *    the EN home page is the linked translation of the PT front page.
 *  - B2 page allowlist: exact approved set (irlanda + county pages), B2
 *    rendering decision for allowlisted vs unlisted vs translated pages.
 *  - B2 replacement: a PT record with a published EN translation is hidden
 *    behind it (never both) — the archive/search exclusion set.
 *  - Taxonomy policy: categories/tags Polylang-translated with linked EN
 *    terms; counties/towns shared (untranslated, no duplicates).
 *  - EN records carry linked EN category terms + the SAME shared county/town
 *    terms as their PT siblings.
 *  - Event source-language helper contract (values stored vs exported).
 *
 * Read-only against the pilot dataset where possible; fixtures that are
 * created are deleted at the end.
 *
 * Usage (from the project root):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-stage32-bilingual.php
 */


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed = 0;
$failed = 0;


test_prerequisite_hint( 'polylang' );
test_require( function_exists( 'pll_home_url' ), 'polylang', 'test prerequisite is available: function_exists( pll_home_url )', 'activate the Polylang plugin' );
// ---------------------------------------------------------------------------
$en_home = pll_home_url( 'en' );
$pt_home = pll_home_url( 'pt' );
assert_true( untrailingslashit( $en_home ) === untrailingslashit( home_url( '/en/' ) ), "pll_home_url('en') is the bare /en/ URL (got {$en_home})" );
assert_true( untrailingslashit( $pt_home ) === untrailingslashit( home_url( '/' ) ), "pll_home_url('pt') stays / (got {$pt_home})" );

$pt_front = (int) get_option( 'page_on_front' );
$en_front = (int) pll_get_post( $pt_front, 'en' );
assert_true( $pt_front > 0 && 'page' === get_post_field( 'post_type', $pt_front ), 'site front page is a static page (PT)' );
assert_true( $en_front > 0 && 'page' === get_post_field( 'post_type', $en_front ), 'the English homepage is the linked translation of the PT front page' );
assert_true( 'en' === pll_get_post_language( $en_front, 'slug' ), 'the English homepage record is assigned to en' );
assert_true( (int) pll_get_post( $en_front, 'pt' ) === $pt_front, 'the front-page pair is linked from both sides' );

// ---------------------------------------------------------------------------
$expected_allowlist = array( 'dublin', 'cork', 'galway', 'limerick', 'kildare', 'meath', 'wicklow', 'waterford', 'laois', 'irlanda', 'blog' );
$allowlist          = conexao_b2_page_allowlist();
sort( $expected_allowlist );
sort( $allowlist );
assert_true( $expected_allowlist === $allowlist, 'allowlist is exactly the approved county pages + irlanda + the blog posts page' );

foreach ( array( 'irlanda', 'dublin', 'cork' ) as $slug ) {
	$page = get_page_by_path( $slug );
	assert_true( $page && conexao_is_b2_page( (int) $page->ID ), "allowlisted page is B2-eligible: {$slug}" );
}
foreach ( array( 'moradia', 'sobre-nos', 'inicio', 'newsletter', 'empregos', 'politica-de-privacidade' ) as $slug ) {
	$page = get_page_by_path( $slug );
	assert_true( $page && ! conexao_is_b2_page( (int) $page->ID ), "unlisted page stays out of B2: {$slug}" );
}

// B2 decision matrix (simulate an EN request context).
$en_language = PLL()->model->get_language( 'en' );
PLL()->curlang = $en_language;

$irlanda = get_page_by_path( 'irlanda' );
assert_true( conexao_should_render_b2_fallback( (int) $irlanda->ID ), 'allowlisted untranslated page renders B2 in the EN context' );

$moradia = get_page_by_path( 'moradia' );
assert_true( ! conexao_should_render_b2_fallback( (int) $moradia->ID ), 'unlisted untranslated page does NOT render B2 (B1 302 policy)' );

$sobre = get_page_by_path( 'sobre-nos' );
assert_true( ! conexao_should_render_b2_fallback( (int) $sobre->ID ), 'a translated page never renders B2 (the translation serves)' );

// A hidden-status event never renders B2 (status gate keeps precedence).
$hidden = get_posts( array( 'post_type' => 'event', 'post_status' => 'any', 'name' => 'evento-rejeitado-spam', 'fields' => 'ids', 'lang' => '' ) );
if ( $hidden ) {
	assert_true( ! conexao_should_render_b2_fallback( (int) $hidden[0] ), 'rejected event never renders B2 (status gate precedence)' );
}

PLL()->curlang = PLL()->model->get_language( 'pt' );

// ---------------------------------------------------------------------------
$replaced_leisure = conexao_b2_translation_replaced_pt_ids( array( 'leisure' ) );
$phoenix_pt       = get_posts( array( 'post_type' => 'leisure', 'name' => 'phoenix-park', 'post_status' => 'any', 'fields' => 'ids', 'lang' => '' ) );
assert_true( in_array( (int) $phoenix_pt[0], array_map( 'intval', $replaced_leisure ), true ), 'the PT phoenix-park master is in the replacement set (its EN translation exists)' );

$replaced_sponsor = conexao_b2_translation_replaced_pt_ids( array( 'sponsor' ) );
$cafe             = get_posts( array( 'post_type' => 'sponsor', 'name' => 'cafe-brasil-dublin', 'post_status' => 'any', 'fields' => 'ids', 'lang' => '' ) );
assert_true( ! in_array( (int) $cafe[0], array_map( 'intval', $replaced_sponsor ), true ), 'an untranslated sponsor is NOT in the replacement set (stays a B2 fallback record)' );

$replaced_event = conexao_b2_translation_replaced_pt_ids( array( 'event' ) );
$festa_pt       = get_posts( array( 'post_type' => 'event', 'name' => 'festa-junina-dublin-2026', 'post_status' => 'any', 'fields' => 'ids', 'lang' => '' ) );
assert_true( in_array( (int) $festa_pt[0], array_map( 'intval', $replaced_event ), true ), 'the PT event master is replaced by its EN translation' );

// ---------------------------------------------------------------------------
$translated = PLL()->model->get_translated_taxonomies();
assert_true( in_array( 'conexao_category', $translated, true ) && in_array( 'conexao_tag', $translated, true ), 'categories/tags are Polylang-translated' );
assert_true( ! in_array( 'conexao_county', $translated, true ) && ! in_array( 'conexao_town', $translated, true ), 'counties/towns are shared (untranslated)' );

$natureza = get_term_by( 'slug', 'natureza', 'conexao_category' );
$nature   = $natureza ? (int) pll_get_term( (int) $natureza->term_id, 'en' ) : 0;
assert_true( $nature > 0, 'the Natureza term has a linked EN translation' );
assert_true( 'nature' === get_term_field( 'slug', $nature ), "the EN term carries a natural English slug (got 'nature')" );
assert_true( 'Nature' === get_term_field( 'name', $nature ), 'the EN term carries a human-authored English name' );
assert_true( 'en' === pll_get_term_language( $nature, 'slug' ), 'the EN term is assigned to en' );
assert_true( (int) pll_get_term( $nature, 'pt' ) === (int) $natureza->term_id, 'the category pair is linked from both sides' );

$dublin_county = get_term_by( 'slug', 'dublin', 'conexao_county' );
assert_true( 0 === (int) pll_get_term( (int) $dublin_county->term_id, 'en' ), 'the county term has no EN translation (single shared identity)' );

// EN records carry EN category terms + the SAME shared county/town terms.
$en_guide = get_posts( array( 'post_type' => 'guide', 'name' => 'how-to-get-a-pps-number', 'post_status' => 'any', 'fields' => 'ids', 'lang' => '' ) );
$en_guide_cats = wp_get_object_terms( (int) $en_guide[0], 'conexao_category', array( 'fields' => 'slugs' ) );
sort( $en_guide_cats );
assert_true( array( 'documents', 'work' ) === $en_guide_cats, 'the EN guide carries its linked EN category terms (documents, work)' );

$en_event  = get_posts( array( 'post_type' => 'event', 'name' => 'festa-junina-dublin-2026-en', 'post_status' => 'any', 'fields' => 'ids', 'lang' => '' ) );
$pt_event  = get_posts( array( 'post_type' => 'event', 'name' => 'festa-junina-dublin-2026', 'post_status' => 'any', 'fields' => 'ids', 'lang' => '' ) );
$en_towns  = wp_get_object_terms( (int) $en_event[0], 'conexao_town', array( 'fields' => 'ids' ) );
$pt_towns  = wp_get_object_terms( (int) $pt_event[0], 'conexao_town', array( 'fields' => 'ids' ) );
assert_true( ! empty( $en_towns ) && ! empty( $pt_towns ) && (int) $en_towns[0] === (int) $pt_towns[0], 'the EN event and its PT master share the SAME town term' );

// ---------------------------------------------------------------------------
if ( class_exists( 'Conexao_Event_Source_Language' ) ) {
	assert_true( array( 'pt', 'en', 'other' ) === Conexao_Event_Source_Language::allowed(), 'stored values are exactly pt|en|other' );
	assert_true( 'unknown' === Conexao_Event_Source_Language::export_value( '' ), 'unclassified exports as unknown' );

	$irish = get_posts( array( 'post_type' => 'event', 'name' => 'irish-dance-workshop-dublin', 'post_status' => 'any', 'fields' => 'ids', 'lang' => '' ) );
	assert_true( 'en' === get_post_meta( (int) $irish[0], '_event_source_language', true ), 'the English-source event carries _event_source_language=en' );
	assert_true( 'en' === pll_get_post_language( (int) $irish[0], 'slug' ), 'the English-source event IS the English record (source-inherited)' );

	$festa_lang = get_post_meta( (int) $festa_pt[0], '_event_source_language', true );
	assert_true( 'pt' === $festa_lang, 'the Portuguese-source event carries _event_source_language=pt' );
	assert_true( 'pt' === pll_get_post_language( (int) $festa_pt[0], 'slug' ), 'the Portuguese-source event stays a Portuguese record' );
	assert_true( get_post_meta( (int) $en_event[0], '_event_source_language', true ) === $festa_lang, 'the EN translation copies the source-language meta verbatim' );
} else {
	assert_true( false, 'Conexao_Event_Source_Language is unavailable (runtime plugin inactive?)' );
}

// ---------------------------------------------------------------------------
global $wpdb;
$dupe_identity = (int) $wpdb->get_var(
	"SELECT COUNT( * ) FROM (
		SELECT p.ID FROM {$wpdb->posts} p
		JOIN {$wpdb->postmeta} s ON ( p.ID = s.post_id AND s.meta_key = '_event_source' )
		JOIN {$wpdb->postmeta} i ON ( p.ID = i.post_id AND i.meta_key = '_event_source_id' )
		WHERE p.post_type = 'event' AND s.meta_value = 'eventbrite' AND i.meta_value = 'pilot-eb-0001'
	) AS identities"
);
assert_true( 2 === $dupe_identity, 'the pilot identity exists exactly twice (PT master + linked EN translation)' );

$dupe_uuid = (int) $wpdb->get_var(
	"SELECT COUNT( * ) FROM (
		SELECT u.meta_value, COUNT( * ) AS c
		FROM {$wpdb->postmeta} u
		JOIN {$wpdb->posts} p ON p.ID = u.post_id
		WHERE u.meta_key = '_event_export_uuid' AND p.post_type = 'event'
		GROUP BY u.meta_value HAVING c > 1
	) AS d"
);
assert_true( 1 === $dupe_uuid, 'exactly one export UUID pair shares its value (master + translation), no other duplicates' );

$dupe_leisure_uuid = (int) $wpdb->get_var(
	"SELECT COUNT( * ) FROM (
		SELECT u.meta_value, COUNT( * ) AS c
		FROM {$wpdb->postmeta} u
		JOIN {$wpdb->posts} p ON p.ID = u.post_id
		WHERE u.meta_key = '_leisure_export_uuid' AND p.post_type = 'leisure'
		GROUP BY u.meta_value HAVING c > 1
	) AS d"
);
assert_true( 1 === $dupe_leisure_uuid, 'exactly one Lazer UUID pair shares its value (master + translation), no other duplicates' );

test_finish();
