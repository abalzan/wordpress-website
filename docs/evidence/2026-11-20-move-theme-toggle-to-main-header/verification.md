# Evidence — Move the light/dark theme toggle from the hamburger menu into the main header

Date: 2026-11-20. Environment: local Docker stack (`wordpress` service on
`http://localhost:8080`, theme bind-mounted from
`wp-content/themes/conexao-br-irlanda`; `db` healthy). Test DB:
`conexao_stage05_test_5bd9ece5c032` (isolated, pre-existing). Baseline commit:
`238c1b74583ea2ba8b3829e1ae367d88f6af05f8`.

## 1. Baseline (pre-change)

Rendered `http://localhost:8080/` (server-rendered HTML, all breakpoints share
the same markup; CSS decides visibility):

| Marker | Byte offset in rendered HTML |
|---|---|
| `mobile-menu-toggle` | 25324 |
| `class="logo"` | 25587 |
| `language-switcher--mobile` | 29907 |
| `header-cta-compact` | 32050, 32425 |
| `id="mobile-menu"` (drawer starts) | 32578 |
| `theme-toggle` occurrences | 33309… (all **inside** the drawer, after `id="mobile-menu"`) |
| `mobile-menu-theme` | 33589 (drawer) |

Source greps (baseline):

- `grep -c 'mobile-menu-theme' header.php` → **1**
- `grep -c 'class="theme-toggle"' header.php` → **1** (in `.mobile-menu-theme`)
- `grep -c 'initThemeToggleRelocation' assets/js/main.js` → **2** (call + definition)
- `grep -Pc '^\s*conexao_language_switcher\(' header.php` → **2**
- `grep -c 'Alternar tema claro/escuro' header.php` → **1**

Pre-fix browser measurement (header.php already moved, CSS narrow rules not yet
added) proved the overflow that motivates the `<=389px` / `<=359px` caps:

| Width | Compact CTA right edge | Viewport | Overflow |
|---|---|---|---|
| 320px | 358 | 320 | **38px past the viewport** |
| 360px | 374 | 360 | **14px past the viewport** |

## 2. After change — static checks

| Command | Result |
|---|---|
| `php -l wp-content/themes/conexao-br-irlanda/header.php` | No syntax errors detected |
| `node --check wp-content/themes/conexao-br-irlanda/assets/js/main.js` | exit 0 (JS syntax OK) |
| `grep -c 'mobile-menu-theme' header.php` | **0** |
| `grep -c 'mobile-menu-theme' assets/css/header-nav.css` | **0** |
| `grep -rc 'mobile-menu-theme' wp-content/themes/conexao-br-irlanda/` | **0** (whole theme tree) |
| `grep -c 'class="theme-toggle"' header.php` | **1** (inside `.header-actions`) |
| `grep -c 'initThemeToggleRelocation' assets/js/main.js` | **0** |
| `grep -Pc '^\s*conexao_language_switcher\(' header.php` | **2** (unchanged; flag gate expects 2) |
| `grep -c 'Alternar tema claro/escuro' header.php` | **1** (string moved verbatim, count unchanged) |

## 3. After change — rendered HTML (`curl http://localhost:8080/`)

| Marker | Byte offset in rendered HTML |
|---|---|
| `mobile-menu-toggle` | 25328 |
| `class="logo"` | 25591 |
| `class="theme-toggle"` | **30182** (in `.header-actions`) |
| `language-switcher--mobile` | 31326 |
| `header-cta-compact` | 33469, 33844 |
| `id="mobile-menu"` (drawer starts) | 33997 |
| `mobile-menu-theme` | **absent** |

Order in the header: hamburger (25328) → logo (25591) → theme toggle (30182) →
PT | EN (31326) → Anuncie Aqui (33469).

Drawer assertions on the rendered HTML:

| Assertion | Expected | Actual |
|---|---|---|
| `theme-toggle` inside `#mobile-menu` | 0 | **0** |
| `mobile-menu-theme` anywhere | 0 | **0** |
| Total `.theme-toggle` in the document | 1 | **1** |
| Drawer search (`.mobile-menu-search`) present | yes | yes |
| Drawer CTA (`.mobile-menu-cta`) present | yes | yes |

## 4. After change — real browser geometry (Playwright / Chromium)

Script: `/tmp/measure_theme_toggle.py` (headless Chromium, real viewport per
width against `http://localhost:8080`). Final result: **PASS — all header
layout + theme-functionality checks passed**.

Per width: all five controls visible, one horizontal line (centres within 6px),
left-to-right order hamburger → logo → theme toggle → PT | EN → CTA, logo
0–20px from the hamburger, right cluster pinned to the container edge, no
pairwise overlap, no horizontal document scroll, theme toggle 44x44, and
desktop-only controls hidden.

| Width | Hamburger | Logo | Theme toggle | PT \| EN | CTA compact |
|---|---|---|---|---|---|
| 320px | 16–64 | 66–122 (56 wide) | 128–172 (44x44) | 174–254 | 256–304 |
| 360px | 16–64 | 72–144 (72 wide) | 156–200 (44x44) | 208–288 | 296–344 |
| 390px | 16–64 | 72–178 (106 wide) | 186–230 (44x44) | 238–318 | 326–374 |
| 430px | 16–64 | 72–178 | 226–270 (44x44) | 278–358 | 366–414 |
| 480px | 16–64 | 72–178 | 276–320 (44x44) | 328–408 | 416–464 |
| 768px | 16–64 | 72–178 | 471–515 (44x44) | 523–603 | 611–752 (141 wide, label) |
| 1024px | 24–72 | 80–150 | 956–1000 (44x44) | 719–799 | 807–948 (141 wide, label) |

Container right edge equals the last control's right edge at every width
(320 → 304, 360 → 344, 390 → 374, 430 → 414, 480 → 464, 768 → 752,
1024 → 1000), so there is no horizontal overflow.

Drawer at 390px (opened via the hamburger):

```
{'open': True, 'toggles': 0, 'themeRows': 0, 'totalToggles': 1, 'search': True, 'cta': True}
```

Desktop 1280px: `.language-switcher--desktop` (955–1031) → full CTA
(1043–1184) → theme toggle (1196–1240); hamburger, mobile switcher and compact
CTA hidden — the desktop actions row keeps the toggle at its end position.

## 5. After change — theme functionality

| Check | Initial | After click | After reload |
|---|---|---|---|
| `data-theme` on `<html>` | light | dark | **dark** (persisted) |
| `aria-pressed` | false | true | — |
| moon icon `display` | flex | none | — |
| sun icon `display` | none | flex | — |
| `localStorage['conexao-theme']` | — | `dark` | — |
| `aria-label` | Alternar tema claro/escuro | unchanged | unchanged |

One worker (`initThemeToggle`) is attached to the single `.theme-toggle` node;
the DOM relocation helper was deleted, so no second handler or clone exists.

## 6. Script contract (`./scripts/run-tests.sh --scripts`)

| Run | Suites | Passed | Failed |
|---|---|---|---|
| Baseline (before edits) | 18 | 16 | 2 |
| After change | 18 | 16 | 2 |

Identical failing set in both runs, both pre-existing and unrelated to this
diff (no file under `scripts/` was touched):

- `tests/scripts/verify-i18n-freshness.py` — `.pot` catalogues older than
  source files (mtime-based; includes the theme catalogue, whose `.pot` was
  already older than `header.php` before this change; no translatable string
  changed — the one `Alternar tema claro/escuro` string moved verbatim).
- `tests/scripts/verify-script-conventions.py` — `scripts/conexao-test-exec.sh`
  not catalogued in `scripts/README.md` (untouched file).

## 7. In-process PHP — theme component (`--php --only theme`)

| Run | Suites | Passed | Failed | Assertions |
|---|---|---|---|---|
| After change | 67 | 51 | 16 | 3839 passed, 200 failed |
| Baseline (changes stashed) | 67 | 51 | 16 | 3839 passed, 200 failed |

### 7.1 Regression proof

The 16 failing suites are **byte-identical** in both runs; the failing-suite
list diff is empty:

```
$ diff <(after failing list | sort) <(baseline failing list | sort)
FAILING LISTS IDENTICAL
```

The baseline run was produced by `git stash push` of the three modified theme
files, re-running the same command against the same isolated DB, then
`git stash pop`. Identical suite totals, identical assertion totals and an
identical failing list mean this change introduces **zero test delta**.

Header-presentation suites (after change):

| Suite | Result |
|---|---|
| `test-header-cta-dark-mode.php` | **31 passed, 0 failed** |
| `test-header-menu-selection.php` (en) | **16 passed, 0 failed** |
| `test-language-switcher-flag.php` | **55 passed, 0 failed** |
| `test-nav-language-context.php` | 16 passed, 0 failed |
| `test-nav-language-context-logic.php` | 49 passed, 0 failed |
| `test-nav-menu-regression.php` | 41 passed, 0 failed |
| `test-ui-convergence-t1.php` / `t2` / `test-ui-polish-p3.php` | 57 / 37 / 13 passed, 0 failed |

The 16 failing suites are content/translation-data suites (Christmas records,
EN translations, Polylang shared-slug pages, leisure/event location fixtures)
whose failures reproduce identically at the baseline — see §7.1.
