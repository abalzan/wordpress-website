# Plan — Stage 21: the CI site imports a real ICS, and the two gates stop depending on untracked `dist/`

| | |
|---|---|
| **Task title** | Stage 21 — fix CI run 37009756493: ICS occurrence acceptance + Stage 13/14 `dist/` dependency |
| **Date** | 2026-10-02 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Repository baseline (starting SHA)** | `1746a1c285b6108f8ab822b8cca5d77b5ac24aca` |
| **Applicable skill(s)** | `wp-repository`, `wp-testing`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

CI run `37009756493` is red in three independent places. This plan fixes the
root cause of each:

1. **HTTP acceptance `verify-event-occurrence-http.py` (6 failures).** The
   deterministic CI site never imported an iCalendar document, so the three ICS
   events the suite asserts on did not exist: absent from the Laois archive and
   404 on their detail pages. The suite had only ever passed against a developer
   database into which the Stage 20 evidence importer had been run by hand.
2. **Script-contract `verify-stage13-commissioning-readiness.py` (3 failures).**
   The preflight derived `versions_match_release` from the git-ignored
   `dist/release.json`, which does not exist on a fresh CI checkout, so
   `release_readiness` was `BLOCKED` on a clean tree.
3. **Script-contract `verify-stage14-secret-scan.py` (2 failures).** The gate
   required `dist/*.zip` and `dist/release.json` to already be built, with the
   same untracked-state dependency.

## 2. Explicit non-goals

- **No production action of any kind.** No deploy, upload, activation, content
  write or Polylang change. Local Docker and CI only.
- **No second recurrence engine, no fixture-only event system.** The CI site
  must exercise the production import and render path, not a parallel one.
- **No rewrite of the event model.** `Conexao_Event_Recurrence` and
  `Conexao_Event_Query` are untouched: one canonical post per event, no
  occurrence posts, no new meta keys.
- **No weakening of any gate.** Both script-contract gates keep every assertion;
  they stop reading untracked state. Stage 14 keeps scanning the full release
  set (9 plugin ZIPs + the theme ZIP + the release record).
- **No change to i18n / Polylang behaviour**, and no change to `plugins.json`.
- **Not touching the mobile/Flutter repository.**

## 3. Relevant authoritative documents

`AGENTS.md`; `docs/engineering-standard.md` (§1.3 gitignore, §8 tests, §9
reporting, §11 release, §13.2 plan/report); `docs/testing.md`; `docs/routing.md`
(§ Events archive and filters); `docs/plugins/conexao-event-importer.md`;
`docs/plugins/conexao-event-runtime.md`; `scripts/README.md`.

## 3a. Skill workflow

| | |
|---|---|
| **Skill** | `wp-repository` (loop), `wp-testing` (verification), `wp-update-docs` (docs) |
| **Its preconditions** | branch `i18n`; read `AGENTS.md` + the standard |
| **Its guardrails that bind this task** | never weaken a fail-closed gate; never hand-maintain a second source of truth; evidence in `docs/evidence/<date>-<stage>/`; no report at the repository root |
| **Its verification commands** | `./scripts/run-tests.sh`, `python3 scripts/verify-permanent-gates.py`, `./scripts/lint.sh` |
| **Its evidence location** | `docs/evidence/2026-10-02-stage-21/` |

## 4. Current-state findings

Verified by reading the code and running commands against the local stack at
`1746a1c`, not assumed.

- `scripts/bootstrap-ci-fixtures.php` builds events **only** from
  `scripts/data/ci-fixture-events.php`. Grepping the whole orchestrator for an
  ICS import returns nothing. The Laois feed is imported only by
  `wp-content/plugins/conexao-event-importer/tests/stage20-laois-import-run.php`,
  a Stage 20 **evidence runner** that no CI step invokes.

## 5. Proposed approach

**Option chosen for (1) — import a real ICS through the real engine.** Add
`scripts/data/ci-fixture-laois-ics.php`, which emits a real iCalendar document,
and a step 4c in `bootstrap-ci-fixtures.php` that persists it on the
`laois_tourism` source through `ics_content` — the exact slot wp-admin fills
when an operator uploads an ICS file — and calls
`Conexao_Event_Importer_Engine::run_source()`. The parser, the source-scoped
`Conexao_Laois_Tourism_Series` adapter, the RRULE reader, the recurrence meta
writer, `Conexao_Event_Query` and the theme card are all the production ones.

The document carries three deliberately different shapes (collapsed weekly
series with no `RRULE`, a genuine multi-day timed event, a `VALUE=DATE` all-day
range) so the whole occurrence contract is covered, and **no `ATTACH` line**, so
the importer never attempts a network banner download in an offline job. Its
dates are **relative** to one captured site-local "today", for the reason
`ci-fixture-events.php` already documents: a fixed date would silently empty
`/eventos/` once expired. The series starts five days in the past, so the badge
the archive renders is provably the *next* occurrence and not the series start.

Rejected: seeding the events directly with `wp_insert_post()` (a parallel event
system that would never exercise the importer or the adapter); reusing the
committed real-world `laois-tourism.ics` unchanged (its fixed 2026 dates make the
suite expire, the exact trap `ci-fixture-events.php` warns about).

**Option chosen for (1b) — one real presentation defect.** With the fixture in
place, the series card still rendered `event-card-date-day = 4-18` and
`datetime="2026-10-04/2026-10-18"`: the theme was still presenting the collapsed
`DTSTART..DTEND` pair as a continuous multi-day run — the exact pre-fix
interpretation, left behind in the presentation layer after Stage 20 fixed the
matching layer. `template-parts/event-card.php` now suppresses the range badge
and the ISO range for a weekly series; the series already carries its own
end-of-series chip (`até 18 OUT`).

Rejected: changing what the importer stores (the Stage 20 recurrence keys and the
export/import allowlists are correct as they are, and the in-process suite
asserts them).

**Option chosen for (2) — read the record the build just emitted.** The preflight
already builds the artifacts twice into a temp dir; `build-plugins-zip.sh` emits
`release.json` there. `versions_match_release` now compares the plugin headers
against **that** record instead of a possibly-absent `dist/release.json`. This is
strictly stronger — it compares against the record describing the artifacts just
built — and it fails closed when there is no build (`--skip-build`).

Rejected: committing a `dist/`; relaxing the check when no record is found.

**Option chosen for (3) — build the artifacts inside the gate.** Stage 14 now
runs `build-plugins-zip.sh` **and** `build-theme-zip.sh` into a temporary
directory it removes, and scans those ZIPs plus the emitted release record. The
gate always measures what the repository builds right now, can never be
satisfied by a stale or hand-modified directory, and behaves identically on a
laptop and on a fresh CI runner. A build failure is still a hard failure.

Rejected: skipping the artifact scan when `dist/` is absent (that is the
weakening the task forbids).

## 6. Files expected to change

| Path | Change | Why |
|---|---|---|
| `scripts/data/ci-fixture-laois-ics.php` | **new** | Emits the deterministic ICS document and the occurrence facts the audit asserts |
| `scripts/bootstrap-ci-fixtures.php` | modified | Step 4c runs the real importer over that document; step 9 audits the occurrence contract numerically |
| `wp-content/themes/conexao-br-irlanda/template-parts/event-card.php` | modified | A weekly series is no longer rendered as a continuous date range |
| `tests/acceptance/verify-event-occurrence-http.py` | rewritten | Date-relative and structural; no hard-coded title, slug or calendar date |
| `scripts/verify-commissioning-readiness.py` | modified | Version agreement read from the record the run's own build emitted |
| `tests/scripts/verify-stage14-secret-scan.py` | modified | Builds and scans the full release set instead of reading untracked `dist/` |
| `docs/reports/…stage-21….md`, `docs/reports/README.md`, `docs/testing.md` | modified / new | Plan, report, index, test-model note |

## 7. Files explicitly expected NOT to change

| Path | Why |
|---|---|
| `wp-content/plugins/conexao-event-runtime/**` | The recurrence model and evaluator are correct and untouched; the defect was that nothing imported an ICS and that the theme still rendered a range |
| `wp-content/plugins/conexao-event-importer/includes/class-conexao-ics-recurrence.php`, `…-laois-tourism-series.php`, `class-event-importer.php` | Stage 20's engine is correct; it was simply never exercised by the CI site |
| `plugins.json` | No plugin added, removed or re-classified; the registry and the generated regions stay as they are |

## 8. Content / data impact

- Records created / updated / deleted: **3 `event` posts created on the local CI
  database only** (one weekly series, one multi-day, one all-day), created by the
  real importer from the fixture ICS. Re-running creates 0 and updates 0.
- Portable identifier strategy: `_event_source = laois_tourism` plus the stable
  `_event_source_id` (the ICS `UID`), which is date-independent, so a later run
  upserts the same three records rather than creating new ones. No local
  post/attachment ID is used as cross-environment identity.
- This writes content: the six-step contract (§5.2) applies to **local** content.
  The importer's own dry-run is the dry-run step; `--apply` is the idempotent
  apply; step 9 is the numeric verify and it fails closed.
- PT content changed? The three records are new synthetic fixtures. **No existing
  PT record is modified.** Verified: the bootstrap reports `pt_changed=0` and
  `pt_drift=0`, and `created this run: 0` on the second run.

## 9. Route / HTTP impact

- Routes added, removed or changed: **none.** No rewrite, no redirect, no
  permalink change. The three events resolve on the existing `/eventos/<slug>/`
  route the CPT already registers.
- Redirects needed: none.
- Sitemap coverage: unchanged; these are ordinary published events covered by the
  existing event sitemap path.
- HTTP matrix rows to add or update: none in `tests/acceptance/matrices/`. The
  maintained `verify-event-occurrence-http.py` suite is rewritten in place; the
  other three acceptance suites are untouched.

## 10. Polylang / English impact

- Translated post types / taxonomies affected: none. `conexao_county` receives
  the **shared** `Laois` term (a shared proper name, never duplicated per
  language); `conexao_category` receives the existing `Cultura` term.
- Is PT immutable in this change? **Yes.** No PT record is edited.
- B1 / B2: the records are B2 like every other event; no EN record is created
  and no EN translation is authored. The EN archive renders them through the
  existing B2 fallback, unchanged.
- Canonical / hreflang / language switcher: untouched.
- Language-scoped cache keys: untouched. `Conexao_Event_Query::flush_cache()` is
  called after the import so the day-keyed transient reflects this run.
- Completeness gate: unchanged; it only covers B1 translated types, and the
  permanent `taxonomy_policy` gate still passes with the new shared terms.

## 11. Security impact


## 14. Test plan

- In-process suites: unchanged in count. `test-ics-occurrence-dates.php`
  (172 assertions) still proves the occurrence contract against the real captured
  Laois feed and must stay green. The new numeric occurrence proof for the CI
  fixture lives in `bootstrap-ci-fixtures.php --verify`, which evaluates **every**
  calendar day of the series with the real `Conexao_Event_Recurrence`.
- Acceptance: `verify-event-occurrence-http.py` rewritten — date-relative,
  structural discovery, no hard-coded title or date.
- Script contract: stage13 and stage14 must pass **both** with and without a
  local `dist/`, which is the condition CI actually runs in.
- Registry drift: `php scripts/generate-registry-docs.php --check` must stay
  green (`plugins.json` is untouched).
- A pass is: 81/81 PHP suites, 18/18 script-contract suites, 4/4 HTTP
  acceptance suites, 7/7 permanent gates, and `./scripts/run-tests.sh` exit 0.

## 15. Acceptance matrix plan

No matrix rows added. The rewritten suite asserts, for the discovered cards:

- **Series**: listed; the card advertises exactly ONE machine-readable date (not
  a range); that date is strictly in the future; it is the *next* occurrence
  (`badge - 7d < today <= badge`); the series-end chip is the last occurrence;
  exactly 2 further occurrences follow the badge; the gap is a whole number of
  weeks; the recurrence label names the weekday of the advertised occurrence; no
  `Hoje`/`Amanhã` chip on a non-occurrence day; detail page 200, self-canonical,
  own content, not a 410, no redirect loop.
- **Multi-day**: listed; advertises a full range; keeps its clock time; never
  labelled a weekly series; detail page 200 and canonical.
- **All-day**: listed; advertises its range; **no** clock time; never labelled a
  weekly series; detail page 200 and canonical.
- **Unaffected**: `/eventos/` 200 and pt-BR; `?county=laois` 200; unknown county
  200 with no cards; `?county=dublin` 200; unknown URL still 404; no redirect loop
  on `/eventos/` or `/en/eventos/`.


## 16. Rollback plan

In order:

1. `git revert` the five changed source files (the two `wp-content/` changes
   last, so the site is never left with a card template the data does not match).
2. Re-run `docker compose exec wordpress php scripts/bootstrap-ci-fixtures.php
   --apply` — the three fixture events remain in the local database; delete them
   by the recorded slugs (`evento-ci-serie-semanal-laois`,
   `evento-ci-multiday-laois`, `evento-ci-all-day-laois`) if a clean site is
   wanted. They are synthetic, local-only, and carry no production identity.
3. No rollback is needed for release metadata: **no** version, `plugins.json` or
   `dist/release.json` change was made, and the Stage 13/14 fixes change no
   recorded fact — only where that fact is read from.
4. Pre-change state captured for comparison: the `1746a1c` tree, the `event`-post
   inventory, and the stage13/stage14 `dist/`-absent reproduction in this §4.

## 17. Documentation plan

| Document | Change | Procedure moved to (skill) |
|---|---|---|
| `docs/reports/2026-10-02-stage-21-…-plan.md` | new (this file) | — |
| `docs/reports/2026-10-02-stage-21-….md` | new report with the measured numbers | — |
| `docs/reports/README.md` | register the report | — |
| `docs/testing.md` | note that the two script-contract gates build their own artifacts, so they are hermetic | — |
| `docs/plugins/conexao-event-importer.md` | note the CI ICS fixture step | — |

## 18. Release implications

- Component version bump(s): **none.** No plugin header version changes, so the
  Stage 13 version-agreement fact is unaffected by this work.
- Effect on the artifact allowlist: **none**; `plugins.json` is untouched and the
  Stage 14/registry gates stay green.
- Is a release in scope? **No.** No artifact is published by this task.

## 19. Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| The ICS fixture drifts from what the real Laois feed looks like, so the adapter is no longer genuinely exercised | low | the document reproduces the publisher's actual encoding (timed VEVENT, no `RRULE`, whole-week span, same weekday) and the audit asserts the resulting 4 occurrences numerically |
| The relative-date fixture masks a real "series expired" bug | low | the offsets are asserted, not assumed: `--verify` fails closed if the next occurrence is outside the window |
| A range-suppression change to the card breaks genuine multi-day events | low | the guard is `! conexao_event_is_recurring()`, so one-time events keep their range; the suite asserts the multi-day and all-day cards still render ranges |
| **Doing too much**: refactoring the recurrence engine or the importer while fixing CI | — | refused explicitly in §2; `wp-content/plugins/conexao-event-runtime` and the Stage 20 engine files are in the do-not-change list |
| The two gates become slower because they now build artifacts | low | they already built artifacts (the preflight double build, stage14 `--build`); both builds are a few seconds |

## 20. Verification gates

1. `CONEXAO_TEST_BASE_URL=http://localhost:8080 python3
   tests/acceptance/verify-event-occurrence-http.py` → `0 failed`.
2. `./scripts/run-tests.sh` → exit 0, with 81/81 PHP, 18/18 script-contract and
   4/4 HTTP acceptance suites.
3. `python3 scripts/verify-permanent-gates.py` → 7/7 `PASS`.
4. `./scripts/lint.sh` → no new violations, PHPStan 0 errors.
5. `php scripts/generate-registry-docs.php --check` → green.
6. Stage 13 and Stage 14 with `dist/` moved aside **and** in place → both exit 0.
7. The deterministic import run twice → run 2 `created=0 updated=0`, identical
   occurrence sets and no duplicate posts.
8. A negative proof: injecting a plugin header version mismatch still makes
   Stage 13 report `release_readiness` `BLOCKED`.

## 21. Completion criteria

Per `docs/engineering-standard.md` §14. The task is complete when
`verify-event-occurrence-http.py` passes completely, Stage 13 and Stage 14 pass,
all previously-green suites remain green, `./scripts/run-tests.sh` passes, the
permanent gates are 7/7, occurrence behaviour is correct for the recurring,
multi-day and all-day cases, all three detail pages return 200, repeated
deterministic runs are identical and idempotent, and production writes are 0.

_Last verified: 2026-10-02 by Stage 21_

`site-local today` is read from the HTTP `Date` header converted to
Europe/Dublin, the timezone `bootstrap-ci-fixtures.php` step 1e asserts, so the
suite never depends on the runner's clock.

- Capabilities checked: none added. The change adds no admin surface.
- Nonces added/changed: none.
- Sanitisation / escaping: the ICS document is a committed, static-shaped string
  built from constant offsets; the acceptance suite reads only rendered HTML.
- SQL: none; the bootstrap audit uses `get_posts()` / `get_post_meta()`.
- REST surface change: **none.** No endpoint was added — in particular no
  test-only date parameter on the archive, which would be a production surface
  with no user benefit.
- Secrets: none. The fixture URLs use the reserved `example.invalid` domain, no
  `ATTACH` line means no remote fetch, and no `.env` value is read.

## 12. Performance impact

- Queries added or removed: none on any request path. The import runs once, in
  the fixture step, not during page rendering. The card change removes no query.
- Caching: no new transient key. `Conexao_Event_Query::flush_cache()` is invoked
  once after the import, exactly as it already was for the synthetic events.
- Assets or images: none — deliberately no `ATTACH`, so no banner is sideloaded.
- Measurable improvement: none claimed; this is a correctness fix.

## 13. Production impact

| Question | Answer |
|---|---|
| Will anything be written to production? | **no** |
| Will a deploy, upload or activation happen? | **no** |
| Who performs it, and how? | nobody; the work is local Docker and the CI runner |
| What is the verification of the production result? | not applicable — production writes **0** |

| `docs/routing.md`, `wp-content/themes/…/inc/i18n/**` | No route and no i18n behaviour change |
| `wp-content/plugins/conexao-translation-*/**` | Untouched; the Stage 13 fix is in the preflight, not the engine (the engine digest is pinned and must not move) |
| `scripts/lib/commissioning.py` | Pinned engine digest; Stage 14 asserts it is unchanged |

- Measured on the local database: `event` posts = 29, and
  `SELECT … WHERE post_title LIKE '%Yoga%' OR '%Avenue%' OR '%Imposter%'`
  returns **zero rows**. `GET /eventos/chair-yoga-at-portlaoise-library/` → 404.
  `GET /eventos/?county=laois` → 0 `event-card` elements. That single fact
  explains all six HTTP failures; the three events are one shared root cause,
  not three independent defects.
- The importer **did** reach the network on a "deterministic" run: the option
  `conexao_event_sources[laois_tourism][ics_content]` is empty, and
  `Conexao_Event_Importer_Engine::run_source()` re-reads the source from the
  option, so the `ics_content` the Stage 20 runner injected into its local array
  was discarded. Confirmed by a direct probe: a handler built with `ics_content`
  set parses it and never touches the network.
- The HTTP suite hard-coded `CHAIR_TITLE`, `CHAIR_SLUG`, `CHAIR_SOURCE` and the
  literal badge `6-20` / `20 OUT`. Those are only correct while the site-local
  date is 2026-10-02; the archive is a `today .. today+7` window, so the
  assertions were a time bomb as well as a missing-data problem.
- Reproduced the Stage 13/14 failures exactly by moving the git-ignored `dist/`
  aside: stage13 → `803 passed, 3 failed` with the three reported messages;
  stage14 → `84 passed, 2 failed` with the two reported messages. Both pass with
  `dist/` present, which is precisely the signature of an environmental
  dependency on untracked build output.
