# Report — Stage 23: event freshness on `/eventos/` (stale schedule meta + weekly window boundary)

| | |
|---|---|
| **Stage / task name** | Stage 23 — why `/eventos/` can keep listing events that should no longer be classified as happening today; fix + deterministic regression coverage |
| **Date** | 2026-10-12 |
| **Author / agent** | Cline |
| **Branch** | `cline/rphybc0f` |
| **Start SHA** | `6ec382bba663e21c5dc90b90fac01f61afe82d36` (byte-identical to upstream `master`) |
| **Final SHA** | see the branch head; the last commit of this stage |
| **Working tree at finish** | clean |
| **Skills followed** | `wp-repository`, `wp-content-change`, `wp-write-in-process-test`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/plugins/conexao-event-runtime.md`, `docs/plugins/conexao-event-importer.md`, `docs/content-model.md`, `docs/testing.md`, `docs/reports/2026-10-02-stage-20-laois-ics-occurrence-dates.md`, `docs/reports/2026-10-02-stage-21-ci-ics-fixture-and-hermetic-gates.md`, `plugins.json` |
| **Production writes** | **0** — no production endpoint, credential, database or filesystem was contacted |

Plan: [`2026-10-12-stage-23-event-freshness-plan.md`](2026-10-12-stage-23-event-freshness-plan.md).
Evidence: [`../evidence/2026-10-12-stage-23/`](../evidence/2026-10-12-stage-23/).

## 1. Problem and reproduction

**Reported symptom.** `/eventos/` occasionally keeps showing events that a visitor
saw as "happening today" the previous day, after they should have dropped out of
today's results. Intermittent, cause unknown.

**What `/eventos/` actually reads** (traced end to end, not assumed):

```
archive.php → conexao_content_archive_query()            (theme, inc/archive-query.php)
  → conexao_event_upcoming_ids()                          (theme, inc/events.php)
    → Conexao_Event_Query::upcoming_events()              (plugin, class-event-query.php)
       ├─ get_transient( 'conexao_event_upcoming_<Ymd>_<lang>' )   ← cache, day + language keyed
       ├─ candidate_event_ids()   ← one SQL widening query (status gate + date bounds)
       └─ Conexao_Event_Recurrence::next_occurrence()  ← exact PHP decision, site timezone
  → $query->set( 'post__in', $ids ); orderby => post__in  (pagination/filters keep working)
  → Conexao_Event_Runtime::filter_public_event_queries()  ← _event_status gate
```

The **source of truth** for an event's schedule is the imported `_event_*` meta:
`_event_date` (start), `_event_end_date` (end; for a weekly series, the end of the
last occurrence), `_event_recurrence*` (weekly rule) and `_event_status` (a
manually/import-maintained visibility state). `/eventos/` never reads a stored
"is today" flag: it evaluates dates live. There is **no cron, no scheduler and no
production-side refresh** — imports are manual and local-only, and WordPress.com
offers no SSH/WP-CLI/filesystem/database.

**Reproduction (deterministic, no real-world dates).** Two independent
instruments, both committed as evidence:

1. `docs/evidence/2026-10-12-stage-23/event-date-harness.php` — runs the **real**
   `Conexao_Event_Query` / `Conexao_Event_Recurrence` classes against a **real SQL
   engine** (SQLite, so the plugin's own SQL text runs unmodified) with a
   controlled clock, scanning a 17-shape fixture matrix day by day
   (`event-date-harness-prefix.txt`, `…-postfix.txt`).
2. Three in-process suites (§5) against a real WordPress.

Conditions that reproduce each confirmed cause are in §2.

## 2. Root cause

Two **confirmed** defects in the date layer were found and fixed. Both are
reproducible with the instruments above; neither is a cache defect.

### 2.1 Confirmed cause A (matches the reported symptom) — stale `_event_end_date`

`Conexao_Event_Importer_Engine::upsert_event()` decides "unchanged" by comparing
the title, `_event_date`, `_event_start_time`, URL, venue, organizer, banner,
address and the recurrence rule — but **not** `_event_end_date` /
`_event_end_time`, and the `"unchanged"` fast path returns **before**
`save_event_meta()`. So when a source corrects or shortens an event's end date and
nothing else changes, the importer classifies the record `unchanged` and the
**old** end date stays on it.

Why that shows up exactly as reported: `_event_end_date` is what keeps a multi-day
event on `/eventos/` for every day it runs (the candidate query admits
`_event_end_date >= today`, and `occurs_on_date()` is true for every day in
`[start, end]`), and it is the same field
`Conexao_Event_Status::get_event_end_timestamp()` uses to decide that an event has
finished. A stale end date therefore keeps an **already-ended** event in today's
results, with the "Hoje" chip, until the stale date itself passes — intermittently
(only for events whose end date moved) and for "some of the same events" (only
those records), which is the shape of the report.

Evidence: `vacuity-fix1-importer.txt` — with only the two end-field comparisons
removed, the record keeps `_event_end_date = today+3` after the source shortened it
to `today+1`, and the runtime still classifies it as an upcoming event on both
`today+2` and `today+3`. The defect is **real upstream code**: the same function
already reconciles taxonomies and the recurrence rule on that fast path, each with
a comment explaining that the fast path would otherwise leave stale metadata — the
event's own end date was simply missed.

### 2.2 Confirmed cause B — the weekly candidate window was bound to the wrong date

`Conexao_Event_Query::candidate_event_ids()` required
`_event_recurrence_end >= today + 7`, while its own docblock and
`docs/plugins/conexao-event-runtime.md` state the series must only be "not ended
before today". Consequence: **every active weekly series vanished from `/eventos/`
seven days before its last occurrence** — including on the day it occurs (proved:
`event-date-harness-prefix.txt` shows fixtures 107/108 absent on every simulated
day; the post-fix scan shows them present on their occurrence days and gone
afterwards).

Direction: this cause produces *missing* events, not lingering ones, so it is not
the reported symptom — it is a **corroborating date-boundary defect** in the same
function that computes date-based results, fixed because leaving it would keep the
query contradicting its own documented contract.

### 2.3 Layers investigated and ruled out (with evidence)

| Layer | Verdict | Evidence |
|---|---|---|
| Event query date filter (expired events) | **sound** — a 17-shape fixture matrix scanned over 36 simulated days shows every ended event excluded and every ongoing multi-day event retained; the evaluator can only return a date `>= today` and `<= end` | `event-date-harness-*.txt` §1–§3 |
| Transient cache | **sound** — key is `conexao_event_upcoming_<Ymd>_<lang>`, TTL ends at the next local midnight, a previous day's entry is never served to a later day, `flush_cache()` clears ±1 day × languages, the explicit-clock path writes nothing | harness §5; suite `test-event-date-transitions.php` T5/T6 |
| Timezone / UTC mixing | **sound** — `today()` is site-local midnight, date-only values are read as local dates (a UTC instant on the previous UTC date is correctly the event's day), and the local day survives the DST change | harness §4; suite T7 |
| `_event_status` staleness | **not the mechanism** — `/eventos/` is date-filtered live; an event that ended yesterday is excluded regardless of a stale `published` status | harness §2; suite T2 |
| Scheduled jobs / imports / continuation tasks | **none exist** — no cron or scheduler in the event lifecycle; imports are manual and local-only | no `wp_schedule_event`/`wp_next_scheduled` anywhere in the event plugins |
| Browser-side caching | **none** — the archive's "load more" re-fetches server-rendered pages; no event data is stored client-side | `assets/js/main.js` `initLoadMore()` |
| Production edge cache | **unverifiable from here; cannot explain a day-scale symptom** — the repository's own production evidence records `Cache-Control: max-age=300, must-revalidate` with `X-Ac: STALE/HIT` on HTML | `docs/reports/english-stage43-production-deployment.md` §S11 |

### 2.4 What could not be established

The **exact production cause** of the reported incident cannot be asserted: no
production access exists (by design) and no production event record was inspected.
What is established is (i) the code-level mechanism that produces the symptom
(§2.1), (ii) that the cache and query layers cannot produce it (§2.3), and (iii)
the operator evidence that would confirm the production case (§11).



## 3. Implementation

### 3.1 Files changed (code)

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-importer.php` | `upsert_event()` now reads `_event_end_date` / `_event_end_time` and compares them with the incoming `end_date` / `end_time` (plus a comment explaining why) | a source end-date-only change must reach the existing record, or the event keeps being listed after it ended |
| `wp-content/plugins/conexao-event-runtime/includes/class-event-query.php` | one `$wpdb->prepare()` argument: the weekly end bound is bound to the current local date instead of the window end (plus a docblock paragraph) | an active weekly series must stay listed until it actually ends |
| `conexao-event-importer.php` / `conexao-event-runtime.php` headers | version `1.7.1 → 1.7.2` and `1.2.2 → 1.2.3` (header + constant) | the repository's component-version contract |
| `languages/conexao-event-importer.pot` | regenerated with `./scripts/i18n-make-pot.sh` | the freshness gate compares commit times; no strings changed (header date + two shifted source references) |

**Smallest reliable fix, and why not the alternatives.** For cause A the
alternatives were: reconcile the schedule meta on the fast path (a second write
path for fields the update path already owns) or rewrite the deduplicator /
`save_event_meta()` (broad, unnecessary). Adding the two comparisons makes change
detection complete for the fields that decide visibility, keeps the existing update
path, identity matching, dedup and idempotence untouched, and mirrors the pattern
the file already uses for the recurrence rule. For cause B the alternatives were:
widening the evaluator's documented 7-day scan (a semantics change) or touching the
archive query (no defect there). One binding is corrected and the SQL remains a
**strict superset** of the evaluator, which still decides exactly.

### 3.2 Cache behaviour before / after

**Unchanged, deliberately.** No transient key, TTL, invalidation hook or
object-cache entry was added, removed or modified. The cache was proven sound
(§2.3), so the fixes make the *inputs* to the query correct (the stored end date;
the candidate window) instead of masking a symptom with cache changes.

### 3.3 Production-specific limitation

Nothing in this change can be *operated* from production: it is source code. The
importer change only takes effect when an operator runs a local import and
transfers the result; the runtime change takes effect on the next deploy. Neither

## 4. Regression coverage (deterministic, no real-world dates)

All three suites are discovered by convention (`<component>/tests/test-*.php`), use
the shared bootstrap and assertion API, create and delete their own fixtures, and
take a **controlled clock** through `upcoming_events( $from )` /
`upcoming_event_ids( $from )` — nothing sleeps until midnight and nothing depends
on a production record.

| Suite | Status | Coverage |
|---|---|---|
| `wp-content/plugins/conexao-event-runtime/tests/test-event-date-transitions.php` | **new** — 54 assertions | T1 today → yesterday without touching the record; T2 an event that ended yesterday excluded even with a pre-populated cache entry (including a stale previous-day entry that must never be served); T3 tomorrow becomes today (classification, not just membership); T4 ongoing multi-day event visible on every day it runs and gone the day after its end; T5 date-keyed cache, lifetime ends at local midnight, save/delete refresh the list; T6 cached vs cache-bypassed equality; T7 site timezone vs UTC (a UTC instant whose local date differs) and the DST change; T8 upcoming events, ordering, uniqueness, taxonomy filter, pagination and weekly series all intact |
| `wp-content/plugins/conexao-event-runtime/tests/test-event-query.php` | **extended** (new section `R`) — 48 assertions | a weekly series whose last occurrence is inside the 7-day window (and one ending today) is still listed; a series that ended yesterday stays excluded; a series whose next occurrence falls after its end date stays excluded — i.e. the widened SQL is still decided exactly by the evaluator |
| `wp-content/plugins/conexao-event-importer/tests/test-event-schedule-refresh.php` | **new** — 21 assertions | an end-date-only source change is applied to the **existing** record (`updated`, same post ID, new `_event_end_date`); the runtime consequence follows (no longer an upcoming event after the refreshed end date); identical data re-imports as `unchanged` (idempotence); the source identity still resolves to exactly one record (no duplicate); a start-date change still behaves as before |

Existing suites were **added to, never relaxed**; no assertion anywhere was
weakened, skipped or deleted to obtain a green result.

## 5. Verification

### 5.1 Focused results (real WordPress, real run)

| Suite | Result |
|---|---|
| `test-event-date-transitions.php` | **54 passed, 0 failed** |
| `test-event-query.php` | **48 passed, 0 failed** |
| `test-event-schedule-refresh.php` | **21 passed, 0 failed** |
| `./scripts/run-tests.sh --php --only conexao-event-runtime` | 4 suites, **3 passed, 1 failed** — the failure is `test-event-language-gate.php` failing its declared prerequisite `polylang` (not installed in the ad-hoc environment); assertions **192 passed, 0 failed** |
| `./scripts/run-tests.sh --php --only conexao-event-importer` | 17 suites, **11 passed, 6 failed** — all six are environmental (2 × `Class "DOMDocument" not found` because the ad-hoc PHP has no `dom` extension; 1 × `polylang` prerequisite; 1 × ICS fixture records that only `bootstrap-ci-fixtures.php` step 4c creates; 1 × town-term fixture state; **`test-ics-occurrence-dates.php` was proven identical with the change reverted**); assertions **1141 passed, 6 failed** |

### 5.2 Aggregate run

`./scripts/run-tests.sh` (the canonical entrypoint: in-process PHP + script
contract + HTTP acceptance) was run on the final tree, and again with both fixes
reverted, in the same ad-hoc environment:

| Layer | Final | Pristine (both fixes reverted) |
|---|---|---|
| In-process PHP | 83 suites, **34 passed, 49 failed**; assertions **2824 passed, 72 failed** | 83 suites, **32 passed, 51 failed**; assertions **2815 passed, 81 failed** |
| Script-contract | 18 suites, **17 passed, 1 failed** (`verify-stage14-secret-scan.py` — needs a local WordPress over HTTP) | 18 suites, 17 passed, 1 failed (the same one) |
| HTTP acceptance | **0 suites run — `BLOCKED: HTTP acceptance environment unavailable`** | same |

The aggregate therefore **fails in this sandbox for environmental reasons**, not
because of this change: the environment has no Docker/MySQL/Polylang, no `pt_BR`
core language pack, no committed CI fixtures, no `dom` extension, and port 8080 is
already occupied by the sandbox itself, so the acceptance layer is BLOCKED before
any row executes. §7 shows the failing set differs only by the two suites that
cover the fixes — every other failure is identical without them.

### 5.3 Gates

| Gate | Result |
|---|---|
| `php scripts/generate-registry-docs.php --check` | **registry OK: 10 plugins validated, 19 generated regions current (zero writes)** |
| `tests/scripts/verify-i18n-freshness.py` | **3 passed, 0 failed — stale catalogues: 0** (both touched catalogues regenerated with the documented `./scripts/i18n-make-pot.sh` and committed) |
| `tests/scripts/verify-cache-key-scoping.py` | **2 passed, 0 failed** (no cache key added or changed) |
| `tests/scripts/verify-documentation-drift.py` | **14 passed, 0 failed** |
| `tests/scripts/verify-script-conventions.py` | **727 passed, 0 failed** |
| `tests/scripts/verify-release-integrity.py` | **224 passed, 0 failed** |
| `tests/scripts/verify-stage13-commissioning-readiness.py` | **808 passed, 0 failed** |
| `verify-agent-governance.py`, `verify-stage3/4/6/7/8/8-consistency/10/17/18` | passed |
| `tests/scripts/verify-stage14-secret-scan.py` | **failed — environment**: "the PHP runtime scanner could not be executed (no local WordPress)"; it fails identically with the fixes reverted, and it was already failing before this change |
| `php -l` on every changed PHP file | 0 syntax errors |
| `./scripts/lint.sh` | **could not run** — it requires `composer install` (`vendor/bin/phpcs`, `vendor/bin/phpstan`), which this sandbox has no Composer for; CI's `static quality` job is authoritative for PHPCS/PHPStan (§11) |

### 5.4 Baseline comparison

See §7. No failure is newly introduced; the only difference between the two runs
is that **two suites fail when the fixes are absent** and pass with them — exactly
the coverage added for the two confirmed causes (§6).


## 6. Failure proofs / negative tests

Each fix was reverted **on its own** (nothing else changed) and the corresponding
suite re-run, to prove the new assertions actually depend on the behaviour.

| Proof | What was broken | Result |
|---|---|---|
| Fix A — importer schedule refresh | the two end-field comparisons removed from `upsert_event()` | `test-event-schedule-refresh.php`: **16 passed, 5 failed** — `an end-date-only change is detected as an update` (got `unchanged`), `the stored end date is refreshed…` (got `today+3`, expected `today+1`), `the record is NO LONGER an upcoming event the day after its refreshed end date`, `…on the stale end date`, `the unchanged fast path leaves the end date untouched`. Evidence: `vacuity-fix1-importer.txt` |
| Fix B — weekly candidate window | the binding returned to `window_end` | `test-event-query.php`: **44 passed, 4 failed** — all four `R:` window assertions; `event-date-harness.php`: **36 passed, 2 failed** — both weekly boundary assertions. Evidence: `vacuity-fix2-runtime.txt` |
| Environment claim — `test-ics-occurrence-dates.php` | nothing; fix B reverted | **171 passed, 1 failed, identical to the run with the fix in place** → the failure is not caused by this change |

## 7. Regression comparison

Same environment, same command (`./scripts/run-tests.sh`), fixes in place vs both
fixes reverted:

| | Pristine (both fixes reverted) | Final |
|---|---|---|
| In-process PHP suites | 83 total, **32 passed, 51 failed** | 83 total, **34 passed, 49 failed** |
| In-process assertions | **2815 passed, 81 failed** | **2824 passed, 72 failed** |
| Script-contract suites | 17 passed, 1 failed | 17 passed, 1 failed |
| HTTP acceptance | BLOCKED (0 suites run) | BLOCKED (0 suites run) |

Diffing the two failing-suite lists (`fails-pristine.txt` vs the final run's list):

| Direction | Suites |
|---|---|
| failing **only** with the fixes reverted (i.e. fixed by this change) | `plugin/conexao-event-runtime/test-event-query.php`, `plugin/conexao-event-importer/test-event-schedule-refresh.php` |
| failing **only** with the fixes in place (i.e. newly introduced) | **none** |

49 of the 51 pristine failures are unchanged, environment-dependent conditions
(§8). The two that move are exactly the suites written for the two confirmed
causes, and 9 failing assertions became passing (81 → 72) with none added. The
new `test-event-date-transitions.php` suite passes in **both** runs by design: it
locks in the date-layer contract (T1–T8) rather than the two defects.

## 8. Known pre-existing failures (environment, not this change)

Classified from the aggregate log; each is caused by a capability the CI stack has
and this sandbox does not (proved by the pristine run producing the same list):

| Class | Suites | Cause |
|---|---|---|
| Polylang absent | 25 (theme EN/nav/translation suites, `test-event-language-gate.php`, importer language suites, leisure language suites) | Polylang is a pinned third-party plugin CI installs; it is not in this repository and cannot be installed here |
| Missing committed CI fixtures | `test-ics-occurrence-dates.php` (1 assertion), `test-town-sanitization.php` (5), `test-en-course-provider-category-filter.php`, `test-guide-breadcrumb-filter.php`, `test-i18n-foundation.php`, `test-filter-link-aria-semantics.php`, `test-leisure-multiselect-filters.php`, `test-nav-menu-regression.php`, several `conexao-translation-automation` suites | they assert over the synthetic site built by `scripts/bootstrap-ci-fixtures.php` (pages, guides, pt_BR formats, town terms), which was never run here |
| `dom` extension missing in the ad-hoc PHP | `test-mondello-park-importer.php`, `test-motorsport-ireland-importer.php` | `DOMDocument` is unavailable in the locally provisioned PHP CLI |
| Other declared prerequisites | `test-automation-translation-plan.php`, `test-en-jobs-shared-slug.php`, admin-ux/translation suites | require plugin activation states or fixtures this sandbox cannot provide |
| `verify-stage14-secret-scan.py` | script layer | requires a local WordPress over HTTP |

can be verified against production from here (§11).


## 9. Content, Polylang, route and production impact

| Question | Answer |
|---|---|
| Records created / updated / deleted by this change | **0** — code, tests, docs and catalogues only (`git diff --stat` touches no content) |
| Portable identifier strategy | unchanged — `_event_source` + `_event_source_id` (+ URL, content) remain the identity; nothing about matching changed |
| PT content changed | **no** |
| Translated types / taxonomies affected | none; PT remains immutable |
| Language-scoped cache keys | unchanged (`flush_cache()` still clears every language) |
| Routes, redirects, canonical, hreflang, sitemap | **unchanged** — no route or redirect file was touched |
| REST contract | unchanged |
| Capabilities, nonces, sanitisation, escaping | unchanged; the only SQL edit is a **parameter binding** in an existing `$wpdb->prepare()` query |
| Secrets | none |
| Queries added / removed | none (the comparison adds meta reads on a path that already reads meta) |
| Production write / deploy / upload / activation / DB mutation | **no / no / no / no / no** |

## 10. Operational follow-up (production verification still required)

**Outstanding question.** Does the production symptom come from stale
`_event_end_date` values on live records (the mechanism proven in §2.1)?
Production cannot be inspected from here and nothing in this change was applied
there.

**Evidence an authorised operator can collect in wp-admin only** (no SSH, no
WP-CLI, no database access — WordPress.com):

1. **Confirm the site configuration** — Settings → General: the timezone is
   `Dublin` / Europe/Dublin. (If it is UTC, that is a separate defect: the runtime
   evaluates dates in the site timezone.)
2. **Find a reported case** — Events list, look for events whose end date (the
   "Data final" field in the event editor) is **in the past** while the record is
   still `Published`. A record whose stored end date is in the future but whose
   real event has finished is the symptom; note its source id and end date.
3. **Confirm the value against the source** — open the event's source URL and read
   the real end date. If the source says the event ended earlier than the stored
   end date, the record carries a stale end date, and the importer fix is what
   refreshes it on the next import.
4. **Do not purge caches for this** — the repository's cache layer was proven sound
   (§2.3), and a purge would not change a stored end date. The WordPress.com edge
   cache is `max-age=300, must-revalidate` and cannot explain a day-scale symptom.
5. **After a future deploy of this change**, re-check step 2 for the same record
   after one local import + transfer, and confirm the stored end date now equals
   the source's and that the event leaves `/eventos/` on the day after it.

**Cache settings or scheduled processes requiring attention: none.** No cron,
scheduler or cache configuration is part of this change, and none was found to be
responsible for the symptom.



## 11. Limitations

- **No Docker, MySQL or WordPress-as-CI in this sandbox.** A PHP CLI, WordPress
  7.1.3 (SQLite) and GNU gettext were provisioned locally instead (see
  `environment.md` in the evidence directory). The in-process suites therefore ran
  against a real WordPress, but without Polylang, the `pt_BR` core language pack,
  the committed CI fixtures or the `dom` extension.
- **The HTTP acceptance layer did not run**: the runner reported
  `BLOCKED: HTTP acceptance environment unavailable` (no reachable WordPress at
  `http://localhost:8080`; the sandbox occupies that port). No acceptance row was
  executed, so no request-visible claim is made here.
- **`./scripts/lint.sh` (PHPCS + PHPStan) did not run**: it requires
  `composer install`, and Composer is unavailable in this sandbox. CI's
  `static quality` job remains authoritative for those gates. PHP syntax
  (`php -l`) was checked on every changed file.
- **Production was not inspected or modified**, so the *exact* production cause of
  the reported incident remains unproven (§2.4). What is proven: the code-level
  mechanism, that the cache and query layers cannot produce it, and that the fix
  prevents it.
- **`test-ics-occurrence-dates.php`** keeps one failing assertion here because the
  ICS fixture records are only created by `bootstrap-ci-fixtures.php` step 4c in
  CI; it was proven identical with the change reverted.
## 12. Files added / modified

**3 added, 10 modified, 0 deleted** (`git diff --stat fee68bf..HEAD` plus the
evidence/plan/report files; every path is one the plan named).

| Path | Change | Note |
|---|---|---|
| `wp-content/plugins/conexao-event-runtime/includes/class-event-query.php` | modified | the fix (weekly end bound) + docblock paragraph |
| `wp-content/plugins/conexao-event-importer/includes/class-event-importer.php` | modified | the fix (end-field change detection) + comment |
| `wp-content/plugins/conexao-event-runtime/tests/test-event-date-transitions.php` | **added** | T1–T8 |
| `wp-content/plugins/conexao-event-importer/tests/test-event-schedule-refresh.php` | **added** | schedule refresh + idempotence |
| `wp-content/plugins/conexao-event-runtime/tests/test-event-query.php` | modified | new section `R` |
| `wp-content/plugins/conexao-event-runtime/conexao-event-runtime.php` | modified | version 1.2.3 |
| `wp-content/plugins/conexao-event-importer/conexao-event-importer.php` | modified | version 1.7.2 |
| `…/conexao-event-runtime/languages/conexao-event-runtime.pot`, `…/conexao-event-importer/languages/conexao-event-importer.pot` | modified | regenerated with the documented script (no string changes) |
| `docs/plugins/conexao-event-runtime.md`, `docs/plugins/conexao-event-importer.md` | modified | the two contracts, each keeping its generated region current |
| `docs/architecture.md`, `docs/plugins/README.md`, `docs/project-inventory.md` | modified | **generated** regions regenerated for the version bumps |
| `docs/reports/2026-10-12-stage-23-event-freshness.md`, `…-plan.md` | **added** | this report + the plan |
| `docs/evidence/2026-10-12-stage-23/**` | **added** | harness, probes, vacuity proofs, run logs, environment notes |

`plugins.json` was **not** modified (it is the authoritative registry; the version
lives in the component headers), and no generated region was hand-edited.

## 13. Documentation updated

| Document | Change |
|---|---|
| `docs/plugins/conexao-event-importer.md` | new "Schedule fields and change detection (v1.7.2)" section: the start **and end** fields participate, and why an end-date-only change must reach the record |
| `docs/plugins/conexao-event-runtime.md` | the weekly candidate end bound is `_event_recurrence_end >= today` (with the superset argument) |
| `docs/architecture.md`, `docs/plugins/README.md`, `docs/project-inventory.md`, both plugin docs | generated registry regions regenerated for the version bumps |
| `docs/reports/README.md` | this report registered |

No procedure was moved out of a document, so no skill gained or lost a workflow.

## 13a. Evidence paths

`docs/evidence/2026-10-12-stage-23/`:

| File | Proves |
|---|---|
| `event-date-harness.php`, `harness-wp-stubs.php` | the investigation instrument: the real runtime classes against real SQL (SQLite) with a controlled clock — runnable without WordPress |
| `event-date-harness-prefix.txt` | the pre-fix scan: 36 passed, **2 failed** (the weekly window), and the day-by-day matrix showing every expired event excluded |
| `event-date-harness-postfix.txt` | the post-fix scan: **38 passed, 0 failed**, plus the corrected day-by-day matrix |
| `focused-suites.txt` | the three focused suites on the final tree: **54 / 48 / 21 assertions, 0 failed** |
| `component-runs.txt` | `./scripts/run-tests.sh --php --only conexao-event-runtime` (192 assertions, 0 failed; 1 Polylang prerequisite) and `--only conexao-event-importer` (1141 assertions, 6 failed — all environmental) |
| `vacuity-fix1-importer.txt` | reverting the importer fix alone fails `test-event-schedule-refresh.php` 16/5, including the reproduced stale-end-date symptom |
| `vacuity-fix2-runtime.txt` | reverting the runtime fix alone fails `test-event-query.php` 44/4 and the harness 36/2 |
| `aggregate-run.txt` | the canonical runner on the fixed tree (all three layers, with the acceptance layer BLOCKED) |
| `aggregate-pristine-baseline.txt` | the same runner with both fixes reverted — the baseline for §7 |
| `fails-final.txt`, `fails-pristine.txt` | the two failing-suite lists that §7 diffs |
| `script-contract-final.txt` | the script-contract layer on the final tree: **17 passed, 1 failed** (the local-WordPress-dependent Stage 14 scan) |
| `environment.md` | exactly how the verification environment was provisioned, and what it lacks |

## 14. Rollback / recovery

1. `git revert` the code commit (`fe43ac5`) — two single-site edits, no migration,
   no data change.
2. The version bumps, the regenerated catalogues and the registry regions are
   cosmetic/derived and revert with their own commits; reverting the code alone
   leaves the new suites red, which is the intended signal.
3. **No content rollback is required**: this change writes no content and no meta.
4. Rollback does **not** cover: a record whose end date was already refreshed by an
   import run performed with the fix in place. That value is source-derived and is
   reversed only by re-importing older source data.

## 15. Final status

**Final status: PASS WITH LIMITATION**

Both confirmed causes are fixed, the new and extended regression suites pass
(54 + 48 + 21 assertions, 0 failed), each fix is proven to fail its suite when
reverted, every permanent gate that can run here is green (registry drift, i18n
freshness, cache scoping, documentation drift, script conventions, release
integrity, Stage 13), and the aggregate failing-suite set differs from the
baseline **only** by the two suites that fail without the fixes (49 vs 51) with no
new failure anywhere. The limitation is that **production could not be inspected or
modified** and **the CI-only layers (Docker WordPress, HTTP acceptance, PHPCS/
PHPStan) could not run in this sandbox**, so the exact production cause of the
reported incident and the request-visible acceptance layer remain unverified;
§10 lists the wp-admin-only evidence an operator can collect to close that gap.

_Last verified: 2026-10-12 by Stage 23 — event freshness on `/eventos/`_
