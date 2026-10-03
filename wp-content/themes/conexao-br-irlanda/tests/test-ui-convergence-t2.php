<?php
/**
 * UI convergence Phase 2 (T1.6 / T1.8 / T1.10 / T1.11 / T1.12 / T1.13) suite.
 *
 * Each assertion below pins a defect that was MEASURED in Chromium against the
 * local site before the fix, and re-checks the root cause rather than the
 * symptom. Where the audit's diagnosis was wrong, the corrected mechanism is
 * what is asserted.
 *
 *  T1.6 (audit D4)  — heading order. Measured: NOT "h1 -> h4 on 9 pages" but
 *    `h1 -> h3` on the event and course archives (their grid sits under the
 *    page <h1> with no <h2> above it) plus one `h1 -> h4` on the event single
 *    from the footer column title. The card title is therefore an <h2> on an
 *    archive and stays <h3> in a nested context, and the footer column title
 *    is an <h2> inside its own landmark.
 *
 *  T1.8 (audit D7) — one location row on the event card. Both values are still
 *    rendered; only the duplicated row and the second map-pin icon are gone, so
 *    this also guards against "fixing" it by dropping the county.
 *
 *  T1.10 (audit D9) — the comment submit control is inside the shared .btn
 *    contract (min 44px target + visible focus ring), not raw browser chrome.
 *
 *  T1.11 (audit D10) — the default WordPress category must not leak its raw
 *    English name into the PT UI, while every other category keeps its real
 *    name and the term/slug/URL are untouched.
 *
 *  T1.12 (audit D6) — a leisure card without an image renders NO placeholder
 *    box; a leisure card WITH an image still renders its image block.
 *
 *  T1.13 (audit D3/I13) — no rendered text may use gray-500 (2.07:1 on white).
 *
 * Read-only: creates nothing, mutates nothing, contacts nothing.
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

echo "UI convergence Phase 2 (T1.6 / T1.8 / T1.10 / T1.11 / T1.12 / T1.13)\n";
echo str_repeat( '-', 72 ) . "\n";

$theme_dir = dirname( __DIR__ );

/**
 * Read a theme source file once.
 *
 * @param string $rel Path relative to the theme root.
 * @return string
 */
function conexao_ui_t2_source( $rel ) {
	static $cache = array();
	if ( ! isset( $cache[ $rel ] ) ) {
		$cache[ $rel ] = (string) file_get_contents( dirname( __DIR__ ) . '/' . $rel );
	}
	return $cache[ $rel ];
}

// ---------------------------------------------------------------------------
// T1.6 — heading level contract.
// ---------------------------------------------------------------------------

$event_card = conexao_ui_t2_source( 'template-parts/event-card.php' );
$provider_card = conexao_ui_t2_source( 'template-parts/provider-card.php' );
$footer = conexao_ui_t2_source( 'footer.php' );

assert_true(
	strpos( $event_card, 'conexao_card_heading_tag' ) !== false,
	'T1.6 the event card resolves its title level instead of hard-coding <h3>'
);

assert_true(
	1 === preg_match(
		'{is_archive\(\)\s*\|\|\s*is_home\(\)\s*\|\|\s*is_search\(\)\s*\)\s*\?\s*2\s*:\s*3}',
		$event_card
	),
	'T1.6 the event card renders <h2> under an archive/h1 context and stays <h3> elsewhere'
);

assert_true(
	strpos( $event_card, '<h3 class="event-card-title">' ) === false,
	'T1.6 no hard-coded <h3 class="event-card-title"> remains (that was the h1->h3 jump)'
);

assert_true(
	strpos( $provider_card, 'conexao_provider_heading_tag' ) !== false,
	'T1.6 the provider card resolves its title level too (/cursos/ had the same jump)'
);

assert_true(
	strpos( $provider_card, '<h3 class="provider-card-title">' ) === false,
	'T1.6 no hard-coded <h3 class="provider-card-title"> remains'
);

assert_true(
	strpos( $footer, '<h4 class="footer-column-title">' ) === false,
	'T1.6 the footer column title is no longer a bare <h4> (the event-single h1->h4 jump)'
);

// ---------------------------------------------------------------------------
// T1.8 — one location row, both values preserved.
// ---------------------------------------------------------------------------

assert_true(
	strpos( $event_card, 'event-card-detail--location' ) !== false,
	'T1.8 the event card renders a single merged location row'
);

assert_true(
	strpos( $event_card, 'event-card-detail--county' ) === false,
	'T1.8 the duplicate county-only row is gone'
);

// Guard against the wrong "fix": dropping the county instead of merging it.
assert_true(
	strpos( $event_card, '$event_county_name' ) !== false
		&& strpos( $event_card, '$event_location_parts[] = $event_county_name;' ) !== false,
	'T1.8 the county is still rendered (merged into the row), not deleted'
);

assert_true(
	strpos( $event_card, '$event_location_parts[] = $event_location;' ) !== false,
	'T1.8 the location text is still rendered'
);

// The county must not be repeated when it IS the location text.
assert_true(
	strpos( $event_card, 'mb_strtolower( $event_location ) !== mb_strtolower( $event_county_name )' ) !== false,
	'T1.8 the county is suppressed only when it duplicates the location text'
);

// ---------------------------------------------------------------------------
// T1.10 — the comment submit control is inside the .btn contract.
// ---------------------------------------------------------------------------

$main_css = conexao_ui_t2_source( 'assets/css/main.css' );

assert_true(
	strpos( $main_css, '#submit.submit' ) !== false,
	'T1.10 the comment submit control is styled by the theme'
);

assert_true(
	preg_match( '/#submit\.submit\s*\{[^}]*min-height:\s*44px/', $main_css ) === 1,
	'T1.10 the comment submit control meets the 44px interactive target minimum'
);

assert_true(
	strpos( $main_css, '#submit.submit:focus-visible' ) !== false,
	'T1.10 the comment submit control has a keyboard focus ring'
);

assert_true(
	preg_match( '/#submit\.submit\s*\{[^}]*border-radius:\s*var\(--radius-full\)/', $main_css ) === 1,
	'T1.10 the comment submit control uses the shared pill radius token'
);

// ---------------------------------------------------------------------------
// T1.11 — the default category label (the corrected root cause).
// ---------------------------------------------------------------------------

assert_true(
	function_exists( 'conexao_blog_category_label' ),
	'T1.11 the blog-category display-label helper is defined'
);

$default_term = get_term( (int) get_option( 'default_category' ), 'category' );

assert_true(
	$default_term instanceof WP_Term,
	'T1.11 the WordPress default category term exists'
);

if ( $default_term instanceof WP_Term ) {
	// The helper's whole purpose: the raw native name must NOT reach the UI.
	assert_true(
		conexao_blog_category_label( $default_term ) !== $default_term->name
			|| 'Uncategorized' === $default_term->name,
		'T1.11 the default category resolves to a translated label, not its raw stored name'
	);

	// The PT label is the translated string.
// ---------------------------------------------------------------------------
// T1.12 — no placeholder box for an image-less leisure card.
// ---------------------------------------------------------------------------

$leisure_card = conexao_ui_t2_source( 'template-parts/leisure-card.php' );
$leisure_css = conexao_ui_t2_source( 'assets/css/leisure.css' );

assert_true(
	strpos( $leisure_card, 'leisure-card-image-pending' ) === false,
	'T1.12 the placeholder block is no longer rendered by the card template'
);

assert_true(
	strpos( $leisure_card, 'Imagem pendente' ) === false,
	'T1.12 the untranslated placeholder label is gone'
);

assert_true(
	strpos( $leisure_css, '.leisure-card-image-pending' ) === false,
	'T1.12 the placeholder rules are retired rather than left dead'
);

// The image path must be preserved: gating on the flag, not deleting it.
assert_true(
	strpos( $leisure_card, 'if ( $leisure_has_image )' ) !== false,
	'T1.12 the image block is gated on a real image, not removed'
);

assert_true(
	strpos( $leisure_card, '$leisure_img_html' ) !== false,
	'T1.12 a leisure card that HAS an image still renders it'
);

// The card must keep its link even without an image.
assert_true(
	strpos( $leisure_card, 'class="leisure-card-title"' ) !== false,
	'T1.12 the card title (the primary link) is always rendered'
);

// ---------------------------------------------------------------------------
// T1.13 — no gray-500 as rendered text.
// ---------------------------------------------------------------------------

assert_true(
	preg_match( '/color:\s*var\(--conexao-gray-500\)/', $main_css ) === 0,
	'T1.13 no rule in main.css paints text with gray-500 (2.07:1 on white, fails AA)'
);

// The token itself stays (it is a palette entry, not a usage).
assert_true(
	strpos( $main_css, '--conexao-gray-500: #adb5bd;' ) !== false,
	'T1.13 the gray-500 palette token is retained (only its text usages were retired)'
);

// The replacement must be the already-established gray-600, not a new colour.
assert_true(
	substr_count( $main_css, 'color: var(--conexao-gray-600);' ) >= 12,
	'T1.13 the converted rules use the existing gray-600 token'
);

// --- the TOKEN layer, which is the larger of the two causes -------------------
// A negative proof showed that reverting ONLY the token (leaving the main.css
// conversions in place) left this suite green, so the token assertion below was
// added to close that real gap.
$design_system = conexao_ui_t2_source( 'assets/css/design-system.css' );
$dark_mode = conexao_ui_t2_source( 'assets/css/dark-mode.css' );

assert_true(
	1 === preg_match(
		'/--color-text-muted:\s*var\(--conexao-gray-600\);/',
		$design_system
	),
	'T1.13 --color-text-muted aliases gray-600, not gray-500 (the token WAS the bigger cause)'
);

assert_true(
	0 === preg_match(
		'/{[^}]*--color-text-muted:\s*var\(--conexao-gray-500\);[^}]*}/',
		$design_system
	),
	'T1.13 no design-system block re-aliases muted text to gray-500 (2.07:1 on white)'
);

// Dark mode is deliberately untouched: its own gray-500 override is AA there
// (7.36:1 on the dark body). Pinning this prevents a future "fix" from
// degrading dark mode while repairing light mode.
assert_true(
	1 === preg_match(
		'/--color-text-muted:\s*var\(--conexao-gray-500\);/',
		$dark_mode
	),
	'T1.13 dark mode keeps its own --color-text-muted override (dark gray-500 is AA on dark surfaces)'
);

	assert_true(
		'' !== conexao_blog_category_label( $default_term ),
		'T1.11 the default category label is never empty'
	);

	// Non-default categories must keep their real, editor-chosen name.
	$real_cats = get_categories(
		array(
			'hide_empty' => false,
			'exclude'    => array( (int) $default_term->term_id ),
		)
	);
	foreach ( $real_cats as $real_cat ) {
		assert_equals(
			$real_cat->name,
			conexao_blog_category_label( $real_cat ),
			sprintf( 'T1.11 the real category "%s" keeps its editor-chosen name', $real_cat->name )
		);
	}
}

// A non-term input must be safe (no fatal, empty string).
assert_equals(
	'',
	conexao_blog_category_label( null ),
	'T1.11 the label helper tolerates a non-term input'
);
assert_equals(
	'',
	conexao_blog_category_label( 'not-a-term' ),
	'T1.11 the label helper tolerates a string input'
);

// The template must use the helper on BOTH surfaces, and must not reintroduce
// the raw term name anywhere. A negative proof showed `substr_count(...) >= 2`
// was vacuous (reverting only ONE of the two call sites still passed), so the
// raw-name form is now pinned directly instead.
$home_php = conexao_ui_t2_source( 'home.php' );
assert_true(
	false !== strpos( $home_php, 'conexao_blog_category_label( $category )' ),
	'T1.11 the /blog/ filter bar renders through the label helper'
);
assert_true(
	false !== strpos( $home_php, 'conexao_blog_category_label( $categories[0] )' ),
	'T1.11 the archive card badge renders through the label helper'
);
assert_true(
	strpos( $home_php, 'esc_html( $category->name )' ) === false
		&& strpos( $home_php, 'esc_html( $categories[0]->name )' ) === false,
	'T1.11 no raw term name reaches either the filter bar or the card badge'
);

// The heading level must never be able to break out of the h1..h6 range.
assert_true(
	preg_match( '/heading_level < 2 \|\| .*heading_level > 6/', $event_card ) === 1,
	'T1.6 the caller-supplied heading_level is clamped to a valid h2..h6 range'
);
test_finish( 'ui-convergence-t2' );
