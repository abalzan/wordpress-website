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

require_once __DIR__ . '/lib/isolated-db.php';
if ( ! conexao_test_db_preflight() ) {
	exit( 1 );
}

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
 * Establish the per-process operator-store isolation suffix.
 *
 * ## Why this runs BEFORE wp-load.php
 *
 * WordPress loads the active plugins (and fires `init` / `wp_loaded`) INSIDE
 * `wp-load.php`. The importer re-arms missed import continuations on
 * `wp_loaded`, and that path logs through `Conexao_Import_Log::add()` — which
 * writes an option. If the suffix were defined after the require, that write
 * would land in the operator's live log before the suite body ever ran.
 *
 * The suffix is therefore established here, using only plain PHP (no WordPress
 * function is available or allowed at this point):
 *
 *   p<pid>_<16 hex characters>
 *
 * The random token is not decoration. `p<pid>` alone collides when two workers
 * share a database in different PID namespaces, and it re-uses a stale row
 * after a `SIGKILL`ed run left one behind.
 *
 * A pre-defined suffix is REFUSED: it could only come from a parent process
 * handing its own store to a child, which is exactly the shared-store defect
 * this stage removes.
 *
 * @return void
 */
function conexao_tests_establish_isolation() {
	foreach ( array( 'getmypid', 'random_bytes', 'preg_match', 'define' ) as $required_function ) {
		if ( ! function_exists( $required_function ) ) {
			conexao_tests_bootstrap_fail( 'required PHP function is unavailable: ' . $required_function );
		}
	}

	if ( defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ) {
		conexao_tests_bootstrap_fail(
			'refusing a pre-defined CONEXAO_TEST_OPTION_SUFFIX. Each test process must establish its own '
			. 'per-process operator store; a child process must load this bootstrap instead of inheriting '
			. 'the parent suffix.'
		);
	}

	$pid = getmypid();

	if ( ! is_int( $pid ) || $pid <= 0 ) {
		conexao_tests_bootstrap_fail( 'could not determine a positive process id (getmypid()).' );
	}

	try {
		$token = bin2hex( random_bytes( 8 ) );
	} catch ( Throwable $e ) {
		conexao_tests_bootstrap_fail( 'could not generate a random isolation token: ' . $e->getMessage() );
		return;
	}

	$suffix = 'p' . $pid . '_' . $token;

	if ( ! preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', $suffix ) ) {
		conexao_tests_bootstrap_fail( 'generated isolation suffix is malformed: ' . $suffix );
	}

	define( 'CONEXAO_TEST_OPTION_SUFFIX', $suffix );
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

$conexao_tests_wp_config = file_get_contents( CONEXAO_TESTS_WP_ROOT . '/wp-config.php' );
if (
	false === $conexao_tests_wp_config
	|| 1 !== preg_match(
		'/define\s*\(\s*[\'"]DB_NAME[\'"]\s*,\s*getenv_docker\s*\(\s*[\'"]WORDPRESS_DB_NAME[\'"]/',
		$conexao_tests_wp_config
	)
) {
	conexao_tests_bootstrap_fail( 'wp-config.php must select DB_NAME from the per-process WORDPRESS_DB_NAME environment.' );
}

/*
 * The bootstrap environment contract. Every check fails closed with a
 * `conexao-test-bootstrap:` line and a non-zero exit, which the runner reports
 * as BLOCKED rather than as a pass.
 */
if ( 'cli' !== PHP_SAPI ) {
	conexao_tests_bootstrap_fail( 'the test harness only supports the CLI SAPI (got ' . PHP_SAPI . ').' );
}

if ( ! extension_loaded( 'pcre' ) ) {
	conexao_tests_bootstrap_fail( 'the PCRE extension is required to validate the isolation suffix.' );
}

/**
 * Establish the deterministic CLI request context WordPress needs.
 *
 * Several migrated suites set exactly these values before loading WordPress.
 * Keeping it here is one of the duplicated-bootstrap removals recorded in the
 * Stage E report (PHASE 5).
 *
 * SERVER_NAME is part of that contract: core helpers used by wp_head() (for
 * example the resource-hint host de-duplication in general-template.php) read
 * it directly. A real web request always has it; a bare CLI process does not,
 * which produced an "Undefined array key SERVER_NAME" warning in any suite
 * that rendered a full page. Deriving it from HTTP_HOST models the request
 * faithfully instead of masking the warning.
 */
$_SERVER['HTTP_HOST']   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$_SERVER['REQUEST_URI'] = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
$_SERVER['REQUEST_METHOD'] = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';

if ( ! isset( $_SERVER['SERVER_NAME'] ) ) {
	$conexao_tests_server_host = (string) $_SERVER['HTTP_HOST'];
	$conexao_tests_port_at     = strrpos( $conexao_tests_server_host, ':' );
	if ( false !== $conexao_tests_port_at ) {
		$conexao_tests_server_host = substr( $conexao_tests_server_host, 0, $conexao_tests_port_at );
	}
	$_SERVER['SERVER_NAME'] = '' !== $conexao_tests_server_host ? $conexao_tests_server_host : 'localhost';
	unset( $conexao_tests_server_host, $conexao_tests_port_at );
}

// Establish this process's operator-store suffix BEFORE WordPress (and
// therefore the importer plugin) executes any code. See
// conexao_tests_establish_isolation() for why the order is the contract.
conexao_tests_establish_isolation();

if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) {
	define( 'WP_HTTP_BLOCK_EXTERNAL', true );
}

if ( ! defined( 'WP_ACCESSIBLE_HOSTS' ) ) {
	define( 'WP_ACCESSIBLE_HOSTS', 'localhost,127.0.0.1,::1' );
}

require_once CONEXAO_TESTS_WP_ROOT . '/wp-load.php';

if ( ! function_exists( 'add_action' ) || ! isset( $GLOBALS['wp_version'] ) ) {
	conexao_tests_bootstrap_fail( 'wp-load.php did not produce a usable WordPress environment.' );
}

$conexao_tests_expected_db = (string) getenv( 'CONEXAO_TEST_DB_NAME' );
global $wpdb;
if ( ! isset( $wpdb->dbname ) || $wpdb->dbname !== $conexao_tests_expected_db || DB_NAME !== $conexao_tests_expected_db ) {
	conexao_tests_bootstrap_fail( 'post-bootstrap database identity does not match CONEXAO_TEST_DB_NAME.' );
}

/*
 * Fail-closed actual-$wpdb identity guard (Stage 4 isolation remediation).
 *
 * A separately-created mysqli/PDO connection is NOT proof that WordPress's own
 * $wpdb connection is restricted. This query runs through the exact $wpdb
 * instance the test suite will use, AFTER WordPress has connected, and BEFORE
 * any suite assertion or test-side write. It establishes:
 *
 *   - CURRENT_USER() resolves to the requested restricted test account;
 *   - DATABASE() is the disposable test schema;
 *   - the query ran through the same $wpdb object the suite uses.
 *
 * If the identity is not the restricted account on the disposable schema, the
 * suite terminates immediately. No reconnect, no silent substitution.
 */
$conexao_tests_identity = $wpdb->get_row(
	'SELECT USER() AS login_user, CURRENT_USER() AS matched_account, DATABASE() AS selected_database',
	ARRAY_A
);

if ( ! is_array( $conexao_tests_identity ) ) {
	conexao_tests_bootstrap_fail( 'could not read the actual $wpdb connection identity.' );
}

$conexao_tests_expected_user = (string) getenv( 'CONEXAO_TEST_DB_USER' );
$conexao_tests_matched       = isset( $conexao_tests_identity['matched_account'] ) ? (string) $conexao_tests_identity['matched_account'] : '';
$conexao_tests_selected      = isset( $conexao_tests_identity['selected_database'] ) ? (string) $conexao_tests_identity['selected_database'] : '';

if ( '' === $conexao_tests_expected_user ) {
	conexao_tests_bootstrap_fail( 'CONEXAO_TEST_DB_USER is required for the actual $wpdb identity guard.' );
}

/*
 * CURRENT_USER() returns the account as MySQL matched it, including the host
 * pattern (e.g. "conexao_test@172.18.%"). The expected user is the bare
 * account name. Accept the bare name or the name@host form, but require the
 * account portion to be exactly the restricted user.
 */
$conexao_tests_matched_user = $conexao_tests_matched;
$conexao_tests_at_at        = strpos( $conexao_tests_matched, '@' );
if ( false !== $conexao_tests_at_at ) {
	$conexao_tests_matched_user = substr( $conexao_tests_matched, 0, $conexao_tests_at_at );
}

if ( $conexao_tests_expected_user !== $conexao_tests_matched_user ) {
	conexao_tests_bootstrap_fail(
		'actual $wpdb connection identity mismatch: expected CURRENT_USER() to resolve to "'
		. $conexao_tests_expected_user . '", got "' . $conexao_tests_matched . '". '
		. 'The test process is NOT isolated to the restricted account.'
	);
}

if ( $conexao_tests_expected_db !== $conexao_tests_selected ) {
	conexao_tests_bootstrap_fail(
		'actual $wpdb selected database mismatch: expected "' . $conexao_tests_expected_db
		. '", got "' . $conexao_tests_selected . '".'
	);
}

// Record only the non-secret identity values for the evidence log.
printf(
	"WPDB_IDENTITY=PASS login_user=%s matched_account=%s selected_database=%s\n",
	isset( $conexao_tests_identity['login_user'] ) ? (string) $conexao_tests_identity['login_user'] : '',
	$conexao_tests_matched,
	$conexao_tests_selected
);

unset( $conexao_tests_expected_db, $conexao_tests_wp_config, $conexao_tests_identity );
unset( $conexao_tests_expected_user, $conexao_tests_matched, $conexao_tests_selected );
unset( $conexao_tests_matched_user, $conexao_tests_at_at );

/**
 * Whether a host name is a documented local development host.
 *
 * Used by conexao_tests_verify_isolation(): WordPress reports `production` when
 * WP_ENVIRONMENT_TYPE is unset, which is true of the existing local Compose
 * volume, so the environment check must not refuse the local stack. A real
 * production host is refused before this is consulted.
 *
 * @param string $host Lower-case host name.
 * @return bool
 */
function conexao_tests_is_local_host( $host ) {
	$host = strtolower( trim( (string) $host ) );

	if ( '' === $host ) {
		return false;
	}

	$local_hosts = array( 'localhost', '127.0.0.1', '::1', 'host.docker.internal' );

	if ( in_array( $host, $local_hosts, true ) ) {
		return true;
	}

	foreach ( array( '.local', '.test', '.localhost', '.invalid' ) as $suffix ) {
		if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
			return true;
		}
	}

	if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
		// A private or reserved address is a local/CI environment.
		return false === filter_var(
			$host,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	// A single-label host name (a Docker service name, a laptop hostname) is
	// never a public production domain.
	return false === strpos( $host, '.' );
}

/**
 * Verify that this process really is isolated from the operator stores.
 *
 * Runs AFTER `wp-load.php` (the store classes only exist once the plugin is
 * loaded) and BEFORE the suites are allowed to touch anything. Every failure
 * exits non-zero: a test suite must never continue with production storage.
 *
 * When the importer plugin is not active, no store can write, so only the
 * suffix assertion applies and theme suites keep working unchanged.
 *
 * @return void
 */
function conexao_tests_verify_isolation() {
	$suffix = defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ? (string) CONEXAO_TEST_OPTION_SUFFIX : '';

	if ( ! preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', $suffix ) ) {
		conexao_tests_bootstrap_fail( 'the per-process isolation suffix is missing or malformed.' );
	}

	$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '';

	$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

	// The production host is refused unconditionally: no environment type can
	// make writing to conexaobr.ie acceptable.
	foreach ( array( 'conexaobr.ie', 'www.conexaobr.ie' ) as $production_host ) {
		if ( $production_host === $home_host ) {
			conexao_tests_bootstrap_fail( 'refusing to run against the production host: ' . $production_host );
		}
	}

	/*
	 * A `production` environment type is refused UNLESS the site is served from
	 * a documented local development host.
	 *
	 * WordPress reports `production` whenever WP_ENVIRONMENT_TYPE is unset, and
	 * the existing local Compose volume has no such define (verified: its
	 * wp-config.php predates the WORDPRESS_CONFIG_EXTRA entry). Refusing on
	 * that alone would make the shared harness unusable on the very stack it
	 * runs on, while adding nothing: a real production install is refused by the
	 * host check above. The environment check therefore stays fail-closed for
	 * every non-local host.
	 */
	if ( 'production' === $environment && ! conexao_tests_is_local_host( $home_host ) ) {
		conexao_tests_bootstrap_fail(
			'refusing to run against a WordPress install with environment type "production" on host "'
			. $home_host . '". The in-process suites write to the database they load.'
		);
	}

	$stores = array(
		array( 'Conexao_Import_Log', 'option_key', 'OPTION_KEY', 'TEST_OPTION_PREFIX' ),
		array( 'Conexao_Import_History', 'option_key', 'OPTION_KEY', 'TEST_OPTION_PREFIX' ),
		array( 'Conexao_Event_Cleanup', 'status_option', 'STATUS_OPTION', 'STATUS_TEST_OPTION_PREFIX' ),
		array( 'Conexao_Event_Cleanup', 'history_option', 'HISTORY_OPTION', 'HISTORY_TEST_OPTION_PREFIX' ),
	);

	foreach ( $stores as $store ) {
		list( $class, $method, $production_constant, $prefix_constant ) = $store;

		if ( ! class_exists( $class ) ) {
			// The importer plugin is not active: no store can write.
			continue;
		}

		if ( ! method_exists( $class, $method ) || ! defined( $class . '::' . $production_constant ) || ! defined( $class . '::' . $prefix_constant ) ) {
			conexao_tests_bootstrap_fail( 'operator store ' . $class . ' does not expose ' . $method . '()/' . $prefix_constant . '.' );
		}

		$key      = call_user_func( array( $class, $method ) );
		$prefix   = constant( $class . '::' . $prefix_constant );
		$expected = $prefix . $suffix;

		if ( constant( $class . '::' . $production_constant ) === $key ) {
			conexao_tests_bootstrap_fail( 'operator store ' . $class . ' resolved its PRODUCTION option name in a test process.' );
		}

		if ( $expected !== $key ) {
			conexao_tests_bootstrap_fail(
				'operator store ' . $class . ' resolved "' . $key . '" instead of its own per-process test store.'
			);
		}
	}
}

/**
 * Delete this process's four operator test stores at shutdown.
 *
 * Registered at priority 999, AFTER `Conexao_Import_Log`'s own flush at
 * priority 5, so buffered entries are persisted to the test row and then
 * removed with it — never "flushed" into a row that has already been deleted.
 *
 * The key set is derived ONLY from the four accessors. No query, no `LIKE`
 * pattern and no option-name literal participates, so no code path here can
 * name — let alone delete — a production option.
 *
 * @return void
 */
function conexao_tests_clean_option_stores() {
	$stores = array(
		array( 'Conexao_Import_Log', 'option_key', 'OPTION_KEY', 'TEST_OPTION_PREFIX' ),
		array( 'Conexao_Import_History', 'option_key', 'OPTION_KEY', 'TEST_OPTION_PREFIX' ),
		array( 'Conexao_Event_Cleanup', 'status_option', 'STATUS_OPTION', 'STATUS_TEST_OPTION_PREFIX' ),
		array( 'Conexao_Event_Cleanup', 'history_option', 'HISTORY_OPTION', 'HISTORY_TEST_OPTION_PREFIX' ),
	);

	$suffix = defined( 'CONEXAO_TEST_OPTION_SUFFIX' ) ? (string) CONEXAO_TEST_OPTION_SUFFIX : '';

	foreach ( $stores as $store ) {
		list( $class, $method, $production_constant, $prefix_constant ) = $store;

		if ( ! class_exists( $class ) || ! method_exists( $class, $method ) ) {
			continue;
		}

		try {
			$key = call_user_func( array( $class, $method ) );
		} catch ( Throwable $e ) {
			fwrite( STDERR, 'conexao-test-cleanup: ' . $class . ' could not resolve its test store: ' . $e->getMessage() . "\n" );
			continue;
		}

		$prefix     = defined( $class . '::' . $prefix_constant ) ? constant( $class . '::' . $prefix_constant ) : '';
		$production = defined( $class . '::' . $production_constant ) ? constant( $class . '::' . $production_constant ) : '';

		$is_current_process_test_key = (
			is_string( $key )
			&& '' !== $suffix
			&& '' !== $prefix
			&& $key !== $production
			&& 0 === strpos( $key, $prefix )
			&& $key === $prefix . $suffix
			&& 1 === preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/', substr( $key, strlen( $prefix ) ) )
		);

		if ( ! $is_current_process_test_key ) {
			fwrite( STDERR, 'conexao-test-cleanup: refusing to delete a key that is not this process\'s test store: ' . $key . "\n" );
			continue;
		}

		delete_option( $key );
	}
}

try {
	conexao_tests_verify_isolation();
} catch ( Throwable $e ) {
	conexao_tests_bootstrap_fail( 'isolation verification failed: ' . $e->getMessage() );
}

// The shared assertion API. Defined after the WordPress load because the
// failure-message formatter uses wp_json_encode().
require_once CONEXAO_TESTS_ROOT . '/lib/assertions.php';
require_once CONEXAO_TESTS_ROOT . '/lib/option-isolation.php';

// Remove this process's operator test stores. Priority 999 runs after the log
// store's own buffered flush at priority 5.
add_action( 'shutdown', 'conexao_tests_clean_option_stores', 999 );

unset( $conexao_tests_wp_root );
