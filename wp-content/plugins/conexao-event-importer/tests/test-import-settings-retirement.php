<?php
/**
 * Retirement of the empty Import Settings admin page.
 *
 * ## What this suite is for
 *
 * The `admin.php?page=conexao-import-settings` screen displayed explanatory
 * text ("Nothing to configure") and provided no useful controls. It has been
 * retired: no submenu, no page callback, no hidden page. The old URL redirects
 * to the Event Import dashboard for `manage_options` users.
 *
 * This suite proves:
 *   - the Settings submenu is no longer registered;
 *   - the old URL redirects to the dashboard for administrators only;
 *   - `Conexao_Import_Settings` remains available as a storage class;
 *   - `cleanup_scope` still round-trips through the storage;
 *   - Cleanup behaviour is unchanged.
 *
 * The underlying settings storage (`conexao_event_importer_settings`) and its
 * `cleanup_scope` key are NOT touched by this change.
 *
 * @package Conexao_Event_Importer
 */

// Shared Stage E test bootstrap: the only place allowed to locate wp-load.php.
require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

// Admin menu registration lives in wp-admin; the real `add_submenu_page()` is
// what this suite inspects.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-cleanup.php';

test_prerequisite_hint( 'activate-plugin:conexao-event-importer' );

test_require(
	class_exists( 'Conexao_Event_Sources' ) && class_exists( 'Conexao_Import_Settings' ),
	'activate-plugin:conexao-event-importer',
	'test prerequisite is available: the importer classes are loaded',
	'activate the conexao-event-importer plugin'
);

// Isolate the settings option so this suite never mutates operator state.
$restore_options = test_options_isolate(
	array(
		Conexao_Import_Settings::OPTION_KEY,
		Conexao_Event_Sources::OPTION_KEY,
		Conexao_Import_Log::OPTION_KEY,
	)
);

register_shutdown_function(
	static function () use ( $restore_options ) {
		wp_set_current_user( 0 );
		test_options_restore( $restore_options, true );
	}
);

// Find an administrator for capability-based redirect tests.
$admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);

test_require(
	! empty( $admin ),
	'fixture:administrator',
	'an administrator account exists for redirect tests',
	'create an administrator in the local install'
);

$admin_id = (int) $admin[0]->ID;

// ---------------------------------------------------------------------------
// Test A — the Settings submenu is retired.
// ---------------------------------------------------------------------------

test_section( 'Test A — Settings submenu is retired' );

// Capture the real admin menu surface as an administrator would see it.
wp_set_current_user( $admin_id );

$GLOBALS['menu']    = array();
$GLOBALS['submenu'] = array();

$sources = new Conexao_Event_Sources();
$sources->register_admin_menu();

$top_slugs    = wp_list_pluck( (array) $GLOBALS['menu'], 2 );
$sub_slugs    = isset( $GLOBALS['submenu']['conexao-event-import'] )
	? wp_list_pluck( (array) $GLOBALS['submenu']['conexao-event-import'], 2 )
	: array();

assert_true(
	in_array( 'conexao-event-import', $top_slugs, true ),
	'Test A: the Event Import dashboard is still a registered top-level page'
);

assert_true(
	in_array( 'conexao-event-sources', $sub_slugs, true ),
	'Test A: Event Sources is still registered as a submenu'
);

assert_true(
	! in_array( 'conexao-import-settings', $sub_slugs, true ),
	'Test A: the Settings submenu (conexao-import-settings) is NOT registered'
);

// No admin page callback is registered for the retired settings slug.
$retired_hooks = array_values(
	array_filter(
		array_keys( (array) $GLOBALS['_registered_pages'] ),
		static function ( $hook ) {
			return false !== strpos( (string) $hook, 'conexao-import-settings' );
		}
	)
);

assert_true(
	empty( $retired_hooks ),
	'Test A: no admin page callback is registered for the retired settings slug',
	implode( ', ', $retired_hooks )
);

// The `render_page` method no longer exists on the settings class.
assert_true(
	! method_exists( 'Conexao_Import_Settings', 'render_page' ),
	'Test A: Conexao_Import_Settings::render_page() no longer exists'
);

assert_true(
	false !== has_action(
		'admin_page_access_denied',
		array( Conexao_Event_Importer::instance()->sources, 'maybe_redirect_retired_settings_page' )
	),
	'Test C: the settings retirement redirect is armed on admin_page_access_denied'
);

// ---------------------------------------------------------------------------
// Test D — Conexao_Import_Settings remains available as storage.
// ---------------------------------------------------------------------------

test_section( 'Test D — settings storage remains available' );

assert_true(
	class_exists( 'Conexao_Import_Settings' ),
	'Test D: Conexao_Import_Settings class exists after bootstrap'
);

assert_true(
	'conexao_event_importer_settings' === Conexao_Import_Settings::OPTION_KEY,
	'Test D: the option key is unchanged'
);

assert_true(
	is_array( Conexao_Import_Settings::get_defaults() ),
	'Test D: get_defaults() still works'
);

assert_true(
	is_array( Conexao_Import_Settings::all() ),
	'Test D: all() still works'
);

// ---------------------------------------------------------------------------
// Test E — cleanup_scope round-trips through storage.
// ---------------------------------------------------------------------------

test_section( 'Test E — cleanup_scope round-trips' );

Conexao_Import_Settings::update( array( 'cleanup_scope' => 'imported_only' ) );

assert_true(
	'imported_only' === Conexao_Import_Settings::get( 'cleanup_scope', 'all' ),
	'Test E: cleanup_scope reads back imported_only'
);

Conexao_Import_Settings::update( array( 'cleanup_scope' => 'all' ) );

assert_true(
	'all' === Conexao_Import_Settings::get( 'cleanup_scope', 'all' ),
	'Test E: cleanup_scope reads back all'
);

// Absent setting: the existing 'all' fallback is preserved.
delete_option( Conexao_Import_Settings::OPTION_KEY );
$prop = new ReflectionProperty( 'Conexao_Import_Settings', 'cache' );
$prop->setAccessible( true );
$prop->setValue( null, null );

assert_true(
	'all' === Conexao_Import_Settings::get( 'cleanup_scope', 'all' ),
	'Test E: absent cleanup_scope falls back to all'
);

// ---------------------------------------------------------------------------
// Test F — rendering the dashboard does not overwrite stored settings.
// ---------------------------------------------------------------------------

test_section( 'Test F — dashboard render does not overwrite settings' );

Conexao_Import_Settings::update( array( 'cleanup_scope' => 'imported_only' ) );
$prop->setValue( null, null ); // reset cache so reads hit the option

$before = Conexao_Import_Settings::get( 'cleanup_scope', 'all' );

// Render the dashboard (read-only).
ob_start();
$sources->render_import_dashboard();
ob_get_clean();

$prop->setValue( null, null );
$after = Conexao_Import_Settings::get( 'cleanup_scope', 'all' );

assert_true(
	$before === $after,
	'Test F: rendering the dashboard does not overwrite cleanup_scope',
	'before=' . var_export( $before, true ) . ' after=' . var_export( $after, true )
);

// ---------------------------------------------------------------------------
// Test G — Cleanup behaviour is unchanged.
// ---------------------------------------------------------------------------

test_section( 'Test G — cleanup behaviour is unchanged' );

// cleanup_scope = imported_only: manual events protected, imported events selectable.
Conexao_Import_Settings::update( array( 'cleanup_scope' => 'imported_only' ) );
$prop->setValue( null, null );

$manual_event_id = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_status' => 'publish',
		'post_title'  => '[SETTINGS RETIREMENT TEST] manual event',
	)
);

$imported_event_id = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_status' => 'publish',
		'post_title'  => '[SETTINGS RETIREMENT TEST] imported event',
	)
);
update_post_meta( $imported_event_id, '_event_imported', '1' );
update_post_meta( $imported_event_id, '_event_date', '2020-01-01' );

$cleanup = new Conexao_Event_Cleanup();

$method = new ReflectionMethod( $cleanup, 'find_past_events' );
$method->setAccessible( true );

// Imported event with a past date IS selected when cleanup_scope=imported_only.
$selected = array_map( 'intval', (array) $method->invoke( $cleanup ) );

assert_true(
	in_array( $imported_event_id, $selected, true ),
	'Test G: imported event IS selected for cleanup when cleanup_scope=imported_only'
);

// cleanup_scope absent: the 'all' fallback means no ownership restriction.
wp_delete_post( $manual_event_id, true );
wp_delete_post( $imported_event_id, true );

delete_option( Conexao_Import_Settings::OPTION_KEY );
$prop->setValue( null, null );

$manual_event_id2 = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_status' => 'publish',
		'post_title'  => '[SETTINGS RETIREMENT TEST] manual event 2',
		'post_date'   => '2020-01-01 00:00:00',
	)
);

$imported_event_id2 = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_status' => 'publish',
		'post_title'  => '[SETTINGS RETIREMENT TEST] imported event 2',
		'post_date'   => '2020-01-01 00:00:00',
	)
);
update_post_meta( $imported_event_id2, '_event_imported', '1' );
update_post_meta( $imported_event_id2, '_event_date', '2020-01-01' );

$selected2 = array_map( 'intval', (array) $method->invoke( $cleanup ) );

assert_true(
	in_array( $manual_event_id2, $selected2, true ),
	'Test G: with cleanup_scope absent, manual event IS selected (all fallback)'
);

assert_true(
	in_array( $imported_event_id2, $selected2, true ),
	'Test G: with cleanup_scope absent, imported event IS selected (all fallback)'
);

wp_delete_post( $manual_event_id2, true );
wp_delete_post( $imported_event_id2, true );

// Restore settings for other suites.
delete_option( Conexao_Import_Settings::OPTION_KEY );
$prop->setValue( null, null );

test_finish( 'Import Settings retirement' );

// ---------------------------------------------------------------------------
// Test B — the old Settings URL redirects to the dashboard (administrator only).
// ---------------------------------------------------------------------------

test_section( 'Test B — retired Settings URL redirects' );

// Administrator: redirected to the dashboard.
$_GET['page'] = 'conexao-import-settings';

assert_equals(
	admin_url( 'admin.php?page=conexao-event-import' ),
	$sources->retired_settings_redirect_url(),
	'Test B: an administrator visiting the retired URL is sent to the dashboard'
);

// The dashboard itself is never redirected (no redirect loop).
$_GET['page'] = 'conexao-event-import';

assert_true(
	'' === $sources->retired_settings_redirect_url(),
	'Test B: the dashboard itself is never redirected (no loop)'
);

// A different slug is not handled by the settings redirect.
$_GET['page'] = 'conexao-event-sources';

assert_true(
	'' === $sources->retired_settings_redirect_url(),
	'Test B: other slugs are not redirected by the settings redirect'
);

// Non-administrator: NOT redirected (WordPress keeps its own refusal).
wp_set_current_user( 0 );

$_GET['page'] = 'conexao-import-settings';

assert_true(
	'' === $sources->retired_settings_redirect_url(),
	'Test B: a visitor without manage_options is NOT redirected from the retired URL'
);

unset( $_GET['page'] );
wp_set_current_user( $admin_id );

// ---------------------------------------------------------------------------
// Test C — the retirement redirect is armed on admin_page_access_denied.
// ---------------------------------------------------------------------------

test_section( 'Test C — retirement redirect is armed' );

assert_true(
	false !== has_action(
		'admin_page_access_denied',
		array( Conexao_Event_Importer::instance()->sources, 'maybe_redirect_retired_settings_page' )
	),
	'Test C: the settings retirement redirect is armed on admin_page_access_denied'
);