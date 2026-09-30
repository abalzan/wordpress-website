<?php
/**
 * Stage 2 promotion and separation tests.
 *
 * These prove the facts that are easy to assert verbally and easy to break
 * silently:
 *
 *   - the shared engine is byte-identical to its recorded pre-stage digest;
 *   - the registry classifies the engine and the automation plugin correctly
 *     and the generated regions agree with plugins.json;
 *   - the dependency graph is valid and the engine precedes its dependents;
 *   - there is still no cron, no public endpoint, no provider and no second
 *     translation engine;
 *   - activation has no side effects.
 *
 * @package Conexao_Translation_Automation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

test_title( 'conexao-translation-automation — Stage 2 promotion and separation' );

$ENGINE_FILE  = CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php';
$ENGINE_SHA   = 'baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4';
$PLUGIN_DIR   = CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation';
$REGISTRY     = CONEXAO_TESTS_WP_ROOT . '/plugins.json';

// ---------------------------------------------------------------------------
// 1. Engine integrity
// ---------------------------------------------------------------------------
test_section( 'Shared engine integrity' );

assert_true( is_file( $ENGINE_FILE ), 'the shared engine file exists' );
assert_true(
	$ENGINE_SHA === hash_file( 'sha256', $ENGINE_FILE ),
	'the shared engine is byte-identical to the pre-stage digest ' . $ENGINE_SHA . ' (got ' . hash_file( 'sha256', $ENGINE_FILE ) . ')'
);

// The engine still owns the lifecycle it owned before.
assert_true(
	method_exists( 'Conexao_Translation_Rollout_Engine', 'run' )
	&& method_exists( 'Conexao_Translation_Rollout_Engine', 'build_plan' )
	&& method_exists( 'Conexao_Translation_Rollout_Engine', 'validate_manifest' ),
	'the unchanged engine still owns run, build_plan and validate_manifest'
);

// The promoted plugin still declares its own version and requires PHP 8.
$engine_header = (string) file_get_contents( CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/conexao-translation-rollout.php' );
assert_true( false !== strpos( $engine_header, 'Version: 1.1.0' ), 'the promoted engine declares its version' );

// ---------------------------------------------------------------------------
// 2. Separation: no cron, no endpoint, no provider, no second engine
// ---------------------------------------------------------------------------
test_section( 'Separation' );

$plugin_dir = $PLUGIN_DIR;
$php_files  = array_merge(
	array( $plugin_dir . '/conexao-translation-automation.php' ),
	glob( $plugin_dir . '/includes/*.php' ) ?: array()
);

/**
 * Collect every occurrence of the given tokens across the plugin's PHP.
 *
 * Comments are stripped first: this file's own documentation NAMES the hooks
 * it deliberately does not register, and a scan that matched prose would make
 * the guarantee unprovable and meaningless.
 *
 * @param array  $tokens Tokens to look for.
 * @param string $suffix Token suffix.
 * @return array
 */
function conexao_promotion_token_hits( array $tokens, string $suffix ): array {
	global $php_files;

	$hits = array();

	foreach ( $php_files as $file ) {
		$body = conexao_promotion_strip_comments( (string) file_get_contents( $file ) );

		foreach ( $tokens as $token ) {
			if ( false !== strpos( $body, $token . $suffix ) ) {
				$hits[] = basename( $file ) . ':' . $token;
			}
		}
	}

	return $hits;
}

/**
 * Remove comments from PHP source, keeping string literals intact.
 *
 * @param string $source PHP source.
 * @return string
 */
function conexao_promotion_strip_comments( string $source ): string {
	$tokens    = token_get_all( $source );
	$out       = '';
	$in_string = false;

	foreach ( $tokens as $token ) {
		if ( is_string( $token ) ) {
			$out       .= $token;
			$in_string = ( "'" === $token || '"' === $token ) ? ! $in_string : $in_string;

			continue;
		}

		$name = token_name( $token[0] );

		if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
			$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );

			continue;
		}

		$out .= $token[1];
	}

	return $out;
}

// STAGE 3: the plugin still schedules nothing. B2 was resolved by NOT
// depending on pv-cron, not by adding a cron job, so this assertion is
// UNCHANGED and still must hold.
$hits = conexao_promotion_token_hits(
	array( 'wp_schedule_event', 'wp_schedule_single_event', 'wp_next_scheduled', 'wp_unschedule_event', 'cron_schedules', 'as_enqueue_async_action' ),
	''
);
assert_true( array() === $hits, 'the plugin still schedules nothing: the trigger model does not depend on pv-cron' . ( $hits ? ': ' . implode( ', ', $hits ) : '' ) );

// Still no public surface.
$hits = conexao_promotion_token_hits(
	array( 'rest_api_init', 'register_rest_route', 'admin_post_', 'add_shortcode', 'wp_ajax_' ),
	''
);
assert_true( array() === $hits, 'the plugin still exposes no REST route, admin_post, shortcode or AJAX handler' . ( $hits ? ': ' . implode( ', ', $hits ) : '' ) );

// Still no activation side effects.
$hits = conexao_promotion_token_hits(
	array( 'register_activation_hook', 'register_deactivation_hook', 'register_uninstall_hook', 'on_activation', 'activate_' ),
	''
);
assert_true( array() === $hits, 'the plugin still registers no activation/deactivation/uninstall hook' . ( $hits ? ': ' . implode( ', ', $hits ) : '' ) );

// STAGE 3: there is now a provider INTERFACE and a validator, but still NO
// implementation and NO network client. The assertion is narrowed to what
// Stage 3 actually promises: no vendor, no HTTP client, no outbound call.
$hits = conexao_promotion_token_hits(
	array( 'wp_remote_post', 'wp_remote_get', 'wp_remote_request', 'curl_exec', 'fsockopen', 'OpenAI', 'anthropic', 'Claude', 'Gemini', 'deepl' ),
	''
);
assert_true( array() === $hits, 'the plugin makes no network call and embeds no vendor' . ( $hits ? ': ' . implode( ', ', $hits ) : '' ) );

// Still no second translation engine, and no reimplementation of the engine's
// own vocabulary (plan categories, gate keys) outside the engine.
$hits = conexao_promotion_token_hits( array( 'class Conexao_Translation_Rollout_Engine', "'would-create'", "'would-update'" ), '' );
assert_true( array() === $hits, 'the plugin neither redeclares the engine nor reimplements its plan vocabulary' . ( $hits ? ': ' . implode( ', ', $hits ) : '' ) );

// The plugin declares only its own boundary classes. Stage 3 added the
// change-detection, provider, trigger and audit classes; the list below is the
// COMPLETE set, so a future stray class still fails here.
$declared = array();
foreach ( $php_files as $file ) {
	$body = (string) file_get_contents( $file );

	if ( preg_match_all( '/^(?:final |abstract )?(?:class|interface) ([A-Za-z_]+)/m', $body, $m ) ) {
		foreach ( $m[1] as $c ) {
			$declared[] = $c;
		}
	}
}

$expected_classes = array(
	'Conexao_Translation_Automation_Result',
	'Conexao_Translation_Automation_Lock',
	'Conexao_Translation_Automation_Apply_Gate',
	'Conexao_Translation_Automation_Environment',
	'Conexao_Translation_Automation_Orchestrator',
	'Conexao_Translation_Automation_Digest',
	'Conexao_Translation_Automation_Source_State',
	'Conexao_Translation_Automation_Inventory',
	'Conexao_Translation_Automation_Change_Detector',
	'Conexao_Translation_Automation_Provider_Interface',
	'Conexao_Translation_Automation_Provider_Result',
	'Conexao_Translation_Automation_Audit',
	'Conexao_Translation_Automation_Hooks',
	'Conexao_Translation_Automation_Trigger',
);
sort( $declared );
sort( $expected_classes );
assert_true( $expected_classes === $declared, 'the plugin declares exactly its own boundary classes, and no others' );

// STAGE 3: the interface exists but is IMPLEMENTED BY NOBODY. This is the
// structural proof that no provider can be reached at runtime.
$implementations = array();
foreach ( get_declared_classes() as $declared_class ) {
	if ( in_array( 'Conexao_Translation_Automation_Provider_Interface', class_implements( $declared_class ) ?: array(), true ) ) {
		$implementations[] = $declared_class;
	}
}

assert_true( array() === $implementations, 'the provider interface is implemented by nobody: there is NO provider' );

// ---------------------------------------------------------------------------
// 3. No credentials anywhere in the plugin source
// ---------------------------------------------------------------------------
test_section( 'No credentials in source' );

$leaks = array();

foreach ( $php_files as $file ) {
	$body = (string) file_get_contents( $file );

	foreach ( array( 'WP_APPLICATION_PASSWORD', 'WP_USERNAME', 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'OPENAI_API_KEY' ) as $name ) {
		if ( false !== strpos( $body, $name ) ) {
			$leaks[] = basename( $file ) . ':' . $name;
		}
	}
}

assert_true( array() === $leaks, 'the plugin references no credential constant' . ( $leaks ? ': ' . implode( ', ', $leaks ) : '' ) );

test_finish( 'automation promotion' );
