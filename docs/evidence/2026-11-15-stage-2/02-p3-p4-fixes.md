# P3 — source-handler fetch contract: conformance and fixes

## 1. The contract

| Situation | Handler must | Engine effect |
|---|---|---|
| Transport error, non-200, unreachable | `throw new Conexao_Source_Fetch_Exception( $message, $http_status )` | run recorded `failed`, `complete = false`, zero reconciliation |
| Retrieved and parsed (events or none) | `return array()` | normal import; `complete = true` |
| Retrieved but unparsable / empty body / no feed configured | `return array()` **and** `mark_fetch_incomplete()` | normal import of what was collected; `complete = false`, zero reconciliation |

The exception class is the existing `includes/class-source-fetch-exception.php`
(reused, not replaced). Parsed-empty is *not* an invented exception: it is
recorded through the existing completeness flag, so an unparsable page can never
masquerade as a complete empty listing and can never authorise absence-based
reconciliation.

## 2. Per-handler audit

| Handler | Before Stage 2 | Conforms now? | Change |
|---|---|---|---|
| **Eventbrite** | first page throws (`fetch_via_scrape`), later pages tolerated but mark the walk incomplete | **yes — unchanged** | none |
| **Heritage Week** | first page throws, later pages tolerated but mark the walk incomplete | **yes — unchanged** | none |
| **Mondello Park** | `is_wp_error()` → log + `return array()`; non-200 → log + `return array()`; empty body / invalid JSON → `return array()` | **no → fixed** | transport error and non-200 now **throw**; empty body and invalid JSON now mark the walk **incomplete** |
| **Laois Tourism** | `fetch_html()` throws (correct); empty body → `return array()`; unparsable HTML → log + `return array()` | **no → fixed** | empty body and unparsable HTML now mark the walk **incomplete** |
| **IVVCC** | `fetch_html()` throws (correct); empty body → `return array()` | **no → fixed** | empty body now marks the walk **incomplete** |
| **Motorsport Ireland** | `fetch_html()` throws (correct); empty body → `return array()` | **no → fixed** | empty body now marks the walk **incomplete** |
| **iCalendar** | `fetch_ical()` throws (correct); no feed configured → `return array()`; no data → `return array()` | **no → fixed** | missing feed and missing data now mark the walk **incomplete** |

Verified by reading every `return array()` in each handler's `fetch_events()` and
its helpers: after the fixes the only remaining silent empty returns are (a) the
documented "retrieved listing contains no events" cases and (b) the
"retrieved-but-unparsable → incomplete" cases. No undisclosed path swallows an
upstream failure.

### Paths deliberately left as valid empty results

| Handler | Path | Why it is a valid empty listing |
|---|---|---|
| Laois Tourism | page parsed, `find_event_nodes()` matched no container | the page was retrieved and parsed; the flexible selector set is the documented contract |
| IVVCC | calendar parsed, no event cards | the calendar was retrieved and parsed |
| Motorsport Ireland | listing parsed, no cards | the listing was retrieved and parsed |
| iCalendar | valid `VCALENDAR` with no `VEVENT` | the feed was retrieved and parsed |
| Eventbrite | page 1 parsed, zero results | the discovery payload was retrieved and parsed |
| Heritage Week | page parsed, zero cards | the listing was retrieved and parsed |

A selector drift on Laois Tourism would therefore still look like an empty
listing. That risk is a property of the source contract (and of its flexible
selectors), not of this fetch contract, and it is recorded as a residual risk in
`07-preservation-limitations-and-status-inventory.md`.

## 3. Tests

New suite `wp-content/plugins/conexao-event-importer/tests/test-source-fetch-contract.php`
(55 assertions) proves, per handler:

- transport failure → `Conexao_Source_Fetch_Exception` with status `0`;
- HTTP failure (403/500/503) → `Conexao_Source_Fetch_Exception` with that status;
- empty body / unparsable payload / missing feed → `array()` **and**
  `is_fetch_complete() === false`;
- retrieved, parsed, genuinely empty listing → `array()` **and**
  `is_fetch_complete() === true`;
- engine level (iCalendar): a 403 run is recorded `failed`, `complete=false`,
  `found=0`, with a fatal error, and an incomplete run is `complete=false`;
- in both engine cases a pre-existing live event of that source **remains
  published** (no reconciliation).

Eventbrite and Heritage Week were **not** re-tested here: their contract is
already proven by `test-source-fetch-failure.php`,
`test-source-pagination-completeness.php`, `test-eventbrite-checkpoint.php` and
`test-stage1-run-coordination.php`. Duplicating them would add no coverage.
