# CONEXÃO BR IRLANDA — Laois Events Visibility Report (Stage 7.x)

| | |
|---|---|
| **Subject** | 30 named Laois Events reported as "not appearing on /eventos" |
| **Date** | 2026-09-26 |
| **Branch** | `i18n` |
| **Baseline SHA** | `7778d7644aba94eca7d3717e569d98afbf3cf15a` |
| **Plan** | [`2026-09-26-stage7-laois-events-visibility-plan.md`](2026-09-26-stage7-laois-events-visibility-plan.md) |
| **Final classification** | **LAOIS EVENTS VISIBILITY — PASS WITH LIMITATION** |

## 1. Summary

The 30 events were **never missing from the database and never missing from
`/eventos`**. They were missing from **`/eventos?county=laois`** — the county
filter, which is how a user looks for "Laois events".

The cause was a **configuration value, not a query**: the stored
`conexao_event_sources` option held `laois_tourism.county = ''` (empty) while
the shipped default declares `'Laois'`. The iCalendar handler only forwards the
hint when it is non-empty, so **no Laois Tourism event ever received a
`conexao_county` term**, and the archive's county filter matched none of them.

Two code defects let that persist, and both are fixed:

1. `Conexao_Event_Sources::get_all()` only ever **added missing** sources; it
   never repaired a drifted field, so the shipped county was lost permanently.
2. `upsert_event()`'s `"unchanged"` fast path returned **before**
   `save_event_taxonomies()`, so even a corrected configuration could never
   backfill the term onto an already-imported event.

The limitation: **29 of the 30 events do not exist on production at all**
(measured, §8), and no production write was authorised, so nothing was deployed.

## 2. ROOT CAUSE

**Observed symptom:** the 30 Laois events did not appear when looking for Laois
events on `/eventos`.

**Measured evidence (before the fix):**

| Condition | Result |
|---|---|
| `post_status` = `publish` | 30/30 PASS |
| `_event_status` = `published` | 30/30 PASS |
| Date window (`_event_date`/`_event_end_date` ≥ today) | 30/30 PASS |
| SQL candidate stage `candidate_event_ids()` | 30/30 PASS |
| PHP recurrence stage `next_occurrence()` | 30/30 PASS |
| Language gate `is_in_current_language()` | 30/30 PASS (all PT) |
| Present in the plain archive ordering | **30/30** (ranks 124–315) |
| `conexao_county` term present | **0/30** |
| Reachable via `/eventos/?county=laois` | **0/30** (20 results, all Eventbrite) |

**Why the 30 Laois events existed:** they were imported normally by the
`laois_tourism` source (iCalendar feed) between 2026-03-16 and 2026-09-23. The
records are valid on every field the query inspects.

**Why `/eventos` excluded them (from the county filter):** the source's
`county` hint was empty, so `save_event_taxonomies()` had nothing to assign and
no `conexao_county` term was ever written. The archive's county branch adds

```php
$tax_query[] = array( 'taxonomy' => 'conexao_county', 'field' => 'slug', 'terms' => $county );
```

so a post with no county term cannot match `?county=laois`.

**Why other events still appeared:** every `eventbrite_<county>` source carries a
non-empty `county` in the stored option, so those events got the term. The 34
older `laois_tourism` events that *do* carry the term are all
`_event_status = source_not_found`, so the runtime's status gate removes them —
which is why the filter showed 20 events, not 54.

**Exact code/data condition responsible:**

```
conexao_event_sources[laois_tourism].county = ''      (data — the shipped default is 'Laois')
  → Conexao_Source_ICalendar::parse_vevent()  : if ( ! empty( $this->config['county'] ) ) $event['county'] = ...
  → Conexao_Event_Importer::save_event_taxonomies() : if ( ! empty( $normalized['county'] ) ) ... skipped
  → no conexao_county term
  → /eventos?county=laois tax_query cannot match
```

Two persistence bugs kept it alive:

- `Conexao_Event_Sources::get_all()` merged only *missing* sources, never
  repairing a drifted `county`.

## 3. What was NOT changed (and why)

| Path | Why untouched |
|---|---|
| `inc/archive-query.php` | The archive query is correct; the data was wrong. |
| `conexao-event-runtime/**` | The status/date/language gates are correct. |
| `sources/class-icalendar-source.php` | The county-hint contract is correct; the stored *value* was wrong. |
| `inc/i18n/rest-language.php` | No REST regression was found. |
| Pagination / ordering | Ordering is by next occurrence; ranks 124–315 are correct, not a defect. |

## 4. Before / after (measured)

| Metric | Before | After |
|---|---|---|
| Named events found in the local database | 30/30 | 30/30 |
| `conexao_county` term on the 30 | 0 | **30 (`laois`, term 1373)** |
| Targets in `/eventos/?county=laois` | **0/30** | **30/30** |
| `?county=laois` result set size | 20 | 50 |
| `laois_tourism` effective county hint | `''` | `'Laois'` |
| Upcoming set / archive pages | 1380 / 138 | 1380 / 138 (unchanged) |
| Plain `/eventos` page-1 targets | 0 | 0 (unchanged, by design) |
| Other-county filters (dublin/kerry/cork/donegal) | 200, no targets | 200, no targets |
| Single event URLs (followed to canonical) | 30/30 → 200 | 30/30 → 200 |

HTTP evidence: [`stage7-work/http-before.md`](../../stage7-work/http-before.md)
and [`stage7-work/http-after.md`](../../stage7-work/http-after.md) (+ `.json`).

The county filter now returns the targets across pages 1–4:

| Page | Cards | Target posts |
|---|---|---|
| `?county=laois` p1 | 10 | 8 |
| `?county=laois` p2 | 10 | 10 |
| `?county=laois` p3 | 10 | 9 |
| `?county=laois` p4 | 10 | 3 |
| **Total** | | **30/30** |

## 5. Identity / data integrity (Phase 18, 19)

A field-by-field diff of `stage7-work/laois-events-before.json` against
`laois-events-after.json` over 20 tracked fields:

```
records compared      : 30
FIELDS THAT CHANGED   : NONE   (outside county)
uuid preserved        : True   (30 distinct)
source preserved      : True
source_id preserved   : True   (30 distinct, 0 shared)
archive rank preserved: True   (ordering untouched)
```

| Gate | Result |
|---|---|
| Duplicate Events created | **0** |
| Source IDs changed | **0** |
| UUIDs changed | **0** |
| Existing events deleted/recreated | **0** |
| New `conexao_county` terms created | **0** (still exactly one `laois`, term 1373) |
| PT Event content/slug/date/status changed | **0** |
| Records modified | 30 (one additive term each) + 1 Donegal event (see §6) |

## 6. Scope note — one extra event

The repair script is rule-driven, not ID-driven, so it also corrected **one
`eventbrite_donegal` event** (ID 18706) that lacked its Donegal term for the
same reason: 31 assignments in total. That is the intended behaviour — the fix
operates on the data rule, so it corrects every record the rule covers.

## 7. Future-event regression (Phase 17)

`stage7-work/stage7-future-proof.php` proves a **future** Laois Tourism event
flows through automatically, with no per-ID handling:

```
PASS laois_tourism county = 'Laois'                       (effective config)

## 8. Production status (measured, read-only)

| Question | Answer |
|---|---|
| Was anything written to production? | **No** |
| Was anything deployed? | **No** |
| Do the 30 events exist on production? | **29 of 30 do not** |

Measured against `https://conexaobr.ie` (1779 events, full REST enumeration,
exact normalised-title match): **1/30 present** — "Mabon & Full Moon Day
Retreat" (prod ID 11553). The other 29 are absent from production entirely, so
the reported production symptom is *absence of the records*, not a query defect.
Both code fixes live in `conexao-event-importer`, which is `production: no` in
`plugins.json` and is in no release ZIP, so they cannot affect production.

**To make Laois Tourism events visible on production**, an authorised operator
must (a) re-run the `laois_tourism` import locally with the repaired
configuration, (b) export, and (c) import the JSON through the production admin.
That is a content change and is **out of scope here** — no production write was
authorised.

## 9. Independent data-integrity finding (not the visibility cause)

Every meta row of the 30 posts is **duplicated** (25 duplicated keys per post;
`post_id 276` likewise has a duplicated `_wp_old_slug`). This does **not** affect
listing — `get_post_meta()` returns the first row and the candidate SQL uses
`SELECT DISTINCT p.ID`, which absorbs the join fan-out. It is a real data-hygiene
defect, recorded here and deliberately **not** fixed in this stage (it is not the
root cause, and rewriting meta on 30 PT records is a separate content change).

Two of the 30 are also duplicate *titles* of existing records (100003 vs 10504,
100004 vs 11065) and 100011 duplicates 13310 — consistent with a re-import that
ran before the source configuration was correct.

## 10. Verification gates

## 11. Final metrics

| Metric | Value |
|---|---|
| Named Laois events investigated | 30 |
| Named events found in the local database | **30** |
| Named events missing from the local database | **0** |
| Named events found on production | **1 / 30** |
| Named events publicly reachable individually (local) | **30 / 30** (canonical `/eventos/<slug>`, HTTP 200) |
| Named events appearing on `/eventos` before | **0** on page 1; **30** present in the archive at pages 13–32 |
| Named events appearing on `/eventos?county=laois` before | **0 / 30** |
| Named events appearing on `/eventos?county=laois` after | **30 / 30** |
| Events excluded by status | **0** |
| Events excluded by date | **0** |
| Events excluded by taxonomy | **30** (missing `conexao_county` term) — the root cause |
| Events excluded by language | **0** |
| Events excluded by another query condition | **0** |
| Events affected by cache | **0** (transient flushed after the repair) |
| Duplicate Events created | **0** |
| Source IDs changed | **0** |
| UUIDs changed | **0** |
| New county terms created | **0** |
| PT Event content changes | **0** (only an additive shared county term) |
| Other-county regressions | **0** (dublin/kerry/cork/donegal: 200, 0 targets) |
| Eventbrite regressions | **0**; 1 Donegal event *improved* |
| Heritage Week regressions | **0** (no `heritage_week_*` event matched the rule) |
| Laois Tourism regressions | **0** — 30 repaired |
| HTTP checks passed | **40 / 41** |
| HTTP checks failed | **1** — `/eventos/?county=laois&paged=6` → 404, the expected out-of-range page |
| Automated tests passed | new suite **18/18**; script contract **6/6**; permanent gates **7/7** (86 assertions) |
| Pre-existing unrelated failures | **3** (proven identical on the reverted baseline) |
| Production deployment | **None** (not authorised; both fixes are in a `production: no` plugin) |
| **Final classification** | **LAOIS EVENTS VISIBILITY — PASS WITH LIMITATION** |

The limitation is the production side only: the code fix and its validation are
complete and measured locally, but 29 of the 30 records do not exist on
production and no production content write was authorised in this task.

## 12. Rollback

- **Code:** `git checkout` the two plugin files.
- **Data:** one additive term assignment per record. Reverse with
  `wp_remove_object_terms( $id, array( 1373 ), 'conexao_county' )` for the IDs
  listed in `stage7-work/repair-plan.json` (31 records), using
  `stage7-work/terms-backup-before-repair.sql` as the pre-change term state. No
  content, meta, status, slug, date or identity field is involved, so nothing
  else needs reversing.

## 13. Artifacts

| File | Contents |
|---|---|
| `stage7-work/laois-events-visibility-audit.json` / `.md` | per-event stage-by-stage verdicts and identity fields |
| `stage7-work/laois-events-before.json` / `-after.json` | full before/after snapshots (20 fields × 30 events) |
| `stage7-work/http-before.md` / `.json` | BEFORE HTTP evidence (0/30 in the county filter) |
| `stage7-work/http-after.md` / `.json` | AFTER HTTP evidence (30/30) |
| `stage7-work/repair-plan.json` | the dry-run plan, including identity fields and the reversal list |
| `stage7-work/terms-backup-before-repair.sql` | pre-change term relationships |
| `stage7-work/stage7-future-proof.php` | the Phase 17 future-event proof |
| `stage7-work/stage7-snapshot.php`, `stage7-audit.php` | the measurement tooling |

---

## ROOT CAUSE:

The stored `conexao_event_sources` option held `laois_tourism.county = ''` while
the shipped default declares `'Laois'`. `Conexao_Source_ICalendar` only forwards
the hint when it is non-empty, so `save_event_taxonomies()` never assigned a
`conexao_county` term and the archive's `?county=laois` tax query matched none of
the 30 events. Two bugs kept it alive: `get_all()` never repaired a drifted
field, and the `"unchanged"` import fast path returned before
`save_event_taxonomies()`.

## FIX APPLIED:

`get_all()` now restores a shipped default county when the stored value is empty
(never overwriting a non-empty one); the `"unchanged"` fast path now reconciles
taxonomies; and `scripts/repair-event-county-terms.php` (dry-run first,
idempotent) assigned the **existing** shared `conexao_county/laois` term (1373)
to the 30 records plus one Donegal event. 31 assignments, 0 new terms.

## WHY THE 30 EVENTS WERE INVISIBLE:

They were valid all along — 30/30 pass `post_status`, `_event_status`, the date
window, the SQL candidate stage, the recurrence stage and the language gate, and
all 30 sit in the archive ordering at ranks 124–315. They were invisible to the
**county filter** because they carried no `conexao_county` term, so
`/eventos?county=laois` (before: 20 results) returned none of them.

## HOW FUTURE LAOIS TOURISM EVENTS ARE PROTECTED:

The configuration is now self-healing, so every future import receives the
county hint from the handler and `save_event_taxonomies()` writes the shared
term on every path, including the unchanged fast path. Proven by
`stage7-work/stage7-future-proof.php` (a future ICS-shaped event reaches the
county query with no per-ID handling) and locked in by
`tests/test-source-county-hint.php` (18 assertions on the rule, 0 record IDs).


| Gate | Command | Result |
|---|---|---|
| New regression suite | `conexao-event-importer/tests/test-source-county-hint.php` | **18 passed, 0 failed** |
| Script contract | `./scripts/run-tests.sh --scripts` | **6/6 suites, 0 failed** |
| Permanent invariants | `python3 scripts/verify-permanent-gates.py` | **7/7 gates, 86 assertions, 0 violations** |
| Registry drift | `php scripts/generate-registry-docs.php --check` | 14 plugins, 23 regions, zero writes |
| PHPCS on changed lines | `vendor/bin/phpcs` | **0 violations on added lines** (file totals identical to baseline) |
| Full suite | `./scripts/run-tests.sh` | 3 pre-existing failures, **proven pre-existing** |
| Future-event proof | `stage7-work/stage7-future-proof.php` | **PASS** |
| Idempotency | `--apply` twice | 31 then **0** assignments |

**Pre-existing failures (not caused by this work):** `test-error-handling.php`
(1), `test-ivvcc-importer.php` (1), `test-town-sanitization.php` (1). Verified by
reverting both changed files to the baseline and re-running: identical failures.
PHPStan could not complete in this environment (worker OOM at 1G and 3G) — a
resource limit, not a code error.

PASS handler parsed the VEVENT
PASS parsed event carries county = 'Laois'                (hint forwarded)
PASS fixture got the shared laois county term
PASS fixture is returned by the /eventos county query (no per-ID handling)
PASS conexao_county term count unchanged (32)             (no new term)
PASS fixture removed
FUTURE-EVENT REGRESSION: PASS
```

`tests/test-source-county-hint.php` asserts the **rule**, never a record list:
**18 passed, 0 failed**. It fails if the original bug returns — an emptied
shipped county must be restored, a deliberate override must survive, a
county-less source must stay empty, and a source-shaped event must be reachable
through a county-scoped query.

- `upsert_event()` returned from the `"unchanged"` branch before
  `save_event_taxonomies()`, so a later run with a correct hint still could not
  assign the term.

The drift itself is reachable from wp-admin: the "Add Source" form renders the
County input **empty** (placeholder only) and `handle_save_source()` persists
whatever is submitted.

**Minimal fix applied:**

| # | Change | File |
|---|---|---|
| A | `get_all()` restores a shipped default county when the stored value is empty (never overwrites a non-empty one) | `includes/class-event-sources.php` |
| B | The `"unchanged"` fast path now calls `save_event_taxonomies()` | `includes/class-event-importer.php` |
| C | One-off, dry-run-first, idempotent repair assigning the **existing** shared term (1373) | `scripts/repair-event-county-terms.php` |
| D | Regression suite for the rule (18 assertions) | `tests/test-source-county-hint.php` |

**Why the fix applies to future events:** A makes the hint self-healing at the
configuration layer, so any future Laois Tourism import receives the county
forwarded by the handler; B guarantees the assignment is not skipped on any
import path. Proven end-to-end in §7.

