# Report — Event Import dashboard: remove the Sources overview

| | |
|---|---|
| **Stage / task name** | Remove the Sources section from the Event Import dashboard |
| **Date** | 2026-11-14 |
| **Author / agent** | Cline (agent) |
| **Branch** | `event-import-improvements` |
| **Start SHA** | `d9580e7` (working tree already carried the task's implementation plus an unrelated in-progress settings-retirement change) |
| **Final SHA** | `eb8e586` appeared externally mid-session (see §3); no commit was created or pushed by this agent |
| **Working tree at finish** | dirty — the two new documents in this report/plan plus the incidental lint fix and regenerated catalogue (see §3) |
| **Skills followed** | `wp-add-admin-screen` (screens), `wp-repository` (scope and verification loop) |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md` §4.2/§13.2/§14, `docs/plugins/conexao-event-importer.md`, `docs/testing.md` |

## 1. Scope completed

- The main dashboard (`admin.php?page=conexao-event-import`) no longer renders
  the **Sources** heading, the `conexao-source-list` list, the active/inactive
  indicators or the per-source error messages.
- The Import Summary (`Last successful import`), Run Import, Export Events and
  the complete 14-column Import History (including Logs and the log actions)
  remain, in that order.
- `$sources = $this->get_all()` and the summary's "first non-error
  `last_import`" calculation were preserved exactly; the value the operator
  sees is unchanged.
- The dedicated Event Sources screen (`admin.php?page=conexao-event-sources`) is
  untouched: submenu, table, Add/Edit form, per-source actions, bulk actions,
  Select/Deselect All and notices are all still rendered and covered by tests.
- Run Import is unchanged: POST, the `conexao_event_sources` nonce,
  `conexao_source_action=run_import`, `source_id=all`,
  `handle_run_import()` and the `conexao_event_importer_run_all` filter.
- Unused dashboard-only CSS was removed; `.conexao-source-error` stays because
  the Event Sources table still uses it.
- Documentation updated; tests added (Test I — presentation, Test J — import
  behaviour, Test K — Event Sources regression and no-write proof); the opt-in
  browser proof updated.

## 2. Scope NOT completed

- No production action of any kind (explicitly out of scope).
- The four failing importer suites listed in §12 were **not** fixed: they belong
  to unrelated work already present in the tree (settings retirement, export
  memory) or to a local content-data condition (town terms). They are reported,
  not silently ignored.

## 3. Files added / modified / deleted

The task's implementation was already present, uncommitted, in the worktree at
session start and was recorded in the external commit `eb8e586`
("Retire the empty Import Settings page and update related functionality",
13:45:13 UTC) which also carried the unrelated settings-retirement change. This
agent did not create that commit and did not push.

| Path | Change | Note |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php` | modified | Removed the Sources heading/list/indicators/error spans from `render_import_dashboard()`; docblock records why and that `$sources` still feeds the summary |
| `wp-content/plugins/conexao-event-importer/assets/admin.css` | modified | Removed `.conexao-source-list`, `.conexao-source-indicator`, `--active`, `--inactive`; kept `.conexao-source-error` |
| `wp-content/plugins/conexao-event-importer/tests/test-import-history-dashboard.php` | modified | Tests I–K added (393 lines) |
| `tests/browser/verify-import-continuation-browser.py` | modified | Browser assertions: Sources section absent, remaining dashboard sections present |
| `docs/plugins/conexao-event-importer.md` | modified | Submenu description + new "The dashboard is not a source overview" section |
| `wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot` | regenerated | `Sources` msgid gone; line references refreshed by `scripts/i18n-make-pot.sh` |
| `wp-content/plugins/conexao-event-importer/includes/class-import-settings.php` | modified (incidental) | One trailing blank line removed so the PHPCS baseline gate is green again; no behaviour change |
| `docs/plans/2026-11-14-event-import-dashboard-source-overview-removal.md` | added | Plan required by §13.2 |
| `docs/reports/2026-11-14-event-import-dashboard-source-overview-removal.md` | added | This report |

Counts for this session: **2 added, 2 modified** (plus the implementation
already present). No file deleted.

## 4. Runtime impact

WordPress admin renders less markup and emits no source overview on the
dashboard. No stored data, import path, scheduling or log behaviour changes.
The Event Sources screen is byte-identical in behaviour.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | confirmed |
| No production content, Polylang or database change | confirmed |
| No application behaviour change beyond the dashboard markup | confirmed |
| No Flutter/mobile repository accessed | confirmed |
| No `.env` or secret change | confirmed |

## 5. Content / data impact

None. `conexao_event_sources`, `conexao_event_import_history` and
`conexao_event_import_log` are read-only for this change. Test K asserts the
source option is byte-identical before/after a dashboard render, and the suite
snapshots/restores all three operator options with a shutdown safety net.

## 6. Polylang impact

None. No post, term, translation relationship, taxonomy or locale path is
touched. No PT content changed.

## 7. Route / HTTP impact

No route, redirect, canonical, hreflang or sitemap change. Both admin screens
remain registered (`conexao-event-import`, `conexao-event-sources`). The opt-in
browser proof exercised the dashboard over real local HTTP (§9).

## 8. Production actions

| Action | Performed? | Detail |
|---|---|---|
| Production write | **no** | — |
| Deploy / upload | **no** | — |
| Plugin activation | **no** | — |
| Content or DB mutation | **no** | Local probe fixtures created and deleted by the opt-in browser suite only |

Production is WordPress.com (no SSH, no WP-CLI, no filesystem, no database); no
production verification was attempted or required.

## 9. Verification commands and results

| Command | Exit | Result |
|---|---|---|
| `./scripts/run-tests.sh --php --only conexao-event-importer` | 1 | 37 suites: **33 passed, 4 failed**; **3717 assertions passed, 2 failed**. `test-import-history-dashboard.php`: **136 passed, 0 failed** |
| `docker compose exec -T wordpress php …/test-import-history-dashboard.php` | 0 | **136 passed, 0 failed** |
| `python3 tests/browser/verify-import-continuation-browser.py` | 0 | **46 passed, 0 failed**; no Sources section, `conexao-source-list`, indicator or error markup; Event Import heading, summary, Run Import (source_id=all), Export Events and 14-column history present; 12 probe events cleaned |
| `./scripts/lint.sh` | 0 | PHP syntax 592 files OK; PHPCS 2825 errors / 2231 warnings in 244 files vs baseline 3290 / 2672 — **no new violations**; PHPStan level 5 — **0 errors** |
| `php scripts/generate-registry-docs.php --check` | 0 | 11 plugins validated, 20 generated regions current, zero writes |
| `./scripts/i18n-make-pot.sh conexao-event-importer` | 0 | 1 catalogue regenerated; `Sources` msgid absent |
| Negative control (Sources block re-added, then restored) | 1 (expected) | 133 passed, **3 failed** — heading, list container and indicator assertions went red |
| Local probe cleanup check | 0 | `conexao_event_sources`: 154 entries, **0** `g18_probe_*`; no source deleted |

### Numeric test results

In-process PHP: **37 suites total, 33 passed, 4 failed**; **3717 assertions
passed, 2 failed**. The importer suite this task owns and changed
(`import-history-dashboard`) is **136 passed, 0 failed**.

### HTTP acceptance

Not run: the change has no public route, so no acceptance row applies. The
opt-in browser layer above is the request-level proof.

### Static analysis

PHPCS + PHPStan via `./scripts/lint.sh`: exit 0 (details above).

### Script-contract results

Not run: no file under `scripts/` changed.

### Release / build results

Not in scope.

## 10. Failure proofs / negative tests

| Proof | Result |
|---|---|
| Temporarily re-insert the original `Sources` heading + `conexao-source-list` into `render_import_dashboard()` and run the dashboard suite | **133 passed, 3 failed**: `Test I: the Sources heading is absent`, `Test I: the conexao-source-list container is absent`, `Test I: no active/inactive source indicator is rendered` — the gate is non-vacuous |
| Restore the file and re-run | sha256 identical to the pre-proof file (`cbf4868…`), `git status` clean for that path, **136 passed, 0 failed** |
| Browser `--negative-control` | Not tested: it breaks continuation scheduling, not the presentation assertions this task adds |



## 11. Regression comparison

No baseline run at `d9580e7` was captured: the worktree already contained the
implementation when this session started. Comparing the failing lists instead:

| | Before this task | After |
|---|---|---|
| Failing in-process suites (importer) | 4 (the same four, all in unrelated subsystems) | 4 — identical list |
| Failing assertion count | 2 (same two assertions) | 2 |
| Failing script-contract | not run (no `scripts/` change) | not run |
| Failing acceptance assertions | not applicable | not applicable |

The diff of failing lists is **empty**; no failure was introduced by this
change, and the dashboard suite is green.

## 12. Known pre-existing failures

1. **`test-eventbrite-token-retirement.php`** — `PHP Fatal error: Call to
   undefined method Conexao_Import_Settings::render_page()`. The method was
   removed by the unrelated settings-retirement change now in the tree; the
   test file is unmodified and the dashboard change does not touch it.
2. **`test-import-settings-retirement.php`** — 18 passed, 1 failed
   (`Test G: with cleanup_scope absent, manual event IS selected (all
   fallback)`). Belongs to the same settings-retirement work; `cleanup_scope` is
   untouched here.
3. **`test-export-language.php`** — `Allowed memory size of 536870912 bytes
   exhausted` in `includes/class-event-export.php:935`. Export code is not part
   of this diff; the local dataset drives it.
4. **`test-town-sanitization.php`** — 82 passed, 1 failed ("no
   Eircode-contaminated or non-town terms in conexao_town"). A local
   content-data condition in the taxonomy; no taxonomy code changed here.

## 13. Limitations

- No `d9580e7` baseline test run: the implementation was already in the
  worktree at session start, so the comparison is by attribution (§12), not a
  captured before-run.
- The importer layer's aggregate exit code is 1 because of the four failures
  above; they are outside this change's scope and were left untouched.
- The browser `--negative-control` was not run (§10).
- A commit (`eb8e586`) appeared in the repository during the session, created
  externally and containing both this task's already-present changes and the
  unrelated settings-retirement work. This agent did not commit or push; the
  history was not rewritten.
- Local-only verification; no production check is possible or required.

## 14. Evidence paths

`docs/evidence/2026-11-14-event-import-dashboard/`:

| File | Proves |
|---|---|
| `importer-in-process.txt` | The full importer layer result (37 suites, 3717 assertions, the four failures and the dashboard suite's 136 passes) |
| `browser-proof.txt` | The Chromium proof: absent Sources markup, present remaining sections, 14 columns, 0 failed |
| `lint.txt` | PHP syntax, PHPCS baseline, PHPStan |

## 15. Documentation updated

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/plugins/conexao-event-importer.md` | Dashboard submenu no longer lists source statuses; new "The dashboard is not a source overview" section documents the split, the preserved summary calculation and the unchanged Run Import contract; Event Sources management section retained | — |
| `docs/plans/2026-11-14-event-import-dashboard-source-overview-removal.md` | Plan (new) | — |
| `docs/reports/2026-11-14-event-import-dashboard-source-overview-removal.md` | This report (new) | — |

## 16. Rollback / recovery

Re-add the removed markup in `render_import_dashboard()` and the four CSS rule
blocks in `assets/admin.css` (`git checkout` on those paths restores the
previous committed state). No data rollback is needed: nothing was written.
Rollback does not cover the unrelated settings-retirement change.

## 17. Final status

**Final status: PASS WITH LIMITATION**

The dashboard change is complete and verified by the targeted in-process suite
(136/0), a real-Chromium proof (46/0) and a non-vacuous negative control; the
limitation is that the full importer layer still reports four failures from
unrelated work already present in the tree and from local data conditions, which
this task deliberately did not touch.

_Last verified: 2026-11-14 by the Event Import dashboard cleanup_
