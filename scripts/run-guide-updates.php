<?php
/**
 * Standalone script to update guide content.
 * Run from the WordPress root directory.
 *
 * Usage: php /var/www/html/wp-content/../scripts/run-guide-updates.php
 * or: docker exec wordpress-website-wordpress-1 php /var/www/html/scripts/run-guide-updates.php
 */

// Bootstrap WordPress.
$wp_load = '/var/www/html/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	// Try relative path.
	$wp_load = dirname( __DIR__ ) . '/wp-load.php';
}
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "Cannot find wp-load.php\n" );
	exit( 1 );
}
require_once $wp_load;

// Include the guide update script.
$update_script = __DIR__ . '/update-guides-content.php';
if ( ! file_exists( $update_script ) ) {
	fwrite( STDERR, "Cannot find update-guides-content.php\n" );
	exit( 1 );
}

// Define WP_CLI class if not defined (the update script uses WP_CLI::log).
if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		public static function log( $message ) {
			echo $message . "\n";
		}
		public static function success( $message ) {
			echo "SUCCESS: " . $message . "\n";
		}
	}
}

require_once $update_script;