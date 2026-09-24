# conexao-job-translation

**Purpose:** turn the Job CPT from the approved **B2 fallback** (`/en/empregos/{pt-slug}/`
rendering the Portuguese record under the English URL + notice) into a **real
English translation**: exactly one linked EN translation per eligible public
Portuguese `job` record. The Jobs landing **Page** is out of scope — Stage 4.5
already created the EN `/en/jobs/` Page; this plugin only *verifies* that pair.

Owner: project maintainer. Introduced by Stage 6
(`CONEXAO_BR_ENGLISH_JOBS_TRANSLATION_REPORT.md`).

| | |
|---|---|
| Folder | `wp-content/plugins/conexao-job-translation/` |
| Admin screen | Tools → **EN Job Translations** |
| WP-CLI | `scripts/stage6-job-translate.php` (`wp eval-file - dry-run json`) |
| Frontend effect | **none** (importer only — the frontend behaviour lives in the theme) |
| Safe to deactivate | yes, after the rollout |
| Depends on | Polylang + the Stage 4.5 Jobs page pair (verified, never created here) |

## What it does

1. **Jobs landing page check** — verifies the PT `empregos` page and its linked,
   published EN `jobs` page exist (the importer refuses to run without them and
   never creates or modifies a page: Stage 4.5 owns the Page layer).
2. **Job records** — for every manifest entry (keyed by the **Portuguese slug**,
   never by local ID), creates or refreshes exactly ONE linked English `job`
   with the authored English title, body, excerpt and meta description
   (`includes/translation-map.php` is the source of truth; the rendered manifest
   lives in `stage6-work/job-translation-manifest.json`).
3. **Verbatim field layer** — copies the shared featured image and every
   `_job_*` meta key actually stored on the PT record (company, location,
   salary, employment type, dates, application URL, source, editorial status:
   proper names, Irish place names, URLs and controlled values are never
   translated). Publication date, author and menu order are preserved.
4. **Relationships** — `pll_set_post_language` + `pll_save_post_translations`,
   verified in BOTH directions before a row is reported successful.
5. **Duplicate gates** — refuses to create a second EN job when the EN slug is
   already used by an unlinked job record; re-runs are idempotent (an existing,
   linked, published EN translation is *verified*, not duplicated, and a drifted
   slug is repaired).
6. **PT regression gate** — every PT job is snapshotted before the run and
   byte-compared after it (`pt_changed` must be 0; the only permitted write is a
   Polylang language backfill for a language-less record).
7. **Audit** — the completeness inventory (below) is printed at the end of every
   run and on the admin screen; the gate is
   `eligible public PT jobs missing EN = 0`.

## Source identity / deduplication

The `job` CPT has **no importer and no source-identity meta** (no source ID, no
UUID — verified in the Stage 6 inventory). Identity is therefore the Polylang
pair itself plus the EN-slug uniqueness probe inside the `job` namespace.

## Audit rows

- total public PT jobs / total public EN jobs
- translated job pairs verified
- eligible public PT jobs missing EN (gate)
- EN jobs missing PT translation
- jobs excluded (documented)
- taxonomy terms used by PT jobs / missing EN (measured 0 — jobs carry no terms)
- Jobs page PT/EN IDs + pair status

## Related theme pieces (Stage 6)

- `inc/empregos-landing.php` — `conexao_empregos_current_jobs()` (the
  language-aware Jobs listing query on the landing template) and the
  language-aware `conexao_empregos_page_url()` (EN job breadcrumbs point at
  `/en/jobs/`).
- `page-empregos.php` — the «Vagas»/“Openings” section listing the real job
  records in the current language (B2 set semantics; PT masters replaced by
  their EN translations are never listed twice).

See `docs/routing.md` § English for the URL contract.
