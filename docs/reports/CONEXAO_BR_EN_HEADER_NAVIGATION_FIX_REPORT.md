# Conexão BR — English Header Navigation / Polylang Menu Assignment Fix Report

**Scope:** the English (`/en/`) primary header navigation rendering an empty `<nav>` after the previous header-fallback fix.
**Environment:** local Docker stack (`compose.yaml`, WordPress + MySQL + Polylang 3.8.9) at `http://localhost:8080`, branch `i18n`. Production untouched.
**Predecessor:** CONEXAO_BR_HEADER_NAVIGATION_REGRESSION_REPORT.md (removed the `wp_page_menu` page-list fallback).

**`EN_MENU_STATUS = MISSING`** — no English menu existed in the database and Polylang's per-language `primary` assignment had no `en` entry. The empty `<nav>` was the theme's safe empty fallback working as designed against a genuine data gap, not a filtering bug.

---

## 1. Root cause

Once Polylang is active, `PLL_Frontend_Nav_Menu::nav_menu_locations()` (filter `theme_mod_nav_menu_locations`, priority 20) **unconditionally overwrites every registered theme location** with the per-language assignment stored in Polylang's `nav_menus` option:

```php
$menus[ $loc ] = empty( $this->options['nav_menus'][ $theme ][ $loc ][ $this->curlang->slug ] )
    ? 0 : $this->options['nav_menus'][ $theme ][ $loc ][ $this->curlang->slug ];
```

Observed state before the fix (verified in `wp_options`):

- `polylang.nav_menus = { 'conexao-br-irlanda': { primary: { pt: 1381 } } }` — **no `en` key**.
- `nav_menu` terms in the database: **only** `Menu Principal` (1381) and `Menu Rodapé` (1382). **No English menu existed.**
- Theme mod `nav_menu_locations = { primary: 1381, footer: 0 }` (irrelevant under Polylang).

Therefore on any `/en/` request `curlang->slug = 'en'` → `primary` resolved to `0` → `wp_nav_menu('primary')` had no menu → the theme's safe fallback `conexao_safe_nav_menu_fallback()` correctly rendered nothing (it replaced `wp_page_menu` in the previous fix, and that behaviour is preserved).

Two secondary defects surfaced while making the EN menu render, both in the **render-time nav layer**, which had been written PT-first because an EN menu never existed to exercise it:

1. **PT-only forced labels**: `conexao_modify_primary_nav_items()` renamed any `Home`-like item to `Início` and any `Lazer`/`Leisure` item to `Lazer e turismo`, regardless of language.
2. **PT-only destinations/active states**:
   - `conexao_primary_nav_sections()` built CPT archive URLs with `get_post_type_archive_link()` (always Portuguese) and the Blog URL with `home_url( '/blog/' )`.
   - `conexao_bind_section_object()` bound page sections to the Portuguese page only (`get_permalink( PT page )`), so an EN Contact item would have linked to `/contato/`.
   - `conexao_fix_nav_active_states()` matched the raw request path against PT patterns, so `/en/guias/` never matched `/guias`.

## 2. Current PT menu state (unchanged)

| Item | Value |
|---|---|
| Theme location | `primary` (registered in `functions.php:534`) |
| Polylang assignment | `nav_menus['conexao-br-irlanda']['primary']['pt'] = 1381` |
| Menu | `Menu Principal` (term 1381, 9 stored items) |
| Stored items | Home, Apoiadores, Guias, Eventos, Cursos, Empregos, Blog, Sobre Nós*, Contato (*removed at render) |
| Rendered order | Início, Apoiadores, Guias, Eventos, Cursos, Lazer e turismo, Empregos, Blog, Contato |
| Render filters | `conexao_override_guides_menu_links` (10), `conexao_modify_primary_nav_items` (20), `conexao_normalize_primary_nav_sections` (25) |

## 3. Current EN menu state (after the fix)

| Item | Value |
|---|---|
| Polylang assignment | `nav_menus['conexao-br-irlanda']['primary']['en'] = 2933` |
| Menu | `Main Menu` (term 2933, slug `main-menu`, 10 stored items) |
| Stored items | Home, Sponsors, Guides, Events, Courses, Leisure & Tourism, Jobs, Blog, About Us*, Contact (*removed at render) |
| Stored URLs | canonical PT paths (`/`, `/apoiadores/`, …) + page objects for About Us/Contact — resolved per language at render |
| Rendered order | Home, Sponsors, Guides, Events, Courses, Leisure & Tourism, Jobs, Blog, Contact |
| Rendered destinations | `/en/`, `/en/apoiadores/`, `/en/guias/`, `/en/eventos/`, `/en/cursos/`, `/en/lazer/`, `/empregos/` (B1), `/blog/` (B1), `/en/contact/` |

## 4. Polylang menu configuration found

- Strategy: **one theme location, per-language menus** (`nav_menus[stylesheet][location][language]`) — Polylang Free's native model; the assignment is exactly what wp-admin's per-language Menus tabs write.
- PT: "Menu Principal" (1381). EN: "Main Menu" (2933). No shared-menu-with-language-filtered-items strategy exists in this project; the per-language architecture was preserved.
- Nav menus are **not** translatable terms in this setup (no term meta/term-language rows for 1381/1382), and none is required: the frontend resolution uses the `nav_menus` option only. Menu *translations* are therefore expressed as two sibling menus assigned per language, each editable in wp-admin.
- Polylang Free does not translate or filter **custom-link** menu items per language, and does not translate page-object items automatically in 3.8.9 — which is why the theme's own Stage 3.3 language-aware link layer had to cover the nav (see §7).

## 5. Files changed

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/functions.php` | Render-time nav layer made language-aware (5 functions + 1 new helper). See §7. Pre-existing from the earlier fix: `conexao_safe_nav_menu_fallback()`. |
| `scripts/create-en-primary-menu.php` | **New.** Idempotent, data-only: creates the "Main Menu" EN mirror of "Menu Principal" and writes `nav_menus['primary']['en']`. Never touches PT items, footer/social menus, or the plain location mod. |
| `scripts/assign-polylang-nav-menus.php` | Comment/note update only (EN now handled by the new script); behaviour unchanged. |
| `wp-content/themes/conexao-br-irlanda/tests/test-nav-menu-regression.php` | **New** (from the earlier fix) + new section C: EN menu existence, per-language assignment, stored content, PT untouched, language-aware helpers. |
| `wp-content/themes/conexao-br-irlanda/tests/test-header-menu-selection.php` | **New.** Boots WordPress under a PT or EN request context and asserts actual menu selection/rendering per language. |
| `scripts/nav-regression-http-verify.py` | **New** (from the earlier fix) + EN expectations: canonical EN labels/order/destinations, aria-current, mobile drawer, EN content pages. |
| `docs/routing.md` | EN primary navigation bullet + §Navigation Architecture updated (per-language assignment, language-aware render layer, the new script). |
| `docs/themes/conexao-br-irlanda.md` | One-paragraph menu-selection cross-reference updated. |
| `wp-content/themes/conexao-br-irlanda/header.php` | **Not touched by this fix.** (Its two `fallback_cb` lines were changed to `conexao_safe_nav_menu_fallback` by the earlier fix and remain so.) |

No other files, templates, CSS, JS, plugins or docs were modified.

## 6. Database / configuration changes

Local database only (documented, minimal, no production contact):

1. **Created** nav menu term `Main Menu` → **term_id 2933** (`nav-menu` taxonomy), 10 items (IDs 22188–22197), mirroring the stored structure/titles of `Menu Principal`.
2. **Updated** `wp_options.polylang`: added `nav_menus['conexao-br-irlanda']['primary']['en'] = 2933`. No other key of the option was modified; `['primary']['pt'] = 1381` is untouched.
3. Nothing else: no posts/pages/CPTs/taxonomies/terms/redirects/theme mods were created or modified. `nav_menu_locations.primary` remains `1381`.

Idempotency verified: a re-run of `scripts/assign-polylang-nav-menus.php` reports "EN already has … = 2933 — left untouched"; re-running the creation script reports the menu already populated and changes nothing.

Production: **BLOCKED by policy** (not modified from this task). The equivalent production action is editorial and done in wp-admin → Appearance → Menus (create the EN menu, assign it under Polylang's per-language tab for `primary`), or by running the same script where WP-CLI/PHP is available.


## 7. Exact implementation fix

### 7.1 Data (the actual cause)

`scripts/create-en-primary-menu.php` (idempotent, local/staging only):
- finds/creates the `Main Menu` nav menu;
- populates it only when empty, mirroring the PT stored structure: custom links to the canonical PT paths (`/`, `/apoiadores/`, `/guias/`, `/eventos/`, `/cursos/`, `/lazer/`, `/empregos/`, `/blog/`) and page objects for `sobre-nos` (About Us) and `contato` (Contact), with English titles;
- writes `nav_menus['stylesheet']['primary']['en']` — the same option wp-admin's per-language Menus tabs write.

No navigation HTML or links were hard-coded in `header.php`; the header still calls `wp_nav_menu( 'primary' )` and both menus remain fully editable in wp-admin.

### 7.2 Theme render-time layer made language-aware (all guarded; PT output byte-identical)

- **New `conexao_primary_nav_archive_url( $post_type, $fallback_slug )`**: default language → `get_post_type_archive_link()` exactly as before; other languages → `conexao_language_archive_url()` (e.g. `/en/eventos/`).
- **`conexao_primary_nav_sections()`**: section URLs use that helper; the Blog section uses `conexao_lang_url( '/blog/' )` (byte-identical on PT; approved B1 destination on EN). The `'início'` section keeps the bare `home_url( '/' )`, which Polylang rewrites to `/en/` in a real EN request.
- **`conexao_bind_section_object()`**: page sections bind the **linked translation** of the canonical PT page when the current language has a published one (`/contato/` → `/en/contact/`); otherwise the approved B1 Portuguese page. This applies the project's existing translation-identity rule to nav binding.
- **`conexao_modify_primary_nav_items()`**: canonical labels are per language — `Início`/`Home` and `Lazer e turismo`/`Leisure & Tourism`. Both English labels are the existing labels of the real EN records/sections, not invented strings for untranslated content.
- **`conexao_normalize_primary_nav_sections()`**: added a final section-resolution fallback through the shared `conexao_get_item_section_key()` (which understands `Home` and the bare homepage link — the case the PT title/URL lookup cannot match, and the reason the EN Home item was initially left unbound); the Blog/Cursos/generic CPT fallback inserts now use language-aware URLs and titles.
- **`conexao_get_item_section_key()`**: added the canonical EN title aliases (`guides`, `events`, `courses`, `leisure`, `leisure & tourism`, `jobs`, `sponsors`, `ireland`, `about`, `about us`, `contact`) so EN items bind deterministically.
- **`conexao_fix_nav_active_states()`**: strips the current non-default language prefix (`/en`) from the request path before matching its canonical PT patterns, so `/en/guias/…` marks Guides active and `/en/` marks Home active.

## 8. PT verification — **PASS**

- Homepage `/`: `<nav id="site-navigation">` → `<ul id="primary-menu">` with 9 items, order Início, Apoiadores, Guias, Eventos, Cursos, Lazer e turismo, Empregos, Blog, Contato; Início carries `current-menu-item` + `aria-current="page"`; hrefs unchanged (`/`, `/apoiadores/`, `/guias/`, `/eventos/`, `/cursos/`, `/lazer/`, `/empregos/`, `/blog/`, `/contato/`); **0** page-list (`page_item`-only) entries.
- Content page `/contato/`: same 9-item menu, Contato current, no page-list.
- Mobile drawer (`<nav class="mobile-menu-nav">`): the same 9 canonical items.
- All 9 PT destination URLs return **HTTP 200**.
- Defaults preserved when no language context exists (CLI/admin): asserted by tests A1–A15 and C9.


## 9. EN verification — **PASS**

`/en/` markup (desktop):

```html
<nav id="site-navigation" class="primary-navigation" aria-label="Menu Principal">
  <ul id="primary-menu" class="primary-menu">
    <li ... menu-item-home current-menu-item ...><a href="http://localhost:8080/en/" aria-current="page" class="nav-link">Home</a></li>
    <li ...><a href="http://localhost:8080/en/apoiadores/" class="nav-link">Sponsors</a></li>
    <li ...><a href="http://localhost:8080/en/guias/" class="nav-link">Guides</a></li>
    <li ...><a href="http://localhost:8080/en/eventos/" class="nav-link">Events</a></li>
    <li ...><a href="http://localhost:8080/en/cursos/" class="nav-link">Courses</a></li>
    <li ...><a href="http://localhost:8080/en/lazer/" class="nav-link">Leisure &amp; Tourism</a></li>
    <li ...><a href="http://localhost:8080/empregos/" class="nav-link">Jobs</a></li>
    <li ...><a href="http://localhost:8080/blog/" class="nav-link">Blog</a></li>
    <li ...><a href="http://localhost:8080/en/contact/" class="nav-link">Contact</a></li>
  </ul>
</nav>
```

- **9 items**, canonical EN order; **no** page-list fallback; **not empty**.
- **`aria-current="page"`** on Home (real request).
- Destinations: 7 of 9 in the `/en/` URL space; `Jobs` → `/empregos/` and `Blog` → `/blog/` are the approved **B1** destinations (the jobs directory is a page with no EN translation — `job` CPT has `has_archive = false`; the Blog archive has no per-language archive — both documented Stage 3.3 policy, consistent with the rest of the EN chrome).
- EN content pages `/en/contact/` and `/en/about-us/`: curated EN menu present, Contact marked current on `/en/contact/`, no page-list, EN switcher.
- All 9 rendered EN destinations return **HTTP 200** (`/en/*`, `/empregos/`, `/blog/`).
- `About Us` is stored (parity with the stored PT "Sobre Nós" item) and removed at render, exactly as PT does.

## 10. Language-switch verification — **PASS**

| Request | Switcher state | Other-language target |
|---|---|---|
| `/` | PT `is-current` + `aria-current="true"` | EN link → `http://localhost:8080/en/` |
| `/contato/` | PT current | EN link → `http://localhost:8080/en/contact/` (real linked translation) |
| `/en/` | EN current | PT link → `http://localhost:8080/` |
| `/en/contact/` | EN current | PT link → `http://localhost:8080/contato/` |

PT → EN and EN → PT both resolve to the counterpart document (not merely the homepage), and switching also switches the navigation (PT menu ↔ EN menu) because the location is re-resolved per language. `/en/` routing is unchanged.

## 11. Mobile verification — **PASS (markup level)**

- EN mobile drawer: `<nav class="mobile-menu-nav">` → `<ul id="menu-main-menu" class="mobile-menu">` with the same **9 EN items and EN destinations** as the desktop nav (one shared `primary` location — no separate/duplicated mobile menu system).
- PT mobile drawer: the 9 canonical PT items.
- No page-list fallback in the drawer in either language.
- Drawer language switcher, drawer "Anuncie Aqui" CTA and drawer search markup unchanged.
- Visual open/close/responsive behaviour: **NOT_TESTABLE** in this environment (no browser/screenshot tooling). No CSS/JS was changed.

## 12. Desktop verification — **PASS (markup level)**

- Logo, logo home link, hamburger toggle, desktop search, mobile-search toggle, theme toggle, language switcher, full + compact "Anuncie Aqui" CTAs all present on `/` and `/en/` (asserted by the HTTP verifier, 18/18 header-action checks).
- `#site-navigation` / `primary-navigation` classes and `#primary-menu` id unchanged; no header redesign; hero and site-wide CSS untouched.
- Visual layout/overflow: **NOT_TESTABLE** (no browser tooling).


## 13. Automated tests

| Test | Covers | How to run |
|---|---|---|
| `wp-content/themes/conexao-br-irlanda/tests/test-nav-menu-regression.php` | A: PT location/menu/Polylang assignment + render-filter presence; C: **EN menu existence, per-language assignment, stored EN content, PT assignment untouched, language-aware helpers**; B: fallback safety (`wp_page_menu` never used, safe fallback renders nothing); F: regression guards | `docker compose exec -T wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-nav-menu-regression.php` |
| `wp-content/themes/conexao-br-irlanda/tests/test-header-menu-selection.php` | **Actual per-language selection**: boots WP under `/` or `/en/`, asserts the resolved `primary` menu id, rendered labels/order, EN URL space, no page-list, active item, no cross-language label leakage | `… php …/test-header-menu-selection.php pt` and `… en` |
| `scripts/nav-regression-http-verify.py` | **Rendered HTTP matrix**: PT home/content, EN home/content pages, canonical orders, no page-list, aria-current, EN destinations, mobile drawer in both languages, 18 header-action checks | `python3 scripts/nav-regression-http-verify.py` |

These assert **actual menu selection behaviour** (which menu term the location resolves to per language, and what the rendered `wp_nav_menu()` output contains) — not string presence in `header.php`.

## 14. Exact test counts / results

| Suite | Result |
|---|---|
| `test-nav-menu-regression.php` | **31 passed, 0 failed** (exit 0) |
| `test-header-menu-selection.php pt` | **12 passed, 0 failed** (exit 0) |
| `test-header-menu-selection.php en` | **14 passed, 0 failed** (exit 0) |
| `scripts/nav-regression-http-verify.py` | **42 passed, 0 failed** (exit 0) |
| **Total** | **99 checks, 0 failures** |

Test-matrix coverage (Phase 9): PT homepage ✓, PT content page (`/contato/`) ✓, EN homepage (`/en/`) ✓, EN content pages (`/en/contact/`, `/en/about-us/`) ✓, PT → EN switch ✓, EN → PT switch ✓; desktop and mobile blocks both asserted at markup level (viewport-specific visual rendering NOT_TESTABLE — no browser tooling).


## 15. Limitations

| Item | Classification |
|---|---|
| Visual layout (nav fit, overflow, CTA position, drawer animation, breakpoints) in both languages | **NOT_TESTABLE** — no browser/screenshot tooling. Markup-level verification only; no CSS/JS changed. |
| `Jobs` → `/empregos/` and `Blog` → `/blog/` from the EN nav | **BY DESIGN / documented B1 policy** — the jobs directory is a page with no published EN translation (`job` CPT `has_archive = false`) and the Blog archive has no per-language archive; both are the approved B1 destinations used across the rest of the EN chrome. Not an invented URL, not a leak of a *translated* record under the wrong language. |
| Production per-language menu assignment | **BLOCKED by policy** — production was not modified. Production needs the same two data steps (create the EN menu in wp-admin → assign under Polylang's `primary` per-language tab), or the script where PHP/WP-CLI is reachable. Production IDs/URLs differ, so the local term ids (1381/2933) must not be copied. |
| English label wording ("Sponsors", "Guides", …) | Stored **data**, editable in wp-admin Appearance → Menus; the render-time layer only enforces the two canonical labels that already had per-language rules (`Home`/`Início`, `Leisure & Tourism`/`Lazer e turismo`). An editorial re-label needs no code change. |
| Polylang's own rel=alternate output vs the theme's `inc/seo.php` hreflang | Pre-existing Stage 2/3.3 item (**out of scope**); untouched by this fix. |
| `job` CPT archive (`has_archive = false`) | Pre-existing data-model design; unchanged. |

## 16. Regression check (git diff review)

Files in the working tree: `functions.php`, `header.php` (earlier fix only), `docs/routing.md`, `docs/themes/conexao-br-irlanda.md` + new `scripts/create-en-primary-menu.php`, `scripts/assign-polylang-nav-menus.php`, `scripts/nav-regression-http-verify.py`, both theme test files, this report.

Confirmed unchanged: no page/CPT/taxonomy URLs or slugs; no redirects; no Polylang routing/language configuration; no CPT or taxonomy registration or behaviour; no SEO/canonical/hreflang/sitemap code; no event/lazer/employment logic; no unrelated i18n behaviour; no CSS/JS; no production data. Every `functions.php` hunk sits inside the nav functions (`conexao_nav_menu_args`, `conexao_modify_primary_nav_items`, `conexao_primary_nav_sections`, `conexao_bind_section_object`, `conexao_normalize_primary_nav_sections`, `conexao_fix_nav_active_states`, `conexao_get_item_section_key`) or adds the safe fallback helper / the new nav URL helper.

## Final acceptance criteria

| Criterion | Status |
|---|---|
| PT header still renders the curated primary menu | **PASS** |
| EN header renders the appropriate English primary menu | **PASS** |
| EN navigation is not empty when a valid EN menu is configured | **PASS** |
| `wp_page_menu()` never used as the primary-navigation fallback | **PASS** (absent from `header.php`; safe fallback asserted) |
| No automatic WordPress page list appears (either language) | **PASS** |
| PT → EN and EN → PT switching works | **PASS** |
| Desktop navigation works in both languages | **PASS** (markup; visual NOT_TESTABLE) |
| Mobile navigation works in both languages | **PASS** (markup; visual NOT_TESTABLE) |
| Theme toggle remains functional | **PASS** (markup present; JS untouched) |
| Search remains functional | **PASS** (desktop + mobile markup present; logic untouched) |
| "Anuncie Aqui" remains functional | **PASS** (full/compact/drawer CTAs present) |
| Existing `/` and `/en/` routing unchanged | **PASS** |
| No hard-coded replacement navigation introduced | **PASS** (menu is DB data through WordPress/Polylang) |
| No unrelated site architecture modified | **PASS** |
| Tests pass | **PASS** (99 checks, 0 failures) |

**Overall status: PASS** — `EN_MENU_STATUS = ASSIGNED_AND_RENDERING` (verified in the rendered `/en/` markup, desktop and mobile drawer).

