# Plan — Stage 23: event freshness on `/eventos/` (stale schedule meta + weekly window boundary)

| | |
|---|---|
| **Task title** | Investigate why `/eventos/` can keep listing events that should no longer be classified as happening today, fix the confirmed causes, and lock the date layer down with deterministic regression tests |
| **Date** | 2026-10-12 |
| **Author / agent** | Cline |
| **Branch** | `cline/rphybc0f` |
| **Repository baseline (starting SHA)** | `6ec382bba663e21c5dc90b90fac01f61afe82d36` (= upstream `master`) |
| **Applicable skill(s)** | `wp-repository`, `wp-content-change`, `wp-write-in-process-test`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

Trace the complete event retrieval path behind `/eventos/` (template → archive query →
`Conexao_Event_Query` → `Conexao_Event_Recurrence` → transient cache → imported
`_event_*` meta), prove or rule out each candidate cause of "expired events keep
appearing", then fix the confirmed causes with the smallest possible changes and add
deterministic, clock-controlled regression tests for the date layer.

Confirmed causes to fix:

1. **Importer schedule refresh gap (primary).** `upsert_event()`'s change detection
   compares `_event_date` and `_event_start_time` but **not** `_event_end_date` /
   `_event_end_time`, and the `"unchanged"` fast path deliberately reconciles
   taxonomies and recurrence but never `save_event_meta()`. A source that corrects or
   shortens an event's end date is therefore classified `unchanged` and the stored end
   date is never refreshed — the event keeps appearing on `/eventos/` (with a
   "Hoje" chip) after it has really ended, and `Conexao_Event_Status::mark_expired_events()`
   reads the same stale timestamp.
2. **Weekly candidate-window boundary (corroborating).** `Conexao_Event_Query::candidate_event_ids()`
   requires `_event_recurrence_end >= today + 7` although its own docblock and
   `docs/plugins/conexao-event-runtime.md` state the series must only be "not ended
   before today". An active weekly series is therefore dropped from `/eventos/` up to
   six days before it ends — including on the day it occurs.

## 2. Explicit non-goals

- **No cache changes.** The date-keyed transient is proven sound (evidence `cache-probe.txt`):
  the key carries the site-local `Ymd`, the TTL ends at local midnight, a stale
  previous-day entry cannot be read, and `flush_cache()` clears ±1 day for every
  language. No TTL, key or invalidation change is justified.
- **No production action of any kind** (no write, upload, activation, import, cache purge).
- **No new event lifecycle**, no second scheduler, no occurrence posts, no new meta keys.
- **No change to the recurrence evaluator** (`Conexao_Event_Recurrence`): its
  "scan the next 7 calendar days" contract is documented and correct; the SQL
  candidate query must stay a superset of it.
- **No change to PT content, slugs, taxonomies, routes, redirects, Polylang config or REST contracts.**
- **No broadening of the importer's past-event filter** (it deliberately leaves already
  imported records alone) and no "cleanup" of historical records.
- **No Flutter/mobile repository access.**


## 3. Relevant authoritative documents

| Fact | Owner |
|---|---|
| Scope, safety rules, never-touch list | `AGENTS.md` |
| Engineering rules, §5.2 content-change contract, §8 tests, §9 docs, §14 done | `docs/engineering-standard.md` |
| Event meta, statuses, recurrence model, query integration, cache | `docs/plugins/conexao-event-runtime.md` |
| Import pipeline, date filter, change detection, identity | `docs/plugins/conexao-event-importer.md` |
| Event meta keys and language policy | `docs/content-model.md` |
| Test layers, prerequisites, permanent gates | `docs/testing.md` |
| Plugin load order / production / build facts | `plugins.json` |

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository` (orientation, plan, scope) → `wp-content-change` (importer write path) → `wp-write-in-process-test` (suites) → `wp-update-docs` (docs + index) |
| **Its preconditions** | Clean tree confirmed; baseline SHA recorded; local stack for the in-process layer |
| **Its guardrails that bind this task** | Idempotent writes only; no portable-identifier change; no second lifecycle; no weakened gate; tests must fail when the change is reverted; no bespoke assertion helpers; `tests/bootstrap.php` is the only WordPress loader |
| **Its verification commands** | `./scripts/run-tests.sh`, `./scripts/run-tests.sh --list`, `php -l`, `./scripts/lint.sh`, `php scripts/generate-registry-docs.php --check` |
| **Its evidence location** | `docs/evidence/2026-10-12-stage-23/` |

## 4. Current-state findings

- Baseline `6ec382b` is **byte-identical to upstream `master`** (`gh api`), so nothing
  in the tree is a planted divergence: the defects are real upstream defects.
- `/eventos/` is fed by `conexao_event_upcoming_ids()` → `Conexao_Event_Query::upcoming_events()`
  → SQL candidate widening → exact PHP evaluation (`next_occurrence()`), cached in the
  date- and language-keyed transient `conexao_event_upcoming_YYYYMMDD_<lang>`.
- **Query layer is sound (proved by execution, `docs/evidence/2026-10-12-stage-23/`)**: a
  fixture matrix of 17 event shapes scanned day by day with the real classes over real
  SQL (SQLite) shows that every event that has ended is excluded and every ongoing
  multi-day event stays visible. The only two anomalies are the weekly-window boundary
  above (a **false negative**) and the evaluator/SQL window mismatch (benign superset).
- **Cache layer is sound (proved by execution)**: the transient key is
  `conexao_event_upcoming_<Ymd>_<lang>`, TTL = seconds to the next local midnight, a
  yesterday-keyed entry is never read today, and `flush_cache()` clears ±1 day × languages.
- **Import layer is not sound**: `class-event-importer.php` compares
  `title, start_date, start_time, url, venue, organizer, banner, address, recurrence`
  but never `end_date` / `end_time`; the fast path returns before `save_event_meta()`.
  The same file *does* reconcile taxonomy and recurrence on that path, with comments
  explaining that the fast path would otherwise leave stale metadata — the end date was
  simply missed.
- No cron, no scheduled refresh, no production-side job: imports are manual and
  local-only, and WordPress.com offers no CLI/filesystem.

## 5. Proposed approach

1. **Importer (fix 1).** Add the event's end date and end time to the change-detection
   comparison in `upsert_event()`, so a source end-date/time correction reaches the
   existing record through the *existing* update path (identity, dedup, taxonomy and
   recurrence behaviour untouched). Rejected alternatives: reconciling the schedule
   meta on the fast path (a second write path for the same fields), and rewriting
   `save_event_meta()`/the deduplicator (broad, unnecessary).
2. **Runtime (fix 2).** Correct one placeholder: `re.meta_value >= today` instead of
   `>= window_end`, restoring the documented contract ("not ended before today") and
   keeping the SQL a strict superset of the evaluator's 7-day scan. Rejected
   alternatives: widening the evaluator window (changes documented semantics) and
   touching the archive query (no defect there).
3. **Tests.** Two new suites plus one extended suite, all deterministic (controlled
   clock via `upcoming_events( $from )`, fixture events created and deleted by the
   suite itself): the eight date-layer cases the task requires, the weekly-window
   boundary, and the importer's schedule refresh + idempotence.
4. **Docs.** Record the change-detection contract and the weekly-window boundary in the
   two plugin docs, register the report in `docs/reports/README.md`.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-importer.php` | add `end_date`/`end_time` to change detection (+ comment) | stale `_event_end_date` keeps expired multi-day events visible |
| `wp-content/plugins/conexao-event-runtime/includes/class-event-query.php` | one `$wpdb->prepare()` argument (`window_end` → `today_str`) + comment | active weekly series invisible up to 6 days before their end |
| `wp-content/plugins/conexao-event-runtime/tests/test-event-date-transitions.php` | new suite | day transitions, cache rollover, timezone/DST, ongoing multi-day, filters/order intact |
| `wp-content/plugins/conexao-event-runtime/tests/test-event-query.php` | new section | weekly-window boundary (regression for fix 2) |
| `wp-content/plugins/conexao-event-importer/tests/test-event-schedule-refresh.php` | new suite | end-date-only source change is applied; idempotent; no duplicate |
| `docs/plugins/conexao-event-importer.md`, `docs/plugins/conexao-event-runtime.md` | contract wording | keep the owner of each fact current |
| `docs/reports/2026-10-12-stage-23-*.md`, `docs/reports/README.md` | plan + report + index row | §9 |
| `docs/evidence/2026-10-12-stage-23/**` | investigation output | machine evidence |

## 7. Files explicitly expected NOT to change

- `plugins.json`, any `BEGIN/END GENERATED` region, `composer.json`/`composer.lock`.
- `wp-content/plugins/conexao-event-runtime/includes/class-event-recurrence.php`
  (evaluator semantics are correct and documented).
- `wp-content/plugins/conexao-event-runtime/includes/class-event-status.php`
  (the status gate composes correctly with the date filter).
- `wp-content/themes/conexao-br-irlanda/**` (no theme defect found; PT templates untouched).
- `scripts/**`, `.github/workflows/**`, `.htaccess`, `docs/routing.md`, `docs/content-model.md`.
- Any test that already exists: existing assertions may be **added to**, never relaxed.

## 8. Content / data impact

- Records created / updated / deleted by this change: **0** (code + tests + docs only).
- Portable identifier strategy: unchanged — `_event_source` + `_event_source_id` (+ URL,
  content) remain the identity; nothing about matching changes.
- Does this write content? No. The changed importer code runs only when an operator
  performs a local import, which this task does not do. No dry-run/snapshot obligation
  arises because no write is performed here.
- PT content changed? **No.**

## 9. Route / HTTP impact

- Routes added, removed or changed: **none**.
- Redirects: none. Sitemap: none. HTTP matrix rows: none added (the acceptance suite
  already covers `/eventos/`; its fixtures keep behaving identically — see §15).

## 10. Polylang / English impact

- Translated types affected: none.
- PT immutable: **yes**.
- B1/B2: unchanged. Canonical/hreflang/switcher: unchanged.
- Language-scoped cache keys: unchanged (`flush_cache()` still clears every language).
- Completeness gate: not applicable (no content, no new strings).

## 11. Security impact

- Capabilities: unchanged. Nonces: unchanged.
- Sanitisation/escaping: unchanged; no new output.
- SQL: the only SQL edit is a **parameter binding** in an existing `$wpdb->prepare()`
  query (still a prepared statement, still a `Y-m-d` string literal).
- REST surface: unchanged. Secrets: none.



## 12. Performance impact

- Queries added/removed: **none** (the change-detection comparison adds meta reads on a
  path that already reads meta; the runtime query is unchanged in shape).
- Caching: no new key, no TTL change, no invalidation change.
- Assets/images: none.
- Measurable improvement: none claimed; this is a correctness change.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how (production has no SSH/WP-CLI)? | nobody in this task; any future release is a separate, authorised `wp-release-deploy` task |
| What is the verification of the production result? | none performed; the report's "Operational follow-up" lists the operator evidence that would confirm the production symptom |

## 14. Test plan

- In-process suites: 2 new + 1 extended (see §6). Deterministic clock via
  `Conexao_Event_Query::upcoming_events( $from )`; fixtures created and deleted by the
  suite; no dependence on real-world dates.
- Acceptance: none added. The existing `tests/acceptance/verify-event-occurrence-http.py`
  discovers ICS cards from what the site renders and its CI fixtures are open-ended
  weekly series / `today+1..today+3` multi-day / all-day range, so neither fix changes
  their candidacy (verified by reading `scripts/data/ci-fixture-*.php`).
- Script contract: unaffected (`tests/scripts/**` untouched).
- Registry drift: not applicable (`plugins.json` untouched) but re-run anyway.
- Pass criterion: the two new suites and the extended suite report `0 failed`, and the
  aggregate failure set is identical to the recorded baseline.

## 15. Acceptance matrix plan

No rows added. Rationale: the change alters *which events are candidates* only for a
series whose `_event_recurrence_end` falls inside the 7-day window (absent from every
committed fixture) and *refreshes a stored end date* only when an operator imports
changed source data (not performed in tests). Adding a row would require a fixture that
mutates between two imports inside the acceptance run; the in-process suites cover it
deterministically instead.

## 16. Rollback plan

1. `git revert` the two code commits (importer comparison, runtime placeholder) — both
   are single-site edits with no data migration.
2. No content rollback is required: this change writes no content and no meta.
3. The tests are additive; reverting the code without the tests leaves the new suites
   red, which is the intended signal.
4. What rollback does **not** cover: an operator who already ran an import with the fix
   in place will have refreshed end dates on existing records. That is the intended
   behaviour, is source-derived, and is reversed only by re-importing older source data.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/plugins/conexao-event-importer.md` | state that the schedule fields (start **and end** date/time) participate in change detection, and why | — |
| `docs/plugins/conexao-event-runtime.md` | state the weekly candidate boundary explicitly (`_event_recurrence_end >= today`) | — |
| `docs/reports/README.md` | register the Stage 23 report | — |
| `docs/reports/2026-10-12-stage-23-event-freshness.md` | the report | — |
| `docs/testing.md` | only if the layer inventory/prerequisite changes (it does not) | — |

## 18. Release implications

- Component versions: `conexao-event-runtime` **1.2.2 → 1.2.3** and
  `conexao-event-importer` **1.10.0 → 1.10.1** (component headers; the registry reads
  versions from the headers, so `plugins.json` is untouched — re-run the drift gate).
- Artifact allowlist: unchanged (derived from `plugins.json`).
- A release is **not** in scope for this task.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| The importer comparison addition causes update churn on re-import | low | the update path writes exactly the compared values, so the next run compares equal; asserted by the new idempotence assertion |
| A source that stops declaring an end date narrows a manually extended range | low | that is already true of every other source-driven update; the source owns imported scheduling data, and the behaviour is asserted, not assumed |
| **Doing too much**: refactoring the evaluator, the status lifecycle or the cache to "fix" a symptom that is already correct | medium | non-goals §2; the two fixes are one comparison list and one placeholder |
| Production edge cache remains unverified | medium | stated explicitly as a limitation; operator evidence steps in the report, no speculative cache change |

## 20. Verification gates

1. `php -l` on every changed PHP file → 0 syntax errors.
2. `./scripts/run-tests.sh --list` → the new suites are discovered by convention.
3. `./scripts/run-tests.sh --only conexao-event-runtime` and `--only conexao-event-importer`
   → `N passed, 0 failed` for the touched components.
4. `./scripts/run-tests.sh` → aggregate, compared against the recorded baseline.
5. `php scripts/generate-registry-docs.php --check` → 0 writes, no drift.
6. `./scripts/lint.sh` → syntax + no new PHPCS violation + PHPStan 0 errors.
7. The harness/evidence probes re-run and pasted into `docs/evidence/2026-10-12-stage-23/`.

## 21. Completion criteria

`docs/engineering-standard.md` §14: the change is scoped and recorded; every fact has
one owner; the tests fail when the fix is reverted; the gates above were run with real
numbers pasted into the report; the working tree contains only the planned files; no
production write occurred; the mobile repository was not touched; and the final status
line states honestly whether the production symptom is confirmed.

_Last verified: 2026-10-12 by Stage 23 — event freshness on `/eventos/`_
