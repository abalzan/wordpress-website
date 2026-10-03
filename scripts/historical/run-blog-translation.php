<?php
/**
 * Stage 5 — Blog EN translation runner (WP-CLI, local/staging).
 *
 * Usage (from the project root, local Docker):
 *   cat scripts/run-blog-translation.php | docker compose exec -T wordpress wp eval-file - --allow-root
 *   cat scripts/run-blog-translation.php | docker compose exec -T wordpress wp eval-file - --allow-root -- --dry-run
 *   ... -- --dry-run --json   (machine-readable report)
 *
 * LOCAL / STAGING ONLY — never point this at production (the WordPress.com
 * deployment uses the admin screen of the same plugin; see
 * docs/plugins/conexao-blog-translation.md).
 *
 * @package Conexao_Blog_Translation
 *
 * HISTORICAL — NOT SUPPORTED TOOLING (Stage 19).
 *
 * The rollout plugin this script drove was removed from the active repository
 * in Stage 19, because its authored translation data had already been
 * consolidated into the shared translation stages of `conexao-en-translation`.
 * This script therefore CANNOT run: the functions it calls no longer exist.
 * It is kept only for provenance, alongside `scripts/historical/stage5-blog-inventory.php`
 * and the Stage 5/6/7/9 reports that describe the architecture as it was.
 * The supported manual translation workflow is the operator runbook
 * (`docs/translation-manual-operator-runbook.md`) and the seven stages of
 * `conexao-en-translation`. Historical provenance is also recoverable from
 * Git history at the commit that removed the retired plugins.
 *
 */

require_once __DIR__ . '/../lib/bootstrap.php';
conexao_script_load_wordpress();

$argv_args = isset( $args ) ? (array) $args : array();
$dry_run   = (bool) array_intersect( array( 'dry-run', '--dry-run' ), $argv_args );
$json      = (bool) array_intersect( array( 'json', '--json' ), $argv_args );

// WP-CLI's eval-file passes positional tokens (flags would be parsed as unknown
// options), so `wp eval-file - dry-run json` is the supported invocation.

if ( ! function_exists( 'conexao_blog_translation_run' ) ) {
	echo "ERROR: activated plugin conexao-blog-translation is required.\n";
	return;
}

$report = conexao_blog_translation_run( array( 'dry_run' => $dry_run ) );

if ( $json ) {
	echo wp_json_encode(
		array(
			'mode'    => $dry_run ? 'dry-run' : 'apply',
			'summary' => $report['summary'],
			'rows'    => $report['rows'],
			'terms'   => $report['terms'],
			'excluded' => $report['excluded'],
			'audit'   => $report['audit'],
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	), "\n";
	return;
}

$s = $report['summary'];

echo '== EN Blog translation — ' . ( $dry_run ? "DRY RUN\n" : "APPLY\n" );
echo "posts: created {$s['created']}, updated {$s['updated']}, skipped {$s['skipped']}, errors {$s['errors']}\n";
echo "category terms: created {$s['terms_created']}, linked {$s['terms_linked']}, skipped {$s['terms_skipped']}\n";
echo "internal links localized: {$s['links_localized']}\n";
echo "posts page: {$s['posts_page']}\n";
echo "PT sources changed (must be 0): {$s['pt_changed']}\n";

foreach ( $report['rows'] as $row ) {
	printf(
		"  %-60s → %-12s %s\n",
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
