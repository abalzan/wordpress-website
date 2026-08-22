<?php
/**
 * One-shot: delete attachments for slugs whose Commons match was wrong/weak,
 * so the image seeder can retry them with refined queries.
 *
 * Run inside the container:
 *   wp eval-file scripts/data/retry-image-queries.php --allow-root
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

// slug => refined Commons query for the retry pass.
$retry = array(
	// Clearly wrong matches.
	'brigits-garden'                 => 'Brigits Garden Galway',
	'sean-mac-diarmada-cottage'      => 'Kiltyclogher',
	'cavan-county-museum'            => 'Ballyjamesduff',
	'castle-leslie-estate'           => 'Castle Leslie Glaslough',
	'monaghan-county-museum'         => 'Monaghan town Ireland',
	'mitchelstown-cave'              => 'Mitchelstown Caves',
	'carlingford'                    => 'Carlingford County Louth',
	'borris-house'                   => 'Borris County Carlow',
	'oak-park-forest-park'           => 'Oak Park Carlow',
	'dunbrody-famine-ship'           => 'Dunbrody New Ross',
	'clonmacnoise'                   => 'Clonmacnoise monastery',
	'downpatrick-head'               => 'Dún Briste',
	'timahoe-round-tower'            => 'Timahoe',
	'wild-nephin-ballycroy-national-park' => 'Ballycroy',
	// Weak-but-correct matches worth one better attempt.
	'portmarnock-beach'              => 'Portmarnock',
	'dun-laoghaire-east-pier'        => 'Dún Laoghaire',
	'drumcliffe'                     => 'Drumcliff',
	'shannon-pot'                    => 'Shannon Pot',
	'dunmore-cave'                   => 'Dunmore Cave',
	'holy-cross-abbey'               => 'Holy Cross Abbey',
	'lismore-castle-gardens'         => 'Lismore Castle',
	'bog-of-allen-nature-centre'     => 'Bog of Allen',
	'donadea-forest-park'            => 'Donadea',
	'mondello-park'                  => 'Mondello',
	'adare-village'                  => 'Adare',
	'heywood-gardens'                => 'Heywood Gardens',
	'ardagh-heritage-village'        => 'Ardagh',
	'parkes-castle'                  => "Parke's Castle",
	'arigna-mining-experience'       => 'Arigna',
	'croagh-patrick'                 => 'Croagh Patrick',
	'spike-island-cork'              => 'Spike Island',
	'medieval-mile-museum'           => 'Kilkenny St Mary',
	'rothe-house-garden'             => 'Rothe House',
	'castlecomer-discovery-park'     => 'Castlecomer',
	'saltee-islands'                 => 'Saltee Islands',
	'belvedere-house-gardens'        => 'Belvedere House Mullingar',
);

$meta_keys = array(
	'_leisure_image_attachment_id',
	'_leisure_image_source',
	'_leisure_image_source_url',
	'_leisure_image_author',
	'_leisure_image_license',
	'_leisure_image_attribution',
	'_leisure_image_alt_text',
	'_leisure_image_status',
);

foreach ( $retry as $slug => $query ) {
	$post = get_page_by_path( $slug, OBJECT, 'leisure' );
	if ( ! $post ) {
		echo "NOT FOUND post: {$slug}\n";
		continue;
	}
	$att = get_post_meta( $post->ID, '_leisure_image_attachment_id', true );
	if ( $att ) {
		wp_delete_attachment( (int) $att, true );
		echo "Deleted attachment {$att} for {$slug}\n";
	}
	foreach ( $meta_keys as $key ) {
		delete_post_meta( $post->ID, $key );
	}
}

echo "Cleared " . count( $retry ) . " items for retry.\n";