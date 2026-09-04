<?php
/**
 * Tests for the Phase 3C leisure attribute environment normalization.
 *
 * Verifies `conexao_leisure_attributes()` (functions.php) and its canonical
 * normalization rule `conexao_leisure_normalize_environment_attributes()`:
 *
 *  - `Interior + exterior` (slug `interior-exterior`) is a combined/derived
 *    state. When it applies, the individual `Interior` and `Exterior`
 *    attributes NEVER render alongside it — on the archive card, on the
 *    single page, or on any future surface.
 *  - Without the combined attribute, `Interior` and `Exterior` render
 *    independently (and may coexist).
 *  - Normalization runs AFTER the taxonomy terms and the legacy checkbox
 *    meta fallback are merged, so redundant legacy signals
 *    (`_leisure_indoor` / `_leisure_outdoor`) are removed too.
 *  - Unrelated attributes (Famílias, Estacionamento, Acessível, ...) are
 *    untouched, and the card priority contract is unchanged.
 *  - The archive card and the single page both render through the shared
 *    helper, so they can never disagree.
 *
 * The script creates temporary posts (and terms only when missing) and
 * deletes them at the end; it never modifies existing leisure records and
 * performs no destructive taxonomy cleanup.
 *
 * Requirements: conexao-data-model (leisure CPT + conexao_leisure_attribute
 * taxonomy) and the active theme.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-leisure-attribute-normalization.php
 */

// --- Bootstrap WordPress (plugins + theme option). ---
$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

// Load the active theme so the helpers under test are defined.
// The active theme is not auto-loaded by wp-load.php in a CLI context.
$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed        = 0;
$failed        = 0;
$created_posts = array();
$created_terms = array();

function t_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

function t_section( $title ) {
	echo "\n=== {$title} ===\n";
}

/** @return int Term ID for a conexao_leisure_attribute term (created if missing). */
function attr_test_term( $name, $slug ) {
	global $created_terms;
	$existing = term_exists( $slug, 'conexao_leisure_attribute' );
	if ( $existing && ! is_wp_error( $existing ) ) {
		$term = get_term( (int) ( is_array( $existing ) ? $existing['term_id'] : $existing ), 'conexao_leisure_attribute' );
		return (int) $term->term_id;
	}
	$inserted = wp_insert_term( $name, 'conexao_leisure_attribute', array( 'slug' => $slug ) );
	if ( is_wp_error( $inserted ) ) {
		echo "  FATAL: could not create attribute term: {$inserted->get_error_message()}\n";
		exit( 1 );
	}
	$created_terms[] = (int) $inserted['term_id'];
	return (int) $inserted['term_id'];
}

/** @return int Created (temporary) leisure post ID. */
function attr_test_create_leisure( $title ) {
	global $created_posts;
	$post_id = wp_insert_post(
		array(
			'post_type'   => 'leisure',
			'post_title'  => '[ATTR TEST] ' . $title,
			'post_status' => 'publish',
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		echo "  FATAL: could not create test leisure: {$post_id->get_error_message()}\n";
		exit( 1 );
	}
	$created_posts[] = (int) $post_id;
	return (int) $post_id;
}

/** Assign attribute terms by slug to a test post. */
function attr_test_assign( $post_id, $slugs ) {
	$term_ids = array();
	foreach ( $slugs as $slug ) {
		$term_ids[] = (int) attr_test_term( $slug, $slug );
	}
	wp_set_object_terms( $post_id, $term_ids, 'conexao_leisure_attribute' );
}

/** Assert the helper returns exactly the expected slug set. */
function attr_test_assert_set( $post_id, $expected_slugs, $message ) {
	$actual = array_keys( conexao_leisure_attributes( $post_id ) );
	sort( $actual );
	$expected = (array) $expected_slugs;
	sort( $expected );
	t_assert( $actual === $expected, $message . ' — got [' . implode( ', ', $actual ) . ']' );
}

// ---------------------------------------------------------------------------
// Vocabulary
// ---------------------------------------------------------------------------
attr_test_term( 'Interior + exterior', 'interior-exterior' );

// ---------------------------------------------------------------------------
// 1-3. Single environment attributes
// ---------------------------------------------------------------------------
t_section( 'Single Environment Attributes' );

$p = attr_test_create_leisure( 'so interior' );
attr_test_assign( $p, array( 'interior' ) );
attr_test_assert_set( $p, array( 'interior' ), 'only Interior renders Interior' );

$p = attr_test_create_leisure( 'so exterior' );
attr_test_assign( $p, array( 'exterior' ) );
attr_test_assert_set( $p, array( 'exterior' ), 'only Exterior renders Exterior' );

$p = attr_test_create_leisure( 'so combo' );
attr_test_assign( $p, array( 'interior-exterior' ) );
attr_test_assert_set( $p, array( 'interior-exterior' ), 'only Interior + exterior renders Interior + exterior' );

// ---------------------------------------------------------------------------
// 4. Interior + Exterior without the combined attribute
// ---------------------------------------------------------------------------
t_section( 'Interior and Exterior Together (no combined attribute)' );

$p = attr_test_create_leisure( 'interior e exterior' );
attr_test_assign( $p, array( 'interior', 'exterior' ) );
attr_test_assert_set( $p, array( 'interior', 'exterior' ), 'Interior + Exterior without combined renders both' );

// ---------------------------------------------------------------------------
// 5-7. Combined attribute wins over the individual ones (taxonomy)
// ---------------------------------------------------------------------------
t_section( 'Combined Attribute Suppresses Individuals (taxonomy)' );

$p = attr_test_create_leisure( 'combo + interior' );
attr_test_assign( $p, array( 'interior-exterior', 'interior' ) );
attr_test_assert_set( $p, array( 'interior-exterior' ), 'Interior + exterior + Interior renders only the combined' );

$p = attr_test_create_leisure( 'combo + exterior' );
attr_test_assign( $p, array( 'interior-exterior', 'exterior' ) );
attr_test_assert_set( $p, array( 'interior-exterior' ), 'Interior + exterior + Exterior renders only the combined' );

$p = attr_test_create_leisure( 'combo + interior + exterior' );
attr_test_assign( $p, array( 'interior-exterior', 'interior', 'exterior' ) );
attr_test_assert_set( $p, array( 'interior-exterior' ), 'Interior + exterior + Interior + Exterior renders only the combined' );

// ---------------------------------------------------------------------------
// 8. Combined attribute + unrelated attributes
// ---------------------------------------------------------------------------
t_section( 'Combined Attribute With Unrelated Attributes' );

$p = attr_test_create_leisure( 'combo + unrelated' );
attr_test_assign( $p, array( 'interior-exterior', 'familias', 'estacionamento', 'acessivel' ) );
attr_test_assert_set(
	$p,
	array( 'interior-exterior', 'familias', 'estacionamento', 'acessivel' ),
	'combined appears once and unrelated attributes are preserved'
);

$names = conexao_leisure_attributes( $p );
t_assert( 'Interior + exterior' === $names['interior-exterior'], 'combined display name is exactly "Interior + exterior"' );

// ---------------------------------------------------------------------------
// 9. Legacy meta fallback (merged BEFORE normalization)
// ---------------------------------------------------------------------------
t_section( 'Legacy Meta Fallback' );

// The exact production pattern (e.g. Huntington Castle): combined term plus
// legacy indoor/outdoor checkbox meta.
$p = attr_test_create_leisure( 'combo + legacy meta' );
attr_test_assign( $p, array( 'interior-exterior' ) );
update_post_meta( $p, '_leisure_indoor', '1' );
update_post_meta( $p, '_leisure_outdoor', '1' );
attr_test_assert_set( $p, array( 'interior-exterior' ), 'combined term + legacy indoor/outdoor meta renders only the combined' );

// Legacy meta only — no combined attribute: individual attributes still work.
$p = attr_test_create_leisure( 'legacy indoor only' );
update_post_meta( $p, '_leisure_indoor', '1' );
attr_test_assert_set( $p, array( 'interior' ), 'legacy indoor meta alone renders Interior' );

$p = attr_test_create_leisure( 'legacy outdoor only' );
update_post_meta( $p, '_leisure_outdoor', '1' );
attr_test_assert_set( $p, array( 'exterior' ), 'legacy outdoor meta alone renders Exterior' );

$p = attr_test_create_leisure( 'legacy indoor + outdoor' );
update_post_meta( $p, '_leisure_indoor', '1' );
update_post_meta( $p, '_leisure_outdoor', '1' );
attr_test_assert_set( $p, array( 'interior', 'exterior' ), 'legacy indoor + outdoor meta without combined renders both' );

// ---------------------------------------------------------------------------
// 10. Card rendering — real template part
// ---------------------------------------------------------------------------
t_section( 'Card Rendering (leisure-card.php)' );

$p = attr_test_create_leisure( 'card combo' );
attr_test_assign( $p, array( 'interior-exterior', 'familias' ) );
update_post_meta( $p, '_leisure_indoor', '1' );
update_post_meta( $p, '_leisure_outdoor', '1' );

$card_html = '';
$test_post = get_post( $p );
if ( $test_post instanceof WP_Post ) {
	setup_postdata( $GLOBALS['post'] = $test_post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	ob_start();
	include get_template_directory() . '/template-parts/leisure-card.php';
	$card_html = ob_get_clean();
	wp_reset_postdata();
}

t_assert( '' !== $card_html, 'card template renders without errors' );
t_assert( 1 === substr_count( $card_html, 'Interior + exterior' ), 'card renders the combined attribute exactly once' );
t_assert( false === strpos( $card_html, '>Interior<' ), 'card does not render redundant Interior' );
t_assert( false === strpos( $card_html, '>Exterior<' ), 'card does not render redundant Exterior' );
t_assert( false !== strpos( $card_html, 'Famílias' ), 'card still renders unrelated attributes (Famílias)' );

// Card priority contract preserved: without the combined attribute, the
// individual Interior renders on the card in its usual priority slot.
$p = attr_test_create_leisure( 'card interior only' );
attr_test_assign( $p, array( 'interior', 'familias', 'estacionamento' ) );
$test_post = get_post( $p );
if ( $test_post instanceof WP_Post ) {
	setup_postdata( $GLOBALS['post'] = $test_post ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	ob_start();
	include get_template_directory() . '/template-parts/leisure-card.php';
	$card_html = ob_get_clean();
	wp_reset_postdata();
}
t_assert( false !== strpos( $card_html, '>Interior<' ), 'card still renders Interior when it is the only environment attribute' );

// ---------------------------------------------------------------------------
// 11. Single page / card agreement through the shared helper
// ---------------------------------------------------------------------------
t_section( 'Single Page and Card Use the Shared Helper' );

$card_src   = file_get_contents( get_template_directory() . '/template-parts/leisure-card.php' );
$single_src = file_get_contents( get_template_directory() . '/single-leisure.php' );
t_assert( false !== strpos( $card_src, 'conexao_leisure_attributes(' ), 'leisure-card.php resolves attributes via the shared helper' );
t_assert( false !== strpos( $single_src, 'conexao_leisure_attributes(' ), 'single-leisure.php resolves attributes via the shared helper' );

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
foreach ( $created_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
foreach ( $created_terms as $term_id ) {
	wp_delete_term( $term_id, 'conexao_leisure_attribute' );
}

$leftover = get_posts(
	array(
		'post_type'   => 'leisure',
		'post_status' => 'any',
		's'           => '[ATTR TEST]',
		'numberposts' => 5,
		'fields'      => 'ids',
	)
);
t_assert( empty( $leftover ), 'no temporary test posts remain' );

echo "\n----------------------------------------\n";
echo "RESULT: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
