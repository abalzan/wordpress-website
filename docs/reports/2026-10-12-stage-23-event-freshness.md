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
can be verified against production from here (§11).
