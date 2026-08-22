<?php
/**
 * Seed script: Wikimedia Commons images for the NEW /lazer/ expansion locations.
 *
 * Reuses the EXISTING image workflow (Conexao_Wikimedia_Client from
 * conexao-admin-ux) with the same rules as scripts/seed-leisure-wikimedia-images.php:
 *   - Searches Wikimedia Commons (curated search terms below).
 *   - Accepts only PD / CC0 / CC BY / CC BY-SA licenses.
 *   - Downloads the image into the Media Library (never hotlinks).
 *   - Stores author/license/source/attribution metadata on the post.
 *   - Sets the attachment as featured image; marks status 'local'.
 *   - Marks '_leisure_image_status' = 'pending' when nothing suitable is found.
 *
 * Only processes leisure posts WITHOUT a local image yet (the new expansion
 * records); existing locations are skipped automatically.
 *
 * Run via:
 *   wp eval-file scripts/seed-leisure-expansion-images.php --allow-root
 *   wp eval-file scripts/seed-leisure-expansion-images.php --allow-root --dry-run
 *   wp eval-file scripts/seed-leisure-expansion-images.php --allow-root --slug=<slug>
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'WP_USE_THEMES', false );
	$dir = dirname( __FILE__ );
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
	if ( ! defined( 'ABSPATH' ) ) {
		fwrite( STDERR, "Unable to locate wp-load.php\n" );
		exit( 1 );
	}
}

$is_cli     = ( php_sapi_name() === 'cli' );
$args       = $is_cli ? getopt( '', array( 'dry-run::', 'slug::', 'shard::', 'shards::' ) ) : array();
$dry_run    = isset( $args['dry-run'] );
$filter_slug = isset( $args['slug'] ) ? $args['slug'] : '';
// Optional parallel sharding: IMGSEED_SHARD=N IMGSEED_SHARDS=M processes items
// where (index % M) === N. (Env vars are used instead of CLI flags because
// WP-CLI rejects unknown --parameters before they reach the script.)
$shard  = isset( $args['shard'] ) ? (int) $args['shard'] : (int) getenv( 'IMGSEED_SHARD' );
$shards = isset( $args['shards'] ) ? max( 1, (int) $args['shards'] ) : max( 1, (int) getenv( 'IMGSEED_SHARDS' ) );

require_once CONEXAO_ADMIN_UX_DIR . 'includes/class-wikimedia-client.php';
$client = new Conexao_Wikimedia_Client();

/**
 * Curated Commons search terms for slugs where the plain title would return
 * poor matches. Everything else searches by post title.
 */
$overrides = array(
	// Dublin / Wicklow / Meath
	'national-museum-archaeology'        => 'National Museum of Ireland Kildare Street',
	'epic-irish-emigration-museum'       => 'EPIC Irish Emigration Museum Dublin',
	'national-botanic-gardens-dublin'    => 'National Botanic Gardens Dublin',
	'glasnevin-cemetery-museum'          => 'Glasnevin Cemetery',
	'dun-laoghaire-east-pier'            => 'Dún Laoghaire pier',
	'portmarnock-beach'                  => 'Portmarnock beach',
	'russborough-house'                  => 'Russborough House',
	'wicklow-historic-gaol'              => 'Wicklow Gaol',
	'brittas-bay-beach'                  => 'Brittas Bay',
	'killruddery-house-gardens'          => 'Killruddery',
	'emerald-park'                       => 'Tayto Park',
	'kells-historic-town'                => 'Kells County Meath',
	// Galway / Clare / Cork / Kerry
	'spanish-arch-long-walk'             => 'Spanish Arch Galway',
	'coole-park'                         => 'Coole Park Gort',
	'portumna-castle-gardens'            => 'Portumna Castle',
	'holy-island-inis-cealtra'           => 'Inis Cealtra',
	'spanish-point-beach'                => 'Spanish Point Clare',
	'vandeleur-walled-garden'            => 'Vandeleur garden Kilrush',
	'english-market-cork'                => 'English Market Cork',
	'blackrock-castle-observatory'       => 'Blackrock Castle Cork',
	'spike-island-cork'                  => 'Spike Island Cork harbour',
	'garnish-island'                     => 'Garinish Island Glengarriff',
	'derrynane-house'                    => 'Derrynane House',
	'banna-strand'                       => 'Banna Strand Kerry',
	// Midlands
	'rock-of-dunamase'                   => 'Dunamase',
	'timahoe-round-tower'                => 'Timahoe round tower',
	'abbeyleix-heritage-house'           => 'Abbeyleix',
	'charleville-forest-castle'          => 'Charleville Castle Tullamore',
	'tullamore-dew-experience'           => 'Tullamore Dew',
	'belvedere-house-gardens'            => 'Belvedere House Westmeath',
	'mullaghmeen-forest'                 => 'Mullaghmeen',
	'ardagh-heritage-village'            => 'Ardagh County Longford',
	'granard-motte-bailey'               => 'Granard motte',
	'royal-canal-greenway'               => 'Royal Canal Ireland',
	'lough-ree'                          => 'Lough Ree',
	'rathcroghan'                        => 'Rathcroghan',
	'strokestown-park-famine-museum'     => 'Strokestown Park',
	'arigna-mining-experience'           => 'Arigna mining',
	'lough-key-forest-park'              => 'Lough Key',
	// North-west
	'glencar-waterfall'                  => 'Glencar Waterfall',
	'parkes-castle'                      => "Parke's Castle",
	'acres-lake-floating-boardwalk'      => 'Drumshanbo boardwalk',
	'lough-rynn-castle-gardens'          => 'Lough Rynn',
	'sean-mac-diarmada-cottage'          => 'Sean Mac Diarmada',
	'cavan-burren-park'                  => 'Cavan Burren',
	'killykeen-forest-park'              => 'Killykeen',
	'dun-a-ri-forest-park'               => 'Dún na Rí forest park',
	'cavan-county-museum'                => 'Cavan County Museum',
	'belturbet-railway-station'          => 'Belturbet railway station',
	'castle-leslie-estate'               => 'Castle Leslie',
	'carrickmacross-lace-gallery'        => 'Carrickmacross lace',
	'patrick-kavanagh-centre'            => 'Patrick Kavanagh Inniskeen',
	// Donegal / Sligo / Mayo
	'sliabh-liag'                        => 'Slieve League',
	'fanad-head-lighthouse'              => 'Fanad Head Lighthouse',
	'grianan-of-aileach'                 => 'Grianan Ailigh',
	'ards-forest-park'                   => 'Ards Forest Park Donegal',
	'strandhill-beach'                   => 'Strandhill',
	'carrowmore-megalithic-cemetery'     => 'Carrowmore',
	'queen-maeves-trail-knocknarea'      => 'Knocknarea',
	'lissadell-house'                    => 'Lissadell House',
	'drumcliffe'                         => 'Drumcliff Sligo',
	'eagles-flying'                      => 'Eagles Flying Sligo',
	'national-museum-country-life'       => 'National Museum of Ireland Country Life Turlough',
	'ceide-fields'                       => 'Céide Fields',
	'great-western-greenway'             => 'Great Western Greenway',
	'wild-nephin-ballycroy-national-park'=> 'Ballycroy National Park',
	// South-east / south-west
	'house-of-waterford-crystal'         => 'Waterford Crystal factory',
	'reginals-tower'                     => "Reginald's Tower",
	'waterford-greenway'                 => 'Waterford Greenway',
	'copper-coast-geopark'               => 'Copper Coast Waterford',
	'mount-congreve-gardens'             => 'Mount Congreve',
	'lismore-castle-gardens'             => 'Lismore Castle',
	'ardmore-round-tower'                => 'Ardmore Round Tower',
	'coumshingaun-loop'                  => 'Coumshingaun',
	'hook-lighthouse'                    => 'Hook Head Lighthouse',
	'dunbrody-famine-ship'               => 'Dunbrody famine ship',
	'curracloe-beach'                    => 'Curracloe',
	'wexford-wildfowl-reserve'           => 'Wexford Wildfowl Reserve',
	'irish-national-heritage-park'       => 'Irish National Heritage Park',
	'swiss-cottage'                      => 'Swiss Cottage Cahir',
	'glen-of-aherlow'                    => 'Glen of Aherlow',
	'the-vee-bay-lough'                  => 'The Vee Tipperary',
	'ormond-castle'                      => 'Ormond Castle Carrick-on-Suir',
	'mitchelstown-cave'                  => 'Mitchelstown Cave',
	'holy-cross-abbey'                   => 'Holy Cross Abbey Tipperary',
	'king-johns-castle-limerick'         => "King John's Castle Limerick",
	'hunt-museum'                        => 'Hunt Museum Limerick',
	'st-marys-cathedral-limerick'        => "St Mary's Cathedral Limerick",
	'adare-village'                      => 'Adare',
	'foynes-flying-boat-museum'          => 'Foynes flying boat',
	'lough-gur'                          => 'Lough Gur',
	'curraghchase-forest-park'           => 'Curraghchase',
	'irish-national-stud-japanese-gardens' => 'Japanese Gardens Kildare',
	'castletown-house'                   => 'Castletown House Celbridge',
	'bog-of-allen-nature-centre'         => 'Bog of Allen',
	'pollardstown-fen'                   => 'Pollardstown Fen',
	'donadea-forest-park'                => 'Donadea',
	'lullymore-heritage-park'            => 'Lullymore',
	'mondello-park'                      => 'Mondello Park',
	'king-johns-castle-carlingford'      => "King John's Castle Carlingford",
	'carlingford-lough-greenway'         => 'Carlingford Lough',
	'millmount-fort-museum'              => 'Millmount Drogheda',
	// Carlow
	'altamont-gardens'                   => 'Altamont Gardens',
	'ducketts-grove'                     => "Duckett's Grove",
	'huntington-castle-gardens'          => 'Huntington Castle Clonegal',
	'borris-house'                       => 'Borris House',
	'oak-park-forest-park'               => 'Oak Park Carlow',
	'mount-leinster-nine-stones'         => 'Mount Leinster',
	// Disambiguation additions (avoid wrong-location matches).
	'newcastle-wood'                     => 'Newcastle Wood County Longford',
	'woodstock-gardens'                  => 'Woodstock Gardens Inistioge',
	// Refined retries for items pending after the first pass.
	'eagles-flying'                      => 'Golden Eagle Ireland',
	'westport-house'                     => 'Westport House Ireland',
	'acres-lake-floating-boardwalk'      => 'Drumshanbo',
	'kilbeggan-distillery'               => 'Kilbeggan Distillery',
	'glendeer-pet-farm'                  => 'Glendeer',
	'newcastle-wood'                     => 'Newcastle Wood',
	'athlone-castle'                     => 'Athlone Castle',
	'coole-park'                         => 'Coole Park',
);

// Merge refined retry queries for the second pass (retry overrides win).
$retry_overrides = require dirname( __FILE__ ) . '/data/image-retry-overrides.php';
$overrides = array_merge( $overrides, $retry_overrides );

$query = new WP_Query( array(
	'post_type'      => 'leisure',
	'post_status'    => array( 'publish', 'draft' ),
	'posts_per_page' => -1,
	'orderby'        => 'ID',
	'order'          => 'ASC',
	'no_found_rows'  => true,
	'meta_query'     => array(
		array(
			'key'     => '_leisure_image_attachment_id',
			'compare' => 'NOT EXISTS',
		),
	),
) );

$posts = $query->posts;
echo 'Found ' . count( $posts ) . " leisure locations without a local image.\n";

$imported = 0;
$not_found = 0;
$errors = 0;
$report = array();

$item_index = 0;
foreach ( $posts as $post ) {
	$post_id = $post->ID;
	$slug    = $post->post_name;
	$title   = $post->post_title;
	$item_index++;

	if ( $filter_slug && $slug !== $filter_slug ) {
		continue;
	}

	if ( ( $item_index % $shards ) !== $shard ) {
		continue;
	}

	$county = get_post_meta( $post_id, '_leisure_county', true );

	$search = isset( $overrides[ $slug ] ) ? $overrides[ $slug ] : $title;

	echo "  [{$slug}] searching Commons: {$search}\n";

	$image = $client->find_best_image( $search );

	// Fallback: simplify the query (drop parentheticals/suffixes).
	if ( ! $image && ! isset( $overrides[ $slug ] ) ) {
		$simplified = trim( preg_replace( '/\s*\(.*?\)\s*/', ' ', $title ) );
		if ( $simplified && $simplified !== $title ) {
			echo "    retrying with: {$simplified}\n";
			$image = $client->find_best_image( $simplified );
		}
	}

	if ( ! $image ) {
		echo "    NOT FOUND: no acceptable licensed image.\n";
		$not_found++;
		if ( ! $dry_run ) {
			update_post_meta( $post_id, '_leisure_image_status', 'pending' );
		}
		$report[] = array( 'slug' => $slug, 'title' => $title, 'status' => 'image_not_found', 'search' => $search );
		continue;
	}

	$license_label = $image['license_label'];
	$author        = $image['author'];
	$page_url      = $image['page_url'];
	$img_url       = $image['image_url'];

	echo "    FOUND: {$image['filename']} | {$author} | {$license_label}\n";

	$attribution = "Foto: {$author}";
	if ( $image['require_attr'] ) {
		$attribution .= ", Licença: {$license_label}";
	}
	$attribution .= ', Fonte: Wikimedia Commons';

	$alt_text = $title . ', ' . $county . ', Irlanda';

	if ( $dry_run ) {
		echo "    [DRY RUN] Would import.\n";
		$report[] = array( 'slug' => $slug, 'title' => $title, 'status' => 'dry_run', 'license' => $license_label, 'source_url' => $page_url );
		continue;
	}

	$safe_filename = sanitize_title( $slug );
	$attachment_id = $client->import_image( $img_url, $safe_filename, $post_id );

	if ( is_wp_error( $attachment_id ) ) {
		echo "    ERROR importing image: " . $attachment_id->get_error_message() . "\n";
		update_post_meta( $post_id, '_leisure_image_status', 'pending' );
		$errors++;
		$report[] = array( 'slug' => $slug, 'title' => $title, 'status' => 'import_error', 'error' => $attachment_id->get_error_message() );
		continue;
	}

	update_post_meta( $post_id, '_leisure_image_attachment_id', (int) $attachment_id );
	update_post_meta( $post_id, '_leisure_image_source', 'Wikimedia Commons' );
	update_post_meta( $post_id, '_leisure_image_source_url', $page_url );
	update_post_meta( $post_id, '_leisure_image_author', $author );
	update_post_meta( $post_id, '_leisure_image_license', $license_label );
	update_post_meta( $post_id, '_leisure_image_attribution', $attribution );
	update_post_meta( $post_id, '_leisure_image_alt_text', $alt_text );
	update_post_meta( $post_id, '_leisure_image_status', 'local' );

	set_post_thumbnail( $post_id, $attachment_id );
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
	wp_update_post( array(
		'ID'           => $attachment_id,
		'post_title'   => $title,
		'post_excerpt' => $alt_text,
	) );

	$imported++;
	echo "    IMPORTED: attachment {$attachment_id}\n";
	$report[] = array(
		'slug' => $slug, 'title' => $title, 'status' => 'imported',
		'attachment_id' => (int) $attachment_id,
		'author' => $author, 'license' => $license_label, 'source_url' => $page_url,
	);
}

echo "\n=== Summary ===\n";
echo "Imported: {$imported}\n";
echo "Not found (marked pending): {$not_found}\n";
echo "Import errors: {$errors}\n";
if ( $dry_run ) {
	echo "[DRY RUN] No changes were made.\n";
}

file_put_contents(
	CONEXAO_ADMIN_UX_DIR . 'wikimedia-image-report.json',
	json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
);
echo "Report saved to: " . CONEXAO_ADMIN_UX_DIR . "wikimedia-image-report.json\n";