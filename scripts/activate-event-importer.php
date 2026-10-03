<?php
/**
 * One-off script to activate the Conexao Event Importer plugin.
 * Requires WordPress to be loaded. Run via: php scripts/activate-event-importer.php
 */

// Load WordPress core.
require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

if ( ! function_exists( 'activate_plugin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$result = activate_plugin( 'conexao-event-importer/conexao-event-importer.php' );

if ( is_wp_error( $result ) ) {
	echo 'ERROR: ' . $result->get_error_message() . "\n";
} else {
	echo "Plugin activated successfully\n";
}