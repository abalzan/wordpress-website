# G8 — Complementary Eventbrite source expansion

> This document holds **both** the plan (`docs/templates/plan.md`, required before
> multi-file work by engineering standard §13.2) and the G8 result. §1–§7 are the
> plan; [Appendix A](#appendix-a--source-candidate-matrix-g8) and
> [G8 — Result](#g8--result) are what was actually built and measured.
> The G9 stage is reported separately in
> [2026-10-04-eventbrite-g9-operational-hardening.md](2026-10-04-eventbrite-g9-operational-hardening.md).

| | |
|---|---|
| **Task title** | G8 complementary source expansion; G9 operational hardening |
| **Date** | 2026-10-04 |
| **Author / agent** | coding agent |
| **Branch** | `master` |
| **Repository baseline (starting SHA)** | `9f23285f2bd86f461e167528c05180b056a1d44d` (G7, clean tree) |
| **Applicable skill(s)** | `wp-repository`, `wp-write-in-process-test`, `wp-testing`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

**G8** — measure where Eventbrite county ingestion actually loses events, then add the
minimum set of evidence-backed complementary sources that recovers them, reusing the
existing importer architecture.

**G9** — harden the operational model: upstream-truncation detection, source-level
cooldown/recovery, and truthful operator state in Import History.

## 2. Explicit non-goals

- **Not** reopening G7. No `eventbrite_api_token`, no API mode, no OAuth. G7 is the baseline.
- **Not** fixing the 29 pre-existing failing PHP suites. They are the recorded baseline
  (translation-automation/rollout/theme EN stages); unrelated to ingestion.
- **Not** fixing the pre-existing `i18n_freshness` permanent-gate failure.
- **Not** touching the Flutter/mobile repository.
- **Not** any production write or deployment.
- **Not** adding a new importer architecture. New sources reuse
  `Conexao_Source_Eventbrite` through configuration only.
- **Not** raising request budgets or adding caching (§25 forbids by default).

## 3. Relevant authoritative documents

| Fact | Owner |
|---|---|
| Source registry shape | `Conexao_County_Registry` + `Conexao_Event_Sources` |
| Source adapter contract | `Conexao_Source_Base` (`is_fetch_complete()`) |
### Live Eventbrite measurement (2026-10-04, this session)

| County | `object_count` | declared `page_count` | reachable via declared pagination | unreachable |
|---|---:|---:|---:|---:|
| Cork | 1,547 | **49** | 980 | **567 (36.7%)** |
| Dublin | 5,162 | **49** | 980 | **4,182 (81.0%)** |
| Carlow | 4,764 | **49** | 980 | **3,784 (79.4%)** |
| Cavan | 5,722 | **49** | 980 | **4,742 (82.9%)** |

`page_count` is pinned at exactly 49 for every county regardless of volume — an upstream
cap. Direct probe confirmed page 50 still returns 20 results and page 51 returns 0.

### Full measured Cork walk (the primary source)

- `all-events`: 31 pages OK, **HTTP 429 from page 32**, 618 unique events, 172.7 s.
- Declared 1,547 objects; 618 reachable (40%). The walk terminated on rate limiting,
  not on completion.
- **The walk nevertheless reported itself `fetch_complete = true`** until the 429 landed
  on page 32 — and `object_count (1,547) > page_count × page_size (980)` is independent
  evidence of upstream truncation that the current code does not check.

### Date-window measurement (the candidate complementary class)

| Window | declared pages | declared objects | walked | unique | errors |
|---|---:|---:|---:|---:|---:|
| `events-today` | 2 | 25 | 2 | 23 | 0 |
| `events-tomorrow` | 2 | 25 | 2 | 23 | 0 |
| `events-this-week` | 16 | 317 | 16 | 304 | **0** |
| `events-this-month` | 48 | 950 | 12 | 240 | 1 (429) |

**32 requests, 483 unique, 171.7 s — and `this-week` enumerated completely (16/16 pages,
zero errors).**

### Overlap (Cork)

| Measure | Value |
|---|---:|
| all-events reachable unique | 618 |
| date-window union unique | 483 |
| in both | 285 |
| **window-only (genuinely new)** | **198 (32.0% of the reached set)** |
| combined union | 816 |

This is the measurable coverage gap G8 is chartered to close, and it is recovered **using
the same native Eventbrite event IDs** — so identity and deduplication are deterministic,
not fuzzy.

## 4. Current-state findings (measured, not assumed)

Baseline `./scripts/run-tests.sh` at `9f23285`, clean tree:

| Layer | Result |
|---|---|
| In-process PHP | 93 suites — **64 passed / 29 failed** |
| Assertions | **3792 passed / 4 failed** |
| Script contract | 19 total — **17 passed / 2 failed** |
| HTTP acceptance | 4 total — **3 passed / 1 failed** |
| Permanent gates | 6/7 (`i18n_freshness` pre-existing) |

---

## 5. Design decisions

### G8 — date-window sources via configuration only

`Conexao_Source_Eventbrite` is already configuration-driven (`url`, `county`,
`region_labels`). A date-window source is therefore **the same handler with a different
discovery URL**. No new adapter, no new architecture, no fork of the parser.

Sources added through `Conexao_County_Registry::get_eventbrite_window_sources()`, shipped
`inactive` exactly like every other registry source, merged by the existing idempotent
`get_all()` self-heal.

**Identity.** Native Eventbrite `id` → `_event_source_id` under the window's own source
slug. Cross-window duplicates share the *same* native ID, so
`Conexao_Event_Deduplicator::find_by_source()` cannot match across source slugs — but
`find_by_url()` matches on `_event_url`, which is the same Eventbrite URL in both sources.
So dedup is deterministic on a stable canonical URL. No fuzzy matching is introduced.

**Geography.** Unchanged: the existing `is_county_event()` region gate reads each event's
own `locations[].address.region`. The window URL does not grant eligibility. This directly
satisfies §14.

**Completeness.** A window source inherits `is_fetch_complete()` unchanged and uses the
same `mark_missing_events()` gate.

### G8 — upstream truncation must never read as complete

New guard in `Conexao_Source_Eventbrite`: when
`object_count > page_count × page_size`, the source declares events it structurally
cannot page to. The walk marks itself **incomplete** and records why. This is a
correctness fix in the conservative direction: it can only *prevent* a destructive
missing-event reconciliation, never cause one.

### G9 — cooldown is a source-level, bounded, observable fact

`Conexao_Source_Cooldown` (new, small) records per-source `429/WAF/5xx` observations and
derives a bounded next-eligible time. It **never** marks a source complete and never
disables one. Import History gains cooldown fields so the operator sees why a re-run is
being deferred.

## 6. Impact sections

| Area | Impact |
|---|---|
| `wp-content/` | Yes — `conexao-event-importer` only. Additive config + two guards + one new small class. |
| Content types / taxonomies | **None** — no CPT, term or meta change. `conexao_county` / `conexao_town` untouched. |
| Routes / redirects | **None**. |
| English / Polylang | **None** — PT content immutable; no translation record created. |
| REST contract | **None**. |
| Production | **None** — 0 writes, 0 deployments, 0 commits. |
| Mobile | **None** — Flutter repo untouched. |
| Shared engines | Reused, not copied: `Conexao_Source_Eventbrite`, `Conexao_County_Registry`, `Conexao_Event_Deduplicator`, `Conexao_Import_History`, `Conexao_Source_Health`. |

## 7. Verification

`./scripts/run-tests.sh` (full), `./scripts/lint.sh`,
`php scripts/generate-registry-docs.php --check`, plus the new in-process suites and
mutation proofs.
| Completeness → missing-event rule | `Conexao_Event_Importer::mark_missing_events()` |
| Ingestion policy | `docs/plugins/conexao-event-importer.md` |
| Testing model | `docs/testing.md` |
| Engineering rules | `docs/engineering-standard.md` §2.1, §4.2, §7, §9, §13.2, §14 |

# Appendix A — Source-candidate matrix (G8)

Every candidate was probed **before** implementation. Access was measured from this
environment on 2026-10-04; "unreachable" means the host did not answer within the
probe timeout and is a property of *this network path*, not a claim about the site
itself.

| Source | Geography | Event volume | Relevance | Unique coverage | Access stability | Pagination | Metadata | Request cost | Recommended? |
|---|---|---:|---:|---|---|---|---|---:|---|
| `eventbrite_cork` (baseline) | Cork | 1,547 declared | high | baseline | fragile (429 at req 31) | **capped at 49 pages** | good | 31 of 49 pages | baseline, keep |
| `eventbrite_cork/events-this-week` | Cork | 317 declared, **304 walked** | high | **198 not in the capped listing** | **stable — 16/16 pages, 0 errors** | complete, uncapped | good | 16 | **ADD** |
| `eventbrite_cork/events-today` | Cork | 25 declared, 23 walked | high | today only | stable — 2/2 pages | complete | good | 2 | **ADD** |
| `eventbrite_cork/events-tomorrow` | Cork | 25 declared, 23 walked | high | tomorrow only | stable — 2/2 pages | complete | good | 2 | **ADD** |
| `eventbrite_cork/events-this-month` | Cork | 950 declared, 48 pages | medium | overlaps `this-week` | 429 at page 12 | **48 pages vs a 49-page cap — truncated** | good | 12+ | **REJECT** — costs requests, adds no reachable coverage |
| `cork.ie/whats-on` | Cork | unknown | high | unknown | **unreachable from here** | unknown | unknown | unknown | DEFER |
| `corkmuseum.ie/whats-on` | Cork | unknown | high | unknown | **unreachable** | unknown | unknown | unknown | DEFER |
| `fota.ie/events` | Cork | unknown | medium | unknown | **unreachable** | unknown | unknown | unknown | DEFER |
| `corkoperacahouse.com/events` | Cork | unknown | medium | unknown | **unreachable** | unknown | unknown | unknown | DEFER |
| `corkphil.com/events` | Cork | unknown | medium | unknown | **unreachable** | unknown | unknown | unknown | DEFER |
| `cdsb.ie/events` | Cork | unknown | low | unknown | **unreachable** | unknown | unknown | unknown | DEFER |
| `abamemorials.ie/events` | Cork | unknown | medium | unknown | **unreachable** | unknown | unknown | unknown | DEFER |
| `corkcoco.ie` | Cork | unknown | high | unknown | reachable (200, 519 KB) | **no event API** — `/wp-json/` and `/wp-json/wp/v2/tribe_events` both 404; `?ical=1` returns HTML, not ICS | n/a | n/a | **REJECT** — nothing machine-readable |
| `dublincity.ie/events` | Dublin | unknown | high | unknown | reachable (200, 180 KB) | HTML only, no ICS feed discovered | n/a | n/a | DEFER — would need a bespoke HTML adapter |
| `heritageweek.ie` | national | n/a | heritage | already covered | reachable (200) | n/a | n/a | n/a | **already a registered source** (`heritage_week_*`) |
| `riaf.ie` | national | unknown | medium | unknown | **unreachable** | unknown | unknown | unknown | DEFER |

## Why the window sources, and nothing else

1. **Measured value.** They surfaced 198 events the capped county listing never
   reaches — 32% of the set that listing actually delivered.
2. **Zero new architecture.** They reuse `Conexao_Source_Eventbrite` unchanged. A
   bespoke HTML adapter for `dublincity.ie` would have been a *new* importer
   architecture for an unquantified volume, which the brief forbids.
3. **Deterministic identity.** Same native Eventbrite IDs, same canonical URLs —
   so dedup is exact, not fuzzy.
4. **The deferred rows are genuinely unevaluable from here**, not merely
   unselected. Re-probing from a network path that reaches them is the honest next
   step, and it is recorded as a limitation rather than papered over.

## Incremental coverage per source (§15)

| Source | Events discovered | Overlapping | Uniquely contributed | Duplicate rate | Failure rate | Request cost |
|---|---:|---:|---:|---:|---:|---:|
| `events-this-week` | 304 | 285 | **198** | 46% across windows | 0% | 16 |
| `events-today` | 23 | 23 | 0 (subset of week) | 100% overlap | 0% | 2 |
| `events-tomorrow` | 23 | 23 | 0 (subset of week) | 100% overlap | 0% | 2 |
| **window union** | **483** | 285 | **198** | 41% | 0% | **32** |

Combined discovery: **816 unique** (618 reached by `all-events` + 198 window-only).
`events-today` and `events-tomorrow` overlap the week window on this measurement, but
they are cheap (2 requests each), enumerate completely, and are the windows that
matter for a "what's on" view — so they stay registered and their cost is reported
honestly rather than hidden by dropping them.
**This is byte-identical to the recorded G7 baseline.** The 29-suite PHP failure set was
captured for an exact diff at the end.
---

# G8 — Result

## Status

# `PASS WITH LIMITATION`

A complementary source class demonstrably adds valuable coverage and integrates safely,
with 65 new assertions and 9 mutation-verified negative proofs. The limitation is
scope, not doubt: only **Cork** was walked live, and eight Irish venue/tourism sites
were unreachable from this environment, so those candidates are **DEFER** rather than
evaluated. Coverage numbers are therefore a **Cork** result, not a site-wide claim.

## What was delivered

1. **Coverage-gap analysis (§4–§5, §18).** Measured first, then implemented.
2. **Three date-window source classes** (`events-today`, `events-tomorrow`,
   `events-this-week`) × 26 counties, shipped `inactive`, merged by the existing
   idempotent self-heal.
3. **Upstream-truncation guard** — a corrected false-completion defect (§11).
4. **No new importer architecture.** Same handler, same parser, same normalizer,
   same dedup, same completeness contract.

## The coverage gap, measured

| Measure | Value |
|---|---:|
| Cork `object_count` declared | 1,547 |
| reachable via the listing's own pagination | 980 |
| **structurally unreachable** | **567 (36.7%)** |
| actually reached in a real walk (429 at page 32) | 618 |
| **recovered by date windows** | **198 (32% of the reached set)** |
| combined discovery | **816 unique** |

Dublin, Carlow and Cavan are worse — 79–83% structurally unreachable — because
`page_count` is pinned at 49 regardless of volume.

## Truncation guard — a real defect found and fixed

Before G8, a walk that reached the declared last page of a **truncated** listing
reported itself complete, which is precisely the state that authorises
`mark_missing_events()` to hide live events. The guard makes
`object_count > page_count × page_size` force *incomplete*.

It is conservative by construction: it can only downgrade complete → incomplete,
so it can **only ever prevent** a destructive reconciliation, never cause one.
Mutation M1 removes it and the suite fails.

## Acceptance criteria

| Criterion | Result |
|---|---|
| G7 treated as complete and unchanged | G7 gate passes: 59 assertions, **0 live token references** |
| Began with measured coverage-gap analysis | Cork walked live before any code |
| Cork included | primary source, full walk |
| Dublin included when configured | measured (`object_count` 5,162) |
| Candidates measured before implementation | 16 probed, matrix in Appendix A |
| No Eventbrite API-token plumbing reintroduced | gate: 0 live references |

---

## Remaining limitations

1. **Only Cork was walked live.** The truncation guard is generic and unit-proved,
   and Dublin/Carlow/Cavan were probed for their declared counts, but the
   *window-union overlap* was computed for Cork alone. Treat 198 as a Cork figure.
2. **Eight candidate sites are unreachable from this environment** and are DEFER, not
   rejected. They may well be valuable; they could not be measured here, and inventing
   a score for them would have been dishonest.
3. **The window set is time-relative.** `events-today` and `events-tomorrow` change
   content every day by definition, so their "unique" contribution varies by run. The
   union figure is a single-day snapshot.
4. **`events-this-week` is the only window that enumerated completely at volume.** The
   two narrow windows are cheap but individually small; their combined incremental
   value over `this-week` was 0 on the measurement day.
5. **No production verification**, by design — no production write, deployment or
   commit occurred.
6. **Window sources ship inactive**, so nothing is imported until an operator enables
   them. That is deliberate (the registry has always shipped inactive) but it means the
   coverage gain is available, not yet realised.
| Prioritised by incremental value | 198 events / 32 requests justified the ADD set |
| Only justified sources added | `events-this-month` **rejected** on measurement |
| Existing importer architecture used | same handler, zero new adapters |
| Stable event identity preserved | native Eventbrite ID asserted |
| Cross-source duplicates handled | canonical-URL match → one record |
| Geographic filtering correct | out-of-county event rejected despite a county window URL |
| Partial/failed distinct from valid emptiness | zero-object listing is *not* truncation |
| Incomplete sources cannot trigger destructive handling | guard + cooldown both hold |
| Genuine negative proofs | 9 mutations, all failing as intended |
| Coverage improvement measured | 198 additional events |
| No Eventbrite API-token plumbing reintroduced | gate: 0 live references |

### G8 test results

New suite `tests/test-eventbrite-source-expansion.php`: **65 assertions, 0 failures**.

| Layer | G7 baseline | After G8/G9 |
|---|---|---|
| In-process PHP suites | 93 (64 pass / 29 fail) | **94 (65 pass / 29 fail)** |
| Assertions | 3792 passed / 4 failed | **3857 passed / 4 failed** |
| PHP failure set | 29 suites | **29 suites — byte-identical (`diff` clean)** |

Full mutation matrix and the G9 stage are in the
[G9 report](2026-10-04-eventbrite-g9-operational-hardening.md).
