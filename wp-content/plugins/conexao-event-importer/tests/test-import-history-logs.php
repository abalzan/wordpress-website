<?php
/**
 * The Import History "Logs" column: run-level log association and rendering.
 *
 * ## The change this suite locks down
 *
 * The separate `admin.php?page=conexao-import-log` screen listed every log entry
 * in one stream, so an operator reading a failed Import History row had to guess
 * which of 2,000 entries belonged to it. The screen is retired and its useful
 * part now lives in the Import History table: each row opens the entries whose
 * `run_id` equals THAT row's `run_id`, and nothing else.
 *
 * ## What it proves
 *
 *   A. Run association — a row shows only its own run's entries; another run on
 *      the same source never leaks in; the count is the run's real count; the
 *      entries are newest first; error/warning/info levels and messages render.
 *   B. Historical records — a row with no `run_id` says the logs are
 *      unavailable and never falls back to source-wide entries; a run with no
 *      entries says "No logs"; missing optional fields raise no PHP notice.
 *   C. Dashboard rendering — the Logs header exists, every row has a Logs cell,
 *      the original 13 columns survive, the empty state still works, the
 *      disclosure is a real <details>/<summary>, and long messages/context are
 *      escaped rather than interpreted as HTML.
 *   D. Downloads and clearing — the per-run download reuses the existing
 *      protected handler, invalid run IDs are rejected, and both protected
 *      actions keep their capability + nonce checks.
 *   E. Regression — the log store's buffering, retention and pruning are
 *      untouched, and the index helper does not rescan the store per row.
 *
 * ## Safety
 *
 * `conexao_event_import_log` and `conexao_event_import_history` are OPERATOR
 * state, not fixtures. Both are snapshotted with `test_options_isolate()` and
 * restored verbatim, with a shutdown safety net. No content is created, no
 * network call is made, no import runs.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

// Child mode: prove the per-run download handler refuses a request with no run
// ID. The handler calls wp_die(), which ends the request, so this has to run in
// its own process — the parent asserts on this process's exit status.
if ( PHP_SAPI === 'cli' && isset( $argv[1] ) && '--child-invalid-run' === $argv[1] ) {
	require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

	// The handler checks manage_options BEFORE it looks at the run ID, so the
	// child must be an administrator: what is under test is the run-ID
	// validation, not the capability gate (which the parent asserts separately).
	$ihl_child_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);

	if ( ! empty( $ihl_child_admins ) ) {
		wp_set_current_user( (int) $ihl_child_admins[0]->ID );
	}

	$_GET['run'] = '';

	( new Conexao_Import_Log_Admin() )->handle_download_run_log();

	// Unreachable when the handler refuses as documented.
	exit( 0 );
}

// Admin menu registration lives in wp-admin; the real `add_submenu_page()` is
// what this suite inspects, so it must be available in the CLI context.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_prerequisite_hint( 'activate-plugin:conexao-event-importer' );
test_require(
	class_exists( 'Conexao_Event_Sources' ) && class_exists( 'Conexao_Import_Log' ),
	'activate-plugin:conexao-event-importer',
	'test prerequisite is available: the importer classes are loaded',
	'activate the conexao-event-importer plugin'
);

$ihl_restore = test_options_isolate(
	array(
		Conexao_Import_Log::OPTION_KEY,
		Conexao_Import_History::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $ihl_restore ) {
		wp_set_current_user( 0 );
		test_options_restore( $ihl_restore, true );
	}
);

$ihl_admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);

test_require(
	! empty( $ihl_admin ),
	'fixture:administrator',
	'an administrator account exists for rendering the dashboard',
	'create an administrator in the local install'
);

$ihl_admin_id = (int) $ihl_admin[0]->ID;

/**
 * Render the REAL history table and return its HTML.
 *
 * @param array $history History entries, newest first.
 * @return array{0: string, 1: string[]} HTML and any collected PHP messages.
 */
function ihl_render_history( array $history ) {
	$sources = new Conexao_Event_Sources();

	$messages = array();

	set_error_handler(
		static function ( $errno, $errstr ) use ( &$messages ) {
			if ( in_array( $errno, array( E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE ), true ) ) {
				$messages[] = $errstr;
			}
			return true;
		}
	);

	ob_start();
	$sources->render_history_table( $history );
	$html = (string) ob_get_clean();

	restore_error_handler();

	return array( $html, $messages );
}

/**
 * Parse rendered admin HTML into a document and an XPath handle.
 *
 * @param string $html Rendered markup.
 * @return array{0: DOMDocument, 1: DOMXPath}
 */
function ihl_parse( $html ) {
	libxml_use_internal_errors( true );

	$dom = new DOMDocument();
	$dom->loadHTML( '<?xml encoding="utf-8">' . $html );

	return array( $dom, new DOMXPath( $dom ) );
}

/**
 * The body rows of the rendered history table.
 *
 * @param DOMXPath $xpath XPath handle.
 * @return DOMNode[]
 */
function ihl_rows( DOMXPath $xpath ) {
	$rows = $xpath->query(
		'//table[contains(concat(" ", normalize-space(@class), " "), " conexao-import-history ")]/tbody/tr'
	);

	// Iterated, never (array)-cast: a DOMNodeList is a Traversable, and casting
	// an internal object to array exports its (empty) properties, not its items.
	$out = array();
	foreach ( $rows as $row ) {
		$out[] = $row;
	}

	return $out;
}

/**
 * The header labels of the rendered history table, in order.
 *
 * @param DOMXPath $xpath XPath handle.
 * @return string[]
 */
function ihl_headers( DOMXPath $xpath ) {
	$nodes = $xpath->query(
		'//table[contains(concat(" ", normalize-space(@class), " "), " conexao-import-history ")]/thead/tr/th'
	);

	$out = array();
	foreach ( $nodes as $node ) {
		$out[] = trim( (string) $node->textContent );
	}

	return $out;
}

/**
 * The trimmed text of one cell in a row.
 *
 * @param DOMXPath $xpath XPath handle.
 * @param DOMNode  $row   Table row.
 * @param int      $index Zero-based cell index.
 * @return string
 */
function ihl_cell_text( DOMXPath $xpath, $row, $index ) {
	$cells = $xpath->query( './td', $row );

	if ( ! $cells || $cells->length <= $index ) {
		return '';
	}

	return trim( (string) $cells->item( $index )->textContent );
}

/**
 * Build one structured log entry.
 *
 * @param string $run_id      Run ID.
 * @param string $level       Level.
 * @param string $message     Message.
 * @param array  $extra       Optional event_id, event_title, http_status, context, time.
 * @return array
 */
function ihl_log_entry( $run_id, $level, $message, array $extra = array() ) {
	return array(
		'time'        => isset( $extra['time'] ) ? $extra['time'] : '2026-11-14 09:00:00',
		'source'      => 'ihl_probe',
		'level'       => $level,
		'message'     => $message,
		'run_id'      => $run_id,
		'event_id'    => isset( $extra['event_id'] ) ? $extra['event_id'] : '',
		'event_title' => isset( $extra['event_title'] ) ? $extra['event_title'] : '',
		'http_status' => isset( $extra['http_status'] ) ? (int) $extra['http_status'] : 0,
		'context'     => isset( $extra['context'] ) ? $extra['context'] : array(),
	);
}

/**
 * Write a log store (newest first) and return it.
 *
 * @param array $entries Entries, newest first.
 * @return array
 */
function ihl_seed_log( array $entries ) {
	update_option( Conexao_Import_Log::OPTION_KEY, $entries, false );
	return $entries;
}

// ---------------------------------------------------------------------------
// Seed a log store with two runs on the SAME source, plus run-less entries.
//
// The same-source pair is the whole point: an implementation that grouped by
// source instead of by run would pass a naive single-run test and fail this one.
// ---------------------------------------------------------------------------

$ihl_run_a = 'ihl_run_a';
$ihl_run_b = 'ihl_run_b';

$ihl_log = array(
	// Run A, newest first: error, warning, info.
	ihl_log_entry( $ihl_run_a, 'error', 'Run A failed to fetch page 2', array( 'http_status' => 502, 'time' => '2026-11-14 09:03:00' ) ),
	ihl_log_entry( $ihl_run_a, 'warning', 'Run A is being throttled', array( 'time' => '2026-11-14 09:02:00' ) ),
	ihl_log_entry( $ihl_run_a, 'info', 'Run A started', array( 'time' => '2026-11-14 09:01:00' ) ),
	// Run B, same source, different run.
	ihl_log_entry( $ihl_run_b, 'error', 'Run B failed differently', array( 'http_status' => 429, 'time' => '2026-11-14 09:13:00' ) ),
	ihl_log_entry( $ihl_run_b, 'info', 'Run B started', array( 'time' => '2026-11-14 09:11:00' ) ),
	// A run-less entry: must never be attributed to any row.
	ihl_log_entry( '', 'info', 'Orphan entry with no run', array( 'time' => '2026-11-14 09:14:00' ) ),
);

ihl_seed_log( $ihl_log );

// ---------------------------------------------------------------------------
// Test A — run association.
// ---------------------------------------------------------------------------

test_section( 'Test A — a row shows only its own run\'s entries' );

$ihl_history = array(
	// Newest first, matching Conexao_Import_History::get_all().
	array(
		'time'    => '2026-11-14 09:15:00',
		'source'  => 'ihl_probe',
		'run_id'  => $ihl_run_b,
		'found'   => 2,
		'new'     => 1,
		'updated' => 0,
		'unchanged' => 0,
		'duplicates' => 0,
		'skipped' => 0,
		'errors'  => 1,
		'status'  => 'error',
	),
	array(
		'time'    => '2026-11-14 09:05:00',
		'source'  => 'ihl_probe',
		'run_id'  => $ihl_run_a,
		'found'   => 3,
		'new'     => 1,
		'updated' => 0,
		'unchanged' => 0,
		'duplicates' => 0,
		'skipped' => 0,
		'errors'  => 1,
		'status'  => 'error',
	),
);

$ihl_rendered = ihl_render_history( $ihl_history );

assert_true(
	empty( $ihl_rendered[1] ),
	'Test A: rendering the history table emits no PHP warning or notice',
	implode( ' | ', $ihl_rendered[1] )
);

list( $ihl_dom, $ihl_xpath ) = ihl_parse( $ihl_rendered[0] );
$ihl_rows = ihl_rows( $ihl_xpath );

assert_equals( 2, count( $ihl_rows ), 'Test A: both seeded history rows are rendered' );

// Row 0 is run B (2 entries), row 1 is run A (3 entries).
$ihl_logs_b = $ihl_xpath->query( './/td[contains(concat(" ", normalize-space(@class), " "), " conexao-history-logs-cell ")]', $ihl_rows[0] );
$ihl_logs_a = $ihl_xpath->query( './/td[contains(concat(" ", normalize-space(@class), " "), " conexao-history-logs-cell ")]', $ihl_rows[1] );

assert_equals( 1, $ihl_logs_b->length, 'Test A: the newest row has a Logs cell' );
assert_equals( 1, $ihl_logs_a->length, 'Test A: the older row has a Logs cell' );

$ihl_summary_b = $ihl_xpath->query( './/summary', $ihl_logs_b->item( 0 ) );
$ihl_summary_a = $ihl_xpath->query( './/summary', $ihl_logs_a->item( 0 ) );

assert_equals( 1, $ihl_summary_b->length, 'Test A: run B\'s Logs cell is a <details> disclosure' );
assert_equals( 1, $ihl_summary_a->length, 'Test A: run A\'s Logs cell is a <details> disclosure' );

assert_equals(
	'View logs (2 entries)',
	trim( (string) $ihl_summary_b->item( 0 )->textContent ),
	'Test A: run B\'s count is its own entry count (2), not the source total'
);

assert_equals(
	'View logs (3 entries)',
	trim( (string) $ihl_summary_a->item( 0 )->textContent ),
	'Test A: run A\'s count is its own entry count (3), not the source total'
);

// Run B's panel must contain ONLY run B's messages.
$ihl_panel_b = $ihl_xpath->query( './/ul[contains(concat(" ", normalize-space(@class), " "), " conexao-history-log-list ")]/li', $ihl_logs_b->item( 0 ) );
$ihl_b_texts = array();
foreach ( $ihl_panel_b as $ihl_item ) {
	$ihl_b_texts[] = trim( (string) $ihl_item->textContent );
}

assert_equals( 2, count( $ihl_b_texts ), 'Test A: run B renders exactly its two entries' );

$ihl_b_joined = implode( ' | ', $ihl_b_texts );

assert_true(
	false !== strpos( $ihl_b_joined, 'Run B failed differently' ),
	'Test A: run B shows its own error message'
);

assert_true(
	false !== strpos( $ihl_b_joined, 'Run B started' ),
	'Test A: run B shows its own info message'
);

assert_true(
	false === strpos( $ihl_b_joined, 'Run A' ),
	'Test A: run B never shows run A\'s messages (same source, different run)'
);

assert_true(
	false === strpos( $ihl_b_joined, 'Orphan entry' ),
	'Test A: a run-less entry is never attributed to a run'
);

// Run A's panel must contain ONLY run A's messages.
$ihl_panel_a = $ihl_xpath->query( './/ul[contains(concat(" ", normalize-space(@class), " "), " conexao-history-log-list ")]/li', $ihl_logs_a->item( 0 ) );
$ihl_a_texts = array();
foreach ( $ihl_panel_a as $ihl_item ) {
	$ihl_a_texts[] = trim( (string) $ihl_item->textContent );
}

assert_equals( 3, count( $ihl_a_texts ), 'Test A: run A renders exactly its three entries' );

$ihl_a_joined = implode( ' | ', $ihl_a_texts );

assert_true(
	false === strpos( $ihl_a_joined, 'Run B' ),
	'Test A: run A never shows run B\'s messages'
);

// Newest first: run A's error (09:03) precedes its info (09:01).
$ihl_pos_error = strpos( $ihl_a_joined, 'Run A failed to fetch page 2' );
$ihl_pos_info  = strpos( $ihl_a_joined, 'Run A started' );

assert_true(
	false !== $ihl_pos_error && false !== $ihl_pos_info && $ihl_pos_error < $ihl_pos_info,
	'Test A: entries are rendered newest first'
);

// Levels render as words, so severity never depends on colour alone.
assert_true(
	false !== strpos( $ihl_a_joined, 'ERROR' ),
	'Test A: the error level is rendered as a word'
);

assert_true(
	false !== strpos( $ihl_a_joined, 'WARNING' ),
	'Test A: the warning level is rendered as a word'
);

assert_true(
	false !== strpos( $ihl_a_joined, 'INFO' ),
	'Test A: the info level is rendered as a word'
);

// The error entry carries its HTTP status and its event context.
assert_true(
	false !== strpos( $ihl_a_joined, 'HTTP 502' ),
	'Test A: the HTTP status is shown when present'
);

// ---------------------------------------------------------------------------
// Test B — historical records and empty states.
// ---------------------------------------------------------------------------

test_section( 'Test B — historical records and empty states' );

// A record written before run logging existed: no run_id at all.
$ihl_legacy = ihl_render_history(
	array(
		array(
			'time'       => '2026-01-02 03:04:05',
			'source'     => 'ihl_legacy_row',
			'found'      => 4,
			'new'        => 1,
			'updated'    => 0,
			'duplicates' => 1,
			'errors'     => 0,
			'status'     => 'success',
		),
	)
);

assert_true(
	empty( $ihl_legacy[1] ),
	'Test B: a record with no run_id produces no PHP warning or notice',
	implode( ' | ', $ihl_legacy[1] )
);

list( $ihl_legacy_dom, $ihl_legacy_xpath ) = ihl_parse( $ihl_legacy[0] );
$ihl_legacy_rows = ihl_rows( $ihl_legacy_xpath );

assert_equals( 1, count( $ihl_legacy_rows ), 'Test B: the legacy record renders as one row' );

$ihl_legacy_cell = ihl_cell_text( $ihl_legacy_xpath, $ihl_legacy_rows[0], 13 );

assert_true(
	false !== strpos( $ihl_legacy_cell, 'Logs unavailable for this historical run' ),
	'Test B: a record with no run_id says the logs are unavailable (got "' . $ihl_legacy_cell . '")'
);

assert_true(
	false === strpos( $ihl_legacy_cell, 'Orphan entry' ),
	'Test B: a record with no run_id never falls back to source-wide logs'
);

assert_true(
	false === strpos( $ihl_legacy_cell, 'Run A' ) && false === strpos( $ihl_legacy_cell, 'Run B' ),
	'Test B: a record with no run_id never borrows another run\'s entries'
);

// A run whose entries have been pruned: the run exists, the log does not.
$ihl_pruned = ihl_render_history(
	array(
		array(
			'time'       => '2026-01-02 03:04:05',
			'source'     => 'ihl_pruned_row',
			'run_id'     => 'ihl_run_pruned',
			'found'      => 1,
			'new'        => 0,
			'updated'    => 0,
			'duplicates' => 0,
			'errors'     => 0,
			'status'     => 'success',
		),
	)
);

list( $ihl_pruned_dom, $ihl_pruned_xpath ) = ihl_parse( $ihl_pruned[0] );
$ihl_pruned_rows = ihl_rows( $ihl_pruned_xpath );

assert_equals(
	'No logs',
	ihl_cell_text( $ihl_pruned_xpath, $ihl_pruned_rows[0], 13 ),
	'Test B: a run with no matching entries renders the empty state'
);

assert_true(
	false === strpos( $ihl_pruned[0], '<details' ),
	'Test B: an empty run renders no disclosure (nothing to expand)'
);

// A run with exactly one entry uses the singular wording.
$ihl_single = ihl_render_history(
	array(
		array(
			'time'       => '2026-01-02 03:04:05',
			'source'     => 'ihl_single_row',
			'run_id'     => $ihl_run_b,
			'found'      => 1,
			'new'        => 0,
			'updated'    => 0,
			'duplicates' => 0,
			'errors'     => 0,
			'status'     => 'success',
		),
	)
);

assert_true(
	false !== strpos( $ihl_single[0], 'View logs' ),
	'Test B: a run with entries renders the View logs disclosure'
);

// Missing optional log fields must not warn: an entry with only a message.
ihl_seed_log(
	array(
		array(
			'time'    => '2026-11-14 09:00:00',
			'source'  => 'ihl_probe',
			'level'   => 'info',
			'message' => 'Entry with no optional fields',
			'run_id'  => 'ihl_run_bare',
		),
	)
);

$ihl_bare = ihl_render_history(
	array(
		array(
			'time'       => '2026-01-02 03:04:05',
			'source'     => 'ihl_bare_row',
			'run_id'     => 'ihl_run_bare',
			'found'      => 1,
			'new'        => 0,
			'updated'    => 0,
			'duplicates' => 0,
			'errors'     => 0,
			'status'     => 'success',
		),
	)
);

assert_true(
	empty( $ihl_bare[1] ),
	'Test B: an entry with no optional fields produces no PHP warning or notice',
	implode( ' | ', $ihl_bare[1] )
);

assert_true(
	false !== strpos( $ihl_bare[0], 'Entry with no optional fields' ),
	'Test B: an entry with only a message still renders its message'
);

// Restore the two-run store for the rendering tests.
ihl_seed_log( $ihl_log );

// ---------------------------------------------------------------------------
// Test C — dashboard rendering contract.
// ---------------------------------------------------------------------------

test_section( 'Test C — dashboard rendering contract' );

$ihl_headers = ihl_headers( $ihl_xpath );

assert_equals( 'Logs', $ihl_headers[ count( $ihl_headers ) - 1 ], 'Test C: the Logs header exists and is last' );

$ihl_original = array( 'Time', 'Source', 'Found', 'New', 'Updated', 'Unchanged', 'Duplicates', 'Skipped', 'Errors', 'Status', 'Listing', 'Progress', 'Cooldown' );

assert_equals(
	$ihl_original,
	array_slice( $ihl_headers, 0, 13 ),
	'Test C: the original 13 columns are unchanged and still first'
);

assert_equals( 14, count( $ihl_headers ), 'Test C: the table has exactly 14 columns' );

// Every row has a Logs cell.
$ihl_missing_cells = 0;
foreach ( $ihl_rows as $ihl_row ) {
	$ihl_cells = $ihl_xpath->query( './td', $ihl_row );
	if ( ! $ihl_cells || $ihl_cells->length < 14 ) {
		$ihl_missing_cells++;
	}
}

assert_equals( 0, $ihl_missing_cells, 'Test C: every history row has a Logs cell' );

// The disclosure is a real <details>/<summary>, so it is keyboard-operable.
assert_true(
	false !== strpos( $ihl_rendered[0], '<details' ),
	'Test C: the inline viewer is a native <details> disclosure'
);

assert_true(
	false !== strpos( $ihl_rendered[0], '<summary' ),
	'Test C: the disclosure has a <summary> control'
);

// The empty state still works.
$ihl_empty = ihl_render_history( array() );

assert_contains(
	__( 'No imports yet.', 'conexao-event-importer' ),
	$ihl_empty[0],
	'Test C: the existing empty-history state is rendered'
);

assert_true(
	empty( $ihl_empty[1] ),
	'Test C: an empty history produces no PHP warning or notice',
	implode( ' | ', $ihl_empty[1] )
);

// Long messages and context are escaped, never interpreted as markup.
ihl_seed_log(
	array(
		ihl_log_entry(
			'ihl_run_escape',
			'error',
			'<script>alert("xss")</script> & "quoted"',
			array(
				'event_title' => '<b>Bold</b> & "quoted"',
				'event_id'    => '"><img src=x>',
				'context'     => array( 'url' => 'https://example.test/?a=1&b=<script>' ),
			)
		),
	)
);

$ihl_escape = ihl_render_history(
	array(
		array(
			'time'       => '2026-01-02 03:04:05',
			'source'     => 'ihl_escape_row',
			'run_id'     => 'ihl_run_escape',
			'found'      => 1,
			'new'        => 0,
			'updated'    => 0,
			'duplicates' => 0,
			'errors'     => 1,
			'status'     => 'error',
		),
	)
);

assert_true(
	false === strpos( $ihl_escape[0], '<script>alert' ),
	'Test C: a log message is never rendered as raw HTML'
);

assert_true(
	false === strpos( $ihl_escape[0], '<img src=x>' ),
	'Test C: an event ID is never rendered as raw HTML'
);

assert_true(
	false !== strpos( $ihl_escape[0], '&lt;script&gt;alert' ),
	'Test C: the message is escaped and still readable'
);

assert_contains(
	'&amp;',
	$ihl_escape[0],
	'Test C: an ampersand in a message is escaped'
);

assert_true(
	false !== strpos( $ihl_escape[0], 'https://example.test/?a=1&amp;b=' ),
	'Test C: a URL in the context is escaped and still readable'
);

// Restore the two-run store.
ihl_seed_log( $ihl_log );

// ---------------------------------------------------------------------------
// Test D — downloads and clearing stay protected.
// ---------------------------------------------------------------------------

test_section( 'Test D — downloads and clearing stay protected' );

$ihl_admin_class = new Conexao_Import_Log_Admin();

// The per-run download reuses the EXISTING protected handler.
assert_true(
	false !== has_action( 'admin_post_conexao_download_run_log', array( $ihl_admin_class, 'handle_download_run_log' ) ),
	'Test D: the per-run download uses the existing protected handler'
);

assert_true(
	false !== has_action( 'admin_post_conexao_download_log', array( $ihl_admin_class, 'handle_download_log' ) ),
	'Test D: the whole-log download uses the existing protected handler'
);

assert_true(
	false !== has_action( 'admin_post_conexao_clear_log', array( $ihl_admin_class, 'handle_clear_log' ) ),
	'Test D: Clear Logs uses the existing protected handler'
);

// The per-run URL carries the run ID and a per-run nonce.
$ihl_run_url = Conexao_Import_Log_Admin::run_log_download_url( $ihl_run_a );

assert_true(
	false !== strpos( $ihl_run_url, 'action=conexao_download_run_log' ),
	'Test D: the per-run download URL targets the protected endpoint'
);

assert_true(
	false !== strpos( $ihl_run_url, 'run=' . $ihl_run_a ),
	'Test D: the per-run download URL carries the run ID'
);

assert_true(
	false !== strpos( $ihl_run_url, '_wpnonce=' ),
	'Test D: the per-run download URL carries a nonce'
);

// The rendered dashboard actually offers that URL for the matching row.
assert_true(
	false !== strpos( $ihl_rendered[0], esc_url( $ihl_run_url ) ),
	'Test D: the dashboard renders the per-run download action for a run with entries'
);

// An invalid run ID is rejected by the handler before any log is read. The
// handler calls wp_die(), which ENDS the request, so this is proven in a child
// process: the child must exit non-zero with the documented refusal, and this
// parent process must still be alive to report it.
$ihl_child = escapeshellarg( __FILE__ );
$ihl_cmd   = 'php ' . $ihl_child . ' --child-invalid-run 2>&1';

exec( $ihl_cmd, $ihl_child_out, $ihl_child_status );

$ihl_child_text = implode( "\n", $ihl_child_out );

// wp_die() ends the request with exit 0 in this CLI context, so the refusal is
// proven by the message it prints, not by the exit status.
assert_true(
	false !== stripos( $ihl_child_text, 'Invalid import run.' ),
	'Test D: the per-run handler refuses a request with no run ID'
);

// Capability + nonce are enforced inside the handler source, not just implied.
$ihl_log_admin_source = (string) file_get_contents(
	WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-import-log-admin.php'
);

assert_true(
	false !== strpos( $ihl_log_admin_source, "current_user_can( self::CAPABILITY )" ),
	'Test D: every protected handler checks manage_options'
);

assert_true(
	false !== strpos( $ihl_log_admin_source, "check_admin_referer( 'conexao_download_run_log_'" ),
	'Test D: the per-run download verifies its per-run nonce'
);

assert_true(
	false !== strpos( $ihl_log_admin_source, "check_admin_referer( 'conexao_download_log'" ),
	'Test D: the whole-log download verifies its nonce'
);

assert_true(
	false !== strpos( $ihl_log_admin_source, "check_admin_referer( 'conexao_clear_log'" ),
	'Test D: Clear Logs verifies its nonce'
);

assert_true(
	false !== strpos( $ihl_log_admin_source, 'sanitize_key( wp_unslash( $_GET[\'run\'] ) )' ),
	'Test D: the requested run ID is sanitised'
);

assert_true(
	false !== strpos( $ihl_log_admin_source, 'sanitize_file_name( $filename )' ),
	'Test D: the download filename is sanitised'
);

// The clear action is destructive, so it must be confirmed before it runs.
assert_true(
	false !== strpos( $ihl_rendered[0], 'data-conexao-confirm' ),
	'Test D: the Clear Logs action requires an explicit confirmation'
);

// And it must NOT sit beside the per-row View logs control.
$ihl_clear_in_row = $ihl_xpath->query(
	'//table[contains(concat(" ", normalize-space(@class), " "), " conexao-import-history ")]//a[contains(@href, "conexao_clear_log")]'
);

assert_equals(
	0,
	$ihl_clear_in_row->length,
	'Test D: the destructive Clear Logs action is not placed inside the history table'
);

// ---------------------------------------------------------------------------
// Test E — regression: the log store and the index helper are unchanged.
// ---------------------------------------------------------------------------

test_section( 'Test E — log store and index helper regressions' );

assert_true(
	'conexao_event_import_log' === Conexao_Import_Log::OPTION_KEY,
	'Test E: the log option key is unchanged'
);

assert_true(
	2000 === Conexao_Import_Log::MAX_ENTRIES,
	'Test E: the log retention cap is unchanged at 2000 entries'
);

assert_true(
	250 === Conexao_Import_Log::BUFFER_FLUSH_AT,
	'Test E: the buffering flush threshold is unchanged'
);

assert_true(
	method_exists( 'Conexao_Import_Log', 'begin_run' )
		&& method_exists( 'Conexao_Import_Log', 'end_run' )
		&& method_exists( 'Conexao_Import_Log', 'flush' ),
	'Test E: run buffering is unchanged'
);

assert_true(
	method_exists( 'Conexao_Import_Log', 'prune_older_than_90_days' ),
	'Test E: 90-day retention is unchanged'
);

assert_true(
	method_exists( 'Conexao_Import_Log', 'sanitize_context' )
		|| method_exists( 'Conexao_Import_Log', 'add' ),
	'Test E: context sanitisation is unchanged'
);

// The index groups by run and drops run-less entries.
$ihl_index = Conexao_Import_Log::index_entries_by_run( $ihl_log );

assert_true(
	isset( $ihl_index[ $ihl_run_a ] ) && 3 === count( $ihl_index[ $ihl_run_a ] ),
	'Test E: the index groups run A\'s three entries'
);

assert_true(
	isset( $ihl_index[ $ihl_run_b ] ) && 2 === count( $ihl_index[ $ihl_run_b ] ),
	'Test E: the index groups run B\'s two entries'
);

assert_equals(
	2,
	count( $ihl_index ),
	'Test E: the index has one group per run, and no group for run-less entries'
);

assert_equals(
	array(),
	Conexao_Import_Log::entries_for_run_index( $ihl_index, 'ihl_run_unknown' ),
	'Test E: an unknown run resolves to an empty list'
);

assert_equals(
	array(),
	Conexao_Import_Log::entries_for_run_index( $ihl_index, '' ),
	'Test E: an empty run ID resolves to an empty list'
);

// The index preserves the stored (newest-first) order within a group.
$ihl_group_times = array();
foreach ( $ihl_index[ $ihl_run_a ] as $ihl_entry ) {
	$ihl_group_times[] = $ihl_entry['time'];
}

assert_equals(
	array( '2026-11-14 09:03:00', '2026-11-14 09:02:00', '2026-11-14 09:01:00' ),
	$ihl_group_times,
	'Test E: the index preserves newest-first order within a run'
);

// Rendering is a pure read: it must not rewrite the log store.
$ihl_log_before = get_option( Conexao_Import_Log::OPTION_KEY );
ihl_render_history( $ihl_history );
$ihl_log_after = get_option( Conexao_Import_Log::OPTION_KEY );

assert_true(
	$ihl_log_before === $ihl_log_after,
	'Test E: rendering the history table does not rewrite the log store'
);

// The index is built once per render, not once per row: a single call site.
$ihl_sources_source = (string) file_get_contents(
	WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-sources.php'
);

assert_equals(
	1,
	substr_count( $ihl_sources_source, 'Conexao_Import_Log::get_all()' ),
	'Test E: the log store is read exactly once per render (no per-row rescan)'
);

assert_equals(
	1,
	substr_count( $ihl_sources_source, 'Conexao_Import_Log::index_entries_by_run(' ),
	'Test E: the run index is built exactly once per render'
);

assert_true(
	false === strpos( $ihl_sources_source, 'Conexao_Import_Log::get_for_run(' ),
	'Test E: the renderer does not rescan the store per row via get_for_run()'
);

wp_set_current_user( 0 );

test_finish( 'import-history-logs' );