# CONEXÃO BR IRLANDA — ENGLISH LEISURE CARD DESCRIPTIONS
## Stage 7.x — Translate Leisure card descriptions on `/en/lazer/`

**Final classification: ENGLISH LEISURE CARD DESCRIPTIONS — PASS WITH LIMITATION**
(the implementation is complete and fully verified against the real production
dataset in a WordPress validation clone; the identified limitation is operational —
production deployment, see §17/§18).

| | |
|---|---|
| Scope | the description rendered by `.leisure-card-excerpt` on the Leisure archive |
| PT archive | `/lazer/` — unchanged (verified byte-identical) |
| EN archive | `/en/lazer/` — now renders the authored English descriptions |
| Records in scope | 289 published `leisure` records (production population, measured) |
| Records translated | 289 / 289 (0 skipped, 0 empty, 0 refused) |
| Data model | `_leisure_excerpt_en` post meta on the SAME records (no EN leisure records, no duplicates) |
| Identity | `_leisure_uuid` / `_leisure_export_uuid` unchanged (measured 0 changes) |
| HTTP acceptance | 4,783 checks, 0 failed (before 2,182 + after 2,601) |
| In-process tests | Stage 7 suite 32/32; migration round-trip 9/9; 19 pre-existing theme suites identical before/after the change (0 regressions) |
| Production deployment | NOT_EXECUTED (operator runbook §17) |

The Stage 7 authoritative translation source was **the Portuguese description the
user sees today** — the manual `post_excerpt` of each published leisure record,
captured read-only from production and validated card-by-card against the rendered
`/lazer/` HTML (289/289). No alternate English source was searched for or used.

---

## 0. How this was verified (validation environment)

No Docker daemon and no MySQL exist in this sandbox, so — like Stages 4.5/5/6 —
the stage was validated in a **reproducible WordPress clone** built from this
repository plus the real production dataset (read-only captures):

- **WordPress 7.0.2** (the project's core version) with the official
  **SQLite database integration** drop-in (no MySQL needed),
  served by PHP's built-in server at `http://127.0.0.1:8765`
  (PHP 8.4, `.local/php/php`), driven by **wp-cli**.
- **Polylang 3.8.9** (the project's version) + the repo theme + the production
  plugin set (data-model, content, admin-ux, event-runtime, leisure-migration),
  languages configured by the repo's own `scripts/stage2-polylang-setup.php`
  (`pt` default + `en`, directory URLs, no browser detection).
- **The real Leisure dataset**: all 289 published records created from the
  read-only production captures (slug, title, content, the resolved PT excerpt,
  dates, `_leisure_*` meta, taxonomy terms, Polylang language `pt`;
  deterministic UUIDs so the identity gates are measurable). Nothing about the
  production site was modified.
- End-to-end sequence: capture inventory → `before` card/inventory/HTTP capture →
  apply the Stage 7 rollout → `after` captures → acceptance matrices → test suites.

Reproduction: `scripts/stage7-validation-clone-setup.sh` prints the exact command
sequence (fetch, install, activate, seed, validate).

Production itself was read-only touched via HTTPS (`https://conexaobr.ie`):
`GET /lazer/` (29 archive pages) and the public REST API
(`wp/v2/leisure`, `wp/v2/conexao_category`, `conexao_county`,
`conexao_leisure_attribute`, `conexao_tag`), captured 2026-09-24.

Note: production has **not yet received the Stage 4.3 English layer** — `/en/lazer/`
there currently 301s to `/lazer/` (measured). The English behaviours below are
therefore verified on the validation clone, which runs the same theme/plugins and
the same data the production rollout will use.

---

## 1. Phase 0 — inventory of the current Portuguese descriptions (measured)

`scripts/stage7-leisure-inventory.php` (pure PHP, no WordPress) builds
`stage7-work/leisure-description-inventory.json` + `.md` from the captures:

| Metric | Value |
|---|---|
| Published leisure records (REST `wp/v2/leisure`) | 289 |
| Unique slugs | 289 |
| Rendered archive cards (`/lazer/`, all pages) | 289 |
| Archive pages | 29 (10 cards per page, 9 on the last) |
| Records with a non-empty manual `post_excerpt` | 289 |
| Records with an empty description | **0** |
| Excerpts longer than the 18-word card trim (end in `...`) | 82 |
| Excerpts that fit within 18 words | 207 |
| Pipeline replication matches vs the captured card HTML | **289 / 289** (0 mismatches) |
| Cards without a REST record / records without a card / duplicate cards | 0 / 0 / 0 |
| Card order = publication date DESC | yes |
| REST `wptexturize` artifacts resolved to the stored DB text | 15 |

For every record the inventory stores: ID, title, slug, permalink, status, date,
the **raw PT description source**, the exact rendered card excerpt
(`.leisure-card-excerpt`), truncation state, featured media, taxonomy terms,
non-empty `_leisure_*` meta, language (`pt`) and EN relationship (none).

## 2. Phase 1 — the `.leisure-card-excerpt` trace (measured, not assumed)

```
archive query (WP default order: date DESC)
  → template-parts/leisure-card.php  (line 154, the only renderer of the class)
    → esc_html( wp_trim_words( <description source>, 18, '...' ) )
      → description source: get_the_excerpt()
        → manual post_excerpt when set (ALL 289 records — measured)
        → otherwise a content-derived excerpt (never happens on this dataset)
```

- **Current PT source field:** `post_excerpt` (manual excerpt; 289/289 non-empty).
- **Current PT transformation:** `wp_trim_words(…, 18, '...')` **inside the
  template**, then `esc_html()` (plain text; no HTML survives — the sources are
  plain text).
- **Current EN behaviour (before Stage 7):** none — the same function returned
  the Portuguese excerpt on `/en/lazer/`, which is the defect the user reported.
- **Required EN transformation:** identical pipeline, applied to an authored
  English description — same 18-word trim, same `'...'` suffix, same escaping.
- **Source of truth:** the same value the PT card uses; the inventory proves the
  replication matches the live rendered HTML for every record (289/289).

No truncation happens outside the template (no `excerpt_length` filter, no CSS
truncation, no custom helper) — verified by reading the renderer and by the
289/289 byte-level replication match against the captured HTML.

## 3. Phase 2 — the translations (human-authored English)

`stage7-work/leisure-description-translations.json` — 289 entries, each
`{seq, id, slug, title, pt_excerpt, en_excerpt}`, assembled and validated by
`scripts/stage7-leisure-translations-build.php` (hard gates: every inventory slug
has exactly one authored description; no extra slugs; no empty value; no HTML in
the EN value; EN ≠ PT; length-ratio sanity check).

| Metric | Value |
|---|---|
| Entries authored | 289 |
| Validation errors | 0 |
| Length-ratio flags | 0 |
| PT words (total / mean / median / min / max) | 4,907 / 17.0 / 16 / 9 / 30 |
| EN words (total / mean / median / min / max) | 4,907 / 17.0 / 17 / 9 / 29 |
| PT excerpts > 18 words | 82 |
| EN descriptions > 18 words | 88 |

Quality rules applied per entry: natural English for a visitor to Ireland;
facts, dates, names, places, measurements, official names and proper nouns
preserved (counties, towns, Irish place names, `Ninth Earl of Ormond`,
`49,400 Irish soldiers`, `1798`, `121 metres`, `UNESCO World Heritage Site`, …);
URLs and identifiers never invented or translated (none occur in the sources);
no fact added or removed; English kept proportionate to the Portuguese so the
18-word card trim behaves equivalently.

Example (production record #21197 `dwyer-mcallister-cottage`):

- PT (authoritative source): *Casa rural restaurada aos pés da montanha Keadeen,
  palco de um episódio da Rebelião de 1798 e hoje pequeno museu de época.*
- EN (authored): *Restored farmhouse at the foot of Keadeen mountain, scene of an
  episode of the 1798 Rebellion and today a small period museum.*

## 4. Phase 3 — where the English description lives

**`_leisure_excerpt_en` post meta on the existing Portuguese record.** Rationale
(measured against the alternatives, not assumed):

- **The existing multilingual mechanism for Leisure EN *records* is Polylang
  linked translations** (`pll_get_post_types` translates `leisure`) — but this
  stage's scope explicitly excludes EN Leisure records/duplication, so creating
  linked EN leisure posts would contradict the task.
- **The existing B2 architecture stores no per-record EN content** (B2 renders
  the PT record; the only language-scoped stores are Polylang relationships and
  language-suffixed caches), so there was no approved EN-content store to reuse
  for a *description*.
- Therefore the safest architecture within the constraints is a language-suffixed
  meta field **on the same record**: persistent (post meta), language-aware
  (read only when the request language is EN), auditable (manifest lists every
  value + the PT source it was authored against), reversible (`remove` deletes
  only this meta; B2 re-engages), and compatible with the current records
  (no schema change, no new posts, identity untouched).
- **Portability reuses the approved leisure pipeline**: the
  `conexao-leisure-migration` exporter and importer now carry
  `_leisure_excerpt_en` (plain-text sanitization), so a leisure export/import ZIP
  round-trips the English descriptions with the dataset —
  verified by `tests/test-excerpt-en-roundtrip.php` (9/9).
- The Portuguese source field is never modified (measured: PT sources changed 0).


---

## 5. Implementation — files changed / created

**Theme (the rendering layer; language logic only in `inc/polylang.php`):**

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/polylang.php` | New `conexao_leisure_card_excerpt( $leisure_id )`: returns the authored `_leisure_excerpt_en` value when the request language is EN and the value exists; otherwise the exact existing `get_the_excerpt()` pipeline. No other theme behaviour touched. |
| `wp-content/themes/conexao-br-irlanda/template-parts/leisure-card.php` | Line 154 renders through the helper; the `wp_trim_words( …, 18, '...' ) + esc_html()` pipeline is unchanged and shared by both languages. |
| `wp-content/themes/conexao-br-irlanda/tests/test-leisure-card-excerpt-language.php` | New Stage 7 acceptance test (32 checks). |

**Rollout tooling (new plugin, follows the Stage 4.5/5/6 pattern — activate for the rollout, then remove):**

| File | Purpose |
|---|---|
| `wp-content/plugins/conexao-leisure-translation/conexao-leisure-translation.php` | Plugin + admin screen (Tools → *EN Leisure Descriptions*), nonce-gated preview/apply/remove |
| `.../includes/apply.php` | Engine: slug + title matching, PT-drift refusal, apply/preview/remove, PT + UUID invariant accounting |
| `.../includes/audit.php` | Completeness audit (gate: published PT leisure records missing EN = 0) |
| `.../data/stage7-leisure-descriptions.json` | The 289 authored entries (slug, title, pt_excerpt, en_excerpt) |
| `scripts/run-leisure-translation.php` | WP-CLI runner (preview / apply / remove / audit / json) |

**Migration compatibility:** `conexao-leisure-migration` exporter + importer
carry `_leisure_excerpt_en` (plain-text sanitization);
`tests/test-excerpt-en-roundtrip.php` pins the contract (9 checks).

**Stage tooling (analysis / verification, no runtime role):**
`scripts/stage7-leisure-inventory.php`, `scripts/stage7-leisure-translations-build.php`,
`scripts/stage7-clone-seed-leisure.php`, `scripts/stage7-clone-inventory.php`,
`scripts/stage7-leisure-http-verify.py`, `scripts/stage7-validation-clone-setup.sh`.

**Docs updated in the same change:** `AGENTS.md` (plugin table),
`docs/plugins/README.md`, new `docs/plugins/conexao-leisure-translation.md`,
`docs/plugins/conexao-leisure-migration.md`,
`docs/themes/conexao-br-irlanda.md`, `docs/routing.md` (§ English rollout state —
Stage 7), `scripts/build-plugins-zip.sh` (packages the new plugin; also adds the
Stage 6 `conexao-job-translation` entry that was missing from the build list).

**No file outside this set was modified.** `inc/seo.php`, the archive query, the
routing rules, the theme CSS/JS, Blog/Jobs/Events/Sponsors/Courses code and
Polylang configuration are untouched.

## 6. Phase 4/5 — B2 preserved, no duplicates, identities untouched

- The B2 architecture is fully intact: `/en/lazer/` still renders the PT records
  when no EN description exists (proved by the in-process test "EN request, no EN
  description → approved B2 fallback" and by the record-level fallback contract).
  No B2 code path was changed, and B2 for Events/Sponsors/Courses/Jobs is
  untouched.
- No leisure record was created, duplicated, merged or deleted. Measured in the
  clone before/after: 289 records → 289 records; new/missing records = 0.
- `_leisure_uuid` / `_leisure_export_uuid`: all 289 records carry non-empty values;
  **0 changed** across the rollout (the engine reads them for the audit trail and
  never writes them; it also re-checks them after every write). The same
  invariant holds for the migration round-trip test.

## 7. Phase 6 — every eligible record translated (exact metrics)

| Metric | Value |
|---|---|
| Leisure records inspected | 289 |
| Records with a PT description | 289 |
| EN descriptions authored | 289 |
| EN descriptions rendered on `/en/lazer/` | 289 |
| Records with an empty description | 0 |
| Records using the approved fallback (no EN description) | 0 (mechanism preserved; exercised by test with a temporary record) |
| Records skipped | 0 |
| Records refused (PT drift) | 0 |
| Errors | 0 |
| Duplicate records created | 0 |

These are measured values from the clone run (`http-after.json`,
`clone-inventory-after.json`, the audit output), not estimates. All 289 eligible
records were translated — no sampling.


## 8. Phase 7 — markup preservation

All 289 Portuguese sources are **plain text** (verified: no HTML tags in any
`post_excerpt`), so the EN values are plain text too — no markup was dropped,
altered or invented. The authored values are validated to contain no HTML
(build gate), and the card's single `esc_html()` layer is applied identically in
both languages. External URLs do not occur inside any source description, so none
had to be preserved; the card's own links (external destinations, map links,
CTAs) are compared card-by-card between the PT and EN archives and are identical.

## 9. Phase 8 — EN card acceptance (`/en/lazer/`)

The `after` HTTP matrix (`stage7-work/http-after.md`, 2,601 checks, 0 failed)
verifies, for **all 289 cards on all 29 pages**:

- HTTP 200, `<html lang="en-US">`, self-canonical, `hreflang` pair intact;
- every `.leisure-card-excerpt` equals the authored English translation trimmed
  to 18 words (the same pipeline as PT) — checked per card, by slug;
- **no card renders the Portuguese description** (per-card inequality check
  against the PT archive) — the specific regression this stage must prevent;
- same card count, same card order, same card links (image/title/CTA hrefs per
  card), same image state, same card classes, same CTA count as `/lazer/`;
- `?county=` / `?categoria=` filter views and `/page/N/` views still render.

## 10. Phase 9 — PT card acceptance (`/lazer/`)

- 289/289 PT card excerpts equal the **production rendering** for the same record
  (captured from `https://conexaobr.ie/lazer/`) — the pre-existing text is intact.
- 289/289 PT cards are **byte-identical between the before and after captures**
  (ids, excerpts, links, images, classes), and the whole-set hash is unchanged
  (`stage7-work/card-hashes.md`):

  | Capture | PT card-set SHA-256 |
  |---|---|
  | before | `4300a7ca7d2b0e708b69feec2db874f61e5899bc5568b94217af11598acb40ee` |
  | after | `4300a7ca7d2b0e708b69feec2db874f61e5899bc5568b94217af11598acb40ee` |

- Portuguese description changes = **0** (also enforced by the engine, which
  compares `post_excerpt` / `post_title` / `post_content` before/after every
  write; measured 0).

## 11. Phase 10/11 — language selection + cache

- **Selection uses the project's existing helpers**: the helper reads the current
  request language through `conexao_current_language_slug()` (theme
  `inc/polylang.php`, which already routes the B2 shell case), never a URL string
  test. PT requests never read the EN meta (tested); EN requests read it only when
  a value exists, otherwise they take the identical PT path.
- **Cache audit (Phase 11):** the Leisure archive has no transient cache (cards
  are rendered per request by the main query); the only related caches are the
  shared filter-term object caches and the B2 replaced-IDs list, both already
  language-scoped (`conexao_lang_cache_key()` / per-language keys) and untouched by
  this stage. PT and EN are separate URLs and separate queries, so a warm PT page
  cannot serve the EN archive and vice versa. No caching system was added or
  changed.

## 12. Phase 12/13 — SEO / routing / search / detail regression

- Routing is unchanged: `/lazer/` → PT archive, `/en/lazer/` → EN archive (200 in
  the clone; on production `/en/lazer/` 301s to `/lazer/` until the Stage 4.3 EN
  layer ships — a pre-existing state, unchanged by this stage).
- The HTTP matrix compares the SEO head of every archive page before vs after:
  `html lang`, `<title>`, canonical, `hreflang` set, robots, `og:locale` are
  **identical** (and the pair resolves to `/lazer/` ↔ `/en/lazer/`). `inc/seo.php`
  was not modified; no new SEO producer exists.
- Leisure detail URLs, card links, filters, language switching and search were not
  redesigned; the regression matrix re-fetches the archive filter views, a leisure
  single (`/lazer/{slug}/`), the EN B2 single (`/en/lazer/{slug}/`), `/`,
  `/en/`, `/blog/`, `/en/blog/`, `/guias/`, `/en/guias/`, `/empregos/`,
  `/en/jobs/`, `/en/empregos/` and `/sitemap.xml`, and their status/redirect
  behaviour is identical before and after.


## 13. Phase 14 — automated quality check

- **`tests/test-leisure-card-excerpt-language.php` (new, 32 checks, all pass)** —
  renders the *actual* `template-parts/leisure-card.php` in each language context:
  PT output byte-identical to the pre-existing pipeline; EN output = the authored
  translation trimmed to 18 words; EN without a translation = the PT description
  (B2); EN never renders the PT description when a translation exists; PT never
  reads the EN meta. Plus engine invariants (preview writes nothing; PT drift is
  refused; re-apply is idempotent; full remove → apply rollback restores all 289;
  UUIDs and PT fields untouched) and the dataset-level contract (0 wrong
  descriptions across the 289 real records; the meta exists only on leisure posts).
  The suite fails if the EN archive ever renders the PT description again — it
  reports a failure when run against the pre-Stage-7 theme (verified).
- **`tests/test-excerpt-en-roundtrip.php` (new, 9 checks, all pass)** — the
  migration export/import path carries, sanitizes and deletes the new meta.

## 14. Phase 15 — before/after rendered evidence

`stage7-work/before-after-excerpts.md` records representative cards with their
real IDs/titles:

| Card | `/lazer/` before = after | `/en/lazer/` before | `/en/lazer/` after |
|---|---|---|---|
| Dwyer McAllister Cottage (#21197 prod) | Casa rural restaurada aos pés da montanha Keadeen, palco de um episódio da Rebelião de 1798 e hoje… | (the same Portuguese text) | Restored farmhouse at the foot of Keadeen mountain, scene of an episode of the 1798 Rebellion and today… |
| National Botanic Garden – Kilmacurragh (#21196) | Jardim botânico no condado de Wicklow, parte dos Jardins Botânicos Nacionais, famoso pelos rododendros e pelas árvores raras… | (the same Portuguese text) | Botanic garden in County Wicklow, part of the National Botanic Gardens, famous for its rhododendrons and rare trees… |
| Kilmainham Gaol (#10148) | Prisão histórica que desempenhou papel central na luta pela independência irlandesa. Museu imperdível. | (the same Portuguese text) | A historic prison that played a central role in the struggle for Irish independence. An unmissable museum. |

`stage7-work/card-hashes.md` adds the whole-set hash evidence:
before, the EN card set hashed **identically to the PT set** (the defect);
after, the EN set hashes differently while the PT set hash is unchanged.

**Verification limitation (explicit):** no browser tooling exists in this
environment, so the evidence is HTML-level (the actual bytes the server renders,
parsed and compared card-by-card) — not a visual browser check. The card layout
markup itself is byte-identical between PT and EN (same template, same classes,
same links), so no visual divergence is introduced by the change.

## 15. Phase 16 — Blog / Jobs / Events regression (measured, 0 regressions)

19 pre-existing theme suites were run twice in the same clone — once with the
Stage 7 files, once with the pre-Stage-7 theme files restored — and the results are
**identical in every suite** (`stage7-work/regression-suite-comparison.txt`):

| Suite | Stage 7 | Pre-Stage-7 |
|---|---|---|
| test-leisure-multiselect-filters | 63 / 0 | 63 / 0 |
| test-leisure-attribute-normalization | 22 / 0 | 22 / 0 |
| test-leisure-card-map-action | 44 / 0 | 44 / 0 |
| test-leisure-related-events | 19 / 0 | 19 / 0 |
| test-i18n-foundation | 28 / 1 | 28 / 1 |
| test-polylang-foundation | 53 / 0 | 53 / 0 |
| test-stage32-bilingual | 23 / 19 | 23 / 19 |
| test-stage33-bilingual | 51 / 26 | 51 / 26 |
| test-event-location-filters | 39 / 0 | 39 / 0 |
| test-guide-breadcrumb-filter | 36 / 0 | 36 / 0 |
| test-sponsor-archive-ordering | 15 / 0 | 15 / 0 |
| test-nav-language-context | 14 / 1 | 14 / 1 |
| test-nav-language-context-logic | 49 / 0 | 49 / 0 |
| test-nav-menu-regression | 22 / 9 | 22 / 9 |
| test-stage7-hero-sponsors | 8 / 2 | 8 / 2 |
| test-recurrence-i18n | 12 / 0 | 12 / 0 |
| test-blog-en-translation | 18 / 9 | 18 / 9 |
| test-job-en-translation | 19 / 9 | 19 / 9 |
| test-stage45-pages | 112 / 11 | 112 / 11 |
| **test-leisure-card-excerpt-language (new)** | **32 / 0** | **0 / 1** (fails: the helper does not exist pre-Stage-7) |

Plugin suites (event runtime): `test-event-query` 41/0,
`test-event-language-gate` 16/0, `test-event-recurrence` 88/2 (identical with the
pre-Stage-7 theme — environmental), `test-plugin-separation --no-tooling` 30/0.

The non-zero failures are **pre-existing and environmental** for this clone (the
Stage 4.5 EN pages, the Stage 5/6 Blog/Job rollout content and the Stage 3.2 pilot
dataset are not installed in it; one locale/date artifact; nav-menu seeding), and
their counts are unchanged by Stage 7 — the Blog and Jobs code paths are not
touched at all. `/en/`, `/en/blog/`, `/en/jobs/` and `/en/lazer/` were also
re-fetched in the HTTP matrix and their status/redirect behaviour is identical
before/after.


## 16. Scope control — what this stage did NOT do

Confirmed by the file list in §5 and by the regression matrix:

- Leisure titles, detail pages and taxonomies: **not translated**.
- No EN Leisure CPT records created; no leisure record duplicated, merged or
  deleted; no UUID or export-UUID change; no source-identity change.
- Events, Guides, Jobs, Blog, Sponsors, Courses: **not modified**.
- Polylang configuration, `inc/seo.php`, routing rules, the archive query, the
  theme CSS/JS, REST contracts and the Flutter/mobile application: **not touched**
  (the mobile app was never accessed, built, tested or inspected).
- No archive redesign; no page-specific CSS.

## 17. Rollout runbook (production — NOT_EXECUTED here)

Production write access is unavailable in this environment (no wp-admin session,
no WP-CLI on WordPress.com, no REST path for private post meta). The stage
therefore stops after validation and delivers the package. Prerequisites: the
Stage 4.3 English layer (Polylang 3.8.9 + theme + plugins + settings) deployed —
today production still redirects `/en/…`, so the EN layer must land first.

1. **Back up** (UpdraftPlus DB + uploads).
2. **Deploy the theme** (`dist/conexao-br-irlanda.zip`). Safe before the data:
   with no `_leisure_excerpt_en` meta the cards behave exactly as today
   (proven by the `before` matrix).
3. **Deploy + activate** `conexao-leisure-translation` (`dist/conexao-leisure-translation.zip`).
4. **Dry run**: Tools → *EN Leisure Descriptions* → **Preview (dry run)**.
   Expect `entries 289`, `would-apply 289`, `refused 0`, `errors 0`.
5. **Apply**: expect `applied 289`, `refused 0`, `errors 0`, `PT sources changed 0`,
   `UUID fields changed 0`, audit gate **PASS**.
6. **Verify (production)**: `/en/lazer/` → 200, `lang="en-US"`, every card shows
   English text; `/lazer/` → 200, the Portuguese text is unchanged (compare a few
   cards with `stage7-work/http-before.json`). The verification script is reusable
   against the live site — `python3 scripts/stage7-leisure-http-verify.py
   --base https://conexaobr.ie --label after` (run it after refreshing the clone
   inventory from production, or use the card-level checks alone).
7. **Deactivate** the plugin (no frontend role; the meta stays).
8. **Rollback**: *Remove (rollback)* in the plugin screen (deletes only
   `_leisure_excerpt_en`; the B2 fallback re-engages immediately), or restore the
   DB backup.

## 18. Limitations

- **Production was not modified or verified live** (no write path in this
  environment). Every execution happened in the validation clone; the production
  rollout is the runbook above. Production `/lazer/` was read-only captured and is
  the PT reference used throughout.
- **No browser/visual verification** (no browser tooling): all evidence is
  HTML-level (server-rendered bytes), which is what the acceptance criteria ask
  for; layout claims are structural (identical markup/classes/links between PT and
  EN cards).
- Pre-existing local failures in unrelated suites (missing EN pages/rollout data in
  this clone, a locale/date artifact, nav-menu seeding) are unchanged by this stage
  (§15).
- The clone seeds records without featured media (no image binaries), so cards
  render the neutral "Image pending" state there; the PT and EN renders are
  compared in that same state, and the `<img>` markup path is untouched by this
  stage (only the excerpt text changed).


## 19. Required final metrics

| Metric | Value |
|---|---|
| Leisure records inspected | 289 |
| Records with PT descriptions | 289 |
| EN descriptions authored | 289 |
| EN descriptions rendered | 289 (all 29 pages of `/en/lazer/`, verified per card) |
| Records with empty descriptions | 0 |
| Records using approved fallback | 0 (mechanism preserved and tested) |
| Portuguese descriptions changed | 0 (byte-identical card sets before/after; engine invariant 0) |
| English cards verified | 289 (authored translation, 18-word pipeline, no PT leakage) |
| Portuguese cards verified | 289 (identical to the production rendering) |
| Internal links changed | 0 (per-card href sets identical PT vs EN and before vs after) |
| UUIDs changed | 0 (`_leisure_uuid` + `_leisure_export_uuid`, 289 records) |
| Duplicate records created | 0 (289 → 289) |
| HTTP checks passed | 4,783 (before 2,182 + after 2,601) |
| HTTP checks failed | 0 |
| Regression tests passed | 19 pre-existing suites identical pre/post (0 new failures) + Stage 7 suite 32/32 + migration round-trip 9/9 + event-runtime suites |
| Production deployment | NOT_EXECUTED (operator runbook §17) |
| Final classification | **ENGLISH LEISURE CARD DESCRIPTIONS — PASS WITH LIMITATION** |

## 20. Acceptance criteria — checklist

1. Every eligible public Leisure card with a PT description has an EN translation — **yes (289/289)**.
2. EN `/lazer/` renders the translated description — **yes (per-card verified)**.
3. PT `/lazer/` retains the exact existing description — **yes (hash-identical)**.
4. No duplicate Leisure records created — **yes (289 → 289)**.
5. `_leisure_uuid` unchanged — **yes (0 changes)**.
6. `_leisure_export_uuid` unchanged — **yes (0 changes)**.
7. Card count/order/images/links unchanged — **yes (compared per card)**.
8. B2 behaviour intact for records without an EN description — **yes (tested)**.
9. Cache separation correct — **yes (no archive cache; language-scoped existing caches untouched)**.
10. SEO/routing unchanged — **yes (head compared before/after)**.
11. No Blog/Jobs regression — **yes (identical suite results; untouched code paths)**.
12. The actual rendered `.leisure-card-excerpt` content is verified — **yes (HTML-level, all records)**.

## 21. Final classification

**ENGLISH LEISURE CARD DESCRIPTIONS — PASS WITH LIMITATION**

The implementation and validation layer is complete: all 289 eligible Portuguese
card descriptions were translated into natural English, `/en/lazer/` renders them
inside `.leisure-card-excerpt` (verified card-by-card on the real dataset), and
`/lazer/` is byte-identical to the production text. The limitation is the
operational one shared with Stages 4.5/5/6: production deployment remains an
operator step because no production write path exists in this environment (and
production must first receive the Stage 4.3 English layer).

---

## Artefacts

| Artefact | Content |
|---|---|
| `stage7-work/leisure-description-inventory.json` / `.md` | Phase 0/1 inventory — all 289 records, sources, rendered card excerpts, pipeline validation |
| `stage7-work/leisure-description-translations.json` | The 289 authored entries (slug, title, pt_excerpt, en_excerpt) |
| `stage7-work/http-before.json` / `.md` | 2,182-check baseline (EN = PT excerpts — the defect) |
| `stage7-work/http-after.json` / `.md` | 2,601-check acceptance (EN = authored translations, PT unchanged) |
| `stage7-work/clone-inventory-before.json` / `-after.json` | Record-level identity/PT/EN state (uuid + PT change gates) |
| `stage7-work/card-hashes.md` | Before/after SHA-256 of the PT and EN card sets |
| `stage7-work/before-after-excerpts.md` | Representative rendered before/after card excerpts |
| `stage7-work/regression-suite-comparison.txt` | Every test suite, with vs without the Stage 7 files |
| `stage7-work/source/` | Read-only production captures (REST pages, all 29 archive HTML pages, taxonomy term sets) |

