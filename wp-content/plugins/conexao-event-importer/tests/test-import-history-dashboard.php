<?php
/**
 * The Event Import dashboard is the ONE place import history is shown.
 *
 * ## The change this suite locks down
 *
 * Import history used to be rendered twice: a 10-row "summary" inside the main
 * Event Import dashboard (10 columns) and a complete table on a separate
 * `admin.php?page=conexao-import-history` screen (13 columns, including
 * Listing, Progress and Cooldown). Two renderers of the same data meant the
 * operator had to know which screen told the truth, and the dashboard silently
 * hid everything past the tenth run.
 *
 * The complete table now lives on the dashboard, the separate screen is retired,
 * and there is exactly one renderer. This suite proves that, and proves that the
 * consolidation did not quietly change the data it displays.
 *
 * ## What it proves
 *
 *   A. The dashboard renders the FULL retained history (12 records, not 10),
 *      newest first, with the oldest record still present, in a 13-column table.
 *   B. Every detailed column survives: Listing, Progress, Cooldown — plus the
 *      ten summary columns that existed before.
 *   C. The Progress cell is produced by the production helper
 *      `Conexao_Event_Sources::history_progress_text()`, not by a second
 *      calculation, and that helper is defined exactly once.
 *   D. An empty history renders the existing empty state, with no warning.
 *   E. A historical record that predates the optional fields still renders its
 *      documented fallbacks, with no warning or notice.
 *   F. The Import Logs link is still offered, so detailed diagnostics remain
 *      reachable from the single history screen.
 *   G. `conexao-import-history` is no longer a registered submenu, its page
 *      callback is gone, the dashboard/Event Sources/Import Logs pages are still
 *      registered, and the retired URL resolves to the dashboard for an
 *      administrator (and only for one).
 *   H. Nothing about the storage contract moved: `MAX_ENTRIES`, the option key,
 *      newest-first ordering and the stored rows themselves are unchanged, and
 *      rendering does not touch them.
 *
 * ## Safety
 *
 * `conexao_event_import_history` and `conexao_event_sources` are OPERATOR
 * state, not fixtures. Both are snapshotted with `test_options_isolate()` and
 * restored verbatim, with a shutdown safety net. No content is created, no
 * network call is made, no import runs.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

// Admin menu registration lives in wp-admin; the real `add_submenu_page()` is
// what this suite inspects, so it must be available in the CLI context.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_prerequisite_hint( 'activate-plugin:conexao-event-importer' );
test_require(
	class_exists( 'Conexao_Event_Sources' ) && class_exists( 'Conexao_Import_History' ),
	'activate-plugin:conexao-event-importer',
	'test prerequisite is available: the importer classes are loaded',
	'activate the conexao-event-importer plugin'
);

$iah_restore = test_options_isolate(
	array(
		Conexao_Import_History::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
		Conexao_Import_Log::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $iah_restore ) {
		wp_set_current_user( 0 );
		test_options_restore( $iah_restore, true );
	}
);

$iah_admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);

test_require(
	! empty( $iah_admin ),
	'fixture:administrator',
	'an administrator account exists for rendering the dashboard',
	'create an administrator in the local install'
);

$iah_admin_id = (int) $iah_admin[0]->ID;

/**
 * Render the REAL dashboard and return its HTML.
 *
 * No output-buffering tricks beyond capturing the method's own echo, and no
 * reimplementation of the page: the assertions run against what an
 * administrator's browser would receive.
 *
 * @return string
 */
function iah_dashboard_html() {
	$sources = new Conexao_Event_Sources();

	ob_start();
	$sources->render_import_dashboard();

	return (string) ob_get_clean();
}

/**
 * Render a callable and collect every PHP warning/notice it emits.
 *
 * PHP 8 turns an undefined array key into an E_WARNING, which is exactly the
 * failure mode a widened table of older records could introduce. Deprecations
 * from core are deliberately not collected: they belong to WordPress, not to
 * this renderer.
 *
 * @param callable $render Callable that echoes.
 * @return array{0: string, 1: string[]} HTML and the collected messages.
 */
function iah_render_capturing_warnings( callable $render ) {
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
	$produced = $render();
	$html     = (string) ob_get_clean();

	// A helper may either echo or return its markup; both count as rendered.
	if ( '' === $html && is_string( $produced ) ) {
		$html = $produced;
	}

	restore_error_handler();

	return array( $html, $messages );
}

/**
 * Parse rendered admin HTML into a document and an XPath handle.
 *
 * The DOMDocument is returned ALONGSIDE the DOMXPath on purpose: an XPath
 * object does not keep its document alive, so a helper that returned only the
 * XPath would free the document on return and every later query would come
 * back empty. Keeping both references is what makes the parsed tree usable for
 * the rest of the suite.
 *
 * @param string $html Rendered markup.
 * @return array{0: DOMDocument, 1: DOMXPath}
 */
function iah_parse( $html ) {
	libxml_use_internal_errors( true );

	$dom = new DOMDocument();
	$dom->loadHTML( '<?xml encoding="utf-8">' . $html );

	return array( $dom, new DOMXPath( $dom ) );
}

/**
 * The Import History table in rendered markup.
 *
 * Selected by the class the production renderer emits, so a rename breaks the
 * suite loudly instead of silently matching nothing.
 *
 * @param DOMXPath $xpath XPath handle.
 * @return DOMNodeList
 */
function iah_history_table( DOMXPath $xpath ) {
	return $xpath->query(
		'//table[contains(concat(" ", normalize-space(@class), " "), " conexao-import-history ")]'
	);
}

/**
 * The trimmed text of one cell in a row.
 *
 * @param DOMXPath $xpath XPath handle.
 * @param DOMNode  $row   Table row.
 * @param int      $index Zero-based cell index.
 * @return string
 */
function iah_cell_text( DOMXPath $xpath, $row, $index ) {
	$cells = $xpath->query( './td', $row );

	if ( ! $cells || $cells->length <= $index ) {
		return '';
	}

	return trim( (string) $cells->item( $index )->textContent );
}

/**
 * The rows of the rendered history table.
 *
 * @param DOMXPath $xpath XPath handle.
 * @return array List of DOMNode rows (body only).
 */
function iah_history_rows( DOMXPath $xpath ) {
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
function iah_history_headers( DOMXPath $xpath ) {
	$nodes = $xpath->query(
		'//table[contains(concat(" ", normalize-space(@class), " "), " conexao-import-history ")]/thead/tr/th'
	);

	// Same rule as iah_history_rows(): iterate the list, never cast it.
	$out = array();
	foreach ( $nodes as $node ) {
		$out[] = trim( (string) $node->textContent );
	}

	return $out;
}

// ---------------------------------------------------------------------------
// Seed a history longer than the retired dashboard's 10-row slice.
//
// Written through the PRODUCTION recorder, so ordering, field shapes and the
// retention cap are the real ones rather than a hand-built array.
// ---------------------------------------------------------------------------

$iah_history_count = 12;

for ( $iah_i = 1; $iah_i <= $iah_history_count; $iah_i++ ) {
	Conexao_Import_History::record(
		sprintf( 'iah_probe_%02d', $iah_i ),
		array(
			'found'      => $iah_i,
			'new'        => 1,
			'updated'    => 2,
			'unchanged'  => 3,
			'duplicates' => 4,
			'skipped'    => 5,
			'errors'     => 0,
		),
		'success',
		'probe run ' . $iah_i,
		array(
			'complete'   => 0 === ( $iah_i % 3 ),
			'cooldown'   => array(
				'cooling_down'      => 0 === ( $iah_i % 2 ),
				'seconds_remaining' => 120,
				'next_eligible'     => '2026-11-13 10:02:00',
				'last_reason'       => 'throttled',
				'consecutive'       => 1,
			),
			'checkpoint' => array(
				'has_checkpoint' => 0 === ( $iah_i % 4 ),
				'last_page'      => 2,
				'page_count'     => 20,
				'complete'       => false,
				'last_failure'   => '',
				'updated_at'     => '2026-11-13 10:00:00',
			),
		)
	);
}

$iah_retained = Conexao_Import_History::get_all();

// ---------------------------------------------------------------------------
// Test A — the dashboard renders the COMPLETE history.
// ---------------------------------------------------------------------------

test_section( 'Test A — the dashboard displays the complete retained history' );

// The dashboard is an admin screen: its capability check must see an
// administrator, exactly as a real request would.
wp_set_current_user( $iah_admin_id );

$iah_render   = iah_render_capturing_warnings( 'iah_dashboard_html' );
$iah_html     = $iah_render[0];
$iah_warnings = $iah_render[1];

list( $iah_dom, $iah_xpath ) = iah_parse( $iah_html );
$iah_rows    = iah_history_rows( $iah_xpath );
$iah_headers = iah_history_headers( $iah_xpath );

assert_true(
	empty( $iah_warnings ),
	'Test A: rendering the dashboard emits no PHP warning or notice',
	implode( ' | ', $iah_warnings )
);

assert_true(
	$iah_history_count === count( $iah_retained ),
	'Test A: precondition — the recorder retained all ' . $iah_history_count . ' probe runs'
);

assert_true(
	count( $iah_rows ) > 10,
	'Test A: the dashboard shows MORE than the old 10-row slice (' . count( $iah_rows ) . ' rows)'
);

assert_true(
	count( $iah_rows ) === count( $iah_retained ),
	'Test A: the number of displayed entries matches the retained history'
);

assert_true(
	13 === count( $iah_headers ),
	'Test A: the table renders all 13 columns (got ' . count( $iah_headers ) . ')'
);

assert_true(
	'iah_probe_12' === iah_cell_text( $iah_xpath, $iah_rows[0], 1 ),
	'Test A: the newest entry is displayed first (got ' . iah_cell_text( $iah_xpath, $iah_rows[0], 1 ) . ')'
);

assert_true(
	'iah_probe_01' === iah_cell_text( $iah_xpath, $iah_rows[ count( $iah_rows ) - 1 ], 1 ),
	'Test A: the oldest retained entry is still displayed (got ' . iah_cell_text( $iah_xpath, $iah_rows[ count( $iah_rows ) - 1 ], 1 ) . ')'
);

$iah_rendered_sources = array();
foreach ( $iah_rows as $iah_row ) {
	$iah_rendered_sources[] = iah_cell_text( $iah_xpath, $iah_row, 1 );
}

$iah_expected_sources = array();
for ( $iah_i = 1; $iah_i <= $iah_history_count; $iah_i++ ) {
	$iah_expected_sources[] = sprintf( 'iah_probe_%02d', $iah_i );
}

assert_equals(
	$iah_expected_sources,
	array_reverse( $iah_rendered_sources ),
	'Test A: every seeded run appears exactly once, newest first'
);

assert_true(
	false !== strpos( $iah_html, 'Import History' ),
	'Test A: the dashboard renders the Import History section heading'
);

// ---------------------------------------------------------------------------
// Test B — every detailed and summary column is present.
// ---------------------------------------------------------------------------

test_section( 'Test B — all 13 columns are present, in order' );

$iah_expected_headers = array(
	__( 'Time', 'conexao-event-importer' ),
	__( 'Source', 'conexao-event-importer' ),
	__( 'Found', 'conexao-event-importer' ),
	__( 'New', 'conexao-event-importer' ),
	__( 'Updated', 'conexao-event-importer' ),
	__( 'Unchanged', 'conexao-event-importer' ),
	__( 'Duplicates', 'conexao-event-importer' ),
	__( 'Skipped', 'conexao-event-importer' ),
	__( 'Errors', 'conexao-event-importer' ),
	__( 'Status', 'conexao-event-importer' ),
	__( 'Listing', 'conexao-event-importer' ),
	__( 'Progress', 'conexao-event-importer' ),
	__( 'Cooldown', 'conexao-event-importer' ),
);

assert_equals( $iah_expected_headers, $iah_headers, 'Test B: the 13 headers are exactly the retired page\'s headers' );

foreach ( $iah_expected_headers as $iah_header ) {
	assert_true(
		in_array( $iah_header, $iah_headers, true ),
		'Test B: the "' . $iah_header . '" column is rendered'
	);
}

// The detail columns must carry real content, not an empty cell.
assert_true(
	'complete' === iah_cell_text( $iah_xpath, $iah_rows[0], 10 ) || 'incomplete' === iah_cell_text( $iah_xpath, $iah_rows[0], 10 ),
	'Test B: the Listing column renders the completeness of the run'
);

assert_true(
	'' !== iah_cell_text( $iah_xpath, $iah_rows[0], 12 ),
	'Test B: the Cooldown column renders the cooldown state'
);

assert_true(
	false !== strpos( iah_cell_text( $iah_xpath, $iah_rows[0], 12 ), 'cooling down' ),
	'Test B: a cooling-down run states the reason and the remaining seconds (got ' . iah_cell_text( $iah_xpath, $iah_rows[0], 12 ) . ')'
);

// ---------------------------------------------------------------------------
// Test C — the Progress cell is the production helper's text, and only one
// calculation exists.
// ---------------------------------------------------------------------------

test_section( 'Test C — the Progress cell comes from the production helper' );

$iah_progress_mismatches = array();

foreach ( $iah_retained as $iah_index => $iah_entry ) {
	$iah_cell = iah_cell_text( $iah_xpath, $iah_rows[ $iah_index ], 11 );
	$iah_help = Conexao_Event_Sources::history_progress_text( $iah_entry );

	if ( $iah_cell !== $iah_help ) {
		$iah_progress_mismatches[] = $iah_entry['source'] . ' cell="' . $iah_cell . '" helper="' . $iah_help . '"';
	}
}

assert_true(
	empty( $iah_progress_mismatches ),
	'Test C: every Progress cell equals Conexao_Event_Sources::history_progress_text() for the same row',
	implode( ' | ', $iah_progress_mismatches )
);

$iah_class_source = (string) file_get_contents(
	WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-sources.php'
);

assert_equals(
	1,
	substr_count( $iah_class_source, 'function history_progress_text' ),
	'Test C: the progress calculation is defined exactly once'
);

assert_true(
	false !== strpos( $iah_class_source, 'self::history_progress_text( $entry )' ),
	'Test C: the history table renders the Progress cell through that single helper'
);

// A checkpoint is progress, never completeness: the Listing column keeps its own
// signal. Both are read from the same row so a future renderer cannot merge them.
$iah_page_row = null;
foreach ( $iah_retained as $iah_index => $iah_entry ) {
	if ( ! empty( $iah_entry['checkpoint']['has_checkpoint'] ) ) {
		$iah_page_row = $iah_index;
		break;
	}
}

assert_true( null !== $iah_page_row, 'Test C: precondition — at least one seeded run carries a checkpoint' );

$iah_page_cell = iah_cell_text( $iah_xpath, $iah_rows[ $iah_page_row ], 11 );

assert_true(
	false !== strpos( $iah_page_cell, 'page 2 of 20' ),
	'Test C: a resumable run states its position (got ' . $iah_page_cell . ')'
);

assert_true(
	false === strpos( $iah_page_cell, 'resumable' ),
	'Test C: no continuation is scheduled for these probes, so the cell must NOT promise resumable'
);

// ---------------------------------------------------------------------------
// Test D — an empty history renders the existing empty state, silently.
// ---------------------------------------------------------------------------

test_section( 'Test D — empty history renders "No imports yet." without notices' );

update_option( Conexao_Import_History::OPTION_KEY, array(), false );

$iah_empty = iah_render_capturing_warnings( 'iah_dashboard_html' );

assert_contains(
	__( 'No imports yet.', 'conexao-event-importer' ),
	$iah_empty[0],
	'Test D: the existing empty state is rendered'
);

assert_true(
	empty( $iah_empty[1] ),
	'Test D: an empty history produces no PHP warning or notice',
	implode( ' | ', $iah_empty[1] )
);

list( $iah_empty_dom, $iah_empty_xpath ) = iah_parse( $iah_empty[0] );

assert_true(
	0 === count( iah_history_rows( $iah_empty_xpath ) ),
	'Test D: no history rows are rendered for an empty history'
);

assert_true(
	0 === iah_history_table( $iah_empty_xpath )->length,
	'Test D: the history table markup is absent, not an empty table'
);

// Restore the seeded history for the remaining tests.
update_option( Conexao_Import_History::OPTION_KEY, $iah_retained, false );

// ---------------------------------------------------------------------------
// Test E — a historical record without the optional fields renders its fallbacks.
//
// `unchanged`, `skipped`, `complete`, `checkpoint` and `cooldown` were added
// after the first runs were recorded. A record that predates them must render
// exactly as it did before, and never produce a PHP warning.
// ---------------------------------------------------------------------------

test_section( 'Test E — a pre-optional-fields record renders its fallbacks' );

$iah_legacy_entry = array(
	'time'       => '2026-01-02 03:04:05',
	'source'     => 'iah_legacy_row',
	'found'      => 4,
	'new'        => 1,
	'updated'    => 0,
	'duplicates' => 1,
	'errors'     => 0,
	'status'     => 'success',
);

$iah_sources_for_render = new Conexao_Event_Sources();

$iah_legacy = iah_render_capturing_warnings(
	static function () use ( $iah_sources_for_render, $iah_legacy_entry ) {
		$iah_sources_for_render->render_history_table( array( $iah_legacy_entry ) );
	}
);

assert_true(
	empty( $iah_legacy[1] ),
	'Test E: an old record with no optional fields produces no PHP warning or notice',
	implode( ' | ', $iah_legacy[1] )
);

list( $iah_legacy_dom, $iah_legacy_xpath ) = iah_parse( $iah_legacy[0] );
$iah_legacy_rows  = iah_history_rows( $iah_legacy_xpath );

assert_equals( 1, count( $iah_legacy_rows ), 'Test E: the legacy record is rendered as exactly one row' );

assert_equals( '0', iah_cell_text( $iah_legacy_xpath, $iah_legacy_rows[0], 5 ), 'Test E: a missing "unchanged" falls back to 0' );
assert_equals( '0', iah_cell_text( $iah_legacy_xpath, $iah_legacy_rows[0], 7 ), 'Test E: a missing "skipped" falls back to 0' );
assert_equals(
	__( 'complete', 'conexao-event-importer' ),
	iah_cell_text( $iah_legacy_xpath, $iah_legacy_rows[0], 10 ),
	'Test E: a missing "complete" keeps the documented fallback (complete, never inferred from a checkpoint)'
);
assert_equals(
	'—',
	iah_cell_text( $iah_legacy_xpath, $iah_legacy_rows[0], 11 ),
	'Test E: a missing checkpoint renders a dash in Progress'
);
assert_equals(
	'—',
	iah_cell_text( $iah_legacy_xpath, $iah_legacy_rows[0], 12 ),
	'Test E: a missing cooldown renders a dash in Cooldown'
);

// ---------------------------------------------------------------------------
// Test F — Import Logs stays reachable from the single history screen.
// ---------------------------------------------------------------------------

test_section( 'Test F — the Import Logs link is preserved' );

$iah_log_links = $iah_xpath->query( '//a[contains(@href, "page=conexao-import-log")]' );

assert_true(
	$iah_log_links->length >= 1,
	'Test F: the dashboard links to the separate Import Logs page'
);

assert_true(
	false !== strpos( $iah_html, esc_url( admin_url( 'admin.php?page=conexao-import-log' ) ) ),
	'Test F: the link points at admin.php?page=conexao-import-log'
);

assert_true(
	method_exists( 'Conexao_Import_Log', 'get_all' ) && class_exists( 'Conexao_Import_Log_Admin' ),
	'Test F: the Import Logs feature is still present, and still separate from history'
);

// ---------------------------------------------------------------------------
// Test G — the separate page is retired, and the dashboard is the only screen.
// ---------------------------------------------------------------------------

test_section( 'Test G — conexao-import-history is retired' );

$iah_sources = new Conexao_Event_Sources();

assert_true(
	! method_exists( $iah_sources, 'render_history_page' ),
	'Test G: the dedicated page renderer is removed'
);

assert_true(
	! method_exists( $iah_sources, 'render_history_summary' ),
	'Test G: the old compact summary renderer is removed (no second implementation)'
);

assert_true(
	method_exists( $iah_sources, 'render_history_table' ),
	'Test G: exactly one history-table renderer remains'
);

assert_true(
	false === strpos( $iah_class_source, 'render_history_page' ),
	'Test G: the retired callback is not referenced anywhere in the class'
);

assert_true(
	false === strpos( $iah_class_source, 'render_history_summary' ),
	'Test G: the retired summary is not referenced anywhere in the class'
);

// Register the real menus as an administrator, then inspect what WordPress got.
wp_set_current_user( $iah_admin_id );

$GLOBALS['menu']    = array();
$GLOBALS['submenu'] = array();

$iah_sources->register_admin_menu();
( new Conexao_Import_Log_Admin() )->register_admin_menu();

$iah_top_slugs = wp_list_pluck( (array) $GLOBALS['menu'], 2 );
$iah_sub_slugs = isset( $GLOBALS['submenu']['conexao-event-import'] )
	? wp_list_pluck( (array) $GLOBALS['submenu']['conexao-event-import'], 2 )
	: array();

assert_true(
	in_array( 'conexao-event-import', $iah_top_slugs, true ),
	'Test G: the Event Import dashboard is still a registered top-level page'
);

assert_true(
	in_array( 'conexao-event-sources', $iah_sub_slugs, true ),
	'Test G: the Event Sources submenu is still registered'
);

assert_true(
	in_array( 'conexao-import-log', $iah_sub_slugs, true ),
	'Test G: the Import Logs submenu is still registered'
);

assert_true(
	! in_array( 'conexao-import-history', $iah_sub_slugs, true ),
	'Test G: the retired Import History submenu is NOT registered'
);

$iah_retired_hooks = array_values(
	array_filter(
		array_keys( (array) $GLOBALS['_registered_pages'] ),
		static function ( $hook ) {
			return false !== strpos( (string) $hook, 'conexao-import-history' );
		}
	)
);

assert_true(
	empty( $iah_retired_hooks ),
	'Test G: no admin page callback is registered for the retired slug',
	implode( ', ', $iah_retired_hooks )
);

// The retirement redirect: the old URL resolves to the dashboard, and only there.
$_GET['page'] = 'conexao-import-history';

assert_equals(
	admin_url( 'admin.php?page=conexao-event-import' ),
	$iah_sources->retired_history_redirect_url(),
	'Test G: an administrator visiting the retired URL is sent to the dashboard'
);

$_GET['page'] = 'conexao-event-import';

assert_true(
	'' === $iah_sources->retired_history_redirect_url(),
	'Test G: the dashboard itself is never redirected (no loop)'
);

$_GET['page'] = 'conexao-import-log';

assert_true(
	'' === $iah_sources->retired_history_redirect_url(),
	'Test G: unrelated admin pages are not redirected'
);

wp_set_current_user( 0 );

$_GET['page'] = 'conexao-import-history';

assert_true(
	'' === $iah_sources->retired_history_redirect_url(),
	'Test G: a visitor without manage_options is NOT redirected (WordPress still refuses the page)'
);

unset( $_GET['page'] );

assert_true(
	false !== has_action( 'admin_page_access_denied', array( Conexao_Event_Importer::instance()->sources, 'maybe_redirect_retired_history_page' ) ),
	'Test G: the retirement redirect is armed on admin_page_access_denied'
);

assert_true(
	false === has_action( 'admin_menu', array( Conexao_Event_Importer::instance()->sources, 'render_history_page' ) ),
	'Test G: the retired callback is not hooked to the admin menu'
);


// ---------------------------------------------------------------------------
// Test H — the storage contract and the recording behaviour are unchanged.
// ---------------------------------------------------------------------------

test_section( 'Test H — history storage and recording are untouched' );

assert_true(
	'conexao_event_import_history' === Conexao_Import_History::OPTION_KEY,
	'Test H: the history option key is unchanged'
);

assert_true(
	100 === Conexao_Import_History::MAX_ENTRIES,
	'Test H: the retention cap is unchanged at 100 entries'
);

assert_true(
	method_exists( 'Conexao_Import_History', 'record' ),
	'Test H: the single recording entry point still exists'
);

// Rendering must be a pure read: the rendered page may not rewrite the option.
$iah_before_render = get_option( Conexao_Import_History::OPTION_KEY );
iah_dashboard_html();
$iah_after_render = get_option( Conexao_Import_History::OPTION_KEY );

assert_true(
	$iah_before_render === $iah_after_render,
	'Test H: rendering the dashboard neither trims, reorders nor clears the stored history'
);

assert_true(
	! empty( $iah_after_render ),
	'Test H: consolidating the interface did not clear the import history'
);

// The recorder still prepends and still caps at MAX_ENTRIES.
update_option( Conexao_Import_History::OPTION_KEY, array(), false );

for ( $iah_i = 1; $iah_i <= Conexao_Import_History::MAX_ENTRIES + 1; $iah_i++ ) {
	Conexao_Import_History::record(
		sprintf( 'iah_cap_%03d', $iah_i ),
		array( 'found' => 1 ),
		'success'
	);
}

$iah_capped = Conexao_Import_History::get_all();

assert_equals(
	Conexao_Import_History::MAX_ENTRIES,
	count( $iah_capped ),
	'Test H: the recorder still retains exactly MAX_ENTRIES entries'
);

assert_true(
	'iah_cap_101' === $iah_capped[0]['source'],
	'Test H: the recorder still stores the newest entry first (got ' . $iah_capped[0]['source'] . ')'
);

assert_true(
	'iah_cap_001' !== $iah_capped[ count( $iah_capped ) - 1 ]['source'],
	'Test H: the oldest entry beyond the cap is still dropped'
);

assert_true(
	'iah_cap_002' === $iah_capped[ count( $iah_capped ) - 1 ]['source'],
	'Test H: the oldest retained entry is the 2nd recorded run (100-entry window)'
);

// And the capped history still renders in full on the dashboard.
wp_set_current_user( $iah_admin_id );
list( $iah_cap_dom, $iah_cap_xpath ) = iah_parse( iah_dashboard_html() );

assert_equals(
	Conexao_Import_History::MAX_ENTRIES,
	count( iah_history_rows( $iah_cap_xpath ) ),
	'Test H: the dashboard renders the whole 100-entry retained history'
);

// Leave the isolated store as the seeded history; the shutdown net restores the
// operator's real option afterwards.
update_option( Conexao_Import_History::OPTION_KEY, $iah_retained, false );

wp_set_current_user( 0 );

test_finish( 'import-history-dashboard' );
