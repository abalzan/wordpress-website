<?php
/**
 * Homepage shortcut consolidation (UI/UX Improvement 2).
 *
 * Verifies that the "Acesso Rápido" primary grid and the "Precisa de ajuda?"
 * help section are derived from the SAME canonical card definitions
 * (`$quick_access_cards` in front-page.php, matched by the inert `key` slug)
 * and that no destination is rendered as two tiles.
 *
 * Read-only. No content is created or modified.
 *
 * Usage (from the project root):
 *   php wp-content/themes/conexao-br-irlanda/tests/test-homepage-shortcut-consolidation.php
 *
 * @package conexao-br-irlanda
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed = 0;
$failed = 0;

/**
 * Switch the request language context the way Polylang does while routing.
 *
 * Polylang's `curlang` switches language-aware helpers (URLs, queries), but it
 * does not by itself switch the WordPress translation catalogue: `__()` still
 * resolves against the catalogue loaded for the process. Load the committed
 * `languages/<locale>.mo` for the requested language explicitly (the same
 * pattern other suites use), so an EN render really renders EN strings rather
 * than passing on an English HTML comment.
 *
 * @param string $slug Language slug.
 * @return string|false Previous language slug.
 */
function hsc_set_language( $slug ) {
	if ( ! function_exists( 'PLL' ) || ! PLL() ) {
		return false;
	}

	$model    = PLL()->model;
	$previous = isset( PLL()->curlang->slug ) ? PLL()->curlang->slug : false;

	PLL()->curlang = $model->get_language( $slug );

	$locale    = ( 'en' === $slug ) ? 'en_US' : 'pt_BR';
	$catalogue = get_template_directory() . '/languages/' . $locale . '.mo';
	if ( function_exists( 'unload_textdomain' ) && function_exists( 'load_textdomain' ) && is_file( $catalogue ) ) {
		unload_textdomain( 'conexao-br-irlanda' );
		load_textdomain( 'conexao-br-irlanda', $catalogue, $locale );
	}

	return $previous;
}

if ( ! function_exists( 'conexao_current_language_slug' ) ) {
	exit( 1 );
}

test_prerequisite_hint( 'polylang' );
test_require( function_exists( 'pll_current_language' ), 'polylang', 'test prerequisite is available: function_exists( pll_current_language )', 'activate the Polylang plugin' );

/**
 * Render front-page.php and split its shortcut links into the primary grid
 * and the help section.
 *
 * @param string $lang 'pt' or 'en'.
 * @return array{qa: string[], help: string[], html: string}
 */
function hsc_render_shortcuts( $lang ) {
	hsc_set_language( $lang );

	ob_start();
	$template = trailingslashit( get_stylesheet_directory() ) . 'front-page.php';
	include $template;
	$html = (string) ob_get_clean();

	hsc_set_language( 'pt' );

	$qa = array();
	if ( preg_match_all( '/<a\s+href="([^"]+)"\s+class="[^"]*quick-access-card[^"]*"/', $html, $m ) ) {
		$qa = $m[1];
	}

	$help = array();
	if ( preg_match_all( '/<a\s+href="([^"]+)"\s+class="[^"]*help-shortcut[^"]*"/', $html, $m ) ) {
		$help = $m[1];
	}

	return array( 'qa' => array_values( $qa ), 'help' => array_values( $help ), 'html' => $html );
}

/**
 * Assert no PHP warning/notice leaked into the rendered output.
 */
function hsc_no_php_noise( $html, $lang ) {
	assert_true(
		false === stripos( $html, 'Warning:' ) && false === stripos( $html, 'Notice:' ) && false === stripos( $html, 'Fatal error:' ),
		"{$lang}: no PHP warnings/notices in rendered homepage"
	);
}

// ---------------------------------------------------------------------------
// Run for both languages.
foreach ( array( 'pt', 'en' ) as $lang ) {
	$rendered = hsc_render_shortcuts( $lang );
	$qa       = $rendered['qa'];
	$help     = $rendered['help'];
	$html     = $rendered['html'];

	$label = strtoupper( $lang );

	hsc_set_language( $lang );
	$expected_jobs_url = conexao_lang_url( '/empregos/' );
	$expected_qa       = array(
		conexao_lang_url( '/lazer/' ),
		$expected_jobs_url,
		conexao_lang_url( '/eventos/' ),
		conexao_lang_url( '/cursos/' ),
		conexao_lang_url( '/apoiadores/' ),
		conexao_lang_url( '/blog/' ),
		conexao_get_guides_archive_url(),
	);
	$expected_help = array(
		conexao_get_guide_category_url( 'moradia', 'moradia' ),
		conexao_get_guide_category_url( 'documentos', 'documentos' ),
		conexao_get_guide_category_url( 'saude', 'saude' ),
		conexao_get_guide_category_url( 'beneficios', 'beneficios' ),
		conexao_get_guide_category_url( 'financas', 'financas' ),
	);
	hsc_set_language( 'pt' );

	// 1. Homepage renders in both languages.
	assert_true( '' !== $html, "{$label}: homepage renders" );

	// 2. No PHP noise.
	hsc_no_php_noise( $html, $label );

	// 3. Empregos replaces Transporte without changing the other card order.
	assert_true( $expected_qa === $qa, "{$label}: quick-access links keep their order with Empregos in the former Transporte slot" );
	assert_true( $expected_help === $help, "{$label}: help shortcuts remain unchanged apart from moving Empregos to Quick Access" );

	$jobs_card_pattern = '/<a href="' . preg_quote( esc_url( $expected_jobs_url ), '/' ) . '" class="([^"]*quick-access-card[^"]*)">(.*?)<\/a>/s';
	$jobs_card_count   = preg_match_all( $jobs_card_pattern, $html, $jobs_card_matches, PREG_SET_ORDER );
	assert_true( 1 === $jobs_card_count, "{$label}: exactly one Empregos card renders in Quick Access" );
	if ( 1 === $jobs_card_count ) {
		$expected_title       = ( 'en' === $lang ) ? 'Jobs' : 'Empregos';
		$expected_description = ( 'en' === $lang ) ? 'Job opportunities' : 'Oportunidades de trabalho';
		$jobs_card_classes    = $jobs_card_matches[0][1];
		$jobs_card_markup     = $jobs_card_matches[0][2];

		assert_true(
			false !== strpos( $jobs_card_markup, '<span class="quick-access-label">' . esc_html( $expected_title ) . '</span>' ),
			"{$label}: Empregos card has the localized title"
		);
		assert_true(
			false !== strpos( $jobs_card_markup, '<span class="quick-access-desc">' . esc_html( $expected_description ) . '</span>' ),
			"{$label}: Empregos card has the localized description"
		);
		assert_true(
			false !== strpos( $jobs_card_markup, '<svg viewBox="0 0 24 24"' )
			&& false !== strpos( $jobs_card_markup, 'aria-hidden="true"' )
			&& false !== strpos( $jobs_card_markup, '<rect x="3" y="7" width="18" height="14" rx="2"></rect>' ),
			"{$label}: Empregos card has an aria-hidden briefcase SVG"
		);
		assert_true(
			false !== strpos( $jobs_card_classes, 'quick-access-card--mobile-priority' )
			&& false !== strpos( $jobs_card_classes, 'quick-access-card--priority-empregos' )
			&& false === strpos( $jobs_card_classes, 'quick-access-card--mobile-only' ),
			"{$label}: Empregos keeps its priority behavior without becoming mobile-only"
		);
	}

	// 4. No shortcut renders in both the grid and the help section (disjoint).
	$inter = array_intersect( $qa, $help );
	assert_true( array() === $inter, "{$label}: no shortcut renders in both the grid and the help section" );

	// 5. Every shortcut destination appears at most once across the two sections.
	$all = array_merge( $qa, $help );
	assert_true( count( $all ) === count( array_unique( $all ) ), "{$label}: every destination appears at most once" );

	// 6. Localized headings.
	if ( 'pt' === $lang ) {
		assert_true( false !== strpos( $html, 'Acesso Rápido' ), "{$label}: 'Acesso Rápido' heading present" );
		assert_true( false !== strpos( $html, 'Precisa de ajuda?' ), "{$label}: 'Precisa de ajuda?' heading present" );
	} else {
		// Match the heading itself, not the English HTML comment that also says
		// "Quick Access": a comment-only match would let a PT render pass the EN
		// assertion and hide a missing catalogue.
		assert_true( false !== strpos( $html, '>Quick Access</h2>' ), "{$label}: 'Quick Access' heading present" );
		assert_true( false !== strpos( $html, '>Need help?</h2>' ), "{$label}: 'Need help?' heading present" );
	}

	// 7. Both shortcut groups render at least one link.
	assert_true( count( $qa ) > 0 && count( $help ) > 0, "{$label}: both shortcut groups render at least one link" );

	// 8. The help grid does NOT contain any QA destination (consolidation goal).
	$overlap = array_intersect( $qa, $help );
	assert_true( array() === $overlap, "{$label}: no duplicate destination across QA and help" );

	// 9. Together, QA + help contain all 12 remaining destinations.
	$combined = array_unique( array_merge( $qa, $help ) );
	assert_true( 12 === count( $combined ), "{$label}: all 12 unique destinations present (got " . count( $combined ) . ')' );
}

test_finish( 'homepage-shortcut-consolidation' );
