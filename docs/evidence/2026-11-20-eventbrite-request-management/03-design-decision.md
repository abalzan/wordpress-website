# 03 — Design decision (Phase 3)

**This is the operator sign-off artifact.** No code was changed to produce it.
Per the task contract, Phase 4 does not begin until the operator explicitly
approves the **Implement now** items below.

| | |
|---|---|
| **Date** | 2026-11-20 |
| **Branch** | `event-import-improvements` |
| **Baseline SHA** | `9d97ba9d571cad95415d3de7fa5aba8d5c95f654` |
| **Author** | Automated investigation (read-only) |
| **Evidence** | `01-current-request-flow.md`, `02-rate-limit-state-machine.md` |

## 0. Summary recommendation

The current request-control design is **sound and already implements every
structural protection the task brief asks about**: a bounded in-walk cooldown
ladder, a run-scoped shared breaker, a persisted per-source cross-run cooldown,
a resumable enumeration checkpoint with a strict ordering contract, a
continuation scheduler with per-source locking, and incomplete-enumeration
safety in front of absence reconciliation.

The dominant 2026-11-20 failure (463 × HTTP 429) is **provider-side rate
limiting**, not an importer defect. The importer's request volume is already
conservative (2s page delay, 400-request per-run budget, 45s per-source time
budget, 3-attempt page ceiling, 20/40/60s cooldown, shared breaker that skips
later sources after the first 429).

**One small, evidence-backed, low-risk change is justified now:** retain a
bounded, allowlisted WAF diagnostic header on HTTP 405 outcomes so the
WAF/captcha classification becomes provable from the log rather than inferred.
This is the log review's own #3 recommendation and directly addresses the task's
"make provider restrictions easier to diagnose" objective.

Everything else is **Defer** (plausible but insufficient evidence, or needs
provider/product input) or **Do not implement** (duplicates existing protection,
risks correctness, or would weaken safety).

## 1. Candidate A — Stagger Eventbrite source scheduling

**Observed problem.** Run All starts Eventbrite sources back-to-back with no
stagger. The 2026-11-20 sequence shows `eventbrite_carlow` (14:05:25) →
`cavan` (14:06:27) → `clare` (14:06:41) → `donegal` (14:07:34, the 429) →
100 sources skipped in <1s.

**Current behaviour.** Sources run sequentially in a plain `foreach`
(`class-event-importer.php:300`). The only inter-request timing is the 2s page
delay inside a walk. There is no per-source start stagger.

**Exact implementation gap.** There is **no demonstrated gap that staggering
would close.** The 429 cascade on 2026-11-20 was triggered by `donegal` after
~2.5 minutes of cumulative Eventbrite requests across four prior counties — not
by two sources starting in the same second. The shared breaker already prevents
the request storm (100 sources skipped, not 100 sources hammered). Staggering
the *start* of each source would add wall-clock time to every Run All without
evidence that it reduces 429s, because the 429 is driven by *cumulative request
rate against one upstream*, not by *inter-source start spacing*.

**Alternatives considered.**
- A per-source start delay (e.g. 30s between Eventbrite sources): rejected —
  adds ~50 minutes to a 105-source Run All with no evidence of benefit; the
  breaker already caps the damage.
- A token-bucket rate limiter across all Eventbrite sources: rejected — this is
  a larger change than the evidence supports, and the existing 2s page delay +
  400-request budget already bound the rate.

**Decision: Do not implement.** The existing sequential + breaker design
already prevents the request storm. Staggering would add runtime without
addressing the demonstrated cause (cumulative rate, not start collision).

## 2. Candidate B — Per-source and run-level request budgets

**Observed problem.** The task asks whether requests per source, per Run All,
per time window, total elapsed time, and continuation attempts are bounded.

**Current behaviour.** All five are already bounded:

| Bound | Mechanism | Value |
|---|---|---|
| Requests per run | `Conexao_Request_Budget` | 400 |
| Requests per source | Per-child budget reset (`begin_run_scope(false)`) | 400 |
| Elapsed time per source | `TIME_BUDGET_SECONDS` | 45s |
| Pages per source | `MAX_PAGES` | 50 |
| Continuation attempts | `MAX_ATTEMPTS` / `MAX_STALLED` | 40 / 4 |

**Exact implementation gap.** **None demonstrated.** The only bound not
expressed as a single number is "requests per time window" — but the 2s page
delay + 45s time budget together cap a single source at ~22 requests per walk,
and the 400-request budget caps the whole run. A separate per-window budget
would duplicate these.

**Decision: Do not implement.** Every budget the task names already exists. A
budget-exhausted source is already represented as deferred/incomplete, never as
a false success (`class-request-budget.php:22-25`,
`class-eventbrite-client.php:490`, `sources/class-eventbrite-source.php:336`).

## 3. Candidate C — Shared breaker recovery strategy

**Observed problem.** The task asks whether the breaker recovery is appropriate
for a provider-wide 429.

**Current behaviour.** The breaker is process-local, opens on the first 429,
skips all later Eventbrite sources in the same Run All, and is cleared only by
`begin_run()` at the next logical run. It does not persist, does not retry, and
does not block non-Eventbrite sources. A continuation firing in a fresh process
starts with a clean breaker (correct: new logical attempt). The per-source
cooldown (20/40/60s, persisted) provides cross-run recovery for the same source.

**Exact implementation gap.** **None demonstrated.** The breaker already:
- remains open for the rest of the Run All (provider-wide cooldown);
- prevents all Eventbrite sources from making requests during that interval;
- lets a later Run All or continuation resume safely (fresh `begin_run()`);
- does not extend/reset on repeated 429s from skipped sources (they send
  nothing);
- behaves correctly across separate Run All invocations and individual runs.

**Alternatives considered.**
- Persisting the breaker across runs: rejected — would risk a stuck-open
  breaker and duplicate the per-source cooldown's role.
- Weakening the breaker to allow N sources through: rejected — the G5
  measurement showed 24/24 remaining paths returned 429; this would cause a
  retry storm.

**Decision: Do not implement.** The breaker recovery is correct as designed.

## 4. Candidate D — Checkpoint reuse and repeated request reduction

**Observed problem.** The task asks whether continuation checkpoints reliably
prevent repeating completed pages.

**Current behaviour.** The checkpoint (`Conexao_Source_Enumeration_Checkpoint`)
records the last confirmed page, advances only after fetch→parse→merge, never
moves backwards, and is invalidated on fingerprint/schema/page-count mismatch.
A resumed walk re-walks only `OVERLAP_PAGES = 1` page below the checkpoint
(bounded, deterministic) to absorb boundary churn. A resumed walk is marked
`reconciliation_safe = false` so absence reconciliation cannot fire on the
subset.

**Exact implementation gap.** **None demonstrated.** The checkpoint already
prevents re-fetching the confirmed prefix (the G10 measurement: 31 pages / 618
events no longer re-spent). The 1-page overlap is a deliberate correctness
trade, not waste.

**Decision: Do not implement.** Checkpoint reuse is correct and already
minimises repeated requests.

## 5. Candidate E — Better 405/WAF diagnostics  ← **IMPLEMENT NOW**

**Observed problem.** The log review's #3 recommendation: HTTP 405 is
classified as WAF by the code path (`classify_page_failure()` returns `'waf'`
for 405, `sources/class-eventbrite-source.php:350`), but the **retained log
entry does not carry the `x-amzn-waf-action` header**, so the WAF/captcha
attribution is inferred from the code path, not proven from the log. The
2026-10-04 G5 report captured `x-amzn-waf-action: captcha` for the same
signature, but that header is not stored today.

**Current behaviour.** On a non-retryable status (including 405), the client
logs a generic message via `get_non_retryable_message()` and returns `''`
(`class-eventbrite-client.php:287-305`). The log entry's context contains
`url`, `http_status`, `reason`, `run_id` — but **no response headers**.
`Conexao_Import_Log::sanitize_context()` strips any key containing
`token/password/secret/authorization/cookie/session/...` (`class-import-log.php:1177-1183`),
and `add()` only keeps an allowlist of context keys
(`class-import-log.php:240-260`) — which currently has no header key.

**Exact implementation gap.** There is no mechanism to retain a bounded,
non-sensitive response header on a 405 outcome. The classification is correct
but not evidenced.

**Proposed change (minimal, surgical).**

1. **`class-eventbrite-client.php`** — in the non-retryable branch
   (`:287-305`), when the status is 405, read `x-amzn-waf-action` from the
   response and add it to the log context under a new allowlisted key
   `waf_action`. The value is a short, non-sensitive token (e.g. `captcha`,
   `challenge`, `block`); it is passed through `sanitize_text_field()` and
   bounded to a small allowlist of known values (anything else is recorded as
   `unknown`). No cookies, no authorization headers, no bodies, no request
   secrets are read or stored.

2. **`class-import-log.php`** — add `waf_action` to the `keep_keys` allowlist
   in `add()` (`:240-260`) so the value survives sanitisation.
   `sanitize_context()` already strips any key containing a secret name, so a
   header named `x-amzn-waf-action` is not at risk — but the value is validated
   against the allowlist before storage.

3. **Test** — a mocked 405 response carrying
   `x-amzn-waf-action: captcha` produces a log entry whose context contains
   `waf_action = captcha`; a 405 with no such header produces no `waf_action`
   key; a 405 with a sensitive-looking header value is not stored.

**Why this is safe.**
- 405 remains **non-retryable** (`RETRYABLE_STATUSES` unchanged). No CAPTCHA
  bypass, no retry, no new request.
- The header allowlist is bounded to one key with a known-value allowlist.
- No cookies, authorization, session IDs, CAPTCHA tokens, request secrets or
  full bodies are read or stored.
- The change is additive: it only adds a context key to an existing log entry.

**Alternatives considered.**
- Storing the full response header set: rejected — violates the task's explicit
  prohibition on sensitive headers and bodies.
- Storing the response body: rejected — explicitly forbidden.
- Changing 405 to retryable: rejected — no evidence supports it, and it would
  hammer the WAF.

**Expected effect on request volume.** **Zero.** This is a logging change only;
it sends no additional requests and changes no retry/cooldown behaviour.

**Risks and mitigations.**
- Risk: a future WAF header name leaks a sensitive value. Mitigation: the
  value is validated against a fixed allowlist (`captcha`, `challenge`,
  `block`, `unknown`); anything else is stored as `unknown`.
- Risk: log size grows. Mitigation: one short string per 405 entry; 405s are
  rare (15 in the retained window).

**Test plan.** Add a focused test to
`test-eventbrite-rate-limit.php` (or a new `test-eventbrite-waf-diagnostics.php`)
asserting: (a) a 405 with `x-amzn-waf-action: captcha` logs `waf_action=captcha`;
(b) a 405 without the header logs no `waf_action`; (c) a 405 with a
sensitive-looking value is not stored verbatim; (d) 405 remains non-retryable
(no second request).

**Rollback.** Revert the two-file change; the log context simply loses the
`waf_action` key. No data migration, no option change, no behaviour change.

**Decision: Implement now** — pending operator sign-off.

## 6. Candidate F — Transport error handling

**Observed problem.** The task asks whether DNS/cURL errors are retryable, how
they interact with the breaker, and whether transient transport failures cause
unnecessary repeated discovery requests.

**Current behaviour.** A `WP_Error` from `wp_remote_get` is classified as
`transport_error` (or `budget_exhausted` for the budget error code)
(`class-eventbrite-client.php:258-285`). It is retried up to `MAX_RETRIES = 3`
with exponential backoff (2/4/8s). A transport error does **not** open the
shared breaker (only a 429 does). It does record a per-source cooldown with
reason `network` (`class-event-importer.php:2559`, `class-source-cooldown.php:96`).

**Exact implementation gap.** **None demonstrated.** Transport errors are
already:
- retryable (3 attempts, bounded backoff);
- kept separate from the 429 and 405 categories (distinct `reason` values);
- not merged into the breaker (a DNS failure does not skip later Eventbrite
  sources — correctly, because it is a local/transient condition, not a
  provider-wide rate limit);
- recorded as a per-source `network` cooldown so the next run does not
  immediately re-send.

The 2026-11-14 DNS failures (cURL error 6) were isolated to one day and did not
recur; the log review concluded they were a transient local DNS issue.

**Decision: Do not implement.** Transport handling is correct and already
independent of the 429/405 categories.

## 7. Decision summary

| Candidate | Decision | Rationale |
|---|---|---|
| A. Stagger source scheduling | **Do not implement** | Breaker already prevents the storm; 429 is cumulative-rate, not start-collision |
| B. Per-source / run budgets | **Do not implement** | All five bounds already exist; exhaustion is already incomplete/deferred |
| C. Breaker recovery | **Do not implement** | Recovery is correct: process-local, cleared per logical run, composed with per-source cooldown |
| D. Checkpoint reuse | **Do not implement** | Already prevents prefix re-fetch; 1-page overlap is a correctness trade |
| **E. 405/WAF diagnostics** | **Implement now** | Bounded, allowlisted `waf_action` header on 405; makes WAF attribution provable; zero request impact |
| F. Transport errors | **Do not implement** | Already retryable, independent, and cooldown-recorded |

## 8. What this means for the operator

- **No change to request volume, retry behaviour, cooldown, breaker, checkpoint
  or continuation.** The importer's request-control design is already complete
  and correct for the demonstrated failure modes.
- **One small logging change** (Candidate E) makes the 405/WAF classification
  provable from the log instead of inferred. It is additive, bounded and
  secret-free.
- **The dominant 429 problem is provider-side.** The importer cannot eliminate
  Eventbrite's rate limit; it can only (and already does) avoidable-request
  minimisation, safe recovery coordination, and diagnosability. The operational
  recommendations in the log review (pause Run All while rate-limited; retry
  the 3 stalled sources manually) remain the correct immediate actions.

## 9. Explicit non-goals

- No concurrency, no stagger, no new scheduler, no new CPT, no new admin page.
- No change to the 20/40/60s ladder, the shared breaker, the per-source
  cooldown, the checkpoint, or the continuation scheduler.
- No CAPTCHA/WAF bypass, no retry of 405, no change to provider request headers.
- No production writes, no live provider requests, no commits, no deploy.
- No new event data, source activation, or registry changes.

---

**Operator sign-off**

- [ ] **Approve** Candidate E (405/WAF diagnostics) — proceed to Phase 4.
- [ ] **Decline** — record the decision and stop; no code change.

If approved, Phase 4 implements **only** Candidate E, then Phases 5–7
(tests, report, final safety verification) run. If declined, Phases 6–7 run
with this design note as the record and no code change.