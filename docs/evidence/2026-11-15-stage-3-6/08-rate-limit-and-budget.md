# Rate limits, retry decisions and budget ledger

## 1. Budget ledger

Counted **per unique resolved URL** (not per source id), and globally. As all
eight URLs map to exactly one source id each, the per-URL and per-source numbers
are the same.

| Scope | Requests used | Cap | Remaining | Bytes | Elapsed (requests only) |
|---|---|---|---|---|---|
| `offaly` | **3** | 30 | 27 | 7,629 | 333 ms of request time (121 + 125 + 87) |
| `meath` | 0 | 30 | 30 | 0 | — |
| `laois` | 0 | 30 | 30 | 0 | — |
| `mayo` | 0 | 30 | 30 | 0 | — |
| `kerry` | 0 | 30 | 30 | 0 | — |
| `westmeath` | 0 | 30 | 30 | 0 | — |
| `clare` | 0 | 30 | 30 | 0 | — |
| `leitrim` | 0 | 30 | 30 | 0 | — |
| **Global** | **3** | **200** | **197** | **7,629** | 333 ms |

No URL was stopped by a budget. The three Offaly requests were terminated by the
**non-retryable HTTP 405**, not by request or time exhaustion. The largest single
per-URL consumption was 3/30 (10%) and the global consumption was 3/200 (1.5%).

## 2. Pacing actually applied

| Parameter | Value | Applied? |
|---|---|---|
| Inter-request page delay | 2.0 s (`Pacing::PAGE_DELAY_SECONDS`) | Yes — request 2 followed request 1 by 29 s, request 3 followed by 17 s; the 2 s floor was never approached |
| Retry attempts | max 3 (`Client::MAX_RETRIES`) | Not exercised — status was non-retryable |
| Exponential backoff (5xx/transport) | 2/4/8 s | Not exercised |
| 429 cooldown ladder | 20/40/60 s (`Pacing::RATE_LIMIT_BASE_SECONDS` × retry, capped 60) | Not exercised |
| `Retry-After` honouring | `min(header, 60 s)` | Not applicable — the header was absent |
| Non-retryable stop set | everything outside `{429, 500, 502, 503, 504}` | **Yes** — `405` stopped the URL immediately on each attempt |

No second retry layer was stacked on top of the plugin's policy, and no P1 Run All
breaker was invoked or modified.

## 3. Rate limiting observed

| Signal | Observed |
|---|---|
| HTTP 429 | **no** |
| `Retry-After` header | **no** |
| `X-RateLimit-Limit` / `-Remaining` / `-Reset` | **no** |
| Cooldown wait | **none applied** |
| Bot-control challenge | **yes** — `x-amzn-waf-action: captcha`, `HTTP 405`, CloudFront `MAN51-P2` |

A bot-control CAPTCHA is not a rate limit. Waiting would not have changed the
outcome, and no wait was manufactured.

## 4. Retry and stop decisions, per request

| # | Status | Retryable per plugin policy? | Decision |
|---|---|---|---|
| 1 | 405 | no | Stop the URL; record `non_retryable` |
| 2 | 405 | no | Stop the URL; record `non_retryable` |
| 3 | 405 | no | Stop; record `non_retryable` |

Requests 2 and 3 were **operator-initiated diagnostics**, not automatic retries:
request 1 was recorded without a response-body excerpt, so the cause of the 405 was
unreadable and the canary verdict would have been a guess. Both were charged to the
ledger and to the budgets, and both are documented in `01-canary-offaly.md` §3.

## 5. URLs stopped by budget

**None.**

## 6. Remaining capacity (for a future authorised run)

197 global requests remain, and every URL other than Offaly has its full 30-request
allowance. None of that capacity was spent, because §5 forbids proceeding past a
failed canary and because additional requests would have hit the same WAF wall.
