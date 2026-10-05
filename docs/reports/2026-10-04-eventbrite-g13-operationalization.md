# G13 — Evidence-based operationalization

| | |
|---|---|
| **Stage** | G13 — classify sources, derive cadence, confirm the operating model |
| **Date** | 2026-10-05 |
| **Branch** | `master` |
| **Start SHA** | `30b2b3a` (clean tree) |
| **Predecessor** | [G12](2026-10-04-eventbrite-g12-volatility.md) · [G11](2026-10-04-eventbrite-g11-volatility.md) · [G10](2026-10-04-eventbrite-g10-checkpoint.md) · [G9](2026-10-04-eventbrite-g9-operational-hardening.md) |
| **Input evidence** | [`evidence/eventbrite-g12/`](evidence/eventbrite-g12/) |
| **Production files changed** | **0** |
| **Production writes** | **0** |
| **Deployments** | **0** |

---

## Status

# `PASS WITH LIMITATION`

G13 makes the source decision **ACTIVATE `eventbrite_cork_events_this_week`** and
**REJECT `eventbrite_cork_events_today`**, on measured value and request cost,
and it deliberately leaves **cadence UNRESOLVED**.

The limitation is inherited and honest, not hidden: G12's observation window was
**114.6 minutes**, not seven days. The **daily change rate was never measured**,
so §16's requirement to derive cadence from *daily* volatility **cannot be
satisfied from evidence** — and §18 explicitly instructs that unresolved
volatility leaves the cadence unresolved rather than invented. G13 follows §18.

What G13 *does* settle, it settles from evidence: one source activated, one
rejected, one deferred — each with a measured number attached; cooldown, request
budget and caching all reviewed and **left unchanged** because no evidence
justified changing them; and the operator workflow documented against the
existing Import History, with no second dashboard.

---

## §1 — Decision summary (§14)

| Source | Decision | Measured basis |
|---|---|---|
| `eventbrite_cork_events_this_week` | **ACTIVATE** | 124 unique / **97 incremental (78.2 %)**, **9/9 complete**, 60 requests, **0 duplicates** |
| `eventbrite_cork_events_tomorrow` | **DEFER** | 23 unique / 20 incremental, but **0 change in 9 comparisons** and ≥2 events already inside `this-week` |
| `eventbrite_cork_events_today` | **REJECT** | 8 unique, **0.20 events/request**, drained to **0**; volatility is the calendar, not the listing |

**Why not "it returned events, so activate it"** — the brief's §14 warning.
`events-today` returned events and is still rejected; `events-tomorrow` is stable
and is still not activated. Volume alone decided nothing.

## §2 — The source-value calculation (§13)

Measured by `tests/experiments/g12-source-value.php` over all 40 observations
([`03-source-value.txt`](evidence/eventbrite-g12/03-source-value.txt)):

| Source | Obs | Unique | Repeat | Overlap w/ all-events | **Incremental** | Events/req | Requests |
|---|---:|---:|---:|---:|---:|---:|---:|
| `eventbrite_cork` | 10 | 162 | 162 | 162 | 0 | 2.03 | 80 |
| `events_this_week` | 10 | 124 | 123 | 27 | **97** | **2.07** | 60 |
| `events_today` | 10 | 8 | 8 | 0 | 8 | **0.20** | 40 |
| `events_tomorrow` | 10 | 23 | 23 | 3 | **20** | 1.15 | 20 |

**Window union: 125 incremental events for 120 requests** — an **upper bound**,
because the overlap denominator is the *bounded* 8-page all-events walk (162
events). Against G8's full 31-page walk (618 events) the true overlap is larger
and the true incremental smaller. The bound is labelled as such in the evidence
file rather than presented as exact.

### 2.1 `events_this_week` → **ACTIVATE**

Every §14 ACTIVATE criterion is met by a measurement:

| Criterion | Evidence |
|---|---|
| Produces meaningful unique coverage | **97 incremental events** (78.2 % of its unique set) |
| Sufficiently stable | **9/9 complete enumerations**, mean change rate **0.42 %** |
| Acceptable request cost | **60 requests / 10 observations = 6 per observation** |
| Acceptable completeness semantics | complete, so it may reconcile absence safely; its own incompleteness rules still apply |

Corroborated by G8: **193 incremental events in 16 requests** against the full
618-event all-events walk. Two independent walks agree on direction and
magnitude.

Its cost per unit of value is the best of the three windows: **2.07 events per
request** versus 1.15 (`tomorrow`) and **0.20** (`today`).

### 2.2 `events_tomorrow` → **DEFER**

Attractive on paper — 87 % incremental, complete, 2 requests. Still deferred,
for two measured reasons:

1. **No observed change at all.** Zero new, zero removed, zero movement across
   **9 complete comparisons** spanning 114.6 minutes.
2. **Its value is largely subsumed.** Comparing G8's full walks, 2 of its 23
   events are not in `this-week`. Activating it would add ~2 unique events for
   a permanently-on source and its ongoing request cost.

§14's `DEFER` is exactly this case: real potential, insufficient evidence. More
measurement, not more configuration.

### 2.3 `events_today` → **REJECT**

| | |
|---|---|
| Unique events observed | **8** (draining to 0) |
| Events per request | **0.20** — worst of any source measured |
| Requests spent | **40** for 8 events |
| Volatility | **HIGH**, but **calendar-driven** |
| Why rejected | its churn is the Irish day ending, not Eventbrite churn |

§6 excludes `events-today` from ordinary cadence inference unless the analysis
models its calendar semantics. G12 did not model them, so a cadence derived from
it would be **measuring the clock**. This is the clearest example in the
programme of "returns events" ≠ "worth activating".

## §3 — Activation safety (§15)

Nothing new was created. Activation uses the **existing registry**:

- **Canonical source identity preserved.** `eventbrite_cork_events_this_week`
  comes from `Conexao_County_Registry::get_eventbrite_window_source()`; its URL,
  county hint and region labels are untouched.
- **Source-specific completeness preserved.** Same `Conexao_Source_Eventbrite`
  handler; its truncation/budget rules still apply and are not bypassed.
- **No second importer architecture.** `type = eventbrite`, same adapter, same
  checkpoint class, same cooldown class.
- **G7 API-token retirement intact.** Verified live: the local database contains
  **zero** `eventbrite%` options, and no executable file references
  `eventbrite_api_token`.

The two required safety proofs were established live in **G14**, not asserted
here (see [G14 §4–§5](2026-10-04-eventbrite-g14-controlled-import.md)):

```text
source failure  → incomplete  → mark_missing_events() NOT called
source duplicate → one stable Eventbrite identity → one WordPress event
```

## §4 — Cadence decision (§16–§18)

### **Cadence is left UNRESOLVED.** No interval was assigned.

This is the decision the evidence forces, and §18 prescribes it explicitly:

> *"If volatility remains unknown, leave the cadence explicitly unresolved
> rather than inventing one."*

**Why the daily rate is unknown.** G12 measured change over **114.6 minutes**,
9 comparisons, at a **15-minute** sampling interval. Extrapolating a sub-daily
rate to a daily one would assume a stationarity no measurement supports —
precisely the failure G11 §31 was written to prevent.

**What was measured, and what it does and does not license:**

| Measured | Value | Licenses an interval? |
|---|---|---|
| `this-week` change rate | **0.42 %/interval** at 15 min | **No** — bounds churn *at that interval only* |
| `this-week` new events | 6 over 9 comparisons, **all in G11's first 5** | **No** — G12's 4 rounds added 0 |
| `this-week` request cost | 6 per observation | Yes — for **cost**, not timing |
| Daily change rate | **not measured** | — |
| Events per day | **not measured** | — |

**Deliberately rejected shortcuts**, recorded so they are not re-proposed:

- *"0.42 %/interval × 96/day ⇒ ~40 %/day ⇒ run hourly"* — invents stationarity
  from a 1.9-hour sample.
- *"It changed, so run it often"* — capability, not evidence (§16).
- *"Run it on a 5-minute cron"* — the historical schedule G11's protocol
  deliberately slowed **below**, because at 5 minutes a probe measures
  Eventbrite's rate limiter rather than the listing.

### 4.1 The operating model that *is* justified today

Cadence being unresolved does **not** leave the system without an operating
model. The supported model is the one G9/G10 built and G14 verified:

- **Manual, operator-driven, on demand.** No cron, no scheduler, no auto-retry.
  This is the existing contract (`class-import-cli.php`: *"Import execution is
  local-only and on-demand. There is no cron scheduling"*).
- **Bounded progressive discovery** for the all-events listing: a checkpointed
  walk that resumes instead of restarting (G10), verified live in G14.
- **Bounded complete discovery** for `this-week`: a small, fully-enumerable
  window runnable on demand in ~6 requests.

**To finalise cadence:** continue `./tests/experiments/g11-run-round.sh <label>`
at the 15-minute protocol interval until a multi-day series exists, then derive
a per-source interval. **No code change is needed for that.**

### 4.2 All-events treatment (§17)

Unchanged and explicitly **not** scheduled. The objective remains:

> bounded progressive discovery + periodic complete reconciliation where
> possible

A large all-events crawl is **not** something that must complete on a frequent
run — G8/G12 measured it structurally truncated at 49 × 20 = 980 against ~1,534
declared objects, so "complete" is not reachable through this listing at all.

## §5 — Cooldown review (§19)

**No change.** The 20 s → 40 s → 60 s ladder, source-scoped cooldown,
zero-request skip, truthful incomplete state and no automatic retry loop are all
**preserved**.

G12 produced **0 × 429 and 0 × WAF**, so there is **no evidence of a defect** —
and equally **no evidence justifying a change**. Budgets were not raised to
compensate for anything. G14 supplied the missing live confirmation that the
cooldown's truthfulness still holds end to end.

## §6 — Request-budget review (§20)

**No change.** The Eventbrite request ceiling was **not** raised.

§20 permits an increase only when legitimate imports are being cut off. The
measured situation is the opposite: G14's controlled runs stopped on
`time_budget`, not on an artificial ceiling, and G12 spent 200 requests with
**zero** rejections.

## §7 — Caching review (§21)

**No caching added.** G4 found no measurable live cache-hit improvement, and
G12/G14 produced no new evidence of benefit. Checkpoint state remains **progress
state only** — "where were we", never "what did we see".

## §8 — Operator workflow (§22)

The operator distinguishes run states through the **existing** Import History.
No parallel dashboard was created.

| State | How Import History shows it |
|---|---|
| **COMPLETE** | `Listing = complete`, no fatal errors |
| **PARTIAL** | `Listing = partial`, `Progress = page N of M — resumable` |
| **RATE LIMITED** | `Progress` shows `rate_limited`; cooldown shows why and when |
| **WAF BLOCKED** | `Progress` shows `waf`; cooldown `last_reason = waf` |
| **FAILED** | `status = failed` with `fatal_errors` |
| **COOLING DOWN** | `Cooldown = cooling down, ~Ns, next eligible <time>` |

Also already surfaced: current checkpoint, last complete run, last failure,
recovery eligibility, and whether missing-event reconciliation occurred (only
when `Listing = complete` **and** the run was not a resumed subset).

## §9 — Production / deployment / mobile

| | |
|---|---|
| Production files changed | **0** |
| Production writes | **0** |
| Deployments | **0** |
| Eventbrite API integration | **not reintroduced** — G7 retirement intact |
| Cooldown / request budget / caching | **unchanged** |
| `OVERLAP_PAGES` | **1 — unchanged** |
| **Flutter/mobile repository** | **untouched — never accessed, inspected, built, tested or referenced** |

## §10 — What G13 hands to G14

- **Activate exactly one source:** `eventbrite_cork_events_this_week`.
- **Do not activate** `events_today` or `events_tomorrow`.
- **Cadence: unresolved** — G14 must run the importer **on demand**, not on a
  schedule.
- **Verify** checkpoint/resume, completeness, reconciliation safety,
  deduplication and Import History truthfulness on live data.