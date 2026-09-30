# Report — EN Translation Rollout, Phase 1: Production Inventory (read-only)

> **Phase 1 only.** Nothing was translated, created, linked, updated or
> deployed. Every production request this task issued was a **`GET`**. The
> rollout stops at the inventory/manifest boundary; dry-run, snapshot, apply,
> verify, idempotence and gate are **Phase 2**.
>
> **The verified 2,175 PT translated-scope baseline was re-measured live and
> still holds exactly: 2,175.** EN is still **0** across every post type and
> taxonomy. PT drift across the whole inventory session is **0**.

| | |
|---|---|
| **Stage / task name** | EN Translation Rollout — Phase 1: Production Inventory Only |
| **Date** | 2026-09-30 |
| **Author / agent** | Cline (AI agent) |
| **Branch** | `i18n` |
| **Start / Final SHA** | `caf8d259cad1e25e612615b4d359fec4f3ebec6e` (unchanged) |
| **Working tree at finish** | only this report, `docs/evidence/2026-09-30-en-translation-inventory/` and one line in `docs/reports/README.md`. No `wp-content/`, `scripts/` or `tests/` change. |
| **Production writes** | **0** — GET only |
| **Status** | **PASS — EN INVENTORY READY FOR DRY-RUN** (with the §9 dataset-drift limitation, stated not glossed) |

---

## 1. What was done

1. Re-read the current `i18n` HEAD as the implementation authority (`AGENTS.md`,
   `docs/routing.md`, `docs/content-model.md`, `docs/releases.md`, the
   `conexao-translation-rollout` / `conexao-en-translation` plugin docs and
   source, and the theme's i18n policy in `inc/i18n/`).
2. Captured a fresh production baseline over the bilingual REST contract the
   theme already exposes (`?lang=pt` / `?lang=en` plus the read-only
   `conexao_language` field) — no new probe endpoint, no new lifecycle.
3. Classified every EN rollout scope B1/B2 from HEAD, before any counting.
4. Dumped the repository's **own** authored stage manifests by executing
   `conexao-en-translation`'s data files in PHP and joined them to live
   production state. The expected work is the repository's, not a new list.
5. Produced a deterministic, classified manifest and a reproducible PT
   protection baseline.
6. Re-read production a second and third time to prove determinism and PT
   drift = 0.

The inventory tool is `docs/evidence/2026-09-30-en-translation-inventory/en-translation-inventory.py`.
It has **no write verb, no `--apply` and no production-write path**; it reuses
`scripts/lib/rest.py` for base-URL resolution, auth, retries and pagination.

## 2. Production baseline (live, `GET` only)

`GET /wp-json/pll/v1/settings`:

```
force_lang 1 · hide_default true · rewrite true · redirect_lang false
browser false · media_support false · domains [] · nav_menus [] · sync []
default_lang "pt" · post_types [] · taxonomies [] · version 3.8.10
```

`GET /wp-json/pll/v1/languages`:

| Language | Name | Locale | term_id | default | assigned count |
|---|---|---|---|---|---|
| `pt` | Português | `pt_BR` | 1747 | **true** | **2,681** |
| `en` | English | `en_US` | 1744 | false | **0** |

Exactly two languages, `pt` default, EN assigned **0**. The configuration is
unchanged from the verified i18n foundation.


### 2.1 Record and language state (the record's OWN language, not the request `lang`)

| post_type | total records | PT | **EN** | unassigned | `?lang=pt` / `?lang=en` collection |
|---|---:|---:|---:|---:|---|
| `guide` | 56 | 56 | **0** | 0 | 56 / **0** |
| `event` | 1,808 | 1,808 | **0** | 0 | 1,808 / 1,808 |
| `leisure` | 289 | 289 | **0** | 0 | 289 / 289 |
| `sponsor` | 10 | 10 | **0** | 0 | 10 / 10 |
| `job` | 1 | 1 | **0** | 0 | 1 / 1 |
| `course_provider` | 11 | 11 | **0** | 0 | 11 / 11 |
| `post` | 37 | 37 | **0** | 0 | 37 / **0** |
| `page` | 43 | 43 | **0** | 0 | 43 / 43 |

**EN translation links: 0. Orphan EN records: 0. Duplicate translations: 0.**

The five B2 types return the *same* record ids for `?lang=pt` and `?lang=en`
because the B2 resolvers widen the EN request to the PT record. That is **B2
resolution, not EN content**, and the inventory never counted it as EN: the
authoritative test is each record's own `conexao_language.lang`, which is `pt`
for every one of them.

### 2.2 The 2,175 baseline, re-measured

| Scope | Records |
|---|---:|
| Translated CPT scope (`guide`+`event`+`leisure`+`sponsor`+`job`+`course_provider`) | **2,175** ✅ |
| B1 editorial scope (`post` 37 + `page` 43) | 80 |
| **Total PT in inventory scope** | **2,255** |

`2,175` is the repository-defined translated-CPT scope and is measured, not
assumed. `post` and `page` are reported separately because the documented
2,175 contract deliberately excludes them.

### 2.3 Taxonomy state

| Taxonomy | Terms | PT | **EN** | unassigned | Contract |
|---|---:|---:|---:|---:|---|
| `conexao_category` | 58 | 58 | **0** | 0 | **translated** |
| `conexao_tag` | 0 | 0 | **0** | 0 | **translated** (empty) |
| `conexao_county` | 26 | 0 | 0 | 26 | **shared** ✅ |
| `conexao_town` | 251 | 0 | 0 | 251 | **shared** ✅ |

`conexao_county` and `conexao_town` correctly carry **no language and no
translations** — the same physical term serves both languages, exactly as
`inc/i18n/guard.php` documents. They do not enter the translated-taxonomy
manifest (§10).

### 2.4 Routing baseline

All twelve primary routes answer `200` with the correct `<html lang>`, self- or
PT-canonical as documented, and the `pt-BR`/`en`/`x-default` hreflang set. **0
incorrect redirects, 0 unexpected 404s.** Full matrix:
`docs/evidence/2026-09-30-en-translation-inventory/03-route-matrix.txt`.

| Route | Status | `lang` | Canonical | Classification |
|---|---|---|---|---|
| `/en/guias/` | 200 | `en-US` | `/en/guias/` | real EN archive, **empty** (B1, no EN records yet) |
| `/en/guias/{pt-slug}/` | 200 | `en-US` | `/guias/{pt-slug}/` | B1 replaced-master → serves the PT guide |
| `/en/guias/{authored-en-slug}/` | **404** | `en-US` | — | **proves the EN guide record does not exist** |

## 3. The B1/B2 model, established before counting

Read from HEAD, not inferred from URLs.

**B1 — real EN records required** (`conexao_b2_post_types()` deliberately
omits them; a real linked EN record is the only acceptable outcome):

| Stage | Type | Why B1 |
|---|---|---|
| `en-guide` | `guide` | `/en/guias/` must be populated by authored EN guides, never the PT body under an English shell |
| `en-post` | `post` | Blog posts translate 1:1 |
| `en-page` | `page` | key narrative/legal pages are real translations |
| `en-blog-page` | `page` | the `/en/blog/` posts page |
| `en-jobs-page` | `page` | the `/en/empregos/` landing page |

**B2 — PT content served under an EN URL; no EN duplicate identity:**

| Scope | Type | Why B2 |
|---|---|---|
| `en-leisure-description` | `leisure` | the English is a **field on the same PT record** (`_leisure_excerpt_en`); a second leisure identity is forbidden |
| `en-course-provider-description` | `course_provider` | same shape (`_provider_excerpt_en`) |
| *(no stage)* | `event`, `sponsor`, `job` | directory types resolved through the B2 fallback; language is a record attribute only |

**Nothing was converted in either direction.** `en-guide` stayed B1 even though
its archive is currently empty, and the two description stages stayed B2
fields — creating an EN `leisure` post would have been a *new* requirement, not
an inventory finding.

**The distinction that matters most:** `/en/empregos/` and `/en/blog/` are
`200` and self-consistent in language, yet **no EN page record exists** for
either. Routing succeeding is *not* evidence that content is required. Per §10
this was not turned into a false requirement — and conversely, the two shared-
slug page stages are classified B1 `CREATE_CANDIDATE` because the **stage
definitions** call for a linked EN record, not because the route responds 200.

## 4. Stage inventory (read from HEAD)

| Property | `en-guide` | `en-page` | `en-post` | `en-blog-page` | `en-jobs-page` | `en-leisure-description` | `en-course-provider-description` |
|---|---|---|---|---|---|---|---|
| Registered | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| Target | `guide` + 13 `conexao_category` terms | `page` | `post` | `page` `blog` | `page` `empregos` | `leisure` | `course_provider` |
| B1/B2 | **B1** | **B1** | **B1** | **B1** | **B1** | **B2** | **B2** |
| Strategy | linked EN record | linked EN record | linked EN record | linked EN record, **shared slug** | linked EN record, **shared slug** | field on the PT record | field on the PT record |
| Authored rows | 51 + 13 terms | 31 | 42 | 1 | 1 | 289 | 10 |
| Source selection | published PT, language `pt` | same | same | same | same | published PT, language `pt` | same |
| Expected EN identity | PT slug (key) | PT slug | PT slug | PT page slug `blog` | PT page slug `empregos` | — | — |
| EN slug rule | authored `en_slug` | authored `en_slug` | authored `en_slug` | **reuses PT `post_name`** | **reuses PT `post_name`** | none (no slug minted) | none |
| Linking | `pll_save_post_translations` both ways | same | same | same + duplicate guard | same + duplicate guard | deliberate no-op | deliberate no-op |
| Field mapping | title/excerpt/content/meta_description | same | same | same | same | `_leisure_excerpt_en` | `_provider_excerpt_en` |
| Shared taxonomies | never created/re-slugged | n/a | n/a | n/a | n/a | `conexao_county`/`conexao_town` untouched | same |
| Stable identifier | PT slug (never a local ID) | same | same | same | same | PT slug | PT slug |

## 5. PT source inventory (deterministic)

The repository's own manifests were dumped by executing the plugin's data files
in PHP (`00-repository-stage-manifests.json`) and joined to live production by
**PT slug** — the only portable identity the standard allows. Titles are
recorded for readability and are never a mutation key.

Every source record carries: WordPress ID, post type, slug, status, title,
excerpt, date/modified, parent, menu order, taxonomy term ids, canonical URL,
own language, and the record's translation links.

## 6. Existing EN state (inventoried separately)

| post_type | EN records | EN ids | EN slugs | linked PT↔EN pairs | orphan EN | duplicates |
|---|---:|---:|---:|---:|---:|---:|
| `guide` | **0** | — | — | 0 | 0 | 0 |
| `post` | **0** | — | — | 0 | 0 | 0 |
| `page` | **0** | — | — | 0 | 0 | 0 |
| `event` / `leisure` / `sponsor` / `job` / `course_provider` | **0** | — | — | 0 | 0 | 0 |
| `conexao_category` | **0** | — | — | 0 | 0 | 0 |
| `conexao_tag` | **0** | — | — | 0 | 0 | 0 |

The previous "EN = 0" baseline was **not** assumed — it was re-verified live
and is still 0. There are no pre-existing EN records to protect, and none were
overwritten (there was nothing to overwrite).

**Corroborating negative evidence:** `/en/guias/autumn-in-ireland-eating-and-wellbeing/`
(the authored EN slug of a guide row) returns **404**, and
`/wp-json/wp/v2/guide?slug=…` returns `[]`. The EN guide records the
documentation describes do **not** exist in production — see §9.

## 7. Taxonomy scope separation

* **`conexao_category` (translated)** — 58 PT, 0 EN. The `en-guide` stage's
  13 authored EN terms are in the manifest as `CREATE_CANDIDATE`. No term is
  created or assigned here.
* **`conexao_tag` (translated)** — empty (0 terms). Nothing to translate.
* **`conexao_county` (shared, 26) and `conexao_town` (shared, 251)** — no
  language, no translations, **0 shared terms in the manifest**. The manifest
  consistency check `taxonomy_consistency` fails closed if either ever enters.

## 8. The EN manifest

Manifest digest (SHA-256 over the canonical manifest, identical in three
independent runs):
`7d7b066f0eb5aaa89b61642bed65482f784ad1a492e769fdd01549b8624c528a`

### 8.1 Stage matrix

| Stage | Type | B1/B2 | PT source count | EN existing | Create candidates | Conflicts | Orphans | Out of scope |
|---|---|---|---:|---:|---:|---:|---:|---:|
| `en-guide` | `guide` + 13 terms | B1 | 65 | 0 | **62** | 0 | 0 | 3 |

### 8.2 Totals

| Metric | Count |
|---|---:|
| Total source objects | **2,277** |
| **B1 create candidates** (real EN records to be created) | **131** (49 guides + 13 terms + 31 pages + 1 blog page + 1 jobs page + 36 posts) |
| **B2 objects** (English field written onto the existing PT record) | **299** (289 leisure + 10 course providers) |
| **Total expected EN objects** | **430** |
| Already-complete objects | 0 |
| Conflicts | 0 |
| Orphan objects | 0 |
| Invalid objects | 0 |
| B2-only (no EN object by design) | 1,820 |
| Out of scope | 27 |
| **Reconciliation** | `131 + 299 + 1,820 + 27 = 2,277` ✅ |

### 8.3 Per-object fields

Every manifest row carries: source PT ID, source post type/taxonomy, source
slug, target language `en`, target object type, target slug where
deterministically defined, translation-relationship expectation, stage name,
B1/B2 classification, reason for inclusion, whether an EN object already
exists, whether it is eligible for creation, and whether a conflict was
detected. See `01-en-inventory-manifest.json`.

## 9. Expected vs actionable — and the stated limitations

### 9.1 The three headline findings

**(a) `en-blog-page` and `en-jobs-page` have no EN page record in production.**
`docs/routing.md` describes `/en/empregos/` and `/en/blog/` as bilingual
"fully" and "the approved shape", and both routes answer `200`. But only **one**
page record holds each slug (PT `blog` id 10; PT `empregos` id 11086), and both
report `translations: []`. The EN pages the documentation describes **do not
exist in production**. Classified `CREATE_CANDIDATE` because the *stage
definitions* require a linked EN record — not because a route responds 200.

**(b) The EN Guides rollout of 2026-09-28 was a LOCAL apply; production has 0.**
`docs/routing.md` states the EN Guides archive is "**applied 2026-09-28**: one
linked EN `guide` per public PT guide (48/48), plus the 13 linked EN
`conexao_category` terms". Live production contradicts every number: `guide` EN
= 0, `conexao_category` EN = 0, `/en/guias/` renders empty, and the authored EN
guide slug `/en/guias/autumn-in-ireland-eating-and-wellbeing/` returns **404**.

This is **not** a contradiction inside the repository:
`docs/reports/README.md` records that rollout as *"run with
`scripts/run-en-translation.php --only=guide`"* — a local/dev apply — and
`docs/reports/2026-09-29-production-deployment-readiness.md` lists **"EN Guides
(48) | production: No"**. So the authoritative reading is: **the 48/48 Guides
rollout has not been applied to production**, and `docs/routing.md` states the
outcome without qualifying the environment. The same applies to the
`en-jobs-page` rollout ("The EN Jobs destination becomes the PT-derived
`/en/empregos/`", EN record 33309) — no such page exists in production.

**Reported, not reconciled.** The task forbids modifying production, and
"apply the Guides stage to production" is a Phase 2 decision, not an inventory
action. But Phase 2 must not treat `docs/routing.md` as evidence that EN Guides
or the EN Jobs page already exist: they do not.

**(c) The authored dataset is slightly out of date against production** — 9
authored rows have no PT source:

| Stage | Authored slug | Why |
|---|---|---|
| `en-guide` | `outono-irlanda-alimentacao-bem-estar` | the concept exists in production as a **`post`** (id 25028), not a `guide` |
| `en-guide` | `carteira-de-motorista-2` | the production guide is `carteira-motorista-brasileiros` (id 25031) — a PT slug rename |
| `en-guide` | `learner-permit-theory-test-irlanda-cnh-brasileira` | no production guide has this slug |
| `en-post` | 6 slugs (`auxilios-…`, `cursos-e-apoio-para-empreendedores`, `dica-de-saude-…`, `guia-para-quem-esta-com-dificuldades-financeiras`, `guia-pratico-para-brasileiros-em-laois`) | no production post has these slugs (all 6 verified individually, 0 matches) |

These are `OUT_OF_SCOPE` (the engine's own `skip`: *"PT record absent in this
site (documented exclusion)"*), **not** conflicts — the stage can still run and
the gate will still pass. The practical consequence, which Phase 2 must know:
**applying the current datasets as-is yields 48/51 EN guides and 36/42 EN blog
posts, not 51/51 and 42/42.** Re-keying those 9 rows is an editorial data
change and belongs to Phase 2, not here.

### 9.2 One flagged creation risk (not a conflict)

`en-page :: newsletter` authors the EN slug `newsletter`, which the PT record
itself already holds. `en-page` has **no** shared-slug permit (only
`en-blog-page` and `en-jobs-page` arm
`conexao_en_translation_with_shared_page_slug()`), so WordPress would uniquify
the new EN record to `newsletter-2` — the exact pre-existing slug debt Stage O
documents for `jobs-2`/`newsletter-2`. In production neither `newsletter-2` nor
`jobs-2` exists, so the stage will *create* rather than *repair*. Recorded as
`slug_creation_risk` on the row; it is a Phase 2 dry-run observation, not a
reason to block.

### 9.3 A measurement limitation, stated rather than glossed


## 10. Manifest consistency (all checks pass, fail-closed)

| Check | Result | Evidence |
|---|---|---|
| No duplicate source IDs | **PASS** | 0 duplicates across stage+object+slug |
| No duplicate target identity | **PASS** | 0 collisions on `post_type::target_slug` |
| No conflicting stage ownership | **PASS** | no source claimed by two stages |
| Stable identifiers | **PASS** | keys are IDs and the PT slug; titles are never a mutation key |
| No PT overwrite | **PASS** | 0 PT records are a write target |
| No accidental EN update | **PASS** | 0 existing EN records; none proposed for creation |
| Taxonomy consistency | **PASS** | 0 `conexao_county`/`conexao_town` terms in the manifest |

The shared-slug rows (`en-blog-page::blog`, `en-jobs-page::empregos`,
`en-page::newsletter`) reuse the PT `post_name` **by design**. A shared slug is
a routing shape, not a fork: each still creates a *new* EN-language record and
only ever *reads* the PT record. They are reported explicitly rather than
silently folded into "no PT overwrite".

## 11. Determinism

Two full, **uncached** reads of live production (`05-determinism.json`):

| | run 1 | run 2 | run 3 (safety re-read) |
|---|---|---|---|
| manifest digest | `7d7b066f0eb5aaa8…` | `7d7b066f0eb5aaa8…` | `7d7b066f0eb5aaa8…` |
| manifest rows byte-identical | — | **yes** | **yes** |
| PT identity digest | `cfe4b7a670266da3…` | `cfe4b7a670266da3…` | `cfe4b7a670266da3…` |
| per-type PT content digests | — | **all equal** | **all equal** |

Determinism comes from a total ordering — every collection is paged in
`orderby=id&order=asc`, and the manifest is sorted by
`(stage, object_type, source_slug, source_pt_id)` before hashing.

## 12. PT protection baseline

Captured for the whole inventory scope: per-type IDs, slugs, statuses,
published counts, an **identity digest** (`id, slug, status`) and a **content
digest** (title, excerpt, date, modified, parent, menu order, taxonomy
assignments, translation links). Both are plain SHA-256 over a canonical
key-sorted serialisation and are reproducible by re-running the same command.

| post_type | PT records | published | identity digest | content digest |
|---|---:|---:|---|---|
| `guide` | 56 | 56 | `035b850c63cb5bc7…` | `b6b6700ee964e5ca…` |
| `event` | 1,808 | 1,808 | `e62772a6c316777b…` | `3c444ce3cedcd578…` |

## 13. Safety ledger

| | |
|---|---|
| **Production writes** | **0** |
| HTTP verbs issued | `GET` only (the tool defines no other verb) |
| **EN records created** | **0** |
| **Translation links created** | **0** |
| **Taxonomy translations created** | **0** (EN `conexao_category` 0, EN `conexao_tag` 0) |
| **PT drift** | **0** (identity and content digests identical across all three runs) |
| PT translated-scope records | 2,175 — matches the verified baseline |
| **`.env` changed** | **NO** (md5 `e0e2ff20b47ebfee98838c976a8e331f` before and after) |
| **Secrets exposed** | **NO** (credentials read from the environment only; never logged, never written to evidence) |
| `run-en-translation.php` invoked | **NO** — no `--apply`, no `--remove`, no dry-run writes |
| Snapshots created for mutation | **0** |
| Plugins deployed | **0** |
| Menus / options / theme mods touched | **0** |
| Source changes | none — `git status` shows only this report, the evidence directory and one `docs/reports/README.md` line |

`_leisure_excerpt_en` and `_provider_excerpt_en` are **not** REST-registered
(`register_post_meta(..., show_in_rest)` is never called for them), so their
per-record presence cannot be read through the API. Their state was established
instead by the rendered EN archives, which the theme reads them on:
`/en/lazer/` and `/en/cursos/` both render **Portuguese** card text, which is
the positive proof that neither field is stored. This is deterministic and
reproducible, but it is a rendered-output signal rather than a per-record
field read, and Phase 2's snapshot must re-check it in the same way.

`page` is likewise **not** in `conexao_rest_language_post_types()`, so the
pages collection carries no `conexao_language` field. Page language and
translation links were resolved through the `search-result` surface, which
registers the same field — 43/43 pages resolved, all `pt`, all with
`translations: []`.

| `en-page` | `page` | B1 | 31 | 0 | **31** | 0 | 0 | 0 |
| `en-post` | `post` | B1 | 42 | 0 | **36** | 0 | 0 | 6 |
| `en-blog-page` | `page` | B1 | 1 | 0 | **1** | 0 | 0 | 0 |
| `en-jobs-page` | `page` | B1 | 1 | 0 | **1** | 0 | 0 | 0 |
| `en-leisure-description` | `leisure` | B2 | 289 | 0 | **289** | 0 | 0 | 0 |
| `en-course-provider-description` | `course_provider` | B2 | 10 | 0 | **10** | 0 | 0 | 0 |
| *(no stage)* B2 directory scope | `event`/`sponsor`/`job`/uncovered B2 | B2 | 1,820 | — | 0 | 0 | 0 | 0 |
| *(no stage)* B1 uncovered scope | `guide`/`page`/`post` with no authored row | B1 | 18 | — | 0 | 0 | 0 | 18 |
| **Total** | | | **2,277** | **0** | **430** | **0** | **0** | **27** |

The 18 stage-less B1 out-of-scope records are 7 `guide`, 10 `page` and 1 `post`
published in production for which no stage carries an authored English row:

* `guide` — `carteira-motorista-brasileiros`, `como-comprar-casa-irlanda-mortgage`,
  `profissoes-na-irlanda`, `registrar-company-irlanda`,
  `registrar-self-employed-irlanda`, `saude-da-mulher-irlanda-hse-servicos`,
  `small-claim-irlanda`
* `page` — the ten county/region pages `cork`, `dublin`, `galway`, `irlanda`,
  `kildare`, `laois`, `limerick`, `meath`, `waterford`, `wicklow`
  (B2-eligible directory pages per the documented allowlist, deliberately not
  B1-translated)
* `post` — `outono-irlanda-alimentacao-bem-estar` (the very slug the orphaned
  `en-guide` row is keyed on — see §9.1c)

They are inventoried and explicitly excluded, which is what lets the
categories reconcile against the **full** discovered corpus rather than only
against the authored manifest.

| Conflict detection | slug collision, broken pair, non-published PT | same | same | + shared-slug duplicate guard | same | PT-drift vs authored source | PT-drift vs authored source |
| Idempotence | re-run reports zero creates | same | same | same | same | same | same |
| Eligible against this production | **yes** (EN = 0) | **yes** | **yes** | **yes** | **yes** | **yes** (field absent) | **yes** (field absent) |

**Implementation vs production state: they agree.** Every stage is a
`CREATE`-only plan — there is no `update`, no `remove` and no conflict to
resolve. No stage definition was modified and no stage behaviour was "fixed"
during inventory.

| `/en/eventos/`, `/en/lazer/`, `/en/cursos/`, `/en/apoiadores/` | 200 | `en-US` | self | B2 archive over PT records |
| `/en/empregos/`, `/en/blog/` | 200 | `en-US` | PT (shared slug) | B2, **not** a linked EN page record (§7) |

`/en/lazer/` and `/en/cursos/` render **Portuguese** card descriptions
(`04-b2-resolution-samples.txt`), which is the positive proof that
`_leisure_excerpt_en` / `_provider_excerpt_en` are **not** stored in production.
## 14. Acceptance criteria

| # | Required | Result |
|---|---|---|
| 1 | Current production state read successfully | ✅ GET-only read of 8 post types + 4 taxonomies + Polylang settings/languages |
| 2 | Current repository EN stages identified from HEAD | ✅ 7 stages, from `conexao_en_translation_stage_ids()` |
| 3 | Every stage classified B1 or B2 | ✅ 5 B1 record stages, 2 B2 field stages; 3 B2 directory types with no stage |
| 4 | Complete PT source inventory deterministic | ✅ 2,277 objects, identical digest across 3 runs |
| 5 | Existing EN state separately inventoried | ✅ EN = 0 everywhere, per post type and taxonomy |
| 6 | Existing translation relationships inventoried | ✅ 0 links, 0 orphans, 0 duplicates |
| 7 | Taxonomy scope correctly separated | ✅ 58/0 category, 0/0 tag, 26 + 251 shared, 0 shared terms in manifest |
| 8 | Deterministic EN manifest produced | ✅ digest `7d7b066f0eb5aaa8…`, reproducible |
| 9 | Explicit CREATE / COMPLETE / B2 / CONFLICT / ORPHAN / INVALID | ✅ all seven categories, reconciling to 2,277 |
| 10 | No production write | ✅ GET only |
| 11 | PT drift remains 0 | ✅ digests stable |
| 12 | EN creation remains 0 | ✅ |
| 13 | Sufficient to drive the next dry-run without re-discovery | ✅ 430 expected EN objects, each with a stable source ID/slug, a target identity, a stage and a B1/B2 classification |

## 15. Status

### PASS — EN INVENTORY READY FOR DRY-RUN

The manifest is complete, internally consistent, deterministic across three
independent production reads, conflict-free and safe as the input to Phase 2.

**Carried forward to Phase 2 (not blocking, but must be decided there):**
1. The EN Guides (48/48) and EN Jobs page rollouts were **local applies**; in
   production EN is still 0 (§9.1b). `docs/routing.md` states the outcome
   without qualifying the environment — either apply the stages or correct the
   documentation.
2. Nine authored rows have no PT source (§9.1c) — applying as-is yields 48/51
   guides and 36/42 posts. Re-keying is an editorial data change.
3. `en-page::newsletter` will be created as `newsletter-2` (§9.2) unless the
   shared-slug permit or the authored slug is addressed.
4. `_leisure_excerpt_en` / `_provider_excerpt_en` are not REST-readable; the
   Phase 2 snapshot must verify them through the rendered EN archives.

**Not started, by design:** dry-run · snapshot · apply · verify · idempotence ·
gate. The next explicit task is:

**EN Translation Rollout — Phase 2: Dry-Run + Snapshot**

| `leisure` | 289 | 289 | `28c0e43bab72581e…` | `b19f53a7c3912abd…` |
| `sponsor` | 10 | 10 | `282853d2913924fa…` | `cfbc5989cc0ea16b…` |
| `job` | 1 | 1 | `893e91ff15b2924e…` | `6e504698fefe5098…` |
| `course_provider` | 11 | 11 | `0aed5ce1ec8efcc6…` | `8305ad313a4c39ba…` |
| `post` | 37 | 37 | `9fac9eac4ab0d22d…` | `9bbbb0afb6fade72…` |
| `page` | 43 | 43 | `b3eea7d8b7c31c9f…` | `7413f5a30e6c35c3…` |
| **all types** | **2,255** | **2,255** | `cfe4b7a670266da3…` | — |

The single all-types PT identity digest, for Phase 2 to compare against:

```
cfe4b7a670266da32ba635f3f46994328143d3de77a914aa52491d42e3a6f853
```

This baseline is sufficient for Phase 2 to prove **PT drift = 0** by recomputing
the same digests after apply and comparing them to the values above.

