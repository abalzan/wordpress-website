<?php
/**
 * The single WordPress bootstrap for every maintained in-process PHP suite.
 *
 * Engineering standard §8.2: "Every test file requires `tests/bootstrap.php` —
 * no inline `wp-load.php` discovery". This file is the ONLY place in the
 * repository allowed to locate and load WordPress for tests.
 *
 * It is designed to work in three environments:
 *
 *   1. Inside the WordPress container (`docker compose exec wordpress php
 *      /var/www/html/tests/...`), where the repository's `tests/` and
 *      `scripts/` directories are mounted at `/var/www/html/tests` and
 *      `/var/www/html/scripts` (dev-only Compose mounts).
 *   2. On the host, when the repository root IS the WordPress root.
 *   3. On a CI container, where the paths are set explicitly through the
 *      environment variables below.
 *
 * Resolution order for `wp-load.php`:
 *   1. `$CONEXAO_TEST_WP_ROOT` (explicit override, used by CI);
 *   2. the repository root (this file's `../`);
 *   3. `/var/www/html` (the Compose WordPress root).
 *
 * ## Safety contract (PHASE 5)
 *
 * This bootstrap never connects to production, never infers a production URL,
 * never creates or mutates content, never activates plugins, never runs a
 * migration and never sends an external request. It reads the local WordPress
 * environment and nothing else.
 *
 * @package Conexao_BR_Test_Harness
 */

// tests/ -> repository root (or /var/www/html inside the container).
define( 'CONEXAO_TESTS_ROOT', __DIR__ );

/**
 * Print a bootstrap failure and exit non-zero.
 *
 * A WordPress bootstrap failure is a test failure, never a silent pass
 * (PHASE 38 `wp_bootstrap_failures`).
 *
 * @param string $message Failure message.
 * @return void
 */
function conexao_tests_bootstrap_fail( $message ) {
	fwrite( STDERR, 'conexao-test-bootstrap: ' . $message . "\n" );
	exit( 1 );
}

/**
 * Resolve the directory that contains `wp-load.php`.
 *
 * @return string Absolute path, or an empty string when WordPress is absent.
 */
function conexao_tests_locate_wp_root() {
	$candidates = array();

	$override = getenv( 'CONEXAO_TEST_WP_ROOT' );
	if ( is_string( $override ) && '' !== $override ) {
		$candidates[] = rtrim( $override, '/' );
	}

	// The repository root, or /var/www/html when running inside the container
	// (the repository's tests/ directory is mounted there).
	$candidates[] = dirname( __DIR__ );

	// The documented Compose WordPress root.
	$candidates[] = '/var/www/html';

	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate && is_file( $candidate . '/wp-load.php' ) ) {
			return $candidate;
		}
	}

	return '';
}

$conexao_tests_wp_root = conexao_tests_locate_wp_root();

if ( '' === $conexao_tests_wp_root ) {
	conexao_tests_bootstrap_fail(
		'could not locate wp-load.php. Start the local stack with "docker compose up -d" '
		. 'and run the suite through ./scripts/run-tests.sh (which executes the suites '
		. 'inside the WordPress container), or set CONEXAO_TEST_WP_ROOT.'
	);
}

define( 'CONEXAO_TESTS_WP_ROOT', $conexao_tests_wp_root );

/**
 * Establish the deterministic CLI request context Polylang needs.
 *
 * Several migrated suites set exactly these two values before loading
 * WordPress. Keeping it here is one of the duplicated-bootstrap removals
 * recorded in the Stage E report (PHASE 5).
 */
$_SERVER['HTTP_HOST']   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$_SERVER['REQUEST_URI'] = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
$_SERVER['REQUEST_METHOD'] = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';

require_once CONEXAO_TESTS_WP_ROOT . '/wp-load.php';

if ( ! function_exists( 'add_action' ) || ! isset( $GLOBALS['wp_version'] ) ) {
	conexao_tests_bootstrap_fail( 'wp-load.php did not produce a usable WordPress environment.' );
}

// The shared assertion API. Defined after the WordPress load because the
// failure-message formatter uses wp_json_encode().
require_once CONEXAO_TESTS_ROOT . '/lib/assertions.php';

unset( $conexao_tests_wp_root );
