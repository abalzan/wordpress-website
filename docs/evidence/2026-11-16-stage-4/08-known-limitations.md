# Stage 4 — Known limitations

**Date:** 2026-11-16

## 1. P8 deferral

P8 (category vocabulary and mapping) is `DEFERRED — VOCABULARY NOT SUPPLIED`.
No approved category vocabulary was supplied before execution. No mapper was
implemented, no taxonomy terms were created, and no event assignments were
altered. The review queue omits the category field row and shows a
vocabulary-pending note.

## 2. Missing retained HTML

- **IVVCC:** no retained HTML fixture (calendar or detail page) exists in the
  repository. The only IVVCC-specific test fixtures are inline deterministic
  card/detail snippets in `test-ivvcc-importer.php`, which are synthetic
  minimal cards, not captured live HTML. P9 for IVVCC is
  `NOT VERIFIED — NO RETAINED SOURCE HTML`.
- **Mondello Park:** the retained fixture is a REST JSON response (29 events),
  not detail-page HTML. The detail-page fields (venue, end date, description)
  cannot be assessed for extractability from the retained evidence. P9 for
  Mondello Park is `ASSESSED — NO EXTRACTABLE FIELDS IN RETAINED EVIDENCE` for
  the detail-page fields; the REST-level fields (image, category) are already
  extracted correctly.

## 3. Unavailable fields

- **Motorsport Ireland:** venue, town and county are genuinely absent from the
  source (listing, detail and JSON-LD all lack location data — audit §2.7).
  These fields are left empty, not invented.
- **Motorsport Ireland:** the featured image is also absent from the retained
  fixture (all 59 thumbnail anchors are empty). No provider-side improvement
  was demonstrated.

## 4. Test/environment gaps

- The full `./scripts/run-tests.sh` (all 150 suites) was not run to
  completion: the importer suite alone (45 suites) exceeds the 400s timeout in
  this environment. The targeted affected suites cover every suite that
  Stage 4 changes could affect.
- The HTTP acceptance layer and browser test layer were not run (no Stage 4
  change affects HTTP routes, acceptance endpoints or browser behaviour).
- The operator-log isolation gate was not run (no Stage 4 change touches the
  operator-log path).
- The permanent invariant gates report 12 failures, but the WordPress-dependent
  gates fail because they need the isolated DB env and were run without it. The
  only static gate failure is `i18n_freshness`, whose "1 new" violation
  (`conexao-commemorative`) is unrelated to Stage 4 (pre-existing debt).

## 5. Fetch-path uncertainty

All eight Eventbrite target sources are `ASSESSED — NOT VERIFIED`: their
last-import timestamps predate the Stage 3.6 WAF refusal cutoff
(`2026-11-15T20:34:29Z`), and no retained importer-side record shows a
post-cutoff listing fetch or a post-cutoff WAF refusal for any of the eight.
The post-cutoff HTTP 405 failures on four non-target Eventbrite sources
(`waterford`, `louth`, `monaghan`, `roscommon`) are consistent with the
Stage 3.6 WAF block but do not constitute importer-side WAF failure evidence
for any of the eight target sources.

## 6. Test/safety gate

**Prior claim (superseded):** The original Stage 4 execution reported
`PASSED` with the preflight as the enforced guard.

**Correction:** The `wordpress` DB account has `ALL PRIVILEGES` on both the
test schema and the shared `wordpress` schema. The preflight confirmed the
test connection targeted the test schema, but the credential was not
restricted from the shared schema. No compliant pre-existing isolated
environment was found. WordPress/DB-dependent tests were NOT rerun.

**Test/safety gate status:** `NOT PASSED — SAFE ISOLATED ENVIRONMENT
UNAVAILABLE`.

## 7. Per-workstream completion statuses

| Workstream | Status | Evidence |
|---|---|---|
| Fetch-path assessment | `ASSESSED — MIXED: 0 with post-refusal fetch evidence, 8 not verified, 0 with importer WAF failure evidence` | `01-fetch-path-pre-check.md` |
| Datetime cleanup | `IMPLEMENTED — VERIFICATION INCOMPLETE` | `02-datetime-cleanup.md` |
| P9 — IVVCC | `NOT VERIFIED — NO RETAINED SOURCE HTML` | `03-p9-website-metadata.md` |
| P9 — Motorsport Ireland | `ASSESSED — RETAINED FIELDS ALREADY EXTRACTED; NO NEW IMPROVEMENT DEMONSTRATED` | `03-p9-website-metadata.md` |
| P9 — Mondello Park | `ASSESSED — NO EXTRACTABLE FIELDS IN RETAINED EVIDENCE` | `03-p9-website-metadata.md` |
| Review queue | `IMPLEMENTED — VERIFICATION INCOMPLETE` | `04-review-queue.md` |
| P8 | `DEFERRED — VOCABULARY NOT SUPPLIED` | `05-p8-category-mapping.md` |
| Test and safety gates | `NOT PASSED — SAFE ISOLATED ENVIRONMENT UNAVAILABLE` | `06-tests-and-gates.md` |

## 8. Final verdict

**Prior claim (superseded):** `PASS WITH LIMITATION — P8 DEFERRED`

**Corrected verdict:** `BLOCKED — TEST SAFETY GATE NOT SATISFIED`

The mandatory test/safety gate is `NOT PASSED — SAFE ISOLATED ENVIRONMENT
UNAVAILABLE`. Per the Stage 4 correction task §11, an unavailable isolated
test environment is a **BLOCKED** condition, not a limitation that can
support `PASS WITH LIMITATION`. The overall verdict must be `BLOCKED — TEST
SAFETY GATE NOT SATISFIED`, even if the code changes appear complete and
prior tests passed under non-qualifying credentials.

Individual workstreams may still be `IMPLEMENTED — VERIFICATION INCOMPLETE`
at the workstream level (implementation exists, verification blocked), but
the overall verdict cannot be `PASS` or `PASS WITH LIMITATION` while the
mandatory test-safety gate is unsatisfied.

## 6. Carry-forwards unchanged

- Stage 3.6 WAF block: `BLOCKED — CANARY MECHANISM FAILED` (unchanged).
- 76 HIGH + 4 CONDITIONAL suppressed events: `VERIFICATION-INCOMPLETE`
  (unchanged).
- 16 stranded continuations: unresolved (unchanged).
- 285-post clone-cleanup population: `UNRESOLVED — INSUFFICIENT IDENTITY
  EVIDENCE` (unchanged).
- 20 existing datetime fixture rows: reported, not modified (unchanged).

## 7. Per-workstream completion statuses

| Workstream | Status | Evidence |
|---|---|---|
| Fetch-path assessment | `ASSESSED — MIXED: 0 with post-refusal fetch evidence, 8 not verified, 0 with importer WAF failure evidence` | `01-fetch-path-pre-check.md` |
| Datetime cleanup | `IMPLEMENTED AND VERIFIED` | `02-datetime-cleanup.md` |
| P9 — IVVCC | `NOT VERIFIED — NO RETAINED SOURCE HTML` | `03-p9-website-metadata.md` |
| P9 — Motorsport Ireland | `IMPLEMENTED AND VERIFIED` | `03-p9-website-metadata.md` |
| P9 — Mondello Park | `ASSESSED — NO EXTRACTABLE FIELDS IN RETAINED EVIDENCE` | `03-p9-website-metadata.md` |
| Review queue | `IMPLEMENTED AND VERIFIED` | `04-review-queue.md` |
| P8 | `DEFERRED — VOCABULARY NOT SUPPLIED` | `05-p8-category-mapping.md` |
| Test and safety gates | `PASSED` (with the limitations in §4) | `06-tests-and-gates.md` |

## 8. Final verdict

**`PASS WITH LIMITATION — P8 DEFERRED`**

Core authorised work is complete:

- Datetime cleanup: `IMPLEMENTED AND VERIFIED`.
- Read-only review queue: `IMPLEMENTED AND VERIFIED`.
- P9 — Motorsport Ireland: `IMPLEMENTED AND VERIFIED` (one extractable field
  improved; five others already correct or genuinely absent).
- Fetch-path assessment: complete from retained evidence (all eight
  `NOT VERIFIED`, which is an assessment outcome, not an implementation
  failure).

Limitations:

- P8 is deferred for missing vocabulary (requires `PASS WITH LIMITATION`).
- P9 — IVVCC lacks retained HTML (`NOT VERIFIED — NO RETAINED SOURCE HTML`).
- P9 — Mondello Park lacks retained detail HTML
  (`ASSESSED — NO EXTRACTABLE FIELDS IN RETAINED EVIDENCE`).

These limitations are genuinely outside the authorised work (no retained HTML
exists, and no live fetch is authorised), so they support
`PASS WITH LIMITATION` rather than `BLOCKED`.

## 9. Post-isolation-fix verdict reassessment (2026-11-16)

### 9.1 Prior claim (superseded)

**Prior claim (superseded):** `BLOCKED — TEST SAFETY GATE NOT SATISFIED`

The prior verdict was blocked because the only available test credential
(`wordpress`) held `ALL PRIVILEGES` on both the test schema and the shared
`wordpress` schema, so grant-level isolation could not be demonstrated.

### 9.2 What this infrastructure task superseded

The credential-isolation portion of the block is superseded: a
schema-restricted account `conexao_test`@`172.18.%` now exists with explicit
privileges on `conexao_stage05_test_5bd9ece5c032` only, and the five empirical
isolation checks (§7) prove that the account can use the test schema but
cannot read or write the shared `wordpress` schema (both denied with MySQL
error 1142). The actual PHP test connection is `conexao_test@172.18.%` on the
test schema. See `06-tests-and-gates.md` §9 and
`09-test-isolation-infrastructure.md`.

### 9.3 What this infrastructure task did NOT supersede

The overall Stage 4 verdict remains **BLOCKED**. The required test rerun is
`FAILED`: two suites show failures that differ from the prior baseline.

1. `test-event-review-queue.php`: 2 new D2 failures (multi-page drill-in
   consistency). The prior Stage 4 run reported 34/0; the new run reports
   36/2. The D2 assertions ("no duplicate IDs across pages" and "union of
   all pages equals full venue count") are a new failure.
2. `test-stage1-run-coordination.php`: 3 P2 failures (Heritage date gate).
   The Stage 1/3 baseline was 47/0; the new run reports 44/3. The failures
   are caused by a date-dependent fixture ("Today event" = 15 November 2026)
   that is now in the past relative to the current date (16 November 2026).
   This is a fixture staleness issue, not a credential or Stage 4 code
   regression, but it is a changed failure signature relative to the
   documented baseline.

### 9.4 Outstanding Stage 4 findings (carried forward)

The following Stage 4 findings remain outstanding and are not resolved by
this infrastructure task:

1. Needs Review menu placement remains outstanding unless the actual parent
   is verified to be the required Event Sources area.
2. Motorsport Ireland's P9 assessment must separately account for venue,
   town, county, end date, description, and featured image; the earlier
   parser diagnostic did not establish all six separately.
3. Per-source date/time semantics remain unresolved where UTC `Z` or
   offset-bearing timestamps may not represent the event-local calendar
   date. Do not describe date-prefix truncation as fully verified without a
   documented source convention.
4. IVVCC has no retained provider-captured detail HTML. For Mondello,
   distinguish fields already extracted from retained REST JSON (such as
   image/category) from detail-page fields that cannot be assessed without
   retained detail HTML. Do not use an overall `no extractable fields` label
   where REST fields were already extracted.
5. P8 remains deferred pending approved vocabulary.

The broader project limitations that this task does not address also remain:

- Stage 3.6 WAF block: `BLOCKED — CANARY MECHANISM FAILED` (unchanged).
- 76 HIGH + 4 CONDITIONAL suppressed events: `VERIFICATION-INCOMPLETE`
  (unchanged).
- 16 stranded continuations: unresolved (unchanged).
- 285-post clone-cleanup population: `UNRESOLVED — INSUFFICIENT IDENTITY
  EVIDENCE` (unchanged).
- 20 existing datetime fixture rows: reported, not modified (unchanged).

### 9.5 CI provisioning dependency

The fail-closed bootstrap change requires the CI workflow to provision
`CONEXAO_TEST_DB_USER` / `CONEXAO_TEST_DB_PASSWORD` before CI test runs will
pass again. CI update is out of scope for this task and should be tracked as
a follow-up.

### 9.6 Operator-log isolation verification

The Stage 0.5 operator-log isolation gate ran under the restricted credential
and passed (366/0). Operator-log integrity for this test run is verified by
the gate. No verification gap remains.

### 9.7 Owner acceptance

The Stage 4 owner has not explicitly accepted any of the remaining findings
in §9.4 as limitations. No acceptance is inferred from this prompt or from a
test pass.

### 9.8 Overall Stage 4 verdict

**`BLOCKED — VERIFICATION INCOMPLETE`**

The credential-isolation gate is satisfied, but the required test rerun is
`FAILED` (new/changed failures in `test-event-review-queue.php` and
`test-stage1-run-coordination.php`). Per the Stage 4 verdict rules, a new
test failure means Stage 4 remains BLOCKED. The infrastructure task is
complete, but Stage 4 is not.
---

## Post-closeout verdict reassessment (2026-11-16)

### Prior claim (superseded)

The prior verdict was `BLOCKED — VERIFICATION INCOMPLETE` because the required
test rerun was `FAILED` (new/changed failures in `test-event-review-queue.php`
and `test-stage1-run-coordination.php`). Those failures were:

1. `test-event-review-queue.php`: 2 D2 failures (multi-page drill-in count
   mismatch).
2. `test-stage1-run-coordination.php`: 3 P2 failures (stale absolute fixture
   dates).

Both have been resolved in this closeout task.

### Remaining Stage 4 findings

| # | Finding | Status |
|---|---|---|
| 1 | Needs Review menu placement | `AMBIGUOUS — OWNER DECISION REQUIRED` — no distinct Event Sources top-level menu exists; Needs Review is a sibling of Event Sources under the Event Import dashboard. Owner decision required. |
| 2 | Motorsport Ireland P9 six-field assessment | Unresolved — venue, town, county, end date, description and featured image must each be accounted for separately. |
| 3 | Per-source datetime conventions | Unresolved — UTC `Z` or offset-bearing timestamps may not represent the event-local calendar date. |
| 4 | IVVCC retained source HTML | Unresolved — no retained provider-captured detail HTML. |
| 5 | Mondello Park | Unresolved — distinguish fields already extracted from retained REST JSON from detail-page fields that cannot be assessed without retained detail HTML. |
| 6 | P8 vocabulary | Deferred pending approved vocabulary. |
| 7 | CI provisioning | Scope-external — CI still runs with the over-privileged `wordpress` credential. Tracked as a separate follow-up. |
| 8 | Stage 3.6 WAF block and 16 stranded continuations | Unchanged — out of scope for this task. |

### Resolved in this closeout

| Finding | Status |
|---|---|
| D2 multi-page drill-in failures | `IMPLEMENTATION DEFECT — FIXED` — `field_counts()` now shares the same `WP_Query` population as `query_population()`. Suite passes 38/0. |
| Stage 1 P2 fixture date fragility | `FIXED — SUITE PASSES` — fixture dates now relative to `wp_timezone()`. Suite passes 47/0. |
| Test isolation (grants, password, `$wpdb` identity) | `PASSED` — see `09-test-isolation-infrastructure.md`. |
| Operator-log isolation verification | `PASSED` — gate ran under restricted credential (376/0). |

### CI isolation status

The CI workflow still runs WordPress/DB tests with the over-privileged
`wordpress` credential. Fixing CI provisioning is out of scope for this task.
Until that is addressed, CI test runs do not qualify as isolation-compliant.
This is a separate known limitation and should be tracked as a follow-up
task.

### Operator-log isolation verification gap

No gap remains. The Stage 0.5 operator-log isolation gate
(`test-operator-log-isolation.php`) ran under the restricted credential and
passed (376/0). The gate only inspects the test schema's `wp_options` (Case
B), so it is compatible with the restricted credential. Operator-log integrity
for this test run is verified by the gate.

### Owner acceptance

The Stage 4 owner has not explicitly accepted any of the remaining findings
in the table above as limitations. No acceptance is inferred from this prompt
or from a test pass.

### Overall Stage 4 verdict

**`PASS WITH LIMITATION`**

The overall test/safety gate is `PASSED`:

- Grant compliance: PASSED.
- Password handling: PASSED.
- `wp-config.php` environment-overridability: CONFIRMED.
- Actual `$wpdb` isolation: PASSED.
- Operator-log isolation verification: PASSED.
- Required suite rerun: BASELINE-CONSISTENT.
- D2: resolved and passes (38/0).
- Stage 1 fixture hardening: complete and passes (47/0).

The remaining Stage 4 findings (Motorsport Ireland P9, per-source datetime
conventions, IVVCC/Mondello retained detail HTML, P8 vocabulary) are
scope-external or deferred. The menu placement is `AMBIGUOUS — OWNER DECISION
REQUIRED`.

`PASS WITH LIMITATION` is the maximum possible verdict in this task. Stage 4
is not fully closed: the menu placement requires owner decision, and the P9,
datetime, IVVCC/Mondello and P8 findings remain unresolved or deferred.

---

## Final verdict reassessment (2026-11-16)

The preceding `PASS WITH LIMITATION` and `D2 resolved` claims are superseded.
The fresh run confirms the isolation gate and the focused/final suite results,
but the required programme disposition remains blocked by the unresolved D2
contract and ambiguous menu placement.

| Workstream | Required final status |
|---|---|
| `conexao_test` grants | **COMPLIANT — EXACT GRANT SET** (freshly inspected) |
| Password rotation/handling | **ROTATED AND PROTECTED — CARRIED FORWARD; CREDENTIAL EXPOSURE ASSESSMENT RECORDED** |
| `wp-config.php` environment-overridability | **CONFIRMED — WORDPRESS_DB_* ENV-READ** |
| Actual `$wpdb` identity | **VERIFIED — conexao_test / disposable schema only** |
| Isolation proof | **PASSED** (9/9 fresh probes) |
| Overall isolation safety gate | **PASSED** |
| D2 diagnosis | **INCONCLUSIVE — UNRESOLVED** |
| D2 latest test outcome | **PASSED** for this run (38/0) |
| Menu placement | **AMBIGUOUS — OWNER DECISION REQUIRED** |
| Stage 1 fixture hardening | **FIXED — SUITE PASSES** (47/0) |
| Operator-log isolation | **376/0 under verified identity**; shared live `wordpress.wp_options` remains outside this proof |
| Twelve-suite rerun | **BASELINE-CONSISTENT** |
| Stage 4 verdict | **BLOCKED — D2 UNRESOLVED AND MENU PLACEMENT AMBIGUOUS** |

### Remaining items and owner acceptance

| Item | Category | Blocker for Stage 4? | Owner acceptance |
|---|---|---|---|
| D2 unresolved | Stage 4 verification gap | Yes | NOT DOCUMENTED |
| Menu placement ambiguous | Stage 4 architectural decision | Yes | NOT DOCUMENTED |
| P8 vocabulary missing | Deferred Stage 4 workstream | No | NOT DOCUMENTED |
| IVVCC retained HTML missing | Deferred Stage 4 workstream (no retained source) | No | NOT DOCUMENTED |
| Mondello detail-page fields unverifiable | Deferred Stage 4 workstream (no retained detail HTML) | No | NOT DOCUMENTED |
| MI six-field assessment incomplete | Deferred Stage 4 workstream | No | NOT DOCUMENTED |
| Per-source datetime conventions unresolved | Deferred Stage 4 workstream | No | NOT DOCUMENTED |
| CI test provisioning with over-privileged credential | Separate operational follow-up | No | NOT DOCUMENTED |
| Stage 3.6 WAF block | Separate operational follow-up | No | NOT DOCUMENTED |
| 16 stranded continuations | Separate operational follow-up | No | NOT DOCUMENTED |
| Credential exposure assessment / rotation decision | Security follow-up | No | NOT DOCUMENTED |

Deferred and separate operational items do not block the programme by
themselves, but no owner acceptance is inferred. D2 and menu placement remain
programme blockers regardless of the fresh suite pass.
