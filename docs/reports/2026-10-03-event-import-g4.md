# Report — Event Import G4: Request Efficiency, Backoff and Budgets

| | |
|---|---|
| **Stage / task name** | Event Import G4 — G4A per-run response reuse, G4B `Retry-After` + retry classification, G4C bounded outbound request execution |
| **Date** | 2026-10-03 |
| **Branch** | `master` |
| **Start SHA** | `9ab9de3f7bfcf2cde99471e58f8a1c1e6a6e8b2f` |
| **Code SHA** | `141cedb` |
| **Predecessor** | [2026-10-03 Event Import Reliability Fixes (G1–G3)](2026-10-03-event-import-fixes-g1-g3.md) · [Event Import Diagnosis](2026-10-03-event-import-diagnosis.md) |
| **Evidence** | [`docs/evidence/2026-10-03-event-import-g4/`](../evidence/2026-10-03-event-import-g4/) |
| **Plugin version** | `conexao-event-importer` 1.7.2 → **1.8.0** |
| **Production writes** | **0** |

---

## A. Executive summary — including the finding that does not flatter G4A

Three mechanisms were implemented, each with its own baseline, tests, negative
proof and runtime measurement. **Two of the three delivered a measurable
improvement. One did not, and that is reported plainly rather than dressed up.**

| Unit | Mechanism | Verdict |
|---|---|---|
| **G4A** | per-run response reuse | **PASS WITH LIMITATION** — mechanism complete, tested and negative-proofed, but produced **0 cache hits** on the measured workload because the duplication it targets was already removed by G2A |
| **G4B** | `Retry-After` + retry classification | **PASS** |
| **G4C** | bounded outbound request execution | **PASS** |

**The honest headline:** G4 did *not* materially reduce the request count.
1,525 → 1,449 outbound requests (−76, −5.0 %), and that delta is **upstream
variance, not G4**. The reuse store recorded **0 hits** on every source measured.
The duplication G4A exists to remove had already been removed by G2A's
pagination fix. What G4 delivers is correctness hardening — bounded retries,
bounded execution, and a tested guarantee that no future regression can
reintroduce duplicate traffic — not a request-count win.

> Eventbrite is an external dependency that can return rate limits and other
> upstream failures; G4 improves how the importer reuses safe responses, handles
> bounded retry/backoff behaviour, and limits runaway request execution.
> **Eventbrite is not "fixed".**

---
## B. Fresh post-G1–G3 baseline (§2) — measured, not assumed

The brief warned that the pre-G1–G3 figures (1,785 requests / 1,601 duplicates)
are no longer the baseline. A fresh baseline was therefore measured **on the
committed G1–G3 tree**, before any G4 code was written.

### B.1 Focused sources (§13 representatives)

| Source | Duration | Status | Found | Complete | Outbound | Unique | Duplicates | 429s |
|---|---:|---|---:|---|---:|---:|---:|---:|
| `eventbrite_carlow` | 51.5 s | success (incomplete) | 36 | false | 27 | 25 | 2 | 3 |
| `eventbrite_cavan` | 14.3 s | **failed** | 0 | false | 3 | 1 | 2 | 3 |
| `heritage_week_wicklow` | 11.5 s | warning | 121 | true | 132 | 132 | **0** | 0 |

Evidence: `baseline-focused.txt`, `baseline-focused-summary.json`.

### B.2 Full dry run (56 sources)

```
elapsed_seconds=849.0
combined_status=failed
found=1407 created=4 updated=174 skipped=1229 failed=0
per_source_status={"warning":13,"success":3,"failed":40}
failed_sources=40 incomplete_sources=44 fatal_error_count=40

outbound_requests=1525 unique_requests=1472 duplicate_requests=53
status_distribution={"200":1417,"429":108}
count_429=108 count_502=0 count_504=0 transport_errors=0
responses_with_retry_after=0
by_host={heritageweek.ie:1325, eventbrite.ie:113, motorsportireland.com:51,
         mondellopark.ie:30, ivvcc.ie:5, laoistourism.ie:1}
history_after={rows:100, found0_and_success:0, marked_incomplete:81}
```

Evidence: `baseline-full.txt`, `baseline-full-summary.json`.

**Observations that shaped the design:**

1. **The largest legitimate single source is 132 requests** (`heritage_week_wicklow`,
   a *complete* enumeration of 11 listing pages + 121 detail pages). This is the
   number the G4C ceiling is derived from.
2. **`responses_with_retry_after = 0`** — Eventbrite did not send the header in
   this baseline, so G4B's `Retry-After` support is **unexercised by real
   traffic** and is proven by deterministic tests instead (§E).
3. The 53 duplicate requests were concentrated on 24 URLs, 46 of them on
   Eventbrite `/all-events/` URLs fetched **exactly 3×** — the signature of
   `MAX_RETRIES = 3` against a `429`, i.e. **retries, not reuse waste**.

---
## C. Files changed

| File | Change |
|---|---|
| `includes/class-request-reuse.php` **(new)** | G4A per-run response reuse store: eligibility, deterministic key, get/set, counters |
| `includes/class-request-budget.php` **(new)** | G4C per-run outbound request budget: consume/exhaust/stats, filterable ceiling |
| `includes/sources/abstract-class-source.php` | added `request_get()` — the single reuse-aware transport seam; `fetch_html()` routes through it |
| `includes/sources/class-heritage-week-source.php` | `fetch_detail_html()` routes through the seam |
| `includes/sources/class-mondello-park-source.php` | REST fetch routes through the seam |
| `includes/class-eventbrite-client.php` | G4B: `RETRYABLE_STATUSES`, `parse_retry_after()`, `is_retryable_status()`, injected sleeper + applied-delay recording, `request_page()` seam; `fetch_page()` classification rewritten around them |
| `includes/class-event-importer.php` | `begin_run_scope()` per run; one run-correlated request-accounting summary per run |
| `conexao-event-importer.php` | version 1.7.2 → 1.8.0 (header **and** the stale `CONEXAO_EVENT_IMPORTER_VERSION` constant); loads the two new classes |
| `tests/test-request-reuse.php` **(new)** | G4A — 53 assertions |
| `tests/test-request-retry-policy.php` **(new)** | G4B — 58 assertions |
| `tests/test-request-budget.php` **(new)** | G4C — 33 assertions, incl. the cross-stage data-loss proof and its control |
| `tests/test-source-fetch-failure.php` | §Test 5 updated: 502 moved to the retried group (§G4B), 504 added. **This is a deliberate assertion change, not a weakening** — the G1 failure/exception semantics are untouched |
| `tests/test-eventbrite-importer.php` | a pre-existing test double overriding `backoff()` updated to the new optional-parameter signature |
| `languages/conexao-event-importer.pot` | regenerated with `scripts/i18n-make-pot.sh` (new G4 strings) |
| `docs/plugins/conexao-event-importer.md` | §"Request efficiency, retry policy and bounded execution (G4)"; filters documented |
| `docs/architecture.md`, `docs/project-inventory.md`, `docs/plugins/README.md` | regenerated registry regions (version bump) |

**No second importer. No second HTTP client. No replacement of
`Conexao_Eventbrite_Client`.** `wp_remote_get()` remains the only transport.

---
## D. G4A — per-run response reuse

### D.1 Implementation

`Conexao_Request_Reuse` is a plain in-memory store, not a client. It performs no
requests; it decides whether the existing `wp_remote_get()` transport needs to
run at all. Scoping is per run: `begin_run_scope()` resets it in `run_source()`.
Nothing is written to an option, transient, table or file.

Eligibility is deliberately narrow: `GET`/`HEAD` only; no body; not
streaming/ranged; no `Authorization`, `Cookie`, `Range`, `If-None-Match` or
`If-Modified-Since`; and **only 2xx responses are ever stored**.

The "only 2xx" rule is the load-bearing one. It means a transient 429/5xx can
never be replayed later as if it were page content, and a retry always goes back
to the network — which is what keeps `failure != empty success` true under
caching.

### D.2 Tests — `test-request-reuse.php` (53 assertions, all passing)

| Case | Expected | Result |
|---|---|---|
| same GET twice in one run | 1 outbound request, same body | PASS |
| same URL in the next run | a fresh outbound request | PASS |
| differing URL / method / `Accept` / `Accept-Language` / `UA` / body / query | different key | PASS |
| header name case + order | same key (no false miss) | PASS |
| differing `Accept-Language` at runtime | **no** false cache hit (2 outbound) | PASS |
| POST / PUT / DELETE / PATCH | never cached (2 POSTs both hit the network) | PASS |
| `Authorization` / `If-None-Match` / body-bearing GET | not cacheable | PASS |
| cached malformed body | same parse result, **0 events — fails closed** | PASS |
| cached malformed 200 through the **real** Eventbrite parser | raises, fetched *and* reused | PASS |
| cached 500 | **not** reused; the second attempt goes to the network | PASS |
| cached 200 headers | preserved on a hit | PASS |
| `run_id` correlation | preserved | PASS |

### D.3 Negative proof (§17)

Cache-key discrimination removed (headers and body dropped from the key):

```
48 passed, 5 failed
  FAIL: G4A: a different Accept header produces a different cache key
  FAIL: G4A: a different Accept-Language produces a different cache key
  FAIL: G4A: a different User-Agent produces a different cache key
  FAIL: G4A: a different body produces a different cache key
  FAIL: G4A: the differing Accept-Language was NOT served from cache — expected 2, got 1
```

The last line is the important one: the mutation reproduces the **real**
false-hit defect at runtime, not merely a key inequality. Not vacuous.

### D.4 Runtime measurement — the honest result

| Source | Outbound | Unique | Duplicates | Reuse `hits` | Reuse `misses` | Reuse `stores` |
|---|---:|---:|---:|---:|---:|---:|
| `eventbrite_carlow` | 16 | 16 | 0 | **0** | 16 | 16 |
| `eventbrite_carlow` (trace run) | 17 | 17 | 0 | **0** | 17 | 17 |
| `eventbrite_cavan` (trace run) | 17 | 17 | 0 | **0** | 17 | 17 |
| `heritage_week_wicklow` | 132 | 132 | 0 | **0** | 132 | 132 |

**Cache hits: 0, everywhere.** The mechanism works (a hit is a hit whenever a
duplicate exists — proven deterministically), but on the current workload there
are **no same-run duplicate requests left to eliminate**. G2A already fixed the
Heritage Week ping-pong, which *was* the duplicate traffic; what remains is
retries against failing URLs, which must not be cached.

Consequently **G4A is reported as PASS WITH LIMITATION**: complete, tested and
negative-proofed, but with **no measurable request-count improvement** on the
representative runs. Claiming a caching win here would be false.

---
## E. G4B — `Retry-After` and retry classification

### E.1 Implementation

`Conexao_Eventbrite_Client` now classifies statuses explicitly instead of via an
inline `switch`, and honours `Retry-After` under a hard cap:

```
RETRYABLE_STATUSES = [429, 500, 502, 503, 504]
delay = min( parse_retry_after(header), MAX_RETRY_AFTER_SECONDS /* 60 */ )
```

`parse_retry_after()` accepts delta-seconds and HTTP-date, clamps a past date to
`0`, and returns `null` for empty/non-numeric/unparseable values — which routes
back to the **existing** 2/4/8 s exponential backoff.

`MAX_RETRIES = 3` is unchanged and remains authoritative. There is no "retry
until success" path.

**502/504 became retryable.** Before G4 the retried set was `{429, 500, 503}`;
502 fell through to the `default` branch, was logged as an error and returned
immediately. `test-source-fetch-failure.php` explicitly asserted that, and that
assertion was **moved**, with the reason written into the test. 401/403/404/408
remain non-retryable.

### E.2 Tests — `test-request-retry-policy.php` (58 assertions, all passing)

Deterministic fake responses only; the client receives an injected sleeper that
records the delay it *would* apply, so **no test sleeps**.

| Scenario | Expected | Result |
|---|---|---|
| `Retry-After: 7` on a 429 | next attempt waits **7 s** (not the 2 s default) | PASS |
| `Retry-After: 999999` | clamped to the 60 s cap; never sleeps the literal value | PASS |
| `Retry-After: 3600` / far-future HTTP-date | clamped | PASS |
| `Retry-After: soon` | falls back to the 2 s base backoff | PASS |
| 429 with **no** `Retry-After` | falls back to the 2 s base backoff | PASS |
| past HTTP-date | clamps to `0` | PASS |
| 401 / 403 / 404 / 408 | **1 request**, empty result | PASS |
| 429 / 500 / **502** / 503 / **504** | **3 requests** (bounded retry) | PASS |
| transport `WP_Error` | 3 attempts, empty result, status `0` | PASS |
| permanently-429 page | exactly 3 attempts, empty result | PASS |
| success on the 3rd attempt | honoured | PASS |
| retry diagnostics | carry the `run_id` | PASS |

### E.3 Negative proofs (§17)

**Remove `Retry-After` handling:**

```
55 passed, 3 failed
  FAIL: G4B: the client waited exactly the Retry-After value (7s), not the 2s default backoff — expected 7, got 2
  FAIL: G4B: the applied delay is recorded for diagnostics — expected 7, got 2
  FAIL: G4B: Retry-After=999999 was clamped to the hard cap before sleeping — expected 60, got 2
```

**Make 401 retryable:**

```
56 passed, 2 failed
  FAIL: G4B: HTTP 401 is NOT retried (no blind retry)
  FAIL: G4B: HTTP 401 issues exactly 1 request(s) — expected 1, got 3
```

The second proof reproduces the exact regression the brief warned about —
turning an authentication failure into repeated traffic.

### E.4 Runtime measurement

```
429 count:     108 (baseline) → 106 (after)
502 / 504:     0 → 0          (Eventbrite returned none in these runs)
retry delays:  responses_with_retry_after = 0  (both runs)
duration:      849.0 s → 824.1 s
```

**Limitation, stated plainly:** Eventbrite sent **no `Retry-After` header at all**
during either run, so the new code path was **never exercised by real traffic**.
G4B's `Retry-After` support is proven by deterministic tests only. The 429 count
moved 108 → 106 (2 fewer) because Eventbrite's rate limiting fluctuates between
runs, **not** because of G4B.

---
## F. G4C — bounded outbound request execution

### F.1 The ceiling, and why 400

`Conexao_Request_Budget::DEFAULT_LIMIT = 400` per run, chosen from the measured
post-G1–G3 baseline rather than guessed:

| Observed value (baseline) | Requests |
|---|---:|
| Largest legitimate single source (`heritage_week_wicklow`, **complete** enumeration) | **132** |
| Whole 56-source dry run | 1,525 |
| Chosen per-source ceiling | **400** |

400 is ~3× the largest legitimate single source and far below a runaway. A
ceiling low enough to be "tight" (say 150) would have made legitimate large
counties fail their enumeration, which the brief explicitly warns against — the
goal is *prevent runaway*, not *make normal imports fail*. It is filterable via
`conexao_event_importer_request_budget`.

### F.2 Accounting rules

- Counts requests that **actually leave the process**.
- A response served from the G4A store is **not charged** — nothing was sent.
- A retry attempt **is** charged — it is a real request.
- `consume()` never grants a partial batch, so "exactly N outbound requests"
  stays exact.
- Exhaustion returns a `WP_Error`, which flows through the **existing**
  fetch-failure path, which G2's completeness machinery already maps to
  `complete = false`. No new failure concept was introduced.

### F.3 Tests — `test-request-budget.php` (33 assertions, all passing)

| Case | Expected | Result |
|---|---|---|
| ceiling 5, 8 attempts | exactly **5** outbound, the 6th blocked and counted | PASS |
| `consume(10)` then `consume(5)` | batch refused, `used` stays **10** | PASS |
| 1 outbound + 2 reuses (ceiling 2) | 1 charged, 1 cache hit, **not exhausted** | PASS |
| 10 identical requests (ceiling 3) | **1** outbound, **1** charged | PASS |
| budget 3 vs a 20-page listing | **3** outbound, `complete=false` | PASS |
| — | events fetched before exhaustion **retained** | PASS |
| — | explicit `INCOMPLETE` logged, History row `complete=false` | PASS |
| **cross-stage**: real run, ceiling 3 | `complete=false`, status `warning` | PASS |
| **cross-stage** | live event **NOT** marked `source_not_found`; still `published` | PASS |
| **control**: same source, ample budget | `complete=true`, event **IS** marked `source_not_found` | PASS |

The control is what makes this proof real: without it, the guard could simply be
disabling `mark_missing_events()` everywhere.

### F.4 Negative proof (§17) — including the data-loss mechanism

Ceiling check removed:

```
17 passed, 16 failed
  FAIL: G4C: the run stopped at the ceiling of 3 outbound requests (got 20) — expected 3, got 20
  FAIL: G4C: a budget-truncated walk reports complete=false
  FAIL: G4C cross-stage: the real run reports complete=false
  FAIL: G4C cross-stage: a ceiling-truncated run is not recorded as a bare success (got "success")
  FAIL: G4C cross-stage: the Import History row is not a bare success (got "success")
  FAIL: G4C cross-stage: the live event was NOT marked source_not_found (got "source_not_found")
  FAIL: G4C cross-stage: the live event is still published for the public site — expected 'published', got 'source_not_found'
  ... (16 total)
```

This mutation reproduces **both** halves of the failure the brief warns about:
the run becomes a false `success`, **and** a live event is hidden from the public
site as `source_not_found`. Not vacuous.

### F.5 Runtime measurement

In every live run the budget was `limit=400, blocked=0, exhausted=false`. The
largest per-source consumption observed was 132 (Wicklow). **No legitimate import
was affected by the ceiling** — which is the intended outcome, and the reason
400 was chosen from measurement rather than intuition.

---
## G. Full-run comparison (§11)

Same harness, same 56 sources, same dry-run mode, ~35 minutes apart.

| Metric | Fresh post-G1–G3 baseline | After G4 | Change |
|---|---:|---:|---|
| Elapsed | 849.0 s | **824.1 s** | −24.9 s |
| **Outbound requests** | 1,525 | **1,449** | **−76 (−5.0 %)** |
| Unique requests | 1,472 | 1,399 | −73 |
| Duplicate requests | 53 | 50 | −3 |
| HTTP 429 | 108 | 106 | −2 |
| HTTP 502 / 504 | 0 / 0 | 0 / 0 | — |
| Retry attempts (Eventbrite Carlow focused) | 6 | 3 | −3 |
| Eventbrite requests | 113 | 113 | 0 |
| Heritage Week requests | 1,325 | 1,249 | −76 |
| `found` | 1,407 | 1,458 | +51 |
| created / updated | 4 / 174 | 47 / 252 | dry-run "would-be" |
| failed sources | 40 | 40 | **0** |
| incomplete sources | 44 | 44 | **0** |
| `found=0` + `success` rows | 0 | **0** | **0** |

Evidence: `baseline-full.txt`, `g4-full.txt`, `g4-full-summary.json`.

### G.1 What actually explains the −76 requests

**It is not G4.** The entire reduction is on `heritageweek.ie`
(1,325 → 1,249), while Eventbrite's request count is **identical** (113 → 113)
and `found` moved by +51 events. Heritage Week's request count is a function of
how many events exist upstream that run (`found` moved 1,407 → 1,458), so this is
**upstream variance between two runs**, not an efficiency gain.

Corroborating measurement: the reuse store recorded **0 hits** in the full run.
If G4A had eliminated any request, `hits` would be non-zero. It is zero.

### G.2 The honest verdict on the request-count goal

The brief asked for "fewer unnecessary requests **+** correct bounded retries
**+** bounded execution **+** no data-loss regression". G4 delivered the last
three, and **not** the first in any measurable way. Reporting a request-count win
would be false; the number went down by 5 % for reasons unrelated to G4.

---
## H. Source-specific runtime verification (§13)

### H.1 Heritage Week — `heritage_week_wicklow`

```
pages visited        : 11   (p1 -> p2 -> p3 -> p4 -> p5 -> p6 -> p7 -> p8 -> p9 -> p10 -> p11)
unique listing URLs  : 11
detail pages         : 121
page revisits        : 0
cache hits           : 0
cache misses         : 132
outbound requests    : 132
unique URLs          : 132
duplicates           : 0
budget               : used 132 / 400, blocked 0, exhausted false
termination reason   : no forward link left (COMPLETE enumeration)
complete             : true
found                : 121
```

Forward-only traversal is intact under G4 (no ping-pong), the reuse store is
warm (132 entries) but had nothing to hit, and the budget was never approached.
A later run of the same source hit a real upstream 429 on the first page and
correctly reported `termination=INCOMPLETE` with 1 request — the G1 truthfulness
path working live, not swallowed by the cache.

### H.2 Eventbrite — `eventbrite_carlow` / `eventbrite_cavan`

```
                              carlow (baseline)  carlow (after)   cavan (after)
pages attempted                    27                16               17
pages completed                    25                16               17
cache hits                          0                 0                0
cache misses                        0                17               17
outbound requests                  27                16               17
retry attempts                      6                 3                0
429s                                 3                 0                0
budget used/blocked                 —              16/0             17/0
termination           time budget 45 s after page 25/49   same, page 16/49
complete                            false             false            false
found                                36                36               38
status                 success (incomplete)  success (incomplete)  success (incomplete)
```

In the baseline, Cavan was **rate-limited and recorded `failed`, `found=0`,
`complete=false`** (the G1 truthful-failure path). In the G4 runs Eventbrite was
not rate-limiting, so both counties walked further and reported `complete=false`
with the time budget as the (truthful) reason. `complete=false` is reported
honestly in every case — never a false "complete".

Evidence: `baseline-focused.txt`, `g4-focused.txt`, `g4-trace-run.txt`,
`g4-trace-cavan.txt`, `g4-trace-eventbrite_carlow.json`.

---

## I. Import History verification (§12)

Captured over HTTP as an authenticated local administrator against
`http://localhost:8080/wp-admin/admin.php?page=conexao-import-history`
(HTTP 200, 182,750 bytes, `CAPTURE_OK`):

```
http_status=200
authenticated_page=yes
rendered_rows=100
rendered_status={'failed': 100}
rendered_found0_and_success=0
```

- **`found=0` + `success` does not reappear**: **0 rows**, parsed from the
  rendered table (not the option). The G1 invariant holds.
- **Truthful failures remain visible**: all 100 rows are `failed` — 40 sources
  genuinely fail against Eventbrite's WAF/rate limiting, and that is now shown
  rather than hidden. G4 did **not** suppress a single one.

Evidence: `import-history-after.txt`, `import-history-page-after.html`.

---
## J. Regression and static verification (§14, §15)

### J.1 `./scripts/lint.sh`

```
OK: no PHP parse errors.
PHPCS legacy baseline: 2775 errors / 2092 warnings in 206 files (baseline: 3290 / 2672).
OK: no new PHPCS violations against the baseline.
 [OK] No errors            (PHPStan level 5)
lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)
```

One PHPCS violation was introduced (`SpacingAfterParamType` on a new docblock) and
was **fixed in the code** (alignment), not by touching the baseline. The totals
went *down* (2776 → 2775). **No PHPStan baseline entry was added or relaxed, no
lint rule weakened, no test expectation weakened** — with the single, deliberate,
documented exception in §C (`test-source-fetch-failure.php`, the 502
reclassification).

Evidence: `lint.txt`.

### J.2 `./scripts/run-tests.sh`

| Layer | G1–G3 baseline | After G4 | Change |
|---|---|---|---|
| In-process PHP suites | 87 total, 58 passed, 29 failed | **90 total, 61 passed, 29 failed** | +3 suites, **all pass**, same 29 failures |
| Script-contract suites | 18 total, 17 passed, 1 failed | **18 total, 17 passed, 1 failed** | unchanged |
| HTTP acceptance suites | 4 total, 3 passed, 1 failed | **4 total, 3 passed, 1 failed** | unchanged |
| `plugin/conexao-event-importer` | 19 suites | **22 suites, 0 failures** | +3 |

**`plugin/conexao-event-importer`: 22/22 suites pass**, including all three new
G4 suites, discovered automatically by the `test-*.php` convention — no runner
change and no hardcoded list.

### J.3 A pre-existing gate this task fixed

`verify-i18n-freshness.py` was **already failing at HEAD before any G4 change**
(proven by stashing all G4 work and re-running: `3 passed, 1 failed`, the same
27,720 s lag). The G1–G3 commit changed translatable strings without
regenerating the catalogue. G4 added more strings, so regenerating it with
`./scripts/i18n-make-pot.sh` was required — and doing so **fixed a pre-existing
failure**:

```
before:  3 passed, 1 failed   (status: fail)
after:   3 passed, 0 failed   (status: pass)
```

`verify-stage8-release-consistency.py` also failed transiently because `dist/`
held a stale local build from another branch; rebuilt with
`./scripts/build-plugins-zip.sh` → **45 passed, 0 failed**.

Registry drift gate: `php scripts/generate-registry-docs.php --check` →
`registry OK: 10 plugins validated, 19 generated regions current (zero writes)`.

---

## K. Test-state safety (§14)

All three new suites call `test_options_isolate()` on every operator-state
option they touch and restore it verbatim, with the G3 shutdown safety net:

| Suite | Options isolated |
|---|---|
| `test-request-reuse.php` | history, log, sources |
| `test-request-retry-policy.php` | history, log, sources |
| `test-request-budget.php` | history, log, sources, source_health |

`test-request-budget.php` creates a real `event` post and a real probe source,
then **deletes both** before finishing; the G3 log-retention suite remains green
(55 passed, 0 failed) after the whole G4 suite set ran.

Live diagnostic counts were checked after the full G4 run: the log holds 1,283
entries. **98 lack a `run_id`** — all of them legacy entries whose `source_id`
field is itself absent (pre-dating the G3 schema), from the Mondello Park and
IVVCC handlers, which G3 deliberately did not cover. **All 27 new
"Request accounting" entries written by G4 carry a `run_id`.** This is a
pre-existing gap, reported, not introduced.

---
## L. Known pre-existing failures (not introduced by G4)

| Suite | Cause |
|---|---|
| 18 `conexao-translation-automation` + 4 `conexao-translation-rollout` + 1 `conexao-en-translation` | `insufficient data` / missing stage plugins in this local image |
| 7 `theme/conexao-br-irlanda` | `insufficient data: polylang`, missing EN stages |
| `tests/scripts/verify-stage14-secret-scan.py` | host-environment artifact: the PHP runtime scanner cannot execute when the runner runs on the host; fails identically at baseline |
| `tests/acceptance/verify-event-occurrence-http.py` | byte-identical output at baseline (Laois archive fixture counts) |

The failing set is **unchanged** from the G1–G3 baseline except that
`test-error-handling.php` stayed green and the i18n gate went from red to green.

Two additional limitations, carried forward:

1. **98 legacy log entries still lack a `run_id`** (§K). Pre-existing; G3 scoped
   `run_id` to Eventbrite, Heritage Week and source health only.
2. **40 of 56 sources still fail** against Eventbrite's WAF rate limiting. That
   is the truth the G1 work exposed, unchanged by G4.

---

## M. Invariants (§7) — none regressed

| Invariant | Proof |
|---|---|
| `failed fetch != empty success` | G1 suite 138/138; a non-2xx is never cached; Import History shows **0** `found=0`+`success` rows |
| `incomplete walk != complete walk` | G2 suite 24/24; G4C exhaustion reports `complete=false` |
| `partial data != permission to mark missing` | G2 guard + G4C cross-stage test **with its control**; the ceiling-removal mutation reproduces `source_not_found` |
| `retry != unbounded retry` | G4B: `MAX_RETRIES = 3`, 60 s `Retry-After` cap |
| `cache hit != stale cross-run data` | G4A: store reset per run; nothing persisted; separate-run test |
| `request budget != silent data loss` | G4C: exhaustion → incomplete, fetched events retained |
| `diagnostics survive tests` | G3 suites green; G4 suites isolate and restore |

---

## N. Production safety (§16)

| Check | Result |
|---|---|
| Production host contacted | **No** — every request targeted `http://localhost:8080` or the public source sites themselves |
| `https://conexaobr.ie` contacted | **No** |
| Production credentials used | **No** |
| Production import run | **No** — every run was `dry_run = true` except two explicitly-labelled *local* test runs against a throwaway probe source |
| Production events / options / Polylang / uploads modified | **No** |
| `conexao-event-importer` production status | Unchanged: `tooling`, `production: false` |
| Flutter/mobile repository | Not accessed |

```
Production writes: 0
```

---

## O. Working-tree ownership

Starting state (pre-existing, **not** touched by this task):

```
 M docs/evidence/2026-09-26-stage-l/gate.json
?? docs/evidence/2026-10-03-event-import-diagnosis/
?? docs/reports/2026-10-03-event-import-diagnosis.md
```

Final state — identical:

```
 M docs/evidence/2026-09-26-stage-l/gate.json
?? docs/evidence/2026-10-03-event-import-diagnosis/
?? docs/reports/2026-10-03-event-import-diagnosis.md
```

All G4 work is committed at `141cedb`. (`gate.json` is rewritten by the
permanent-gate runner each time `./scripts/run-tests.sh` executes; it was already
modified when this task started.)

---

## P. Rollback

```bash
# Full rollback of G4
git revert 141cedb

# Per change unit
git checkout 9ab9de3 -- wp-content/plugins/conexao-event-importer/includes/class-request-reuse.php
git checkout 9ab9de3 -- wp-content/plugins/conexao-event-importer/includes/class-request-budget.php
git checkout 9ab9de3 -- wp-content/plugins/conexao-event-importer/includes/sources/abstract-class-source.php
git checkout 9ab9de3 -- wp-content/plugins/conexao-event-importer/includes/class-eventbrite-client.php
git checkout 9ab9de3 -- wp-content/plugins/conexao-event-importer/includes/class-event-importer.php
```

Data rollback: **none required**. Every verification run was a dry run; no
event, meta, term or media was written. The only mutated state is local
diagnostic options (`conexao_event_import_history`, `conexao_event_import_log`,
`conexao_event_source_health`), which hold the genuine record of these runs.

Reverting restores the pre-G4 behaviour: un-honoured `Retry-After`, no 502/504
retry, and **unbounded** per-source request execution. It does not reintroduce
any G1–G3 defect — those fixes live in files this revert touches only at the
transport seam, and reverting them would also remove the transport seam.

---

## Q. Final status

```
G4A = PASS WITH LIMITATION
      mechanism complete, 53 assertions, negative-proofed;
      0 cache hits measured — no request-count improvement is claimed

G4B = PASS
      bounded Retry-After honoured and capped, 502/504 retry,
      401/403/404/408 never retried, ceiling of 3 preserved;
      NOT exercised by real traffic (Eventbrite sent no Retry-After)

G4C = PASS
      per-run ceiling of 400, cache hits uncharged, exhaustion -> incomplete,
      partial data preserved, mark_missing_events() not called (with control)

G4 = PASS WITH LIMITATION
```

**Why not a clean PASS?** Two of the three acceptance criteria that G4 was
explicitly asked to satisfy were not met in measurable form: G4A did not improve
the request count (it could not — there were no duplicates left), and G4B's new
`Retry-After` path was never hit by live traffic. Both limitations are reported
as measured facts rather than smoothed over. What G4 *did* deliver, and prove, is
bounded retry/backoff and bounded request execution with no regression to any
G1–G3 invariant.

**Eventbrite is not fixed.** It is an external dependency that returns rate
limits and WAF refusals; 40 of 56 sources still fail against it, truthfully.
G4 improves how the importer reuses safe responses, handles bounded
retry/backoff, and limits runaway request execution.