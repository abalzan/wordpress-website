<?php
/**
 * Seed script: /lazer/ leisure & tourism locations.
 *
 * Populates the `leisure` CPT with an initial set of permanent attractions,
 * museums, parks, beaches, castles and outdoor destinations across all 26
 * Republic of Ireland counties. Run via:
 *
 *   wp eval-file scripts/seed-leisure-locations.php --allow-root
 *
 * Only real, well-known, permanent destinations are included. Addresses,
 * prices, accessibility, opening hours and other optional attributes are left
 * empty unless confident they are accurate — unreliable data is NOT invented.
 * Uses the shared `conexao_county` and `conexao_category` taxonomies.
 */

require_once __DIR__ . '/lib/bootstrap.php';
conexao_script_load_wordpress();

/**
 * Resolve a term (by name) in a taxonomy, creating it if missing.
 *
 * @param string $name     Term name.
 * @param string $taxonomy Taxonomy slug.
 * @return int|WP_Error Term ID on success.
 */
function conexao_seed_leisure_term( $name, $taxonomy ) {
	$term = term_exists( $name, $taxonomy );
	if ( $term && ! is_wp_error( $term ) ) {
		return (int) $term['term_id'];
	}
	$created = wp_insert_term( $name, $taxonomy );
	if ( is_wp_error( $created ) ) {
		return $created;
	}
	return (int) $created['term_id'];
}

/**
 * Create or update a leisure location.
 *
 * @param array $data Location data.
 * @return int Post ID.
 */
function conexao_seed_leisure_location( $data ) {
	$existing = get_page_by_path( $data['slug'], OBJECT, 'leisure' );
	if ( $existing ) {
		return (int) $existing->ID;
	}

	$defaults = array(
		'excerpt'    => '',
		'content'    => '',
		'county'     => '',
		'town'       => '',
		'address'    => '',
		'website'    => '',
		'official_website' => '',
		'discover_ireland' => '',
		'map_url'    => '',
		'feature'    => false,
		'free'       => '',
		'family'     => false,
		'accessibility' => false,
		'pet'        => false,
		'indoor'     => false,
		'outdoor'    => false,
		'parking'    => false,
		'booking'    => false,
		'duration'   => '',
		'best_time'  => '',
		'category'   => 'Outros',
	);
	$data = wp_parse_args( $data, $defaults );

	// Resolve taxonomy terms.
	$category_term = conexao_seed_leisure_term( $data['category'], 'conexao_category' );
	$county_term   = $data['county'] ? conexao_seed_leisure_term( $data['county'], 'conexao_county' ) : 0;

	$post_id = wp_insert_post( array(
		'post_title'   => $data['title'],
		'post_name'    => $data['slug'],
		'post_content' => $data['content'],
		'post_excerpt' => $data['excerpt'],
		'post_status'  => 'publish',
		'post_type'    => 'leisure',
	) );

	if ( is_wp_error( $post_id ) ) {
		fwrite( STDERR, "  ERROR: {$data['title']} - {$post_id->get_error_message()}\n" );
		return 0;
	}

	// Assign taxonomies.
	$term_ids = array( (int) $category_term );
	$result   = wp_set_object_terms( $post_id, $term_ids, 'conexao_category' );
	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, "  WARN: category assignment failed for {$data['title']}\n" );
	}

	if ( $county_term ) {
		$result = wp_set_object_terms( $post_id, array( (int) $county_term ), 'conexao_county' );
		if ( is_wp_error( $result ) ) {
			fwrite( STDERR, "  WARN: county assignment failed for {$data['title']}\n" );
		}
	}

	// Structured characteristics (conexao_leisure_attribute taxonomy).
	// Canonical representation; legacy meta is also written below for
	// transition compatibility.
	$attr_term_ids = array();
	$attr_map = array(
		'family'        => 'Famílias',
		'outdoor'       => 'Exterior',
		'indoor'        => 'Interior',
		'booking'       => 'Necessita reserva',
		'accessibility' => 'Acessível',
		'pet'           => 'Pet friendly',
		'parking'       => 'Estacionamento',
	);
	foreach ( $attr_map as $data_key => $term_name ) {
		if ( ! empty( $data[ $data_key ] ) ) {
			$term = term_exists( $term_name, 'conexao_leisure_attribute' );
			if ( $term && ! is_wp_error( $term ) ) {
				$attr_term_ids[] = (int) $term['term_id'];
			}
		}
	}
	// Indoor + outdoor collapse into the combined term.
	if ( ! empty( $data['indoor'] ) && ! empty( $data['outdoor'] ) ) {
		$int_term = term_exists( 'Interior', 'conexao_leisure_attribute' );
		$ext_term = term_exists( 'Exterior', 'conexao_leisure_attribute' );
		$combo    = term_exists( 'Interior + exterior', 'conexao_leisure_attribute' );
		$filtered = array();
		foreach ( $attr_term_ids as $tid ) {
			if ( $int_term && ! is_wp_error( $int_term ) && (int) $int_term['term_id'] === $tid ) {
				continue;
			}
			if ( $ext_term && ! is_wp_error( $ext_term ) && (int) $ext_term['term_id'] === $tid ) {
				continue;
			}
			$filtered[] = $tid;
		}
		$attr_term_ids = $filtered;
		if ( $combo && ! is_wp_error( $combo ) ) {
			$attr_term_ids[] = (int) $combo['term_id'];
		}
	}
	// Gratuito from the free flag.
	if ( ! empty( $data['free'] ) ) {
		$free_term = term_exists( 'Gratuito', 'conexao_leisure_attribute' );
		if ( $free_term && ! is_wp_error( $free_term ) ) {
			$attr_term_ids[] = (int) $free_term['term_id'];
		}
	}
	if ( ! empty( $attr_term_ids ) ) {
		$attr_term_ids = array_values( array_unique( array_map( 'intval', $attr_term_ids ) ) );
		wp_set_object_terms( $post_id, $attr_term_ids, 'conexao_leisure_attribute' );
	}

	// Set meta fields.
	update_post_meta( $post_id, '_leisure_county', $data['county'] );
	update_post_meta( $post_id, '_leisure_town', $data['town'] );
	update_post_meta( $post_id, '_leisure_address', $data['address'] );
	update_post_meta( $post_id, '_leisure_website', $data['website'] );

	// Official website is the primary external destination. Backfill from the
	// legacy `website` field when the new field was not explicitly provided,
	// so existing seeded entries automatically become direct-linked.
	$official_website = ! empty( $data['official_website'] ) ? $data['official_website'] : $data['website'];
	update_post_meta( $post_id, '_leisure_official_website', $official_website );
	update_post_meta( $post_id, '_leisure_discover_ireland', $data['discover_ireland'] );

	update_post_meta( $post_id, '_leisure_map_url', $data['map_url'] );
	update_post_meta( $post_id, '_leisure_feature', $data['feature'] ? '1' : '' );
	// _leisure_free: store the canonical Portuguese label instead of a bare value
	// so the legacy meta is meaningful if ever read during the transition.
	update_post_meta( $post_id, '_leisure_free', ! empty( $data['free'] ) ? 'Gratuito' : '' );
	update_post_meta( $post_id, '_leisure_family', $data['family'] ? '1' : '' );
	update_post_meta( $post_id, '_leisure_accessibility', $data['accessibility'] ? '1' : '' );
	update_post_meta( $post_id, '_leisure_pet_friendly', $data['pet'] ? '1' : '' );
	update_post_meta( $post_id, '_leisure_indoor', $data['indoor'] ? '1' : '' );
	update_post_meta( $post_id, '_leisure_outdoor', $data['outdoor'] ? '1' : '' );
	update_post_meta( $post_id, '_leisure_parking', $data['parking'] ? '1' : '' );
	update_post_meta( $post_id, '_leisure_booking', $data['booking'] ? '1' : '' );
	update_post_meta( $post_id, '_leisure_duration', $data['duration'] );
	update_post_meta( $post_id, '_leisure_best_time', $data['best_time'] );

	return (int) $post_id;
}

echo "Seeding /lazer/ locations...\n";

// ------------------------------------------------------------------
// Locations per county (accurate, well-known, permanent destinations).
// Optional attributes intentionally left empty where uncertain.
// ------------------------------------------------------------------

$locations = array(
	// ---- DUBLIN ----
	array( 'title' => 'Trinity College & Book of Kells', 'slug' => 'trinity-college-book-of-kells', 'county' => 'Dublin', 'town' => 'Dublin', 'category' => 'História', 'website' => 'https://www.tcd.ie/visitors/book-of-kells/', 'excerpt' => 'A icônica universidade irlandesa e o famoso Livro de Kells, um dos mais importantes manuscritos medievais do mundo.', 'content' => '<p>O Trinity College, fundado em 1592, é a universidade mais antiga da Irlanda. A Biblioteca Antiga abriga o Livro de Kells, um manuscrito iluminado do século IX, e a impressionante Long Room.</p>', 'indoor' => true, 'booking' => true ),
	array( 'title' => 'Dublin Castle', 'slug' => 'dublin-castle', 'county' => 'Dublin', 'town' => 'Dublin', 'category' => 'Castelos', 'website' => 'https://www.dublincastle.ie/', 'excerpt' => 'Castelo histórico no coração de Dublin, sede do governo britânico na Irlanda por séculos e agora um complexo de museus e salões de estado.', 'content' => '<p>Construído no século XIII sobre o local de uma fortificação viking, o Dublin Castle serviu como centro do poder britânico na Irlanda até 1922. Hoje é um local de eventos estatais e atração turística.</p>', 'indoor' => true ),
	array( 'title' => 'Kilmainham Gaol', 'slug' => 'kilmainham-gaol', 'county' => 'Dublin', 'town' => 'Dublin', 'category' => 'História', 'website' => 'https://kilmainhamgaolmuseum.ie/', 'excerpt' => 'Prisão histórica que desempenhou papel central na luta pela independência irlandesa. Museu imperdível.', 'content' => '<p>Kilmainham Gaol foi a prisão onde muitos líderes da independência irlandesa foram detidos e executados. O museu oferece visitas guiadas sobre a história da Irlanda moderna.</p>', 'indoor' => true, 'family' => true, 'booking' => true ),
	array( 'title' => 'Guinness Storehouse', 'slug' => 'guinness-storehouse', 'county' => 'Dublin', 'town' => 'Dublin', 'category' => 'Cultura', 'website' => 'https://www.guinness-storehouse.com/', 'excerpt' => 'A famosa fábrica e museu da cerveja Guinness, com o bar panorâmico Gravity no topo.', 'content' => '<p>O Guinness Storehouse é uma atração de sete andares contando a história da marca mais famosa da Irlanda, com degustações e vistas panorâmicas de Dublin do Gravity Bar.</p>', 'indoor' => true, 'booking' => true ),
	array( 'title' => 'Phoenix Park', 'slug' => 'phoenix-park', 'county' => 'Dublin', 'town' => 'Dublin', 'category' => 'Natureza', 'website' => 'https://www.phoenixpark.ie/', 'excerpt' => 'Um dos maiores parques urbanos fechados da Europa, lar de veados selvagens, a residência presidencial e o Dublin Zoo.', 'content' => '<p>O Phoenix Park cobre mais de 700 hectares e abriga os veados, Áras an Uachtaráin (residência presidencial), o Papal Cross e o Dublin Zoo.</p>', 'outdoor' => true, 'family' => true ),
	array( 'title' => 'Dublin Zoo', 'slug' => 'dublin-zoo', 'county' => 'Dublin', 'town' => 'Dublin', 'category' => 'Vida Selvagem', 'website' => 'https://www.dublinzoo.ie/', 'excerpt' => 'Zoológico no Phoenix Park com animais de todo o mundo e programas de conservação.', 'content' => '<p>O Dublin Zoo, no Phoenix Park, é um dos zoológicos mais antigos do mundo, oferecendo recintos habitats e atividades educativas para toda a família.</p>', 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Howth Cliff Walk', 'slug' => 'howth-cliff-walk', 'county' => 'Dublin', 'town' => 'Howth', 'category' => 'Caminhadas', 'excerpt' => 'Trilha costeira espetacular ao redor da península de Howth, com vistas do mar da Irlanda.', 'content' => '<p>A Howth Cliff Walk é uma trilha costeira que oferece vistas dramáticas do mar, da ilha de Lambay e da baía de Dublin. Acesso fácil de trem a partir do centro da cidade.</p>', 'outdoor' => true ),
	array( 'title' => 'Malahide Castle & Gardens', 'slug' => 'malahide-castle-gardens', 'county' => 'Dublin', 'town' => 'Malahide', 'category' => 'Castelos', 'website' => 'https://www.malahidecastleandgardens.ie/', 'excerpt' => 'Castelo de 800 anos com jardins exuberantes, localizado na vila litorânea de Malahide.', 'content' => '<p>O Malahide Castle, habitado pela mesma família por quase 800 anos, fica em 260 acres de jardins e parque. Inclui um parque infantil e café.</p>', 'family' => true, 'parking' => true, 'booking' => true ),

	// ---- WICKLOW ----
	array( 'title' => 'Wicklow Mountains National Park', 'slug' => 'wicklow-mountains-national-park', 'county' => 'Wicklow', 'category' => 'Natureza', 'website' => 'https://www.nationalparks.ie/wicklow/', 'excerpt' => 'Parque nacional de montanhas, lagos e vales glaciais, conhecido como o "Jardim da Irlanda".', 'content' => '<p>O Wicklow Mountains National Park protege uma vasta área de montanhas, turfeiras e vales. É ideal para caminhadas, piqueniques e vistas panorâmicas.</p>', 'outdoor' => true, 'family' => true ),
	array( 'title' => 'Glendalough', 'slug' => 'glendalough', 'county' => 'Wicklow', 'town' => 'Wicklow', 'category' => 'História', 'website' => 'https://www.visitwicklow.ie/glendalough/', 'excerpt' => 'Assentamento monástico do século VI num vale glacial com lagos e trilhas.', 'content' => '<p>Glendalough ("Vale dos Dois Lagos") abriga as ruínas de um mosteiro fundado por São Kevin no século VI, num cenário natural deslumbrante com trilhas ao redor dos lagos.</p>', 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Powerscourt Estate', 'slug' => 'powerscourt-estate', 'county' => 'Wicklow', 'town' => 'Enniskerry', 'category' => 'Jardins', 'website' => 'https://powerscourt.com/', 'excerpt' => 'Mansão histórica com jardins premiados e o Terraço da Cachoeira; também há um centro de compras e café.', 'content' => '<p>Os jardins de Powerscourt, entre os mais premiados do mundo, oferecem Terraces, um jardim japonês e vistas para as montanhas de Wicklow. A mansão abriga lojas e cafés.</p>', 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Powerscourt Waterfall', 'slug' => 'powerscourt-waterfall', 'county' => 'Wicklow', 'town' => 'Enniskerry', 'category' => 'Natureza', 'website' => 'https://powerscourt.com/gardens-and-waterfall/', 'excerpt' => 'A cachoeira mais alta da Irlanda, com 121 metros, cercada por trilhas na floresta.', 'content' => '<p>A Powerscourt Waterfall é a cachoeira mais alta da Irlanda, com 121 metros, inserida num cenário de floresta. Possui área de piquenique e trilhas.</p>', 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Bray Head', 'slug' => 'bray-head', 'county' => 'Wicklow', 'town' => 'Bray', 'category' => 'Caminhadas', 'excerpt' => 'Trilha costeira com uma grande cruz no topo e vistas da baía de Bray e das montanhas de Wicklow.', 'content' => '<p>Bray Head é uma caminhada popular ao longo da costa, culminando numa cruz no topo do penhasco, com vistas do mar da Irlanda e em direção ao sul de Dublin.</p>', 'outdoor' => true ),
	array( 'title' => 'Great Sugar Loaf', 'slug' => 'great-sugar-loaf', 'county' => 'Wicklow', 'town' => 'Kilmacanogue', 'category' => 'Caminhadas', 'excerpt' => 'Pico de montanha em forma de cúpula com vistas panorâmicas das montanhas de Wicklow e da costa.', 'content' => '<p>O Great Sugar Loaf é um pico de 501 metros de quartzito com uma trilha até o topo que recompensa com vistas de 360 graus.</p>', 'outdoor' => true ),
	array( 'title' => 'Sally Gap', 'slug' => 'sally-gap', 'county' => 'Wicklow', 'category' => 'Natureza', 'excerpt' => 'Passo de montanha cênico nas montanhas de Wicklow, rodeado de turfeiras e berços dos rios Liffey e Dargle.', 'content' => '<p>Sally Gap é um passo de montanha entre as montanhas de Wicklow, oferecendo um dos passeios mais panorâmicos da Irlanda, com vastas paisagens de turfeiras e lagos.</p>', 'outdoor' => true ),
	array( 'title' => 'Avondale Forest Park', 'slug' => 'avondale-forest-park', 'county' => 'Wicklow', 'town' => 'Rathdrum', 'category' => 'Natureza', 'website' => 'https://www.avondaleforestpark.ie/', 'excerpt' => 'Parque florestal com trilhas, passeios de copa de árvores e história ligada a Charles Stewart Parnell.', 'content' => '<p>O Avondale Forest Park combina belas trilhas na floresta, um passeio pela copa das árvores (Treetop Walk) e história ligada ao líder político Charles Stewart Parnell.</p>', 'outdoor' => true, 'family' => true, 'parking' => true ),

	// ---- MEATH ----
	array( 'title' => 'Newgrange', 'slug' => 'newgrange', 'county' => 'Meath', 'town' => 'Slane', 'category' => 'História', 'website' => 'https://www.newgrange.com/', 'excerpt' => 'Túmulo de passagem neolítico com mais de 5.000 anos, mais antigo que Stonehenge e as pirâmides.', 'content' => '<p>Newgrange, parte do Brú na Bóinne, é um túmulo de passagem de mais de 5.000 anos famoso pelo alinhamento com o solstício de inverno, quando a luz ilumina o corredor interno.</p>', 'outdoor' => true, 'booking' => true ),
	array( 'title' => 'Knowth', 'slug' => 'knowth', 'county' => 'Meath', 'town' => 'Slane', 'category' => 'História', 'website' => 'https://www.worldheritageireland.ie/bru-na-boinne/built-heritage/knowth/', 'excerpt' => 'Grande túmulo de passagem neolítico com a maior coleção de arte megalítica da Europa ocidental.', 'content' => '<p>Knowth é o maior túmulo de passagem do Brú na Bóinne, com mais de 200 pedras decoradas. As visitas incluem acesso ao interior do grande montículo.</p>', 'outdoor' => true, 'booking' => true ),
	array( 'title' => 'Hill of Tara', 'slug' => 'hill-of-tara', 'county' => 'Meath', 'town' => 'Navan', 'category' => 'História', 'website' => 'https://heritageireland.ie/places-to-visit/hill-of-tara/', 'excerpt' => 'Antiga sede dos Reis Supremos da Irlanda, com monumentos e vistas panorâmicas.', 'content' => '<p>O Hill of Tara era o centro político e espiritual da Irlanda antiga, sede dos Reis Supremos. Hoje oferece monumentos megalíticos e amplas vistas.</p>', 'outdoor' => true ),
	array( 'title' => 'Trim Castle', 'slug' => 'trim-castle', 'county' => 'Meath', 'town' => 'Trim', 'category' => 'Castelos', 'website' => 'https://heritageireland.ie/places-to-visit/trim-castle/', 'excerpt' => 'O maior castelo anglo-normando da Irlanda, famoso por ter sido locação do filme "Braveheart".', 'content' => '<p>Trim Castle é o maior castelo anglo-normando da Irlanda, com uma imponente torre de menagem. Ficou conhecido como locação do filme "Coração Valente".</p>', 'outdoor' => true ),
	array( 'title' => 'Slane Castle', 'slug' => 'slane-castle', 'county' => 'Meath', 'town' => 'Slane', 'category' => 'Castelos', 'website' => 'https://www.slanecastle.ie/', 'excerpt' => 'Castelo histórico às margens do rio Boyne, conhecido pelos seus concertos e destilaria.', 'content' => '<p>Slane Castle, às margens do rio Boyne, é famoso pela sua história e pelos icónicos concertos ao ar livre. A propriedade também abriga uma destilaria de whisky.</p>', 'outdoor' => true ),
	array( 'title' => 'Loughcrew', 'slug' => 'loughcrew', 'county' => 'Meath', 'town' => 'Oldcastle', 'category' => 'História', 'website' => 'https://heritageireland.ie/places-to-visit/loughcrew-cairns/', 'excerpt' => 'Conjunto de túmulos de passagem neolíticos nas colinas de Loughcrew, com arte rupestre.', 'content' => '<p>Os Cairns de Loughcrew formam um dos maiores complexos megalíticos da Irlanda, com túmulos de passagem no topo das colinas e arte neolítica no interior.</p>', 'outdoor' => true ),
	array( 'title' => 'Battle of the Boyne Visitor Centre', 'slug' => 'battle-of-the-boyne-visitor-centre', 'county' => 'Meath', 'town' => 'Drogheda', 'category' => 'História', 'website' => 'https://www.battleoftheboyne.ie/', 'excerpt' => 'Centro de visitantes que conta a história da Batalha do Boyne de 1690, na casa de Oldbridge.', 'content' => '<p>O centro interpreta a Batalha do Boyne de 1690, um dos confrontos mais importantes da história irlandesa, com visitas guiadas ao campo de batalha e à casa de Oldbridge.</p>', 'indoor' => true ),
);

// ------------------------------------------------------------------
// GALWAY / CLARE / CORK / KERRY and remaining counties.
// (Representative selection; expand as needed.)
// ------------------------------------------------------------------

$locations = array_merge( $locations, array(
	// ---- GALWAY ----
	array( 'title' => 'Kylemore Abbey', 'slug' => 'kylemore-abbey', 'county' => 'Galway', 'town' => 'Connemara', 'category' => 'História', 'website' => 'https://kylemoreabbey.com/', 'excerpt' => 'Mosteiro e castelo do século XIX num cenário lacustre deslumbrante em Connemara.', 'content' => '<p>Kylemore Abbey é um castelo do século XIX à beira de um lago, rodeado pelos Twelve Bens. Inclui jardins vitorianos, uma igreja gótica e lojas/café.</p>', 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Connemara National Park', 'slug' => 'connemara-national-park', 'county' => 'Galway', 'town' => 'Letterfrack', 'category' => 'Natureza', 'website' => 'https://www.nationalparks.ie/connemara/', 'excerpt' => 'Parque nacional de montanhas, turfeiras e pôneis selvagens, com trilhas até o Diamond Hill.', 'content' => '<p>O Connemara National Park abrange montanhas, turfeiras e prados, e é famoso pelos seus pôneis. A trilha até o topo de Diamond Hill oferece vistas espetaculares.</p>', 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Salthill', 'slug' => 'salthill', 'county' => 'Galway', 'town' => 'Galway', 'category' => 'Praias', 'excerpt' => 'Promenade litorânea de Galway, com praia, piscina de mar e vista para a baía.', 'content' => '<p>Salthill é a zona balnear de Galway, com um calçadão, praia e a tradição de "chutar a parede" no fim do promenade, além de excelentes vistas para a baía de Galway.</p>', 'outdoor' => true, 'family' => true ),
	array( 'title' => 'Sky Road', 'slug' => 'sky-road', 'county' => 'Galway', 'town' => 'Clifden', 'category' => 'Natureza', 'excerpt' => 'Rota costeira cênica ao redor da península de Kingstown, com vistas de Clifden e da baía.', 'content' => '<p>A Sky Road é uma rota circular costeira que oferece algumas das vistas mais dramáticas de Connemara, sobre a baía de Clifden e a ilha de Omey.</p>', 'outdoor' => true ),
	array( 'title' => 'Aran Islands', 'slug' => 'aran-islands', 'county' => 'Galway', 'category' => 'Ilhas', 'website' => 'https://www.arannislands.ie/', 'excerpt' => 'Ilhas gaélicas na foz da Baía de Galway, famosas pelas fortalezas de pedra e cultura tradicional.', 'content' => '<p>As Ilhas Aran (Inishmore, Inishmaan e Inisheer) preservam a cultura e língua gaélica, paisagens de calcário e o antigo forte de Dún Aonghasa. Acesso por ferry ou avião.</p>', 'outdoor' => true ),
	array( 'title' => 'Dún Aonghasa', 'slug' => 'dun-aonghasa', 'county' => 'Galway', 'town' => 'Inishmore', 'category' => 'História', 'website' => 'https://heritageireland.ie/places-to-visit/dun-aonghasa/', 'excerpt' => 'Forte de pedra pré-histórico na borda de um penhasco de 100 m na ilha de Inishmore.', 'content' => '<p>Dún Aonghasa é um forte de pedra da Idade do Bronze situado na borda de um penhasco de 100 metros sobre o oceano Atlântico, na ilha de Inishmore.</p>', 'outdoor' => true ),
	array( 'title' => 'Diamond Hill', 'slug' => 'diamond-hill', 'county' => 'Galway', 'town' => 'Letterfrack', 'category' => 'Caminhadas', 'website' => 'https://www.nationalparks.ie/connemara/', 'excerpt' => 'Trilha em Connemara com vistas panorâmicas da baía de Ballynakill e das Twelve Bens.', 'content' => '<p>Diamond Hill, no Connemara National Park, oferece uma trilha recompensadora com vistas das Twelve Bens, da baía e do Oceano Atlântico.</p>', 'outdoor' => true ),
	array( 'title' => 'Galway Atlantaquaria', 'slug' => 'galway-atlantaquaria', 'county' => 'Galway', 'town' => 'Salthill', 'category' => 'Família', 'website' => 'https://www.nationalaquarium.ie/', 'excerpt' => 'Aquário nacional da Irlanda, em Salthill, com vida marinha local e tanques interativos.', 'content' => '<p>O Galway Atlantaquaria é o aquário nacional da Irlanda, com tanques de espécies locais, tubarões e atividades interativas para crianças.</p>', 'indoor' => true, 'family' => true, 'parking' => true ),

	// ---- CLARE ----
	array( 'title' => 'Cliffs of Moher', 'slug' => 'cliffs-of-moher', 'county' => 'Clare', 'town' => 'Liscannor', 'category' => 'Natureza', 'website' => 'https://www.cliffsofmoher.ie/', 'excerpt' => 'Falésias de 214 metros sobre o Oceano Atlântico, uma das atrações naturais mais famosas da Irlanda.', 'content' => '<p>As Cliffs of Moher erguem-se até 214 metros sobre o Atlântico, estendendo-se por 8 km. O centro de visitantes e os passadiços oferecem vistas inesquecíveis.</p>', 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Burren National Park', 'slug' => 'burren-national-park', 'county' => 'Clare', 'category' => 'Natureza', 'website' => 'https://www.nationalparks.ie/burren/', 'excerpt' => 'Paisagem cárstica única de calcário com flora rara e trilhas no Condado de Clare.', 'content' => '<p>O Burren National Park protege uma paisagem cárstica de lajes de calcário com flora excecionalmente rica, incluindo orquídeas e plantas alpinas, e trilhas marcadas.</p>', 'outdoor' => true ),
	array( 'title' => 'Bunratty Castle & Folk Park', 'slug' => 'bunratty-castle-folk-park', 'county' => 'Clare', 'town' => 'Bunratty', 'category' => 'Castelos', 'website' => 'https://www.bunrattycastle.ie/', 'excerpt' => 'Castelo do século XV restaurado com um parque folclórico que recria a vida rural irlandesa.', 'content' => '<p>Bunratty Castle é um castelo do século XV restaurado com mobiliário de época. O Folk Park ao lado recria uma vila e casas rurais irlandesas do século XIX, com animais.</p>', 'indoor' => true, 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Aillwee Caves', 'slug' => 'aillwee-caves', 'county' => 'Clare', 'town' => 'Ballyvaughan', 'category' => 'Natureza', 'website' => 'https://aillweecave.ie/', 'excerpt' => 'Sistema de cavernas no Burren com formações de pedra e uma cascata subterrânea.', 'content' => '<p>As Aillwee Caves oferecem um passeio por um sistema de cavernas no Burren, com estalactites, e uma cascata subterrânea, além de uma casa de pássaros de rapina.</p>', 'indoor' => true, 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Doolin Cave', 'slug' => 'doolin-cave', 'county' => 'Clare', 'town' => 'Doolin', 'category' => 'Natureza', 'website' => 'https://doolincave.ie/', 'excerpt' => 'Caverna com um dos maiores estalactites pendentes do mundo.', 'content' => '<p>A Doolin Cave é famosa pelo Great Stalactite, um dos maiores estalactites pendentes do mundo, com visitas guiadas ao subsolo.</p>', 'indoor' => true ),
	array( 'title' => 'Loop Head', 'slug' => 'loop-head', 'county' => 'Clare', 'town' => 'Kilbaha', 'category' => 'Natureza', 'website' => 'https://loophead.ie/', 'excerpt' => 'Península com falésias e um farol clássico na entrada do estuário do Shannon.', 'content' => '<p>A península de Loop Head estende-se entre o Atlântico e o estuário do Shannon, com falésias impressionantes, um farol e trilhas costeiras.</p>', 'outdoor' => true ),
	array( 'title' => 'Lahinch Beach', 'slug' => 'lahinch-beach', 'county' => 'Clare', 'town' => 'Lahinch', 'category' => 'Praias', 'excerpt' => 'Praia de areia popular para surf e banhos de mar na costa oeste da Irlanda.', 'content' => '<p>Lahinch é uma das praias de surf mais famosas da Irlanda, com uma longa faixa de areia e excelentes condições para desportos aquáticos e banhos de mar.</p>', 'outdoor' => true, 'family' => true ),
	array( 'title' => 'Kilkee Cliffs', 'slug' => 'kilkee-cliffs', 'county' => 'Clare', 'town' => 'Kilkee', 'category' => 'Natureza', 'excerpt' => 'Passarela costeira ao longo de falésias com formações rochosas características.', 'content' => '<p>As Kilkee Cliffs oferecem uma caminhada deslumbrante ao longo do Atlântico, com formações rochosas como o "Diamond Rocks" e arcos naturais.</p>', 'outdoor' => true ),

	// ---- CORK ----
	array( 'title' => 'Blarney Castle', 'slug' => 'blarney-castle', 'county' => 'Cork', 'town' => 'Blarney', 'category' => 'Castelos', 'website' => 'https://blarneycastle.ie/', 'excerpt' => 'Castelo medieval famoso pela Pedra de Blarney, cujo beijo concede "o dom da eloquência".', 'content' => '<p>Blarney Castle, do século XV, é conhecido pela Blarney Stone, que se beija no topo. Os jardins incluem grutas e trilhas na floresta.</p>', 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Cobh', 'slug' => 'cobh', 'county' => 'Cork', 'town' => 'Cobh', 'category' => 'Cidades', 'excerpt' => 'Cidade portuária histórica, última paragem do Titanic e porto de partida de milhões de emigrantes.', 'content' => '<p>Cobh (antes Queenstown) foi a última paragem do Titanic e o principal porto de emigração da Irlanda. A sua catedral de St Colman e as ruas coloridas atraem muitos visitantes.</p>', 'outdoor' => true ),
	array( 'title' => 'Titanic Experience Cobh', 'slug' => 'titanic-experience-cobh', 'county' => 'Cork', 'town' => 'Cobh', 'category' => 'História', 'website' => 'https://www.titanicexperiencecobh.ie/', 'excerpt' => 'Museu interativo que recria a história do Titanic e da sua ligação com Cobh.', 'content' => '<p>Situado no antigo escritório da White Star Line, o Titanic Experience Cobh conta a história do Titanic e dos passageiros que embarcaram em Queenstown.</p>', 'indoor' => true ),
	array( 'title' => 'Fota Wildlife Park', 'slug' => 'fota-wildlife-park', 'county' => 'Cork', 'town' => 'Carrigtwohill', 'category' => 'Vida Selvagem', 'website' => 'https://www.fotawildlife.ie/', 'excerpt' => 'Parque de vida selvagem na ilha de Fota, com animais em recintos abertos e um castelo.', 'content' => '<p>O Fota Wildlife Park combina um parque de animais em recintos abertos (girafas, cangurus, pinguins) com a elegante Fota House e jardins. Excelente para famílias.</p>', 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Jameson Distillery Midleton', 'slug' => 'jameson-distillery-midleton', 'county' => 'Cork', 'town' => 'Midleton', 'category' => 'Cultura', 'website' => 'https://www.jamesonwhiskey.com/distilleries/jameson-distillery-midleton', 'excerpt' => 'Destilaria histórica onde se produz o whisky Jameson, com visitas guiadas e degustações.', 'content' => '<p>A Jameson Distillery em Midleton é a origem do famoso whisky irlandês, com visitas guiadas que percorrem o processo de destilação e terminam em degustações.</p>', 'indoor' => true, 'booking' => true ),
	array( 'title' => 'Kinsale', 'slug' => 'kinsale', 'county' => 'Cork', 'town' => 'Kinsale', 'category' => 'Cidades', 'excerpt' => 'Vila costeira pitoresca com ruas coloridas, gastronomia premiada e uma história marítima rica.', 'content' => '<p>Kinsale é uma das vilas mais charmosas da Irlanda, famosa pela gastronomia, pelas ruas coloridas e pela história ligada aos fortes de Charles e James.</p>', 'outdoor' => true ),
	array( 'title' => 'Charles Fort', 'slug' => 'charles-fort', 'county' => 'Cork', 'town' => 'Kinsale', 'category' => 'História', 'website' => 'https://heritageireland.ie/places-to-visit/charles-fort/', 'excerpt' => 'Forte em estrela do século XVII na entrada do porto de Kinsale.', 'content' => '<p>Charles Fort é um exemplo impressionante de fortificação em estrela do século XVII, construído para proteger o porto de Kinsale, com vistas para o mar.</p>', 'outdoor' => true ),
	array( 'title' => 'Mizen Head', 'slug' => 'mizen-head', 'county' => 'Cork', 'town' => 'Goleen', 'category' => 'Natureza', 'website' => 'https://www.mizenhead.ie/', 'excerpt' => 'Ponto mais a sudoeste da Irlanda, com ponte sobre o desfiladeiro, sinal marítimo e vistas do Atlântico.', 'content' => '<p>Mizen Head é o ponto mais a sudoeste da Irlanda, acessível por uma ponte pedonal sobre um desfiladeiro, com um antigo sinal marítimo e vistas dramáticas do Atlântico.</p>', 'outdoor' => true, 'parking' => true ),

	// ---- KERRY ----
	array( 'title' => 'Killarney National Park', 'slug' => 'killarney-national-park', 'county' => 'Kerry', 'town' => 'Killarney', 'category' => 'Natureza', 'website' => 'https://www.nationalparks.ie/killarney/', 'excerpt' => 'Primeiro parque nacional da Irlanda, com lagos, montanhas e o castelo de Ross.', 'content' => '<p>O Killarney National Park protege os Lagos de Killarney, a montanha de Carrantuohill (o pico mais alto da Irlanda) e florestas de carvalhos, além de veados-de-vermelho.</p>', 'outdoor' => true, 'family' => true, 'parking' => true ),
	array( 'title' => 'Muckross House', 'slug' => 'muckross-house', 'county' => 'Kerry', 'town' => 'Killarney', 'category' => 'História', 'website' => 'https://muckross-house.ie/', 'excerpt' => 'Mansão vitoriana no coração do parque, com jardins e quintas tradicionais.', 'content' => '<p>Muckross House é uma mansão vitoriana que abriga museus de tradições, com jardins e quintas tradicionais (Muckross Traditional Farms) que recriam a vida rural.</p>', 'indoor' => true, 'outdoor' => true, 'parking' => true ),
	array( 'title' => 'Ross Castle', 'slug' => 'ross-castle', 'county' => 'Kerry', 'town' => 'Killarney', 'category' => 'Castelos', 'website' => 'https://www.rosscastlekillarney.com/', 'excerpt' => 'Castelo do século XV às margens do Lago de Leane, com passeios de barco.', 'content' => '<p>Ross Castle é uma torre do século XV às margens do Lough Leane, restaurada e aberta a visitas, de onde partem passeios de barco pelos lagos de Killarney.</p>', 'outdoor' => true ),
	array( 'title' => 'Torc Waterfall', 'slug' => 'torc-waterfall', 'county' => 'Kerry', 'town' => 'Killarney', 'category' => 'Natureza', 'excerpt' => 'Cascata de 20 metros rodeada de floresta, nas encostas do Monte Torc.', 'content' => '<p>Torc Waterfall é uma cascata de cerca de 20 metros junto à estrada entre Killarney e Kenmare, com uma curta caminhada na floresta e vistas sobre os lagos.</p>', 'outdoor' => true ),
	array( 'title' => 'Gap of Dunloe', 'slug' => 'gap-of-dunloe', 'county' => 'Kerry', 'town' => 'Killarney', 'category' => 'Natureza', 'excerpt' => 'Desfiladeiro glaciar entre montanhas, percorrido por carruagens e trilhas.', 'content' => '<p>O Gap of Dunloe é um desfiladeiro glaciar entre as montanhas MacGillycuddy\'s Reeks e Purple Mountain, atravessado por um caminho de pedra famoso por carruagens a cavalo.</p>', 'outdoor' => true ),
	array( 'title' => 'Ring of Kerry', 'slug' => 'ring-of-kerry', 'county' => 'Kerry', 'category' => 'Natureza', 'excerpt' => 'Rota cênica circular de 179 km pela península de Iveragh, com vistas costeiras espetaculares.', 'content' => '<p>O Ring of Kerry é uma das rotas de condução mais cênicas da Europa, circundando a península de Iveragh com vistas de praias, montanhas e ilhas.</p>', 'outdoor' => true ),
	array( 'title' => 'Dingle Peninsula', 'slug' => 'dingle-peninsula', 'county' => 'Kerry', 'town' => 'Dingle', 'category' => 'Natureza', 'excerpt' => 'Península gaélica com praias selvagens, Slea Head e o centro urbano de Dingle.', 'content' => '<p>A Península de Dingle liga o litoral selvagem, o Slea Head Drive, a língua gaélica e a vila pitoresca de Dingle, com os seus golfinhos e gastronomia.</p>', 'outdoor' => true ),
	array( 'title' => 'Inch Beach', 'slug' => 'inch-beach', 'county' => 'Kerry', 'town' => 'Inch', 'category' => 'Praias', 'excerpt' => 'Longa praia de areia na península de Dingle, popular para surf e caminhadas.', 'content' => '<p>Inch Beach é uma praia de 5 km de areia dourada na base da península de Dingle, famosa pelo surf e por caminhadas com vista para as montanhas.</p>', 'outdoor' => true ),
) );

$created = 0;
foreach ( $locations as $data ) {
	$id = conexao_seed_leisure_location( $data );
	if ( $id ) {
		$created++;
		echo "  OK: {$data['title']} (ID: {$id})\n";
	}
}

echo "\nCreated/verified {$created} leisure locations.\n";
echo "Remember to flush rewrite rules if this is the first run.\n";