<?php
/**
 * Remove the "Sobre Nós" item from the primary navigation menu.
 *
 * The /sobre-nos/ page itself is NOT touched: it stays published, keeps its
 * content and SEO configuration, and remains directly accessible at
 * /sobre-nos/. Only the navigation menu item is deleted.
 *
 * Scope (intentionally narrow):
 *  - Only inspects the menu assigned to the theme's "primary" location
 *    ("Menu Principal"). Desktop and mobile navigation both render this
 *    same location, so one removal covers both.
 *  - Footer / social menus are never modified.
 *  - Other menu items are never modified.
 *
 * An item is considered a "Sobre Nós" nav item when ANY of the following
 * matches (mirroring how the theme's own render-time filters identify
 * sections — title first, URL fallback):
 *  - its title is "Sobre Nós"/"About us" (accent/case-insensitive),
 *  - its stored URL points at /sobre-nos/ or /about-us/,
 *  - it is bound to the Page whose path is "sobre-nos".
 *
 * Items are inspected at the database/meta level so stale/broken items
 * (which wp_get_nav_menu_items() silently hides as "_invalid") are also
 * caught and cleaned up.
 *
 * Run via WP-CLI:
 *   docker compose exec -T wordpress wp eval-file \
 *     /var/www/html/scripts/remove-sobre-nos-menu-item.php --allow-root
 *
 * Or on any environment with wp-load.php reachable from this file's tree:
 *   php scripts/remove-sobre-nos-menu-item.php
 */

// Ensure we're in WordPress context
require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

echo "=== Removing 'Sobre Nós' from primary navigation ===\n\n";

/**
 * Resolve the menu assigned to the theme's primary location.
 *
 * @return WP_Term|null Menu term object, or null when unassigned/not found.
 */
function conexao_sobrenos_get_primary_menu() {
	$locations = get_nav_menu_locations();

	if ( empty( $locations['primary'] ) ) {
		return null;
	}

	$menu = wp_get_nav_menu_object( (int) $locations['primary'] );

	return ( $menu && ! is_wp_error( $menu ) ) ? $menu : null;
}

/**
 * Whether a nav menu item post is the "Sobre Nós" section link.
 *
 * @param WP_Post $item Nav menu item post.
 * @return bool
 */
function conexao_sobrenos_is_target_item( $item ) {
	// Title match: "Sobre Nós"/"About us", accent- and case-insensitive.
	$title = remove_accents( strtolower( trim( wp_strip_all_tags( (string) $item->post_title ) ) ) );
	if ( in_array( $title, array( 'sobre nos', 'about us' ), true ) ) {
		return true;
	}

	// Stored URL match: /sobre-nos/ or /about-us/.
	$url = untrailingslashit( (string) get_post_meta( $item->ID, '_menu_item_url', true ) );
	if ( '' !== $url && ( false !== strpos( $url, '/sobre-nos' ) || false !== strpos( $url, '/about-us' ) ) ) {
		return true;
	}

	// Bound-object match: a post_type/page item bound to the sobre-nos Page.
	if ( 'post_type' === get_post_meta( $item->ID, '_menu_item_type', true )
		&& 'page' === get_post_meta( $item->ID, '_menu_item_object', true ) ) {
		$object_id = (int) get_post_meta( $item->ID, '_menu_item_object_id', true );
		$page      = $object_id ? get_post( $object_id ) : null;

		if ( $page && 'page' === $page->post_type && 'sobre-nos' === $page->post_name ) {
			return true;
		}
	}

	return false;
}
/**
 * All nav menu item posts attached to a menu, including stale ones.
 *
 * Unlike wp_get_nav_menu_items(), this does NOT drop items flagged
 * "_invalid" (e.g. legacy items whose bound object vanished), so cleanup
 * also covers broken leftovers.
 *
 * @param WP_Term $menu Menu term object.
 * @return WP_Post[] Nav menu item posts.
 */
function conexao_sobrenos_get_menu_item_posts( $menu ) {
	return get_posts( array(
		'post_type'              => 'nav_menu_item',
		'post_status'            => 'any',
		'posts_per_page'         => -1,
		'orderby'                => 'menu_order',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'tax_query'              => array(
			array(
				'taxonomy' => 'nav_menu',
				'field'    => 'term_taxonomy_id',
				'terms'    => (int) $menu->term_taxonomy_id,
			),
		),
	) );
}



$primary_menu = conexao_sobrenos_get_primary_menu();

if ( null === $primary_menu ) {
	echo "WARNING: No menu assigned to the 'primary' theme location.\n";
	echo "Nothing to do. Assign 'Menu Principal' to the primary location first.\n";
	exit( 0 );
}

printf( "Primary menu: %s (ID: %d)\n\n", $primary_menu->name, (int) $primary_menu->term_id );

$menu_items = conexao_sobrenos_get_menu_item_posts( $primary_menu );

if ( empty( $menu_items ) ) {
	echo "Primary menu has no items. Nothing to do.\n";
	exit( 0 );
}

$removed = 0;

foreach ( $menu_items as $item ) {
	if ( ! conexao_sobrenos_is_target_item( $item ) ) {
		continue;
	}

	printf(
		"Removing menu item #%d: \"%s\" -> %s\n",
		(int) $item->ID,
		$item->post_title,
		get_post_meta( $item->ID, '_menu_item_url', true )
	);

	$deleted = wp_delete_post( $item->ID, true );

	if ( $deleted ) {
		$removed++;
	} else {
		printf( "ERROR: failed to delete menu item #%d\n", (int) $item->ID );
	}
}

if ( 0 === $removed ) {
	echo "\nNo 'Sobre Nós' menu item found in the primary navigation. Nothing removed.\n";
} else {
	printf( "\nRemoved %d menu item(s).\n", $removed );
}

// Safety verification: the /sobre-nos/ page must remain published and reachable.
$page = get_page_by_path( 'sobre-nos' );

if ( $page && 'publish' === $page->post_status ) {
	printf(
		"\nVerified: /sobre-nos/ page still exists (ID: %d, status: publish) -> %s\n",
		(int) $page->ID,
		get_permalink( $page->ID )
	);
} elseif ( $page ) {
	printf(
		"\nWARNING: /sobre-nos/ page exists (ID: %d) but status is '%s'.\n",
		(int) $page->ID,
		$page->post_status
	);
} else {
	echo "\nWARNING: /sobre-nos/ page not found! It should NOT have been deleted by this script.\n";
}

// Show the resulting primary menu structure for quick review.
echo "\nResulting primary navigation:\n";

$remaining = conexao_sobrenos_get_menu_item_posts( $primary_menu );

if ( empty( $remaining ) ) {
	echo "  (no items)\n";
} else {
	foreach ( $remaining as $item ) {
		printf(
			"  - #%d %s -> %s\n",
			(int) $item->ID,
			$item->post_title,
			get_post_meta( $item->ID, '_menu_item_url', true )
		);
	}
}

echo "\n=== Done! ===\n";
