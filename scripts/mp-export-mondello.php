<?php
/**
 * Mondello-only event export (local DB → dist/mondello-only-export.json).
 *
 * Builds a conexao-event-export JSON containing ONLY events whose
 * `_event_source` is `mondellopark`, reusing Conexao_Event_Export::export_event()
 * so the format is byte-compatible with the standard admin export (manifest
 * v1.1.0, embedded images, UUIDs).
 *
 * Usage: cat scripts/mp-export-mondello.php | docker compose exec -T wordpress php > dist/mondello-only-export.json
 */

require '/var/www/html/wp-load.php';
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$query = new WP_Query( array(
	'post_type'      => 'event',
	'post_status'    => 'any',
	'posts_per_page' => 50,
	'orderby'        => 'ID',
	'order'          => 'ASC',
	'meta_query'     => array(
		array( 'key' => '_event_source', 'value' => 'mondellopark' ),
	),
) );

if ( method_exists( 'Conexao_Event_Export', 'export_event' ) ) {
	// export_event() is protected — reach it through a tiny subclass.
	class Mp_Export_Expose extends Conexao_Event_Export {
		public function export_one( $post ) {
			return $this->export_event( $post );
		}
	}
	$ex = new Mp_Export_Expose();
} else {
	fwrite( STDERR, "export_event() not found\n" );
	exit( 1 );
}

$events = array();
foreach ( $query->posts as $post ) {
	$events[] = $ex->export_one( $post );
}

$payload = array(
	'manifest' => array(
		'format'      => Conexao_Event_Export::FORMAT,
		'version'     => Conexao_Event_Export::FORMAT_VERSION,
		'exported_at' => gmdate( 'Y-m-d\TH:i:sP' ),
		'source_url'  => home_url(),
		'event_count' => count( $events ),
		'scope'       => 'mondellopark-only',
	),
	'events'   => $events,
);

echo json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

fwrite(
	STDERR,
	sprintf( "Exported %d Mondello events\n", count( $events ) )
);
