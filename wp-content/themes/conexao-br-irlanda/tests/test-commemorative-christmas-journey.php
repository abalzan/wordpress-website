<?php
/**
 * Christmas signature-experience suite — "One Christmas, Two Skies".
 *
 * Proves the CONTRACT of the signature experience rather than its beauty:
 *
 *   - the package identity is the shared star, and the artwork the templates point at
 *     really exists, is local and is small enough to serve;
 *   - the two-sky journey is COMPLETE WITHOUT JAVASCRIPT: both perspectives are in the
 *     DOM, equally structured, and the control is `hidden` until the script upgrades it;
 *   - the server never emits tab roles (the fallback must not pretend to be a widget),
 *     and every id/aria-controls pair the script will rely on is already correct;
 *   - the decorative star is aria-hidden and cannot be focused;
 *   - the homepage hero carries ONE call to action and never places copy on the art;
 *   - PT and EN are separate, natural copy — not a locale swap on the same strings.
 *
 * Read-only: renders templates, reads files, writes nothing.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_title( 'Christmas signature experience' );

/**
 * Render one package template part and return its markup.
 *
 * @param string $template Theme-relative template path.
 * @param array  $args     Template args.
 * @return string
 */
function conexao_christmas_test_render( $template, array $args ) {
	ob_start();
	get_template_part( $template, null, $args );

	return (string) ob_get_clean();
}

$ce_package = conexao_commemorative_package( 'christmas' );

test_require( null !== $ce_package, 'the theme is active', 'the christmas package is registered' );

test_section( 'The identity is one shared star, not a bauble' );

assert_equals( 'shared_star', $ce_package['motif'], 'the signature motif is the shared star' );
assert_equals( 'calm', $ce_package['energy'], 'the energy level stays calm' );
assert_equals(
	array( 'homepage_hero', 'site_wide_ambient', 'landing_page' ),
	array_keys( $ce_package['placements'] ),
	'the package keeps exactly its three approved placements — no new placement was invented'
);
assert_equals( 700, (int) $ce_package['motion']['duration_ms'], 'the motion language states the 700 ms travel' );

$ce_journey = conexao_christmas_test_render(
	'template-parts/commemorative/christmas/journey',
	array( 'ce' => array( 'package' => $ce_package ) )
);

assert_true( '' !== trim( $ce_journey ), 'the journey renders' );

test_section( 'Both skies are complete without JavaScript' );

assert_equals( 2, substr_count( $ce_journey, 'data-ce-journey-panel=' ), 'both perspectives are rendered server-side' );
assert_equals( 2, substr_count( $ce_journey, 'data-ce-journey-tab=' ), 'both controls are rendered server-side' );
assert_equals( 2, substr_count( $ce_journey, 'ce-christmas-journey__panel-title' ), 'each perspective has its own heading' );
assert_equals( 2, substr_count( $ce_journey, 'ce-christmas-journey__panel-text' ), 'each perspective has its own paragraph' );
assert_equals( 2, substr_count( $ce_journey, 'ce-christmas-journey__note' ), 'each perspective states its own nuance' );
assert_equals( 6, substr_count( $ce_journey, '<li>' ), 'each perspective lists three observed details (2 x 3)' );
assert_equals( 2, substr_count( $ce_journey, 'ce-christmas-journey__sky ' ), 'each perspective has its own sky scene' );

assert_contains( 'data-ce-journey-controls hidden', $ce_journey, 'the control stays hidden until the script reveals it' );
assert_not_contains( 'role="tab"', $ce_journey, 'the no-JS fallback does not pretend to be a tabs widget' );
assert_not_contains( 'role="tablist"', $ce_journey, 'no tablist role before the script runs' );
assert_not_contains( 'role="tabpanel"', $ce_journey, 'no tabpanel role before the script runs' );

test_section( 'The control and the panels are already wired correctly' );

foreach ( array( 'brasil', 'irlanda' ) as $ce_key ) {
	assert_contains( 'id="ce-christmas-journey-tab-' . $ce_key . '"', $ce_journey, $ce_key . ': the tab carries its id' );
	assert_contains( 'aria-controls="ce-christmas-journey-panel-' . $ce_key . '"', $ce_journey, $ce_key . ': the tab controls its panel' );
	assert_contains( 'id="ce-christmas-journey-panel-' . $ce_key . '"', $ce_journey, $ce_key . ': the panel carries its id' );
	assert_equals( 1, substr_count( $ce_journey, 'id="ce-christmas-journey-panel-' . $ce_key . '"' ), $ce_key . ': the panel id appears exactly once' );
}

assert_contains( 'data-ce-journey-state="brasil"', $ce_journey, 'the scene publishes a clear default state' );
assert_contains( 'data-ce-journey-tabs', $ce_journey, 'the tablist hook the script upgrades exists' );

test_section( 'The decorative star is never announced and never focusable' );

assert_equals( 2, substr_count( $ce_journey, 'class="ce-xmas-star ' ), 'the shared star appears twice: the control and the closing thread' );

preg_match_all( '/<svg[^>]*ce-xmas-star[^>]*>/s', $ce_journey, $ce_star_tags );

assert_equals( 2, count( $ce_star_tags[0] ), 'both star instances are SVG, not raster crops' );

foreach ( $ce_star_tags[0] as $ce_tag ) {
	assert_contains( 'aria-hidden="true"', $ce_tag, 'the decorative star is hidden from assistive technology' );
	assert_contains( 'focusable="false"', $ce_tag, 'the decorative star cannot receive focus' );
}

assert_not_contains( '<img', substr( $ce_journey, strpos( $ce_journey, 'ce-christmas-journey__scene' ) ), 'the scenes are CSS skies, not extra image requests' );


test_section( 'The artwork is local, present and sized for the web' );

$ce_art_dir = get_template_directory() . '/assets/images/presentations/christmas/two-skies/';
$ce_art     = array(
	'two-skies-hero-840.webp',
	'two-skies-hero-1200.webp',
	'star-bridge-780.webp',
	'star-bridge-1280.webp',
	'two-skies-postcard-760.webp',
	'two-skies-postcard-1120.webp',
);

foreach ( $ce_art as $ce_file ) {
	assert_true( is_file( $ce_art_dir . $ce_file ), 'the derivative ' . $ce_file . ' exists' );
	assert_true( (int) @filesize( $ce_art_dir . $ce_file ) > 0, $ce_file . ' is not empty' );
	assert_true( (int) @filesize( $ce_art_dir . $ce_file ) < 300000, $ce_file . ' stays under 300 KB' );
}

assert_equals( array(), (array) glob( $ce_art_dir . '*.png' ), 'the multi-megabyte PNG masters are not shipped in the theme' );
assert_true(
	is_file( get_template_directory() . '/assets/images/presentations/christmas/decorations/star.svg' ),
	'the ambient star asset exists'
);
assert_true(
	! is_file( get_template_directory() . '/assets/images/presentations/christmas/decorations/bauble.svg' ),
	'the retired bauble asset is gone'
);
assert_contains( 'star-bridge-1280.webp', $ce_journey, 'the journey uses the star-bridge composition' );
assert_not_contains(
	'src="http',
	str_replace( get_template_directory_uri(), '', $ce_journey ),
	'every artwork URL resolves inside the theme — no remote hotlink'
);

test_section( 'The homepage hero: one CTA, art and copy as siblings' );

$GLOBALS['wp_query']->is_home           = false;
$GLOBALS['wp_query']->is_page           = true;
$GLOBALS['wp_query']->is_singular       = true;
$GLOBALS['wp_query']->is_archive        = false;
$GLOBALS['wp_query']->is_search         = false;
$GLOBALS['wp_query']->is_404            = false;
$GLOBALS['wp_query']->queried_object    = get_post( (int) get_option( 'page_on_front' ) );
$GLOBALS['wp_query']->queried_object_id = (int) get_option( 'page_on_front' );

conexao_commemorative_flush_plan();

$GLOBALS['conexao_ce_plan'] = conexao_commemorative_build_plan( new DateTimeImmutable( '2026-12-15 12:00:00', wp_timezone() ) );

ob_start();
conexao_commemorative_render_placement( 'homepage_hero' );
$ce_hero = (string) ob_get_clean();

assert_true( '' !== trim( $ce_hero ), 'the christmas hero renders inside its window' );
assert_contains( 'ce-christmas-hero', $ce_hero, 'the package hero component renders' );
assert_equals( 1, substr_count( $ce_hero, '<a ' ), 'the band offers exactly one call to action' );
assert_contains( 'btn btn-primary', $ce_hero, 'the single control is the primary button' );
assert_contains( 'two-skies-hero-1200.webp', $ce_hero, 'the hero uses the warm-evening panorama' );
assert_contains( 'srcset=', $ce_hero, 'the hero art is responsive' );
assert_contains( 'width="1200"', $ce_hero, 'the hero art declares its intrinsic width (no layout shift)' );
assert_contains( 'height="675"', $ce_hero, 'the hero art declares its intrinsic height (no layout shift)' );
assert_contains( 'alt="', $ce_hero, 'the informative artwork carries alternative text' );
assert_contains( '<h2', $ce_hero, 'the band keeps a level-two heading under the page h1' );
assert_not_contains( '<h1', $ce_hero, 'the band never introduces a second document heading' );
assert_not_contains( 'data-dismiss-key', $ce_hero, 'the hero is a band, never a dismissible overlay' );

$ce_landing = conexao_commemorative_resolve_record( 'page', 'natal' );

if ( $ce_landing instanceof WP_Post ) {
	assert_contains( 'href="' . esc_url( get_permalink( $ce_landing ) ) . '"', $ce_hero, 'the single CTA leads to the Christmas landing page' );
}

test_section( 'The ambient marker requests the star, never the retired bauble' );

conexao_commemorative_flush_plan();

$GLOBALS['conexao_ce_plan'] = conexao_commemorative_build_plan( new DateTimeImmutable( '2026-12-15 12:00:00', wp_timezone() ) );

ob_start();
conexao_commemorative_render_placement( 'site_wide_ambient' );
$ce_ambient = (string) ob_get_clean();

assert_contains( 'data-ce-star-asset=', $ce_ambient, 'the ambient layer points at the star asset' );
assert_contains( 'decorations/star.svg', $ce_ambient, 'the star asset path is the installed file' );
assert_not_contains( 'bauble', $ce_ambient, 'no reference to the retired bauble survives' );
assert_contains( 'aria-hidden="true"', $ce_ambient, 'the ambient layer stays decorative' );

test_section( 'English is written, not poured from the Portuguese text' );

/**
 * Force the active UI locale for the duration of one assertion block.
 *
 * @return string
 */
function conexao_christmas_test_force_english() {
	return 'en_US';
}

add_filter( 'conexao_current_locale', 'conexao_christmas_test_force_english' );

$ce_journey_en = conexao_christmas_test_render(
	'template-parts/commemorative/christmas/journey',
	array( 'ce' => array( 'package' => $ce_package ) )
);

remove_filter( 'conexao_current_locale', 'conexao_christmas_test_force_english' );

assert_contains( 'Two skies, one star', $ce_journey_en, 'the English heading is real English' );
assert_contains( 'Winter, and the windows do the lighting', $ce_journey_en, 'the Irish perspective is translated' );
assert_contains( 'Choose a sky', $ce_journey_en, 'the control label is translated' );
assert_not_contains( 'Dois céus', $ce_journey_en, 'no Portuguese heading leaks into the English rendering' );
assert_not_contains( 'Inverno, e são as janelas', $ce_journey_en, 'no Portuguese vignette leaks into the English rendering' );

assert_contains( 'Dois céus, uma mesma estrela', $ce_journey, 'the Portuguese heading renders in the canonical locale' );
assert_not_contains( 'Two skies, one star', $ce_journey, 'no English heading leaks into the Portuguese rendering' );

assert_equals(
	substr_count( $ce_journey, '<li>' ),
	substr_count( $ce_journey_en, '<li>' ),
	'both locales carry the same number of observed details (parity, not truncation)'
);

conexao_commemorative_flush_plan();

test_finish( 'commemorative-christmas-journey' );
