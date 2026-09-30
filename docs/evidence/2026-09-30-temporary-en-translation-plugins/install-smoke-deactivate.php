<?php
/**
 * Deactivate the two bind-mounted temporary plugins, saving the exact prior
 * `active_plugins` list. Step 1 of the installation smoke-test cycle.
 *
 * This exists so the smoke test can exercise the EXTRACTED artifact rather than
 * the Compose bind mount: with the mount deactivated, the only thing that can
 * supply the engine and the stage layer is the file extracted from the ZIP.
 *
 * The snapshot is written to a file under this directory so step 3 can restore
 * it even if step 2 dies. LOCAL DISPOSABLE ONLY — this touches the local
 * Compose database and nothing else.
 *
 * @package Conexao_BR_Temporary_Plugin_Evidence
 */

require_once '/var/www/html/tests/bootstrap.php';

$targets = array( 'conexao-translation-rollout/conexao-translation-rollout.php', 'conexao-en-translation/conexao-en-translation.php' );

$snapshot = (array) get_option( 'active_plugins' );
file_put_contents( '/tmp/temp-install-smoke.active_plugins', wp_json_encode( $snapshot ) );

$next = array_values( array_diff( $snapshot, $targets ) );
update_option( 'active_plugins', $next );

echo "active_plugins before: " . count( $snapshot ) . "\n";
echo "active_plugins after:  " . count( $next ) . "\n";
echo "deactivated:\n";
foreach ( $targets as $target ) {
	echo ( in_array( $target, $snapshot, true ) ? '  - ' : '  (already inactive) ' ) . $target . "\n";
}
echo "snapshot saved to /tmp/temp-install-smoke.active_plugins\n";
