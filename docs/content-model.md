# Content Model

## Custom Post Types

Registered by `conexao-data-model` plugin (class `Conexao_Data_Model`, method `register_content_types()`).

| Post Type | Label | Archive Slug | Supports | Notes |
|-----------|-------|--------------|----------|-------|
| `guide` | Guias Práticos | `guias` | title, editor, excerpt, thumbnail, author, revisions, page-attributes, custom-fields | Practical guides |
| `event` | Eventos | `eventos` | Same as above | Imported + manual; visibility gated by `_event_status` |
| `job` | Empregos | *disabled* | Same as above | Job listings; **archive disabled** — `/empregos/` is a static page (Jobs Landing), singles stay at `/empregos/{slug}/` |
| `sponsor` | Apoiadores | `apoiadores` | Same as above | Business directory |
| `course_provider` | Cursos | `cursos` | title, editor, excerpt, thumbnail, revisions, custom-fields | Directory; links externally; no single-post pages |
| `leisure` | Lazer e Turismo | `lazer` | Same as guide (incl. author, page-attributes) | Tourism directory; local Media Library images |
| `recruitment_agency` | Agências de Recrutamento | *none* | title, editor, excerpt, revisions, custom-fields | Admin-only directory; no archive, no single URL (rendered inside /empregos/ landing via template-part). Managed under Empregos menu. |
| `permit_employer` | Empregadores — Employment Permits | *none* | Same as recruitment_agency | Admin-only directory of employers with verified historical Employment Permit evidence (official DETE statistics); rendered inside the /empregos/ landing, below the agencies. Employers are NOT recruitment agencies. Managed under Empregos menu. |

All CPTs are: `public`, `show_in_rest` (Gutenberg), rewrite with a Portuguese
slug, `with_front => false`, and `has_archive` — **except `job`**, whose archive
is disabled so that `/empregos/` can be a normal WordPress page (the "Jobs
Landing" hub, rendered by the theme's `page-empregos.php`). Individual job
posts keep their `/empregos/{slug}/` permalinks (the CPT `rewrite` slug stays
`empregos`).

**`recruitment_agency`** and **`permit_employer`** are further exceptions: they
are not public (`public` and `publicly_queryable` are false), have no rewrite,
no archive, and no single post URL. The CPTs exist purely as wp-admin data
containers for the curated directories rendered inside the /empregos/ landing
page ("Agências de recrutamento" and "Empresas com histórico de Employment
Permits"). In wp-admin both appear as a submenu item under the existing
Empregos menu. `permit_employer` records carry a `_employer_permit_status`
of `verified` (verified HISTORICAL permit evidence — never "currently
sponsoring"), `unverified` (plain entry, no indicator), or `exception`
(has the same historical evidence plus a company-stated current-position
caveat kept in the admin data; it renders as a normal permit-history
card — the unified directory's compact notice is the single Employment
Permit explanation). See
`docs/research/2026-09-empregos-agencies-and-employment-permits.md` for the
validated data and editorial rules.

## The Blog posts page (Stage O)

`/blog/` is not a CPT archive: it is the **static posts page** — the `page`
record whose ID is stored in the `page_for_posts` option. The Blog *archive* is
therefore a `page` record, and its English counterpart is a `page` record too.

| Field | PT | EN |
|---|---|---|
| post type | `page` | `page` |
| post_name | `blog` | `blog` (**shared slug on purpose**: `/blog/` ↔ `/en/blog/`) |
| Polylang language | `pt` | `en` |
| status | `publish` | `publish` |
| parent | 0 | 0 |
| `conexao_meta_description` | Portuguese | authored English |

- Identity: the **PT page slug `blog`** — never a local post ID.
- The EN record is a *linked translation*, never a fork: the pair is verified
  bidirectionally (`pll_get_post(pt,'en') === en` **and**
  `pll_get_post(en,'pt') === pt`).
- The body is a short authored-English archive introduction. The visible
  archive (heading, description, category filter) is template-driven by
  `home.php`, so no page body is fabricated for the layout.
- No taxonomy: the PT posts page carries zero terms in `conexao_category`,
  `conexao_county`, `conexao_tag` and `conexao_town`.
- **Blog `post` records are a different identity and stay B1.** The 42 EN
  `post` records are not allowlisted anywhere: `post` is not in
  `conexao_b2_post_types()` and there is no per-post allowlist key.
  `post_type:post:missing_en = 0` because every eligible public PT post is
  genuinely translated, not because anything is exempted.
- Authored by the `en-blog-page` stage of `conexao-en-translation`
  (`includes/blog-page-data.php`, version `blog-page-v1`), applied through the
  shared `conexao-translation-rollout` engine.

## Taxonomies

Registered by `conexao-data-model` (method `register_taxonomies()`).

| Taxonomy | Slug | Hierarchical | Applied To |
|----------|------|--------------|------------|
| `conexao_category` | `categories` | Yes | All 6 CPTs |
| `conexao_county` | `counties` | Yes | All 6 CPTs |
| `conexao_tag` | `tags` | No | All 6 CPTs |
| `conexao_town` | `towns` | Yes | Events only (registered by event-importer) |

### Default terms (seeded by `Conexao_Data_Model_Relationships::seed_terms()`)

**Categories**: Moradia, Empregos, Saúde, Família, Transporte, Finanças, Benefícios, Onde Comer, Educação, Documentos, Turismo, Negócios, Treinamento, Natureza, História, Cultura, Família, Praias, Caminhadas, Aventura, Jardins, Museus, Castelos, Vida Selvagem, Patrimônio, Cidades, Ilhas, Greenways, Outros

**Counties**: All 26 Republic of Ireland counties.

### Language neutrality of the proper-name taxonomies

`conexao_county` and `conexao_town` are **shared proper-name taxonomies**. A term is
language-neutral, which means:

- it carries **no** Polylang language assignment (`pll_get_term_language()` is `false`);
- it has **no** term translation and **no** per-language suffixed duplicate
  (`dublin`, never `dublin-en` / `dublin-pt`);
- the same physical term is used by records of either language, so `?county=` /
  `?cidade=` resolve identically in the PT and EN contexts.

This is a **data** invariant as well as a policy one. A freshly seeded term is
already language-neutral because the seed uses a bare `wp_insert_term()` on a
taxonomy Polylang does not translate. Terms created *before* the Stage 3.2
policy correction still carry a stale language assignment, which is residue of
the period when these taxonomies were translated; it is removed once, with

```bash
php scripts/remediate-shared-taxonomy-language.php --dry-run
php scripts/remediate-shared-taxonomy-language.php --apply
```

The script reads the shared/translated split from `PLL()->model->get_translated_taxonomies()`
at runtime rather than from a list of its own, refuses any term with a real
cross-language counterpart, and never creates, renames, re-slugs, merges or
deletes a term. The permanent `test-taxonomy-policy.php` gate enforces the
invariant from then on.

`conexao_category` and `conexao_tag` are Polylang-**translated**: one shared
concept identity per term-translation pair, linked in both directions.

**Leisure attributes** (`conexao_leisure_attribute`, leisure only — Phase 2
vocabulary): Famílias, Exterior, Interior, Interior + exterior, Gratuito,
Pet friendly, Acessível, Estacionamento, Necessita reserva, **Pago**,
**Gratuito em determinadas condições**, **Acesso de transporte público**,
**Bicicleta**. These are the canonical practical-characteristics concepts for
the /lazer/ directory — never create duplicate synonyms (Grátis, Free,
Transporte, etc.). Admission terms (Gratuito / Pago / Gratuito em
determinadas condições) are mutually exclusive and assigned only when the
existing data supports them; unknown admission information stays unassigned.
The Características filter reads this taxonomy dynamically, so new terms are
automatically filterable via `?atributo=`.

## Meta Fields

### Guide (`_conexao_featured`)

- Type: `boolean` (checkbox)
- Purpose: Mark a guide as featured. **Not currently consumed** — the homepage
  "Guia em Destaque" section was removed (guides are surfaced via Quick Access,
  the main navigation and "Mais Lidos"); the meta is kept for future
  featured-guide features.

### Event (registered by event-runtime)

- `_event_date` (date), `_event_time` (text), `_event_start_time`, `_event_end_date`, `_event_end_time`
- `_event_location`, `_event_venue`, `_event_address`
- `_event_map_url` (text, v1.1.0) — deterministic Google Maps search URL derived by the importer from the strongest available location data (address → venue + location → location). Never overwritten when non-empty; REST-visible for the mobile app
- `_event_url`, `_event_source_url`
- `_event_banner`, `_event_banner_attachment_id`
- `_event_registration`, `_event_cta`
- `_event_source`, `_event_source_id`, `_event_organizer`, `_event_price`
- `_event_import_date`, `_event_last_checked`
- `_event_status`, `_event_imported`

**Recurrence** (optional; events without these keys remain one-time):

| Key | Format | Notes |
|---|---|---|
| `_event_recurrence` | `''` (one-time) or `weekly` | Empty/unsupported value = legacy one-time behavior |
| `_event_recurrence_days` | CSV of ISO weekdays `1` (Mon) … `7` (Sun), e.g. `3`, `1,3` | Invalid/empty entries are discarded |
| `_event_recurrence_start` | `Y-m-d` | Optional; falls back to `_event_date` when blank. Invalid = series undefined |
| `_event_recurrence_end` | `Y-m-d` | Optional; blank or invalid = open-ended |

Visible in the admin editor (toggle + weekday picker + start/end dates). Evaluated
by `Conexao_Event_Recurrence` (event runtime plugin) on local calendar dates in
the WordPress timezone (`wp_timezone()`). No occurrence posts, no cron.

### Sponsor

- `_sponsor_link` (url), `_sponsor_display_order` (text) — `_sponsor_link` is the
  canonical/official website: archive cards, homepage carousel and SEO schema
  click through to it. `_sponsor_display_order` ("Ordem de exibição") is the
  editor-curated order used by the homepage Hero carousel AND the `/apoiadores/`
  archive: ascending first, then supporters without an order value newest
  published first (see `conexao_sponsor_archive_ordered_ids()` in the theme).
  Numeric `0` is a valid order value, never treated as empty.
- `_sponsor_contacts` (array) — ordered contact/social links repeater, one
  meta holding `[ { type, url }, … ]` rows; types: website, instagram,
  facebook, whatsapp, linkedin, tiktok, email (stored as `mailto:`), outro.
  Managed by `Conexao_Data_Model_Contacts`; row order is meaningful
- `_sponsor_featured` (checkbox)
- `_sponsor_status`, `_sponsor_category`, `_sponsor_type`, `_sponsor_description`
- Canonical carousel/detail image (Media Library attachment ID):
  - `_sponsor_image` — single portrait "Imagem do Apoiador" used at every
    breakpoint (desktop Hero carousel, mobile Hero carousel, detail page).
    Recommended ~3:4 or 4:5 (e.g. 800×1000 / 900×1200).
- Legacy (kept as read-only fallbacks — never rendered in the editor):
  `_sponsor_mobile_image` → `_sponsor_desktop_image` → `_sponsor_logo` are
  consulted in that order when `_sponsor_image` is empty, so pre-consolidation
  records keep their artwork; the next admin save persists the resolved value
  into `_sponsor_image`. Attachments referenced by the legacy keys are never
  deleted.

### Job

- `_job_company` (text), `_job_location`, `_job_salary`, `_job_employment_type`, `_job_expiration_date` (date)
- `_job_status`, `_job_application_url`, `_job_source`
- `_job_requirements`, `_job_description`

### Empresas Landing page (page post type)

The `/empregos/` landing page uses the standard Page fields (title, featured
image, body) plus one minimal field:

- `_empregos_link` (url, page) — optional destination of the
  "Ver vagas no Instagram" CTA. When empty, the CTA is not rendered. Managed in
  the "Jobs — Link" metabox on the Page editor.

### Course Provider

- `_provider_logo` (integer — attachment ID)
- `_provider_category` (string), `_provider_location`, `_provider_url`, `_provider_status`, `_provider_order` (integer)

### Recruitment Agency

- `_agency_website` (string — http(s) URL), `_agency_phone` (string), `_agency_location` (string)
- `_agency_job_types` (string — comma-separated canonical keys from `Conexao_Data_Model_Agency::job_types()`: warehouse, general_operative, factory_production, logistics, hospitality, cleaning, retail, construction_labour, driving_delivery, office_admin, agriculture_seasonal; legacy free-text values are passed through as-is by `job_type_labels()`)
- `_agency_temporary` (boolean), `_agency_permanent` (boolean)
- `_agency_order` (integer — missing values still render, sorted last), `_agency_last_checked` (string — date, Y-m-d)
- `_agency_wrc_licence` (string)
- `_agency_status` (string — custom publishing status; only `published` renders on /empregos/)
- `_agency_notes` (string — internal maintenance notes; REST-hidden, never rendered on the site)

**Directory filters (/empregos/).** The unified opportunities directory rendered by
`template-parts/employment-opportunities.php` is filterable via `?tipo=` / `?area=` /
`?localizacao=` / `?contrato=` (theme: `inc/employment-opportunities.php`,
`conexao_employment_opportunities_filter_state()`):

- `tipo` selects the resource type: `agency`, `public_sector` or `permit_history`.
  `permit_history` matches only records with the structured `has_permit_history` flag
  (from `_employer_permit_status`) — historical evidence only, never a sponsorship claim.
- `area` reuses the canonical `_agency_job_types` keys as filter slugs — no duplicate registry.
- `contrato` maps to the `_agency_temporary` / `_agency_permanent` flags (an agency flagged
  for both matches either value).
- `localizacao` normalizes the human-readable `_agency_location` string ("Nacional",
  "Dublin, Limerick", "Nacional (Dublin)", "Deansgrange, Co. Dublin; Dundalk, Co. Louth")
  into canonical location slugs via the registry in
  `conexao_recruitment_agency_locations()`. `_agency_location` remains the single source of
  truth — when adding an agency with a new location, add the location (slug, label, name
  aliases) to that registry so it becomes filterable; unknown segments are ignored and never
  guessed. "Nacional" coverage matches every specific location filter.
- Area/localização/contrato apply only where structured data exists (the agencies);
  non-agency resources never match those dimensions and none of their attributes are invented.

### Leisure (comprehensive image metadata)

Location fields:
- `_leisure_county`, `_leisure_town`, `_leisure_address`
- `_leisure_official_website`, `_leisure_discover_ireland`, `_leisure_website` (legacy)
- `_leisure_map_url`
- `_leisure_internal_page` (boolean, Phase 3B) — explicit "keep internal page"
  classification. When set, the record stays on its internal
  `/lazer/{slug}/` page even with an Official Website or Discover Ireland URL
  configured; those URLs are then displayed as labelled reference links
  ("Site oficial" / "Ver no Discover Ireland") instead of triggering the
  external 302 redirect. The flag is the only redirect/display decoupling
  signal — the mere presence of a display link never redirects, and every
  record without the flag classifies exactly as before.
- `_leisure_free`, `_leisure_duration`, `_leisure_best_time`
- `_leisure_family`, `_leisure_accessibility`, `_leisure_pet_friendly`, `_leisure_indoor`, `_leisure_outdoor`, `_leisure_parking`, `_leisure_booking`
- `_leisure_feature` (boolean — featured destination)

Map URLs (Phase 3B): the single page's "Ver localização no mapa" link is
resolved at render time by `conexao_leisure_map_url()` (theme functions.php):
1. `_leisure_map_url` when set (existing canonical data is never replaced);
2. otherwise a deterministic Google Maps search URL derived from the verified
   address (+ town/county) when `_leisure_address` exists;
3. otherwise a deterministic Google Maps search URL derived from
   title + town + county — only when at least one location signal exists
   beyond the title. Uses the official `?api=1&query=` URL scheme: no API
   key, no geocoding, no remote requests, no embed; identical data always
   yields the identical URL.

Practical-information fields (Phase 2 — all optional, free-text):
- `_leisure_practical_notes` — "Observações práticas": concise visitor tips
  (e.g. "Leve agasalho", "Fecha no inverno"). Never bulk-generated; left
  empty when no reliable practical information exists. Rendered only on the
  individual leisure page, never on archive cards.
- `_leisure_practical_source_url` — source used to verify the practical
  notes (official site / Discover Ireland page). Admin + export/import field.
  Phase 3B: on internal pages it is additionally surfaced as a labelled
  display-only authoritative link ("Mais informações") via
  `conexao_leisure_authoritative_links()` (theme functions.php) so the
  stale-verification message stays truthful — it is never printed as raw
  text and never influences the external-redirect classification.
- `_leisure_practical_last_checked` — date the practical information was
  last verified (site date format). If the notes are older than 6 months,
  the single page shows a verify note instead of "Informação verificada em
  [date]". Phase 3B — the note is truthful: it links to the site oficial /
  Discover Ireland / source only when a real, displayable authoritative link
  exists (`conexao_leisure_authoritative_links()`); with no link available it
  shows a neutral "Informações podem estar desatualizadas..." note instead of
  implying an unavailable link. Verification metadata applies to the
  free-text practical notes only, never to stable taxonomy attributes.

Opening hours are intentionally NOT modelled (high-maintenance without a
verification workflow; the official website CTA remains the authoritative
source for current hours).

Related events + SEO (Phase 3C): on INTERNAL single pages only, county is the
single reliable relationship used to surface a \"Próximos eventos\" section —
the destination's `conexao_county` term intersects the SHARED cached
upcoming-event list (`Conexao_Event_Query::upcoming_events()` via
`conexao_leisure_related_events()`, theme functions.php). Never category /
keywords / title similarity / free text / geographic distance; no second event
query system; no per-page transient. Internal singles also emit
`TouristAttraction` JSON-LD (inc/seo/schema.php) with only actually-stored
name/description/image/place. `_leisure_duration` and `_leisure_best_time`
remain editorial fields: they render on the single page ONLY when actual data
exists (never empty labels).

Image fields:
- `_leisure_image_attachment_id` (integer — local Media Library)
- `_leisure_image_source`, `_leisure_image_source_url`, `_leisure_image_author`, `_leisure_image_license`, `_leisure_image_attribution`, `_leisure_image_alt_text`
- `_leisure_image_status` (none|pending|local)

## Image Model

### Lazer images
- **Always local**: Production images are WordPress Media Library attachments.
- `_leisure_image_attachment_id` holds the attachment ID.
- Featured image (post thumbnail) is set to this attachment by the Admin UX editor.
- Wikimedia Commons metadata (`_leisure_image_author`, `_license`, `_attribution`) stored as reference/attribution only.
- Image status tracks workflow: `none` → `pending` → `local`.

### Event images
- External URLs stored in `_event_banner`.
- `_event_banner_attachment_id` stores the local attachment ID after sideloading.
- `Conexao_Event_Image_Handler` downloads external images into the Media Library.
- Admin UX provides an Image Sync page for bulk sideloading.

## Import/Export Identifiers

### Lazer (stable UUID)
- Meta key: `_lazer_uuid`
- UUID is generated on export and used for deduplication on import.
- Matching order: UUID → slug → title.
- Never uses WordPress post IDs as portable identifiers.

### Events (source + source_id + export UUID)
- `_event_source` (e.g. `laois_tourism`) + `_event_source_id` (external ID) form the primary deduplication key.
- Export adds a UUID meta for cross-instance matching.
- Matching order: UUID → source+source_id → URL → content title+date.
_Last verified: 2026-09-26 by Stage O — Enable the Real English Blog Archive_
