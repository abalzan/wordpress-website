<?php
/**
 * UI polish Phase 3 (Tier-2 remainder) regression suite.
 *
 * Pins the three minimal convergence changes validated against live Chromium
 * renders on master (which already contains Phases 1+2 = T1.1-T1.14):
 *
 *  R1 (audit I1/T1.3 remainder) — component headings are token-bound:
 *    .help-section-heading uses --text-xl (not raw 1.25rem);
 *    mobile .quick-access-heading uses --text-base (not off-scale 1.0625rem);
 *    the leisure-filter and empregos-opportunities titles carry documented
 *    scoped-exception comments (they are deliberately NOT section-scale).
 *
 *  R2 (audit D5/T2.6) — card CTAs meet the 44px target floor:
 *    .event-card-cta, .post-type-archive-sponsor .archive-card-cta and
 *    .leisure-card-cta each declare min-height: 44px, matching the provider
 *    card which already renders 44px. Inline text links are deliberately NOT
 *    enlarged (accepted WCAG 2.5.8 inline exceptions; density is a feature).
 *
 *  R3 (audit T2.5, Direction B narrow) — the single editorial measure:
 *    .single-post-content max-width is 720px (not 800px) and the single body
 *    uses the documented --text-lg step (not off-scale 1.0625rem).
 *
 * Read-only: creates nothing, mutates nothing, contacts nothing.
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

echo "UI polish Phase 3 (Tier-2 remainder)\n";
echo str_repeat( '-', 72 ) . "\n";

/**
 * Read a theme source file once.
 *
 * @param string $rel Path relative to the theme root.
 * @return string
 */
function conexao_ui_p3_source( $rel ) {
	static $cache = array();
	if ( ! isset( $cache[ $rel ] ) ) {
		$cache[ $rel ] = (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}
	return $cache[ $rel ];
}

$main_css    = conexao_ui_p3_source( 'assets/css/main.css' );
$leisure_css = conexao_ui_p3_source( 'assets/css/leisure.css' );

// ---------------------------------------------------------------------------
// R1 — component headings are token-bound, exceptions documented.
// ---------------------------------------------------------------------------

assert_true(
	strpos( $main_css, '.help-section-heading' ) !== false
		&& strpos( $main_css, 'font-size: var(--text-xl);' ) !== false,
	'R1 the help-section heading is token-bound (--text-xl), not a raw rem value'
);

assert_true(
	0 === preg_match( '/\.quick-access-heading\s*\{[^}]*font-size:\s*1\.0625rem;/s', $main_css ),
	'R1 no off-scale 1.0625rem remains on the mobile quick-access heading'
);

assert_true(
	1 === preg_match( '/\.quick-access-heading\s*\{[^}]*font-size:\s*var\(--text-base\);/s', $main_css ),
	'R1 the mobile quick-access heading converges to --text-base (16px)'
);

assert_true(
	false !== strpos( $leisure_css, 'leisure-filters-title' )
		&& false !== stripos( $leisure_css, 'scoped component exception' ),
	'R1 the leisure filter title carries its documented scoped-exception rationale'
);

assert_true(
	false !== strpos( $main_css, 'empregos-opportunities-title' )
		&& false !== stripos( $main_css, 'scoped component exception' ),
	'R1 the empregos opportunities title carries its documented scoped-exception rationale'
);

// The deliberate hero-band exception must NOT be swept into the scale.
assert_true(
	1 === preg_match( '/\.newsletter-title\s*\{[^}]*clamp\(1\.75rem,\s*3vw,\s*2\.5rem\)/s', $main_css ),
	'R1 the newsletter hero-band title keeps its deliberate clamp (not section-scale)'
);

// ---------------------------------------------------------------------------
// R2 — card CTA 44px target floor.
// ---------------------------------------------------------------------------

assert_true(
	1 === preg_match( '/\.event-card-cta\s*\{[^}]*min-height:\s*44px;/s', $main_css ),
	'R2 the event card CTA declares the 44px target floor'
);

assert_true(
	1 === preg_match( '/\.post-type-archive-sponsor\s+\.archive-card-cta\s*\{[^}]*min-height:\s*44px;/s', $main_css ),
	'R2 the sponsor archive card CTA declares the 44px target floor'
);

assert_true(
	1 === preg_match( '/\.leisure-card-cta\s*\{[^}]*min-height:\s*44px;/s', $leisure_css ),
	'R2 the leisure card CTA declares the 44px target floor'
);

assert_true(
	1 === preg_match( '/\.provider-card-cta\s*\{[^}]*min-height:\s*44px;/s', $main_css ),
	'R2 the provider card CTA still declares its 44px floor (the reference treatment)'
);

// ---------------------------------------------------------------------------
// R3 — single editorial measure.
// ---------------------------------------------------------------------------

assert_true(
	1 === preg_match( '/\.single-post-content\s*\{[^}]*max-width:\s*720px;/s', $main_css ),
	'R3 the single column uses the 720px editorial measure (not 800px)'
);

assert_true(
	0 === preg_match( '/\.single-post-content\s*\{[^}]*max-width:\s*800px;/s', $main_css ),
	'R3 no 800px single-post-content rule remains as the governing measure'
);

assert_true(
	1 === preg_match( '/\.single-post-content\s+\.entry-content\s*\{[^}]*font-size:\s*var\(--text-lg\);/s', $main_css ),
	'R3 the single body uses the documented --text-lg step (not off-scale 1.0625rem)'
);

test_finish( 'ui-polish-p3' );
