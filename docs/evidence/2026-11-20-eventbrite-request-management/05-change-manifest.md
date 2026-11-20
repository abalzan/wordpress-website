# 05 — Change manifest

Exact files changed, added or deleted by this task, and why. No other paths
were touched.

## Modified (2)

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-eventbrite-client.php` | Added `WAF_ACTION_ALLOWLIST` and `WAF_ACTION_MAX_LENGTH` constants; added `get_waf_action()` method; wired a bounded `waf_action` context key into the non-retryable (405) log call. | Candidate E: retain ONLY the allowlisted `x-amzn-waf-action` value on 405 so the WAF classification is provable from the log. |
| `wp-content/plugins/conexao-event-importer/includes/class-import-log.php` | Added `waf_action` to the `keep_keys` allowlist in `add()`. | Candidate E: let the bounded `waf_action` value survive context sanitisation. |

## Added (1 code + 5 evidence)

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/tests/test-eventbrite-waf-diagnostics.php` | New focused test suite (7 sections, 32 assertions). | Candidate E verification: allowlist, sanitisation, length limit, sensitive-header exclusion, 405 non-retryable, 429 unchanged. |
| `docs/evidence/2026-11-20-eventbrite-request-management/01-current-request-flow.md` | Current request lifecycle and code references. | Phase 1 evidence. |
| `docs/evidence/2026-11-20-eventbrite-request-management/02-rate-limit-state-machine.md` | Breaker / cooldown / Retry-After behaviour. | Phase 1/2 evidence. |
| `docs/evidence/2026-11-20-eventbrite-request-management/03-design-decision.md` | Implement / defer / do-not-implement decisions (the Phase 3 sign-off artifact). | Phase 3 design note. |
| `docs/evidence/2026-11-20-eventbrite-request-management/04-test-results.md` | Test identity, commands, results, baseline distinctions. | Phase 5 evidence. |
| `docs/evidence/2026-11-20-eventbrite-request-management/05-change-manifest.md` | This file. | Phase 6 evidence. |

## Deleted (0)

Nothing was deleted.

## Superseded tests (0)

No existing test was modified, weakened or deleted. The Candidate E change is
purely additive and does not supersede any existing assertion. All
regression-sensitive suites (rate-limit, retry-policy, budget, checkpoint,
import-log, run-coordination, source-fetch-failure) pass unchanged.

## Counts

**2 modified, 1 code file added, 5 evidence files added, 0 deleted.**

## `wp-content/` changes (explicit call-out)

Both modified files and the new test are under
`wp-content/plugins/conexao-event-importer/`. No theme, no other plugin, no
`scripts/`, no `docs/` canonical file, no `plugins.json`, no `compose.yaml` was
touched.

## Out-of-scope confirmation

No change was made to:
- the 20/40/60s cooldown ladder;
- the shared breaker;
- the per-source cooldown;
- the enumeration checkpoint;
- the continuation scheduler;
- the request budget;
- the retry policy (`RETRYABLE_STATUSES` unchanged — 405 stays non-retryable);
- any provider request header (no User-Agent or other header change);
- any event data, source activation, or registry data;
- any production state.

No CAPTCHA/WAF bypass, no retry of 405, no new admin page, no Needs Review
restoration, no concurrency, no new scheduler, no new CPT.