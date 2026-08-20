<?php
/**
 * Seed script: associate Discover Ireland reference URLs + "Image pending"
 * status for every existing /lazer/ leisure location.
 *
 * Run via:
 *   wp eval-file scripts/seed-leisure-images.php --allow-root
 *
 * Licensing note: Discover Ireland's Terms of Use prohibit copying, downloading
 * or publicly displaying their content/images. Therefore this script does NOT
 * download or hotlink any Discover Ireland image. It only stores the official
 * Discover Ireland page URL as a reference/attribution source (useful for
 * future maintenance) and marks each image as "pending" until a properly
 * licensed image (e.g. from the Media Library / Wikimedia Commons / the
 * location's own official site permitting reuse) is associated.
 *
 * The mapping below uses the Discover Ireland URL patterns observed on the
 * site's sitemap (https://www.discoverireland.ie/sitemap.xml). Where the exact
 * attraction page could not be confirmed, the county/town page is used as a
 * fallback reference.
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

/**
 * Map a leisure location slug to its Discover Ireland reference URL.
 *
 * @param string $slug Leisure post slug.
 * @return string Discover Ireland URL, or empty string when unknown.
 */
function conexao_discover_ireland_url_for( $slug ) {
	$map = array(
		/*  --- DUBLIN ---  */
		'trinity-college-book-of-kells'  => 'https://www.discoverireland.ie/dublin/trinity-college',
		'dublin-castle'                  => 'https://www.discoverireland.ie/dublin/dublin-castle',
		'kilmainham-gaol'                => 'https://www.discoverireland.ie/dublin/kilmainham-gaol',
		'guinness-storehouse'            => 'https://www.discoverireland.ie/dublin/guinness-storehouse',
		'phoenix-park'                   => 'https://www.discoverireland.ie/dublin/phoenix-park',
		'dublin-zoo'                     => 'https://www.discoverireland.ie/dublin/dublin-zoo',
		'howth-cliff-walk'               => 'https://www.discoverireland.ie/dublin/howth-cliff-path-loop',
		'malahide-castle-gardens'        => 'https://www.discoverireland.ie/dublin/malahide-castle-and-gardens',
		/*  --- WICKLOW ---  */
		'wicklow-mountains-national-park' => 'https://www.discoverireland.ie/wicklow/wicklow-mountains-national-park',
		'glendalough'                    => 'https://www.discoverireland.ie/glendalough',
		'powerscourt-estate'             => 'https://www.discoverireland.ie/wicklow/powerscourt-house-gardens',
		'powerscourt-waterfall'          => 'https://www.discoverireland.ie/wicklow/powerscourt-house-gardens',
		'bray-head'                      => 'https://www.discoverireland.ie/bray',
		'great-sugar-loaf'               => 'https://www.discoverireland.ie/wicklow',
		'sally-gap'                      => 'https://www.discoverireland.ie/wicklow',
		'avondale-forest-park'           => 'https://www.discoverireland.ie/wicklow',
		/*  --- MEATH ---  */
		'newgrange'                      => 'https://www.discoverireland.ie/meath/newgrange',
		'knowth'                         => 'https://www.discoverireland.ie/meath/knowth',
		'hill-of-tara'                   => 'https://www.discoverireland.ie/meath/hill-of-tara',
		'trim-castle'                    => 'https://www.discoverireland.ie/meath/trim-castle',
		'slane-castle'                   => 'https://www.discoverireland.ie/meath/slane-castle',
		'loughcrew'                      => 'https://www.discoverireland.ie/meath/loughcrew',
		'battle-of-the-boyne-visitor-centre' => 'https://www.discoverireland.ie/meath/battle-of-the-boyne-visitor-centre',
		/*  --- GALWAY ---  */
		'kylemore-abbey'                 => 'https://www.discoverireland.ie/kylemore',
		'connemara-national-park'        => 'https://www.discoverireland.ie/connemara',
		'salthill'                       => 'https://www.discoverireland.ie/galway/salthill',
		'sky-road'                       => 'https://www.discoverireland.ie/galway/sky-road',
		'aran-islands'                   => 'https://www.discoverireland.ie/aran-islands',
		'dun-aonghasa'                   => 'https://www.discoverireland.ie/aran-islands/dun-aonghasa',
		'diamond-hill'                   => 'https://www.discoverireland.ie/galway/diamond-hill',
		'galway-atlantaquaria'           => 'https://www.discoverireland.ie/galway/galway-atlantaquaria',
		/*  --- CLARE ---  */
		'cliffs-of-moher'                => 'https://www.discoverireland.ie/clare/cliffs-of-moher',
		'burren-national-park'           => 'https://www.discoverireland.ie/the-burren',
		'bunratty-castle-folk-park'      => 'https://www.discoverireland.ie/clare/bunratty-castle-and-folk-park',
		'aillwee-caves'                  => 'https://www.discoverireland.ie/clare/aillwee-burren-experience-cave-tours-birds-of-prey-farm-shop',
		'doolin-cave'                    => 'https://www.discoverireland.ie/clare/doolin-cave',
		'loop-head'                      => 'https://www.discoverireland.ie/clare/loop-head',
		'lahinch-beach'                  => 'https://www.discoverireland.ie/lahinch',
		'kilkee-cliffs'                  => 'https://www.discoverireland.ie/clare/kilkee-cliffs',
		/*  --- CORK ---  */
		'blarney-castle'                 => 'https://www.discoverireland.ie/cork/blarney-castle-and-gardens',
		'cobh'                           => 'https://www.discoverireland.ie/cobh',
		'titanic-experience-cobh'        => 'https://www.discoverireland.ie/cork/titanic-experience-cobh',
		'fota-wildlife-park'             => 'https://www.discoverireland.ie/cork/fota-wildlife-park',
		'jameson-distillery-midleton'    => 'https://www.discoverireland.ie/cork/jameson-distillery-midleton',
		'kinsale'                        => 'https://www.discoverireland.ie/kinsale',
		'charles-fort'                   => 'https://www.discoverireland.ie/cork/charles-fort',
		'mizen-head'                     => 'https://www.discoverireland.ie/cork/mizen-head',
		/*  --- KERRY ---  */
		'killarney-national-park'        => 'https://www.discoverireland.ie/killarney',
		'muckross-house'                 => 'https://www.discoverireland.ie/kerry/muckross-house',
		'ross-castle'                    => 'https://www.discoverireland.ie/kerry/ross-castle',
		'torc-waterfall'                 => 'https://www.discoverireland.ie/kerry/torc-waterfall',
		'gap-of-dunloe'                  => 'https://www.discoverireland.ie/kerry/gap-of-dunloe',
		'ring-of-kerry'                  => 'https://www.discoverireland.ie/kerry/ring-of-kerry',
		'dingle-peninsula'               => 'https://www.discoverireland.ie/dingle',
		'inch-beach'                     => 'https://www.discoverireland.ie/kerry/inch-beach',
	);

	return isset( $map[ $slug ] ) ? $map[ $slug ] : '';
}

echo "Associating Discover Ireland references for /lazer/ locations...\n";

$query = new WP_Query( array(
	'post_type'      => 'leisure',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
) );

$updated = 0;
foreach ( $query->posts as $post_id ) {
	$post   = get_post( $post_id );
	$slug   = $post->post_name;
	$di_url = conexao_discover_ireland_url_for( $slug );

	$current_status = get_post_meta( $post_id, '_leisure_image_status', true );
	$has_local      = (bool) get_post_meta( $post_id, '_leisure_image_attachment_id', true );
	$has_external   = (bool) get_post_meta( $post_id, '_leisure_image_external_url', true );

	if ( $di_url ) {
		update_post_meta( $post_id, '_leisure_image_source_url', $di_url );
		if ( ! get_post_meta( $post_id, '_leisure_image_source', true ) ) {
			update_post_meta( $post_id, '_leisure_image_source', 'Discover Ireland' );
		}
	}

	if ( ! $has_local && ! $has_external ) {
		if ( 'pending' !== $current_status ) {
			update_post_meta( $post_id, '_leisure_image_status', 'pending' );
			$updated++;
			echo "  PENDING: {$post->post_title} ({$slug})\n";
		}
	} elseif ( $has_local && 'local' !== $current_status ) {
		update_post_meta( $post_id, '_leisure_image_status', 'local' );
		$updated++;
	} elseif ( $has_external && 'external' !== $current_status ) {
		update_post_meta( $post_id, '_leisure_image_status', 'external' );
		$updated++;
	}
}

echo "\nUpdated {$updated} leisure locations.\n";
echo "Associated Discover Ireland reference pages for maintenance.\n";
echo "Images remain 'pending' until a properly licensed image is provided.\n";