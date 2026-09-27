<?php
/**
 * Tests for the Stage 2 Polylang foundation.
 *
 * Verifies the multilingual infrastructure added by Stage 2 of the
 * English-support architecture (CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md)
 * without touching the Portuguese production data:
 *
 *  - Languages: pt (pt_BR, default) + en (en_US), URL mode = language prefix
 *    with the default language hidden.
 *  - `conexao_current_locale()` follows the active Polylang language (the
 *    Stage 1 extension point, not a second detection path).
 *  - Language-scoped cache keys + flush helpers (PT ≠ EN).
 *  - Language switcher data and its "never link a fake EN detail page" policy.
 *  - Shared taxonomy identity: ONE term per county/town/category — never
 *    Dublin-PT/Dublin-EN duplicates.
 *  - Event/Lazer identity meta is language-neutral (unchanged by Polylang).
 *  - SEO ownership stays with the theme.
 *
 * The script only reads data and creates nothing.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-polylang-foundation.php
 */

// --- Bootstrap WordPress. ---

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed = 0;
$failed = 0;

function t2_strict( $expected, $actual, $message ) {
	assert_true(
		$expected === $actual,
		$message . ( $expected === $actual ? '' : ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) )
	);
}


test_prerequisite_hint( 'polylang' );
test_require( function_exists( 'pll_languages_list' ), 'polylang', 'test prerequisite is available: function_exists( pll_languages_list )', 'activate the Polylang plugin' );
$slugs = pll_languages_list( array( 'fields' => 'slug' ) );
sort( $slugs );
t2_strict( array( 'en', 'pt' ), $slugs, 'exactly two languages exist: pt + en' );
t2_strict( 'pt', pll_default_language( 'slug' ), 'Portuguese is the default language' );
t2_strict( 'pt_BR', conexao_language_locale( 'pt' ), 'pt locale is pt_BR' );
t2_strict( 'en_US', conexao_language_locale( 'en' ), 'en locale is en_US' );
t2_strict( 1, (int) PLL()->options['force_lang'], 'URL mode = language in a directory' );
assert_true( (bool) PLL()->options['hide_default'], 'default language has no URL prefix (PT URLs unchanged)' );

t2_strict( 'pt', conexao_current_language_slug(), 'current language slug resolves (CLI = default language)' );
t2_strict( 'pt_BR', conexao_current_locale(), 'conexao_current_locale() = pt_BR in the PT context' );
t2_strict( 'pt_BR', conexao_og_locale(), 'og:locale = pt_BR in the PT context' );
t2_strict( 'pt-BR', str_replace( '_', '-', conexao_current_locale() ), 'html lang renders pt-BR' );
t2_strict( 'pt', conexao_requested_language_slug(), 'requested-language capture resolves to a real slug' );

$post_types = PLL()->model->get_translated_post_types();
foreach ( array( 'post', 'page', 'guide', 'event', 'leisure', 'sponsor', 'job', 'course_provider' ) as $type ) {
	assert_true( in_array( $type, $post_types, true ), "translated post type registered: {$type}" );
}
foreach ( array( 'recruitment_agency', 'permit_employer' ) as $type ) {
	assert_true( ! in_array( $type, $post_types, true ), "admin-only post type excluded: {$type}" );
}
$taxonomies = PLL()->model->get_translated_taxonomies();
foreach ( array( 'conexao_category', 'conexao_tag', 'category', 'post_tag' ) as $tax ) {
	assert_true( in_array( $tax, $taxonomies, true ), "translated taxonomy registered: {$tax}" );
}
// STAGE 3.2 policy correction — proper-noun location taxonomies are
// deliberately NOT translated: one shared term per county/town, no
// per-language duplicates, identical filter behaviour in both languages
// (decision §1: "Counties/towns are shared — no per-language duplicate
// terms"; see inc/i18n/guard.php conexao_polylang_translated_taxonomies()).
foreach ( array( 'conexao_county', 'conexao_town' ) as $tax ) {
	assert_true( ! in_array( $tax, $taxonomies, true ), "location taxonomy deliberately SHARED (not translated): {$tax}" );
}

t2_strict( '_pt', conexao_language_suffix(), 'cache suffix follows the current language' );
t2_strict( 'conexao_home_events_pt', conexao_lang_cache_key( 'conexao_home_events' ), 'cache keys are language-scoped' );

set_transient( 'conexao_stage2_cache_probe_pt', 'pt-value', 60 );
set_transient( 'conexao_stage2_cache_probe_en', 'en-value', 60 );
conexao_flush_language_cache( 'conexao_stage2_cache_probe' );
t2_strict( false, get_transient( 'conexao_stage2_cache_probe_pt' ), 'flush removes the PT variant' );
t2_strict( false, get_transient( 'conexao_stage2_cache_probe_en' ), 'flush removes the EN variant' );

$rows = conexao_language_switcher_data();
t2_strict( 2, count( $rows ), 'switcher exposes one entry per language' );
$labels = array_column( $rows, 'label' );
sort( $labels );
t2_strict( array( 'EN', 'PT' ), $labels, 'switcher uses concise PT / EN labels' );
$switcher_urls = '';
foreach ( $rows as $row ) {
	$switcher_urls .= $row['url'];
	assert_true( '' !== $row['url'], "switcher entry '{$row['label']}' always has a URL" );
}
assert_true( false !== strpos( $switcher_urls, '/en/' ), 'switcher links into the /en/ namespace' );

foreach ( array( 'conexao_county', 'conexao_town', 'conexao_category' ) as $tax ) {
	$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
	assert_true( ! is_wp_error( $terms ), "taxonomy {$tax} is queryable" );

	$by_slug = array();
	foreach ( $terms as $term ) {
		$by_slug[ $term->slug ][] = $term->term_id;
	}
	$duplicated = array_filter(
		$by_slug,
		static function ( $ids ) {
			return count( $ids ) > 1;
		}
	);
	t2_strict( array(), $duplicated, "no duplicate term slugs in {$tax}" );

	$suffixed = array_filter(
		array_keys( $by_slug ),
		static function ( $slug ) {
			return (bool) preg_match( '/-(pt|en|ptbr|enus)$/i', (string) $slug );
		}
	);
	t2_strict( array(), $suffixed, "{$tax} has no per-language suffixed terms" );
}

// STAGE 3.2 — translated taxonomies carry exactly one language per term;
// SHARED location taxonomies carry none (a language on a shared term would
// mean Polylang still owns it).
$unassigned_translated = 0;
$translated_terms      = get_terms(
	array(
		'taxonomy'   => array( 'conexao_category', 'category' ),
		'hide_empty' => false,
		'lang'       => '',
	)
);
foreach ( $translated_terms as $term ) {
	if ( '' === (string) pll_get_term_language( $term->term_id, 'slug' ) ) {
		$unassigned_translated++;
	}
}
t2_strict( 0, $unassigned_translated, 'every translated-taxonomy term has exactly one language assignment' );

$assigned_shared = 0;
$location_terms  = get_terms(
	array(
		'taxonomy'   => array( 'conexao_county', 'conexao_town' ),
		'hide_empty' => false,
		'lang'       => '',
	)
);
foreach ( $location_terms as $term ) {
	if ( '' !== (string) pll_get_term_language( $term->term_id, 'slug' ) ) {
		$assigned_shared++;
	}
}
t2_strict( 0, $assigned_shared, 'no shared county/town term carries a language assignment' );

$event = get_posts( array( 'post_type' => 'event', 'posts_per_page' => 1, 'post_status' => 'any', 'fields' => 'ids' ) );
if ( $event ) {
	$event_id = (int) $event[0];
	$lang     = pll_get_post_language( $event_id, 'slug' );
	assert_true( in_array( $lang, array( 'pt', 'en' ), true ), 'event has exactly one language assignment' );
	// STAGE 3.2 — translations may legitimately exist now. The invariant is
	// that a translation group only ever contains true identity siblings
	// (same _event_source + _event_source_id), in distinct languages — never
	// an invented second identity.
	$translations = pll_get_post_translations( $event_id );
	assert_true( count( $translations ) >= 1, 'event is part of a translation group' );
	assert_true( count( $translations ) === count( array_unique( $translations ) ), 'no duplicated record in the translation group' );
	$group_langs = array_keys( $translations );
	sort( $group_langs );
	assert_true( $group_langs === array_values( array_unique( $group_langs ) ), 'each translation sits in its own language' );
	foreach ( $translations as $tr_id ) {
		assert_true(
			get_post_meta( $tr_id, '_event_source', true ) === get_post_meta( $event_id, '_event_source', true )
			&& get_post_meta( $tr_id, '_event_source_id', true ) === get_post_meta( $event_id, '_event_source_id', true ),
			'translation #' . $tr_id . ' shares the identity meta of its source (never a second identity)'
		);
	}

	$identity_before = array(
		'_event_source'      => get_post_meta( $event_id, '_event_source', true ),
		'_event_source_id'   => get_post_meta( $event_id, '_event_source_id', true ),
		'_event_export_uuid' => get_post_meta( $event_id, '_event_export_uuid', true ),
	);
	pll_get_post_translations( $event_id );
	t2_strict( $identity_before['_event_source'], get_post_meta( $event_id, '_event_source', true ), 'event source meta unchanged by language reads' );
	t2_strict( $identity_before['_event_source_id'], get_post_meta( $event_id, '_event_source_id', true ), 'event source id meta unchanged by language reads' );
	t2_strict( $identity_before['_event_export_uuid'], get_post_meta( $event_id, '_event_export_uuid', true ), 'event export uuid unchanged by language reads' );
}

$leisure = get_posts( array( 'post_type' => 'leisure', 'posts_per_page' => 1, 'post_status' => 'any', 'fields' => 'ids' ) );
if ( $leisure ) {
	$leisure_id = (int) $leisure[0];
	$uuid       = get_post_meta( $leisure_id, '_leisure_export_uuid', true );
	assert_true( in_array( pll_get_post_language( $leisure_id, 'slug' ), array( 'pt', 'en' ), true ), 'Lazer record has exactly one language assignment' );
	t2_strict( $uuid, get_post_meta( $leisure_id, '_leisure_export_uuid', true ), 'Lazer export uuid untouched by the language layer' );
}

assert_true( function_exists( 'conexao_seo_canonical' ), 'theme canonical emitter present' );
assert_true( false !== has_filter( 'conexao_seo_canonical_url', 'conexao_polylang_canonical_url' ), 'canonical filter wired through the theme owner' );
assert_true( false !== has_action( 'wp_head', 'conexao_seo_hreflang' ), 'theme emits hreflang' );
assert_true( false !== has_filter( 'pll_check_canonical_url', 'conexao_polylang_language_redirect_is_temporary' ), 'language-mismatch redirects are converted to 302' );

test_finish();
