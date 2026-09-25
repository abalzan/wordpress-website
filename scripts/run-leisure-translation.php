<?php
/**
 * Stage 7 — EN Leisure description rollout runner (WP-CLI, local/staging).
 *
 * Usage (from the project root, local Docker):
 *   cat scripts/run-leisure-translation.php | docker compose exec -T wordpress wp eval-file - --allow-root
 *   ... preview (default) / apply / remove / audit / json
 *
 * LOCAL / STAGING ONLY — never point this at production (the WordPress.com
 * deployment uses the admin screen of the same plugin; see
 * docs/plugins/conexao-leisure-translation.md).
 *
 * @package Conexao_Leisure_Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__ ) . '/wp-load.php';
}

$argv_args = isset( $args ) ? (array) $args : array();
$mode      = 'preview';
foreach ( array( 'preview', 'apply', 'remove', 'audit' ) as $candidate ) {
	if ( in_array( $candidate, $argv_args, true ) ) {
		$mode = $candidate;
		break;
	}
}
$json = (bool) array_intersect( array( 'json', '--json' ), $argv_args );

if ( $json ) {
	if ( 'audit' === $mode ) {
		echo wp_json_encode( conexao_leisure_translation_audit(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
		return;
	}

	$report = conexao_leisure_translation_run( $mode );
	echo wp_json_encode(
		array(
			'mode'    => $mode,
			'summary' => $report['summary'],
			'rows'    => $report['rows'],
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	), "\n";
	return;
}

echo "== Stage 7 — EN Leisure descriptions ({$mode}) ==\n";

if ( 'audit' === $mode ) {
	$audit = conexao_leisure_translation_audit();
	foreach ( $audit['counts'] as $label => $value ) {
		echo str_pad( (string) $label, 52 ) . $value . "\n";
	}
	echo $audit['pass'] ? "GATE: PASS\n" : "GATE: FAIL — missing EN descriptions above\n";
	return;
}

$report = conexao_leisure_translation_run( $mode );
$s      = $report['summary'];

echo str_pad( 'entries', 20 ) . $s['entries'] . "\n";
echo str_pad( 'applied', 20 ) . $s['applied'] . "\n";
echo str_pad( 'removed', 20 ) . $s['removed'] . "\n";
echo str_pad( 'skipped-identical', 20 ) . $s['skipped_identical'] . "\n";
echo str_pad( 'refused', 20 ) . $s['refused'] . "\n";
echo str_pad( 'errors', 20 ) . $s['errors'] . "\n";
echo str_pad( 'PT sources changed', 20 ) . $s['pt_changed'] . "\n";
echo str_pad( 'UUID fields changed', 20 ) . $s['uuid_changed'] . "\n";

if ( $s['refused'] || $s['errors'] ) {
	echo "\nNon-clean rows:\n";
	foreach ( $report['rows'] as $row ) {
		if ( in_array( $row['action'], array( 'error', 'refused-pt-drift' ), true ) ) {
			echo "  {$row['action']}: {$row['slug']} — {$row['message']}\n";
		}
	}
}
