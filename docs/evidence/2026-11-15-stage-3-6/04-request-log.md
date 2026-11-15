# Request log — every outbound request, no retries issued

All requests in Stage 3.6 are listed here. There are **three**, all `GET`, all to
the single authorised Offaly discovery URL. No retry was issued (`HTTP 405` is not
in the plugin's retryable set `{429, 500, 502, 503, 504}`), no redirect was
followed, and no other URL was requested.

Machine-readable duplicate: `harness/request-ledger.jsonl`
(sha256 `925a189f1d141c11f0badcb6d417c51a2cdbae620c2a66c190c49f026f019228`).

## 1. Common request shape

| Field | Value |
|---|---|
| Method | `GET` |
| url_id | `offaly` |
| Source ids mapped to the URL | `eventbrite_offaly` |
| Discovery URL | `https://www.eventbrite.ie/d/ireland--offaly/all-events/` |
| Query string added | **none** (page 1 = the configured URL, unmodified) |
| User-Agent | `Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36` (the plugin's configured value) |
| Extra request headers | `Accept: text/html,application/xhtml+xml` — no cookies, no auth, no spoofed client hints |
| Redirects | not followed (`CURLOPT_FOLLOWLOCATION = false`) |
| Client | PHP 8.5.4 cURL 8.18.0, standalone harness (no WordPress) |

## 2. Per-request records (§4.6 fields)

### Request 1

| Field | Value |
|---|---|
| Pass / page | pass 1, page 1 |
| Note | `pass1_page1` |
| Timestamp (UTC) | `2026-11-15T20:33:43Z` |
| HTTP method | `GET` |
| HTTP status | **405** |
| Response headers present | `content-type: text/html; charset=UTF-8`, `content-length: 2543`, `cache-control: no-store, max-age=0` |
| Headers absent | no `Retry-After`, no `Link`, no `X-RateLimit-*`, no `X-Total-Count`, no `X-Page` |
| Body size | 2543 bytes |
| Body sha256 | `d2883fd91482b221406f73a96a15f0b8a0a2340c193fce2eec9f81f679448568` |
| Elapsed | 121 ms |
| Retry number | 1 (first attempt) |
| Retry reason | — (non-retryable status) |
| Parsed / passed validation | **NO** — WAF CAPTCHA page, not a listing |

### Request 2

| Field | Value |
|---|---|
| Pass / page | pass 1, page 1 (canary re-run after bounded body capture was added) |
| Note | `pass1_page1` |
| Timestamp (UTC) | `2026-11-15T20:34:12Z` |
| HTTP method | `GET` |
| HTTP status | **405** |
| Response headers present | `content-type: text/html; charset=UTF-8`, `content-length: 2543`, `cache-control: no-store, max-age=0` |
| Body size | 2543 bytes |
| Body sha256 | `f6398cd1b8ee2781c9616460217e01628dc313a787cb5d515c3c14cf363e1e84` |
| Elapsed | 125 ms |
| Retry number | 1 (first attempt of the re-run) |
| Retry reason | — (non-retryable status) |
| Parsed / passed validation | **NO** |
| Body excerpt | `Human Verification JavaScript is disabled In order to continue, you need to verify that you're not a robot by solving a CAPTCHA puzzle. The CAPTCHA puzzle requires JavaScript. Enable JavaScript and then reload the page.` |

### Request 3

| Field | Value |
|---|---|
| Pass / page | pass 1, page 1 (diagnostic: complete header capture) |
| Note | `canary_diagnostic_full_headers` |
| Timestamp (UTC) | `2026-11-15T20:34:29Z` |
| HTTP method | `GET` |
| HTTP status | **405** |
| Response headers present | `server: CloudFront`, **`x-amzn-waf-action: captcha`**, `x-cache: Error from cloudfront`, `via: 1.1 72c5918c420571f670847735e1f8f3ba.cloudfront.net (CloudFront)`, `x-amz-cf-pop: MAN51-P2`, `x-amz-cf-id: XpIpKHEpttF4F6UpiDvHXVoCpKQljeUPm9Pp4ELwaKoCqYtGtA3EwQ==`, `content-type: text/html; charset=UTF-8`, `content-length: 2543`, `cache-control: no-store, max-age=0`, `strict-transport-security: max-age=63072000; includeSubDomains; preload`, `access-control-*` headers |
| Body size | 2543 bytes |
| Body sha256 | `d9e29573dfc4ed4f8c84d36c2a87b1973a93ee338da29cad9af061cae0f7ff36` |
| Elapsed | 87 ms |
| Retry number | 1 (first attempt) |
| Retry reason | — (non-retryable status) |
| Parsed / passed validation | **NO** |

## 3. Sanitised raw page evidence

No sanitised `search_data.events` payload exists, because no response was a
listing. The reproducible page-level representation for each request is the
recorded `HTTP status + complete relevant header set + body size + body sha256`
plus the bounded, tag-stripped body excerpt. The raw WAF HTML is deliberately not
stored (it is an anti-bot challenge with inline script, not listing evidence).

## 4. Budget after the run

| Scope | Used | Cap | Remaining |
|---|---|---|---|
| `offaly` (requests, incl. the re-run and the diagnostic) | **3** | 30 | 27 |
| `offaly` (elapsed wall clock across the three requests) | ≈ 46 s (20:33:43 → 20:34:29, including manual pauses) | 120 s | n/a — no walk started |
| `meath`, `laois`, `mayo`, `kerry`, `westmeath`, `clare`, `leitrim` | **0** | 30 each | 30 each |
| **Global** | **3** | 200 | **197** |
| Bytes fetched | **7,629** (3 × 2543) | — | — |

URL-to-source-ID grouping: **1:1 for all eight URLs**, so there is no shared-URL
budget to distribute and the per-URL ledger equals the per-source ledger.
