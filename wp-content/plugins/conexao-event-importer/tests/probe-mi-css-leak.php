<?php
/**
 * Motorsport Ireland CSS-leak probe + reproduction.
 *
 * Read-only. Two jobs:
 *  1. Reproduce the parser output for the affected card in the saved live
 *     listing fixture (mi-listing.html) — prints the normalized description.
 *  2. Scan the local DB for motorsport_ireland events whose stored
 *     post_content/post_excerpt contain leaked CSS/page-builder markup.
 *
 * Usage: docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/probe-mi-css-leak.php
 *
 * @package Conexao_Event_Importer
 */

$wp_load = '/var/www/html/wp-load.php';
if ( file_exists( dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php' ) ) {
	$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
}
require_once $wp_load;
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

function probe_contains_bad( $text ) {
	foreach ( array( '--stroke-style', '--stroke-thickness', '#block-' ) as $frag ) {
		if ( false !== strpos( (string) $text, $frag ) ) {
			return true;
		}
	}
	return false;
}

echo "=== 1. Fixture reproduction (mi-listing.html) ===\n";
$fixture = __DIR__ . '/fixtures/mi-listing.html';
$html    = file_get_contents( $fixture );
$cards   = Conexao_Source_Motorsport_Ireland::parse_listing_cards( $html, 'https://www.motorsportireland.com/events' );

$affected = 0;
$target   = null;
foreach ( $cards as $card ) {
	if ( probe_contains_bad( isset( $card['description'] ) ? $card['description'] : '' ) ) {
		$affected++;
		if ( null === $target ) {
			$target = $card;
		}
	}
}
echo "Cards parsed: " . count( $cards ) . "\n";
echo "Cards with CSS leakage in description: {$affected}\n";
if ( $target ) {
	echo "\nAffected sample card:\n";
	echo '  source_id   : ' . $target['source_id'] . "\n";
	echo '  title       : ' . $target['title'] . "\n";
	echo '  url         : ' . $target['url'] . "\n";
	echo "  description (BEFORE):\n";
	echo '  >>' . $target['description'] . "<<\n";
}

echo "\n=== 2. Local DB scan (motorsport_ireland events) ===\n";
$query = new WP_Query( array(
	'post_type'      => 'event',
	'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
	'posts_per_page' => -1,
	'meta_query'     => array(
		array( 'key' => '_event_source', 'value' => 'motorsport_ireland' ),
	),
	'fields'         => 'ids',
	'no_found_rows'  => true,
) );

$ids    = $query->posts;
$dirty  = array();
$clean  = 0;
foreach ( $ids as $pid ) {
	$content = (string) get_post_field( 'post_content', $pid );
	$excerpt = (string) get_post_field( 'post_excerpt', $pid );
	if ( probe_contains_bad( $content ) || probe_contains_bad( $excerpt ) ) {
		$dirty[] = $pid;
	} else {
		$clean++;
	}
}
echo "Total motorsport_ireland events: " . count( $ids ) . "\n";
echo "Clean: {$clean}\n";
echo "Polluted (CSS in content/excerpt): " . count( $dirty ) . "\n";
foreach ( $dirty as $pid ) {
	echo sprintf(
		"  [dirty] ID=%d | source_id=%s | %s | content_len=%d\n",
		$pid,
		get_post_meta( $pid, '_event_source_id', true ),
		get_the_title( $pid ),
		strlen( (string) get_post_field( 'post_content', $pid ) )
	);
}
if ( $dirty ) {
	$pid   = $dirty[0];
	$frag_start = max( 0, strpos( (string) get_post_field( 'post_content', $pid ), '--stroke' ) - 80 );
	echo "\nStored content fragment of first dirty event (ID {$pid}):\n>>" . substr( (string) get_post_field( 'post_content', $pid ), $frag_start, 240 ) . "<<\n";
}
