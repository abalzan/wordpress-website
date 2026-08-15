<?php
/**
 * Script to flush rewrite rules and update navigation menu URLs.
 * 
 * This script:
 * 1. Flushes WordPress rewrite rules to register the new CPT archive URLs
 * 2. Updates the primary navigation menu items to use the correct CPT archive URLs
 * 3. Removes any conflicting static pages
 * 
 * Run via: docker compose exec -T wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/flush-navigation-rules.php
 */

// Ensure we're in WordPress context
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
        fwrite( STDERR, "Unable to locate wp-load.php.\n" );
        exit( 1 );
    }
}

echo "=== Flushing Navigation Rules ===\n\n";

// 0. Ensure the "inicio" and "blog" pages exist (needed for front page and posts page).
echo "0. Ensuring front page and posts page exist...\n";
$front_page = get_page_by_path( 'inicio' );
if ( ! $front_page ) {
    $front_page_id = wp_insert_post( array(
        'post_title'   => 'Início',
        'post_name'    => 'inicio',
        'post_content' => '<!-- wp:paragraph --><p>Bem-vindo ao Conexão BR Irlanda, o portal da comunidade brasileira na Irlanda.</p><!-- /wp:paragraph -->',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ) );
    echo "   Created front page: inicio (ID: {$front_page_id})\n";
} else {
    echo "   Front page exists: inicio (ID: {$front_page->ID})\n";
}

$blog_page = get_page_by_path( 'blog' );
if ( ! $blog_page ) {
    $blog_page_id = wp_insert_post( array(
        'post_title'   => 'Blog',
        'post_name'    => 'blog',
        'post_content' => '<!-- wp:paragraph --><p>Artigos e notícias para a comunidade brasileira na Irlanda.</p><!-- /wp:paragraph -->',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ) );
    echo "   Created posts page: blog (ID: {$blog_page_id})\n";
} else {
    echo "   Posts page exists: blog (ID: {$blog_page->ID})\n";
}

// Configure front page and posts page settings.
$front_page = get_page_by_path( 'inicio' );
$blog_page  = get_page_by_path( 'blog' );
if ( $front_page ) {
    update_option( 'show_on_front', 'page' );
    update_option( 'page_on_front', (int) $front_page->ID );
    echo "   Front page set to: inicio (ID: {$front_page->ID})\n";
}
if ( $blog_page ) {
    update_option( 'page_for_posts', (int) $blog_page->ID );
    echo "   Posts page set to: blog (ID: {$blog_page->ID})\n";
}

// 1. Delete conflicting static pages (only CPT archive slugs that would conflict)
// NOTE: irlanda, sobre-nos, and contato are real static pages and must NOT be deleted.
echo "1. Removing conflicting static pages...\n";
$conflicting_slugs = array( 'guias', 'eventos', 'cursos', 'empregos', 'apoiadores' );
foreach ( $conflicting_slugs as $slug ) {
    $page = get_page_by_path( $slug );
    if ( $page ) {
        wp_delete_post( $page->ID, true );
        echo "   Deleted static page: {$slug} (ID: {$page->ID})\n";
    } else {
        echo "   No conflicting page found: {$slug}\n";
    }
}

// 2. Re-register CPTs and flush rewrite rules
echo "\n2. Re-registering CPTs and flushing rewrite rules...\n";

if ( class_exists( 'Conexao_Data_Model' ) ) {
    $data_model = Conexao_Data_Model::instance();
    $data_model->register_content_types();
    $data_model->register_taxonomies();
    echo "   CPTs re-registered.\n";
}

flush_rewrite_rules();
echo "   Rewrite rules flushed.\n";

// 3. Update or create the primary navigation menu
echo "\n3. Updating primary navigation menu...\n";

$primary_menu = wp_get_nav_menu_object( 'Menu Principal' );
if ( ! $primary_menu ) {
    $primary_menu_id = wp_create_nav_menu( 'Menu Principal' );
    echo "   Created new menu: Menu Principal (ID: {$primary_menu_id})\n";
} else {
    $primary_menu_id = $primary_menu->term_id;
    echo "   Found existing menu: Menu Principal (ID: {$primary_menu_id})\n";
    
    $menu_items = wp_get_nav_menu_items( $primary_menu_id );
    if ( $menu_items ) {
        foreach ( $menu_items as $item ) {
            wp_delete_post( $item->ID, true );
        }
        echo "   Removed existing menu items.\n";
    }
}

// Define the menu items with correct Portuguese URLs (canonical)
$menu_items_config = array(
    array( 'title' => 'Início', 'url' => home_url( '/' ) ),
    array( 'title' => 'Blog', 'url' => home_url( '/blog/' ) ),
    array( 'title' => 'Guias', 'url' => home_url( '/guias/' ) ),
    array( 'title' => 'Eventos', 'url' => home_url( '/eventos/' ) ),
    array( 'title' => 'Cursos', 'url' => home_url( '/cursos/' ) ),
    array( 'title' => 'Empregos', 'url' => home_url( '/empregos/' ) ),
    array( 'title' => 'Apoiadores', 'url' => home_url( '/apoiadores/' ) ),
    array( 'title' => 'Irlanda', 'url' => home_url( '/irlanda/' ) ),
    array( 'title' => 'Sobre Nós', 'url' => home_url( '/sobre-nos/' ) ),
    array( 'title' => 'Contato', 'url' => home_url( '/contato/' ) ),
);

foreach ( $menu_items_config as $item_config ) {
    $menu_item_data = array(
        'menu-item-title'  => $item_config['title'],
        'menu-item-url'    => $item_config['url'],
        'menu-item-type'   => 'custom',
        'menu-item-status' => 'publish',
    );
    
    $result = wp_update_nav_menu_item( $primary_menu_id, 0, $menu_item_data );
    if ( is_wp_error( $result ) ) {
        echo "   ERROR adding '{$item_config['title']}': {$result->get_error_message()}\n";
    } else {
        echo "   Added: {$item_config['title']} -> {$item_config['url']}\n";
    }
}

$locations = get_theme_mod( 'nav_menu_locations' );
if ( ! is_array( $locations ) ) {
    $locations = array();
}
$locations['primary'] = $primary_menu_id;
set_theme_mod( 'nav_menu_locations', $locations );
echo "\n   Assigned menu to primary location.\n";

// 4. Verify CPT archive URLs
echo "\n4. Verifying CPT archive URLs...\n";
$cpt_checks = array(
    'guide' => 'guias',
    'event' => 'eventos',
    'course' => 'cursos',
    'job' => 'empregos',
    'sponsor' => 'apoiadores',
);

foreach ( $cpt_checks as $cpt => $expected_slug ) {
    $archive_link = get_post_type_archive_link( $cpt );
    if ( $archive_link ) {
        echo "   {$cpt} archive: {$archive_link}\n";
        if ( strpos( $archive_link, $expected_slug ) === false ) {
            echo "   WARNING: Expected '{$expected_slug}' in URL!\n";
        }
    } else {
        echo "   WARNING: No archive link for {$cpt}\n";
    }
}

// 5. Verify static page URLs
echo "\n5. Verifying static page URLs...\n";
$page_checks = array(
    'irlanda' => 'Irlanda',
    'sobre-nos' => 'Sobre Nós',
    'contato' => 'Contato',
);

foreach ( $page_checks as $slug => $title ) {
    $page = get_page_by_path( $slug );
    if ( $page ) {
        echo "   {$slug} -> " . get_permalink( $page->ID ) . " (Title: {$page->post_title})\n";
    } else {
        echo "   WARNING: Page not found: {$slug}\n";
    }
}

echo "\n=== Done! ===\n";
echo "Navigation URLs:\n";
echo "  - Guias:      /guias/\n";
echo "  - Eventos:    /eventos/\n";
echo "  - Cursos:     /cursos/\n";
echo "  - Empregos:   /empregos/\n";
echo "  - Apoiadores: /apoiadores/\n";
echo "  - Irlanda:    /irlanda/\n";
echo "  - Sobre Nós:  /sobre-nos/\n";
echo "  - Contato:    /contato/\n";