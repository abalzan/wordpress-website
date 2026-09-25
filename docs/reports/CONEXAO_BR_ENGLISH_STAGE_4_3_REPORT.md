# CONEXAO_BR_ENGLISH_STAGE_4_3_REPORT.md

Stage 4.3 of the approved English-support architecture
(`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`, `CONEXAO_BR_ENGLISH_SUPPORT_AUDIT.md`, Stage 1/2/3.x
reports, and the frozen Stage 4.1 REST contract used by the frozen Stage 4.2 Flutter client):
make the **WordPress website** production-ready for bilingual English support.

**Scope:** WordPress only. The Flutter app (`abalzan/conexaobr_app`) was **not** accessed, not
modified, not built and not tested (see §27).

---

## 1. Status

**ENGLISH STAGE 4.3 — BLOCKED**

**Why BLOCKED (hard gate #2, Phase 21):** on production, `/en/` does **not** serve the English
homepage. Measured 2026-09-23: `GET /en/` → `301` →
`https://conexaobr.ie/eventos/encore-halloween-edition-special-guest-1926/` (WordPress core
`redirect_guess_404_permalink()` matching `post_name LIKE 'en%'` — i.e. `/en/` is simply a 404).
`/en/home/` behaves the same way, `/en/about-us/`, `/en/contact/`, `/en/privacy-policy/`,
`/en/terms-of-use/`, `/en/cookie-policy/` are 404, and four of the five English archives are 404.

**Why the gate cannot be closed in this environment:** the whole English layer is *code*
(theme PHP + Polylang), and production is WordPress.com-managed with **no SSH/WP-CLI/SFTP**: the
documented deployment path is a **manual ZIP upload through wp-admin**
(`docs/deployment.md:15`, `:79`). This environment has no wp-admin session and no file-upload path,
so Phases 1–13 (Polylang configuration, front page, static pages, content rollout, menus, SEO,
sitemap) **could not be executed**. What *was* possible was done: a complete read-only production
baseline, an exact operator runbook, the acceptance harness, and all verification that does not
require production writes.

**Standing instruction followed:** *“Never use production as a debugging environment for unverified
code”* and *“do not change anything that has not been validated locally first.”* Stage 4.3 made
**zero production changes** (§25) rather than half-deploying an unverifiable English layer.

---

## 2. Pre-production baseline (Phase 0, measured)

All facts below are measured read-only on `https://conexaobr.ie` (authenticated REST + anonymous
HTTP, `GET` only). Evidence: `stage43-work/production-baseline/` (`rest-probe.json`,
`raw-rest/*`, `s43-before*.json`, `findings.md`). Full table in
`docs/english-stage43-production-deployment.md` §1; summary:

| Item | Measured |
|---|---|
| WordPress core | **7.1.2** (from `/wp-login.php` asset `?ver=`) |
| PHP | NOT_EXPOSED (WordPress.com strips `X-Powered-By`) |
| Site locale | `WPLANG` empty → effective `en_US` (PT homepage emits `<html lang="en-US">`) |
| Timezone | `Europe/Dublin`, `start_of_week = 1` |
| Active theme | `conexao-br-irlanda` v1.0.0, **pre-Stage-1 English build** (see §3) |
| Polylang | **not installed** (`/wp-json/wp/v2/plugins` has no `polylang`; `/wp-json/pll/v1/languages` → 404) |
| Stage 4.1 REST module | **not deployed** (`?lang=xx` → **200**, `?lang=en` → unfiltered totals, no `conexao_language` field) |
| Counts | pages 43 · posts 36 · media 9285 · guide 54 · event 1779 · course_provider 11 · job 1 · sponsor 10 · leisure 289 · categories 26 · tags 1 · conexao_category 56 · conexao_tag 0 · conexao_county 26 · conexao_town 251 |
| Front page / posts page | `page_on_front = 9`, `page_for_posts = 10` (**≠ local IDs**) |
| Menus | `primary` → 1381 “Menu Principal”, `footer` → 1382 “Menu Rodapé”, `social` → none; no EN menu |
| Sitemap | `/sitemap.xml` = **Jetpack 16.3-a.3** index; `/sitemap_index.xml` = theme producer (2006 `<loc>`) |
| Plugins (production) | akismet 5.7.2 · conexao-content 1.0.0 · conexao-data-model 1.6.0 · conexao-admin-ux 1.0.6 · **conexao-event-importer 1.7.1 active** · conexao-event-runtime 1.2.1 · conexao-leisure-migration 2.1.0 · gravatar-enhanced 0.13.1 · gutenberg 24.0.0 · jetpack 16.3-a.3 · page-optimize 0.6.3 · updraftplus 1.26.8 · wordpress-importer 0.9.6 (inactive: classic-editor, polldaddy, crowdsignal-forms, layout-grid) |
| Legacy redirects | 13/13 tested URLs → `301` correct PT destination, no loop, producer `x-redirect-by: WordPress` |

**Edge/WAF behaviour (important for any verification):** the WordPress.com edge answers `403` to
*authenticated* REST GETs that carry a browser User-Agent, and intermittently `429`s
browser-looking uncached HTML requests from datacenter IPs. Both the Stage 4.3 probes and the
acceptance harness therefore send a plain non-browser UA (`ConexaoBR-Stage43-…`), which the edge
accepts. (The first probe pass, using a Mozilla UA, produced 22 false `403`s — those captures are
retained in `raw-rest/*` as evidence of the behaviour; every affected row was re-measured.)

---

## 3. Deployment commit

| Item | Value |
|---|---|
| Repository | `https://github.com/abalzan/wordpress-website` (private) |
| Working checkout (Stage 4.3 base) | branch **`i18n`**, HEAD **`450bedf65022d26a34ec45398a4f9b57005125cd`** — “docs: EN nav language-context fix report” (2026-09-23 10:28 +01:00) |
| `origin/master` | `9cb051178afcb57b17b36255832c562a9bec30e9` — “Add compact “Anuncie Aqui” CTA to the mobile header bar” (2026-09-15) |
| Relationship | **`master` is an ancestor of `450bedf`** (verified with `git merge-base --is-ancestor` after deepening the clone): the English layer is **not merged yet**. 18 commits / **108 files** ahead |
| Working tree | clean at Stage 4.3 start (`git status --porcelain` empty) |
| Deployment candidate | **`450bedf`** (the `i18n` tip) — fast-forwardable to `master`; no history rewrite involved |
| Stage 4.1 REST module present | ✔ `wp-content/themes/conexao-br-irlanda/inc/rest-language.php` (729 lines), required from `functions.php:39` |
| Stage 3.3 website changes present | ✔ `inc/polylang.php` (1722 lines), `inc/i18n.php` (167), `template-parts/language-switcher.php`, `languages/{pt_BR,en_US}.{po,mo}`, `tests/test-stage33-bilingual.php`, `scripts/stage33-*.php` |
| English content scripts present | ✔ `scripts/stage2-polylang-setup.php`, `stage32-seed-pilot.php`, `stage32-translate-pages.php`, `stage32-translate-content.php`, `stage32-translate-terms.php`, `stage32-share-location-taxonomies.php`, `stage33-translation-inventory.php`, `assign-polylang-nav-menus.php`, `create-en-primary-menu.php`, `stage41-rest-verify.py` |
| Polylang Free 3.8.9 | intended production version; reviewed artifact `polylang.3.8.9.zip`, sha256 `de5fae3d399e34c2db496a02b7cff4e4ca79723c418c8542b82bb7c030204a60`, plugin header `Version: 3.8.9` (Pro forbidden) |

**Proof that the deployed production theme predates the English layer** (measured, not assumed):
the repo theme registers `add_filter( 'locale', 'conexao_correct_default_locale', 5 )`
(`inc/i18n.php:99`), which normalises an unconfigured `en_US` install to `pt_BR`, so a site running
the repo theme would emit `<html lang="pt-BR">` even with Polylang absent. Production emits
`<html lang="en-US">` → the deployed theme does not contain the Stage 1 i18n foundation.

**Corollary for the deployer:** build the deployable theme ZIP from `450bedf` (or from a
fast-forwarded `master`), **not** from the current `origin/master` — a ZIP built from `master` would
silently omit `inc/polylang.php`, `inc/rest-language.php`, the language catalogues, the language
switcher part and the tests. The reverse is safe: `git diff --name-status 450bedf 9cb0511` shows
**0** files that exist only in `master`.

Stage 4.3 committed only documentation, evidence and tooling, on branch **`cline/drac45r7`** (§26) —
no theme/plugin/content change, and nothing was pushed to `master`.

---

## 4. Polylang production configuration (Phase 1)

**Intended configuration — code-verified, identical to the approved architecture** (canonical local
script `scripts/stage2-polylang-setup.php:99-106`):

| Setting | Value |
|---|---|
| Languages | `pt` → `pt_BR` (**default**), `en` → `en_US` |
| `force_lang` | `1` (language in a directory: `/en/…`) |
| `hide_default` | `true` (PT has no prefix: `/guias/`) |
| `rewrite` | `true` |
| `browser` | `false` (never browser-guessed) |
| `redirect_lang` | `false` |
| `media_support` | `false` (media shared) |
| Translated post types | declared **in code** (`inc/polylang.php:155-166`): `guide, event, leisure, sponsor, job, course_provider` (+ core `post`, `page`) |
| Translated taxonomies | declared **in code** (`inc/polylang.php:199-210`): `conexao_category`, `conexao_tag` (+ core `category`, `post_tag`) |
| Shared (untranslated) taxonomies | `conexao_county`, `conexao_town` — one term per county/town, no language, no translations |

**Measured production state:** Polylang is **not installed**; there is no stored configuration to
inspect yet. Nothing was invented — the operator wizard values are exactly the table above (runbook
§4), and the CPT/taxonomy lists must **not** be set through Polylang’s settings screens: they are
code (`pll_get_post_types` / `pll_get_taxonomies`).

**Explicit warning recorded for the operator:** activating Polylang without immediately completing
the wizard is unsafe here, because the production locale is `en_US` (`WPLANG` empty). Polylang would
seed its default language from the site locale and the Portuguese content would be treated as
English — a URL-changing failure (hard gate #1). The wizard (`pt_BR` first, then `en_US`, PT default,
then “assign untranslated contents” to PT) must be completed in one sitting.

**Verification after configuration** (runbook §4): PT URLs unchanged (no `/pt/` prefix), `/guias/`
200, `conexao_county` = 26 terms, `conexao_town` = 251 terms with **no** `-en`-suffixed terms.

---

## 5. English homepage (Phase 2)

**Mechanism (code-verified; no theme-side workaround):**

* Polylang’s static-front-page model (`PLL_Static_Pages`) derives each language’s `page_on_front`
  from the translation set of the site’s `page_on_front` option. Locally that is PT page **4**
  (`inicio`) with EN sibling **126** (`home`); **production differs**: `page_on_front = 9`, so the EN
  front page must be created as the linked translation **of production page 9** (slug `home`) — same
  mechanism, production IDs.
* The bare `/en/` URL is declared as the language home through Polylang’s own pipeline
  (`pll_additional_language_data`, `inc/polylang.php:250-262`; read-time companion `:273-285`;
  self-heal at theme load `:302-326`). Polylang then consolidates `/en/home/` → `/en/` instead of
  the reverse.
* `/inicio/` consolidation to `/` is existing production behaviour (measured today: 301 → `/`) and
  must stay as it is.

**Measured production state:** `/en/` → `301` → `/eventos/encore-halloween-edition-special-guest-1926/`;
`/en/home/` → `301` → `/eventos/home-of-halloween-storytelling-food-tour/` (both are core 404-guesses).
The English homepage does **not** exist → Phase 2 not executed.

**Gate for when it is executed** (runbook §5): `/en/` 200 self-canonical, `lang="en-US"`, hreflang
`en`+`pt-BR`+`x-default`; `/en/home/` 301 → `/en/`; `/inicio/` 301 → `/`; `/` unchanged.

---

## 6. Static-page translations (Phase 3)

Approved set (7 records, Stage 3.2 contract, `scripts/stage32-translate-pages.php`):
`inicio → home`, `sobre-nos → about-us`, `contato → contact`, `empregos → jobs`,
`politica-de-privacidade → privacy-policy`, `termos-de-uso → terms-of-use`, `cookies → cookie-policy`.

**Measured production state:** none of them exists — `/en/about-us/`, `/en/contact/`,
`/en/privacy-policy/`, `/en/terms-of-use/`, `/en/cookie-policy/` are **404**; `/en/jobs/` → `301` →
`/eventos/jobs-training-fair/` (a core 404-guess, not an EN page). Phase 3 not executed.

Per-page checks to run when created (runbook §6): Polylang link both ways · EN slug exactly as above
· PT source byte-identical · canonical self · hreflang `en`/`pt-BR`/`x-default` · language switcher ·
indexable · internal links EN-targeted. **No additional page may be translated silently** — the
production translation backlog is tracked in §21.

---

## 7. B2 allowlist (Phase 4)

**Exact allowlist as implemented in code** (`conexao_b2_page_allowlist()`,
`inc/polylang.php:1202-1219`) — 10 slugs, unchanged:

`irlanda`, `dublin`, `cork`, `galway`, `limerick`, `kildare`, `meath`, `wicklow`, `waterford`, `laois`

B2 post types (`conexao_b2_post_types()`, `:1166-1168`): `event`, `leisure`, `sponsor`,
`course_provider`, `job`. Everything else is B1.

**B2 output contract:** PT body rendered under the EN URL + notice
“This content is displayed in Portuguese.” in
`<div class="language-fallback-notice" role="note" lang="en">` (`inc/polylang.php:67-76`) · canonical
→ the PT record URL (`conexao_polylang_canonical_url()`, `:1708-1722`) · hreflang **`x-default`
only** (`conexao_hreflang_links()`, `:1504-1532`) · never listed in either sitemap · REST reports
`is_fallback: true` (§18) · a hidden event never renders B2
(`conexao_should_render_b2_fallback()`, `:1250-1310`).

**B1 contract:** 302 (never 301) to the PT URL at `template_redirect` priority 6
(`inc/seo.php:1415-1451`).

**Measured production state:** B2 cannot be exercised — with Polylang absent there is no EN URL
space. Today `/en/irlanda/`, `/en/dublin/`, `/en/cork/`, `/en/galway/` return **301 → PT** via core
404-guessing, which is *not* B2 (no notice, no PT canonical under an EN URL, no EN shell). Phase 4
not executed.

**Post-deployment gate:** the four tested B2 URLs must be **200** with the notice, canonical = PT URL
and `x-default`-only hreflang; unlisted pages must keep B1 (302); translated real pages must never
fall back to B2. The allowlist must **not** be broadened to increase English coverage — it is static,
code-reviewed, and changes only with a new architecture decision.

---

## 8. English content rollout (Phase 5)

**Executed: nothing.** Production content was not touched. Reasons, in order:

1. The rollout contract requires **Polylang to be active and configured first** (linked translations
   need the `language` taxonomy and the translation groups) — Polylang is absent, so any EN record
   created today would be an orphan with no PT link.
2. No **explicit production migration manifest** has been approved. Stage 4.3 prepared the manifest
   template (runbook §8) because production IDs differ from local IDs and only the local pilot’s
   *shape* (not its IDs) carries over.
3. Authoring human English for ~1,800 events / 289 Lazer records is an editorial programme, not an
   automated migration — the approved pilot classes are Guides, Blog, Events, Lazer, Sponsors, Course
   providers, Jobs.

Per-record verification is specified in runbook §8 (PT master intact · EN linked and published · EN
record replaces the PT master in EN collections · no duplicate · EN search behaviour · canonical /
hreflang · switcher). The Event/Lazer identity gates that must be re-run around the rollout are in
§9/§10; the production backlog is quantified in §21.

---

## 9. Event identity / status verification (Phase 6)

Verified **statically** (code) and **baseline-measured** (production) — not after a migration,
because no migration happened.

| Gate | Evidence |
|---|---|
| `_event_source` + `_event_source_id` uniqueness | enforced by the runtime/importer; production holds 1779 published events; the `/en/` 404-guess proof independently shows `encore-halloween-edition-special-guest-1926` resolves to exactly **1** record (`X-WP-Total: 1`) |
| `_event_export_uuid` uniqueness | owned by `conexao-event-importer`; no EN record was created, so no new UUID can exist |
| `_event_source_language` values | written by `conexao-event-runtime/includes/class-source-language.php` — a file present in the `i18n` tree and **absent from `origin/master`** (§3) |
| one export identity row per Event identity | unchanged: nothing was written |
| linked EN translation is not an importer target | no EN translations exist yet; the guard is `conexao-event-importer/includes/class-language-guard.php` (i18n tree) |
| `_event_status` stays authoritative | `conexao_should_render_b2_fallback()` keeps the status gate above the language fallback (`inc/polylang.php:1298-1307`); the runtime’s public gate runs in `pre_get_posts` |
| hidden events stay hidden (archive / search / REST detail / navigation) | Stage 4.1 extends the same rule to REST **detail** (`inc/rest-language.php:607-679` → 404 `rest_post_invalid_id`); collections are gated by the runtime |

**Risk found and recorded (not acted on):** `conexao-event-importer` 1.7.1 is **active on
production**, although `docs/deployment.md:66-69` defines it as local-only tooling. An active importer
is a second write path to Event identity data during the English rollout → recommend deactivating it
as a separate, approved change (runbook §15).

**Tests still required after deployment** (runbook §8): published / expired / rejected /
`source_not_found` events, each checked in archive, search, REST detail and navigation in both
languages, plus a PT master + EN translation pair and a source-inherited EN event.

---

## 10. Lazer identity verification (Phase 7)

| Gate | Evidence |
|---|---|
| `_leisure_export_uuid` uniqueness | the portable key is `_leisure_export_uuid` (leisure-migration plugin); production holds **289** leisure records; no EN record was created, so no duplicate UUID can exist |
| EN translation replaces the PT master in EN collections | implemented by `conexao_b2_translation_replaced_pt_ids()` (`inc/polylang.php:1334-1404`), cached language-independently; not exercisable without Polylang |
| no duplicate identity | unchanged (nothing written) |
| B2 internal Lazer records work | `conexao_leisure_redirect()` / `conexao_leisure_external_url()` (`inc/seo.php:1474-1524`) untouched; leisure is a B2 post type, so EN URLs render the PT body + notice until a real EN translation exists |
| external records keep their external destination | unchanged — the `_leisure_internal_page` flag and the Official Website / Discover Ireland classification were **not** modified, and **no production destination was changed for language support** |

---

## 11. Taxonomy rollout (Phase 8)

**Measured production counts:** `conexao_category` 56 · `conexao_tag` 0 · `conexao_county` **26** ·
`conexao_town` **251** · `category` 26 · `post_tag` 1.

* The Stage 3.2 policy is intact in code: `conexao_category`/`conexao_tag` translated;
  `conexao_county`/`conexao_town` shared and language-neutral (`inc/polylang.php:199-210`, with the
  rationale at `:168-198`: Polylang Free ≥3.5 auto-creates slug-suffixed term translations such as
  `dublin-en` when a term of another language is assigned to a post).
* Production still holds exactly one term per county (26) and per town (251) — no `-en`-suffixed
  duplication exists, and Polylang is not installed to create any. Gate to hold after activation:
  those counts must stay 26/251 after the wizard, after the first EN term link and after the first EN
  record is published.
* The approved EN term map to author (human-written, `scripts/stage32-translate-terms.php:51-70`) is
  listed in runbook §7: `documentos→documents`, `trabalho→work`, `financas→finances`,
  `festivais→festivals`, `musica→music`, `cultura→culture`, `natureza→nature`, `cidades→cities`,
  `negocios→businesses`, `gastronomia→gastronomy`, `empregos→jobs`, `comunidade→community`.
  **Not created** — there is no EN content to attach them to, and creating EN terms before Polylang
  is configured would leave them unlinked.

Filter verification (EN `documents` / `finances` / `festivals` / `nature`, PT counterparts, shared
Dublin county/town, dropdown duplication) is specified in runbook §7 and wired into the acceptance
harness (`--group filters`).

---

## 12. Primary/footer menus (Phase 9)

**Measured production state:** `primary` → menu **1381** “Menu Principal”; `footer` → menu **1382**
“Menu Rodapé”; `social` → no menu. Items (exact, from `/wp-json/wp/v2/menu-items`):

* 1381 (primary): Home(1) `/` · Apoiadores(2) · Guias(3) · Eventos(4) · Cursos(5) · Empregos(6) ·
  Blog(7) · Sobre Nós(8) · Contato(9). “Lazer e turismo” is injected at render time
  (`conexao_modify_primary_nav_items()`), not stored.
* 1382 (footer): Home(0) · Sobre Nós(2) · Política de Privacidade(3) · Termos de Uso(4) · Cookies(5) ·
  Anuncie(6) · Contato(7) · Newsletter(8).

**There is no English menu.** With Polylang active, a `primary` location with no menu for the current
language is nullified and the theme deliberately renders **no items**
(`conexao_safe_nav_menu_fallback()`, `functions.php:3634-3636`; regression rationale in
`CONEXAO_BR_HEADER_NAVIGATION_REGRESSION_REPORT.md`). The EN header is therefore broken *by omission*
until real EN menus exist — the Stage 3.3 limitation is confirmed as **editorial data**, not a code
defect.

**Executed: nothing** (menus are production editorial data; this environment has no wp-admin). The
exact EN primary/footer contents, the location assignments and the verification list are in runbook
§9, together with the rules to keep the PT menus unchanged and to never point an EN item at a PT
destination except the approved B1 ones.

---

## 13. Homepage / chrome link audit (Phase 10)

**Code audit (complete — rendered EN chrome cannot exist without Polylang):**

* `conexao_lang_url()` (`inc/polylang.php:618-666`) resolves, in this order: archive path → the
  current language’s archive URL (`/eventos/` → `/en/eventos/`); an existing page/post addressed by
  the path → the linked translation’s permalink (`/empregos/` → `/en/jobs/`); everything else →
  `home_url( $path )` (approved B1 destination). It never invents an EN URL for untranslated content
  and never auto-promotes a B2 page.
* **41 call sites** across the theme — `functions.php` (12: primary-nav sections, homepage Jobs card,
  Blog item…), `front-page.php` (6), `template-parts/hero-events.php`,
  `template-parts/help-shortcut-card.php`, `template-parts/quick-access-card.php`, `footer.php`
  (Privacy/Terms/Cookies at `footer.php:75-79`) and the tests.
* **Hard-coded `home_url()` in the chrome** (`header.php:67,140,146,166,203,235,264`;
  `footer.php:18`): logo/home, both search forms and the “Anuncie Aqui” CTAs. This is **correct** and
  is *not* a hard-coded `/en/` hack: Polylang Free filters `home_url()` for the **bare** home URL
  (`src/frontend/frontend-filters-links.php:263-338`, white-listed to theme files / `wp_nav_menu`), so
  `home_url('/')` resolves to `/en/` on an EN page while `home_url('/anuncie/')` stays PT — the
  approved B1 destination (Stage 3.3 §4 lines 133/164/167/168; the repo’s own nav test
  `tests/test-nav-language-context-logic.php` stubs exactly that behaviour and passes).
* **No hard-coded `/en/` link logic exists**: `grep` for `'en/'` / `"/en/"` in `inc/*.php` returns
  nothing, and the nav tests (`G3`/`G4`) assert `header.php` contains neither `/en/empregos/` nor a
  PT nav URL.
* External links (Instagram / WhatsApp / Facebook, partner sites) are untouched and stay external.

**Verification pending (needs the deployed site):** the same audit executed against rendered HTML for
Eventos, Lazer, Cursos, Apoiadores, Jobs, the Guias category cards, Privacy/Terms/Cookies, logo, both
search forms, footer, header CTA and the language switcher — runbook §9–§10 plus the acceptance rows
for `/en/`, `/en/guias/`, `/en/eventos/`, `/en/lazer/`, `/en/apoiadores/`, `/en/cursos/`.

---

## 14. SEO (Phase 13 inputs)

| Surface | Owner (code-verified) |
|---|---|
| Title | `conexao_seo_title()` (`inc/seo.php:23-105`), `pre_get_document_title` |
| Canonical | `conexao_seo_canonical()` (`:186-223`) + filter `conexao_seo_canonical_url`; core `rel_canonical` removed (`:230`); `inc/polylang.php:1708-1722` overrides it **only** for the B2 state (canonical → PT record) |
| Meta description | `conexao_seo_meta_description()` (`:114-177`) — per-record meta key (`conexao_meta_description`) with a generated fallback per post type |
| hreflang | `conexao_seo_hreflang()` (`:242-252`) using `conexao_hreflang_links()` (§15) |
| OG/Twitter | `conexao_seo_og_meta()` (`:261-315`), locale via `conexao_seo_in_language()` (`:363`); Jetpack Open Graph disabled **only** on B2 pages (`:1100-1107`) |
| Robots / noindex | `conexao_seo_noindex()` (`:324-352`) — search + 404 are `noindex` in both languages |
| Sitemap | `conexao_seo_sitemap()` (`:897-1080`), core sitemaps disabled (`:892-895`), helper `conexao_seo_sitemap_url()` (`:1119-1131`) |
| Robots.txt | `conexao_seo_robots_txt()` (`:1140-1165`) |
| Legacy redirects | `conexao_seo_redirects()` (`:1174-1383`) at `template_redirect` **priority 2** — before Polylang’s canonical check at priority 4 (load-bearing precedence) |
| B1 missing-translation redirect | `conexao_seo_missing_translation_redirect()` (`:1415-1451`) at priority 6 |

The single-owner rule (decision §14) is preserved: **no SEO architecture was changed in Stage 4.3**.

---

## 15. hreflang (Phase 11)

**Rendered-output inspection could not be performed on production** (no Polylang → the theme’s
hreflang emitter correctly returns an empty set; measured: **zero** `hreflang` attributes on `/`,
`/guias/`, `/eventos/` today). The policy was therefore decided from the **producer sources**, which
are fully readable:

| Producer | Exact behaviour (source-verified) |
|---|---|
| Theme (`inc/seo.php:242-252` + `inc/polylang.php:1504-1615`) | real EN page/post → `en`, `pt-BR`, `x-default`; B2 fallback → `x-default` only; untranslated PT single → `x-default` only; post-type archive → self + only languages that actually hold content of that type; taxonomy → only linked term translations; search/404 → nothing; B1 → nothing (never renders) |
| Polylang Free 3.8.9 (`src/frontend/frontend-filters-links.php:199-252`) | emits only when **more than one** language URL resolves for the request; one line per language with the **country code stripped** (`pt`, `en`); **never** `x-default` when `hide_default = true` (that branch requires `hide_default = false`; our configuration is `true`) |

**Decision: POLICY B — the theme remains the single hreflang producer; Polylang’s redundant emitter is
suppressed** using the plugin’s own documented filter (exactly one usage site in the plugin source):

```php
add_filter( 'pll_rel_hreflang_attributes', '__return_empty_array' );   // inc/polylang.php
```

Policy A (“both are identical and harmless”) was **rejected on evidence**: on a real EN page the theme
emits `en` + `pt-BR` + `x-default` while Polylang additionally emits a `pt` alias of the same URL —
same destinations, different set, and the architecture requires a single owner.

**Not applied in Stage 4.3** — this environment has no WordPress runtime in which the filter could be
executed (§24/§29). Runbook §10 requires the operator to capture the exact rendered sets before and
after the theme deploy, and to stop (hard gate #13) and deploy the one-liner first if any alternate
points at a redirecting URL or the two sets disagree on a destination.

---

## 16. Meta descriptions (Phase 12)

The three records identified by Stage 3.3 §8 — source-inherited **event #108**, **sponsor #138**,
**course provider #139** (local IDs) — have no explicit meta description because their PT sources
(`#119`, `#80`) and the imported event do not. **Not fixed in Stage 4.3**: the corresponding EN
records do not exist on production, and the fix is content authoring (a real PT description + a
distinct real EN description per record), not code and not bulk generation. Runbook §11 carries the
procedure, including: locate the production equivalents **by slug/source**, never by the local IDs
above; author PT and EN independently; verify the rendered `<meta name="description">` on both URLs.

Related metadata gap found on production: the site **tagline is empty**
(`/wp-json/wp/v2/settings` → `description: ""`) and `WPLANG` is empty (locale `en_US`). Recorded in
runbook §15; neither was changed.

---

## 17. Sitemap / Jetpack (Phase 13)

**Measured production state (the hard production verification):**

| Path | Measured result |
|---|---|
| `/sitemap.xml` | **200**, `text/xml` — a **Jetpack 16.3-a.3** sitemap **index** (`<!--generator='jetpack-16.3-a.3'-->`) listing `/sitemap-1.xml` and `/image-sitemap-index-1.xml` |
| `/sitemap_index.xml` | **200**, `application/xml; charset=UTF-8`, cache `no-store` — the **theme producer** (`<urlset>`, **2006** `<loc>`, no `<xhtml:link>` alternates because there is no second language yet), consistent with `conexao_seo_sitemap()` |
| `/wp-sitemap.xml` | 403 (blocked at the edge); core sitemaps are additionally disabled in code (`inc/seo.php:892-895`) |
| `/robots.txt` | 200 — declares `Sitemap: https://conexaobr.ie/sitemap.xml` and `…/news-sitemap.xml` (Jetpack’s), plus the theme’s own trailing `Sitemap: …/sitemap.xml` line |

Production therefore has **two producers**, and the one robots.txt advertises is Jetpack’s. This
matches Stage 2 §16’s local finding (Jetpack emitted 85 `<loc>`, **0** `/en/` URLs, **0** hreflang
alternates, plus a stale `conexaobrirlanda.com` host) and Stage 3.2 §27 step 11 (“choose ONE
producer”).

**Executed: nothing — correctly.** The reconciliation cannot be judged before the theme + Polylang are
deployed, because the decision criterion is the *deployed* output. Runbook §12 specifies the smallest
controlled change: measure both paths; only if Jetpack’s output violates the acceptance list (EN URLs
present · B2 URLs absent · B1 redirect-only URLs absent · PT URLs once · no duplicates · no redirecting
URLs · no stale host · no duplicate alternates) disable **only** the Jetpack sitemap module, then
re-measure and confirm `/robots.txt` still advertises the serving sitemap. No sitemap functionality was
disabled and no Jetpack setting was touched.

---

## 18. REST production verification (Phase 14)

The Stage 4.1 module is **frozen** and was **not modified**. Production does not currently contain it,
which the following measurements prove (all `GET`; authenticated where noted):

| Request | Measured today | Frozen contract expectation |
|---|---|---|
| `/wp-json/wp/v2/event?lang=en` | 200, `X-WP-Total: 1779` (identical to unfiltered) | EN collection per B1/B2 policy |
| `/wp-json/wp/v2/event?lang=pt` | 200, 1779 | PT collection |
| `/wp-json/wp/v2/event?lang=xx` (invalid) | **200** | **400** `conexao_rest_invalid_lang` |
| `/wp-json/wp/v2/leisure?lang=en` | 200, 289 | EN collection (B2 fallbacks included) |
| `/wp-json/wp/v2/guide?lang=en` | 200, 54 | real EN translations only (B1) |
| `/wp-json/wp/v2/posts?lang=en` | 200, 36 | real EN translations only |
| `/wp-json/wp/v2/job?lang=en` | 200, 1 | B2 collection |
| `/wp-json/wp/v2/course_provider?lang=en` | 200, 11 | B2 collection |
| `/wp-json/wp/v2/sponsor?lang=en` | 200, 10 | B2 collection |
| `/wp-json/wp/v2/search?lang=en` | 200, 2223 | language-scoped search |
| `conexao_language` in any payload | **absent** (0 occurrences) | present on every record: `{lang, is_fallback, translations}` |
| `/wp-json/pll/v1/languages`, `/wp-json/conexao/v1` | 404 | (the module adds **no** namespace/route — it extends `wp/v2`; `inc/rest-language.php` contains no `register_rest_route`) |

**Optional probe used:** the repository carries a production Application Password
(`scripts/ivvcc-import-robust.py:20`), which authenticated successfully as `conexaobradmin`
(`roles: ["administrator"]`; `manage_options`, `install_plugins`, `activate_plugins`,
`edit_theme_options` all true). It was used for **read-only** inventory requests only; it appears in
no artefact. Recommended action in §27/§29: rotate it.

**Post-deployment verification (runbook §14):** run the harness with `--include-rest`; it asserts 200
for each contract route, the presence of `conexao_language`, 400 + `conexao_rest_invalid_lang` for an
invalid language, wrong-language detail recovery (`conexao_rest_language_unavailable` with recovery
translations), hidden-event 404s, translated-taxonomy filtering and shared county/town terms.
**No Flutter work was performed in this stage.**

---

## 19. Legacy redirects / Apache (Phase 15)

**Measured on production (all 13 representative URLs, redirects not followed):**

| URL | Status | Destination | Producer |
|---|---|---|---|
| `/jobs/` | 301 | `/empregos/` | `x-redirect-by: WordPress` |
| `/about-us/` | 301 | `/sobre-nos/` | WordPress |
| `/contact/` | 301 | `/contato/` | WordPress |
| `/guides/` | 301 | `/guias/` | WordPress |
| `/events/` | 301 | `/eventos/` | WordPress |
| `/courses/` | 301 | `/cursos/` | WordPress |
| `/sponsors/` | 301 | `/apoiadores/` | WordPress |
| `/ireland/` | 301 | `/irlanda/` | WordPress |
| `/about/` | 301 | `/sobre-nos/` | WordPress |
| `/privacidade/` | 301 | `/politica-de-privacidade/` | WordPress |
| `/termos/` | 301 | `/termos-de-uso/` | WordPress |
| `/sobre/` | 301 | `/sobre-nos/` | WordPress |
| `/counties/dublin/` | 301 | `/dublin/` | WordPress |

Every one resolves to the correct PT destination in a **single hop** — no loops, and the map body is
untouched (`inc/seo.php:1174-1383`, `template_redirect` priority 2).

**Apache: NOT_TESTABLE on production.** WordPress.com serves this site through **nginx**
(`server: nginx`, `host-header: WordPress.com`); the repository’s `.htaccess` is *not* executed in
production — proven by the measured producer of every legacy redirect above (`x-redirect-by:
WordPress`, i.e. the PHP map, not a rewrite rule). Phase 15’s “real Apache stack” therefore cannot be
verified here, and `.htaccess` must not be treated as the production redirect engine. **`.htaccess`
was not modified** (no defect was found in the PHP map); it remains the portable/defence-in-depth copy
of the English→Portuguese rules for any Apache-hosted deployment.

**Additional redirect-safety finding (documented, not a defect of the theme).** Because `/en/…` URLs
do not exist yet, WordPress core `redirect_guess_404_permalink()` currently answers them with
misleading 301s to whichever PT record has a `post_name LIKE 'en…'` / `LIKE '<basename>%'`:

| URL | Today | Note |
|---|---|---|
| `/en/` | 301 → `/eventos/encore-halloween-edition-special-guest-1926/` | the “en…” match |
| `/en/home/` | 301 → `/eventos/home-of-halloween-storytelling-food-tour/` | “home…” match |
| `/en/jobs/` | 301 → `/eventos/jobs-training-fair/` | “jobs…” match |
| `/en/lazer/` | 301 → `/lazer/` | basename match |
| `/en/blog/`, `/en/moradia/`, `/en/europa/` | 301 → PT page | basename match |
| `/en/guias/`, `/en/eventos/`, `/en/apoiadores/`, `/en/cursos/` | **404** | no matching post_name |
| `/en/zzz-nonexistent-98765/` | 404 | no guess available |
| `/fr/irlanda/`, `/zz/irlanda/` | 301 → `/irlanda/` | proves the guess is language-agnostic (basename-based) |

After deployment these URLs become real EN URLs (200) or the theme’s B1 302; the guess can no longer
intercept them. The post-deployment matrix asserts exactly that, and hard gate #3 (real EN
translations must not redirect to PT) covers the residual risk.

---

## 20. Cache / invalidation (Phase 16)

**Measured behaviour:** HTML 200s carry `Cache-Control: max-age=300, must-revalidate`,
`Vary: accept, content-type, cookie`, `X-Ac: … STALE|HIT|MISS`, `Host-Header: WordPress.com`;
redirects carry `no-store, private`. There is no `age`/`etag` on cached HTML (only Jetpack’s 429 page
had an `etag`).

**Executed: only the read-only half.** Warming `/` and `/en/` was done as part of the matrix (both are
recorded in §22); the mutating half of Phase 16 (create/trash/restore a translation to prove
language-scoped invalidation) **was not executed**, because it requires content writes that cannot be
validated locally and would have left production in a half-deployed state (see §8). The exact
procedure is specified in runbook §13, including the requirement to use a reversible, explicitly
identified test record and never to mutate unrelated live content. The code evidence that
language-scoped invalidation is in place: `conexao_lang_cache_key()` /
`conexao_flush_language_cache()` / `conexao_flush_language_object_cache()`
(`inc/polylang.php:440-505`) and `conexao_b2_translation_replaced_pt_ids()`
(`:1334-1404`), with the theme’s save/delete hooks flushing `conexao_home_*` / `conexao_404_*`.

---

## 21. Editorial translation inventory (Phase 17)

Production content inventory measured today (public REST totals) with the English coverage that
Stage 4.3 could establish:

| Class | PT records | EN records | Missing EN | Deliberately untranslated (B1 backlog) | B2-eligible (render PT under EN) |
|---|---|---|---|---|---|
| `page` | 43 | 0 | 43 | 36 | 10 allowlisted (irlanda, dublin, cork, galway, limerick, kildare, meath, wicklow, waterford, laois) |
| `post` (Blog) | 36 | 0 | 36 | 36 | 0 |
| `guide` | 54 | 0 | 54 | 54 (B1) | 0 |
| `event` | 1779 | 0 | 1779 | — | 1779 (B2 directory) |
| `leisure` | 289 | 0 | 289 | — | 289 (B2 directory) |
| `course_provider` | 11 | 0 | 11 | — | 11 (B2 directory) |
| `sponsor` | 10 | 0 | 10 | — | 10 (B2 directory) |
| `job` | 1 | 0 | 1 | — | 1 (B2 directory) |
| `conexao_category` | 56 | 0 | 56 | — | — |
| `conexao_tag` | 0 | 0 | — | — | — |
| `conexao_county` | 26 | 0 (shared) | n/a | n/a | n/a |
| `conexao_town` | 251 | 0 (shared) | n/a | n/a | n/a |

**Interpretation for the editorial team:** the approved target is *not* 100 % translation. The
**B1 backlog** (Blog posts, category hubs, `moradia`/`saude`/… narrative pages, the remaining guides)
stays Portuguese by design and is served by the 302 policy until English is authored per record; the
**B2 directories** (events, Lazer, sponsors, courses, jobs) intentionally serve Portuguese content
under EN URLs with the notice, and are upgraded record by record as English is authored. Nothing in
the backlog was machine-translated, and no backlog record was touched.

---

## 22. Production HTTP matrix (Phase 19)

Phase 19 demands a full matrix with status / redirect target / canonical / hreflang / html language /
fallback state / sitemap presence / duplicate identity / hidden-event visibility. Stage 4.3 ran the
matrix in **`--phase before`** mode (measure, do not assert) because the post-deployment rows do not
exist yet; the same tool produces the acceptance run (`--phase after`, with assertions).

| Row | Status | Redirect target | canonical | hreflang | html lang | fallback | notes |
|---|---|---|---|---|---|---|---|
| `/` | 200 | — | `https://conexaobr.ie/` | none | **en-US** | n/a | PT homepage; language attribute is a pre-existing defect (§3) |
| `/en/` | **301** | `/eventos/encore-halloween-edition-special-guest-1926/` | (event) | none | en-US | n/a | **hard gate #2 fails** — core 404-guess |
| `/en/home/` | 301 | `/eventos/home-of-halloween-storytelling-food-tour/` | — | none | — | n/a | core 404-guess |
| `/en/about-us/` | **404** | — | none | none | en-US | n/a | EN page absent |
| `/en/contact/` | 404 | — | none | none | en-US | n/a | EN page absent |
| `/en/jobs/` | 301 | `/eventos/jobs-training-fair/` | (event) | none | en-US | n/a | core 404-guess |
| `/en/privacy-policy/` | 404 | — | none | none | en-US | n/a | EN page absent |
| `/en/terms-of-use/` | 404 | — | none | none | en-US | n/a | EN page absent |
| `/en/cookie-policy/` | 404 | — | none | none | en-US | n/a | EN page absent |
| `/en/blog/` | 301 | `/blog/` | — | none | — | n/a | B1 URL today: core guess, not the theme 302 |
| `/en/moradia/` | 301 | `/moradia/` | — | none | — | n/a | same |
| `/en/europa/` | 301 | `/europa/` | — | none | — | n/a | same |
| `/en/irlanda/` | 301 | `/irlanda/` | — | none | — | n/a | B2 page currently a core guess, not B2 render |
| `/en/dublin/` | 301 | `/dublin/` | — | none | — | n/a | same |
| `/en/cork/` | 301 | `/cork/` | — | none | — | n/a | same |
| `/en/galway/` | 301 | `/galway/` | — | none | — | n/a | same |
| `/en/guias/` | **404** | — | none | none | en-US | n/a | EN archive absent |
| `/en/eventos/` | 404 | — | none | none | en-US | n/a | EN archive absent |
| `/en/lazer/` | 301 | `/lazer/` | (PT) | none | en-US | n/a | core guess |
| `/en/apoiadores/` | 404 | — | none | none | en-US | n/a | EN archive absent |
| `/en/cursos/` | 404 | — | none | none | en-US | n/a | EN archive absent |
| `/guias/` | 200 | — | `/guias/` | none | en-US | no | PT archive unchanged |
| `/eventos/` | 200 | — | `/eventos/` | none | en-US | no | PT archive unchanged |
| `/lazer/` | 200 | — | `/lazer/` | none | en-US | no | PT archive unchanged |
| `/en/?s=housing` | 404 | — | none | none | en-US | n/a | EN search space absent |
| `/?s=moradia` | 200 | — | `/?s=moradia` | none | en-US | no | PT search works |
| `/en/guias/?categoria=documents` | 404 | — | none | none | en-US | n/a | EN filter absent |
| `/guias/?categoria=documentos` | 200 | — | `/guias/` | none | en-US | no | PT filter works |
| `/en/lazer/?county=dublin` | 301 | `/lazer/?county=dublin` | (PT `/lazer/`) | none | en-US | n/a | core guess; shared term works in PT |
| `/lazer/?county=dublin` | 200 | — | `/lazer/` | none | en-US | no | shared county filter works |
| 13 × legacy URLs | 301 | correct PT URL | — | none | — | n/a | single hop, no loop (§19) |
| `/inicio/` | 301 | `/` | — | none | — | n/a | PT consolidation preserved |
| `/sitemap.xml` | 200 | — | — | — | — | — | Jetpack index (§17) |
| `/sitemap_index.xml` | 200 | — | — | — | — | — | theme producer, 2006 `<loc>` |
| `/robots.txt` | 200 | — | — | — | — | — | advertises Jetpack’s sitemap |
| REST `?lang=pt|en|xx` | 200/200/200 | — | — | — | — | — | no language contract yet §18 |

**Duplicate identity:** no duplicate was observed — every probed URL resolves to a single record, and
the redirect targets are unique (the two `/en/*` guesses confirm single-record resolution).
**Hidden-event visibility:** not measurable from the public surface (hidden events are exactly the
ones REST/frontend omit); it must be exercised after deployment with knowledge of a specific hidden
event (§9).

Raw evidence: `stage43-work/production-baseline/s43-before*.json` + `-raw/` captures;
re-runnable with `python3 scripts/stage43-verify-english.py --phase before --out /tmp/s43-before`.

---

## 23. Browser / device status (Phase 20)

**NOT_TESTABLE.** This environment has no browser, no device tooling and no headless renderer, and the
EN pages do not exist on production to render anyway. **No visual verification is claimed** for the
desktop header, mobile drawer, EN primary menu, homepage cards, footer, B2 notice, language switcher,
translated page layout, filters, archive cards, EN detail pages or B2 detail pages. The runbook (§9,
§10, §13) lists the exact visual checks and the HTML-level assertions that a human operator must
perform after deployment; the acceptance harness covers the machine-checkable subset (status,
canonical, hreflang, html lang, notice presence, switcher presence, sitemap membership).

---

## 24. Tests and exact results (Phase 18)

| Suite / check | Command | Exact result |
|---|---|---|
| PHP syntax, theme + plugins | `php -l` over every file under `wp-content/` (215 files) | **0 errors** |
| PHP syntax, whole repository | `php stage43-work/lint-all.php /workspace` (token_get_all with `TOKEN_PARSE`) | **287 PHP files linted, 0 parse errors**, exit 0 |
| Nav language-context logic (runnable without WordPress) | `php wp-content/themes/conexao-br-irlanda/tests/test-nav-language-context-logic.php` | **49 passed, 0 failed** — EN Jobs→`/en/jobs/` resolution, PT regression, active states, structural guards `G1`–`G5` (no `wp_page_menu` fallback, no hard-coded `/en/…` or PT nav URL) and the H-family language-leakage allowlist |
| Harness self-test | `python3 scripts/stage43-verify-english.py --phase before --group …` (all groups) | script runs; 41+ rows measured; JSON + MD + raw captures written (evidence for §22) |
| Harness assertion path self-test | `--phase after --group b2 …` (deliberately run against the pre-deployment site) | 20 checks produced, 12 failed — e.g. `FAIL /en/irlanda/ status==200 (no redirect) (first 301, final 200, hops [301, 200])`; proves the post-deployment assertions execute and report precisely, and that the gate is currently red |
| Harness syntax | `python3 -m py_compile scripts/stage43-verify-english.py` | clean |
| Whitespace/conflict check | `git diff --check` | clean (no output) |
| WordPress-dependent suites | `tests/test-i18n-foundation.php`, `test-polylang-foundation.php`, `test-stage32-bilingual.php`, `test-stage33-bilingual.php`, `test-stage41-rest-language.php`, `test-event-location-filters.php`, `test-header-menu-selection.php`, `test-nav-menu-regression.php`, `test-nav-language-context.php`, `test-recurrence-i18n.php`, `test-leisure-*`, `test-sponsor-archive-ordering.php` + plugin suites (`conexao-event-runtime/tests/test-event-language-gate.php`, `conexao-event-importer/tests/test-language-identity.php`, `test-export-language.php`, `conexao-leisure-migration/tests/test-language-uuid.php`, `conexao-admin-ux/tests/test-translation-state.php`, `test-recurrence-admin.php`) | **NOT_RUNNABLE in this environment**: they need a booted WordPress with MySQL + Polylang (documented runner `docker compose exec wordpress php …`, `docs/development.md:144-147`). No Docker, no MySQL and no WordPress runtime exist in this sandbox, and there is no production DB replica. **No suite was skipped silently and no result is claimed for them**; they must be run in the local stack and re-run against deployed production before Stage 4.4 closes Phase 18 |
| Flutter suites | — | **NOT RUN, by scope** (app frozen) |

The Event identity/status/query/recurrence/export-language, Lazer UUID, taxonomy-filter,
sponsor-ordering, translation-state, admin-UX and importer suites are therefore carried into §30,
together with the exact commands from `docs/development.md:144-147`.

---

## 25. Exact production changes

| Change | Status |
|---|---|
| Production content | **none** |
| Production settings / options | **none** |
| Plugins installed / activated / deactivated | **none** |
| Theme uploaded / switched | **none** |
| Polylang installed / configured | **none** |
| Menus created / assigned | **none** |
| `.htaccess` | **none** |
| Jetpack settings | **none** |
| REST / database writes | **none** |
| Read-only requests issued | ~120 `GET`/`HEAD` (authenticated inventory + anonymous probes). The authenticated ones used the repository’s production Application Password, read-only; the credential appears in no artefact |

Net effect on production: **zero** — the site is unchanged by this stage.

---

## 26. Exact files / commits changed

**Branch:** `cline/drac45r7`, based on `i18n` HEAD `450bedf65022d26a34ec45398a4f9b57005125cd`.

| Commit | Contents |
|---|---|
| `229c8de2a1a5b43a95e33c50e4f5893d0ec05aeb` | Stage 4.3 production read-only baseline evidence + Phase 19 acceptance harness (`scripts/stage43-verify-english.py`, `stage43-work/**`) |
| final Stage 4.3 commit | `CONEXAO_BR_ENGLISH_STAGE_4_3_REPORT.md`, `docs/english-stage43-production-deployment.md`, the Phase 19 `before`-matrix artefacts |

Files added (all new; **no existing file was rewritten**):

```
CONEXAO_BR_ENGLISH_STAGE_4_3_REPORT.md                  (this report)
docs/english-stage43-production-deployment.md            (operator runbook §1–§15)
scripts/stage43-verify-english.py                        (Phase 19 acceptance harness, read-only)
stage43-work/reports-digest.md                           (distilled report digest used for planning)
stage43-work/lint-all.php                                (repo-wide PHP parse linter)
stage43-work/production-baseline/probe.py                (round-1 read-only probe)
stage43-work/production-baseline/rest_probe.py           (authenticated read-only REST inventory)
stage43-work/production-baseline/findings.md             (round-1 narrative)
stage43-work/production-baseline/rest-probe.json         (machine-readable inventory)
stage43-work/production-baseline/raw-rest/**             (plugins, themes, types, taxonomies, settings,
                                                          counts, redirects, sitemap, REST captures)
stage43-work/production-baseline/s43-before*.json/.md    (Phase 19 matrix, `before` phase)
stage43-work/production-baseline/s43-before*-raw/**      (matrix raw captures)
```

---

## 27. Exact files intentionally not changed

| Not changed | Why |
|---|---|
| `wp-content/themes/conexao-br-irlanda/**` (including `inc/polylang.php`) | the English code is already the approved implementation; the one candidate change (hreflang policy B, §15) cannot be executed or validated in this environment → Stage 4.4 item |
| `wp-content/plugins/**` | nothing to fix; the importer deactivation is a production *setting*, not a code change |
| `.htaccess` | no defect found; production does not execute it (§19) |
| `scripts/ivvcc-import-robust.py` | its hard-coded credential fallback is **reported** (§29), not silently edited (unrelated live tooling, out of scope) |
| `master` | the English layer is not merged; merging/fast-forwarding is a deployment decision for the operator |
| Flutter repository | frozen by scope |
| Production database | no checkpoint and no write was possible/needed (§28) |

---

## 28. Rollback state

* **Production rollback is a no-op**: nothing was changed, so nothing needs reverting. Production
  remains: WordPress core 7.1.2 · theme `conexao-br-irlanda` (pre-English build) · the plugin set and
  versions of §2 · menus 1381/1382 · `page_on_front=9` / `page_for_posts=10` · Polylang absent ·
  `.htaccess`/Jetpack untouched.
* **No S0 checkpoint exists** (UpdraftPlus 1.26.8 is active on production, but this environment has no
  wp-admin access). The runbook therefore *starts* with the backup step (runbook §3).
* Documented rollback order for the real deployment (runbook §13): revert/disable newly deployed code
  → delete only the newly created EN records → restore menu/configuration → restore cache → re-verify
  PT behaviour → re-run the matrix. A full DB restore from the S0 checkpoint is the last resort and
  only from a checkpoint verified complete.
* Stage 4.3’s own repository work is trivially revertible (delete the added files, or discard
  `cline/drac45r7`); no existing file was modified, so no history surgery is involved.

---

## 29. Remaining limitations

1. **No production write path in this environment** — no wp-admin, no SSH/SFTP, no ZIP upload, no
   WP-CLI. This is the single reason the stage is BLOCKED; every other limitation below is secondary.
2. **Polylang absent on production** → none of the English behaviour could be observed rendered; the
   baseline is a *pre-deployment* baseline, and all post-deployment gates are specified but unrun.
3. **No local WordPress runtime** (no Docker/MySQL/PHP stack, no DB replica) → the WordPress-dependent
   regression suites could not be executed, and no production change could be validated locally first
   (which is precisely why none was attempted).
4. **No browser/device tooling** → Phase 20 is NOT_TESTABLE (§23).
5. **Edge WAF/rate limiting** → authenticated REST with a browser UA returns 403 and uncached HTML can
   return 429; a small number of round-1 probes were affected and are explicitly marked in the round-1
   findings; all reported rows were re-measured with the accepted UA.
6. **PHP version of production** is not exposed (WordPress.com strips it) → recorded NOT_EXPOSED rather
   than guessed.
7. **`/sitemap.xml` ownership conflict** remains unresolved until deployment (§17) — the decision
   criterion is the deployed output.
8. **hreflang policy B is specified but not applied** (§15) — it requires a one-line theme patch and a
   WordPress runtime to validate.
9. **Menus, EN content, EN taxonomy terms and the three meta descriptions are not created** — they are
   production editorial data and/or require wp-admin (§8/§11/§12/§16).
10. **Deployment-integrity risk recorded**: the English layer lives on `i18n` (`450bedf`) and is 18
    commits ahead of `master`; building the deploy ZIP from `master` would silently ship the
    pre-English theme (§3).
11. **Security finding (not fixed, out of scope)**: a production **Application Password** is committed
    in `scripts/ivvcc-import-robust.py:20` (`WP_USERNAME` / `WP_APPLICATION_PASSWORD` defaults) and it
    authenticated as an administrator today. Recommendation: rotate it in wp-admin and remove the
    hard-coded fallback; never commit credentials. Recorded in runbook §15.
12. **Production hygiene finding (not fixed)**: `conexao-event-importer` 1.7.1 is active on production
    although it is documented as local-only tooling (§9).
13. **Production locale defect (pre-existing, not fixed)**: `WPLANG` is empty → PT pages render
    `<html lang="en-US">`; the theme deploy fixes the lang attribute and Polylang fixes the per-language
    locale, but the WordPress site language itself is still unset.

---

## 30. Website-only Stage 4.4 prerequisites

| # | Prerequisite | Owner | Blocking? |
|---|---|---|---|
| 1 | Grant the deploy path (wp-admin administrator) **or** fast-forward `master` to `450bedf` and build the theme ZIP from the `i18n` tip; upload `dist/conexao-br-irlanda.zip` | site admin | yes — every other item depends on it |
| 2 | UpdraftPlus files+DB checkpoint before any change (runbook §3) | site admin | yes |
| 3 | Install + configure Polylang Free 3.8.9 exactly per runbook §4 (wizard in one sitting) | site admin | yes |
| 4 | Create the EN front page (linked translation of page 9, slug `home`) and the 7 EN static pages (runbook §5/§6) | editor | yes for Phases 2–3 |
| 5 | Create the EN primary + footer menus and assign the locations (runbook §9) | editor | yes for the EN header |
| 6 | Author EN terms per runbook §7, then the EN content manifest (runbook §8) | editor | yes for Phase 5 |
| 7 | Apply the hreflang policy-B one-liner and validate it in the local stack (runbook §10) | developer | no (gate #13 only if the two sets contradict) |
| 8 | Reconcile the sitemap (runbook §12) after measuring the deployed output | site admin | no |
| 9 | Author the three PT/EN meta descriptions + EN page descriptions (runbook §11) | editor | no |
| 10 | Deactivate `conexao-event-importer` on production (runbook §15) | site admin | no (risk reduction) |
| 11 | Rotate the leaked Application Password and remove the hard-coded fallback (runbook §15) | site admin | no (security) |
| 12 | Run the WordPress-dependent regression suites in the local stack and re-verify the frozen REST contract against production (§24/§18) | developer | yes for Phase 18 |
| 13 | Run the acceptance harness on production and attach the report: `python3 scripts/stage43-verify-english.py --phase after --include-rest --out /tmp/s43-after` | operator | yes |
| 14 | Human visual validation of the EN chrome (desktop + mobile) per runbook §9/§13 | operator | yes for Phase 20 |

No prerequisite in this list requires touching the Flutter app, the REST contract, the B2 allowlist,
the Event/Lazer identity model, the Portuguese URLs or the legacy redirect map body.

---

## 31. Final acceptance matrix (Phase 21 hard gates)

| # | Hard gate | Status | Evidence |
|---|---|---|---|
| 1 | Portuguese homepage or URLs change unintentionally | **PASS (not violated)** | zero production writes (§25); PT URLs measured unchanged (§22) |
| 2 | `/en/` does not serve the English homepage directly | **FAIL — gate triggered** | `/en/` → 301 → `/eventos/encore-halloween-edition-special-guest-1926/` (§5/§22) |
| 3 | Real EN translations redirect to PT | NOT_APPLICABLE (no EN translations exist) | — |
| 4 | B1 pages silently converted to B2 | PASS (unchanged) / to re-verify | no content changed; B1 302 policy is code-verified (§7) |
| 5 | Unapproved pages in the B2 allowlist | PASS | allowlist is the exact 10 code-reviewed slugs (§7) |
| 6 | Event identity duplicates | PASS (nothing written) | §9 |
| 7 | Lazer identity duplicates | PASS (nothing written) | §10 |
| 8 | Hidden Event becomes public | NOT_MEASURABLE today / unchanged | §9 |
| 9 | Source-inherited EN Event duplicated | NOT_APPLICABLE (no EN events) | §9 |
| 10 | County/town duplication returns | PASS currently (26/251 terms) / gate to hold after activation | §11 |
| 11 | `dublin-en`-style location terms appear | PASS currently (none) / gate to hold | §11 |
| 12 | EN primary menu points to wrong-language destinations | NOT_APPLICABLE (no EN menu yet; the EN header renders nothing by design) | §12 |
| 13 | Canonical/hreflang contradictory | PASS today (no hreflang emitted without Polylang) / policy B specified | §15 |
| 14 | B2 URLs in the sitemap | PASS today (Jetpack index + theme producer contain no `/en/` B2 URLs) | §17 |
| 15 | Legacy redirects loop or lose their destination | **PASS** — 13/13 correct, single hop | §19 |
| 16 | REST `lang=en` differs materially from the frozen contract | **FAIL (expected pre-deployment)** — module not deployed: `lang=xx` → 200, no `conexao_language` | §18 |
| 17 | PT/EN cache contamination | NOT_MEASURABLE (no EN surface); language-scoped keys are code-verified | §20 |
| 18 | Production data outside the approved rollout modified | **PASS** — nothing was modified at all | §25 |
| 19 | Flutter code/repository modified | **PASS** — not accessed, not modified, not built, not tested | §27 |
| 20 | A change requiring redesign of the established WordPress architecture | **PASS** — no architecture change was needed or made | §14/§26 |

Two gates (#2 and #16) are *measured failing* on production today for the same reason — the English
layer (theme code + Polylang) has not been deployed — and neither can be closed from this environment
because the deployment path (ZIP upload via wp-admin) is unavailable. Per Phase 21, that is a
**STOP/BLOCKED** condition, not a licence to change production speculatively.

---

## 32. Final classification

**ENGLISH STAGE 4.3 — BLOCKED**

* The website side of the bilingual architecture is **complete, frozen and verified in code**
  (Stage 4.1 REST contract present and untouched; Stage 1/2/3.x theme layer present; B2 allowlist,
  B1 policy, taxonomy policy, SEO ownership, redirect precedence all confirmed at the source level).
* Production is **verified to be pre-deployment**: no Polylang, no English theme layer, no EN pages,
  no EN menus; `/en/` is a 404 answered by a core 404-guess (hard gate #2).
* The **only** blocker is access: production is WordPress.com-managed and the documented deployment
  mechanism is a manual ZIP upload through wp-admin, which this environment does not have.
* Everything that could be established without production writes **was**: a measured baseline, an
  exact ordered runbook with gates and rollback, the acceptance harness, the frozen-contract
  verification plan, the editorial backlog inventory, and the static/regression checks that run
  outside WordPress (`php -l` clean on 287 files; 49/49 nav-language-context tests; `git diff --check`
  clean).
* **Zero production changes were made**, and nothing was claimed as verified that was not measured.
  The stage can be converted to `PASS`/`PASS WITH LIMITATION` by executing
  `docs/english-stage43-production-deployment.md` §3–§14 with wp-admin access and attaching the
  `--phase after` harness report.

