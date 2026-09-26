<?php
/**
 * Verify the event export/import functionality.
 *
 * Usage: docker compose exec wordpress php /var/www/html/scripts/verify-event-transfer.php
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();
require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();echo "=== Event Transfer Verification ===\n\n";

// 1. Plugin active?
$active = get_option( 'active_plugins', array() );
if ( ! is_array( $active ) ) {
	$active = array();
}
echo "Plugin active: " . ( in_array( 'conexao-event-importer/conexao-event-importer.php', $active, true ) ? 'YES' : 'NO' ) . "\n";

// 2. New classes load.
$classes = array(
	'Conexao_Event_Export',
	'Conexao_Event_Import',
	'Conexao_Event_Transfer_Admin',
);
foreach ( $classes as $class ) {
	echo "Class {$class}: " . ( class_exists( $class ) ? 'OK' : 'MISSING' ) . "\n";
}

// 3. Export class works.
$exporter = new Conexao_Event_Export();
$count    = $exporter->count_events();
echo "\nEvents available for export: {$count}\n";

$payload = $exporter->build_export();
echo "Export format: {$payload['manifest']['format']}\n";
echo "Export version: {$payload['manifest']['version']}\n";
echo "Events in payload: " . count( $payload['events'] ) . "\n";

if ( count( $payload['events'] ) > 0 ) {
	$first = $payload['events'][0];
	echo "First event UUID: " . ( isset( $first['uuid'] ) ? $first['uuid'] : 'MISSING' ) . "\n";
	echo "First event title: " . ( isset( $first['post']['title'] ) ? $first['post']['title'] : 'MISSING' ) . "\n";
	echo "First event meta keys: " . count( $first['meta'] ) . "\n";
	echo "First event taxonomies: " . implode( ', ', array_keys( $first['taxonomies'] ) ) . "\n";
}

// 4. Import class works.
$importer = new Conexao_Event_Import();
echo "\nImport class: OK\n";

// 5. Admin menu pages registered.
echo "\nAdmin menu pages:\n";
global $submenu;
if ( isset( $submenu['conexao-event-import'] ) ) {
	foreach ( $submenu['conexao-event-import'] as $item ) {
		echo "  - {$item[0]} (slug: {$item[2]})\n";
	}
} else {
	echo "  WARNING: conexao-event-import menu not found. Menu may not be registered yet.\n";
}

// 6. Admin post actions registered.
echo "\nAdmin post actions:\n";
echo "  conexao_export_events: " . ( has_action( 'admin_post_conexao_export_events' ) ? 'registered' : 'NOT registered' ) . "\n";
echo "  conexao_import_events: " . ( has_action( 'admin_post_conexao_import_events' ) ? 'registered' : 'NOT registered' ) . "\n";

echo "\n=== Verification complete ===\n";
