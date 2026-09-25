# CONEXÃO BR Irlanda — English Stage 4.5 Report
## Website Page Translation (WordPress only — Flutter frozen and out of scope)

**Stage:** 4.5 — translate ALL public, user-facing WordPress Pages into human-authored English
**Work line:** `CONEXAO_BR_ENGLISH_*` (Stages 1–4.3 architecture), repo branch `cline/g6fqrfsq`
**Date:** 2026-09-23

---

## 1. Status

**ENGLISH STAGE 4.5 — PASS WITH LIMITATION**

Every eligible public Page (37 of 43) has a complete, human-authored, statically validated
English translation with a deployable, auditable production migration mechanism. Zero eligible
pages remain untranslated. Every exclusion (6 pages) is documented with measured evidence.

The limitation is execution: **the production apply is NOT_EXECUTED**. Production
(https://conexaobr.ie, WordPress.com) currently has **no English layer at all** (measured:
Polylang absent, `/en/` serves a WordPress fuzzy 301) — the Stage 4.3 deployment runbook is
still pending — and this environment has **no production write path** (no wp-admin session,
no WP-CLI on WordPress.com, no REST `translations` capability in Polylang Free, and no
application password in the environment). Per the task's own rule (Phase 24), the stage
therefore stops after validation and delivers the complete migration package + exact operator
instructions instead of pretending the website is translated.

## 2. Scope

**In scope (this stage):** all public WordPress **Pages** — every eligible published page
record, their hierarchy, content, custom fields, internal links, SEO metadata, template
parity, navigation, breadcrumbs, sitemap membership, search behaviour and the theme chrome
(gettext strings) those pages render through.

**Out of scope (unchanged, separate workflows):** Events, Guides, Blog *posts*, Lazer
records, Sponsors, Courses, Jobs/CPT records, taxonomy terms (except where page
content/navigation required it), the Flutter application (completely frozen — never accessed,
inspected, built or modified in this stage), and the Stage 4.1 REST contract
(`inc/rest-language.php` untouched; `page` remains deliberately excluded from the bilingual
REST surface even though all pages are now translated).

**Task discrepancy, documented:** the task's READ-FIRST list references
`CONEXAO_BR_ENGLISH_STAGE_4_4_REPORT.md` and "the Stage 4.4 SEO policy". **No Stage 4.4
report exists in this repository** (verified: `find . -name '*STAGE_4_4*'` → no matches; the
only "4.4" reference is a forward-looking mention inside the Stage 4.3 report). The
hreflang/SEO policy this stage implements is therefore the one established and measured by
Stages 2/3.1/3.2/3.3 and restated in the Stage 4.3 runbook (§8 of
`stage43-work/reports-digest.md`): the theme `inc/seo.php` is the single SEO owner, one
hreflang set per page pair, `x-default` → the default-language URL, no alternate pointing at
a redirect. No new hreflang producer was created.

## 3. Baseline

Measured read-only over HTTPS from this environment on 2026-09-23 (public REST + HTML GET
only; no credentials; raw captures under `stage45-work/inventory-cache/`):

| Surface | Measured production state |
|---|---|
| WordPress pages (public REST) | **43 published pages**, all top-level (no parent/child hierarchy exists) |
| Front page / posts page | `page_on_front=9` (`inicio`), `page_for_posts=10` (`blog`) — Stage 4.3 baseline values; `/inicio/` 301 → `/` confirms |
| **Polylang** | **not installed** — `/wp-json/pll/v1/languages` → 404 `rest_no_route` |
| `/en/` | **does not exist** — 301 (WordPress fuzzy slug guess to an event page); `/en/about-us/` → 404 |
| `/en/*` page URLs | all 37 planned EN URLs: non-200 (404/301) — measured in the BEFORE matrix |
| Sitemap producer | Jetpack 16.3-a.3 (`/sitemap.xml` sitemapindex → `sitemap-1.xml`, 79 locs) — the theme producer is shadowed (pre-existing, Stage 4.3 §15) |
| Theme deployed | pre-Stage-1 build (PT pages emit `<html lang="en-US">`, no hreflang) — the Stage 4.3 ZIP deploy is still pending |
| PT page surface | intact: 36/36 PT page URLs 200 + `/` 200; canonical self; `/inicio/` 301 → `/` (BEFORE matrix: **83/83 rows pass**) |
| Legacy aliases | `/sobre/`, `/privacidade/`, `/termos/`, `/about/` all 301 to their canonical PT pages (measured) |
| `/lazer/` | serves the **leisure CPT archive** (grid markup, title "Lazer & Turismo na Irlanda") — the `lazer` page record is shadowed |
| en_US gettext catalog | 68 compiled entries of 385 (curated subset; homepage strings untranslated) |

## 4. Complete page inventory

Built from live production data by `scripts/stage45-page-inventory.py` (read-only; REST
pages + per-page HTML head + sitemap + repo seed/redirect/allowlist cross-references).
Machine-readable: `stage45-work/page-inventory.json`; human-readable:
`stage45-work/page-inventory.md` (all 43 rows). Per page it records: PT ID, slug, title,
permalink, parent ID/slug, menu order, status, template, depth, front/posts flags, featured
media, current Polylang/EN state, B1/B2 state, Gutenberg usage, word count, internal/external
links, sitemap membership, SEO head facts (title tag, meta description, canonical, robots,
html lang, hreflang, og:locale) and the Stage 4.5 classification with reason + evidence.

**Total: 43 published pages. 37 to translate. 6 technical exclusions.** Class distribution:
A=22 (normal user-facing), B=1 (front page), C=1 (posts page), F=3 (legal), G=10 (previously
B2-allowlisted directory/info pages: `irlanda` + 9 counties), H=6 (technical). No D/E classes
exist because **no production page has a parent** (all 43 are top-level, depth 0) — verified
from the REST `parent` field of every record.

## 5. Classification of every Page

| # | ID | slug | title | class | action | EN slug | evidence summary |
|---|---|---|---|---|---|---|---|
| 1 | 1 | `about` | About | H | exclude | — | WP installer sample page; `/about/` 301 → `/sobre-nos/` (measured) |
| 2 | 9 | `inicio` | Início | B | translate | `home` | front page (page_on_front) |
| 3 | 10 | `blog` | Blog | C | translate | `blog` (shared slug) | posts page (page_for_posts) |
| 4 | 11 | `sobre-nos` | Sobre Nós | A | translate | `about-us` | key narrative page (Stage 3.2 set) |
| 5 | 12 | `contato` | Contato | A | translate | `contact` | primary-menu item |
| 6 | 13 | `newsletter` | Newsletter | A | translate | `newsletter` (shared slug) | footer-menu item |
| 7 | 14 | `revista` | Revista Digital | A | translate | `digital-magazine` | public editorial page |
| 8 | 15 | `anuncie` | Anuncie Aqui | A | translate | `advertise` | header CTA destination |
| 9 | 16 | `politica-de-privacidade` | Política de Privacidade | F | translate | `privacy-policy` | legal (Stage 3.2 set) |
| 10 | 17 | `termos-de-uso` | Termos de Uso | F | translate | `terms-of-use` | legal (Stage 3.2 set) |
| 11 | 18 | `cookies` | Política de Cookies | F | translate | `cookie-policy` | legal (Stage 3.2 set) |
| 12 | 19 | `search` | Buscar | H | exclude | — | search utility page (see §30) |
| 13 | 20 | `moradia` | Moradia | A | translate | `housing` | topic hub; homepage quick-access destination |
| 14 | 22 | `familia` | Família | A | translate | `family` | topic hub |
| 15 | 24 | `financas` | Finanças | A | translate | `finances` | topic hub; quick-access destination |
| 16 | 26 | `educacao` | Educação | A | translate | `education` | topic hub |
| 17 | 32 | `negocios` | Negócios | A | translate | `business` | topic hub |
| 18 | 33 | `servicos` | Serviços | A | translate | `services` | topic hub |
| 19 | 35 | `irlanda` | Irlanda para Brasileiros | G | translate | `ireland` | previously B2-allowlisted |
| 20 | 36 | `europa` | Europa para Brasileiros | A | translate | `europe` | public editorial page |
| 21 | 37 | `laois` | Condado de Laois | G | translate | `county-laois` | previously B2-allowlisted |
| 22 | 38 | `dublin` | Condado de Dublin | G | translate | `county-dublin` | previously B2-allowlisted |
| 23 | 39 | `cork` | Condado de Cork | G | translate | `county-cork` | previously B2-allowlisted |
| 24 | 40 | `galway` | Condado de Galway | G | translate | `county-galway` | previously B2-allowlisted |
| 25 | 41 | `limerick` | Condado de Limerick | G | translate | `county-limerick` | previously B2-allowlisted |
| 26 | 42 | `kildare` | Condado de Kildare | G | translate | `county-kildare` | previously B2-allowlisted |
| 27 | 43 | `meath` | Condado de Meath | G | translate | `county-meath` | previously B2-allowlisted |
| 28 | 44 | `wicklow` | Condado de Wicklow | G | translate | `county-wicklow` | previously B2-allowlisted |
| 29 | 45 | `waterford` | Condado de Waterford | G | translate | `county-waterford` | previously B2-allowlisted |
| 30 | 61 | `privacidade` | Privacidade | H | exclude | — | obsolete alias; `/privacidade/` 301 → `/politica-de-privacidade/` (measured) |
| 31 | 62 | `termos` | Termos | H | exclude | — | obsolete alias; `/termos/` 301 → `/termos-de-uso/` (measured) |
| 32 | 63 | `sobre` | Sobre | H | exclude | — | obsolete alias; `/sobre/` 301 → `/sobre-nos/` (measured) |
| 33 | 11086 | `empregos` | Empregos | A | translate | `jobs` | Jobs landing page (template `page-empregos.php`; job CPT archive disabled) |
| 34 | 11293 | `saude` | Saúde | A | translate | `healthcare` | topic hub; quick-access destination |
| 35 | 11294 | `transporte` | Transporte | A | translate | `transport` | topic hub |
| 36 | 11295 | `beneficios` | Benefícios | A | translate | `benefits` | topic hub; quick-access destination |
| 37 | 11296 | `documentos` | Documentos | A | translate | `documents` | topic hub; quick-access destination |
| 38 | 11297 | `onde-comer` | Onde Comer | A | translate | `where-to-eat` | topic hub |
| 39 | 11298 | `lazer` | Lazer | H | exclude | — | shadowed by the leisure CPT archive (see §30) |
| 40 | 11299 | `turismo` | Turismo | A | translate | `tourism` | topic hub |
| 41 | 11300 | `compras` | Compras | A | translate | `shopping` | topic hub |
| 42 | 11301 | `voluntariado` | Voluntariado | A | translate | `volunteering` | topic hub |
| 43 | 11302 | `categorias` | Categorias | A | translate | `categories` | category index (18 internal links) |


The full reason + evidence text for every row is in `stage45-work/page-inventory.{json,md}`
(the `classification` object per page) and mirrored in `scripts/stage45-page-inventory.py`
(`CLASSIFICATION`). The default rule applied throughout was PUBLIC PAGE = TRANSLATE; nothing
was excluded for convenience.

## 6. Translation order

Mandatory Phase 2 order encoded in the manifest (and asserted by the validator):
front page → key narrative/jobs/posts pages → topic hubs → category index →
Ireland/Europe → counties → utility (newsletter, magazine, advertise) → legal.
No child-before-parent risk exists (no page has a parent), but the importer still enforces
the gate (`includes/apply.php`: a PT parent without a published EN translation aborts that
page with an error instead of creating an orphan hierarchy).

## 7. Pages translated

**37 pages, human-authored.** Content data:
`wp-content/plugins/conexao-page-translation/includes/translation-map.php` (single source of
truth; reviewable JSON rendering: `stage45-work/translation-manifest.json`).

- The 7 Stage 3.2 approved pages reuse their established EN wording/slug pairs
  (`home`, `about-us`, `contact`, `jobs`, `privacy-policy`, `terms-of-use`, `cookie-policy`)
  — **except `contato` and `empregos`, which were re-authored faithfully** because the
  *current production PT content* drifted from the Stage 3.2 local dataset: the real
  `contato` page carries the WhatsApp CTA + mailto line + trailing empty paragraph (all
  preserved), and the real `empregos` page carries the Instagram CTA text (translated
  as-is; the Stage 3.2 wording described older local content).
- The remaining 30 pages are translated from the **measured production rendered content**,
  block for block (see §12).
- No machine translation was used anywhere; all EN copy was authored in this stage.

## 8. Parent/child hierarchy

**No production page has a parent** (all 43 top-level; verified from the REST `parent`
field). Therefore:

- every EN page is created top-level — the hierarchy is preserved exactly (depth 0 = depth 0);
- **0 parent/child relationships need creating**; the Phase 5 machinery is nevertheless
  implemented and enforced for any future hierarchy: the importer assigns the **translated
  parent** when a PT parent exists and refuses (hard error) to create a child whose parent
  is not yet translated;
- breadcrumbs on EN pages resolve through the translation group: page breadcrumbs are
  `Início → <page>` (gettext "Início" → "Home" + Polylang's bare-home-URL rewrite to `/en/`
  + the EN page title) — no PT breadcrumb can appear on an EN page (the `after` HTTP matrix
  asserts exactly this per page).

## 9. Homepage translation

The homepage pair is unchanged in mechanism (Stage 3.2) and complete in content (Stage 4.5):

- PT `/` keeps serving `front-page.php` in Portuguese; the PT page record (`inicio`, ID 9)
  keeps its one-paragraph body; `/inicio/` still 301s to `/` (measured).
- EN `/en/` is the linked translation (EN slug `home`); `/en/home/` 301-consolidates to
  `/en/` (asserted in the `after` matrix).
- **The homepage's visible EN text is the theme chrome**, and Stage 4.5 completes it: the
  hero ("Everything Brazilians need to live better in Ireland"), the quick-access cards
  (Housing / Jobs / Healthcare / Transport / Finances / Benefits / Documents), the
  "Precisa de ajuda?" shortcuts, the Latest Posts / Agenda / Jobs sections and every CTA
  now have English strings through the completed `en_US` catalog (384 entries; previously
  only 68). Every homepage link already resolves through `conexao_lang_url()` (Stage 3.3)
  — the quick-access cards point to `/en/guias/?categoria=…` (linked EN terms) and the
  Jobs card to `/en/jobs/`.
- The footer brand/about links and the bottom legal links resolve to the linked EN pages
  (Stage 3.3 fix), now including `/en/privacy-policy/`, `/en/terms-of-use/`,
  `/en/cookie-policy/` and the newly translated `/en/advertise/` + `/en/newsletter/`.

## 10. Legal-page translation

All three legal pages are translated **fully and faithfully** (no simplification, no
invented legal terms): `privacy-policy` (all four sections), `terms-of-use` (Use of Content /
External Links), `cookie-policy` (what cookies are / the three cookie types). The EN wording
reuses the Stage 3.2 approved translations (they mirror the production PT bodies exactly).
No additional public legal page exists in the inventory (the alias pages `privacidade` /
`termos` are unreachable technical records — §30).

## 11. County-page translation (and `/irlanda/`)

The 10 previously B2-allowlisted pages (`irlanda`, `dublin`, `cork`, `galway`, `limerick`,
`kildare`, `meath`, `wicklow`, `waterford`, `laois`) all get **real EN translations**, which
retires the B2 fallback for every page:

- titles use the official English naming ("County Dublin", "Ireland for Brazilians");
- slugs are natural English (`county-dublin`, …, `ireland`) — **no county/town taxonomy
  work is involved** (counties/towns are shared single-term identities per the Stage 3.2
  policy; no term was created, translated or duplicated in this stage);
- after the apply, each of these pages behaves as a real EN page: 200 at `/en/<slug>/`,
  self-canonical, full `en`/`pt-BR`/`x-default` hreflang pair, EN sitemap entry — and the
  B2 notice/canonical-to-PT behaviour simply no longer triggers for them
  (`conexao_b2_page_allowlist()` still exists, unchanged, as the mechanism for future

## 12. Content translation quality

- **Structure preservation is machine-checked**: `scripts/stage45-validate-manifest.py`
  compares the EN block skeleton (ordered `h2/h3/p/ul` sequence after stripping block
  comments) with the PT original's rendered skeleton — all 37 pages match exactly
  (including `contato`'s trailing empty paragraph and `empregos`'s `<br>` CTA layout).
- **Terminology is standardised** across pages and with the established catalog:

  | PT | EN (fixed across all pages, titles, meta, chrome) |
  |---|---|
  | Moradia | Housing |
  | Saúde | Healthcare |
  | Família | Family |
  | Transporte | Transport |
  | Finanças | Finances |
  | Benefícios | Benefits |
  | Educação | Education |
  | Documentos | Documents |
  | Emprego(s)/Vagas | Job(s)/Job openings |
  | Negócios | Business |
  | Serviços | Services |
  | Voluntariado | Volunteering |
  | Turismo | Tourism |
  | Compras | Shopping |
  | Onde Comer | Where to Eat |
  | Guias (práticos) | (Practical) Guides |
  | Eventos | Events |
  | Apoiador(es) | Supporter(s) |
  | Condado de X | County X |
  | Cidade (filters) | Town |

- **Never translated** (official names / proper nouns / identifiers): Conexão BR Irlanda,
  PPS Number, Medical Card, GP, IRP, HSE, Civil Service, Department of Enterprise,
  Employment Permit(s), Discover Ireland, CNH (glossed as "Brazilian driver's licence
  (CNH)"), Jobs.ie, Indeed, IrishJobs, WhatsApp, Instagram, Mário Sérgio Cortella, Irish
  place/county names, all URLs, emails, phone numbers.
- **Capitalisation/CTA wording**: sentence case body, Title Case page titles, consistent
  CTA verbs ("View all", "See more", "Explore Guides", "Get in touch with us",
  "Subscribe to our Newsletter").
- The completeness scanner (§24) reports **37/37 translated, 0 possible-untranslated,
  0 empty** on the authored data.

## 13. Custom-field translation

Audited per page (REST `meta` + the theme/consumers of each field):

| Field | Treatment |
|---|---|
| `conexao_meta_description` | **translated** — an authored EN meta description per page (37/37); set by the importer via `update_post_meta` (the theme's SEO owner reads it first, so no excerpt fallback is needed) |
| `_empregos_link` (Jobs landing "Mais informações" URL) | **copied verbatim** from the PT source (a URL — never translated); the importer's pass-through list applies it to the EN Jobs page |
| `_wp_page_template` | **copied** (`page-empregos.php` for the Jobs landing; all other pages default) — template parity asserted in the matrix |
| `_thumbnail_id` / featured media | **copied** (Jobs landing image, media ID 10959; media is shared — `media_support=false`) |
| `menu_order`, `post_parent` | **copied** (all 0/top-level) |
| `advanced_seo_description`, `jetpack_seo_*`, `_wpcom_ai_launchpad_*`, `footnotes` | untouched on PT; not replicated on EN (WordPress.com/Jetpack bookkeeping, empty on every audited page) |
| `_yoast_wpseo_metadesc` | not replicated (Yoast is not installed; the seed's dead duplicate of `conexao_meta_description`) |

No text-bearing custom field other than `conexao_meta_description` exists on any audited
page (verified against the REST-exposed `meta` of every page).

## 14. Internal-link resolution

Only one translated page contains internal links: **`categorias`** (18 links). Its EN body
keeps the canonical PT hrefs in the data file, and the importer resolves them at apply time
through the **Polylang relationship** (`conexao_page_translation_resolve_path`):
page links → the linked EN page's permalink path; archive links (`/lazer/`, `/eventos/`,
`/guias/`) → the EN archive URL (`conexao_language_archive_url`); anything unresolved keeps
the PT URL (approved B1). No string prefix-mangling — the resolver reuses the theme's own
`conexao_lang_url_archive_post_type()` single source of truth (so `/empregos/` correctly
resolves to the **page** translation `/en/jobs/`, not an archive, because the `job` CPT has
`has_archive=false`).

Simulated resolution (validated, `stage45-work/translation-manifest.json` →
`internal_links_localized`): `/moradia/`→`/en/housing/`, `/empregos/`→`/en/jobs/`,
`/saude/`→`/en/healthcare/`, `/familia/`→`/en/family/`, `/transporte/`→`/en/transport/`,
`/financas/`→`/en/finances/`, `/beneficios/`→`/en/benefits/`, `/educacao/`→`/en/education/`,
`/documentos/`→`/en/documents/`, `/onde-comer/`→`/en/where-to-eat/`,
`/turismo/`→`/en/tourism/`, `/compras/`→`/en/shopping/`, `/negocios/`→`/en/business/`,
`/servicos/`→`/en/services/`, `/voluntariado/`→`/en/volunteering/` (15 page links),
`/lazer/`→`/en/lazer/`, `/eventos/`→`/en/eventos/`, `/guias/`→`/en/guias/` (3 archive
links). External links (`wa.me`, `mailto:`) are preserved byte-for-byte.

Theme chrome links that gain EN destinations were fixed to resolve through
`conexao_lang_url()` (§27 lists the files): the header "Anuncie Aqui" CTA (3 instances) now
resolves to `/en/advertise/` in English instead of hardcoding `/anuncie/`.

  content; a published translation always takes precedence).

## 15. Navigation

- **Primary menu (PT, "Menu Principal", 9 items)**: unchanged (nothing in this stage
  touches PT menu data). Items: Início, Apoiadores, Guias, Eventos, Cursos, Lazer e
  turismo, Empregos, Blog, Contato.
- **Primary menu (EN, "Main Menu")**: the Stage 3.3 mechanism (Polylang per-language
  `nav_menus` assignment + `scripts/create-en-primary-menu.php`) already covers the EN
  header; with every page now translated, **each page-backed nav item resolves to its EN
  counterpart at render time** — Jobs → `/en/jobs/`, About Us → `/en/about-us/`,
  Contact → `/en/contact/`, and the Blog item → `/en/blog/` through
  `conexao_lang_url( '/blog/' )` (the previously "approved B1 exception" retires itself
  now that the posts page is a real translation). The render-time language-aware layer
  (`conexao_primary_nav_sections()` + `conexao_bind_section_object()`) was hardened for
  shared slugs (§27).
- **Footer menu**: PT footer items are page objects (Polylang-translated to their EN
  counterparts) + the "Anuncie"/"Newsletter" custom items, which now also resolve to
  `/en/advertise/` / `/en/newsletter/` (page-backed destinations + `conexao_lang_url`).
  The footer menu's per-language assignment remains the operator step recorded in the
  Stage 4.3 runbook (§7 of the digest); the `after` verification captures the rendered
  footer for review.
- **Header CTA**: fixed in this stage to `conexao_lang_url( '/anuncie/' )` — EN visitors
  now reach `/en/advertise/` (previously hardcoded PT).
- **Language switcher**: unchanged (text-only PT/EN, template part from Stage 2); it
  links PT⇄EN per page through the translation group, which now exists for every page.
- No page-list fallback is used anywhere (the safe empty fallback remains the guard).

## 16. Breadcrumbs

Page breadcrumbs are `Início → <page title>`. On EN pages: "Home" (translated gettext) →
the EN page title, with the home link rewritten by Polylang to `/en/` (the bare-home-URL
rule) — verified per page in the `after` matrix (breadcrumb home link is the EN home; no
PT "Início"; the EN title present). With no page hierarchy, no parent crumbs exist; the
breadcrumb renderer walks parents through translated permalinks automatically for any
future hierarchy.

## 17. Translation relationships

The importer creates exactly ONE linked EN translation per PT page and verifies the link
**from both directions** (`pll_get_post(pt,'en')` and `pll_get_post(en,'pt')`) before
reporting success; the WP-CLI runner prints the full relationship table; the PHP test
suite re-verifies it post-rollout (no shared EN page across two PT sources, no orphan EN
page outside the map). Production EN IDs do not exist yet (NOT_EXECUTED) — the planned
relationship table (37 rows, all top-level → no parents):

| PT ID | PT slug | EN ID | EN slug | parent PT | parent EN | status |
|---|---|---|---|---|---|---|
| 9 | inicio | *(assigned at apply)* | home | — | — | authored + validated |
| 11 | sobre-nos | *(assigned at apply)* | about-us | — | — | authored + validated |
| 12 | contato | *(assigned at apply)* | contact | — | — | authored + validated |
| 11086 | empregos | *(assigned at apply)* | jobs | — | — | authored + validated |
| 10 | blog | *(assigned at apply)* | blog (shared slug) | — | — | authored + validated |
| 20 | moradia | *(assigned at apply)* | housing | — | — | authored + validated |
| 11293 | saude | *(assigned at apply)* | healthcare | — | — | authored + validated |
| 11294 | transporte | *(assigned at apply)* | transport | — | — | authored + validated |
| 22 | familia | *(assigned at apply)* | family | — | — | authored + validated |
| 24 | financas | *(assigned at apply)* | finances | — | — | authored + validated |
| 11295 | beneficios | *(assigned at apply)* | benefits | — | — | authored + validated |
| 26 | educacao | *(assigned at apply)* | education | — | — | authored + validated |
| 11296 | documentos | *(assigned at apply)* | documents | — | — | authored + validated |
| 11297 | onde-comer | *(assigned at apply)* | where-to-eat | — | — | authored + validated |
| 11299 | turismo | *(assigned at apply)* | tourism | — | — | authored + validated |
| 11300 | compras | *(assigned at apply)* | shopping | — | — | authored + validated |
| 32 | negocios | *(assigned at apply)* | business | — | — | authored + validated |
| 33 | servicos | *(assigned at apply)* | services | — | — | authored + validated |
| 11301 | voluntariado | *(assigned at apply)* | volunteering | — | — | authored + validated |
| 11302 | categorias | *(assigned at apply)* | categories | — | — | authored + validated |

| 35 | irlanda | *(assigned at apply)* | ireland | — | — | authored + validated |
| 36 | europa | *(assigned at apply)* | europe | — | — | authored + validated |
| 37 | laois | *(assigned at apply)* | county-laois | — | — | authored + validated |
| 38 | dublin | *(assigned at apply)* | county-dublin | — | — | authored + validated |
| 39 | cork | *(assigned at apply)* | county-cork | — | — | authored + validated |
| 40 | galway | *(assigned at apply)* | county-galway | — | — | authored + validated |
| 41 | limerick | *(assigned at apply)* | county-limerick | — | — | authored + validated |
| 42 | kildare | *(assigned at apply)* | county-kildare | — | — | authored + validated |
| 43 | meath | *(assigned at apply)* | county-meath | — | — | authored + validated |
| 44 | wicklow | *(assigned at apply)* | county-wicklow | — | — | authored + validated |
| 45 | waterford | *(assigned at apply)* | county-waterford | — | — | authored + validated |
| 13 | newsletter | *(assigned at apply)* | newsletter (shared slug) | — | — | authored + validated |
| 14 | revista | *(assigned at apply)* | digital-magazine | — | — | authored + validated |
| 15 | anuncie | *(assigned at apply)* | advertise | — | — | authored + validated |
| 16 | politica-de-privacidade | *(assigned at apply)* | privacy-policy | — | — | authored + validated |
| 17 | termos-de-uso | *(assigned at apply)* | terms-of-use | — | — | authored + validated |
| 18 | cookies | *(assigned at apply)* | cookie-policy | — | — | authored + validated |

## 18. SEO

Per EN page (asserted in the `after` matrix): `<title>` carries the EN page title;
`meta name=description` equals the authored EN description; **canonical is the EN page's
own URL** (self-canonical — never the PT source); `og:locale` follows
`conexao_current_locale()` (`en_US` on EN pages — Stage 1 mechanism, unchanged); robots
index (pages are indexable; `search`/404 stay noindex). B2 canonical-to-PT no longer
applies to any page (no page renders B2); B1 redirect-only URLs never render a canonical.
The two homepage-family URLs keep the established special canonicals: `/` and `/en/` are
self-canonical; the posts pages (`/blog/`, `/en/blog/`) canonicalize to their language
home (`is_front_page() || is_home() → home_url('/')` — the theme's pre-existing rule,
mirrored in both languages).

## 19. hreflang

Single producer (theme `inc/seo.php` `conexao_seo_hreflang()`, data from
`conexao_hreflang_links()` — unchanged in this stage; no page-specific hreflang logic was
created). Expected output per real EN page: `en` → the EN URL, `pt-BR` → the PT URL,
`x-default` → the PT URL (the approved default-language destination), and the mirrored set
on the PT side. The `/` ↔ `/en/` homepage pair keeps its Stage 3.2 shape. No alternate
points at a redirect (the `after` matrix asserts the hreflang set per page). The
Polylang-vs-theme duplicate hreflang question remains the documented Stage 4.x carry-over
(the Stage 4.3 runbook decision item) — deliberately not changed in this stage.

## 20. Sitemap

The theme producer (`conexao_seo_sitemap()`) is the single owner once the Stage 4.3
runbook's Jetpack-sitemap step is done. After the apply: every EN page appears **exactly
once** with its `<xhtml:link>` alternates (Stage 3.1 EN pass — unchanged code), the EN
front page is the `/en/` language-home entry, B2/B1 URLs are never listed
(`check_sitemap()` in the verifier asserts: 1× per EN URL, no redirecting `/en/` URLs, no
duplicates). `/sitemap.xml` and `/sitemap_index.xml` both serve the theme output
(unchanged).

## 21. Search

No search redesign (out of scope). Expected post-rollout behaviour (asserted by the
operator's `after` run): EN WordPress search (`/en/?s=…`) surfaces the real EN pages
(pages are B1-type records: real EN records only — never a duplicate PT copy);
`conexao_b2_search_widen_query` does not widen for pages; PT search remains byte-identical
(PT pages keep their language/content; nothing in this stage writes PT records). The
`search` utility page stays robots-disallowed in both languages.

## 22. Cache

No new caching system (out of scope). Verified by design (Stage 2/3.2 mechanisms,
unchanged): the language-scoped transient family (`conexao_home_*_{pt,en}`,
`conexao_404_*_{pt,en}`) keys on the language suffix; PT and EN homepage caches are
written and invalidated independently (`conexao_flush_language_cache()` deletes the
un-suffixed key plus every language suffix on save); warming PT creates no EN keys and
vice versa. Translation creation goes through `wp_insert_post`, so the existing
save/trash invalidation hooks apply to the new EN records exactly as they do to PT ones.
The WordPress.com edge cache keys on the URL path (`/en/…` is a distinct path) — the
runbook's post-rollout edge-cache flush step covers it.

## 23. PT regression

**Baseline checkpoint captured**: `stage45-work/pt-snapshot.json` (43 pages; per page:
title, content sha256, excerpt sha256, status, parent, menu order, template, permalink,
featured media, modified, meta description, canonical — from the measured REST + HTML
captures). **Self-comparison passes: 0 differences.**

The migration mechanism *cannot* modify PT records by construction (no update call ever
targets a PT id), and it *proves* it per run: `conexao_page_translation_run()` snapshots
every PT page before the run and re-verifies after it, tripping a hard error
("PT SOURCE CHANGED — regression gate tripped") on any field drift. Post-deployment, the
operator re-runs `scripts/stage45-pt-snapshot.py` and diffs:

```bash
python3 scripts/stage45-pt-snapshot.py --out /tmp/pt-after.json
python3 scripts/stage45-pt-snapshot.py --compare stage45-work/pt-snapshot.json /tmp/pt-after.json
```

Expected: **identical (0 unintended PT changes)**. The BEFORE HTTP matrix additionally
proves the PT *rendering* surface is intact today (83/83 rows: PT 200s, self-canonical,
`/inicio/`→`/` consolidation, legacy alias 301s unchanged).


## 24. Translation completeness scanner

`scripts/stage45-translation-completeness-scan.py` (read-only; review signal only — it
never rewrites content and never decides translation quality). It checks the title, body,
meta description and structure of every EN page and produces
`translated | possible-untranslated | empty | excluded-technical` rows.

- **Manifest mode (run now):** 37 translated, 0 possible-untranslated, 0 empty. One
  initial flag (`contato`: the word "com" inside the `tdcriativo@gmail.com` email) was
  **manually inspected** — a false positive by construction — and the scanner was taught
  to strip emails/URLs before the marker scan (a scanner-side fix, not a content change).
- **Live mode (post-deployment):** `python3 scripts/stage45-translation-completeness-scan.py --live`
  fetches the deployed EN pages over the public REST API and re-runs the same checks.
- Design notes: the scanner combines Portuguese function-word markers, diacritic
  detection outside allow-listed brand names, a block-skeleton comparison against the PT
  original, and a byte-identical-body check — keyword detection alone never classifies a
  page. Every "possible-untranslated" result requires manual inspection before any
  classification.

## 25. Tests

**Ran in this environment (all green):**

| Check | Result |
|---|---|
| `scripts/stage45-validate-manifest.py` (37-page manifest: coverage, order, slug rules/collisions, shared-slug allowlist, block parity, link resolution, meta descriptions, exclusion parity) | **ALL MANIFEST CHECKS PASSED** |
| `scripts/stage45-translation-completeness-scan.py` (manifest mode) | 37/37 translated, 0 flags, 0 empty |
| `scripts/stage45-verify-pages.py --phase before` (live production HTTP matrix, 83 rows) | **83 passed / 0 failed** |
| `scripts/stage45-pt-snapshot.py --compare` (self) | identical — 0 unintended PT changes |
| `scripts/stage45-build-en-catalog.py --check` (po/mo sync after completion) | 384/384 entries translated, no drift |
| EN slug collision probes vs live Media Library (37 slugs) | 0 collisions |
| `git diff --check` | clean |
| `python3 -m py_compile` on every new/changed Python script | clean |

**NOT runnable in this environment (no PHP runtime, no Docker — documented, not skipped
silently):** `php -l` (replaced by a structural brace/paren/string balance check over every
new/changed PHP file — passed), the PHP test suites, and the WP-CLI dry run. These run on
staging/CI per the operator instructions (§31): `php -l` per file, then

```bash
php wp-content/themes/conexao-br-irlanda/tests/test-i18n-foundation.php
php wp-content/themes/conexao-br-irlanda/tests/test-polylang-foundation.php
php wp-content/themes/conexao-br-irlanda/tests/test-stage32-bilingual.php
php wp-content/themes/conexao-br-irlanda/tests/test-stage33-bilingual.php
php wp-content/themes/conexao-br-irlanda/tests/test-stage41-rest-language.php
php wp-content/themes/conexao-br-irlanda/tests/test-nav-menu-regression.php
php wp-content/themes/conexao-br-irlanda/tests/test-guide-breadcrumb-filter.php
php wp-content/themes/conexao-br-irlanda/tests/test-stage45-pages.php     # NEW (Stage 4.5)
wp eval-file scripts/stage45-translate-pages.php                          # NEW — dry run first
wp eval-file scripts/stage45-translate-pages.php apply                    # then apply
```

plus the existing event/leisure/admin-ux suites listed in the task (their subjects are
untouched by this stage; the theme edits are the three guarded changes in §27).

## 26. HTTP verification matrix

**Measured (BEFORE, production, 2026-09-23): 83 rows — 83 passed / 0 failed.**
Artifacts: `stage45-work/http-matrix-before.{json,md}` + `-raw/` captures. Coverage:
36 PT page URLs + `/` (200, self-canonical, PT titles), 37 planned EN URLs (all non-200 —
no EN layer exists), `/inicio/` → 301 `/`, `/en/` non-200, 4 legacy alias 301s, the
excluded `search` page (200), and the eventos/guias archives.

**Prepared (AFTER, 123 rows + sitemap pass)** — `scripts/stage45-verify-pages.py --phase
after --out stage45-work/http-matrix-after`: per translated page — PT 200 + self-canonical
+ `pt-BR` html lang + full hreflang pair + PT title; EN 200 + self-canonical EN + `en-US`
html lang + EN title + EN meta description + no B2 notice + EN breadcrumb; PT-slug-under-
`/en/` redirect to the EN page (35 rows); homepage pair (`/`, `/en/`, `/inicio/`, `/en/home/`);
blog shared-slug pair; legacy aliases; excluded-page behaviour (`/en/search/` 302, no
`/en/leisure/` page, `/en/lazer/` archive); archives; and the sitemap pass (every EN URL
exactly once, no redirecting URLs). Representative deep/legal/county/template/Gutenberg/
external-link pages are all inside the per-page groups (every one of the 37 pages is
probed — the matrix is exhaustive, not sampled).


## 27. Exact files changed

**Created:**

| File | Role |
|---|---|
| `wp-content/plugins/conexao-page-translation/conexao-page-translation.php` | Stage 4.5 importer plugin (admin screen, dry-run/apply, reports) |
| `wp-content/plugins/conexao-page-translation/includes/translation-map.php` | the 37 human-authored EN page translations (single source of truth) |
| `wp-content/plugins/conexao-page-translation/includes/apply.php` | creation/linking/verification engine + Polylang-relationship link localizer + shared-slug permit |
| `scripts/stage45-translate-pages.php` | WP-CLI runner (local/staging) — same map, same engine |
| `scripts/stage45-page-inventory.py` | Phase 0 read-only inventory builder |
| `scripts/stage45-validate-manifest.py` | static manifest validator + JSON manifest renderer |
| `scripts/stage45-pt-snapshot.py` | Phase 23 PT regression checkpoint/diff |
| `scripts/stage45-verify-pages.py` | Phase 28 HTTP acceptance matrix (before/after + sitemap) |
| `scripts/stage45-translation-completeness-scan.py` | Phase 22 completeness scanner (manifest/live) |
| `scripts/stage45-build-en-catalog.py` | en_US catalog completion + .mo compiler (+ `--check` drift guard) |
| `wp-content/themes/conexao-br-irlanda/tests/test-stage45-pages.php` | Stage 4.5 PHP test suite |
| `docs/plugins/conexao-page-translation.md` | plugin documentation |
| `stage45-work/*` | measured artifacts: page-inventory.{json,md}, translation-manifest.json, pt-snapshot.json, http-matrix-before.{json,md}+raw, completeness-scan.{json,md}, inventory-cache/ |

**Modified (theme — three guarded changes + the catalog):**

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/page.php` | page-header eyebrow lookup resolves through the translation group (PT-slug-keyed map; PT rendering byte-identical) |
| `wp-content/themes/conexao-br-irlanda/header.php` | the 3 "Anuncie Aqui" CTA links use `conexao_lang_url( '/anuncie/' )` instead of `home_url( '/anuncie/' )` (PT output byte-identical: the helper returns `home_url( $path )` on the default language / inactive Polylang) |
| `wp-content/themes/conexao-br-irlanda/inc/polylang.php` | `conexao_lang_url_object()` normalises a shared-slug page lookup to the default-language source (no-op for unique slugs / inactive Polylang) |
| `wp-content/themes/conexao-br-irlanda/languages/en_US.po` + `en_US.mo` | catalog completed: 384/384 entries translated (was 67); .mo recompiled and validated by an independent reader |

**Modified (docs/build):** `docs/routing.md` (Stage 4.5 rollout section), `docs/plugins/README.md`
(8th plugin), `AGENTS.md` (plugin table row), `scripts/build-plugins-zip.sh` (package the new plugin).

**Untouched by design:** `inc/rest-language.php` (Stage 4.1 REST contract — FROZEN),
`inc/seo.php` (single SEO owner — no new hreflang/canonical/sitemap logic),
`pt_BR.po`/`pt_BR.mo`, every plugin other than the new one, all PT content records,
`.htaccess`, Flutter (never accessed).

## 28. Exact scripts created

1. `scripts/stage45-page-inventory.py` — Phase 0 inventory builder (read-only, paced,
   cached, re-runnable; embeds the reviewed classification with evidence).
2. `scripts/stage45-build-en-catalog.py` — completes + compiles the theme's en_US gettext
   catalog (idempotent; `--check` for drift).
3. `scripts/stage45-translate-pages.php` — WP-CLI apply runner (local/staging).
4. `scripts/stage45-validate-manifest.py` — static manifest validation + the deployable
   JSON manifest (`--emit-manifest`).
5. `scripts/stage45-pt-snapshot.py` — PT regression checkpoint + `--compare` differ.
6. `scripts/stage45-verify-pages.py` — HTTP acceptance matrix (before/after, resume,
   sitemap pass).
7. `scripts/stage45-translation-completeness-scan.py` — completeness review scanner
   (manifest + live modes).

Plus the migration plugin itself (`wp-content/plugins/conexao-page-translation/` — the
production write mechanism, see §31 for why a plugin and not a REST script) and the PHP
test suite (`tests/test-stage45-pages.php`).

## 29. Exact records created

**On production: none — the production apply is NOT_EXECUTED** (§31). No EN page, no
Polylang link, no menu item, no PT record was created or modified on
https://conexaobr.ie by this stage. The complete, validated creation set is the
**37-page manifest** (`stage45-work/translation-manifest.json`, rendered from
`includes/translation-map.php`): 37 EN pages to create (each with title, slug, Gutenberg
content, meta description, template parity, featured-media copy, `_empregos_link`
pass-through and internal-link resolution map), 0 PT records to touch, 2 shared-slug
permits (`blog`, `newsletter`), 0 menu-data changes (render-time resolution covers the EN
nav; the per-language menu assignments are the Stage 4.3 operator steps).


## 30. Exact records excluded

| ID | slug | title | reason | evidence |
|---|---|---|---|---|
| 19 | `search` | Buscar | Search utility page — the entire body is a single `wp:search` block; no editorial content; deliberately de-indexed and unlinked by the theme | `inc/seo.php` `$excluded_pages` contains `search`; robots.txt `Disallow: /search/` and `/en/search/`; measured content = one search form (2 words); no menu item anywhere; the 404 template uses `get_search_form()` (`/?s=`), never this page; `/search/` 200 measured |
| 61 | `privacidade` | Privacidade | Obsolete alias page — its whole body is a "page moved" notice, and the URL is permanently redirected before the page can ever render | `inc/seo.php` redirect map `/privacidade` → `/politica-de-privacidade/`; HTTP 301 measured 2026-09-23; sitemap-excluded; created by the seed's "FOOTER REDIRECT PAGES" section |
| 62 | `termos` | Termos | Obsolete alias page (same pattern as `privacidade`) | `inc/seo.php` `/termos` → `/termos-de-uso/`; HTTP 301 measured; sitemap-excluded |
| 63 | `sobre` | Sobre | Obsolete alias page (same pattern) | `inc/seo.php` `/sobre` → `/sobre-nos/`; HTTP 301 measured; sitemap-excluded |
| 1 | `about` | About | WordPress installer sample page — English filler boilerplate ("This is an example of a page…", links to wordpress.com/page/new); never site content; additionally shadowed by the `/about/` 301 | Measured content (WP.com boilerplate); HTTP 301 `/about/` → `/sobre-nos/` measured; `inc/seo.php` map entry + `.htaccess` rule |
| 11298 | `lazer` | Lazer | Shadowed page — `/lazer/` is served by the leisure CPT archive (`has_archive='lazer'`), so this page's own permalink never renders its content; creating an EN translation would invent an asymmetric `/en/leisure/` URL with no reachable PT counterpart | `conexao_lang_url()` docblock: "/lazer/ is the leisure archive even though a canonical lazer page also exists"; measured `/lazer/` HTML renders the archive grid (title "Lazer & Turismo na Irlanda \| Conexão BR"), never the page body |

**Content quality table** (per translated page — untranslated-content flags / metadata
gaps / link issues / status): all 37 rows report `translated` with **no flags, no metadata
gaps, no link issues** — full table in `stage45-work/completeness-scan.{json,md}`.

## 31. Production deployment status

**NOT_EXECUTED — with a complete, validated migration package and exact operator
instructions.**

Why (measured, not assumed):

1. **Production has no English layer to translate into.** Polylang is not installed
   (`/wp-json/pll/v1/languages` → 404) and the deployed theme is the pre-Stage-1 build.
   The Stage 4.3 runbook (Polylang Free 3.8.9 + theme ZIP + plugin ZIPs + settings) is the
   prerequisite and is itself still pending.
2. **No production write path exists in this environment.** WordPress.com offers no
   SSH/WP-CLI; the REST API cannot write Polylang translation links on Free 3.8.9
   (verified against the plugin sources: `lang` is honoured on write, `translations` is
   admin-form only); no wp-admin session or application password is available here (the
   only credential visible in the repo is the one Stage 4.3 already flagged for rotation —
   deliberately not used).
3. The task's Phase 24 explicitly defines this outcome: stop after validation, deliver the
   manifest + deployable data + operator instructions, and classify the production portion
   as NOT_EXECUTED.

**Operator instructions (exact, in order):**

1. Execute the Stage 4.3 runbook first (Polylang Free 3.8.9 + Stage-4.3 theme ZIP +
   plugin ZIPs + language settings + content assignment + menus). Verify with
   `scripts/stage43-verify-english.py --phase after`.
2. Deploy this stage's artefacts: rebuild and upload the **theme ZIP**
   (`./scripts/build-theme-zip.sh` — it carries the three guarded changes + the completed
   en_US catalog) and the **`conexao-page-translation` plugin ZIP**
   (`./scripts/build-plugins-zip.sh`). Activate the plugin.
3. Take a fresh PT checkpoint: `python3 scripts/stage45-pt-snapshot.py --out /tmp/pt-pre-apply.json`.
4. In wp-admin → Tools → **EN Page Translations**: run **Preview (dry run)** — expect
   37 × "would-create", 0 errors, and the categorias link-resolution map. Then **Apply** —
   expect 37 × "created … linked both ways", `PT pages changed: 0`.
5. Verify (read-only):
   - `wp eval-file scripts/stage45-translate-pages.php` — a re-run reports 37 × "exists" (idempotency proof);
   - `php wp-content/themes/conexao-br-irlanda/tests/test-stage45-pages.php` — the relationship table;
   - `python3 scripts/stage45-verify-pages.py --phase after --out stage45-work/http-matrix-after` — the 123-row matrix + sitemap pass;
   - `python3 scripts/stage45-pt-snapshot.py --compare /tmp/pt-pre-apply.json <fresh snapshot>` — 0 PT changes;
   - `python3 scripts/stage45-translation-completeness-scan.py --live` — 37 translated, 0 flags.
6. Assign the EN menus if not already done by the Stage 4.3 runbook (wp-admin → Menus →
   per-language tabs: `primary` → "Main Menu", `footer` → the footer menu for EN), then
   re-check the header/footer links in the matrix output.
7. Deactivate (and optionally delete) the `conexao-page-translation` plugin after the
   rollout; its data lives in the theme-independent map file, committed to the repo.

**Local/staging validation first (as the task requires):** with Docker available,
`wp eval-file scripts/stage45-translate-pages.php` (dry run) → `... apply` → the full test
list in §25 → only then execute the production steps above.


## 32. Known limitations

1. **Production apply NOT_EXECUTED** (§31) — the translations exist as validated,
   deployable data, not as live production pages. This is the sole reason the stage is
   not a full PASS.
2. **PHP-level validation deferred to staging**: this environment has no PHP runtime or
   Docker, so `php -l`, the PHP test suites and the WP-CLI dry run could not be executed
   here. Structural checks + code review + the Python simulation suite stand in (§25).
3. **Shared slugs (`blog`, `newsletter`)** rely on the scoped `wp_unique_post_slug`
   permit (the Polylang-Pro-style pattern). Validated against the Polylang 3.8.9 sources
   (pagename auto-translate disambiguates by language) and hardened in
   `conexao_lang_url_object()`, but not executed against a live WordPress here.
4. **The EN posts archive will be (initially) empty**: `/en/blog/` lists EN-language posts
   only — there are none on production yet (blog *posts* are explicitly out of scope for
   this stage). The page renders its EN chrome + the English empty-state message until
   the blog translation workflow lands EN posts.
5. **Footer menu EN assignment** and the **Jetpack-sitemap handover** remain operator
   steps from the Stage 4.3 runbook (data/configuration, not code).
6. The **Polylang duplicate hreflang block** (theme vs Polylang `wp_head`) remains the
   documented Stage 4.x carry-over — unchanged, same URLs, not a Stage 4.5 defect.
7. `advanced_seo_description` / `jetpack_seo_*` WordPress.com meta were audited (empty on
   every page) and intentionally not replicated to the EN records.

## 33. Stage 4.6 website prerequisites

- Execute the Stage 4.3 runbook + the Stage 4.5 operator instructions (§31) — after that,
  every public Page exists in both languages with correct hreflang/sitemap/nav.
- Translate the **blog posts** (the only remaining PT-only *content* surface a page
  visitor reaches from `/en/blog/`); the empty-state message is already English.
- Complete the EN **guides** translation workflow (guides are B1; the EN homepage already
  links `/en/guias/` and the linked EN category terms).
- Decide the hreflang consolidation (Stage 3.3 §19 item 12) — exactly one emitter.
- Refresh `scripts/stage33-translation-inventory.php` / admin-ux translation-state views
  against the post-rollout dataset (they predate the page rollout).
- Re-run the full Stage 4.5 verification suite after each future content change (the
  scripts are idempotent and cached).

## 34. Final acceptance matrix

| # | Requirement | State |
|---|---|---|
| 1 | Every eligible public Page has a real linked EN translation | **37/37 authored + validated** (production apply NOT_EXECUTED — §31) |
| 2 | EN translations are real Polylang translations (linked, exactly one, verified both ways) | Engine enforces + verifies; PHP test re-checks post-rollout |
| 3 | Parent/child hierarchy preserved | All pages top-level (measured); future-hierarchy guard implemented |
| 4 | EN pages have natural English content | 37/37 human-authored; scanner: 0 flags; block parity 37/37 |
| 5 | Internal links point to EN counterparts | Resolver through the Polylang relationship; simulated map validated (18 links) |
| 6 | PT originals unchanged | By construction + PT snapshot gate; baseline self-compare 0 diffs |
| 7 | SEO metadata translated | 37/37 EN meta descriptions; titles/slugs natural |
| 8 | Navigation/breadcrumbs resolve correctly | Render-time layer covers all page-backed items; breadcrumb assertions in the after-matrix |
| 9 | No page unnecessarily on B1 | Post-rollout: every translated page's EN URL is real; only the excluded `search` utility keeps B1 (documented) |
| 10 | No page unnecessarily on B2 | Post-rollout: 0 pages render B2 (the 10 allowlisted pages all become real translations); mechanism kept for CPTs |
| 11 | hreflang/canonical/sitemap correct | Single-owner policy untouched; assertions encoded in the after-matrix + sitemap pass |
| 12 | REST contract unchanged | `inc/rest-language.php` untouched; `page` still excluded (documented) |
| 13 | Flutter untouched | Never accessed/inspected/built/modified |
| 14 | Tests | All environment-runnable checks green (§25); PHP suites staged for staging/CI |
| 15 | Production safety | No production write attempted; validated package + exact operator instructions delivered |


## 35. Final classification

**ENGLISH STAGE 4.5 — PASS WITH LIMITATION**

The deliverable set is complete and validated to the maximum this environment allows:
every eligible public Page (37/37) is translated, structured, linked-by-design and
deployable; every exclusion is evidenced; the PT surface is proven intact (measured
before-matrix 83/83); the production apply is **NOT_EXECUTED** because production has no
English layer yet and no production write path exists here — exactly the contingency the
task defines, delivered as a complete migration package + exact operator instructions
(§31) rather than a false claim that the website is translated.

---

**Final inventory table (Phase 29):**

| Type | Total | EN translated | Missing | Excluded technical | B1 remaining | B2 remaining |
|---|---:|---:|---:|---:|---:|---:|
| Public editorial Pages (target set) | 37 | 37 | **0** | 0 | 0 post-rollout | 0 post-rollout |
| Technical/system pages (excluded) | 6 | — | — | 6 | 1 (`search`, intentional) | 0 |
| **All published Pages** | **43** | **37** | **0** | **6** | **1** | **0** |

Every non-zero value outside the expectation is accounted for: the single remaining
page-level B1 is the excluded `search` utility page (§30 — the task's own example of a
legitimate technical exclusion); B2 remaining is zero for pages because all 10
allowlisted pages receive real translations (the B2 *mechanism* remains active and
unchanged for the CPT allowlist). EN "translated" = authored, validated and deployable —
the production apply itself is NOT_EXECUTED (§31).

---

### Final numbers (required reporting)

| Metric | Value |
|---|---|
| Total public Pages discovered | **43** |
| Total EN Pages created (authored, validated, deployable) | **37** |
| Total EN Pages already existing on production | **0** (no English layer deployed — measured) |
| Total Pages excluded (technical) | **6** — `about` (installer sample, 301-shadowed), `privacidade`/`termos`/`sobre` (obsolete alias pages, 301-shadowed), `search` (utility page, de-indexed), `lazer` (shadowed by the leisure archive) — each with measured evidence (§30) |
| Total parent/child relationships created | **0** (no production page has a parent; the hierarchy-preserving mechanism is implemented and enforced) |
| Total internal links updated at apply time | **18** in `categorias` (15 → EN page translations, 3 → EN archives), resolved through the Polylang relationship; plus the header CTA (theme fix, 3 instances) and the render-time nav layer |
| Total SEO metadata translated | **37** EN meta descriptions + 37 EN titles + 384 gettext catalog strings (the EN page chrome) |
| Total B1 pages remaining (post-rollout) | **1 page-level** — the excluded `search` utility (documented, intentional); **0 eligible editorial pages** |
| Total B2 pages remaining (post-rollout) | **0 pages** (the 10 allowlisted pages all get real translations; the B2 mechanism remains for CPTs) |
| Exact production deployment status | **NOT_EXECUTED** — Stage 4.3 deployment prerequisite still pending on production; no production write path from this environment; complete operator instructions in §31 |
| Final classification | **ENGLISH STAGE 4.5 — PASS WITH LIMITATION** |


