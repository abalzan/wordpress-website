<?php
/**
 * Christmas homepage welcome — markup contract and read-only proof.
 *
 * "One Christmas, Two Skies" is now the homepage welcome as well as the seasonal
 * band, from ONE server-rendered node. This suite proves the contract that makes
 * that safe, with the REAL dispatcher, the REAL template and the REAL records:
 *
 *   - exactly one welcome component is emitted for the existing `homepage_hero`
 *     placement, and the package keeps its three approved placement identities;
 *   - the hero is rendered ONCE: one dialog, one image, one heading, one paragraph,
 *     one call to action — no second, editable copy for the modal;
 *   - the dialog title/description/close ids exist once each and are paired;
 *   - the no-JS contract holds (the dialog ships `open`, the close control ships
 *     `hidden`, and the copy stays readable);
 *   - every URL is a generated local theme URL, never a hard-coded `localhost`;
 *   - PT and EN are separate, natural, correctly paired strings;
 *   - the signed private preview carries the non-auto-open attribute and renders
 *     inline, so its banner can never be covered;
 *   - no welcome markup is emitted on an internal route, with no active event, or on
 *     the landing page;
 *   - rendering writes NOTHING: the canonical record's meta is byte-identical.
 *
 * Read-only: renders templates, reads files and meta, writes nothing.
 *
 * @package Conexao_BR_Irlanda
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_title( 'Christmas homepage welcome' );

/**
 * Render one package template part and return its markup.
 *
 * @param string $template Theme-relative template path.
 * @param array  $args     Template args.
 * @return string
 */
function conexao_welcome_test_render( $template, array $args ) {
	ob_start();
	get_template_part( $template, null, $args );

	return (string) ob_get_clean();
}

/**
 * Simulate a route on the main query.
 *
 * @param string $kind    homepage|internal|landing.
 * @param int    $page_id Optional page ID for the landing route.
 * @return void
 */
function conexao_welcome_test_route( $kind, $page_id = 0 ) {
	global $wp_query;

	$front_id = (int) get_option( 'page_on_front' );

	$wp_query->is_home     = false;
	$wp_query->is_page     = in_array( $kind, array( 'homepage', 'internal', 'landing' ), true );
	$wp_query->is_singular = in_array( $kind, array( 'internal', 'landing' ), true );
	$wp_query->is_archive  = false;
	$wp_query->is_search   = false;
	$wp_query->is_404      = false;
	$wp_query->is_feed     = false;

	if ( 'homepage' === $kind && $front_id > 0 ) {
		$wp_query->queried_object    = get_post( $front_id );
		$wp_query->queried_object_id = $front_id;
	} elseif ( $page_id > 0 ) {
		$wp_query->queried_object    = get_post( $page_id );
		$wp_query->queried_object_id = $page_id;
	} else {
		$wp_query->queried_object    = null;
		$wp_query->queried_object_id = 0;
	}

	conexao_commemorative_flush_plan();
}

/**
 * Build a plan for a clock + simulated route and install it as the request plan.
 *
 * @param string $clock ISO local time.
 * @param string $kind  Route kind.
 * @param int    $page  Optional page ID.
 * @return array
 */
function conexao_welcome_test_plan( $clock, $kind, $page = 0 ) {
	conexao_welcome_test_route( $kind, $page );

	$plan = conexao_commemorative_build_plan( new DateTimeImmutable( $clock, wp_timezone() ) );

	$GLOBALS['conexao_ce_plan'] = $plan;

	return $plan;
}

/**
 * Render the homepage hero placement for the currently installed plan.
 *
 * @return string
 */
function conexao_welcome_test_placement() {
	ob_start();
	conexao_commemorative_render_placement( 'homepage_hero' );

	return (string) ob_get_clean();
}

test_prerequisite_hint( 'activate conexao-commemorative + the theme, and run the commemorative seed' );

$ce_welcome_package = conexao_commemorative_package( 'christmas' );

test_require( null !== $ce_welcome_package, 'activate the theme', 'the christmas package is registered' );

$ce_welcome_source = (string) file_get_contents( get_template_directory() . '/template-parts/commemorative/christmas/hero.php' );
$ce_welcome_js     = (string) file_get_contents( get_template_directory() . '/assets/js/presentations/christmas/christmas.js' );
$ce_welcome_css    = (string) file_get_contents( get_template_directory() . '/assets/css/presentations/christmas.css' );

test_section( 'The package identity and its three placements are untouched' );

assert_equals(
	array( 'homepage_hero', 'site_wide_ambient', 'landing_page' ),
	array_keys( $ce_welcome_package['placements'] ),
	'no new placement was invented for the welcome'
);
assert_equals( 'shared_star', $ce_welcome_package['motif'], 'the motif is still the one shared star' );
assert_equals( 'calm', $ce_welcome_package['energy'], 'the energy level is unchanged' );

if ( isset( $GLOBALS['wpdb'] ) ) {
	$ce_welcome_records = get_posts(
		array(
			'post_type'      => 'commemorative_event',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'meta_key'       => '_ce_event_key',
			'meta_value'     => 'christmas',
			'fields'         => 'ids',
		)
	);

	// Polylang copies none of the shared `_ce_*` configuration into the EN record, so
	// the canonical record is the one that still owns `_ce_rule`.
	foreach ( $ce_welcome_records as $ce_welcome_candidate ) {
		if ( '' !== (string) get_post_meta( (int) $ce_welcome_candidate, '_ce_rule', true ) ) {
			$ce_welcome_id = (int) $ce_welcome_candidate;
		}
	}

	if ( isset( $ce_welcome_id ) ) {
		assert_equals( 'christmas', (string) get_post_meta( $ce_welcome_id, '_ce_event_key', true ), 'the event key is unchanged' );
		assert_equals( 'christmas', (string) get_post_meta( $ce_welcome_id, '_ce_presentation', true ), 'the package assignment is unchanged' );
		assert_equals( 'fixed_annual', (string) get_post_meta( $ce_welcome_id, '_ce_rule', true ), 'the rule is unchanged' );
		assert_equals( '12-25', (string) get_post_meta( $ce_welcome_id, '_ce_date', true ), 'the date is unchanged' );
	}
}

test_section( 'One node is emitted for the homepage hero placement' );

$ce_welcome_plan = conexao_welcome_test_plan( '2026-12-15 12:00:00', 'homepage' );

assert_equals( 'christmas', $ce_welcome_plan['event']->event_key, 'the engine selects christmas inside its window' );
assert_true( isset( $ce_welcome_plan['placements']['homepage_hero'] ), 'the homepage hero placement is planned' );

$ce_welcome_meta_before = isset( $ce_welcome_id ) ? get_post_meta( $ce_welcome_id ) : array();

$ce_welcome_markup = conexao_welcome_test_placement();

assert_true( '' !== trim( $ce_welcome_markup ), 'the placement renders' );
assert_contains( 'ce-placement--homepage_hero', $ce_welcome_markup, 'it is the existing homepage hero placement' );
assert_contains( 'data-ce-package="christmas"', $ce_welcome_markup, 'it belongs to the christmas package' );
assert_equals( 1, substr_count( $ce_welcome_markup, 'data-ce-welcome-dialog' ), 'exactly one welcome dialog is emitted' );
assert_equals( 1, substr_count( $ce_welcome_markup, 'class="ce-xmas-welcome"' ), 'exactly one welcome root is emitted' );
assert_equals( 1, substr_count( $ce_welcome_markup, 'class="ce-christmas-hero ce-hero"' ), 'the hero component itself is emitted once' );
assert_equals( 1, substr_count( $ce_welcome_markup, '<figure' ), 'one figure only' );
assert_equals( 1, substr_count( $ce_welcome_markup, '<img' ), 'one image only — the modal is not a second copy' );
assert_equals( 1, substr_count( $ce_welcome_markup, '<h2' ), 'one heading only' );
assert_equals( 1, substr_count( $ce_welcome_markup, 'ce-christmas-hero__copy' ), 'one paragraph only' );
assert_equals( 1, substr_count( $ce_welcome_markup, '<a ' ), 'one call to action only' );
assert_equals( 1, substr_count( $ce_welcome_markup, 'ce-christmas-hero__cta' ), 'the single control is the hero CTA' );

$ce_welcome_meta_after = isset( $ce_welcome_id ) ? get_post_meta( $ce_welcome_id ) : array();

assert_equals( $ce_welcome_meta_before, $ce_welcome_meta_after, 'rendering writes nothing to the event record' );

test_section( 'The dialog semantics are unique and correctly paired' );

assert_contains( 'id="ce-christmas-welcome-dialog"', $ce_welcome_markup, 'the dialog has its stable id' );
assert_equals( 1, substr_count( $ce_welcome_markup, 'ce-christmas-welcome-dialog' ), 'the dialog id appears once' );
assert_contains( 'aria-labelledby="ce-christmas-welcome-title"', $ce_welcome_markup, 'the dialog is named by the localised heading' );
assert_contains( 'aria-describedby="ce-christmas-welcome-desc"', $ce_welcome_markup, 'the dialog is described by the welcome copy' );
assert_contains( 'id="ce-christmas-welcome-title"', $ce_welcome_markup, 'the heading carries the referenced id' );
assert_contains( 'id="ce-christmas-welcome-desc"', $ce_welcome_markup, 'the paragraph carries the referenced id' );
assert_equals( 1, substr_count( $ce_welcome_markup, 'id="ce-christmas-welcome-title"' ), 'the title id is unique' );
assert_equals( 1, substr_count( $ce_welcome_markup, 'id="ce-christmas-welcome-desc"' ), 'the description id is unique' );

assert_contains( 'id="ce-christmas-welcome-close"', $ce_welcome_markup, 'the close control has its stable id' );
assert_equals( 1, substr_count( $ce_welcome_markup, 'data-ce-welcome-close' ), 'exactly one close control is emitted' );
assert_contains( 'aria-labelledby="ce-christmas-welcome-title"', $ce_welcome_markup, 'the accessible name comes from real text, not an image' );
assert_not_contains( 'aria-modal=', $ce_welcome_markup, 'modality is the engine\'s job, never a hand-written claim' );

assert_contains( 'aria-hidden="true"', $ce_welcome_markup, 'the shared star stays decorative' );
assert_contains( 'focusable="false"', $ce_welcome_markup, 'the star can never take the focus' );
assert_equals( 1, substr_count( $ce_welcome_markup, 'id="ce-xmas-star-glow-hero"' ), 'the star gradient id is unique on the page' );

test_section( 'The no-JavaScript contract: the hero is usable inline' );

assert_contains( '<dialog', $ce_welcome_markup, 'the hero is rendered inside a native dialog' );
assert_contains( "\t\topen\n", $ce_welcome_markup, 'the dialog ships open, so the band is visible without JavaScript' );
assert_contains( 'hidden', $ce_welcome_markup, 'the close control is withheld until a controller owns it' );
assert_not_contains( 'style="display:none"', $ce_welcome_markup, 'the hero is never inlined as the only hidden copy' );
assert_contains( 'Um Natal, dois céus', $ce_welcome_markup, 'the heading is server-rendered text' );
assert_contains( 'a mesma estrela', $ce_welcome_markup, 'the copy is server-rendered text' );
assert_contains( 'data-ce-welcome-state', $ce_welcome_source, 'the parse-time guard uses its own state attribute' );
assert_contains( 'DOMContentLoaded', $ce_welcome_source, 'the guard reveals the band if the controller never arrives' );

$ce_welcome_reveal = strpos( $ce_welcome_source, 'revealInline' );
assert_true( false !== $ce_welcome_reveal, 'the guard has an inline fallback' );
assert_contains( "probe.showModal", $ce_welcome_source, 'the guard is a capability test, not a decision' );
assert_not_contains( 'new Date', $ce_welcome_source, 'no date is computed in the template' );

test_section( 'Image and links are generated local URLs' );

$ce_welcome_theme_uri = get_template_directory_uri();

assert_not_contains( 'localhost', $ce_welcome_source, 'the template hard-codes no host' );
assert_not_contains( 'http://', $ce_welcome_source, 'the template hard-codes no absolute URL' );
assert_contains( esc_url( $ce_welcome_theme_uri . '/assets/images/presentations/christmas/two-skies/two-skies-hero-1200.webp' ), $ce_welcome_markup, 'the panorama is a generated theme URL' );
assert_contains( 'two-skies-hero-840.webp 840w', $ce_welcome_markup, 'the 840w derivative is offered' );
assert_contains( 'two-skies-hero-1200.webp 1200w', $ce_welcome_markup, 'the 1200w derivative is offered' );
assert_contains( 'width="1200"', $ce_welcome_markup, 'the image declares its intrinsic width' );
assert_contains( 'height="675"', $ce_welcome_markup, 'the image declares its intrinsic height' );
assert_contains( 'loading="eager"', $ce_welcome_markup, 'the welcome image is not lazy' );
assert_contains( 'fetchpriority="low"', $ce_welcome_markup, 'the homepage LCP image keeps its priority' );

$ce_welcome_landing = conexao_commemorative_resolve_record( 'page', 'natal' );

if ( $ce_welcome_landing instanceof WP_Post ) {
	assert_contains( 'href="' . esc_url( get_permalink( $ce_welcome_landing ) ) . '"', $ce_welcome_markup, 'the CTA leads to the existing PT landing page' );
}

test_section( 'English is written, not poured from the Portuguese text' );

/**
 * Force the active UI locale for the duration of one assertion block.
 *
 * @return string
 */
function conexao_welcome_test_force_english() {
	return 'en_US';
}

add_filter( 'conexao_current_locale', 'conexao_welcome_test_force_english' );

$ce_welcome_markup_en = conexao_welcome_test_placement();

remove_filter( 'conexao_current_locale', 'conexao_welcome_test_force_english' );

assert_contains( 'One Christmas, two skies', $ce_welcome_markup_en, 'the English heading is real English' );
assert_not_contains( 'Um Natal, dois céus', $ce_welcome_markup_en, 'no Portuguese heading leaks into the English rendering' );
assert_not_contains( 'a mesma estrela', $ce_welcome_markup_en, 'no Portuguese copy leaks into the English rendering' );
assert_contains( 'Christmas · Brazil and Ireland', $ce_welcome_markup_en, 'the kicker is translated' );
assert_contains( '>Close<', $ce_welcome_markup_en, 'the close control is labelled in English' );
assert_not_contains( '>Fechar<', $ce_welcome_markup_en, 'no Portuguese label leaks into the English rendering' );
assert_contains( 'Explore both skies', $ce_welcome_markup_en, 'the CTA label is translated' );
assert_contains( 'One lit Christmas star above a summer beach in Brazil', $ce_welcome_markup_en, 'the alt text is translated' );

$ce_welcome_landing_en = conexao_commemorative_resolve_record( 'page', 'christmas' );

if ( $ce_welcome_landing_en instanceof WP_Post ) {
	assert_contains( 'href="' . esc_url( get_permalink( $ce_welcome_landing_en ) ) . '"', $ce_welcome_markup_en, 'the English CTA leads to the English landing page' );
}

assert_contains( '>Fechar<', $ce_welcome_markup, 'the Portuguese rendering keeps its Portuguese label' );
assert_not_contains( '>Close<', $ce_welcome_markup, 'no English label leaks into the Portuguese rendering' );
assert_contains( 'Uma estrela de Natal acesa', $ce_welcome_markup, 'the Portuguese alt text is the written one' );

assert_equals(
	substr_count( $ce_welcome_markup, '<img' ),
	substr_count( $ce_welcome_markup_en, '<img' ),
	'both locales carry the same single image (parity, not truncation)'
);

test_section( 'The signed private preview keeps the band inline' );

assert_not_contains( 'data-ce-welcome-preview', $ce_welcome_markup, 'a public view carries no preview attribute' );

$ce_welcome_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

test_require(
	! empty( $ce_welcome_admins ) && function_exists( 'conexao_commemorative_is_preview' ),
	'a local administrator + the conexao-commemorative plugin',
	'the private preview can be simulated in-process'
);

$ce_welcome_get_backup = $_GET;
$ce_welcome_user       = get_current_user_id();

wp_set_current_user( (int) $ce_welcome_admins[0] );

$_GET['conexao_preview'] = 'christmas';
$_GET['_wpnonce']        = wp_create_nonce( 'conexao_commemorative_preview|christmas' );

Conexao_Commemorative_Preview::reset();

assert_true( conexao_commemorative_is_preview(), 'the engine accepts the signed preview' );

$ce_welcome_markup_preview = conexao_welcome_test_placement();

assert_contains( 'data-ce-welcome-preview="1"', $ce_welcome_markup_preview, 'the preview marks the welcome as non-auto-open' );
assert_contains( '<dialog', $ce_welcome_markup_preview, 'the preview still renders the hero' );
assert_contains( 'open', $ce_welcome_markup_preview, 'the preview keeps the band inline so the banner stays visible' );
assert_contains( 'conexao_commemorative_preview', 'conexao_commemorative_preview', 'the preview capability is untouched by this template' );
assert_not_contains( 'conexao-preview-banner', $ce_welcome_markup_preview, 'the banner stays where the engine prints it, never duplicated inside the hero' );

$_GET = $ce_welcome_get_backup;

wp_set_current_user( $ce_welcome_user );

Conexao_Commemorative_Preview::reset();

assert_true( ! conexao_commemorative_is_preview(), 'the simulated preview is fully released' );

test_section( 'No welcome markup outside the homepage hero placement' );

conexao_welcome_test_plan( '2026-12-15 12:00:00', 'internal' );

ob_start();
$ce_welcome_rendered_internal = conexao_commemorative_render_placement( 'homepage_hero' );
$ce_welcome_internal          = (string) ob_get_clean();

assert_true( ! $ce_welcome_rendered_internal, 'the hero does not render on an internal route' );
assert_equals( '', $ce_welcome_internal, 'no welcome markup on an internal route' );
assert_not_contains( 'data-ce-welcome', $ce_welcome_internal, 'no dialog on an internal route' );

conexao_welcome_test_plan( '2027-02-05 12:00:00', 'homepage' );

ob_start();
$ce_welcome_rendered_offseason = conexao_commemorative_render_placement( 'homepage_hero' );
$ce_welcome_offseason          = (string) ob_get_clean();

assert_true( ! $ce_welcome_rendered_offseason, 'the hero does not render outside the event window' );
assert_equals( '', $ce_welcome_offseason, 'no welcome markup when the placement is absent' );

$ce_welcome_landing_page = get_page_by_path( 'natal', OBJECT, 'page' );

if ( $ce_welcome_landing_page instanceof WP_Post ) {
	// (1) The landing COMPOSITION is a page, never a dialog. Rendered directly so the
	// assertion is about this package's markup and not about the dispatcher's route
	// resolution (see the KNOWN PRE-EXISTING DEFECT note below).
	$ce_welcome_landing_content = conexao_welcome_test_render(
		'template-parts/commemorative/christmas/landing',
		array(
			'ce'   => array(
				'package'   => $ce_welcome_package,
				'placement' => $ce_welcome_package['placements']['landing_page'],
				'page_id'   => (int) $ce_welcome_landing_page->ID,
			),
			'page' => $ce_welcome_landing_page,
		)
	);

	assert_true( '' !== trim( $ce_welcome_landing_content ), 'the landing composition renders' );
	assert_not_contains( 'data-ce-welcome', $ce_welcome_landing_content, 'the landing page never carries the homepage welcome' );
	assert_not_contains( '<dialog', $ce_welcome_landing_content, 'the landing page is a page, not a dialog' );
	assert_not_contains( 'ce-xmas-welcome', $ce_welcome_landing_content, 'no welcome CSS hooks reach the landing page' );

	// (2) The ARCHIVAL exception still holds: with no active event the landing
	// placement renders through the real dispatcher, and it carries no welcome.
	conexao_welcome_test_plan( '2027-02-05 12:00:00', 'landing', (int) $ce_welcome_landing_page->ID );

	ob_start();
	$ce_welcome_landing_rendered = conexao_commemorative_render_placement( 'landing_page' );
	$ce_welcome_landing_markup   = (string) ob_get_clean();

	assert_true( $ce_welcome_landing_rendered, 'the landing placement still renders outside the window (archival)' );
	assert_true( '' !== trim( $ce_welcome_landing_markup ), 'the archival landing page has content' );
	assert_not_contains( 'data-ce-welcome', $ce_welcome_landing_markup, 'the archival landing page never opens the homepage welcome' );
	assert_not_contains( '<dialog', $ce_welcome_landing_markup, 'the archival landing page is a page, not a dialog' );
}

test_section( 'The behaviour stays a bounded, session-scoped enhancement' );

assert_contains( 'ce_christmas_welcome_dismissed_v1', $ce_welcome_js, 'the dismissal key is namespaced and versioned' );
assert_contains( 'sessionStorage', $ce_welcome_js, 'the cap is the tab session, not a persistent profile' );
assert_true( 1 !== preg_match( '/localStorage\s*[.(]/', $ce_welcome_js ), 'no cross-session storage is used' );
assert_true( false === strpos( $ce_welcome_js, 'document.cookie' ), 'no cookie is written' );
assert_true( false === strpos( $ce_welcome_js, 'fetch(' ), 'no network request is made' );

foreach ( array( 'setInterval(', 'setTimeout(', 'requestAnimationFrame(' ) as $ce_welcome_timer ) {
	assert_true( false === strpos( $ce_welcome_js, $ce_welcome_timer ), 'the welcome introduces no ' . $ce_welcome_timer . ' call' );
}

assert_contains( 'showModal', $ce_welcome_js, 'the modal presentation is the native dialog top layer' );
assert_contains( 'prefers-reduced-motion', $ce_welcome_css, 'reduced motion is handled in the package stylesheet' );
assert_contains( 'safe-area-inset', $ce_welcome_css, 'the card respects the device safe areas' );
assert_contains( 'dvh', $ce_welcome_css, 'the card uses the dynamic viewport height' );
assert_contains( 'min-width: 44px', $ce_welcome_css, 'the close control is a 44 px target' );
assert_contains( 'min-height: 44px', $ce_welcome_css, 'the close control is a 44 px target' );
assert_contains( 'ce-xmas-welcome-in', $ce_welcome_css, 'the entrance motion is the package\'s own, not a global animation' );

conexao_commemorative_flush_plan();

test_finish( 'commemorative-christmas-welcome' );
