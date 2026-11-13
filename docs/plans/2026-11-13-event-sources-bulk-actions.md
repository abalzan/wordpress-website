# Plan — Event Sources bulk actions: verified selection controller, Enable/Disable/Delete

| | |
|---|---|
| **Task title** | Complete, correct and verify the Conexao Event Importer bulk-management improvements |
| **Date** | 2026-11-13 |
| **Author / agent** | Cline (AI agent), for the repository owner |
| **Branch** | `ci-improvements` (working tree; the task named `master`) |
| **Repository baseline (starting SHA)** | `2163e3d` + an uncommitted partial implementation of the feature |
| **Applicable skill(s)** | `wp-add-admin-screen`, `wp-security-review`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-frontend-perf`, `wp-update-docs` |
| **Plan status** | implemented |

## 1. Scope

Finish and verify the Event Sources bulk actions in the local-only
`conexao-event-importer` plugin: one centralised, delegated selection controller
in `assets/admin.js`; a counter whose numeric spans (selected AND total) are the
only thing JavaScript writes; Enable Selected in addition to Disable/Delete;
strict server-side validation (capability, nonce, ID sanitisation,
de-duplication, registry validation, action whitelist); and real regression
coverage at the Node, PHP and browser layers.

## 2. Explicit non-goals

- No change to importing itself: fetching, normalisation, matching,
  de-duplication, event creation/update, image handling, lifecycle,
  reconciliation, history/logging, request budgets, checkpoints, continuation.
- No change to `_event_status` or to any production runtime plugin.
- No new dependency, framework or build step; no new route, CPT or meta key.
- No production write, deploy, commit or push.
- The three pre-existing stale `.pot` catalogues (theme, admin-ux, commemorative)
  are out of scope; only the importer catalogue this change made stale is
  regenerated.

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` (§2.1, §4.2, §7, §8, §9);
`docs/plugins/conexao-event-importer.md` (plugin behaviour);
`docs/testing.md` (test layers, opt-in browser layer, permanent gates);
`plugins.json` (plugin registry, version source); `scripts/README.md`.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-add-admin-screen` + `wp-security-review` (+ `wp-write-in-process-test`, `wp-update-docs`) |
| **Its preconditions** | A capability-gated wp-admin surface that must be operable without CLI (production is WordPress.com); existing nonce and redirect conventions reused |
| **Its guardrails that bind this task** | `manage_options`, the existing `conexao_event_sources` nonce, `sanitize_text_field`, no SQL, escaping at output, no new secrets, no production action |
| **Its verification commands** | `./scripts/run-tests.sh --php --only conexao-event-importer`, `./scripts/run-tests.sh --scripts`, `node --test tests/node/bulk-selection.test.js`, `python3 tests/browser/verify-event-sources-bulk-browser.py`, `./scripts/lint.sh` |
| **Its evidence location** | quoted command output in `docs/reports/2026-11-13-event-sources-bulk-actions.md` |

## 4. Current-state findings

Read from the working tree, not from a summary:

1. `assets/admin.js` contained a bulk controller, but its **total** was never
   read from the DOM, and the counter suffix rendered by PHP
   (` of %d selected`) was static.
2. `includes/class-event-sources.php::handle_bulk_action()` already whitelisted
   `enable|disable|delete` and set explicit statuses, but did not de-duplicate
   IDs, mixed notices through `esc_html__()` and then escaped them again, and
   repeated the redirect/notice construction four times.
3. `tests/…/test-bulk-actions.php` **never actually ran**: it invoked a handler
   that ends with `exit`, so the suite stopped after its first section and still
   exited 0 — a silent false pass.
4. `tests/node/bulk-selection.test.js` existed but had leftover `console.log`
   debug lines and no coverage for the total, action validation or duplicate
   initialisation.
5. No browser proof existed for this feature at all.

## 5. Proposed approach

- **PHP**: split the handler into `apply_bulk_action()` (pure registry work,
  returns a result array) + `build_bulk_notice()` (message composition) +
  `handle_bulk_action()` (capability + nonce + single redirect). Explicit
  statuses, skipped sources reported, duplicates removed, invalid IDs reported.
  Rejected alternative: keeping one monolithic handler and testing it through a
  subprocess only — untestable core, duplicated logic.
- **Markup**: keep the existing counter structure; add
  `#conexao-bulk-total-count` inside the translated suffix rendered by PHP.
  Rejected alternative: JS owning the whole counter text (would need the
  translated phrase in JavaScript — a second source of truth for i18n).
- **JS**: one `syncSelectionState()` deriving everything from live checkboxes;
  delegated `change`/`click` on `.wrap.conexao-event-sources`; init guard on the
  form; action whitelist read from the rendered `<option>` values.
- **Tests**: Node runtime suite + gate (script layer), in-process PHP suite for
  the core plus child-process proof of capability/nonce, opt-in browser suite
  with a negative control.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/assets/admin.js` | bulk controller rewritten | centralised state, delegation, validation |
| `wp-content/plugins/conexao-event-importer/assets/admin.css` | total-count + error status rules | visual consistency |
| `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php` | handler split, counter markup, `aria-label` | correctness, validation, testability |
| `wp-content/plugins/conexao-event-importer/conexao-event-importer.php` | version 1.9.1, two i18n strings | cache-busting for CSS, translatable messages |
| `wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot` | regenerated | new strings |
| `wp-content/plugins/conexao-event-importer/tests/test-bulk-actions.php` | rewritten | the old suite could not fail |
| `tests/node/bulk-selection.test.js` | extended | runtime coverage |
| `tests/scripts/verify-event-importer-bulk-selection.py` | new gate | dispatches the Node suite |
| `tests/browser/verify-event-sources-bulk-browser.py` | new opt-in suite | real browser proof |
| `docs/plugins/conexao-event-importer.md`, `docs/testing.md` | documentation | maintainer knowledge |
| `docs/architecture.md`, `docs/plugins/README.md`, `docs/project-inventory.md` | regenerated | version 1.9.1 |

## 7. Files explicitly expected NOT to change

Any other plugin (especially `conexao-event-runtime`, which owns
`_event_status`), the theme, `plugins.json`, `compose.yaml`, the import engines,
the importer's other 33 suites, routes, redirects and the sitemap.

## 8. Content / data impact

- Records created / updated / deleted: **none** in production. Locally, the
  suites create and remove their own `src_*` registry entries (PHP suite),
  `brbulk_probe_*` sources and one fixture event (browser suite), and restore
  the registry option afterwards.
- Portable identifiers only; no local post IDs cross an environment boundary.
- Does this write content? No post/meta/taxonomy write outside the test fixtures.
- PT content changed? **No.**

## 9. Route / HTTP impact

No route, redirect or sitemap change. The admin page keeps its single POST →
`wp_safe_redirect( admin.php?page=conexao-event-sources&bulk_message=&bulk_status= )`
contract.

## 10. Polylang / English impact

None. Nothing here touches translated content, taxonomies, cache keys or the
`/en/` layer. New UI strings are English msgids awaiting translation, added to
the importer catalogue.

## 11. Security impact

- Capabilities: `manage_options` on the handler and on the page.
- Nonces: the existing `conexao_event_sources` nonce, unchanged name and action.
- Sanitisation: `sanitize_text_field` for the action and every ID; non-scalar IDs
  ignored; IDs trimmed; duplicates removed; every ID validated against
  `get_all()`.
- Escaping: notice text is composed with `__()` and escaped once at output.
- SQL: none (options API only).
- REST: no change.
- Secrets: none.

## 12. Performance impact

The selection controller adds no query, no request and no dependency. The bulk
handler performs one `get_all()` read and one option write per changed source —
the same cost as the pre-existing per-row actions.

## 13. Verification plan

`./scripts/run-tests.sh --php --only conexao-event-importer`,
`./scripts/run-tests.sh --scripts`, the Node gate directly, the browser suite
happy path **and** `--negative-control`, `./scripts/lint.sh`, the registry-docs
drift gate and the i18n gate.

## 14. Risks and rollback

Risk: a replaced table body could lose delegated handling — mitigated by
delegating on the stable page wrap and by the dynamic-row tests. Risk: the
handler's `exit` could hide a broken suite again — mitigated by the child-process
proof and by asserting the marker `HANDLER-RETURNED` never appears. Rollback:
revert the four product files, the two test files, the gate and the browser
suite; the registry option is untouched by the product change.

_Last verified: 2026-11-13 by the Event Sources bulk-actions task_
