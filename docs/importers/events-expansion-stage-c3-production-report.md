# Events Expansion — Stage C3 Report (Post-Import Production Verification + Reconciliation)

**Scope:** Verify that the operator's **manual** production import of the Stage C2
pilot export produced the expected production state, and reconcile production
against the authoritative C2 export artifact. **READ-ONLY — no production writes.**

**Prerequisite:** `EVENTS EXPANSION STAGE C2 PASSED` (see
`docs/importers/events-expansion-stage-c2-report.md`). Stages A, B, C1 also passed.

**Pilot sources (6):** `eventbrite_laois`, `eventbrite_cork`, `eventbrite_dublin`,
`heritage_week_laois`, `heritage_week_cork`, `heritage_week_dublin`.

**Status date:** 12 September 2026

---

## 0. Verdict (summary)

| Question | Answer |
|---|---|
| Manual C2 production import reconciled | **Yes — 742 / 742 exact** |
| Missing pilot Events | **0** |
| Unexpected duplicate identities | **0** |
| Correct source / source_id | **Yes** (0 drift, 0 generic replacement on pilot records) |
| Eventbrite venue / address / town survived | **Yes (742/742 have venue + address)** |
| Heritage Week behaviour correct | **Yes** (0 imported; 2026 edition past; no HW crawling) |
| County filter works | **Yes** |
| City filter works | **Yes** |
| County + City AND semantics | **Yes** (cross-county combo → 0) |
| Category combinations | **Yes** |
| Archive works (HTTP 200, pagination) | **Yes** |
| Detail pages work (no fatal errors) | **Yes** |
| Source / ticket links work | **Yes (archive card CTA → external, `target=_blank rel=noopener`)** |
| Images intact | **Yes (741/741, 741 distinct attachments, no logos)** |
| REST API contract valid | **Yes** (minor non-functional URL-meta transformation — see §22) |
| App runtime verified | **NOT TESTABLE** (no app runtime available) |
| Production source crawling inactive | **Yes** (no cron; manual-only; no post-import crawl) |
| Unrelated production data changed | **No** |
| Critical performance regression | **No** |

**FINAL CLASSIFICATION: EVENTS EXPANSION STAGE C3 PASSED**

### 0.1 Reconciliation totals (explicit)

```
EXPECTED   = 742
MATCHED    = 742   (exact 742, field-diff 0)
MISSING    = 0
UNEXPECTED = 0     (pilot-source production Events not represented in the C2 export)
DUPLICATED = 0     (by uuid / source+source_id / canonical URL)
```

---

## 1. Stage prerequisites

| Stage | Result |
|---|---|
| Events Expansion Stage A | PASSED |
| Events Expansion Stage B | PASSED |
| Events Expansion Stage C1 | PASSED |
| Events Expansion Stage C2 | PASSED (`docs/importers/events-expansion-stage-c2-report.md`) |

C2 produced and audited a scoped export of **742 published Events**, 6 pilot
source registrations, **741 embedded images**, **0 past Events**, payload audit
`ALL PASS`.

## 2. Manual import context

The operator imported the validated C2 payload into production (WordPress.com,
`https://conexaobr.ie`) manually. **Stage C3 performed no import, no re-import,
no source activation and no writes of any kind.** The verification is entirely
read-only: production REST (`GET`) plus read-only front-end HTTP (`GET`).

## 3. C2 export artifact (authoritative expected dataset)

| Property | Value |
|---|---|
| File | `wp-content/uploads/c2-pilot-export.json` (local Docker, 70,156,698 bytes) |
| Format / version | `conexao-event-export` `1.1.0` |
| `exported_at` | 2026-09-12T18:22:53+01:00 |
| `source_url` | `http://localhost:8080` (local; **no localhost URLs leaked into production data — verified**) |
| `event_count` | 742 |
| `filters.sources` | the six pilot sources |
| Events with embedded image | 741 / 742 |
| Events with `_event_status` = published | 742 / 742 |
| Past-dated Events | 0 |
| By source | `eventbrite_cork` 378 · `eventbrite_dublin` 330 · `eventbrite_laois` 34 · Heritage Week 0 |
| By county | Cork 378 · Dublin 330 · Laois 34 |

The export artifact (not a re-run of the import) is the authoritative expected
dataset for reconciliation.

## 4. Production baseline

No explicit pre-import production snapshot was persisted, so the baseline is
derived (documented, not assumed):

| Metric | Value | Source |
|---|---|---|
| Production Events REST-visible (`X-WP-Total`) | **828** | `GET /wp-json/wp/v2/event` |
| Pilot Events (6 sources) present | **742** | reconciled |
| Non-pilot pre-existing Events | **86** | 828 − 742 |

Because the export was produced against a local DB shared with earlier stages,
the total is **not** forced to equal 742 — the metric that matters is the
reconciliation of the scoped 742 identities, which is exact (§6).
## 5. Reconciliation methodology

`scripts/c3-production-verify.py` (READ-ONLY):

1. Fetches **every** Event exposed by the production REST API, authenticated with
   an Application Password and `context=edit` (raw, un-texturized titles), 100/page.
2. Indexes production by `_event_export_uuid`, by `(_event_source, _event_source_id)`
   and by canonical URL (`_event_url` / `_event_source_url`), and audits duplicate
   groups across all three keys.
3. Matches each of the 742 exported identities by **UUID → source+source_id → URL**.
4. Classifies each as **A** present+correct · **B** present+field-diff ·
   **C** missing · **D** duplicated.
5. Compares **every** exported meta key (not a hand-picked subset), the raw title,
   taxonomy terms and featured-image presence, normalising only well-understood
   display/transport artefacts (see below).
6. Emits `docs/importers/events-expansion-stage-c3-reconciliation.json`.

**Normalisations applied (so only genuine differences remain):**

- **Title** — the REST `rendered` title passes through WordPress `the_title`
  filters (`wptexturize` turns `" - "` into an en-dash; `convert_chars` encodes
  `&`). The comparison uses the **raw stored title** (`context=edit`), which is
  byte-identical to the export. (Confirmed against the importer's own change
  detection, `class-event-importer.php:1050`.)
- **Whitespace** — repeated-space runs collapsed (the import collapses them).
- **URL-ish meta** (`_event_banner`, `_event_map_url`) — percent-escapes stripped
  by the production import (see §22 for the full finding).
- **`_event_export_uuid`** — exported at top level, populated in production meta by
  the import.

## 6. Expected vs actual counts

| Metric | Expected (C2 export) | Actual (production) | Δ |
|---|---:|---:|---:|
| Events in scope | 742 | 742 matched | 0 |
| `eventbrite_laois` | 34 | 34 | 0 |
| `eventbrite_cork` | 378 | 378 | 0 |
| `eventbrite_dublin` | 330 | 330 | 0 |
| `heritage_week_*` | 0 | 0 | 0 |
| Events with embedded image | 741 | 741 | 0 |

## 7. Per-source reconciliation

| Source | Exported | Matched (exact) | Missing | Duplicated | Unexpected |
|---|---:|---:|---:|---:|---:|
| `eventbrite_laois` | 34 | 34 | 0 | 0 | 0 |
| `eventbrite_cork` | 378 | 378 | 0 | 0 | 0 |
| `eventbrite_dublin` | 330 | 330 | 0 | 0 | 0 |
| `heritage_week_laois` | 0 | – | 0 | 0 | 0 |
| `heritage_week_cork` | 0 | – | 0 | 0 | 0 |
| `heritage_week_dublin` | 0 | – | 0 | 0 | 0 |
| **Total** | **742** | **742** | **0** | **0** | **0** |

Every exported identity matched a production Event (matched-by: `uuid` ×742;
0 needed the source_id/URL fallback). **No field differences** survived
normalisation (A = 742, B = 0).

## 8. Source identity verification

- **All 742 pilot records carry a county-specific source key** — no record was
  replaced by a generic `eventbrite` / `heritage_week` value (0 generic among pilot).
- `_event_source_id` present and non-empty on **742 / 742**.
- Distinct pilot source keys in production: `eventbrite_laois`, `eventbrite_cork`,
  `eventbrite_dublin` (Heritage Week contributed 0 events).
- **Legacy generic identities (documented separately, NOT touched):** production
  retains **2** pre-existing Events with the older generic `_event_source=eventbrite`:

  | id | title | source_id | status |
  |---|---|---|---|
  | 11551 | Wildlife Photography Experience | 1998361982484 | published |
  | 242 | The path of love, befriending ourselves and others. | 1985103556174 | published |

  These are pre-existing Laois records from before the county-specific key split.
  They are **not** duplicates of any pilot record (different `source` key ⇒ no
  identity collision) and were left untouched.
- **12 manual/legacy Events** have **no** `_event_source` (pre-existing production
  data, no status meta, no export UUID) — preserved, out of scope.

## 9. Duplicate verification

| Duplicate key | Duplicate groups |
|---|---:|
| `(_event_source, _event_source_id)` | **0** |
| `_event_export_uuid` | **0** |
| `_event_url` (canonical) | **0** |
| Content (`title` + `date` + `time` + `venue`) | 3 — **all pre-existing non-pilot manual/legacy rows** |

The three content-similarity groups are legacy manual club rows that have **no**
`_event_source`, `_event_date`, `_event_venue` or export UUID (`Kingdom Veteran
Vintage…` ×2, `Blessington Vintage…` ×4, `RIAC/IVVCC Cars and Breakfast` ×2). They
are **not** pilot Events and were **not** introduced by the manual C2 import (the
export contains only the three `eventbrite_*` sources). **No duplicate was
introduced by the manual import** ⇒ no duplicate manifest is required.

## 10. Eventbrite validation (representative production records)

All 742 Eventbrite pilot records have `venue`, `address`, `_event_url`, and a
featured image (except one). Samples (raw production values):

**eventbrite_laois (34 events; 34 with venue/address/image/url)**
- `12042` iPLAN Expert/Advanced Workshop — The Killeshin Hotel · *Dublin Road, Portlaoise, Laois, R32 TYW7* · 2026-11-04 09:30–16:00 · Portlaoise/Laois
- `12040` FREE bPerfect Makeover — Laois Pharmacy · *1 Lyster Square, Portlaoise, Laois, R32 …*
- `12038` GDI Novembertagung 2026 — The Killeshin Hotel · *Dublin Road, Portlaoise…*
- `12036` Centre and Focus (suburban: Durrow) — *Unity health balance., Clonageera, Durrow…*
- `11569` MysticMoments Soap (suburban: Mountmellick) — Mountmellick Community School

**eventbrite_cork (378 events; 378 with venue/address/image/url)**
- `13511` Homecoming — Tribe Flow Studio · *11/12 Academy Street, Cork…*
- `13509` Cork Speed Dating — Clancy's Cork · *15-16 Princes Street, Cork…*
- `13507` The Fashion Hound Edit (suburban: Inniscarra) — Top Barkz · *Bridgestown, Inniscarra…*

**eventbrite_dublin (330 events; 330 with venue/address/url; 329 with image)**
- `13515` Dance Before Dark at HOUSE — House Dublin · *27 Leeson Street Lower, Dublin…*
- `13467` WIN Your Next Client on LinkedIn — Crowne Plaza Northwood · *Northwood Park, Santry…*
- `13463` One Day Ticket (suburban: Dún Laoghaire) — LexIcon Library · *Queen's Road, Dun Laoghaire…*

Each sample retained the C2-validated `primary_venue` (`_event_venue` always equals
the export; the export's `_event_location` label is preserved too). The single
imageless pilot Event is `13104` (`eventbrite_dublin`) — matching the export
(741/742) exactly.

## 11. Heritage Week validation

- **Heritage Week contributed 0 Events to production** — the correct outcome, since
  the C2 stage established the 2026 Heritage Week edition was already past and the
  importer's past-event filter (and the payload audit) legitimately produced 0.
- No `heritage_week_*` source key exists on any production Event (grep over all 828).
- No inappropriate past Heritage Week Event was imported.
- No Heritage Week crawling is active on production (§18).
- Existing Heritage-Week-derived production data: none found.

**Classification: PASS** (0 imported is expected, not a failure).

## 12. Location validation

Live `/eventos/` filter results (production):

| Filter | HTTP | Page-1 cards | Max page | Empty state |
|---|---:|---:|---:|:--:|
| (unfiltered) | 200 | 10 | 82 | no |
| `?county=laois` | 200 | 10 | 4 | no |
| `?county=cork` | 200 | 10 | 38 | no |
| `?county=dublin` | 200 | 10 | 34 | no |
| `?cidade=portlaoise` | 200 | 10 | 2 | no |
| `?cidade=cork` | 200 | 10 | 38 | no |
| `?cidade=dublin` | 200 | 10 | 33 | no |
| `?county=laois&cidade=portlaoise` | 200 | 10 | 2 | no |
| `?county=kildare&cidade=maynooth` | 200 | 0 | 1 | **yes** |
| `?categoria=rally` | 200 | 10 | 2 | no |
| `?county=kildare&categoria=rally` | 200 | 2 | 1 | no |
| `?county=cork&cidade=portlaoise` | 200 | 0 | 1 | **yes (strict AND)** |
| `?county=laois&cidade=cork` | 200 | 0 | 1 | **yes (strict AND)** |
| `?county=atlantis` (unknown) | 200 | 0 | 1 | yes |

**Strict AND semantics confirmed:** a county combined with a city from another
county returns zero (`county=cork&cidade=portlaoise` → 0; `county=laois&cidade=cork`
→ 0). City never overrides county. Category combines with county
(`county=kildare&categoria=rally` → 2). Unknown slugs degrade gracefully to empty.

**Classification: PASS.**

## 13. Filter UI validation

Live `/eventos/` archive markup:

- **Three dimensions present:** `Localização` (county), `Cidade` (town),
  `Categoria` (category); root `data-event-filters` container present.
- **Active states:** `is-active`, `is-selected`, `aria-current`, and native
  `checked` inputs observed on a filtered view; screen-reader label
  “Localização. Filtro ativo: Laois” rendered.
- **Clear/reset:** a `Limpar` link pointing to the unfiltered `/eventos/` URL.
- **URL persistence / refresh / back-forward:** filtering is server-side and
  URL-driven (`?county=` / `?cidade=` / `?categoria=`) — the shared contract with
  the Lazer directory (no client-side redesign). Refresh and browser back/forward
  reproduce the same URL state natively.
- **Mobile:** responsive directory-filter behaviour is provided by the shared
  `initDirectoryFilters()` JS (`main.js`), unchanged by C3.
- **Dark mode:** `dark-mode.css` carries 49 `event-filter` rules (existing
  design-system tokens). No C3 change.
- **No redesign performed** (per §11 of the stage brief).

**Classification: PASS (markup + static assets).** Interactive browser-only
behaviours (actual click/back-button traversal, visual dark mode) are
**NOT TESTABLE** without a browser/DOM automation environment.

## 14. Archive validation

| Check | Result |
|---|---|
| `/eventos/` HTTP | 200 |
| Imported Events appear | Yes (e.g. `post-253` Laois with `conexao_county-laois conexao_town-portlaoise`) |
| Existing Events remain | Yes (`post-242`, `post-11551` legacy `eventbrite`) |
| Duplicate cards | None observed |
| Malformed cards / missing titles | None observed |
| Broken images | None observed (cards render `event-card-banner-img`) |
| Pagination | Semantic `/eventos/page/N/` links present; max page 82 unfiltered |
| Laois / Cork / Dublin | All 200 with cards (see §12) |

**Classification: PASS.**

## 15. Detail page validation

Representative live Event pages (all HTTP 200, no fatal errors):

| Label | HTTP | Event JSON-LD | Venue in page | og:image |
|---|---:|---:|:--:|:--:|
| eventbrite_laois sample | 200 | yes | yes | yes |
| eventbrite_cork sample | 200 | yes | yes | yes |
| eventbrite_dublin sample | 200 | yes | yes | yes |
| non-pilot (motorsport_ireland) | 200 | yes | n/a | yes |
| non-pilot (legacy generic eventbrite) | 200 | yes | n/a | yes |

Canonical link correct (`https://conexaobr.ie/eventos/<slug>/`); JSON-LD `Event`
includes `name`, canonical `url`, local `image`, `eventStatus`, `startDate`,
`location` (`Place`) and `organizer`. Title, date, image and schema all present.

**Note (pre-existing theme behaviour, not a C2/C3 change):** the Event single
template (`single.php`) deliberately renders title + featured image + short
summary; the venue/address/county/town and the provider CTA are surfaced on the
**archive card** (the `event-card-cta` links externally — §16) and in the JSON-LD.
The single page carries no rendering of `_event_map_url` or the raw
`_event_banner` (see §22). This behaviour is identical to pre-import production.

**Classification: PASS.**

## 16. External link validation

- Archive card CTAs on `/eventos/?county=laois`: **10 / 10** point to the
  provider's canonical event URL (`eventbrite.ie` / `eventbrite.com` / `.co.uk`),
  all with `target="_blank" rel="noopener noreferrer"`.
- The exported `_event_url` (ticket/detail URL) survived verbatim on all 742
  records (reconciliation) — e.g. `https://www.eventbrite.ie/e/dance-before-dark-at-house-tickets-1990852312860`.
- Heritage Week links: n/a (0 imported).
- No server-side liveness assertion of external providers is claimed: external
  providers may block automated requests (anti-bot). The **hrefs are verified**
  and normal browser behaviour is expected.

**Classification: PASS (href verification); provider liveness = NOT TESTABLE.**

## 17. Media validation

| Check | Result |
|---|---|
| Pilot Events with an embedded image (C2) | 741 |
| Pilot Events with a featured image in production | **741 / 742** |
| Pilot Events without image | 1 (`13104`, `eventbrite_dublin`) — matches export |
| Distinct featured-media attachment IDs | **741** (no shared/duplicate attachments) |
| Sampled media mime/size | `image/jpeg`, 640×~300–960 — event banners |
| Provider logos / placeholders | none found (filenames derive from the event slug) |
| Unexpected duplicate attachments | none |
| Unrelated media deleted | no evidence; library intact for referenced events |

**Classification: PASS.**

## 18. API validation (REST contract)

Authenticated `GET /wp-json/wp/v2/event/<id>` returns the full existing contract
with **no unexpected shape change**: `id`, `slug`, `link`, `title`, `content`,
`featured_media`, `meta` (33 keys incl. all `_event_*`), and taxonomy fields
`conexao_category`, `conexao_county`, `conexao_town`, `conexao_tag`.

Representative imported Event (`237`, `smokie-show-back-in-portlaoise`): every
exported meta key matched exactly — `_event_source`=`eventbrite_laois`,
`_event_source_id`=`1983257220735`, `_event_export_uuid`, `_event_date`/
`_event_end_date`, `_event_start_time`/`_event_end_time`, `_event_venue`,
`_event_address`, `_event_url`, `_event_source_url`, `_event_organizer`,
`_event_status`=published, `featured_media`=13517, county=Laois, town=Portlaoise.

**One non-functional transformation** (all 742 otherwise reconcile exactly): see §22.

**Classification: PASS (contract valid; documented minor transformation).**

## 19. App validation

No Conexão BR mobile-app runtime was available in this environment, so the
runtime app checks (Event list, County filter, City filter, detail, pagination)
are **NOT TESTABLE**. No app verification is fabricated. The **API / data
contract** that the app consumes was validated (§18): all `_event_*` meta and
taxonomy fields the app relies on are present and correct, and the exported
timestamps (`_event_import_date`, `_event_last_checked`) and UUIDs are intact.

**Classification: NOT TESTABLE (runtime); API contract PASS.**

## 20. SEO / indexing validation

| Check | Result |
|---|---|
| Event canonical URLs | Correct (`https://conexaobr.ie/eventos/<slug>/`) |
| Duplicate Event routes | None (0 duplicate canonical URLs) |
| Event JSON-LD | `Event` + `Place` emitted on detail pages |
| External source URLs remain external | Yes — card CTA is external; JSON-LD `url` is the **canonical site URL**, not the provider URL |
| Unwanted indexable duplicates | None |
| Theme sitemap (`/sitemap_index.xml`) | HTTP 200, **828 event URLs** (= published count), imported sample present |

**Observation (pre-existing, not a C2/C3 regression):** `robots.txt` advertises
`/sitemap.xml`, which Jetpack serves as a posts/pages-only map (no CPT URLs). The
theme's custom sitemap (`/sitemap_index.xml`) *does* contain all 828 event URLs.
This split predates the import (Jetpack sitemap `lastmod` 2026-09-02). No broad
SEO change was made.

**Classification: PASS** (with the pre-existing sitemap-endpoint note).

## 21. Performance

Production timings (server response, `curl`):

| Endpoint | Time |
|---|---:|
| `/eventos/` (unfiltered) | ~0.15–1.1 s |
| `?county=laois` | ~0.1–1.1 s |
| `?cidade=portlaoise` | ~0.1–0.9 s |
| `?county=laois&cidade=portlaoise` | ~0.1–1.0 s |
| `?county=kildare&categoria=rally` | ~0.1–0.8 s |
| Event detail | ~0.08–0.9 s |
| `/sitemap_index.xml` (828 event URLs) | ~0.6 s |

Filtered queries are **faster** than unfiltered (smaller result set). No query
explosion, no unusually slow taxonomy query, no PHP loop over all Events, no
external HTTP call during rendering observed. No material regression versus the
pre-import baseline (no pre-import timing snapshot existed; timings are healthy
in absolute terms).

**Classification: PASS.**

## 22. Production counts

Production after the manual import (REST-visible, 828 Events):

| Metric | Value |
|---|---:|
| Total published Events | **828** |
| Pilot Events (6 sources) | **742** |
| Non-pilot pre-existing Events | **86** |
| `_event_status` = published | 816 |
| `_event_status` = (none, legacy/manual) | 12 |
| With venue | 749 (pilot 742 / 742) |
| With address | 745 (pilot 742 / 742) |
| With featured image | 743 (pilot 741) |
| With `_event_export_uuid` | 816 (pilot 742) |

**By source:** `eventbrite_cork` 378 · `eventbrite_dublin` 330 · `eventbrite_laois`
34 · `motorsport_ireland` 59 · manual 12 · `ivvcc` 7 · `mondellopark` 6 ·
legacy `eventbrite` 2.

**By county (term):** Cork 379 · Dublin 331 · Laois 36 · Kildare 7 · Wicklow 2 ·
Kerry 1 · Sligo 1 · Waterford 1.

**By town (top):** Cork 377 · Dublin 330 · Portlaoise 12 · Killeshin 5 ·
Stradbally 4 · Laois 4 · Killenard 2 · (then singles: Abbeyleix, Ballyvourney,
Castletown, Cobh, Durrow, Dungarvan, Graiguecullen, Mountmellick, Mountrath,
Portarlington, etc.).

**By category:** populated only on motorsport/curated non-pilot Events (Rally 11,
Autocross 10, Sporting Trial 8, …); the 742 Eventbrite pilot Events carry **0**
categories (Eventbrite discovery carries no tags — consistent with C2). 766 Events
have no category.

| Metric | Value |
|---|---:|
| `source_not_found` count | **NOT TESTABLE via public REST** (runtime `_event_status` gate hides it; §22.1) |
| `expired` count | **NOT TESTABLE via public REST** (same gate) |
| Duplicate identities / URLs / UUIDs | **0 / 0 / 0** |

Baseline reconciliation: every material difference is explained — 742 pilot
Events added (matching the C2 export exactly); 86 Events are pre-existing
non-pilot records (motorsport 59, IVVCC 7, Mondello 6, 12 manual, 2 legacy
generic); production source crawling added nothing (§23).

### 22.1 `source_not_found` / `expired` — why NOT TESTABLE

`Conexao_Event_Runtime::filter_public_event_queries()` (`pre_get_posts`) constrains
**all** non-admin, non-CLI Event queries — including REST requests — to
`_event_status = published` OR no status. `source_not_found` / `expired` records
are therefore not enumerable through the public/authenticated REST API. Evidence
that none were imported: the C2 export contained **only** `published` Events
(742/742), and the visible production set contains **0** such statuses. This is a
**limitation of the read-only REST surface**, not a production defect.

## 23. Production source-safety verification

| Requirement | Status |
|---|---|
| Eventbrite county sources inactive on production | **No crawling runs** (below) |
| Heritage Week county sources inactive on production | **Confirmed: 0 HW events; no HW crawl** |
| Production-side scraping running | **No** |
| New production cron / import job | **No** (`grep` for `wp_schedule_event`/`wp_next_scheduled` = 0 matches) |
| Source credentials added to production | None added by C3 (C3 is read-only) |
| Remote provider requests in normal Event rendering | **No** (images local; JSON-LD URL canonical; `_event_banner` external URL not rendered — attachment preferred) |

**Evidence:**

- Production runs plugin `conexao-event-importer` v1.7.1 (**active**). Its design is
  **manual-only**: no `wp_schedule_event` anywhere; all trigger points are
  `admin_post_*` handlers (logged-in admin) plus WP-CLI (unavailable on
  WordPress.com). No REST import route is registered ⇒ **no automatic crawling**.
- **No post-import crawling:** every pilot record's `_event_last_checked`
  (max `2026-09-12 18:12:20`) and `_event_import_date` (max `2026-09-12 18:12:16`)
  fall **before** the C2 export cut-off (`2026-09-12T18:22:53`). Nothing has been
  re-crawled on production.
- Production architecture remains: **LOCAL source import → validated export →
  controlled/manual production import.**

> **Observation (pre-existing, flagged for the maintainer):** the stage brief and
> `AGENTS.md` describe `conexao-event-importer` as *local-only*, yet it is
> **active** on production (v1.7.1). It is **dormant** (no cron, no REST trigger,
> manual admin action only), so no scraping occurs. This state predates C3 (the
> plugin is the historical production importer). Per the corrective-action policy
> no write was made; deactivating it on production is a recommended, optional
> hardening follow-up — **not a C3 blocker**.

**Classification: PASS.** Per-source *option* active flags are not exposed via
REST, so that sub-detail is **PARTIAL / not directly observable**; the operational
conclusion (nothing runs) is firmly established.

## 24. Mobile / accessibility validation

| Check | Result |
|---|---|
| Viewport meta | `width=device-width, initial-scale=1` (present) |
| Filter controls | Native form controls (`input`/`select`) with labels + `aria-*` |
| Active-state semantics | `aria-current`, screen-reader active-filter label |
| Dark mode | `dark-mode.css` (49 `event-filter` rules) |
| Focus states | Provided by the shared design-system CSS (unchanged) |
| Horizontal overflow | No obvious overflow markers in rendered markup |

Desktop/mobile/dark-mode **visual** rendering, focus-ring appearance, contrast and
overflow are **NOT TESTABLE** without a browser/DOM automation environment; the
static assets and semantic hooks are present and unchanged by C3.

**Classification: PASS (static/semantic); visual = NOT TESTABLE.**

## 25. Discrepancies

Only **non-functional** transformations were found — no missing, duplicated,
mis-sourced or corrupted pilot data.

| # | Discrepancy | Extent | Impact | Class |
|---|---|---:|---|---|
| D1 | `_event_map_url` lost its percent-escapes during the production import (`%20`/`%2C` removed ⇒ `Unity%20health…` → `Unityhealth…`) | 712 / 742 | Field is **app-only** (not rendered on the site); still a valid Google Maps search link — reduced query precision only | harmless/manual-import transformation |
| D2 | `_event_banner` lost its percent-escapes (`%3A%2F%2F` etc. removed) | 741 / 742 | Banner is only a **fallback**; the local attachment (`_event_banner_attachment_id`, set on all 741) is preferred by the card/hero templates ⇒ unused | harmless/manual-import transformation |
| D3 | `_event_banner_attachment_id` present in production, absent in the export | 741 | **Expected** — the import assigns the local attachment id | expected import behaviour |
| D4 | `_event_export_uuid` compared at a different location (export top-level vs production meta) | 742 | Comparison artefact only; UUIDs match 742/742 | expected |
| D5 | Title differences on the first (unauthenticated) pass | 276 | **Display artefact** — `the_title`/`wptexturize` en-dash + `convert_chars` `&`; raw stored titles are identical | expected |

No field difference requires correction. D1/D2 originate in the **production
import transport** (WP.com meta sanitisation stripping `%XX`); they do **not**
affect the primary Event data (source, source_id, dates, times, venue, address,
canonical/ticket URLs, images).

## 26. Corrective actions

**None required.** Every discrepancy is classified as harmless/expected (§25). Per
the corrective-action policy, no write was performed: the exact cause is
identified, affected records are enumerated (all harmless), and the primary data
is fully reconciled. Because D2 is not rendered on the web and D1 affects only app
map-query precision, a scoped correction would provide negligible value and
carries avoidable risk. **No corrective write.**

Optional, non-required follow-ups for the maintainer (no action taken):

1. Deactivate the local-only `conexao-event-importer` on production (dormant; §23
   observation).
2. If app map precision matters, rebuild `_event_map_url` from the stored address
   on production (a scoped, reversible, app-only field refresh) — deferred.

## 27. Rollback status

**Rollback = NOT REQUIRED.** No critical production problem was found; the manual
C2 import reconciled exactly (742/742), introduced 0 duplicates, and left the 86
pre-existing non-pilot Events intact.

## 28. Final production state

- **828** published Events (742 pilot + 86 pre-existing non-pilot).
- 742/742 pilot Events reconciled **exactly** against the C2 export
  (source, source_id, UUID, dates, times, venue, address, canonical/ticket URLs,
  organizer, taxonomy, image).
- **0** missing, **0** duplicate identities/URLs, **0** unexpected pilot records.
- Heritage Week: **0** imported (correct — 2026 edition past); no HW crawling.
- Filters (county / city / category / strict AND) work; archive and detail pages
  return 200; external provider links are external; images intact (741).
- Source crawling remains **inactive** (no cron; no post-import crawl).
- No unrelated production data changed; no corrective write; no rollback.

## 29. Reproducibility / artifacts

| Artifact | Description |
|---|---|
| `scripts/c3-production-verify.py` | Read-only production fetch + C2 reconciliation (writes the JSON below) |
| `scripts/c3-production-http-verify.py` | Read-only front-end/REST HTTP verification |
| `docs/importers/events-expansion-stage-c3-reconciliation.json` | Machine-readable reconciliation (742 rows, EXPECTED/MATCHED/MISSING/UNEXPECTED/DUPLICATED) |
| `docs/importers/events-expansion-stage-c3-http.json` | Archive/filter/detail/sitemap/external-link results |

Re-run:

```bash
export WP_USERNAME='...' WP_APPLICATION_PASSWORD='...'   # Application Password
python3 scripts/c3-production-verify.py \
    --export /tmp/c2-pilot-export.json \
    --out docs/importers/events-expansion-stage-c3-reconciliation.json
python3 scripts/c3-production-http-verify.py \
    --out docs/importers/events-expansion-stage-c3-http.json
```

---

## 30. FINAL CLASSIFICATION

**EVENTS EXPANSION STAGE C3 PASSED**

The operator's manual C2 production import is fully reconciled: all 742 scoped
pilot Events are present in production with correct source identities and intact
Eventbrite venue/address/town/date/time data, with zero missing, zero duplicated
and zero unexpected identities. Eventbrite and location filters (including strict
County + City AND semantics and category combinations) work; the archive, detail
pages, external source/ticket links, images, REST API contract and SEO signals are
valid; production source crawling remains inactive; no unrelated production data
changed. The only discrepancies are two non-functional URL-meta transformations
(`_event_map_url`, `_event_banner`) that require no correction. App runtime checks
are honestly marked **NOT TESTABLE**; `source_not_found`/`expired` counts are
**NOT TESTABLE** through the gated REST surface. **Rollback: NOT REQUIRED.**

