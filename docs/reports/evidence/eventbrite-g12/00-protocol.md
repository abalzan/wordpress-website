# G12 — Eventbrite volatility observation protocol (continued)

> Written **before** the G12 rounds were collected, as §7 of the brief requires.
> It inherits the G11 protocol verbatim. **No parameter was changed**, so the G11
> and G12 datasets are directly comparable and can be pooled.

## 1. Objective

Complete the volatility measurement G11 could not finish: extend the observed
window from G11's 19 minutes to the longest legitimate window this execution
environment can sustain, and decide — from evidence — whether `OVERLAP_PAGES = 1`
still holds and what each window source's cadence should be.

## 2. What is inherited unchanged from G11

| Parameter | Value | Source |
|---|---|---|
| Interval between observations | 15 min | G11 protocol §4 |
| Pages per observation | 8 (all-events), 6 (windows) | G11 protocol §3 |
| Inter-request delay | 2 s | `Conexao_Eventbrite_Pacing` |
| Rate-limit ladder | 20 s → 40 s, 60 s cap | G5 |
| Retries per page | 3 | `Conexao_Eventbrite_Client::MAX_RETRIES` |
| Comparison rule | removals only when **both** sides complete | G11 §8 / §9 |
| Identity | native Eventbrite event ID | G11 §7 |

## 3. What G12 changes

**Nothing about how a measurement is taken.** G12 reuses:

- `tests/experiments/g11-eventbrite-volatility.php` (the observation harness),
- `tests/lib/eb-volatility.php` (identity, completeness, comparison, overlap),
- `tests/experiments/g11-analyse.php` (the deterministic analysis),
- `tests/experiments/g11-run-round.sh` (one round across the four sources).

G12 adds exactly one new artefact, `tests/experiments/g12-source-value.php`,
because §13 asks for a **cross-source** measure (incremental value against the
all-events listing) that the per-source analysis does not compute. It is a pure
read-only reduction over observations already captured, built on the same
library — not a second measurement architecture (§0.3).

## 4. Sources

Unchanged from G11, all resolved from the live registry:

| Source id | Class | URL | Ship status |
|---|---|---|---|
| `eventbrite_cork` | all-events | `…/d/ireland--cork/all-events/` | active |
| `eventbrite_cork_events_this_week` | date window | `…/d/ireland--cork/events-this-week/` | inactive |
| `eventbrite_cork_events_today` | date window | `…/d/ireland--cork/events-today/` | inactive |
| `eventbrite_cork_events_tomorrow` | date window | `…/d/ireland--cork/events-tomorrow/` | inactive |

Cork remains the single county (§5). Dublin is still not observed: adding it
would roughly double external request pressure without informing the boundary
question.

`events-today` is observed but **excluded from ordinary cadence inference**
(§6): its volatility is calendar-driven, so a cadence derived from it would be
measuring the clock, not the listing.

## 5. Safety

The harness snapshots and restores every shared importer option
(`Conexao_Import_History`, `Conexao_Import_Log`, `Conexao_Event_Sources`, source
health, `Conexao_Source_Cooldown`, `Conexao_Source_Enumeration_Checkpoint`) and
never calls a registered source's `fetch_events()`. It therefore cannot advance
a checkpoint, write a cooldown, reach `mark_missing_events()`, activate a source
or import anything. Every observation is a separate process.

## 6. The honest limit of this protocol (carried from G11 §11)

1. **A seven-day window cannot be observed inside one agent session.** G12
   extends the window to hours. It still measures **sub-daily** drift. Any
   *daily* change rate is therefore reported as `UNKNOWN`, never extrapolated.
2. Page-count-declared truncation is a standing property, not a change.
3. `this-week` is a rolling window; its content changes by construction.

## 7. Change log

| When | Change | Reason |
|---|---|---|
| Before collection | — | Inherited G11 protocol verbatim; no parameter changed. |