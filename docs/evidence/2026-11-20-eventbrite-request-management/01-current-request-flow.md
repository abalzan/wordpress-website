# 01 — Current Eventbrite request flow

Evidence-backed map of how the Conexao Event Importer initiates, paces, bounds
and recovers Eventbrite requests. Every claim cites an exact file, class and
method. **Confirmed** = read directly from the implementation. **Inference** =
reasoned from the code path but not separately exercised.

All paths are relative to
`wp-content/plugins/conexao-event-importer/includes/`.

## 1. How Eventbrite requests are initiated

### Individual source import

- The engine entry point is `Conexao_Event_Importer::run_source()`
  (`class-event-importer.php:434`), which delegates to
  `run_source_unbuffered()` (`:518`).
- `run_source_unbuffered()` opens a per-source scope via
  `begin_run_scope( ! $is_child )` (`:461`). For a standalone source run
  (`$is_child = false`) this calls `Conexao_Request_Reuse::begin_run()`,
  `Conexao_Request_Budget::begin_run()` **and**
  `Conexao_Eventbrite_Pacing::begin_run()` (`:120-141`). So a standalone run
  starts with a fresh breaker, budget and reuse store.
- The source handler is resolved by `get_source_handler()` (`:1537`) and its
  `fetch_events()` is called at `:690`. For an Eventbrite source this is
  `Conexao_Source_Eventbrite::fetch_events()` → `fetch_via_scrape()`
  (`sources/class-eventbrite-source.php:501`, `:513`).
- `fetch_via_scrape()` builds one `Conexao_Eventbrite_Client`
  (`class-eventbrite-client.php`) bound to the source ID and the shared pacing
  policy (`sources/class-eventbrite-source.php:522-523`).
- **Page 1 is always requested first** (`:564`) because it carries the
  pagination metadata (`page_count`, `object_count`, `page_size`) the
  truncation guard depends on. A failed or unparseable page 1 is a **fatal**
  `Conexao_Source_Fetch_Exception` — never an empty success (`:566-599`).
- The walk then loops `for ( $page = $resume_page; $page <= $page_count; $page++ )`
  (`:689`), calling `$client->fetch_page( $base_url, $page )` per page (`:731`).

### Run All

- `Conexao_Event_Importer::run_all()` (`:184`) → `run_all_unbuffered()`
  (`:199`).
- `run_all_unbuffered()` sets a fresh `current_run_id = 'all-' . microtime(true)`
  and calls `begin_run_scope( true )` **once** for the whole logical run
  (`:228`). This resets the shared breaker, budget and reuse store at the
  parent boundary.
- It then iterates `$active_sources` **sequentially** in a plain `foreach`
  (`:300`), calling `run_source( $source['id'], $dry_run, [ 'parent_run_group_id' => ... ] )`
  per source.
- Each child call has `$is_child = true`, so `begin_run_scope( false )` is used
  (`:461`): the **request budget and reuse store are reset per child**, but the
  **Eventbrite breaker is intentionally NOT reset** (the `! $is_child` guard at
  `:139`). This is the mechanism that makes the breaker span the whole Run All.

### Continuation / resumed import

- A continuation is armed by `Conexao_Import_Continuation::schedule()`
  (`class-conexao-import-continuation.php:660`) and fired by `run_due()`
  (`:976`) via the WP-Cron hook `conexao_event_import_continuation` or the
  `shutdown` fail-safe.
- `run_due()` enforces the server-clock cooldown (`:995`), takes a per-source
  lock via `acquire_lock()` (`:1001`, `:255`), then calls the runner
  (`default_runner()` at `:445`) which invokes
  `Conexao_Event_Importer::run_source( $source_id, false, $context )` with the
  persisted `parent_run_group_id`.
- Because the continuation calls `run_source()` with a `parent_run_group_id`
  context, it is treated as a **child** run: `begin_run_scope( false )` — the
  budget/reuse reset, but the breaker is **not** reset. A continuation that
  fires in a fresh process therefore starts with a clean static breaker (the
  process is new), which is correct: a continuation is a new logical attempt.

## 2. Sequential vs concurrent processing

**Confirmed:** Eventbrite sources are processed **strictly sequentially**.
`run_all_unbuffered()` is a plain `foreach` over `$active_sources`
(`class-event-importer.php:300`); there is no concurrency, no batching, no
parallel HTTP, and no stagger between sources. The only inter-source timing is
whatever the per-source pacing policy accumulates inside a single walk.

The continuation worker (`run_due()`) is also sequential: it iterates due
sources one at a time (`:986`), and a per-source lock prevents two workers from
resuming the same source concurrently (`:1001`). There is **no global lock** —
by design, so Cork's lock never blocks Wicklow (class docblock `:78-82`).

## 3. Where request counts, page counts, time budgets and invocation budgets are enforced

| Control | Where | Value | Scope |
|---|---|---|---|
| Outbound request budget | `Conexao_Request_Budget` (`class-request-budget.php`) | `DEFAULT_LIMIT = 400` (`:57`) | Per run / per child; reset by `begin_run()` (`:88`) |
| Page delay | `Conexao_Eventbrite_Pacing::PAGE_DELAY_SECONDS` (`class-eventbrite-pacing.php:81`) | `2` seconds | Per request, via `pace_page_request()` (`:510`) |
| Max pages | `Conexao_Source_Eventbrite::MAX_PAGES` (`sources/class-eventbrite-source.php:31`) | `50` | Per walk |
| Time budget | `Conexao_Source_Eventbrite::TIME_BUDGET_SECONDS` (`:41`) | `45` seconds | Per walk, via `$pacing->now()` (`:681`) |
| Rate-limit retries | `Conexao_Eventbrite_Client::MAX_RETRIES` (`class-eventbrite-client.php:33`) | `3` | Per page |
| Rate-limit cooldown ladder | `Conexao_Eventbrite_Pacing::RATE_LIMIT_BASE_SECONDS` / `MAX_COOLDOWN_SECONDS` (`:90`,`:101`) | `20s → 40s → 60s` (capped) | Per 429 |
| Continuation attempts | `Conexao_Import_Continuation::MAX_ATTEMPTS` (`class-conexao-import-continuation.php:159`) | `40` | Per source lifecycle |
| Continuation stall ceiling | `Conexao_Import_Continuation::MAX_STALLED` (`:148`) | `4` | Per source lifecycle |

**Budget exhaustion is safe:** `Conexao_Request_Budget::consume()` returns
false when the ceiling is reached (`class-request-budget.php:130-146`); the
client converts that into a `WP_Error( 'conexao_request_budget_exhausted' )`
(`class-eventbrite-client.php:490`), which the source handler classifies as
`budget_exhausted` and treats as an **incomplete** enumeration
(`sources/class-eventbrite-source.php:336-370`). It never produces a false
success or a complete enumeration.

## 4. How the shared breaker is scoped, keyed, opened and cleared

**Confirmed** from `class-eventbrite-pacing.php`:

- **Scoped:** `protected static $run_rate_limited` (plus
  `$run_rate_limited_source`, `$run_skipped_sources`, `$run_state`,
  `$shared`) — all `static`, in-memory, reset by `begin_run()` (`:262-276`).
  Nothing is persisted: no option, transient, table or scheduler. The docblock
  (`:38-46`) states this explicitly.
- **Keyed:** by source ID. `record_run_rate_limit( $source_id )` (`:316`) stores
  the first source that tripped the limit; `record_skipped_source( $source_id )`
  (`:358`) accumulates skipped sources.
- **Opened:** `Conexao_Eventbrite_Client::fetch_page()` calls
  `Conexao_Eventbrite_Pacing::record_run_rate_limit( $this->source_id )` on the
  **first 429** of any page (`class-eventbrite-client.php:355`). It also records
  the retry window via `record_run_retry_state()` (`:356`).
- **Cleared:** only by `begin_run()` (`:262`), which is called at the start of
  each **logical** run (parent Run All, or a standalone source run). It is
  **not** cleared per child source within a Run All (the `! $is_child` guard at
  `class-event-importer.php:139`).
- **Enforced:** the next Eventbrite source checks
  `Conexao_Eventbrite_Pacing::is_rate_limited()` at the top of
  `fetch_via_scrape()` (`sources/class-eventbrite-source.php:531`); if set, it
  records the skip, marks the fetch incomplete, logs a `warning`, and throws a
  `Conexao_Source_Fetch_Exception( ..., 429 )` **without sending any request**.

## 5. How the 20/40/60-second cooldown is calculated and persisted

**Confirmed** from `class-eventbrite-pacing.php`:

- `get_cooldown_seconds( $retry_number, $retry_after )` (`:479`):
  - If `$retry_after` is present (parsed, already-capped), it is honoured
    exactly, then clamped to `MAX_COOLDOWN_SECONDS` (60s). If the requested
    value exceeds 60s, `last_retry_after_capped` is set (`:487-497`).
  - Otherwise the deterministic ladder is
    `min( RATE_LIMIT_BASE_SECONDS * $retry_number, MAX_COOLDOWN_SECONDS )`
    (`:503-506`), i.e. 20s, 40s, 60s, 60s, …
- `apply_rate_limit_cooldown( $retry_number, $retry_after )` (`:543`) increments
  the hit counter, sets `rate_limited = true`, computes the cooldown, and calls
  `sleep_for()`.
- **Persistence:** the in-walk cooldown is **in-memory only** (static run
  state). The **cross-run** cooldown is a separate mechanism:
  `Conexao_Source_Cooldown` (`class-source-cooldown.php`) persists a per-source
  record in the `conexao_event_source_cooldown` option (`:46`), with its own
  `BASE_SECONDS = 20`, `MAX_SECONDS = 60`, `STEPS = 3` (`:60-78`). It is
  recorded by `Conexao_Event_Importer::record_throttle_cooldown()` (`:2533`)
  after a throttled fetch failure, and enforced at the top of
  `run_source_unbuffered()` (`:566`).

## 6. How Retry-After is parsed, validated, capped and applied

**Confirmed** from `class-eventbrite-client.php`:

- `parse_retry_after_details( $header, $now )` (`:690`) returns
  `{ delay, kind, unsupported }`:
  - **delta-seconds** (`/^\d+$/`): `kind = 'delta'`.
  - **HTTP-date** (IMF-fixdate, RFC850, asctime): `kind = 'http_date'`,
    delay = timestamp − now.
  - **Plausible-but-unsupported date** (contains a comma or month/day name but
    matches no accepted format): `kind = 'unsupported'`,
    `unsupported = true` (`:727-729`).
  - **Empty / non-numeric / whitespace**: `kind = 'fallback'`,
    `delay = null` (`:703-705`).
- **Validation / capping:** negative delays clamp to `0.0` (`:733-735`);
  the result is always `min( seconds, MAX_RETRY_AFTER_SECONDS )` where
  `MAX_RETRY_AFTER_SECONDS = 60` (`:590`, `:738`).
- **Applied:** in `fetch_page()`, a 429 with a usable `Retry-After` passes the
  parsed delay to `apply_rate_limit_cooldown( $attempt, $retry_after )`
  (`:383`). An **unsupported** date is treated as a rate limit with **no**
  earlier retry: it records the run rate limit, marks the run deferred, logs an
  error, and returns `''` (`:366-381`).
- **Missing header:** `wp_remote_retrieve_header( $response, 'retry-after' )`
  returns `''` → `fallback` → `delay = null` → the deterministic ladder is used.

## 7. Whether source-level cooldowns and run-level breaker state interact safely

**Confirmed:** they are **complementary and non-conflicting**:

- The **run-level breaker** (`Conexao_Eventbrite_Pacing`) is an in-memory,
  per-logical-run flag that stops *later sources in the same Run All* from
  sending requests after one source hits a 429.
- The **source-level cooldown** (`Conexao_Source_Cooldown`) is a persisted,
  per-source, cross-run record that stops *the same source* from being retried
  on the *next* Run All until its window elapses.
- They are enforced at different layers: the breaker at
  `fetch_via_scrape()` entry (`sources/class-eventbrite-source.php:531`), the
  cooldown at `run_source_unbuffered()` entry (`class-event-importer.php:566`).
- A continuation's `schedule()` composes with the source cooldown by taking the
  **later** of its own `run_at` and the cooldown's `next_eligible`
  (`class-conexao-import-continuation.php:686-691`), so a continuation never
  starts a run the run gate would immediately refuse.

## 8. How continuation checkpoints avoid repeating already completed pages

**Confirmed** from `sources/class-eventbrite-source.php` and
`class-source-enumeration-checkpoint.php`:

- Page 1 is always fetched (for metadata). `resolve_resume_page( $page_count )`
  (`:196`) then reads the stored checkpoint via
  `Conexao_Source_Enumeration_Checkpoint::get()` and validates it with
  `is_usable()`.
- If usable and not already at the end, the walk resumes at
  `max( 2, last_page - OVERLAP_PAGES )` where `OVERLAP_PAGES = 1` (`:70`). The
  overlap re-validates the page boundary (Eventbrite's listing is a live
  ranking with no stable sort key — class docblock `:44-66`).
- The checkpoint is advanced **only after** a page is fetched, parsed and
  merged (`persist_checkpoint( $page, ... )` at `:859`), never before. A failed
  page never advances it (`:766`, `:796`, `:821`).
- A resumed walk sets `reconciliation_safe = false` (`:252`), so
  `mark_missing_events()` cannot fire on the subset — preventing destructive
  absence reconciliation.

## 9. Which conditions invalidate a checkpoint and force a safe restart

**Confirmed** from `Conexao_Source_Enumeration_Checkpoint::is_usable()`
(`class-source-enumeration-checkpoint.php:186-227`). A checkpoint is discarded
(and the walk restarts at page 1) when:

1. No checkpoint exists (first run).
2. The record is not an array (corrupted / hand-edited).
3. `schema_version` ≠ `SCHEMA_VERSION` (1).
4. The stored `fingerprint` is empty or ≠ the current source fingerprint
   (derived from `type|url|county` — `:160`).
5. `last_page < 1`.
6. `last_page > page_count` declared by **this** run's page 1 (would walk off
   the end of a smaller listing).

Additionally, `resolve_resume_page()` returns 2 (full re-walk) when
`last_page >= page_count` (already at the end) (`:239`).

## 10. Whether a new Run All can overlap an existing Eventbrite run or continuation

**Confirmed:** there is **no run-level mutex** preventing a new Run All from
starting while a continuation is mid-flight. The protections are:

- **Per-source continuation lock** (`acquire_lock()`, `:255`): a continuation
  worker takes `conexao_event_import_lock_<source_id>` via atomic
  `add_option()`; a second worker that finds it held fails safe (does nothing).
  The lock has a 900s TTL (`LOCK_TTL`, `:177`) so a killed worker self-heals.
- **No global lock** — by design (class docblock `:78-82`).
- **Request budget / reuse store** are per-run, so an overlapping run gets its
  own budget; it does not share the first run's in-memory state.

**Inference:** an operator-triggered Run All that overlaps a due continuation
for the *same source* is not explicitly blocked at the run level; the
continuation lock only protects the continuation worker path, not the manual
`run_source` path. In practice the 30-minute continuation cooldown and the
per-source cooldown make a same-instant overlap unlikely, but it is not
structurally impossible. This is noted as a residual risk, not a demonstrated
defect.

## 11. Whether any existing scheduler already provides staggered execution or request budgets

**Confirmed:** the only scheduler is `Conexao_Import_Continuation`, which uses
WP-Cron single events (`wp_schedule_single_event( $run_at, HOOK, [ $source_id ] )`,
`:545`) — one event per source, never recurring, never a job per page. It
provides **per-source** scheduling with a 30-minute base cooldown
(`COOLDOWN_SECONDS = 1800`, `:129`) escalating 30→60→120 min (capped at 7200s,
`MAX_BACKOFF_SECONDS`, `:136`).

There is **no** existing mechanism that staggers the *start* of multiple
Eventbrite sources within a single Run All, and **no** per-run or per-window
request budget beyond the per-run `Conexao_Request_Budget` (400 requests) and
the per-source `TIME_BUDGET_SECONDS` (45s). The continuation scheduler staggers
*retries across runs*, not *requests within a run*.