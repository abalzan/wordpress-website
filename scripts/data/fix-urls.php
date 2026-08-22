<?php
/**
 * One-shot fixer: applies URL verification results to the expansion datasets.
 *
 * - Replaces/removes official_website values that failed HTTP checks.
 * - Adds verified Discover Ireland URLs (status "ok"/"suggested").
 * - Clears Discover Ireland URLs that returned 404.
 *
 * Run inside the WordPress container:
 *   php /var/www/html/scripts/data/fix-urls.php
 */

$fixes1 = array(
	// slug => array('official' => url-or-null, 'discover' => url-or-null); null = unchanged
	'glasnevin-cemetery-museum'        => array( 'official' => 'https://www.dctrust.ie/' ),
	'russborough-house'                => array( 'official' => '' ),
	'mount-usher-gardens'              => array( 'official' => 'https://www.avoca.com/en/stores-and-cafes/mount-usher/', 'discover' => 'https://www.discoverireland.ie/wicklow/mount-usher-gardens' ),
	'bective-abbey'                    => array( 'official' => '', 'discover' => 'https://www.discoverireland.ie/meath/bective-abbey' ),
	'killruddery-house-gardens'        => array( 'discover' => 'https://www.discoverireland.ie/wicklow/killruddery-house-gardens' ),
	'emerald-park'                     => array( 'discover' => 'https://www.discoverireland.ie/meath/emerald-park' ),
	'galway-city-museum'               => array( 'discover' => 'https://www.discoverireland.ie/galway/galway-city-museum' ),
	'ennis-friary'                     => array( 'discover' => 'https://www.discoverireland.ie/clare/ennis-friary' ),
	'valentia-island'                  => array( 'discover' => 'https://www.discoverireland.ie/valentia-island' ),
	'coole-park'                       => array( 'official' => '' ),
	'garnish-island'                   => array( 'official' => 'https://heritageireland.ie/places-to-visit/ilnacullin-garinish-island/' ),
	'doneraile-park'                   => array( 'official' => 'https://heritageireland.ie/places-to-visit/doneraile-court-and-estate/' ),
	'kerry-cliffs'                     => array( 'official' => '', 'discover' => 'https://www.discoverireland.ie/kerry/kerry-cliffs' ),
	'derrynane-house'                  => array( 'official' => 'https://heritageireland.ie/places-to-visit/daniel-oconnell-house-derrynane-house/', 'discover' => 'https://www.discoverireland.ie/kerry/derrynane-house' ),
	'gallarus-oratory'                 => array( 'official' => '' ),
	'muckross-abbey'                   => array( 'official' => '' ),
	'emo-court'                        => array( 'discover' => 'https://www.discoverireland.ie/laois/emo-court' ),
	'heywood-gardens'                  => array( 'discover' => 'https://www.discoverireland.ie/laois/heywood-gardens' ),
	'abbeyleix-heritage-house'         => array( 'discover' => 'https://www.discoverireland.ie/laois/abbeyleix-heritage-house' ),
	'clonmacnoise'                     => array( 'discover' => 'https://www.discoverireland.ie/offaly/clonmacnoise' ),
	'lough-boora-discovery-park'       => array( 'discover' => 'https://www.discoverireland.ie/offaly/lough-boora-discovery-park' ),
	'tullamore-dew-experience'         => array( 'official' => 'https://www.tullamoredew.com/en-gb/visit-tullamore-dew/' ),
	'athlone-castle'                   => array( 'discover' => 'https://www.discoverireland.ie/westmeath/athlone-castle' ),
	'boyle-abbey'                      => array( 'discover' => 'https://www.discoverireland.ie/roscommon/boyle-abbey' ),
	'arigna-mining-experience'         => array( 'discover' => 'https://www.discoverireland.ie/roscommon/arigna-mining-experience' ),
	'castle-leslie-estate'             => array( 'discover' => 'https://www.discoverireland.ie/monaghan/castle-leslie-estate' ),
	'monaghan-county-museum'           => array( 'discover' => 'https://www.discoverireland.ie/monaghan/monaghan-county-museum' ),
	'carrickmacross-lace-gallery'      => array( 'discover' => 'https://www.discoverireland.ie/monaghan/carrickmacross-lace-gallery' ),
	// Clear guessed Discover Ireland URLs that returned 404.
	'dun-laoghaire-east-pier'          => array( 'discover' => '' ),
	'portmarnock-beach'                => array( 'discover' => '' ),
	'brittas-bay-beach'                => array( 'discover' => '' ),
	'wicklow-way'                      => array( 'discover' => '' ),
	'kells-historic-town'              => array( 'discover' => '' ),
	'spanish-arch-long-walk'           => array( 'discover' => '' ),
	'holy-island-inis-cealtra'         => array( 'discover' => '' ),
	'craggaunowen'                     => array( 'discover' => '' ),
	'vandeleur-walled-garden'          => array( 'discover' => '' ),
	'charleville-forest-castle'        => array( 'discover' => '' ),
	'mullaghmeen-forest'               => array( 'discover' => '' ),
	'ardagh-heritage-village'          => array( 'discover' => '' ),
	'granard-motte-bailey'             => array( 'discover' => '' ),
	'royal-canal-greenway'             => array( 'discover' => '' ),
	'newcastle-wood'                   => array( 'discover' => '' ),
	'lough-ree'                        => array( 'discover' => '' ),
	'acres-lake-floating-boardwalk'    => array( 'discover' => '' ),
	'lough-allen'                      => array( 'discover' => '' ),
	'lough-rynn-castle-gardens'        => array( 'discover' => '' ),
	'sean-mac-diarmada-cottage'        => array( 'discover' => '' ),
	'killykeen-forest-park'            => array( 'discover' => '' ),
	'dun-a-ri-forest-park'             => array( 'discover' => '' ),
	'shannon-pot'                      => array( 'discover' => '' ),
	'belturbet-railway-station'        => array( 'discover' => '' ),
	'sliabh-beagh'                     => array( 'discover' => '' ),
);

$fixes2 = array(
	'donegal-castle'                   => array( 'discover' => 'https://www.discoverireland.ie/donegal/donegal-castle' ),
	'carrowmore-megalithic-cemetery'   => array( 'discover' => 'https://www.discoverireland.ie/sligo/carrowmore-megalithic-cemetery' ),
	'sligo-abbey'                      => array( 'discover' => 'https://www.discoverireland.ie/sligo/sligo-abbey' ),
	'westport-house'                   => array( 'discover' => 'https://www.discoverireland.ie/mayo/westport-house' ),
	'ceide-fields'                     => array( 'discover' => 'https://www.discoverireland.ie/mayo/ceide-fields' ),
	'wild-nephin-ballycroy-national-park' => array( 'official' => '', 'discover' => 'https://www.discoverireland.ie/mayo/wild-nephin-ballycroy-national-park' ),
	'clare-island'                     => array( 'discover' => 'https://www.discoverireland.ie/mayo/clare-island' ),
	'kilkenny-castle'                  => array( 'discover' => 'https://www.discoverireland.ie/kilkenny/kilkenny-castle' ),
	'medieval-mile-museum'             => array( 'discover' => 'https://www.discoverireland.ie/kilkenny/medieval-mile-museum' ),
	'jerpoint-abbey'                   => array( 'discover' => 'https://www.discoverireland.ie/kilkenny/jerpoint-abbey' ),
	'rothe-house-garden'               => array( 'discover' => 'https://www.discoverireland.ie/kilkenny/rothe-house-garden' ),
	'dunmore-cave'                     => array( 'discover' => 'https://www.discoverireland.ie/kilkenny/dunmore-cave' ),
	'waterford-greenway'               => array( 'discover' => 'https://www.discoverireland.ie/waterford/waterford-greenway' ),
	'mount-congreve-gardens'           => array( 'discover' => 'https://www.discoverireland.ie/waterford/mount-congreve-gardens' ),
	'lismore-castle-gardens'           => array( 'discover' => 'https://www.discoverireland.ie/waterford/lismore-castle-gardens' ),
	'irish-national-heritage-park'     => array( 'discover' => 'https://www.discoverireland.ie/wexford/irish-national-heritage-park' ),
	'ferns-castle'                     => array( 'discover' => 'https://www.discoverireland.ie/wexford/ferns-castle' ),
	'rock-of-cashel'                   => array( 'discover' => 'https://www.discoverireland.ie/tipperary/rock-of-cashel' ),
	'cahir-castle'                     => array( 'discover' => 'https://www.discoverireland.ie/tipperary/cahir-castle' ),
	'swiss-cottage'                    => array( 'discover' => 'https://www.discoverireland.ie/tipperary/swiss-cottage' ),
	'glen-of-aherlow'                  => array( 'discover' => 'https://www.discoverireland.ie/glen-of-aherlow' ),
	'ormond-castle'                    => array( 'discover' => 'https://www.discoverireland.ie/tipperary/ormond-castle' ),
	'mitchelstown-cave'                => array( 'discover' => 'https://www.discoverireland.ie/tipperary/mitchelstown-cave' ),
	'lough-gur'                        => array( 'discover' => 'https://www.discoverireland.ie/limerick/lough-gur' ),
	'castletown-house'                 => array( 'official' => 'https://heritageireland.ie/places-to-visit/castletown-house-and-parklands/', 'discover' => 'https://www.discoverireland.ie/kildare/castletown-house' ),
	'bog-of-allen-nature-centre'       => array( 'discover' => 'https://www.discoverireland.ie/kildare/bog-of-allen-nature-centre' ),
	'maynooth-castle'                  => array( 'discover' => 'https://www.discoverireland.ie/kildare/maynooth-castle' ),
	'altamont-gardens'                 => array( 'discover' => 'https://www.discoverireland.ie/carlow/altamont-gardens' ),
	'borris-house'                     => array( 'discover' => 'https://www.discoverireland.ie/carlow/borris-house' ),
	// Clear guessed Discover Ireland URLs that returned 404.
	'sliabh-liag'                      => array( 'discover' => '' ),
	'errigal'                          => array( 'discover' => '' ),
	'queen-maeves-trail-knocknarea'    => array( 'discover' => '' ),
	'drumcliffe'                       => array( 'discover' => '' ),
	'achill-island'                    => array( 'discover' => '' ),
	'woodstock-gardens'                => array( 'discover' => '' ),
	'coumshingaun-loop'                => array( 'discover' => '' ),
	'the-vee-bay-lough'                => array( 'discover' => '' ),
	'holy-cross-abbey'                 => array( 'discover' => '' ),
	'adare-village'                    => array( 'discover' => '' ),
	'curraghchase-forest-park'         => array( 'discover' => '' ),
	'carlingford'                      => array( 'discover' => '' ),
	'monasterboice'                    => array( 'discover' => '' ),
	'carlingford-lough-greenway'       => array( 'discover' => '' ),
	'millmount-fort-museum'            => array( 'discover' => '' ),
	'ducketts-grove'                   => array( 'discover' => '' ),
	'mount-leinster-nine-stones'       => array( 'discover' => '' ),
);

/**
 * Apply fixes to a dataset file line-by-line.
 */
function conexao_fix_file( $path, $fixes ) {
	$lines = file( $path );
	if ( ! $lines ) {
		fwrite( STDERR, "Cannot read {$path}\n" );
		exit( 1 );
	}
	$applied = 0;
	foreach ( $lines as $i => $line ) {
		if ( ! preg_match( "/'slug' => '([a-z0-9-]+)'/", $line, $m ) ) {
			continue;
		}
		$slug = $m[1];
		if ( ! isset( $fixes[ $slug ] ) ) {
			continue;
		}
		$fix = $fixes[ $slug ];
		if ( array_key_exists( 'official', $fix ) ) {
			$new = addslashes( $fix['official'] );
			$line = preg_replace( "/'official_website' => '(?:[^']|\\\\')*'/", "'official_website' => '{$new}'", $line );
			$applied++;
		}
		if ( array_key_exists( 'discover', $fix ) ) {
			$new = addslashes( $fix['discover'] );
			$line = preg_replace( "/'discover_ireland' => '(?:[^']|\\\\')*'/", "'discover_ireland' => '{$new}'", $line );
			$applied++;
		}
		$lines[ $i ] = $line;
		unset( $fixes[ $slug ] );
	}
	file_put_contents( $path, implode( '', $lines ) );
	echo basename( $path ) . ": {$applied} field(s) updated.\n";
	if ( ! empty( $fixes ) ) {
		echo '  WARNING: slugs not found: ' . implode( ', ', array_keys( $fixes ) ) . "\n";
	}
}

conexao_fix_file( __DIR__ . '/leisure-expansion-data-1.php', $fixes1 );
conexao_fix_file( __DIR__ . '/leisure-expansion-data-2.php', $fixes2 );
echo "Done.\n";