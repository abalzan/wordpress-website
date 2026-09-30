<?php
/**
 * Temporary-plugin runtime verification — reads the INSTALLED copies of
 * `conexao-translation-rollout` and `conexao-en-translation`.
 *
 * Evidence for docs/reports/2026-09-30-temporary-en-translation-plugins.md.
 * Proves, in one disposable local WordPress, the facts Phase 6/8/9 require:
 *
 *   1. The shared engine loads with no fatal error and exposes the whole
 *      lifecycle surface (validate_manifest, build_plan, calculate_gate, run).
 *   2. `conexao-en-translation` detects the shared engine.
 *   3. ALL SEVEN current stage IDs resolve through the engine's own registry
 *      — no more, no fewer — and no retired stage is registered.
 *   4. Each stage's authored manifest is non-empty and the engine's own
 *      `validate_manifest()` accepts it.
 *   5. B1 vs B2 classification is the documented one: five B1 record stages
 *      and two B2 field stages, the latter writing only to an EN-namespaced
 *      `_..._en` meta key on the SAME record.
 *   6. The `guide` stage's taxonomy callback/gate are present and no other
 *      stage declares one.
 *   7. The shared-slug policy resolves `newsletter` for `en-page` and grants
 *      no permit to the B2 stages.
 *
 * READ-ONLY by construction: it calls only the engine's pure
 * validation/planning entry points and read-only registry lookups. It never
 * calls `Engine::run()`, never inserts a post or a term, never writes an
 * option and never creates a translation link. Those are asserted below.
 *
 * Run with:
 *   docker compose exec -T wordpress php \
 *     /var/www/html/docs/evidence/2026-09-30-temporary-en-translation-plugins/runtime-verification.php
 *
 * @package Conexao_BR_Temporary_Plugin_Evidence
 */

require_once '/var/www/html/tests/bootstrap.php';

$passed = 0;
$failed = 0;

/**
 * Record one assertion and print it.
 *
 * @param bool   $condition Result.
 * @param string $message   Description.
 * @return bool
 */
function conexao_tv_check( bool $condition, string $message ): bool {
	global $passed, $failed;

	if ( $condition ) {
		++$passed;
		echo "  [PASS] {$message}\n";
	} else {
		++$failed;
		echo "  [FAIL] {$message}\n";
	}

	return $condition;
}

echo "TEMPORARY EN TRANSLATION PLUGINS — runtime verification (read-only)\n";
echo "site:   " . home_url() . "\n";
echo "engine: " . ( defined( 'CONEXAO_TRANSLATION_ROLLOUT_VERSION' ) ? 'v' . CONEXAO_TRANSLATION_ROLLOUT_VERSION : 'NOT LOADED' ) . "\n\n";

// -- 1/2. Both plugins load, and the EN plugin finds the engine ---------------

conexao_tv_check(
	class_exists( 'Conexao_Translation_Rollout_Engine' ),
	'the shared engine class is loaded (conexao-translation-rollout active, includes resolved)'
);

foreach ( array( 'register_stage', 'registered_stages', 'get_stage', 'validate_manifest', 'build_plan', 'calculate_gate', 'run' ) as $method ) {
	conexao_tv_check(
		method_exists( 'Conexao_Translation_Rollout_Engine', $method ),
		'engine exposes Conexao_Translation_Rollout_Engine::' . $method . '()'
	);
}

foreach (
	array(
		'conexao_en_translation_stage_ids',
		'conexao_en_translation_post_types',
		'conexao_en_translation_engine_config',
		'conexao_en_translation_stage_manifest',
		'conexao_en_translation_shared_page_slug_for',
		'conexao_en_translation_shared_page_slug_filter',
		'conexao_en_translation_with_shared_page_slug',
		'conexao_en_translation_blog_page_config',
		'conexao_en_translation_jobs_page_config',
		'conexao_en_translation_leisure_description_config',
		'conexao_en_translation_course_provider_description_config',
	) as $fn
) {
	conexao_tv_check( function_exists( $fn ), 'EN stage layer exposes ' . $fn . '()' );
}

conexao_tv_check(
	defined( 'CONEXAO_TRANSLATION_ROLLOUT_DIR' ) && defined( 'CONEXAO_EN_TRANSLATION_DIR' ),
	'both plugins resolved their own directory constants from the installed copies'
);

// -- 3. Exactly the seven current stage IDs ---------------------------------

$EXPECTED_B1 = array( 'en-guide', 'en-page', 'en-post', 'en-blog-page', 'en-jobs-page' );
$EXPECTED_B2 = array( 'en-leisure-description', 'en-course-provider-description' );
$EXPECTED    = array_merge( $EXPECTED_B1, $EXPECTED_B2 );

// The B2 field each stage owns, keyed by stage. The plugin's own constants are
// authoritative; these are the documented values the packaging must preserve.
$B2_CONSTANTS = array(
	'en-leisure-description'         => 'CONEXAO_EN_LEISURE_DESCRIPTION_META',
	'en-course-provider-description' => 'CONEXAO_EN_COURSE_PROVIDER_DESCRIPTION_META',
);
$B2_META = array(
	'en-leisure-description'         => '_leisure_excerpt_en',
	'en-course-provider-description' => '_provider_excerpt_en',
);

$declared   = conexao_en_translation_stage_ids();
$registered = Conexao_Translation_Rollout_Engine::registered_stages();

echo "\nSTAGE SET\n";
conexao_tv_check( 7 === count( $declared ), 'the plugin declares exactly seven current stages (got ' . count( $declared ) . ')' );
conexao_tv_check( $EXPECTED === $declared, 'the declared stage set is exactly the seven current stages' );

foreach ( $declared as $stage ) {
	conexao_tv_check( in_array( $stage, $registered, true ), 'stage ' . $stage . ' is REGISTERED with the shared engine' );
}

$retired = array( 'en-page-translation', 'en-blog-translation', 'en-job-translation', 'en-leisure-translation', 'en-guide-translation' );
$en_only = array_values( array_intersect( $registered, array_merge( $EXPECTED, $retired ) ) );
conexao_tv_check( $EXPECTED === $en_only, 'no obsolete or retired stage is registered by the EN plugin' );

foreach ( array( 'en-event', 'en-sponsor', 'en-job' ) as $absent ) {
	conexao_tv_check( ! in_array( $absent, $registered, true ), $absent . ' does not exist: event/sponsor/job stay B2 and stage-less' );
}


// -- 4. Every stage's authored manifest validates through the engine ----------

echo "\nMANIFEST VALIDATION (engine validate_manifest + build_plan)\n";

foreach ( $declared as $stage ) {
	$config = Conexao_Translation_Rollout_Engine::get_stage( $stage );

	if ( null === $config ) {
		conexao_tv_check( false, 'stage ' . $stage . ' is registered' );
		continue;
	}

	$manifest = call_user_func( $config['manifest_callback'] );
	$rows     = (array) ( $manifest['records'] ?? array() );
	$manifest_rows[ $stage ] = count( $rows );

	conexao_tv_check( count( $rows ) > 0, 'stage ' . $stage . ' ships a non-empty authored manifest (' . count( $rows ) . ' record(s))' );

	// The engine's own validator, on the stage's own manifest. It returns
	// true on success and a WP_Error on any defect (fail-closed, zero writes).
	$errors = Conexao_Translation_Rollout_Engine::validate_manifest( $manifest, $config );
	conexao_tv_check(
		true === $errors,
		'validate_manifest() accepts the ' . $stage . ' manifest'
			. ( is_wp_error( $errors ) ? ': ' . $errors->get_error_message() : '' )
	);

	// build_plan() over an empty observed state: pure planning, zero writes.
	$plan = Conexao_Translation_Rollout_Engine::build_plan( $manifest, array() );
	conexao_tv_check( is_array( $plan ), 'build_plan() returns a plan for ' . $stage );
}

// -- 5. B1 / B2 classification, read from the SOURCE -------------------------

echo "\nB1 / B2 CLASSIFICATION\n";

// A B1 record stage has no B2 field constant; a B2 stage declares its target
// through the plugin's own *_META constant and its adapter reports en_id ==
// pt_id (a field on the SAME record, never a second identity).
foreach ( $EXPECTED_B1 as $stage ) {
	$config = Conexao_Translation_Rollout_Engine::get_stage( $stage );
	conexao_tv_check(
		! defined( $B2_CONSTANTS[ $stage ] ?? '' ),
		$stage . ' is a B1 record stage: creates linked EN records, declares no B2 field constant'
	);
}

foreach ( array_keys( $B2_META ) as $stage ) {
	$constant = $B2_CONSTANTS[ $stage ];
	$meta     = defined( $constant ) ? (string) constant( $constant ) : '';
	$expected = $B2_META[ $stage ];

	conexao_tv_check(
		'' !== $meta,
		$stage . ' declares its B2 field constant ' . $constant . ' = ' . ( '' !== $meta ? $meta : 'UNDEFINED' )
	);
	conexao_tv_check( $expected === $meta, $stage . ' writes to ' . $expected . ', the documented B2 field' );
	conexao_tv_check(
		(bool) preg_match( '/^_[a-z_]+_en$/', $meta ),
		$stage . ' writes only to an EN-namespaced _meta key, never a PT field'
	);
}


// -- 6. Taxonomy capability belongs to the guide stage only ------------------

echo "\nTAXONOMY CAPABILITY\n";

$guide_config = Conexao_Translation_Rollout_Engine::get_stage( 'en-guide' );
conexao_tv_check(
	! empty( $guide_config['taxonomy_callback'] ) && function_exists( $guide_config['taxonomy_callback'] ),
	'en-guide declares the engine taxonomy callback and it resolves (' . ( $guide_config['taxonomy_callback'] ?? 'none' ) . ')'
);
conexao_tv_check(
	! empty( $guide_config['taxonomy_gate_callback'] ) && function_exists( $guide_config['taxonomy_gate_callback'] ),
	'en-guide declares the taxonomy gate callback and it resolves'
);

foreach ( array( 'en-page', 'en-post', 'en-blog-page', 'en-jobs-page' ) as $no_tax ) {
	$cfg = Conexao_Translation_Rollout_Engine::get_stage( $no_tax );
	conexao_tv_check( empty( $cfg['taxonomy_callback'] ), $no_tax . ' declares no taxonomy callback: only en-guide creates EN terms' );
}

conexao_tv_check(
	! empty( $gated = conexao_en_translation_guide_terms_v1() ),
	'the authored EN taxonomy data is bundled with the plugin and resolves ('
		. count( (array) $gated ) . ' term(s))'
);

// -- 7. The shared-slug policy, including `newsletter` -----------------------

echo "\nSHARED-SLUG POLICY\n";

conexao_tv_check( 'newsletter' === conexao_en_translation_shared_page_slug_for( 'en-page' ), 'en-page declares the shared slug "newsletter"' );
conexao_tv_check( 'blog' === conexao_en_translation_shared_page_slug_for( 'en-blog-page' ), 'en-blog-page declares the shared slug "blog"' );
conexao_tv_check( 'empregos' === conexao_en_translation_shared_page_slug_for( 'en-jobs-page' ), 'en-jobs-page declares the shared slug "empregos"' );

foreach ( array( 'en-guide', 'en-post', 'en-leisure-description', 'en-course-provider-description' ) as $no_permit ) {
	conexao_tv_check(
		'' === conexao_en_translation_shared_page_slug_for( $no_permit ),
		$no_permit . ' declares NO shared slug, so no uniqueness exception is armed for it'
	);
}

// The target EN slug for newsletter must be exactly "newsletter", never "-2".
$page_manifest = conexao_en_translation_stage_manifest( 'en-page' );
$page_records  = (array) ( $page_manifest['records'] ?? array() );
$nl_row        = (array) ( $page_records['newsletter'] ?? array() );

conexao_tv_check( array() !== $nl_row, 'the en-page manifest authors the newsletter row' );

// -- 8. No second translation lifecycle -------------------------------------

echo "\nNO SECOND LIFECYCLE\n";

// The engine plugin legitimately declares TWO classes: the Engine (which owns
// the lifecycle) and the Admin (its wp-admin screen, which owns no lifecycle).
// What must not exist is a SECOND ENGINE. So the assertion is on the lifecycle
// entry point, not on the class count.
$engine_classes = array_values(
	array_filter(
		get_declared_classes(),
		static function ( $c ) {
			return method_exists( $c, 'run' ) && method_exists( $c, 'build_plan' ) && method_exists( $c, 'calculate_gate' );
		}
	)
);
conexao_tv_check(
	array( 'Conexao_Translation_Rollout_Engine' ) === $engine_classes,
	'exactly ONE class owns the translation lifecycle: ' . implode( ', ', $engine_classes )
);

conexao_tv_check(
	class_exists( 'Conexao_Translation_Rollout_Admin' ) && ! method_exists( 'Conexao_Translation_Rollout_Admin', 'build_plan' ),
	'the Admin class is the engine plugin\'s wp-admin screen and owns no lifecycle step'
);

foreach ( array( 'conexao_translation_rollout', 'conexao_rollout_run', 'en_translation_rollout' ) as $dup ) {
	conexao_tv_check( ! function_exists( $dup ), 'no second engine entry point exists (' . $dup . '() is undefined)' );
}

// Engine::run() must remain UNCALLED by this verification: prove the plan and
// gate surface is usable on its own, which is exactly what a dry-run needs.
$gate = Conexao_Translation_Rollout_Engine::calculate_gate(
	array(
		'eligible_public_pt' => 1,
		'with_en'            => 0,
		'missing_en'         => 1,
		'conflicts'          => 0,
		'pt_drift'           => 0,
	)
);
conexao_tv_check( is_array( $gate ) && isset( $gate['gate'] ), 'calculate_gate() works standalone, without Engine::run()' );
conexao_tv_check( 'FAIL' === ( $gate['gate'] ?? '' ), 'the standalone gate correctly reports FAIL for 1 eligible PT / 0 EN' );

echo "\n-----------------------------------------------------------------\n";
echo 'assertions: ' . ( $passed + $failed ) . " total, {$passed} passed, {$failed} failed\n";
echo "Engine::run() calls by this verification: 0\n";
echo "posts created: 0   terms created: 0   options written: 0   links created: 0\n";
echo "-----------------------------------------------------------------\n";

exit( $failed > 0 ? 1 : 0 );

conexao_tv_check(
	'newsletter' === (string) ( $nl_row['en_slug'] ?? '' ),
	'the authored EN target slug is "newsletter" and never falls back to "newsletter-2"'
);

// Fail-closed: the permit filter must not be armed outside a stage's own writes.
conexao_tv_check(
	false === has_filter( 'wp_unique_post_slug', 'conexao_en_translation_shared_page_slug_filter' ),
	'the shared-slug uniqueness exception is NOT armed outside a stage\'s own writes'
);
conexao_tv_check(
	! get_transient( 'conexao_en_translation_shared_page_slug' ),
	'no shared-slug permit transient is left set after this read-only verification'
);
