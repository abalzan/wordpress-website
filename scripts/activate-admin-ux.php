<?php
/**
 * Activate the Conexão Admin UX plugin (and ensure all portal plugins active).
 *
 * Usage: docker compose exec wordpress php /var/www/html/scripts/activate-admin-ux.php
 */

$wp_load = '/var/www/html/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found. Run inside the WordPress container.\n" );
	exit( 1 );
}

require_once $wp_load;

$plugins = array(
	'conexao-data-model/conexao-data-model.php',
	'conexao-content/conexao-content.php',
	'conexao-event-importer/conexao-event-importer.php',
	'conexao-test-events/conexao-test-events.php',
	'conexao-admin-ux/conexao-admin-ux.php',
);

$active = get_option( 'active_plugins', array() );
if ( ! is_array( $active ) ) {
	$active = array();
}

$changed = false;
foreach ( $plugins as $plugin ) {
	if ( ! in_array( $plugin, $active, true ) ) {
		$active[] = $plugin;
		$changed  = true;
		echo "Activated: {$plugin}\n";
	} else {
		echo "Already active: {$plugin}\n";
	}
}

if ( $changed ) {
	update_option( 'active_plugins', $active );
	echo "Updated active_plugins.\n";
}

echo "Active plugins (" . count( $active ) . "): " . implode( ', ', $active ) . "\n";