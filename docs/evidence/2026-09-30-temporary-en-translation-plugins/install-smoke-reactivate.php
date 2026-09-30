<?php
/**
 * Restore the exact `active_plugins` snapshot taken by
 * install-smoke-deactivate.php. Step 3 of the installation smoke-test cycle.
 *
 * Run unconditionally by the harness, whether step 2 passed or failed, so the
 * local Compose site is always left exactly as it was found. The result is
 * verified, not assumed: the restored value is compared to the saved snapshot
 * and any difference is reported.
 *
 * LOCAL DISPOSABLE ONLY.
 *
 * @package Conexao_BR_Temporary_Plugin_Evidence
 */

require_once '/var/www/html/tests/bootstrap.php';

$file = '/tmp/temp-install-smoke.active_plugins';

if ( ! is_readable( $file ) ) {
	echo "ERROR: no snapshot at {$file}; the local site may be left deactivated.\n";
	exit( 1 );
}

$snapshot = json_decode( (string) file_get_contents( $file ), true );
if ( ! is_array( $snapshot ) ) {
	echo "ERROR: the snapshot is unreadable; refusing to guess the prior state.\n";
	exit( 1 );
}

update_option( 'active_plugins', $snapshot );
$restored = (array) get_option( 'active_plugins' );

echo "snapshot entries: " . count( $snapshot ) . "\n";
echo "restored entries: " . count( $restored ) . "\n";
echo 'exact match: ' . ( $restored === array_values( $snapshot ) || $restored === $snapshot ? 'YES' : 'NO' ) . "\n";

sort( $restored );
foreach ( $restored as $plugin ) {
	echo "  - {$plugin}\n";
}

@unlink( $file );

exit( $restored === $snapshot ? 0 : 1 );
