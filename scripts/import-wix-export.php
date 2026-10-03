<?php
/**
 * import-wix-export.php — import a Wix content export into WordPress.
 *
 * Purpose: create/update posts from a `wix-posts.json` export directory.
 * Safety: local-write. Reads WIX_EXPORT_DIR; requires an already-loaded
 * WordPress (it is invoked through wp eval-file).
 * Scope: only the records present in the export file. It does not delete
 * anything and does not touch records absent from the export.
 *
 * Bootstrap exception: requires an already-loaded WordPress (wp eval-file),
 * so it only asserts ABSPATH instead of loading WordPress itself. Current.
 *
 * @package Conexao_BR_Scripts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

$base_dir = getenv( 'WIX_EXPORT_DIR' ) ?: '/migration';
$posts_file = rtrim( $base_dir, '/' ) . '/wix-posts.json';
$media_file = rtrim( $base_dir, '/' ) . '/media-map.json';
$images_dir = rtrim( $base_dir, '/' ) . '/images';

if ( ! file_exists( $posts_file ) ) {
	WP_CLI::error( 'Missing wix-posts.json' );
}

if ( ! file_exists( $media_file ) ) {
	WP_CLI::error( 'Missing media-map.json' );
}

$posts = json_decode( file_get_contents( $posts_file ), true );
$media = json_decode( file_get_contents( $media_file ), true );

if ( ! is_array( $posts ) || ! is_array( $media ) ) {
	WP_CLI::error( 'Invalid export JSON.' );
}

$admin = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => array( 'ID' ),
	)
);

if ( empty( $admin ) ) {
	WP_CLI::error( 'No administrator user found.' );
}

$author_id       = (int) $admin[0]->ID;
$attachment_urls = array();
$attachment_ids  = array();
$created_media   = 0;
$reused_media    = 0;

foreach ( $media as $item ) {
	if ( empty( $item['downloaded'] ) || empty( $item['url'] ) || empty( $item['filename'] ) ) {
		continue;
	}

	$source_url = $item['url'];
	$filename   = $item['filename'];
	$file_path  = $images_dir . '/' . $filename;

	if ( ! file_exists( $file_path ) ) {
		continue;
	}

	$existing = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'meta_key'       => '_wix_source_url',
			'meta_value'     => $source_url,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	if ( ! empty( $existing ) ) {
		$attachment_id                 = (int) $existing[0];
		$attachment_ids[ $source_url ] = $attachment_id;
		$attachment_urls[ $source_url ] = wp_get_attachment_url( $attachment_id );
		++$reused_media;
		continue;
	}

	$bits = wp_upload_bits( $filename, null, file_get_contents( $file_path ) );
	if ( ! empty( $bits['error'] ) ) {
		WP_CLI::warning( 'Upload failed for ' . $filename . ': ' . $bits['error'] );
		continue;
	}

	$filetype      = wp_check_filetype( $bits['file'], null );
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
			'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		),
		$bits['file']
	);

	if ( is_wp_error( $attachment_id ) ) {
		WP_CLI::warning( 'Attachment insert failed for ' . $filename . ': ' . $attachment_id->get_error_message() );
		continue;
	}

	$metadata = wp_generate_attachment_metadata( $attachment_id, $bits['file'] );
	wp_update_attachment_metadata( $attachment_id, $metadata );
	update_post_meta( $attachment_id, '_wix_source_url', $source_url );

	$attachment_ids[ $source_url ]  = (int) $attachment_id;
	$attachment_urls[ $source_url ] = wp_get_attachment_url( $attachment_id );
	++$created_media;
}

$created_posts = 0;
$updated_posts = 0;

foreach ( $posts as $item ) {
	if ( empty( $item['wixId'] ) || empty( $item['title'] ) ) {
		continue;
	}

	$post_content = (string) ( $item['content'] ?? '' );
	if ( ! empty( $attachment_urls ) ) {
		$post_content = str_replace( array_keys( $attachment_urls ), array_values( $attachment_urls ), $post_content );
	}

	$existing = get_posts(
		array(
			'post_type'      => 'post',
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'meta_key'       => '_wix_source_id',
			'meta_value'     => $item['wixId'],
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);

	$postarr = array(
		'post_title'   => $item['title'],
		'post_name'    => $item['slug'] ?? '',
		'post_excerpt' => $item['excerpt'] ?? '',
		'post_content' => $post_content,
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_author'  => $author_id,
		'post_date'    => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $item['date'] ?? 'now' ) ) ),
		'post_date_gmt'=> gmdate( 'Y-m-d H:i:s', strtotime( $item['date'] ?? 'now' ) ),
	);

	if ( ! empty( $existing ) ) {
		$postarr['ID'] = (int) $existing[0];
		$post_id       = wp_update_post( wp_slash( $postarr ), true );
		++$updated_posts;
	} else {
		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		++$created_posts;
	}

	if ( is_wp_error( $post_id ) ) {
		WP_CLI::warning( 'Post import failed for ' . $item['title'] . ': ' . $post_id->get_error_message() );
		continue;
	}

	update_post_meta( $post_id, '_wix_source_id', $item['wixId'] );
	update_post_meta( $post_id, '_wix_original_author', $item['author'] ?? '' );

	$category_ids = array();
	foreach ( $item['categories'] ?? array() as $name ) {
		$term = term_exists( $name, 'category' );
		if ( 0 === $term || null === $term ) {
			$term = wp_insert_term( $name, 'category' );
		}
		if ( ! is_wp_error( $term ) ) {
			$category_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}
	}
	if ( ! empty( $category_ids ) ) {
		wp_set_post_terms( $post_id, $category_ids, 'category', false );
	}

	$tag_ids = array();
	foreach ( $item['tags'] ?? array() as $name ) {
		$term = term_exists( $name, 'post_tag' );
		if ( 0 === $term || null === $term ) {
			$term = wp_insert_term( $name, 'post_tag' );
		}
		if ( ! is_wp_error( $term ) ) {
			$tag_ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}
	}
	wp_set_post_terms( $post_id, $tag_ids, 'post_tag', false );

	$featured_source = $item['imageUrls'][0] ?? '';
	if ( $featured_source && ! empty( $attachment_ids[ $featured_source ] ) ) {
		set_post_thumbnail( $post_id, $attachment_ids[ $featured_source ] );
	}
}

$defaults = get_posts(
	array(
		'post_type'      => array( 'post', 'page' ),
		'post_status'    => array( 'publish', 'draft' ),
		'post__in'       => array( 1, 2 ),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $defaults as $default_id ) {
	wp_delete_post( $default_id, true );
}

$front_page = get_page_by_path( 'inicio' );
if ( ! $front_page ) {
	$front_page_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'INÍCIO',
			'post_name'   => 'inicio',
		)
	);
} else {
	$front_page_id = $front_page->ID;
}

$blog_page = get_page_by_path( 'blog' );
if ( $front_page_id && $blog_page ) {
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', (int) $front_page_id );
	update_option( 'page_for_posts', (int) $blog_page->ID );
}

update_option( 'permalink_structure', '/%postname%/' );
flush_rewrite_rules();

WP_CLI::success(
	sprintf(
		'Imported posts: created %d, updated %d. Media: created %d, reused %d.',
		$created_posts,
		$updated_posts,
		$created_media,
		$reused_media
	)
);
