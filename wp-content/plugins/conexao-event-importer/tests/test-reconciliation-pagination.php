<?php
/**
 * Stage 2 / P10 — paginated absence reconciliation, gated on P4.
 *
 * ## The defect this locks down
 *
 * `mark_missing_events()` evaluated at most 300 posts per source. Sources with
 * more events (Dublin, Cork) were therefore only partially reconciled: events
 * beyond the first 300 were never checked for absence.
 *
 * ## The contract this suite proves
 *
 *  1. the two P4 gates are evaluated BEFORE any candidate query — an
 *     incomplete enumeration or a checkpoint-resumed subset performs zero
 *     candidate queries and zero status mutations;
 *  2. candidate enumeration is keyset-paginated on the unique post ID
 *     (`ID > last_id`, `ORDER BY ID ASC`, bounded LIMIT) and visits every
 *     candidate exactly once, even when every other sort value collides;
 *  3. the COMPLETE candidate set is enumerated before the first mutation; a
 *     failed, malformed or interrupted page aborts the pass with zero
 *     absence-based mutations;
 *  4. a complete enumeration reconciles every source-owned event, including
 *     sources with more than 300 events;
 *  5. a re-run in the same state is idempotent (no new writes);
 *  6. a source with fewer than 300 events behaves exactly as before.
 *
 * Every fixture here is synthetic (`_event_source = s2r_*`) and is removed at
 * the end. No live provider is contacted and no shared-database record is
 * touched: the suite runs against the disposable test database.
 *
 * @package Conexao_Event_Importer
 */

// Shared test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_require_operator_store_isolation();

$restore_options = test_options_isolate(
	array(
		Conexao_Import_Continuation::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		test_options_restore( $restore_options, true );
	}
);

/**
 * Minimal handler double with controllable gate values.
 */
class S2R_Handler_Double extends Conexao_Source_Base {

	/** @var bool Completeness gate value. */
	public $complete = true;

	/** @var bool Reconciliation-safety gate value. */
	public $safe = true;

	/**
	 * Constructor with an optional config, so a double needs no fixture config.
	 *
	 * @param array $config Source config.
	 */
	public function __construct( $config = array() ) {
		parent::__construct( $config );
	}

	/**
	 * Source slug.
	 *
	 * @return string
	 */
	public function get_id() {
		return 's2r_double';
	}

	/**
	 * No fetching: this double only carries gate state.
	 *
	 * @return array[]
	 */
	public function fetch_events() {
		return array();
	}

	/**
	 * Completeness gate.
	 *
	 * @return bool
	 */
	public function is_fetch_complete() {
		return (bool) $this->complete;
	}

	/**
	 * Reconciliation-safety gate.
	 *
	 * @return bool
	 */
	public function is_reconciliation_safe() {
		return (bool) $this->safe;
	}
}

$GLOBALS['s2r_query_count']   = 0;
$GLOBALS['s2r_fail_on']       = 0;
$GLOBALS['s2r_malformed_on']  = 0;
$GLOBALS['s2r_throw_on']      = 0;
$GLOBALS['s2r_spy_enabled']   = false;

add_filter( 'query', 's2r_query_spy', 10, 1 );

/**
 * Spy/fault-injection filter over the reconciliation candidate queries.
 *
 * The candidate query is identified by its distinctive shape: it selects
 * `p.ID` from the posts table with an `_event_source` join and an `ID >`
 * keyset predicate.
 *
 * @param string $query SQL about to run.
 * @return string
 */
function s2r_query_spy( $query ) {
	if ( false === strpos( (string) $query, '_event_source' ) || false === strpos( (string) $query, 'p.ID >' ) ) {
		return $query;
	}

	++$GLOBALS['s2r_query_count'];

	$GLOBALS['s2r_queries'][] = (string) $query;

	if ( ! empty( $GLOBALS['s2r_spy_enabled'] ) ) {
		global $wpdb;

		if ( (int) $GLOBALS['s2r_fail_on'] === (int) $GLOBALS['s2r_query_count'] ) {
			return 'SELECT p.ID FROM ' . $wpdb->posts . '_s2r_missing_table WHERE 1 = 1';
		}

		if ( (int) $GLOBALS['s2r_malformed_on'] === (int) $GLOBALS['s2r_query_count'] ) {
			return 'SELECT 0 AS ID FROM ' . $wpdb->posts . ' LIMIT 1';
		}

		if ( (int) $GLOBALS['s2r_throw_on'] === (int) $GLOBALS['s2r_query_count'] ) {
			throw new RuntimeException( 'simulated interruption during candidate enumeration' );
		}
	}

	return $query;
}

/**
 * Reset the spy state.
 *
 * @param int $fail_on      Page number (1-based) that must fail.
 * @param int $malformed_on Page number that must be malformed.
 * @param int $throw_on     Page number that must throw.
 * @param bool $enabled     Whether fault injection is active.
 * @return void
 */
function s2r_spy_reset( $fail_on = 0, $malformed_on = 0, $throw_on = 0, $enabled = false ) {
	$GLOBALS['s2r_query_count']  = 0;
	$GLOBALS['s2r_queries']      = array();
	$GLOBALS['s2r_fail_on']      = $fail_on;
	$GLOBALS['s2r_malformed_on'] = $malformed_on;
	$GLOBALS['s2r_throw_on']     = $throw_on;
	$GLOBALS['s2r_spy_enabled']  = $enabled;
}

test_title( 'Stage 2 — paginated absence reconciliation (P10)' );

// ===========================================================================
// Fixtures
// ===========================================================================

$plugin           = Conexao_Event_Importer::instance();
$s2r_big_source   = 's2r_paging';
$s2r_small_source = 's2r_small';
$s2r_big_count    = 401; // More than the historical 300-post ceiling.
$s2r_small_count  = 5;

/**
 * Create one synthetic event owned by a probe source.
 *
 * Every event in a cohort deliberately shares the same `_event_url` and
 * `_event_date`, so the only stable, unique ordering key is the post ID.
 *
 * @param string $source Source slug.
 * @param int    $index  Index within the cohort.
 * @param string $url    Shared URL value.
 * @return int Post ID.
 */
function s2r_create_event( $source, $index, $url ) {
	global $wpdb;

	/*
	 * Fixtures are inserted directly: this suite needs 401+ synthetic rows, and
	 * a full wp_insert_post() (term assignment, Polylang, deferred term counts)
	 * would dominate the suite's runtime. The rows are ordinary `event` posts
	 * with the same meta an import would write, so every code path under test
	 * reads them exactly like imported records.
	 */
	$now = current_time( 'mysql' );

	$wpdb->insert(
		$wpdb->posts,
		array(
			'post_type'             => 'event',
			'post_status'           => 'publish',
			'post_title'            => 'S2R ' . $source . ' event ' . $index,
			'post_name'             => 's2r-' . $source . '-' . $index,
			'post_date'             => $now,
			'post_date_gmt'         => get_gmt_from_date( $now ),
			'post_modified'         => $now,
			'post_modified_gmt'     => get_gmt_from_date( $now ),
			'post_content'          => '',
			'post_excerpt'          => '',
			'to_ping'               => '',
			'pinged'                => '',
			'post_content_filtered' => '',
			'guid'                  => '',
			'post_parent'           => 0,
			'menu_order'            => 0,
			'post_mime_type'        => '',
			'comment_status'        => 'closed',
			'ping_status'           => 'closed',
		)
	);

	$post_id = (int) $wpdb->insert_id;

	$meta = array(
		'_event_source'    => $source,
		'_event_source_id' => 's2r-' . $source . '-' . $index,
		'_event_url'       => $url,
		'_event_date'      => '2026-12-01',
		'_event_venue'     => 'S2R Shared Venue',
		'_event_status'    => Conexao_Event_Status::PUBLISHED,
	);

	foreach ( $meta as $meta_key => $meta_value ) {
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => $meta_key,
				'meta_value' => $meta_value,
			)
		);
	}

	return $post_id;
}

/**
 * All event IDs for a probe source, ascending.
 *
 * @param string $source Source slug.
 * @return int[]
 */
function s2r_ids( $source ) {
	global $wpdb;

	/*
	 * Read the cohort through raw SQL, NOT WP_Query: the Event Runtime's
	 * public gate hides non-published `_event_status` records from ordinary
	 * queries, so a fixture helper built on get_posts() would silently stop
	 * seeing events the moment reconciliation marked them — and every
	 * assertion after that would measure nothing.
	 */
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} src
			         ON src.post_id = p.ID AND src.meta_key = '_event_source' AND src.meta_value = %s
			 WHERE p.post_type = 'event'
			 ORDER BY p.ID ASC",
			$source
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Count events of a probe source currently carrying a given `_event_status`.
 *
 * @param string $source Source slug.
 * @param string $status Status value.
 * @return int
 */
function s2r_count_status( $source, $status ) {
	global $wpdb;

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} src
			         ON src.post_id = p.ID AND src.meta_key = '_event_source' AND src.meta_value = %s
			 INNER JOIN {$wpdb->postmeta} st
			         ON st.post_id = p.ID AND st.meta_key = '_event_status'
			 WHERE p.post_type = 'event' AND st.meta_value = %s",
			$source,
			$status
		)
	);
}

/**
 * Reset every event of a probe source to published.
 *
 * @param string $source Source slug.
 * @return void
 */
function s2r_reset_statuses( $source ) {
	foreach ( s2r_ids( $source ) as $id ) {
		Conexao_Event_Status::set_status( $id, Conexao_Event_Status::PUBLISHED );
	}
}

/**
 * Delete every event of a probe source.
 *
 * @param string $source Source slug.
 * @return void
 */
function s2r_cleanup( $source ) {
	global $wpdb;

	$ids = s2r_ids( $source );

	if ( empty( $ids ) ) {
		return;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$placeholders})", $ids ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$placeholders})", $ids ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$placeholders})", $ids ) );

	wp_cache_flush();
}

/**
 * Invoke the protected reconciliation entry point.
 *
 * @param string      $source  Source slug.
 * @param array       $raw     Raw events for the run.
 * @param object|null $handler Handler double.
 * @return int Marked count.
 */
function s2r_mark( $source, array $raw, $handler = null ) {
	static $method = null;

	if ( null === $method ) {
		$method = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'mark_missing_events' );
		$method->setAccessible( true );
	}

	return (int) $method->invoke( Conexao_Event_Importer::instance()->importer, $source, $raw, $handler );
}

/**
 * Invoke the protected candidate enumeration.
 *
 * @param string $source Source slug.
 * @return int[]|null
 */
function s2r_enumerate( $source ) {
	static $method = null;

	if ( null === $method ) {
		$method = new ReflectionMethod( 'Conexao_Event_Importer_Engine', 'enumerate_source_owned_event_ids' );
		$method->setAccessible( true );
	}

	return $method->invoke( Conexao_Event_Importer::instance()->importer, $source );
}

// Create the cohorts once and reset their state between cases.
s2r_cleanup( $s2r_big_source );
s2r_cleanup( $s2r_small_source );

$s2r_big_first = 0;
$s2r_big_last  = 0;

for ( $i = 1; $i <= $s2r_big_count; $i++ ) {
	$id = s2r_create_event( $s2r_big_source, $i, 'https://s2r.test/e/shared-absent-url' );

	if ( 0 === $s2r_big_first ) {
		$s2r_big_first = $id;
	}

	$s2r_big_last = $id;
}

for ( $i = 1; $i <= $s2r_small_count; $i++ ) {
	s2r_create_event( $s2r_small_source, $i, 'https://s2r.test/e/shared-absent-url' );
}

wp_cache_flush();


// ===========================================================================
// 1 — keyset enumeration covers every candidate exactly once.
// ===========================================================================
test_section( 'P10: keyset enumeration visits every candidate exactly once, in ID order' );

s2r_spy_reset();
$s2r_candidates = s2r_enumerate( $s2r_big_source );

assert_true( is_array( $s2r_candidates ), 'P10: enumeration returns a candidate list' );
assert_equals( $s2r_big_count, count( $s2r_candidates ), 'P10: a source with more than 300 events enumerates ALL of them' );
assert_equals( count( array_unique( $s2r_candidates ) ), count( $s2r_candidates ), 'P10: no candidate is returned twice across page boundaries' );

$s2r_sorted = $s2r_candidates;
sort( $s2r_sorted );
assert_equals( $s2r_sorted, $s2r_candidates, 'P10: candidates are enumerated in ascending ID order' );
assert_equals( $s2r_big_first, $s2r_candidates[0], 'P10: the walk starts at the lowest candidate ID' );
assert_equals( $s2r_big_last, $s2r_candidates[ count( $s2r_candidates ) - 1 ], 'P10: the walk ends at the highest candidate ID' );

// Exact page boundaries: 200 + 200 + 1 with the same bounded LIMIT each time.
assert_equals( 3, $GLOBALS['s2r_query_count'], 'P10: 401 candidates are read in 3 bounded pages (200 + 200 + 1)' );

$page_one = isset( $GLOBALS['s2r_queries'][0] ) ? $GLOBALS['s2r_queries'][0] : '';
$page_two = isset( $GLOBALS['s2r_queries'][1] ) ? $GLOBALS['s2r_queries'][1] : '';
$page_three = isset( $GLOBALS['s2r_queries'][2] ) ? $GLOBALS['s2r_queries'][2] : '';

assert_true( false !== strpos( $page_one, 'p.ID > 0' ), 'P10: the first page starts the cursor at 0' );
assert_true( false !== strpos( $page_two, 'p.ID > ' . $s2r_candidates[199] ), 'P10: page 2 starts strictly AFTER the last ID of page 1 (keyset, not OFFSET)' );
assert_true( false !== strpos( $page_three, 'p.ID > ' . $s2r_candidates[399] ), 'P10: page 3 starts strictly AFTER the last ID of page 2 (final partial page)' );

foreach ( array( $page_one, $page_two, $page_three ) as $s2r_page_sql ) {
	assert_true( false !== strpos( $s2r_page_sql, 'LIMIT 200' ), 'P10: every candidate page is bounded by the documented page size' );
	assert_true( false === strpos( $s2r_page_sql, 'OFFSET' ), 'P10: pagination never uses OFFSET' );
}

// Enumeration is read-only: nothing changed while the candidate set was built.
assert_equals( 0, s2r_count_status( $s2r_big_source, Conexao_Event_Status::SOURCE_NOT_FOUND ), 'P10: candidate enumeration changes no event status' );

// ===========================================================================
// 2 — a complete enumeration reconciles every source-owned event.
// ===========================================================================
test_section( 'P10: a complete enumeration reconciles all source-owned events, including beyond 300' );

$s2r_raw = array(
	array(
		'source' => $s2r_big_source,
		'url'    => 'https://s2r.test/e/present',
	),
);

s2r_spy_reset();

$s2r_marked = s2r_mark( $s2r_big_source, $s2r_raw, new S2R_Handler_Double() );

assert_equals( $s2r_big_count, $s2r_marked, 'P10: every absent source-owned event is marked, not just the first 300' );
assert_equals( $s2r_big_count, s2r_count_status( $s2r_big_source, Conexao_Event_Status::SOURCE_NOT_FOUND ), 'P10: all candidates carry source_not_found after a complete pass' );
assert_equals(
	Conexao_Event_Status::SOURCE_NOT_FOUND,
	get_post_meta( $s2r_big_last, '_event_status', true ),
	'P10: an event with an ID beyond the historical 300-post ceiling is reconciled'
);
assert_true(
	get_post_meta( $s2r_big_first, '_event_status', true ) === get_post_meta( $s2r_big_last, '_event_status', true ),
	'P10: the first and the last candidate receive identical treatment'
);

// ===========================================================================
// 3 — idempotence.
// ===========================================================================
test_section( 'P10: reconciliation is idempotent for a source in the same state' );

$s2r_status_before = array();

foreach ( s2r_ids( $s2r_big_source ) as $s2r_id ) {
	$s2r_status_before[ $s2r_id ] = (string) get_post_meta( $s2r_id, '_event_status', true );
}

s2r_spy_reset();
$s2r_marked_again = s2r_mark( $s2r_big_source, $s2r_raw, new S2R_Handler_Double() );

assert_equals( 0, $s2r_marked_again, 'P10: a second pass over the same state marks nothing new' );

$s2r_status_after = array();

foreach ( s2r_ids( $s2r_big_source ) as $s2r_id ) {
	$s2r_status_after[ $s2r_id ] = (string) get_post_meta( $s2r_id, '_event_status', true );
}

assert_equals( $s2r_status_before, $s2r_status_after, 'P10: the second pass produces exactly the same statuses' );


// ===========================================================================
// 4 — gate ordering: the P4 gates run BEFORE any candidate query.
// ===========================================================================
test_section( 'P10: an incomplete enumeration performs zero candidate queries and zero mutations' );

s2r_reset_statuses( $s2r_big_source );
s2r_spy_reset();

$s2r_incomplete_handler        = new S2R_Handler_Double();
$s2r_incomplete_handler->complete = false;

$s2r_marked = s2r_mark( $s2r_big_source, $s2r_raw, $s2r_incomplete_handler );

assert_equals( 0, $s2r_marked, 'P10: an incomplete enumeration causes zero reconciliation' );
assert_equals( 0, (int) $GLOBALS['s2r_query_count'], 'P10: an incomplete enumeration sends NO candidate-page query (gate fires first)' );
assert_equals( 0, s2r_count_status( $s2r_big_source, Conexao_Event_Status::SOURCE_NOT_FOUND ), 'P10: no event is marked source_not_found by an incomplete enumeration' );

test_section( 'P10: a checkpoint-resumed subset performs zero candidate queries and zero mutations' );

s2r_reset_statuses( $s2r_big_source );
s2r_spy_reset();

$s2r_resumed_handler        = new S2R_Handler_Double();
$s2r_resumed_handler->safe  = false;

$s2r_marked = s2r_mark( $s2r_big_source, $s2r_raw, $s2r_resumed_handler );

assert_equals( 0, $s2r_marked, 'P10: a checkpoint-resumed subset causes zero absence-based reconciliation' );
assert_equals( 0, (int) $GLOBALS['s2r_query_count'], 'P10: a resumed subset sends NO candidate-page query (safety gate fires first)' );
assert_equals( 0, s2r_count_status( $s2r_big_source, Conexao_Event_Status::SOURCE_NOT_FOUND ), 'P10: a resumed subset marks no event source_not_found' );

// ===========================================================================
// 5 — a failed later candidate page aborts with zero mutations.
// ===========================================================================
test_section( 'P10: a failed later candidate page causes zero absence-based mutations' );

s2r_reset_statuses( $s2r_big_source );
s2r_spy_reset( 2, 0, 0, true );

global $wpdb;
$wpdb->suppress_errors( true );

$s2r_marked = s2r_mark( $s2r_big_source, $s2r_raw, new S2R_Handler_Double() );

$wpdb->suppress_errors( false );

assert_equals( 0, $s2r_marked, 'P10: a failed candidate page aborts the pass' );
assert_true( (int) $GLOBALS['s2r_query_count'] >= 2, 'P10: the failure was injected on the second candidate page' );
assert_equals( 0, s2r_count_status( $s2r_big_source, Conexao_Event_Status::SOURCE_NOT_FOUND ), 'P10: page 1 is NOT reconciled while page 2 is still being discovered' );

// ===========================================================================
// 6 — a malformed candidate page aborts with zero mutations.
// ===========================================================================
test_section( 'P10: a malformed candidate page causes zero absence-based mutations' );

s2r_reset_statuses( $s2r_big_source );
s2r_spy_reset( 0, 2, 0, true );

$s2r_marked = s2r_mark( $s2r_big_source, $s2r_raw, new S2R_Handler_Double() );

assert_equals( 0, $s2r_marked, 'P10: a malformed (non-advancing ID) page aborts the pass' );
assert_equals( 0, s2r_count_status( $s2r_big_source, Conexao_Event_Status::SOURCE_NOT_FOUND ), 'P10: a malformed page leaves every status unchanged' );

// ===========================================================================
// 7 — an interruption before enumeration completes leaves every status alone.
// ===========================================================================
test_section( 'P10: an interruption before enumeration completes causes zero absence-based mutations' );

s2r_reset_statuses( $s2r_big_source );
s2r_spy_reset( 0, 0, 2, true );

$s2r_interrupted = false;

try {
	s2r_mark( $s2r_big_source, $s2r_raw, new S2R_Handler_Double() );
} catch ( RuntimeException $e ) {
	$s2r_interrupted = true;
}

assert_true( $s2r_interrupted, 'P10: the simulated interruption propagated instead of being swallowed' );
assert_equals( 0, s2r_count_status( $s2r_big_source, Conexao_Event_Status::SOURCE_NOT_FOUND ), 'P10: an interrupted candidate scan mutates nothing' );
assert_equals( $s2r_big_count, s2r_count_status( $s2r_big_source, Conexao_Event_Status::PUBLISHED ), 'P10: every event remains published after the interruption' );

// ===========================================================================
// 8 — a source with fewer than 300 events behaves exactly as before.
// ===========================================================================
test_section( 'P10: a source with fewer than 300 events reconciles in one page, as before' );

$s2r_small_raw = array(
	array(
		'source' => $s2r_small_source,
		'url'    => 'https://s2r.test/e/present',
	),
);

s2r_spy_reset();
$s2r_marked = s2r_mark( $s2r_small_source, $s2r_small_raw, new S2R_Handler_Double() );

assert_equals( $s2r_small_count, $s2r_marked, 'P10: a small source reconciles all of its absent events' );
assert_equals( 1, (int) $GLOBALS['s2r_query_count'], 'P10: a small source needs exactly one candidate page' );
assert_equals( $s2r_small_count, s2r_count_status( $s2r_small_source, Conexao_Event_Status::SOURCE_NOT_FOUND ), 'P10: the small source is fully reconciled' );

// ===========================================================================
// 9 — a translated record is never mutated by reconciliation.
// ===========================================================================
test_section( 'P10: reconciliation never changes the status of a translated record' );

$s2r_languages = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list() : array();
$s2r_default   = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';

if ( count( $s2r_languages ) >= 2 && '' !== $s2r_default && function_exists( 'pll_set_post_language' ) ) {
	$s2r_translation_source = 's2r_translation';
	s2r_cleanup( $s2r_translation_source );

	$s2r_other_language = '';

	foreach ( $s2r_languages as $s2r_language ) {
		if ( $s2r_language !== $s2r_default ) {
			$s2r_other_language = $s2r_language;
			break;
		}
	}

	$s2r_target_id      = s2r_create_event( $s2r_translation_source, 1, 'https://s2r.test/e/translation-target' );
	$s2r_translation_id = s2r_create_event( $s2r_translation_source, 2, 'https://s2r.test/e/translation-target' );

	pll_set_post_language( $s2r_target_id, $s2r_default );
	pll_set_post_language( $s2r_translation_id, $s2r_other_language );

	wp_cache_flush();
	s2r_spy_reset();

	$s2r_marked = s2r_mark(
		$s2r_translation_source,
		array(
			array(
				'source' => $s2r_translation_source,
				'url'    => 'https://s2r.test/e/present',
			),
		),
		new S2R_Handler_Double()
	);

	assert_equals( 1, $s2r_marked, 'P10: only the import-language record is reconciled' );
	assert_equals(
		Conexao_Event_Status::SOURCE_NOT_FOUND,
		get_post_meta( $s2r_target_id, '_event_status', true ),
		'P10: the import-language record is marked source_not_found'
	);
	assert_equals(
		Conexao_Event_Status::PUBLISHED,
		get_post_meta( $s2r_translation_id, '_event_status', true ),
		'P10: the translated record keeps its status (the importer never mutates a translation)'
	);

	s2r_cleanup( $s2r_translation_source );
} else {
	echo "  INFO: Polylang is inactive or exposes fewer than two languages; the translation guard is a documented no-op here.\n";
}


// ===========================================================================
// Cleanup
// ===========================================================================
s2r_cleanup( $s2r_big_source );
s2r_cleanup( $s2r_small_source );

s2r_spy_reset();

test_options_restore( $restore_options );
$GLOBALS['conexao_test_option_snapshots'] = array();

test_finish( 'stage2-reconciliation-pagination' );

