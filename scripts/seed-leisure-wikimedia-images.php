<?php
/**
 * Seed script: find and import Wikimedia Commons images for every /lazer/ location.
 *
 * For each existing leisure location this script:
 *   1. Searches Wikimedia Commons using a curated search term (attraction name).
 *   2. Fetches candidate file metadata (image URL, author, license, source URL).
 *   3. Verifies the license is acceptable (Public Domain / CC0 / CC BY / CC BY-SA).
 *   4. Downloads the best-matching image and imports it into the WordPress
 *      Media Library (preferred over hotlinking).
 *   5. Stores all attribution metadata on the leisure post:
 *      _leisure_image_attachment_id, _leisure_image_source,
 *      _leisure_image_source_url, _leisure_image_author,
 *      _leisure_image_license, _leisure_image_alt_text,
 *      _leisure_image_status = 'local'
 *   6. Sets the image as the post featured thumbnail.
 *   7. If no suitable licensed image is found, marks the location as
 *      'Image not found' (status = 'pending') without using a fallback.
 *
 * Run via:
 *   wp eval-file scripts/seed-leisure-wikimedia-images.php --allow-root
 *
 * or inside Docker:
 *   docker exec -i wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/../../..//scripts/seed-leisure-wikimedia-images.php
 *
 * The script is idempotent: it can be re-run to refresh or fix individual locations.
 * Use --dry-run to preview without importing.
 * Use --slug=<slug> to process a single location.
 *
 * @package Conexao_BR_Irlanda
 */

// --- WordPress bootstrap (same pattern as other seed scripts) ---
require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

// --- Parse CLI arguments ---
$is_cli = ( php_sapi_name() === 'cli' );
$args   = $is_cli ? getopt( '', array( 'dry-run::', 'slug::', 'verbose::' ) ) : array();
$dry_run = isset( $args['dry-run'] );
$filter_slug = isset( $args['slug'] ) ? $args['slug'] : '';
$verbose = isset( $args['verbose'] );

// --- Load the Wikimedia client ---
require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-wikimedia-client.php';
$client = new Conexao_Wikimedia_Client();

// --- Curated search terms for each leisure location ---
// Each slug maps to a Wikimedia search query that targets the actual attraction,
// not generic county/city images. These were chosen to return the most relevant
// free-licensed photo on Commons.
//
// Format: 'post_slug' => array(
//     'search'   => 'Wikimedia search query',
//     'title'    => 'Display title (for reports)',
//     'county'   => 'County name (for alt text)',
// ),

$search_terms = array(
	// --- DUBLIN ---
	'trinity-college-book-of-kells' => array( 'search' => 'Trinity College Dublin Book of Kells Long Room', 'title' => 'Trinity College & Book of Kells', 'county' => 'Dublin' ),
	'dublin-castle'                 => array( 'search' => 'Dublin Castle Ireland', 'title' => 'Dublin Castle', 'county' => 'Dublin' ),
	'kilmainham-gaol'               => array( 'search' => 'Kilmainham Gaol', 'title' => 'Kilmainham Gaol', 'county' => 'Dublin' ),
	'guinness-storehouse'           => array( 'search' => 'Guinness Storehouse Dublin', 'title' => 'Guinness Storehouse', 'county' => 'Dublin' ),
	'phoenix-park'                  => array( 'search' => 'Phoenix Park Dublin', 'title' => 'Phoenix Park', 'county' => 'Dublin' ),
	'dublin-zoo'                    => array( 'search' => 'Dublin Zoo', 'title' => 'Dublin Zoo', 'county' => 'Dublin' ),
	'howth-cliff-walk'              => array( 'search' => 'Howth Cliff Walk', 'title' => 'Howth Cliff Walk', 'county' => 'Dublin' ),
	'malahide-castle-gardens'       => array( 'search' => 'Malahide Castle', 'title' => 'Malahide Castle & Gardens', 'county' => 'Dublin' ),

	// --- WICKLOW ---
	'wicklow-mountains-national-park' => array( 'search' => 'Wicklow Mountains National Park', 'title' => 'Wicklow Mountains National Park', 'county' => 'Wicklow' ),
	'glendalough'                    => array( 'search' => 'Glendalough monastic site', 'title' => 'Glendalough', 'county' => 'Wicklow' ),
	'powerscourt-estate'             => array( 'search' => 'Powerscourt House and Gardens', 'title' => 'Powerscourt Estate', 'county' => 'Wicklow' ),
	'powerscourt-waterfall'          => array( 'search' => 'Powerscourt Waterfall', 'title' => 'Powerscourt Waterfall', 'county' => 'Wicklow' ),
	'bray-head'                      => array( 'search' => 'Bray Head Wicklow', 'title' => 'Bray Head', 'county' => 'Wicklow' ),
	'great-sugar-loaf'               => array( 'search' => 'Great Sugar Loaf Wicklow', 'title' => 'Great Sugar Loaf', 'county' => 'Wicklow' ),
	'sally-gap'                      => array( 'search' => 'Sally Gap Wicklow Mountains', 'title' => 'Sally Gap', 'county' => 'Wicklow' ),
	'avondale-forest-park'           => array( 'search' => 'Avondale Forest Park Rathdrum Ireland', 'title' => 'Avondale Forest Park', 'county' => 'Wicklow' ),

	// --- MEATH ---
	'newgrange'                      => array( 'search' => 'Newgrange passage tomb', 'title' => 'Newgrange', 'county' => 'Meath' ),
	'knowth'                         => array( 'search' => 'Knowth passage tomb', 'title' => 'Knowth', 'county' => 'Meath' ),
	'hill-of-tara'                   => array( 'search' => 'Hill of Tara Ireland', 'title' => 'Hill of Tara', 'county' => 'Meath' ),
	'trim-castle'                    => array( 'search' => 'Trim Castle', 'title' => 'Trim Castle', 'county' => 'Meath' ),
	'slane-castle'                   => array( 'search' => 'Slane Castle Ireland', 'title' => 'Slane Castle', 'county' => 'Meath' ),
	'loughcrew'                      => array( 'search' => 'Loughcrew cairns', 'title' => 'Loughcrew', 'county' => 'Meath' ),
	'battle-of-the-boyne-visitor-centre' => array( 'search' => 'Battle of the Boyne Oldbridge', 'title' => 'Battle of the Boyne Visitor Centre', 'county' => 'Meath' ),

	// --- GALWAY ---
	'kylemore-abbey'                 => array( 'search' => 'Kylemore Abbey', 'title' => 'Kylemore Abbey', 'county' => 'Galway' ),
	'connemara-national-park'        => array( 'search' => 'Connemara National Park Diamond Hill', 'title' => 'Connemara National Park', 'county' => 'Galway' ),
	'salthill'                       => array( 'search' => 'Salthill Galway promenade', 'title' => 'Salthill', 'county' => 'Galway' ),
	'sky-road'                       => array( 'search' => 'Sky Road Clifden', 'title' => 'Sky Road', 'county' => 'Galway' ),
	'aran-islands'                   => array( 'search' => 'Aran Islands Inishmore cliffs', 'title' => 'Aran Islands', 'county' => 'Galway' ),
	'dun-aonghasa'                   => array( 'search' => 'Dún Aonghasa', 'title' => 'Dún Aonghasa', 'county' => 'Galway' ),
	'diamond-hill'                   => array( 'search' => 'Diamond Hill Connemara', 'title' => 'Diamond Hill', 'county' => 'Galway' ),
	'galway-atlantaquaria'           => array( 'search' => 'Galway Atlantaquaria', 'title' => 'Galway Atlantaquaria', 'county' => 'Galway' ),

	// --- CLARE ---
	'cliffs-of-moher'                => array( 'search' => 'Cliffs of Moher', 'title' => 'Cliffs of Moher', 'county' => 'Clare' ),
	'burren-national-park'           => array( 'search' => 'The Burren National Park', 'title' => 'Burren National Park', 'county' => 'Clare' ),
	'bunratty-castle-folk-park'      => array( 'search' => 'Bunratty Castle and Folk Park', 'title' => 'Bunratty Castle & Folk Park', 'county' => 'Clare' ),
	'aillwee-caves'                  => array( 'search' => 'Aillwee Caves', 'title' => 'Aillwee Caves', 'county' => 'Clare' ),
	'doolin-cave'                    => array( 'search' => 'Doolin Cave', 'title' => 'Doolin Cave', 'county' => 'Clare' ),
	'loop-head'                      => array( 'search' => 'Loop Head Ireland', 'title' => 'Loop Head', 'county' => 'Clare' ),
	'lahinch-beach'                  => array( 'search' => 'Lahinch Beach', 'title' => 'Lahinch Beach', 'county' => 'Clare' ),
	'kilkee-cliffs'                  => array( 'search' => 'Kilkee Cliffs', 'title' => 'Kilkee Cliffs', 'county' => 'Clare' ),

	// --- CORK ---
	'blarney-castle'                 => array( 'search' => 'Blarney Castle', 'title' => 'Blarney Castle', 'county' => 'Cork' ),
	'cobh'                           => array( 'search' => 'Cobh Ireland', 'title' => 'Cobh', 'county' => 'Cork' ),
	'titanic-experience-cobh'        => array( 'search' => 'Titanic Experience Cobh', 'title' => 'Titanic Experience Cobh', 'county' => 'Cork' ),
	'fota-wildlife-park'            => array( 'search' => 'Fota Wildlife Park', 'title' => 'Fota Wildlife Park', 'county' => 'Cork' ),
	'jameson-distillery-midleton'    => array( 'search' => 'Jameson Distillery Midleton', 'title' => 'Jameson Distillery Midleton', 'county' => 'Cork' ),
	'kinsale'                        => array( 'search' => 'Kinsale Ireland', 'title' => 'Kinsale', 'county' => 'Cork' ),
	'charles-fort'                   => array( 'search' => 'Charles Fort Kinsale', 'title' => 'Charles Fort', 'county' => 'Cork' ),
	'mizen-head'                     => array( 'search' => 'Mizen Head', 'title' => 'Mizen Head', 'county' => 'Cork' ),

	// --- KERRY ---
	'killarney-national-park'        => array( 'search' => 'Killarney National Park', 'title' => 'Killarney National Park', 'county' => 'Kerry' ),
	'muckross-house'                 => array( 'search' => 'Muckross House Killarney', 'title' => 'Muckross House', 'county' => 'Kerry' ),
	'ross-castle'                    => array( 'search' => 'Ross Castle Killarney', 'title' => 'Ross Castle', 'county' => 'Kerry' ),
	'torc-waterfall'                 => array( 'search' => 'Torc Waterfall', 'title' => 'Torc Waterfall', 'county' => 'Kerry' ),
	'gap-of-dunloe'                  => array( 'search' => 'Gap of Dunloe', 'title' => 'Gap of Dunloe', 'county' => 'Kerry' ),
	'ring-of-kerry'                  => array( 'search' => 'Ring of Kerry', 'title' => 'Ring of Kerry', 'county' => 'Kerry' ),
	'dingle-peninsula'               => array( 'search' => 'Dingle Peninsula Slea Head', 'title' => 'Dingle Peninsula', 'county' => 'Kerry' ),
	'inch-beach'                     => array( 'search' => 'Inch Beach Kerry', 'title' => 'Inch Beach', 'county' => 'Kerry' ),
);

// --- Helper: get leisure posts ---
$query = new WP_Query( array(
	'post_type'      => 'leisure',
	'post_status'    => array( 'publish', 'draft' ),
	'posts_per_page' => -1,
	'orderby'        => 'title',
	'order'          => 'ASC',
	'no_found_rows'  => true,
) );

$leisure_posts = $query->posts;
echo "Found " . count( $leisure_posts ) . " leisure locations.\n";

// --- Process each location ---
$imported = 0;
$skipped  = 0;
$not_found = 0;
$report   = array();

foreach ( $leisure_posts as $post ) {
	$post_id = $post->ID;
	$slug    = $post->post_name;
	$title   = $post->post_title;

	// Check if filtered to a single slug.
	if ( $filter_slug && $slug !== $filter_slug ) {
		continue;
	}

	if ( ! isset( $search_terms[ $slug ] ) ) {
		echo "  SKIP: {$title} ({$slug}) - no search term defined\n";
		$skipped++;
		$report[] = array( 'slug' => $slug, 'title' => $title, 'status' => 'no_search_term' );
		continue;
	}

	$term_data   = $search_terms[ $slug ];
	$search      = $term_data['search'];
	$display_title = $term_data['title'];
	$county        = $term_data['county'];
	$existing_att  = get_post_meta( $post_id, '_leisure_image_attachment_id', true );

	if ( $existing_att && ! $dry_run ) {
		// Skip locations that already have a local image (unless --verbose).
		if ( ! $verbose ) {
			echo "  SKIP: {$display_title} - already has a local image (ID: {$existing_att})\n";
			$skipped++;
			$report[] = array( 'slug' => $slug, 'title' => $display_title, 'status' => 'already_has_image' );
			continue;
		}
	}

	echo "  Searching Commons for: {$display_title} ({$slug})\n";
	echo "    Query: {$search}\n";

	$image = $client->find_best_image( $search );

	if ( ! $image ) {
		echo "    NOT FOUND: No acceptable licensed image found on Wikimedia Commons.\n";
		if ( ! $dry_run ) {
			update_post_meta( $post_id, '_leisure_image_status', 'pending' );
			update_post_meta( $post_id, '_leisure_image_source', '' );
			update_post_meta( $post_id, '_leisure_image_source_url', '' );
			update_post_meta( $post_id, '_leisure_image_author', '' );
			update_post_meta( $post_id, '_leisure_image_license', '' );
			update_post_meta( $post_id, '_leisure_image_alt_text', '' );
		}
		$not_found++;
		$report[] = array(
			'slug' => $slug, 'title' => $display_title, 'status' => 'image_not_found',
			'search' => $search,
		);
		continue;
	}

	$license_label = $image['license_label'];
	$license_short = $image['license_short'];
	$author        = $image['author'];
	$page_url      = $image['page_url'];
	$img_url       = $image['image_url'];

	echo "    FOUND: {$image['filename']}\n";
	echo "    Author: {$author}\n";
	echo "    License: {$license_label}\n";
	echo "    Priority: {$image['license_priority']} (1=PD/CC0, 2=CC BY, 3=CC BY-SA)\n";
	echo "    Source: {$page_url}\n";
	echo "    Size: {$image['image_width']}x{$image['image_height']}\n";

	// Build attribution text.
	$attribution = "Foto: {$author}";
	if ( $image['require_attr'] ) {
		$attribution .= ", Licença: {$license_label}";
	}
	$attribution .= ", Fonte: Wikimedia Commons";

	// Build alt text: "Attraction name, County"
	$alt_text = $display_title . ', ' . $county . ', Irlanda';

	if ( $dry_run ) {
		echo "    [DRY RUN] Would import and save metadata.\n";
		$report[] = array(
			'slug' => $slug, 'title' => $display_title, 'status' => 'dry_run',
			'image_url' => $img_url, 'author' => $author,
			'license' => $license_label, 'source_url' => $page_url, 'alt_text' => $alt_text,
		);
		continue;
	}

	// Download and import the image into the Media Library.
	$safe_filename = sanitize_title( $slug );
	$attachment_id = $client->import_image( $img_url, $safe_filename, $post_id );

	if ( is_wp_error( $attachment_id ) ) {
		echo "    ERROR: Failed to import image: " . $attachment_id->get_error_message() . "\n";
		update_post_meta( $post_id, '_leisure_image_status', 'pending' );
		$not_found++;
		$report[] = array(
			'slug' => $slug, 'title' => $display_title, 'status' => 'import_error',
			'error' => $attachment_id->get_error_message(),
		);
		continue;
	}

	// Store all metadata.
	update_post_meta( $post_id, '_leisure_image_attachment_id', (int) $attachment_id );
	update_post_meta( $post_id, '_leisure_image_external_url', '' );
	update_post_meta( $post_id, '_leisure_image_source', 'Wikimedia Commons' );
	update_post_meta( $post_id, '_leisure_image_source_url', $page_url );
	update_post_meta( $post_id, '_leisure_image_author', $author );
	update_post_meta( $post_id, '_leisure_image_license', $license_label );
	update_post_meta( $post_id, '_leisure_image_attribution', $attribution );
	update_post_meta( $post_id, '_leisure_image_alt_text', $alt_text );
	update_post_meta( $post_id, '_leisure_image_status', 'local' );

	// Set as featured image (thumbnail).
	set_post_thumbnail( $post_id, $attachment_id );

	// Set the attachment's alt text.
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

	// Set attachment title and description for accessibility.
	wp_update_post( array(
		'ID'         => $attachment_id,
		'post_title' => $display_title,
		'post_excerpt' => $alt_text,
	) );

	$imported++;
	echo "    IMPORTED: Attachment ID {$attachment_id}\n";
	echo "    Alt text: {$alt_text}\n";
	echo "    Attribution: {$attribution}\n";

	$report[] = array(
		'slug' => $slug, 'title' => $display_title, 'status' => 'imported',
		'attachment_id' => (int) $attachment_id,
		'image_url' => $img_url, 'author' => $author,
		'license' => $license_label, 'source_url' => $page_url, 'alt_text' => $alt_text,
	);
}

// --- Summary ---
echo "\n=== Summary ===\n";
echo "Imported: {$imported}\n";
echo "Skipped (existing image): {$skipped}\n";
echo "Not found (no acceptable license): {$not_found}\n";

if ( $dry_run ) {
	echo "\n[DRY RUN] No changes were made. Run without --dry-run to import.\n";
}

// Save report as JSON for reference.
$report_file = CONEXAO_ADMIN_UX_DIR . 'wikimedia-image-report.json';
file_put_contents( $report_file, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
echo "\nReport saved to: {$report_file}\n";

// --- Detailed report ---
echo "\n=== Detailed Report ===\n";
foreach ( $report as $item ) {
	$status = $item['status'];
	$label  = strtoupper( $status );
	if ( $status === 'imported' ) {
		echo "  ✓ {$item['title']} — {$item['license']} ({$item['author']})\n";
	} elseif ( $status === 'image_not_found' ) {
		echo "  ✗ {$item['title']} — Image not found\n";
	} elseif ( $status === 'import_error' ) {
		echo "  ✗ {$item['title']} — Import error\n";
	} elseif ( $status === 'already_has_image' ) {
		echo "  = {$item['title']} — Already has image\n";
	} elseif ( $status === 'no_search_term' ) {
		echo "  ? {$item['title']} — No search term\n";
	} elseif ( $status === 'dry_run' ) {
		echo "  [DRY] {$item['title']} — {$item['license']}\n";
	}
}

echo "\nDone.\n";
