# Content Model

## Custom Post Types

Registered by `conexao-data-model` plugin (class `Conexao_Data_Model`, method `register_content_types()`).

| Post Type | Label | Archive Slug | Supports | Notes |
|-----------|-------|--------------|----------|-------|
| `guide` | Guias Práticos | `guias` | title, editor, excerpt, thumbnail, author, revisions, page-attributes, custom-fields | Practical guides |
| `event` | Eventos | `eventos` | Same as above | Imported + manual; visibility gated by `_event_status` |
| `job` | Empregos | `empregos` | Same as above | Job listings |
| `sponsor` | Apoiadores | `apoiadores` | Same as above | Business directory |
| `course_provider` | Cursos | `cursos` | title, editor, excerpt, thumbnail, revisions, custom-fields | Directory; links externally; no single-post pages |
| `leisure` | Lazer e Turismo | `lazer` | Same as guide (incl. author, page-attributes) | Tourism directory; local Media Library images |

All CPTs are: `public`, `show_in_rest` (Gutenberg), `has_archive`, rewrite with Portuguese slug, `with_front => false`.

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

- `_sponsor_link` (url), `_sponsor_display_order` (text)
- `_sponsor_featured` (checkbox)
- `_sponsor_status`, `_sponsor_category`, `_sponsor_type`, `_sponsor_description`
- Responsive carousel images (Media Library attachment IDs):
  - `_sponsor_desktop_image` — landscape artwork for the homepage carousel at ≥769px (~16:9 recommended)
  - `_sponsor_mobile_image` — portrait artwork at ≤768px (~3:4/4:5 recommended); falls back to the desktop image
- Legacy: `_sponsor_logo` (pre-two-field single image; kept as a fallback —
  the admin editor migrates it into `_sponsor_desktop_image` on first save)

### Job

- `_job_company` (text), `_job_location`, `_job_salary`, `_job_employment_type`, `_job_expiration_date` (date)
- `_job_status`, `_job_application_url`, `_job_source`
- `_job_requirements`, `_job_description`

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