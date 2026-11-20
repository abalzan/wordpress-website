# 02 — Rate-limit state machine

The breaker / cooldown / Retry-After behaviour, as implemented. This is the
reference for the Phase 3 design decisions.

## 1. The three independent state layers

The importer has **three** distinct rate-limit / throttle mechanisms. They are
often conflated; they are not the same thing.

| Layer | Class | Lifetime | Keyed by | What it stops |
|---|---|---|---|---|
| **In-walk cooldown** | `Conexao_Eventbrite_Pacing` (instance) | One source walk | — | The current page's retry loop (20/40/60s) |
| **Run-scoped shared breaker** | `Conexao_Eventbrite_Pacing` (static) | One logical Run All | Source ID (first + skipped) | Later Eventbrite sources in the same Run All |
| **Cross-run source cooldown** | `Conexao_Source_Cooldown` | Persisted option | Source ID | The same source on the next Run All |

A fourth mechanism, the **continuation scheduler**
(`Conexao_Import_Continuation`), owns *cross-run retry timing* (30 min base,
escalating) and is the only one that persists a timer.

## 2. State transitions (confirmed)

### 2.1 In-walk cooldown (per page)

```
fetch_page(url, page)
  └─ for attempt = 1..MAX_RETRIES (3):
       ├─ request_page()
       │    ├─ 200 ─────────────────────────────► return body   [last_failure_reason = 'ok']
       │    ├─ non-retryable (401/403/404/408) ─► log error, return ''   [http_error]
       │    ├─ 429:
       │    │    ├─ ++consecutive_rate_limits
       │    │    ├─ record_run_rate_limit(source)        ← opens the SHARED breaker
       │    │    ├─ record_run_retry_state(now + cooldown)
       │    │    ├─ cooldown = apply_rate_limit_cooldown(attempt, retry_after)
       │    │    │    └─ sleep_for(cooldown)  ── if past deadline → wait_deferred = true
       │    │    ├─ if wait_deferred: return ''           [rate_limited_deferred]
       │    │    └─ else continue (retry)
       │    ├─ 5xx (500/502/503/504):
       │    │    └─ backoff(attempt, retry_after) → continue
       │    └─ WP_Error:
       │         ├─ budget_exhausted → return ''           [budget_exhausted]
       │         └─ transport_error  → backoff → continue  [transport_error]
       └─ exhausted:
            ├─ rate_limited  → log "still HTTP 429 after 3 attempts", return ''
            └─ otherwise     → log "could not be reached after 3 attempts", return ''
```

Key facts:

- **One cooldown per 429**, not per retry storm. `apply_rate_limit_cooldown()`
  increments `rate_limit_hits` and applies exactly one bounded wait
  (`class-eventbrite-pacing.php:543-552`).
- **The ladder is 20 → 40 → 60 → 60 …** (`get_cooldown_seconds()`,
  `:479-507`). It is `min(20 * attempt, 60)`, so attempt 1 = 20s, attempt 2 =
  40s, attempt 3 = 60s.
- **Retry-After overrides the ladder** when present and parseable, clamped to
  60s (`:487-497`).
- **A deferred wait is not a failure.** If the cooldown would push past the
  source's invocation deadline (`set_wait_deadline()`), `sleep_for()` sets
  `wait_deferred = true` and sleeps **0 seconds** (`:578-580`). The source
  handler treats this as a resumable incomplete stop
  (`sources/class-eventbrite-source.php:712-727`), not a budget exhaustion.

### 2.2 Run-scoped shared breaker

```
record_run_rate_limit(source_id):
  if !run_rate_limited:
      run_rate_limited        = true
      run_rate_limited_source = source_id
  run_state.active          = true
  run_state.rate_limited_at = time()

record_skipped_source(source_id):
  run_skipped_sources[] = source_id   (deduped)

is_rate_limited():
  return run_rate_limited

begin_run():
  run_rate_limited        = false
  run_rate_limited_source = ''
  run_skipped_sources     = []
  run_state               = { active: false, ... }
  shared                  = null        ← fresh pacing instance
```

Enforcement at the top of every Eventbrite source walk
(`sources/class-eventbrite-source.php:531-561`):

```
if Conexao_Eventbrite_Pacing::is_rate_limited():
    record_skipped_source( source )
    mark_fetch_incomplete()
    log warning "Not attempted: Eventbrite already rate-limited source X ..."
    throw Conexao_Source_Fetch_Exception( reason, 429 )
```

The engine catches this at `class-event-importer.php:730-763`, classifies it as
`rate_limited` (not `failed`), and records a per-source cooldown via
`record_throttle_cooldown( $source_id, 429 )` (`:761`).

### 2.3 Cross-run source cooldown

```
record_throttled(source_id, reason):          # reason ∈ {rate_limited, waf, server_error, network}
  if reason ∉ THROTTLED_REASONS: return      # 404/parse → NOT throttled
  consecutive = (last_reason == reason) ? consecutive + 1 : 1
  step        = min(consecutive, STEPS=3)
  seconds     = min(BASE_SECONDS=20 * step, MAX_SECONDS=60)
  record      = { consecutive, last_reason, last_observed, cooldown_seconds, next_eligible = now + seconds }
  update_option('conexao_event_source_cooldown', map, false)

is_cooling_down(source_id):
  return strtotime(next_eligible) > time()

record_recovered(source_id):                 # a clean 200 clears it
  unset(map[source_id]); update_option(...)
```

Enforcement at the top of `run_source_unbuffered()`
(`class-event-importer.php:566-624`): a cooling-down source returns a `failed`
status with an explicit "Not attempted … Try again in about N seconds" message,
records an **incomplete** enumeration (`complete = false`), and sends **zero**
requests.

## 3. Retry-After decision table (confirmed)

| Header value | `kind` | `delay` | `unsupported` | Behaviour |
|---|---|---|---|---|
| `"120"` | `delta` | `120.0` → capped to `60.0` | false | 60s cooldown, `retry_after_capped = true` |
| `"7"` | `delta` | `7.0` | false | 7s cooldown |
| `"0"` | `delta` | `0.0` | false | immediate retry |
| IMF-fixdate future | `http_date` | `date − now` (capped) | false | that delay |
| IMF-fixdate past | `http_date` | `0.0` (clamped) | false | immediate retry |
| `"Wed, 01 Foo 2026 12:00:00 GMT"` | `unsupported` | `null` | **true** | run deferred, no earlier retry, error logged |
| `"soon"` | `fallback` | `null` | false | deterministic ladder |
| `""` / `"   "` | `fallback` | `null` | false | deterministic ladder |
| missing | `fallback` | `null` | false | deterministic ladder |

The `unsupported` branch is the only one that refuses to retry earlier: it
records the run rate limit, marks the run deferred, and returns `''`
(`class-eventbrite-client.php:366-381`). This is the fail-safe for a plausible
date the importer cannot parse — it will not guess a delay and retry too early.

## 4. What the breaker does NOT do (confirmed)

- It does **not** persist. `begin_run()` fully resets it; nothing is written to
  any option, transient or table (`class-eventbrite-pacing.php:38-46`).
- It does **not** retry. It only prevents *later* sources from sending requests;
  the rate-limited source itself stops after its 3 in-walk attempts.
- It does **not** block non-Eventbrite sources. The check is inside
  `Conexao_Source_Eventbrite::fetch_via_scrape()` only; Heritage Week, IVVCC,
  Motorsport Ireland, Laois Tourism, Mondello Park and ICS sources are
  unaffected.
- It does **not** survive a process boundary. A continuation firing in a fresh
  process starts with a clean breaker (correct: it is a new logical attempt).
- It does **not** extend or reset on repeated 429s from later sources — later
  sources are skipped without sending a request, so they cannot re-trip it.

## 5. What the cooldown ladder does NOT do (confirmed)

- It does **not** grow unbounded. `min(20 * attempt, 60)` caps at 60s.
- It does **not** sleep past the invocation deadline. A deferred wait sleeps 0s
  and is reported as resumable (`class-eventbrite-pacing.php:578-580`).
- It does **not** consume request budget. Cooldown sends nothing;
  `Conexao_Request_Budget` is untouched (`class-request-budget.php:22-25`).

## 6. Observed behaviour in the 2026-11-20 Run All (from evidence)

From `05-nov20-run-all-sequence.json` (137 history entries):

- `eventbrite` (legacy) succeeded at 14:02:05 (0 events).
- `eventbrite_carlow` partial at 14:05:25 (`time_budget`).
- `eventbrite_cavan` partial at 14:06:27 (`rate_limited_deferred`).
- `eventbrite_clare` partial at 14:06:41 (`incomplete_enumeration`).
- `eventbrite_donegal` **rate_limited** at 14:07:34 — this is the source that
  tripped the shared breaker.
- From 14:07:34 onward, **every** subsequent Eventbrite source
  (`cork`, `kildare`, `kerry`, `galway`, `dublin`, …) is `rate_limited` with
  `found=0, new=0` — skipped without sending a request.

This is the breaker working exactly as designed: one 429 cascade, then 100
sources skipped. The log review's "100 of 105 Eventbrite sources were skipped"
is this mechanism, not a defect.

## 7. Residual risks (confirmed or inferred)

1. **No run-level mutex against overlapping Run All + continuation** (inferred;
  see `01-current-request-flow.md` §10). The per-source continuation lock
  protects the continuation worker path, not the manual `run_source` path.
2. **The breaker is process-local** (confirmed). A long Run All that is
  interrupted and restarted re-arms the breaker, so a restarted run may re-send
  Eventbrite requests the first run already 429'd. The per-source cooldown
  mitigates this for the *same* source, but a *different* source in the
  restarted run could still walk into a 429.
3. **405 WAF classification is inferred, not proven from retained headers**
  (confirmed by the log review). The `x-amzn-waf-action` header is not stored.