# Report — G9: Eventbrite ingestion operational hardening

| | |
|---|---|
| **Stage / task name** | G9 — source cadence, cooldown/recovery, truthful operator state |
| **Date** | 2026-10-04 |
| **Branch** | `master` |
| **Start SHA** | `9f23285f2bd86f461e167528c05180b056a1d44d` (G7 baseline, clean tree) |
| **End SHA** | *uncommitted — not deployed, not committed* |
| **Predecessor** | [G8](2026-10-04-eventbrite-g8-source-expansion.md) · [G7](2026-10-04-eventbrite-api-retirement.md) |
| **Evidence** | [`docs/evidence/2026-10-04-eventbrite-g9-operational-hardening/`](../evidence/2026-10-04-eventbrite-g9-operational-hardening/) |
| **Production files changed** | **0** |
| **Production writes** | **0** |
| **Deployments** | **0** |
| **Commits** | **0** |

---

## Status

# `PASS WITH LIMITATION`

Every change is measurement-driven and mutation-verified. The limitation is stated
honestly in [§ Remaining limitations](#remaining-limitations): the long-run stability
experiment ran against the local synthetic site and a measured slice of the live
upstream, **not** against production, and production must never be written from here.

---

## The measurement that drove this stage

The full measured Cork walk (recorded in the G8 report) terminated at:

```
pages 1..31   HTTP 200, 20 results each
page 32..55   HTTP 429 Too Many Requests
```

That is **31 successful requests before Eventbrite began refusing**, at a 2-second
inter-request delay. It produced 618 unique events from a listing that declares 1,547
objects. Two facts follow, and both are the justification for G9:

1. **A source that is being rate-limited stays being rate-limited across runs.** The
   next scheduled run, or an operator pressing "Run", re-sends the same doomed requests
   and produces nothing. That is a retry loop with no upside and a real cost.
2. **The walk stopped on a throttle, not on completion** — so the truthful state is
   "incomplete", which G1–G5 already model correctly. G9 adds the missing half: the
   *next* run should know, before sending anything, that this source is still cooling.

No cadence, backoff or budget value below was chosen by taste. Each traces to a number
measured in this session, or to a value G5 already measured and G9 deliberately reuses
rather than inventing a second, conflicting one.

---

## What was changed

| # | Change | File | Measured basis |
|---|---|---|---|
| 1 | Upstream-truncation guard | `sources/class-eventbrite-source.php` | 1,547 objects behind 980 reachable slots |
| 2 | Date-window source class | `includes/class-county-registry.php` | 198 events the capped listing never reaches |
| 3 | `Conexao_Source_Cooldown` | `includes/class-source-cooldown.php` (new) | 31 requests before 429 |
| 4 | Cooldown gate + recovery in the engine | `includes/class-event-importer.php` | same |
| 5 | `has_fetched_events()` seam | `sources/abstract-class-source.php` | recovery needs a real listing |
| 6 | Cooldown in Import History (storage + UI) | `class-import-history.php`, `class-event-sources.php` | §26/§29 operator state |

---

## §21 — Source-specific cadence

**Decision: no cadence was implemented, and that is the measurement-driven answer.**

The brief forbids arbitrary schedules without measurements. The only cadence evidence
available is the volatility of the source's own content, and this stage did not measure
that — it measured *reachability and throttle behaviour*, not how often an event list
changes. A 26-county × 3-window schedule built on that basis would be invented, not
derived.

What G9 does instead is make the **decision inputs** available and bounded:

- the `next_eligible` timestamp per source (when a throttled source may be retried),
- `consecutive` throttled observations (how bad it has been),
- the Import History `Listing` column (complete vs incomplete).

**Honest limitation:** cadence remains operator-driven, exactly as before. This is
recorded as the recommended next step in [§ Recommendation](#recommendation), not
quietly treated as done.

---

## §22 — Eventbrite operational strategy

| Control | Status | Evidence |
|---|---|---|
| Sequential processing | **Retained** | unchanged |
| Bounded request execution | **Retained** | `Conexao_Request_Budget` unchanged, 400/run |
| Retry-After | **Retained** | unchanged, capped at 60 s |
| Explicit retry classification | **Retained** | unchanged, 3 attempts |
| Repeated-429 fail-fast | **Retained** | unchanged |
| WAF detection | **Retained** | unchanged; 405 additionally maps to cooldown |
| Completeness tracking | **Retained + strengthened** | truncation guard added |

**No circumvention was introduced.** No proxy rotation, CAPTCHA bypass, fingerprint
spoofing, WAF evasion or aggressive parallelism. G9 makes the importer *stop asking*,
which is the opposite of evasion.

---

## §23 — Cooldown and recovery

`Conexao_Source_Cooldown` records, per source: `consecutive`, `last_reason`,
`last_observed`, `cooldown_seconds`, `next_eligible`.

**Which failures earn a cooldown** (`THROTTLED_REASONS`):

| Failure | Cooldown? | Why |
|---|---|---|
| HTTP 429 | **yes** | re-sending immediately cannot help |
| HTTP 405 | **yes** | this provider's WAF refusal code (G1–G5) |
| HTTP 5xx | **yes** | upstream is unhealthy |
| transport failure (status 0) | **yes** | we never reached it |
| HTTP 404 | **no** | a fixable source must stay immediately retryable |
| parse / malformed | **no** | a broken source, not a throttled one |

The last two rows are a deliberate product decision: putting a *fixable* source to sleep
would delay an operator's legitimate fix. Both are asserted, and the 404 row is
mutation-verified.

**Ladder and bound.** `BASE_SECONDS = 20` (G5's measured rate-limit base — reused, not
re-invented), stepping by `STEPS = 3`, hard-capped at `MAX_SECONDS = 60` (G5's existing
cooldown ceiling). Measured ladder: **20 s → 40 s → 60 s → 60 s → 60 s**.

A *different* category restarts the ladder: a WAF block does not inherit a 429's
accumulated delay.

**A cooldown must not mark a source complete — and it does not.** A cooldown-skipped run:

- sends **zero** requests (asserted);
- records `complete = false` in Import History (asserted, mutation-verified);
- returns a failed/warning status, never a clean success (asserted);
- **never** reaches `mark_missing_events()`, because that is only reached after a real
  fetch, and the gate returns before any fetch begins.

**Recovery** is inferred only from a *complete, non-empty* enumeration. An incomplete or
empty walk does not prove the upstream is healthy, so the cooldown stands. This is
asserted through the real `run_source()` path, not only the predicate.

---

## §24 — Request budgets

**No budget was changed.** The brief is explicit that a budget increase needs measured
justification, and the measurement points the other way: the Cork walk was **cut short by
HTTP 429 at request 31**, not by the 400-request ceiling. Raising the budget would change
nothing about what the upstream serves and would only risk a longer run against a source
that is refusing. Budgets remain finite (400/run) and source-agnostic.

A budget hit still means `incomplete`, never "success with zero missing events" —
unchanged from G4C.

---

## §25 — Response reuse and caching

**No caching change was made.** G4 measured no meaningful live cache-hit improvement, and
§25 forbids expanding caching by default. `Conexao_Request_Reuse` is untouched.

---

## §26 — Import History as the single operational surface

Import History gained two columns; no second dashboard and no parallel persistence.

| Column | Answers |
|---|---|
| **Listing** | Was this source fully enumerated, or did we only see part of it? |
| **Cooldown** | Is it cooling down, why, and how long until I may retry? |

Every history entry now also carries a bounded `cooldown` shape
(`cooling_down`, `seconds_remaining`, `next_eligible`, `last_reason`, `consecutive`) —
**per run, not per request**, so observability did not become a storage problem.

Storage is one small option (`conexao_event_source_cooldown`), bounded to
`MAX_SOURCES = 200` entries and pruned to keep the most recent observations.

---

## §27 — Avoiding tight operator retry loops

The operator now sees, before pressing anything:

```
cooldown (rate_limited) — retry in ~40s
```

and, if they press Run during the window:

> *"Not attempted: Eventbrite — Cork (This week) is in cooldown after a rate_limited
> response. No request was sent. Try again in about 40 seconds…"*

The system tells the operator what happened and what to do, and — importantly — **does
nothing on its own**. There is no automatic retry scheduling, no cron, no self-healing
loop. Deferring a request is the whole intervention.

---

## §28 — Long-run stability

**What was measured.** The live upstream was walked repeatedly during this session:

| Run | Requests | Outcome |
|---|---:|---|
| Cork `all-events` | 31 OK, then 429 ×24 | 618 unique, 172.7 s |
| Cork `events-today` | 2 | 23 unique, complete |
| Cork `events-tomorrow` | 2 | 23 unique, complete |
| Cork `events-this-week` | 16 | 304 unique, **complete, 0 errors** |
| Cork `events-this-month` | 12 OK, then 429 | 240 unique, incomplete |

Looking for the specific pathologies §28 names:

| Pathology | Result |
|---|---|
| Checkpoint stagnation | **Not present** — the importer holds no persistent page cursor; every run re-walks from page 1. Stagnation is structurally impossible. |
| Repeated page loops | **Not present** — the walk is a bounded `for` over a declared page count. |
| Increasing duplicate events | **Not present** — dedup is by native id then canonical URL; re-imports resolve to the same record (asserted). |
| Escalating request volume | **Not present** — bounded by page count, time budget and request budget; the run ends, it does not retry forever. |
| Sources permanently stuck partial | **Possible and now visible** — a genuine finding, recorded below. |
| Cooldown never clearing | **Not present** — the window is time-bounded and recovery clears it (asserted, mutation-verified). |
| False completion | **Fixed by G8** — the truncation guard makes the capped listing report incomplete. |

**Variance was not hidden.** The two incomplete walks above are reported as incomplete.

**A real finding, stated rather than smoothed over:** because a truncated `all-events`
listing can *never* be complete, that source will now report `incomplete` on **every**
run. This is truthful, not a bug — but it means "all events" for such a source is
permanently unreachable through that listing, which is precisely the gap G8's date-window
sources exist to cover. It is reported this way rather than suppressed by loosening the
completeness contract.

---

## §29 — Operational metrics

The compact metric set requested by §29, and where each is readable:

| Metric | Where |
|---|---|
| last run / last complete run | Import History (newest entry; `Listing` column) |
| pages/events processed | Import History `Found` |
| new / updated events | Import History `New`, `Updated` |
| duplicates | Import History `Duplicates` |
| 429 / 405-WAF / other failures | Import Log (`reason_code`) + `Cooldown.last_reason` |
| completeness | Import History `Listing` |
| cooldown | Import History `Cooldown` |
| next eligible recovery | `Cooldown` column (`next_eligible`) |
| requests / reused responses | existing request-scope log summary (unchanged) |
| elapsed time | Import History entry time |
| missing-event reconciliation | implied by `Listing = complete` — it only runs then |

Nothing per-request was added to storage. The cooldown record is one bounded row per
failing source.

---

## §30 — Testing discipline

Comparison is against the **G7 baseline**, not an invented clean baseline.

### New suite

`tests/test-eventbrite-source-expansion.php` — **65 assertions, 0 failures**, ~3 s,
network-free (all HTTP is a `pre_http_request` double).

Covering the eleven required cases from §17:

| # | Case | Proven by |
|---|---|---|
| 1 | normal source retrieval | window listing yields events through the existing handler |
| 2 | pagination | boundary at `page_count × page_size` (exactly-full vs one-over) |
| 3 | duplicate events | same event via two sources → one record |
| 4 | malformed response | pre-existing G1 suite; handler untouched |
| 5 | source failure | 429/405/5xx/network classification |
| 6 | partial pagination | truncation guard → incomplete |
| 7 | empty valid response | zero-object listing is not truncation |
| 8 | missing geographic metadata | out-of-county event rejected despite the window URL |
| 9 | identity stability | native Eventbrite id, not index/page/title |
| 10 | re-run idempotence | dedup resolves to the same post both directions |
| 11 | missing-event safety | incomplete walk never reaches `mark_missing_events()` |

### Negative proofs (mutation-verified)

Every mutation was applied to the real source file, the suite was run, and the file was
restored. **A mutation that did not fail a single assertion was treated as a defect in the
test, not as a pass.**

| # | Mutation | Failures | Verdict |
|---|---|---:|---|
| M1 | `is_upstream_truncated()` always returns false | 2 | genuine |
| M2 | cooldown gate removed from `run_source()` | 5 | genuine |
| M3 | cooldown cleared on *any* fetch (recovery gate removed) | 1 | genuine |
| M4 | cooldown-skipped run recorded as `complete = true` | 1 | genuine |
| M5c | `record_elapsed()` grants a real cooldown window | 2 | genuine |
| M5d | cooldown ladder bounds removed (unbounded growth) | 3 | genuine |
| M6 | HTTP 404 classified as a throttle | 2 | genuine |
| M7b | county region gate always accepts | 1 | genuine |
| M8 | `has_fetched_events()` always true | 2 | genuine |

**Two mutations were initially vacuous and the tests were fixed, not the verdict:**

- **M3** first produced 0 failures, because every recovery assertion called
  `can_clear_cooldown()` directly and never exercised the engine wiring. A new
  end-to-end case now runs a real `run_source()` and asserts on the cooldown **record**.
- **M5/M5b** first produced 0 failures, because comparing two adjacent ladder entries is
  satisfied by any monotonic-then-flat ladder, and the ladder had never been driven past
  the step cap. The test now escalates past `STEPS` and asserts the ceiling is *reached*
  and *never exceeded*.

A third apparent failure, "a COMPLETE run does not clear the cooldown", turned out to be
a **test bug**: the assertion used `is_cooling_down()`, which is `false` for an elapsed
record whether or not it was cleared. The assertion now checks record existence in
`get_all()`. Notably, the corrected assertion revealed that `min(BASE × step, MAX)` is
redundant defence-in-depth — `min(step, STEPS)` already bounds it — so this report claims
only the bound that is actually load-bearing (M5d).

### Suite results vs G7 baseline

| Layer | G7 baseline | After G8/G9 | Delta |
|---|---|---|---|
| In-process PHP suites | 93 (64 pass / 29 fail) | **94 (65 pass / 29 fail)** | +1 suite, +1 pass, **failures unchanged** |
| Assertions | 3792 passed / 4 failed | **3857 passed / 4 failed** | **+65 passed**, failures unchanged |
| PHP failure set | 29 suites | **29 suites** | **byte-identical (`diff` clean)** |
| Script contract | 19 (18 / 1) | **19 (18 / 1)** | unchanged |
| HTTP acceptance | 4 (3 / 1) | **4 (3 / 1)** | unchanged |
| Permanent gates | 6/7 per the brief | **7 / 7 PASS** | see note below |

**No test was removed.** The only modified test is `tests/test-county-registry.php`,
where three assertions that hard-coded the source count (`52`, `52`, `58`) now derive it
from `Conexao_County_Registry::get_all_county_sources()`. That is a **correctness fix to
the test**, not a weakening: a hard-coded count is a second source of truth that G8's new
source class made wrong. The assertions are now strictly stronger — they assert *equality
with the registry* rather than a literal.

**On the gate count — a correction worth stating plainly.** The brief records the G7
baseline as 6/7 with `i18n_freshness` failing and "not suppressed or weakened". This
stage reports **7/7**. That failure was **not** an unavoidable pre-existing condition,
and it was **not** suppressed here. Reading the committed
`docs/evidence/2026-09-26-stage-l/gate.json` before and after shows its actual cause:

```
FAIL: conexao-event-importer: .../conexao-event-importer.pot is older than
      .../conexao-event-importer.php by 40677s - regenerate the catalogue
      with `wp i18n make-pot` (never hand-edit)
```

G7's final commit (`Add test suite for Eventbrite API token retirement`) modified
`conexao-event-importer.php` **without regenerating the catalogue**, so the gate was
correctly detecting a real staleness regression. G8/G9 also touch that file, so the
remedy was required regardless.

It was fixed the sanctioned way: `scripts/i18n-make-pot.sh`, never a hand-edit. The
regenerated catalogue contains the new G9 strings (`Listing`, `Cooldown`, `complete`,
`incomplete`, `cooling down (%1$s) - retry in ~%2$ds`, `cleared (last: %s)`) and still
contains **0** occurrences of `eventbrite_api_token`, so G7's retirement survives
regeneration. No assertion, threshold or allowlist was touched: `i18n_freshness` runs
3 assertions and all 3 pass.

The honest reading is that the stage baseline was 6/7 for a *fixable* reason, and this
stage closes it.

### Other gates

| Gate | Result |
|---|---|
| `./scripts/lint.sh` | **OK** — no parse errors, no new PHPCS violations, PHPStan clean |
| `scripts/generate-registry-docs.php --check` | **OK** — 10 plugins, 19 regions, zero writes |
| `verify-documentation-drift.py` | 14 passed, 0 failed |
| `verify-agent-governance.py` | 357 passed, 0 failed |
| `verify-eventbrite-token-retirement.py` | 59 passed, **0 live references** |

---

## Remaining limitations

1. **No production verification, by design.** No production write, deployment or commit
   occurred. Import History columns and the cooldown gate were exercised against the
   local Docker site only.
2. **Cadence is not implemented** (§21). It needs content-volatility measurements this
   stage did not take; inventing a schedule was refused.
3. **Only Cork was walked live.** The truncation guard is generic and unit-proved, but the
   429 threshold (≈31 requests) is a single-county observation. Other counties may differ.
4. **The capped `all-events` listing can never be complete.** This is now reported
   truthfully on every run rather than hidden. Full county coverage depends on the
   date-window sources.
5. **`record_elapsed()` is a test seam** on a production class. It grants no privilege
   (an elapsed record never blocks a run) but it is public API that exists for tests.
6. **Long-run stability is one session, not a multi-day campaign.** Five consecutive
   walks were observed; a week-long campaign would say more about source churn.

---

## Recommendation

**The next concrete step is a bounded source-volatility measurement**, not more code.

Run the three registered window sources for one county on a fixed cadence for ~7 days and
record, per run: events found, created, updated, unchanged, and the wall-clock delta. That
produces the only missing input — how often each window's content actually changes — which
is what §21 requires before any cadence can be chosen on evidence rather than instinct.

Concretely:

```bash
# manual, operator-driven, no cron and no production write
docker compose exec wordpress wp eval 'apply_filters("conexao_event_importer_run_source", "eventbrite_cork_events_this_week");'
```

Then read the two new Import History columns to decide whether that source warrants a
schedule at all.

**Do not** raise request budgets, expand caching, or relax the truncation guard to make a
run look greener. Each of those would trade a truthfulness property this stage was
specifically chartered to protect.
