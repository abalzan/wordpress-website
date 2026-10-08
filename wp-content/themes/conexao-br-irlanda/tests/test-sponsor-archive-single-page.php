<?php
/**
 * Tests for the Apoiadores directory as a SINGLE-PAGE archive.
 *
 * `/apoiadores/` is the `sponsor` post-type archive. Its listing is the MAIN
 * WP_Query, shaped by `conexao_content_archive_query()` (`pre_get_posts`).
 * These assertions prove the directory renders the WHOLE editor-curated set on
 * one page: no page size, no pagination controls, and no second page carrying
 * additional Apoiadores.
 *
 * The suite carries its own NEGATIVE CONTROL. The same fixtures are queried
 * again with the pre-change configuration (the site page size) reintroduced and
 * MUST then produce FEWER records plus pagination links. That is what makes
 * "no pagination" a real assertion instead of a statement about the current
 * data size: if the unlimited configuration is removed from
 * `inc/archive-query.php`, this suite fails.
 *
 * It also asserts the change is SCOPED: the other archives keep their page size
 * and their pagination.
 *
 * The script creates temporary sponsor posts (enough to exceed the site page
 * size) and deletes them at the end; it writes no other data. Published
 * sponsors already present in the database are read-only context.
 *
 * Requirements: conexao-data-model (sponsor CPT), the active theme and the local
 * WordPress stack.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-sponsor-archive-single-page.php
 */

// --- Bootstrap WordPress (plugins + theme option). ---

// Load the active theme so the helpers under test are defined.
// The active theme is not auto-loaded by wp-load.php in a CLI context.
// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$created_posts = array();

/**
 * Create a temporary published sponsor with an optional display order.
 *
 * @param int    $index       Fixture index (used in the title).
 * @param string $order_value `_sponsor_display_order` value ('' = unordered).
 * @return int New sponsor post ID.
 */
function sponsor_single_page_create( $index, $order_value = '' ) {
	global $created_posts;

	$post_id = wp_insert_post(
		array(
			'post_type'    => 'sponsor',
			'post_title'   => '[SINGLE PAGE TEST] Apoiador ' . $index,
			'post_status'  => 'publish',
			'post_content' => 'fixture',
		),
		true
	);

	if ( is_wp_error( $post_id ) || ! $post_id ) {
		exit( 1 );
	}

	if ( '' !== $order_value ) {
		update_post_meta( $post_id, '_sponsor_display_order', $order_value );
	}

	$created_posts[] = (int) $post_id;

	return (int) $post_id;
}

/**
 * Run an archive main query exactly like WordPress would on the front end.
 *
 * The new query is swapped in as the "main query" (`is_main_query()` compares
 * against `$GLOBALS['wp_the_query']`), then `pre_get_posts` runs inside
 * `WP_Query::query()` — the real hook path, never a reimplementation (the same
 * harness `test-leisure-multiselect-filters.php` uses).
 *
 * Note on the swapped query: it has no queried object, so WordPress builds a
 * pagination href from the site root rather than from the archive permalink.
 * That is a harness detail only — the assertions below ask whether pagination is
 * generated at all, and which records the query returns, never what the page
 * link looks like (the HTTP acceptance matrix asserts the real URLs).
 *
 * @param string $post_type Post type to query.
 * @param int    $paged     Page number (default 1).
 * @return WP_Query
 */
function sponsor_single_page_query( $post_type = 'sponsor', $paged = 1 ) {
	$saved_wp_the_query = $GLOBALS['wp_the_query'];

	$args = array(
		'post_type'   => $post_type,
		'post_status' => 'publish',
	);
	if ( $paged > 1 ) {
		$args['paged'] = $paged;
	}

	$query                   = new WP_Query();
	$GLOBALS['wp_the_query'] = $query;
	$query->query( $args );
	$GLOBALS['wp_the_query'] = $saved_wp_the_query;

	return $query;
}

/**
 * Render the shared pagination component exactly like archive.php does, with
 * the given query standing in as the main query.
 *
 * The component is the ONE place that emits /page/N/ controls, so rendering it
 * is the behavioural proof of "pagination is not generated" — not a string
 * check of a template file.
 *
 * @param WP_Query $query Query to render pagination for.
 * @return string Rendered HTML ('' when the component early-returns).
 */
function sponsor_single_page_render_pagination( $query ) {
	$saved_wp_query          = $GLOBALS['wp_query'];
	$saved_wp_the_query      = $GLOBALS['wp_the_query'];
	$GLOBALS['wp_query']     = $query;
	$GLOBALS['wp_the_query'] = $query;

	ob_start();
	include get_template_directory() . '/template-parts/pagination.php';
	$html = ob_get_clean();

	$GLOBALS['wp_query']     = $saved_wp_query;
	$GLOBALS['wp_the_query'] = $saved_wp_the_query;

	return (string) $html;
}

echo 'Site: ' . home_url() . "\n";

test_title( 'Apoiadores archive — single-page directory' );
test_prerequisite_hint( 'bootstrap-ci-fixtures' );

test_require(
	post_type_exists( 'sponsor' ),
	'bootstrap-ci-fixtures',
	'the sponsor (Apoiadores) post type is registered'
);

test_require(
	function_exists( 'conexao_sponsor_archive_ordered_ids' ),
	'theme',
	'conexao_sponsor_archive_ordered_ids() is available'
);

// Start from a clean homepage sponsor cache so cached fixtures never leak.
delete_transient( 'conexao_home_sponsors' );

// ---------------------------------------------------------------------------
// Fixtures
//
// The regression proof is only meaningful when the fitted set is LARGER than
// the page size pagination would introduce. Enough temporary supporters are
// created to exceed both the site's configured page size and the small page
// size the negative control uses. The fixture count is bounded so an unusual
// `posts_per_page` cannot make the suite pathological — and the bound is
// asserted below, never silently accepted.
// ---------------------------------------------------------------------------
test_section( 'Fixtures' );

$site_page_size = (int) get_option( 'posts_per_page' );
$control_size   = 3; // The "pagination reintroduced" page size for the control.
$fixture_cap    = 40;

$available_ids = conexao_sponsor_archive_ordered_ids();
$target_total  = min( max( $control_size + 1, $site_page_size + 2 ), $fixture_cap );
$to_create     = max( 0, $target_total - count( $available_ids ) );

for ( $i = 1; $i <= $to_create; $i++ ) {
	// Half the fixtures carry an explicit editor-curated order and half stay
	// unordered, so both ordering groups are represented on the single page.
	$order = ( $i % 2 === 1 ) ? '900' . $i : '';
	sponsor_single_page_create( $i, $order );
}

$available_ids = conexao_sponsor_archive_ordered_ids();
$available     = count( $available_ids );

echo '  site posts_per_page: ' . $site_page_size
	. ' | temporary sponsors created: ' . $to_create
	. ' | available Apoiadores: ' . $available . "\n";

assert_true(
	$available > $control_size,
	'the fitted set is larger than the control page size (the negative control is meaningful)',
	'available=' . $available . ' control=' . $control_size
);
assert_true(
	$available > $site_page_size,
	'the fitted set is larger than the site page size (pagination would have paginated before this change)',
	'available=' . $available . ' posts_per_page=' . $site_page_size
);
assert_true(
	$fixture_cap >= $site_page_size + 2,
	'the fixture bound did not truncate the page-size proof',
	'cap=' . $fixture_cap . ' posts_per_page=' . $site_page_size
);

// ---------------------------------------------------------------------------
// 1. The archive query renders EVERY Apoiador on one page
// ---------------------------------------------------------------------------
test_section( 'Single page: rendered == available' );

$archive      = sponsor_single_page_query();
$rendered_ids = array_map( 'intval', wp_list_pluck( $archive->posts, 'ID' ) );

assert_equals(
	$available,
	count( $rendered_ids ),
	'rendered Apoiadores equals the number available (no supporter is left off the page)'
);
assert_true(
	array() === array_diff( $available_ids, $rendered_ids ),
	'no available Apoiador is missing from the archive page'
);
assert_true(
	array() === array_diff( $rendered_ids, $available_ids ),
	'the archive page renders nothing outside the available Apoiador set'
);
assert_true(
	count( $rendered_ids ) === count( array_unique( $rendered_ids ) ),
	'no Apoiador is rendered twice'
);
assert_true(
	-1 === (int) $archive->get( 'posts_per_page' ) && true === (bool) $archive->get( 'nopaging' ),
	'the archive uses the WordPress unlimited-result configuration (-1 + nopaging), never an arbitrary page size',
	'posts_per_page=' . (int) $archive->get( 'posts_per_page' ) . ' nopaging=' . (int) (bool) $archive->get( 'nopaging' )
);
assert_true(
	$archive->max_num_pages <= 1,
	'the archive query reports at most one page',
	'max_num_pages=' . $archive->max_num_pages
);

// ---------------------------------------------------------------------------
// 2. Ordering is unchanged (the editor-curated contract)
// ---------------------------------------------------------------------------
test_section( 'Ordering preserved' );

assert_true(
	$available_ids === $rendered_ids,
	'the rendered order matches conexao_sponsor_archive_ordered_ids() exactly (post__in + orderby post__in untouched)',
	'first_rendered=' . implode( ',', array_slice( $rendered_ids, 0, 5 ) )
);
assert_true(
	array_slice( $available_ids, 0, 3 ) === array_slice( $rendered_ids, 0, 3 ),
	'the first three Apoiadores are unchanged'
);
assert_true(
	array_slice( $available_ids, -3 ) === array_slice( $rendered_ids, -3 ),
	'the last three Apoiadores are unchanged'
);

// ---------------------------------------------------------------------------
// 3. No pagination is generated (behavioural: the shared component renders '')
// ---------------------------------------------------------------------------
test_section( 'No pagination generated' );

$pagination_html = sponsor_single_page_render_pagination( $archive );

assert_equals(
	'',
	trim( $pagination_html ),
	'the shared pagination component renders nothing for /apoiadores/'
);
assert_not_contains(
	'conexao-pagination',
	$pagination_html,
	'no .conexao-pagination container is emitted'
);
assert_not_contains(
	'page-numbers',
	$pagination_html,
	'no numbered / next / previous page link is emitted'
);

// ---------------------------------------------------------------------------
// 4. Page 2 carries no additional Apoiadores (no hidden second page)
// ---------------------------------------------------------------------------
test_section( 'No second paginated page' );

$page_two     = sponsor_single_page_query( 'sponsor', 2 );
$page_two_ids = array_map( 'intval', wp_list_pluck( $page_two->posts, 'ID' ) );

assert_true(
	array() === array_diff( $page_two_ids, $available_ids ),
	'a paged request surfaces no Apoiador outside the single-page set (nothing is hidden on page 2)'
);

// ---------------------------------------------------------------------------
// 5. Negative control — pagination reintroduced MUST be detected
//
// This replays the pre-change configuration (the site page size + the same
// ordering) and asserts the symptoms the assertions above would catch: fewer
// records rendered than available, and pagination controls generated.
// ---------------------------------------------------------------------------
test_section( 'Negative control (pagination reintroduced)' );

$limited_query = new WP_Query(
	array(
		'post_type'      => 'sponsor',
		'post_status'    => 'publish',
		'posts_per_page' => max( 1, $site_page_size ),
		'paged'          => 1,
		'post__in'       => $available_ids,
		'orderby'        => 'post__in',
		'order'          => 'ASC',
	)
);
$limited_ids   = array_map( 'intval', wp_list_pluck( $limited_query->posts, 'ID' ) );

assert_true(
	count( $limited_ids ) < $available,
	'control: a limited page size renders fewer Apoiadores than are available',
	'rendered=' . count( $limited_ids ) . ' available=' . $available
);
assert_true(
	$limited_query->max_num_pages > 1,
	'control: a limited page size produces more than one page',
	'max_num_pages=' . $limited_query->max_num_pages
);

$limited_pagination = sponsor_single_page_render_pagination( $limited_query );

assert_contains(
	'conexao-pagination',
	$limited_pagination,
	'control: the same fixtures WITH a page size DO produce the pagination container'
);
assert_contains(
	'page-numbers',
	$limited_pagination,
	'control: the same fixtures WITH a page size DO produce numbered / next / previous links'
);

$small_control = new WP_Query(
	array(
		'post_type'      => 'sponsor',
		'post_status'    => 'publish',
		'posts_per_page' => $control_size,
		'paged'          => 1,
		'post__in'       => $available_ids,
		'orderby'        => 'post__in',
		'order'          => 'ASC',
	)
);
assert_true(
	count( wp_list_pluck( $small_control->posts, 'ID' ) ) === $control_size,
	'control: page size ' . $control_size . ' returns exactly ' . $control_size . ' Apoiadores (page 1 of a paginated directory)'
);

// ---------------------------------------------------------------------------
// 6. Scope: no unrelated archive pagination changed
// ---------------------------------------------------------------------------
test_section( 'Scope: other archives keep their pagination' );

foreach ( array( 'guide', 'leisure' ) as $other_type ) {
	$other = sponsor_single_page_query( $other_type );

	assert_true(
		(int) $other->get( 'posts_per_page' ) === $site_page_size,
		'the ' . $other_type . ' archive keeps the site page size (the change is scoped to Apoiadores)',
		'posts_per_page=' . (int) $other->get( 'posts_per_page' )
	);
	assert_true(
		false === (bool) $other->get( 'nopaging' ),
		'the ' . $other_type . ' archive is still paginated (nopaging is not global)'
	);
}

assert_true(
	(int) get_option( 'posts_per_page' ) === $site_page_size,
	'the global WordPress page-size setting is untouched'
);

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
foreach ( $created_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
// Restore the homepage sponsor cache to its pre-test (unprimed) state.
delete_transient( 'conexao_home_sponsors' );

test_finish( 'test-sponsor-archive-single-page' );
