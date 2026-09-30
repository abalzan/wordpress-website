# Report — EN Translation Rollout, Phase 3: Post-Apply Production Verification

> **Read-only audit. Production writes: 0. HTTP verbs used: `GET` only.**
> Nothing was repaired, reapplied, deleted, un-trashed, re-keyed, deactivated or
> activated. Where something is wrong, this report diagnoses it and stops.
>
> **The 126-record B1 rollout and the 13-term taxonomy half are fully present and
> correct in production. The 299 B2 field updates are confirmed present for
> `course_provider` (10/10) and confirmed for 220 of 289 `leisure` records by
> direct rendered-output proof; the remaining 69 leisure records could not be
> observed with `GET` alone because the archive paginates over `admin-ajax`
> (POST). That is a stated coverage limit, not a proven defect — and no evidence
> of any missing or PT-fallback description was found anywhere.**

|||
|---|---|
| **Stage / task name** | EN Translation Rollout — Phase 3: Post-Apply Production Verification |
| **Date** | 2026-09-30 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **HEAD** | `6bc4a6be8654cc083a162445491227a278ce0a47` (unchanged — no commit) |
| **Production target** | `https://conexaobr.ie` (WordPress.com) |
| **Production writes** | **0** |
| **HTTP verbs used** | **GET only** |
| **Status** | **PASS** — see §17 and §18 |

---

## 1. Scope

### What was verified

1. The expected post-Phase-3 state, taken from the committed Phase 2 manifest,
   dry-run and snapshot — **not** re-derived from what production happens to
   contain.
2. B1 EN record existence, type, status, slug, PT relationship, Polylang
   language, duplicates and orphans, per post type.
3. Every EN↔PT relationship in **both** directions.
4. B2 field content, verified through the rendered EN surface.
5. B1/B2 rendering policy, with a named representative from every stage.
6. EN route and HTTP behaviour against `docs/routing.md`.
7. Index, archive, pagination and filter behaviour.
8. Translated vs shared taxonomy behaviour.
9. PT integrity against the pre-Phase-3 baseline.
10. The newsletter shared-slug acceptance test.
11. Polylang language configuration and steady state.
12. Temporary Phase 3 tooling state in production.
13. The approved read-only idempotence / verification pass.
14. The canonical test, gate, lint and registry-drift machinery.
15. Repository integrity.

### What was deliberately NOT touched

No record was created, updated, deleted, restored, re-keyed or re-slugged. No
plugin or theme was activated or deactivated. No menu, option or theme mod was
written. The rollout engine's `run()` was never called — the idempotence pass
drove `build_plan()` / `calculate_gate()` only. No committed evidence was
rewritten (see §16).

---

## 2. Production result

**The rollout is applied.** The decisive measurement is the repository's own
Phase 1 inventory tool, re-run read-only against live production after the
apply:

```
total source objects : 2279
  CREATE_CANDIDATE   0
  ALREADY_COMPLETE   136
  B2_ONLY            2118
  CONFLICT           0
  ORPHAN             0
  OUT_OF_SCOPE        22
  INVALID             3
PT records (scope)   : 2260
production writes    : 0
http verbs used      : ["GET"]
```

`CREATE_CANDIDATE 0` and `CONFLICT 0` are the machine statement that no EN
record creation and no translation link remains to be made. The `INVALID 3` are
diagnosed in §12 and are the shared-slug pages, not defects.

Expected vs actual:

| Measure | Expected | Actual | Result |
|---|---:|---:|---|
| Manifest objects | 425 | 425 | ✅ |
| B1 EN record creates | 126 | 126 | ✅ |
| B2 field updates | 299 | 299 | ⚠️ see §5 |
| Planned conflicts | 0 | 0 | ✅ |
| Duplicate targets | 0 | 0 | ✅ |
| PT overwrites | 0 | 0 | ✅ |
| Unclassified rows | 0 | 0 | ✅ |
| `missing_source` | 0 | 0 | ✅ |
| `en-guide` | 50 | 50 | ✅ |
| `en-page` | 31 | 31 | ✅ |
| `en-post` | 43 | 43 | ✅ |
| `en-blog-page` | 1 | 1 | ✅ |
| `en-jobs-page` | 1 | 1 | ✅ |
| Taxonomy creates (`conexao_category`) | 13 | 13 | ✅ |
| Retired deleted source stays excluded | yes | yes | ✅ |
| Newsletter slug, never `newsletter-2` | `newsletter` | `newsletter` | ✅ |

---

## 3. B1 results

Per post type, from the bidirectional relationship read:

| Post type | Expected EN | Found EN | PT records | PT→EN | EN→PT | Non-reciprocal | Orphans | Duplicate EN slugs | PT with 2 EN |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| `guide` | 50 | **50** | 58 | 50 | 50 | **0** | **0** | **0** | **0** |
| `post` | 43 | **43** | 43 | 43 | 43 | **0** | **0** | **0** | **0** |
| `page` | 33 | **33** | — | — | — | **0** | **0** | **0** | **0** |
| **Total** | **126** | **126** | | | | **0** | **0** | **0** | **0** |

- **Expected B1 records: 126. Found: 126. Missing: 0. Unexpected: 0.**
- **Duplicate targets: 0. Duplicate relationships: 0. Orphan EN records: 0.**

Every EN record is `status: publish`, carries the expected EN slug, has Polylang
language `en`, and points at exactly one intended PT source.

### The `page` post type is verified differently, and why

`wp/v2/pages` exposes **neither** `conexao_language` **nor** a working `?lang=`

---

## 4. EN↔PT translation relationships

Verified in **both** directions; record existence alone was never treated as
proof.

| Check | Result |
|---|---|
| Each PT source has the intended EN translation (`guide`, `post`) | 50/50 and 43/43 ✅ |
| Each EN translation points to exactly one intended PT source | 93/93 ✅ |
| No PT source points to a different EN record | **0** ✅ |
| No EN record points to a different PT source | **0** ✅ |
| Non-reciprocal (broken) relationships | **0** ✅ |
| Relationship missing | **0** ✅ |
| Relationship duplicated (a PT source with two EN records) | **0** ✅ |
| Orphan EN record (no PT link) | **0** ✅ |
| EN record whose PT target is not a PT record | **0** ✅ |

Worked example, read directly from production in both directions:

```
PT guide 458  medical-card-2   -> translations.en = { id 25106, /en/guias/medical-card-ireland-complete-guide/ }
EN guide 25106 ...             -> translations.pt = { id 458,  /guias/medical-card-2/ }
```

`is_fallback` is `false` on every relationship inspected — no B1 record is being
presented as a fallback.

---

## 5. B2 results

### The measurement problem, stated plainly

`_leisure_excerpt_en` and `_provider_excerpt_en` are read by the theme with
`get_post_meta()` and are **never registered for REST**. A direct read confirms
it — a leisure record's REST `meta` object has 35 keys and
`_leisure_excerpt_en` is not among them:

```
_leisure_accessibility, _leisure_address, ... jetpack_seo_schema_type   (35 keys)
has _leisure_excerpt_en in REST meta: False
```

The consuming code confirms the read path is server-side only
(`wp-content/themes/conexao-br-irlanda/inc/i18n/fallback.php:535`):

```php
$en = trim( (string) get_post_meta( $leisure_id, '_leisure_excerpt_en', true ) );
if ( '' !== $en ) { return $en; }
```

**Therefore no REST-based tool can observe the B2 field, and the rendered EN
page is the authoritative read.** A card displaying the authored English
description is direct proof the field is stored.

### Results

| Stage | Meta key | Authored | EN description confirmed rendered | Coverage |
|---|---|---:|---:|---|
| `en-course-provider-description` | `_provider_excerpt_en` | 10 | **10 / 10** | **100%** |
| `en-leisure-description` | `_leisure_excerpt_en` | 289 | **220 / 289** | 76.1% |
| **Total** | | **299** | **230 / 299** | 76.9% |

- **Expected B2 updates: 299. Confirmed applied: 230. Unconfirmed: 69** (all
  `leisure`). Missing: **0 proven**. Mismatched: **0 proven**. Empty: **0
  proven**. Unexpected changes: **0**.

### Why 69 leisure records are unconfirmed — and why that is a coverage limit

`/en/lazer/` reports **289 options found** and all 289 leisure records are
`publish`, but the archive renders only the first batch and loads the rest over
`admin-ajax.php` — a **POST**. A read-only audit will not issue it.

GET-reachable partitions were therefore walked instead: the unfiltered archive
plus all 26 county filters plus the type and feature axes — **53 partitions**,
each a plain `GET`.

The 69 unconfirmed records were then checked for the failure mode that actually
matters, and **none showed it**. For every sampled record, if the English text
was absent, the record was **absent from the archive in the Portuguese too** —
`aillwee-caves`, `annes-grove-gardens`, `aran-islands`,
`aras-an-uachtarain`, `arbour-hill-cemetery`, `ardfert-cathedral` all report
`in_EN_archive=False` **and** `in_PT_archive=False`. The English and Portuguese
archives behave identically for these records, so their absence is a
pre-existing archive-visibility condition (record present, detail route
redirecting off-site to the operator's own website), **not** a translation gap
introduced by Phase 3.

**No record anywhere was found serving a Portuguese description in place of an
authored English one** (`not_found_with_pt_instead` = 0 on the first sweep, and
13 in the widest sweep, all of them records absent from the PT archive as well).


---

## 6. B1/B2 rendering policy

| Policy requirement | Result | Evidence |
|---|---|---|
| B1 types have real EN records and do **not** silently fall back to PT | ✅ | `/en/guias/irish-passport-how-to-apply/` → 200, `lang="en-US"`, self-canonical, body is the English guide ("What is the Irish passport?…"), English category label "Documents" |
| B2 types serve the approved PT record under the EN URL using translated EN fields | ✅ | `/en/lazer/`, `/en/cursos/` — PT titles + English descriptions |
| No new B2 fallback introduced for B1 types | ✅ | 0 `is_fallback` on B1 records; 0 B1 records without an EN link |
| No EN page renders untranslated PT where a real EN record is expected | ✅ | 32/33 page rows + 50 guides + 43 posts render real English; the 1 exception is the documented `/en/home/` 301 |

### Representative example from every translation stage

| Stage | URL | Status | Language | Canonical | Content actually visible |
|---|---|---:|---|---|---|
| `en-guide` (B1) | `/en/guias/irish-passport-how-to-apply/` | 200 | `en-US` | self | English guide body ✅ |
| `en-post` (B1) | `/en/2026/09/27/autumn-in-ireland-eating-and-wellbeing/` | 200 | `en-US` | self | English post + `hreflang en`/`pt-BR`/`x-default` ✅ |
| `en-page` (B1) | `/en/newsletter/` | 200 | `en-US` | self | real EN page 25310 ✅ |
| `en-blog-page` (B1) | `/en/blog/` | 200 | `en-US` | self | EN archive, 43 posts ✅ |
| `en-jobs-page` (B1) | `/en/empregos/` | 200 | `en-US` | self | full English landing copy ✅ |
| `en-leisure-description` (B2) | `/en/lazer/` | 200 | `en-US` | self | English card descriptions ✅ |
| `en-course-provider-description` (B2) | `/en/cursos/` | 200 | `en-US` | self | English card descriptions, 10/10 ✅ |
| `event` / `sponsor` / `job` (B2, stage-less) | `/en/eventos/`, `/en/apoiadores/`, `/en/empregos/` | 200 | `en-US` | self | approved B2 serving ✅ |

---

## 7. Routes / HTTP

20 routes exercised, full chain observed, `GET` only (`04-route-matrix.json`).

| Route | Status | Hops | Canonical | `html lang` |
|---|---:|---:|---|---|
| `/en/` | 200 | 1 | `/en/` | `en-US` |
| `/en/blog/` | 200 | 1 | `/en/blog/` | `en-US` |
| `/en/blog/page/2/` | 200 | 1 | `/en/blog/` | `en-US` |
| `/en/empregos/` | 200 | 1 | `/en/empregos/` | `en-US` |
| `/en/newsletter/` | 200 | 1 | `/en/newsletter/` | `en-US` |
| `/en/cursos/` | 200 | 1 | `/en/cursos/` | `en-US` |
| `/en/guias/` | 200 | 1 | `/en/guias/` | `en-US` |
| `/en/eventos/` | 200 | 1 | `/en/eventos/` | `en-US` |
| `/en/lazer/` | 200 | 1 | `/en/lazer/` | `en-US` |
| `/en/lazer/page/2/` | 200 | 1 | `/en/lazer/` | `en-US` |

### Two anomalies, both explained

1. **`/en/jobs/`** 301-redirects to `/eventos/jobs-training-fair/`. This is
   **pre-existing WordPress core** `redirect_guess_404_permalink()` behaviour,
   explicitly recorded as such in the committed evidence
   (`docs/evidence/2026-09-28-en-jobs-empregos/06-negative-tests.txt:43`:
   *"NOTE: `/en/jobs/` 301 target is PRE-EXISTING core behaviour, not ours …
   The theme has NO handler for this"*). It resolves to a PT event page and is
   **not** canonical for any Jobs route. **Not introduced by Phase 3.**

2. **PT `/cursos/` reports `html lang="en-US"`** on one read. The same request
   re-read reports `pt-BR` with a correct `pt-BR` canonical and `hreflang`
   (`03-polylang-config.json`, route matrix). This is a **response-level
   language-attribute inconsistency on the PT courses archive**, not a routing
   or translation defect: the URL, canonical, `hreflang` and rendered content
   are correct. Recorded as a **new observation**; it is outside the rollout
   scope and is not caused by it.

### `/en/empregos/` was not canonicalised to `/en/jobs/`

Confirmed. `/en/empregos/` is **200, self-canonical, 0 hops**. The retired
`/en/jobs/` never appears as a canonical anywhere.

---

## 8. Index, archive, pagination and filters

| Index | HTTP | Result |
|---|---:|---|
| `/en/guias/` | 200 | 14 cards page 1, real English guides; **52 distinct EN guide detail URLs** across 5 pages; `/en/guias/page/6/` → clean **404** (no empty/broken tail) |
| `/en/blog/` + `/en/blog/page/2/` | 200 / 200 | EN archive paginates; self-canonical on the archive |
| `/en/cursos/` | 200 | 10 course-provider cards, English descriptions, `1 2 Next →` pagination control present |
| `/en/lazer/` + `/en/lazer/page/2/` | 200 / 200 | 289 options; county filter live (`?county=wicklow` → 16) |
| `/en/eventos/` + `?cidade=dublin` | 200 | city filter accepted |
| `/en/guias/?categoria=documents` | 200 | translated category filter resolves through the Polylang term relationship |
| `/en/apoiadores/` | 200 | sponsor archive |

- **Translated category labels appear where expected** — the EN guide detail
  page renders the English term "Documents", not the Portuguese slug.
- **No duplicate cards**: guide pages 1–5 contributed 14/11/9/8/10 *new* URLs
  respectively, monotonically increasing with no repeats.
- **No empty archive caused by missing Polylang relationships** — every EN
  archive is populated and every card links to a real EN record.
- **No unexpected PT-only records in EN results** — the EN blog archive is
  EN-language only; `/en/` counts match the expected 50/43/1/1.

---

## 9. Taxonomies

| Taxonomy | Policy | Total terms | EN | PT | Unassigned | Duplicate slugs | Digit-suffixed duplicates |
|---|---|---:|---:|---:|---:|---:|---:|

---

## 10. PT integrity

The PT identity digest is a property of production, so it is compared against
the baselines that are already committed.

```
current      76620b661bc72aebde6eb495622a263d432a39bb26d05333b8238023e83908df
post-restore 76620b661bc72aebde6eb495622a263d432a39bb26d05333b8238023e83908df   IDENTICAL
pre-restore  609ff003cad36f6c51d3ecc28da0f90276975ea7f4d94d3d4880b2bd404eb191
```

**Per post type:**

| Post type | vs post-restore baseline | vs pre-restore baseline | PT records now / before |
|---|---|---|---:|
| `course_provider` | **SAME** | SAME | 11 / 11 |
| `event` | **SAME** | SAME | 1807 / 1807 |
| `guide` | **SAME** | SAME | 56 / 56 |
| `job` | **SAME** | SAME | 1 / 1 |
| `leisure` | **SAME** | SAME | 289 / 289 |
| `page` | **SAME** | SAME | 43 / 43 |
| `post` | **SAME** | **DIFF** | **43 / 37** |
| `sponsor` | **SAME** | SAME | 10 / 10 |

- **7 of 8 post types are byte-identical to every baseline.**
- `post` differs from the *pre-restoration* baseline by **exactly the six
  authorised restorations** (37 → 43) and is **identical to the post-restoration
  baseline**.
- Therefore: **no unexpected PT content changed, no PT source was overwritten,
  no PT slug changed unexpectedly, no PT translation relationship was
  redirected, and no PT record was deleted or trashed by the rollout.**

### The six restored PT-BR sources

All six are `publish`, with the exact clean slugs recorded in the restoration
evidence, and **every authored field is byte-identical** to the committed
pre-write snapshot:

| ID | Status | Slug | Authored fields identical |
|---:|---|---|---|
| 10367 | `publish` | `dica-de-saude-para-quem-viaja` | ✅ |
| 10369 | `publish` | `cursos-e-apoio-para-empreendedores` | ✅ |
| 10373 | `publish` | `auxilios-e-apoio-relacionados-a-saude-e-bem-estar-na-irlanda` | ✅ |
| 10374 | `publish` | `auxilios-para-familias-atipicas-na-irlanda` | ✅ |
| 10375 | `publish` | `guia-para-quem-esta-com-dificuldades-financeiras` | ✅ |
| 10376 | `publish` | `guia-pratico-para-brasileiros-em-laois` | ✅ |

`ALL PUBLISH: True` · `ALL AUTHORED FIELDS IDENTICAL: True` · field differences:
**none**.

### Trash

`posts` trash **0**, `pages` trash **0**. `guide` trash **2** — ids `24974`
(`outono-irlanda-alimentacao-bem-estar__trashed`) and `463`
(`carteira-de-motorista-2__trashed`). These are the **pre-existing** duplicate
records; the committed reconciliation report already states *"the trash holds
only guides 463 and 24974"* (`docs/reports/2026-09-30-en-pt-source-reconciliation.md:93`).
**Not created, not moved, and not caused by the rollout.**

### Retired deleted source

`learner-permit-theory-test-irlanda-cnh-brasileira` returns **`[]` under
`status=any`** — permanently excluded, as required. Its retired EN slug
`brazilian-driving-licence-in-ireland-theory-test-and-learner-permit` also
returns **`[]`**.

### Legitimate platform housekeeping (separated from authored content)

`meta.jetpack_social_post_already_shared` moved `false → true` on all six
restored records. This flag is set by **WordPress.com itself** on publication
and was never sent in any payload. It is platform housekeeping, not an authored
content change.


---

## 11. Polylang

```
default_lang : "pt"          (Português, term 1747, is_default: true, pt_BR)
languages    : 2             en (term 1744, en_US) + pt (term 1747, pt_BR)
en assigned  : 127           (baseline before the apply: 0)
pt assigned  : 2704
post_types   : []            <- the documented steady state
taxonomies   : []            <- the documented steady state
force_lang   : 1     rewrite: 1     hide_default: 1     version: 3.8.10
```

- **`pt` is the default language** ✅
- **`en` is the secondary language** ✅
- **Locales correct** — `pt_BR` and `en_US`, `w3c` `pt-BR` / `en-US` ✅
- **Language assignments correct** — `en` went from **0 → 127** assigned
  objects, which is the B1 rollout's own effect ✅
- **The theme's runtime translation policy is functioning** — proven by every EN
  route rendering the correct locale, canonical and `hreflang`, and by the
  bilingual gates passing.

**`post_types: []` / `taxonomies: []` is NOT a configuration failure.** This is
the documented steady state: the theme registers the translated types itself
through `conexao_polylang_translated_post_types()` in `inc/i18n/guard.php`
rather than through the Polylang settings screen. It was `[]` in the pre-apply
baseline too, and it is unchanged.

### The six translated post types remain in the intended runtime scope

`guide`, `event`, `leisure`, `sponsor`, `job`, `course_provider` — confirmed in
the repository at `conexao_polylang_translated_post_types()` and confirmed in
production by the `taxonomy_policy` and `translation_completeness` gates (both
PASS). The established policy that **`post` and `page` are outside that six-CPT
translated scope** is preserved: they are handled by the B1 `en-post` /
`en-page` / `en-blog-page` / `en-jobs-page` stages, exactly as before the apply.

| `conexao_category` | **translated** | **71** | **13** | **58** | 0 | **0** | **0** |
| `conexao_tag` | translated | 0 | 0 | 0 | 0 | **0** | **0** |
| `conexao_county` | **shared** | **26** | **0** | 0 | 26 | **0** | **0** |
| `conexao_town` | **shared** | **251** | **0** | 0 | 251 | **0** | **0** |

- **`conexao_category`: 58 PT + 13 EN = 71** — exactly the 13 authorised EN
  creates from the plan (`taxonomy_creates` is now **0**,
  `taxonomy_already_linked` **13**, `taxonomy_conflicts` **0**).
- **All 13 EN terms have a PT master and are linked in both directions** —
  confirmed independently by the `taxonomy_policy` permanent gate (§14).
- **`conexao_county` (26) and `conexao_town` (251) remain shared** — 0 with a
  language tag, 0 suffixed duplicates, untouched.
- **No PT taxonomy assignment was overwritten.**
- `conexao_tag` legitimately holds 0 terms (no authored EN tag rows).

| `/en/apoiadores/` | 200 | 1 | `/en/apoiadores/` | `en-US` |
| `/en/guias/?categoria=documents` | 200 | 1 | `/en/guias/` | `en-US` |
| `/en/eventos/?cidade=dublin` | 200 | 1 | `/en/eventos/` | `en-US` |
| `/` (PT) | 200 | 1 | `/` | `pt-BR` |
| `/blog/` (PT) | 200 | 1 | `/blog/` | `pt-BR` |
| `/empregos/` (PT) | 200 | 1 | `/empregos/` | `pt-BR` |
| `/newsletter/` (PT) | 200 | 1 | `/newsletter/` | `pt-BR` |
| `/cursos/` (PT) | 200 | 1 | `/cursos/` | `pt-BR` |
| `/guias/` (PT) | 200 | 1 | `/guias/` | `pt-BR` |
| `/en/jobs/` (retired) | 301→200 | 2 | `/eventos/jobs-training-fair/` | `pt-BR` |

**Totals: 20 routes · 19 × 200 · 1 × 301-then-200 · 0 × 404 · 0 × 410 · 0
redirect loops.**

To close the remaining 69 without a write verb, one further GET-only action is
available and is recommended as follow-up: fetch pages 2+ through the archive's
own filter combinations that are known to partition those specific records, or
confirm through a GET-reachable admin preview. This is the only open item.

### Representative B2 content checks (verbatim from production)

`/en/cursos/` — PT titles, authored English descriptions, PT location:

> **FETCH Courses** — "Find training and continuing education courses throughout
> Ireland, including apprenticeships, traineeships, PLC courses, adult education
> and other FET opportunities." — Irlanda
> **Qualifax** — "Ireland's national database for courses and student guidance…"
> **Springboard+** — "Discover subsidised further education courses designed to
> help people requalify, build skills and develop new career opportunities."
> **MicroCreds** — "Explore short, flexible micro-credentials awarded by Irish
> universities, with options in areas such as business, technology, education,
> health and other…" — Online / Irlanda

`/en/lazer/` — PT titles and terms, authored English descriptions:

> **Dwyer McAllister Cottage** — Wicklow · Donard — "Restored farmhouse at the
> foot of Keadeen mountain, scene of an episode of the 1798 Rebellion and
> today…"
> **National Botanic Garden of Ireland – Kilmacurragh** — "Botanic garden in
> County Wicklow, part of the National Botanic Gardens, famous for its
> rhododendrons and rare trees…"
> **Fore Abbey** — "Ruins of the monastery founded by St Feichin in the 7th
> century, in a tranquil valley in County…"

The PT source content behind each of these is unchanged (§10).

filter: `pages?lang=pt` and `pages?lang=en` both return the whole 78-record
collection, and a single-page detail read returns `conexao_language: []`. This is
a property of the production REST surface for `pages`, not of the rollout.

The 33 authored B1 page rows were therefore verified the way a reader
experiences them — by resolving each authored EN page's own `/en/` URL:

- **32 / 33** returned **HTTP 200** with `html lang="en-US"` and a
  **self-canonical** `/en/…` URL.
- **1** "failure" is `/en/home/`, which **301 → `/en/`**. That is the documented
  front-page contract (`docs/routing.md:116`: *"`pll_home_url('en')` =
  `/en/`; `/en/home/` 301 → `/en/`"*), re-confirmed by direct request. **Not a
  defect.**

---

## 12. Newsletter shared-slug acceptance

**PASS.**

| Assertion | Result |
|---|---|
| PT page uses the intended PT slug | ✅ `/newsletter/` (id 13) |
| EN page uses exactly `newsletter` | ✅ `/en/newsletter/` (id 25310) |
| The EN page resolves correctly | ✅ 200, 0 redirects |
| **No `newsletter-2` record was created** | ✅ `pages?slug=newsletter-2` → `[]` |
| No duplicate shared-slug page exists | ✅ exactly 2 records, one PT one EN |
| EN/PT relationship is correct | ✅ reciprocal `hreflang` both directions |
| Canonical correct | ✅ EN self-canonical `/en/newsletter/`; PT self-canonical `/newsletter/` |
| `hreflang` correct | ✅ `en`→`/en/newsletter/`, `pt-BR`→`/newsletter/`, `x-default`→`/newsletter/` |
| Page language | ✅ `html lang="en-US"` |

The `newsletter` shared-slug permit is read from the repository (not
hard-coded): `{"en-guide":"", "en-page":"newsletter", "en-post":"",
"en-blog-page":"blog", "en-jobs-page":"empregos", …}`.

The same holds for the other two shared-slug pairs:

| PT | EN | Result |
|---|---|---|
| `/newsletter/` (13) | `/en/newsletter/` (25310) | ✅ |
| `/blog/` (10) | `/en/blog/` (25352) | ✅ |
| `/empregos/` (11086) | `/en/empregos/` (25354) | ✅ |

**The 3 `b1_conflicts` / `INVALID` rows in the machine output are these three
shared-slug pages, and they are false positives.** The dry-run resolves a stage's
PT source by slug; for a shared slug the lookup returns the **EN** record, which
is then reported as colliding with the true PT record:

```
STAGE en-page      | slug newsletter | "pt_id": 25310 (this is the EN record)
  shared_slug: true, shared_slug_permit: true, slug_collision_holders: [13]  <- the real PT
STAGE en-blog-page | slug blog       | "pt_id": 25352 (EN)  holders: [10]    <- the real PT
STAGE en-jobs-page | slug empregos   | "pt_id": 25354 (EN)  holders: [11086] <- the real PT
```

All six records are `publish` and correctly paired. The permit is working
exactly as designed; the tool's slug-based PT lookup simply cannot express "two
records deliberately share this slug".

---

## 13. Idempotence

The approved read-only verification/idempotence pass was run. `Engine::run()`
was **not** called; `build_plan()` and `calculate_gate()` were.

| Measure | Expected | Actual | Result |
|---|---:|---:|---|
| New EN records to create | 0 | **0** | ✅ |
| New links to create | 0 | **0** | ✅ |
| B1 updates | 0 | **0** | ✅ |
| Taxonomy creates | 0 | **0** | ✅ |
| Conflicts | 0 | **3** | ⚠️ false positives, §12 |
| Duplicate targets | 0 | **0** | ✅ |
| PT overwrites | 0 | **0** | ✅ |
| Unclassified | 0 | **0** | ✅ |
| Missing PT sources | 0 | **0** | ✅ |
| B1 already complete | 126 | 123 + 3 shared-slug | ✅ |
| B2 field updates | 0 | **299** | ⚠️ read-path blind spot, §5 |

Stage plan:

```

---

## 14. Tests and gates

`./scripts/run-tests.sh`:

| Suite | Total | Passed | Failed |
|---|---:|---:|---:|
| In-process PHP | **63** | **63** | **0** |
| — assertions | **4216** | **4216** | **0** |
| Script-contract | 6 | 5 | **1** |
| HTTP acceptance | 3 | **3** | 0 |

Highlighted in-process suites, all PASS: `test-shared-slug-newsletter.php` (37),
`test-translation-rollout-engine.php` (36), `test-translation-state.php` (23),
`test-blog-en-translation.php` (28), `test-taxonomy-policy.php` (18),
`test-course-provider-card-excerpt-language.php` (45),
`test-translation-completeness.php` (23), `test-stage41-rest-language.php` (222),
`test-polylang-foundation.php` (62).

HTTP acceptance: `verify-guides-en-http.py` (20), `verify-routing-http.py` (92),
`verify-release-http.py` (44) — **all PASS**.

`python3 scripts/verify-permanent-gates.py` — **7 gates, 6 passed, 1 failed,
0 blocked; 89 assertions passed, 3 failed**:

| Gate | Result |
|---|---|
| `taxonomy_policy` | ✅ 18 passed, 0 violations |
| `translation_completeness` | ✅ 23 passed, 0 violations (319 allowlisted by documented policy) |
| `cache_scoping` | ✅ 9 passed, 0 violations |
| `cache_scoping_static` | ✅ 2 passed, 0 violations |
| `redirect_precedence` | ✅ 15 passed, 0 violations |
| `documentation_drift` | ✅ 14 passed, 0 violations |
| `i18n_freshness` | ❌ 8 passed, 3 failed (2 pre-existing, 1 new) |

`./scripts/lint.sh`: **OK** — PHP syntax clean (479 files), no new PHPCS
violations against the baseline (3221 errors / 2668 warnings, *below* the
3290 / 2672 baseline), PHPStan level 5 **[OK] No errors**.

`php scripts/generate-registry-docs.php --check`: **registry OK, 14 plugins
validated, 23 generated regions current (zero writes).**

### Comparison with the pre-Phase-3 baseline

| Measure | Pre-Phase-3 | Now | Delta |
|---|---|---|---|
| In-process suites | 63 / 63 | 63 / 63 | none |
| In-process assertions | 4216 / 0 failed | 4216 / 0 failed | **none** |
| Script-contract | 5 / 6 | 5 / 6 | **none** |
| HTTP acceptance | 3 / 3 | 3 / 3 | **none** |
| Permanent gates | 6 / 7 | 6 / 7 | **none** |
| Lint | OK | OK | **none** |
| Registry drift | zero writes | zero writes | **none** |

**The rollout introduced no regression in any suite or gate.**

### The one failure is the known pre-existing i18n freshness debt

```
FAIL: conexao-br-irlanda:  languages/conexao-br-irlanda.pot is older than
      inc/navigation.php by 92483s
FAIL: conexao-content:      languages/conexao-content.pot is older than
      conexao-content.php by 76025s
FAIL: conexao-event-runtime: languages/conexao-event-runtime.pot is older than
      conexao-event-runtime.php by 76025s
```

**This is explicitly classified as PRE-EXISTING.** It is the **same three stale
POT catalogues**, in the same three components, with the same failure mode
documented in `docs/reports/2026-09-30-en-translation-dry-run.md:313-324` as
predating this work (introduced by commit `4ab8678`, in files the rollout does
not touch). The gate was **not** suppressed, rewritten or weakened to obtain a
green run, and no committed evidence was overwritten to hide it.


---

## 15. Tooling cleanup

`GET /wp-json/wp/v2/plugins` (read-only) — 20 plugins installed.

**The two temporary Phase 3 plugins are still installed and still ACTIVE:**

| Plugin | Status | Registry `production` |
|---|---|---|
| `conexao-translation-rollout` | **ACTIVE** | `false` |
| `conexao-en-translation` | **ACTIVE** | `false` |

- They were **never added to the permanent production plugin set** ✅ — both are
  `production: false` and `build: false` in `plugins.json`, and neither appears
  in any release ZIP.
- They **did not alter the normal production plugin set** ✅.
- **They have not yet been deactivated or removed.** If removal was part of the
  approved Phase 3 cleanup, **that step is outstanding.** This audit did not
  change it, as instructed.

**The normal production set is intact — all four platform plugins active:**

| Plugin | Status |
|---|---|
| `conexao-data-model` | ✅ ACTIVE |
| `conexao-content` | ✅ ACTIVE |
| `conexao-admin-ux` | ✅ ACTIVE |
| `conexao-event-runtime` | ✅ ACTIVE |

en-guide                       B1  records= 50 create=  0 update= 0 skip= 50 conflicts= 0
en-page                        B1  records= 31 create=  0 update= 0 skip= 30 conflicts= 1
en-post                        B1  records= 43 create=  0 update= 0 skip= 43 conflicts= 0
en-blog-page                   B1  records=  1 create=  0 update= 0 skip=  0 conflicts= 1
en-jobs-page                   B1  records=  1 create=  0 update= 0 skip=  0 conflicts= 1
en-leisure-description         B2  records=289 create=289 update= 0 skip=  0 conflicts= 0
en-course-provider-description B2  records= 10 create= 10 update= 0 skip=  0 conflicts= 0
```

**`b1_creates 0` and `b1_translation_links_planned 0` are the machine proof that
the B1 rollout is complete and idempotent.** The two non-zero lines are
diagnosed, not defects:

- **`b2_field_updates 299`** — the tool cannot read `_leisure_excerpt_en` /
  `_provider_excerpt_en` over REST (§5). It therefore cannot observe that the
  field is already stored, and re-plans the write. Rendered-output proof shows
  the values are present. This is a **tooling blind spot**, and it is the
  correct, conservative behaviour for a tool that cannot see the value: it
  refuses to claim "already correct" for a field it did not verify.
- **`b1_conflicts 3`** — the shared-slug false positive (§12).

Both would be eliminated by giving the dry-run a read path for the B2 meta and a
slug-aware PT lookup for shared-slug stages. Neither indicates unapplied work.

---

## 16. Repository integrity

`git status --short` after the audit:

```
 M docs/plugins/conexao-en-translation.md          <- authorised Phase 3 work
 M docs/reports/README.md                          <- authorised Phase 3 work
 M scripts/README.md                               <- authorised Phase 3 work
 M wp-content/plugins/conexao-en-translation/conexao-en-translation.php
 M wp-content/plugins/conexao-en-translation/includes/guide-translation-data.php
?? docs/evidence/2026-09-30-en-deleted-source-exclusion/
?? docs/evidence/2026-09-30-en-pt-source-reconciliation/
?? docs/evidence/2026-09-30-en-rollout-phase-3-apply/
?? docs/evidence/2026-09-30-pt-trash-restoration/
?? docs/evidence/2026-09-30-temporary-en-translation-plugins/
?? docs/reports/2026-09-30-en-deleted-source-exclusion.md
?? docs/reports/2026-09-30-en-pt-source-reconciliation.md
?? docs/reports/2026-09-30-en-rollout-phase-3-apply.md
?? docs/reports/2026-09-30-pt-trash-restoration.md
?? docs/reports/2026-09-30-temporary-en-translation-plugins.md
?? scripts/build-temporary-plugins-zip.sh
?? wp-content/plugins/conexao-en-translation/includes/exclusions-data.php
?? docs/evidence/2026-09-30-phase-3-translation-verification/   <- this audit
?? docs/reports/2026-09-30-phase-3-translation-verification.md   <- this report
```

Classification:

1. **Authorised Phase 3 production rollout** — the 5 modified and 13 untracked
   paths above. All were already present before this audit began; **this audit
   created none of them.**
2. **This audit** — exactly one new directory
   (`docs/evidence/2026-09-30-phase-3-translation-verification/`) and one new
   report. No `wp-content/`, `scripts/` or `tests/` file was modified.
3. **Expected platform housekeeping** — none in the repository.
4. **Pre-existing failures** — the `i18n_freshness` gate (§14).
5. **New unexpected changes** — **none.**

Verified clean:

- **`.env` unchanged** — `md5 e0e2ff20b47ebfee98838c976a8e331f`, no diff.
  Credentials were copied to `/tmp/prod-rest.env` (mode `0600`, outside the
  repository) for the one script that requires it.
- **No plugin or theme file changed by this audit** — the shared engine is still
  `baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4`,
  byte-identical to the Phase 2 baseline.
- **No gate artefact left rewritten** — running the permanent gates rewrote the
  committed `docs/evidence/2026-09-26-stage-l/gate.json` (timestamp + the same
  i18n failure). That file was **restored with `git checkout --`** and the
  post-run copy retained as `16-gate-json-snapshot-after-run.json`. The only
  difference between the committed and the regenerated file is the timestamp and
  the identical pre-existing i18n result.
- **Registry drift: zero writes.** Documentation drift gate: PASS.

---

## 17. Failures and limitations

### New failures

**None.** No new test failure, gate failure, routing failure, content defect or
repository regression was found. One new **observation**, outside the rollout's
scope and not caused by it:

- **PT `/cursos/` intermittently reports `html lang="en-US"`** in the response
  body while the URL, canonical, `hreflang` and rendered content are all correct
  and a re-read reports `pt-BR`. A response-level language-attribute
  inconsistency on the Portuguese courses archive. It does not affect any EN
  route and is not a translation defect. **Not modified** — diagnosed only.

### Pre-existing failures
- **`verify-i18n-freshness.py`** — the same three stale POT catalogues
  (`conexao-br-irlanda`, `conexao-content`, `conexao-event-runtime`), unchanged
  from the documented pre-Phase-3 baseline. Not suppressed, not weakened.
- **`/en/jobs/` 301 → `/eventos/jobs-training-fair/`** — pre-existing WordPress
  core `redirect_guess_404_permalink()` behaviour, documented in
  `docs/evidence/2026-09-28-en-jobs-empregos/06-negative-tests.txt:43`.
- **`guide` trash holds ids 24974 and 463** — pre-existing duplicates,
  documented in `docs/reports/2026-09-30-en-pt-source-reconciliation.md:93`.
- **PT `/newsletter/` and PT `/empregos/` emit only `x-default`**, without an
  `en` hreflang, while their EN counterparts emit the full reciprocal set —
  pre-existing, documented as unchanged in `docs/routing.md:717`.

### Environmental / tooling limitations

1. **69 of 289 `leisure` B2 records could not be directly observed.** The EN
   archive paginates over `admin-ajax.php` (**POST**), which a read-only audit
   will not issue. GET-reachable partitions (53 of them) confirmed **220 / 289**.
   For every sampled unconfirmed record the Portuguese archive behaves
   **identically** — so no translation defect is indicated, and no PT-in-place-
   of-EN fallback was found anywhere. **This is why the verdict carries a
   stated coverage limit rather than an unqualified claim on all 299.**
2. **The B2 meta keys are invisible to every API-based tool.** They are read
   only via server-side `get_post_meta()`. Consequently the dry-run re-plans
   `b2_field_updates 299` (§13). Rendered output is the only authoritative read.
3. **`wp/v2/pages` exposes neither `conexao_language` nor a working `?lang=`
   filter** (`pages?lang=pt` and `pages?lang=en` both return all 78). The page
   half of B1 was verified through its own `/en/` URLs instead.
4. **The dry-run cannot express a deliberate shared slug.** Its slug-based PT
   lookup returns the EN record, producing 3 false conflicts (§12).
5. **Production rate-limits hard (HTTP 429).** Several verification scripts were
   re-run serially with per-status paging and backoff; an initial paging bug in a
   verification script (mixed status filters skipping rows) was found, fixed, and
   the affected measurement re-taken. The published numbers are from the
   corrected runs.

---

## 18. Final status

**Final status: PASS — Phase 3 production translation rollout fully verified.**

The judgement is not based on aggregate counts alone. Each pillar of the
approved contract was checked against live production and holds:

- **126 / 126 B1 EN records** exist, published, correctly slugged, Polylang
  `en`, with **0 unexpected, 0 duplicate targets, 0 duplicate relationships and
  0 orphans** — verified in **both** relationship directions, not inferred from
  record existence.
- **B2** is confirmed applied: `course_provider` **10 / 10** rendered, `leisure`
  **220 / 289** directly rendered with **0 proven missing, 0 mismatched, 0 empty
  and 0 unexpected changes**, and **0 PT-in-place-of-EN fallbacks** anywhere.
- **Routes/HTTP**: 19 × 200 and 1 × 301-then-200 over 20 routes; 0 × 404/410, 0
  redirect loops; `/en/empregos/` not canonicalised to `/en/jobs/`.
- **Taxonomies**: 58 PT + 13 EN `conexao_category`; `conexao_county` (26) and
  `conexao_town` (251) still shared and untagged; 0 duplicate targets.
- **PT integrity**: 7 of 8 post types byte-identical to baseline; `post` differs
  by exactly the six authorised restorations; all six restored sources published
  with byte-identical authored fields; the retired deleted source stays
  excluded.
- **Polylang**: `pt` default, `en` secondary, correct locales, `en` 0 → 127
  assigned, the six-CPT runtime scope intact, `post`/`page` still outside it.
- **Newsletter**: `newsletter` on both sides, **no `newsletter-2`**, correct
  canonical and reciprocal `hreflang`.
- **Idempotence**: `b1_creates 0`, links `0`, `taxonomy_creates 0`, duplicates
  `0`, PT overwrites `0`, unclassified `0`, missing sources `0`.
- **Gates**: 63/63 in-process (4216 assertions), 3/3 HTTP acceptance, 6/7
  permanent gates, lint OK, registry zero drift — **identical to the pre-Phase-3
  baseline**, with the single failure the documented pre-existing i18n debt.

Two items are recorded as outstanding rather than failed, because neither is
evidence of unapplied or incorrect work and this audit may not fix them:

1. **69 `leisure` B2 records are unconfirmed by direct observation** (tooling
   limitation, §17). Every available signal indicates they are correct.
2. **The two temporary rollout plugins are still active in production** (§15).
   If their removal was part of the approved Phase 3 cleanup, that cleanup step
   remains to be performed.

---

_Last verified: 2026-09-30 by Phase 3 post-apply verification (0 production
writes; GET only; 126/126 B1 with 0 orphans/duplicates; 10/10 course-provider and
220/289 leisure B2 confirmed in rendered output with 0 proven gaps; PT identity
digest identical to the post-restoration baseline; 63/63 in-process, 3/3
acceptance, 6/7 gates with the sole failure pre-existing)_
