# Report — Event Sources bulk actions: corrected, implemented and verified

| | |
|---|---|
| **Task** | Complete, correct and verify the Conexao Event Importer bulk-management improvements |
| **Date** | 2026-11-13 |
| **Author / agent** | Cline (AI agent), for the repository owner |
| **Branch** | `ci-improvements` (working tree; the task named `master`) |
| **Baseline** | `2163e3d` plus an uncommitted partial implementation of the feature |
| **Plan** | [`docs/plans/2026-11-13-event-sources-bulk-actions.md`](../plans/2026-11-13-event-sources-bulk-actions.md) |
| **Skills** | `wp-add-admin-screen`, `wp-security-review`, `wp-write-in-process-test`, `wp-update-docs` |
| **Production actions** | **none** — no deploy, no upload, no production write, no commit/push |

## 1. The actual root causes

1. **The total was never live.** PHP rendered the total as a static number inside a
   translated suffix (` of 138 selected`) and there was no element for it, so
   JavaScript could not re-count the DOM when rows were added, removed or
   replaced. The counter could claim a total that no longer existed.
2. **The numeric span is nested inside the translated suffix.** Overwriting the
   parent (`#conexao-bulk-count`) or writing the whole phrase into
   `#conexao-bulk-selected-count` duplicates the suffix or destroys it. A
   partial implementation had already drifted towards that mistake.
3. **No single state owner.** Apply, the action selector, the header checkbox and
   the accessible status were updated in separate places, so a change could
   leave the header in a stale partial state, or leave Apply enabled with no
   valid action.
4. **The PHP regression suite could not fail.** `handle_bulk_action()` ends with
   `wp_safe_redirect(); exit;`. The suite invoked it inside its own process, so
   the run died after the first section and still exited **0** — a silent pass.
   That is why the previous report could not claim verification.
5. **The success notice was never a WordPress success notice** (found by the
   browser run): the markup rendered `class="notice notice-notice-success"`,
   because the code prefixed an already-prefixed class name.
6. **The delete confirmation contained a literal escape sequence** (found while
   reviewing the message): `'...?\n\nThis will...'` in a single-quoted PHP string
   is a backslash and an `n`, not a newline, and `wp_localize_script()`
   JSON-encodes it that way, so the dialog showed `…source(s)?\n\nThis will…`.
7. **Duplicate IDs were not removed.** A duplicated `delete` reported the second
   pass as a *failure*; a duplicated `enable`/`disable` miscounted skips.
8. **The handler mixed escaping layers.** Notice text was built with
   `esc_html__()` and then escaped again by the page — a double escape for any
   ID containing an HTML special character.

## 2. Changes implemented

### Counter markup (PHP + JS, one owner per number)

`render_bulk_actions_controls()` now renders **two numeric spans inside the one
translated suffix**:

```html
<span class="conexao-bulk-count" id="conexao-bulk-count">
  <span class="conexao-bulk-selected-count" id="conexao-bulk-selected-count">0</span>
  of <span class="conexao-bulk-total-count" id="conexao-bulk-total-count">138</span> selected
</span>
```

JavaScript writes **only** `#conexao-bulk-selected-count` and
`#conexao-bulk-total-count`; the phrase stays PHP-owned and is never rewritten.
The browser assertion is `^0 of \d+ selected$` — exactly one suffix, and the
total equals the number of rendered rows (`0 of 140 selected` on the local site,
which has 140 sources).

### One selection controller (`assets/admin.js`)

- `syncSelectionState()` derives **everything** from the live DOM: selected count,
  total, header `checked`/`indeterminate`, Apply `disabled`, action-selector
  `disabled` and the `aria-live="polite"` message. No JS selection array exists.
- `liveRows()` / `liveCheckedRows()` re-query on every call, so replaced rows, a
  replaced table body and a replaced table are all reflected.
- **Delegated** `change` and `click` listeners on the stable
  `.wrap.conexao-event-sources` container; Select All / Deselect All / the header
  checkbox are delegated too, so no per-row listener exists.
- Initialisation is guarded by `data-conexao-bulk-init="1"` on
  `#conexao-bulk-actions-form` (outside the table), and the controller initialises
  whether it runs before or after `DOMContentLoaded`.
- The action whitelist is read from the **rendered `<option>` values**, not a
  second hardcoded list, so the client cannot offer an action the server rejects.
- Submission is re-validated: no selection → blocked with a message; no valid
  action → blocked with a message; `delete` → `confirm()` with the **actual**
  count and the statement that imported events are kept.
- The status region is reset when the state becomes valid again, so a stale
  error never survives a correction.

### Server side (`includes/class-event-sources.php`)

`handle_bulk_action()` is now only capability + nonce + one redirect. It delegates
to:

- `apply_bulk_action( $action, $raw_ids )` — the whole registry side. Returns
  `action, error, requested, duplicates, invalid[], updated[], skipped[], failed[]`.
  It **de-duplicates**, ignores non-scalar/blank IDs, validates every ID against
  `get_all()`, refuses empty selections, and sets **explicit** statuses
  (`active` / `inactive`) — never a toggle.
- `build_bulk_notice( $result )` — composes the notice once, reporting updated,
  already-correct (skipped) and failed counts separately plus ignored IDs, and
  uses `__()` (plain text) because the page escapes at output.

The redirect keeps the established contract (`wp_safe_redirect()` +
`bulk_message` / `bulk_status` back to the same page); no second notice
mechanism was introduced. The notice class bug is fixed
(`class="notice notice-success"`).

### Assets

`admin.css` gains the total-count weight, the live-region style and
`.conexao-bulk-status--error`. The plugin version is bumped to **1.9.1** (the CSS
cache-buster is the version), while `admin.js` keeps its `filemtime()` buster.
The browser suite asserts the served URL carries the current file's mtime (and
that the stylesheet carries a cache-buster).

## 3. Files modified

| Path | Change |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php` | handler split into `apply_bulk_action()` / `build_bulk_notice()`, counter spans, header `aria-label`, notice class fix |
| `wp-content/plugins/conexao-event-importer/assets/admin.js` | rewritten bulk-selection controller |
| `wp-content/plugins/conexao-event-importer/assets/admin.css` | total-count, live region, error status |
| `wp-content/plugins/conexao-event-importer/conexao-event-importer.php` | version 1.9.1; `bulkSelectedOne`, `bulkSelectedMany`, `bulkNoSelection`, `bulkNoAction`; single-line delete confirmation |
| `wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot` | regenerated with `scripts/i18n-make-pot.sh` |
| `wp-content/plugins/conexao-event-importer/tests/test-bulk-actions.php` | rewritten (the old suite could not fail) |
| `tests/node/bulk-selection.test.js` | 20 runtime cases |
| `tests/scripts/verify-event-importer-bulk-selection.py` | new script-contract gate |
| `tests/browser/verify-event-sources-bulk-browser.py` | new opt-in browser proof |
| `docs/plugins/conexao-event-importer.md` | bulk-action + controller contract |
| `docs/testing.md` | gate row + browser-proof section |
| `docs/architecture.md`, `docs/plugins/README.md`, `docs/project-inventory.md` | regenerated (version 1.9.1) |
| `docs/plans/2026-11-13-event-sources-bulk-actions.md` | plan |
| `docs/reports/2026-11-13-event-sources-bulk-actions.md` | this report |

Not modified: any other plugin (notably `conexao-event-runtime`, which owns
`_event_status`), the theme, `plugins.json`, `compose.yaml`, the import engines,
the importer's other 33 suites, routes, redirects and the sitemap.

## 4. How Enable Selected works

1. The operator ticks rows and chooses **Enable Selected**. Apply becomes usable
   only when both a selection and a valid action exist.
2. The browser submits the one bulk form. The row checkboxes are associated with
   it through `form="conexao-bulk-actions-form"` and submit as
   `bulk_source_ids[]`; the header checkbox has no `name` and is never submitted.
3. `render_sources_page()` runs the existing nonce check, then
   `handle_bulk_action()` verifies `manage_options` and the nonce and calls
   `apply_bulk_action()`.
4. For every **distinct** submitted ID that exists in the registry:
   - `status === 'active'` → added to `skipped` (nothing is written);
   - otherwise `set_status( $source_id, 'active' )` → `updated` on success,
     `failed` otherwise.
5. The notice reports updated, already-active and failed counts separately, and
   lists any ignored IDs. The redirect carries `bulk_status=success` unless
   something failed or was ignored.

Selecting every checkbox and choosing Enable Selected enables every selected
source. An empty selection is refused — it is never read as “all sources”.
Seeded sources keep their declared default statuses; nothing here activates
anything automatically.

## 5. How Disable Selected and Delete Selected work

**Disable Selected** is the mirror image: `set_status( $id, 'inactive' )` for the
selected sources, already-inactive ones reported as skipped, nothing toggled.
Both actions are idempotent: re-running them reports skips and writes nothing
(the suite asserts a second identical pass produces zero updates).

**Delete Selected** uses the existing `delete( $source_id )` and removes only the
**configuration**. Before submission the browser shows a confirmation built from
the **actual** selected count and stating that imported events remain. The suite
and the browser run prove that an event referencing the deleted source still
exists afterwards, with its `_event_source`, `_event_source_id` and
`_event_status` meta untouched. Deleting a source never deletes events or media.

## 6. Tests performed and results

### In-process PHP — `test-bulk-actions.php` (rewritten)

The old suite is the reason this task existed: it invoked the exiting handler and
stopped after one section while exiting 0. It now tests the core directly and
tests the real handler in a **child PHP process** (the only honest way to assert
on a method that terminates its process).

```
docker compose exec -T wordpress php \
  /var/www/html/wp-content/plugins/conexao-event-importer/tests/test-bulk-actions.php

bulk-actions: 97 passed, 0 failed
```

Covered: invalid action (string, array, null, empty), empty selection, blank and
nested IDs, unknown IDs (partial and total), enable one/many/already-active/mixed,
disable one/many/already-inactive/mixed, idempotency of both, duplicate IDs on
enable **and** on delete (no false failure), delete of several sources, deleting a
configuration preserving its imported event and `_event_status`, notice wording
for updated/skipped/failed/ignored, and the security contract: logged-out request
refused, missing nonce refused, valid nonce applies the action and redirects. In
all three child-process cases the marker `HANDLER-RETURNED` is asserted **absent**,
so a handler that stopped dying would fail the suite.

### Node runtime — `tests/node/bulk-selection.test.js` + the new gate

```
node --test tests/node/bulk-selection.test.js   ->  20 tests, 20 pass, 0 fail
python3 tests/scripts/verify-event-importer-bulk-selection.py
  rendered bulk actions == server whitelist: delete, disable, enable
  runtime cases executed: 20
  20 passed, 0 failed
  GATE-RESULT {"gate": "event_importer_bulk_selection_runtime", "status": "pass", ...}
```

The gate also asserts that the **rendered** `bulk_action_type` options equal the
server's `$allowed_actions` whitelist, and it fails if fewer than 20 runtime cases
execute (so a suite that silently stops running cannot pass).

### Whole importer plugin (regression comparison)

```
./scripts/run-tests.sh --php --only conexao-event-importer
In-process PHP suites: 34 total, 32 passed, 2 failed
Assertions: 3476 passed, 1 failed
```

The two failures are **pre-existing and unrelated**, proved by re-running both
with the committed `class-event-sources.php` restored:

| Suite | Failure | Proof it is not mine |
|---|---|---|
| `test-export-language.php` | PHP fatal, 512 MB exhausted in `class-event-export.php` | identical fatal with the HEAD version of the file (local DB has ~10k events) |
| `test-town-sanitization.php` | `no Eircode-contaminated or non-town terms in conexao_town` | identical `82 passed, 1 failed` with the HEAD version; it asserts on existing taxonomy **data** |

### Static quality

```
./scripts/lint.sh
lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)

php scripts/generate-registry-docs.php --check
registry OK: 11 plugins validated, 20 generated regions current (zero writes).
```

### Script-contract layer

```
./scripts/run-tests.sh --scripts
Script-contract suites: 23 total, 22 passed, 1 failed
```

The new gate `verify-event-importer-bulk-selection.py` is discovered by
convention and passes. The single failure is `verify-i18n-freshness.py`; see
§8 for why it cannot go green before a commit.

## 7. Browser verification results

Real Chromium (Playwright) against the local Docker WordPress at
`http://localhost:8080`, logged in through the real `wp-login.php` form, driving
`/wp-admin/admin.php?page=conexao-event-sources` with real clicks, real typing and
real form submissions.

```
python3 tests/browser/verify-event-sources-bulk-browser.py
82 passed, 0 failed        (exit 0)
```

Every item of the requested checklist, and where it was asserted:

| # | Required check | Result |
|---|---|---|
| 1 | Load the page, verify the initial counter | PASS — `0 of 140 selected` exactly once; total equals the rendered rows |
| 2 | Select one source, count changes | PASS — `1`, header becomes indeterminate |
| 3 | Select another, count changes again | PASS — `2`, both values checked |
| 4 | Deselect one, count decreases | PASS — `1` |
| 5 | Click Select All | PASS |
| 6 | Every source is selected | PASS — checked count == rendered rows |
| 7 | Header checkbox is checked | PASS — checked, not indeterminate |
| 8 | Click Deselect All | PASS |
| 9 | Count returns to zero | PASS |
| 10 | Header checkbox is unchecked | PASS — checked false, indeterminate false |
| 11 | Partial group shows indeterminate | PASS |
| 12 | Apply disabled until selection **and** action | PASS — including “no action” and “unknown action value” |
| 13 | Enable Selected submits the correct IDs | PASS — `FormData` **and** the captured POST body (`bulk_source_ids[]=brbulk_probe_a` / `_b`, and no unselected ID) |
| 14 | Backend enables only those sources | PASS — registry read back: probes a and b `active`; c and d untouched |
| 15 | Repeat for Disable Selected | PASS — probes c and d `inactive`, a and b still `active` |

Additionally verified in the same run:

- every row checkbox is associated with `#conexao-bulk-actions-form` and named
  `bulk_source_ids[]`; the header checkbox has no name and is not inside the
  form; there are **no nested forms** and the per-source Import Now forms still
  work;
- `Delete Selected` shows a confirmation naming the actual count and explaining
  that imported events remain, removes only that configuration, keeps the other
  probes and keeps the fixture event that referenced it;
- the served `admin.js` URL carries the **current file's mtime**, and
  `admin.css` carries a cache-buster;
- the confirmation text contains no literal `\n` escape sequence;
- **no uncaught JavaScript error and no console error** for the whole run.

Dedicated probe sources (`brbulk_probe_a..d`) plus one fixture event were created
for the run and removed at the end (`probe sources removed: 3`, the fourth having
been deleted by the test itself). No real configured source was selected,
disabled or deleted.

### Negative controls (proof the checks can fail)

| Proof | Mutation | Result |
|---|---|---|
| Node gate | the counter-update line removed from `assets/admin.js` | `GATE-RESULT … status: fail`, **12 failed**, exit 1 |
| In-process PHP suite | the “already in requested state” condition forced false | 8 FAIL lines, e.g. `src_a reported skipped — expected ["src_a"], got []` |
| Browser suite | `--negative-control` serves the same mutated `admin.js` | `PASS: without the counter update the counter assertion goes red` (count stayed `0`) |
| Pre-existing failures | HEAD `class-event-sources.php` restored | identical `test-export-language` fatal and `test-town-sanitization` `82 passed, 1 failed` |

Every mutation was reverted immediately; the restored files match their
pre-mutation checksums and pass `php -l` / `node --check`.
