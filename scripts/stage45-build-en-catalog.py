#!/usr/bin/env python3
"""
Stage 4.5 — complete the theme's en_US gettext catalog and compile the .mo.

Why: the EN pages translated by this stage render theme chrome (homepage hero,
quick-access cards, section CTAs, page eyebrows, filters, footer) through
gettext. The Stage 1 catalog was deliberately a curated subset (65 strings;
Stage 1 report §3/§6 "Complete the en_US catalog" follow-up). This script is
that completion: every remaining empty msgstr in en_US.po gets a
human-authored English translation (table T below), then en_US.mo is
recompiled — no msgfmt dependency (WordPress.com has no CLI).

Rules honoured:
  - human-authored translations, no machine translation;
  - brand/organisation/programme/place names are never translated
    (PPS Number, Medical Card, WhatsApp, HSE, Discover Ireland, Jobs.ie, …);
  - pt_BR.po / pt_BR.mo are never touched;
  - existing non-empty en_US msgstr are preserved byte-for-byte;
  - format strings (%s/%d/%1$s), HTML entities and tags are preserved.

Re-runnable: applying the same table twice is a no-op. With --check it only
reports (drift detection for CI/staging).

Usage:
  python3 scripts/stage45-build-en-catalog.py           # write .po + .mo
  python3 scripts/stage45-build-en-catalog.py --check   # verify only
"""

import re
import struct
import sys

PO = "wp-content/themes/conexao-br-irlanda/languages/en_US.po"
MO = "wp-content/themes/conexao-br-irlanda/languages/en_US.mo"

# ---------------------------------------------------------------------------
# Human-authored translation table: (msgctxt|None, msgid) -> msgstr
# ([singular, plural] for plurals). Strings already translated in the curated
# catalog are intentionally absent (preserved as-is).
# ---------------------------------------------------------------------------
T = {
    # style.css (theme headers — already English; kept identical on purpose)
    (None, "Conexão BR Irlanda - Community Portal"): "Conexão BR Irlanda - Community Portal",
    (None, "https://conexaobr.ie"): "https://conexaobr.ie",
    (None, "Modern Community Portal theme for Conexão BR Irlanda - a digital magazine and community hub for Brazilians in Ireland. Features a modern portal design with green/orange palette, responsive layout, and full Gutenberg support."): "Modern Community Portal theme for Conexão BR Irlanda - a digital magazine and community hub for Brazilians in Ireland. Features a modern portal design with green/orange palette, responsive layout, and full Gutenberg support.",
    (None, "Conexão BR Irlanda"): "Conexão BR Irlanda",

    # 404.php
    (None, "Ops! Página não encontrada."): "Oops! Page not found.",
    (None, "A página que você procura pode ter sido movida ou removida."): "The page you are looking for may have been moved or removed.",
    (None, "Tente buscar pelo que precisa:"): "Try searching for what you need:",
    (None, "Voltar para o início"): "Back to the homepage",
    (None, "Guias Populares"): "Popular Guides",
    (None, "PPS Number"): "PPS Number",
    (None, "Medical Card"): "Medical Card",
    (None, "Abrir Conta Bancária"): "Open a Bank Account",
    (None, "Alugar Casa"): "Renting a House",
    (None, "Carteira de Motorista"): "Driving Licence",
    (None, "Próximos Eventos"): "Upcoming Events",
    (None, "Nenhum evento próximo no momento."): "No upcoming events at the moment.",

    # archive.php
    ("archive eyebrow", "Agenda Irlandesa"): "Irish Agenda",
    ("archive page title", "Eventos"): "Events",
    ("archive description", "Encontre eventos, encontros e atividades na Irlanda."): "Find events, meetups and activities in Ireland.",
    ("archive eyebrow", "Aprendizagem e Formação"): "Learning & Training",
    ("archive page title", "Cursos"): "Courses",
    ("archive description", "Encontre cursos, formações e oportunidades de aprendizagem na Irlanda."): "Find courses, training and learning opportunities in Ireland.",
    ("archive eyebrow", "Informação e Guias"): "Information & Guides",
    ("archive eyebrow", "Lazer & Turismo"): "Leisure & Tourism",
    ("archive page title", "Lazer"): "Leisure",
    ("archive description", "Descubra lugares para visitar, natureza, cultura, turismo e coisas para fazer na Irlanda."): "Discover places to visit, nature, culture, tourism and things to do in Ireland.",
    ("archive eyebrow", "Oportunidades"): "Opportunities",
    (None, "Oportunidades"): "Opportunities",
    ("archive eyebrow", "Parceiros da Comunidade"): "Community Partners",
    (None, "Conhecer o Apoiador"): "Meet the Supporter",

    # comments.php
    (None, "Um comentário em &ldquo;%1$s&rdquo;"): "One comment on &ldquo;%1$s&rdquo;",
    (None, "%1$s comentário em &ldquo;%2$s&rdquo;"): ["%1$s comment on &ldquo;%2$s&rdquo;", "%1$s comments on &ldquo;%2$s&rdquo;"],
    (None, "← Anteriores"): "← Previous",
    (None, "Próximos →"): "Next →",
    (None, "Os comentários estão fechados."): "Comments are closed.",


    # footer.php
    (None, "Conectando a comunidade brasileira na Irlanda com informações, eventos, cursos e muito mais. Sua revista digital para brasileiros na Irlanda."): "Connecting the Brazilian community in Ireland with information, events, courses and much more. Your digital magazine for Brazilians in Ireland.",
    (None, "Redes Sociais"): "Social Media",
    (None, "© 2025 Conexão BR Irlanda. Todos os direitos reservados."): "© 2025 Conexão BR Irlanda. All rights reserved.",
    (None, "Privacidade"): "Privacy",
    (None, "Termos"): "Terms",
    (None, "Cookies"): "Cookies",

    # front-page.php (the homepage — Phase 9)
    (None, "Tudo que o brasileiro precisa para viver melhor na <span>Irlanda</span>"): "Everything Brazilians need to live better in <span>Ireland</span>",
    (None, "Conectando a comunidade brasileira com informações, eventos, guias práticos e muito mais."): "Connecting the Brazilian community with information, events, practical guides and much more.",
    (None, "Destaque da Comunidade"): "Community Highlight",
    (None, "Portal da Comunidade Brasileira"): "The Brazilian Community Portal",
    (None, "Explorar Guias"): "Explore Guides",
    (None, "Ver Eventos"): "See Events",
    (None, "Acesso Rápido"): "Quick Access",
    (None, "Moradia"): "Housing",
    (None, "Casas e apartamentos"): "Houses and apartments",
    (None, "Vagas de trabalho"): "Job openings",
    (None, "Saúde"): "Healthcare",
    (None, "Acesso à saúde"): "Healthcare access",
    (None, "Transporte"): "Transport",
    (None, "Como se locomover"): "Getting around",
    (None, "Finanças"): "Finances",
    (None, "Bancos e impostos"): "Banks and taxes",
    (None, "Benefícios"): "Benefits",
    (None, "Auxílios e subsídios"): "Allowances and benefits",
    (None, "Agenda da comunidade"): "Community agenda",
    (None, "Educação"): "Education",
    (None, "Cursos e escolas"): "Courses and schools",
    (None, "Documentos"): "Documents",
    (None, "Vistos e PPS Number"): "Visas and PPS Number",
    (None, "Negócios parceiros"): "Partner businesses",
    (None, "Novidades e artigos"): "News and articles",
    (None, "Guias práticos"): "Practical guides",
    (None, "Conteúdo em Destaque"): "Featured Content",
    (None, "Últimas Publicações"): "Latest Posts",
    (None, "Ver todas"): "View all",
    (None, "Novos conteúdos serão publicados em breve."): "New content will be published soon.",
    (None, "Mais Lidos"): "Most Read",
    (None, "Precisa de ajuda?"): "Need help?",
    (None, "Atalhos para as principais áreas do site"): "Shortcuts to the main areas of the site",
    (None, "Últimas novidades"): "Latest news",
    (None, "Ver todos"): "View all",
    (None, "Agenda"): "Agenda",
    (None, "Não perca os eventos da comunidade brasileira na Irlanda."): "Don't miss the Brazilian community events in Ireland.",
    (None, "Ver todos os eventos"): "View all events",
    (None, "Onde procurar emprego"): "Where to look for jobs",
    (None, "Ver mais"): "See more",


    # functions.php
    (None, "Menu Principal"): "Main Menu",
    (None, "Menu Rodapé"): "Footer Menu",
    (None, "Menu Social"): "Social Menu",
    (None, "Sidebar Principal"): "Main Sidebar",
    (None, "Rodapé - Coluna 1"): "Footer - Column 1",
    (None, "Rodapé - Coluna 2"): "Footer - Column 2",
    (None, "Rodapé - Coluna 3"): "Footer - Column 3",
    (None, "%d min de leitura"): ["%d minute read", "%d minutes read"],
    (None, "Posts Relacionados"): "Related Posts",
    (None, "Website"): "Website",
    (None, "Cores do Portal"): "Portal Colours",
    (None, "Cor Primária (Verde)"): "Primary Colour (Green)",
    (None, "Cor de Destaque (Laranja)"): "Accent Colour (Orange)",
    (None, "Instagram URL"): "Instagram URL",
    (None, "WhatsApp URL"): "WhatsApp URL",
    (None, "Facebook URL"): "Facebook URL",
    (None, "Hero Section"): "Hero Section",
    (None, "Título do Hero"): "Hero Title",
    (None, "Subtítulo do Hero"): "Hero Subtitle",
    (None, "Rodapé"): "Footer",
    (None, "Texto do Rodapé"): "Footer Text",
    (None, "Site oficial"): "Official website",
    (None, "Ver no Discover Ireland"): "View on Discover Ireland",
    (None, "Mais informações"): "More information",
    (None, "Categorias"): "Categories",
    (None, "Todos"): "All",
    (None, "Card do Portal"): "Portal Card",
    (None, "Hero do Portal"): "Portal Hero",
    (None, "Miniatura do Portal"): "Portal Thumbnail",
    (None, "Prévia de Evento (16:9)"): "Event Preview (16:9)",
    (None, "Tile do Apoiador (512px)"): "Supporter Tile (512px)",
    (None, "Banner de Evento"): "Event Banner",
    (None, "Logo de Provedor de Cursos"): "Course Provider Logo",
    (None, "Vaga Vertical (Instagram)"): "Vertical Job Card (Instagram)",
    (None, "Imagem da vaga: use uma imagem vertical, preferencialmente 1080 × 1920 px (formato Instagram Stories)."): "Job image: use a vertical image, preferably 1080 × 1920 px (Instagram Stories format).",
    (None, "Fale com a Conexão BR"): "Talk to Conexão BR",
    (None, "Fale conosco no WhatsApp"): "Chat with us on WhatsApp",
    (None, "Pular para o conteúdo"): "Skip to content",

    # header.php
    (None, "Abrir menu"): "Open menu",
    (None, "Alternar tema claro/escuro"): "Toggle light/dark theme",
    (None, "Abrir pesquisa"): "Open search",
    (None, "Pesquisar"): "Search",
    (None, "Buscar no portal..."): "Search the portal...",
    (None, "Buscar"): "Search",
    (None, "Anuncie Aqui"): "Advertise",
    (None, "Menu"): "Menu",
    (None, "Fechar menu"): "Close menu",
    (None, "Menu Mobile"): "Mobile Menu",
    (None, "Pesquisar no site"): "Search the site",
    (None, "Fechar pesquisa"): "Close search",

    # home.php (Blog landing)
    (None, "Artigos e Notícias"): "Articles & News",
    (None, "Informações, dicas e notícias para a comunidade brasileira na Irlanda."): "Information, tips and news for the Brazilian community in Ireland.",
    (None, "Categorias do blog"): "Blog categories",
    (None, "Nenhum artigo publicado ainda."): "No articles published yet.",
    (None, "Novos artigos serão publicados aqui em breve."): "New articles will be published here soon.",

    # inc/employment-opportunities.php + inc/job-resources.php + empregos landing
    (None, "Agência de recrutamento"): "Recruitment agency",
    (None, "Setor público"): "Public sector",
    (None, "Histórico de Employment Permits"): "Employment Permit history",
    (None, "Portal oficial para recrutamento do Civil Service e outras oportunidades no setor público irlandês."): "The official portal for Civil Service recruitment and other opportunities in the Irish public sector.",
    (None, "Ver vagas"): "View openings",
    (None, "Vagas no HSE em áreas de saúde, administração, serviços de apoio e outras funções."): "HSE vacancies in healthcare, administration, support services and other roles.",
    (None, "Oportunidades de emprego nas autoridades locais da Irlanda."): "Job opportunities in Ireland's local authorities.",
    (None, "Temporário"): "Temporary",
    (None, "Permanente"): "Permanent",
    (None, "Todas"): "All",
    (None, "Este campo é exibido apenas na página de destino de Empregos (template \"Empregos — Página de Difusão\")."): "This field is only shown on the Jobs landing page (the \"Empregos — Página de Difusão\" template).",
    (None, "URL ou destino do botão \"Mais informações\". Se deixado em branco, o botão não será exibido."): "URL or destination of the \"More information\" button. Leave blank to hide the button.",
    (None, "Imagem da página de Empregos: use uma imagem vertical, preferencialmente 1080 × 1920 px (formato Instagram Stories)."): "Jobs page image: use a vertical image, preferably 1080 × 1920 px (Instagram Stories format).",
    (None, "Encontre vagas de emprego em diversas áreas e regiões da Irlanda."): "Find job openings across many sectors and regions of Ireland.",
    (None, "Jobs.ie — site de empregos na Irlanda"): "Jobs.ie — Irish jobs website",
    (None, "Busca de vagas em todo o país, com filtros por área, salário e localização."): "Vacancy search across the whole country, with filters by sector, salary and location.",
    (None, "Indeed Irlanda — site de busca de empregos"): "Indeed Ireland — job search website",
    (None, "Uma das maiores plataformas de recrutamento da Irlanda, com vagas de grandes empresas."): "One of Ireland's largest recruitment platforms, with vacancies from major companies.",
    (None, "IrishJobs — site de empregos na Irlanda"): "IrishJobs — Irish jobs website",
    (None, "Você não tem permissão para acessar esta página."): "You do not have permission to access this page.",
    (None, "Sites de emprego salvos com sucesso."): "Job sites saved successfully.",
    (None, "Estes sites de busca de emprego aparecem como cartões na página /empregos/ (seção \"Onde procurar emprego\"). Escolha uma imagem da Biblioteca de Mídia para o logo de cada cartão; sem imagem, um banner com a inicial do site é exibido automaticamente."): "These job-search sites appear as cards on the /empregos/ page (the \"Where to look for jobs\" section). Choose a Media Library image for each card's logo; without an image, a banner with the site's initial is shown automatically.",
    (None, "Mostrando os sites padrão. Salve abaixo para começar a editar — a lista salva passa a valer para o site público."): "Showing the default sites. Save below to start editing — the saved list takes effect on the public site.",
    (None, "Adicionar site"): "Add site",
    (None, "Salvar sites de emprego"): "Save job sites",
    (None, "Novo site"): "New site",
    (None, "Remover este site"): "Remove this site",
    (None, "Nome do site"): "Site name",
    (None, "URL"): "URL",
    (None, "Descrição"): "Description",
    (None, "Texto alternativo (alt)"): "Alternative text (alt)",
    (None, "Imagem do cartão (logo)"): "Card image (logo)",
    (None, "Escolher imagem"): "Choose image",
    (None, "Remover imagem"): "Remove image",


    # inc/seo.php + index.php + page templates
    (None, "Breadcrumb"): "Breadcrumb",
    (None, "Ler mais →"): "Read more →",
    (None, "Empregos — Página de Difusão"): "Empregos — Página de Difusão",
    (None, "Páginas:"): "Pages:",
    (None, "Ver vagas no Instagram"): "See openings on Instagram",
    (None, "Landing Page (SEO)"): "Landing Page (SEO)",
    (None, "Categorias Relacionadas"): "Related Categories",
    ("page header eyebrow", "Fale Conosco"): "Get in Touch",
    ("page header eyebrow", "Conheça a Conexão BR"): "Meet Conexão BR",
    ("page header eyebrow", "Viver na Irlanda"): "Living in Ireland",

    # search
    (None, "Resultados da busca: %s"): "Search results for: %s",
    (None, "Buscar..."): "Search...",

    # single-leisure.php
    (None, "Ver lugares de lazer na categoria %s"): "See leisure places in the %s category",
    (None, "Ver lugares de lazer em %s"): "See leisure places in %s",
    (None, "Ver no Wikimedia Commons"): "View on Wikimedia Commons",
    (None, "Localização e Contato"): "Location & Contact",
    (None, "Localização"): "Location",
    (None, " (abre em nova aba)"): " (opens in a new tab)",
    (None, "Ver localização de %s no mapa (abre em nova aba)"): "See %s's location on the map (opens in a new tab)",
    (None, "Ver localização no mapa"): "See location on the map",
    (None, "Informações úteis"): "Useful information",
    (None, "Duração"): "Duration",
    (None, "Melhor época"): "Best time to visit",
    (None, "Observações práticas"): "Practical notes",
    (None, "Informação verificada em %s"): "Information verified on %s",
    (None, "Verifique as informações no site oficial"): "Check the information on the official website",
    (None, "Verifique as informações no Discover Ireland"): "Check the information on Discover Ireland",
    (None, "Verifique as informações na fonte:"): "Check the information at the source:",
    (None, "Informações podem estar desatualizadas. Confirme os detalhes diretamente com a atração antes da sua visita."): "Information may be outdated. Confirm the details directly with the attraction before your visit.",
    (None, "Outros lugares relacionados"): "Other related places",
    (None, "Ver mais lugares na categoria %s"): "See more places in the %s category",
    (None, "Próximos eventos"): "Upcoming events",

    # single-sponsor.php
    (None, "Todos os apoiadores"): "All supporters",
    (None, "Entre em contato"): "Get in touch",
    (None, "Escolha um canal para falar diretamente com o Apoiador:"): "Choose a channel to talk directly to the Supporter:",
    (None, "(abre em nova aba)"): "(opens in a new tab)",

    # template-parts/content-none.php
    (None, "Nada encontrado"): "Nothing found",
    (None, "Pronto para publicar seu primeiro post? <a href=\"%s\">Comece aqui</a>."): "Ready to publish your first post? <a href=\"%s\">Start here</a>.",
    (None, "Pronto para publicar seu primeiro artigo? <a href=\"%s\">Comece aqui</a>."): "Ready to publish your first article? <a href=\"%s\">Start here</a>.",
    (None, "Desculpe, mas nada corresponde aos seus termos de busca. Tente novamente com palavras-chave diferentes."): "Sorry, but nothing matches your search terms. Please try again with different keywords.",
    (None, "Nova busca"): "New search",
    (None, "Nenhum guia publicado ainda."): "No guides published yet.",
    (None, "Novos guias práticos serão publicados aqui em breve."): "New practical guides will be published here soon.",
    (None, "Nenhum evento encontrado com esses filtros."): "No events found with these filters.",
    (None, "Limpar filtros"): "Clear filters",
    (None, "Nenhum evento publicado ainda."): "No events published yet.",
    (None, "Novos eventos serão publicados aqui em breve."): "New events will be published here soon.",
    (None, "Nenhuma vaga de emprego publicada ainda."): "No job openings published yet.",
    (None, "Novas oportunidades serão publicadas aqui em breve."): "New opportunities will be published here soon.",
    (None, "Nenhum apoiador cadastrado ainda."): "No supporters registered yet.",
    (None, "Em breve, novos negócios estarão apoiando nossa comunidade."): "New businesses will be supporting our community soon.",
    (None, "Parece que não conseguimos encontrar o que você procura. Talvez a busca possa ajudar."): "It seems we could not find what you are looking for. Perhaps a search can help.",


    # template-parts — filters, cards, employment directory
    (None, "Filtrar cursos por categoria"): "Filter courses by category",
    (None, "Tipo de oportunidade"): "Opportunity type",
    (None, "Área de trabalho"): "Work area",
    (None, "Tipo de contrato"): "Contract type",
    (None, "Filtrar por Tipo de oportunidade. Filtro ativo: %s"): "Filter by Opportunity type. Active filter: %s",
    (None, "Filtrar por Tipo de oportunidade"): "Filter by Opportunity type",
    (None, "Filtrar por Área de trabalho. Filtro ativo: %s"): "Filter by Work area. Active filter: %s",
    (None, "Filtrar por Área de trabalho"): "Filter by Work area",
    (None, "Filtrar por Localização. Filtro ativo: %s"): "Filter by Location. Active filter: %s",
    (None, "Filtrar por Localização"): "Filter by Location",
    (None, "Filtrar por Tipo de contrato. Filtro ativo: %s"): "Filter by Contract type. Active filter: %s",
    (None, "Filtrar por Tipo de contrato"): "Filter by Contract type",
    (None, "Empregador"): "Employer",
    (None, "Oportunidades de emprego"): "Job opportunities",
    (None, "Avisos importantes sobre emprego e Employment Permits"): "Important notices about jobs and Employment Permits",
    (None, "Atenção:"): "Warning:",
    (None, "nunca pague por uma promessa de emprego, visto ou Employment Permit. Uma agência de recrutamento legítima não deve cobrar para encontrar emprego."): "never pay for a promise of a job, visa or Employment Permit. A legitimate recruitment agency must not charge you to find a job.",
    (None, "indica uso anterior nos dados oficiais do Department of Enterprise e não garante sponsorship atual — a elegibilidade depende da vaga, do empregador e das regras vigentes na Irlanda."): "indicates past use in official Department of Enterprise data and does not guarantee current sponsorship — eligibility depends on the role, the employer and the rules in force in Ireland.",
    (None, "Consultar as regras oficiais"): "Check the official rules",
    (None, "Certifique-se de que tem direito legal a trabalhar na Irlanda."): "Make sure you have the legal right to work in Ireland.",
    (None, "Procurar localização"): "Search location",
    (None, "Nenhuma localização encontrada."): "No location found.",
    (None, "Filtros ativos:"): "Active filters:",
    (None, "Remover filtro: %s"): "Remove filter: %s",
    (None, "%s oportunidade encontrada"): ["%s opportunity found", "%s opportunities found"],
    (None, "Filtrar (%s filtros ativos)"): "Filter (%s active filters)",
    (None, "Filtros"): "Filters",
    (None, "Fechar filtros"): "Close filters",
    (None, "Nenhuma localização encontrada"): "No location found",
    (None, "Limpar"): "Clear",
    (None, "Mostrar resultados"): "Show results",
    (None, "Nenhuma oportunidade encontrada com esses filtros."): "No opportunities found with these filters.",
    (None, "Licenciada"): "Licensed",
    (None, "Visitar site de %s"): "Visit %s's website",
    (None, "Visitar site"): "Visit website",
    (None, "Ver vagas em %s"): "See openings at %s",
    (None, "registro: %s"): "registration: %s",
    (None, "Ver vagas no site de %s"): "See openings on %s's website",
    (None, "Vagas"): "Openings",
    (None, "Paginação"): "Pagination",
    (None, "← Anterior"): "← Previous",
    (None, "Próximo →"): "Next →",
    (None, "Hoje"): "Today",
    (None, "Amanhã"): "Tomorrow",
    (None, "Saiba mais"): "Learn more",
    (None, "Filtrar por %s"): "Filter by %s",
    (None, "Filtrar por %1$s. Filtro ativo: %2$s"): "Filter by %1$s. Active filter: %2$s",
    (None, "County"): "County",
    (None, "Procurar county"): "Search county",
    (None, "Nenhum county encontrado."): "No county found.",
    (None, "Cidade"): "Town",
    (None, "Procurar cidade"): "Search town",
    (None, "Nenhuma cidade encontrada."): "No town found.",
    (None, "Categoria"): "Category",
    (None, "%s evento encontrado"): ["%s event found", "%s events found"],
    (None, "Nenhum county encontrado"): "No county found",
    (None, "Nenhuma cidade encontrada"): "No town found",
    (None, "Apoiadores em destaque"): "Featured supporters",
    (None, "Apoiador anterior"): "Previous supporter",
    (None, "Próximo apoiador"): "Next supporter",
    (None, "Navegar entre apoiadores"): "Browse supporters",
    (None, "Ir para Apoiador %1$d"): "Go to Supporter %1$d",
    (None, "Filtrar guias por categoria"): "Filter guides by category",
    (None, "na agenda"): "in the agenda",
    (None, "Acessar %s (abre em nova aba)"): "Open %s (opens in a new tab)",
    (None, "Acessar"): "Open",
    (None, "Acessar %s"): "Open %s",
    (None, "Imagem pendente"): "Image pending",
    (None, "Ver fonte da imagem"): "View image source",
    (None, "Ver site oficial"): "Visit official website",
    (None, "Ver no mapa"): "View on map",
    (None, "%s selecionados"): "%s selected",
    (None, "Tipo"): "Type",
    (None, "Características"): "Features",
    (None, "Filtrar por County. Filtro ativo: %s"): "Filter by County. Active filter: %s",
    (None, "Filtrar por County"): "Filter by County",
    (None, "Filtrar por %1$s. Filtros ativos: %2$s"): "Filter by %1$s. Active filters: %2$s",
    (None, "Encontre o que fazer"): "Find things to do",
    (None, "Condado"): "County",
    (None, "%s opção encontrada"): ["%s option found", "%s options found"],
    (None, "Fique por dentro de tudo!"): "Stay on top of everything!",
    (None, "Receba as últimas notícias, eventos e guias práticos diretamente no seu email. Sem spam, apenas conteúdo relevante para brasileiros na Irlanda."): "Get the latest news, events and practical guides straight to your inbox. No spam, only content relevant to Brazilians in Ireland.",
    (None, "Assine nossa newsletter"): "Subscribe to our newsletter",
    (None, "Junte-se a milhares de brasileiros que já recebem nossas atualizações."): "Join thousands of Brazilians who already receive our updates.",
    (None, "Seu melhor email"): "Your best email",
    (None, "Assinar"): "Subscribe",
    (None, "Ao assinar, você concorda com nossa política de privacidade."): "By subscribing, you agree to our privacy policy.",
    (None, "Ver cursos"): "See courses",
    (None, "A vida é uma constante oportunidade de recomeçar. Cada dia é uma nova chance de construir algo melhor."): "Life is a constant opportunity to start over. Every day is a new chance to build something better.",
    (None, "Mário Sérgio Cortella"): "Mário Sérgio Cortella",
    (None, "Compartilhar:"): "Share:",
    (None, "Compartilhar no Facebook"): "Share on Facebook",
    (None, "Compartilhar no X (Twitter)"): "Share on X (Twitter)",
    (None, "Compartilhar no LinkedIn"): "Share on LinkedIn",
    (None, "Copiar link"): "Copy link",
}



# ---------------------------------------------------------------------------
# Minimal PO/MO machinery (no external dependencies).
# ---------------------------------------------------------------------------

def _unescape(s):
    return re.sub(r'\\(.)', lambda m: {"n": "\n", "t": "\t", '"': '"', "\\": "\\"}.get(m.group(1), m.group(1)), s)


def _escape(s):
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n").replace("\t", "\\t")


def parse_po(path):
    """Parse a .po file into (header_blocks, entries)."""
    text = open(path, encoding="utf-8").read()
    blocks = re.split(r"\n\s*\n", text)
    header = []
    entries = []
    for b in blocks:
        if not re.search(r"^msgid", b, re.M):
            header.append(b)
            continue
        blines = b.split("\n")

        def grab(key):
            for i, line in enumerate(blines):
                m = re.match(r"^" + key + r' "(.*?)"\s*$', line)
                if not m:
                    continue
                val = _unescape(m.group(1))
                for cont in blines[i + 1:]:
                    cs = cont.strip()
                    if cs.startswith('"') and cs.endswith('"') and len(cs) >= 2:
                        val += _unescape(cs[1:-1])
                    else:
                        break
                return val
            return None

        entry = {
            "raw_comments": [l for l in blines if l.startswith("#")],
            "ctxt": grab("msgctxt"),
            "id": grab("msgid"),
            "plural": grab("msgid_plural"),
            "str": {},
        }
        for i, line in enumerate(blines):
            m = re.match(r'^msgstr(?:\[(\d)\])? "(.*?)"\s*$', line)
            if not m:
                continue
            idx = int(m.group(1)) if m.group(1) else 0
            val = _unescape(m.group(2))
            for cont in blines[i + 1:]:
                cs = cont.strip()
                if cs.startswith('"') and cs.endswith('"') and len(cs) >= 2:
                    val += _unescape(cs[1:-1])
                else:
                    break
            entry["str"][idx] = val
        if entry["id"] is not None:
            entries.append(entry)
    return header, entries


def render_po(header, entries):
    out = []
    for h in header:
        if h.strip():
            out.append(h.rstrip("\n"))
    for e in entries:
        lines = list(e["raw_comments"])
        if e["ctxt"] is not None:
            lines.append('msgctxt "' + _escape(e["ctxt"]) + '"')
        lines.append('msgid "' + _escape(e["id"]) + '"')
        if e["plural"] is not None:
            lines.append('msgid_plural "' + _escape(e["plural"]) + '"')
            lines.append('msgstr[0] "' + _escape(e["str"].get(0, "")) + '"')
            lines.append('msgstr[1] "' + _escape(e["str"].get(1, "")) + '"')
        else:
            lines.append('msgstr "' + _escape(e["str"].get(0, "")) + '"')
        out.append("\n".join(lines))
    return "\n\n".join(out) + "\n"


def write_mo(path, entries):
    msgs = []
    for e in entries:
        if e["id"] is None:
            continue
        key = (e["ctxt"] + "\x04" + e["id"]) if e["ctxt"] is not None else e["id"]
        if e["plural"] is not None:
            key = e["id"] + "\x00" + e["plural"]
            val = e["str"].get(0, "") + "\x00" + e["str"].get(1, "")
        else:
            val = e["str"].get(0, "")
        if e["id"] == "" :  # header entry carries the metadata string
            val = e["str"].get(0, "")
        msgs.append((key, val))
    msgs.sort(key=lambda kv: kv[0].encode("utf-8"))

    keys = b""
    vals = b""
    koffsets = []
    voffsets = []
    for k, v in msgs:
        k = k.encode("utf-8")
        v = v.encode("utf-8")
        koffsets.append((len(k), len(keys)))
        keys += k + b"\x00"
        voffsets.append((len(v), len(vals)))
        vals += v + b"\x00"

    n = len(msgs)
    keystart = 28 + n * 16
    valuestart = keystart + len(keys)
    out = [struct.pack("<7I", 0x950412DE, 0, n, 28, 28 + n * 8, 0, 0)]
    for ln, off in koffsets:
        out.append(struct.pack("<2I", ln, keystart + off))
    for ln, off in voffsets:
        out.append(struct.pack("<2I", ln, valuestart + off))
    out.append(keys)
    out.append(vals)
    with open(path, "wb") as fh:
        fh.write(b"".join(out))


def main():
    check_only = "--check" in sys.argv
    header, entries = parse_po(PO)

    applied = 0
    missing_table = []   # empty msgstr with no table entry
    stale_table = []     # table entries that matched nothing in the .po
    seen = set()

    for e in entries:
        if e["id"] == "":
            continue  # header
        key = (e["ctxt"], e["id"])
        seen.add(key)
        has_value = any(e["str"].values())
        if has_value:
            continue  # curated catalog entry — preserved byte-for-byte
        if key in T:
            t = T[key]
            if e["plural"] is not None:
                assert isinstance(t, list) and len(t) == 2, f"plural needs [sing,plur]: {key!r}"
                e["str"] = {0: t[0], 1: t[1]}
            else:
                assert isinstance(t, str), f"non-plural needs str: {key!r}"
                e["str"] = {0: t}
            applied += 1
        else:
            missing_table.append(key)

    for key in T:
        if key not in seen:
            stale_table.append(key)

    total_empty_after = sum(1 for e in entries if e["id"] and not any(e["str"].values()))

    print(f"entries: {len(entries)} (excl. header {len(entries) - 1})")
    print(f"applied from table: {applied}")
    print(f"still empty: {total_empty_after}")
    if missing_table:
        print("MISSING TRANSLATIONS (not in table):")
        for k in missing_table:
            print("  -", k)
    if stale_table:
        print("STALE TABLE ENTRIES (matched no .po msgid):")
        for k in stale_table:
            print("  -", k)

    if check_only:
        sys.exit(1 if (missing_table or stale_table) else 0)

    if missing_table:
        print("refusing to write: the catalog would stay incomplete", file=sys.stderr)
        sys.exit(1)

    with open(PO, "w", encoding="utf-8") as fh:
        fh.write(render_po(header, entries))
    write_mo(MO, entries)
    nonempty = sum(1 for e in entries if e["id"] and any(e["str"].values()))
    print(f"wrote {PO} and {MO} ({nonempty} translated entries + header)")


if __name__ == "__main__":
    main()

