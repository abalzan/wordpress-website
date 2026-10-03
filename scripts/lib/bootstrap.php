<?php
/**
 * scripts/lib/bootstrap.php — the canonical PHP script bootstrap (Stage I).
 *
 * Engineering standard §10.2: "Every PHP script uses `scripts/lib/bootstrap.php`
 * (single `wp-load.php` resolution + `ABSPATH` + `WP_USE_THEMES=false`)".
 *
 * This file is the ONLY place in `scripts/` allowed to locate and load
 * WordPress. It replaces the hand-rolled `wp-load.php` discovery loops that
 * existed before Stage I.
 *
 * It provides four things and nothing else:
 *
 *   1. Repository-root / WordPress-root resolution that never depends on the
 *      caller's current working directory and never hard-codes a workstation
 *      path.
 *   2. The standard CLI contract: `--help`, `--dry-run`, `--apply`,
 *      `--confirm-production`, `--json`, plus strict unknown-argument failure.
 *   3. The standard run header (`script` / `target` / `mode` / `scope`) and
 *      the closing `summary:` line, so an operator can tell WHAT, WHERE, MODE
 *      and RESULT without reading the source.
 *   4. Central target classification (local / staging / production / unknown)
 *      and the production write guard.
 *
 * It contains NO application logic, is NOT a framework, and never talks to a
 * production site on its own.
 *
 * Usage from a script (the standard preamble):
 *
 *     require_once __DIR__ . '/lib/bootstrap.php';
 *     $ctx = conexao_script_boot( array( ... ) );
 *
 * @package Conexao_BR_Scripts
 */

// Idempotent: several scripts can be loaded in the same process.
if ( defined( 'CONEXAO_SCRIPTS_BOOTSTRAP' ) ) {
	return;
}
define( 'CONEXAO_SCRIPTS_BOOTSTRAP', dirname( __DIR__ ) );

/**
 * Print a script failure to stderr and exit non-zero.
 *
 * A validation failure, a missing prerequisite, a blocked production target and
 * a failed verification are ALL failures: none of them may exit 0.
 *
 * @param string $message Failure message.
 * @param int    $code    Exit code (default 1).
 * @return void
 */
function conexao_script_fail( $message, $code = 1 ) {
	fwrite( STDERR, 'ERROR: ' . $message . "\n" );
	exit( (int) $code );
}

/**
 * Absolute path of the directory holding `wp-load.php`.
 *
 * Resolution order (first hit wins):
 *   1. `CONEXAO_WP_ROOT` — explicit override (used by CI and unusual hosts).
 *   2. The repository root, i.e. the parent of `scripts/`.
 *   3. `/var/www/html` — the documented Compose WordPress root, which is where
 *      the repository's `scripts/` tree is bind-mounted.
 *
 * @return string Absolute path, or '' when WordPress cannot be found.
 */
function conexao_script_locate_wp_root() {
	$candidates = array();

	$override = getenv( 'CONEXAO_WP_ROOT' );
	if ( is_string( $override ) && '' !== $override ) {
		$candidates[] = rtrim( $override, '/' );
	}

	$candidates[] = dirname( CONEXAO_SCRIPTS_BOOTSTRAP );
	$candidates[] = '/var/www/html';

	foreach ( $candidates as $candidate ) {
		if ( '' !== $candidate && is_file( $candidate . '/wp-load.php' ) ) {
			return $candidate;
		}
	}

	return '';
}

/**
 * Load WordPress unless it is already loaded.
 *
 * Sets `WP_USE_THEMES = false` BEFORE the load so a CLI script never triggers
 * a theme template. When WordPress is already present (scripts normally run
 * through `wp eval-file`, which has loaded it) this is a no-op.
 *
 * @return void
 */
function conexao_script_load_wordpress() {
	if ( defined( 'ABSPATH' ) ) {
		return;
	}

	// Must be defined before wp-load.php.
	if ( ! defined( 'WP_USE_THEMES' ) ) {
		define( 'WP_USE_THEMES', false );
	}

	$wp_root = conexao_script_locate_wp_root();
	if ( '' === $wp_root ) {
		conexao_script_fail(
			'could not locate wp-load.php. Start the local stack with "docker compose up -d" '
			. 'and run this script inside the WordPress container '
			. '(docker compose exec -T wordpress wp eval-file scripts/<name>.php), '
			. 'or set CONEXAO_WP_ROOT to the directory that contains wp-load.php.'
		);
	}

	require_once $wp_root . '/wp-load.php';

	if ( ! function_exists( 'add_action' ) || ! isset( $GLOBALS['wp_version'] ) ) {
		conexao_script_fail( 'wp-load.php did not produce a usable WordPress environment.' );
	}
}

/**
 * Classify a URL as a target class.
 *
 * Centralised here on purpose: hostname string comparison scattered across
 * scripts is how a script ends up believing it is talking to local when it is
 * not. `unknown` fails closed for production-capable writes.
 *
 * @param string $url Site URL.
 * @return string One of 'local', 'staging', 'production', 'unknown'.
 */
function conexao_script_classify_target( $url ) {
	$host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );
	if ( '' === $host ) {
		return 'unknown';
	}

	if ( in_array( $host, array( 'conexaobr.ie', 'www.conexaobr.ie' ), true ) ) {
		return 'production';
	}
	if ( in_array( $host, array( 'localhost', '127.0.0.1', '0.0.0.0', '::1' ), true ) ) {
		return 'local';
	}
	if ( preg_match( '/\.(local|test|invalid|localhost)$/', $host ) ) {
		return 'staging';
	}
	if ( 0 === strpos( $host, 'staging.' ) || 0 === strpos( $host, 'dev.' ) ) {
		return 'staging';
	}

	return 'unknown';
}

/**
 * Resolve the target site URL for a PHP script.
 *
 * Precedence: the `CONEXAO_SITE_URL` environment override, then the loaded
 * WordPress install's `home_url()`. There is deliberately NO production
 * default: a script can never fall through to conexaobr.ie.
 *
 * @return string Site URL without a trailing slash.
 */
function conexao_script_site_url() {
	$override = getenv( 'CONEXAO_SITE_URL' );
	if ( is_string( $override ) && '' !== $override ) {
		return rtrim( $override, '/' );
	}

	if ( function_exists( 'home_url' ) ) {
		return rtrim( (string) home_url( '/' ), '/' );
	}

	// conexao_script_fail() exits, but returning keeps the contract explicit so
	// static analysis sees a string on every path.
	conexao_script_fail(
		'no target site could be resolved. Set CONEXAO_SITE_URL, or run this script '
		. 'through wp eval-file so the local WordPress install provides it.'
	);

	return '';
}

/**
 * The raw CLI tokens for this script, whichever way it was invoked.
 *
 * `wp eval-file` exposes the remaining tokens in the global `$args`; a direct
 * `php scripts/x.php --dry-run` exposes them in `$argv`. Both are supported so
 * one parser serves every invocation style.
 *
 * @return array<int,string>
 */
function conexao_script_cli_tokens() {
	// NOTE: do NOT use `global $args;` here. A `global` statement for a name
	// that does not exist creates it as NULL, and WP-CLI-only invocations (where
	// the tokens live in $args) would then be indistinguishable from a plain
	// `php script.php` run, silently discarding every flag. Inspect the global
	// table directly instead.
	$cli_args = $GLOBALS['args'] ?? null;
	if ( is_array( $cli_args ) && array() !== $cli_args ) {
		return array_map( 'strval', $cli_args );
	}

	// `wp eval-file` (and any WP-CLI entry) always defines $args, even when it
	// is empty. An empty $args therefore means "no flags were passed", NOT
	// "fall back to $argv": under WP-CLI, $argv still holds PHP's original
	// command line (the script path, the eval-file name, ...), and reading it
	// would turn the script's own name into an unknown argument.
	$under_wp_cli = defined( 'WP_CLI' ) && WP_CLI;
	if ( $under_wp_cli ) {
		return array();
	}

	if ( PHP_SAPI === 'cli' && isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ) {
		return array_slice( array_map( 'strval', $GLOBALS['argv'] ), 1 );
	}

	return array();
}

/**
 * Parse the standard CLI contract.
 *
 * Recognised flags: --help, --dry-run, --apply, --confirm-production, --json,
 * plus any additional flags a script declares in its spec (used verbatim, never
 * interpreted here).
 *
 * `--dry-run` and `--apply` are mutually exclusive. Write-capable scripts
 * default to dry-run, so there is no accidental write mode. An unknown argument
 * is always a non-zero failure — a typo must never silently downgrade a run to a
 * different operation.
 *
 * @param array $spec Script spec (see conexao_script_boot()).
 * @return array Parsed CLI state.
 */
function conexao_script_parse_args( array $spec ) {
	$known = array(
		'--help'               => 'help',
		'-h'                   => 'help',
		'--dry-run'            => 'dry_run',
		'--apply'              => 'apply',
		'--confirm-production' => 'confirm_production',
		'--json'               => 'json',
	);

	foreach ( (array) ( $spec['extra_flags'] ?? array() ) as $flag => $key ) {
		$known[ $flag ] = $key;
	}

	$seen = array(
		'dry_run'            => false,
		'apply'              => false,
		'confirm_production' => false,
		'json'               => false,
		'help'               => false,
	);

	$extra  = array();
	$tokens = conexao_script_cli_tokens();

	foreach ( $tokens as $token ) {
		$flag  = $token;
		$value = null;
		if ( false !== strpos( $token, '=' ) ) {
			list( $flag, $value ) = explode( '=', $token, 2 );
		}

		if ( isset( $known[ $flag ] ) ) {
			$key = $known[ $flag ];
			if ( null !== $value ) {
				$extra[ $key ] = $value;
			}
			$seen[ $key ] = true;
			continue;
		}

		// Positional tokens are only meaningful to a script that opts in.
		if ( ! empty( $spec['accept_positional'] ) ) {
			$extra['positional'][] = $token;
			continue;
		}

		conexao_script_fail(
			sprintf( 'unknown argument "%s". Run with --help to see the supported arguments.', $token ),
			2
		);
	}

	if ( $seen['dry_run'] && $seen['apply'] ) {
		conexao_script_fail( '--dry-run and --apply are mutually exclusive; choose one.', 2 );
	}

	$writes = ! empty( $spec['writes'] );

	if ( $seen['apply'] ) {
		$mode = 'apply';
	} elseif ( $seen['dry_run'] ) {
		$mode = 'dry-run';
	} else {
		$mode = $writes ? 'dry-run' : 'read-only';
	}

	return array(
		'mode'               => $mode,
		'writes'             => $writes,
		'confirm_production' => (bool) $seen['confirm_production'],
		'json'               => (bool) $seen['json'],
		'help'               => (bool) $seen['help'],
		'extra'              => $extra,
		'tokens'             => $tokens,
	);
}

/**
 * Print the standard `--help` page and exit 0.
 *
 * @param array $spec Script spec.
 * @return void
 */
function conexao_script_print_help( array $spec ) {
	$out = array( $spec['script'], str_repeat( '=', strlen( $spec['script'] ) ), '' );

	$out[] = 'Purpose:';
	$out[] = '  ' . $spec['purpose'];
	$out[] = '';
	$out[] = 'Target:';
	$out[] = '  ' . $spec['target_description'];
	$out[] = '';
	$out[] = 'Scope:';
	foreach ( explode( "\n", $spec['scope'] ) as $line ) {
		$out[] = '  ' . $line;
	}
	$out[] = '';
	$out[] = 'Safety:';
	$out[] = '  ' . $spec['safety'];
	$out[] = '';
	$out[] = 'Modes:';
	$out[] = '  ' . $spec['modes_description'];
	$out[] = '';
	$out[] = 'Arguments:';
	foreach ( (array) $spec['arguments'] as $arg ) {
		$out[] = '  ' . $arg;
	}
	$out[] = '  --help                     Show this help and exit 0.';
	if ( ! empty( $spec['writes'] ) ) {
		$out[] = '  --dry-run                  Plan only. The default. Zero writes.';
		$out[] = '  --apply                    Perform the writes described under Scope.';
	}
	if ( ! empty( $spec['json'] ) ) {
		$out[] = '  --json                     Emit the machine-readable result on stdout.';
	}
	if ( ! empty( $spec['production_capable'] ) ) {
		$out[] = '  --confirm-production       Acknowledge an apply against a production target.';
	}
	$out[] = '';
	$out[] = 'Environment:';
	foreach ( (array) $spec['environment'] as $line ) {
		$out[] = '  ' . $line;
	}

	fwrite( STDOUT, implode( "\n", $out ) . "\n" );
	exit( 0 );
}

/**
 * Print the standard run header.
 *
 * @param array $ctx Context returned by conexao_script_boot().
 * @return void
 */
function conexao_script_print_header( array $ctx ) {
	$lines = array(
		'script: ' . $ctx['script'],
		'target: ' . $ctx['target_url'] . ' (' . $ctx['target_class'] . ', ' . $ctx['target_env'] . ')',
		'mode:   ' . $ctx['mode'],
		'scope:  ' . str_replace( "\n", ' ', $ctx['scope'] ),
	);

	if ( 'production' === $ctx['target_class'] ) {
		$lines[] = str_repeat( '!', 68 );
		$lines[] = '!!  PRODUCTION TARGET — every write below changes the live site.';
		$lines[] = '!!  Target: ' . $ctx['target_url'];
		$lines[] = str_repeat( '!', 68 );
	}

	$stream = $ctx['json'] ? STDERR : STDOUT;
	fwrite( $stream, implode( "\n", $lines ) . "\n" );
}

/**
 * Render the standard closing summary as a single line (without printing it).
 *
 * Split out of conexao_script_summary() so a script whose primary stdout
 * payload is a machine-readable document can still emit the summary without
 * corrupting that document.
 *
 * @param array $summary Summary counts.
 * @return string The `summary: ...` line, newline-terminated.
 */
function conexao_script_summary_line( array $summary ) {
	$parts = array();
	foreach ( $summary as $key => $value ) {
		$parts[] = $key . '=' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
	}

	return 'summary: ' . implode( ', ', $parts ) . "\n";
}

/**
 * Print the standard closing summary and return the process exit code.
 *
 * With `--json` stdout carries only the JSON document, so the run header has
 * already been routed to stderr. Without it, the summary is one human-readable
 * line.
 *
 * @param array $ctx     Context returned by conexao_script_boot().
 * @param array $summary Summary counts.
 * @param array $extra   Optional top-level JSON payload merged into the result.
 * @return int Exit code (0 = success, non-zero when errors were counted).
 */
function conexao_script_summary( array $ctx, array $summary, array $extra = array() ) {
	if ( $ctx['json'] ) {
		$payload = array_merge(
			array(
				'script' => $ctx['script'],
				'target' => $ctx['target_url'],
				'mode'   => $ctx['mode'],
				'scope'  => $ctx['scope'],
			),
			$extra,
			array( 'summary' => $summary )
		);
		fwrite(
			STDOUT,
			wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n"
		);
	} else {
		$parts = array();
		foreach ( $summary as $key => $value ) {
			$parts[] = $key . '=' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
		}
		fwrite( STDOUT, 'summary: ' . implode( ', ', $parts ) . "\n" );
	}

	$errors = isset( $summary['errors'] ) ? (int) $summary['errors'] : 0;

	return $errors > 0 ? 1 : 0;
}

/**
 * Boot a script: resolve the target, enforce the safety contract, print the run
 * header, and hand the caller its context.
 *
 * Spec keys (required): script, purpose, scope, safety, target_description,
 * modes_description, arguments.
 * Optional: safety_level, writes, production_capable, read_only, json,
 * extra_flags, accept_positional, load_wordpress, environment.
 *
 * @param array $spec Script spec.
 * @return array Context array.
 */
function conexao_script_boot( array $spec ) {
	$spec = array_merge(
		array(
			'safety_level'       => 'read-only',
			'writes'             => false,
			'production_capable' => false,
			'read_only'          => false,
			'json'               => false,
			'extra_flags'        => array(),
			'accept_positional'  => false,
			'load_wordpress'     => true,
			'environment'        => array(),
		),
		$spec
	);

	foreach ( array( 'script', 'purpose', 'scope', 'safety', 'target_description', 'modes_description', 'arguments' ) as $required ) {
		if ( ! isset( $spec[ $required ] ) || '' === $spec[ $required ] ) {
			conexao_script_fail( "script spec is missing the required key \"$required\"." );
		}
	}

	if ( $spec['writes'] && $spec['read_only'] ) {
		conexao_script_fail( 'script spec cannot be both write-capable and read-only.' );
	}

	$parsed = conexao_script_parse_args( $spec );

	if ( $parsed['help'] ) {
		conexao_script_print_help( $spec );
	}

	if ( $spec['load_wordpress'] ) {
		conexao_script_load_wordpress();
	}

	$site_url = conexao_script_site_url();
	$class    = conexao_script_classify_target( $site_url );

	// The production write guard. A write-capable script that can reach
	// production must be told so explicitly, and the exact target is printed.
	if ( $spec['writes'] && 'apply' === $parsed['mode'] && $spec['production_capable'] ) {
		if ( ! $parsed['confirm_production'] ) {
			conexao_script_fail(
				sprintf(
					'refusing to apply against target "%s" (classified as %s) without '
					. '--confirm-production. Re-run with --confirm-production once you have '
					. 'confirmed this is the intended target, or point CONEXAO_SITE_URL at '
					. 'the intended environment.',
					$site_url,
					$class
				),
				3
			);
		}
		fwrite( STDOUT, "confirmed: production apply against {$site_url} (--confirm-production)\n" );
	}

	// An unknown target fails closed for a production-capable write.
	if ( $spec['writes'] && $spec['production_capable'] && 'unknown' === $class ) {
		conexao_script_fail(
			sprintf(
				'refusing to write: target "%s" could not be classified as local, staging '
				. 'or production. Set CONEXAO_SITE_URL to an explicit target.',
				$site_url
			),
			3
		);
	}

	$ctx = array(
		'script'             => $spec['script'],
		'purpose'            => $spec['purpose'],
		'scope'              => $spec['scope'],
		'safety'             => $spec['safety'],
		'safety_level'       => $spec['safety_level'],
		'mode'               => $parsed['mode'],
		'writes'             => $parsed['writes'],
		'json'               => $parsed['json'],
		'confirm_production' => $parsed['confirm_production'],
		'target_url'         => $site_url,
		'target_class'       => $class,
		'target_env'         => $class,
		'extra'              => $parsed['extra'],
		'tokens'             => $parsed['tokens'],
	);

	// With --json, stdout must carry only the JSON document, so the header is
	// routed to stderr (print_header honours $ctx['json']).
	conexao_script_print_header( $ctx );

	return $ctx;
}
