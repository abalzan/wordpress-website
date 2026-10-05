# Multi-file event importer — root cause and repair

**Stage:** Event importer tooling repair
**Date:** 2026-10-05
**Branch:** `master`
**Base commit:** `a22b4c6` ("Add reconciliation tests and internal query handling
for source_not_found events")

Supersedes the acceptance claims of
[`docs/importers/event-multipart-import-report.md`](../importers/event-multipart-import-report.md) §8
and
[`docs/importers/event-multipart-import-timeout-report.md`](../importers/event-multipart-import-timeout-report.md) §9,
which reported the multi-file workflow as PASSING. It never worked. This report
documents why, what was fixed, and what is now proven.

---

## 1. Summary

The multi-file importer did not work for **any** file count — not 1, not 2, not
5. Three independent defects each broke the chain, and each was sufficient on
its own. The existing reports recorded green results because every assertion
they made was satisfied by a response the code returned while importing nothing.

| Layer | Defect | Effect |
|---|---|---|
| Orchestration | Batch token minted with `wp_hash()` (32 hex) while both validators required 64 hex | **100% failure** — every file returned `Invalid batch token.` |
| Importer contract | `validate_upload()` required `is_uploaded_file()`; staged files are not HTTP uploads | **100% failure** — every file returned `The uploaded file could not be read.` |
| UI orchestration | `admin.js` matched `form[action*="conexao_import_events_multi"]`, but the action is a hidden *input* | **Orchestration never started** — batch staged, then silently abandoned |
| Importer lifecycle | The transfer importer's identity lookups ran outside the G17 internal scope | `source_not_found` reappearance duplicated a native event outside wp-admin/WP-CLI |

Measured chain, before → after, on the same fixture set:

```
selected 5 -> submitted 5 -> received 5 -> staged 5 -> imported 0, 5 file errors
selected 5 -> submitted 5 -> received 5 -> staged 5 -> imported 5, 0 file errors
```

---

## 2. Root cause

### 2.1 Primary — a self-contradictory batch-token contract

`Conexao_Event_Multi_Import::stage_uploads()` minted:

```php
$batch_token = wp_hash( uniqid( 'multi_import_', true ) . wp_rand(), 'auth' );
```

`wp_hash()` uses **md5** by default and returns **32** hex characters. Both
consumers validated a **64**-character hex string:

```php
preg_match( '/^[a-f0-9]{64}$/', $batch_token );   // import_staged_file()
preg_match( '/^[a-f0-9]{64}$/', $batch_token );   // cleanup_batch()
```

So `stage_uploads()` could mint a token that no other method would ever accept.
Proven in-process:

```
token      : [a36d448485d73212c008548ed52c31c6]
strlen     : 32
matches    : 0        <- rejected by the class's own validator
raw wp_hash: b5a950cadbd50e715a43cc1e74ccd364
```

This is why the failure was total and silent: the browser received a batch
token, reported it to the operator, and every subsequent per-file request was
refused with an opaque message. The timeout report §5 describes this regex as
the *security model* being verified — the regex was real, and the code could not
satisfy it.

### 2.2 Second — the staged file is not an HTTP upload

The request-per-file architecture stages uploads on disk and re-reads them in a
**later** request. `Conexao_Event_Import::validate_upload()` gated on
`is_uploaded_file()`, which is necessarily false for a file the server itself
copied:

```
is_uploaded_file(staged path) = false
validate_upload => WP_Error invalid_upload: The uploaded file could not be read.
```

Even with a valid token, every file would still have imported nothing.

### 2.3 Third — browser orchestration never started

`admin.js` located the multi-file form with:

```js
document.querySelector('form[action*="conexao_import_events_multi"]')
```

The form posts to `admin-post.php` and carries its action in a hidden **input**.
Measured against the real rendered admin HTML:

```
form[action*="conexao_import_events_multi"]  -> 0 match(es)
form containing hidden action input           -> 1 match(es)
  its action attribute: http://localhost:8080/wp-admin/admin-post.php
  => initMultiFileImport() bails at `if (!multiForm) return;`
```

The batch was staged server-side and then abandoned: no progress UI, no
per-file requests, no result, no error.
---

## 3. Architecture (unchanged by this repair)

The established architecture was kept exactly; only the broken links were
repaired. No second importer, no second deduplicator, no parallel code path.

```
Browser  -- POST (all N files in ONE multipart request)
             |
             v
  stage_uploads()                    <- validates, orders, copies to a
             |                        per-batch staging dir, mints a token
             |  batch token (64 hex)
             v
  admin.js orchestration             <- ONE request per file
             |
             v
  import_staged_file(token, index)   <- schema check, SHA-256, then the
             |                        EXISTING Conexao_Event_Import::import_file()
             v
  Conexao_Event_Import::import_file()  <- unchanged identity ladder:
             |                           UUID -> source+source_id -> URL -> content
             v
  cleanup_batch(token)
```

Per-file results are aggregated in the browser, exactly as the timeout report §3
specified. That design was correct; only its links were broken.

---

## 4. The fixes

Each fix is at the smallest correct architectural layer.

### 4.1 Token contract — one generator, one validator (`class-event-multi-import.php`)

- `generate_batch_token()` mints `hash( 'sha256', wp_salt( 'auth' ) . uniqid( … ) . wp_rand( … ) )`
  — a true **64**-character token satisfying the existing, unchanged contract.
- `is_valid_batch_token()` is now the **single** validator used by both
  `import_staged_file()` and `cleanup_batch()`, so the contract cannot drift again.
- `conexao_multi_import_batch_token` allows deterministic testing; a filtered
  value that does not satisfy the contract is ignored, so a filter cannot
  weaken it.

### 4.2 Provenance — containment-checked, not a bypass (`class-event-import.php`)

`validate_upload()` now accepts a file that is either a genuine HTTP upload
**or** a path inside a caller-declared `trusted_root`:

- The default path is **byte-identical** to before (`is_uploaded_file()`), so
  single-file behaviour is unchanged.
- The opt-in is **not** a bypass flag: the importer resolves `realpath()` on
  both the file and the root and requires containment, so `..` traversal and
  symlink escapes are resolved before the comparison.
- Every other check (error code, size, extension, JSON, manifest format,
  events array) is unchanged.
- The orchestrator passes its own `$batch_dir`.

### 4.3 UI orchestration (`assets/admin.js`)

`findMultiFileForm()` matches the hidden action input — the reliable
discriminator — instead of a form action attribute that never contained it.
The submit-button lookup also accepts `<input type="submit">`, which is what
`submit_button()` actually renders, so the loading/duplicate-submit guard works.

### 4.4 Truthful per-file reporting (`class-event-multi-import.php`)

`import_staged_file()` previously set `success = true` **unconditionally**,
even when the importer had returned errors — a failed file was displayed as a
success with zero events. Now `success` is false whenever the file carries
errors or a failed event count, and `status` is derived from it.

### 4.5 G17 scope for the transfer importer (`class-event-import.php`)

The G17 `Conexao_Event_Internal_Query` scope was applied to the source
deduplicator only. The **transfer** importer's four identity lookups
(`find_by_uuid`, `find_by_source`, `find_by_url`, `find_by_content`) ran
outside it, so outside wp-admin/WP-CLI the public `_event_status` gate applied
to importer-internal queries and a reappearing `source_not_found` event
resolved to nothing and was **inserted a second time** — the G16 defect,
reproduced through the multi-file path.

They now run inside `Conexao_Event_Internal_Query::run()` and carry the same
sentinel, **reusing the existing authoritative scope** rather than adding a
mechanism. The public visibility rule is untouched.

### 4.6 Failure isolation, cleanup and truthful UI

- A file PHP rejected before staging (oversized/partial) is no longer staged at
  all and no longer discards the rest of the batch; it is reported per file.
- A `copy()` failure mid-batch now removes the partially created batch
  directory instead of orphaning it.
- `conexao_multi_import_max_files` bounds a batch (default 50).
- Staging errors and rejected files are now **rendered**. Previously the handler
  redirected with an error code that nothing displayed — a silent bounce.
- Progress-UI strings were hardcoded Portuguese in JS; they are now declared in
  the localized payload so they are translatable and covered by the i18n gate.

---

## 5. Per-file results (actual numbers)

From the reproduction harness against local Docker, same fixture set:

| Case | Files | Staged | Imported | Created | Updated | Failed | Final events |
|---|---|---|---|---|---|---|---|
| A — one file | 1 | 1 | 1 | 2 | 0 | 0 | +2 |
| B — two files | 2 | 2 | 2 | 4 | 0 | 0 | +4 |
| C — five files | 5 | 5 | 5 | 5 | 0 | 0 | +5 |
| D — mixed | 5 | 5 | 2 | 2 | 0 | 3 | +2 |
| D — re-run (idempotency) | 5 | 5 | 2 | 0 | 2 | 3 | +0 |

Case D is the failure-isolation proof: valid, invalid-JSON, empty, and
unsupported-extension files in one batch → the valid files **after** the
failures still imported, and each failure named its own file.

Before the fix, every row read `imported 0, N file errors`, e.g.

```
file[0] a.json  success=false status=failed created=0 ... errors=1 | Invalid batch token.
```

---

## 6. Error semantics

The architecture's documented semantics are **per-file independence with
stop-on-first-failure** (timeout report §6): earlier files stay committed, the
failing file is marked `failed`, the rest are marked "not processed". That
behaviour is preserved; it was simply never reached.

What changed is truthfulness: a file carrying errors is no longer reported as a
success, and staging failures are surfaced instead of silently swallowed.

---

## 7. Identity and deduplication

No deduplication system was added or replaced. The existing identity ladder in
`Conexao_Event_Import` remains authoritative and is proven through the batch:

| Scenario | Result |
|---|---|
| Independent events across files | both imported |
| Same file twice in one batch | exactly one event |
| Same file re-imported | 0 created, identity keeps its native ID |
| Two files sharing one event | exactly one event |
| Different providers, same logical event (URL match) | 1 created + 1 updated = **one** event |

---

## 8. G17 compatibility

A `source_not_found` event re-imported through a multi-file batch keeps its
**original native ID** and leaves `source_not_found`, while public event queries
still cannot see it:

```
TEST 8: the reappearing event kept its native ID (no duplicate insert)
TEST 8: the reappearing event is no longer source_not_found
TEST 11: a source_not_found event stays hidden from public event queries
TEST 11: the internal scope still resolves a hidden event
TEST 11: the internal scope is always closed again (no leak)
```

No `WP_CLI` dependency was introduced; the suite asserts it runs on the plain
path.

---

## 9. Security

No check was weakened.

- `is_uploaded_file()` remains the default and only provenance for the
  single-file path.
- The staged-file opt-in is containment-verified against a root the caller owns.
- Nonce + `manage_options` on every handler, unchanged.
- Batch token still validated as 64-char hex; traversal tokens refused.
- Filenames sanitised with `sanitize_file_name()`; staged dir `.htaccess` denied.
- Negative tests: empty/scalar/name-less `$_FILES`, traversal tokens
  (`../../etc/passwd`), out-of-range indexes, over-count batches, oversized
  uploads. A traversal attempt leaves `/etc` untouched.
- Per-file errors are asserted to contain **no filesystem path**.

---

## 10. Cleanup

Staging-directory count is asserted before, during and after every batch:

- after a fully successful batch — directory removed;
- after a batch containing a failing file (partial multi-file processing) —
  directory removed;
- after an oversized-file rejection — no directory created;
- after the whole suite — count equal to the starting value.

---

## 11. Performance

10 files × 25 events = 250 events, 227 920 bytes total (local Docker fixtures):

| Measure | Value |
|---|---|
| Files / events | 10 / 250 |
| Total input | 0.2 MB |
| Created / updated | 250 / 0 (clean DB) |
| Staging time | 0.00 s |
| Total duration | 18.59 s |
| **Slowest single file** | **2.69 s** |
| Peak memory (per file / process) | 48.5 MB / 48.5 MB |

The slowest single file at 2.69 s is far below a typical 30–60 s gateway
timeout, confirming the request-per-file architecture solves the original 504
without any limit being raised. Nothing was optimised; no limit was increased.

---

## 12. Tests

`wp-content/plugins/conexao-event-importer/tests/test-multi-file-import.php` —
**149 assertions, 149 passed, 0 failed.**

| Test | Covers |
|---|---|
| 1/2/3 | one, two and five files reach the importer with real identities |
| 4 | mixed success/failure isolation; per-file naming; no path leakage |
| 5 | duplicate files (in-batch and repeated) |
| 6 | overlapping events |
| 7 | A+B vs B+A vs A+B+C vs C+B+A produce an identical identity set |
| 8 | G17 reappearance through a batch keeps its native ID |
| 9 | cross-source dedup |
| 10 | recurrence/occurrence meta preserved |
| 11 | public `source_not_found` hiding unchanged; scope closes |
| 12 | repeated multi-file import is idempotent |
| 13 | staging cleanup (success, partial failure, rejection, whole suite) |
| 14 | malformed upload structures, hostile tokens/indexes, file-count bound |
| 15 | negative control (see below) |

Assertions check real Event identities in the database (`_event_export_uuid`
counts and native IDs), never a response flag alone.

### Negative controls (anti-vacuity)

Each defect was re-injected and the suite re-run:

| Injected defect | Result |
|---|---|
| Token reverted to `wp_hash()` (32 chars) | **77 passed / 73 failed** |
| Scalar `is_uploaded_file()` assumption restored | **90 passed / 60 failed** |
| G17 internal scope disabled | **147 passed / 2 failed** (TEST 8) |
| Orchestrator processes only the first file | **95 passed / 47 failed** |

The suite fails when the fix is removed, so a "multi-file passed" result is not
vacuous. TEST 15 additionally runs a first-file-only mutation **inside** the
suite and asserts the identity checks detect it.

---

## 13. Gates

| Gate | Result |
|---|---|
| `./scripts/lint.sh` | **OK** — syntax clean, no new PHPCS violations, PHPStan clean |
| `./scripts/run-tests.sh` | see §14 |
| Permanent gates (discovered by convention) | taxonomy policy, translation completeness, language-scoped caching, legacy redirect precedence, documentation drift, i18n freshness |
| i18n freshness | `conexao-event-importer` **fresh** (catalogue regenerated after new strings); the one stale catalogue is the pre-existing `conexao-event-runtime` entry |
| `./scripts/generate-registry-docs.php --check` | registry drift gate — no plugin registry change was needed |

---

## 14. Full verification and failure attribution

`./scripts/run-tests.sh` was run **before** the change (baseline) and **after**.
Every remaining failure was then **proved pre-existing** by stashing this
change and re-running that suite — no failure was merely assumed.

### Final run (after)

| Layer | Result |
|---|---|
| In-process PHP | **94 passed / 4 failed** |
| Script contract | **17 passed / 2 failed** |
| HTTP acceptance | **3 passed / 1 failed** |
| `./scripts/lint.sh` | **OK** — syntax clean, no new PHPCS violations, PHPStan clean |
| `./scripts/generate-registry-docs.php --check` | **OK** — 10 plugins, 19 regions, zero writes |
| `scripts/verify-permanent-gates.py` | **7 gates: 6 passed, 1 failed; 84 assertions, 84 passed, 1 failed; 1 violation, 1 pre-existing, 0 new** |

Raw output: `docs/evidence/2026-10-05-multi-file-import/run-tests.sh.log`.

### The 7 remaining failures — all proved pre-existing

| Suite | Proved by re-running with this change stashed |
|---|---|
| `conexao-event-importer/test-export-language.php` (exit 255) | Pre-existing. Memory exhausted at 512 MB in `class-event-export.php:937` — the **export** side, which the multi-file chain never touches. |
| `conexao-event-importer/test-town-sanitization.php` (82/1) | Re-ran stashed → **same failure**. Cause: 3 `conexao_town` terms (`Co galway`, `Co. Wicklow`, `Galway Ireland`) belonging to **Eventbrite-sourced** events (`eventbrite_galway`, `eventbrite_wicklow`) from the G8–G16 work. This change creates no taxonomy terms and its fixtures carry empty taxonomies. |
| `theme/conexao-br-irlanda/test-event-location-filters.php` (62/1) | Re-ran stashed → **same failure**: "unfiltered archive returns all four test events". The local archive holds far more events than the suite's four fixtures expect (same drift as the HTTP suite below). |
| `theme/conexao-br-irlanda/test-stage41-rest-language.php` (218/4) | Re-ran stashed → **same 4 failures** (county-filter and B2 fallback assertions). Unrelated translation component. |
| `tests/acceptance/verify-event-occurrence-http.py` (44/5) | Re-ran stashed → **identical 5 failures** (`found 5 weekly series`, `found 2 multi-day`, `found 3 all-day`, `badge=2026-10-05 today=2026-10-05`, non-occurrence-day label). Date-dependent assertions against a drifted local fixture set. |
| `tests/scripts/verify-i18n-freshness.py` | **Pre-existing**, `conexao-event-runtime` catalogue, `new: 0`. Unchanged before and after. |
| `tests/scripts/verify-eventbrite-token-retirement.py` | **Pre-existing**, Eventbrite token-history component; untouched by this change. |

### Suites that were failing for an environmental reason only

An earlier run reported 22 further failures across `conexao-translation-rollout`,
`conexao-translation-automation` and `conexao-en-translation`. Those were **not**
code failures: the bind mounts for those three plugins had gone **empty inside
the container** (`0` entries) while populated on the host. `docker compose
restart wordpress` restored them (`0 -> 4 / 4 / 3` entries) and the suites then
passed — `test-translation-rollout-engine.php` **36/0**,
`test-automation-translation-plan.php` **157/0**,
`test-shared-slug-newsletter.php` **39/0**. Neither those plugins nor
`compose.yaml` are touched by this change.

**Newly introduced failures: none.** No gate was weakened; no test was deleted,
skipped or edited to pass.

**Fixture hygiene:** the database contains **0** events matching `mfi-%`,
`repro-%`, `perf-%`, or a `[MFI]`/`[PERF]` title — every fixture created by the
reproduction harness, the performance run and the new suite was removed.

---

## 15. Production safety

- **No production writes.** No production deployment. No wp-admin changes on
  production. No WP-CLI production access.
- `conexao-event-importer` is local-only tooling (`production: false` in
  `plugins.json`); production steady state is unchanged.
- All work used local Docker with deterministic fixtures prefixed `[MFI]`,
  `[PERF]` and `repro-`, all deleted at the end (verified: 0 leftover events).
- No Polylang, translation, event-source, recurrence-semantics or public
  visibility change was made beyond the importer-internal scope reuse in §4.5.
- The external Flutter/mobile repository was never accessed.

---

## 16. Acceptance criteria

| # | Criterion | Evidence |
|---|---|---|
| 1 | Multiple selected files received by the backend | TEST 3/4: `5 staged` of 5 submitted |
| 2 | Every intended file processed | TEST 3: 5 results, 5 created |
| 3 | Each file produces an independently verifiable result | §5; per-file `filename` + counts |
| 4 | Single-file import unchanged | §4.2 default path byte-identical; `test-export-language.php` behaviour unchanged |
| 5 | Mixed success/failure deterministic and documented | TEST 4; §6 |
| 6 | Duplicate/overlapping events deduplicated | TEST 5/6/9/12 |
| 7 | Reappearing `source_not_found` restores | TEST 8 |
| 8 | Repeated multi-file import idempotent | TEST 12 (0 created, IDs stable) |
| 9 | File ordering does not change final state | TEST 7 (A+B ≡ B+A, A+B+C ≡ C+B+A) |
| 10 | Temporary uploaded files cleaned up | TEST 13 (success, partial failure, rejection, suite-wide) |
| 11 | Security checks intact | §9; TEST 14 |
| 12 | UI accurately reports per-file outcomes | §4.4, §4.6 |
| 13 | Browser/HTTP/importer tests pass | §12, §14 |
| 14 | Full tests and gates with numeric evidence | §12, §13, §14 |
| 15 | No production changes | §15 |

---

## 17. Limitations

- Verified in local Docker against deterministic fixtures only. **No production
  access, no production writes, no deployment.**
- **No browser automation was available** in this environment (Playwright is not
  installed), so the browser layer was proven by (a) identifying the control-flow
  defect against the real rendered admin HTML via DOMXPath, and (b) `node
  --check` plus a rewritten, i18n-routed selector. The end-to-end click path is
  **not** covered by an automated browser test. This is the one acceptance
  element that is **PASS WITH LIMITATION**.
- The HTTP acceptance layer does not exercise the multi-file admin flow (it
  requires authenticated wp-admin form posts).
- Real multipart exports embedding base64 images are much larger than the 0.2 MB
  fixture; per-file memory headroom (48.5 MB of a 512 MB limit) suggests ample
  headroom, but was not measured at production export sizes.
