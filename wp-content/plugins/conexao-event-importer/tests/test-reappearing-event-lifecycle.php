<?php
/**
 * Regression test -- reappearing-event lifecycle (Events Expansion C2 blocker).
 * Proves BOTH sides of the fix.
 */
// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

set_time_limit( 0 );

// WP-CLI already bootstraps WordPress; only a plain PHP invocation needs
// wp-load.php (and re-including it inside a wp-cli eval would re-define
// constants). `wp eval-file` additionally passes positional args in $args
// instead of $argv, so read both.
if ( ! defined( 'WPINC' ) ) {
}
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

if ( ! class_exists( 'Conexao_Event_Status' ) || ! class_exists( 'Conexao_Event_Deduplicator' ) ) {
	fwrite( STDERR, "FATAL: runtime/importer classes not loaded.\n" );
	exit( 1 );
}

$is_cli_context = defined( 'WP_CLI' ) && WP_CLI;
$explicit       = '';
if ( isset( $args ) && is_array( $args ) ) {
	$explicit = implode( ' ', $args );
} elseif ( isset( $argv ) && is_array( $argv ) ) {
	$explicit = implode( ' ', array_slice( $argv, 1 ) );
}
$cli_mode    = $is_cli_context || false !== strpos( $explicit, 'cli' );
$public_mode = ! $cli_mode;

if ( false !== strpos( $explicit, 'cleanup-only' ) ) {
	echo 'cleaned=' . lc_cleanup( 'c2_lifecycle_test' ) . "\n";
	exit( 0 );
}

$source_slug = 'c2_lifecycle_test';
$run_token   = substr( md5( uniqid( 'c2-lifecycle', true ) ), 0, 10 );
$fail        = 0;

function lc_cleanup( $source_slug ) {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT p.ID FROM {$wpdb->posts} p
		 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_event_source'
		 WHERE p.post_type = 'event' AND pm.meta_value = %s",
		$source_slug
	) );
	$n = 0;
	foreach ( $ids as $pid ) { wp_delete_post( (int) $pid, true ); $n++; }
	return $n;
}

function lc_count_identity( $source, $source_id ) {
	$q = new WP_Query( array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_event_source', 'value' => $source ),
			array( 'key' => '_event_source_id', 'value' => $source_id ),
		),
	) );
	return $q->found_posts ? count( $q->posts ) : 0;
}

function lc_normalized( $source, $source_id, $url, $title, $date, $time ) {
	return array(
		'title'          => $title,
		'description'    => 'Description for the reappearing-event lifecycle regression test.',
		'start_date'     => $date,
		'start_time'     => $time,
		'event_time'     => $time,
		'end_date'       => $date,
		'end_time'       => '12:00',
		'event_location' => 'Lifecycle Test Town',
		'venue'          => 'Lifecycle Test Venue',
		'address'        => '1 Test Street, Lifecycle Test Town',
		'source_url'     => $url,
		'banner'         => '',
		'source'         => $source,
		'source_id'      => $source_id,
		'organizer'      => 'Lifecycle Test Organizer',
		'price'          => '',
		'county'         => '',
		'town'           => '',
		'category'       => '',
	);
}

function lc_make_snf( $normalized ) {
	$pid = wp_insert_post( array(
		'post_type'    => 'event',
		'post_status'  => 'publish',
		'post_title'   => $normalized['title'],
		'post_content' => $normalized['description'],
		'post_excerpt' => 'Excerpt ' . $normalized['title'],
	) );
	// Save the full meta set so a re-import with identical data takes the
	// importer's "unchanged" fast path (the path that must ALSO restore the
	// source_not_found status — see class-event-importer upsert_event()).
	Conexao_Event_Status::set_status( $pid, Conexao_Event_Status::SOURCE_NOT_FOUND );
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
	return $pid;
}

// Start from a clean slate: remove any leftovers from interrupted runs.
lc_cleanup( $source_slug );

echo '=== REAPPEARING-EVENT LIFECYCLE (context: ' . ( $cli_mode ? 'CLI / internal importer' : 'public frontend' ) . ') ===' . "\n";

$engine = Conexao_Event_Importer::instance()->importer;
$dedup  = new Conexao_Event_Deduplicator();
$upsert = new ReflectionMethod( $engine, 'upsert_event' );
$upsert->setAccessible( true );

/* ------------------------------------------------------------------ *
 * PUBLIC-FRONTEND PHASE (plain PHP invocation — gate active)
 * ------------------------------------------------------------------ */
if ( $public_mode ) {
	$sid        = 'public-hide-' . $run_token;
	$url        = 'https://example.test/events/' . $sid;
	$normalized = lc_normalized( $source_slug, $sid, $url, 'C2 Public-Hide Test Event', '2027-01-15', '10:00' );
	$pid        = lc_make_snf( $normalized );

	assert_true( get_post( $pid ) instanceof WP_Post, 'test event created and marked source_not_found', 'id=' . $pid );

	$q = new WP_Query( array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			'relation' => 'AND',
			array( 'key' => '_event_source', 'value' => $source_slug ),
			array( 'key' => '_event_source_id', 'value' => $sid ),
		),
	) );
	assert_true( ! in_array( (int) $pid, array_map( 'intval', $q->posts ), true ), 'public WP_Query hides source_not_found event', 'ids=' . wp_json_encode( $q->posts ) );

	assert_true( 0 === $dedup->find( $normalized ), 'public deduplicator does not match source_not_found event');

	$all = new WP_Query( array( 'post_type' => 'event', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
	assert_true( ! in_array( (int) $pid, array_map( 'intval', $all->posts ), true ), 'public archive query excludes source_not_found event');

	assert_true( get_post( $pid ) && 'source_not_found' === Conexao_Event_Status::get_status( $pid ), 'event still exists in DB (hidden by gate, not deleted)');

	lc_cleanup( $source_slug );
}

/* ------------------------------------------------------------ *
 * INTERNAL IMPORTER / CLI PHASE (WP-CLI or --mode cli)
 * ------------------------------------------------------------ */
if ( $cli_mode ) {
	$run = $run_token;

	// ---- Scenario A: reappearing event with UNCHANGED data ----
	$sidA = 'reappear-unchanged-' . $run;
	$urlA = 'https://example.test/events/' . $sidA;
	$nA   = lc_normalized( $source_slug, $sidA, $urlA, 'C2 Reappearing Unchanged Test', '2027-01-15', '10:00' );
	$pidA = lc_make_snf( $nA );

	assert_true( get_post( $pidA ) && 'source_not_found' === Conexao_Event_Status::get_status( $pidA ), 'CLI: SNF event exists (unchanged scenario)', 'id=' . $pidA );

	$foundA = (int) $dedup->find( $nA );
	assert_true( $foundA === (int) $pidA, 'CLI: deduplicator finds source_not_found event', 'found=' . var_export( $foundA, true ) );

	$resA = $upsert->invoke( $engine, $nA, $foundA );
	assert_true( isset( $resA['post_id'] ) && (int) $resA['post_id'] === (int) $pidA, 'CLI: upsert matched the existing event (no creation)', wp_json_encode( $resA ) );
	assert_true( 1 === lc_count_identity( $source_slug, $sidA ), 'CLI: unchanged scenario — no duplicate (identity count = 1)');
	assert_true( 'published' === Conexao_Event_Status::get_status( $pidA ), 'CLI: unchanged event restored to published');
	assert_true( $source_slug === get_post_meta( $pidA, '_event_source', true ) && $sidA === get_post_meta( $pidA, '_event_source_id', true ), 'CLI: source identity unchanged');

	// ---- Scenario B: reappearing event with CHANGED data (title) ----
	$sidB = 'reappear-changed-' . $run;
	$urlB = 'https://example.test/events/' . $sidB;
	$nB   = lc_normalized( $source_slug, $sidB, $urlB, 'C2 Reappearing Changed Test', '2027-02-20', '14:00' );
	$pidB = lc_make_snf( $nB );

	$foundB = (int) $dedup->find( $nB );
	assert_true( $foundB === (int) $pidB, 'CLI: deduplicator finds second source_not_found event', 'found=' . var_export( $foundB, true ) );

	$nB2 = $nB; $nB2['title'] = 'C2 Reappearing Changed Test (updated title)';
	$resB = $upsert->invoke( $engine, $nB2, $foundB );
	assert_true( isset( $resB['post_id'] ) && (int) $resB['post_id'] === (int) $pidB && 'updated' === $resB['action'], 'CLI: changed event updated (not created)', wp_json_encode( $resB ) );
	assert_true( 1 === lc_count_identity( $source_slug, $sidB ), 'CLI: changed scenario — no duplicate (identity count = 1)');
	assert_true( 'published' === Conexao_Event_Status::get_status( $pidB ), 'CLI: changed event restored to published');
	assert_true( 'C2 Reappearing Changed Test (updated title)' === get_post_field( 'post_title', $pidB ), 'CLI: updated title persisted');

// ---- Scenario C: full lifecycle A->B->D->E->F->G->H (task section 10) ----
	// Step C ("public query hides it") is covered by the PUBLIC phase of this
	// same script (run as plain PHP). Under WP-CLI the gate is intentionally
	// bypassed for the importer, so only the internal-side steps run here.
	$sidC = 'lifecycle-' . $run;
	$urlC = 'https://example.test/events/' . $sidC;
	$nC   = lc_normalized( $source_slug, $sidC, $urlC, 'C2 Full Lifecycle Test', '2027-03-10', '09:00' );
	$pidC = wp_insert_post( array( 'post_type' => 'event', 'post_status' => 'publish', 'post_title' => $nC['title'], 'post_content' => $nC['description'] ) );
	update_post_meta( $pidC, '_event_source', $nC['source'] );
	update_post_meta( $pidC, '_event_source_id', $nC['source_id'] );
	update_post_meta( $pidC, '_event_url', $nC['source_url'] );
	update_post_meta( $pidC, '_event_source_url', $nC['source_url'] );
	Conexao_Event_Status::set_status( $pidC, Conexao_Event_Status::PUBLISHED ); // A: published
	assert_true( 'published' === Conexao_Event_Status::get_status( $pidC ), 'C: (A) event is published');

	Conexao_Event_Status::set_status( $pidC, Conexao_Event_Status::SOURCE_NOT_FOUND ); // B: disappears
	assert_true( 'source_not_found' === Conexao_Event_Status::get_status( $pidC ), 'C: (B) event now source_not_found');

	$foundC = (int) $dedup->find( $nC ); // D: internal importer lookup must still find it
	assert_true( $foundC === (int) $pidC, 'C: (D) internal importer lookup finds source_not_found event', 'found=' . var_export( $foundC, true ) );

	$resC = $upsert->invoke( $engine, $nC, $foundC ); // E: source import runs again with event present
	assert_true( isset( $resC['post_id'] ) && (int) $resC['post_id'] === (int) $pidC && 'published' === Conexao_Event_Status::get_status( $pidC ), 'C: (E/F) existing event matched and restored to published', wp_json_encode( $resC ) );
	assert_true( 'created' !== ( $resC['action'] ?? '' ), 'C: (G) created count = 0 for this event');
	assert_true( 1 === lc_count_identity( $source_slug, $sidC ), 'C: (H) no duplicate exists (identity count = 1)');
	assert_true( $source_slug === get_post_meta( $pidC, '_event_source', true ) && $sidC === get_post_meta( $pidC, '_event_source_id', true ), 'C: source identity unchanged');

	lc_cleanup( $source_slug );
}

echo 'REAPPEARING-EVENT LIFECYCLE: ' . ( 0 === $fail ? 'ALL PASS (0 failures)' : $fail . ' FAILURE(S)' ) . PHP_EOL;

test_finish();
