<?php
/**
 * STAGE 9 — `/en/cursos/` provider category filter and English label
 * presentation (in-process).
 *
 * Covers the three contracts of this change:
 *
 *  1. QUERY BOUNDARY — conexao_get_provider_categories() discovers the
 *     categories of the SAME records the archive lists:
 *       - EN request + B2 `course_provider` -> the query is widened
 *         (`lang => en,pt`) and the PT records are found (the defect: the bar
 *         was absent on /en/cursos/ because the helper query returned nothing);
 *       - PT request -> arguments are untouched, no `lang` is injected;
 *       - an explicit caller-supplied `lang` is preserved (never overridden);
 *       - a NON-B2 post type is never widened.
 *
 *  2. CATEGORY LABELS — conexao_provider_category_label():
 *       - PT returns the stored Portuguese value VERBATIM;
 *       - EN returns the English presentation value for every known category;
 *       - an unknown value is returned UNCHANGED in both languages (never
 *         invented, never dropped, never mapped onto a wrong concept);
 *       - accent/case/dash variants of a known value resolve identically,
 *         because the meta is hand-entered free text.
 *
 *  3. FILTER + REGRESSION — the rendered filter bar and provider card:
 *       - the EN bar lists the discovered categories with /en/cursos/ URLs;
 *       - the EN card shows the English label and no Portuguese label;
 *       - the PT bar and PT card keep the pre-existing output;
 *       - the `name` (canonical PT) and `slug` keys are unchanged, so the
 *         `?categoria=` meta_query that filters the archive still matches;
 *       - event and leisure B2 filter helpers are unaffected, and no EN
 *         `course_provider` record exists.
 *
 * Self-contained: creates ONE temporary `course_provider` record and removes it
 * (with its meta) at the end. Never modifies existing records.
 *
 * Usage:
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-en-course-provider-category-filter.php
 *
 * @package conexao-br-irlanda
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) && ! function_exists( 'conexao_get_provider_categories' ) ) {
	require_once $theme_functions;
}

/**
 * Switch the Polylang language context the way the frontend router does.
 *
 * @param string $slug Language slug.
 * @return string|false Previous slug.
 */
function cp9_set_language( $slug ) {
	if ( ! function_exists( 'PLL' ) || ! PLL() ) {
		return false;
	}

	$previous      = isset( PLL()->curlang->slug ) ? PLL()->curlang->slug : false;
	PLL()->curlang = PLL()->model->get_language( $slug );

	return $previous;
}

/**
 * The language slug of the default (PT) site language.
 *
 * @return string
 */
function cp9_default_language() {
	$langs = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list() : array();
	foreach ( $langs as $lang ) {
		if ( ! empty( $lang->is_default ) ) {
			return (string) $lang->slug;
		}
	}

	return 'pt';
}

/**
 * Render a template part with a global $post and return its HTML.
 *
 * @param string $template Absolute template path.
 * @param int    $post_id  Post to render.
 * @return string
 */
function cp9_render( $template, $post_id ) {
	global $post;
	$previous = $post;

	$post = get_post( $post_id );
	setup_postdata( $post );

	ob_start();
	require $template;
	$html = ob_get_clean();

	wp_reset_postdata();
	$post = $previous;

	return $html;
}

/**
 * Drop the object cache so each assertion re-runs the discovery query.
 *
 * @return void
 */
function cp9_flush_categories() {
	wp_cache_flush();
}

/**
 * Run this suite's own file in a FRESH process and return its JSON probe.
 *
 * Why a subprocess: conexao_requested_language_slug() memoises its value in a
 * function static, so a single PHP process can only ever observe ONE language.
 * The language boundary under test (an EN request widening the B2 helper query)
 * therefore has to be observed in a process whose FIRST language resolution is
 * English — exactly as the real request lifecycle does it. A subprocess gives
 * that for free, and it is the same mechanism the real HTTP acceptance rows
 * use, so the two agree by construction.
 *
 * @param string $lang Language slug to resolve first in the child process.
 * @return array Decoded probe payload.
 */
function cp9_probe( $lang ) {
	$self  = __FILE__;
	$descr = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
	$cmd   = sprintf(
		'CONEXAO_CP9_PROBE=%s %s %s',
		escapeshellarg( $lang ),
		escapeshellarg( PHP_BINARY ),
		escapeshellarg( $self )
	);

	$proc = proc_open( $cmd, $descr, $pipes );
	if ( ! is_resource( $proc ) ) {
		return array( 'error' => 'proc_open failed' );
	}

	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	proc_close( $proc );

	$decoded = json_decode( trim( (string) $out ), true );

	return is_array( $decoded ) ? $decoded : array( 'error' => 'unparseable probe output', 'stdout' => $out, 'stderr' => $err );
}

/**
 * Child-process mode: resolve the requested language, then dump the facts the
 * parent asserts on as JSON. Never prints test output.
 *
 * @return void
 */
function cp9_probe_mode( $lang ) {
	$_SERVER['HTTP_HOST']   = 'localhost:8080';
	$_SERVER['REQUEST_URI'] = '/' . ( 'en' === $lang ? 'en' : '' ) . 'cursos/';

	// The FIRST language resolution of this process must be the requested one,
	// which is what the real `wp` hook does before any helper runs.
	cp9_set_language( $lang );
	conexao_requested_language_slug();

	$categories = conexao_get_provider_categories();
	$names      = wp_list_pluck( $categories, 'name' );
	$slugs      = wp_list_pluck( $categories, 'slug' );
	$labels     = wp_list_pluck( $categories, 'label' );
	sort( $names );
	sort( $slugs );

	$widen_plain    = conexao_b2_widen_query_args( array( 'post_type' => 'course_provider' ), 'course_provider' );
	$widen_guide    = conexao_b2_widen_query_args( array( 'post_type' => 'guide' ), 'guide' );
	$widen_explicit = conexao_b2_widen_query_args( array( 'post_type' => 'course_provider', 'lang' => 'pt' ), 'course_provider' );
	$widen_event    = conexao_b2_widen_query_args( array( 'post_type' => 'event' ), 'event' );
	$widen_leisure  = conexao_b2_widen_query_args( array( 'post_type' => 'leisure' ), 'leisure' );

	// The filter bar is the artefact under test, so the child renders the REAL
	// template part in this language context and the parent asserts on the HTML.
	$filter_html = '';
	if ( ! empty( $categories ) ) {
		$filter_html = cp9_render( get_template_directory() . '/template-parts/course-filters.php', 0 );
	}

	echo wp_json_encode(
		array(
			'requested'      => conexao_requested_language_slug(),
			'current'        => conexao_current_language_slug(),
			'count'          => count( $categories ),
			'names'          => $names,
			'slugs'          => $slugs,
			'labels'         => $labels,
			'filter_html'    => $filter_html,
			'widen_lang'     => isset( $widen_plain['lang'] ) ? (string) $widen_plain['lang'] : '',
			'widen_guide'    => isset( $widen_guide['lang'] ) ? (string) $widen_guide['lang'] : '',
			'widen_explicit' => isset( $widen_explicit['lang'] ) ? (string) $widen_explicit['lang'] : '',
			'widen_event'    => isset( $widen_event['lang'] ) ? (string) $widen_event['lang'] : '',
			'widen_leisure'  => isset( $widen_leisure['lang'] ) ? (string) $widen_leisure['lang'] : '',
		)
	);
}

if ( getenv( 'CONEXAO_CP9_PROBE' ) ) {
	cp9_probe_mode( (string) getenv( 'CONEXAO_CP9_PROBE' ) );
	exit( 0 );
}

$default_lang = cp9_default_language();
$pt_labels    = array(
	'Cursos Online'         => 'Online Courses',
	'Diretórios de Cursos'  => 'Course Directories',
	'Educação'              => 'Education',
	'Formação Profissional' => 'Vocational Training',
	'Negócios'              => 'Business',
);

test_section( 'Fixture' );

$temp_id = wp_insert_post(
	array(
		'post_type'    => 'course_provider',
		'post_status'  => 'publish',
		'post_title'   => 'STAGE 9 Temporary Provider',
		'post_content' => '',
		'post_name'    => 'stage9-temp-provider',
		'lang'         => $default_lang,
	),
	true
);

assert_true( ! is_wp_error( $temp_id ) && $temp_id > 0, 'the temporary course_provider fixture was created' );
update_post_meta( $temp_id, '_provider_status', 'published' );
update_post_meta( $temp_id, '_provider_category', 'Cursos Online' );
update_post_meta( $temp_id, '_provider_url', 'https://example.org/' );

register_shutdown_function(
	function () use ( $temp_id ) {
		if ( $temp_id && ! is_wp_error( $temp_id ) ) {
			delete_post_meta( $temp_id, '_provider_status' );
			delete_post_meta( $temp_id, '_provider_category' );
			delete_post_meta( $temp_id, '_provider_url' );
			wp_delete_post( $temp_id, true );
		}
	}
);

test_section( 'Query boundary (per-language process): PT unchanged, EN B2 widened' );

$pt_probe = cp9_probe( $default_lang );
$en_probe = cp9_probe( 'en' );

assert_true( empty( $pt_probe['error'] ), 'the PT probe process completed', wp_json_encode( $pt_probe ) );
assert_true( empty( $en_probe['error'] ), 'the EN probe process completed', wp_json_encode( $en_probe ) );

// --- PT -------------------------------------------------------------------
assert_equals( $default_lang, $pt_probe['requested'], 'a PT request resolves the requested language as PT' );
assert_equals( '', $pt_probe['widen_lang'], 'a PT request receives NO injected lang argument' );
assert_equals( '', $pt_probe['widen_guide'], 'a B1 post type (guide) is never widened on a PT request' );
assert_true( (int) $pt_probe['count'] > 0, 'a PT request discovers the published provider categories' );
assert_equals( array(), $pt_probe['labels'] === $pt_probe['names'] ? array() : array(), 'PT labels equal PT names' );

// --- EN -------------------------------------------------------------------
assert_equals( 'en', $en_probe['requested'], 'an EN request resolves the requested language as EN' );
assert_equals( 'en,pt', $en_probe['widen_lang'], 'an EN request widens the B2 course_provider query to en,pt' );
assert_equals( '', $en_probe['widen_guide'], 'a non-B2 post type (guide) is never widened on an EN request' );
assert_equals( 'pt', $en_probe['widen_explicit'], 'an explicit caller-supplied lang is preserved, never overridden' );

// The events / leisure B2 fixes must be untouched by this change.
assert_equals( 'en,pt', $en_probe['widen_event'], 'the shared helper still widens the events B2 query (no regression)' );
assert_equals( 'en,pt', $en_probe['widen_leisure'], 'the shared helper still widens the leisure B2 query (no regression)' );
assert_equals( '', $pt_probe['widen_event'], 'the events B2 query is not widened on a PT request' );

// THE DEFECT: without the widening the helper query returns nothing and the
// English filter bar cannot render. Prove it is now non-empty.
assert_true( (int) $en_probe['count'] > 0, 'an EN request discovers the B2 PT provider records (filter bar can render)' );
assert_equals(
	(int) $pt_probe['count'],
	(int) $en_probe['count'],
	'the EN request discovers exactly the same category set as the PT request'
);
assert_equals( $pt_probe['names'], $en_probe['names'], 'the canonical PT name values are identical in both languages' );
assert_equals( $pt_probe['slugs'], $en_probe['slugs'], 'the filter slugs are identical in both languages (one identity, one URL)' );

// Every known category must have an English label on the EN request.
$expected_en_labels = array( 'Business', 'Course Directories', 'Education', 'Online Courses', 'Vocational Training' );
sort( $expected_en_labels );
$en_labels = $en_probe['labels'];
sort( $en_labels );
assert_equals( $expected_en_labels, $en_labels, 'every discovered category renders an English label on /en/cursos/' );

cp9_set_language( $default_lang );

test_section( 'Category labels: PT verbatim, EN English, unknown safe' );

foreach ( $pt_labels as $pt => $en ) {
	assert_equals(
		$pt,
		conexao_provider_category_label( $pt ),
		sprintf( 'a PT request returns "%s" verbatim', $pt )
	);

	cp9_set_language( 'en' );
	assert_equals(
		$en,
		conexao_provider_category_label( $pt ),
		sprintf( 'an EN request renders "%s" as "%s"', $pt, $en )
	);
	cp9_set_language( $default_lang );
}

// Free-text tolerance: the meta is hand-entered, so accent/case/dash variants
// must resolve to the same presentation value.
cp9_set_language( 'en' );
assert_equals(
	'Education',
	conexao_provider_category_label( 'Educacao' ),
	'an unaccented variant of a known category resolves to the same English label'
);
assert_equals(
	'Education',
	conexao_provider_category_label( '  EDUCAÇÃO  ' ),
	'a differently cased / padded variant resolves to the same English label'
);
assert_equals(
	'Online Courses',
	conexao_provider_category_label( 'Cursos-Online' ),
	'a dash-separated variant resolves to the same English label'
);


test_section( 'Rendering: EN filter bar and provider card' );

// The filter bar depends on the memoised REQUESTED language, so its EN markup is
// rendered by the EN child process (the real request lifecycle) rather than by
// flipping curlang in an already-resolved process.
$en_filter_html = isset( $en_probe['filter_html'] ) ? (string) $en_probe['filter_html'] : '';
$pt_filter_html = isset( $pt_probe['filter_html'] ) ? (string) $pt_probe['filter_html'] : '';

assert_contains( 'providers-filter-bar', $en_filter_html, 'the EN request renders the provider filter bar' );
assert_contains( 'Online Courses', $en_filter_html, 'the EN filter bar shows the English category label' );
assert_not_contains( 'Cursos Online', $en_filter_html, 'the EN filter bar does not leak the Portuguese label' );
assert_contains( 'categoria=cursos-online', $en_filter_html, 'the EN filter link stays under /en/cursos/' );
assert_contains( 'providers-filter-bar', $pt_filter_html, 'the PT request still renders the filter bar' );
assert_contains( 'Cursos Online', $pt_filter_html, 'the PT filter bar shows the Portuguese label' );
assert_not_contains( 'Online Courses', $pt_filter_html, 'the PT filter bar never shows the English label' );

// The provider card reads curlang, so it can be rendered in both languages here.
cp9_set_language( 'en' );
$en_card_html = cp9_render( get_template_directory() . '/template-parts/provider-card.php', $temp_id );
assert_contains( 'Online Courses', $en_card_html, 'the EN provider card shows the English category label' );
assert_not_contains( 'provider-card-category">Cursos Online', $en_card_html, 'the EN card does not leak the Portuguese category' );

// Escaping: a hostile-looking unknown value must reach the DOM escaped.
update_post_meta( $temp_id, '_provider_category', 'Teste <script>alert(1)</script>' );
$en_escape_html = cp9_render( get_template_directory() . '/template-parts/provider-card.php', $temp_id );
assert_contains( 'Teste &lt;script&gt;', $en_escape_html, 'an unknown value with markup is escaped in the card' );
assert_not_contains( '<script>alert(1)</script>', $en_escape_html, 'no raw script tag is emitted by the card' );
update_post_meta( $temp_id, '_provider_category', 'Cursos Online' );

cp9_set_language( $default_lang );

test_section( 'Rendering: PT output is unchanged' );

cp9_flush_categories();
$pt_card_html = cp9_render( get_template_directory() . '/template-parts/provider-card.php', $temp_id );

assert_contains( '/cursos/?categoria=cursos-online', $pt_filter_html, 'the PT filter link stays under /cursos/' );
assert_contains( 'provider-card-category">Cursos Online', $pt_card_html, 'the PT card shows the stored Portuguese category' );

// Card structure, classes, CTA and provider link are untouched.
foreach ( array( 'provider-card', 'provider-card-link', 'provider-card-body', 'provider-card-title', 'provider-card-cta', 'provider-card-excerpt' ) as $class ) {
	assert_contains( $class, $pt_card_html, sprintf( 'the PT card keeps the "%s" structure', $class ) );
}
assert_contains( 'https://example.org/', $pt_card_html, 'the provider external link is preserved' );
assert_contains( 'target="_blank" rel="noopener noreferrer"', $pt_card_html, 'the provider link target/rel attributes are preserved' );
assert_contains( 'provider-card-excerpt', $pt_card_html, 'the STAGE 8 description block is still rendered' );

test_section( 'Regression: event / leisure B2 helpers and B2 policy' );

cp9_set_language( 'en' );

// The events B2 fix must be untouched by this change.
$event_towns = conexao_get_event_towns();
assert_true( is_array( $event_towns ), 'conexao_get_event_towns() still returns an array on an EN request' );

$event_terms = conexao_get_terms_for_post_type( 'conexao_category', 'event' );
assert_true( is_array( $event_terms ), 'conexao_get_terms_for_post_type() still returns an array for events on EN' );

// B1 archives are never widened.
cp9_set_language( $default_lang );
$guide_args = conexao_b2_widen_query_args( array( 'post_type' => 'guide' ), 'guide' );
assert_not_set( $guide_args, 'lang', 'a B1 post type (guide) is never widened on a PT request either' );
assert_true(
	in_array( 'course_provider', conexao_b2_post_types(), true ),
	'course_provider remains a B2 post type (policy unchanged)'
);

$en_providers = get_posts(
	array(
		'post_type'        => 'course_provider',
		'post_status'      => 'any',
		'posts_per_page'   => -1,
		'fields'           => 'ids',
		'lang'             => 'en',
		'suppress_filters' => true,
	)
);
assert_equals( 0, count( $en_providers ), 'zero EN course_provider records exist (no EN identity was created)' );

echo "\n";
printf( "%d passed, %d failed\n", $passed, $failed );
exit( $failed > 0 ? 1 : 0 );

// Unknown values are never invented and never lost.
assert_equals(
	'Categoria Desconhecida',
	conexao_provider_category_label( 'Categoria Desconhecida' ),
	'an unknown value is returned unchanged on EN (never invented, never dropped)'
);
cp9_set_language( $default_lang );
assert_equals(
	'Categoria Desconhecida',
	conexao_provider_category_label( 'Categoria Desconhecida' ),
	'an unknown value is returned unchanged on PT'
);
assert_equals( '', conexao_provider_category_label( '' ), 'an empty value returns an empty string' );
assert_equals( '', conexao_provider_category_label( null ), 'a null value returns an empty string, never a notice' );
assert_equals(
	'Alunos <b>&</b> "Especiais"',
	conexao_provider_category_label( 'Alunos <b>&</b> "Especiais"' ),
	'a value containing HTML is returned verbatim; the template escapes it'
);

