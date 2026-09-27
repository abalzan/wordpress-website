<?php
/**
 * Tests for the Events archive location filters (County + City/Town).
 *
 * Verifies:
 *  - Location normalization (Conexao_Event_Location, local importer plugin):
 *    county derivation from 'Co X' markers + structured town index; town
 *    normalization (trim/case); missing city/town handling.
 *  - conexao_get_event_towns(): all towns vs county-scoped towns
 *    (County -> City cascade), empty for unknown counties.
 *  - conexao_event_filter_url(): shared URL builder (full state preserved,
 *    empty dimensions omitted, page cursor reset, no filter -> base URL).
 *  - The event branch of conexao_content_archive_query(): ?county=,
 *    ?cidade=, ?categoria= AND semantics, invalid slugs yield zero results
 *    gracefully, events missing town/county stay discoverable, the
 *    recurrence-aware post__in path keeps working with filters.
 *  - Template markup: the Lazer/Empregos-standard filter widget
 *    (dropdown triggers with listbox options, active-filter chips,
 *    mobile bottom-sheet form), county-scoped town options, active
 *    states, reset link, state preservation across dimension links.
 *
 * Creates temporary terms (unique test slugs) and temporary event posts
 * and deletes both at the end; never modifies existing records/terms.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-event-location-filters.php
 */

// --- Bootstrap WordPress (plugins + theme option). ---
$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

// Load the active theme so the helpers under test are defined (the active
// theme is not auto-loaded by wp-load.php in a CLI context).
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

/** Get or create a temporary term; returns the slug.
 *
 * Test-owned prefix discipline: slugs under the unique 'ev-' prefix are
 * created by this test only (production data never uses them), so terms
 * ADOPTED from a previous interrupted run are tracked for cleanup too —
 * the fixture never adopts (and never deletes) production terms.
 */
function ev_test_term( $name, $slug, $taxonomy ) {
	global $created_terms;
	$existing = term_exists( $slug, $taxonomy );
	if ( $existing && ! is_wp_error( $existing ) ) {
		// Adopted test-owned term from an interrupted earlier run.
		$created_terms[ $taxonomy ][] = (int) $existing['term_id'];
		return $slug;
	}
	$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
	if ( is_wp_error( $inserted ) ) {
		echo "  FATAL: could not create {$taxonomy} term: {$inserted->get_error_message()}\n";
		exit( 1 );
	}
	$created_terms[ $taxonomy ][] = (int) $inserted['term_id'];
	return $slug;
}

/** Create a temporary published event with the given location taxonomy slugs. */
function ev_test_event( $title, $county_slug, $town_slug, $category_slug ) {
	global $created_posts;
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'event',
			'post_title'   => $title,
			'post_status'  => 'publish',
			'post_content' => 'Test event for location filters.',
		)
	);
	if ( is_wp_error( $post_id ) ) {
		echo "  FATAL: could not create event: {$post_id->get_error_message()}\n";
		exit( 1 );
	}
	$created_posts[] = (int) $post_id;
	// Future date so the upcoming-events query includes it.
	update_post_meta( $post_id, '_event_date', gmdate( 'Y-m-d', strtotime( '+30 days' ) ) );
	if ( $county_slug ) {
		wp_set_object_terms( $post_id, array( $county_slug ), 'conexao_county', false );
	}
	if ( $town_slug ) {
		wp_set_object_terms( $post_id, array( $town_slug ), 'conexao_town', false );
	}
	if ( $category_slug ) {
		wp_set_object_terms( $post_id, array( $category_slug ), 'conexao_category', false );
	}
	return (int) $post_id;
}

/**
 * Run the event main-query filtering exactly like the archive would: the
 * new query is swapped in as the "main query", $_GET is set, then
 * pre_get_posts runs inside WP_Query::query() - the real hook path.
 */
function ev_test_query( array $get_params ) {
	$tmp_get                 = $_GET;
	$_GET                    = $get_params;
	$saved_wp_the_query      = $GLOBALS['wp_the_query'];
	$q                       = new WP_Query();
	$GLOBALS['wp_the_query'] = $q;
	$q->query(
		array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
		)
	);
	$GLOBALS['wp_the_query'] = $saved_wp_the_query;
	$_GET                    = $tmp_get;
	return $q;
}

/** Render the events filters template part with the given $_GET and return the HTML. */
function ev_test_render_filters( array $get_params ) {
	$tmp_get = $_GET;
	$_GET    = $get_params;
	ob_start();
	include get_template_directory() . '/template-parts/event-filters.php';
	$html = ob_get_clean();
	$_GET = $tmp_get;
	return $html;
}

// ---------------------------------------------------------------------------
// A/B. Location normalization (importer plugin - local only).
// ---------------------------------------------------------------------------
t_section( 'A/B. Location normalization (Conexao_Event_Location)' );

if ( class_exists( 'Conexao_Event_Location' ) ) {
	$loc = new Conexao_Event_Location();

	// County derived from a 'Co X' marker.
	$r = $loc->normalize( 'Park Hotel, Dungarvan, Co Waterford' );
	t_assert( isset( $r['county'] ) && 'Waterford' === $r['county'], "county derived from 'Co Waterford' marker" );
	t_assert( isset( $r['town'] ) && 'Dungarvan' === $r['town'], 'town from the national town index (deterministic)' );

	// Structured 'County X' spelling.
	$r = $loc->normalize( 'County Laois' );
	t_assert( isset( $r['county'] ) && 'Laois' === $r['county'], "county derived from 'County Laois'" );

	// Known town implies its county (deterministic mapping, no guessing).
	$r = $loc->normalize( 'Portlaoise' );
	t_assert( isset( $r['town'] ) && 'Portlaoise' === $r['town'], 'known town name normalized (canonical casing)' );

	// Unknown strings never produce a guessed county.
	$r = $loc->normalize( 'Somewhere Unmapped Hall' );
	t_assert( empty( $r['county'] ), 'unmappable location yields no county (no guessing)' );

	// Whitespace/case normalization of the town value.
	$r = $loc->normalize( '  portlaoise  ' );
	t_assert( isset( $r['town'] ) && 'Portlaoise' === $r['town'], 'town value is trimmed and proper-cased' );

	// Empty location stays empty.
	$r = $loc->normalize( '' );
	t_assert( empty( $r['county'] ) && empty( $r['town'] ), 'empty location normalizes to nothing' );
} else {
	echo "  SKIP: Conexao_Event_Location not loaded (importer inactive).\n";
}

// ---------------------------------------------------------------------------
// Fixture: temporary terms + four events.
//   A: county Cavan / town Cavan Town / category Alpha
//   B: county Cavan / no town      / category Beta   (missing town)
//   C: county Dublin / town Dublin Town / category Alpha
//   D: no county / no town / category Beta           (missing county)
// ---------------------------------------------------------------------------
t_section( 'Fixture' );

$co_cavan  = ev_test_term( 'EV Cavan', 'ev-cavan', 'conexao_county' );
$co_dublin = ev_test_term( 'EV Dublin', 'ev-dublin', 'conexao_county' );
$t_ctown   = ev_test_term( 'EV Cavan Town', 'ev-cavan-town', 'conexao_town' );
$t_dtown   = ev_test_term( 'EV Dublin Town', 'ev-dublin-town', 'conexao_town' );
$c_alpha   = ev_test_term( 'EV Alpha', 'ev-alpha', 'conexao_category' );
$c_beta    = ev_test_term( 'EV Beta', 'ev-beta', 'conexao_category' );

$post_a = ev_test_event( 'EV Event A', $co_cavan, $t_ctown, $c_alpha );
$post_b = ev_test_event( 'EV Event B', $co_cavan, '', $c_beta );
$post_c = ev_test_event( 'EV Event C', $co_dublin, $t_dtown, $c_alpha );
$post_d = ev_test_event( 'EV Event D', '', '', $c_beta );

// The archive path reads the date-keyed upcoming-events transient; a stale
// one (built before this test's fixture existed) must not leak into the
// assertions. Same discipline as the event-runtime's own query test.
if ( class_exists( 'Conexao_Event_Query' ) ) {
	Conexao_Event_Query::flush_cache();
}

t_assert( get_post( $post_a ) instanceof WP_Post && get_post( $post_b ) instanceof WP_Post && get_post( $post_c ) instanceof WP_Post && get_post( $post_d ) instanceof WP_Post, 'temporary event posts created' );

// ---------------------------------------------------------------------------
// C/D/E/F/G/H/I/J. Archive query semantics (real pre_get_posts path).
// ---------------------------------------------------------------------------
t_section( 'Archive query semantics' );

// Unfiltered: all four events remain discoverable.
$q = ev_test_query( array() );
$ids = wp_list_pluck( $q->posts, 'ID' );
t_assert( in_array( $post_a, $ids, true ) && in_array( $post_b, $ids, true ) && in_array( $post_c, $ids, true ) && in_array( $post_d, $ids, true ), 'unfiltered archive returns all four test events (incl. missing county/town)' );

// County only.
$q = ev_test_query( array( 'county' => 'ev-cavan' ) );
t_assert( array( $post_a, $post_b ) === wp_list_pluck( $q->posts, 'ID' ), 'county filter returns both county events' );

// City only.
$q = ev_test_query( array( 'cidade' => 'ev-cavan-town' ) );
t_assert( array( $post_a ) === wp_list_pluck( $q->posts, 'ID' ), 'city filter works without a county (AND across independent dimensions)' );

// County + City: an event without a town still satisfies county alone (I).
$q = ev_test_query( array( 'county' => 'ev-cavan', 'cidade' => 'ev-cavan-town' ) );
t_assert( array( $post_a ) === wp_list_pluck( $q->posts, 'ID' ), 'county AND city combination is deterministic' );

// County + Category.
$q = ev_test_query( array( 'county' => 'ev-cavan', 'categoria' => 'ev-beta' ) );
t_assert( array( $post_b ) === wp_list_pluck( $q->posts, 'ID' ), 'county AND category combination' );

// County + City + Category: no event matches beta in cavan-town.
$q = ev_test_query( array( 'county' => 'ev-cavan', 'cidade' => 'ev-cavan-town', 'categoria' => 'ev-alpha' ) );
t_assert( array( $post_a ) === wp_list_pluck( $q->posts, 'ID' ), 'county AND city AND category' );

$q = ev_test_query( array( 'county' => 'ev-cavan', 'cidade' => 'ev-cavan-town', 'categoria' => 'ev-beta' ) );
t_assert( 0 === $q->found_posts, 'impossible combination returns zero results gracefully' );

// A city filter can never override the county selection.
$q = ev_test_query( array( 'county' => 'ev-cavan', 'cidade' => 'ev-dublin-town' ) );
t_assert( 0 === $q->found_posts, 'city from another county cannot override the county filter (AND semantics)' );

// Invalid slugs fail gracefully (zero results, no error).
$q = ev_test_query( array( 'county' => 'ev-naoexiste' ) );
t_assert( 0 === $q->found_posts, 'invalid county slug yields zero results' );
$q = ev_test_query( array( 'cidade' => 'ev-naoexiste' ) );
t_assert( 0 === $q->found_posts, 'invalid city slug yields zero results' );
$q = ev_test_query( array( 'categoria' => 'ev-naoexiste' ) );
t_assert( 0 === $q->found_posts, 'invalid category slug yields zero results' );

// Missing-county event (D) is found by category and by city scoping.
$q = ev_test_query( array( 'categoria' => 'ev-beta', 'cidade' => 'ev-cavan-town' ) );
t_assert( array() === wp_list_pluck( $q->posts, 'ID' ), 'AND across city+category keeps excluding unrelated events' );
$q = ev_test_query( array( 'categoria' => 'ev-beta' ) );
t_assert( in_array( $post_d, wp_list_pluck( $q->posts, 'ID' ), true ) && in_array( $post_b, wp_list_pluck( $q->posts, 'ID' ), true ), 'events without county/town stay discoverable via other filters (J)' );

// tax_query structure: AND relation, one group per active dimension.
$q = ev_test_query( array( 'county' => 'ev-cavan', 'cidade' => 'ev-cavan-town', 'categoria' => 'ev-alpha' ) );
$tax_query = $q->get( 'tax_query' );
$tq = is_array( $tax_query ) ? ( isset( $tax_query['queries'] ) ? $tax_query['queries'] : $tax_query ) : array();
$groups = array_values( array_filter( is_array( $tq ) ? $tq : array(), 'is_array' ) );
t_assert( 3 === count( $groups ), 'tax_query contains one group per active dimension' );

// The recurrence-aware post__in path is active and our future-dated events are in it.
$upcoming = conexao_event_upcoming_ids();
t_assert( is_array( $upcoming ) && in_array( $post_a, $upcoming, true ), 'test event is in the shared upcoming-events ID list (post__in path works with filters)' );

// ---------------------------------------------------------------------------
// County -> City scoping + URL builder.
// ---------------------------------------------------------------------------
t_section( 'County -> City scoping + URL helper' );

$all_towns = conexao_get_event_towns( '' );
$all_slugs = wp_list_pluck( $all_towns, 'slug' );
t_assert( in_array( $t_ctown, $all_slugs, true ) && in_array( $t_dtown, $all_slugs, true ), 'all towns used by events are listed without a county' );

$cavan_towns = conexao_get_event_towns( 'ev-cavan' );
$cavan_slugs = wp_list_pluck( $cavan_towns, 'slug' );
t_assert( in_array( $t_ctown, $cavan_slugs, true ) && ! in_array( $t_dtown, $cavan_slugs, true ), 'towns are scoped to the selected county' );

t_assert( array() === conexao_get_event_towns( 'ev-naoexiste' ), 'unknown county yields no town options' );

$base = get_post_type_archive_link( 'event' );
$url = conexao_event_filter_url( array() );
t_assert( remove_query_arg( array( 'county', 'cidade', 'categoria' ), $url ) === $base || $url === $base, 'empty filter state builds the clean archive URL (reset behavior)' );

$url = conexao_event_filter_url( array( 'county' => 'ev-cavan', 'cidade' => 'ev-cavan-town', 'categoria' => 'ev-alpha' ) );
t_assert( false !== strpos( $url, 'county=ev-cavan' ) && false !== strpos( $url, 'cidade=ev-cavan-town' ) && false !== strpos( $url, 'categoria=ev-alpha' ), 'full filter state is preserved in one URL (shareable/refresh-safe)' );

$url = conexao_event_filter_url( array( 'county' => 'ev-cavan' ), $base . '?paged=2' );
t_assert( false === strpos( $url, 'paged=' ), 'filter changes reset the page cursor' );

t_assert( conexao_event_filter_url( array( 'county' => 'EV-Cavan' ) ) === conexao_event_filter_url( array( 'county' => 'ev-cavan' ) ), 'URL slugs are canonicalized (case normalization)' );

// ---------------------------------------------------------------------------
// Template markup: Lazer-standard filter widget (dropdowns, chips, sheet).
// ---------------------------------------------------------------------------
t_section( 'Filter template markup' );

$html = ev_test_render_filters( array() );
t_assert( false !== strpos( $html, 'data-event-filters' ), 'filter widget root rendered (Lazer/Empregos widget contract)' );
t_assert( false !== strpos( $html, 'event-filters-dropdown-trigger' ) && false !== strpos( $html, 'role="listbox"' ) && false !== strpos( $html, 'aria-selected' ), 'desktop dropdown triggers with listbox option semantics rendered' );
t_assert( false !== strpos( $html, 'county=ev-cavan' ) && false !== strpos( $html, 'county=ev-dublin' ), 'county options rendered from counties used by events' );
t_assert( false !== strpos( $html, 'cidade=ev-cavan-town' ) && false !== strpos( $html, 'cidade=ev-dublin-town' ), 'city options rendered (all towns, no county selected)' );
t_assert( false !== strpos( $html, 'categoria=ev-alpha' ), 'category options still rendered (existing filter preserved)' );
$county_count   = count( conexao_get_terms_for_post_type( 'conexao_county', 'event' ) );
$town_count     = count( conexao_get_event_towns() );
$expects_search = ( $county_count > 8 || $town_count > 8 );
t_assert( $expects_search === ( false !== strpos( $html, 'data-option-search' ) ), 'client-side search rendered only for long option lists (matches Lazer/Empregos threshold)' );
t_assert( false === strpos( $html, 'Limpar filtros' ) && false === strpos( $html, 'Filtros ativos' ), 'reset link and active chips hidden when no filter is active' );
t_assert( false !== strpos( $html, 'data-mobile-form' ) && false !== strpos( $html, 'Mostrar resultados' ), 'mobile bottom sheet form rendered with the apply action' );
t_assert( false !== strpos( $html, 'name="county"' ) && false !== strpos( $html, 'name="cidade"' ) && false !== strpos( $html, 'name="categoria"' ), 'mobile sheet radios carry the existing event query params' );

$html = ev_test_render_filters( array( 'county' => 'ev-cavan', 'categoria' => 'ev-alpha' ) );
t_assert( false !== strpos( $html, 'cidade=ev-cavan-town' ) && false === strpos( $html, 'cidade=ev-dublin-town' ), 'city options scoped to the selected county in the template' );
t_assert( false !== strpos( $html, 'Limpar filtros' ) && false !== strpos( $html, 'Filtros ativos' ), 'reset link and active chips appear when a filter is active' );
t_assert( false !== strpos( $html, 'Remover filtro:' ), 'active filter chips are removable per dimension' );

// County option link while categoria active: keeps categoria, clears other-county city.
$needle = 'href="' . esc_url( conexao_event_filter_url( array( 'county' => '', 'cidade' => '', 'categoria' => 'ev-alpha' ) ) ) . '"';
t_assert( false !== strpos( $html, $needle ), 'county toggle link preserves the category dimension' );

// ---------------------------------------------------------------------------
// Cleanup: temporary posts + terms. Never touches existing records.
// ---------------------------------------------------------------------------
t_section( 'Cleanup' );

foreach ( $created_posts as $pid ) {
	wp_delete_post( $pid, true );
}
foreach ( $created_terms as $tax => $term_ids ) {
	foreach ( $term_ids as $tid ) {
		wp_delete_term( $tid, $tax );
	}
}
if ( class_exists( 'Conexao_Event_Query' ) ) {
	Conexao_Event_Query::flush_cache();
}

t_assert( null === get_post( $post_a ) && null === get_post( $post_b ) && null === get_post( $post_c ) && null === get_post( $post_d ), 'temporary event posts removed' );
t_assert( null === term_exists( $t_ctown, 'conexao_town' ) && null === term_exists( $t_dtown, 'conexao_town' ), 'temporary town terms removed' );

echo "\nRESULTS: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
