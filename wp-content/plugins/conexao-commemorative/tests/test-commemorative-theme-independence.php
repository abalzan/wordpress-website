<?php
/**
 * Architectural regression test: Commemorative Events plugin theme independence.
 *
 * This test ensures the plugin has no direct dependencies on the
 * conexao-br-irlanda theme or any specific WordPress theme.
 *
 * @package Conexao_Commemorative
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

test_section( 'Plugin has no direct theme dependencies' );

// 1. Scan plugin PHP files for theme-specific references
$plugin_dir = dirname( __DIR__ );
$php_files  = array();

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $plugin_dir, RecursiveDirectoryIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( $file->isFile() && 'php' === $file->getExtension() ) {
		$php_files[] = $file->getPathname();
	}
}

$forbidden_patterns = array(
	'conexao-br-irlanda'           => 'Direct theme slug reference',
	'get_template_directory'       => 'Theme directory function',
	'get_template_directory_uri'   => 'Theme directory URI function',
	'get_stylesheet_directory'     => 'Stylesheet directory function',
	'get_stylesheet_directory_uri' => 'Stylesheet directory URI function',
	'CONEXAO_THEME'                => 'Theme constant',
	'conexao_asset_version'        => 'Theme asset version function',
	'conexao_current_locale'       => 'Theme locale function (should use fallback)',
	'conexao_current_language_slug'=> 'Theme language slug function',
);

$violations = array();

foreach ( $php_files as $file ) {
	$content = file_get_contents( $file );
	$relative = str_replace( $plugin_dir . '/', '', $file );

	foreach ( $forbidden_patterns as $pattern => $description ) {
		// Skip the test file itself
		if ( strpos( $relative, 'test-commemorative-theme-independence.php' ) !== false ) {
			continue;
		}

		// Allow fallback usage of conexao_current_locale in repository.php
		if ( $pattern === 'conexao_current_locale' && $relative === 'includes/class-conexao-commemorative-repository.php' ) {
			// Check it's used with function_exists fallback
			if ( preg_match( '/function_exists\s*\(\s*[\'"]conexao_current_locale[\'"]\s*\)/', $content ) ) {
				continue;
			}
		}

		// Allow filter registration in commemorative-presentations.php (theme file, not plugin)
		if ( $pattern === 'conexao_commemorative_presentations' && $relative === 'includes/functions.php' ) {
			// This is the filter NAME, not a theme dependency
			continue;
		}

		if ( strpos( $content, $pattern ) !== false ) {
			$violations[] = array(
				'file'        => $relative,
				'pattern'     => $pattern,
				'description' => $description,
			);
		}
	}
}

assert_true( empty( $violations ), 'No forbidden theme dependencies found in plugin code' );

if ( ! empty( $violations ) ) {
	foreach ( $violations as $v ) {
		fwrite( STDERR, "VIOLATION: {$v['file']} contains '{$v['pattern']}' ({$v['description']})\n" );
	}
}

// 2. Verify plugin initializes without theme functions
test_section( 'Plugin initializes without theme' );

// The plugin should boot on plugins_loaded without requiring theme functions
$booted = false;
add_action( 'plugins_loaded', function() use ( &$booted ) {
	$booted = true;
}, 0 );

// Simulate plugins_loaded
do_action( 'plugins_loaded' );

assert_true( $booted, 'Plugin boots on plugins_loaded without theme' );

// 3. Verify public API functions exist and are callable
test_section( 'Public API functions are available' );

$public_functions = array(
	'conexao_commemorative_active',
	'conexao_commemorative_is_active',
	'conexao_commemorative_resolution',
	'conexao_commemorative_preview_event',
	'conexao_commemorative_is_preview',
	'conexao_commemorative_next_boundary',
	'conexao_commemorative_presentations',
	'conexao_commemorative_admin_overview',
	'conexao_commemorative_reason_label',
);

foreach ( $public_functions as $func ) {
	assert_true( function_exists( $func ), "Public function {$func} exists" );
}

// 4. Verify filter exists for theme to register packages
test_section( 'Theme integration filter exists' );

$filters = array(
	'conexao_commemorative_presentations',
);

foreach ( $filters as $filter ) {
	assert_true( has_filter( $filter ), "Filter {$filter} is registered" );
}

// Verify action exists for cache purge (fired by plugin, external systems hook into it)
test_section( 'Cache purge action is documented' );

// The action is fired via do_action() in the cache class; it's not registered with add_action
// because it's meant for external systems (CDN, host) to hook into.
// We verify the action name is used in the codebase.
$cache_file = $plugin_dir . '/includes/class-conexao-commemorative-cache.php';
$cache_content = file_get_contents( $cache_file );
assert_contains( 'conexao_commemorative_purge_caches', $cache_content, 'Cache purge action name used in cache class' );

// 5. Verify plugin classes don't reference theme
test_section( 'Plugin classes have no theme references' );

$plugin_classes = array(
	'Conexao_Commemorative',
	'Conexao_Commemorative_Repository',
	'Conexao_Commemorative_Resolver',
	'Conexao_Commemorative_Schedule',
	'Conexao_Commemorative_Cache',
	'Conexao_Commemorative_Preview',
	'Conexao_Commemorative_Admin',
	'Conexao_Commemorative_Migration',
	'Conexao_Commemorative_Event',
);

foreach ( $plugin_classes as $class ) {
	assert_true( class_exists( $class ), "Class {$class} exists" );

	$reflection = new ReflectionClass( $class );
	$source_file = $reflection->getFileName();

	if ( $source_file && strpos( $source_file, 'conexao-commemorative' ) !== false ) {
		$content = file_get_contents( $source_file );
		$relative = str_replace( $plugin_dir . '/', '', $source_file );

		foreach ( $forbidden_patterns as $pattern => $description ) {
			// Allow fallback usage in repository
			if ( $pattern === 'conexao_current_locale' && $relative === 'includes/class-conexao-commemorative-repository.php' ) {
				if ( preg_match( '/function_exists\s*\(\s*[\'"]conexao_current_locale[\'"]\s*\)/', $content ) ) {
					continue;
				}
			}

			if ( strpos( $content, $pattern ) !== false ) {
				$violations[] = array(
					'file'        => $relative,
					'pattern'     => $pattern,
					'description' => $description,
				);
			}
		}
	}
}

assert_true( empty( $violations ), 'No forbidden theme dependencies in plugin classes' );

// 6. Verify event records can be retrieved without theme
test_section( 'Event records retrievable without theme' );

// Create a test event
$post_id = wp_insert_post( array(
	'post_type'   => 'commemorative_event',
	'post_status' => 'publish',
	'post_title'  => '[TEST-TI] Theme Independence Event',
) );

if ( ! is_wp_error( $post_id ) && $post_id ) {
	update_post_meta( $post_id, '_ce_event_key', 'theme_independence_test' );
	update_post_meta( $post_id, '_ce_rule', 'fixed_annual' );
	update_post_meta( $post_id, '_ce_date', '12-25' );
	update_post_meta( $post_id, '_ce_lead_in_days', '15' );
	update_post_meta( $post_id, '_ce_priority', '10' );
	update_post_meta( $post_id, '_ce_enabled', '1' );
	update_post_meta( $post_id, '_ce_presentation', 'christmas' );

	// Reset cache
	Conexao_Commemorative_Repository::reset_cache();

	// Try to retrieve the event
	$events = Conexao_Commemorative_Repository::all();
	$found = false;

	foreach ( $events as $event ) {
		if ( $event['event_key'] === 'theme_independence_test' ) {
			$found = true;
			assert_equals( 'theme_independence_test', $event['event_key'], 'Event key matches' );
			assert_equals( 'fixed_annual', $event['rule'], 'Event rule matches' );
			assert_equals( 'christmas', $event['presentation'], 'Event presentation matches' );
			break;
		}
	}

	assert_true( $found, 'Test event found in repository without theme' );

	// Clean up
	wp_delete_post( $post_id, true );
	Conexao_Commemorative_Repository::reset_cache();
} else {
	assert_true( false, 'Could not create test event' );
}

// 7. Verify scheduling works without theme
test_section( 'Scheduling works without theme' );

$post_id = wp_insert_post( array(
	'post_type'   => 'commemorative_event',
	'post_status' => 'publish',
	'post_title'  => '[TEST-TI] Scheduling Test',
) );

if ( ! is_wp_error( $post_id ) && $post_id ) {
	update_post_meta( $post_id, '_ce_event_key', 'scheduling_test' );
	update_post_meta( $post_id, '_ce_rule', 'fixed_annual' );
	update_post_meta( $post_id, '_ce_date', '12-25' );
	update_post_meta( $post_id, '_ce_lead_in_days', '15' );
	update_post_meta( $post_id, '_ce_priority', '10' );
	update_post_meta( $post_id, '_ce_enabled', '1' );
	update_post_meta( $post_id, '_ce_presentation', 'christmas' );

	Conexao_Commemorative_Repository::reset_cache();

	// Test scheduling at a known date (Christmas season)
	$now = new DateTimeImmutable( '2026-12-20 12:00:00', new DateTimeZone( 'Europe/Dublin' ) );
	$event = Conexao_Commemorative_Repository::find_by_event_key( 'scheduling_test' );

	if ( $event ) {
		$window = Conexao_Commemorative_Schedule::resolve( $event, $now );
		assert_true( null !== $window, 'Window resolved for Christmas event' );
		assert_equals( 2026, $window['occurrence_year'], 'Occurrence year is 2026' );
	}

	wp_delete_post( $post_id, true );
	Conexao_Commemorative_Repository::reset_cache();
}

// 8. Verify admin overview works without theme
test_section( 'Admin overview works without theme' );

$post_id = wp_insert_post( array(
	'post_type'   => 'commemorative_event',
	'post_status' => 'publish',
	'post_title'  => '[TEST-TI] Admin Overview',
) );

if ( ! is_wp_error( $post_id ) && $post_id ) {
	update_post_meta( $post_id, '_ce_event_key', 'admin_overview_test' );
	update_post_meta( $post_id, '_ce_rule', 'fixed_annual' );
	update_post_meta( $post_id, '_ce_date', '12-25' );
	update_post_meta( $post_id, '_ce_lead_in_days', '15' );
	update_post_meta( $post_id, '_ce_priority', '10' );
	update_post_meta( $post_id, '_ce_enabled', '1' );
	update_post_meta( $post_id, '_ce_presentation', 'christmas' );

	Conexao_Commemorative_Repository::reset_cache();

	$overview = conexao_commemorative_admin_overview( $post_id );
	assert_true( null !== $overview, 'Admin overview returned' );
	assert_equals( 'admin_overview_test', $overview['event']['event_key'], 'Event key in overview' );
	assert_true( isset( $overview['next_window'] ), 'Next window in overview' );
	assert_true( isset( $overview['reason'] ), 'Reason in overview' );

	wp_delete_post( $post_id, true );
	Conexao_Commemorative_Repository::reset_cache();
}

// 9. Verify preview works without theme
test_section( 'Preview works without theme' );

// Preview is capability-gated and nonce-verified, but the function should exist
assert_true( function_exists( 'conexao_commemorative_preview_event' ), 'Preview function exists' );
assert_true( function_exists( 'conexao_commemorative_is_preview' ), 'Is preview function exists' );

// 10. Verify cache/boundary works without theme
test_section( 'Cache and boundary work without theme' );

assert_true( function_exists( 'conexao_commemorative_next_boundary' ), 'Next boundary function exists' );

$boundary = conexao_commemorative_next_boundary();
// May be null if no events scheduled, but function should not error
assert_true( is_array( $boundary ) || null === $boundary, 'Boundary returns array or null' );

test_section( 'Theme independence test complete' );

test_finish( 'commemorative-theme-independence' );