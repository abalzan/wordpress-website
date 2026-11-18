<?php
/**
 * Needs Review feature retirement verification.
 *
 * ## What this locks down
 *
 * The owner has retired the Needs Review feature (§6 of the retirement task).
 * This suite proves the queue is no longer registered, loaded, or reachable
 * through the admin, while the Event Import menu and Event Sources page
 * remain intact.
 *
 *   A. `Conexao_Event_Review_Queue` class is NOT defined / not loaded.
 *   B. `conexao-event-needs-review` submenu is NOT registered under Event
 *      Import.
 *   C. Event Sources submenu (`conexao-event-sources`) IS still registered
 *      under Event Import; the Event Import top-level menu IS present.
 *   D. `Conexao_Event_Sources::render_needs_review_page()` and
 *      `render_needs_review_drilldown()` no longer exist.
 *   E. No `admin_menu` hook callback renders the Needs Review page.
 *
 * Read-only: creates no posts, options, or menu entries of its own beyond the
 * temporary registration used to inspect the submenu tree.
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
// A. Queue class must not be loaded (require_once removed; class file deleted).
// ---------------------------------------------------------------------------
test_section( 'A: needs-review queue class not loaded' );
assert_true( ! class_exists( 'Conexao_Event_Review_Queue', false ), 'Conexao_Event_Review_Queue is not defined' );
assert_true( ! class_exists( 'Conexao_Event_Review_Queue', true ), 'Conexao_Event_Review_Queue not autoloadable' );

// ---------------------------------------------------------------------------
// B. Menu: Needs Review absent; Event Sources and Event Import present.
// ---------------------------------------------------------------------------
test_section( 'B: admin menu tree' );

// Find an administrator for capability-based menu registration.
$admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
test_require(
	! empty( $admin ),
	'fixture:administrator',
	'an administrator account exists for menu registration',
	'create an administrator in the local install'
);
$admin_id = (int) $admin[0]->ID;
wp_set_current_user( $admin_id );

$GLOBALS['menu']    = array();
$GLOBALS['submenu'] = array();

$sources = new Conexao_Event_Sources();
$sources->register_admin_menu();

$sub_slugs = isset( $GLOBALS['submenu']['conexao-event-import'] )
	? wp_list_pluck( (array) $GLOBALS['submenu']['conexao-event-import'], 2 )
	: array();

assert_true( ! in_array( 'conexao-event-needs-review', $sub_slugs, true ), 'needs-review submenu is NOT registered under Event Import' );
assert_true( in_array( 'conexao-event-sources', $sub_slugs, true ), 'Event Sources submenu still registered under Event Import' );

$top_slugs = wp_list_pluck( (array) $GLOBALS['menu'], 2 );
assert_true( in_array( 'conexao-event-import', $top_slugs, true ), 'Event Import top-level menu still present' );
assert_true( ! in_array( 'conexao-event-needs-review', $top_slugs, true ), 'needs-review is not a top-level menu' );

// ---------------------------------------------------------------------------
// C. Queue renderers removed from Conexao_Event_Sources.
// ---------------------------------------------------------------------------
test_section( 'C: queue renderers removed' );
assert_true( ! method_exists( 'Conexao_Event_Sources', 'render_needs_review_page' ), 'render_needs_review_page() no longer exists' );
assert_true( ! method_exists( 'Conexao_Event_Sources', 'render_needs_review_drilldown' ), 'render_needs_review_drilldown() no longer exists' );

// ---------------------------------------------------------------------------
// D. No admin_menu callback renders the Needs Review page.
// ---------------------------------------------------------------------------
test_section( 'D: no Needs Review admin_menu callback' );
$hooks = array();
foreach ( (array) $GLOBALS['wp_filter']['admin_menu'] ?? array() as $priority => $callbacks ) {
	foreach ( (array) $callbacks as $callback ) {
		if ( is_array( $callback ) && isset( $callback['function'] ) ) {
			$hooks[] = $callback['function'];
		}
	}
}
$needs_review_callback = false;
foreach ( $hooks as $hook ) {
	if ( is_array( $hook ) && isset( $hook[0] ) && is_object( $hook[0] ) && $hook[0] instanceof Conexao_Event_Sources ) {
		if ( in_array( $hook[1], array( 'render_needs_review_page', 'render_needs_review_drilldown' ), true ) ) {
			$needs_review_callback = true;
		}
	}
}
assert_true( ! $needs_review_callback, 'no admin_menu callback renders the Needs Review page' );

wp_set_current_user( 0 );

test_finish( 'needs-review-retirement' );
exit( $failed > 0 ? 1 : 0 );
