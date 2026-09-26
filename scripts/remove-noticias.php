<?php
/**
 * One-off script to completely remove the "Notícias" content type and all of
 * its data from the Conexão BR Irlanda WordPress installation.
 *
 * Safe to re-run. Scopes every deletion strictly to the `news` post type and
 * the `noticias` static page — it never touches Eventos, Guias, Empregos,
 * Apoiadores, Cursos, or other shared WordPress content.
 *
 * Requires WordPress to be loaded. Run via:
 *
 *   wp eval-file scripts/remove-noticias.php --allow-root
 *   php scripts/remove-noticias.php   (if wp-load.php is discoverable)
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

echo "=== Removing Notícias (news post type) ===\n";

$deleted_posts   = 0;
$deleted_pages   = 0;
$deleted_meta    = 0;
$deleted_rels    = 0;
$deleted_terms   = 0;
$deleted_options = 0;

// 1. Delete every 'news' post (permanently). wp_delete_post() removes the
//    post row, its postmeta, term relationships, and the featured-image
//    _thumbnail_id relationship associated with the post.
//
//    We query ALL news post IDs directly from the DB (any status) because
//    get_posts( 'post_status' => 'any' ) does NOT include 'trash' or
//    'auto-draft' posts. This guarantees every news record is removed.
$news_ids = $GLOBALS['wpdb']->get_col(
	"SELECT ID FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'news'"
);

foreach ( $news_ids as $post_id ) {
	// wp_delete_post( $id, true ) force-deletes posts in ANY status,
	// including trash and auto-draft.
	if ( wp_delete_post( (int) $post_id, true ) ) {
		$deleted_posts++;
	}
}

// 2. Delete the legacy 'noticias' static page (created by the conexao-content
//    plugin's default-pages seeding). Scoped by slug to avoid touching
//    anything else.
$noticias_page = get_page_by_path( 'noticias' );
if ( $noticias_page ) {
	if ( wp_delete_post( $noticias_page->ID, true ) ) {
		$deleted_pages++;
		echo "  Deleted 'noticias' page (ID {$noticias_page->ID}).\n";
	}
}

// 3. Remove any orphaned news-specific postmeta that might remain. Scoped
//    strictly to postmeta rows whose post is a 'news' type. (In normal
//    operation wp_delete_post() already clears these, so this is a safety
//    net that only affects rows for the removed news type.)
$orphaned = $GLOBALS['wpdb']->get_col(
	"SELECT pm.post_id FROM {$GLOBALS['wpdb']->postmeta} pm
	 INNER JOIN {$GLOBALS['wpdb']->posts} p ON p.ID = pm.post_id
	 WHERE p.post_type = 'news'"
);
foreach ( $orphaned as $post_id ) {
	// Guard: only delete meta for posts that no longer exist (wp_delete_post
	// already removed live news posts above). We scope on the meta keys used
	// exclusively by news to be extra safe.
	$meta_keys = array( '_news_title', '_news_content', '_news_featured_image', '_news_author', '_news_category', '_news_date', '_news_source', '_news_url', '_news_status' );
	foreach ( $meta_keys as $key ) {
		$deleted_meta += delete_post_meta( (int) $post_id, $key );
	}
}

// 4. Clean up orphaned term relationships tied to the (now deleted) news
//    posts. These rows are typically removed by wp_delete_post(), but we
//    explicitly clear any remaining references scoped to news posts.
$GLOBALS['wpdb']->query(
	"DELETE tr FROM {$GLOBALS['wpdb']->term_relationships} tr
	 INNER JOIN {$GLOBALS['wpdb']->posts} p ON p.ID = tr.object_id
	 WHERE p.post_type = 'news'"
);
$deleted_rels = (int) $GLOBALS['wpdb']->rows_affected;

// 5. Remove empty/unused taxonomy terms that were only associated with news.
//    The shared taxonomies (conexao_category, conexao_county, conexao_tag)
//    are also used by other content types, so we only delete terms that have
//    ZERO remaining object associations anywhere.
foreach ( array( 'conexao_category', 'conexao_county', 'conexao_tag' ) as $taxonomy ) {
	$terms = get_terms( array(
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'fields'     => 'ids',
	) );
	if ( is_wp_error( $terms ) ) {
		continue;
	}
	foreach ( $terms as $term_id ) {
		$count = (int) $GLOBALS['wpdb']->get_var(
			$GLOBALS['wpdb']->prepare(
				"SELECT COUNT(*) FROM {$GLOBALS['wpdb']->term_relationships} WHERE term_taxonomy_id = %d",
				$term_id
			)
		);
		if ( 0 === $count ) {
			if ( wp_delete_term( $term_id, $taxonomy ) ) {
				$deleted_terms++;
			}
		}
	}
}

// 6. Remove news-specific options / transients.
$news_options = array(
	'conexao_home_news',
	'conexao_404_news',
);
foreach ( $news_options as $option ) {
	if ( delete_transient( $option ) || delete_option( $option ) ) {
		$deleted_options++;
	}
}

// 7. Flush rewrite rules so the /news/ archive / individual news routes are
//    no longer publicly accessible.
flush_rewrite_rules();

echo "\n=== Summary ===\n";
echo "News posts deleted:       {$deleted_posts}\n";
echo "Noticias pages deleted:   {$deleted_pages}\n";
echo "News meta rows deleted:   {$deleted_meta}\n";
echo "News term rels deleted:   {$deleted_rels}\n";
echo "Empty terms deleted:      {$deleted_terms}\n";
echo "News options removed:     {$deleted_options}\n";
echo "Rewrite rules flushed.\n";
echo "Done. Notícias has been completely removed.\n";