<?php
/**
 * Cleanup obsolete sponsor meta fields.
 *
 * This script removes the following meta fields from sponsor posts:
 * - Social media: _sponsor_website, _sponsor_instagram, _sponsor_facebook, _sponsor_linkedin, _sponsor_whatsapp
 * - Contact info: _sponsor_email, _sponsor_phone, _sponsor_address, _sponsor_location
 *
 * Run this script once via WP-CLI or by loading it in a browser while logged in as admin.
 *
 * Usage:
 *   wp eval-file scripts/cleanup-sponsor-fields.php
 *
 * Or visit: your-site.local/wp-content/plugins/conexao-admin-ux/scripts/cleanup-sponsor-fields.php (while logged in as admin)
 *
 * @package Conexao_Admin_Ux
 */

// Allow running via WP-CLI or browser.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	// Running via WP-CLI - WordPress is already loaded.
} elseif ( file_exists( dirname( __FILE__, 4 ) . '/wp-load.php' ) ) {
	require_once dirname( __FILE__, 4 ) . '/wp-load.php';
} else {
	die( 'Could not find wp-load.php' );
}

// Only allow admins to run this.
if ( ! current_user_can( 'manage_options' ) ) {
	die( 'Unauthorized access' );
}

// Meta keys to delete from sponsor posts.
$sponsor_meta_to_delete = array(
	// Social media fields.
	'_sponsor_website',
	'_sponsor_instagram',
	'_sponsor_facebook',
	'_sponsor_linkedin',
	'_sponsor_whatsapp',
	// Contact fields.
	'_sponsor_email',
	'_sponsor_phone',
	'_sponsor_address',
	'_sponsor_location',
);

// Get all sponsor posts.
$sponsors = get_posts( array(
	'post_type'      => 'sponsor',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
) );

if ( empty( $sponsors ) ) {
	echo "No sponsor posts found.\n";
	exit;
}

echo sprintf( "Found %d sponsor posts.\n", count( $sponsors ) );

$deleted_count = 0;

foreach ( $sponsors as $sponsor_id ) {
	foreach ( $sponsor_meta_to_delete as $meta_key ) {
		$value = get_post_meta( $sponsor_id, $meta_key, true );
		if ( '' !== $value && false !== $value ) {
			delete_post_meta( $sponsor_id, $meta_key );
			$deleted_count++;
			echo sprintf( "Deleted %s from post %d\n", $meta_key, $sponsor_id );
		}
	}
}

echo sprintf( "\nCleanup complete. Deleted %d meta values from %d sponsor posts.\n", $deleted_count, count( $sponsors ) );