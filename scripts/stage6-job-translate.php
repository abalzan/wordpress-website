<?php
/**
 * Stage 6 — Job EN translation runner (WP-CLI, local/staging).
 *
 * Usage (from the project root, local Docker):
 *   cat scripts/stage6-job-translate.php | docker compose exec -T wordpress wp eval-file - --allow-root
 *   cat scripts/stage6-job-translate.php | docker compose exec -T wordpress wp eval-file - --allow-root -- dry-run
 *   cat scripts/stage6-job-translate.php | docker compose exec -T wordpress wp eval-file - --allow-root -- dry-run json
 *
 * LOCAL / STAGING ONLY — never point this at production (the WordPress.com
 * deployment uses the admin screen of the same plugin; see
 * docs/plugins/conexao-job-translation.md).
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

if ( ! function_exists( 'conexao_job_translation_run' ) ) {
	echo "ERROR: activated plugin conexao-job-translation is required.\n";
	return;
}

$report = conexao_job_translation_run( array( 'dry_run' => $dry_run ) );

if ( $json ) {
	echo wp_json_encode(
		array(
			'mode'      => $dry_run ? 'dry-run' : 'apply',
			'summary'   => $report['summary'],
			'rows'      => $report['rows'],
			'excluded'  => $report['excluded'],
			'jobs_page' => $report['jobs_page'],
			'audit'     => $report['audit'],
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	), "\n";
	return;
}

$s = $report['summary'];

echo '== EN Job translation — ' . ( $dry_run ? "DRY RUN\n" : "APPLY\n" );
echo "jobs: created {$s['created']}, updated {$s['updated']}, skipped {$s['skipped']}, errors {$s['errors']}\n";
echo "meta fields copied: {$s['meta_copied']}\n";
echo "jobs page: {$s['jobs_page']}\n";
echo "PT sources changed (must be 0): {$s['pt_changed']}\n";

foreach ( $report['rows'] as $row ) {
	printf(
		"  %-40s → %-12s %s\n",
		$row['pt_slug'],
		$row['action'],
		$row['message']
	);
}

$audit = $report['audit'];
if ( ! empty( $audit['counts'] ) ) {
	echo "\n-- completeness audit --\n";
	foreach ( $audit['counts'] as $label => $value ) {
		printf( "  %-42s %s\n", $label, is_scalar( $value ) ? $value : wp_json_encode( $value ) );
	}
	echo '  GATE: ' . ( $audit['pass'] ? "PASS\n" : "FAIL\n" );

	if ( ! empty( $audit['missing'] ) ) {
		echo "  missing EN translations:\n";
		foreach ( $audit['missing'] as $row ) {
			printf( "    - %s (#%d)\n", $row['slug'], $row['id'] );
		}
	}
}
