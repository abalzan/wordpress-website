<?php
/**
 * One-off verification that the automation components of the event importer
 * load correctly in the live WordPress environment.
 *
 * Usage: wp eval-file scripts/verify-event-importer-automation.php
 */

$classes = array(
	'Conexao_Import_Settings',
	'Conexao_Source_Health',
	'Conexao_Import_Notifier',
	'Conexao_Source_Fetch_Exception',
	'Conexao_Import_Scheduler',
	'Conexao_Import_Rest',
	'Conexao_Event_Image_Sync_Scheduler',
	'Conexao_Event_Status',
	'Conexao_Event_Cleanup',
	'Conexao_Event_Importer_Engine',
);

echo "=== Class check ===
";
foreach ( $classes as $class ) {
	printf( "%-40s %s
", $class, class_exists( $class ) ? 'OK' : 'MISSING' );
}

echo "
=== Scheduler ===
";
$plugin   = Conexao_Event_Importer::instance();
$scheduler = $plugin->scheduler;
echo 'Next kickoff: ' . ( $scheduler->get_next_scheduled() ? gmdate( 'Y-m-d H:i:s', $scheduler->get_next_scheduled() ) . ' UTC' : 'NOT SCHEDULED' ) . "
";
echo 'Tick hook scheduled: ' . ( wp_next_scheduled( Conexao_Import_Scheduler::TICK_HOOK ) ? 'yes' : 'no' ) . "
";
echo 'Cleanup scheduled: ' . ( wp_next_scheduled( 'conexao_event_cleanup_cron' ) ? gmdate( 'Y-m-d H:i:s', wp_next_scheduled( 'conexao_event_cleanup_cron' ) ) . ' UTC' : 'NOT SCHEDULED' ) . "
";
echo 'Image sync scheduled: ' . ( wp_next_scheduled( 'conexao_event_image_sync_cron' ) ? gmdate( 'Y-m-d H:i:s', wp_next_scheduled( 'conexao_event_image_sync_cron' ) ) . ' UTC' : 'not yet (schedules on admin_init)' ) . "
";

echo "
=== Due sources ===
";
foreach ( $plugin->sources->get_active() as $source ) {
	printf(
		"%-20s freq=%-7s due=%s
",
		$source['id'],
		isset( $source['import_frequency'] ) ? $source['import_frequency'] : 'weekly',
		$scheduler->is_source_due( $source ) ? 'yes' : 'no'
	);
}

echo "
=== Settings ===
";
echo 'notify_email: ' . ( Conexao_Import_Settings::get_notify_email() ? 'set' : 'EMPTY' ) . "
";
echo 'auto_disable_after_failures: ' . Conexao_Import_Settings::get( 'auto_disable_after_failures' ) . "
";
echo 'cleanup_scope: ' . Conexao_Import_Settings::get( 'cleanup_scope' ) . "
";
echo 'rest_token: ' . ( Conexao_Import_Settings::get_rest_token() ? 'generated (' . strlen( Conexao_Import_Settings::get_rest_token() ) . ' chars)' : 'MISSING' ) . "
";

echo "
=== REST routes ===
";
$routes = rest_get_server()->get_routes();
foreach ( array_keys( $routes ) as $route ) {
	if ( 0 === strpos( $route, '/conexao-events' ) ) {
		echo $route . "
";
	}
}

echo "
=== WP-CLI ===
";
echo ( defined( 'WP_CLI' ) && WP_CLI ) ? "running under WP-CLI
" : "web context (CLI commands registered only under WP-CLI)
";

echo "
VERIFICATION COMPLETE
";