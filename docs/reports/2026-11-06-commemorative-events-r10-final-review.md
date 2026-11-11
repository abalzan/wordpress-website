# Report — Commemorative Events r10: consolidated final review (read-only)

> **Read-only review.** No code, content, configuration, test, generated file,
> documentation (other than this report) or database change was made. No commit,
> push, deploy, release, activation, deactivation or production access occurred.
> This file is the **only** write of the review.

| | |
|---|---|
| **Task** | Final, evidence-based review of the Commemorative Events architecture r10 implementation (Stages 0–6) |
| **Date** | 2026-11-06 |
| **Branch** | `commemorative-events` (not switched, not created, not deleted) |
| **HEAD at start / end** | `657c3340f85f7b9f783edc85c43805ee8a35347c` / **identical** |
| **Specification** | [`2026-11-03-commemorative-events-architecture-proposal-r10-revised.md`](2026-11-03-commemorative-events-architecture-proposal-r10-revised.md) |
| **Plans / reports reviewed** | [`…stage-0-3-plan`](2026-11-06-commemorative-events-stage-0-3-plan.md), [`…stage-0-3-implementation`](2026-11-06-commemorative-events-stage-0-3-implementation.md), [`…stage-4-6-plan`](2026-11-06-commemorative-events-stage-4-6-plan.md), [`…stage-4-6-implementation`](2026-11-06-commemorative-events-stage-4-6-implementation.md) |
| **Docs reviewed** | `docs/commemorative-events.md`, `docs/commemorative-content.md`, `docs/plugins/conexao-commemorative.md`, `docs/content-model.md`, `docs/routing.md`, `docs/testing.md`, `docs/README.md`, `docs/reports/README.md` |
| **Evidence reviewed** | `docs/evidence/2026-11-06-commemorative-events/` (12 files) and `…/stages-4-6/` (41 files, incl. `gate.json` and the browser JSON + PNGs) |

## 1. Executive verdict

**NEEDS WORK** — the r10 architecture is **substantially implemented and locally
verifiable**, and every commemorative-specific suite and permanent gate I could
re-run safely is green with the numbers the reports claim. It is **not** yet
release-ready, and two r10 requirements are not met as written:

1. **The landing-page sitemap exclusion is not effective in the only environment
   that can be observed** (Jetpack serves `/sitemap.xml`; the theme producer never
   runs) and its production behaviour is unverified. The HTTP matrix row that is
   supposed to prove it passes vacuously. **(High)**
2. **The Polylang single-owner rule is a convention, not a mechanism**: there is no
   `pll_copy_post_metas` exclusion and the EN editor is not read-only, so the
   "drift class" r10 §3.6 says is *removed* is in fact only *detected*. **(High)**

Neither defect changes today's public rendering (verified live). They are
conformance and release-readiness blockers, not runtime failures. The verdict is
`NEEDS WORK` rather than `PASS WITH LIMITATIONS` because these are **local**
conformance gaps, not only the separately authorised production checks.

There is **no Critical finding**: no public exposure of the CPT or its meta, no
preview bypass found, no public activation path, no data loss, no production
action.

## 2. Scope and method

**Reviewed**

- r10 in full (2,201 lines) — §3 data model, §4 scheduling and caching, §5 admin
  UX, §6 conflict resolution, §7 presentation architecture, §8 migration, §9
  testing plan, §10 roadmap (Stages 0–6), §13 limitations.
- All four plan/report documents, the three architecture/content docs, the
  permanent-gate aggregate script, the acceptance matrix and the evidence
  directories.
- The complete implementation: the `conexao-commemorative` plugin (10 files,
  3,530 lines), the `conexao-data-model` commemorative schema (501 lines), the
  `conexao-admin-ux` config, the theme registry/dispatcher (1,138 lines), the
  placement shells and package templates, the moved Halloween assets, the shared
  and package JS/CSS, the sitemap decision function, the ten theme test suites,
  the six new PHP gates, the Node runtime gate and the diagnostics scripts.

**Not reviewed / not run (and why)**

| Item | Reason |
|---|---|
| The six plugin in-process suites (admin, cache-boundary, migration, preview, resolution, schedule) | They create and delete `commemorative_event` records and options. Re-running them would violate this review's "do not seed/create/edit/delete WordPress records" constraint. Their recorded evidence was read and cross-checked instead. |
| `./scripts/run-tests.sh` (aggregate) | The two known importer suites stall in this database (see §8). Running it would repeat an already-documented multi-hour stall. |
| `./scripts/lint.sh` | Slow full-tree PHPCS/PHPStan; the committed `final-lint.txt` was read and its counts were cross-checked against the Stage 0–3 `lint.txt`. |
| `./scripts/verify-release.sh` | Builds release artifacts (`dist/`, git-ignored) — a write side effect, and unnecessary to judge the code. |
| `python3 scripts/verify-permanent-gates.py` | It **writes** `docs/evidence/…/gate.json`. I ran the individual gates (which only print) and aggregated them myself. |
| The browser/Playwright review | It logs into the local admin and would be a long, state-touching re-execution; the recorded JSON + PNGs were inspected instead. |
| Production (WordPress.com); the Flutter/mobile repository | Explicitly out of scope; no access attempted. |

**Method.** Preservation snapshot → r10 read in full → requirements matrix →
source inspection (no edits) → safe targeted re-execution (12 theme suites, 1 Node
gate, 4 static gates, 10 live HTTP probes, 4 read-only SQL queries) → end-state
verification of worktree and stashes.

## 3. Preservation snapshot

| | At review start | At review end |
|---|---|---|
| Branch | `commemorative-events` | `commemorative-events` |
| `HEAD` | `657c3340f85f7b9f783edc85c43805ee8a35347c` | **identical** |
| `git status --short` | **empty** (0 modified, 0 untracked) | **one new untracked file** — this report |
| Stash entries | **9** (`stash@{0}` … `stash@{8}`) | **9**, contents untouched |
| Upstream | none (`origin/commemorative-events` does not exist ⇒ **not pushed**) | same |

- **No** `reset`, `clean`, `checkout --`, stash apply/pop/drop/clear, rebase,
  cherry-pick, commit, push, tag, release or deploy was executed.
- **No** production access, production cache purge, plugin
  activation/deactivation, dependency installation, asset rebuild, catalog
  regeneration or fixture write.
- **Database:** read-only SQL only. Two checks bound the side-effect surface:
  `SELECT … FROM wp_posts WHERE post_modified_gmt >= '2026-11-06 11:30:00'`
  returned **0** (nothing was created or edited during the review), and the
  read-only option query returned the pre-existing armed boundary, last-purge
  record and seeded-key record unchanged.
- **One accidental, content-free side effect, disclosed:** a single
  `php -r 'require wp-load.php; …'` inspected the active-plugin list. It performed
  no content write (proven by the query above), but loading WordPress on the local
  stack may have let due WP-Cron events run. Nothing in the repository or in post
  content changed as a result.
- **The single intentional write is this report.** `git status --short` therefore
  moves from 0 to exactly
  `?? docs/reports/2026-11-06-commemorative-events-r10-final-review.md`. The path
  did not exist before, so no file was overwritten. The documentation-drift gate
  was re-run **after** the file was written and still passes (14/0, exit 0), so the
  deliverable breaks no gate.

### 3.1 The repository no longer matches the reports' recorded state

| Fact | Reports say | Measured now |
|---|---|---|
| `HEAD` | `cfea1fa…` (Stage 4–6), `50ae318` (Stage 0–3) | **`657c334…`** |
| Worktree | "modified/untracked only — no commit" | **clean, 0 changes** |
| Commits | "no commit created" | **two commits on top of `50ae318`**: `cfea1fa` (Stage 0–3) and `657c334` (Stage 4–6) |
| Push | "no push" | **no upstream ⇒ still unpushed** ✔ |

`657c334` contains **exactly** the 62 working-tree paths the Stage 4–6 report
recorded (expanding the six untracked directories gives 105 files — no extra path,
no missing path). So the committed tree is the reviewed tree; only the
*preservation statements* are stale. Its commit message — "Add tests for
commemorative presentation dispatch and seasonal Halloween effects" — materially
under-describes a commit that carries the whole Stage 4–6 implementation,
documentation and evidence.

## 4. Requirements matrix

Status vocabulary: **PASS** (implemented and evidenced) · **PARTIAL** (implemented
with a documented or discovered deviation) · **FAIL** (not implemented or not met)
· **NOT VERIFIED** (no admissible evidence) · **N/A**.

### 4.1 Stage 0 — decisions and interfaces

| # | Requirement (r10) | Implementation | Evidence | Status |
|---|---|---|---|---|
| S0.1 | Theme-independent **platform plugin** `conexao-commemorative` (§2.3) | `wp-content/plugins/conexao-commemorative/` | no template/theme reference in any plugin file (grep); `plugins.json` entry `platform`, `production/build/mount: true`, depends on `conexao-data-model` | **PASS** |
| S0.2 | Frozen public API: `conexao_commemorative_active()`, `conexao_commemorative_presentations` filter | `includes/functions.php` | dispatch suite (live re-run 99/0) asserts API + registry wiring | **PASS** |
| S0.3 | Field set frozen incl. `_ce_event_key`, `_ce_year_precedence`; shared/translated split | `Conexao_Data_Model_Commemorative::defaults()`, `SHARED_META_KEYS`, `TRANSLATED_META_KEYS`, `IDENTITY_META_KEYS` | `docs/content-model.md` table; registry + docs gates green | **PASS** |
| S0.4 | Registry, mount, CI, docs entries | `plugins.json`, `compose.yaml`, generated regions | `generate-registry-docs.php --check` → 11 plugins, 20 regions, exit 0 (re-run) | **PASS** |

### 4.2 Stage 1 — scheduling engine

| # | Requirement | Implementation | Evidence | Status |
|---|---|---|---|---|
| S1.1 | Four rules + injected clock | `class-…-schedule.php` (631 lines) | schedule suite 56/0; dispatch suite injects 2026-10-20 and 2026-12-20 | **PASS** |
| S1.2 | Inclusive `00:00:00`/`23:59:59` in `wp_timezone()`; `Europe/Dublin` fallback (W14) | `timezone()`, `annual_window()`, `contains()` | schedule suite | **PASS** |
| S1.3 | New Year = seven-day `12-26 → 01-01` wrap | `resolve_window()`, `wraps()`, `window_for_year()` | live probe; `occurrence_year` 2027 across midnight | **PASS** |
| S1.4 | `one_off` never searches adjacent years | `resolve_one_off()` | schedule suite | **PASS** |
| S1.5 | Occurrence-year identity (annual = occurrence year; one-off = stored year; non-wrapping window = window year; wrapping window = **end** year) | `resolve()` / `resolve_window()` | resolution suite (27, recorded) | **PASS** |
| S1.6 | Order: published → enabled → in window → valid package → precedence → priority → event-key | `Resolver::evaluate()`, `rank()` | resolution suite | **PASS** |
| S1.7 | Precedence is a manual per-occurrence-year hint that cannot enable a draft/disabled/out-of-window event | `is_preferred()` read only after eligibility | resolution suite; `preferred_conflicts()` counts flags only | **PASS** |
| S1.8 | Multiple preferred → global priority → event-key; admin warning | `rank()` + `preferred_flag_conflicts()` | resolver + admin code | **PASS** |
| S1.9 | `02-29` / impossible month-days rejected, never coerced | `sanitize_month_day()`, `Schedule::month_day()` | schedule suite + sanitizer | **PASS** |
| S1.10 | Halloween cadence restored (20–30 s / 25–35 s) without editing tests | `halloween.js:170-173` | **live re-run: nav-effects 114 passed, 0 failed** | **PASS** |
| S1.11 | No runtime fallback to a built-in event list | `Repository::scheduled()` reads the CPT only | no fallback path; seed uses `conexao_commemorative_seeded_keys` | **PASS** |

### 4.3 Stage 2 — records, administration, migration

| # | Requirement | Implementation | Evidence | Status |
|---|---|---|---|---|
| S2.1 | `commemorative_event` CPT in `conexao-data-model`, private on every axis | `register_commemorative_post_type()` (`public`, `publicly_queryable`, `has_archive`, `rewrite`, `query_var` false; `exclude_from_search` true) | gate `commemorative_cpt_privacy` **live 11/0**; live `GET /commemorative_event/` → **404** | **PASS** |
| S2.2 | r10 §3.2/§3.7: CPT `show_in_rest => true` + explicit REST tests + authenticated read | **`show_in_rest => false`** (route removed entirely) | live `GET /wp-json/wp/v2/commemorative_event` → **404**; the §9.8 "authenticated user can read" row is **N/A** | **PARTIAL** (M4) |
| S2.3 | Every `_ce_*` meta registered with a sanitize callback, `show_in_rest => false` | `register_meta()` (17 keys) | data-model code; privacy gate | **PASS** |
| S2.4 | `_ce_year_precedence` JSON map, years `Y−2..Y+5`, note ≤ 200, invalid JSON → `{}` | `sanitize_year_precedence()`, `allowed_years()`, `decode_year_precedence()` | resolution suite; admin save validates the year | **PASS** |
| S2.5 | Admin list columns + reason + Preview row action | admin-ux `columns`, `render_column()`, `row_actions()` | config/code; admin suite 41 (recorded) | **PASS** |
| S2.6 | Editor as **five** sections (Informação, Agendamento, Apresentação, Administração, Preferência anual) | 3 config sections + one engine meta box carrying diagnostics, preview link, precedence control, date simulator | config + admin code | **PARTIAL** (same capabilities, different structure) |
| S2.7 | Read-only date-simulation inspector | `render_simulation()` with nonce | admin code (nonce verified before use) | **PASS** |
| S2.8 | Dashboard summary ("active now / next to start") | **not implemented** — the list screen shows notices with the armed boundary and last purge | grep: no `summary` key for `commemorative_event`, no dashboard widget | **FAIL** (L2) |
| S2.9 | Non-blocking warning when >1 event is preferred for the same year | `preferred_flag_conflicts()` + notice | admin code | **PASS** |
| S2.10 | Idempotent, dry-run-first seed of the six occasions incl. corrected New Year window + EN pair | `class-…-migration.php`, `scripts/seed-commemorative-events.php` | migration suite 25 (recorded); live DB `conexao_commemorative_seeded_keys` lists all six | **PASS** |
| S2.11 | §3.6: shared config **only** on PT; EN carries `_ce_event_key` only; **exclusion from Polylang meta copy**; EN editor read-only | Seed writes identity only; engine reads canonical; **no `pll_copy_post_metas` filter; the editor renders the same shared fields for both languages** (no readonly / language awareness) | migration suite asserts the split (**detection**); no prevention mechanism | **PARTIAL → H2** |
| S2.12 | Locale only via `conexao_current_locale()`; canonical → PT fallback for copy, noticed | `Repository::content()`, `current_locale()` | code | **PASS** |

### 4.4 Stage 3 — preview and activation boundary

| # | Requirement | Implementation | Evidence | Status |
|---|---|---|---|---|
| S3.1 | Conjunctive preview auth: logged in **AND** `manage_options` **AND** `edit_post` **AND** valid per-key nonce; silent failure | `class-…-preview.php::requested()` | gate `commemorative_no_public_activation` **live 5/0**; HTTP suite 23/0; live bogus-nonce request → 0 commemorative bytes | **PASS** |
| S3.2 | Preview works for published/disabled/draft/out-of-window; PT/EN; persists nothing | preview → resolver bypass only | preview suite 20 (recorded); HTTP rows (recorded) | **PASS** |
| S3.3 | Preview **actively** bypasses caches and **fails closed** if it cannot | `DONOTCACHEPAGE`, `DONOTCDN`, `nocache_headers()`, `no-store, private` + `_ce_ts` token | code; host behaviour unverified; token is `time()` (L1); no fail-closed path exists | **PARTIAL** |
| S3.4 | One armed boundary (never stacked), per-request due check, targeted purge, re-arm chain | `arm()`/`clear()` (scheduled **without args**), `maybe_purge_due()`, `handle_scheduled_boundary()` | cache-boundary suite 40 (recorded); live DB boundary `2026-12-10T00:00:00+00:00` (christmas start) | **PASS** |
| S3.5 | Lock guarantee (one purge per boundary, self-expiring) | `acquire_lock()`/`release_lock()` (object cache `add`, else option with TTL 60) | cache-boundary suite | **PASS** (L3) |
| S3.6 | Recalculation **after** persistence; `save_post` 999, `added/updated/deleted_post_meta`, `transition_post_status`, `deleted_post` (never `before_delete_post`) | `Cache::boot()` | code + suite | **PASS** |
| S3.7 | Priority / precedence changes invalidate but do **not** re-arm | `ORDER_ONLY_KEYS` in `handle_meta_change()` | code; but `on_save()` always re-arms (L3) | **PARTIAL** |
| S3.8 | Targeted only: never `wp_cache_flush()`, never `delete_expired_transients()` | `invalidate()` | code; unrelated caches survive (suite) | **PASS** (L8) |
| S3.9 | wp-admin purge action (capability + nonce) — production has no CLI | `handle_admin_purge()` (`manage_options` + `check_admin_referer`) | code; notice with the form | **PASS** |
| S3.10 | Option B stated honestly, no exact-activation claim | `docs/plugins/conexao-commemorative.md` §"Option B", reports §1/§11 | docs | **PASS** |

### 4.5 Stage 4 — experience-package framework and Halloween migration

| # | Requirement | Implementation | Evidence | Status |
|---|---|---|---|---|
| S4.1 | Theme-owned registry; the plugin sees package **keys** only | `inc/commemorative-presentations.php` (1,138 lines), filter registration | packages suite **live 131/0**; dispatch suite 99/0 | **PASS** |
| S4.2 | Placement catalogue (`homepage_hero`, `site_wide_ambient`, `landing_page`; no `combined`) | `conexao_commemorative_placement_catalogue()` | dispatch suite | **PASS** |
| S4.3 | One active event → one package → **only that package's** placements | `build_plan()`, `asset_plan()` | gate `commemorative_single_package` **live 6/0** (stolen-placement proof) | **PASS** |
| S4.4 | Route kinds; hero never on internal pages; ambient only where configured | `route_kinds()`, placement `routes` | dispatch suite; browser rows for `/` and `/documentos/` | **PASS** |
| S4.5 | No event identity in templates | `front-page.php` → `render_placement('homepage_hero')`; header/footer generic | presentation suite **live 33/0** | **PASS** |
| S4.6 | Tokens `--ce-*`, signature motif, motion, copy voice, do-not list | registry `design` + `wrapper_attributes()` | packages suite (registry ↔ CSS parity, distinct identities) | **PASS** |
| S4.7 | Energy + session cap + visibility pause + reduced motion + one at a time + cleanup | energy table, `ambient_options()`, `commemorative.js` | ambient **live 53/0**; Node gate **live 11 cases / 22 assertions**; browser reduced-motion row | **PASS** |
| S4.8 | Ambient decorative only (`aria-hidden`, `pointer-events: none`, no focus, no audio) | ambient shell + scheduler | gate **live 7/0**; browser `focusable: 0`, `pointerEvents: none` | **PASS** |
| S4.9 | Conditional assets; no active event ⇒ zero commemorative bytes | `enqueue_assets()` (priority 20) | gates (a)+(f) live; live `/` and `/guias/` = **0** commemorative refs | **PASS on non-landing routes** (M2) |
| S4.10 | Halloween CSS/JS/art out of the global bundles; bundle smaller; cadence preserved | `assets/{css,js,images}/presentations/halloween/` | measured: `main.js` 121,402 → 97,242 B (−24,160, −19.9 %), `main.css` 228,671 → 211,423 B (−17,248, −7.5 %), **0** "halloween" in `main.js`/`main.css`/`dark-mode.css` | **PASS** |
| S4.11 | Preview across placements with a non-dismissible banner | `wp_body_open` banner + `data-preview` | HTTP suite 23/0 (recorded); live bogus-nonce probe renders nothing | **PASS** |

### 4.6 Stage 5 — second package and landing page

| # | Requirement | Implementation | Evidence | Status |
|---|---|---|---|---|
| S5.1 | Second package with a distinct identity, **zero** engine changes | `christmas` (`bauble_ornament`, green/red/gold, `calm`) | packages + dispatch suites; no plugin edit needed | **PASS** |
| S5.2 | Distinct placement map (hero + ambient + landing) | registry | dispatch suite | **PASS** |
| S5.3 | Landing page is a **normal page** with double opt-in, existing routing | `page-commemorative-landing.php` + `_ce_landing_package` + slug match | live `/natal/` 200, `/en/christmas/` 200; no rewrite/query var | **PASS** |
| S5.4 | ≥ 3 real modules from existing records | `christmas/landing.php` (5 modules) | **live `/natal/`: 5 modules**; landing suite 45/0 | **PASS** |
| S5.5 | PT/EN canonical and mirrored hreflang | existing Polylang SEO layer | **live: self-canonical + `pt-BR`/`en`/`x-default` pair on both URLs** | **PASS** |
| S5.6 | Sitemap exclusion by default | `conexao_seo_sitemap_include_page()` (both passes) | gate (c) 11/0 renders the theme producer; **the live `/sitemap.xml` is Jetpack and never runs the producer** | **PARTIAL / NOT VERIFIED** (H1) |
| S5.7 | Archival after the window (never unpublished) | landing resolution independent of the event | landing suite renders at `2027-03-01` with no active event | **PASS** (M2 asset implication) |
| S5.8 | Annual precedence on the new package for one year, adjacent years unaffected | existing control | resolution suite (27) | **PASS** (in-process only) |

### 4.7 Stage 6 — verification, accessibility, documentation

| # | Requirement | Implementation | Evidence | Status |
|---|---|---|---|---|
| S6.1 | Seven new **fail-closed** gates, each with an injected negative proof | 6 PHP gates + 1 Node gate | **all re-run live: 5/0, 5/0, 11/0, 6/0, 7/0, 4/0, 22/0** | **PASS** |
| S6.2 | Accessibility review with real browser evidence | `commemorative-browser-review.py` | `browser-review.json` **50 passed / 0 failed**, Chromium 151; contrast 9.36–16.23 light / 13.67–15.82 dark; 9 screenshots | **PASS** (no axe/Lighthouse score, §8) |
| S6.3 | Architecture doc + runbook + playbooks + calendar + watchlist | `docs/commemorative-events.md`, `docs/commemorative-content.md` | documentation-drift 14/0 (re-run); both indexed | **PASS** |
| S6.4 | Editorial checklist recorded in the Stage 6 acceptance record | briefs carry all seven fields | **no distinct "editorial checklist" record exists** (grep) | **PARTIAL** |
| S6.5 | Precedence control verified by an administrator **not** on the team | — | not done (report states) | **NOT VERIFIED** |
| S6.6 | Overlapping fixtures demonstrated in **local preview** | — | no such evidence in `stages-4-6/` | **NOT VERIFIED** |
| S6.7 | `./scripts/run-tests.sh` green | — | aggregate did not complete; component layers green apart from pre-existing failures | **FAIL** (aggregate claim only) |

## 5. Findings

### 5.1 Critical

**None.** No public access to commemorative CPT data or meta, no preview bypass,
no public activation path, no SQL injection, no unescaped output in the reviewed
paths, no data loss, no production or mobile-repository action.

### 5.2 High

**H1 — The landing-page sitemap exclusion is not in effect in the observable
environment, and its production behaviour is unverified.**

- r10 §7.5/§7.13: "excluded from the sitemap unless deliberately approved";
  §9.4/§9.5(c) require it to be verified.
- The implementation is correct **inside the theme producer**:
  `conexao_seo_sitemap_include_page()` excludes `conexao_commemorative_is_landing_page()`
  and is used by both the PT and EN passes; gate (c) renders that producer and
  passes 11/0 (re-run).
- **But the producer never runs here.** `conexao_seo_sitemap()` is hooked to
  `template_redirect` (priority 0) for `/sitemap.xml` or `/sitemap_index.xml` only.
  Measured live: `GET /sitemap.xml` returns **536 bytes generated by
  `jetpack-16.2-a.3`** — a sitemap **index** pointing at
  `https://conexaobrirlanda.com/sitemap-1.xml`. It enumerates no local page, so the
  HTTP row `pt-sitemap-does-not-publish-commemorative-urls` ("absent `/natal/`,
  `/en/christmas/`, `commemorative_event`") **passes vacuously** and cannot fail.
- Consequence: the only in-repo mechanism for the exclusion is shadowed by the
  plugin that serves the sitemap here (WordPress.com runs Jetpack; Jetpack
  sitemaps include `page` records by default — an inference, not a measurable fact
  from this repository). Until this is checked against the production sitemap — or
  a Jetpack sitemap filter/robots rule is added — **the landing pages may be
  indexed**, which is what r10 forbids.
- Next action: a separately authorised production check of the page sitemap, plus
  (if it fails) a small, separately planned exclusion for whichever producer
  serves `/sitemap.xml`. The HTTP row should assert against the **serving**
  producer or be annotated "vacuous in this environment".

**H2 — The Polylang single-owner rule (§3.6) is enforced by convention and
detection, not by the mechanisms r10 requires.**

- r10 §3.6 requires the scheduling/presentation `_ce_*` keys to be **excluded from
  Polylang's meta copy**, the **EN editor to render the shared values read-only**,
  and "a permanent gate [that] removes the drift class entirely rather than
  detecting it".
- Measured: there is **no** `pll_copy_post_metas` (or equivalent) filter anywhere in
  the repository; the `conexao-admin-ux` editor/config have **no** Polylang or
  language awareness, so `_ce_enabled`, `_ce_priority`, `_ce_presentation`, the
  scheduling inputs and `_ce_year_precedence` are **editable on the EN record**
  exactly as on the PT record; a `readonly` field type exists in `class-fields.php`
  and is not used here.
- Current functional impact is contained: `Repository::scheduled()` collapses
  translations onto the canonical record and `content()` reads only translated
  copy, so duplicated EN config is ignored at render time. The damage is to the
  documented invariant and to the gate: `test-commemorative-migration.php` asserts
  "the EN record carries NO shared configuration rows" — a **detecting** assertion
  that starts failing the moment an editor creates a translation through the normal
  Polylang UI (Polylang copies post meta by default) or edits the EN record.
- Next action (local remediation, small): add the Polylang meta-copy exclusion for
  `SHARED_META_KEYS`, render those fields read-only (or hide them) on non-canonical
  records, and promote the split assertion into a registered permanent gate.

### 5.3 Medium

**M1 — The PHPCS gate is red partly because of this project, and the Stage 0–3
report says otherwise.**

- Stage 0–3 report: "the PHPCS baseline drift (my files add zero violations)".
- Its own committed evidence (`docs/evidence/2026-11-06-commemorative-events/lint.txt`)
  records new violations, not zero: brand-new sniff
  `Universal.CodeAnalysis.NoDoubleNegative.FoundDouble` (1);
  `Generic.WhiteSpace.ScopeIndent.IncorrectExact` 19 → **21**;
  `Squiz.Commenting.VariableComment.Missing` 5 → **15**;
  `Universal.Files.SeparateFunctionsFromOO.Mixed` 5 → **6**;
  `WordPress.Files.FileName.InvalidClassFileName` 74 → **75**;
  `WordPress.WP.I18n.MissingTranslatorsComment` 101 → **102**.
- Stage 4–6 added **none** (`final-lint.txt` has identical counts), so the
  Stage 4–6 wording ("the same six categories Stage 0–3 recorded") is accurate.
  Total violations still fell (2,858/2,221 vs the 3,290/2,672 baseline), but the
  gate's rule is per-sniff growth, so `lint.sh` fails.
- Next action: fix the ~16 residual instances (mostly docblocks and translator
  comments) or record a **deliberate** baseline regeneration in a separate,
  approved change — never silently.

**M2 — Commemorative assets load on the landing pages all year, contradicting the
literal §7.9 rule, and the exception is undocumented.**

- r10 §7.9: "With no active event, **zero commemorative CSS/JS/images** are
  emitted."
- Measured live: `/natal/` and `/en/christmas/` return 200 with
  `commemorative.js`, `christmas.js`, `christmas.css` and 5 modules **while no
  event is active** (the browser JSON shows the same). This is deliberate
  (archival, §7.13) and is why landing resolution does not require an active event.
- Gate (a) only inspects the homepage/internal route, and
  `docs/commemorative-events.md` §2 states the no-event-zero rule without the
  landing exception, so a reader (or a future gate author) will treat it as
  absolute.
- Next action: document the exception in `docs/commemorative-events.md` §2/§3 and
  in the gate description — or gate the landing assets behind the window if the
  archived page should be asset-free.

**M3 — The reports' "no commit / dirty worktree" preservation record is stale, and
the commit history misdescribes the change.**

- The reports were accurate when written; they no longer describe the repository.
  The work is committed in two commits and the branch is unpushed.
- `657c334` ("Add tests for commemorative presentation dispatch and seasonal
  Halloween effects") contains the entire Stage 4–6 implementation, documentation
  and evidence (105 files, +14,593/−1,909); release provenance and review will read
  that message.
- `docs/reports/README.md` still labels the Stage 0–3 report "**(working tree, no
  commit)**", now permanently false.
- Next action: no history rewrite (out of scope and forbidden here). Record the
  report → commit mapping in the release notes and correct the index wording in a
  later documentation change.

**M4 — r10 §3.2/§3.7 REST deviation is stronger than the spec but is not recorded
as a deviation.**

- r10 specified `show_in_rest => true` with explicit unauthenticated **and**
  authenticated REST tests. The implementation sets `show_in_rest => false` and
  removes the route. The Stage 4–6 report correctly records this as **defect D2**
  ("the CPT was readable through REST … fixed"), but neither report notes that the
  fix departs from the approved field table, nor that §9.8's "a user with the CPT
  capability can read what the editor needs" row is now not applicable.
- Security-wise the deviation is **better** (no route at all) and the CPT is edited
  through the classic editor; no functional loss was observed.
- Next action: one sentence in `docs/content-model.md` /
  `docs/plugins/conexao-commemorative.md` so the spec and the code stop disagreeing
  silently.

**M5 — Two Stage 6 acceptance items have no evidence.**

- r10 Stage 6 requires (a) verification of the manual-precedence control by an
  administrator **not** on the implementation team, and (b) "an overlapping pair of
  event fixtures used in local preview to demonstrate that the editor's manual
  preference selects the intended eligible event".
- Neither exists: the Stage 4–6 report itself lists (a) as unverified, and
  `final-dispatch-probe.txt` contains no precedence/overlap rows (only dispatch and
  asset probes). The behaviour is covered by the in-process resolution suite (27
  assertions), which is a different kind of proof.
- Next action: perform both in a local-only follow-up (no production write) and
  attach the transcript as evidence, or record them as accepted limitations.

### 5.4 Low / informational

| # | Finding | Detail | Suggested action |
|---|---|---|---|
| L1 | The preview cache-buster is predictable | `Preview::link()` sets `_ce_ts` to `time()` (one-second resolution), not a random token. `DONOTCACHEPAGE`/`no-store` are also sent, but r10 §5.4 asks for a token no shared cache can ever satisfy. | Use `wp_generate_password( 12, false )` (or a nonce-derived value) if the host bypass proves unreliable. |
| L2 | No dashboard summary | r10 §5.2's "Commemorative events: active now / next to start" summary widget does not exist; the list-screen notice shows the armed boundary instead. | Add it to the admin-ux config (`summary`) in a later change; low value, not a defect of behaviour. |
| L3 | `on_save()` always re-arms | `handle_meta_change()` correctly skips re-arming for `_ce_priority`/`_ce_year_precedence`, but `save_post` (priority 999) always calls `arm()`. Re-arming is idempotent (clear + schedule one) so there is no stacking, only a deviation from the §4.6 point 5 nuance. | Optionally skip `arm()` in `on_save()` when only ordering keys changed. |
| L4 | Broken documentation link | `docs/README.md` and `docs/reports/README.md` link to `2026-11-03-commemorative-events-architecture-audit.md`, which does not exist (the file is `…-proposal-r10-revised.md`). | Fix the two links in a documentation-only change; the drift gate checks index coverage, not targets. |
| L5 | Stale index wording | `docs/README.md` still labels the plugin page "(r10 Stages 0–3)"; `docs/reports/README.md` still says "working tree, no commit". | Correct during the release-documentation pass. |
| L6 | Theme version unchanged | The theme is `1.0.2` although it gains a registry, a dispatcher, 10 templates, 8 assets and 12 test suites. Cache-busting is `filemtime`-based (`conexao_asset_version()`), so no stale-asset risk was found, but the release identity does not move. | Bump the theme version in the release commit. |
| L7 | Landing modules are uncached | `conexao_commemorative_module_from_records()` issues one `get_posts()` per referenced record (~14 on the Christmas landing page) with object caching but no transient; no measured query-count or budget evidence exists (§7.9 asks for measurement). | Measure and, if needed, add a language-scoped transient with explicit invalidation. |
| L8 | Part of the purge is a no-op | `invalidate()` deletes `conexao_commemorative_active` / `…_evaluated` transients that **nothing writes** (the engine memoises per request). Effective invalidation therefore depends entirely on `conexao_commemorative_purge_caches`, which has **no listener** in the repository. This sharpens the Option B statement rather than contradicting it. | Say so explicitly in `docs/plugins/conexao-commemorative.md`; the production integration must implement the action. |
| L9 | A zero-placement package can win and render nothing | The resolver accepts any *registered* package; `new_year`, `st_patricks_day`, `valentines_day`, `easter` are registered with `placements => []`. If one of them ever overlaps an implemented package, it suppresses the experience entirely. No current seeded window overlaps. | Document as a content-calendar rule (do not mark a planned package as the winner for an overlapping year), or add a "renders nothing" diagnostic to the list. |
| L10 | Documentation wording on schedulers | `assets/js/commemorative.js` says "no package can ship a second scheduler", but Halloween legitimately runs its own (approved) navigation scheduler in `halloween.js`, and its ambient layer is driven by that scheduler rather than `bootAmbientPlacement()`. The behaviour is correct and documented as an exception in the architecture doc; only the shared file's comment overstates. | Re-word the comment to name the single approved exception. |

## 6. Report accuracy

### 6.1 Claims confirmed

| Claim | Verification |
|---|---|
| Six engine suites, 209 assertions (41/40/25/20/27/56) | Evidence and code agree |
| Theme suites: dispatch 99, packages 131, ambient 53, landing 45, gates 5/5/11/6/7/4 | **all re-run live, identical numbers, exit 0** |
| Halloween nav 114/0 (was 102/5); presentation 33/0 (was 26/0) | **both re-run live** |
| Node runtime gate: 11 cases / 22 assertions | **re-run live: 22 passed, 0 failed** |
| `main.js` −24,160 B; `main.css` −17,248 B; `dark-mode.css` −1,689 B | **measured: 121,402→97,242 and 228,671→211,423 (exact); "halloween" absent from all three** |
| Halloween cadence 20,000/30,000 and 25,000/35,000 | **present at `halloween.js:170-173`** |
| 62 changed paths at the Stage 4–6 finish | **the commit contains exactly those paths** (105 files after expanding directories) |
| 9 stashes preserved | **9 at start and end** |
| Landing page: 5 modules, self-canonical, real hreflang pair, 200 PT/EN | **confirmed live** and in `browser-review.json` |
| No-event routes emit zero commemorative bytes | **confirmed live on `/` and `/guias/`** |
| CPT has no route and no REST route | **confirmed live (404/404)**; gate 11/0 |
| Bogus-nonce preview renders nothing anonymously | **confirmed live** |
| Boundary armed at the Christmas start (`2026-12-10`) | **confirmed in the live DB option** |
| The 2 "new" `translation_completeness` page violations are the pre-existing `/empregos/` pages | **confirmed** (`pre-existing-data-conditions.txt`, records last modified 2026-10-21) |
| PHPCS categories did **not** grow in Stage 4–6 | **confirmed: identical counts in `lint.txt` and `final-lint.txt`** |

### 6.2 Partially confirmed

| Claim | Reality |
|---|---|
| "No commit, no push, no deploy; working tree dirty" | True when written; **now committed twice**, still unpushed. The index still says "working tree, no commit". |
| "PHPStan `[OK] No errors`" (Stage 0–3) | The **committed Stage 0–3 evidence** (`phpstan.txt`) records 15 errors and `EXIT=1`; the later `final-lint.txt` shows `[OK] No errors`. The code is clean **now**; the Stage 0–3 evidence documents an earlier failing run and was never updated. |
| "Sitemap proven in-process because Jetpack answers `/sitemap.xml`" | Accurate, **and stronger than stated**: the Jetpack response is an index that lists no pages, so the HTTP row is vacuous and the theme producer never runs in any observed environment. |
| "Four pre-existing red checks unchanged" | Three unchanged; **`i18n_freshness` is now green** (§6.3). |
| "Production cache check remains Option B" | Accurate; additionally, the in-repo purge portion is a no-op (L8), so the guarantee rests entirely on an unimplemented host action. |

### 6.3 Contradicted / newly resolved

| Claim | Finding |
|---|---|
| Stage 0–3: "the PHPCS baseline drift (my files add zero violations)" | **Contradicted by its own evidence** — 1 new sniff + 5 growths, ~16 new instances (M1). |
| Stage 4–6: "the aggregate stays red because of pre-existing content/lint debt" | The aggregate did not complete at all (40 suites pass, then stall). §6.1 is careful; the summary sentence is looser than the evidence. |
| (new) `i18n_freshness` "cannot exist before a commit" | **Resolved by the commit.** Re-run at HEAD: `fresh`, `3 passed, 0 failed`, `GATE-RESULT status=pass`. Current tally **13 pass / 1 fail**, not 12/2. |
| (new) `verify-stage8-release-consistency` "inherited from uncommitted version changes" | Still true in substance: `dist/release.json` (git-ignored, not committed) records tag `v2026.11.06`, branch `leisure-location`, sha `83082ed`, data-model `1.6.3`, and **no `conexao-commemorative` artifact**, while the code has data-model `1.7.0` and admin-ux `1.0.9`. A future release must rebuild it. |

## 7. Test and evidence summary (exact numbers, layers separated)

**Layer 1 — commemorative engine, in-process PHP (recorded; NOT re-run: they write records)**

| Suite | Assertions |
|---|---|
| `test-commemorative-admin` | 41 |
| `test-commemorative-cache-boundary` | 40 |
| `test-commemorative-migration` | 25 |
| `test-commemorative-preview` | 20 |
| `test-commemorative-resolution` | 27 |
| `test-commemorative-schedule` | 56 |
| **Total** | **209 passed, 0 failed** |

**Layer 2 — theme presentation, in-process PHP (re-run by this review, exit 0 each)**

| Suite | Assertions |
|---|---|
| `test-commemorative-presentation-dispatch` | 99 |
| `test-commemorative-packages` | 131 |
| `test-commemorative-ambient` | 53 |
| `test-commemorative-landing-page` | 45 |
| `test-commemorative-gate-assets` | 5 |
| `test-commemorative-gate-public-activation` | 5 |
| `test-commemorative-gate-cpt-privacy` | 11 |
| `test-commemorative-gate-single-package` | 6 |
| `test-commemorative-gate-ambient-decoration` | 7 |
| `test-commemorative-gate-placement-assets` | 4 |
| `test-seasonal-halloween-nav-effects` | 114 |
| `test-seasonal-halloween-presentation` | 33 |
| **Total** | **513 passed, 0 failed** |

**Layer 3 — whole theme PHP layer** (recorded): 63 suites, **53 pass / 10 fail**,
**3,685 assertions passed / 37 failed**. Failing set:
`test-blog-en-translation`, `test-en-jobs-shared-slug`, `test-event-filter-cache`,
`test-guide-en-translation`, `test-job-en-translation`, `test-jobs-en-language`,
`test-stage33-bilingual`, `test-stage41-rest-language`, `test-stage45-pages`,
`test-translation-completeness` — the baseline set **minus** the Halloween nav
suite, so no new failure is attributable to this work. Not re-run.

**Layer 4 — permanent gates** (each gate re-run individually; the aggregate script
was not run because it writes `gate.json`)

| Gate | Result |
|---|---|
| `taxonomy_policy` | **PASS** 18/0 |
| `translation_completeness` | **FAIL** 21 passed, 2 failed, 3 violations (1 pre-existing, 2 "new" = the pre-existing `/empregos/` pages) |
| `cache_scoping` | **PASS** 9/0 |
| `cache_scoping_static` | PASS (recorded) |
| `redirect_precedence` | **PASS** 15/0 |
| `documentation_drift` | **PASS** 14/0 |
| `i18n_freshness` | **PASS** 3/0 — **improved from the reports' FAIL** |
| `commemorative_no_asset_without_event` | **PASS** 5/0 |
| `commemorative_no_public_activation` | **PASS** 5/0 |
| `commemorative_cpt_privacy` | **PASS** 11/0 |
| `commemorative_single_package` | **PASS** 6/0 |
| `commemorative_ambient_decorative` | **PASS** 7/0 |
| `commemorative_placement_assets` | **PASS** 4/0 |
| `commemorative_ambient_runtime` (Node) | **PASS** 22/0 (11 executed cases) |
| **Aggregate** | **13 pass / 1 fail** at HEAD (reports recorded 12/2 pre-commit) |

**Layer 5 — script contract** (3 re-run, rest recorded): `verify-script-conventions`
**779/0** (re-run), `verify-documentation-drift` **14/0** (re-run),
`verify-commemorative-runtime` **22/0** (re-run); the recorded script layer finishes
19 pass / 2 fail (`verify-i18n-freshness` — now green; and
`verify-stage8-release-consistency` — still red for the stale `dist/release.json`).

**Layer 6 — HTTP acceptance** (recorded; NOT re-run as a suite, but 10 of its rows
were re-probed live and agree): `verify-commemorative-http` **23/0** (12 matrix rows
+ 11 authenticated preview rows). `verify-guides-en-http` 20/0. Failures:
`verify-event-occurrence-http` (2 Laois fixture rows), `verify-release-http`
(/empregos/ 302), `verify-routing-http` (5 /empregos/ rows) — all pre-existing and
all caused by the local `/empregos/` page carrying Polylang language `en`.

**Layer 7 — browser**: `browser-review.json` **50 passed / 0 failed**, Chromium
151.0.7922.34, 9 screenshots. Contrast 9.36–16.23:1 (light) and 13.67–15.82:1
(dark); ambient node `ariaHidden: true`, `pointerEvents: none`, `focusable: 0`;
reduced motion: 0 ambient children; no console errors; no horizontal overflow.

**No double counting.** 209 (engine) + 513 (theme presentation) are separate
layers. The Stage 4–6 "366" figure sums only the ten new theme suites
(99+131+53+45+5+5+11+6+7+4) and is consistent. The 63-suite theme layer includes
the ten new theme suites but **not** the plugin suites.

**The aggregate is not green and is not claimed green.** Two attempts are recorded:
25 suites all PASS, then 40 suites all PASS, then a stall inside
`conexao-event-importer`. The report names `test-import-continuation` and
`test-source-fetch-failure`, but the recorded attempt actually **completed**
`test-import-continuation` (272/0) and stopped later, so the exact stalling suite is
not conclusively identified by the evidence — an evidence-quality nit, not a code
issue.

## 8. Known limitations and unverified items

| Item | State after this review |
|---|---|
| **Independent admin verification of the annual-precedence control** | **Still unverified.** No evidence of a second person, and no local preview demonstration of an overlapping pair (M5). |
| **Production cache behaviour (Option B)** | **Still unverified.** The guarantee is honestly stated; the in-repo purge is partly a no-op and the host hook has no listener (L8); production TTL/edge behaviour cannot be measured from this repository. |
| **Sitemap end-to-end verification** | **Now positively a gap, not only unverified** (H1): Jetpack serves `/sitemap.xml` (index with no page entries) so both the HTTP row and the theme producer are bypassed. Must be checked against production and may require a code change. |
| **Automated accessibility score** | **Not produced.** No axe-core or Lighthouse run exists; evidence is Playwright-based measurement (contrast, focus order, reduced motion) — real, but not a WCAG conformance claim. |
| **Aggregate runner** | **Still stalls** in `conexao-event-importer` suites in this database (40 suites pass first). Not re-attempted. |
| **Plugin in-process suites** | **Not re-run** by this review (they write records). Their recorded evidence and code were reviewed; the counts are internally consistent, but they were not re-proved here. |
| **PHPCS** | **Red** on per-sniff growth attributable to this project (M1). |
| **PHPStan** | **Green** (`[OK] No errors` in `final-lint.txt`) — fixed after the Stage 0–3 evidence snapshot. |
| **`translation_completeness`** | **Red**, on two `/empregos/` pages created/modified 2026-10-21 (pre-existing local data, not this work). |
| **`i18n_freshness`** | **Green at HEAD** (was red pre-commit). |
| **`/empregos/` acceptance rows** | **Red**, pre-existing (page 22200 is Polylang `en` while the site default is `pt`). |
| **Event-fixture acceptance rows** | **Red**, pre-existing (Laois weekly/all-day fixtures absent from the archive). |
| **Mobile layout / light-dark contrast** | **Verified** at 390×844 and in dark mode by the recorded browser run; not re-captured. |
| **Production deployment, activation or release** | **Not attempted, not authorised, not claimed.** |

## 9. Release-readiness checklist

| # | Item | State | Basis |
|---|---|---|---|
| 1 | r10 data model + engine implemented and locally verified | **READY** | 209 engine + 513 theme assertions; live probes |
| 2 | One-event / one-package / route-specific dispatch; decorations safe | **READY** | gates 6/0, 7/0; dispatch 99/0; browser evidence |
| 3 | Conditional assets on ordinary routes | **READY** | gates (a)+(f); live 0 bytes on `/` and `/guias/` |
| 4 | Landing pages PT/EN, canonical, hreflang, 5 modules | **READY** | live HTTP + browser |
| 5 | Global bundles free of Halloween code and smaller | **READY** | measured byte counts and grep |
| 6 | Halloween functional behaviour + approved cadence | **READY** | 114/0 + 33/0 live |
| 7 | Preview authorisation and cache bypass | **READY (local)** / **NOT VERIFIED (host)** | gate 5/0 + HTTP 23/0; host bypass unverified |
| 8 | Activation-boundary cache invalidation | **READY (local)** / **NOT VERIFIED (production)** | armed boundary in the DB; Option B |
| 9 | **Landing-page sitemap exclusion** | **BLOCKED** | H1 — Jetpack serves the sitemap; exclusion not in effect and unverified in production |
| 10 | **Polylang single-owner invariant enforced** | **BLOCKED** | H2 — no meta-copy exclusion; EN editor writable |
| 11 | Permanent gates | **READY with 1 known red** | 13/14 pass; `translation_completeness` red on pre-existing data |
| 12 | Lint | **BLOCKED** | M1 — PHPCS per-sniff growth from this project |
| 13 | Aggregate test runner green | **BLOCKED** | stalls in unrelated importer suites; layers verified separately |
| 14 | Independent admin/precedence verification | **NOT VERIFIED** | M5 |
| 15 | Accessibility review (non-automated) | **READY** | 50/0 browser checks, contrast measured; no axe/Lighthouse |
| 16 | Documentation, registry, runbooks, playbooks | **READY** (two index defects) | drift 14/0, registry OK; L4/L5 |
| 17 | Release manifest consistent with the code | **BLOCKED** | `dist/release.json` stale (1.6.3 / no commemorative artifact) — rebuilding is part of any release |
| 18 | Report/code state agreement (HEAD, commit, evidence) | **NEEDS UPDATE** | M3, §3.1 |
| 19 | Production deployment / activation | **NOT VERIFIED / NOT AUTHORISED** | out of scope |

## 10. Next-step recommendation

### 10.1 Local code/documentation remediation (no production access needed)

Ordered by value; every item is small and independently reviewable.

1. **H2** — exclude `SHARED_META_KEYS` from Polylang's meta copy
   (`pll_copy_post_metas`), render those fields read-only (or hide them) on
   non-canonical records, and register the PT/EN split as a **permanent gate**
   instead of a suite assertion. Add a regression test that creates an EN
   translation and asserts the split survives.
2. **H1 (documentation half)** — record in
   `tests/acceptance/matrices/commemorative.json` and `docs/testing.md` that the
   sitemap HTTP row is vacuous wherever Jetpack serves `/sitemap.xml`, and that the
   real proof is gate (c). The production half is §10.2.
3. **M1** — decide the PHPCS position: fix the ~16 instances, or regenerate the
   baseline as a **deliberate** change with the diff recorded.
4. **M2** — document the landing-page asset exception in
   `docs/commemorative-events.md` §2/§3 and in the gate description.
5. **M5** — perform the independent administrator check and the overlapping-pair
   preview locally; store the transcript under
   `docs/evidence/<date>-commemorative-precedence/`.
6. **M3 / L4 / L5 / L6** — one release-hygiene commit: fix the two broken index
   links, correct "working tree, no commit", bump the theme version, and record the
   report → commit mapping in the release notes.
7. **L1 / L3 / L7 / L8 / L10** — optional hardening and wording fixes; none blocks
   a release.

### 10.2 Separately authorised production verification

Only after §10.1, and only under `wp-production-operations`:

- confirm whether the **production sitemap** lists `/natal/` and `/en/christmas/`,
  and whether Jetpack or the theme serves it — this decides whether H1 needs a code
  change;
- confirm the **preview cache bypass** actually holds on WordPress.com (headers
  honoured, no cached preview page);
- measure the **effective maximum cache lifetime** for the Option B statement;
- confirm the **`conexao_commemorative_purge_caches`** integration exists (today the
  repository has no listener);
- confirm the theme/plugin versions and catalogues that will ship.

### 10.3 Release / deploy decision

**Not ready.** Do not plan a release until:

- H2 is fixed and gated;
- H1 is resolved (production sitemap verified, plus a code change if needed);
- the PHPCS position is decided (M1);
- `dist/release.json` is rebuilt so the manifest includes
  `conexao-commemorative`, data-model `1.7.0` and admin-ux `1.0.9`;
- the reports are corrected (M3) so the release record matches the repository.

The aggregate suite being red on **pre-existing** debt is not itself a release
blocker, provided the release notes cite the unchanged baseline failures and the
two importer stalls by name.

## 11. Code-change recommendation

**No speculative refactoring is justified.** The architecture is coherent and the
reuse/shared-engine discipline the engineering standard asks for is respected (the
plugin never touches the theme, the theme never recomputes scheduling, one
registry, one dispatcher, one scheduler helper, one registry generator).

The only changes recommended are those tied to a concrete finding:

- **H2** (a mechanism, not a refactor): Polylang meta-copy exclusion + read-only
  shared fields + a permanent gate.
- **H1** (verification and, only if production proves it necessary, a small
  exclusion for the serving sitemap producer).
- **M1** (either the ~16 lint fixes or a deliberate baseline regeneration).
- Documentation corrections for M2 / M3 / M4 / L4 / L5 / L10.

Everything in §5.4 otherwise is optional polish.

## Appendix — commands executed by this review

| Command | Result |
|---|---|
| `git rev-parse --abbrev-ref HEAD; git rev-parse HEAD; git status --short; git stash list` | start and end: `commemorative-events` / `657c334…` / clean → 1 untracked (this report) / 9 stashes |
| `git show --stat --oneline cfea1fa`; `git show --name-only 657c334` | commit contents; exact 62-path ↔ 105-file correspondence |
| `git log --oneline -6`; `git branch -vv` | two commits above `50ae318`; **no upstream** |
| `grep -c halloween assets/js/main.js assets/css/main.css assets/css/dark-mode.css` | `0 0 0` |
| `git show cfea1fa:…/main.js \| wc -c` vs `wc -c` | 121,402 → 97,242; main.css 228,671 → 211,423 |
| `docker compose exec -T wordpress php …/tests/<suite>.php` × 12 | dispatch 99/0, packages 131/0, ambient 53/0, landing 45/0, gates 5/5/11/6/7/4, nav 114/0, presentation 33/0 — **all exit 0** |
| `python3 tests/scripts/verify-commemorative-runtime.py` | **22 passed, 0 failed**, `status=pass`, exit 0 |
| `python3 tests/scripts/verify-documentation-drift.py` | **14 passed, 0 failed**, exit 0 |
| `php scripts/generate-registry-docs.php --check` | 11 plugins, 20 regions current, exit 0, zero writes |
| `python3 tests/scripts/verify-script-conventions.py` | **779 passed, 0 failed**, exit 0 |
| `python3 tests/scripts/verify-i18n-freshness.py` | **3 passed, 0 failed**, exit 0 (was FAIL pre-commit) |
| `docker compose exec … test-{taxonomy-policy,translation-completeness,cache-language-scoping,redirect-precedence}.php` | 18/0 pass; **21 pass / 2 fail (FAIL)**; 9/0 pass; 15/0 pass |
| `curl -s -o … -w '%{http_code}'` on `/`, `/guias/`, `/natal/`, `/en/christmas/`, `/commemorative_event/`, `/wp-json/wp/v2/commemorative_event`, `/sitemap.xml`, preview with a bogus nonce | 200 / 0 markers; 200 / 0; 200 / 6 markers; 200 / 6; **404**; **404**; 200 **Jetpack index (536 B)**; 200 / 0 |
| `curl … /natal/ \| grep hreflang/canonical` | self-canonical + `pt-BR`/`en`/`x-default`; 5 modules; `christmas.css` only |
| `docker compose exec db mysql -N -e "SELECT … post_modified_gmt >= '2026-11-06 11:30:00'"` | **0** rows (no content written by the review) |
| `… SELECT option_name, option_value … IN (conexao_commemorative_next_boundary, _last_purge, _seeded_keys, cron)` | boundary armed `2026-12-10T00:00:00+00:00` (christmas/start); seeded keys = all six |

_Read-only review. The only file written is this report. No code, content,
configuration, test, generated file or database state was changed; no commit,
push, deploy, release or production action was performed._

_Last verified: 2026-11-06 by the commemorative-events r10 final review._
