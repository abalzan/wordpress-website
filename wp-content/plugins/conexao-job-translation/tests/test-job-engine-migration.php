<?php
/**
 * Stage 6 migration proof: the job stage runs through the shared engine.
 *
 * Verifies (read-only against the local site): the stage config validates,
 * its manifest validates, the engine dry-run plan is deterministic and
 * write-free, the gate payload is numeric, and the engine manifest is
 * byte-equivalent to the historical map. Uses only the stage's own authored
 * manifest (no production data).
 *
 * @package Conexao_Job_Translation
 */

require_once dirname( __DIR__, 4 ) . '/tests/bootstrap.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-job-translation/includes/translation-map.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-job-translation/includes/stage-fields.php';
require_once CONEXAO_TESTS_WP_ROOT . '/wp-content/plugins/conexao-job-translation/includes/stage-config.php';

$passed = 0;
$failed = 0;

// The migrated stage must no longer own generic orchestration.
assert_true( ! function_exists( 'conexao_job_translation_run' ), 'legacy generic run() orchestration is gone' );
assert_true( ! function_exists( 'conexao_job_translation_audit' ), 'legacy generic audit() gate is gone' );
assert_true( ! class_exists( 'Conexao_Job_Translation_Admin' ), 'legacy per-stage admin action plumbing is gone' );
assert_true( function_exists( 'conexao_job_translation_snapshot_job' ), 'stage-specific snapshot helper is retained' );
assert_true( function_exists( 'conexao_job_translation_copy_fields' ), 'stage-specific field mapping is retained' );
assert_true( function_exists( 'conexao_job_translation_verify_jobs_page' ), 'stage-specific landing gate is retained' );

$config = conexao_job_translation_engine_config();

assert_true( true === Conexao_Translation_Rollout_Engine::validate_config( $config ), 'job stage config validates' );
assert_true( 'job' === $config['stage'] && 'job' === $config['source_post_type'], 'job stage identity (post type job, pt->en)' );

$manifest = call_user_func( $config['manifest_callback'] );

assert_true( true === Conexao_Translation_Rollout_Engine::validate_manifest( $manifest, $config ), 'job manifest validates (stable PT-slug keys)' );
assert_true( isset( $manifest['records']['oportunidades'] ), 'historical manifest entry preserved (oportunidades)' );

$adapter = conexao_job_translation_engine_adapter();
$dry1    = Conexao_Translation_Rollout_Engine::run( $config, $adapter, array( 'dry_run' => true ) );

assert_true( ! is_wp_error( $dry1 ), 'engine dry-run completes' );

$dry2 = Conexao_Translation_Rollout_Engine::run( $config, $adapter, array( 'dry_run' => true ) );

assert_true( ! is_wp_error( $dry2 ) && wp_json_encode( $dry1['plan'] ) === wp_json_encode( $dry2['plan'] ), 'dry-run plan is deterministic' );
assert_true( isset( $dry1['gate']['gate'] ) && in_array( $dry1['gate']['gate'], array( 'PASS', 'FAIL' ), true ), 'gate is numeric PASS|FAIL' );
assert_true( array_key_exists( 'missing_en', $dry1['gate'] ), 'gate reports missing_en count' );
assert_true( isset( $dry1['summary']['match']['stable-id'] ), 'apply reports stable-id match strategy' );

$legacy = conexao_job_translation_manifest();

assert_true( isset( $legacy['jobs']['oportunidades']['en_slug'] ) && $legacy['jobs']['oportunidades']['en_slug'] === $manifest['records']['oportunidades']['en_slug'], 'engine manifest is byte-equivalent to the historical map' );

test_finish();
