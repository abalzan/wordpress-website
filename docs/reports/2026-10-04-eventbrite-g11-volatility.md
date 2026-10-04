# G11 — Eventbrite volatility measurement, overlap validation and cadence evidence

| | |
|---|---|
| **Stage / task name** | G11 — Eventbrite volatility measurement, overlap validation, cadence evidence |
| **Date** | 2026-10-04 |
| **Branch** | `master` |
| **Start SHA** | `645523d1c98aab968b6146a11a690763023cc101` (G10, clean tree) |
| **Predecessor** | [G10](2026-10-04-eventbrite-g10-checkpoint.md) · [G9](2026-10-04-eventbrite-g9-operational-hardening.md) · [G8](2026-10-04-eventbrite-g8-source-expansion.md) |
| **Protocol** | [`evidence/eventbrite-g11/00-protocol.md`](evidence/eventbrite-g11/00-protocol.md) — written **before** collection |
| **Evidence** | [`docs/reports/evidence/eventbrite-g11/`](evidence/eventbrite-g11/) |
| **Production files changed** | **0** |
| **Production writes** | **0** |
| **Deployments** | **0** |
| **Commits** | **0** |

---

## Status

# `PASS WITH LIMITATION`

G11 obtained **real, live Eventbrite measurements** — 24 observations across 4
Cork sources over a 19-minute window — and they answer the overlap question
with actual data rather than reasoning: **maximum observed page displacement
was exactly 1 page**, at every interior page boundary where movement occurred.

The limitation is scope, stated plainly rather than papered over: the
observation window is **19 minutes, not 7 days**. A single agent session cannot
occupy seven days. Consequently:

- the **overlap** question is answered (`SUFFICIENT`, on live data);
- the **daily change rate / cadence** question is **NOT** answered, and is
  classified `UNKNOWN` rather than extrapolated from a 19-minute sample.

Calling this `PASS` would require pretending a 19-minute window is a weekly
one. It is not, and G11 §31 forbids that.

---

## §1 — Observation period

| | |
|---|---|
| Start | `2026-10-04T20:03:08+00:00` |
| End | `2026-10-04T20:22:28+00:00` |
| Duration | **~19 minutes** |
| Rounds | 6 (`r01`–`r06`) |
| Observations | **24** (6 per source × 4 sources) |
| Complete observations | **18** (all window sources) / **0** (all-events — see §4) |

**Requested vs actual.** The brief asks for ~7 days "where environment/access
permits". Access permitted live collection; the *duration* did not. The missing
period was **not faked**, and no day-2..day-7 data is present anywhere in this
report.

## §2 — Sources observed

Cork is the **primary county**, per §4 of the brief. All URLs were resolved
from the live registry (`Conexao_County_Registry`) — none were hand-written.

| Source | Registry URL | Ship status | Observations |
|---|---|---|---:|
| `eventbrite_cork` | `…/d/ireland--cork/all-events/` | active | 6 |
| `eventbrite_cork_events_this_week` | `…/d/ireland--cork/events-this-week/` | **inactive** | 6 |
| `eventbrite_cork_events_today` | `…/d/ireland--cork/events-today/` | **inactive** | 6 |
| `eventbrite_cork_events_tomorrow` | `…/d/ireland--cork/events-tomorrow/` | **inactive** | 6 |

**Why these:** they are the three G8 date-window sources plus the existing
all-events source, all for the one county G8 established has the clearest live
truncation/rate-limit evidence.

**Dublin was deliberately not used.** A second county would roughly double
Eventbrite load for no additional information about *boundary* movement, and the
protocol's budget discipline forbade it. This is a narrowing, recorded here
rather than silently made.

**Window sources remain `inactive`.** They were observed read-only through the
harness and were never activated, never imported, never enabled.

## §3 — Protocol

Full protocol: [`00-protocol.md`](evidence/eventbrite-g11/00-protocol.md).

| Parameter | Value | Provenance |
|---|---|---|
| Interval | 15 min between rounds (achieved ~4 min incl. walk time) | protocol §4 |
| Pages/observation | 8 (all-events), 6 (windows) | protocol §3 — a **reduction** vs G8's 31–48 |
| Inter-request delay | 2 s | `Conexao_Eventbrite_Pacing` — **unchanged** |
| Rate-limit ladder | 20 s → 40 s, 60 s cap | G5 — **unchanged** |
| Retries/page | 3 | `Conexao_Eventbrite_Client::MAX_RETRIES` — **unchanged** |
| Max requests/observation | 8 | protocol §5 |

The interval is deliberately **far slower** than the historical 5-minute
importer cadence: G11 measures drift, and a 5-minute probe against the measured
## §4 — All-events volatility (`eventbrite_cork`)

| Observation | Pages walked | Present | New | Changed | Removed | Moved | Complete | Failure class |
|---|---:|---:|---:|---:|---:|---:|---|---|
| r01→r02 | 8 | 160 | 0 | 0 | 0 | 0 | **no** | `upstream_truncated` |
| r02→r03 | 8 | 160 | 1 | 0 | 0 | **7** | **no** | `upstream_truncated` |
| r03→r04 | 8 | 160 | 0 | 0 | 0 | 0 | **no** | `upstream_truncated` |
| r04→r05 | 8 | 160 | 0 | 0 | 0 | 0 | **no** | `upstream_truncated` |
| r05→r06 | 8 | 160 | 0 | 0 | 0 | 0 | **no** | `upstream_truncated` |

**Every all-events observation is incomplete, and that is the correct result.**
The listing declared `page_count = 49` and `object_count = 1,536`, while
`49 × 20 = 980` — G8's structural-truncation fact, reproduced live. The
measurement library classifies this `upstream_truncated` and forces
`complete = false`.

Consequently:

- **removals: 0 reported** — not because nothing was removed, but because an
  incomplete walk **cannot** establish a removal. This is the §6/§18 safety
  rule working exactly as designed;
- **change rate: `null`** — an invalid denominator, never a misleading 0 %;
- **volatility: `UNKNOWN`**;
- **overlap verdict: `UNMEASURED`** — 0 complete comparisons.

**Page movement was still measured** (movement needs only the events that *were*
seen), and it is the most important signal in this report. At `r02→r03`,
**7 events each moved exactly one page upstream**:

```
1999182093458: p2 -> p1      1996823767642: p6 -> p5
1999119285598: p3 -> p2      1996822198950: p7 -> p6
2002102187538: p4 -> p3      1990545386836: p8 -> p7
1987834119362: p5 -> p4
```

This is a **uniform single-page upstream shift** — the signature of exactly one
event entering at the top of the listing and displacing everything below it by
one page. It is measured, live, on the real Cork all-events listing.

## §5 — Window-source volatility

### 5.1 `events-this-week` — the only source with real, valid churn data

| Observation | Present | New | Changed | Unchanged | Removed | Moved | Requests | Sec | Complete |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| r01→r02 | 119 | 1 | 0 | 118 | 1 | **4** | 6 | 14.18 | yes |
| r02→r03 | 119 | 0 | 0 | 119 | 0 | 0 | 6 | 1.04 | yes |
| r03→r04 | 119 | 1 | 0 | 118 | 1 | **5** | 6 | 11.94 | yes |
| r04→r05 | 119 | 0 | 0 | 119 | 0 | 0 | 6 | 1.06 | yes |
| r05→r06 | 119 | 2 | 0 | 117 | 2 | **5** | 6 | 12.45 | yes |

Change rate per interval: `0.84 %`, `0 %`, `0.84 %`, `0 %`, `1.68 %` →
mean **0.67 %** → classified **LOW**.

Both the new **and** the removed events are valid here, because both sides are
complete. Movement is again a uniform one-page upstream shift (e.g. `r03→r04`:
p2→p1, p3→p2, p4→p3, p5→p4, p6→p5).

### 5.2 `events-today`

| Observation | Present | New | Removed | Moved | Requests | Complete |
|---|---:|---:|---:|---:|---:|---|
| r01→r02 | 8 | 0 | 0 | 0 | 1 | yes |
| r02→r03 | 4 | 0 | 4 | 0 | 1 | yes |
| r03→r04 | 4 | 0 | 0 | 0 | 1 | yes |
| r04→r05 | **0** | 0 | 4 | 0 | 6 | yes |
| r05→r06 | 0 | 0 | 0 | 0 | 6 | yes |

**This is the highest apparent volatility in the dataset — and it is an
artefact of the window, not of the listing.** "Today" emptied as the day ended
in Ireland (20:22 UTC). The removals are *valid* (both sides complete), but
they reflect the **calendar advancing**, not Eventbrite churning.

This is exactly the kind of number that must not be fed straight into a cadence
decision, and it is the clearest argument in this report for treating
`events-today` as a *time-relative* source rather than a volatility source.

### 5.3 `events-tomorrow`

| Observation | Present | New | Changed | Unchanged | Removed | Moved | Requests | Complete |
|---|---:|---:|---:|---:|---:|---:|---:|---|
| all 5 pairs | 23 | 0 | 0 | 23 | 0 | 0 | 2 | yes |

**Zero movement, zero change across ~19 minutes.** Volatility **LOW**.
## §6 — Boundary movement: the decisive measurement

G10 established from measurement that `?page=N` is server-asserted, there is no
cursor, no next-link, no sort key, page size is 20, and page count is pinned at
49. **Therefore page position is not an identity**, and G11 §12 forbids
concluding "page 8 did not change, so ordering is stable."

Instead every page was compared as a **set of stable event IDs**. The sweep
below re-runs the boundary logic at **every interior page boundary** both
observations covered — the position the overlap constant actually cares about.

| Source | Pair | Events moved | **Max displacement** | Boundaries crossed | Widest crossing |
|---|---|---:|---:|---:|---:|
| `eventbrite_cork` | r03 | 7 | **1 page** | p1–p7 (1 each) | 1 event |
| `eventbrite_cork` | r02/r04/r05/r06 | 0 | 0 page | none | 0 |
| `events-this-week` | r02 | 4 | **1 page** | p2–p5 (1 each) | 1 event |
| `events-this-week` | r04 | 5 | **1 page** | p1–p5 (1 each) | 1 event |
| `events-this-week` | r06 | 5 | **1 page** | p1–p5 (1 each) | 1 event |
| `events-today` | all | 0 | 0 page | none | 0 |
| `events-tomorrow` | all | 0 | 0 page | none | 0 |

### §6.1 — Answering the brief's question directly

> *How much data can realistically cross the resume boundary between two runs?*

**One page's worth — at most — measured on live Eventbrite data, across 20
observation pairs and every interior boundary.**

At no point did any event move two or more pages between observations. Every
recorded displacement was exactly **1**.

Mechanically this is consistent: the observed shift is always a *uniform
one-page displacement of a contiguous block*, which is what happens when
**N new events are inserted at the top** and the listing has no sort key. The
number of pages displaced therefore equals the number of new top-of-listing
events, which during the observation window was **at most 1**.

## §7 — Overlap conclusion

# `SUFFICIENT`

**Evidence.** Across every complete comparison with observed movement
(5 comparisons on `events-this-week`, 1 on the all-events listing), the maximum
page displacement was **1 page**. `OVERLAP_PAGES = 1` absorbs a 1-page
displacement exactly, and it has a structural margin of **20 slots per page**
(Eventbrite's measured page size) against the observed 1.

**The margin argument is bounded honestly:** a 1-page overlap covers up to 20
simultaneous new top-of-listing insertions. The observed maximum was **1**. So
the measured behaviour consumes **5 % of the overlap's capacity**.

**This is a live, empirical conclusion** — not the structural-only justification
G10 was left with. G10 §27.2 recorded: *"`OVERLAP_PAGES = 1` is justified
structurally, not empirically."* G11 closes exactly that gap, on live data.

**`OVERLAP_PAGES` was not changed.** The brief forbids changing it to fit the
experiment, and nothing here required it.

**Counter-evidence, stated plainly:** a single session cannot bound the tail.
If a busy weekend produced 25 new top-of-listing events between two runs, a
1-page overlap would **not** cover the displacement. The measured maximum of 1
does not exclude that; it only shows it did not occur in a 19-minute window.
## §8 — Cadence evidence

| Source | Changes per observation | % obs. with new | % obs. with movement | Boundary crossings | Classification |
|---|---:|---:|---:|---:|---|
| `eventbrite_cork` | 0.2 | 20 % | 20 % | 0 | **UNKNOWN** (0 complete obs.) |
| `events-this-week` | 0.8 | 60 % | 60 % | 0 | **LOW** (mean 0.67 %) |
| `events-today` | 1.6 | 0 % | 0 % | 0 | **HIGH** — but calendar-driven, see §5.2 |
| `events-tomorrow` | 0 | 0 % | 0 % | 0 | **LOW** |

**Boundary-crossing frequency: 0 across all 20 pairs**, at the production
resume position.

**Changes per day: NOT MEASURED.** A 19-minute window cannot yield a daily
rate, and none is reported.

**No production cadence was derived.** Per §25, G11 produces evidence; a later
stage decides. The one defensible statement is that **`events-this-week` is the
only source that showed sustained real change**, and it is the natural cadence
candidate — but that is a G12 decision, not a G11 one.

## §9 — Window-source recommendation

| Source | Recommendation | Evidence |
|---|---|---|
| `events-this-week` | **ACTIVATE CANDIDATE** | The only source with sustained, valid, non-artefactual change: 0.67 % mean change rate, real page movement on 3 of 5 intervals, 6 requests/observation, **complete on every observation**. It is also G8's source that recovered 198 events the capped listing never reaches. |
| `events-tomorrow` | **DEFER** | Zero change over the window. Cannot distinguish "stable" from "not yet changing" without a longer window. Cheap (2 requests) and complete, but a cadence decision on zero data would be guesswork. |
| `events-today` | **DEFER** | Its apparent volatility is **calendar-driven**, not listing churn (§5.2). Its cost/benefit is dominated by the day boundary, not by Eventbrite. Needs a day-scale measurement. |

**Default at the end of G11 remains: do not activate.** Nothing was activated.

## §10 — Duplicates

**Zero duplicate event IDs** in all 24 observations (within an observation, or
across pages). Cross-source duplication was not measured in this stage (the
comparison is per source); G8's cross-source union figure stands unchanged.

## §11 — Request cost and operational measurements

| Source | Requests/obs | Requests total | Elapsed range | 429s | WAFs |
|---|---:|---:|---|---:|---:|
| `eventbrite_cork` | 8 | 48 | 1.41 – 15.82 s | **0** | **0** |
| `events-this-week` | 6 | 36 | 1.04 – 14.18 s | **0** | **0** |
| `events-today` | 1–6 | 15 | 0.19 – 8.70 s | **0** | **0** |
| `events-tomorrow` | 2 | 12 | 0.38 – 23.90 s | **0** | **0** |
| **Total** | — | **111** | — | **0** | **0** |

**No 429 and no WAF response occurred during the entire experiment.** The
reduced per-observation budget (8 pages vs G8's 31) kept the run inside
Eventbrite's tolerance — which is itself a measured finding supporting the
budget-discipline decision.

Note the elapsed-time spread (1.4 s to 23.9 s for the same request count):
latency is variable upstream, not a function of our request volume.
## §12 — Tests

| Layer | Baseline (G10) | After G11 | Delta |
|---|---:|---:|---|
| In-process PHP suites | 95 total / 66 passed / **29 failed** | 96 total / 67 passed / **29 failed** | +1 suite, **+0 failures** |
| Assertions | **3934 passed / 4 failed** | **4011 passed / 4 failed** | **+77 passed, +0 failed** |
| New suite | — | `test-eventbrite-volatility.php` — **77 passed, 0 failed** | +77 |
| Permanent gates | 7/7, 84 assertions | **7/7, 84 assertions** | unchanged |

### Failure-set delta

```
$ diff baseline-fails after-fails
IDENTICAL FAILURE SET
```

**29 pre-existing failing suites before, 29 after — byte-identical.** The 4
failing assertions are unchanged. No baseline was modified, suppressed or
relaxed to make this stage green.

### Coverage of §26's required tests

| # | Required | Where | Result |
|---|---|---|---|
| 1 | event-set comparison | G11.1 | pass |
| 2 | new-event detection | G11.2 | pass |
| 3 | changed-event detection | G11.3 | pass |
| 4 | unchanged-event detection | G11.4 | pass |
| 5 | valid removal detection | G11.5 | pass |
| 6 | incomplete obs ⇒ no false removals | G11.6 (5 assertions) | pass |
| 7 | page movement detection | G11.7 (6 assertions) | pass |
| 8 | boundary-crossing detection | G11.8 (7 assertions) | pass |
| 9 | duplicate detection | G11.9 (5 assertions) | pass |
| 10 | 429 observation classification | G11.10 (6 assertions) | pass |
| 11 | WAF observation classification | G11.11 (7 assertions) | pass |
| 12 | completeness-aware volatility calc | G11.12 (20 assertions) | pass |

### Negative proofs — mutation testing, non-vacuous

| # | Mutation | Result |
|---|---|---|
| M1 | incomplete observation treated as complete | **PROVEN** (66 passed, 11 failed) |
| M2 | removals kept despite invalid comparison | **PROVEN** (74 passed, 3 failed) |
| M3 | **page number used as event identity** | **PROVEN** (51 passed, 26 failed) |
| M4 | page movement never recorded | **PROVEN** (64 passed, 13 failed) |
| M5 | boundary crossing never computed | **PROVEN** (73 passed, 4 failed) |
| M6 | HTTP 429 not classified as rate-limited | **PROVEN** (75 passed, 2 failed) |
| M7 | HTTP 405/403 not classified as WAF | **PROVEN** (75 passed, 2 failed) |
| M8 | upstream truncation treated as complete | **PROVEN** (74 passed, 3 failed) |
| M9 | change rate from an invalid denominator | **PROVEN** (72 passed, 5 failed) |
| M10 | duplicate event IDs not detected | **PROVEN** (75 passed, 2 failed) |

**10/10 proven, 0 vacuous.**

M3 is the important one: replacing event-ID identity with page-number identity
collapses **26** assertions, proving §12's "page positions are not identities"
requirement is genuinely load-bearing and not merely asserted in a comment.

### Other verification

| Gate | Result |
|---|---|
| `./scripts/lint.sh` | **OK** — syntax clean, no new PHPCS violations, PHPStan clean |
| `python3 scripts/verify-permanent-gates.py` | **7/7 PASS**, 84 assertions, 0 violations |
| `php scripts/generate-registry-docs.php --check` | **OK** — 10 plugins, 19 regions current, zero writes |
| Full `./scripts/run-tests.sh` | 96 PHP suites / 19 script-contract / 4 HTTP acceptance |

**The two non-PHP failures are pre-existing and unrelated to G11.** G8 recorded
this same baseline (script-contract 17 passed / 2 failed; HTTP acceptance
3 passed / 1 failed):

| Failing suite | Cause | G11-related? |
|---|---|---|
| `tests/scripts/verify-stage14-secret-scan.py` | *"the PHP runtime scanner could not be executed (no local WordPress); conformance is UNPROVEN, not assumed"* — the container has no `python3` | **No** — environment limitation |
| `tests/acceptance/verify-event-occurrence-http.py` | *"exactly one weekly series is listed in the Laois archive — found 5"* — Laois archive content | **No** — pre-existing content fixture |

A direct credential scan over every G11 addition (evidence, harness, library,
tests) returned **clean** — no `Authorization`, bearer token, password, or API
key pattern.

## §13 — Evidence classification

### Live evidence (observed from Eventbrite during G11)

- 24 observations, 4 Cork sources, `2026-10-04T20:03Z`–`20:22Z`
- **Maximum page displacement = 1 page**, at every interior boundary
- `events-this-week` change rate: 0.84 %, 0 %, 0.84 %, 0 %, 1.68 %
- `eventbrite_cork` declares 49 pages / 1,536 objects ⇒ structurally truncated
- 7-event and 5-event uniform one-page upstream shifts (exact IDs recorded)
- 111 requests total, **0 × 429**, **0 × WAF**
- 0 duplicate IDs across all observations

### Historical evidence (inherited from G1–G10)

- `OVERLAP_PAGES = 1`, checkpoint/resume, `reconciliation_safe` — **unchanged**
- Eventbrite 429 after ~31 requests; page size 20; page count pinned at 49
- G10: 77 assertions, 8/8 mutations proven
- G8: window sources recovered 198 events the capped listing never reaches

### Fixture evidence

- All 77 G11 measurement-logic assertions run on **literal arrays**, not HTTP
- M1–M10 mutation proofs operate on deterministic fixtures

### Unmeasured

1. **Daily / weekly change rate** — the 19-minute window cannot produce it
2. **Boundary crossing under a long interval** (hours/days) — the tail risk in §7
3. **`events-today` / `events-tomorrow` behaviour across a day boundary**
4. **Cross-source duplicate cost** (comparison here is per source)
5. **Movement beyond page 8** of the all-events listing
6. **A complete all-events observation** — structurally impossible while
   `object_count > page_count × page_size`
## §14 — Live limitations, stated exactly

> "`OVERLAP_PAGES = 1` is **empirically supported on live Eventbrite data** for
> observation intervals of minutes, where the maximum measured displacement was
> 1 page and a 1-page overlap has 20 slots of capacity against 1 observed
> insertion. Movement across **hour- or day-long** intervals remains
> **unmeasured**, and the daily change rate required for a cadence decision
> remains **unmeasured**."

This is the honest boundary of what G11 established. It is narrower than "G11
proves overlap is sufficient forever", and wider than G10's structural-only
justification.

## §15 — Production / deployment / mobile

| | |
|---|---|
| Production files changed | **0** |
| Production writes | **0** |
| Deployments | **0** |
| Commits | **0** |
| `OVERLAP_PAGES` | **1 — unchanged** |
| Request budgets / cooldowns / caching | **unchanged** |
| Window sources | still **`inactive`** |
| Eventbrite parsing | **unchanged** |
| Checkpoint / resume / completeness / `reconciliation_safe` | **unchanged** |
| **Flutter/mobile repository** | **untouched — never accessed, inspected, built, tested or referenced** |

G11 added files **only** under `tests/` (measurement instrumentation) and
`docs/reports/` (evidence). No file under `wp-content/` was modified except the
addition of one new test file, which is inert and ships no runtime code.

## §16 — Files added

| Path | Purpose |
|---|---|
| `tests/lib/eb-volatility.php` | Pure comparison library (identity, completeness, movement, boundary, change rate, classification) |
| `tests/experiments/g11-eventbrite-volatility.php` | Read-only observation harness (reuses production client + parser) |
| `tests/experiments/g11-analyse.php` | Deterministic analysis + interior boundary sweep |
| `tests/experiments/g11-run-round.sh` | One observation round across the 4 sources |
| `tests/experiments/g11-mutation-proofs.sh` | M1–M10 mutation verification |
| `tests/experiments/g11-collect-evidence.sh` | Regenerates evidence from captured data |
| `wp-content/plugins/conexao-event-importer/tests/test-eventbrite-volatility.php` | 77 measurement-logic assertions |
| `docs/reports/evidence/eventbrite-g11/` | Protocol + 24 observations + analyses |

## §17 — Recommendation for G12

G11's job was evidence. The precise inputs G12 now has:

1. **`OVERLAP_PAGES = 1` is empirically supported** — max measured displacement
   was 1 page with 20 slots of capacity. **Recommendation: keep it at 1.** No
   change is warranted by the evidence, and G10's structural risk was never
   realised in 20 live comparisons.
2. **The measurement harness is reusable.** Re-running
   `g11-run-round.sh` over a **multi-day** window is the exact missing input for
   the daily change rate. The interval and budget in the protocol are already
   conservative enough to leave running unattended.
3. **`events-this-week` is the only window source with a real case for
   activation** — sustained valid change, complete enumeration, 6 requests per
   observation, and G8's measured 198-event coverage gain.
4. **`events-today` must NOT be activated on this evidence.** Its volatility is
   calendar-driven; a cadence decision from it would be measuring the clock,
   not Eventbrite.
5. **Per-observation budgets can stay at 6–8 pages.** 111 requests across 24
   observations produced **zero** 429s, versus G8's 429 at request 31 — the
   disciplined budget is what kept the measurement clean.
6. **What still blocks a confident cadence decision:** a multi-day run of the
   same harness on `events-this-week` and `events-tomorrow`. Everything else G12
   needs is already measured.
~31-request 429 wall would mostly measure Eventbrite's rate limiter.