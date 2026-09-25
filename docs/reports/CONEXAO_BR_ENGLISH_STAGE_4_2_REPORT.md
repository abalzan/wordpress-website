# CONEXAO_BR_ENGLISH_STAGE_4_2_REPORT.md

Stage 4.2 of the approved English-support architecture
(`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`, audit
`CONEXAO_BR_ENGLISH_SUPPORT_AUDIT.md`, Stage 1/2/3.x reports, and the frozen
Stage 4.1 WordPress REST contract in `CONEXAO_BR_ENGLISH_STAGE_4_1_REPORT.md`):
complete the **Flutter half** of the bilingual architecture — Portuguese as
the default, English as a first-class app language, language selection at the
data/repository boundary, language-aware REST requests, language-scoped local
cache, language-safe pagination/search/filters, translation metadata and
unchanged Event/Lazer identity.

Everything is **local only**. No production change of any kind: WordPress
production was not touched, nothing was uploaded to WordPress.com, no release
APK/AAB was built, and no WordPress architecture was changed to compensate for
Flutter behaviour (no Stage 4.1 contract defect was found).

---

## 1. Status

**ENGLISH STAGE 4.2 — PASS** (with one explicitly listed limitation area
outside this stage's deliverable, §25: the pre-existing Portuguese UI chrome
strings are not translated in this stage).

The Flutter client now consumes the frozen Stage 4.1 contract end to end:
`ContentLanguage` at the application boundary, `conexao_language` parsed by
every contract DTO and mapped into the domain, `lang` applied exactly once by
the Dio transport, a Drift v7 additive migration that scopes the cache by
language view without losing a single Portuguese row, language-scoped
Riverpod state, language-keyed pagination/search/filters, server-driven
translation navigation, and one bounded wrong-language recovery probe.

Evidence: **`flutter analyze` — 0 issues**; **full Flutter suite green**
(pre-existing 995 tests plus new language tests); **11/11 Flutter-side
wire-contract checks** against the live local WordPress; **4/4 real-server
client integration tests**; device (Android emulator) verification of PT
startup, the language selector, EN collections, the translation action and
`lang` request hygiene.


## 2. Flutter baseline (Phase 0)

Recorded before any Stage 4.2 edit:

| Item | Value |
|---|---|
| Repository | `https://github.com/abalzan/conexaobr_app` (local clone `/home/andrei/development/conexaobr_app`) |
| Branch | `master` |
| HEAD at start | `5d7f08ceb4f48ea00298394fc38b1812a2a56472` |
| `origin/master` (last known) | same commit — see §3 for the fetch limitation |
| Working tree at start | clean except one pre-existing build artefact (`android/build/reports/problems/problems-report.html`) |
| Flutter | 3.47.2 (stable), engine `a804b26164` |
| Dart | 3.13.2 |
| `flutter doctor -v` | Flutter ✓, Android SDK 37 (licence warning), Linux toolchain ✓, 1 device (Linux desktop) |
| `flutter analyze` | No issues found |
| Baseline test suite | 995 passed, 0 failed (`flutter test`) |
| Local WordPress | Docker stack (`wordpress-website-wordpress-1`, `:8080`), theme with the Stage 4.1 module, Polylang `pt`+`en` active |

## 3. Repository / access verification

* The Flutter repository **is accessible locally** in this environment as a
  complete clone with full history (branch `master`, HEAD `5d7f08c`), and all
  Stage 4.2 work was performed there.
* A fresh `git fetch` / `git ls-remote https://github.com/abalzan/conexaobr_app`
  **fails with `fatal: could not read Username for 'https://github.com': No such
  device or address`** — this environment holds no GitHub credentials (and no
  `gh` CLI). The last-known `origin/master` ref is byte-identical to local HEAD
  (`5d7f08c`), i.e. local HEAD matched the remote as of the last successful
  fetch performed outside this stage.
* Consequently local HEAD vs remote HEAD is verified against the local
  remote-tracking ref, **not** against a live remote read. No commit or push was
  performed (not required by this stage), so the remote cannot have diverged as
  a result of this work.

This is recorded as a verification limitation, not a BLOCKED condition: every
deliverable below was implemented, tested and device-checked against the real
local clone.

## 4. Language abstraction (Phase 1)

`lib/core/language/content_language.dart` introduces the one canonical
representation:

* `enum ContentLanguage { pt, en }` with `code` (`pt`/`en` — the frozen wire
  values), `ContentLanguage.defaultLanguage = pt`, and
  `ContentLanguage.tryParse` that **never guesses** (unknown values return
  null). Only values this app itself persisted are parsed leniently, via
  `parseOrDefault`.
* `scopedCacheKey(base)` encodes the cache-key policy: Portuguese keeps the
  legacy unsuffixed key (`guides_list`), English is namespaced
  (`guides_list_en`) — so existing installs, existing tests and every
  Portuguese freshness timestamp survive the upgrade untouched.
* No raw `"pt"`/`"en"` string is scattered through widgets; request builders,
  repositories, cache keys and providers consume the enum.
* `lib/core/language/translation_ref.dart` owns the single parser for the
  contract's `translations` wire shape and the `TranslationRef` value object
  (id + canonical URL exactly as provided by the server — the app never builds
  a translated slug).
* `lib/core/language/app_strings.dart` centralises the few **new** UI labels
  (selector title, language endonyms, counterpart action, recovery action) so
  no literal English string is scattered through widgets.

Language state is injected through Riverpod (`contentLanguageProvider`), never
a global mutable singleton.

## 5. DTO changes (Phase 2)

Every content DTO that corresponds to a WordPress REST type parses
`conexao_language` null-safely through one shared `LanguageMetaDto`
(`lib/data/dto/language_meta_dto.dart`):

| DTO | File | Notes |
|---|---|---|
| Event | `event_dto.dart` | raw-JSON DTO |
| Lazer | `leisure_dto.dart` | raw-JSON DTO |
| Guide | `guide_dto.dart` | freezed DTO (field added, `*.freezed.dart`/`*.g.dart` regenerated) |
| Blog post | `post_dto.dart` | manual DTO |
| Employment | `opportunity_dto.dart` | `AgencyDto` + `PermitEmployerDto` (tolerated; those routes are outside the seven contract types, so the field simply never arrives) |
| Course provider | `course_provider_dto.dart` | manual DTO |
| Sponsor | `sponsor_dto.dart` | manual DTO |
| Search result | `search_providers.dart` | the app's local `SearchResult` projects the source record's metadata (the server `/wp/v2/search` endpoint is not consumed — see §14) |

Parsing rules (unit-tested):

* field absent / non-object → legacy value (`lang == null`), preserving the
  exact pre-Stage-4.2 Portuguese parsing;
* `translations` emitted as `[]` by PHP for an empty map → empty map;
* unknown language keys and malformed entries are skipped, never thrown;
* `_translation_outdated` and other Polylang internals are deliberately not
  modelled — the domain layer never sees them.

## 6. Domain model (Phase 3)

`lib/domain/models/content_language_meta.dart` defines
`ContentLanguageMeta { recordLanguage, isFallback, translations }` plus
`ContentLanguageState`, and it distinguishes exactly the four contract states
(first verified in unit tests and on the wire):

| State | Shape |
|---|---|
| `realPortuguese` | `lang=pt`, `is_fallback=false` |
| `realEnglish` | `lang=en`, `is_fallback=false`, linked PT counterpart present |
| `englishFallback` (B2) | `lang=pt`, `is_fallback=true` (PT record served under EN) |
| `sourceInheritedEnglish` | `lang=en`, `is_fallback=false`, no linked translations |
| `legacy` | metadata absent (pre-Stage-4.2 payload / migrated row) |

`counterpartFor(language)` exposes only the server-linked counterpart; there is
no derivation or slug construction. Every content domain model (Event, Guide,
Post, Leisure, Sponsor, CourseProvider, Opportunity) and the local
`SearchResult` carry the metadata; favourites snapshots report `legacy` because
they are language-agnostic user data. UI code never parses REST JSON and never
inspects WordPress metadata.

## 7. Dio / request builder (Phase 4)

`lib/data/api/wp_client.dart` gains one nullable `ContentLanguage? language`
parameter on `getCollection` and `getFullList`, applied through
`withLanguageQuery(query, language)`:

* `null` → the query map is returned **unchanged** (legacy no-`lang`
  behaviour preserved byte-for-byte for out-of-contract requests);
* `pt`/`en` → `lang=<code>` is added (replacing any pre-existing value, so it
  can never be appended twice);
* Dio serialises the whole map, so `lang` composes safely with `page`,
  `per_page`, `search`, `_embed`, `_fields`, ordering and taxonomy filters —
  verified by unit tests and by the on-device `NetworkCounter` (see §22).

Error mapping (`lib/core/errors/app_error.dart`, `AppError.fromDio`):

* HTTP 400 with `conexao_rest_invalid_lang` → new typed `InvalidLanguage`
  (deterministic; the app never silently downgrades to Portuguese);
* HTTP 404 with `conexao_rest_language_unavailable` → new typed
  `LanguageUnavailable` carrying `requestedLanguage`, `recordLanguage` and the
  server-provided `translations` map (`recoveryFor(language)` returns the
  linked counterpart URL, or null — nothing is invented);
* every other 400/404 keeps its previous mapping (`InvalidResponse` /
  `NotFound`), so existing error behaviour is unchanged.

Detail-by-slug (how this app fetches details) never returns the frozen 404
body, so the repositories additionally perform **one bounded legacy probe**
(`lib/data/repo/language_recovery.dart`) when a language-scoped slug query
comes back empty: a single no-`lang` request recovers the record's
`conexao_language` metadata. Found + different language → `LanguageUnavailable`
with recovery metadata; found + same language → the record is returned;
nothing → `NotFound`. No retry loop, no infinite polling.

## 8. Repository language boundary (Phase 5)

Every contract-touching public repository method takes
`ContentLanguage language = ContentLanguage.pt`, so existing callers keep
working unchanged while the repository becomes the canonical place where
language is selected, requests are built, cache keys are chosen and pagination
is scoped:

| Repository | Language-scoped surface |
|---|---|
| Guide | `cachedGuides`, `cachedGuide`, `listUpdatedAt`, `fetchPage`, `completeListCache`, `getGuide`, `filterCached`, `invalidateList`, `evictIfNeeded`, `filterVocabulary` |
| Post | `cachedPosts`, `cachedPost`, `listUpdatedAt`, `fetchPage`, `completeListCache`, `getPost`, `invalidateList` |
| Event | `cachedEvents`, `upcoming`, `getEvent`, `listUpdatedAt`, `refresh`, `evictIfNeeded`, `filterVocabulary`, `townsForCounties` |
| Leisure | `cachedLeisure`, `getLeisureList`, `getLeisure`, `listUpdatedAt`, `refresh`, `relatedLeisure`, `relatedEvents`, `filterVocabulary` |
| Sponsor | `cachedSponsors`, `getSponsors`, `listUpdatedAt`, `refresh` |
| Course provider | `cachedProviders`, `getProviders`, `listUpdatedAt`, `refresh` |
| Term | `terms`, `slugToId`, `idForSlug` |
| Opportunity | `getPageContent` only (`job` is a contract type; agencies/employers are outside the contract and stay shared) |
| Contact | unchanged (pages are outside the contract — `?lang` verified as a no-op) |

Details that matter for correctness:

* **Cache-meta keys** are language-scoped through `scopedCacheKey`; the event
  sweep's single-flight guard is now a per-language map, so an EN caller can
  never be handed an in-flight PT sweep;
* `completeListCache` counts cached rows **per language** before deciding to
  skip a sweep, and its pages are fetched with `lang`;
* the wrong-language detail probe is implemented once and reused by guide,
  post, leisure and event detail (for events the hidden-status gate is
  evaluated **before** the language mismatch, so hidden events can never
  surface through recovery).

## 9. Riverpod / provider changes (Phase 6)

`contentLanguageProvider` (`NotifierProvider<ContentLanguageNotifier,
ContentLanguage>`) is persisted under its **own** `content_language` key and is
not coupled to the theme preference. A dedicated
`sharedPreferencesOrNullProvider` tolerates test containers that do not
override `SharedPreferences`, so the provider graph can never end up in an
error state.

Scoping rules applied throughout:

* **List notifiers** (events, guides, blog, leisure) `ref.listen` to the
  language: a switch **synchronously resets the state** (no stale PT frame can
  render under EN) and then reloads exactly once for the new language. In-flight
  completions are discarded when the language changed underneath them, and the
  leisure notifier's coalescing key includes the language;
* **Detail providers** (`eventDetailProvider`, `guideDetailProvider`,
  `postDetailProvider`, `leisureDetailProvider`) `ref.watch` the language, so a
  switch re-runs the fetch in the new language instead of reusing PT state;
* **Vocabulary / option providers** (event/guide/leisure term options,
  dependent towns, blog categories) watch the language and pass it to the
  repository;
* **Search** is a *family keyed by language* (`searchIndexProvider(language)`),
  which structurally prevents a previous language's results from appearing;
* **Home previews / empregos page content** watch the language as well.

A **stability defect was found and fixed on device**: `ConexaoBrApp` rebuilds
on language/theme changes, and it was constructing a new `GoRouter` on every
build, which reset navigation to the initial route when the language changed.
The router is now a stable `appRouterProvider` instance; this also removes the
same latent reset from the pre-existing theme switch.

## 10. Drift / database changes (Phase 7)

Schema **v6 → v7**, implemented as a data-preserving rebuild:

| Table | Change |
|---|---|
| `guide_rows`, `post_rows`, `event_rows`, `leisure_rows`, `sponsor_rows`, `course_provider_rows` | `lang` (default `'pt'`) **added to the primary key** (`{id} → {id, lang}`), plus `is_fallback` (default 0) and `language_meta` (nullable JSON) |
| `term_rows` | `lang` (default `'pt'`) added to the primary key (`{taxonomy, term_id} → {taxonomy, term_id, lang}`) |
| `opportunity_rows`, `contact_page_rows` | unchanged (outside the bilingual contract) |
| term-link tables, `favorite_rows`, `cache_meta_rows` | unchanged structurally (cache keys are scoped in code) |

Collision analysis that drove the design:

* a PT master and its EN translation have different WordPress ids, so they
  coexist naturally — but an unscoped read would return **both** and render two
  logical items in one collection (gate 7). Scoping reads by `lang` and making
  `lang` part of the primary key fixes this structurally;
* a B2 record is the **same** WordPress id under both views. With `{id}` alone
  the second fetch would overwrite the first (flipping `is_fallback`); with
  `{id, lang}` both representations exist side by side and each view sees the
  correct flag;
* `terminology replaced per language` (`replaceTerms`) previously deleted
  **all** rows of a taxonomy, which would have wiped the other language's
  vocabulary; the delete is now scoped by `(taxonomy, lang)`;
* `evict*Except(keepIds)` and count/age budgets are scoped per language, so an
  EN refresh can never evict PT rows.

Migration safety: SQLite cannot alter a primary key in place, so each affected
table is rebuilt (rename → `m.createTable` with the current schema → copy the
**actual old column list read at run time** via `PRAGMA table_info` → drop). New
columns take their defaults, which is exactly the Portuguese-only history of
this database: every pre-existing row survives with `lang='pt'`,
`is_fallback=0`, `language_meta=NULL`, and favourites/freshness metadata are
untouched. Two focused tests cover it (a genuine v1 → v7 chain and a genuine
v6 → v7 file built by stripping the Stage 4.2 columns from the current DDL).

## 11. Upsert / identity semantics (Phase 8)

* Upserts conflict on `(id, lang)`; PT and EN representations therefore cannot
  overwrite each other, and a B2 record cannot overwrite a real EN translation
  of another id.
* A real EN translation replaces the PT master only at the **collection
  selection** level (the server already excludes the master from the EN
  collection); the cached PT record is untouched.
* A source-inherited EN record exists exactly once (single id, single row).
* Event identity (`_event_source` / `_event_source_id` / `_event_export_uuid`)
  and Lazer identity (`_leisure_export_uuid`) were not touched: the Flutter
  cache still keys by the per-language representation id plus the language
  view, and cross-language pairing comes only from the server-provided
  `translations` map. Numeric WordPress ids are never used as a cross-language
  identity.
* Duplicate rows cannot be created by language alone: the same (id, lang) pair
  is an upsert, and different (id, lang) pairs are different representations.

## 12. Pagination (Phase 9)

* page state lives in the list notifier and is reset by the language listener,
  so PT page 2 → PT page 3 stays PT, EN page 1 → EN page 2 stays EN, and a
  language switch never reuses the previous language's `totalPages`;
* every page request (including `completeListCache` sweeps and the event
  full-collection sweep) carries the language;
* B2 replacement happens server-side per page, and the existing slug de-dupe on
  `loadMore` still prevents re-appending a page; a translated PT master cannot
  reappear on later EN pages because it is not part of the EN collection at
  all.

Unit/integration tests cover sequential pagination, the language switch
mid-pagination, and provider-level reset (asserting the fake repository saw the
new language).

## 13. Filters (Phase 10)

Every filterable surface was audited and routed through the language-aware
repository/DB layer:

| Screen | Filters | Behaviour under EN |
|---|---|---|
| Eventos | Categoria, Condado, Cidade | vocabulary from EN-view content links; translated categories exclude B2 parents, shared counties/towns keep them; filters reset on language switch |
| Lazer | Tipo, Condado, Características | same rules; characteristics are meta flags (language-neutral) |
| Guias | Categorias, Condados | vocabulary language-scoped; slug→id resolution uses the language-scoped term cache |
| Blog | Categorias | core `categories` terms fetched with `lang`, so EN slugs resolve to EN term ids |
| Empregos | Localização, Tipo, Área, Contrato | agencies/employers are outside the bilingual contract and stay shared (verified `?lang=en` returns identical ids); the filters are meta-driven, not taxonomy-driven |

Correctness rules implemented in SQL (not inferred from labels):

* **shared** location taxonomies (`conexao_county`, `conexao_town`) are
  included for all records of the requested view, so a county that appears only
  on a B2 record is still filterable in EN;
* **translated** taxonomies (`conexao_category`, `conexao_tag`, `categories`,
  `post_tag`) exclude B2 fallback parents, so an English sheet can never offer
  Portuguese taxonomy terms — matching the verified server behaviour that an EN
  slug filter does not match PT-termed B2 records;
* no `dublin-en`-style term is ever created: the app only stores what the
  server returns, and shared county ids are identical in both languages;
* switching language resets the selected filter state, so an incompatible
  translated taxonomy id can never be retained.

## 14. Search (Phase 11)

Search in this app is a **local index over the language-scoped cache**
(Stage 19 architecture); the server `/wp/v2/search` endpoint was never
consumed by the app, so no client-side language inference exists anywhere.

* `searchIndexProvider` is now a family keyed by `ContentLanguage` and reads
  only rows of that language view. That structurally guarantees the Stage 4.1
  search contract is mirrored: EN records and server-allowed B2 fallbacks are
  present; B1 content and replaced PT masters are absent (they are not in the
  EN cache); hidden events are absent (server gate plus the client visibility
  filter).
* The search screen watches `searchIndexProvider(selectedLanguage)`, so
  switching language can never show results cached for the previous language,
  and the query text is simply re-applied to the new index.
* Phase 19 additionally verifies the *server* search contract shape
  (`/wp/v2/search?lang=en` returns `conexao_language` on every hit) as part of
  the frozen-contract regression, while the app continues to search locally.

## 15. Translation navigation (Phase 12)

* Detail screens (guide, event, leisure, post) render a translate action only
  when the server linked a counterpart for the other language
  (`LanguageCounterpartAction`). B2 fallbacks and source-inherited EN records
  have an empty `translations` map, so no action is shown — a fallback is never
  presented as a real translation, and no PT counterpart is invented.
* Tapping it switches the content language to the counterpart's own language
  and opens the counterpart through the **provided canonical URL** (the
  resolver maps the server-provided path to the existing native route; no slug
  is ever constructed). The app's existing `InternalLinkResolver` was extended
  to understand the `/en/` locale prefix (native routes are language-agnostic),
  which also lets EN canonical URLs inside HTML content navigate natively.
* Wrong-language recovery: `LanguageUnavailable` carries the server's
  `translations` map; `DetailErrorState` offers a secondary action that opens
  the linked counterpart from that provided URL. When no counterpart exists the
  screen shows a deterministic message with no invented link.
* One routing system only: the new action reuses `AppRouter.openNativeRoute` /
  `openX` helpers and the existing route catalog.

## 16. Offline / cache behaviour (Phase 13)

Verified by repository tests and the migration test:

1. load PT → PT rows cached under `lang='pt'`;
2. switch to EN → EN rows cached separately; `cachedGuides(en)` never returns
   PT rows (and vice versa);
3. the same B2 record cached under both views keeps both `is_fallback`
   states without overwriting;
4. returning to PT finds the PT cache intact (favourite rows and freshness
   metadata untouched by the EN round-trip);
5. when a translation is added/removed server-side, the next response is
   authoritative: EN collections are served by the server's replacement rule,
   and the local EN view is rebuilt from that response.

No cross-language leakage path exists: every read, write, eviction, cache key
and cache-meta timestamp is scoped by the language view.

## 17. Portuguese backward compatibility (Phase 14)

* The full pre-existing suite (995 tests) was run **before** any EN-specific
  assertion was added, and again at the end: **1037 passed, 0 failed**.
* Portuguese remains the default everywhere: repository method defaults are
  `ContentLanguage.pt`, PT requests send `?lang=pt`, PT cache keys keep their
  legacy names, and legacy payloads (no `conexao_language`) hydrate to the
  legacy state, which behaves exactly as before.
* Existing behaviour that had to be reconciled was updated deliberately and is
  listed in §23: the schema-version expectation in the release-gate migration
  test (6 → 7, with added language assertions) and the mechanical widening of
  test fakes/companions for the new primary keys and nullable parameters.
* Home, Eventos, Lazer, Guias, Blog, Empregos, Cursos, Apoiadores, search,
  favourites/Salvos, detail screens, deep-link-ready routes and offline banners
  all continue to work in Portuguese (verified by the suite and on device).

## 18. UI language integration (Phase 15)

The smallest architecture-consistent mechanism was implemented:

* the selector lives on the Explore hub (an app-bar action opening a small
  sheet with **Português / English**, each labelled with its own endonym);
* content language state is the persisted `contentLanguageProvider`, kept
  **separate** from the theme preference; navigation stays deterministic and no
  language-specific URL is invented;
* `MaterialApp.locale` follows the content language through the existing
  `flutter_localizations` delegates (`pt_BR` / `en`), so framework-provided
  strings (accessibility labels, back tooltips, pickers) follow the selection —
  observed on device as `Tab 1 of 4` under EN and `Guia 2 de 4` under PT;
* the few **new** labels go through `AppStrings` (no scattered literals);
* no authentication, accounts, sync or push notifications were added.

## 19. WordPress contract verification (Phase 19)

The WordPress module was **not modified**. Verification was performed against
the same live local stack Stage 4.1 used (Docker WordPress on `:8080`, theme
with `inc/rest-language.php`, Polylang `pt`+`en`), in three layers:

**a) Flutter-side wire matrix** — `tool/stage42_contract_check.py`
(read-only GETs, default `http://127.0.0.1:8080/wp-json/wp/v2`):

| # | Check | Result |
|---|---|---|
| 1 | EN event collection (`?lang=en`: metadata on every record, B2 flags) | PASS (100 items, 100 B2 fallbacks) |
| 2 | EN leisure collection | PASS (100 items) |
| 3 | EN guide collection (B1: real EN only, no replaced PT master) | PASS (2 EN records, PT master excluded) |
| 4 | EN search contract shape | PASS (/wp/v2/search?lang=en hits carry metadata) |
| 5 | EN real translation detail | PASS (`translations.pt` present) |
| 6 | EN B2 fallback detail | PASS (`lang=pt`, `is_fallback=true`) |
| 7 | Wrong-language detail error + recovery metadata | PASS (404 `conexao_rest_language_unavailable`, `data.translations` object) |
| 8 | Invalid lang rejected deterministically | PASS (400 `conexao_rest_invalid_lang`) |
| 9 | Hidden event excluded (detail + both collections) | PASS |
| 10 | Translated taxonomy uses language-appropriate terms | PASS (PT 58 terms / EN 1 term, ids disjoint) |
| 11 | Shared county identity + EN filter works | PASS (32 = 32 identical ids, no `-en` suffix, Dublin filter returns events) |

**11/11 passed.**

**b) Real-server Flutter client tests** —
`test/integration/stage42_wp_contract_test.dart` drives the app's own
`WPClient`/DTOs against the live stack (auto-skipped when it is not running):
EN guide collection parsing with metadata, EN event collection B2 semantics,
wrong-language slug behaviour plus the legacy probe recovering
`translations.en`, and PT/legacy requests returning the Portuguese collection.
**4/4 passed.**

**c) Local-only fixtures** created with the WordPress bootstrap inside the
container (never production) to exercise states the pilot dataset no longer
contained: a linked PT↔EN guide pair (22183/22198 via `pll_save_post_translations`)
and a hidden event (`_event_status=rejected`, 22199). Both were used by the
checks above and are left in the local database so the checks are
reproducible; they are identifiable by the `stage42-` slug prefix and can be
deleted locally without affecting anything else. No production content was
created, modified or deleted.

## 20. Test results (Phase 16)

* `flutter analyze` — **No issues found** (final run, whole project including
  `test/` and `integration_test/`).
* Full suite — **1037 passed, 0 failed** (baseline before the stage: 995).
  The 42 added tests are:

| File | Focus |
|---|---|
| `test/unit/content_language_test.dart` | enum, parsing, cache-key policy, wire translations, `AppStrings` |
| `test/unit/language_meta_test.dart` | DTO parsing (populated / `[]` quirk / malformed / absent), cached round-trip, domain states, every content mapper |
| `test/unit/wp_client_language_test.dart` | `withLanguageQuery` (once, composed, legacy null), `lang` on the wire, `InvalidLanguage` / `LanguageUnavailable` mapping, plain 400/404 unchanged |
| `test/repository/language_cache_test.dart` | PT/EN coexistence, B2 dual representation, per-language eviction, per-language term vocabulary, wrong-language recovery (with and without counterpart), hidden-event probe, translated vs shared filter vocabulary |
| `test/repository/language_migration_test.dart` | genuine v6 → v7 migration preserving PT rows, favourites and freshness; B2 dual representation after migration |
| `test/unit/language_provider_test.dart` | list reset + reload on language switch, pagination reset mid-pagination, language-keyed search index isolation |
| `test/integration/stage42_wp_contract_test.dart` | live-server client integration (see §19b) |

Deterministic unit/repository tests were preferred; no UI-only assertions are
load-bearing for the gates.

## 21. Device / integration status (Phase 17)

Device: **Android emulator `s21_api36`** (API 36, x86_64, KVM-accelerated),
debug build launched with
`WP_API_BASE=http://10.0.2.2:8080/wp-json/wp/v2/` and
`WP_SITE_BASE=http://10.0.2.2` (a debug-only cleartext allowance was added to
the debug manifest; release builds are unaffected).

Verified on device via the app's MCP toolkit (semantic snapshots, taps) and
`NetworkCounter`:

| Step | Result |
|---|---|
| PT app startup | PASS — Home renders PT sections ("Próximos eventos", "Últimas publicações"), PT nav labels, PT content |
| Language selector | PASS — Explore app-bar action opens the "Idioma" sheet with **Português / English** |
| English selection | PASS — labels switch to EN accessibility strings; content requests become EN |
| English Guias | PASS — exactly the two EN records (real translation fixture + source-inherited EN); no PT-only guide, no replaced PT master |
| EN B2 collections | PASS — the EN event list shows PT content only as server-flagged B2 fallback (local dataset is PT-only) |
| Translation action | PASS — with the counterpart URL resolvable, the detail app bar shows the third (translate) action alongside save/share |
| Return to PT | PASS — switching back restores PT labels and PT content |
| `lang` hygiene | PASS — `NetworkCounter`: 0 requests with a duplicated `lang`, both languages exercised |

Two real issues were found **because** of the device run and fixed:

1. the GoRouter was recreated on every `MaterialApp` rebuild, resetting
   navigation when the language changed → now a stable `appRouterProvider`;
2. the counterpart action resolved the full canonical URL, which the app's
   production-hardening rejects for non-default ports (staging/local hosts) →
   it now resolves the server-provided **path** (host-agnostic), which also
   keeps production behaviour unchanged.

Not verified on device in this environment (listed honestly rather than
claimed): dark-mode screenshots, Android back-navigation beyond the detail
screens, and the wrong-language recovery *button* (its repository/error path is
covered by tests; the button requires opening a PT-only slug from an EN state,
which the local dataset only exposes through deep links).

## 22. Performance / network sanity (Phase 18)

* `lang` is added in exactly one place (the transport) and observed exactly
  once per request — asserted by unit tests and by the on-device counter
  (`duplicate_lang_params == 0`).
* Per-language single-flight guards (event sweeps) and the leisure notifier's
  language-aware coalescing key prevent duplicate or cross-language traffic
  when the language changes mid-flight; provider tests assert a single reload
  per switch.
* Pagination does not refetch page 1: the list state is preserved across
  `loadMore`, and a language switch resets to page 1 deliberately (one fetch).
* Offline/cache paths remain deterministic: every read is a pure Drift query
  and the wrong-language probe performs at most one extra request, only after
  an empty language-scoped result.

## 23. Exact files changed

Flutter repository (`abalzan/conexaobr_app`), uncommitted working-tree changes
(no commits and no pushes were made; see §3):

**New files** (18):

```
lib/core/language/
lib/core/language/app_strings.dart
lib/core/language/content_language.dart
lib/core/language/translation_ref.dart
lib/data/dto/language_meta_dto.dart
lib/data/repo/language_recovery.dart
lib/domain/models/content_language_meta.dart
lib/widgets/detail_error_state.dart
lib/widgets/language_counterpart_action.dart
test/integration/
test/integration/stage42_wp_contract_test.dart
test/repository/language_cache_test.dart
test/repository/language_migration_test.dart
test/unit/content_language_test.dart
test/unit/language_meta_test.dart
test/unit/language_provider_test.dart
test/unit/wp_client_language_test.dart
tool/stage42_contract_check.py
```

**Modified files** (84):

```
android/app/src/debug/AndroidManifest.xml
integration_test/perf_helpers.dart
lib/app/app.dart
lib/app/providers.dart
lib/core/constants/app_constants.dart
lib/core/errors/app_error.dart
lib/core/navigation/internal_link_resolver.dart
lib/data/api/wp_client.dart
lib/data/dto/course_provider_dto.dart
lib/data/dto/event_dto.dart
lib/data/dto/event_mapper.dart
lib/data/dto/guide_dto.dart
lib/data/dto/guide_dto.freezed.dart
lib/data/dto/guide_dto.g.dart
lib/data/dto/guide_mapper.dart
lib/data/dto/leisure_dto.dart
lib/data/dto/leisure_mapper.dart
lib/data/dto/opportunity_dto.dart
lib/data/dto/post_dto.dart
lib/data/dto/post_mapper.dart
lib/data/dto/sponsor_dto.dart
lib/data/repo/app_database.dart
lib/data/repo/app_database.g.dart
lib/data/repo/course_provider_repository.dart
lib/data/repo/course_provider_repository_impl.dart
lib/data/repo/event_repository.dart
lib/data/repo/guide_repository.dart
lib/data/repo/leisure_repository.dart
lib/data/repo/leisure_repository_impl.dart
lib/data/repo/opportunity_repository.dart
lib/data/repo/opportunity_repository_impl.dart
lib/data/repo/post_repository.dart
lib/data/repo/sponsor_repository.dart
lib/data/repo/sponsor_repository_impl.dart
lib/data/repo/tables.dart
lib/data/repo/term_repository.dart
lib/domain/models/course_provider.dart
lib/domain/models/event.dart
lib/domain/models/guide.dart
lib/domain/models/leisure.dart
lib/domain/models/opportunity.dart
lib/domain/models/post.dart
lib/domain/models/sponsor.dart
lib/domain/models/taxonomy_term.dart
lib/features/apoiadores/apoiadores_screen.dart
lib/features/blog/post_detail_screen.dart
lib/features/blog/providers/post_providers.dart
lib/features/cursos/cursos_screen.dart
lib/features/empregos/providers/empregos_providers.dart
lib/features/events/event_detail_screen.dart
lib/features/events/providers/event_detail_provider.dart
lib/features/events/providers/events_providers.dart
lib/features/explore/explore_screen.dart
lib/features/guides/guide_detail_screen.dart
lib/features/guides/providers/guides_providers.dart
lib/features/home/providers/home_providers.dart
lib/features/lazer/lazer_detail_screen.dart
lib/features/lazer/providers/lazer_providers.dart
lib/features/saved/snapshot_event.dart
lib/features/saved/snapshot_guide.dart
lib/features/saved/snapshot_leisure.dart
lib/features/saved/snapshot_post.dart
lib/features/search/search_providers.dart
lib/features/search/search_screen.dart
lib/widgets/error_state.dart
test/helpers/fake_event_repository.dart
test/helpers/fake_leisure_repository.dart
test/helpers/fake_opportunity_repositories.dart
test/helpers/fake_post_repository.dart
test/helpers/fake_repositories.dart
test/helpers/fake_stage10_repositories.dart
test/repository/event_migration_test.dart
test/repository/leisure_migration_test.dart
test/repository/leisure_repository_test.dart
test/repository/opportunity_migration_test.dart
test/repository/post_migration_test.dart
test/repository/schema_upgrade_test.dart
test/repository/sponsor_repository_test.dart
test/unit/filter_isolation_test.dart
test/unit/search_cache_completeness_test.dart
test/unit/search_provider_test.dart
test/widget/async_lifecycle_safety_test.dart
test/widget/home_offline_retry_storm_test.dart
test/widget/lazer_state_retention_test.dart
```

Also intentionally modified, for local device testing only:

```
android/app/src/debug/AndroidManifest.xml   # debug-only cleartext allowance
integration_test/perf_helpers.dart          # fake repository signatures
```

Generated code regenerated with `dart run build_runner build`:
`lib/data/dto/guide_dto.freezed.dart`, `lib/data/dto/guide_dto.g.dart`,
`lib/data/repo/app_database.g.dart`.

No migration script files were added to WordPress; the Drift migration lives in
`lib/data/repo/app_database.dart` (`schemaVersion => 7`, `_addLanguageScoping`).

Pre-existing unrelated change present before this stage and left untouched:
`android/build/reports/problems/problems-report.html`.

## 24. Exact files intentionally not changed

* **WordPress module** — `inc/rest-language.php`, the theme, plugins and
  `scripts/stage41-rest-verify.py` are byte-identical (Phase 19 says do not
  modify the module; no contract defect was found).
* **`opportunity_rows` / `contact_page_rows` schema** — agencies/employers and
  pages are outside the seven bilingual contract types; `?lang` was verified to
  be a no-op there, so they stay shared and unscoped.
* **Contact repository/screen** — unchanged for the same reason.
* **Favourites** — user data keyed by `(contentType, slug)`; deliberately not
  language-scoped so saved items survive language switches (their snapshots
  report `legacy` metadata).
* **Router route catalog** (`lib/core/navigation/app_route_paths.dart`, route
  paths inside `router.dart`) — no new routing system; only the router
  *instance* was made stable.
* **Design system** — no new colours/spacing/typography; the two new widgets
  reuse shared components and `AppSpacing`.
* **Production endpoints** — `apiBaseUrl`/`siteBaseUrl` defaults are unchanged;
  the `String.fromEnvironment` override only affects explicitly defined debug
  builds.

## 25. Known limitations

1. **Portuguese UI chrome strings are not translated in this stage.** The app's
   existing 178 literals (screen titles, buttons, filter-sheet labels, empty
   states, date formatting) remain Portuguese under EN; what follows the
   content language today is the served content, the framework-provided
   strings (via `MaterialApp.locale`), and every label newly introduced by this
   stage (through `AppStrings`). This is a deliberate scope decision ("smallest
   architecture-consistent mechanism", no navigation redesign) and it is
   carried into Stage 4.3 as a first-class prerequisite.
2. **Remote-HEAD verification is limited** by the missing GitHub credentials in
   this environment (§3); local HEAD matched the last known `origin/master`.
3. **Device verification gaps** (honest list): dark-mode screenshots, Android
   back-navigation beyond the detail screens, and the wrong-language recovery
   button were not exercised on the emulator; their code paths are covered by
   repository/error tests and the live-server contract tests.
4. **Local-stack URL shape.** On the local Docker stack the server emits
   `http://localhost:8080/...` canonical URLs, which the app's
   production-hardening (default ports only) classifies as external unless the
   site base is overridden; production canonical URLs (`https://conexaobr.ie`)
   are unaffected. The counterpart action now resolves the provided *path*, so
   behaviour is identical in production and testable locally.
5. **Production EN content does not exist yet** (the Stage 3.x content rollout
   is local). Against production, EN mode therefore returns the server's
   current behaviour; the EN experience is fully verifiable only once 4.3
   deploys the module and content — the app-side contract already matches it.

## 26. Stage 4.3 prerequisites

1. **Deploy the Stage 4.1 module + EN content to production**, then re-run
   `tool/stage42_contract_check.py --base https://conexaobr.ie/wp-json/wp/v2`
   and the live-server client test against production to confirm the same
   shapes on the deployed stack.
2. **UI chrome localization** (ARB or the project's chosen mechanism) for the
   ~178 Portuguese literals, including date formatting, so an EN user sees an
   EN interface. `AppStrings` is the ready extension point for new labels.
3. **Decide the `pages` REST surface** (Stage 4.1 §26.2) so Contact and other
   static pages can follow the language like the seven contract types.
4. **Production menu, hreflang, metadata and sitemap reconciliation** —
   unchanged carry-overs from Stage 3.3/4.1.
5. **English editorial backlog** (Stage 3.3 §7): B1 → real translations decides
   what the EN app can show; the REST contract (and therefore the app) reflects
   data, not intent.
6. Optional: provide CI credentials for live remote verification so §3's
   limitation disappears.

## 27. Final acceptance matrix (Phase 20 hard gates)

| # | Hard gate (BLOCKED if…) | Result | Evidence |
|---|---|---|---|
| 1 | Flutter repository inaccessible | **PASS** | complete local clone used throughout; live remote read unavailable (§3) and recorded |
| 2 | Portuguese functionality regresses | **PASS** | 995 baseline tests green before EN assertions; 1037 green at the end; PT device flows verified |
| 3 | PT and EN cache entries overwrite one another | **PASS** | `{id, lang}` PKs + per-language eviction/keys; migration and repository tests |
| 4 | PT content renders under EN when a real EN response exists | **PASS** | every read scoped by view; EN guide list contained only EN records on device |
| 5 | B2 fallback treated as a real EN translation | **PASS** | distinct domain states; B2 has empty `translations` → no counterpart action; wire check 6 |
| 6 | B1 content appears where the server excludes it | **PASS** | EN guide collection = 2 EN records only; B1 wrong-language path returns the typed error |
| 7 | PT master + EN translation shown as two items in one EN collection | **PASS** | server replacement + view-scoped reads/pagination |
| 8 | Hidden Event appears | **PASS** | wire check 9 (detail + both collections); probe checks visibility first |
| 9 | Event identity corrupted | **PASS** | identity meta untouched; no cross-language merging; upserts keyed `(id, lang)` |
| 10 | Lazer identity corrupted | **PASS** | same treatment; no UUID or slug rewriting |
| 11 | Source-inherited EN Event duplicated | **PASS** | one id/one row; single appearance in the EN collection |
| 12 | Shared county/town identity altered | **PASS** | identical ids in both languages, no suffixed terms created (wire check 11) |
| 13 | Language inferred from title/body text | **PASS** | no text inspection anywhere; only explicit metadata and the frozen taxonomy policy |
| 14 | Widgets construct REST language URLs manually | **PASS** | `lang` exists only in the transport; widgets read the provider/`AppStrings` |
| 15 | Drift migration deletes/loses PT data | **PASS** | v6→v7 and v1→v7 tests preserve rows, favourites and freshness |
| 16 | Pagination mixes languages | **PASS** | per-language page state/scoping; mid-pagination switch test |
| 17 | Search mixes languages | **PASS** | language-keyed index family; isolation test |
| 18 | Production touched | **PASS** | GET-only local verification, no deploy/upload/release build, WordPress files byte-identical |

## 28. Final classification

**ENGLISH STAGE 4.2 — PASS**

The Flutter app is bilingual at the data, cache, provider, pagination, filter,
search and navigation layers, consuming the frozen Stage 4.1 contract exactly,
with Portuguese preserved byte-for-byte in behaviour and every Phase 20 gate
passing. The only limitation carried forward is UI-chrome translation (§25.1),
which is explicitly out of this stage's deliverable and is recorded as the
first Stage 4.3 prerequisite rather than hidden inside the PASS.
