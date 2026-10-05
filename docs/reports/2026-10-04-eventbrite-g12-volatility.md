# G12 — Eventbrite volatility completion

| | |
|---|---|
| **Stage** | G12 — complete the multi-day volatility measurement |
| **Date** | 2026-10-04 → 2026-10-05 |
| **Branch** | `master` |
| **Start SHA** | `30b2b3a` (G11, clean tree) |
| **Predecessor** | [G11](2026-10-04-eventbrite-g11-volatility.md) · [G10](2026-10-04-eventbrite-g10-checkpoint.md) |
| **Protocol** | [`evidence/eventbrite-g12/00-protocol.md`](evidence/eventbrite-g12/00-protocol.md) |
| **Evidence** | [`docs/reports/evidence/eventbrite-g12/`](evidence/eventbrite-g12/) |
| **Production files changed** | **0** |
| **Production writes** | **0** |
| **Deployments** | **0** |
| **Commits** | **0** |

---

## Status

# `PASS WITH LIMITATION`

G12 genuinely re-ran the G11 measurement harness live and extended the
observation window from **19 minutes to 114.6 minutes**, raising the complete
comparison count on the window sources from **5 to 9 per source**. It answers
three of its four questions with direct evidence:

- **`OVERLAP_PAGES = 1` remains SUFFICIENT** — 9 complete comparisons, maximum
  page displacement still exactly **1 page**.
- **`events-this-week` has real, valid churn** — 6 valid new/removed events
  across 9 complete comparisons.
- **The all-events listing is structurally truncated, live, again** — 49 pages
  × 20 = 980 reachable against ~1,534 declared objects.

**The limitation is duration, and it is not papered over.** The brief asks for
approximately **seven days**. The honest observation window is **114.6
minutes**. A single agent session cannot occupy seven days, so:

- the **daily change rate** — the input §12 needs for a cadence decision —
  is **still `UNKNOWN`**, exactly as G11 left it, and is **not** extrapolated
  from sub-hourly samples;
- §13's *events-per-day* framing is likewise **not** computed.

Calling this `PASS` would require pretending a 1.9-hour window is a 7-day one.
It is not. **No day-2..day-7 data exists anywhere in this report.**

---

## §1 — Observation period

| | |
|---|---|
| Start | `2026-10-04T20:03:08+00:00` |
| End | `2026-10-04T21:57:44+00:00` |
| **Duration** | **114.6 minutes (~1.9 hours)** |
| Observations | **40** (10 per source) |
| Rounds | 10 (`r01`–`r06` inherited from G11; `g12r01`–`g12r04` collected in G12) |
| Complete observations | **30 / 40** (all window sources); **0 / 10** (all-events) |
| Requests | **200** |
| HTTP 429 | **0** |
| WAF / 405 | **0** |
| Elapsed (summed) | 327 s |

**The dataset is pooled.** G12 deliberately did **not** restart the series: the
G11 protocol and harness are unchanged, the rounds are contiguous in time, and
the deterministic analysis sorts by timestamp. `r01`–`r06` and `g12r01`–`g12r04`
therefore form one series of 10 observations per source.

**Requested vs actual.** ~7 days requested; 114.6 minutes achieved. The gap was
**not faked**. The harness is preserved and re-runnable (§11).

## §2 — Sources

Unchanged from G11, all resolved from the live registry — none hand-written:

| Source | Registry URL | Ship status | Obs |
|---|---|---|---:|
| `eventbrite_cork` | `…/d/ireland--cork/all-events/` | active | 10 |
| `eventbrite_cork_events_this_week` | `…/d/ireland--cork/events-this-week/` | was **inactive** | 10 |
| `eventbrite_cork_events_today` | `…/d/ireland--cork/events-today/` | **inactive** | 10 |
| `eventbrite_cork_events_tomorrow` | `…/d/ireland--cork/events-tomorrow/` | **inactive** | 10 |

Cork remains the only county (§5). Dublin was not observed: it would roughly
double external request pressure without informing the boundary question.

**Window sources stayed `inactive` throughout G12.** G12 is measurement only; no
source was activated, imported or enabled here. That is G13/G14's decision.

## §3 — Protocol conformance

Every G11 parameter was **inherited unchanged** — this is what makes the pooled
dataset valid:

| Parameter | Value | Changed? |
|---|---|---|
| Interval between observations | 15 min | **no** |
| Pages per observation | 8 (all-events) / 6 (windows) | **no** |
| Inter-request delay | 2 s (`Conexao_Eventbrite_Pacing`) | **no** |
| Rate-limit ladder | 20 s → 40 s, 60 s cap | **no** |
| Retries per page | 3 | **no** |
| Completeness rule | removals only when both sides complete | **no** |
| Identity | native Eventbrite event ID | **no** |

The **only** addition is `tests/experiments/g12-source-value.php`, which §13
needs and the per-source analysis does not compute. It is a read-only reduction
over observations already captured, built on the same
`tests/lib/eb-volatility.php` library — not a second measurement architecture
(scope rule §0.3).

## §4 — Valid-removal rule (§9)

Unchanged, and **demonstrably live**:

- `eventbrite_cork`: **0 removals reported across all 9 comparisons**, because
  all 10 of its observations are `upstream_truncated` → incomplete. Its change
  rate is `null`, not a misleading 0 %.
- Window sources: removals are computed **only** where both sides are complete,
  and every window observation is complete, so their removals are valid.

A rate limit or WAF response is recorded as a `failure_class` and an incomplete
flag, and **never** enters the removed set. This window had **zero** 429s and
**zero** WAF responses, so nothing had to be excluded on those grounds — but the
separation is structural, not incidental.

## §5 — Source-by-source volatility (§12)

| Source | Obs | Complete | Volatility | Evidence |
|---|---:|---:|---|---|
| `eventbrite_cork` | 10 | 0 | **UNKNOWN** | structurally truncated; no complete comparison exists |
| `events_this_week` | 10 | 10 | **LOW** | 6 valid new, 6 valid removed over 9 comparisons |
| `events_today` | 10 | 10 | **HIGH** (calendar-driven) | drained 8 → 4 → 0 events as the day ended |
| `events_tomorrow` | 10 | 10 | **LOW** | 23 events, **zero** change in all 9 comparisons |

### 5.1 `events-this-week` — the only source with real, valid churn

| Pair | Present | New | Removed | Moved | Complete |
|---|---:|---:|---:|---:|---|
| r01→r02 | 119 | 1 | 1 | 4 | yes |
| r02→r03 | 119 | 0 | 0 | 0 | yes |
| r03→r04 | 119 | 1 | 1 | 5 | yes |
| r04→r05 | 119 | 0 | 0 | 0 | yes |
| r05→r06 | 119 | 2 | 2 | 5 | yes |
| **g12r01** | 119 | **1** | **1** | 5 | yes |
| g12r02 | 119 | 0 | 0 | 0 | yes |
| g12r03 | 119 | 0 | 0 | 0 | yes |
| g12r04 | 119 | 0 | 0 | 0 | yes |

Change rate per interval: 0.84 %, 0 %, 0.84 %, 0 %, 1.68 %, 0.84 %, 0 %, 0 %,
0 % → mean **0.42 %** → **LOW**.

**Recorded honestly:** G12's four rounds added **no new events** to this source,
while G11's first five rounds added 6. The two halves of the pooled series
differ. That is a real observation — but a single quiet stretch is also **not**
evidence of a daily rate, and it is exactly the extrapolation §12 forbids.

### 5.2 `events-today` — calendar volatility, and it drained

`events-today` fell **8 → 4 → 4 → 0 → 0 → 0 → 0 → 0 → 0**. The listing emptied
as the Irish day ended (observations run to 21:57 UTC). This is the §6
"calendar semantics" case in its purest form: the volatility measures **the
clock**, not Eventbrite's listing behaviour. It is excluded from ordinary
cadence inference, exactly as the brief requires.

### 5.3 `events-tomorrow` — stable, but static

**Zero** new, zero removed, zero page movement across all 9 comparisons. Its
`LOW` classification is real but rests on a listing that did not change inside
a ~2-hour window — weak evidence about a *daily* rate.

### 5.4 `eventbrite_cork` — permanently incomplete

All 10 observations: 49 declared pages, ~1,534 declared objects, 160 events
walked, `upstream_truncated`, `complete = false`. G8's structural truncation
fact, reproduced live a third time.

## §6 — Page movement and boundary crossing

- **Maximum observed page displacement: 1 page**, unchanged from G11.
- Movement is again the uniform one-page upstream shift that is the signature of
  a single insertion at the top of the listing.
- **Every displacement occurred on the all-events and `this-week` listings.**
  `events-today` and `events-tomorrow` showed **zero** movement.
- Duplicate event IDs within any single observation: **0**.

Full per-boundary detail: [`02-analysis.txt`](evidence/eventbrite-g12/02-analysis.txt)
(INTERIOR BOUNDARY SWEEP section).

## §7 — Overlap re-evaluation (§11)

### Verdict: **SUFFICIENT**

| | |
|---|---|
| Complete comparisons used | **9** (G11: 5) |
| Max page displacement | **1 page** |
| Current `OVERLAP_PAGES` | **1 — unchanged** |
| Structural capacity | 20 slots per page (measured page size 20) against 1 observed |

`OVERLAP_PAGES` was **not changed**. The brief forbids changing it to fit the
experiment, and after 9 further complete comparisons — nearly double G11's — the
measurement still sits exactly inside it. G10's structural justification and
G11's empirical justification both survive G12 unchanged.

**Not reclassified for the other sources.** `events-today` and
`events-tomorrow` return `EXCESSIVE` (zero movement across complete
comparisons), but that verdict describes *their* listings, not the all-events
checkpoint-resume path the constant exists to protect. Changing a global
constant because two small windows happened not to move would be exactly the
kind of evidence-free change §11 forbids.

## §8 — What this window cannot establish

Stated plainly so no later reader over-reads the dataset:

1. **No daily change rate.** The window is 1.9 hours. §12's *changes per day*
   and §13's *events per day* are **not computable** and were **not
   extrapolated**.
2. **Rate-limit separation is untested in this window.** 200 requests produced
   **0 × 429** and **0 × WAF**, because the disciplined 6–8 page budget stays
   far below Eventbrite's measured ~31-request wall. The separation logic is
   proven by fixture tests (G11 mutations M6/M7), not by a live 429 here.
3. **All-events volatility remains `UNKNOWN`** — 0 complete comparisons exist
   for it, and raising its page budget to manufacture completeness would defeat
   the very truncation guard this programme exists to keep.
4. **`this-week` is a rolling window.** Its content changes by construction.

## §9 — Files added

| Path | Purpose |
|---|---|
| `tests/experiments/g12-source-value.php` | Read-only §13 source-value reduction over captured observations |
| `docs/reports/evidence/eventbrite-g12/` | Protocol + 40 observations + analyses + proofs |

No file under `wp-content/` was modified. `OVERLAP_PAGES`, cooldown, request
budgets, completeness, reconciliation safety and the G7 token retirement are all
**unchanged**.

## §10 — Production / deployment / mobile

| | |
|---|---|
| Production files changed | **0** |
| Production writes | **0** |
| Deployments | **0** |
| Commits | **0** |
| `OVERLAP_PAGES` | **1 — unchanged** |
| Window sources | still **`inactive`** at the end of G12 |
| Checkpoint / cooldown / completeness / reconciliation safety | **unchanged** |
| **Flutter/mobile repository** | **untouched — never accessed, inspected, built, tested or referenced** |

## §11 — Recommendation for G13

1. **Keep `OVERLAP_PAGES = 1`.** 9 further complete comparisons, max
   displacement still 1 page. No evidence justifies a change.
2. **`events-this-week` is the only window source with a live case for
   activation**: valid churn, 9/9 complete enumerations, 6 requests per
   observation, and G8's measured coverage gain.
3. **`events-today` must not be activated.** Its volatility is the calendar.
4. **`events-tomorrow` is stable but its value is small and largely subsumed**
   by `this-week` (see G13 §2).
5. **Cadence remains unresolved on daily-rate grounds.** The honest input G13
   receives is a *sub-daily* change rate only. G13 must either leave exact
   timing explicitly unresolved (§18) or justify an interval from sub-daily
   evidence **and say so**.
6. **To close the remaining gap**, continue the same harness unchanged:
   `./tests/experiments/g11-run-round.sh <label>` on the 15-minute protocol
   interval. No code change is needed to extend the observation; the harness is
   preserved precisely so the series can keep running.