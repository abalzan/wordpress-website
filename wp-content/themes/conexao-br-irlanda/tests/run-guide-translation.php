<?php
/**
 * Stage 9 — local runner for the Guide EN translation plugin.
 *
 * Usage (from the project root):
 *   docker compose exec -T wordpress php \
 *     /var/www/html/wp-content/themes/conexao-br-irlanda/tests/run-guide-translation.php
 *   ... dry-run
 *   ... dry-run json
 *
 * LOCAL ONLY. Production (WordPress.com, no WP-CLI) uses the plugin admin
 * screen: Tools -> EN Guide Translations.
 *
 * @package conexao-br-irlanda
 */

$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

$wp_load = '/var/www/html/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found. Run inside the WordPress container.\n" );
	exit( 1 );
}
require_once $wp_load;

if ( ! defined( 'CONEXAO_GUIDE_TRANSLATION_DIR' ) ) {
	$plugin = WP_PLUGIN_DIR . '/conexao-guide-translation/conexao-guide-translation.php';
	if ( ! file_exists( $plugin ) ) {
		echo "ERROR: plugin directory not found.\n";
		exit( 1 );
	}
	require_once $plugin;
}

$argv_args = ( isset( $argv ) && is_array( $argv ) ) ? array_slice( $argv, 1 ) : array();
$dry_run   = (bool) array_intersect( array( 'dry-run', '--dry-run' ), $argv_args );
$json      = (bool) array_intersect( array( 'json', '--json' ), $argv_args );

if ( ! function_exists( 'conexao_guide_translation_run' ) ) {
	echo "ERROR: activate the conexao-guide-translation plugin first.\n";
	exit( 1 );
}

$report = conexao_guide_translation_run( array( 'dry_run' => $dry_run ) );

if ( $json ) {
	echo wp_json_encode(
		array(
			'mode'    => $dry_run ? 'dry-run' : 'apply',
			'summary' => $report['summary'],
			'rows'    => $report['rows'],
			'audit'   => $report['audit'],
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	), "\n";
	exit( 0 );
}

$s = $report['summary'];
echo '== EN Guide translation — ' . ( $dry_run ? 'DRY RUN' : 'APPLY' ) . "\n";
echo "guides: created {$s['created']}, updated {$s['updated']}, skipped {$s['skipped']}, errors {$s['errors']}\n";
echo "terms: created {$s['terms_created']}, linked {$s['terms_linked']}\n";
echo "PT changed (must be 0): {$s['pt_changed']}\n";
foreach ( $report['rows'] as $row ) {
	printf( "  %-48s -> %-12s %s\n", $row['pt_slug'], $row['action'], $row['message'] );
}
$audit = $report['audit'];
if ( ! empty( $audit['counts'] ) ) {
	echo "\n-- completeness audit --\n";
	foreach ( $audit['counts'] as $label => $value ) {
		printf( "  %-42s %s\n", $label, is_scalar( $value ) ? $value : wp_json_encode( $value ) );
	}
	echo '  GATE: ' . ( $audit['pass'] ? "PASS\n" : "FAIL\n" );
	if ( ! empty( $audit['missing'] ) ) {
		echo "  missing EN:\n";
		foreach ( $audit['missing'] as $row ) {
			printf( "    - %s (#%d)\n", $row['slug'], $row['id'] );
		}
	}
}
