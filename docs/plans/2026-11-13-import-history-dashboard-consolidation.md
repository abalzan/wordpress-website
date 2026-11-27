# Plan — Consolidate Import History into the Event Import dashboard

| | |
|---|---|
| **Task title** | Consolidate the Import History interface into the main Event Import dashboard and retire `conexao-import-history` |
| **Date** | 2026-11-13 |
| **Author / agent** | AI agent (Cline) |
| **Branch** | `master` |
| **Repository baseline (starting SHA)** | `e749bb0` (`Add regression tests for bulk actions on Event Sources`) |
| **Applicable skill(s)** | `wp-repository` (orientation, plan, scope), `wp-add-admin-screen` (admin surface change), `wp-write-in-process-test` (new regression suite), `wp-testing` (run/diagnose the contract), `wp-security-review` (redirect + capabilities + escaping), `wp-update-docs` (living docs + generated regions), `wp-plugin-registry` (version bump → regenerated registry docs) |
| **Plan status** | approved (implementation proceeded in the same session) |

## 0. Working tree at start

`git status --short` is **not clean**. Three files carry uncommitted work that
is **not mine** and must be preserved, untouched, and never swept into this
change's diff:

| Path | Pre-existing change |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php` | `apply_bulk_action()` rewritten to one `update_option()` per batch + new `persist_sources()` |
| `wp-content/plugins/conexao-event-importer/tests/test-bulk-actions.php` | new bulk-action regression assertions |
| `docs/plugins/conexao-event-importer.md` | bulk-action documentation sections |

This task touches `class-event-sources.php` in **disjoint regions**
(`register_admin_menu()`, the dashboard renderer, the history renderers). The
pre-existing hunks sit in `apply_bulk_action()` / `persist_sources()` and are
left byte-identical.

## 1. Scope

Move the complete Import History table (13 columns: Time, Source, Found, New,
Updated, Unchanged, Duplicates, Skipped, Errors, Status, Listing, Progress,
Cooldown) from the separate `admin.php?page=conexao-import-history` screen into
the main Event Import dashboard (`admin.php?page=conexao-event-import`), show the
full retained history (up to 100 rows) instead of the latest 10, delete the
duplicate renderer, unregister the retired submenu, redirect the retired URL to
the dashboard, and prove all of it with a new in-process suite, an updated
browser proof and updated living documentation.

## 2. Explicit non-goals

- **No change to import history storage or recording.** `Conexao_Import_History`
  keeps `OPTION_KEY = 'conexao_event_import_history'`, `MAX_ENTRIES = 100`,
  newest-first ordering and every field shape. No migration, no clearing, no
  rewrite of existing rows.
- **No change to `history_progress_text()`.** Its logic, wording and lifecycle
  claims are preserved exactly; the continuation regression suite keeps passing
  against it unchanged.
- **No change to Import Logs.** `conexao-import-log`, `Conexao_Import_Log`,
  `class-import-log.php`, `class-import-log-admin.php` stay exactly as they are;
  history and logs remain two separate concepts.
- **No change to the importer engine, source fetching/parsing, deduplication,
  event creation/updating, source registry, continuation scheduling or
  checkpoints.**
- **No new JavaScript.** The wide table is made usable with CSS overflow only.
- **No new translatable strings where an existing one will do**, and no rewriting
  of historical reports or captured HTML evidence that mention the old URL.
- Refusing tempting adjacent work: refactoring the whole dashboard into classes,
  paginating the history table, adding a "clear history" control, or unifying the
  history and log screens.


## 3. Relevant authoritative documents

| Fact | Owner |
|---|---|
| Scope, safety rules, what must not be touched | `AGENTS.md` |
| Engineering rules, MUST levels, definition of done | `docs/engineering-standard.md` (§0, §2.1, §4.2, §9, §12, §14) |
| Plugin purpose, admin surface, history/log split | `docs/plugins/conexao-event-importer.md` |
| Test model and suite conventions | `docs/testing.md` |
| Plugin registry (version source, docs path) | `plugins.json` |
| Script inventory / gate commands | `scripts/README.md` |
| Documentation index | `docs/README.md` |

No fact needs a new owner: the "the dashboard is the one place history is
shown" statement lives in the plugin document, which already owns the admin
surface description.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository` → `wp-add-admin-screen` + `wp-write-in-process-test` + `wp-update-docs` |
| **Its preconditions** | isolated test options; a plan; generated regions edited only through the generator |
| **Its guardrails that bind this task** | no production write; generated regions are never hand-edited; one renderer, no duplicated calculation; escaping on every output; capability check on the redirect; tests must isolate and restore operator options |
| **Its verification commands** | `./scripts/run-tests.sh --php --only conexao-event-importer`, `./scripts/lint.sh`, `php scripts/generate-registry-docs.php --check`, `./scripts/i18n-check.sh`, `python3 tests/browser/verify-import-continuation-browser.py [--negative-control]` |
| **Its evidence location** | `docs/evidence/2026-11-13-import-history-consolidation/` |

## 4. Current-state findings (read from the code, not assumed)

1. `Conexao_Event_Sources::register_admin_menu()` registers the top-level
   `conexao-event-import` menu, the `conexao-event-sources` submenu and a third
   submenu `conexao-import-history` bound to `render_history_page()`.
2. `render_import_dashboard()` renders: last successful import stat, `Sources`
   list, `Run Import` form, `Export Events` link, then calls
   `render_history_summary()`.
3. `render_history_summary()` reads `Conexao_Import_History::get_all()`, slices
   `array_slice( $history, 0, 10 )`, and renders a 10-column table with **no**
   Listing/Progress/Cooldown columns. With an empty history it prints
   `No imports yet.` and no heading.
4. `render_history_page()` renders the **complete** `get_all()` in a 13-column
   `widefat striped` table, preceded by a description paragraph linking to
   `admin.php?page=conexao-import-log`; empty state `No imports yet.`.
5. `history_progress_text()` is a public static helper used by the renderer **and**
   by `tests/…/test-import-continuation.php` (G18.14 and other assertions).
6. `Conexao_Import_Dashboard` is the thin component that adds
   `add_action( 'admin_menu', array( $sources, 'register_admin_menu' ) )` at
   plugin load.
7. WordPress core order (verified in the container, `wp-admin/admin.php` line
   163 vs line 180): `wp-admin/menu.php` is required **before**
   `do_action( 'admin_init' )`, and `menu.php` ends with
   `user_can_access_admin_page()` → `do_action( 'admin_page_access_denied' )` →
   `wp_die()`. An **unregistered** `page=` therefore never reaches `admin_init`;
   `admin_page_access_denied` is the hook that fires for it.
8. Repository-wide references to the old page slug: `class-event-sources.php`,
   `tests/browser/verify-import-continuation-browser.py`, and historical
   reports/evidence under `docs/reports/` and `docs/evidence/` (left as history).
9. Version: plugin header `1.9.1`; the registry-derived docs are generated from
   `plugins.json` (which deliberately carries no version).

## 5. Proposed approach

**One renderer.** Extract `render_history_table( array $history )` — public for
the same reason `history_progress_text()` is public: the regression suite drives
the real renderer instead of a copy. It renders the `<h2>` section heading, the
Import Logs description link, the empty state, and the complete 13-column table
inside a horizontal-overflow wrapper. `render_import_dashboard()` passes
`Conexao_Import_History::get_all()`. `render_history_summary()` and
`render_history_page()` are deleted — no second implementation survives.

**Retirement.** Delete the `conexao-import-history` `add_submenu_page()` call and
`render_history_page()`. The dashboard (`conexao-event-import`), Event Sources
and Import Logs registrations are unchanged.

**Compatibility redirect, implemented.** `maybe_redirect_retired_history_page()`
is hooked to `admin_page_access_denied` (the only hook that runs for an
unregistered admin page before WordPress 403s it), checks
`current_user_can( 'manage_options' )`, and `wp_safe_redirect()` + `exit`s to
`admin.php?page=conexao-event-import`. The decision itself lives in
`retired_history_redirect_url()` (pure: capability + slug → URL or `''`), so both
directions are testable in-process without faking `exit`. No page is registered
for it and no callback is retained: the old route cannot be reached as a page.
No loop is possible because the target page slug is not the retired slug, and a
non-administrator still receives the standard 403.

**Responsive layout, no JavaScript.** `.conexao-import-history-scroll` wrapper
with `overflow-x: auto` plus a `min-width` on the table and `white-space: nowrap`
on the header cells. The page never widens; every column stays reachable by
scrolling the table; `widefat striped` styling is preserved.

**Rejected options.** (a) Keep both pages and link between them — that is the
duplication the task removes. (b) Register the old slug as a hidden submenu to
host the redirect — explicitly forbidden, and it keeps the slug registered.
(c) Paginate the table — unnecessary at a hard cap of 100 rows, and it would add
an operator step. (d) Use `admin_init` for the redirect — it does not fire for an

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php` | new `render_history_table()`, delete `render_history_summary()` + `render_history_page()`, drop the submenu, add `retired_history_redirect_url()` + `maybe_redirect_retired_history_page()`, dashboard calls the new renderer | the consolidation itself |
| `wp-content/plugins/conexao-event-importer/includes/class-import-dashboard.php` | register `admin_page_access_denied` | the component that owns the admin surface arms the retirement redirect at plugin load |
| `wp-content/plugins/conexao-event-importer/assets/admin.css` | overflow wrapper + header wrapping rules | responsive 13-column table without JS |
| `wp-content/plugins/conexao-event-importer/conexao-event-importer.php` | version `1.9.1` → `1.10.0` (header + constant) | behaviour change in the admin UI |
| `wp-content/plugins/conexao-event-importer/tests/test-import-history-dashboard.php` | **new** in-process suite (Tests A–H) | regression proof |
| `tests/browser/verify-import-continuation-browser.py` | visit the dashboard, scope to the history table, assert 13 headers | the page under proof moved |
| `docs/plugins/conexao-event-importer.md` | admin surface + history sections rewritten for the new layout | living documentation |
| `docs/testing.md` | the browser proof now reads the dashboard | living documentation |
| `docs/plugins/README.md`, `docs/project-inventory.md`, `docs/architecture.md`, generated block in the plugin doc | regenerated by `scripts/generate-registry-docs.php --write` | version bump |

## 7. Files explicitly expected NOT to change

| Path | Why |
|---|---|
| `includes/class-import-history.php` | storage and recording are explicitly out of scope |
| `includes/class-import-log.php`, `includes/class-import-log-admin.php` | Import Logs stays a separate feature |
| `includes/class-conexao-import-continuation.php`, `class-source-*`, engine/source/deduplicator files | no importer behaviour change |
| `wp-content/plugins/conexao-event-runtime/**` | never touched by importer UI work |
| `plugins.json` | the plugin set, load order and class are unchanged |
| `docs/reports/**`, `docs/evidence/**` | historical record; not rewritten |
| `docs/plans/2026-11-13-event-sources-bulk-actions.md`, `tests/test-bulk-actions.php` | pre-existing work in progress |

## 8. Content / data impact

- Records created / updated / deleted: **none**. No post, term, meta or option
  write is performed by the change. The regression suite writes only isolated
  test options and restores them verbatim.
- Portable identifier strategy: not applicable (no content write).
- Does this write content? **No** — the six-step contract does not apply.
- PT content changed? **No.**

## 9. Route / HTTP impact

- Routes added: **none**.
- Routes removed: the **admin** screen `admin.php?page=conexao-import-history`
  (registered submenu + callback). It is replaced by a capability-checked
  `wp_safe_redirect()` to `admin.php?page=conexao-event-import`.
- Public routes, redirects, sitemap and the HTTP acceptance matrix: **unchanged**
  (no public URL is involved).
- Sitemap coverage: unchanged.

## 10. Polylang / English impact

- Translated post types / taxonomies affected: **none**.
- Is PT immutable in this change? **Yes** — no content is read for writing.
- B1/B2 behaviour, canonical, hreflang, language switcher, cache keys:
  **unchanged**.
- Completeness gate: unaffected.

## 11. Security impact

- Capabilities checked: `manage_options` in `render_import_dashboard()` (existing)
  and in the new redirect path.
- Nonces added/changed: **none** (a read-only GET redirect changes no state; the
  existing `conexao_event_sources_nonce` on the Run Import form is untouched).
- Sanitisation / escaping: `sanitize_key()` on the `page` value read for the
  redirect; every table cell keeps its existing `esc_html()` / `esc_attr()`.
- SQL: none added; no raw SQL.
- REST surface change: **none**.
- Secrets involved: **none**.

## 12. Performance impact

- Queries added or removed: **none**. The dashboard already read the same option;
  it now renders up to 100 rows instead of 10 (a hard cap), which is bounded
  output, not extra queries. No new option, transient or HTTP call.
- Caching: unchanged. Assets: one stylesheet rule set; no new file, no new JS.
- Expected measurable improvement: none claimed — the benefit is operator
  workflow (one screen instead of two), not a performance number.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | nobody; out of scope |
| What is the verification of the production result? | not applicable |


## 14. Test plan

- In-process suite: **new**
  `wp-content/plugins/conexao-event-importer/tests/test-import-history-dashboard.php`
  (Tests A–H below), plus the existing `test-import-continuation.php` kept green
  unchanged.
- Browser: `tests/browser/verify-import-continuation-browser.py` pointed at the
  dashboard, happy path + `--negative-control`.
- Script contract/registry/i18n gates: `generate-registry-docs.php --check`,
  `i18n-check.sh`, `./scripts/run-tests.sh --scripts`.
- Pass = 0 failures in the importer PHP suites, 0 errors in `./scripts/lint.sh`,
  0 drift in the registry gate, browser proof exit 0 and negative control exit 1
  (**red by design**).

| Test | Proves |
|---|---|
| A | 12 retained records render (not 10), newest first, oldest present, row count == retained count, 13 `<th>` |
| B | Listing, Progress, Cooldown **and** the ten summary columns are all rendered |
| C | the Progress cell equals `history_progress_text()` for the same row — one calculation, and `history_progress_text` is defined once |
| D | empty history renders `No imports yet.` with no warning/notice |
| E | a record missing `unchanged`, `skipped`, `complete`, `checkpoint`, `cooldown` renders the documented fallbacks with no warning/notice |
| F | the `admin.php?page=conexao-import-log` link is present |
| G | `conexao-import-history` is unregistered, `render_history_page()` gone, dashboard + Event Sources + Import Logs still registered, the retired URL resolves to the dashboard for an administrator and to nothing for a subscriber or for the dashboard itself |
| H | `MAX_ENTRIES`, the option key, newest-first ordering and the on-disk history are unchanged by rendering |

## 15. Acceptance matrix plan

No acceptance row is added or changed: no public URL is created, removed or
redirected. The admin screen is covered by the in-process suite and the opt-in
browser proof, which is where admin surfaces are proven in this repository.

## 16. Rollback plan

1. `git checkout --` the five plugin/test/doc files listed in §6 (or `git revert`
   the single commit once it exists).
2. The retired route comes back with the submenu registration — no data rewrite
   is needed in either direction, because **no data changed**.
3. Rollback does **not** restore anything else: history rows, `MAX_ENTRIES`, the
   option key and the recording path were never modified.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/plugins/conexao-event-importer.md` | dashboard owns the complete 13-column history; the dedicated page is retired; the redirect; storage/recording unchanged; Import Logs still separate | — |
| `docs/testing.md` | the continuation browser proof reads the dashboard | — |
| `docs/plugins/README.md`, `docs/project-inventory.md`, `docs/architecture.md` | regenerated version strings | generator |
| `docs/plans/…`, `docs/reports/2026-11-13-import-history-consolidation.md` | this plan + the completion report | — |

## 18. Release implications

- Component version bump: `conexao-event-importer` `1.9.1` → `1.10.0` in the
  plugin header **and** the `CONEXAO_EVENT_IMPORTER_VERSION` constant (the
  constant is what cache-busts `assets/admin.css`, so a CSS-only change requires
  it).
- Artifact allowlist: unchanged (`plugins.json` unchanged).
- A release/deploy is **not** in scope; no ZIP is built for this task.

unregistered `page=` (see finding 7), so the redirect would silently never run.


## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| A second history calculation reappears | medium | one renderer; Test C compares the cell to the production helper and asserts a single definition |
| Retired URL 403s instead of redirecting | medium | hook chosen from verified core order (`admin_page_access_denied`), plus a live HTTP check against the local stack |
| Old rows trigger PHP notices in the wider table | medium | fallbacks preserved byte-for-byte; Test E installs an error handler and asserts zero notices |
| A 13-column table breaks the dashboard layout | medium | CSS overflow wrapper, no JS, manual browser check at two widths |
| Scope creep into bulk-action or importer code | medium | §7 list; only disjoint regions edited; pre-existing dirty hunks left untouched |
| Pre-existing dirty worktree confused with this change | medium | baseline recorded before editing; report separates them |

## 20. Verification gates

1. `./scripts/run-tests.sh --php --only conexao-event-importer` → 0 failed suites
   (except the **pre-existing** `test-bulk-actions.php` failure recorded in §22).
2. `./scripts/run-tests.sh --scripts` → 0 failed script-contract suites.
3. `./scripts/lint.sh` → PHP syntax + PHPCS + PHPStan clean for changed files.
4. `php scripts/generate-registry-docs.php --check` → no drift.
5. `./scripts/i18n-check.sh` → catalogue not stale (strings reused where possible).
6. `python3 tests/browser/verify-import-continuation-browser.py` → exit 0.
7. `python3 tests/browser/verify-import-continuation-browser.py --negative-control`
   → exit 1 (red by design).

## 21. Completion criteria

Restated from `docs/engineering-standard.md` §14: the dashboard is the single
place history is shown, with all 13 columns and the full retained history; the
retired page is gone from the menu with no second renderer; no storage or
recording behaviour changed; Import Logs untouched; the continuation browser
proof passes on the new location and its negative control still goes red; the
gates in §20 report real numbers in the report; no production write occurred and
the working tree is left for review.

## 22. Baseline captured before editing

`./scripts/run-tests.sh --php --only conexao-event-importer` was run on the
starting working tree (same three pre-existing dirty files, nothing of this task
present). It is the comparison point for the report:

| Suite | Baseline |
|---|---|
| `test-bulk-actions.php` | **FAILED** — pre-existing, from the uncommitted bulk-persistence work (`nothing reported updated on failed delete`, `src_a still present`, …). Not caused by this task; out of scope. |
| every other importer suite | passed |

The report compares against exactly this baseline and does not "fix" the
pre-existing failure.
