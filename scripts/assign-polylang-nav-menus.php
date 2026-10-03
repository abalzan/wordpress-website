<?php
/**
 * Assign the primary navigation menu to the Polylang multilingual-menu
 * locations (per-language assignment).
 *
 * WHY THIS EXISTS
 *
 * With Polylang active, the plain WordPress location assignment
 * (theme mod `nav_menu_locations`) is NOT used to resolve the header menu.
 * Polylang's `theme_mod_nav_menu_locations` filter (priority 20) unconditionally
 * overwrites every registered theme location with the per-language assignment
 * stored in Polylang's `nav_menus` option (`PLL()->options['nav_menus']
 * [stylesheet][location][language-slug]`), or with 0 when that per-language
 * entry does not exist. When the i18n work enabled Polylang's frontend menu
 * integration, this per-language assignment was never populated — so EVERY
 * language (including Portuguese) saw its location nulled to 0, and the theme
 * rendered WordPress' full automatic page list instead of the curated
 * "Menu Principal" menu.
 *
 * WHAT IT DOES (idempotent)
 *
 *  - Finds the existing "Menu Principal" nav menu (never creates items).
 *  - Writes `nav_menus[stylesheet]['primary']['pt'] = <menu id>` in Polylang's
 *    option so the Portuguese header resolves the curated menu again.
 *  - Re-asserts the plain WordPress assignment (`nav_menu_locations.primary`)
 *    as a belt-and-braces measure for non-Polylang contexts (admin screens).
 *  - English is handled by scripts/create-en-primary-menu.php (creates the
 *    "Main Menu" EN mirror + the per-language EN assignment). Until that is
 *    run, the EN header falls back to the theme's safe empty fallback (no
 *    navigation items), never to the wp_page_menu page list.
 *  - Footer / social menus are never modified (out of scope).
 *
 * Run via WP-CLI:
 *   docker compose exec -T wordpress wp eval-file \
 *     /var/www/html/scripts/assign-polylang-nav-menus.php --allow-root
 *
 * Or on any environment with wp-load.php reachable from this file's tree:
 *   php scripts/assign-polylang-nav-menus.php
 *
 * @package conexao-br-irlanda
 */

// Ensure we're in WordPress context
require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

if ( PHP_SAPI !== 'cli' ) {
	exit( "This script must be run from the command line.\n" );
}

echo "=== Polylang primary-menu assignment ===\n";

// 1. Polylang must be active: without it the theme's plain location
//    assignment is authoritative and nothing needs to be written.
if ( ! function_exists( 'pll_current_language' ) ) {
	echo "Polylang is not active. Nothing to do (theme location assignment applies as-is).\n";
	exit( 0 );
}

// 2. Locate the curated primary menu.
$primary_menu = wp_get_nav_menu_object( 'Menu Principal' );
if ( ! $primary_menu ) {
	fwrite( STDERR, "ERROR: menu 'Menu Principal' does not exist. Create/seed it first (scripts/flush-navigation-rules.php) and retry.\n" );
	exit( 1 );
}
$primary_menu_id = (int) $primary_menu->term_id;
echo sprintf( "Menu Principal found (term_id %d, %d items).\n", $primary_menu_id, count( (array) wp_get_nav_menu_items( $primary_menu_id ) ) );

// 3. Polylang per-language assignment for the 'primary' location.
//
//    Structure Polylang 3.x expects (see src/nav-menu.php /
//    src/frontend/frontend-nav-menu.php):
//      nav_menus[ get_option('stylesheet') ][ location ][ language_slug ] = menu term_id
$stylesheet = (string) get_option( 'stylesheet' );
$options    = get_option( 'polylang', array() );
if ( ! is_array( $options ) ) {
	fwrite( STDERR, "ERROR: 'polylang' option missing or malformed.\n" );
	exit( 1 );
}

$before = isset( $options['nav_menus'][ $stylesheet ]['primary']['pt'] ) ? (int) $options['nav_menus'][ $stylesheet ]['primary']['pt'] : 0;

if ( $before === $primary_menu_id ) {
	echo sprintf( "Polylang nav_menus['%s']['primary']['pt'] already = %d. No change.\n", $stylesheet, $primary_menu_id );
} else {
	$options['nav_menus'][ $stylesheet ]['primary']['pt'] = $primary_menu_id;
	update_option( 'polylang', $options );
	echo sprintf( "Wrote Polylang nav_menus['%s']['primary']['pt'] = %d (was %d).\n", $stylesheet, $primary_menu_id, $before );
}

// English: when an EN menu exists it must be assigned through the same
// per-language mechanism. scripts/create-en-primary-menu.php creates the
// "Main Menu" EN mirror of this menu and writes nav_menus['primary']['en'].
// Report the state so operators can see whether an EN menu is configured.
$en_assigned = isset( $options['nav_menus'][ $stylesheet ]['primary']['en'] ) ? (int) $options['nav_menus'][ $stylesheet ]['primary']['en'] : 0;
if ( $en_assigned ) {
	echo sprintf( "Note: EN already has nav_menus['%s']['primary']['en'] = %d — left untouched.\n", $stylesheet, $en_assigned );
} else {
	echo "Note: EN unassigned — create/assign the EN menu with scripts/create-en-primary-menu.php (until then the EN header uses the safe empty fallback).\n";
}

// 4. Re-assert the plain WordPress location assignment (non-Polylang
//    contexts, e.g. wp-admin Menus screen). Idempotent.
$locations          = get_theme_mod( 'nav_menu_locations', array() );
$locations          = is_array( $locations ) ? $locations : array();
$location_before    = isset( $locations['primary'] ) ? (int) $locations['primary'] : 0;
$locations['primary'] = $primary_menu_id;
if ( $location_before !== $primary_menu_id ) {
	set_theme_mod( 'nav_menu_locations', $locations );
	echo sprintf( "Set nav_menu_locations.primary = %d (was %d).\n", $primary_menu_id, $location_before );
} else {
	echo sprintf( "nav_menu_locations.primary already = %d. No change.\n", $primary_menu_id );
}

echo "Done.\n";
