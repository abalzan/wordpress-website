<?php
require_once '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$sources = new Conexao_Event_Sources();
$all = get_option( Conexao_Event_Sources::OPTION_KEY, array() );

if (isset($all['ivvcc'])) {
    $all['ivvcc']['status'] = 'active';
    $all['ivvcc']['crawl_delay'] = 1;  // Override for faster testing
    update_option( Conexao_Event_Sources::OPTION_KEY, $all, false);
    echo "IVVCC activated with crawl_delay=1\n";
    echo "Status: " . $all['ivvcc']['status'] . "\n";
    echo "Crawl delay: " . $all['ivvcc']['crawl_delay'] . "\n";
} else {
    echo "IVVCC source not found\n";
}
