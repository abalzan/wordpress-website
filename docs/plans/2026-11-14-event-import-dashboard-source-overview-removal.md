# Plan — Remove the Sources section from the Event Import dashboard

| | |
|---|---|
| **Task title** | Remove the dashboard Sources overview from Event Import |
| **Date** | 2026-11-14 |
| **Author / agent** | Cline (agent) |
| **Branch** | `event-import-improvements` |
| **Repository baseline (starting SHA)** | `d9580e7` (`Refactor Import History Dashboard: Retire Import Logs Screen and Enhance Logs Column`) |
| **Applicable skill(s)** | `wp-add-admin-screen` (admin screen surface), `wp-repository` (scope/verification loop) |
| **Plan status** | approved |

## 1. Scope

Remove the read-only **Sources** section from the main Event Import dashboard
(`admin.php?page=conexao-event-import`): the `Sources` heading, the
`conexao-source-list` list, the active/inactive indicators and the per-source
error text. Keep the Import Summary (last successful import), Run Import,
Export Events and the complete 14-column Import History with its Logs column
and log actions. Source management keeps living exclusively on the Event
Sources screen.

## 2. Explicit non-goals

- No change to `render_sources_page()`, the Event Sources submenu, the source
  table, the Add/Edit form, per-source actions, bulk actions or validation.
- No change to `handle_run_import()`, the nonce, `source_id=all`, the
  `conexao_event_importer_run_all` filter, or which sources are imported.
- No change to import history rendering/storage/retention or log
  storage/download/clear handlers.
- No source configuration deleted, disabled or migrated.
- The unrelated in-progress **Import Settings retirement** work already present
  in the worktree is preserved and not extended by this task.
- No production write, deployment, activation or commit.

## 3. Relevant authoritative documents

- `AGENTS.md` — scope, safety rules, "PT immutable", no production writes.
- `docs/engineering-standard.md` §4.2 (admin screen contract) and §14
  (definition of done).
- `docs/plugins/conexao-event-importer.md` — plugin ownership and the
  documented dashboard/Event Sources split.
- `docs/testing.md` — the three test layers and the opt-in browser layer.
- Skill `wp-add-admin-screen` — capability, nonce, no-write-on-page-load,
  regression suite requirements.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-add-admin-screen` |
| **Its preconditions** | Ownership boundary known (the screen lives in `conexao-event-importer`); capability and nonce surfaces identified |
| **Its guardrails that bind this task** | Capability check on every entry point; nonce on every write; a GET/page load must not write; no new user-facing strings; no production path |
| **Its verification commands** | `./scripts/run-tests.sh --php --only conexao-event-importer`, `./scripts/lint.sh`, `php scripts/generate-registry-docs.php --check` |
| **Its evidence location** | `docs/evidence/2026-11-14-event-import-dashboard/` (not required: no machine artifact produced) |

## 4. Current-state findings

Verified in `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php`:

- `render_import_dashboard()` guarded by `current_user_can( 'manage_options' )`,
  then handled `conexao_source_action=run_import` after
  `check_admin_referer( 'conexao_event_sources', 'conexao_event_sources_nonce' )`.
- It read `$sources = $this->get_all()` and rendered, in order: the
  `Event Import` heading, the `conexao-import-summary` card (whose value is
  computed by scanning `$sources` for the first `last_import` that is non-empty
  and not in `error` status), then `<h2>Sources</h2>` +
  `<ul class="conexao-source-list">`, then Run Import, Export Events and the
  full `render_history_table()`.
## 5. Proposed approach

Delete the dashboard-only markup (heading, list, indicators, error spans) in
place. Keep `$sources = $this->get_all()` and the existing summary loop
untouched, because the "Last successful import" value is derived from it. Remove
the now-unused list/indicator CSS rules; keep `.conexao-source-error`, which the
Event Sources table still renders.

Options rejected:

- **Hiding with CSS** — leaves dead PHP rendering state the operator cannot act
  on and contradicts the requirement to remove the section.
- **Replacing the summary scan with `Conexao_Import_History`** — changes the
  displayed value's meaning; the task requires preserving it.
- **Moving source controls onto the dashboard** — explicitly out of scope and
  would duplicate the Event Sources screen.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php` | Remove dashboard Sources markup; document why in the method docblock | The requested UI cleanup |
| `wp-content/plugins/conexao-event-importer/assets/admin.css` | Remove `.conexao-source-list` / `.conexao-source-indicator*` rules | No longer emitted anywhere |
| `wp-content/plugins/conexao-event-importer/tests/test-import-history-dashboard.php` | Add Tests I–K (dashboard absence/presence, Run Import contract, Event Sources regression + no-write proof) | Protect the change |
| `tests/browser/verify-import-continuation-browser.py` | Assert the Sources section is absent and the remaining dashboard sections are present | Opt-in browser proof |
| `docs/plugins/conexao-event-importer.md` | Update the dashboard description and record the Event Sources split | Documentation must match behaviour |
| `wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot` | Regenerated catalogue | Repository workflow (no hand edits) |

## 7. Files explicitly expected NOT to change

- `includes/class-import-history.php`, `includes/class-import-log*.php`,
  `includes/class-event-export.php` — history/log/export contracts unchanged.
- `render_sources_page()`, `render_sources_table()`,
  `render_bulk_actions_controls()`, `render_add_form()`, `render_edit_form()`,
  `handle_run_import()`, `handle_bulk_action()`, `get_all()`, `get_active()`.
- The Event Sources submenu registration in `register_admin_menu()`.
- `plugins.json`, plugin load order, build/mount facts.
- Historical reports and archived evidence.

## 8. Content / data impact

- Records created / updated / deleted: **none**.
- `conexao_event_sources` is only read (`get_all()`); rendering writes nothing.
- Import history and log options are only read by the existing renderer.
- No PT/EN content, translation relationship or taxonomy term is touched.

## 9. Route / HTTP impact

- Routes added, removed or changed: **none** (`conexao-event-import` and
  `conexao-event-sources` both stay registered).
- Redirects: **none**.
- Sitemap coverage: **none** (admin screens).
- HTTP matrix rows: **none** (no public surface change).

## 10. Polylang / English impact

- Translated post types / taxonomies affected: **none**.
- PT immutable: **yes**.
- B1/B2 fallback: **none**.
- Canonical, hreflang, language switcher: **unaffected**.
- Cache keys: **none**.

## 11. Security impact

- Capabilities checked: unchanged `manage_options` guard at the top of both
  screens.
- Nonces: unchanged `conexao_event_sources` / `conexao_event_sources_nonce` on
  every write path, including Run Import.
- Sanitisation / escaping: unchanged (`esc_html_e`, `esc_html`, `esc_attr`,
  `esc_url`).
- SQL: none.
- REST surface: none.
- Secrets: none.

- Each source list item rendered a `conexao-source-indicator` span
  (`--active`/`--inactive`) and, when `last_import_status === 'error'`, a
  `conexao-source-error` span.
- `render_sources_page()` renders the dedicated Event Sources screen and still
  uses `.conexao-source-error` for a source's last error.
- CSS in `assets/admin.css` carried `.conexao-source-list`,
  `.conexao-source-indicator`, `.conexao-source-indicator--active` and
  `.conexao-source-indicator--inactive` rules used only by the dashboard list.

## 12. Performance impact

- One less `<ul>` render over the source registry; `get_all()` is still called
  once for the summary, so the option read count is unchanged.
- CSS: four unused rule blocks removed (smaller stylesheet).
- Caching/assets: no new keys; no JavaScript.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | nobody in this task |
| Verification of the production result | not applicable — local-only change |

## 14. Test plan

- Update `wp-content/plugins/conexao-event-importer/tests/test-import-history-dashboard.php`:
  - Test I — Event Import heading, Import Summary and its value, Sources
    heading absent, `conexao-source-list` absent, indicators absent, per-source
    errors absent, Run Import/Import History order, Export Events link target,
    14-column history and log actions.
  - Test J — the Run Import form's POST method, nonce field/value,
    `conexao_source_action=run_import`, `source_id=all`, and the handler's
    dispatch to `conexao_event_importer_run_all` with only active sources.
  - Test K — `conexao_event_sources` byte-identical across a dashboard render,
    plus the Event Sources page still rendering its table, statuses, actions,
    bulk controls and Add/Edit form.
- Browser: `tests/browser/verify-import-continuation-browser.py` asserts the
  absence/presence matrix in a real Chromium session (opt-in).
- Pass criterion: the importer in-process layer reports the same or fewer
  failures than the baseline, with the dashboard suite green.

## 15. Acceptance matrix plan

Not applicable: no request-visible public route, so no HTTP acceptance row is
added.

## 16. Rollback plan

Restore the removed markup and CSS from git (`git checkout -- <paths>`); no data
rollback is needed because nothing was written. Rollback does not cover the
pre-existing settings-retirement work, which is a separate change.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/plugins/conexao-event-importer.md` | Dashboard submenu description no longer lists source statuses; new "The dashboard is not a source overview" section records the split and the unchanged Run Import/summary contract | — |

## 18. Release implications

- No version bump required for a local UI cleanup; no artifact allowlist change.
- A release is **not** in scope.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Accidentally removing the summary's `$sources` read | medium | Test I asserts the summary and Test K asserts the registry is still read/populated |
| Removing CSS the Event Sources page still needs | medium | Only `.conexao-source-list`/`.conexao-source-indicator*` removed; `.conexao-source-error` kept and asserted by Test K |
| Touching unrelated in-progress work (settings retirement) | medium | Scope confined to the dashboard render, its CSS, tests and this plugin doc |
| Test suite is slow (single long suites) | high | Run the targeted suite first, then the full importer layer in the background |

## 20. Verification gates

1. `./scripts/run-tests.sh --php --only conexao-event-importer` — dashboard suite green, no new failures.
2. `./scripts/lint.sh` — PHPCS/PHPStan, no new violations.
3. `php scripts/generate-registry-docs.php --check` — exit 0.
4. Browser layer attempted; `2` reported as environment-unavailable, never as a pass.

## 21. Completion criteria

- Dashboard renders no Sources heading/list/indicators/errors.
- Import Summary, Run Import, Export Events and the 14-column history remain.
- Event Sources screen unchanged and covered by Test K.
- Run Import still posts the same nonce/action/scope.
- Docs updated; real numbers recorded in the report; no production write.

_Last verified: 2026-11-14 by the Event Import dashboard cleanup_

