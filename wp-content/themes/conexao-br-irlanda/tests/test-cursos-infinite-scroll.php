<?php
/**
 * Tests for the /cursos/ infinite-scroll retag.
 *
 * Verifies the archive.php progressive-enhancement retag that moves the
 * Cursos archive from the manual "Carregar mais" Load More
 * (`data-load-more`) to the shared automatic Infinite Scroll
 * (`data-infinite-scroll`) already used by /lazer/, /eventos/, /blog/ and
 * /guias/ (retrained 2026-10-18).
 *
 *  - /cursos/ grid is tagged `data-infinite-scroll` (NOT `data-load-more`)
 *  - /cursos/ course cards carry stable `id="post-{ID}"` so the shared
 *    initInfiniteScroll() duplicate guard can detect them
 *  - /cursos/ renders the shared `.conexao-pagination` component so the
 *    shared initInfiniteScroll() has a next-page URL to fetch when more
 *    than one page exists
 *  - No server-rendered Carregar mais button markup on /cursos/
 *  - /eventos/ keeps `data-infinite-scroll` (regression)
 *  - /lazer/ keeps `data-infinite-scroll` (regression)
 *  - No archive grid carries both competing handlers at once
 *  - Guia archive keeps its existing non-Load-More behaviour (regression)
 *
 * Creates no content and modifies nothing.
 *
 * Usage:
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-cursos-infinite-scroll.php
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$theme_functions = get_template_directory() . '/functions.php';
if ( file_exists( $theme_functions ) ) {
    require_once $theme_functions;
}

$passed = 0;
$failed = 0;

/**
 * Render archive.php for a given post-type archive and return the HTML.
 *
 * The archive.php progressive-enhancement block reads is_post_type_archive()
 * off the main query, so we install a query for the target post type. The
 * grid wrapper renders regardless of have_posts(), so the markup attributes
 * are captured even when the loop is empty.
 *
 * @param string $post_type Post type archive to render.
 * @return string Rendered archive HTML.
 */
function cis_render_archive( $post_type ) {
    global $wp_query;
    $saved = $wp_query;
    $query = new WP_Query( array( 'post_type' => $post_type, 'posts_per_page' => 3 ) );
    $GLOBALS['wp_query'] = $query;
    ob_start();
    require get_template_directory() . '/archive.php';
    $html = ob_get_clean();
    $GLOBALS['wp_query'] = $saved;
    return $html;
}

// ---------------------------------------------------------------------------
// Grid tagging: Cursos uses infinite scroll, Eventos/Lazer keep it
// ---------------------------------------------------------------------------

test_section( 'Grid progressive-enhancement tagging' );

$course_html  = cis_render_archive( 'course_provider' );
$event_html   = cis_render_archive( 'event' );
$leisure_html = cis_render_archive( 'leisure' );
$guide_html   = cis_render_archive( 'guide' );

assert_true(
    false !== strpos( $course_html, 'data-infinite-scroll' ),
    '/cursos/ grid IS tagged data-infinite-scroll (Infinite Scroll added)'
);
assert_true(
    false === strpos( $course_html, 'data-load-more' ),
    '/cursos/ grid is NOT tagged data-load-more (Load More removed)'
);
assert_true(
    false !== strpos( $event_html, 'data-infinite-scroll' ),
    '/eventos/ grid keeps data-infinite-scroll (regression guard)'
);
assert_true(
    false === strpos( $event_html, 'data-load-more' ),
    '/eventos/ grid is NOT tagged data-load-more (regression guard)'
);
assert_true(
    false !== strpos( $leisure_html, 'data-infinite-scroll' ),
    '/lazer/ grid keeps data-infinite-scroll (regression guard)'
);
assert_true(
    false === strpos( $leisure_html, 'data-load-more' ),
    '/lazer/ grid is NOT tagged data-load-more (regression guard)'
);

// No grid may carry both handlers at once: that would bind the competing
// initInfiniteScroll() and initLoadMore() behaviours to the same markup.
foreach ( array(
    'cursos' => $course_html,
    'eventos' => $event_html,
    'lazer' => $leisure_html,
    'guias' => $guide_html,
) as $label => $html ) {
    assert_true(
        ! ( false !== strpos( $html, 'data-infinite-scroll' ) && false !== strpos( $html, 'data-load-more' ) ),
        '/' . $label . '/ grid never carries both data-infinite-scroll and data-load-more'
    );
}

// ---------------------------------------------------------------------------
// Cards expose stable IDs for the shared duplicate-detection guard
// ---------------------------------------------------------------------------

test_section( 'Course-card stable IDs' );

assert_true(
    false !== strpos( $course_html, 'id="post-' ),
    '/cursos/ cards expose id="post-{ID}" for the shared duplicate guard'
);

// ---------------------------------------------------------------------------
// Infinite scroll needs a next-page URL from the server-rendered pagination
// ---------------------------------------------------------------------------

test_section( 'Pagination next-page URL source' );

assert_true(
    false !== strpos( $course_html, 'conexao-pagination' ),
    '/cursos/ renders the .conexao-pagination component'
);
assert_true(
    false === strpos( $course_html, 'load-more__button' ),
    '/cursos/ has no server-rendered Carregar mais button markup'
);

test_finish( 'cursos infinite-scroll retag' );
