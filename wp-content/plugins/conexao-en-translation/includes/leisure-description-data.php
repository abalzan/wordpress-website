<?php
/**
 * Stage 7 — authored English Leisure card descriptions (versioned dataset v1).
 *
 * The English layer for the Leisure archive is a DESCRIPTION-LEVEL translation
 * on the SAME Portuguese records: one authored English description per published
 * `leisure` record, stored in `_leisure_excerpt_en` post meta by the shared
 * `conexao-translation-rollout` engine (stage `en-leisure-description`).
 *
 * Provenance: the 289 rows below are the human-authored English translations
 * captured with the retired `conexao-leisure-translation` Stage 7 dataset
 * (`data/stage7-leisure-descriptions.json`, PT source read from production on
 * 2026-09-24). They are reproduced here VERBATIM so the active stage owns its
 * own versioned data and the retired plugin is not a runtime dependency.
 *
 * Keys and fields:
 *   - array key / `slug`  : the PT post_name — the ONLY portable identity
 *                          (engineering standard §0.4). The retired dataset's
 *                          local `id` column is deliberately NOT carried over:
 *                          a local post ID is not portable identity.
 *   - `pt_source`          : the Portuguese description the English was authored
 *                          against. It is the PT-drift guard's reference: a row
 *                          whose live `post_excerpt` no longer matches (after
 *                          normalisation) is REFUSED, never silently applied.
 *   - `pt_title`           : the title captured with the source, used to verify
 *                          that the slug still resolves to the same record.
 *   - `en_description`     : the authored English card description.
 *
 * The English is a FAITHFUL translation of `pt_source`: no invented opening
 * hours, prices, facilities, accessibility or historical claims. The card
 * renders it through the existing 18-word `wp_trim_words()` + `esc_html()`
 * pipeline, identical in both languages.
 *
 * @package Conexao_EN_Translation
 */

defined( 'ABSPATH' ) || exit;

/**
 * The authored English Leisure card descriptions, keyed by PT slug.
 *
 * @return array<string,array<string,string>>
 */
function conexao_en_translation_leisure_description_data_v1(): array {
	return array(
		'dwyer-mcallister-cottage' => array(
			'pt_source'      => 'Casa rural restaurada aos pés da montanha Keadeen, palco de um episódio da Rebelião de 1798 e hoje pequeno museu de época.',
			'pt_title'       => 'Dwyer McAllister Cottage',
			'en_description' => 'Restored farmhouse at the foot of Keadeen mountain, scene of an episode of the 1798 Rebellion and today a small period museum.',
		),
		'national-botanic-gardens-kilmacurragh' => array(
			'pt_source'      => 'Jardim botânico no condado de Wicklow, parte dos Jardins Botânicos Nacionais, famoso pelos rododendros e pelas árvores raras numa antiga propriedade do século XIX.',
			'pt_title'       => 'National Botanic Garden of Ireland – Kilmacurragh',
			'en_description' => 'Botanic garden in County Wicklow, part of the National Botanic Gardens, famous for its rhododendrons and rare trees on a former 19th-century estate.',
		),
		'fore-abbey' => array(
			'pt_source'      => 'Ruínas do mosteiro fundado por São Feichin no século VII, num vale tranquilo do condado de Westmeath, com as lendárias “Sete Maravilhas de Fore”.',
			'pt_title'       => 'Fore Abbey',
			'en_description' => 'Ruins of the monastery founded by St Feichin in the 7th century, in a tranquil valley in County Westmeath, with the legendary "Seven Wonders of Fore".',
		),
		'roscrea-heritage-centre-roscrea-castle-and-damer-house' => array(
			'pt_source'      => 'Conjunto no centro de Roscrea com um castelo do século XIII, a casa pré-paladiana Damer House e jardins murados com fonte.',
			'pt_title'       => 'Roscrea Castle, Gardens and Damer House/Black Mills',
			'en_description' => 'A group of buildings in the centre of Roscrea with a 13th-century castle, the pre-Palladian Damer House and walled gardens with a fountain.',
		),
		'the-main-guard' => array(
			'pt_source'      => 'Antigo tribunal do século XVII no centro de Clonmel, com arcada de colunas de arenito restaurada e espaço de exposições.',
			'pt_title'       => 'The Main Guard',
			'en_description' => 'A former 17th-century courthouse in the centre of Clonmel, with a restored sandstone columned arcade and an exhibition space.',
		),
		'famine-warhouse-1848' => array(
			'pt_source'      => 'Casa de lavoura perto de Ballingarry que foi palco de um cerco sangrento da Rebelião dos Jovens Irlandeses de 1848, hoje museu.',
			'pt_title'       => 'Famine Warhouse 1848',
			'en_description' => 'A farmhouse near Ballingarry that was the scene of a bloody siege during the 1848 Young Ireland Rebellion, now a museum.',
		),
		'st-marys-church-gowran' => array(
			'pt_source'      => 'Igreja colegiada do final do século XIII, com túmulos medievais dos Butler e esculturas do “Mestre de Gowran”, hoje monumento nacional.',
			'pt_title'       => 'St. Mary’s Church Gowran',
			'en_description' => 'A collegiate church from the late 13th century, with medieval Butler tombs and carvings by the "Gowran Master", now a national monument.',
		),
		'kells-priory' => array(
			'pt_source'      => 'O maior recinto eclesiástico murado da Irlanda, fundado por volta de 1193 e conhecido localmente como os “Sete Castelos de Kells”.',
			'pt_title'       => 'Kells Priory',
			'en_description' => 'Ireland\'s largest walled ecclesiastical enclosure, founded around 1193 and known locally as the "Seven Castles of Kells".',
		),
		'dungarvan-castle' => array(
			'pt_source'      => 'Castelo anglo-normando de cerca de 1209 que protegia a entrada do porto de Dungarvan, com um raro “shell keep” poligonal.',
			'pt_title'       => 'Dungarvan Castle',
			'en_description' => 'An Anglo-Norman castle of about 1209 that guarded the entrance to Dungarvan harbour, with a rare polygonal shell keep.',
		),
		'newmills-corn-and-flax-mills' => array(
			'pt_source'      => 'Conjunto industrial do século XIX perto de Letterkenny, com uma das maiores rodas de água em funcionamento do país, que servia a indústria do linho.',
			'pt_title'       => 'Newmills Corn and Flax Mills',
			'en_description' => 'A 19th-century industrial complex near Letterkenny, with one of the largest working waterwheels in the country, which once served the linen industry.',
		),
		'glebe-house-and-gallery' => array(
			'pt_source'      => 'Casa regencial de 1828 perto de Letterkenny onde viveu o pintor Derek Hill, com uma coleção de arte que inclui Picasso, Renoir e Braque.',
			'pt_title'       => 'Glebe Gallery and Garden – Derek Hill House',
			'en_description' => 'An 1828 Regency house near Letterkenny where the painter Derek Hill lived, with an art collection that includes Picasso, Renoir and Braque.',
		),
		'doe-castle' => array(
			'pt_source'      => 'Fortaleza medieval dos séculos XV/XVI à beira-mar em Sheephaven Bay, antiga casa dos chefes MacSweeney; terrenos abertos todo o ano.',
			'pt_title'       => 'Doe Castle',
			'en_description' => 'A 15th/16th-century fortress by the sea on Sheephaven Bay, former seat of the MacSweeney chiefs; grounds open all year round.',
		),
		'ballyhack-castle' => array(
			'pt_source'      => 'Casa-torre de cerca de 1450 sobre o estuário de Waterford, provavelmente construída pelos Cavaleiros Hospitalários de São João.',
			'pt_title'       => 'Ballyhack Castle',
			'en_description' => 'A tower house of around 1450 above the Waterford estuary, probably built by the Knights Hospitallers of St John.',
		),
		'ionad-culturtha-an-phiarsaigh-pearses-cottage' => array(
			'pt_source'      => 'Centro cultural em Ros Muc, no coração da Gaeltacht de Connemara, onde Patrick Pearse construiu a sua casa de verão e que hoje se visita tal como ele a deixou.',
			'pt_title'       => 'Ionad Cultúrtha an Phiarsaigh Conamara- Pearse’s Cottage and Visitor Centre',
			'en_description' => 'A cultural centre in Ros Muc, in the heart of the Connemara Gaeltacht, where Patrick Pearse built his summer house, visited today just as he left it.',
		),
		'aughnanure-castle' => array(
			'pt_source'      => 'Casa-torre tardo-medieval mais bem preservada de Connemara, sobre um afloramento rochoso do rio Drimneen, perto do Lough Corrib.',
			'pt_title'       => 'Aughnanure Castle',
			'en_description' => 'The best-preserved late-medieval tower house in Connemara, on a rocky outcrop on the River Drimneen, near Lough Corrib.',
		),
		'athenry-castle' => array(
			'pt_source'      => 'Casa-torre do século XIII que guardava um vau estratégico no rio Clareen, no centro de uma das vilas medievais mais bem preservadas da Irlanda.',
			'pt_title'       => 'Athenry Castle',
			'en_description' => 'A 13th-century tower house that guarded a strategic ford on the River Clareen, in the centre of one of Ireland\'s best-preserved medieval towns.',
		),
		'desmond-castle-newcastlewest' => array(
			'pt_source'      => 'Salão de banquetes medieval do século XV no centro de Newcastle West, com galeria de carvalho restaurada; acesso por visita guiada.',
			'pt_title'       => 'Desmond Castle Newcastlewest',
			'en_description' => 'A 15th-century medieval banqueting hall in the centre of Newcastle West, with a restored oak gallery; access by guided tour.',
		),
		'desmond-castle-adare' => array(
			'pt_source'      => 'Castelo medieval fortificado nas margens do rio Maigue, com torre quadrada e fosso, ligado aos condes de Desmond; acesso pelo Adare Heritage Centre.',
			'pt_title'       => 'Adare Castle',
			'en_description' => 'A fortified medieval castle on the banks of the River Maigue, with a square tower and moat, linked to the Earls of Desmond; access via the Adare Heritage Centre.',
		),
		'askeaton-castle' => array(
			'pt_source'      => 'Ruínas de uma fortaleza medieval de 1199 no centro de Askeaton, antiga base dos condes de Desmond — atualmente fechada, apenas vista exterior.',
			'pt_title'       => 'Askeaton Castle',
			'en_description' => 'Ruins of a medieval fortress of 1199 in the centre of Askeaton, former base of the Earls of Desmond — currently closed, exterior viewing only.',
		),
		'blasket-centre-ionad-an-bhlascaoid' => array(
			'pt_source'      => 'Centro de património dedicado à comunidade de língua irlandesa das Ilhas Blasket, com exposições interativas e miradouro sobre o Atlântico.',
			'pt_title'       => 'Ionad an Bhlascaoid – The Blasket Centre',
			'en_description' => 'A heritage centre dedicated to the Irish-speaking community of the Blasket Islands, with interactive exhibitions and a viewpoint over the Atlantic.',
		),
		'listowel-castle' => array(
			'pt_source'      => 'Castelo anglo-normando sobre o rio Feale, último reduto dos Fitzmaurice na Guerra dos Nove Anos, com acesso por visita guiada.',
			'pt_title'       => 'Listowel Castle',
			'en_description' => 'An Anglo-Norman castle above the River Feale, the Fitzmaurices\' last stronghold in the Nine Years\' War, with access by guided tour.',
		),
		'ardfert-cathedral' => array(
			'pt_source'      => 'Conjunto de três igrejas medievais, a mais antiga do século XII, no local do mosteiro fundado por São Brandão, o Navegador.',
			'pt_title'       => 'Ardfert Cathedral',
			'en_description' => 'A group of three medieval churches, the oldest from the 12th century, on the site of the monastery founded by St Brendan the Navigator.',
		),
		'aras-an-uachtarain' => array(
			'pt_source'      => 'Residência oficial do Presidente da Irlanda, no Phoenix Park, com visitas guiadas gratuitas aos sábados, sujeitas ao serviço do Estado.',
			'pt_title'       => 'Áras an Uachtaráin',
			'en_description' => 'The official residence of the President of Ireland, in Phoenix Park, with free guided tours on Saturdays, subject to State business.',
		),
		'st-stephens-green' => array(
			'pt_source'      => 'Um dos parques públicos mais conhecidos da Irlanda, no centro da zona comercial de Dublin, mantido no traçado vitoriano original.',
			'pt_title'       => 'St Stephen’s Green',
			'en_description' => 'One of Ireland\'s best-known public parks, in the heart of Dublin\'s shopping district, kept to its original Victorian layout.',
		),
		'st-audens-church' => array(
			'pt_source'      => 'A única igreja paroquial medieval que resta em Dublin, dedicada ao bispo de Rouen, com a Capela da Guilda de Santa Ana e o túmulo de Portlester.',
			'pt_title'       => 'St Audoen’s Church and Visitor Centre',
			'en_description' => 'Dublin\'s only surviving medieval parish church, dedicated to the bishop of Rouen, with the St Anne\'s Guild Chapel and the Portlester tomb.',
		),
		'st-marys-abbey-chapter-house' => array(
			'pt_source'      => 'Restos de um dos maiores e mais importantes mosteiros medievais da Irlanda, com acesso apenas por visita guiada e abertura sazonal.',
			'pt_title'       => 'St. Mary’s Abbey – Chapter House – Cistercian Monastery',
			'en_description' => 'Remains of one of the largest and most important medieval monasteries in Ireland, accessible by guided tour only and open seasonally.',
		),
		'royal-hospital-kilmainham' => array(
			'pt_source'      => 'Um dos edifícios mais emblemáticos de Dublin e o melhor exemplo irlandês de arquitetura do século XVII, hoje sede do Museu Irlandês de Arte Moderna.',
			'pt_title'       => 'Royal Hospital Kilmainham',
			'en_description' => 'One of Dublin\'s most landmark buildings and Ireland\'s finest example of 17th-century architecture, today home to the Irish Museum of Modern Art.',
		),
		'rathfarnham-castle' => array(
			'pt_source'      => 'Castelo isabelino do século XVI com quatro torres, remodelado no século XVIII, com retratos de família e exposições culturais.',
			'pt_title'       => 'Rathfarnham Castle',
			'en_description' => 'A 16th-century Elizabethan castle with four towers, remodelled in the 18th century, with family portraits and cultural exhibitions.',
		),
		'phoenix-park-peoples-flower-gardens' => array(
			'pt_source'      => 'Jardim de flores vitoriano de 9 hectares dentro do Phoenix Park, com lago ornamental, parque infantil e canteiros sazonais.',
			'pt_title'       => 'Phoenix Park – People’s Flower Gardens',
			'en_description' => 'A 9-hectare Victorian flower garden inside Phoenix Park, with an ornamental lake, playground and seasonal flower beds.',
		),
		'phoenix-park-visitor-centre-ashtown-castle' => array(
			'pt_source'      => 'Centro de visitantes do Phoenix Park ao lado do Castelo de Ashtown, uma casa-torre redescoberta, com jardim murado vitoriano e exposições.',
			'pt_title'       => 'Phoenix Park Visitor Centre, Ashtown Castle and Biodiversity Centre',
			'en_description' => 'The Phoenix Park visitor centre beside Ashtown Castle, a rediscovered tower house, with a Victorian walled garden and exhibitions.',
		),
		'pearse-museum-st-endas-park' => array(
			'pt_source'      => 'Museu na casa onde Patrick Pearse viveu e dirigiu a sua escola de ensino em irlandês entre 1910 e 1916, num parque de cerca de 20 hectares.',
			'pt_title'       => 'Pearse Museum – St. Enda&#8217;s Park',
			'en_description' => 'A museum in the house where Patrick Pearse lived and ran his Irish-speaking school between 1910 and 1916, set in a park of about 20 hectares.',
		),
		'iveagh-gardens' => array(
			'pt_source'      => 'Conhecidos como o “jardim secreto” de Dublin, com labirinto de teixos, rosário, fontes e cascata, desenhados por Ninian Niven em 1865.',
			'pt_title'       => 'Iveagh Gardens',
			'en_description' => 'Known as Dublin\'s "secret garden", with a yew maze, rosery, fountains and cascade, designed by Ninian Niven in 1865.',
		),
		'irish-national-war-memorial-gardens' => array(
			'pt_source'      => 'Jardins memoriais em Islandbridge, dedicados aos 49.400 soldados irlandeses mortos na Primeira Guerra Mundial, desenhados por Sir Edwin Lutyens.',
			'pt_title'       => 'Irish National War Memorial Gardens',
			'en_description' => 'Memorial gardens at Islandbridge, dedicated to the 49,400 Irish soldiers who died in the First World War, designed by Sir Edwin Lutyens.',
		),
		'grangegorman-military-cemetery' => array(
			'pt_source'      => 'O maior cemitério militar da Irlanda, aberto em 1876 para pessoal do Império Britânico, com túmulos das duas guerras mundiais.',
			'pt_title'       => 'Grangegorman Military Cemetery',
			'en_description' => 'Ireland\'s largest military cemetery, opened in 1876 for British Empire personnel, with graves from both world wars.',
		),
		'government-buildings' => array(
			'pt_source'      => 'Sede de departamentos do Estado irlandês e o último grande edifício público construído pelos britânicos na Irlanda, com visitas guiadas gratuitas aos sábados.',
			'pt_title'       => 'Government Buildings',
			'en_description' => 'The seat of Irish government departments and the last major public building constructed by the British in Ireland, with free guided tours on Saturdays.',
		),
		'garden-of-remembrance' => array(
			'pt_source'      => 'Jardim memorial no centro de Dublin, dedicado a todos os que deram a vida pela liberdade irlandesa, com a escultura dos Filhos de Lir.',
			'pt_title'       => 'Garden of Remembrance',
			'en_description' => 'A memorial garden in central Dublin, dedicated to all those who gave their lives for Irish freedom, with the Children of Lir sculpture.',
		),
		'farmleigh-house-and-estate' => array(
			'pt_source'      => 'Propriedade eduardiana de 78 acres dentro do Phoenix Park, usada como residência oficial de hóspedes do Estado, com jardins e parque abertos ao público.',
			'pt_title'       => 'Farmleigh House and Gardens',
			'en_description' => 'A 78-acre Edwardian estate inside Phoenix Park, used as the State\'s official guest residence, with gardens and parkland open to the public.',
		),
		'custom-house-visitor-centre' => array(
			'pt_source'      => 'Centro de visitantes dedicado à história do edifício da Alfândega de Dublin, da autoria de James Gandon, e a mais de 230 anos de história irlandesa.',
			'pt_title'       => 'Custom House Visitor Centre',
			'en_description' => 'A visitor centre dedicated to the history of Dublin\'s Custom House, designed by James Gandon, and to more than 230 years of Irish history.',
		),
		'casino-marino' => array(
			'pt_source'      => 'Pequeno templo de jardim neoclássico do século XVIII, com 16 salas decoradas, considerado uma obra-prima da arquitetura europeia.',
			'pt_title'       => 'Casino Marino',
			'en_description' => 'A small 18th-century neoclassical garden temple, with 16 decorated rooms, considered a masterpiece of European architecture.',
		),
		'arbour-hill-cemetery' => array(
			'pt_source'      => 'Cemitério militar onde estão sepultados 14 dos líderes executados do Levante da Páscoa de 1916.',
			'pt_title'       => 'Arbour Hill Cemetery',
			'en_description' => 'A military cemetery where 14 of the executed leaders of the 1916 Easter Rising are buried.',
		),
		'fota-arboretum-and-gardens' => array(
			'pt_source'      => 'Arboreto de 11 hectares na Ilha de Fota com uma das melhores coleções da Europa de árvores e arbustos raros ao ar livre.',
			'pt_title'       => 'Fota Arboretum and Gardens',
			'en_description' => 'An 11-hectare arboretum on Fota Island with one of Europe\'s finest outdoor collections of rare trees and shrubs.',
		),
		'desmond-castle-kinsale' => array(
			'pt_source'      => 'Casa-torre urbana do início do século XVI no centro de Kinsale, que foi alfândega, prisão e armazém ao longo da sua história.',
			'pt_title'       => 'Desmond Castle Kinsale',
			'en_description' => 'An urban tower house from the early 16th century in the centre of Kinsale, which over its history served as custom house, prison and warehouse.',
		),
		'barryscourt-castle' => array(
			'pt_source'      => 'Um dos melhores exemplos de casa-torre irlandesa, construído entre 1392 e 1420, com salões restaurados e jardim de ervas.',
			'pt_title'       => 'Barryscourt Castle',
			'en_description' => 'One of the finest examples of an Irish tower house, built between 1392 and 1420, with restored halls and a herb garden.',
		),
		'annes-grove-gardens' => array(
			'pt_source'      => 'Antiga propriedade da família Annesley no norte de Cork, com jardins paisagísticos de estilo Robinsoniano e uma coleção rara de arbustos floridos.',
			'pt_title'       => 'Annes Grove Gardens',
			'en_description' => 'A former Annesley family estate in north Cork, with Robinsonian-style landscaped gardens and a rare collection of flowering shrubs.',
		),
		'national-design-craft-gallery' => array(
			'pt_source'      => 'Única galeria da Irlanda dedicada exclusivamente a design e artesanato, no Castle Yard do século XVIII, ao lado do Kilkenny Castle.',
			'pt_title'       => 'National Design &#038; Craft Gallery',
			'en_description' => 'Ireland\'s only gallery dedicated exclusively to design and craft, in the 18th-century Castle Yard, beside Kilkenny Castle.',
		),
		'cavan-cathedral' => array(
			'pt_source'      => 'Catedral católica de Cavan, dedicada a São Patrício e São Félim, construída na década de 1940 e notável pelo interior com vitrais.',
			'pt_title'       => 'Cavan Cathedral',
			'en_description' => 'Cavan\'s Catholic cathedral, dedicated to St Patrick and St Felim, built in the 1940s and notable for its stained-glass interior.',
		),
		'jfk-arboretum' => array(
			'pt_source'      => 'Arboreto de 623 acres perto de New Ross, dedicado a John F. Kennedy, com mais de 5.000 tipos de árvores e mirante no Slieve Coillte.',
			'pt_title'       => 'The John F. Kennedy Arboretum',
			'en_description' => 'A 623-acre arboretum near New Ross, dedicated to John F. Kennedy, with more than 5,000 varieties of trees and a lookout point on Slieve Coillte.',
		),
		'lough-muckno-leisure-park' => array(
			'pt_source'      => 'Parque lacustre municipal em Castleblayney, com trilhas, parque infantil, pesca e desportos aquáticos, aberto todo o ano.',
			'pt_title'       => 'Lough Muckno Leisure Park',
			'en_description' => 'A municipal lakeside park in Castleblayney, with walking trails, a playground, fishing and water sports, open all year round.',
		),
		'old-head-of-kinsale' => array(
			'pt_source'      => 'Ponta rochosa cênica perto de Kinsale, com farol, signal tower e memorial do Lusitania; o percurso exterior e o mirante são de acesso livre.',
			'pt_title'       => 'Old Head of Kinsale',
			'en_description' => 'A scenic rocky headland near Kinsale, with a lighthouse, signal tower and Lusitania memorial; the outer walk and lookout are freely accessible.',
		),
		'dursey-island' => array(
			'pt_source'      => 'Ilha habitada no fim da península de Beara, alcançada pelo único teleférico da Irlanda, com trilhas costeiras e colônias de aves marinhas.',
			'pt_title'       => 'Dursey Island',
			'en_description' => 'An inhabited island at the end of the Beara Peninsula, reached by Ireland\'s only cable car, with coastal trails and seabird colonies.',
		),
		'doagh-famine-village' => array(
			'pt_source'      => 'Museu a céu aberto na península de Inishowen que conta a história de uma família de Donegal da Grande Fome até os dias atuais.',
			'pt_title'       => 'Doagh Famine Village',
			'en_description' => 'An open-air museum on the Inishowen Peninsula telling the story of a Donegal family from the Great Famine to the present day.',
		),
		'the-model' => array(
			'pt_source'      => 'Galeria de arte de Sligo e sede da Niland Collection, com importantes obras de Jack B. Yeats e entrada gratuita às galerias.',
			'pt_title'       => 'The Model',
			'en_description' => 'Sligo\'s art gallery and home of the Niland Collection, with important works by Jack B. Yeats and free admission to the galleries.',
		),
		'joyce-tower-museum' => array(
			'pt_source'      => 'Torre Martello de 1804 onde começa o Ulysses, hoje museu gratuito com coleção joyceana e vista para a baía de Dublin.',
			'pt_title'       => 'James Joyce Tower Museum',
			'en_description' => 'An 1804 Martello tower where Ulysses opens, today a free museum with a Joycean collection and views over Dublin Bay.',
		),
		'derrigimlagh' => array(
			'pt_source'      => 'Turfeira de Connemara com dois marcos do século XX: as ruínas da estação de rádio transatlântica de Marconi e o memorial da travessia de Alcock e Brown.',
			'pt_title'       => 'Derrigimlagh',
			'en_description' => 'A Connemara bogland with two 20th-century landmarks: the ruins of Marconi\'s transatlantic radio station and the memorial to Alcock and Brown\'s crossing.',
		),
		'forty-foot' => array(
			'pt_source'      => 'Ponto histórico de banho de mar em Sandycove, no extremo sul da baía de Dublin, com tradição de natação o ano inteiro.',
			'pt_title'       => 'The Forty Foot',
			'en_description' => 'A historic sea-bathing spot at Sandycove, at the southern tip of Dublin Bay, with a year-round swimming tradition.',
		),
		'chester-beatty' => array(
			'pt_source'      => 'Museu de culturas do mundo dentro do Dublin Castle, com entrada gratuita e coleções da Ásia, do Oriente Médio, do Norte da África e da Europa.',
			'pt_title'       => 'Chester Beatty',
			'en_description' => 'A museum of world cultures inside Dublin Castle, with free entry and collections from Asia, the Middle East, North Africa and Europe.',
		),
		'king-johns-castle-carlingford' => array(
			'pt_source'      => 'Fortaleza normanda do século XII sobre o Carlingford Lough, com salão medieval e vista para as Mourne Mountains.',
			'pt_title'       => 'King John&#8217;s Castle (Carlingford)',
			'en_description' => 'A 12th-century Norman fortress above Carlingford Lough, with a medieval hall and views of the Mourne Mountains.',
		),
		'mount-leinster-nine-stones' => array(
			'pt_source'      => 'Ponto mais alto das Blackstairs Mountains (795 m), com mirante dos Nine Stones e vista para quatro condados.',
			'pt_title'       => 'Mount Leinster &#038; Nine Stones',
			'en_description' => 'The highest point of the Blackstairs Mountains (795 m), with the Nine Stones lookout and views over four counties.',
		),
		'oak-park-forest-park' => array(
			'pt_source'      => 'Floresta de carvalhos e faías com trilhas, lagos e o Oak Park Lake, perto da cidade de Carlow.',
			'pt_title'       => 'Oak Park Forest Park',
			'en_description' => 'Woodland of oak and beech with trails, lakes and Oak Park Lake, near the town of Carlow.',
		),
		'borris-house' => array(
			'pt_source'      => 'Casa ancestral dos MacMurrough Kavanagh, reis de Leinster, com salões Tudor e mercado de artesãos.',
			'pt_title'       => 'Borris House',
			'en_description' => 'The ancestral home of the MacMurrough Kavanaghs, kings of Leinster, with Tudor halls and a craft market.',
		),
		'huntington-castle-gardens' => array(
			'pt_source'      => 'Castelo de 1625 da família Durdin-Robertson, com porões medievais, jardins murados e templo druídico.',
			'pt_title'       => 'Huntington Castle &#038; Gardens',
			'en_description' => 'A 1625 castle of the Durdin-Robertson family, with medieval cellars, walled gardens and a druidic temple.',
		),
		'ducketts-grove' => array(
			'pt_source'      => 'Ruínas góticas vitorianas da mansão Duckett, com walled gardens restaurados e parque público.',
			'pt_title'       => 'Duckett&#8217;s Grove',
			'en_description' => 'Victorian Gothic ruins of the Duckett mansion, with restored walled gardens and a public park.',
		),
		'altamont-gardens' => array(
			'pt_source'      => 'Jardins românticos do século XVIII com lago, arboreto e o "corredor de azaleias" — entrada gratuita.',
			'pt_title'       => 'Altamont Gardens',
			'en_description' => 'Romantic 18th-century gardens with a lake, arboretum and the "azalea walk" — free entry.',
		),
		'brownshill-dolmen' => array(
			'pt_source'      => 'Dólmen com a maior laje de cobertura da Europa: 100 toneladas de granito sobre pedras de suporte de 5.000 anos.',
			'pt_title'       => 'Brownshill Dolmen',
			'en_description' => 'A dolmen with the largest capstone in Europe: 100 tonnes of granite resting on 5,000-year-old support stones.',
		),
		'millmount-fort-museum' => array(
			'pt_source'      => 'Fortaleza sobre motte normanda com vista de Drogheda, abrigando o museu municipal e a torre Martello.',
			'pt_title'       => 'Millmount Fort &#038; Museum',
			'en_description' => 'A fortress on a Norman motte with views over Drogheda, housing the county museum and the Martello tower.',
		),
		'carlingford-lough-greenway' => array(
			'pt_source'      => 'Greenway de 10 km ao longo do lough, entre Carlingford e Newry, com vista para as Mourne Mountains.',
			'pt_title'       => 'Carlingford Lough Greenway',
			'en_description' => 'A 10 km greenway along the lough, between Carlingford and Newry, with views of the Mourne Mountains.',
		),
		'mellifont-abbey' => array(
			'pt_source'      => 'Primeira abadia cisterciense da Irlanda (1142), com lavabo octogonal único e ruínas evocativas.',
			'pt_title'       => 'Mellifont Abbey',
			'en_description' => 'Ireland\'s first Cistercian abbey (1142), with a unique octagonal lavabo and evocative ruins.',
		),
		'monasterboice' => array(
			'pt_source'      => 'Sítio monástico com as alta cruzes mais famosas da Irlanda, incluindo a Muiredach\'s Cross do século X.',
			'pt_title'       => 'Monasterboice',
			'en_description' => 'A monastic site with Ireland\'s most famous high crosses, including the 10th-century Muiredach\'s Cross.',
		),
		'proleek-dolmen' => array(
			'pt_source'      => 'Dólmen megalítico com laje de 40 toneladas, cercado de lendas — quem acertar uma pedra no topo terá sorte.',
			'pt_title'       => 'Proleek Dolmen',
			'en_description' => 'A megalithic dolmen with a 40-tonne capstone, wrapped in legend — whoever lands a stone on top will have good luck.',
		),
		'carlingford' => array(
			'pt_source'      => 'Vila medieval preservada na costa do Carlingford Lough, com muralhas, castelo e o Carlingford Oyster Festival.',
			'pt_title'       => 'Carlingford',
			'en_description' => 'A preserved medieval village on the shores of Carlingford Lough, with town walls, a castle and the Carlingford Oyster Festival.',
		),
		'mondello-park' => array(
			'pt_source'      => 'Único autódromo internacional da Irlanda, com corridas abertas ao público e experiências de pilotagem.',
			'pt_title'       => 'Mondello Park',
			'en_description' => 'Ireland\'s only international racing circuit, with race meetings open to the public and driving experiences.',
		),
		'lullymore-heritage-park' => array(
			'pt_source'      => 'Parque de descoberta numa ilha de calcário na turfeira, com tren, playground, minigolfe e história da turfa.',
			'pt_title'       => 'Lullymore Heritage &#038; Discovery Park',
			'en_description' => 'A discovery park on a limestone island in the boglands, with a train ride, playground, mini golf and peatland history.',
		),
		'donadea-forest-park' => array(
			'pt_source'      => 'Floresta com trilhas, lago, torre do castelo de Aylmer e o 9/11 Memorial Garden.',
			'pt_title'       => 'Donadea Forest Park',
			'en_description' => 'A forest park with trails, a lake, the Aylmer castle tower and the 9/11 Memorial Garden.',
		),
		'maynooth-castle' => array(
			'pt_source'      => 'Fortaleza dos Fitzgerald, os poderosos condes de Kildare, no campus da universidade mais antiga da Irlanda.',
			'pt_title'       => 'Maynooth Castle',
			'en_description' => 'A Fitzgerald stronghold of the powerful Earls of Kildare, on the campus of Ireland\'s oldest university.',
		),
		'pollardstown-fen' => array(
			'pt_source'      => 'Maior fen (pântano alcalino) da Irlanda, reserva natural com passarelas e plantas raras de origem glacial.',
			'pt_title'       => 'Pollardstown Fen',
			'en_description' => 'Ireland\'s largest fen (alkaline marsh), a nature reserve with boardwalks and rare plants of glacial origin.',
		),
		'bog-of-allen-nature-centre' => array(
			'pt_source'      => 'Centro do Irish Peatland Conservation Council com jardim de sapos, turfeira visitável e exposições sobre pântanos.',
			'pt_title'       => 'Bog of Allen Nature Centre',
			'en_description' => 'The Irish Peatland Conservation Council\'s centre, with a frog garden, a walkable bog and exhibitions about peatlands.',
		),
		'castletown-house' => array(
			'pt_source'      => 'Mansão palladiana de 1722, a maior da Irlanda, com salões restaurados e parque aberto ao público.',
			'pt_title'       => 'Castletown House',
			'en_description' => 'A Palladian mansion of 1722, the largest in Ireland, with restored halls and parkland open to the public.',
		),
		'irish-national-stud-japanese-gardens' => array(
			'pt_source'      => 'Haras nacional com garanhões campeões, museu do cavalo e os famosos Jardins Japoneses de 1906.',
			'pt_title'       => 'Irish National Stud &#038; Japanese Gardens',
			'en_description' => 'The national stud farm with champion stallions, a horse museum and the famous Japanese Gardens of 1906.',
		),
		'curraghchase-forest-park' => array(
			'pt_source'      => 'Parque florestal com ruínas da mansão De Vere, trilhas, lago e colônia de morcegos protegida.',
			'pt_title'       => 'Curraghchase Forest Park',
			'en_description' => 'A forest park with the ruins of the De Vere mansion, trails, a lake and a protected bat colony.',
		),
		'lough-gur' => array(
			'pt_source'      => 'Lago em forma de rim cercado por megálitos, círculos de pedra e o Grange Stone Circle, o maior da Irlanda.',
			'pt_title'       => 'Lough Gur',
			'en_description' => 'A kidney-shaped lake surrounded by megaliths, stone circles and the Grange Stone Circle, the largest in Ireland.',
		),
		'foynes-flying-boat-museum' => array(
			'pt_source'      => 'Museu na antiga base do Pan Am, com réplica em tamanho real do Boeing 314 e a origem do Irish Coffee.',
			'pt_title'       => 'Foynes Flying Boat &#038; Maritime Museum',
			'en_description' => 'A museum on the former Pan Am base, with a full-size replica Boeing 314 and the origins of Irish Coffee.',
		),
		'adare-village' => array(
			'pt_source'      => 'Vila considerada a mais bonita da Irlanda, com cottages de palha, mosteiros e o castelo dos Desmond.',
			'pt_title'       => 'Adare',
			'en_description' => 'A village considered Ireland\'s prettiest, with thatched cottages, monasteries and the Desmond castle.',
		),
		'st-marys-cathedral-limerick' => array(
			'pt_source'      => 'Catedral de 1168, o edifício em uso mais antigo de Limerick, com misericórdias medievais raras.',
			'pt_title'       => 'St Mary&#8217;s Cathedral',
			'en_description' => 'A cathedral of 1168, Limerick\'s oldest building still in use, with rare medieval misericords.',
		),
		'hunt-museum' => array(
			'pt_source'      => 'Coleção de arte e antiguidades dos Hunt, com Picasso, Renoir, Leonardo da Vinci e artefatos célticos.',
			'pt_title'       => 'Hunt Museum',
			'en_description' => 'The Hunts\' collection of art and antiquities, with Picasso, Renoir, Leonardo da Vinci and Celtic artefacts.',
		),
		'king-johns-castle-limerick' => array(
			'pt_source'      => 'Castelo normando de 1210 às margens do Shannon, com exposições interativas sobre cercos e história medieval.',
			'pt_title'       => 'King John&#8217;s Castle',
			'en_description' => 'A Norman castle of 1210 on the banks of the Shannon, with interactive exhibitions on sieges and medieval history.',
		),
		'holy-cross-abbey' => array(
			'pt_source'      => 'Abadia cisterciense restaurada do século XII, famosa pela relíquia da Vera Cruz e ainda local de culto.',
			'pt_title'       => 'Holy Cross Abbey',
			'en_description' => 'A restored 12th-century Cistercian abbey, famous for the relic of the True Cross and still a place of worship.',
		),
		'mitchelstown-cave' => array(
			'pt_source'      => 'Uma das maiores cavernas da Europa, com estalactites gigantes, salões enormes e acústica de concertos.',
			'pt_title'       => 'Mitchelstown Cave',
			'en_description' => 'One of Europe\'s largest caves, with giant stalactites, vast chambers and concert-hall acoustics.',
		),
		'ormond-castle' => array(
			'pt_source'      => 'Casa isabelina única na Irlanda, construída por Tomás Butler, 10º Conde de Ormond, junto a torres medievais.',
			'pt_title'       => 'Ormond Castle',
			'en_description' => 'Ireland\'s only Elizabethan manor house, built by Thomas Butler, 10th Earl of Ormond, alongside medieval towers.',
		),
		'the-vee-bay-lough' => array(
			'pt_source'      => 'Passo de montanha em forma de V com mirante sobre o vale do Suir e o lago glaciar Bay Lough.',
			'pt_title'       => 'The Vee &#038; Bay Lough',
			'en_description' => 'A V-shaped mountain pass with a viewpoint over the Suir valley and the glacial lake Bay Lough.',
		),
		'glen-of-aherlow' => array(
			'pt_source'      => 'Vale cênico entre as Galtee Mountains, com trilhas, mirante do Christ the King e vilas charmosas.',
			'pt_title'       => 'Glen of Aherlow',
			'en_description' => 'A scenic valley between the Galtee Mountains, with trails, the Christ the King viewpoint and charming villages.',
		),
		'swiss-cottage' => array(
			'pt_source'      => 'Chalé ornamental de 1810, exemplo perfeito do estilo "picturesque", com interior restaurado e floresta.',
			'pt_title'       => 'Swiss Cottage',
			'en_description' => 'An ornamental cottage of 1810, a perfect example of the "picturesque" style, with a restored interior and woodland.',
		),
		'cahir-castle' => array(
			'pt_source'      => 'Um dos castelos mais bem preservados da Irlanda, do século XIII, numa ilha do rio Suir.',
			'pt_title'       => 'Cahir Castle',
			'en_description' => 'One of Ireland\'s best-preserved castles, from the 13th century, on an island in the River Suir.',
		),
		'rock-of-cashel' => array(
			'pt_source'      => 'Conjunto medieval espetacular sobre afloramento de calcário: catedral gótica, capela românica e torre redonda.',
			'pt_title'       => 'Rock of Cashel',
			'en_description' => 'A spectacular medieval complex on a limestone outcrop: Gothic cathedral, Romanesque chapel and round tower.',
		),
		'tintern-abbey-wexford' => array(
			'pt_source'      => 'Abadia cisterciense fundada em 1200 por William Marshal, com colunata vitoriana e vale arborizado.',
			'pt_title'       => 'Tintern Abbey',
			'en_description' => 'A Cistercian abbey founded in 1200 by William Marshal, with a Victorian colonnade and a wooded valley.',
		),
		'ferns-castle' => array(
			'pt_source'      => 'Castelo normando do século XIII com capela em cruz e vitrais modernos, na antiga capital dos reis de Leinster.',
			'pt_title'       => 'Ferns Castle',
			'en_description' => 'A 13th-century Norman castle with a cruciform chapel and modern stained glass, in the former capital of the kings of Leinster.',
		),
		'wexford-wildfowl-reserve' => array(
			'pt_source'      => 'Reserva natural no North Slob, refúgio de inverno de um terço da população mundial de gansos-de-frente-branca da Groenlândia.',
			'pt_title'       => 'Wexford Wildfowl Reserve',
			'en_description' => 'A nature reserve on the North Slob, winter home to one third of the world\'s Greenland white-fronted geese.',
		),
		'curracloe-beach' => array(
			'pt_source'      => 'Praia de 11 km de areia fina, cenário da abertura de "O Resgate do Soldado Ryan", com dunas e trilhas.',
			'pt_title'       => 'Curracloe Beach',
			'en_description' => 'An 11 km stretch of fine sandy beach, location of the opening of "Saving Private Ryan", with dunes and trails.',
		),
		'saltee-islands' => array(
			'pt_source'      => 'Santuário de aves marinhas com uma das maiores colônias de gansos-patola da Irlanda, acessível por barco de Kilmore Quay.',
			'pt_title'       => 'Saltee Islands',
			'en_description' => 'A seabird sanctuary with one of Ireland\'s largest gannet colonies, reachable by boat from Kilmore Quay.',
		),
		'dunbrody-famine-ship' => array(
			'pt_source'      => 'Réplica fiel de um navio de emigrantes da Grande Fome, com tour imersivo sobre a travessia do Atlântico.',
			'pt_title'       => 'Dunbrody Famine Ship',
			'en_description' => 'A faithful replica of a Great Famine emigrant ship, with an immersive tour of the Atlantic crossing.',
		),
		'hook-lighthouse' => array(
			'pt_source'      => 'Farol medieval em operação desde o século XIII — um dos mais antigos do mundo — com tours e café.',
			'pt_title'       => 'Hook Lighthouse',
			'en_description' => 'A medieval lighthouse in operation since the 13th century — one of the oldest in the world — with tours and a café.',
		),
		'johnstown-castle' => array(
			'pt_source'      => 'Castelo neogótico do século XIX com lagos, jardins e o Museu da Agricultura Irlandesa.',
			'pt_title'       => 'Johnstown Castle',
			'en_description' => 'A 19th-century neo-Gothic castle with lakes, gardens and the Irish Agricultural Museum.',
		),
		'irish-national-heritage-park' => array(
			'pt_source'      => 'Parque de 35 acres que recria 9.000 anos de história irlandesa: cabanas do mesolítico, crannóg, mosteiro e anel normando.',
			'pt_title'       => 'Irish National Heritage Park',
			'en_description' => 'A 35-acre park recreating 9,000 years of Irish history: Mesolithic huts, a crannóg, a monastery and a Norman ringwork.',
		),
		'coumshingaun-loop' => array(
			'pt_source'      => 'Trilha circular em torno de um lago glaciar num circo de falésias — uma das caminhadas mais espetaculares do sul.',
			'pt_title'       => 'Coumshingaun Loop (Comeragh Mountains)',
			'en_description' => 'A circular trail around a glacial lake in an amphitheatre of cliffs — one of the most spectacular walks in the south.',
		),
		'ardmore-round-tower' => array(
			'pt_source'      => 'Sítio monástico de São Declan com torre redonda de 30 m, catedral com esculturas e oratório primitivo.',
			'pt_title'       => 'Ardmore Round Tower &#038; Cathedral',
			'en_description' => 'St Declan\'s monastic site with a 30 m round tower, a carved cathedral and an early oratory.',
		),
		'lismore-castle-gardens' => array(
			'pt_source'      => 'Jardins históricos do castelo dos duques de Devonshire, com upper e lower gardens sobre o rio Blackwater.',
			'pt_title'       => 'Lismore Castle Gardens',
			'en_description' => 'Historic gardens of the castle of the Dukes of Devonshire, with upper and lower gardens above the River Blackwater.',
		),
		'mount-congreve-gardens' => array(
			'pt_source'      => 'Um dos maiores jardins privados da Europa, com 70 acres de azaleias, rododendros e bosques plantados por Ambrose Congreve.',
			'pt_title'       => 'Mount Congreve Gardens',
			'en_description' => 'One of Europe\'s largest private gardens, with 70 acres of azaleas, rhododendrons and woodlands planted by Ambrose Congreve.',
		),
		'copper-coast-geopark' => array(
			'pt_source'      => 'Litoral de 25 km com falésias, praias e geologia vulcânica de 460 milhões de anos, patrimônio UNESCO.',
			'pt_title'       => 'Copper Coast Geopark',
			'en_description' => 'A 25 km coastline of cliffs, beaches and 460-million-year-old volcanic geology, a UNESCO heritage site.',
		),
		'waterford-greenway' => array(
			'pt_source'      => 'Greenway de 46 km sobre antiga ferrovia, com viadutos, túnel e travessia do rio Suir — a mais popular da Irlanda.',
			'pt_title'       => 'Waterford Greenway',
			'en_description' => 'A 46 km greenway along a former railway, with viaducts, a tunnel and a crossing of the River Suir — the most popular in Ireland.',
		),
		'reginals-tower' => array(
			'pt_source'      => 'Torre viking do século XIII, o edifício civil mais antigo da Irlanda em uso contínuo.',
			'pt_title'       => 'Reginald&#8217;s Tower',
			'en_description' => 'A 13th-century Viking tower, Ireland\'s oldest civic building in continuous use.',
		),
		'waterford-treasures' => array(
			'pt_source'      => 'Conjunto de museus sobre a Irlanda viking e medieval, no coração do Viking Triangle.',
			'pt_title'       => 'Waterford Treasures Museums',
			'en_description' => 'A group of museums about Viking and medieval Ireland, in the heart of the Viking Triangle.',
		),
		'house-of-waterford-crystal' => array(
			'pt_source'      => 'Fábrica e centro de visitantes do cristal Waterford, com tour pela produção e galeria de peças únicas.',
			'pt_title'       => 'House of Waterford Crystal',
			'en_description' => 'The Waterford Crystal factory and visitor centre, with a tour of the production floor and a gallery of one-of-a-kind pieces.',
		),
		'woodstock-gardens' => array(
			'pt_source'      => 'Jardins vitorianos restaurados da mansão Tighe, com arboreto, avenue de teixos e vista do vale do Nore.',
			'pt_title'       => 'Woodstock Gardens',
			'en_description' => 'Restored Victorian gardens of the Tighe mansion, with an arboretum, an avenue of yews and views over the Nore valley.',
		),
		'jerpoint-park' => array(
			'pt_source'      => 'Cidade medieval perdida de Newtown Jerpoint, com igreja de São Nicolau e lendas sobre o santo.',
			'pt_title'       => 'Jerpoint Park (Newtown Jerpoint)',
			'en_description' => 'The lost medieval town of Newtown Jerpoint, with the church of St Nicholas and legends about the saint.',
		),
		'castlecomer-discovery-park' => array(
			'pt_source'      => 'Parque de aventura em 80 acres de floresta, com arborismo, zip lines, canoagem e trilhas.',
			'pt_title'       => 'Castlecomer Discovery Park',
			'en_description' => 'An adventure park in 80 acres of woodland, with treetop walks, zip lines, canoeing and trails.',
		),
		'dunmore-cave' => array(
			'pt_source'      => 'Caverna de calcário com formações dramáticas e história ligada a um ataque viking do século X.',
			'pt_title'       => 'Dunmore Cave',
			'en_description' => 'A limestone cave with dramatic formations and a history linked to a 10th-century Viking attack.',
		),
		'smithwicks-experience' => array(
			'pt_source'      => 'Tour pela antiga cervejaria de Kilkenny, onde o ale Smithwick\'s é produzido desde 1710.',
			'pt_title'       => 'Smithwick&#8217;s Experience',
			'en_description' => 'A tour of Kilkenny\'s former brewery, where Smithwick\'s ale has been brewed since 1710.',
		),
		'rothe-house-garden' => array(
			'pt_source'      => 'Casa mercantil tudor do século XVII, única do tipo aberta ao público na Irlanda, com jardim de recriação histórica.',
			'pt_title'       => 'Rothe House &#038; Garden',
			'en_description' => 'A 17th-century Tudor merchant\'s house, the only one of its kind open to the public in Ireland, with a historically recreated garden.',
		),
		'jerpoint-abbey' => array(
			'pt_source'      => 'Abadia cisterciense do século XII com esculturas góticas excepcionais e figuras esculpidas nos túmulos.',
			'pt_title'       => 'Jerpoint Abbey',
			'en_description' => 'A 12th-century Cistercian abbey with exceptional Gothic carvings and sculpted figures on its tombs.',
		),
		'medieval-mile-museum' => array(
			'pt_source'      => 'Museu na igreja de St Mary sobre a Kilkenny medieval, com cripta e lápidas dos mercadores.',
			'pt_title'       => 'Medieval Mile Museum',
			'en_description' => 'A museum in St Mary\'s Church about medieval Kilkenny, with a crypt and merchants\' tombstones.',
		),
		'kilkenny-castle' => array(
			'pt_source'      => 'Castelo normando de 1195, sede dos duques de Ormonde por seis séculos, com jardins vitorianos.',
			'pt_title'       => 'Kilkenny Castle',
			'en_description' => 'A Norman castle of 1195, seat of the Dukes of Ormonde for six centuries, with Victorian gardens.',
		),
		'clare-island' => array(
			'pt_source'      => 'Ilha da pirata Grace O\'Malley na entrada de Clew Bay, com abadia muralhada, farol e trilhas costeiras.',
			'pt_title'       => 'Clare Island',
			'en_description' => 'Grace O\'Malley\'s pirate island at the mouth of Clew Bay, with a walled abbey, a lighthouse and coastal trails.',
		),
		'wild-nephin-ballycroy-national-park' => array(
			'pt_source'      => 'Parque nacional de turfeiras selvagens e montanhas Nephin, com centro de visitantes e céus escuros.',
			'pt_title'       => 'Wild Nephin Ballycroy National Park',
			'en_description' => 'A national park of wild blanket bogs and the Nephin mountains, with a visitor centre and dark skies.',
		),
		'great-western-greenway' => array(
			'pt_source'      => 'Greenway de 42 km sobre antiga ferrovia, de Westport a Achill, um dos passeios de bicicleta mais bonitos da Irlanda.',
			'pt_title'       => 'Great Western Greenway',
			'en_description' => 'A 42 km greenway along a former railway, from Westport to Achill — one of Ireland\'s most beautiful cycle rides.',
		),
		'ceide-fields' => array(
			'pt_source'      => 'Sistema agrícola neolítico de 5.500 anos, o mais extenso do mundo, sob a turfeira do norte de Mayo.',
			'pt_title'       => 'Céide Fields',
			'en_description' => 'A 5,500-year-old Neolithic field system, the most extensive in the world, beneath the blanket bog of north Mayo.',
		),
		'downpatrick-head' => array(
			'pt_source'      => 'Cabeço com o sea stack Dún Briste ("rota quebrada"), igreja antiga e poço de São Patrício sobre o Atlântico.',
			'pt_title'       => 'Downpatrick Head',
			'en_description' => 'A headland with the Dún Briste sea stack ("broken track"), an old church and St Patrick\'s well above the Atlantic.',
		),
		'national-museum-country-life' => array(
			'pt_source'      => 'Museu nacional da vida rural irlandesa, num parque com jardins e moinho, entrada gratuita.',
			'pt_title'       => 'National Museum of Ireland – Country Life',
			'en_description' => 'The national museum of Irish rural life, in grounds with gardens and a mill — free entry.',
		),
		'westport-house' => array(
			'pt_source'      => 'Mansão georgiana da família Browne com parque de aventura, zoológico pequeno e terrenos históricos.',
			'pt_title'       => 'Westport House &#038; Adventure Park',
			'en_description' => 'A Georgian mansion of the Browne family with an adventure park, a small zoo and historic grounds.',
		),
		'keem-bay' => array(
			'pt_source'      => 'Baía em forma de ferradura com areia branca, eleita repetidamente entre as praias mais bonitas da Irlanda.',
			'pt_title'       => 'Keem Bay',
			'en_description' => 'A horseshoe-shaped bay of white sand, repeatedly voted among Ireland\'s most beautiful beaches.',
		),
		'achill-island' => array(
			'pt_source'      => 'Maior ilha da Irlanda, ligada por ponte, com falésias de Croaghaun, praias selvagens e vila de Keel.',
			'pt_title'       => 'Achill Island',
			'en_description' => 'Ireland\'s largest island, linked by a bridge, with the Croaghaun cliffs, wild beaches and the village of Keel.',
		),
		'croagh-patrick' => array(
			'pt_source'      => 'Montanha sagrada de São Patrício (764 m), com peregrinação anual e vista sobre Clew Bay e suas 365 ilhotas.',
			'pt_title'       => 'Croagh Patrick',
			'en_description' => 'St Patrick\'s holy mountain (764 m), with an annual pilgrimage and views over Clew Bay and its 365 islands.',
		),
		'eagles-flying' => array(
			'pt_source'      => 'Centro irlandês de aves de rapina com demonstrações de voo de águias, falcões e corujas.',
			'pt_title'       => 'Eagles Flying',
			'en_description' => 'Ireland\'s birds-of-prey centre with flying displays of eagles, falcons and owls.',
		),
		'drumcliffe' => array(
			'pt_source'      => 'Sítio monástico do século VI com alta cruz e torre redonda, onde está o túmulo de W.B. Yeats.',
			'pt_title'       => 'Drumcliffe',
			'en_description' => 'A 6th-century monastic site with a high cross and round tower, where W.B. Yeats is buried.',
		),
		'lissadell-house' => array(
			'pt_source'      => 'Mansão dos Gore-Booth, ligada a Constance Markievicz e a Yeats, com jardins e galeria de exposições.',
			'pt_title'       => 'Lissadell House',
			'en_description' => 'The Gore-Booth family mansion, linked to Constance Markievicz and Yeats, with gardens and an exhibition gallery.',
		),
		'sligo-abbey' => array(
			'pt_source'      => 'Abadia dominicana do século XIII, única com altar-mor medieval original preservado na Irlanda.',
			'pt_title'       => 'Sligo Abbey',
			'en_description' => 'A 13th-century Dominican abbey, the only one in Ireland with its original medieval high altar preserved.',
		),
		'queen-maeves-trail-knocknarea' => array(
			'pt_source'      => 'Trilha até o cairn de pedra da rainha Medb no topo do Knocknarea, com vista de Sligo e do Atlântico.',
			'pt_title'       => 'Queen Maeve&#8217;s Trail (Knocknarea)',
			'en_description' => 'A trail up to Queen Medb\'s cairn of stones on the summit of Knocknarea, with views of Sligo and the Atlantic.',
		),
		'carrowmore-megalithic-cemetery' => array(
			'pt_source'      => 'Maior conjunto de túmulos megalíticos da Irlanda, com mais de 5.500 anos, sob o olhar do Knocknarea.',
			'pt_title'       => 'Carrowmore Megalithic Cemetery',
			'en_description' => 'Ireland\'s largest concentration of megalithic tombs, over 5,500 years old, beneath the gaze of Knocknarea.',
		),
		'strandhill-beach' => array(
			'pt_source'      => 'Praia de surf aos pés do Knocknarea, com escolas de surf, banhos de sargaço e pôr do sol famoso.',
			'pt_title'       => 'Strandhill Beach',
			'en_description' => 'A surfing beach at the foot of Knocknarea, with surf schools, seaweed baths and a famous sunset.',
		),
		'benbulben' => array(
			'pt_source'      => 'Mesa de calcário símbolo de Sligo, imortalizada por Yeats, com trilha na crista e caminhada fácil na base.',
			'pt_title'       => 'Benbulben',
			'en_description' => 'A limestone table mountain and symbol of Sligo, immortalised by Yeats, with a ridge trail and an easy walk at its base.',
		),
		'ards-forest-park' => array(
			'pt_source'      => 'Península florestal com praias, ruínas franciscanas e trilhas costeiras de frente para Sheephaven Bay.',
			'pt_title'       => 'Ards Forest Park',
			'en_description' => 'A forested peninsula with beaches, Franciscan ruins and coastal trails facing Sheephaven Bay.',
		),
		'errigal' => array(
			'pt_source'      => 'Montanha mais alta de Donegal (751 m), com subida íngreme e panorama sobre o Poisoned Glen.',
			'pt_title'       => 'Errigal',
			'en_description' => 'Donegal\'s highest mountain (751 m), with a steep climb and a panorama over the Poisoned Glen.',
		),
		'grianan-of-aileach' => array(
			'pt_source'      => 'Fortaleza de pedra do século VI/VII no topo do Greenan Mountain, antiga sede dos reis de Tyrconnell.',
			'pt_title'       => 'Grianán of Aileach',
			'en_description' => 'A 6th/7th-century stone fort on top of Greenan Mountain, former seat of the kings of Tyrconnell.',
		),
		'fanad-head-lighthouse' => array(
			'pt_source'      => 'Farol icônico de 1817 entre as praias de Fanad, com tours internos e hospedagem no farol.',
			'pt_title'       => 'Fanad Head Lighthouse',
			'en_description' => 'An iconic 1817 lighthouse among the Fanad beaches, with interior tours and lighthouse accommodation.',
		),
		'malin-head' => array(
			'pt_source'      => 'Ponto mais ao norte da Irlanda, com torre de sinais napoleônica, locações de Star Wars e vistas do Atlântico.',
			'pt_title'       => 'Malin Head',
			'en_description' => 'Ireland\'s northernmost point, with a Napoleonic signal tower, Star Wars filming locations and Atlantic views.',
		),
		'donegal-castle' => array(
			'pt_source'      => 'Fortaleza dos O\'Donnell restaurada com mansão jacobina anexa, no centro de Donegal Town.',
			'pt_title'       => 'Donegal Castle',
			'en_description' => 'A restored O\'Donnell fortress with an adjoining Jacobean mansion, in the centre of Donegal Town.',
		),
		'sliabh-liag' => array(
			'pt_source'      => 'Falésias marinhas de quase 600 metros — entre as mais altas da Europa — com a trilha One Man’s Pass.',
			'pt_title'       => 'Sliabh Liag (Slieve League)',
			'en_description' => 'Sea cliffs of almost 600 metres — among the highest in Europe — with the One Man\'s Pass trail.',
		),
		'glenveagh-national-park' => array(
			'pt_source'      => 'Segundo maior parque nacional da Irlanda, com castelo, jardins, vales glaciais e águias-reais reintroduzidas.',
			'pt_title'       => 'Glenveagh National Park',
			'en_description' => 'Ireland\'s second-largest national park, with a castle, gardens, glacial valleys and reintroduced golden eagles.',
		),
		'sliabh-beagh' => array(
			'pt_source'      => 'Planalto de turfeira na fronteira com a Irlanda do Norte, com trilhas, mirante e centro de visitantes.',
			'pt_title'       => 'Sliabh Beagh',
			'en_description' => 'A bogland plateau on the border with Northern Ireland, with trails, a viewpoint and a visitor centre.',
		),
		'patrick-kavanagh-centre' => array(
			'pt_source'      => 'Centro dedicado ao poeta Patrick Kavanagh na igreja antiga de Inniskeen, sua terra natal.',
			'pt_title'       => 'Patrick Kavanagh Centre',
			'en_description' => 'A centre dedicated to the poet Patrick Kavanagh in the old church of Inniskeen, his birthplace.',
		),
		'carrickmacross-lace-gallery' => array(
			'pt_source'      => 'Ateliê e galeria da famosa renda de Carrickmacross, técnica usada no vestido de noiva de Diana, princesa de Gales.',
			'pt_title'       => 'Carrickmacross Lace Gallery',
			'en_description' => 'A studio and gallery of the famous Carrickmacross lace, the technique used in the wedding dress of Diana, Princess of Wales.',
		),
		'monaghan-county-museum' => array(
			'pt_source'      => 'Primeiro museu de condado da Irlanda, premiado, com coleções sobre artesanato, conflitos e vida rural.',
			'pt_title'       => 'Monaghan County Museum',
			'en_description' => 'Ireland\'s first county museum, award-winning, with collections on crafts, conflict and rural life.',
		),
		'rossmore-forest-park' => array(
			'pt_source'      => 'Antiga propriedade dos Rossmore com trilhas, esculturas de madeira, lagos e ruínas de portais.',
			'pt_title'       => 'Rossmore Forest Park',
			'en_description' => 'A former Rossmore estate with trails, wooden sculptures, lakes and the ruins of old gateways.',
		),
		'castle-leslie-estate' => array(
			'pt_source'      => 'Propriedade da família Leslie desde 1660, com castelo vitoriano, centro equestre, lago e trilhas.',
			'pt_title'       => 'Castle Leslie Estate',
			'en_description' => 'A Leslie family estate since 1660, with a Victorian castle, an equestrian centre, a lake and trails.',
		),
		'belturbet-railway-station' => array(
			'pt_source'      => 'Estação ferroviária de bitola estreita restaurada, com museu ferroviário e vagões de época.',
			'pt_title'       => 'Belturbet Railway Station',
			'en_description' => 'A restored narrow-gauge railway station, with a railway museum and period carriages.',
		),
		'cavan-county-museum' => array(
			'pt_source'      => 'Museu premiado com a maior réplica de trincheira da Primeira Guerra Mundial ao ar livre na Irlanda.',
			'pt_title'       => 'Cavan County Museum',
			'en_description' => 'An award-winning museum with Ireland\'s largest outdoor First World War trench replica.',
		),
		'shannon-pot' => array(
			'pt_source'      => 'Nascente do rio Shannon, o mais longo da Irlanda, num poço cárstico nas encostas do Cuilcagh.',
			'pt_title'       => 'Shannon Pot',
			'en_description' => 'The source of the River Shannon, Ireland\'s longest river, in a karst pool on the slopes of Cuilcagh.',
		),
		'dun-a-ri-forest-park' => array(
			'pt_source'      => 'Parque florestal com cabanas de gelo, ponte romana e lendas locais, nas margens do rio Cabra.',
			'pt_title'       => 'Dún a Rí Forest Park',
			'en_description' => 'A forest park with ice houses, a Roman bridge and local legends, on the banks of the River Cabra.',
		),
		'killykeen-forest-park' => array(
			'pt_source'      => 'Penínsulas florestais entre as ilhotas do Lough Oughter, com trilhas, caiaque e pesca.',
			'pt_title'       => 'Killykeen Forest Park',
			'en_description' => 'Wooded peninsulas among the islands of Lough Oughter, with trails, kayaking and fishing.',
		),
		'cavan-burren-park' => array(
			'pt_source'      => 'Paisagem cárstica com megálitos, dolmens e trilhas interpretativas no Geoparque Global Marble Arch Caves.',
			'pt_title'       => 'Cavan Burren Park',
			'en_description' => 'A karst landscape with megaliths, dolmens and interpretive trails in the Marble Arch Caves Global Geopark.',
		),
		'sean-mac-diarmada-cottage' => array(
			'pt_source'      => 'Casa natal de um dos signatários da Proclamação de 1916, preservada como museu no norte de Leitrim.',
			'pt_title'       => 'Seán Mac Diarmada Cottage',
			'en_description' => 'The birthplace of one of the signatories of the 1916 Proclamation, preserved as a museum in north Leitrim.',
		),
		'lough-rynn-castle-gardens' => array(
			'pt_source'      => 'Jardins históricos da propriedade dos Clements, com labirinto, walled garden e vista para o lago.',
			'pt_title'       => 'Lough Rynn Castle Gardens',
			'en_description' => 'Historic gardens of the Clements estate, with a maze, a walled garden and views over the lake.',
		),
		'lough-allen' => array(
			'pt_source'      => 'Lago serrilhado no alto do Shannon, cercado pelas montanhas de Arigna e Sliabh an Iarainn.',
			'pt_title'       => 'Lough Allen',
			'en_description' => 'A jagged lake on the upper Shannon, ringed by the Arigna and Sliabh an Iarainn mountains.',
		),
		'acres-lake-floating-boardwalk' => array(
			'pt_source'      => 'Passarela flutuante de 600 m sobre o Lough Allen, parte do Shannon Blueway, com trilhas e caiaque.',
			'pt_title'       => 'Acres Lake Floating Boardwalk',
			'en_description' => 'A 600 m floating boardwalk over Lough Allen, part of the Shannon Blueway, with trails and kayaking.',
		),
		'parkes-castle' => array(
			'pt_source'      => 'Castelo-forte do século XVII restaurado às margens do Lough Gill, com exposição sobre o plantation.',
			'pt_title'       => 'Parke&#8217;s Castle',
			'en_description' => 'A restored 17th-century fortified castle on the shores of Lough Gill, with an exhibition about the plantation.',
		),
		'glencar-waterfall' => array(
			'pt_source'      => 'Cascata de 15 metros imortalizada por W.B. Yeats, perto de Sligo, com trilha curta e área de piquenique.',
			'pt_title'       => 'Glencar Waterfall',
			'en_description' => 'A 15-metre waterfall immortalised by W.B. Yeats, near Sligo, with a short trail and a picnic area.',
		),
		'roscommon-castle' => array(
			'pt_source'      => 'Castelo normando do século XIII em ruínas evocativas, com parque público e lago ao redor.',
			'pt_title'       => 'Roscommon Castle',
			'en_description' => 'A 13th-century Norman castle in evocative ruins, surrounded by a public park and lake.',
		),
		'lough-key-forest-park' => array(
			'pt_source'      => 'Parque florestal com ilhas, torre de observação, zip line, bungalows e trilhas para todas as idades.',
			'pt_title'       => 'Lough Key Forest &#038; Activity Park',
			'en_description' => 'A forest park with islands, an observation tower, a zip line, bungalows and trails for all ages.',
		),
		'arigna-mining-experience' => array(
			'pt_source'      => 'Tour guiado por mina de carvão real conduzido por ex-mineradores, nas colinas de Arigna.',
			'pt_title'       => 'Arigna Mining Experience',
			'en_description' => 'A guided tour of a real coal mine led by former miners, in the Arigna hills.',
		),
		'strokestown-park-famine-museum' => array(
			'pt_source'      => 'Mansão georgiana com o Museu Nacional da Grande Fome e jardins murados restaurados.',
			'pt_title'       => 'Strokestown Park &#038; National Famine Museum',
			'en_description' => 'A Georgian mansion with the National Famine Museum and restored walled gardens.',
		),
		'boyle-abbey' => array(
			'pt_source'      => 'Abadia cisterciense do século XII com claustro impressionante, no coração de Boyle.',
			'pt_title'       => 'Boyle Abbey',
			'en_description' => 'A 12th-century Cistercian abbey with an impressive cloister, in the heart of Boyle.',
		),
		'rathcroghan' => array(
			'pt_source'      => 'Capital real antiga de Connacht e porta de entrada para o outro mundo na mitologia irlandesa.',
			'pt_title'       => 'Rathcroghan',
			'en_description' => 'The ancient royal capital of Connacht and gateway to the Otherworld in Irish mythology.',
		),
		'lough-ree' => array(
			'pt_source'      => 'Grande lago do rio Shannon com ilhas, pesca, passeios de barco e praias fluviais.',
			'pt_title'       => 'Lough Ree',
			'en_description' => 'A great lake on the River Shannon with islands, fishing, boat trips and riverside beaches.',
		),
		'newcastle-wood' => array(
			'pt_source'      => 'Floresta de coníferas e folhosas com trilhas tranquilas perto de Ballymahon.',
			'pt_title'       => 'Newcastle Wood',
			'en_description' => 'A forest of conifers and broadleaves with peaceful trails near Ballymahon.',
		),
		'royal-canal-greenway' => array(
			'pt_source'      => 'Greenway de 130 km ao longo do Royal Canal, de Dublin a Cloondara — o trecho final fica em Longford.',
			'pt_title'       => 'Royal Canal Greenway',
			'en_description' => 'A 130 km greenway along the Royal Canal, from Dublin to Cloondara — the final stretch is in Longford.',
		),
		'granard-motte-bailey' => array(
			'pt_source'      => 'Motte normanda do século XII sobre morro antigo, com estátua de Santa Kitirine e vistas amplas.',
			'pt_title'       => 'Granard Motte &#038; Bailey',
			'en_description' => 'A 12th-century Norman motte on an ancient hill, with a St Kitirine statue and wide views.',
		),
		'ardagh-heritage-village' => array(
			'pt_source'      => 'Vila vencedora de prêmios de conservação, com verde central pitoresco e centro de interpretação.',
			'pt_title'       => 'Ardagh Heritage Village',
			'en_description' => 'An award-winning heritage village, with a picturesque village green and an interpretive centre.',
		),
		'corlea-trackway' => array(
			'pt_source'      => 'Centro que protege uma estrada de carvalho de 2.000 anos construída sobre a turfeira, única na Europa.',
			'pt_title'       => 'Corlea Trackway Visitor Centre',
			'en_description' => 'A centre protecting a 2,000-year-old oak road built across the bog, the only one of its kind in Europe.',
		),
		'glendeer-pet-farm' => array(
			'pt_source'      => 'Fazenda de animais aberta ao público com cabras, cordeiros, aves e playground coberto.',
			'pt_title'       => 'Glendeer Pet Farm',
			'en_description' => 'An animal farm open to visitors, with goats, lambs, birds and an indoor playground.',
		),
		'mullaghmeen-forest' => array(
			'pt_source'      => 'Grande floresta de folhosas plantadas, com trilhas e vista para o Lough Derravaragh.',
			'pt_title'       => 'Mullaghmeen Forest',
			'en_description' => 'A large planted broadleaf forest, with trails and views over Lough Derravaragh.',
		),
		'kilbeggan-distillery' => array(
			'pt_source'      => 'Destilaria licenciada mais antiga do mundo (1757), com moinho a vapor funcionando e degustações.',
			'pt_title'       => 'Kilbeggan Distillery Experience',
			'en_description' => 'The world\'s oldest licensed distillery (1757), with a working steam mill and tastings.',
		),
		'athlone-castle' => array(
			'pt_source'      => 'Castelo normando do século XII no rio Shannon, com museu interativo sobre o cerco de Athlone.',
			'pt_title'       => 'Athlone Castle Visitor Centre',
			'en_description' => 'A 12th-century Norman castle on the River Shannon, with an interactive museum about the Siege of Athlone.',
		),
		'belvedere-house-gardens' => array(
			'pt_source'      => 'Casa de campo do século XVIII com jardins, folly "Jealous Wall" e eventos familiares o ano inteiro.',
			'pt_title'       => 'Belvedere House Gardens &#038; Park',
			'en_description' => 'An 18th-century country house with gardens, the "Jealous Wall" folly and family events all year round.',
		),
		'tullamore-dew-experience' => array(
			'pt_source'      => 'Centro de visitantes do whisky Tullamore D.E.W. num armazém do século XIX, com degustações guiadas.',
			'pt_title'       => 'Tullamore D.E.W. Experience',
			'en_description' => 'The Tullamore D.E.W. whiskey visitor centre in a 19th-century warehouse, with guided tastings.',
		),
		'charleville-forest-castle' => array(
			'pt_source'      => 'Castelo neogótico do século XIX cercado por floresta antiga de carvalhos, com tours e eventos.',
			'pt_title'       => 'Charleville Forest Castle',
			'en_description' => 'A 19th-century neo-Gothic castle surrounded by ancient oak woodland, with tours and events.',
		),
		'lough-boora-discovery-park' => array(
			'pt_source'      => 'Parque de turfeiras regeneradas com esculturas ao ar livre, trilhas de caminhada e ciclismo e aves raras.',
			'pt_title'       => 'Lough Boora Discovery Park',
			'en_description' => 'A park of regenerated boglands with outdoor sculptures, walking and cycling trails and rare birds.',
		),
		'birr-castle-demesne' => array(
			'pt_source'      => 'Propriedade científica dos Parsons com jardins premiados, o telescópio gigante do século XIX e centro de ciência.',
			'pt_title'       => 'Birr Castle Demesne',
			'en_description' => 'The Parsons\' scientific estate with award-winning gardens, the giant 19th-century telescope and a science centre.',
		),
		'clonmacnoise' => array(
			'pt_source'      => 'Um dos mosteiros mais importantes da Europa medieval, às margens do rio Shannon, com torres redondas e alta cruzes.',
			'pt_title'       => 'Clonmacnoise',
			'en_description' => 'One of medieval Europe\'s most important monasteries, on the banks of the River Shannon, with round towers and high crosses.',
		),
		'slieve-bloom-mountains' => array(
			'pt_source'      => 'Montanhas de turfeira e floresta entre Laois e Offaly, com trilhas, cachoeiras e mirantes panorâmicos.',
			'pt_title'       => 'Slieve Bloom Mountains',
			'en_description' => 'Mountains of bogland and forest between Laois and Offaly, with trails, waterfalls and panoramic viewpoints.',
		),
		'abbeyleix-heritage-house' => array(
			'pt_source'      => 'Centro de patrimônio que conta a história planejada da vila georgiana de Abbeyleix e do tapete local.',
			'pt_title'       => 'Abbeyleix Heritage House',
			'en_description' => 'A heritage centre telling the story of planned Georgian Abbeyleix and of the local carpet industry.',
		),
		'timahoe-round-tower' => array(
			'pt_source'      => 'Torre redonda do século XII considerada uma das mais belas da Irlanda, com portal ricamente decorado.',
			'pt_title'       => 'Timahoe Round Tower',
			'en_description' => 'A 12th-century round tower considered one of Ireland\'s most beautiful, with a richly decorated doorway.',
		),
		'heywood-gardens' => array(
			'pt_source'      => 'Jardins formais dos séculos XVIII/XIX com terraços, lago e o famoso "jardim secreto" circular.',
			'pt_title'       => 'Heywood Gardens',
			'en_description' => 'Formal 18th/19th-century gardens with terraces, a lake and the famous circular "secret garden".',
		),
		'emo-court' => array(
			'pt_source'      => 'Mansão neoclássica do século XVIII com jardins e lago desenhados por Capability Brown; entrada gratuita.',
			'pt_title'       => 'Emo Court',
			'en_description' => 'An 18th-century neoclassical mansion with gardens and a lake designed by Capability Brown; free entry.',
		),
		'rock-of-dunamase' => array(
			'pt_source'      => 'Fortaleza normanda em ruínas sobre afloramento rochoso, com vistas de 360° pelo interior de Laois.',
			'pt_title'       => 'Rock of Dunamase',
			'en_description' => 'A ruined Norman fortress on a rocky outcrop, with 360° views across the Laois countryside.',
		),
		'banna-strand' => array(
			'pt_source'      => 'Praia extensa de areia dourada ao norte de Tralee, com dunas e águas ideais para surf e banho.',
			'pt_title'       => 'Banna Strand',
			'en_description' => 'A long beach of golden sand north of Tralee, with dunes and waters ideal for surfing and swimming.',
		),
		'muckross-abbey' => array(
			'pt_source'      => 'Abadia franciscana do século XV com claustro e um enorme teixo central, no coração do parque nacional.',
			'pt_title'       => 'Muckross Abbey',
			'en_description' => 'A 15th-century Franciscan abbey with a cloister and a huge central yew tree, in the heart of the national park.',
		),
		'gallarus-oratory' => array(
			'pt_source'      => 'Oratório cristão primitivo em pedra seca, com mais de 1.200 anos, em forma de casco de barco invertido.',
			'pt_title'       => 'Gallarus Oratory',
			'en_description' => 'An early Christian dry-stone oratory, over 1,200 years old, shaped like an upturned boat.',
		),
		'derrynane-house' => array(
			'pt_source'      => 'Casa de Daniel O\'Connell, o Libertador, dentro de um parque costeiro com praia e jardins subtropicais.',
			'pt_title'       => 'Derrynane House &#038; National Historic Park',
			'en_description' => 'The house of Daniel O\'Connell, the Liberator, within a coastal park with a beach and subtropical gardens.',
		),
		'kerry-cliffs' => array(
			'pt_source'      => 'Falésias de mais de 300 metros com vista para Skellig Michael, no Anel de Skellig.',
			'pt_title'       => 'Kerry Cliffs',
			'en_description' => 'Cliffs over 300 metres high with views of Skellig Michael, on the Skellig Ring.',
		),
		'valentia-island' => array(
			'pt_source'      => 'Ilha conectada por ponte e ferry, com pegadas de dinossauro, jardins subtropicais e o farol de Cromwell Point.',
			'pt_title'       => 'Valentia Island',
			'en_description' => 'An island linked by bridge and ferry, with dinosaur footprints, subtropical gardens and the Cromwell Point lighthouse.',
		),
		'skellig-michael' => array(
			'pt_source'      => 'Patrimônio Mundial da UNESCO: mosteiro do século VI no topo de ilha rochosa no Atlântico, cenário de Star Wars.',
			'pt_title'       => 'Skellig Michael',
			'en_description' => 'A UNESCO World Heritage Site: a 6th-century monastery atop a rocky island in the Atlantic, a Star Wars filming location.',
		),
		'doneraile-park' => array(
			'pt_source'      => 'Parque paisagístico do século XVIII com cervos, trilhas e playground, entrada gratuita.',
			'pt_title'       => 'Doneraile Park',
			'en_description' => 'An 18th-century landscaped park with deer, trails and a playground — free entry.',
		),
		'spike-island-cork' => array(
			'pt_source'      => 'Ilha-fortaleza no porto de Cork, já a maior prisão do mundo, hoje museu premiado acessível por ferry.',
			'pt_title'       => 'Spike Island',
			'en_description' => 'A fortress island in Cork harbour, once the world\'s largest prison, today an award-winning museum reached by ferry.',
		),
		'garnish-island' => array(
			'pt_source'      => 'Ilha-jardim na baía de Glengarriff, famosa pelos jardins italianos, torre martelo e focas no trajeto de barco.',
			'pt_title'       => 'Garnish Island',
			'en_description' => 'A garden island in Glengarriff harbour, famous for its Italian gardens, Martello tower and the seals seen on the boat trip.',
		),
		'blackrock-castle-observatory' => array(
			'pt_source'      => 'Castelo do século XVI transformado em observatório de ciências astronômicas às margens do rio Lee.',
			'pt_title'       => 'Blackrock Castle Observatory',
			'en_description' => 'A 16th-century castle turned astronomical science observatory on the banks of the River Lee.',
		),
		'cork-city-gaol' => array(
			'pt_source'      => 'Antiga prisão vitoriana com figuras de cera e audioguia sobre dois séculos de vida carcerária.',
			'pt_title'       => 'Cork City Gaol',
			'en_description' => 'A former Victorian prison with wax figures and an audio guide covering two centuries of prison life.',
		),
		'english-market-cork' => array(
			'pt_source'      => 'Mercado coberto histórico do século XVIII, templo da gastronomia de Cork com bancas de peixe, queijos e quitandas.',
			'pt_title'       => 'English Market',
			'en_description' => 'A historic 18th-century covered market, a temple of Cork gastronomy with stalls of fish, cheeses and baked goods.',
		),
		'vandeleur-walled-garden' => array(
			'pt_source'      => 'Jardim murado do século XIX restaurado, com estufa vitoriana e café, no oeste de Clare.',
			'pt_title'       => 'Vandeleur Walled Garden',
			'en_description' => 'A restored 19th-century walled garden, with a Victorian glasshouse and a café, in west Clare.',
		),
		'craggaunowen' => array(
			'pt_source'      => 'Parque de história viva que recria a Irlanda pré-histórica e medieval, com crannóg e casa fortificada.',
			'pt_title'       => 'Craggaunowen',
			'en_description' => 'A living-history park recreating prehistoric and medieval Ireland, with a crannóg and a fortified house.',
		),
		'spanish-point-beach' => array(
			'pt_source'      => 'Baía de areia com águas calmas e história ligada à Armada Espanhola, popular para famílias e surf.',
			'pt_title'       => 'Spanish Point Beach',
			'en_description' => 'A sandy bay with calm waters and history linked to the Spanish Armada, popular with families and surfers.',
		),
		'holy-island-inis-cealtra' => array(
			'pt_source'      => 'Ilha sagrada no Lough Derg com mosteiro do século VII, células de pedra e cruzes antigas.',
			'pt_title'       => 'Holy Island (Inis Cealtra)',
			'en_description' => 'A holy island on Lough Derg with a 7th-century monastery, stone cells and ancient crosses.',
		),
		'scattery-island' => array(
			'pt_source'      => 'Ilha monástica no estuário do Shannon, com torre redonda, ruínas de igrejas e colônia de focas.',
			'pt_title'       => 'Scattery Island',
			'en_description' => 'A monastic island in the Shannon estuary, with a round tower, church ruins and a seal colony.',
		),
		'ennis-friary' => array(
			'pt_source'      => 'Convento franciscano do século XIII com esculturas medievais notáveis, no centro de Ennis.',
			'pt_title'       => 'Ennis Friary',
			'en_description' => 'A 13th-century Franciscan friary with notable medieval carvings, in the centre of Ennis.',
		),
		'thoor-ballylee' => array(
			'pt_source'      => 'Torre normanda do século XIV onde viveu o poeta W.B. Yeats, chamada por ele de "o lugar mais bonito do mundo".',
			'pt_title'       => 'Thoor Ballylee',
			'en_description' => 'A 14th-century Norman tower where the poet W.B. Yeats lived, called by him "the most beautiful place on earth".',
		),
		'portumna-castle-gardens' => array(
			'pt_source'      => 'Mansão fortificada do século XVII às margens do Lough Derg, com jardins restaurados e parque florestal.',
			'pt_title'       => 'Portumna Castle &#038; Gardens',
			'en_description' => 'A 17th-century fortified mansion on the shores of Lough Derg, with restored gardens and a forest park.',
		),
		'coole-park' => array(
			'pt_source'      => 'Reserva natural e antiga propriedade de Lady Gregory, coração do Renascimento Literário irlandês.',
			'pt_title'       => 'Coole Park',
			'en_description' => 'A nature reserve and former Lady Gregory estate, heart of the Irish Literary Revival.',
		),
		'brigits-garden' => array(
			'pt_source'      => 'Jardins inspirados nas festas celtas, com jardim solar, floresta antiga e playground natural.',
			'pt_title'       => 'Brigit&#8217;s Garden',
			'en_description' => 'Gardens inspired by the Celtic festivals, with a sun garden, ancient woodland and a natural playground.',
		),
		'galway-city-museum' => array(
			'pt_source'      => 'Museu moderno sobre a história de Galway, do período medieval à cultura das Ilhas Aran.',
			'pt_title'       => 'Galway City Museum',
			'en_description' => 'A modern museum on the history of Galway, from the medieval period to the culture of the Aran Islands.',
		),
		'spanish-arch-long-walk' => array(
			'pt_source'      => 'Arco do século XVI à beira do rio Corrib e as coloridas casas do Long Walk, cartão-postal de Galway.',
			'pt_title'       => 'Spanish Arch &#038; Long Walk',
			'en_description' => 'A 16th-century arch on the River Corrib and the colourful houses of the Long Walk, a Galway postcard view.',
		),
		'dowth' => array(
			'pt_source'      => 'Terceiro grande túmulo de passagem do Brú na Bóinne, com 5.000 anos, aberto a visitas guiadas.',
			'pt_title'       => 'Dowth Passage Tomb',
			'en_description' => 'The third great passage tomb of Brú na Bóinne, 5,000 years old, open to guided visits.',
		),
		'sonairte-ecology-centre' => array(
			'pt_source'      => 'Centro de ecologia e horta orgânica numa fazenda histórica perto do mar, com trilhas e loja vegetariana.',
			'pt_title'       => 'Sonairte Ecology Centre',
			'en_description' => 'An ecology centre and organic garden on a historic farm near the sea, with trails and a vegetarian café.',
		),
		'kells-historic-town' => array(
			'pt_source'      => 'Vila monástica histórica, antiga casa do Livro de Kells, com alta cruzes e torre redonda.',
			'pt_title'       => 'Kells Historic Town',
			'en_description' => 'A historic monastic town, former home of the Book of Kells, with high crosses and a round tower.',
		),
		'bective-abbey' => array(
			'pt_source'      => 'Ruínas de abadia cisterciense do século XII às margens do rio Boyne, cenário do filme Braveheart.',
			'pt_title'       => 'Bective Abbey',
			'en_description' => 'Ruins of a 12th-century Cistercian abbey on the banks of the River Boyne, a filming location for Braveheart.',
		),
		'emerald-park' => array(
			'pt_source'      => 'Parque temático e zoológico (antigo Tayto Park) com montanhas-russas, zoo e atrações para toda a família.',
			'pt_title'       => 'Emerald Park',
			'en_description' => 'A theme park and zoo (formerly Tayto Park) with roller coasters, a zoo and attractions for the whole family.',
		),
		'wicklow-way' => array(
			'pt_source'      => 'A trilha de longa distância mais antiga da Irlanda: 131 km pelas montanhas de Wicklow, de Dublin a Clonegal.',
			'pt_title'       => 'The Wicklow Way',
			'en_description' => 'Ireland\'s oldest long-distance trail: 131 km through the Wicklow Mountains, from Dublin to Clonegal.',
		),
		'mount-usher-gardens' => array(
			'pt_source'      => 'Jardins românticos às margens do rio Vartry, com plantas de todo o mundo e estilo de jardim selvagem.',
			'pt_title'       => 'Mount Usher Gardens',
			'en_description' => 'Romantic gardens on the banks of the River Vartry, with plants from all over the world and a wild-garden style.',
		),
		'killruddery-house-gardens' => array(
			'pt_source'      => 'Mansão do século XVII ainda habitada, com jardins formais, orangerie e mercado de produtores aos sábados.',
			'pt_title'       => 'Killruddery House &#038; Gardens',
			'en_description' => 'A 17th-century mansion still lived in, with formal gardens, an orangery and a farmers\' market on Saturdays.',
		),
		'brittas-bay-beach' => array(
			'pt_source'      => 'Uma das melhores praias da costa leste, com 4 km de areia dourada e dunas protegidas.',
			'pt_title'       => 'Brittas Bay Beach',
			'en_description' => 'One of the east coast\'s finest beaches, with 4 km of golden sand and protected dunes.',
		),
		'wicklow-historic-gaol' => array(
			'pt_source'      => 'Prisão histórica do século XVIII transformada em museu interativo sobre dois séculos de história sombria.',
			'pt_title'       => 'Wicklow&#8217;s Historic Gaol',
			'en_description' => 'An 18th-century prison turned interactive museum on two centuries of dark history.',
		),
		'russborough-house' => array(
			'pt_source'      => 'Mansão palladiana do século XVIII com interior ornamentado, labirinto e vista para as montanhas de Wicklow.',
			'pt_title'       => 'Russborough House',
			'en_description' => 'An 18th-century Palladian mansion with an ornate interior, a maze and views of the Wicklow Mountains.',
		),
		'portmarnock-beach' => array(
			'pt_source'      => 'Longa praia de areia ao norte de Dublin, popular para caminhadas, banhos e esportes aquáticos.',
			'pt_title'       => 'Portmarnock Beach (Velvet Strand)',
			'en_description' => 'A long sandy beach north of Dublin, popular for walks, swimming and water sports.',
		),
		'dun-laoghaire-east-pier' => array(
			'pt_source'      => 'Calçadão marítimo de 1,3 km com vistas da baía de Dublin, ideal para caminhadas à beira-mar.',
			'pt_title'       => 'Dún Laoghaire East Pier',
			'en_description' => 'A 1.3 km seafront promenade with views of Dublin Bay, ideal for a walk by the sea.',
		),
		'glasnevin-cemetery-museum' => array(
			'pt_source'      => 'Cemitério histórico onde repousam os grandes nomes da independência irlandesa, com museu e visitas guiadas.',
			'pt_title'       => 'Glasnevin Cemetery Museum',
			'en_description' => 'A historic cemetery where the great names of Irish independence rest, with a museum and guided tours.',
		),
		'national-botanic-gardens-dublin' => array(
			'pt_source'      => 'Jardins botânicos nacionais com estufas vitorianas, mais de 15 mil espécies de plantas e entrada gratuita.',
			'pt_title'       => 'National Botanic Gardens',
			'en_description' => 'The national botanic gardens with Victorian glasshouses, more than 15,000 plant species and free entry.',
		),
		'epic-irish-emigration-museum' => array(
			'pt_source'      => 'Museu interativo premiado que conta a história dos 10 milhões de irlandeses que emigraram pelo mundo.',
			'pt_title'       => 'EPIC The Irish Emigration Museum',
			'en_description' => 'An award-winning interactive museum telling the story of the 10 million Irish who emigrated around the world.',
		),
		'national-museum-archaeology' => array(
			'pt_source'      => 'Museu nacional com tesouros da Irlanda antiga: artefatos vikings, ouro da Idade do Bronze e corpos de pântano.',
			'pt_title'       => 'National Museum of Ireland – Archaeology',
			'en_description' => 'The national museum with treasures of ancient Ireland: Viking artefacts, Bronze Age gold and bog bodies.',
		),
		'inch-beach' => array(
			'pt_source'      => 'Longa praia de areia na península de Dingle, popular para surf e caminhadas.',
			'pt_title'       => 'Inch Beach',
			'en_description' => 'A long sandy beach on the Dingle Peninsula, popular for surfing and walks.',
		),
		'dingle-peninsula' => array(
			'pt_source'      => 'Península gaélica com praias selvagens, Slea Head e o centro urbano de Dingle.',
			'pt_title'       => 'Dingle Peninsula',
			'en_description' => 'A Gaelic-speaking peninsula with wild beaches, Slea Head and the town of Dingle.',
		),
		'ring-of-kerry' => array(
			'pt_source'      => 'Rota cênica circular de 179 km pela península de Iveragh, com vistas costeiras espetaculares.',
			'pt_title'       => 'Ring of Kerry',
			'en_description' => 'A 179 km scenic circular route around the Iveragh Peninsula, with spectacular coastal views.',
		),
		'gap-of-dunloe' => array(
			'pt_source'      => 'Desfiladeiro glaciar entre montanhas, percorrido por carruagens e trilhas.',
			'pt_title'       => 'Gap of Dunloe',
			'en_description' => 'A glacial gorge between mountains, explored by horse-drawn carriages and on foot.',
		),
		'torc-waterfall' => array(
			'pt_source'      => 'Cascata de 20 metros rodeada de floresta, nas encostas do Monte Torc.',
			'pt_title'       => 'Torc Waterfall',
			'en_description' => 'A 20-metre waterfall surrounded by woodland, on the slopes of Mount Torc.',
		),
		'ross-castle' => array(
			'pt_source'      => 'Castelo do século XV às margens do Lago de Leane, com passeios de barco.',
			'pt_title'       => 'Ross Castle',
			'en_description' => 'A 15th-century castle on the shores of Lough Leane, with boat trips.',
		),
		'muckross-house' => array(
			'pt_source'      => 'Mansão vitoriana no coração do parque, com jardins e quintas tradicionais.',
			'pt_title'       => 'Muckross House',
			'en_description' => 'A Victorian mansion in the heart of the park, with gardens and traditional farms.',
		),
		'killarney-national-park' => array(
			'pt_source'      => 'Primeiro parque nacional da Irlanda, com lagos, montanhas e o castelo de Ross.',
			'pt_title'       => 'Killarney National Park',
			'en_description' => 'Ireland\'s first national park, with lakes, mountains and Ross Castle.',
		),
		'mizen-head' => array(
			'pt_source'      => 'Ponto mais a sudoeste da Irlanda, com ponte sobre o desfiladeiro, sinal marítimo e vistas do Atlântico.',
			'pt_title'       => 'Mizen Head',
			'en_description' => 'Ireland\'s most south-westerly point, with a bridge over the gorge, a maritime signal station and Atlantic views.',
		),
		'charles-fort' => array(
			'pt_source'      => 'Forte em estrela do século XVII na entrada do porto de Kinsale.',
			'pt_title'       => 'Charles Fort',
			'en_description' => 'A 17th-century star fort at the entrance to Kinsale harbour.',
		),
		'kinsale' => array(
			'pt_source'      => 'Vila costeira pitoresca com ruas coloridas, gastronomia premiada e uma história marítima rica.',
			'pt_title'       => 'Kinsale',
			'en_description' => 'A picturesque coastal town with colourful streets, award-winning food and a rich maritime history.',
		),
		'jameson-distillery-midleton' => array(
			'pt_source'      => 'Destilaria histórica onde se produz o whisky Jameson, com visitas guiadas e degustações.',
			'pt_title'       => 'Jameson Distillery Midleton',
			'en_description' => 'A historic distillery where Jameson whiskey is produced, with guided tours and tastings.',
		),
		'fota-wildlife-park' => array(
			'pt_source'      => 'Parque de vida selvagem na ilha de Fota, com animais em recintos abertos e um castelo.',
			'pt_title'       => 'Fota Wildlife Park',
			'en_description' => 'A wildlife park on Fota Island, with animals in open enclosures and a castle.',
		),
		'titanic-experience-cobh' => array(
			'pt_source'      => 'Museu interativo que recria a história do Titanic e da sua ligação com Cobh.',
			'pt_title'       => 'Titanic Experience Cobh',
			'en_description' => 'An interactive museum recreating the story of the Titanic and its link with Cobh.',
		),
		'cobh' => array(
			'pt_source'      => 'Cidade portuária histórica, última paragem do Titanic e porto de partida de milhões de emigrantes.',
			'pt_title'       => 'Cobh',
			'en_description' => 'A historic port town, the Titanic\'s last port of call and the departure point of millions of emigrants.',
		),
		'blarney-castle' => array(
			'pt_source'      => 'Castelo medieval famoso pela Pedra de Blarney, cujo beijo concede "o dom da eloquência".',
			'pt_title'       => 'Blarney Castle',
			'en_description' => 'A medieval castle famous for the Blarney Stone, whose kiss grants "the gift of eloquence".',
		),
		'kilkee-cliffs' => array(
			'pt_source'      => 'Passarela costeira ao longo de falésias com formações rochosas características.',
			'pt_title'       => 'Kilkee Cliffs',
			'en_description' => 'A coastal walkway along cliffs with distinctive rock formations.',
		),
		'lahinch-beach' => array(
			'pt_source'      => 'Praia de areia popular para surf e banhos de mar na costa oeste da Irlanda.',
			'pt_title'       => 'Lahinch Beach',
			'en_description' => 'A sandy beach popular for surfing and sea swimming on Ireland\'s west coast.',
		),
		'loop-head' => array(
			'pt_source'      => 'Península com falésias e um farol clássico na entrada do estuário do Shannon.',
			'pt_title'       => 'Loop Head',
			'en_description' => 'A peninsula with cliffs and a classic lighthouse at the mouth of the Shannon estuary.',
		),
		'doolin-cave' => array(
			'pt_source'      => 'Caverna com um dos maiores estalactites pendentes do mundo.',
			'pt_title'       => 'Doolin Cave',
			'en_description' => 'A cave with one of the largest free-hanging stalactites in the world.',
		),
		'aillwee-caves' => array(
			'pt_source'      => 'Sistema de cavernas no Burren com formações de pedra e uma cascata subterrânea.',
			'pt_title'       => 'Aillwee Caves',
			'en_description' => 'A cave system in the Burren with stone formations and an underground waterfall.',
		),
		'bunratty-castle-folk-park' => array(
			'pt_source'      => 'Castelo do século XV restaurado com um parque folclórico que recria a vida rural irlandesa.',
			'pt_title'       => 'Bunratty Castle &amp; Folk Park',
			'en_description' => 'A restored 15th-century castle with a folk park recreating rural Irish life.',
		),
		'burren-national-park' => array(
			'pt_source'      => 'Paisagem cárstica única de calcário com flora rara e trilhas no Condado de Clare.',
			'pt_title'       => 'Burren National Park',
			'en_description' => 'A unique limestone karst landscape with rare flora and trails in County Clare.',
		),
		'cliffs-of-moher' => array(
			'pt_source'      => 'Falésias de 214 metros sobre o Oceano Atlântico, uma das atrações naturais mais famosas da Irlanda.',
			'pt_title'       => 'Cliffs of Moher',
			'en_description' => 'Cliffs 214 metres above the Atlantic Ocean, one of Ireland\'s most famous natural attractions.',
		),
		'galway-atlantaquaria' => array(
			'pt_source'      => 'Aquário nacional da Irlanda, em Salthill, com vida marinha local e tanques interativos.',
			'pt_title'       => 'Galway Atlantaquaria',
			'en_description' => 'Ireland\'s national aquarium, in Salthill, with local marine life and interactive tanks.',
		),
		'diamond-hill' => array(
			'pt_source'      => 'Trilha em Connemara com vistas panorâmicas da baía de Ballynakill e das Twelve Bens.',
			'pt_title'       => 'Diamond Hill',
			'en_description' => 'A trail in Connemara with panoramic views of Ballynakill Bay and the Twelve Bens.',
		),
		'dun-aonghasa' => array(
			'pt_source'      => 'Forte de pedra pré-histórico na borda de um penhasco de 100 m na ilha de Inishmore.',
			'pt_title'       => 'Dún Aonghasa',
			'en_description' => 'A prehistoric stone fort on the edge of a 100 m cliff on the island of Inishmore.',
		),
		'aran-islands' => array(
			'pt_source'      => 'Ilhas gaélicas na foz da Baía de Galway, famosas pelas fortalezas de pedra e cultura tradicional.',
			'pt_title'       => 'Aran Islands',
			'en_description' => 'Gaelic islands at the mouth of Galway Bay, famous for their stone forts and traditional culture.',
		),
		'sky-road' => array(
			'pt_source'      => 'Rota costeira cênica ao redor da península de Kingstown, com vistas de Clifden e da baía.',
			'pt_title'       => 'Sky Road',
			'en_description' => 'A scenic coastal route around the Kingstown peninsula, with views of Clifden and the bay.',
		),
		'salthill' => array(
			'pt_source'      => 'Promenade litorânea de Galway, com praia, piscina de mar e vista para a baía.',
			'pt_title'       => 'Salthill',
			'en_description' => 'Galway\'s seaside promenade, with a beach, a sea pool and views over the bay.',
		),
		'connemara-national-park' => array(
			'pt_source'      => 'Parque nacional de montanhas, turfeiras e pôneis selvagens, com trilhas até o Diamond Hill.',
			'pt_title'       => 'Connemara National Park',
			'en_description' => 'A national park of mountains, bogs and wild ponies, with trails up Diamond Hill.',
		),
		'kylemore-abbey' => array(
			'pt_source'      => 'Mosteiro e castelo do século XIX num cenário lacustre deslumbrante em Connemara.',
			'pt_title'       => 'Kylemore Abbey',
			'en_description' => 'A 19th-century abbey and castle in a stunning lakeside setting in Connemara.',
		),
		'battle-of-the-boyne-visitor-centre' => array(
			'pt_source'      => 'Centro de visitantes que conta a história da Batalha do Boyne de 1690, na casa de Oldbridge.',
			'pt_title'       => 'Battle of the Boyne Visitor Centre',
			'en_description' => 'A visitor centre telling the story of the 1690 Battle of the Boyne, at Oldbridge House.',
		),
		'loughcrew' => array(
			'pt_source'      => 'Conjunto de túmulos de passagem neolíticos nas colinas de Loughcrew, com arte rupestre.',
			'pt_title'       => 'Loughcrew',
			'en_description' => 'A cluster of Neolithic passage tombs in the Loughcrew hills, with rock art.',
		),
		'slane-castle' => array(
			'pt_source'      => 'Castelo histórico às margens do rio Boyne, conhecido pelos seus concertos e destilaria.',
			'pt_title'       => 'Slane Castle',
			'en_description' => 'A historic castle on the banks of the River Boyne, known for its concerts and distillery.',
		),
		'trim-castle' => array(
			'pt_source'      => 'O maior castelo anglo-normando da Irlanda, famoso por ter sido locação do filme "Braveheart".',
			'pt_title'       => 'Trim Castle',
			'en_description' => 'Ireland\'s largest Anglo-Norman castle, famous as a filming location for "Braveheart".',
		),
		'hill-of-tara' => array(
			'pt_source'      => 'Antiga sede dos Reis Supremos da Irlanda, com monumentos e vistas panorâmicas.',
			'pt_title'       => 'Hill of Tara',
			'en_description' => 'The ancient seat of the High Kings of Ireland, with monuments and panoramic views.',
		),
		'knowth' => array(
			'pt_source'      => 'Grande túmulo de passagem neolítico com a maior coleção de arte megalítica da Europa ocidental.',
			'pt_title'       => 'Knowth',
			'en_description' => 'A great Neolithic passage tomb with the largest collection of megalithic art in western Europe.',
		),
		'newgrange' => array(
			'pt_source'      => 'Túmulo de passagem neolítico com mais de 5.000 anos, mais antigo que Stonehenge e as pirâmides.',
			'pt_title'       => 'Newgrange',
			'en_description' => 'A Neolithic passage tomb over 5,000 years old, older than Stonehenge and the pyramids.',
		),
		'avondale-forest-park' => array(
			'pt_source'      => 'Parque florestal com trilhas, passeios de copa de árvores e história ligada a Charles Stewart Parnell.',
			'pt_title'       => 'Avondale Forest Park',
			'en_description' => 'A forest park with trails, treetop walks and history linked to Charles Stewart Parnell.',
		),
		'sally-gap' => array(
			'pt_source'      => 'Passo de montanha cênico nas montanhas de Wicklow, rodeado de turfeiras e berços dos rios Liffey e Dargle.',
			'pt_title'       => 'Sally Gap',
			'en_description' => 'A scenic mountain pass in the Wicklow Mountains, surrounded by bogs where the rivers Liffey and Dargle rise.',
		),
		'great-sugar-loaf' => array(
			'pt_source'      => 'Pico de montanha em forma de cúpula com vistas panorâmicas das montanhas de Wicklow e da costa.',
			'pt_title'       => 'Great Sugar Loaf',
			'en_description' => 'A dome-shaped mountain peak with panoramic views of the Wicklow Mountains and the coast.',
		),
		'bray-head' => array(
			'pt_source'      => 'Trilha costeira com uma grande cruz no topo e vistas da baía de Bray e das montanhas de Wicklow.',
			'pt_title'       => 'Bray Head',
			'en_description' => 'A coastal trail with a large cross at the summit and views of Bray Bay and the Wicklow Mountains.',
		),
		'powerscourt-waterfall' => array(
			'pt_source'      => 'A cachoeira mais alta da Irlanda, com 121 metros, cercada por trilhas na floresta.',
			'pt_title'       => 'Powerscourt Waterfall',
			'en_description' => 'Ireland\'s highest waterfall, at 121 metres, surrounded by forest trails.',
		),
		'powerscourt-estate' => array(
			'pt_source'      => 'Mansão histórica com jardins premiados e o Terraço da Cachoeira; também há um centro de compras e café.',
			'pt_title'       => 'Powerscourt Estate',
			'en_description' => 'A historic mansion with award-winning gardens and the Waterfall Terrace; there is also a shopping centre and café.',
		),
		'glendalough' => array(
			'pt_source'      => 'Assentamento monástico do século VI num vale glacial com lagos e trilhas.',
			'pt_title'       => 'Glendalough',
			'en_description' => 'A 6th-century monastic settlement in a glacial valley with lakes and trails.',
		),
		'wicklow-mountains-national-park' => array(
			'pt_source'      => 'Parque nacional de montanhas, lagos e vales glaciais, conhecido como o "Jardim da Irlanda".',
			'pt_title'       => 'Wicklow Mountains National Park',
			'en_description' => 'A national park of mountains, lakes and glacial valleys — known as the "Garden of Ireland".',
		),
		'malahide-castle-gardens' => array(
			'pt_source'      => 'Castelo de 800 anos com jardins exuberantes, localizado na vila litorânea de Malahide.',
			'pt_title'       => 'Malahide Castle &amp; Gardens',
			'en_description' => 'An 800-year-old castle with lush gardens, located in the coastal village of Malahide.',
		),
		'howth-cliff-walk' => array(
			'pt_source'      => 'Trilha costeira espetacular ao redor da península de Howth, com vistas do mar da Irlanda.',
			'pt_title'       => 'Howth Cliff Walk',
			'en_description' => 'A spectacular coastal trail around the Howth Peninsula, with views of the Irish Sea.',
		),
		'dublin-zoo' => array(
			'pt_source'      => 'Zoológico no Phoenix Park com animais de todo o mundo e programas de conservação.',
			'pt_title'       => 'Dublin Zoo',
			'en_description' => 'A zoo in Phoenix Park with animals from around the world and conservation programmes.',
		),
		'phoenix-park' => array(
			'pt_source'      => 'Um dos maiores parques urbanos fechados da Europa, lar de veados selvagens, a residência presidencial e o Dublin Zoo.',
			'pt_title'       => 'Phoenix Park',
			'en_description' => 'One of Europe\'s largest enclosed city parks, home to wild deer, the presidential residence and Dublin Zoo.',
		),
		'guinness-storehouse' => array(
			'pt_source'      => 'A famosa fábrica e museu da cerveja Guinness, com o bar panorâmico Gravity no topo.',
			'pt_title'       => 'Guinness Storehouse',
			'en_description' => 'The famous Guinness brewery and museum, with the panoramic Gravity Bar at the top.',
		),
		'kilmainham-gaol' => array(
			'pt_source'      => 'Prisão histórica que desempenhou papel central na luta pela independência irlandesa. Museu imperdível.',
			'pt_title'       => 'Kilmainham Gaol',
			'en_description' => 'A historic prison that played a central role in the struggle for Irish independence. An unmissable museum.',
		),
		'dublin-castle' => array(
			'pt_source'      => 'Castelo histórico no coração de Dublin, sede do governo britânico na Irlanda por séculos e agora um complexo de museus e salões de estado.',
			'pt_title'       => 'Dublin Castle',
			'en_description' => 'A historic castle in the heart of Dublin, seat of the British government in Ireland for centuries and now a complex of museums and state halls.',
		),
		'trinity-college-book-of-kells' => array(
			'pt_source'      => 'A icônica universidade irlandesa e o famoso Livro de Kells, um dos mais importantes manuscritos medievais do mundo.',
			'pt_title'       => 'Trinity College &amp; Book of Kells',
			'en_description' => 'Ireland\'s iconic university and the famous Book of Kells, one of the most important medieval manuscripts in the world.',
		),
	);
}
