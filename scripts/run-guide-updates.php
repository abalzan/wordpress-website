<?php
/**
 * Standalone script to update guide content.
 * Run from the WordPress root directory.
 *
 * Usage: php /var/www/html/wp-content/../scripts/run-guide-updates.php
 * or: docker exec wordpress-website-wordpress-1 php /var/www/html/scripts/run-guide-updates.php
 */

// Bootstrap WordPress.
require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

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