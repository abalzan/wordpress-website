<?php
/**
 * Motorsport Ireland CSS-leak local repair (update-only).
 *
 * Root cause (2026-09): the MI importer captured Squarespace page-builder
 * CSS (inline <style id="container-styles"> inside eventlist-description)
 * as event content. Fixed at the source layer (extract_visible_text).
 * Existing local records still carry the leaked CSS — this tool repairs
 * ONLY the affected content field(s).
 *
 * Safety properties:
 *  - Targets only posts with `_event_source = motorsport_ireland` whose
 *    post_content or post_excerpt contains leaked CSS/page-builder
 *    fragments (--stroke-style / --stroke-thickness / #block-).
 *  - Never creates posts, never deletes posts, never touches any other
 *    source.
 *  - Never touches title, dates, category, organizer, source identity or
 *    any other meta. Only post_content (+ its derived post_excerpt) is
 *    rewritten, mirroring the importer's own mapping
 *    (class-event-importer.php: description -> post_content + excerpt).
 *  - Clean text is re-derived from the saved live listing fixture
 *    (tests/fixtures/mi-listing.html) matched by stable source ID — no
 *    network fetch, deterministic.
 *  - Records whose source ID is not found in the fixture (or whose clean
 *    text still fails the fragment check) are reported and LEFT UNCHANGED.
 *
 * DRY RUN BY DEFAULT. Run with --apply to write.
 *
 * Usage:
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/repair-mi-css-leak.php
 *   docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/repair-mi-css-leak.php --apply
 *
 * @package Conexao_Event_Importer
 */

$wp_load = '/var/www/html/wp-load.php';
if ( file_exists( dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php' ) ) {
	$wp_load = dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/wp-load.php';
}
require_once $wp_load;
require_once WP_PLUGIN_DIR . '/conexao-event-importer/conexao-event-importer.php';

$apply = in_array( '--apply', $argv, true );
$bad   = array( '--stroke-style', '--stroke-thickness', '#block-' );

function repair_contains_bad( $text ) {
	foreach ( array( '--stroke-style', '--stroke-thickness', '#block-' ) as $frag ) {
		if ( false !== strpos( (string) $text, $frag ) ) {
			return true;
		}
	}
	return false;
}

// 1. Index the saved live fixture by stable source ID (clean parser output).
$fixture_html = file_get_contents( __DIR__ . '/fixtures/mi-listing.html' );
$cards        = Conexao_Source_Motorsport_Ireland::parse_listing_cards( $fixture_html, 'https://www.motorsportireland.com/events' );
$clean_by_sid = array();
foreach ( $cards as $card ) {
	$sid = isset( $card['source_id'] ) ? $card['source_id'] : '';
	if ( '' !== $sid && isset( $card['description'] ) && ! repair_contains_bad( $card['description'] ) ) {
		$clean_by_sid[ $sid ] = $card['description'];
	}
}
echo 'Fixture cards indexed (clean descriptions): ' . count( $clean_by_sid ) . "\n";

// 2. Find affected local records.
$query = new WP_Query( array(
	'post_type'      => 'event',
	'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
	'posts_per_page' => -1,
	'meta_query'     => array(
		array( 'key' => '_event_source', 'value' => 'motorsport_ireland' ),
	),
	'no_found_rows'  => true,
) );

$affected = array();
foreach ( $query->posts as $post ) {
	$content = (string) get_post_field( 'post_content', $post->ID );
	$excerpt = (string) get_post_field( 'post_excerpt', $post->ID );
	if ( repair_contains_bad( $content ) || repair_contains_bad( $excerpt ) ) {
		$affected[] = $post;
	}
}

echo "Affected local events: " . count( $affected ) . "\n";
echo $apply ? "MODE: APPLY (update-only writes)\n" : "MODE: DRY RUN (no writes)\n";
echo str_repeat( '-', 80 ) . "\n";

$repaired   = 0;
$unchanged  = 0;
$errors     = 0;

foreach ( $affected as $post ) {
	$pid     = $post->ID;
	$title   = get_the_title( $pid );
	$sid     = get_post_meta( $pid, '_event_source_id', true );
	$content = (string) get_post_field( 'post_content', $pid );
	$excerpt = (string) get_post_field( 'post_excerpt', $pid );

	if ( ! isset( $clean_by_sid[ $sid ] ) ) {
		echo sprintf( "[UNCHANGED] ID=%d sid=%s %s — source ID not in fixture; no clean text available\n", $pid, $sid, $title );
		$unchanged++;
		continue;
	}

	$clean = $clean_by_sid[ $sid ];
	if ( repair_contains_bad( $clean ) ) {
		echo sprintf( "[ERROR] ID=%d sid=%s %s — derived text failed fragment check\n", $pid, $sid, $title );
		$errors++;
		continue;
	}

	$new_excerpt = wp_trim_words( $clean, 30, '...' );
	echo sprintf(
		"[%s] ID=%d sid=%s %s\n      BEFORE content: %s\n      AFTER  content: %s\n",
		$apply ? 'REPAIR' : 'WOULD-REPAIR',
		$pid,
		$sid,
		$title,
		'' !== $content ? substr( $content, 0, 110 ) . '…' : '(empty)',
		'' !== $clean ? $clean : '(empty)'
	);

	if ( $apply ) {
		$result = wp_update_post( array(
			'ID'           => $pid,
			'post_content' => $clean,
			'post_excerpt' => $new_excerpt,
		), true );
		if ( is_wp_error( $result ) ) {
			echo '      ERROR: ' . $result->get_error_message() . "\n";
			$errors++;
			continue;
		}
		// Post-write verification.
		$ok = ! repair_contains_bad( (string) get_post_field( 'post_content', $pid ) )
			&& ! repair_contains_bad( (string) get_post_field( 'post_excerpt', $pid ) );
		if ( ! $ok ) {
			echo "      ERROR: post-write verification failed (fragments still present)\n";
			$errors++;
			continue;
		}
	}

	$repaired++;
}

echo str_repeat( '-', 80 ) . "\n";
echo sprintf(
	"Summary: affected=%d | repaired=%d | unchanged=%d | errors=%d | writes=%s\n",
	count( $affected ),
	$repaired,
	$unchanged,
	$errors,
	$apply ? 'PERFORMED' : 'NONE (dry run)'
);
