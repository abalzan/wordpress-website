<?php
/**
 * Create the English primary navigation menu and assign it to the Polylang
 * per-language 'primary' menu location.
 *
 * WHY THIS EXISTS
 *
 * The header primary navigation resolves exclusively through Polylang's
 * per-language assignment option (nav_menus[stylesheet][location][language]).
 * The Portuguese assignment ("Menu Principal") was restored by
 * scripts/assign-polylang-nav-menus.php, but no English menu existed in the
 * dataset, so Polylang nullified the 'primary' location on /en/ requests and
 * the header rendered its safe empty fallback (an empty <nav> — never the
 * wp_page_menu page list). This script supplies the missing data: the English
 * menu plus its per-language assignment.
 *
 * WHAT IT DOES (idempotent, data-only)
 *
 *  - Finds or creates the "Main Menu" nav menu — the English mirror of the
 *    curated "Menu Principal" menu. It mirrors the PT menu's STORED structure
 *    exactly: custom links to the canonical Portuguese paths (the theme
 *    resolves every URL to the current language at render time — see
 *    conexao_primary_nav_archive_url() / conexao_bind_section_object() /
 *    conexao_lang_url()), plus page-object items for the Contact and About Us
 *    pages, with English titles.
 *  - Writes nav_menus[stylesheet]['primary']['en'] = <menu id> — the exact
 *    same option wp-admin's per-language Menus tabs write.
 *  - Re-running reports the state and changes nothing.
 *
 * WHAT IT NEVER DOES
 *
 *  - Never creates, edits or deletes a Portuguese ("Menu Principal") item.
 *  - Never touches the footer/social menus or the 'footer' location.
 *  - Never touches the plain nav_menu_locations theme mod (the Portuguese
 *    menu stays the non-Polylang/default assignment).
 *  - Never invents English detail URLs for untranslated content — English
 *    destinations are resolved at render time by the theme's Stage 3.3
 *    language-aware link layer (untranslated pages keep the approved B1
 *    Portuguese destination).
 *
 * Local/staging tooling only — production must not be modified from here. Run
 * the equivalent flow in wp-admin (Menus → per-language tabs) for production.
 *
 * Run inside the WordPress container:
 *   php /path/to/create-en-primary-menu.php
 *
 * @package conexao-br-irlanda
 */

// Ensure we're in WordPress context.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'WP_USE_THEMES', false );
	// Locate wp-load.php by walking up from this file's directory.
	$dir = dirname( __FILE__ );
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once( $dir . '/wp-load.php' );
			break;
		}
		$dir = dirname( $dir );
	}
	if ( ! defined( 'ABSPATH' ) ) {
		fwrite( STDERR, "Could not locate wp-load.php. Run inside WordPress.\n" );
		exit( 1 );
	}
}

echo "=== English primary menu creation + Polylang assignment ===\n";

if ( ! function_exists( 'pll_current_language' ) ) {
	fwrite( STDERR, "ERROR: Polylang is not active — nothing to do (single-language theme location assignment applies as-is).\n" );
	exit( 1 );
}

$stylesheet = (string) get_option( 'stylesheet' );

// 1. Canonical English menu content. It mirrors the stored structure of the
//    "Menu Principal" menu: custom links to canonical PT paths (language
//    resolved at render time) plus page objects. Titles are the existing
//    English labels of the linked objects ("Home" / "Contact" are the titles
//    of the real EN front page and EN contact page); directory labels follow
//    the same canonical nine-section architecture as the PT menu.
$en_items = array(
	array( 'title' => 'Home', 'type' => 'custom', 'path' => '/' ),
	array( 'title' => 'Sponsors', 'type' => 'custom', 'path' => '/apoiadores/' ),
	array( 'title' => 'Guides', 'type' => 'custom', 'path' => '/guias/' ),
	array( 'title' => 'Events', 'type' => 'custom', 'path' => '/eventos/' ),
	array( 'title' => 'Courses', 'type' => 'custom', 'path' => '/cursos/' ),
	array( 'title' => 'Leisure & Tourism', 'type' => 'custom', 'path' => '/lazer/' ),
	// Stored as the canonical PT path; the render-time language layer resolves it
	// to the linked EN translation /en/jobs/ (Jobs is a page-backed section — see
	// conexao_primary_nav_sections() / CONEXAO_BR_EN_NAV_LANGUAGE_CONTEXT_FIX_REPORT.md).
	array( 'title' => 'Jobs', 'type' => 'custom', 'path' => '/empregos/' ),
	array( 'title' => 'Blog', 'type' => 'custom', 'path' => '/blog/' ),
	array( 'title' => 'About Us', 'type' => 'page', 'page_path' => 'sobre-nos' ),
	array( 'title' => 'Contact', 'type' => 'page', 'page_path' => 'contato' ),
);

// 2. Find or create the "Main Menu" nav menu.
$menu = wp_get_nav_menu_object( 'Main Menu' );
if ( ! $menu ) {
	$menu_id = wp_update_nav_menu_object( 0, array( 'menu-name' => 'Main Menu' ) );
	if ( is_wp_error( $menu_id ) || ! $menu_id ) {
		fwrite( STDERR, "ERROR: could not create the 'Main Menu' nav menu.\n" );
		exit( 1 );
	}
	echo sprintf( "Created nav menu 'Main Menu' (term_id %d).\n", $menu_id );
} else {
	$menu_id = (int) $menu->term_id;
	echo sprintf( "Nav menu 'Main Menu' found (term_id %d, %d items).\n", $menu_id, count( (array) wp_get_nav_menu_items( $menu_id ) ) );
}

// 3. Populate items when the menu is empty. Never touches existing items.
$existing = wp_get_nav_menu_items( $menu_id );
if ( ! $existing || 0 === count( (array) $existing ) ) {
	foreach ( $en_items as $spec ) {
		$args = array(
			'menu-item-title'  => $spec['title'],
			'menu-item-status' => 'publish',
		);

		if ( 'page' === $spec['type'] ) {
			$page = get_page_by_path( $spec['page_path'] );
			if ( ! $page ) {
				fwrite( STDERR, sprintf( "WARNING: page '%s' not found — skipping item '%s'.\n", $spec['page_path'], $spec['title'] ) );
				continue;
			}
			$args['menu-item-object']    = 'page';
			$args['menu-item-object-id'] = (int) $page->ID;
			$args['menu-item-type']      = 'post_type';
		} else {
			$args['menu-item-url']  = home_url( $spec['path'] );
			$args['menu-item-type'] = 'custom';
		}

		$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( is_wp_error( $item_id ) || ! $item_id ) {
			fwrite( STDERR, sprintf( "ERROR: could not create menu item '%s'.\n", $spec['title'] ) );
			exit( 1 );
		}
	}
	echo sprintf( "Populated %d EN menu items (mirror of the PT menu's stored structure).\n", count( (array) wp_get_nav_menu_items( $menu_id ) ) );
} else {
	echo "Menu already populated — no items created or modified.\n";
}

// 4. Polylang per-language assignment for the 'primary' location.
//    Structure Polylang 3.x expects:
//      nav_menus[ stylesheet ][ location ][ language_slug ] = menu term_id
//    This is the exact option wp-admin's per-language Menus tabs write.
$options = get_option( 'polylang', array() );
if ( ! is_array( $options ) ) {
	fwrite( STDERR, "ERROR: 'polylang' option missing or malformed.\n" );
	exit( 1 );
}

$before = isset( $options['nav_menus'][ $stylesheet ]['primary']['en'] ) ? (int) $options['nav_menus'][ $stylesheet ]['primary']['en'] : 0;

if ( $before === $menu_id ) {
	echo sprintf( "Polylang nav_menus['%s']['primary']['en'] already = %d. No change.\n", $stylesheet, $menu_id );
} else {
	$options['nav_menus'][ $stylesheet ]['primary']['en'] = $menu_id;
	update_option( 'polylang', $options );
	echo sprintf( "Wrote Polylang nav_menus['%s']['primary']['en'] = %d (was %d).\n", $stylesheet, $menu_id, $before );
}

// 5. Report the PT assignment so operators see the complete per-language state.
$pt_assigned = isset( $options['nav_menus'][ $stylesheet ]['primary']['pt'] ) ? (int) $options['nav_menus'][ $stylesheet ]['primary']['pt'] : 0;
echo sprintf( "PT state: nav_menus['%s']['primary']['pt'] = %d%s.\n", $stylesheet, $pt_assigned, $pt_assigned > 0 ? '' : ' (UNASSIGNED — run scripts/assign-polylang-nav-menus.php)' );

echo "Done.\n";