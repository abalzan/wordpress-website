# CONEXÃO BR IRLANDA — English Homepage Hero Sponsors

## 1. Status and scope

**Local implementation: PASS. Production deployment: NOT EXECUTED.** This Stage 7.x change is WordPress-only. Flutter/mobile was not accessed, inspected, modified, built or tested. The production site is not claimed fixed because this environment has no production write path and the live `/en/` currently 301s before the English theme layer is deployed.

The smallest change is in `wp-content/themes/conexao-br-irlanda/functions.php`: the existing featured-sponsor secondary query now applies the same approved B2 selection already used by EN sponsor archives and REST collections. The existing renderer, markup, CSS, JavaScript, sponsor metadata, media handling, and external links are unchanged.

## 2. Phase 0 — measured reproduction

### Production read-only baseline (2026-09-24)

| URL | HTTP | Hero sponsors present | Carousel present | Observed result |
|---|---:|---:|---:|---|
| `https://conexaobr.ie/` | 200 | Yes (1 wrapper) | Yes (1 hero carousel) | Existing PT homepage and carousel render |
| `https://conexaobr.ie/en/` | 301 → PT event | No valid EN homepage | No valid EN homepage | Live English layer is not deployed; this is not a valid live defect reproduction |

Production could not be used as the post-fix rendered acceptance target: `/en/` is redirected before the repository theme code is present.

### Local Docker baseline before the fix

| URL | HTTP | `.hero-sponsors` | `sponsors-carousel--hero` | Slides | Tiles |
|---|---:|---:|---:|---:|---:|
| `http://localhost:8080/` | 200 | 1 | 1 | 3 | 3 |
| `http://localhost:8080/en/` | 200 | 0 | 0 | 0 | 0 |

Local sponsor inventory measured before the fix: **3 published sponsors**, all PT-language records; **0 published EN sponsor translations**. All 3 were featured, so the EN absence was not an empty editorial dataset.

## 3. Exact defect trace

1. `front-page.php` calls `conexao_get_featured_sponsors()` before rendering and sets `$hero_has_sponsors`.
2. `template-parts/featured-sponsors.php` calls the same helper and returns when its result is empty.
3. Before the fix, the helper used a language-scoped cache key but its `WP_Query` had no B2 language scope. Polylang narrowed the EN request to EN records, yielding 0.
4. The approved Sponsor B2 architecture says an EN request receives EN records plus untranslated PT records, with a PT master replaced by its published EN translation.
5. The fix applies that rule only on the EN requested-language path. The PT query remains unchanged.

This is a server-side PHP/data-selection defect, not a CSS or JavaScript defect. The existing JS was not changed.

## 4. Change made

`conexao_get_featured_sponsors()` now builds `$query_args` and, only when the requested language is EN:

- sets `lang` to `en,pt`;
- calls the existing `conexao_b2_translation_replaced_pt_ids( array( 'sponsor' ) )`;
- excludes PT identities replaced by linked EN translations.

The renderer still uses the same classes and data attributes:

- `hero-sponsors`
- `sponsors-carousel sponsors-carousel--hero`
- `sponsors-carousel-stage`
- `sponsors-carousel-viewport`
- `sponsors-carousel-list`
- `sponsors-slide`
- `sponsor-tile`
- `sponsor-tile-logo`
- `sponsor-tile-name`
- `sponsors-carousel-arrow`
- `sponsors-carousel-dots`

The existing gettext catalog already contains English equivalents for all requested carousel labels: `Featured supporters`, `Previous supporter`, `Next supporter`, `Browse supporters`, `Go to Supporter N`, and `Sponsor N of M`. No catalog change was needed.


## 5. Cache, links, media and identity behavior

- Cache key: `conexao_home_sponsors_pt` / `conexao_home_sponsors_en` via the existing `conexao_lang_cache_key()`.
- Invalidation: existing `conexao_flush_language_cache( 'conexao_home_sponsors' )` remains in the save/delete/insert invalidation hub.
- PT sponsor data, IDs, metadata, order, titles, images and links were not changed.
- EN B2 fallback links use the source sponsor’s existing canonical permalink; no `/en/` prefix is fabricated.
- Linked EN sponsor records use their own `get_permalink()` result through the existing data path.
- The existing `conexao_sponsor_carousel_image()` output and all image attributes remain unchanged.
- The temporary PT/EN test pair used no attachment and proved that only the EN translation is selected; it was force-deleted and no `[PH STAGE7]` fixture remains.

## 6. HTTP acceptance matrix — local after fix

| Surface | HTTP | Hero wrapper | Hero carousel | Slides/tiles | Language/UI | Canonical |
|---|---:|---:|---:|---:|---|---|
| `/` | 200 | 1 | 1 | 3 / 3 | PT labels preserved | `/` |
| `/en/` | 200 | 1 | 1 | 3 / 3 | EN labels: Featured supporters; Previous supporter; Next supporter; Browse supporters; Go to Supporter 1–3; Sponsor 1–3 of 3; no PT carousel-label leakage | `/en/` |
| `/en/blog/` | 200 | n/a | n/a | n/a | regression route passes | unchanged |
| `/en/jobs/` | 200 | n/a | n/a | n/a | regression route passes | unchanged |

The existing JavaScript passed `node --check`, and its unchanged data bindings (`data-sponsors-carousel`, `data-sponsors-prev`, `data-sponsors-next`, `data-sponsors-dot`) were source-verified. Full browser/device interaction remains not testable in this environment.

## 7. Automated tests

- **Stage 7.x focused source-level test: 10 passed, 0 failed.**
  - EN B2 fallback data is non-empty.
  - No duplicate EN identities.
  - Existing Hero carousel renderer and one slide per selected sponsor.
  - Existing EN gettext strings are verified by the HTTP acceptance request.
  - Temporary linked PT/EN pair selects EN only and excludes the PT master.
  - Cache keys remain language-scoped.
- `php -l`: PASS for changed PHP files.
- `node --check assets/js/main.js`: PASS.
- Changed-file `git diff --check`: PASS.

Regression suites were run but have pre-existing local dataset failures unrelated to this change: Polylang foundation reports a shared county/town assignment failure; Blog translation reports PT 42 / EN 0 and missing EN posts page data; Job translation reports PT 1 / EN 0. The Stage 7 focused test and the relevant HTTP routes pass. Those unrelated records were not changed.

## 8. Required exact metrics

| Metric | Value |
|---|---:|
| PT sponsors eligible for hero | 3 |
| EN sponsors eligible for hero | 3 (B2 result; 0 dedicated EN translations) |
| EN translated sponsors used | 0 |
| PT/B2 sponsors used on EN | 3 |
| Duplicate sponsor identities | 0 |
| EN hero slides rendered | 3 |
| PT hero slides rendered | 3 |
| EN hero UI strings verified | 6 label families / 10 rendered instances |
| Sponsor links verified | 3 EN Hero links + 3 PT Hero links |
| Sponsor images verified | 3 EN tiles, existing image renderer; 3 PT tiles unchanged |
| PT regression changes | 0 data/content changes; PT carousel remains 3 slides |
| HTTP checks passed | 4/4 local target checks (`/`, `/en/`, `/en/blog/`, `/en/jobs/`) |
| HTTP checks failed | 0 local target checks |
| Automated tests passed | 10/10 focused Stage 7 test; syntax/JS checks pass |
| Production deployment | NOT EXECUTED — no production write path |
| Final classification | **ENGLISH HERO SPONSORS — PASS WITH LIMITATION** |

The limitation is operational only: the repository change and local rendered `/en/` acceptance pass, but the live WordPress.com site cannot be changed from this environment and its current `/en/` is not yet the deployed English homepage.

## 9. Production operator instructions

1. Build the validated theme artifact with `./scripts/build-theme-zip.sh` (build only; do not upload from this environment).
2. In WordPress.com wp-admin, upload and activate `dist/conexao-br-irlanda.zip` through Appearance → Themes, after the normal Stage 4.3 Polylang/config rollout is present.
3. Clear the site/page cache and the existing language-scoped homepage transients.
4. Verify `https://conexaobr.ie/` first: HTTP 200, PT Hero Sponsors, 3 slides in the current rollout dataset, Portuguese labels and unchanged PT links/images.
5. Verify `https://conexaobr.ie/en/`: HTTP 200, `.hero-sponsors`, `sponsors-carousel--hero`, 3 slides, English labels, no duplicate identities, correct canonical `/en/`, and no PT carousel-label leakage.
6. Test arrows, dots, keyboard focus, live status and autoplay with the existing JS; no JS change is required.
7. Verify `/en/blog/` and `/en/jobs/` remain healthy.

## 10. Files changed

- `wp-content/themes/conexao-br-irlanda/functions.php` — EN B2 hero sponsor query selection only.
- `wp-content/themes/conexao-br-irlanda/tests/test-stage7-hero-sponsors.php` — focused data-selection/renderer regression test.
- `docs/themes/conexao-br-irlanda.md` — documented the EN B2 hero behavior and language-scoped cache.
- `CONEXAO_BR_ENGLISH_HERO_SPONSORS_REPORT.md` — this report.

The pre-existing unrelated working-tree changes in `compose.yaml` and `wp-content/plugins/conexao-page-translation/` were not modified by this stage.

## 11. Final classification

**ENGLISH HERO SPONSORS — PASS WITH LIMITATION**
