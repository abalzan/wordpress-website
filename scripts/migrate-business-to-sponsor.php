<?php
/**
 * Migrate existing "business" (Empresas) posts to "sponsor" (Apoiadores).
 *
 * Run via: wp eval-file migrate-business-to-sponsor.php --allow-root
 *
 * Preserves names, logos, descriptions, websites, contact info, social links,
 * featured status, and relevant metadata. Does NOT blindly copy fields that
 * don't make sense for Apoiadores (e.g. WhatsApp is kept as an extra social
 * link; location is preserved).
 *
 * Existing "business" posts are NOT deleted — they are deprioritized from the
 * admin (hidden from the UI) but their data remains in the database until the
 * migration is verified.
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

global $wpdb;

echo "=== Migrating business (Empresas) -> sponsor (Apoiadores) ===\n\n";

// Fetch all existing business posts (any status).
$businesses = get_posts( array(
	'post_type'      => 'business',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'no_found_rows'  => true,
	'update_post_meta_cache' => false,
	'update_post_term_cache' => false,
) );

if ( empty( $businesses ) ) {
	echo "No existing business posts found. Nothing to migrate.\n";
	flush_rewrite_rules();
	echo "Rewrite rules flushed.\n";
	exit( 0 );
}

echo sprintf( "Found %d business post(s) to migrate.\n\n", count( $businesses ) );

$migrated = 0;
$skipped  = 0;

foreach ( $businesses as $business ) {
	echo sprintf( "Processing: %s (ID: %d)\n", $business->post_title, $business->ID );

	// Check if a sponsor post with the same title already exists (idempotency).
	$existing = get_posts( array(
		'post_type'      => 'sponsor',
		'title'          => $business->post_title,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );
	if ( ! empty( $existing ) ) {
		echo "  SKIP: sponsor post with same title already exists (ID: {$existing[0]}).\n";
		$skipped++;
		continue;
	}

	// Determine the sponsor status from the business status meta.
	$business_status = get_post_meta( $business->ID, '_business_status', true );
	if ( empty( $business_status ) ) {
		$business_status = ( 'publish' === $business->post_status ) ? 'published' : 'draft';
	}
	$sponsor_status = in_array( $business_status, array( 'published', 'draft', 'needs_review', 'archived' ), true ) ? $business_status : 'draft';

	// Create the sponsor post.
	$sponsor_id = wp_insert_post( array(
		'post_type'     => 'sponsor',
		'post_status'   => ( 'published' === $sponsor_status ) ? 'publish' : 'draft',
		'post_title'    => $business->post_title,
		'post_content'  => $business->post_content,
		'post_excerpt'  => $business->post_excerpt,
		'post_author'   => $business->post_author,
		'post_date'     => $business->post_date,
		'post_modified' => $business->post_modified,
	), true );

	if ( is_wp_error( $sponsor_id ) ) {
		echo "  ERROR: " . $sponsor_id->get_error_message() . "\n";
		continue;
	}

	// Preserve the slug (so old single URLs keep working via permalink).
	wp_update_post( array( 'ID' => $sponsor_id, 'post_name' => $business->post_name ) );

	// --- Map business meta -> sponsor meta ---
	$meta_map = array(
		'_business_logo'      => '_sponsor_logo',
		'_business_category'  => '_sponsor_category',
		'_business_type'      => '_sponsor_type',
		'_business_address'   => '_sponsor_address',
		'_business_location'  => '_sponsor_location',
		'_business_phone'     => '_sponsor_phone',
		'_business_email'     => '_sponsor_email',
		'_business_website'   => '_sponsor_website',
		'_business_whatsapp'  => '_sponsor_whatsapp',
		'_business_instagram' => '_sponsor_instagram',
		'_business_facebook'  => '_sponsor_facebook',
		'_business_linkedin'  => '_sponsor_linkedin',
		'_business_status'    => '_sponsor_status',
		'_business_featured'  => '_sponsor_featured',
		'_business_display_order' => '_sponsor_display_order',
		'_conexao_featured'   => '_sponsor_featured',
	);

	foreach ( $meta_map as $from => $to ) {
		$value = get_post_meta( $business->ID, $from, true );
		if ( '' !== $value && false !== $value ) {
			update_post_meta( $sponsor_id, $to, $value );
		}
	}

	// If no explicit featured meta, derive from _conexao_featured.
	if ( ! get_post_meta( $sponsor_id, '_sponsor_featured', true ) ) {
		$featured = get_post_meta( $business->ID, '_conexao_featured', true );
		if ( $featured ) {
			update_post_meta( $sponsor_id, '_sponsor_featured', 1 );
		}
	}

	// Copy the featured image (logo).
	$thumb_id = get_post_thumbnail_id( $business->ID );
	if ( $thumb_id ) {
		set_post_thumbnail( $sponsor_id, $thumb_id );
	}

	// Copy taxonomies (conexao_category, conexao_county, conexao_tag).
	foreach ( array( 'conexao_category', 'conexao_county', 'conexao_tag' ) as $taxonomy ) {
		$terms = wp_get_object_terms( $business->ID, $taxonomy, array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			wp_set_object_terms( $sponsor_id, $terms, $taxonomy );
		}
	}

	// Set the sponsor status meta.
	update_post_meta( $sponsor_id, '_sponsor_status', $sponsor_status );

	echo "  Migrated to sponsor ID: {$sponsor_id}\n";
	$migrated++;
}

echo "\n=== Migration complete ===\n";
echo sprintf( "Migrated: %d\n", $migrated );
echo sprintf( "Skipped (already exists): %d\n", $skipped );

// The "business" post type is no longer exposed in the admin (it is hidden
// from the UI by the data model). Its data is preserved in the database.
// We flush rewrite rules so the new sponsor archive (/apoiadores/) works.
flush_rewrite_rules();
echo "Rewrite rules flushed. The /apoiadores/ archive is now active.\n";
echo "Note: Existing business posts remain in the database (not deleted) until the migration is verified.\n";