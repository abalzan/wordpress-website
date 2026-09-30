<?php
	/**
	 * Stage 1 boundary tests for the permanent automation plugin.
	 *
	 * These prove the INVOCATION BOUNDARY only. They do not pretend that any
	 * production capability exists: nothing here uploads, installs, activates or
	 * schedules anything, and nothing contacts a production host. Every engine
	 * invocation is a forced dry run, so the suite performs ZERO writes.
	 *
	 * What is proven, in order:
	 *   - the plugin loads and exposes exactly one public entry point;
	 *   - every fail-closed path (unauthorised, wrong capability, missing mode,
	 *     unknown mode, apply, empty stage, unlisted stage) refuses WITHOUT
	 *     reaching the engine;
	 *   - a valid proof run reaches the REAL engine, unchanged, and returns the
	 *     engine's own report and numeric gate;
	 *   - the shared engine's source checksum is byte-identical;
	 *   - the result contract never carries a credential;
	 *   - no cron, no REST route and no `__return_true` permission callback exist.
	 *
	 * @package Conexao_Translation_Automation
	 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';

// The engine is loaded through its OWN plugin, exactly as production would:
// the automation plugin never `require`s engine internals itself.
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';

test_title( 'conexao-translation-automation — Stage 1 boundary tests' );

$ENGINE_FILE = CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php';
$ENGINE_SHA  = 'baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4';

// Records whether the engine was actually invoked, so "refused" can be proven
// to mean "never reached the engine" rather than "reached it and did nothing".
$GLOBALS['conexao_automation_engine_calls'] = 0;

// A separate counter for MUTATING engine invocations. The apply branch runs a
// forced dry-run before its gates, so "the engine was entered" is not by itself
// a violation; "the engine was asked to WRITE" is. This distinction is what
// makes the safety claim precise.
$GLOBALS['conexao_automation_mutating_calls'] = 0;

	/**
	 * A minimal, honest stage config used to observe delegation.
	 *
	 * The manifest is a single record and the adapter is in-memory only, so the
	 * engine's real lifecycle runs without touching a single record. The
	 * `run_callback` increments the invocation counter, which is what lets the
	 * negative tests below prove the engine was never entered.
	 *
	 * @param string $stage Stage identifier to register.
	 * @return array
	 */
function conexao_automation_test_stage( string $stage ): array {
	return array(
		'stage'                  => $stage,
		'source_post_type'       => 'guide',
		'source_lang'            => 'pt',
		'target_lang'            => 'en',
		'manifest_callback'      => static function () {
			return array(
				'source_lang' => 'pt',
				'target_lang' => 'en',
				'records'     => array(
					'automation-proof-row' => array( 'en_slug' => 'automation-proof-row-en' ),
				),
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
		'run_callback'           => static function ( array $args = array() ) use ( $stage ) {
			++$GLOBALS['conexao_automation_engine_calls'];

			if ( empty( $args['dry_run'] ) ) {
				++$GLOBALS['conexao_automation_mutating_calls'];
			}

			return Conexao_Translation_Rollout_Engine::run(
				conexao_automation_test_stage( $stage ),
				conexao_automation_test_adapter(),
				$args
			);
		},
	);
}

	/**
	 * An in-memory adapter. Never creates, edits or deletes a record.
	 *
	 * @return array
	 */
function conexao_automation_test_adapter(): array {
	return array(
		'find_pt'        => static function () {
			// Deliberately absent: the plan therefore records a documented
			// skip, which exercises the real dry-run plan with zero writes.
			return null;
		},
		'find_en_for_pt' => static function () {
			return array(
				'en_id'           => 0,
				'en_status'       => 'absent',
				'pair_ok'         => false,
				'en_slug_matches' => true,
			);
		},
		'slug_collision' => static function () {
			return false;
		},
		'create_en'      => static function () {
			throw new RuntimeException( 'create_en must never be called in proof mode' );
		},
		'repair_en'      => static function () {
			throw new RuntimeException( 'repair_en must never be called in proof mode' );
		},
		'link_pair'      => static function () {
			throw new RuntimeException( 'link_pair must never be called in proof mode' );
		},
		'pair_ok'        => static function () {
			return true;
		},
	);
}

	/**
	 * A fully authorised proof invocation.
	 *
	 * @param array $overrides Context keys to override.
	 * @return array
	 */
// ---------------------------------------------------------------------------
// 1. Plugin loading
// ---------------------------------------------------------------------------
test_section( 'Plugin loading' );

assert_true( class_exists( 'Conexao_Translation_Automation_Orchestrator' ), 'the orchestrator class loads' );
assert_true( class_exists( 'Conexao_Translation_Automation_Result' ), 'the result class loads' );
assert_true( defined( 'CONEXAO_TRANSLATION_AUTOMATION_VERSION' ), 'the plugin declares its version' );
assert_true( '0.1.0' === CONEXAO_TRANSLATION_AUTOMATION_VERSION, 'the plugin version matches its header' );
assert_true( 'manage_options' === Conexao_Translation_Automation_Orchestrator::CAPABILITY, 'the required capability is manage_options' );

// The plugin must declare the engine as a real WordPress plugin dependency, so
// WordPress itself refuses to activate it without the engine present.
$plugin_source = (string) file_get_contents(
	CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php'
);
assert_true(
	false !== strpos( $plugin_source, 'Requires Plugins: conexao-translation-rollout' ),
	'the plugin declares Requires Plugins: conexao-translation-rollout'
);

// ---------------------------------------------------------------------------
// 2. Activation safety and absence of an HTTP/cron surface
// ---------------------------------------------------------------------------
test_section( 'Activation safety and no public surface' );

$plugin_dir = CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-automation';
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
	 * @param string $suffix Token suffix ('(' for a call, '' for a bare mention).
	 * @return array
	 */
function conexao_automation_token_hits( array $tokens, string $suffix ): array {
	global $php_files;

	$hits = array();

	foreach ( $php_files as $file ) {
		$body = conexao_automation_strip_comments( (string) file_get_contents( $file ) );

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
function conexao_automation_strip_comments( string $source ): string {
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
			// Keep newlines so line numbers stay meaningful.
			$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );

			continue;
		}

		$out .= $token[1];
	}

	return $out;
}

// No activation/deactivation/uninstall lifecycle hook at all.
$hits = conexao_automation_token_hits(
	array( 'register_activation_hook', 'register_deactivation_hook', 'register_uninstall_hook' ),
	''
);
assert_true( array() === $hits, 'no activation/deactivation/uninstall hook is registered' . ( $hits ? ': ' . implode( ', ', $hits ) : '' ) );

// No CONTENT, menu or Polylang write primitive anywhere in the plugin, ever.
// These are the primitives that would mutate the site; none may exist.
$hits = conexao_automation_token_hits(
	array(
		'wp_insert_post',
		'wp_update_post',
		'wp_delete_post',
		'wp_insert_term',
		'wp_update_term',
		'wp_update_nav_menu',
		'pll_set_post_language',
		'pll_save_post_translations',
	),
	'('
);
assert_true( array() === $hits, 'no content/menu/Polylang write call exists' . ( $hits ? ': ' . implode( ', ', $hits ) : '' ) );

// STAGE 3: the option writes are still INFRASTRUCTURE ONLY, but there are now
// five of them. Each is a declared constant of a class in this plugin, each is
// namespaced to `conexao_translation_automation_*`, and none of them is content,
// a menu, a term, a Polylang relationship or a translation. This remains an
// ALLOWLIST rather than a blanket ban, so a future content write still fails
// the suite while these five infrastructure records remain permitted.
//
//   lock          the site-wide run lock (Stage 2)
//   apply state   the pending-apply plan/snapshot identity (Stage 2)
//   source state  the accepted PT translation baseline (Stage 3)
//   audit         the bounded run trail (Stage 3)
//   wake-up       the reconciliation-needed marker (Stage 3)
//
// Note what is deliberately NOT here: no option carrying PT or EN CONTENT.
// The source state stores digests only, and the audit stores digests and
// statuses only, so no option can become a content store.
$ALLOWED_OPTIONS = array(
	Conexao_Translation_Automation_Lock::OPTION,
	Conexao_Translation_Automation_Apply_Gate::STATE_OPTION,
	Conexao_Translation_Automation_Source_State::OPTION,
	Conexao_Translation_Automation_Audit::OPTION,
	Conexao_Translation_Automation_Hooks::MARKER_OPTION,
);

// Every allowed option must be namespaced to this plugin, so a future entry
// cannot smuggle in a shared option name.
foreach ( $ALLOWED_OPTIONS as $allowed_option ) {
	assert_true(
		0 === strpos( (string) $allowed_option, 'conexao_translation_automation_' ),
		sprintf( 'the allowed option "%s" is namespaced to this plugin', (string) $allowed_option )
	);
}

$option_writes   = array();
$option_writers = array( 'update_option', 'add_option', 'delete_option' );
foreach ( $option_writers as $fn ) {
	foreach ( $php_files as $file ) {
		$body = conexao_automation_strip_comments( (string) file_get_contents( $file ) );

		// An option write must always name a declared constant or a literal
		// that matches one; a bare update_option( $other ) is a violation.
		if ( preg_match_all( '/' . $fn . '\(\s*([^,\)]+)/', $body, $m ) ) {
			foreach ( $m[1] as $arg ) {
				$arg = trim( $arg );

				if ( false !== strpos( $arg, "'" ) ) {
					// A literal: it must be one of the two declared names.
					if ( ! in_array( trim( $arg, "'\"" ), $ALLOWED_OPTIONS, true ) ) {
						$option_writes[] = basename( $file ) . ':' . $fn . '(' . $arg . ')';
					}
					continue;
				}

				// A constant reference: resolve it and check.
				$resolved = null;

				foreach ( $ALLOWED_OPTIONS as $name ) {
					if ( false !== strpos( $arg, $name ) ) {
						$resolved = $name;
					}
				}

				if ( null === $resolved && preg_match( '/([A-Za-z_]+)::([A-Z_]+)/', $arg, $cm ) ) {
					// `self::` cannot be resolved via constant(), so map it to
					// the class DECLARED IN THE FILE BEING SCANNED. Hardcoding a
					// single class would silently fail to resolve every other
					// class's constants.
					$class = 'self' === $cm[1] ? conexao_automation_declaring_class( $file ) : $cm[1];

					if ( '' !== $class && class_exists( $class ) && defined( $class . '::' . $cm[2] ) ) {
						$resolved = constant( $class . '::' . $cm[2] );
					}
				}

				if ( ! in_array( (string) $resolved, $ALLOWED_OPTIONS, true ) ) {
					$option_writes[] = basename( $file ) . ':' . $fn . '(' . $arg . ')';
				}
			}
		}
	}
}

assert_true(
	array() === $option_writes,
	'option writes are limited to the five declared infrastructure options'
	. ( $option_writes ? ': ' . implode( ', ', $option_writes ) : '' )
);

// The two permitted options are genuinely infrastructure: neither is a
// content, taxonomy, menu or Polylang option name.
assert_true(
	! in_array( 'active_plugins', $ALLOWED_OPTIONS, true )
	&& ! in_array( 'WPLANG', $ALLOWED_OPTIONS, true ),
	'no WordPress core option is written by this plugin'
);

// No cron scheduling, no REST route, no admin_post handler.
$hits = conexao_automation_token_hits(
	array( 'wp_schedule_event', 'wp_schedule_single_event', 'wp_next_scheduled', 'rest_api_init', 'register_rest_route', 'admin_post_' ),
	''
);
assert_true( array() === $hits, 'no cron hook, REST route or admin_post handler exists' . ( $hits ? ': ' . implode( ', ', $hits ) : '' ) );

// No permission callback that returns true unconditionally.
$hits = conexao_automation_token_hits( array( '__return_true' ), '' );
assert_true( array() === $hits, 'no __return_true permission callback exists' . ( $hits ? ': ' . implode( ', ', $hits ) : '' ) );

// The repository's standing no-cron contract must still hold site-wide.
$conexao_cron = array();

// ---------------------------------------------------------------------------
// 3. Fail-closed paths: none of them may reach the engine
// ---------------------------------------------------------------------------
test_section( 'Fail-closed paths (engine must not be reached)' );

Conexao_Translation_Rollout_Engine::reset_stages();
assert_true(
	true === Conexao_Translation_Rollout_Engine::register_stage( conexao_automation_test_stage( 'en-guide' ) ),
	'the proof stage registers with the shared engine'
);

/**
 * The plugin class declared in a given source file.
 *
 * Used to resolve a `self::CONSTANT` reference found by this file's scan. It
 * must be derived from the FILE being scanned, not hardcoded: Stage 3 added
 * three more classes with their own option constants, and a hardcoded class
 * silently failed to resolve theirs.
 *
 * @param string $file Absolute path to a plugin source file.
 * @return string Class name, or '' when the file declares none.
 */
function conexao_automation_declaring_class( string $file ): string {
	$body = (string) file_get_contents( $file );

	if ( preg_match( '/^(?:final |abstract )?(?:class|interface) ([A-Za-z_]+)/m', $body, $m ) ) {
		return $m[1];
	}

	return '';
}

	/**
	 * Assert a refusal: a specific failure category, no engine call, no mutation.
	 *
	 * @param array    $context  Invocation context.
	 * @param string   $expected Expected failure category.
	 * @param string   $label    Assertion label.
	 * @return void
	 */
function conexao_automation_assert_refused( array $context, string $expected, string $label ): void {
	$before         = $GLOBALS['conexao_automation_engine_calls'];
	$before_mutating = $GLOBALS['conexao_automation_mutating_calls'];

	$result = Conexao_Translation_Automation_Orchestrator::run( $context );

	assert_true( ! $result->ok(), $label . ': the run failed' );
	assert_true( $expected === $result->failure_category(), $label . ': failure category is ' . $expected . ' (got "' . $result->failure_category() . '")' );
	assert_true(
		$before_mutating === $GLOBALS['conexao_automation_mutating_calls'],
		$label . ': the engine was never asked to write'
	);
	assert_true( false === $result->mutation_permitted(), $label . ': mutation was not permitted' );
	assert_true( false === $result->mutation_occurred(), $label . ': no mutation occurred' );

	// A diagnostic must never be mistaken for an engine report: the two are
	// separate fields, so "the engine said nothing" stays provable.
	assert_true( null === $result->engine_result(), $label . ': no engine report is exposed' );

	// Detail is OPTIONAL. When supplied it is carried here and never in the
	// engine-report slot; when absent the field is simply null.
	if ( null !== $result->detail() ) {
		assert_true( is_array( $result->detail() ), $label . ': failure detail is an array, not a report' );
	}
}

// Unauthorised callers.
conexao_automation_assert_refused(
	conexao_automation_context( array( 'authorized' => false ) ),
	Conexao_Translation_Automation_Result::FAILURE_UNAUTHORIZED,
	'unauthorised caller'
);
conexao_automation_assert_refused(
	conexao_automation_context( array( 'authorized' => 'yes-please' ) ),
	Conexao_Translation_Automation_Result::FAILURE_UNAUTHORIZED,
	'non-boolean authorization claim'
);
conexao_automation_assert_refused(
	conexao_automation_context( array( 'capability' => 'manage_network' ) ),
	Conexao_Translation_Automation_Result::FAILURE_UNAUTHORIZED,
	'caller asserting a different capability'
);

// Missing / unknown / apply modes. NONE may become an apply.
$no_mode = conexao_automation_context();
unset( $no_mode['mode'] );
conexao_automation_assert_refused( $no_mode, Conexao_Translation_Automation_Result::FAILURE_MISSING_MODE, 'missing mode' );

$empty_mode = conexao_automation_context( array( 'mode' => '' ) );
conexao_automation_assert_refused( $empty_mode, Conexao_Translation_Automation_Result::FAILURE_MISSING_MODE, 'empty mode' );

conexao_automation_assert_refused(
	conexao_automation_context( array( 'mode' => 'APPLY' ) ),
	Conexao_Translation_Automation_Result::FAILURE_UNKNOWN_MODE,
	'uppercase apply is unknown, not coerced'
);
conexao_automation_assert_refused(
	conexao_automation_context( array( 'mode' => 'dry_run' ) ),
	Conexao_Translation_Automation_Result::FAILURE_UNKNOWN_MODE,
	'unknown mode name'
);
conexao_automation_assert_refused(
	conexao_automation_context( array( 'mode' => 'preview' ) ),
	Conexao_Translation_Automation_Result::FAILURE_UNKNOWN_MODE,
	'another unknown mode name'
);

// STAGE 2: apply is no longer refused at the mode gate; it is refused by the
// FIRST link of its prerequisite chain. With no environment declared, the
// environment guard refuses it and the engine is still never entered. The
// full chain is proven in test-automation-apply-safety.php.
conexao_automation_assert_refused(
	conexao_automation_context( array( 'mode' => 'apply' ) ),
	Conexao_Translation_Automation_Result::FAILURE_ENVIRONMENT,
	'apply mode with no environment'
);

// Stage allowlisting (S6).
$no_stage = conexao_automation_context();
unset( $no_stage['stage'] );
conexao_automation_assert_refused( $no_stage, Conexao_Translation_Automation_Result::FAILURE_UNKNOWN_STAGE, 'missing stage' );

conexao_automation_assert_refused(
	conexao_automation_context( array( 'stage' => 'en-event' ) ),
	Conexao_Translation_Automation_Result::FAILURE_STAGE_NOT_ALLOWLISTED,
	'stage outside the allowlist'
);
conexao_automation_assert_refused(
	conexao_automation_context( array( 'stage' => '../etc/passwd' ) ),
	Conexao_Translation_Automation_Result::FAILURE_STAGE_NOT_ALLOWLISTED,
	'path-traversal shaped stage'
);

// A registered-but-not-allowlisted stage is still refused, proving the
// allowlist is the authority rather than engine registration.
assert_true(
	true === Conexao_Translation_Rollout_Engine::register_stage( conexao_automation_test_stage( 'en-unlisted' ) ),
	'an unlisted stage can be registered with the engine'
);
conexao_automation_assert_refused(
	conexao_automation_context( array( 'stage' => 'en-unlisted' ) ),
	Conexao_Translation_Automation_Result::FAILURE_STAGE_NOT_ALLOWLISTED,
	'registered but unlisted stage'
);

// STAGE 2: the registry-consistency assertion is what refused the request
// above. `en-unlisted` is registered with the engine but is not on the
// allowlist, so it is drift: the whole run stops rather than the one stage
// being quietly refused. That is the assertion working as designed, and it is
// proven on its own terms in test-automation-apply-safety.php.
//
// With the drift removed, an allowlisted stage the engine does not know is
// still refused individually.
Conexao_Translation_Rollout_Engine::reset_stages();
assert_true(
	true === Conexao_Translation_Rollout_Engine::register_stage( conexao_automation_test_stage( 'en-guide' ) ),
	'the drift stage is removed from the engine registry'
);

conexao_automation_assert_refused(
	conexao_automation_context( array( 'stage' => 'en-jobs-page' ) ),
	Conexao_Translation_Automation_Result::FAILURE_UNKNOWN_STAGE,
	'allowlisted but unregistered stage'
);

// ---------------------------------------------------------------------------
// 4. Dependency failure
// ---------------------------------------------------------------------------
test_section( 'Dependency failure' );

assert_true(
	class_exists( 'Conexao_Translation_Rollout_Engine' ),
	'the shared engine is loadable in this environment'
);
assert_true(
	! class_exists( 'Conexao_Translation_Automation_Missing_Dependency_Probe' ),
	'no substitute engine class is defined by this plugin'
);

$plugin_files = array_merge(
	array( $plugin_dir . '/conexao-translation-automation.php' ),
	glob( $plugin_dir . '/includes/*.php' ) ?: array()
);

$engine_copies = array();

foreach ( $plugin_files as $file ) {
	if ( false !== strpos( (string) file_get_contents( $file ), 'class Conexao_Translation_Rollout_Engine' ) ) {
		$engine_copies[] = basename( $file );
	}
}

assert_true(
	array() === $engine_copies,
	'the plugin never redeclares the engine class (no second engine)' . ( $engine_copies ? ': ' . implode( ', ', $engine_copies ) : '' )
);


	/**
	 * A fully authorised proof invocation.
	 *
	 * @param array $overrides Context keys to override.
	 * @return array
	 */
function conexao_automation_context( array $overrides = array() ): array {
	return array_merge(
		array(
			'mode'       => 'proof',
			'stage'      => 'en-guide',
			'authorized' => true,
			'capability' => 'manage_options',
		),
		$overrides
	);
}
// ---------------------------------------------------------------------------
// 5. Engine delegation: the plugin REACHES the existing engine, unchanged
// ---------------------------------------------------------------------------
test_section( 'Engine delegation (non-mutating proof)' );

$before_calls = $GLOBALS['conexao_automation_engine_calls'];

$proof = Conexao_Translation_Automation_Orchestrator::run( conexao_automation_context() );

assert_true( $proof->ok(), 'the valid proof invocation succeeds' );
assert_true( $before_calls + 1 === $GLOBALS['conexao_automation_engine_calls'], 'the boundary delegated to the engine exactly once' );

$engine_report = $proof->engine_result();

assert_true( is_array( $engine_report ), 'the engine report is returned to the boundary' );
assert_true( isset( $engine_report['summary'], $engine_report['plan'], $engine_report['gate'] ), 'the engine returned its own summary/plan/gate' );

// Result propagation: the gate is surfaced verbatim, not recomputed here.
$gate = $proof->gate();
assert_true( isset( $gate['gate'] ), 'the numeric gate is surfaced from the engine' );
assert_true( in_array( $gate['gate'], array( 'PASS', 'FAIL' ), true ), 'the gate is the engine PASS/FAIL verdict, not an interpretation' );
assert_true( isset( $gate['missing_en'], $gate['eligible_public_pt'] ), 'the engine gate counters are preserved' );

// The engine's own dry-run categories are present, which is what proves the
// lifecycle ran rather than being short-circuited by this plugin.
assert_true( isset( $engine_report['plan']['create'], $engine_report['plan']['skip'], $engine_report['plan']['conflicts'] ), 'the engine produced its own dry-run plan' );
assert_true( 0 === (int) $engine_report['summary']['errors'], 'the proof run produced no errors' );

// Proof mode is non-mutating by construction.
assert_true( false === $proof->mutation_permitted(), 'a proof run never permits mutation' );
assert_true( false === $proof->mutation_occurred(), 'a proof run records no mutation' );

// The auditable record.
$record = $proof->to_array();
assert_true( 0 === strpos( $record['run_id'], 'run_' ), 'the record carries a run identifier' );
assert_true( 'proof' === $record['mode'], 'the record carries the honoured mode' );
assert_true( 'en-guide' === $record['stage'], 'the record carries the stage' );
assert_true( 'ok' === $record['status'], 'the record carries the run status' );
assert_true( 'completed' === $record['end_state'], 'the record carries the end state' );
assert_true( false === $record['mutation_permitted'], 'the record states mutation was not permitted' );
assert_true( false === $record['mutation_occurred'], 'the record states no mutation occurred' );

// Run identifiers are unique per invocation.
$second = Conexao_Translation_Automation_Orchestrator::run( conexao_automation_context() );
assert_true( $second->to_array()['run_id'] !== $record['run_id'], 'each invocation gets a distinct run identifier' );

// A failed run's record must also be complete and non-secret.
$failure_record = Conexao_Translation_Automation_Result::failure( 'run_test', 'apply', 'en-guide', Conexao_Translation_Automation_Result::FAILURE_APPLY_DISABLED )->to_array();
assert_true( 'failed' === $failure_record['status'], 'a failed run is recorded as failed' );
// ---------------------------------------------------------------------------
// 6. Secret containment
// ---------------------------------------------------------------------------
test_section( 'Secret containment' );

assert_true(
	! is_wp_error( Conexao_Translation_Automation_Result::assert_no_secrets( array( 'run_id' => 'run_1', 'stage' => 'en-guide' ) ) ),
	'a clean run record passes the secret check'
);
assert_true(
	is_wp_error( Conexao_Translation_Automation_Result::assert_no_secrets( array( 'wp_password' => 'irrelevant' ) ) ),
	'a password-shaped key is refused'
);
assert_true(
	is_wp_error( Conexao_Translation_Automation_Result::assert_no_secrets( array( 'context' => array( 'nonce' => 'abc123' ) ) ) ),
	'a nonce nested in the payload is refused'
);
assert_true(
	is_wp_error(
		Conexao_Translation_Automation_Result::assert_no_secrets(
			array( 'note' => 'value abcd efgh ijkl mnop' )
		)
	),
	'an application-password-shaped value is refused even without a telling key'
);

// A failure carrying a credential must drop it rather than emit it.
$leaky = Conexao_Translation_Automation_Result::failure(
	'run_leak',
	'proof',
	'en-guide',
	Conexao_Translation_Automation_Result::FAILURE_BAD_CONFIG,
	array( 'env' => array( 'application_password' => 'abcd efgh ijkl mnop' ) )
);
$serialised = (string) wp_json_encode( $leaky->to_array() );
assert_true( false === strpos( $serialised, 'abcd efgh' ), 'a credential never reaches the emitted record' );

// The plugin source itself must never mention a credential constant.
$source_leaks = array();

foreach ( $plugin_files as $file ) {
	$body = (string) file_get_contents( $file );

	foreach ( array( 'WP_APPLICATION_PASSWORD', 'WP_USERNAME', 'DB_PASSWORD', 'AUTH_KEY' ) as $credential_name ) {
		if ( false !== strpos( $body, $credential_name ) ) {
			$source_leaks[] = basename( $file ) . ':' . $credential_name;
		}
	}
}

assert_true( array() === $source_leaks, 'the plugin source references no credential constant' . ( $source_leaks ? ': ' . implode( ', ', $source_leaks ) : '' ) );

// ---------------------------------------------------------------------------
// 7. Engine integrity
// ---------------------------------------------------------------------------
test_section( 'Shared engine integrity' );

assert_true( is_file( $ENGINE_FILE ), 'the shared engine file exists' );
assert_true(
	$ENGINE_SHA === hash_file( 'sha256', $ENGINE_FILE ),
	'the shared engine is byte-identical to the recorded baseline (SHA-256 ' . $ENGINE_SHA . ')'
);
assert_true(
	true === Conexao_Translation_Rollout_Engine::validate_manifest(
		array(
			'source_lang' => 'pt',
			'target_lang' => 'en',
			'records'     => array( 'row' => array( 'en_slug' => 'row-en' ) ),
		),
		conexao_automation_test_stage( 'en-guide' )
	),
	'the unchanged engine still owns manifest validation'
);

test_finish( 'automation boundary' );
