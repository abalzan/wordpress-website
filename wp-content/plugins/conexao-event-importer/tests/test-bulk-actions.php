<?php
/**
 * Regression suite — bulk actions on Event Sources (enable / disable / delete).
 *
 * ## Why this suite tests the CORE instead of the redirecting handler
 *
 * `handle_bulk_action()` ends with `wp_safe_redirect(); exit;`. Invoking it inside
 * a suite process terminates the run at the first call and silently skips every
 * later assertion: an earlier version of this suite did exactly that, printed one
 * section heading and still exited 0. The registry work therefore lives in
 * `apply_bulk_action()`, which the handler runs unchanged, and the suite drives
 * THAT. The handler itself — capability, nonce, redirect — is proven end-to-end
 * in a CHILD PHP process, which may die without taking the suite with it.
 *
 * ## What this suite proves
 *
 * 1. `apply_bulk_action()` whitelists `enable`, `disable` and `delete`.
 * 2. `enable` sets `active`; `disable` sets `inactive`; neither ever TOGGLES.
 * 3. Sources already in the requested state are reported as skipped and are
 *    left untouched (idempotent).
 * 4. A mixed selection reports updated and skipped separately.
 * 5. Duplicate submitted IDs are de-duplicated before processing.
 * 6. Unknown IDs are rejected and reported separately; non-scalar and empty IDs
 *    are ignored.
 * 7. An empty selection is an error, never "every source".
 * 8. `delete` removes source configurations and never imported events.
 * 9. The redirect notice reports successes, already-correct statuses and
 *    failures separately, and turns into an error when anything was ignored.
 * 10. The real handler refuses an unauthenticated request and a request without
 *     a valid nonce, and does not return in either case.
 * 11. The real handler, for an authenticated administrator with a valid nonce,
 *     performs the action and then redirects (it does not return).
 *
 * The suite replaces the stored sources option for the duration of each case and
 * restores it afterwards, so it is safe against a populated install.
 *
 * @package Conexao_Event_Importer
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

test_prerequisite_hint( 'activate-plugin:conexao-event-importer' );
test_require(
	class_exists( 'Conexao_Event_Sources' ),
	'activate-plugin:conexao-event-importer',
	'test prerequisite is available: class_exists( Conexao_Event_Sources )',
	'activate the conexao-event-importer plugin'
);

echo "\n== Bulk actions on Event Sources ==\n";

$sources_mgr = new Conexao_Event_Sources();

/**
 * Replace the stored registry, returning the previous value.
 *
 * @param array $map Replacement registry.
 * @return array
 */
function bulk_set_sources( array $map ) {
	$original = get_option( Conexao_Event_Sources::OPTION_KEY, array() );
	update_option( Conexao_Event_Sources::OPTION_KEY, $map, false );
	return $original;
}

/**
 * Restore the registry captured by bulk_set_sources().
 *
 * @param array $original Previous value.
 * @return void
 */
function bulk_restore_sources( $original ) {
	update_option( Conexao_Event_Sources::OPTION_KEY, $original, false );
}

/**
 * Build a minimal registry with the given statuses.
 *
 * @param array $statuses Map of source_id => status.
 * @return array
 */
function bulk_registry( array $statuses ) {
	$registry = array();
	foreach ( $statuses as $id => $status ) {
		$registry[ $id ] = array(
			'id'                 => $id,
			'name'               => 'Source ' . $id,
			'url'                => 'https://example.com/' . $id,
			'type'               => 'website',
			'status'             => $status,
			'last_import'        => '',
			'last_import_status' => '',
			'events_imported'    => 0,
			'last_error'         => '',
			'county'             => '',
		);
	}
	return $registry;
}

/**
 * Invoke a protected method on a fresh sources manager.
 *
 * @param string $method Method name.
 * @param array  $args   Arguments.
 * @return mixed
 */
function bulk_call( $method, array $args ) {
	$reflect = new ReflectionClass( 'Conexao_Event_Sources' );
	$call    = $reflect->getMethod( $method );
	$call->setAccessible( true );
	return $call->invokeArgs( new Conexao_Event_Sources(), $args );
}

/**
 * Apply a bulk action through the same core the handler uses.
 *
 * @param string $action Bulk action.
 * @param mixed  $ids    Submitted IDs.
 * @return array
 */
function bulk_apply( $action, $ids ) {
	return bulk_call( 'apply_bulk_action', array( $action, $ids ) );
}

/**
 * Build the redirect notice for a result.
 *
 * @param array $result Result from bulk_apply().
 * @return array
 */
function bulk_notice( array $result ) {
	return bulk_call( 'build_bulk_notice', array( $result ) );
}

/**
 * Read one source's status, or null when it is absent.
 *
 * @param string $id Source ID.
 * @return string|null
 */
function bulk_status( $id ) {
	$source = ( new Conexao_Event_Sources() )->get( $id );
	return $source ? $source['status'] : null;
}

/**
 * First administrator ID, or 0 when the install has no administrator.
 *
 * @return int
 */
function bulk_admin_id() {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	return empty( $admins ) ? 0 : (int) $admins[0];
}

/**
 * Drop this process's cached copy of the sources option.
 *
 * A child process writes to the database, so the parent's in-memory options
 * cache is stale until it is purged.
 *
 * @return void
 */
function bulk_purge_option_cache() {
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( Conexao_Event_Sources::OPTION_KEY, 'options' );
}

/**
 * Global counter for registry writes to `conexao_event_sources`.
 *
 * Used by Tests A-D to assert that a bulk operation performs exactly one
 * `update_option()` call, not one per selected source.
 *
 * @var int
 */
$GLOBALS['conexao_bulk_update_count'] = 0;

/**
 * Reset the registry-write counter.
 *
 * @return void
 */
function bulk_reset_write_count() {
	$GLOBALS['conexao_bulk_update_count'] = 0;
}

/**
 * Read the registry-write counter.
 *
 * @return int
 */
function bulk_write_count() {
	return (int) $GLOBALS['conexao_bulk_update_count'];
}

/**
 * Instrument `update_option()` for `conexao_event_sources` so tests can count
 * registry writes. Hooks the `pre_update_option_*` filter, which fires on
 * every `update_option()` call for that key — including calls that are
 * short-circuited by a failure simulation — so the count reflects the number
 * of `update_option()` attempts, not just successful writes.
 *
 * @return void
 */
function bulk_instrument_option_writes() {
	add_filter(
		'pre_update_option_' . Conexao_Event_Sources::OPTION_KEY,
		function ( $value ) {
			$GLOBALS['conexao_bulk_update_count']++;
			return $value;
		}
	);
}

/**
 * Stop instrumenting `update_option()` for `conexao_event_sources`.
 *
 * @return void
 */
function bulk_uninstrument_option_writes() {
	remove_all_filters( 'pre_update_option_' . Conexao_Event_Sources::OPTION_KEY );
}

/**
 * Normalise the registry and reset the write counter so that lazy seeding or
 * migration writes performed by get_all() do not distort the bulk-operation
 * write count.
 *
 * @return array The normalised registry.
 */
function bulk_normalise_and_reset() {
	$sources_mgr = new Conexao_Event_Sources();
	$sources_mgr->get_all();
	bulk_reset_write_count();
	return $sources_mgr;
}

/**
 * Run the REAL handle_bulk_action() in a child PHP process.
 *
 * The handler terminates its process, so the only honest way to assert on it is
 * to give it a process of its own. The child prints HANDLER-RETURNED when the
 * handler comes back normally; that marker must NEVER appear.
 *
 * @param int    $user_id    Current user for the request (0 = logged out).
 * @param string $action     Bulk action to submit.
 * @param array  $ids        Source IDs to submit.
 * @param string $nonce_mode 'valid' | 'missing'.
 * @return array { output: string, code: int }
 */
function bulk_run_handler( $user_id, $action, array $ids, $nonce_mode ) {
	$bootstrap = dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
	$plugin    = WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';
	$post      = array(
		'conexao_source_action' => 'bulk_action',
		'bulk_action_type'      => $action,
		'bulk_source_ids'       => $ids,
	);

	$code  = "<?php\n";
	$code .= 'require_once ' . var_export( $bootstrap, true ) . ";\n";
	$code .= 'require_once ' . var_export( $plugin, true ) . ";\n";
	$code .= 'wp_set_current_user( ' . (int) $user_id . " );\n";
	$code .= '$_POST = ' . var_export( $post, true ) . ";\n";
	$code .= "if ( 'valid' === " . var_export( $nonce_mode, true ) . " ) {\n";
	$code .= "\t\$_POST['conexao_event_sources_nonce'] = wp_create_nonce( 'conexao_event_sources' );\n";
	$code .= "}\n";
	$code .= "\$_REQUEST = \$_POST;\n";
	$code .= '$reflect = new ReflectionClass( "Conexao_Event_Sources" );' . "\n";
	$code .= '$method  = $reflect->getMethod( "handle_bulk_action" );' . "\n";
	$code .= '$method->setAccessible( true );' . "\n";
	$code .= '$method->invoke( new Conexao_Event_Sources() );' . "\n";
	$code .= "echo \"HANDLER-RETURNED\\n\";\n";

	$file = tempnam( sys_get_temp_dir(), 'conexao-bulk-handler-' );
	file_put_contents( $file, $code );

	$output = array();
	$status = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );
	unlink( $file );

	bulk_purge_option_cache();

	return array(
		'output' => implode( "\n", $output ),
		'code'   => $status,
	);
}

// ---------------------------------------------------------------------------
// 1. Invalid action is rejected and nothing changes.
// ---------------------------------------------------------------------------
test_section( 'Invalid bulk action is rejected' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'inactive' ) ) );
$result   = bulk_apply( 'purge', array( 'src_a' ) );

assert_true( 'invalid_action' === $result['error'], 'error is invalid_action' );
assert_true( 'inactive' === bulk_status( 'src_a' ), 'src_a untouched by an invalid action' );
assert_true( array() === $result['updated'], 'nothing reported updated' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 2. Invalid action types are rejected too (array, null, empty string).
// ---------------------------------------------------------------------------
test_section( 'Non-string and empty actions are rejected' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'inactive' ) ) );

assert_true( 'invalid_action' === bulk_apply( array( 'enable' ), array( 'src_a' ) )['error'], 'array action rejected' );
assert_true( 'invalid_action' === bulk_apply( null, array( 'src_a' ) )['error'], 'null action rejected' );
assert_true( 'invalid_action' === bulk_apply( '', array( 'src_a' ) )['error'], 'empty action rejected' );
assert_true( 'inactive' === bulk_status( 'src_a' ), 'src_a untouched by invalid actions' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 3. Empty selection is an error and never means "every source".
// ---------------------------------------------------------------------------
test_section( 'Empty selection never affects every source' );

$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'inactive',
	'src_b' => 'inactive',
) ) );

$result = bulk_apply( 'enable', array() );
assert_true( 'empty_selection' === $result['error'], 'empty array reports empty_selection' );
assert_true( 0 === $result['requested'], 'no IDs were requested' );
assert_true( 'inactive' === bulk_status( 'src_a' ), 'src_a stays inactive' );
assert_true( 'inactive' === bulk_status( 'src_b' ), 'src_b stays inactive' );

assert_true( 'empty_selection' === bulk_apply( 'enable', null )['error'], 'null selection reports empty_selection' );
assert_true( 'empty_selection' === bulk_apply( 'enable', array( '', '   ', array( 'nested' ) ) )['error'], 'blank and nested IDs are ignored' );
assert_true( 'inactive' === bulk_status( 'src_b' ), 'src_b still inactive after junk selection' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 4. Unknown IDs are reported separately; valid IDs are still processed.
// ---------------------------------------------------------------------------
test_section( 'Invalid source IDs are reported separately' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'inactive' ) ) );
$result   = bulk_apply( 'enable', array( 'src_a', 'bogus_id', 'another_bogus' ) );

assert_true( 'active' === bulk_status( 'src_a' ), 'valid src_a enabled' );
assert_equals( array( 'src_a' ), $result['updated'], 'only src_a reported updated' );
assert_equals( array( 'bogus_id', 'another_bogus' ), $result['invalid'], 'both unknown IDs reported invalid' );
assert_true( '' === $result['error'], 'a partial success is not an error state' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 5. All IDs unknown is an error, and nothing is written.
// ---------------------------------------------------------------------------
test_section( 'A selection of only unknown IDs is an error' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'inactive' ) ) );
$result   = bulk_apply( 'enable', array( 'bogus_one', 'bogus_two' ) );

assert_true( 'no_valid_sources' === $result['error'], 'error is no_valid_sources' );
assert_equals( array( 'bogus_one', 'bogus_two' ), $result['invalid'], 'unknown IDs listed' );
assert_true( 'inactive' === bulk_status( 'src_a' ), 'src_a untouched' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 6. Enable one inactive source.
// ---------------------------------------------------------------------------
test_section( 'Enable one inactive source' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'inactive' ) ) );
$result   = bulk_apply( 'enable', array( 'src_a' ) );

assert_true( 'active' === bulk_status( 'src_a' ), 'src_a is now active' );
assert_equals( array( 'src_a' ), $result['updated'], 'src_a reported updated' );
assert_true( 0 === count( $result['skipped'] ), 'nothing skipped' );
assert_true( 0 === count( $result['failed'] ), 'nothing failed' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 7. Enable multiple inactive sources.
// ---------------------------------------------------------------------------
test_section( 'Enable multiple inactive sources' );

$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'inactive',
	'src_b' => 'inactive',
	'src_c' => 'inactive',
) ) );
$result   = bulk_apply( 'enable', array( 'src_a', 'src_b', 'src_c' ) );

assert_true( 'active' === bulk_status( 'src_a' ), 'src_a enabled' );
assert_true( 'active' === bulk_status( 'src_b' ), 'src_b enabled' );
assert_true( 'active' === bulk_status( 'src_c' ), 'src_c enabled' );
assert_true( 3 === count( $result['updated'] ), 'three sources reported updated' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 8. Enabling an already-active source skips it and never disables it.
// ---------------------------------------------------------------------------
test_section( 'Enable already-active sources are skipped, never toggled' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'active' ) ) );
$result   = bulk_apply( 'enable', array( 'src_a' ) );

assert_true( 'active' === bulk_status( 'src_a' ), 'src_a stays active' );
assert_equals( array( 'src_a' ), $result['skipped'], 'src_a reported skipped' );
assert_true( array() === $result['updated'], 'nothing reported updated' );
assert_true( array() === $result['failed'], 'nothing reported failed' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 9. Enable a mixed selection: updated and skipped are reported separately.
// ---------------------------------------------------------------------------
test_section( 'Enable a mixed selection' );

$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'active',
	'src_b' => 'inactive',
	'src_c' => 'inactive',
) ) );
$result   = bulk_apply( 'enable', array( 'src_a', 'src_b', 'src_c' ) );

assert_true( 'active' === bulk_status( 'src_a' ), 'src_a stays active (skipped, not toggled)' );
assert_true( 'active' === bulk_status( 'src_b' ), 'src_b enabled' );
assert_true( 'active' === bulk_status( 'src_c' ), 'src_c enabled' );
assert_equals( array( 'src_a' ), $result['skipped'], 'src_a reported skipped' );
assert_equals( array( 'src_b', 'src_c' ), $result['updated'], 'src_b and src_c reported updated' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 10. Disable one and several sources.
// ---------------------------------------------------------------------------
test_section( 'Disable one and several sources' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'active' ) ) );
$result   = bulk_apply( 'disable', array( 'src_a' ) );
assert_true( 'inactive' === bulk_status( 'src_a' ), 'one source disabled' );
assert_equals( array( 'src_a' ), $result['updated'], 'one source reported updated' );
bulk_restore_sources( $original );

$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'active',
	'src_b' => 'active',
	'src_c' => 'active',
) ) );
$result   = bulk_apply( 'disable', array( 'src_a', 'src_b', 'src_c' ) );
assert_true( 'inactive' === bulk_status( 'src_a' ) && 'inactive' === bulk_status( 'src_b' ) && 'inactive' === bulk_status( 'src_c' ), 'three sources disabled' );
assert_true( 3 === count( $result['updated'] ), 'three sources reported updated' );
bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 11. Disabling an already-inactive source skips it and never enables it.
// ---------------------------------------------------------------------------
test_section( 'Disable already-inactive sources are skipped, never toggled' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'inactive' ) ) );
$result   = bulk_apply( 'disable', array( 'src_a' ) );

assert_true( 'inactive' === bulk_status( 'src_a' ), 'src_a stays inactive' );
assert_equals( array( 'src_a' ), $result['skipped'], 'src_a reported skipped' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 12. Disable a mixed selection.
// ---------------------------------------------------------------------------
test_section( 'Disable a mixed selection' );

$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'active',
	'src_b' => 'inactive',
	'src_c' => 'active',
) ) );
$result   = bulk_apply( 'disable', array( 'src_a', 'src_b', 'src_c' ) );

assert_true( 'inactive' === bulk_status( 'src_a' ), 'src_a disabled' );
assert_true( 'inactive' === bulk_status( 'src_b' ), 'src_b stays inactive' );
assert_true( 'inactive' === bulk_status( 'src_c' ), 'src_c disabled' );
assert_equals( array( 'src_b' ), $result['skipped'], 'src_b reported skipped' );
assert_equals( array( 'src_a', 'src_c' ), $result['updated'], 'src_a and src_c reported updated' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 13. Idempotency: repeating the same action changes nothing the second time.
// ---------------------------------------------------------------------------
test_section( 'Enable and disable are idempotent' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'inactive', 'src_b' => 'active' ) ) );

$first  = bulk_apply( 'enable', array( 'src_a', 'src_b' ) );
$second = bulk_apply( 'enable', array( 'src_a', 'src_b' ) );
assert_true( 1 === count( $first['updated'] ) && 1 === count( $first['skipped'] ), 'first enable: one updated, one skipped' );
assert_true( 0 === count( $second['updated'] ), 'second enable updates nothing' );
assert_true( 2 === count( $second['skipped'] ), 'second enable skips both' );

$first  = bulk_apply( 'disable', array( 'src_a', 'src_b' ) );
$second = bulk_apply( 'disable', array( 'src_a', 'src_b' ) );
assert_true( 2 === count( $first['updated'] ), 'first disable disables both' );
assert_true( 0 === count( $second['updated'] ), 'second disable updates nothing' );
assert_true( 2 === count( $second['skipped'] ), 'second disable skips both' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 14. Duplicate submitted IDs are processed once.
// ---------------------------------------------------------------------------
test_section( 'Duplicate submitted IDs are processed once' );

$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'inactive',
	'src_b' => 'inactive',
) ) );
$result   = bulk_apply( 'enable', array( 'src_a', 'src_a', 'src_b', 'src_a' ) );

assert_true( 4 === $result['requested'], 'four IDs were submitted' );
assert_true( 2 === $result['duplicates'], 'two duplicates were removed' );
assert_true( 2 === count( $result['updated'] ), 'two distinct sources were enabled' );
assert_true( 0 === count( $result['failed'] ), 'duplicates are not failures' );
assert_true( 'active' === bulk_status( 'src_a' ) && 'active' === bulk_status( 'src_b' ), 'both sources active' );

bulk_restore_sources( $original );

// A duplicated DELETE must not report the second pass as a failure.
test_section( 'Duplicate IDs on delete do not report a false failure' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'active' ) ) );
$result   = bulk_apply( 'delete', array( 'src_a', 'src_a' ) );

assert_true( 1 === count( $result['updated'] ), 'src_a deleted once' );
assert_true( 0 === count( $result['failed'] ), 'no false failure was reported' );
assert_true( null === $sources_mgr->get( 'src_a' ), 'src_a removed from the registry' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 15. Delete selected sources.
// ---------------------------------------------------------------------------
test_section( 'Delete selected sources' );

$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'active',
	'src_b' => 'inactive',
	'src_c' => 'active',
) ) );
$result   = bulk_apply( 'delete', array( 'src_a', 'src_b' ) );

assert_true( null === $sources_mgr->get( 'src_a' ), 'src_a removed' );
assert_true( null === $sources_mgr->get( 'src_b' ), 'src_b removed' );
assert_true( 'active' === bulk_status( 'src_c' ), 'unselected src_c untouched' );
assert_true( 2 === count( $result['updated'] ), 'two sources reported deleted' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 16. Deleting configurations never deletes imported events.
// ---------------------------------------------------------------------------
test_section( 'Delete source config preserves imported events' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'active' ) ) );

$event_id = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_status' => 'publish',
		'post_title'  => 'Bulk delete fixture ' . wp_generate_password( 8, false ),
	)
);
assert_true( $event_id && ! is_wp_error( $event_id ), 'fixture event created' );
update_post_meta( $event_id, '_event_source', 'src_a' );
update_post_meta( $event_id, '_event_source_id', 'bulk-delete-fixture-' . $event_id );
update_post_meta( $event_id, '_event_status', 'published' );

bulk_apply( 'delete', array( 'src_a' ) );

assert_true( null === $sources_mgr->get( 'src_a' ), 'src_a source config removed' );
assert_true( get_post( $event_id ) !== null, 'imported event post still exists' );
assert_true( 'src_a' === get_post_meta( $event_id, '_event_source', true ), 'event still references the deleted source' );
assert_true( 'published' === get_post_meta( $event_id, '_event_status', true ), '_event_status untouched' );

wp_delete_post( $event_id, true );
bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 17. The redirect notice reports each outcome separately.
// ---------------------------------------------------------------------------
test_section( 'The notice reports successes, skips and failures separately' );

$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'inactive' ) ) );
$notice   = bulk_notice( bulk_apply( 'enable', array( 'src_a' ) ) );
assert_true( 'success' === $notice['status'], 'a clean enable reports success' );
assert_contains( '1 source(s) enabled.', $notice['message'], 'success wording names the count' );

$result = array(
	'action'     => 'enable',
	'error'      => '',
	'requested'  => 3,
	'duplicates' => 0,
	'invalid'    => array(),
	'updated'    => array( 'src_a', 'src_b' ),
	'skipped'    => array( 'src_c' ),
	'failed'     => array(),
);
$notice = bulk_notice( $result );
assert_contains( '2 source(s) enabled.', $notice['message'], 'updated count is reported' );
assert_contains( '1 source(s) were already active.', $notice['message'], 'skipped count is reported separately' );
assert_true( 'success' === $notice['status'], 'a skip is not an error' );

$result['invalid'] = array( 'bogus_id' );
$notice            = bulk_notice( $result );
assert_contains( 'Invalid IDs ignored: bogus_id.', $notice['message'], 'invalid IDs named' );
assert_true( 'error' === $notice['status'], 'an ignored ID turns the notice into an error' );

$result['failed'] = array( 'src_d' );
$result['error']  = '';
$notice           = bulk_notice( $result );
assert_contains( '1 source(s) could not be enabled.', $notice['message'], 'failure count is reported separately' );

$disabled = array(
	'action'     => 'disable',
	'error'      => '',
	'requested'  => 2,
	'duplicates' => 0,
	'invalid'    => array(),
	'updated'    => array( 'src_a' ),
	'skipped'    => array( 'src_b' ),
	'failed'     => array(),
);
$notice = bulk_notice( $disabled );
assert_contains( '1 source(s) disabled.', $notice['message'], 'disable success wording' );
assert_contains( '1 source(s) were already disabled.', $notice['message'], 'already-disabled wording' );

$deleted = $disabled;
$deleted['action'] = 'delete';
$notice = bulk_notice( $deleted );
assert_contains( '1 source(s) deleted successfully.', $notice['message'], 'delete success wording' );

// Error notices for the rejected requests.
assert_true( 'Invalid bulk action requested.' === bulk_notice( bulk_apply( 'purge', array( 'src_a' ) ) )['message'], 'invalid action notice' );
assert_true( 'No sources were selected.' === bulk_notice( bulk_apply( 'enable', array() ) )['message'], 'empty selection notice' );
assert_contains( 'No valid sources selected. Invalid IDs: bogus', bulk_notice( bulk_apply( 'enable', array( 'bogus' ) ) )['message'], 'no-valid-sources notice names the IDs' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// 18. The REAL handler enforces capability and nonce, in its own process.
// ---------------------------------------------------------------------------
test_section( 'The real handler refuses unauthorised requests and bad nonces' );

test_require(
	function_exists( 'exec' ),
	'php-cli:exec-enabled',
	'test prerequisite is available: exec() can start the handler in a child process',
	'run the suite inside the WordPress container where exec() is enabled'
);

$admin_id = bulk_admin_id();
test_require(
	$admin_id > 0,
	'ensure-local-test-user.php',
	'test prerequisite is available: an administrator exists for the nonce case',
	'php scripts/ensure-local-test-user.php'
);

// (a) Logged out: capability must be refused.
$original = bulk_set_sources( bulk_registry( array( 'src_a' => 'inactive' ) ) );

$run = bulk_run_handler( 0, 'enable', array( 'src_a' ), 'valid' );
assert_true( false === strpos( $run['output'], 'HANDLER-RETURNED' ), 'logged-out handler did not return (it died)' );
assert_contains( 'error-page', $run['output'], 'logged-out request ends on the wp_die page' );
assert_true( 'inactive' === bulk_status( 'src_a' ), 'logged-out request changed nothing' );

// (b) Administrator, missing nonce: the nonce must be refused.
$run = bulk_run_handler( $admin_id, 'enable', array( 'src_a' ), 'missing' );
assert_true( false === strpos( $run['output'], 'HANDLER-RETURNED' ), 'nonce-less handler did not return (it died)' );
// The die message is localized (this site runs pt_BR), so the assertion is on
// the wp_die page itself rather than on one language's wording.
assert_contains( 'error-page', $run['output'], 'nonce-less request ends on the wp_die page' );
assert_true( 'inactive' === bulk_status( 'src_a' ), 'nonce-less request changed nothing' );

// (c) Administrator with a valid nonce: the action is applied and the request
// is redirected (so the handler still does not return).
$run = bulk_run_handler( $admin_id, 'enable', array( 'src_a' ), 'valid' );
assert_true( false === strpos( $run['output'], 'HANDLER-RETURNED' ), 'authorised handler redirected instead of returning' );
assert_true( 'active' === bulk_status( 'src_a' ), 'authorised handler enabled the selected source' );

bulk_restore_sources( $original );

// ---------------------------------------------------------------------------
// Test A: Multi-source Enable Selected performs exactly one registry write.
// ---------------------------------------------------------------------------
test_section( 'Test A: Multi-source Enable Selected performs one registry write' );

bulk_instrument_option_writes();
$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'active',
	'src_b' => 'inactive',
	'src_c' => 'inactive',
	'src_d' => 'active',
) ) );
bulk_normalise_and_reset();

$result = bulk_apply( 'enable', array( 'src_a', 'src_b', 'src_c', 'src_d' ) );

assert_true( 'active' === bulk_status( 'src_a' ), 'src_a stays active' );
assert_true( 'active' === bulk_status( 'src_b' ), 'src_b enabled' );
assert_true( 'active' === bulk_status( 'src_c' ), 'src_c enabled' );
assert_true( 'active' === bulk_status( 'src_d' ), 'src_d stays active' );
assert_equals( array( 'src_a', 'src_d' ), $result['skipped'], 'already-active sources skipped' );
assert_equals( array( 'src_b', 'src_c' ), $result['updated'], 'inactive sources enabled' );
assert_true( array() === $result['failed'], 'nothing failed' );
assert_true( 1 === bulk_write_count(), 'exactly one registry update for the batch' );

// Unselected sources remain unchanged (none here, but the registry is intact).
$registry = ( new Conexao_Event_Sources() )->get_all();
assert_true( isset( $registry['src_a'] ) && isset( $registry['src_b'] ) && isset( $registry['src_c'] ) && isset( $registry['src_d'] ), 'all sources still present' );

bulk_restore_sources( $original );
bulk_uninstrument_option_writes();

// ---------------------------------------------------------------------------
// Test B: Multi-source Disable Selected performs exactly one registry write.
// ---------------------------------------------------------------------------
test_section( 'Test B: Multi-source Disable Selected performs one registry write' );

bulk_instrument_option_writes();
$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'active',
	'src_b' => 'inactive',
	'src_c' => 'active',
	'src_d' => 'inactive',
) ) );
bulk_normalise_and_reset();

$result = bulk_apply( 'disable', array( 'src_a', 'src_b', 'src_c', 'src_d' ) );

assert_true( 'inactive' === bulk_status( 'src_a' ), 'src_a disabled' );
assert_true( 'inactive' === bulk_status( 'src_b' ), 'src_b stays inactive' );
assert_true( 'inactive' === bulk_status( 'src_c' ), 'src_c disabled' );
assert_true( 'inactive' === bulk_status( 'src_d' ), 'src_d stays inactive' );
assert_equals( array( 'src_b', 'src_d' ), $result['skipped'], 'already-inactive sources skipped' );
assert_equals( array( 'src_a', 'src_c' ), $result['updated'], 'active sources disabled' );
assert_true( array() === $result['failed'], 'nothing failed' );
assert_true( 1 === bulk_write_count(), 'exactly one registry update for the batch' );

bulk_restore_sources( $original );
bulk_uninstrument_option_writes();

// ---------------------------------------------------------------------------
// Test C: Multi-source Delete Selected performs exactly one registry write.
// ---------------------------------------------------------------------------
test_section( 'Test C: Multi-source Delete Selected performs one registry write' );

bulk_instrument_option_writes();
$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'active',
	'src_b' => 'inactive',
	'src_c' => 'active',
) ) );
bulk_normalise_and_reset();

// Create imported event fixtures that must survive the delete.
$event_id_a = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_status' => 'publish',
		'post_title'  => 'Delete fixture A ' . wp_generate_password( 8, false ),
	)
);
$event_id_b = wp_insert_post(
	array(
		'post_type'   => 'event',
		'post_status' => 'publish',
		'post_title'  => 'Delete fixture B ' . wp_generate_password( 8, false ),
	)
);
assert_true( $event_id_a && ! is_wp_error( $event_id_a ), 'fixture event A created' );
assert_true( $event_id_b && ! is_wp_error( $event_id_b ), 'fixture event B created' );
update_post_meta( $event_id_a, '_event_source', 'src_a' );
update_post_meta( $event_id_a, '_event_source_id', 'delete-fixture-a-' . $event_id_a );
update_post_meta( $event_id_a, '_event_status', 'published' );
update_post_meta( $event_id_b, '_event_source', 'src_b' );
update_post_meta( $event_id_b, '_event_source_id', 'delete-fixture-b-' . $event_id_b );
update_post_meta( $event_id_b, '_event_status', 'published' );

$result = bulk_apply( 'delete', array( 'src_a', 'src_b' ) );

assert_true( null === $sources_mgr->get( 'src_a' ), 'src_a removed' );
assert_true( null === $sources_mgr->get( 'src_b' ), 'src_b removed' );
assert_true( 'active' === bulk_status( 'src_c' ), 'unselected src_c untouched' );
assert_true( 2 === count( $result['updated'] ), 'two sources reported deleted' );
assert_true( array() === $result['failed'], 'nothing failed' );
assert_true( 1 === bulk_write_count(), 'exactly one registry update for the batch' );

// Imported event posts remain present.
assert_true( get_post( $event_id_a ) !== null, 'event A post still exists' );
assert_true( get_post( $event_id_b ) !== null, 'event B post still exists' );
assert_true( 'src_a' === get_post_meta( $event_id_a, '_event_source', true ), 'event A still references deleted source' );
assert_true( 'src_b' === get_post_meta( $event_id_b, '_event_source', true ), 'event B still references deleted source' );
assert_true( 'published' === get_post_meta( $event_id_a, '_event_status', true ), '_event_status untouched on event A' );
assert_true( 'published' === get_post_meta( $event_id_b, '_event_status', true ), '_event_status untouched on event B' );

wp_delete_post( $event_id_a, true );
wp_delete_post( $event_id_b, true );

bulk_restore_sources( $original );
bulk_uninstrument_option_writes();

// ---------------------------------------------------------------------------
// Test D: No-op batch performs no registry write.
// ---------------------------------------------------------------------------
test_section( 'Test D: No-op batch performs no registry write' );

bulk_instrument_option_writes();
$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'active',
	'src_b' => 'active',
) ) );
bulk_normalise_and_reset();

$result = bulk_apply( 'enable', array( 'src_a', 'src_b' ) );

assert_true( 2 === count( $result['skipped'] ), 'both sources skipped' );
assert_true( array() === $result['updated'], 'nothing reported updated' );
assert_true( array() === $result['failed'], 'nothing reported failed' );
assert_true( 0 === bulk_write_count(), 'no registry update for a no-op batch' );

bulk_restore_sources( $original );
bulk_uninstrument_option_writes();

// ---------------------------------------------------------------------------
// Test E: Invalid and duplicate IDs.
// ---------------------------------------------------------------------------
test_section( 'Test E: Invalid and duplicate IDs in a mixed selection' );

bulk_instrument_option_writes();
$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'inactive',
	'src_b' => 'active',
) ) );
bulk_normalise_and_reset();

$result = bulk_apply( 'enable', array( 'src_a', 'src_a', 'src_b', 'bogus_id', '' ) );

// The empty string is filtered out during sanitisation, so 4 IDs reach
// validation: src_a (x2), src_b, bogus_id.
assert_true( 4 === $result['requested'], 'four IDs were submitted' );
assert_true( 1 === $result['duplicates'], 'one duplicate was removed' );
assert_equals( array( 'bogus_id' ), $result['invalid'], 'unknown ID reported invalid' );
assert_equals( array( 'src_a' ), $result['updated'], 'only the inactive source enabled' );
assert_equals( array( 'src_b' ), $result['skipped'], 'already-active source skipped' );
assert_true( array() === $result['failed'], 'nothing failed' );
assert_true( 1 === bulk_write_count(), 'exactly one registry update for the batch' );

assert_true( 'active' === bulk_status( 'src_a' ), 'src_a enabled' );
assert_true( 'active' === bulk_status( 'src_b' ), 'src_b stays active' );

bulk_restore_sources( $original );
bulk_uninstrument_option_writes();

// ---------------------------------------------------------------------------
// Test F: Failed persistence reports failures and writes no deletion logs.
// ---------------------------------------------------------------------------
test_section( 'Test F: Failed persistence reports failures accurately' );

$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'inactive',
	'src_b' => 'active',
) ) );
bulk_normalise_and_reset();

// Simulate a failed write by making update_option() think nothing changed.
// Returning the current value from pre_update_option_ causes update_option()
// to return false (no-op), which persist_sources() treats as an unverified
// write and reports every affected ID as failed.
$current_sources = get_option( Conexao_Event_Sources::OPTION_KEY, array() );
add_filter(
	'pre_update_option_' . Conexao_Event_Sources::OPTION_KEY,
	function () use ( $current_sources ) {
		return $current_sources;
	}
);

$result = bulk_apply( 'enable', array( 'src_a', 'src_b' ) );

// The write was rejected: nothing should be reported as updated.
assert_true( array() === $result['updated'], 'nothing reported updated on failed write' );
assert_equals( array( 'src_a', 'src_b' ), $result['failed'], 'both sources reported failed' );

// Sources remain in their previous persisted state.
assert_true( 'inactive' === bulk_status( 'src_a' ), 'src_a stays inactive' );
assert_true( 'active' === bulk_status( 'src_b' ), 'src_b stays active' );

remove_all_filters( 'pre_update_option_' . Conexao_Event_Sources::OPTION_KEY );

bulk_restore_sources( $original );
bulk_uninstrument_option_writes();

// ---------------------------------------------------------------------------
// Test G: Failed delete persistence writes no successful deletion logs.
// ---------------------------------------------------------------------------
test_section( 'Test G: Failed delete persistence writes no deletion logs' );

$original = bulk_set_sources( bulk_registry( array(
	'src_a' => 'active',
	'src_b' => 'inactive',
) ) );
bulk_normalise_and_reset();

$current_sources = get_option( Conexao_Event_Sources::OPTION_KEY, array() );
add_filter(
	'pre_update_option_' . Conexao_Event_Sources::OPTION_KEY,
	function () use ( $current_sources ) {
		return $current_sources;
	}
);

$result = bulk_apply( 'delete', array( 'src_a', 'src_b' ) );

assert_true( array() === $result['updated'], 'nothing reported updated on failed delete' );
assert_equals( array( 'src_a', 'src_b' ), $result['failed'], 'both sources reported failed' );

// Both sources remain in the registry.
assert_true( isset( ( new Conexao_Event_Sources() )->get_all()['src_a'] ), 'src_a still present' );
assert_true( isset( ( new Conexao_Event_Sources() )->get_all()['src_b'] ), 'src_b still present' );

remove_all_filters( 'pre_update_option_' . Conexao_Event_Sources::OPTION_KEY );

bulk_restore_sources( $original );
bulk_uninstrument_option_writes();

test_finish( 'bulk-actions' );
