# Restore the four missing English page translations (Jobs, Contact, About, Home)

**Date:** 2026-09-28 · **Repository:** `abalzan/wordpress-website` · **Branch:** `i18n`
**Starting SHA:** `726004a2d1abca6dd7ebe35c8a1ca468a24e825d`
**Evidence:** [`docs/evidence/2026-09-28-en-page-restore/`](../evidence/2026-09-28-en-page-restore/)

## Final status

**PASS WITH LIMITATION** — the four EN page translations are created, linked, verified
and idempotent, with **zero PT canonical mutation** and **zero new gate or test
violations**. The limitation is pre-existing and unrelated: the `guide` post type
still has 48 records missing an EN translation, so the translation-completeness gate
remains red on that axis only.

## Scope

Restore four missing EN `page` translations through the **existing** translation
engine. No new engine, no standalone page-creation script, no routing change.

| PT source | PT ID | EN slug | EN title |
|---|---|---|---|
| `empregos` | 11293 | `jobs` | Jobs |
| `contato` | 12 | `contact` | Contact |
| `sobre-nos` | 11 | `about-us` | About Us |
| `inicio` | 9 | `home` | Home |

## Source

- Authored data: `wp-content/plugins/conexao-page-translation/includes/translation-map.php`
  — **not modified**. Every EN title, slug, body and meta description consumed by this
  rollout was already authored there.
- Engine: `wp-content/plugins/conexao-page-translation/includes/apply.php`
  (`conexao_page_translation_run()`), reached through its own documented runner
  `scripts/historical/stage45-translate-pages.php` — the same code the production
  admin screen (Tools → EN Page Translations → Preview / Apply) invokes.
- Run modes: **dry run** (default), `apply`, and the engine's own internal
  PT before/after checkpoint (the `pt_changed` counter).

## Strategy

**Linked EN record** (one EN `page` per PT `page`, joined by
`pll_save_post_translations()`), not an authored EN field. The identity stays with
the PT record; the EN page is a linked translation of the same identity, never a
second identity record. Strategy is identical for all four pages — not mixed.

## Before

Inventory confirmed **four missing EN records, zero conflicts, zero slug collisions**:

| PT | PT ID | EN exists? | EN slug owner | Relationship |
|---|---:|---|---|---|
| `empregos` | 11293 | no | free | none |
| `contato` | 12 | no | free | none |
| `sobre-nos` | 11 | no | free | none |
| `inicio` | 9 | no | free | none |

The permanent gate agreed independently:
`post_type:page:missing_en: 4` with `sample: ["empregos","contato","sobre-nos","inicio"]`
— exactly the four in scope (`baseline-run-tests-full.log`).

## Dry-run

`stage45-translate-pages.php inicio,sobre-nos,contato,empregos` (no `apply`), zero writes:

```
  inicio     → home     WOULD-CREATE   sobre-nos  → about-us  WOULD-CREATE
  contato    → contact  WOULD-CREATE   empregos   → jobs      WOULD-CREATE
Summary: created=0 updated=0 exists=0 errors=0 pt_changed=0 links_localized=0
```

**4 create · 0 conflict · 0 error · 0 writes** — the expected shape. Proceeded.

## Snapshot

- Engine PT checkpoint: captured by the engine itself before and after every run
  (`pt_changed=0` in all three runs).
- Independent field-level snapshot:
  `pt-snapshot-before.json` → `pt-snapshot-after.json`, taken with the engine's own
  `conexao_page_translation_snapshot_page()` plus ID, type, `post_modified`,
  featured media, all meta keys and all taxonomies. Both verified readable.

## Apply

```
inicio     → home     CREATED linked both ways
sobre-nos  → about-us CREATED linked both ways
contato    → contact  CREATED linked both ways
empregos   → jobs     CREATED linked both ways
Summary: created=4 updated=0 exists=0 errors=0 pt_changed=0
```

**Created EN page IDs: 33306 (home), 33307 (about-us), 33308 (contact), 33309 (jobs).**

## Idempotence

Second identical `apply` run: `created=0 updated=0 exists=4 errors=0 pt_changed=0` —
the engine skipped all four as "EN translation already exists (idempotent skip)".
No duplicates, no content mutation, no duplicate relationships.

## PT immutability

Field-by-field diff of the before/after snapshots across all four pages:

```
PT content differences:        0
PT title differences:         0
PT slug differences:          0
PT status differences:        0
PT taxonomy differences:      0
PT metadata differences:      0
PT unintended differences:    0   (content SHA-256, meta keys, post_modified all identical)
```

The only intended change is the reverse-direction Polylang relationship
(`en_translation: 0 → 33306/33307/33308/33309`), which is the rollout's purpose and
is a relationship, not a PT content mutation.

## Polylang

`pll_get_post()` verified **both directions** at creation and again on re-run:

| PT | EN | en→pt | Status |
|---|---:|---:|---|
| #9 `inicio` | #33306 `home` | #9 | OK |
| #11 `sobre-nos` | #33307 `about-us` | #11 | OK |
| #12 `contato` | #33308 `contact` | #12 | OK |
| #11293 `empregos` | #33309 `jobs` | #11293 | OK |

All four EN records: `post_type=page`, `post_status=publish`, language `en`, correct
parent (all top-level), correct template (`page-empregos.php` on the Jobs page),
correct `conexao_meta_description`, and content **byte-identical to the manifest**
after link localisation. Duplicate check: each EN slug has exactly **1** page record.

## Routes

| URL | Before | After | Notes |
|---|---|---|---|
| `/en/jobs/` | `301 → /eventos/jobs-training-fair/` | **`200`** | the 301 was a core 404-guess on a missing EN page (documented in `CONEXAO_BR_ENGLISH_STAGE_4_3_REPORT.md:518`), not a routing rule; it disappears now that the page exists |
| `/en/contact/` | `404` | **`200`** | `lang="en-US"`, self-canonical |
| `/en/about-us/` | `404` | **`200`** | `lang="en-US"`, self-canonical |
| `/en/` | `200` | **`200`** | still the EN front page (`page_on_front`=9 + its EN link) |
| `/en/empregos/` | `302 → /empregos/` | **`302 → /empregos/`** | **B1 preserved, unchanged** |
| `/empregos/`, `/inicio/`, `/contato/`, `/sobre-nos/` | 200/301 | **unchanged** | PT canonical, titles and canonicals identical |
| `/en/blog/`, `/en/guias/`, `/en/lazer/`, `/en/eventos/`, `/en/cursos/` | 200 | **unchanged** | no side effects |

hreflang on each new EN page: `hreflang="en"` → the EN URL, `hreflang="x-default"` →
the PT page. Canonical is the EN URL on every EN page — no fallback is being served
as English, so no `is_fallback` notice applies.

## Navigation

Desktop (Chrome UA) and Mobile (iPhone UA) header markup on `/en/` were extracted
and are **identical**:

```
Jobs    -> /en/jobs/        Contact -> /en/contact/      Home -> /en/
```

Click-through chains resolve with **0 redirect hops** and English titles:
`/en/jobs/` → 200 "Jobs", `/en/contact/` → 200 "Contact", `/en/about-us/` → 200 "About Us".
No language switch, no PT fallback, no unexpected redirect on any of them.

## Gates

All seven permanent gates, `python3 scripts/verify-permanent-gates.py`:

| Gate | Before | After |
|---|---|---|
| taxonomy policy | PASS 18/0, 0 violations | PASS 18/0, 0 violations |
| **translation completeness** | **FAIL — 52 violations** (48 guide + **4 page**) | **FAIL — 48 violations** (48 guide + **0 page**) |
| cache scoping runtime | PASS 9/0 | PASS 9/0 |
| cache scoping static | PASS 2/0 | PASS 2/0 |
| redirect precedence | PASS 15/0 | PASS 15/0 |
| documentation drift | PASS 14/0 | PASS 14/0 |
| i18n catalogue freshness | PASS 8/0 | PASS 8/0 |
| **Aggregate** | 6/7 passed, 84 assertions passed / 2 failed | 6/7 passed, 85 passed / 1 failed |

`post_type:page:missing_en: 4 → 0`. **New violations: 0.** The remaining 48 are the
pre-existing `guide` findings, preserved as visible findings — no gate was modified
and no allowlist was added. `php scripts/generate-registry-docs.php --check` → registry
OK, zero writes.

## Tests

`./scripts/run-tests.sh`, before vs after, same environment:

| Metric | Baseline | After |
|---|---:|---:|
| PHP suites | 60 total, 40 passed, **20 failed** | 60 total, 42 passed, **18 failed** |
| Assertions | 2918 passed, **101 failed** | 2949 passed, **71 failed** |
| Script-contract | 6/6 passed | 6/6 passed |
| HTTP acceptance | 3 total, 1 passed, 2 failed | 3 total, 1 passed, 2 failed |

**Sorted failing-suite diff: 0 new suites, 2 newly passing**
(`test-header-menu-selection.php (en)`, `test-nav-language-context.php` — both were
failing purely because the four EN pages did not exist). The 2 remaining HTTP
acceptance failures (`verify-guides-en-http.py`, `verify-routing-http.py`) are the
pre-existing `guide` translations and Lazer card excerpts — same rows, same
messages as baseline.

## Content regression

No unrelated page changed. PT titles/canonicals for `/empregos/`, `/inicio/`,
`/contato/`, `/sobre-nos/` are byte-identical to baseline; the other five EN
archives render exactly as before; the PT header still links `/empregos/` and
`/contato/` only. Published page count moved 72 → 76, exactly the four new EN pages.

## No catalogue or routing side effects

`git status --short` shows **no change** under `wp-content/`, `wp/`, `scripts/` or
`tests/` — no POT/PO/MO, no routing, no rewrite and no engine code was touched. The
only tracked diff is the regenerated `docs/evidence/2026-09-26-stage-l/gate.json`
(gate output). No rewrite flush was needed.

## Production

**No production writes or deployment were performed.** All work is local Docker
(`localhost:8080`). No production SSH, WP-CLI, database, upload or activation.

## Flutter

**Flutter/mobile repository was not accessed.**

## Limitations

Pre-existing and out of scope, all present **before** this rollout:

1. `guide`: 48 eligible PT records still missing an EN translation — the sole reason
   the translation-completeness gate remains red.
2. `test-stage45-pages.php`: 11 "meta description contains no untranslated Portuguese"
   failures across pages that already had EN translations before this change.
3. `test-stage41-rest-language.php`: fixture-prerequisite failures for event/guide
   fixtures unrelated to pages.
4. `verify-guides-en-http.py`, `verify-routing-http.py`, `test-guide-en-translation.php`,
   `test-blog-en-translation.php`, `test-job-en-translation.php`, `test-jobs-en-language.php`,
   `test-leisure-attribute-normalization.php`, `test-event-location-filters.php`,
   `test-ivvcc-importer.php`, `test-town-sanitization.php`, and the two
   `conexao-leisure-migration` prerequisite failures — all unchanged from baseline.
5. `./scripts/lint.sh` fails on a pre-existing PHPCS baseline growth and because
   `composer` is not installed in this environment. Not caused by this rollout: no
   source file was modified.

## Rollback

The four created page IDs (33306–33309) are recorded above; a rollback is a delete of
those four EN records, which leaves the PT pages — proven unchanged — as the sole
records. The engine's `refresh` path remains available for any future correction of
the authored copy.
