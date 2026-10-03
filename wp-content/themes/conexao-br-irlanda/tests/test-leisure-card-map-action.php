<?php
/**
 * Tests for the Lazer card "Ver no mapa" map action (Phase 3D).
 *
 * Verifies the secondary map CTA added beside the existing primary CTA in
 * template-parts/leisure-card.php. Groups: A. valid map URL, B. no map URL,
 * C. external card, D. internal card, E. primary CTA unchanged, F. expected
 * Google Maps URL, G. image/card link intact, H. external-link attrs,
 * I. accessibility markup, J. dark-mode styling hooks.
 *
 * Creates temporary posts/terms and deletes them at the end; never modifies
 * existing leisure records.
 *
 * Usage:
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-leisure-card-map-action.php
 */


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed        = 0;
$failed        = 0;
$created_posts = array();
$created_terms = array();

function card_render( $post_id ) {
	global $post;
	$test_post = get_post( $post_id );
	if ( ! $test_post instanceof WP_Post ) {
		return '';
	}
	setup_postdata( $post = $test_post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	ob_start();
	include get_template_directory() . '/template-parts/leisure-card.php';
	$html = ob_get_clean();
	wp_reset_postdata();
	return $html;
}

function map_test_county( $name, $slug ) {
	global $created_terms;
	$existing = term_exists( $slug, 'conexao_county' );
	if ( $existing && ! is_wp_error( $existing ) ) {
		$term = get_term( (int) ( is_array( $existing ) ? $existing['term_id'] : $existing ), 'conexao_county' );
		return (int) $term->term_id;
	}
	$inserted = wp_insert_term( $name, 'conexao_county', array( 'slug' => $slug ) );
	if ( is_wp_error( $inserted ) ) {
		exit( 1 );
	}
	$created_terms[] = (int) $inserted['term_id'];
	return (int) $inserted['term_id'];
}

function map_test_create_leisure( $title, $county_term_id = 0, $external = true, $extra_meta = array() ) {
	global $created_posts;
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'leisure',
			'post_title'  => '[MAPTEST] ' . $title,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		exit( 1 );
	}
	if ( $county_term_id ) {
		wp_set_object_terms( $post_id, array( (int) $county_term_id ), 'conexao_county' );
	}
	if ( $external ) {
		update_post_meta( $post_id, '_leisure_official_website', 'https://example.com/' . sanitize_title( $title ) );
	}
	foreach ( $extra_meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	$created_posts[] = (int) $post_id;
	return (int) $post_id;
}

// ---------------------------------------------------------------------------
// Setup: a county term shared by the test records.
// ---------------------------------------------------------------------------
test_section( 'Setup' );
$county_id = map_test_county( 'Map Test County', 'map-test-county' );
assert_true( $county_id > 0, 'county term available for test records' );

// ---------------------------------------------------------------------------
// A. Card with a valid map URL renders "Ver no mapa"
// ---------------------------------------------------------------------------
test_section( 'A. Card with valid map URL renders "Ver no mapa"' );

$card_a = map_test_create_leisure( 'Forest Park', $county_id, true, array(
	'_leisure_town' => 'Tullamore',
) );
$html_a = card_render( $card_a );

assert_true( false !== strpos( $html_a, 'leisure-card-actions' ), 'actions container present' );
assert_true( false !== strpos( $html_a, 'leisure-card-cta--map' ), 'map CTA has the --map modifier class' );
assert_true( false !== strpos( $html_a, 'Ver no mapa' ), 'map CTA shows "Ver no mapa" label' );

// ---------------------------------------------------------------------------
// B. Card without a map URL omits the map action
// ---------------------------------------------------------------------------
test_section( 'B. Card without map URL omits the map action' );

$card_b = map_test_create_leisure( 'No Location Place', 0, true );
$html_b = card_render( $card_b );

assert_true( false === strpos( $html_b, 'leisure-card-cta--map' ), 'no map CTA rendered when no map URL' );
assert_true( false === strpos( $html_b, 'Ver no mapa' ), 'no "Ver no mapa" label when no map URL' );
assert_true( false !== strpos( $html_b, 'leisure-card-actions' ), 'actions container still present (primary CTA only)' );
assert_true( false === strpos( $html_b, 'href=""' ), 'no empty href link in the card' );

// ---------------------------------------------------------------------------
// C. External leisure card keeps primary CTA and adds map CTA
// ---------------------------------------------------------------------------
test_section( 'C. External leisure card (official website)' );

$card_c = map_test_create_leisure( 'External Castle', $county_id, true );
$html_c = card_render( $card_c );

assert_true( false !== strpos( $html_c, 'Ver site oficial' ), 'external card keeps "Ver site oficial" primary CTA' );
assert_true( false !== strpos( $html_c, 'rel="noopener"' ), 'external primary CTA keeps rel="noopener"' );
assert_true( false !== strpos( $html_c, 'leisure-card-cta--map' ), 'external card also renders the map CTA' );


// ---------------------------------------------------------------------------
// D. Internal leisure card WITH _leisure_internal_page flag → "Ver mais"
// ---------------------------------------------------------------------------
test_section( 'D. Internal leisure card (_leisure_internal_page flag → "Ver mais")' );

$card_d = map_test_create_leisure( 'Internal Museum', $county_id, true, array(
	'_leisure_internal_page' => '1',
) );
$html_d = card_render( $card_d );

assert_true( false !== strpos( $html_d, 'Ver mais' ), 'internal flagged card shows "Ver mais" primary CTA (not "Ver local")' );
assert_true( 0 === preg_match( '/>Ver local/', $html_d ), 'internal flagged card no longer shows "Ver local"' );
assert_true( false !== strpos( $html_d, 'leisure-card-cta--map' ), 'internal flagged card also renders the map CTA' );
assert_true( false !== strpos( $html_d, esc_url( get_permalink( $card_d ) ) ), 'internal flagged card links to internal page' );

// ---------------------------------------------------------------------------
// D2. Internal leisure card WITHOUT flag → no primary CTA
// ---------------------------------------------------------------------------
test_section( 'D2. Internal leisure card (no flag → no primary CTA)' );

$card_d2 = map_test_create_leisure( 'Internal No Flag', $county_id, false );
$html_d2 = card_render( $card_d2 );

assert_true( 0 === preg_match( '/>Ver local/', $html_d2 ), 'internal unflagged card no longer shows "Ver local"' );
assert_true( false === strpos( $html_d2, 'Ver mais' ), 'internal unflagged card does not show "Ver mais" (no useful page)' );
assert_true( false === strpos( $html_d2, 'Ver site oficial' ), 'internal unflagged card does not show "Ver site oficial"' );
assert_true( 0 === preg_match( '/class="leisure-card-cta"/', $html_d2 ), 'internal unflagged card renders no primary CTA (exact class check)' );
assert_true( false !== strpos( $html_d2, 'leisure-card-cta--map' ), 'internal unflagged card still renders the map CTA' );
assert_true( false === strpos( $html_d2, 'href="' . esc_url( get_permalink( $card_d2 ) ) . '"' ), 'internal unflagged card image/title are not linked' );
assert_true( false === strpos( $html_d2, 'href=""' ), 'internal unflagged card has no empty href links' );

// ---------------------------------------------------------------------------
// E. Existing primary CTA label/logic unchanged
// ---------------------------------------------------------------------------
test_section( 'E. Primary CTA label/logic unchanged' );

// External via Discover Ireland (no official website) => "Ver mais".
$card_e2 = map_test_create_leisure( 'External No Official', $county_id, false, array(
	'_leisure_discover_ireland' => 'https://www.discoverireland.ie/place',
) );
$html_e2 = card_render( $card_e2 );
assert_true( false !== strpos( $html_e2, 'Ver mais' ), 'external without official site still shows "Ver mais"' );
assert_true( (bool) conexao_leisure_external_url( $card_e2 ), 'e2 classifies as external (setup sanity)' );
assert_true( false !== strpos( $html_c, 'Ver site oficial' ), 'external with official site still shows "Ver site oficial"' );
assert_true( false !== strpos( $html_d, 'Ver mais' ), 'internal flagged card shows "Ver mais"' );

// ---------------------------------------------------------------------------
// F. Map CTA uses the expected Google Maps URL
// ---------------------------------------------------------------------------
test_section( 'F. Map CTA uses expected Google Maps URL' );

$expected_url = conexao_leisure_map_url( $card_a );
assert_true( (bool) preg_match( '#^https://www\.google\.com/maps/search/\?api=1&query=#', $expected_url ), 'helper returns a Google Maps search URL' );
assert_true( false !== strpos( $html_a, esc_url( $expected_url ) ), 'map CTA href matches the helper output' );

$card_f2 = map_test_create_leisure( 'Stored Map Url', $county_id, true, array(
	'_leisure_map_url' => 'https://www.google.com/maps/search/?api=1&query=Custom+Place',
) );
$html_f2 = card_render( $card_f2 );
assert_true( false !== strpos( $html_f2, 'query=Custom+Place' ), 'stored _leisure_map_url is used verbatim' );

// ---------------------------------------------------------------------------
// G. Map CTA does NOT replace the image/card link
// ---------------------------------------------------------------------------
test_section( 'G. Map CTA does not replace image/card link' );

$primary_url_c = conexao_leisure_external_url( $card_c );
assert_true( false !== strpos( $html_c, 'leisure-card-image' ), 'image wrapper link still present' );
assert_true( false !== strpos( $html_c, esc_url( $primary_url_c ) ), 'image/title link points to primary destination' );
assert_true( 1 === substr_count( $html_c, '<a class="leisure-card-image"' ), 'exactly one image link (not replaced by map)' );

// ---------------------------------------------------------------------------
// H. External-link attributes on the map CTA
// ---------------------------------------------------------------------------
test_section( 'H. Map CTA external-link attributes' );

assert_true( false !== strpos( $html_a, 'target="_blank"' ), 'map CTA opens in a new tab (target="_blank")' );

// ---------------------------------------------------------------------------
// I. Accessibility markup
// ---------------------------------------------------------------------------
test_section( 'I. Accessibility markup' );

assert_true( false !== strpos( $html_a, 'aria-label=' ), 'map CTA has a descriptive aria-label' );
assert_true( false !== strpos( $html_a, 'no mapa (abre em nova aba)' ), 'aria-label names the action and new-tab behavior' );

$map_cta_html = '';
if ( preg_match( '/leisure-card-cta--map.*?<\/a>/s', $html_a, $m ) ) {
	$map_cta_html = $m[0];
}
assert_true( '' !== $map_cta_html, 'map CTA section extracted for inspection' );
assert_true( false !== strpos( $map_cta_html, 'aria-hidden="true"' ), 'map icon SVG is decorative (aria-hidden)' );

$actions_block = '';
if ( preg_match( '/<div class="leisure-card-actions">(.*?)<\/div>/s', $html_a, $m ) ) {
	$actions_block = $m[1];
}
assert_true( 2 === substr_count( $actions_block, '<a ' ), 'two real links inside the actions container' );
assert_true( 2 === substr_count( $actions_block, '</a>' ), 'two properly closed links inside the actions container' );

// ---------------------------------------------------------------------------
// J. Dark-mode styling hooks exist
// ---------------------------------------------------------------------------
test_section( 'J. Dark-mode styling hooks' );

$dark_css = file_get_contents( get_template_directory() . '/assets/css/dark-mode.css' );
assert_true( false !== strpos( $dark_css, '.leisure-card-cta--map' ), 'dark-mode.css defines styles for the map CTA' );
assert_true( false !== strpos( $dark_css, '[data-theme="dark"] .leisure-card-cta--map' ), 'dark-mode map CTA rule uses the data-theme selector' );

$leisure_css = file_get_contents( get_template_directory() . '/assets/css/leisure.css' );
assert_true( false !== strpos( $leisure_css, '.leisure-card-actions' ), 'leisure.css defines the actions container' );
assert_true( false !== strpos( $leisure_css, '.leisure-card-cta--map' ), 'leisure.css defines the map CTA modifier' );

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
foreach ( $created_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
foreach ( $created_terms as $term_id ) {
	wp_delete_term( $term_id, 'conexao_county' );
}

$leftover = get_posts(
	array(
		'post_type'   => 'leisure',
		'post_status' => 'any',
		's'           => '[MAPTEST]',
		'numberposts' => 5,
		'fields'      => 'ids',
	)
);
assert_true( empty( $leftover ), 'no temporary test posts remain' );

test_finish();
