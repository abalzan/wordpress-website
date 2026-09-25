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
 *   wp eval-file scripts/stage45-translate-pages.php                 # dry run
 *   wp eval-file scripts/stage45-translate-pages.php apply           # create
 *   wp eval-file scripts/stage45-translate-pages.php refresh         # re-apply
 *                                                                     # manifest
 *                                                                     # content to
 *                                                                     # existing EN
 *                                                                     # pages
 *   wp eval-file scripts/stage45-translate-pages.php refresh emprego  # …scoped
 *
 * `refresh` re-applies the human-authored EN title/content/meta/template of an
 * EXISTING EN translation from the manifest. It is how a corrected or
 * completed manifest reaches a site whose EN pages were created by an earlier
 * (incomplete) run — e.g. the EN Jobs landing page, whose body was authored
 * after its first creation. It NEVER touches the Portuguese page (the PT
 * before/after gate below still runs and must report `pt_changed = 0`), never
 * creates a second translation, and is idempotent.
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

$args    = (array) ( isset( $argv ) ? $argv : array() );
$apply   = in_array( 'apply', $args, true );
$refresh = in_array( 'refresh', $args, true );

// Optional scope: "refresh emprego" (or a comma-separated list) restricts the
// run to those PT slugs. Useful to repair one page without touching the rest.
$only = array();
foreach ( $args as $arg ) {
	if ( is_string( $arg ) && false !== strpos( $arg, ',' ) ) {
		foreach ( explode( ',', $arg ) as $slug ) {
			$slug = trim( $slug );
			if ( '' !== $slug ) {
				$only[] = $slug;
			}
		}
		continue;
	}
	if ( is_string( $arg ) && ! in_array( $arg, array( 'apply', 'refresh', __FILE__ ), true ) && '' !== $arg && '/' !== substr( $arg, -1 ) ) {
		$only[] = $arg;
	}
}

echo "== Stage 4.5 — EN page translations ==\n";
if ( $refresh ) {
	echo "Mode: REFRESH (re-apply the manifest to existing EN translations";
	echo $only ? ' — scope: ' . implode( ', ', $only ) : '';
	echo ")\n\n";
} else {
	echo $apply ? "Mode: APPLY\n\n" : "Mode: DRY RUN (pass 'apply' to create)\n\n";
}

if ( ! function_exists( 'pll_get_post' ) ) {
	fwrite( STDERR, "ERROR: Polylang is not active.\n" );
	exit( 1 );
}

$result = conexao_page_translation_run(
	array(
		// `refresh` IS a write (it re-applies the manifest copy to an existing
		// EN page), so — like `apply` — it is only a preview when neither flag
		// is given. The PT before/after gate still runs in every case.
		'dry_run'         => ! ( $apply || $refresh ),
		'update_existing' => $refresh,
		'only'            => $only,
	)
);

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

// Relationship table (Phase 11) — printed after an apply/refresh run and
// scoped to the same slugs the run covered (a scoped run must not print a
// FAIL for every page it deliberately skipped).
if ( ( $apply || $refresh ) && 0 === $s['errors'] ) {
	echo "\nRelationship table (both directions verified at creation):\n";
	foreach ( conexao_page_translation_map() as $pt_slug => $en ) {
		if ( $only && ! in_array( $pt_slug, $only, true ) ) {
			continue;
		}
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
