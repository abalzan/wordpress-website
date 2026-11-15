# Task C — the connected-failure hypothesis, tested against retained evidence

**Evidence base:** `01-attribution-evidence.md` and its five TSVs, plus the `02-`
inventory, plus code inspection of the branch at HEAD `77f09a3` — which is the
code running on this shared stack (`00-scope-and-baseline.md` §4b).
**Writes:** none. **Provider requests:** none. **Reproduction attempts:** none.

---

## 0. The hypothesis, restated as five testable links

| # | Link |
|---|---|
| 1 | A shared trigger or lifecycle defect left some continuations in `running` / `run_at = 0` |
| 2 | The shutdown fail-safe then executed nine of them in a burst at 14:01–14:10 |
| 3 | One or more of those executions stopped before a complete enumeration, despite a recorded `success` |
| 4 | Reconciliation ran against the incomplete returned set and marked events `source_not_found` |
| 5 | P12 changes how *future* incomplete executions are classified, cannot correct history, and does not prove deployment |

Each link is tested on its own evidence. A link the retained data cannot reach is
reported as INSUFFICIENT EVIDENCE — never filled with timing correlation.

---

## 1. Q1 — Can the 80 events be tied to the nine runs?

**Answer: NO. There is no event-level attribution, and the run-level evidence
points away from attribution for 8 of the 9 affected sources.**

### 1.1 There is no per-event audit trail for `_event_status`

Inspected in the code, not assumed:

* `mark_missing_events()` (`class-event-importer.php:2629-2714`) is the only
  writer that sets `_event_status = source_not_found` in bulk. It `return`s a
  count and **writes no log entry** — not per event, not one summary line. Its
  only caller (line 1025) discards the return value.
* `Conexao_Event_Status::set_status()` writes the meta value; there is no audit
  row, revision, or companion meta key recording who changed it or when.
* No `*_status_updated_at`-style meta exists. Verified by querying every
  `meta_key LIKE '%status%'` for three cohort posts — the only match is
  `_event_status` itself.
* A history row carries **counters only** — no event IDs (`02-` §8).

Therefore **the moment a given event's `_event_status` changed cannot be
established from any retained record.**

`wp_posts.post_modified` is explicitly **not** used as a proxy: it records the
post row, not a postmeta write, and the two diverge routinely — post `13317`
carries `post_modified = 2026-11-09`, six days before the incident window, while
its status is the very value the incident concerns.

### 1.2 The run-level evidence contradicts the attribution for 8 of 9 sources

This is the decisive finding of the task, and it does not depend on interpreting
logs:

| Affected source | Registry `last_import` | Inside 14:01–14:10 on 2026-11-15? | History row in window? | Log entries in window? |
|---|---|---|---|---|
| `eventbrite_meath` | 2026-11-14 14:23:45 | **NO — previous day** | no | no |
| `eventbrite_laois` | 2026-11-14 14:17:00 | **NO — previous day** | no | no |
| `eventbrite_mayo` | 2026-11-14 14:23:24 | **NO — previous day** | no | no |
| `eventbrite_kerry` | 2026-11-14 14:11:46 | **NO — previous day** | no | no |
| `eventbrite_westmeath` | 2026-11-14 14:31:21 | **NO — previous day** | no | no |
| `eventbrite_clare` | 2026-11-14 14:02:10 | **NO — previous day** | no | no |
| `eventbrite_cork` | 2026-11-15 14:01:21 | **YES** | yes (#1) | yes (27) |
| `eventbrite_leitrim` | 2026-11-14 14:17:10 | **NO — previous day** | no | no |
| `eventbrite_offaly` | 2026-11-14 14:24:51 | **NO — previous day** | no | no |

The nine sources that ran in the window were `cork`, `louth`, `monaghan`,
`roscommon`, `waterford`, `carlow_events_today`, `dublin_events_this_week`,
`longford_events_this_week` and `wicklow_events_today`. **Eight of those hold
none of the 80 cohort events.** The overlap between "nine window runs" and "nine
affected sources" is exactly **one** source — `cork`, 4 events.

Two sets of nine were conflated. It is a numeric coincidence, not a causal chain.

### 1.3 Was any event already suppressed before 14:01?

The prompt allows a reliable independent record of prior suppression to exclude
an event from nine-run attribution. **No such record exists** (§1.1), so no event
can be excluded on that ground — and, symmetrically, **absence of such a record
proves nothing either way**. What can be said is weaker and sufficient: for the
76 events in the eight other sources the nine window runs cannot be the cause,
because those runs never touched those sources.

> **Q1 verdict: NO EVENT-LEVEL ATTRIBUTION EXISTS. 76 of 80 cannot be attributed
> to the nine runs on run-level evidence; the remaining 4 (`cork`) belong to a
> source that did run in the window, but that run was gate-blocked (§3).**

---

## 2. Q2 — Were the nine runs genuinely incomplete?

**Answer: INCOMPLETENESS INDICATED for all nine, from direct retained entries,
using reason semantics established by code.**

The reason codes are not free text; each is produced at a specific point in
`class-eventbrite-source.php` and each leaves `fetch_complete === false`:

| Source | Entry | Reason | Meaning established by code |
|---|---|---|---|
| `cork` | 14:01:16 warning | `incomplete_enumeration` | 45 s time budget expired after page **38/49** |
| `louth` | 14:02:07 warning | `incomplete_enumeration` | pacing wait did not fit the window after page **24/49** |
| `monaghan` | 14:02:49 warning | `rate_limited` | rate limited on page **27/49**, HTTP 429 |
| `roscommon` | 14:04:51 history `rate_limited` | — | fetch produced **0** events, 429 |
| `waterford` | 14:06:52 history `rate_limited` | — | fetch produced **0** events, 429 |
| `carlow_events_today` | 14:07:14 warning | `checkpoint_resume` | resumed from a checkpoint; prefix not re-requested |
| `dublin_events_this_week` | 14:07:17 warning | `upstream_truncated` | listing declares 1080 objects, pagination exposes 49 pages |
| `longford_events_this_week` | 14:10:31 history `warning` | — | partial-enumeration warning present |
| `wicklow_events_today` | 14:10:57 warning | `checkpoint_resume` | resumed from a checkpoint; prefix not re-requested |

`last_page` / `page_count` are used only where code establishes their meaning:
`page_count` is what the listing *declares*, `last_page` the last confirmed page,
and `MAX_PAGES` truncation is detected at `class-eventbrite-source.php:863`. No
"reached the end of the enumeration" claim is made where the checkpoint does not
support one.

> **Q2 verdict: INCOMPLETENESS INDICATED — nine of nine.** Six stopped mid-walk on
> a budget or a 429; two resumed from a checkpoint (complete-but-subset); one
> resumed and fetched nothing.

---

## 3. Q3 — Did the nine runs pass P4's gates and execute reconciliation?

**Answer: NO. All nine were refused before `mark_missing_events()` could write
anything, and the retained log says so in words.**

The call path, read from `class-event-importer.php:998-1060`:

```php
$fetch_complete      = $this->is_handler_fetch_complete( $handler );       // P4
$reconciliation_safe = $this->is_handler_reconciliation_safe( $handler );  // G10
if ( $fetch_complete && $reconciliation_safe ) {
    $this->mark_missing_events( $source_id, $raw_events, $handler );
} else {
    $this->mark_fetch_incomplete_in_result( $result, $source_id );
    // … and a warning is logged
}
```

`mark_missing_events()` then **re-evaluates both gates itself** (lines 2634-2645)
so that no caller can reach the candidate queries with an incomplete set. A
recorded `success` proves none of this: the status is written by a different code
path, and in pre-P12 code every non-`failed` outcome mapped to `success`.

The retained log closes the question independently. Every one of the nine runs
logged *"Events absent from this walk were left untouched (not marked
source_not_found)"* or *"Absence-based reconciliation was skipped because the
events already imported from the confirmed pages are not in this run's result
set."* Those exact strings are emitted only from the `else` branch above and from
the resumed-subset branch at line 1050.

> **Q3 verdict: reconciliation was NOT executed by any of the nine runs. The
> fetch results were incomplete, the gates refused, and the refusal is logged.**

This consequence runs *against* the hypothesis rather than for it. **On this
codebase an incomplete enumeration cannot produce `source_not_found`.** The gate
exists to prevent exactly that and demonstrably worked during the incident
window. Whatever produced the 80 suppressions, it was a run whose enumeration
passed the completeness and safety gates — i.e. a run in which those events were
genuinely absent from a complete walk.

A caveat that must be stated: "complete" here means complete **as the handler
measured it** (`is_fetch_complete()`, bounded by `MAX_PAGES` = 49 and the region
filter). A walk can be reported complete and still not contain an event that a
provider's own listing no longer surfaces. That is a different failure mode from
"incomplete enumeration", and it is not the one this hypothesis proposes.

---

## 4. Q4 — Are the stranded continuations connected to the nine?

**Answer: SAME MECHANISM, DIFFERENT INSTANCES — and the connection does not run
in the direction the hypothesis needs.**

### 4.1 The state of the store at capture

| Metric | Value |
|---|---|
| Continuation records | 22 |
| `state = running` with `run_at = 0` (stranded) | **16** |
| `state = scheduled` with `run_at > 0` | 6 |
| Stranded records belonging to one of the nine affected sources | **1** (`cork`) |
| Stranded records for the eight other affected sources | **0** — they have no continuation record at all |

Stage 3 reported **15** such records. It is **16** now: `eventbrite_cork` joined
the stranded set *after* Stage 3's capture (§4.2).

### 4.2 A directly observed instance of the stranding mechanism

`eventbrite_cork`, 2026-11-15 18:35:00 — **the same mechanism, observed live,
41 minutes before the capture**:

* one and only one log entry at that second: `Continuation starting for
  eventbrite_cork: resuming the enumeration from page 38 of 49. No browser is
  involved.`;
* **no** `Continuation scheduled`, **no** completion entry, **no** error entry,
  **no** page-fetch entries;
* the record is now `state = running`, `run_at = 0`, `last_run_at = 18:35:00`,
  `attempts = 7`, `stalled = 1`;
* its own WP-Cron event (`2026-11-15 15:01:21`) is **still present and
  unconsumed** at 19:22 — so the 18:35 execution cannot have been WordPress cron
  (`01-` §3.1). That entry's timestamp is exactly `scheduled_at` (14:01:21) + the
  cooldown, i.e. the value armed at 14:01:21; a re-arm after a fire would have
  used a later schedule, so the entry is the original and was never fired.

Read against the code, the sequence is exact. `run_due()`
(`class-conexao-import-continuation.php:968-1055`):

1. **skips** any record whose `state` is not `scheduled` — so a stranded record
   is never picked up again;
2. sets `state = running`, `run_at = 0`, `last_run_at = now()` and **saves**;
3. logs `Continuation starting …`;
4. `call_user_func( self::get_runner(), $id )` — the actual run;
5. `finally { release_lock() }`, then a safety-net `reconcile()` if the record is
   still `running`.

The 18:35 record has step 3's entry and none of step 4's or 5's output: a process
that died after the save and before the runner finished — a fatal error, an
execution-time limit, or an aborted request. Because step 2 has already persisted
`running` / `run_at = 0` and step 1 refuses to retry anything not `scheduled`,
the record is **permanently stranded** with no automatic recovery path.

### 4.3 Are the 16 connected to the nine 14:01–14:10 executions?

| Question | Answer, with basis |
|---|---|
| Same code path? | **Yes** — every continuation execution goes through `run_due()`; there is no other path |
| Same trigger class? | **Yes** — non-WordPress-cron (`01-` §3.1); `run_due()`'s only other caller is the `shutdown` action |
| Same failure mode? | **Indicated, not proved.** Only one of the nine (`cork`) still has a record, and it is stranded — but from the **18:35** attempt. At 14:01 cork's run *completed* (`Continuation scheduled … resumable at page 38 of 49`) and was re-armed for 15:01, so the 14:01 instance did **not** strand |
| Can a stranded continuation produce *new* suppressed events? | **No, on this code.** (a) `run_due()` never re-executes a stranded record, so it cannot reach reconciliation; (b) even if it ran, an incomplete enumeration cannot mark (§3) |

Honest summary: the 16 and the nine share a mechanism and a trigger class, and
the mechanism is *observed* stranding records. But they are not the same records,
the eight other affected sources have no continuation at all, and stranding
cannot itself manufacture a suppression.

The *real* current risk the 16 represent is narrower: 16 sources whose enumeration
can never finish, because the record that would resume them is unreachable by
`run_due()` until something resets it. That is a **coverage** problem (events
never get imported), not a **false-suppression** problem.

---

## 5. Q5 — The P12 statement

**Answer: correct as a statement; and P12 is live here, which does not help
retroactively.**

| Claim | Verdict |
|---|---|
| "P12 changes how future incomplete executions are classified" | **Supported.** The 18:39–18:41 history rows use `partial` / `rate_limited` where the 14:01–14:10 rows use the pre-P12 `warning` (`01c` against the full history store) |
| "It cannot retroactively correct historical run outcomes" | **Supported by construction.** The status is stored in the history row at run time; nothing rewrites it |
| "It does not prove the pushed code is deployed" | **Correct as a general rule**, and here the deployment question was answered separately, by file hash rather than inference: the local shared stack runs `77f09a3`'s code verbatim (`00-` §4b). For WordPress.com production: **DEPLOYMENT VERSION UNVERIFIED** |

---

## 6. Verdict

> ### **REFUTED — for the hypothesis as stated.**
>
> The claim under test is that the stranded-continuation / shutdown-burst /
> incomplete-run chain *produced the 80 suppressed events*. Reliable evidence
> contradicts its terminal links and its event attribution:
>
> * 76 of the 80 events belong to sources that did not run in the window at all
>   (§1.2), so the nine runs cannot be their cause;
> * the nine runs were incomplete (§2) but their reconciliation was **refused** by
>   two independent gates, and the refusal is logged for every one of them (§3) —
>   they marked nothing;
> * therefore the step "incomplete set → `source_not_found`" is **not a behaviour
>   this codebase has**, and cannot be the explanation.

Per-link results:

| # | Link | Result |
|---|---|---|
| 1 | Lifecycle defect strands continuations in `running`/`run_at=0` | **CONFIRMED** — 16 stranded, and a fresh instance observed live at 18:35:00 (§4.2) |
| 2 | The shutdown fail-safe executed nine of them at 14:01–14:10 | **CONSISTENT WITH SHUTDOWN-TRIGGERED EXECUTION; SPECIFIC TRIGGER UNVERIFIED** — non-WordPress-cron established, no direct shutdown indicator (`01-` §4.2) |
| 3 | Executions stopped before complete enumeration despite `success` | **CONFIRMED** for the nine (§2) |
| 4 | Reconciliation ran on the incomplete set and marked events | **REFUTED** for the nine (§3) — and the 80 events are not in those sources anyway (§1.2) |
| 5 | P12 changes future classification, cannot correct history | **CONFIRMED as a statement** (§5) |

`Partially confirmed` would be the wrong label. Three links do hold, but they
hold for a chain that terminates before the effect in question, and the effect is
attributed to sources the chain never touched.

---

## 7. Residual uncertainty — what this analysis cannot settle

1. **What actually suppressed the 80 events is not established.** The runs that
   did it fall outside the retained log (which begins 2026-11-14 14:43) and the
   retained history (which begins 2026-11-14 19:29), while the eight affected
   sources last ran at 2026-11-14 14:02–14:31. No record of those runs survives.
2. **Whether the eight sources' 2026-11-14 runs passed the gates is unknown.** By
   the logic of §3 they must have passed the completeness gate in order to mark
   anything — but that is an inference from the code, not a retained observation.
   If the gate behaved differently before Stage 2's rework the inference weakens.
   The 14:10:57 entry proves the gate was live on 2026-11-15 at 14:10; it does not
   prove the same for 2026-11-14 at 14:2x.
3. **Whether `cork`'s 4 events were suppressed by a complete walk is unknown.**
   Cork's only retained run was gate-blocked, so its suppressions predate
   2026-11-15 14:01 and the run responsible is evicted.
4. **Whether the event-level absence is genuine is unknown for all 80.** No
   upstream verification was permitted and no enumeration manifest exists
   (`02-` §8).
5. **The historical cron option was never observed.** No contemporaneous
   snapshot, no database backup, no cron audit (`01-` §3). Link 2 therefore
   carries a permanent — not a temporary — evidential gap.

None of these gaps is filled by circumstantial timing anywhere in this document.
