# P4 — handler inventory and gate verification

Verification-first audit. Nothing in this file was re-implemented: the two
existing gates (`is_handler_fetch_complete()` /
`is_handler_reconciliation_safe()` in `includes/class-event-importer.php`) and
the base-class completeness flag (`Conexao_Source_Base::$fetch_complete`) are the
only mechanisms, and they were verified, not rebuilt.

## 1. How the handler set was discovered

`Conexao_Event_Importer_Engine::get_source_handler()` is the single routing
point. It resolves a source by **type** (`icalendar`, `eventbrite`,
`heritage_week`), then by **normalized id** (`laois_tourism`, `heritage_week`,
`national_heritage_week`, `ivvcc`, `motorsport_ireland`, `mondello_park`,
`eventbrite`), and finally through the `conexao_event_importer_get_handler`
filter for third-party handlers.

Registered handler classes in this repository (complete list):

| # | Handler class | Routed by | Pagination | Checkpoint / resume |
|---|---|---|---|---|
| 1 | `Conexao_Source_Eventbrite` | type `eventbrite`, id `eventbrite` | multi-page (declared `page_count`, 49-page cap, time budget) | **yes** (`Conexao_Source_Enumeration_Checkpoint`) |
| 2 | `Conexao_Source_Heritage_Week` | type `heritage_week`, id `heritage_week` / `national_heritage_week` | multi-page forward walk per `where[]` | no |
| 3 | `Conexao_Source_ICalendar` | type `icalendar` (also `laois_tourism` when typed iCalendar) | single feed document | no |
| 4 | `Conexao_Source_Laois_Tourism` | id `laois_tourism` | single page | no |
| 5 | `Conexao_Source_Ivvcc` | id `ivvcc` | single calendar page + bounded detail pages | no |
| 6 | `Conexao_Source_Motorsport_Ireland` | id `motorsport_ireland` | single listing page + bounded detail pages | no |
| 7 | `Conexao_Source_Mondello_Park` | id `mondello_park` | single REST page + bounded detail pages | no |
| 8 | third-party handlers | `conexao_event_importer_get_handler` filter | unknown | unknown |

No third-party handler is registered anywhere in this repository (the filter has
no listeners in `wp-content/`), so the audited set is 1–7.

**Fail-open default.** `is_handler_fetch_complete()` and
`is_handler_reconciliation_safe()` both return `true` when the handler does not
implement the corresponding method. That is a deliberate backwards-compatibility
default: a handler written before the contract is treated as complete/safe. The
audit therefore had to establish, handler by handler, whether that default is
*correct* rather than merely convenient.

## 2. Gate implementation per handler

| Handler | `is_fetch_complete()` | `is_reconciliation_safe()` | Fail-open default relied on? Correct? |
|---|---|---|---|
| Eventbrite | overrides nothing; base flag cleared by `mark_fetch_incomplete()` on: time-budget truncation, declared-page/page-limit truncation, upstream pagination truncation, later-page fetch failure, rate-limited skip, and a thrown first page (exception path) | **implements** it; `false` once the walk resumed from a checkpoint (`mark_reconciliation_unsafe()`), `true` for a full non-resumed walk | No — both implemented |
| Heritage Week | base flag cleared on: time budget (during listing and during detail enrichment), page parse failure, forward link resolving to a visited/current page, revisited pagination URL, later-page fetch failure, page limit; first-page failure throws | not implemented → **fail-open `true`** | **Yes, correct.** No checkpoint, no resume, one forward walk per `where[]`; a stopped walk always marks itself incomplete. |
| iCalendar | base flag cleared on: no feed configured, empty feed body, unparsable/empty data | not implemented → `true` | **Yes, correct.** Single feed document, no resume. |
| Laois Tourism | base flag cleared on: empty body, unparsable HTML | not implemented → `true` | **Yes, correct.** Single page, no resume. |
| IVVCC | base flag cleared on: empty body (HTTP failures throw via `fetch_html()`) | not implemented → `true` | **Yes, correct.** One calendar page; detail failures never drop the event. |
| Motorsport Ireland | base flag cleared on: empty body (HTTP failures throw) | not implemented → `true` | **Yes, correct.** One listing page; detail failures never drop the event. |
| Mondello Park | base flag cleared on: empty body, invalid JSON (transport/non-200 now throw — see P3) | not implemented → `true` | **Yes, correct.** One REST page, no resume. |

## 3. The four walk outcomes, verified

| Outcome | Eventbrite | Heritage Week | Other five handlers |
|---|---|---|---|
| **Complete** | `true` — `test-source-pagination-completeness.php` (G2B complete traversal), `test-eventbrite-checkpoint.php` | `true` — `test-source-pagination-completeness.php` (G2A p1→p2→p3, and no-next-link single page) | `true` — `test-source-fetch-contract.php` (retrieved parsed listing with no cards) |
| **Truncated (time budget)** | `false` — `test-source-pagination-completeness.php` (G2B truncation) | `false` — `test-source-pagination-completeness.php` (time-budget fixture) | n/a (no internal budget) |
| **Failed (fetch exception)** | run recorded `failed`; no reconciliation — `test-source-fetch-failure.php`, `test-eventbrite-token-retirement.php` | same — `test-source-fetch-failure.php` | `test-source-fetch-contract.php` (Mondello, Laois, IVVCC, Motorsport, iCalendar) |
| **Checkpoint-resumed subset** | `is_reconciliation_safe() === false`, `is_fetch_complete() === true` — `test-eventbrite-checkpoint.php` (G10.10/10.11/10.15) | n/a (cannot resume) | n/a (cannot resume) |

## 4. Engine-level gate behaviour

Both gates are evaluated at the single call site in `run_source()` and passed to
`mark_missing_events()`, which re-evaluates them before issuing any candidate
query (see `04-p10-reconciliation-pagination.md`). Coverage:

| Acceptance item | Evidence |
|---|---|
| Both gates evaluated independently | `test-source-pagination-completeness.php` (incomplete → no reconciliation) and `test-eventbrite-checkpoint.php` (complete-but-resumed → no reconciliation) |
| A checkpoint-resumed subset triggers zero absence-based reconciliation | `test-eventbrite-checkpoint.php`; `test-reconciliation-pagination.php` (gate-first, zero candidate queries) |
| A failed/incomplete enumeration triggers zero missing-event reconciliation | `test-source-pagination-completeness.php` (Cavan scenario); `test-source-fetch-contract.php` (engine: failed and incomplete iCalendar walk); `test-reconciliation-pagination.php` |
| Existing live events are not marked `source_not_found` by a failed/incomplete walk | `test-source-pagination-completeness.php` (Cavan), `test-source-fetch-contract.php` (engine section), `test-reconciliation-pagination.php` (interruption / failed page) |
| Complete, reconciliation-safe enumeration still uses the existing mechanism | `test-source-pagination-completeness.php` (Cavan complete-walk control); `test-reconciliation-pagination.php` (complete pass marks all 401) |
| No handler returns `true` unconditionally | §2 above; every early-return path in the five P3-fixed handlers now marks the walk incomplete |

## 5. Gaps found, and what was done

| Gap | Type | Action |
|---|---|---|
| Mondello Park returned `array()` on a transport error **and** on a non-200, i.e. a dead source looked like an empty one, and `is_fetch_complete()` stayed `true` | **P3 contract** (with a P4 consequence) | Fixed: throws `Conexao_Source_Fetch_Exception`; empty/invalid payload marks the walk incomplete. Tested. |
| Laois Tourism returned `array()` on an empty body and on unparsable HTML while remaining "complete" | **P3 contract** (with a P4 consequence) | Fixed: both paths mark the walk incomplete. Tested. |
| IVVCC / Motorsport Ireland returned `array()` on a 200 with an empty body while remaining "complete" | **P3 contract** (with a P4 consequence) | Fixed: marks the walk incomplete. Tested. |
| iCalendar returned `array()` with no feed configured and with no data while remaining "complete" | **P3 contract** (with a P4 consequence) | Fixed: marks the walk incomplete. Tested. |
| Eventbrite / Heritage Week | already conforming | **No change.** Coverage documented above and reused, not duplicated. |

### Out-of-scope observation (recorded, not changed)

IVVCC and Motorsport Ireland wrap per-event processing in `try/catch` and skip a
single event when its parse throws; Motorsport Ireland also skips a card when the
detail enrichment throws. Such an event is absent from an otherwise complete
walk, so absence-based reconciliation could mark that one event
`source_not_found`. This is a **per-event** failure, not one of the four walk
outcomes in scope here, and fixing it belongs with identity/duplicate handling
(P11), which Stage 2 must not touch. It is recorded as a known limitation in
`07-preservation-limitations-and-status-inventory.md` §5. Heritage Week,
Mondello Park and Laois Tourism never drop an event on a detail failure (they
keep the listing-only event), so they are unaffected.

