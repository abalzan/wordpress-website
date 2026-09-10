<?php
/**
 * Stage B dry-run validation for the IVVCC source (sections 36-37).
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/dry-run-ivvcc.php
 *
 * Temporarily enables the ivvcc source in the LOCAL source config, runs the
 * shared engine with dry_run = true (fetch -> normalize -> date filter ->
 * dedupe -> report; no posts/taxonomies/images touched), then restores the
 * previous source status.
 *
 * @package Conexao_Event_Importer
 */

$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
if ( file_exists( $wp_load ) ) {
	require_once $wp_load;
} else {
	require_once '/var/www/html/wp-load.php';
}

require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$sources_manager = new Conexao_Event_Sources();
$all             = get_option( Conexao_Event_Sources::OPTION_KEY, array() );
$prev_status     = isset( $all['ivvcc']['status'] ) ? $all['ivvcc']['status'] : 'missing';

if ( ! isset( $all['ivvcc'] ) ) {
	$all['ivvcc']             = $sources_manager->get_defaults()['ivvcc'];
	$all['ivvcc']['id']       = 'ivvcc';
	$prev_status              = 'missing';
}
$all['ivvcc']['status'] = 'active';
update_option( Conexao_Event_Sources::OPTION_KEY, $all, false );

$result = Conexao_Event_Importer::instance()->importer->run_source( 'ivvcc', true );

// Restore the previous status (the source ships inactive).
$all = get_option( Conexao_Event_Sources::OPTION_KEY, array() );
if ( 'missing' === $prev_status ) {
	unset( $all['ivvcc'] );
} else {
	$all['ivvcc']['status'] = $prev_status;
}
update_option( Conexao_Event_Sources::OPTION_KEY, $all, false );

echo "IVVCC DRY-RUN (no posts/taxonomies/images written)\n";
echo 'status:    ' . ( isset( $result['status'] ) ? $result['status'] : '?' ) . "\n";
echo 'found:     ' . (int) $result['found'] . "\n";
echo 'created:   ' . (int) $result['created'] . " (dry-run: reported, not written)\n";
echo 'updated:   ' . (int) $result['updated'] . "\n";
echo 'unchanged: ' . (int) $result['unchanged'] . "\n";
echo 'skipped:   ' . (int) $result['skipped'] . "\n";
echo 'failed:    ' . (int) $result['failed'] . "\n\n";

if ( ! empty( $result['event_results'] ) ) {
	foreach ( $result['event_results'] as $event_result ) {
		printf( "%s — %s — %s\n", strtoupper( $event_result['outcome'] ), $event_result['title'], isset( $event_result['message'] ) ? $event_result['message'] : '' );
	}
}

echo "\nSource status restored to: {$prev_status}\n";
