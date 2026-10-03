<?php
/**
 * Verify the Conexão Admin UX plugin classes load and work.
 *
 * Usage: docker compose exec wordpress php /var/www/html/scripts/verify-admin-ux.php
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();
require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();echo "=== Admin UX Plugin Verification ===\n\n";

// 1. Plugin active?
$active = get_option( 'active_plugins', array() );
if ( ! is_array( $active ) ) {
	$active = array();
}
echo "Plugin active: " . ( in_array( 'conexao-admin-ux/conexao-admin-ux.php', $active, true ) ? 'YES' : 'NO' ) . "\n";

// 2. Config class loads.
if ( ! class_exists( 'Conexao_Admin_Ux_Config' ) ) {
	echo "FAIL: Conexao_Admin_Ux_Config class not found.\n";
	exit( 1 );
}
echo "Config class: OK\n";

// 3. All 5 content types have config.
foreach ( Conexao_Admin_Ux_Config::SUPPORTED_TYPES as $post_type ) {
	$config = Conexao_Admin_Ux_Config::get( $post_type );
	if ( $config ) {
		$sections = isset( $config['sections'] ) ? count( $config['sections'] ) : 0;
		$fields   = 0;
		foreach ( (array) $config['sections'] as $section ) {
			$fields += isset( $section['fields'] ) ? count( $section['fields'] ) : 0;
		}
		echo "  {$post_type}: " . count( $config['columns'] ) . " columns, {$sections} sections, {$fields} fields\n";
	} else {
		echo "  {$post_type}: MISSING CONFIG\n";
	}
}

// 4. Fields test: collect + validate.
$event_config = Conexao_Admin_Ux_Config::get( 'event' );
$fields       = Conexao_Admin_Ux_Fields::collect_fields( $event_config );
echo "Event fields collected: " . count( $fields ) . "\n";

$errors = Conexao_Admin_Ux_Fields::validate(
	$event_config,
	array(
		'conexao_fields' => array(
			'event_title' => '',
			'event_date'  => '',
		),
	)
);
echo "Validation test (empty required): " . count( $errors ) . " errors\n";
foreach ( $errors as $error ) {
	echo "  - {$error}\n";
}

// 5. Sanitization.
$date = Conexao_Admin_Ux_Fields::sanitize_date( '20/08/2026' );
echo "Sanitize date '20/08/2026' => {$date}\n";
$time = Conexao_Admin_Ux_Fields::sanitize_time( '10:00 PM' );
echo "Sanitize time '10:00 PM' => {$time}\n";

// 6. Actions: status meta key.
foreach ( Conexao_Admin_Ux_Config::SUPPORTED_TYPES as $post_type ) {
	echo "  Status meta for {$post_type}: " . Conexao_Admin_Ux_Actions::status_meta_key( $post_type ) . "\n";
}

// 7. Verify laois towns.
echo "Laois towns count: " . count( Conexao_Admin_Ux_Config::laois_towns() ) . "\n";
echo "Counties count: " . count( Conexao_Admin_Ux_Config::counties() ) . "\n";

echo "\n=== Verification complete ===\n";