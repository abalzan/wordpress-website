<?php
/**
 * Conexão BR Irlanda - Community Portal theme functions
 *
 * LOADER ONLY. This file declares the theme constants and requires the
 * focused modules under inc/ in the documented load order. It contains no
 * application or business logic: every concern lives in its own module.
 *
 * @package Conexao_BR_Irlanda
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CONEXAO_THEME_VERSION', '1.0.0' );
define( 'CONEXAO_THEME_DIR', get_template_directory() );
define( 'CONEXAO_THEME_URI', get_template_directory_uri() );

// ---------------------------------------------------------------------------
// Load order.
//
// The order below preserves the effective execution order of the previous
// monolithic functions.php exactly (the modules were cut out of it in source
// order). Two ordering constraints are load-bearing and must not change:
//
//   1. inc/i18n.php must load first so the Stage 1 locale correction
//      (en_US -> pt_BR) applies to everything after it, including SEO.
//   2. inc/i18n/ must load before inc/rest-language.php and inc/seo/, which
//      build on the language helpers (conexao_current_locale(),
//      conexao_lang_url(), conexao_lang_term()).
//
// Modules that only define functions and register hooks are otherwise
// order-independent at load time; within each module the original source
// order is preserved, so hook registration order is unchanged.
// ---------------------------------------------------------------------------

// -- Foundation: locale -----------------------------------------------------
require_once CONEXAO_THEME_DIR . '/inc/i18n.php';

// -- Language: Polylang capability, locale, URLs, terms, fallback,
//    hreflang and switcher (was inc/polylang.php) --------------------------
require_once CONEXAO_THEME_DIR . '/inc/i18n/guard.php';
require_once CONEXAO_THEME_DIR . '/inc/i18n/locale.php';
require_once CONEXAO_THEME_DIR . '/inc/i18n/urls.php';
require_once CONEXAO_THEME_DIR . '/inc/i18n/terms.php';
require_once CONEXAO_THEME_DIR . '/inc/i18n/fallback.php';
require_once CONEXAO_THEME_DIR . '/inc/i18n/hreflang.php';
require_once CONEXAO_THEME_DIR . '/inc/i18n/switcher.php';

// -- REST: the bilingual REST contract (Stage 4.1) -------------------------
// Language-aware ?lang= collection filtering, the conexao_language record
// metadata (lang / is_fallback / translations), the detail-endpoint language
// rules and the REST event-status gate. All hooks are guarded by
// conexao_polylang_active(), so a single-language site behaves exactly as
// before. Untouched by the Stage F modularisation.
require_once CONEXAO_THEME_DIR . '/inc/rest-language.php';

// -- SEO: titles, meta, canonical, hreflang, Open Graph, robots, schema,
//    sitemap, redirects (was inc/seo.php) ----------------------------------
// The order below is the original source order of inc/seo.php, so the wp_head
// emission sequence (and therefore the rendered tag order) is unchanged.
require_once CONEXAO_THEME_DIR . '/inc/seo/titles.php';
require_once CONEXAO_THEME_DIR . '/inc/seo/meta.php';
require_once CONEXAO_THEME_DIR . '/inc/seo/canonical.php';
require_once CONEXAO_THEME_DIR . '/inc/seo/hreflang.php';
require_once CONEXAO_THEME_DIR . '/inc/seo/open-graph.php';
require_once CONEXAO_THEME_DIR . '/inc/seo/robots.php';
require_once CONEXAO_THEME_DIR . '/inc/seo/schema.php';
require_once CONEXAO_THEME_DIR . '/inc/seo/sitemap.php';
require_once CONEXAO_THEME_DIR . '/inc/seo/redirects.php';
require_once CONEXAO_THEME_DIR . '/inc/seo/related.php';

// -- Empregos data sources --------------------------------------------------
// Optional "Mais informações" link field + helpers for page-empregos.php.
require_once CONEXAO_THEME_DIR . '/inc/empregos-landing.php';
// The "Onde procurar emprego" job-search resources.
require_once CONEXAO_THEME_DIR . '/inc/job-resources.php';
// The "Agências de recrutamento" recruitment-agencies data source.
require_once CONEXAO_THEME_DIR . '/inc/recruitment-agencies.php';
// The "Empresas com histórico de Employment Permits" data source.
require_once CONEXAO_THEME_DIR . '/inc/permit-employers.php';
// The shared Employment Opportunities data/model layer over the three
// employment data sources. Data only - renders no UI.
require_once CONEXAO_THEME_DIR . '/inc/employment-opportunities.php';
// View counting into _conexao_view_count, powering the homepage "Mais Lidos".
require_once CONEXAO_THEME_DIR . '/inc/post-views.php';
// Accent-insensitive search over the native post_title/excerpt/content columns.
require_once CONEXAO_THEME_DIR . '/inc/search.php';

// -- Theme modules (were the body of the old functions.php) ------------------
// The order below is the original source order of the old functions.php body,
// so hook registration order on shared hooks (init, delete_post) is unchanged.
require_once CONEXAO_THEME_DIR . '/inc/admin.php';
require_once CONEXAO_THEME_DIR . '/inc/queries.php';
require_once CONEXAO_THEME_DIR . '/inc/setup.php';
require_once CONEXAO_THEME_DIR . '/inc/assets.php';
require_once CONEXAO_THEME_DIR . '/inc/performance.php';
require_once CONEXAO_THEME_DIR . '/inc/cache.php';
require_once CONEXAO_THEME_DIR . '/inc/content.php';
require_once CONEXAO_THEME_DIR . '/inc/sponsors.php';
require_once CONEXAO_THEME_DIR . '/inc/customizer.php';
require_once CONEXAO_THEME_DIR . '/inc/archive-filters.php';
require_once CONEXAO_THEME_DIR . '/inc/leisure.php';
require_once CONEXAO_THEME_DIR . '/inc/events.php';
require_once CONEXAO_THEME_DIR . '/inc/archive-query.php';
require_once CONEXAO_THEME_DIR . '/inc/b2-fallback.php';
require_once CONEXAO_THEME_DIR . '/inc/shortcodes.php';
require_once CONEXAO_THEME_DIR . '/inc/ui.php';
require_once CONEXAO_THEME_DIR . '/inc/navigation.php';

		// WordPress core expects properties such as `menu_name` and
