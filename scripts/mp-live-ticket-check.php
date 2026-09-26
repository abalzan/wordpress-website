<?php
/**
 * mp-live-ticket-check.php — live ticket-availability probe for events.
 *
 * Purpose: report, per event, whether the linked ticket page still responds.
 * Safety: read-only. It performs HTTP GETs to third-party ticket sites and
 * writes nothing to WordPress.
 * Scope: reports ticket reachability for the events it queries. It creates,
 * updates and deletes nothing, in WordPress or elsewhere.
 *
 * @package Conexao_BR_Scripts
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

if ( ! class_exists( 'Mp_Live_Ticket_Check' ) ) {
	class Mp_Live_Ticket_Check extends Conexao_Source_Mondello_Park {
		public function check( $html ) {
			$doc = new DOMDocument();
			libxml_use_internal_errors( true );
			$doc->loadHTML( $html );
			$xpath = new DOMXPath( $doc );
			$url   = $this->extract_ticket_url( $xpath );
			$ids   = $this->extract_ticket_show_ids( $xpath, $url );
			return array( $url, $ids );
		}
	}
}

$src  = new Mp_Live_Ticket_Check( array( 'id' => 'mondello_park', 'county' => 'Kildare' ) );
$args = array(
	'timeout'    => 30,
	'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
	'headers'    => array( 'Accept-Language' => 'en-GB,en;q=0.9' ),
);
$res = wp_remote_get( 'https://mondellopark.ie/events/drift-games-winter-bash/', $args );
if ( is_wp_error( $res ) ) { echo 'FETCH ERROR: ' . $res->get_error_message() . PHP_EOL; exit( 1 ); }
$code = wp_remote_retrieve_response_code( $res );
echo 'HTTP ' . $code . PHP_EOL;
$html = wp_remote_retrieve_body( $res );
list( $url, $ids ) = $src->check( $html );
echo 'primary_ticket_url: ' . $url . PHP_EOL;
echo 'ticket_show_ids: ' . implode( ', ', $ids ) . PHP_EOL;
echo ( 'https://mondellopark.ticketsolve.com/ticketbooth/shows/1173670621' === $url ) ? 'MATCH: 1173670621 (Book Now) is primary' . PHP_EOL : 'MISMATCH — unexpected primary' . PHP_EOL;
