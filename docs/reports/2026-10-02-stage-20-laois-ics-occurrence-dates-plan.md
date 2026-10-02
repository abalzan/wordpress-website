# Plan — Stage 20: Laois ICS occurrence dates (recurring series vs. date range)

| | |
|---|---|
| **Task title** | Fix Laois Tourism ICS import so recurring events match their actual occurrence dates |
| **Date** | 2026-10-02 |
| **Author / agent** | Cline |
| **Branch** | `i18n` |
| **Repository baseline (starting SHA)** | `848e00ee29173dda1ab1c41c1181cec798641197` |
| **Applicable skill(s)** | `wp-content-change`, `wp-write-in-process-test`, `wp-http-acceptance-matrix`, `wp-update-docs` |
| **Plan status** | approved |

## 1. Scope

The Laois Tourism ICS feed publishes no `RRULE` at all. It encodes a weekly
course as a **single VEVENT** whose `DTSTART` is the first occurrence and whose
`DTEND` is the end of the **last** occurrence. The importer copied that pair
verbatim into `_event_date` / `_event_end_date`, and the existing one-time
evaluator (`Conexao_Event_Recurrence::one_time_occurs_on_date()`) treats an
inclusive `[start, end]` range as continuous availability.

Result: `Chair Yoga At Portlaoise Library` (29 Sep → 20 Oct 2026) was matched
on **every** day in that 21-day window instead of on its four weekly dates.

This task:

1. Establishes how the source really represents the event (measured, not
   assumed).
2. Adds **occurrence-date resolution** to the ICS import path — a generic,
   standards-based `RRULE` reader plus a **source-scoped** adapter for the
   Laois encoding convention.
3. Persists the resolved series into the recurrence meta the data model
   **already has** (`_event_recurrence`, `_event_recurrence_days`,
   `_event_recurrence_start`, `_event_recurrence_end`), so the existing date
   layer (`Conexao_Event_Recurrence`) and archive query
   (`Conexao_Event_Query`) match occurrence dates with **no change to the
   runtime evaluator and no second recurrence engine**.
4. Proves correctness, idempotence, DST safety and no collateral change.

## 2. Explicit non-goals

| Not doing | Why |
|---|---|
| Not reinterpreting standard ICS `DTEND` globally | Standard DTEND semantics are correct for a genuine multi-day event. The extra rule is isolated to the `laois_tourism` source id. |
| Not creating one WordPress post per occurrence | The existing architecture is explicitly "one canonical event + recurrence meta" (`class-event-query.php` header). Occurrence posts would duplicate identity, break dedup and multiply translations. |
| Not writing a general RFC 5545 recurrence expander | Out of proportion to the need and would duplicate `Conexao_Event_Recurrence`. The reader returns a *description*; the runtime stays the single evaluator. |
| Not touching `Conexao_Event_Recurrence`, `Conexao_Event_Query` or the theme | The bug is entirely in what the importer *stores*. The date layer already honours recurrence meta correctly — it was simply never given any. |
| Not hard-coding "Chair Yoga" or any title | The rule is structural (weekday + whole-week span + session-duration remainder) and applies to any qualifying event. |
| Not changing the Laois source URL or its `hide_subsequent_recurrences` parameter | The feed variant is already correct; it returns a byte-identical document. |
| Not retiring or touching the translation infrastructure | Out of scope (AGENTS.md non-goal). |

## 3. Relevant authoritative documents

| Fact | Owner |
|---|---|
| Repository orientation, safety rules, scope | `AGENTS.md` |
| Engineering rules (testing §8, docs §9, content changes §5.2) | `docs/engineering-standard.md` |
| Content model — event meta, recurrence, language policy | `docs/content-model.md` |
| Importer behaviour and pitfalls | `docs/plugins/conexao-event-importer.md` |
| Recurrence evaluator contract | `docs/plugins/conexao-event-runtime.md` |
| Test layering, suite discovery | `docs/testing.md` |
| Documentation index | `docs/README.md` |

## 3a. Skill workflow

| **Skill** | **Its preconditions** |
|---|---|
| `wp-content-change` | Baseline → dry-run → apply → verify, idempotence, no unrelated records touched. Local Docker only. |
| `wp-write-in-process-test` | Real WordPress via `tests/bootstrap.php`; discovered by glob at `<component>/tests/test-*.php`. |
| `wp-http-acceptance-matrix` | Use existing acceptance infra; local base URL only. |
## 4. Investigation performed before editing

| Question | Method | Result |
|---|---|---|
| Which source handler imports Laois? | `grep laois` + `_event_source_id` values | The **iCalendar** handler (`Conexao_Source_ICalendar`), *not* the HTML scraper `Conexao_Source_Laois_Tourism`. UIDs look like `10016457-1790690400-1792508400@laoistourism.ie`. |
| Where is the live feed? | `curl` against the configured URL and variants | `https://laoistourism.ie/events/?ical=1`, `PRODID:-//Laois Tourism - ECPv6.18.0//`. The configured `photo/?hide_subsequent_recurrences=1&ical=1` variant returns a **byte-identical** document (md5 `121dc815…`). |
| Does the feed carry recurrence? | `grep -c RRULE / EXDATE / RDATE` over all 30 VEVENTs | **0 RRULE, 0 EXDATE, 0 RDATE, 0 X- recurrence properties.** No standard or vendor recurrence signal exists. |
| What does the encoding look like? | Parsed all 30 VEVENTs; computed span, weekday and time deltas | 5 multi-week timed events, each with weekday(DTSTART)==weekday(DTEND), an exact whole-week day span, and a positive sub-day time remainder. Descriptions independently state the count ("four-week", "6-week", "seven-week"). |
| Is that a coincidence? | Cross-checked every other event | The other 25 are single-day or short genuine multi-day events; the only other multi-week event is all-day `VALUE=DATE` (excluded). |
| Regression or pre-existing limit? | `git log` on recurrence files + Stage 7 report | Pre-existing design limitation: the recurrence *model* exists and the archive honours it, but nothing ever populated it from an import. |
| Calendar-app behaviour | Same bytes read under RFC 5545 | A compliant client sees one VEVENT too; the weekly reading is the source's own intent, corroborated by the description. Documented as the source convention, not as calendar-app magic. |

## 5. Design

```
ICS VEVENT
   ├─ RRULE present?  ──yes──► Conexao_ICS_Recurrence  (generic, RFC 5545 subset)
   │                              → type=weekly, days[], start, end
   └─ no RRULE ──► source has a declared adapter? (registry, by source id)
                     └─ laois_tourism ──► Conexao_Laois_Tourism_Series::detect()
                                              → only when all 4 structural
                                                conditions hold
   ↓
normalized['recurrence']
   ↓
Conexao_Event_Importer_Engine::save_event_recurrence_meta()
   ↓
_event_recurrence / _days / _start / _end     ← meta the model ALREADY has
   ↓
Conexao_Event_Recurrence (unchanged)  →  date layer matches occurrence dates
Conexao_Event_Query (unchanged)      →  archive orders/curates by occurrence
```

Key properties:

- **One canonical post.** No occurrence posts, no new post type, no new meta keys.
- **The runtime evaluator is untouched** — it remains the single place that
  answers "occurs on date X".
- **The source-specific rule is isolated** behind a per-source adapter registry,
  and the standard RRULE path is always consulted first.
- **Idempotent writes**: delete-then-write of the same four values, and the rule
  participates in change detection so the first post-fix run updates and later
  runs report "unchanged".

## 6. Impact analysis

| Area | Impact |
|---|---|
| `wp-content/` | `conexao-event-importer` only (new 2 classes, 5 files edited). The runtime plugin and theme are untouched. |
| Content / routes / i18n | **None.** No route, slug, URL or translation behaviour changes. PT stays canonical; EN translations keep sharing identity meta. |
| Recurrence semantics | The `_event_recurrence*` meta group is now written by the importer. Meta keys and the evaluator are unchanged. |
| Export/import transfer | The four recurrence keys were added to both allowlists so a transferred record keeps matching occurrence dates. |
| Cache | `Conexao_Event_Query`'s date-keyed transient is flushed on event save as before; a rule change invalidates it through the normal save path. |
| Production | **No production write.** All work is local Docker; the importer is local-only tooling. |

## 7. Verification plan

| Check | Tool |
|---|---|
| Parser + evaluator + idempotence + DST | New in-process suite `tests/test-ics-occurrence-dates.php` |
| Import lifecycle, create/update counts, digests | `tests/stage20-laois-import-run.php` (baseline → dry-run → apply×2 → verify) |
| Two clean deterministic runs compared | Re-run after purge; compare digests and counters |
| Full suite + gates | `./scripts/run-tests.sh` |
| Static quality | `./scripts/lint.sh` |
| Archive/date filtering + detail pages over HTTP | New acceptance rows in `tests/acceptance/matrices/routing.json` + HTTP checks |
| Registry/doc drift | `php scripts/generate-registry-docs.php --check` |

## 8. Risks and mitigations

| Risk | Mitigation |
|---|---|
| A genuine Laois multi-day timed event is misread as a series | Rule requires an exact whole-week span **and** same weekday **and** a plausible single-session time remainder; a fixture event (Avenue Q, 30 Sep→3 Oct) is asserted to stay one-time. |
| The rule leaks to other feeds | Adapter registry keyed by source id; a test asserts the same fixture under another source id yields no recurrence. |
| A rule already stored is clobbered on re-import | Delete-then-write is deterministic; change detection compares a canonical signature; three consecutive writes asserted byte-identical. |
| Timezone drift shifts a calendar date | All date maths is calendar-date based (UTC-anchored `strtotime`/`gmdate`), never a site-timezone instant conversion; a DST-spanning series is asserted. |
| Re-import churn | Second run measured `created=0, updated=0, unchanged=30`. |

## 9. Rollback

Revert the commit. No schema change and no new meta key: the four
`_event_recurrence*` keys are written by the importer and deleted again when a
source stops declaring a series, so reverting restores the previous
DTSTART/DTEND-only behaviour without data surgery.
| `wp-update-docs` | Plan/report/evidence from the templates; keep `docs/README.md` current. |