# Stage 4 — Read-only fetch-path pre-check

**Assessment date:** 2026-11-16
**Cutoff (last observed Stage 3.6 WAF refusal):** `2026-11-15T20:34:29Z`
**Live requests:** none. This is a read-only examination of retained
source-registry, import-log/history and code evidence.

## 1. Per-source registry state

Source: `wp_options.conexao_event_sources` (read-only `SELECT`).

| Source | `last_import` | `last_import_status` | `events_imported` | `last_error` |
|---|---|---|---|---|
| `eventbrite_offaly` | 2026-11-14 14:24:51 | success | 2 | (empty) |
| `eventbrite_meath` | 2026-11-14 14:23:45 | success | 4 | (empty) |
| `eventbrite_laois` | 2026-11-14 14:17:00 | success | 1 | (empty) |
| `eventbrite_mayo` | 2026-11-14 14:23:24 | error | 0 | HTTP status: 429 |
| `eventbrite_kerry` | 2026-11-14 14:11:46 | success | 2 | (empty) |
| `eventbrite_westmeath` | 2026-11-14 14:31:21 | success | 7 | (empty) |
| `eventbrite_clare` | 2026-11-14 14:02:10 | success | 5 | (empty) |
| `eventbrite_leitrim` | 2026-11-14 14:17:10 | success | 1 | (empty) |

**All eight `last_import` timestamps (2026-11-14) predate the cutoff
`2026-11-15T20:34:29Z`.** None of the eight has a post-cutoff import.

## 2. Retained import-log evidence

Source: `wp_options.conexao_event_import_log` (read-only `SELECT`).

The log contains 2,178 entries. The latest entry is `2026-11-16 08:19:36`.
There are **32 post-cutoff entries**, all for four Eventbrite sources that
are NOT in the eight-source Stage 3.6 target set:

| Source | Post-cutoff entries | Evidence |
|---|---|---|
| `eventbrite_waterford` | 8 | HTTP 405 on discovery page (2026-11-15 20:41:18) |
| `eventbrite_louth` | 8 | HTTP 405 on discovery page (2026-11-16 08:19:34) |
| `eventbrite_monaghan` | 8 | HTTP 405 on discovery page (2026-11-16 08:19:35) |
| `eventbrite_roscommon` | 8 | HTTP 405 on discovery page (2026-11-16 08:19:35) |

**Zero post-cutoff log entries exist for any of the eight target sources.**

## 3. Retained import-history evidence

Source: `wp_options.conexao_event_import_history` (read-only `SELECT`).

The history contains 70 timestamped records. Post-cutoff history records
exist only for `eventbrite_roscommon`, `eventbrite_monaghan` and
`eventbrite_louth` (the same HTTP 405 failures). **No post-cutoff history
record exists for any of the eight target sources.**

## 4. Per-source outcome

| Source | Outcome |
|---|---|
| `eventbrite_offaly` | `ASSESSED — NOT VERIFIED` |
| `eventbrite_meath` | `ASSESSED — NOT VERIFIED` |
| `eventbrite_laois` | `ASSESSED — NOT VERIFIED` |
| `eventbrite_mayo` | `ASSESSED — NOT VERIFIED` |
| `eventbrite_kerry` | `ASSESSED — NOT VERIFIED` |
| `eventbrite_westmeath` | `ASSESSED — NOT VERIFIED` |
| `eventbrite_clare` | `ASSESSED — NOT VERIFIED` |
| `eventbrite_leitrim` | `ASSESSED — NOT VERIFIED` |

**Summary: `ASSESSED — MIXED: 0 with post-refusal fetch evidence, 8 not
verified, 0 with importer WAF failure evidence`.**

Rationale: all eight sources' last-import timestamps predate the cutoff, and
no retained importer-side record (log or history) shows a post-cutoff
listing fetch or a post-cutoff WAF refusal for any of the eight. Per the
task rules, a generic `success`/`warning` status or the absence of a recent
run is not proof of fetch functionality or blockage. `NOT VERIFIED` is the
default when direct evidence is insufficient.

The post-cutoff HTTP 405 failures on the four non-target Eventbrite sources
(`waterford`, `louth`, `monaghan`, `roscommon`) are consistent with the
Stage 3.6 WAF block, but they do not constitute importer-side WAF failure
evidence for any of the eight target sources.

## 5. Request-construction comparison (diagnostic only)

### Importer (Eventbrite discovery fetch)

`Conexao_Eventbrite_Client::request_page()` (`class-eventbrite-client.php`):

- User-Agent: `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36`
- Headers: `Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8`, `Accept-Language: en-IE,en;q=0.9`
- Transport: `wp_remote_get()` (WordPress HTTP API → cURL with WordPress's default transport, HTTP/1.1, redirects enabled, cookies via WordPress's cookie jar)
- Retry: 429/500/503 with backoff; `Retry-After` honoured
- Request reuse: per-run memoisation of 2xx responses; per-run outbound budget

### Stage 3.6 harness

`docs/evidence/2026-11-15-stage-3-6/harness/lib.php`:

- User-Agent: `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36` (identical)
- Headers: `Accept: text/html,application/xhtml+xml` (simpler than the importer's)
- Transport: raw cURL (`CURLOPT_*`), `CURLOPT_FOLLOWLOCATION => false`, `CURLOPT_MAXREDIRS => 0`
- Retry: 429/500/502/503/504 with backoff; `Retry-After` honoured
- No cookie jar, no persistent session, no HTTP/2

### Material differences (the harness would NOT have replicated)

1. **Transport library.** The importer uses `wp_remote_get()` (WordPress's
   WP_HTTP wrapper, typically cURL but with WordPress's default option set);
   the harness uses raw cURL. The TLS fingerprint (cipher suites, extensions)
   and HTTP version negotiation can differ between the two, which is a
   plausible WAF signal.
2. **Redirect handling.** The importer follows redirects (WordPress default
   `redirection => 5`); the harness sets `FOLLOWLOCATION => false`. A WAF
   that challenges on a redirect hop would behave differently.
3. **Cookie/session state.** WordPress's HTTP client can carry cookies
   across requests within a run (a session cookie set by a prior response
   would be replayed); the harness has no cookie jar.
4. **Accept header.** The importer sends a richer `Accept` header
   (`application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8`); the harness
   sends a minimal one.

These differences are recorded as diagnostically valuable for a future
Stage 3.7 unblocking investigation. They do **not** establish current
importer functionality from code alone, and they do not change the per-source
`NOT VERIFIED` outcomes above.

## 6. Effect on the rest of Stage 4

The Stage 3.6 result concerns Eventbrite discovery URLs. It does not
establish the availability or blockage of IVVCC, Motorsport Ireland or
Mondello Park, and it is not treated as a blanket gate on P9. P9 proceeded
only with retained HTML/source fixtures already present in the repository;
no new provider HTML was fetched.