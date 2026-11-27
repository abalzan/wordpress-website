# Stage 2 — Event Importer fetch contract, reconciliation safeguards, partial-enrichment protection and reconciliation pagination

| | |
|---|---|
| **Stage / task name** | Stage 2 — Event Importer P3, P4, P5 and P10 implementation |
| **Date** | 2026-11-15 |
| **Branch** | `event-import-improvements` |
| **Actual HEAD at start** | `b5a3ee92be0f3e547be1f3be7e291afd3d924e60` |
| **HEAD at finish** | `b5a3ee92be0f3e547be1f3be7e291afd3d924e60` (no commit) |
| **Working tree at start** | clean |
| **Working tree at finish** | dirty by design: Stage 2 implementation, three new suites, regenerated importer POT, documentation and evidence |
| **Skills applied** | `wp-repository`, `wp-content-change`, `wp-write-in-process-test`, `wp-testing`, `wp-security-review`, `wp-update-docs` |
| **Canonical docs consulted** | `docs/engineering-standard.md`, `docs/testing.md`, `docs/content-model.md`, `docs/plugins/conexao-event-importer.md`, Stage 0/0.5/1 reports and evidence |

> **Note on the referenced audit/roadmap report.** The task refers to a
> 2026-11-14 report titled approximately “Event Importer — Final Audit and
> Implementation Roadmap”. No such file exists at this HEAD (searched `docs/` and
> the full git history; the P-numbered findings are referenced only as an
> external roadmap in `docs/plans/2026-11-14-stage-0-5-test-safety-plan.md`).
> Rather than fabricate or rewrite a historical audit, Stage 2 records its
> section here, in the established stage-report series, and leaves the Stage 0,
> Stage 0.5, Stage 1 and Stage 1 reconciliation reports untouched.

## 1. Scope completed

- **P4 (verification-first).** Inventoried every registered handler from
  `get_source_handler()`, established per handler whether `is_fetch_complete()` /
  `is_reconciliation_safe()` are implemented, whether the fail-open default
  applies and whether it is correct, and recorded the existing test coverage. No
  gate was re-implemented; no optional code was added.
- **P3.** Enforced the explicit fetch contract. Fixed the five handlers that
  returned `array()` on a failed or unparsable retrieval (Mondello Park, Laois
  Tourism, IVVCC, Motorsport Ireland, iCalendar). Eventbrite and Heritage Week
  already conformed and were left unchanged.
- **P5.** Added an additive per-field enrichment state
  (`Conexao_Event_Enrichment_State`), declared it in Heritage Week, and made
  `upsert_event()` preserve stored values for fields whose retrieval failed —
  field by field, with the description's change detection scoped to payloads that
  declare enrichment.
- **P10.** Replaced the 300-post single query in `mark_missing_events()` with
  gate-first, read-only keyset pagination and bounded, revalidated mutation after
  the complete candidate set is known. Mutation also re-checks the importer's
  language guard, so a Polylang translation is never flipped to
  `source_not_found`.

## 2. Scope NOT completed (deliberately)

- No shared-database query, fingerprint or status inventory: no verified
  read-only method exists (see §11).
- No P7, P8, P9, P11, P12 or P13 work.
- No production action, provider request, activation, release, commit or push.
- No change to Event Runtime visibility, `_event_status` semantics, source
  identity, retention or the source registry.

## 3. Files added / modified / deleted

**Added**

- `wp-content/plugins/conexao-event-importer/includes/class-conexao-event-enrichment-state.php`
- `wp-content/plugins/conexao-event-importer/tests/test-source-fetch-contract.php`
- `wp-content/plugins/conexao-event-importer/tests/test-partial-enrichment-preservation.php`
- `wp-content/plugins/conexao-event-importer/tests/test-reconciliation-pagination.php`
- `docs/evidence/2026-11-15-stage-2/` (this evidence set)
- this report

**Modified**

- `wp-content/plugins/conexao-event-importer/conexao-event-importer.php` (loads the new class)
- `wp-content/plugins/conexao-event-importer/includes/class-event-importer.php` (P5 write path, P10 reconciliation)
- `wp-content/plugins/conexao-event-importer/includes/class-event-normalizer.php` (forwards the state)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-heritage-week-source.php` (P5 declarations)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-mondello-park-source.php` (P3)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-laois-tourism-source.php` (P3/P4)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-ivvcc-source.php` (P3/P4)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-motorsport-ireland-source.php` (P3/P4)
- `wp-content/plugins/conexao-event-importer/includes/sources/class-icalendar-source.php` (P3/P4)
- `wp-content/plugins/conexao-event-importer/languages/conexao-event-importer.pot` (regenerated)
- `docs/plugins/conexao-event-importer.md` (Stage 2 section)
- `docs/reports/README.md` (index entry)
- `docs/README.md` (documentation index entry)

**Deleted:** none. **Stage 1 tests:** none modified. **Database:** no schema, user
or privilege change; no shared-database write.

## 4. Runtime impact

- A failing source now always produces a truthful failed/incomplete run instead
  of a silent "success, 0 events".
- A failed Heritage Week detail fetch no longer erases a stored description,
  venue, address, organiser, location label or banner.
- Absence reconciliation covers every source-owned event rather than the first
  300, and it can no longer run on an incomplete or resumed walk.

## 5. Content / data impact

No content was written outside the disposable test database. Reconciliation still
uses the existing `source_not_found` transition; Stage 2 changed only how many
candidates are considered and when the transition is allowed to run.

## 6. Polylang impact

None. No taxonomy policy, language scoping or translation identity was touched.

## 7. Route / HTTP impact

None. No route, redirect, REST endpoint or `/en/` layer file was modified.

## 8. Production actions

None. No production request, credential, activation, upload, database, release or
deployment action was performed.


## 9. Verification commands and results

See `docs/evidence/2026-11-15-stage-2/05-tests.txt` and
`06-quality-gates.txt` for the full transcripts. Summary:

| Check | Command | Result |
|---|---|---|
| Targeted P3/P4 suite | `test-source-fetch-contract.php` | 55 passed, 0 failed |
| Targeted P5 suite | `test-partial-enrichment-preservation.php` | 42 passed, 0 failed |
| Targeted P10 suite | `test-reconciliation-pagination.php` | 57 passed, 0 failed |
| Full importer component | `./scripts/run-tests.sh --php --only conexao-event-importer` | **42 suites: 39 passed, 3 failed; 4434 assertions passed, 40 failed** — all 3 failures are pre-existing (see §11) |
| Static script gate | `./scripts/run-tests.sh --scripts` | 17/18 (the three known stale catalogues) |
| Registry drift | `php scripts/generate-registry-docs.php --check` | OK, 11 plugins / 20 regions current, zero writes |
| Lint | `./scripts/lint.sh` | **OK** — syntax clean, no new PHPCS violations, PHPStan clean |
| Disposable DB identity | `tests/lib/isolated-db.php` | `ISOLATED_DB_PREFLIGHT=PASS` |

## 10. Failure proofs / negative tests

- Incomplete enumeration → **zero** candidate queries and zero mutations
  (asserted with a `query`-filter spy plus an unchanged status set).
- Checkpoint-resumed subset → the same.
- Failed later candidate page, malformed page, and an interruption during the
  scan → zero absence-based mutations in each case.
- Failed/incomplete engine runs → the pre-existing live event stays published.
- A failed detail fetch → stored description/venue/address/organiser/location
  unchanged; an absent element is not a clear; a present-but-blank description
  container *is* a clear.

## 11. Limitations

1. **No shared-database read-only access.** The Stage 1 reconciliation account
   (`conexao_ro`) had already been dropped, and Stage 2 forbids creating or
   altering accounts and forbids using the application account. Therefore no
   before/after `source_not_found` inventory and no before/after cohort
   fingerprint exists for this stage. This is why the verdict is
   `PASS WITH LIMITATION`, not `PASS`.
2. A pre-change suite run was started but overlapped the first edits and was
   discarded; the post-change full run is the authoritative test evidence.
3. Per-event drops in IVVCC/Motorsport (P11-adjacent) and Laois selector drift are
   recorded residual risks, not Stage 2 changes.
4. **Three pre-existing importer suites fail** and are not fixed here:
   `test-import-continuation.php` (deterministic 22 page-3 assertions from a
   clean state — 29 in the final run because a leftover `state: running`
   continuation record from a SIGKILLED run adds 7 lock/stall failures),
   `test-source-fetch-failure.php` (122/10 — Stage 1 records a first-page 429 as
   `rate_limited` while the G1 test asserts `failed`) and
   `test-town-sanitization.php` (82/1 — four legacy `conexao_town` terms in the
   disposable clone). Proof of non-involvement, isolation runs, the clean-state
   control and disposition:
   `docs/evidence/2026-11-15-stage-2/08-preexisting-failures.md`.
   Fixing them would require changing Stage 1 behaviour or weakening an existing
   test, both outside this stage's authority.

## 12. Evidence paths

- `docs/evidence/2026-11-15-stage-2/00-baseline-and-scope.md`
- `docs/evidence/2026-11-15-stage-2/01-p4-handler-inventory.md`
- `docs/evidence/2026-11-15-stage-2/02-p3-p4-fixes.md`
- `docs/evidence/2026-11-15-stage-2/03-p5-enrichment-state.md`
- `docs/evidence/2026-11-15-stage-2/04-p10-reconciliation-pagination.md`
- `docs/evidence/2026-11-15-stage-2/05-tests.txt`
- `docs/evidence/2026-11-15-stage-2/06-quality-gates.txt`
- `docs/evidence/2026-11-15-stage-2/07-preservation-limitations-and-status-inventory.md`

## 13. Rollback / recovery

Revert the Stage 2 diff: delete the four added files (the state class and three
suites), restore the ten modified importer files and the regenerated catalogue,
and drop the Stage 2 documentation. No database migration, option or schema change
was introduced, so no data rollback is required.

## 14. Final status

**PASS WITH LIMITATION.** Every code-level objective (P3, P4, P5, P10) is
implemented and verified in the disposable database; the shared-database
preservation/status comparison required for a full `PASS` could not be performed
safely and is not claimed.

---

## 15. Post-implementation verification (2026-11-15)

> Appended after the Stage 2 verdict. Everything above is unchanged. This
> section records a separate verification of four gaps in §§2, 5, 11 and 14.

**Baseline at verification time:** branch `event-import-improvements`, HEAD
`3f6141a251365b8f7bdbfa0d2dd0fec92e9fefdd` (Stage 2 committed; the `b5a3ee9`
HEAD recorded in §1 is no longer HEAD), clean worktree. The Stage 2 diff was
verified intact and unmodified.

Evidence: `docs/evidence/2026-11-15-stage-2-verification/` (files `00`–`04`,
plus the raw probe, captures and test logs).

### 15.1 The read-only transaction path — attempted and VERIFIED

The limitation in §11.1 and in
`docs/evidence/2026-11-15-stage-2/00-baseline-and-scope.md` §4 — "no verified
read-only method exists" — is **factually wrong and is retracted**. The task's
§3.7 engine-enforced read-only transaction was available all along and needs no
account or privilege change, so it never conflicted with the rule that forbids
creating accounts.

Probe, run before any data query (`…-verification/01b-readonly-probe.txt`):

```
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;
SELECT @@transaction_read_only;                      -- 1
UPDATE wordpress.wp_posts SET post_title = post_title WHERE 1 = 0;
-- ERROR 1792 (25006) at line 4: Cannot execute statement in a READ ONLY transaction.
```

`ERROR 1792` / SQLSTATE `25006` is the engine's read-only rejection, not an
access-denied error, and it fired on a predicate that matches no row. Engine
enforcement is therefore established, and it was re-confirmed after the captures.
Account: `root@localhost` (the MySQL administrative account from the local
compose configuration) — **not** the WordPress application account.

One short read-only transaction per dataset, tab-separated, `ORDER BY`
documented, SHA-256; the method was calibrated by reproducing the recorded
275-row `stage32_export_test` fingerprint exactly.

### 15.2 What the shared-database capture shows

| Cohort | Result | Status |
|---|---|---|
| 60 incident rows (47 created + 13 updated) | `dd49a34a…`, **60/60 rows byte-identical** to the authoritative baseline enumeration | **preserved** (row-level equality, stronger than a digest) |
| 60 incident postmeta | **1,333** rows (same as baseline) | count identical; baseline method undocumented so content digest is not comparable |
| 275 `stage32_export_test` | `a77b0943…` | **exact match** with Stage 0.5 and both Stage 1 captures |
| 6 externally imported | `b31be54a…` | **2 of 6 differ** — `post_modified` only (133818, 171003) |
| 14 probe-associated | `77b87490…` | **no baseline for comparison** (pattern-based; count 14 matches) |

Additional evidence obtained because the read-only path was finally available:

- **`source_not_found` inventory, new baseline: 106 events**
  (`f1d3eda8…`, full list in `…-verification/01a-source-not-found-inventory.tsv`;
  by source: meath 22, laois 21, kerry 15, mayo 14, westmeath 7, clare 6, cork 5,
  leitrim 5, cork-tomorrow 5, offaly 4, none 1, mayo-tomorrow 1). No status was
  changed and none was restored.
- **Row-level diff of every event post row** against the retained 2026-11-14
  inventory: **5 changed** (all `post_modified` only) and **74 new**, 0 deleted —
  and **none inside Stage 1's or Stage 2's window**.
- **Postmeta identity:** `73,759 − 1,628 = 72,131` and `72,527 − 396 = 72,131`,
  so no postmeta row was added to or removed from any pre-existing event.

### 15.3 The shared database changed after Stage 1 — externally, before Stage 2's code

An externally triggered **"Run All" import ran 14:01–14:10:57 UTC**: 56 new
events, 4 pre-existing rows refreshed. It is recorded by the importer's own log
(newest entry `2026-11-15 14:10:57`, run group
`source-ce1a8ed6-fd98-4fe3-b45e-cba7191a10f1`). It **completed before Stage 2's
first code edit** (earliest Stage 2 file mtime `14:15:13`) and long before the
Stage 2 commit (`15:21:17`), so it executed pre-Stage-2 code. It is the same
class of event Stage 0.5 documented at 07:42–07:45.

Consequences, stated precisely:

- The all-event count moved 3,705 → 3,761 and the all-event digest cannot equal
  the Stage 1 value. The delta is fully enumerated by the row-level diff in §15.2.
- `133818` and `171003` (two of the six externally imported cohort) were among
  the refreshed rows, so that cohort's fingerprint does not match its Stage 1
  state. The Stage 1 evidence contains no comparable *hash* for this cohort, so
  the comparison is against recorded row values.
- Whether the *trigger* was an operator action in wp-admin or something else
  cannot be determined from database evidence. What the evidence does bound is
  the time and the code version: the run wrote before any Stage 2 code existed.
  §11.1's statement that "Stage 2 made no shared-database writes of any kind"
  remains consistent with that bound but should henceforth be read as "no write
  in the Stage 2 window (14:15–15:21)"; the 14:01 write is not accounted for by
  this verification, and is flagged rather than attributed.
- The newest importer log entry is `14:10:57`, so **no importer run occurred
  against `wordpress` during Stage 2's window**. Since `mark_missing_events()` is
  the only writer of `_event_status = 'source_not_found'` and it runs only inside
  a source run, Stage 2 could not have marked any event in the shared database.
  At entry granularity, every importer timestamp on 2026-11-15 falls in
  **07:41–07:52** or **14:00–14:10**: there is no entry between 07:53 and 14:00,
  and none after 14:10 — so nothing ran inside Stage 1's window (09:34–13:50)
  either.

### 15.4 `test-source-fetch-failure.php` — classification CONFIRMED

§11.4 called it pre-existing on the weakest evidence (the diff does not contain
the rate-limit code; the failure "looks like" the Stage 1 model). A clean-state
control has now been run against the **actual pre-Stage-2 tree** (`git archive
b5a3ee9`, mounted over the plugin directory; disposable clone; same harness):

| Tree | Result |
|---|---|
| `b5a3ee9` (pre-Stage-2) | 122 passed, **10 failed** — the five `429` assertions for Eventbrite and the five for Heritage Week |
| `3f6141a` (HEAD) | 122 passed, **10 failed** — identical output |

The two logs are identical apart from the container ID. The failure is a test of
the **pre-Stage-1** contract (`429` ⇒ `failed` + `fatal_errors`); the code records
`rate_limited` with a cooldown — Stage 1's status model. Every non-429 class in
the suite still passes. §11.4's classification stands, now on strong evidence.

### 15.5 `test-import-continuation.php` — RECLASSIFIED as a Stage 1 open item

§11.4 lists it under "three pre-existing importer suites fail". That framing is
corrected: the suite is a **Stage 1 open item**, not a Stage 2-era pre-existing
failure.

- **Stage 1 origin:** the Stage 1 report records it as *stopped* — "produced no
  output for more than one minute; it is not claimed as passing" — and its
  failure table lists it as `stopped`. Stage 1 never produced a passing record.
- **Current Stage 2 symptom:** it **fails**, deterministically, at
  **262 passed / 22 failed** (`G18.1` page-3 expectation, `G18.3` cascade,
  `G18.10` per-county page 3, `G18.14` position/reason cell). It does **not**
  hang.
- **Not a Stage 2 symptom:** the same suite at the pre-Stage-2 tree `b5a3ee9`
  gives the same **262/22** with an identical failure set. That excludes P10's
  pagination and P5's write path as causes by direct measurement.
- **Stage 1's "hang" explained:** the suite is slow-to-*report*, not
  slow-to-fail — it returns in ~145 s and prints its results only at the end.
  Stage 1's runner stopped it at ">1 minute with no output", which is shorter
  than the suite's normal runtime. The Stage 1 observation was accurate; the
  inference "non-returning" was mistaken.
- **Stage 1's `PASS WITH LIMITATION` on continuation verification still
  applies.** Stage 2 does not close it. Required follow-up: Stage 3 or a
  dedicated continuation-verification task (decide between updating the `G18`
  expectations to the Stage 1 pacing model or adjusting the pacing policy).

Details: `docs/evidence/2026-11-15-stage-2-verification/03-continuation-reclassification.md`.

### 15.6 The "additive-only" claim — CONFIRMED, with one disclosed nuance

| File | Claim | Verified |
|---|---|---|
| `includes/class-event-normalizer.php` | additive (declares enrichment state) | **yes** — +19 lines, one conditional `$result['enrichment']`; non-declaring handlers produce byte-identical payloads; the new class drops unknown fields/states and treats an absent field as "write as before"; Eventbrite regression suites all pass (68/0, 87/0, 124/0, 91/0, 77/0, 65/0, 57/0) |
| `conexao-event-importer.php` | require only | **yes** — exactly 3 added lines, nothing else |
| `includes/sources/class-heritage-week-source.php` | only `enrich_event()` state declarations | **scope confirmed, "only" corrected** |

For the third file: all 13 hunks lie inside `enrich_event()` (old lines 862–981;
the method spans 864–1058 and the next function starts at 1059), and **no** added
or removed line touches the Stage 1 date filter (`is_definitely_past`), the
pagination anchor (`extract_page_number`, `normalize_pagination_url`, page/anchor/
checkpoint/offset/per_page logic), `fetch_detail_html()` or the image helpers.

However, the diff is not *literally* declarations only: organizer extraction was
restructured so that a present-but-empty "Further Information" `<p>` no longer
writes `organizer => ''`, leaving the key unset instead. That is a payload change
in one edge case only; it changes no stored value under the P5 write path (the
accompanying state is `UNAVAILABLE`, which `should_preserve()` honours), and it
affects no other handler. Recorded, not escalated.

### 15.7 Verdict reassessment

**Prior verdict (§14): `PASS WITH LIMITATION`, limitation = "no shared-database
read-only access, so no inventory or fingerprint could be captured".**

That stated limitation is **closed and retracted as factually incorrect**. The
path was verified and used; the captures exist; the headline preservation results
are strong:

- 60/60 incident rows byte-identical to their authoritative baseline;
- the 275-row `stage32_export_test` cohort fingerprint-identical;
- no event post row created or modified inside either stage's window;
- no postmeta row added to or removed from any pre-existing event;
- no importer ran against the shared database during Stage 2's window;
- `test-source-fetch-failure` confirmed pre-existing by clean-state control;
- `test-import-continuation` reclassified as a Stage 1 open item and shown
  identical at both trees;
- the additive claim confirmed, with one disclosed nuance.

**Verdict for this verification: `PASS WITH LIMITATION` — the Stage 2 code is not
implicated in any change, but `PASS` is withheld for two specific, tracked
reasons, and the limitation text is replaced:**

1. **The comparison baseline moved.** An external Run All wrote 56 new events and
   refreshed 4 pre-existing rows at 14:01–14:10, before any Stage 2 code existed.
   Two of the six externally imported cohort therefore no longer match their
   Stage 1 state, and the all-event digest cannot equal its Stage 1 value. Both
   deltas are fully enumerated and attributed, but they are deltas, and the
   `source_not_found` population (106 now vs 18 on 2026-10-03) cannot be
   reconciled run-by-run because the importer retains only its last 100 history
   rows and 2,000 log entries.
2. **The trigger of that import is unattributed.** The evidence bounds its time
   and the code version (pre-Stage-2) but cannot say whether an operator or a
   session started it. This verification does not attribute it to Stage 2 and
   does not exclude it either; §11.1 should be read as "no shared-database write
   in the Stage 2 window (14:15–15:21)".

No regression attributable to Stage 2's implementation was found. If the user
accepts the external-import attribution for both items above, every remaining
§10 condition is met and the Stage 2 verdict may be upgraded to `PASS`; that is a
user decision, so it is not taken here. Nothing found in this verification
requires investigating a potential Stage 2 defect.

### 15.8 Safety and state at the end of verification

| Check | Result |
|---|---|
| Database written to | none — every shared-database statement ran inside a verified read-only transaction |
| Accounts/grants created, dropped or altered | none |
| Event statuses changed | none |
| Application code / tests modified | none — `git status` shows only new documentation files and this appended section |
| Stage 0 / 0.5 / 1 / reconciliation / Stage 2 evidence modified | none |
| Credentials in the new evidence | none (account name and server version only) |
| Commit / push / deploy | none |
| Pre-check vs post-check shared-DB state | identical: 3,761 events, 73,759 postmeta, 275 `stage32_export_test` (`a77b0943…`), 106 `source_not_found` (`f1d3eda8…`), all-event GMT digest `e0454927…`, probe still `ERROR 1792` |
| Test runs against the shared database | none — the clean-state controls used the disposable clone (`ISOLATED_DB_PREFLIGHT=PASS`) |

**Verification task status: COMPLETE WITH LIMITATIONS** — all four items were
addressed with new evidence; the verdict remains limited by the two items in
§15.7, which are disclosed rather than resolved.

