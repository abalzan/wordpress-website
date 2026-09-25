# CONEXAO_BR_ENGLISH_STAGE_1_REPORT.md

Stage 1 of the approved English-support architecture (see
`CONEXAO_BR_ENGLISH_SUPPORT_AUDIT.md` and
`CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`): i18n foundation only.
Polylang, `/en/` routing, hreflang, language switcher and bilingual content
are deliberately **not** implemented in this stage.

---

## 1. Status

**ENGLISH I18N FOUNDATION — PASS**

All Phase 1–14 work items completed locally. Production untouched.

## 2. Baseline state (recorded before any edit)

| Item | Value |
|---|---|
| Branch | `master` |
| HEAD | `9cb051178afcb57b17b36255832c562a9bec30e9` |
| Working tree at start | Clean (only the two untracked audit/decision docs) |
| WordPress (container) | 7.0.2-class image, PHP 8.5, MySQL via Docker Compose |
| Site locale (DB) | `WPLANG` empty → `en_US` (confirmed via `wp option get WPLANG`) |
| Theme text domain | `conexao-br-irlanda` (declared in `style.css`; `load_theme_textdomain()` already called at `functions.php:499` → `languages/` — which did **not** exist) |
| Plugin text domains | `conexao-data-model`, `conexao-content`, `conexao-admin-ux`, `conexao-event-runtime`, `conexao-event-importer`, `conexao-leisure-migration`, `conexao-sponsor-migration` (declared in headers; **zero** `load_plugin_textdomain()` calls repo-wide) |
| Translation files | Zero `.po`/`.mo`/`.pot` anywhere in `wp-content/` (confirmed with `find`) — matches the audit |
| Theme gettext calls | 446 (audit figure); `functions.php` alone had 38 `__()` calls |
| `og:locale` | Hard-coded `pt_BR` at `inc/seo.php` (audit line ~261) |
| Production `<html lang>` | `en-US` (accidental `en_US` locale), content Portuguese |
| JS strings | No `wp_localize_script` / `wp_add_inline_script` anywhere |

The audit's locale problem reproduced locally: `wp eval 'date_i18n("F", ...)'`
printed English month names while all content is Portuguese.

## 3. Exact gettext inventory before/after

| Surface | Before | After |
|---|---|---|
| Theme gettext-wrapped strings (POT msgids) | 446 calls in PHP, 0 catalog files | **382 unique msgids** in `conexao-br-irlanda.pot` (deduplicated; includes the 4 previously hard-coded recurrence labels, 3 nav override labels, ~25 SEO strings and 18 JS UI strings) |
| Theme translation files | 0 | `languages/conexao-br-irlanda.pot`, `languages/pt_BR.po` (382 identity msgstr), `languages/pt_BR.mo`, `languages/en_US.po` (65 curated translations), `languages/en_US.mo` |
| Plugin loaders | 0 | 7/7 plugins call `load_plugin_textdomain()` on `init` |
| Plugin POTs | 0 | 7 (`languages/<domain>.pot` per plugin) |
| Hard-coded user-facing PT strings (audit gaps) | ~25 + recurrence grammar + 14 JS literals | Externalized (see §5) |

The POT msgid count is lower than the audit's 446 *call* count because a POT
contains unique strings, not call sites. Nothing was dropped: `wp i18n
make-pot` scanned all theme PHP.

## 4. Translation catalog files created

Theme — `wp-content/themes/conexao-br-irlanda/languages/`:

- `conexao-br-irlanda.pot` — generated with `wp i18n make-pot` (WP-CLI
  2.12), translators comments preserved, `tests/` excluded.
- `pt_BR.po` + `pt_BR.mo` — **identity catalog** (every msgstr = msgid).
  Portuguese is the source language; the catalog pins the current visible
  PT wording exactly and enables real pt_BR lookups. Header:
  `Plural-Forms: nplurals=2; plural=(n > 1);`.
- `en_US.po` + `en_US.mo` — **curated, human-authored** catalog covering
  the Stage-1 surfaces: recurrence labels, weekday names, navigation
  overrides, SEO title/description patterns, breadcrumb root and all JS UI
  strings. Untranslated entries fall back to the Portuguese source by
  design — nothing half-translated can be emitted. Header:
  `Plural-Forms: nplurals=2; plural=(n != 1);`.

Both `.mo` files compile clean with `msgfmt --check`. Every `en_US` msgid
was verified to exist in the POT (0 missing).

Plugins — `wp-content/plugins/<plugin>/languages/<plugin>.pot` for all 7
plugins (admin strings catalogued; no admin copy was translated in source).

**No pre-existing translation assets were overwritten** — none existed at
baseline; this was re-verified before generating.

## 5. Hard-coded Portuguese strings externalized

### 5.1 Event recurrence labels — `functions.php`

`conexao_event_recurrence_label()` refactored into a language-aware
presentation mechanism; the recurrence **model is untouched** (ISO weekday
codes 1–7, `Conexao_Event_Recurrence` meta/evaluator/storage):

- `conexao_recurrence_weekday_name( $iso_day )` — localized full weekday
  name via gettext (source strings = PT names).
- `conexao_recurrence_list_join( $items )` — localized list glue:
  `'%1$s e %2$s'` for two items; for 3+ items the comma-joined head plus
  the final item pass through the same pattern. The EN catalog renders
  "Monday and Wednesday" / "Monday, Wednesday and Friday".
- `conexao_recurrence_present_days( $iso_days )` — grammar dispatcher:
  PT locales keep the exact current wording including the PT-specific
  `-feira` stripping rule (which only runs on the PT path); non-PT locales
  join translated names directly.
- Templates call `conexao_event_recurrence_label()` unchanged
  (`template-parts/event-card.php`, `template-parts/event-preview.php`).

PT output remains byte-identical: "Toda quarta-feira", "Toda segunda e
quarta", "Toda segunda, quarta e sexta", "Toda sábado e domingo".

### 5.2 Navigation runtime overrides — `functions.php`

- `Início` (Home label) → `__( 'Início', 'conexao-br-irlanda' )`
- `Lazer e turismo` (Lazer rename) → `__( 'Lazer e turismo', … )`
- Fallback CPT section titles (`Guias`, `Eventos`, `Lazer e turismo`,
  `Empregos`, `Apoiadores`) → wrapped the same way.

Menu IDs, structure, slugs and routing unchanged. The matching logic
(`'home' === $title || 'início' === $title …`) is untouched.

### 5.3 SEO strings — `inc/seo.php`

Wrapped (output byte-identical in PT):

- Title patterns: `Guia Prático`, `Eventos na Irlanda`, ` em %s` (job
  location), `Empregos`, `Apoiadores`/`Lazer` singular fallbacks, archive
  titles `Guias Práticos`, `Eventos na Irlanda`, `Empregos para
  Brasileiros na Irlanda`, `Apoiadores na Irlanda`, `Lazer & Turismo na
  Irlanda`.
- `Busca: %s` (search title), 404 title `Página não encontrada`.
- All 14 dynamic meta-description templates (singular sprintf-based,
  archive/404 plain).
- `conexao_archive_title()` (6 CPT labels), `conexao_archive_description()`
  (5 archive subtitles), breadcrumb root `Início`, search crumb `Busca`,
  `conexao_cpt_label()` (6 labels).

`'Irlanda'` (proper noun) intentionally left literal. **No hreflang, no
bilingual URLs** — the SEO layer remains functionally single-language.

### 5.4 JavaScript strings — `assets/js/main.js`

New centralized mechanism: `conexao_js_i18n_strings()` (in `inc/i18n.php`)
injected via `wp_add_inline_script( 'conexao-main', 'window.ConexaoI18n =
{…};', 'before' )` inside the existing `conexao_enqueue_scripts()`. No
framework added. `main.js` reads `window.ConexaoI18n` via a local `t(key,
fallback)` helper (fallbacks = current PT literals, used only if the inline
payload is absent).

Externalized: submenu toggle aria template, "Link copiado", filter label +
active-filter count (**now correctly singular/plural**: "%d filtro ativo"
/ "%d filtros ativos" — previously always plural), sponsor carousel
position status ("Apoiador %1$d de %2$d"; the arrow aria-labels were
already gettext-wrapped in `template-parts/featured-sponsors.php`),
infinite-scroll "Carregando...", error/retry text, end-of-results text,
load-more "Carregar mais"/"Carregando..." and the loaded-cards
announcement — which now uses proper singular/plural forms ("Mais 1 evento
carregado." / "Mais 3 eventos carregados."; the old code always emitted
the plural). No PT/EN copies were hard-coded into `main.js`.

## 6. Recurrence-language implementation

See §5.1. Extension contract for Stage 2: `conexao_current_locale()` decides
the grammar path; adding `en_US` support requires only catalog entries, no
further code. Tests: `tests/test-recurrence-i18n.php` (12 assertions: PT
byte-exact for 1/2/3/4 days + weekend, EN natural wording, empty/invalid
edge cases, non-recurring empty label).

## 7. JavaScript localization mechanism

`wp_add_inline_script` (before `conexao-main`, enqueued with
`strategy: defer` + `in_footer`), producing `window.ConexaoI18n` as JSON
from the single gettext source `conexao_js_i18n_strings()`. Verified
rendered on `/` and `/eventos/` with exact PT values. Pluralization is
resolved with the gettext machinery (distinct singular/plural keys); JS
picks the pre-translated form and substitutes the `%d`/`%1$d` placeholders.

## 8. Plugin textdomain loading

All 7 plugins append a named loader + `add_action( 'init', … )`:

```php
function conexao_<name>_load_textdomain() {
    load_plugin_textdomain( '<domain>', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'conexao_<name>_load_textdomain' );
```

`languages/` directories created in each plugin; POT generated per plugin.
No plugin functionality, admin UX or copy changed (diffs verified as pure
additions). Note: `conexao-sponsor-migration` is currently **inactive**
locally, so its loader is verified in tests via the plugin source file.

## 9. Locale correction

- Code-level: `inc/i18n.php` hooks the `locale` filter
  (`conexao_correct_default_locale`, priority 5): an unconfigured `en_US`
  install is normalized to `pt_BR`; any explicitly configured non-`en_US`
  locale is never overridden.
- Local DB (documented local-only change, per the stage rules):
  `wp core language install pt_BR` + `wp core language activate pt_BR`
  (which set `WPLANG=pt_BR`) + `wp option update date_format
  "j \d\e F \d\e Y"`.
- Result: `<html lang="pt-BR">` on every page (verified on 16 routes),
  `og:locale` dynamic (`conexao_og_locale()` reads
  `conexao_current_locale()`), dates Portuguese.

No `/en/`, no URL/cookie/browser language inference, no switcher.

## 10. Date/locale verification

- Blog/front-page/archive dates: `get_the_date()` with the corrected
  `date_format` renders "6 de setembro de 2026" style (verified on `/`
  and `/blog/`).
- Event dates: `date_i18n( 'd M Y' )` and `template-parts/event-card.php`
  render Portuguese (the card's existing PT month/weekday maps remain in
  place and now agree with the site locale; left untouched by design).
- Recurrence output: PT weekday grammar unchanged (test-asserted).
- No date storage, event meta, DB format, UTC/timezone or number-format
  behavior was modified.

## 11. Portuguese regression results

Compared before/after on all changed surfaces (local environment):

| Surface | Result |
|---|---|
| Navigation labels (Início, Guias, Eventos, Lazer e turismo, Empregos, Apoiadores, Blog, Contato) | Byte-identical |
| Recurrence labels | Byte-identical (test-asserted) |
| Breadcrumbs (root "Início", CPT labels) | Byte-identical |
| Page titles (/, /guias/, /blog/, 404, event single) | Byte-identical (e.g. `Guias Práticos \| Conexão BR`, `Página não encontrada \| Conexão BR`, `… \| Eventos na Irlanda \| Conexão BR`) |
| Meta descriptions | Byte-identical |
| 404 title | Byte-identical |
| Event UI labels | Unchanged |
| Filter accessibility labels | Same PT text; singular count now grammatically correct ("1 filtro ativo") |
| Copy-button feedback ("Link copiado") | Same (payload-verified) |
| Sponsor accessibility text | Same (payload-verified) |
| Infinite-scroll / load-more status | Same PT text; 1-item announcement now correctly singular |
| Dates | English "September 6, 2026" → Portuguese "6 de setembro de 2026" (intended Stage 1 correction) |
| `<html lang>` | `en-US` → `pt-BR` (intended correction) |
| `og:locale` | `pt_BR` (unchanged value, now dynamic) |
| Canonical URLs | Unchanged PT URLs; no `/en/` generated |

16-route HTTP sweep (home, all 6 CPT archives, /blog/, /empregos/,
/contato/, /sobre-nos/, /irlanda/, search, 404, three filtered URLs): all
expected status codes (200/404), `lang="pt-BR"`, `og:locale=pt_BR`,
0 hreflang, 0 `/en/` URLs, 0 PHP warnings/errors in output, 0 debug.log
entries dated today. JavaScript: `node --check` passes; no browser/device
validation was performed in this stage (no browser tooling available) —
documented as a limitation.

## 12. Test commands and exact results

All tests run inside the local container:

```
docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-recurrence-i18n.php
→ Recurrence i18n: 12 passed, 0 failed

docker compose exec wordpress php /var/www/html/wp-content/themes/conexao-br-irlanda/tests/test-i18n-foundation.php
→ i18n foundation: 28 passed, 0 failed

test-event-location-filters.php    → RESULTS: 44 passed, 1 failed   (pre-existing, see below)
test-guide-breadcrumb-filter.php   → RESULT:  36 passed, 0 failed
test-leisure-attribute-normalization.php → RESULT: 22 passed, 0 failed
test-leisure-card-map-action.php   → RESULT:  44 passed, 0 failed
test-leisure-multiselect-filters.php → RESULT: 62 passed, 0 failed
test-leisure-related-events.php    → RESULT:  19 passed, 0 failed   (includes recurrence label assertion)
test-sponsor-archive-ordering.php  → RESULT:  15 passed, 0 failed
```

The single failure in `test-event-location-filters.php`
("unfiltered archive returns all four test events (incl. missing
county/town)") was **verified to reproduce identically with the Stage 1
theme diff stashed** — a pre-existing, environment-dependent failure
(event-query transient state), not caused by Stage 1.

Catalog validation: `msgfmt --check --statistics` clean for both catalogs;
65 curated EN entries all present in the POT (0 missing, 0 drift).

Static checks: `php -l` clean on all modified/new PHP files; `node
--check` clean on `main.js`; `git diff --check` clean.

## 13. Production safety confirmation

- No plugin/theme ZIPs built for upload; nothing deployed.
- No WordPress.com settings, production locale, menus, content or
  redirects touched.
- The `WPLANG`/`date_format` changes are **local Docker environment only**
  (documented in §9).
- Polylang NOT installed; `/en/` NOT created; hreflang NOT emitted;
  database content NOT translated.
- Event identity fields, importer matching, export JSON, event-runtime
  status logic, Lazer UUID logic, sponsor identity, REST endpoints, search
  query architecture, sitemap architecture, `.htaccess`, redirect rules,
  CPT/taxonomy slugs: untouched (0 changes outside the file list in §14).

## 14. Exact files changed

Modified (10):

1. `wp-content/themes/conexao-br-irlanda/functions.php`
2. `wp-content/themes/conexao-br-irlanda/inc/seo.php`
3. `wp-content/themes/conexao-br-irlanda/assets/js/main.js`
4.–10. `wp-content/plugins/{conexao-data-model, conexao-content,
   conexao-admin-ux, conexao-event-runtime, conexao-event-importer,
   conexao-leisure-migration, conexao-sponsor-migration}/<plugin>.php`
   (append-only loader + `languages/<domain>.pot`)

Created:

- `wp-content/themes/conexao-br-irlanda/inc/i18n.php`
- `wp-content/themes/conexao-br-irlanda/languages/{conexao-br-irlanda.pot, pt_BR.po, pt_BR.mo, en_US.po, en_US.mo}`
- `wp-content/plugins/<each of 7>/languages/<domain>.pot` (7 files)
- `wp-content/themes/conexao-br-irlanda/tests/test-recurrence-i18n.php`
- `wp-content/themes/conexao-br-irlanda/tests/test-i18n-foundation.php`
- `CONEXAO_BR_ENGLISH_STAGE_1_REPORT.md` (this file)

## 15. Exact files intentionally not changed

`header.php` (uses `language_attributes()` — no hard-coded lang),
`template-parts/event-card.php` (PT date maps remain correct),
`template-parts/featured-sponsors.php` (arrow aria-labels already
gettext-wrapped), event runtime / importer / leisure-migration /
sponsor-migration includes, REST endpoints, `inc/search.php`, sitemap
code, `.htaccess`, all templates' markup, all CSS, `compose.yaml`,
`scripts/`, `docs/`.

## 16. Known limitations

- `en_US` catalog covers a curated subset (65 strings); full-theme EN
  coverage is deferred until Stage 2 makes it active. Untranslated strings
  safely fall back to Portuguese.
- `test-event-location-filters.php` has 1 pre-existing failure (reproduces
  without the Stage 1 diff).
- No browser/device validation performed in this stage (no browser tooling
  in the environment). JS correctness was verified by syntax check plus
  payload inspection.
- Plugin translations are catalogued (POT) but no plugin `.po`/`.mo`
  pairs were generated; admin English support is deferred by design.
- The `date_format`/locale DB changes are local; production
  WordPress.com General Settings will need the same values at deploy time
  — production untouched in this stage.

## 17. Stage 2 prerequisites

- Polylang install/activate + `pt_BR` default language + `/en/` namespace.
- Wire `conexao_current_locale` (the single extension point in
  `inc/i18n.php`) to Polylang's per-request locale — no other theme change
  needed for locale resolution; `og:locale` and the recurrence grammar
  follow automatically.
- Teach `inc/seo.php` about per-language canonicals/hreflang (explicitly
  NOT done here) and the theme sitemap about language variants.
- Complete the `en_US` catalog (add remaining msgids to the curated PO).
- Decide production deployment of the locale/date-format settings.

## 18. Final classification

**ENGLISH I18N FOUNDATION — PASS**
