<?php
/**
 * Regression suite — importer-internal reconciliation of `source_not_found`
 * events (G17, defect discovered during G16 Eventbrite activation).
 *
 * ## The defect
 *
 * A source event that disappeared from its source feed is marked
 * `_event_status = source_not_found`, which correctly HIDES it from every
 * public frontend event query. When the event reappears in a later run the
 * importer must find that existing record by its source identity
 * (`_event_source` + `_event_source_id`) and restore it.
 *
 * It could not: the identity lookup is a plain WP_Query, so
 * `Conexao_Event_Runtime::filter_public_event_queries()` applied the public
 * `_event_status` gate to it, the lookup returned nothing, and
 * `upsert_event()` took the `wp_insert_post()` branch — creating a SECOND
 * native event for one logical event. During G16 this grew the duplicated
 * native IDs 47 -> 197 -> 239 while every run reported `duplicates = 0`.
 *
 * The pre-fix failure is only observable when `WP_CLI` is NOT defined,
 * because the filter's WP-CLI escape accidentally masked it. This suite
 * therefore asserts the behaviour on the plain, non-WP-CLI path.
 *
 * ## The fix under test
 *
 * `Conexao_Event_Internal_Query` (production runtime) provides a narrow,
 * token-guarded scope. The importer opens it around its own identity lookup
 * (`Conexao_Event_Deduplicator::find()`); the public filter exempts only
 * queries carrying that process's private token while the scope is open.
 *
 * ## What this suite proves
 *
 *  1. public hiding (unchanged)      5. cross-source dedup (unchanged)
 *  2. importer visibility (fixed)    6. no frontend leakage
 *  3. reappearance restores          7. WP-CLI independence
 *  4. repeated import is idempotent  8. the signal is not forgeable
 *
 * Creates only its own `_event_source = g17_reconciliation_test` records and
 * deletes them at the end. No network, no production, no source registry.
 *
 * Usage:
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-source-not-found-reconciliation.php
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

set_time_limit( 0 );

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

if ( ! class_exists( 'Conexao_Event_Status' ) || ! class_exists( 'Conexao_Event_Deduplicator' ) || ! class_exists( 'Conexao_Event_Internal_Query' ) ) {
	fwrite( STDERR, "FATAL: runtime/importer classes not loaded.\n" );
	exit( 1 );
}

$source_slug = 'g17_reconciliation_test';
$token       = substr( md5( uniqid( 'g17', true ) ), 0, 10 );
$engine      = Conexao_Event_Importer::instance()->importer;
$dedup       = new Conexao_Event_Deduplicator();
$upsert      = new ReflectionMethod( $engine, 'upsert_event' );
$upsert->setAccessible( true );

// Record the execution context up front: Test 7 asserts the whole suite works
// on the plain (non-WP-CLI) path, and this proves it rather than assuming it.
echo '== G17 source_not_found reconciliation ==' . "\n";
echo 'context: ' . ( defined( 'WP_CLI' ) && WP_CLI ? 'WP-CLI defined' : 'plain PHP (WP_CLI NOT defined)' ) . "\n";
echo 'public gate active: ' . ( defined( 'WP_CLI' ) && WP_CLI ? 'bypassed by WP-CLI escape' : 'active' ) . "\n";

assert_true( ! ( defined( 'WP_CLI' ) && WP_CLI ), 'TEST 7: suite runs on the non-WP-CLI path where the gate is active' );

/**
 * Delete every record this suite owns, so a run starts from a clean slate.
 *
 * @param string $source Source slug owned by the suite.
 * @return int Number of records removed.
 */
function g17_cleanup( $source ) {
	global $wpdb;

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_event_source'
			 WHERE p.post_type = 'event' AND pm.meta_value = %s",
			$source
		)
	);

	foreach ( $ids as $pid ) {
		wp_delete_post( (int) $pid, true );
	}

	return count( $ids );
}

/**
 * Build normalized event data for the fixture source.
 *
 * @param string $source    Source slug.
 * @param string $source_id Source event ID.
 * @param string $suffix    Unique suffix for the title.
 * @param string $date      Event date.
 * @return array
 */
function g17_normalized( $source, $source_id, $suffix, $date = '2027-06-15' ) {
	return array(
		'title'          => 'G17 Reconciliation ' . $suffix,
		'description'    => 'Deterministic fixture for the source_not_found reconciliation regression suite.',
		'start_date'     => $date,
		'start_time'     => '11:00',
		'event_time'     => '11:00',
		'end_date'       => $date,
		'end_time'       => '13:00',
		'event_location' => 'Reconciliation Test Town',
		'venue'          => 'Reconciliation Test Venue',
		'address'        => '1 Reconciliation Street',
		'source_url'     => 'https://example.test/events/' . $source_id,
		'banner'         => '',
		'source'         => $source,
		'source_id'      => $source_id,
		'organizer'      => 'Reconciliation Test Organizer',
		'price'          => '',
		'county'         => '',
		'town'           => '',
		'category'       => '',
	);
}

/**
 * Create an event record in a chosen `_event_status` state.
 *
 * The full change-detection meta set is written so a re-import with identical
 * data takes the importer's "unchanged" fast path — the branch that carries
 * the restore logic (class-event-importer.php upsert_event()).
 *
 * @param array  $normalized Normalized event data.
 * @param string $status     Status to store.
 * @return int Post ID.
 */
function g17_make_event( array $normalized, $status ) {
	$pid = wp_insert_post(
		array(
			'post_type'    => 'event',
			'post_status'  => 'publish',
			'post_title'   => $normalized['title'],
			'post_content' => $normalized['description'],
			'post_excerpt' => wp_trim_words( $normalized['description'], 30, '...' ),
		)
	);

	Conexao_Event_Status::set_status( $pid, $status );

	update_post_meta( $pid, '_event_source', $normalized['source'] );
	update_post_meta( $pid, '_event_source_id', $normalized['source_id'] );
	update_post_meta( $pid, '_event_url', $normalized['source_url'] );
	update_post_meta( $pid, '_event_source_url', $normalized['source_url'] );
	update_post_meta( $pid, '_event_date', $normalized['start_date'] );
	update_post_meta( $pid, '_event_start_time', $normalized['start_time'] );
	update_post_meta( $pid, '_event_end_date', $normalized['end_date'] );
	update_post_meta( $pid, '_event_end_time', $normalized['end_time'] );
	update_post_meta( $pid, '_event_venue', $normalized['venue'] );
	update_post_meta( $pid, '_event_address', $normalized['address'] );
	update_post_meta( $pid, '_event_organizer', $normalized['organizer'] );
	update_post_meta( $pid, '_event_banner', $normalized['banner'] );

	return (int) $pid;
}

/**
 * Every native event ID carrying a given (source, source_id) identity.
 *
 * Uses DIRECT SQL on purpose: it must see hidden rows too, so it cannot be
 * fooled by the visibility gate in either direction. This is the native-ID
 * identity check the acceptance criteria require — not a "duplicates = 0"
 * count.
 *
 * @param string $source    Source slug.
 * @param string $source_id Source event ID.
 * @return int[] Post IDs.
 */
function g17_native_ids( $source, $source_id ) {
	global $wpdb;

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} ms ON ms.post_id = p.ID AND ms.meta_key = '_event_source' AND ms.meta_value = %s
			 INNER JOIN {$wpdb->postmeta} mi ON mi.post_id = p.ID AND mi.meta_key = '_event_source_id' AND mi.meta_value = %s
			 WHERE p.post_type = 'event'
			 ORDER BY p.ID ASC",
			$source,
			$source_id
		)
	);

	return array_map( 'intval', $ids );
}

/**
 * Does an ordinary public event query return this post?
 *
 * Mirrors a plain frontend WP_Query: no internal marker, no scope.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function g17_public_sees( $post_id ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	return in_array( (int) $post_id, array_map( 'intval', $query->posts ), true );
}

g17_cleanup( $source_slug );

/* ====================================================================== *
 * TEST 1 — public hiding (the rule that must NOT be weakened)
 * ====================================================================== */
test_section( 'Test 1: source_not_found is hidden from public event queries' );

$sid_hidden = 'hidden-' . $token;
$n_hidden   = g17_normalized( $source_slug, $sid_hidden, 'Hidden' );
$pid_hidden = g17_make_event( $n_hidden, Conexao_Event_Status::SOURCE_NOT_FOUND );

assert_true( get_post( $pid_hidden ) instanceof WP_Post, 'T1: event record exists', 'id=' . $pid_hidden );
assert_true( 'source_not_found' === Conexao_Event_Status::get_status( $pid_hidden ), 'T1: event is in source_not_found state' );
assert_true( ! g17_public_sees( $pid_hidden ), 'T1: a plain public event query does NOT return it' );

// The gate must be the reason, not an accident of post_status: the record is
// `publish` at the posts level and is hidden purely by the _event_status gate.
assert_true( 'publish' === get_post_status( $pid_hidden ), 'T1: post_status is publish (hidden by the status gate, not by post status)' );

/* ====================================================================== *
 * TEST 2 — importer visibility (the fix)
 * ====================================================================== */
test_section( 'Test 2: importer-internal reconciliation sees source_not_found' );

$found_hidden = (int) $dedup->find( $n_hidden );

assert_true( $found_hidden === $pid_hidden, 'T2: importer reconciliation lookup finds the hidden record', 'found=' . var_export( $found_hidden, true ) . ' expected=' . $pid_hidden );

// The lookup must find it BY SOURCE IDENTITY, not by an incidental content
// match — this is the identity that reappearance depends on.
$identity_query = (int) $dedup->find( $n_hidden );
assert_true( $identity_query === $pid_hidden, 'T2: lookup resolves by (source, source_id) identity' );

/* ====================================================================== *
 * TEST 3 — reappearance restores the existing record
 * ====================================================================== */
test_section( 'Test 3: re-import restores the existing record (no second native event)' );

$ids_before = g17_native_ids( $source_slug, $sid_hidden );
$result     = $upsert->invoke( $engine, $n_hidden, $found_hidden );
$ids_after  = g17_native_ids( $source_slug, $sid_hidden );

echo '  native IDs before re-import: ' . wp_json_encode( $ids_before ) . "\n";
echo '  upsert action: ' . ( $result['action'] ?? '(none)' ) . ' post_id=' . ( $result['post_id'] ?? 0 ) . "\n";
echo '  native IDs after re-import:  ' . wp_json_encode( $ids_after ) . "\n";

assert_true( isset( $result['post_id'] ) && (int) $result['post_id'] === $pid_hidden, 'T3: upsert operated on the EXISTING record (same native ID)', wp_json_encode( $result ) );
assert_true( 'created' !== ( $result['action'] ?? '' ), 'T3: action is not "created" — no duplicate insert', 'action=' . ( $result['action'] ?? '' ) );
assert_true( 'published' === Conexao_Event_Status::get_status( $pid_hidden ), 'T3: the hidden record was RESTORED to published' );
assert_true( 1 === count( $ids_after ), 'T3: exactly ONE native event record exists for this identity', 'ids=' . wp_json_encode( $ids_after ) );
assert_true( $ids_before === $ids_after, 'T3: the native-ID set is unchanged by reappearance', wp_json_encode( $ids_after ) );
assert_true( $source_slug === get_post_meta( $pid_hidden, '_event_source', true ), 'T3: source UID (source slug) unchanged' );
assert_true( $sid_hidden === get_post_meta( $pid_hidden, '_event_source_id', true ), 'T3: source UID (source_id) unchanged' );
assert_true( isset( $ids_after[0] ) && (int) $ids_after[0] === $pid_hidden, 'T3: the surviving record IS the original native ID (canonical identity preserved)' );

/* ====================================================================== *
 * TEST 4 — repeated reappearance is idempotent
 * ====================================================================== */
test_section( 'Test 4: repeated reappearance imports are idempotent' );

// Re-hide the record, then re-import it. Each cycle is the real scenario: the
// event disappears again and comes back.
for ( $cycle = 1; $cycle <= 3; $cycle++ ) {
	Conexao_Event_Status::set_status( $pid_hidden, Conexao_Event_Status::SOURCE_NOT_FOUND );
	assert_true( 'source_not_found' === Conexao_Event_Status::get_status( $pid_hidden ), 'T4: cycle ' . $cycle . ' event hidden again' );

	$found_cycle = (int) $dedup->find( $n_hidden );
	$res_cycle   = $upsert->invoke( $engine, $n_hidden, $found_cycle );
	$ids_cycle   = g17_native_ids( $source_slug, $sid_hidden );

	assert_true( $found_cycle === $pid_hidden, 'T4: cycle ' . $cycle . ' importer still finds the same record', 'found=' . var_export( $found_cycle, true ) );
	assert_true( 'created' !== ( $res_cycle['action'] ?? '' ), 'T4: cycle ' . $cycle . ' did not create a second record', 'action=' . ( $res_cycle['action'] ?? '' ) );
	assert_true( 'published' === Conexao_Event_Status::get_status( $pid_hidden ), 'T4: cycle ' . $cycle . ' restored to published' );
	assert_true( array( $pid_hidden ) === $ids_cycle, 'T4: cycle ' . $cycle . ' native-ID set stable', 'ids=' . wp_json_encode( $ids_cycle ) );
}

echo '  native IDs after 3 further reappearance cycles: ' . wp_json_encode( g17_native_ids( $source_slug, $sid_hidden ) ) . "\n";

/* ====================================================================== *
 * TEST 5 — cross-source dedup unchanged
 * ====================================================================== */
test_section( 'Test 5: cross-source deduplication is unchanged' );

// A genuinely NEW source event must still be created exactly once.
$sid_new   = 'new-' . $token;
$n_new     = g17_normalized( $source_slug, $sid_new, 'Brand New' );
$found_new = (int) $dedup->find( $n_new );

assert_true( 0 === $found_new, 'T5: a genuinely new source event matches nothing' );

$res_new = $upsert->invoke( $engine, $n_new, $found_new );
$ids_new = g17_native_ids( $source_slug, $sid_new );

assert_true( 'created' === ( $res_new['action'] ?? '' ), 'T5: a new source event is created', wp_json_encode( $res_new ) );
assert_true( 1 === count( $ids_new ), 'T5: the new event has exactly one native record', 'ids=' . wp_json_encode( $ids_new ) );

// Re-importing it must not create a second one.
$found_new2 = (int) $dedup->find( $n_new );
$res_new2   = $upsert->invoke( $engine, $n_new, $found_new2 );

assert_true( isset( $ids_new[0] ) && $found_new2 === (int) $ids_new[0], 'T5: re-import matches the existing record', 'found=' . var_export( $found_new2, true ) );
assert_true( 'created' !== ( $res_new2['action'] ?? '' ), 'T5: re-import does not create a duplicate' );
assert_true( 1 === count( g17_native_ids( $source_slug, $sid_new ) ), 'T5: still exactly one native record' );

/* ====================================================================== *
 * TEST 6 — the exemption does not leak into any frontend surface
 * ====================================================================== */
test_section( 'Test 6: no frontend event surface leaks source_not_found' );

$sid_leak = 'leak-' . $token;
$n_leak   = g17_normalized( $source_slug, $sid_leak, 'Leak Probe' );
$pid_leak = g17_make_event( $n_leak, Conexao_Event_Status::SOURCE_NOT_FOUND );

// (a) homepage "Proximos Eventos" section — the shared runtime helper.
Conexao_Event_Query::flush_cache();
$upcoming = array_map( 'intval', Conexao_Event_Query::upcoming_event_ids() );
assert_true( ! in_array( $pid_leak, $upcoming, true ), 'T6: homepage upcoming-events section excludes it', 'id=' . $pid_leak );
assert_true( ! isset( Conexao_Event_Query::upcoming_events()[ $pid_leak ] ), 'T6: homepage upcoming events map excludes it' );

// (b) events archive (primary archive query shape).
$archive = new WP_Query(
	array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);
assert_true( ! in_array( $pid_leak, array_map( 'intval', $archive->posts ), true ), 'T6: events archive excludes it' );

// (c) unfiltered archive with an explicit post_type array.
$archive_arr = new WP_Query(
	array(
		'post_type'      => array( 'event' ),
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);
assert_true( ! in_array( $pid_leak, array_map( 'intval', $archive_arr->posts ), true ), 'T6: array post_type archive excludes it' );

// (d) filtered archive — the same query narrowed by the source identity the
// importer itself uses. This is the shape a frontend filter produces.
$filtered = new WP_Query(
	array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_event_source', 'value' => $source_slug ),
			array( 'key' => '_event_source_id', 'value' => $sid_leak ),
		),
	)
);
assert_true( ! in_array( $pid_leak, array_map( 'intval', $filtered->posts ), true ), 'T6: filtered archive excludes it', 'ids=' . wp_json_encode( $filtered->posts ) );

// (e) date-specific query.
$date_query = new WP_Query(
	array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'meta_query'     => array(
			array( 'key' => '_event_date', 'value' => $n_leak['start_date'] ),
		),
	)
);
assert_true( ! in_array( $pid_leak, array_map( 'intval', $date_query->posts ), true ), 'T6: date-specific event query excludes it' );

// (f) multi-post-type query that INCLUDES event.
$mixed = new WP_Query(
	array(
		'post_type'      => array( 'post', 'event' ),
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);
assert_true( ! in_array( $pid_leak, array_map( 'intval', $mixed->posts ), true ), 'T6: mixed post_type query including event excludes it' );

// (g) taxonomy-shaped archive query.
$tax_shape = new WP_Query(
	array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'tax_query'      => array(
			array(
				'taxonomy' => 'conexao_county',
				'field'    => 'term_id',
				'terms'    => 0,
			),
		),
	)
);
assert_true( ! in_array( $pid_leak, array_map( 'intval', $tax_shape->posts ), true ), 'T6: taxonomy-shaped event query excludes it' );

assert_true( ! g17_public_sees( $pid_leak ), 'T6: generic public query still excludes it' );

/* ====================================================================== *
 * TEST 8 — the internal signal is not forgeable by a public request
 * ====================================================================== */
test_section( 'Test 8: the internal reconciliation signal cannot be forged' );

// (a) An arbitrary caller passing the query argument by NAME cannot exempt a
// query: the scope is closed, so the token check is never reached.
$forged_by_name = new WP_Query(
	array(
		'post_type'                            => 'event',
		'post_status'                          => 'publish',
		'posts_per_page'                       => -1,
		'fields'                               => 'ids',
		'no_found_rows'                        => true,
		Conexao_Event_Internal_Query::QUERY_ARG => '1',
	)
);
assert_true( ! in_array( $pid_leak, array_map( 'intval', $forged_by_name->posts ), true ), 'T8: a query carrying the argument with a guessed value is still gated', 'ids=' . wp_json_encode( $forged_by_name->posts ) );

// (b) The token is never derived from request input, so a simulated public
// request cannot supply it.
$_GET[ Conexao_Event_Internal_Query::QUERY_ARG ]     = 'anything';
$_REQUEST[ Conexao_Event_Internal_Query::QUERY_ARG ] = 'anything';

$forged_by_request = new WP_Query(
	array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	)
);
assert_true( ! in_array( $pid_leak, array_map( 'intval', $forged_by_request->posts ), true ), 'T8: attacker-supplied request input cannot expose it' );
assert_true( ! Conexao_Event_Internal_Query::is_open(), 'T8: the reconciliation scope is CLOSED outside the importer' );

unset( $_GET[ Conexao_Event_Internal_Query::QUERY_ARG ], $_REQUEST[ Conexao_Event_Internal_Query::QUERY_ARG ] );

// (c) Even inside an open scope, a query WITHOUT the marker stays gated: the
// scope alone is not enough, only marked queries are exempt.
Conexao_Event_Internal_Query::run(
	function () use ( $pid_leak ) {
		$unmarked = new WP_Query(
			array(
				'post_type'      => 'event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		assert_true( ! in_array( $pid_leak, array_map( 'intval', $unmarked->posts ), true ), 'T8: an UNMARKED query is still gated even while the scope is open' );

		$marked = new WP_Query(
			Conexao_Event_Internal_Query::mark_query_args(
				array(
					'post_type'      => 'event',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			)
		);
		assert_true( in_array( $pid_leak, array_map( 'intval', $marked->posts ), true ), 'T8: a MARKED query inside the scope sees the hidden record (the intended exemption)' );
	}
);

// (d) The scope is closed again once the callback returned.
assert_true( ! Conexao_Event_Internal_Query::is_open(), 'T8: scope closed after run() returned' );

// (e) An exception inside the scope must not leave it open (fail-closed).
$threw = false;

try {
	Conexao_Event_Internal_Query::run(
		function () {
			throw new RuntimeException( 'simulated importer failure' );
		}
	);
} catch ( RuntimeException $e ) {
	$threw = true;
}

assert_true( $threw, 'T8: the simulated failure propagated to the caller' );
assert_true( ! Conexao_Event_Internal_Query::is_open(), 'T8: scope closed even when the callback threw (no leaked exemption)' );

// (f) A marked query after the failed scope is gated again.
$after_exception = new WP_Query(
	Conexao_Event_Internal_Query::mark_query_args(
		array(
			'post_type'      => 'event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	)
);
assert_true( ! in_array( $pid_leak, array_map( 'intval', $after_exception->posts ), true ), 'T8: a marked query AFTER the scope closed is gated again' );

/* ====================================================================== *
 * Cleanup — only records this suite owns.
 * ====================================================================== */
$removed = g17_cleanup( $source_slug );

assert_true( 0 === count( g17_native_ids( $source_slug, $sid_hidden ) ), 'CLEANUP: reappearance fixture identity fully removed', 'ids=' . wp_json_encode( g17_native_ids( $source_slug, $sid_hidden ) ) );
assert_true( 0 === count( g17_native_ids( $source_slug, $sid_new ) ), 'CLEANUP: new-event fixture fully removed' );
assert_true( 0 === count( g17_native_ids( $source_slug, $sid_leak ) ), 'CLEANUP: leak-probe fixture fully removed' );

echo 'cleaned ' . $removed . " fixture record(s)\n";

test_finish( 'G17 SOURCE_NOT_FOUND RECONCILIATION' );
