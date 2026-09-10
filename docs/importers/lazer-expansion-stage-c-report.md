# Lazer Expansion — Stage C

## URL Verification, Final Dataset Lock & Local Implementation

- **Date**: 2026-10-09
- **Authority**: Stage A audit (PASSED) + Stage B report/dataset (COMPLETE)
- **Status**: COMPLETE. 12 NEW records created + 1 EXISTING record improved in the **LOCAL** WordPress only (Docker, `http://localhost:8080`). **Production untouched.**
- **Dataset artifact**: `docs/importers/lazer-expansion-stage-c-final-dataset.json` (13 records, valid JSON)
- **Implementation files**: `scripts/data/leisure-expansion-data-3.php`, extensions to `scripts/seed-leisure-expansion.php` and `scripts/verify-lazer-urls.php`, plus `scripts/apply-lazer-stage-c-knocknarea.php` (improvement) and `scripts/verify-lazer-stage-c.php` (post-import audit).

---

## 1. Approval scope (final lock)

12 NEW + 1 EXISTING — DATA IMPROVEMENT. No Stage B `REVIEW` (National Archives, Arranmore, Dan O'Hara's Homestead, Duncannon Fort) and no `REJECTED` (Galway city, Mullaghmore, Jerpoint Glass Studio) candidate was promoted, added or revisited.

---

## 2. URL verification (2026-10-09, live web)

Method: every supplied URL fetched with a browser user agent and identity-checked against the candidate (title/entity; attraction/destination detail — not category/search/homepage/expired); missing URLs located via the Discover Ireland sitemap plus dlr/Heritage Ireland sitemap scans. Original Stage B URLs are preserved in the final dataset JSON (`urls.stage_b_urls_preserved`).

| # | Candidate | Official | Discover Ireland | Ireland.com | Result |
|---|-----------|----------|------------------|-------------|--------|
| 1 | Chester Beatty | ✅ chesterbeatty.ie (free; D02 AD92; closure 15/06–31/12/2026 EU Presidency re-confirmed) | ✅ /dublin/chester-beatty | — | unchanged |
| 2 | The Forty Foot | ❌ no deep dlr page (dlr sitemap scan — none) | ❌ confirmed absent (DI sitemap) | — | **left empty — nothing invented**; facts rest on Stage A/B dlr verification |
| 3 | Derrigimlagh | — | ✅ /galway/derrigimlagh (Ballyconneely, Free to visit) | ✅ live | unchanged |
| 4 | James Joyce Tower Museum | ✅ joycetower.ie (free; Tue–Sun 10–16; groups book; not wheelchair accessible; A96 FX33) | ✅ /dublin/james-joyce-museum | — | unchanged |
| 5 | The Model | ✅ themodel.ie (Free Entry; hours; F91 TP20) | ✅ /sligo/the-model-home-of-the-niland-collection (Paid car parking tag) | — | unchanged |
| 6 | Doagh Famine Village | ⚠ 403 to bots (as in Stage B; kept per existing manual-review policy) | ✅ /donegal/doagh-famine-village (F93 PK19; Family/Dog/Free parking tags) | — | unchanged |
| 7 | Dursey Island | ❌ durseycablecar.com unreachable (connection failure, 4 attempts incl. http/www) | ✅ **FOUND**: /cork/dursey-island (dedicated destination page, "Visit Dursey Island", Co. Cork; Free to visit/Family friendly tags) | ✅ /cork/dursey-island | **DI page located by the Stage C verifier sweep + identity-confirmed; operator URL NOT stored** (unverifiable → §2 rule); record now external |
| 8 | Old Head of Kinsale | — | ✅ **REPLACED**: /cork/old-head-signal-tower-signature-discovery-point (located via DI sitemap; "Old Head Signal Tower - Kinsale"; Free car parking tag) | ✅ /cork/old-head-of-kinsale | Stage B DI candidate 404 (confirmed); original URL preserved in dataset |
| 9 | Lough Muckno Leisure Park | — | ✅ **NEW**: /monaghan/lough-muckno-leisure-park (located via DI sitemap; Castleblayney; Free to visit/Family/Dog/Free parking; "open all year") | — | resolves Stage B ⚠ gap |
| 10 | JFK Arboretum | ❌ heritageireland.ie OPW page 404 (re-confirmed) | ✅ /wexford/the-john-f-kennedy-arboretum (Y34 KA48; Paid parking; tours Mar–Oct) | — | unchanged |
| 11 | Cavan Cathedral | ❌ diocese parish/cathedral page not locatable | ❌ no DI page (DI sitemap) | — | **left empty — nothing invented**; identity re-confirmed via encyclopedic check |
| 12 | National Design & Craft Gallery | ✅ ndcg.ie (identity confirmed; site now also branded "DCCI Gallery" — same attraction, documented; hours not published on fetched pages) | ❌ no DI page (DI sitemap) | — | unchanged |
| 13 | Knocknarea (existing) | — | ✅ **NEW**: /sligo/queen-maeve-trail (exact-name match; Strandhill; Free car parking tag; second DI page /sligo/knocknarea-walking-trail also verified live) | — | resolves Stage B improvement #2 |

URL changes vs Stage B: **3 URL substitutions/additions** (Old Head DI URL; Lough Muckno DI page; Dursey DI page) plus the Knocknarea improvement URL. **2 fields intentionally left empty** (Forty Foot official/DI; Cavan Cathedral official/DI). No guessed URLs.

<!-- CONTINUED -->

---

## 3. Final data (summary)

- **Titles/slugs**: `chester-beatty`, `forty-foot`, `derrigimlagh`, `joyce-tower-museum`, `the-model`, `doagh-famine-village`, `dursey-island`, `old-head-of-kinsale`, `lough-muckno-leisure-park`, `jfk-arboretum`, `cavan-cathedral`, `national-design-craft-gallery` (all unique; no `/lazer/` slug collision).
- **Counties**: Dublin (2), Galway, Sligo, Donegal, Cork (2), Monaghan, Wexford, Cavan, Kilkenny — all verified against live sources; no county inferred from a nearby city.
- **Addresses**: stored only where authoritative (Eircode-backed: Chester Beatty D02 AD92, Joyce Tower A96 FX33, The Model F91 TP20, Doagh F93 PK19, JFK Y34 KA48, NDCG R95 CAA6; DI contact Ballinaboy; place-level Forty Foot/Old Head per Stage B). Left **empty** for Dursey, Lough Muckno ("Concord Road" not authoritative) and Cavan Cathedral (no verified street address).
- **Free/paid** (one value each): `Gratuito` ×8 (Chester Beatty, Forty Foot, Derrigimlagh, Joyce Tower, The Model, Lough Muckno, Cavan Cathedral, NDCG); `Gratuito em determinadas condições` ×3 (Dursey, Old Head, JFK Arboretum); `Pago` ×1 (Doagh). No prices invented.
- **Year-round**: all 12 qualify — the physical destination is legitimate year-round in every case. Doagh is the only B-class (permanent place, seasonal operator hours, recorded in its practical notes); Chester Beatty's 2026 EU-Presidency closure is documented as temporary in its practical notes.
- **Descriptions**: original Conexão BR PT copy (excerpt + 2 short paragraphs), factual only, no source prose reproduced.
- **Ireland.com URLs** (Derrigimlagh, Dursey, Old Head): recorded in the dataset JSON. The existing Lazer model has no Ireland.com meta field, and adding one would be a schema change out of scope; the Ireland.com URL remains the verified practical source on the Dursey record (verification metadata).

## 4. Taxonomy & characteristics

Only existing `conexao_category` terms (Museus ×3, Praias, História, Patrimônio ×2, Ilhas, Natureza ×2, Jardins, Cultura). **One primary category per record** (the existing expansion data model and archive filters are single-category; smallest set = smallest change). **No new categories created; no taxonomy definitions modified.**

Attributes assigned only with verified evidence: transport (Chester Beatty, Forty Foot, Joyce Tower), accessibility (Chester Beatty, The Model — Joyce Tower explicitly NOT accessible, attribute withheld per Stage B), parking (Forty Foot, Doagh, Old Head, Lough Muckno, JFK), pet friendly (Doagh, Lough Muckno, JFK), family (The Model, Doagh, Dursey, Lough Muckno, JFK — Dursey from the verified DI "Family friendly" tag), bicycle (Derrigimlagh), plus the free/paid term per record. Legacy meta (`_leisure_parking`, `_leisure_pet_friendly`, `_leisure_accessibility`, `_leisure_family`) written alongside the canonical `conexao_leisure_attribute` terms for transition compatibility (same convention as the existing seed).

## 5. Internal/external classification (§11/§22)

- **External (302 from `/lazer/{slug}/`)**: chester-beatty, derrigimlagh, joyce-tower-museum, the-model, doagh-famine-village, dursey-island, old-head-of-kinsale, lough-muckno-leisure-park, jfk-arboretum, national-design-craft-gallery (10).
- **Internal**: forty-foot, cavan-cathedral (2 — no official/DI URL exists, so the model classifies them internal; nothing was force-externalized). `_leisure_internal_page` = false on all 12 new records.
- **Knocknarea**: stays internal — the new DI URL is display-only via the Phase 3B `_leisure_internal_page` flag, so adding the reference link cannot trigger the external redirect and the single page (existing local image + content) is preserved unchanged except for the 4 approved improvements.

<!-- CONTINUED-2 -->

## 6. Local implementation (exact counts)

Applied with the existing seed/migration architecture — `scripts/seed-leisure-expansion.php` extended to load `scripts/data/leisure-expansion-data-3.php` (new optional keys: `address`, `free_status`, `accessibility`, `transport`, `bicycle`, `practical_*`; Discover Ireland URLs added to the duplicate-prevention index). **No new CPT, table, taxonomy, meta field or import infrastructure.**

- **Records created: 12** (local IDs 13860–13871, all `publish`)
- **Records updated: 1** (`queen-maeves-trail-knocknarea`, ID 10736 — 5 field changes implementing exactly the 4 approved improvements; see §8)
- **Records skipped: 0** (of the Stage C dataset)
- **Duplicates detected: 0** within the Stage C dataset; 178 pre-existing expansion records correctly skipped as already-imported duplicates when the seed re-ran over parts 1/2 (dedup verified working)
- **Errors: 0** (seed summary: Created 12 / Failures 0)

Local post-import audit: `wp eval-file scripts/verify-lazer-stage-c.php` — **242 checks, 0 failures** (identity, taxonomy, geography, attributes, links, classification, deterministic map, practical metadata, no imported images, description sanity, all-leisure duplicate scan, Knocknarea checks). Output ends `STAGE C LOCAL AUDIT PASSED`.

## 7. Deduplication (§17)

Before each create, the extended seed checked: slug, normalized title, official URL, **Discover Ireland URL** (new in the index), and Jaccard token-overlap ≥ 0.80 against all existing leisure posts (any status, incl. trash) and intra-dataset records. **Duplicate count: 0** — all 12 Stage B `NEW` records were confirmed genuinely absent from the 233 pre-existing records. The Stage B finding for Knocknarea (already exists as `queen-maeves-trail-knocknarea`) was honoured: improvement path, no second record.

## 8. Knocknarea improvement (before/after — exactly the 4 approved items)

| Field | Before | After |
|---|---|---|
| `_leisure_town` | `Sligo` | `Strandhill` |
| `_leisure_discover_ireland` | (empty) | `https://www.discoverireland.ie/sligo/queen-maeve-trail` |
| `_leisure_internal_page` | (empty) | `1` — required by the model so the record keeps its internal page with the new DI URL as display-only reference (single-page behavior unchanged, §22) |
| `_leisure_parking` + `Estacionamento` attribute | (empty / not assigned) | `1` / assigned (DI: "Free car parking" tag + car park on the trail) |
| Map | (no deterministic target) | no stored URL — render-time derivation now resolves to "Queen Maeve's Trail (Knocknarea), Strandhill, Co. Sligo, Ireland" |

Not touched: title, slug, category, county, excerpt, content, image (local attachment 10737), free/outdoor flags. Script is idempotent (second run: "Applied changes: 0"). Both DI candidate pages verified live 2026-10-09; the exact-name match was stored.

## 9. Images (§14)

- **All 12 NEW records: decision C — no image.** No external images downloaded; no source-site images, logos or DI/Ireland.com images reused; no Commons rights path was verified in Stage C, so the Stage B "probable Commons candidate" status was not actioned. Verified post-import: `_leisure_image_attachment_id` empty on all 12.
- **Knocknarea: decision A — existing image preserved** (local attachment 10737, status `local`), untouched.

<!-- CONTINUED-3 -->

## 10. Front-end QA (local, `http://localhost:8080`)

- **Archive `/lazer/`**: all 12 new destinations render (10 on page 1, Chester Beatty + The Forty Foot on page 2 — verified via `/lazer/page/2/`); Knocknarea card correct; **no duplicate cards** (the only repeated `/lazer/{slug}/` occurrences are the 3 links of a single card: image/title/CTA).
- **Cards**: labels, category chips, county chips, attribute chips and external CTAs render through the unchanged shared `template-parts/leisure-card.php` + attribute helper.
- **Single pages**: 10 external records 302-redirect to the correct canonical target (chester-beatty → chesterbeatty.ie; joyce-tower-museum → joycetower.ie; the-model → themodel.ie; doagh-famine-village → doaghfaminevillage.com; dursey-island → DI; old-head-of-kinsale → DI; lough-muckno-leisure-park → DI; jfk-arboretum → DI; national-design-craft-gallery → ndcg.ie; derrigimlagh → DI). No loops; no accidental internal pages.
- **Internal pages** (forty-foot, cavan-cathedral, knocknarea): HTTP 200; Knocknarea shows "Ver no Discover Ireland" display link and keeps its page/image.
- **Mobile layout**: rendered through the unchanged responsive archive/single CSS (viewport meta present; no page-specific CSS added — architecture rule §6 honoured).
- **Dark mode**: `dark-mode.css` is part of the enqueued chain on `/lazer/` (confirmed inside the combined stylesheet bundle `data-handles="… conexao-leisure, conexao-dark-mode …"`); new records use the same design-system variables, so dark rendering is unchanged.
- **Accessibility**: unchanged helpers render descriptive `aria-label`s ("Ver localização de {nome} no mapa"), listbox semantics in filters, tabbable card titles; no markup regressions introduced (no template code changed).

## 11. Filter QA (§21 — semantics unchanged)

| Filter | Result |
|---|---|
| `?county=cork` | Dursey Island + Old Head of Kinsale only |
| `?categoria=museus` | Chester Beatty + Joyce Tower + The Model (+ pre-existing museum records) |
| `?atributo=gratuito-em-determinadas-condicoes` | Dursey Island + Old Head of Kinsale + JFK Arboretum only (exactly the 3 approved conditional-free records) |
| Combination `?county=cork&atributo=gratuito-em-determinadas-condicoes` | Dursey + Old Head (AND across dimensions works) |
| Combination `?categoria=museus&atributo=gratuito` | Chester Beatty + Joyce Tower + The Model |
| Clearing filters | base `/lazer/` returns the full archive |
| Pagination | `/lazer/page/2/` serves the overflow (contains Chester Beatty + The Forty Foot); filter URLs keep the no-pagina convention |

No filter code was modified during Stage C.

## 12. Related-content regression (§23)

- Knocknarea single page "Outros lugares relacionados": Coumshingaun Loop, Drumcliffe, Mount Leinster & Nine Stones — all **internal** records; self excluded from the cards; no external record listed (the 10 new external records never appear as related cards — verified via the related selector's use of `conexao_leisure_external_url()`).
- `test-leisure-related-events.php` passes (19 assertions, incl. "external (redirecting) record returns empty").
- Maximum related count (3) and internal-only selection logic untouched.

## 13. Performance / query regression (§24)

- **No per-card remote requests**: grep over the leisure render path (archive.php, single-leisure.php, template-parts/leisure-card.php, functions.php, inc/seo.php) finds **zero** `wp_remote_*` calls; map URL and authoritative links are pure string derivations at render time; new records have no image attachment and no external media.
- **No N+1**: cards render from the main archive query; meta/terms are bulk-primed per page (WP core meta/term caches) inside the shared card template.
- **Local render timing** (`/lazer/`, uncached curl): 0.16–0.28 s total, ~113 kB HTML — unchanged profile with 245 published records.
- Infinite-scroll/filter behavior unchanged (no JS/CSS/PHP touched in Stage C).

<!-- CONTINUED-4 -->

## 14. Regression tests (§25)

Full relevant suite run via `docker compose exec wordpress php wp-content/themes/conexao-br-irlanda/tests/…`:

| Test | Result |
|---|---|
| test-leisure-attribute-normalization.php | **22 passed, 0 failed** |
| test-leisure-multiselect-filters.php | **62 passed, 0 failed** |
| test-leisure-related-events.php | **19 passed, 0 failed** |
| test-sponsor-archive-ordering.php | **15 passed, 0 failed** |
| **Total** | **118 passed, 0 failed** |

No existing test was weakened or removed; no new behavior required new tests (all Stage C data flows through existing model/render paths; `scripts/verify-lazer-stage-c.php` provides the record-level verification).

## 15. Production safety

- Production WordPress **untouched**: every WP write command in this stage ran against the local Docker stack (`wordpress-website-wordpress-1`, `http://localhost:8080`); no production URL, credential or deployment script was used.
- No production data modified; no production images created; no production taxonomy changes.
- No `.htaccess`/routing changes; no plugin/theme code changes (`git status`: only `scripts/` additions/extensions + `docs/importers/` artifacts).

## 16. Stage D handoff

Stage C **prepares and validates the final local dataset only** (12 NEW + 1 improvement, verified end-to-end locally). **Production deployment is a separate stage (Stage D)** and must not be attempted as part of Stage C. The frozen dataset for Stage D is `docs/importers/lazer-expansion-stage-c-final-dataset.json` (13 records), backed by `scripts/data/leisure-expansion-data-3.php` and the idempotent Knocknarea script.

---

LAZER EXPANSION STAGE C PASSED




