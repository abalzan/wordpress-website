# Stage 6 — Job inventory (dynamic, measured)

Generated from the live production site (public REST + rendered HTML, read-only,
2026-09-24) and cross-checked against the sandbox clone running the repo theme +
plugins. Machine-readable form: `job-inventory.json` (clone WP-side via
`scripts/stage6-job-inventory.php`) + `source/production-job-rest.json`
(production REST capture).

## 1. Records discovered

Production `job` CPT, public REST (`GET /wp-json/wp/v2/job?per_page=100&status=publish`,
`X-WP-Total: 1`):

| ID | slug | title | status | date | permalink |
|---|---|---|---|---|---|
| 10954 | `oportunidades` | Oportunidades | publish | 2026-08-25 | `https://conexaobr.ie/empregos/oportunidades/` |

Non-publish statuses are not publicly observable (REST `status=draft|private|…`
requires auth; nothing else links to such records — the Jobs page, sitemap,
search and archives were all measured). Measured surfaces:

- `/empregos/` (Jobs landing page): **does not list job CPT records** — it is a
  hub page (portrait + Instagram CTA + the unified opportunities directory of
  recruitment agencies / public-sector portals / permit employers). The single
  job is reachable directly (Instagram traffic), unlinked from the landing page.
- Production sitemap (Jetpack, pre-EN-layer): contains `/empregos/` only; the job
  single is absent there (the repo theme's own sitemap — not yet deployed —
  includes jobs at priority 0.7 plus the EN pass for real translations).

## 2. Eligibility classification

| Class | Definition | Count | Records |
|---|---|---|---|
| A | public and user-facing | **1** | `oportunidades` |
| B | public but historical/expired | 0 | — |
| C | draft/private/pending/protected | 0 observable | (not publicly observable) |
| D | technical/import-only | 0 | — |
| E | duplicate/invalid/orphan | 0 | — |

`oportunidades` is public, indexable, renders 200 with the site chrome and has no
expiration gate (`_job_expiration_date` empty; `_job_status` unset — and neither
is consumed by any frontend query: measured, see §4). **PUBLIC JOB = TRANSLATE** →
eligible. No exclusions.

## 3. Record schema (measured)

Job 10954 carries:

- `title`, `content` (Gutenberg paragraph markup), auto `excerpt`, `author`
  283039560, `featured_media` 10959 (`conexao_br_emprego.jpeg`, 1024×1536),
  `template` "" (the shared `single.php` renders jobs; the job portrait image
  size is `conexao-job-portrait`), `menu_order` 0.
- Meta actually stored (all EMPTY strings): `_job_company`, `_job_location`,
  `_job_salary`, `_job_employment_type`, `_job_expiration_date`. Platform fields
  (`advanced_seo_description`, `jetpack_*`, `footnotes`) empty/unused by the theme.
- Taxonomies `conexao_category` / `conexao_county` / `conexao_tag`: **no terms**.
- **No source identity**: no `_job_source_id`, no UUID, no external ID, no
  importer for the `job` CPT anywhere in the data model (verified against
  `conexao-data-model`, `conexao-admin-ux`, `conexao-event-importer`). Job records
  are manual; the dedup identity for this stage is the Polylang pair plus the EN
  slug uniqueness probe.
- Language state on production: none (Polylang is not installed on production yet
  — the Stage 4.3 rollout is pending). In the post-rollout state (the clone) the
  record is `pt` (default-language mass assignment, scripts/stage2-polylang-setup.php).

Admin-UX editing schema (class-config.php `job_config()`) defines more fields than
production uses: `_job_title`/`_job_description` are VIRTUAL aliases of
post_title/post_content (class-fields.php); `_job_requirements` (textarea),
`_job_closing_date`, `_job_application_url`, `_job_source`, `_job_status`
(editorial badge, admin-only). All absent on the measured record.


## 4. Jobs rendering flow (measured, BEFORE)

- **PT landing** `/empregos/` → Page 11086, template `page-empregos.php`:
  eyebrow «Oportunidades», title, portrait (media 10959), body, Instagram CTA
  (`_empregos_link`), unified opportunities directory. No `job` query existed
  anywhere on the page (measured HTML + template trace).
- **PT job single** `/empregos/oportunidades/` → `single.php`; title
  `Oportunidades | Empregos | Conexão BR`; breadcrumb Início › Empregos ›
  Oportunidades (the Empregos crumb uses `conexao_empregos_page_url()`);
  JobPosting schema from the (empty) `_job_*` meta; meta description auto-derived.
- **EN landing** `/en/jobs/` → Stage 4.5 EN Page (slug `jobs`, same template,
  linked pair). Rendered EN chrome (measured in the clone): h1 "Jobs", EN body,
  "See openings on Instagram" CTA, EN filter labels. It contained **no job
  records** — the page never queried them (no language filter was hiding
  anything; there simply was no query).
- **EN job single** `/en/empregos/oportunidades/` → **B2 fallback** (200,
  `lang="en-US"`, PT body, `language-fallback-notice`, canonical → PT URL,
  hreflang pt-BR + x-default only). `job` is in `conexao_b2_post_types()`.
- **Which B2 hook causes that state**: `conexao_should_render_b2_fallback()`
  (inc/polylang.php) returns true for an EN request of a published, untranslated
  B2-type record; `conexao_polylang_language_redirect_is_temporary()` suppresses
  Polylang's 301 for exactly those requests; `single.php` emits
  `conexao_b2_fallback_notice()`.
- **Which query replaces it once EN Jobs exist**: none needed for routing — a
  published linked EN job resolves `/en/empregos/{en-slug}/` natively through
  Polylang, and `conexao_should_render_b2_fallback()` returns false (a real EN
  translation wins). For the landing page, Stage 6 adds the missing
  language-aware job query (`conexao_empregos_current_jobs()`) to the shared
  template — the "existing translated Page → EN Job CPT query → EN Job detail"
  connection this stage is defined to build.

## 5. Taxonomy audit

Public jobs use **zero** terms of `conexao_category`, `conexao_county`,
`conexao_tag` (measured). Nothing to translate; no unused terms were created or
duplicated. County/town shared-geography policy untouched.

## 6. SEO / sitemap state

- Theme `inc/seo.php` owns title (`{title} | Empregos | Conexão BR` — gettext
  "Empregos" → "Jobs"), meta description (`conexao_meta_description` or
  auto-derived), JobPosting schema, breadcrumb, sitemap (jobs priority 0.7 + EN
  pass for real EN records), hreflang via `conexao_object_translation_links()`.
- All of it is language-driven by the record's own Polylang language — no
  job-specific SEO change is required for EN jobs (verified over HTTP).
