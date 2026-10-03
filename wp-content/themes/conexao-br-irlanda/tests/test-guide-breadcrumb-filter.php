<?php
/**
 * Tests for the Guias Práticos breadcrumb category-filter navigation.
 *
 * Verifies:
 *  - A guide's category breadcrumb links to the EXISTING filtered Guias
 *    archive (/guias/?categoria=<slug> — the same tax_query applied by the
 *    archive filter bar) instead of the WordPress taxonomy term archive
 *    URL (/categories/<slug>/, which 301-redirects to the standalone
 *    /<slug>/ static page, e.g. /moradia/).
 *  - The category crumb URL is generated through the shared
 *    conexao_get_guide_category_url() helper (single source of truth with
 *    the homepage Quick Access cards).
 *  - Início / Guias Práticos / title crumbs stay unchanged; the current
 *    guide remains the final non-linked crumb.
 *  - Multiple-category behavior: the first term returned by get_the_terms()
 *    (ordered by name) is the deterministic primary term — the same rule
 *    the breadcrumb already used before this change.
 *  - Guides without a category render no category crumb (no invented or
 *    broken link).
 *  - The archive pre_get_posts branch (?categoria=) keeps filtering only
 *    matching guides; invalid slugs return zero results gracefully.
 *  - Rendered breadcrumb markup keeps the exact classes used by light and
 *    dark mode CSS (.conexao-breadcrumb-link / -sep / -current) and keeps
 *    the current page as a non-linked span with aria-current="page".
 *
 * Creates temporary terms (unique test slugs) and temporary guide posts and
 * deletes both at the end; never modifies existing records/terms.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-guide-breadcrumb-filter.php
 */

// --- Bootstrap WordPress (plugins + theme option). ---

// Load the active theme so the helpers under test are defined (the active
// theme is not auto-loaded by wp-load.php in a CLI context).
// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
	require_once $theme_functions;
}

$passed        = 0;
$failed        = 0;
$created_posts = array();
$created_terms = array();

/** Get or create a temporary term; returns the slug. */
function gbf_test_term( $name, $slug ) {
	global $created_terms;
	$existing = term_exists( $slug, 'conexao_category' );
	if ( $existing && ! is_wp_error( $existing ) ) {
		return $slug;
	}
	$inserted = wp_insert_term( $name, 'conexao_category', array( 'slug' => $slug ) );
	if ( is_wp_error( $inserted ) ) {
		exit( 1 );
	}
	$created_terms[] = (int) $inserted['term_id'];
	return $slug;
}

/** Create a temporary published guide post with the given category slugs. */
function gbf_test_guide( $title, $category_slugs = array() ) {
	global $created_posts;
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'guide',
			'post_title'   => '[GBF TEST] ' . $title,
			'post_status'  => 'publish',
			'post_content' => 'test',
		)
	);
	if ( is_wp_error( $post_id ) || ! $post_id ) {
		exit( 1 );
	}
	$created_posts[] = (int) $post_id;
	if ( $category_slugs ) {
		wp_set_object_terms( $post_id, $category_slugs, 'conexao_category', false );
	}
	return (int) $post_id;
}

/**
 * Enter a singular-guide state (is_singular() === true) so the breadcrumb
 * function resolves exactly as it does on /guias/{slug}/. The global post
 * and main query are swapped; caller restores via gbf_restore_globals().
 */
function gbf_enter_singular_guide( $post_id ) {
	$GLOBALS['_gbf_saved']['post']         = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
	$GLOBALS['_gbf_saved']['wp_the_query'] = isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null;
	$GLOBALS['_gbf_saved']['wp_query']     = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
	$GLOBALS['_gbf_saved']['get']          = $_GET;

	$q                       = new WP_Query( array( 'post_type' => 'guide', 'p' => $post_id, 'post_status' => 'publish' ) );
	$GLOBALS['wp_the_query'] = $q;
	$GLOBALS['wp_query']     = $q;
	$GLOBALS['post']         = get_post( $post_id );
	setup_postdata( $GLOBALS['post'] );
}

function gbf_restore_globals() {
	if ( isset( $GLOBALS['_gbf_saved']['post'] ) ) {
		$GLOBALS['post'] = $GLOBALS['_gbf_saved']['post'];
	}
	$GLOBALS['wp_the_query'] = $GLOBALS['_gbf_saved']['wp_the_query'];
	$GLOBALS['wp_query']     = $GLOBALS['_gbf_saved']['wp_query'];
	$_GET                    = $GLOBALS['_gbf_saved']['get'];
	unset( $GLOBALS['_gbf_saved'] );
}

/**
 * Run the guide archive main query exactly like /guias/ does: the new query
 * is swapped in as the "main query" (is_main_query compares against
 * $GLOBALS['wp_the_query']), $_GET is set, then pre_get_posts runs inside
 * WP_Query::query() — the real hook path, no reimplementation.
 */
function gbf_archive_query( array $get_params ) {
	$tmp_get            = $_GET;
	$_GET               = $get_params;
	$saved_wp_the_query = $GLOBALS['wp_the_query'];
	$q                  = new WP_Query();
	$GLOBALS['wp_the_query'] = $q;
	$q->query(
		array(
			'post_type'   => 'guide',
			'post_status' => 'publish',
		)
	);
	$GLOBALS['wp_the_query'] = $saved_wp_the_query;
	$_GET                    = $tmp_get;
	return $q;
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
$slug_alpha  = gbf_test_term( 'GBF Alpha', 'gbf-alpha' );           // Moradia-type category.
$slug_beta   = gbf_test_term( 'GBF Beta', 'gbf-beta' );             // Finanças-type category.
$slug_gamma  = gbf_test_term( 'GBF Gamma', 'gbf-gamma' );           // Saúde-type category.
$slug_multi  = gbf_test_term( 'GBF Zulu First', 'gbf-zulu-first' ); // sorts after "AAA" by name.
$slug_multi2 = gbf_test_term( 'AAA GBF Multi', 'gbf-aaa-multi' );   // sorts first by name.

$guide_alpha = gbf_test_guide( 'Casa: Guia de Aluguel', array( $slug_alpha ) );
$guide_beta  = gbf_test_guide( 'Contas e Impostos', array( $slug_beta ) );
$guide_gamma = gbf_test_guide( 'Médico de Família', array( $slug_gamma ) );
$guide_multi = gbf_test_guide( 'Guia com Duas Categorias', array( $slug_multi2, $slug_multi ) );
$guide_nocat = gbf_test_guide( 'Guia sem Categoria' );
$guides_url = conexao_get_guides_archive_url();

// ---------------------------------------------------------------------------
// 1. Breadcrumb data — single term (acceptance example: Moradia)
// ---------------------------------------------------------------------------
test_section( 'Breadcrumb data for a single-category guide' );

gbf_enter_singular_guide( $guide_alpha );
$crumbs = conexao_seo_breadcrumb_data();

assert_true( is_array( $crumbs ) && count( $crumbs ) >= 4, 'breadcrumb trail contains Início → Guias Práticos → category → title' );

if ( is_array( $crumbs ) ) {
	$first = $crumbs[0];
	assert_true( 'Início' === $first['name'] && home_url( '/' ) === $first['url'], 'A. first crumb is Início linking to the homepage' );

	$second = $crumbs[1];
	assert_true( 'Guias Práticos' === $second['name'], 'B. second crumb label is Guias Práticos' );
	assert_true( $second['url'] === $guides_url, 'B. Guias Práticos crumb links to the Guias archive' );

	// Category crumb: find it by name.
	$cat_crumb = null;
	foreach ( $crumbs as $crumb ) {
		if ( $crumb['name'] === 'GBF Alpha' ) {
			$cat_crumb = $crumb;
		}
	}
	assert_true( null !== $cat_crumb, 'C. category crumb exists for a categorized guide' );

	if ( $cat_crumb ) {
		$expected_url = conexao_get_guide_category_url( $slug_alpha, $slug_alpha );
		assert_true( $cat_crumb['url'] === $expected_url, 'D. category crumb href equals the shared filter URL helper output' );
		assert_true( false !== strpos( $cat_crumb['url'], 'guias/' ) && false !== strpos( $cat_crumb['url'], 'categoria=' . $slug_alpha ), 'D. href points to the Guias archive with the category filter encoded' );
		assert_true( false === strpos( $cat_crumb['url'], '/categories/' ), 'D. href does NOT use the WordPress taxonomy archive URL (/categories/slug/)' );
		assert_true( get_permalink( $guide_alpha ) !== $cat_crumb['url'], 'D. category crumb is not the current guide page' );
	}

	$last = $crumbs[ count( $crumbs ) - 1 ];
	assert_true( $last['name'] === get_the_title( $guide_alpha ) && $last['url'] === get_permalink( $guide_alpha ), 'E. final crumb is the current guide title (current page)' );
}

gbf_restore_globals();

// ---------------------------------------------------------------------------
// 2. Other categories resolve to their own filtered archive
// ---------------------------------------------------------------------------
test_section( 'Category crumb resolution for multiple categories' );

foreach ( array( $slug_beta => 'GBF Beta', $slug_gamma => 'GBF Gamma' ) as $slug => $name ) {
	$which_guide = ( 'GBF Beta' === $name ) ? $guide_beta : $guide_gamma;
	gbf_enter_singular_guide( $which_guide );
	$crumbs    = conexao_seo_breadcrumb_data();
	$cat_crumb = null;
	foreach ( $crumbs as $crumb ) {
		if ( $crumb['name'] === $name ) {
			$cat_crumb = $crumb;
		}
	}
	$term = get_term_by( 'slug', $slug, 'conexao_category' );
	assert_true( null !== $cat_crumb && $cat_crumb['url'] === add_query_arg( 'categoria', $slug, $guides_url ), "{$name} breadcrumb links to /guias/?categoria={$slug}" );
	assert_true( null !== $cat_crumb && $cat_crumb['name'] === $term->name, "{$name} breadcrumb uses the real taxonomy term name (no hard-coded label)" );
	gbf_restore_globals();
}

// ---------------------------------------------------------------------------
// 3. Multiple categories — deterministic primary term (first by name)
// ---------------------------------------------------------------------------
test_section( 'Multiple categories: first term returned by get_the_terms()' );

gbf_enter_singular_guide( $guide_multi );
$crumbs    = conexao_seo_breadcrumb_data();
$cat_crumb = null;
foreach ( $crumbs as $crumb ) {
	// Category crumb: an entry with the filtered archive URL and a term name.
	if ( false !== strpos( (string) $crumb['url'], 'categoria=' ) ) {
		$cat_crumb = $crumb;
	}
}
// get_the_terms() orders by name ascending → "AAA GBF Multi" first.
$expected_first = get_term_by( 'slug', $slug_multi2, 'conexao_category' );
assert_true( null !== $cat_crumb && $cat_crumb['name'] === $expected_first->name, 'single category crumb uses the primary term (first of get_the_terms(), ordered by name)' );
assert_true( null !== $cat_crumb && false !== strpos( $cat_crumb['url'], 'categoria=' . $slug_multi2 ), 'primary-term crumb encodes that term\'s filter slug' );
gbf_restore_globals();

// ---------------------------------------------------------------------------
// 4. Guide without category — no category crumb, no invented link
// ---------------------------------------------------------------------------
test_section( 'Guide without category' );

gbf_enter_singular_guide( $guide_nocat );
$crumbs = conexao_seo_breadcrumb_data();
assert_true( is_array( $crumbs ) && 3 === count( $crumbs ), 'F. uncategorized guide keeps Início → Guias Práticos → title (no category crumb)' );
if ( is_array( $crumbs ) ) {
	$has_category_crumb = false;
	foreach ( $crumbs as $crumb ) {
		if ( false !== strpos( (string) $crumb['url'], 'categoria=' ) || false !== strpos( (string) $crumb['url'], '/categories/' ) ) {
			$has_category_crumb = true;
		}
	}
	assert_true( ! $has_category_crumb, 'F. no category/filter/term URL appears when the guide has no category' );
	assert_true( 'Guias Práticos' === $crumbs[1]['name'] && get_the_title( $guide_nocat ) === $crumbs[2]['name'], 'F. surrounding crumb levels remain correct' );
}
gbf_restore_globals();
// ---------------------------------------------------------------------------
// 5. Archive receives the filter and returns the correct result set
// ---------------------------------------------------------------------------
test_section( 'Archive filtering (?categoria=) — result sets' );

$q = gbf_archive_query( array( 'categoria' => $slug_alpha ) );
assert_true( array( $guide_alpha ) === wp_list_pluck( $q->posts, 'ID' ), 'G/H. ?categoria=alpha returns exactly the alpha guide' );

$q = gbf_archive_query( array( 'categoria' => $slug_beta ) );
assert_true( array( $guide_beta ) === wp_list_pluck( $q->posts, 'ID' ), 'G/H. ?categoria=beta returns exactly the beta guide' );

$q = gbf_archive_query( array( 'categoria' => $slug_gamma ) );
assert_true( array( $guide_gamma ) === wp_list_pluck( $q->posts, 'ID' ), 'G/H. ?categoria=gamma returns exactly the gamma guide' );

$q = gbf_archive_query( array( 'categoria' => $slug_alpha . ',nao-existe-gbf' ) );
assert_true( 0 === $q->found_posts, 'G. the Guias filter stays single-select: a comma value is one unknown slug → zero results (existing behavior, no invented OR)' );

$q = gbf_archive_query( array( 'categoria' => 'nao-existe-gbf' ) );
assert_true( 0 === $q->found_posts, 'H. an all-invalid filter yields zero results gracefully' );

$q = gbf_archive_query( array( 'categoria' => $slug_multi2 ) );
$expected_multi = array( $guide_multi );
sort( $expected_multi );
$actual_multi = wp_list_pluck( $q->posts, 'ID' );
sort( $actual_multi );
assert_true( $expected_multi === $actual_multi, 'G/H. multi-category guide is returned when filtering by its primary term' );

$q = gbf_archive_query( array() );
$all_ids = wp_list_pluck( $q->posts, 'ID' );
assert_true( in_array( $guide_alpha, $all_ids, true ) && in_array( $guide_nocat, $all_ids, true ), 'I. unfiltered Guias archive still returns every published guide (incl. uncategorized)' );

assert_true( add_query_arg( 'categoria', $slug_alpha, $guides_url ) === $guides_url . '?categoria=' . $slug_alpha, 'J. filtered URL is the canonical deterministic /guias/?categoria= form' );

// ---------------------------------------------------------------------------
// 6. Rendered breadcrumb markup (classes used by light + dark-mode CSS,
//    current item non-linked with aria-current, separators preserved)
// ---------------------------------------------------------------------------
test_section( 'Rendered breadcrumb markup' );

gbf_enter_singular_guide( $guide_alpha );
ob_start();
conexao_seo_breadcrumbs();
$html = ob_get_clean();

$plain        = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5 );
$expected_url = add_query_arg( 'categoria', $slug_alpha, $guides_url );
$expected_href = esc_url( $expected_url );

assert_true( false !== strpos( $html, '<nav class="conexao-breadcrumbs" aria-label="Breadcrumb">' ), 'J. semantic <nav aria-label="Breadcrumb"> wrapper preserved' );
assert_true( false !== strpos( $html, 'class="conexao-breadcrumb-link"' ), 'J. category crumb remains a real link with the conexao-breadcrumb-link class (light + dark mode CSS)' );
assert_true( false !== strpos( $plain, 'href="' . $expected_href . '" class="conexao-breadcrumb-link">GBF Alpha</a>' ), 'K. category link href is the filtered Guias archive and accessible text is the category name' );
assert_true( false !== strpos( $html, 'class="conexao-breadcrumb-sep" aria-hidden="true">&rsaquo;' ), 'J. separator markup preserved (›, aria-hidden)' );
assert_true( false !== strpos( $html, 'class="conexao-breadcrumb-current" aria-current="page">' ), 'J. current guide stays a non-linked span with aria-current="page"' );
assert_true( false === strpos( $html, '/categories/' ), 'K. rendered breadcrumb never emits the /categories/ term archive href' );
assert_true( false !== strpos( $html, 'conexao-breadcrumb-list' ) && false !== strpos( $html, 'conexao-breadcrumb-item' ), 'J. breadcrumb list/item classes unchanged (desktop + mobile layout + dark mode CSS)' );

gbf_restore_globals();

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
foreach ( $created_posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
foreach ( $created_terms as $term_id ) {
	wp_delete_term( $term_id, 'conexao_category' );
}

$leftover = get_posts(
	array(
		'post_type'   => 'guide',
		'post_status' => 'any',
		's'           => '[GBF TEST]',
		'numberposts' => 5,
		'fields'      => 'ids',
	)
);
assert_true( empty( $leftover ), 'no temporary test posts remain' );
$leftover_terms = get_terms(
	array(
		'taxonomy'   => 'conexao_category',
		'hide_empty' => false,
		'search'     => 'gbf-',
		'fields'     => 'ids',
	)
);
assert_true( ! is_wp_error( $leftover_terms ) && empty( $leftover_terms ), 'no temporary test terms remain' );

test_finish();
