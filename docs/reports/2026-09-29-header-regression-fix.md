# Report — Production Header Regression Fix (i18n)

| | |
|---|---|
| **Task** | Fix the production header regression after deploying the `i18n` theme |
| **Date** | 2026-09-29 |
| **Branch** | `i18n` (start/final SHA `33114c6dec671df6d07f097a0bb570454af0a8cd`, unchanged — working-tree fix) |
| **Production writes by this task** | **0** (every production request was a `GET`) |
| **Status** | **PASS WITH LIMITATION** — source fix proven in a real browser; production verification §9 awaits human deployment |

---

## 1. The reported symptom was not the real defect

The brief stated the header was "no longer visually appearing" and warned not to
assume `header.php` needed the markup back. Following that instruction literally is
what found the truth:

**The header markup was never missing, and the header was never hidden.**

Measured against live `https://conexaobr.ie/` with a real headless browser
(Playwright/Chromium 1.63), computed styles at eight widths:

| Width | `#masthead` | height | visibility | opacity | logo | nav | overflow-X |
|---|---|---|---|---|---|---|---|
| 320 | `block` | 61px | `visible` | `1` | 78×29, loaded | hidden (by design) | 0 |
| 360 | `block` | 61px | `visible` | `1` | 96×36, loaded | hidden | 0 |
| 390 | `block` | 61px | `visible` | `1` | 96×36, loaded | hidden | 0 |
| 480 | `block` | 61px | `visible` | `1` | 96×36, loaded | hidden | 0 |
| 768 | `block` | 61px | `visible` | `1` | 96×36, loaded | hidden | 0 |
| 1024 | `block` | 65px | `visible` | `1` | 60×40, loaded | hidden | 0 |
| 1280 | `block` | 81px | `visible` | `1` | 72×48, loaded | `flex` | 0 |
| 1440 | `block` | 81px | `visible` | `1` | 72×48, loaded | `flex` | 0 |

A production screenshot shows logo, theme toggle, search and the "Anuncie Aqui" CTA
rendering normally — with a conspicuous **empty white band** where the primary
navigation belongs. That band is the actual regression, and it is what made the
header read as "broken" to a human.

The layers §3 of the brief asks to test independently were each measured and are all
healthy: no `display:none`/`visibility:hidden`/`opacity:0`/zero-height on
`.site-header`; `.main-header .site-container` resolves to 1200px at desktop; logo
loads (`naturalWidth` 72/96) in both light and dark; `.header-actions` fits; all four
stylesheets are requested and return 200; **zero console errors and zero failed
requests**; no JS touches `.site-header`; no duplicate markup; no load-order fault.

`header-nav.css`, `design-system.css`, `main.css` and `dark-mode.css` are all
unmodified by this fix — the asset chain and the design tokens were never the problem.

## 2. Root cause

The **primary navigation rendered nothing**, and the theme's fallback was hard-coded
to render nothing.

`wp-content/themes/conexao-br-irlanda/inc/navigation.php` → `conexao_safe_nav_menu_fallback()`:

```php
function conexao_safe_nav_menu_fallback( $args = array() ) {
	// Intentionally empty: render no primary navigation items.
}
```

The mechanism, confirmed in WordPress core (`wp-includes/nav-menu-template.php:167`):

```php
if ( ( ! $menu || is_wp_error( $menu ) || ( isset( $menu_items ) && empty( $menu_items ) && ! $args->theme_location ) )
	&& isset( $args->fallback_cb ) && $args->fallback_cb && is_callable( $args->fallback_cb ) ) {
	return call_user_func( $args->fallback_cb, (array) $args );
}
```

Both header calls pass `theme_location => 'primary'`. So when no menu is found the
fallback **is** invoked — and it emitted nothing, producing exactly the production
markup from the brief:

```html
<nav id="site-navigation" class="primary-navigation" aria-label="Menu Principal">
</nav>
```

**Why no menu is found in production.** `PLL_Frontend_Nav_Menu::nav_menu_locations()`
(polylang `src/frontend/frontend-nav-menu.php:238`, filter
`theme_mod_nav_menu_locations`, priority 20) *unconditionally* overwrites every
registered location:

```php
$menus[ $loc ] = empty( $this->options['nav_menus'][ $theme ][ $loc ][ $this->curlang->slug ] )
	? 0 : $this->options['nav_menus'][ $theme ][ $loc ][ $this->curlang->slug ];
```

Production's Polylang `nav_menus` option is empty (confirmed read-only via
`GET /wp-json/pll/v1/settings`), so `primary` is nulled to `0` **for every language,
including Portuguese** → `wp_nav_menu('primary')` finds no menu → the empty fallback
runs → empty `<nav>`.

Local has the per-language assignment and is therefore unaffected:
`nav_menus = {"conexao-br-irlanda":{"primary":{"pt":69,"en":333}}}`.

**This is a data gap plus a missing safety net.** The correct *data* fix is the
documented `scripts/assign-polylang-nav-menus.php` (§
`CONEXAO_BR_HEADER_NAVIGATION_REGRESSION_REPORT.md`), but that is a **production
write** this task is forbidden to perform. The safe source-level correction is to make
the header degrade gracefully when the location cannot be resolved — which is exactly
what this fix does.

## 3. Reproduction (production state, locally)

The production state was reproduced locally with a temporary, read-only mu-plugin
nullifying `theme_mod_nav_menu_locations.primary` to `0`, then **removed**:

- **Before the fix** → `<nav id="site-navigation">` empty; desktop nav 0×0px; 0 items.
- **After the fix** → 9 items, `Início … Contato`, desktop nav 48px tall at 1280px.

## 4. Fix

One source file changed, plus its tests and the two docs that described the old behaviour.

**`wp-content/themes/conexao-br-irlanda/inc/navigation.php`**

1. `conexao_canonical_primary_nav_order()` *(new)* — the canonical nine-section order
   in one place, language-aware labels.
2. `conexao_canonical_primary_nav_items()` *(new)* — builds those items from the
   **existing** `conexao_primary_nav_sections()` engine, binding each through the
   **existing** `conexao_bind_section_object()`. Pure and idempotent; writes nothing.
3. `conexao_safe_nav_menu_fallback()` *(changed)* — now renders those items through
   the **existing** `conexao_normalize_primary_nav_sections()` shaping pass and
   WordPress' own `walk_nav_menu_tree()`, scoped strictly to
   `theme_location === 'primary'`.

Design constraints honoured:

- **No hard-coded menu.** Destinations still come from `conexao_primary_nav_sections()`,
  so URLs stay language-aware and no URL/slug/route is hard-coded.
- **No second source of truth.** The order is defined once and the fallback reuses the
  same engine and the same filters the stored-menu path uses.
- **Never overrides a real menu** — `wp_nav_menu()` only reaches the fallback when no
  menu was found at all.
- **Never `wp_page_menu`** — the original regression (WordPress' full page list
  overflowing the header) stays fixed; asserted by test B4i/B4j.
- No CSS added, no JavaScript added, no `header.php` change, no i18n change, no
  menu data touched.

Also updated: `tests/test-nav-menu-regression.php` (B4a–B5), and the stale
"safe **empty** fallback" wording in `test-nav-language-context.php` and
`test-nav-language-context-logic.php`; `docs/routing.md` and
`docs/themes/conexao-br-irlanda.md` (which also pointed at `functions.php`, now a
loader only).

## 5. Header verification (real browser, reproduced production state)

| Check | 320 | 360 | 390 | 480 | 768 | 1024 | 1280 | 1440 |
|---|---|---|---|---|---|---|---|---|
| header visible, non-zero height | 61 | 61 | 61 | 61 | 61 | 65 | 81 | 81 |
| logo visible + loaded | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| hamburger (mobile/tablet) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — |
| mobile search toggle | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| desktop nav (≥1025) | — | — | — | — | — | — | ✅ | ✅ |
| compact CTA (≤1024) | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — | — |
| full CTA (≥1025) | — | — | — | — | — | — | ✅ | ✅ |
| horizontal overflow | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |

Dark/light: logo swaps correctly (`--light` hidden / `--dark` shown and vice versa),
header 81px in both. Sticky `position:sticky`, `top:0px`, `z-index:1000` — no
unexpected offset.

## 6. Navigation verification

Expected canonical order vs actual rendered:

`Início, Apoiadores, Guias, Eventos, Cursos, Lazer e turismo, Empregos, Blog, Contato`
→ **identical, 9/9**, both in the fallback path and with the real stored menu.

| Destination | PT (fallback) | EN (fallback) |
|---|---|---|
| Início / Home | `/` | `/` |
| Apoiadores / Sponsors | `/apoiadores/` | `/apoiadores/` |
| Guias / Guides | `/guias/` | `/guias/` |
| Eventos / Events | `/eventos/` | `/eventos/` |
| Cursos / Courses | `/cursos/` | `/cursos/` |
| Lazer e turismo / Leisure & Tourism | `/lazer/` | `/lazer/` |
| Empregos / Jobs | `/empregos/` | `/empregos/` |
| Blog | `/blog/` | `/blog/` |
| Contato / Contact | `/contato/` | `/contato/` |

The **stored production menu was not modified** and no menu data was written.
`nav-item`/`nav-link` classes, `aria-current="page"` on Início and section dedup all
behave identically to the stored-menu path.

## 7. Accessibility

Verified via computed ARIA in the browser: `<nav>` keeps `aria-label` ("Menu Principal"
/ "Main Menu"); the hamburger has an accessible name (`Abrir menu`) and `aria-expanded`
flips `false → true` on open; drawer `aria-hidden` flips `true → false`; theme toggle,
both search controls and both CTAs keep accessible names; `aria-current="page"` is
present on the current item. **No duplicate element IDs** (checked explicitly). The
mobile drawer opens and closes correctly. No new ARIA errors.

## 8. Regression — before/after

Baseline captured **before** any edit, then re-run after.

| Gate | Before | After | Delta |
|---|---|---|---|
| In-process PHP suites | 62 total, 52 passed, **10 failed** | 62 total, 52 passed, **10 failed** | none |
| Assertions | 2910 passed, 2 failed | **2920** passed, 2 failed | **+10** (new B4a–B5) |
| Script-contract suites | 6 total, 5 passed, 1 failed | 6 total, 5 passed, 1 failed | none |
| HTTP acceptance suites | 3 total, **3 passed, 0 failed** | 3 total, **3 passed, 0 failed** | none |
| `./scripts/lint.sh` | — | **OK** (syntax clean, no new PHPCS violations, PHPStan clean) | — |
| Registry drift gate | — | **OK** — 14 plugins, 23 regions, zero writes | — |
| Permanent gates (Stage L) | — | 7 total, 6 passed, 1 failed | see below |

**New failures introduced by this fix: 0.** The 11 failing suite names are
byte-identical before and after (`diff` of the two `[FAIL]` sets is empty). They are
pre-existing EN-content/fixture suites unrelated to the header. No assertion was
weakened; the one assertion that encoded the bug (`B4 … renders no markup`) was
*replaced by ten stronger* ones.

Two pre-existing failures were individually proven pre-existing by re-running with my
change stashed:

- **`i18n_freshness`** (2 stale `.pot` catalogues) — `conexao-content` and
  `conexao-event-runtime`, both **plugin** files I never touched; the gate compares
  file mtimes. Stashing `navigation.php` reproduces the identical failure. The theme's
  own catalogue is **fresh** (`conexao-br-irlanda … fresh`).
- **`scripts/nav-regression-http-verify.py`** (58 passed / 5 failed) — not part of
  `run-tests.sh`; its 5 failures are `language switcher present` checks that contradict
  this task's explicit requirement that the switcher stay **disabled**. Identical
  result with my change stashed.

## 9. Production

- **State now:** header renders; **primary navigation empty** (the defect above).
  `GET` only. Nothing written — no menu, option, theme mod, Polylang setting or plugin
  state touched.
- **Deployment is a separate human-authorized step and was NOT performed here.**
- **Verified artifact:** `dist/conexao-br-irlanda.zip`, 115 files,
  `sha256 34068f3984ef2c4b7b78795b86fdaf0d681e056ac0f8cc194b5b739a0c645481`;
  `release-manifest.py --verify` → `release=v2026.09.29 artifacts=8 built=8`.
  No `.env`, secret, test, fixture or dev-only file leakage; the fixed
  `inc/navigation.php` is present in the ZIP. Asset fingerprints: `header-nav.css`
  `ad13dcbf532b05f3`, `design-system.css` `5189e17f305b6f26`, `main.css`
  `17d342cff3b8306b`, `main.js` `1114b175ed515b88` (all unchanged by this fix).
- **After the human deploy, verify read-only:** production `inc/navigation.php`
  carries `conexao_canonical_primary_nav_items`; the deployed theme asset fingerprint
  matches the built artifact; `#primary-menu` contains the nine canonical items at
  ≥1025px; logo/actions load; the language switcher remains hidden; Polylang state and
  PT content unchanged.

**Not yet done, by design:** the artifact has not been deployed, so the deployed
fingerprint cannot be compared to the tested build. This is why the status is
**PASS WITH LIMITATION**, not an unconditional pass.

## 10. Preserved (explicitly verified)

Polylang, `conexao_language`, language routing, PT/EN locale handling, hreflang, B2
fallback and the disabled language switcher are all untouched —
`CONEXAO_LANGUAGE_SWITCHER_ENABLED` is still `false` in an **unmodified**
`functions.php`, and `.language-switcher` occurs **0** times in the rendered page.
The header design, responsive breakpoints, menu ordering and PT/EN URLs are unchanged.

## 11. Working tree

Only the documented files changed: `inc/navigation.php` (fix), 3 theme test files
(assertions), `docs/routing.md` + `docs/themes/conexao-br-irlanda.md` (behaviour
docs), this report, and the regenerated `docs/evidence/2026-09-26-stage-l/gate.json`.
Pre-existing untracked report/evidence files from earlier sessions are untouched.
No commit was made.

## 12. Final status

**PASS WITH LIMITATION.** The regression is root-caused to a single function, fixed at
source, and verified in a real browser at every required breakpoint with zero new
gate failures. The limitation is that the verified artifact has not yet been deployed
by a human, so the production fingerprint comparison in §9 is still outstanding. A
plain `PASS` would require that comparison, and claiming one now would be unverified.
