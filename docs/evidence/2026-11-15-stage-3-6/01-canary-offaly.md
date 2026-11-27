# Canary — `eventbrite_offaly` (2 targets)

**Canary URL:** `https://www.eventbrite.ie/d/ireland--offaly/all-events/`
(resolved from the source registry entry `eventbrite_offaly`, status `active`).

**Targets under this URL:** post `20151` / `1998485272247` (*Country For Causes
Church Concert*) and post `138744` / `2003670834403` (*Free Health Checks in
Offaly- Edenderry Municipal District Offices*) — both `source_not_found`.

**Verdict: BLOCKED — CANARY MECHANISM FAILED.** The harness itself is isolated
and its request recording is complete, but the provider **never served a
discovery listing**: every request returned an AWS WAF JavaScript CAPTCHA
challenge with `HTTP 405`. The response structure is therefore not the expected
listing structure, and §5 requires a stop.

---

## 1. Standalone isolation proof

### 1.1 How WordPress is avoided

| Mechanism | Implementation |
|---|---|
| No WordPress core | `wp-load.php` / `wp-settings.php` / `wp-config.php` are never included; no `require` of any path outside the harness and one parser file |
| No plugin bootstrap | `conexao-event-importer.php` is never included, so `register_activation_hook`, the `shutdown → Conexao_Import_Continuation::run_due()` hook and the `wp_loaded → rearm_missing()` hook are **never registered** |
| No lifecycle hooks possible | The plugin API functions do not exist in the process (`add_action`, `do_action`, `apply_filters` all absent), so no hook could be registered even accidentally |
| No WordPress state | No option, transient, object cache, log, history or continuation store is opened; no database connection is opened at all |
| Parser reuse only | The single plugin file included is `includes/class-eventbrite-parser.php`. It calls **no** WordPress function (verified: `grep -n 'apply_filters\|do_action\|get_option\|wp_remote\|esc_html\|__(' class-eventbrite-parser.php` returns nothing). Its `defined('ABSPATH') || exit;` guard is satisfied by defining `ABSPATH` to a scratch path — a constant definition, which registers nothing |
| Not the source handler | `Conexao_Source_Eventbrite::fetch_events()` / `fetch_via_scrape()` and its pagination/checkpoint/termination logic are **not** loaded or called; the walk is the independent enumeration this task requires |

### 1.2 Isolation check output (verbatim)

Harness: `harness/isolation_check.php`; output:
`harness/isolation-check-output.txt`.

```
ABSPATH defined (scratch constant only)                    PASS
WordPress core NOT loaded (WPINC undefined)                PASS
WordPress core NOT loaded (wp_version undefined)           PASS
add_action() NOT available (no plugin API)                 PASS
do_action() NOT available                                  PASS
apply_filters() NOT available                              PASS
wp_remote_get() NOT available                              PASS
get_option() NOT available                                 PASS
wpdb class NOT available                                   PASS
Conexao_Eventbrite_Parser available (isolated)             PASS
Conexao_Eventbrite_Client NOT loaded                       PASS
Conexao_Source_Eventbrite NOT loaded                       PASS
Conexao_Import_Continuation NOT loaded                     PASS
Conexao_Import_Log NOT loaded                              PASS
No forbidden file in get_included_files()                  PASS

Included files:
  /tmp/stage36/isolation_check.php
  /tmp/stage36/lib.php
  /home/andrei/IdeaProjects/wordpress-website/wp-content/plugins/conexao-event-importer/includes/class-eventbrite-parser.php

Forbidden includes: (none)
Registered hooks: none possible (no plugin API function exists)

ISOLATION: PASS
```

Three included files; one of them a pure parser. **Isolation: proven.**

## 2. Policy provenance (no invented pacing)

The harness mirrors the plugin's existing bounded policy rather than inventing a
more aggressive one. Constants are copied, with their source:

| Harness constant | Value | Copied from |
|---|---|---|
| `S36_USER_AGENT` | `Mozilla/5.0 (Windows NT 10.0; …) Chrome/120.0.0.0 Safari/537.36` | `Conexao_Eventbrite_Client::USER_AGENT` |
| `S36_MAX_RETRIES` | 3 | `Conexao_Eventbrite_Client::MAX_RETRIES` |
| `S36_BASE_BACKOFF_SECONDS` | 2 (ladder 2/4/8 s) | `Conexao_Eventbrite_Client::BASE_BACKOFF_SECONDS` |
| `S36_RETRYABLE_STATUSES` | 429, 500, 502, 503, 504 | `Conexao_Eventbrite_Client::RETRYABLE_STATUSES` |
| `S36_MAX_RETRY_AFTER_SECONDS` | 60 | `Conexao_Eventbrite_Client::MAX_RETRY_AFTER_SECONDS` |
| `S36_PAGE_DELAY_SECONDS` | 2.0 | `Conexao_Eventbrite_Pacing::PAGE_DELAY_SECONDS` |
| `S36_RATE_LIMIT_BASE_SECONDS` | 20 (ladder 20/40/60 s) | `Conexao_Eventbrite_Pacing::RATE_LIMIT_BASE_SECONDS` |
| `S36_MAX_COOLDOWN_SECONDS` | 60 | `Conexao_Eventbrite_Pacing::MAX_COOLDOWN_SECONDS` |
| page URL form | page 1 = base URL, page *n* = `base?page=n` | `Conexao_Eventbrite_Client::build_page_url()` |
| task caps | 30 req/URL, 120 s/URL, 200 global | this task, §4.5 |

No second retry layer was stacked on the plugin's policy: the harness **is** the
only client in this task and it uses exactly those bounds. No P1 Run All breaker
was touched or invoked.

## 3. Canary request transcript

Three requests, all GET, all to the one authorised canary URL. No redirect was
followed (`CURLOPT_FOLLOWLOCATION = false`); no cookie jar; no credentials.

| # | UTC | Note | HTTP | Bytes | sha256 (16) | ms | Outcome |
|---|---|---|---|---|---|---|---|
| 1 | 2026-11-15T20:33:43Z | `pass1_page1` | **405** | 2543 | `d2883fd91482b221` | 121 | non-retryable → stop |
| 2 | 2026-11-15T20:34:12Z | `pass1_page1` (canary re-run after adding bounded body capture) | **405** | 2543 | `f6398cd1b8ee2781` | 125 | non-retryable → stop |
| 3 | 2026-11-15T20:34:29Z | `canary_diagnostic_full_headers` | **405** | 2543 | `d9e29573dfc4ed4f` | — | non-retryable → stop |

Request 2 exists because request 1 was recorded without a response-body excerpt,
so the cause of the 405 could not be read. Request 3 captured the complete header
set for the same reason. Both are charged to the ledger and the budgets. Three
responses are byte-identical in length and differ only by rotating challenge
token, so the condition is deterministic, not transient.

### 3.1 Complete response headers (request 3)

```
server: CloudFront
date: Sun, 15 Nov 2026 20:34:29 GMT
content-length: 2543
x-amzn-waf-action: captcha
cache-control: no-store, max-age=0
content-type: text/html; charset=UTF-8
access-control-allow-origin: *
access-control-max-age: 86400
access-control-allow-methods: OPTIONS,GET,POST
access-control-expose-headers: x-amzn-waf-action
x-cache: Error from cloudfront
via: 1.1 72c5918c420571f670847735e1f8f3ba.cloudfront.net (CloudFront)
x-amz-cf-pop: MAN51-P2
x-amz-cf-id: XpIpKHEpttF4F6UpiDvHXVoCpKQljeUPm9Pp4ELwaKoCqYtGtA3EwQ==
strict-transport-security: max-age=63072000; includeSubDomains; preload
```

No `Retry-After`, no `Link`, no `X-RateLimit-*`, no `X-Total-Count`. The
`x-amzn-waf-action: captcha` header is the operative fact.

### 3.2 Response body (sanitised excerpt)

```
Human Verification JavaScript is disabled In order to continue, you need to
verify that you're not a robot by solving a CAPTCHA puzzle. The CAPTCHA puzzle
requires JavaScript. Enable JavaScript and then reload the page.
```

The raw challenge HTML was **not** stored: it is a WAF page with inline script,
not listing evidence (§4.6 forbids storing unrelated page scripts). Its size,
sha256 and headers are recorded above and in `harness/request-ledger.jsonl`.

## 4. Response validation and G8/G10

| Requirement | Result |
|---|---|
| HTTP 200 with an HTML body | **NO** — HTTP 405 |
| `window.__SERVER_DATA__` present | **NO** — body has no server-data payload |
| `search_data.events.results` parseable | **NO** |
| `search_data.events.pagination` present | **NO** — `object_count`, `page_count`, `page_size` all unavailable |
| Parser (`Conexao_Eventbrite_Parser`) invoked on a listing | **NO** — a challenge page is not a listing; parsing it would prove nothing and the parser was therefore not exercised against provider data |
| Independent cross-check extractor | Not exercised for the same reason |
| **G8** `object_count > page_count × page_size` | **NOT EVALUATED** — no pagination metadata was served; the guard cannot be computed |
| **G10** page-1 start/end fingerprint, two passes | **NOT EVALUATED** — there was no page 1 of a listing |
| Discovery-listing request authority respected | **YES** — 3 requests, 1 URL, discovery only, no detail/page/image/robots request, no redirect followed |
| Side effects | **NONE** — see `09-preservation-confirmation.md` |

## 5. Canary target comparison

| post_id | `_event_source_id` | Classification | Reason |
|---|---|---|---|
| 20151 | 1998485272247 | **INDETERMINATE** | No discovery listing was served (HTTP 405 WAF CAPTCHA); no manifest exists to compare against |
| 138744 | 2003670834403 | **INDETERMINATE** | As above |

Neither target can be called PRESENT *or* ABSENT. Calling either would require
listing evidence that the provider refused to serve.

## 6. Mechanism verdict and §5 gate decision

| §5 pass condition | Result |
|---|---|
| Harness is isolated (no WordPress, no plugin bootstrap, no lifecycle hooks) | **PASS** — §1 |
| Request evidence is complete | **PASS** — §3, `harness/request-ledger.jsonl`, `04-request-log.md` |
| Response parsing is validated | **FAIL** — no listing was served; the response structure is a WAF challenge, not the expected listing |
| G8/G10 logic demonstrably applied | **FAIL** — no pagination metadata and no page 1 of a listing existed |
| No unauthorised request or side effect | **PASS** — §4, `09-preservation-confirmation.md` |

Per §5 — *"If … the parser cannot be validated, the response structure is
unexpected … stop with BLOCKED — CANARY MECHANISM FAILED. Do not fetch the other
URLs."* — the other seven URLs (**meath, laois, mayo, kerry, westmeath, clare,
leitrim**) were **not fetched**. No request was made to any county other than
Offaly.

## 7. Pagination-depth observation (§5 canary decision point)

**None available.** The Offaly response carried no pagination metadata, so there
is no observed page depth on which to re-consider the 30-request cap. This is not
a case of the canary revealing that the cap is too small; it is a case of the
canary revealing that no listing is served at all to this client.

## 8. Interpretation — what the 405 is, and what it is not

* It **is** an AWS WAF bot-control challenge (`x-amzn-waf-action: captcha`,
  served by CloudFront `MAN51-P2`), returned for a plain HTTP GET with the site's
  configured browser User-Agent.
* It is **not** a rate limit: no `429`, no `Retry-After`, no `X-RateLimit-*`.
* It is **not** negative evidence about the two Offaly events. An event cannot be
  inferred absent (or present) from a CAPTCHA page.
* It is **not** proof that Eventbrite has withdrawn the listing: the source
  registry records a successful Offaly import on 2026-11-14 14:24:51 (2 events)
  from the WordPress container. The challenge is specific to the request path used
  here (plain HTTP client, no JavaScript), which cannot satisfy a
  JavaScript CAPTCHA. Solving or evading that challenge is **out of scope** — it
  would require browser automation or header spoofing beyond the configured
  User-Agent, both prohibited by §6.2.
