# CONEXAO_BR_ENGLISH_STAGE_3_2_REPORT.md

Stage 3.2 of the approved English-support architecture
(`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`, audit
`CONEXAO_BR_ENGLISH_SUPPORT_AUDIT.md`, `CONEXAO_BR_ENGLISH_STAGE_1_REPORT.md`,
`CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md`,
`CONEXAO_BR_ENGLISH_STAGE_3_1_REPORT.md`): move from the proven bilingual
infrastructure into **controlled English content rollout** — the English
static front page, the remaining key static-page translations, the approved
B2 page allowlist, a controlled content-migration pilot with linked Polylang
translations, English taxonomy completion, event source-language metadata +
the additive export `lang` field, and a minimal editorial translation-state
indicator.

Everything is **local/staging only**. No production change of any kind: no
upload to WordPress.com, no production settings, no deployment (the
deployment plan is Phase 10 and is written, not executed).

---

## 1. Status

**ENGLISH STAGE 3.2 — PASS WITH LIMITATION**

Every Phase 12 hard gate passes (matrix in §28). The Stage 3.1 blocker —
`/en/` serving the English homepage directly — is **resolved** (§3). Seven
approved static pages are real translations (§4), the B2 page allowlist is
explicit and narrow (§5), a controlled 8-record content pilot proves the
per-type migration contract end-to-end (§6–§10), the export carries the
additive `lang` field with 57 focused assertions (§19), the editorial
translation-state indicator ships with 22 focused assertions (§11), and the
Stage 3.1 guarantees are re-verified with real English content in place
(§12–§18).

Three defects surfaced by the real English content were found and fixed at
the root cause during the regression work — none is hidden as a "known
issue":

1. **Duplicate EN appearance** — with a real EN translation present, the PT
   master and its EN translation both appeared in EN archives/search
   (Stage 3.1's replacement rule existed only inside the events query). Fixed
   for every B2 archive + EN search via
   `conexao_b2_translation_replaced_pt_ids()` (§8, §13).
2. **Legacy redirect hijack** — `/jobs/`, `/about-us/`, `/contact/` (legacy
   301 sources) were bounced 302 → `/en/…` by Polylang's language canonical
   once EN pages owned those slugs, because the legacy map ran *after*
   Polylang. Fixed by restoring the legacy map's precedence (priority 2 < 4)
   (§18).
3. **Taxonomy duplication** — Polylang Free ≥ 3.5 auto-created suffixed
   proper-noun copies (`dublin-en`, name copied verbatim) when county/town
   terms were assigned across languages, splitting filter slugs. Fixed
   structurally per the decision's own invariant ("Counties/towns are shared
   — no per-language duplicate terms"): county/town taxonomies are no longer
   Polylang-translated — one shared term per county/town, identical filters
   in both languages (§10).

Limitations (documented, no hard gate violated, concrete next steps): local
Apache is unavailable in this environment, so the `.htaccess` redirect layer
was verified as *unchanged* but not exercised (§18, §23); browser/device
testing is NOT TESTABLE (no tooling) (§25); production Jetpack sitemap
reconciliation remains a plan item by design (§10); the town-sanitization
suite has three dataset-existence expectations a fresh pilot catalogue cannot
satisfy (§22); the EN homepage quick-access cards still link to PT URLs
(pre-existing theme chrome — Stage 3.3 fix path) (§26).

## 2. Baseline (Phase 0)

| Item | Value |
|---|---|
| Repo / branch | `/workspace`, branch `i18n` (Stage 3.1 delivered state) |
| HEAD | `a26fd7f` ("Implement Stage 3.1 of English support architecture…") |
| Working tree at start | clean |
| WordPress | **7.1** (`wordpress-7.1.tar.gz`, matches the Stage 3.1 baseline) |
| PHP | **8.5.8** static CLI build (static-php-cli 2.8.6; mysqli, mbstring, gd, zip, intl, curl…) |
| Database | **MariaDB 11.4.8** (utf8mb4 / utf8mb4_unicode_ci), port 3307 |
| Polylang | **3.8.9 Free**, active, unmodified (same build as Stage 2/3.1) |
| Site locale | `WPLANG = pt_BR` + core pt_BR language pack installed |
| Languages | `pt` (default) + `en`; `force_lang=1`, `hide_default=true`, `browser=false`, `redirect_lang=false`, `media_support=false` |
| Site URL | `http://localhost:8090` (see environment note) |
| Timezone | `Europe/Dublin` (set during regression work to match production) |
| DB checkpoint | taken **before any Stage 3.2 change** — `/tmp/env/stage32-baseline.sql` (233 KB; local only, not committed) |

**Environment note (documented deviation).** This sandbox has **no Docker**
(`docker` absent; `/var/www/html` cannot be created; no user namespaces). To
run the stage against a real stack, an equivalent native environment was
built instead of the repo's `compose.yaml` services: WordPress 7.1 at
`/tmp/env/wordpress` with the repo's theme + 7 custom plugins **symlinked**
(exactly the compose mounts), Polylang 3.8.9 unpacked from
`downloads.wordpress.org`, and PHP's built-in server (`php -S`, 8 workers)
with a front-controller router. The repo gained no local-only plumbing except
an untracked `wp-load.php` shim at the repository root (excluded via
`.git/info/exclude`) so the repo's own scripts/tests resolve WordPress
exactly as inside the Docker container. Port 8080 is taken by a sandbox
service, so the local site runs on **8090**; `home`/`siteurl` were updated.
Because the stack is not Apache, the **`.htaccess` layer of the legacy
redirects cannot execute locally** — §18 and §23 state exactly which
behaviour is therefore NOT locally exercised. Everything else (routing,
Polylang, rewrite rules, the theme redirect layer, HTTP statuses, canonicals,
hreflang, sitemap, caches) runs through real HTTP on real MySQL.

**Stage 3.1 baseline confirmed before any change** (the Stage 3.1 database is
not in the repository; the PT catalogue was rebuilt with
`scripts/stage32-seed-pilot.php`, the 43 static pages with the repo's own
`conexao-content/create-pages.php`, course providers with
`scripts/seed-course-providers.php`, then Polylang configured by the repo's
`scripts/stage2-polylang-setup.php`):

- PT default language, EN secondary, directory-based `/en/` — ✔
- B2 rendering for the approved post types; B1 302 for guides/blog/pages — ✔
- `/en/home/` existed and rendered; **`/en/` 301 → `/en/home/`** — the Stage
  3.1 blocker reproduced exactly (`pll_home_url('en')` = `/en/home/`) — ✔
- The three Stage 3.1 pilot pages recreated as linked translations:
  `inicio → home`, `sobre-nos → about-us`, `contato → contact`
- Event/Lazer identity guarantees checked **before** the stage: no duplicate
  `_event_source + _event_source_id`, no duplicate `_event_export_uuid`, no
  duplicate `_leisure_export_uuid` → **not broken**; the stage proceeds.


## 3. Static-front-page configuration (Phase 1)

**Root cause.** Polylang Free derives each language's `page_on_front` from the
translation set of the site's `page_on_front` option (`PLL_Static_Pages`):
the English record *is* the English front page because it is the linked
translation of the Portuguese front page — that mechanism already worked in
Stage 3.1. What did not work is the **URL Polylang publishes** for the
language home: `PLL_Links_Model::set_language_home_url()` reports the
translated page's slugged URL (`/en/home/`) whenever the language has a
`page_on_front`, and `PLL_Frontend_Static_Pages::redirect_canonical()` then
301s `/en/` → `/en/home/`.

**Declared configuration (not a redirect hack).** The language home URL is
declared through Polylang's own language-construction pipeline in
`inc/polylang.php`:

- `conexao_polylang_language_home_data()` on
  **`pll_additional_language_data`** (priority 20) returns the links-model
  directory home (`PLL()->links_model->home_url($slug)`) as the language
  `home_url`. This is Polylang's documented allowlist filter whose sole
  purpose is exactly these four URL properties, and it is the same filter
  Polylang registers its own `set_language_home_urls()` on — the declaration
  replaces Polylang's default decision through the supported extension point.
- `conexao_polylang_language_home_url()` on **`pll_language_home_url`**
  mirrors the same value at read time; it is a no-op unless a site defines
  `PLL_CACHE_HOME_URL=false`, and keeps behaviour constant.
- **`conexao_polylang_language_home_ensure()`** (called at theme load)
  self-heals the one ordering hazard found here: Polylang builds its language
  list at `plugins_loaded` priority 1, *before* the theme loads. If the
  language cache is rebuilt in that window (language edit, permalink change,
  cache clean — this actually happened mid-stage and reverted `/en/` to the
  Stage 3.1 behaviour until diagnosed), the stored `home_url` reverts to
  `/en/home/`. The guard compares the cached EN home URL with the declared
  one and rebuilds the list once, at theme load, with the declaration
  registered; the corrected list is re-persisted, so the steady state costs
  one comparison and zero writes.

No rewrite rule, no `template_redirect` special case, no hidden URL was
added: Polylang's own canonical now consolidates `/en/home/` → `/en/`, the
mirror of `/inicio/` → `/`.

**Verified after applying (all over HTTP; full matrix in §23):**

| Check | Result |
|---|---|
| `pll_home_url('en')` | `http://localhost:8090/en/` |
| `pll_home_url('pt')` | `http://localhost:8090/` |
| `/en/` | **200**, front-page template (hero renders), `<html lang="en-US">` |
| `/` | **200**, `<html lang="pt-BR">`, unchanged |
| `/en/home/` | **301 → `/en/`** (canonical consolidation) |
| `/inicio/` | **301 → `/`** (PT parity, unchanged) |
| canonical on `/en/` / `/` | self-referential in both |
| hreflang on `/en/` | `en` → `/en/`, `pt-BR` → `/`, `x-default` → `/` |
| hreflang on `/` | `pt-BR` → `/`, `en` → `/en/`, `x-default` → `/` |
| switcher on `/` | `PT` current; `EN` → `…/en/` |
| switcher on `/en/` | `EN` current; `PT` → `…/` |
| homepage cache separation | `conexao_home_*_pt` / `_en` keys written independently; warming PT creates **no** EN keys and vice versa |
| resilience | after `delete_transient('pll_languages_list')`: `/en/` 200 and `/en/home/` 301 → `/en/` again |
| linked-translation relationship | `pll_get_post(4,'en')=126`, `pll_get_post(126,'pt')=4` |
| front-page template | `front-page.php` used for `/en/` (hero markup present) |


## 4. Remaining static translations (Phase 2)

`scripts/stage32-translate-pages.php` creates one linked English translation
per approved page (idempotent, collision-checked, link verified from both
sides, PT originals never touched; English copy is human-authored for this
stage — no machine translation). It contains the three Stage 3.1 pilot pages
(recreated for this environment) plus the four remaining approved key pages:

| PT page (id) | EN page (id) | EN URL | Notes |
|---|---|---|---|
| inicio (4) | home (126) | `/en/` | front page (§3) |
| sobre-nos (6) | about-us (127) | `/en/about-us/` | Stage 3.1 pilot URL preserved |
| contato (7) | contact (128) | `/en/contact/` | Stage 3.1 pilot URL preserved |
| empregos (15) | jobs (129) | `/en/jobs/` | **new**; same `page-empregos.php` template as PT (verified) |
| politica-de-privacidade (11) | privacy-policy (130) | `/en/privacy-policy/` | **new**; Gutenberg structure preserved |
| termos-de-uso (12) | terms-of-use (131) | `/en/terms-of-use/` | **new**; Gutenberg structure preserved |
| cookies (13) | cookie-policy (132) | `/en/cookie-policy/` | **new**; Gutenberg structure preserved |

Selection follows the architecture decision §8/§12: key narrative pages
(About, Contact, Privacy, Terms, Cookies, Jobs landing) are *real
translations*; county pages and `/irlanda/` stay B2 (§5); every other page
(category hubs, newsletter, revista, anuncie, search, europa, and the legacy
`sobre`/`termos`/`privacidade` alias pages) keeps B1. Nothing was translated
automatically.

- PT permalinks unchanged; PT content untouched (data-level diff in §24).
- EN slugs differ only where WordPress uniqueness requires it; all seven EN
  page slugs are natural English slugs (no `-en` suffix on pages).
- `conexao_meta_description` authored per page; the Jobs page reuses the PT
  landing template.
- The two WP installer default pages (`sample-page`, `privacy-policy`) were
  deleted locally so the EN `privacy-policy` slug could take the natural name
  (local housekeeping, not production content).

**B1 redirect retirement.** The B1 302 for these pages is the *generic*
missing-translation rule; it stops firing automatically the moment a real,
published translation exists — verified: `/en/jobs/`,
`/en/privacy-policy/`, `/en/terms-of-use/`, `/en/cookie-policy/`,
`/en/about-us/`, `/en/contact/` all render 200. **No redirect-map entry was
removed or changed** for this: none of these pages had a page-specific 302
entry to retire (the root-level legacy 301 sources `/about-us/`, `/contact/`,
`/jobs/` are a different namespace and stay, §18).

## 5. B2 page allowlist (Phase 3)

Stage 3.1 left `conexao_b2_page_allowlist()` empty on purpose. Stage 3.2
fills it with exactly the directory/information pages the architecture
decision §8 approves — nothing else:

| Slug | Reason for inclusion |
|---|---|
| `irlanda` | "Static pages (county pages, /irlanda/)" → B2 in decision §8; country information hub |
| `dublin`, `cork`, `galway`, `limerick`, `kildare`, `meath`, `wicklow`, `waterford`, `laois` | the nine county landing pages created by the repo's own `create-pages.php`; decision §8 assigns county pages to B2 |

Explicitly **not** in the allowlist, with the reason:

- `inicio`, `sobre-nos`, `contato`, `empregos`, `politica-de-privacidade`,
  `termos-de-uso`, `cookies` — real translations (§4). A page with a real EN
  translation never renders B2 (verified).
- `moradia`, `saude`, `transporte`, `familia`, `financas`, `beneficios`,
  `educacao`, `documentos`, `onde-comer`, `turismo`, `compras`, `negocios`,
  `servicos`, `voluntariado`, `categorias`, `europa`, `newsletter`,
  `revista`, `anuncie`, `search` and the legacy alias pages — narrative or
  utility pages, B1 until translated. B2 is never a catch-all and never a
  substitute for editorial translation; legal/sensitive pages are never put
  into B2 to "make them render".

Verified behaviour: allowlisted pages render PT content under the EN URL with
the approved English notice, canonical → PT, `hreflang` only `x-default`, and
are absent from the sitemap; unlisted, untranslated pages keep the B1 302
(`/en/moradia/`, `/en/europa/`, `/en/newsletter/`, `/en/anuncie/` → 302 →
PT). The B2 single-level safety rules (published only, `_event_status` gate
precedence for events, no fake EN records) remain the Stage 3.1 decision
point `conexao_should_render_b2_fallback()`. One gap was found and fixed:
`page.php` never rendered the B2 notice (only the CPT templates did), so
allowlisted pages rendered with the PT body but without the approved notice —
`page.php` now calls the same guarded `conexao_b2_fallback_notice()`.


## 6. Content migration pilot (Phase 4)

`scripts/stage32-translate-content.php` is the controlled migration framework
plus its pilot set: one or two records per content type in scope, chosen to
exercise each type's archive, single, filter, SEO and search paths. **Nothing
outside the explicit map is translated** — no bulk machine translation, no
automatic selection.

Per-type contract enforced by the script:

| Step | Rule |
|---|---|
| Source selection | explicit PT slug list in `stage32_content_pilot_map()` |
| EN authoring | human-authored English in the same map (title/content/meta description) |
| Linking | `pll_set_post_language()` + `pll_save_post_translations()`; link verified from **both** sides before the run reports OK |
| PT record | never modified (verified by the post-ID diff in §24) |
| Identity meta | copied verbatim per type allowlist (`stage32_meta_copy_map()`) — never invented |
| Featured media | the same attachment is shared (`set_post_thumbnail`), never duplicated/re-uploaded |
| Taxonomy | the linked EN term when one exists, otherwise the shared PT term |
| SEO | authored `conexao_meta_description`; canonical/hreflang verified at HTML level (§23) |
| Language switcher / archives / search / cache | verified (§12, §13, §17, §23) |

Pilot records created (all PT sources pre-existing):

| Type | PT master | EN translation | Notes |
|---|---|---|---|
| Guide | `como-tirar-o-pps-number` (80) | (134) `/en/guias/how-to-get-a-pps-number/` | featured image shared (attachment 133) |
| Guide | `como-abrir-conta-bancaria-na-irlanda` (81) | (135) `/en/guias/how-to-open-a-bank-account-in-ireland/` | |
| Blog post | `comunidade-celebra-festa-junina-em-dublin` (86) | (136) `/en/community-celebrates-festa-junina-in-dublin/` | native `category` EN term |
| Event | `festa-junina-dublin-2026` (90) | (137) `/en/eventos/festa-junina-dublin-2026-en/` | full identity meta shared; banner attachment shared |
| Event (source-inherited) | — | (**97** itself) `/en/eventos/irish-dance-workshop-dublin/` | the English-source record moved `pt→en` **in place** — no second record (architecture §8) |
| Lazer | `phoenix-park` (100) | (138) `/en/lazer/phoenix-park-en/` | internal classification + UUID preserved |
| Sponsor | `brasil-market-dublin` (108) | (139) `/en/apoiadores/brasil-market-dublin-en/` | `_sponsor_export_uuid` preserved |
| Course provider | `fetch-courses` (115) | (140) `/en/cursos/fetch-courses-en/` | `_provider_category` label translated on the EN record |
| Job | `ajudante-de-cozinha-dublin` (113) | (141) `/en/empregos/kitchen-assistant-dublin/` | |

Featured-media fixture: one deterministic local attachment (133,
`stage32-pilot-image`, generated with GD) is attached to the PT guide and PT
event so media preservation is exercised; both EN translations carry the same
attachment id (verified, 16/16 residual assertions in §22).

**Event-specific hard gate — verified.** The EN event translation:
does **not** receive a new `_event_source + _event_source_id` (identical
values to the master); does **not** receive a conflicting
`_event_export_uuid` (the master's UUID, shared verbatim); is **not** an
import target (`Conexao_Event_Importer_Language_Guard::is_import_target()`
false; the guarded upsert returns `skipped`, proven by the Stage 2 identity
test which still passes in the Stage 3.2 dataset — §22); does **not** alter
scheduling/lifecycle meta (same `_event_date`, `_event_start_time`,
`_event_status`); does **not** bypass `_event_status`; and no third record
exists for the identity (exactly 2 records: master + linked translation).

**Lazer-specific hard gate — verified.** `_leisure_export_uuid` identical on
master and translation; no duplicate Lazer record; the internal-page
classification (`_leisure_internal_page`) preserved; externally classified
records untouched (`conexao_leisure_external_url()` returns the same
destination before and after — Fota verified) and their external destination
is never changed for language reasons.

## 7. Event source-language handling (Phase 6 mechanism)

The mechanism distinguishes the three required states with an **explicit
signal only** — never title-text inference:

| State | Representation | Export value |
|---|---|---|
| Source content already English | `_event_source_language = en` | `"lang": "en"` |
| Source content Portuguese | `_event_source_language = pt` | `"lang": "pt"` |
| Source content another language | `_event_source_language = other` | `"lang": "other"` |
| Unknown / unclassified | meta **absent** | `"lang": "unknown"` |

- **Source of truth**: the `_event_source_language` post meta on the
  import-language record, owned by `conexao-event-runtime`
  (`Conexao_Event_Source_Language`, §19). The value is only ever stored when
  it is a valid classification; `unknown` is an export-only normalization of
  absence and is never written to the database.
- **Explicit signals consumed**: the importer normalizer accepts
  `$raw['source_language']` and validates it; the Eventbrite normalizer maps
  the source's own declared `locale` (`pt_BR` → pt, `en_IE` → en, anything
  else → other, absent → unclassified). No title/body heuristics exist.
- **Pilot source-language data** (explicit, from construction knowledge):
  eight Portuguese-source pilot events = `pt`; `irish-dance-workshop-dublin`
  (Eventbrite, English listing) = `en` and, as the source-inherited record,
  **is** the English record; `encontro-de-brasileiros-limerick` (manual
  event, no source) deliberately left unset = `unknown`.
- The meta is **never identity**: dedup matching (`_event_source` +
  `_event_source_id` → URL → content), UUIDs, scheduling and the
  `_event_status` gate do not read it.


## 8. Event identity verification (Phase 4/E + 8)

Verified with the Stage 2/3.1 identity suites re-run against the Stage 3.2
dataset (all local, real importer code, no mocks):

- `test-language-identity.php` — **27 passed, 0 failed**: the importer creates
  the PT master; re-import is `unchanged`; a linked EN translation shares the
  identity meta; dedup still resolves the PT master; the importer refuses to
  write to the translation (`skipped` + reason, content byte-identical); the
  identity exists exactly once per language and twice across languages.
- `test-stage32-bilingual.php` (new, theme) — the `pilot-eb-0001` identity
  exists **exactly twice** (master + linked translation) and exactly **one**
  export-UUID value pair is shared, with no other duplicate UUID anywhere.
- HTTP: `/en/eventos/festa-junina-dublin-2026-en/` is self-canonical with the
  full hreflang pair; the PT master URL `/eventos/festa-junina-dublin-2026/`
  is unchanged 200; `/en/eventos/festa-junina-dublin-2026/` (PT slug under
  `/en/`) 302 → the PT canonical, no loop.
- The source-inherited EN event serves at
  `/en/eventos/irish-dance-workshop-dublin/` (200, EN record); its PT URL
  `/eventos/irish-dance-workshop-dublin/` 302 → the EN canonical, terminating
  200 — no loop, no duplicate.
- Export: the EN translation is **never an export row** (one row per
  identity) — 57 assertions (§19).

**Hidden statuses stay hidden.** The EN events archive lists exactly the 7
public events (2 EN records + 5 PT B2 fallbacks) and none of the expired /
rejected / `source_not_found` events; EN search for those titles returns 0
hits; an expired event's EN URL 302 → its PT URL → 404 (the runtime's
`_event_status` gate working in both languages).

## 9. Lazer identity verification (Phase 4/L + 8)

- `test-language-uuid.php` — **14 passed, 0 failed**: `_leisure_export_uuid`
  byte-identical on master and translation; exactly one record per language
  carries it; `find_by_uuid()` resolves the PT master; `update_item()` refuses
  to write to a translation; `conexao_leisure_external_url()` returns the same
  destination before and after the translation exists.
- New assertions: exactly one shared `_leisure_export_uuid` value pair (the
  master + its translation), no other duplicates; internal/external
  classification preserved on the translation; Fota's external destination
  unchanged.
- EN archive: `/en/lazer/phoenix-park-en/` is a real EN record
  (self-canonical + pair); the PT master `/lazer/phoenix-park/` is a 200 PT
  record that is *replaced* by its translation in the EN archive (§13).


## 10. Taxonomy localization (Phase 5)

`scripts/stage32-translate-terms.php` creates the English term names required
by the pilot content — **only** the terms the introduced EN records actually
use. Human-authored names, genuine English slugs (WordPress uniqueness makes a
distinct slug mandatory for a distinct term), PT slugs and PT term IDs
untouched; Polylang term translations linked and verified from both sides:

| PT term (id) | EN term (id) | Taxonomy |
|---|---|---|
| documentos (11) | documents (173) | `conexao_category` |
| trabalho (83) | work (176) | `conexao_category` |
| financas (7) | finances (179) | `conexao_category` |
| festivais (80) | festivals (182) | `conexao_category` |
| musica (79) | music (185) | `conexao_category` |
| cultura (17) | culture (188) | `conexao_category` |
| natureza (15) | nature (191) | `conexao_category` |
| cidades (26) | cities (194) | `conexao_category` |
| negocios (13) | businesses (197) | `conexao_category` |
| gastronomia (81) | gastronomy (200) | `conexao_category` |
| empregos (3) | jobs (203) | `conexao_category` |
| comunidade (85) | community (206) | `category` (native blog) |

**Policy correction found by real content (hard-gate relevant).** With
county/town registered as Polylang-translated taxonomies (the Stage 2
interpretation), Polylang Free ≥ 3.5 auto-created a term translation the
first time a county/town term was assigned to an EN record
(`PLL_Crud_Posts::set_object_terms()` → `translate_term()` → `wp_insert_term`):
`dublin-en` in `conexao_county` (213) and in `conexao_town` (216), name copied
verbatim, linked as translations of the PT Dublin terms. Effects observed:
suffixed duplicate terms, duplicate filter-dropdown entries
(`cidade=dublin` **and** `cidade=dublin-en`), and split filter matching.

Root-cause fix, aligned with the decision's own §1 invariant
("Counties/towns are shared — no per-language duplicate terms"): county and
town taxonomies are **no longer Polylang-translated**
(`conexao_polylang_translated_taxonomies()` keeps `conexao_category` +
`conexao_tag`). One physical shared term per county/town; no language, no
translations, no auto-creation path.

`scripts/stage32-share-location-taxonomies.php` (idempotent) performs the
migration and the cleanup, and documents Polylang's storage split (posts →
`language`/`post_translations`; terms → `term_language`/`term_translations`),
because an early version of this cleanup matched POST ids while deleting TERM
relationships: it removed the `language` links of 16 **pages** (post ids
30–45 collide with county term ids). That collateral damage was detected by
the verification pass, and the final script (a) reassigns the suffixed
duplicate's objects to the shared sibling, (b) deletes the duplicates and
their links, (c) removes `term_language`/`term_translations` relationships
from every remaining county/town term (26 + 8), and (d) **self-heals any page
that lost its `language` relationship** (all 16 restored to `pt`). The
corrected state was re-verified end to end.

**Filter contract, verified empirically:**

| Filter | Result |
|---|---|
| `/en/eventos/?cidade=dublin` (shared town) | EN records **and** PT B2 records; one Dublin entry in the dropdown |
| `/eventos/?cidade=dublin` (PT) | PT records, unchanged |
| `/en/lazer/?county=dublin` (shared county) | EN record **and** PT B2 records |
| `/en/eventos/?categoria=festivais` (PT slug) | PT B2 records (brazilian-day-galway) |
| `/en/eventos/?categoria=festivals` (EN slug) | EN records (festa-junina-dublin-2026-en) |
| `/en/lazer/?categoria=natureza` (PT slug) | PT B2 Lazer records |
| `/en/lazer/?categoria=nature` (EN slug) | the EN Lazer record |
| `/en/guias/?categoria=documents` (EN slug) | EN guide records |
| `/eventos/?categoria=festivais` (PT) | PT records incl. the PT master, unchanged |

So: **PT-slug filters match PT records (B2 fallback included) in both language
contexts; EN-slug filters match EN records.** B2 records are never claimed to
match English slugs; county/town filters use the one shared slug in both
languages. The events archive's category pre-validation (`get_term_by`) was
language-scoped by Polylang and silently returned zero rows for PT slugs under
`/en/` — aligned to the `/lazer/` handler's language-neutral resolution so
both B2 archives behave identically. Taxonomy guarantees re-verified: no
duplicate term slugs, no suffixed terms in the shared taxonomies,
translated-taxonomy terms each carry exactly one language, shared county/town
terms carry none (`test-polylang-foundation.php`, §22).


## 11. Editorial translation state (Phase 7)

`wp-content/plugins/conexao-admin-ux/includes/class-translation-state.php`
(`Conexao_Admin_Ux_Translation_State`), wired from the plugin's main file at
`plugins_loaded` priority 20 — the smallest useful indicator built on the
existing Admin UX architecture, **not** a workflow engine (no statuses,
assignments, notifications, or Pro features).

State model, derived from actual Polylang links + editing state:

| State | Meaning |
|---|---|
| `missing` | the PT record has no English translation |
| `current` | a linked EN translation exists and carries no outdated flag |
| `outdated` | a linked EN translation exists and carries `_translation_outdated` |
| `not_applicable` | type outside the translated set, or Polylang inactive |

Documented rule behind the flag (real edit events — never content length or
timestamps alone): **saving a default-language (PT) record flags every linked
EN translation outdated** (importer updates included — a source change
invalidates the translation); **saving an EN translation clears its own
flag**; a newly created translation starts clean; revisions/autosaves never
touch it; the PT master never carries the flag.

Admin behaviour: an `EN` column on the list table of every translated post
type (label + link to the translation) and a **"Tradução (EN)"** editor meta
box — PT records show the English state, EN records show their relationship to
the PT source (title + link) and their own state; missing vs outdated are
visually distinct. Public non-exposure verified: `_translation_outdated` is
never registered for REST, no theme/front-end file reads it, and no SEO or
routing decision consumes it.

`wp-content/plugins/conexao-admin-ux/tests/test-translation-state.php` —
**22 passed, 0 failed** (§22).

## 12. Search (Phase 8)

- **PT search unchanged** (`/?s=…`): noindex, no hreflang, PT results as
  before.
- **EN search** (`/en/?s=…`): real EN records of any type + PT records of B2
  types only (never PT guides/blog/pages), hidden event statuses excluded by
  the SQL status-gate replication, and — new in Stage 3.2 — a PT master whose
  EN translation exists is **replaced by the translation**, never both
  (§13).
- Verified: `/en/?s=festa` → the EN post + the EN event translation (the PT
  master gone); `/en/?s=feijoada` → the PT B2 event; `/en/?s=Irish%20Dance` →
  the EN-native event; `/en/?s=rejeitado` / `?s=removido` / `?s=MPB` → 0 hits
  for the hidden statuses.
- The search exclusion set is computed by
  `conexao_b2_translation_replaced_pt_ids()` (briefly object-cached in the
  shared `conexao_filters` group, flushed from the existing save/delete/insert
  hub) and applied as `post__not_in`.

## 13. Archives (Phase 8)

- `/en/guias/` contains **only the two real EN guides** (B1 type — no PT
  fallback records).
- `/en/eventos/` lists exactly 7 public events: 2 EN records at `/en/…` URLs
  and 5 PT B2 fallbacks linking to their PT URLs; hidden statuses excluded;
  the PT event master replaced by its EN translation.
- `/en/lazer/`, `/en/apoiadores/`, `/en/cursos/`, `/en/empregos/`
  (job singles) show real EN records where they exist and PT B2 fallbacks
  otherwise — **a PT master with a published EN translation is hidden behind
  it** (verified for Lazer, Sponsors, Events; the events archive already had
  this rule inside `Conexao_Event_Query`).
- Fix detail: `conexao_b2_archive_widen_query()` (EN B2 archives) and
  `conexao_b2_search_widen_query()` (EN search) now exclude
  `conexao_b2_translation_replaced_pt_ids()`. For the sponsor archive, whose
  curated ordering uses `post__in`, the replacement ids are removed from the
  `post__in` list directly — WP_Query ignores `post__not_in` whenever
  `post__in` is set (core behaviour, documented in the code comment).
- No record appears twice in any EN archive; B1 content stays excluded where
  required; PT archives are unchanged.


## 14. SEO (Phase 8)

Single owner unchanged: `inc/seo.php` (+ its Polylang bridge in
`inc/polylang.php`).

| Context | Canonical | Indexable |
|---|---|---|
| `/`, `/eventos/`, `/guias/…`, `/lazer/…`, `/apoiadores/`, `/cursos/`, `/empregos/`, PT singles | self | yes |
| `/en/`, real EN pages/singles (`/en/about-us/`, `/en/guias/how-to-get-a-pps-number/`, `/en/eventos/festa-junina-dublin-2026-en/`, `/en/lazer/phoenix-park-en/`, `/en/apoiadores/brasil-market-dublin-en/`, `/en/cursos/fetch-courses-en/`, `/en/empregos/kitchen-assistant-dublin/`, `/en/community-celebrates-festa-junina-in-dublin/`) | **self (EN)** | yes |
| B2 fallbacks (`/en/irlanda/`, `/en/dublin/`, `/en/empregos/oportunidades/`) | **the PT record's own URL** | yes, but not offered as an EN sitemap URL |
| B1 untranslated (`/en/moradia/`, `/en/europa/`, `/en/blog/`) | redirect-only (302 → PT) | no (never renders) |
| Search/404 | no canonical hreflang; search + 404 noindex | no |

`og:locale` on EN pages is `en_US` (dynamic, through the Stage 1 extension
point); `<html lang>` is `en-US` on EN and `pt-BR` on PT.

## 15. hreflang (Phase 8)

- Real translation pairs: `hreflang="en"` → EN URL, `hreflang="pt-BR"` → PT
  URL, `hreflang="x-default"` → the PT URL (default language). Verified on
  `/` ⇄ `/en/`, every translated page pair, the EN guide/event/leisure/
  sponsor/course/job pilots, and the EN blog post (homepage + object pairs).
- PT home emits the `en` alternate pointing at `/en/` (the real homepage pair).
- B2 fallbacks emit **`x-default` only** (the PT URL) — no alternate to a URL
  that would redirect; the EN shell is not advertised as a translation.
- A source-inherited EN record with no PT counterpart (e.g. `/en/eventos/irish-dance-workshop-dublin/`) emits **self-only** `hreflang="en"` — no invented alternate, no `x-default` pointing at a non-existent pair.
- B1 redirect-only URLs emit nothing (they never render).
- No duplicate hreflang tags; no alternate pointing at a redirecting URL;
  `x-default` always the default-language URL.

## 16. Sitemap (Phase 8)

Local producer: the theme's `/sitemap.xml` (Jetpack is not installed in this
environment — Stage 3.1 measured the theme producer the same way, with
Jetpack deactivated).

- **107 `<loc>` entries, 0 duplicates.**
- **14 EN URLs** — the real EN content only: `/en/`, the 7 EN pages, the EN
  guides/post/event/leisure/sponsor/course/job records.
- **0 B2 fallback URLs**: `/en/irlanda/`, `/en/dublin/`, … and every PT
  record rendered as an EN fallback are absent (they have no EN record).
- Externally classified Lazer records stay excluded, as before.
- The homepage entry declares the real `/` ⇄ `/en/` pair via
  `xhtml:link` alternates.
- Fixed defect: the PT front-page *page* was emitted a second time as `<loc>/`
  (the front page's own permalink is the homepage URL — pre-existing behaviour
  now exposed by verification); the pages loop skips `page_on_front`, so `/`
  appears exactly once. `/inicio/` was never emitted (redirect target).

## 17. Cache behaviour (Phase 8)

- All homepage/404/event-query caches remain language-keyed
  (`conexao_*_pt` / `conexao_*_en`); verified concretely: after warming `/`,
  only the `_pt` keys exist; after warming `/en/`, the `_en` keys exist
  **alongside** the untouched `_pt` keys — no overwrite, no leakage.
- The event-runtime ordered-ID transient remains date- and language-keyed
  (`conexao_event_upcoming_YYYYMMDD_{lang}`), flushed for every language on
  save.
- New caches introduced in this stage: `conexao_b2_replaced_{types}` in the
  shared `conexao_filters` object-cache group (300 s like its siblings),
  flushed by `conexao_homepage_cache_invalidate()` on save/delete/insert.
- The language-list cache (`pll_languages_list` transient) is self-healing for
  the declared EN home URL (§3).


## 18. Redirect behaviour (Phases 2/9)

**Legacy map unchanged.** `.htaccess` and the theme's `conexao_seo_redirects()`
map body are byte-identical (git-verified; no entry removed, reordered or
retargeted).

**Layer note (environment).** The theme layer was exercised over HTTP in this
environment; the `.htaccess` layer cannot run under PHP's built-in server (no
Apache) and is therefore verified as *unchanged*, not as *executed*. The two
layers implement the same legacy map (Stage 2 established the layering), so
theme-layer results are the strongest available local evidence; §23 marks the
`.htaccess`-only rows NOT_TESTABLE.

**Defect found and fixed — legacy precedence.** With EN pages now owning the
slugs `jobs`, `about-us` and `contact`, Polylang's language canonical
(`template_redirect` priority **4**) resolved `/jobs/`, `/about-us/`,
`/contact/` to those EN pages and redirected 302 → `/en/…`, *before* the
theme's legacy map (priority **5**) could apply the production 301s.
Reproduced in the matrix, root-caused, and fixed by moving
`conexao_seo_redirects()` to `template_redirect` priority **2** (before
Polylang, before the missing-translation rule at 6, which still runs after).
The map itself is untouched — only its precedence.

**Verified after the fix (theme layer):**

| Legacy URL | Result |
|---|---|
| `/guides/`, `/events/`, `/courses/`, `/sponsors/`, `/ireland/`, `/about/`, `/privacidade/`, `/termos/`, `/sobre/`, `/guias-praticos/`, `/counties/dublin/`, `/categories/moradia/`, `/empresas/` | **301 → the correct PT URL** (unchanged) |
| `/jobs/`, `/about-us/`, `/contact/` | **301 → `/empregos/`, `/sobre-nos/`, `/contato/`** (production contract restored) |
| `/post/festa-junina-dublin-2026/` | **301 → `/blog/festa-junina-dublin-2026/`** (legacy step; pre-existing chain to the post URL) |
| `/en/` | 200 (homepage); `/en/home/` 301 → `/en/` |
| B2 singles `/en/irlanda/`, `/en/dublin/`, `/en/empregos/oportunidades/` | 200 (no redirect) |
| B2 archives `/en/eventos/`, `/en/lazer/`, `/en/apoiadores/`, `/en/cursos/` (+ filters) | 200 |
| B1 pages not yet translated (`/en/moradia/`, `/en/europa/`, `/en/blog/`) | 302 → PT |
| translated taxonomy URLs (`/en/guias/?categoria=documents`) | 200 |
| real EN translations | never redirect to PT (all 200 self-canonical) |

**Loop audit:** all 27 redirecting URLs in the matrix followed hop-by-hop —
**0 loops**; 26 terminate in 200; the one non-200 termination is
`/en/eventos/noite-de-mpb-bray/` → 302 → `/eventos/noite-de-mpb-bray/` → 404,
which is the *expired-event status gate* working as designed (the PT URL of an
expired event is a 404 for public requests), not a broken chain.


## 19. Export JSON changes (Phase 6)

Additive only, in `conexao-event-importer`:

- New per-event top-level field **`"lang"`** in `export_event()` —
  `pt` | `en` | `other` | `unknown` (absence → `unknown`);
- `_event_source_language` added to the exporter's meta key list (so the
  classification also travels inside the `meta` bag) and to the transfer
  importer's meta allowlist (so production records receive it on import);
- The export query is constrained to the **import language** when Polylang is
  active, so linked EN translations are never exported as extra identity rows;
- Existing fields (`uuid`, `post`, `meta`, `taxonomies`, `featured_image`,
  manifest) are unchanged — backward compatible with existing consumers;
- No importer identity semantics changed; the export does not mutate event
  content or meta beyond the pre-existing UUID provisioning;
- Flutter does **not** depend on the field in Stage 3.2, and no Flutter REST
  contract was implemented.

Focused test `tests/test-export-language.php` — **57 passed, 0 failed**,
covering: English source, Portuguese source, unknown/unclassified, legacy
event with no language metadata, export compatibility (all pre-existing fields
+ no duplicate UUID), translation exclusion (an EN translation is never a
row), no-mutation snapshots, importer write paths (explicit signal only — an
entirely English title with no signal stays `unknown`), and normalizer /
Eventbrite-locale mapping.

## 20. Exact files changed

Modified (12):

| File | Change |
|---|---|
| `wp-content/themes/conexao-br-irlanda/inc/polylang.php` | EN home URL declaration + self-heal (§3); B2 page allowlist (§5); `conexao_b2_translation_replaced_pt_ids()` (§13) |
| `wp-content/themes/conexao-br-irlanda/inc/seo.php` | legacy redirect map priority 5 → 2 (§18); sitemap front-page duplicate fix (§16) |
| `wp-content/themes/conexao-br-irlanda/functions.php` | B2 archive/search replacement exclusion + `post__in` handling (§13); language-neutral event category pre-validation (§10) |
| `wp-content/themes/conexao-br-irlanda/page.php` | render the B2 notice on allowlisted pages (§5) |
| `wp-content/themes/conexao-br-irlanda/tests/test-polylang-foundation.php` | updated to the Stage 3.2 taxonomy policy + translation-group identity assertions (§10, §22) |
| `wp-content/plugins/conexao-event-runtime/conexao-event-runtime.php` | require + register `_event_source_language` (§7) |
| `wp-content/plugins/conexao-event-importer/includes/class-event-normalizer.php` | `source_language` pass-through + validation (§7) |
| `wp-content/plugins/conexao-event-importer/includes/class-eventbrite-normalizer.php` | explicit `locale` → source language (§7) |
| `wp-content/plugins/conexao-event-importer/includes/class-event-importer.php` | write `_event_source_language` on upsert (§7) |
| `wp-content/plugins/conexao-event-importer/includes/class-event-export.php` | `lang` field, meta key, import-language constraint (§19) |
| `wp-content/plugins/conexao-event-importer/includes/class-event-import.php` | meta allowlist round-trip (§19) |
| `wp-content/plugins/conexao-admin-ux/conexao-admin-ux.php` | require + init the translation-state module (§11) |

Added (10):

| File | Purpose |
|---|---|
| `wp-content/themes/conexao-br-irlanda/tests/test-stage32-bilingual.php` | Stage 3.2 focus tests (42 assertions) |
| `wp-content/plugins/conexao-event-runtime/includes/class-source-language.php` | `Conexao_Event_Source_Language` (§7) |
| `wp-content/plugins/conexao-event-importer/tests/test-export-language.php` | export language contract tests (57 assertions) |
| `wp-content/plugins/conexao-admin-ux/includes/class-translation-state.php` | editorial translation-state indicator (§11) |
| `wp-content/plugins/conexao-admin-ux/tests/test-translation-state.php` | indicator tests (22 assertions) |
| `scripts/stage32-seed-pilot.php` | local pilot PT catalogue (§2) |
| `scripts/stage32-translate-pages.php` | approved page translations (§4) |
| `scripts/stage32-translate-content.php` | controlled content-migration framework + pilot (§6) |
| `scripts/stage32-translate-terms.php` | EN term translations (§10) |
| `scripts/stage32-share-location-taxonomies.php` | county/town shared-term migration + cleanup (§10) |

Documentation updated in the same change (per AGENTS.md): `docs/routing.md`
(§ English rollout state), `docs/plugins/conexao-event-runtime.md`,
`docs/plugins/conexao-event-importer.md`, `docs/plugins/conexao-admin-ux.md`,
`AGENTS.md` (taxonomy language policy line), plus this report.

## 21. Exact files intentionally not changed

- `.htaccess` — untouched (legacy redirects preserved verbatim).
- `inc/i18n.php` (Stage 1 extension points), `inc/search.php`,
  `inc/post-views.php`, `inc/employment-opportunities.php`,
  `inc/recruitment-agencies.php`, `inc/permit-employers.php`,
  `inc/empregos-landing.php`, `inc/job-resources.php` — no redesign of
  search, filters, CPTs, routes or REST endpoints.
- `conexao-data-model` (CPTs/taxonomies/meta), `conexao-content` (page
  seeding), `conexao-leisure-migration`, `conexao-sponsor-migration` — no
  identity-model or migration-tool changes.
- `conexao-event-runtime` gate logic (`Conexao_Event_Status`, the
  `pre_get_posts` gate, `Conexao_Event_Query`) — unchanged; only the new
  source-language class + meta registration were added.
- The importer's deduplication/upsert **semantics** — only the additive
  source-language write and the export constraint were added.
- `CONEXAO_BR_ENGLISH_STAGE_1/2/3_1_REPORT.md`, the audit and the architecture
  decision — no history rewritten.
- No Polylang files, no Polylang Pro features, no new REST layer, no new
  search engine, no new caching system, no second SEO owner.


## 22. Tests and exact results (Phase 11)

`php -l` on every changed/added PHP file: **22/22 clean** (§20 lists the
files). `git diff --check`: clean.

| Suite | Result |
|---|---|
| theme `test-polylang-foundation.php` (updated) | **61–62 passed, 0 failed** (the count reflects the sampled event's real translation-group size) |
| theme `test-stage32-bilingual.php` (new) | **42 passed, 0 failed** |
| theme `test-i18n-foundation.php` | **29 passed, 0 failed** (needed the core pt_BR language pack — environment gap, fixed) |
| theme `test-event-location-filters.php` | 46 passed, 0 failed |
| theme `test-guide-breadcrumb-filter.php` | 36 passed, 0 failed |
| theme `test-leisure-attribute-normalization.php` | 22 passed, 0 failed |
| theme `test-leisure-card-map-action.php` | 44 passed, 0 failed |
| theme `test-leisure-multiselect-filters.php` | 63 passed, 0 failed |
| theme `test-leisure-related-events.php` | 19 passed, 0 failed |
| theme `test-recurrence-i18n.php` | 12 passed, 0 failed |
| theme `test-sponsor-archive-ordering.php` | 15 passed, 0 failed |
| importer `test-language-identity.php` (identity gate) | **27 passed, 0 failed** |
| importer `test-export-language.php` (new) | **57 passed, 0 failed** |
| importer `test-county-registry.php` | 473 passed, 0 failed |
| importer `test-past-event-filter.php` | 29 passed, 0 failed |
| importer `test-error-handling.php` | 55 passed, 0 failed |
| importer `test-import-log.php` | 53 passed, 0 failed |
| importer `test-event-address.php` | 76 passed, 0 failed |
| importer `test-event-image-sync.php` | 30 passed, 0 failed |
| importer `test-eventbrite-importer.php` | 68 passed, 0 failed |
| importer `test-reappearing-event-lifecycle.php` (public mode) | ALL PASS, 0 failures |
| importer `test-reappearing-event-lifecycle.php` (CLI mode, via `wp eval-file`) | ALL PASS, 0 failures |
| importer `test-town-sanitization.php` | 30 passed, **3 failed** — see below |
| runtime `test-event-language-gate.php` (status gate) | **16 passed, 0 failed** |
| runtime `test-event-query.php` | 41 passed, 0 failed |
| runtime `test-event-recurrence.php` | 90 passed, 0 failed (after setting `Europe/Dublin`; 2 pre-fix failures were the empty-timezone environment gap) |
| runtime `test-plugin-separation.php` (`with-tooling`) | 34 passed, 0 failed |
| leisure `test-language-uuid.php` (Lazer UUID gate) | **14 passed, 0 failed** |
| admin-ux `test-translation-state.php` (new) | **22 passed, 0 failed** |
| residual Phase 4 verification script | 16 passed, 0 failed |

Documented exceptions (not regressions):

- **`test-town-sanitization.php` — 30/3.** All three failures are
  *database-existence expectations for historical production data*
  (`term_exists('Oranmore'|'Cobh'|'Corofin', 'conexao_town')`); a fresh pilot
  catalogue contains only the eight seeded towns. Every sanitization/ensure
  logic assertion passes (the test creates `Ballinamore`/`Dublin` itself, and
  those pass). The test file is unchanged by this stage.
- **`test-plugin-separation.php` (`no-tooling` mode) — 24/6.** That mode
  asserts the importer classes are *not loaded*, which cannot hold here
  because the importer plugin is intentionally active (the local stack mounts
  it, exactly as `compose.yaml` does). `with-tooling` — the applicable mode —
  is 34/0.


## 23. HTTP verification matrix

84 URLs fetched with `curl` (status, redirect target, canonical, hreflang,
`html lang`, fallback notice, robots, cache keys). Highlights; the raw
machine-readable matrix is at `/tmp/env/http-matrix.json` (local only).

| URL | Status | Notes |
|---|---|---|
| `/` | 200 | `lang=pt-BR`, self-canonical, hl `pt-BR,en,x-default` |
| `/inicio/` | 301 → `/` | |
| `/eventos/` `/guias/` `/lazer/` `/apoiadores/` `/cursos/` `/empregos/` `/blog/` | 200 | self-canonical, hl pair |
| `/guias/como-tirar-o-pps-number/` | 200 | PT master unchanged |
| `/lazer/phoenix-park/`, `/apoiadores/…`, `/eventos/festa-junina-dublin-2026/` | 200 | PT masters unchanged |
| `/eventos/irish-dance-workshop-dublin/` | 302 → `/en/eventos/irish-dance-workshop-dublin/` | source-inherited EN record |
| `/irlanda/`, `/dublin/`, `/politica-de-privacidade/`, `/termos-de-uso/`, `/cookies/`, `/sobre-nos/`, `/contato/` | 200 | PT originals |
| `/eventos/?cidade=dublin`, `/eventos/?categoria=festivais`, `/lazer/?county=dublin`, `/lazer/?categoria=natureza`, `/guias/?categoria=documentos` | 200 | PT filters unchanged |
| `/?s=festa` | 200 | noindex, no hreflang |
| `/en/` | 200 | `lang=en-US`, canonical `/en/`, hl `en,pt-BR,x-default` |
| `/en/home/` | 301 → `/en/` | |
| `/en/about-us/`, `/en/contact/`, `/en/jobs/`, `/en/privacy-policy/`, `/en/terms-of-use/`, `/en/cookie-policy/` | 200 | real translations, self-canonical, hl pair |
| `/en/guias/how-to-get-a-pps-number/` | 200 | switcher → `/guias/como-tirar-o-pps-number/`; `og:locale=en_US` |
| `/en/eventos/festa-junina-dublin-2026-en/` | 200 | real EN event translation, hl pair |
| `/en/eventos/irish-dance-workshop-dublin/` | 200 | source-inherited EN event |
| `/en/eventos/festa-junina-dublin-2026/` | 302 → `/eventos/festa-junina-dublin-2026/` | PT slug under `/en/` |
| `/en/guias/como-tirar-o-pps-number/` | 302 → `/guias/como-tirar-o-pps-number/` | PT slug under `/en/` |
| `/en/lazer/phoenix-park-en/`, `/en/apoiadores/brasil-market-dublin-en/`, `/en/cursos/fetch-courses-en/`, `/en/empregos/kitchen-assistant-dublin/`, `/en/community-celebrates-festa-junina-in-dublin/` | 200 | real EN records, self-canonical |
| `/en/lazer/phoenix-park/` | 301 → `/lazer/phoenix-park/` | PT master replaced by translation (never both) |
| `/en/irlanda/`, `/en/dublin/` | 200 | B2: canonical → PT, hl `x-default` only, **notice present** |
| `/en/empregos/oportunidades/` | 200 | B2 job, notice present |
| `/en/moradia/`, `/en/europa/`, `/en/blog/` | 302 → PT | B1 |
| `/en/guias/` | 200 | only the two real EN guides |
| `/en/eventos/` | 200 | 2 EN records + 5 PT B2 fallbacks, hidden statuses absent |
| `/en/lazer/`, `/en/apoiadores/`, `/en/cursos/` | 200 | real EN + PT B2 fallbacks, no duplicates |
| `/en/eventos/?cidade=dublin`, `/en/lazer/?county=dublin` | 200 | shared terms match both languages' records |
| `/en/eventos/?categoria=festivals`, `/en/lazer/?categoria=nature`, `/en/guias/?categoria=documents` | 200 | EN-slug filters match EN records |
| `/en/eventos/?categoria=festivais` | 200 | PT-slug filter matches PT B2 records |
| `/en/?s=festa`, `/en/?s=feijoada`, `/en/?s=Irish%20Dance` | 200 | EN results; no duplicates; no hidden statuses |
| `/guides/`, `/events/`, `/courses/`, `/sponsors/`, `/ireland/`, `/about/`, `/privacidade/`, `/termos/`, `/sobre/`, `/guias-praticos/`, `/counties/dublin/`, `/categories/moradia/`, `/empresas/` | 301 → PT | theme redirect layer |
| `/jobs/`, `/about-us/`, `/contact/` | 301 → `/empregos/`, `/sobre-nos/`, `/contato/` | **fixed precedence** |
| `/nonexistent-page/`, `/en/nonexistent-page/` | 404 | |

Cache-behaviour rows: after warming `/` only `conexao_home_*_pt` (and the
language-keyed event transient `_pt`) exist; after warming `/en/` the `_en`
keys appear without touching `_pt` — no cross-language leakage in either
direction (§17).

**NOT_TESTABLE locally:** the `.htaccess` half of the legacy redirect layer
(no Apache in this environment); browser/device rendering (§25).

## 24. Production safety

- Nothing was deployed, uploaded, or configured on production; no
  WordPress.com interaction occurred in this stage.
- Production data/settings were never read or written; the local environment
  is entirely synthetic (pilot catalogue + repo tooling).
- `git status` on the tracked tree shows only Stage 3.2 source/doc changes;
  the local-only `wp-load.php` shim and everything under `/tmp/env` are
  outside the repository.
- **Portuguese content proven untouched**: all 77 PT records of the pilot
  catalogue (pages 4–45, posts 80–114) were compared field-by-field
  (`post_title`, `post_content`, `post_excerpt`, `post_status`) against the
  pre-change checkpoint dump — **0 differences**. PT URLs were separately
  verified byte-stable over HTTP (§23).
- Local rollback: restore `/tmp/env/stage32-baseline.sql` (pre-change
  checkpoint) + discard the working-tree changes. No production artefact
  depends on this stage.


## 25. Browser/device status

**NOT TESTABLE — no browser or device tooling is available in this
environment.** No screenshots, no viewport/DPR checks, no layout regression
tooling. All rendering assertions in this report are HTML-level (`curl`),
status-code level, or PHP-level. This is the same limitation Stage 2 and
Stage 3.1 recorded, and no claim of visual validation is made anywhere.

## 26. Known limitations

1. **Browser/device validation NOT TESTABLE** (§25) — HTML-level evidence only.
2. **`.htaccess` redirect layer not locally executable** (no Apache). It is
   git-verified unchanged and mirrors the theme layer (which was tested); a
   production smoke test of the root-level 301s remains part of the
   deployment plan (§27).
3. **Jetpack sitemap reconciliation is plan-only.** Locally the theme
   producer serves the sitemap (Stage 3.1 measured the same way, with Jetpack
   deactivated). On production Jetpack owns `/sitemap.xml`, so the theme
   producer's EN entries and alternates are not observable there until
   Jetpack is reconciled (§27 step 11). Not a defect — an unfinished
   production task.
4. **EN event translations are not transferred by the export JSON.** The
   export carries one row per identity (by design); linked EN translations
   are editorial records and their production transfer is a later-stage
   concern (§27 step 4 and Stage 3.3).
5. **EN homepage quick-access cards still link to PT URLs** (`/empregos/`,
   `/lazer/`, `/eventos/`, `/cursos/`, `/apoiadores/`, `/blog/`). These are
   hardcoded theme paths resolved with `home_url()`, which Polylang does not
   prefix (Polylang filters only the bare home URL). The nav menu and the
   language switcher are correct (verified); the primary nav's Jobs item
   links `/en/jobs/`. Concrete next step for Stage 3.3: a
   `conexao_lang_url()` helper mapping hardcoded paths to their translation
   when one exists, adopted by the front-page card arrays.
6. **`/en/{pt-slug}/` for a translated record 302s to the PT URL** rather
   than to the EN translation (`/en/sobre-nos/` → `/sobre-nos/`,
   `/en/guias/como-tirar-o-pps-number/` → the PT guide). This is the
   Stage 3.1-documented B1 behaviour for PT slugs under `/en/` (PT slugs are
   canonical; the EN URL is the EN record's own slug). Kept unchanged here —
   no gate requires it — with a Stage 3.3 option to redirect slug-mismatch
   requests to the real EN translation instead.
7. **`test-town-sanitization.php` 3 dataset-existence expectations** and
   **`test-plugin-separation.php` `no-tooling` mode** are not satisfiable in
   this environment (§22); neither touches Stage 3.2 code paths.
8. **No Polylang Pro features** were used or installed; the editorial
   workflow is the minimal in-repo indicator (§11), not the Pro translation
   editor.


## 27. Production deployment plan (Phase 10 — written, NOT executed)

Nothing below was done on production. Every step is manual on WordPress.com;
local WordPress settings cannot simply be copied.

**Preconditions**
- Confirm the WordPress.com plan supports uploading plugin ZIPs (Business or
  higher) — required for Polylang and the changed plugins.
- Confirm production PHP ≥ 7.4 (Polylang requirement); record the value.
- Full WordPress.com backup + save the current `/sitemap.xml` and
  `/news-sitemap.xml` for later comparison.
- Plan a change window; no downtime expected, but the first requests after
  activation rebuild caches.

**1. Polylang installation / activation (manual)**
- Install **Polylang 3.8.9 (Free)** from the WordPress.org directory in
  wp-admin (not from a local copy; **not** Pro).
- Activate and configure in wp-admin → Languages:
  - add `pt_BR` (slug `pt`) **first** so it becomes the default; add `en_US`
    (slug `en`);
  - Settings → URL modifications: language in a **directory** (`force_lang=1`),
    **hide default language** (`hide_default=true`), pretty permalinks,
    **browser detection OFF**, `redirect_lang` OFF, media **not** translated.
- Assign the default language to all existing content through Polylang's
  "assign untranslated contents" action (never hand-assign; never duplicate).
- Check Settings → General afterwards: site language `Português do Brasil`
  and timezone **Europe/Dublin** (the local suite is timezone-sensitive).

**2. Required language configuration (manual verification)**
- `pll_home_url('pt')` = `https://conexaobr.ie/`;
  `pll_home_url('en')` = `https://conexaobr.ie/en/` — the latter requires the
  English front page to be the linked translation of the PT one (step 3).

**3. English static-front-page configuration**
- WordPress.com does not allow theme-file edits: the declaration filters ship
  with the theme ZIP. Create the English homepage as a linked translation of
  the PT front page (slug `home`, title `Home`).
- Verify: `/en/` 200 serving the English homepage directly, `/en/home/` 301 →
  `/en/`, `/inicio/` 301 → `/`, canonical + hreflang as in §14/§15.
- If `/en/` still redirects to `/en/home/`, the deployed theme ZIP is not the
  Stage 3.2 theme (the self-heal guard is missing).

**4. Translation / content migration sequence**
- Order matters: **terms first, then content** (an untranslated term assigned
  to an EN record makes Polylang auto-create a same-name copy — the behaviour
  Stage 3.2 removed for proper nouns and must avoid for categories).
- Per approved batch: (1) EN term translations
  (`scripts/stage32-translate-terms.php` is the local template); (2) the
  approved page translations (`scripts/stage32-translate-pages.php`); (3)
  content per type via the migration framework
  (`scripts/stage32-translate-content.php`): guides, blog, events, Lazer,
  sponsors, course providers, jobs.
- Production transfer of translations is **manual/editorial** in Stage 3.2
  (local `wp eval-file` scripts are unavailable on WordPress.com). Linked EN
  records must be created as Polylang translations of the PT records — never
  as independent records — and identity meta (event source/source_id/UUID;
  Lazer/sponsor UUIDs) copied verbatim, exactly as the local framework does.
- Hidden-status events must never be published to make a translation exist.

**5. Required taxonomy relationships**
- `conexao_category` / `conexao_tag`: verify Polylang lists them as
  **translated taxonomies** (the theme declares them in code via
  `pll_get_taxonomies`; the settings screen must not override).
- `conexao_county` / `conexao_town`: must **not** be translated taxonomies
  (Stage 3.2 policy) — one shared term per county/town, no `-en` copies,
  identical filters in both languages. If production already carries `*-en`
  county/town terms, clean them up with an equivalent of
  `scripts/stage32-share-location-taxonomies.php` before publishing links.


**6. Event source-language metadata rollout**
- Deploy the runtime + importer changes: new imports populate
  `_event_source_language` from explicit source signals only.
- **Backfill** for pre-existing events (meta absent → `unknown` in exports) is
  a deliberate, reviewed manual/CLI step for known-English sources. Do not
  infer language from titles.

**7. Export JSON compatibility**
- Existing consumers keep working: additive field, manifest and all existing
  fields unchanged. Verify with one export run: every event row has `lang` ∈
  {pt,en,other,unknown}, no duplicate identity rows, and the import side
  stores `_event_source_language` when present.

**8. Editorial-state deployment**
- Admin-only and inert without Polylang; ships with the Admin UX plugin ZIP.
  No settings, no migrations.

**9. Plugin/theme deployment (manual ZIP uploads)**
- Build ZIPs locally from the Stage 3.2 tree
  (`./scripts/build-plugins-zip.sh`, `./scripts/build-theme-zip.sh`) and
  upload: theme `conexao-br-irlanda`, plugins `conexao-event-runtime`,
  `conexao-admin-ux` (the local-only `conexao-event-importer` need not be
  active on production).
- Deploy order in the window: **theme** (front-page declaration, B2 allowlist,
  redirect precedence, sitemap fix) → **runtime plugin** (new meta +
  source-language class) → **Admin UX** (indicator); Polylang first if not yet
  installed.

**10. Cache flush sequence**
- After activation: flush the Polylang language cache (saving any language
  setting does it), purge the WordPress.com edge cache for `/`, `/en/` and the
  archives, and delete stale un-suffixed legacy `conexao_*` transients.
  Re-warm `/` and `/en/` and confirm each renders its own language.

**11. Jetpack sitemap reconciliation**
- Jetpack owns production `/sitemap.xml`. Choose ONE producer: keep Jetpack
  and enable the multilingual alternates it supports, or disable Jetpack's
  sitemap module and let the theme producer (already EN-aware) serve it.
  Afterwards verify: real EN URLs present, B2 fallback URLs absent, no
  duplicate `<loc>`, alternates only for real pairs, PT set unchanged.

**12. Rollback procedure**
- Deactivate Polylang → the site returns to single-language PT exactly as
  before (all theme/plugin guards are no-ops without Polylang; deactivation
  does not touch PT content, URLs or identity meta).
- Code-level rollback: restore the previous plugin/theme ZIPs from the
  WordPress.com backup. Full rollback: restore the database backup.
- Post-rollback checks: `/` unchanged, PT archives/singles 200, legacy
  redirects 301, sitemap back to its pre-stage content.

## 28. Final acceptance matrix (Phase 12)

| # | Hard gate | Result | Evidence |
|---|---|---|---|
| 1 | `/en/` serves the English homepage directly | **PASS** | §3 (200, front-page template; `/en/home/` 301 → `/en/`) |
| 2 | No PT URL changed unintentionally | **PASS** | §24 (77/77 records byte-equal; PT URLs 200/301 unchanged) |
| 3 | No duplicate Event source identity | **PASS** | §8 (identity count 2: master + linked translation; identity test 27/0) |
| 4 | No duplicate Event UUID | **PASS** | §8, §22 (one shared pair, no other duplicates) |
| 5 | No duplicate Lazer export UUID | **PASS** | §9 (14/0; one shared pair) |
| 6 | Hidden Event status not public in EN | **PASS** | §8 (archive/search 0 hits; gate test 16/0) |
| 7 | No real EN translation redirects to PT | **PASS** | §23 (all EN translations 200 self-canonical) |
| 8 | B1 not accidentally replaced by B2 | **PASS** | §5, §23 (`/en/moradia/`, `/en/europa/`, `/en/blog/` 302 → PT; allowlist exhaustive) |
| 9 | B2 not a catch-all | **PASS** | §5 (`conexao_b2_page_allowlist()` = irlanda + 9 county pages only) |
| 10 | No taxonomy duplication / suffixed identity terms | **PASS** | §10 (`dublin-en` removed; 0 suffixed terms; foundation test 62/0) |
| 11 | Canonical/hreflang consistent with the architecture | **PASS** | §14, §15, §23 |
| 12 | No B2 fallback URL in the sitemap | **PASS** | §16 (0 B2 URLs; 107 locs, 0 duplicates) |
| 13 | No PT/EN cache leakage | **PASS** | §17 (language-keyed keys, no overwrite/leak) |
| 14 | Importer does not create translations from front-end requests | **PASS** | no front-end write path exists; guard tests unaffected (§22) |
| 15 | Existing legacy redirects not broadly changed | **PASS** | §18 (map byte-identical; only precedence fixed) |
| 16 | Production untouched | **PASS** | §24 (no deploy, no upload, no production settings) |
| — | Event identity gate (supporting) | **PASS** | 27/0 (§22) |
| — | Event status gate | **PASS** | 16/0 + HTTP (§22) |
| — | Lazer UUID dedup | **PASS** | 14/0 (§22) |
| — | Export JSON (lang) | **PASS** | 57/0 (§19, §22) |
| — | Editorial translation state | **PASS** | 22/0 (§11, §22) |
| — | Browser/device | **NOT_TESTABLE** | no tooling (§25) |
| — | Production deployment | **NOT EXECUTED** | by design (Phase 10 plan only) |
| — | Flutter REST contract | **NOT IMPLEMENTED** | out of scope by instruction |

## 29. Final classification

**ENGLISH STAGE 3.2 — PASS WITH LIMITATION**

All sixteen hard gates pass, the stage's ten objectives are delivered, and the
three defects that real English content exposed were fixed at the root cause
with regression coverage. The limitations are explicit, do not violate any
hard gate, and each has a concrete next step: browser/device validation is
NOT TESTABLE in this environment; the `.htaccess` redirect layer could not be
executed locally (verified unchanged); the production Jetpack sitemap
reconciliation and the production deployment itself are plan-only by design;
a fresh pilot catalogue cannot satisfy three dataset-existence expectations
in the town-sanitization suite; and two pre-existing chrome/redirect-shape
nits (EN homepage card links, `/en/{pt-slug}/` redirect targets) are
documented with Stage 3.3 fix paths.

