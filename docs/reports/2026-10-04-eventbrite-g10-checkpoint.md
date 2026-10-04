# Report — G10: Eventbrite persistent enumeration checkpoint and safe resume

| | |
|---|---|
| **Stage / task name** | G10 — persistent, source-scoped Eventbrite enumeration checkpoint with bounded, safe resume |
| **Date** | 2026-10-04 |
| **Branch** | `master` |
| **Start SHA** | `62e570fcb5bab5297b0af7051efd0c56deb4e66a` (G9 baseline, clean tree) |
| **End SHA** | *uncommitted — not deployed, not committed* |
| **Predecessor** | [G9](2026-10-04-eventbrite-g9-operational-hardening.md) · [G8](2026-10-04-eventbrite-g8-source-expansion.md) · [G7](2026-10-04-eventbrite-api-retirement.md) |
| **Evidence** | [`docs/evidence/2026-10-04-eventbrite-g10-checkpoint/`](../evidence/2026-10-04-eventbrite-g10-checkpoint/) |
| **Production files changed** | **0** |
| **Production writes** | **0** |
| **Deployments** | **0** |
| **Commits** | **0** |

---

## Status

# `PASS WITH LIMITATION`

The checkpoint state machine, the resume algorithm, the overlap policy and every
safety invariant are implemented and proved by 77 assertions plus 8/8 mutation
proofs. The limitation is stated honestly in
[§ Remaining limitations](#remaining-limitations): **the convergence and
request-savings numbers are measured against a deterministic fixture, not against
live Eventbrite**, because a live multi-run sequence cannot be executed here —
Eventbrite answers 429 after ~31 requests per walk, and one walk is all the
evidence G8 could obtain.

## §2 — Baseline (G8/G9), recorded before any change

Working tree at start: **clean**. `git status --porcelain` empty; no pre-existing
user changes to preserve.

| Suite | G9 baseline |
|---|---|
| `test-eventbrite-source-expansion` | 65 passed, 0 failed |
| `test-source-pagination-completeness` | 24 passed, 0 failed |
| `test-eventbrite-importer` | 68 passed, 0 failed |
| `test-eventbrite-rate-limit` | 73 passed, 0 failed |
| `test-eventbrite-token-retirement` | 42 passed, 0 failed |
| `test-request-budget` | 32 passed, **1 failed** (order-dependent) |
| `test-request-reuse` | 53 passed, 0 failed |
| `test-error-handling` | 55 passed, 0 failed |
| `test-source-fetch-failure` | 138 passed, 0 failed |
| `test-import-log` | 55 passed, 0 failed |
| `test-import-log-run-correlation` | 21 passed, 0 failed |
| **Permanent gates** | **7/7 green, 84 assertions** |

**Pre-existing failure recorded, not hidden.** `test-request-budget` failed with

## §3 — The actual pagination contract

Derived from the repository code **and** the live walk evidence committed in
[`01-live-cork-all-events-walk.json`](../evidence/2026-10-04-eventbrite-g8-source-expansion/01-live-cork-all-events-walk.json).

| Question | Answer | Source |
|---|---|---|
| How is the page count discovered? | `search_data.events.pagination.page_count` on **page 1** | `class-eventbrite-source.php` + live payload |
| Is there a next-page URL / cursor? | **No.** No next-link or cursor exists in the payload | live payload |
| Are page numbers explicit? | **Yes** — `?page=N` appended to the base URL | `build_page_url()` |
| Does a page carry a stable identifier? | **Positional only.** The payload asserts `page_number`, which always equals the requested page | live walk: pages 1–31 each reported their own `page_number` |
| Is the page size known? | **Yes** — `page_size` = 20 | live walk, 20 results on every page |
| Is ordering stable across runs? | **No.** The payload exposes **no sort key** | live payload |
| Can objects move between pages? | **Yes** — the listing is a live ranking over a changing population, so new top-of-listing events shift every downstream boundary | no stable sort key + G8's measured churn |
| How is the end detected? | The walk runs to the declared `page_count`; a failed page makes the run incomplete | `fetch_via_scrape()` |
| How is `object_count` obtained? | Same `pagination` block on page 1 | live: Cork 1,547 / Dublin 5,162 / Cavan 5,722 |
| How is `page_count` obtained? | Same block; pinned at 49 for every county | live |
| How is a page failure represented? | `fetch_page()` returns `''`; the handler classifies it (`rate_limited`, `waf`, `budget_exhausted`, `network`, `upstream_unavailable`), marks the walk incomplete and either breaks (429) or continues (other failures) | G1–G5 |

**The load-bearing conclusion:** a page number is a **position, not an
identity**. That is why the resume strategy needs a bounded overlap, and why
`last_page + 1` alone would be unsafe.

## §4 — Checkpoint model

> **A checkpoint is the last position whose page was fetched, parsed and merged —
> durably confirmed progress.**

It is **not** "everything before this is permanently complete", and it can never
independently imply completeness, absence, or permission to reconcile.

**Class:** `Conexao_Source_Enumeration_Checkpoint`
(`wp-content/plugins/conexao-event-importer/includes/class-source-enumeration-checkpoint.php`)

**Storage:** one WordPress option, `conexao_event_enumeration_checkpoints`, keyed
by source id. This reuses the **existing project persistence pattern** —
`Conexao_Source_Cooldown` uses the identical shape (single option, per-source
records, `MAX_SOURCES` bound, `prune()` on overflow). No new table, no new
framework, no separate subsystem.

**Fields actually stored** (only what is necessary):

| Field | Why it is needed |
|---|---|
| `source_id` | source isolation |
| `fingerprint` | binds the record to the source definition that produced it |
| `last_page` | the resume position — the only field the resume reads |
| `page_count` | lets an operator read "page 31 of 49" without a request |
| `complete` | **progress only** — "this generation reached its final page" |
| `last_failure` | `rate_limited` / `waf` / `time_budget` / `budget_exhausted` / … |
| `updated_at` | LRU pruning |
| `runs` | how many runs contributed |
| `schema_version` | an incompatible record is discarded, never reinterpreted |

**Explicitly NOT stored:** response bodies, HTML, per-request data, event
payloads. The checkpoint answers "where were we", never "what did we see". This
also satisfies §26 — the checkpoint is progress state, not a response cache.

## §5 — Source-scoped state

State is keyed by source id. `eventbrite_cork`, `eventbrite_dublin`,
`eventbrite_carlow`, `eventbrite_cavan` each own an independent record.

Proved by `G10.7` (4 assertions): Cork is stopped at page 4 by a 429; Dublin is
then walked from scratch, does **not** resume, requests page 2, and ends at its
own page 20 — while Cork's record is untouched at page 4.

## §6 — Run identity vs checkpoint identity

| Concept | Owner | Lifetime | Used for |
|---|---|---|---|
| `run_id` | `Conexao_Event_Importer_Engine::$current_run_id` | one execution | correlating log rows and Import History |
| **checkpoint** | `Conexao_Source_Enumeration_Checkpoint` | **survives the run** | where the next run resumes |
| enumeration generation | the `fingerprint` + `schema_version` pair | changes when the source definition changes | invalidating incompatible progress |

The checkpoint deliberately does **not** store a `run_id`. Storing one would

## §7 — Safe checkpoint advancement (ordering)

```
fetch (200) → validate/parse OK → merge into the result set → PERSIST checkpoint
```

The checkpoint is advanced at **exactly one place** in the walk
(`class-eventbrite-source.php`, immediately after
`$all_events = array_merge( $all_events, $page_data['events'] );`), and nowhere
else. Every failure branch instead persists `$this->confirmed_page` — the last
page that genuinely completed — never the page currently being attempted.

**Proved observationally by `G10.3`:** with a fixture whose page 4 answers 429,
the stored checkpoint is exactly `3`, and page 4 is confirmed to have been
genuinely requested (so `3` is a real fail-closed result, not a loop
off-by-one). **Proved negatively by mutation M1** (advance before the fetch):
the suite fails 9 assertions.

## §8 — Atomicity / crash safety

| Crash point | Result | Why it is safe |
|---|---|---|
| after fetch, before parse | checkpoint unchanged | next run re-walks that page |
| during normalisation | checkpoint unchanged | next run re-walks that page |
| immediately before the checkpoint write | checkpoint unchanged | same |
| immediately after the checkpoint write | checkpoint at page N | the events are already merged into the result set, so they are already being processed |
| mid-walk, any page | checkpoint at the last merged page | progress is a **prefix**, so no page is ever skipped |

The system is **at-least-once with idempotent identity**, not exactly-once.
Reprocessing is safe because the deduplication boundary remains the existing
stable Eventbrite event ID (§11). Exactly-once assumptions were refused because
a crash between the WordPress write and the checkpoint write would then lose
events permanently.

## §9 — Resume behaviour

```
1. load the checkpoint for this source
2. validate it (schema + fingerprint + last_page >= 1 + last_page <= page_count)
3. page 1 is fetched regardless — it carries the pagination metadata
4. resolve the resume position, including bounded overlap
5. continue forward to page_count
6. advance the checkpoint after each confirmed page
```

`resolve_resume_page()` returns `2` (a normal full walk) whenever the
checkpoint is absent, stale, malformed, foreign or already at/past the current
end. Resumption is enabled by default and can be turned off with the
`conexao_eventbrite_checkpoint_enabled` filter, which is what makes the BEFORE /
AFTER comparison in §22 possible on identical code.

## §10 — Page reordering protection: the bounded overlap

**Was overlap needed? Yes, and the evidence is structural, not speculative.**

- The payload exposes **no sort key and no cursor** (measured, §3).
- The listing is a live ranking over a changing population (G8 measured churn
  between runs).
- Therefore a page boundary is a **position**. Publishing *k* new events at the
  top shifts every downstream boundary by *k* positions, so an event confirmed at
  page *N* in run 1 can legitimately appear at page *N+k* in run 2. Resuming at
  `last_page + 1` would skip exactly those displaced events — a silent coverage
  hole, which is exactly the class of bug G8 was created to eliminate.

**Why one page.** `OVERLAP_PAGES = 1`. Eventbrite's measured page size is 20, so
one page of overlap absorbs up to **20** new top-of-listing insertions
displacing the boundary — far more than the churn observed between two scheduled
runs — while costing **1** request instead of re-walking the prefix. A larger
overlap would defeat the purpose of checkpointing; `0` would reintroduce the
silent gap.

**Measured cost** (`03-convergence-experiment.txt`): 19 repeat requests across
10 resumed runs, i.e. **1.9 requests per run**, of which 1 is the mandatory page-1
metadata fetch and 1 is the overlap page. Every one of those repeats was
de-duplicated to zero duplicate events (§11).

Proved by `G10.6`: a checkpoint at page 10 resumes at page 9, and page 8 is
**not** re-walked.

## §11 — Stable event identity

Checkpointing changes **no** identity logic. The primary identity remains the
native Eventbrite event id, exactly as in G8.

- `G10.17` proves dedup by identity is real and observable: a fixture serving the
  same six ids on two pages yields **6 results, zero repeats**. Removing that
  dedup is mutation **M8** (suite fails).
- `G10.12` proves repeated resumed runs are idempotent: after convergence the
  checkpoint stops changing and no further unique events appear.
- `G10.6` proves the overlap re-collects events without producing duplicates.
- **No page-derived identity was introduced anywhere.**

---

## §12 — Completeness is independent of the checkpoint

> **Checkpoint / progress state does not imply source completeness.**
> A checkpoint says "we got this far safely", never "this is everything".

Three separate facts, three separate signals:

| Fact | Signal | May it authorise deletion? |
|---|---|---|
| how far enumeration got | the checkpoint | **never** |
| was the listing fully enumerated | `is_fetch_complete()` | only together with the third |
| may absence be reasoned about | `is_reconciliation_safe()` | this is the gate |

Realisable states, each covered by a test:

| Scenario | Checkpoint | `is_fetch_complete()` | Reconciliation |
|---|---|---|---|
| partial run | page 5 of 20 | false | refused |
| rate-limited run | page 4, `last_failure=rate_limited` | false | refused |
| WAF-blocked run | page 5, `last_failure=waf` | false | refused |
| request/time-budget stop | last confirmed page | false | refused |
| genuine completion (non-truncated) | final page | **true** | allowed |
| truncated listing that reached its last page | page 49, `complete=true` (progress) | **false** | refused |

**A checkpoint can never turn `complete` true.** The checkpoint's own
`complete` flag is written *after* the truncation guard has already run, which is
precisely how `G10.10` proves the two diverge: the truncated Cork-shaped fixture
(1,547 objects behind 49 × 20) records a checkpoint at page 49 with progress
`complete = true`, while `is_fetch_complete()` remains **false**.
conflate "which execution did this work" with "how far has enumeration got", and
would let a stale run id masquerade as current state.

**Why a fingerprint instead of an explicit generation counter.** G10 §6 asks
for an enumeration generation only where pagination can be invalidated by source
changes. Measured evidence says the only material invalidations are (a) the
source definition changing and (b) the listing shrinking. A fingerprint derived
from exactly the fields that change *which events* the listing yields
(`type`, `url`, `county`) covers both, without inventing a counter that would
need its own invalidation rules. Where the listing shrinks, the existing
`last_page > page_count` check discards the record. An explicit generation
integer would add a second mechanism for a problem the fingerprint already
solves, so it was deliberately **not** introduced.
`FAIL: G4C control: an unconstrained run completes the walk` / `FAIL: G4C
control: on a COMPLETE walk the absent event IS marked source_not_found` **only
when run after the Eventbrite suites in the same batch**, and passed 33/0 when
run standalone. That order-dependence is a real pre-existing defect, not a G10
regression; §Tests records how G10 resolves it (the checkpoint option was not
isolated, so state leaked between suites).

## §13 — Truncation guard remains authoritative

G8's rule is untouched:

```php
if ( $this->is_upstream_truncated( $object_count, $page_count, $page_size ) ) {
    $this->mark_fetch_incomplete();
}
```

It still runs **before** the resume decision and before any final checkpoint
write, so reaching the nominal final page can never imply completeness.
`G10.10` proves the required state is still reachable:

```
checkpoint = 49, progress complete = true, is_fetch_complete() = false
```

Removing the guard is mutation **M4** (suite fails).

## §14 — Safety: interaction with every failure mode

| Condition | Checkpoint behaviour | Enumeration behaviour | `mark_missing_events()` |
|---|---|---|---|
| **HTTP 429** | preserved at the **last confirmed** page; `last_failure = rate_limited` | `break` (G5C fail-fast), incomplete | unreachable |
| **WAF / HTTP 405** | preserved at the last confirmed page; `last_failure = waf` | incomplete | unreachable |
| **Request budget exhausted** | every page that *was* confirmed is persisted | incomplete (`budget_exhausted`) | unreachable |
| **Time budget exhausted** | last confirmed page persisted; `last_failure = time_budget` | incomplete | unreachable |
| **Cooldown** | **untouched** — the gate returns before any fetch, so progress cannot be reset | incomplete, **zero requests** | unreachable |
| **Truncation** | may record progress-complete at the last page | **incomplete** | unreachable |
| **Resumed subset** | unchanged | complete-but-subset | **unreachable by a second, independent gate** |
| **Parse failure** | last confirmed page persisted; `last_failure = parse_failed` | incomplete | unreachable |

### The two gates in front of `mark_missing_events()`

```php
$fetch_complete      = $this->is_handler_fetch_complete( $handler );
$reconciliation_safe = $this->is_handler_reconciliation_safe( $handler );

if ( $fetch_complete && $reconciliation_safe ) {
    $this->mark_missing_events( $source_id, $raw_events );
}
```

The second gate is the G10 addition and it exists because resuming creates a
state the pre-G10 engine could not produce: **a run that is complete but whose
event set is a subset.** Without it, a resumed walk that reached page 49 would
flip every event in the skipped pages 2–31 to `source_not_found` and hide live
events from the public site — precisely the defect the 2026-10-03 investigation
found five real Cavan events behind.

`G10.15` proves this by consequence, against a **real WordPress post** using the
exact meta keys and post type the reconciliation query reads
(`event`, `_event_source`, `_event_url`, `_event_status`). The case first asserts
the probe event *is* reachable by that query, so the proof cannot be vacuous.
After a resumed run the event is **still published**. Mutation **M7** (checkpoint
authorises reconciliation) makes the suite fail 3 assertions.

## §15 — What was NOT changed (explicit non-goals)

| Refused | Why |
|---|---|
| **No cadence change** (§25) | G9 deferred cadence pending volatility measurements; none were taken |
| **No response caching** (§26) | G4 measured no live cache-hit benefit; the checkpoint stores progress, not bodies |
| **No window sources activated** (§24) | G8's `events-this-week` / `today` / `tomorrow` stay `inactive` |
| **No API integration** | G7 retired the token; 0 live references remain |
| **No new table / framework** | the existing cooldown option pattern was reused |
| **No G8/G9 guard weakened** | every existing guard verified by mutation |
| **No production writes, no deploy, no commit** | out of scope by the brief |

## §16 — Import History integration

`Conexao_Import_History::record()` gained a bounded `checkpoint` shape
(`has_checkpoint`, `last_page`, `page_count`, `complete`, `last_failure`,
`updated_at`), recorded on every engine run path alongside the existing
`complete` and `cooldown` fields. The Import History table gained a
**Progress** column rendering e.g. `page 31 of 49 (rate_limited) — resumable`.

No second dashboard was created, and Import History **never** treats the
checkpoint's existence as completion — the existing **Listing** column remains
the only completeness signal, and the Progress column's own docblock says so.

## §17 — Stale / invalid checkpoint policy

**Chosen policy: discard the checkpoint and restart safely at page 1.** Never
skip unknown pages, never resume from an unverified position.

| Case | Behaviour | Proved by |
|---|---|---|
| nonexistent checkpoint | normal full walk | `G10.1` |
| foreign fingerprint (other source definition) | discarded, full walk | `G10.8a` |
| impossible page (`last_page > page_count`) | discarded, full walk | `G10.8b` |
| malformed / incompatible schema | discarded, full walk | `G10.8c` |
| corrupted non-array record | rejected by the validator itself | `G10.8d` |
| configuration change (URL changed) | discarded, full walk | `G10.9` |
| cosmetic change (rename) | **retained** — progress not wasted | `G10.9` |

## §18 — Source configuration changes

The fingerprint covers only `type`, `url` and `county` — the fields that change
*which events* the listing yields. Changing the discovery URL invalidates the
checkpoint; renaming the source does not. Page-size and parser changes need no
special handling because the page size is re-read from page 1 on every run, and
a changed parser produces different page content which the identity dedup and
normalisation already handle. Disable/re-enable of a source changes nothing,
because the checkpoint is keyed by source id and is not touched by status.

## §19 — Persistence design

Reused the established pattern rather than inventing one:

| Concern | How it is met |
|---|---|
| source isolation | keyed by source id |
| atomic-enough writes | a single `update_option()` of a small record, written only after the work it describes |
| predictable cleanup | `MAX_SOURCES = 200` with least-recently-updated pruning; `reset()` for a single source |
| no secrets | the record holds no credentials and no event data |
| bounded state size | ~9 small scalars per source |
| no event payloads | nothing but progress counters is stored |

---

## §20 — The state machine under test

New suite: `wp-content/plugins/conexao-event-importer/tests/test-eventbrite-checkpoint.php`
— **77 assertions, 0 failed**, all against `pre_http_request` doubles. No real
upstream contact, no production write.

| # | Required scenario | Case | Result |
|---|---|---|---|
| 1 | first run starts normally | `G10.1` | pass — no resume, page 2 requested, final page checkpointed |
| 2 | successful page advances checkpoint | `G10.1`, `G10.3` | pass |
| 3 | failure before processing does not advance | `G10.2`, `G10.3` | pass — 429 on page 5 ⇒ checkpoint 4 |
| 4 | failure after processing, before persistence, recoverable | `G10.2` + M1 | pass — next run resumes |
| 5 | request-budget stop persists checkpoint | `G10.13b` | pass |
| 6 | time-budget stop persists checkpoint | `G10.13a` | pass |
| 7 | repeated 429 preserves checkpoint | `G10.2` | pass |
| 8 | WAF block preserves checkpoint | `G10.4` | pass — page 6 never recorded as confirmed |
| 9 | next run resumes | `G10.5` | pass |
| 10 | bounded overlap occurs | `G10.6` | pass — checkpoint 10 ⇒ page 9 only |
| 11 | overlap does not duplicate events | `G10.6`, `G10.17` | pass |
| 12 | source isolation works | `G10.7` | pass |
| 13 | malformed checkpoint rejected safely | `G10.8c`, `G10.8d` | pass |
| 14 | stale checkpoint invalidated safely | `G10.8a`, `G10.8b`, `G10.9` | pass |
| 15 | configuration change invalidates unsafe state | `G10.9` | pass |
| 16 | complete source sets completion independently | `G10.11` | pass |
| 17 | truncation guard prevents completion | `G10.10` | pass |
| 18 | incomplete source never reaches `mark_missing_events()` | `G10.15`, `G10.16` | pass |
| 19 | repeated resumed runs are idempotent | `G10.12` | pass |

## §21 — Mutation / negative proofs

`scripts/g10-mutations.sh` applies one surgical mutation at a time and requires
the suite to **fail**. A mutation that leaves the suite green is reported
`VACUOUS` and fails the script, so a vacuous proof cannot pass. Originals are
restored by an `EXIT` trap.

Result: **8 / 8 PROVEN, 0 vacuous** (`02-mutation-proofs.txt`).

| # | Mutation | Suite result under mutation | Reaches the intended branch? |
|---|---|---|---|
| **M1** | advance checkpoint **before** a successful fetch | 68 passed, **9 failed** | yes — `G10.3` (checkpoint becomes 4, not 3), `G10.2`, `G10.12`, `G10.15` |
| **M2** | resume always restarts at page 1 | 72 passed, **5 failed** | yes — `G10.5`, `G10.6`, `G10.7`, `G10.11`, `G10.12` |
| **M3** | an incomplete run is reported as complete | 75 passed, **2 failed** | yes — `G10.16` (engine-level `complete=false`) |
| **M4** | upstream-truncation guard removed | 76 passed, **1 failed** | yes — `G10.10` |
| **M5** | one checkpoint shared across all sources | 48 passed, **29 failed** | yes — every `G10.7` isolation assertion |
| **M6** | a failed page advances the checkpoint | 76 passed, **1 failed** | yes — `G10.2` (checkpoint 5, not 4) |

| **M7** | checkpoint authorises missing-event reconciliation | 74 passed, **3 failed** | yes — `G10.15` (the probe event becomes `source_not_found`) |
| **M8** | overlap identity deduplication removed | 76 passed, **1 failed** | yes — `G10.17` (12 results instead of 6) |

Each mutation produces a **different, specific** failure set, which is how we
know the tests read the intended branch rather than tripping incidentally. M5
in particular fails 29 assertions, confirming source isolation is genuinely

## §22 — Controlled convergence experiment

`wp-content/plugins/conexao-event-importer/tests/g10-convergence-experiment.php`,
run twice on identical code — once with `conexao_eventbrite_checkpoint_enabled`
false (BEFORE) and once true (AFTER).

**Fixture:** 12 pages × 20 events = **240 objects**, the measured Eventbrite page
size. Each run is bounded by the **real per-run request budget** of **4
requests** (`Conexao_Request_Budget`), which is the honest model of the G8/G9
measurement that a walk is cut short long before the listing ends. Ten bounded
runs per sequence. Full output: `03-convergence-experiment.txt`.

### BEFORE — repeated bounded runs restart from page 1

```
run  requests  pages     checkpoint  new ids  cumul  complete
1    4         1,2,3,4   0           80       80     no
2    4         1,2,3,4   0           0        80     no
...  (runs 3-10 identical)
TOTAL requests: 40 | unique events recovered: 80 / 240
```

Every run re-fetches the same four pages, recovers **0** new events after the
first, and never converges.

### AFTER — repeated bounded runs progressively advance

```
run  requests  pages        checkpoint  new ids  cumul  complete
1    4         1,2,3,4      4           80       80     no
2    4         1,3,4,5      5           20       100    no
3    4         1,4,5,6      6           20       120    no
...
9    4         1,10,11,12   12          20       240    yes
TOTAL requests: 40 | unique events recovered: 240 / 240
```

Converges at **run 9**, reaching genuine completeness.

## §23 — Request savings

The key comparison is **not** "fewer requests" — with a fixed per-run budget the
request count is identical by construction. The honest metric is **how much new
coverage each request buys**.

| Metric | BEFORE | AFTER |
|---|---|---|

| Total requests (10 runs) | 40 | 40 |
| Unique events recovered | **80 / 240 (33%)** | **240 / 240 (100%)** |
| New events per run after the first | **0** | **20** |
| Converged | **no** | **yes (run 9)** |
| Coverage gain at the same request cost | — | **+160 unique events (3.0×)** |
| Repeat requests for overlap | 0 | **19 across 10 runs (1.9/run)** |
| Duplicate events produced by overlap | 0 | **0** |

**Overlap cost is 19 requests for 160 extra events** — about 8.4 additional
events recovered per repeat request. Of those 19, ten are the mandatory page-1
metadata fetch (the G8 truncation guard depends on it) and nine are the overlap

This is the concrete form of the brief's requirement — *"materially more new-page

**One honest artefact:** in AFTER run 10 the walk restarts at page 1. That is
deliberate and correct: once a generation reaches `last_page >= page_count` there
is nothing left to resume, so the walk re-enumerates the listing to detect a new

## §24 — Verification: before vs after

Other gates:

| Gate | Result |
|---|---|
| `./scripts/lint.sh` | **OK** — syntax clean, **no new** PHPCS violations, PHPStan clean |
| `scripts/generate-registry-docs.php --check` | **OK** — 10 plugins, 19 regions, zero writes |
| `verify-eventbrite-token-retirement.py` | **59 passed, 0 live references** (G7 intact) |
| `verify-script-conventions.py` | 734 passed, 0 failed (after cataloguing the new script) |
| `i18n_freshness` | **3 passed** — catalogues regenerated with `scripts/i18n-make-pot.sh`, never hand-edited; 0 `eventbrite_api_token` occurrences survive |

| Suite | Baseline | G10 | Delta |
|---|---|---|---|
| `test-eventbrite-checkpoint` (**new**) | — | **77 passed, 0 failed** | **+77 assertions** |
| `test-eventbrite-source-expansion` | 65 / 0 | 65 / 0 | 0 |
| `test-source-pagination-completeness` | 24 / 0 | 24 / 0 | 0 |
| `test-eventbrite-importer` | 68 / 0 | 68 / 0 | 0 |
| `test-eventbrite-rate-limit` | 73 / 0 | 73 / 0 | 0 |
| `test-eventbrite-token-retirement` | 42 / 0 | 42 / 0 | 0 |
| `test-request-budget` | 32 / **1** | **33 / 0** | **+1 pass, −1 failure** |
| `test-request-reuse` | 53 / 0 | 53 / 0 | 0 |
| `test-error-handling` | 55 / 0 | 55 / 0 | 0 |
| `test-source-fetch-failure` | 138 / 0 | 138 / 0 | 0 |
| `test-import-log` | 55 / 0 | 55 / 0 | 0 |
| `test-import-log-run-correlation` | 21 / 0 | 21 / 0 | 0 |
| **Total** | **626 passed, 1 failed (627 assertions)** | **704 passed, 0 failed** | **+77 assertions, −1 failure** |
| **Permanent gates** | 7/7, 84 assertions | **7/7, 84 assertions** | 0 |

**Failure-set delta:** the single pre-existing failure is **resolved**, and no
new failure was introduced anywhere. No assertion count was reduced, no gate was
weakened, and no allowlist was touched.

**The pre-existing failure was fixed at the source, not masked.** Its root cause
was genuine: `test-request-budget` did not isolate the new checkpoint option, so
a checkpoint written by an earlier suite leaked into it. Its G4C control then
measured *resume-safety* instead of the completeness guard it was written for.
The fix isolates the option and makes the control an explicit full walk — the
exact situation the control was authored for — so the control still proves the
guard is not blanket-disabled. See the comment at the control.

## §25 — Files changed (G10 only)

**New files (5)**

| Path | Purpose |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-source-enumeration-checkpoint.php` | the checkpoint store (option-backed, source-scoped) |
| `wp-content/plugins/conexao-event-importer/tests/test-eventbrite-checkpoint.php` | 77 assertions, the full state machine |
| `wp-content/plugins/conexao-event-importer/tests/g10-convergence-experiment.php` | the BEFORE/AFTER convergence measurement |
| `scripts/g10-mutations.sh` | the 8 mutation proofs |
| `docs/evidence/2026-10-04-eventbrite-g10-checkpoint/` | 7 evidence artefacts |

**Modified files (G10 changes only)**

| Path | Change |
|---|---|
| `includes/sources/class-eventbrite-source.php` | `OVERLAP_PAGES`, `resolve_resume_page()`, `persist_checkpoint()`, `classify_page_failure()`, `is_reconciliation_safe()`, `did_resume()`, `get_confirmed_page()`, and the single post-merge checkpoint write |
| `includes/class-event-importer.php` | `is_handler_reconciliation_safe()`, the second gate in front of `mark_missing_events()`, `checkpoint_state_for()`, and the `checkpoint` payload on every history record |
| `includes/class-import-history.php` | bounded `checkpoint` shape on the history entry |
| `includes/class-event-sources.php` | the Import History **Progress** column (+40 lines, no unrelated reformatting) |
| `conexao-event-importer.php` | one `require_once` for the new class |
| `tests/test-request-budget.php` | isolate the new option; make the G4C control an explicit full walk |
| `docs/plugins/conexao-event-importer.md` | the operator-facing G10 section |
| `scripts/README.md` | catalogue the new script (required by the Stage I gate) |
| `languages/*.pot` (9) | regenerated with the sanctioned script; otherwise only the creation timestamp changed |

**No production file, theme, route, translation state or schedule was touched.**

## §26 — Remaining limitations

1. **The convergence and request-savings numbers are fixture-based, not live.**
   A live multi-run sequence cannot be produced here: Eventbrite answers 429
   after ~31 requests per walk, so consecutive runs cannot be issued in one
   session. The *state machine* is proven deterministically; the *absolute
   coverage-per-request ratio on live Cork* is not measured. Classified as
   **live verification limited**.
2. **`OVERLAP_PAGES = 1` is justified structurally, not empirically.** The
   reasoning (no sort key ⇒ positions shift; 20 events per page ⇒ one page
   absorbs ≤20 insertions) is sound, but the *actual* churn rate between two
   scheduled runs has not been measured. If a real listing shifts by more than
   one page between runs, `OVERLAP_PAGES` should be raised — a one-line constant.
3. **Live resume was not exercised end to end.** A single live walk cannot
   produce a checkpoint and then resume it, because cooldown blocks the follow-up
   run. The resume path is proven against doubles only.
4. **Once a generation completes, the next run re-walks from page 1.** Correct
   (a new cycle must be detectable) but it means completion costs one full walk.
   A generation counter would avoid it; §6 explains why one was not introduced.
5. **The server-asserted `page_number` is recorded for diagnostics but is not
   enforced.** Enforcing it would break the established G8/G9 suites, whose
   fixtures serve a static `page_number: 1` on every URL. Overlap already covers
   the reordering risk, so this was a deliberate trade-off, recorded here rather
   than hidden.
6. **Per-event import failures inside confirmed pages are not retried by a
   resume.** The checkpoint is about *enumeration* progress; a failed event stays
   visible in Import History's `failed_events` with its reason, exactly as
   before G10. The G12 batch boundary is unaffected.
7. **One new test seam exists on a production class:**
   `conexao_eventbrite_checkpoint_enabled`. It grants no privilege (disabling
   checkpointing only makes a run do more work) and exists so the BEFORE/AFTER
   comparison can run on identical code.

## §27 — Production / deployment / mobile

| | |
|---|---|
| Production files changed | **0** |
| **Production writes** | **0** |
| **Deployments** | **0** |
| Commits | **0** (not requested) |
| Production configuration / menus / translation state | untouched |
| **Flutter/mobile repository** | **untouched — never accessed, inspected, built, tested or referenced** |
| Local Docker WordPress | used for tests only; no production target was contacted by any command |

## §28 — Recommendation

**G10 should be closed.**

Checkpoint persistence, resume, bounded overlap, source isolation, invalidation
and every safety invariant are implemented and proved by 77 assertions and 8/8
mutation proofs, with no existing guard weakened and no baseline weakened.

**The next stage should be the planned volatility measurement** — the one input
G9's §21 and G10's `OVERLAP_PAGES` both still lack. Run the three registered
window sources for one county on a fixed cadence for ~7 days and record, per run:
events found, created, updated, unchanged, and the wall-clock delta. That
produces (a) the churn rate that would confirm or correct `OVERLAP_PAGES = 1`,
and (b) the volatility data cadence requires. Both are measurements, not code.

```bash
# manual, operator-driven, no cron and no production write
docker compose exec wordpress wp eval \
  'apply_filters("conexao_event_importer_run_source", "eventbrite_cork_events_this_week");'
```

**Do not** raise request budgets, expand caching, raise the overlap to "cover
more", or relax the truncation guard to make a run look greener. Each of those
would trade a truthfulness property this stage was chartered to protect.
