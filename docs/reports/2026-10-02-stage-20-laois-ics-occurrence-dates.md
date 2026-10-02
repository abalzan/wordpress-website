# Report — Stage 20: Laois ICS occurrence dates

| | |
|---|---|
| **Stage / task name** | Stage 20 — Laois Tourism ICS occurrence dates (recurring series vs. date range) |
| **Date** | 2026-10-02 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Start SHA** | `848e00ee29173dda1ab1c41c1181cec798641197` |
| **Working tree at finish** | dirty only with the files listed in §10 plus pre-existing unrelated modifications |
| **Skills followed** | `wp-content-change`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-update-docs` |
| **Authoritative docs consulted** | `AGENTS.md`, `docs/engineering-standard.md`, `docs/content-model.md`, `docs/plugins/conexao-event-importer.md`, `docs/plugins/conexao-event-runtime.md`, `docs/testing.md`, `docs/templates/plan.md`, `docs/templates/report.md` |
| **Production writes** | **0** — local Docker only; no production endpoint, credential or database was contacted |

Plan: [`2026-10-02-stage-20-laois-ics-occurrence-dates-plan.md`](2026-10-02-stage-20-laois-ics-occurrence-dates-plan.md).
Evidence: [`../evidence/2026-10-02-stage-20/`](../evidence/2026-10-02-stage-20/).

## 1. Root cause

`Chair Yoga At Portlaoise Library` was visible on 2 October although it is a
weekly class with four occurrence dates.

The Laois Tourism feed **publishes no `RRULE` at all** — measured over the
whole live document: **30 VEVENTs, 0 RRULE, 0 EXDATE, 0 RDATE, 0 `X-`
recurrence properties**. A multi-week course is encoded as a **single VEVENT**
whose

```
DTSTART = first occurrence
DTEND   = end of the LAST occurrence      (NOT start + duration)
```

`Conexao_Source_ICalendar::parse_vevent()` copied that pair verbatim into
`start_date` / `end_date`, the importer stored it as `_event_date` /
`_event_end_date`, and the pre-existing evaluator
`Conexao_Event_Recurrence::one_time_occurs_on_date()` treats an inclusive
`[start, end]` range as continuous availability:

```php
return $event_date <= $local_date && $local_date <= $end_date;
```

So a 21-day bounding range made the event "occur" on all 21 days.

This is a **pre-existing design limitation, not a regression**: the recurrence
model (`_event_recurrence*`), the evaluator and the archive query all existed
and worked, but nothing ever populated that metadata from an import. The Stage
7 report records the same pattern from the other direction — a correct query
fed by absent data.

## 2. What the source actually says

| Property | Measured value |
|---|---|
| Feed | `https://laoistourism.ie/events/?ical=1` |
| PRODID | `-//Laois Tourism - ECPv6.18.0//NONSGML v1.0//EN` |
| VEVENTs | 30 |
| `RRULE` / `EXDATE` / `RDATE` / `X-*` recurrence | **0 / 0 / 0 / 0** |
| Configured variant `photo/?hide_subsequent_recurrences=1&ical=1` | **byte-identical** document (md5 `121dc81516e413a2ee1328b9f8c2ddf0`) |

The five multi-week programmes and what the source itself says about them:

| Event | DTSTART | DTEND | Span | Occ. | Source DESCRIPTION |
|---|---|---|---|---|---|
| Chair Yoga At Portlaoise Library | 29 Sep 14:00 | 20 Oct 15:00 | 3 wk + 1 h | **4** | "a **four-week** Chair Yoga programme" |
| Pilates Programme with Sabrina | 22 Sep 20:00 | 27 Oct 21:00 | 5 wk + 1 h | **6** | "a welcoming **6-week** Pilates programme" |
| Bootcamp with Malone Fitness | 21 Sep 19:00 | 26 Oct 20:00 | 5 wk + 1 h | **6** | "a supportive **6-week** programme" |
| Beginners Crochet Classes | 14 Sep 10:30 | 19 Oct 12:30 | 5 wk + 2 h | **6** | "our **6-week** Beginners Crochet Course" |
| What Were You Thinking Of Darling? | 7 Sep 10:30 | 19 Oct 12:30 | 6 wk + 2 h | **7** | "a **seven-week** visual art workshop series" |

The counts the structural rule produces are the counts the publisher states in
prose. That is the independent corroboration that the interpretation matches
the publisher's intent — the code never reads a title (asserted in §8).

Every other event in the feed is single-day or a short genuine multi-day event;
the only other multi-week event is all-day `VALUE=DATE` (`Imposter Art
Exhibition`, 2–31 Oct), excluded by the all-day test.

## 3. How the calendar-compatible interpretation was established

Without assuming anything about calendar applications: the same bytes read under
RFC 5545 yield a single VEVENT, so the only defensible calendar-compatible
reading is the one the source's own description states. The rule is therefore
## 4. The fix

**ICS/source event → resolve occurrence dates → store recurrence window → the
existing date layer matches only those dates.**

1. `includes/class-conexao-ics-recurrence.php` — generic RFC 5545 `RRULE`
   reader (`FREQ=WEEKLY`, `INTERVAL=1`, `BYDAY`, `COUNT`, `UNTIL`). Anything
   outside the representable subset returns null and the event stays one-time.
2. `includes/class-conexao-laois-tourism-series.php` — the **source-specific**
   collapsed-series detector, registered for `laois_tourism` only.
3. `Conexao_Event_Importer_Engine::save_event_recurrence_meta()` — persists the
   four `_event_recurrence*` keys the data model **already had**.

`Conexao_Event_Recurrence` and `Conexao_Event_Query` are **untouched**: the
runtime remains the single evaluator of "occurs on date X". No second
recurrence engine, no occurrence posts, no new meta keys.

### Why source-scoped rather than global

Standard ICS `DTEND` must keep its standard meaning. Applying "DTEND is the
last occurrence" universally would silently destroy genuine multi-day events.
The adapter registry is keyed by source id, and the standard `RRULE` path is
always consulted **first**, so a feed publishing real `RRULE`s is untouched.

### Discrimination between the five shapes

| Shape | Detected as | Why |
|---|---|---|
| Genuine multi-day event | one-time | fails the whole-week/weekday/session-delta test (fixture: `Avenue Q Hit Musical`) |
| Weekly course | series | all four conditions hold |
| Explicit `RRULE` | series | standard path wins before the adapter |
| Start/end merely spanning days | one-time | span is not a multiple of 7 |
| All-day `VALUE=DATE` | one-time | excluded outright by the all-day test |

## 5. Before / after, measured

Measured on the real local database by scanning **every** calendar day of the
window with the real runtime evaluator
([`verify.json`](../evidence/2026-10-02-stage-20/verify.json)):

| Condition | Before | After |
|---|---|---|
| Chair Yoga occurrences recognised | 22 (whole range) | **4** |
| `2026-09-29` | matches | **matches** |
| `2026-10-02` | **matches (wrong)** | **no** |
| `2026-10-05` | **matches (wrong)** | **no** |
| `2026-10-06` | matches | **matches** |
| `2026-10-07` | **matches (wrong)** | **no** |
| `2026-10-12` | **matches (wrong)** | **no** |
| `2026-10-13` | matches | **matches** |
| `2026-10-14` | **matches (wrong)** | **no** |
| `2026-10-20` | matches | **matches** |
| Next occurrence from 2 Oct | 2 Oct | **6 Oct** |
| Laois events stored as weekly series | 0 | **5** (exactly the multi-week programmes) |

Actual output:

```
Chair Yoga record: #1346 2026-09-29..2026-10-20 days=2 rec=weekly window=2026-09-29..2026-10-20
## 6. Date/time correctness (TZID and DST)

The source declares `TZID=Europe/Dublin` and the site runs `Europe/Dublin`.
Every date computation in the new code is **calendar-based** (UTC-anchored
`strtotime`/`gmdate` on a `Y-m-d` string), never a site-timezone instant
conversion, so no value can drift across a day boundary.

`Pilates Programme with Sabrina` runs 22 Sep → 27 Oct 2026, straight across the
**25 Oct 01:00 DST end**. Asserted in the in-process suite: it occurs on 22 Sep,
6 Oct, 20 Oct and **27 Oct** (after the transition) and not on 1, 7, 21 or 26
Oct. `Chair Yoga` keeps `14:00` / `15:00` exactly as the source declares.

## 7. Idempotence (measured)

| Run | found | created | updated | unchanged | dup | skipped | failed | errors |
|---|---|---|---|---|---|---|---|---|
| 1 | 30 | **30** | 0 | 0 | 0 | 0 | 0 | 0 |
| 2 | 30 | **0** | **0** | **30** | 0 | 0 | 0 | 0 |

The second run produced **zero creates and zero updates**; all 30 events
reported `outcome: "unchanged", severity: "success"`. Additionally asserted in
the in-process suite: three consecutive writes of the same rule leave the
stored meta byte-identical, leave **exactly one** `postmeta` row per key, and
clearing a rule removes all four keys.

This required one non-obvious change: the rule participates in **change
detection**. Without it, an event imported before this fix would compare as
"unchanged" forever and never receive its rule — the fix would only ever apply
to newly created events.

## 8. Regression coverage (172 assertions, all passing)

`wp-content/plugins/conexao-event-importer/tests/test-ics-occurrence-dates.php`,
run against the **committed real-world fixture**
(`tests/fixtures/ics/laois-tourism.ics`):

| § | Coverage |
|---|---|
| 1–2 | classes load; fixture has **zero** RRULE/EXDATE/RDATE; 30 events parse |
| 3 | **Chair Yoga: exactly 4 occurrences**; all 4 dates match; **all 18 in-range non-occurrence dates** (incl. 2, 5, 7, 12, 14 Oct) do not; `next_occurrence` correct |
| 4 | the other **4 multi-week programmes** round-trip through the real writer and evaluator |
| 5 | genuine multi-day event still covers its whole range; all-day `VALUE=DATE` events not turned into series; single-day event not recurring |
| 6 | explicit `RRULE` (`COUNT`, `UNTIL`) works; `FREQ=DAILY` and `INTERVAL=2` safely refused |
| 7 | rule is **source-scoped**; adapter rejects all-day / different weekdays / non-whole-week / zero-length / reversed time / missing time, with a positive control |
| 8 | DST: a series spanning the 25 Oct transition keeps its dates |
| 9 | idempotence (3 writes, row counts, clearing) |
| 10 | change detection sees the rule and is weekday-order insensitive |
| 11 | the archive query layer uses occurrence dates; scanning every date in the window matches exactly the generated set |

No test reads an event **title** to decide behaviour.

## 9. HTTP acceptance (36 assertions, all passing)

`tests/acceptance/verify-event-occurrence-http.py`, against the local stack:

- `/eventos/` 200, served as `pt-BR`; 30 Laois cards discovered across pages.
## 10. Determinism (two clean runs)

| Measure | Run A | Run B |
|---|---|---|
| Baseline digest (pre-import) | `9af92ef8…145b3` | `9af92ef8…145b3` ✅ identical |
| run 1 / run 2 counters | 30 created / 0 created | 30 created / 0 created ✅ |
| Laois events | 30 | 30 ✅ |
| Weekly series | 5 | 5 ✅ |
| Series identity + window | see §2 | identical ✅ |
| Post-import snapshot digest | `4310939d…56108` | differs **only** by auto-increment post IDs (purged in between) |
| ID-stripped content digest | `63977f45…b27662` | `63977f45…b27662` ✅ identical |

Both runs were executed after a full purge of the Laois rows, so the
comparison is genuinely independent. Full detail:
[`determinism.md`](../evidence/2026-10-02-stage-20/determinism.md).

## 11. Tests and gates — actual counts

| Layer | Result |
|---|---|
| In-process PHP | **81 suites / 81 passed, 0 failed** — **6,305 assertions**, 0 failed (baseline 80 suites / 6,133) |
| Script-contract | **18 total / 17 passed / 1 failed** |
| HTTP acceptance | **4 suites / 4 passed, 0 failed** (3 pre-existing + 1 new, 36 assertions) |
| `./scripts/lint.sh` | **OK** — syntax clean, **no new PHPCS violations** (baseline debt 3290/2672 → 2841/2183), **PHPStan 0 errors** |

### Pre-existing failures (NOT introduced by this change)

| Failure | Status |
|---|---|
| `tests/scripts/verify-i18n-freshness.py` — 3 stale catalogues | **Pre-existing.** Present in the baseline run before any edit. The gate itself classifies **2 as pre-existing**; the third was the importer catalogue, cleared by regenerating with the supported `./scripts/i18n-make-pot.sh` (never hand-edited). Because the gate reads **git commit time**, it reports stale until the regenerated catalogues are committed. |
| `tests/scripts/verify-release-integrity.py` — non-deterministic zip build | **Pre-existing and flaky.** Failed on the baseline run, passed on every subsequent run (`224 passed, 0 failed`). Not related to this change. |
| MySQL `Duplicate entry … wp_term_relationships` during import | **Pre-existing bug**, not caused by this change: `save_event_taxonomies()` was already invoked on the "unchanged" fast path at the baseline SHA (`git show HEAD:…` confirms) and that function is untouched here. It does not affect event data or any assertion. **Not fixed** — out of scope, flagged in §14. |

### Newly introduced failures

**None.**

## 12. Runtime impact

WordPress behaves differently in exactly one way: **an imported ICS series now
stores its recurrence window**, so the archive, the event card date badge, the
"Hoeje/Amanhã" chips and the ordering all follow real occurrence dates. Nothing
in the runtime plugin, the theme, routing, canonical URLs, hreflang, i18n or
translations changed. PT remains canonical; EN translations keep sharing the
same identity meta.

## 13. Files added / modified / deleted

**5 added, 7 modified, 0 deleted.**

| Path | Change | Note |
|---|---|---|
| `…/conexao-event-importer/includes/class-conexao-ics-recurrence.php` | **added** | generic RFC 5545 RRULE reader |
| `…/conexao-event-importer/includes/class-conexao-laois-tourism-series.php` | **added** | source-specific collapsed-series rule |
| `…/conexao-event-importer/tests/test-ics-occurrence-dates.php` | **added** | 172-assertion in-process suite |
| `…/conexao-event-importer/tests/stage20-laois-import-run.php` | **added** | deterministic baseline/dry-run/apply/verify runner |
## 14. Limitations

1. **`FREQ=DAILY`, `INTERVAL>1` and ordinal `BYDAY` are not represented.** They
   degrade to a one-time event rather than being approximated. The data model
   expresses weekly-with-weekdays only; extending it would be a separate,
   deliberate change.
2. **The Laois rule is a heuristic over a feed that publishes no
   machine-readable recurrence signal.** It is validated against every event
   currently in the feed and against the description counts, but it is still a
   convention. If the publisher ever adds real `RRULE`s, the standard path takes
   precedence and the adapter stops firing.
3. **A genuine timed multi-day event that spans an exact whole number of weeks,
   starts and ends on the same weekday, and whose end time is later in the day
   than its start time would be misread as a series.** No such event exists in
   the current feed, and the rule is scoped to one source. A fully robust
   solution needs a recurrence signal from the publisher.
4. **`EXDATE` / `RDATE` are not honoured** — absent from this feed; supporting
   them would be new behaviour rather than a fix.
5. The `wp_term_relationships` duplicate-entry error on the importer's
   "unchanged" fast path (§11) is **pre-existing and left unfixed**.
6. The i18n-freshness gate is git-time based, so it stays red until the
   regenerated catalogues are committed.

## 15. Rollback / recovery

Revert the commit. **No schema change and no new meta key** were introduced: the
four `_event_recurrence*` keys are written by the importer and **deleted** again
when a source stops declaring a series, so reverting restores the previous
DTSTART/DTEND-only behaviour with no data surgery. Local recovery from a bad
run is `DELETE` the Laois rows and re-run the importer (demonstrated twice in
§10). No production rollback is needed because no production write occurred.

## 16. Acceptance criteria

| # | Criterion | Status |
|---|---|---|
| 1 | Chair Yoga no longer appears on non-occurrence dates | ✅ 18 in-range dates excluded |
| 2 | Chair Yoga appears on all four occurrence dates | ✅ 29 Sep, 6, 13, 20 Oct |
| 3 | Other Laois recurring events behave correctly | ✅ 4 more series, consistent counts |
| 4 | Genuine multi-day events not broken | ✅ asserted + HTTP |
| 5 | Explicit ICS `RRULE` events continue to work | ✅ `COUNT`/`UNTIL`; non-representable refused safely |
| 6 | All-day events continue to work | ✅ `VALUE=DATE` untouched |
| 7 | Re-import is idempotent | ✅ run 2 = 0 created / 0 updated |
| 8 | No unrelated events modified | ✅ only the 5 qualifying series got recurrence meta |
| 9 | Archive/date filtering uses occurrence dates | ✅ verified against the real DB and over HTTP |
| 10 | HTTP acceptance passes with numeric evidence | ✅ 36 passed, 0 failed |
| 11 | Full tests/gates pass, pre-existing separated | ✅ 81/81 in-process, 4/4 HTTP, lint OK; 1 pre-existing gate failure stated |
| 12 | No production writes | ✅ 0 |
| `…/conexao-event-importer/tests/fixtures/ics/laois-tourism.ics` | **added** | captured real feed (md5 `121dc815…`) |
| `tests/acceptance/verify-event-occurrence-http.py` | **added** | HTTP acceptance (36 assertions) |
| `…/includes/sources/class-icalendar-source.php` | modified | `all_day` flag + recurrence resolution |
| `…/includes/class-event-importer.php` | modified | persist/reconcile recurrence + change detection |
| `…/includes/class-event-normalizer.php` | modified | `recurrence` passthrough |
| `…/includes/class-event-export.php` / `class-event-import.php` | modified | recurrence keys in both allowlists |
| `…/conexao-event-importer.php` | modified | require the two new classes |
| `docs/plugins/conexao-event-importer.md` | modified | new "Recurrence / occurrence dates" section |
| 9 × `languages/*.pot` | modified | regenerated with `scripts/i18n-make-pot.sh` |
- **Chair Yoga card date badge is `6-20` / `OUT`** — the next occurrence, not
  the series start (`29-09` absent) and not "today"; carries the weekly label
  "Toda terça-feira" and the series end "20 OUT"; is **not** labelled "Hoje";
  still links to its original source page.
- `Avenue Q Hit Musical` (genuine multi-day) and `Imposter Art Exhibition`
  (all-day) are **not** labelled as weekly series.
- Detail pages for all three: **200**, self-canonical, own content, **no 410**.
- `/eventos/?county=laois` 200; unknown county filter 200 with **no cards**
  (no filter regression); `/eventos/?county=dublin` 200; unknown URL still 404.
- **No redirect loop** for `/eventos/`, the event single, or `/en/eventos/`.

**Why no per-date HTTP row exists**: the public archive is a "today + 7-day
window" list and accepts no caller-supplied date. Adding such a parameter purely
for testing would be a production surface with no user benefit. The per-date
matching is therefore proven exhaustively in the in-process suite and against
the real database (§5), and the HTTP layer proves the rendered occurrence
dates and that nothing else regressed.
stored occurrence dates (4): 2026-09-29, 2026-10-06, 2026-10-13, 2026-10-20
MATCH (4): 2026-09-29, 2026-10-06, 2026-10-13, 2026-10-20
NO MATCH (18): 2026-09-30, 2026-10-01, 2026-10-02, 2026-10-03, 2026-10-04, 2026-10-05,
               2026-10-07, 2026-10-08, 2026-10-09, 2026-10-10, 2026-10-11, 2026-10-12,
               2026-10-14, 2026-10-15, 2026-10-16, 2026-10-17, 2026-10-18, 2026-10-19
verdict: PASS
```
stated as **the publisher's encoding convention**, derived from structure
(weekday + whole-week span + single-session time remainder) and validated
against the description counts — not as a guess about any client.