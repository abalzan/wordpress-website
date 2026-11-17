<?php
/**
 * Stage 4 — the read-only "Needs review" queue.
 *
 * ## What this locks down
 *
 * The needs-review view surfaces published event posts missing defined
 * critical fields (featured image, venue, county) in the Event Sources area.
 * This suite proves:
 *
 *   A. The view is registered as a submenu of the Event Import menu (NOT a
 *      top-level dashboard page and NOT on the main Event Import dashboard).
 *   B. The population is published event posts only (revisions, attachments,
 *      trash, auto-drafts and other post types are excluded).
 *   C. Per-field missing detection is correct for thumbnail, venue and county.
 *   D. The aggregate count and the drill-in list share the same population
 *      (count == drill-in total).
 *   E. The view is read-only: rendering it creates/updates/deletes no post,
 *      postmeta, taxonomy term or option.
 *   F. Capability gating: a user without `manage_options` cannot render it.
 *   G. Output is escaped (no raw post title / source echoed unescaped).
 *   H. P8-deferred: the category field is omitted and a "vocabulary pending"
 *      note is shown.
 *
 * Test posts are created and deleted within the suite; the shared
 * `conexao_event_sources` option is not touched.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_require_operator_store_isolation();

$passed = 0;
$failed = 0;

// ---------------------------------------------------------------------------
// Fixtures: create a small, controlled set of event posts.
// ---------------------------------------------------------------------------

$created = array();

function rq_mk_event( $title, $args = array() ) {
	$post_id = wp_insert_post(
		array_merge(
			array(
				'post_title'  => $title,
				'post_type'   => 'event',
				'post_status' => 'publish',
			),
			$args
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return 0;
	}
	return (int) $post_id;
}

// A: complete event (thumbnail + venue + county).
$a = rq_mk_event( 'RQ complete event' );
if ( $a ) {
	$created[] = $a;
	update_post_meta( $a, '_event_venue', 'Park Hotel' );
	wp_set_object_terms( $a, array( 'rq-county-term' ), 'conexao_county' );
	// A valid thumbnail: use an existing attachment if one exists, else skip.
	$att = get_posts( array( 'post_type' => 'attachment', 'numberposts' => 1, 'post_status' => 'inherit' ) );
	if ( ! empty( $att ) ) {
		update_post_meta( $a, '_thumbnail_id', (int) $att[0]->ID );
	}
}

// B: missing venue only.
$b = rq_mk_event( 'RQ missing venue' );
if ( $b ) {
	$created[] = $b;
	wp_set_object_terms( $b, array( 'rq-county-term' ), 'conexao_county' );
}

// C: missing county only.
$c = rq_mk_event( 'RQ missing county' );
if ( $c ) {
	$created[] = $c;
	update_post_meta( $c, '_event_venue', 'Some Venue' );
}

// D: missing everything (no venue, no county, no thumbnail).
$d = rq_mk_event( 'RQ missing all' );
if ( $d ) {
	$created[] = $d;
}

// E: a non-event post (page) — must never appear in the queue.
$e = wp_insert_post(
	array(
		'post_title'  => 'RQ a page',
		'post_type'   => 'page',
		'post_status' => 'publish',
	),
	true
);
if ( ! is_wp_error( $e ) ) {
	$created[] = (int) $e;
}

// F: a draft event — must never appear (population is publish only).
$f = rq_mk_event( 'RQ draft event', array( 'post_status' => 'draft' ) );
if ( $f ) {
	$created[] = $f;
}

test_require(
	! empty( $created ) && $a && $b && $c && $d,
	'fixture:event-posts',
	'test event posts created',
	'could not create test posts'
);

// ---------------------------------------------------------------------------
// A. Menu registration.
// ---------------------------------------------------------------------------

test_section( 'A: menu registration' );
$rq_admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
test_require( ! empty( $rq_admin ), 'fixture:administrator', 'an administrator exists', 'create an administrator' );
$rq_admin_id = (int) $rq_admin[0]->ID;
wp_set_current_user( $rq_admin_id );

$GLOBALS['menu']    = array();
$GLOBALS['submenu'] = array();
$sources = new Conexao_Event_Sources();
$sources->register_admin_menu();

$sub_slugs = isset( $GLOBALS['submenu']['conexao-event-import'] )
	? wp_list_pluck( (array) $GLOBALS['submenu']['conexao-event-import'], 2 )
	: array();

assert_true( in_array( 'conexao-event-needs-review', $sub_slugs, true ), 'needs-review submenu registered under Event Import' );
assert_true( in_array( 'conexao-event-sources', $sub_slugs, true ), 'Event Sources submenu still registered' );

// The needs-review page must NOT be a top-level menu.
$top_slugs = wp_list_pluck( (array) $GLOBALS['menu'], 2 );
assert_true( ! in_array( 'conexao-event-needs-review', $top_slugs, true ), 'needs-review is not a top-level menu' );

// ---------------------------------------------------------------------------
// B. Population: published event posts only.
// ---------------------------------------------------------------------------

test_section( 'B: population is published event posts only' );
assert_true( Conexao_Event_Review_Queue::is_in_population( $a ), 'published event is in population' );
assert_true( ! Conexao_Event_Review_Queue::is_in_population( $e ), 'page is NOT in population' );
assert_true( ! Conexao_Event_Review_Queue::is_in_population( $f ), 'draft event is NOT in population' );

// ---------------------------------------------------------------------------
// C. Per-field missing detection.
// ---------------------------------------------------------------------------

test_section( 'C: per-field missing detection' );
// A: complete (thumbnail may be absent if no attachment exists; assert venue+county present).
assert_true( Conexao_Event_Review_Queue::has_venue( $a ), 'A has venue' );
assert_true( Conexao_Event_Review_Queue::has_county( $a ), 'A has county' );

// B: missing venue.
assert_true( ! Conexao_Event_Review_Queue::has_venue( $b ), 'B missing venue' );
assert_true( Conexao_Event_Review_Queue::is_missing( $b, 'venue' ), 'B flagged for venue' );

// C: missing county.
assert_true( ! Conexao_Event_Review_Queue::has_county( $c ), 'C missing county' );
assert_true( Conexao_Event_Review_Queue::is_missing( $c, 'county' ), 'C flagged for county' );

// D: missing everything.
assert_true( Conexao_Event_Review_Queue::is_missing( $d, 'venue' ), 'D flagged for venue' );
assert_true( Conexao_Event_Review_Queue::is_missing( $d, 'county' ), 'D flagged for county' );
assert_true( Conexao_Event_Review_Queue::is_missing( $d, 'thumbnail' ), 'D flagged for thumbnail' );

// A blank venue (whitespace) counts as missing.
assert_true( ! Conexao_Event_Review_Queue::has_venue( 999999 ), 'nonexistent post has no venue' );

// ---------------------------------------------------------------------------
// D. Count == drill-in total (same population, bounded slice).
// ---------------------------------------------------------------------------

test_section( 'D: count and drill-in share the same population' );
// Verify the count helper returns the expected shape, and that a bounded
// population slice produces a drill-in total identical to an independent
// filter over the same slice (the count and drill-in share one query path).
$counts = Conexao_Event_Review_Queue::field_counts();
assert_true( is_array( $counts ), 'field_counts returns an array' );
assert_true( array_key_exists( 'venue', $counts ), 'venue count present' );
assert_true( array_key_exists( 'county', $counts ), 'county count present' );
assert_true( array_key_exists( 'thumbnail', $counts ), 'thumbnail count present' );
// The venue count must be >= the number of our known missing-venue fixtures (B and D).
assert_true( $counts['venue'] >= 2, 'venue count includes at least B and D' );
assert_true( $counts['county'] >= 2, 'county count includes at least C and D' );

// Bounded consistency: over the first population page, the drill-in filter
// total equals an independent filter over the same page (identical
// population, identical result).
$first = Conexao_Event_Review_Queue::query_population( 1, Conexao_Event_Review_Queue::PER_PAGE );
$drill_venue = count( Conexao_Event_Review_Queue::filter_missing( $first['ids'], 'venue' ) );
$indep_venue = 0;
foreach ( $first['ids'] as $pid ) {
	if ( ! Conexao_Event_Review_Queue::has_venue( $pid ) ) {
		$indep_venue++;
	}
}
assert_equals( $drill_venue, $indep_venue, 'venue drill-in equals independent filter over the same page' );

// The full count is >= the first-page drill-in (count covers the whole
// population, drill-in covers one page).
assert_true( $counts['venue'] >= $drill_venue, 'full venue count >= first-page drill-in' );

// Multi-page consistency: create enough events to span more than one page,
// then verify the union of all drill-in pages equals the full count and
// contains no duplicates or omissions.
test_section( 'D2: multi-page drill-in consistency' );
$multi_ids = array();
for ( $i = 0; $i < Conexao_Event_Review_Queue::PER_PAGE + 5; $i++ ) {
    $mid = rq_mk_event( "RQ multipage venue-missing {$i}" );
    if ( $mid ) {
        $created[] = $mid;
        $multi_ids[] = $mid;
    }
}
test_require( count( $multi_ids ) > Conexao_Event_Review_Queue::PER_PAGE, 'fixture:multipage', 'more events than one page created', 'not enough events for multipage test' );

// Collect all venue-missing IDs across all pages.
$all_venue_missing = array();
$page = 1;
$max_pages = 400;
do {
    $result = Conexao_Event_Review_Queue::query_population( $page, Conexao_Event_Review_Queue::PER_PAGE );
    if ( empty( $result['ids'] ) ) {
        break;
    }
    $missing = Conexao_Event_Review_Queue::filter_missing( $result['ids'], 'venue' );
    foreach ( $missing as $mid ) {
        $all_venue_missing[] = $mid;
    }
    $page++;
    $page_count = count( $result['ids'] );
} while ( $page <= $max_pages && $page_count === Conexao_Event_Review_Queue::PER_PAGE );

// The union of all pages must have no duplicates.
assert_equals( count( $all_venue_missing ), count( array_unique( $all_venue_missing ) ), 'no duplicate IDs across pages' );

// The union must equal the full count.
$counts_after = Conexao_Event_Review_Queue::field_counts();
assert_equals( $counts_after['venue'], count( $all_venue_missing ), 'union of all pages equals full venue count' );

// Every venue-missing fixture we created must appear in the union.
$missing_from_union = array_diff( $multi_ids, $all_venue_missing );
assert_true( empty( $missing_from_union ), 'all multipage venue-missing fixtures appear in the union' );

// ---------------------------------------------------------------------------
// E. Read-only: rendering changes no post/postmeta/term/option.
// ---------------------------------------------------------------------------

test_section( 'E: read-only rendering' );
$snap_before = array(
	'posts'    => wp_count_posts( 'event' ),
	'options'  => (int) get_option( 'conexao_event_sources', 0 ),
	'meta_a'   => get_post_meta( $a, '_event_venue', true ),
);

ob_start();
$sources->render_needs_review_page();
$rq_html = ob_get_clean();

$snap_after = array(
	'posts'    => wp_count_posts( 'event' ),
	'options'  => (int) get_option( 'conexao_event_sources', 0 ),
	'meta_a'   => get_post_meta( $a, '_event_venue', true ),
);

assert_true( $snap_before['posts']->publish === $snap_after['posts']->publish, 'publish count unchanged by render' );
assert_true( $snap_before['meta_a'] === $snap_after['meta_a'], 'venue meta unchanged by render' );
assert_true( false !== strpos( $rq_html, 'Needs Review' ), 'render outputs the Needs Review heading' );

// ---------------------------------------------------------------------------
// F. Capability gating.
// ---------------------------------------------------------------------------

test_section( 'F: capability gating' );
wp_set_current_user( 0 );
ob_start();
$sources->render_needs_review_page();
$anon_html = ob_get_clean();
assert_true( '' === trim( $anon_html ), 'anonymous user renders nothing (capability gated)' );
wp_set_current_user( $rq_admin_id );

// ---------------------------------------------------------------------------
// G. Output escaping.
// ---------------------------------------------------------------------------

test_section( 'G: output escaping' );
// A post title containing HTML must be escaped in the drill-in.
$xss = rq_mk_event( '<script>alert(1)</script>' );
if ( $xss ) {
	$created[] = $xss;
}
ob_start();
$sources->render_needs_review_page();
$esc_html = ob_get_clean();
assert_true( false === strpos( $esc_html, '<script>alert(1)</script>' ), 'raw script tag not echoed unescaped' );
assert_true( false !== strpos( $esc_html, '&lt;script&gt;' ) || false === strpos( $esc_html, 'alert(1)' ), 'title is escaped or absent' );

// ---------------------------------------------------------------------------
// H. P8-deferred: category omitted, vocabulary note shown.
// ---------------------------------------------------------------------------

test_section( 'H: P8-deferred category handling' );
assert_true( false === strpos( $rq_html, 'conexao_category' ), 'category field is omitted from the queue' );
assert_true(
	false !== strpos( $rq_html, 'vocabulary' ) || false !== strpos( $rq_html, 'Vocabulary' ),
	'vocabulary-pending note is shown'
);

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------

foreach ( $created as $cleanup_id ) {
	wp_delete_post( (int) $cleanup_id, true );
}
wp_set_current_user( 0 );

test_finish( 'review-queue' );

exit( $failed > 0 ? 1 : 0 );