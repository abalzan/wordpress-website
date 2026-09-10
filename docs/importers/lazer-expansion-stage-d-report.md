# Lazer Expansion — Stage D

## Deployment Preparation + Production Verification (production write BLOCKED — no credentials)

- **Date**: 2026-09-10
- **Authority**:
  - `docs/importers/lazer-expansion-stage-a-audit.md` (PASSED)
  - `docs/importers/lazer-expansion-stage-b-dataset.json` + `docs/importers/lazer-expansion-stage-b-report.md` (COMPLETE)
  - `docs/importers/lazer-expansion-stage-c-final-dataset.json` (frozen, 13 records)
  - `docs/importers/lazer-expansion-stage-c-report.md` (PASSED)
- **Production**: https://conexaobr.ie (`conexaobrirlanda.com` 301-redirects to it; AGENTS.md canonical domain is `conexaobr.ie`)
- **Local**: Docker WordPress, http://localhost:8080

---

## 1. Stage D scope

DEPLOYMENT + VERIFICATION only. No new CPTs, taxonomies, tables, APIs, or parallel
models. No redesign. Frozen set = 12 NEW + 1 Knocknarea UPDATE = exactly 13 records.
No REVIEW/REJECTED candidates. Production is a static/imported dataset; the Event
Importer is never activated on production.

## 2. Stage A/B/C prerequisite verification — PASS

| Check | Result |
|---|---|
| Stage A audit exists | PASS (`lazer-expansion-stage-a-audit.md`, ends `LAZER EXPANSION STAGE A AUDIT PASSED`) |
| Stage B dataset exists, 20 candidates (12 NEW / 4 REVIEW / 1 EXISTING-improvement / 3 REJECTED) | PASS |
| Stage B report exists | PASS |
| Stage C final dataset exists, 13 records (12 NEW + 1 EXISTING) | PASS |
| Stage C report exists, ends `LAZER EXPANSION STAGE C PASSED` | PASS |
| Stage C implementation files exist | PASS (`scripts/data/leisure-expansion-data-3.php` 12 slugs, `scripts/seed-leisure-expansion.php`, `scripts/verify-lazer-urls.php`, `scripts/apply-lazer-stage-c-knocknarea.php`, `scripts/verify-lazer-stage-c.php`) |
| Final dataset vs Stage C report agreement | PASS — 13/13 slugs match the §3 slug list; free/paid split Gratuito×8 / Pago×1 / Condicional×3 matches §3; counties Dublin(2)+Galway+Sligo+Donegal+Cork(2)+Monaghan+Wexford+Cavan+Kilkenny match |
| Stage C local QA state reproducible | PASS — `verify-lazer-stage-c.php` re-run 2026-09-10: **243 checks, 0 failures** (`STAGE C LOCAL AUDIT PASSED`); local published leisure = 245 |
| Stage B REVIEW/REJECTED excluded | PASS — payload scan finds none of National Archives / Arranmore / Dan O'Hara / Duncannon Fort / Galway city / Mullaghmore / Jerpoint Glass |

## 3. Repository / preflight status — PASS (with notes)

- Branch `master`, ahead of `origin/master` by 7 commits (Stage C + event tooling commits), **working tree clean** at inspection start.
- New untracked file added by this stage: `scripts/build-lazer-stage-d-zip.php` (scoped-package builder). `dist/` is gitignored; the ZIP is a handoff artifact, not committed.
- Disk: root `/` 96% used, **7.5G avail** (Stage C "very low" condition persists but does not block: the scoped ZIP is 6775 bytes; container `/tmp` has 51G avail; no cleanup performed, no caches/backups/DBs deleted).
- Local stack healthy: `wordpress-website-wordpress-1` + `db-1` Up; `conexao-leisure-migration` 2.1.0 activated locally for packaging (was inactive by default).
- Eval-file CLI caveat (documented, harmless): repo `scripts/` is NOT bind-mounted into the container (container ships its own older `scripts/` copy), so `wp eval-file scripts/...` fails from the repo path and `wp eval-file` swallows extra args after `--allow-root`. Workaround used: `docker compose cp` the script to `/tmp/` in the container and hard-code the output path. No repo or container code was modified for this.

## 4. Deployment mechanism used (existing project workflow, no new mechanism)

Per `docs/deployment.md` §"Lazer (Leisure/Tourism)" and `docs/plugins/conexao-leisure-migration.md`,
the established local → production transfer is:

1. **Export from source**: `Lazer → Exportar Lazer` (or WP-CLI `run-leisure-migration.php export`) → ZIP with `data.json` + `images/`.
2. **Import to target**: `Lazer → Importar Lazer` → upload ZIP → **dry-run preview** → confirm.
3. **Matching**: stable UUID → slug → title (never WordPress post IDs). Only the `leisure` CPT + its taxonomies + leisure image attachments are touched.
4. **Media**: transferred inside the ZIP as files and restored as local Media Library attachments; Wikimedia metadata is attribution-only, never hotlinked.
5. No IDs/slugs/meta/taxonomies are remapped except by the importer's normal create/update path; taxonomies are matched by name.
6. No WordPress.com credentials, no SSH/WP-CLI access to production, and no REST write credentials exist in this environment — therefore step 2 **on production** is performed by the site operator via wp-admin, not by this agent.

Deliberate scoping decision (narrower than a full export, safer for §6 rules): a full 245-item export would rewrite **every** production leisure record. Instead `scripts/build-lazer-stage-d-zip.php` builds a **scoped 13-item package** (`dist/lazer-stage-d-13-records.zip`, 6775 bytes, `lazer-export/data.json` only, no image files) through the real `Conexao_Lazer_Exporter::build_export()` + `Conexao_Lazer_Importer::import_file(dry_run)` validation. Importer matching confines writes to the 13 frozen slugs (12 creates by slug/title miss, 1 Knocknarea update by slug match). Knocknarea `image_data` is stripped in the package so production performs a pure metadata/text update and never touches attachment 10737.

Operator runbook (production):

```
1. Lazer → Importar Lazer → upload dist/lazer-stage-d-13-records.zip → preview.
   Expected preview: found=13, created=12, updated=1 (Knocknarea), failed=0,
   images_imported=0, images_reused=0, images_missing=0, taxonomies_created=0.
2. Confirm import. Expected: created=12, updated=1, skipped=0, failed=0.
3. Verify: /lazer/ shows 245 records; spot-check §7 field table below.
Rollback (scoped): trash only the 12 created slugs; restore Knocknarea
_leisure_town=Sligo, _leisure_discover_ireland='', _leisure_internal_page delete,
_leisure_parking delete, remove Estacionamento term. Do not touch anything else.
```

## 5. Production baseline (read-only, 2026-09-10) — PASS

Collected via public REST (`/wp-json/wp/v2/leisure`, 3 pages) + live HTTP probes. No production writes.

| Metric | Value |
|---|---|
| Published `leisure` total | **233** (`X-WP-Total: 233`; local = 245 = 233 + 12 new) |
| Internal / external | 46 internal / 187 external (external = official or DI URL set without `_leisure_internal_page`) |
| By county (meta `_leisure_county`) | Louth 7, Carlow 7, Kildare 8, Limerick 7, Tipperary 8, Wexford 9, Waterford 9, Kilkenny 9, Mayo 10, Sligo 8, Donegal 8, Monaghan 6, Cavan 6, Leitrim 6, Roscommon 6, Longford 6, Westmeath 5, Offaly 5, Laois 6, Kerry 15, Cork 14, Clare 14, Galway 14, Meath 12, Wicklow 14, Dublin 14 |
| By category | Castelos 26, Caminhadas 13, Natureza 52, História 56, Jardins 16, Museus 17, Greenways 4, Cidades 6, Aventura 4, Vida Selvagem 7, Praias 10, Ilhas 9, Patrimônio 2, Cultura 10, Vida na Irlanda 1 |
| Frozen 13 in production | **1/13 present**: only Knocknarea `queen-maeves-trail-knocknarea` (ID 10736). All 12 NEW slugs 404 (`/lazer/chester-beatty/`, `/lazer/forty-foot/`) |
| Knocknarea pre-deploy state (ID 10736) | town=`Sligo`, DI=empty, `_leisure_internal_page`=false, `_leisure_free`=`1`, `_leisure_parking`=false, image attachment **10737 status local**, cats=[Caminhadas] |
| Duplicate check (all 233 slugs) | **0 duplicates** |
| `/lazer/` archive | HTTP 200, ~128 kB, `leisure-card` markup present, no fatal errors |
| Filter `?county=cork` | HTTP 200 |
| Filter `?categoria=museus` | HTTP 200, museum records incl. Millmount Fort Museum |
| Combined `?county=cork&categoria=ilhas` | HTTP 200 |
| Sitemap | `GET /sitemap.xml` 200 (Jetpack index → `sitemap-1.xml`, 79 URLs); leisure URLs are theme-rendered, not in the Jetpack sitemap (pre-existing behavior, unchanged) |

## 6. Exact frozen deployment set — PASS

12 NEW + 1 UPDATE; payload slugs identical to the Stage C report §3 list; no REVIEW/REJECTED leakage (scan negative); no duplicate slugs/official/DI URLs/titles in the payload.

| # | Slug | County | Category | Free | Internal? | Official | Discover Ireland |
|---|---|---|---|---|---|---|---|
| 1 | chester-beatty | Dublin | Museus | Gratuito | external (302) | https://chesterbeatty.ie/ | https://www.discoverireland.ie/dublin/chester-beatty |
| 2 | forty-foot | Dublin | Praias | Gratuito | **internal 200** | — | — |
| 3 | derrigimlagh | Galway | História | Gratuito | external | — | https://www.discoverireland.ie/galway/derrigimlagh |
| 4 | joyce-tower-museum | Dublin | Museus | Gratuito | external | https://joycetower.ie/ | https://www.discoverireland.ie/dublin/james-joyce-museum |
| 5 | the-model | Sligo | Museus | Gratuito | external | https://www.themodel.ie/ | https://www.discoverireland.ie/sligo/the-model-home-of-the-niland-collection |
| 6 | doagh-famine-village | Donegal | Patrimônio | Pago | external | https://www.doaghfaminevillage.com/ | https://www.discoverireland.ie/donegal/doagh-famine-village |
| 7 | dursey-island | Cork | Ilhas | Condicional | external | — | https://www.discoverireland.ie/cork/dursey-island |
| 8 | old-head-of-kinsale | Cork | Natureza | Condicional | external | — | https://www.discoverireland.ie/cork/old-head-signal-tower-signature-discovery-point |
| 9 | lough-muckno-leisure-park | Monaghan | Natureza | Gratuito | external | — | https://www.discoverireland.ie/monaghan/lough-muckno-leisure-park |
| 10 | jfk-arboretum | Wexford | Jardins | Condicional | external | — | https://www.discoverireland.ie/wexford/the-john-f-kennedy-arboretum |
| 11 | cavan-cathedral | Cavan | Patrimônio | Gratuito | **internal 200** | — | — |
| 12 | national-design-craft-gallery | Kilkenny | Cultura | Gratuito | external | https://www.ndcg.ie/ | — |
| 13 | queen-maeves-trail-knocknarea | Sligo | Caminhadas | Gratuito | **internal (flag `1`)** | — | https://www.discoverireland.ie/sligo/queen-maeve-trail (display-only) |

ZIP↔final-dataset field audit (title/county/town/address/official/DI/free/practical notes+source/category+attributes/county-taxonomy): **12/12 NEW records byte-identical**. Knocknarea: title `Queen Maeve's Trail (Knocknarea)` and `_leisure_free`=`1` are the pre-existing local values intentionally preserved by the improvement script (the 4 approved improvements are town/DI/parking/map only) — documented deviation, not a defect.

## 7. Deployment execution results — BLOCKED (no production write path)

| Item | Result |
|---|---|
| Export payload validated | PASS — `STAGE-D PACKAGE OK`: 13 items, dry-run found=13, errors=0 (local preview: 13 updates since all exist locally) |
| Payload contains exactly the 13-record set | PASS |
| No REVIEW/REJECTED record present | PASS |
| No unrelated leisure record included | PASS (245 → 13 scoped) |
| No duplicate source URLs/slugs | PASS |
| Destination accessible and healthy | PASS (read-only HTTP 200s; REST 200) |
| Required runtime/plugins active on production | NOT TESTABLE (no authenticated access to production wp-admin; public REST shows `leisure` CPT + `conexao_category/county/tag` taxonomies present, which is the surface the importer needs) |
| **Production write (the actual import)** | **BLOCKED — no production credentials exist in this environment** (no wp-admin login, no SSH/WP-CLI, no REST application password; `env` shows no `WP_*` secrets; repo holds none). Zero production writes performed. |
| Created / updated / skipped / rejected / errors | **0 / 0 / 0 / 0 / 0** (nothing written) |
| Created post IDs / Knocknarea ID | none (production); local reference IDs 13860–13871 + Knocknarea 10736 |
| Attachment changes | none |

Per §6 rules, a full-dataset push was deliberately NOT attempted, and no alternative
write mechanism (direct DB, REST POST without credentials, ad-hoc scripts on production)
was invented. The handoff artifact `dist/lazer-stage-d-13-records.zip` (gitignored,
6775 bytes) + the §4 runbook is the complete deployment preparation.

## 8. Post-deployment field verification — BLOCKED

Cannot verify production records that do not yet exist. The production baseline (§5)
plus the ZIP↔final-dataset audit (§6) is the complete pre-deploy evidence. Expected
post-import state once the operator runs the import: 245 published (233 + 12),
Knocknarea town=Strandhill + DI set + internal flag + Estacionamento, image 10737 intact.

## 9. Duplicate verification — PASS (baseline) / BLOCKED (post-deploy)

- Production 233 slugs: 0 duplicates. Frozen 13 slugs: 12 absent + Knocknarea present exactly once. Payload internal dupes: none. Post-import production re-scan: BLOCKED (awaiting import).

## 10. Image / media verification — PASS (payload) / BLOCKED (post-deploy)

- All 12 NEW payload items: `image_data.filename` empty; importer will create **zero** attachments (`images_imported=0` by construction; package contains no `images/` files).
- Knocknarea: package strips `image_data` and `_leisure_image_attachment_id`, so the import path performs zero image handling; production attachment 10737 (`local`) cannot be altered by this package (importer's `handle_item_image` returns `0/0/0` when filename is empty).
- No scraping/hotlinking/downloading: none performed; outbound link domains are metadata strings only.

## 11. Archive QA — PASS (baseline) / BLOCKED (post-deploy)

- Production `/lazer/` 200, no fatals; the 12 new slugs correctly 404 pre-deploy; Knocknarea 200.
- Local proof (Stage C implementation): 10 new cards on `/lazer/` page 1 (IDs 13862–13871), 2 on `/lazer/page/2/` (13860 Chester Beatty, 13861 Forty Foot); no duplicate cards (3 links per card only).
- Post-import archive check (12 new present, Knocknarea present, no dupes): BLOCKED.

## 12. Filter QA — PASS (baseline) / BLOCKED (post-deploy deltas)

- Production `?county=cork`, `?categoria=museus`, combined `?county=cork&categoria=ilhas` all 200 with correct pre-deploy content; no code changes to filter logic in any stage.
- Local proof: `?county=cork` → Dursey + Old Head; `?categoria=museus` → Chester Beatty + Joyce Tower + The Model (+ pre-existing); `?atributo=gratuito-em-determinadas-condicoes` → exactly Dursey + Old Head + JFK; AND combos + pagination verified in Stage C.
- Post-import production filter deltas: BLOCKED.

## 13. Internal single-page QA — PASS (baseline+local) / BLOCKED (post-deploy)

- Production Knocknarea 200 pre-deploy (Sligo content, no Strandhill/DI yet — correct pre-state).
- Local: `forty-foot` 200, `cavan-cathedral` 200, Knocknarea 200 with `Ver no Discover Ireland` + Strandhill + Estacionamento; `chester-beatty` 302 → `https://chesterbeatty.ie/`.
- Post-import production singles: BLOCKED.

## 14. External redirect QA — PASS (local proof) / BLOCKED (production)

Local Stage C behavior (unchanged code path `conexao_leisure_external_url()`): 10 external records 302 to canonical targets. Production: BLOCKED (targets 404 until import).

## 15. Outbound-link QA — PASS

Live re-check 2026-09-10 (browser UA, following redirects): chesterbeatty.ie 200; DI Derrigimlagh 200; joycetower.ie 200; themodel.ie 200; doaghfaminevillage.com 202 (bot-challenge/accepted, documented); DI Dursey 200; DI Old Head Signal Tower 200; DI Lough Muckno 200; DI JFK Arboretum 200; ndcg.ie 301→200; Ireland.com Dursey 200; DI Chester Beatty 200; DI Joyce Museum 200; DI Model 200; DI Doagh 200; DI Queen Maeve Trail 200. Forty Foot and Cavan Cathedral intentionally carry no outbound links (nothing invented). Doagh 403-to-bots from Stage B/C now resolves as 202 via browser UA — limitation recorded honestly, canonical URL unchanged and correct.

## 16. SEO / indexing QA — PASS (baseline, no changes)

- No SEO code touched in any stage; internal/external model unchanged (`_leisure_internal_page` flag semantics preserved; payload sets it only on Knocknarea).
- Jetpack sitemap index 200 (`sitemap-1.xml`, 79 URLs); leisure deep URLs are theme-rendered rather than Jetpack-sitemap entries — pre-existing behavior, unchanged by this stage. No duplicate indexable URLs, redirect loops, or noindex changes introduced (zero production writes).

## 17. Related-content isolation QA — PASS (local proof) / BLOCKED (production)

Stage C local proof holds (external records never appear in internal related cards; max 3; self excluded; related-events logic untouched; `test-leisure-related-events.php` 19/19). No taxonomy definitions modified; scoped package creates no new terms (all categories/counties/attributes already exist). Production re-check: BLOCKED.

## 18. Performance / runtime QA — PASS (baseline)

- Production: `/lazer/` 0.12 s / 128 kB; filtered 0.6–0.9 s; Knocknarea 0.86 s / 66 kB — no fatals, no regressions possible (zero writes).
- Local render path grep (Stage C): zero `wp_remote_*` in archive/single/card/seo render path; map/authoritative links are string derivations. Scoped package adds no fetching (no images, no code).
- JS console: NOT TESTABLE (no instrumented browser in this environment).

## 19. App QA — NOT TESTABLE (runtime unavailable) with contract validation PASS

- No app runtime exists in this environment; no results fabricated. Public REST contract checked instead: `leisure` type exposed with `conexao_category/county/leisure_attribute/tag`, `meta` (`_leisure_*`), `featured_media`, `link` — the exact surface the app consumes. Payload uses only existing fields/terms, so no schema breakage is possible. Detail/internal-external/image/offline/favorite behavior: NOT TESTABLE until production import + app run.

## 20. Regression-test results — PASS (118/118)

Local Docker, 2026-09-10 (same suite as Stage C):

| Test | Result |
|---|---|
| test-leisure-attribute-normalization.php | 22 passed, 0 failed |
| test-leisure-multiselect-filters.php | 62 passed, 0 failed |
| test-leisure-related-events.php | 19 passed, 0 failed |
| test-sponsor-archive-ordering.php | 15 passed, 0 failed |
| **Total** | **118 passed, 0 failed** |
| `verify-lazer-stage-c.php` | 243 checks, 0 failures |

## 21. Rollback status — NOT REQUIRED (nothing deployed)

Zero production writes → nothing to roll back. Scoped rollback plan is documented in §4 (12 slugs to trash + 5 Knocknarea field restores) and was not exercised. No broad cleanup performed or needed.

## 22. Deviations / limitations

1. **Production import not executed — BLOCKED**: no wp-admin/SSH/REST-write credentials to WordPress.com production exist in this environment. This is an environment limitation, not a dataset or code defect.
2. **Knocknarea title/free-label variance** (§6): pre-existing local values preserved intentionally; not part of the 4 approved improvements.
3. **`conexaobrirlanda.com` vs `conexaobr.ie`**: the former 301-redirects to the latter; all probes used the canonical `conexaobr.ie` per AGENTS.md.
4. **Doagh bot response**: 403 (Stage B/C) → 202 via browser UA; canonical URL unchanged.
5. **Sitemap**: Jetpack index only; leisure deep URLs not individually listed (pre-existing).
6. **JS console + app runtime**: NOT TESTABLE here.
7. **Production plugin state**: NOT TESTABLE without admin access (public REST confirms CPT/taxonomies present).
8. **Disk**: root 96% (7.5G avail) — monitored, not modified; does not block the 7 kB handoff.
9. **Stale container `scripts/` copy**: documented workaround in §3; no code changed because of it.

## 23. Final production counts

| State | Published leisure |
|---|---|
| Production baseline (pre-deploy, verified) | **233** |
| Production after Stage D (this session) | **233 (unchanged — import BLOCKED)** |
| Expected after operator runs §4 runbook | **245** (233 + 12; Knocknarea updated in place) |
| Local (Stage C implementation) | 245 |

## 24. Final classification

Production deployment was **prepared, validated, and packaged** (scoped 13-record ZIP,
field-identical to the frozen Stage C dataset, image-safe, dry-run clean, regressions
green, baseline captured), but the **production write itself is BLOCKED**: this
environment holds no credentials or write path to the WordPress.com production site,
and the stage rules forbid inventing a new deployment mechanism. The handoff artifact
`dist/lazer-stage-d-13-records.zip` + the §4 operator runbook is the complete,
reviewable path to PASSED.

LAZER EXPANSION STAGE D BLOCKED
