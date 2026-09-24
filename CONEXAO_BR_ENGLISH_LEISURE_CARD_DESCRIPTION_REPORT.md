# Conexão BR Irlanda — Stage 7.x: English Leisure card descriptions

**ENGLISH LEISURE CARD DESCRIPTION — BLOCKED**

Stage: 7.x — “Restore English descriptions on /en/lazer/” (Leisure card excerpt only)
Date: 2026-09-24 · Branch: `cline/rx6v6bw5` · Flutter: **frozen — never touched**
Scope guard: no Events / Guides / Jobs / Blog / Sponsors / Courses / REST contracts / mobile code were modified. **No code was changed at all** — the measurement itself proved the stage’s precondition false.

---

## 0. Executive summary

The stage asked: make `.leisure-card-excerpt` on `/en/lazer/` render the **original English source description**, wherever that English text genuinely exists, without inventing or machine-translating anything, and without changing PT `/lazer/`.

**Phase 0 measured the entire Leisure population (289/289 records) and proved the premise false: there is no original English description anywhere in the Leisure data.** Every Leisure description — card excerpt (`post_excerpt`), full description (`post_content`) and practical notes (`_leisure_practical_notes`) — is the project’s **own authored Portuguese text**, written in Portuguese from day one (verified in every authoring artifact in the repository). No `_leisure_*` meta field, no SEO description field and no import/export artifact ever carried English description prose. The upstream English pages (Heritage Ireland/OPW, Discover Ireland) are stored as **URLs only**, and their prose is third-party copyrighted content that the project’s own Stage A audit explicitly forbids copying.

Per the stage’s own rules (§5 “do not pretend it is English”; §26 “if investigation proves that the original English text is not actually available … stop and document that finding rather than inventing replacement English”), the stage therefore **stops at the measurement**: no fix is implemented, no English text is invented, PT `/lazer/` is provably unchanged (byte-identical before/after captures), and the architecture (`_leisure_uuid` / `_leisure_export_uuid`, B2 fallback, no duplicate records) is untouched because nothing was modified.

The only legitimate path to English Leisure card excerpts is the architecture-approved translation model (human-authored EN Leisure records linked via Polylang — the Stage 3.2 pilot `phoenix-park-en` model), which is a full Leisure translation stage and explicitly out of 7.x scope.

| Metric | Value |
|---|---|
| Leisure records inspected | **289 / 289** (complete production population via public REST) |
| Records with original English description | **0** |
| Records using original English description on EN archive | 0 (not applicable — no source exists) |
| Records without English source | **289** |
| Records using fallback | 289 (EN archive stays approved B2: PT content + EN chrome, unchanged) |
| EN excerpts verified English | 0 (none can be — no English source exists to render) |
| PT excerpts changed | **0** (no code change; byte-identical HTTP captures) |
| PT excerpts unchanged | **289/289 stored** (10/10 rendered on `/lazer/` page 1 verified byte-equal to `post_excerpt`) |
| EN cards verified | EN archive not deployed to production (301 → `/lazer/`, unchanged); sandbox EN-archive behaviour documented from Stage 3.2 measurements + code trace |
| Internal links changed | **0** |
| UUIDs changed | **0** (`_leisure_uuid` / `_leisure_export_uuid` untouched — nothing written) |
| Duplicate records created | **0** (nothing written anywhere) |
| HTTP checks passed | 3/3 probes × 2 runs (before + after) — all statuses/excerpts/canonicals as expected |
| HTTP checks failed | 0 |
| Regression tests passed | Not applicable — no fix exists to pin; no test added (decision documented in §13) |
| Production deployment | Not performed (nothing to deploy) |
| Final classification | **ENGLISH LEISURE CARD DESCRIPTION — BLOCKED** |

---

## 1. Scope and environment

| In scope | Out of scope (untouched) |
|---|---|
| `.leisure-card-excerpt` rendering on `/en/lazer/` — **investigation only** | Every CPT’s content and every shared theme file (zero modifications) |
| Phase 0 measurement of the actual Leisure source data | Flutter/mobile app (completely frozen) |
| Production read-only REST + HTTP captures | Events, Guides, Jobs, Blog, Sponsors, Courses, REST contracts |
| Evidence deliverables (`stage7-work/`) | Leisure identity (`_leisure_uuid`, `_leisure_export_uuid`), B2 mechanism, routing, `inc/seo.php` |

**Verification environment.** This sandbox has no Docker and no local WordPress database (same constraint as Stages 5/6). Production, however, **is readable** through its public REST API and HTML, so the Phase 0 measurement ran against the **real production Leisure dataset** — a stronger source of truth than any local clone:

- `https://conexaobr.ie/wp-json/wp/v2/leisure` (public REST, read-only): 3 pages × 100, `x-wp-total: 289` → `stage7-work/source/leisure-page-{1,2,3}.json` (fields: `id, slug, link, title, excerpt, content, meta, date, status`; the REST meta exposure includes every `_leisure_*` field).
- Raw HTML of `/lazer/`, `/en/lazer/`, `/lazer/?county=dublin` → `stage7-work/http-cache/`.
- Repository authoring artifacts (seed scripts, importer datasets, Wix inventory) for provenance.
- No write request of any kind was made; production was only read.


---

## 2. Phase 0 — measured reality (the decisive evidence)

Full inventory: `stage7-work/leisure-description-inventory.json` (all 289 records, per-record language of every text-bearing field) and `stage7-work/leisure-description-inventory.md` (evidence tables). Method: PT/EN stopword-and-diacritic detector over every text value, plus full manual review of all 289 excerpts and every unique meta value (`stage7-work/census-meta-values.out`).

### 2.1 Where the current Portuguese card excerpt comes from

`template-parts/leisure-card.php:154`:

```php
<p class="leisure-card-excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 18, '...' ) ); ?></p>
```

`get_the_excerpt()` → **`post_excerpt`** (all 289 records have a non-empty excerpt; the admin «Descrição curta» field `_leisure_short_description` is a virtual alias that edits `post_excerpt`, not separate storage — `conexao-admin-ux/includes/class-fields.php`). Proven byte-level against production: all 10 card excerpts rendered on `/lazer/` page 1 equal the REST `post_excerpt` of the same records trimmed to 18 words + `...` — 10/10, 0 mismatches (`http-before.json → excerpt_vs_rest`).

### 2.2 Where the original English description exists — nowhere

| Candidate location | Result |
|---|---|
| Database `post_excerpt` (the card excerpt source) | **PT on 289/289** records |
| Database `post_content` | **PT on 289/289** (1 “mixed” = `gap-of-dunloe`, a PT sentence with English proper nouns) |
| `_leisure_*` custom fields (complete inventory: 30 keys × 289 records via REST; code-verified full set) | **No English text.** The only EN-flagged values are URLs, postal addresses, image-source URLs and proper-noun alt texts («X, County, Irlanda»). No description-bearing `_leisure_*` field exists. |
| `advanced_seo_description` (SEO field) | **empty on 289/289** |
| `jetpack_seo_html_title`, `footnotes` | empty on 289/289 |
| Imported/exported source artifacts | **All Portuguese-authored**: `scripts/seed-leisure-locations.php` + `scripts/data/leisure-expansion-data-1/2/3.php` together author **245 unique slugs — and production contains exactly those 245** (REST publication dates: 55 on 2026-08-19, 178 on 2026-08-21, 12 on 2026-09-10 = the Stage C batch), plus a **44-record OPW/Heritage Ireland batch dated 2026-09-15** with no authoring artifact in this repository. `docs/importers/lazer-expansion-stage-b-dataset.json` (its `description` values are PT), `lazer-expansion-stage-c-final-dataset.json` (no description field). **0 English entries** in every authored excerpt/content pair. |
| Wix migration source | `content-inventory/lazer-existing-inventory.csv` has **no description column** — the Wix Lazer pages carried no descriptions. |
| Upstream English pages (Heritage Ireland/OPW, Discover Ireland) | Linked as **URLs only** (`_leisure_official_website`: 203 records; `_leisure_discover_ireland`: 89). Their English prose was never captured or stored — **by explicit project rule**: the Lazer Stage A audit states “Copyrighted content not to be copied: source descriptions/prose … Existing records keep their own authored Portuguese excerpts/posts”. |

### 2.3 Evidence table (short form)

| Record | Current card excerpt | Original source | Source language | Candidate EN value in data |
|---|---|---|---|---|
| `dwyer-mcallister-cottage` | Portuguese («Casa rural restaurada aos pés da montanha Keadeen…») | `post_excerpt` (authored PT, seed script) | Portuguese | **none** |
| `fore-abbey` | Portuguese | `post_excerpt` | Portuguese | **none** |
| `altamont-gardens` | Portuguese | `post_excerpt` (authored PT, `leisure-expansion-data-2.php`) | Portuguese | **none** |
| `queen-maeves-trail-knocknarea` | Portuguese | `post_excerpt` (authored PT, Stage C dataset) | Portuguese | **none** |
| `gap-of-dunloe` | Portuguese | `post_excerpt` | Portuguese | **none** |
| … all 284 others | Portuguese | `post_excerpt` | Portuguese | **none** |

**Answer to the Phase 0 question:** the current PT card excerpt comes from `post_excerpt`; the “original English description” **is not stored in the database, not in any custom field, not in `post_content`, not in any import/export artifact** — it exists only on third-party websites the project deliberately never copied. **The Leisure descriptions are original Portuguese editorial content, not translations of an English source.** The task’s hypothesis (“Original Leisure source description = English”) is disproven for **every** record (0/289), not just some.

---

## 3. Phase 1 — render trace of `leisure-card-excerpt` (documented, not modified)

| Step | Location | Detail |
|---|---|---|
| Archive query | `leisure` post-type archive → `archive.php` | PT: normal archive query. EN `/en/lazer/`: B2 set — PT records + EN chrome (Stage 3.2; canonical/hreflang owned by `inc/seo.php` + Polylang helpers; B2 redirect suppression in `inc/polylang.php` `conexao_polylang_language_redirect_is_temporary()`, case 2: post-type archives) |
| Card include | `archive.php:131` | `get_template_part( 'template-parts/leisure', 'card' )` — same card in both languages |
| Excerpt line | `template-parts/leisure-card.php:154` | `esc_html( wp_trim_words( get_the_excerpt(), 18, '...' ) )` — **no language handling** |
| Source field | `post_excerpt` | PT on all 289 records |

- **Current behaviour:** PT and EN archives render the same PT `post_excerpt`.
- **EN expected behaviour (task):** original English description where it exists — **it exists for no record**.
- **PT expected behaviour:** unchanged — **verified unchanged** (nothing modified).

The smallest possible change would have been a language-aware excerpt selection in the card template. It was **not implemented** because there is no English source value to select: any selection either keeps PT (a no-op) or requires invented English (forbidden by §5/§26).

---

## 4. Phases 2–12 — why each implementation phase is a documented no-op

The stage’s implementation phases were all **conditional on an existing English source** (“uses the original English Leisure description when that source is confirmed to be English”). The condition is false for 289/289 records, so each phase resolves to its documented safe state rather than to a code change:

| Phase | Prescribed action if EN source existed | Actual outcome (no EN source exists) |
|---|---|---|
| 2 — language-specific description selection | `if (EN && original EN description exists) use it; else existing behaviour` | The `if` branch has an empty population on both sides; implementing it would be dead code wrapping forbidden invented text. **Not implemented — documented instead.** |
| 3 — empty/missing source handling | approved fallback | This is the state of **all 289 records**: the existing approved B2 behaviour (PT record + EN chrome) remains, exactly as today. Nothing else was permissible (“Do not replace an existing valid PT description globally”). |
| 4 — HTML/markup preservation | preserve source markup | No source text exists to preserve. (For the record: the stored PT excerpts are plain text; the card renders them through `esc_html()` + `wp_trim_words()`.) |
| 5 — B2 compatibility | keep B2 mechanism | **Untouched** — zero code changes; `conexao_b2_post_types()` still includes `leisure`; EN Leisure requests keep rendering the approved B2 fallback. |
| 6 — Leisure identity | preserve `_leisure_uuid` / `_leisure_export_uuid`, no duplicates | **Untouched** — nothing was written to the database; no record created, modified or duplicated. (Measured note: production records expose no UUID meta over REST — consistent with UUIDs being assigned by the local ZIP export pipeline; this stage neither reads nor writes them.) |
| 7 — multi-record check | per-record EN availability census | **Done for all 289 records** — `leisure-description-inventory.json` carries per-record `original_english_description_in_data: false` (289/289). No estimates. |
| 8 — `/en/lazer/` acceptance | EN excerpts English | **NOT APPLICABLE** — no English source exists to render; the EN archive keeps its approved B2 state. Production `/en/lazer/` is still 301 → `/lazer/` (English layer deployment pending, unchanged). |
| 9 — PT acceptance | PT unchanged | **PASS by measurement**: `/lazer/` 200, 10 cards, excerpts byte-equal to `post_excerpt`, same ordering/links/images; before/after captures byte-identical. |
| 10 — SEO/routing regression | routing untouched | **PASS trivially** — no routing/SEO code touched; `inc/seo.php` unmodified; `/lazer/` and `/en/lazer/` behave exactly as before. |
| 11 — cache regression | language-scoped caches | **PASS trivially** — no cache code touched. Measured: the Leisure archive is not transient-cached (theme transients are front-page/404 only), so no PT/EN cache cross-contamination is possible; nothing changed. |
| 12 — search/detail regression | detail URLs/search unchanged | **PASS trivially** — zero code changes anywhere; detail URLs, search scoping and card links are byte-identical (before/after HTTP captures equal). |

---

## 5. Phase 13 — automated test: deliberate decision

No regression test was added. The requested test would pin “EN request + original English Leisure description exists → card uses it”. Since **no record in the entire population has an English source description**, the test’s precondition can never be satisfied; the only test writable against the current data would *pin the PT excerpt on the EN archive as expected behaviour* — i.e. codify the defect as a contract, which would be misleading. Per §23 (“Create only the necessary artifacts”) and §16 (scope control), the finding is documented in the inventory instead. A future stage that authors English Leisure descriptions (the Stage 3.2 pilot model) owns both the renderer change and its regression test.


## 6. Phase 14 — before/after evidence

- **Before** (`stage7-work/http-before.{json,md}` + `http-cache/`): production `/lazer/` 200 with PT card excerpts (raw HTML captured); `/en/lazer/` 301 → `/lazer/` (English layer not deployed to production yet — the defect lives where the Stage 4.3 layer is deployed, measured in the Stage 3.2 sandbox: `/en/lazer/` 200 B2 with PT cards); card excerpts proven byte-equal to `post_excerpt`.
- **After** (`stage7-work/http-after.{json,md}`): **no change made**: before/after captures compare **byte-identical** (`b == a` → `True`). The requested “after” state (EN excerpts in English) is **NOT APPLICABLE** — there is no original English description to render, and inventing one is forbidden.
- No browser was used; all evidence is raw-HTTP/HTML-level. No sandbox WordPress clone was rebuilt: the decisive question is answered by the production data itself, and a clone cannot contain English text that the real data does not have. These limitations are stated explicitly.

## 7. Phase 15 — regression against Blog + Jobs

No shared code was changed (git status: only new files under `stage7-work/`). Language helpers (`inc/polylang.php`, `inc/i18n.php`), Polylang behaviour, B2 helpers, archive URL resolution and cache helpers are byte-identical to the Stage 6 state, therefore the Stage 5/6 Blog and Jobs results hold by construction. Production spot-checks from this stage’s captures additionally confirm the site renders normally (`/lazer/` 200, `/lazer/?county=dublin` 200). `/en/blog/` and `/en/jobs/` remain future production routes (English layer deployment pending) — unchanged by this stage, which touched nothing.

### 7.1 Discovered pre-existing defect (reported, not fixed — outside this stage’s scope)

As an unchanged-baseline check, the full PHP tree was linted with the sandbox PHP 8.4.23 CLI (`.local/php/php -l`, 229 theme/plugin files + 75 `scripts/` files). **One file at HEAD (`dc8fafa`, the Stage 6 commit) has a fatal parse error — untouched by this stage (git-verified):**

- **File:** `wp-content/plugins/conexao-page-translation/includes/translation-map.php` (the Stage 4.5 EN pages importer’s translation map — a rollout-only plugin, currently not needed on production)
- **Defect (proven by bisection + lint):** the `'blog'` map entry opens `array(` at line 98 and its closer (a line containing only `),` at two tabs of indentation) is **missing after line 103**; a compensating **orphan closer of the same shape sits at EOF (line 368)**, after `conexao_page_translation_hub_content()` already closed at line 366. PHP reports `syntax error, unexpected token ";", expecting ")" … on line 291`. The plugin cannot be loaded by PHP in this state — activating it for the planned Stage 4.5 production rollout would fatal wp-admin.
- **Verified remedy (NOT applied — this file belongs to the closed Stage 4.5 deliverable and is unrelated to Leisure; fixing it here would violate this stage’s scope control):** re-insert the `),` closer after line 103 (closing the `'blog'` entry) and delete the orphan closer at line 368. A patched copy at `/tmp/translation-map-fixed2.php` lints clean (`No syntax errors detected`) — reproduced twice from the committed file.
- **Impact on this stage: none.** The Leisure finding rests on production REST data and live HTML, not on this plugin; Leisure rendering never loads it. The defect is flagged here for the Stage 4.5 owner/operator ahead of the pending English-layer deployment.
- All other 303 PHP files in the repository lint clean.


## 8. Phase 16 — scope control: confirmed

Not done, by measurement or by design: no Leisure titles translated, no full descriptions translated, no EN Leisure CPT records created, no duplicates, no UUID changes, no Events/Guides/Jobs/Blog/Sponsors/Courses changes, no Flutter/REST changes, no archive redesign, no PT content change. **Zero code and zero content modifications** — the complete change set of this stage is new evidence/documentation files only.

---

## 9. Deliverables

| File | Content |
|---|---|
| `CONEXAO_BR_ENGLISH_LEISURE_CARD_DESCRIPTION_REPORT.md` | this report |
| `stage7-work/leisure-description-inventory.json` | machine-readable inventory: all 289 records, per-record excerpt/content/practical-notes language, upstream links, `original_english_description_in_data` flag (false × 289) |
| `stage7-work/leisure-description-inventory.md` | human-readable Phase 0 evidence tables + field-location table |
| `stage7-work/http-before.json` / `http-before.md` | production HTTP + card-excerpt evidence, before (redirects not followed; excerpt-vs-REST byte comparison) |
| `stage7-work/http-after.json` / `http-after.md` | re-capture after the (null) change — byte-identical; NOT-APPLICABLE framing for the EN gate |
| `stage7-work/source/leisure-page-{1,2,3}.json` | raw read-only production REST captures (the 289-record dataset) |
| `stage7-work/http-cache/` | raw production HTML (`prod-lazer.html`) + the `/en/lazer/` 301 response headers (`prod-en-lazer.headers.txt` — the 301 carries no body) |
| `stage7-work/analyze-leisure-source.py`, `census-meta-values.py`, `build-inventory.py`, `http-capture.py`, `census-meta-values.out` | the measurement scripts + full census output (reproducible) |

No plugin/theme/test files were added or modified.


---

## 10. Final classification

**ENGLISH LEISURE CARD DESCRIPTION — BLOCKED**

Reason (per the stage’s own BLOCKED definition — “the original English source cannot be reliably identified or another critical dependency prevents a safe implementation”): the original English source description was reliably **sought and measured across the complete population and every candidate storage** (production database via public REST: `post_excerpt`, `post_content`, all `_leisure_*` meta, SEO fields; repository authoring artifacts: seed scripts, expansion datasets, Wix inventory) and **does not exist for any of the 289 Leisure records**. The Leisure descriptions are the project’s own authored Portuguese content; the only English descriptions in existence are on third-party pages (Heritage Ireland/OPW, Discover Ireland) that the project links to as URLs and — by its own documented copyright rule — must not copy. With no English source text available, the prescribed fix (“use the original English source description”) cannot be implemented for even one record, and every alternative (machine translation, paraphrase, upstream copying) is explicitly forbidden by the stage rules.

**What is NOT blocked:** everything the stage was required to protect is intact and proven — PT `/lazer/` unchanged (byte-identical captures), Leisure identity/UUID architecture untouched (nothing written), B2 mechanism untouched, no duplicates, routing/SEO/cache/search untouched, Blog/Jobs stages unaffected.

**Unblocking path (for the operator):** author English Leisure descriptions and create them through the architecture-approved linked-translation model (Polylang-linked EN records with `_leisure_export_uuid` preserved — the Stage 3.2 `phoenix-park-en` pilot), i.e. a dedicated Leisure translation stage equivalent to Stage 5 (Blog) / Stage 6 (Jobs), with human-authored `en_excerpt` values. At that point the existing B2 renderer already prefers real EN records over PT fallbacks (measured behaviour: `conexao_b2_translation_replaced_pt_ids()` replaces the PT master in the EN archive), and the card will show the EN excerpt on `/en/lazer/` with no further renderer change.