<?php
/**
 * DRY RUN of the retirement rule. Read-only: it never calls get_all() and never
 * writes an option. It applies Conexao_Event_Sources::RETIRED_WINDOW_SOURCE_PATTERN
 * to the PERSISTED registry in memory and reports what a write would remove.
 */
require_once '/var/www/html/tests/bootstrap.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-event-sources.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/includes/class-county-registry.php';

$stored = get_option( Conexao_Event_Sources::OPTION_KEY, array() );
echo 'stored_sources=' . ( is_array( $stored ) ? count( $stored ) : -1 ) . "\n";

$would_remove = array();
if ( is_array( $stored ) ) {
	foreach ( array_keys( $stored ) as $id ) {
		if ( preg_match( Conexao_Event_Sources::RETIRED_WINDOW_SOURCE_PATTERN, (string) $id ) ) {
			$would_remove[] = $id;
		}
	}
}
echo 'would_remove=' . count( $would_remove ) . "\n";
echo 'would_remove_ids=' . implode( ',', $would_remove ) . "\n";

// What the registry itself generates after the change (pure computation).
$registry = Conexao_County_Registry::get_all_county_sources();
$generated_windows = array();
foreach ( array_keys( $registry ) as $id ) {
	if ( preg_match( Conexao_Event_Sources::RETIRED_WINDOW_SOURCE_PATTERN, (string) $id ) ) {
		$generated_windows[] = $id;
	}
}
echo 'registry_generates=' . count( $registry ) . "\n";
echo 'registry_window_sources=' . count( $generated_windows ) . "\n";

echo "writes_performed=0\n";
