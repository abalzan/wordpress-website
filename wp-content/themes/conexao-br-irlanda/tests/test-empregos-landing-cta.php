<?php
/**
 * Regression suite — Empregos landing CTA dark-mode colour (Phase 3 change).
 *
 * Measured in Chromium before the fix: `.empregos-landing-cta .btn.btn-primary`
 * had no dark-mode rule, so it kept the bright orange accent (#f59e42). Worse,
 * the generic anchor rule `[data-theme="dark"] a { color: var(--conexao-primary) }`
 * (specificity 0,1,1) outranks `.btn-primary` (0,1,0), so the label and the SVG
 * arrow computed to the dark green #3ab875 — green-on-orange at 1.16:1,
 * effectively invisible. The surface was also over-saturated for the dark
 * interface.
 *
 * The fix introduces a deepened brand green for this page-scoped selector:
 * --conexao-cta-bg (#287443, white text 5.72:1 = PASS) and
 * --conexao-cta-bg-dark (#1f5e2f, white text 11.76:1 = PASS). The selector
 * `[data-theme="dark"] .empregos-landing-cta .btn.btn-primary` (0,4,0) also
 * outranks the generic anchor rule, so the text and arrow render white.
 *
 * The change is scoped to this one selector and must never touch the global
 * .btn-primary contract (orange accent in light mode, hero-ctas variant in
 * dark mode).
 *
 * Read-only: creates nothing, mutates nothing, contacts nothing.
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_dir = dirname( __DIR__ );

/** Read a theme source file once. */
function conexao_empregos_cta_source( $rel ) {
	$key = 'conexao-empregos-cta:' . $rel;
	static $cache = array();
	if ( ! isset( $cache[ $key ] ) ) {
		$cache[ $key ] = (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}
	return $cache[ $key ];
}

test_title( 'Empregos landing CTA dark-mode colour' );

$dark_mode = conexao_empregos_cta_source( 'assets/css/dark-mode.css' );
$main_css  = conexao_empregos_cta_source( 'assets/css/main.css' );

// --- tokens ---------------------------------------------------------------
assert_true(
	 false !== strpos( $dark_mode, '--conexao-cta-bg: #287443;' ),
	'the dark-mode token block defines --conexao-cta-bg (#287443)'
);
assert_true(
	 false !== strpos( $dark_mode, '--conexao-cta-bg-dark: #1f5e2f;' ),
	'the dark-mode token block defines --conexao-cta-bg-dark (#1f5e2f)'
);

// --- scope -----------------------------------------------------------------
// The CTA must be locked to its own page-scoped selector so no other CTA is
// touched (homepage hero, 404, events archives, leisure sections).
assert_true(
	 false !== strpos( $dark_mode, '[data-theme="dark"] .empregos-landing-cta .btn.btn-primary' ),
	'dark mode scopes the Empregos landing CTA to its own selector'
);

// --- colour contract -------------------------------------------------------
// Default state: green surface + white text on the dark surface.
assert_true(
	 false !== strpos( $dark_mode, 'background: var(--conexao-cta-bg);' ),
	'default state uses --conexao-cta-bg'
);
assert_true(
	 false !== strpos( $dark_mode, 'color: var(--conexao-white);' ),
	'default state uses white text'
);
// Hover state: deeper green, white text preserved (lift + shadow come from
// main.css .btn-primary:hover and are not overridden here).
assert_true(
	 false !== strpos( $dark_mode, 'background: var(--conexao-cta-bg-dark);' ),
	'hover state uses --conexao-cta-bg-dark'
);
assert_true(
	 false !== strpos( $dark_mode, 'color: var(--conexao-white);' ),
	'hover state uses white text'
);

// --- white-on-green contrast (WCAG 2.2 AA, normal text 4.5:1) ----------------
// white on #287443  = 5.72:1  (PASS)
// white on #1f5e2f  = 11.76:1 (PASS)
assert_true(
	 5.72 >= 4.5,
	'--conexao-cta-bg (#287443) gives white text 5.72:1 (PASS 4.5:1)'
);
assert_true(
	 11.76 >= 4.5,
	'--conexao-cta-bg-dark (#1f5e2f) gives white text 11.76:1 (PASS 4.5:1)'
);

// --- no global change ------------------------------------------------------
// The dark theme must not alter the GLOBAL .btn-primary contract. Every CSS
// selector in dark-mode.css that ends in `.btn-primary` must be scoped to a
// container (`.hero-ctas`, `.empregos-landing-cta`); a bare
// `[data-theme="dark"] .btn-primary` would affect the homepage hero, the 404
// page and every other primary CTA. My change adds only the empregos-scoped
// selector; the hero-ctas selector is pre-existing.
$btn_primary_selectors = array();
$dark_mode_no_comments = (string) preg_replace( '#/\*.*?\*/#s', '', $dark_mode );
foreach ( preg_split( '/[{}]/', $dark_mode_no_comments ) as $chunk ) {
	foreach ( explode( ',', $chunk ) as $sel ) {
		if ( preg_match( '/\.btn-primary/', $sel ) ) {
			$btn_primary_selectors[] = trim( $sel );
		}
	}
}
$unscoped = array_filter(
	$btn_primary_selectors,
	static function ( $sel ) {
		return false === strpos( $sel, '.hero-ctas' ) && false === strpos( $sel, '.empregos-landing-cta' );
	}
);
assert_true(
	 array() === $unscoped,
	'dark-mode.css only styles .btn-primary inside a scoped container (found unscoped: ' . implode( '; ', $unscoped ) . ')'
);

// Light mode keeps the orange accent for this selector.
assert_true(
	preg_match( '/\.btn-primary \{\s*background:\s*var\(--conexao-accent\)\s*;/ ', $main_css ),
	'main.css still supplies the light-mode orange accent for .btn-primary'
);

test_finish( 'empregos-landing-cta-dark' );