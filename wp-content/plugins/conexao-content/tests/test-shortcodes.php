<?php
/**
 * Regression coverage for the Conexão Content plugin shortcodes.
 *
 * Protects:
 *  - `[conexao_grid]` renders a card grid for the requested categories,
 *    skips hidden events (`_event_status` of draft/expired/source_not_found/
 *    rejected), links to external URLs when present, and returns '' for an
 *    empty result set.
 *  - `[conexao_blog_categories]` renders category pill links including the
 *    "Todos" link and links to the native blog archive.
 *
 * Creates temporary local test posts and terms, uses only fixtures owned by
 * the test, and cleans up everything it creates. Does not modify production
 * content or persistent site configuration.
 *
 * @package Conexao_Content
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed  = 0;
$failed  = 0;

/** Temporary posts created by this suite (deleted at the end). */
$cc_posts = array();

/** Temporary terms created by this suite, keyed by term ID => taxonomy. */
$cc_terms = array();

test_title( 'Conexão Content: shortcodes' );

// ---------------------------------------------------------------------------
// Preconditions
// ---------------------------------------------------------------------------

test_prerequisite_hint( 'conexao-content' );
test_require(
	function_exists( 'conexao_grid_shortcode' ) && function_exists( 'conexao_blog_categories_shortcode' ),
	'activate-plugin:conexao-content',
	'test prerequisite is available: the content shortcode functions',
	'activate the conexao-content plugin'
);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a temporary post and track it for cleanup.
 *
 * @param array $args wp_insert_post() arguments.
 * @return int Post ID (0 on failure).
 */
function cc_create_post( array $args ) {
	global $cc_posts;

	$post_id = wp_insert_post( $args );
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return 0;
	}

	$cc_posts[] = (int) $post_id;
	return (int) $post_id;
}

/**
 * Create a temporary term and track it for cleanup.
 *
 * Fails loudly when a term with the same slug already exists in the target
 * taxonomy, so the test never silently reuses a pre-existing term.
 *
 * @param string $slug   Unique term slug.
 * @param string $name   Term name.
 * @param string $taxonomy Taxonomy name.
 * @return int Term ID (0 on failure).
 */
function cc_create_term( $slug, $name, $taxonomy ) {
	global $cc_terms;

	if ( term_exists( $slug, $taxonomy ) ) {
		assert_fail(
			"term '{$slug}' already exists in taxonomy '{$taxonomy}' — the test must not reuse a pre-existing term"
		);
		return 0;
	}

	$created_term = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
	if ( is_wp_error( $created_term ) ) {
		return 0;
	}

	$term_id = (int) $created_term['term_id'];
	$cc_terms[ $term_id ] = $taxonomy;
	return $term_id;
}

/**
 * Generate a unique fixture slug to prevent collisions between runs.
 *
 * @param string $prefix Slug prefix.
 * @return string Unique slug.
 */
function cc_unique_slug( $prefix ) {
	return $prefix . '_' . strtolower( wp_generate_password( 8, false, false ) );
}

// ---------------------------------------------------------------------------
// 1. [conexao_grid] — empty result returns ''
// ---------------------------------------------------------------------------

test_section( 'conexao_grid: empty result' );

$html = do_shortcode( '[conexao_grid categories="nao-existe-xyz"]' );
assert_equals( '', $html, 'an unknown category returns an empty grid (no stray markup)' );

// ---------------------------------------------------------------------------
// 2. [conexao_grid] — renders cards for matching posts
// ---------------------------------------------------------------------------

test_section( 'conexao_grid: renders matching posts' );

$cat_slug = cc_unique_slug( 'cc-test-categoria' );
$cat_id = cc_create_term( $cat_slug, 'Categoria Teste', 'conexao_category' );
assert_true( $cat_id > 0, 'a test category term was created' );

$post_id = cc_create_post(
	array(
		'post_type'   => 'guide',
		'post_title'  => '[CC TEST] Guia de teste',
		'post_status' => 'publish',
		'post_content' => 'Conteúdo de teste.',
	)
);
assert_true( $post_id > 0, 'a test guide post was created' );

wp_set_post_terms( $post_id, array( $cat_id ), 'conexao_category' );

$html = do_shortcode( '[conexao_grid categories="' . $cat_slug . '"]' );
assert_true( '' !== $html, 'the grid returns non-empty HTML for a matching category' );
assert_contains( '[CC TEST] Guia de teste', $html, 'the grid card shows the post title' );
assert_contains( 'conexao-card', $html, 'the grid uses the conexao-card wrapper' );
assert_contains( 'href="', $html, 'the grid card links somewhere' );

// ---------------------------------------------------------------------------
// 3. [conexao_grid] — hides non-published events
// ---------------------------------------------------------------------------

test_section( 'conexao_grid: hides hidden events' );

$event_id = cc_create_post(
	array(
		'post_type'   => 'event',
		'post_title'  => '[CC TEST] Evento oculto',
		'post_status' => 'publish',
	)
);
assert_true( $event_id > 0, 'a test event post was created' );

update_post_meta( $event_id, '_event_status', 'expired' );
wp_set_post_terms( $event_id, array( $cat_id ), 'conexao_category' );

$html = do_shortcode( '[conexao_grid categories="' . $cat_slug . '"]' );
assert_not_contains( '[CC TEST] Evento oculto', $html, 'an expired event does not appear in the grid' );

// ---------------------------------------------------------------------------
// 4. [conexao_grid] — external URL links
// ---------------------------------------------------------------------------

test_section( 'conexao_grid: external URL links' );

$sponsor_id = cc_create_post(
	array(
		'post_type'   => 'sponsor',
		'post_title'  => '[CC TEST] Apoiador externo',
		'post_status' => 'publish',
	)
);
assert_true( $sponsor_id > 0, 'a test sponsor post was created' );

update_post_meta( $sponsor_id, '_sponsor_link', 'https://example.ie/' );
wp_set_post_terms( $sponsor_id, array( $cat_id ), 'conexao_category' );

$html = do_shortcode( '[conexao_grid categories="' . $cat_slug . '"]' );
assert_contains( 'https://example.ie/', $html, 'the sponsor card links to its external URL' );
assert_contains( 'target="_blank"', $html, 'the external link opens in a new tab' );
assert_contains( 'rel="noopener noreferrer"', $html, 'the external link carries secure attributes' );

// ---------------------------------------------------------------------------
// 5. [conexao_blog_categories] — renders category pills
// ---------------------------------------------------------------------------

test_section( 'conexao_blog_categories: renders category pills' );

$blog_cat_slug = cc_unique_slug( 'cc-test-blog-cat' );
$blog_cat_id = cc_create_term( $blog_cat_slug, 'Categoria Blog', 'category' );
assert_true( $blog_cat_id > 0, 'a test blog category was created' );

$html = do_shortcode( '[conexao_blog_categories]' );
assert_true( '' !== $html, 'the blog categories shortcode returns non-empty HTML' );
assert_contains( 'Todos', $html, 'the "Todos" pill is always present' );
assert_contains( '/blog/', $html, 'the base blog URL is linked' );

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------

test_section( 'Cleanup' );

// Delete temporary posts before their associated terms, so term-to-post
// relationships do not block term deletion.
foreach ( $cc_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}

// Delete only terms created by this test. Never touch pre-existing terms.
foreach ( $cc_terms as $term_id => $taxonomy ) {
	wp_delete_term( $term_id, $taxonomy );
}

// Verify no temporary post remains.
$leftover_posts = get_posts(
	array(
		'post_type'   => 'any',
		'post_status' => 'any',
		's'           => '[CC TEST]',
		'numberposts' => 5,
		'fields'      => 'ids',
	)
);
assert_true( empty( $leftover_posts ), 'no temporary post remains after the suite' );

// Verify no temporary term remains.
$leftover_terms = 0;
foreach ( $cc_terms as $term_id => $taxonomy ) {
	if ( term_exists( (int) $term_id, $taxonomy ) ) {
		++$leftover_terms;
	}
}
assert_equals( 0, $leftover_terms, 'no temporary term remains after the suite' );

test_finish( 'conexao-content shortcodes' );
