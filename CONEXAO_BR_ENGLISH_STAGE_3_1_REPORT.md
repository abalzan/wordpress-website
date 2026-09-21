# CONEXAO_BR_ENGLISH_STAGE_3_1_REPORT.md

Stage 3.1 of the approved English-support architecture
(`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`, audit
`CONEXAO_BR_ENGLISH_SUPPORT_AUDIT.md`, `CONEXAO_BR_ENGLISH_STAGE_1_REPORT.md`,
`CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md`): establish the **safe bilingual content
framework** — B2 fallback rendering, English taxonomy-localization foundation,
key static-page translation pilot, language-aware archives and search,
translation-state handling, and the SEO/sitemap rules for fallback vs real
translations.

Everything is **local/staging only**. No production change of any kind.

---

## 1. Status

**ENGLISH STAGE 3.1 — PASS WITH LIMITATION**

The B2 infrastructure, the bilingual archive/search behavior, the taxonomy
identity guarantees, the pilot translated pages, the canonical/hreflang rules,
the sitemap rules and the caches all pass their gates. One requirement is
**BLOCKED in this environment**: `/en/` must serve the English homepage at the
bare home URL. The EN homepage translation exists and renders (front-page
template, English content) at `/en/home/`, but `/en/` 301-redirects there
because Polylang's per-language static-front-page mapping is not registered in
this local installation (`pll_home_url('en')` resolves to `/en/home/`). The
Portuguese homepage is unaffected. Fix path and evidence in §12.

## 2. Baseline (Phase 1)

| Item | Value |
|---|---|
| Branch | `master` |
| HEAD (before and after) | `8ac39b1094fce91eaad78a1490382d7bfaca4731` |
| Working tree at start | clean (no modified/untracked files) |
| WordPress | 7.1 (Docker) |
| Polylang | **3.8.9 Free**, active (checksums verified in Stage 2) |
| Site locale | `WPLANG = pt_BR` |
| Languages | `pt` (default) + `en` |
| URL mode | `force_lang = 1` (directory), `hide_default = true`, `redirect_lang = false` |
| DB checkpoint | full `mysqldump` taken **before** any language/content change: `/tmp/stage31-baseline.sql` (16 MB, inside the `db` container; not committed) |
| Production | untouched, nothing uploaded |

Confirmed: no production change occurred (no deploy, no remote settings, no
WordPress.com interaction).

## 3. B2 implementation (Phases 2–3)

B2 = "Portuguese record served under the English URL with English chrome, an
English notice and a canonical pointing at the Portuguese record".

Implemented in `inc/polylang.php`:

- `conexao_b2_post_types()` — **allowlist**: `event`, `leisure`, `sponsor`,
  `course_provider`, `job`.
- `conexao_b2_page_allowlist()` / `conexao_is_b2_page()` — static-page
  allowlist, **deliberately empty** in this stage (every page stays B1).
- `conexao_is_b2_post_type()` — type predicate.
- `conexao_should_render_b2_fallback()` — the single decision point; true only
  when Polylang is active, the requested language is `en`, the resolved record
  is a B2 type (or allowlisted page), published, has **no** EN translation and
  is not an English record; for events it additionally requires the public
  `_event_status` gate to pass (published or legacy no-status).
- `conexao_b2_fallback_notice()` — renders the approved English notice
  (`This content is displayed in Portuguese.`, `lang="en"`, `role="note"`).
- `conexao_is_language_fallback()` — narrowed to B2-eligible records only, so
  B1 types can never report a fallback state.

Redirect ownership:

- `conexao_polylang_language_redirect_is_temporary()` (`pll_check_canonical_url`,
  priority 20) returns `false` (no canonical redirect) for B2 singles and for
  B2 post-type archives under `/en/`, and keeps the 302 for everything else.
- `conexao_seo_missing_translation_redirect()` (`inc/seo.php`,
  `template_redirect` priority 6) skips its 302 when the B2 renderer owns the
  request.

Templates render the notice: `single.php`, `single-leisure.php`,
`single-sponsor.php` (the notice call is a no-op on PT requests). Styling uses
the existing amber tokens in `assets/css/main.css`
(`.language-fallback-notice`) with dark-mode overrides in
`assets/css/dark-mode.css` — no page-specific hacks, no new colour system.

## 4. B1 preservation (Phases 2–3)

B1 (Guides, Blog, key narrative static pages) is **untouched**: no B2 rendering,
no fake English page, the approved 302 policy stands.

| URL | Result |
|---|---|
| `/en/guias/inverno-irlanda-depressao-sazonal-saude-mental/` | 302 → `/guias/inverno-irlanda-depressao-sazonal-saude-mental/` |
| `/en/sobre-nos/` | 302 → `/sobre-nos/` |
| `/en/contato/` | 302 → `/contato/` |
| `/en/blog/` | 302 → `/blog/` |
| `/en/empregos/` (landing **page**, not the CPT) | 302 → `/empregos/` |

No B1 type was silently converted to B2.


## 5. Event B2 behavior (Phase 4)

- `/en/eventos/{pt-slug}/` renders the Portuguese event with EN chrome, the
  English notice, and `<link rel="canonical">` → the Portuguese permalink.
- `/en/eventos/` lists the same upcoming set as `/eventos/` (180 event cards on
  page 1 in both languages).
- The event archive path is language-curated in
  `Conexao_Event_Query::is_in_current_language()` (event runtime): in EN, PT
  records with **no** EN translation are included; records that have an EN
  translation only appear in their own language, so no event appears twice.
- `?cidade=`/`?county=`/`?categoria=` filters work on both sides:
  `/eventos/?cidade=dublin` → 200 and `/en/eventos/?cidade=dublin` → 200.

## 6. Event identity verification (Phase 4)

| Check | Result |
|---|---|
| Duplicate `(_event_source, _event_source_id)` identities | **0** |
| Duplicate `_event_export_uuid` values | **0** |
| Duplicate `_leisure_export_uuid` values | **0** |
| Event meta modified by the B2 code path | none (B2 rendering is read-only) |
| Second Event created for any EN request | none (no HTTP path creates posts) |

The importer is WP-CLI-only (`conexao-event-importer` registers no REST/AJAX
route and no front-end hook), so an HTTP `/en/` request cannot be treated as a
new import source. No importer identity logic was modified.

## 7. Event status verification (Phase 4)

- Distribution before and after: `published = 1493`, `expired = 404`,
  `source_not_found = 335` (total 2232).
- EN archive sampling: **0 of 739** hidden-status event IDs appear in
  `/en/eventos/page/1..5/`.
- EN search: after the status branch was corrected (see §16) a search for a
  hidden event's topic returns **0** results in EN; a hidden event's EN URL does
  not render (it falls through to the pre-existing WordPress 404-guess 301 → PT
  → 404; the record is never displayed and never listed).
- `test-event-language-gate.php`: 16/16 PASS.

## 8. Lazer B2 behavior (Phase 6)

- `/en/lazer/queen-maeves-trail-knocknarea/` (internal-page record) → **200**,
  English notice present, canonical → `/lazer/queen-maeves-trail-knocknarea/`.
- Externally classified records keep the existing architecture:
  `/en/lazer/dwyer-mcallister-cottage/` → **302** to the official
  `heritageireland.ie` URL (the Lazer external redirect is unchanged).
- All Lazer meta (UUID, official/Discover Ireland URLs, media, attributes, map
  action, related-events logic) is read-only under B2 — nothing is written.
- `/en/lazer/` lists the same 199 cards as `/lazer/`.

## 9. Directory B2 behavior (Phase 7)

- Sponsors: `/en/apoiadores/{slug}/` → 200 + notice + canonical PT; the archive
  keeps the editor-curated ordering (`conexao_sponsor_archive_ordered_ids()`
  now requests both languages on EN so the ordered ID list is not language-empty).
- Courses: `/en/cursos/apprenticeship-ie/` → 200 + notice; provider cards and
  external CTAs unchanged.
- Employment: `/en/empregos/oportunidades/` (a `job` record) → 200 + notice;
  the public-sector decision and CTA labels are untouched.
- No new routes were created; no CTA label changed; no external behaviour changed.

## 10. Taxonomy localization (Phase 8)

Identity guarantees verified:

| Taxonomy | Terms | Duplicate slugs | `-pt`/`-en` suffixed slugs |
|---|---|---|---|
| `conexao_category` | 59 | 0 | 0 |
| `conexao_county` | 32 | 0 | 0 |
| `conexao_town` | 250 | 0 | 0 |

Every shared term keeps exactly one language assignment; no Dublin/Cork/…
duplicate was created; no slug was changed.

Filter behavior (the explicit Phase 8 test):

| URL | Result |
|---|---|
| `/lazer/?county=dublin` | 200 (202 cards) |
| `/en/lazer/?county=dublin` | **200** (202 cards — no longer 302 to PT) |
| `/eventos/?cidade=dublin` | 200 |
| `/en/eventos/?cidade=dublin` | **200** |

EN filter URLs now render because B2 post-type archives under `/en/` are no
longer bounced by the language-mismatch redirect (§3); the filter URL structure
was not redesigned.

Pilot EN term-name (workflow evidence): a linked EN term `Nature`
(id 2875, slug `nature`) was created for the PT term `natureza` (id 1419) via
`pll_set_term_language()` + `pll_save_term_translations()`. Result: PT-slug
filtering is unaffected (`/lazer/?categoria=natureza` and
`/en/lazer/?categoria=natureza` both 200 with 192 cards), no suffixed slug was
produced, and the PT term identity/slug is unchanged. Filtering by the
**English** slug (`?categoria=nature`) returns 0 because B2 posts carry the PT
term — that requires real EN content and is a Stage 3.2 item (§26).

## 11. Key static-page translations (Phase 9)

Pilot set (human-authored English, Gutenberg blocks preserved, no machine
translation), created and linked with Polylang:

| PT page | EN page | EN URL | Result |
|---|---|---|---|
| `inicio` (9) | `Home` (22150) | `/en/home/` | 200 (front-page template, English content) |
| `contato` (12) | `Contact` (22151) | `/en/contact/` | 200 |
| `sobre-nos` (11) | `About Us` (22152) | `/en/about-us/` | 200 |

PT originals unchanged (`/`, `/contato/`, `/sobre-nos/` all 200, PT content).
Media, blocks, internal links, page identity and Polylang relationships intact.

**Slug note (factual constraint):** WordPress requires a globally unique
`post_name` per post type, so an EN page cannot reuse the PT slug. The EN pages
therefore use English slugs (`home`, `contact`, `about-us`) — Polylang's
translated-slug model — instead of the literal `/en/sobre-nos/` shape. This is
reported, not hidden: it changes no Portuguese URL.

Real-translation SEO behavior was verified on the About pilot before it was
re-linked: EN canonical self-referential, hreflang `en` + `pt-BR` +
`x-default`, PT page mirrored, switcher linking both ways. The
homepage/About/Contact trio is the only translated content in this stage
(deliberately no legal/county bulk translation).

## 12. Homepage translation (Phase 10)

| Requirement | Result |
|---|---|
| EN homepage exists and renders | **yes** (`/en/home/`, 200, `front-page` template, English body) |
| `/en/` renders the English homepage | **BLOCKED** — `/en/` 301 → `/en/home/` |
| Portuguese `/` unchanged | **PASS** (200, `front-page`, PT hero, canonical `/`) |
| Homepage transients language-separated | **PASS** (§17) |
| Language switcher `/` ↔ `/en/` | **PASS** (rows resolve through `pll_home_url()` per language) |

Root cause (measured, not assumed): `pll_home_url('en')` itself returns
`http://localhost:8080/en/home/`, i.e. Polylang does not map the linked EN page
to the EN home path in this local installation, so the canonical redirect points
at that slugged URL. A theme-side canonical suppression cannot help, and the
resolved language record carries no per-language `page_on_front`. **Fix path
(configuration, not code):** set the static front page for English in
Polylang → Languages → English → *Static front page*, and mirror the mapping on
production. Until then this entry is **BLOCKED**; the task's stop-condition #8
is **not** triggered (the Portuguese homepage is untouched).

## 13. Language switcher behavior (Phase 10)

- B2 single under `/en/`: **EN is marked current** (`aria-current`, `lang="en"`)
  and the **PT** row links to the canonical Portuguese permalink.
- PT page: PT current; EN row links to the EN counterpart once a real
  translation exists, otherwise to the EN archive (Stage 2 policy retained).
- EN archive: EN current; PT row switches to the PT archive.
- No fake EN detail URLs are produced (`pll_get_post()` stays the source of
  truth for real translations).

## 14. SEO / canonical / hreflang (Phase 11)

`inc/seo.php` remains the single SEO owner.

| Case | Canonical | hreflang | og:locale / inLanguage | html lang |
|---|---|---|---|---|
| Real EN translation (pilots) | self (EN URL) | `en` + `pt-BR` + `x-default` | `en_US` | `en-US` |
| B2 fallback (5 types) | **PT permalink** | `x-default` only (record's own URL) | `en_US` (shell) | `en-US` (shell) |
| B1 (guide/blog/page) | no page (302) | — | — | — |

Conflict removal: on B2 pages Jetpack's Open Graph module emitted a second
`og:locale=pt_BR` (detected record language) beside the theme's correct
`og:locale=en_US`. Jetpack OG output is now disabled **only** on B2 fallback
pages (`jetpack_enable_open_graph` filter in `inc/seo.php`); the theme's full
OG/Twitter set keeps rendering. Verified: B2 page has exactly one `og:locale`
(`en_US`); PT pages keep both (both `pt_BR`, no conflict).

The English shell locale is applied through the Stage 1 extension point
(`conexao_current_locale` filter plus a `locale` filter for
`language_attributes()`), scoped strictly to the B2 fallback state; every other
request keeps Polylang's own locale resolution.

## 15. Sitemap behavior (Phase 12)

Theme sitemap (intended canonical producer) now:

- emits the Portuguese URL set as before;
- emits `<xhtml:link rel="alternate">` **only** for real translation pairs;
- adds an **EN pass**: real EN records get their own indexable EN `<url>`;
- **excludes** B2 fallback URLs and B1 redirect-only URLs from the EN `<url>`
  inventory (no EN record exists, so they cannot appear);
- keeps one home entry carrying the `/` ↔ `/en/` pair.

Measured with Jetpack temporarily deactivated to expose the theme output:
1711 `<url>` entries, **0** B2 fallback URLs, 1 `/en/` loc (the home alternate).
Jetpack was re-activated immediately afterwards.

**Still requires production verification:** locally Jetpack (16.2-a.3) owns
`/sitemap.xml` and shadows the theme producer. On production the same
reconciliation must be confirmed (Jetpack sitemap disabled in favour of the
theme producer, or Jetpack's EN output validated) — a WordPress.com-side check
that cannot be performed from this environment.

## 16. Search behavior (Phase 13)

Implemented with no search plugin and no search redesign:

- **PT search**: unchanged (all PT content).
- **EN search**: EN content of any type **plus** B2-type PT records
  (`event`, `leisure`, `sponsor`, `course_provider`, `job`) with no EN
  translation.
- **B1 exclusion**: PT guides/blog/pages never appear in EN search
  (`/en/?s=blog` → 0 results).
- **Event status gate**: hidden statuses never appear in EN search. An early
  draft of the SQL branch made the status test a tautology for events;
  corrected, after which the hidden-event search returns 0 in EN.
- **No duplicates**: language scope and B2 scope are one predicate, so a record
  cannot be returned twice.

Verified: `/en/?s=dublin` → 60 B2 cards; `/en/?s=evento` → B2 records only;
`/en/?s=blog` → 0; PT counterparts unchanged.

Known pre-existing gap (documented, not changed): PT search (`post_type=any`)
sits outside the event runtime's `pre_get_posts` gate, so a hidden event can
still match PT search. Changing it would alter unrelated public query logic,
which this stage forbids; recorded in §25/§26.

## 17. Cache behavior (Phase 14)

The Stage 2 language-keyed model is retained (no new caching layer):

- transients are language-suffixed (`_transient_conexao_home_latest_pt`,
  `_transient_conexao_home_popular_pt`, `_transient_conexao_home_sponsors_pt`);
- the event ID list is keyed by local date **and** language
  (`_transient_conexao_event_upcoming_20260921_pt`), and `flush_cache()` clears
  every language variant;
- the sponsor ordered-ID query is language-aware on EN requests so the B2
  archive ordering stays stable;
- flush helpers (`conexao_flush_language_cache()`,
  `conexao_flush_language_object_cache()`) clear every language variant on save.

Warm-up isolation: after flushing all transients, requesting the PT home then
`/en/` produced correct per-language output with no cross-language leakage
(same for archives): warming one language does not serve the other.

## 18. Editorial state (Phase 15)

**NOT IMPLEMENTED in this stage — recorded as a Stage 3.2 prerequisite.**

Polylang Free has no editorial workflow, and no custom `_translation_outdated`
mechanism or admin indicator was added: before the content catalogue exists such
an indicator would have nothing to show. The minimum state visibility the stage
asks for is already derivable from data present today
(`pll_get_post_translations()` + `pll_get_post_language()`), which is what the
pilots used. A small indicator built on `conexao-admin-ux` is scoped for Stage
3.2 together with the translation queue.

## 19. REST limitation (Phase 16)

Unchanged and still unfulfilled: Polylang Free provides no collection `?lang=`
filtering and no `_links.translations`, so the Flutter bilingual REST contract is
**not** implemented in this stage. No Flutter change, no global REST redesign.
Remaining requirement: §26 item 7.


## 20. Exact files changed

Modified (9 files, +729 / −7 lines; `git diff --check` reports no whitespace
errors; no DB dumps, no vendor files, no build artifacts):

1. `wp-content/themes/conexao-br-irlanda/inc/polylang.php` — B2 decision helpers,
   B2 notice renderer, requested-language-aware switcher and switcher URLs, B2
   shell locale, B2-aware redirect ownership, language-home front-page rule.
2. `wp-content/themes/conexao-br-irlanda/inc/seo.php` — B2 bypass of the
   missing-translation 302, EN pass in the sitemap, Jetpack-OG suppression on B2
   pages.
3. `wp-content/themes/conexao-br-irlanda/functions.php` — B2 archive widening,
   B2 search widening plus the SQL predicate (with the event-status gate),
   sponsor ordered-ID language awareness, event archive language widening.
4. `wp-content/plugins/conexao-event-runtime/includes/class-event-query.php` —
   B2 membership rule in `is_in_current_language()`.
5. `wp-content/themes/conexao-br-irlanda/single.php` — notice call.
6. `wp-content/themes/conexao-br-irlanda/single-leisure.php` — notice call.
7. `wp-content/themes/conexao-br-irlanda/single-sponsor.php` — notice call.
8. `wp-content/themes/conexao-br-irlanda/assets/css/main.css` — notice styles.
9. `wp-content/themes/conexao-br-irlanda/assets/css/dark-mode.css` — notice
   dark-mode tokens.

Created: `CONEXAO_BR_ENGLISH_STAGE_3_1_REPORT.md` (this file).

Local database only (not in the repository, not in git): EN pages 22150/22151/22152,
EN `conexao_category` term 2875, language term meta, transients.

## 21. Exact files intentionally not changed

- `.htaccess` and every legacy EN→PT redirect entry (the redirect map body in
  `inc/seo.php` is untouched).
- CPT/taxonomy registration, rewrite slugs, `conexao_town` registration.
- Event importer identity/dedup logic and export JSON (no `"lang"` field added).
- Lazer/Sponsor migration export–import semantics and fixtures.
- `inc/i18n.php` (the Stage 1 extension point was consumed, not edited),
  `inc/search.php`, `inc/post-views.php`, `inc/empregos-landing.php`,
  `inc/job-resources.php`, `inc/recruitment-agencies.php`,
  `inc/permit-employers.php`, `inc/employment-opportunities.php`.
- All templates except the three single templates above; `template-parts/`
  (including `language-switcher.php`) needed no change; `header.php`,
  `footer.php`, `front-page.php`, `archive.php`, `page.php`, `home.php`.
- `compose.yaml`, `scripts/`, `docs/`, Jetpack configuration.
- REST endpoints and everything Flutter-facing.

## 22. Tests and exact results (Phase 17)

PHP syntax: every changed file passes `php -l`.

Theme tests (`wp-content/themes/conexao-br-irlanda/tests/`):

| Test | Result |
|---|---|
| `test-i18n-foundation.php` | 29 passed, 0 failed |
| `test-polylang-foundation.php` | 57 passed, 0 failed |
| `test-recurrence-i18n.php` | 12 passed, 0 failed |
| `test-leisure-card-map-action.php` | 44 passed, 0 failed |
| `test-leisure-related-events.php` | 19 passed, 0 failed |
| `test-guide-breadcrumb-filter.php` | 36 passed, 0 failed |
| `test-leisure-attribute-normalization.php` | 22 passed, 0 failed |
| `test-leisure-multiselect-filters.php` | 63 passed, 0 failed |
| `test-sponsor-archive-ordering.php` | 15 passed, 0 failed |
| `test-event-location-filters.php` | 45 passed, **1 failed (pre-existing**, reproduced unchanged since Stage 1) |

Plugin tests:

| Test | Result |
|---|---|
| `conexao-event-runtime/tests/test-event-language-gate.php` | 16 passed, 0 failed |
| `conexao-event-runtime/tests/test-event-query.php` | 41 passed, 0 failed |
| `conexao-event-runtime/tests/test-event-recurrence.php` | 90 passed, 0 failed |
| `conexao-admin-ux/tests/test-recurrence-admin.php` | 60 passed, 0 failed |

Stage 3.1 checks (HTTP matrix + database assertions, all local):

| Check | Result |
|---|---|
| PT URLs (archives, singles, pages, filters) | all 200, no redirect |
| `/en/` routes | archives 200; B2 singles 200; B1 302 |
| B2 single render + notice | 5/5 types, notice exactly once, absent on PT |
| B2 canonical | PT permalink on all 5 types |
| B2 hreflang | `x-default` only |
| B2 archives vs PT archives | eventos 180/180, lazer 199/199, cursos provider cards, apoiadores order intact |
| Filters | PT and EN both 200 with identical card counts |
| Event identity | 0 duplicate source identities, 0 duplicate UUIDs |
| Event status | 0/739 hidden IDs in EN archive pages 1–5; EN hidden search 0 |
| Taxonomy | 0 duplicate slugs, 0 suffixed slugs, every term language-assigned |
| Sitemap (theme producer) | 1711 `<url>`, 0 B2 fallback URLs |
| Search | PT unchanged; EN = B2 only; B1 excluded; hidden excluded |
| Cache | language-suffixed transients; no cross-language leakage |

Browser/device checks: not run (§24).

## 23. Production safety (Phase 18)

- No upload of Polylang or translated content; no production locale, redirect,
  sitemap, Jetpack or WordPress.com setting touched.
- No production Event/Lazer content created or modified.
- The database checkpoint (§2) is local and untracked.
- Everything reproduces locally with `docker compose up -d` plus the documented
  data operations (page/term creation, language assignment, translation links).

## 24. Browser/device status

**NOT TESTABLE — no browser tooling available.**

No browser or device emulation exists in this environment. All verification was
HTTP (status codes, redirects, headers, HTML) and database assertions.
Dark-mode rules were reviewed against the existing token system but not visually
validated; responsive structure was not device-tested. No browser-based claim is
made anywhere in this report.


## 25. Known limitations

1. **`/en/` serves `/en/home/`** (301) — §12; needs the Polylang per-language
   static-front-page mapping (configuration, not code).
2. **EN page slugs differ from PT slugs** (`home`, `contact`, `about-us`) —
   forced by WordPress slug uniqueness; no PT URL changed.
3. **English-slug taxonomy filtering returns 0** for B2 PT records — linked EN
   terms exist but B2 posts carry PT terms; requires real EN content (Stage 3.2).
4. **B2 chrome wording** is limited by the curated Stage 1 `en_US` catalog: some
   chrome labels still fall back to Portuguese on EN pages. This is identical on
   real EN archive pages, so it is a catalog gap, not a B2 defect.
5. **Jetpack shadows the theme sitemap locally** — production reconciliation
   outstanding (§15).
6. **PT search (`post_type=any`) does not apply the event status gate** —
   pre-existing, unrelated to B2, deliberately left untouched (§16).
7. **Editorial translation state not implemented** — §18.
8. **Polylang Pro absent** (no licence): no Pro translation editor and no
   outdated-translation workflow.
9. **Pre-existing test failure** in `test-event-location-filters.php` (1 test).
10. **Hidden-event EN URLs reach a 301 via WordPress' 404-guess** (the PT target
    404s); the record is never rendered and never listed. Pre-existing core
    behaviour, unchanged by this stage.

## 26. Stage 3.2 prerequisites

1. Register the EN static front-page mapping so `/en/` serves the English
   homepage (§12), then re-verify the switcher `/` ↔ `/en/`.
2. Add the remaining key static pages (`empregos` landing, `irlanda`, county
   pages, legal pages) with human-authored content and retire the corresponding
   B1 302s.
3. Controlled content migration (Guides/Blog/Event/Lazer/Sponsor/Course) using
   the framework proven here — one identity per record, linked translations only.
4. Decide the contents of `conexao_b2_page_allowlist()` for the approved
   county/directory pages.
5. Complete EN taxonomy names for the terms actually used by EN filters and
   settle the filter-slug contract once real EN records exist.
6. Source-language detection for imported events plus the additive export JSON
   `lang` field (Phase 5 documentation item).
7. REST contract for Flutter (Polylang Pro, or a theme-owned `lang`/
   `translations` implementation).
8. Editorial workflow tooling (`_translation_outdated` in `conexao-admin-ux`, or
   the Pro budget decision).
9. Production deployment plan: Polylang ZIP, language and front-page settings,
   locale/date-format settings, cache flush, Jetpack sitemap reconciliation.

## 27. Final acceptance matrix

| Item | Result | Evidence |
|---|---|---|
| B2 fallback | **PASS** | 5/5 types render with notice; canonical → PT; single `x-default` alternate (§3, §5, §8, §9) |
| B1 behavior | **PASS** | guide/blog/page 302 to PT, no fake page (§4) |
| Event identity | **PASS** | 0 duplicate identities/UUIDs; no HTTP write path (§6) |
| Event status gate | **PASS** | 0/739 hidden in EN archive; EN hidden search 0; gate test 16/16 (§7) |
| Lazer identity | **PASS** | 0 duplicate UUIDs; external classification unchanged; internal B2 ok (§8) |
| Taxonomy localization | **PASS** (foundation) | no duplication/suffixing; EN+PT filters 200; EN term-name pilot proven; EN-slug filtering deferred (§10) |
| Static page translation | **PASS** (pilot) | 3 EN pages linked and rendering; PT originals unchanged (§11) |
| Homepage | **BLOCKED** | EN homepage renders at `/en/home/`; `/en/` 301s there (Polylang front-page mapping); PT `/` unchanged (§12) |
| Language switcher | **PASS** | EN current on B2 pages; PT links to the canonical PT permalink (§13) |
| Canonical | **PASS** | EN self for real translations; PT for B2 (§14) |
| hreflang | **PASS** | real pairs only; `x-default` for B2 (§14) |
| Sitemap | **PASS** (local) | theme producer emits no B2 fallback URL; production Jetpack check outstanding (§15) |
| Search | **PASS** | PT unchanged; EN = B2 only; B1 excluded; hidden excluded (§16) |
| Cache | **PASS** | language-keyed transients/object cache; no cross-language leakage (§17) |
| Portuguese regression | **PASS** | PT URLs unchanged; PT content untouched; theme tests green (§22) |
| Browser/device | **NOT_TESTABLE** | no browser tooling (§24) |
| Editorial state | **NOT_NEEDED** / deferred | explicitly deferred to Stage 3.2 (§18) |
| REST | **NOT_NEEDED** in this stage | unchanged by design (§19) |

## 28. Final classification

**ENGLISH STAGE 3.1 — PASS WITH LIMITATION**

The safe bilingual content framework is in place and verified: B2 fallback for
the approved types, B1 preserved, Event/Lazer identity and the `_event_status`
gate intact, taxonomy identity shared with no duplication, a proven page and term
translation workflow, language-aware archives and search, and canonical/hreflang
correctness owned by a single SEO producer.

The single blocked item is environment/config-dependent: `/en/` must serve the
English homepage at the bare home URL, which requires registering the English
static front page in Polylang (§12). No critical stop condition was triggered:
no duplicate Event/Lazer identity, no PT URL change, no contradictory
canonical/hreflang, no bypassed status gate, no taxonomy duplication, no
cache cross-contamination, no redirect collision, and the Portuguese homepage is
untouched.


