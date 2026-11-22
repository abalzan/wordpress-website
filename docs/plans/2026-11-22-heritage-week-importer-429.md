# Plan — National Heritage Week importer: fix HTTP 429, add retry/backoff and import logging

> **This is a template.** Copy it to `docs/plans/<date>-<topic>.md` and fill it in. Do **not** edit this file to turn it into a repository-specific plan, and do not leave a filled-in plan at this path.

| | |
|---|---|
| **Task title** | National Heritage Week importer: fix HTTP 429, add retry/backoff and import logging |
| **Date** | 2026-11-22 |
| **Author / agent** | Cline (AI coding agent) |
| **Branch** | `event-import-improvements` |
| **Repository baseline (starting SHA)** | `05a720774addccb1e41a2bae2f7ab0ebc73315b8` |
| **Applicable skill(s)** | `wp-repository`, `wp-content-change`, `wp-security-review`, `wp-write-in-process-test`, `wp-testing` |
| **Plan status** | draft |

## 1. Scope

Fix the Conexão BR Irlanda event importer's National Heritage Week (`conexao_sources` type `heritage_week`, one source per county) so it no longer fails with HTTP 429 and zero imported events. Add polite outbound fetching with retry + exponential backoff + `Retry-After` respect on 429/5xx, a configurable inter-request delay between listing and detail-page requests, a per-source execution lock so manual and scheduled imports cannot run concurrently for the same source, structured import logging, and status reporting that distinguishes an empty listing, a failed request, a parsing failure and a database import failure. Event-importer plugin only; plus one new in-process test suite. No production write, no deploy, no activation, no plugins.json change.

## 2. Explicit non-goals

Do not bypass Cloudflare/CAPTCHA or any access control. heritageweek.ie is reachable (HTTP 200) here; the 429 is intermittent rate limiting handled with backoff, not evaded. Do not change the deliberate past-event date filter (`Conexao_Event_Date_Filter`); Heritage Week 2026 (August) is now past relative to 2026-11-22, so those events are skipped as past — documented as a finding, not fixed by weakening the filter. Do not introduce a new importer or source type; do not change `plugins.json`, production activation order, or production state; do not touch the Flutter/mobile repository.

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` (§0 change-safety, §13 AI-agent standard, §14 checklist); `docs/plugins/conexao-event-importer.md`; `docs/content-model.md`; `docs/testing.md`; `scripts/README.md`.

## 3a. Skill workflow

| Skill | Preconditions | Guardrails | Verification commands |
|---|---|---|---|
| `wp-repository` | baseline SHA, plan before write | no feature change mixed with refactor | `./scripts/run-tests.sh` |
| `wp-content-change` | dry-run first, six-step contract | portable identifiers | `./scripts/run-tests.sh --php` |
| `wp-security-review` | no secrets logged | never log tokens/cookies/credentials | `./scripts/lint.sh` |
| `wp-write-in-process-test` | `tests/bootstrap.php` only WP loader | one OS process per suite, isolated DB | `./scripts/run-tests.sh --php` |
| `wp-testing` | `CONEXAO_TEST_DB_NAME` isolated DB | no shared-DB writes in test | `./scripts/run-tests.sh` |

## 5. Proposed approach

**Chosen option:** Add a shared, filterable polite-fetch capability to `Conexao_Source_Base` (the base all sources already extend) and use it in `Conexao_Source_Heritage_Week`; add a per-source lock in the engine's `run_source()`; enrich log entries.

Design details:
- `Conexao_Source_Base` gains constants `HTTP_MAX_RETRIES` (3), `HTTP_BASE_BACKOFF_SECONDS` (2), `HTTP_REQUEST_DELAY_SECONDS` (1), `HTTP_MAX_RETRY_AFTER_SECONDS` (60) — mirroring the proven `Conexao_Eventbrite_Client` values (G4B/G5) so no second, conflicting retry policy exists.
- New `fetch_html_polite( $url, $args )`: before each outbound request (except the first of the walk) sleeps the inter-request delay; retries retryable statuses (429, 500, 502, 503, 504) up to MAX_RETRIES; on 429 honors `Retry-After` (delta-seconds or HTTP-date, capped) else exponential backoff; logs every attempt (url, attempt, http_status, duration, content-type, small body preview, retry_after, rate_limit_hits/streak); uses an injectable sleeper (`set_http_sleeper` + `conexao_event_importer_http_sleeper` filter, null = real sleep) so tests are deterministic and never sleep.
- `Conexao_Source_Heritage_Week::fetch_events()`: first listing page and detail pages use `fetch_html_polite()` instead of strict `fetch_html()` / tolerant `fetch_html_or_empty()`, so a transient 429 is retried instead of fatal. Logs events discovered per page and total. Keeps `fetch_complete`/`forward_link_blocked` semantics.
- `Conexao_Event_Importer_Engine::run_source()`: acquires `Conexao_Import_Continuation::acquire_lock( $source_id )` before running and releases in `finally`; if locked, returns `rate_limited`/source-skipped result ("another import is already running for this source").
- All logged context runs through `Conexao_Import_Log::add()` which sanitizes sensitive keys — no tokens/cookies/credentials ever logged.

Options rejected:
- Put retry only in Heritage Week: rejected, because the base-class seam (`fetch_html`/`request_get`) is shared by all sources and a shared polite-fetch is consistent with the existing `Conexao_Request_Reuse`/`Conexao_Request_Budget` layer.
- Remove the past-event date filter: rejected — deliberate, cross-cutting design; not the reported bug; changing it would affect all sources and import stale festivals.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/sources/abstract-class-source.php` | Add polite fetch (retry/backoff/Retry-After/delay), retryable-status helper, sleeper injection | Shared fetch seam; fixes 429 for all sources |
| `wp-content/plugins/conexao-event-importer/includes/sources/class-heritage-week-source.php` | Use `fetch_html_polite` for listing + detail pages; log events discovered; surface errors | The failing source |
| `wp-content/plugins/conexao-event-importer/includes/class-event-importer.php` | Acquire/release per-source lock in `run_source()`; return rate_limited when locked | Prevent concurrent/duplicate imports |
| `wp-content/plugins/conexao-event-importer/tests/test-heritage-week-429-retry.php` | New suite: simulate 429 → 200 via `pre_http_request` double; assert retry, backoff, Retry-After cap, no events lost, logs captured | Deterministic verification of the fix |
| `docs/reports/2026-11-22-heritage-week-importer-429.md` | Final report with root cause and live-test numbers | Required reporting |

## 7. Files explicitly expected NOT to change

- `plugins.json` — plugin set and load order unchanged.
- `wp-content/plugins/conexao-event-importer/includes/sources/class-eventbrite-client.php` and `class-eventbrite-pacing.php` — Eventbrite retry policy untouched.
- `wp-content/plugins/conexao-event-importer/includes/class-event-date-filter.php` — past-event filter is deliberate; not part of this bug.
- Anything under `wp-content/themes/`, `wp-content/plugins/conexao-event-runtime/`, `scripts/`, `tests/` (other than the new suite), `docs/` other than plan/report.


## 8. Content / data impact

- Records created / updated / deleted: none by the code change itself. Live verification writes to the LOCAL dev database only (events skipped as past → 0 created; no PT content, no production).
- Portable identifier strategy: N/A (no content writes).
- Does this write content? The code change writes nothing. A live import test may create/update events in the local DB only — allowed, not production.
- PT content changed? No.

## 9. Route / HTTP impact

- Routes added/removed/changed: none.
- Sitemap coverage: none.
- HTTP matrix rows: none (no new endpoint).

## 10. Polylang / English impact

- Translated post types / taxonomies affected: none.
- PT immutable: Yes (no content or slug changes).
- Language-scoped cache keys: none affected.

## 11. Security impact

- Capabilities checked: imports remain gated by existing admin capabilities (`manage_options`) in the dashboard.
- Nonces: unchanged.
- Sanitisation / escaping: log context sanitized by existing `sanitize_context` allowlist; body preview truncated and non-sensitive.
- SQL: none (uses existing WP APIs).
- REST surface change: none.
- Secrets: none logged; credentials never involved (unauthenticated public listing).

## 12. Performance impact

- Queries: none added.
- Caching: per-run response reuse (`Conexao_Request_Reuse`) already dedups identical requests; no new transients.
- Expected measurable improvement: a transient 429 no longer fails a whole source; retry + backoff recovers; inter-request delay reduces the chance of triggering rate limiting. Measured via unit test (exactly N requests on 429→200 sequence) and live fetch (HTTP 200, events found).

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | N/A |
| Verification of production result? | N/A (local-only tooling; production does not run the importer) |

## 14. Test plan

- In-process suite: `wp-content/plugins/conexao-event-importer/tests/test-heritage-week-429-retry.php` — deterministic, uses `pre_http_request` HTTP double (no live requests, no sleeps).
- Live verification: run `run_source('heritage_week_roscommon')` and `run_source('heritage_week_meath')` against the live site in the local dev container; assert HTTP 200, events found > 0, parsed fields, county set; report honestly that August-2026 events are skipped as past by the existing date filter.
- `./scripts/run-tests.sh --php` (new suite + existing suites), `./scripts/lint.sh`.
- Pass numerically: new suite assertions all pass; live fetch returns HTTP 200 and >0 events identified.

## 15. Acceptance matrix plan

No new HTTP acceptance rows (no new endpoint; acceptance targets the local WP, not heritageweek.ie).

## 16. Rollback plan

Revert the three plugin file changes and delete the new test file. No content was written to production. Pre-change state: baseline SHA `05a72077`; the working tree is clean so `git stash`/`git checkout --` restores it.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/reports/2026-11-22-heritage-week-importer-429.md` | New final report | `wp-update-docs` |

No existing document's procedure is removed.

## 18. Release implications

- Component version bump: `conexao-event-importer` header `Version: 1.10.0` → `1.11.0` (CHANGELOG + `docs/releases.md` entry) — done in the report step if a release is in scope.
- Effect on artifact allowlist: none (`plugins.json` unchanged).
- Is a release in scope? **No** — local-only tooling; this plan covers the fix and verification only. Release/deploy is a separate production decision.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Scope creep: rewriting the date filter or adding a queue | Medium | Explicitly listed as non-goals; keep change to fetch + lock + logging |
| Live 429 not reproducible (site permissive now) | High | Verify via deterministic HTTP-double unit test instead of relying on live 429 |
| Inter-request delay slows imports | Low | Configurable via filter, default 1s (matches Eventbrite) |
| Locking causes a stuck source if a worker dies | Medium | Lock has TTL (`Conexao_Import_Continuation::LOCK_TTL`) with takeover |

Risk of doing too much: avoided by not touching Eventbrite pacing, date filter, or plugin registry.

## 20. Verification gates

1. `./scripts/run-tests.sh --php` — all in-process suites pass, including new `test-heritage-week-429-retry.php` (n assertions).
2. `./scripts/lint.sh` — PHP syntax + PHPCS + PHPStan clean.
3. Live fetch against `https://www.heritageweek.ie/event-listings?q=&where%5B%5D=roscommon` → HTTP 200, >0 event cards identified, county Roscommon associated.
4. Live import run → result reported honestly (found/parsed/county correct; past-dated events skipped by existing date filter).

## 21. Completion criteria

Per `docs/engineering-standard.md` §14: every change is verifiable, no production write, behavior-preserving refactor not mixed with feature change, PT immutable, tests added that fail when the change is reverted, report contains real numbers. The task is complete when the new retry test passes, lint is clean, live fetch/parse verified, and the final report is written with real numbers.
