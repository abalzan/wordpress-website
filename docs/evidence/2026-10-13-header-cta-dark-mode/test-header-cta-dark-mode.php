<?php
/**
 * Regression suite — Header "Anuncie Aqui" CTA dark-mode contrast.
 *
 * The header renders the /anuncie/ action in three responsive instances of the
 * SAME link: the full-text desktop `.header-cta` (>1024px), the compact mobile
 * top-bar `.header-cta-compact` and the full-width drawer `.mobile-menu-cta`.
 * Each is an `<a>` whose upload SVG uses `stroke="currentColor"`.
 *
 * The defect (measured in Chromium before the fix): the desktop `.header-cta`
 * was left out of the dark-mode colour pin. `header-nav.css` paints it white at
 * specificity (0,1,0), but the generic dark anchor rule
 * `[data-theme="dark"] a { color: var(--conexao-primary) }` (0,1,1) outranks it,
 * so in dark mode the label AND its currentColor icon computed to the dark green
 * #3ab875 on the orange #f59e42 = 1.19:1 — effectively invisible. On hover/
 * active it was orange-on-orange (~1.46:1).
 *
 * The fix extends the existing dark-mode CTA block (already covering the two
 * mobile variants) to also pin `.header-cta` (+ :hover/:active/:focus-visible)
 * to `var(--conexao-white)`. The selector (0,2,0 base / 0,3,0 with a state)
 * outranks the generic anchor rules, and because the SVG icon is
 * `stroke="currentColor"` the icon inherits the pinned white with no separate
 * icon rule. The orange accent BACKGROUND is intentionally left unchanged; only
 * the text/icon colour is pinned. Light mode is never touched.
 *
 * Read-only: creates nothing, mutates nothing, contacts nothing.
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

/** Read a theme source file once. */
function conexao_header_cta_source( $rel ) {
	$key = 'conexao-header-cta:' . $rel;
	static $cache = array();
	if ( ! isset( $cache[ $key ] ) ) {
		$cache[ $key ] = (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}
	return $cache[ $key ];
}

/** WCAG relative luminance of a #rrggbb colour. */
function conexao_header_cta_luminance( $hex ) {
	$hex = ltrim( $hex, '#' );
	$ch  = array( hexdec( substr( $hex, 0, 2 ) ) / 255, hexdec( substr( $hex, 2, 2 ) ) / 255, hexdec( substr( $hex, 4, 2 ) ) / 255 );
	$lin = array_map(
		static function ( $v ) {
			return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
		},
		$ch
	);
	return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
}

/** WCAG contrast ratio between two #rrggbb colours. */
function conexao_header_cta_contrast( $a, $b ) {
	$la = conexao_header_cta_luminance( $a );
	$lb = conexao_header_cta_luminance( $b );
	$hi = max( $la, $lb );
	$lo = min( $la, $lb );
	return round( ( $hi + 0.05 ) / ( $lo + 0.05 ), 2 );
}

test_title( 'Header "Anuncie Aqui" CTA dark-mode contrast' );

$dark_mode  = conexao_header_cta_source( 'assets/css/dark-mode.css' );
$header_nav = conexao_header_cta_source( 'assets/css/header-nav.css' );
$header_php = conexao_header_cta_source( 'header.php' );

// --- the specificity trap is real (the generic dark anchor rule exists) -----
// The fix is only meaningful if the generic rule that caused green-on-orange is
// still present; the pinned selectors must outrank it.
assert_true(
	false !== strpos( $dark_mode, '[data-theme="dark"] a {' ) && false !== strpos( $dark_mode, 'color: var(--conexao-primary);' ),
	'the generic dark anchor colour rule [data-theme="dark"] a is present (the trap the fix must outrank)'
);

// --- the dark-mode CTA block covers all three variants in every state -------
// Extract the combined rule that starts at the .header-cta selector and read
// through to its closing brace (the body has no nested braces).
$start = strpos( $dark_mode, '[data-theme="dark"] .header-cta,' );
assert_true(
	false !== $start,
	'dark-mode.css pins the desktop .header-cta in the accent-CTA block'
);
$open  = strpos( $dark_mode, '{', $start );
$close = strpos( $dark_mode, '}', $open );
assert_true(
	false !== $open && false !== $close,
	'the accent-CTA rule is well-formed (opening and closing braces found)'
);
$block = substr( $dark_mode, $start, $close - $start );

$variants = array( '.header-cta', '.header-cta-compact', '.mobile-menu-cta' );
$states   = array( '', ':hover', ':active', ':focus-visible' );
foreach ( $variants as $variant ) {
	foreach ( $states as $state ) {
		$selector = '[data-theme="dark"] ' . $variant . $state;
		assert_true(
			false !== strpos( $block, $selector ),
			'the dark accent-CTA block pins ' . $selector
		);
	}
}

// --- white text is pinned for the block -------------------------------------
assert_true(
	1 === substr_count( $block, 'color: var(--conexao-white);' ),
	'the dark accent-CTA block sets color: var(--conexao-white) exactly once for the shared body'
);

// --- the orange BACKGROUND is NOT altered by the dark CTA block -------------
// The task requires the orange fill to stay; only the text/icon colour is
// pinned. The block must therefore contain no background/background-color.
assert_true(
	false === stripos( $block, 'background' ),
	'the dark accent-CTA block does not touch the background (orange fill preserved)'
);

// --- the base rule in header-nav.css still supplies the orange fill ---------
// Light mode and the (unchanged) orange surface come from here; the dark block
// must not have replaced it.
assert_true(
	(bool) preg_match( '/\.header-cta,\s*\.mobile-menu-cta,\s*\.header-cta-compact\s*\{[^}]*background:\s*var\(--color-accent\);/s', $header_nav ),
	'header-nav.css still paints the accent CTA with the orange var(--color-accent) fill'
);
assert_true(
	(bool) preg_match( '/\.header-cta,\s*\.mobile-menu-cta,\s*\.header-cta-compact\s*\{[^}]*color:\s*var\(--conexao-white\);/s', $header_nav ),
	'header-nav.css still declares the intended white label for the accent CTA'
);

// --- the icon inherits white via currentColor (no separate icon rule) -------
// Each CTA anchor's upload SVG must use stroke="currentColor", and dark mode
// must NOT add a rule that repaints the CTA svg to another colour. The anchor
// href is a PHP echo whose closing tag emits a greater-than character, so a
// `<a [^>]*class=…>` pattern would stop at that tag's own greater-than sign;
// locate the class token and scan forward to the matching </a> instead.
foreach ( $variants as $variant ) {
	$class = ltrim( $variant, '.' ); // HTML class has no leading dot.
	$pos   = strpos( $header_php, 'class="' . $class . '"' );
	assert_true(
		false !== $pos,
		'header.php renders the ' . $variant . ' anchor'
	);
	$close  = strpos( $header_php, '</a>', $pos );
	$anchor = substr( $header_php, $pos, $close - $pos );
	assert_true(
		false !== strpos( $anchor, 'currentColor' ),
		'the ' . $variant . ' upload icon uses stroke="currentColor" (inherits the pinned white)'
	);
}
assert_true(
	0 === preg_match( '/\[data-theme="dark"\][^{]*\.header-cta[^{]*svg/i', $dark_mode ),
	'dark-mode.css adds no separate rule that recolours the CTA svg (currentColor handles it)'
);

// --- focus indicator stays accessible ---------------------------------------
// The block pins :focus-visible colour (above); the focus RING comes from the
// shared :focus-visible outline rule and must still reference --focus-ring.
assert_true(
	(bool) preg_match( '/\.header-cta:focus-visible[\s\S]{0,400}?outline:\s*var\(--focus-ring\)/s', $header_nav ),
	'header-nav.css keeps a --focus-ring outline on .header-cta:focus-visible'
);
assert_true(
	false !== strpos( $dark_mode, '--focus-ring: 3px solid var(--conexao-accent);' ),
	'dark mode keeps a visible --focus-ring (3px solid accent) for the CTA focus state'
);

// --- contrast: the fix removes the green-on-orange invisibility -------------
// Before: dark green #3ab875 label on orange #f59e42. After: white on the SAME
// orange. The orange surface is unchanged (light-mode customizer default
// #F68B1F, dark accent #f59e42); white text is the repo's established accent-
// button treatment. Assert the improvement and the parity, computed live.
$green_on_orange = conexao_header_cta_contrast( '#3ab875', '#f59e42' );
$white_on_orange = conexao_header_cta_contrast( '#ffffff', '#f59e42' );
$white_on_light  = conexao_header_cta_contrast( '#ffffff', '#F68B1F' );

assert_true(
	1.19 === $green_on_orange,
	'before the fix the dark label measured 1.19:1 (green #3ab875 on orange #f59e42, effectively invisible)'
);
assert_true(
	$white_on_orange > $green_on_orange,
	sprintf( 'white text on the orange accent (%s:1) beats the old green-on-orange (%s:1)', $white_on_orange, $green_on_orange )
);
assert_true(
	abs( $white_on_light - 2.43 ) < 0.01,
	sprintf( 'dark-mode white-on-orange matches the light-mode white-on-orange accent treatment (light #F68B1F = %s:1)', $white_on_light )
);

test_finish( 'header-cta-dark-mode' );

