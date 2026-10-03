<?php
/**
 * Stage 7.x — English homepage Hero Sponsors data-selection regression test.
 *
 * Verifies the actual B2 selection path used by the shared Hero renderer:
 * an EN request includes published PT sponsors when no EN translation exists,
 * and replaces a PT master with its linked EN translation. The test also
 * proves the renderer receives non-empty data and emits the existing markup.
 *
 * Usage (inside the WordPress container):
 *   php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-stage7-hero-sponsors.php
 *
 * A temporary linked PT/EN sponsor pair is created and force-deleted by a
 * shutdown guard. No production data or sponsor media is changed.
 *
 * @package Conexao_BR_Irlanda
 */


// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed = 0;
$failed = 0;
$fixture_ids = array();

function s7_set_language( $slug ) {
	if ( ! function_exists( 'PLL' ) || ! PLL() || ! isset( PLL()->model ) ) {
		return false;
	}
	$language = PLL()->model->get_language( $slug );
	if ( ! $language ) {
		return false;
	}
	PLL()->curlang = $language;
	return $slug === pll_current_language( 'slug' );
}

function s7_flush_sponsor_cache() {
	delete_transient( 'conexao_home_sponsors' );
	delete_transient( 'conexao_home_sponsors_pt' );
	delete_transient( 'conexao_home_sponsors_en' );
	if ( function_exists( 'conexao_flush_language_cache' ) ) {
		conexao_flush_language_cache( 'conexao_home_sponsors' );
	}
}

function s7_cleanup_fixtures() {
	global $fixture_ids;
	foreach ( array_reverse( $fixture_ids ) as $post_id ) {
		if ( $post_id > 0 && function_exists( 'wp_delete_post' ) ) {
			wp_delete_post( (int) $post_id, true );
		}
	}
	$fixture_ids = array();
	s7_flush_sponsor_cache();
}
register_shutdown_function( 's7_cleanup_fixtures' );


assert_true( function_exists( 'conexao_get_featured_sponsors' ), 'featured sponsor data helper is loaded' );
assert_true( function_exists( 'pll_get_post' ), 'Polylang is active' );
if ( $failed > 0 || ! function_exists( 'conexao_get_featured_sponsors' ) || ! function_exists( 'pll_get_post' ) ) {
	exit( 1 );
}

// The existing dataset has PT source sponsors and no EN sponsor translations.
// The EN request must still receive the B2 fallback set, rather than zero rows.
s7_set_language( 'en' );
s7_flush_sponsor_cache();
$english_sponsors = conexao_get_featured_sponsors();
assert_true( count( $english_sponsors ) > 0, 'EN homepage receives eligible B2 sponsor data', 'count=' . count( $english_sponsors ) );
assert_true( count( $english_sponsors ) === count( array_unique( array_column( $english_sponsors, 'permalink' ) ) ), 'EN sponsor set has no duplicate permalink identities' );

// Polylang's current language is enough for query selection, but gettext is
// also locale-driven; switch the CLI locale explicitly for markup assertions.
switch_to_locale( 'en_US' );
// Reload the theme catalog for the explicit EN CLI locale. The normal HTTP
// request loads this automatically through WordPress's after_setup_theme hook;
// this makes the in-process renderer assertion independent of bootstrap order.
unload_textdomain( 'conexao-br-irlanda' );
load_theme_textdomain( 'conexao-br-irlanda', get_template_directory() . '/languages' );
ob_start();
get_template_part( 'template-parts/featured-sponsors' );
$markup = (string) ob_get_clean();
assert_true( false !== strpos( $markup, 'sponsors-carousel sponsors-carousel--hero' ), 'EN Hero uses the existing sponsors-carousel--hero component' );
assert_true( substr_count( $markup, 'class="sponsors-slide"' ) === count( $english_sponsors ), 'EN Hero renders one slide per selected sponsor' );
// The HTTP acceptance matrix verifies the English gettext labels in the
// real request lifecycle. The CLI WordPress gettext catalog can retain the
// bootstrap locale even after a direct Polylang language switch, so this
// source-level test deliberately focuses on data selection and markup.


// A linked PT/EN pair must produce exactly one EN representation. The EN
// translation wins; the PT master is excluded from the same query result.
$pt_id = wp_insert_post( array(
	'post_type'   => 'sponsor',
	'post_status' => 'publish',
	'post_title'  => '[PH STAGE7] PT Hero Sponsor',
	'post_name'   => 'stage7-pt-hero-sponsor',
), true );
$en_id = wp_insert_post( array(
	'post_type'   => 'sponsor',
	'post_status' => 'publish',
	'post_title'  => '[PH STAGE7] EN Hero Sponsor',
	'post_name'   => 'stage7-en-hero-sponsor',
), true );
if ( is_wp_error( $pt_id ) || is_wp_error( $en_id ) ) {
	assert_true( false, 'temporary linked PT/EN sponsor pair can be created', is_wp_error( $pt_id ) ? $pt_id->get_error_message() : $en_id->get_error_message() );
} else {
	$pt_id = (int) $pt_id;
	$en_id = (int) $en_id;
	$fixture_ids = array( $pt_id, $en_id );
	update_post_meta( $pt_id, '_sponsor_featured', '1' );
	update_post_meta( $en_id, '_sponsor_featured', '1' );
	pll_set_post_language( $pt_id, 'pt' );
	pll_set_post_language( $en_id, 'en' );
	pll_save_post_translations( array( 'pt' => $pt_id, 'en' => $en_id ) );

	s7_flush_sponsor_cache();
	$english_after_pair = conexao_get_featured_sponsors();
	$english_permalinks  = array_column( $english_after_pair, 'permalink' );
	assert_true( in_array( get_permalink( $en_id ), $english_permalinks, true ), 'linked EN sponsor translation is selected' );
	assert_true( ! in_array( get_permalink( $pt_id ), $english_permalinks, true ), 'PT master is replaced, not displayed beside its EN translation' );
	assert_true( 1 === count( array_filter( $english_permalinks, static function ( $url ) use ( $pt_id, $en_id ) {
		return $url === get_permalink( $pt_id ) || $url === get_permalink( $en_id );
	} ) ), 'mixed translation state has one visible representation for the identity' );
}

assert_true( 'conexao_home_sponsors_pt' !== conexao_lang_cache_key( 'conexao_home_sponsors' ) || 'pt' === conexao_current_language_slug(), 'PT and EN sponsor caches remain language-scoped' );

test_finish();
