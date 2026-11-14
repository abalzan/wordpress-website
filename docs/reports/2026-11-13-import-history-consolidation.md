# Report — Import History consolidated into the Event Import dashboard

| | |
|---|---|
| **Stage / task name** | Consolidate Import History into the main Event Import dashboard; retire `conexao-import-history` |
| **Date** | begun 2026-11-13, verified 2026-11-14 |
| **Author / agent** | AI agent (Cline) |
| **Branch** | `master` |
| **Start SHA** | `e749bb03ece0f9baf01c18fcb6a9e6349398f9ac` |
| **Final SHA** | `e749bb03ece0f9baf01c18fcb6a9e6349398f9ac` (no commit, by instruction) |
| **Working tree at finish** | **dirty by design** — 11 modified + 2 added files of this task, plus one untracked theme file that is **not** part of this task (§13) |
| **Skills followed** | `wp-repository`, `wp-add-admin-screen`, `wp-write-in-process-test`, `wp-testing`, `wp-security-review`, `wp-update-docs`, `wp-plugin-registry` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/testing.md`, `docs/content-model.md`, `docs/routing.md`, `docs/plugins/conexao-event-importer.md`, `plugins.json`, `docs/templates/plan.md`, `docs/templates/report.md` |
| **Plan** | [`docs/plans/2026-11-13-import-history-dashboard-consolidation.md`](../plans/2026-11-13-import-history-dashboard-consolidation.md) |

## 1. Scope completed

Delivered, mapped to the request:

1. **One history screen.** `Conexao_Event_Sources::render_import_dashboard()`
   (`admin.php?page=conexao-event-import`) now ends with the complete Import
   History table, below Sources / Run Import / Export Events. Section order:
   Event Import heading → Import Summary → Sources → Run Import → Export Events
   → Import History.
2. **Full retained history.** `render_history_table( Conexao_Import_History::get_all() )`
   renders every stored row (up to `MAX_ENTRIES = 100`), newest first — not the
   old `array_slice( …, 0, 10 )`.
3. **All 13 columns preserved, in order:** Time, Source, Found, New, Updated,
   Unchanged, Duplicates, Skipped, Errors, Status, **Listing**, **Progress**,
   **Cooldown**.
4. **One renderer.** `render_history_table()` replaces the old 10-column
   `render_history_summary()`; both it and `render_history_page()` are deleted.
   No live reference to either survives outside the test that asserts their
   absence.
5. **Progress semantics unchanged.** The Progress cell still calls
   `Conexao_Event_Sources::history_progress_text()` — the function the
   continuation suite drives. No second calculation exists.
6. **Listing / Cooldown unchanged.** `complete` / `incomplete` with a missing
   historical `complete` treated as complete; cooldown shows cooling-down
   (reason + seconds), cleared (last reason) or `—`.
7. **Retired page.** The `conexao-import-history` submenu registration and the
   page renderer are gone. The retired URL is redirected to the dashboard for
   `manage_options` users by `maybe_redirect_retired_history_page()`, hooked to
   `admin_page_access_denied` (verified in core: `wp-admin/includes/menu.php:382`
   fires it immediately before `wp_die()`), using `wp_safe_redirect()` + `exit`.
   `retired_history_redirect_url()` is a pure decision, refuses to redirect the
   dashboard itself (no loop) and refuses non-administrators.
8. **Import Logs untouched and still linked.** `conexao-import-log`,
   `Conexao_Import_Log`, `class-import-log.php`, `class-import-log-admin.php` and
   their page are unchanged; the history section keeps the “For detailed import
   logs (including failures and API errors), see the **Import Logs** page.” link.
9. **Responsive, no JavaScript.** `.conexao-import-history-scroll`
   (`max-width:100%; overflow-x:auto`) wraps the `widefat striped` table, whose
   `min-width:1080px` makes narrow windows scroll the table instead of widening
   the page. No column is hidden.
10. **Browser proof relocated, not weakened.**
    `verify-import-continuation-browser.py` opens the dashboard, selects
    `table.conexao-import-history` by the class the renderer emits, and
    additionally asserts **13 headers** and that Listing/Progress/Cooldown are
    the last three. Its phases, assertions, negative control and “browser closed
    while the server continues” proof are intact.
11. **Regression coverage.** New `tests/test-import-history-dashboard.php`
    (Tests A–H), 74 assertions.
12. **Version + generated docs.** `conexao-event-importer` 1.9.1 → **1.10.0**
    (header + `CONEXAO_EVENT_IMPORTER_VERSION`, which cache-busts `admin.css`),
    registry docs regenerated and verified with zero drift.

## 2. Scope NOT completed

None of the requested work is missing. Deliberately **not** done: history
pagination, a “clear history” control, or a dashboard class refactor (tempting
while extracting the renderer); any change to how history is recorded or stored
(§5); any release, artifact publishing, deploy or production action.

## 3. Files added / modified / deleted

**2 added, 11 modified, 0 deleted** (plus this report and its evidence).

| Path | Change | Note |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php` | modified | one `render_history_table()`; `render_history_summary()` + `render_history_page()` deleted; submenu removed; redirect pair added; dashboard calls the renderer. Also carries the **pre-existing, not-this-task** `apply_bulk_action()`/`persist_sources()` hunks |
| `wp-content/plugins/conexao-event-importer/includes/class-import-dashboard.php` | modified | `admin_page_access_denied` wiring for the retirement redirect |
| `wp-content/plugins/conexao-event-importer/conexao-event-importer.php` | modified | version 1.9.1 → 1.10.0 (header + constant) |
| `wp-content/plugins/conexao-event-importer/assets/admin.css` | modified | overflow wrapper + table readability rules |
| `wp-content/plugins/conexao-event-importer/tests/test-import-history-dashboard.php` | **added** | Tests A–H, 74 assertions |
| `tests/browser/verify-import-continuation-browser.py` | modified | reads the dashboard table; adds 13-column assertions |
| `docs/plugins/conexao-event-importer.md` | modified | new layout, retirement, unchanged storage; plus **pre-existing** bulk-action sections |
| `docs/testing.md` | modified | browser proof reads the dashboard; new in-process suite named |
| `docs/architecture.md`, `docs/plugins/README.md`, `docs/project-inventory.md` | modified (generated) | version 1.10.0 |
| `wp-content/plugins/conexao-event-importer/tests/test-bulk-actions.php` | modified | **not this task** — concurrent bulk-action work present in the tree |
| `docs/plans/2026-11-13-import-history-dashboard-consolidation.md` | **added** | this task's plan |

`wp-content/` changes are limited to the importer plugin (UI, CSS, tests,
version) — no engine, source, dedup, export or runtime file.

## 4. Runtime impact

Yes, one admin screen changes: the Event Import dashboard now shows the full
13-column history instead of a 10-row summary, and the separate Import History
menu item is gone (its URL redirects for administrators). No front-end, REST,
cron or content behaviour changes. Import history storage, recording and
continuation scheduling are untouched.

### 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | confirmed |
| No production content, Polylang or database change | confirmed |
| No application behaviour change outside the admin history UI | confirmed (diff touches admin rendering, CSS, tests, version, docs) |
| No Flutter/mobile repository accessed | confirmed |
| No `.env` or secret change | confirmed |

## 5. Content / data impact

**None.** No post, term, attachment, meta or option row was created, updated or
deleted by the change. `Conexao_Import_History` is byte-identical
(`OPTION_KEY = 'conexao_event_import_history'`, `MAX_ENTRIES = 100`,
newest-first, `record()` unchanged); Test H asserts the key, the cap, the single
recording entry point, that rendering is a pure read, and that 101 recorded runs
still yield exactly 100 retained rows.

Local environment hygiene during verification only: synthetic `g18_probe_*`
sources/history rows and the 12 temporarily seeded display rows were removed or
restored; history is back to its pre-check state (0 rows) and no probe source
remains.

## 6. Polylang impact

**None.** No PT or EN record, term, slug or URL was touched. History rows carry
no language dimension. `0` records created or changed.

## 7. Route / HTTP impact

- Admin route removed: `wp-admin/admin.php?page=conexao-import-history` is no
  longer registered (no submenu, no page hook, no callback).
- Admin redirect added (administrators only):
  `…?page=conexao-import-history` → `…?page=conexao-event-import`
  (`admin_page_access_denied`, `wp_safe_redirect()`, `exit`).
- Public routes, canonical, hreflang, sitemap: unchanged.

Verified in a real browser at 1440px and 1024px: the retired URL lands on
`http://localhost:8080/wp-admin/admin.php?page=conexao-event-import` and that
page renders the history table; no `href` anywhere in the admin document points
at the retired slug.

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | |
| Deploy / upload | **no** | |
| Plugin activation | **no** | |
| Content or DB mutation | **no** | |

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `./scripts/run-tests.sh --php --only conexao-event-importer` | 1 | 35 in-process suites: **32 passed, 3 failed**; passing suites report **2 805 assertions passed, 0 failed**. The 3 reds are analysed in §12 |
| `docker compose exec wordpress php …/test-import-history-dashboard.php` | 0 | **74 passed, 0 failed** |
| `docker compose exec wordpress php …/test-import-continuation.php` (clean re-run) | 0 | **272 passed, 0 failed** |
| `docker compose exec wordpress php …/test-event-cleanup-purge.php` (re-run) | 0 | **715 passed, 0 failed** (the aggregate red was my own SIGTERM) |
| `docker compose exec wordpress php …/test-town-sanitization.php` | 1 | **82 passed, 1 failed** — pre-existing DB term (§12) |
| `docker compose exec wordpress php …/test-export-language.php` | 255 | PHP fatal: 512 MB exhausted in `class-event-export.php` (§12) |
| same, `-d memory_limit=4096M` (diagnostic) | — | **57 passed, 1 failed** — 45 duplicate `[STAGE32-EXP]` fixtures from earlier aborted runs (§12) |
| `python3 tests/browser/verify-import-continuation-browser.py` | 0 | **28 passed, 0 failed** on the dashboard |
| `python3 tests/browser/verify-import-continuation-browser.py --negative-control` | 0 | **8 passed, 0 failed** — cell reads `page 1 of 6 (time_budget) — NOT scheduled …`, never `resumable`; exit 0 because the control goes red as designed |
| ad-hoc Playwright operator check (`/tmp`, not committed) | 0 | **36 passed, 0 failed** at 1440px and 1024px |
| `./scripts/lint.sh` | 0 | `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)` — 589 files |
| `php scripts/generate-registry-docs.php --check` | 0 | `registry OK: 11 plugins validated, 20 generated regions current (zero writes)` |
| `./scripts/run-tests.sh --scripts` | 1 | 23 script suites: **21 passed, 2 failed** (i18n freshness §12; release-integrity §10) |
| `python3 tests/scripts/verify-release-integrity.py` (re-run) | 0 | **234 passed, 0 failed** |
| `./scripts/i18n-check.sh` | 1 | 3 passed, 3 failed; **importer catalogue is fresh** (§12) |

### Numeric test results

In-process PHP (`--only conexao-event-importer`): **35 suites — 32 passed, 3
failed**; **2 805 assertions passed / 0 failed** in the passing suites. Targeted
suites: dashboard **74/0**, continuation **272/0**, purge **715/0**.

### HTTP acceptance

Not in scope for this task (admin-only change); no acceptance matrix row added.

### Static analysis

PHP syntax 589 files clean; PHPCS clean against the baseline; PHPStan level 5
clean (`./scripts/lint.sh`).

### Script-contract results

`./scripts/run-tests.sh --scripts`: **23 suites, 21 passed, 2 failed** — both
failures reproduce independently of this change (§10, §12).

### Release / build results

Not in scope (no build or release requested). The release-integrity suite ran
only because it is part of the script layer; its determinism check passed on an
isolated re-run, and two manual builds of the importer artefact produced
identical `sha256 0694465287e42ae8c4f6f75f6677d2d66aa48d67e11b115f52f98ed7bb255ec8`.


## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| Browser negative control: arming deliberately broken | the Progress cell stops claiming `resumable` (`NOT scheduled — the continuation could not be scheduled`); **8 passed, 0 failed**, script exit 0 |
| Release-integrity determinism failure in the script batch | re-ran the suite alone: **234 passed, 0 failed**; two independent builds are byte-identical → the batch failure was transient interference from concurrently running jobs, not a product defect |

## 11. Regression comparison

No clean baseline run could be captured **during this verification session**,
because the working tree was being modified concurrently by pre-existing
background jobs (§13). The comparison uses the evidence that exists:

| | Baseline (plan §22) | Final |
|---|---|---|
| Failing in-process suites (importer) | `test-bulk-actions.php` (pre-existing) | `test-event-cleanup-purge` (my SIGTERM), `test-export-language` (memory), `test-town-sanitization` (DB) |
| `test-bulk-actions.php` | recorded failing | **149 passed, 0 failed** — the concurrent bulk work advanced and now passes; not caused by this change |
| `test-import-continuation.php` | not recorded as failing | **272/0** |
| `test-import-history-dashboard.php` | did not exist | **74/0** |
| Failing script-contract | not recorded | `verify-i18n-freshness`, `verify-release-integrity` (transient) |

No failure in the final list is attributable to this diff: each is either
environmental (memory limit, local DB terms, untouched components’ catalogues) or
was caused by my own process control (SIGTERM) and passes on re-run.

## 12. Known pre-existing failures

1. **`test-export-language.php` — PHP fatal at 512 MB**
   (`class-event-export.php:937`, image `base64_encode`). The local DB holds
   **3 504 events**; the export embeds image bytes. Export code is **git-clean**
   and the suite does not touch any admin renderer. With `memory_limit=4096M` it
   reaches 57 passed / 1 failed, and that failure is fixture accumulation:
   **221 posts** titled `[STAGE32-EXP]` from earlier aborted runs make one
   identity appear 45×. Both are local data conditions, not this change.
2. **`test-town-sanitization.php` — 82 passed, 1 failed**
   (`no Eircode-contaminated or non-town terms in conexao_town`). A read-only
   assertion about `conexao_town` content; this change touches no taxonomy and no
   term, and the file is git-clean.
3. **i18n freshness — 3 stale catalogues** (`conexao-br-irlanda`,
   `conexao-admin-ux`, `conexao-commemorative`). Two are recorded in
   `tests/baseline/permanent-gates.json`; `conexao-commemorative` is not, so the
   gate labels it “new”, but all three components are **git-clean** and
   untouched. The importer catalogue is **not** among the violations, i.e. no new
   translatable string was introduced.
4. **Probe residue** (`g18_probe_*` sources/events/history) from previously
   aborted background runs — cleaned locally; the clean continuation re-run then
   passed.


## 13. Limitations

- **Concurrent tooling interfered with the worktree and the suites.** Detached
  background jobs from an earlier session ran `git stash` twice during this
  session, removing the tracked changes; they were restored with
  `git stash apply`. That stash also contained another agent’s Christmas-theme
  work, which produced conflicts in two theme files; those were backed up to
  `/tmp/wt-backup/`, restored to `HEAD` (their work remains in the stash list),
  and are outside this task. One untracked file,
  `wp-content/themes/conexao-br-irlanda/tests/test-christmas-egg.php`, remains in
  the tree and is **not** part of this change.
- Because of that interference, **no clean baseline run of the whole suite was
  possible in this session**; the “before” column in §11 is the plan’s recorded
  baseline, not a fresh measurement.
- The first clean continuation run failed `G18.9` because four killed runs had
  left 73 probe events behind; after the suite’s own cleanup plus removal of the
  synthetic probe sources, the identical suite passed 272/0. The failure was
  fixture pollution, not the renderer.
- **No production verification** was performed or attempted (production is
  WordPress.com and the task forbids production writes).
- Two script-contract gates remain red for pre-existing reasons (i18n freshness;
  the transient release-integrity determinism result), so the full repository
  contract is not green in this environment.
- Screenshots were captured at 1440px and 1024px only; no mobile-specific admin
  layout was evaluated.

## 14. Evidence paths

`docs/evidence/2026-11-14-import-history-consolidation/`

| File | Proves |
|---|---|
| `01-importer-suite-aggregate.log` | the 35-suite aggregate, 32/3, and which suites are red |
| `02-dashboard-suite.log` | dashboard suite 74/0 |
| `03-continuation-suite.log` | continuation 272/0 after fixture cleanup |
| `04-browser-proof.log` | browser proof 28/0 on the dashboard, 13 columns |
| `05-browser-negative-control.log` | control goes red as designed (8/0) |
| `06-manual-browser-check.log` | 36/0 at 1440px and 1024px, redirect, no retired links |
| `07-lint.log` | syntax + PHPCS + PHPStan clean |
| `08-release-integrity-rerun.log` | isolated re-run 234/0 |
| `09-script-contract-gate.log` | 23 suites, 21/2, with the failing suites named |
| `10-export-language-diagnostic-4g.log` | memory-limit diagnosis and fixture pollution |
| `11-town-sanitization.log` | 82/1 with the failing DB assertion |
| `12-event-cleanup-purge-rerun.log` | purge 715/0 on re-run |
| `13-dashboard-1440.png`, `14-dashboard-1024.png` | rendered dashboard with the seeded 12-row history at both widths |
| `15-registry-docs-check.txt` | registry check, zero writes |
| `16-git-state.txt` | final `git status --short` and `git diff --stat` |


## 15. Documentation updated

| Document | Change |
|---|---|
| `docs/plugins/conexao-event-importer.md` | dashboard is the one history screen; retired page + redirect; full retained history; Listing/Progress/Cooldown preserved; Import Logs still separate; storage unchanged |
| `docs/testing.md` | the continuation browser proof reads the dashboard; names the new in-process suite |
| `docs/architecture.md`, `docs/plugins/README.md`, `docs/project-inventory.md` | regenerated 1.10.0 version strings |
| `plugins.json` | unchanged (no new/changed plugin row); registry docs regenerated and verified |

Historical reports and captured HTML evidence mentioning
`conexao-import-history` were left untouched, as instructed.

## 16. Rollback / recovery

`git checkout -- <the 11 modified paths>` and delete the two added files. There
is **no data migration to roll back**: history rows, the option key, the cap and
the recorder are unchanged, and no content was written. Rolling back does not
restore the retired admin page’s menu entry unless the submenu registration and
`render_history_page()` are restored together, and it does not touch the
concurrent, unrelated work in `test-bulk-actions.php` or the theme.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The requested change is complete and every gate that exercises it is green
(dashboard 74/0, continuation 272/0, browser proof 28/0, negative control red as
designed, manual 36/0, lint clean, registry zero-drift); the limitation is that
the shared local environment still has unrelated red gates (export memory,
`conexao_town` data, i18n catalogues of untouched components) and that the
worktree was concurrently interfered with, so a fully green repository-wide run
could not be produced here.

