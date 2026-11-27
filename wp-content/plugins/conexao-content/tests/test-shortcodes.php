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
 * Read-only. Creates temporary posts and terms, deletes them at the end.
 *
 * @package Conexao_Content
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed  = 0;
$failed  = 0;
$created = array();

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

function cc_create_post( array $args ) {
	global $created;
	$post_id = wp_insert_post( $args );
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		return 0;
	}
	$created[] = (int) $post_id;
	return (int) $post_id;
}

function cc_create_term( $slug, $name, $taxonomy ) {
	if ( term_exists( $slug, $taxonomy ) ) {
		return (int) term_exists( $slug, $taxonomy )['term_id'];
	}
	$created_term = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
	if ( is_wp_error( $created_term ) ) {
		return 0;
	}
	return (int) $created_term['term_id'];
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

$cat_id = cc_create_term( 'cc-test-categoria', 'Categoria Teste', 'conexao_category' );
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

$html = do_shortcode( '[conexao_grid categories="cc-test-categoria"]' );
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

$html = do_shortcode( '[conexao_grid categories="cc-test-categoria"]' );
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

$html = do_shortcode( '[conexao_grid categories="cc-test-categoria"]' );
assert_contains( 'https://example.ie/', $html, 'the sponsor card links to its external URL' );
assert_contains( 'target="_blank"', $html, 'the external link opens in a new tab' );
assert_contains( 'rel="noopener noreferrer"', $html, 'the external link carries secure attributes' );

// ---------------------------------------------------------------------------
// 5. [conexao_blog_categories] — renders category pills
// ---------------------------------------------------------------------------

test_section( 'conexao_blog_categories: renders category pills' );

$blog_cat_id = cc_create_term( 'cc-test-blog-cat', 'Categoria Blog', 'category' );
assert_true( $blog_cat_id > 0, 'a test blog category was created' );

$html = do_shortcode( '[conexao_blog_categories]' );
assert_true( '' !== $html, 'the blog categories shortcode returns non-empty HTML' );
assert_contains( 'Todos', $html, 'the "Todos" pill is always present' );
assert_contains( '/blog/', $html, 'the base blog URL is linked' );

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------

test_section( 'Cleanup' );

foreach ( $created as $post_id ) {
	wp_delete_post( $post_id, true );
}

$leftover = get_posts(
	array(
		'post_type'   => 'any',
		'post_status' => 'any',
		's'           => '[CC TEST]',
		'numberposts' => 5,
		'fields'      => 'ids',
	)
);
assert_true( empty( $leftover ), 'no temporary post remains after the suite' );

test_finish( 'conexao-content shortcodes' );
