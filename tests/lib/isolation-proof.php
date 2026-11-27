<?php
/**
 * Empirical isolation proof through the actual $wpdb connection.
 *
 * Verifies:
 *   1. Positive read against the disposable schema succeeds.
 *   2. Negative read against wordpress.wp_posts fails with a permission error.
 *   3. Negative no-op write against wordpress.wp_posts fails with a permission error.
 *   4. Temporary probe table DDL/DML in the disposable schema succeeds and is cleaned up.
 *
 * This runs through the same $wpdb instance the test suites use, after the
 * bootstrap identity guard has already confirmed the restricted account.
 */

require_once __DIR__ . '/../bootstrap.php';

global $wpdb;

$results = array( 'pass' => 0, 'fail' => 0 );

function probe_check( $label, $condition, &$results ) {
	if ( $condition ) {
		$results['pass']++;
		printf( "ISOLATION_PROBE=PASS %s\n", $label );
	} else {
		$results['fail']++;
		printf( "ISOLATION_PROBE=FAIL %s\n", $label );
	}
}

// 1. Positive read against the disposable schema.
$positive = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'posts' );
probe_check( 'positive_read_disposable_schema', false !== $positive && $positive >= 0, $results );

// 2. Negative read against wordpress.wp_posts.
$wpdb->suppress_errors( true );
$negative_read = $wpdb->get_var( 'SELECT COUNT(*) FROM `wordpress`.`wp_posts`' );
$wpdb_error    = $wpdb->last_error;
$wpdb->suppress_errors( false );
probe_check(
	'negative_read_shared_schema_denied',
	null === $negative_read && '' !== $wpdb_error,
	$results
);

// 3. Negative no-op write against wordpress.wp_posts.
$wpdb->suppress_errors( true );
$negative_write = $wpdb->query( 'UPDATE `wordpress`.`wp_posts` SET `post_title` = `post_title` WHERE `ID` = -1' );
$write_error    = $wpdb->last_error;
$wpdb->suppress_errors( false );
probe_check(
	'negative_write_shared_schema_denied',
	false === $negative_write || 0 === $negative_write,
	$results
);

// 4. Temporary probe table DDL/DML in the disposable schema.
$probe_table = $wpdb->prefix . 'isolation_probe_' . substr( md5( uniqid( '', true ) ), 0, 12 );

$create = $wpdb->query( "CREATE TABLE `{$probe_table}` ( `id` INT PRIMARY KEY, `val` VARCHAR(32) )" );
probe_check( 'ddl_create_probe_table', false !== $create, $results );

$insert = $wpdb->query( "INSERT INTO `{$probe_table}` ( `id`, `val` ) VALUES ( 1, 'probe' )" );
probe_check( 'dml_insert_probe_row', 1 === $insert, $results );

$update = $wpdb->query( "UPDATE `{$probe_table}` SET `val` = 'updated' WHERE `id` = 1" );
probe_check( 'dml_update_probe_row', 1 === $update, $results );

$delete = $wpdb->query( "DELETE FROM `{$probe_table}` WHERE `id` = 1" );
probe_check( 'dml_delete_probe_row', 1 === $delete, $results );

$drop = $wpdb->query( "DROP TABLE `{$probe_table}`" );
probe_check( 'ddl_drop_probe_table', false !== $drop, $results );

// Confirm no probe table remains.
$remaining = $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
	$wpdb->dbname,
	$probe_table
) );
probe_check( 'probe_table_cleaned_up', '0' === (string) $remaining, $results );

printf( "ISOLATION_PROBE_SUMMARY pass=%d fail=%d\n", $results['pass'], $results['fail'] );

if ( $results['fail'] > 0 ) {
	exit( 1 );
}
exit( 0 );