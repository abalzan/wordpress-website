# CONEXÃO BR — ENGLISH JOBS: FROM B2 FALLBACK TO REAL ENGLISH JOB CONTENT

**Stage:** 6 (Job translation — real EN Job records + `/en/jobs/`) — WordPress website/theme only.
**Status:** **PASS WITH LIMITATION** — every eligible public Job has a real, linked English
translation; `/en/jobs/` lists the real EN Job records (cards open the EN Job details);
`/empregos/` still lists the PT Jobs; B2 is retired for translated Jobs and preserved for
future untranslated ones; Portuguese originals verified unchanged; local HTTP acceptance
matrices 31/31 (before) and 57/57 (after); in-process suites 134/134 across the four
Stage-6-relevant suites.
**Production:** `https://conexaobr.ie/` — **not modified, not deployed** by this stage (the
repo produces the artifacts; the rollout is an operator step, see §Production rollout).
**Flutter / mobile app:** untouched (no file, contract or REST change in this stage).

---

## 0. Scope and environment

Task: translate the Job CPT into English, retire the approved **B2 fallback** for
translated Jobs (`/en/empregos/{pt-slug}/` rendering the PT record under the EN URL +
notice), and connect the existing Stage 4.5 EN Jobs Page (`/en/jobs/`) to the real EN
Job CPT records — without recreating the Jobs Page, without inventing an archive, and
without touching Flutter, the REST contract, the Blog translation, events, Lazer,
sponsors, courses, guides or the B2 architecture of any other CPT.

| In scope | Out of scope (untouched) |
|---|---|
| `job` CPT records (1 eligible public record), their meta/taxonomy/media, the Jobs landing template's job listing, job breadcrumb language resolution | every other CPT (guide, event, leisure, sponsor, course_provider, post) and their archives/content |
| Jobs routing / canonical / hreflang / sitemap membership / search scoping for job records | the Jobs **Page** records themselves (Stage 4.5 owns both; this stage verifies the pair) |
| Theme language logic that connects Page → EN Job query → EN Job detail | REST contracts (`inc/rest-language.php` untouched; behaviour re-measured, not modified), Flutter app, event runtime, leisure runtime |
| The B2 state machine for the `job` post type only | the approved B2 architecture for event / leisure / sponsor / course_provider |

### Verification environment

This sandbox has no Docker and no PHP preinstalled and no write access to the production
database, so the project's local Docker site could not be started here. Following the
proven Stage 5 methodology, a **faithful sandbox WordPress clone** was built (static PHP
8.4.23 + WordPress 7.1.2 + SQLite drop-in + **Polylang 3.8.9**, the same version as the
project) and seeded with the **real production data read through the public REST API**:
the single published Job (`oportunidades`, ID 10954, its content/featured image), the
Empregos page + its Stage 4.5 EN `jobs` page (from the validated Stage 4.5 manifest
entry), 3 real production Blog posts (for the Stage 5 regression net), the primary nav
menus with per-language assignment, and one directory record so `/empregos/` renders its
real sections. Every claim below is either (a) an HTTP response from that clone,
(b) an in-process WordPress check, or (c) a comparison against the production REST
payloads / raw production HTML (`stage6-work/http-cache/`, `stage6-work/source/`).

Nothing in this report was executed against production; production was only read
(public REST + HTML GET).

---

## 1. Executive summary — phase results

| Phase | Deliverable | Result |
|---|---|---|
| 0 | Dynamic production Job inventory | **PASS** — 1 public job discovered, full schema measured, no hard-coded counts |
| 1 | Eligibility classification | **PASS** — class A: 1 (`oportunidades`); excluded: 0 |
| 2 | Jobs rendering flow traced | **PASS** — templates/queries/B2 hooks measured and documented (§3) |
| 3 | Translation workflow engine | **PASS** — `conexao-job-translation`: dry-run, apply, idempotent, per-row report, both-direction verification, duplicate gates, PT snapshot gate |
| 4 | Human-authored English | **PASS** — 1/1 job, natural English, structure-preserving |
| 5 | Custom field audit | **PASS** — full field policy table, no text-bearing field ignored (§4) |
| 6 | Source identity / dedup | **PASS** — no source-identity mechanism exists for `job` (measured); Polylang pair + EN slug probe is the identity; duplicate creation refused (proven) |
| 7 | Polylang relationships | **PASS** — 1/1 verified in both directions |
| 8 | EN slugs | **PASS** — 1 natural EN slug (`opportunities`), collision-probed live against production |
| 9–12 | Detail routing / `/en/jobs/` query / card + detail correctness | **PASS** — `/en/jobs/` lists the EN job (→ `/en/empregos/opportunities/`); `/empregos/` still lists the PT job; EN detail fully English |
| 13 | Application links | **PASS** — the Instagram application/CTA URL byte-identical on the EN page |
| 14 | Internal links | **PASS** — EN job breadcrumb Jobs crumb → `/en/jobs/` (Polylang-resolved); nav Jobs → `/en/jobs/`; no string prefixing |
| 15 | Search | **PASS** — PT search → PT job only; EN search → EN job only; no duplicates |
| 16 | SEO | **PASS** — EN title, EN meta description, self-canonical, `en-US`, `og:locale en_US`, correct hreflang |
| 17 | Breadcrumbs | **PASS** — Home › Jobs (`/en/jobs/`) › Opportunities |
| 18 | Taxonomies | **PASS** — 0 terms used by public jobs → 0 translated; shared geography untouched |
| 19 | Sitemap | **PASS** — PT job + EN job each exactly once, alternates only for the real pair |
| 20 | Cache | **PASS** — language-scoped transients verified distinct; no new cache system |
| 21 | Manifest | **PASS** — validated manifest, every eligible job exactly once |
| 22–23 | Importer + idempotency | **PASS** — dry-run exact counts; apply created 1; re-runs created 0; orphan-with-EN-slug refused |
| 24 | PT integrity gate | **PASS** — PT changes = 0 (in-run gate + before/after inventory compare) |
| 25 | Completeness scanner | **PASS** — 1 translated, 0 possible-untranslated, 0 empty, 0 flags |
| 26–27 | HTTP acceptance matrix | **PASS** — before 31/31, after 57/57; `/en/jobs/` critical gate proven |
| 28–29 | B2 transition + future jobs | **PASS** — states A and B both proven over HTTP; no code edit per new job |
| 30 | Blog regression | **PASS** — Blog suite 27/27; blog archives/singles 200 in both languages |
| 31–32 | Unrelated systems + production safety | **PASS** — nothing unrelated touched; production write NOT_EXECUTED |

The limitation is the same class as Stages 4.5 and 5: **production deployment is an
operator step** (production currently has no English layer at all — the Stage 4.3
Polylang + theme deployment is still pending there — and this environment has no
production write path: no wp-admin session, no WP-CLI on WordPress.com, and Polylang
Free cannot write `translations` over the public REST API).

---

## 2. Architecture decision — the measured Jobs reality (Phases 0–2)

The task's mental model assumed `/empregos/` lists Job records through a query that a
language filter could empty out. **Measurement showed a different reality**, and the
implementation follows the measurements (never the assumption):

1. `/empregos/` is a WordPress **Page** (`page-empregos.php`, Stage 4.5's translated EN
   counterpart at `/en/jobs/`). Before this stage it contained **no job CPT query at
   all** — its sections are the Instagram CTA and the unified opportunities directory
   (recruitment agencies / public-sector portals / permit employers, which are separate
   admin-only CPTs, out of scope). The single production job (`oportunidades`, an
   Instagram-promo record) was **orphaned** — reachable directly, listed nowhere.
2. Therefore the Stage-6 connection is additive: `page-empregos.php` gains a
   «Vagas»/"Openings" section whose query (`conexao_empregos_current_jobs()`) lists the
   published `job` records **in the current language**, with the approved B2 archive
   semantics (the same contract as `Conexao_Event_Query::is_in_current_language()`):
   - PT request → PT jobs;
   - EN request → EN jobs + untranslated PT jobs as the B2 set; a PT master that has a
     published EN translation is replaced by it (never both languages of one identity);
   - language-less legacy rows stay visible.
   Cards reuse the shared `.archive-grid` / `.archive-card` markup and design tokens
   (no new card CSS); every card links to the record's own permalink (the measured
   behaviour of every other B2 archive — measured live: `/en/apoiadores/` cards of PT
   records link to their canonical PT URLs). The section hides entirely when no
   published job exists. The PT page's content was verified **byte-identical outside
   the added section** (raw-HTML diff of the landing page before/after the theme
   change, `?ver=` cache-busters normalised).
3. **No invented archive** was created: no `/en/job/`, no `/en/jobs-archive/`, no
   `/en/jobs/jobs/`; `has_archive` stays `false`; the Jobs Page pair is untouched
   (Stage 4.5 ownership; the importer *verifies* it and refuses to run without it).
4. The B2 state machine for jobs is unchanged code: `job` stays in
   `conexao_b2_post_types()`; `conexao_should_render_b2_fallback()` automatically
   returns false once a linked EN record exists (real EN wins). What changed is only
   that the landing page now *shows* the records, and the breadcrumb crumb for jobs
   (`conexao_empregos_page_url()`) is language-aware: on a real EN job detail it points
   at `/en/jobs/`; on PT (and in the B2 fallback state) it is byte-identical to before.

Full flow trace with the measured BEFORE states: `stage6-work/job-inventory.md` §4.


---

## 3. Inventory and eligibility (Phases 0–1) — measured values

| Measurement | Value |
|---|---|
| Production public Job records (REST `X-WP-Total`) | **1** — `oportunidades` (#10954, publish, 2026-08-25) |
| Non-publish statuses observable | 0 (REST auth required; no such record is linked anywhere) |
| Clone records (any status) | 1 (publish) — matches production |
| Eligible (class A — public and user-facing) | **1** |
| Excluded | **0** — no documented exclusions were needed |
| Taxonomy terms used by the job | **0** (conexao_category / conexao_county / conexao_tag all empty) |
| Source-identity meta on the job | **none** (no importer exists for `job`; no `_job_source_id`/UUID anywhere in the data model) |
| Meta actually stored | `_job_company`, `_job_location`, `_job_salary`, `_job_employment_type`, `_job_expiration_date` — **all empty strings**; plus Jetpack/platform fields unused by the theme |

Public-visibility evidence for the single record: 200 at `/empregos/oportunidades/`
(production, measured), full site chrome, JobPosting schema emitted, no expiration
(`_job_expiration_date` empty) and no visibility gate (`_job_status` unset and —
measured — consumed by no frontend query). **PUBLIC JOB = TRANSLATE** → 1 eligible.

Artifacts: `stage6-work/job-inventory.json` / `.md`,
`stage6-work/source/production-job-rest.json` (+ empregos page, media 10959, taxonomy
term captures), raw production HTML in `stage6-work/http-cache/`. Builder:
`scripts/stage6-job-inventory.php` (WP-side, dynamic — never hard-codes counts).

---

## 4. Custom field audit (Phase 5) — the field policy table

Every field reachable on the `job` CPT was traced through its PHP/template consumer
(data model registration, admin-ux editing schema, `single.php`, `inc/seo.php`
title/schema/meta, the new landing query):

| Field | Decision | Reason (measured consumer) |
|---|---|---|
| `post_title` | **translate** | visible: h1, `<title>`, cards, breadcrumb current crumb |
| `post_content` | **translate** | visible: single body (the whole Instagram-promo copy) |
| `post_excerpt` | **translate** (authored EN excerpt) | visible: card excerpt, og:description fallback |
| `post_name` (slug) | **new EN slug** (`opportunities`) | URL identity; PT slug never changes |
| `conexao_meta_description` | **translate** (authored) | SEO meta description (`inc/seo.php`) |
| `_thumbnail_id` (featured media) | **copy verbatim (share)** | presentation; media is shared (`media_support=false`), 1080×1920 portrait |
| `post_date` / `post_author` / `menu_order` | **copy verbatim** | record identity/presentation (measured on the EN single) |
| `_job_company` | **copy verbatim** | proper name (empty in production) — JobPosting `hiringOrganization` |
| `_job_location` | **copy verbatim** | Irish place names (empty) — JobPosting `jobLocation` |
| `_job_salary` | **copy verbatim** | structured value (empty) — schema `baseSalary` |
| `_job_employment_type` | **copy verbatim** | controlled vocabulary (empty) — schema `employmentType` |
| `_job_expiration_date` / `_job_closing_date` | **copy verbatim** | dates (empty) |
| `_job_application_url` | **copy verbatim** | URL (empty) — would be the application CTA link |
| `_job_source` | **copy verbatim** | source label (empty) |
| `_job_status` | **copy verbatim** | admin-ux editorial badge only — no frontend consumer (measured) |
| `_job_requirements` | **translate-if-present** | the one text-bearing meta field (empty in production → nothing to do; the importer supports an `en_meta` override and the completeness scanner flags a non-trivial identical copy) |
| `_job_title` / `_job_description` | n/a (virtual) | admin-ux aliases of post_title/post_content (class-fields.php) |
| `advanced_seo_description`, `jetpack_*`, `footnotes` | ignore | platform fields, unused by the theme SEO owner (empty) |
| `conexao_category` / `conexao_tag` | nothing to do | translated taxonomies — 0 terms used by the job |
| `conexao_county` | nothing to do (shared) | geography is shared by architecture — one term per county, never duplicated |

The importer implements exactly this policy: the translate list comes from the manifest;
every `_job_*` key actually stored is copied verbatim at apply time (nothing hard-coded,
no ghost fields invented); the scanner re-checks the result.


---

## 5. The translation (Phase 4) — human-authored English

One eligible record, translated by hand (no machine translation, no placeholders,
structure-preserving — same paragraph/block markup, same `<br>` layout, the external
Instagram href untouched):

| | Portuguese (production) | English (authored) |
|---|---|---|
| Title | Oportunidades | Opportunities |
| Slug | `oportunidades` | `opportunities` |
| Excerpt | (auto) | "At Conexão BR Irlanda, we share new job opportunities every day — and finding openings near you just got even easier! In our Instagram Highlights, the openings are grouped by County…" |
| Meta description | (auto) | "New job opportunities every day for the Brazilian community in Ireland — browse the openings grouped by county on the Conexão BR Irlanda Instagram." |
| Body | Instagram-promo copy: daily opportunities, Highlights grouped by Condado, 4 checklist lines, Instagram link, save/share line, hashtags | faithful 1:1 English rendering of the same 8 paragraphs ("Instagram Highlights", "County", "résumé", "free Guides", hashtags localised to their natural English community forms) |
| Application/CTA URL | `https://www.instagram.com/reel/Dcd0YXqMrEK/?igsi=M3VrZm04aHV4czN6` | **copied verbatim** (external link — never translated, never prefixed) |

Terminology follows Stage 4.5 ("Jobs", "Openings", "Instagram Highlights",
"See openings on Instagram"). Brand/community names (`Conexão BR Irlanda`,
`ConexaoBRIrlanda` hashtag) and the Instagram URL are untouched per the
never-translate list. Source of truth: `wp-content/plugins/conexao-job-translation/includes/translation-map.php`.

---

## 6. Slugs, routing, relationships (Phases 7–9)

- **EN slug `opportunities`**: lowercase URL-safe, deterministic, no `-en`, no `-2`,
  not the PT slug. Collision probe against the live production site (public REST, 8
  endpoints: pages, posts, jobs, media, categories, conexao_category, conexao_county,
  conexao_tag) → free everywhere. In-scope uniqueness enforced again at apply time
  inside the `job` namespace.
- **Routing (unchanged architecture)**: PT job `/empregos/oportunidades/`; EN job
  `/en/empregos/opportunities/` (Polylang prefix on the CPT rewrite slug — the same
  shape Stage 3.2 measured for pilot EN jobs). Wrong-language requests follow the
  established behaviour, re-measured: before translation `/en/empregos/oportunidades/`
  renders the approved B2 fallback (200, EN shell, PT body, notice, canonical → PT);
  after translation it redirects (301) to the PT job — the PT master is replaced, and a
  PT request can never render the EN body (measured: PT page PT title/PT body/pt-BR).
- **Polylang relationships**: created with `pll_set_post_language` +
  `pll_save_post_translations` (the real Polylang mechanism — no simulated custom
  metadata), verified in both directions in the importer row report, the audit, the
  in-process suite and the HTTP matrix (switcher PT → PT URL; hreflang pairs).

---

## 7. `/en/jobs/` — the critical acceptance (Phases 10–11, 27)

Before this stage `/en/jobs/` was a real EN Page (Stage 4.5) with **no job records on
it** (there was no job query to filter). After this stage, measured over HTTP:

| Requirement | Measured result |
|---|---|
| `/en/jobs/` lists the EN jobs | ✅ 1 EN job listed ("Opportunities"), EN section title "Openings", EN excerpt, EN card labels |
| every card opens the EN job detail | ✅ card link → `/en/empregos/opportunities/` (200, en-US) |
| no PT job as the primary card | ✅ zero PT cards once translated (B2 card only while untranslated — by design) |
| no job appears twice | ✅ 1 card, no duplicates |
| not empty due to language filtering | ✅ the query curates the B2 set instead of letting the language filter empty the page |
| `/empregos/` still lists the PT jobs | ✅ PT card ("Oportunidades") → `/empregos/oportunidades/`, pt-BR, unchanged content outside the new section |
| preserved ordering/layout | ✅ newest-first (date DESC), shared archive card markup, `.empregos-jobs` section uses the same design tokens/section rhythm as the existing opportunities section; hidden entirely when empty |
| cache separation | ✅ `conexao_empregos_current_jobs_pt` = [PT id], `conexao_empregos_current_jobs_en` = [EN id], no unsuffixed key; `/en/jobs/` and `/empregos/` bodies differ |

Card-level language correctness on `/en/jobs/`: title EN, excerpt EN, labels EN
("Openings", "1 minute read", EN date), link EN. Official names/identifiers exempt.


---

## 8. Job detail language correctness (Phase 12) — measured on `/en/empregos/opportunities/`

| Element | Measured |
|---|---|
| `lang` / `<title>` / h1 | `en-US` / "Opportunities \| Jobs \| Conexão BR" / "Opportunities" |
| meta row | "August 25, 2026" + "1 minute read" (gettext) |
| body | the authored English copy; **no Portuguese prose** (scanner + HTTP checks) |
| meta description | English (authored) |
| canonical / hreflang | self-canonical; `en` → itself, `pt-BR` → PT job, `x-default` → PT job |
| og tags | og:title "Opportunities", og:url EN, og:description EN, **og:locale en_US** |
| breadcrumb | Home › Jobs (**→ `/en/jobs/`**) › Opportunities |
| language switcher | PT → `/empregos/oportunidades/` (the PT sibling); EN current |
| application/CTA link | Instagram reel URL byte-identical (https, external, untouched) |
| B2 notice | absent (real EN record — the notice only renders in the fallback state) |
| share buttons / related posts | none on job singles (post/guide-only component; identical PT/EN) |
| JobPosting schema | emitted (language-neutral values; empty structured meta stays empty) |

PT job detail (`/empregos/oportunidades/`) remains fully PT: pt-BR, PT title, PT body,
PT date, self-canonical, hreflang pair with the EN job, no notice.

---

## 9. Search, sitemap, REST (Phases 15, 19, 20)

- **Search**: `/?s=oportun…` finds the PT job and surfaces no EN URL;
  `/en/?s=opport…` finds the EN job and surfaces no PT-URL duplicate — no PT+EN
  representation of one identity in either language's results.
- **Sitemap (theme `inc/seo.php` — the single SEO/sitemap owner, unchanged)**: the PT
  job and the EN job each appear exactly once (`/empregos/oportunidades/`,
  `/en/empregos/opportunities/`), plus the page pair; hreflang `xhtml:link` alternates
  exist only on the real pairs; no B2/redirect-only URL is listed. Production's current
  Jetpack sitemap policy (job single absent) is a production-host state, not a theme
  policy — once the theme deploys, the theme sitemap governs (jobs included).
- **REST contract (`inc/rest-language.php` — untouched)**: re-measured after the
  translation: `?lang=en` → the EN job only (the translated PT master replaced, never
  duplicated); `?lang=pt` → the PT job; no `lang` → unfiltered pre-Stage-4.1 behaviour.
  Scope was not expanded.

---

## 10. B2 transition + future jobs (Phases 28–29) — both states proven over HTTP

| State | Setup (live probe) | Measured result |
|---|---|---|
| **A — no EN translation** (future job) | throwaway PT job `s6-b2-http-probe` created via WP-CLI, deleted after | `/en/empregos/s6-b2-http-probe/` → **200 B2**: `en-US` shell, B2 notice, PT body, canonical → PT; the probe **appears on `/en/jobs/`** as a B2 card linking to its canonical PT URL; gone from the listing immediately after deletion |
| **B — published EN job** (this stage's record) | the real translated pair | `/en/empregos/opportunities/` → **200 real EN** (EN body, no notice, self-canonical); `/en/empregos/oportunidades/` (PT slug under /en/) → 301 → PT job (replaced master); the EN listing shows the EN card only |

`job` remains in `conexao_b2_post_types()`; `conexao_should_render_b2_fallback()` is
unchanged code; no other CPT's B2 behaviour was touched (their suites/mechanisms
untouched). **No code edit is needed for each new job** — future PT jobs automatically
get state A; creating their EN translation (linked, published) automatically moves them
to state B.

---

## 11. Migration engine, manifest, idempotency, PT gate (Phases 3, 21–24)

- **Engine**: `wp-content/plugins/conexao-job-translation/` (admin screen Tools → EN
  Job Translations + WP-CLI runner `scripts/stage6-job-translate.php`), modelled on the
  proven Stage 5 importer with job-specific isolation: no posts-page machinery, no page
  creation (the Jobs page pair is *verified* — the importer refuses to run without it),
  dynamic `_job_*` copying, slug-drift repair.
- **Manifest**: `stage6-work/job-translation-manifest.json` — every eligible PT job
  exactly once (pt_id 10954, slugs/titles, EN slug/title, field policy, meta
  description, taxonomy mapping, featured media, translator notes). Validated by
  `scripts/stage6-job-validate.py` (structure, coverage vs production, slug format,
  live collision probe) → PASS.
- **Dry run → exact counts**: `jobs: created 1, updated 0, skipped 0, errors 0`;
  `jobs page: verified`; `PT sources changed 0`.
- **Apply run 1** (original): created 1, errors 0, meta fields copied 1
  (`_thumbnail_id` — the only non-empty verbatim field), PT changed 0, gate PASS.
- **Idempotent re-runs** (`apply-run-1.json`, `apply-run-2.json`, plus the earlier
  post-restore re-run): `created 0, skipped 1 (exists), errors 0, pt_changed 0` —
  no second EN job, no duplicate identity.
- **Recovery / duplicate gates**: an existing valid EN translation is *verified*, not
  replaced; an **orphaned record already holding the EN slug** (broken link state,
  simulated live) → hard error, nothing created
  ("EN slug already used by another job record (#…) — refusing to create a duplicate").
- **PT integrity gate**: in-run byte-snapshot compare (`pt_changed = 0`) plus the
  before/after inventory compare (`scripts/stage6-job-pt-snapshot.py` →
  `stage6-work/pt-snapshot.json`: **PT records 1/1, PT changes 0, GATE PASS**) —
  title, content hash, excerpt, slug, date, status, author, menu order, thumbnail,
  taxonomy, `_job_*` meta, meta description, language all identical.
- **Completeness scanner** (`scripts/stage6-job-completeness-scan.py` →
  `stage6-work/completeness-scan.{json,md}`): 1 EN job scanned — **translated 1,
  possible-untranslated 0, empty 0, eligible PT missing EN 0, EN without PT 0,
  unreviewed flags 0 — GATE PASS**.


---

## 12. HTTP acceptance matrices (Phases 26–27) — every translated job, no sampling

`scripts/stage6-job-verify.py` (deterministic; writes
`stage6-work/http-matrix-{before,after}.{json,md}`):

- **BEFORE** (post-4.5/5 stack, Stage-6 theme, no EN job records): **31/31** —
  PT landing/lists PT job; `/en/jobs/` real EN page listing the **B2 set** (PT card,
  canonical PT link — never empty); PT job 200 pt-BR self-canonical no-EN-alternate;
  `/en/empregos/oportunidades/` 200 **B2** (notice, EN shell, PT body, canonical → PT);
  sitemap has the PT job only; PT search finds the PT job; blog surfaces intact.
- **AFTER** (translation applied): **57/57** — every row of §7/§8/§9/§10, including
  the `/en/jobs/` critical gate, per-pair hreflang sets, replaced-master redirect,
  breadcrumb targets, no-duplicate listings/search/sitemap, the live B2 state-A probe
  and the blog regression rows.

A 200 OK alone was never sufficient: each gate asserts content, language, canonical,
hreflang targets, listing membership and link targets.

---

## 13. Tests run (exact counts)

| Suite | Result |
|---|---|
| `tests/test-job-en-translation.php` (new, Stage 6) | **29 passed / 0 failed** |
| `tests/test-blog-en-translation.php` (Stage 5 regression — full suite, posts-page/gate/pairs/content/SEO) | **27 passed / 0 failed** |
| `tests/test-i18n-foundation.php` | **29 passed / 0 failed** |
| `tests/test-nav-language-context-logic.php` | **49 passed / 0 failed** |
| HTTP acceptance matrix (before / after) | **31/31, 57/57** |
| `php -l` on every new/changed PHP file (plugin ×4, scripts ×2, test, `inc/empregos-landing.php`, `page-empregos.php`) | clean |
| `python3 -m py_compile` on the four new scripts | clean |
| `git diff --check` | clean |
| Manifest validation (structure/coverage/slug/collision probe) | PASS |
| PT integrity compare | PASS (0 changes) |
| Completeness scanner | PASS (0 flags) |

WP-CLI runs (all successful): inventory builder (before/after), translation runner
(dry-run + apply ×3 + json reports), B2 probe create/delete, transient/cache checks.

Not run (environment-bound, honestly reported): the full local Docker matrix
(`docker compose` unavailable in this sandbox — no Docker daemon), `.htaccess`/Apache
behaviour, visual browser/device rendering, and the pre-existing dataset-existence
suites that need the Stage 3.2 pilot dataset (`test-stage32-bilingual` keeps its
pre-existing dataset-existence failures for records this clone intentionally does not
seed — leisure/sponsor/course pilots; unrelated to Jobs, unchanged by this stage).

---

## 14. Blog regression (Phase 30) — explicit

The Blog translation is completed production architecture; this stage's shared-code
changes were proven blog-neutral: `page-empregos.php` + `inc/empregos-landing.php`
(jobs-page-only template + module), one additive CSS block, one new plugin. Measured
after the Jobs work, in the same clone with the Blog layer applied:

- `/blog/` → 200 pt-BR (PT archive, PT posts); `/en/blog/` → 200 en-US (real EN
  archive, EN posts, no B2 notice); EN blog single (`/en/who-have-you-become-away-from-home/`)
  → 200; PT blog single → 200.
- The **full Stage 5 in-process suite passed 27/27** (posts page pair, completion gate,
  both-direction links, EN content, PT-untouched, categories, slugs, sitemap inputs).
- Blog search/sitemap/pagination surfaces unchanged (the jobs stage touches none of
  their code paths; the sitemap's blog section is independent of the jobs section).
- The only Blog-suite failures observed during setup were clone-dataset artifacts
  (the WordPress `hello-world` sample post, deleted; and one `preg_match` PCRE2
  warning from the static PHP build's `\u` escapes in the *existing* test's PT-prose
  heuristic — the check still passes), not regressions.

---

## 15. Files changed / created (complete list)

**New plugin** `wp-content/plugins/conexao-job-translation/`:
`conexao-job-translation.php` (admin screen), `includes/translation-map.php`
(authored EN + policy), `includes/apply.php` (engine), `includes/audit.php` (gate).

**Theme (shared code, minimal and jobs-scoped)**:
- `page-empregos.php` — the «Vagas»/"Openings" section (shared card markup).
- `inc/empregos-landing.php` — `conexao_empregos_current_jobs()` (language-aware,
  B2-set query, language-scoped transient + save/delete flush) and the
  language-aware `conexao_empregos_page_url()` (job breadcrumb).
- `assets/css/main.css` — one additive block (`.empregos-jobs*`) on design tokens.
- `tests/test-job-en-translation.php` — the Stage 6 suite.
- No gettext catalog change was needed: the section reuses the existing
  "Vagas" → "Openings" catalog entry (verified live).

**Scripts**: `stage6-job-inventory.php`, `stage6-job-translate.php`,
`stage6-job-verify.py`, `stage6-job-pt-snapshot.py`,
`stage6-job-completeness-scan.py`, `stage6-job-validate.py`.

**Artifacts**: `stage6-work/` — job-inventory.{json,md}, job-translation-manifest.json,
pt-snapshot.json, http-matrix-{before,after}.{json,md}, completeness-scan.{json,md},
apply-run-{1,2}.json, source/ (production captures), http-cache/ (raw HTML evidence).

**Docs**: `docs/plugins/conexao-job-translation.md` (new), `docs/plugins/README.md`,
`AGENTS.md` (plugin table), `docs/routing.md` (Empregos/Job-singles EN rows),
`docs/themes/conexao-br-irlanda.md` (landing page section).

**Untouched**: Flutter (never accessed), REST contract, event/leisure/sponsor/course/
guide content + code, Blog translation data + plugin, Stage 4.5 page records, PT job
content, unrelated plugins, unrelated SEO behaviour.


---

## 16. Discovered pre-existing defect (documented, not fixed — out of scope)

While running the wider regression net, `php -l` exposed a **pre-existing parse error**
in the Stage 4.5 tooling plugin
`wp-content/plugins/conexao-page-translation/includes/translation-map.php`
(present at the base commit `d0724e2`, untouched by this branch — verified with
`git show d0724e2:… | php -l`):

- the `'blog'` element of the page map opens a nested `'categorias' => array(…)` and
  **never closes the `'blog'` array itself** (a `),` is missing after the categorias
  entry, around line 155), and a compensating extra `)` sits at the file's end (line 368);
- PHP reports `Parse error: syntax error, unexpected token ";", expecting ")" … on line 291`;
- consequence: that plugin **cannot be activated on any PHP host** — including the
  pending Stage 4.5 production rollout — until repaired (Stage 4.5 was validated in a
  no-PHP environment, which is why it was never caught).

This does **not** affect Stage 6 (the Jobs importer is an independent plugin; the Jobs
page pair is created by the Stage 4.5 runbook step, and the clone seeds it directly
from the validated manifest). Per Phase 31 (do not touch unrelated systems) it was
**not modified** here; the operator fixing Stage 4.5's rollout should add the missing
`),` after the `'categorias'` block (and remove the stray `)` at EOF) and re-run
`php -l` + the Stage 4.5 validation.

---

## 17. Production rollout runbook (operator steps — NOT_EXECUTED here)

Production write access is unavailable in this environment (no wp-admin session, no
WP-CLI on WordPress.com, no REST `translations` capability in Polylang Free). Per the
Stage 4.5/5 production-safety rule, the stage stops after validation and delivers the
package. Prerequisites: the Stage 4.3 English layer (Polylang 3.8.9 + theme + plugins +
settings), then Stage 4.5 (Pages) and Stage 5 (Blog) must already be rolled out — the
Jobs importer verifies the Jobs page pair and refuses to run without it.

1. **Back up** (UpdraftPlus DB + uploads; export the job list).
2. **Deploy the theme** (`dist/conexao-br-irlanda.zip`) — it contains the Jobs landing
   section + breadcrumb change; both are inert/safe before any EN job exists (the
   BEFORE matrix proves the B2 state keeps working, the PT page is unchanged outside
   the new section, and untranslated jobs simply appear as B2 cards).
3. **Deploy + activate** `conexao-job-translation` (`dist/conexao-job-translation.zip`).
4. **Dry run**: Tools → *EN Job Translations* → **Preview (dry run)**. Expect
   `jobs would-create 1`, `jobs page: verified`, `PT sources changed 0`.
5. **Apply**. Expect `created 1`, `errors 0`, `PT sources changed 0`, audit gate
   **PASS** (`eligible public PT jobs missing EN = 0`).
6. **Verify (production)**:
   - `/en/jobs/` → 200 en-US, "Openings" section lists *Opportunities*, card opens
     `/en/empregos/opportunities/` (EN content, no B2 notice, self-canonical, hreflang
     en/pt-BR/x-default, breadcrumb Home › Jobs › Opportunities);
   - `/empregos/` → 200 pt-BR, lists *Oportunidades* → `/empregos/oportunidades/`;
   - `/en/empregos/oportunidades/` → 301 → `/empregos/oportunidades/`;
   - `/?s=oportun…` → PT job only; `/en/?s=opport…` → EN job only;
   - `/sitemap.xml` → both job URLs exactly once with the pair alternates;
   - the Blog surfaces unchanged (`/blog/`, `/en/blog/`, an EN single).
7. **Deactivate** the plugin (no frontend role; the content stays).
8. **Rollback**: trash the EN job record (or restore the DB backup). The PT record is
   never touched; the B2 fallback re-engages automatically the moment the linked EN
   record is gone (proven by the state machine) — `/en/jobs/` returns to showing the
   PT job as a B2 card, and `/en/empregos/oportunidades/` returns to the B2 render.

Before/after verification scripts are reusable against production by pointing them at
the live site: `scripts/stage6-job-verify.py --base https://conexaobr.ie …` (the B2
probe requires a WP-CLI path and is local-only; on production verify state A on any
still-untranslated future job instead).


---

## 18. Limitations / not executed

- **Production was not modified or verified live.** Every execution happened in the
  sandbox clone described in §0; the production rollout is the operator runbook above.
- **Local Docker could not be started** (no Docker daemon / PHP in this sandbox). The
  clone uses the same WordPress major + Polylang 3.8.9 and the real production Jobs
  data, but not the rest of the pilot dataset (events, leisure, sponsors, courses) —
  which is why those suites keep their pre-existing local dataset-existence failures
  (unchanged, unrelated).
- **Browser/device rendering was not measured** (no browser tooling): the mobile
  "markup" claim is structural (same shared responsive card/grid components), not a
  visual claim. `.htaccess`/Apache behaviour untested locally (unchanged file).
- The production Jetpack sitemap currently excludes the job single; the theme sitemap
  (the SEO owner once deployed) includes it — production sitemap membership changes
  only when the theme layer deploys (Stage 4.3 runbook), not from this stage's code.
- Menus were seeded in the clone for the regression net; production per-language menu
  assignment remains the Stage 4.3 operator step.

---

## 19. Acceptance statement (final criteria)

1. Every eligible public Job has a real EN translation — 1/1. ✔
2. Every EN Job is linked to exactly one PT Job. ✔
3. Relationships verified in both directions. ✔
4. No duplicate Job source identities (no identity mechanism exists; the pair + slug
   gates enforce one-to-one; duplicate creation refused in a live test). ✔
5. `/en/jobs/` actually lists the EN Jobs. ✔
6. `/empregos/` still lists the PT Jobs. ✔
7. EN cards link to EN Job details. ✔
8. EN Job details contain English content. ✔
9. Application URLs remain valid (byte-identical). ✔
10. PT Job content remains unchanged (PT changes = 0). ✔
11. B2 remains available for genuinely untranslated future Jobs (state A proven live). ✔
12. Real EN Jobs override B2 (state B proven; replaced master redirects). ✔
13. Search is language-correct. ✔
14. SEO/canonical/hreflang is language-correct. ✔
15. Sitemap behaviour is correct (each URL once, alternates on real pairs). ✔
16. No Blog regression (27/27 + HTTP rows). ✔
17. Full acceptance tests pass (88/88 HTTP + 134/134 in-process). ✔

The critical user-facing requirement holds: an English-speaking visitor opening
`/en/jobs/` sees the available Jobs as real English Job records ("Openings" →
*Opportunities*), and clicking one opens its English Job detail page — never the
Portuguese record as primary content, never an empty language-filtered page, and never
a B2 fallback once the real translation exists.

## 20. Final classification

**ENGLISH JOBS STAGE — PASS WITH LIMITATION**

(The implementation and validation layer is complete and fully verified locally; the
identified limitation is the operational one shared with Stages 4.5 and 5 — production
deployment remains an operator step because no production write path exists in this
environment.)

---

## 21. Final numbers table (exact measured values)

| Metric | Value |
|---|---|
| Job records discovered | 1 (production public REST; 0 non-publish observable) |
| Eligible public Jobs | 1 |
| EN Jobs authored | 1 |
| EN Jobs already existing | 0 |
| EN Jobs created | 1 (validation clone; production apply is the operator step) |
| PT→EN relationships | 1 (pll_get_post(pt,'en') === en) |
| EN→PT relationships | 1 (pll_get_post(en,'pt') === pt) |
| Duplicate source identities | 0 before / 0 after (no source-identity mechanism exists for `job` — measured) |
| Duplicate EN translations | 0 (re-runs create 0; orphan-with-EN-slug refused) |
| Excluded Jobs | 0 |
| Taxonomy terms translated | 0 used → 0 translated (nothing invented) |
| Internal links localized | 0 content links needed (the only link is external, preserved verbatim); chrome links (breadcrumb/nav/switcher) resolve through Polylang |
| Application URLs verified | 1 (byte-identical on the EN page) |
| EN sitemap entries | 1 job URL (+1 EN page URL) |
| Hreflang pairs | 2 (jobs page pair + job pair) |
| B1 remaining | 0 (the `job` CPT is B2-class; nothing sits in B1) |
| B2 remaining | 0 eligible jobs untranslated (the B2 mechanism stays active for future jobs — proven) |
| PT changes | 0 detected / 0 remaining |
| Completeness flags | 0 (1 translated / 0 possible-untranslated / 0 empty) |
| HTTP checks | 88/88 passed (before 31/31 + after 57/57), 0 failed |
| PHP tests | 134/134 passed across the 4 Stage-6-relevant suites (job 29, blog 27, i18n 29, nav-logic 49) |
| WP-CLI runs | all successful (inventory ×2, dry-run, apply ×3, probes, cache checks) |
| Production deployment | NOT_EXECUTED (operator runbook §17) |
| Final classification | **ENGLISH JOBS STAGE — PASS WITH LIMITATION** |

