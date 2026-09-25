# CONEXAO_BR_ENGLISH_STAGE_4_1_REPORT.md

Stage 4.1 of the approved English-support architecture
(`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`, audit
`CONEXAO_BR_ENGLISH_SUPPORT_AUDIT.md`, Stage 1/2/3.1/3.2/3.3 reports):
implement the bilingual REST contract required for the Flutter app **without
changing the established WordPress content architecture** — Portuguese default,
English through `lang=en`, language-aware collections, real EN translations,
B2 fallback records where the architecture permits them, explicit translation
relationships, stable Event/Lazer identity semantics, language-aware cache
separation and full backward compatibility for existing Portuguese consumers.

Everything is **local/staging only**. No production change of any kind: no
upload to WordPress.com, no production settings, no deployment, no Flutter
builds. Work is committed to the WIP branch `cline/hd9yjjc0`, never to the
default branch.

---

## 1. Status

**ENGLISH STAGE 4.1 — PASS WITH LIMITATION**

The WordPress half of the stage is complete and verified: the bilingual REST
contract is implemented inside the existing REST API (one new theme module,
zero new endpoints, zero content-architecture changes), and it is proven by a
dedicated 212-assertion in-process REST suite plus a 156-check wire-level HTTP
verification matrix (59 machine-readable rows), with every pre-existing suite
re-run green. Backward compatibility is proven: requests without `lang` behave
exactly as before.

The limitation is explicit and material: **Phases 9 and 10 (the Flutter API
integration and cache/database migration) could NOT be performed** — the
Flutter repository (`https://github.com/abalzan/conexaobr_app`) is not
accessible with this environment's credentials (HTTP 404 via both `git
ls-remote` and the authenticated GitHub CLI; it is not among the 61 repositories
visible for the `abalzan` account to this token). No Flutter file was read,
changed or tested; every Flutter-specific deliverable in this report is marked
**NOT TESTABLE / NOT PERFORMED** rather than hidden inside the PASS. The
classification follows the stage rule ("do not hide incomplete REST or Flutter
behaviour inside PASS").

| Area | Result | Evidence |
|---|---|---|
| REST contract (request model, metadata, filtering, details, errors) | implemented + verified | §4–§6, §18, §19 |
| Event hard gates (identity, `_event_status`, no mutation) | verified, incl. new detail gate | §7, §19 |
| Lazer hard gates (UUID, replacement, external classification) | verified | §8 |
| Taxonomy / filter contract (translated vs shared) | verified | §9 |
| Search contract | verified | §10 |
| Cache separation | verified (no new cache; lifecycle invalidation) | §11 |
| Backward compatibility (no `lang` = old behaviour) | proven | §20 |
| Flutter integration (Phases 9–10) | **NOT PERFORMED — repository inaccessible** | §3, §12–§17, §25 |
| Production safety | untouched | §23 |
| Flutter integration (Phases 9–10) | **NOT PERFORMED — repository inaccessible** | §3, §12–§17, §25 |
| Production safety | untouched | §23 |

## 2. WordPress baseline (Phase 0)

Recorded **before any Stage 4.1 edit**:

| Item | Value |
|---|---|
| Repository / branch | `/workspace`, branch `i18n` → work branch `cline/hd9yjjc0` |
| HEAD at start | `43146aa` ("Add Polylang navigation menu assignment and regression tests") |
| Working tree at start | clean (`git status --porcelain` empty) |
| Local stack | PHP 8.4.23 (static-php-cli bulk build, opcache disabled), WordPress 7.1.1, MariaDB 11.4.8, Polylang 3.8.9 (Free) |
| Environment | PHP built-in server on 127.0.0.1:8090 with `Host: localhost:8080` (port 8080 is taken by a sandbox service); theme/plugins mirrored into the docroot as real files before every run; untracked `wp-load.php` shim at the repo root (git-excluded) — the same harness pattern as Stage 3.2/3.3 |
| Dataset | rebuilt with the repo's own seeders in the documented order: `stage32-seed-pilot.php`, `create-pages.php`, `seed-course-providers.php`, `stage2-polylang-setup.php`, `stage32-translate-pages.php`, `stage32-translate-terms.php`, `stage32-translate-content.php`, `stage32-share-location-taxonomies.php`, `create-en-primary-menu.php` (+ menu curation scripts) — 15 EN records, 10 events incl. 3 hidden, 8 leisure (5 internal / 3 external), 4 sponsors, 3 jobs, 8 course providers, 6 guides, 5 posts, 43+ pages |
| REST routes at baseline | 198 routes — standard WP REST (`/wp/v2/{event,leisure,guide,posts,job,course_provider,sponsor,...}` + taxonomies + `/wp/v2/search` + Polylang's `/pll/v1/*`); **no custom conexao routes** |
| REST payload shape at baseline | standard WP post objects: id/date/slug/status/type/link/title/content/excerpt/author/featured_media/menu_order/template/meta (28 `_event_*` keys)/`conexao_*` term ids/class_list/`_links`; **no `lang` field, no `_links.translations`, no `conexao_language`** |
| Collection behaviour at baseline | no language filtering at all: `/wp/v2/event` returned 8 records (PT + EN mixed); `?lang=en` returned the identical 8 — Polylang Free sets the language *context* from `lang` (curlang) but does not filter post queries |
| Term collections at baseline | Polylang Free DOES filter terms in REST by curlang: `conexao_category` 34 (no lang) / 23 (`lang=pt`) / 11 (`lang=en`); invalid `lang=xx` silently behaved as `pt` (the ambiguity this stage removes); county/town (untranslated) returned all 9 terms regardless |
| Detail behaviour at baseline | any record ID answered 200 in any language context — including **hidden events** (`/wp/v2/event/77` expired → 200 with full payload), while the front-end singles 404 |
| Search at baseline | `/wp/v2/search?search=festa` mixed PT+EN results (4) |
| Test counts at baseline | stage33 93/0 · stage32 42/0 · polylang-foundation 61/0 · i18n 29/0 · event-location-filters 46/0 · guide-breadcrumb 36/0 · leisure-attribute 22/0 · leisure-card-map 44/0 · leisure-multiselect 63/0 · leisure-related 19/0 · nav-menu-regression 26/5 → 31/0 after local menu curation · recurrence-i18n 12/0 · sponsor-archive 15/0 · header-menu pt 12/0 / en 14/0 · event-language-gate 16/0 · event-query 41/0 · event-recurrence 88/2 → 90/0 after timezone fix · plugin-separation (with-tooling) 34/0 · language-identity 27/0 · export-language 57/0 · town-sanitization 30/3 (documented dataset-existence failures, Stage 3.2 §22) · translation-state 22/0 · recurrence-admin 60/0 · past-event-filter 0 failures · reappearing-event-lifecycle ALL PASS |

**Stage 3.3 invariant — re-confirmed exactly**: Polylang Free 3.8.9 provides
neither collection-level `?lang=` filtering for posts (mixed 8-record response
with and without `lang=en`, identical membership) nor `_links.translations`
(absent from every payload). Term collections are the one place Polylang
filters, and its invalid-value fallback is silent (default language). The
contract therefore had to be implemented in the REST layer itself; nothing was
assumed to come from Polylang.

## 3. Flutter baseline (Phase 0)

**NOT TESTABLE — repository inaccessible.**

- `git ls-remote https://github.com/abalzan/conexaobr_app.git` →
  `remote: Repository not found` (with and without the egress proxy's injected
  credentials).
- `gh api repos/abalzan/conexaobr_app` → HTTP 404 (authenticated as
  `cline-cloud[bot]`, the GitHub App token this sandbox provides).
- The repository does not appear in the 61 repositories listed for the
  `abalzan` account, and no fork/mirror under a different name is discoverable
  (`gh search repos conexao flutter`, `gh search repos conexaobr in:name`).

Consequently the required Phase 0 Flutter inventory (REST DTO → mapper →
domain → repository → Drift → Riverpod flow; existing Event/Lazer/Guide/Blog/
Employment/Course API clients; cache keys and persistence boundaries;
navigation/language abstractions) **could not be recorded**. The Flutter-side
deliverables of Phases 9–17 are correspondingly marked NOT PERFORMED in this
report (§12–§17, §19, §25) and are carried into §26 as the first Stage 4.2
prerequisite. No claim is made anywhere about Flutter behaviour.

## 4. REST contract (Phases 1–2)

Implemented in **one new theme module** — `wp-content/themes/conexao-br-irlanda/inc/rest-language.php`
(729 lines) — loaded from `functions.php` immediately after `inc/polylang.php`.
The theme is the owner per the architecture ("theme language logic lives only
in inc/…"); every hook is guarded by `conexao_polylang_active()`, so with
Polylang inactive the REST API behaves exactly as before Stage 4.1.

**Request model** (the existing endpoints — no new routes; the route inventory
stays at 198):

| Request | Behaviour |
|---|---|
| `GET /wp-json/wp/v2/{type}` (no `lang`) | unchanged pre-Stage-4.1 behaviour (unfiltered collection, any-language detail) |
| `GET /wp-json/wp/v2/{type}?lang=pt` | the Portuguese collection / detail |
| `GET /wp-json/wp/v2/{type}?lang=en` | the English collection / detail per the B1/B2 policy |
| `lang` = anything else | **HTTP 400** `conexao_rest_invalid_lang`, `{ status: 400, lang: <given>, supported: ["pt","en"] }` — never a silent mixed/default-language response (this deliberately overrides Polylang Free's silent default-language fallback) |

Supported post types: `event`, `leisure`, `guide`, `post` (route `posts`),
`job`, `course_provider`, `sponsor`. Supported taxonomies: translated
(`conexao_category`, `conexao_tag`, `category`→`categories`, `post_tag`→`tags`)
+ shared (`conexao_county`, `conexao_town`). Plus `/wp/v2/search` and the
`search` param on typed collections. Accepted values are discovered from
Polylang (`pll_languages_list`), never hard-coded.

Implementation surfaces (all core-designed extension points):

- `rest_{$post_type}_query` — collection filtering (tax_query on Polylang's
  `language` taxonomy — deterministic, independent of Polylang's front-end
  query filters, which are not loaded in REST);
- `rest_request_before_callbacks` (prio 5) — invalid-lang 400; (prio 10) —
  detail language rules + the event status gate;
- `register_rest_field('conexao_language')` on the 7 post types, the 6
  taxonomies and `search-result` — standard additional-fields pipeline, so it
  runs identically over HTTP and in-process and respects `_fields`;
- `rest_post_search_query` — search handler filtering;
- `rest_{$post_type}_collection_params` — `lang` is documented in the route
  schema (`OPTIONS /wp/v2/event` → `lang: { type: string, enum: [pt, en] }`)
  for discoverability; the **authoritative error contract** for an invalid
  value is uniformly `conexao_rest_invalid_lang` (the before-callbacks
  validation also covers detail/term/search routes where no schema argument
  exists, and replaces core's `rest_invalid_param` on collections so clients
  see one error code everywhere).

**Record metadata** — the smallest additive representation, one new
read-only field per record:

```json
"conexao_language": {
  "lang": "pt",              /* the record's own language slug */
  "is_fallback": false,      /* true iff the record is served under a requested
                                language different from its own (B2) */
  "translations": {          /* linked counterparts, excluding self */
    "en": { "id": 136, "url": "https://…/en/eventos/festa-junina-dublin-2026-en/" }
  }
}
```

The four required states are distinguishable without scraping HTML
(all verified, §18/§19):

1. **real PT record** — `lang=pt`, `is_fallback=false`;
2. **real EN translation** — `lang=en`, `is_fallback=false`, `translations.pt` present;
3. **B2 PT fallback under EN** — `lang=pt`, `is_fallback=true`, no translations;
4. **source-inherited EN record** — `lang=en`, `is_fallback=false`, `translations` empty.

The **existing `link` field already carries the language-correct canonical**
(no duplicate field was invented): real EN → EN permalink (self-canonical);
B2 fallback → PT permalink (canonical of untranslated data, per
`conexao_polylang_canonical_url`); source-inherited EN → EN permalink (self).
Internal Polylang implementation details are not exposed (no term ids, no
`_translation_outdated` — editorial state stays admin-only); stable WordPress
identifiers and canonical URLs only. Term records carry
`{ lang, translations }` where `lang: null` means a **shared** term (county/town).

## 5. Language filtering (Phase 3)

The exact language-selection rule per collection (mirrors the front-end
architecture — Stage 3.3 §13 is the reference):

| Type | `lang=pt` | `lang=en` |
|---|---|---|
| event (B2 + status gate) | `language=pt` AND `_event_status` public | (`language=en` OR `language=pt`) AND public, minus PT masters that have a published EN translation |
| leisure, sponsor, course_provider, job (B2) | `language=pt` | (`language=en` OR `language=pt`), minus replaced PT masters |
| guide, post (B1) | `language=pt` | `language=en` only — **no fallback** |

Verified counts on the pilot dataset: event 6 pt / 7 en (2 EN + 5 B2) —
identical membership to the front-end `/en/eventos/`; leisure 8/8; guide 6/2;
posts 5/1; job 3/3; course_provider 8/8; sponsor 4/4. Rules proven in §18/§19:
B1-only content never appears in EN; a PT master with an EN translation never
appears next to it; hidden events never appear; source-inherited EN events
remain EN-only; externally classified Lazer records keep their data and
classification; no identity appears twice in one language collection. PT rules
preserve the Portuguese API behaviour: `lang=pt` returns exactly the
Portuguese records (mirroring the front-end PT archives, which Polylang
filters to `pt`).

Filters compose: `include`/`post__in` is filtered directly (WP_Query ignores
`post__not_in` when `post__in` is set — the same rule the theme's archive
wideners use), `per_page`/`page` pagination never surfaces a replaced master,
taxonomy term-id filters compose with the language scope (§9).

## 6. Translation metadata (Phase 2)

The deterministic translation relationship is `conexao_language.translations`:
for every record, the map of linked counterparts keyed by language slug, each
`{ id, url }` — the counterpart's stable WordPress ID and its canonical
permalink. Examples (wire-verified):

- PT master `festa-junina-dublin-2026` (#73) → `translations.en = { id: 136, url: …/en/eventos/festa-junina-dublin-2026-en/ }`;
- EN translation #136 → `translations.pt = { id: 73, url: …/eventos/festa-junina-dublin-2026/ }`;
- source-inherited EN #80 → `translations: {}` (no PT counterpart exists);
- shared county `dublin` → `{ lang: null, translations: {} }`.

The 404 recovery channel carries the same relationship: a wrong-language
detail request answers
`conexao_rest_language_unavailable` with `data.translations` populated, so a
client can re-fetch the right record deterministically. Fallback state is the
`is_fallback` boolean (B2 render under a different requested language).
Editorial state (`_translation_outdated`) is NOT exposed — admin-only, as
required.

## 7. Event API behaviour (Phase 5 — hard gates)

| Gate | Result |
|---|---|
| `_event_source` + `_event_source_id` unchanged | PASS — identity meta byte-identical on master and EN translation; the importer suites (27 + 57 assertions) still pass |
| `_event_export_uuid` unchanged | PASS — exactly one shared master↔translation pair in the dataset; EN collection contains each identity at most once |
| `_event_source_language` is metadata only | PASS — never read by any REST decision; export suite 57/0 |
| `_event_status` remains authoritative | PASS — the runtime's `pre_get_posts` gate already covered REST collections; **Stage 4.1 extends the same rule to REST detail requests**: expired/rejected/source_not_found events now answer 404 `rest_post_invalid_id` (indistinguishable from a nonexistent record) for public requests — closing the only leak (baseline: 200 with full payload, while the front-end single 404s). Editorial users (`edit_post` capability) keep access |
| Hidden events absent from public responses | PASS — collections (default/pt/en), details (default/pt/en), EN search, all verified |
| EN translation is not an importer target | PASS — unchanged importer semantics (`is_import_target()` false, guarded upsert skips) |
| No REST request creates or mutates Event records | PASS — identity/status/modified snapshot before/after a full read batch is identical; the module registers GET-path filters only |
| Translated Events do not become additional export identity rows | PASS — one row per identity asserted at the data level |

The detail status gate is the one intentional behaviour change on the legacy
surface: it is required by Phase 5 ("expired/rejected/source_not_found Events
remain absent from public responses" — a 200 detail IS a public response) and
aligns REST with the already-established public behaviour (front-end 404).
It is documented here, in docs/routing.md, and asserted by dedicated test
assertions (§19).

## 8. Lazer API behaviour (Phase 6 — hard gates)

| Gate | Result |
|---|---|
| `_leisure_export_uuid` unchanged | PASS — identical on master (#83) and EN translation (#137); asserted at the data level (the UUID is migration-internal and is deliberately NOT newly exposed in REST payloads — the test asserts its absence) |
| Real EN translation resolves as the translated record | PASS — `lang=en` collection/detail return #137 with `lang=en`, `is_fallback=false`, PT link |
| PT master replaced in EN collections, not duplicated | PASS — #83 never appears next to #137 |
| B2 internal-page records behave per Stage 3.3 | PASS — untranslated internal records (e.g. cliffs-of-moher) appear in EN as explicit `is_fallback=true` records |
| Externally classified records preserve their destination | PASS — fota-wildlife-park appears in EN as B2 with its classification data intact; `conexao_leisure_external_url()` untouched (external → url, internal → ''), the redirect stays a front-end concern |
| REST does not create duplicate Lazer identity | PASS — no duplicate `_leisure_export_uuid` in any language collection |

## 9. Taxonomy / filter contract (Phase 7)

- **Shared** (`conexao_county`, `conexao_town`): one shared term/slug per
  county/town — identical collections in `lang=pt` and `lang=en` (verified
  id-for-id), `conexao_language.lang = null`, no translations, **zero
  suffixed/duplicated terms** (regex scan over all county slugs). Filtering
  posts by a shared county (dublin) works identically in both languages and
  composes with the EN replacement rule.
- **Translated** (`conexao_category`, `conexao_tag`, `category`, `post_tag`):
  the language-appropriate term resolves — EN collection exposes
  `documents`, `finances`, `festivals`, `nature`, … (11 EN terms); PT exposes
  `documentos`, `financas`, … (23 PT terms); the sets never mix; each EN term
  links its PT counterpart (`translations.pt.id`). Polylang's own REST term
  filtering provides this for valid languages (kept); Stage 4.1 adds the 400
  for invalid values (removing the silent pt-fallback) and the metadata field.
- Post filtering by the language-appropriate term id: EN guides filtered by
  EN `documents` return the EN guide and exclude the PT master; PT guides
  filtered by PT `documentos` return the PT records. Term-id filtering matches
  records tagged with exactly that term — the same semantics as the front-end
  slug filters, where an EN archive filtered by an EN slug does not match
  PT-termed B2 records.
- **No county/town translations were created and no suffixed location terms
  exist** — the Stage 3.2 shared-term policy is untouched.

## 10. Search contract (Phase 11)

Search was not redesigned — the established WordPress language membership is
exposed through the existing endpoints:

- `GET /wp/v2/search?search=…` (no `lang`) — unchanged mixed membership
  (both PT and EN festa records);
- `?lang=pt` — PT masters only (EN records excluded);
- `?lang=en` — EN records + B2-eligible PT records; translated PT masters
  excluded (replaced, never duplicated); B1-only PT content excluded
  (untranslated PT guide never surfaces); hidden events excluded;
- `search=` on typed collections composes with the same rules;
- every search result carries `conexao_language` (post or term payload), so
  Flutter never infers language from title/body text.

## 11. Cache behaviour (Phase 8)

- **No new response cache and no new caching framework.** WordPress core does
  not cache REST responses; the module introduces zero persistence.
- The only reused cache is `conexao_b2_translation_replaced_pt_ids()` —
  **language-independent identity data** (the set of PT ids hidden behind
  their EN translations), keyed by post-type subset only, and invalidated on
  `save_post`/`delete_post`/`wp_insert_post` by the theme's existing
  `conexao_homepage_cache_invalidate()` hook. PT and EN responses can
  therefore never share a cache key.
- Deterministic tests (§19 section 9): warming PT between two EN reads
  changes nothing (identical EN payload, PT master still replaced); warming
  EN does not affect PT; **creating** a real EN translation for a B2 event
  immediately flips the EN collection (EN record in, PT master out — no stale
  fallback) while the PT collection stays identical; **trashing** the
  translation immediately returns the PT master exactly once; the EN
  collection still has one row per identity after the lifecycle.
- The event runtime's date/language-scoped transients
  (`conexao_event_upcoming_<date>_{pt|en}`) are untouched and remain
  compatible (Stage 3.3 §17 behaviour; the REST layer does not read them).

## 12. Flutter DTO/model changes (Phase 9) — NOT PERFORMED

The Flutter repository could not be accessed (§3), so no DTO/model change was
made or verified. **Design guidance for Stage 4.2** (contract-driven, ready to
implement once the repository is accessible): the DTO layer should add a
`conexao_language` object (lang / is_fallback / translations map) to the
existing Event, Lazer, Guide, Blog, Employment, Course, Sponsor and
Search-result DTOs; the domain model should carry `language`, the
translation/fallback state and the canonical URL where already appropriate;
Polylang-specific implementation details must not be persisted. The
WordPress contract this guidance builds on is frozen and verified in §4–§11.

## 13. Mapper changes (Phase 9) — NOT PERFORMED

Same blocker. Stage 4.2 guidance: mappers map `conexao_language` into the
domain representation above; PT parsing must remain the default and must be
regression-tested before any EN assertion, per the stage rules.

## 14. Repository changes (Phase 9) — NOT PERFORMED

Same blocker. Stage 4.2 guidance: add an explicit language input at the
repository/data boundary (e.g. a `ContentLanguage` enum threaded through the
repository interfaces and Dio request builders) rather than scattering
`?lang=en` string concatenation through widgets; existing Portuguese
behaviour stays the default when no language is supplied (matching the
server's no-`lang` contract).

## 15. Riverpod/provider changes (Phase 9) — NOT PERFORMED

Same blocker. Stage 4.2 guidance: the language becomes a provider-level input
(caches/pagination keyed by it — see §16) so UI layers never build language
URLs by hand.

## 16. Drift/database changes (Phase 10) — NOT PERFORMED

Same blocker. Stage 4.2 guidance, contract-aligned: where the same WordPress
identity can exist in PT and EN (Event/Lazer translation pairs), the local
cache must be able to hold both representations without accidental overwrite
— language as part of the cache/unique keys — WITHOUT altering the identity
semantics (`_event_source`/`_event_source_id`/`_event_export_uuid`/
`_leisure_export_uuid` remain the portable identity; WordPress numeric ids
are per-language record ids). Any Drift schema migration must be additive and
reversible with a focused migration test that preserves installed Portuguese
data. None of this was implemented or tested in Stage 4.1.

## 17. Navigation impact (Phase 9) — NOT PERFORMED

Same blocker. The WordPress side of the contract needed for navigation is
ready and verified: `conexao_language.translations[<lang>].url` provides the
language-correct destination URL for every linked record, and the `link`
field is the canonical (§4, §6). No Flutter navigation file was inspected or
changed.

## 18. HTTP verification matrix (Phase 13)

Generator: `scripts/stage41-rest-verify.py` (read-only GETs; local run:
`python3 scripts/stage41-rest-verify.py http://127.0.0.1:8090 localhost:8080`).
Machine-readable output: **`stage41-rest-matrix.json`** (committed, 59 rows;
each collection row records url, lang, status, records, record_langs,
fallback_records, duplicate_identity, hidden_event_visible,
translation_metadata; each detail row records record_lang, fallback,
canonical_url, translations, error_code). Console result: **156 passed,
0 failed**. Selected rows (wire-observed):

| URL | lang | HTTP | Records | langs | fallbacks | dup identity | hidden | notes |
|---|---|---|---|---|---|---|---|---|
| `/wp-json/wp/v2/event` | pt | 200 | 6 | [pt] | 0 | no | no | PT masters only |
| `/wp-json/wp/v2/event` | en | 200 | 7 | [en, pt] | 5 | no | no | 2 EN + 5 B2; #73 replaced by #136 — matches front-end `/en/eventos/` |
| `/wp-json/wp/v2/leisure` | en | 200 | 8 | [en, pt] | 7 | no | no | #83 replaced by #137 |
| `/wp-json/wp/v2/guide` | en | 200 | 2 | [en] | 0 | no | no | B1: real EN only |
| `/wp-json/wp/v2/posts` | en | 200 | 1 | [en] | 0 | no | no | B1 |
| `/wp-json/wp/v2/job`, `/course_provider`, `/sponsor` | en | 200 | 3 / 8 / 4 | [en, pt] | 2 / 7 / 3 | no | no | B2 |
| `/wp-json/wp/v2/event/73` | pt | 200 | — | pt | false | — | — | canonical `/eventos/festa-junina-dublin-2026/` |
| `/wp-json/wp/v2/event/136` | en | 200 | — | en | false | — | — | canonical `/en/eventos/festa-junina-dublin-2026-en/` |
| `/wp-json/wp/v2/event/74` | en | 200 | — | pt | **true** | — | — | B2 fallback; canonical = PT permalink |
| `/wp-json/wp/v2/event/80` | en | 200 | — | en | false | — | — | source-inherited EN; self-canonical; no translations |
| `/wp-json/wp/v2/event/73` | en | 404 | — | — | — | — | — | `conexao_rest_language_unavailable` + EN pointer |
| `/wp-json/wp/v2/event/136` | pt | 404 | — | — | — | — | — | same code + PT pointer |
| `/wp-json/wp/v2/guide/63` | en | 404 | — | — | — | — | — | B1 content never renders under EN |
| `/wp-json/wp/v2/event/77` (hidden) | en / none | 404 | — | — | — | — | — | `rest_post_invalid_id`, indistinguishable from nonexistent |
| `/wp-json/wp/v2/event/999999` | en | 404 | — | — | — | — | — | nonexistent |
| `/wp-json/wp/v2/{7 types}` + `/search` + `/conexao_county` | xx | 400 | — | — | — | — | — | `conexao_rest_invalid_lang` |
| `/wp-json/wp/v2/guide?conexao_category=138` | en | 200 | EN guides | [en] | 0 | no | no | EN term `documents` filter |
| `/wp-json/wp/v2/guide?conexao_category=11` | pt | 200 | PT guides | [pt] | 0 | no | no | PT term `documentos` filter |
| `/wp-json/wp/v2/event?conexao_county={dublin}` | en / pt | 200 | Dublin events | per lang | — | no | no | shared county; EN side replaces the PT master |
| `/wp-json/wp/v2/search?search=festa` | none / pt / en | 200 | 4 / 2 / 2 | mixed / pt / en | — | no | no | every result carries `conexao_language` |
| legacy collections (7) | none | 200 | 8 / 9 / 8 / 6 / 4 / 9 / 5 | mixed | — | no* | no | *master + translation both listed by design (distinguishable by lang) |

The full 59-row matrix with exact URLs and canonical values is committed as
`stage41-rest-matrix.json`; fixture ids resolve by slug at run time except the
hidden event, which the gate keeps out of every public collection (its pilot
id is configurable as the script's 3rd argument; the authoritative dynamic
hidden-event assertions live in the PHP suite, §19).

## 19. Tests and exact results (Phase 12)

**New suite** — `wp-content/themes/conexao-br-irlanda/tests/test-stage41-rest-language.php`
(793 lines): dispatches real REST requests **in-process through the WordPress
REST server**, with the process booted in Polylang's REST context so
Polylang's own `rest_pre_dispatch` language handler runs exactly as over HTTP
(the harness resets the Polylang current language per request to mirror a
fresh HTTP process). Sections: 1 request model, 2 translation metadata,
3 detail endpoints, 4 collection membership (B1/B2 matrix over all seven
types + include/pagination composition), 5 event hard gates (identity,
no-mutation snapshot, export-uuid uniqueness), 6 lazer hard gates,
7 taxonomy contract, 8 search contract, 9 cache separation (warm-up order +
translation create/trash lifecycle), 10 backward compatibility. Fixtures are
resolved from the pilot dataset; the [S41] lifecycle fixture is deleted and
its translation group dissolved at the end (dataset byte-stability).

**Result: `stage 4.1 rest-language: 212 passed, 0 failed`** (run twice for
determinism; idempotent).

**Wire-level** — `scripts/stage41-rest-verify.py` → **156 passed, 0 failed**
(+ `stage41-rest-matrix.json`, 59 rows).

**PHP syntax checks** — `php -l` clean on every changed file
(`functions.php`, `inc/rest-language.php`, the new test) and on every
`inc/*.php` + plugin main/include file. **`git diff --check`** — clean
(no whitespace/conflict markers).

**Existing suites re-run after the change** (every changed layer verified for
PT regression before EN assertions, per the stage rule):

| Suite | Result |
|---|---|
| test-stage33-bilingual | 93 passed, 0 failed |
| test-stage32-bilingual | 42 passed, 0 failed |
| test-polylang-foundation | 62 passed, 0 failed (baseline batch printed 61 — see note) |
| test-i18n-foundation | 29 passed, 0 failed |
| test-event-location-filters | 46 passed, 0 failed |
| test-guide-breadcrumb-filter | 36 passed, 0 failed |
| test-leisure-attribute-normalization | 22 passed, 0 failed |
| test-leisure-card-map-action | 44 passed, 0 failed |
| test-leisure-multiselect-filters | 63 passed, 0 failed |
| test-leisure-related-events | 19 passed, 0 failed |
| test-nav-menu-regression | 31 passed, 0 failed |
| test-recurrence-i18n | 12 passed, 0 failed |
| test-sponsor-archive-ordering | 15 passed, 0 failed |
| test-header-menu-selection (pt / en) | 12/0 · 14/0 |
| test-event-language-gate (runtime) | 16 passed, 0 failed |
| test-event-query | Passed: 41, Failed: 0 |
| test-event-recurrence | PASSED: 90, FAILED: 0 |
| test-plugin-separation (with-tooling) | PASSED: 34, FAILED: 0 |
| test-language-identity (importer) | 27 passed, 0 failed |
| test-export-language (importer) | 57 passed, 0 failed |
| test-town-sanitization (importer) | 30 passed, 3 failed — the pre-existing dataset-existence failures documented by Stage 3.2 §22 / 3.3 §25.6, unchanged |
| test-translation-state (admin-ux) | 22 passed, 0 failed |
| test-recurrence-admin (admin-ux) | Passed: 60, Failed: 0 |
| test-past-event-filter (importer) | Failed: 0 |
| test-reappearing-event-lifecycle (importer) | ALL PASS (0 failures) |

Note on polylang-foundation 61→62: the suite emits one assertion per member
of the translation group of the newest event; during the environment rebuild
the EN event translation became the newest event between the first baseline
batch and the later runs, adding one *passing* assertion (both #73 and #136
correctly share identity meta). Zero failures throughout; no Stage 4.1 code
influences that suite (it exercises no REST surface). The first batch also
predates two local environment-setup fixes documented in §2 (timezone, menu
curation) whose earlier failures (2 recurrence, 5 nav) were environmental and
are resolved.

**Flutter tests** — **NOT RUN** (repository inaccessible, §3): no
`flutter analyze`, no existing tests, no new DTO/mapper/repository/cache-key/
Drift-migration/language-selection/identity/search/pagination/error tests
were executed. No Flutter result is claimed.

## 20. Backward compatibility (Phase 14)

Explicitly proven (PHP suite section 10 + HTTP matrix legacy rows):

- **Old requests without `?lang=` behave exactly as before**: every legacy
  collection returns precisely the union of the PT and EN public memberships
  (nothing removed, nothing added) — e.g. `/wp/v2/event` still returns the
  same 8 records (6 PT + 2 EN) as at baseline; `/wp/v2/search?search=festa`
  still returns the same 4 mixed results.
- **Legacy detail**: any record of any language answers 200 without `lang`
  (PT and EN records both verified) — the language-mismatch 404 applies only
  when a client explicitly requests a language.
- **PT filters**: `conexao_category=<pt term>`, `conexao_county=<shared>`
  work unchanged (matrix rows); `/wp/v2/conexao_category` without `lang`
  still returns all 34 terms.
- **Schema**: the only response difference for legacy consumers is the
  additive read-only `conexao_language` field; every pre-existing field is
  still present (asserted field-by-field); `_fields` filtering works
  (including `_fields=id,conexao_language`).
- **No existing consumer is forced to add `lang=pt`** — omission is the
  documented, tested, default-preserving path.
- The one intentional legacy-surface change is the hidden-event detail 404
  (§7) — required by Phase 5, aligned with the front-end, editorial access
  preserved, and never a legitimate consumer path (hidden events are absent
  from every collection).

## 21. Exact files changed (branch `cline/hd9yjjc0`, commits `a59c2e7`…`2b067ad`)

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/rest-language.php` | **new** — the bilingual REST contract module (729 lines) |
| `wp-content/themes/conexao-br-irlanda/functions.php` | loads `inc/rest-language.php` after `inc/polylang.php` (+10 lines: one require + comment) |
| `wp-content/themes/conexao-br-irlanda/tests/test-stage41-rest-language.php` | **new** — 212-assertion REST contract suite (793 lines) |
| `scripts/stage41-rest-verify.py` | **new** — Phase 13 wire-level matrix generator (340 lines) |
| `stage41-rest-matrix.json` | **new** — machine-readable matrix output (59 rows) |
| `docs/routing.md` | new "English rollout state (Stage 4.1 — bilingual REST contract)" section |
| `docs/themes/conexao-br-irlanda.md` | `inc/rest-language.php` added to "Files to Inspect First" |
| `CONEXAO_BR_ENGLISH_STAGE_4_1_REPORT.md` | **this report** |

Total: 7 files (the report being the 8th), 2640 insertions(+), 0 deletions of
existing behaviour (`git diff --stat 43146aa..HEAD`, report excluded from that
stat).

## 22. Exact files intentionally not changed

- **All plugin code** — `conexao-data-model` (CPT/taxonomy registration),
  `conexao-content`, `conexao-admin-ux`, `conexao-event-runtime`
  (`_event_status` gate, recurrence, town taxonomy, status UI),
  `conexao-event-importer` (identity/dedup/import/export semantics),
  `conexao-leisure-migration` (UUID semantics), `conexao-sponsor-migration`.
- **CPT registration, taxonomy registration, Polylang files/settings** —
  untouched (the module only *reads* Polylang's model/API).
- **Theme language + SEO architecture** — `inc/polylang.php`, `inc/i18n.php`,
  `inc/seo.php` (SEO/canonical/hreflang/sitemap ownership), `inc/search.php`,
  all templates, `assets/`, every other `inc/` module.
- **`.htaccess`** (legacy redirect map, headers) — byte-untouched.
- **Front-end B1/B2 routing decisions** — `conexao_b2_page_allowlist()`,
  redirect filters, archive wideners: unchanged (the REST layer *consumes*
  `conexao_is_b2_post_type()` / `conexao_b2_translation_replaced_pt_ids()`,
  it does not redefine them).
- **Importer/exporter identity rules** — untouched and re-verified (§7).
- **No production files, no WordPress.com settings, no Jetpack, no Polylang
  configuration, no production data, no Flutter files.**

## 23. Production safety (Phase 15)

LOCAL/STAGING ONLY. Nothing was uploaded to production (WordPress.com /
https://conexaobr.ie), no REST code deployed, no Flutter build produced,
no WordPress.com setting, no Jetpack change, no Polylang configuration
change, no production data touched. All work lives on the WIP branch
`cline/hd9yjjc0` of `abalzan/wordpress-website` (never the default branch);
the default branch and the production site are untouched.

**NOT_TESTABLE items** (production-specific, listed per the stage rules):

1. Production REST behaviour of the contract (the module is not deployed;
   production currently has no EN content, so `lang=en` there would return
   the empty/PT-only EN surface until Stage 3.x content rolls out).
2. WordPress.com hosting-level response caching/CDN interactions with the
   `lang` query parameter (production caching is hoster-owned; locally
   WordPress core does not cache REST responses and the module adds none).
3. `.htaccess` execution (no Apache in this sandbox — unchanged file,
   WordPress-level evidence only, as in Stage 3.3).
4. Jetpack sitemap reconciliation (Jetpack not installed locally — unchanged
   from Stage 3.2/3.3).
5. Production menu/EN-content state (§2 of Stage 3.3 §25).

## 24. Browser / device status

- **No browser/device validation was performed** — this sandbox has no
  browser tooling (same limitation as Stage 3.3 §20). All evidence in this
  report is code-level, in-process-dispatch-level and real-HTTP-level
  (curl against the local WordPress stack).
- The REST contract is machine-consumed (JSON), so the browser-only gap does
  not affect the contract claims; no visual claim is made anywhere.
- Flutter device/emulator validation: **NOT TESTABLE** (§3).

## 25. Known limitations

1. **Flutter integration not performed (Phases 9–10) — the defining
   limitation.** `https://github.com/abalzan/conexaobr_app` is inaccessible to
   this environment (404 via git and the authenticated GitHub CLI; not among
   the 61 repos visible to the token). No Flutter baseline, DTO, mapper,
   repository, provider, Drift, navigation, test or analyze result exists in
   this stage. Everything Flutter is NOT TESTABLE, documented in §3/§12–§17/
   §19, and carried to §26 item 1.
2. **Pages are not part of the REST language contract.** The stage's
   supported collection list (Events, Lazer, Guides, Blog, Employment,
   Courses, Sponsors) does not include `page`; static pages keep the
   pre-Stage-4.1 REST behaviour. Bilingual pages are front-end scope (Stage
   3.x); a `pages` REST extension is a Stage 4.2 candidate (§26 item 2).
3. **Term filtering relies on Polylang's own REST term mechanism** for
   translated taxonomies (kept deliberately rather than reimplemented); the
   contract adds validation + metadata on top. If a future Polylang version
   changes that mechanism, the term rows of the matrix must be re-run.
4. **The hidden-event detail gate is a deliberate legacy-surface change**
   (§7): unauthenticated REST detail requests for expired/rejected/
   source_not_found events now 404 (they returned 200 at baseline). It is
   required by Phase 5 and matches the front-end; flagged here so no
   consumer is surprised.
5. **`lang` is validated on the contract routes only** (the 7 post types +
   details, the 6 taxonomies, `/wp/v2/search`). Other REST routes keep
   Polylang's default handling of a `lang` parameter (including its silent
   invalid-value fallback) — out of contract scope by design.
6. **Local dataset ≠ production dataset** (as in Stage 3.2/3.3): identities
   and counts verified here are the pilot catalogue's; the *behaviour* rules
   are dataset-independent and asserted structurally.
7. **`.htaccess` execution and browser/device validation are NOT TESTABLE**
   (no Apache, no browser tooling) — unchanged limitations from Stage 3.3.
8. **town-sanitization's 3 dataset-existence failures** remain (pre-existing,
   documented since Stage 3.2 §22; unrelated to this stage).
9. **The HTTP matrix's hidden-event row uses the pilot id** (configurable
   script argument) because the status gate keeps hidden events undiscoverable
   through the API itself — the authoritative dynamic assertions are in the
   PHP suite (§19).

## 26. Stage 4.2 prerequisites

1. **Flutter repository access** — grant the working credential access to
   `abalzan/conexaobr_app` (or provide a mirror), then execute Phases 9–10
   against the frozen contract of this report (guidance in §12–§17): DTO +
   domain model for `conexao_language`, explicit language input at the
   repository boundary, provider-level language parameter, language-scoped
   cache keys, additive Drift migration if needed, PT-regression-first tests,
   `flutter analyze` + full test suite.
2. **Decide the `pages` REST surface** (include pages in the contract with
   the B2-page allowlist rules, or keep them front-end-only).
3. **Decide whether `/pll/v1` routes** (Polylang's own languages endpoint)
   should also be exposed to the app or stay internal.
4. **Production rollout plan for the module** (deploy with the Stage 3.x
   content rollout; re-run `scripts/stage41-rest-verify.py` against
   production once EN content exists there).
5. **English editorial backlog** (Stage 3.3 §7) — which B1 records graduate
   to real translations; the REST contract reflects data, it does not create
   content.
6. **Production menu work + hreflang consolidation + metadata completion**
   (Stage 3.3 §25.3–5) — unchanged carry-overs.
7. **Apache-level redirect verification + Jetpack sitemap reconciliation**
   on the deployed stack (Stage 3.3 §25.1/8) — unchanged carry-overs.

## 27. Final acceptance matrix (Phase 16 hard gates)

| # | Hard gate (BLOCKED if…) | Result | Evidence |
|---|---|---|---|
| 1 | PT API behaviour changes unintentionally | **PASS** | no-`lang` surface keeps identical memberships, all fields intact (§19 s.10, §20); the one intentional change (hidden-event detail 404) is Phase-5-mandated and documented (§7) |
| 2 | `lang=en` does not return the defined EN collection | **PASS** | all 7 collections return the defined EN memberships (§5, §18, §19 s.4) |
| 3 | B1 content appears in EN when it should not | **PASS** | guides/blog EN = real translations only; B1 guide detail 404; B1 guide absent from EN search (§5, §10, §19) |
| 4 | B2 fallback appears without explicit B2 eligibility | **PASS** | only the five B2 post types produce fallbacks; every fallback row is flagged `is_fallback=true`; B1 types never fall back (§5, §18, §19 s.4) |
| 5 | PT master + EN translation both appear in the same EN collection | **PASS** | replacement asserted per type, with pagination/include/filter composition (§5, §18, §19 s.4) |
| 6 | Hidden Event statuses become visible | **PASS** | collections, details (now gated too) and search all exclude expired/rejected/source_not_found (§7, §18, §19 s.3) |
| 7 | Event identity changes | **PASS** | `_event_source`/`_event_source_id`/`_event_export_uuid` byte-identical; importer suites 27/0 + 57/0; no mutation from reads (§7, §19 s.5) |
| 8 | Lazer identity changes | **PASS** | `_leisure_export_uuid` shared master↔translation, no duplicates, classifier untouched (§8, §19 s.6) |
| 9 | Source-inherited EN Event is duplicated | **PASS** | #80 appears exactly once, EN-only, self-canonical, no translations (§5, §18, §19) |
| 10 | County/town terms become duplicated | **PASS** | identical shared collections PT/EN, `lang:null`, zero suffixed terms (§9, §18, §19 s.7) |
| 11 | PT/EN API caches collide | **PASS** | no new cache; the replacement set is language-independent + invalidated on save/delete; warm-up order and lifecycle tests (§11, §19 s.9) |
| 12 | Flutter bypasses the established architecture | **N/A — NOT TESTABLE** | no Flutter code could be read or changed (§3); the server-side contract keeps the boundary by being a pure REST surface |
| 13 | Existing Portuguese local data is lost | **PASS (WordPress)** / **N/A (Flutter)** | local WordPress dataset intact (only [S41] fixtures created + deleted with group dissolution); no Flutter database exists in this stage (§3) |
| 14 | Production is touched | **PASS** | local/staging only; WIP branch; nothing deployed (§23) |

## 28. Final classification

**ENGLISH STAGE 4.1 — PASS WITH LIMITATION**

The WordPress bilingual REST contract — the stage's core deliverable — is
fully implemented, deterministic and verified from three angles (in-process
REST dispatch: 212/0; wire-level HTTP matrix: 156/0 with 59 machine-readable
rows; every pre-existing suite re-run green), with backward compatibility
explicitly proven and all fourteen applicable hard gates passing. The
limitation is not hidden: Phases 9–10 (Flutter) were **not performed**
because the Flutter repository is inaccessible to this environment's
credentials, and no Flutter behaviour is claimed anywhere in this report.
Completing the Flutter half against the frozen, verified contract of §4–§11
is the first prerequisite of Stage 4.2 (§26).

---

## Appendix — final repository state (as required by the stage rules)

- Branch: `cline/hd9yjjc0` (created from `i18n` @ `43146aa`; pushed to
  `origin`, tracking set). The default branch was never committed to.
- HEAD at report time: `2b067ad` "Stage 4.1: document the bilingual REST
  contract (routing.md, theme docs)" — plus this report as the final commit.
- Stage commits: `a59c2e7` (REST contract module) · `e70c755` (test suite +
  rest_base fixes) · `b9b2cd2` (HTTP verification matrix) · `2b067ad` (docs).
- Working tree: clean of tracked changes; untracked local-only shim
  `/wp-load.php` is git-excluded via `.git/info/exclude` (never committed),
  same as Stage 3.2/3.3.
- No history was rewritten, no unrelated files deleted, no production branch
  or host touched.

