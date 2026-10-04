# G11 — Eventbrite volatility measurement protocol

> Written **before** any observation was collected, as G11 §6 requires. Any
> change to this protocol mid-experiment is recorded in
> [§9 Change log](#9-change-log).

## 1. Objective

Measure how volatile the Eventbrite county listing is around the checkpoint
boundary, so a later stage can decide — from evidence rather than intuition —
whether `OVERLAP_PAGES = 1` is the right value and what cadence a source
deserves.

**G11 changes no production constant.** This document defines what is
*measured*, not what is *changed*.

## 2. County and sources

**Primary county: Cork.** Chosen because G8 already produced the clearest live
evidence of truncation and rate limiting on Cork, because Cork has a
manageable-enough listing for repeated controlled observation, and because it
already has both an all-events source and the G8 date-window sources.

Sources measured — all resolved from the **real registry**
(`Conexao_County_Registry`), never hand-written URLs:

| Source id | Class | Registry URL | Ship status |
|---|---|---|---|
| `eventbrite_cork` | all-events | `https://www.eventbrite.ie/d/ireland--cork/all-events/` | active |
| `eventbrite_cork_events_this_week` | date window | `https://www.eventbrite.ie/d/ireland--cork/events-this-week/` | **inactive** |
| `eventbrite_cork_events_today` | date window | `https://www.eventbrite.ie/d/ireland--cork/events-today/` | **inactive** |
| `eventbrite_cork_events_tomorrow` | date window | `https://www.eventbrite.ie/d/ireland--cork/events-tomorrow/` | **inactive** |

The window sources ship `inactive` in the registry and **stay inactive**.
G11 observes them through the read-only harness only. It does not activate
them, and it does not import from them.

Dublin is **not** used as a secondary source. Adding a second county would
roughly double Eventbrite load for no additional information about *boundary*
movement, and G11's budget discipline (§7) forbids it. This is a deliberate
narrowing, recorded here rather than silently made.

## 3. Page budget per observation

| Source | Pages per observation | Rationale |
|---|---:|---|
| all-events | **8** | Enough pages to sit either side of a realistic checkpoint boundary (G8/G10 stopped at ~31). |
| date windows | **6** | `events-this-week` declares 16 pages; 6 pages × 20 = 120 events is far more than a boundary needs. |

This is a **reduction** against G8's walk, not an increase. It exists so a
single observation costs 6–8 requests instead of 31–48, which is what makes a
repeated cadence affordable without hammering Eventbrite.

## 4. Experimental interval

**Fixed interval: 15 minutes between observations of the same source.**

Justification, from measured evidence rather than preference:

- G8/G10 measured Eventbrite answering **429 after ~31 requests**. A 6–8 page
  observation costs ~20–25 % of that allowance, so a 15-minute gap gives the
  limiter an order of magnitude more recovery time than the walk itself
  consumes.
- G5's measured policy already paces requests at **2 s apart** with a
  **20 s → 40 s** rate-limit ladder and a **60 s** hard cap. The harness
  reuses `Conexao_Eventbrite_Pacing` unchanged, so these protections apply
  inside every observation.
- 15 minutes is deliberately **far slower** than the historical 5-minute
  importer cadence. G11 does not reproduce the old aggressive schedule: the
  point is to measure drift, and a 5-minute probe against a ~31-request wall
  would mostly measure Eventbrite's rate limiter rather than the listing.

## 5. Safety limits (unchanged from G9/G10)

| Limit | Value | Source |
|---|---|---|
| Inter-request delay | 2 s | `Conexao_Eventbrite_Pacing::PAGE_DELAY_SECONDS` |
| Rate-limit backoff | 20 s → 40 s, 60 s cap | G5 ladder |
| Retries per page | 3 | `Conexao_Eventbrite_Client::MAX_RETRIES` |
| Per-observation page ceiling | 8 / 6 | §3 above |
| Total requests per observation | ≤ 8 | §3 above |

G11 **does not** raise any budget, shorten any cooldown, add parallelism, add
caching, or retry around a 429 or a WAF response.

## 6. Failure handling

| Condition | Classification | Action |
|---|---|---|
| HTTP 429 | `rate_limited` | Stop that observation immediately. Record status, page, request count, elapsed. No retry beyond the production client's own bounded ladder. |
| HTTP 405 / 403 | `waf` | Stop that observation. Record. Do not bypass, proxy, or alter headers. |
| Timeout / transport error | `timeout` / `network` | Stop that observation. Record. |
| Request/time budget exhausted | `budget_exhausted` | Stop that observation. Record. |
| Parse failure | `parse_failed` | Stop that observation. Record. |
| Upstream declares more objects than its pagination can expose | `upstream_truncated` | Walk to the declared end, then mark **incomplete**. (G8's guard.) |

Any of these makes the observation **incomplete**, and an incomplete
observation can never produce removals or a change rate.

## 7. Identity

Primary identity is the **native Eventbrite event ID**, the same value the
importer stores in `_event_source_id`. Never a title, never a page number,
never an array position, never a URL.

The harness stores `event_id`, `page`, `position` and a `field_hash` over
title / start / end / URL / image / summary / published / cancelled / venue. It
stores **no response body, no description text, no ticket URL and no
credential**.

## 8. Comparison rule

Two consecutive observations of the same source are compared. The comparison
refuses to report a removal unless **both** sides are complete.

## 9. Change log

| When | Change | Reason |
|---|---|---|
| Before collection | — | Initial protocol above. |

*(Any mid-experiment change is added here with its reason.)*

## 10. Isolation

The harness snapshots and restores every shared importer option before and
after each observation — `Conexao_Import_History`, `Conexao_Import_Log`,
`Conexao_Event_Sources`, source health, `Conexao_Source_Cooldown` and
`Conexao_Source_Enumeration_Checkpoint`. It never calls a registered source's
`fetch_events()`, so it cannot advance a checkpoint, write a cooldown, or reach
`mark_missing_events()`.

Each observation is a separate process, so no observation can inherit another's
in-memory state.

## 11. What this protocol cannot establish

Stated up front so no later reader over-reads the dataset:

1. **A seven-day window cannot be observed inside a single agent session.**
   Observations are minutes apart, not days apart. This dataset therefore
   measures **short-horizon drift** (minutes), and the report must classify
   any *daily* change rate as unmeasured rather than extrapolating it.
2. **Page-count-declared truncation is a standing property, not a change.** It
   is recorded per observation but is not a volatility signal.
3. **`this-week` is a rolling window.** Its content changes as events pass and
   as new events appear, by construction. That is real volatility, but it is
   not directly comparable to the all-events listing.