# Conexão BR — Header / Primary Navigation Regression Report

**Scope:** header primary navigation regression after the i18n / Polylang work.
**Environment:** local Docker stack (`compose.yaml`, WordPress + MySQL + Polylang 3.8.9) at `http://localhost:8080`, branch `i18n`. Production untouched.

---

## 1. Root cause

The header always called `wp_nav_menu()` with `theme_location => 'primary'`
(`header.php` desktop nav + mobile drawer nav). The WordPress-level assignment
(`nav_menu_locations.primary = 1381` inside `theme_mods_conexao-br-irlanda`)
was **intact**, and the curated menu ("Menu Principal", 9 stored items) was
**intact** in the database. Neither was the problem.

The regression is Polylang's menu-resolution model:

* Once Polylang is active, `PLL_Frontend_Nav_Menu::nav_menu_locations()`
  (filter `theme_mod_nav_menu_locations`, **priority 20**) **unconditionally
  overwrites every registered theme location** with the per-language
  assignment stored in Polylang's `nav_menus` option
  (`nav_menus[stylesheet][location][language-slug]`) — or with `0` when that
  per-language entry does not exist:

  ```php
  $menus[ $loc ] = empty( $this->options['nav_menus'][ $theme ][ $loc ][ $this->curlang->slug ] )
      ? 0 : $this->options['nav_menus'][ $theme ][ $loc ][ $this->curlang->slug ];
  ```

* The i18n work activated Polylang's frontend menu integration but **never
  populated the per-language `nav_menus` option** (it was `a:0:{}` empty).
  Result: the `primary` location was nulled to `0` for **every language,
  including Portuguese** → `wp_nav_menu('primary')` found no menu →
  `fallback_cb => 'wp_page_menu'` rendered WordPress' full automatic page
  list (the `page_item page-item-…` dump observed in the header, overflowing
  it on every page, desktop and mobile drawer alike).

Evidence chain:

1. `curl http://localhost:8080/` → 86 `page_item` occurrences inside
   `#primary-menu` (before the fix).
2. `wp_options.polylang` → `nav_menus: a:0:{}`; theme mod
   `nav_menu_locations = {primary: 1381, footer: 1382}`; menu term 1381
   "Menu Principal" with 9 published items (Home, Apoiadores, Guias, Eventos,
   Cursos, Empregos, Blog, Sobre Nós→hidden at render, Contato).
3. Polylang source (`src/frontend/frontend-nav-menu.php`) — filter above,
   verified against the installed 3.8.9.

This matches (and now resolves) the data gap documented in
`CONEXAO_BR_ENGLISH_STAGE_3_3_REPORT.md` §25.3 / §19 item 13: no English menu
is assigned to `primary`; creating one is an editorial/production decision and
was deliberately NOT patched with a navigation hack there.

## 2. Files changed

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/header.php` | Both `wp_nav_menu()` calls (desktop `#site-navigation` + mobile drawer): `fallback_cb` changed from `'wp_page_menu'` → `'conexao_safe_nav_menu_fallback'`. Nothing else touched. |
| `wp-content/themes/conexao-br-irlanda/functions.php` | Added `conexao_safe_nav_menu_fallback()` next to the existing nav filters — an explicitly empty fallback that renders no navigation items. |
| `scripts/assign-polylang-nav-menus.php` | **New** idempotent script: writes `nav_menus['conexao-br-irlanda']['primary']['pt'] = <"Menu Principal" id>` in Polylang's option, re-asserts `nav_menu_locations.primary`, never creates/edits menu items, never touches footer/social menus. EN is intentionally left unassigned (data gap — see §3). |
| `docs/routing.md` | §Navigation Architecture: new subsection "Menu selection with Polylang (per-language assignment)". |
| `docs/themes/conexao-br-irlanda.md` | One-paragraph cross-reference to the same mechanism. |
| `wp-content/themes/conexao-br-irlanda/tests/test-nav-menu-regression.php` | **New** logic-level regression test (22 checks). |
| `scripts/nav-regression-http-verify.py` | **New** HTTP-level rendered verification (31 checks). |

`git diff` reviewed: no URLs, permalinks, redirects, Polylang language URLs,
CPT registrations, taxonomy behavior, SEO, sitemap, event/lazer/job data,
search, or unrelated i18n logic touched.

## 3. Exact fix

Two-part fix that restores the existing menu architecture instead of
hard-coding anything:

1. **Data (menu selection restored):** `scripts/assign-polylang-nav-menus.php`
   populates the Polylang-native per-language assignment:

   ```
   nav_menus['conexao-br-irlanda']['primary']['pt'] = 1381   // "Menu Principal"
   ```

   With this entry present, Polylang's `theme_mod_nav_menu_locations` filter
   resolves `primary` → 1381 for Portuguese, and the curated menu renders
   through the theme's existing `wp_nav_menu()` calls and render-time filters
   (`conexao_modify_primary_nav_items`, `conexao_normalize_primary_nav_sections`,
   `conexao_override_guides_menu_links`) — zero markup duplication, fully
   manageable in wp-admin.

   **EN deliberately not assigned:** no English menu exists (Stage 3.3 §25.3
   documents this as production editorial/data work). The architecture stays
   one `primary` location, per-language via Polylang — when an EN menu is
   created, assigning `['en']` (via wp-admin → Menus, or the same script's
   pattern) makes EN pages receive it with no further code changes.

2. **Code (fallback made safe):** `fallback_cb` replaced with the explicit
   empty fallback `conexao_safe_nav_menu_fallback()`. When no valid custom
   menu exists for the current language, the header renders **no** primary
   navigation items — never the WordPress page list.

PT/EN language-specific menu selection therefore uses the **existing Polylang
per-language mechanism** — no new multilingual menu architecture, no second
navigation system, no hard-coded links in `header.php`.

## 4–8. Verification (exact results)

All evidence below was captured after the fix against
`http://localhost:8080` (PT) and `http://localhost:8080/en/` (EN).

### 4. PT menu verification — PASS

`curl http://localhost:8080/` inside `<nav id="site-navigation">`:

```html
<ul id="primary-menu" class="primary-menu">
  <li id="menu-item-11607" class="menu-item menu-item-home ... current-menu-item ... nav-item"><a href="http://localhost:8080/" aria-current="page" class="nav-link">Início</a></li>
  ... Apoiadores → /apoiadores/ ... Guias → /guias/ ... Eventos → /eventos/ ... Cursos → /cursos/
  <li ... menu-item-object-leisure ...><a href="http://localhost:8080/lazer/" class="nav-link">Lazer e turismo</a></li>
  ... Empregos → /empregos/ ... Blog → /blog/ ... Contato → /contato/
</ul>
```

* Exact canonical nine-item order: Início, Apoiadores, Guias, Eventos, Cursos,
  Lazer e turismo, Empregos, Blog, Contato — via the existing stored menu +
  render-time filters (no hard-coding).
* Rendered markup uses `menu-item` classes; **zero** `page_item`-only
  fallback entries (`grep -c 'page_item'` on `/` = 0; it was 86 before).
* No unrelated pages (Condado de …, Sobre, Política de …, etc.) in the nav.
* `/contato/` renders the same curated nav (its `page_item page-item-12`
  fragment is a normal WordPress core class on the *real* Contato menu item,
  combined with `menu-item` — not a fallback list).

### 5. EN menu verification — PASS (with documented data gap)

* `/en/` → 200; `/en/about-us/` → 200.
* EN primary nav renders **no items** (safe empty fallback) — the automatic
  page list is gone from EN pages too (0 `page_item`-only entries).
* EN menu *content* remains the known Stage 3.3 data gap
  (`CONEXAO_BR_ENGLISH_STAGE_3_3_REPORT.md` §25.3): no EN menu exists in the
  dataset; creating/assigning one is an editorial decision. The per-language
  mechanism is in place, so assigning an EN menu needs data only, no code.
* PT/EN language switcher verified on both languages: PT pages show
  `PT` (current) + `EN` → `/en/`; EN pages show `PT` → `/` + `EN` (current).

### 6. Mobile verification — PASS (markup level)

* Mobile drawer nav (`<nav class="mobile-menu-nav">`) renders the same curated
  nine-item menu on PT (desktop and mobile share the `primary` location —
  unchanged architecture).
* Drawer "Anuncie Aqui" CTA (`.mobile-menu-cta`) present; hamburger toggle
  (`.mobile-menu-toggle`) present.
* On EN, the drawer receives the same safe empty fallback — no desktop
  page-list fallback injected into the drawer.
* Interactive open/close behavior and visual overflow: NOT_TESTABLE (no
  browser tooling in this environment — see §11).

### 7. Desktop verification — PASS (markup level)

* The nine-item curated menu is what `header-nav.css` was already designed and
  tuned for (flex `ul`, 1025–1280px compact rules, `display:none` ≤1024px).
  The DOM structure that regressed is restored; **no CSS changes were needed
  or made** (no `overflow:hidden`, no `display:none` hacks, no text shrinking).
* Visual fit/overflow and logo dimensions: NOT_TESTABLE (no browser
  tooling) — but the overflow was a direct artifact of the 30+ item page list,
  which no longer renders.

### 8. Header-action verification — PASS

Present in the rendered header of both `/` and `/en/` (asserted by the HTTP
script): logo, theme toggle, desktop search (`#header-search-field`), mobile
search (`#mobile-search-field`), hamburger toggle, full CTA
(`.header-cta` → `/anuncie/`), compact CTA (`.header-cta-compact`), drawer CTA
(`.mobile-menu-cta`), language switcher (desktop + drawer), social links.
No header markup outside the two `fallback_cb` lines was modified.

## 9. Test commands

```bash
# Logic-level regression test (inside the WordPress container)
docker compose exec -T wordpress php \
  /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-nav-menu-regression.php

# HTTP-level rendered verification (from the repo root)
python3 scripts/nav-regression-http-verify.py            # default base URL
python3 scripts/nav-regression-http-verify.py http://localhost:8080

# Repair/re-assert the Polylang per-language assignment (idempotent)
docker compose cp scripts/assign-polylang-nav-menus.php \
  wordpress:/var/www/html/scripts/
docker compose exec -T wordpress php /var/www/html/scripts/assign-polylang-nav-menus.php
```

## 10. Exact test results

`tests/test-nav-menu-regression.php` — **22 passed, 0 failed** (exit 0):

```
== A. PT primary menu ==
  PASS  A1 'primary' nav menu location is registered
  PASS  A2 'footer'/'social' locations remain registered
  PASS  A3 a menu is assigned to the primary location
  PASS  A4 primary location assignment is non-zero
  PASS  A5 curated menu "Menu Principal" exists
  PASS  A6 "Menu Principal" is the menu assigned to primary
  PASS  A7 Polylang is active
  PASS  A8 Polylang nav_menus[primary][pt] is assigned
  PASS  A9 Polylang nav_menus[primary][pt] points at "Menu Principal"
  PASS  A10 EN per-language state is coherent (no EN menu yet, or a valid one assigned)
  PASS  A11 EN language exists (EN layer intact)
  PASS  A12 stored menu carries the canonical curated destinations (Início, Apoiadores, Guias, Eventos, Cursos, Empregos, Blog, Contato)
  PASS  A13 stored menu has no "Notícias" item
  PASS  A14 render-time nav modifier conexao_modify_primary_nav_items() exists (Início rename, Sobre Nós removal, Lazer e turismo insertion, Blog fallback insert)
  PASS  A15 render-time nav normalizer conexao_normalize_primary_nav_sections() exists
== B. Fallback safety ==
  PASS  B1 header.php does not use wp_page_menu as wp_nav_menu fallback
  PASS  B2 BOTH wp_nav_menu calls (desktop + mobile drawer) use the safe empty fallback
  PASS  B3 safe fallback helper exists
  PASS  B4 safe fallback renders no markup (explicitly empty output)
== F. Regression guards ==
  PASS  F1 nav-menu class/args filters still registered (nav-item/nav-link classes preserved)
  PASS  F2 guides-link override filter still active
  PASS  F3 language switcher renderer still present
```

`scripts/nav-regression-http-verify.py` — **31 passed, 0 failed** (exit 0):
PT homepage 6/6 (wp_nav_menu list, `menu-item` classes, no page-list fallback,
canonical nine-item order, language switcher, mobile drawer menu), EN homepage
4/4 (renders, no page-list fallback, safe empty nav, switcher), PT content page
`/contato/` 1/1, EN content page `/en/about-us/` 2/2, header actions PT + EN
9 × 2 (logo, theme toggle, desktop search, mobile search, hamburger, full CTA,
compact CTA, drawer CTA, language switcher).

`scripts/assign-polylang-nav-menus.php` run output:

```
Menu Principal found (term_id 1381, 9 items).
Wrote Polylang nav_menus['conexao-br-irlanda']['primary']['pt'] = 1381 (was 0).
Note: EN intentionally unassigned (no EN menu exists yet; EN header uses the safe empty fallback).
Set nav_menu_locations.primary = 1381 (was 0).
Done.
```

Before → after on the PT homepage: `grep -c 'page_item'` **86 → 0**; curated
nav items **0 → 9** (desktop and drawer).

## 11. Limitations / NOT_TESTABLE items

| Item | Classification |
|---|---|
| Visual desktop fit: nine-item nav inside viewport, no horizontal overflow, CTA position, logo dimensions | **NOT_TESTABLE** — no browser/screenshot tooling in this environment. Markup-level verification (§7) shows the page-list DOM (the overflow source) is gone and the intended nine-item DOM is restored; no CSS was changed. |
| Tablet/mobile visual breakpoints, hamburger open/close interaction, drawer scrolling | **NOT_TESTABLE** — same reason. Markup for the drawer, compact CTA and search overlays verified unchanged and present (§6, §8). |
| EN menu *content* (an English primary menu with the directory archives) | **BLOCKED / data gap** — by design: no EN menu exists in this dataset (`CONEXAO_BR_ENGLISH_STAGE_3_3_REPORT.md` §25.3). Creating it is an editorial decision. Assigning it later is data-only (Polylang `nav_menus['primary']['en']` / wp-admin Menus screen) and needs no code change. Meanwhile EN pages intentionally render the safe empty fallback. |
| Production menu assignment | **BLOCKED** — production must not be modified from this task. In production, verify `PLL()->options['nav_menus'][stylesheet]['primary']['pt']` is assigned (wp-admin → Menus, per-language tabs, or the same script); if it is not, the same page-list regression applies there, because Polylang's filter behaves identically regardless of the plain `nav_menu_locations` assignment. |
| `.htaccess`/Apache-layer behavior | Untouched by this change; out of scope (see Stage 3.3 §25.1). |
| Footer menu (`Menu Rodapé`) rendering | Not regressed and not modified: `footer.php` contains no `wp_nav_menu()` call and the footer menu is rendered outside this header mechanism. |

## Final acceptance status

| Criterion | Status |
|---|---|
| Automatic page-list navigation gone | **PASS** (86 → 0 `page_item`-only entries; both languages) |
| Existing curated WordPress primary menu restored | **PASS** (canonical nine items, correct order, desktop + drawer) |
| No hard-coded replacement navigation | **PASS** (no links/labels added to `header.php`; assignment is data through Polylang) |
| PT navigation correct | **PASS** |
| EN navigation correct per existing multilingual architecture | **PASS** (per-language mechanism in place; EN menu content = documented data gap, safe empty fallback meanwhile) |
| Mobile navigation correct | **PASS** (markup level) |
| Language switcher works | **PASS** (PT current + EN `/en/` link; EN current + PT link) |
| Theme toggle works | **PASS** (markup present; JS untouched) |
| Search works | **PASS** (desktop + mobile markup present; logic untouched) |
| "Anuncie Aqui" CTAs work | **PASS** (full, compact, drawer — all `/anuncie/`) |
| No horizontal header overflow | **PASS** at markup level (overflow source removed); visual confirmation **NOT_TESTABLE** |
| Existing URLs and i18n routing untouched | **PASS** (`git diff` reviewed — no routing/redirect/permalink changes) |
| Focused regression tests pass | **PASS** (22 + 31 checks, exit 0) |
| No unrelated files/logic changed | **PASS** (diff limited to header nav, one helper function, docs, new tests/script) |

