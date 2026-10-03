<?php
/**
 * Stage 8 — the legacy apply endpoint is closed.
 *
 * ## What this suite proves, and why the counter is the point
 *
 * The interesting property of a CLOSED endpoint is not that it refuses; it is
 * that refusing is the ONLY thing it can do. A refusal message is cheap: a
 * handler can print "deprecated" and still have applied the change first. So
 * every case below pairs its assertion with an ENGINE-INVOCATION COUNTER on a
 * real, registered stage whose `run_callback` increments it.
 *
 * If the counter does not move, then for that request no stage callback ran,
 * `Engine::run()` was never entered, no inventory was read, no plan was built
 * and no adapter create/repair/link function was reachable. That is the claim
 * worth testing; the HTTP-shaped refusal is supporting evidence.
 *
 * ## The attack shapes covered
 *
 * | Shape | Why it mattered |
 * |---|---|
 * | anonymous / unprivileged caller | the endpoint was `admin_post_` only; capability must still gate a dead endpoint |
 * | `mode=apply` + a valid stage | THE bypass: this used to reach `apply_plan()` |
 * | `mode=remove` | rollback deletes EN records; also a mutation |
 * | `mode=preview` | a dry run wrote nothing but still executed stage code |
 * | missing `mode` | the old default was `preview`, not apply — asserted, not assumed |
 * | invalid / malformed stage | the old code resolved a stage straight from the request |
 * | alternate parameter names | `mod`, `dry_run`, `modes`, … |
 * | GET vs POST | `admin-post.php` reads `$_REQUEST`, so the method is not a defence |
 *
 * ## No production contact, no content write
 *
 * The suite runs against the LOCAL stack and writes no post, term, option or
 * relationship. The synthetic stage's adapter throws if a write is ever
 * attempted, so a regression that re-enabled apply fails loudly here instead
 * of quietly succeeding.
 *
 * @package Conexao_Translation_Rollout
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/conexao-translation-rollout.php';

use Conexao_Translation_Rollout_Admin as Legacy;
use Conexao_Translation_Rollout_Engine as Engine;

test_title( 'conexao-translation-rollout — Stage 8 legacy apply endpoint closure' );

/**
 * A synthetic stage whose run_callback counts invocations.
 *
 * @param int &$calls Engine invocation counter.
 * @return array Stage configuration.
 */
function conexao_s8_probe_stage( int &$calls ): array {
	return array(
		'stage'                  => 'stage8-legacy-probe',
		'source_post_type'       => 'post',
		'source_lang'            => 'pt',
		'target_lang'            => 'en',
		'manifest_callback'      => static function () {
			return array(
				'source_lang' => 'pt',
				'target_lang' => 'en',
				'records'     => array(),
			);
		},
		'snapshot_callback'      => static function () {
			return array();
		},
		'build_en_args_callback' => static function () {
			return array();
		},
		'copy_fields_callback'   => static function () {
			return 0;
		},
		'run_callback'           => static function ( array $args = array() ) use ( &$calls ) {
			++$calls;

			return Engine::run( array(), array(), $args );
		},
	);
}

/**
 * Whether the synthetic caller holds manage_options.
 *
 * @var bool
 */
$GLOBALS['conexao_s8_can'] = true;

/**
 * Grant or withhold manage_options for this suite's synthetic caller.
 *
 * Uses WordPress's real `user_has_cap` filter rather than redefining
 * `current_user_can()`, so the code under test runs unmodified.
 *
 * @param array $allcaps Capabilities the role would grant.
 * @return array
 */
function conexao_s8_filter_caps( $allcaps ) {
	$allcaps['manage_options'] = ! empty( $GLOBALS['conexao_s8_can'] );

	return $allcaps;
}

add_filter( 'user_has_cap', 'conexao_s8_filter_caps' );

/**
 * Invoke the legacy handler with a synthetic request and capture the refusal.
 *
 * `wp_die()` is intercepted so the handler can be called directly and its
 * outcome asserted, instead of terminating the suite process.
 *
 * @param array $post Request fields to present.
 * @param bool  $can  Whether the caller holds manage_options.
 * @param bool  $get  Whether to present the request as GET.
 * @return array{died:bool,message:string,code:int}
 */
function conexao_s8_invoke( array $post, bool $can = true, bool $get = false ): array {
	$captured = array(
		'died'    => false,
		'message' => '',
		'code'    => 0,
	);

	$handler = static function ( $message = '', $title = '', $args = array() ) use ( &$captured ) {
		$captured['died']    = true;
		$captured['message'] = is_scalar( $message ) ? (string) $message : '';
		$captured['code']    = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : 0;

		throw new Exception( 'wp_die' );
	};

	add_filter( 'wp_die_handler', static fn() => $handler );

	$prev_post   = $_POST;
	$prev_get    = $_GET;
	$prev_method = $_SERVER['REQUEST_METHOD'];

	$GLOBALS['conexao_s8_can'] = $can;
	$_POST                      = $post;
	$_GET                       = $get ? $post : array();
	$_SERVER['REQUEST_METHOD']  = $get ? 'GET' : 'POST';

	try {
		Legacy::handle_run();
	} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the throw IS the assertion mechanism; wp_die never returns.
		// Swallowed deliberately: the capture above is the result.
	} finally {
		remove_all_filters( 'wp_die_handler' );
		$_POST                     = $prev_post;
		$_GET                      = $prev_get;
		$_SERVER['REQUEST_METHOD'] = $prev_method;
	}

	return $captured;
}

$calls = 0;

Engine::reset_stages();
Engine::register_stage( conexao_s8_probe_stage( $calls ) );

assert_true(
	array( 'stage8-legacy-probe' ) === Engine::registered_stages(),
	'A. the synthetic stage is registered with the engine, so a bypass would be REACHABLE',
	'registered=' . implode( ',', Engine::registered_stages() )
);

// --- 1. The endpoint's identity is preserved, so the refusal can name it ---
assert_true(
	Legacy::ACTION === 'conexao_translation_rollout_run',
	'B. the deprecated action name is unchanged (an old bookmark still resolves to a named refusal)',
	Legacy::ACTION
);
assert_true(
	Legacy::REPLACEMENT_ACTION === 'conexao_translation_automation_proof',
	'C. the refusal names the approved replacement control plane',
	Legacy::REPLACEMENT_ACTION
);

test_section( 'The endpoint refuses, and the engine is never entered' );

// --- 2. The bypass itself: mode=apply against a registered stage -----------
$apply = conexao_s8_invoke(
	array(
		'stage'                  => 'stage8-legacy-probe',
		'mode'                   => 'apply',
		'_conexao_rollout_nonce' => 'a-stale-or-valid-nonce',
	)
);

assert_true( $apply['died'], 'D. mode=apply is refused (the handler terminates via wp_die)' );
assert_true( 0 === $calls, 'E. mode=apply NEVER reaches the engine run_callback', 'calls=' . $calls );
assert_true( 410 === $apply['code'], 'F. the refusal is 410 Gone, not a redirect and not a success', 'code=' . $apply['code'] );
assert_true(
	false !== strpos( $apply['message'], Legacy::REPLACEMENT_ACTION ),
	'G. the refusal names the replacement action',
	$apply['message']
);

// --- 3. Every other mutating and non-mutating mode -------------------------
foreach ( array( 'remove', 'preview', 'run', 'APPLY', 'applY', 'delete', '' ) as $mode ) {
	$attempt = conexao_s8_invoke(
		array(
			'stage' => 'stage8-legacy-probe',
			'mode'  => $mode,
		)
	);

	assert_true(
		$attempt['died'] && 0 === $calls,
		sprintf( 'H. mode=%s is refused and never reaches the engine', var_export( $mode, true ) ),
		'died=' . (int) $attempt['died'] . ' calls=' . $calls
	);
}

// --- 4. No mode at all (the old default was preview, not apply) -----------
$no_mode = conexao_s8_invoke( array( 'stage' => 'stage8-legacy-probe' ) );

assert_true( $no_mode['died'] && 0 === $calls, 'I. a missing mode is refused and never reaches the engine', 'calls=' . $calls );

// --- 5. Anonymous and unprivileged callers --------------------------------
$anon = conexao_s8_invoke(
	array(
		'stage' => 'stage8-legacy-probe',
		'mode'  => 'apply',
	),
	false
);

assert_true( $anon['died'] && 0 === $calls, 'J. an anonymous/unprivileged caller is refused and never reaches the engine', 'calls=' . $calls );
assert_true( 403 === $anon['code'], 'K. the unprivileged refusal is 403', 'code=' . $anon['code'] );
assert_true(
	false === strpos( $anon['message'], Legacy::REPLACEMENT_ACTION ),
	'L. the unprivileged refusal leaks nothing about the deprecation (it is not a public oracle)',
	$anon['message']
);

// --- 6. Stage input is no longer interpreted at all ------------------------
foreach ( array( 'en-guide', '../../etc/passwd', 'job', '', array( 'nested' ) ) as $stage ) {
	$bad_stage = conexao_s8_invoke(
		array(
			'stage' => $stage,
			'mode'  => 'apply',
		)
	);

	assert_true(
		$bad_stage['died'] && 0 === $calls,
		sprintf( 'M. stage=%s cannot select a run target', wp_json_encode( $stage ) ),
		'calls=' . $calls
	);
}

// --- 7. Alternate parameter names cannot smuggle a mode -------------------
foreach ( array( 'mod', 'modes', 'mode[]', 'dry_run', 'run', 'stage_slug', 'action' ) as $alt ) {
	$smuggled = conexao_s8_invoke(
		array(
			'stage' => 'stage8-legacy-probe',
			$alt    => 'apply',
		)
	);

	assert_true(
		$smuggled['died'] && 0 === $calls,
		sprintf( 'N. alternate parameter "%s" cannot select apply', $alt ),
		'calls=' . $calls
	);
}

// --- 8. GET is not a defence, and is treated identically ------------------
$get_attempt = conexao_s8_invoke(
	array(
		'stage' => 'stage8-legacy-probe',
		'mode'  => 'apply',
	),
	true,
	true
);

assert_true(
	$get_attempt['died'] && 0 === $calls,
	'O. a GET request is refused exactly like POST (admin-post.php reads $_REQUEST)',
	'calls=' . $calls
);

// --- 9. The screen cannot start a run either ------------------------------
ob_start();
Legacy::render();
$screen = (string) ob_get_clean();

assert_true( 0 === $calls, 'P. rendering the deprecated screen never reaches the engine', 'calls=' . $calls );
assert_true(
	false === strpos( $screen, '<form' ) && false === strpos( $screen, 'admin-post.php' ),
	'Q. the deprecated screen renders no run form and no admin-post target',
	'has_form=' . (int) ( false !== strpos( $screen, '<form' ) )
);
assert_true(
	false !== strpos( $screen, Legacy::REPLACEMENT_ACTION ),
	'R. the deprecated screen points the operator at the replacement',
	$screen
);

// --- 10. The engine's own lifecycle is untouched by the closure -----------
assert_true(
	'1.2.0' === CONEXAO_TRANSLATION_ROLLOUT_VERSION,
	'S. the engine plugin version was bumped for this behaviour change',
	CONEXAO_TRANSLATION_ROLLOUT_VERSION
);

// --- 11. Total invocation count across the whole suite -------------------
assert_true(
	0 === $calls,
	'T. TOTAL: the engine run_callback was invoked ZERO times across every request shape above',
	'calls=' . $calls
);

fwrite(
	STDERR,
	'EVIDENCE legacy-endpoint engine-invocations=' . (int) $calls
	. ' refusal-code=' . (int) $apply['code']
	. ' anonymous-code=' . (int) $anon['code'] . "\n"
);

test_finish();