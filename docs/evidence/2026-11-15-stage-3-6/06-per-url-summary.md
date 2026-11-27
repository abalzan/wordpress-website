# Per-URL summary

Eight unique discovery URLs, each mapped to exactly one source id (no shared
URLs). Only the canary URL was requested.

| url_id | Source ids mapped | Targets | Attempted | Requests | Bytes | HTTP | Pages walked | Present | Absent | Indeterminate | Enumeration state |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `offaly` | `eventbrite_offaly` | 2 | **YES** | 3 | 7,629 | **405** | 0 | 0 | 0 | **2** | `FAILED` — WAF CAPTCHA challenge, no listing served |
| `meath` | `eventbrite_meath` | 22 | no | 0 | 0 | — | 0 | — | — | **22** | `NOT_ATTEMPTED` — canary gate (§5) |
| `laois` | `eventbrite_laois` | 17 | no | 0 | 0 | — | 0 | — | — | **17** | `NOT_ATTEMPTED` — canary gate (§5) |
| `mayo` | `eventbrite_mayo` | 14 | no | 0 | 0 | — | 0 | — | — | **14** | `NOT_ATTEMPTED` — canary gate (§5) |
| `kerry` | `eventbrite_kerry` | 7 | no | 0 | 0 | — | 0 | — | — | **7** | `NOT_ATTEMPTED` — canary gate (§5) |
| `westmeath` | `eventbrite_westmeath` | 5 | no | 0 | 0 | — | 0 | — | — | **5** | `NOT_ATTEMPTED` — canary gate (§5) |
| `clare` | `eventbrite_clare` | 5 | no | 0 | 0 | — | 0 | — | — | **5** | `NOT_ATTEMPTED` — canary gate (§5) |
| `leitrim` | `eventbrite_leitrim` | 4 | no | 0 | 0 | — | 0 | — | — | **4** | `NOT_ATTEMPTED` — canary gate (§5) |
| **Total** | 8 sources | **76** | 1 of 8 | **3** | **7,629** | — | 0 | **0** | **0** | **76** | — |

## Declared pagination, reachable ceiling, G8, G10

| url_id | `object_count` | `page_count` | `page_size` | Reachable ceiling | G8 result | G10 result |
|---|---|---|---|---|---|---|
| `offaly` | not served | not served | not served | not computable | **NOT EVALUATED** (no pagination metadata) | **NOT EVALUATED** (no page 1 of a listing) |
| all other URLs | not requested | not requested | not requested | — | — | — |

## Reasons for the incomplete results

* **`offaly`** — the provider's WAF returned `HTTP 405` with
  `x-amzn-waf-action: captcha` and a JavaScript-CAPTCHA interstitial on all three
  requests. No `window.__SERVER_DATA__` payload was present, so nothing could be
  parsed, no manifest was created, and neither target could be classified beyond
  `INDETERMINATE`. This is a mechanism/provider-access failure, not a listing
  result.
* **The other seven URLs** — not attempted, per §5: *"If … the response structure
  is unexpected … stop with BLOCKED … Do not fetch the other URLs."* Spending
  seven more requests against the same WAF would produce seven more challenge
  pages and no additional evidence.

## Rate limits and errors

No `429`, no `Retry-After`, no `X-RateLimit-*` header, no transport error, no
parse error (no listing to parse), no redirect. Three deterministic `405`s.
