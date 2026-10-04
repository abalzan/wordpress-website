# Report — Event Import G5: Eventbrite Rate-Limit Strategy

| | |
|---|---|
| **Stage / task name** | Event Import G5 — G5A pacing policy, G5B 429 cooldown state, G5C fail-fast, G5D diagnostics, G5E source-wide cooldown, G5F sequential execution |
| **Date** | 2026-10-04 |
| **Branch** | `master` |
| **Start SHA** | `1a382c45180a9bf97b624a4a4623b8cdb8511ddd` |
| **Predecessor** | [G4 — Request Efficiency, Backoff and Budgets](2026-10-03-event-import-g4.md) · [G1–G3](2026-10-03-event-import-fixes-g1-g3.md) |
| **Evidence** | [`docs/evidence/2026-10-04-eventbrite-rate-limit-strategy/`](../evidence/2026-10-04-eventbrite-rate-limit-strategy/) |
| **Plugin version** | `conexao-event-importer` 1.8.0 → **1.9.0** |
| **Production writes** | **0** |

---

## A. Executive summary — including the finding that blocks the headline experiment

G5 delivers a centralised Eventbrite pacing and rate-limit policy, proven by
**73 deterministic assertions** and **10 negative proofs**, with **zero new
test-suite failures** against the G4 baseline.

**But the headline live experiment could not be run, and that is reported
first rather than buried.** When G5 started, Eventbrite stopped serving its
discovery pages to this environment entirely:

```text
GET https://www.eventbrite.ie/d/ireland--cork/all-events/
→ HTTP 405,  x-amzn-waf-action: captcha        (CloudFront / AWS WAF)
```

This is a **WAF captcha challenge, not the HTTP 429 rate limit**. It is a
different condition, it began before any G5 code was written, and it affects
every county uniformly. A spaced probe (`upstream-reachability-probe.txt`,
51 observations over ~40 minutes) returned **51/51 × HTTP 405 with
`x-amzn-waf-action: captcha`, and zero 429s**. Cork, Dublin and Carlow were
all blocked identically.

| Unit | Verdict |
|---|---|
| **G5A** pacing policy | **PASS WITH LIMITATION** (mechanism + tests); live high-volume confirmation **BLOCKED** by the WAF |
| **G5B** 429 handling | **PASS** (mechanically); `Retry-After` still **not live-observed** |
| **G5C** incomplete safety | **PASS** |
| **G5D** diagnostics | **PASS** — and it fixed a real pre-existing defect (§I) |
| **G5E** operational stability | **PASS WITH LIMITATION** — cross-source scope decided on **archived real 429 evidence**, not a live G5 run |

```text
G5 = PASS WITH LIMITATION
```

> **Eventbrite is not "fixed".** It is an external dependency that can
> rate-limit *and* WAF-block the importer. G5 makes the importer respond to
> those conditions in a bounded, sequential, truthful and operationally
> recoverable manner. It does not remove them.

---

## B. Fresh G4 baseline (§4) — what the committed tree actually did

Measured **before** any G5 code, from the committed G4 state's own Import Log
(`exp-a-cork-g4-log-baseline.txt`, the 2026-10-04 08:16 run):

```text
page_count=49  object_count=1553

08:16:10  page 1 fetched
08:16:12  page 2      (Δ 2 s)
08:16:16  page 3      (Δ 4 s)
08:16:19  page 4      (Δ 3 s)
08:16:22  page 5      (Δ 3 s)
08:16:25  page 6      (Δ 3 s)
08:16:28  page 7      (Δ 3 s)
08:16:29  page 8 → HTTP 429
08:16:31  retry 1  → HTTP 429   (Δ 2 s)
08:16:35  retry 2  → HTTP 429   (Δ 4 s)
08:16:44  page 8 given up after 3 attempts
08:16:45  page 9 → HTTP 429
08:16:47  retry 1  → HTTP 429   (Δ 2 s)
08:16:51  retry 2  → HTTP 429   (Δ 4 s)
08:16:59  time budget (45 s) expired after page 9/49

result: 140 fetched / 140 unique / 122 created / 1 updated
        source INCOMPLETE — events absent from this walk NOT marked source_not_found
        13 outbound requests, 0 reused, 0 budget blocks, budget limit 400
```

**The diagnostic-first reading (§6) is unambiguous.** The pre-G5 walk did
exactly:

```text
page N → immediate page N+1 → immediate page N+2
429 → 2 s → 429 → 4 s → 429 → next page → 429 → 2 s → 429 → 4 s → 429
```

Two 2-second/4-second retries were **not** enough against a real Eventbrite
429, and the walk then kept paginating into a source that was refusing
everything. The walk only stopped because the **45-second wall clock** ran out —
not because the importer recognised rate limiting. That is the defect G5 fixes.

### B.1 Why this is not budget exhaustion

The run consumed **13 of 400** requests with **0 blocked**. Budget was never
the constraint. This confirms the brief's premise and is the reason G5 does
not touch the request budget.

---

## C. G5E measurement — is the rate limit page-specific or source-wide?

This is the question §14 requires evidence for before any cross-source cooldown
may be introduced. It was answered from the **archived real 429 trace** of the
2026-10-03 full run (`exp-g5e-cross-source-analysis.txt`,
`full-run-request-trace.log`):

```text
eventbrite_requests=108          status_distribution={200: 36, 429: 72}
healthy_same_page_interval      min=2.45  median=2.85  max=4.54 seconds
first_429_request_index         36   (the 37th Eventbrite request)
first_429_elapsed_seconds       329.87
requests_before_first_429       36
pages_fetched_before_first_429  3
first_429_path                  /d/ireland--clare/all-events/
first_429_page_attempts         3
first_429_retry_intervals       [2.13, 4.59] seconds

distinct_paths_after_first_429  24
paths_100pct_429_after_first_429 24/24      ← every subsequent path, every attempt
total_429                       72
retry_after_headers_observed    0
```

**Conclusion: the rate limit is source-wide, not page-specific.** Once the
first 429 appeared, **all 24 remaining Eventbrite discovery paths answered 429
on every single attempt.** Cork → Donegal → Dublin → Galway → Kerry → … was
not 24 independent problems; it was **one problem asked 24 times**, producing
72 wasted 429s.

This measurement is the *sole* justification for the run-scoped shared cooldown
in `Conexao_Eventbrite_Pacing`. It is not an assumption.

### C.1 Live confirmation was not possible

A live Cork → Dublin cross-source test (§19 Experiment D) was attempted in both
orders and both were blocked by the WAF:

```text
ORDER: eventbrite_cork → eventbrite_dublin
  cork   : 1 outbound request, HTTP 405 (WAF captcha), status=failed complete=false
  dublin : 1 outbound request, HTTP 405 (WAF captcha), status=failed complete=false
ORDER: eventbrite_dublin → eventbrite_cork   (identical)
```

So the G5E conclusion rests on **archived real 429 evidence**, and the live G5E
run is **BLOCKED**. This is stated as a limitation, not smoothed over.

---

## D. Pacing measurements and how the delay was chosen (§8)

| Fact | Measured value | Source |
|---|---|---|
| Healthy inter-request interval (G4) | min 2.45 s, **median 2.85 s**, max 4.54 s | archived full-run trace |
| Healthy request latency | ≈ 1.9 s (interval minus the 1 s delay) | derived |
| Sustained rate at first 429 | ≈ 20 requests/minute | derived |
| Requests before first 429 | **36** | archived trace |
| Retry intervals on a 429 page | **2.13 s, 4.59 s** — both insufficient | archived trace |
| `Retry-After` headers ever observed | **0** | every run to date |

The G5 policy (`PAGE_DELAY_SECONDS = 2`, cooldown 20 s → 40 s, capped 60 s) is
the **smallest conservative step the measurements support**:

- **Page delay 1 s → 2 s.** The old floor plus ~1.9 s latency produced a median
  2.85 s interval and a first 429 at ~20 req/min. Doubling the floor materially
  reduces the sustained rate while keeping a 49-page walk inside a realistic
  operator session. This is a measured, minimal step — **not** a guessed
  multi-minute stall, and not an invented "safe" number.
- **Cooldown 20 s → 40 s.** The measured 2 s and 4 s retries both failed against
  a real 429. Rate limiting therefore gets its own ladder rather than reusing
  the transient-failure schedule. Bounded at 60 s so Eventbrite can never
  dictate the process length.

**The 2-second figure is a policy default, not a measured optimum.** No run in
this stage could confirm it against live Eventbrite traffic, because upstream
was WAF-blocked throughout. It is documented as such.

---

## E. Exact files changed

| File | Change |
|---|---|
| `includes/class-eventbrite-pacing.php` | **NEW.** The single pacing/rate-limit policy: page pacing, cooldown ladder, `Retry-After` capping, run-scoped shared cooldown, injectable sleeper + clock. |
| `includes/class-eventbrite-client.php` | 429 becomes a **distinct** branch (its own cooldown, its own classification, its own diagnostics); delegates page pacing to the policy; forwards `set_sleeper()` to the policy; `get_applied_delays()` now reports the true unified record. |
| `sources/class-eventbrite-source.php` | Uses the shared pacing policy; **fail-fast** on a rate-limited page (`break`, not `continue`); refuses to start a walk when the run is already rate limited; time budget measured through the policy clock. |
| `includes/class-event-importer.php` | `begin_run_scope()` resets the Eventbrite rate-limit scope per run (6 lines). |
| `includes/class-import-log.php` | **G5D fix:** the diagnostic allowlist was silently discarding every rate-limit field (`reason`, `page`, `cooldown_seconds`, …). See §I. |
| `tests/test-eventbrite-rate-limit.php` | **NEW.** 73 assertions. |
| `tests/test-request-retry-policy.php` | Updated 429 expectations to the G5 cooldown; injected sleepers so the suite observes rather than endures waits. |
| `tests/test-source-pagination-completeness.php` | Injected the pacing sleeper + new filter name (see §M.2). |
| `conexao-event-importer.php` | Load the new class; version 1.8.0 → 1.9.0. |
| `docs/plugins/conexao-event-importer.md` | Full G5 operating-model documentation. |
| `docs/architecture.md`, `docs/project-inventory.md`, `docs/plugins/README.md` | Regenerated registry regions (version bump). |

No engine rewrite, no second HTTP client, no change to `Conexao_Eventbrite_Client`'s
identity, no translation/UI/mobile changes, no production changes.

---

## F. G5A — pacing

**PASS WITH LIMITATION.**

- Pacing is explicit and centralised in `Conexao_Eventbrite_Pacing`. No `sleep()`
  is scattered through handlers; the handler calls `$pacing->pace_page_request()`
  and the client calls `$pacing->apply_rate_limit_cooldown()`.
- Deterministic tests prove the timing policy with an injected sleeper and an
  injected clock — **no test sleeps**.
- Cork stress testing demonstrates the policy under a high-volume source only
  in the deterministic harness (49-page fixture); the **live** Cork run is
  blocked by the WAF (§A).
- Dublin exists as a configured source (`eventbrite_dublin`) and was tested; the
  live run is likewise blocked.
- Pacing does not bypass the request budget or the time budget (§K).

## G. G5B — 429 handling

**PASS (mechanically). `Retry-After` remains NOT live-observed.**

- 429 is distinctly handled: its own branch, its own state, its own message,
  its own classification (`rate_limited`), distinct from `http_error`.
- The attempt ceiling is **unchanged** (`MAX_RETRIES = 3`) — proven by a mutation
  that removes the ceiling's effect.
- `Retry-After` honoured when present (`7` → `7.0 s` exactly).
- `Retry-After` **hard-capped** at 60 s (`999999` → `60`).
- Repeated 429s cannot create a tight loop: every retry is preceded by a
  non-zero cooldown, asserted directly.

**Retry-After evidence (§11):** the G5 harness records
`responses_with_retry_after` on every probe. Every measured value to date is
**0** — from the G4 runs and from the G5 runs. **No `Retry-After` header has ever
been observed in a real Eventbrite response from this environment.** The
`Retry-After` path is therefore classified:

```text
mechanically proven, but NOT live-observed
```

Synthetic evidence has not been presented as live evidence.

## H. G5C — incomplete safety

**PASS.**

- Rate-limited termination is `incomplete` — asserted.
- Partial data is preserved — asserted (`count($events) > 0` after the stop).
- `mark_missing_events()` is not called for an incomplete walk — asserted through
  `is_fetch_complete() === false`, and **mutation M5** proves it: forcing the
  rate-limited walk to report complete makes the suite fail.
- Idempotent retry: the existing G1–G4 dedup path is unchanged, and the
  deterministic walk test asserts the fetched events survive intact.

## I. G5D — diagnostics

**PASS — and this stage fixed a real pre-existing defect.**

While implementing, the G5D assertions failed against the **existing** log
layer. Root cause: `Conexao_Import_Log::add()` writes a structured entry, then
rebuilds it through a **hard-coded allowlist**:

```php
$keep_keys = array( 'url', 'attempt', 'source_url', 'post_id' );
```

Every field G5 needed — `reason`, `page`, `cooldown_seconds`, `retry_after`,
`rate_limit_hits`, `skipped` — was **written and then silently discarded**.
Pre-G5 diagnostics could never have distinguished rate limiting from generic
failure, because the distinguishing information never survived.

The allowlist was extended, and a negative proof (**M10**) re-removes it and
shows the suite failing. Diagnostics are otherwise preserved: `run_id`
correlation is asserted on every entry.

## J. G5E — operational stability

**PASS WITH LIMITATION.**

- Eventbrite sources execute **sequentially** — proven by an in-flight
  concurrency counter (max observed = 1) and by the absence of any parallel HTTP
  primitive in the handler.
- Repeated rate limiting does not create uncontrolled traffic: the fail-fast
  mutation shows the *unprotected* walk issuing **118 requests and walking 33
  pages** past the point where it should have stopped, versus **4 requests**
  with G5.
- Individual Eventbrite sources remain independently recoverable — recovery
  needs no 56-source rerun (§18 operating model documented).
- **Limitation:** the cross-source scope decision rests on the archived real 429
  trace (§C), not on a live G5 measurement, because upstream was WAF-blocked.

### J.1 Source-level recovery (G5G)

The existing per-source execution controls are used, not extended. `run_source()`
re-opens the rate-limit scope via `begin_run_scope()`, so a single county can be
retried on its own immediately. No second importer, no new runner.

### J.2 Avoiding tight operator retry loops (G5H)

There is **no permanent block and no scheduler**. Instead:

- the skip is **visible and classified** (`reason=rate_limited`, `skipped=true`,
  plus the cooldown minutes in the message);
- the run-scoped skip list records which sources were not attempted;
- every new run starts clean.

This satisfies "protect the operator without permanently blocking them" and the
brief's explicit instruction not to create a database-backed rate-limit queue.

---

## K. Request-budget and time-budget interaction (§21, §22)

G5 does not weaken G4C. The budget remains **400**.

| Property | Proof |
|---|---|
| 429 retries **consume** outbound request budget | 3 retries → `budget.used == 3` (asserted) |
| Cooldown consumes **no** request budget | cooldown total (120 s) is accounted separately; `blocked == 0` (asserted) |
| The budget is **not reset** after a 429 | no reset exists in the rate-limit path |
| Cooldown cannot circumvent the ceiling | cooldown sends no request; `consume()` is the only path that spends budget |
| Budget exhaustion → incomplete → no `source_not_found` | unchanged G4C path, suite green |
| Time budget survives a cooldown | a 10 s budget + a 20 s cooldown ends **incomplete** with events preserved (asserted) |

---

## L. Negative proofs (§26) — all 10 mutations fail

`negative-proofs.txt`. **A mutation that passed unchanged would have been
vacuous and is reported as such.**

| # | Mutation | Result |
|---|---|---|
| M1 | Remove pacing (2 s → 0 s) | **FAILS** (3 assertions) |
| M2 | Remove repeated-429 fail-fast (`break` → `continue`) | **FAILS** — 118 requests / 33 pages vs 4 |
| M3 | Remove rate-limit classification | **FAILS** |
| M4 | Allow immediate retry after 429 (cooldown → 0) | **FAILS** (5 assertions) |
| M5 | Mark the rate-limited walk complete (`source_not_found` risk) | **FAILS** (2 assertions) |
| M6 | Remove shared Eventbrite cooldown | **FAILS** (4 assertions) |
| M7 | Remove the `Retry-After` hard cap | **FAILS** (2 assertions) |
| M8 | Remove the distinct 429 branch entirely | **FAILS** (suite dies / 4 assertions) |
| M9 | Stop recording the run-wide rate limit | **FAILS** (2 assertions) |
| M10 | Discard the rate-limit diagnostic fields | **FAILS** (3 assertions) |
| — | RESTORED | 73 passed, 0 failed |

### L.1 Four mutations were initially VACUOUS, and were fixed

This is reported because §26 requires it:

1. **M2 / M5 were vacuous at first.** The 45-second time budget ended the walk
   *before* page 3 could be requested, so the fail-fast assertions passed for the
   wrong reason. Fixed by giving the walk a deliberately huge budget and adding
   an explicit **control** assertion proving the walk was not ended by
   exhaustion. Both now fail loudly under mutation.
2. **M3 was vacuous.** "At least one entry is classified `rate_limited`"
   survived a mutation that relabelled only one entry. Replaced with "**every**
   diagnostic from an all-429 fetch is classified `rate_limited`".
3. **M7 was vacuous.** The client parser already capped `Retry-After` at the
   same 60 s, so removing the pacing cap changed nothing observable. Replaced
   with a **direct** assertion on the policy using a value the parser never
   produces (`86400`, `61`).

---

## M. Regression verification (§27)

```text
./scripts/lint.sh        → lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)
./scripts/run-tests.sh   → see table below
php scripts/generate-registry-docs.php --check → registry OK (zero writes)
```

| Metric | G4 baseline | G5 | Delta |
|---|---:|---:|---|
| In-process suites | 90 (61 pass / 29 fail) | **91 (62 pass / 29 fail)** | +1 new suite, **0 new failures** |
| Assertions | 3566 passed / 4 failed | **3640 passed / 4 failed** | **+74** |
| Script-contract suites | 18 (17/1) | 18 (17/1) | unchanged |
| HTTP acceptance suites | 4 (3/1) | 4 (3/1) | unchanged |
| **Failing suites (set difference G5 − G4)** | — | — | **EMPTY** |

### M.1 Pre-existing failures — proved to pre-date G5

All 31 failing suites are **identical** to the G4 baseline
(`regression-vs-g4.txt`). Verified by `LC_ALL=C comm -23 g5-fails g4-fails`
returning nothing. Representative pre-existing failures (all translation/theme,
none Eventbrite):

```text
plugin/conexao-translation-automation/*   (18 suites)
plugin/conexao-translation-rollout/*      (4 suites)
plugin/conexao-en-translation/test-shared-slug-newsletter.php
theme/conexao-br-irlanda/test-*           (6 suites)
tests/scripts/verify-stage14-secret-scan.py
tests/acceptance/verify-event-occurrence-http.py
```

### M.2 Two regressions I introduced and fixed (reported, not hidden)

1. **`test-source-pagination-completeness.php` broke** (23/24). The suite injected
   its waits through `conexao_eventbrite_request_delay_seconds`; G5 renamed the
   pacing filter and added a longer 429 cooldown, so the suite **really slept
   123 s** and its state was wrong. Proved pre-existing-green by running it at
   the stashed pre-G5 tree (**24 passed in 1 s**). Fixed by injecting the pacing
   policy; now **24 passed in 1 s** — exactly the baseline.
2. **`verify-stage8-release-consistency.py` broke** after the version bump
   (`zip=1.8.0 source=1.9.0`). Fixed the correct way — by rebuilding through
   `./scripts/build-plugins-zip.sh` + `build-theme-zip.sh` — **not** by editing
   a baseline. Now 47/0.

### M.3 A real defect found by the G5D work

`Conexao_Import_Log` silently discarded every rate-limit diagnostic field (§I).
Fixed and mutation-proven.

---

## N. Import History verification (§28)

`import-history-verification.txt`, `import-history-eventbrite-rows.txt`.

```text
source=eventbrite_dublin  status=failed  found=0  complete=false
source=eventbrite_cork    status=failed  found=0  complete=false
source=eventbrite_dublin  status=failed  found=0  complete=false
...
never-success-after-failure: CONFIRMED (0 rows show success + 429)
```

A genuine upstream failure is represented as **`failed` / `complete=false`**,
**never** as `found=0, success`. Partial runs remain non-destructive: an
incomplete walk never calls `mark_missing_events()`, so previously imported
events are untouched (mutation M5 proves the guard).

---

## O. Full local run (§29)

The full 56-source local dry run was **not re-run in this stage**. It was
deliberately skipped: upstream was WAF-blocked for the entire session, so a full
run would have measured the WAF, not G5. Running it would have produced a number
that looked like a result while measuring nothing about rate limiting.

What *was* measured live, every source, both orders (§C.1):

```text
Cork   : 1 outbound request → HTTP 405 (WAF captcha) → failed, incomplete
Dublin : 1 outbound request → HTTP 405 (WAF captcha) → failed, incomplete
Carlow : 1 outbound request → HTTP 405 (WAF captcha) → failed, incomplete
```

**Attribution (§29's requirement).** Comparing numbers here would be
dishonest: post-G4 and G5 runs are not comparable because the upstream condition
changed from *429 rate limiting* to *WAF captcha refusal*. No numerical
difference is attributed to G5. The G5 contribution is measured where it *is*
comparable — in the deterministic harness (requests/pages/429s/completion) and in
the unchanged test-suite baseline.

---

## P. Production prohibition (§30)

All work was local, against `http://localhost:8080`. No production URL was
contacted; no production credential was used or read; no production content,
options or events were touched.

```text
Production writes: 0
```

## Q. No bypassing of upstream controls (§24)

G5 does not rotate IPs, introduce proxies, spoof client identities, bypass WAF
controls, add randomised evasion, use undocumented credentials, scrape through
another service or create an external broker. The importer is deliberately made
**more** conservative: slower, sequential, bounded, and respectful of 429. The
WAF block observed in this stage was documented as an upstream condition and
**not** worked around — the spaced probe deliberately used one request every two
minutes per URL rather than probing harder.

---

## R. Files, evidence and working tree

```text
New:
  wp-content/plugins/conexao-event-importer/includes/class-eventbrite-pacing.php
  wp-content/plugins/conexao-event-importer/tests/test-eventbrite-rate-limit.php
  docs/reports/2026-10-04-eventbrite-rate-limit-strategy.md
  docs/evidence/2026-10-04-eventbrite-rate-limit-strategy/

Evidence:
  exp-a-cork-g4-log-baseline.txt          Cork G4 baseline (the §B trace)
  exp-b-d-dubin-and-cross-source.txt      Dublin baseline + Experiment D, both orders
  exp-g5e-cross-source-analysis.txt       24/24 source-wide 429 analysis
  upstream-reachability-probe.txt         51× HTTP 405 / WAF captcha over ~40 min
  g5-suite-output.txt                     73 assertions, 0 failed
  negative-proofs.txt                     10 mutations, all failing
  lint.txt                                lint OK
  run-tests.txt                           full suite result
  regression-vs-g4.txt                    failure-set diff vs G4
  import-history-verification.txt         §28 verification
  import-history-eventbrite-rows.txt     §28 history rows
  verification-scripts/                   every harness used, re-runnable
```

### R.1 Local diagnostic state changed (not production, not content)

While running the suites, the local test fixtures deactivated all 55 configured
sources in the `conexao_event_sources` option. This is **pre-existing** local
diagnostic behaviour of the G2B suite (it calls `sources->save()`/`delete()`),
observed before G5 and not caused by it. Sources were reactivated afterwards so
the experiments could run; `conexao_event_import_history`,
`conexao_event_import_log` and `conexao_event_source_health` retain the genuine
record of these runs. **No event, meta, term or media was written** — every run
was a dry run except the G2B suite's own controlled fixture, which creates and
deletes its own probe post.

---

## S. Rollback

```bash
# Full rollback of G5
git revert <G5-code-sha>

# Per file
git checkout 1a382c4 -- wp-content/plugins/conexao-event-importer/includes/class-eventbrite-client.php
git checkout 1a382c4 -- wp-content/plugins/conexao-event-importer/includes/sources/class-eventbrite-source.php
git checkout 1a382c4 -- wp-content/plugins/conexao-event-importer/includes/class-event-importer.php
git checkout 1a382c4 -- wp-content/plugins/conexao-event-importer/conexao-event-importer.php
rm wp-content/plugins/conexao-event-importer/includes/class-eventbrite-pacing.php
rm wp-content/plugins/conexao-event-importer/tests/test-eventbrite-rate-limit.php
# then rebuild the release record so release consistency stays green:
./scripts/build-plugins-zip.sh && ./scripts/build-theme-zip.sh
```

Data rollback: **none required.** Every verification run was a dry run. Reverting
restores the pre-G5 behaviour: 1-second page pacing, 2/4-second retries on 429,
and a walk that keeps paginating past a rate-limited page. It removes no G1–G4
guarantee — the incomplete-walk guard, the request budget and `Retry-After`
capping all remain.

---

## T. Final status (§33)

```text
G5A = PASS WITH LIMITATION
      one explicit centralised policy, 73 deterministic assertions,
      no ad-hoc sleeps, budgets intact;
      live Cork/Dublin confirmation BLOCKED by the upstream WAF captcha

G5B = PASS
      429 distinctly handled, ceiling of 3 preserved, cooldown bounded,
      Retry-After honoured and hard-capped, no tight loop possible;
      Retry-After remains MECHANICALLY PROVEN BUT NOT LIVE-OBSERVED
      (0 real headers ever seen)

G5C = PASS
      rate-limited termination is incomplete, partial data preserved,
      mark_missing_events() not called, retry idempotent, M5 mutation-proof

G5D = PASS
      rate_limited distinguishable from upstream_unavailable / http_error /
      transport_error / budget_exhausted; URL, page, attempt, cooldown,
      retry_after presence/cap recorded; run_id correlated;
      fixed the pre-existing allowlist defect; M10 mutation-proof

G5E = PASS WITH LIMITATION
      sequential execution proven (max 1 in flight), fail-fast proven
      (118 requests -> 4 under mutation), per-source recovery intact;
      cross-source scope decided on ARCHIVED real 429 evidence
      (24/24 paths 100% 429), live G5E run BLOCKED by the WAF

G5 = PASS WITH LIMITATION
```

**Why not a clean PASS.** The brief's central success criterion is comparative:
*does the new pacing materially reduce repeated-429 probability on the
high-volume Cork/Dublin sources?* That comparison requires live Eventbrite
traffic. For the entire G5 window, Eventbrite served `HTTP 405 /
x-amzn-waf-action: captcha` to every discovery URL (51/51 probes), so no live
paced run could be compared against a live unpaced run. The pacing numbers are
therefore **derived from the archived 429 measurements and mechanically proven,
not confirmed live**. That is reported as measured fact rather than smoothed
over.

> Eventbrite is an external dependency that can rate-limit and WAF-block the
> importer. G5 makes the importer respond to that condition in a bounded,
> sequential, truthful and operationally recoverable manner. **Eventbrite is
> not fixed.**
