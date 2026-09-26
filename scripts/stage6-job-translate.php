<?php
/**
 * Stage 6 — Job EN translation runner (WP-CLI, local/staging).
 *
 * Stage H: the lifecycle is owned by the shared rollout engine
 * (conexao-translation-rollout). This script is a thin local/staging driver
 * that asks the engine to run the `job` stage; it owns no orchestration.
 *
 * Usage (from the project root, local Docker):
 *   cat scripts/stage6-job-translate.php | docker compose exec -T WordPress wp eval-file - --allow-root
 *   cat scripts/stage6-job-translate.php | docker compose exec -T WordPress wp eval-file - --allow-root -- dry-run
 *   cat scripts/stage6-job-translate.php | docker compose exec -T WordPress wp eval-file - --allow-root -- dry-run json
 *
 * LOCAL / STAGING ONLY — never point this at production (the WordPress.com
 * deployment uses the shared admin screen, Tools → Translation Rollouts; see
 * docs/plugins/conexao-translation-rollout.md).
 *
 * @package Conexao_Job_Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__ ) . '/wp-load.php';
}

$argv_args = isset( $args ) ? (array) $args : array();
$dry_run   = (bool) array_intersect( array( 'dry-run', '--dry-run' ), $argv_args );
$json      = (bool) array_intersect( array( 'json', '--json' ), $argv_args );

// WP-CLI's eval-file passes positional tokens (flags would be parsed as unknown
// options), so `wp eval-file - dry-run json` is the supported invocation.

if ( ! class_exists( 'Conexao_Translation_Rollout_Engine' ) || ! function_exists( 'conexao_job_translation_engine_config' ) ) {
	echo "ERROR: the shared engine (conexao-translation-rollout) and the job stage (conexao-job-translation) must both be active.\n";
	return;
}

$config  = conexao_job_translation_engine_config();
$adapter = conexao_job_translation_engine_adapter();
$report  = Conexao_Translation_Rollout_Engine::run( $config, $adapter, array( 'dry_run' => $dry_run ) );

if ( is_wp_error( $report ) ) {
	echo 'ERROR: ' . $report->get_error_message() . "\n";
	return;
}

if ( $json ) {
	echo wp_json_encode(
		array(
			'mode'    => $dry_run ? 'dry-run' : 'apply',
			'stage'   => $config['stage'],
			'summary' => $report['summary'],
			'plan'    => $report['plan'],
			'rows'    => $report['rows'],
			'verify'  => $report['verify'],
			'gate'    => $report['gate'],
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	), "\n";
	return;
}

$s = $report['summary'];
$g = $report['gate'];

echo '== EN Job translation (shared engine) — ' . ( $dry_run ? "DRY RUN\n" : "APPLY\n" );
echo "jobs: created {$s['created']}, updated {$s['updated']}, skipped {$s['skipped']}, errors {$s['errors']}\n";
echo "meta fields copied: {$s['meta_copied']}\n";
echo "match strategy: stable-id {$s['match']['stable-id']}, slug fallback {$s['match']['slug']}, title fallback {$s['match']['title']}\n";
echo "PT sources changed (must be 0): {$s['pt_changed']}\n";

foreach ( $report['rows'] as $row ) {
	printf(
		"  %-40s → %-12s %s\n",
		(string) $row['stable_key'],
		(string) $row['action'],
		(string) $row['message']
	);
}

echo "\n-- numeric gate --\n";
printf( "  %-42s %d\n", 'eligible public PT jobs', $g['eligible_public_pt'] );
printf( "  %-42s %d\n", 'with EN translation', $g['with_en'] );
printf( "  %-42s %d\n", 'missing EN (gate condition)', $g['missing_en'] );
printf( "  %-42s %d\n", 'conflicts', $g['conflicts'] );
printf( "  %-42s %d\n", 'PT drift', $g['pt_drift'] );
printf( "  %-42s %d\n", 'extra gate failures', $g['extra_failures'] );
echo '  GATE: ' . $g['gate'] . "\n";
