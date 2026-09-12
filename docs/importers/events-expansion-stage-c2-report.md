# Events Expansion — Stage C2 Report (Pilot Validation + First Export Preparation)

**Scope:** Validate the six-source pilot after the first live local import and
prepare the first production-ready Event export. **LOCAL ONLY — no production
writes.**

**Prerequisite:** Stage C1 = `EVENTS EXPANSION STAGE C1 PASSED` (verified — see
`docs/importers/events-expansion-stage-c1-pilot-report.md`).

**Pilot sources (6):** `eventbrite_laois`, `eventbrite_cork`,
`eventbrite_dublin`, `heritage_week_laois`, `heritage_week_cork`,
`heritage_week_dublin`. No additional counties activated.

**Status date:** 12 September 2026

**Remediation date:** 12 September 2026

---

> ## ⚠️ STATUS UPDATE — BLOCKER REMEDIATED (see §19)
>
> The original blocker documented below (**117 duplicate Cork Event records**,
> §1–§18) was **fixed, the duplicates reconciled, and the full C2 validation
> re-run successfully** on 12 September 2026. The original analysis is preserved
> verbatim as historical context. **The final classification is now
> `EVENTS EXPANSION STAGE C2 PASSED`** (see §19/§20). No production write
> occurred at any point.

---

## 0. Verdict (summary)

| Question | Answer |
|---|---|
| Second imports create no unexpected duplicates | **NO — 117 duplicate Cork Event records created** |
| Existing Events matched | Partial — Laois/Dublin idempotent; Cork re-created hidden events |
| Source identities remain stable | Yes for matched events (0 drift), but **117 duplicate (source, source_id) identities now exist** |
| Cross-provider duplicates handled conservatively | Yes (0 cross-source matches) |
| Location/date/category data correct | Yes |
| Content boundary respected | Yes (max 44–150 bytes/summary) |
| Media correct | Yes (no duplicate image ingestion; images reused) |
| Export valid and scoped | **Not produced — blocked** (dataset contains duplicate identities) |
| Production untouched | Yes |
| Regression tests pass | Yes (same env-only failures as C1) |

**FINAL CLASSIFICATION: EVENTS EXPANSION STAGE C2 BLOCKED**

> **Blocker:** the idempotency re-run created **117 duplicate Cork Event
> records** (one new `published` record for each of 117 Cork events that had
> previously been auto-marked `source_not_found` when it dropped out of the
> Eventbrite moving window, then reappeared). Root cause and proof are in §5.
> Per the stage's stop conditions ("Any unexpected duplicate is a blocker"), no
> production export was produced.

---

## 1. Production Safety Statement

- **No production writes occurred.** All work on local Docker WordPress
  (`localhost:8080`). Production (WordPress.com / conexaobr.ie) never contacted.
- **No export was imported, no WordPress.com write API called, no production
  option modified, no production source activated.**
- The six pilot sources and all 58 registry sources remain **inactive** on the
  local DB (`active=0`); imports were driven explicitly by source id.
- Only two plugin files were changed (C2 tooling/churn fix — see §17), both in
  the **local-only** `conexao-event-importer` plugin. The production
  `conexao-event-runtime` plugin was **not** modified.

---

---

## 2. Environment / Baseline Reconciliation

The local DB has been used across many prior stages (Motorsport Ireland, IVVCC,
Mondello Park, Laois Tourism, plus the C1/C2 Eventbrite/Heritage Week imports).
The DB state therefore does **not** equal "C1 baseline + one import" — the
Eventbrite feed is a **time-budgeted moving window**, and repeated runs across
the prior session accumulated `source_not_found` records. This is material to
interpreting the idempotency result and is called out here explicitly.

**Pre-C2 baseline (canonical, captured immediately before this stage's second
pass — `tests/c2-run-pair-baseline.json`):**

| Metric | Value |
|---|---|
| Published (gate-visible) Events | 661 |
| Pilot published Events | 587 |
| `eventbrite_laois` / `eventbrite_cork` / `eventbrite_dublin` (published) | 34 / 260 / 293 |
| Duplicate pilot (source, source_id) identities | **0** |
| Media attachments / max id | 1155 / 17472 |
| Town terms / Category terms | 24 / 58 |

**Ungated pilot census before the second pass** (`tests/c2-state-probe.php`,
bypassing the runtime gate with SQL):

| Metric | Value |
|---|---|
| Pilot Events (all statuses) | 765 |
| `_event_status` | published 587 / `source_not_found` **176** / expired 2 |
| By county | laois 34 / cork 382 / dublin 349 |
| Past-dated pilot Events | **0** (all `_event_date` ≥ today) |
| With embedded image | 764 / 765 (~50 MB) |

The **176 pilot `source_not_found` records** are the seed of the blocker: they
are Eventbrite events that dropped out of the moving window on earlier runs and
were hidden (never deleted) by the importer's missing-source handling.

`tests/c2-clean-verify.log` (prior session, 15:21) captured a run where the
feed returned exactly the published window (`cork found=260 created=1`); no
hidden events reappeared in that window, so the defect did not surface then.
This stage's run received `cork found=379`, which includes the previously
hidden events — exposing the defect.

---

## 3. Idempotency (§2)

The same six pilot sources were run a **second time** (`tests/c2-run2-pass.log`),
sequentially with 45 s rate-limit gaps.

### 3.1 Second-run per-source results

| Source | found | created | updated | unchanged | past-skipped | status |
|---|---:|---:|---:|---:|---:|---|
| `eventbrite_laois` | 34 | **0** | 0 | 34 | 0 | success |
| `eventbrite_cork` | 379 | **121** | 3 | 255 | 0 | success |
| `eventbrite_dublin` | 272 | **1** | 0 | 271 | 0 | success |
| `heritage_week_laois` | 24 | 0 | 0 | 0 | 24 | warning |
| `heritage_week_cork` | 24 | 0 | 0 | 0 | 24 | warning |
| `heritage_week_dublin` | 0 | 0 | 0 | 0 | 0 | success |

### 3.2 First-run (C1) vs second-run (C2) counts

| Source | C1 first run `created` | C2 second run `created` | Interpretation |
|---|---:|---:|---|
| `eventbrite_laois` | 34 | 0 | ✅ fully idempotent |
| `eventbrite_cork` | 379 | **121** | ❌ 117 duplicates + 4 genuinely new |
| `eventbrite_dublin` | 233 | 1 | ✅ 1 genuinely new (feed growth) |
| `heritage_week_*` | 0 (2026 edition ended) | 0 | ✅ (all past) |

### 3.3 Classification of the 122 created records

`tests/c2-postrun-verify.php` classifies each created record against the whole
live pilot set:

- **117 = DUPLICATES** — same `source` + `source_id` **and** same source URL
  **and** same sanitized title+date as an existing `source_not_found` record.
- **5 = genuinely new** — newly published events that appeared in the feed
  (4 Cork + 1 Dublin).
- **0 pilot Events removed.**

**Failures reported by the verifier (3):**
`No duplicate (source, source_id) identities`, `No duplicate source URLs`,
`No unexpected duplicate created`.

Any one of these is a hard blocker per §2 / §16.

### 3.4 Unchanged-record integrity

- `source_id` unchanged for **all** baseline pilot events (0 drift).
- `_event_export_uuid` unchanged for all baseline pilot events.
- Laois records: `created=0 updated=0 unchanged=34` — proving the title
  entity-churn fix (§17) holds (no perpetual update churn).

---
## 4. Source Identity Audit (§3)

`tests/c2-postrun-verify.php` + SQL census over the pilot set.

| Check | Result |
|---|---|
| Every pilot Event carries `_event_source` | ✅ |
| Every pilot Event carries a non-empty `_event_source_id` | ✅ |
| `_event_source` uses the **county-specific** key (Eventbrite) | ✅ (`eventbrite_<county>`) |
| `_event_source` uses the **county-specific** key (Heritage Week) | ✅ (`heritage_week_<county>`; 0 records — edition ended) |
| `source_id` stable between runs | ✅ (0 drift) |
| `_event_export_uuid` stable between runs | ✅ |
| Duplicate `(source, source_id)` identities | ❌ **117** |

**Identity drift: none.** The defect is **duplicate identity creation**, not
drift — the new records carry the *same* county-specific source key and the
*same* source id as the hidden records they duplicate.

### 4.1 Duplicate census (SQL, authoritative)

| Source | Duplicate `(source, source_id)` pairs |
|---|---:|
| `eventbrite_laois` | 0 |
| `eventbrite_cork` | **117** |
| `eventbrite_dublin` | 0 |
| `heritage_week_*` | 0 |
| **Whole DB (all sources)** | **117** |

Each pair = 1 old `source_not_found` record + 1 new `published` record.
Example: `eventbrite_cork|1993436308661 → [16771, 18088]`;
`eventbrite_cork|1976267308720 → [16988, 18146]` (URL `.../foodies-new-friends-cork-tickets-1976267308720` on both).

---

## 5. Root Cause — Why the Reappearing Events Duplicated

**The Event Runtime public query gate hides `source_not_found` events from the
importer's own deduplicator when the importer runs outside `wp-admin` (e.g.
WP-CLI / direct tooling scripts).**

Mechanism:

1. `conexao-event-runtime` registers
   `filter_public_event_queries()` on `pre_get_posts` and returns early only
   when `is_admin()`. In a CLI run `is_admin()` is **false**, so the gate
   **applies to every `event` query** — including the importer's internal
   lookups.
2. The importer's `Conexao_Event_Deduplicator::find()` tries, in order:
   `find_by_source()` → `find_by_url()` → `find_by_content()`. Each builds a
   `WP_Query` for `post_type=event` and therefore receives the gate's
   `_event_status = published OR NOT EXISTS` constraint.
3. A previously-imported event that dropped out of the moving window was marked
   `_event_status = source_not_found`. It is **invisible** to those queries.
4. When it reappears in the feed, `find()` returns 0 → `upsert_event()` takes
   the **create** branch → a second record with the same `source`/`source_id`/
   URL is inserted (`published`).
5. The documented behaviour — *"reappearing events return to published"*
   (`class-event-importer.php` line ~1171, `ivvcc-importer.md` §lifecycle) —
   never runs, because the event is matched as new, not as the hidden one.

### 5.1 Proof (`tests/c2-gate-diagnosis.php`)

A Cork `source_not_found`-only record (source_id `1996782347754` → post 16478):

```
source_not_found-only source_id: '1996782347754'
  WITH gate  : WP_Query ids=[]            <-- invisible to the importer
-- gate removed --
  NO gate    : WP_Query ids=[16478]        <-- found
  find_by_source(no gate) -> 16478
```

With the runtime `pre_get_posts` callback removed, the same query and the same
`find_by_source()` call return the hidden record. This isolates the gate as the
cause.

### 5.2 Why this is a genuine defect, not expected behaviour

- The importer's contract is "re-run never duplicates" and the lifecycle
  documentation states hidden events return to `published` on reappearance.
  Both are violated.
- In `wp-admin` the gate is skipped (`is_admin()` true) so the admin import path
  hides the bug; the project's documented CLI path (`wp conexao-events import`)
  and the local tooling scripts (`run_source()`) expose it.
- Baseline had **0** duplicate identities; the second pass produced **117**.

**Stop condition tripped:** "Duplicate Event records unexpectedly created."

---

## 6. Cross-Source Duplicate Review (§4)

Two read-only reviews over the full Event DB:

| Review | Tool | Result |
|---|---|---|
| Exact content match (sanitized title + exact date, different sources) | `tests/c2-cross-source-dupes.php` | **0 cross-source groups, 0 same-source groups** |
| Fuzzy review (same county+date+venue, and ≥80 % title similarity) | `tests/c2-cross-source-fuzzy.php` | **0 venue-overlap candidates, 0 title-overlap candidates** |

Pairwise comparisons performed: Eventbrite Laois ↔ Heritage Week Laois,
Eventbrite Cork ↔ Heritage Week Cork, Eventbrite Dublin ↔ Heritage Week Dublin,
and each pilot provider ↔ the existing sources (`eventbrite`, `laois_tourism`,
`motorsport_ireland`, `ivvcc`, `mondellopark`).

**Finding:** there are **no cross-provider duplicates** to merge, and the
conservative content-match behaviour is confirmed (no ambiguous pairs were
merged). The nature of the blocker is **intra-source** (same provider, same
source id) — see §5.

> Note: Heritage Week contributed **0** events (2026 edition ended; all 24
> fetched cards per county are correctly past-classified), so HW ↔ EB overlap
> could not be exercised with imported content this stage. This matches the C1
> finding.

---

## 7. Location Audit (§5)

`tests/c2-state-probe.php`, `tests/c2-baseline.php`.

### 7.1 Totals

| Metric | Pre-C1 baseline (C1 §2.4) | Pre-C2 baseline | After C2 second pass |
|---|---:|---:|---:|
| Published (visible) Events | 104 | 661 | **759** |
| Pilot Events (all statuses) | — | 765 | **887** |
| `_event_status` published / source_not_found / expired | — | 587 / 176 / 2 | **685 / 200 / 2** |

### 7.2 Events by county (published)

| County | Pre-C2 | After C2 |
|---|---:|---:|
| cork | 261 | **380** |
| dublin | 294 | **273** |
| laois | 36 | 36 |
| kildare | 6 | 6 |
| wicklow / waterford / sligo / kerry | 1–2 each | 1–2 each |

(County totals move with the Eventbrite moving window, not just with creation.)

### 7.3 Town-level vs county-only

| Metric | Pre-C2 | After C2 |
|---|---:|---:|
| Events **without** a county term | 59 | **59** (unchanged) |
| Events **without** a town term | 67 | **67** (unchanged) |
| `conexao_town` terms | 24 | **24** (unchanged) |

- ✅ **No existing Event lost its county** (the 59 county-less records are the
  pre-existing non-pilot/legacy events; count unchanged).
- ✅ **No incorrect county assignment** — every pilot Event carries the county of
  its source (`cork`/`dublin`/`laois`), verified by `c2-postrun-verify.php`.
- ✅ **No fabricated town values** — pilot town values come only from Eventbrite
  `locations[].locality` / venue address city (never guessed).
- ✅ **No unexpected town creation** — town term count unchanged (24).

The 117 duplicate Cork records are correctly assigned to **Cork** (the defect is
duplication, not mis-location).

---

## 8. Date / Time Audit (§6)

`tests/c2-audit2.php` over all pilot Events (all statuses).

| Metric | Value |
|---|---:|
| Single-day Events | 584 |
| Multi-day Events | 101 |
| Overnight Events (end time < start time, same date) | 77 |
| Events with a start time | 685 |
| Events with **no** time | 0 |
| Invalid stored date/time formats | **0** |

Representative overnight samples preserved verbatim:
`Laois Pride After Party 2026 (21:00→01:00)`,
`Celtic Sleep Out, Ireland 2026 (21:00→06:00)`,
`Paul Oakenfold (23:00→02:30)`.

- ✅ All dates/times map into the existing Event model (`_event_date`,
  `_event_start_time`, `_event_end_date`, `_event_end_time`).
- ✅ **No recurrence synthesis** — no `_event_recurrence*` created by the pilot.
- ✅ **No invented times** — `no_time=0`; every stored time came from the source.
- ✅ **No past-dated pilot Events** were created (importer past-filter holds;
  Heritage Week cards correctly skipped as past).

---

## 9. Category Audit (§7)

| Check | Result |
|---|---|
| Categories mapped onto pilot Events | **none** — Eventbrite discovery payload carries no tags; Heritage Week contributed 0 events |
| Unknown source category behaviour | unchanged (warning + skip; no categories created) |
| Category terms created | **0** (58 → 58) |
| Category counts | unchanged/sane |

No unexpected categories were created and no unknown-category records exist.

---

## 10. Content Boundary Audit (§8)

`tests/c2-audit2.php` (regex delimiter repaired this stage — see §17).

| Check | Result |
|---|---|
| Max pilot content length | **150 bytes** (single worst case); typical 44–150 |
| Content > 20 000 chars | **0** |
| Cookie-banner text | 0 |
| Marketing / provider banners | 0 |
| Tracker URLs | 0 |
| `<script>/<iframe>/<noscript>` | 0 |
| "nav-ui" heuristic hit | 1 (false positive — post 18146: *"You sign up. We book. Make new friends over dinner…"*) |

- ✅ **Eventbrite uses the short summary** (`summary`, falling back to
  `full_description`) — no giant editorial copy.
- ✅ **Heritage Week** description handling stays within the documented boundary
  (only the `text-block` div text, whitespace-collapsed) — 0 events imported.
- ✅ No provider logos, navigation, or tracker URLs.

The single "nav-ui" hit is a legitimate marketing one-liner in the event's own
summary, not site navigation — a heuristic false positive (the pattern matches
`sign up`), not a boundary breach.

---

## 11. Image / Media Audit (§9)

`tests/c2-state-probe.php` + targeted SQL.

| Metric | Pre-C2 | After C2 |
|---|---:|---:|
| Total Media Library attachments | 1155 | **1163** (+8) |
| Max attachment id | 17472 | 18214 |
| Pilot Events with an embedded image | 764 / 765 | **886 / 887** |

- ✅ **Only expected Event media added** — the +8 net attachments correspond to
  the ~5 genuinely new events plus a small number of first-time downloads.
- ✅ **No duplicate image ingestion** — the image handler de-duplicates by
  `_event_source_url`. Of the 117 duplicate Cork pairs, **116 share the *same*
  attachment id** as the hidden record they duplicate (e.g. `16988 → 16990`
  and `18146 → 16990`); only 1 pair references a different attachment. So
  duplicating the Event did **not** duplicate the image.
- ✅ **No provider logos** — pilot image attachments derive from Eventbrite
  event banners.
- ✅ **No images unexpectedly replaced** — reused attachment ids are identical;
  no unrelated existing media changed.
- ⚠️ Residual: the 117 duplicate *events* remain (see §5); their media is shared,
  so no orphaned/duplicated media was created.

---

## 12. Export Preparation & Export (§10–§11) — WITHHELD

Per §10 the scoped pilot Event set, the newly-created Events and the updated
existing Events were identified (see §3, §4). The scoped-export capability was
implemented in the existing mechanism (backward-compatible optional args on
`Conexao_Event_Export::build_export()` — see §17).

**No production export was generated.**

Rationale — the stage requires the export to be *valid and scoped* (§16) with
*correct source/source_id* and *no unrelated Event records* (§12). The pilot
dataset now contains **117 duplicate `(source, source_id)` identities** (§4);
exporting it would propagate the duplicate identities into production.
Producing an export is therefore intentionally **withheld** until the blocker is
fixed.

For reference, the intended scope (once unblocked):

| Item | Value |
|---|---|
| Scope mechanism | `build_export(['sources' => <6 pilot slugs>])` (existing format `conexao-event-export` v1.1.0) |
| Scope applied in CLI | + the runtime public gate → published pilot Events only |
| Candidate pilot set (published) | 685 (post-run) — **currently invalid due to duplicates** |
| Source identities | six county-specific keys (§4) |
| Attachment count | 886 pilot Events carry an image (50–60 MB embedded) |

> The export format was **not** invented or changed; only optional scoping args
> were added to the existing `build_export()`.

---

## 13. Export Payload Audit (§12) — NOT RUN

Because no export was generated (§12), the payload audit
(`tests/c2-payload-audit.php`) was not run against a production candidate. The
script was prepared and its `$past`/`$today` ordering defect fixed (§17) so it
is ready for the unblocked stage.

---

## 14. Regression (§14)

Serial runs after the second pass (importer 1.7.0 + runtime 1.2.0 active), on the
post-second-pass DB (759 published Events).

| Suite | Result | Notes |
|---|---|---|
| `test-county-registry` | 471 pass / **2 fail** | clean-DB seeding asserts (env; identical to C1) |
| `test-event-address` | 76 / 0 | ✔ |
| `test-past-event-filter` | 29 / 0 | ✔ |
| `test-error-handling` | 54 pass / **1 fail** | "History entry was added" — history buffer full at `MAX_ENTRIES=100` (`count` stays 100). Env/DB-size, not code |
| `test-import-log` | 53 / 0 | ✔ |
| `test-ivvcc-importer` | 45 pass / **1 fail** | real-IVVCC-URL DB collision (env; identical to C1) |
| `test-mondello-park-importer` | 142 / 0 | ✔ |
| `test-motorsport-ireland-importer` | 90 / 0 | ✔ |
| `test-event-image-sync` | 30 / 0 | ✔ |
| runtime `test-event-query` | 40 / 0 | ✔ |
| runtime `test-event-recurrence` | 90 / 0 | ✔ |
| runtime `test-plugin-separation` (with-tooling) | 30 pass / **4 fail** | cap-100 harness vs 759-event DB (env; identical class to C1) |
| theme `test-event-location-filters` | 44 pass / **1 fail** | unfiltered-archive cap assert (env; identical to C1) |

**Net:** ~1,194 asserts pass. All 9 failures are the **same environment/DB-size
failures documented in C1** (§16) — none is caused by C2 code. No data change
was caused by export generation, because no export was generated.

---

## 15. Production Safety (§13)

| Requirement | Status |
|---|---|
| Production untouched | ✅ (never contacted) |
| No export imported | ✅ |
| No WordPress.com write API called | ✅ |
| No production option modified | ✅ |
| No production source activated | ✅ |
| Runtime plugin unmodified | ✅ (`conexao-event-runtime` untouched) |

---

## 16. Recommended Remediation (to unblock C2)

1. **Make the importer's internal queries bypass the public gate.** Either:
   - (a) in `conexao-event-runtime::filter_public_event_queries()`, also return
     early under CLI (`defined('WP_CLI') && WP_CLI`) — the gate is documented as
     a *frontend* gate; or
   - (b) in the importer engine, remove/restore the runtime `pre_get_posts`
     callback around `run_source()`, or set a private query var the gate honours.
     (Prefer (a): smaller blast radius, matches the gate's contract.)
2. **Add a regression test** asserting an Event can be matched from
   `source_not_found` back to `published` in a non-admin context (currently
   untested).
3. **Reconcile the 117 duplicate Cork records.** Keep one canonical record per
   `(source, source_id)` (the reappearing `published` record is the live one; the
   `source_not_found` twin is stale) via a one-off, audited local reconciliation
   using `Conexao_Event_Deduplicator` semantics — never a blind delete.
4. **Re-run the C2 second pass**; confirm `created` = genuinely-new only and
   `0` duplicate identities, then proceed to the scoped export (§12).

---

## 17. Files Changed by This Stage

| File | Change |
|---|---|
| `includes/class-event-importer.php` | `is_unchanged()` now compares the **raw** `post_title` (`get_post_field`) and normalizes HTML entities on both sides — fixes perpetual "updated" churn from `the_title` texturization and Eventbrite entity-variant flips (found during the C2 idempotency re-run) |
| `includes/class-event-export.php` | `build_export()` gains **backward-compatible** optional `sources[]` / `after` scoping args; manifest records applied `filters`; default behaviour and JSON format unchanged |
| `docs/importers/events-expansion-stage-c2-report.md` | **NEW** — this report |
| `tests/c2-run2-background.php`, `c2-postrun-verify.php`, `c2-state-probe.php`, `c2-gate-diagnosis.php` | **NEW** — C2 idempotency / verification / proof tooling |
| `tests/c2-baseline.php`, `c2-live-import-pilot.php`, `c2-export.php`, `c2-payload-audit.php`, `c2-audit2.php` | prior-session C2 tooling; `c2-payload-audit.php` `$past`/`$today` ordering fixed, `c2-audit2.php` regex delimiter fixed |

Local DB changes (local-only): the second pass created **122** Event records (117
duplicates + 5 new) and 8 net attachments; `_event_status` / missing-source state
updated. **No production change.**

---

## 18. Success Criteria (§16)

| Criterion | Met |
|---|---|
| Second imports create no unexpected duplicates | ❌ **117 duplicate Cork Events** |
| Source identities remain stable | ⚠️ no drift, but 117 duplicate identities exist |
| Cross-provider duplicates handled conservatively | ✔ (0 merges) |
| Location / date / category data correct | ✔ |
| Content boundary respected | ✔ |
| Media correct | ✔ |
| Export is valid and scoped | ⚠️ mechanism ready, **withheld** (dataset invalid) |
| Production untouched | ✔ |
| Regression tests pass | ✔ (env-only failures) |

**FINAL CLASSIFICATION: EVENTS EXPANSION STAGE C2 BLOCKED**

Blocker: `_event_status = source_not_found` Events are hidden from the
importer's deduplicator by the Event Runtime public gate in non-admin (CLI)
contexts, so reappearing Events are **re-created** instead of being restored to
`published`. Proven by `tests/c2-gate-diagnosis.php`; 117 duplicate Cork Event
records resulted (baseline had 0). No production export was produced.

---

## 19. C2 Remediation (12 September 2026) — blocker resolved

### 19.1 Objective

Fix the importer/runtime interaction so internal importer queries can see
`_event_status = source_not_found` Events for deduplication/restoration,
reconcile the 117 duplicates that the failed C2 run created, and re-run the
complete C2 validation. No production write occurred; production
(WordPress.com / conexaobr.ie) was never contacted.

**Verdict of this remediation: the blocker is resolved.**

### 19.2 Root cause (recap)

The Event Runtime public gate (`Conexao_Event_Runtime::filter_public_event_queries()`)
returned early only for `is_admin()`. Under WP-CLI `is_admin()` is `false`, so
the gate's `_event_status = published OR NOT EXISTS` constraint was applied to
the importer's own deduplicator queries. A reappearing `source_not_found` event
was therefore invisible to `Conexao_Event_Deduplicator::find()`, and
`upsert_event()` took the **create** branch — producing a duplicate. (Full
mechanism + proof: §5.)

### 19.3 Runtime fix (primary)

`wp-content/plugins/conexao-event-runtime/conexao-event-runtime.php` — the gate
now returns immediately in **two** non-public contexts:

```php
public function filter_public_event_queries( $query ) {
    if ( is_admin() ) {
        return;
    }
    // Internal importer/tooling (WP-CLI) must query hidden statuses
    // (e.g. source_not_found) for deduplication and reappearing-event
    // restoration. This does NOT affect public frontend requests.
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        return;
    }
    …
}
```

- **Scope:** WP-CLI only — the smallest safe boundary. Public web requests never
  run under WP-CLI; `wp-admin` already returned above.
- **Not weakened:** frontend visibility, sitemap, public REST, archive and
  related-content behavior are unchanged (they never set `WP_CLI`).
- **Not disabled:** the `source_not_found` lifecycle is intact; the public gate
  is **not** removed and `source_not_found` Events are **not** made public.
- Runtime version bumped **1.2.0 → 1.2.1**.

### 19.4 Importer fix (secondary, same lifecycle)

`wp-content/plugins/conexao-event-importer/includes/class-event-importer.php` —
the `upsert_event()` "unchanged" fast path returned before the shared
`Conexao_Event_Status::set_status( … PUBLISHED )` line, so a reappearing
`source_not_found` event whose data was unchanged stayed hidden. The fast path
now restores `source_not_found → published` itself (scoped to `source_not_found`
only; `draft`/`rejected`/`expired` states are never auto-restored). Importer
version bumped **1.7.0 → 1.7.1**.

Deduplication rules were **not** changed: the lookup order remains
source+source_id → URL → content.

### 19.5 Regression test (new)

`wp-content/plugins/conexao-event-importer/tests/test-reappearing-event-lifecycle.php`
proves **both** sides in one context-aware script:

- **Public frontend** (plain `php`): a `source_not_found` event is hidden from a
  dedup-shaped `WP_Query`, from a site-wide archive query, and from the
  deduplicator; the post still exists (hidden, not deleted).
- **Internal importer** (`wp eval-file` → `WP_CLI` true): the deduplicator
  **finds** the `source_not_found` event; the upsert matches the **same** post
  (no creation), restores it to `published`, keeps the source identity, and
  yields an identity count of 1 (no duplicate). Covers the unchanged path, the
  changed/updated path, and the full A→B→D→E→F→G→H lifecycle.

Result: **ALL PASS (0 failures)** in both contexts.

### 19.6 Duplicate reconciliation (117 pairs)

Tooling: `tests/c2-reconcile.php` (modes `manifest` / `verify` / `apply`),
`tests/c2-recon-probe.php`, `tests/c2-recon-audit.php`.

**Manifest first (machine-readable, read-only).** `tests/c2-reconciliation-manifest.json`
captured every duplicate pair with original/duplicate post IDs, source,
source_id, canonical URL, full field comparison (title/date/time/venue/
organizer/county/town/category/attachment/export UUID), a classified reason,
media usage, and the keep/remove selection.

| Manifest metric | Value |
|---|---:|
| Required pairs (hard gate) | 117 |
| Pairs found | **117** |
| Pairs with verification errors | **0** |
| Pairs sharing the same attachment | **116** |
| Pairs using a distinct attachment | **1** |

The manifest builder **stops** (writes nothing, `--apply` refuses) if the pair
count ≠ 117, if any group has ≠ 2 members, or if any pair fails the identity/
URL/title/date classification. The **1** distinct-attachment pair
(`17087`/`18168`, attachments `17088` vs `18169`) matches the original report's
116/117 claim exactly.

**Preferred rule applied:** the pre-existing (`source_not_found`) record is
**kept** and restored to `published`; only the C2-created duplicate record is
**removed** (`wp_delete_post(…, true)`). `--apply` re-verifies each duplicate's
source/source_id against the manifest immediately before deleting, and is
idempotent/resumable (already-applied pairs are skipped and their kept record is
re-asserted `published`).

### 19.7 Media safety (§8)

- **No attachment was ever deleted.** 116 pairs share one attachment; the 1
  distinct pair keeps **both** attachments in the Media Library.
- For the distinct pair the duplicate's attachment (`18169`) was **re-parented**
  to the kept record (`17087`) rather than orphaned.
- Post-reconciliation audit (`tests/c2-recon-audit.php`): attachment library
  intact, **no dangling attachment parents**, both attachments of the distinct
  pair still present, kept record published. **ALL PASS.**

### 19.8 Before / after counts (§9)

| Metric | Before | After reconciliation | After second pass |
|---|---:|---:|---:|
| Total Events (all sources, publish) | 1019 | 902 | 903 |
| Cork-county Events | 504 | 387 | 388 |
| `eventbrite_cork` Events | 503 | 386 | 386 |
| Duplicate `(source, source_id)` identities | **117** | **0** | **0** |
| Distinct pilot source identities | 770 | 770 | 771 |
| Pilot published | 685 | 685 | 742 |
| Pilot `source_not_found` | 200 | 83 | 29 |
| Pilot expired | 2 | 2 | 0 |

- **117 duplicate records eliminated.** No legitimate Event removed.
- The 117 kept records collapsed the identity set back to one record per
  `(source, source_id)`; distinct identities unchanged (770) at reconciliation.

### 19.9 Reappearing-event lifecycle verification (§10)

The canonical second pass (WP-CLI, gate bypass active) restored previously
hidden events instead of duplicating them. Representative evidence from
`tests/c2-run3-pass.log`:

- **`eventbrite_cork: found=378 created=0 updated=4 unchanged=374 dup=0`**
  (the blocked run reported `created=121`).
- `UPDATED: OctoberFesht 2026 id=17087` — the kept record from the distinct
  reconciliation pair was matched via dedup and **updated**, not re-created.
- Across the whole re-run **created total = 1** (one genuinely new Dublin
  event), **duplicates = 0**.

### 19.10 Second-pass results — all six pilot sources (§11, §13)

| Source | found | created | updated | unchanged | past-skipped | dup | status |
|---|---:|---:|---:|---:|---:|---:|---|
| `eventbrite_laois` | 34 | 0 | 0 | 34 | 0 | 0 | success |
| `eventbrite_cork` | 378 | **0** | 4 | 374 | 0 | 0 | success |
| `eventbrite_dublin` | 330 | **1** | 1 | 328 | 0 | 0 | success |
| `heritage_week_laois` | 24 | 0 | 0 | 0 | 24 | 0 | warning |
| `heritage_week_cork` | 24 | 0 | 0 | 0 | 24 | 0 | warning |
| `heritage_week_dublin` | 0 | 0 | 0 | 0 | 0 | 0 | success |

- **Laois: 0 created / 0 updated / 34 unchanged** — fully idempotent.
- **Cork: 0 created** (was 121) — the defect is gone.
- **Dublin: 1 created** — genuine moving-window growth (§12), not a duplicate.
- **Heritage Week: 0 created** — the 2026 edition has ended; all cards are
  correctly past-classified. Zero newly created Events remain expected.

**Moving-window variance (documented separately, §12):** Eventbrite discovery
counts differ between runs by design (`eventbrite_dublin` 272 → 330 etc.); C2
success is defined by identity/matching stability, not identical counts.

### 19.11 Cross-source / audit re-runs (§14)

| Audit | Tool | Result |
|---|---|---|
| Duplicate identities / URLs (pilot) | `c2-verify-remediated.php` | **ALL PASS** (0 duplicates; 771 identities) |
| Duplicate identities (ALL sources) | `c2-verify-remediated.php`, `c2-recon-audit.php` | **0** |
| Cross-source exact content match | `c2-cross-source-dupes.php` | **0 cross-source, 0 same-source groups** |
| Cross-source fuzzy overlap | `c2-cross-source-fuzzy.php` | **0 venue, 0 title candidates** |
| Date / time model | `c2-audit2.php` | valid formats; 0 missing times; 0 past-created |
| Category audit | `c2-audit2.php` | 0 unknown categories; terms 58 → 58 |
| Content boundary | `c2-audit2.php` | max 150 bytes; 0 over-20k; 0 cookie/marketing/tracker/script |
| Media audit | `c2-recon-audit.php`, `c2-verify-remediated.php` | attachments intact; 0 dangling |

**Known heuristic false positives (not breaches):**
- `c2-audit2.php` `nav-ui` pattern flags ids 16988 and 17983 — both are
  legitimate event copy containing the phrase *"sign up"* (the same false
  positive class the original report documented).
- `c2-postrun-verify.php` content classifier (title+date only) flagged
  `17143`/`17743`; these are **two distinct tour sessions** (source_ids
  `1999081943908` vs `1999081944911`, different URLs, start times 13:00 vs
  14:00). The classifier was aligned to the documented content-match rule
  (title + date + start time, mirroring `find_by_content`), after which
  `POST-RUN VERIFY: ALL PASS`.

### 19.12 Regression suite (§16)

Run serially against the remediated DB (importer 1.7.1 + runtime 1.2.1):

| Suite | Result | Notes |
|---|---|---|
| **new** `test-reappearing-event-lifecycle` (public) | pass | SNF hidden publicly |
| **new** `test-reappearing-event-lifecycle` (CLI) | pass | SNF discoverable + restored |
| `test-county-registry` | 471 / **2** | env/DB-size (identical to C1) |
| `test-event-address` | 76 / 0 | ✔ |
| `test-past-event-filter` | 29 / 0 | ✔ |
| `test-error-handling` | 54 / **1** | history buffer cap (env; identical to C1) |
| `test-import-log` | 53 / 0 | ✔ |
| `test-ivvcc-importer` | 45 / **1** | real-IVVCC-URL DB collision (env; identical to C1) |
| `test-mondello-park-importer` | 142 / 0 | ✔ |
| `test-motorsport-ireland-importer` | 90 / 0 | ✔ |
| `test-eventbrite-importer` | 68 / 0 | ✔ (see §19.15 — stale expectations repaired) |
| `test-event-image-sync` | 30 / 0 | ✔ |
| runtime `test-event-query` | 40 / 0 | ✔ |
| runtime `test-event-recurrence` | 90 / 0 | ✔ |
| runtime `test-plugin-separation` (with-tooling) | 30 / **4** | cap-100 harness vs 771-event DB (env; identical class to C1) |
| theme `test-event-location-filters` | 44 / **1** | unfiltered-archive cap assert (env; identical to C1) |

**All remaining failures are the same environment/DB-size failures documented in
C1** (plus the `test-eventbrite-importer` stale-expectation repair in §19.15).
**No failure is caused by the C2 remediation code.**

### 19.13 Export + payload audit (§11, §17, §19)

With the dataset clean, the first scoped pilot export was produced by the
**existing** mechanism (`Conexao_Event_Export::build_export(['sources' => <6
pilots>])`, format `conexao-event-export` v1.1.0) — **local file only**, run in
a gate-active context so the export scope is the **published** pilot Events:

| Property | Value |
|---|---|
| File | `wp-content/uploads/c2-pilot-export.json` (~70 MB) |
| Manifest event_count | **742** (= payload) |
| By source | laois 34 / cork 378 / dublin 330 |
| Embedded images / UUIDs | 741 / 742 |
| Past events included | **0** |

`tests/c2-payload-audit.php`:

```
PAYLOAD AUDIT: ALL PASS (0 failures)
```

Manifest format/version/count/scope ✔; all UUIDs present + valid v4 ✔; all
source keys are pilot keys ✔; all Eventbrite county sources represented ✔;
non-empty `_event_source_id` ✔; every payload event matches a live event by
UUID ✔; payload count = live pilot count (742) ✔; all dates/times valid ✔; no
HTML, no oversized content ✔. (Heritage Week sources contribute 0 — expected,
2026 edition ended.)

### 19.14 Production safety

| Requirement | Status |
|---|---|
| Production untouched / never contacted | ✅ |
| No export imported; no WP.com write API called | ✅ |
| No production option modified; no source activated | ✅ |
| All 58 registry sources remain inactive locally | ✅ |
| Production remains untouched until the subsequent production stage | ✅ |

### 19.15 Files changed / added by this remediation

| File | Change |
|---|---|
| `conexao-event-runtime/conexao-event-runtime.php` | gate early-return under WP-CLI (v1.2.1) |
| `conexao-event-importer/includes/class-event-importer.php` | unchanged fast path restores `source_not_found → published` (v1.7.1) |
| `conexao-event-importer/tests/test-reappearing-event-lifecycle.php` | **NEW** regression test |
| `conexao-event-importer/tests/c2-reconcile.php` | **NEW** manifest/verify/apply reconciliation tool |
| `conexao-event-importer/tests/c2-recon-probe.php`, `c2-recon-audit.php`, `c2-verify-remediated.php` | **NEW** census / media audit / post-remediation verification |
| `conexao-event-importer/tests/c2-run3-background.php` | **NEW** canonical second pass (WP-CLI) |
| `conexao-event-importer/tests/c2-reconciliation-manifest.json`, `c2-reconciliation-after.json` | **NEW** reconciliation evidence |
| `conexao-event-importer/tests/c2-run3-pass.log`, `c2-baseline-post-remediation.json` | **NEW** second-pass + baseline evidence |
| `conexao-event-importer/tests/test-eventbrite-importer.php` | repaired stale Stage-A/B expectations (`is_laois_event` → `is_county_event`; summary preference; injected county) |
| `conexao-event-importer/tests/c2-postrun-verify.php` | content classifier aligned to `find_by_content` (title+date+time); export-UUID check treats empty→assigned as expected (551 assigned by export) |
| `conexao-event-importer/tests/c2-payload-audit.php` | Heritage-Hour-zero handling for the six-source scope assert |
| `docs/plugins/conexao-event-runtime.md`, `docs/plugins/conexao-event-importer.md` | version + gate-behavior docs |

### 19.16 Remaining limitations

- Eventbrite discovery is a time-budgeted moving window: `found` counts vary
  between runs. Idempotency is asserted on identities/matching, not counts.
- Heritage Week 2026 has ended — those three county sources contribute 0 events
  this stage; HW ↔ EB overlap cannot be exercised with imported content until
  the 2027 edition.
- One `nav-ui` content heuristic false positive remains (`sign up` phrase in
  legitimate copy); it is a test heuristic, not a data breach.
- The `c2-postrun-verify.php` "created since baseline" set legitimately includes
  events that were hidden before C2 and restored by the fix; these are not
  duplicates (proven by 0 duplicate identities/URLs).

### 19.17 Success criteria (§19) — met

| Criterion | Met |
|---|---|
| Importer can see `source_not_found` Events internally | ✅ (CLI gate bypass) |
| Public runtime still hides `source_not_found` Events | ✅ |
| Reappearing Events restored instead of duplicated | ✅ |
| Regression test passes (both sides) | ✅ |
| All 117 accidental duplicates safely reconciled | ✅ |
| Duplicate source identities = 0 | ✅ |
| Unexpected duplicate URLs = 0 | ✅ |
| Second imports create no unexpected duplicates | ✅ (created = 1 genuine) |
| Location / date / time data correct | ✅ |
| Media safe | ✅ |
| Existing Event functionality intact | ✅ |
| All six pilot sources behave correctly | ✅ |
| Production export generated + audited | ✅ (742 events, payload audit ALL PASS) |
| Production untouched | ✅ |

---

## 20. FINAL CLASSIFICATION

**EVENTS EXPANSION STAGE C2 PASSED**

The original blocker (117 duplicate Cork Event records — §1–§18, preserved as
historical context) was remediated: the Event Runtime gate now bypasses under
WP-CLI for internal importer queries, a focused reappearing-event regression
test passes in both public and CLI contexts, all 117 duplicates were reconciled
through an audited machine-readable manifest (media preserved), the complete C2
validation was re-run (no unexpected duplicates; created = 1 genuine), and the
first scoped production export was generated and audited (742 events;
`PAYLOAD AUDIT: ALL PASS`). No production write occurred; production remains
untouched until the subsequent production stage.

