# Plan — Laois Events visibility on /eventos (Stage 7.x)

| | |
|---|---|
| **Task title** | Diagnose and fix 30 named Laois Events missing from /eventos |
| **Date** | 2026-09-26 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Repository baseline (starting SHA)** | `7778d7644aba94eca7d3717e569d98afbf3cf15a` |
| **Plan status** | approved |

## 1. Scope

Measure where each of the 30 named Laois Tourism events falls out of the
`/eventos` pipeline (source → record → query → render), prove the exclusion
reason per event, then apply the smallest fix that restores them through the
**normal** event query — with no hard-coded IDs, no re-import, no duplicate
records and no change to the `_event_source` / `_event_source_id` /
`_event_export_uuid` identity fields.

## 2. Explicit non-goals


Root cause chain (proven, not inferred):

1. The stored `conexao_event_sources` option has `laois_tourism.county = ''`
   (empty), while the shipped default in `Conexao_Event_Sources::get_defaults()`
   declares `'county' => 'Laois'`. The stored option wins, because `get_all()`
   only ever *adds missing* sources — it never repairs a drifted field.
2. `Conexao_Source_ICalendar::parse_vevent()` only sets `$event['county']` when
   `$this->config['county']` is non-empty, so the county is never forwarded.
3. `Conexao_Event_Importer::save_event_taxonomies()` is therefore a no-op for
   county, so no `conexao_county` term is ever written.
4. The events are still listed on the **plain** `/eventos` archive (deep in the
   pagination) but are **invisible to the county filter** — which is how a user
   looks for "Laois events".

Why other events still appear: every `eventbrite_<county>` source carries a
non-empty `county` in the stored option, so those events get the term. The 34
older `laois_tourism` events that *do* carry the term are all
`_event_status = source_not_found`, so they are excluded by the status gate —
which is why the county filter shows 20 events and not 54.

Additional data-integrity finding (measured, **not** the visibility cause):
**every** meta row of the 30 posts is duplicated (25 duplicated keys per post;
`post_id 276` also has a duplicated `_wp_old_slug`). `get_post_meta()` returns
the first row and `SELECT DISTINCT p.ID` absorbs the join fan-out, so listing is
unaffected — but the duplication is real and is recorded.

Production cross-check: `https://conexaobr.ie` returns **1779** events and
**29 of the 30 named events do not exist there at all** (exact normalised-title
match: 1/30 — "Mabon & Full Moon Day Retreat" = prod 11553). The 30 records are a
**local-only** dataset.

## 5. Proposed approach

Fix the two real defects, both upstream of the query:

- **Fix A (code, forward-looking).** Make the source-level `county` hint
  self-healing: when a stored source's `county` is empty but the shipped
  default declares one, restore it in `get_all()`. This repairs the drift that
  caused the bug and protects **every future** Laois Tourism import.
- **Fix B (code, importer path).** Guarantee the county assignment runs for
  *every* imported event, including the `"unchanged"` fast path, which today
  returns **before** `save_event_taxonomies()`. Without this, an already-imported
  event can never acquire a county term on a later run.

Then **Fix C (local data, dry-run first)**: assign the existing shared
`conexao_county/laois` term to the 30 records via a dry-run-first, idempotent
script. No term is created; the shared identity is reused.

Options rejected:

- *Hard-code the 30 IDs / titles in the query* — explicitly forbidden; masks the
  cause and breaks future imports.
- *Change the `/eventos` query, date filter or ordering* — the query is correct;
  the data was wrong.
- *Create a new `Laois` term* — forbidden; counties are shared geography.
- *Re-import* — forbidden, and unnecessary.

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `wp-content/plugins/conexao-event-importer/includes/class-event-sources.php` | repair empty `county` from shipped defaults in `get_all()` | Fix A |
| `wp-content/plugins/conexao-event-importer/includes/class-event-importer.php` | run `save_event_taxonomies()` on the `unchanged` fast path | Fix B |
| `scripts/repair-laois-event-county.php` | new dry-run-first, idempotent repair script | Fix C |
| `wp-content/plugins/conexao-event-importer/tests/test-source-county-hint.php` | new regression suite | Phase 25 |
| `docs/reports/CONEXAO_BR_LAOIS_EVENTS_VISIBILITY_REPORT.md` | final report | deliverable |
| `stage7-work/*` | evidence artifacts | deliverable |

- **No re-import** of the 30 events. The brief forbids it and it is not needed:
  the records already exist.
- **No production content write.** Production has no CLI and the brief does not
  authorise a production data change. Local Docker only.
- **No change to the event query, filters, pagination or ordering.** The
  `/eventos` query is behaving exactly as designed; the defect is upstream of
  it, in the data.
- **No new county terms.** `conexao_county` is a *shared* geography taxonomy
  (AGENTS.md); `laois` (term 1373) already exists and is reused.
- **No de-duplication of the genuine local duplicate** (`post_id 276`, duplicated
  `_wp_old_slug`) — out of scope, recorded only.
- **Flutter/mobile repository** — untouched, frozen.

## 3. Relevant authoritative documents

`AGENTS.md`, `docs/engineering-standard.md`, `docs/content-model.md`,
`docs/routing.md`, `docs/testing.md`, `docs/plugins/conexao-event-runtime.md`,
`docs/plugins/conexao-event-importer.md`, `docs/events/*.md`.

## 4. Current-state findings (measured, baseline `7778d76`)

All 30 events exist locally as post IDs **100001–100030**, all
`post_status=publish`, all `_event_status=published`, all dated today or later,
and **all 30 are in `Conexao_Event_Query::upcoming_events()`** — the list that
`/eventos` feeds to `post__in`.

Measured, per condition:

| Condition | Result |
|---|---|
| `post_status` = publish | 30/30 PASS |
| `_event_status` = published | 30/30 PASS |
| Date window (`_event_date`/`_event_end_date` >= today) | 30/30 PASS |
| SQL candidate stage (`candidate_event_ids()`) | 30/30 PASS |
| PHP recurrence stage (`next_occurrence()`) | 30/30 PASS |
| Language gate (`is_in_current_language()`) | 30/30 PASS (all PT) |
| **Present in the archive ordering** | **30/30 — at ranks 124–315 (pages 13–32)** |
| **`conexao_county` term present** | **0/30 — none has any county term** |
| **Reachable via `/eventos/?county=laois`** | **0/30** (filter returns 20 events, all Eventbrite) |


## 7. Files explicitly expected NOT to change

- `wp-content/themes/conexao-br-irlanda/inc/archive-query.php` — the archive
  query is correct; changing it would mask the cause.
- `wp-content/plugins/conexao-event-runtime/**` — the status/date gate is correct.
- `.../sources/class-icalendar-source.php` — the county-hint contract is
  correct; the *config value* was wrong.
- `wp-content/themes/conexao-br-irlanda/inc/i18n/rest-language.php` and the REST
  language layer — no REST regression was found.
- `plugins.json` — no plugin added, removed or re-classified.

## 8. Content / data impact

- Records created: **0**. Updated: **30** (one taxonomy term assignment each).
  Deleted: **0**.
- Portable identifiers: `_event_source`, `_event_source_id` and
  `_event_export_uuid` are **read-only** in the repair script and asserted
  unchanged in the AFTER snapshot.
- Does this write content? It writes **one taxonomy term** on existing PT
  records — a data correction, so the six-step contract applies: the repair
  script is dry-run by default and requires an explicit `--apply`.
- PT content changed? **No.** Title, content, slug, dates, status and identity
  meta are untouched; only `conexao_county` is added.

## 9. Route / HTTP impact

- Routes: none added, removed or changed. Redirects: none.
- Sitemap: unaffected (a term assignment does not alter a post's published
  state).
- HTTP matrix: `/eventos`, `/eventos/?county=laois`, `/en/eventos`,
  `/en/eventos/?county=laois`.

## 10. Polylang / English impact

- Taxonomies: `conexao_county` only, which is **shared** (not translated), so
  one term serves both languages and no per-language term is created.
- PT immutable: **yes** — no PT field changes.
- B1/B2: unchanged. The events are PT records; the B2 rule in
  `Conexao_Event_Query::is_in_current_language()` and the `lang=en,pt` widening
  in `conexao_content_archive_query()` are untouched.
- Language-scoped cache keys: unaffected. The upcoming-events transient is keyed
  by day + language; the repair script flushes it via
  `Conexao_Event_Query::flush_cache()`.

## 11. Security impact

- Capabilities: the repair script is CLI-only and local-only; it is not shipped
  in any release ZIP and is not reachable over HTTP.

## 14. Test plan

- New in-process suite:
  `wp-content/plugins/conexao-event-importer/tests/test-source-county-hint.php`
  asserting the **rule**, not the record list:
  1. a shipped-default source whose stored `county` is empty is repaired to the
     default county;
  2. a stored non-empty county is **never** overwritten;
  3. a source with no declared default county stays empty;
  4. a source-shaped event (config county set) produces a `county` that
     `save_event_taxonomies()` turns into a real `conexao_county` term, and that
     event is then returned by the events query.
- Existing suites: `./scripts/run-tests.sh` (all layers, one exit code).
- Acceptance: `stage7-work/http-after.md`.

## 15. Acceptance matrix plan

| id | url | expect_status | expect_contains | note |
|---|---|---|---|---|
| A1 | `/eventos/` | 200 | event-card markup | plain archive unchanged |
| A2 | `/eventos/?county=laois` | 200 | >=30 target cards across pages | county filter reaches the 30 |
| A3 | `/en/eventos/` | 200 | event-card markup | EN archive unchanged |
| A4 | `/en/eventos/?county=laois` | 200 | target cards | EN county filter |
| A5 | `/eventos/?county=dublin` | 200 | dublin cards, no target cards | no cross-county leakage |

## 16. Rollback plan

- Code: `git revert` the two plugin commits.
- Data: the repair is a single additive term assignment. Rollback is
  `wp_remove_object_terms( $id, array( 1373 ), 'conexao_county' )` for the same
  30 IDs, driven by the BEFORE snapshot (`stage7-work/laois-events-before.json`),
  which records each post's county array as empty. No content, meta or identity
  field is involved, so nothing else needs reversing.

## 17. Documentation plan

| Document | Change |
|---|---|
| `docs/reports/CONEXAO_BR_LAOIS_EVENTS_VISIBILITY_REPORT.md` | new final report |
| `docs/plugins/conexao-event-importer.md` | note the county-hint self-healing contract |
| `scripts/README.md` | register the repair script |

## 18. Release implications

- No version bump required (local-only tooling, no behaviour change in a
  production plugin).
- Effect on the artifact allowlist: **none** — `conexao-event-importer` is
  `production: no` and is not in any release ZIP.
- A release is **not** in scope.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Repairing `county` from defaults overwrites a deliberate user choice | low | Only fills an **empty** county; a non-empty stored value is never touched (asserted by test) |
| Scope creep into the archive query | medium | Query/theme/runtime files are on the do-not-change list and are verified unchanged |
| The term assignment changes PT content | low | Only a shared, non-translated geography term is added; all other fields asserted identical in the AFTER snapshot |
| Repair script run twice | low | Idempotent by design: it only assigns when no county term exists |

## 20. Verification gates

1. `./scripts/run-tests.sh` — all layers, aggregate exit code 0.
2. `php scripts/generate-registry-docs.php --check` — registry drift 0.
3. AFTER snapshot: `targets_in_county_filter` = 30 (was 0), and every identity
   field byte-identical to BEFORE.
4. `stage7-work/http-after.md` — 30/30 target cards on `/eventos/?county=laois`.

## 21. Completion criteria

Every applicable item of `docs/engineering-standard.md` §14 holds; the report
contains real numbers; no event ID is hard-coded; no duplicate is created; no
identity field changed; PT content unchanged apart from the proven, necessary
taxonomy correction.

- Nonces / escaping / SQL: the script uses WP APIs (`wp_set_object_terms`,
  `$wpdb->prepare`) exclusively; no raw SQL concatenation.
- REST surface: unchanged. Secrets: none.

## 12. Performance impact

- Queries: none added to any request path. The repair script is one-off.
- Caching: the `conexao_event_upcoming_*` transient is flushed after the repair
  so the archive reflects the new terms immediately.
- Assets/images: unaffected.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | n/a — production is read-only in this stage |
| What is the verification of the production result? | Read-only REST + HTTP measurement of the current production state (1779 events; 29/30 absent), recorded as evidence |

Production is **not** modified. Both code fixes are in a local-only plugin
(`conexao-event-importer`, `production: no` in `plugins.json`), so they cannot
affect production behaviour at all.
