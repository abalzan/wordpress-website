<?php
/**
 * One-off script to activate the Conexao Course Importer plugin.
 * Requires WordPress to be loaded. Run via: php scripts/activate-course-importer.php
 */

// Load WordPress core.
$wp_load = dirname( __DIR__ ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	// Inside Docker, wp-load.php is at /var/www/html/wp-load.php.
	require_once '/var/www/html/wp-load.php';
}

if ( ! function_exists( 'activate_plugin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$result = activate_plugin( 'conexao-course-importer/conexao-course-importer.php' );

if ( is_wp_error( $result ) ) {
	echo 'ERROR: ' . $result->get_error_message() . "\n";
} else {
	echo "Plugin activated successfully\n";
}