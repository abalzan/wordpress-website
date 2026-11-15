# Task E — restoration plan (conditional; NOT executed)

> **No restoration is currently recommended as safe on the evidence available in
> Stage 3.5.** This document is a decision plan, not an instruction. Nothing in it
> is authorised by this task. Every event it lists still has
> `upstream_presence_verified = no`, and no event may be treated as live,
> restorable or safe to change until a separately authorised upstream check has
> produced positive evidence.
>
> **Authorisation status: NONE.** This Stage 3.5 task authorises no event or
> source mutation, no continuation mutation, no provider request and no deploy.

Per-event detail lives in `02-suppressed-event-inventory.tsv` (80 rows); this
document refers to those rows and does not restate them.

---

## 1. The single rule this plan obeys

A local future `_event_end_date`, an `active` registry row, a historical
`success`, a `post_modified`, or the absence of a log entry **is not evidence
that an event is upstream**. Under the code at HEAD an incomplete enumeration
cannot even produce `source_not_found` (`03-connected-failure-analysis.md` §3), so
the tempting reading — "the source was mid-walk, therefore the event is fine" — is
not merely unproven, it is the wrong inference.

Every gate below therefore terminates in a **fresh, authorised, outbound provider
fetch**.

## 2. Tier breakdown

| Tier | Events | Sources | What the tier means |
|---|---:|---|---|
| **Priority 1 — high-priority candidate; upstream verification required** | **76** | meath 22, laois 17, mayo 14, kerry 7, westmeath 5, clare 5, leitrim 4, offaly 2 | Check these first |
| **Priority 2 — conditional candidate; clean enumeration and stable-ID confirmation required** | **4** | cork 4 | Decision depends on a per-event check the listing cannot supply |
| **Priority 3 — possible legitimate suppression / low priority** | **0** | — | None assigned; see §4 |
| **Indeterminate** | **0** | — | None assigned; see §5 |
| Excluded (baseline drift) | 0 | — | No cohort member drifted |

---

## 3. Priority 1 — 76 events, 8 sources

### 3.1 Why these are checked first

* their `_event_end_date` is current or future, so a wrong suppression has a
  **visible** cost — a real event missing from the public site;
* the source is `active` in the registry, so an upstream check is meaningful;
* **no** retained evidence suggests the event is gone: for these eight sources the
  runs that suppressed them predate the retained log, so nothing in the local
  record argues for legitimate absence;
* the cohort is on a clock — its earliest future end dates are `2026-11-17`, and
  three members (`136466`, `136793`, `136876`) expire **today**. Anything not
  verified this week stops being a current/future candidate at all.

Priority is a statement about **verification order**, never about liveness.

### 3.2 Evidence gate — all of it, before any write

1. **Authoritative upstream presence.** A fresh outbound request to the provider
   that returned the event returns it, and the returned record identifies the
   same event.
2. **Stable source-ID match.** The provider's returned identifier equals the
   stored `_event_source_id` for that cohort row. A mismatch means a re-issued or
   stale identity, and the row is **not** restorable by status alone (§7).
3. **Date sanity.** The provider's returned date, read as a local calendar date, is
   still current or future; otherwise the event is legitimately past and the item
   closes with **no change**.
4. **Complete enumeration** — required only if the verification walks a listing:
   the walk must report `is_fetch_complete() === true` with a
   `reconciliation_safe` checkpoint, and must retain its enumeration for audit.

### 3.3 Verification mechanism — and the mechanism that does **not** satisfy it

> **Required: a fresh provider fetch** — an authorised outbound request to the
> source that returned the original event, addressed to the event itself (its
> `_event_url` / `_event_source_id`), using whatever single-event lookup the
> provider offers.

> **An internal `run_source()` execution does NOT satisfy this gate.** It exercises
> the same code path that produced the suppression: the same listing, the same
> `MAX_PAGES` ceiling, the same region filter, the same rate-limit budget. If the
> importer cannot see the event, it cannot distinguish "the source no longer lists
> it" from "the importer still cannot reach it" — which is exactly the ambiguity
> being resolved. Re-running the importer is a fine *repair attempt*; it is not
> *verification*.

A listing walk is acceptable only where it is provably complete for that source
(`is_fetch_complete()` true, no `upstream_truncated`, no `MAX_PAGES` stop) **and**
the event falls inside the walked range. For the eight Priority 1 sources that
condition may well hold; it cannot be assumed, so the single-event lookup is the
default and the walk is the fallback.

### 3.4 Operation a later, separately authorised task would consider

Inspect first, then act — the values come from code, not assumption:

* `_event_status` has a **closed vocabulary**: `published`, `source_not_found`,
  `expired`, `rejected`
  (`wp-content/plugins/conexao-event-runtime/includes/class-event-status.php:17-20`).
* The public visibility gate is *"`published` OR legacy no-status row"*
  (`class-event-query.php:295`).
* Therefore the only operation that restores visibility is setting
  **`_event_status = published`** on the specific post ID.
* **`post_status` is not touched.** All 80 cohort posts are already `publish`;
  suppression is enforced by the meta value, and no inventory row shows an
  exception that would justify a `post_status` write.

The operation is therefore: *for each allow-listed post ID whose gate passed, set
`_event_status` from `source_not_found` to `published`, and nothing else.*

### 3.5 Safeguards that must precede it

| Safeguard | Requirement |
|---|---|
| Backup | A read-only snapshot of the exact `(post_id, _event_status)` pairs for the allow-list, taken immediately before the write and kept as evidence |
| Capability | The operation runs under an account holding the capability the admin screen requires; no ad-hoc SQL account |
| Dry-run preview | Render the exact allow-list with current → proposed value per row and diff it against the inventory TSV; a row not in the TSV is a refusal |
| Exact allow-list | An explicitly enumerated ID list. **No query-derived selection at write time** (never "all rows still `source_not_found`") — that would reintroduce the class of defect this investigation is about |
| Scope guard | Refuse any ID that is in a preservation cohort, is not an `event`, or whose `_event_source_id` differs from the inventory |

### 3.6 Verification after the operation

1. Re-run the inventory queries and confirm each allow-listed row now reads
   `published` and every non-listed row is unchanged.
2. Query the **real public path** — the event query the theme uses, with the
   `_event_status` gate — and confirm the event is returned. Not `get_post()`: the
   visibility rule is the thing being verified.
3. Confirm the count of `source_not_found` events fell by exactly the allow-list
   size and by nothing else.

### 3.7 Reversibility

Fully reversible. The prior value is known for every row (`source_not_found`), it
is captured in the backup, and restoring it is the same single-meta write in the
opposite direction. No post, term, media or translation is touched, so nothing is
lost if the change is reverted.

### 3.8 Authorisation required

A **separate, explicit authorisation** covering (a) the upstream fetch and (b) the
later write. This Stage 3.5 prompt authorises neither.

### 3.9 How would we know we were wrong?

The gate is designed so that being wrong is detectable: if a restored event is
**not** in fact upstream, the next complete run for that source will find it
absent from a complete walk and `mark_missing_events()` will set
`source_not_found` again. There is also a dedicated reappearing-event lifecycle for
the reverse direction (`class-event-importer.php:1872-1880`).

**Is re-suppression acceptable?** That is a decision for the user, not for this
plan. Two positions, both defensible:

* **Accept it** (recommended default): reconciliation is the safety net and a
  false restoration self-corrects within one complete run. No new rule needed.
* **Treat it as a defect signal**: if a restored event is re-suppressed within one
  run, that is evidence about the *enumeration*, not about the event, and it argues
  for investigating that source rather than for a "keep it published" flag.

Introducing any new rule (e.g. a sticky-published exception) would be a
**behaviour change to reconciliation** and is explicitly out of scope.

---

## 4. Priority 2 — 4 events, `eventbrite_cork`

### 4.1 Why conditional rather than high

Cork's retained run is the only one of the nine in which an affected source
actually ran, and it shows exactly why an enumeration cannot decide these four:

* the listing **declares 1252 objects but exposes only 49 pages of 20** — 980
  reachable objects out of 1252 (`upstream_truncated`, `page_count = 49`);
* the walk stopped at **page 38/49** on the 45 s budget;
* the run's reconciliation was refused (`03-` §3).

A future enumeration of that listing therefore **cannot** demonstrate presence or
absence for an event sitting beyond page 49 — and the importer cannot tell whether
it does. "Wait for a clean enumeration" is not an achievable gate for this source.

### 4.2 Evidence gate

1. A **fresh per-event provider lookup** by `_event_source_id` / `_event_url`
   returns the event. §3.3 applies unchanged; **`run_source()` still does not
   satisfy it.**
2. The returned identifier equals the stored `_event_source_id` — signature-change
   handling per §6.
3. Date sanity per §3.2(3).
4. **Plus** a recorded decision that enumeration-based reconciliation is
   achievable for this source at all. If it is not (49-page ceiling), restoring
   these four creates a permanent oscillation: restored now, re-suppressed on the
   next `is_fetch_complete()` run that cannot reach them — or protected from
   re-suppression only by never completing a walk.

### 4.3 What a future run must retain for this check

Stated concretely, because none of it exists today (`02-` §8):

* the **enumeration manifest** — the ordered list of `_event_source_id` values the
  walk returned — with run identity, `is_fetch_complete()`, `page_count`,
  `MAX_PAGES` outcome and region-filter rejection counts;
* the **per-event lookup result** for each of the four target IDs, with HTTP
  status, the provider's returned identifier and the returned date;
* a record of **which reconciliation gates passed**, so "complete" is a recorded
  fact rather than an inference from a success status.

A future run that retains none of this reproduces this investigation's evidence gap.

### 4.4 Operation, safeguards, verification, reversibility, authorisation

Identical to §3.4–§3.8, applied to four explicitly enumerated IDs. One extra
safeguard for this tier: the allow-list must carry a **written note of the
enumeration limitation**, so a later reader cannot mistake the restoration for a
clean enumeration result.

### 4.5 The truncated-listing risk, stated plainly

If these four events genuinely sit beyond the reachable 49 pages, restoring them
does not fix the *importer*; it hides a coverage defect for as long as the
suppression happens to be lifted. That is a reason to verify per event and to keep
the limitation documented — **not** a reason to leave a live event hidden if the
provider confirms it exists.

---

## 5. Priority 3 and Indeterminate — currently empty, and how a row would enter them

**No cohort member is assigned Priority 3 or Indeterminate.** Nothing in the
retained evidence indicates a stale identity, a persistently failing source, or
date/contract ambiguity for any of the 80, and nothing is too thin to tier.

A row would **move** into a lower tier only on new evidence, never on the current
record:

| Trigger | New tier | Then what |
|---|---|---|
| The provider's fresh lookup returns "not found" / 404 / cancelled | **Priority 3 — possible legitimate suppression** | Leave `_event_status` unchanged. Local evidence is sufficient to *close* the item as "legitimately gone" **only** when the lookup itself is the authoritative upstream answer; no write is warranted |
| The returned identifier differs from `_event_source_id` | **Priority 3 — stale/re-issued identity** | Leave unchanged; restoring by status would attach a different event's identity record |
| The source becomes `inactive` or accumulates repeated consecutive failures | **Priority 3 — source inactivity/failure** | Leave unchanged; local evidence is enough to *defer*, but an upstream check is still needed to close it |
| The provider's returned date is already past | **out of scope — legitimately past** | Leave unchanged; expected behaviour under the reconciliation rule |
| Records needed for a decision are missing or contradictory | **Indeterminate** | Leave unchanged pending further evidence |

For Priority 3 rows, **local-only evidence is sufficient for "leave unchanged"**
(the safe action needs no proof), but it is **not** sufficient to declare an event
legitimately gone. Closing an item as "absent upstream" requires the provider
response that says so.

---

## 6. Stale or re-issued `_event_source_id` values

Checked, not assumed:

* **Inside the cohort there are zero duplicate `_event_source_id` values** — no
  collision, and no evidence of a re-issued identifier within the 80.
* A stale identity cannot be detected from local data alone: nothing records
  whether the provider has retired an ID and re-issued it. The only detector is an
  upstream lookup returning an identifier that differs from the stored one, or
  returning an event whose title/date/venue clearly is not the stored one.
* If that happens, **restoration by status is not sufficient**. The record would
  need an identity decision (re-link, retire, or leave suppressed), which is a
  content-integrity change well beyond a status write and outside this plan's
  scope.
---

## 7. The 16 stranded continuations — a separate item, requiring separate authorisation

They are **outside the 80-event restoration scope** and Stage 3.5 authorises no
continuation mutation. They are surfaced here because leaving them undecided keeps
16 sources in a state where enumeration can never complete.

### 7.1 What Stage 3.5 established about them

| Question | Result |
|---|---|
| Connected to the nine 14:01–14:10 runs? | **Same mechanism and trigger class, different instances** (`03-` §4). Only 1 of the 16 (`cork`) belongs to an affected source, and its stranding dates from the 18:35 attempt, not from 14:01 |
| Will they create more suppressed events? | **No on this code** — `run_due()` never re-executes a non-`scheduled` record, and even if it ran, an incomplete enumeration cannot mark |
| What is the real cost of leaving them? | 16 sources whose enumeration can never finish → **coverage loss** (events never imported) plus permanent noise in the Continuation diagnostics |

### 7.2 The decisions the user must make

| Option | What it requires | Evidence for | Evidence against |
|---|---|---|---|
| **Retry** | A fresh-provider-fetch verification for the corresponding source (§4.2 mechanism), then a reset of the record out of `running`/`run_at=0` | 16 enumerations are currently unreachable; a retry resumes coverage | The retry re-enters a path that has already stranded records; without the mechanism fix (out of scope) it can strand again |
| **Abandon** | A documented reason per record and a mechanism to mark it abandoned **with no side effect on the source or its events** | Stops the diagnostics noise; makes the stranded state explicit | Forfeits the resumable progress (`last_page` / `page_count`); the source's next full run restarts at page 1 |
| **Leave visible, pending further investigation** | Nothing — the diagnostics already show them | Honest, no mutation; Stage 3 made them visible by design | Adds permanent noise; the coverage loss continues silently for 16 sources |

**No disposition is recommended here.** Each option needs its own authorisation and
its own evidence; "retry" in particular needs the upstream check first, because a
retry is a *repair attempt*, not verification (§3.3).

### 7.3 What is explicitly NOT authorised for the continuations

* no reset, retry, reschedule or clear;
* no `wp_schedule_single_event` / `wp_clear_scheduled_hook` call;
* no change to the 22 cron entries, including the 16 overdue ones;
* no change to `run_due()`, `reconcile()` or the stranding behaviour.

---

## 8. Authorisation matrix

| Item | Tiers affected | Authorisation needed | Granted by Stage 3.5? |
|---|---|---|---|
| Fresh provider fetch for the 76 Priority 1 events | P1 | Separate, explicit: outbound requests to Eventbrite for an enumerated ID list | **No** |
| Fresh provider fetch for the 4 Priority 2 events **plus** the enumeration-achievable decision | P2 | Separate, explicit, and the two must be authorised together | **No** |
| `_event_status` → `published` for an explicit allow-list | P1, P2 | Separate, explicit content-write authorisation with dry-run, backup and allow-list | **No** |
| Any change to `post_status` | — | **Not proposed anywhere**; would need its own justification and evidence | **No** |
| Continuation retry / abandon / leave | §7 | Separate, explicit decision per option | **No** |
| Any new reconciliation rule (e.g. sticky-published) | — | **Out of scope**; a behaviour change to the importer | **No** |
| Deploy, activate, release, push | — | **Out of scope for every stage of this line of work so far** | **No** |

---

## 9. Risks and open questions

1. **Expiry races.** Everything in the cohort expires. Three members end today;
   several end `2026-11-17`/`2026-11-18`. Any verification run must re-capture the
   inventory first and treat a now-past event as legitimately out of scope.
2. **`run_source()` mistaken for verification.** The single most likely way to
   get this wrong is to re-run the importer, see the event still absent, and
   conclude it is gone — or see it appear and conclude the earlier suppression was
   a false positive caused by incompleteness. Neither conclusion is licensed by
   that evidence (§3.3).
3. **Cork's 49-page ceiling.** Four events may be unreachable by any enumeration
   this importer can perform. Restoration would mask, not fix, that.
4. **Re-suppression churn.** Accepted by default (§3.9), but the user should decide
   explicitly rather than discover it.
5. **The root cause of the 80 suppressions is still unknown** (`03-` §7). A
   restoration plan can correct the symptom; it cannot prevent recurrence. That
   needs a run that retains an enumeration manifest — which does not exist yet.
6. **Retention keeps eroding the evidence.** Every day of import activity pushes
   the 2026-11-14/15 records further out. If a future investigation needs this
   window, the TSVs in this folder are now the only copy.
7. **Deployment.** Everything here describes the local shared stack. WordPress.com
   production identity is **UNVERIFIED**, so none of these findings may be assumed
   to describe the live site until that is established by an authorised check.

---

## 10. Closing statement

> **No restoration is recommended as safe on the evidence available in Stage 3.5.**
> No event may be called confirmed live, confirmed restorable or safe to restore:
> upstream presence is unproven for all 80, and no enumeration manifest exists that
> could prove it. Every operation in this document requires a separate, explicit
> authorisation, and none has been granted.

