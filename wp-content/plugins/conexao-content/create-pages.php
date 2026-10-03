<?php
/**
 * Script to create all missing WordPress pages for Conexão BR Irlanda
 * Run via: wp eval-file create-pages.php --allow-root
 */

// Ensure we're in WordPress context
if ( ! defined( 'ABSPATH' ) ) {
    define( 'WP_USE_THEMES', false );
    // Locate wp-load.php by walking up from this file's directory.
    $dir = dirname( __FILE__ );
    while ( $dir !== dirname( $dir ) ) {
        if ( file_exists( $dir . '/wp-load.php' ) ) {
            require_once( $dir . '/wp-load.php' );
            break;
        }
        $dir = dirname( $dir );
    }
    if ( ! defined( 'ABSPATH' ) ) {
        fwrite( STDERR, "Unable to locate wp-load.php. Run via: wp eval-file create-pages.php --allow-root\n" );
        exit( 1 );
    }
}

echo "Starting page creation...\n";

// ============================================================
// 1. MAIN PAGES
// ============================================================
// NOTE: The following pages are handled by CPT archives and should NOT be
// created as static pages:
// - guias (guide CPT archive)
// - eventos (event CPT archive)
// - cursos (course CPT archive)
// - apoiadores (sponsor CPT archive)
// Creating static pages with these slugs would conflict with the CPT archives.
//
// Empregos is the EXCEPTION: its CPT archive is disabled (see
// conexao-data-model), and /empregos/ is instead a normal WordPress page —
// the "Jobs Landing" hub — created below in section "EMPREGOS LANDING PAGE"
// and rendered by the theme's page-empregos.php template. Individual job posts
// (e.g. /empregos/oportunidades/) keep their /empregos/{slug}/ URLs.

$main_pages = [
    'inicio' => [
        'title' => 'Início',
        'content' => '<!-- wp:paragraph --><p>Bem-vindo ao Conexão BR Irlanda, o portal da comunidade brasileira na Irlanda.</p><!-- /wp:paragraph -->',
        'meta_desc' => 'Conexão BR Irlanda - Portal da comunidade brasileira na Irlanda. Guias, eventos, cursos, empregos e mais.',
    ],
    'blog' => [
        'title' => 'Blog',
        'content' => '',
        'meta_desc' => 'Blog do Conexão BR Irlanda - Artigos, notícias e informações para brasileiros na Irlanda.',
    ],
    'sobre-nos' => [
        'title' => 'Sobre Nós',
        'content' => '<!-- wp:heading --><h2>Quem somos</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>O Conexão BR Irlanda é o portal da comunidade brasileira na Irlanda. Nosso objetivo é conectar, informar e apoiar brasileiros que vivem ou planejam viver na Irlanda.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>Nossa Missão</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Oferecer informações relevantes, guias práticos, eventos e uma rede de apoio para que cada brasileiro possa viver melhor na Irlanda.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>Nossa Visão</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Ser a principal referência para a comunidade brasileira na Irlanda, promovendo integração, conhecimento e oportunidades.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>Nossos Valores</h2><!-- /wp:heading -->
<!-- wp:list --><ul><li>Comunidade e solidariedade</li><li>Informação de qualidade e verificada</li><li>Inclusão e diversidade</li><li>Transparência e confiança</li></ul><!-- /wp:list -->',
        'meta_desc' => 'Conheça o Conexão BR Irlanda, o portal da comunidade brasileira na Irlanda. Nossa missão, visão e valores.',
    ],
    'contato' => [
        'title' => 'Contato',
        'content' => '<!-- wp:heading --><h2>Entre em contato conosco</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Tem uma sugestão, dúvida ou quer anunciar conosco? Mande uma mensagem!</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p><a href="https://wa.me/353899451428">Fale conosco pelo WhatsApp</a></p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Email: tdcriativo@gmail.com</p><!-- /wp:paragraph -->',
        'meta_desc' => 'Entre em contato com a equipe do Conexão BR Irlanda. Tire dúvidas, envie sugestões ou saiba como anunciar.',
    ],
    'newsletter' => [
        'title' => 'Newsletter',
        'content' => '<!-- wp:heading --><h2>Assine nossa Newsletter</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Receba as últimas notícias, eventos e guias práticos diretamente no seu email. Sem spam, apenas conteúdo relevante para brasileiros na Irlanda.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Junte-se a milhares de brasileiros que já recebem nossas atualizações semanais.</p><!-- /wp:paragraph -->',
        'meta_desc' => 'Assine a newsletter do Conexão BR Irlanda e receba notícias, eventos e guias para brasileiros na Irlanda.',
    ],
    'revista' => [
        'title' => 'Revista Digital',
        'content' => '<!-- wp:heading --><h2>Revista Digital Conexão BR Irlanda</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Nossa revista digital traz artigos especiais, entrevistas, histórias inspiradoras e conteúdo exclusivo para a comunidade brasileira na Irlanda.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Em breve, novas edições estarão disponíveis aqui.</p><!-- /wp:paragraph -->',
        'meta_desc' => 'Revista digital do Conexão BR Irlanda com artigos, entrevistas e conteúdo exclusivo para brasileiros na Irlanda.',
    ],
    'anuncie' => [
        'title' => 'Anuncie Aqui',
        'content' => '<!-- wp:heading --><h2>Anuncie no Conexão BR Irlanda</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Alcance a comunidade brasileira na Irlanda! Divulgue seu negócio, serviço ou evento para milhares de brasileiros em todo o país.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Por que anunciar conosco?</h3><!-- /wp:heading -->
<!-- wp:list --><ul><li>Público-alvo qualificado: brasileiros na Irlanda</li><li>Alta relevância e engajamento</li><li>Diversos formatos de anúncio</li><li>Presença em redes sociais e site</li></ul><!-- /wp:list -->
<!-- wp:paragraph --><p>Entre em contato pelo WhatsApp <a href="https://wa.me/353899451428">clique aqui</a> para saber mais.</p><!-- /wp:paragraph -->',
        'meta_desc' => 'Anuncie no Conexão BR Irlanda e alcance milhares de brasileiros na Irlanda. Divulgue seu negócio ou serviço.',
    ],
    'politica-de-privacidade' => [
        'title' => 'Política de Privacidade',
        'content' => '<!-- wp:heading --><h2>Política de Privacidade</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Esta Política de Privacidade descreve como o Conexão BR Irlanda coleta, usa e protege as informações pessoais dos usuários do nosso site.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Informações que coletamos</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Coletamos informações que você nos fornece voluntariamente, como nome e email ao assinar nossa newsletter, e informações de navegação através de cookies.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Como usamos suas informações</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Utilizamos suas informações para enviar newsletters, melhorar nosso conteúdo e personalizar sua experiência no site.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Seus direitos</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Você tem direito a acessar, corrigir ou excluir seus dados pessoais a qualquer momento. Entre em contato conosco para exercer esses direitos.</p><!-- /wp:paragraph -->',
        'meta_desc' => 'Política de privacidade do Conexão BR Irlanda. Saiba como coletamos, usamos e protegemos suas informações.',
    ],
    'termos-de-uso' => [
        'title' => 'Termos de Uso',
        'content' => '<!-- wp:heading --><h2>Termos de Uso</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Ao acessar e usar o site Conexão BR Irlanda, você concorda com os seguintes termos e condições.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Uso do Conteúdo</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Todo o conteúdo deste site é fornecido apenas para fins informativos. Não nos responsabilizamos por decisões tomadas com base nas informações aqui publicadas.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Links Externos</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Nosso site pode conter links para sites externos. Não nos responsabilizamos pelo conteúdo ou práticas de privacidade desses sites.</p><!-- /wp:paragraph -->',
        'meta_desc' => 'Termos de uso do Conexão BR Irlanda. Condições para acesso e uso do conteúdo do portal.',
    ],
    'cookies' => [
        'title' => 'Política de Cookies',
        'content' => '<!-- wp:heading --><h2>Política de Cookies</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Este site utiliza cookies para melhorar sua experiência de navegação e fornecer conteúdo relevante.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>O que são cookies?</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Cookies são pequenos arquivos de texto armazenados no seu navegador que nos ajudam a lembrar suas preferências e entender como você usa nosso site.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Tipos de cookies que utilizamos</h3><!-- /wp:heading -->
<!-- wp:list --><ul><li>Cookies essenciais: necessários para o funcionamento do site</li><li>Cookies de análise: para entender como você usa o site</li><li>Cookies de preferência: para lembrar suas escolhas</li></ul><!-- /wp:list -->',
        'meta_desc' => 'Política de cookies do Conexão BR Irlanda. Saiba como utilizamos cookies em nosso site.',
    ],
    'search' => [
        'title' => 'Buscar',
        'content' => '<!-- wp:search /-->',
        'meta_desc' => 'Busque por conteúdo no Conexão BR Irlanda.',
    ],
];

// ============================================================
// 2. COMMUNITY CATEGORY PAGES
// ============================================================
$category_pages = [
    'moradia' => 'Moradia',
    'saude' => 'Saúde',
    'familia' => 'Família',
    'transporte' => 'Transporte',
    'financas' => 'Finanças',
    'beneficios' => 'Benefícios',
    'educacao' => 'Educação',
    'documentos' => 'Documentos',
    'onde-comer' => 'Onde Comer',
    'lazer' => 'Lazer',
    'turismo' => 'Turismo',
    'compras' => 'Compras',
    'negocios' => 'Negócios',
    'servicos' => 'Serviços',
    'voluntariado' => 'Voluntariado',
];

// ============================================================
// 3. IRELAND / COUNTIES / EUROPE
// ============================================================
$ireland_content = '<!-- wp:heading --><h2>Irlanda para brasileiros</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Guia completo sobre a Irlanda para brasileiros. Informações sobre moradia, saúde, imigração, transporte, educação e muito mais.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Moradia</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Encontre dicas e informações sobre aluguel de casas, apartamentos e acomodações na Irlanda.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Saúde</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Saiba como funciona o sistema de saúde irlandês, como se registrar em um GP e obter o Medical Card.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Imigração</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Informações sobre vistos, permissões de trabalho, IRP e cidadania irlandesa.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Dirigir na Irlanda</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Saiba como trocar sua CNH brasileira, obter a carteira de motorista irlandesa e as regras de trânsito.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Bancos e Finanças</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Guia para abrir conta bancária, entender impostos e gerenciar suas finanças na Irlanda.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Educação</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Informações sobre o sistema educacional irlandês, escolas, universidades e cursos.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Emprego</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Mercado de trabalho, direitos trabalhistas e oportunidades de emprego na Irlanda.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Benefícios</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Saiba quais benefícios sociais estão disponíveis e como solicitar cada um deles.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Transporte</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Como se locomover na Irlanda: transporte público, táxis, bicicletas e dicas de mobilidade.</p><!-- /wp:paragraph -->';

$europe_content = '<!-- wp:heading --><h2>Europa para brasileiros</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Guia com informações sobre outros países europeus para brasileiros que desejam explorar a Europa.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Portugal</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Informações sobre Portugal para brasileiros: vistos, moradia, trabalho e mais.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Espanha</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Guia sobre a Espanha: cultura, oportunidades e informações para brasileiros.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Itália</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Descubra a Itália: cidadania italiana, oportunidades e estilo de vida.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Alemanha</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Informações sobre a Alemanha: mercado de trabalho, vistos e qualidade de vida.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>França</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Guia sobre a França para brasileiros: imigração, cultura e oportunidades.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Países Baixos (Holanda)</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Informações sobre os Países Baixos: trabalho, moradia e estilo de vida.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Bélgica</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Guia sobre a Bélgica: oportunidades, cultura e informações para brasileiros.</p><!-- /wp:paragraph -->';

$county_content_template = '<!-- wp:heading --><h2>%s para brasileiros</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Guia completo sobre o condado de %s na Irlanda. Informações sobre moradia, eventos, negócios, guias, empregos e restaurantes.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Eventos</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Confira os eventos e atividades acontecendo no condado de %s.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Negócios e Serviços</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Encontre empresas e serviços brasileiros no condado de %s.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Guias Práticos</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Guias úteis para viver no condado de %s.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Empregos</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Oportunidades de trabalho no condado de %s.</p><!-- /wp:paragraph -->
<!-- wp:heading --><h3>Restaurantes</h3><!-- /wp:heading -->
<!-- wp:paragraph --><p>Descubra restaurantes e opções gastronômicas no condado de %s.</p><!-- /wp:paragraph -->';

$counties = [
    'laois' => 'Laois',
    'dublin' => 'Dublin',
    'cork' => 'Cork',
    'galway' => 'Galway',
    'limerick' => 'Limerick',
    'kildare' => 'Kildare',
    'meath' => 'Meath',
    'wicklow' => 'Wicklow',
    'waterford' => 'Waterford',
];

// ============================================================
// PAGE CREATION FUNCTION
// ============================================================
function create_page_if_not_exists($slug, $title, $content, $meta_desc = '') {
    $existing = get_page_by_path($slug);
    if ($existing) {
        echo "  EXISTS: {$slug} (ID: {$existing->ID})\n";
        return $existing->ID;
    }
    
    $page_id = wp_insert_post([
        'post_title'    => $title,
        'post_name'     => $slug,
        'post_content'  => $content,
        'post_status'   => 'publish',
        'post_type'     => 'page',
        'meta_input'    => $meta_desc ? ['_yoast_wpseo_metadesc' => $meta_desc] : [],
    ]);
    
    if (is_wp_error($page_id)) {
        echo "  ERROR: {$slug} - {$page_id->get_error_message()}\n";
        return false;
    }
    
    // Add SEO meta description directly
    if ($meta_desc) {
        update_post_meta($page_id, '_yoast_wpseo_metadesc', $meta_desc);
        // Also add standard meta description
        update_post_meta($page_id, 'conexao_meta_description', $meta_desc);
    }
    
    echo "  CREATED: {$slug} (ID: {$page_id})\n";
    return $page_id;
}

function create_redirect_page_if_missing($slug, $title, $content) {
    $existing = get_page_by_path($slug);
    if ($existing) {
        echo "  EXISTS: {$slug}\n";
        return $existing->ID;
    }

    $page_id = wp_insert_post([
        'post_title'    => $title,
        'post_name'     => $slug,
        'post_content'  => $content,
        'post_status'   => 'publish',
        'post_type'     => 'page',
    ]);

    if (is_wp_error($page_id)) {
        echo "  ERROR: {$slug} - {$page_id->get_error_message()}\n";
        return false;
    }

    echo "  CREATED: {$slug} (redirect page)\n";
    return $page_id;
}

function add_menu_items($menu_id, $items) {
    foreach ($items as $item) {
        $menu_item_data = [
            'menu-item-title'     => $item['title'],
            'menu-item-url'       => $item['url'] ?? '',
            'menu-item-type'      => $item['type'] ?? 'custom',
            'menu-item-object'    => $item['object'] ?? 'custom',
            'menu-item-object-id' => $item['object_id'] ?? 0,
            'menu-item-status'    => 'publish',
        ];

        $result = wp_update_nav_menu_item($menu_id, 0, $menu_item_data);
        if (is_wp_error($result)) {
            echo "  ERROR adding item '{$item['title']}': {$result->get_error_message()}\n";
        } else {
            echo "  Added: {$item['title']}" . (isset($item['type']) ? " (type: {$item['type']})" : '') . "\n";
        }
    }
}

// ============================================================
// EXECUTE PAGE CREATION
// ============================================================

echo "\n=== MAIN PAGES ===\n";
foreach ($main_pages as $slug => $data) {
    create_page_if_not_exists($slug, $data['title'], $data['content'], $data['meta_desc']);
}

echo "\n=== EMPREGOS LANDING PAGE ===\n";
// /empregos/ is now a normal WordPress page (the "Jobs Landing" hub) rendered
// by the theme's page-empregos.php template. It carries the editable title,
// portrait featured image, body content and the optional "Mais informações"
// link — all manageable from wp-admin. Content is intentionally left minimal
// so the owner can populate it through the editor.
$empregos_page = get_page_by_path('empregos');
if (!$empregos_page) {
    $empregos_page_id = wp_insert_post([
        'post_title'    => 'Empregos',
        'post_name'     => 'empregos',
        'post_content'  => '<!-- wp:paragraph --><p>Informações sobre oportunidades de emprego para a comunidade brasileira na Irlanda. Este conteúdo é editável — substitua-o pelos detalhes sobre como as oportunidades são partilhadas, orientações de Instagram e dicas por condado.</p><!-- /wp:paragraph -->',
        'post_status'   => 'publish',
        'post_type'     => 'page',
    ]);

    if (is_wp_error($empregos_page_id)) {
        echo "  ERROR: empregos - {$empregos_page_id->get_error_message()}\n";
    } else {
        echo "  CREATED: empregos (ID: {$empregos_page_id})\n";
        $empregos_page = get_post($empregos_page_id);
    }
} else {
    echo "  EXISTS: empregos (ID: {$empregos_page->ID})\n";
}

if ($empregos_page) {
    // Assign the dedicated Jobs landing template so the layout takes effect.
    update_post_meta($empregos_page->ID, '_wp_page_template', 'page-empregos.php');
    echo "  Template: page-empregos.php\n";
}

echo "\n=== COMMUNITY CATEGORY PAGES ===\n";
$cat_content = '<!-- wp:heading --><h2>%s</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Ajude a comunidade brasileira na Irlanda com informações sobre %s.</p><!-- /wp:paragraph -->
<!-- wp:paragraph --><p>Conteúdo para esta seção está sendo preparado. Enquanto isso, explore nossos guias abaixo.</p><!-- /wp:paragraph -->';

foreach ($category_pages as $slug => $title) {
    $desc = strtolower($title);
    $content = sprintf($cat_content, $title, $desc);
    $meta_desc = "Guia completo sobre {$title} para brasileiros na Irlanda. Informações, dicas e recursos úteis.";
    create_page_if_not_exists($slug, $title, $content, $meta_desc);
}

echo "\n=== IRELAND PAGE ===\n";
create_page_if_not_exists('irlanda', 'Irlanda para Brasileiros', $ireland_content, 'Guia completo sobre a Irlanda para brasileiros. Moradia, saúde, imigração, transporte, educação e muito mais.');

echo "\n=== EUROPE PAGE ===\n";
create_page_if_not_exists('europa', 'Europa para Brasileiros', $europe_content, 'Guia sobre países europeus para brasileiros. Portugal, Espanha, Itália, Alemanha, França e mais.');

echo "\n=== COUNTY PAGES ===\n";
foreach ($counties as $slug => $name) {
    $content = sprintf($county_content_template, $name, $name, $name, $name, $name, $name, $name, $name);
    $meta_desc = "Guia completo sobre o condado de {$name} na Irlanda. Eventos, empresas, guias e empregos para brasileiros.";
    create_page_if_not_exists($slug, "Condado de {$name}", $content, $meta_desc);
}

// ============================================================
// CREATE ADDITIONAL PAGES FOR FOOTER LINKS THAT DON'T MATCH
// ============================================================
echo "\n=== FOOTER REDIRECT PAGES ===\n";
create_redirect_page_if_missing('privacidade', 'Privacidade', '<!-- wp:paragraph --><p>Esta página foi movida. <a href="/politica-de-privacidade/">Clique aqui para acessar nossa Política de Privacidade</a>.</p><!-- /wp:paragraph -->');
create_redirect_page_if_missing('termos', 'Termos', '<!-- wp:paragraph --><p>Esta página foi movida. <a href="/termos-de-uso/">Clique aqui para acessar nossos Termos de Uso</a>.</p><!-- /wp:paragraph -->');
create_redirect_page_if_missing('sobre', 'Sobre', '<!-- wp:paragraph --><p>Esta página foi movida. <a href="/sobre-nos/">Clique aqui para acessar Sobre Nós</a>.</p><!-- /wp:paragraph -->');

// Create /categorias/ page for the "Ver todas" link
$categorias = get_page_by_path('categorias');
if (!$categorias) {
    wp_insert_post([
        'post_title'    => 'Categorias',
        'post_name'     => 'categorias',
        'post_content'  => '<!-- wp:heading --><h2>Todas as Categorias</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p>Explore todas as categorias do Conexão BR Irlanda.</p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><a href="/moradia/">Moradia</a></li>
<li><a href="/empregos/">Empregos</a></li>
<li><a href="/saude/">Saúde</a></li>
<li><a href="/familia/">Família</a></li>
<li><a href="/transporte/">Transporte</a></li>
<li><a href="/financas/">Finanças</a></li>
<li><a href="/beneficios/">Benefícios</a></li>
<li><a href="/educacao/">Educação</a></li>
<li><a href="/documentos/">Documentos</a></li>
<li><a href="/onde-comer/">Onde Comer</a></li>
<li><a href="/lazer/">Lazer</a></li>
<li><a href="/turismo/">Turismo</a></li>
<li><a href="/compras/">Compras</a></li>
<li><a href="/negocios/">Negócios</a></li>
<li><a href="/servicos/">Serviços</a></li>
<li><a href="/voluntariado/">Voluntariado</a></li>
<li><a href="/eventos/">Eventos</a></li>
<li><a href="/guias/">Guias</a></li>
</ul><!-- /wp:list -->',
        'post_status'   => 'publish',
        'post_type'     => 'page',
    ]);
    echo "  CREATED: categorias\n";
} else {
    echo "  EXISTS: categorias\n";
}

// ============================================================
// CONFIGURE FRONT PAGE AND POSTS PAGE
// ============================================================
echo "\n=== FRONT PAGE / POSTS PAGE CONFIGURATION ===\n";

// Set the front page to the "inicio" page.
$front_page = get_page_by_path( 'inicio' );
if ( $front_page ) {
    update_option( 'show_on_front', 'page' );
    update_option( 'page_on_front', (int) $front_page->ID );
    echo "  Front page set to: inicio (ID: {$front_page->ID})\n";
} else {
    echo "  WARNING: 'inicio' page not found; keeping default front page.\n";
}

// Set the posts page to the "blog" page so /blog/ serves the posts archive.
$blog_page = get_page_by_path( 'blog' );
if ( $blog_page ) {
    update_option( 'page_for_posts', (int) $blog_page->ID );
    echo "  Posts page set to: blog (ID: {$blog_page->ID})\n";
} else {
    echo "  WARNING: 'blog' page not found; /blog/ will not serve the posts archive.\n";
}

// ============================================================
// CREATE NAVIGATION MENUS
// ============================================================
echo "\n=== NAVIGATION MENUS ===\n";

// Get all page IDs for menu items
$page_slugs = array_merge(
    array_keys($main_pages),
    array_keys($category_pages),
    ['irlanda', 'europa'],
    array_keys($counties),
    ['privacidade', 'termos', 'sobre', 'categorias']
);

$page_ids = [];
foreach ($page_slugs as $slug) {
    $page = get_page_by_path($slug);
    if ($page) {
        $page_ids[$slug] = $page->ID;
    }
}

// Create Primary Menu
$primary_menu_id = wp_get_nav_menu_object('Menu Principal');
if (!$primary_menu_id) {
    $primary_menu_id = wp_create_nav_menu('Menu Principal');
    echo "  CREATED: Menu Principal\n";
} else {
    $primary_menu_id = $primary_menu_id->term_id;
    echo "  FOUND: Menu Principal (ID: {$primary_menu_id})\n";
    
    // Remove existing items
    $menu_items = wp_get_nav_menu_items($primary_menu_id);
    if ($menu_items) {
        foreach ($menu_items as $item) {
            wp_delete_post($item->ID, true);
        }
        echo "  Removed existing menu items\n";
    }
}

// Add items to Primary Menu
// NOTE: Guias, Eventos, Cursos, Empregos, Apoiadores are now CPT archives (not static pages)
// They use custom URLs pointing to the CPT archive paths
//
// ORDER IS THE SOURCE OF TRUTH. The array below is written in the canonical
// primary-navigation order documented in docs/routing.md §Navigation
// Architecture and docs/themes/conexao-br-irlanda.md §Navigation Logic:
//
//   Início, Apoiadores, Guias, Eventos, Cursos, Lazer e turismo, Empregos,
//   Blog, Contato
//
// `add_menu_items()` inserts in array order, so this array's order becomes the
// nav_menu_item `menu_order` — the single authoritative stored order (desktop
// and mobile share the same 'primary' location). The render-time layer in
// inc/navigation.php only INSERTS items the stored menu is missing (e.g.
// "Lazer e turismo", which has no stored item and is injected at render time
// between Cursos and Empregos) and binds each item to its canonical object; it
// deliberately never re-sorts the items it was given. Seeding out of order
// therefore renders out of order, so keep this list in canonical order.
// Existing databases are repaired order-only by
// scripts/reorder-primary-menu-blog-apoiadores.php (idempotent).
$primary_items = [
    ['title' => 'Home', 'type' => 'custom', 'url' => home_url('/')],
    ['title' => 'Apoiadores', 'type' => 'custom', 'url' => home_url('/apoiadores/')],
    ['title' => 'Guias', 'type' => 'custom', 'url' => home_url('/guias/')],
    ['title' => 'Eventos', 'type' => 'custom', 'url' => home_url('/eventos/')],
    ['title' => 'Cursos', 'type' => 'custom', 'url' => home_url('/cursos/')],
    ['title' => 'Empregos', 'type' => 'custom', 'url' => home_url('/empregos/')],
    // NOTE: "Lazer e turismo" has NO stored item by design — the render-time
    // layer injects it between Cursos and Empregos.
    ['title' => 'Blog', 'type' => 'custom', 'url' => home_url('/blog/')],
    // NOTE: "Irlanda" is intentionally NOT a primary-nav item. The /irlanda/
    // page remains published and directly accessible; it is just not linked
    // from the main navigation (desktop or mobile share this same menu).
    ['title' => 'Sobre Nós', 'type' => 'post_type', 'object' => 'page', 'object_id' => $page_ids['sobre-nos'] ?? 0],
    ['title' => 'Contato', 'type' => 'post_type', 'object' => 'page', 'object_id' => $page_ids['contato'] ?? 0],
];

add_menu_items($primary_menu_id, $primary_items);

// Assign primary menu to theme location
$locations = get_theme_mod('nav_menu_locations');
if (!is_array($locations)) {
    $locations = [];
}
$locations['primary'] = $primary_menu_id;
set_theme_mod('nav_menu_locations', $locations);
echo "  Assigned Menu Principal to primary location\n";

// Create Footer Menu
$footer_menu_id = wp_get_nav_menu_object('Menu Rodapé');
if (!$footer_menu_id) {
    $footer_menu_id = wp_create_nav_menu('Menu Rodapé');
    echo "  CREATED: Menu Rodapé\n";
} else {
    $footer_menu_id = $footer_menu_id->term_id;
    echo "  FOUND: Menu Rodapé (ID: {$footer_menu_id})\n";
    
    $menu_items = wp_get_nav_menu_items($footer_menu_id);
    if ($menu_items) {
        foreach ($menu_items as $item) {
            wp_delete_post($item->ID, true);
        }
        echo "  Removed existing footer menu items\n";
    }
}

$footer_items = [
    ['title' => 'Home', 'url' => home_url('/')],
    ['title' => 'Sobre Nós', 'object' => 'page', 'object_id' => $page_ids['sobre-nos'] ?? 0],
    ['title' => 'Política de Privacidade', 'object' => 'page', 'object_id' => $page_ids['politica-de-privacidade'] ?? 0],
    ['title' => 'Termos de Uso', 'object' => 'page', 'object_id' => $page_ids['termos-de-uso'] ?? 0],
    ['title' => 'Cookies', 'object' => 'page', 'object_id' => $page_ids['cookies'] ?? 0],
    ['title' => 'Anuncie', 'object' => 'page', 'object_id' => $page_ids['anuncie'] ?? 0],
    ['title' => 'Contato', 'object' => 'page', 'object_id' => $page_ids['contato'] ?? 0],
    ['title' => 'Newsletter', 'object' => 'page', 'object_id' => $page_ids['newsletter'] ?? 0],
];

foreach ($footer_items as &$item) {
    $item['type'] = isset($item['object']) ? 'post_type' : 'custom';
}
unset($item);

add_menu_items($footer_menu_id, $footer_items);

// Assign footer menu to theme location
$locations['footer'] = $footer_menu_id;
set_theme_mod('nav_menu_locations', $locations);
echo "  Assigned Menu Rodapé to footer location\n";

// ============================================================
// FLUSH REWRITE RULES
// ============================================================
echo "\n=== FLUSHING REWRITE RULES ===\n";
flush_rewrite_rules();
echo "  Done!\n";

echo "\n=== SUMMARY ===\n";
echo "All pages created. Navigation menus set up.\n";
echo "Visit the site to verify all links work correctly.\n";