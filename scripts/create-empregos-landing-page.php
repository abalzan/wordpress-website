<?php
/**
 * Create / update the Empregos landing page at /empregos/.
 *
 * The `job` CPT archive was disabled (see conexao-data-model) so that
 * /empregos/ could become a normal WordPress page — the "Jobs Landing" hub —
 * rendered by the theme's page-empregos.php template. Individual job posts
 * (e.g. /empregos/oportunidades/) keep their /empregos/{slug}/ URLs untouched.
 *
 * This script is the narrow, idempotent way to add that page to an existing
 * database WITHOUT re-running create-pages.php (which also rebuilds the menus).
 *
 * Scope:
 *  - Creates the "Empregos" page only if it does not already exist. It NEVER
 *    overwrites existing page content (title, body, featured image, link).
 *  - Ensures the page uses the page-empregos.php template.
 *  - Flushes rewrite rules so the disabled job archive correctly yields
 *    /empregos/ to the page. Empty values are left untouched.
 *
 * Run via WP-CLI:
 *   docker compose exec -T wordpress wp eval-file \
 *     /var/www/html/scripts/create-empregos-landing-page.php --allow-root
 *
 * Or on any environment with wp-load.php reachable from this file's tree:
 *   php scripts/create-empregos-landing-page.php
 */

// Ensure we're in WordPress context.
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
		fwrite( STDERR, "Unable to locate wp-load.php.\n" );
		exit( 1 );
	}
}

echo "=== Creating Empregos landing page ===\n\n";

$page = get_page_by_path( 'empregos', OBJECT, 'page' );

if ( ! $page ) {
	$starter = '<!-- wp:paragraph --><p>Informações sobre oportunidades de emprego para a comunidade brasileira na Irlanda. Este conteúdo é editável — substitua-o pelos detalhes sobre como as oportunidades são partilhadas, orientações de Instagram e dicas por condado.</p><!-- /wp:paragraph -->';

	$page_id = wp_insert_post(
		array(
			'post_title'   => 'Empregos',
			'post_name'    => 'empregos',
			'post_content' => $starter,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		)
	);

	if ( is_wp_error( $page_id ) ) {
		echo 'ERROR: empregos - ' . $page_id->get_error_message() . "\n";
		exit( 1 );
	}

	echo "  CREATED: empregos (ID: {$page_id})\n";
	$page_id = (int) $page_id;
} else {
	echo "  EXISTS: empregos (ID: {$page->ID})\n";
	$page_id = (int) $page->ID;
}

// Always ensure the dedicated Jobs landing template is applied.
$current_template = get_page_template_slug( $page_id );
if ( 'page-empregos.php' !== $current_template ) {
	update_post_meta( $page_id, '_wp_page_template', 'page-empregos.php' );
	echo "  Template -> page-empregos.php\n";
} else {
	echo "  Template: page-empregos.php (already)\n";
}

echo "  Permalink: " . esc_url( get_permalink( $page_id ) ) . "\n";

// The job CPT has_archive was turned off; flush so /empregos/ resolves to the
// page (and /empregos/{slug} single job posts keep working).
flush_rewrite_rules();

echo "\n=== Done. Edit the page at wp-admin (Páginas > Empregos) to set the title, portrait image and body content. The optional 'Mais informações' link lives in the 'Jobs — Link' box. ===\n";