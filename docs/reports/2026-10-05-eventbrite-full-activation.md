# Plan — G16: enable all supported event-import sources and perform a controlled full import

> This document holds **both** the plan (`docs/templates/plan.md`, required
> before multi-file work by engineering standard §13.2) and the G16 result.
> §1–§23 are the plan; the Activation Matrix and the `G16 — Result` sections
> at the end are what was actually done and measured.

| | |
|---|---|
| **Task title** | G16 — enable all supported event-import sources; controlled full import |
| **Date** | 2026-10-05 |
| **Author / agent** | coding agent |
| **Branch** | `master` |
| **Repository baseline (starting SHA)** | `199ff5b1ad3335cc7b28ea64cc19be71504dbfff` (G14, clean tree) |
| **Applicable skill(s)** | `wp-repository`, `wp-write-in-process-test`, `wp-testing`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

**G16** — enable every event source that is currently **supported, configured,
validated and safe to activate** under the existing importer architecture, and
then perform one controlled full importer run plus one serial resume run,
verifying the whole chain (activation → import → checkpoint → resume →
completeness → reconciliation safety → dedup → truthful history) on live data
in a positively verified non-production environment.

The stage is **activation**, not source discovery. It reuses
`Conexao_Event_Sources::set_status()` and the existing handlers only.

## 2. Explicit non-goals

- **Not** activating `events_today` or `events_tomorrow`. G13 classified
  `events_today` **REJECT** and `events_tomorrow` **DEFER** on measurement.
  G16 §1 forbids reversing an evidence-backed decision without fresh evidence,
  and §7 says so again by name.
- **Not** introducing a new importer architecture, adapter or parser.
- **Not** reintroducing the retired Eventbrite API path (G7): no
  `eventbrite_api_token`, no API-mode flag, no OAuth, no API fallback.
- **Not** adding new URLs, guessed endpoints, undocumented feeds or speculative
  adapters. A source that is not configured is **not invented** (§9).
- **Not** raising the request budget, the time budget, the page cap or removing
  cooldown/overlap/truncation handling to force completion (§22).
- **Not** inventing a cron schedule or any recurring cadence (§11).
- **Not** adding caching (G13 §7, unchanged).
- **Not** fixing the 29 pre-existing failing PHP suites or the pre-existing
  `verify-stage14-secret-scan.py` / `verify-event-occurrence-http.py` failures.
  They are the recorded baseline and are unrelated to ingestion.
- **Not** any production write or deployment.
- **Not** touching the Flutter/mobile repository.

## 3. Relevant authoritative documents

| Fact | Owner |
|---|---|
| Engineering rules | `docs/engineering-standard.md` |
| Source registry shape | `includes/class-county-registry.php` + `includes/class-event-sources.php` |
| Source adapter contract | `Conexao_Source_Base` / `Conexao_Source_Eventbrite::is_fetch_complete()` |
| G13 ACTIVATE/DEFER/REJECT decisions | `docs/reports/2026-10-04-eventbrite-g13-operationalization.md` |
| G14 controlled-import + concurrency incident | `docs/reports/2026-10-04-eventbrite-g14-controlled-import.md` |
| Cooldown / budget / cadence policy | G13 §4–§7 |
| Venue-source activation gates | `docs/importers/ivvcc-importer.md`, `docs/importers/motorsport-ireland.md`, `docs/importers/mondello-park-importer.md` |
| Registry drift gate | `plugins.json` + `scripts/generate-registry-docs.php` |

## 3a. Skill workflow

## 4. Current-state findings (measured, not assumed)

Baseline `./scripts/run-tests.sh` at `199ff5b`, clean tree:

| Layer | Result |
|---|---|
| In-process PHP | 96 suites — **67 passed / 29 failed** |
| Assertions | **4011 passed / 4 failed** |
| Script contract | 19 total — **18 passed / 1 failed** |
| HTTP acceptance | 4 total — **3 passed / 1 failed** |

Live local registry (read from the local DB):

| Measure | Value |
|---|---:|
| Sources in registry | **136** |
| Active | **4** |
| Inactive | **132** |
| Source types | `eventbrite` 105, `heritage_week` 26, `website` 4, `icalendar` 1 |
| Event posts in local DB | **729** |
| Checkpoints present | 20 (all test/probe residue, **no real source**) |
| `eventbrite%` options in DB | **0** — G7 retirement intact |

The four active sources were `laois_tourism`, `heritage_week`, `eventbrite`
(Eventbrite — Laois) and `eventbrite_cork_events_this_week` (the single G13
`ACTIVATE`).

**Pre-existing user changes: none.** `git status --porcelain` was empty at
`199ff5b` before any work began. Every source-state number above was captured
before the first G16 edit.

## 5. Proposed approach

Three source classes exist in the registry, and they are **not** equally
eligible. The approach is therefore a **per-class decision backed by evidence
already in the repository**, then activation of only the eligible classes.

1. **Build the activation matrix first** (§6 template), before enabling anything.
2. **Decide each class against recorded evidence**, not against "it exists in
   the registry". A source being registered is not eligibility.
3. **Activate only the eligible class**, through
   `Conexao_Event_Sources::set_status()` — the exact code path the wp-admin
   **Enable** button uses.
4. **Prove the environment non-production** with the G14 guard before any
   write-capable step, and run the importer **strictly serially**, waiting for
   each process to genuinely exit (§12).
5. **Verify** the full chain on live data, including the ten negative proofs,
   and report honestly.

Options considered and rejected:

- *Enable all 136 registry sources.* Rejected — G13's `REJECT`/`DEFER`
  decisions and the venue sources' documented operator-confirmation gates make
  that a safety regression, and §1/§7/§9 forbid it.
- *Enable only `eventbrite_cork_events_this_week` (i.e. do nothing).* Rejected
  — that is G14's state, and the stage's purpose is to widen the
  evidence-backed set.
- *Add a lock to close the G14 concurrency hazard.* Rejected — §12 asks the
  stage to **determine and document** whether locking exists, not to introduce
  new architecture. Serial execution is used and the limitation is reported.
| | |
|---|---|
| **Skill** | `wp-repository` (plan/build/verify/report loop), `wp-write-in-process-test`, `wp-update-docs` |
| **Its preconditions** | Plan filed before multi-file work; environment proven non-production before any write-capable operation |
| **Its guardrails that bind this task** | No production writes; dry-run first; never a second source of truth; never weaken a safety mechanism to reach a number |
| **Its verification commands** | `./scripts/run-tests.sh`, `./scripts/lint.sh`, `php scripts/generate-registry-docs.php --check` |
| **Its evidence location** | `docs/evidence/2026-10-05-eventbrite-g16/` |
## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `docs/reports/2026-10-05-eventbrite-full-activation.md` | add | the required G16 report (this file) |
| `docs/evidence/2026-10-05-eventbrite-g16/*` | add | machine evidence |
| `tests/experiments/g16-*.php` / `.sh` | add | environment guard, controlled-run harness, negative proofs |

## 7. Files explicitly expected NOT to change

| Path | Why |
|---|---|
| `wp-content/plugins/conexao-event-importer/includes/**` | no importer redesign, no budget/cooldown/overlap change (§2) |
| `includes/class-eventbrite-pacing.php` | `PAGE_DELAY_SECONDS`, `RATE_LIMIT_BASE_SECONDS`, `MAX_COOLDOWN_SECONDS`, `MAX_RATE_LIMIT_RETRIES` must stay |
| `includes/class-request-budget.php` | `DEFAULT_LIMIT = 400` must stay (§22) |
| `includes/sources/class-eventbrite-source.php` | `OVERLAP_PAGES = 1`, `TIME_BUDGET_SECONDS = 45`, `MAX_PAGES = 50` must stay |
| `plugins.json` | no plugin is added, removed or re-classified |
| The Flutter/mobile repository | out of scope, never touched |

## 8. Content / data impact

- Records created/updated/deleted: **local only** — event posts in the local
  Docker database, created/updated by the importer itself.
- Portable identifier strategy: unchanged — native Eventbrite id in
  `_event_source_id`, native source id per handler. No local post IDs used as
  cross-environment identity.
- Does this write content? **Yes, locally.** The six-step content-change
  contract (§5.2) is satisfied by the G14 harness: environment proof first,
  snapshot before, idempotent apply through the supported path, verify with a
  numeric gate.
- PT content changed? **No.** No route, taxonomy or translation change.

## 9. Route / HTTP impact

- Routes added/removed/changed: **none.**
- Redirects: none.
- Sitemap: unchanged.
- HTTP matrix rows: **none added.** The `/eventos/` archive content changes
  (more events), which is a data effect, not a route effect.

## 10. Polylang / English impact

- Translated post types/taxonomies affected: `event` posts only; no taxonomy
  policy change.
- PT immutable: **Yes.** No slug, URL, term or record-identity change. Events
  are imported under their existing source-attribution and geography rules.
- B1/B2: not applicable — no new EN destination.
- Language-scoped cache keys: unchanged.
- Completeness gate: unchanged (`conexao_category` / `conexao_tag` translated;
  `conexao_county` / `conexao_town` shared).

## 11. Security impact
## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, how? | nobody — production is not touched |
| Verification of the production result | n/a; `0 production writes` |

## 14. Test plan

- In-process suite(s) to add: none required for an activation-only stage; the
  existing Eventbrite suites already cover the invariants. **No assertion is
  removed or weakened.**
- Acceptance area / matrix rows: none.
- Script contract impact: none.
- Registry drift check: `php scripts/generate-registry-docs.php --check` — must
  report zero writes.
- Numerically: the **failure set must be identical** to the recorded baseline
  (29 PHP suites, 1 script, 1 HTTP). New failures are regressions.

## 15. Acceptance matrix plan

No HTTP acceptance rows are added. The G16 verification is in-process plus live
importer evidence, captured in `docs/evidence/2026-10-05-eventbrite-g16/`.

## 16. Rollback plan

1. The registry is a single option (`conexao_event_sources`). Capture its
   pre-change value before any activation.
2. Rollback = restore that captured value, or flip the same source IDs back to
   `inactive` through `set_status()`.
3. Events created by the run are **local database rows**; the local database is
   disposable and is not part of any release artifact. Rollback of the
   *configuration* does not delete imported events, and rollback of local event
   rows is out of scope for the repository (there is no production effect).
4. Checkpoints/cooldown are run-scoped or operator-resettable and are not part
   of a release artifact.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/reports/2026-10-05-eventbrite-full-activation.md` | add the G16 report (plan + result) | — |
| `docs/plugins/conexao-event-importer.md` | correct the stale registry-size figure to the real count | — |
| `docs/reports/README.md` | index the new report if that index is hand-maintained | — |

## 18. Release implications

- Component version bumps: **none.** No `wp-content/` source change.
- Artifact allowlist: unchanged (`plugins.json` untouched).
- Release in scope: **no.**

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Activating a source that was rejected/deferred | medium | matrix-first; per-class decision against recorded evidence; G13 carried forward verbatim |
| Import storm against Eventbrite | high if all 26 all-events counties run at once | bounded budgets, 2 s pacing, shared run-level rate limiting, cooldown — all unchanged; incompleteness is the expected outcome |
| Concurrency re-creating the G14 duplicate incident | medium | strict serial execution; no second invocation until the first process has exited; no lock exists — documented as a limitation |
| Test suites mutating operator state | observed | verified option isolation restored `conexao_event_sources`; re-verified registry integrity after the baseline run |
| Doing too much | medium | no importer redesign, no new sources, no cadence, no budget change; activation only |

## 20. Verification gates

1. `php scripts/generate-registry-docs.php --check` → registry OK, zero writes.
2. Environment proof (G14 guard) → non-production confirmed, and the guard is
   shown non-vacuous by a simulated production target.
3. Pre-import safety check (§13 values) → all as expected.
4. Controlled run 1 (serial) → per-source metrics captured.
5. Controlled run 2 (serial) → resume/overlap verified.
6. Cross-source dedup verified live → one WordPress event per native id.
7. Ten negative proofs with mutation → all genuinely non-vacuous.
8. `./scripts/run-tests.sh` → failure set identical to baseline.
9. `./scripts/lint.sh` → no new findings.
10. Local DB `eventbrite%` options → still zero.

## 21. Completion criteria

Per `docs/engineering-standard.md` §14: the plan is filed; the change is
implemented and verified with real numbers; the report states
created/updated/unchanged, failures, rate limits and cooldown; production
writes are 0; deployments are 0; the mobile repository is untouched; and no
safety mechanism was weakened to reach a number.

_Last verified: 2026-10-05 by G16_

- Capabilities checked: `manage_options` (unchanged; the harness acts as the
  operator, in a local environment).
- Nonces: unchanged.
- Sanitisation/escaping: unchanged.
- SQL: unchanged — WordPress APIs only.
- REST surface: none.
- Secrets: **none.** No credentials are read, written or required. The
  environment guard uses only `home_url()`, `wp_get_environment_type()` and
  the repository's own `conexao_script_classify_target()`.

## 12. Performance impact

- Queries: unchanged per request. More event rows exist.
- Caching: none added (§2).
---

# Appendix A — Activation matrix (G16)

Every source in the registry, classified source-by-source. 136 total,
**81 active**, **55 disabled**. Classes are derived from the source ID shape,
so no county can be omitted.

| Source class | Count | Configured | Supported | Prior decision | Evidence | Final action |
|---|---:|---:|---:|---|---|---|
| `eventbrite_<county>` (all-events) | 26 | yes | yes | never classified as a set (G8 measured only Cork) | live probe `http=200`, 20 events page-1, Carlow `page_count=49` vs `object_count=4,740`; truncation guard proven in G8/G9 | **ENABLE** |
| `heritage_week_<county>` | 26 | yes | yes | never classified, never measured | live probe `http=200`, 12 listing cards parsed (Carlow) | **ENABLE** |
| `eventbrite_<county>_events_this_week` | 26 | yes | yes | G13 **ACTIVATE** (Cork: 97 incremental, 9/9 complete, 2.07 ev/req) | G13 §1 + live probe `http=200`, 299 objects / 15 declared pages | **ENABLE** |
| `eventbrite_<county>_events_today` | 26 | yes | yes | G13 **REJECT** | 0.20 events/request, drained to 0, calendar-driven volatility | **KEEP DISABLED** |
| `eventbrite_<county>_events_tomorrow` | 26 | yes | yes | G13 **DEFER** | 0 changes across 9 comparisons; value subsumed by `this-week` | **KEEP DISABLED** |
| `ivvcc` | 1 | yes | yes | ships `inactive` by documented gate | `http=200`; *"activate … only after operator confirmation as an internal deployment control"* | **KEEP DISABLED** |
| `motorsport_ireland` | 1 | yes | yes | ships `inactive` by documented gate | `http=200`; *"Stage C production import requires explicit operator confirmation"* | **KEEP DISABLED** |
| `mondello_park` | 1 | yes | yes | ships `inactive` by documented gate | `http=200`; *"READ-ONLY — no production writes, no production activation"* | **KEEP DISABLED** |
| `laois_tourism` (ICS) | 1 | yes | yes | active pre-G16 | handler fetched 27,671 bytes of real VCALENDAR | **UNCHANGED (active)** |
| `heritage_week` (national) | 1 | yes | yes | active pre-G16 | probe `http=200` | **UNCHANGED (active)** |
| `eventbrite` (Eventbrite — Laois) | 1 | yes | yes | active pre-G16 | probe `http=200` | **UNCHANGED (active)** |

**Reconciliation:** 26 + 26 + 26 + 3 active legacy = **81 active**;
26 + 26 + 3 gated = **55 disabled**. 81 + 55 = 136. ✅

**No source was invented.** The probe measured `sources_with_url = 136`,
`unconfigured = 0` — every registered source already had configuration, so
§9 was never engaged.

**A probe bug was found and it mattered.** The first probe reported
---

# G16 — Result

## Executive result

# `PARTIAL — activation complete, one blocking defect found`

**What changed:** the evidence-backed event source set went from **4 active → 81
active** out of 136. The 77 newly activated sources are exactly the three
eligible classes (26 Eventbrite county all-events, 26 Heritage Week county, 26
`this-week` windows). All G13 `REJECT`/`DEFER` decisions were carried forward
verbatim, all three gated venue sources stayed inactive, and the registry
remained consistent (136 ids, no duplicates, no key mismatches, no missing URLs).

**What the controlled run found — the reason this is not a clean pass:**

The full import **surfaced a real, pre-existing correctness defect that is
invisible on the WP-CLI path.** The local database went from **47 → 197
duplicated native event IDs during run 1**, and to **239** during run 2 — while
both runs reported `duplicates = 0`.

The event runtime hides `_event_status = source_not_found` rows from *every*
event query unless `WP_CLI` is defined. The deduplicator resolves identity
through `WP_Query`, so on any **non-WP-CLI** import entry point it cannot see an
event it previously marked missing. When that event reappears upstream, the
importer **creates a second WordPress post instead of restoring the first**. The
correct restore logic already exists at `class-event-importer.php:1488` — it is
simply never reached.

190 of the 197 duplicated IDs have a `source_not_found` sibling. The defect is
**pre-existing**, is **not** a concurrency artefact (run 2 was serial and still
added 42 duplicates), and G14 could not have seen it for the same reason.

**Two further findings**, both consequences of the wider coverage rather than
code changes: three contaminated `conexao_town` terms were created
(`Co galway`, `Co. Wicklow`, `Galway Ireland`), and the JSON exporter exhausted
its 512 MB ceiling at 2,705 events.

## Activation matrix

See [Appendix A](#appendix-a--activation-matrix-g16). Final state:

| Measure | Value |
|---|---:|
| Registry size | 136 |
| **Active** | **81** |
| Inactive — G13 REJECT (`events_today`) | 26 |
| Inactive — G13 DEFER (`events_tomorrow`) | 26 |
| Inactive — documented operator gate | 3 |
| Sources invented / duplicated | **0** |

## Sources enabled
## Eventbrite

- **All-events sources (26):** every one reported `complete = false`, as
  designed. Carlow alone declares `object_count = 4,740` behind
  `page_count = 49` (980 reachable). The truncation guard held throughout.
- **Window sources (26 `this-week`):** mostly stopped on `time_budget` or
  `rate_limited`.
- **Checkpointing:** persisted, source-scoped and truthful — Cork ended run 1 at
  `page 9/49, last_failure = rate_limited`; Dublin at `page 14/49,
  last_failure = time_budget`.
- **Overlap:** `OVERLAP_PAGES = 1`, unchanged and verified live in run 2.
- **Completeness:** `complete = false` on 54 of 81 sources. **Checkpoint
  completion never overrode it** — a resumed source reaching its declared last
  page still reported `complete = false` where truncation applied.
- **Deduplication:** cross-source dedup **passed** (0 new); same-source
  reappearance dedup **failed** (see the defect below).

## Import run

### Environment (verified before any write)

| Signal | Value |
|---|---|
| `home_url()` | `http://localhost:8080` |
| `wp_get_environment_type()` | `local` |
| `conexao_script_classify_target()` | `local` |
| Production host reached | **never** |

The guard was proven **non-vacuous**, not merely present:

| Simulated target | Result |
|---|---|
| `CONEXAO_SITE_URL=https://conexaobr.ie` | **REFUSING TO IMPORT** — "is the PRODUCTION host" |
| `CONEXAO_SITE_URL=https://random-host.example` | **REFUSING TO IMPORT** — "classifies as `unknown`" |

### Pre-import safety check (§13) — 15 checks, all OK

`plugin_active`, `registry_valid`, `overlap_pages_is_1`, `request_budget_400`,
`time_budget_45`, `max_pages_50`, `cooldown_present`, `cooldown_base_20`,
`cooldown_max_60`, `checkpoint_present`, `checkpoint_enabled`, `api_token_absent`,
`active_sources_present`, `no_lock_implementation`, `no_concurrent_run`.

### Run 1 — full import, 81 sources, serial, 5,555.4 s

| Measure | Value |
|---|---:|
| Sources in registry | 136 |
| Sources attempted (active) | **81** |
| Sources skipped (inactive) | 55 |
| **Events found** | **3,849** |
| **Created** | **1,837** |
| **Updated** | **9** |
| **Unchanged** | **626** |
| **Duplicates (as reported)** | **0** |
| **Failures / errors** | **0** |
| Skipped (past) | 1,377 |
| Status | `failed` (sources rate-limited) |
| Sources `success` / `warning` / `failed` | 16 / 45 / 20 |
| Sources `complete = yes` / `no` | 27 / 54 |
| Events in DB | 729 → **2,566** |
| Duplicated native IDs | 47 → **197** ⚠️ |

### Run 2 — serial resume on `eventbrite_cork`, 178.5 s

| Measure | Run 1 | Run 2 (resume) |
|---|---:|---:|
| Starting checkpoint | none | **page 9 of 49** |
| Ending checkpoint | 9 of 49 | **17 of 49** |
| Found | 180 | **220** |
| Created | 154 | **138** |
| Unchanged | 26 | **82** |
| Duplicates (reported) | 0 | **0** |
## The defect (G16's principal finding)

**Symptom.** 47 → 197 → 239 duplicated native event IDs across two runs, while
every run reported `duplicates = 0`.

**Isolation.**

| Measure | Value |
|---|---:|
| Duplicated native IDs after run 2 | 239 |
| Duplicated IDs with a `source_not_found` sibling (after run 1) | **190 of 197** |
| **New cross-source** duplicates created by run 1 | **0** |

**Root cause, proved.** `Conexao_Event_Runtime::filter_public_event_queries()`
(`conexao-event-runtime.php:180`) injects a `_event_status = published OR absent`
clause into *every* event query, and skips it **only** when `WP_CLI` is defined:

```php
// Internal importer/tooling (WP-CLI) must query hidden statuses
// (e.g. source_not_found) for deduplication and reappearing-event
// restoration. This does NOT affect public frontend requests.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    return;
}
```

`Conexao_Event_Deduplicator::find_by_source()` resolves identity through
`WP_Query`, so on a non-WP-CLI entry point a previously-missing event is
invisible, `$post_id` is 0, and `upsert_event()` takes the **`wp_insert_post()`
branch** instead of the restore branch at `class-event-importer.php:1488`.

**Decisive experiment** — same query, same arguments, only `WP_CLI` differing:

| Context | SQL contains `_event_status` gate | Rows returned |
|---|---|---|
| Normal (filter entry point) | **yes** | `10236` only |
| `WP_CLI` defined | **no** | `10236, 3367` |

`3367` is the original `_event_status = source_not_found` post; `10236` is the
duplicate the importer created for the same `(source, source_id)` pair.

**Why it survived the test suite.** `test-reappearing-event-lifecycle.php:156`
*asserts as correct* that "public deduplicator does not match source_not_found
event" — correct for the frontend, and inherited unchanged by the importer's
non-WP-CLI paths. The existing suites encode the frontend rule and never
exercise the admin/filter import path.

**Not fixed in G16, deliberately.** The fix touches the **event-runtime
plugin** — production code that this stage's plan named as a file that must not
change. G16 is an activation stage; silently changing a production query gate
inside it would be exactly the scope creep the brief forbids. The defect is
reported with a reproduction instead. The recommended fix is to exempt
*importer-internal* queries specifically (rather than widening the `WP_CLI`
escape), so the frontend gate stays intact.

## Concurrency

**There is no lock.** A recursive scan of every PHP file in the plugin for
`flock(` / `LOCK_EX` / `import_lock` / `acquire_lock` returns **zero hits**, and
this is asserted as `no_lock_implementation` by the pre-import safety check.
G14's §9 hazard therefore still stands.

**Operator restriction, honoured throughout:** one run per environment at a
time. Run 1 (5,555 s) and run 2 were executed strictly serially, each launched
only after the previous process had genuinely exited, and both were driven from
the background with polling rather than a client-side timeout — precisely to
avoid re-creating the G14 incident where a timed-out command left an orphaned
process running beside a second one. The harness additionally writes a run
marker (`conexao_g16_import_running`) and refuses to start while one is live.
## Tests

Compared against the baseline recorded at `199ff5b` before any G16 change.

| Layer | Baseline | After G16 | Delta |
|---|---|---|---|
| In-process PHP suites | 96 total, **67 passed / 29 failed** | 96 total, **64 passed / 32 failed** | **+3 failed** |
| Assertions | **4,011 passed / 4 failed** | **3,935 passed / 8 failed** | −76 passed, **+4 failed** |
| Script-contract suites | 19 total, **18 / 1** | 19 total, **17 / 2** | **+1 failed** |
| HTTP acceptance suites | 4 total, **3 / 1** | 4 total, **3 / 1** | unchanged |

**Failure-set diff — 3 new failures, 0 fixed.** Every new failure was
investigated, and **none is a code regression** (`git status wp-content/` = **0
changed files**):

| New failure | Cause |
|---|---|
| `test-export-language.php` | **Memory exhausted** (512 MB) in `class-event-export.php:937`. The exporter builds the whole export in memory; at 2,705 events (was 729) it no longer fits. A real scalability limit, exposed by volume. |
| `test-town-sanitization.php` | **3 new contaminated `conexao_town` terms** — `Co galway`, `Co. Wicklow`, `Galway Ireland` — created by the newly activated county sources from Eventbrite venue strings ("Co. Galway", "Galway, Ireland"). Affects **7 events**. |
| `test-event-location-filters.php` | Asserts the unfiltered archive returns "all four test events"; the archive now legitimately returns far more. A test assumption about data volume. |

`verify-event-occurrence-http.py` was already failing at baseline; it now fails
one assertion more (an all-day Laois card count of 3 instead of 2) for the same
data-volume reason.

**Negative proofs — 10 / 10 PROVEN, 0 vacuous**
([`06-negative-proofs.txt`](evidence/2026-10-05-eventbrite-g16/06-negative-proofs.txt)):

| # | Mutation | Result |
|---|---|---|
| N1 | failing source becomes a successful empty | **PROVEN** (16 passed, 122 failed) |
| N2 | incomplete enumeration called complete | **PROVEN** (75 / 2) |
| N3 | checkpoint ignored (resume restarts) | **PROVEN** (72 / 5) |
| N4 | rate-limited walk reported complete | **PROVEN** (75 / 2) |
| N5 | duplicate overlapping event not deduplicated | **PROVEN** (76 / 1) |
| N6 | truncated listing treated as complete | **PROVEN** (76 / 1) |
| N7 | checkpoint usability guard removed | **PROVEN** (71 / 6) |
| N8 | reconciliation-safety gate removed | **PROVEN** (74 / 3) |
| N9 | source isolation removed | **PROVEN** (65 / 12) |
| N10 | duplicate identity protection removed | **PROVEN** (76 / 1) |

The baseline was **not** changed to make anything green. Working tree restored
and verified clean after mutation.

**Other gates.**

## Remaining limitations

1. **Blocking defect — duplicate creation on non-WP-CLI imports.** 239
   duplicated native IDs (from 47 at baseline). Root-caused and reproduced;
   **not fixed**, deliberately, because the fix is in the production
   event-runtime plugin. Until it is fixed, **the importer must be run through
   WP-CLI**, or the wp-admin "Run all" button will keep duplicating any event it
   previously marked `source_not_found`.
2. **Cadence remains UNRESOLVED** (inherited from G13 §4). No cron was added and
   no interval was invented. All 81 sources were run **on demand**, which is the
   documented operating model. Every source therefore has an unresolved cadence.
3. **Eventbrite enumeration is incomplete by design.** 54 of 81 sources reported
   `complete = false`; the all-events class is structurally truncated upstream
   (49 pages vs 1,547–5,722 objects). This is the expected outcome (§15/§22) and
   budgets were **not** raised to change it.
4. **No live 429 was induced deliberately**, but rate limiting was hit
   organically — `eventbrite_cork` ended run 1 at `page 9/49` with
   `last_failure = rate_limited`, and 20 sources finished `failed` for
   rate-limit/time-budget reasons. Cooldown and checkpoint preservation held.
5. **Concurrency remains unresolved.** No lock exists; the serial-execution
   operator restriction is documented above and was honoured, but nothing in the
   code prevents a second concurrent run.
6. **52 rejected/deferred and 3 gated sources remain disabled** — listed above
   with reasons. The three venue sources are blocked by an operator policy gate,
   not by capability.
7. **Three contaminated `conexao_town` terms** now exist, affecting 7 events.
   The town normaliser does not strip `Co. <town>` or trailing `, Ireland` from
   Eventbrite venue strings.
8. **The exporter no longer fits in memory** at this event volume (512 MB
   exhausted at 2,705 events).
9. **WP-CLI is not installed in the local container**, so the documented
   `wp conexao-events import` command could not be executed literally. As in
   G14, the same underlying filter (`conexao_event_importer_run_source` /
   `_run_all`) was invoked from PHP — the same code path, not a
   reimplementation. **This substitution is precisely what exposed the defect
   above**, so the WP-CLI path itself is *not* verified here.
10. **Test baseline is now 3 PHP suites + 1 script contract worse** than the
    recorded baseline, for the data-volume reasons itemised above. Reported,
    not suppressed.

## Recommendation

**All currently eligible event sources are now enabled and importing, but the
result is not a clean pass and should not be treated as one.**

- ✅ Every currently supported, configured, validated and evidence-backed event
  source is enabled: **81 active**, up from 4.
- ✅ Rejected sources remain rejected (26 `events_today` = 0 active).
- ✅ Deferred sources remain deferred (26 `events_tomorrow` = 0 active).
- ✅ No speculative configuration introduced (0 invented, 0 unconfigured).
- ✅ Registry consistent; plugin active in a verified `local` environment.
- ✅ Eventbrite API-token plumbing still retired; website importer still the
  supported Eventbrite path.
- ✅ Checkpoint/resume enabled and verified live; `OVERLAP_PAGES = 1` unchanged;
  completeness still independent of checkpoint; truncation guard still active;
  `mark_missing_events()` still gated; cooldown and budgets unchanged.
- ✅ Cross-source deduplication verified live — **0 new cross-source
  duplicates**.
- ✅ Import History truthful, including an audited `found = 0` row.
- ✅ All 10 negative proofs non-vacuous; no budget/cooldown/cadence/caching
  change.
- ❌ **Same-source reappearance deduplication is broken on non-WP-CLI entry
  points**, and the defect is in production runtime code that G16 deliberately
  did not modify.

**Next step (G17, recommended):** fix the deduplicator's blindness to
`source_not_found` by exempting *importer-internal* queries from
`filter_public_event_queries()` — not by widening the `WP_CLI` escape, which
would leak the frontend gate. Then add a regression suite that drives the
importer through a **non-WP-CLI** entry point, which is the gap that let this
through. Until then, run the importer via WP-CLI only.

---

## Evidence index

| File | Contents |
|---|---|
| `evidence/2026-10-05-eventbrite-g16/01-source-eligibility.{txt,json}` | read-only live probes per source class; registry census; G7 re-check |
| `…/02-registry-before-activation.json` | **rollback snapshot** (4 originally-active sources) |
| `…/03-activation.json` | activation rule, per-class enable/keep reasons, leak check |
| `…/04-run1-console.txt` | full run 1 console: all 81 sources, safety check, checkpoints |
| `…/run1-import.json` | run 1 machine record (per-source metrics, global counters) |
| `…/05-run2-console.txt` | run 2 console: resume chain |
| `…/run2-import.json` | run 2 machine record |
| `…/06-negative-proofs.txt` | N1–N10 mutation results |

## Files added / modified

| Path | Change |
|---|---|
| `docs/reports/2026-10-05-eventbrite-full-activation.md` | added (this file) |
| `docs/evidence/2026-10-05-eventbrite-g16/` | added (9 evidence artefacts) |
| `tests/experiments/g16-source-eligibility.php` | added |
| `tests/experiments/g16-activate-sources.php` | added |
| `tests/experiments/g16-controlled-import.php` | added |

**`wp-content/` changed: 0 files.** No importer source, no plugin registry, no
theme. The activation was a **data** change (the `conexao_event_sources` option
in the local database), exactly as `class-event-sources.php` documents:
"registering a source never starts an import", and enabling is an operator
decision.

_Last verified: 2026-10-05 by G16_
| Gate | Result |
|---|---|
| `./scripts/lint.sh` | **OK** — syntax clean, no new PHPCS violations, PHPStan clean (exit 0) |
| `php scripts/generate-registry-docs.php --check` | **registry OK: 10 plugins validated, 19 generated regions current (zero writes)** |
| G7 API-token retirement | intact — **0** `eventbrite%` options in the DB; the only `eventbrite_api_token` occurrences are **3 comments** documenting the retirement, no executable reference |
| Registry consistency | 136 sources, 136 distinct IDs, **0** key/id mismatches, **0** missing URLs |
| `events_today` / `events_tomorrow` active | **0 / 0** |
| Venue sources active | **0** |

## Production

**0 production writes**

## Deployment

**0 deployments**

## Mobile

**Flutter/mobile repository untouched** — never accessed, inspected, built,
tested or referenced. All work was confined to this repository.

## Import History

**Truthful.** 89 records after run 1.

- The combined row is `status = failed` because individual sources were
  rate-limited — accurate, not a masked success.
- `complete = false` on every incomplete row, matching the walk.
- Checkpoints and their `last_failure` values are recorded and match the live
  state (`eventbrite_cork` → `9/49, rate_limited`).
- **One `found = 0, status = success` row** — `eventbrite_offaly_events_this_week`
  — was audited rather than assumed. It is **truthful, not a false success**:
  the log shows `page_count=0, object_count=0` (Eventbrite declares the listing
  empty), 1 outbound request, 0 retries, `consecutive_failures = 0`. A genuinely
  empty, completely-enumerated window is correctly reported as
  complete-with-no-events — exactly the distinction G1 established.
- Rate-limit and cooldown state are preserved; `fatal_errors` is empty and
  `errors = 0` throughout — nothing hidden.

## Post-import verification

| Check | Result |
|---|---|
| Events exist | **2,705** (from 729) |
| Expected sources represented | **45** distinct `_event_source` values |
| Publication state | **2,705 / 2,705** `publish` |
| `_event_status` distribution | published 2,112 · source_not_found 566 · expired 22 · rejected 1 |
| Geographic filtering | works — Cork 647, Dublin 397, Galway 199, Meath 127; `/eventos/?county=laois` renders **188** cards |
| County taxonomy | 29 terms; **2,644** events carry a county |
| Previously imported events stable | yes — 626 `unchanged`, 9 `updated`, 0 errors |
| New events carry expected metadata | yes — native `_event_source_id` + county attribution on all |
| Duplicate native IDs | ⚠️ **239** — see the defect section |
| New cross-source duplicates | **0** ✅ |
| Errors | 0 | **0** |
| Run `complete` | false | **false** |
| Duplicated native IDs | 197 | **239** ⚠️ |

The resume chain works as designed: it resumed at page 9, re-walked the
one-page overlap, advanced to 17, and reported 82 unchanged with 0 in-run
duplicates. It remains incomplete, and the checkpoint stays valid.

### Rate limits and cooldown

Both Eventbrite and Heritage Week sources recorded live `rate_limited` and
`time_budget` stops. **No budget was raised and no cooldown was bypassed to
finish a listing** (§22). Incomplete + checkpoint-preserved is the recorded
outcome.

**77 sources activated** via `Conexao_Event_Sources::set_status()` — the same
method the wp-admin **Enable** button calls. Canonical IDs, geography, parser
routing, budgets, completeness rules and cooldown were untouched; no registry
entry was added, removed or re-pointed.

| Source class | Why eligible |
|---|---|
| `eventbrite_<county>` ×26 | Primary county coverage. Supported by the existing `Conexao_Source_Eventbrite`, reachable (`http=200`), and **safe** precisely because G8/G9's truncation guard forces `complete=false` — so it can never trigger destructive reconciliation. |
| `heritage_week_<county>` ×26 | Same handler as the already-active national `heritage_week`; reachable and parsing (12 cards on page 1). Never before activated or measured; G16 measured them. |
| `eventbrite_<county>_events_this_week` ×26 | G13's `ACTIVATE` class. Measured on Cork (97 incremental events, 9/9 complete, 2.07 events/request); the same handler and the same complete-enumerable window semantics apply per county. |

Activation is **idempotent**: a second invocation reported `changed=0,
already_active=78` and re-asserted `rejected_or_deferred_now_active=0`.

## Sources not enabled

**55 sources remain inactive**, each with a recorded reason:

| Source class | Count | Why it stays disabled |
|---|---:|---|
| `eventbrite_<county>_events_today` | 26 | G13 **REJECT**: 8 unique events for 40 requests = **0.20 events/request**, drained to 0; the volatility is the Irish calendar ending, not the listing. Reversing this would override measured evidence. |
| `eventbrite_<county>_events_tomorrow` | 26 | G13 **DEFER**: **0 changes across 9 complete comparisons**, and ≥2 of its 23 events already appear inside `this-week`. §1 forbids activating a `DEFER` source unless this stage first establishes the missing evidence — it did not. |
| `ivvcc` | 1 | Documented operator-confirmation gate. Reachable and parseable, but activation requires explicit operator confirmation as an internal deployment control. G16 has no such authorisation. |
| `motorsport_ireland` | 1 | Same gate. |
| `mondello_park` | 1 | Same gate. |

The three venue sources are reachable and parse; the blocker is **policy, not
capability**, and it is the operator's to lift — not G16's.
`laois_tourism http=403` — a false negative. It had hard-coded a Chrome/120
User-Agent; the handler sends Chrome/126, and laoistourism.ie answers **403 to
120, 200 to 126**. The probe was corrected to call
`Conexao_Source_Base::get_http_user_agent()`, after which it measured
`http=200, 27,671 bytes, is_ical=yes`. Trusting the first result would have
wrongly declared a healthy active source broken.
- Expected measurable improvement: more events reachable in the archive; not a
  performance optimisation and not claimed as one.