# CONEXAO_BR_ENGLISH_STAGE_3_3_REPORT.md

Stage 3.3 of the approved English-support architecture
(`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`, audit
`CONEXAO_BR_ENGLISH_SUPPORT_AUDIT.md`, `CONEXAO_BR_ENGLISH_STAGE_1_REPORT.md`,
`CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md`,
`CONEXAO_BR_ENGLISH_STAGE_3_1_REPORT.md`,
`CONEXAO_BR_ENGLISH_STAGE_3_2_REPORT.md`): close the English-facing UX/content
gaps that only became visible once real English content exists — the
Portuguese destinations left in the English homepage chrome, the header/footer
navigation audit, English taxonomy presentation, and a full re-verification of
identity, search, archives, SEO, sitemap, cache and redirect behaviour against
the Stage 3.2 pilot dataset.

Everything is **local/staging only**. No production change of any kind: no
upload to WordPress.com, no production settings, no deployment (the
production-readiness review in §19 is written, not executed). Work is committed
to the WIP branch `cline/bddevw8x`, never to the default branch.

---

## 1. Status

**ENGLISH STAGE 3.3 — PASS WITH LIMITATION**

Every Phase 13 hard gate passes (§27). The Stage 3.2 blocker named in its own
§26 — English homepage quick-access/section/footer links pointing at Portuguese
URLs — is **resolved at the root cause** (§3, §4): theme chrome now resolves
canonical Portuguese paths through a single language-aware helper, and the
English homepage reaches `/en/eventos/`, `/en/lazer/`, `/en/cursos/`,
`/en/apoiadores/`, `/en/jobs/`, `/en/privacy-policy/`, `/en/terms-of-use/`,
`/en/cookie-policy/` and `/en/guias/?categoria=documents|finances` while the
Portuguese homepage output stays byte-identical.

| Area | Result | Evidence |
|---|---|---|
| English homepage/nav destinations | 0 Portuguese leaks where a real EN destination exists | §3, §4, §24 |
| English taxonomy presentation | 12/12 linked EN terms, EN filters resolve EN slugs, county/town shared, no suffixed duplicates | §6 |
| Translation-state inventory | 8 content types, per-type states, B2 overlay | §7 |
| Pilot quality (15 EN records) | 200 + self-canonical, EN terms, shared media, master links; 3 inherited metadata gaps | §8 |
| Event identity / status / export language | re-verified, no duplicate identity | §9, §10 |
| Lazer identity | re-verified, no duplicate record | §11 |
| Search / archives | EN membership correct, no duplicates, no hidden events | §12, §13 |
| SEO / hreflang / sitemap | self-canonical pairs, B2 canonical → PT, B2 absent from sitemap, no duplicate URLs | §14–§16 |
| Cache isolation | PT/EN transients separate; trashing/restoring a translation invalidates and re-fills correctly | §17 |
| Redirects | legacy 301 precedence intact, `/en/home/` → `/en/`, B1 302 / B2 200 | §18 |

Limitations (documented, no hard gate violated, with concrete next steps):

1. **No Apache in this environment** → the `.htaccess` redirect layer is
   verified as *unchanged* (`git diff` shows no edit) but its execution is
   **NOT TESTABLE** locally (§18, §25). Redirect evidence is WordPress-level
   (`template_redirect`, `redirect_canonical`).
2. **Browser/device validation is NOT TESTABLE** — no browser tooling exists in
   this sandbox (§20). No visual claim is made anywhere in this report.
3. **No English menu is assigned to the `primary` location** in the local
   dataset, so the EN header falls back to WordPress' page-list menu (Polylang
   filters it to the seven English pages: language-consistent, but it does not
   expose the directory archives the PT menu carries). This is *data*
   (`PLL()->options['nav_menus']` empty, `nav_menu_locations.primary = 69`), it
   needs an editorial/menu decision in production, and it is carried into
   §19/§25/§26 rather than patched with a navigation hack.
4. **Two hreflang emitters coexist** (the theme's SEO owner + Polylang's own
   `wp_head` rel-alternate set). Pre-existing since Stage 2, not introduced
   here; the theme's set is the authoritative one and is correct for every URL
   tested (§14, §15, §25).
5. Three EN records have **no meta description** because their Portuguese
   source has none either — content, not code (§8).
6. `test-town-sanitization.php` still reports the same **3 dataset-existence
   expectations** a fresh pilot catalogue cannot satisfy (documented by Stage
   3.2 §22) — unchanged, unrelated to Stage 3.3 (§23).

## 2. Baseline (Phase 0)

Recorded **before any Stage 3.3 edit**:

| Item | Value |
|---|---|
| Repository / branch | `/workspace`, branch `i18n` → work branch `cline/bddevw8x` |
| HEAD | `6fce555` ("Stage 3.2: controlled English content rollout") |
| Working tree | clean (`git status --porcelain` empty) |
| Local stack | PHP 8.5.8 (static-php-cli 2.8.6), WordPress 7.1.1, MariaDB 11.4.8, Polylang 3.8.9 (Free) |
| Theme / plugins | `conexao-br-irlanda` active; all 7 custom plugins active |
| DB checkpoint | taken before any change — `/tmp/env/stage33-baseline.sql` (local only, not committed) |
| Polylang languages | `pt` (pt_BR, **default**) + `en` (en_US) |
| Polylang URL config | `force_lang = 1` (directory), `hide_default = true`, `rewrite = true`, `browser = false`, `redirect_lang = false`, `media_support = false` |
| Translated post types | `post, page, wp_block, guide, event, leisure, sponsor, job, course_provider` |
| Translated taxonomies | `category, post_tag, conexao_category, conexao_tag` (county/town deliberately excluded) |
| Front page | `/` 200 (PT `inicio` #4), `/en/` 200 (EN `home` #126, linked pair) |
| Seven real EN static translations | home #126 `/en/`, about-us #127, contact #128, jobs #129, terms-of-use #130, cookie-policy #131, privacy-policy #141 — all 200 |
| B2 allowlist | `irlanda` + 9 county pages (10 slugs, `conexao_b2_page_allowlist()`) |
| Stage 3.2 pilot content | 2 guides, 1 blog post, 1 event translation, 1 source-inherited EN event, 1 leisure, 1 sponsor, 1 course provider, 1 job (15 EN records total) |
| Editorial indicator | `Conexao_Admin_UX_Translation_State` present (`missing` / `current` / `outdated` / `not_applicable`) |
| Event/Lazer identity | `_event_source + _event_source_id` unique, `_event_export_uuid` / `_leisure_export_uuid` shared master↔translation, `_event_status` authoritative |
| Source-language field | `_event_source_language` (pt/en/other; absence ⇒ `unknown` on export) |

Environment note (harness only, not a product change): the local WordPress was
built from `scripts/stage32-*.php` +
`wp-content/plugins/conexao-content/create-pages.php`; the WP core `pt_BR`
language pack was installed (needed by the Stage 1 i18n suite's `date_i18n()`
month-name assertion); the PHP built-in server runs with **opcache disabled**
because stale opcodes compiled from an earlier symlinked theme path change the
`debug_backtrace()` result Polylang uses for its `home_url` whitelist. That
artifact was found, isolated and re-tested (theme files are mirrored into the
docroot as real files before every run) so no finding in this report rests on
it.

## 3. Homepage quick-access audit (Phase 1)

Audit target: `/en/` (English static front page) and every
quick-access/card/section/footer link it renders. Classification used:

- **A** real English destination exists (must point to it)
- **B** approved B2 destination exists (may point to the EN B2 URL)
- **C** destination must remain B1 (approved Portuguese URL stays)
- **D** external URL (unchanged)
- **E** intentionally language-neutral

### Before (Stage 3.2 state, reproduced locally)

| Card / link | Destination rendered on `/en/` | Class | Required |
|---|---|---|---|
| Empregos/Jobs | `/empregos/` | A | `/en/jobs/` |
| Lazer/Leisure | `/lazer/` | A (B2 archive) | `/en/lazer/` |
| Eventos/Events | `/eventos/` | A (B2 archive) | `/en/eventos/` |
| Educação | `/cursos/` | A (B2 archive) | `/en/cursos/` |
| Apoiadores/Supporters | `/apoiadores/` | A (B2 archive) | `/en/apoiadores/` |
| Guias cards (Moradia, Saúde, Documentos, Finanças, Benefícios, Transporte) | `/en/guias/` (filter lost) | A/B | linked EN term when it has EN guides |
| Blog | `/blog/` | C | unchanged |
| Footer Privacidade | `/politica-de-privacidade/` | A | `/en/privacy-policy/` |
| Footer Termos | `/termos-de-uso/` | A | `/en/terms-of-use/` |
| Footer Cookies | `/cookies/` | A | `/en/cookie-policy/` |
| Header CTA "Anuncie Aqui" | `/anuncie/` | C (no translation) | unchanged |
| Job-board shortcuts (jobs.ie, indeed.ie, irishjobs.ie) | external | D | unchanged |
| Theme toggle, language switcher PT item | `#`, `/` | E | unchanged |

### After (Stage 3.3)

| Card / link | Destination rendered on `/en/` |
|---|---|
| Jobs | `/en/jobs/` |
| Leisure | `/en/lazer/` |
| Events | `/en/eventos/` |
| Educação | `/en/cursos/` |
| Supporters | `/en/apoiadores/` |
| Moradia / Saúde / Benefícios / Transporte (no linked EN term with EN guides) | `/en/guias/` |
| Documentos | `/en/guias/?categoria=documents` |
| Finanças | `/en/guias/?categoria=finances` |
| Blog | `/blog/` (approved B1) |
| Footer privacy / terms / cookies | `/en/privacy-policy/`, `/en/terms-of-use/`, `/en/cookie-policy/` |
| Header CTA, externals, switcher | unchanged |

Portuguese `/` was re-checked link-by-link and still emits exactly
`/eventos/`, `/lazer/`, `/cursos/`, `/apoiadores/`, `/empregos/`, `/blog/`,
`/guias/?categoria=moradia|saude|financas|documentos`, the three PT legal pages
and no English destination. Implementation is one helper
(`conexao_lang_url()`), not hard-coded `/en/` URLs, so the Portuguese path
cannot regress (§21).

## 4. Header, footer and navigation audit (Phase 2)

| Surface | Audit result | Verdict |
|---|---|---|
| Desktop header logo | `home_url( '/' )` → Polylang rewrites the **bare** home URL → `/en/` on `/en/` | translated EN destination ✔ |
| Desktop primary nav | No EN menu assigned to `primary` → WordPress page-list fallback, Polylang-filtered to the 7 EN pages; all targets `/en/...` | language-consistent, **incomplete** (no archive entries) — data gap, §25 |
| Mobile drawer nav | Same `wp_nav_menu` + the language switcher; identical result | language-consistent, same gap |
| Mobile search form / header search form | action = current language home (`/en/`) | translated EN destination ✔ |
| Header CTA "Anuncie Aqui" | `/anuncie/` (no EN page; `/en/anuncie/` 302 → PT) | B1 untranslated destination ✔ |
| Footer brand logo / about text | `/en/`, EN string catalogue | translated EN destination ✔ |
| Footer menu (Rodapé) | `post_type` items, Polylang-translated → `/en/about-us/`, `/en/contact/`, `/en/privacy-policy/`, `/en/terms-of-use/`, `/en/cookie-policy/`; `Anuncie`/`Newsletter` stay PT | translated EN + approved B1 ✔ |
| Footer bottom legal links | were `home_url( '/politica-de-privacidade/' )` etc. → **fixed** to the linked EN pages | A → fixed ✔ |
| Language switcher (desktop + mobile) | PT item → `/` (home) or the PT counterpart; EN item is the current page | approved behaviour ✔ |
| Breadcrumbs | guide breadcrumb filter suite unchanged (`36 passed`) | ✔ |
| Archive links / filter form actions | `get_post_type_archive_link()` → `/en/eventos/`, `/en/lazer/`, `/en/cursos/`, `/en/apoiadores/` | translated EN destination ✔ |
| Homepage quick links | see §3 | fixed ✔ |
| Externals (Instagram, WhatsApp, job boards, Wikipedia-style tourism links) | unchanged, still external | intentionally shared ✔ |

Verdict: one genuine EN-language leak was fixed (footer legal links, §3) and one
**completeness** gap is recorded (EN primary menu not assigned). Labels were not
translated for cosmetic completeness: every visible EN label already comes from
the Stage 1 gettext catalogue.

## 5. Internal-link resolution (Phase 3)

The seven EN static pages, the EN blog post, the EN guide/event/leisure/
sponsor/course/job records were inspected at the content level (post content
`href` extraction) **and** at the rendered level:

- **No in-body internal links exist** in any of the 15 EN pilot records
  (the EN copy authored in Stage 3.2 is plain narrative/Gutenberg structure),
  so there was nothing to rewrite inside content and no link rewriter was
  built.
- All internal links on those pages come from theme chrome (header, footer,
  breadcrumbs, language switcher, section CTAs, filter bars). Every one of them
  was classified in §3/§4: chrome now routes canonical Portuguese paths through
  `conexao_lang_url()`; the remaining non-EN destinations are the approved B1
  ones (Blog, Anuncie) and genuine external destinations.
- Two intentional exceptions, documented rather than "fixed":
  1. **B2 pages are not auto-promoted.** `/irlanda/`, `/dublin/` … render
     Portuguese content under the EN URL with canonical → PT; chrome links to
     the canonical Portuguese URL instead of the B2 shell (avoids a redundant
     hop and never invents a URL).
  2. **Language-switcher PT targets** are Portuguese *by design* (they are the
     user's way back to Portuguese) — they appear in the PT-cluster counts and
     are not leakage.
- PT originals were not rewritten: `git diff` touches no content-bearing file,
  and the PT homepage link set is byte-identical (§3).

## 6. Taxonomy presentation completion (Phase 4)

- **12/12 Stage 3.2 EN terms** exist and are linked in both directions
  (`conexao_category`: documentos↔documents, trabalho↔work, financas↔finances,
  festivais↔festivals, musica↔music, cultura↔culture, natureza↔nature,
  cidades↔cities, negocios↔businesses, gastronomia↔gastronomy,
  empregos↔jobs; `category`: comunidade↔community) — asserted individually plus
  a "12 linked terms" aggregate assertion.
- **EN archives display the EN term name** (EN post cards render
  `Documents`, `Work`, `Festivals`, `Music`, `Cities`, `Nature`, `Businesses`,
  `Gastronomy`, `Jobs`, `Community`).
- **EN filters resolve the EN slug**: `/en/guias/?categoria=documents` (2 EN
  guides) and `/en/guias/?categoria=finances` (1 EN guide) are real, populated
  destinations; the homepage cards now link to them.
- **PT filters are unchanged**: `/guias/?categoria=moradia` and
  `/guias/?categoria=documentos` still resolve with Portuguese slugs
  (`conexao_get_guide_category_url()` short-circuits to the previous behaviour
  in the default language).
- **Shared county/town contract re-verified**: every `conexao_county` (26) and
  `conexao_town` (8) term is language-neutral, zero suffixed duplicates, and
  the EN leisure record carries the *same* county/town term ids as its PT
  master. `?county=`/`?cidade=` therefore match both languages identically.
- **B2 PT records keep PT terms**: for all 7 pilot pairs, each PT master's
  `conexao_category` terms are all language `pt`, and each EN record's terms are
  either the linked EN term or the shared master term.
- Unused taxonomy terms were **not** translated for catalogue completeness
  (e.g. `moradia`, `saude`, `transporte`, `beneficios`, `educacao` have no EN
  term because no EN record uses them → the cards fall back to the plain EN
  archive instead of an empty filter).

## 7. Translation-state inventory (Phase 5)

Produced with the editorial system itself
(`Conexao_Admin_UX_Translation_State::get_state()`), read-only, via the new
`scripts/stage33-translation-inventory.php`:

| Content type | total (PT) | missing | current | outdated | not_applicable |
|---|---|---|---|---|---|
| course_provider | 8 | 7 | 1 | 0 | 0 |
| event | 9 | 8 | 1 | 0 | 0 |
| guide | 6 | 4 | 2 | 0 | 0 |
| job | 3 | 2 | 1 | 0 | 0 |
| leisure | 8 | 7 | 1 | 0 | 0 |
| page | 42 | 35 | 7 | 0 | 0 |
| post | 5 | 4 | 1 | 0 | 0 |
| sponsor | 4 | 3 | 1 | 0 | 0 |

Self-standing English records (no PT source): `event`
`irish-dance-workshop-dublin` (#108) — the approved source-inherited record.

**Policy overlay** (`conexao_is_b2_page()`): 10 pages are **B2-accepted** and
*not* translation debt — `irlanda` + `laois`, `dublin`, `cork`, `galway`,
`limerick`, `kildare`, `meath`, `wicklow`, `waterford`.

Documented rollout plan derived from the inventory (no translations were
created):

| Group | Content | Action |
|---|---|---|
| Ready (real EN translation, state `current`) | home, about-us, contact, jobs, privacy-policy, terms-of-use, cookie-policy, 2 guides, 1 blog post, 1 event, 1 leisure, 1 sponsor, 1 course provider, 1 job (15 records) | keep; monitor `outdated` |
| Ready, no PT counterpart | source-inherited EN event | keep as-is (single identity) |
| Intentionally **B2** | `irlanda` + 9 county pages | keep PT content + notice; revisit only if county content is authored in EN |
| Intentionally **B1** (approved, backlog) | 28 page records (category hubs, newsletter, revista, anuncie, search, europa, moradia/saude/…/voluntariado, legacy aliases), 4 guides, 4 posts, 8 events, 7 leisure, 7 course providers, 3 sponsors, 2 jobs | 302 → PT until English authoring is approved per record |
| Not yet approved / out of scope | `recruitment_agency`, `permit_employer` (admin-only, no public URLs), attachments (shared) | excluded by policy |

## 8. Pilot quality review (Phase 6)

All 15 EN records were fetched and inspected (title, body presence, metadata,
featured media, taxonomy, internal links, canonical, hreflang, switcher,
archive membership, search membership, cache):

| Record | URL | Status | Canonical | hreflang | Terms | Media | PT↔EN master |
|---|---|---|---|---|---|---|---|
| page home | `/en/` | 200 | self | en + pt-BR + x-default | – | – | #4 |
| page about-us | `/en/about-us/` | 200 | self | pair | – | – | #6 |
| page contact | `/en/contact/` | 200 | self | pair | – | – | #7 |
| page jobs | `/en/jobs/` | 200 | self | pair | – | – | #15 |
| page terms-of-use | `/en/terms-of-use/` | 200 | self | pair | – | – | #12 |
| page cookie-policy | `/en/cookie-policy/` | 200 | self | pair | – | – | #13 |
| page privacy-policy | `/en/privacy-policy/` | 200 | self | pair | – | – | #11 |
| guide PPS | `/en/guias/how-to-get-a-pps-number/` | 200 | self | pair | Documents, Work | 132 (shared) | #91 |
| guide bank account | `/en/guias/how-to-open-a-bank-account-in-ireland/` | 200 | self | pair | Documents, Finances | – | #92 |
| post festa junina | `/en/community-celebrates-festa-junina-in-dublin/` | 200 | self | pair | Community (`category`) | – | #97 |
| event festa junina | `/en/eventos/festa-junina-dublin-2026-en/` | 200 | self | pair | Festivals, Music | 132 (shared) | #101 |
| event (source-inherited) | `/en/eventos/irish-dance-workshop-dublin/` | 200 | self | **en only** (approved) | Culture | – | – |
| leisure phoenix park | `/en/lazer/phoenix-park-en/` | 200 | self | pair | Cities, Nature | – | #111 |
| sponsor brasil market | `/en/apoiadores/brasil-market-dublin-en/` | 200 | self | pair | Businesses, Gastronomy | – | #119 |
| course fetch | `/en/cursos/fetch-courses-en/` | 200 | self | pair | `_provider_category` label | – | #80 |
| job kitchen assistant | `/en/empregos/kitchen-assistant-dublin/` | 200 | self | pair | Gastronomy, Jobs | – | #124 |

Concrete issues found (all fixed or documented — no broad translation):

1. **Missing meta description on 3 EN records** (#108 source-inherited event,
   #138 sponsor, #139 course provider). Cause verified at the data level: the
   Portuguese sources (`#119`, `#80`) have an empty
   `conexao_meta_description` too, and the imported event has none. This is a
   content gap inherited from the PT dataset, not a Stage 3.3 defect; fixing it
   would mean authoring new PT+EN metadata, which this stage must not do.
   → carried into §19/§26 as a content task. Each page still emits a
   title + fallback description, so no page is broken.
2. **No accidental PT-facing CTA.** Every non-EN link detected on the EN pages
   was traced to its origin and is legitimate: the language switcher's PT item
   (PT counterpart or the PT archive when no translation exists), the
   `hreflang` link elements, and the approved B1 destinations (Blog, Anuncie).
   No body-level CTA was found pointing at a Portuguese page that has an
   English counterpart.
3. **No incorrect B1/B2 behaviour** was found: the seven real translations are
   200/self-canonical; `/en/blog/`, `/en/moradia/`, `/en/anuncie/`,
   `/en/europa/` are 302 → PT; `/en/irlanda/`, `/en/dublin/`, `/en/cork/`,
   `/en/galway/` are 200 B2 with canonical → PT and `x-default` only.

## 9. Event language regression (Phase 7)

- `_event_source_language` remains **non-identity metadata**: no dedup,
  scheduling, `_event_status` or UUID code path reads it (importer suite
  `test-language-identity.php` — 27 passed / 0 failed — still passes with the
  pilot translations in place).
- Accepted values are only `pt`, `en`, `other`; absent ⇒ the export writes
  `"unknown"` and never persists `unknown` (asserted by the 57 assertions of
  `test-export-language.php`).
- Dataset distribution (local pilot): 9 × `pt`, 1 × `en`
  (`irish-dance-workshop-dublin`, the source-inherited record), and the manual
  `encontro-de-brasileiros-limerick` deliberately unset.
- Eventbrite `locale` → source language mapping stays explicit
  (`pt_BR` → pt, `en_IE` → en, anything else → other, absent → unclassified).
- English source-inherited events remain **single records** (`/en/eventos/irish-dance-workshop-dublin/`
  200, self-canonical, `hreflang="en"` only) and EN *translations* are never
  exported as additional identity rows (one export row per identity, asserted).
- Import semantics were **not** changed in this stage.

## 10. Event identity verification

| Check | Result |
|---|---|
| `_event_source + _event_source_id` uniqueness | pass (identity test) |
| `_event_export_uuid` uniqueness | exactly one shared master↔translation pair, no other duplicates |
| EN translation is not an import target | `is_import_target()` false, guarded upsert returns `skipped` |
| Exactly two records per identity (master + linked translation) | pass |
| PT master URL unchanged and 200 | `/eventos/festa-junina-dublin-2026/` 200 (self-canonical, pair hreflang) |
| `/en/eventos/festa-junina-dublin-2026/` (PT slug under `/en/`) | 302 → PT canonical, no loop |
| Hidden events stay hidden | expired (`noite-de-mpb-bray`), `rejected` (`evento-rejeitado-spam`), `source_not_found` (`evento-removido-na-fonte`) are absent from `/en/eventos/` and from EN search |
| `_event_status` authoritative | event-runtime gate suite 16 passed / 0 failed; `_event_status = published` + legacy no-status records render |

## 11. Lazer identity verification

| Check | Result |
|---|---|
| `_leisure_export_uuid` identical on master + translation | pass |
| Exactly one record per language carries it | pass |
| `find_by_uuid()` resolves the PT master | pass |
| `update_item()` refuses to write to a translation | pass |
| External classification (`conexao_leisure_external_url()`) unchanged for Fota | pass |
| Internal classification preserved on the translation | pass |
| No duplicate Lazer record created | pass (`test-language-uuid.php` — 14 passed / 0 failed) |
| EN archive shows the EN record; PT master replaced, not duplicated | pass (§17) |

## 12. Search

- EN search renders at `/en/?s=…` with the EN shell (`lang="en-US"`,
  `noindex`) and returns EN-pluralised result templates (Stage 1 i18n strings).
- Membership: only EN records + B2 fallback records appear; PT masters that have
  a published EN translation are excluded via
  `conexao_b2_translation_replaced_pt_ids()` — no duplicate hits.
- Hidden events do not appear in EN search (checked for the expired / rejected /
  `source_not_found` titles).
- PT search (`/?s=…`) is unchanged (noindex, PT results, no EN records
  injected).

## 13. Archives

| Archive | Result |
|---|---|
| `/en/guias/` | 200, 2 EN guides (PT guides excluded) |
| `/en/eventos/` | 200, exactly the **7 public events** (2 EN + 5 PT B2), each identity once; hidden events absent; translated PT master replaced |
| `/en/lazer/` | 200, EN record listed; PT master replaced |
| `/en/cursos/`, `/en/apoiadores/` | 200, EN records listed |
| `/en/empregos/` | 302 → `/empregos/` (page-level B1; the EN landing is `/en/jobs/`) |
| `/guias/`, `/eventos/`, `/lazer/`, `/cursos/`, `/apoiadores/`, `/empregos/`, `/blog/` | 200, PT membership unchanged |

## 14. SEO

- Canonical is correct on every URL tested: real EN records are
  **self-canonical**; B2 pages canonicalise to their Portuguese URL.
- `og:locale` follows the language (`en_US` on EN, `pt_BR` on PT) through the
  Stage 1 `conexao_og_locale()`.
- Search and 404 stay `noindex` in both languages.
- 107 sitemap URLs, no duplicates.
- No page emits a canonical pointing at a redirecting URL.
- Known pre-existing observation: Polylang's own `wp_head` rel-alternate set is
  emitted in addition to the theme's authoritative set (see §15/§25). SEO
  architecture was **not** modified in this stage.

## 15. hreflang

| Case | Expected | Observed |
|---|---|---|
| Real translation pair (`/en/`, `/en/about-us/`, `/en/guias/how-to-get-a-pps-number/`, `/en/eventos/festa-junina-dublin-2026-en/`) | `en` + `pt-BR` + `x-default` | ✔ |
| B2 page (`/en/irlanda/`, `/en/dublin/`) | `x-default` only | ✔ |
| Source-inherited EN event (no PT pair) | self-only `hreflang="en"`, self-canonical | ✔ |
| B1 redirect-only URLs (`/en/moradia/`, `/en/blog/`) | 302, no hreflang emitted | ✔ |
| No alternate points at a redirect | verified | ✔ |
| Polylang's additional rel-alternate block | present (pre-existing) | limitation, §25 |

## 16. Sitemap

- 107 URLs, **0 duplicates**.
- 14 EN URLs, each exactly once — the 7 EN pages, 2 EN guides, 1 EN blog post,
  1 EN event, 1 source-inherited event, 1 EN leisure, 1 EN sponsor, 1 EN course,
  1 EN job.
- **B2 URLs are absent** (`/en/irlanda/`, `/en/dublin/` not present); B1-only EN
  URLs (`/en/blog/`) are absent.
- PT URLs unchanged and present once.
- Local sitemap generator: the theme's own `sitemap.xml` route
  (`inc/seo.php`); **Jetpack is not installed locally**, so the Jetpack
  reconciliation step remains a production plan item (§19) — unchanged from
  Stage 3.2.

## 17. Cache / query regression (Phase 9)

Executed as a dedicated regression (`/tmp/env/cache33.php`, 25 assertions —
**25 passed / 0 failed**), reading the transients straight from the options
table (the authoritative evidence; `get_transient()` caches its first miss in
the CLI process):

| Check | Result |
|---|---|
| PT warm-up writes `conexao_home_latest_pt`, leaves the EN key empty | ✔ |
| EN warm-up writes `conexao_home_latest_en`, leaves the PT value untouched | ✔ |
| PT and EN cached payloads differ | ✔ |
| Warming PT does not overwrite EN | ✔ |
| Event runtime cache is language-scoped (`conexao_event_upcoming_<date>_pt` / `_en`) | ✔ |
| EN events archive lists exactly the 7 public events, each identity once | ✔ |
| Translated PT master is replaced, not duplicated, after warm-up | ✔ |
| Hidden events absent from the EN archive | ✔ |
| Trashing an EN translation invalidates both language caches | ✔ |
| …and the PT record falls back into the EN archive exactly once | ✔ |
| Restoring the translation brings the EN record back, PT master replaced again | ✔ |
| Cache flush/regeneration leaves both languages correct | ✔ |

No new cache namespace was introduced; `conexao_lang_cache_key()` /
`conexao_flush_language_cache()` (Stage 2) remain the only mechanism.

## 18. Redirect behaviour (Phase 10)

| URL | Status | Target |
|---|---|---|
| `/jobs/` | 301 | `/empregos/` |
| `/about-us/` | 301 | `/sobre-nos/` |
| `/contact/` | 301 | `/contato/` |
| `/guides/` | 301 | `/guias/` |
| `/events/` | 301 | `/eventos/` |
| `/courses/` | 301 | `/cursos/` |
| `/sponsors/` | 301 | `/apoiadores/` |
| `/ireland/` | 301 | `/irlanda/` |
| `/about/` | 301 | `/sobre-nos/` |
| `/en/home/` | 301 | `/en/` |
| `/en/blog/`, `/en/moradia/`, `/en/anuncie/`, `/en/europa/` | 302 | PT counterpart (B1) |
| `/en/irlanda/`, `/en/dublin/`, `/en/cork/`, `/en/galway/` | 200 | B2 (no redirect) |
| real EN translations (`/en/about-us/`, `/en/jobs/`, `/en/guias/how-to-get-a-pps-number/`, …) | 200 | never redirected to PT |

- Zero redirect loops (every chain terminates in a 200; each was followed).
- The legacy map still wins over Polylang's language canonical (priority 2 < 4),
  i.e. the Stage 3.2 precedence fix is intact.
- `/en/home/` still consolidates to `/en/`.
- **`.htaccess` execution: NOT TESTABLE** — Apache is unavailable in this
  environment. The file is **unchanged** (`git diff` touches no `.htaccess`
  line); all evidence above is WordPress-level. Production redirect verification
  must therefore be re-run on the deployed stack (§19).

## 19. Production-readiness review (Phase 12 — written, NOT executed)

Updated from the Stage 3.2 deployment plan (§27 there) with the Stage 3.3
changes. **Local settings are not production settings**: every step must be
re-verified on WordPress.com before/after rollout.

| # | Item | Action | Stage 3.3 change |
|---|---|---|---|
| 1 | **Polylang ZIP/version** | Install **Polylang Free 3.8.9** (Free only — no Pro), verify the version on the target, keep the plugin ZIP in `dist/` | unchanged |
| 2 | **Language configuration** | `pt` (pt_BR, default) + `en` (en_US); `force_lang = 1`, `hide_default = true`, `rewrite = true`, `browser = false`, `media_support = false`; translated post types/taxonomies are code-level (`inc/polylang.php`) | unchanged |
| 3 | **English front page** | EN front page = linked translation of the PT front page; `pll_home_url('en') = /en/`; `/en/home/` 301 → `/en/` | unchanged |
| 4 | **Translated content** | 15 pilot EN records (7 pages + 8 content records) — re-run the Stage 3.2 batch on production (production IDs differ); verify canonical/hreflang per URL | unchanged |
| 5 | **Taxonomy relationships** | 12 linked EN terms must exist production-side before the content batch; county/town taxonomies stay untranslated | unchanged |
| 6 | **Source-language event metadata** | `_event_source_language` writes on import; verify accepted values + absence ⇒ `unknown` | unchanged |
| 7 | **Export JSON compatibility** | additive `lang` field; one row per identity; EN translations never exported as identity rows | unchanged |
| 8 | **Editorial-state code** | `conexao-admin-ux` translation-state module active; verify `_translation_outdated` sync | unchanged |
| 9 | **Cache flush** | flush transients/object cache for **both** languages after the batch (`conexao_flush_language_cache()` keys); never flush only PT | **new emphasis** (§17) |
| 10 | **Jetpack sitemap reconciliation** | Jetpack's sitemap must not re-add B2 URLs or duplicates; compare against the theme's 14 EN URLs | unchanged (not testable locally) |
| 11 | **Redirect verification** | run `scripts/stage33-http-verify.py` against production **plus** an Apache-level check of the `.htaccess` map | **new script** |
| 12 | **Canonical/hreflang verification** | re-check self-canonical EN pages, B2 canonical → PT, `x-default`; decide on consolidating the duplicated Polylang rel-alternate set | **new item** (§25.4) |
| 13 | **English navigation** | assign a per-language EN menu to the `primary` location so the header exposes the directory archives; verify the footer menu translation | **new item** (§4/§25.3) |
| 14 | **Metadata completion** | author `conexao_meta_description` for the records that still lack it (PT + EN) | **new item** (§8) |
| 15 | **Rollback** | keep the pre-rollout DB checkpoint; rollback = delete the EN translations + re-run the cache flush; Polylang deactivation restores Stage 1 behaviour by design (every helper is guarded) | unchanged |

Nothing in this list was executed: no upload, no setting change, no plugin
installation on production, and no production URL was contacted during
Stage 3.3.

## 20. Browser / device status

**NOT TESTABLE.** No browser tooling exists in this sandbox (no Chromium/
Firefox binary, no Playwright/Puppeteer module, no headless driver), so no
visual or responsive validation was attempted and **none is claimed**:

| Surface | Status |
|---|---|
| Desktop header | NOT TESTABLE (code-level + HTTP evidence only) |
| Mobile drawer navigation | NOT TESTABLE |
| Homepage quick-access cards | NOT TESTABLE visually; HTTP/markup evidence in §3, §24 |
| B2 notice | NOT TESTABLE visually; markup asserted in the Stage 3.2/3.3 tests |
| Language switcher | NOT TESTABLE visually; URLs asserted (tests + HTTP) |
| Translated pages | NOT TESTABLE visually; language markers asserted in the rendered HTML |
| Archive cards / filter controls | NOT TESTABLE visually; filter suites pass (46/63/15 assertions) |

Responsive CSS was not touched; the only changed markup carries identical
classes and structure (link `href` values only).

## 21. Exact files changed

Modified (7):

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/polylang.php` | Stage 3.3 helpers: `conexao_lang_url()`, `conexao_lang_url_object()`, `conexao_lang_url_archive_post_type()`, `conexao_lang_term()`, `conexao_term_has_language_content()`, `conexao_find_term_across_languages()` |
| `wp-content/themes/conexao-br-irlanda/functions.php` | `conexao_get_guide_category_url()` resolves the current language's linked term (PT behaviour short-circuits unchanged) |
| `wp-content/themes/conexao-br-irlanda/front-page.php` | section/CTA links (`/eventos/`, `/blog/`, `/empregos/`) through `conexao_lang_url()` |
| `wp-content/themes/conexao-br-irlanda/footer.php` | 3 legal links through `conexao_lang_url()` |
| `wp-content/themes/conexao-br-irlanda/template-parts/quick-access-card.php` | card `url` destination through `conexao_lang_url()` |
| `wp-content/themes/conexao-br-irlanda/template-parts/help-shortcut-card.php` | shortcut destination through `conexao_lang_url()` |
| `wp-content/themes/conexao-br-irlanda/template-parts/hero-events.php` | hero events CTA through `conexao_lang_url()` |

Added (3):

| File | Purpose |
|---|---|
| `wp-content/themes/conexao-br-irlanda/tests/test-stage33-bilingual.php` | 93 focused Stage 3.3 assertions (§23) |
| `scripts/stage33-http-verify.py` | 122-assertion HTTP verification matrix (§24) |
| `scripts/stage33-translation-inventory.php` | read-only editorial translation-state inventory (§7) |

Documentation updated in the same change (per AGENTS.md): `docs/routing.md`
(§ "English rollout state (Stage 3.3)"), plus this report.

## 22. Exact files intentionally not changed

- `.htaccess` — redirect map body untouched (verified with `git diff`).
- `wp-content/themes/conexao-br-irlanda/inc/seo.php` — single SEO owner; no
  canonical/hreflang/sitemap/redirect logic changed.
- `wp-content/themes/conexao-br-irlanda/inc/i18n.php` — Stage 1 i18n foundation
  reused as-is; no new translation framework.
- `wp-content/themes/conexao-br-irlanda/header.php` — the header already
  resolves correctly (§4); no markup change was needed.
- `wp-content/themes/conexao-br-irlanda/404.php`, `page.php`, `single*.php`,
  `archive.php`, `search.php`, `searchform.php` — no EN leakage found; the
  `home_url()` fallbacks in `single-sponsor.php` and the filter templates sit
  behind language-aware `get_post_type_archive_link()` lookups and never fire.
- `wp-content/plugins/conexao-event-runtime/**`, `conexao-event-importer/**`,
  `conexao-leisure-migration/**`, `conexao-data-model/**`, `conexao-content/**`
  — identity, status, export-language and migration semantics untouched.
- `wp-content/plugins/conexao-admin-ux/**` — the editorial indicator was reused,
  not modified.
- `scripts/stage32-*.php` migration scripts — no content was migrated.
- `docs/plugins/*.md`, `AGENTS.md` — no plugin behaviour changed.
- Any production configuration, WordPress.com setting or live production URL.

## 23. Tests and exact results (Phase 14)

Commands (local environment; suites are run from the mirrored copy inside the
local WordPress install so their own `wp-load.php` bootstrap resolves):

```bash
php -l <changed/new PHP files>                       # 9 files
git diff --check
php wp-content/themes/conexao-br-irlanda/tests/test-*.php
php wp-content/plugins/*/tests/test-*.php
python3 scripts/stage33-http-verify.py http://127.0.0.1:8899
php /tmp/env/cache33.php
```

| Suite | Result |
|---|---|
| `test-i18n-foundation.php` (Stage 1) | **29 passed, 0 failed** |
| `test-polylang-foundation.php` (Stage 2/3.2 taxonomy policy) | **62 passed, 0 failed** |
| `test-stage32-bilingual.php` (Stage 3.2) | **42 passed, 0 failed** |
| `test-stage33-bilingual.php` (**new**) | **93 passed, 0 failed** |
| `test-recurrence-i18n.php` | **12 passed, 0 failed** |
| `test-guide-breadcrumb-filter.php` | **36 passed, 0 failed** |
| `test-event-location-filters.php` | **46 passed, 0 failed** |
| `test-leisure-multiselect-filters.php` | **63 passed, 0 failed** |
| `test-sponsor-archive-ordering.php` | **15 passed, 0 failed** |
| `test-language-identity.php` (event identity) | **27 passed, 0 failed** |
| `test-export-language.php` (export `lang`) | **57 passed, 0 failed** |
| `test-event-language-gate.php` (`_event_status`) | **16 passed, 0 failed** |
| `test-event-query.php` (status gate, sorting, cache) | **22 checks passed, 0 failed** |
| `test-language-uuid.php` (Lazer UUID) | **14 passed, 0 failed** |
| `test-translation-state.php` (editorial state) | **22 passed, 0 failed** |
| `test-town-sanitization.php` | 30 passed, **3 failed — pre-existing dataset-existence expectations** (Stage 3.2 §22, unchanged) |
| `scripts/stage33-http-verify.py` (**new**) | **122 passed, 0 failed** |
| cache regression (`cache33.php`) | **25 passed, 0 failed** |
| `php -l` all changed/new PHP files | 9/9 clean |
| `git diff --check` | clean |

Totals: **733 assertions passed / 3 failed** across the 16 suites plus the HTTP
and cache regressions; the only failures are the three documented
dataset-existence expectations in `test-town-sanitization.php`
(`Oranmore`, `Cobh`, `Corofin` town terms do not exist in a fresh pilot
catalogue — the suite asserts a production town inventory). No Stage 3.3
assertion fails anywhere.

New Stage 3.3 assertions cover: PT invariance for 18 chrome paths, EN
resolution for 5 archives + 6 pages, B1/B2 preservation, template-level card and
shortcut markup in both languages, footer resolver usage, the 12 pilot EN term
pairs, the shared county/town contract, per-record EN term mapping (7 pairs),
and the language-aware Guides category cards.

## 24. HTTP verification matrix (selected rows)

Full machine-readable matrix: `python3 scripts/stage33-http-verify.py <base-url>`
(prints every row it fetched).

| URL | Status | Location / canonical |
|---|---|---|
| `/` | 200 | self-canonical, `pt-BR`+`en`+`x-default` |
| `/en/` | 200 | self-canonical, pair hreflang |
| `/en/home/` | 301 | `/en/` |
| `/jobs/`, `/about-us/`, `/contact/`, `/guides/`, `/events/`, `/courses/`, `/sponsors/`, `/ireland/`, `/about/` | 301 | PT canonical targets (legacy map) |
| `/en/about-us/`, `/en/contact/`, `/en/jobs/`, `/en/privacy-policy/`, `/en/terms-of-use/`, `/en/cookie-policy/` | 200 | self-canonical |
| `/en/blog/`, `/en/moradia/`, `/en/anuncie/`, `/en/europa/` | 302 | PT counterpart (B1) |
| `/en/irlanda/`, `/en/dublin/`, `/en/cork/`, `/en/galway/` | 200 | canonical → PT, `x-default` only (B2) |
| `/en/guias/`, `/en/eventos/`, `/en/lazer/`, `/en/cursos/`, `/en/apoiadores/` | 200 | EN archives |
| `/en/guias/how-to-get-a-pps-number/` | 200 | self-canonical, pair |
| `/en/eventos/festa-junina-dublin-2026-en/` | 200 | self-canonical, pair |
| `/en/eventos/irish-dance-workshop-dublin/` | 200 | self-canonical, `en` only |
| `/en/lazer/phoenix-park-en/`, `/en/apoiadores/brasil-market-dublin-en/`, `/en/cursos/fetch-courses-en/`, `/en/empregos/kitchen-assistant-dublin/` | 200 | self-canonical, pair |
| `/sitemap.xml` | 200 | 107 URLs, 0 duplicates, no B2/B1-only EN URLs |
| `/?s=…`, `/en/?s=…` | 200 | noindex in both languages |

## 25. Known limitations

1. **`.htaccess` execution is NOT TESTABLE locally** (no Apache). The file is
   unchanged; only WordPress-level redirect behaviour was exercised. Apache
   rewrite verification must run on the deployed stack.
2. **Browser/device validation is NOT TESTABLE** (no browser tooling). No visual
   claim is made; evidence is code-level, markup-level and HTTP-level.
3. **No English menu is assigned to the `primary` location** in this dataset
   (`PLL()->options['nav_menus']` empty; `nav_menu_locations.primary = 69`).
   The EN header therefore shows the Polylang-filtered page-list fallback (the
   7 EN pages) instead of the directory entries the PT menu carries. No
   Portuguese URL leaks, but the EN header is *incomplete*. Assigning menus is
   production data/editorial work (§19 item 13).
4. **Duplicate hreflang emitters.** The theme owns hreflang (correct set:
   `en`+`pt-BR`+`x-default`), and Polylang's own `wp_head` rel-alternate block
   is also emitted. Pre-existing since Stage 2; not a contradiction (same URLs)
   but redundant. Consolidation is deliberately deferred because
   "do not modify SEO architecture" governs this stage.
5. **Three EN records lack a meta description** (source-inherited event #108,
   sponsor #138, course provider #139) because their Portuguese sources have
   none. Content task, not code.
6. **`test-town-sanitization.php` 3 failures** — dataset-existence expectations
   for a production town inventory; identical to Stage 3.2 §22, unrelated to
   this stage.
7. **Local dataset ≠ production dataset.** The local catalogue was rebuilt from
   the repository's own seeders; production IDs, media library items and the
   primary menu differ. Conclusions about *behaviour* transfer; conclusions
   about *specific IDs* do not.
8. **Jetpack is not installed locally**, so its sitemap reconciliation could not
   be exercised (unchanged from Stage 3.2).
9. `scripts/stage32-share-location-taxonomies.php` hits a WordPress 7.1
   strictness fatal in its final cache step
   (`clean_term_cache( array(), array( …taxonomies… ), true )` — core now wants a
   string taxonomy). The taxonomy work itself completed before that call and the
   shared-term contract is now asserted directly by
   `test-stage33-bilingual.php`; the script was **not** modified in this stage
   (it is Stage 3.2 tooling) but the one-line fix is
   `foreach ( $taxonomies as $t ) { clean_term_cache( array(), $t, true ); }`.

## 26. Stage 4 prerequisites

1. **Flutter/REST layer** (explicitly out of scope here): the REST surface must
   expose language-aware URLs and never emit a B2 shell URL as a canonical EN
   record; `conexao_lang_url()` semantics are the reference for link building.
2. **English editorial backlog** per §7: decide which B1 records graduate to
   real translations, in priority order (guides → events → leisure → jobs).
3. **Production menu work**: create and assign per-language menus
   (`primary`, `footer`) so the EN header exposes the directories (§25.3).
4. **hreflang/SEO consolidation decision**: keep or suppress Polylang's own
   rel-alternate block so exactly one hreflang set is emitted (§25.4).
5. **Metadata completion** for the records missing `conexao_meta_description`
   (PT + EN) (§25.5).
6. **Apache-level redirect verification** and the Jetpack sitemap
   reconciliation on the deployed stack (§25.1, §25.8).
7. **Stage 3.2 tooling fix** for the `clean_term_cache()` call (§25.9).
8. Only then: production rollout per §19 — nothing was deployed in Stages
   3.1–3.3.

## 27. Final acceptance matrix (Phase 13 hard gates)

| # | Hard gate | Result | Evidence |
|---|---|---|---|
| 1 | English homepage links point to the wrong language when a real EN destination exists | **PASS** | §3, §24 (all A-class links now EN) |
| 2 | A real EN translation redirects to PT | **PASS** | §24 (all 15 EN URLs 200) |
| 3 | A B2 page loses its B2 behaviour | **PASS** | §8, §24 (`/en/irlanda/`, `/en/dublin/`, `/en/cork/`, `/en/galway/` 200 + canonical → PT) |
| 4 | B1 silently converted into B2 | **PASS** | §24 (`/en/blog/`, `/en/moradia/`, `/en/anuncie/`, `/en/europa/` still 302) |
| 5 | Event identity duplicated | **PASS** | §10 (`test-language-identity.php` 27/0; one UUID pair) |
| 6 | Lazer identity duplicated | **PASS** | §11 (`test-language-uuid.php` 14/0) |
| 7 | Hidden events become public | **PASS** | §10, §13, §17 (expired/rejected/source_not_found absent everywhere) |
| 8 | Shared county/town taxonomy duplicated again | **PASS** | §6, §23 (0 suffixed terms; 0 language-tagged terms) |
| 9 | Canonical/hreflang contradictory | **PASS** | §14, §15, §24 |
| 10 | B2 URLs enter the sitemap | **PASS** | §16 (`/en/irlanda/`, `/en/dublin/` absent) |
| 11 | PT/EN cache leakage | **PASS** | §17 (25/0 cache assertions) |
| 12 | Legacy redirect precedence regresses | **PASS** | §18 (all 9 legacy 301s intact) |
| 13 | Portuguese URLs or content change unintentionally | **PASS** | §3, §5, §23 (PT invariance assertions; no content file changed) |
| 14 | Production touched | **PASS** | no deployment, no production settings, report only; work on `cline/bddevw8x` |

## 28. Final classification

**ENGLISH STAGE 3.3 — PASS WITH LIMITATION**

All 14 hard gates pass and the Stage 3.2 blocker is fixed at the root cause, but
three items are deliberately *not* claimed as complete and are carried forward
with concrete plans: `.htaccess` execution and browser/device validation are
**not testable** in this environment (§20, §25.1–2), and the English **primary
navigation** is complete only as far as the available data allows — it needs an
assigned per-language menu in production (§25.3). The duplicated hreflang
emitter, the three missing meta descriptions and the pre-existing
town-sanitization dataset expectations are documented (§25.4–6) rather than
hidden inside a PASS.
