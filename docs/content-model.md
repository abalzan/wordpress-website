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

All CPTs are: `public`, `show_in_rest` (Gutenberg), rewrite with a Portuguese
slug, `with_front => false`, and `has_archive` — **except `job`**, whose archive
is disabled so that `/empregos/` can be a normal WordPress page (the "Jobs
Landing" hub, rendered by the theme's `page-empregos.php`). Individual job
posts keep their `/empregos/{slug}/` permalinks (the CPT `rewrite` slug stays
`empregos`).

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

## Meta Fields

### Guide (`_conexao_featured`)

- Type: `boolean` (checkbox)
- Purpose: Mark as featured on homepage

### Event (registered by event-importer)

- `_event_date` (date), `_event_time` (text), `_event_start_time`, `_event_end_date`, `_event_end_time`
- `_event_location`, `_event_venue`, `_event_address`
- `_event_url`, `_event_source_url`
- `_event_banner`, `_event_banner_attachment_id`
- `_event_registration`, `_event_cta`
- `_event_source`, `_event_source_id`, `_event_organizer`, `_event_price`
- `_event_import_date`, `_event_last_checked`
- `_event_status`, `_event_imported`

### Sponsor

- `_sponsor_link` (url), `_sponsor_display_order` (text) — `_sponsor_link` is the
  canonical/official website: archive cards, homepage carousel and SEO schema
  click through to it
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

- `_empregos_link` (url, page) — optional destination of the "Mais informações"
  CTA. When empty, the CTA is not rendered. Managed in the "Jobs — Link"
  metabox on the Page editor.

### Course Provider

- `_provider_logo` (integer — attachment ID)
- `_provider_category` (string), `_provider_location`, `_provider_url`, `_provider_status`, `_provider_order` (integer)

### Leisure (comprehensive image metadata)

Location fields:
- `_leisure_county`, `_leisure_town`, `_leisure_address`
- `_leisure_official_website`, `_leisure_discover_ireland`, `_leisure_website` (legacy)
- `_leisure_map_url`
- `_leisure_free`, `_leisure_duration`, `_leisure_best_time`
- `_leisure_family`, `_leisure_accessibility`, `_leisure_pet_friendly`, `_leisure_indoor`, `_leisure_outdoor`, `_leisure_parking`, `_leisure_booking`
- `_leisure_feature` (boolean — featured destination)

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