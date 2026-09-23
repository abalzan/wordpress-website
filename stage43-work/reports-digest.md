# Stage 4.3 Production Deployer Digest — English/Polylang Work (Stages 0–4.2)

Compiled from the 9 reports listed below plus the repository files they cite. Every literal value below is
quoted from a report section or from the cited source file. **Nothing here was invented, and nothing in the
repository was modified** (this digest lives in `/workspace/stage43-work/` only).

## 0. Sources, precedence, and how to read this

| Doc | Role |
|---|---|
| `CONEXAO_BR_ENGLISH_SUPPORT_AUDIT.md` | Audit (Phase 1–10), 2026-09-20. Problem statement + options. |
| `CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md` | **APPROVED** architecture (Stage 0). Decisions A–P, §1–§27. |
| `CONEXAO_BR_ENGLISH_STAGE_1_REPORT.md` | i18n foundation (gettext, locale). PASS. |
| `CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md` | Polylang install + `/en/` routing + guards. PASS. |
| `CONEXAO_BR_ENGLISH_STAGE_3_1_REPORT.md` | B2 fallback + B1 preserved. PASS WITH LIMITATION (`/en/` → `/en/home/`). |
| `CONEXAO_BR_ENGLISH_STAGE_3_2_REPORT.md` | EN front page fix, 7 page translations, B2 allowlist, content pilot, taxonomy correction, **production deployment plan (written, not executed)**. PASS WITH LIMITATION. |
| `CONEXAO_BR_ENGLISH_STAGE_3_3_REPORT.md` | EN chrome links, taxonomy presentation, full re-verification, production-readiness review. PASS WITH LIMITATION. |
| `CONEXAO_BR_ENGLISH_STAGE_4_1_REPORT.md` | Bilingual REST contract (WordPress). PASS WITH LIMITATION (Flutter half not performed). Contract **FROZEN**. |
| `CONEXAO_BR_ENGLISH_STAGE_4_2_REPORT.md` | Flutter client consumes the frozen contract. PASS. |

**Precedence rule used by this digest:** the *code in the repository* is authoritative over report prose
(reports describe local environments whose IDs differ between stages). Where reports disagree, the later
Stage 3.2/3.3/4.x statement wins **and the contradiction is flagged in §14**.

**Three "local-only" ID sets exist** (Stage 2 vs Stage 3.2 vs Stage 3.3 rebuilt their own databases). Any ID
in this digest is tagged `[LOCAL ONLY]`. **Never carry a local post/term ID to production.**

Related, NOT in the 9-report set but present in the repo and relevant to §7 (menus):
`CONEXAO_BR_EN_HEADER_NAVIGATION_FIX_REPORT.md`, `CONEXAO_BR_EN_NAV_LANGUAGE_CONTEXT_FIX_REPORT.md`,
`CONEXAO_BR_HEADER_NAVIGATION_REGRESSION_REPORT.md`, plus `docs/routing.md` (which documents the menu fix).
See §7.6 for the conflict with Stage 3.3 §25.3.

---

## 1. APPROVED PRODUCTION POLYLANG CONFIGURATION

Owner of record: `CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md` §2 "Chosen architecture", §5 "Default
language", §6 "`/en/` URL strategy", §27 "Final decision" (rows A–P). Applied by
`scripts/stage2-polylang-setup.php` (idempotent, accepts `dry-run`) and declared in code by
`wp-content/themes/conexao-br-irlanda/inc/polylang.php`. Stage 2 report §4 "Language configuration" states:
*"Applied by `scripts/stage2-polylang-setup.php` (idempotent; accepts `dry-run`) — the same values the Polylang
admin screens would store"*.

### 1.1 Plugin

| Item | Value (exact) | Source |
|---|---|---|
| Plugin | **Polylang 3.8.9 (Free)** — author `WP SYNTEX` | Stage 2 §2 |
| Install source | `https://downloads.wordpress.org/plugin/polylang.3.8.9.zip`; `wp plugin install polylang --version=3.8.9` | Stage 2 §2 |
| Integrity | `wp plugin verify-checksums polylang` → "Success: Verified 1 of 1 plugins." | Stage 2 §2 |
| Requirements | PHP 7.4+, WordPress 6.5+, tested up to 7.1.1 | Stage 2 §2 |
| Activation | `wp plugin activate polylang` — no warnings/notices/deprecations/fatals from Polylang | Stage 2 §2 |
| **Pro** | **NOT installed, no licence.** Stage 2 §2: *"**Not installed.** No license is available…"*; Stage 3.2 §26.8; Stage 3.3 §19 item 1: *"Install **Polylang Free 3.8.9** (Free only — no Pro)"* | Stage 2 §2, 3.2 §26.8, 3.3 §19.1 |
| Install location (local) | `/var/www/html/wp-content/plugins/polylang` (Docker volume) — **not** vendored in the repo | Stage 2 §2 |

### 1.2 Languages, defaults, URL mode

| Setting (Polylang option key) | Value | Meaning |
|---|---|---|
| Languages | `pt` → locale `pt_BR`; `en` → locale `en_US` | Stage 2 §4 |
| Creation order | `pt_BR` created **first** (first language created wins the default), then re-asserted: `PLL()->model->update_default_lang( 'pt' )` | `scripts/stage2-polylang-setup.php` lines 83–96 |
| `default_lang` | `pt` | Stage 2 §4 |
| `force_lang` | `1` | "language code in a directory (`/en/`)" |
| `hide_default` | `true` | "*the default language has **no** prefix (`/guias/`)*" |
| `rewrite` | `true` | pretty permalinks |
| `browser` | `false` | "*never guess a language from the browser*" |
| `redirect_lang` | `false` | "unchanged front-page behaviour" |
| `media_support` | `false` | "*media is shared, not per-language*" |
| `post_types` option | *(empty)* | translated types declared **in code** so environments cannot drift |
| WP site locale | `WPLANG = pt_BR` | Stage 2 §3, Stage 3.2 §2 |
| Timezone (local; production must match) | `Europe/Dublin` | Stage 3.2 §2 / §27 step 1 |

Exact code (`scripts/stage2-polylang-setup.php` lines 99–106):

```php
$expected = array(
	'force_lang'    => 1,
	'hide_default'  => true,
	'rewrite'       => true,
	'browser'       => false,
	'redirect_lang' => false,
	'media_support' => false,
);
```

Languages are created with `PLL()->model->add_language( array( 'locale' => $locale ) )` (line 74) — **no
`flag` argument is ever passed**, and no report specifies flags. **GAP for the deployer:** the approved
configuration contains no flag setting. The language switcher is **text-only, no flags** — Stage 2 §7:
*"**Labels:** text only — `PT` / `EN`, no flags (flags are not part of the site's visual language)."* Nothing in
the reports constrains the wp-admin flag choice.

### 1.3 Translated post types

| Report state | Value | Note |
|---|---|---|
| Stage 2 §4 | `post`, `page`, `guide`, `event`, `leisure`, `sponsor`, `job`, `course_provider` | "identity-critical CPTs included; admin-only `recruitment_agency` / `permit_employer` excluded" |
| Stage 3.3 §2 baseline (observed) | `post, page, wp_block, guide, event, leisure, sponsor, job, course_provider` | adds `wp_block` (Polylang default) |
| **Code (authoritative)** | `conexao_polylang_translated_post_types()` @ `inc/polylang.php:155`, filter registered line 166 (`add_filter( 'pll_get_post_types', …, 10, 2 )`) **adds**: `guide`, `event`, `leisure`, `sponsor`, `job`, `course_provider` | `post`, `page` (+ `wp_block`) come from Polylang's own defaults; the theme filter only *adds* CPTs |

```php
foreach ( array( 'guide', 'event', 'leisure', 'sponsor', 'job', 'course_provider' ) as $post_type ) {
	$post_types[ $post_type ] = $post_type;
}
```

Excluded by design (`inc/polylang.php` docblock lines 149–151): *"`recruitment_agency` and
`permit_employer` are admin-only (no public URLs) and deliberately excluded, as are attachments (media is
shared)."*

### 1.4 Translated / shared taxonomies (FINAL policy = Stage 3.2 correction)

| Taxonomy | Status | Where |
|---|---|---|
| `conexao_category` | **TRANSLATED** (linked EN terms) | code `inc/polylang.php:199` `conexao_polylang_translated_taxonomies()`, loop `array( 'conexao_category', 'conexao_tag' )`, filter registered line 210 |
| `conexao_tag` | **TRANSLATED** | same |
| `category` (native) | **TRANSLATED** | Polylang default (observed Stage 3.3 §2) |
| `post_tag` (native) | **TRANSLATED** | Polylang default |
| `conexao_county` | **NOT translated — SHARED** | Stage 3.2 §10; code docblock lines ~171–193 |
| `conexao_town` | **NOT translated — SHARED** | same |

Code comment (`inc/polylang.php` ~line 175): *"STAGE 3.2 POLICY CORRECTION — `conexao_county` and
`conexao_town` are proper nouns and are deliberately NOT Polylang-translated anymore."*

**⚠ CONTRADICTION (flag):** Stage 2 §4 lists `conexao_category`, `conexao_county`, `conexao_town`,
`conexao_tag`, `category`, `post_tag` as *"Translated taxonomies"*, and `ARCHITECTURE_DECISION §9` describes
county/town as *"Term translations: one term ID, per-language names"*. **Stage 3.2 §10 supersedes both**
(county/town are not translated at all). Deploy the Stage 3.2/3.3 policy (§6).

### 1.5 Existing-content language assignment (production step)

Method: Polylang's own bulk routine `PLL_Model::set_language_in_mass()` — the same code path as the wizard's
"assign untranslated contents"; no custom SQL, no duplication, no meta writes
(`scripts/stage2-polylang-setup.php` lines 147–148; Stage 2 §5). Local result: **objects without a language
2687 posts / 366 terms → 0 posts / 0 terms**, all content counts unchanged (Stage 2 §5). Production wording
(Stage 3.2 §27 step 1): *"Assign the default language to all existing content through Polylang's 'assign
untranslated contents' action (never hand-assign; never duplicate)."*

### 1.6 Output-level consequences (verified)

| Surface | PT | EN |
|---|---|---|
| `<html lang>` | `pt-BR` | `en-US` |
| `og:locale` | `pt_BR` | `en_US` |
| Locale plumbing | `conexao_current_locale()` (`inc/i18n.php:48`, `apply_filters( 'conexao_current_locale', … )` line 62) → Polylang adapter `conexao_polylang_current_locale()` @ `inc/polylang.php:337`, filter line 353 | `pll_current_language( 'locale' )` |
| Cache keys | `conexao_*_pt` | `conexao_*_en` (suffix built by `conexao_language_suffix()` = `'_' . sanitize_key( $slug )`, `inc/polylang.php:425`; `conexao_lang_cache_key()` line 440) |
| Edge cache | WordPress.com edge cache keys on URL path; `/en/` is a distinct path → no `Vary: Accept-Language` needed (Stage 2 §17, decision §18) | — |


---

## 2. ENGLISH FRONT PAGE mechanism

Primary source: Stage 3.2 §3 "Static-front-page configuration (Phase 1)"; Stage 3.1 §12 "Homepage translation"
(the blocker); `inc/polylang.php` lines 212–335; Stage 2 §8 note on the front page.

### 2.1 Mechanism (exact)

| Layer | Mechanism | Detail |
|---|---|---|
| Which record serves `/en/` | **Polylang's own per-language `page_on_front`**, derived from the translation set of the site's `page_on_front` option (`PLL_Static_Pages`) | Stage 3.2 §3: *"the English record *is* the English front page because it is the linked translation of the Portuguese front page — that mechanism already worked in Stage 3.1"* |
| Which URL Polylang publishes as the language home | Declared through Polylang's language-construction pipeline | `conexao_polylang_language_home_data()` hooked on **`pll_additional_language_data`** (priority **20**), returns `trailingslashit( PLL()->links_model->home_url( $slug ) )` as the language `home_url` — code `inc/polylang.php:250`, filter line 262 |
| Read-time mirror | `conexao_polylang_language_home_url()` on **`pll_language_home_url`** (priority 20) — no-op unless a site defines `PLL_CACHE_HOME_URL=false` | code `inc/polylang.php:273`, filter line 285 |
| Self-heal guard | `conexao_polylang_language_home_ensure()` (called at theme load, `inc/polylang.php:302`), plus `conexao_polylang_language_home_serves_front_page()` on `redirect_canonical` priority 20 (`inc/polylang.php:949`, filter line 988) | Stage 3.2 §3: Polylang builds its language list at `plugins_loaded` priority 1 *before* the theme loads; if the cache is rebuilt in that window the stored `home_url` reverts to `/en/home/`. The guard compares and rebuilds once; *"the steady state costs one comparison and zero writes."* |
| `/inicio/` PT consolidation | Polylang's canonical: `/inicio/` → `/` | Stage 3.2 §3 table; unchanged in Stage 3.3 §18 |
| `/en/home/` → `/en/` | Polylang's own canonical consolidation (301) | Stage 3.2 §3; Stage 3.3 §18 |
| Switcher | `template-parts/language-switcher.php`, rendered by `conexao_language_switcher()`; desktop in `.header-actions`, mobile in drawer `.mobile-menu-language`; labels `PT`/`EN` | Stage 2 §7 |

Stage 3.2 §3 explicit statement: *"No rewrite rule, no `template_redirect` special case, no hidden URL was
added: Polylang's own canonical now consolidates `/en/home/` → `/en/`, the mirror of `/inicio/` → `/`."*

### 2.2 Exact local IDs — [LOCAL ONLY, NOT production-portable]

| Item | Stage 3.2 [LOCAL ONLY] | Stage 3.3 [LOCAL ONLY] |
|---|---|---|
| PT front page | `inicio` ID **4** (`pll_get_post(4,'en')=126`) | "PT `inicio` #4" |
| EN front page | `home` ID **126** | "EN `home` #126" |

The **only** production-portable facts: the EN front page is a **linked Polylang translation of the PT front
page**, its **slug is `home`** (Stage 3.2 §27 step 3: *"Create the English homepage as a linked translation of
the PT front page (slug `home`, title `Home`)"*), and `pll_home_url('en')` must equal `/en/`.

### 2.3 Verified behaviour (Stage 3.2 §3 table, HTTP)

| Check | Result |
|---|---|
| `pll_home_url('en')` / `pll_home_url('pt')` | `/en/` / `/` |
| `/en/` | **200**, front-page template (`front-page.php`, hero renders), `<html lang="en-US">` |
| `/` | **200**, `<html lang="pt-BR">`, unchanged |
| `/en/home/` | **301 → `/en/`** |
| `/inicio/` | **301 → `/`** (PT parity) |
| canonical `/en/` and `/` | self-referential both |
| hreflang `/en/` | `en` → `/en/`, `pt-BR` → `/`, `x-default` → `/` |
| hreflang `/` | `pt-BR` → `/`, `en` → `/en/`, `x-default` → `/` |
| switcher `/` / `/en/` | `PT` current / `EN` current respectively |
| homepage cache | `conexao_home_*_pt` and `_en` written independently; warming PT creates **no** EN keys and vice versa |
| resilience | after `delete_transient('pll_languages_list')`: `/en/` 200 and `/en/home/` 301 → `/en/` again |

Stage 3.1 §12 recorded the pre-fix root cause for reference: *"`pll_home_url('en')` itself returns
`http://localhost:8080/en/home/`"* — the fix was configuration through Polylang's API, not code suppression.

**Production warning (Stage 3.2 §27 step 3):** *"If `/en/` still redirects to `/en/home/`, the deployed theme
ZIP is not the Stage 3.2 theme (the self-heal guard is missing)."*

### 2.4 Homepage/404/event cache keys (language-scoped)

| Key family | Where | Language suffix |
|---|---|---|
| `conexao_home_news`, `conexao_home_events`, `conexao_home_sponsors`, `conexao_home_jobs`, `conexao_home_featured`, `conexao_home_popular`, `conexao_home_latest` | `functions.php` lines 900–906 (`conexao_flush_language_cache(...)`); `conexao_lang_cache_key( 'conexao_home_popular' )` line 1215, `…_latest` line 1286, `…_sponsors` line 1464 | `_pt` / `_en` via `conexao_lang_cache_key()` |
| `conexao_404_guides`, `conexao_404_events` | `functions.php` lines 909–910 | `_pt` / `_en` |
| `conexao_event_upcoming_YYYYMMDD_{lang}` | event-runtime `Conexao_Event_Query`; `flush_cache()` clears **every** language variant | `_pt` / `_en` (Stage 2 §10, Stage 3.3 §17) |
| `conexao_b2_replaced_{types}` | object cache group `conexao_filters`, 300 s, flushed by `conexao_homepage_cache_invalidate()` | **language-independent by design** (Stage 3.2 §17, Stage 4.1 §11) |
| `pll_languages_list` | Polylang language-list cache — self-healing for the declared EN home URL | Stage 3.2 §17 |

Invalidation helpers: `conexao_flush_language_cache()` (`inc/polylang.php:455`) deletes the un-suffixed key plus
`$key . '_' . sanitize_key($slug)` for every language; `conexao_flush_language_object_cache()`
(`inc/polylang.php:484`) does the same for `wp_cache_*`.


---

## 3. ENGLISH STATIC PAGES (approved list)

Creator script: **`scripts/stage32-translate-pages.php`** (added Stage 3.2 §20; Stage 3.1 §11 created the first
three). Mechanism: for each entry of `stage32_page_translation_map()` (script line 55) it looks up the PT page
by slug (`get_page_by_path()`), skips when an EN translation already exists (idempotent), refuses to collide
(`get_page_by_path( $en['en_slug'] )` → *"slug '…' already taken by page #… — refusing to collide."*), creates
the EN page, links with Polylang, and **verifies the link from both sides** before reporting OK (script lines
132–199). CLI: `wp eval-file scripts/stage32-translate-pages.php` (script lines 22–23: *"Usage (local only,
NEVER production)"*).

Approved list — Stage 3.2 §4 table (exact):

| PT page (id) [LOCAL ONLY] | EN page (id) [LOCAL ONLY] | EN slug | EN URL | Notes (from the report) |
|---|---|---|---|---|
| `inicio` (4) | `home` (126) | `home` | `/en/` | front page (§3) |
| `sobre-nos` (6) | `about-us` (127) | `about-us` | `/en/about-us/` | Stage 3.1 pilot URL preserved |
| `contato` (7) | `contact` (128) | `contact` | `/en/contact/` | Stage 3.1 pilot URL preserved |
| `empregos` (15) | `jobs` (129) | `jobs` | `/en/jobs/` | **new**; same `page-empregos.php` template as PT (verified) |
| `politica-de-privacidade` (11) | `privacy-policy` (130) | `privacy-policy` | `/en/privacy-policy/` | **new**; Gutenberg structure preserved |
| `termos-de-uso` (12) | `terms-of-use` (131) | `terms-of-use` | `/en/terms-of-use/` | **new**; Gutenberg structure preserved |
| `cookies` (13) | `cookie-policy` (132) | `cookie-policy` | `/en/cookie-policy/` | **new**; Gutenberg structure preserved |

Script map confirms the slug pairs exactly (`stage32-translate-pages.php`): `'inicio' => 'home'`,
`'sobre-nos' => 'about-us'`, `'contato' => 'contact'`, `'empregos' => 'jobs'`,
`'politica-de-privacidade' => 'privacy-policy'`, `'termos-de-uso' => 'terms-of-use'`,
`'cookies' => 'cookie-policy'`.

**⚠ CONTRADICTION (flag) — EN page IDs differ between Stage 3.2 and Stage 3.3 §2.** Stage 3.2 §4 (above) gives
`privacy-policy` 130 / `terms-of-use` 131 / `cookie-policy` 132. Stage 3.3 §2 baseline lists the seven EN pages
as *"home #126 `/en/`, about-us #127, contact #128, jobs #129, terms-of-use #130, cookie-policy #131,
privacy-policy #141"*. The two stages were run against separately rebuilt local databases, so both are
`[LOCAL ONLY]`; the **slugs are the stable contract**. Stage 3.2 §27 step 4 states explicitly: *"re-run the
Stage 3.2 batch on production (production IDs differ)"*.

Selection rule (Stage 3.2 §4): *"key narrative pages (About, Contact, Privacy, Terms, Cookies, Jobs landing)
are *real translations*; county pages and `/irlanda/` stay B2 (§5); every other page (category hubs,
newsletter, revista, anuncie, search, europa, and the legacy `sobre`/`termos`/`privacidade` alias pages) keeps
B1. Nothing was translated automatically."*

Additional facts:

- EN slugs are **natural English slugs — no `-en` suffix on pages**; the differing slug is forced by
  WordPress' globally unique `post_name` (Stage 3.1 §11 "Slug note", Stage 3.2 §4).
- `conexao_meta_description` authored per page (Stage 3.2 §4).
- The B1 302 for an approved page **retires itself** the moment a published translation exists —
  *"No redirect-map entry was removed or changed for this"* (Stage 3.2 §4).
- Verified 200 (Stage 3.3 §19/§24): `/en/about-us/`, `/en/contact/`, `/en/jobs/`, `/en/privacy-policy/`,
  `/en/terms-of-use/`, `/en/cookie-policy/`, `/en/`.
- Local cleanup note (Stage 3.2 §4): *"The two WP installer default pages (`sample-page`, `privacy-policy`)
  were deleted locally so the EN `privacy-policy` slug could take the natural name (local housekeeping, not
  production content)."* **Check for the same slug collision in production before creating `/en/privacy-policy/`.**
- Stage 3.3 §7 inventory counts pages: `page` total 42 PT → **missing 35, current 7**, 0 outdated; the seven
  `current` are exactly the seven approved pages.


---

## 4. B2 MECHANISM (PT content under EN URL + notice) and B1 (302 → PT)

Decision basis: `ARCHITECTURE_DECISION §12 "Missing-translation policy"` — **B5 = per-content-type hybrid**:
*"**B1** (redirect to PT): Guides, Blog, key static pages … EN URL 302-redirects to PT page. Canonical stays on
PT. hreflang links the pair."* and *"**B2** (PT content under EN shell): Events (PT-source), Lazer, Sponsors,
Courses, Employment, county pages … Visible notice: \"This content is displayed in Portuguese.\" `rel=canonical`
→ PT record; hreflang pair."* (Note: the decision says "hreflang pair" for B2; **Stage 3.1 §14 / Stage 3.2 §15
changed B2 to `x-default` only** — see §4.4 and §14.)

### 4.1 B2 allowlist — exact, as implemented in code

All in `wp-content/themes/conexao-br-irlanda/inc/polylang.php`:

| Element | Function | Lines | Value |
|---|---|---|---|
| B2-eligible post types | `conexao_b2_post_types()` | **1166–1174** | `event`, `leisure`, `sponsor`, `course_provider`, `job` (Stage 3.1 §3 calls it the "allowlist") |
| Post-type predicate | `conexao_is_b2_post_type()` | 1176 | `in_array( $post_type, conexao_b2_post_types(), true )` |
| **B2 static-page allowlist** | `conexao_b2_page_allowlist()` | **1202–1219** | `dublin`, `cork`, `galway`, `limerick`, `kildare`, `meath`, `wicklow`, `waterford`, `laois`, `irlanda` — **10 PT slugs**; filterable via `apply_filters( 'conexao_b2_page_allowlist', $allowlist )` (line 1218) |
| Page predicate | `conexao_is_b2_page( $post_id )` | 1227 | matches `$post->post_name` against the allowlist (page post type only) |
| Single decision point | `conexao_should_render_b2_fallback( $post_id = null )` | **1250** | true only if: Polylang active; **requested** language `en`; resolved post is a B2 type **or** an allowlisted page; `post_status === 'publish'`; **no** real EN translation (`pll_get_post( $post_id, 'en' )` empty or self); record's own language ≠ `en`; **for events**: `_event_status` empty or `published` |
| Fallback state helper | `conexao_is_language_fallback()` | 1003 | *"narrowed to B2-eligible records only, so B1 types can never report a fallback state"* (Stage 3.1 §3) |
| Replacement rule (Stage 3.2) | `conexao_b2_translation_replaced_pt_ids( array $post_types )` | 1334 | PT masters hidden behind a published EN translation; cached 300 s in object-cache group `conexao_filters` under `conexao_b2_replaced_{md5(types)}` (line 1350) |

Stage 3.1 §3 records the page allowlist was *"**deliberately empty** in this stage (every page stays B1)"*;
Stage 3.2 §5 filled it with exactly the 10 slugs above; gate: *"`conexao_b2_page_allowlist()` = irlanda + 9
county pages only"* (Stage 3.2 §28 row 9).

Explicitly **NOT** in the allowlist (Stage 3.2 §5, exact list): `inicio`, `sobre-nos`, `contato`, `empregos`,
`politica-de-privacidade`, `termos-de-uso`, `cookies` (real translations); `moradia`, `saude`, `transporte`,
`familia`, `financas`, `beneficios`, `educacao`, `documentos`, `onde-comer`, `turismo`, `compras`, `negocios`,
`servicos`, `voluntariado`, `categorias`, `europa`, `newsletter`, `revista`, `anuncie`, `search` and the legacy
alias pages (B1 until translated). Rule: *"B2 is never a catch-all and never a substitute for editorial
translation; legal/sensitive pages are never put into B2 to \"make them render\"."*

### 4.2 B2 notice — exact text and markup

`conexao_b2_fallback_notice()` @ `inc/polylang.php:67` (renders nothing unless `conexao_is_language_fallback()`):

```php
<div class="language-fallback-notice" role="note" aria-live="polite" lang="en">
	<p><?php esc_html_e( 'This content is displayed in Portuguese.', 'conexao-br-irlanda' ); ?></p>
</div>
```

Rendered by `single.php`, `single-leisure.php`, `single-sponsor.php` (Stage 3.1 §3) **and** `page.php` (added
Stage 3.2 §5 after the gap *"`page.php` never rendered the B2 notice (only the CPT templates did)"*). Styles:
`.language-fallback-notice` in `assets/css/main.css` (existing amber tokens) + `assets/css/dark-mode.css`
overrides — *"no page-specific hacks, no new colour system"* (Stage 3.1 §3).


### 4.3 B2 rendering / redirect ownership (mechanism table)

| Mechanism | File / hook | Behaviour |
|---|---|---|
| Polylang language-mismatch 301 → 302 | `conexao_polylang_language_redirect_is_temporary()` @ `inc/polylang.php:1406`, filter `pll_check_canonical_url` **priority 20** (line 1452) | returns false (no canonical redirect) for B2 singles and B2 post-type archives under `/en/`; keeps the 302 for everything else (Stage 3.1 §3) |
| Theme missing-translation 302 (B1) | `conexao_seo_missing_translation_redirect()` @ `inc/seo.php:1415`, `template_redirect` **priority 6** (line 1451) | early-returns when `conexao_should_render_b2_fallback()` owns the request; otherwise `wp_safe_redirect( $target, 302 )` to the PT URL. Guarantees in code docblock: *"Never fires on a Portuguese request … Never fires on a real translation … Never fires on the front page … Never fires on archives, search, 404 or feeds. Targets Portuguese URLs only, so `/en/x → /x` can never loop back."* |
| Requested-language capture | `conexao_requested_language_slug()` @ `inc/polylang.php:396`, on `wp` and `template_redirect` **priority 0** (lines 417–418) | required because Polylang flips its own `curlang` while computing the canonical redirect (`PLL_Frontend_Canonical::check_canonical_url()` → `canonical.php:260`), so `pll_current_language()` cannot report the requested language (Stage 2 §8) |
| B2 shell locale | `conexao_polylang_b2_shell_locale()` @ `inc/polylang.php:368` on the `locale` filter **priority 99** (line 377) | EN shell locale for `language_attributes()` / `get_locale()`, scoped strictly to the B2 fallback state (Stage 3.1 §14) |
| Jetpack OG suppression on B2 | `conexao_seo_disable_jetpack_og_on_b2()` on `jetpack_enable_open_graph` (`inc/seo.php:1107`) | prevents a second `og:locale=pt_BR` beside the theme's `og:locale=en_US` on B2 pages (Stage 3.1 §14) |
| Archive/search widening | `conexao_b2_archive_widen_query()`, `conexao_b2_search_widen_query()` (`functions.php`, Stage 3.2 §13) + `conexao_b2_translation_replaced_pt_ids()` applied as `post__not_in`; for the sponsor archive the ids are removed from `post__in` directly (*"WP_Query ignores `post__not_in` whenever `post__in` is set"*) | B2 records appear in EN archives/search; a PT master with a published EN translation is replaced, never shown twice |
| Event archive membership | `Conexao_Event_Query::is_in_current_language()` (`conexao-event-runtime/includes/class-event-query.php`) | EN: PT events with no EN translation included; events with an EN translation only in their own language (Stage 3.1 §5) |
| Legacy redirect precedence | `conexao_seo_redirects()` @ `inc/seo.php:1174`, moved to `template_redirect` **priority 2** (line 1383) from priority 5 (Stage 3.2 §18) | must stay **before** Polylang's language canonical (priority 4), otherwise `/jobs/`, `/about-us/`, `/contact/` 302 to `/en/…` instead of issuing the production 301s |

### 4.4 B2 canonical / hreflang / indexability / sitemap

| Context | Canonical | hreflang | Indexable | In EN sitemap |
|---|---|---|---|---|
| B2 fallback (`/en/irlanda/`, `/en/dublin/`, `/en/empregos/oportunidades/`) | **the PT record's own URL** (`inc/polylang.php` filter `conexao_seo_canonical_url` via `conexao_polylang_canonical_url()` @ line 1708, filter line 1722) | **`x-default` only** (the PT URL) — *"no alternate to a URL that would redirect; the EN shell is not advertised as a translation"* (Stage 3.2 §15) | yes, "but not offered as an EN sitemap URL" (Stage 3.2 §14) | **absent** (Stage 3.2 §16 / §28 row 12; Stage 3.3 §16) |
| Real EN translation | self (EN permalink) | `en` + `pt-BR` + `x-default` (= PT) | yes | present once |
| Source-inherited EN record with no PT pair | self (EN) | **self-only `hreflang="en"`** — *"no invented alternate, no `x-default` pointing at a non-existent pair"* (Stage 3.2 §15) | yes | present |
| B1 untranslated (`/en/moradia/`, `/en/europa/`, `/en/blog/`) | redirect-only (302) | nothing emitted (never renders) | no | absent |

### 4.5 B1 behaviour — exact (301 vs 302)

**B1 is a 302 (temporary), never a 301.** Decision §12 implementation mechanics: *"**B1 (redirect):**
`template_redirect` hook at priority 6 issues a 302 to the PT URL when an EN URL has no translation for B1
content types. (302, not 301 — EN page may be created later.)"* Stage 2 §8: *"**Never 301 on a missing
translation.**"*

| B1 URL (verified local) | Result |
|---|---|
| `/en/guias/{pt-slug}/`, `/en/eventos/{pt-slug}/`, `/en/lazer/{pt-slug}/` | 302 → PT URL |
| `/en/blog/`, `/en/empregos/`, `/en/irlanda/` (pre-Stage-3.2) | 302 → PT URL |
| `/en/moradia/`, `/en/europa/`, `/en/anuncie/`, `/en/blog/` (post-3.2/3.3) | 302 → PT counterpart |
| `/en/towns/dublin/`, `/en/counties/dublin/` (terms) | 302 → PT term URL |
| `/en/guias/` (archive) | 200 — *B1 applies to records, not archives* |

Separately, the **legacy EN→PT map stays 301** (`.htaccess` layer 1 + `inc/seo.php` layer 2) and is
**byte-identical** in every stage: `/guides/`, `/events/`, `/courses/`, `/sponsors/`, `/jobs/`, `/ireland/`,
`/about-us/`, `/contact/`, `/about/`, `/privacidade/`, `/termos/`, `/sobre/`, `/guias-praticos/`,
`/counties/dublin/`, `/categories/moradia/`, `/empresas/` → correct PT URL, 301 (Stage 3.3 §18).


### 4.6 B2 in REST (Stage 4.1) — exact

| Rule | Value |
|---|---|
| B2 membership | `?lang=en` on a B2 type (`event`, `leisure`, `sponsor`, `course_provider`, `job`) returns `(language=en OR language=pt)` minus replaced PT masters → PT records appear flagged **`is_fallback: true`** |
| B2 payload | `conexao_language = { lang: "pt", is_fallback: true, translations: {} }` |
| B2 `link` field | *"B2 fallback → PT permalink (canonical of untranslated data, per `conexao_polylang_canonical_url`)"* (Stage 4.1 §4) — *"the existing `link` field already carries the language-correct canonical (no duplicate field was invented)"* |
| B1 in REST | `guide`/`post`: `lang=en` returns `language=en` only, **no fallback**; a B1 record requested under the wrong language answers **404 `conexao_rest_language_unavailable`** (Stage 4.1 §5, §25.3) |
| B2 and REST URLs | *"the REST surface must expose language-aware URLs and never emit a B2 shell URL as a canonical EN record"* (Stage 3.3 §26.1) |

---

## 5. STAGE 3.2 MIGRATION CONTRACT (scripts + CLI + identifiers)

All scripts below are **LOCAL/STAGING ONLY** (headers say *"LOCAL / STAGING ONLY"*). Production cannot run them
(WordPress.com has no CLI/SSH — `docs/deployment.md`), so Stage 3.2 §27 step 4 defines the production path as
**manual/editorial**.

### 5.1 Scripts, CLI invocations, contract

| Script | CLI (local) | What it does | Idempotency / guards |
|---|---|---|---|
| `scripts/stage32-seed-pilot.php` | `wp eval-file scripts/stage32-seed-pilot.php` | Rebuilds a production-shaped **Portuguese** pilot catalogue: `conexao_town` terms, extra `conexao_category` terms, 6 guides, 4 blog posts, 10 events with full identity meta (`_event_source`, `_event_source_id`, `_event_export_uuid`, dates, `_event_status`) covering published / legacy-no-status / expired / rejected / source_not_found + one English-source event, 8 leisure (`_leisure_export_uuid`; 5 internal + 3 external), 4 sponsors (`_sponsor_export_uuid`), 3 jobs. Course providers come from `scripts/seed-course-providers.php` | *"all Portuguese, all idempotent by slug / identity key"* (script docblock) |
| `scripts/stage32-translate-pages.php` | `wp eval-file scripts/stage32-translate-pages.php` | Creates the 7 approved EN page translations (§3) | Skips existing translations; **refuses slug collisions**; verifies the Polylang link from both sides |
| `scripts/stage32-translate-content.php` | `wp eval-file scripts/stage32-translate-content.php` | Controlled content-migration framework + pilot set. Per-type contract (Stage 3.2 §6): explicit PT slug list in `stage32_content_pilot_map()`; human-authored EN; linking via `pll_set_post_language()` + `pll_save_post_translations()`; PT record never modified; identity meta copied verbatim per `stage32_meta_copy_map()`; featured media **shared** via `set_post_thumbnail()`; taxonomy = linked EN term else shared PT term | *"**Nothing outside the explicit map is translated** — no bulk machine translation, no automatic selection."* Lookups use `get_posts( array( …, 'name' => $pt_slug, 'post_status' => 'any', 'lang' => '' ) )` — **by slug, never by local ID** |
| `scripts/stage32-translate-terms.php` | `wp eval-file scripts/stage32-translate-terms.php` | **One linked EN term translation per PT term actually used** by the pilot EN records; EN terms get natural English slugs; counties/towns never touched | *"Idempotent: a term that already has an EN translation is skipped."* Uses `pll_save_term_translations()`; PT slugs untouched; no `-en`/`-pt` suffix duplicates |
| `scripts/stage32-share-location-taxonomies.php` | `wp eval-file scripts/stage32-share-location-taxonomies.php` | Makes `conexao_county`/`conexao_town` truly shared: (1) reassign suffixed duplicates' objects to the default-language sibling via the Polylang translation link, then delete duplicate + link; (2) remove `term_language`/`term_translations` relationships from every remaining county/town term; (3) self-heal any **page** that lost its `language` relationship (16 pages restored to `pt`); (4) recalculate language counts + flush caches | Idempotent. Documents that **posts** use `language`/`post_translations` while **terms** use `term_language`/`term_translations`, because an early buggy version matched POST ids and damaged 16 pages (post ids 30–45 collide with county term ids) |
| `scripts/stage33-translation-inventory.php` | `wp eval-file scripts/stage33-translation-inventory.php` | **Read-only** editorial state inventory via `Conexao_Admin_UX_Translation_State::get_state()`; states `missing` / `current` / `outdated` / `not_applicable`, grouped by type | *"It never creates, edits or links anything"* |

Support/verification scripts: `scripts/stage2-polylang-setup.php`; `scripts/stage2-http-verify.sh`
(`./scripts/stage2-http-verify.sh [base-url]`, default `http://localhost:8080`); `scripts/stage2-rest-probe.php`;
`scripts/stage33-http-verify.py` (`python3 scripts/stage33-http-verify.py [base-url]`, default
`http://localhost:8080`); `scripts/stage41-rest-verify.py`
(`python3 scripts/stage41-rest-verify.py <base-url> [host] [hidden_event_id]`, defaults
`http://localhost:8080` / hidden event id `77`).

Docker form (`docs/development.md` §"Multilingual (EN) development — Stage 2" / §"Running Scripts"):
`docker compose exec wordpress wp eval-file scripts/<name>.php`; the setup script also documented piped:
`docker compose exec -T wordpress wp eval-file - --allow-root < scripts/stage2-polylang-setup.php`
(dry run: append `dry-run`).


### 5.2 Identifiers, dry-run flags, manifest

| Question | Answer |
|---|---|
| Are local IDs used as portable identifiers? | **No.** Content is matched **by PT slug**; identity travels in `_event_source` + `_event_source_id`, `_event_export_uuid`, `_leisure_export_uuid`, `_sponsor_export_uuid`; linking uses Polylang's translation APIs. Stage 3.2 §27 step 4: *"Linked EN records must be created as Polylang translations of the PT records — never as independent records — and identity meta (event source/source_id/UUID; Lazer/sponsor UUIDs) copied verbatim, exactly as the local framework does."* (cf. AGENTS.md rule 2 and `ARCHITECTURE_DECISION §1`.) |
| Dry-run flag | **Only** `scripts/stage2-polylang-setup.php`: `$dry_run = (bool) array_intersect( array( 'dry-run', '--dry-run' ), (array) $args );` (line 46). In dry-run it prints `DRY RUN (no changes)`, reports languages that would be created, prints `key = current → expected` lines without writing, and skips assignment (*"DRY RUN: assignment skipped"*). The Stage 3.2 content/term/page scripts have **no dry-run flag**. |
| Manifest format | **None in Stage 3.2/3.3** — the "manifest" is the in-script PHP map (`stage32_content_pilot_map()`, `stage32_meta_copy_map()`, `stage32_page_translation_map()`). The only JSON manifest is the event export in `conexao-event-importer` (fields `uuid`, `post`, `meta`, `taxonomies`, `featured_image`, `manifest`, + additive `lang`) — Stage 3.2 §19. |
| Order of operations (production) | Stage 3.2 §27 step 4: *"Order matters: **terms first, then content** (an untranslated term assigned to an EN record makes Polylang auto-create a same-name copy — the behaviour Stage 3.2 removed for proper nouns and must avoid for categories)."* Per batch: (1) EN term translations; (2) approved page translations; (3) content per type. |
| Script defect to fix before re-running the taxonomy cleanup | `scripts/stage32-share-location-taxonomies.php` ends with `clean_term_cache( array(), array( …taxonomies… ), true )`, a **WordPress 7.1 strictness fatal**; Stage 3.3 §25.9 gives the one-line fix: `foreach ( $taxonomies as $t ) { clean_term_cache( array(), $t, true ); }` — *"The taxonomy work itself completed before that call."* |

### 5.3 Pilot records created (Stage 3.2 §6 table — IDs `[LOCAL ONLY]`)

| Type | PT master | EN translation | Notes |
|---|---|---|---|
| Guide | `como-tirar-o-pps-number` (80) | (134) `/en/guias/how-to-get-a-pps-number/` | featured image shared (attachment 133) |
| Guide | `como-abrir-conta-bancaria-na-irlanda` (81) | (135) `/en/guias/how-to-open-a-bank-account-in-ireland/` | — |
| Blog post | `comunidade-celebra-festa-junina-em-dublin` (86) | (136) `/en/community-celebrates-festa-junina-in-dublin/` | native `category` EN term |
| Event | `festa-junina-dublin-2026` (90) | (137) `/en/eventos/festa-junina-dublin-2026-en/` | full identity meta shared; banner shared |
| Event (source-inherited) | — | (**97** itself) `/en/eventos/irish-dance-workshop-dublin/` | English-source record moved `pt→en` **in place** — no second record |
| Lazer | `phoenix-park` (100) | (138) `/en/lazer/phoenix-park-en/` | classification + UUID preserved |
| Sponsor | `brasil-market-dublin` (108) | (139) `/en/apoiadores/brasil-market-dublin-en/` | `_sponsor_export_uuid` preserved |
| Course provider | `fetch-courses` (115) | (140) `/en/cursos/fetch-courses-en/` | `_provider_category` label translated on the EN record |
| Job | `ajudante-de-cozinha-dublin` (113) | (141) `/en/empregos/kitchen-assistant-dublin/` | — |

Fixture: attachment 133 `stage32-pilot-image` (GD-generated) attached to the PT guide + PT event; both EN
translations carry the same attachment id. Stage 3.2 §6 hard gates (event): EN translation gets **no new**
`_event_source`/`_event_source_id`, **no conflicting** `_event_export_uuid`, is **not** an import target
(`is_import_target()` false; guarded upsert returns `skipped`), does not alter scheduling/lifecycle meta, does
not bypass `_event_status`, no third record. Lazer: `_leisure_export_uuid` identical; `_leisure_internal_page`
preserved; `conexao_leisure_external_url()` unchanged (Fota verified).

### 5.4 Event source-language metadata (Stage 3.2 §7)

| State | Representation | Export value |
|---|---|---|
| Source content already English | `_event_source_language = en` | `"lang": "en"` |
| Source content Portuguese | `_event_source_language = pt` | `"lang": "pt"` |
| Source content another language | `_event_source_language = other` | `"lang": "other"` |
| Unknown / unclassified | **meta absent** | `"lang": "unknown"` |

Signals consumed: `$raw['source_language']` (validated) and the Eventbrite normalizer's declared `locale`
(`pt_BR` → pt, `en_IE` → en, anything else → other, absent → unclassified). *"No title/body heuristics exist."*
`unknown` is *"an export-only normalization of absence and is never written to the database."* Owner:
`Conexao_Event_Source_Language` in `conexao-event-runtime` (`includes/class-source-language.php`). The meta is
**never identity** (dedup, UUIDs, scheduling and the `_event_status` gate do not read it). Export: new
additive per-event top-level field `"lang"` in `export_event()`, `_event_source_language` added to the exporter
meta key list and to the transfer importer's meta allowlist; export query constrained to the **import
language** so EN translations are never extra identity rows (57 assertions, `tests/test-export-language.php`).
Production backfill (Stage 3.2 §27 step 6): *"**Backfill** for pre-existing events (meta absent → `unknown` in
exports) is a deliberate, reviewed manual/CLI step for known-English sources. Do not infer language from titles."*


---

## 6. TAXONOMY POLICY

### 6.1 Translated vs shared (final)

| Taxonomy | Model | Slug behaviour | Filter behaviour |
|---|---|---|---|
| `conexao_category` | **TRANSLATED** — linked Polylang term translations, one shared concept identity per pair | PT slugs untouched; EN terms get genuine English slugs (WordPress uniqueness forces a distinct slug) | PT slug → PT records (incl. B2 fallback) in both language contexts; EN slug → EN records |
| `conexao_tag` | TRANSLATED | same | same |
| `category` (native blog) | TRANSLATED | EN term e.g. `community` | `category` archive |
| `post_tag` | TRANSLATED | — | — |
| `conexao_county` | **SHARED** — one physical term, **no language, no translations** | one shared slug (`dublin`) | `?county=dublin` matches PT **and** EN records in both languages |
| `conexao_town` | **SHARED** — same rule | one shared slug | `?cidade=dublin` matches both languages identically |

Architecture invariant (decision §1, quoted in Stage 3.2 §1): *"Counties/towns are shared — no per-language
duplicate terms."* Stage 3.2 §10: county and town *"are **no longer Polylang-translated**
(`conexao_polylang_translated_taxonomies()` keeps `conexao_category` + `conexao_tag`). One physical shared term
per county/town; no language, no translations, no auto-creation path."*

### 6.2 The `dublin-en` problem and its fix (root cause, verbatim)

Stage 3.2 §10: *"With county/town registered as Polylang-translated taxonomies (the Stage 2 interpretation),
Polylang Free ≥ 3.5 auto-created a term translation the first time a county/town term was assigned to an EN
record (`PLL_Crud_Posts::set_object_terms()` → `translate_term()` → `wp_insert_term`): `dublin-en` in
`conexao_county` (213) and in `conexao_town` (216), name copied verbatim, linked as translations of the PT
Dublin terms."* Effects observed: *"suffixed duplicate terms, duplicate filter-dropdown entries (`cidade=dublin`
**and** `cidade=dublin-en`), and split filter matching."*

Fix (two parts): (1) theme policy — `inc/polylang.php` `conexao_polylang_translated_taxonomies()` no longer adds
county/town; (2) data migration — `scripts/stage32-share-location-taxonomies.php` (§5.1), which deletes the
suffixed duplicates/links and strips `term_language`/`term_translations` from all remaining county/town terms.
Collateral-damage note reproduced in §5.1 (16 pages lost their `language` relationship to an early buggy version;
self-healed). Final state (Stage 3.3 §6): *"every `conexao_county` (26) and `conexao_town` (8) term is
language-neutral, zero suffixed duplicates"* — Stage 2/3.1 baselines were 32 counties / 250 towns (production
shape) vs 26/8 in the Stage 3.2/3.3 pilot catalogue.

### 6.3 Representative EN ↔ PT term pairs created by `stage32-translate-terms.php` (Stage 3.2 §10)

| PT term (id) [LOCAL ONLY] | EN term (id) [LOCAL ONLY] | Taxonomy |
|---|---|---|
| `documentos` (11) | `documents` (173) | `conexao_category` |
| `trabalho` (83) | `work` (176) | `conexao_category` |
| `financas` (7) | `finances` (179) | `conexao_category` |
| `festivais` (80) | `festivals` (182) | `conexao_category` |
| `musica` (79) | `music` (185) | `conexao_category` |
| `cultura` (17) | `culture` (188) | `conexao_category` |
| `natureza` (15) | `nature` (191) | `conexao_category` |
| `cidades` (26) | `cities` (194) | `conexao_category` |
| `negocios` (13) | `businesses` (197) | `conexao_category` |
| `gastronomia` (81) | `gastronomy` (200) | `conexao_category` |
| `empregos` (3) | `jobs` (203) | `conexao_category` |
| `comunidade` (85) | `community` (206) | `category` (native blog) |

Stage 3.3 §6 re-verified all **12/12** links in both directions and that EN archives display the EN names
(*"Documents, Work, Festivals, Music, Cities, Nature, Businesses, Gastronomy, Jobs, Community"*), that
`/en/guias/?categoria=documents` (2 guides) and `?categoria=finances` (1 guide) are real populated
destinations, and that PT filters are unchanged (`/guias/?categoria=moradia`, `?categoria=documentos`).
Unused terms were deliberately **not** translated (e.g. `moradia`, `saude`, `transporte`, `beneficios`,
`educacao` have no EN term because no EN record uses them).

### 6.4 Filter contract (empirically verified — Stage 3.2 §10 table, verbatim)

| Filter | Result |
|---|---|
| `/en/eventos/?cidade=dublin` (shared town) | EN records **and** PT B2 records; one Dublin entry in the dropdown |
| `/eventos/?cidade=dublin` (PT) | PT records, unchanged |
| `/en/lazer/?county=dublin` (shared county) | EN record **and** PT B2 records |
| `/en/eventos/?categoria=festivais` (PT slug) | PT B2 records (brazilian-day-galway) |
| `/en/eventos/?categoria=festivals` (EN slug) | EN records (festa-junina-dublin-2026-en) |
| `/en/lazer/?categoria=natureza` (PT slug) | PT B2 Lazer records |
| `/en/lazer/?categoria=nature` (EN slug) | the EN Lazer record |
| `/en/guias/?categoria=documents` (EN slug) | EN guide records |
| `/eventos/?categoria=festivais` (PT) | PT records incl. the PT master, unchanged |

Summary sentence (Stage 3.2 §10): *"**PT-slug filters match PT records (B2 fallback included) in both language
contexts; EN-slug filters match EN records.** B2 records are never claimed to match English slugs; county/town
filters use the one shared slug in both languages."* Stage 2 §12 nuance (pre-Stage-3.2): `/lazer/?county=dublin`
resolved a term query var and the EN variant 302'd to the PT filtered URL until Stage 3.1/3.2 — superseded.


---

## 7. MENUS

### 7.1 Registered locations (theme)

`register_nav_menus()` in `functions.php:544–548`:

| Location | Label (PT, gettext) |
|---|---|
| `primary` | `Menu Principal` |
| `footer` | `Menu Rodapé` |
| `social` | `Menu Social` |

There is **no separate `mobile` location** — `header.php` renders `theme_location => 'primary'` twice
(desktop nav line 103–104, mobile drawer nav line 213–215).

### 7.2 Assignment scripts (repo tooling)

| Script | CLI | What it writes | Guardrails |
|---|---|---|---|
| `scripts/assign-polylang-nav-menus.php` | `docker compose exec -T wordpress wp eval-file /var/www/html/scripts/assign-polylang-nav-menus.php --allow-root` **or** `php scripts/assign-polylang-nav-menus.php` | `nav_menus[get_option('stylesheet')]['primary']['pt'] = <menu id>` in the `polylang` option; re-asserts `nav_menu_locations.primary` theme mod (belt-and-braces for admin screens) | Requires the existing `Menu Principal` menu (exits 1 with *"ERROR: menu 'Menu Principal' does not exist. Create/seed it first (scripts/flush-navigation-rules.php) and retry."*); never creates items; **footer/social menus are never modified (out of scope)** |
| `scripts/create-en-primary-menu.php` | `php /path/to/create-en-primary-menu.php` (inside the WP container) | Creates/finds **`Main Menu`** (EN) and writes `nav_menus[stylesheet]['primary']['en'] = <menu id>` | *"Never creates, edits or deletes a Portuguese (\"Menu Principal\") item. Never touches the footer/social menus or the 'footer' location. Never touches the plain `nav_menu_locations` theme mod"*; idempotent (populates items only when the menu is empty) |

Why the Polylang option is mandatory (`assign-polylang-nav-menus.php` header): Polylang's
`theme_mod_nav_menu_locations` filter (priority 20) *"unconditionally overwrites every registered theme
location with the per-language assignment stored in Polylang's `nav_menus` option
(`PLL()->options['nav_menus'][stylesheet][location][language-slug]`), or with 0 when that per-language entry
does not exist."*

### 7.3 Exact EN menu content created by `create-en-primary-menu.php`

Stored order and titles (custom links keep the **canonical PT paths**; URLs resolve at render time):

| # | Title | Type | Stored path / page |
|---|---|---|---|
| 1 | `Home` | custom | `/` |
| 2 | `Sponsors` | custom | `/apoiadores/` |
| 3 | `Guides` | custom | `/guias/` |
| 4 | `Events` | custom | `/eventos/` |
| 5 | `Courses` | custom | `/cursos/` |
| 6 | `Leisure & Tourism` | custom | `/lazer/` |
| 7 | `Jobs` | custom | `/empregos/` (resolves to the linked `/en/jobs/`) |
| 8 | `Blog` | custom | `/blog/` (**approved B1 exception — stays PT**) |
| 9 | `About Us` | page object | `sobre-nos` |
| 10 | `Contact` | page object | `contato` |

### 7.4 Local limitations recorded by the reports

**Stage 3.3 §25.3 (verbatim):** *"**No English menu is assigned to the `primary` location** in this dataset
(`PLL()->options['nav_menus']` empty; `nav_menu_locations.primary = 69`). The EN header therefore shows the
Polylang-filtered page-list fallback (the 7 EN pages) instead of the directory entries the PT menu carries. No
Portuguese URL leaks, but the EN header is *incomplete*. Assigning menus is production data/editorial work
(§19 item 13)."* Stage 3.3 §4 verdict: *"language-consistent, **incomplete** (no archive entries) — data gap,
§25"*. Stage 3.3 §26.3 (Stage 4 prerequisite): *"**Production menu work**: create and assign per-language
menus (`primary`, `footer`) so the EN header exposes the directories (§25.3)."* Stage 3.3 §19 item 13:
*"assign a per-language EN menu to the `primary` location so the header exposes the directory archives; verify
the footer menu translation."*

### 7.5 What the reports say is already correct without menu work

Stage 3.3 §4: desktop header **logo** → `/en/` (Polylang rewrites the bare home URL); mobile/header **search
form** action = current-language home (`/en/`); header CTA `Anuncie Aqui` → `/anuncie/` (B1, unchanged);
footer brand logo/about → `/en/`; **footer menu (Rodapé)** items are `post_type` items and Polylang-translated →
`/en/about-us/`, `/en/contact/`, `/en/privacy-policy/`, `/en/terms-of-use/`, `/en/cookie-policy/`, with
`Anuncie`/`Newsletter` staying PT; footer bottom legal links were **fixed** in Stage 3.3 to the linked EN pages
via `conexao_lang_url()` (`footer.php`); language switcher desktop+mobile verified.

### 7.6 ⚠ CONTRADICTION (flag): Stage 3.3 §25.3 vs `docs/routing.md` + the three nav fix reports

Stage 3.3 §25.3 (above) says no EN menu is assigned locally and the EN header falls back to the page list.
**`docs/routing.md` §"English rollout state (Stage 3.3)"** (current repo state) says the opposite:
*"**EN primary navigation**: an English menu (\"Main Menu\", term 2933 locally) is assigned to the `primary`
location through Polylang's per-language `nav_menus` option, exactly like the Portuguese \"Menu Principal\"
menu."* — and `scripts/create-en-primary-menu.php` + `CONEXAO_BR_EN_HEADER_NAVIGATION_FIX_REPORT.md` /
`CONEXAO_BR_EN_NAV_LANGUAGE_CONTEXT_FIX_REPORT.md` exist in the repo. Read this as **Stage 3.3 report text
frozen at the time of writing vs a later fix landed in the same work-line**; the *production* action is
unchanged either way: create/assign the per-language `primary` menu (and verify `footer`) in wp-admin, because
the fix is **data**, never code. `docs/routing.md` also records the follow-up Jobs/Blog resolution:
*"`conexao_primary_nav_sections()` therefore models the Jobs section as a **page** (`path = empregos`), not as
a CPT archive"* (fixed in `CONEXAO_BR_EN_NAV_LANGUAGE_CONTEXT_FIX_REPORT.md`) and *"**Approved B1 exception in
the EN nav: `/blog/` only.**"*


---

## 8. SEO / HREFLANG

### 8.1 Ownership and implementation

| Surface | Owner | Code |
|---|---|---|
| Canonical | `inc/seo.php` | `conexao_seo_canonical()` @ `inc/seo.php:186`; applies filter `conexao_seo_canonical_url` (line 218) — Polylang bridge `conexao_polylang_canonical_url()` @ `inc/polylang.php:1708`, registered line 1722 |
| hreflang | `inc/seo.php` (emitter) + `inc/polylang.php` (data) | `conexao_seo_hreflang()` @ `inc/seo.php:242`, `add_action( 'wp_head', 'conexao_seo_hreflang', 5 )` (line 252); data from `conexao_hreflang_links()` @ `inc/polylang.php:1504` (helpers `conexao_hreflang_code()` :1093, `conexao_hreflang_with_default()` :1627) |
| `og:locale` | `inc/seo.php` | dynamic via Stage 1 `conexao_current_locale()` (pre-Stage-1 it was hardcoded `pt_BR` at `inc/seo.php:261` — audit finding) |
| Robots / noindex | `inc/seo.php` | search + 404 `noindex` in both languages (Stage 3.3 §14) |
| Sitemap | `inc/seo.php` | `conexao_seo_sitemap()` @ `:897`, `add_action( 'template_redirect', 'conexao_seo_sitemap', 0 )` (:1080); core sitemaps disabled via `add_filter( 'wp_sitemaps_enabled', 'conexao_seo_disable_core_sitemap' )` (:895); helper `conexao_seo_sitemap_url( $url, $priority, $freq, array $alternates = array() )` (:1119) |
| Legacy redirects | `inc/seo.php` | `conexao_seo_redirects()` @ `:1174`, `template_redirect` **priority 2** (:1383) |
| Missing-translation 302 (B1) | `inc/seo.php` | `conexao_seo_missing_translation_redirect()` @ `:1415`, priority **6** (:1451) |

Single-owner rule (`ARCHITECTURE_DECISION §14`): *"**The theme's `inc/seo.php` is the sole owner of SEO
output.** Polylang is configured to defer canonical/hreflang/sitemap output."* Reason (§14): *"`inc/seo.php`
knows about `conexao_leisure_external_url()` (external redirect classification), `_event_status` …, and the B5
per-type model."*

### 8.2 Exact hreflang output by case (Stage 2 §14, Stage 3.2 §15, Stage 3.3 §15)

| Case | Output |
|---|---|
| `/` (PT home) — Stage 2 state | `hreflang="pt-BR"` → `/`, `hreflang="x-default"` → `/` — **no** `en` link |
| `/` (PT home) — Stage 3.2/3.3 (EN home exists) | `pt-BR` → `/`, `en` → `/en/`, `x-default` → `/` |
| `/en/` | `en` → `/en/`, `pt-BR` → `/`, `x-default` → `/` |
| `/en/eventos/` (real archive) | `en` → `/en/eventos/`, `pt-BR` → `/eventos/`, `x-default` → `/eventos/` |
| Real EN translation (`/en/about-us/`, `/en/guias/how-to-get-a-pps-number/`, `/en/eventos/festa-junina-dublin-2026-en/`, `/en/lazer/phoenix-park-en/`, `/en/apoiadores/brasil-market-dublin-en/`, `/en/cursos/fetch-courses-en/`, `/en/empregos/kitchen-assistant-dublin/`, `/en/community-celebrates-festa-junina-in-dublin/`) | `en` + `pt-BR` + `x-default` (= PT) |
| PT single with no translation | `x-default` → its own URL only (Stage 2 §14) |
| **B2 fallback** (`/en/irlanda/`, `/en/dublin/`) | **`x-default` only** (the PT URL) — *"no alternate to a URL that would redirect; the EN shell is not advertised as a translation"* |
| Source-inherited EN event with no PT pair (`/en/eventos/irish-dance-workshop-dublin/`) | **self-only `hreflang="en"`**, self-canonical; no `x-default` |
| Empty EN archive (language with no content) | the *current* archive only (self) — *"an empty shell is never advertised as an alternate"* (Stage 2 §14) |
| `/blog/`, terms, search, 404 | posts page/term follow the object's translation links; search/404 emit nothing |
| B1 redirect-only URLs (`/en/blog/`, `/en/moradia/`) | nothing — they never render |

Invariants (Stage 3.2 §15): *"No duplicate hreflang tags; no alternate pointing at a redirecting URL;
`x-default` always the default-language URL."* (Stage 2 §14: *"No alternate pointing at a URL that would
redirect."*)

### 8.3 Canonical rules — exact

| Context | Canonical |
|---|---|
| `/`, PT archives, PT singles/pages | self |
| `/en/`, real EN pages/singles | self (EN) |
| B2 fallbacks | **the PT record's own URL** (`conexao_seo_canonical_url` filter via `conexao_polylang_canonical_url()`) |
| B1 untranslated | redirect-only → *"no canonical"* (never renders) |
| Search / 404 | no hreflang; both `noindex` |
| Stage 2 note | *"A new filter `conexao_seo_canonical_url` is applied inside `conexao_seo_canonical()`, and `inc/polylang.php` uses it for **one** rule: if the request renders a record of another language (the B2 fallback state), canonical → the record's own (PT) URL."* Pagination normalisation, WP core `rel_canonical` removal and the noindex rules are untouched (Stage 2 §13). |


### 8.4 ⚠ KNOWN DUPLICATION — two hreflang emitters

Stage 3.3 §25.4 (verbatim): *"**Duplicate hreflang emitters.** The theme owns hreflang (correct set:
`en`+`pt-BR`+`x-default`), and Polylang's own `wp_head` rel-alternate block is also emitted. Pre-existing since
Stage 2; not a contradiction (same URLs) but redundant. Consolidation is deliberately deferred because \"do not
modify SEO architecture\" governs this stage."* Carried forward: Stage 3.3 §19 item 12 (*"decide on
consolidating the duplicated Polylang rel-alternate set"*), Stage 3.3 §26.4 (*"**hreflang/SEO consolidation
decision**: keep or suppress Polylang's own rel-alternate block so exactly one hreflang set is emitted"*),
Stage 4.1 §26.6 (*"hreflang consolidation … unchanged carry-overs"*). **Deployer action: decide (a) leave as-is
or (b) suppress Polylang's block; do not change the ownership/structure of `inc/seo.php` (see §12).**

### 8.5 ⚠ THE 3 EN RECORDS WITHOUT META DESCRIPTIONS (exact identification)

Stage 3.3 §25.5 (verbatim): *"**Three EN records lack a meta description** (source-inherited event #108,
sponsor #138, course provider #139) because their Portuguese sources have none. Content task, not code."*
Stage 3.3 §8 item 1 (verbatim cause): *"**Missing meta description on 3 EN records** (#108 source-inherited
event, #138 sponsor, #139 course provider). Cause verified at the data level: the Portuguese sources (`#119`,
`#80`) have an empty `conexao_meta_description` too, and the imported event has none. This is a content gap
inherited from the PT dataset, not a Stage 3.3 defect; fixing it would mean authoring new PT+EN metadata, which
this stage must not do. … Each page still emits a title + fallback description, so no page is broken."*

| # | Record (Stage 3.3 id) | Type | EN slug / URL | PT source | Why |
|---|---|---|---|---|---|
| 1 | **#108** | `event` (source-inherited EN, Eventbrite) | `/en/eventos/irish-dance-workshop-dublin/` | none — *this* is the English record | imported event has no meta description |
| 2 | **#138** | `sponsor` | `/en/apoiadores/brasil-market-dublin-en/` | `brasil-market-dublin` (**#119**) | PT source `conexao_meta_description` empty |
| 3 | **#139** | `course_provider` | `/en/cursos/fetch-courses-en/` | `fetch-courses` (**#80**) | PT source `conexao_meta_description` empty |

**⚠ Cross-stage ID discrepancy (flag):** Stage 3.2 §6 numbers the same three records #**97** (source-inherited
event), #**139** (sponsor), #**140** (course provider), because Stage 3.2 and Stage 3.3 ran on separately
rebuilt local databases. **Identify them in production by type + slug, never by ID.** Production action
(Stage 3.3 §19 item 14): *"author `conexao_meta_description` for the records that still lack it (PT + EN)"*.


---

## 9. SITEMAP

### 9.1 Implementation

- **Theme producer:** `conexao_seo_sitemap()` @ `inc/seo.php:897`, served at `/sitemap.xml` **and**
  `/sitemap_index.xml` (`if ( '/sitemap.xml' !== $path && '/sitemap_index.xml' !== $path ) { return; }`),
  `template_redirect` priority 0 (:1080); core sitemaps disabled via
  `add_filter( 'wp_sitemaps_enabled', 'conexao_seo_disable_core_sitemap' )` (:895).
- Output root: `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">`;
  per-entry alternates via `conexao_seo_sitemap_url( $url, $priority, $freq, array $alternates = array() )` (:1119).
- Stage 2 hardening: URL sets pinned to the **default language** so an EN translation can never appear as a
  duplicate `<url>`; `<xhtml:link rel="alternate">` only for real translation sets (post/page/CPT and term);
  homepage entry declares the real `/` ↔ `/en/` pair; exclusion rules (`conexao_leisure_external_url`, utility
  pages) unchanged.
- Stage 3.1 §15 adds the **EN pass** (real EN records get their own indexable EN `<url>`); **excludes** B2
  fallback URLs and B1 redirect-only URLs. With Jetpack deactivated locally: *"1711 `<url>` entries, **0** B2
  fallback URLs, 1 `/en/` loc (the home alternate)"*.
- Stage 3.2 §16 / Stage 3.3 §16 (theme producer, Jetpack absent): **107 `<loc>`, 0 duplicates, 14 EN URLs** —
  *"the real EN content only"* (7 EN pages, 2 EN guides, 1 EN blog post, 1 EN event, 1 source-inherited event,
  1 EN leisure, 1 EN sponsor, 1 EN course, 1 EN job); **0 B2 fallback URLs**; B1-only EN URLs (`/en/blog/`)
  absent; PT URLs unchanged and present once; externally classified Lazer records excluded.
- Fixed defect (Stage 3.2 §16): the PT front-page *page* was emitted a second time as `<loc>/`; *"the pages loop
  skips `page_on_front`, so `/` appears exactly once. `/inicio/` was never emitted (redirect target)."*

### 9.2 Jetpack conflict — what each stage found locally

| Finding | Source |
|---|---|
| Locally Jetpack 16.2-a.3 **owns `/sitemap.xml`** (generator header `jetpack-16.2-a.3`), so the theme sitemap is **shadowed** — *"pre-existing, not Polylang-caused"* | Stage 2 §16 |
| Jetpack output after enabling Polylang: **85 `<loc>` entries, 0 `/en/` URLs, 0 hreflang/`xhtml:link` entries**, stale local domain `conexaobrirlanda.com` | Stage 2 §16 |
| Production: Jetpack `16.3-a.1` observed, owns `/sitemap.xml`, `news-sitemap.xml`, `image-sitemap-index-1.xml`, CDN `i0.wp.com`/`c0.wp.com` | Audit §1.1 |
| *"Production sitemap ownership is **not** claimed as solved."* | Stage 2 §16 |
| Stage 3.1/3.2/3.3 measured the theme producer with Jetpack **deactivated / not installed**; production reconciliation is plan-only | Stage 3.1 §15, Stage 3.2 §26.3, Stage 3.3 §16 / §19.10 |

### 9.3 Production reconciliation — required (decision §15 + Stage 3.2 §27 step 11)

Decision §15: *"**Single owner: the theme custom sitemap in `inc/seo.php`.** Jetpack's sitemap on production must
be reconciled to avoid dual-producer drift."* Stage 3.2 §27 step 11 (verbatim): *"Jetpack owns production
`/sitemap.xml`. Choose ONE producer: keep Jetpack and enable the multilingual alternates it supports, or disable
Jetpack's sitemap module and let the theme producer (already EN-aware) serve it. Afterwards verify: real EN URLs
present, B2 fallback URLs absent, no duplicate `<loc>`, alternates only for real pairs, PT set unchanged."*
Stage 3.3 §19 item 10: *"Jetpack's sitemap must not re-add B2 URLs or duplicates; compare against the theme's
14 EN URLs"*. Precondition (Stage 3.2 §27): *"Full WordPress.com backup + save the current `/sitemap.xml` and
`/news-sitemap.xml` for later comparison."*


---

## 10. REST CONTRACT (Stage 4.1 / 4.2) — **FROZEN**

Frozen statement (Stage 4.1 §12 preamble, line 329): *"WordPress contract this guidance builds on is frozen and
verified in §4–§11."* Stage 4.2 status line: *"The Flutter client now consumes the frozen Stage 4.1 contract end
to end"* and §28: *"consuming the frozen Stage 4.1 contract exactly."* **Stage 4.2 §19 states: *"The WordPress
module was **not modified**."***

Implementing file: **`wp-content/themes/conexao-br-irlanda/inc/rest-language.php`** (729 lines, new in Stage 4.1;
loaded from `functions.php` immediately after `inc/polylang.php`; every hook guarded by
`conexao_polylang_active()`, so with Polylang inactive the REST API behaves exactly as before).

### 10.1 Namespace, route base, inventory

| Item | Value |
|---|---|
| Namespace / route base | **`wp-json/wp/v2/…`** — *"the existing endpoints — no new routes; the route inventory stays at 198"* (Stage 4.1 §4) |
| Supported post types | `event`, `leisure`, `guide`, `post` (route base **`posts`**), `job`, `course_provider`, `sponsor` — `conexao_rest_language_post_types()` @ `inc/rest-language.php:66`; base mapping `conexao_rest_language_post_type_rest_base()` :76 |
| Supported taxonomies | translated `conexao_category`, `conexao_tag`, `category` (base `categories`), `post_tag` (base `tags`) — `conexao_rest_language_translated_taxonomies()` :85; shared `conexao_county`, `conexao_town` — `conexao_rest_language_shared_taxonomies()` :94; base map :104 |
| Search | `GET /wp-json/wp/v2/search` plus the `search=` param on typed collections |
| Route gate | `conexao_rest_language_route_supported( $route )` :160 — regexes `#^/wp/v2/search(?:/|$)#` and `#^/wp/v2/{base}(?:/\d+)?$#`; *"Everything else (media, pages, Polylang's own routes, core meta routes) is intentionally out of scope for Stage 4.1."* |
| Accepted languages | discovered from Polylang at runtime (`conexao_rest_supported_languages()` :139 → `pll_languages_list( array( 'fields' => 'slug' ) )`), *"never hard-coded"* — currently `pt`, `en` |

### 10.2 `lang` parameter semantics (exact)

| Request | Behaviour |
|---|---|
| `GET /wp-json/wp/v2/{type}` (no `lang`) | **unchanged pre-Stage-4.1 behaviour** (unfiltered collection, any-language detail) |
| `GET /wp-json/wp/v2/{type}?lang=pt` | the Portuguese collection / detail |
| `GET /wp-json/wp/v2/{type}?lang=en` | the English collection / detail per the B1/B2 policy |
| `lang` = anything else | **HTTP 400** `conexao_rest_invalid_lang`, body `{ status: 400, lang: <given>, supported: ["pt","en"] }` — *"never a silent mixed/default-language response (this deliberately overrides Polylang Free's silent default-language fallback)"* |

Implementation surfaces (Stage 4.1 §4): `rest_{$post_type}_query` (collection filtering, tax_query on Polylang's
`language` taxonomy); `rest_request_before_callbacks` priority 5 (`conexao_rest_language_validate_request()` :198,
error code at :211) and priority 10 (`conexao_rest_language_detail_gate()` :607); `register_rest_field(
'conexao_language' )` on the 7 post types, the 6 taxonomies and `search-result`
(`conexao_rest_language_register_fields()` :473); `rest_post_search_query`
(`conexao_rest_language_filter_search_query()` :347); `rest_{$post_type}_collection_params`
(`conexao_rest_language_collection_params()` :576) so `OPTIONS /wp/v2/event` documents
`lang: { type: string, enum: [pt, en] }`; query clause helper `conexao_rest_language_tax_clause()` :240,
merge helper :255, posts filter :274; payload builders `conexao_rest_language_post_payload()` :398,
`conexao_rest_language_term_payload()` :440; error `conexao_rest_language_unavailable_error()` :691.

### 10.3 `conexao_language` field (exact shape)

```json
"conexao_language": {
  "lang": "pt",              /* the record's own language slug */
  "is_fallback": false,      /* true iff the record is served under a requested
                                language different from its own (B2) */
  "translations": {          /* linked counterparts, excluding self */
    "en": { "id": 136, "url": "https://…/en/eventos/festa-junina-dublin-2026-en/" }
  }
}
```

Four distinguishable states (Stage 4.1 §4): (1) real PT record → `lang=pt`, `is_fallback=false`;
(2) real EN translation → `lang=en`, `is_fallback=false`, `translations.pt` present; (3) B2 PT fallback under EN
→ `lang=pt`, `is_fallback=true`, no translations; (4) source-inherited EN record → `lang=en`, `is_fallback=false`,
`translations` empty. Term records carry `{ lang, translations }` where **`lang: null` means a SHARED term**
(county/town). The existing `link` field carries the language-correct canonical (real EN → EN permalink; B2 → PT
permalink; source-inherited EN → EN permalink). *"Internal Polylang implementation details are not exposed (no
term ids, no `_translation_outdated` — editorial state stays admin-only)."*


### 10.4 Language-filtering rule per collection (Stage 4.1 §5 table, verbatim)

| Type | `lang=pt` | `lang=en` |
|---|---|---|
| event (B2 + status gate) | `language=pt` AND `_event_status` public | (`language=en` OR `language=pt`) AND public, minus PT masters that have a published EN translation |
| leisure, sponsor, course_provider, job (B2) | `language=pt` | (`language=en` OR `language=pt`), minus replaced PT masters |
| guide, post (B1) | `language=pt` | `language=en` only — **no fallback** |

Verified counts on the pilot dataset: event 6 pt / 7 en (2 EN + 5 B2); leisure 8/8; guide 6/2; posts 5/1;
job 3/3; course_provider 8/8; sponsor 4/4 (Stage 4.1 §5). Filters compose: `include`/`post__in` filtered
directly, pagination never surfaces a replaced master, taxonomy term-id filters compose with the language scope.

### 10.5 B1/B2 membership reporting, wrong-language recovery, hidden events

| Behaviour | Exact |
|---|---|
| B1 membership | *"B1-only content never appears in EN"*; `guide`/`post` EN collections contain real EN records only (Stage 4.1 §5, §18: `/wp-json/wp/v2/guide?lang=en` → 2 records, langs `[en]`, 0 fallbacks) |
| B2 membership | B2 records appear with `is_fallback=true`; counts in §18 matrix: event `en` = 7 records, 5 fallbacks; leisure `en` = 8 records, 7 fallbacks; job/course/sponsor `en` = 3/8/4 records with 2/7/3 fallbacks |
| Wrong-language detail | **404** `conexao_rest_language_unavailable` with `data.translations` populated, *"so a client can re-fetch the right record deterministically"* (Stage 4.1 §6). Matrix rows: `/wp-json/wp/v2/event/73?lang=en` → 404 + EN pointer; `event/136?lang=pt` → 404 + PT pointer; `guide/63?lang=en` → 404 (B1 under EN) |
| Hidden events | Collections, details and search all exclude `expired` / `rejected` / `source_not_found`; **detail requests now answer 404 `rest_post_invalid_id`, "indistinguishable from a nonexistent record", while editorial users (`edit_post` capability) keep access** — *"the one intentional behaviour change on the legacy surface"* (Stage 4.1 §7, §25.4) |
| Backward compatibility | Without `lang`, every legacy collection returns exactly the union of PT+EN public memberships (e.g. `/wp/v2/event` still 8 records = 6 PT + 2 EN; `/wp/v2/search?search=festa` still 4 mixed results); legacy detail answers 200 for any language; the only response difference is the **additive read-only `conexao_language`** field; `_fields` works (incl. `_fields=id,conexao_language`); *"No existing consumer is forced to add `lang=pt`"* (Stage 4.1 §20) |
| Cache | *"**No new response cache and no new caching framework.** WordPress core does not cache REST responses; the module introduces zero persistence."* The only reused cache is `conexao_b2_translation_replaced_pt_ids()` (language-independent, invalidated on `save_post`/`delete_post`/`wp_insert_post`) (Stage 4.1 §11) |

### 10.6 EXACT LIST OF ROUTES TO VERIFY IN PRODUCTION

Generator: `scripts/stage41-rest-verify.py` (340 lines) →
`python3 scripts/stage41-rest-verify.py <base-url> [host] [hidden_event_id]`, default base
`http://localhost:8080`, default hidden-event id `77`. Committed output: **`stage41-rest-matrix.json`** (59 rows).
Local result: **156 passed, 0 failed** console; Stage 4.2 re-check `tool/stage42_contract_check.py
--base https://conexaobr.ie/wp-json/wp/v2` → **11/11 passed** (Flutter-side wire matrix), plus **4/4** live-server
client integration tests.

| # | Route to verify | `lang` values |
|---|---|---|
| 1 | `/wp-json/wp/v2/event` | none, `pt`, `en` |
| 2 | `/wp-json/wp/v2/leisure` | none, `pt`, `en` |
| 3 | `/wp-json/wp/v2/guide` | none, `pt`, `en` |
| 4 | `/wp-json/wp/v2/posts` | none, `pt`, `en` |
| 5 | `/wp-json/wp/v2/job` | none, `pt`, `en` |
| 6 | `/wp-json/wp/v2/course_provider` | none, `pt`, `en` |
| 7 | `/wp-json/wp/v2/sponsor` | none, `pt`, `en` |
| 8 | details for each of the above (`/{base}/{id}`) — PT record, EN translation, B2 fallback, source-inherited EN | `none`, `pt`, `en` (+ wrong-language 404 cases) |
| 9 | `/wp-json/wp/v2/search?search=…` | none, `pt`, `en` |
| 10 | `/wp-json/wp/v2/conexao_category` | `pt`, `en` (`documents`/`finances` vs `documentos`/`financas`) |
| 11 | `/wp-json/wp/v2/categories`, `/wp-json/wp/v2/tags`, `/wp-json/wp/v2/conexao_tag` | `pt`, `en` |
| 12 | `/wp-json/wp/v2/conexao_county`, `/wp-json/wp/v2/conexao_town` | `pt`, `en` (must be identical id-for-id, `lang: null`, no `-en` suffix) |
| 13 | invalid value: the 7 types + `/wp/v2/search` + `/wp/v2/conexao_county` with `lang=xx` | expect **400** `conexao_rest_invalid_lang` |
| 14 | term-id filters composing with language: `/wp-json/wp/v2/guide?conexao_category={EN term id}&lang=en`, `…{PT term id}&lang=pt`, `/wp-json/wp/v2/event?conexao_county={dublin id}&lang=en|pt` | as listed |
| 15 | hidden-event detail: `/wp-json/wp/v2/event/{hidden id}` | none, `en` → 404 `rest_post_invalid_id` |

Stage 4.1 §26 (Stage 4.2 prerequisites) item 4: *"**Production rollout plan for the module** (deploy with the
Stage 3.x content rollout; re-run `scripts/stage41-rest-verify.py` against production once EN content exists
there)."*


---

## 11. CARRY-FORWARD PRODUCTION WORK (verbatim wording)

### 11.1 Stage 4.2 §26 "Stage 4.3 prerequisites" (exact)

1. *"**Deploy the Stage 4.1 module + EN content to production**, then re-run
   `tool/stage42_contract_check.py --base https://conexaobr.ie/wp-json/wp/v2` and the live-server client test
   against production to confirm the same shapes on the deployed stack."*
2. *"**UI chrome localization** (ARB or the project's chosen mechanism) for the ~178 Portuguese literals,
   including date formatting, so an EN user sees an EN interface. `AppStrings` is the ready extension point for
   new labels."*
3. *"**Decide the `pages` REST surface** (Stage 4.1 §26.2) so Contact and other static pages can follow the
   language like the seven contract types."*
4. *"**Production menu, hreflang, metadata and sitemap reconciliation** — unchanged carry-overs from Stage
   3.3/4.1."*
5. *"**English editorial backlog** (Stage 3.3 §7): B1 → real translations decides what the EN app can show; the
   REST contract (and therefore the app) reflects data, not intent."*
6. *"Optional: provide CI credentials for live remote verification so §3's limitation disappears."*

### 11.2 Stage 4.1 §26 items 6–7 — the detailed wording (exact)

6. *"**Production menu work + hreflang consolidation + metadata completion** (Stage 3.3 §25.3–5) — unchanged
   carry-overs."*
7. *"**Apache-level redirect verification + Jetpack sitemap reconciliation** on the deployed stack (Stage 3.3
   §25.1/8) — unchanged carry-overs."*

(Stage 4.1 §26 items 1–5 cover: Flutter repository access + Phases 9–10; the `pages` REST decision;
`/pll/v1` exposure decision; the production rollout plan for the REST module; the English editorial backlog.)

### 11.3 Stage 3.3 §19 "Production-readiness review (Phase 12 — written, NOT executed)" — 15 items

Framing: *"Updated from the Stage 3.2 deployment plan (§27 there) with the Stage 3.3 changes. **Local settings are
not production settings**: every step must be re-verified on WordPress.com before/after rollout."* + *"Nothing in
this list was executed: no upload, no setting change, no plugin installation on production, and no production URL
was contacted during Stage 3.3."*

| # | Item | Action | Stage 3.3 change |
|---|---|---|---|
| 1 | Polylang ZIP/version | *"Install **Polylang Free 3.8.9** (Free only — no Pro), verify the version on the target, keep the plugin ZIP in `dist/`"* | unchanged |
| 2 | Language configuration | *"`pt` (pt_BR, default) + `en` (en_US); `force_lang = 1`, `hide_default = true`, `rewrite = true`, `browser = false`, `media_support = false`; translated post types/taxonomies are code-level (`inc/polylang.php`)"* | unchanged |
| 3 | English front page | *"EN front page = linked translation of the PT front page; `pll_home_url('en') = /en/`; `/en/home/` 301 → `/en/`"* | unchanged |
| 4 | Translated content | *"15 pilot EN records (7 pages + 8 content records) — re-run the Stage 3.2 batch on production (production IDs differ); verify canonical/hreflang per URL"* | unchanged |
| 5 | Taxonomy relationships | *"12 linked EN terms must exist production-side before the content batch; county/town taxonomies stay untranslated"* | unchanged |
| 6 | Source-language event metadata | *"`_event_source_language` writes on import; verify accepted values + absence ⇒ `unknown`"* | unchanged |
| 7 | Export JSON compatibility | *"additive `lang` field; one row per identity; EN translations never exported as identity rows"* | unchanged |
| 8 | Editorial-state code | *"`conexao-admin-ux` translation-state module active; verify `_translation_outdated` sync"* | unchanged |
| 9 | Cache flush | *"flush transients/object cache for **both** languages after the batch (`conexao_flush_language_cache()` keys); never flush only PT"* | **new emphasis** (§17) |
| 10 | Jetpack sitemap reconciliation | *"Jetpack's sitemap must not re-add B2 URLs or duplicates; compare against the theme's 14 EN URLs"* | unchanged (not testable locally) |
| 11 | Redirect verification | *"run `scripts/stage33-http-verify.py` against production **plus** an Apache-level check of the `.htaccess` map"* | **new script** |
| 12 | Canonical/hreflang verification | *"re-check self-canonical EN pages, B2 canonical → PT, `x-default`; decide on consolidating the duplicated Polylang rel-alternate set"* | **new item** (§25.4) |
| 13 | English navigation | *"assign a per-language EN menu to the `primary` location so the header exposes the directory archives; verify the footer menu translation"* | **new item** (§4/§25.3) |
| 14 | Metadata completion | *"author `conexao_meta_description` for the records that still lack it (PT + EN)"* | **new item** (§8) |
| 15 | Rollback | *"keep the pre-rollout DB checkpoint; rollback = delete the EN translations + re-run the cache flush; Polylang deactivation restores Stage 1 behaviour by design (every helper is guarded)"* | unchanged |


### 11.4 Stage 3.2 §27 "Production deployment plan (Phase 10 — written, NOT executed)" — full step list

Header: *"Nothing below was done on production. Every step is manual on WordPress.com; local WordPress settings
cannot simply be copied."*

**Preconditions:** confirm the WordPress.com plan supports uploading plugin ZIPs (**Business or higher**);
confirm production PHP ≥ 7.4 and record the value; full WordPress.com backup + save `/sitemap.xml` and
`/news-sitemap.xml`; plan a change window (*"no downtime expected, but the first requests after activation
rebuild caches"*).

| Step | Title | Key content (condensed; quotes are verbatim) |
|---|---|---|
| 1 | Polylang installation / activation (manual) | Install **Polylang 3.8.9 (Free)** from the WordPress.org directory in wp-admin (*"not from a local copy; **not** Pro"*); add `pt_BR` (slug `pt`) **first** so it becomes default, then `en_US` (slug `en`); Settings → URL modifications per §1.2; *"Assign the default language to all existing content through Polylang's 'assign untranslated contents' action (never hand-assign; never duplicate)."*; check Settings → General → site language `Português do Brasil` + timezone `Europe/Dublin` |
| 2 | Required language configuration | `pll_home_url('pt') = https://conexaobr.ie/`; `pll_home_url('en') = https://conexaobr.ie/en/` — *"the latter requires the English front page to be the linked translation of the PT one (step 3)"* |
| 3 | English static-front-page configuration | *"WordPress.com does not allow theme-file edits: the declaration filters ship with the theme ZIP. Create the English homepage as a linked translation of the PT front page (slug `home`, title `Home`)."* Verify `/en/` 200, `/en/home/` 301 → `/en/`, `/inicio/` 301 → `/`, canonical + hreflang as §14/§15. Failure signal: *"If `/en/` still redirects to `/en/home/`, the deployed theme ZIP is not the Stage 3.2 theme (the self-heal guard is missing)."* |
| 4 | Translation / content migration sequence | Order: **terms first, then content**; then per batch (1) EN term translations (`scripts/stage32-translate-terms.php` is the local template), (2) approved page translations (`scripts/stage32-translate-pages.php`), (3) content per type via the framework. *"Production transfer of translations is **manual/editorial** in Stage 3.2 (local `wp eval-file` scripts are unavailable on WordPress.com)."* + *"Hidden-status events must never be published to make a translation exist."* |
| 5 | Required taxonomy relationships | `conexao_category`/`conexao_tag` must be listed as **translated** (theme declares them in code; *"the settings screen must not override"*); `conexao_county`/`conexao_town` must **not** be translated; *"If production already carries `*-en` county/town terms, clean them up with an equivalent of `scripts/stage32-share-location-taxonomies.php` before publishing links."* |
| 6 | Event source-language metadata rollout | Deploy runtime + importer changes; backfill for pre-existing events as *"a deliberate, reviewed manual/CLI step for known-English sources"*; *"Do not infer language from titles."* |
| 7 | Export JSON compatibility | Additive field; verify one export run: every row `lang` ∈ {pt,en,other,unknown}, no duplicate identity rows, and the import side stores `_event_source_language` when present |
| 8 | Editorial-state deployment | *"Admin-only and inert without Polylang; ships with the Admin UX plugin ZIP. No settings, no migrations."* |
| 9 | Plugin/theme deployment (manual ZIP uploads) | `./scripts/build-plugins-zip.sh`, `./scripts/build-theme-zip.sh`; upload theme `conexao-br-irlanda`, plugins `conexao-event-runtime`, `conexao-admin-ux` (*"the local-only `conexao-event-importer` need not be active on production"*). Deploy order in the window: **theme** (front-page declaration, B2 allowlist, redirect precedence, sitemap fix) → **runtime plugin** (new meta + source-language class) → **Admin UX** (indicator); Polylang first if not yet installed |
| 10 | Cache flush sequence | *"flush the Polylang language cache (saving any language setting does it), purge the WordPress.com edge cache for `/`, `/en/` and the archives, and delete stale un-suffixed legacy `conexao_*` transients. Re-warm `/` and `/en/` and confirm each renders its own language."* |
| 11 | Jetpack sitemap reconciliation | See §9.3 |
| 12 | Rollback procedure | See §13.3 |


---

## 12. OPEN LIMITATIONS / UNRESOLVED ISSUES + EXPLICIT PROHIBITIONS

### 12.1 Open limitations carried to Stage 4.3 (consolidated, with source)

| # | Limitation | Source(s) | Concrete next step stated in the reports |
|---|---|---|---|
| L1 | `.htaccess` redirect layer **never executed locally** (no Apache) — verified *unchanged* only | Stage 3.2 §26.2, Stage 3.3 §18/§25.1, Stage 4.1 §25.7 | Apache-level check on the deployed stack + `scripts/stage33-http-verify.py` against production (Stage 3.3 §19.11) |
| L2 | **Browser/device validation NOT TESTABLE** ("no browser tooling") — markup/HTTP evidence only, *"no visual claim is made anywhere"* | Stage 2 §23, Stage 3.1 §24, Stage 3.2 §25, Stage 3.3 §20, Stage 4.1 §24 | Visual QA post-deploy (not planned inside these stages) |
| L3 | **Jetpack sitemap reconciliation is plan-only**; theme EN entries/alternates not observable on production until reconciled | Stage 3.2 §26.3, Stage 3.3 §19.10 | §9.3 / §11.4 step 11 |
| L4 | **No English menu assigned to `primary`** in the Stage 3.3 dataset → EN header page-list fallback (plus the later repo fix — see §7.6) | Stage 3.3 §25.3 | §11.3 item 13 |
| L5 | **Duplicate hreflang emitters** (theme + Polylang `wp_head`) | Stage 3.3 §25.4 | §11.3 item 12 |
| L6 | **3 EN records lack `conexao_meta_description`** (#108 event, #138 sponsor, #139 course provider — identify by slug) | Stage 3.3 §8.1 / §25.5 | §11.3 item 14 |
| L7 | **`test-town-sanitization.php` = 30 passed / 3 failed** — dataset-existence expectations (`term_exists('Oranmore'\|'Cobh'\|'Corofin', 'conexao_town')`) a fresh pilot catalogue cannot satisfy; *"The test file is unchanged by this stage."* Also `test-plugin-separation.php` `no-tooling` mode 24/6 (only `with-tooling` 34/0 applicable) | Stage 3.2 §22, Stage 3.3 §23, Stage 4.1 §25.8 | None (pre-existing; the production dataset may satisfy them) |
| L8 | **EN event translations are not transferred by the export JSON** — *"The export carries one row per identity (by design); linked EN translations are editorial records and their production transfer is a later-stage concern"* | Stage 3.2 §26.4 | Manual/editorial production transfer (§11.4 step 4) |
| L9 | **`/en/{pt-slug}/` for a translated record 302s to the PT URL** rather than to its EN translation (`/en/sobre-nos/` → `/sobre-nos/`) — documented B1 behaviour for PT slugs under `/en/`, *"Kept unchanged here — no gate requires it"* | Stage 3.2 §26.6 | Optional improvement (never implemented) |
| L10 | **PT search (`post_type=any`) does not apply the event status gate** — *"pre-existing, unrelated to B2, deliberately left untouched"* | Stage 3.1 §25.6 | Out of scope by explicit decision |
| L11 | **Polylang Free provides no `?lang=` collection filtering / no translations link in REST** (Stage 2 §19: identical results for `?lang=pt-br`, `?lang=en`, no param; single items had no `lang` field, no `_links.translations`) → **superseded by the Stage 4.1 module** | Stage 2 §19 / §25.6 | Fixed in Stage 4.1 (frozen contract) |
| L12 | **Polylang Pro absent** → *"no Pro translation editor and no outdated-translation workflow"*; free-tier per-post linking + the in-repo `_translation_outdated` indicator is what exists | Stage 2 §25.8, Stage 3.1 §25.8 | Editorial workflow stays in-repo |
| L13 | **`en_US` catalog is a curated subset** (65 strings Stage 1; +2 Stage 2) — untranslated strings fall back to Portuguese by design; plugin catalogs stay POT-only (*"admin English support is deferred by design"*) | Stage 1 §16, Stage 2 §25.9 | Complete the catalog (Stage 1 §17 item 4) |
| L14 | **Local dataset ≠ production dataset** — *"Conclusions about *behaviour* transfer; conclusions about *specific IDs* do not."* | Stage 3.3 §25.7, Stage 4.1 §25.6 | Rebuild on production; verify by slug |
| L15 | **`scripts/stage32-share-location-taxonomies.php` WP 7.1 `clean_term_cache()` fatal** (final cache step only) | Stage 3.3 §25.9 | One-line fix (§5.2) |
| L16 | **Flutter UI chrome untranslated** (~178 PT literals; date formatting) — the single Stage 4.2 limitation | Stage 4.2 §25.1 / §26.2 | Stage 4.3 prerequisite |
| L17 | **`page` not in the REST language contract** — static pages keep pre-Stage-4.1 REST behaviour | Stage 4.1 §25.2 | Decide the `pages` REST surface (§11.1 item 3) |
| L18 | **`lang` validated only on contract routes** (7 types + details, 6 taxonomies, `/wp/v2/search`); other routes keep Polylang's silent invalid-value fallback | Stage 4.1 §25.5 | By design |
| L19 | **Hidden-event detail 404 is a deliberate legacy-surface change** (baseline: 200 with full payload) | Stage 4.1 §25.4 | Documented for consumers |
| L20 | **Stage-1 locale/date-format settings are local-only**; production WordPress.com General Settings must receive `pt_BR` + the PT date format | Stage 1 §16, Stage 2 §25.11 | §11.4 step 1 |


### 12.2 EXPLICIT PROHIBITIONS / NON-GOALS (do not violate in Stage 4.3)

| Prohibition | Source (exact) |
|---|---|
| **Do not install Polylang Pro** (no licence; Free only) | Stage 2 §2 (*"**Not installed.** No license is available…"*), Stage 3.2 §26.8, Stage 3.3 §19.1 (*"Free only — no Pro"*) |
| **Do not modify `.htaccess` or the legacy redirect map** | Stage 2 §15 (*"The legacy redirect table was **not modified** (`.htaccess` untouched, no entry removed or reordered)"*), Stage 2 §22, Stage 3.1 §21, Stage 3.2 §18 (*"`.htaccess` and the theme's `conexao_seo_redirects()` map body are byte-identical (git-verified; no entry removed, reordered or retargeted)"*), Stage 3.3 §22, Stage 4.1 §22 (*"`.htaccess` (legacy redirect map, headers) — byte-untouched"*) |
| **Do not rename Portuguese CPT slugs / change PT URLs** | Decision §6 and §26 (non-goal *"Rename Portuguese CPT slugs"*); Stage 2/3.x *"PT URLs byte-identical"* gates |
| **Do not create duplicate Event/Lazer records; never a second identity record** | Decision §10, §11, §26 (*"Create duplicate Event/Lazer records"*); Stage 3.2 §6 hard gates; Stage 4.1 §7/§8 |
| **Do not fragment taxonomy terms per language** (no `-pt`/`-en` suffix terms) | Decision §26 (*"Fragment taxonomy terms per language"*); Stage 3.2 §10; Stage 4.2 gate 12 |
| **Do not use machine translation for content** | Audit §4.3 (*"machine translation is explicitly out of scope"*); Decision §26 (*"Use machine translation for content"*) |
| **Do not change the SEO architecture** — *"SEO architecture was **not** modified in this stage"*; *"do not modify SEO architecture"* governs the hreflang consolidation decision | Stage 3.3 §14 / §25.4 |
| **Do not put legal/sensitive pages into B2 to make them render; B2 is never a catch-all** | Stage 3.2 §5 |
| **Do not touch `conexaobr.com`** — unrelated site (`CXBR Investimentos`, theme `hello-elementor`), self-canonical, *"must not be treated as related infrastructure"* | Audit "Canonical verification"; Decision §26 |
| **Do not install/activate the local-only `conexao-event-importer` on production** | `docs/deployment.md`; Stage 3.2 §27 step 9 |
| **Do not infer language from titles/body text** | Stage 3.2 §7; Stage 4.2 gate 13 |
| **Do not publish hidden-status events to make a translation exist** | Stage 3.2 §27 step 4 |
| **Do not let B2 shell URLs become canonical EN records or enter the sitemap** | Decision §15; Stage 3.2 §16; Stage 3.3 §26.1 |
| **Do not treat local IDs or local settings as production values** | Stage 3.2 §27 (*"local WordPress settings cannot simply be copied"*), Stage 3.3 §25.7, Stage 4.1 §25.6 |
| **Do not flush only one language's cache after a batch** | Stage 3.3 §19.9 |

### 12.3 Unresolved items named by the decision as "to be resolved in Stage 2 staging" (and their outcome)

| Item (decision §25 "Unresolved") | Outcome recorded later |
|---|---|
| WordPress.com Business plan Polylang compatibility verification | Still **NOT_TESTABLE** locally; Stage 2 §23/§26 lists it as production-only; Stage 3.2 §27 precondition (Business or higher) |
| Jetpack sitemap disable vs augment on production | Still open (§9.3) |
| Exact set of EN 301s to keep vs retire (analytics-driven) | **No redirect retired** — Stage 2 §15 *"All of them remain"*; Stage 3.2 §18/§4 *"No redirect-map entry was removed or changed"* |
| Polylang Pro budget decision (Free vs Pro for editorial workflow) | **Free only**, in-repo indicator (§12.1 L12) |
| REST/Flutter contract assumed in decision §19 (`?lang=en`, `_links.translations`) | Not delivered by Polylang Free (Stage 2 §19) → implemented by the theme in Stage 4.1 as `lang` + `conexao_language` (**FROZEN**) |


---

## 13. DEPLOYMENT / ROLLBACK GUIDANCE ALREADY WRITTEN (`docs/deployment.md` + reports)

### 13.1 What `docs/deployment.md` says (verbatim extracts)

| Topic | Exact text |
|---|---|
| Production platform | *"- **Platform**: WordPress.com / - **Domain**: https://conexaobr.ie / - **Deployment method**: Manual ZIP upload via WordPress admin"* |
| Environment differences table | *"Web server: Local Apache (Docker) → WordPress.com managed; Database: MySQL in Docker → WordPress.com managed; File system: Bind-mounted → WordPress.com managed; HTTPS: HTTP only → HTTPS (managed); Rewrite rules: `.htaccess` active → **WordPress.com supports .htaccess**; Cron: Manual / WP-CLI → WordPress.com wp-cron"* |
| Build (plugins) | `./scripts/build-plugins-zip.sh` → *"Output: `dist/conexao-data-model.zip`, `dist/conexao-content.zip`, `dist/conexao-admin-ux.zip`, `dist/conexao-event-runtime.zip`, `dist/conexao-event-importer.zip`, `dist/conexao-leisure-migration.zip`"*; *"Import via WordPress Admin → Plugins → Add New → Upload Plugin. Activate in load order."* |
| Which plugins production needs | *"**Production** needs: data-model, content, admin-ux, **event-runtime**. The event importer (local tooling) should **not** be installed or active on production — see docs/plugins/conexao-event-runtime.md for the activation and migration plan."* |
| Build (theme) | `./scripts/build-theme-zip.sh` → `dist/conexao-br-irlanda.zip`; *"Import via WordPress Admin → Appearance → Themes → Add New → Upload Theme."* |
| Production constraints | *"- **Production**: WordPress.com managed, WP_DEBUG disabled, caching enabled / - **Domain-specific configuration**: None required — all paths are relative"* |
| No CLI on production | *"Production is WordPress.com (no SSH/SFTP/CLI), so image corrections cannot be run from a PHP script. Use the standard-library REST script instead"* (§Lazer image refresh) |
| Deployment checklist (first copy) | *"1. Build plugin ZIPs (`./scripts/build-plugins-zip.sh`) 2. Build theme ZIP (`./scripts/build-theme-zip.sh`) 3. Upload and activate plugins in load order on production 4. Upload and activate theme on production 5. Verify all CPT archives load 6. Verify redirects work (English → Portuguese) 7. Run any required seed scripts 8. Test event import 9. Test leisure import (if applicable) 10. Verify sitemap at `/sitemap.xml`"* |
| Media thumbnails | After a theme update, regenerate `conexao-sponsor-tile` (512px) and `conexao-event-preview` (240×135 crop) for pre-existing attachments: `wp media regenerate <IDs...> --skip-delete --yes --allow-root` |
| Media rule (architecture rule 5) | *"Production leisure images are local Media Library attachments. Wikimedia metadata is attribution-only, never a hotlink."* |

### 13.2 Multilingual-specific deploy/verification commands in the repo docs

`docs/development.md` §"Multilingual (EN) development — Stage 2" (local):

```bash
docker compose exec -T wordpress wp plugin install polylang --version=3.8.9 --allow-root
docker compose exec -T wordpress wp plugin activate polylang --allow-root
docker compose exec -T wordpress wp eval-file - --allow-root < scripts/stage2-polylang-setup.php
#    dry run:  ... < scripts/stage2-polylang-setup.php dry-run
./scripts/stage2-http-verify.sh http://localhost:8080
docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-polylang-foundation.php
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-runtime/tests/test-event-language-gate.php
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-language-identity.php
docker compose exec wordpress php /var/www/html/wp-content/plugins/conexao-leisure-migration/tests/test-language-uuid.php
```

Production verification scripts (run against the deployed base URL):
`python3 scripts/stage33-http-verify.py https://conexaobr.ie` (122 assertions locally),
`python3 scripts/stage41-rest-verify.py https://conexaobr.ie/wp-json/wp/v2` (156 checks / 59 rows),
`tool/stage42_contract_check.py --base https://conexaobr.ie/wp-json/wp/v2` (Flutter repo; 11 checks).

### 13.3 Rollback (exact, from Stage 3.2 §27 step 12 + Stage 3.3 §19.15 + decision §22)

| Level | Procedure |
|---|---|
| Plugin-level (simplest) | *"Deactivate Polylang → the site returns to single-language PT exactly as before (all theme/plugin guards are no-ops without Polylang; deactivation does not touch PT content, URLs or identity meta)."* |
| Code-level | *"restore the previous plugin/theme ZIPs from the WordPress.com backup."* |
| Full | *"restore the database backup."* |
| Content-level (Stage 3.3 §19.15) | *"keep the pre-rollout DB checkpoint; rollback = delete the EN translations + re-run the cache flush."* |
| Post-rollback checks | *"`/` unchanged, PT archives/singles 200, legacy redirects 301, sitemap back to its pre-stage content."* |

Decision §22 "What is preserved on rollback": all PT posts/pages **yes**; CPT slugs **yes** ("Never changed");
event identity meta **yes** ("Language-neutral meta"); Lazer UUID **yes**; `.htaccess` redirects **yes** ("Never
touched"); `inc/seo.php` canonical/hreflang **reverts to single-language** (no-ops when Polylang inactive);
homepage transients **flushed — regenerated in PT**; Jetpack sitemap **unaffected**. Rollback risk = **Medium**
(language data lives in `wp_options` + postmeta/termmeta; full data removal requires a backup restore;
WordPress.com backups available).


---

## 14. CONTRADICTIONS, DEPRECATED STATEMENTS, AND THE LOCAL-ID MAP

### 14.1 Contradictions / superseded statements (flag to the deployer)

| # | Conflict | Resolution to use |
|---|---|---|
| C1 | **County/town taxonomy model.** Stage 2 §4 lists `conexao_county`/`conexao_town` as *"Translated taxonomies"*; Decision §9 says county/town use *"one term ID, per-language names"*. | **Stage 3.2 §10 + code win:** county/town are **NOT** translated — one shared term, no language, no translations (§1.4, §6). |
| C2 | **B2 hreflang shape.** Decision §12 says B2 emits a *"hreflang pair"*. | **Stage 3.1 §14 / Stage 3.2 §15/§28 win:** B2 emits **`x-default` only**. |
| C3 | **EN homepage URL.** Stage 3.1 §12/§25.1 says `/en/` 301s to `/en/home/` and calls the homepage **BLOCKED**; Stage 3.2 §1/§3 says the blocker is **resolved**. | Stage 3.2 final: `/en/` 200 EN homepage; `/en/home/` 301 → `/en/`. Stage 3.1 text is historical. |
| C4 | **REST contract source.** Decision §19 promises Polylang `?lang=en` + `_links.translations`; Stage 2 §19 proves Polylang Free does **not** provide them. | **Stage 4.1's theme module is the delivered contract** (`lang` param + `conexao_language`), FROZEN. |
| C5 | **Sitemap local counts.** Stage 3.1 §15 reports **1711** theme `<url>` entries; Stage 3.2 §16 / Stage 3.3 §16 report **107 `<loc>`** with 14 EN URLs. | Different local datasets (large imported catalogue vs pilot). Neither is a production expectation — measure production. |
| C6 | **EN static-page IDs.** Stage 3.2 §4: privacy-policy 130, terms-of-use 131, cookie-policy 132. Stage 3.3 §2: terms-of-use 130, cookie-policy 131, privacy-policy 141. | Both `[LOCAL ONLY]`; stable contract = PT slug ↔ EN slug (§3). |
| C7 | **The 3 metadata-less EN records' IDs.** Stage 3.2 §6: #97 event / #139 sponsor / #140 course. Stage 3.3 §8.1+§25.5: #108 / #138 / #139. | `[LOCAL ONLY]`; identify by type + slug (§8.5). |
| C8 | **EN menu state.** Stage 3.3 §25.3 (no EN menu assigned, page-list fallback) vs `docs/routing.md` §"Stage 3.3" + `scripts/create-en-primary-menu.php` + the three nav fix reports (EN "Main Menu", term 2933 locally, assigned to `primary`). | Report text frozen at write time; fix landed later in the same work-line. Production action unchanged: create/assign per-language menus in wp-admin (§7.6). |
| C9 | **`dublin-en` cleanup scope.** Stage 2 §12 asserts *"no per-language suffixed terms"*; Stage 3.2 §10 documents Polylang Free ≥ 3.5 **did** create `dublin-en` (county 213 / town 216) once EN records existed. | Stage 3.2/3.3 final; the policy change + cleanup script removed them. Stage 2's assertion predates EN terminally-assigned content. |
| C10 | **Failure counts in `test-plugin-separation.php` / `test-town-sanitization.php`** vary by stage (Stage 2 §23: 4 `with-tooling`; Stage 3.2 §22: 3 town; Stage 4.1 §25.8: same) because plugin set/dataset changed. | All pre-existing/dataset-dependent, not regressions (§12.1 L7). |
| C11 | **`_lazer_uuid` vs `_leisure_export_uuid`.** Decision §11/§22 use `_lazer_uuid`; the implementation uses `Conexao_Lazer_Exporter::UUID_META_KEY = '_leisure_export_uuid'`. | **Use `_leisure_export_uuid`** (Stage 2 §11 flags the naming difference explicitly). |

### 14.2 Local-ID map (cross-reading the reports only — never deploy)

| Concept | Stage 3.1 [LOCAL] | Stage 3.2 [LOCAL] | Stage 3.3 [LOCAL] | Stable key |
|---|---|---|---|---|
| PT front page | `inicio` (created by pilot) | `inicio` 4 | `inicio` 4 | slug `inicio` |
| EN front page | `Home` 22150 | `home` 126 | `home` 126 | slug `home` |
| About / Contact EN pages | 22152 / 22151 | 127 / 128 | 127 / 128 | slugs `about-us` / `contact` |
| EN term for `natureza` | `nature` 2875 (large dataset) | `nature` 191 | — | `natureza` ↔ `nature` |
| Event pilot master | — | `festa-junina-dublin-2026` 90 | 101 | slug |
| Source-inherited EN event | — | 97 | 108 | slug `irish-dance-workshop-dublin` |
| Leisure pilot | — | `phoenix-park` 100 → EN 138 | PT 111 | slug |
| Sponsor pilot | — | `brasil-market-dublin` 108 → EN 139 | PT 119 → EN 138 | slug |
| Course provider pilot | — | `fetch-courses` 115 → EN 140 | PT 80 → EN 139 | slug |
| Job pilot | — | `ajudante-de-cozinha-dublin` 113 → EN 141 | PT 124 | slug |
| EN nav menu | — | — | — | `Main Menu` (term 2933 in the later fix); PT = `Menu Principal` |


### 14.3 Test/verification evidence a Stage 4.3 deployer can re-run

| Command | Expected (local, per reports) |
|---|---|
| `./scripts/stage2-http-verify.sh <base>` | *"== Stage 2 HTTP verification: 61 passed, 0 failed =="* (Stage 2 §23) |
| `python3 scripts/stage33-http-verify.py <base>` | 122 passed / 0 failed (Stage 3.3 §23) |
| `python3 scripts/stage41-rest-verify.py <base> [host] [hidden_id]` | 156 passed / 0 failed; 59-row `stage41-rest-matrix.json` (Stage 4.1 §18) |
| `php …/tests/test-polylang-foundation.php` | 57 (Stage 2) → 61–62 (Stage 3.2) → 62 (Stage 3.3) passed / 0 failed |
| `php …/tests/test-stage32-bilingual.php` | 42 / 0 |
| `php …/tests/test-stage33-bilingual.php` | 93 / 0 |
| `php …/tests/test-stage41-rest-language.php` | 212 assertions, 0 failed |
| `php …/conexao-event-importer/tests/test-language-identity.php` | 27 / 0 |
| `php …/conexao-event-importer/tests/test-export-language.php` | 57 / 0 |
| `php …/conexao-event-runtime/tests/test-event-language-gate.php` | 16 / 0 |
| `php …/conexao-leisure-migration/tests/test-language-uuid.php` | 14 / 0 |
| `php …/conexao-admin-ux/tests/test-translation-state.php` | 22 / 0 |
| `php …/tests/test-town-sanitization.php` | 30 / **3 (pre-existing, dataset-existence)** |
| `flutter analyze` (Stage 4.2) | *"No issues found"*; suite 1037 passed / 0 failed (baseline 995) |

---

## 15. FIFTEEN-LINE DECISION SUMMARY (for the Stage 4.3 deployer)

1. **Polylang:** Free **3.8.9** only (no Pro); languages `pt` (`pt_BR`, **default**) + `en` (`en_US`); `force_lang=1`, `hide_default=true`, `rewrite=true`, `browser=false`, `redirect_lang=false`, `media_support=false`; translated types declared in code (`inc/polylang.php` adds `guide,event,leisure,sponsor,job,course_provider`; `post`/`page` come from Polylang defaults).
2. Translated taxonomies = `conexao_category`, `conexao_tag` (+ `category`, `post_tag`); **`conexao_county`/`conexao_town` are NOT translated** (shared proper-noun terms — the `dublin-en` fix).
3. **B2 allowlist:** post types `event,leisure,sponsor,course_provider,job`; pages `irlanda` + `dublin,cork,galway,limerick,kildare,meath,wicklow,waterford,laois` (`conexao_b2_page_allowlist()`, `inc/polylang.php:1202`).
4. **B2 output:** PT body under the EN URL + notice `This content is displayed in Portuguese.`, canonical → PT URL, hreflang `x-default` only, **absent from the EN sitemap**, REST `is_fallback:true`.
5. **B1 = 302** (never 301) to the PT URL (`template_redirect` priority 6); the legacy EN→PT **301** map is untouched and `conexao_seo_redirects()` runs at priority **2** (before Polylang's 4).
6. **EN front page:** the EN page is a linked translation of the PT front page (slug `home`), declared via `pll_additional_language_data` (+ self-heal); `/en/` 200, `/en/home/` 301 → `/en/`, `/inicio/` 301 → `/`.
7. **7 approved EN pages:** inicio→home, sobre-nos→about-us, contato→contact, empregos→jobs, politica-de-privacidade→privacy-policy, termos-de-uso→terms-of-use, cookies→cookie-policy (`stage32-translate-pages.php`).
8. **Migration scripts are local-only, slug/identity-keyed, mostly not dry-runnable:** `stage32-seed-pilot.php`, `stage32-translate-pages.php`, `stage32-translate-content.php`, `stage32-translate-terms.php`, `stage32-share-location-taxonomies.php`; only `stage2-polylang-setup.php` has `dry-run`; production transfer is **manual/editorial, terms first then content**.
9. **REST contract is FROZEN** (`inc/rest-language.php`): existing `/wp/v2/…` routes, `?lang=pt|en`, invalid → **400 `conexao_rest_invalid_lang`**, additive `conexao_language {lang,is_fallback,translations}`, wrong-language detail → 404 `conexao_rest_language_unavailable` (+ recovery translations), hidden events → 404.
10. **Known production blockers:** Jetpack sitemap reconciliation (Jetpack owns `/sitemap.xml`), duplicate hreflang emitters, no EN menu on `primary`, 3 records missing meta descriptions (#108 event / #138 sponsor / #139 course — by slug), `.htaccess` never executed locally.
11. **Identity invariants are non-negotiable:** one Event per `_event_source` + `_event_source_id`; one Lazer per `_leisure_export_uuid` (**not** `_lazer_uuid`); county/town never duplicated; hidden events never public.
12. **Cache:** every language-sensitive key is `_pt`/`_en` (`conexao_lang_cache_key()`), and `conexao_flush_language_cache()` clears all languages — flush **both** after any content batch.
13. **Do not:** install Polylang Pro, edit `.htaccess`, change the redirect map, rename PT slugs, machine-translate content, or carry local IDs/settings to production.
14. **Deploy order:** Polylang → theme ZIP (front-page declaration, B2 allowlist, redirect precedence, sitemap fix) → `conexao-event-runtime` → `conexao-admin-ux`; build with `./scripts/build-plugins-zip.sh` + `./scripts/build-theme-zip.sh`; keep `conexao-event-importer` off production.
15. **Rollback:** deactivate Polylang (single-language PT restored, identity meta untouched) → restore prior plugin/theme ZIPs → restore the DB backup; then re-check `/`, PT archives/singles, the legacy 301s and the sitemap.

