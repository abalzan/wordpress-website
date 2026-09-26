<?php
/**
 * Stage 3.2 — LOCAL-ONLY pilot catalogue seeder.
 *
 * Rebuilds a small, production-shaped Portuguese catalogue in a fresh local
 * environment so the Stage 3.2 bilingual work has realistic data to run
 * against. The production dataset itself never ships in the repository; this
 * script creates representative records through the same APIs and meta keys
 * the production import/migration tooling uses.
 *
 * Shapes created (all Portuguese, all idempotent by slug / identity key):
 *   - conexao_town terms (event town taxonomy)
 *   - extra conexao_category terms used by the pilot records
 *   - 6 guides, 4 blog posts
 *   - 10 events with FULL identity meta (_event_source, _event_source_id,
 *     _event_export_uuid, dates, _event_status) covering: published upcoming,
 *     legacy no-status (manually created), expired, rejected,
 *     source_not_found, and one English-language source event
 *   - 8 leisure records with _leisure_export_uuid (5 internal pages,
 *     3 externally classified)
 *   - 4 sponsors with _sponsor_export_uuid
 *   - 3 jobs
 *
 * Course providers are seeded separately by scripts/seed-course-providers.php.
 *
 * Usage (local only, NEVER production):
 *   wp eval-file scripts/stage32-seed-pilot.php
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	$dir = __DIR__;
	while ( $dir !== dirname( $dir ) ) {
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			require_once $dir . '/wp-load.php';
			break;
		}
		$dir = dirname( $dir );
	}
}
if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress bootstrap failed\n" );
	exit( 1 );
}

$stage32_stats = array( 'created' => 0, 'existing' => 0 );

/**
 * Find a post by post type + slug.
 */
function stage32_find( $post_type, $slug ) {
	$posts = get_posts(
		array(
			'post_type'      => $post_type,
			'name'           => $slug,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	return $posts ? (int) $posts[0] : 0;
}

/**
 * Insert a post when missing; assign terms; set meta. Idempotent.
 */
function stage32_insert( $post_type, $slug, $title, $content, $meta = array(), $terms = array(), $status = 'publish', $extra = array() ) {
	global $stage32_stats;

	$existing = stage32_find( $post_type, $slug );
	if ( $existing ) {
		$stage32_stats['existing']++;
		return $existing;
	}

	$postarr = array_merge(
		array(
			'post_type'    => $post_type,
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $status,
		),
		$extra
	);

	$post_id = wp_insert_post( $postarr, true );
	if ( is_wp_error( $post_id ) ) {
		echo "  ERROR creating {$post_type}/{$slug}: " . $post_id->get_error_message() . "\n";
		return 0;
	}

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
	foreach ( $terms as $taxonomy => $term_slugs ) {
		wp_set_object_terms( $post_id, $term_slugs, $taxonomy );
	}

	$stage32_stats['created']++;
	echo "  + {$post_type}/{$slug} (#{$post_id})\n";
	return $post_id;
}

/**
 * Resolve or create a term, returning its slug.
 */
function stage32_term( $name, $slug, $taxonomy ) {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( $term && ! is_wp_error( $term ) ) {
		return $term->slug;
	}
	$created = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
	if ( is_wp_error( $created ) ) {
		echo "  ERROR creating term {$taxonomy}/{$slug}: " . $created->get_error_message() . "\n";
		return '';
	}
	echo "  + term {$taxonomy}/{$slug}\n";
	return $slug;
}

echo "== Stage 3.2 pilot catalogue (PT) ==\n";

echo "\n-- Towns --\n";
$stage32_towns = array(
	'Dublin'    => 'dublin',
	'Cork'      => 'cork',
	'Galway'    => 'galway',
	'Limerick'  => 'limerick',
	'Bray'      => 'bray',
	'Kilkenny'  => 'kilkenny',
	'Killarney' => 'killarney',
	'Drogheda'  => 'drogheda',
);
foreach ( $stage32_towns as $name => $slug ) {
	stage32_term( $name, $slug, 'conexao_town' );
}

echo "\n-- Categories --\n";
$stage32_categories = array(
	'Música'      => 'musica',
	'Festivais'   => 'festivais',
	'Gastronomia' => 'gastronomia',
	'Esportes'    => 'esportes',
);
foreach ( $stage32_categories as $name => $slug ) {
	stage32_term( $name, $slug, 'conexao_category' );
}

echo "\n-- Guides --\n";
$stage32_guides = array(
	array(
		'slug'    => 'como-tirar-o-pps-number',
		'title'   => 'Como tirar o PPS Number',
		'content' => '<!-- wp:paragraph --><p>O PPS Number é o número de identificação social na Irlanda. Ele é necessário para trabalhar, abrir conta bancária e acessar serviços públicos.</p><!-- /wp:paragraph -->\n<!-- wp:heading --><h2>Documentos necessários</h2><!-- /wp:heading -->\n<!-- wp:paragraph --><p>Passaporte, comprovante de residência e o motivo da solicitação. O pedido é feito no MyWelfare ou no Intreo Centre da sua região.</p><!-- /wp:paragraph -->',
		'terms'   => array( 'conexao_category' => array( 'documentos', 'trabalho' ) ),
	),
	array(
		'slug'    => 'como-abrir-conta-bancaria-na-irlanda',
		'title'   => 'Como abrir conta bancária na Irlanda',
		'content' => '<!-- wp:paragraph --><p>Para abrir uma conta bancária na Irlanda você precisa de um documento de identidade com foto e um comprovante de endereço irlandês.</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p>Bancos tradicionais como AIB e Bank of Ireland e bancos digitais como Revolut são opções comuns na comunidade.</p><!-- /wp:paragraph -->',
		'terms'   => array( 'conexao_category' => array( 'financas', 'documentos' ) ),
	),
	array(
		'slug'    => 'registro-de-imigracao-irp',
		'title'   => 'Registro de imigração (IRP): passo a passo',
		'content' => '<!-- wp:paragraph --><p>O Irish Residence Permit (IRP) é o cartão de registro de imigração para quem permanece na Irlanda por mais de 90 dias.</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p>Em Dublin o registro é feito no Burgh Quay; fora de Dublin, na estação de imigração local (GNIB).</p><!-- /wp:paragraph -->',
		'terms'   => array( 'conexao_category' => array( 'documentos' ) ),
	),
	array(
		'slug'    => 'como-alugar-imovel-na-irlanda',
		'title'   => 'Moradia: como alugar um imóvel na Irlanda',
		'content' => '<!-- wp:paragraph --><p>O mercado de aluguel na Irlanda é competitivo. Sites como Daft.ie e Rent.ie concentram a maioria dos anúncios.</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p>Prepare referências, comprovante de renda e o depósito (normalmente um mês de aluguel).</p><!-- /wp:paragraph -->',
		'terms'   => array( 'conexao_category' => array( 'moradia' ) ),
	),
	array(
		'slug'    => 'saude-na-irlanda-gp-e-hse',
		'title'   => 'Saúde na Irlanda: GP, HSE e seguro saúde',
		'content' => '<!-- wp:paragraph --><p>O sistema público de saúde irlandês é administrado pelo HSE. O primeiro contato é o GP (general practitioner), o clínico geral.</p><!-- /wp:paragraph -->',
		'terms'   => array( 'conexao_category' => array( 'saude' ) ),
	),
	array(
		'slug'    => 'custo-de-vida-na-irlanda',
		'title'   => 'Custo de vida na Irlanda em 2026',
		'content' => '<!-- wp:paragraph --><p>Dublin concentra os maiores custos, principalmente moradia. Cidades como Cork, Galway e Limerick oferecem aluguéis mais acessíveis.</p><!-- /wp:paragraph -->',
		'terms'   => array( 'conexao_category' => array( 'financas', 'moradia' ) ),
	),
);
foreach ( $stage32_guides as $guide ) {
	stage32_insert( 'guide', $guide['slug'], $guide['title'], $guide['content'], array(), $guide['terms'] );
}

echo "\n-- Blog posts --\n";
stage32_term( 'Notícias', 'noticias', 'category' );
stage32_term( 'Comunidade', 'comunidade', 'category' );
stage32_term( 'Dicas', 'dicas', 'category' );

$stage32_posts = array(
	array(
		'slug'  => 'comunidade-celebra-festa-junina-em-dublin',
		'title' => 'Comunidade celebra Festa Junina em Dublin',
		'terms' => array( 'category' => array( 'comunidade' ) ),
	),
	array(
		'slug'  => 'mudancas-no-registro-de-imigracao-2026',
		'title' => 'Mudanças no registro de imigração em 2026',
		'terms' => array( 'category' => array( 'noticias' ) ),
	),
	array(
		'slug'  => '5-dicas-para-o-primeiro-inverno-irlandes',
		'title' => '5 dicas para o primeiro inverno irlandês',
		'terms' => array( 'category' => array( 'dicas' ) ),
	),
	array(
		'slug'  => 'feira-de-empregos-brasileiros-cork',
		'title' => 'Feira de empregos para brasileiros em Cork',
		'terms' => array( 'category' => array( 'noticias', 'comunidade' ) ),
	),
);
foreach ( $stage32_posts as $post ) {
	stage32_insert(
		'post',
		$post['slug'],
		$post['title'],
		'<!-- wp:paragraph --><p>' . $post['title'] . ' — acompanhe os detalhes no Conexão BR Irlanda.</p><!-- /wp:paragraph -->',
		array(),
		$post['terms']
	);
}

echo "\n-- Events --\n";

$stage32_future_1 = gmdate( 'Y-m-d', strtotime( '+12 days' ) );
$stage32_future_2 = gmdate( 'Y-m-d', strtotime( '+20 days' ) );
$stage32_future_3 = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
$stage32_past_1   = gmdate( 'Y-m-d', strtotime( '-40 days' ) );

$stage32_events = array(
	array(
		'slug'      => 'festa-junina-dublin-2026',
		'title'     => 'Festa Junina de Dublin 2026',
		'content'   => '<!-- wp:paragraph --><p>A tradicional Festa Junina da comunidade brasileira em Dublin, com comidas típicas, quadrilha e música ao vivo.</p><!-- /wp:paragraph -->',
		'source'    => 'eventbrite',
		'source_id' => 'pilot-eb-0001',
		'date'      => $stage32_future_1,
		'status'    => 'published',
		'terms'     => array( 'conexao_town' => array( 'dublin' ), 'conexao_county' => array( 'dublin' ), 'conexao_category' => array( 'festivais', 'musica' ) ),
		'venue'     => 'The Grand Social, Dublin',
	),
	array(
		'slug'      => 'feijoada-beneficente-cork',
		'title'     => 'Feijoada Beneficente em Cork',
		'content'   => '<!-- wp:paragraph --><p>Feijoada beneficente organizada por voluntários brasileiros em Cork. Renda revertida para ações comunitárias.</p><!-- /wp:paragraph -->',
		'source'    => 'eventbrite',
		'source_id' => 'pilot-eb-0002',
		'date'      => $stage32_future_2,
		'status'    => 'published',
		'terms'     => array( 'conexao_town' => array( 'cork' ), 'conexao_county' => array( 'cork' ), 'conexao_category' => array( 'gastronomia' ) ),
		'venue'     => 'The Friary, Cork',
	),
	array(
		'slug'      => 'brazilian-day-galway-2026',
		'title'     => 'Brazilian Day Galway 2026',
		'content'   => '<!-- wp:paragraph --><p>Celebração da cultura brasileira em Galway com shows, dança e gastronomia.</p><!-- /wp:paragraph -->',
		'source'    => 'eventbrite',
		'source_id' => 'pilot-eb-0003',
		'date'      => $stage32_future_3,
		'status'    => 'published',
		'terms'     => array( 'conexao_town' => array( 'galway' ), 'conexao_county' => array( 'galway' ), 'conexao_category' => array( 'festivais', 'cultura' ) ),
		'venue'     => 'Eyre Square, Galway',
	),
	array(
		'slug'      => 'workshop-de-curriculo-dublin',
		'title'     => 'Workshop de Currículo e Entrevistas',
		'content'   => '<!-- wp:paragraph --><p>Workshop gratuito sobre currículo no formato irlandês e preparação para entrevistas.</p><!-- /wp:paragraph -->',
		'source'    => 'eventbrite',
		'source_id' => 'pilot-eb-0004',
		'date'      => $stage32_future_1,
		'status'    => 'published',
		'terms'     => array( 'conexao_town' => array( 'dublin' ), 'conexao_county' => array( 'dublin' ), 'conexao_category' => array( 'empregos', 'treinamento' ) ),
		'venue'     => 'Central Library, Dublin',
	),
	array(
		'slug'      => 'noite-de-mpb-bray',
		'title'     => 'Noite de MPB em Bray',
		'content'   => '<!-- wp:paragraph --><p>Show de MPB com músicos brasileiros residentes em Bray.</p><!-- /wp:paragraph -->',
		'source'    => 'eventbrite',
		'source_id' => 'pilot-eb-0005',
		'date'      => $stage32_past_1,
		'status'    => 'expired',
		'terms'     => array( 'conexao_town' => array( 'bray' ), 'conexao_county' => array( 'wicklow' ), 'conexao_category' => array( 'musica' ) ),
		'venue'     => 'The Harbour Bar, Bray',
	),
	array(
		'slug'      => 'evento-rejeitado-spam',
		'title'     => '[Rejected] Promoção enganosa',
		'content'   => '<!-- wp:paragraph --><p>Registro rejeitado pela curadoria.</p><!-- /wp:paragraph -->',
		'source'    => 'eventbrite',
		'source_id' => 'pilot-eb-0006',
		'date'      => $stage32_future_2,
		'status'    => 'rejected',
		'terms'     => array( 'conexao_town' => array( 'dublin' ), 'conexao_county' => array( 'dublin' ) ),
		'venue'     => '',
	),
	array(
		'slug'      => 'evento-removido-na-fonte',
		'title'     => 'Evento removido na fonte',
		'content'   => '<!-- wp:paragraph --><p>Este evento foi removido pelo organizador na fonte.</p><!-- /wp:paragraph -->',
		'source'    => 'eventbrite',
		'source_id' => 'pilot-eb-0007',
		'date'      => $stage32_future_2,
		'status'    => 'source_not_found',
		'terms'     => array( 'conexao_town' => array( 'kilkenny' ), 'conexao_county' => array( 'kilkenny' ) ),
		'venue'     => '',
	),
	array(
		'slug'      => 'irish-dance-workshop-dublin',
		'title'     => 'Irish Dance Workshop for Beginners',
		'content'   => '<!-- wp:paragraph --><p>A beginner-friendly Irish dance workshop hosted by a local dance school, listed for the community.</p><!-- /wp:paragraph -->',
		'source'    => 'eventbrite',
		'source_id' => 'pilot-eb-0008',
		'date'      => $stage32_future_3,
		'status'    => 'published',
		'terms'     => array( 'conexao_town' => array( 'dublin' ), 'conexao_county' => array( 'dublin' ), 'conexao_category' => array( 'cultura' ) ),
		'venue'     => 'DanceHouse, Dublin',
	),
	array(
		'slug'      => 'brazilian-film-night-dublin',
		'title'     => 'Noite de Cinema Brasileiro',
		'content'   => '<!-- wp:paragraph --><p>Sessão de cinema brasileiro com debate após a exibição.</p><!-- /wp:paragraph -->',
		'source'    => 'eventbrite',
		'source_id' => 'pilot-eb-0009',
		'date'      => $stage32_future_2,
		'status'    => 'published',
		'terms'     => array( 'conexao_town' => array( 'dublin' ), 'conexao_county' => array( 'dublin' ), 'conexao_category' => array( 'cultura' ) ),
		'venue'     => 'Irish Film Institute, Dublin',
	),
	array(
		'slug'      => 'encontro-de-brasileiros-limerick',
		'title'     => 'Encontro de Brasileiros em Limerick',
		'content'   => '<!-- wp:paragraph --><p>Encontro informal da comunidade brasileira de Limerick. Evento cadastrado manualmente.</p><!-- /wp:paragraph -->',
		'source'    => '',
		'source_id' => '',
		'date'      => $stage32_future_3,
		'status'    => '',
		'terms'     => array( 'conexao_town' => array( 'limerick' ), 'conexao_county' => array( 'limerick' ), 'conexao_category' => array( 'comunidade' ) ),
		'venue'     => 'The Locke Bar, Limerick',
	),
);

foreach ( $stage32_events as $event ) {
	$meta = array(
		'_event_date'       => $event['date'],
		'_event_start_time' => '19:00',
		'_event_time'       => '19:00',
		'_event_venue'      => $event['venue'],
		'_event_location'   => $event['venue'],
		'_event_imported'   => $event['source'] ? '1' : '0',
	);
	if ( $event['source'] ) {
		$meta['_event_source']      = $event['source'];
		$meta['_event_source_id']   = $event['source_id'];
		$meta['_event_source_url']  = 'https://example.test/' . $event['source_id'];
		$meta['_event_import_date'] = gmdate( 'Y-m-d H:i:s' );
	}
	if ( '' !== $event['status'] ) {
		$meta['_event_status'] = $event['status'];
	}
	$event_id = stage32_insert( 'event', $event['slug'], $event['title'], $event['content'], $meta, $event['terms'] );
	if ( $event_id && $event['source'] && ! get_post_meta( $event_id, '_event_export_uuid', true ) ) {
		update_post_meta( $event_id, '_event_export_uuid', wp_generate_uuid4() );
	}
}

echo "\n-- Leisure --\n";
$stage32_leisure = array(
	array(
		'slug'     => 'phoenix-park',
		'title'    => 'Phoenix Park',
		'internal' => true,
		'county'   => 'dublin',
		'town'     => 'Dublin',
		'cats'     => array( 'natureza', 'cidades' ),
		'content'  => '<!-- wp:paragraph --><p>O Phoenix Park é um dos maiores parques urbanos da Europa, com trilhas, veados e a residência oficial do Presidente da Irlanda.</p><!-- /wp:paragraph -->',
	),
	array(
		'slug'     => 'cliffs-of-moher',
		'title'    => 'Cliffs of Moher',
		'internal' => true,
		'county'   => 'clare',
		'town'     => 'Liscannor',
		'cats'     => array( 'natureza', 'aventura' ),
		'content'  => '<!-- wp:paragraph --><p>Os Cliffs of Moher são falésias de mais de 200 metros sobre o Atlântico, um dos cartões-postais mais visitados da Irlanda.</p><!-- /wp:paragraph -->',
	),
	array(
		'slug'     => 'guinness-storehouse',
		'title'    => 'Guinness Storehouse',
		'internal' => true,
		'county'   => 'dublin',
		'town'     => 'Dublin',
		'cats'     => array( 'museus', 'cidades' ),
		'content'  => '<!-- wp:paragraph --><p>A Guinness Storehouse é a atração mais visitada de Dublin, com sete andares sobre a história da cerveja Guinness.</p><!-- /wp:paragraph -->',
	),
	array(
		'slug'     => 'killarney-national-park',
		'title'    => 'Parque Nacional de Killarney',
		'internal' => true,
		'county'   => 'kerry',
		'town'     => 'Killarney',
		'cats'     => array( 'natureza', 'caminhadas' ),
		'content'  => '<!-- wp:paragraph --><p>O Parque Nacional de Killarney reúne lagos, montanhas e florestas nativas no condado de Kerry.</p><!-- /wp:paragraph -->',
	),
	array(
		'slug'     => 'temple-bar',
		'title'    => 'Temple Bar',
		'internal' => true,
		'county'   => 'dublin',
		'town'     => 'Dublin',
		'cats'     => array( 'cidades', 'cultura' ),
		'content'  => '<!-- wp:paragraph --><p>O Temple Bar é o bairro cultural de Dublin, famoso pelos pubs, galerias e vida noturna.</p><!-- /wp:paragraph -->',
	),
	array(
		'slug'     => 'emerald-park',
		'title'    => 'Emerald Park',
		'internal' => false,
		'external' => 'https://www.emeraldpark.ie/',
		'county'   => 'meath',
		'town'     => 'Ashbourne',
		'cats'     => array( 'familia', 'aventura' ),
		'content'  => '',
	),
	array(
		'slug'     => 'fota-wildlife-park',
		'title'    => 'Fota Wildlife Park',
		'internal' => false,
		'external' => 'https://www.fotawildlife.ie/',
		'county'   => 'cork',
		'town'     => 'Carrigtwohill',
		'cats'     => array( 'vida-selvagem', 'familia' ),
		'content'  => '',
	),
	array(
		'slug'     => 'bru-na-boinne',
		'title'    => 'Brú na Bóinne (Newgrange)',
		'internal' => false,
		'external' => 'https://www.heritageireland.ie/en/bru-na-boinne/',
		'county'   => 'meath',
		'town'     => 'Donore',
		'cats'     => array( 'historia', 'patrimonio' ),
		'content'  => '',
	),
);

foreach ( $stage32_leisure as $item ) {
	$meta = array(
		'_leisure_county' => $item['county'],
		'_leisure_town'   => $item['town'],
	);
	if ( $item['internal'] ) {
		$meta['_leisure_internal_page'] = '1';
	} else {
		$meta['_leisure_official_website'] = $item['external'];
	}
	$terms = array(
		'conexao_county'   => array( $item['county'] ),
		'conexao_category' => $item['cats'],
	);
	$leisure_id = stage32_insert( 'leisure', $item['slug'], $item['title'], $item['content'], $meta, $terms );
	if ( $leisure_id && ! get_post_meta( $leisure_id, '_leisure_export_uuid', true ) ) {
		update_post_meta( $leisure_id, '_leisure_export_uuid', wp_generate_uuid4() );
	}
}

echo "\n-- Sponsors --\n";
$stage32_sponsors = array(
	array( 'slug' => 'brasil-market-dublin', 'title' => 'Brasil Market Dublin', 'link' => 'https://example.test/brasil-market', 'cats' => array( 'negocios', 'gastronomia' ) ),
	array( 'slug' => 'cafe-brasil-dublin', 'title' => 'Café Brasil', 'link' => 'https://example.test/cafe-brasil', 'cats' => array( 'gastronomia' ) ),
	array( 'slug' => 'remessa-online', 'title' => 'Remessa Online', 'link' => 'https://example.test/remessa', 'cats' => array( 'financas' ) ),
	array( 'slug' => 'sabor-mineiro-restaurante', 'title' => 'Sabor Mineiro Restaurante', 'link' => 'https://example.test/sabor-mineiro', 'cats' => array( 'gastronomia' ) ),
);
foreach ( $stage32_sponsors as $sponsor ) {
	$content = '<!-- wp:paragraph --><p>' . $sponsor['title'] . ' é apoiador do Conexão BR Irlanda.</p><!-- /wp:paragraph -->';
	$meta    = array( '_sponsor_link' => $sponsor['link'] );
	$sponsor_id = stage32_insert( 'sponsor', $sponsor['slug'], $sponsor['title'], $content, $meta, array( 'conexao_category' => $sponsor['cats'] ) );
	if ( $sponsor_id && ! get_post_meta( $sponsor_id, '_sponsor_export_uuid', true ) ) {
		update_post_meta( $sponsor_id, '_sponsor_export_uuid', wp_generate_uuid4() );
	}
}

echo "\n-- Jobs --\n";
$stage32_jobs = array(
	array(
		'slug'    => 'oportunidades',
		'title'   => 'Oportunidades de emprego na Irlanda',
		'content' => '<!-- wp:paragraph --><p>Seleção de oportunidades de emprego para a comunidade brasileira na Irlanda.</p><!-- /wp:paragraph -->',
		'cats'    => array( 'empregos' ),
	),
	array(
		'slug'    => 'ajudante-de-cozinha-dublin',
		'title'   => 'Ajudante de cozinha — Dublin',
		'content' => '<!-- wp:paragraph --><p>Restaurante no centro de Dublin contrata ajudante de cozinha em tempo integral.</p><!-- /wp:paragraph -->',
		'cats'    => array( 'empregos', 'gastronomia' ),
	),
	array(
		'slug'    => 'recepcionista-hotel-galway',
		'title'   => 'Recepcionista de hotel — Galway',
		'content' => '<!-- wp:paragraph --><p>Hotel em Galway contrata recepcionista. Inglês intermediário necessário.</p><!-- /wp:paragraph -->',
		'cats'    => array( 'empregos', 'turismo' ),
	),
);
foreach ( $stage32_jobs as $job ) {
	stage32_insert( 'job', $job['slug'], $job['title'], $job['content'], array(), array( 'conexao_category' => $job['cats'] ) );
}

echo "\nDone. created={$stage32_stats['created']} existing={$stage32_stats['existing']}\n";
