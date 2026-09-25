# CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md

Stage 2 of the approved English-support architecture
(`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`, audit
`CONEXAO_BR_ENGLISH_SUPPORT_AUDIT.md`, foundation
`CONEXAO_BR_ENGLISH_STAGE_1_REPORT.md`): introduce **Polylang** as the
multilingual mechanism, make **Portuguese the default language** and English
available under **`/en/`**, without changing a single Portuguese URL, identity
field or SEO owner.

Everything in this stage is **local / staging only**. Nothing was uploaded or
applied to production.

---

## 1. Status

**ENGLISH STAGE 2 — PASS**

All critical gates are green (see §27): Portuguese URLs unchanged, `/en/`
routing live, locale context wired through the Stage 1 extension point, Event
identity provably safe under linked translations, `_event_status` gate
unaffected, Lazer UUID integrity intact, taxonomy identity shared, canonical +
hreflang owned by the theme, caches separated per language, redirects
collision-free.

Scope-limited items are reported explicitly instead of being worked around:

- **B2 fallback rendering is deferred to Stage 3.** Until English content
  exists, an untranslated `/en/{record}/` URL is answered with the approved
  **302** to its Portuguese URL (never 301, never a fake page). See §8, §15,
  §25, §26.
- **Production-only checks are environment-dependent** (WordPress.com Jetpack
  sitemap, plan support for the Polylang ZIP, final PHP version): marked
  NOT_TESTABLE, not defects (§16, §26).
- **Browser/device testing: NOT TESTABLE — no browser tooling available** (§22).

## 2. Polylang version and installation result

| Item | Value |
|---|---|
| Plugin | **Polylang 3.8.9 (Free)** — `WP SYNTEX` |
| Source | `https://downloads.wordpress.org/plugin/polylang.3.8.9.zip` (WordPress.org API), installed with `wp plugin install polylang --version=3.8.9` |
| Integrity | `wp plugin verify-checksums polylang` → **“Success: Verified 1 of 1 plugins.”** |
| Declared requirements | Requires PHP 7.4+, WordPress 6.5+, tested up to 7.1.1 |
| Observed locally | WordPress **7.1**, PHP **8.3.33**, MySQL 9.7.1 (Docker), single site |
| Activation | `wp plugin activate polylang` — clean: **no PHP warnings, notices, deprecations or fatals from Polylang** |
| Install location | `/var/www/html/wp-content/plugins/polylang` (Docker volume) — **not** vendored into this repository |
| Pro | **Not installed.** No license is available in the repository/team context, and Free covers the Stage 2 scope (languages, `/en/` routing, translation linking, switcher, locale context) |

Notes:

- The only PHP notices seen around plugin installation/activation come from
  **WP-CLI's own bundled Symfony Finder** (`phar:///usr/local/bin/wp/...`) under
  PHP 8.3 — pre-existing tooling noise, unrelated to Polylang.
- Polylang resolves its request language from the request context; CLI-only
  invocations that set neither `HTTP_HOST` nor `REQUEST_URI` log one notice
  (`polylang/src/functions.php:81`). The Stage 2 test scripts set a deterministic
  request context to avoid it; the public site is unaffected.

## 3. Baseline / checkpoint

Recorded **before** any multilingual state existed:

| Item | Value |
|---|---|
| Branch | `master` |
| HEAD | `ef7858ce74ec9a702b26040cbddd3fa3c1cba215` |
| Working tree | clean (no modified/untracked files) |
| WordPress | 7.1 (`wp core version`) |
| Theme | `conexao-br-irlanda` 1.0.0 |
| Site locale | `WPLANG = pt_BR` (Stage 1 local setting) |
| Plugins | akismet 5.7.2, classic-editor 1.7.0, conexao-admin-ux 1.0.6, conexao-content 1.0.0, conexao-data-model 1.6.0, conexao-event-importer 1.7.1, conexao-event-runtime 1.2.1, conexao-leisure-migration 2.1.0, conexao-sponsor-migration 1.1.0 (inactive), crowdsignal-forms, gravatar-enhanced, gutenberg 23.8.0, jetpack 16.2-a.3, layout-grid, page-optimize 0.6.3, polldaddy, updraftplus 1.26.7, wordpress-importer 0.9.5 (+ 1 mu-plugin) |
| Content | page 43, post 42, guide 51, event 2232, leisure 289, sponsor 3, job 1, course_provider 10 |
| Taxonomies | conexao_category 58, conexao_county 32, conexao_town 250, conexao_tag 0, category 26, post_tag 1 |
| DB tables / rows | 20 tables, 5923 posts, 66117 postmeta, 385 terms, 560 options |
| DB schema version | `db_version = 61833` (WordPress 7.1) |
| Checkpoint | `~/.conexao-stage2-checkpoints/stage2-baseline.sql` (see below) |

**Checkpoint created (outside the repository):**

```bash
docker compose exec -T db sh -c \
  'MYSQL_PWD=local-root-password mysqldump -uroot --set-gtid-purged=OFF \
   --default-character-set=utf8mb4 --single-transaction --routines --triggers \
   --events wordpress' > ~/conexao-stage2-checkpoints/stage2-baseline.sql
```

- `16,053,878` bytes, 20 `CREATE TABLE` statements.
- **Restore verified** into a scratch database (`stage2_verify`) and compared
  against the live DB: tables 20 = 20, posts 5923 = 5923, postmeta 66117 = 66117,
  terms 385 = 385, options 560 = 560, term relationships 5685 = 5685. The scratch
  DB was dropped afterwards. The restore uses the same primitive as
  `scripts/restore-updraft-db.sh` (`mysql --default-character-set=utf8mb4`).
- The code baseline is git itself (`master` + HEAD above); nothing was stashed,
  rewritten or force-reset. **No commit was made in this stage.**
- No production system was touched at any point.

## 4. Language configuration

Applied by `scripts/stage2-polylang-setup.php` (idempotent; accepts `dry-run`) —
the same values the Polylang admin screens would store:

| Setting | Value | Meaning |
|---|---|---|
| Languages | `pt` → `pt_BR`, `en` → `en_US` | Portuguese + English |
| `default_lang` | `pt` | Portuguese is the default language |
| `force_lang` | `1` | language code in a directory (`/en/`) |
| `hide_default` | `true` | the default language has **no** prefix (`/guias/`) |
| `rewrite` | `true` | pretty permalinks |
| `browser` | `false` | never guess a language from the browser |
| `redirect_lang` | `false` | unchanged front-page behaviour |
| `media_support` | `false` | media is shared, not per-language |
| `post_types` option | *(empty)* | translated types are declared **in code** (`inc/polylang.php`) so environments cannot drift |
| Translated post types | `post`, `page`, `guide`, `event`, `leisure`, `sponsor`, `job`, `course_provider` | identity-critical CPTs included; admin-only `recruitment_agency` / `permit_employer` excluded |
| Translated taxonomies | `conexao_category`, `conexao_county`, `conexao_town`, `conexao_tag`, `category`, `post_tag` | shared terms, per-language names |

## 5. Existing content language assignment

Performed once, with Polylang's **own** bulk routine
`PLL_Model::set_language_in_mass()` — the identical code path the Polylang setup
wizard uses for “assign untranslated contents”. No custom SQL, no duplication,
no meta writes:

| Metric | Before | After |
|---|---|---|
| Objects without a language | **2687 posts / 366 terms** | **0 posts / 0 terms** |
| Content counts (guide/event/leisure/sponsor/job/course_provider/post/page) | 51 / 2232 / 289 / 3 / 1 / 10 / 42 / 43 | unchanged (re-verified at the end of the stage) |
| Taxonomy terms (county/town/category/tag) | 32 / 250 / 58 / 0 | unchanged, one language each, no duplicates |

- Every pre-existing record (pages, guides, blog posts, events, Lazer, sponsors,
  courses, jobs) is `pt`; every shared term is `pt`.
- Identity meta (`_event_source`, `_event_source_id`, `_event_export_uuid`,
  `_event_url`, `_leisure_export_uuid`, `_sponsor_export_uuid`) is untouched —
  proven by `tests/test-polylang-foundation.php` and the identity gates (§9, §11).
- Polylang 3.8 stores languages as terms of the `language` taxonomy; the only
  new terms are Polylang's own (`pt`, `en`) plus its internal helper taxonomies.
  No content taxonomy was fragmented.
- No English translations were created for the content catalogue. The only
  English records ever created were **temporary test fixtures**, deleted by the
  tests that created them (content counts returned to the baseline).

## 6. `conexao_current_locale()` integration

The Stage 1 abstraction remains the single application-level locale answer.
Stage 2 adapts it — it does not duplicate language detection:

| Layer | File | Behaviour |
|---|---|---|
| Extension point | `inc/i18n.php` | `conexao_current_locale()` = `apply_filters( 'conexao_current_locale', get_locale() )` (unchanged signature) |
| Polylang adapter | `inc/polylang.php` | `add_filter( 'conexao_current_locale', … )` → `pll_current_language( 'locale' )` (falls back to the Stage 1 value when Polylang is inactive) |
| Stage 1 locale correction | `inc/i18n.php` | `conexao_correct_default_locale()` becomes a **no-op while Polylang is active**, because `en_US` is now a legitimate per-request locale instead of the accidental unconfigured state |
| Requested vs current language | `inc/polylang.php` | `conexao_requested_language_slug()` captures the URL's language early (see §8) |
| Request-locale consumers | unchanged | recurrence labels, JS i18n payload, static strings, dates, `og:locale`, JSON-LD `inLanguage` all follow automatically |

Verified: PT context → `pt_BR` / `pt-BR` / `og:locale=pt_BR`; EN context →
`en_US` / `en-US` / `og:locale=en_US` (§22 HTTP checks).

No other theme file reads Polylang: the theme's Polylang surface is exactly
`inc/polylang.php` (+ the two call sites in `inc/seo.php` and the header/404/
`functions.php` cache helpers via the generic helpers).

## 7. Language switcher

- **Template part:** `template-parts/language-switcher.php`, rendered by
  `conexao_language_switcher()`.
- **Desktop:** inside the existing `.header-actions` row (next to the theme
  toggle). **Mobile:** inside the existing drawer (`.mobile-menu-language`,
  above the search form), so it never competes for header width. Below 1024px the
  desktop instance is hidden and the drawer instance (larger touch targets) takes
  over.
- **Labels:** text only — `PT` / `EN`, no flags (flags are not part of the site's
  visual language). Styling reuses the existing design tokens
  (`--color-border`, `--color-surface-muted`, `--radius-full`, …) so dark mode
  works without new variables. New strings (`Idioma do site`, `Ver em %s`) were
  added to the theme catalogs; the EN context shows “Site language” /
  “View in Português”.
- **URL policy (approved B5, Stage 2 scope):** real translation → that
  translation's permalink; otherwise the same path in the target language for
  archive/taxonomy/search/home contexts, or that post type's target-language
  archive for singles. **No link to a non-existent detail page.**
- **Verified in rendered HTML:**

| Page | Switcher target |
|---|---|
| `/` (PT home) | EN → `/en/` |
| `/en/` (EN home) | PT → `/` |
| `/eventos/` | EN → `/en/eventos/` |
| `/guias/{pt-slug}/` (no EN translation) | EN → `/en/guias/` (the archive — never `/en/guias/{pt-slug}/`) |

`template-parts/language-switcher.php` is the only new UI component; it is
reused by both placements (architecture rule: shared template parts).

## 8. `/en/` routing

Verified locally over HTTP (full matrix: §22, `scripts/stage2-http-verify.sh`,
**61/61 PASS**):

| Request | Result |
|---|---|
| `/` `/guias/` `/eventos/` `/lazer/` `/blog/` `/cursos/` `/empregos/` `/apoiadores/` `/irlanda/` `/sobre-nos/` `/contato/` `/moradia/` `/saude/` | **200**, byte-identical URL (no redirect) |
| `/en/` `/en/guias/` `/en/eventos/` `/en/lazer/` `/en/cursos/` `/en/apoiadores/` | **200** (language-scoped archives/homes) |
| `/en/blog/` `/en/empregos/` `/en/irlanda/` (untranslated pages) | **302 → PT URL** |
| `/en/guias/{pt-slug}/` `/en/eventos/{pt-slug}/` `/en/lazer/{pt-slug}/` | **302 → PT URL** |
| `/en/towns/dublin/` `/en/counties/dublin/` (terms) | **302 → PT term URL** |
| `/guias/{pt-slug}/` | 200 (self-canonical, unchanged) |

Rules implemented:

- CPT rewrite slugs were **not renamed** (`guias`, `eventos`, `lazer`, `blog`,
  `empregos`, `cursos`, `apoiadores`).
- `/en/` is produced by Polylang's `force_lang=1` + `hide_default=true`.
- **Never 301 on a missing translation.** Two theme-owned mechanisms guarantee
  the 302:
  1. `conexao_seo_missing_translation_redirect()` (`inc/seo.php`,
     `template_redirect` priority 6) — for requests whose **resolved query** is a
     record in another language (single, posts page, term).
  2. `conexao_polylang_language_redirect_is_temporary()` (`inc/polylang.php`,
     `pll_check_canonical_url` priority 20) — converts Polylang's own
     language-mismatch 301 into a 302, which also covers slugs that only exist in
     the other language (e.g. the 404-fallback case).
- **Discovered and fixed during testing:** Polylang deliberately flips its own
  `curlang` to the *detected* language while computing the canonical redirect
  (`PLL_Frontend_Canonical::check_canonical_url()` → `canonical.php:260`), so
  `pll_current_language()` can no longer report which language the URL asked for.
  `conexao_requested_language_slug()` captures the requested language once, early
  (`wp` / `template_redirect` priority 0, i.e. before Polylang's priority 4), and
  every redirect decision uses that captured value. Without it, every mismatch
  check compared `pt` with `pt` and Polylang's 301 won.
- The front page is never redirected: `/en/` is a real language home. (Note:
  `is_home()` is true on `/en/` while the Portuguese front page has no English
  translation — Polylang serves the posts index for the secondary language home;
  see §25.)

## 9. Event identity tests

**HARD GATE — PASSED.** `wp-content/plugins/conexao-event-importer/tests/test-language-identity.php`
(plain PHP, real importer code, no mocks): **27 passed, 0 failed**.

Scenario implemented end-to-end:

1. The importer creates the Portuguese event through its own `upsert_event()`
   path (`_event_source = stage2_identity_test`,
   `_event_source_id = stage2-id-0001`, export UUID assigned by the export tooling,
   as in production).
2. Re-running dedup + upsert resolves the **same** record and reports
   `unchanged` — identity is stable and idempotent.
3. An English translation is created and linked (`pll_save_post_translations`)
   carrying the **same** identity meta (source, source id, export UUID, URL,
   `_event_date`, `_event_start_time`, `_event_status`).
4. `Conexao_Event_Deduplicator::find()` still resolves the **Portuguese master**
   (not the newer translation).
5. The importer **refuses to write** to the translation: the guarded upsert
   returns `skipped` with a reason, the translation's title/content are byte-identical
   afterwards, and no third record is created for the identity.
6. Identity integrity: exactly **1** record per language for that identity,
   **2** in total (`lang=''` across languages), all identity meta language-neutral
   and the export UUID shared verbatim (no UUID invented).

Guardrails added (minimum safe changes, no identity-model redesign):

| Change | File |
|---|---|
| `Conexao_Event_Importer_Language_Guard` (import language assignment on `save_post_event`, `is_import_target()`) | `includes/class-language-guard.php` (new) |
| Upsert refuses a target in another language (`skipped` + log entry) | `includes/class-event-importer.php` |
| Deduplication prefers the import language (`pick_import_language()`, candidates 1 → 10) | `includes/class-event-deduplicator.php` |

Design note recorded for reviewers: in a CLI/admin context Polylang already
pre-filters queries by the *admin* language, which often makes the naive lookup
return the master anyway — the CLI test prints that naive result
(`note: naive newest-first identity lookup returns …`) so the risk is visible.
The language preference makes the outcome deterministic **regardless of which
admin language the import runs under**, which is the actual invariant required.

## 10. Event `_event_status` tests

**HARD GATE — PASSED.** `wp-content/plugins/conexao-event-runtime/tests/test-event-language-gate.php`:
**16 passed, 0 failed**. It runs as a plain PHP process (not WP-CLI) precisely so
the real `pre_get_posts` gate is active (the gate returns early under WP-CLI).

- PT context lists the PT event and **not** its EN translation; EN context lists
  the EN translation and **not** the PT event (no cross-language leakage).
- `expired`, `rejected` and `source_not_found` hide the event in **both**
  languages (`Conexao_Event_Query::upcoming_event_ids()`).
- A real public `WP_Query` (archive path) returns the published PT event, filters
  the EN record out of the PT context, and still hides the expired event — i.e.
  the status gate composes with the language filter via SQL AND and is never
  bypassed.
- The gate's `is_admin()` / `WP_CLI` early returns are untouched (admin list,
  importer and CLI behaviour unchanged).

Cache note (Phase 17): the ordered-ID transient is now
`conexao_event_upcoming_YYYYMMDD_{lang}` and `flush_cache()` clears **every**
language variant, so a PT save can never leave a stale EN list behind.

## 11. Lazer UUID tests

**HARD GATE — PASSED.** `wp-content/plugins/conexao-leisure-migration/tests/test-language-uuid.php`:
**14 passed, 0 failed**.

- The migration language guard assigns `pt` on `save_post_leisure` and **never
  reassigns** an existing language (an EN translation stays `en`).
- `_leisure_export_uuid` is byte-identical on master and translation; no new UUID
  is invented.
- Exactly **1** record per language carries the UUID, **2** in total — no
  duplicate UUID is introduced.
- `find_by_uuid()` resolves the **Portuguese master**; `update_item()` refuses to
  write to a translation (`WP_Error`, translated content untouched).
- `conexao_leisure_external_url()` (external-resource classification used by the
  single template, sitemap and cards) returns the same value before and after the
  translation exists.
- No real production ZIP import was executed — fixtures only.

Note: the architecture doc calls the key `_lazer_uuid`; the implementation (and
these tests) use the actual constant
`Conexao_Lazer_Exporter::UUID_META_KEY = '_leisure_export_uuid'`.

## 12. Taxonomy integrity

`tests/test-polylang-foundation.php` (57/57) asserts, from the live database:

- `conexao_county`, `conexao_town`, `conexao_category`: **no duplicate term
  slugs**, and **no per-language suffixed terms** (`dublin-pt`, `cork-en`, …) —
  i.e. one shared term identity per concept.
- Every shared term has **exactly one** language assignment (`pt`).
- Term counts unchanged (32 counties / 250 towns / 58 categories) and term IDs
  and slugs preserved.
- Filter URLs keep working with the existing helpers:
  `/eventos/?cidade=dublin` → **200** and `/en/eventos/?cidade=dublin` → **200**
  (the query-parameter strategy is unchanged; `/en/` is a prefix, not a rewrite).
- **Documented nuance:** `/lazer/?county=dublin` resolves a *term* query var, so
  the EN variant is answered with a **302** to the PT filtered URL until the
  shared term has an EN name/translation (Stage 3 taxonomy localization). The
  unfiltered `/en/lazer/` archive is 200. No filter URL was redesigned.

## 13. Canonical behaviour

Owner: **`inc/seo.php`** (unchanged single owner). Verified at the HTML level
(HTTP matrix):

| Context | Canonical |
|---|---|
| `/` | `…/` (self) |
| `/en/` | `…/en/` (self) |
| `/eventos/` | `…/eventos/` (self) |
| `/en/eventos/` | `…/en/eventos/` (self) |
| PT single (`/guias/{slug}/`) | self |
| Genuine EN translation (fixture, §19 probe) | the EN permalink (Polylang link filters) |

- A new filter `conexao_seo_canonical_url` is applied inside
  `conexao_seo_canonical()`, and `inc/polylang.php` uses it for **one** rule:
  if the request renders a record of another language (the B2 fallback state),
  canonical → the record's own (PT) URL. Because B2 rendering is deferred, this
  path is implemented and unit-visible but not currently reachable over HTTP
  (fallbacks 302 before rendering — §7). It is the piece Stage 3 needs.
- Pagination normalisation, WordPress core `rel_canonical` removal and the
  noindex rules are untouched.

## 14. hreflang behaviour

Owner: **`inc/seo.php`** (`conexao_seo_hreflang()` on `wp_head`, priority 5),
data from `conexao_hreflang_links()` (`inc/polylang.php`). Emitted **only** for
real relationships:

| Context | Output |
|---|---|
| `/` (PT home) | `hreflang="pt-BR"` → `/`, `hreflang="x-default"` → `/` — **no** `en` link |
| `/en/` (EN home) | `pt-BR` → `/`, `en` → `/en/`, `x-default` → `/` |
| `/en/eventos/` | `en` → `/en/eventos/`, `pt-BR` → `/eventos/`, `x-default` → `/eventos/` |
| PT single with no translation | `x-default` → its own URL only |
| PT archive whose language has content | self alternate + `x-default` |
| Empty EN archive for a language without content | the *current* archive only (self) — an empty shell is never advertised as an alternate |
| `/blog/`, terms, search, 404 | page-specific: posts page/term follow the object's translation links; search/404 emit nothing |
| Redirect-only URLs (`/en/blog/`, untranslated details) | nothing — they never render |

No duplicate hreflang tags, no alternate pointing at a URL that would redirect,
`x-default` always = the default-language URL.

## 15. Redirect collision testing

Performed with `curl -sI` (status + `X-Redirect-By` + `Location`):

| Existing legacy redirect | Result | Verdict |
|---|---|---|
| `/guides/` `/events/` `/courses/` `/jobs/` `/sponsors/` `/ireland/` `/about-us/` `/contact/` | **301 → correct PT URL** | unchanged (layer 1 `.htaccess` + layer 2 `inc/seo.php`) |
| `/en/guias/` `/en/eventos/` `/en/lazer/` `/en/cursos/` `/en/apoiadores/` | **200** | not caught by any legacy rule |
| `/en/guides/` `/en/events/` `/en/sponsors/` | **404** | these paths do not exist in PT either; no legacy rule fires |
| `/en/jobs/` `/en/courses/` `/en/post/foo` | 301 to a similarly-named PT record | WordPress core's `redirect_guess_404_permalink` for a non-existent path (pre-existing WP behaviour, identical for any bogus PT path); **not** produced by the legacy redirect table |

- No loop exists: every theme-owned redirect targets a **Portuguese** URL, and
  Portuguese requests never redirect to `/en/`. The `/en/{pt-url}/ → {pt-url}`
  chains terminate in a 200 (or in the existing leisure 302 to the external
  site).
- The legacy redirect table was **not modified** (`.htaccess` untouched, no entry
  removed or reordered).

## 16. Sitemap analysis

Local finding (documented, environment-dependent):

- Locally **Jetpack 16.2-a.3 owns `/sitemap.xml`** (generator header
  `jetpack-16.2-a.3`), so the theme's custom sitemap (`conexao_seo_sitemap`,
  `template_redirect` priority 0) is **shadowed** in this environment. This is
  pre-existing, not Polylang-caused.
- Jetpack's output after enabling Polylang: **85 `<loc>` entries, 0 `/en/` URLs,
  0 `hreflang`/`xhtml:link` entries**, and it still uses the stale local domain
  (`conexaobrirlanda.com`). So enabling Polylang introduced **no** duplicate
  URLs, **no** missing-but-expected EN URLs, **no** malformed alternates and
  **no** inconsistent canonicals in Jetpack's sitemap.
- The theme sitemap was hardened for the multilingual state (Infrastructure
  only, verifiable once it is actually serving):
  - URL sets are pinned to the **default language** (`lang` query var), so an EN
    translation can never appear as a duplicate `<url>` entry;
  - `<urlset>` now declares `xmlns:xhtml` and each entry may carry
    `<xhtml:link rel="alternate">` entries — emitted **only** for real
    translation sets (post/page/CPT and term), never for untranslated records,
    redirect targets or empty archives;
  - the homepage entry declares the real `/` ↔ `/en/` pair;
  - exclusion rules (`conexao_leisure_external_url`, utility pages) unchanged.
- **Production action required (later stage, not this one):** decide Jetpack vs
  theme sitemap ownership on WordPress.com and verify the theme sitemap output
  there. Production sitemap ownership is **not** claimed as solved.

## 17. Cache separation

Every language-sensitive cache is now keyed per language; no cache can serve the
wrong language.

| Layer | Before | After |
|---|---|---|
| Homepage sections (`conexao_home_news/_events/_sponsors/_jobs/_featured/_popular/_latest`) | shared key | `…_pt` / `…_en` (`conexao_lang_cache_key()`) |
| 404 page (`conexao_404_guides`, `conexao_404_events`) | shared key | `…_pt` / `…_en` |
| Event ordered-ID list (runtime) | `conexao_event_upcoming_YYYYMMDD` | `…YYYYMMDD_pt` / `_en` |
| Filter bar object caches (`conexao_terms_*`, `conexao_event_towns*`, `conexao_provider_categories`) | shared key | `…_pt` / `…_en` |
| Invalidation | deleted the one key | `conexao_flush_language_cache()` / `conexao_flush_language_object_cache()` delete **every** language variant (+ the legacy unsuffixed key) |

Tests performed:

1. Warm PT (`GET /`) then request `/en/` → HTML is `lang="en-US"` with
   `og:locale=en_US` (never the PT cache). ✔
2. Warm EN (`GET /en/`) then request `/` → HTML is `lang="pt-BR"` with
   `og:locale=pt_BR` (never the EN cache). ✔
3. `${lang}` key separation asserted in `test-polylang-foundation.php`
   (flush removes both variants) and in `test-event-query.php`
   (`conexao_event_upcoming_…_pt` ≠ `…_en`). ✔
4. Save/invalidation behaviour: `conexao_homepage_cache_invalidate()` now clears
   all language variants; `Conexao_Event_Query::flush_cache()` clears all
   languages for today ±1 day. ✔

Edge cache note: WordPress.com's edge cache keys on the URL path, and `/en/` is a
distinct path, so no `Vary: Accept-Language` handling is required (architecture
§18).

## 18. Search behaviour

- `/?s=dublin` → **200** (PT search; language-scoped by Polylang to `pt`).
- `/en/?s=dublin` → **200**, **no redirect** to the PT search (verified).
- Search is not redesigned and no search plugin was installed; the Stage 1
  accent-insensitive search layer (`inc/search.php`) is untouched.
- Expired/rejected events still cannot appear (the `_event_status` gate is
  composed with the language filter — §10).
- Consequence of the B5 policy: until English content exists the EN search
  returns 0 results (English is `en`-scoped; PT records are not surfaced as B2
  fallbacks yet). This is the approved Stage 2 state, not a bug; the B2 “EN
  search includes directory PT records” behaviour is Stage 3 work (§26).

## 19. REST behaviour

Documented here is **only what was observed locally** (Phase 19 requirement),
via real HTTP requests and `scripts/stage2-rest-probe.php`:

| Probe | Observed |
|---|---|
| `/wp-json/wp/v2/guide?lang=pt-br`, `?lang=en`, no param | **identical** results (55 REST-visible records at probe time, including the PT + EN fixtures) — the `lang` parameter does **not** scope collections in Polylang 3.8.9 Free |
| `/wp-json/wp/v2/event?lang=…` (2232 records) | same total in every case |
| Single item `guide/{id}` | **no `lang` field**, **no `_links.translations`** (only the standard `self/collection/about/version-history/wp:attachment/wp:term/curies` links) |
| `guide/{pt-id}?lang=en` | 200, returns the PT record, parameter ignored |
| Both fixture records (PT + linked EN) present in every collection | REST is **not** language-filtered in this configuration |

Interpretation: Polylang Free's REST module handles the **write** path
(`lang` when creating/updating) and request context, but does **not** implement
`?lang=` collection filtering or a `translations` link. The architecture
decision's assumed Flutter contract therefore is **not** delivered by Polylang
Free — recorded as a Stage 4 prerequisite (§26). Nothing was changed in the
Flutter/consumer contract in this stage, and PT REST behaviour is unchanged
(robots.txt still disallows `/wp-json/`).

## 20. Importer / migration guards

Minimum safe guards so future imports cannot corrupt identity (all fail-safe and
no-ops when Polylang is inactive):

| Plugin | Guard | File |
|---|---|---|
| `conexao-event-importer` | import language assignment on `save_post_event`; upsert refuses a target in another language (reports a skip); dedup prefers the import language | `includes/class-language-guard.php` (new), `includes/class-event-importer.php`, `includes/class-event-deduplicator.php` |
| `conexao-leisure-migration` | default-language assignment on `save_post_leisure`; `find_by_uuid()` prefers the import language; `update_item()` refuses a translation (`WP_Error`) | `includes/class-language-guard.php` (new), `includes/class-leisure-importer.php` |
| `conexao-sponsor-migration` | default-language assignment on `save_post_sponsor`; `find_existing_sponsor()` prefers the import language; `import_sponsor()` skips a translation | `includes/class-language-guard.php` (new), `includes/class-sponsor-importer.php` |

Explicitly **not** implemented: source-language detection for imported
English-native events (architecture Stage 3), export JSON `"lang"` field, and any
change to UUID/source-ID/URL matching semantics. Documented in
`docs/plugins/*.md`.

## 21. Exact files changed

**Created (13):**

1. `wp-content/themes/conexao-br-irlanda/inc/polylang.php` — the theme's only Polylang-aware file
2. `wp-content/themes/conexao-br-irlanda/template-parts/language-switcher.php`
3. `wp-content/themes/conexao-br-irlanda/tests/test-polylang-foundation.php`
4. `wp-content/plugins/conexao-event-importer/includes/class-language-guard.php`
5. `wp-content/plugins/conexao-event-importer/tests/test-language-identity.php`
6. `wp-content/plugins/conexao-event-runtime/tests/test-event-language-gate.php`
7. `wp-content/plugins/conexao-leisure-migration/includes/class-language-guard.php`
8. `wp-content/plugins/conexao-leisure-migration/tests/test-language-uuid.php`
9. `wp-content/plugins/conexao-sponsor-migration/includes/class-language-guard.php`
10. `scripts/stage2-polylang-setup.php`
11. `scripts/stage2-http-verify.sh`
12. `scripts/stage2-rest-probe.php`
13. `CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md` (this file)

**Modified (33)** — `git diff --shortstat`: *33 files changed, 2840 insertions(+), 364 deletions(-)*

Theme (`wp-content/themes/conexao-br-irlanda/`):
`404.php`, `assets/css/header-nav.css`, `functions.php`, `header.php`,
`inc/i18n.php`, `inc/seo.php`, `languages/conexao-br-irlanda.pot`,
`languages/pt_BR.po`, `languages/pt_BR.mo`, `languages/en_US.po`,
`languages/en_US.mo`, `tests/test-i18n-foundation.php`,
`tests/test-event-location-filters.php`, `tests/test-leisure-multiselect-filters.php`

Plugins:
`conexao-event-importer/conexao-event-importer.php`,
`conexao-event-importer/includes/class-event-importer.php`,
`conexao-event-importer/includes/class-event-deduplicator.php`,
`conexao-event-importer/languages/conexao-event-importer.pot`,
`conexao-event-runtime/includes/class-event-query.php`,
`conexao-event-runtime/tests/test-event-query.php`,
`conexao-leisure-migration/conexao-leisure-migration.php`,
`conexao-leisure-migration/includes/class-leisure-importer.php`,
`conexao-leisure-migration/languages/conexao-leisure-migration.pot`,
`conexao-sponsor-migration/conexao-sponsor-migration.php`,
`conexao-sponsor-migration/includes/class-sponsor-importer.php`,
`conexao-sponsor-migration/languages/conexao-sponsor-migration.pot`

Docs / repo root:
`AGENTS.md`, `docs/routing.md`, `docs/development.md`,
`docs/plugins/conexao-event-runtime.md`, `docs/plugins/conexao-event-importer.md`,
`docs/plugins/conexao-leisure-migration.md`, `docs/plugins/conexao-sponsor-migration.md`

**Classified as local-only, NOT repository content:**

- `/var/www/html/wp-content/plugins/polylang/**` — third-party plugin installed
  into the Docker volume (3.6 MB, 242 PHP files). Not tracked by git; production
  will receive the WordPress.org ZIP.
- `~/.conexao-stage2-checkpoints/stage2-baseline.sql` + the dropped scratch DB
  `stage2_verify` — local database state, deliberately outside the repository.
- Working DB/cache state (Polylang options, language term relationships,
  transients) — local environment state, not files.

## 22. Exact files intentionally not changed

| Area | Files | Why |
|---|---|---|
| Redirect table (legacy EN→PT 301s) | `.htaccess`, redirect arrays in `inc/seo.php` | preserved byte-for-byte (§15) |
| CPT/taxonomy registration & slugs | `conexao-data-model/**`, `conexao-content/**` | Portuguese slugs stay canonical |
| Event identity / status model | `conexao-event-runtime/includes/class-event-status.php` | no identity or status semantics changed |
| Lazer export format / UUID logic | `conexao-leisure-migration/includes/class-leisure-exporter.php` | UUID model untouched |
| Sponsor identity meta | `conexao-sponsor-migration/includes/class-sponsor-exporter.php` | untouched |
| Search layer | `inc/search.php` | no redesign, no search plugin |
| Front-end templates/markup | `front-page.php`, `archive.php`, `single*.php`, `search.php`, `template-parts/*` (except the new switcher) | no template redesign in this stage |
| JavaScript | `assets/js/main.js` | Stage 2 adds no JS (`node --check` run anyway) |
| Jetpack / production config / Flutter | — | production and Stage 4 untouched |

## 23. Exact test commands and results

Environment: WordPress 7.1, PHP 8.3.33, Polylang 3.8.9 **active**, `pt_BR` default.
Run from the project root unless noted.

**Static checks (all clean):**

```bash
php -l <every changed/created PHP file>   # "No syntax errors detected" (28 files)
bash -n scripts/stage2-http-verify.sh     # ok
node --check wp-content/themes/conexao-br-irlanda/assets/js/main.js   # ok (no JS changed)
git diff --check                          # no whitespace errors
```

**HTTP / URL / SEO / cache / REST matrix:**

```bash
./scripts/stage2-http-verify.sh http://localhost:8080
# == Stage 2 HTTP verification: 61 passed, 0 failed ==
```

**Stage 2 gates:**

| Command | Result |
|---|---|
| `docker compose exec wordpress php …/tests/test-polylang-foundation.php` | **57 passed, 0 failed** |
| `…/conexao-event-runtime/tests/test-event-language-gate.php` | **16 passed, 0 failed** |
| `…/conexao-event-importer/tests/test-language-identity.php` | **27 passed, 0 failed** |
| `…/conexao-leisure-migration/tests/test-language-uuid.php` | **14 passed, 0 failed** |
| `wp eval-file - < scripts/stage2-polylang-setup.php` (2nd run) | idempotent: 0 objects without language, config unchanged |
| `wp eval-file - < scripts/stage2-rest-probe.php` | REST contract captured (§19) |

**Portuguese regression + existing suites (Polylang active):**

| Suite | Result |
|---|---|
| `tests/test-i18n-foundation.php` (Stage 1, updated for the Stage 2 scope) | 29 passed, 0 failed |
| `tests/test-recurrence-i18n.php` | 12 passed, 0 failed |
| `tests/test-guide-breadcrumb-filter.php` | 36 passed, 0 failed |
| `tests/test-leisure-attribute-normalization.php` | 22 passed, 0 failed |
| `tests/test-leisure-card-map-action.php` | 44 passed, 0 failed |
| `tests/test-leisure-multiselect-filters.php` | 63 passed, 0 failed |
| `tests/test-leisure-related-events.php` | 19 passed, 0 failed |
| `tests/test-sponsor-archive-ordering.php` | 15 passed, 0 failed |
| `tests/test-event-location-filters.php` | 45 passed, **1 failed (pre-existing)** |
| `conexao-event-runtime/tests/test-event-query.php` | 0 failed (key assertion made language-aware) |
| `conexao-event-runtime/tests/test-event-recurrence.php` | 90 passed, 0 failed |
| `conexao-event-runtime/tests/test-plugin-separation.php with-tooling` | 30 passed, **4 failed (pre-existing)** |

**A/B baseline proving the failures are not Stage 2 regressions** — with Polylang
**deactivated**:

| Suite | Polylang OFF | Polylang ON |
|---|---|---|
| `test-event-location-filters.php` | 44 passed, **1 failed** (same assertion) | 45 passed, **1 failed** |
| `test-plugin-separation.php with-tooling` | 30 passed, **4 failed** (same 4) | 30 passed, **4 failed** |
| `test-event-query.php` | 0 failed | 0 failed (key assertion now language-aware) |
| `test-leisure-multiselect-filters.php` | 62 passed, 0 failed | 63 passed, 0 failed |

The two suites whose *expectations* legitimately changed were updated to assert
the new reality instead of bypassing it: the event transient key now includes the
language suffix, and the two `tax_query` structure tests now count filter
dimensions **and** explicitly assert Polylang's own `language` group. No
acceptance criterion was weakened.

**Not tested / not testable:**

- **Browser/device verification: NOT TESTABLE — no browser tooling available.**
  The language switcher (desktop row / mobile drawer, dark mode) was verified by
  rendered-HTML inspection, CSS review against existing tokens, `php -l` and the
  HTTP matrix — **not** in a real browser at real viewport sizes.
- Production WordPress.com behaviour (Jetpack sitemap ownership, plan support for
  the Polylang ZIP, plan PHP version) — outside this local environment.

## 24. Production safety confirmation

- Polylang was **not** uploaded to production; nothing was deployed (no ZIP built
  for upload, no file copied).
- No production setting was changed: locale, menus, content, redirects, sitemap
  behaviour, WordPress.com settings, plugins.
- No production content was translated and no production English records exist.
- The production database was never touched; all work ran against the local
  Docker stack (MySQL in a container, WordPress in a container).
- The legacy redirect table (`.htaccess` + `inc/seo.php`) is byte-identical.
- The only external network activity was downloading the Polylang ZIP from
  `downloads.wordpress.org` and verifying its checksums against the WordPress.org
  API.

## 25. Known limitations

1. **B2 fallback rendering is deferred (interim 302).** The approved B2 model
   (PT content under an EN shell + notice, canonical → PT, hreflang pair) is not
   rendered yet: untranslated `/en/{record}/` URLs are answered with a 302 to the
   Portuguese URL. This is deliberate (Phase 7 allows “only the technical
   foundation” and conditions B2 on not corrupting canonical/translation state),
   and the pieces B2 needs already exist (`conexao_is_language_fallback()`, the
   `conexao_seo_canonical_url` filter, the switcher's no-fake-URL policy). A B2
   shell without EN archive inclusion would leave the EN directories empty and
   inconsistent, so both ship together in Stage 3.
2. **Empty EN archives.** `/en/guias/`, `/en/eventos/`, `/en/lazer/`,
   `/en/cursos/`, `/en/apoiadores/` resolve (200) but contain no records yet.
   hreflang alternates for empty archives are suppressed on purpose, so nothing
   advertises thin pages.
3. **`/en/` serves the posts index.** While the Portuguese front page (`inicio`)
   has no EN translation, Polylang serves the secondary language home as the
   posts index (`is_home()` without `is_posts_page`) — the EN home is a shell,
   not a translated homepage. Stage 3 translates the front page.
4. **`/en/blog/`, `/en/empregos/`, `/en/irlanda/` and county pages 302 → PT**
   until their pages have EN translations (B1 rule: “translated first, remaining
   B1”).
5. **EN Lazer `?county=` filter URLs 302 → the PT filtered URL** (shared term, no
   EN term name/translation yet). `/en/eventos/?cidade=dublin` is 200.
6. **REST `?lang=` / `_links.translations` are not provided by Polylang Free**
   (verified, §19). The Flutter bilingual contract stays unfulfilled.
7. **Sitemap ownership is unresolved locally** (Jetpack shadows the theme
   sitemap); the production reconciliation is a later-stage action (§16).
8. **Polylang Pro is not installed** (no license): the Pro translation editor and
   the “outdated translation” workflow are unavailable; the free-tier workflow
   (per-post translation links) is what exists today.
9. **`en_US` catalog is still a curated subset.** The two Stage 2 strings were
   added; other untranslated theme strings fall back to Portuguese by design.
   Plugin (`conexao-*`) catalogs remain POT-only (admin English deferred).
10. **Five pre-existing test failures** (not caused by Stage 2; reproduced with
    Polylang deactivated): `test-event-location-filters.php` (1) and
    `test-plugin-separation.php with-tooling` (4), both stemming from the large
    local dataset.
11. **Production settings still pending** (from Stage 1): the local `pt_BR`
    locale/date-format values must be applied on WordPress.com at deploy time.

## 26. Stage 3 prerequisites

1. **B2 shell**: EN notice + `conexao_is_language_fallback()` rendering,
   canonical → PT, hreflang pair for fallbacks, and EN archive inclusion of B2
   records (events/leisure/sponsors/jobs) — including their caches and the EN
   search widening described in architecture §17.
2. **Key static pages translated** (`inicio`, the `blog` posts page, `empregos`,
   `irlanda`, county pages) — fixes limitations 3 and 4 and the home shell.
3. **Taxonomy localization**: EN term names + term translation links for counties
   and towns (fixes the Lazer `?county=` EN filter and gives EN filter labels),
   sharing the existing term IDs and PT slugs.
4. **Editorial content**: EN guides/blog translations (turning B1 302s into real
   pages), EN meta for Lazer/Sponsors, EN jobs landing.
5. **Sitemap**: emit EN `<url>` sets with alternates for translated content and
   reconcile Jetpack on production.
6. **Source-language detection for imported events** (source-EN records assigned
   `en`) + the additive export JSON `lang` field.
7. **REST contract** for the Flutter client (Polylang Pro, or an explicit
   `lang`/`translations` implementation owned by the theme).
8. **Production deployment plan**: Polylang ZIP upload, configuration,
   locale/date-format settings, cache flush, and the WordPress.com checks listed
   as NOT_TESTABLE in §23.
9. **Editorial workflow tooling** (Pro budget decision or the
   `_translation_outdated` flag in `conexao-admin-ux`).

## 27. Final acceptance matrix

| Item | Result | Evidence |
|---|---|---|
| PT URL preservation | **PASS** | 13 PT URLs 200, no redirect; legacy 301s intact (§15, §23) |
| `/en/` routing | **PASS** | `/en/` + 5 archives 200; untranslated details 302 (§8) |
| locale detection | **PASS** | `pt_BR`/`en_US`, `lang` attributes, `og:locale` (§6, §23) |
| language switcher | **PASS** | rendered on desktop + mobile, correct targets, no fake URLs (§7) |
| Event identity | **PASS** | `test-language-identity.php` 27/27 (§9) |
| Event status gate | **PASS** | `test-event-language-gate.php` 16/16 (§10) |
| Lazer UUID | **PASS** | `test-language-uuid.php` 14/14 (§11) |
| taxonomy integrity | **PASS** | no duplicate/suffixed terms, all terms assigned, filters work (§12) |
| canonical | **PASS** | self-canonical PT/EN verified in HTML; fallback rule implemented (§13) |
| hreflang | **PASS** | real relationships only, no duplicates, no redirect targets (§14) |
| redirects | **PASS** | legacy table untouched, no collision, no loop (§15) |
| sitemap | **NOT_TESTABLE** | Jetpack shadows the theme sitemap locally; Jetpack output audited, theme sitemap hardened, production action pending (§16) |
| cache | **PASS** | PT/EN never share cached HTML; keys + invalidation language-scoped (§17) |
| search | **PASS** | PT/EN search respond in their own context, no redirects, gate intact (§18) |
| REST | **PASS** | actual local behaviour verified and documented; `?lang` not provided by Polylang Free (§19) |
| importer | **PASS** | language guards + dedup preference + write refusal in 3 plugins (§9, §11, §20) |
| Portuguese regression | **PASS** | all suites green except 5 pre-existing failures (A/B proven) (§23) |
| browser/device testing | **NOT_TESTABLE** | no browser tooling available (§23) |

## 28. Final classification

**ENGLISH STAGE 2 — PASS**

All critical stop conditions were checked; none occurred:

1. Existing PT URLs changed — **no** (13/13 unchanged, legacy 301s intact).
2. Event identity duplicated by translation linking — **no** (dedup language
   preference + write guard + per-language identity counts).
3. `_event_status` visibility bypassed — **no** (hidden in both languages; the
   real `pre_get_posts` gate is exercised).
4. Lazer UUID integrity breakable — **no** (identical UUID, one record per
   language, no competing import target).
5. `/en/` caught by a legacy redirect — **no** (no collision, no loop).
6. canonical/hreflang ownership ambiguous — **no** (theme remains sole owner;
   Polylang's language-mismatch 301 is intercepted by the owner and re-issued as
   a 302).
7. PT/EN cache cross-contamination — **no** (per-language keys, verified over
   HTTP).
8. Destructive content/taxonomy changes by Polylang — **no** (assignment only; 0
   objects left unassigned; counts, IDs, slugs and identity meta unchanged).
9. Required production dependency unverifiable — the production-only items
   (Jetpack sitemap ownership, WordPress.com plan support for the Polylang ZIP,
   WordPress.com PHP version) are explicitly NOT_TESTABLE and deferred to the
   deployment stage; they do not block the local Stage 2 infrastructure.

The environment-dependent items (§23), the deferred B2 rendering (§25) and the
REST contract gap (§19) are reported openly as limitations and Stage 3/4
prerequisites — not hidden workarounds. No acceptance criterion was weakened to
reach this classification.
