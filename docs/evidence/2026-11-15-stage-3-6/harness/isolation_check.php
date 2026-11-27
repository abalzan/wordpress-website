<?php
/**
 * Stage 3.6 - isolation proof for the standalone harness.
 * Proves that loading the harness performs no WordPress bootstrap and
 * registers no lifecycle hook.
 */
require_once '/tmp/stage36/lib.php';

$checks = array();
$checks['ABSPATH defined (scratch constant only)'] = defined( 'ABSPATH' );
$checks['WordPress core NOT loaded (WPINC undefined)'] = ! defined( 'WPINC' );
$checks['WordPress core NOT loaded (wp_version undefined)'] = ! defined( 'wp_version' );
$checks['add_action() NOT available (no plugin API)'] = ! function_exists( 'add_action' );
$checks['do_action() NOT available'] = ! function_exists( 'do_action' );
$checks['apply_filters() NOT available'] = ! function_exists( 'apply_filters' );
$checks['wp_remote_get() NOT available'] = ! function_exists( 'wp_remote_get' );
$checks['get_option() NOT available'] = ! function_exists( 'get_option' );
$checks['wpdb class NOT available'] = ! class_exists( 'wpdb' );
$checks['Conexao_Eventbrite_Parser available (isolated)'] = class_exists( 'Conexao_Eventbrite_Parser' );
$checks['Conexao_Eventbrite_Client NOT loaded'] = ! class_exists( 'Conexao_Eventbrite_Client' );
$checks['Conexao_Source_Eventbrite NOT loaded'] = ! class_exists( 'Conexao_Source_Eventbrite' );
$checks['Conexao_Import_Continuation NOT loaded'] = ! class_exists( 'Conexao_Import_Continuation' );
$checks['Conexao_Import_Log NOT loaded'] = ! class_exists( 'Conexao_Import_Log' );

$bad = array();
foreach ( get_included_files() as $f ) {
	foreach ( array( 'wp-load.php', 'wp-settings.php', 'wp-config.php', 'conexao-event-importer.php', 'class-eventbrite-client.php', 'sources/class-eventbrite-source.php', 'class-conexao-import-continuation.php' ) as $needle ) {
		if ( false !== strpos( $f, $needle ) ) {
			$bad[] = $f;
		}
	}
}
$checks['No forbidden file in get_included_files()'] = empty( $bad );

$fail = 0;
foreach ( $checks as $label => $ok ) {
	printf( "%-58s %s\n", $label, $ok ? 'PASS' : 'FAIL' );
	if ( ! $ok ) { $fail++; }
}
echo "\nIncluded files:\n";
foreach ( get_included_files() as $f ) { echo '  ', $f, "\n"; }
echo "\nForbidden includes: ", $bad ? implode( ',', $bad ) : '(none)', "\n";
echo "Registered hooks: none possible (no plugin API function exists)\n";
echo $fail ? "\nISOLATION: FAIL\n" : "\nISOLATION: PASS\n";
exit( $fail ? 1 : 0 );
