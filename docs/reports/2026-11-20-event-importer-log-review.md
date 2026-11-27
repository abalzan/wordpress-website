# Event Importer Log Review — Common Errors, Root Causes, and Prevention

| | |
|---|---|
| **Review date** | 2026-11-20 |
| **Review time (UTC)** | 2026-11-20 14:13 UTC (site local time 14:13, Europe/Dublin) |
| **Reviewer** | Automated investigation (read-only) |
| **Local page** | `http://localhost:8080/wp-admin/admin.php?page=conexao-event-import` |
| **Site** | Local Docker WordPress (`http://localhost:8080`), timezone `Europe/Dublin` |
| **Evidence** | [`docs/evidence/2026-11-20-event-importer-log-review/`](../evidence/2026-11-20-event-importer-log-review/) |
| **Method** | Read-only option extraction via `get_option()` inside the local WordPress container; no admin UI mutation, no imports, no provider requests |

> **Read-only declaration.** This investigation was strictly read-only. No
> imports, retries, Run All, cleanup, reconciliation, source activation or
> deactivation, provider requests, scheduled jobs, log truncation, option
> writes, database writes, configuration changes, code changes, commits,
> deployments, or environment changes were performed. The only write was the
> creation of this report and its evidence files under `docs/`. Log data was
> read directly from the `conexao_event_import_log` and
> `conexao_event_import_history` WordPress options via a throwaway PHP script
> that called `get_option()` and wrote JSON to `/tmp` inside the container; the
> script was then copied out and deleted from `scripts/`. No credentials,
> nonces, cookies, or personal data are reproduced in this report or its
> evidence.

---

## 1. Executive summary

### Overall health assessment

The importer is **operationally healthy but heavily constrained by an
external provider condition**. The dominant signal in the retained logs is
**Eventbrite HTTP 429 rate limiting**, which accounts for 463 of 521 distinct
error-level log entries (89%). This is a **provider-side restriction**, not an
importer defect. The importer's rate-limit handling — cooldown ladder, shared
run-scoped breaker, continuation scheduling, and incomplete-enumeration safety
— is working as designed and is well-evidenced in the logs.

Non-Eventbrite sources (Heritage Week, Motorsport Ireland, IVVCC) are healthy:
Heritage Week alone produced 2,687 log entries with **zero errors or warnings**
(all 2,582 "skipped" entries are expected `listing_date_definitely_past`
deduplication). The most recent Run All (2026-11-20 14:01–14:10) created 40 new
events, updated 3, and left 78 unchanged before Eventbrite rate limiting
cascaded.

### Scope reviewed

- **Log entries reviewed:** 5,512 (of 8,000 max retention; no eviction pressure)
- **History records reviewed:** 216 (of 1,200 max / 50 groups)
- **Log date range:** 2026-11-14 14:43:00 → 2026-11-20 14:10:31 (site time, Europe/Dublin)
- **History date range:** 2026-11-14 19:31:50 → 2026-11-20 14:10:34
- **Distinct sources in log:** 156
- **Distinct run IDs in log:** 166

### Top three recurring error patterns

| Rank | Pattern | Occurrences (deduped) | Distinct sources | Distinct runs |
|---:|---|---:|---:|---:|
| 1 | **Eventbrite HTTP 429 rate limiting** | 463 | 102 | 128 |
| 2 | **Eventbrite HTTP 405 WAF captcha** | 15 | 4 | 4 |
| 3 | **DNS/cURL transport failures (Eventbrite)** | 5 | 3 | 3 |

(G1 probe test-source 429s — 30 occurrences from 10 now-deactivated probe
sources on 2026-11-14 — are excluded from the ranking as test artifacts.)

### Most important immediate operational concern

**Eventbrite is rate-limiting the importer aggressively enough that a full Run
All cannot complete its Eventbrite sources in one pass.** On 2026-11-20, 100 of
105 Eventbrite sources were skipped without sending a request because an
earlier source (`eventbrite_donegal`) had already triggered the shared
rate-limit breaker. Only 4 Eventbrite sources returned partial data and 1
returned empty-but-success. This means **Eventbrite event coverage is
incomplete until the rate limit clears and sources are retried individually or
via continuation.**

### Major limitations in the retained log data

- **Retention window is ~6 days.** The log store retains 8,000 entries; the
  oldest retained entry is 2026-11-14 14:43. Any error rate before that date is
  unknown and cannot be extrapolated.
- **No `Retry-After` headers were observed.** Eventbrite did not send
  `Retry-After` in the retained window, so the importer used its deterministic
  20/40/60s ladder. Whether Eventbrite would send `Retry-After` under other
  conditions is unconfirmed.
- **History is dominated by individual source runs.** Only one Run All parent
  record exists (2026-11-20); earlier Run All runs were either not recorded as
  parents or have aged out. Cross-run comparison is therefore limited.
- **HTTP 405 body/captcha headers are not stored.** The log records the status
  code and a generic "unexpected HTTP 405" message but not the
  `x-amzn-waf-action` header that would confirm the WAF/captcha classification
  from the code path alone.

---

## 2. Scope and method

### Exact local page reviewed

`http://localhost:8080/wp-admin/admin.php?page=conexao-event-import` — the
**Event Import** dashboard (`Conexao_Event_Sources::render_import_dashboard()`),
which renders the Import History table (14 columns including a per-run Logs
column) over the full retained history.

### Date/time and timezone

- Review performed 2026-11-20 ~14:13 UTC.
- Site timezone: `Europe/Dublin` (UTC+0 in November).
- Log timestamps are site-local (`current_time('mysql')`), confirmed by
  comparing `current_time('mysql')` (14:21:18) with `gmdate` (14:21:18) at
  review time.

### Read-only methods used

1. **Option extraction via `get_option()`.** A throwaway PHP script
   (`_readonly-dump-importer-logs.php`) was placed in the container-mounted
   `scripts/` directory, executed with `docker compose exec -T wordpress php`,
   and wrote JSON to `/tmp` inside the container. It called only
   `get_option('conexao_event_import_log')`,
   `get_option('conexao_event_import_history')`, and
   `Conexao_Import_Log::get_retention_state()`. **No `update_option()`, no
   `add_option()`, no `delete_option()`, no WP-CLI, no SQL, no HTTP request to
   any provider.** The script was copied out and then deleted from `scripts/`.
2. **Source registry read.** A second read-only script extracted
   `get_option('conexao_event_sources')` (154 source records).
3. **Code-path inspection (read-only).** The Eventbrite client, pacing, source
   handler, import log, and import history classes were read to confirm the
   classification and handling of each error pattern. No code was executed
   against a provider.

### Pages/records reviewed

- **Full log store:** 5,512 entries (all retained entries, not a sample).
- **Full history store:** 216 records (all retained records).
- **Source registry:** 154 source records.

### Inaccessible or omitted sections

None. The full retained log and history were extracted. The only limitation is
the retention window itself (see §9).

---

## 3. Error frequency table

Counts are **deduplicated**: the 124 `"Import failure recorded: …"` wrapper
entries (which restate the underlying fetch error for the history row) are
excluded so each underlying failure is counted once. Categories are mutually
exclusive (priority order: test-source → transport → provider-status → generic).

| Rank | Error category | Occurrences | Affected runs | Affected sources | First seen | Last seen | Trend/status |
|---:|---|---:|---:|---:|---|---|---|
| 1 | Eventbrite HTTP 429 rate limiting | 463 | 128 | 102 | 2026-11-14 14:45 | 2026-11-20 14:10 | **Ongoing** — present in every multi-source run |
| 2 | Eventbrite HTTP 405 WAF captcha | 15 | 4 | 4 | 2026-11-15 20:30 | 2026-11-16 08:19 | **Clustered** — 2026-11-15/16 only; not seen on 11-20 |
| 3 | DNS/cURL transport (Eventbrite) | 5 | 3 | 3 | 2026-11-14 17:01 | 2026-11-14 19:27 | **Isolated** — 2026-11-14 only |
| 4 | Continuation stalled (gave up) | 3 | 1 | 3 | 2026-11-15 20:41 | 2026-11-20 14:07 | **Ongoing** — 3 sources exhausted retries |
| 5 | Mondello Park SSL/transport | 2 | 1 | 2 | 2026-11-20 14:04 | 2026-11-20 14:04 | **Isolated** — single run, 2026-11-20 |
| 6 | Laois Tourism HTTP 520 | 2 | 1 | 1 | 2026-11-20 14:01 | 2026-11-20 14:01 | **Isolated** — single run, 2026-11-20 |
| 7 | Eventbrite discovery unreachable (other) | 1 | 1 | 1 | 2026-11-14 19:27 | 2026-11-14 19:27 | **Isolated** — 2026-11-14 only |
| — | *G1 probe test-source 429 (deactivated)* | *30* | *10* | *10* | *2026-11-14 17:06* | *2026-11-14 17:25* | *Resolved — sources deactivated 2026-11-14* |

**Counting method.** Each log entry is one structured record. The
`"Import failure recorded: …"` wrapper (124 entries) is a history-bookkeeping
duplicate of the preceding fetch-error entry for the same source+time and is
excluded. The G1 probe entries are reported separately because they originate
from 18 test sources that were deliberately deactivated on 2026-11-14 (see
[`docs/reports/2026-11-14-stage-0-event-importer-probe-deactivation.md`](2026-11-14-stage-0-event-importer-probe-deactivation.md))
and are not representative of production source behaviour.

### Warning-level patterns (context, not errors)

Warnings are not failures; they record the importer's safety and pacing
decisions. The dominant warning categories are:

| Warning category | Occurrences | Interpretation |
|---|---:|---|
| Rate-limit cooldown (backoff working) | 410 | The 20/40/60s ladder firing between 429 retries — **expected safety behaviour** |
| Shared breaker: source skipped (no request sent) | 101 | Run-scoped breaker skipping remaining Eventbrite sources after one 429 — **expected safety behaviour** |
| Partial enumeration (safety: no `source_not_found`) | 46 | Listing only partially walked; absent events left untouched — **expected safety behaviour** |
| Enumeration incomplete (rate-limited / time budget / invocation window / truncation) | 61 | The four distinct reasons a walk stopped early — **expected safety behaviour** |
| Continuation scheduled (resumable) | 105 | A resumable continuation was armed for later — **expected recovery behaviour** |
| Continuation cleared (not resumable) | 10 | Source reached a terminal state; no further auto-attempt — **expected** |
| Resumed from checkpoint | 4 | A prior enumeration checkpoint was picked up — **expected recovery** |
| Event skipped (incomplete location) | 2 | IVVCC events with no identifiable location — **data-quality, not a defect** |

---

## 4. Detailed findings

### 4.1 Eventbrite HTTP 429 rate limiting (Rank 1 — dominant)

**Representative redacted examples:**

```text
[2026-11-15 20:02:32] eventbrite_louth | error |
  Eventbrite rate-limited page 1: still HTTP 429 after 3 attempts.
  This source stops here; retry it later instead of immediately.

[2026-11-20 14:07:34] eventbrite_donegal | error |
  Failed to fetch events from Eventbrite — Donegal. HTTP status: 429.

[2026-11-20 14:10:31] eventbrite_wicklow_events_this_week | warning |
  Not attempted: Eventbrite already rate-limited source eventbrite_donegal
  earlier in this run, so this source was skipped without sending any request.
```

**Affected sources/runs:** 102 distinct Eventbrite sources across 128 distinct
runs. Present on 2026-11-14 (369 errors), 2026-11-15 (23), and 2026-11-20 (101).
The 2026-11-20 Run All is the clearest illustration: `eventbrite_donegal` hit a
429 on page 1, and the shared breaker then skipped 99 subsequent Eventbrite
sources without sending any request.

**Observed pattern:**

- The importer uses a deterministic cooldown ladder: **20s → 40s → 60s**
  (capped at 60s), with a maximum of **3 attempts** per page
  (`RATE_LIMIT_BASE_SECONDS = 20`, `MAX_COOLDOWN_SECONDS = 60`,
  `MAX_RATE_LIMIT_RETRIES = 3` in `class-eventbrite-pacing.php`). The log
  confirms this exactly: 139 entries at attempt 1/20s, 134 at attempt 2/40s,
  133 at attempt 3/60s.
- After 3 failed attempts, the source stops and a continuation is scheduled
  (105 "Continuation scheduled" warnings).
- Once any Eventbrite source is rate-limited in a run, the **run-scoped shared
  breaker** skips all remaining Eventbrite sources (101 "Not attempted"
  warnings). This is deliberate: the G5 design (see
  [`docs/reports/2026-10-04-eventbrite-rate-limit-strategy.md`](2026-10-04-eventbrite-rate-limit-strategy.md))
  measured that after the first 429, 24 of 24 remaining Eventbrite paths
  returned 429 on every attempt, so retrying them is a request storm.
- No `Retry-After` header was present in any retained entry
  (`retry_after_present` never set), so the deterministic ladder was always
  used.

**Likely cause:** **Provider-side rate limiting by Eventbrite.** The importer
is making too many requests too quickly for Eventbrite's taste, and Eventbrite
responds with 429. This is consistent with the G5 baseline measurement and the
2026-10-04 WAF incident.

**Confidence:** **Confirmed** (directly demonstrated by 463 timestamped 429
entries with the exact cooldown ladder the code implements).

**Alternative explanations considered and rejected:**

- *Importer bug causing spurious 429s* — rejected: the 429s are real HTTP
  responses from Eventbrite (status recorded per entry), and the importer's
  pacing is already conservative (2s page delay, 20/40/60s backoff).
- *A single misbehaving source* — rejected: 102 distinct sources are affected,
  and the shared-breaker design explicitly treats rate limiting as source-wide.

**User/business impact:** **High.** Eventbrite is the largest event source (105
of 154 sources). When rate-limited, the majority of Eventbrite event coverage
is not refreshed in a single run. Events are eventually picked up via
continuation, but with a materially delayed refresh.

### 4.2 Eventbrite HTTP 405 WAF captcha (Rank 2)

**Representative redacted examples:**

```text
[2026-11-16 08:19:35] eventbrite_roscommon | error |
  Failed to fetch events from Eventbrite — Roscommon. HTTP status: 405.
[2026-11-16 08:19:35] eventbrite_roscommon | error |
  Eventbrite returned an unexpected HTTP 405 response.
```

**Affected sources/runs:** 4 sources (`eventbrite_louth`, `eventbrite_monaghan`,
`eventbrite_roscommon`, `eventbrite_waterford`) across 4 runs, clustered on
2026-11-15 20:30 → 2026-11-16 08:19.

**Observed pattern:** HTTP 405 is classified by the source handler as a **WAF
signal** (`classify_page_failure()` returns `'waf'` for status 405). The client
treats 405 as **non-retryable** (it is not in `RETRYABLE_STATUSES = [429, 500,
502, 503, 504]`), so it fails fast rather than hammering the WAF.

**Likely cause:** **Provider-side WAF/bot protection (AWS WAF / CloudFront
captcha challenge).** The 2026-10-04 G5 report documented the exact same
signature: `HTTP 405, x-amzn-waf-action: captcha` from CloudFront/AWS WAF,
affecting every county uniformly. The importer correctly refuses to bypass it.

**Confidence:** **Strongly supported** (the code path classifies 405 as WAF and
the 2026-10-04 report captured the `x-amzn-waf-action: captcha` header for the
same condition; the header itself is not stored in the current log entries).

**Alternative explanations:** A genuine HTTP method mismatch (server rejecting
the request method) — considered unlikely because the same URLs succeed at other
times and the 2026-10-04 probe captured the WAF header.

**User/business impact:** **Medium.** Clustered in time (2026-11-15/16) and not
seen on 2026-11-20, so it appears to be an intermittent WAF posture rather than
a permanent block. When active, it blocks the affected counties entirely.

### 4.3 DNS/cURL transport failures (Rank 3)

**Representative redacted examples:**

```text
[2026-11-14 19:27:09] eventbrite_louth | error |
  Eventbrite request failed: cURL error 6: Could not resolve host: www.eventbrite.ie
[2026-11-14 19:27:17] eventbrite_louth | error |
  Eventbrite could not be reached after 3 attempts. The discovery page may be
  temporarily unavailable.
```

**Affected sources/runs:** 3 sources (`eventbrite_louth`, `eventbrite_kilkenny`,
`eventbrite_wicklow_events_tomorrow`) across 3 runs, all on 2026-11-14
17:01–19:27.

**Observed pattern:** cURL error 6 (DNS resolution failure) for
`www.eventbrite.ie`, plus one "could not be reached after 3 attempts" after the
retries were exhausted.

**Likely cause:** **Transient local DNS resolution failure** in the local Docker
container on 2026-11-14. This is a local-environment issue, not an Eventbrite
outage (other sources succeeded at the same time).

**Confidence:** **Strongly supported** (cURL error 6 is unambiguously a local
DNS failure; it is isolated to a single day and does not recur).

**Alternative explanations:** Eventbrite-side DNS outage — considered unlikely
because it is isolated to the local container and other Eventbrite requests
succeeded in the same window.

**User/business impact:** **Low.** Isolated to 2026-11-14, 3 sources, and
self-resolved (the sources succeeded on later runs).

### 4.4 Continuation stalled (Rank 4)

**Representative redacted examples:**

```text
[2026-11-20 14:07:38] eventbrite_sligo | error |
  Automatic continuation for eventbrite_sligo STOPPED at page 4 of 49 after
  5 attempt(s) (stalled). The source is still INCOMPLETE and no further
  attempt is scheduled.
```

**Affected sources/runs:** 3 sources (`eventbrite_sligo`, `eventbrite_kildare`,
`eventbrite_waterford`) across 1 run group, 2026-11-15 20:41 → 2026-11-20
14:07.

**Observed pattern:** A continuation that was scheduled to resume an incomplete
enumeration stalled after 5–8 attempts and was marked exhausted. The source
remains incomplete and no further automatic attempt is scheduled.

**Likely cause:** **The continuation could not make progress because the
underlying rate limit persisted across all retry attempts.** This is the
continuation layer correctly giving up rather than looping forever.

**Confidence:** **Strongly supported** (the stall follows the same 429
condition; the continuation exhausted its bounded attempts).

**User/business impact:** **Medium.** The affected sources (Sligo, Kildare,
Waterford) will not auto-resume; they need a manual retry once the rate limit
clears.

### 4.5 Mondello Park SSL/transport (Rank 5)

**Representative redacted example:**

```text
[2026-11-20 14:04:32] mondellopark | error |
  Falha ao buscar REST da Mondello Park: cURL error 56: OpenSSL SSL_read:
  OpenSSL/3.5.6: error:0A000126:SSL routines::unexpected eof while reading,
  errno 0
```

**Affected sources/runs:** 1 source (`mondello_park`, plus a legacy
`mondellopark` alias) across 1 run, 2026-11-20 14:04:32.

**Observed pattern:** cURL error 56 (OpenSSL unexpected EOF) when fetching the
Mondello Park WordPress REST API. The source is marked failed with HTTP status 0
(no HTTP response received).

**Likely cause:** **TLS/transport failure between the local container and
mondellopark.ie** — the remote server closed the connection mid-handshake. This
is a transient network/TLS issue, not an importer defect.

**Confidence:** **Plausible** (cURL error 56 is a transport-layer failure; a
single occurrence is insufficient to distinguish a transient network issue from
a server-side TLS problem).

**Alternative explanations:** Remote server TLS misconfiguration or outage;
local container network instability.

**User/business impact:** **Low.** Single source, single occurrence. Mondello
Park is an inactive-by-default source (`status: inactive` in the registry) that
was run manually.

### 4.6 Laois Tourism HTTP 520 (Rank 6)

**Representative redacted example:**

```text
[2026-11-20 14:01:56] laois_tourism | error |
  Failed to fetch events from Laois Tourism. HTTP status: 520.
[2026-11-20 14:01:56] laois_tourism | error |
  Resposta HTTP 520 ao buscar URL.
```

**Affected sources/runs:** 1 source (`laois_tourism`) across 1 run,
2026-11-20 14:01:56.

**Observed pattern:** HTTP 520 (Cloudflare "unknown error" / origin
unreachable) when fetching the Laois Tourism ICS feed.

**Likely cause:** **Provider-side origin error (Cloudflare 520).** The Laois
Tourism site's origin server returned an error that Cloudflare could not
interpret. This is a provider-side outage, not an importer defect.

**Confidence:** **Strongly supported** (HTTP 520 is a Cloudflare-specific
origin-error code; the importer correctly records and fails).

**Alternative explanations:** Local network issue — considered unlikely because
other sources succeeded at the same time.

**User/business impact:** **Low.** Single source, single occurrence. Laois
Tourism is one ICS source.

### 4.7 G1 probe test-source 429s (excluded from ranking)

**Affected sources/runs:** 10 `g1_probe_eventbrite_*` sources across 10 runs,
2026-11-14 17:06–17:25.

**Observed pattern:** 30 error entries (40 including wrappers) of HTTP 429 from
the G1 probe test sources.

**Likely cause:** **Test artifacts.** These 18 `g1_probe_*` sources (10
Eventbrite + 8 Heritage Week) were deliberately deactivated on 2026-11-14 (see
the Stage 0 deactivation report). Their 429s occurred before/during
deactivation and are not representative of production source behaviour.

**Confidence:** **Confirmed** (the deactivation report documents the 18
sources; the errors predate the deactivation).

**User/business impact:** **None.** Test sources, now inactive.

---

## 5. Root-cause assessment

### Confirmed causes

1. **Eventbrite HTTP 429 rate limiting is provider-side and ongoing.** 463
   deduped error entries across 102 sources and 128 runs, with the exact
   20/40/60s cooldown ladder the code implements. This is not an importer
   defect; it is Eventbrite throttling the importer's request rate.

### Strongly supported causes

2. **Eventbrite HTTP 405 is an AWS WAF/CloudFront captcha challenge.** The code
   classifies 405 as WAF, the 2026-10-04 G5 report captured the
   `x-amzn-waf-action: captcha` header for the same signature, and the importer
   correctly treats it as non-retryable.
3. **DNS/cURL error 6 on 2026-11-14 was a transient local DNS failure.** cURL
   error 6 is unambiguously local name resolution; it is isolated to one day
   and does not recur.
4. **Laois Tourism HTTP 520 was a provider-side Cloudflare origin error.** HTTP
   520 is a Cloudflare-specific origin-unreachable code.
5. **Continuation stalls are the continuation layer correctly giving up after
   bounded retries against a persistent rate limit.**

### Plausible hypotheses

6. **Mondello Park cURL error 56 was a transient TLS/transport failure.** A
   single occurrence is insufficient to distinguish a transient network issue
   from a server-side TLS problem.

### Unknowns requiring more evidence

7. **Whether Eventbrite would send `Retry-After` under other conditions.** No
   `Retry-After` header was observed in the retained window, so the
   deterministic ladder was always used. The `Retry-After` code path exists but
   is unexercised in this data.
8. **The long-term 429 rate.** The retention window is ~6 days; no conclusion
   can be drawn about whether the rate limit is worsening, stable, or
   improving.
9. **Whether the 405 WAF condition is permanently resolved.** It was not seen
   on 2026-11-20, but the retained window is too short to confirm resolution.

---

## 6. Prevention and mitigation plan

### A. Operational actions

| Priority | Action | Rationale |
|---|---|---|
| **High** | **Do not run a full Run All while Eventbrite is rate-limiting.** Wait for the rate limit to clear (the cooldown/continuation system will eventually pick up the deferred sources) or retry the affected sources individually after a quiet period. | Running Run All repeatedly against a rate-limited Eventbrite produces the 429 cascade and wastes the run; the shared breaker already skips sources, but the run still records 100 rate_limited rows. |
| **High** | **Monitor the continuation queue.** Three sources (Sligo, Kildare, Waterford) exhausted their continuation attempts and will not auto-resume. Retry them manually once the rate limit clears. | These sources are stuck incomplete with no further automatic attempt. |
| **Medium** | **Investigate the local DNS resolution on 2026-11-14.** If DNS failures recur, check the Docker container's DNS configuration. | cURL error 6 is a local environment issue; if it recurs it will cause spurious transport failures. |
| **Medium** | **Record a provider incident for the 2026-11-15/16 WAF captcha event.** If 405s recur, pause Eventbrite attempts until the WAF posture is understood. | The 405 WAF condition blocks affected counties entirely; repeated attempts will not help. |
| **Low** | **Retry Mondello Park and Laois Tourism individually.** Both are single-source, single-occurrence failures. | Low impact; a manual retry will likely succeed. |

### B. Importer/code improvements (proposed, not implemented)

| Priority | Improvement | Problem addressed | Expected benefit | Likely files | Risks / trade-offs | Safe validation |
|---|---|---|---|---|---|---|
| **High** | **Store the `x-amzn-waf-action` (and other WAF) response headers on 405 entries.** | The log records only "unexpected HTTP 405"; the WAF/captcha classification is inferred from the code path, not proven from the log. | Turns "strongly supported" WAF attribution into "confirmed" from log evidence alone. | `includes/class-eventbrite-client.php` (capture headers into context), `includes/class-import-log.php` (allowlist the header key). | Small context-size increase; must keep the header allowlist bounded and secret-free. | Unit test that a mocked 405 with `x-amzn-waf-action: captcha` produces a log entry carrying the header. |
| **High** | **Surface the shared-breaker skip count and the first rate-limited source in the Run All parent history row.** | The parent row says "99 source(s) skipped/deferred" but the operator must open individual rows to find *which* source triggered the breaker. | Faster diagnosis; the operator sees "rate-limited by eventbrite_donegal" at a glance. | `includes/class-event-sources.php` (parent row `extra`), `includes/class-import-history.php` (record the first source). | Adds a small field to the parent row; no behaviour change. | In-process test asserting the parent row carries `first_rate_limited_source`. |
| **Medium** | **Distinguish "rate_limited (no request sent)" from "failed (request sent, 429)" in the history status.** | Both currently surface as `rate_limited`/`failed` with similar messages; the operator cannot tell at a glance whether a request was even attempted. | Clearer triage; the "Not attempted" warning already distinguishes them in the log, but the history status does not. | `includes/class-event-sources.php` (`history_status_label`/`history_status_reason`). | Adds a status variant; must not break existing status consumers. | In-process test for the new status label/reason. |
| **Medium** | **Add a "provider incident" pause flag for Eventbrite.** | When a WAF captcha (405) or sustained 429 is detected, the importer could pause Eventbrite sources for a configurable window instead of retrying on the next Run All. | Avoids the request storm and the 100-row rate_limited cascade. | New option + check in `class-eventbrite-source.php` / `class-event-sources.php`. | Adds state; must be admin-visible and clearable (no production CLI). | In-process test that the pause flag skips Eventbrite sources and clears after the window. |
| **Low** | **Add a `Retry-After`-present counter to the retention/diagnostics view.** | The `Retry-After` code path is unexercised; the operator cannot tell whether Eventbrite ever sends it. | Confirms whether the deterministic ladder is always used or whether `Retry-After` ever overrides it. | `includes/class-eventbrite-pacing.php` (counter), `includes/class-import-log.php` (surface in retention state). | Minimal. | Unit test with a mocked `Retry-After` header. |

### C. Provider-side or external causes

| Priority | Follow-up | What would need to be confirmed |
|---|---|---|
| **High** | **Eventbrite rate-limit policy.** The importer is being 429'd aggressively. Confirm with Eventbrite (or via their API docs) what request rate is acceptable for the discovery-page scraping pattern, and whether an API key would raise the limit. | The acceptable request rate; whether the discovery-page HTML scraping is supported or should move to the API. |
| **Medium** | **AWS WAF / CloudFront captcha (405).** Confirm whether the 2026-11-15/16 captcha event was a temporary WAF posture change or a permanent block. | Whether the WAF challenge is expected to recur; whether the browser-like User-Agent is sufficient. |
| **Low** | **Laois Tourism origin (520) and Mondello Park TLS (56).** Confirm whether these were transient provider-side outages. | Whether the providers had an outage on 2026-11-20 14:01–14:04. |

> **No recommendation in this report suggests bypassing CAPTCHA, WAF, access
> controls, or other protections.** The importer correctly identifies and
> respects these provider-side restrictions.

---

## 7. What appears healthy

- **Heritage Week (27 sources): 2,687 log entries, zero errors, zero warnings.**
  All 2,582 "skipped before detail fetch" entries are
  `listing_date_definitely_past` — correct deduplication of past events. This is
  the healthiest source in the log.
- **Motorsport Ireland: 15 events found, 15 created, 0 errors** on 2026-11-20.
- **IVVCC: 10 events found, 2 created, 2 skipped (incomplete location), 0
  failed** on 2026-11-20. The 2 skips are data-quality (no identifiable
  location), not defects.
- **The rate-limit cooldown ladder is working exactly as designed.** 410
  cooldown warnings show the 20/40/60s ladder firing; 101 "Not attempted"
  warnings show the shared breaker preventing a request storm.
- **Continuation scheduling is working.** 105 "Continuation scheduled" warnings
  show resumable continuations being armed; 4 "Resumed from checkpoint" entries
  show them being picked up.
- **Incomplete-enumeration safety is working.** 46 "partially enumerated"
  warnings confirm that absent events are left untouched (not marked
  `source_not_found`) when a walk is incomplete — the G9/G10 safety contract.
- **The most recent Run All (2026-11-20) created 40 new events, updated 3, left
  78 unchanged** before Eventbrite rate limiting cascaded — non-Eventbrite
  sources are doing real work.

> **Caution:** This health assessment is based on a ~6-day retention window and
> is dominated by the 2026-11-20 Run All. It does not prove long-term health.

---

## 8. Data quality and observability gaps

| Gap | Impact | Suggested improvement |
|---|---|---|
| **Retention window is ~6 days (8,000 entries).** | Long-term error rates cannot be established; older runs are evicted. | Consider raising `MAX_ENTRIES` or adding a summary rollup; see `class-import-log.php`. |
| **No `Retry-After` header observed.** | The `Retry-After` code path is unexercised; the operator cannot tell whether Eventbrite ever sends it. | Add a counter (see §6B). |
| **HTTP 405 body/WAF headers not stored.** | The WAF/captcha classification is inferred from the code path, not proven from the log. | Store `x-amzn-waf-action` on 405 entries (see §6B). |
| **History dominated by individual source runs; only 1 Run All parent.** | Cross-run comparison is limited; earlier Run All runs are not available as parents. | This is a retention/history-structure limitation, not a defect. |
| **"Import failure recorded" wrapper duplicates each fetch error.** | 124 of 645 error-level entries are bookkeeping duplicates, inflating raw error counts by ~24%. | Consider logging the wrapper at `info` level or deduplicating; see `class-import-history.php`. |
| **No run-level "first rate-limited source" in the parent row.** | The operator must open individual rows to find which source triggered the breaker. | See §6B. |
| **Continuation correlation is present but dense.** | 65 lifecycle entries carry `parent_run_group_id`/`source_run_id`/`continuation_id`, but the operator UI does not surface them in the history table. | Consider a continuation detail view. |

---

## 9. Recommended next steps

Ordered by priority:

1. **[Operational, High] Pause full Run All while Eventbrite is rate-limiting.**
   Wait for the rate limit to clear or retry affected sources individually.
   *No approval needed — operational guidance.*
2. **[Operational, High] Manually retry the 3 stalled sources** (`eventbrite_sligo`,
   `eventbrite_kildare`, `eventbrite_waterford`) once the rate limit clears.
   *No approval needed — operational guidance.*
3. **[Code, High] Store WAF headers on 405 entries** so the WAF/captcha
   classification is provable from the log. *Requires a code change task.*
4. **[Code, High] Surface the first rate-limited source in the Run All parent
   row.** *Requires a code change task.*
5. **[Operational, Medium] Investigate local DNS resolution** if cURL error 6
   recurs. *No approval needed — environment check.*
6. **[Code, Medium] Distinguish "rate_limited (no request sent)" from "failed
   (request sent, 429)" in the history status.** *Requires a code change task.*
7. **[Code, Medium] Add a provider-incident pause flag for Eventbrite.**
   *Requires a code change task and admin-screen work (no production CLI).*
8. **[Provider, High] Confirm Eventbrite's acceptable request rate** and
   whether an API key would raise the limit. *Requires provider follow-up.*
9. **[Code, Low] Add a `Retry-After`-present counter.** *Requires a code change
   task.*

Items 3, 4, 6, 7, and 9 require a separate implementation task with the
standard plan → build → verify → report workflow. Items 1, 2, and 5 are safe
operational actions. Item 8 is a provider-side follow-up.

---

## 10. Final disposition

**`REVIEW COMPLETE — RECURRING ERRORS IDENTIFIED`**

Recurring errors were identified and are dominated by **Eventbrite HTTP 429
rate limiting** (463 deduped occurrences across 102 sources and 128 runs,
2026-11-14 → 2026-11-20), with a secondary cluster of **Eventbrite HTTP 405
WAF captcha** (15 occurrences, 2026-11-15/16) and isolated transport failures
(DNS, SSL, HTTP 520).

> **This is a log-based assessment, not proof that all importer behaviour is
> error-free.** The retained window is ~6 days; long-term rates cannot be
> established. The dominant error is a **provider-side restriction**, not an
> importer defect — the importer's rate-limit handling, shared breaker,
> continuation scheduling, and incomplete-enumeration safety are working as
> designed and are well-evidenced in the logs.

---

## Appendix A — Evidence files

| File | Contents |
|---|---|
| [`01-log-summary.json`](../evidence/2026-11-20-event-importer-log-review/01-log-summary.json) | Aggregate counts, taxonomy, daily distribution, retention state |
| [`02-redacted-log-samples.json`](../evidence/2026-11-20-event-importer-log-review/02-redacted-log-samples.json) | Redacted representative log entries per error/warning category |
| [`03-history-summary.json`](../evidence/2026-11-20-event-importer-log-review/03-history-summary.json) | History summary, daily status breakdown, Nov 20 Run All parent |
| [`04-source-registry-summary.json`](../evidence/2026-11-20-event-importer-log-review/04-source-registry-summary.json) | 154-source registry summary (active/inactive, by type) |
| [`05-nov20-run-all-sequence.json`](../evidence/2026-11-20-event-importer-log-review/05-nov20-run-all-sequence.json) | Full Nov 20 Run All sequence (137 entries, time order) |

## Appendix B — Read-only method detail

The log and history data were extracted with a throwaway PHP script that:

1. Located `wp-load.php` at `/var/www/html/wp-load.php`.
2. Called `get_option('conexao_event_import_log')` and
   `get_option('conexao_event_import_history')`.
3. Called `Conexao_Import_Log::get_retention_state()` (read-only).
4. Wrote the result as JSON to `/tmp/importer-logs.json` inside the container.
5. Exited.

The script was copied out of the container, the JSON was analyzed with Python
(`json`, `collections.Counter`), and the script was deleted from `scripts/`. **No
`update_option()`, `add_option()`, `delete_option()`, WP-CLI, SQL, or provider
HTTP request was made.** The source registry was extracted the same way.

Code-path inspection (read-only) confirmed:

- `class-eventbrite-pacing.php`: `RATE_LIMIT_BASE_SECONDS = 20`,
  `MAX_COOLDOWN_SECONDS = 60`, `MAX_RATE_LIMIT_RETRIES = 3`,
  `PAGE_DELAY_SECONDS = 2`.
- `class-eventbrite-client.php`: `RETRYABLE_STATUSES = [429, 500, 502, 503,
  504]` (405 is non-retryable); browser-like `USER_AGENT`.
- `sources/class-eventbrite-source.php`: `classify_page_failure()` returns
  `'waf'` for HTTP 405.
- `class-import-log.php`: `MAX_ENTRIES = 8000`, group-integrity retention.
- `class-import-history.php`: `MAX_ENTRIES = 1200`, `MAX_GROUPS = 50`.

## Appendix C — Constraints honoured

- Worked only against the local WordPress site at `http://localhost:8080`.
- No production, staging, or remote site accessed.
- No imports, retries, Run All, cleanup, reconciliation, source
  activation/deactivation, or state-changing operations.
- No provider requests, scraping, or scheduled jobs triggered.
- No logs cleared, truncated, deleted, rotated, or rewritten.
- No plugin code, settings, source configuration, WordPress options, posts,
  terms, attachments, or database records changed.
- No plugins installed, WordPress updated, or environment changed.
- No commit, push, deploy, or code fix created.
- No credentials, cookies, nonces, API keys, authorization headers, passwords,
  or personal data reproduced in this report or its evidence.
- The retired Needs Review feature was not used or recreated.
- The prior local cleanup (deleting events with no county and no image) was not
  repeated or extended.