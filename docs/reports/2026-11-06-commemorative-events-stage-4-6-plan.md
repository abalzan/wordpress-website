# Plan — Commemorative Events architecture r10, Stages 4–6

| | |
|---|---|
| **Task title** | Implement r10 Stages 4–6: experience-package framework + Halloween multi-placement experience, the second package + dedicated landing page, and full verification/a11y/gates/documentation |
| **Date** | 2026-11-06 |
| **Author / agent** | Cline (implementation) |
| **Branch** | `commemorative-events` |
| **Starting SHA** | `cfea1faffbd38bd575128b03eb33af20f0fca641` (dirty worktree: `docs/evidence/2026-11-06-commemorative-events/php-suites.txt`) |
| **Stashes preserved** | 9 (`stash@{0}` … `stash@{8}`) — untouched |
| **Authoritative specification** | [`2026-11-03-commemorative-events-architecture-proposal-r10-revised.md`](2026-11-03-commemorative-events-architecture-proposal-r10-revised.md) |
| **What Stage 0–3 built** | [`2026-11-06-commemorative-events-stage-0-3-implementation.md`](2026-11-06-commemorative-events-stage-0-3-implementation.md) |
| **Applicable skills** | `wp-add-content-type`, `wp-frontend-perf`, `wp-security-review`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-update-docs` |
| **Plan status** | approved (r10 is the approved specification) |

## 1. Verified starting state (measured, not assumed)

| Fact | Measured value | Command / evidence |
|---|---|---|
| Branch / HEAD | `commemorative-events` / `cfea1fa…` | `git branch --show-current`, `git rev-parse HEAD` |
| Worktree | 1 modified evidence file, 0 untracked before this task | `git status --short` |
| Stashes | 9, unchanged | `git stash list` |
| Commemorative engine suites | **6 suites, 209 assertions, 0 failures** (admin 41, cache/boundary 40, migration 25, preview 20, resolution 27, schedule 56) | `baseline-commemorative-suites.txt` |
| Theme PHP layer (baseline) | **53 suites: 42 pass / 11 fail; 3300 assertions pass / 42 fail** | `baseline-theme-php-suites.txt` |
| Halloween nav suite | **102 pass / 5 fail** — 4 are the regressed cadence (`5000/10000`, `10000/15000` vs the approved `20000/30000`, `25000/35000`), 1 is the date-dependent `is_active()` assertion | baseline log + direct suite run |
| `commemorative_event` records | **12** (6 PT canonical + 6 EN), keys `christmas, easter, halloween, new_year, st_patricks_day, valentines_day`; EN records carry `_ce_event_key` only | `baseline-content-inventory.txt` |
| Registered packages | 6 keys, **labels only** (`placements=[]`) | `baseline-content-inventory.txt` |
| Registry / script-contract / docs-drift gates | green | `baseline-registry-check.txt`, `baseline-script-conventions.txt`, `baseline-documentation-drift.txt` |
| Permanent gates | 7 gates: 5 pass, 2 fail (`translation_completeness` 2 new data violations, `i18n_freshness` 2 pre-existing) | `baseline-permanent-gates.txt` |

The Stage 0–3 report and the code agree on every point above. One naming
difference is preserved deliberately: the implementation's package keys are
`st_patricks_day` / `valentines_day` (r10 prose says `st_patricks` /
`valentines`). The stored `_ce_presentation` values use the implemented keys, so
renaming them would be a silent data change — the keys stay.

### 1.1 Content decision for Stage 5 (measured, not assumed)

`baseline-christmas-content.txt` shows:

- **Christmas: 38 event records** matching christmas/winter, of which 25 carry a
  Christmas-specific title and 20 fall inside December 2026
  (`vira-christmas-glow`, `creating-a-christmas-wreath-workshop-with-vivienne-brophy`,
  `christmas-walk-train-ride-with-santa-for-over-50s`, `singles-christmas-party`,
  `2026-solstice-newgrange-circle-of-peace-and-overnight-dowth`, …).
- **A real place record**: `newgrange` (winter-solstice place, leisure CPT).
- **A real guide**: `inverno-irlanda-depressao-sazonal-saude-mental` (winter wellbeing).
- **Real articles**: `coragem-a-estrela-que-ilumina-o-caminho-do-empreendedor-especial-de-natal`,
  `o-calor-que-nasce-do-inverno`, `preparando-o-corpo-para-o-inverno`.
- **New Year: 0 records** matching `new year` / `ano novo` / `réveillon` in any
  content type.

**Decision: `christmas`.** New Year cannot satisfy the r10 "at least three real
modules" rule without inventing content, so it is documented as a planned
editorial brief rather than shipped. `/natal/` and `/en/christmas/` are free
(no page, no redirect, no reserved slug).


## 2. Scope

### Stage 4 — framework + Halloween multi-placement

1. **One** theme-owned registry *and* dispatcher, extending the existing
   `inc/commemorative-presentations.php` (the r10 suggested singular file name is
   not adopted: a second file would be a competing registry).
2. Placement catalogue: `homepage_hero`, `site_wide_ambient` (Stage 4) and
   `landing_page` (Stage 5). No `combined` placement.
3. Shared components: hero shell, ambient layer, token contract, dismissal/focus
   and reduced-motion helpers, one ambient scheduler.
4. Halloween migrated to a package with **both** placements; global
   `main.css` / `dark-mode.css` / `main.js` lose their Halloween blocks; art moves
   to `assets/images/presentations/halloween/`.
5. **Approved cadence restored**: `20000–30000 ms` first, `25000–35000 ms`
   subsequent (r10 §7.7 exception, §9.9).
6. Conditional asset loading with explicit dependencies; zero commemorative
   bytes with no active event.
7. Preview extended to internal pages through the existing signed path.

### Stage 5 — second package + landing page

1. `christmas` becomes a complete package: own motif (one bauble ornament), own
   palette/tokens, own motion language (`calm`), own copy voice, own do-not list,
   own placement map (`homepage_hero`, `site_wide_ambient`, `landing_page`).
2. Landing page `/natal/` + `/en/christmas/` as **normal pages** on the existing
   assigned-template mechanism (`Template Name:` in
   `page-commemorative-landing.php`), with ≥3 real modules reusing existing
   records (events, guide, place, articles).
3. Landing page: canonical/hreflang/status verified; **excluded from the sitemap**
   by default; not unpublished after the window; not a modal.
4. No engine change and no Halloween-specific change is required by Stage 5.

### Stage 6 — verification, a11y, gates, docs

Six new fail-closed permanent gates with injected negative proofs; accessibility
and visual review with real browser evidence; `docs/commemorative-events.md`,
`docs/commemorative-content.md`, index and plugin-doc updates; the content
calendar and manual overlap watchlist; full test/lint/gate rerun.

## 3. Explicit non-goals

- No new plugin, table, dashboard, collision algorithm, Carnival/Easter offset,
  public override or concurrency.
- The CPT stays non-public: no route, archive, query var, REST exposure, sitemap.
- No engine behaviour change. The only non-presentation edit is an **additive,
  optional clock parameter** on the legacy theme `is_active()` so the Halloween
  regression suite becomes date-deterministic (r10 §9.1 names this defect); it is
  behaviour-preserving for every existing caller and covered by the existing
  `test-seasonal-occasion.php`.

## 4. r10 acceptance criterion → implementation map

| r10 criterion | Where it lives | Proof |
|---|---|---|
| §7.4 theme registry, plugin sees keys only | `inc/commemorative-presentations.php` (registry + validation) | `test-commemorative-presentation-dispatch.php` |
| §7.5 placement catalogue | `conexao_commemorative_placement_catalogue()` | dispatch suite |
| §7.6 one identity, tokens as `--ce-*` | registry `design.tokens` + inline custom properties on the wrapper | dispatch suite asserts registry↔CSS token parity |
| §7.6 addendum signature motif | `design.motif` (`carved_pumpkin` / `bauble_ornament`) | dispatch suite + `docs/commemorative-content.md` |
| §7.7 energy + cap + visibility + reduced motion | `assets/js/commemorative.js` (`ConexaoCommemorative.AmbientScheduler`) | Node runtime test + browser run |
| §7.7 Halloween cadence exception | `assets/js/presentations/halloween/halloween.js` | existing nav suite (all assertions kept) |
| §7.8 per-placement a11y | `assets/css/commemorative.css` + templates | a11y gate + review |
| §7.9 conditional assets | `conexao_commemorative_enqueue_assets()` | gates (a) and (f) |
| §7.10 preview across placements | signed preview + generic marker | preview suite + HTTP suite |
| §7.11 conditional dispatch | `conexao_commemorative_request_plan()` | dispatch suite + gate (d) |
| §7.12 briefs, §7.13 operations | `docs/commemorative-content.md` | docs-drift gate |
| §9.5 new gates (a)–(f) | 6 `test-commemorative-gate-*.php` suites | each with an injected negative |
| §9.9 cadence | Halloween package JS | nav suite (5 baseline failures → 0) |

## 5. Files expected to change

| Path | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/commemorative-presentations.php` | registry + validated dispatcher + request plan + enqueue + markers |
| `wp-content/themes/conexao-br-irlanda/inc/occasion.php` | optional injected clock on `is_active()` (additive) |
| `wp-content/themes/conexao-br-irlanda/front-page.php` | hard-coded Halloween branch → one dispatcher call |
| `wp-content/themes/conexao-br-irlanda/header.php` | Halloween marker → generic dispatcher marker |
| `wp-content/themes/conexao-br-irlanda/footer.php` | ambient placement render hook (site-wide) |
| `wp-content/themes/conexao-br-irlanda/inc/assets.php` | conditional commemorative asset enqueue |
| `wp-content/themes/conexao-br-irlanda/inc/seo/sitemap.php` | exclude the opt-in commemorative landing pages |
| `template-parts/commemorative/{homepage-hero,ambient-layer,landing-page}.php` | shared components |
| `template-parts/commemorative/halloween-{hero,ambient}.php` | package content |
| `template-parts/commemorative/christmas-{hero,ambient,landing}.php` | second package content |
| `page-commemorative-landing.php` | assigned page template for the landing page |
| `assets/css/commemorative.css`, `assets/css/presentations/{halloween,christmas}.css` | shared framework + package styles |
| `assets/js/commemorative.js`, `assets/js/presentations/{halloween,christmas}/*.js` | shared scheduler + package behaviour |
| `assets/images/presentations/{halloween,christmas}/**` | moved/new art |
| `assets/css/main.css`, `assets/css/dark-mode.css`, `assets/js/main.js` | Halloween blocks removed |
| `scripts/seed-commemorative-landing-pages.php` | idempotent, dry-run-first landing-page seed (local only) |
| `tests/acceptance/matrices/commemorative.json`, `tests/acceptance/verify-commemorative-http.py` | HTTP/SEO/sitemap rows |
| theme `tests/test-commemorative-*.php` | dispatch, package, ambient, landing, six gates |
| `tests/scripts/verify-commemorative-runtime.py` + `tests/node/*.test.js` | runtime scheduler proof |
| `docs/commemorative-events.md`, `docs/commemorative-content.md`, indexes | documentation |

## 6. Data-safety plan

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |

## 7. Test plan

- New in-process suites (theme): `test-commemorative-presentation-dispatch.php`,
  `test-commemorative-packages.php`, `test-commemorative-ambient.php`,
  `test-commemorative-landing-page.php`, and the six
  `test-commemorative-gate-*.php` gates.
- Updated suites: `test-seasonal-halloween-nav-effects.php` (reads the package JS
  and the package CSS instead of the global bundles; every existing assertion is
  kept, plus "the global bundle no longer contains Halloween code"),
  `test-seasonal-halloween-presentation.php` (integration assertions move from a
  hard-coded `'halloween' ===` branch to the dispatcher contract).
- Runtime JS proof: `tests/node/commemorative-ambient.test.js` executed by
  `tests/scripts/verify-commemorative-runtime.py` (Node is present locally; the
  gate reports `blocked`, never `pass`, when Node is absent).
- HTTP: `tests/acceptance/verify-commemorative-http.py` +
  `matrices/commemorative.json`.
- Browser: Playwright/Chromium (verified available: Chromium 151) for screenshots,
  console/network evidence, reduced-motion and dark-mode checks.
- Gates aggregated by `scripts/verify-permanent-gates.py`.

## 8. Verification gates (final)

```bash
./scripts/run-tests.sh --only conexao-commemorative
./scripts/run-tests.sh --php
./scripts/run-tests.sh
./scripts/lint.sh
php scripts/generate-registry-docs.php --check
python3 tests/scripts/verify-script-conventions.py
python3 tests/scripts/verify-documentation-drift.py
python3 tests/scripts/verify-i18n-freshness.py
python3 scripts/verify-permanent-gates.py
./scripts/verify-release.sh
```

## 9. Risks

| Risk | Mitigation |
|---|---|
| CSS/JS extraction changes the cascade | explicit `wp_enqueue_style` dependencies, browser checks on both packages, light/dark |
| Moving 760 lines of JS breaks the nav effects | move verbatim, restore the approved constants, keep every existing assertion, browser check |
| Landing page enters the sitemap unintentionally | gate (c) + sitemap exclusion by the opt-in template marker, asserted by HTTP |
| Second package needs engine changes | none expected; any engine edit is a documented defect with its own regression test |
| i18n freshness stays red | pre-existing (commit-time based); regenerate the theme catalogue and record the limitation |

_Last verified: 2026-11-06 by the commemorative-events Stages 4–6 plan_

| Will a deploy/upload/activation happen? | **no** |
| Which local records are created? | Only the two landing pages (`natal` PT + linked `christmas` EN) via `scripts/seed-commemorative-landing-pages.php`, dry-run first, idempotent, local only |
| Which existing records change? | None. Event records, their meta and their Polylang links are read-only here |
| Rollback | Delete the two seeded pages; revert the presentation files; the CPT records are untouched |

- No commit, push, deploy, release, production write, activation or cache purge.
