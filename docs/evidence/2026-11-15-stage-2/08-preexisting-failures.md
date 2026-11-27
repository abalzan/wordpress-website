# Pre-existing test failures found by the Stage 2 full run

Three importer suites fail. **None is caused by Stage 2**, and none was fixed,
because fixing them means either changing Stage 1 behaviour or weakening an
existing test — both forbidden in this stage.

| Suite | Result | Cause | Last recorded green |
|---|---|---|---|
| `test-import-continuation.php` | 262 passed, 22 failed **from a clean continuation queue** (255/29 when a leftover `running` record is present — see §3) | deterministic page-count shift: the G18 cases expect the fake-clock walk to stop at page 3 and it stops at page 2 | 272 passed / 0 failed, 2026-11-14 (`docs/evidence/2026-11-14-import-history-consolidation/01-importer-suite-aggregate.log`) — i.e. **before** Stage 1 |
| `test-source-fetch-failure.php` | 122 passed, 10 failed | Stage 1's rate-limit status model: a first-page 429 is now recorded `rate_limited` with a source cooldown instead of `failed`, and the G1 test asserts the literal `failed` + `fatal_errors` | 132 passed / 0 failed, 2026-11-14 (`docs/evidence/2026-11-14-event-import-dashboard/importer-in-process.txt`) — i.e. **before** Stage 1 |
| `test-town-sanitization.php` | 82 passed, 1 failed | Disposable-clone data: four legacy `conexao_town` terms contain a county/Eircode artefact (`Co Clare Ireland`, `Co galway`, `Co. na Gaillimhe`, `Co.Louth`) | Fails identically in 2026-09-26 stage-7 / stage-p and 2026-09-28 runs (`82 passed, 1 failed`) — a long-standing environment-data failure |

## 1. Why Stage 2 is not the cause

### `test-source-fetch-failure.php` (the 429 cases)

Stage 2's diff contains no reference to the rate-limit status at all:

```
$ git diff HEAD -- wp-content/plugins/conexao-event-importer/includes/class-event-importer.php \
    | grep -n 'rate_limited\|mark_rate_limited\|is_rate_limited'
NONE
```

and the code that produces `rate_limited` is Stage 1's:

```
$ git log --oneline -S 'mark_rate_limited' -- …/includes/class-import-result.php
44c885b Implement Stage 1 of Event Importer: Coordination, Rate Limiting, and Heritage Eligibility
$ git log --oneline -S 'mark_rate_limited(' -- …/includes/class-event-importer.php
44c885b Implement Stage 1 …
```

Running the suite **in isolation** reproduces exactly the same 122/10, and only
the two 429 cases fail — every other failure class (500, 502, 503, timeout,
connection failure) still reports `failed`. The suite therefore reflects a
Stage 1 status-model change the G1 test predates.

### `test-import-continuation.php` (the G18.1 page count)

The failing assertion is produced entirely inside the Eventbrite walk and the
pacing policy:

```
FAIL: G18.1: the time budget stopped the walk mid-listing at page 3 (got 2)
FAIL: G18.1: the continuation records the checkpoint page it resumes from
FAIL: G18.3: … (19 further assertions cascade from the page-2 checkpoint)
```

Stage 2 modified **none** of the files on that path:
`class-eventbrite-source.php`, `class-eventbrite-client.php`,
`class-eventbrite-pacing.php`, `class-class-conexao-import-continuation.php`,
`class-source-enumeration-checkpoint.php`. The only Stage 2 code that executes
during a walk is `upsert_event()`'s added description comparison, which does not
touch pacing, the clock, the budget or the checkpoint. The suite also could not be
run to completion during Stage 1 (it was stopped after its preflight), so there is
no post-Stage-1 green record to regress from.

### `test-town-sanitization.php`

The assertion inspects the clone's whole `conexao_town` taxonomy:

```sql
SELECT t.term_id, t.name
FROM wp_terms t
INNER JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id
WHERE tt.taxonomy = 'conexao_town'
-- matches: 2311 'Co Clare Ireland', 8132 'Co galway', 8139 'Co. na Gaillimhe', 8197 'Co.Louth'
```

Those term IDs come from the restored shared data. Stage 2 created no town term
whatsoever (its fixtures carry no `town`), and the identical `82 passed, 1 failed`
result is recorded in September 2026 evidence.

## 2. Isolation runs (same database, suite alone)

Running each failing suite on its own reproduces the same result, so neither is a
cross-suite interference artefact:

```
$ … wordpress php …/tests/test-import-continuation.php
G18 continuation: 262 passed, 22 failed

$ … wordpress php …/tests/test-source-fetch-failure.php
122 passed, 10 failed
  FAIL: Eventbrite first page 429: status is failed (got "rate_limited")
  FAIL: Eventbrite first page 429: fatal_errors > 0 (failure evidence recorded)
  FAIL: Eventbrite first page 429: the persisted history row is status=failed
  FAIL: Eventbrite first page 429: the persisted history row carries fatal_errors
  FAIL: Eventbrite first page 429: HTTP 429 is surfaced as failure evidence
  (and the same five for the Heritage Week 429 case)
```

The continuation failures are not confined to G18.1: G18.10's per-county cases
("cork/wicklow/wexford/waterford/tipperary stopped at its own page 3") and
G18.14's position/reason cells fail identically. Every one of them asserts the
same page-3 stop produced by the fake pacing clock, which is the signature of a
clock-read count change on the Stage 1 pacing path, not of data or of Stage 2
code.

## 2a. Clean-state control for the continuation suite

The suite mutates `conexao_event_import_continuations` and
`conexao_event_enumeration_checkpoints` and restores them in a shutdown handler.
A **killed** run (SIGKILL, or the runner's per-suite timeout) therefore leaves
those test stores dirty — a `state: running` record for `g18_probe_cork`, which
makes the continuation engine treat the source as locked.

Observed state in the disposable clone before the control run:

```
conexao_event_import_continuations =
a:1:{s:14:"g18_probe_cork";a:14:{... "state";s:7:"running";"attempts";i:1;"last_page";i:4; ...}}
```

Both test stores were cleared **in the disposable clone only** (no shared-database
access; no WordPress bootstrap against the shared database) and the suite re-ran
alone:

```
$ … wordpress php …/tests/test-import-continuation.php     (clean queue)
G18 continuation: 262 passed, 22 failed
  FAIL: G18.1: the time budget stopped the walk mid-listing at page 3 (got 2)
  FAIL: G18.10: … stopped at its own page 3 (× 5 counties)
  FAIL: G18.14: the cell states the position and the reason
```

Conclusion: **22 failures are deterministic and independent of leftover state**
(the page-3 expectation), while the extra 7 seen in the third full run
(255/29 instead of 262/22) came from the leftover `running` record. Both are
pre-existing and neither is touched by Stage 2.

## 3. Disposition

- **Not fixed.** Fixing #1 or #2 would change Stage 1 behaviour that the task
  explicitly protects, or weaken an existing G1/G18 assertion, which the task
  explicitly forbids.
- **Escalated.** Both need a separate decision: either the older assertions are
  updated to the Stage 1 status/pacing model, or Stage 1 is adjusted. That is a
  Stage 1 follow-up, not Stage 2 work.
- **#3 is environmental.** It is clone data, not code; clearing those four terms
  from the clone would be a test-environment change, and the shared database must
  not be touched to do it.

All Stage 2-related suites pass in the same run (see `05-tests.txt`).
