<?php
/**
 * Reorder the primary navigation menu (order-only change).
 *
 * Canonical top-level order enforced by this script:
 *
 *   1. Início          (/)
 *   2. Apoiadores      (/apoiadores/)
 *   3. Guias           (/guias/)
 *   4. Eventos         (/eventos/)
 *   5. Cursos          (/cursos/)
 *   6. Lazer e turismo (/lazer/)        — render-time insertion by the theme
 *   7. Empregos        (/empregos/)
 *   8. Blog            (/blog/)
 *   9. Contato         (/contato/)
 *
 * Scope (intentionally narrow — ORDER ONLY):
 *  - Only inspects the menu assigned to the theme's "primary" location
 *    ("Menu Principal"). Desktop and mobile navigation both render this
 *    same location, so one reorder covers both.
 *  - Footer / social menus are never modified.
 *  - Item titles, URLs, IDs, meta, classes and hierarchy are never touched;
 *    only the WordPress-native `menu_order` value of the nav_menu_item
 *    posts is renumbered (the same mechanism as wp-admin drag-and-drop).
 *  - Items not part of the canonical list (e.g. legacy "Irlanda"/"Sobre Nós"
 *    entries, which the theme hides at render time) keep their relative
 *    position and are simply renumbered in place.
 *  - Idempotent: running it again reports "already in canonical order".
 *
 * Run via WP-CLI:
 *   docker compose exec -T wordpress wp eval-file \
 *     /var/www/html/scripts/reorder-primary-menu-blog-apoiadores.php --allow-root
 *
 * Or on any environment with wp-load.php reachable from this file's tree:
 *   php scripts/reorder-primary-menu-blog-apoiadores.php
 */

// Ensure we're in WordPress context
require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

echo "=== Reordering primary navigation (Blog <-> Apoiadores swap) ===\n\n";

/**
 * Canonical top-level section order. Mirrors the $order array used by the
 * theme's conexao_normalize_primary_nav_sections() filter in functions.php.
 */
function conexao_reorder_canonical_order() {
	return array( 'inicio', 'apoiadores', 'guias', 'eventos', 'cursos', 'lazer', 'empregos', 'blog', 'irlanda', 'sobre-nos', 'contato' );
}

/**
 * Resolve the menu assigned to the theme's primary location.
 *
 * @return WP_Term|null Menu term object, or null when unassigned/not found.
 */
function conexao_reorder_get_primary_menu() {
	$locations = get_nav_menu_locations();

	if ( empty( $locations['primary'] ) ) {
		return null;
	}

	$menu = wp_get_nav_menu_object( (int) $locations['primary'] );

	return ( $menu && ! is_wp_error( $menu ) ) ? $menu : null;
}

/**
 * All top-level nav menu item posts attached to a menu, in stored order.
 *
 * @param WP_Term $menu Menu term object.
 * @return WP_Post[] Nav menu item posts (menu_item_parent === 0 only).
 */
function conexao_reorder_get_top_level_items( $menu ) {
	$items = get_posts( array(
		'post_type'              => 'nav_menu_item',
		'post_status'            => 'any',
		'posts_per_page'         => -1,
		'orderby'                => 'menu_order',
		'order'                  => 'ASC',
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'update_post_meta_cache' => false,
		'tax_query'              => array(
			array(
				'taxonomy' => 'nav_menu',
				'field'    => 'term_taxonomy_id',
				'terms'    => (int) $menu->term_taxonomy_id,
			),
		),
	) );

	$top_level = array();
	foreach ( $items as $item ) {
		if ( 0 === (int) get_post_meta( $item->ID, '_menu_item_menu_item_parent', true ) ) {
			$top_level[] = $item;
		}
	}

	return $top_level;
}

/**
 * Map a nav menu item post to its canonical section key.
 *
 * Mirrors how the theme's render-time filters identify sections —
 * title first (accent/case-insensitive), stored URL fallback.
 *
 * @param WP_Post $item Nav menu item post.
 * @return string|null Section key, or null when unrecognized.
 */
function conexao_reorder_get_section_key( $item ) {
	$title = remove_accents( strtolower( trim( wp_strip_all_tags( (string) $item->post_title ) ) ) );

	$by_title = array(
		'home'            => 'inicio',
		'inicio'          => 'inicio',
		'blog'            => 'blog',
		'guias'           => 'guias',
		'guias praticos'  => 'guias',
		'eventos'         => 'eventos',
		'cursos'          => 'cursos',
		'lazer'           => 'lazer',
		'lazer e turismo' => 'lazer',
		'leisure'         => 'lazer',
		'empregos'        => 'empregos',
		'jobs'            => 'empregos',
		'apoiadores'      => 'apoiadores',
		'sponsors'        => 'apoiadores',
		'contato'         => 'contato',
		'contact'         => 'contato',
		'irlanda'         => 'irlanda',
		'ireland'         => 'irlanda',
		'sobre nos'       => 'sobre-nos',
		'about us'        => 'sobre-nos',
	);

	if ( isset( $by_title[ $title ] ) ) {
		return $by_title[ $title ];
	}

	$url = untrailingslashit( (string) get_post_meta( $item->ID, '_menu_item_url', true ) );
	if ( '' === $url ) {
		return null;
	}

	$by_url = array(
		'/blog'       => 'blog',
		'/guias'      => 'guias',
		'/guides'     => 'guias',
		'/eventos'    => 'eventos',
		'/events'     => 'eventos',
		'/cursos'     => 'cursos',
		'/courses'    => 'cursos',
		'/lazer'      => 'lazer',
		'/leisure'    => 'lazer',
		'/empregos'   => 'empregos',
		'/jobs'       => 'empregos',
		'/apoiadores' => 'apoiadores',
		'/sponsors'   => 'apoiadores',
		'/contato'    => 'contato',
		'/contact'    => 'contato',
		'/irlanda'    => 'irlanda',
		'/sobre-nos'  => 'sobre-nos',
	);

	foreach ( $by_url as $needle => $key ) {
		if ( false !== strpos( $url, $needle ) ) {
			return $key;
		}
	}

	return null;
}

$primary_menu = conexao_reorder_get_primary_menu();

if ( null === $primary_menu ) {
	echo "WARNING: No menu assigned to the 'primary' theme location.\n";
	echo "Nothing to do. Assign 'Menu Principal' to the primary location first.\n";
	exit( 0 );
}

printf( "Primary menu: %s (ID: %d)\n\n", $primary_menu->name, (int) $primary_menu->term_id );

$items = conexao_reorder_get_top_level_items( $primary_menu );

if ( empty( $items ) ) {
	echo "Primary menu has no top-level items. Nothing to do.\n";
	exit( 0 );
}

echo "Current stored order:\n";
foreach ( $items as $item ) {
	printf(
		"  %2d. #%d %s -> %s\n",
		(int) $item->menu_order,
		(int) $item->ID,
		$item->post_title,
		get_post_meta( $item->ID, '_menu_item_url', true )
	);
}
echo "\n";

$order      = conexao_reorder_canonical_order();
$contato_at = array_search( 'contato', $order, true );

// Build sort keys: canonical index; unrecognized items sort with "contato"
// (stable sort preserves their original relative position).
$sorted = $items;
usort( $sorted, function ( $a, $b ) use ( $order, $contato_at ) {
	$key_a = conexao_reorder_get_section_key( $a );
	$key_b = conexao_reorder_get_section_key( $b );

	$pos_a = $key_a ? array_search( $key_a, $order, true ) : $contato_at;
	$pos_b = $key_b ? array_search( $key_b, $order, true ) : $contato_at;

	return $pos_a <=> $pos_b;
} );

// Renumber menu_order 1..N; only update posts whose order actually changes.
$changed = 0;
foreach ( $sorted as $index => $item ) {
	$new_order = $index + 1;

	if ( (int) $item->menu_order === $new_order ) {
		continue;
	}

	$old_order = (int) $item->menu_order;

	$updated = wp_update_post(
		array(
			'ID'         => (int) $item->ID,
			'menu_order' => $new_order,
		),
		true
	);

	if ( is_wp_error( $updated ) ) {
		printf( "ERROR: failed to update menu item #%d: %s\n", (int) $item->ID, $updated->get_error_message() );
		exit( 1 );
	}

	printf( "Moved #%d \"%s\": menu_order %d -> %d\n", (int) $item->ID, $item->post_title, $old_order, $new_order );
	$changed++;
}

if ( 0 === $changed ) {
	echo "Primary menu is already in canonical order. No changes made.\n";
} else {
	// Fire the same hook wp-admin fires after a menu update so dependent
	// caches/mirrors are notified.
	do_action( 'wp_update_nav_menu', (int) $primary_menu->term_id );
	printf( "\nRenumbered %d menu item(s).\n", $changed );
}

// Show the resulting primary menu structure for quick review.
echo "\nResulting stored order:\n";
foreach ( conexao_reorder_get_top_level_items( $primary_menu ) as $item ) {
	printf(
		"  %2d. #%d %s -> %s\n",
		(int) $item->menu_order,
		(int) $item->ID,
		$item->post_title,
		get_post_meta( $item->ID, '_menu_item_url', true )
	);
}

echo "\nNote: \"Lazer e turismo\" is inserted at render time by the theme\n";
echo "(between Cursos and Empregos) and has no stored menu item.\n";

echo "\n=== Done! ===\n";
