# Report — Commemorative Events architecture r10, Stages 4–6

| | |
|---|---|
| **Task** | Implement r10 Stages 4–6: experience-package framework + Halloween multi-placement experience, the second package + dedicated landing page, and verification/accessibility/gates/documentation |
| **Date** | 2026-11-06 |
| **Author / agent** | Cline (implementation) |
| **Branch** | `commemorative-events` (no branch created, renamed or deleted) |
| **Starting SHA** | `cfea1faffbd38bd575128b03eb33af20f0fca641` |
| **Working tree at finish** | modified/untracked only — **no commit, no push, no deploy** |
| **Stashes preserved** | **9**, untouched (`git stash list` unchanged) |
| **Authoritative specification** | [`2026-11-03-commemorative-events-architecture-proposal-r10-revised.md`](2026-11-03-commemorative-events-architecture-proposal-r10-revised.md) |
| **Plan** | [`2026-11-06-commemorative-events-stage-4-6-plan.md`](2026-11-06-commemorative-events-stage-4-6-plan.md) |
| **Stages 0–3 record** | [`2026-11-06-commemorative-events-stage-0-3-implementation.md`](2026-11-06-commemorative-events-stage-0-3-implementation.md) |
| **Evidence** | `docs/evidence/2026-11-06-commemorative-events/stages-4-6/` |
| **Skills followed** | `wp-add-content-type`, `wp-frontend-perf`, `wp-security-review`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-update-docs` |

## 1. Summary

Stage 4 built the small, theme-owned presentation composition system r10 §7 asks
for and migrated Halloween onto it; Stage 5 added a second, visually distinct
package (`christmas`) with a real landing page built from existing records; Stage 6
closed the work with seven fail-closed gates (six PHP + one executed Node runtime
gate), a real-browser accessibility/visual review, and the architecture + content
documentation.

The engine still selects **one** event. The theme resolves that event's registered
package and renders **only that package's** placements for the current route:
`homepage_hero` (front page), `site_wide_ambient` (front page + internal pages) and
`landing_page` (the opt-in page). With no active event there is no package, no
placement, no markup and no commemorative asset.

Three things are worth reading first:

1. **Two real defects in the Stage 0–3 engine had to be fixed** — both only appear
   once an event is actually displayed, which had never happened before Stage 4
   (§5 below). Both are minimal, isolated, regression-tested and documented.
2. **The approved Halloween navigation cadence was restored** (`20000–30000 ms`
   first / `25000–35000 ms` subsequent) and every one of the 114 assertions in the
   existing Halloween navigation suite now passes.
3. **The global bundles shrank**: `main.js` −24,160 B (−19.9 %), `main.css`
   −17,248 B (−7.5 %), `dark-mode.css` −1,689 B. The word `halloween` no longer
   appears in any of them.

**Verdict: PASS WITH LIMITATIONS** — every Stage 4/5/6 criterion is implemented and
proven, with three explicitly unverified items (an independent administrator
verification, the production cache check, and a sitemap assertion that Jetpack
intercepts locally) and four pre-existing red checks that are unchanged from the
baseline.

## 2. Starting state — verified, not assumed

| Fact | Measured value | Evidence |
|---|---|---|
| Branch / HEAD | `commemorative-events` / `cfea1fa…` | `git branch --show-current`, `git rev-parse HEAD` |
| Worktree at start | **1 modified file**, 0 untracked | `git status --short` |
| Stashes | 9, unchanged before and after | `git stash list` |
| Engine suites | **6 suites, 209 assertions, 0 failures** | `baseline-commemorative-suites.txt` |
| Theme PHP layer | **53 suites: 42 pass / 11 fail; 3300 assertions pass / 42 fail** | `baseline-theme-php-suites.txt` |
| Halloween nav suite | **102 pass / 5 fail** (4 regressed-cadence, 1 date-dependent) | same |
| `commemorative_event` records | **12** (6 PT + 6 EN), packages still labels-only | `baseline-content-inventory.txt` |
| Registry / script-contract / docs-drift | green | `baseline-registry-check.txt`, `baseline-script-conventions.txt`, `baseline-documentation-drift.txt` |
| Permanent gates | 7 gates: 5 pass, 2 fail (pre-existing data + i18n) | `baseline-permanent-gates.txt`, `baseline-gate.json` |

The Stage 0–3 report and the code agreed on every point above.

## 3. Stage 4 — framework + Halloween multi-placement

| r10 criterion | Status | Where | Evidence |
|---|---|---|---|
| §7.4 theme-owned registry, plugin sees keys only | **pass** | `inc/commemorative-presentations.php` | `test-commemorative-packages.php` (131) |
| §7.5 placement catalogue (`homepage_hero`, `site_wide_ambient`; no `combined`) | **pass** | `conexao_commemorative_placement_catalogue()` | `test-commemorative-presentation-dispatch.php` (99) |
| §7.6 one identity + tokens as `--ce-*` | **pass** | registry `design.tokens` + `conexao_commemorative_wrapper_attributes()` | markup asserts `--ce-primary:#4b2163`; packages suite asserts registry ↔ CSS parity |
| §7.6 addendum signature motif | **pass** | `design.motif` = `carved_pumpkin`; one `halloween/pumpkin.php` reused everywhere | dispatch + presentation suites |
| §7.7 energy + cap + visibility + reduced motion | **pass** | energy table, `…_ambient_options()`, `assets/js/commemorative.js` | `test-commemorative-ambient.php` (53) + `commemorative_ambient_runtime` (11 executed Node cases) |
| §7.7 Halloween cadence exception preserved | **pass** | `assets/js/presentations/halloween/halloween.js` (`20000/30000`, `25000/35000`) | `test-seasonal-halloween-nav-effects.php` (114/0) |
| §7.8 per-placement accessibility | **pass** | `assets/css/commemorative.css`, ambient template, hero `noscript` rule | `commemorative_ambient_decorative` (7) + browser review (50/0) |
| §7.9 conditional assets, no-event = zero bytes | **pass** | `conexao_commemorative_enqueue_assets()` (priority 20), `…_asset_plan()` | gates (a)+(f), live HTTP 0 commemorative refs on `/` and `/documentos/` |
| §7.10 preview across placements | **pass** | signed preview + generic marker on `wp_body_open` | HTTP suite authenticated rows |
| §7.11 no event identity in templates | **pass** | `front-page.php`, `header.php`, `footer.php` | dispatch suite asserts no `halloween`/`ocons_get_primary_active` in `front-page.php` |
| §9.4 route-specific placement, no leakage | **pass** | `…_route_kinds()`, `…_build_plan()` | dispatch suite |
| Halloween CSS/JS/art out of the global bundles | **pass** | `assets/{css,js,images}/presentations/halloween/` | nav suite asserts the bundles contain no `seasonal-halloween`/`halloween` |

**Behaviour preserved:** the welcome card (reveal, session dismissal, focus return),
the navigation handoff (one-shot expiring tab-session record, same-origin only, no
`preventDefault`), the four effects, the bounded placement search, the reduced-motion
guards and the no-JS rule. The card is still revealed by script (approved), and the
shared hero shell adds a `<noscript>` rule that reveals it and hides the dead dismiss
control, so the greeting is readable without JavaScript.


## 4. Stage 5 — second package + dedicated landing page

**Choice: `christmas`**, from a read-only inventory
(`baseline-christmas-content.txt`): 38 christmas/winter events (20 inside December
2026), a real place (`newgrange`), a winter-wellbeing guide, three winter articles and
the travel/emergency guides. **New Year had 0 matching records** in any content type,
so it could not satisfy the "three real modules" rule without inventing content; it
remains a planned brief.

| r10 criterion | Status | Where | Evidence |
|---|---|---|---|
| Second package with its own motif/palette/motion/voice/do-not list | **pass** | `christmas` in the registry (`bauble_ornament`, green/red/gold/warm-white, `calm`) | packages suite asserts the two packages differ on motif, all four tokens, energy and motion |
| Distinct placement map | **pass** | registry `placements` (hero + ambient + landing) | dispatch suite |
| Zero engine changes, zero Halloween-specific edits | **pass** | no Christmas file references Halloween or the plugin | package + dispatch suites |
| Landing page via the existing routing/SEO architecture | **pass** | `page-commemorative-landing.php` + `_ce_landing_package`; no rewrite, no query var, no CPT route | HTTP matrix: 200 PT/EN, self-canonical, real hreflang pair |
| ≥ 3 real, non-duplicative modules from existing records | **pass** | `template-parts/commemorative/christmas/landing.php` (5 modules, 14 records) | landing suite (45); live page: agenda 6, lugar 1, bem-estar 1, leituras 3, guias 3 |
| Archival after the window | **pass** | landing resolution independent of the active event | landing suite renders at `2027-03-01` with no active event |
| Sitemap exclusion by default | **pass** | `conexao_seo_sitemap_include_page()` in both sitemap passes | `commemorative_cpt_privacy` renders the real theme sitemap and asserts the URLs are absent while a normal page is present |
| Annual precedence unaffected in adjacent years | **pass (existing control, unchanged)** | `_ce_year_precedence` on the PT record (Stage 0–3) | `test-commemorative-resolution.php` (27) |
| Preview works for the selected package | **pass** | signed preview | HTTP suite (23/0) |

## 5. Defects found in Stage 0–3 and fixed (minimal, isolated, regression-tested)

Both engine defects were invisible until an event was actually rendered, which
Stage 0–3 never did.

**D1 — `conexao_commemorative_active()` built the value object from the wrong array.**
A live resolution returns the winner's *evaluation row*; an authorised preview returns
the *normalised event*. Consequences: (a) a live event emitted
`Undefined array key "id"` warnings and silently lost every localised
`_ce_heading`/`_ce_subheading`/`_ce_cta_*` value; (b) a preview produced an **empty**
event (`presentation === ''`), so **no placement could ever render** — the preview
banner appeared over a page with no experience.
*Fix:* unwrap the row, resolve content from the normalised event, rebuild the
evaluation row (including the occurrence year) for the value object.
*File:* `wp-content/plugins/conexao-commemorative/includes/functions.php`.
*Proof:* dispatch suite (`presentation === 'halloween'`, non-empty title) and the
authenticated preview rows of the HTTP suite (previously: 5 failures).

**D2 — the CPT was readable through REST.**
`register_post_type()` declared `show_in_rest => true`, so
`GET /wp-json/wp/v2/commemorative_event` returned all 12 records to an unauthenticated
caller (r10 §3.7/§9.8 breach). Stage 0–3's "REST lockdown" covered the `_ce_*` meta,
not the CPT.
*Fix:* `show_in_rest => false`; the route is now `404` and the CPT is absent from
`/wp-json/wp/v2/types`.
*File:* `wp-content/plugins/conexao-data-model/conexao-data-model.php`.
*Proof:* `commemorative_cpt_privacy` gate + HTTP row `pt-cpt-rest-is-locked` (404).

**D3 — the Halloween regression suite was date-dependent** (`is_active()` read the
wall clock), which r10 §9.1 forbids. The legacy theme model gained an optional
injected clock; the default is unchanged for every existing caller.
*Files:* `wp-content/themes/conexao-br-irlanda/inc/occasion.php`,
`tests/test-seasonal-halloween-nav-effects.php`.

**D4 (accessibility, found by the Stage 6 browser review) — card text contrast.**
The module card inherited the theme's body secondary colour, measuring **2.55:1** on
the card surface. Fixed in `assets/css/commemorative.css` by using the theme's primary
text token; the review now measures **9.36–16.23:1**.

## 6. Stage 6 — verification, accessibility, gates and documentation

### 6.1 Full test matrix (real numbers)

| Command | Result |
|---|---|
| `./scripts/run-tests.sh --only conexao-commemorative --php` | **6 suites, 209 assertions, 0 failed** (exit 0) |
| `./scripts/run-tests.sh --only conexao-br-irlanda --php` | **63 suites, 53 pass / 10 fail; 3685 assertions pass / 37 fail** — the failing set is the baseline set **minus** the Halloween nav suite, i.e. no new failures (§7) |
| New commemorative suites | dispatch **99**, packages **131**, ambient **53**, landing **45**, gate-assets **5**, gate-public-activation **5**, gate-cpt-privacy **11**, gate-single-package **6**, gate-ambient-decoration **7**, gate-placement-assets **4** = **366** |
| Updated Halloween suites | nav-effects **114/0**, presentation **33/0** (baseline: 102/5 and 26/0) |
| `python3 tests/acceptance/verify-commemorative-http.py` | **23 passed, 0 failed** (12 matrix rows + 11 authenticated preview rows) |
| `python3 tests/scripts/verify-commemorative-runtime.py` | **11 runtime cases, 22 assertions, 0 failed** (Node) |
| `python3 scripts/verify-permanent-gates.py` | **14 gates: 12 pass, 2 fail** — all **7 new gates pass**; the 2 failures are the pre-existing `translation_completeness` (2 data violations) and `i18n_freshness` (2 pre-existing) |
| `php scripts/generate-registry-docs.php --check` | registry OK — 11 plugins, 20 regions current (exit 0) |
| `python3 tests/scripts/verify-script-conventions.py` | **779 passed, 0 failed** |
| `python3 tests/scripts/verify-documentation-drift.py` | **14 passed, 0 failed** |
| `./scripts/lint.sh` | PHP syntax clean; **PHPStan `[OK] No errors`**; PHPCS **legacy baseline drift, the same six categories Stage 0–3 recorded** (§7) |
| `python3 scripts/diagnostics/commemorative-browser-review.py` | **50 passed, 0 failed** (Chromium 151) |
| `./scripts/run-tests.sh --scripts` (script-contract layer) | **21 suites: 19 pass, 2 fail** — `verify-i18n-freshness` (pre-existing, commit-time) and `verify-stage8-release-consistency` (inherited: the uncommitted Stage 0–3 version bumps to data-model 1.7.0 / admin-ux 1.0.9 do not match the old `dist/release.json`) |
| `./scripts/run-tests.sh --acceptance` (HTTP layer) | **5 suites: 2 pass, 3 fail** — `verify-commemorative-http` **23/0** and `verify-guides-en-http` 20/0 pass; the three failures are the pre-existing `/empregos/` and event-fixture rows |
| `./scripts/verify-release.sh` | steps 1–6 pass (build, manifest, allowlist/hashes, determinism, exclusion proof); step 7 fails only on the pre-existing `/empregos/` smoke row |
| `./scripts/run-tests.sh` (aggregate) | **did not complete locally** — see the note below |

**Note on the local aggregate run.** The aggregate runs every suite serially, and in
this local database two `conexao-event-importer` suites
(`test-import-continuation.php`, `test-source-fetch-failure.php`) make extremely slow
progress — they import and delete hundreds of `event` fixtures against a database that
already holds ~700 events — and stall for tens of minutes each. Both are **event-importer**
suites that execute none of the code this change touches. Two attempts are recorded:
the default run reached **25 suites, all PASS**, before the first stall; the run with
`CONEXAO_TEST_TIMEOUT=900` reached **40 suites, all PASS**, before the second stall
(`final-aggregate-attempt.txt`). Rather than leave a multi-hour run hanging, the three
layers were run explicitly (`--php` per component, `--scripts`, `--acceptance`) and are
reported with real numbers in the table above, so no claim depends on the aggregate
alone. **The aggregate is therefore not reported as green**; its red state in this
environment is the pre-existing failures plus these two environment-specific stalls.

That investigation also revealed that this local database already carries **144
pre-existing `Test event for …` fixtures** from earlier runs; the fixtures created by my
runs were removed (see §10).

### 6.2 The seven new permanent gates (each with an injected negative proof)

| Gate | Positive run | Injected negative proof |
|---|---|---|
| `commemorative_no_asset_without_event` (a) | 5 assertions, 0 violations | an ACTIVE plan must be reported by the same check; the gate fails if it is not |
| `commemorative_no_public_activation` (b) | 5 assertions | an administrator with a valid nonce must still activate; a wrong nonce must not |
| `commemorative_cpt_privacy` (c) | 11 assertions | a fixture sitemap containing the forbidden URLs must be flagged, and the real sitemap must still list a normal page (non-vacuous) |
| `commemorative_single_package` (d) | 6 assertions | a stolen `landing_page` placement in a Halloween plan must be detected |
| `commemorative_ambient_decorative` (e) | 7 assertions | a fixture with `<button>`, `<a>`, `<audio>` and a missing `aria-hidden` must be detected; so must a stylesheet with `pointer-events: auto` |
| `commemorative_placement_assets` (f) | 4 assertions | an ambient asset row in a hero-only plan must be detected |
| `commemorative_ambient_runtime` | 11 executed Node cases | the gate reports **blocked** (never pass) when Node is absent |

All six PHP gates are registered in `scripts/verify-permanent-gates.py`; the runtime
gate is a `tests/scripts/verify-*.py` suite. Every one runs inside
`./scripts/run-tests.sh` through the normal discovery conventions — there is no second
runner.

### 6.3 Browser, accessibility and visual review (real evidence)

`scripts/diagnostics/commemorative-browser-review.py` — Playwright + **Chromium
151.0.7922.34**, local stack, read-only (it logs in as the local test administrator for
the preview rows only). Evidence in
`docs/evidence/2026-11-06-commemorative-events/stages-4-6/browser/`:

| Row | Viewport | Locale / mode | Result |
|---|---|---|---|
| Homepage, no active event | 1440×900 | PT, light | 200, **0 commemorative requests**, 0 placements, no console errors |
| Internal page (`/documentos/`), no active event | 1440×900 | PT, light | 200, 0 commemorative requests |
| Landing `/natal/` | 1440×900 | PT, light | 200, 5 modules, one `<h1>`, self-canonical, EN hreflang, no overflow |
| Landing `/natal/` | 1440×900 | PT, **dark** | contrast 9.36–16.23:1 |
| Landing `/en/christmas/` | 1440×900 | EN, light | 200, 5 modules, self-canonical |
| Landing `/natal/` | 390×844 | PT, light | no horizontal overflow |
| Landing, reduced motion | 1440×900 | PT, `prefers-reduced-motion: reduce` | 0 ambient decorations after 3.5 s |
| Preview homepage | 1440×900 | PT, light, logged in | hero + ambient, banner present, **only** the Halloween package asset requested, ambient `aria-hidden` + `pointer-events: none` + 0 focusable nodes, no overflow |
| Preview homepage, **Christmas package** | 1440×900 | PT, light, logged in | hero band + ambient; band 1120×188 at the hero's bottom edge, **no overlap** with the hero copy, no overflow |
| Preview homepage, **Christmas package** | 390×844 | PT, light, logged in | band 358 wide **in flow below the hero**, no overlap with the copy, no overflow |
| Preview keyboard order | 1440×900 | — | the hero dismiss control is reachable by Tab (after the WordPress admin-bar links) and dismissing hides the card |
| Preview, reduced motion | 1440×900 | — | 0 ambient decorations |

Screenshots: `pt-home-no-event-desktop-light.png`,
`pt-internal-no-event-desktop-light.png`, `pt-landing-desktop-light.png`,
`pt-landing-desktop-dark.png`, `en-landing-desktop-light.png`,
`pt-landing-mobile-light.png`, `preview-homepage-desktop-light.png`,
`preview-homepage-christmas-desktop.png`, `preview-homepage-christmas-mobile.png`.
The raw JSON (console errors, request lists, computed styles, contrast, focus order)
is `browser-review.json`. The two Christmas rows were captured with a separate
Playwright run against the signed Christmas preview link (the review script's
authenticated rows use the Halloween event, which is the record whose cadence the
suite owns).

### 6.4 Asset budget, measured

| Asset | Before | After |
|---|---|---|
| `assets/js/main.js` | 121,402 B | **97,242 B** (−24,160 B, −19.9 %) |
| `assets/css/main.css` | 228,671 B | **211,423 B** (−17,248 B, −7.5 %) |
| `assets/css/dark-mode.css` | 80,245 B | **78,556 B** (−1,689 B) |

Loaded **only** when a placement renders: `commemorative.css` 4,958 B +
`commemorative.js` 10,288 B, plus the package file(s) —
`halloween` 20,608 B CSS + 27,298 B JS + a 2,277 B SVG, `christmas` 4,488 B CSS +
1,956 B JS + a 1,324 B SVG. With no active event the request count is **zero** (both
gates and the live browser run).


## 7. Pre-existing red checks (unchanged, re-measured, not hidden)

Everything below was red **before** this task and is red in exactly the same way
after it. Evidence: `docs/evidence/2026-11-06-commemorative-events/stages-4-6/`.

| Check | Baseline | After | Why it is pre-existing |
|---|---|---|---|
| Theme PHP layer | 11 suites / 42 assertions failing (3300 passing) | **10 suites / 37 assertions failing (3685 passing)** — the failing set is the baseline set minus the Halloween nav suite; no new failing suite or assertion | the remaining suites are the Blog/Jobs/guide EN data suites, `test-event-filter-cache.php`, `stage33/41/45`, `translation-completeness` — all about **existing local content**, none touches a commemorative file |
| `translation_completeness` gate | 2 data violations (`new: 2`) | identical (`page:en_without_pt:empregos`, `guide:carteira-motorista-brasileiros`) | local data last modified 2026-10-21, before this task |
| `i18n_freshness` gate | 2 pre-existing | identical, `new: 0` | the gate uses **git commit time**; the regenerated catalogues cannot have a commit time until the change is committed |
| `./scripts/lint.sh` → PHPCS legacy baseline | 6 categories above the baseline | **the same 6, same counts** (`NoDoubleNegative` 1, `ScopeIndent` +2, `VariableComment.Missing` +10, `SeparateFunctionsFromOO` +1, `InvalidClassFileName` +1, `MissingTranslatorsComment` +1) | every instance is in a legacy file (`inc/occasion.php` properties, `single-leisure.php`, `template-parts/leisure-card.php`, `event-filters.php`, importer classes). A targeted scan of every file this change created or modified reports **zero** violations in those categories; the two I did introduce (`BlockComment.NoEmptyLineBefore`, `EscapeOutput`) were **fixed**, not baselined |
| HTTP acceptance `/empregos/` rows | 3 failures | identical | local page **22200** is assigned Polylang language `en` while the site default is `pt` |
| `verify-event-occurrence-http.py` (Laois archive) | 2 failures | identical | fixture/date-dependent event data, independent of this change |

**Aggregate honesty:** `./scripts/run-tests.sh` remains **red** because those
pre-existing suites and rows are red. This report does not describe a partially green
run as green; the commemorative and Halloween suites are green with the counts above.

## 8. Not verified / deferred

| Item | Status | Reason and smallest next action |
|---|---|---|
| Independent administrator verification of the annual-precedence control (r10 §6.5/§10) | **not independently verified** | No administrator outside the implementation was available here. The control itself is covered by the engine suite (year-scoped preference, adjacent year unaffected, preference never changes eligibility). **Manual procedure:** log in as a second administrator → *Eventos Comemorativos* → open the Christmas event → *Precedência anual* → set occurrence year 2026 and tick "preferido" → save → confirm the list shows *preferido* for 2026 only, 2025/2027 unaffected, and that a disabled competing event still does not win. Remove the flag afterwards. |
| Production cache verification (r10 §4.6 point 8) | **not verified — Option B** | Requires production access, which this task is not authorised to use. The guarantee remains "purge at the first PHP request after the boundary, or within the verified TTL". **Next action:** a separately authorised run on WordPress.com following `docs/plugins/conexao-commemorative.md`. |
| Sitemap assertion over real HTTP | **verified in-process, not end-to-end** | This local stack serves **Jetpack's** sitemap index at `/sitemap.xml`, so the theme producer never answers the request. The exclusion is proven by rendering the theme's real producer inside `commemorative_cpt_privacy`; the HTTP row only asserts the served sitemap contains none of the commemorative URLs. **Next action:** confirm on production before relying on the rule there. |
| Automated accessibility audit score (axe/Lighthouse) | **not run** | No such tool is installed in this repository's environment; no score is claimed. What was run is §6.3 (contrast measurements, focus order, ARIA/pointer-events assertions, reduced motion, overflow). |
| Halloween frame-by-frame visual parity | **intentionally not claimed** | r10 §7.1 allows the visuals to change; acceptance is functional. |


## 9. Files

### Created (theme)

`inc/commemorative-modules.php`, `page-commemorative-landing.php`,
`template-parts/commemorative/{homepage-hero,ambient-layer,landing-page}.php`,
`template-parts/commemorative/modules/module.php`,
`template-parts/commemorative/halloween/{hero,ambient,pumpkin}.php`,
`template-parts/commemorative/christmas/{hero,ambient,landing}.php`,
`assets/css/commemorative.css`, `assets/css/presentations/{halloween,christmas}.css`,
`assets/js/commemorative.js`, `assets/js/presentations/halloween/halloween.js`,
`assets/js/presentations/christmas/christmas.js`,
`assets/images/presentations/christmas/decorations/bauble.svg`, and ten suites:
`tests/test-commemorative-{presentation-dispatch,packages,ambient,landing-page}.php`
plus
`tests/test-commemorative-gate-{assets,public-activation,cpt-privacy,single-package,ambient-decoration,placement-assets}.php`.

### Created (other)

`scripts/seed-commemorative-landing-pages.php`,
`scripts/diagnostics/commemorative-{content-inventory,christmas-content,dispatch-probe}.php`,
`scripts/diagnostics/commemorative-browser-review.py`,
`tests/acceptance/verify-commemorative-http.py`,
`tests/acceptance/matrices/commemorative.json`,
`tests/node/commemorative-ambient.test.js`,
`tests/scripts/verify-commemorative-runtime.py`,
`docs/commemorative-events.md`, `docs/commemorative-content.md`, the plan, this
report and `docs/evidence/2026-11-06-commemorative-events/stages-4-6/`.

### Modified

`inc/commemorative-presentations.php` (registry + dispatcher, replacing the
labels-only version), `inc/occasion.php` (optional clock),
`inc/seo/sitemap.php` (shared page decision + testable producer),
`functions.php` (one `require_once`), `front-page.php`, `header.php`, `footer.php`,
`assets/css/main.css`, `assets/css/dark-mode.css`, `assets/js/main.js`,
`tests/test-seasonal-halloween-{nav-effects,presentation}.php`,
`wp-content/plugins/conexao-commemorative/includes/functions.php`,
`wp-content/plugins/conexao-data-model/conexao-data-model.php`,
`scripts/verify-permanent-gates.py`, `scripts/README.md`,
`docs/{README,content-model,routing,testing}.md`,
`docs/themes/conexao-br-irlanda.md`, `docs/plugins/conexao-commemorative.md`, and the
four catalogues the changed strings belong to
(`conexao-br-irlanda`, `conexao-commemorative`, `conexao-data-model`,
`conexao-admin-ux` .pot files, regenerated with `scripts/i18n-make-pot.sh`; six other
catalogues whose only diff was the `POT-Creation-Date` header were reverted).

### Moved / deleted

`assets/images/halloween/witch-broom.svg` →
`assets/images/presentations/halloween/decorations/witch-broom.svg`;
`template-parts/halloween-hero.php` deleted (its content now lives in
`template-parts/commemorative/halloween/hero.php`, inside the shared shell).

### Local content created (local database only)

Two pages: PT `natal` (id 125982) and EN `christmas` (id 125983), linked by Polylang,
each with the landing template and `_ce_landing_package = christmas`. Created by
`scripts/seed-commemorative-landing-pages.php --apply` (dry-run first; a second run
reports `created=0`). No existing record was modified; the 12 `commemorative_event`
records are untouched.

## 10. Preservation and safety

- **Branch** `commemorative-events` and **HEAD** `cfea1fa…` — unchanged.
- **No commit, no push, no deploy, no release, no tag.**
- **No production write, activation, cache purge or verification.** The only writes
  were local: the two landing pages and the regenerated `.pot` catalogues.
- **9 stashes preserved**; no `git stash` apply/pop/drop/clear, no `reset`, no
  `clean`, no `checkout --` of pre-existing work.
- The Flutter/mobile repository was **not touched**.
- Pre-existing evidence was not overwritten; Stage 4–6 evidence lives in a new
  `stages-4-6/` subdirectory.
- **Local database hygiene.** The killed/timeout investigations of the slow
  `test-import-continuation.php` suite (see §6.1) left importer fixtures behind. I
  removed exactly what my runs created — the `Event g18probe-*` records and the nine
  `Test event for …` / `Unrelated Cache Test` records stamped inside my run window —
  and left the **144 pre-existing** `Test event for …` fixtures untouched, because
  they predate this task and are not mine to delete. No real content, term, option or
  user was modified or removed.

## 11. Final status

**PASS WITH LIMITATIONS**

Implemented and proven: the theme-owned registry and dispatcher, the three
placements, the Halloween migration (approved cadence restored) and the conditional
asset pipeline; the second package and its five real landing-page modules in PT/EN;
the seven gates with injected negative proofs; the architecture doc, the content
playbooks, the calendar and the watchlist.

Limitations, all explicit: no independent administrator verification; the production
cache check remains Option B and unverified; the sitemap rule is proven in-process
because Jetpack answers `/sitemap.xml` locally; no automated a11y score was produced;
and the aggregate suite stays red because of the pre-existing content/lint debt
itemised in §7.

_Last verified: 2026-11-06 by the commemorative-events Stages 4–6 implementation_

