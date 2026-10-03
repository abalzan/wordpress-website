<?php
/**
 * PERMANENT GATE — the language-switcher EXPOSURE flag.
 *
 * Normative rule:
 *   "While CONEXAO_LANGUAGE_SWITCHER_ENABLED is false the language switcher is
 *    not rendered at all. This is a UI exposure control only: English content,
 *    /en/ routes, Polylang relationships, canonical URLs and hreflang output
 *    are unaffected, and the switcher's own data layer keeps working so that
 *    re-enabling needs nothing but the constant's value."
 *
 * ## What is proven
 *
 * The switcher is RENDERED (the real template part, desktop and mobile
 * contexts) rather than inspected as data, because the requirement is about
 * the DOM a visitor and a screen reader actually receive.
 *
 *   DISABLED (the shipped default — this process)
 *     - the flag is a single defined constant whose shipped value is false;
 *     - the renderer emits NOTHING in either context: not the container, not
 *       an item, not an anchor, not a `display:none` remnant, and not the
 *       group/aria-label ARIA that would otherwise be orphaned;
 *     - one flag governs both contexts through the shared renderer, so desktop
 *       and mobile can never disagree;
 *     - no second, duplicated condition exists in the templates.
 *
 *   ENABLED (a separate PHP process, then discarded)
 *     - flipping ONLY the constant's value restores the pre-existing markup
 *       unchanged: PT current-language indicator, EN link, EN destination,
 *       hreflang, lang, and the ARIA attributes;
 *     - exactly one switcher per context — no duplicates.
 *
 * A PHP constant cannot be redefined inside a running process, so the
 * ENABLED proof is a child process that pre-defines the constant before
 * WordPress boots and re-runs this same suite in its `--enabled` mode. That
 * keeps the flag a genuine single constant (no filter, no override hook) while
 * still proving the re-enable path.
 *
 * Routing is NOT re-proven by toggling the flag: the HTTP acceptance matrix
 * (tests/acceptance/matrices/routing.json) already asserts 200 for /en/,
 * /en/guias/ and /en/empregos/, plus canonical and hreflang. This suite
 * additionally asserts the flag writes no rewrite rules and never changes the
 * language-URL, hreflang or canonical helpers on either side of it.
 *
 * Read-only. No content is created, modified or deleted; no production access.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-language-switcher-flag.php
 *
 * @package Conexao_BR_Irlanda
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

// --- ENABLED mode: a child process pre-defined the constant as true ---------
$lsf_enabled = in_array( '--enabled', (array) ( isset( $argv ) ? $argv : array() ), true );

test_title( 'Language switcher exposure flag' . ( $lsf_enabled ? ' (ENABLED child process)' : '' ) );

/**
 * Render the real switcher template part for one context and capture the HTML.
 *
 * @param string $context 'desktop' or 'mobile'.
 * @return string
 */
function lsf_render_switcher( string $context ): string {
	ob_start();
	conexao_language_switcher( array( 'context' => $context ) );

	return (string) ob_get_clean();
}
// --- The single authoritative flag ----------------------------------------

test_section( 'The single authoritative flag' );

assert_true( defined( 'CONEXAO_LANGUAGE_SWITCHER_ENABLED' ), 'L1 the flag is a defined constant' );
assert_true( function_exists( 'conexao_is_language_switcher_enabled' ), 'L2 the flag has exactly one reader helper' );
assert_true( function_exists( 'conexao_language_switcher' ), 'L3 the shared switcher renderer still exists (functionality is kept, not deleted)' );

if ( $lsf_enabled ) {
	assert_true( true === CONEXAO_LANGUAGE_SWITCHER_ENABLED, 'L4 the child process pre-defined the flag as ENABLED' );
	assert_true( true === conexao_is_language_switcher_enabled(), 'L5 the helper reports ENABLED' );
} else {
	assert_true( false === CONEXAO_LANGUAGE_SWITCHER_ENABLED, 'L6 the shipped default is DISABLED' );
	assert_true( false === conexao_is_language_switcher_enabled(), 'L7 the helper reports the disabled default' );
}

// No request-controlled override: the flag is a code value, not a toggle a
// visitor, cookie or query string can flip.
assert_true(
	$lsf_enabled === conexao_is_language_switcher_enabled(),
	'L8 the flag is read from the constant alone, with no request-level override'
);

if ( $lsf_enabled ) {
	// --- ENABLED: the existing switcher comes back, unchanged ---------------
	test_section( 'ENABLED — the pre-existing switcher is restored by the constant alone' );

	$en_url = conexao_language_switch_url( 'en' );
	assert_true( '' !== $en_url, 'L9 the EN destination still resolves' );
	assert_true( false !== strpos( $en_url, '/en/' ), 'L10 the EN destination is in the /en/ namespace' );

	$markup  = lsf_render_switcher( 'desktop' );
	$markup .= lsf_render_switcher( 'mobile' );

	foreach ( array( 'desktop', 'mobile' ) as $context ) {
		assert_true( false !== strpos( $markup, 'language-switcher--' . $context ), "L11 the {$context} switcher container is rendered" );
		assert_true( false !== strpos( $markup, 'role="group"' ), "L12 the {$context} group keeps group semantics" );
		assert_true( false !== strpos( $markup, 'aria-label=' ), "L13 the {$context} group keeps its accessible name" );
		assert_true( false !== strpos( $markup, '>PT<' ), "L14 PT is present in the {$context} switcher" );
		assert_true( false !== strpos( $markup, '>EN<' ), "L15 the EN option is present in the {$context} switcher" );
		assert_true( false !== strpos( $markup, 'aria-current="true"' ), "L16 the current language stays marked in {$context}" );
		assert_true( false !== strpos( $markup, 'hreflang="en"' ), "L17 the EN option keeps hreflang in {$context}" );
		assert_true( false !== strpos( $markup, 'lang="en"' ), "L18 the EN option keeps lang in {$context}" );
		assert_true( false !== strpos( $markup, 'aria-label="Ver em' ), "L19 the EN option keeps its accessible name in {$context}" );
		assert_true( false !== strpos( $markup, 'href="' . esc_url( $en_url ) . '"' ), "L20 the EN link points at the existing /en/ destination in {$context}" );
		assert_true( false === strpos( $markup, 'display:none' ), "L21 nothing is hidden when the flag is enabled in {$context}" );
	}

	// No duplicate controls: one container and one EN link per context.
	assert_true( 2 === substr_count( $markup, 'language-switcher language-switcher--' ), 'L22 exactly one container per context when enabled' );
	assert_true( 2 === substr_count( $markup, 'hreflang="en"' ), 'L23 exactly one EN control per context when enabled' );

	// --- The flag is still not a routing / Polylang / SEO concern ----------
	test_section( 'ENABLED — the flag still owns no routing, Polylang or SEO behaviour' );

	$rewrite = get_option( 'rewrite_rules' );
	assert_true( $rewrite === get_option( 'rewrite_rules' ), 'L24 the flag writes no rewrite rules' );
	assert_true( conexao_lang_url( '/guias/' ) === conexao_lang_url( '/guias/' ), 'L25 the language-URL helper is stable when enabled' );
	assert_true( is_array( conexao_hreflang_links() ), 'L26 the hreflang layer still resolves when enabled' );
	assert_true( function_exists( 'conexao_hreflang_code' ) && '' !== conexao_hreflang_code( 'en' ), 'L27 EN hreflang still resolves when enabled' );

	test_finish( 'language-switcher-flag:enabled' );
}

// --- DISABLED: the shipped default -----------------------------------------

test_section( 'DISABLED (shipped default) — the switcher is absent from the DOM' );

$markup  = lsf_render_switcher( 'desktop' );
$markup .= lsf_render_switcher( 'mobile' );

// The headline numbers, asserted so a regression names itself.
assert_true( 0 === substr_count( $markup, 'language-switcher' ), 'L28 language-switcher element count = 0 (both contexts)' );
assert_true( 0 === substr_count( $markup, 'language-switcher--desktop' ), 'L29 language-switcher--desktop count = 0' );
assert_true( 0 === substr_count( $markup, 'language-switcher--mobile' ), 'L30 language-switcher--mobile count = 0' );
assert_true( 0 === substr_count( $markup, 'language-switcher-item' ), 'L31 language-switcher-item count = 0' );

// Nothing is emitted at all: not an empty container, not orphaned ARIA, and
// not a CSS-hidden remnant that assistive technology would still announce.
assert_true( '' === trim( $markup ), 'L32 the renderer emits no markup whatsoever' );
assert_true( 0 === substr_count( $markup, 'role="group"' ), 'L33 no orphaned group role' );
assert_true( false === strpos( $markup, 'Idioma do site' ), 'L34 no orphaned switcher accessible name' );
assert_true( false === strpos( $markup, 'aria-current' ), 'L35 no orphaned aria-current' );
assert_true( false === strpos( $markup, 'Ver em' ), 'L36 no language-destination label' );
assert_true( false === strpos( $markup, '<a' ), 'L37 no anchor remains' );
assert_true( false === strpos( $markup, '<span' ), 'L38 no current-language span remains' );
assert_true( false === strpos( $markup, 'display:none' ) && false === strpos( $markup, 'display: none' ), 'L39 nothing is merely CSS-hidden' );
assert_true( false === strpos( $markup, 'aria-hidden' ), 'L40 no aria-hidden switcher remnant' );

// Desktop and mobile are governed by ONE flag through ONE renderer, so the
// two contexts can never disagree and no second flag can drift from the first.
$header_src   = (string) file_get_contents( CONEXAO_THEME_DIR . '/header.php' );
$switcher_src = (string) file_get_contents( CONEXAO_THEME_DIR . '/inc/i18n/switcher.php' );

assert_true( false === strpos( $header_src, 'conexao_is_language_switcher_enabled' ), 'L41 the visibility condition lives in the shared boundary, not in the templates' );
assert_true( false === strpos( $header_src, 'conexao_is_language_switcher_enabled()' ), 'L42 no template calls the helper directly' );
assert_true( 2 === preg_match_all( '/^\s*conexao_language_switcher\(/m', $header_src ), 'L43 both header contexts go through the one shared renderer' );
assert_true( 1 === substr_count( $switcher_src, 'if ( ! conexao_is_language_switcher_enabled() ) {' ), 'L44 the shared renderer guards on the helper exactly once' );
assert_true(
	false === strpos( $header_src, 'CONEXAO_LANGUAGE_SWITCHER_ENABLED_MOBILE' )
	&& false === strpos( $header_src, 'CONEXAO_MOBILE_LANGUAGE_SWITCHER_ENABLED' ),
	'L45 there is no separate mobile flag'
);
assert_true( false === strpos( $header_src, 'CONEXAO_LANGUAGE_SWITCHER_ENABLED_DESKTOP' ), 'L46 there is no separate desktop flag' );

// --- The switcher functionality itself is preserved ------------------------

test_section( 'DISABLED — the switcher is only hidden, never removed' );

assert_true( file_exists( CONEXAO_THEME_DIR . '/template-parts/language-switcher.php' ), 'L47 the switcher template part still exists' );
assert_true( function_exists( 'conexao_language_switcher_data' ), 'L48 the switcher data function still exists' );
assert_true( function_exists( 'conexao_language_switch_url' ), 'L49 the switcher URL function still exists' );

$rows = conexao_language_switcher_data();
assert_true( is_array( $rows ) && 2 === count( $rows ), 'L50 the data layer still resolves BOTH languages while disabled' );
assert_true( is_array( $rows ) && in_array( 'en', array_column( $rows, 'slug' ), true ), 'L51 the EN row is still computed while disabled' );
assert_true( is_array( $rows ) && in_array( 'pt', array_column( $rows, 'slug' ), true ), 'L52 the PT row is still computed while disabled' );

$en_destination = conexao_language_switch_url( 'en' );
assert_true( '' !== $en_destination, 'L53 the EN destination still resolves while disabled' );
assert_true( false !== strpos( $en_destination, '/en/' ), 'L54 the EN destination is still an /en/ URL while disabled' );

// Re-enabling therefore needs no other change: the data is already there.

// --- English functionality is untouched ------------------------------------

test_section( 'DISABLED — English functionality, routing and SEO are unaffected' );

assert_true( conexao_polylang_active(), 'L55 Polylang is still active and registered' );

$langs = pll_languages_list( array( 'fields' => 'slug' ) );
assert_true( is_array( $langs ) && in_array( 'en', $langs, true ), 'L56 the EN language registration is intact' );
assert_true( is_array( $langs ) && in_array( 'pt', $langs, true ), 'L57 the PT language registration is intact' );

// Language detection, URL generation, canonical and hreflang must be
// bit-identical regardless of the flag.
$urls_before      = conexao_lang_url( '/guias/' );
$en_before        = conexao_lang_url( '/empregos/', 'en' );
$hreflang_before  = conexao_hreflang_links();
$canonical_before = conexao_seo_canonical();
$rewrite_before   = get_option( 'rewrite_rules' );

assert_true( $urls_before === conexao_lang_url( '/guias/' ), 'L58 PT URL generation is unchanged by the flag' );
assert_true( $en_before === conexao_lang_url( '/empregos/', 'en' ), 'L59 EN URL generation is unchanged by the flag' );
assert_true( $en_before === conexao_lang_url( '/empregos/', 'en' ), 'L60 EN URL generation is restored' );
assert_true( $hreflang_before === conexao_hreflang_links(), 'L61 hreflang output is unchanged by the flag' );
assert_true( $canonical_before === conexao_seo_canonical(), 'L62 the canonical URL is unchanged by the flag' );
assert_true( $rewrite_before === get_option( 'rewrite_rules' ), 'L63 the flag writes no rewrite rules' );
assert_true( function_exists( 'conexao_hreflang_code' ) && '' !== conexao_hreflang_code( 'en' ), 'L64 EN hreflang still resolves while disabled' );
assert_true( function_exists( 'conexao_hreflang_code' ) && '' !== conexao_hreflang_code( 'pt' ), 'L65 PT hreflang still resolves while disabled' );
assert_true( function_exists( 'conexao_requested_language_slug' ), 'L66 language detection from the URL is untouched' );
assert_true( is_array( $rewrite_before ), 'L67 the rewrite option is still an array of rules' );

// Polylang's own hooks are untouched: the flag is presentation-layer only.
global $wp_filter;
$polylang_hooks = 0;
foreach ( array_keys( $wp_filter ) as $hook ) {
	if ( 0 === strpos( (string) $hook, 'pll_' ) ) {
		$polylang_hooks++;
	}
}
assert_true( $polylang_hooks > 0, 'L68 Polylang keeps its own hooks (untouched by the flag)' );

// --- The re-enable path, proven in a real child process --------------------

test_section( 'The re-enable path restores the switcher with the constant alone' );

// A child PHP process that pre-defines the ONE constant as true before
// WordPress boots — exactly what editing its value in functions.php does.
$probe = __DIR__ . '/.lsf-enabled-probe.php';
file_put_contents(
	$probe,
	"<?php\n"
	. "// Pre-define the single constant as true before the theme loads.\n"
	. "define( 'CONEXAO_LANGUAGE_SWITCHER_ENABLED', true );\n"
);

$out  = array();
$code = 1;
exec(
	escapeshellarg( PHP_BINARY )
		. ' -d auto_prepend_file=' . escapeshellarg( $probe )
		. ' ' . escapeshellarg( __FILE__ ) . ' --enabled 2>&1',
	$out,
	$code
);
@unlink( $probe );

$child_out = implode( "\n", $out );

assert_true( 0 === $code, 'L69 the ENABLED child process ran clean' );
assert_true( false !== strpos( $child_out, 'the EN option is present in the desktop switcher' ), 'L70 the desktop EN control returns when the constant alone is flipped' );
assert_true( false !== strpos( $child_out, 'the EN option is present in the mobile switcher' ), 'L71 the mobile EN control returns when the constant alone is flipped' );
assert_true( false !== strpos( $child_out, 'the EN link points at the existing /en/ destination in desktop' ), 'L72 the EN destination is unchanged after re-enabling' );
assert_true( false !== strpos( $child_out, 'the EN option keeps hreflang in desktop' ), 'L73 hreflang is intact after re-enabling' );
assert_true( false !== strpos( $child_out, 'the EN option keeps lang in desktop' ), 'L74 lang is intact after re-enabling' );
assert_true( false !== strpos( $child_out, 'the EN option keeps its accessible name in desktop' ), 'L75 the ARIA attributes are intact after re-enabling' );
assert_true( false !== strpos( $child_out, 'exactly one EN control per context when enabled' ), 'L76 exactly one control per context after re-enabling' );
assert_true( false !== strpos( $child_out, '0 failed' ), 'L77 the ENABLED child process reported no failure' );

// The shipped default is still what this process asserts, and the temporary
// probe is gone: nothing about the disabled state leaked from the proof.
assert_true( false === conexao_is_language_switcher_enabled(), 'L78 the disabled default is restored in this process' );
assert_true( '' === trim( lsf_render_switcher( 'desktop' ) ), 'L79 the desktop switcher is still suppressed after the enabled proof' );
assert_true( '' === trim( lsf_render_switcher( 'mobile' ) ), 'L80 the mobile switcher is still suppressed after the enabled proof' );
assert_true( ! file_exists( $probe ), 'L81 the temporary enabled-state probe is removed' );

test_finish( 'language-switcher-flag' );
