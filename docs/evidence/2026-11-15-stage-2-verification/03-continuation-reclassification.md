# Item 3 — reclassification of `test-import-continuation.php`

**Reclassified: this is a STAGE 1 OPEN ITEM**, not a "Stage 2 pre-existing
failure". The Stage 2 report's framing is corrected here. The verdict is not
changed by this reclassification alone.

## 1. Stage 1 origin (citation)

`docs/reports/2026-11-15-stage-1-event-importer-run-coordination.md`:

> `test-import-continuation.php` was stopped after an isolated preflight when it
> produced no output for more than one minute; it is not claimed as passing.
> (§ "Scope not completed")

and, in the gates table:

> | `test-import-continuation.php` | stopped | Isolated preflight passed; no output for >1 minute |

and:

> The continuation diagnostic was non-returning, not silently classified as a
> pass. (§12)

`docs/evidence/2026-11-15-stage-1/01-targeted-tests.txt` states the same: the
preflight passed, it "produced no output for more than one minute and was
stopped. It was not reported as passing."

So Stage 1 left the suite **unverified**, and its own report records that
explicitly. That is an open item carried out of Stage 1.

## 2. Stage 2 framing (the error being corrected)

`docs/evidence/2026-11-15-stage-2/08-preexisting-failures.md` lists the suite in
a table headed "Pre-existing test failures found by the Stage 2 full run" and
cites its last green as 2026-11-14, "i.e. **before** Stage 1". Presenting it as
a Stage 2-era "pre-existing" failure obscures the real provenance: the suite was
never brought to a verified state by Stage 1, so there was no post-Stage-1 green
baseline for Stage 2 to fail against.

Correct classification:

| Field | Value |
|---|---|
| Origin | Stage 1 open item — unresolved continuation verification |
| Stage 2 status | **failing**, deterministically: 262 passed / 22 failed |
| Stage 1's `PASS WITH LIMITATION` on continuation verification | **still applies**; Stage 2 does not close it |
| Stage 2's verdict effect | none — Stage 2's P3/P4/P5/P10 work is independent of continuation verification |
| Required follow-up | investigate in Stage 3 (or a dedicated continuation-verification task) |

## 3. Current symptom — FAIL, not HANG

Measured in this verification (disposable clone, `ISOLATED_DB_PREFLIGHT=PASS`):

| Run | Tree | Wall clock | Result |
|---|---|---|---|
| `03a-continuation-pre-stage2.log` | `b5a3ee9` (pre-Stage-2) | 146 s | **262 passed, 22 failed** |
| `03b-continuation-head.log` | `3f6141a` (HEAD, Stage 2) | 144 s | **262 passed, 22 failed** |

The suite **completes and reports**; it does not hang. `grep 'FAIL:'` on the two
logs is **identical** (22 lines), and the two logs differ only in the container
ID.

The failure signature is a deterministic page-count expectation:

```
FAIL: G18.1: the time budget stopped the walk mid-listing at page 3 (got 2)
FAIL: G18.1: the continuation records the checkpoint page it resumes from
FAIL: G18.3: … (13 assertions cascade from the page-2 checkpoint)
FAIL: G18.10: cork / wicklow / wexford / waterford / tipperary stopped at its own page 3
FAIL: G18.14: the cell states the position and the reason
             (got: page 2 of 20 (time_budget))
```

## 4. Did the behaviour change between Stage 1 and Stage 2?

**No.** The same suite on the same data at the pre-Stage-2 SHA produces the same
262/22 with the same 22 assertions. The 22 failures therefore predate Stage 2's
code entirely. That excludes, by direct measurement rather than by argument:

| Hypothesis from the task | Verdict |
|---|---|
| Symptom of P10's reconciliation pagination | **excluded** — identical at `b5a3ee9` (no P10) and at HEAD |
| Symptom of P5's write-path changes | **excluded** — identical at `b5a3ee9` (no P5) and at HEAD |
| Unrelated environment drift between Stage 1 and Stage 2 | **excluded for the failure set** — both runs use the same clone, same harness, same data, same result |
| **Stage 1's "hang" was actually a slow failure that eventually errored** | **CONFIRMED, with a refinement** |

### The refinement

The suite is not slow-to-fail; it is **slow-to-report**. It prints its results
only at the end, after ~145 s of quiet work. Stage 1's runner stopped it at
"more than one minute with no output", which is shorter than the suite's normal
runtime. The Stage 1 observation was therefore accurate ("no output for >1
minute; not claimed as passing") but the *inference* "non-returning/hanging" was
mistaken: with a longer window the suite returns 262/22.

That is also why Stage 2 saw it in the full run: Stage 2's runner waited long
enough (162 s) to capture the exit.

## 5. Why the 22 assertions fail at all (recorded, not fixed)

They assert that the fake pacing clock stops the Eventbrite walk at **page 3**;
the current pacing policy stops it at **page 2**, so 22 assertions that cascade
from the page-3 checkpoint fail. That is a Stage 1 pacing-policy change relative
to the assertions' expectations (last green before Stage 1: 272/0 on 2026-11-14).
It is **not** investigated or fixed here — test and application code are out of
scope for this verification, and the required follow-up is the decision between
updating the G18 expectations to the Stage 1 pacing model or adjusting the
pacing policy.

## 6. Residual state effect (for whoever runs it next)

The suite mutates its own continuation/checkpoint stores in the clone and
restores them on shutdown. A **killed** run leaves a `state: running` record for
`g18_probe_cork`, which adds 7 lock/stall failures (255/29 instead of 262/22 —
recorded by Stage 2). Both runs in this verification completed normally, so both
report the clean 262/22, and **no store was manually cleared** by this task.

_Last verified: 2026-11-15 by the Stage 2 post-implementation verification_
