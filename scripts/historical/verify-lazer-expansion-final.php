<?php
/**
 * Final verification for the Lazer expansion.
 *
 * Produces:
 *   - Total leisure posts (any status)
 *   - Per-county counts (existing + new)
 *   - Image coverage (posts with local attachment)
 *   - Duplicate scan (normalized title + slug)
 *   - Official website / Discover Ireland coverage
 *   - Export-pipeline compatibility check (UUIDs present)
 *
 * Run via: wp eval-file scripts/verify-lazer-expansion-final.php --allow-root
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'WP_USE_THEMES', false );
	$dir = dirname( __FILE__ );
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
	if ( ! defined( 'ABSPATH' ) ) {
		fwrite( STDERR, "Unable to locate wp-load.php\n" );
		exit( 1 );
	}
}

$posts = get_posts( array(
	'post_type'      => 'leisure',
	'post_status'    => 'any',
	'numberposts'    => -1,
	'orderby'        => 'ID',
	'order'          => 'ASC',
) );

$total = count( $posts );
$by_county = array();
$with_image = 0;
$with_official = 0;
$with_di = 0;
$with_uuid = 0;
$slug_seen = array();
$title_norm = array();
$dups = array();

foreach ( $posts as $p ) {
	$terms = wp_get_post_terms( $p->ID, 'conexao_county', array( 'fields' => 'names' ) );
	$county = $terms ? $terms[0] : '(sem condado)';
	$by_county[ $county ] = ( $by_county[ $county ] ?? 0 ) + 1;

	if ( get_post_meta( $p->ID, '_leisure_image_attachment_id', true ) ) {
		$with_image++;
	}
	if ( get_post_meta( $p->ID, '_leisure_official_website', true ) ) {
		$with_official++;
	}
	if ( get_post_meta( $p->ID, '_leisure_discover_ireland', true ) ) {
		$with_di++;
	}
	if ( get_post_meta( $p->ID, '_leisure_export_uuid', true ) ) {
		$with_uuid++;
	}

	// Duplicate scan.
	if ( isset( $slug_seen[ $p->post_name ] ) ) {
		$dups[] = "slug: {$p->post_name}";
	}
	$slug_seen[ $p->post_name ] = true;

	$norm = strtolower( preg_replace( '/[^a-z0-9]+/', ' ', iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $p->post_title ) ) );
	$norm = trim( preg_replace( '/\s+/', ' ', preg_replace( '/\b(s|the|of|and)\b/', ' ', $norm ) ) );
	if ( isset( $title_norm[ $norm ] ) ) {
		$dups[] = "title: {$p->post_title} <=> {$title_norm[$norm]}";
	}
	$title_norm[ $norm ] = $p->post_title;
}

ksort( $by_county );

echo "=== FINAL VERIFICATION ===\n";
echo "Total leisure posts: {$total}\n";
echo "With local image: {$with_image} ({$with_image}/{$total})\n";
echo "With official website: {$with_official}\n";
echo "With Discover Ireland: {$with_di}\n";
echo "With export UUID: {$with_uuid}\n";
echo "Duplicate issues: " . count( $dups ) . "\n";
foreach ( $dups as $d ) {
	echo "  DUP: {$d}\n";
}

echo "\n=== COUNTY COVERAGE ===\n";
echo "| County | Posts |\n|--------|------:|\n";
foreach ( $by_county as $county => $count ) {
	echo "| {$county} | {$count} |\n";
}

echo "\n=== IMAGE STATUS ===\n";
$pending = 0;
foreach ( $posts as $p ) {
	if ( 'pending' === get_post_meta( $p->ID, '_leisure_image_status', true ) ) {
		$pending++;
		echo "  PENDING: {$p->post_name} ({$p->post_title})\n";
	}
}
echo "Pending (no image found): {$pending}\n";

echo "\nDone.\n";