# Report — Stage 21: the CI site imports a real ICS, and the two gates stop depending on untracked `dist/`

| | |
|---|---|
| **Stage / task name** | Stage 21 — fix GitHub Actions run `37009756493` |
| **Date** | 2026-10-02 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `1746a1c285b6108f8ab822b8cca5d77b5ac24aca` |
| **Final SHA** | the commit is the operator's to make; the working tree holds exactly the files in §3 |
| **Working tree at finish** | dirty — the five source files and the documentation listed in §3, all intended |
| **Skills followed** | `wp-repository`, `wp-testing`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/testing.md`, `docs/routing.md`, `docs/plugins/conexao-event-importer.md`, `scripts/README.md` |
| **Plan** | [`2026-10-02-stage-21-ci-ics-fixture-and-hermetic-gates-plan.md`](2026-10-02-stage-21-ci-ics-fixture-and-hermetic-gates-plan.md) |
| **Evidence** | `docs/evidence/2026-10-02-stage-21/` |

## 1. Scope completed

All three red areas of run `37009756493` are fixed at their root cause.

- **`verify-event-occurrence-http.py` — 6 failures → 0.** Root cause: the
  deterministic CI site never imported an iCalendar document, so the three ICS
  events did not exist. They were *one* shared root cause, not three independent
  defects. A fixture step now drives the **real** importer over a real ICS
  document, and the suite was rewritten to be date-relative and structural.
- **Stage 13 script-contract — 3 failures → 0.** Root cause: an environmental
  dependency on the git-ignored `dist/release.json`. The version-agreement fact
  is now read from the release record the run's own build emits.
- **Stage 14 script-contract — 2 failures → 0.** Same dependency; the gate now
  builds and scans the full release set itself.

A **second, independent** event defect was found and fixed while validating:
the theme still rendered a weekly series as a continuous date range.

## 2. Scope NOT completed

- **No production verification.** Production writes are 0 and this stage makes no
  production claim. Every number below is from the local Docker stack or the
  host repository.
- **The per-date occurrence match is not proven over HTTP**, because the public
  archive is a `today .. today+7` list that deliberately accepts no
  caller-supplied date. Adding one purely for tests would be a production surface
  with no user benefit, so that half of the contract is proven where a date *can*
  be asked about: `bootstrap-ci-fixtures.php --verify` (every calendar day, real
  evaluator) and the plugin suite `test-ics-occurrence-dates.php` (real captured
  feed). The split is documented in the suite header.

## 3. Files added / modified / deleted

**1 added, 5 modified, 0 deleted.**

| Path | Change | Note |

## 4. Runtime impact

WordPress behaves differently in exactly one way. For a **weekly recurring
event**, the event card no longer renders the collapsed `DTSTART..DTEND` pair as
a continuous date range.

Before (measured on the local site, series start `2026-09-27`, last occurrence
`2026-10-18`, today `2026-10-02`):

```
event-card-date-day = 4-18
event-card-date-iso datetime = 2026-10-04/2026-10-18
```

After:

```
event-card-date-day = 4
event-card-date-iso datetime = 2026-10-04
event-card-recurrence = Toda domingo
event-card-recurrence-end = até 18 OUT
```

The old rendering claimed the event runs continuously from 4 Oct to 18 Oct —
precisely the pre-fix interpretation Stage 20 removed from the *matching* layer
but which was left behind in the *presentation* layer. Genuine multi-day and
all-day events are unaffected: their cards still render `3-5` and `5-8` ranges
and still carry their ISO ranges, because the new guard is
`! conexao_event_is_recurring()`.

The two script-contract gates now read their inputs from a build they perform
themselves rather than from untracked local state. That is a test-harness change,
not a product change.

## 4a. Scope integrity

| Check | Result |
|---|---|
| No production write, deploy, upload or activation | confirmed — production writes **0** |
| No production content, Polylang or database change | confirmed — all work against the local `wordpress-website` Docker stack |
| No mobile/Flutter repository accessed | confirmed — never opened |
| No `.env` or secret change | confirmed — `.env` untouched; the fixture uses the reserved `example.invalid` domain and has no `ATTACH` line, so nothing is fetched over the network |
| No `plugins.json` / registry change | confirmed — `generate-registry-docs.php --check` green |
| No i18n / Polylang behaviour change | confirmed — the permanent `taxonomy_policy` and `translation_completeness` gates are unchanged and green |

## 5. Content / data impact

Three `event` records, **local Docker only**, created by the real importer from
the fixture ICS (site-local today `2026-10-02`):

| Slug | `_event_date` | `_event_end_date` | `_event_recurrence` | `_event_source` |
|---|---|---|---|---|
| `evento-ci-serie-semanal-laois` | 2026-09-27 | 2026-10-18 | `weekly` | `laois_tourism` |
| `evento-ci-multiday-laois` | 2026-10-03 | 2026-10-05 | *(none)* | `laois_tourism` |
| `evento-ci-all-day-laois` | 2026-10-05 | 2026-10-08 | *(none)* | `laois_tourism` |

Match strategy: `_event_source = laois_tourism` + `_event_source_id` = the
date-independent ICS `UID`. No local post ID is used as cross-environment
identity. **No existing record was modified** — the bootstrap reports
`pt_changed=0`, `pt_drift=0`, `created this run: 0`, `repaired this run: 0`.

## 6. Polylang impact

PT immutability: **held**. No PT record was edited; the three new records are
synthetic fixtures. EN records created: **0**. The new events are B2 like every
other event and render on `/en/eventos/` through the existing fallback,
unchanged. The completeness gate covers B1 translated types only and is
unaffected: `translation_completeness` `PASS` with the same **293** allowlisted
entries as before. `conexao_county` received the **shared** `Laois` term — no
per-language duplicate — and `taxonomy_policy` is `PASS`.

## 7. Route / HTTP impact

Routes: **none added, removed or changed.** No rewrite, no redirect, no
canonical change. The three events resolve on the existing `/eventos/<slug>/`
route. Verified: all three detail URLs return **200** and are self-canonical
(asserted by the suite, not assumed).

## 9. Verification results

Every number below is copied from a command in `docs/evidence/2026-10-02-stage-21/`.

| Layer | Command | Result |
|---|---|---|
| PHP suites | `./scripts/run-tests.sh` | **81 total, 81 passed, 0 failed** |
| PHP assertions | same | **6305 passed, 0 failed** |
| Script-contract suites | same | **18 total, 18 passed, 0 failed** |
| HTTP acceptance suites | same | **4 total, 4 passed, 0 failed** — occurrence 49, guides-EN 20, release 44, routing 92 |
| Aggregate | same | `ALL TESTS PASSED` |
| Permanent gates | `python3 scripts/verify-permanent-gates.py` | **7 total, 7 passed, 0 failed**; 84 assertions; `AGGREGATE: PASS` |
| Lint / static | `./scripts/lint.sh` | `lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)`; PHPStan **0 errors** |
| Registry drift | `php scripts/generate-registry-docs.php --check` | green |
| Stage 13 (with `dist/`) | `verify-stage13-commissioning-readiness.py` | **808 passed, 0 failed**; 44 negative proofs proven, 0 missed |
| Stage 13 (**`dist/` absent**) | same, `dist/` moved aside | **808 passed, 0 failed** (`gates-without-dist.txt`) |
| Stage 14 (with `dist/`) | `verify-stage14-secret-scan.py` | **324 passed, 0 failed** |
| Stage 14 (**`dist/` absent**) | same, `dist/` moved aside | **324 passed, 0 failed** (`gates-without-dist.txt`) |
| The failing suite, run alone | `verify-event-occurrence-http.py` | **49 passed, 0 failed** |

**Reproduction of the reported failures, before the fix** (proving the
diagnosis, `dist/` moved aside): stage13 → `803 passed, 3 failed` with exactly
`release_readiness is BLOCKED in a clean tree: a plugin header version does not
match the release record`, `the release detail does not record the engine
digest`, `the release detail does not record both plugin versions`; stage14 →
`84 passed, 2 failed` with exactly `the dist/ directory carries built artifacts
to scan` and `the release record exists to be scanned`.

### Gates were not weakened

- Stage 14 still scans the **full release set** — `build-plugins-zip.sh` **and**
  `build-theme-zip.sh`, i.e. 9 plugin ZIPs + the theme ZIP + the release record —
  and keeps the "no test-only material ships" and secret-shape checks per entry.
  Its assertion count is unchanged at 324. A build failure is a hard failure.
- Stage 13's negative proofs are intact: **44 proven, 0 missed**. A live negative
  was also run — injecting a mismatched plugin header version still makes the
  gate report `release_readiness` `BLOCKED` and exit non-zero.

## 10. Determinism and idempotence

`bootstrap-ci-fixtures.php --apply` run twice after convergence, plus a
before/after state diff (`ics-state-before.tsv` vs `ics-state-after-two-runs.tsv`):

```
RUN 1  laois_tourism: found=3 created=0 updated=0 unchanged=3 skipped=0 errors=0
RUN 2  laois_tourism: found=3 created=0 updated=0 unchanged=3 skipped=0 errors=0
```

- Same create/update counts on repeat: **created 0, updated 0, unchanged 3**.
- Identical logical identities: **3** records, **3** distinct slugs, **3**
  distinct `UID`s — no duplicates.
- Identical occurrence sets: the state diff is **empty** across `_event_date`,
  `_event_end_date`, `_event_recurrence`, `_event_recurrence_start`,
  `_event_recurrence_end` and `_event_source_id`.
- No unrelated event changes: `event publish` went 29 → 32, exactly the three
  new fixtures; every other post type is unchanged (`post-inventory.tsv`).

## 11. Pre-existing vs newly introduced failures

- **Pre-existing (present at `1746a1c`, reproduced before any edit):** the two
  script-contract suites' dependence on untracked `dist/`. On a developer machine
  they pass; in CI they fail. **Not newly introduced by this stage.**
- **Newly introduced by this stage:** none. The first lint run did surface one
  new PHPCS violation (`Squiz.Commenting.BlockComment.NoEmptyLineBefore`) in my
  own new block comment; it was fixed and `./scripts/lint.sh` is now `OK`.
- **Environmental/tooling:** none outstanding. No verifier was unavailable, so no
  `PASS WITH LIMITATION` qualifier is needed anywhere in this report.

## 12. Rollback / recovery

See the plan §16. In short: revert the five source files (the `wp-content/` one
last), re-run the bootstrap, and optionally delete the three fixture events by
their recorded slugs. **No release metadata changed**, so no release rollback
exists or is needed: no plugin version, no `plugins.json` entry, no
`dist/release.json` content.

## 13. Why the fix is minimal

- The recurrence engine, the importer and the runtime plugin are **untouched** —
  Stage 20's work was correct and is proven correct; it simply was never
  exercised by the CI site.
- No second recurrence engine, no title-specific workaround, no fixture-only
  event system, and no global "every `DTSTART..DTEND` is a series" rule: the
  source-specific Laois interpretation stays exactly as isolated as it was.
- The two gate changes move **where a fact is read from**, not what is asserted:
  both keep every check, and Stage 14 now additionally cannot be satisfied by a
  stale local directory.
- The one `wp-content/` change is 3 lines of condition plus a comment, in the one
  template that reads `_event_end_date`.

## 14. Residual risk

The ICS fixture is a miniature, not a copy, of the real Laois feed. It reproduces
the publisher's actual encoding, and the exact-date contract against the *real*
captured feed remains covered by `test-ics-occurrence-dates.php`. A future change
to the publisher's convention would need that suite updated, exactly as before.

_Last verified: 2026-10-02 by Stage 21 — all three CI failures fixed at source;
81/81 PHP suites (6305 assertions), 18/18 script-contract, 4/4 HTTP acceptance,
7/7 permanent gates, lint OK; production writes 0_


## 8. Event occurrence behaviour, before and after

Measured with the real `Conexao_Event_Recurrence` evaluator by
`bootstrap-ci-fixtures.php --verify`.

| Case | Before this change | After |
|---|---|---|
| Recurring series in the CI site | **did not exist** (0 records) | 4 occurrences `2026-09-27, 2026-10-04, 2026-10-11, 2026-10-18`; **0** matches on the 8 neighbouring non-occurrence days probed; next occurrence from today `2026-10-04` |
| Recurring series as rendered | n/a | one date, not a range; weekly label; series-end chip |
| Multi-day event | **did not exist** | `2026-10-03 .. 2026-10-05`, one-time, range preserved, never a weekly series |
| All-day event | **did not exist** | `2026-10-05 .. 2026-10-08`, one-time, no clock time, never a weekly series |

The **real** captured Laois feed keeps its own exact-date proof, unchanged and
green, in `wp-content/plugins/conexao-event-importer/tests/test-ics-occurrence-dates.php`
(172 assertions): Chair Yoga is 4 occurrences on 2026-09-29, 10-06, 10-13,
10-20 and matches on no other day in that window.

|---|---|---|
| `scripts/data/ci-fixture-laois-ics.php` | **added** | Emits the deterministic iCalendar document (collapsed weekly series, multi-day, `VALUE=DATE` all-day) and the occurrence facts the audit asserts |
| `scripts/bootstrap-ci-fixtures.php` | modified | New **step 4c** runs the real importer over that document through `ics_content`; step 9 gains the numeric occurrence audit |
| `wp-content/themes/conexao-br-irlanda/template-parts/event-card.php` | modified | A weekly series no longer renders a continuous date range |
| `tests/acceptance/verify-event-occurrence-http.py` | modified (rewritten) | Date-relative, structural; no hard-coded title, slug or calendar date |
| `scripts/verify-commissioning-readiness.py` | modified | Version agreement read from the record the run's own build emits |
| `tests/scripts/verify-stage14-secret-scan.py` | modified | Builds and scans the full release set instead of reading untracked `dist/` |
| plan, this report, `docs/reports/README.md`, `docs/testing.md`, `docs/evidence/README.md`, `docs/evidence/2026-10-02-stage-21/` | added / modified | Plan, report, index, test-model note, machine evidence |
| `docs/evidence/2026-09-26-stage-l/gate.json` | modified | Regenerated by running `verify-permanent-gates.py`, as designed. The committed copy was stale (recorded `status: "fail"` at `1e09ee2`); it now records `status: "pass"` at `1746a1c`. |

`wp-content/` changes: **one file**, `template-parts/event-card.php`, and it is
the only behavioural change to the product. The recurrence engine, the importer
and the runtime plugin are **untouched**.
