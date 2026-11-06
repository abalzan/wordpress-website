# Report — Commemorative Events architecture r10, Stages 0–3

| | |
|---|---|
| **Stage / task name** | Implement the Commemorative Events Architecture Proposal r10 — Stages 0–3 |
| **Date** | 2026-11-06 |
| **Author / agent** | Cline (implementation) |
| **Branch** | `commemorative-events` (no branch created, renamed or deleted) |
| **Starting SHA** | `50ae318b473da7cc973a08855e696130e8ee73f7` |
| **Working tree at finish** | modified/untracked only — **no commit, no push, no deploy** |
| **Preserved pre-existing work** | clean worktree at start; **9 stashes untouched** (`git stash list` unchanged) |
| **Skills followed** | `wp-add-content-type`, `wp-add-admin-screen`, `wp-write-in-process-test`, `wp-plugin-registry`, `wp-security-review`, `wp-update-docs` |
| **Authoritative specification** | [`docs/reports/2026-11-03-commemorative-events-architecture-proposal-r10-revised.md`](2026-11-03-commemorative-events-architecture-proposal-r10-revised.md) (r10) |
| **Plan** | [`docs/reports/2026-11-06-commemorative-events-stage-0-3-plan.md`](2026-11-06-commemorative-events-stage-0-3-plan.md) |
| **Evidence** | `docs/evidence/2026-11-06-commemorative-events/` |

## 1. Summary of implementation

r10 **Stages 0–3** are implemented: the event data model, the scheduling engine,
event records + administration + the seed migration, and secure preview + cache
handling. The engine is a new **theme-independent platform plugin**
`conexao-commemorative` (the r10 §2.3 recommendation); it never renders markup and
never knows a holiday, a template or an asset.

What the engine now does, with an injected clock and a reason for every decision:

- **Four window rules** (§4.1): `fixed_annual`, `variable_annual` (Gregorian
  Easter), `one_off` (never searching adjacent years) and `window` — including the
  **31 December wrap**, so New Year is the seven-day `12-26 → 01-01` window.
- **Inclusive boundaries** at `00:00:00` / `23:59:59` in `wp_timezone()`
  (`Europe/Dublin` fallback corrected from `Europe/Lisbon`, W14).
- **Occurrence-year identity** (§4.3, r10): the manual-preference key follows the
  *occurrence*, not `$now->format('Y')`. A wrapping window belongs to the year it
  **ends** in, so 2026-12-26 → 2027-01-01 is occurrence year **2027** throughout,
  including after midnight on 1 January.
- **Deterministic resolution** (§6): published → enabled → in window → known
  package → manual annual preference → `_ce_priority` descending → `_ce_event_key`
  ascending; one selected event, every eligible loser recorded as
  `superseded by "<event_key>"` (with `manual preference` when that decided it).
- **Manual annual precedence** (§5.7): one occurrence-year selector, one checkbox
  and one 200-character note on the event editor. It never changes enablement,
  priority or the schedule, and the admin list warns (flags only) when two events
  are marked preferred for the same year.
- **Signed preview** (§5.4): `?conexao_preview=<event_key>&_wpnonce=…&_ce_ts=…`
  honoured only when `is_user_logged_in()` AND `manage_options` AND `edit_post` on
  the canonical record AND a valid nonce for that key; silent failure,
  `DONOTCACHEPAGE` + `no-store` + `nocache_headers()`, a non-dismissible
  `data-preview` banner in the theme, and **nothing persisted**. It works for
  published, disabled, draft and out-of-window events.
- **Activation boundary** (§4.6): one armed boundary (`wp_schedule_single_event`,
  never stacked), a per-request due check as the honest **Option B** fallback, a
  targeted purge (this plugin's transients in **every** language + its
  object-cache group + the `conexao_commemorative_purge_caches` action; never
  `wp_cache_flush()`/`delete_expired_transients()`), a re-arm chain after every
  purge, a non-blocking auto-expiring lock, and an admin "Purgar caches
  comemorativos" action (capability + nonce).
- **Lifecycle correctness**: recalculation runs **after** persistence
  (`save_post_commemorative_event` at priority 999, `added/updated/deleted_post_meta`,
  `transition_post_status`, and `deleted_post` — **not** `before_delete_post`).
  `_ce_priority` and `_ce_year_precedence` invalidate the cache but **do not
  re-arm**; `_ce_enabled` and every scheduling key **do** re-arm.
- **Data model**: `commemorative_event` CPT with no public route/archive/sitemap,
  the `_ce_*` meta keys registered with sanitizers, `show_in_rest => false`, and
  the Polylang single-owner rule (shared configuration on the canonical PT record
  only; the EN record carries the identity and the translated copy).
- **Administration**: the admin-UX list (Estado, Janela calculada, Elegível
  agora?, Motivo, Prioridade — computed by the engine, with the **next
  occurrence** year) and the sectioned editor (Informação, Agendamento,
  Apresentação), plus the engine's diagnostics box (computed window, armed
  boundary, last purge, preview link, read-only date simulation).
- **Seed migration** (§8.2): `scripts/seed-commemorative-events.php` — idempotent,
  dry-run first, creating the six legacy occasions with identical windows (New
  Year corrected) and their EN records, never updating or deleting an existing
  record and never resurrecting a deleted one.

## 2. Files created, modified or deleted

### Created

`wp-content/plugins/conexao-commemorative/`: the plugin bootstrap
(`conexao-commemorative.php`), `includes/class-conexao-commemorative.php`,
`-schedule.php`, `-event.php`, `-repository.php`, `-resolver.php`, `-cache.php`,
`-preview.php`, `-admin.php`, `-migration.php`, `includes/functions.php`,
`languages/conexao-commemorative.pot`, and six suites under `tests/`
(`test-commemorative-{schedule,resolution,cache-boundary,admin,preview,migration}.php`).

| Path | Purpose |
|---|---|
| `wp-content/plugins/conexao-data-model/includes/class-conexao-data-model-commemorative.php` | the `_ce_*` schema, defaults and sanitizers |
| `wp-content/themes/conexao-br-irlanda/inc/commemorative-presentations.php` | theme-owned package registry + preview banner |
| `scripts/seed-commemorative-events.php` | the §8.2 seed |
| `docs/plugins/conexao-commemorative.md` | plugin documentation |
| `docs/reports/2026-11-06-commemorative-events-stage-0-3-plan.md` | the plan |
| `docs/reports/2026-11-06-commemorative-events-stage-0-3-implementation.md` | this report |
| `docs/evidence/2026-11-06-commemorative-events/*` | real command output |

### Modified

| Path | Change |
|---|---|
| `wp-content/plugins/conexao-data-model/conexao-data-model.php` | CPT + meta registration, `1.6.3` → **`1.7.0`** |
| `wp-content/plugins/conexao-admin-ux/includes/class-config.php` | the `commemorative_event` list/editor config |
| `wp-content/plugins/conexao-admin-ux/includes/class-list.php` | one generic `conexao_admin_ux_render_column` filter (the admin never computes scheduling) |
| `wp-content/plugins/conexao-admin-ux/includes/class-editor.php`, `class-fields.php` | the `_ce_name` virtual-title map entries |
| `wp-content/plugins/conexao-admin-ux/conexao-admin-ux.php` | `1.0.8` → **`1.0.9`** (asset constant too) |
| `wp-content/themes/conexao-br-irlanda/functions.php` | one `require_once` |
| `plugins.json` | the `conexao-commemorative` registry entry |
| `compose.yaml`, `scripts/build-plugins-zip.sh`, `AGENTS.md`, `README.md`, `docs/{plugins,deployment,project-inventory,architecture}.md`, `docs/plugins/conexao-*.md` | regenerated from `plugins.json` (`generate-registry-docs.php --write`) |
| `.github/workflows/ci.yml` | the plugin is activated in the integration job |
| `phpcs.xml.dist` | `conexao-commemorative` added to the allowed text domains |
| `scripts/README.md` | the seed row |
| `docs/content-model.md` | the `commemorative_event` section |
| `docs/README.md`, `docs/reports/README.md` | index entries |

**Deleted:** none.

## 3. r10 requirement → implementation map

| r10 requirement | Where |
|---|---|
| §3.2 CPT + fields (incl. `_ce_year_precedence`) | `class-conexao-data-model-commemorative.php` (`SHARED_META_KEYS`, `TRANSLATED_META_KEYS`, `defaults()`), `conexao-data-model.php::register_commemorative_post_type()` |
| §3.3 enabled vs post status | `Conexao_Commemorative_Resolver::evaluate()` (publish **and** enabled) |
| §3.5 validation/sanitisation | the `sanitize_*` methods; every key registered with a callback |
| §3.6 Polylang split | `Repository::canonical_id()/localised_post_id()/content()`; `Migration::create_translation()`; asserted in `test-commemorative-migration.php` |
| §3.7 REST lockdown | `show_in_rest => false` on every `_ce_*` key |
| §4.1 four rules | `Conexao_Commemorative_Schedule::resolve()` (`resolve_one_off`, `resolve_window`, `annual_occurrence`) |
| §4.2 inclusive boundaries + injected clock | `Schedule::clock()`, `annual_window()`, `contains()`; every public method takes `$now` |
| §4.3 nearest occurrence / wrap / **occurrence year** | `resolve()`, `resolve_window()` (`$wrapped ? $candidate + 1 : $candidate`), `next_window()` |
| §4.5 `02-29` rejected; Dublin fallback | `sanitize_month_day()`, `Schedule::timezone()` |
| §4.6 boundary, targeted purge, re-arm, lock, due check | `Conexao_Commemorative_Cache` (`arm`, `clear`, `invalidate`, `handle_scheduled_boundary`, `purge_due_on_request`, `maybe_purge_due`, `acquire_lock`) |
| §4.6 recompute **after persistence**; priority/precedence do not re-arm | `Cache::on_save` (priority 999), `handle_meta_change()` + `ORDER_ONLY_KEYS` |
| §4.6 `transition_post_status`, `deleted_post` (not `before_delete_post`) | `Cache::on_transition()`, `Cache::on_deleted()` |
| §5.2 list columns / §5.3 editor | `class-config.php::commemorative_event_config()`, `Admin::render_column()` |
| §5.4 preview (conjunctive) + cache bypass | `Conexao_Commemorative_Preview::requested()`, `bypass_caches()`, `link()` |
| §5.6 date simulation (read-only) | `Admin::render_simulation()` |
| §5.7 manual annual precedence | `Admin::render_precedence_control()`, `Admin::save_precedence()`, `Resolver::is_preferred()`, `Admin::preferred_flag_conflicts()` |
| §6.1 resolution order + reasons | `Resolver::evaluate/rank/active` |
| §6.5 preference uses the resolved occurrence year | `Resolver::evaluate()` (window occurrence year) + `is_preferred()` |
| §8.2 seed, no runtime fallback | `Conexao_Commemorative_Migration`, `scripts/seed-commemorative-events.php`; `Repository` reads only CPT records |
| §9.1 deterministic tests | all six suites inject the clock |

## 4. Tests and verification commands executed (real results)

Executed in this session against the **local** Docker WordPress (WordPress 7.1,
PHP 8.3 in the container). Raw output is in
`docs/evidence/2026-11-06-commemorative-events/`.

| # | Command | Result |
|---|---|---|
| 1 | the six suites individually (`docker compose exec wordpress php …/test-commemorative-*.php`) | `schedule 56`, `resolution 27`, `cache-boundary 40`, `admin 41`, `preview 20`, `migration 25` → **209 passed, 0 failed** |
| 2 | `./scripts/run-tests.sh --only conexao-commemorative` | 6/6 suites discovered and passed (component layer) |
| 3 | `./scripts/run-tests.sh --php` (whole in-process layer) | **2 failing suites, both pre-existing** — `conexao-event-importer/test-town-sanitization.php` and `conexao-event-runtime/test-event-query.php`; **every commemorative suite passes** and so does every other suite. Proven independent of this change: both fail identically with `conexao-commemorative` **deactivated** (`pre-existing-suite-failures.txt`). |
| 3a | `python3 scripts/verify-permanent-gates.py` | `AGGREGATE: FAIL` — 5 of 7 gates pass; `translation_completeness` fails on **pre-existing local data** (two EN pages without a PT master, one guide without EN — all last modified 2026-10-21), `i18n_freshness` fails only because the new `.pot` is untracked (`gate.json`, `permanent-gates.txt`, `pre-existing-data-conditions.txt`) |
| 4 | `./scripts/lint.sh` | PHP syntax **OK** (552 files); PHPStan **`[OK] No errors`**; PHPCS baseline gate **red on pre-existing drift** (§5) |
| 5 | `php scripts/generate-registry-docs.php --check` | `registry OK: 11 plugins validated, 20 generated regions current (zero writes).` exit `0` |
| 6 | `python3 tests/scripts/verify-script-conventions.py` | `770 passed, 0 failed` |
| 7 | `python3 tests/scripts/verify-documentation-drift.py` | `GATE-RESULT {"gate": "documentation_drift", "status": "pass", "passed": 14, "failed": 0, …}` |
| 8 | `python3 tests/scripts/verify-i18n-freshness.py` | 3 passed, **1 failed** — the new `.pot` has no commit time (untracked; §5) |
| 9 | seed `--dry-run` | `planned: create=6, skip=0` — **zero writes** |
| 10 | seed `--apply` then `--apply` again | `created=6, translations=6` → `created=0, skipped=6` (idempotent) |
| 11 | `./scripts/run-tests.sh --only conexao-commemorative` (HTTP layer) | 3 `/empregos/` acceptance rows fail on a **pre-existing** local data condition (§5) |
| 12 | live engine probe against the seeded local site (`live-engine-probe.txt`) | on 2026-11-06: `active=none`, boundary `christmas start @ 2026-12-10T00:00:00`, all six records `outside_window`; simulated at 2026-12-10 → `christmas`; at 2027-01-01 00:30 → `new_year` with `occ=2027` |

### What the suites prove (r10 §9 rows)

- **§9.2 scheduling (56)** — inclusive boundaries; custom lead-in; Easter
  (2026-04-05); one-off never searching adjacent years; the six New Year dates
  (`12-25` no; `12-26`, `12-31`, `01-01 00:00`, `01-01 23:59:59` yes; `01-02` no);
  occurrence year **2027** for every in-window instant incl. after midnight; the
  non-wrapping window year; a UTC instant resolving the same Dublin day; upcoming
  boundaries; the next-occurrence window; `02-29`/`04-31`/`13-01` rejected.
- **§9.2/§9.3 resolution (27)** — priority; `_ce_event_key` tie-break; manual
  preference beating a higher priority and **not** applying to the next year; two
  preferred events resolved by priority then key; the flag-conflict report;
  preference unable to select a disabled/draft/out-of-window event; unknown
  package; missing identity; the New Year preference applying across midnight.
- **§9.6 cache/boundary (40)** — one armed boundary; `_ce_priority` and
  `_ce_year_precedence` invalidate **without** re-arming (sentinel preserved);
  `_ce_enabled` and a scheduling key **do** re-arm; publish → draft and trash
  re-arm; unrelated transients **and** another object-cache group survive; the
  plugin's transients are cleared in every language; the host action fires once
  per purge; idempotent double purge; the re-arm chain; the due check purging a
  missed boundary then finding nothing due; a held lock skipping the purge; the
  admin action refusing a caller without capability or nonce.
- **§9.3 administration (41)** — the config exposes the five columns and three
  sections; the list filter is wired to the engine; the engine renders the next
  occurrence window/year; the precedence write/clear is year-scoped; the note is
  sanitised and truncated to 200; an out-of-range year and a `02-29` submission
  are refused with a clear warning; a bad nonce and a caller without `edit_post`
  write nothing.
- **§9.3 preview (20)** — the link carries the key + nonce + cache-busting token;
  logged-out refused; admin authorised; bad nonce and unknown key ignored;
  `manage_options` **without** `edit_post` refused; draft/disabled/out-of-window
  previewable; `DONOTCACHEPAGE`/`DONOTCDN` defined; the record byte-identical
  before and after.
- **§8.2/§9.7 migration (25)** — dry run writes nothing; apply creates the plan;
  the second run reports `created = 0`; the New Year record is the `window` rule
  with `12-26`/`01-01` and occurrence year 2027 on 31 December; the EN record
  carries the same key and **no other `_ce_*` row** (checked in `$wpdb->postmeta`);
  a permanently deleted event is not resurrected.

### Two defects the tests caught during implementation (fixed, not worked around)

1. **Stacking boundaries.** `arm()` originally scheduled the boundary *with args*,
   while `clear()` used `wp_clear_scheduled_hook( HOOK )` — WordPress only clears
   entries whose args match, so the clear was a no-op and boundaries could stack.
   The scheduler now registers a no-argument event (asserted by the re-arm-chain
   test).
2. **Language-filtered record reads.** `get_posts` with `lang => ''` still
   inherited Polylang's current-language filter in some contexts (the engine would
   see only EN records and lose the canonical configuration). The repository
   queries now set `suppress_filters => true` deliberately, and the canonical
   record is chosen explicitly (§3.6).

## 5. Skipped tests, limitations and honest non-passes

| Item | Status | Why |
|---|---|---|
| r10 **Stages 4–6** (experience-package dispatcher, Halloween multi-placement migration + visual refresh, second package + landing page, content playbooks, the new permanent gates) | **not implemented** | They depend on Stage 3 acceptance and are a separate change. The theme registers the six package **identities** only; the legacy Halloween presentation is untouched. This is the main reason the verdict is **PASS WITH LIMITATIONS**, not PASS. |
| r10 §9.5 new permanent gates (a)–(f) | **not added** | Stage 6 owns them; four of the six concern placement/asset dispatch, which does not exist yet. The in-process suites assert the equivalent invariants for what *is* built (no public route, no preference-driven activation, targeted-only purge). |
| Production cache verification (§4.6 point 8, Option A confirmation) | **not performed** | Production is WordPress.com and the host cache cannot be inspected from this repository. The documented guarantee is **Option B** ("at the first PHP request after the boundary, or within the verified TTL"), stated in the plugin doc and in the admin diagnostics. |
| `./scripts/lint.sh` PHPCS baseline gate | **red — pre-existing** | The gate reports six categories above the baseline (`NoDoubleNegative` 1, `ScopeIndent` +2, `VariableComment.Missing` +10, `SeparateFunctionsFromOO` +1, `InvalidClassFileName` +1, `MissingTranslatorsComment` +1). Re-aggregating the PHPCS report **excluding every file this change created or modified** yields the **same six deltas**, and every instance is in a legacy file (`inc/occasion.php`, `template-parts/leisure-card.php`, `class-leisure-transfer-admin.php`, …). The 2026-11-05 leisure report already recorded the same drift at `HEAD`. The baseline was **not** regenerated: growing it would hide the drift, and fixing legacy debt is out of scope. PHPStan is clean and PHP syntax is clean. |
| `verify-i18n-freshness.py` | **1 failure — mechanically caused by "do not commit"** | The gate derives freshness from **git commit time**; the new `conexao-commemorative.pot` (correctly regenerated with `scripts/i18n-make-pot.sh`) is untracked, so it has no commit time and the gate fails closed: *"could not determine the commit time … an unknown freshness signal is a FAILURE, never a pass"*. It will pass once the change is committed. No other catalogue is stale. |
| HTTP acceptance `/empregos/` rows (3) | **failing — pre-existing local data condition** | `/empregos/` returns `302 → /en/empregos/` because page **22200** in this local database is assigned Polylang language **`en`** while the site default is **`pt`** (`pll_get_post_language(22200) = 'en'`, `pll_default_language() = 'pt'`). This change adds no route, redirect or page language change. The suite's other acceptance rows pass. |
| Browser/visual verification | **not performed** | This stage renders no public experience, so there is nothing to inspect visually; that belongs to Stage 4. |
| REST exposure HTTP row (§9.8) | covered in-process by registration (`show_in_rest => false`) | No public route exists yet; an authenticated REST write is not part of Stages 0–3. |
| `./scripts/verify-permanent-gates.py` — `translation_completeness` | **fails on pre-existing local data** | 2 "new" violations, neither touched by this change: `page:en_without_pt:empregos` / `empregos-3` and `guide:carteira-motorista-brasileiros` missing its EN link. All three records were last modified **2026-10-21** (before this task's baseline, 2026-11-06) — see `pre-existing-data-conditions.txt`. The other five gates (`taxonomy_policy`, `cache_scoping` ×2, `redirect_precedence`, `documentation_drift`) pass. |
| Full `--php` layer — 2 failing suites | **failing — pre-existing** | `conexao-event-importer/test-town-sanitization.php` (one legacy junk `conexao_town` term `Co Clare Ireland`, term_id 2311) and `conexao-event-runtime/test-event-query.php` (date-dependent multi-day fixtures). Both were re-run with `conexao-commemorative` **deactivated** and fail identically (`pre-existing-suite-failures.txt`), so they are independent of this change. |

## 6. Deviations and decisions worth review

1. **Stage 1's "files: `functions.php` (load change)" is not taken as a rewrite of
   the theme engine.** The new engine is authoritative for the CPT, but the legacy
   `inc/occasion.php` and the Halloween presentation are untouched until Stage 4
   wires the dispatcher. Otherwise New Year would change the live front end without
   the presentation layer that consumes it.
2. **The annual-precedence control and the diagnostics live in the engine's own
   meta box**, not in the admin-UX config fields, because they are engine protocol
   (occurrence years, eligibility, cache boundary). The list/editor chrome still
   comes from `conexao-admin-ux`, and the engine answers the computed columns
   through one documented render filter, so the admin never computes scheduling.
3. **`_commemorative_event_status`** is the admin-UX's editorial bookkeeping meta
   (it mirrors `post_status` for every managed type). The engine never reads it:
   §3.3's single rule is `post_status === 'publish' AND _ce_enabled`.
4. **The presentation registry is minimal (labels only)** until Stage 4 ships the
   placement maps. `_ce_presentation` is therefore validated against the six
   registered keys, and an unknown key is stored as `''` (save time) and treated
   as non-displayable (read time) — defence in depth.
5. **`_ce_year_precedence` is stored as JSON text** (the r10 "JSON map") rather than
   a serialized PHP array, so a REST/CLI read never depends on PHP serialization
   and invalid JSON degrades to `{}`.
6. **The seed creates the linked EN record** (identity + translated title only)
   because §3.6/§8.2 require the pair. If Polylang is inactive it creates the PT
   record only and reports `translations=0`.

## 7. Pre-existing work and stash state

- The worktree was **clean** at the start (`git status --short` empty) on
  `commemorative-events` at `50ae318`.
- **9 stashes** existed before this task and are **unchanged**: no `git stash`
  apply/pop/drop/clear was run, and no stash contents were touched.
- Nothing was reset, cleaned, restored or discarded. No pre-existing change was
  overwritten. No unrelated file was modified: the shared-code edits are the
  admin-ux config/render hooks, the data-model registration, one theme
  `require_once`, `phpcs.xml.dist` (text domain), the registry/mount/CI entries
  generated from `plugins.json`, and the documentation index entries.
- The local database changed **only** in the ways this task requires: six
  `commemorative_event` records + six EN records from the seed, and the plugin was
  activated. No existing record, term, page, option or user was modified or
  deleted. (The migration suite initially deleted a pre-existing seeded record; it
  was restored, and the suite now deletes only records it created itself.)

## 8. Production / git confirmation

- **No commit** (`HEAD` is still `50ae318`), **no push**, **no deploy**.
- **No production write, no production activation, no production cache purge**, no
  WP-CLI/SSH/SFTP access, no database migration.
- The Flutter/mobile repository was **not touched**.
- All implementation changes remain in the working tree on
  **`commemorative-events`** for review.

## 9. Final status

**PASS WITH LIMITATIONS**

Stages 0–3 of r10 are implemented and verified with real numbers: **6 in-process
suites, 209 assertions, 0 failures**, PHPStan `[OK] No errors`, PHP syntax clean,
registry / script-contract / documentation-drift green, and the seed proven
idempotent (`created 6 → 0`). A live probe on the seeded site shows nothing active
on 2026-11-06, the boundary armed at the Christmas window start (2026-12-10), and
— simulated — `new_year` selected on 2027-01-01 00:30 with occurrence year **2027**.

The limitations are explicit and none of them is hidden: Stages 4–6 are **not
implemented**; the production cache guarantee is the honest **Option B** and is
unverified against WordPress.com; and the remaining red checks are all
**pre-existing** — the PHPCS baseline drift (my files add zero violations), the
`translation_completeness` gate and two event suites (proven identical with this
plugin deactivated), the `/empregos/` acceptance rows (a local page with
Polylang language `en`), and the i18n freshness signal that cannot exist before a
commit.




