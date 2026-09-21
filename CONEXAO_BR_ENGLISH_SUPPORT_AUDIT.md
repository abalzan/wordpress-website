# Conexão BR Irlanda — English-Language Support Audit

- **Date:** 2026-09-20
- **Scope:** Audit only. No plugins installed, no code changed, no content created, no multilingual system activated.
- **Production site:** https://conexaobr.ie/ (WordPress.com + Jetpack)
- **Local dev:** Docker (`wordpress:7.0.2-php8.5-apache`, MySQL), repo `master` @ `9cb0511`

## Canonical verification (explicitly requested, do not assume)

| Check | Result |
|---|---|
| `<link rel="canonical">` on https://conexaobr.ie/ | `https://conexaobr.ie/` (self-canonical, emitted by theme `inc/seo.php`) |
| `og:url` | `https://conexaobr.ie/` |
| https://conexaobr.com/ | **A different, unrelated WordPress site** — title `CXBR Investimentos`, theme `hello-elementor`, self-canonical to itself. It is **not** the canonical of this site and links nowhere to it. |
| Repo references to `conexaobr.com` | None found in any PHP/JS/CSS/.htaccess/docs. |
| `hreflang` links | **None anywhere on the site.** |
| `<html lang>` (production) | `en-US` (WordPress locale is `en_US`; see §1.6 — a mismatch worth noting) |
| `og:locale` | `pt_BR` — **hardcoded** in `inc/seo.php` line 261 |

**Conclusion:** canonical domain is `conexaobr.ie`. `conexaobr.com` belongs to a different project and must not be treated as related infrastructure.

---

## 1. Current Architecture (Phase 1)

### 1.1 Platform

| Item | Observation |
|---|---|
| WordPress | 7.0.2 (local Docker image); production is **WordPress.com** (Business-class plan implied — custom plugins/themes install via ZIPs per `docs/deployment.md`) |
| Jetpack | Present on production (`16.3-a.1` observed). Owns `sitemap.xml`, `news-sitemap.xml`, `image-sitemap-index-1.xml`, `i0.wp.com`/`c0.wp.com` CDN |
| PHP | 8.5 (local). Production version not directly observable. |
| Database | MySQL, utf8mb4 / utf8mb4_unicode_ci |
| Permalinks | `/%postname%/`; CPT archives with Portuguese slugs, `with_front => false` |
| Build tooling | **None.** Vanilla JS/CSS, plain files, `filemtime()` cache-busting |

### 1.2 Active theme

- `wp-content/themes/conexao-br-irlanda/` — v1.0.0, **standalone** (no parent, **no child theme**), only theme in the repo.
- `style.css` declares `Text Domain: conexao-br-irlanda` and tag `translation-ready`.
- `functions.php` calls `load_theme_textdomain( 'conexao-br-irlanda', CONEXAO_THEME_DIR . '/languages' )` — **but the `languages/` directory does not exist**; there are **zero** `.po` / `.mo` / `.pot` files anywhere in the repo. The textdomain is declared and loaded, but no translations have ever been built.

### 1.3 Custom plugins (7; no third-party plugins bundled)

| Plugin | Version | Purpose | Multilingual relevance |
|---|---|---|---|
| `conexao-data-model` | 1.3.0 | CPTs, taxonomies, editorial meta | CPT rewrite slugs (Portuguese) are part of URL architecture |
| `conexao-content` | 1.0.0 | Static pages + `[conexao_grid]`, `[conexao_blog_categories]` shortcodes | `create-pages.php` seeds all static pages with **Portuguese Gutenberg content** |
| `conexao-admin-ux` | 1.0.0 | Admin UI, statuses, leisure image workflow (~54 hardcoded PT admin strings) | Admin-only; not user-facing |
| `conexao-event-runtime` | 1.2.1 | Production event meta, `conexao_town`, `_event_status` gate, recurrence model/evaluator/query helper | Identity layer is language-neutral (§8) |
| `conexao-event-importer` | 1.7.1 | Local-only event import/export (source + source_id + UUID dedup) | See §8 — critical |
| `conexao-leisure-migration` | 2.0.0 | Lazer ZIP export/import, `_lazer_uuid` | §9 |
| `conexao-sponsor-migration` | 1.1.0 | Apoiadores JSON export/import + embedded images | Same pattern as Lazer |

**Load order matters:** data-model → content → admin-ux → event-runtime → event-importer (local only) → leisure-migration → sponsor-migration.

### 1.4 Content model

| Post type | Archive URL | Public single | Notes |
|---|---|---|---|
| `guide` | `/guias/` | Yes | Practical guides |
| `event` | `/eventos/` | Yes | Imported + manual; visibility gated by `_event_status` |
| `job` | *disabled* | Yes (`/empregos/{slug}/`) | `/empregos/` is a static page (Jobs Landing) |
| `sponsor` | `/apoiadores/` | Yes | `single-sponsor.php` |
| `course_provider` | `/cursos/` | **No** (links externally) | Meta `_provider_category`, `_provider_url` |
| `leisure` | `/lazer/` | Yes (`single-leisure.php`) | Rich meta model, local Media Library images |
| `recruitment_agency` | none | No | Admin-only data container rendered inside `/empregos/` |
| `permit_employer` | none | No | Admin-only data container rendered inside `/empregos/` |

Taxonomies (shared): `conexao_category` (slug `categories`), `conexao_county` (`counties`), `conexao_tag` (`tags`), `conexao_town` (`towns`, events only). **Term names are Portuguese** (Moradia, Empregos, Saúde, Família, Transporte, Finanças, Benefícios…; leisure attributes: Natureza, História, Castelos, Caminhadas…). Term slugs are Portuguese keywords too (e.g. `moradia`), and slugs appear inside filter URLs (`/guias/?categoria=moradia`).

### 1.5 Template hierarchy & frontend

- Templates: `front-page.php`, `archive.php` (shared by all CPT archives), `page-empregos.php`, `page-landing.php`, `single-leisure.php`, `single-sponsor.php`, `single.php`, `page.php`, `home.php` (blog), `search.php`, `404.php`, plus ~25 shared `template-parts/` (cards, filters, pagination, newsletter, hero, employment directory, etc.).
- SEO is **built into the theme** (`inc/seo.php`, 1413 lines): titles, meta descriptions, canonical, Open Graph (`og:locale` hardcoded `pt_BR`), Twitter cards, schema.org, breadcrumbs, a custom lightweight XML sitemap (core sitemap disabled via `wp_sitemaps_enabled` filter), robots.txt, and ~60+ English/Legacy→Portuguese 301 redirects. **No SEO plugin.**
- JS: single deferred `assets/js/main.js` (2001 lines) — dark-mode toggle, mobile menu/search overlays, copy buttons, leisure multi-select filter URL-state machine, sponsor carousel, **fetch()-based infinite scroll** (fetches the next HTML page and parses it — no REST JSON on the frontend). `job-resources-admin.js` is admin-only.
- CSS: `design-system.css` → `header-nav.css` → `main.css` → `leisure.css` → `dark-mode.css`, all on design-token CSS variables; no page-specific hacks.
- Gutenberg: pages are block content; CPTs registered `show_in_rest`; no reused/pattern blocks observed.

### 1.6 Language / i18n state today

- **Site locale is `en_US`** (production renders `<html lang="en-US">` and dates in English: "September 6, 2026"). All *content* is Portuguese; the *core locale* is English-US. This mismatch is the first thing an i18n effort must resolve.
- `og:locale` is hardcoded `pt_BR` regardless of locale (`inc/seo.php:261`).
- 446 gettext calls in theme PHP (domain `conexao-br-irlanda`); **~75%+ of user-facing template strings are already gettext-wrapped** — but with **no translation files**, every string renders as its Portuguese source string.
- Plugins declare text domains (`conexao-content`, `conexao-admin-ux`, …) and use `__()`/`esc_html__()` in places, but **no plugin calls `load_plugin_textdomain()`**, so plugin gettext strings can never currently be translated.
- **No multilingual plugin is installed** (verified in compose mounts and the plugin inventory; production surface = Jetpack + the 7 custom ZIPs).
- **No language switcher, no hreflang, no locale negotiation** exists anywhere.

## 2. Complete Language-Surface Inventory (Phase 2)

Classification: **A** static theme/UI, **B** database content, **C** taxonomy/term content, **D** custom field content, **E** system/WordPress, **F** third-party/external, **G** generated/derived, **H** unknown.

### 2.1 Global

| Surface | Class | Current state |
|---|---|---|
| Primary navigation | A + B | Menu item titles in DB (PT); runtime filters in `functions.php` override labels to hardcoded PT (`Início`, `Lazer e turismo`, insert fallback `Blog`) |
| Mobile menu / drawer | A | Same `primary` menu; overlay chrome strings gettext-wrapped |
| Search (form, overlay, results, empty state) | A + E | Form labels gettext; `searchform.php` uses `get_search_query()`; results page PT labels |
| Buttons / CTAs ("Ver todas", "Acessar", "Assinar"…) | A | Overwhelmingly gettext-wrapped already |
| Footer | A + B | Static PT strings + Customizer `conexao_footer_text` (DB, PT) |
| Newsletter section | A + F | Theme template gettext; actual subscription form is Jetpack (subscribers emails; form labels/theme-controlled) |
| Cookie/privacy controls | A + B | No cookie banner exists; `/politica-de-privacidade/`, `/termos-de-uso/`, `/cookies/` are **DB pages with PT content** |
| Forms | A | No site forms besides search + newsletter (Jetpack). Contact is page content only |
| Breadcrumbs | G | Generated in `inc/seo.php`; includes hardcoded PT `Início` (L666) |
| Pagination | A | `template-parts/pagination.php` — gettext (`← Anterior` / `Próximo →`) |
| Accessibility labels (aria-label, sr-only) | A | Mostly gettext-wrapped in templates; **exceptions in JS** (§3) |
| Error/empty states (404, no results) | A | `404.php` / `content-none.php` — gettext-wrapped (14–15 calls each) |

### 2.2 Per-section

| Section | Surfaces needing English | Class |
|---|---|---|
| **Guides** | Titles, excerpts, body, category terms, date formats, reading time, CTAs | B, C, A |
| **Blog** | Titles, excerpts, body, native categories, author, pagination | B, C, E |
| **Events** | Title, description, category, county, town, venue meta, banner, recurring labels, registration CTA | B, C, D, A |
| **Lazer** | Title, description, category/type, characteristics (`conexao_leisure_attribute`), county/town, practical notes, attribution, map/website CTAs | B, C, D, A |
| **Empregos** | Landing page body (DB), filters (`?tipo/area/localizacao/contrato`), agency + permit-employer card data (meta), safety/Instagram guidance | B, D, A |
| **Courses** | Titles, excerpts, category meta, external link CTAs | B, D |
| **Sponsors** | Titles, descriptions, contacts (DB/meta), single-sponsor CTAs | B, D |
| **Contact / Sobre Nós / static pages** | Entire DB page bodies (PT Gutenberg blocks) | B |
| **Homepage** | Hero title/subtitle (Customizer), quick-access card labels, section headings, cached transients | B + A |

### 2.3 Key derived surfaces

- **SEO layer (G):** `inc/seo.php` generates meta titles/descriptions/OG/Twitter/JSON-LD from templates with hardcoded PT pattern strings (§3) + DB meta descriptions.
- **Sitemap (G):** custom theme sitemap (pages, CPTs, taxonomy terms) + **Jetpack sitemap/news-sitemap on production** (observed generator `jetpack-16.3-a.1`).
- **Robots.txt (G):** custom in `inc/seo.php`; disallows `/wp-json/`, `/search/`, `/?s=`, `/page/`.
- **Feed (F):** Jetpack/core RSS — language comes from post content + site locale.
- **Jetpack widgets/subscriptions (F):** subscribe prompts; partially theme-controlled, partially Jetpack-controlled strings.

### 2.4 Admin/editorial strings

- `conexao-admin-ux` carries ~54 hardcoded PT admin strings (`class-config.php`), plus event-status UI labels in `conexao-event-runtime` and importer admin pages. **These are wp-admin only — not user-visible on the site** — but they determine whether *editors* can operate in English.

## 3. Hard-Coded Portuguese Audit (Phase 3)

Method: full-tree scan (theme PHP + JS + plugin PHP) for PT strings **outside** gettext calls, plus manual reading of all templates. Findings below are the real gaps; everything else user-facing is already gettext-wrapped.

### 3.1 User-facing hard-coded Portuguese (must be externalized for English)

| # | File | Location | String(s) | Category | Gettext? | Safe to externalize? | Should translate? | Risk |
|---|---|---|---|---|---|---|---|---|
| 1 | `wp-content/themes/conexao-br-irlanda/functions.php` | ~L2437–2495 `conexao_event_recurrence_label()` | Weekday names `segunda-feira`…`domingo` (hardcoded array), `Toda %s` format, `-feira` stripping logic | A | **No** | Yes | **Yes** | **MEDIUM** — weekday-name mapping and PT grammar (`-feira` drop, `e` joining) are language-specific; English needs "Every Wednesday" logic, not just strings |
| 2 | `functions.php` | ~L3308 `conexao_modify_primary_nav_items()` | `Início` (Home label override) | A | No | Yes | Yes | LOW — runtime nav override |
| 3 | `functions.php` | ~L3318 (same filter) | `Lazer e turismo` (label rename) | A | No | Yes | Yes | LOW |
| 4 | `inc/seo.php` | L35 | `' | Guia Prático | '` (title pattern) | G | No | Yes | Yes | LOW |
| 5 | `inc/seo.php` | L97 | `Página não encontrada | ` (404 title) | G | No | Yes | Yes | LOW |
| 6 | `inc/seo.php` | L139, L143, L153 | Archive/404 meta descriptions (`Guias práticos completos…`, `Vagas de emprego…`, `Página não encontrada…`) | G | No | Yes | Yes | LOW |
| 7 | `inc/seo.php` | L261 | `og:locale` = `pt_BR` (hardcoded attribute, not text) | G | n/a | Yes (make dynamic) | n/a (needs `pt_BR`/`en_IE` switch) | LOW |
| 8 | `inc/seo.php` | L666 | `Início` (breadcrumb root) | G | No | Yes | Yes | LOW |
| 9 | `assets/js/main.js` | L235 | `Link copiado` (copy-button feedback) | A | No (no `wp_localize_script` exists) | Yes | Yes | LOW — requires adding a strings-injection mechanism |
| 10 | `assets/js/main.js` | L781 | `Filtrar (N filtros ativos)` (aria-label) | A (a11y) | No | Yes | Yes | LOW |
| 11 | `assets/js/main.js` | L1432 | `Apoiador X de Y` (carousel live status) | A (a11y) | No | Yes | Yes | LOW |
| 12 | `assets/js/main.js` | L1867, L1943 | `eventos` / `cursos` noun choice + `Mais N eventos/cursos carregados.` (infinite-scroll status) | A | No | Yes | Yes | LOW — includes singular/plural logic |
| 13 | `assets/js/main.js` | ~L571, L627… (comments only) | `Mostrar resultados` appears in comments; the rendered button label comes from `template-parts/leisure-filters.php` (gettext-wrapped) | A | Yes (server side) | n/a | n/a | NONE |
| 14 | `inc/seo.php` | redirect tables | English/Legacy path→PT mappings (behavioral, not text) | G | n/a | Special (see §6) | n/a | **HIGH** — these 301s collide with a future `/en/` URL space |

### 3.2 Database-seeded hard-coded Portuguese (not code strings)

| File | What |
|---|---|
| `wp-content/plugins/conexao-content/create-pages.php` | All static page titles, bodies, meta descriptions in PT (Início, Sobre Nós, Contato, county pages, privacy/terms…) — seeded once into the **production DB**, not re-rendered from code |
| `wp-content/plugins/conexao-content/seed-leisure.php` | 20 PT leisure seed records (Trinity College…, categories História/Castelos/Caminhadas…) |
| `wp-content/plugins/conexao-data-model/conexao-data-model.php` | CPT labels + default term seeds (PT) — DB-side effect on activation |
| `wp-content/plugins/conexao-admin-ux/includes/class-config.php` | ~54 PT admin field labels/help texts (`Descrição curta`, `Cidade / Vila`…) — **wp-admin only** |
| `wp-content/plugins/conexao-admin-ux/includes/class-fields.php` L1103 | Recurrence warning text (admin-only) |
| `inc/empregos-landing.php` L70 | `'Jobs — Link "Mais informações"'` — admin metabox label only |

### 3.3 CSS `content:` values / REST-generated / AJAX strings

- No PT strings found in CSS `content:` properties.
- No REST-generated PT output on the frontend (frontend uses no REST JSON; `robots.txt` disallows `/wp-json/`).
- Admin AJAX strings (`class-wikimedia-client`, `job-resources-admin.js`) are wp-admin only.

### 3.4 Strings that should NOT be translated

- Admin-only plugin labels (§3.2 last three rows) — low priority; translating them only matters if editors work in English.
- Proper nouns kept in original language (sponsor names, event source names, county names "Dublin"/"Cork", leisure destination names).

### 3.5 Overall gettext coverage assessment

- Theme templates/parts: ~85–95% gettext-covered → **building one `pt_BR`+`en` .mo pair would translate the bulk of the UI instantly.**
- Remaining gaps are exactly items 1–14 above (~25 strings + the recurrence label logic + the redirect architecture).
- Plugins: gettext present but **no `load_plugin_textdomain()`** — a required fix for any locale-based approach.

## 4. Database / Content Inventory (Phase 4)

### 4.1 Where Portuguese content lives today

| Content store | Location | English counterpart needed? |
|---|---|---|
| Post/page bodies (Gutenberg) | `wp_posts` — guides, blog, events, pages, jobs, sponsors | Yes — every user-facing post/page |
| Titles, excerpts, slugs | `wp_posts` | Titles/excerpts yes; **slugs must stay Portuguese-canonical** (architecture rule #1) |
| Shared taxonomy terms | `wp_terms` — `conexao_category`, `conexao_county`, `conexao_tag` | **Names** need EN translations; slugs/filters are language-shared |
| `conexao_town` | `wp_terms` | Mostly proper nouns (Dublin, Dungarvan) — largely language-neutral |
| Leisure attributes | `conexao_leisure_attribute` terms | Names need EN (Natureza→Nature, etc.) |
| Post meta (PT free text) | `wp_postmeta`: `_leisure_*` (descriptions, practical notes), `_agency_*`, `_employer_*`, `_sponsor_*`, `_meta_description`, event venue/banners | Yes — all human-readable text |
| Post meta (language-neutral) | Dates, times, URLs, UUIDs, statuses, attachment IDs, license/attribution keys | No — must stay shared |
| Image alt text / captions | Media Library fields + `_leisure_image_alt_text` | Yes (low effort, a11y) |
| Menus | `nav_menu_item` posts + `functions.php` runtime overrides | Titles need per-language override layer |
| Widgets | `sidebar-1` | Currently minimal; per-language widgets would be needed if used |
| Customizer | `conexao_hero_title`, `conexao_hero_subtitle`, `conexao_footer_text`, social links | Hero/footer text need EN variants |
| Transients | `conexao_home_*`, `conexao_404_*`, event query cache | **Must be keyed by language** once two languages render the homepage |
| Jetpack data | Subscriptions, sitemap, CDN | Sitemap/news-sitemap would gain EN URLs |

### 4.2 Translated-like alternates that already exist

- None. There is no `*_en` meta, no duplicate English records, no language meta anywhere in the data model (verified via meta-key scans of `class-meta.php`, `class-config.php`, event runtime registration).
- The only "English" traces are the historical 301 redirect tables (`.htaccess`, `inc/seo.php`) mapping old English URLs to PT canonicals.

### 4.3 Which content actually requires an English counterpart (volume estimate)

| Content type | Count (production, approximate) | English needed? |
|---|---|---|
| Guides (posts) | ~50–100 (growing) | Yes — the core English value proposition |
| Blog posts | ~30–60 | Optional; decide per-post |
| Events | hundreds (rolling, imported) | **Often already English** (external Irish sources: Eventbrite, Motorsport Ireland, Laois Tourism…) — title/content frequently EN already |
| Lazer | ~20–40 curated | Yes for descriptions/practical notes |
| Sponsors | ~15–30 | Yes (short) |
| Course providers | ~10–20 | Yes (short) |
| Jobs/agency/permit records | ~30–60 | Yes (short descriptions) |
| Static pages | ~20 (incl. county/category landing pages) | Yes for key pages; can be deferred for county templates |
| Taxonomy terms | ~30–50 names | Yes (name only) |

Note: **machine translation is explicitly out of scope**; all DB-content English must be human-authored.

## 5. Multilingual Architecture Options (Phase 5) — evaluation only, no selection

### Option A — Polylang (Free/Pro)

| Dimension | Assessment |
|---|---|
| Implementation effort | MEDIUM. Term/post language assignment, hreflang, switcher out of the box; theme already gettext-heavy (good fit). |
| Migration effort | LOW–MEDIUM. Set default language `pt_BR`; existing content stays put. |
| Existing PT URLs | **Preserved** — Polylang keeps default-language URLs unchanged (PT stays `/guias/…`). |
| SEO | Good: hreflang, localized canonicals, language switcher. Needs the custom `inc/seo.php` layer taught about per-language titles/og:locale. |
| REST | Standard `lang=` filtering; `show_in_rest` already on. |
| Taxonomies | Shared terms get per-language names (term translations); slugs can stay PT/shared. |
| Menus | Per-language menus supported (fits the runtime label-override filters). |
| Search | Respects language via query filter. |
| Sitemap | Theme custom sitemap + Jetpack sitemap must include alternates — custom work. |
| Caching | Homepage transients need lang keying; Polylang provides API for it. |
| Editorial workflow | Translation editor UI (Pro); missing-translation behavior configurable. |
| Custom-plugin compatibility | Good — all plugins are meta/`WP_Query` based; watch `pre_get_posts` interplay with the `_event_status` gate (filter order matters). |
| Event importer/runtime | Importer runs in admin (default language) → writes PT posts; language meta must be forced to `pt_BR` on import so dedup/identity is unaffected. UUIDs/source IDs untouched. |
| Lazer | ZIP import must force language; meta content is per-post — compatible. |
| Flutter/REST app | `?lang=en` filtering works well for a future app. |
| Rollback | Moderate: deactivate plugin + remove term-language data (mostly reversible). |
| Production risk | MEDIUM. WordPress.com Business supports it, but it's a third-party plugin touching every query — needs staging validation against `inc/seo.php` and the event gate. |

### Option B — WPML

| Dimension | Assessment |
|---|---|
| Implementation effort | HIGH (heavier stack: string translation, translation management, extra DB tables). |
| Migration | MEDIUM (language assignment + string-translation setup). |
| PT URLs | Preserved (default language keeps original URLs). |
| SEO | Excellent tooling but heavier; must still integrate the custom SEO layer. |
| REST | Supported via `wpml_language` param; heavier than Polylang. |
| Custom-plugin compatibility | Same caveats as Polylang plus larger footprint; more hooks that can conflict with the `pre_get_posts` event gate and admin-ux. |
| Everything else | Similar coverage to Polylang with features (translation memory, XLIFF) this project doesn't need yet. |
| Rollback/Risk | HIGHER risk and rollback complexity than Polylang due to more tables/options. |

### Option C — Locale-only (build .po/.mo files, fix locale, keep PT content)

| Dimension | Assessment |
|---|---|
| Implementation effort | LOW–MEDIUM: create the `languages/` dir, generate POT, translate UI strings to EN, add `load_plugin_textdomain()` everywhere, fix the `en_US`-locale oddity, make `og:locale` dynamic. |
| Migration | NONE (no data model change). |
| PT URLs | Untouched. |
| SEO | No hreflang/canonical issues (one language served). |
| Limits | **Does not produce an English site** — only makes the *UI chrome* translatable. DB content remains PT. Useful as Stage-1 groundwork regardless of the final option. |
| Risk | Very low. |

### Option D — Custom locale/meta implementation (language as post meta + `/en/` rewrites, no plugin)

| Dimension | Assessment |
|---|---|
| Implementation effort | HIGH: custom rewrites for every CPT/page, custom switcher, custom hreflang, per-language meta read/write, custom search/filter/sitemap integration. Duplicates what mature plugins already solve. |
| Migration | MEDIUM: create EN records/fields. |
| PT URLs | Preserved by design. |
| SEO | Fully controllable, but everything hand-rolled (higher defect risk). |
| Custom plugins | Search, filters, SEO, sitemap, transients and the importer all need hand-taught language logic — the **largest** compatibility surface of any option. |
| Rollback | Easy-ish (own code), but high chance of long-tail bugs. |
| Risk | HIGH (hand-rolled i18n is a classic long-tail bug source). |

### Option E — Separate English CPT records linked to PT records

| Dimension | Assessment |
|---|---|
| Implementation effort | HIGH: EN clones of 8 CPTs or an EN "translation" CPT per type; every template, filter, SEO and query helper needs dual-type logic. |
| Migration | MEDIUM. |
| PT URLs | Preserved. |
| SEO | Per-language URLs straightforward; hreflang hand-built. |
| Data integrity | **Dangerous for events/lazer**: duplicating records breaks the source+source_id identity layer (duplicate events in archives unless every query excludes EN duplicates). |
| Rollback | Hard (data spread across duplicate records). |
| Risk | HIGH. |

### Option F — Subdomain (`en.conexaobr.ie`)

- Requires a separate WordPress install (or complex routing) → duplicates plugin/content management, breaks the shared-taxonomy single-DB editorial flow.
- On WordPress.com, mapping a second subdomain to a second site doubles hosting cost and doubles every custom-plugin deployment.
- Verdict: highest operational cost; only worth it for full site separation.

### Cross-cutting note (all options)

- **Locale oddity:** the site currently runs `en_US` as the *WordPress* locale with PT content. Whatever option is chosen, decide the canonical locale model first (site default `pt_BR`, per-request English via `/en/`), because `<html lang>`, date formatting, pluralization and gettext loading all depend on it. Today dates already render in English ("September 6, 2026") while content is Portuguese — inconsistent in both directions.
- **Jetpack constraint:** the production `sitemap.xml` / `news-sitemap.xml` come from Jetpack; any hreflang/alternates implementation must either extend the theme's custom sitemap or coordinate with Jetpack — neither is plug-and-play.

## 6. URL / SEO Architecture Assessment (Phase 6)

### 6.1 Current URL strategy

- **Portuguese slugs are canonical** (architecture rule #1): `/guias/`, `/eventos/`, `/cursos/`, `/empregos/`, `/apoiadores/`, `/lazer/`, `/blog/`, PT static pages (`/irlanda/`, `/sobre-nos/`, `/contato/`…).
- **English URLs 301-redirect to Portuguese today, at two layers:**
  1. `.htaccess` (Apache): `/guides/` → `/guias/`, `/events/` → `/eventos/`, `/jobs/`, `/courses/`, `/sponsors/`, `/leisure/`, plus static-page rules (verified live: `https://conexaobr.ie/guides/` → 301 `https://conexaobr.ie/guias/`).
  2. `inc/seo.php` `conexao_seo_redirects()` at `template_redirect` priority 5: English single paths (`/events/{slug}` → `/eventos/{slug}/` etc.), legacy guide paths, `/post/{slug}` → `/blog/{slug}/`, and a direct 301 map with ~60+ entries.
- Filters are query-parameter based and **shared across languages by design**: `?categoria=`, `?county=`, `?cidade=`, `?atributo=`, `?tipo/area/localizacao/contrato` — all built via shared URL helpers (`conexao_event_filter_url()`, `conexao_leisure_filter_url()`).
- Pagination: native WP `/page/N/` (+ `?pagina=` for leisure per docs); robots.txt disallows `/page/`.

### 6.2 Should English be `/en/...`?

**Yes — `/en/` path prefix is the only structure that fits this codebase.** Reasons:

1. The domain is already `conexaobr.ie` (country TLD, single canonical). A subdomain doubles ops (Option F rejected). Language negotiation by IP/cookie is fragile and harms caching/CDN and SEO clarity.
2. `/en/` preserves every existing PT URL byte-for-byte — the hard architecture rule — while Polylang/WPML-style default-URL retention achieves the same result.
3. The existing English→PT 301 redirect table must be **partially retired**: e.g. `/guides/` currently 301s to `/guias/`. If English launches, those redirects (or at least the ones mapping *English content* vs. *legacy garbage*) need a decision: keep for legacy inbound links, or become the English pages. **This is the single riskiest SEO interaction in the whole project** and must be handled with a redirect-inventory audit (which 301s carry real inbound link equity vs. defensive).
4. Slug collision risk: `job` CPT singles live at `/empregos/{slug}/`; English would need `/en/empregos/{slug}/` or `/en/jobs/{slug}/` — the CPT rewrite slugs are Portuguese, so `/en/` prefixing must wrap the full path including the CPT slug (Polylang-style default behavior with `force_lang=1`).

### 6.3 Per-surface SEO implications

| Surface | Implication |
|---|---|
| Canonicals | `inc/seo.php` must emit per-language canonicals (`/en/...` vs PT). Currently self-canonical on conexaobr.ie. |
| hreflang | None today. Must add `pt_BR`/`en` (+`x-default`) pairs, or Jetpack's sitemap must be augmented. |
| Sitemaps | Two producers exist (theme custom sitemap; Jetpack on production). English URLs must be added consistently; duplicate-sitemap drift is a real risk. |
| robots.txt | Custom in theme; would need `/en/search/`, `/en/page/` patterns added if those paths exist. |
| Redirects | The 301 tables in `.htaccess` + `inc/seo.php` (English→PT) collide with the new `/en/` space — inventory and retire carefully. |
| Duplicate content | Guided by hreflang + distinct content; events imported from EN sources would exist once (language-shared) — no duplication if not duplicated as posts. |
| Social metadata | `og:locale` hardcoded `pt_BR` must become dynamic; OG/Twitter per-language strings come from `inc/seo.php` templates. |
| Structured data | JSON-LD (Article, TouristAttraction, breadcrumbs) in `inc/seo.php` — needs per-language `inLanguage` and localized breadcrumb names. |
| Breadcrumbs | Generated with hardcoded PT `Início` root — externalize. |
| Language switcher | Does not exist; must link the language-equivalent URL (or fallback per §12). |
| Pagination | `paginate_links` is language-transparent — fine under `/en/` prefix. |
| Filtered archives | Shared query params under `/en/…` prefix; helpers already centralize URL building (good). |
| Search URLs | `/?s=` / `/search/` disallowed in robots — mirror behavior for `/en/`. |
| conexaobr.com | Unrelated site (verified). Do not build hreflang or redirects touching it. |

## 7. REST / API Implications

- The frontend **does not consume REST JSON** (verified: `main.js` only `fetch()`es HTML pages for infinite scroll and one same-origin helper). Robots.txt disallows `/wp-json/` for crawlers, but the REST API remains technically open (unauthenticated reads).
- All CPTs are registered `show_in_rest => true` (Gutenberg), and event meta is REST-visible — so a future **Flutter app** would consume `wp-json/wp/v2/…`.
- Implications for English:
  - **Polylang:** REST becomes language-filtered (`?lang=en`) and exposes `_links['translations']` — a clean contract for future Flutter English support.
  - **WPML:** REST language via `wpml_language` param / per-language URL namespaces — heavier contract.
  - **Custom meta option:** needs a hand-built `lang` query var + `rest_{$type}_query` filters — more code, full control.
  - Human-readable meta (`_leisure_*`, `_agency_*`) needs per-language variants or a documented "language of the record" contract for the app.
  - The `_event_status` gate also applies to REST event queries; language filtering must compose with it, never bypass it.

## 8. Event Importer / Runtime Implications (Phase 7 — critical)

### 8.1 Identity vs. content

| Layer | Fields | Language-neutral? |
|---|---|---|
| Production identity | `_event_source` + `_event_source_id` (primary dedup), `_event_export_uuid`, `_event_url` fallback, title+date heuristic | **Yes — must remain untouched by any language layer** |
| Scheduling | `_event_date`, `_event_start_time`, `_event_end*`, `_event_recurrence*` (ISO weekday codes 1–7, CSV, end date) | Yes — pure data |
| Lifecycle | `_event_status` (`published`/`expired`/`source_not_found`/`rejected`), expiry marking, cleanup | Yes |
| Content | `post_title`, `post_content`, excerpt, venue/banner meta, county/town/category assignments | Language-bearing |

### 8.2 Verified behavior

- Import pipeline: **local Docker WordPress fetches external sources** (Laois Tourism, National Heritage Week, Eventbrite); production never contacts external sources. Production receives a JSON export with embedded images.
- Upsert identity order: **UUID → source+source_id → URL → title+date**. If English were implemented as *duplicate posts*, the next import run would upsert against the PT record and orphan/duplicate EN records. **English must therefore be a per-record language variant (translation linkage/meta), never a separate event post carrying the same `_event_source_id`.**
- Recurrence labels: the model stores ISO codes; only the theme's `conexao_event_recurrence_label()` renders PT text (hardcoded — §3 item 1). Translating it is presentation-only and safe.
- The `_event_status` gate (`pre_get_posts`) must keep precedence over any language filter so hidden events stay hidden in both languages.
- County/town terms are proper nouns (Dublin, Dungarvan) — language-neutral; event `conexao_category` terms have PT names needing EN term-name translations (translate the term, don't create parallel terms — that would fragment filters).
- **Imported event content is frequently already English** (source data). Pragmatic model: mark events whose source content is EN as EN records; translate only hand-curated posts. This drastically reduces event workload.
- Export JSON format (v1.1) has no language field — a future EN export needs a schema addition (`"lang"`), or language stays out of the export (PT master only) with EN created post-import.

### 8.3 Rules that must hold (any option)

1. One event post per source identity, ever. EN content attaches to the same post.
2. `_event_status` gate language-agnostic and always applied.
3. Slugs never re-derived per language (PT slug canonical).
4. `conexao_town` / `conexao_county` terms shared across languages (name translation only).
5. Importer/admin runs in the default (PT) language context; force language meta on import.

## 9. Lazer Implications

- Data model: `leisure` CPT + rich `_leisure_*` meta + `conexao_leisure_attribute` terms + local Media Library images + attribution meta (architecture rules #5, #8).
- Portable identity: `_lazer_uuid` (matching: UUID → slug → title). **Same conclusion as events: EN must be a variant of the same record, not a duplicate post** — otherwise ZIP export/import (title/slug matching) treats EN duplicates as distinct records and corrupts round-trips.
- Language-bearing fields: descriptions, practical notes, `_leisure_image_alt_text`. Language-neutral: attribution/license metadata (translate display labels only), URLs, verification dates, external-redirect classification (`conexao_leisure_external_url()` — URL-based).
- Related-events / related-destinations logic is county/term based — works unchanged if terms are shared across languages.

## 10. Search Implications

- Native `WP_Query` search (`search.php`, `searchform.php`, overlay) — single-language today.
- With a multilingual plugin: search auto-scopes to the active language (PT search won't surface EN-only posts and vice versa). Behavior for untranslated content needs an explicit decision (§12).
- With a custom meta approach: search must be hand-filtered by language meta — more cost, and a real risk of silently missing results.
- The event archive/filters must compose with the `_event_status` gate under any language filter.
- `/?s=` and `/search/` are disallowed in robots.txt — mirror for `/en/`.
- No third-party search plugin observed (no Jetpack Search module active).

## 11. Editorial Workflow (Phase 8) — documented options, not assumed

| Workflow | Description | Behavior when EN missing |
|---|---|---|
| **W1: PT only (status quo + locale groundwork)** | UI translatable via .mo; all content PT | N/A — no EN pages exist |
| **W2: PT + EN per-record (Polylang-style)** | Every record optionally has an EN translation linked to the PT master | EN URL **redirects or falls back** to PT (see §12) |
| **W3: EN optional per content type** | e.g. Guides + key static pages get EN; Blog optional; Events language-inherited from source | Missing EN on optional types → PT fallback or exclusion from EN listings |
| **W4: EN created later (deferred sync)** | Editors publish PT first; EN translated in a later pass | Fallback until translation exists; re-surface as "needs translation" queue |
| **W5: PT updated after EN exists** | PT edit invalidates EN | Options: flag EN as outdated (needs-review state), keep stale EN live, or hide EN. **Must be decided, not assumed**; recommend an "outdated" flag surfaced in wp-admin. |

Editorial mechanics per option: Polylang Pro gives a translation editor with per-field sync (titles, meta, taxonomies) and "translated/not translated" states; WPML has a full translation-management queue; the custom approach requires building status UI in `conexao-admin-ux`.

Key editorial facts discovered:
- All production content is entered via wp-admin (manual) or the importer (local) — no external CMS.
- Admin UI is entirely PT (`conexao-admin-ux` labels); editors currently work in PT.
- Content sync surfaces: homepage transients invalidate on save — must also invalidate per-language.

## 12. Missing-Translation Behavior Options (documented, not selected)

| # | Behavior | Pros | Cons |
|---|---|---|---|
| **B1** | EN URL 302/301-redirects to the PT page | Simple; no empty EN pages; keeps link equity on PT master | EN visitor bounced to PT; switcher "bounces"; SEO: EN URLs crawl into redirects |
| **B2** | PT content rendered under the EN URL shell (chrome EN, body PT) | Consistent URLs; EN chrome everywhere | Mixed-language page; duplicate-content risk without careful hreflang/canonical |
| **B3** | EN record exists but is **excluded** from EN listings until complete (unlisted) | Clean EN experience; no mixed pages | EN archives look empty early; EN users can't reach content at all |
| **B4** | Explicit "not yet available in English" notice + PT link | Honest UX; no SEO ambiguity | Extra surface to build |
| **B5** | Per-content-type hybrid (recommended candidate, not selected): directories (events/lazer/sponsors/courses) inherit PT record with EN chrome; guides/blog/key pages B1-redirect until translated | Matches where content value lies | Requires per-type rules encoded in the switcher/SEO layer |

Whatever is chosen must be implemented consistently across: language switcher, hreflang alternates (only link pairs that exist), sitemap (only index EN URLs that resolve to real EN content), and search.

## 13. Effort Matrix (Phase 9)

Complexity: LOW / MEDIUM / HIGH. Stages refer to §14.

| Area | Current state | English work required | Complexity | Risk | Dependencies | Recommended stage |
|---|---|---|---|---|---|---|
| UI strings (PHP templates) | 446 gettext calls, no .mo files | Create POT + pt_BR/en catalogs; ~15% strings not yet wrapped | LOW | LOW | None | Stage 1 |
| Hardcoded PT gaps (§3.1) | ~25 strings + recurrence label logic | Externalize; add PT-grammar-aware recurrence labels | LOW–MEDIUM | LOW | gettext setup | Stage 1 |
| JS strings | 4–5 hardcoded, no `wp_localize_script` | Add strings injection (localize or data attributes) | LOW | LOW | gettext setup | Stage 1 |
| Plugin gettext | No `load_plugin_textdomain()` | Add loaders; translate admin strings (optional) | LOW | LOW | None | Stage 1 |
| Locale model | `en_US` WP locale + PT content + hardcoded `og:locale=pt_BR` | Decide locale model; dynamic `html lang` + `og:locale`; date format localization | MEDIUM | MEDIUM | Decision | Stage 1 |
| Navigation/menus | DB menu + runtime PT label overrides | Per-language menu or label override layer | LOW | LOW | Multilingual mechanism | Stage 2 |
| Homepage | Customizer PT text + cached `conexao_home_*` transients | EN Customizer values; per-language transient keys | MEDIUM | MEDIUM | Multilingual mechanism; cache invalidation | Stage 2 |
| Search | Native WP_Query | Language scoping + `/?s=` robots | LOW–MEDIUM | LOW | Multilingual mechanism | Stage 2 |
| Forms/newsletter | Jetpack subscription; theme section | Jetpack form language; theme strings (gettext) | LOW | LOW | Jetpack behavior | Stage 2 |
| 404/empty states | gettext-wrapped | Nothing beyond catalogs | LOW | LOW | None | Stage 1 |
| Guides | ~50–100 PT posts | Human EN translation per post | HIGH (content) | LOW | Editorial capacity | Stage 3 |
| Blog | ~30–60 PT posts | Optional per-post EN | MEDIUM (content) | LOW | Editorial policy | Stage 3+ |
| Events | Imported + manual; EN-source content common | Per-record language model; recurrence/labels EN; archive chrome | MEDIUM (tech) / LOW (content if inherit source) | **HIGH** (identity layer) | §8 rules | Stage 3 |
| Lazer | ~20–40 curated, meta-rich | EN meta fields; attribute term names | MEDIUM (tech) / MEDIUM (content) | MEDIUM (UUID round-trip) | §9 rules | Stage 3 |
| Employment (/empregos/) | Landing page (DB) + agency/permit meta + filters | EN landing body; EN card meta; filter labels | MEDIUM | LOW | Multilingual mechanism | Stage 3 |
| Courses | Directory, external links, meta category | EN titles/excerpts; category term names | LOW | LOW | Term translations | Stage 3 |
| Sponsors | Directory + contacts | EN descriptions | LOW | LOW | Multilingual mechanism | Stage 3 |
| Contact/static pages | ~20 PT Gutenberg pages | Translate key pages first | MEDIUM (content) | LOW | Editorial | Stage 3 |
| Taxonomies | PT term names + PT slugs in filter URLs | Translate term **names**; slugs stay PT | LOW | LOW | Multilingual term support | Stage 2 |
| SEO layer | Custom `inc/seo.php` | Per-language titles/desc/OG/JSON-LD; hreflang; og:locale dynamic; breadcrumb root | MEDIUM | **HIGH** (canonical correctness) | URL decision | Stage 2 |
| Redirect architecture | EN→PT 301s in .htaccess + seo.php | Inventory, retire/keep per legacy value; add `/en/` support | MEDIUM | **HIGH** (link equity, collisions) | URL decision | Stage 2 |
| Sitemap | Theme custom + Jetpack (production) | EN URLs + hreflang alternates; avoid dual-producer drift | MEDIUM | MEDIUM | URL decision; Jetpack coordination | Stage 2 |
| REST | Open, `show_in_rest`, no frontend use | Language filter contract for Flutter app | LOW–MEDIUM | LOW | Multilingual mechanism | Stage 2 (contract), Stage 4 (app) |
| Media | Local attachments; alt text PT | EN alt text (a11y) | LOW | LOW | Media meta language strategy | Stage 3 |
| Importer/runtime | Local import → export → production; identity keys | Force language on import; keep identity untouched; optional export `lang` field | MEDIUM | **HIGH** if violated | §8 rules | Stage 2 (guards) + 3 |
| Lazer/sponsor migration tools | ZIP/JSON round-trip with UUID | Force language; keep UUID matching; verify round-trip | MEDIUM | MEDIUM | §9 | Stage 3 |
| Admin/editorial | PT-only admin UX; no translation workflow | Translation UI or meta workflow; outdated flag | MEDIUM | MEDIUM | Selected option | Stage 2–3 |
| Cache/performance | Home/404 transients; event query transients; WP.com edge + CDN | Per-language cache keys + invalidation | MEDIUM | MEDIUM (mixed-language serving) | Multilingual mechanism | Stage 2 |
| Analytics | Not observed in repo (likely Jetpack/site stats only) | Language dimension in tracking if added | LOW | LOW | Analytics decision | Stage 4 |
| Legal/privacy/cookie | 3 PT DB pages; no cookie banner | Translate privacy/terms/cookies pages | LOW (tech) / MEDIUM (content) | LOW (but legal text) | Editorial/legal review | Stage 3 |

## 14. Recommended Staged Implementation Plan (Phase 10)

No code written, nothing installed — sequence only. Portuguese remains the default at every stage; every stage is independently shippable and reversible.

### Stage 0 — Decisions (no code)
1. Confirm locale model: site default `pt_BR`; English served under `/en/` prefix.
2. Confirm missing-translation behavior per content type (§12 B5 candidate).
3. Confirm editorial model (W3/W4) and translation ownership.

### Stage 1 — i18n foundations (zero URL/SEO impact)
1. Create `languages/` + POT; build pt_BR and en catalogs.
2. Externalize §3.1 gaps (recurrence labels, nav overrides, seo.php strings, JS strings via a localize mechanism).
3. Add `load_plugin_textdomain()` to all 7 plugins.
4. Fix locale oddity: set site locale `pt_BR`; make `html lang` and `og:locale` dynamic.
5. Regression-test dark mode, filters, infinite scroll, transients (no behavior change expected).

### Stage 2 — Multilingual mechanism + URL/SEO (introduces `/en/`)
1. Select and install the multilingual mechanism (locally first; staging on WordPress.com after).
2. Set default language `pt_BR`; verify PT URLs byte-identical; CPT slugs preserved under `/en/` prefix.
3. Teach `inc/seo.php`: per-language canonicals, hreflang pairs (only where translations exist), dynamic og:locale, localized JSON-LD.
4. Redirect-inventory work: audit the ~60+ EN→PT 301s; retire collision entries; keep legacy-value entries.
5. Sitemap strategy: EN URLs + alternates; reconcile theme custom sitemap with Jetpack sitemap on production.
6. Per-language menus/switcher; per-language homepage transient keys; search scoping; robots patterns.
7. Importer/runtime guards: force language on import; add automated tests asserting one-post-per-source-identity before/after.
8. Validate `_event_status` gate precedence with language filters active.

### Stage 3 — Content
1. Translate shared taxonomy term names (categories, leisure attributes; counties/towns mostly proper nouns).
2. Translate homepage/Customizer text, key static pages (Sobre Nós, Contato, privacy/terms/cookies).
3. Enable per-type EN content: Guides first, then directory meta (Lazer/Empregos/Courses/Sponsors), Blog last/optional.
4. Events: adopt per-record language markers; translate only hand-curated event posts; source-EN events inherit as-is.
5. Media alt text EN for hero/featured images (a11y).
6. Lazer/sponsor ZIP/JSON round-trip verification with EN variants present (UUID identity intact).

### Stage 4 — App/extension
1. REST language contract (`?lang=en` + translation links) documented for the future Flutter app.
2. Analytics language dimension (if/when analytics added).
3. Ongoing editorial loop (W5 outdated-flag workflow).

## 15. Risks and Blockers

| Risk | Severity | Mitigation |
|---|---|---|
| EN→PT 301 redirect tables collide with the future `/en/` space and carry unknown inbound-link equity | **HIGH** | Full redirect inventory with analytics/crawl data before Stage 2 |
| Event identity corruption if EN implemented as duplicate posts | **HIGH** | Hard rules §8.3 + automated one-identity tests |
| Custom SEO layer (`inc/seo.php`) vs plugin SEO behavior — two "smart" layers both emitting canonical/hreflang | **HIGH** | Single-owner rule: extend `inc/seo.php`; configure the plugin to defer |
| Dual sitemap producers (theme + Jetpack) drift | MEDIUM | Decide producer per surface; monitor |
| WordPress.com hosting constraints (plugin availability on plan, edge caching) | MEDIUM | Verify plan features; staging validation |
| Locale oddity (`en_US` site locale + PT content) baked into dates/formats | MEDIUM | Stage-1 fix; audit `get_the_date()`/`date_i18n()` surfaces |
| Home/404/event transients serving the wrong language after cache warmth | MEDIUM | Per-language keys + save-hook invalidation tests |
| Mixed-language UX if missing-translation behavior stays implicit | MEDIUM | Explicit per-type decision (§12) before Stage 3 |
| Content volume (human translation of guides/static pages) is the true long pole | MEDIUM | Stage gating; prioritized content plan |
| Gettext coverage ~85% but not 100% — gaps surface piecemeal | LOW | POT regeneration + repeat this audit's scan |
| `conexaobr.com` is an unrelated site — assumptions of redirect/relationship are false | INFO | Documented; excluded from hreflang/redirect plans |

**Blockers:** none preventing Stage 1. Stage 2 requires the explicit Stage-0 decisions first.

## 16. Exact Files / Systems Inspected

Repository (read-only): `AGENTS.md`, `compose.yaml`, `.htaccess`; `docs/` architecture/content-model/routing/frontend/theme/plugin docs (incl. event-runtime + event-importer); theme `style.css`, `functions.php` (3937 lines), `inc/seo.php` (1413), `inc/search.php`, `inc/employment-opportunities.php`, `inc/empregos-landing.php`, `inc/recruitment-agencies.php`, `inc/permit-employers.php`, `inc/job-resources.php`, `inc/post-views.php`; all templates (`header/footer/front-page/archive/single*/page*/home/search/searchform/404/sidebar/comments`) and 25 `template-parts/`; `assets/js/main.js` (2001 lines) + `job-resources-admin.js`; all 7 plugins' main files and key includes (`class-meta.php`, `class-relationships.php`, `create-pages.php`, `seed-leisure.php`, `class-config.php`, `class-fields.php`, `class-event-status/recurrence/query.php`); i18n census (446 theme gettext calls, hardcoded-PT scan, zero `.po/.mo/.pot`, no `load_plugin_textdomain`, no `wp_localize_script`).

Production (live, read-only): conexaobr.ie home (`canonical`, `lang="en-US"`, `og:locale pt_BR`, Jetpack hints, no hreflang, PT nav/content, English date rendering), `/robots.txt`, `/sitemap.xml` (Jetpack `16.3-a.1`), `/eventos/` (`Hoje` chip), `/guides/` → 301 `/guias/`, and **conexaobr.com verified as an unrelated site** ("CXBR Investimentos", Hello Elementor, self-canonical).

---

## FINAL STATUS

**ENGLISH SUPPORT AUDIT — COMPLETE**

No architectural blocker exists: the gettext-heavy theme, shared-taxonomy/URL-helper design, and importer identity rules all support a staged `/en/` implementation. Highest-risk areas — the existing EN→PT redirect tables, the custom SEO/sitemap layer, and the event identity layer — each have concrete mitigations above. Content translation volume, not code, is the dominant long-term cost.












