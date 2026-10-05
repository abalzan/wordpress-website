# Multi-file importer — browser workflow (submit interception) fix

**Stage:** Event importer tooling repair (browser orchestration)
**Date:** 2026-10-05
**Branch:** `master`
**Base commit:** `a22b4c6` ("Add reconciliation tests and internal query handling
for source_not_found events")

Continues [`2026-10-05-multi-file-event-import-repair.md`](2026-10-05-multi-file-event-import-repair.md),
which repaired the **backend** multi-file importer. That work is preserved here
unchanged; this report is about the **browser workflow**, which was still broken
after it.

---

## 1. Summary

After the backend repair, the multi-file importer worked on the server and its
149 in-process assertions passed, but the admin UI was still wrong:

> User selects multiple files → clicks Import → page refreshes → nothing appears
> to happen.

This was **not** a backend problem. Measured in real Chromium, the browser was
performing its **default form submission**: a document-level multipart POST to
`admin-post.php`, a 302 back to the importer page, and a full page reload — and
only *then*, on the reloaded document, did `admin.js` read the batch token from
the query string and start the per-file requests.

The root cause is a single missing link: the multi-file form's `submit` event
was **never intercepted**, and `preventDefault()` was **never called** for it.

| Layer | Defect | Effect |
|---|---|---|
| Browser orchestration | The multi-file form had no `submit` interception; the shared handler matched `form[data-conexao-import-form]` (an attribute BOTH import forms carry) and never called `preventDefault()` | **Every** activation produced a native document POST + full page reload before any import ran |
| Staging response | `handle_import_multi()` could only redirect | There was no JSON staging response for an in-page client to consume |
| Result rendering | The `sprintf()` shim had no positional (`%1$s`) support | Per-file rows rendered the raw template `%1$s — %2$d created, %3$d updated` instead of the real counts |

---

## 2. Root cause — the actual browser trace

Reproduced before the fix against the real rendered admin page with real
Chromium (Playwright), real login, 2 real export files, and the real Import
button. Raw trace: `docs/evidence/2026-10-05-multi-file-import-browser/repro-before-fix.log`.

```
click
→ submit event fired — but NO handler matched the multi-file form
→ preventDefault() NEVER ran  (window.__trace was lost: the document was replaced)
→ native multipart POST to /wp-admin/admin-post.php   (resource type = DOCUMENT)
→ 302 redirect
→ GET /wp-admin/admin.php?page=conexao-event-import-file&conexao_multi_batch=8e03…&conexao_multi_file_count=2
→ full page reload
→ only NOW does DOMContentLoaded → initMultiFileImport() read the token
→ 2 per-file XHRs → 1 cleanup XHR → results finally render
```

Measured facts from the pre-fix run:

| Fact | Measured value |
|---|---|
| URL before click | `http://localhost:8080/wp-admin/admin.php?page=conexao-event-import-file` |
| URL after click | `…/admin.php?page=conexao-event-import-file&conexao_multi_batch=…&conexao_multi_file_count=2&conexao_multi_strategy=update` |
| Page reload | **yes** |
| Staging request | `POST admin-post.php`, resource type **`document`** |
| `submit` event handled for the multi-file form | **no** |
| `preventDefault()` executed | **no** |
| Uncaught JS exceptions | none (this was not an exception — it was a missing interception) |

The previous audit's selector mistake was *also* avoided: the action is a hidden
**input**, so `form[action*=conexao_import_events_multi]` matches **0** elements.
The real discriminator is
`form input[name="action"][value="conexao_import_events_multi"]`.

---

## 3. The real rendered form (DOM, not template source)

Captured from the live admin page (`docs/evidence/.../browser-happy-path.log`):

| Property | Value |
|---|---|
| Form `action` | `http://localhost:8080/wp-admin/admin-post.php` |
| Form `method` | `post` |
| Form `enctype` | `multipart/form-data` |
| Form id / class | *(none — so id/class binding was never an option)* |
| Discriminator | hidden `input[name="action"][value="conexao_import_events_multi"]` |
| File input | `name="conexao_import_files[]"`, `multiple`, `required`, `accept=".json,application/json"` |
| Submit control | `INPUT type="submit" id="submit" name="submit" value="Import Multiple Files"` (WordPress `submit_button()`) |
| Nonce | hidden `conexao_import_multi_nonce` |

Confirmed with DOMXPath in-process (`test-multi-file-import-ui.php` TEST 1) and
in the browser (Playwright `page.evaluate`).


---

## 4. The fix

### 4.1 Client — intercept `submit`, not `click` (`assets/admin.js`)

1. `bindMultiFileSubmit()` registers **one delegated listener on the document**
   for the `submit` event, scoped by the hidden action input. Delegation is used
   because the form markup is server-rendered and could legitimately be
   re-rendered; the scope is strict, so no other admin form is affected.
2. `handleMultiFileSubmit(form, event)` calls **`event.preventDefault()` as its
   first statement**. Nothing executes before it — not a guard, not a lookup —
   so no exception or early return can fall through to a native submission.
   Handling `submit` (not `click`) is deliberate: it covers **Enter-key and
   keyboard activation**, and it runs after native constraint validation, so the
   `required` file input is still enforced by the browser.
3. It builds **one `FormData` from the live form** — every selected file under
   the real field name, plus the nonce, the action and the strategy — asserts
   `FormData.getAll('conexao_import_files[]').length === files.length`, and only
   then sends **one staging XHR**.
4. On success it runs the existing per-file orchestration unchanged, then one
   cleanup XHR, then renders results **in place**. The form and the submit
   control are restored at the end and the form is reset, so a second batch can
   run without a reload.
5. `initImportForms()` now **skips** the multi-file form, so the shared
   import-button spinner handler cannot double-handle it.

### 4.2 Error handling, loading state, accessibility

- Staging failures keep the operator on the page, restore the control and show a
  truthful, non-technical reason (`no_files`, `too_many`, `manifest`,
  `stage_failed`, network). No path, token, nonce or stack trace is ever shown.
- While processing: the real submit control is disabled, the form carries
  `data-conexao-multi-busy` and `aria-busy="true"`, and a `role="status"`
  `aria-live="polite"` status line reports progress. Duplicate activations are
  rejected. Unrelated admin navigation is not blocked.
- The result summary is truthful — **Files processed / imported / unchanged /
  failed** — plus the existing per-file counts, duration and peak memory.

### 4.3 Server — a response-shape switch only (`class-event-transfer-admin.php`)

`handle_import_multi()` now returns **JSON** when the request carries
`conexao_multi_response=json`, and **redirects exactly as before** otherwise.
The switch is decided only **after** `current_user_can( 'manage_options' )` and
`check_admin_referer( 'conexao_import_events_multi', … )`, and every staging,
validation and token rule is identical in both modes. `emit_multi_json_error()`
returns a translated reason and no internals.

**The native POST is deliberately preserved as the no-JavaScript fallback.**
`initMultiFileImport()` still resumes a batch from the query string, so with
JavaScript disabled the importer behaves exactly as before.

### 4.4 Result rendering fix

`sprintf()` in `admin.js` now supports positional placeholders (`%1$s`, `%2$d`)
as well as sequential ones. Before the fix the per-file rows rendered the raw
template text; the browser trace shows the corrected

---

## 5. Backend preservation

The already-verified backend importer was **not** rewritten. Unchanged:

- `Conexao_Event_Multi_Import::stage_uploads()` / `import_staged_file()` /
  `cleanup_batch()` and the 64-hex batch-token contract.
- The trusted-root staged-file provenance check in
  `Conexao_Event_Import::validate_upload()`.
- The G17 importer-internal query scope, cross-source dedup, reappearing-event
  restoration and idempotence.
- The per-file JSON response shape consumed by the existing orchestration.

The only backend change is the additive `conexao_multi_response=json`
**response-shape** branch in the staging handler (plus its error helper). Proof:
the backend suite still reports **149 passed, 0 failed**
(`docs/evidence/2026-10-05-multi-file-import-browser/backend-multi-file-import.log`).

---

## 6. Browser verification

Real Chromium via Playwright — the browser engine already present in the
environment. Full logs in `docs/evidence/2026-10-05-multi-file-import-browser/`.

**Happy path** (`browser-happy-path.log`): **49 passed, 0 failed** (mouse), plus
the same assertions again for keyboard activation.

| Check | Measured |
|---|---|
| URL before / after | identical (`…page=conexao-event-import-file`), no `conexao_multi_batch` |
| `submit` fired / `preventDefault()` ran | yes / **yes** |
| Document-level POSTs | **0** |
| Staging requests | **1** |
| Files in the staging body | **2** of 2 selected |
| Per-file import requests | **2** |
| Cleanup requests | **1** |
| Total POSTs | **4** (= 1 + 2 + 1, no duplicates) |
| Visible summary | `Files processed: 2 / imported: 4 / unchanged: 0 / failed: 0` |
| Fixture events after run | **0** (cleaned up) |
| Duplicate identities | **0** |

**Negative control** (`browser-negative-control.log`): **3 passed** — a mutated
`admin.js` whose handler never calls `preventDefault()` is correctly detected as
navigating, issuing a document POST and leaking the batch token into the URL.

**Database identities** (real `_event_export_uuid` checks):

```
BRBROWSER event posts: 4
  17813 | [BRBROWSER] ALPHA event 01 | future
  17814 | [BRBROWSER] ALPHA event 02 | future
  17815 | [BRBROWSER] BETA event 01  | future
  17816 | [BRBROWSER] BETA event 02  | future
distinct uuids: 4   (brbrowser-alpha-01 x1, -alpha-02 x1, -beta-01 x1, -beta-02 x1)
```

**Performance / N-file check.** The request count is deterministic and linear:
`1 + N + 1` POSTs for N files (measured 1+2+1=4 for N=2). No polling, no repeated
per-file requests, no timeout increase.

`brmfi-alpha.json — 0 created, 2 updated`.

---

## 7. Regression tests

### 7.1 In-process gate (blocking, CI)

`wp-content/plugins/conexao-event-importer/tests/test-multi-file-import-ui.php`
— discovered by convention, runs in `./scripts/run-tests.sh`. **41 passed, 0
failed.**

- **TEST 1** renders the REAL admin page and asserts the form contract with
  DOMXPath (action input, enctype, method, file input name/`multiple`, nonce,
  `submit_button()` control, and that the action is never a form attribute).
- **TEST 2** proves the `submit` binding and that `preventDefault()` is the
  handler's first executable statement.
- **TEST 3** proves the JSON/redirect response switch and that it is decided
  only after the capability and nonce checks.
- **TEST 4 (negative control)** removes `preventDefault()` from a copy of the
  handler and asserts the suite **detects** it; a second mutation moves it after
  an early return and asserts detection there too.

### 7.2 Browser gate (opt-in)

`tests/browser/verify-multi-file-import-browser.py` — real Chromium, explicitly
invoked (it needs Playwright, which is not a blocking dependency). Exit `0`
pass, `1` fail, `2` environment unavailable (never a silent pass).

```bash
python3 tests/browser/verify-multi-file-import-browser.py
python3 tests/browser/verify-multi-file-import-browser.py --negative-control
```

### 7.3 Single-file regression

`initImportForms()` still binds the shared handler to
`form[data-conexao-import-form]` but explicitly skips the multi-file form. The
single-file import form is untouched, its native POST is unchanged, and no other
admin form or action on the page is affected.

---

## 8. Acceptance criteria

| # | Criterion | Evidence |
|---|---|---|
| 1 | No unintended page refresh | URL identical before/after; 0 document POSTs |
| 2 | Submit event intercepted | delegated `submit` listener; trace `submit` fired |
| 3 | `preventDefault()` executes reliably | first statement; trace `prevented: true` |
| 4 | All selected files in the request | FormData files = 2 of 2; staging body carries 2 |
| 5 | Orchestration starts | 1 staging request |
| 6 | Every file processed | 2 per-file requests; 2 file rows `✓` |
| 7 | UI stays on the importer | URL unchanged; progress UI rendered in place |
| 8 | Truthful results | `Files processed: 2 / failed: 0`; per-file counts |
| 9 | Keyboard submission works | focus submit control + Enter → 4 POSTs, no navigation |
| 10 | Single-file import still works | shared handler scoped; single form untouched |
| 11 | No duplicate requests | total POSTs = 1 + N + 1 exactly |
| 12 | Backend tests remain green | backend suite **149/149** |
| 13 | Regression test catches handler removal | in-process TEST 4 + browser `--negative-control` |
| 14 | Full tests + gates with numbers | see §9 |
| 15 | No production writes | §10 |

---

## 9. Gates

| Gate | Result |
|---|---|
| `tests/test-multi-file-import-ui.php` (new) | **41 passed, 0 failed** |
| `tests/test-multi-file-import.php` (backend) | **149 passed, 0 failed** |
| Browser happy path (Chromium) | **49 passed, 0 failed** |
| Browser negative control | **3 passed, 0 failed** (defect detected) |
| `node --check assets/admin.js` | **OK** |
| `php -l` on changed PHP | **OK** |
| `./scripts/run-tests.sh` | in-process **96/100** suites, **7522 passed / 6 failed** assertions; script-contract **17/19**; HTTP acceptance **3/4** |
| `scripts/verify-permanent-gates.py` | **6/7 gates**, 84 assertions (83 passed), **1 violation, 1 pre-existing, 0 new** |

Raw output: `docs/evidence/2026-10-05-multi-file-import-browser/run-tests.sh.log`.

### The 7 remaining failures — all pre-existing

Each is the **same** failure the backend repair report already attributed and
proved pre-existing by stashing that change (`2026-10-05-multi-file-event-import-repair.md` §14).
None touches the files changed here.

| Suite | Cause |
|---|---|
| `conexao-event-importer/test-export-language.php` (exit 255) | Memory exhausted at 512 MB in `class-event-export.php:937` — the **export** side |
| `conexao-event-importer/test-town-sanitization.php` (82/1) | 3 Eventbrite-sourced `conexao_town` terms (G8–G16 taxonomy drift) |
| `theme/test-event-location-filters.php` (62/1) | Local archive holds more events than the suite's 4 fixtures expect |
| `theme/test-stage41-rest-language.php` (218/4) | County-filter / B2-fallback assertions against drifted fixtures |
| `verify-event-occurrence-http.py` (44/5) | Date-dependent archive assertions against drifted fixtures |
| `verify-i18n-freshness.py` | Pre-existing stale `conexao-event-runtime` catalogue (`new: 0`) |
| `verify-eventbrite-token-retirement.py` | Pre-existing `tests/experiments/` reference |

**Newly introduced failures: none.** No gate was weakened, and no test was
deleted, skipped or edited to pass.

---

## 10. Production safety

- **No production writes**, no deployment, no production access. All work used
  the local Docker stack and deterministic `[BRBROWSER]` fixtures, deleted at the
  end (verified: 0 leftover events).
- `conexao-event-importer` is local-only tooling (`production: false`); the
  production steady state is unchanged.
- No Polylang, translation, event-source, recurrence-semantics or visibility
  change.
- The external Flutter/mobile repository was never accessed.

---

## 11. Limitations

- The browser suite is **opt-in** because it needs Playwright, which the
  repository's default layers deliberately do not depend on. The automated
  browser click **was** executable in this environment (Chromium is installed),
  and its real results are recorded above — but it is not a blocking CI gate, so
  a CI runner without Playwright would skip it rather than fail it. The
  deterministic, browserless half of the same contract **is** blocking (the
  in-process suite), including the `preventDefault()` negative control.
- The staging body cannot be parsed by Playwright (binary multipart), so the
  file count in that request is proven by the client-side `FormData` assertion
  plus the server-staged `file_count` and the per-file requests, not by reading
  the multipart body.
- Verified in local Docker against deterministic fixtures only.

## 12. Explicit event trace (fixed path)

```
click
→ submit event fired
→ multi-file handler matched (hidden action input discriminator)
→ preventDefault()   (prevented: true)
→ FormData files=2
→ stage request 200 (JSON: batch_token + file_count=2)
→ 2 import requests (200 + 200)
→ 2 completed
→ cleanup request 200
→ result rendered (Files processed: 2 / failed: 0)
→ URL unchanged
→ 0 document POSTs
```

_Last verified: 2026-10-05 by the multi-file importer browser-workflow repair._

