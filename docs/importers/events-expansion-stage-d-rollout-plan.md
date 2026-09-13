# Events Expansion — Stage D Rollout Plan (Remaining Counties)

**Purpose:** Deterministic, registry-derived plan for rolling out the remaining
Republic of Ireland county event sources using the **existing** importer. This
plan does not introduce a second import system, does not touch the frontend, the
mobile app, the Event REST contract, or the Event Runtime.

**Status date:** 12 September 2026

> ## ✅ STATUS UPDATE — EXECUTED (13 September 2026)
>
> This plan was executed successfully. **EVENTS EXPANSION STAGE D PASSED** —
> see `docs/importers/events-expansion-stage-d-rollout-report.md` and the
> machine-readable `docs/importers/events-expansion-stage-d-rollout-manifest.json`.
> All 23 remaining counties / 46 sources were rolled out (23/23 Eventbrite
> imported; Heritage Week contributes 0 importable events because the 2026
> edition is past). Upstream HTTP 429 rate limits encountered during the first
> concurrent attempt were resolved with strictly sequential throttled runs.

**Depends on:** `EVENTS EXPANSION STAGE C3 PASSED`
(`docs/importers/events-expansion-stage-c3-production-report.md`).

---

## 1. Architecture (preserved, unchanged)

```
external source            Eventbrite public discovery page / Heritage Week listing
        │
        ▼
LOCAL Event Importer       wp-content/plugins/conexao-event-importer  (stays ACTIVE, dormant)
        │                  fetch → normalize → dedup → upsert (writes local Events + Media)
        ▼
LOCAL Events + Media       local Docker WordPress
        │
        ▼
JSON export                v1.1.0 `conexao-event-export` contract (UUIDs + embedded images)
        │
        ▼
PRODUCTION import          WordPress.com (manual operator step)
        │
        ▼
PRODUCTION Event Runtime   conexao-event-runtime (status gate, filters, JSON-LD)
```

- **Production never fetches Eventbrite or Heritage Week.** No cron, no polling,
  no background sync, no frontend fetch.
- The importer remains available for the rollout workflow; it must not be
  deactivated, removed, or replaced.
- All fetching is local and on-demand.

---

## 2. Authoritative county registry

Single source of truth: `wp-content/plugins/conexao-event-importer/includes/class-county-registry.php`
(`Conexao_County_Registry`).

- **26** Republic of Ireland counties (Northern Ireland out of scope).
- **3** pilot counties already rolled out in Stage C1/C2 and verified in C3:
  **Cork, Dublin, Laois**.
- **23** remaining counties → **46** source registrations
  (23 Eventbrite + 23 Heritage Week).

Source key conventions (unchanged):

| Provider | Source ID pattern | Source type |
|---|---|---|
| Eventbrite | `eventbrite_<county-slug>` | `eventbrite` |
| Heritage Week | `heritage_week_<county-slug>` | `heritage_week` |

Legacy Laois source IDs (`eventbrite`, `heritage_week`, `laois_tourism`) are
preserved as-is and are **not** recreated or merged.

---

## 3. Exact remaining counties (registry-derived)

> Derived from `Conexao_County_Registry::get_slugs()` minus the pilot set — not
> from prose. Verified by `tests/stage-d-rollout.php registry` (see the rollout
> report).

```
carlow, cavan, clare, donegal, galway, kerry, kildare, kilkenny, leitrim,
limerick, longford, louth, mayo, meath, monaghan, offaly, roscommon, sligo,
tipperary, waterford, westmeath, wexford, wicklow
```

Count: **23 counties / 46 sources**.

---

## 4. Batches

| Batch | Counties | Eventbrite source IDs | Heritage Week source IDs | Sources |
|---|---|---|---|---|
| 1 | Carlow, Cavan, Clare, Donegal, Galway | `eventbrite_carlow`, `eventbrite_cavan`, `eventbrite_clare`, `eventbrite_donegal`, `eventbrite_galway` | `heritage_week_carlow`, `heritage_week_cavan`, `heritage_week_clare`, `heritage_week_donegal`, `heritage_week_galway` | 10 |
| 2 | Kerry, Kildare, Kilkenny, Leitrim, Limerick | `eventbrite_kerry`, `eventbrite_kildare`, `eventbrite_kilkenny`, `eventbrite_leitrim`, `eventbrite_limerick` | `heritage_week_kerry`, `heritage_week_kildare`, `heritage_week_kilkenny`, `heritage_week_leitrim`, `heritage_week_limerick` | 10 |
| 3 | Longford, Louth, Mayo, Meath, Monaghan | `eventbrite_longford`, `eventbrite_louth`, `eventbrite_mayo`, `eventbrite_meath`, `eventbrite_monaghan` | `heritage_week_longford`, `heritage_week_louth`, `heritage_week_mayo`, `heritage_week_meath`, `heritage_week_monaghan` | 10 |
| 4 | Offaly, Roscommon, Sligo, Tipperary, Waterford | `eventbrite_offaly`, `eventbrite_roscommon`, `eventbrite_sligo`, `eventbrite_tipperary`, `eventbrite_waterford` | `heritage_week_offaly`, `heritage_week_roscommon`, `heritage_week_sligo`, `heritage_week_tipperary`, `heritage_week_waterford` | 10 |
| 5 | Westmeath, Wexford, Wicklow | `eventbrite_westmeath`, `eventbrite_wexford`, `eventbrite_wicklow` | `heritage_week_westmeath`, `heritage_week_wexford`, `heritage_week_wicklow` | 6 |

---

## 5. Per-batch procedure

For **each** batch, in order, with writes confined to the batch:

1. **Preflight** — confirm each source is registered, its stored `type`,
   `county`, `url`, `region_labels`/`hw_where` match the registry, and it is
   `inactive` beforehand. Any mismatch → fix config or mark the source BLOCKED.
2. **Dry-run** — run each source with `run_source( id, dry_run = true )`.
   Dry-run classifies (discovered / import-eligible / past / would-create /
   would-update / duplicate / invalid / errors / address audit) **without writing
   any Event post**.
3. **Provider verification** — Eventbrite: public-page fetch, `__SERVER_DATA__`
   extraction, region/county selection, `primary_venue` preference, address/city,
   dates/times, page-cap detection. Heritage Week: each `where[]` walk, Next-link
   pagination, `data-id` identity, detail parsing, cross-`where[]` dedup, and
   seasonality/past-edition behaviour.
4. **Import** — activate only this batch's sources, run the normal importer,
   write local Events. Existing records are preserved; nothing is deleted.
5. **Idempotency** — immediately rerun the same source set; assert no duplicate
   source identities / URLs, only legitimate updates.
6. **Lifecycle** — for one representative source per provider, verify
   `source_not_found` stays hidden publicly but visible to CLI/admin, and a
   reappearing identity restores rather than duplicates.
7. **Frontend regression** — archive, cards, detail, category filter, county
   filter, city/town filter, AND semantics, pagination, external links, JSON-LD,
   sitemap.
8. Proceed to the next batch only when the current batch is stable.

---

## 6. Final stages

1. **Export** — one scoped rollout export, v1.1.0 schema, restricted to the 46
   rollout source IDs (pilot sources excluded to keep the delta clean).
2. **Production transfer preparation** — export artifact + machine-readable
   manifest (counts, source breakdown, UUID / source+source_id / URL / duplicate
   / past counts, validation). **No automatic production import.**
3. **No destructive cleanup** — no Event deletion, no media purge, no legacy
   source removal, no taxonomy rewrite, no runtime filter change, no cron.

---

## 7. Stop conditions

A county/source is marked **BLOCKED** (not faked) if the upstream site is
blocked, changed, empty, seasonal, or structurally different. Validation is
never weakened and fallback data is never invented. Other independent counties
continue.

---

## 8. Tooling

| Tool | Purpose |
|---|---|
| `tests/stage-d-rollout.php` | Registry plan, preflight, dry-run, import, status snapshot (JSON) |
| `tests/probe-county-sources.php` | Live provider probe (page caps, region labels, HW walk) |
| `tests/test-county-registry.php` | Registry/seed/idempotency tests |
| `tests/test-eventbrite-importer.php` | Eventbrite parser/normalizer tests (fixtures) |
| `tests/test-reappearing-event-lifecycle.php` | `source_not_found` lifecycle (public + CLI) |
| `tests/test-past-event-filter.php` | Past-event filter tests |
| `tests/test-event-address.php` | Address/location tests |
| `tests/verify-filters.php` | County/City/Category AND semantics |
| `includes/class-event-export.php` | v1.1.0 export (`build_export( sources: [...] )`) |
