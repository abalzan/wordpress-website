<?php
/**
 * Stage 4.5 — create the linked English translation of every eligible
 * public WordPress Page (local/staging runner).
 *
 * Shares 100% of its data and logic with the production importer plugin
 * (wp-content/plugins/conexao-page-translation): this script simply loads
 * the same map + apply engine and runs them. Production runs the same code
 * through the plugin's wp-admin screen (WordPress.com has no WP-CLI).
 *
 * Safety contract (identical to the plugin):
 *   - never modifies a Portuguese page (verified by a before/after snapshot);
 *   - idempotent — existing EN translations are skipped;
 *   - refuses slug collisions;
 *   - verifies pll_get_post() in BOTH directions per page;
 *   - Polylang must be active (deploy the EN layer first).
 *
 * Usage (local/staging only):
 *   wp eval-file scripts/stage45-translate-pages.php           # dry run
 *   wp eval-file scripts/stage45-translate-pages.php apply     # create
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	$dir = __DIR__;
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress bootstrap failed\n" );
	exit( 1 );
}

require_once WP_CONTENT_DIR . '/plugins/conexao-page-translation/includes/translation-map.php';
require_once WP_CONTENT_DIR . '/plugins/conexao-page-translation/includes/apply.php';

$apply = in_array( 'apply', (array) $argv, true );

echo "== Stage 4.5 — EN page translations ==\n";
echo $apply ? "Mode: APPLY\n\n" : "Mode: DRY RUN (pass 'apply' to create)\n\n";

if ( ! function_exists( 'pll_get_post' ) ) {
	fwrite( STDERR, "ERROR: Polylang is not active.\n" );
	exit( 1 );
}

$result = conexao_page_translation_run( array( 'dry_run' => ! $apply ) );

foreach ( $result['rows'] as $row ) {
	printf(
		"  %-28s → %-18s %s %s\n",
		$row['slug'],
		$row['en_slug'] ?? '',
		strtoupper( $row['action'] ),
		$row['message'] . ( ! empty( $row['en_url'] ) ? ' ' . $row['en_url'] : '' )
	);
	foreach ( (array) ( $row['links'] ?? array() ) as $from => $to ) {
		printf( "      link: %s → %s\n", $from, $to );
	}
}

$s = $result['summary'];
printf(
	"\nSummary: created=%d updated=%d exists=%d errors=%d pt_changed=%d links_localized=%d\n",
	$s['created'],
	$s['updated'],
	$s['exists'],
	$s['errors'],
	$s['pt_changed'],
	$s['links_localized']
);

// Relationship table (Phase 11) — printed after an apply run.
if ( $apply && 0 === $s['errors'] ) {
	echo "\nRelationship table (both directions verified at creation):\n";
	foreach ( conexao_page_translation_map() as $pt_slug => $en ) {
		$pt_page = get_page_by_path( $pt_slug, OBJECT, 'page' );
		if ( ! $pt_page ) {
			continue;
		}
		$en_id = (int) pll_get_post( (int) $pt_page->ID, 'en' );
		$pt_rt = $en_id ? (int) pll_get_post( $en_id, 'pt' ) : 0;
		printf(
			"  pt #%d %-28s ⇄ en #%d %-18s (en→pt: #%d) %s\n",
			$pt_page->ID,
			$pt_slug,
			$en_id,
			$en_id ? get_post_field( 'post_name', $en_id ) : '—',
			$pt_rt,
			( $en_id && $pt_rt === (int) $pt_page->ID ) ? 'OK' : 'FAIL'
		);
	}
}

exit( $s['errors'] > 0 ? 1 : 0 );
