<?php
/**
 * Tests for the /eventos/ infinite-scroll retag.
 *
 * Verifies the archive.php progressive-enhancement retag that moves the
 * Eventos archive from the manual "Carregar mais" Load More
 * (`data-load-more`) to the shared automatic Infinite Scroll
 * (`data-infinite-scroll`) already used by /lazer/, /blog/ and /guias/.
 *
 *  - /eventos/ grid is tagged `data-infinite-scroll` (NOT `data-load-more`)
 *  - /lazer/ grid keeps `data-infinite-scroll` (regression)
 *  - /cursos/ grid keeps `data-load-more` (regression)
 *  - /eventos/ renders `.conexao-pagination` with a `page-numbers next`
 *    link so the shared initInfiniteScroll() has a next-page URL to fetch
 *  - No server-rendered Carregar mais button markup on /eventos/
 *
 * Creates no content and modifies nothing.
 *
 * Usage:
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-eventos-infinite-scroll.php
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
function eis_render_archive( $post_type ) {
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
// Grid tagging: Eventos uses infinite scroll, Lazer keeps it, Cursos keeps load-more
// ---------------------------------------------------------------------------

test_section( 'Grid progressive-enhancement tagging' );

$event_html   = eis_render_archive( 'event' );
$leisure_html = eis_render_archive( 'leisure' );
$course_html  = eis_render_archive( 'course_provider' );

assert_true(
    false === strpos( $event_html, 'data-load-more' ),
    '/eventos/ grid is NOT tagged data-load-more (Load More removed)'
);
assert_true(
    false !== strpos( $event_html, 'data-infinite-scroll' ),
    '/eventos/ grid IS tagged data-infinite-scroll (Infinite Scroll added)'
);
assert_true(
    false !== strpos( $leisure_html, 'data-infinite-scroll' ),
    '/lazer/ grid keeps data-infinite-scroll (regression guard)'
);
assert_true(
    false === strpos( $leisure_html, 'data-load-more' ),
    '/lazer/ grid is NOT tagged data-load-more (regression guard)'
);
assert_true(
    false !== strpos( $course_html, 'data-load-more' ),
    '/cursos/ grid keeps data-load-more (regression guard)'
);
assert_true(
    false === strpos( $course_html, 'data-infinite-scroll' ),
    '/cursos/ grid is NOT tagged data-infinite-scroll (regression guard)'
);

// ---------------------------------------------------------------------------
// Infinite scroll needs a next-page URL from the server-rendered pagination
// ---------------------------------------------------------------------------

test_section( 'Pagination next-page URL source' );

assert_true(
    false !== strpos( $event_html, 'conexao-pagination' ),
    '/eventos/ renders the .conexao-pagination component'
);
assert_true(
    false !== strpos( $event_html, 'next page-numbers' ),
    '/eventos/ pagination exposes a next-page link (class="next page-numbers") for initInfiniteScroll() to fetch'
);
assert_true(
    false === strpos( $event_html, 'load-more__button' ),
    '/eventos/ has no server-rendered Carregar mais button markup'
);

test_finish( 'eventos infinite-scroll retag' );
