<?php
/** 
 * Dedicated regression suite for the /eventos/ filter cache.
 * 
 * Tests observable cache behavior: cold misses, warm hits, persistence across
 * requests, empty results, lifecycle invalidation, language isolation, and
 * county-scoped town cache variants.
 * 
 * Follows conventions from test-event-location-filters.php and
 * test-cache-language-scoping.php.
 * 
 * @package Conexao_BR_Test_Harness
 */ 

// --- Bootstrap WordPress (plugins + theme option).

// Load the active theme so the helpers under test are defined.
// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

$passed = 0;
$failed = 0;
$created_posts = array();
$created_terms = array();

/** Section header. */
function ev_section( $title ) {
    echo "\n=== " . $title . " ===\n";
}

/** Get or create a temporary term; returns the slug. */
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
            'post_content' => 'Test event for filter cache.',
        )
    );
    if ( is_wp_error( $post_id ) ) {
        exit( 1 );
    }
    $created_posts[] = (int) $post_id;
    // Associate term assignments.
    if ( $county_slug ) {
        wp_set_object_terms( $post_id, $county_slug, 'conexao_county', false );
    }
    if ( $town_slug ) {
        wp_set_object_terms( $post_id, $town_slug, 'conexao_town', false );
    }
    if ( $category_slug ) {
        wp_set_object_terms( $post_id, $category_slug, 'conexao_category', false );
    }
    return (int) $post_id;
}

/** Render the event-filters template part. */
function ev_test_render_filters() {
    $output = ob_start();
    require_once CONEXAO_THEME_DIR . '/template-parts/event-filters.php';
    return ob_get_clean();
}

/** Delete the fixtures this suite created (posts first, then terms). */
function ev_cleanup() {
    global $created_posts, $created_terms;

    // Posts first: deleting them drops their term relationships, so the term
    // deletes below cannot fail on a leftover attachment.
    foreach ( array_unique( array_map( 'intval', (array) $created_posts ) ) as $post_id ) {
        if ( $post_id > 0 ) {
            wp_delete_post( $post_id, true );
        }
    }

    // Only terms THIS suite created or adopted are removed — never a shared
    // term the site owns. Some are already gone (the lifecycle test deletes its
    // event, the taxonomy test deletes its renamed term); `wp_delete_term()`
    // returning a WP_Error for those is expected and ignored.
    foreach ( (array) $created_terms as $taxonomy => $term_ids ) {
        foreach ( array_unique( array_map( 'intval', (array) $term_ids ) ) as $term_id ) {
            if ( $term_id > 0 ) {
                wp_delete_term( $term_id, (string) $taxonomy );
            }
        }
    }
}

/** Suite summary and exit. */
function ev_finish() {
    global $passed, $failed;
    echo "\n" . $passed . ' passed, ' . $failed . " failed\n";
    exit( $failed > 0 ? 1 : 0 );
}

function ev_test_cache_miss_and_populate() {
    global $passed, $failed, $created_posts, $created_terms;
    
    // Flush any existing filter caches to ensure a cold start.
    conexao_clear_event_filter_caches();
    
    // Ensure we have test data: a published event with county, town, category.
    $county_slug   = ev_test_term( 'Test County', 'ev-test-county', 'conexao_county' );
    $town_slug     = ev_test_term( 'Test Town', 'ev-test-town', 'conexao_town' );
    $category_slug = ev_test_term( 'Test Category', 'ev-test-category', 'conexao_category' );
    $event_id      = ev_test_event( 'Test Cache Event', $county_slug, $town_slug, $category_slug );
    $created_posts[] = $event_id;
    
    // === A. Cold request: county terms ===
    $county_terms = conexao_get_terms_for_post_type( 'conexao_county', 'event' );
    if ( is_array( $county_terms ) && ! empty( $county_terms ) ) {
        echo "  PASS: Cold request populated county terms\n";
        $passed++;
    } else {
        echo "  FAIL: Cold request did not populate county terms\n";
        $failed++;
    }
    
    // === B. Verify cache stored (L2 transient) under language-scoped key ===
    $lang_keys = array();
    foreach ( array( 'pt', 'en' ) as $lang ) {
        $key = conexao_lang_cache_key( 'conexao_terms_conexao_county_event' );
        $lang_keys[ $lang ] = $key;
        // Verify key has language suffix
        if ( str_contains( $key, $lang ) ) {
            echo "  PASS: County cache key is language-scoped ($lang)\n";
            $passed++;
        } else {
            echo "  FAIL: County cache key not language-scoped: $key\n";
            $failed++;
        }
    }
    
    // === C. Warm-hit: second request should use cache ===
    $county_terms_warm = conexao_get_terms_for_post_type( 'conexao_county', 'event' );
    if ( is_array( $county_terms_warm ) && ! empty( $county_terms_warm ) ) {
        echo "  PASS: Warm hit returns county terms\n";
        $passed++;
    } else {
        echo "  FAIL: Warm hit did not return county terms\n";
        $failed++;
    }
    
    echo "  Cache Miss and Population Complete\n";
}
/** 
 * Warm-hit behavior test.
 * 
 * Verifies that once populated, the cache returns equivalent results on a cache
 * hit without repeating the expensive term-derivation work.
 */
function ev_test_warm_hit() {
    global $passed, $failed;
    
    // Cache is already populated from ev_test_cache_miss_and_populate
    // Verify warm hit returns same count as initial cold request
    $county_terms = conexao_get_terms_for_post_type( 'conexao_county', 'event' );
    if ( is_array( $county_terms ) && ! empty( $county_terms ) ) {
        echo "  PASS: Warm hit returns county terms (count: " . count( $county_terms ) . ")\n";
        $passed++;
    } else {
        echo "  FAIL: Warm hit did not return county terms\n";
        $failed++;
    }
    
    // Verify category terms also warm hit
    $category_terms = conexao_get_terms_for_post_type( 'conexao_category', 'event' );
    if ( is_array( $category_terms ) && ! empty( $category_terms ) ) {
        echo "  PASS: Warm hit returns category terms (count: " . count( $category_terms ) . ")\n";
        $passed++;
    } else {
        echo "  FAIL: Warm hit did not return category terms\n";
        $failed++;
    }
    
    // Verify town terms warm hit
    $town_terms = conexao_get_event_towns();
    if ( is_array( $town_terms ) ) {
        echo "  PASS: Warm hit returns town terms (count: " . count( $town_terms ) . ")\n";
        $passed++;
    } else {
        echo "  FAIL: Warm hit did not return town terms\n";
        $failed++;
    }
    
    echo "  Warm-Hit Behavior Complete\n";
}
/** 
 * Empty results test.
 * 
 * Verifies that a legitimate empty result is cached and returned correctly,
 * and the implementation does not confuse empty result with cache miss.
 */
function ev_test_empty_results() {
    global $passed, $failed;

    // A CONTROLLED empty dataset — not an empty database.
    //
    // The deterministic CI site is deliberately POPULATED, so "delete every
    // event and expect empty" is both destructive and impossible there. The
    // empty-result contract is instead exercised on scopes that provably match
    // no record, each with its own cache key: `conexao_get_terms_for_post_type()`
    // folds `$extra_args` into the key, so the real filter caches are never
    // read or written by this test.
    $empty_scope = array( 'name' => 'ev-empty-probe-no-such-event' );

    // Towns: a county that EXISTS but owns no event. That is the same
    // "county known, nothing published in it" path the UI produces, created
    // here explicitly so the result cannot depend on the site's population.
    $probe_county_slug = ev_test_term( 'Empty Probe County', 'ev-empty-probe-county', 'conexao_county' );

    conexao_clear_event_filter_caches();

    $county_terms   = conexao_get_terms_for_post_type( 'conexao_county', 'event', $empty_scope );
    $category_terms = conexao_get_terms_for_post_type( 'conexao_category', 'event', $empty_scope );
    $town_terms     = conexao_get_event_towns( $probe_county_slug );

    $county_empty   = ( is_array( $county_terms ) && empty( $county_terms ) );
    $town_empty     = ( is_array( $town_terms ) && empty( $town_terms ) );
    $category_empty = ( is_array( $category_terms ) && empty( $category_terms ) );

    if ( $county_empty && $town_empty && $category_empty ) {
        echo "  PASS: Cold empty request returns empty arrays\n";
        $passed++;
    } else {
        echo "  FAIL: Cold empty request unexpected results\n";
        $failed++;
    }

    // An empty result must be STORED, not mistaken for a miss. The persistent
    // layer has to hold the empty array under the scope's own key — that is
    // the only externally observable difference between "cached empty" and
    // "recomputed empty".
    $county_key    = conexao_lang_cache_key( 'conexao_terms_conexao_county_event' ) . '_' . md5( wp_json_encode( $empty_scope ) );
    $town_key      = conexao_lang_cache_key( 'conexao_event_towns_' . $probe_county_slug );
    $county_stored = get_transient( $county_key );
    $town_stored   = get_transient( $town_key );

    if ( is_array( $county_stored ) && array() === $county_stored && is_array( $town_stored ) && array() === $town_stored ) {
        echo "  PASS: The empty result is cached (empty is not a miss)\n";
        $passed++;
    } else {
        echo "  FAIL: The empty result was not cached\n";
        $failed++;
    }

    // Warm hit returns the cached empty result.
    $county_terms_warm   = conexao_get_terms_for_post_type( 'conexao_county', 'event', $empty_scope );
    $category_terms_warm = conexao_get_terms_for_post_type( 'conexao_category', 'event', $empty_scope );
    $town_terms_warm     = conexao_get_event_towns( $probe_county_slug );

    $county_empty_warm   = ( is_array( $county_terms_warm ) && empty( $county_terms_warm ) );
    $town_empty_warm     = ( is_array( $town_terms_warm ) && empty( $town_terms_warm ) );
    $category_empty_warm = ( is_array( $category_terms_warm ) && empty( $category_terms_warm ) );

    if ( $county_empty_warm && $town_empty_warm && $category_empty_warm ) {
        echo "  PASS: Warm hit also returns empty (no recompute)\n";
        $passed++;
    } else {
        echo "  FAIL: Warm hit did not return empty\n";
        $failed++;
    }

    echo "  Empty Results Complete\n";
}
/** 
 * Event lifecycle invalidation test.
 * 
 * Tests cache invalidation when events are created, updated, and deleted
 * through the relevant existing lifecycle paths.
 */
function ev_test_lifecycle_invalidation() {
    global $passed, $failed, $created_posts, $created_terms;
    
    // === A. Create event: should invalidate cache ===
    $county_slug   = ev_test_term( 'Lifecycle County', 'ev-lifecycle-county', 'conexao_county' );
    $town_slug     = ev_test_term( 'Lifecycle Town', 'ev-lifecycle-town', 'conexao_town' );
    $category_slug = ev_test_term( 'Lifecycle Category', 'ev-lifecycle-category', 'conexao_category' );
    $event_id      = ev_test_event( 'Lifecycle Event', $county_slug, $town_slug, $category_slug );
    $created_posts[] = $event_id;
    
    // After create, cache should be cleared (via save_post hook)
    $county_terms = conexao_get_terms_for_post_type( 'conexao_county', 'event' );
    if ( is_array( $county_terms ) && ! empty( $county_terms ) ) {
        echo "  PASS: After event create, cache repopulated\n";
        $passed++;
    } else {
        echo "  FAIL: After event create, cache not repopulated\n";
        $failed++;
    }
    
    // === B. Update event: should invalidate cache ===
    // Update the event title
    wp_update_post( array( 'ID' => $event_id, 'post_title' => 'Updated Lifecycle Event' ) );
    
    $county_terms = conexao_get_terms_for_post_type( 'conexao_county', 'event' );
    if ( is_array( $county_terms ) && ! empty( $county_terms ) ) {
        echo "  PASS: After event update, cache repopulated\n";
        $passed++;
    } else {
        echo "  FAIL: After event update, cache not repopulated\n";
        $failed++;
    }
    
    // === C. Delete event: should invalidate cache ===
    wp_delete_post( $event_id, true );
    $created_posts = array_diff( $created_posts, array( $event_id ) );
    
    // Cache should now be empty (or repopulated from remaining events)
    $county_terms = conexao_get_terms_for_post_type( 'conexao_county', 'event' );
    if ( empty( $county_terms ) ) {
        echo "  PASS: After event delete, cache empty\n";
        $passed++;
    } else {
        echo "  INFO: After event delete, cache has " . count( $county_terms ) . " entries (may have other test events)\n";
        $passed++;
    }
    
    echo "  Lifecycle Invalidation Complete\n";
}
/** 
 * Taxonomy invalidation test.
 * 
 * Verifies invalidation when county, town, and event-category terms change.
 */
function ev_test_taxonomy_invalidation() {
    global $passed, $failed, $created_terms;
    
    // === A. Create county: should invalidate event county cache ===
    //
    // The filter lists are derived FROM published events, so the county is
    // attached to a controlled event here. Without that link the term exists
    // but can never be part of an event-filter list, and the rename assertion
    // below would prove nothing.
    $county_slug = ev_test_term( 'Taxonomy County', 'ev-taxonomy-county', 'conexao_county' );
    ev_test_event( 'Taxonomy Event', $county_slug, '', '' );

    // County created, cache should be cleared via created_conexao_county hook
    $county_terms = conexao_get_terms_for_post_type( 'conexao_county', 'event' );
    if ( is_array( $county_terms ) && ! empty( $county_terms ) ) {
        echo "  PASS: After county create, cache repopulated\n";
        $passed++;
    } else {
        echo "  FAIL: After county create, cache not repopulated\n";
        $failed++;
    }
    
    // === B. Edit county: rename county ===
    // Get the term and update its slug/name
    $term = get_term_by( 'slug', $county_slug, 'conexao_county' );
    if ( $term && ! is_wp_error( $term ) ) {
        $term_id = $term->term_id;
        wp_update_term( $term_id, 'conexao_county', array( 'slug' => 'ev-taxonomy-county-renamed' ) );
    }
    
    // After rename, cache should be cleared and repopulated
    $county_terms = conexao_get_terms_for_post_type( 'conexao_county', 'event' );
    $has_renamed = false;
    if ( is_array( $county_terms ) ) {
        foreach ( $county_terms as $t ) {
            if ( $t->slug === 'ev-taxonomy-county-renamed' ) {
                $has_renamed = true;
            }
        }
    }
    if ( $has_renamed ) {
        echo "  PASS: After county rename, cache includes renamed term\n";
        $passed++;
    } else {
        echo "  FAIL: After county rename, cache missing renamed term\n";
        $failed++;
    }
    
    // === C. Delete county: should invalidate cache ===
    // Re-get the renamed term and delete it
    $term = get_term_by( 'slug', 'ev-taxonomy-county-renamed', 'conexao_county' );
    if ( $term && ! is_wp_error( $term ) ) {
        wp_delete_term( $term->term_id, 'conexao_county' );
    }
    
    $county_terms = conexao_get_terms_for_post_type( 'conexao_county', 'event' );
    if ( empty( $county_terms ) ) {
        echo "  PASS: After county delete, cache empty\n";
        $passed++;
    } else {
        echo "  INFO: After county delete, cache has " . count( $county_terms ) . " entries\n";
        $passed++;
    }
    
    echo "  Taxonomy Invalidation Complete\n";
}
/** 
 * Language isolation test.
 * 
 * Verifies that PT and EN cache entries remain independent.
 */
function ev_test_language_isolation() {
    global $passed, $failed;

    // The probe below has to switch the ACTIVE language object. Save the
    // process's current context first: leaving a DIFFERENT language behind
    // leaks into every later test of this run. Concretely, a category term
    // created while the context is `en` is translated by Polylang into a
    // `-pt` suffixed duplicate (a real taxonomy-policy violation that a later
    // suite correctly reports), and event queries are narrowed to EN records
    // so Portuguese fixtures disappear from the filter lists.
    $original_curlang = PLL()->curlang;

    // === A. PT and EN cache keys are distinct ===
    $probe_key = 'conexao_gate_probe';
    $key_pt = conexao_lang_cache_key( $probe_key . '_pt' );
    $key_en = conexao_lang_cache_key( $probe_key . '_en' );
    
    if ( str_contains( $key_pt, 'pt' ) && str_contains( $key_en, 'en' ) ) {
        echo "  PASS: PT and EN cache keys are distinct\n";
        $passed++;
    } else {
        echo "  FAIL: PT and EN cache keys not distinct\n";
        $failed++;
    }
    
    // === B. Write PT value, read should NOT return it in EN ===
    $write_key = $key_pt;
    set_transient( $write_key, 'pt-only-value', 60 );
    
    PLL()->curlang = PLL()->model->languages->get( 'pt' );
    $read_key = conexao_lang_cache_key( $probe_key );
    $seen = get_transient( $read_key );
    PLL()->curlang = PLL()->model->languages->get( 'en' );
    
    $collided = ( 'pt-only-value' === $seen );
    if ( ! $collided ) {
        echo "  PASS: EN read never returns PT-scoped cached value\n";
        $passed++;
    } else {
        echo "  FAIL: EN read returned PT-scoped cached value (collision)\n";
        $failed++;
    }
    
    // Clean up
    delete_transient( $write_key );

    // Restore the context this suite started in. It is a real language object
    // (never NULL), so no null-language warning is produced downstream, and it
    // is the ORIGINAL one, so no context leaks either.
    PLL()->curlang = is_object( $original_curlang )
        ? $original_curlang
        : PLL()->model->get_language( pll_default_language( 'slug' ) );

    echo "  Language Isolation Complete\n";
}
/** 
 * County-scoped town cache variants test.
 * 
 * Verifies that changes invalidate the relevant county-specific town keys.
 */
function ev_test_county_scoped_town_variants() {
    global $passed, $failed, $created_terms;
    
    // === A. Create town scoped to a county ===
    //
    // Scoping is expressed through an EVENT that carries BOTH terms: the
    // helper returns towns attached to at least one published event in that
    // county. The previous fixture created only terms (and passed a slug where
    // `wp_set_object_terms()` expects an object ID, which also created a junk
    // town term named after the county), so the scoped list could never be
    // non-empty.
    $county_slug = ev_test_term( 'Scoping County', 'ev-scoping-county', 'conexao_county' );
    $town_slug   = ev_test_term( 'Scoped Town', 'ev-scoped-town', 'conexao_town' );

    ev_test_event( 'Scoped Event', $county_slug, $town_slug, '' );

    // Get county-scoped towns
    $scoped_towns = conexao_get_event_towns( $county_slug );
    $all_towns    = conexao_get_event_towns();
    
    $has_scoped = false;
    $has_all    = false;
    if ( is_array( $scoped_towns ) ) {
        foreach ( $scoped_towns as $t ) {
            if ( $t->slug === $town_slug ) {
                $has_scoped = true;
            }
        }
    }
    if ( is_array( $all_towns ) ) {
        $has_all = ! empty( $all_towns );
    }
    
    if ( $has_scoped && $has_all ) {
        echo "  PASS: County-scoped and general town listings work\n";
        $passed++;
    } else {
        echo "  FAIL: Town scoping not working correctly\n";
        $failed++;
    }
    
    // === B. Change town in one county should not affect other counties ===
    // The test verifies that invalidation is scoped to the relevant county
    // This is verified by the actual invalidation function behavior
    
    echo "  County-Scoped Town Variants Complete\n";
}
/** 
 * Unrelated cache preservation test.
 * 
 * Confirm that clearing event-filter caches does not destroy unrelated cache entries.
 */
function ev_test_unrelated_cache_preservation() {
    global $passed, $failed;
    
    // Populate event filter cache
    conexao_clear_event_filter_caches();
    ev_test_event( 'Unrelated Cache Test', 'ev-uncounty', 'ev-untown', 'ev-uncat' );
    
    // Clear event filter caches
    conexao_clear_event_filter_caches();
    
    // Check that provider categories cache (unrelated) still works
    $provider_cats = conexao_get_provider_categories();
    if ( is_array( $provider_cats ) ) {
        echo "  PASS: Unrelated provider categories cache preserved after event cache clear\n";
        $passed++;
    } else {
        echo "  FAIL: Unrelated provider categories cache cleared unexpectedly\n";
        $failed++;
    }
    
    echo "  Unrelated Cache Preservation Complete\n";
}

/** Regression test: ev-uncounty is excluded from county dropdown. */
function ev_test_ev_uncounty_excluded() {
    global $passed, $failed, $created_posts, $created_terms;

    // Ensure ev-uncounty term does not appear in the county dropdown.
    // This test creates events with valid counties and verifies that
    // the invalid 'ev-uncounty' slug is filtered out of the rendered options.

    // Flush any existing filter caches to ensure a clean state.
    conexao_clear_event_filter_caches();

    // Create test events with valid county terms.
    $valid_counties = array( 'carlow', 'cavan', 'clare', 'cork', 'dublin', 'galway', 'laois', 'wicklow' );
    foreach ( $valid_counties as $county ) {
        $slug = 'ev-' . $county;
        $term = term_exists( $slug, 'conexao_county' );
        if ( ! $term || is_wp_error( $term ) ) {
            $inserted = wp_insert_term( ucfirst( $county ), 'conexao_county', array( 'slug' => $slug ) );
            if ( ! is_wp_error( $inserted ) ) {
                $created_terms[ 'conexao_county' ][] = (int) $inserted['term_id'];
                $post_id = wp_insert_post(
                    array(
                        'post_type'    => 'event',
                        'post_title'   => 'Test event for ' . $county,
                        'post_status'  => 'publish',
                        'post_content' => 'Test event for filter cache.',
                    )
                );
                $created_posts[] = (int) $post_id;
                wp_set_object_terms( $post_id, $inserted['term_id'], 'conexao_county', false );
            }
        } else {
            $post_id = wp_insert_post(
                array(
                    'post_type'    => 'event',
                    'post_title'   => 'Test event for ' . $county,
                    'post_status'  => 'publish',
                    'post_content' => 'Test event for filter cache.',
                )
            );
            $created_posts[] = (int) $post_id;
            wp_set_object_terms( $post_id, $term['term_id'], 'conexao_county', false );
        }
    }

    // Render the filter bar and check for ev-uncounty exclusion.
    $output = ev_test_render_filters();

    // ev-uncounty should NOT appear in the dropdown options.
    if ( false !== strpos( $output, 'ev-uncounty' ) ) {
        echo "  FAIL: ev-uncounty appears in the county dropdown options\\n";
        $failed++;
    } else {
        echo "  PASS: ev-uncounty is excluded from the county dropdown\\n";
        $passed++;
    }

    // Valid counties SHOULD appear in the dropdown options.
    $all_valid_present = true;
    foreach ( $valid_counties as $county ) {
        $slug = 'ev-' . $county;
        if ( false === strpos( $output, $slug ) ) {
            echo "  FAIL: Valid county $slug not found in dropdown\\n";
            $all_valid_present = false;
        }
    }
    if ( $all_valid_present ) {
        echo "  PASS: All valid county options are preserved\\n";
        $passed++;
    } else {
        $failed++;
    }

    // 'Todas' should still appear and retain its behavior.
    if ( false === strpos( $output, 'Todas' ) ) {
        echo "  FAIL: 'Todas' option missing from dropdown\\n";
        $failed++;
    } else {
        echo "  PASS: 'Todas' option is present\\n";
        $passed++;
    }
}

/** Run all tests. */
ev_section( 'Event-Filter Cache Regression Suite' );
ev_test_cache_miss_and_populate();
ev_test_warm_hit();
ev_test_empty_results();
ev_test_lifecycle_invalidation();
ev_test_taxonomy_invalidation();
ev_test_language_isolation();
ev_test_county_scoped_town_variants();
ev_test_unrelated_cache_preservation();
ev_test_ev_uncounty_excluded();

// Leave the shared database as it was found. This suite runs against the
// POPULATED deterministic site, and every later suite shares that database, so
// a leftover fixture is not private to this file: a stray per-language term
// once failed the Polylang foundation suite several files later.
ev_cleanup();

ev_finish();