# Conexão BR Irlanda — English-Support Architecture Decision

**Stage:** Stage 0 / Architecture Decision  
**Date:** 2026-09-20  
**Status:** APPROVED  
**Source of truth:** `CONEXAO_BR_ENGLISH_SUPPORT_AUDIT.md`  

---

## 1. Executive decision

The site will gain English support via **Polylang (Free, plus Pro for editorial workflow)** with an **`/en/` URL prefix**, **Portuguese (`pt_BR`) as the default language**, and the **existing custom SEO layer (`inc/seo.php`) as the single SEO owner**. Portuguese URLs remain byte-for-byte unchanged. English is an additional language layer, never a replacement.

This decision preserves all identity-layer invariants:

- **One Event post per production source identity** — `_event_source`, `_event_source_id`, `_event_export_uuid`, `_event_url`, scheduling fields, and lifecycle/status fields remain language-neutral.
- **One Lazer post per `_lazer_uuid`** — UUID matching on import/export is never broken by a language variant.
- **Counties/towns are shared** — no per-language duplicate terms.
- **The `_event_status` visibility gate keeps precedence** over any language filter.

The existing EN→PT 301 redirects remain as legacy redirects; `/en/` paths do not collide because they carry an unambiguous prefix. No redirects are modified in this stage.

---

## 2. Chosen architecture

| Dimension | Decision |
|---|---|
| Multilingual mechanism | **Polylang** (Free; Pro recommended for editorial workflow) |
| Default language | **Portuguese (`pt_BR`)** |
| English URL namespace | **`/en/` prefix** |
| URL structure (PT) | Unchanged — `/guias/`, `/eventos/`, `/lazer/`, `/blog/`, static pages |
| URL structure (EN) | `/en/guias/`, `/en/eventos/`, `/en/lazer/`, `/en/blog/`, `/en/irlanda/`, … |
| Locale model | WP site locale → `pt_BR`; EN locale → `en_US` on `/en/` paths |
| Content relationship | Polylang translation-to-translation linking (one underlying record + language variant) |
| Taxonomy relationship | Shared terms, per-language names; slugs preserved as PT |
| Event identity | Single record per `_event_source` + `_event_source_id`; language is a post attribute only |
| Lazer identity | Single record per `_lazer_uuid`; language is a post attribute only |
| Missing-translation policy | **B5: per-content-type hybrid** (see §12) |
| SEO ownership | **Theme `inc/seo.php`** (single owner; Polylang defers) |
| Sitemap ownership | **Theme custom sitemap** (Jetpack reconciled on production) |
| Search behavior | Polylang-scoped search to active language; B2-fallback records surfaced |
| Cache strategy | Per-language transient keys; `/en/` prefix distinguishes URL-cache naturally |
| Redirect strategy | Legacy EN→PT 301s preserved; `/en/` prefix avoids collision |
| Editorial workflow | PT first, EN later; outdated-flag model; editors see exists/missing/outdated |
| REST / Flutter strategy | `?lang=en` parameter; `_links.translations`; status-gate composed with language filter |
| Importer/runtime strategy | Force `pt_BR` (or source-detected language) on import; identity untouched; automated identity tests |
| WordPress.com hosting | Business plan supports custom plugins; Polylang verified compatible |
| Rollback | Polylang deactivation removes language metadata; PT content/URLs unchanged; reversible |


## 3. Alternatives evaluated

### Option A — Polylang (Free/Pro)

| Criterion | Assessment |
|---|---|
| Implementation effort | **MEDIUM.** Term/post language assignment, hreflang, switcher out of the box. Theme already 85% gettext-wrapped. |
| Migration effort | **LOW–MEDIUM.** Set default language `pt_BR`; existing content stays put; language meta applied en masse. |
| PT URLs preserved | **Yes** — Polylang default-language behavior keeps original URLs (`/guias/…` unchanged). |
| CPTs | Full support — translation-to-translation linking; no CPT registration changes needed. |
| Shared taxonomies | Term translations model: one term ID, per-language display name. EN name added via Polylang term translation. Slug stays PT (canonical). |
| Custom meta | Polylang does not touch meta keys; `_event_source`, `_event_source_id`, `_lazer_uuid` etc. remain untouched. |
| Custom filters | `pre_get_posts` interplay must be tested (filter ordering with `_event_status` gate). Strategy defined in §11. |
| Custom SEO layer | Polylang can be configured to defer canonical/hreflang; `inc/seo.php` extended to be language-aware. |
| Custom sitemap | Theme custom sitemap is the canonical producer; Jetpack sitemap reconciled. |
| Jetpack | Present on production; must coordinate hreflang/sitemap; not a blocker. |
| WordPress.com | Business plan supports custom plugin ZIP upload; Polylang is well-tested on WP.com. |
| Editorial complexity | Free provides basic linking; Pro provides translation UI, outdated-flag, string translation. |
| Rollback | Moderate — deactivate plugin; language metadata cleared; PT content/URLs intact. |
| Performance | Low overhead — query filter + termtaxonomymeta; no heavy table proliferation. |

**Verdict:** Best fit for this codebase. Smallest footprint, explicit language deferral model, REST `?lang=` contract for Flutter, proven WP.com compatibility.

### Option B — WPML

| Criterion | Assessment |
|---|---|
| Implementation effort | **HIGH** — heavier stack: string translation tables, translation management queue, XLIFF. |
| Migration effort | **MEDIUM** — language assignment + string-translation setup. |
| PT URLs preserved | Yes. |
| Custom-plugin compatibility | Same `pre_get_posts` caveats as Polylang **plus** larger hook footprint; higher risk of interfering with the `_event_status` gate and `conexao-admin-ux`. |
| Rollback complexity | **HIGHER** than Polylang — more DB tables (`wp_icl_*`), more options, harder to clean. |
| Performance | Higher overhead — more tables, more query hooks. |

**Verdict:** Rejected. The audit's finding holds: "WPML introduces a heavier translation-management layer." This site has ~446 gettext calls and a custom SEO layer — it does not need WPML's full translation-management machinery. The extra tables and hooks increase the risk surface for the `pre_get_posts` event gate and the custom sitemap, and complicate rollback. Not justified for this content volume or team size.

### Option C — Locale-only gettext foundation

| Criterion | Assessment |
|---|---|
| Implementation effort | **LOW–MEDIUM** — create `languages/` + POT, translate UI strings, add `load_plugin_textdomain()`, fix locale oddity. |
| PT URLs | Untouched. |
| SEO | No hreflang/canonical issues (single language). |
| Limits | **Does not produce an English site.** Only UI chrome is translatable; DB content remains PT. |

**Verdict:** **Stage 1 foundation only.** Not a complete bilingual architecture by itself. The chosen architecture uses gettext as the groundwork (Stage 1) and adds Polylang for content translation (Stage 2+). This is explicitly the recommended sequence in the audit §14.

### Option D — Custom locale/meta implementation

| Criterion | Assessment |
|---|---|
| Implementation effort | **HIGH** — must hand-build: language assignment, post-to-post translation linking, term translation, hreflang generation, language switcher, REST `?lang=` filtering, canonical switching, sitemap alternates, per-language menus, cache keying by language, outdated-flag UI in `conexao-admin-ux`. |
| Migration | HIGH — custom data migration for language assignment. |
| Risk | HIGH — reimplementing Polylang's core without battle-testing; high probability of subtle bugs. |
| Rollback | Moderate — but custom code paths may have undocumented coupling. |

**Verdict:** Rejected. Polylang (Free) already provides every element this would require to build. The 446-gettext-covered theme is a textbook Polylang fit. Reimplementing would consume the same effort as integration with far less confidence, and would require ongoing maintenance of a custom multilingual stack instead of delegating to a maintained plugin.

### Option E — Separate English CPT records

| Criterion | Assessment |
|---|---|
| Event identity | **Violates the hard requirement.** A second Event post carrying the same `_event_source` + `_event_source_id` would be upserted against by the next import run (UUID → source+source_id → URL → title+date dedup order), orphaning or duplicating EN records. |
| Lazer identity | **Violates the hard requirement.** A second Lazer post with the same `_lazer_uuid` would be matched/duplicated on import by UUID. |
| Storage | Doubles the post count; doubles image storage if attachments are duplicated. |
| Maintenance | Every importer, every cache, every query must learn to exclude EN duplicates. |
| CMSUX | Duplicate-edit problem: changes to identity fields must be synced across two records. |

**Verdict:** Rejected. **Material blocker.** The event and lazer identity models are non-negotiable. A separate-CPT architecture violates the single-identity invariant that the importer's dedup logic depends on. No amount of additional code can make a second post safe under the existing import pipeline. This option is categorically unsafe for this codebase.

### Option F — Separate subdomain (e.g., `en.conexaobr.ie`)

| Criterion | Assessment |
|---|---|
| Canonical domain | The audit verified `conexaobr.ie` as the sole canonical domain. `conexaobr.com` is unrelated. A subdomain is a different origin — adds ops complexity (DNS, SSL, CDN edge config, canonical-tag discipline). |
| WordPress.com | Requires additional WordPress.com Business plan configuration (domain mapping, edge cache rules). |
| Redirects | Every internal link, canonical, sitemap entry must account for the subdomain boundary. Higher risk of misconfiguration. |
| Identity | Same identity problems as Option E (would need two posts) OR a cross-origin API bridge (more custom code). |

**Verdict:** Rejected. The audit explicitly chose the single-domain `/en/` prefix as canonical. A subdomain doubles operations overhead, adds a second origin that must maintain perfect canonical discipline, and provides no benefit the `/en/` prefix cannot deliver. Not justified.


## 4. Why the chosen architecture fits this codebase

1. **Theme is gettext-ready (85%+):** 446 `__()`/`esc_html__()` calls in the theme with declared text domain. Polylang integrates with gettext transparently — the POT/.po/.mo files built in Stage 1 translate all UI chrome, while Polylang handles content-to-content translation linking. No rearchitecture of template string calls is needed.

2. **Standalone theme (no parent/child):** Polylang works at the URL and query level via `pre_get_posts` + `language_attributes()`, so it does not require theme restructuring. The `inc/seo.php` layer is explicitly taught to read Polylang's language context.

3. **Single custom SEO layer (`inc/seo.php`):** Instead of letting Polylang and the theme both emit canonical/hreflang (creating conflicts), Polylang is configured to defer SEO output. `inc/seo.php` — already the owner of canonical, `og:locale`, JSON-LD, breadcrumbs, robots, sitemap — is extended to read `pll_current_language()` and emit per-language values. One owner, zero conflicts.

4. **Event identity is metadata-based:** The event runtime uses `_event_source`, `_event_source_id`, `_event_export_uuid` as language-neutral meta. Polylang does not touch post meta keys — it adds `_pll_language` postmeta and translation-to-translation linking. The identity layer is untouched.

5. **Lazer uses `_lazer_uuid`:** Same protection — Polylang's translation linking does not alter `_lazer_uuid` or any identity meta. The ZIP import forces a language assignment; UUID matching on re-import is unaffected.

6. **Shared taxonomies (`conexao_county`, `conexao_category`):** Polylang's "shared terms" option keeps counties as proper nouns with a single term ID, translating only the display name. No duplicate Dublin/Cork terms per language.

7. **WordPress.com Business hosting:** Supports custom plugin ZIP upload. Polylang is a commonly used plugin on WordPress.com. Jetpack coordination (sitemap, CDN) is the only staging concern.

8. **`/`prefix naturally avoids redirect collisions:** The existing ~60 EN→PT 301 redirects match exact paths (`/guides/`, `/events/`, etc.) and pattern-based rules (`/guides/{slug}`). None match `/en/guides/`, `/en/events/{slug}`, etc. The `/en/` prefix creates an unambiguous namespace.

9. **Frontend is HTML, not REST:** The main frontend renders via PHP templates (`main.js` only fetches HTML for infinite scroll). Polylang's `pre_get_posts` language filtering composes cleanly with the existing template-based rendering. REST is only needed for the future Flutter app, where Polylang's `?lang=` query parameter provides a clean contract.

10. **No external CMS:** All content is entered via wp-admin or the local importer. Polylang's wp-admin translation UI (Pro) or the free "Translations" meta box is the natural fit.


## 5. Default language

| Setting | Value |
|---|---|
| WordPress site locale | **`pt_BR`** (correcting the current accidental `en_US`) |
| English locale | **`en_US`** (active only when serving `/en/` paths) |
| Default language | **Portuguese (`pt_BR`)** |
| Secondary language | **English (`en_US`)** |

**Rationale:** The audit's recommended model (default `pt_BR`, English under `/en/`) is confirmed as final. The current state — WP locale `en_US` while all content is Portuguese, `og:locale` hardcoded `pt_BR`, `html lang` rendered as `en-US` — is an inconsistency that must be resolved in Stage 1.

### Locale model consequences

| Aspect | PT default | EN |
|---|---|---|
| `<html lang>` | `pt-BR` (via `language_attributes()`, Polylang-aware) | `en-US` |
| Date formatting | `pt_BR` locale → PT date strings (`Hoje`, `Amanhã`, `de`, `às`) | `en_US` locale → EN date strings |
| Number formatting | `pt_BR` → comma decimals, period thousands | `en_US` → period decimals, comma thousands |
| Pluralization | gettext `pt_BR` plural-forms rule | gettext `en_US` plural-forms rule |
| `og:locale` | `pt_BR` (dynamic, was hardcoded) | `en_US` |
| `inLanguage` (schema) | `pt-BR` | `en-US` |
| WordPress `locale` filter | `pt_BR` | `en_US` (switched via `locale` filter when `pll_current_language() === 'en'`) |

**Implementation approach:** Stage 1 sets the site locale to `pt_BR`. On `/en/` requests, a `locale` filter switches to `en_US` *only* for date/number/i18n functions, so `get_the_date()`, `date_i18n()`, and gettext plural forms render in English. The post *content* itself is the translated field (Polylang translation linking), not the locale switch.

---

## 6. `/en/` URL strategy

### Structure

```
Portuguese (default):
  /guias/  /eventos/  /lazer/  /cursos/  /apoiadores/
  /empregos/  /blog/  /irlanda/  /sobre-nos/  /contato/

English:
  /en/guias/  /en/eventos/  /en/lazer/  /en/cursos/  /en/apoiadores/
  /en/empregos/  /en/blog/  /en/irlanda/  /en/sobre-nos/  /en/contato/
```

### Key rules

- **CPT rewrite slugs are NOT renamed.** The `guide` CPT keeps its `guias` slug; English serves under `/en/guias/{slug}/` via Polylang's `force_lang=1` prefix behavior.
- **CPT slugs stay Portuguese in the URL.** The Portuguese slug *is* the canonical slug. English pages live at `/en/{same-slug}/`. Polylang's URL mode: default language (`pt_BR`) has no prefix; secondary language (`en`) gets `/en/` prefix on all URLs including CPT paths.
- **`job` CPT singles:** Job posts live at `/empregos/{slug}/` (PT) and `/en/empregos/{slug}/` (EN).
- **`recruitment_agency` and `permit_employer`:** Admin-only CPTs with no public URLs.

### Redirect collision analysis (the High-Risk dependency)

The audit identified existing English→Portuguese 301 redirects in two layers:

**Layer 1 — `.htaccess` (Apache):**
```
RewriteRule ^guides/?$ /guias/ [R=301,L]
RewriteRule ^guides/([^/]+)/?$ /guias/$1/ [R=301,L]
RewriteRule ^events/?$ /eventos/ [R=301,L]
RewriteRule ^events/([^/]+)/?$ /eventos/$1/ [R=301,L]
RewriteRule ^courses/?$ /cursos/ [R=301,L]
RewriteRule ^jobs/?$ /empregos/ [R=301,L]
RewriteRule ^sponsors/?$ /apoiadores/ [R=301,L]
```

**Layer 2 — `inc/seo.php` `conexao_seo_redirects()` (template_redirect, priority 5):**
- Direct 301 map (~60+ entries): `/guides` → `/guias/`, `/events` → `/eventos/`, `/jobs` → `/empregos/`, `/courses` → `/cursos/`, `/sponsors` → `/apoiadores/`, `/ireland` → `/irlanda/`, `/about-us` → `/sobre-nos/`, `/contact` → `/contato/`, plus legacy guide paths, category/county patterns, Wix migration paths.
- Pattern-based: `#^/guides/([^/]+)$#`, `#^/events/([^/]+)$#`, `#^/jobs/([^/]+)$#`, `#^/courses/([^/]+)$#`, `#^/sponsors/([^/]+)$#`, `#^/categories/([^/]+)$#`, `#^/counties/([^/]+)$#`, `#^/post/([^/]+)$#`, `#^/blog/categories/([^/]+)#`.

**Collision analysis:**

All existing redirects are prefix-anchored to the root path. The `/en/` prefix creates an unambiguous namespace that none of the existing rules match:

| Existing redirect pattern | Matches `/en/...` path? | Collision? |
|---|---|---|
| `^/guides/?$` → `/guias/` | No | No |
| `^/guides/([^/]+)$` → `/guias/$1/` | No | No |
| `^/events/?$` → `/eventos/` | No | No |
| `^/categories/([^/]+)$` → `/{slug}/` | No | No |
| `^/counties/([^/]+)$` → `/{slug}/` | No | No |
| `^/post/([^/]+)$` → `/blog/$1/` | No | No |

**Which existing redirects become obsolete?** None become immediately obsolete. Every existing EN→PT 301 protects real inbound legacy links.

**Which remain as legacy redirects?** All of them remain. The entire EN→PT redirect table is kept intact.

**How `/en/` avoids redirect collisions:** 1) `.htaccess` RewriteRules are anchored to `^` — `^guides/?$` does not match `en/guides`. 2) `inc/seo.php` pattern regexes are anchored to `^/` — `#^/guides/([^/]+)$#` does not match `/en/guides/{slug}`. 3) Direct redirect map keys are exact paths (`/guides`, `/events`) — they do not match `/en/guides`.

**How real inbound legacy English links are protected:** Legacy links like `https://conexaobr.ie/guides/pps-number` continue to 301 → `https://conexaobr.ie/guias/pps-number/`. If an English translation exists, PT page's hreflang points to `/en/guias/pps-number/`. New English traffic discovers `/en/` URLs via switcher, hreflang, sitemap, internal links.

**How redirect loops are prevented:** 1) Existing EN→PT rules match only non-`/en/` paths — zero collision. 2) B1 302 redirects go to PT URLs only; PT URLs never redirect back to `/en/`. 3) Polylang `/en/` routing loads templates directly for valid translations — no redirect issued.


## 7. Language switcher model

**Mechanism:** Polylang's built-in language switcher, rendered in the theme header.

| Scenario | Switcher behavior |
|---|---|
| On `/guias/pps-number/` (PT), EN translation exists | Links to `/en/guias/pps-number/` |
| On `/guias/pps-number/` (PT), no EN translation | Links to `/en/guias/` (EN archive) |
| On `/en/guias/pps-number/` (EN), PT translation exists | Links to `/guias/pps-number/` |
| On `/en/` (EN homepage), no EN homepage exists | Links to `/` (PT homepage) |

**Display:** Text-based toggle ("PT" / "EN") or flag icons. Reuses existing CSS variables.

**Fallback:** The switcher never links to a non-existent page. Polylang's API (`pll_the_languages()`, `pll_get_post_translations()`, `pll_get_term_translations()`) provides the translation map. Missing translations fall back to the language's homepage or archive.

---

## 8. Content relationship model

### Core model

Polylang uses **post-to-translation linking**: each WordPress post has a `_pll_language` meta (`pt-br` or `en`), and a shared `trp_translation_id` links PT and EN records as a translation group. The "original" / "master" is the default-language (`pt_BR`) post.

This is **one-record-per-language linked by a shared ID** — not a one-post-to-many-variants model. This satisfies Event/Lazer identity requirements because:

- Identity meta fields (`_event_source`, `_event_source_id`, `_event_export_uuid`, `_event_url`, `_lazer_uuid`) are **not translated fields** — they are identical on both records.
- English is never a *duplicate* of the same source identity — it is a *translation variant* of the same conceptual entity.
- The importer's dedup logic (UUID → source+source_id → URL → title+date) finds the right record regardless of language.

### Per-content-type behavior (B5 policy)

| Content type | PT behavior | EN behavior | Missing-translation policy |
|---|---|---|---|
| **Guides** | Full PT articles | EN translations linked via Polylang | **B1** — EN URL 302-redirects to PT until translated |
| **Blog** | Full PT articles | EN translations linked via Polylang | **B1** — EN URL 302-redirects to PT until translated |
| **Static pages** (key: Sobre Nós, Contato, Privacy, Terms) | Full PT pages | EN translations via Polylang | **Translated first.** Remaining: **B1** redirect until translated. |
| **Static pages** (county pages, /irlanda/) | Full PT pages | EN translations via Polylang | **B2** — PT content under EN shell + notice |
| **Events** | PT + source-EN records coexist | Source-EN events are EN records | **Source-inherited.** Source-EN events = EN records. Source-PT events without EN = B2 fallback. |
| **Lazer** | Full PT directory | EN translations linked via Polylang | **B2** — PT content under EN shell + notice |
| **Sponsors (Apoiadores)** | Full PT directory | EN translations linked via Polylang | **B2** — PT content under EN shell + notice |
| **Courses (Cursos)** | External-link directory (no singles) | Same archive, EN filter UI | **B2** at archive level |
| **Employment (Empregos)** | Static landing + job singles | EN landing + job singles | **B2** for job listings. Landing page gets EN translation. |
| **Recruitment agencies** | Admin-only (no public URL) | Admin-only | No language URL implications |

### Why B5 is the safest for this site's content mix

1. **Guides/Blog are article-length, translation-required content.** B1 (redirect to PT) is the most honest and SEO-safe: canonical stays on PT, hreflang links the pair, no duplicate content, browser-translate available. B2 for narrative content creates SEO ambiguity and poor UX.

2. **Events are partially already English.** Imported events from Eventbrite, Laois Tourism frequently contain English content. Marking these as EN records means zero translation cost for a large subset. For PT-source events without EN translation, B2 (PT content under EN shell) maintains discoverability of time-sensitive community events.

3. **Directories (Lazer/Sponsors/Courses) are structured-data, not narrative.** Their value is in the data (location, contact, description). B2 (PT under EN shell with a notice) makes 100% of directory entries immediately accessible in English. B3 (exclusion) would make the EN directory look empty. B1 (redirect) would lose the EN URL context.

4. **Counties/towns are proper nouns.** Minimal translation needed. B2 at county-page level suffices.

5. **Static pages:** Key pages translated first; remaining /irlanda/ sub-pages use B2.

**Conclusion:** B5 is the safest architecture *for this specific content mix*. It avoids duplicate-content risk for narrative content, leverages source-inherited language for events, and maintains directory coverage for data-heavy types.

---

## 9. Taxonomy relationship model

### Shared taxonomies

| Taxonomy | Applied To | Translation model |
|---|---|---|
| `conexao_category` | All 6 CPTs | Term translations: one term ID, per-language names. EN name via Polylang term translation. Slug stays PT. |
| `conexao_county` | All 6 CPTs | Counties are proper nouns (Dublin, Cork, Galway). Names rarely need translation. Same term ID across languages. |
| `conexao_tag` | All 6 CPTs | Tag translations: same model as categories. |
| `conexao_town` | Events only | Towns are proper nouns. Never duplicated. Term translation only if display name differs. |

### Filter behavior

- `conexao_category`, `conexao_county`, `conexao_town` terms are **shared** across languages. One term ID for "Dublin" in both PT and EN.
- **Filter URLs** preserve the PT slug: `/eventos/?cidade=dublin` and `/en/eventos/?cidade=dublin` both use the same term slug. Filter helpers (`conexao_event_filter_url()`, `conexao_leisure_filter_url()`) are Polylang-aware (prefix `/en/` when in English).
- **No duplicate terms per language.** Fragmenting into "Dublin-PT" and "Dublin-EN" would break the filter model and require every query to OR across duplicates.

### Term name translation

Polylang's "term translations" model: one term ID, per-language `name`. The term's `slug` is shared (canonical PT slug). Translates the displayed name without fragmenting filters.

---

## 10. Event identity rules

### Hard requirement (reiterated)

Exactly **one Event post per production source identity**.

The following fields MUST remain language-neutral and identical on both PT master and EN translation variant:

| Field | Purpose | Language-neutral? |
|---|---|---|
| `_event_source` | e.g. `laois_tourism`, `eventbrite` | **Yes** |
| `_event_source_id` | External source's event ID | **Yes** |
| `_event_export_uuid` | Stable cross-instance UUID for dedup | **Yes** |
| `_event_url` | Detail URL on source site | **Yes** |
| `_event_source_url` | Same as `_event_url` (legacy) | **Yes** |
| Scheduling fields (`_event_date`, `_event_start_time`, `_event_end*`, `_event_recurrence*`) | ISO dates, weekday codes | **Yes** — pure data |
| Lifecycle fields (`_event_status`) | `published` / `expired` / `source_not_found` / `rejected` | **Yes** |
| `_event_banner`, `_event_banner_attachment_id` | Image attachment | **Yes** — media is shared |

Language-bearing fields (translated): `post_title`, `post_content`, `post_excerpt`, `_event_location`, `_event_venue`, `_event_address`.

### Import behavior

- The importer runs in the WordPress admin (default language = `pt_BR`). It forces `pt_BR` language assignment on all imported event posts.
- Source-EN events: the importer can be extended (Stage 3) to detect English source content and assign `en` language instead of `pt_BR`. No identity change.
- Export JSON format (v1.1) will gain a `"lang"` field (Stage 3) — additive, backward-compatible.
- The importer must NOT create duplicate posts. Dedup is by UUID → source+source_id → URL → title+date.

### Status gate precedence

The `pre_get_posts` hook in `conexao-event-runtime` constrains public event queries to `_event_status = published OR no status`. This MUST filter BEFORE or IN CONJUNCTION WITH Polylang's language filter.

- Both hooks are on `pre_get_posts` at priority 10.
- Event-runtime returns early in `is_admin()` and `WP_CLI` contexts (protects admin/CLI queries).
- For front-end and REST reads: both filters compose via SQL AND — status gate narrows post status, Polylang narrows language.
- The `Conexao_Event_Query::upcoming_event_ids()` helper must be made language-aware in Stage 2 so it doesn't return EN events in a PT context.

### Recurrence labels

The recurrence model stores ISO weekday codes (1–7). Only the theme's `conexao_event_recurrence_label()` renders PT text. Translating the label output to EN is a Stage 1 gettext task. The model itself is language-neutral.

---

## 11. Lazer identity rules

### Hard requirement (reiterated)

`_lazer_uuid` MUST remain stable. English must NOT create a duplicate Lazer destination.

### Model

- Each Lazer post has a `_lazer_uuid` meta. The ZIP import/export pipeline matches by UUID → slug → title.
- Polylang links a PT Lazer record and its EN translation variant via translation linking. Both records share the same conceptual `_lazer_uuid`.
- The ZIP import forces a language assignment (`pt_BR` by default; `en` for source-EN records in Stage 3). UUID matching on re-import is unaffected.
- The importer writes meta as-is; Polylang assigns `_pll_language` postmeta separately.
- Round-trip verification (Stage 3): ZIP export → import back → verify UUID matching, no duplicates, EN/PT pairing intact.

### schema.org

Lazer internal pages emit `TouristAttraction` JSON-LD (in `inc/seo.php`). The `inLanguage` property reflects the page language. No identity fields appear in structured data.

---

## 12. Missing-translation policy

**Selected: B5 — Per-content-type hybrid.**

| Policy | Selected for | Behavior |
|---|---|---|
| **B1** (redirect to PT) | Guides, Blog, key static pages | EN URL 302-redirects to PT page. Canonical stays on PT. hreflang links the pair. |
| **B2** (PT content under EN shell) | Events (PT-source), Lazer, Sponsors, Courses, Employment, county pages | EN URL renders with EN chrome but PT content body. Visible notice: "This content is displayed in Portuguese." `rel=canonical` → PT record; hreflang pair. |
| **Source-inherited** | Events from English sources (Eventbrite, etc.) | These are EN records natively — no translation needed. |

### Implementation mechanics

- **B1 (redirect):** `template_redirect` hook at priority 6 issues a 302 to the PT URL when an EN URL has no translation for B1 content types. (302, not 301 — EN page may be created later.)
- **B2 (fallback shell):** Same template renders PT `post_content` with EN chrome + notice banner. `rel=canonical` → PT record; hreflang pair; NOT in EN sitemap `<url>` entries.
- **Source-inherited:** Importer detects English source content and assigns `en` language.

### Search interaction

- EN search includes EN content + B2-fallback PT records for directory types (`leisure`, `sponsor`, `event` PT-source, `job`). B1 types (guides, blog) are PT-language scoped — EN search returns 0 results until translated.
- `_event_status` gate preserved — expired/rejected events never appear in search in either language.


## 13. Editorial workflow

### Model: PT first, EN later (W4) + outdated flag (W5)

| State | Meaning | How editors see it |
|---|---|---|
| PT published, no EN | Content exists in PT only | Switcher shows "EN — not yet available" |
| PT published, EN translated | Translation exists | Switcher links to EN; "Translations" meta box shows linked record |
| PT updated after EN exists | PT changed; EN may be stale | Outdated flag set; EN post shows "Outdated translation" banner |
| EN published, no PT | (Events only — source-EN) | Switcher links to EN index |

### Tools

| Tool | Role |
|---|---|
| Polylang Free | Language assignment, translation linking, switcher, hreflang |
| Polylang Pro (recommended) | Translation editor, outdated flag, string translation |
| Polylang Free only (fallback) | `conexao-admin-ux` extended with `_translation_outdated` meta flag |

### Workflow steps

1. **Guides/Blog:** Write PT → publish. Later, click "Add translation" → EN editor → fill → publish.
2. **Events:** Importer creates PT or EN records (source-inherited). Editors translate only hand-curated events.
3. **PT updated:** On save, set `_translation_outdated = 1` on linked EN variant. Banner shown in wp-admin. Editor reviews and clears.
4. **Static pages:** Key pages first; others use B1 redirect.

### Homepage

Homepage text is in theme-mod/Customizer fields. Stage 3 adds EN versions via Polylang string translation or per-language Customizer settings. Homepage transient keyed by language (`conexao_home_pt` / `conexao_home_en`).

---

## 14. SEO ownership

### Single-owner rule

**The theme's `inc/seo.php` is the sole owner of SEO output.** Polylang is configured to defer canonical/hreflang/sitemap output.

| SEO surface | Owner | Implementation |
|---|---|---|
| Canonical | `inc/seo.php` | Reads `pll_current_language()`. PT → `conexaobr.ie/...`; EN → `conexaobr.ie/en/...` |
| hreflang | `inc/seo.php` | `hreflang="pt-BR"`, `hreflang="en"`, `hreflang="x-default"`. Only links translations that exist. |
| `og:locale` | `inc/seo.php` (dynamic) | Was hardcoded `pt_BR` (line 261). Now: `pt_BR` for PT, `en_US` for EN. |
| `og:title`, `og:description` | `inc/seo.php` | Per-language from translated post fields |
| `inLanguage` (schema) | `inc/seo.php` | `pt-BR` or `en-US` in JSON-LD |
| Localized breadcrumbs | `inc/seo.php` | "Início" → PT, "Home" → EN |
| Sitemap URLs | `inc/seo.php` (theme custom) | Only EN URLs resolving to real content (B2 not indexed) |
| Sitemap alternates | `inc/seo.php` | Per-URL `<xhtml:link>` entries |

### Why not let Polylang handle SEO?

`inc/seo.php` knows about `conexao_leisure_external_url()` (external redirect classification), `_event_status` (excluded when not visible), and the B5 per-type model. Polylang's generic SEO features would omit these. The custom layer must remain the owner.

### Production sitemap reconciliation

On production, Jetpack generates `/sitemap.xml`. The **theme custom sitemap is the canonical producer**. On production, Jetpack's sitemap must be reconciled (disabled or augmented to include EN alternates).

---

## 15. Sitemap ownership

**Single owner: the theme custom sitemap in `inc/seo.php`.** Jetpack's sitemap on production must be reconciled to avoid dual-producer drift.

| Content | In sitemap? | Language |
|---|---|---|
| PT homepage | Yes | pt_BR |
| EN homepage (`/en/`) | Yes | en |
| PT static pages | Yes (except utility/redirect pages) | pt_BR |
| EN static pages (translated) | Yes | en |
| PT guides | Yes | pt_BR |
| EN guides (translated) | Yes | en |
| B1-redirect EN URLs | **No** | — |
| B2 fallback EN URLs | **No** — canonical to PT | — |
| Events (visible) | Yes | Per record language |
| Events (expired/rejected) | **No** — excluded by `_event_status` | — |
| Source-EN events | Yes | en |
| Lazer (internal pages) | Yes | Per record language |
| Lazer (external-redirect) | **No** | — |
| Sponsors, Courses, Employment | Yes | Per record language |
| Taxonomy archives | Yes (non-empty terms) | pt_BR / en |

Each sitemap URL entry includes `<xhtml:link rel="alternate" hreflang="pt-BR" href="...">` and `<xhtml:link rel="alternate" hreflang="en" href="...">` where both-language versions exist. B2 fallback URLs get only the hreflang pair (not a `<url>` entry).

---

## 16. Redirect strategy

### Existing redirects (status: preserved)

All ~60+ EN→PT 301 redirects in `.htaccess` and `inc/seo.php` **remain unchanged** in this stage and throughout implementation. They serve legacy inbound link equity.

### `/en/` namespace (status: new, non-colliding)

No existing redirect pattern matches a path beginning with `/en/`. Polylang handles `/en/` routing internally.

### B1 redirect (new, for untranslated Guides/Blog)

New 302 redirect in `inc/seo.php`: when on an `/en/` URL for a Guide/Blog post type and no EN translation exists, redirect to the PT URL (302 temporary — EN page may be created later). Hook at `template_redirect` priority 6. Only fires for B1 content types (Guides, Blog, key static pages).

### Loop prevention

1. Existing EN→PT rules match only non-`/en/` paths — zero collision.
2. B1 302 redirects go to PT URLs only; PT URLs never redirect back to `/en/`.
3. Polylang `/en/` routing loads templates directly for valid translations — no redirect issued.
4. Sitemap indexes only URLs that resolve (no redirect chains indexed).

---

## 17. Search strategy

### Scoping

- **PT search:** Scoped to `pt-br` language posts (Polylang's `pre_get_posts` adds `lang=pt-br`).
- **EN search:** Scoped to `en` language posts — **extended** to include PT-language B2 records (Events, Lazer, Sponsors, Jobs) accessible under EN URLs via fallback.

### Search result rendering

| Search context | Results include | Rendering |
|---|---|---|
| PT search | All PT content | Standard PT results |
| EN search | EN content + B2-fallback PT records | EN content cards; B2 records show PT title/description with "Lido em português" badge linking to the `/en/` fallback URL |

### Implementation

- Polylang language scoping is the baseline.
- A `posts_where`/`posts_join` extension widens EN search to include B2-type PT records.
- `_event_status` gate preserved — expired/rejected events never appear in search in either language.
- Robots.txt: `/en/search/` and `/en/?s=` disallowed (mirroring `/search/` and `/?s=`).

---

## 18. Cache strategy

### Transient keys (in-theme)

| Existing transient | Current key | New key |
|---|---|---|
| Homepage | `conexao_home_*` | `conexao_home_pt` / `conexao_home_en` |
| 404 cache | `conexao_404_*` | `conexao_404_pt` / `conexao_404_en` |
| Event query | Date-keyed (`Conexao_Event_Query`) | Language-keyed: `_pt` / `_en` suffix |
| Homepage section caches | Shared | Per-language variants |

### Cache invalidation

- Save hooks (`save_post`, `clean_post_cache`) invalidate **both** language variants of affected transients.
- When an EN translation is created for a PT post, **both** PT and EN homepage/event-section transients are invalidated.
- `Conexao_Event_Status::mark_expired_events()` invalidates the event query transient — per-language.

### WordPress.com edge cache

- The `/en/` URL prefix is cache-key-differentiated at the edge (distinct URLs).
- `Vary: Accept-Language` is NOT needed — language is URL-determined (prefix), not header-determined.
- `wp_redirect` responses (B1 302) must not be cached by the edge (standard behavior for redirects).

### Polylang cache note

Polylang (Free) has no caching layer of its own — relies on WordPress object cache. Transient keying handled in theme/plugin code.

## 19. REST / Flutter strategy

### Current state

- Frontend does NOT consume REST JSON (`main.js` only fetches HTML for infinite scroll).
- All CPTs are `show_in_rest => true` (Gutenberg). Event meta is REST-visible.
- REST API is technically open (unauthenticated reads), but robots.txt disallows `/wp-json/`.
- Future Flutter app consumes `wp-json/wp/v2/…`.

### Polylang REST contract

| Parameter | Effect |
|---|---|
| `?lang=en` | Filters collection endpoints to English posts |
| `?lang=pt-br` | Filters to Portuguese posts |
| (no `lang`) | Default language (PT) |
| `_links.translations` | Each post object includes `translations: { "pt-br": <id>, "en": <id> }` when both exist |

### Composition with `_event_status` gate

- Event REST collections compose language filter with `_event_status` gate.
- The event-runtime gate returns early in `is_admin()` and `WP_CLI` — does NOT early-return for REST frontend reads.
- Language filter + status gate compose via SQL AND.

### Flutter contract (future)

Documented stable contract:

```
GET /wp-json/wp/v2/guide?lang=en
GET /wp-json/wp/v2/event?lang=en&status=published
GET /wp-json/wp/v2/leisure?lang=en
GET /wp-json/wp/v2/posts?lang=en
GET /wp-json/wp/v2/guides/{slug}?lang=en
```

- If `lang=en` returns no results for a slug, Flutter falls back to the PT record (matching B2) and renders a notice.
- B2-fallback records could be exposed with a `language_fallback` indicator in the REST response (Stage 4 addition).

---

## 20. Importer / runtime strategy

### Event Importer (local tooling, dormant on production)

- Runs in admin (default language = `pt_BR`). Forces `pt_BR` on all imported event posts.
- `_event_source`, `_event_source_id`, `_event_export_uuid`, `_event_url` written as language-neutral meta. Polylang does not alter them.
- Source-EN events: importer extended (Stage 3) to detect English source and assign `en`.
- Export JSON gains `"lang"` field (Stage 3, additive, backward-compatible).
- Must NOT create duplicate posts. Dedup by UUID → source+source_id → URL → title+date.

### Event Runtime (production-critical)

- `pre_get_posts` gate constrains queries to `_event_status = published OR no status`. Runs in front-end and REST. Returns early in `is_admin()` / `WP_CLI`.
- Polylang language filter composes AFTER status gate (both at priority 10).
- `Conexao_Event_Query::upcoming_event_ids()` must be language-aware (Stage 2).

### Lazer Migration (ZIP export/import)

- `_leisure_uuid` preserved. ZIP import forces language. UUID matching on re-import unaffected.
- Round-trip verification (Stage 3): UUID identity intact with EN variants present.

### Sponsor Migration (JSON export/import)

- Same model as Lazer. `_sponsor_*` meta not touched. JSON may gain language field (Stage 3).

### Automated identity tests (Stage 2)

- After EN variant created for an event, exactly one record per `_event_source` + `_event_source_id`.
- After EN variant created for Lazer, exactly one record per `_lazer_uuid`.
- `_event_status` gate excludes hidden events from EN archives.

## 21. WordPress.com hosting considerations

| Concern | Resolution |
|---|---|
| Plugin upload | WordPress.com Business plan supports custom plugin ZIP upload. Polylang installable via ZIP. ✓ |
| `.htaccess` | WordPress.com supports `.htaccess`. Existing redirects verified active via curl. No changes in Stages 0–2. |
| Edge cache | WordPress.com edge cache keys on URL path. `/en/` prefix = distinct cache keys. ✓ |
| Jetpack | Present (16.3-a.1). Owns `/sitemap.xml` on production. Must be reconciled with theme custom sitemap. |
| CDN | `i0.wp.com`/`c0.wp.com` CDN. Images shared across language variants (same Media Library attachment). ✓ |
| WP-Cron | WordPress.com wp-cron on production. Event expiry runs at end of import (not cron). No change. ✓ |
| SFTP/SSH | Not available on production. Migration via admin ZIP or REST scripts. ✓ |
| Plan feature validation | Verify Business plan supports Polylang before Stage 2 deployment. |

---

## 22. Rollback strategy

### Rollback path

1. Deactivate Polylang (Free or Pro) from the WordPress.com Plugins admin.
2. Optionally remove Polylang-generated data (language meta, translation links, term translation metadata, Polylang options).
3. Theme `inc/seo.php` reverts to single-language mode (`function_exists('pll_current_language')` guards make language calls no-ops when plugin inactive).

### What is preserved on rollback

| Artifact | Preserved? | Reason |
|---|---|---|
| All PT posts/pages | **Yes** | Polylang never deletes posts |
| CPT slugs (`/guias/`, etc.) | **Yes** | Never changed |
| Event identity (`_event_source`, etc.) | **Yes** | Language-neutral meta |
| Lazer UUID (`_lazer_uuid`) | **Yes** | Language-neutral meta |
| `.htaccess` redirects | **Yes** | Never touched |
| `inc/seo.php` canonical/hreflang | **Reverts to single-language** | No-ops when Polylang inactive |
| Homepage transients | **Flushed** — regenerated in PT | Per-language keys invalidated |
| Jetpack sitemap | **Unaffected** | Single-language sitemap |

### Rollback risk

- **Medium.** Polylang data in `wp_options` and `postmeta`/`termmeta`. Deactivation clears the active-language context. Full data removal requires backup restore.
- **Safe for identity layers.** Event/Lazer identity fields never altered by Polylang.
- **WordPress.com backups** available for full restore.

---

## 23. Effort matrix

| Area | English work | Complexity | Risk | Stage |
|---|---|---|---|---|
| UI strings (gettext) | POT + pt_BR/en catalogs | LOW | LOW | Stage 1 |
| Hardcoded PT gaps | Externalize + recurrence labels | LOW–MEDIUM | LOW | Stage 1 |
| Plugin gettext | `load_plugin_textdomain()` to all 7 | LOW | LOW | Stage 1 |
| Locale model | `pt_BR` locale; dynamic html lang/og:locale | MEDIUM | MEDIUM | Stage 1 |
| **Foundation** | | **LOW** | **LOW** | **Stage 1** |
| Polylang integration | Install + configure; force language on imports | MEDIUM | MEDIUM | Stage 2 |
| **Plugin integration** | | **MEDIUM** | **MEDIUM** | **Stage 2** |
| URL routing | `/en/` prefix; CPT slugs preserved | LOW | LOW | Stage 2 |
| SEO layer | Per-language canonical/hreflang/og:locale | MEDIUM | **HIGH** | Stage 2 |
| Redirect migration | Inventory; B1 302 logic added | MEDIUM | **HIGH** | Stage 2 |
| Taxonomy localization | Term name translations; slugs preserved | LOW | LOW | Stage 1–2 |
| Sitemap + alternates | EN URLs; Jetpack reconciliation | MEDIUM | MEDIUM | Stage 2 |
| Search scoping | Language-scoped; B2 fallback included | MEDIUM | LOW | Stage 2 |
| Cache keying | Per-language transients; invalidation | MEDIUM | MEDIUM | Stage 2 |
| Homepage | EN text; per-language transients | LOW | LOW | Stage 2 |
| Menus | Per-language menus | LOW | LOW | Stage 2 |
| **Events** | Source-inherited; identity preserved; gate tested | **HIGH** | **HIGH** | Stage 2–3 |
| **Lazer** | ZIP import language; UUID preserved; B2 fallback | MEDIUM | MEDIUM | Stage 3 |
| Guides | B1 redirect until translated | LOW | LOW | Stage 3 |
| Blog | B1 redirect until translated | LOW | LOW | Stage 3 |
| Employment | EN landing page; B2 job fallback | MEDIUM | LOW | Stage 3 |
| Courses | EN archive UI; B2 entries | MEDIUM | LOW | Stage 3 |
| Sponsors | B2 fallback; EN translations | MEDIUM | LOW | Stage 3 |
| Static pages | Translate key pages; B1 for rest | MEDIUM | LOW | Stage 3 |
| Importer | Force language; identity tests; JSON lang | MEDIUM | **HIGH** | Stage 2 |
| Admin/editorial | Translation UI; outdated flag | MEDIUM | MEDIUM | Stage 2–3 |
| REST contract | `?lang=en` documented for Flutter | LOW–MEDIUM | LOW | Stage 4 |
| Flutter/REST | Language-aware REST for app | LOW–MEDIUM | LOW | Stage 4 |

### One-time migration work

- Redirect inventory: map each EN→PT 301 to its inbound-link value (analytics/crawl data).
- Import script: force `pt_BR` language on all existing posts (WP-CLI).
- Export/import JSON schema: add optional `"lang"` field (additive, backward-compatible).
- Term translations: bulk-assign `pt-br` language to all existing terms.

### Ongoing editorial cost

- **Human content translation** of Guides, Blog, and key static pages — the dominant long-term cost (audit conclusion upheld).
- **Event content** largely source-inherited (EN events already exist; PT events get B2 fallback).
- **Directory content** (Lazer/Sponsors/Courses) — B2 fallback means no translation required for basic coverage.
- **Ongoing:** keeping EN translations current when PT is edited (W5 outdated-flag workflow).

---

## 24. Implementation stages

### Stage 0 — Decisions (this document)

- Confirm locale model: `pt_BR` default, `en` under `/en/`.
- Confirm missing-translation policy: B5 per content type.
- Confirm editorial model: PT first, EN later; outdated-flag workflow.
- Confirm Polylang as the mechanism and `inc/seo.php` as single SEO owner.

**Deliverable:** This document (APPROVED).

### Stage 1 — i18n foundations (zero URL/SEO impact)

1. Create `languages/` directory; generate POT from 446 gettext calls.
2. Build `pt_BR` + `en` `.mo` catalogs.
3. Externalize hardcoded-PT gaps (recurrence labels, JS strings via `wp_localize_script`).
4. Add `load_plugin_textdomain()` to all 7 plugins.
5. Fix locale: set site locale `pt_BR`; dynamic `html lang` + `og:locale`; date format localization.
6. Regression-test: dark mode, filters, infinite scroll, transients.

**Scope:** Zero URL changes. Zero redirect changes. Portuguese site untouched. Reversible by removing `languages/` dir and `load_plugin_textdomain()` calls.

**Test:** PT pages render identically; `html lang="pt-BR"` and `og:locale=pt_BR` correct.

### Stage 2 — Multilingual mechanism + URL/SEO (introduces `/en/`)

1. Install Polylang locally (staging on WordPress.com after).
2. Set default language `pt_BR`; verify PT URLs byte-identical.
3. CPT slugs preserved under `/en/` prefix.
4. Teach `inc/seo.php`: per-language canonicals, hreflang pairs (only where translations exist), dynamic og:locale, localized JSON-LD.
5. Redirect inventory: audit ~60+ EN→PT 301s; add B1 302 logic for untranslated Guides/Blog. **Do not modify existing 301s.**
6. Sitemap: EN URLs + alternates; reconcile theme custom sitemap with Jetpack on production.
7. Per-language menus/switcher; per-language homepage transient keys; search scoping; robots patterns.
8. Importer/runtime guards: force `pt_BR` on import; add automated identity tests.
9. Validate `_event_status` gate precedence with language filters active.

**Scope:** Introduces `/en/` as a new namespace. PT URLs untouched. Existing 301s untouched. Reversible by deactivating Polylang.

**Test:** `/guias/` serves PT; `/en/guias/` exists; `/guides/` still 301s to `/guias/`; hreflang correct; expired events hidden in EN.

### Stage 3 — Content

1. Translate shared taxonomy term names (categories; counties/towns mostly proper nouns).
2. Translate homepage/Customizer text, key static pages (Sobre Nós, Contato, privacidade, termos).
3. Guides first (B1), then directory meta (Lazer/Empregos/Cursos/Sponsors) via B2, Blog last/optional.
4. Events: per-record language markers; source-EN events inherit as `en`; translate only hand-curated posts.
5. Media alt text EN for hero/featured images (a11y).
6. Lazer/sponsor ZIP/JSON round-trip verification with EN variants present (UUID identity intact).

**Test:** EN content renders with EN chrome; hreflang updates; sitemap includes EN URLs; UUID identity preserved after Lazer round-trip.

### Stage 4 — App/extension

1. REST language contract (`?lang=en` + `_links.translations`) documented for the future Flutter app.
2. Analytics language dimension (if/when analytics added).
3. Ongoing editorial loop (W5 outdated-flag workflow).

---

## 25. Risks

| Risk | Severity | Mitigation |
|---|---|---|
| EN→PT 301 redirect tables collide with future `/en/` space and carry unknown inbound-link equity | **HIGH** | Full redirect inventory before Stage 2; `/en/` prefix functionally immune; existing 301s preserved in full |
| Event identity corruption if EN implemented as duplicate posts | **HIGH** | Hard rules §10 + automated identity tests; importer forces language; export JSON gains `lang` field |
| Custom SEO layer (`inc/seo.php`) vs plugin SEO — two "smart" layers both emitting canonical/hreflang | **HIGH** | Single-owner rule §14; Polylang configured to defer; `inc/seo.php` extended for language awareness |
| Dual sitemap producers (theme + Jetpack) drift | MEDIUM | Theme custom sitemap is owner; Jetpack reconciled on production |
| WordPress.com hosting constraints (plugin availability on plan, edge caching) | MEDIUM | Verify Business plan + Polylang (staging); URL-prefix cache keying is naturally safe |
| Locale oddity (`en_US` site locale + PT content) baked into dates/formats | MEDIUM | Stage 1 fix: set `pt_BR` locale; `en_US` switch on `/en/` via `locale` filter |
| Home/404/event transients serving the wrong language after cache warmth | MEDIUM | Per-language keys + save-hook invalidation tests |
| Mixed-language UX if missing-translation behavior stays implicit | MEDIUM | Explicit per-type B5 decision (§12); B2 shows "displayed in Portuguese" notice |
| Content volume (human translation of guides/static pages) is the true long pole | MEDIUM | Stage gating; prioritized content plan (key pages → guides → blog) |
| Gettext coverage ~85% but not 100% — gaps surface piecemeal | LOW | POT regeneration + audit scan repeat |
| `conexaobr.com` is an unrelated site — assumptions of redirect/relationship are false | INFO | Documented; excluded from hreflang/redirect plans |
| WordPress.com `.htaccess` limitations (managed hosting) | LOW | Supported (verified active via curl); no `.htaccess` changes planned in Stages 0–2 |
| Polylang `pre_get_posts` ordering with event `_event_status` gate | MEDIUM | Both at priority 10; status gate narrows post status, Polylang narrows language; tested Stage 2 |
| B2 fallback duplicate content | MEDIUM | `rel=canonical` → PT; hreflang pair; B2 URLs excluded from sitemap `<url>` entries |

### Unresolved (to be resolved in Stage 2 staging)

- WordPress.com Business plan Polylang compatibility verification.
- Jetpack sitemap disable vs augment on production.
- Exact set of EN 301s to keep vs retire (analytics-driven).
- Polylang Pro budget decision (Free vs Pro for editorial workflow).

---

## 26. Explicit non-goals

This Stage 0 decision does NOT:

- Install any plugin (Polylang installation is Stage 2).
- Activate multilingual functionality.
- Modify production.
- Modify database content.
- Create translations.
- Change URLs.
- Change redirects (`.htaccess` + `inc/seo.php` redirects are untouched).
- Modify WordPress settings.
- Modify theme/plugin source code.
- Use machine translation for content.
- Rename Portuguese CPT slugs.
- Create duplicate Event/Lazer records.
- Fragment taxonomy terms per language.
- Implement the Flutter REST contract (Stage 4).
- Touch `conexaobr.com` (unrelated site, excluded).

---

## 27. Final decision

| Item | Decision |
|---|---|
| A. Default language | **Portuguese (`pt_BR`)** — WP locale corrected; `en_US` active on `/en/` |
| B. English URL prefix | **`/en/`** — wraps all English URLs; PT URLs byte-identical |
| C. Multilingual mechanism | **Polylang** (Free; Pro recommended) — smallest production-safe option |
| D. Content relationship model | **Polylang translation linking** — one record + language variant, never duplicate identity |
| E. Taxonomy relationship model | **Shared terms** with per-language names; preserved PT slugs |
| F. Event identity model | **Single record per `_event_source` + `_event_source_id`**; status gate precedes language filter |
| G. Lazer identity model | **Single record per `_lazer_uuid`**; UUID never duplicated |
| H. Missing-translation policy | **B5: per-content-type hybrid** — B1 for Guides/Blog/static pages; B2 for directories; source-inherited for EN-source events |
| I. Search behavior | Polylang-scoped; B2 records included for directory types; status gate preserved |
| J. SEO ownership | **`inc/seo.php` (theme) — single owner**; Polylang defers |
| K. Sitemap ownership | **Theme custom sitemap**; Jetpack reconciled on production |
| L. Cache strategy | **Per-language transient keys**; URL-prefix for edge cache |
| M. Redirect strategy | **Legacy EN→PT 301s preserved**; new B1 302 logic only |
| N. Editorial workflow | **PT first, EN later**; outdated flag for PT updates |
| O. REST/Flutter strategy | **Polylang `?lang=en`**; `_links.translations`; status-gate composed |
| P. Rollback strategy | **Deactivate Polylang**; PT content/URLs/identity meta preserved |

---

## FINAL STATUS

**ENGLISH ARCHITECTURE — APPROVED**

All critical dependencies have an explicit strategy:

1. **Event identity** — single record per source identity preserved via Polylang translation linking; importer forces language; automated identity tests in Stage 2.
2. **Lazer identity** — `_lazer_uuid` never duplicated; ZIP import forces language; round-trip verification in Stage 3.
3. **Redirect collision** — `/en/` prefix structurally immune to existing EN→PT 301 patterns; existing redirects preserved; only new 302 logic for B1 types.
4. **SEO ownership** — single-owner (`inc/seo.php`); Polylang defers; no conflicting canonical/hreflang.
5. **WordPress.com** — Business plan supports Polylang via ZIP; staging validation planned.
6. **Taxonomy** — shared terms, per-language names, preserved slugs.
7. **Status gate** — `_event_status` precedes language filter; tested in Stage 2.

The architecture preserves the existing Portuguese website first. English is an additional language layer. Event and Lazer identity layers are non-negotiable: one underlying production record, language variants attached to that identity, never duplicate source identities.
