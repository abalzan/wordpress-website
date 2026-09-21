<?php
/**
 * Stage 2 — Event status gate × language context.
 *
 * HARD GATE. Verifies that `_event_status` keeps controlling public event
 * visibility independently of the language layer, and that a linked English
 * translation is a separate record that can never leak into the Portuguese
 * listing (or vice versa).
 *
 * This script runs as a plain PHP process (NOT wp-cli) on purpose: the runtime
 * gate returns early under WP_CLI, so only a non-CLI process exercises the real
 * front-end gate.
 *
 * Verified:
 *  - a published PT event is visible in the PT context and its EN translation
 *    is visible in the EN context (each language sees exactly its own record);
 *  - expired / rejected / source_not_found events are hidden in BOTH contexts;
 *  - the same rules hold for a plain WP_Query (the public archive path) as for
 *    `Conexao_Event_Query::upcoming_event_ids()` (the homepage/404 helper).
 *
 * Fixtures are prefixed "[STAGE2-GATE]" and deleted at the end.
 *
 * Usage (from the project root):
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-event-language-gate.php
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';

// Deterministic request context for Polylang in a CLI process.
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

$passed  = 0;
$failed  = 0;
$created = array();

function gate_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$message}\n";
	} else {
		$failed++;
		echo "  FAIL: {$message}\n";
	}
}

echo "== Stage 2 — Event status gate × language ==\n";

if ( ! function_exists( 'pll_set_post_language' ) ) {
	echo "  SKIP: Polylang is not active in this environment.\n";
	exit( 0 );
}

if ( ! class_exists( 'Conexao_Event_Query' ) ) {
	echo "  SKIP: the event runtime plugin is not active in this environment.\n";
	exit( 0 );
}

/**
 * Create an event fixture.
 *
 * @param string $title    Title suffix.
 * @param string $date     _event_date value.
 * @param string $status   _event_status value ('' = legacy/no status).
 * @return int Post ID.
 */
function gate_create_event( $title, $date, $status = '' ) {
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'event',
			'post_title'   => '[STAGE2-GATE] ' . $title,
			'post_content' => 'Gate fixture.',
			'post_status'  => 'publish',
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return 0;
	}

	update_post_meta( $post_id, '_event_date', $date );
	update_post_meta( $post_id, '_event_start_time', '20:00' );
	if ( '' !== $status ) {
		update_post_meta( $post_id, '_event_status', $status );
	}

	return (int) $post_id;
}

/**
 * Switch the Polylang request language for the rest of this process.
 *
 * @param string $slug Language slug.
 * @return void
 */
function gate_switch_language( $slug ) {
	PLL()->curlang = PLL()->model->get_language( $slug );
	clean_post_cache( 0 );
}

$date = gmdate( 'Y-m-d', strtotime( '+5 days' ) );

$pt_id = gate_create_event( 'PT event', $date, 'published' );
if ( $pt_id ) {
	$created[] = $pt_id;
}

$en_id = wp_insert_post(
	array(
		'post_type'    => 'event',
		'post_title'   => 'EN translation event',
		'post_content' => 'Gate fixture (EN).',
		'post_status'  => 'publish',
	),
	true
);

if ( ! is_wp_error( $en_id ) ) {
	$created[] = (int) $en_id;
	pll_set_post_language( (int) $en_id, 'en' );
	pll_save_post_translations( array( 'pt' => $pt_id, 'en' => (int) $en_id ) );
	foreach ( array( '_event_date', '_event_start_time', '_event_status' ) as $meta_key ) {
		update_post_meta( $en_id, $meta_key, get_post_meta( $pt_id, $meta_key, true ) );
	}
}

gate_assert( $pt_id > 0 && ! is_wp_error( $en_id ), 'fixtures created (PT master + linked EN translation)' );
gate_assert( 'pt' === pll_get_post_language( $pt_id, 'slug' ), 'PT fixture is in the pt language' );
gate_assert( 'en' === pll_get_post_language( (int) $en_id, 'slug' ), 'EN fixture is in the en language' );

// --- 1. Language separation of the public event list. --------------------
gate_switch_language( 'pt' );
Conexao_Event_Query::flush_cache();
$pt_ids = Conexao_Event_Query::upcoming_event_ids();
gate_assert( in_array( $pt_id, $pt_ids, true ), 'PT context lists the Portuguese event' );
gate_assert( ! in_array( (int) $en_id, $pt_ids, true ), 'PT context does NOT list the English translation' );

gate_switch_language( 'en' );
Conexao_Event_Query::flush_cache();
$en_ids = Conexao_Event_Query::upcoming_event_ids();
gate_assert( in_array( (int) $en_id, $en_ids, true ), 'EN context lists the English translation' );
gate_assert( ! in_array( $pt_id, $en_ids, true ), 'EN context does NOT list the Portuguese event' );

// --- 2. The status gate hides hidden events in BOTH languages. -----------
foreach ( array( 'expired', 'rejected', 'source_not_found' ) as $hidden_status ) {
	foreach ( array( $pt_id, (int) $en_id ) as $record_id ) {
		update_post_meta( $record_id, '_event_status', $hidden_status );
	}

	gate_switch_language( 'pt' );
	Conexao_Event_Query::flush_cache();
	$pt_hidden = Conexao_Event_Query::upcoming_event_ids();

	gate_switch_language( 'en' );
	Conexao_Event_Query::flush_cache();
	$en_hidden = Conexao_Event_Query::upcoming_event_ids();

	gate_assert( ! in_array( $pt_id, $pt_hidden, true ), "status '{$hidden_status}' hides the event in the PT context" );
	gate_assert( ! in_array( (int) $en_id, $en_hidden, true ), "status '{$hidden_status}' hides the event in the EN context" );
}

// --- 3. The gate composes with the real public query (pre_get_posts). ----
foreach ( array( $pt_id, (int) $en_id ) as $record_id ) {
	update_post_meta( $record_id, '_event_status', 'published' );
}

gate_switch_language( 'pt' );
Conexao_Event_Query::flush_cache();

$query = new WP_Query(
	array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'post__in'       => array( $pt_id, (int) $en_id ),
	)
);
gate_assert( in_array( $pt_id, $query->posts, true ), 'public event query returns the published PT event' );
gate_assert( ! in_array( (int) $en_id, $query->posts, true ), 'public event query filters out the EN record in the PT context' );

update_post_meta( $pt_id, '_event_status', 'expired' );
Conexao_Event_Query::flush_cache();

$query_hidden = new WP_Query(
	array(
		'post_type'      => 'event',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'post__in'       => array( $pt_id ),
	)
);
gate_assert( ! in_array( $pt_id, $query_hidden->posts, true ), 'the _event_status gate still filters the public query (expired hidden)' );

// --- Cleanup -------------------------------------------------------------
gate_switch_language( 'pt' );
foreach ( array_unique( $created ) as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}
Conexao_Event_Query::flush_cache();

echo "\nevent status gate: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
