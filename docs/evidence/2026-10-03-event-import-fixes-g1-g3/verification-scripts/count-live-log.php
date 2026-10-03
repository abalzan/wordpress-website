<?php
require_once '/var/www/html/tests/bootstrap.php';
$l = get_option( 'conexao_event_import_log', array() );
echo 'LIVE_COUNT=' . count( $l ) . "\n";
if ( ! empty( $l ) ) { echo 'LIVE_FIRST=' . $l[ count( $l ) - 1 ]['message'] . "\n"; }
