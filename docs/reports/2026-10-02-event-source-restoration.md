# Event Source Restoration — Why the registry reported 6 sources, and how the full 58 were recovered

| | |
|---|---|
| **Task** | Investigate and restore the event-source registry that had fallen to 6 sources |
| **Date** | 2026-10-02 |
| **Branch** | `i18n` (baseline `205ecec`) |
| **Scope** | `wp-content/plugins/conexao-event-importer` — source registry only |
| **Production writes** | **None.** Production was read only (public, unauthenticated REST). |

## 0. Verdict

The site did **not** lose event sources to a destructive commit, a bad restore or a
deleted file. **Nothing was ever deleted.** The registry reported 6 sources because the
52 county sources were never reachable except through a manual, undocumented-in-code
`wp eval` operator step.

`Conexao_County_Registry` (26 counties x 2 providers = 52 sources) has been present and
correct since Stage B, and `Conexao_Event_Sources::seed_county_sources()` has existed
since then too. But `get_defaults()` returned only the 6 legacy Laois-era sources, and
**`get_all()` — the single function every read goes through — never merged the county
registry.** Any environment not built by hand-running `seed_county_sources()` reported 6.

The project's own history records this exact finding, and fixed it **by hand**
(`docs/importers/events-expansion-stage-c1-pilot-report.md` §2.2):

> **Critical pre-flight finding — county sources were NOT seeded.** The DB contained only
> the 6 default sources. `seed_county_sources()` existed but had never been invoked.
> Seeded all 52 county sources (idempotent) before activation.

That manual fix lived in one local database and was never promoted into code, so every
rebuild of that database silently reverted to 6. This was a **provisioning gap, not data
loss** — which matters, because nothing needed recovering from a backup.

## 1. Current state — the six sources (before)

Measured locally before the change (`Conexao_Event_Sources::get_all()` → 6):

| # | Source ID | Name | Type | URL | County | Status | Events |
|---|---|---|---|---|---|---|---|
| 1 | `laois_tourism` | Laois Tourism | `icalendar` | `laoistourism.ie/events/photo/?hide_subsequent_recurrences=1&ical=1` | Laois | **active** | 13 |
| 2 | `heritage_week` | National Heritage Week | `website` | `heritageweek.ie/event-listings?…where[]=laois` | Laois | **active** | 0 |
| 3 | `eventbrite` | Eventbrite — Laois | `eventbrite` | `eventbrite.ie/d/ireland--laois/all-events/` | Laois | **active** | 0 |
| 4 | `ivvcc` | IVVCC — Irish Veteran & Vintage Car Club | `website` | `ivvcc.ie/upcoming-events-calendar/` | — | inactive | 0 |
| 5 | `motorsport_ireland` | Motorsport Ireland | `website` | `motorsportireland.com/events` | — | inactive | 0 |
| 6 | `mondello_park` | Mondello Park | `website` | `mondellopark.ie/wp-json/wp/v2/events?per_page=100` | Kildare | inactive | 0 |

All six still ship in `get_defaults()` and all six remain after the fix.

## 2. Historical state — the largest legitimate set

**Largest verified legitimate source set: 58 sources** (6 legacy + 52 county).

Evidence:
- `class-county-registry.php` — 26 counties, each with `eb_slug`, `eb_region_labels`, `hw_where`.
- `events-expansion-stage-c1-pilot-report.md:57` — "Total sources in DB | **58** (6 default + 52 county)".
- `events-expansion-stage-d-rollout-manifest.json` — 46 remaining sources rolled out, 1,231 events, 0 duplicates.
- Production read-only: 32 distinct `_event_source` values across 1,807 events.

## 3. Root cause

`get_all()` is the read path for the admin UI, the CLI and the importer. It merged missing
## 4. Eventbrite-per-county investigation

- Counties in the authoritative registry: **26** (Northern Ireland explicitly out of scope).
- `get_eventbrite_source()` generates **all 26** — full coverage, no gaps.
- Mechanism: **public discovery-page scraping**
  (`eventbrite.ie/d/ireland--<county>/all-events/`, `__SERVER_DATA__` extraction), **not**
  the v3 Events Search API, which Stage B verified as removed (404).
- **Credentials: none required.** No API token, no organisation ID, no event-collection ID.
  Nothing is missing and no operator-supplied credential is needed.
- Historical exclusions: none. No county was intentionally omitted.

Production confirms this independently — all 26 `eventbrite_<county>` ids appear in live
production event data.

## 5. Classification of every historical source

| Source | Classification | Evidence / action |
|---|---|---|
| 6 legacy defaults | **ACTIVE** | In `get_defaults()`; preserved byte-for-byte; asserted by test |
| 26 x `eventbrite_<county>` | **MISSING_BUT_RECOVERABLE** → restored | `Conexao_County_Registry`; 26/26 in production |
| 26 x `heritage_week_<county>` | **MISSING_BUT_RECOVERABLE** → restored | `Conexao_County_Registry`; 0 importable events in 2026 (edition ended) — by design |
| `laois_council` | **INTENTIONALLY_RETIRED** | Commit `042259d` deleted `class-laois-council-source.php` (184 lines). Not restored. |
| `leo_laois` | **INTENTIONALLY_RETIRED** | Commit `042259d` deleted `class-leo-laois-source.php` (351 lines). Not restored. |
| `local_enterprise_office_laois` | **INTENTIONALLY_RETIRED** | Same commit; alias kept in the strip-list. Not restored. |
| Eventbrite v3 Search API path | **OBSOLETE_REPLACED** | Stage B verified 404; replaced by discovery-page scraping. Not restored. |

**Counts:** current 6 → historical 58 · missing **52** · recoverable **52** · retired **3** · unknown **0**.
## 6. Restoration

One change, inside the existing architecture: `get_all()` now merges missing county sources
on every read, and the empty-option first-run path seeds the full registry.
`Conexao_County_Registry` remains the single source of truth; no parallel configuration
system, no new importer lifecycle, no one-off per-source code.

Safety properties, all asserted in `test-county-registry.php`:
- inserts **only** missing ids → operator edits are never overwritten;
- every county source ships `inactive` → repair cannot trigger an import or write an Event;
- retired ids are still stripped → nothing obsolete is resurrected;
- idempotent → repeated reads converge.

## 7. Verification

| Metric | Before | After |
|---|---|---|
| Sources in registry | 6 | **58** |
| Legacy 6 present | 6 | **6** (unmodified) |
| Active sources | 3 | **3** (unchanged — no import triggered) |
| Duplicate source ids | 0 | **0** |
| Local events | 55 | **55** (unchanged) |
| Duplicate event identities | 29 | **29** (unchanged — pre-existing) |

- `test-county-registry.php`: **482 passed, 0 failed** (baseline 473).
- Adapter routing: **53/53** `eventbrite`/`heritage_week` sources resolve correctly, 0 mismatches.
- Dry-run (`heritage_week_carlow`): `found=0 created=0 updated=0 duplicates=0 errors=0`; events 55 → 55.
- Determinism: two independent runs from an empty option gave identical digests `96d2a0b0…aedcecc`.
- Full suite: see §11.

## 8. Data integrity

The restoration writes **no event data**. Verified by count before/after (55 → 55) and by
re-running the duplicate scan with the change stashed: **29** duplicate identities both with
and without the fix — pre-existing local fixture drift in `laois_tourism`, unrelated to and
untouched by this work. No PT record, Polylang/i18n field, taxonomy, URL or
`_event_source`/`_event_source_id` ownership was modified.

## 9. Production

Production was **read only**, via the public unauthenticated REST endpoint. No wp-admin
write, no WP-CLI, no SSH, no DB access, no filesystem write, no credentials used.

Production holds **1,808** events with **32** distinct `_event_source` values, including
**all 26** `eventbrite_<county>` ids. **Production never lost the county coverage and never
had the problem at all**, because `conexao-event-importer` is `production: false` in
`plugins.json` — production consumes events via the JSON export and holds no importer source
registry. The "6 sources" figure was a local/CI tooling symptom.

**Operator steps required: none.**

## 10. Rollback

```bash
git checkout HEAD~1 -- \
  wp-content/plugins/conexao-event-importer/includes/class-event-sources.php
```

Rollback is safe and complete: the change only ever *inserts* registrations into the
`conexao_event_sources` option. Reverting the code stops the self-heal; to also drop the
already-registered rows, delete the 52 `eventbrite_*` / `heritage_week_*` keys from that
option. **No event data, taxonomy or content is touched, so no event rollback is required.**
Because all restored sources ship `inactive`, rollback cannot orphan or hide any event.

## 11. Full verification results

`./scripts/run-tests.sh` — **15,180 assertions passed, 2 failed**, 80 PHP suites, 18 script-contract suites.

| Suite | Result | Classification |
|---|---|---|
| `plugin/conexao-event-importer/test-county-registry.php` | **482 passed, 0 failed** | **Fixed by this work** (baseline 473 passed; the old test asserted the pre-restoration behaviour) |
| `tests/acceptance/verify-event-occurrence-http.py` | 46 passed, **3 failed** | **Pre-existing.** Local-DB content drift: extra `laois_tourism` fixtures make the archive return 5/6/3 cards instead of 1. Byte-identical with the change stashed. |
| `tests/scripts/verify-release-integrity.py` | **224 passed, 0 failed** | **Passes in isolation.** Its `[FAIL]` line is an aggregate-suite artefact of the acceptance failure above; not caused by this work. |
| All other 78 PHP suites + 17 script suites | all pass | unchanged |

**New failures introduced: 0.** **Environmental/tooling failures: 0.**

Static analysis and gates:
- `phpcs` on both changed files: **25 errors / 31 warnings, identical with and without the change** — zero new violations.
- `php scripts/generate-registry-docs.php --check`: `registry OK: 10 plugins validated, 19 generated regions current (zero writes)`.
- `scripts/verify-permanent-gates.py`: **7/7 gates passed, 84 assertions, 0 violations, AGGREGATE: PASS** — taxonomy policy, translation completeness, cache scoping, redirect precedence, documentation drift, i18n freshness. No gate was weakened.

## 12. Limitations

- The three failures in `tests/acceptance/verify-event-occurrence-http.py` are **pre-existing
  local-database content drift** (extra `laois_tourism` fixtures: "found 5/6/3" instead of 1).
  Reproduced byte-identically with the change stashed — not caused by this work, not fixed by it.
  Fixing them would mean mutating unrelated local fixture data, which is out of scope here.
- Provider reachability was not exercised: the sources are registered `inactive` and dry-run
  was used instead. Activating an import is an operator decision requiring live traffic.
- Historical per-source `events_imported` counts from the 2026 Stage D rollout live in
  `events-expansion-stage-d-rollout-manifest.json`, not in the registry, and were not
  replayed into the restored rows.
- Production source **configuration** (the `conexao_event_sources` option) could not be read
  directly: the importer is not installed in production and the option is not REST-exposed.
  Production was therefore compared through `_event_source` on live event data, which is
  sufficient to prove coverage but not to read per-source active/inactive flags.

## 13. Evidence

| File | Content |
|---|---|
| `docs/evidence/2026-10-02-event-source-restoration/01-restored-source-inventory.json` | Full 58-source inventory after restoration |
| `…/02-determinism.txt` | Two independent runs, identical digests |
| `…/03-production-readonly-source-inventory.json` | Production read-only breakdown (1,807 events, 32 sources) |
| `…/04-full-test-run.log` | Complete post-change `./scripts/run-tests.sh` output |
| `…/05-baseline-test-run.log` | Complete pre-change run, for before/after comparison |
entries from `get_defaults()` (6) but had **no knowledge of** `Conexao_County_Registry`.
The registry existed only as the manual `seed_county_sources()` step, documented as a
`wp eval` one-liner in `docs/plugins/conexao-event-importer.md`.

`grep` confirmed the call appeared in **zero** non-test code paths — not in
`Conexao_Event_Importer::activate()`, not in `scripts/bootstrap-ci-fixtures.php`, not in any
script. Every environment built without that manual step reported 6.

## 14. Follow-up — all sources enabled (operator request)

On request, all **58** sources were switched to `status = active` in the **local** Docker
stack. **No import was run** and nothing was fetched, per the explicit instruction.

| Metric | Value |
|---|---|
| Sources total / active | **58 / 58** (0 inactive) |
| `get_active()` returns | **58** |
| Imports executed | **0** |
| Sources with a `last_import` timestamp | **0** |
| Events in DB | **55** (unchanged) |
| Config drift vs the authoritative registry (url/type/county) | **0** |

Importer regression suites re-run after enabling, all green:
`test-county-registry` 482/0 · `test-eventbrite-importer` 68/0 · `test-past-event-filter` 29/0 ·
`test-source-county-hint` 18/0 · `test-reappearing-event-lifecycle` 5/0.

### ⚠️ Known consequence the operator must decide on

Two source pairs now cover the **same** ground and are **both active**:

| Active source | Overlaps with | Shared scope |
|---|---|---|
| `eventbrite` (legacy) | `eventbrite_laois` | Eventbrite, Laois |
| `heritage_week` (legacy) | `heritage_week_laois` | National Heritage Week, Laois |

Stage C1 §2.3 deliberately deactivated the legacy pair for exactly this reason. Dedup keys on
(`_event_source`, `_event_source_id`) and the Eventbrite `source_id` is the provider event id,
which is **identical** for both — but `_event_source` differs (`eventbrite` vs `eventbrite_laois`).
So the composite key differs and **the same Laois event will be stored twice**, once per source.

Currently harmless: nothing has been imported, and the only local `eventbrite` event (1) predates
this change. The moment an import runs, expect duplicate Laois events.

Suggested resolution when you are ready — either:
- deactivate the two **legacy** ids (`eventbrite`, `heritage_week`), keeping the county pair; or
- deactivate the two **county** ids (`eventbrite_laois`, `heritage_week_laois`), keeping the legacy pair.

Evidence: `docs/evidence/2026-10-02-event-source-restoration/06-all-sources-enabled.json`.
