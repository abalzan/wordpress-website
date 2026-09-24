<?php
/**
 * Stage 5 — deterministic Blog translation inventory (local/staging).
 *
 * Emits the machine-readable inventory used by the Stage 5 completion audit and
 * by the "Portuguese originals unchanged" verification:
 *
 *   { "generated_at": …, "posts_page": {…}, "pt": [ … ], "en": [ … ],
 *     "categories": [ … ], "audit": { … } }
 *
 * Usage (from the project root, local Docker):
 *   cat scripts/stage5-blog-inventory.php | docker compose exec -T wordpress wp eval-file - --allow-root --json
 *   cat scripts/stage5-blog-inventory.php | docker compose exec -T wordpress wp eval-file - --allow-root --out=/path/to/inventory.json
 *
 * LOCAL / STAGING ONLY.
 *
 * @package Conexao_Blog_Translation
 */

if ( ! defined( 'ABSPATH' ) ) {
	require_once dirname( __DIR__ ) . '/wp-load.php';
}

$argv_args = isset( $args ) ? (array) $args : array();
$out_file  = '';

foreach ( $argv_args as $arg ) {
	if ( 0 === strpos( (string) $arg, '--out=' ) ) {
		$out_file = substr( (string) $arg, 6 );
	} elseif ( 0 === strpos( (string) $arg, 'out=' ) ) {
		// WP-CLI's eval-file only forwards positional tokens, so `out=<path>` is
		// the supported invocation shape.
		$out_file = substr( (string) $arg, 4 );
	}
}

/**
 * Describe one post for the inventory.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function conexao_stage5_inventory_post( int $post_id ): array {
	$post = get_post( $post_id );

	return array(
		'id'           => $post_id,
		'slug'         => (string) $post->post_name,
		'title'        => (string) $post->post_title,
		'status'       => (string) $post->post_status,
		'date'         => (string) $post->post_date,
		'modified'     => (string) $post->post_modified,
		'author'       => (int) $post->post_author,
		'language'     => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'slug' ) : '',
		'url'          => (string) get_permalink( $post_id ),
		'excerpt'      => (string) $post->post_excerpt,
		'content'      => (string) $post->post_content,
		'content_hash' => md5( (string) $post->post_content ),
		'thumbnail'    => (int) get_post_thumbnail_id( $post_id ),
		'meta_desc'    => (string) get_post_meta( $post_id, 'conexao_meta_description', true ),
		'categories'   => array_map(
			static function ( $term ) {
				return array( 'id' => (int) $term->term_id, 'slug' => (string) $term->slug, 'name' => (string) $term->name );
			},
			wp_get_post_terms( $post_id, 'category' )
		),
		'tags'         => array_map(
			static function ( $term ) {
				return array( 'id' => (int) $term->term_id, 'slug' => (string) $term->slug );
			},
			wp_get_post_terms( $post_id, 'post_tag' )
		),
		'translations' => function_exists( 'pll_get_post_translations' ) ? array_map( 'intval', pll_get_post_translations( $post_id ) ) : array(),
		'reading_time' => function_exists( 'conexao_reading_time_minutes' ) ? (int) conexao_reading_time_minutes( $post_id ) : null,
	);
}

$pt_ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'lang' => 'pt', 'orderby' => 'date', 'order' => 'DESC' ) );
$en_ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'lang' => 'en', 'orderby' => 'date', 'order' => 'DESC' ) );

$posts_page_pt = (int) get_option( 'page_for_posts' );
$posts_page_en = ( function_exists( 'pll_get_post' ) && $posts_page_pt > 0 ) ? (int) pll_get_post( $posts_page_pt, 'en' ) : 0;

$inventory = array(
	'generated_by' => 'scripts/stage5-blog-inventory.php',
	'site'         => home_url( '/' ),
	'posts_page'   => array(
		'pt_id'    => $posts_page_pt,
		'pt_url'   => $posts_page_pt > 0 ? (string) get_permalink( $posts_page_pt ) : '',
		'en_id'    => $posts_page_en,
		'en_url'   => $posts_page_en > 0 ? (string) get_permalink( $posts_page_en ) : '',
		'linked'   => $posts_page_pt > 0 && $posts_page_en > 0 && (int) pll_get_post( $posts_page_en, 'pt' ) === $posts_page_pt,
	),
	'pt'           => array_map( 'conexao_stage5_inventory_post', $pt_ids ),
	'en'           => array_map( 'conexao_stage5_inventory_post', $en_ids ),
	'categories'   => array_map(
		static function ( $term ) {
			$en = ( function_exists( 'pll_get_term' ) ) ? (int) pll_get_term( (int) $term->term_id, 'en' ) : 0;

			return array(
				'pt_id'      => (int) $term->term_id,
				'pt_slug'    => (string) $term->slug,
				'pt_name'    => (string) $term->name,
				'count'      => (int) $term->count,
				'language'   => function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( (int) $term->term_id, 'slug' ) : '',
				'en_id'      => $en,
				'en_slug'    => $en > 0 ? (string) get_term_field( 'slug', $en ) : '',
				'en_name'    => $en > 0 ? (string) get_term_field( 'name', $en ) : '',
				'en_count'   => $en > 0 ? (int) get_term_field( 'count', $en ) : 0,
			);
		},
		get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) )
	),
	'audit'        => function_exists( 'conexao_blog_translation_audit' ) ? conexao_blog_translation_audit() : null,
);

$json = wp_json_encode( $inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

if ( '' !== $out_file ) {
	$written = file_put_contents( $out_file, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local tooling.
	echo "wrote {$written} bytes to {$out_file}\n";
	return;
}

echo $json, "\n";
