# Conexão Leisure Migration

- **Path**: `wp-content/plugins/conexao-leisure-migration/`
- **Version**: 2.1.0
- **Purpose**: Export and import the /lazer/ (leisure) dataset as a self-contained ZIP package containing data.json and actual image files from the Media Library. Production images are always local — no dependency on Wikimedia Commons for delivery.

## Responsibilities

- Export all leisure CPT posts + taxonomies + images into a portable ZIP
- Import ZIP into another WordPress installation (with dry-run preview)
- Deduplicate by stable UUID, then slug, then title
- Import image files as local Media Library attachments
- Preserve Wikimedia attribution/license metadata as reference
- Clean up legacy external-image data from the old architecture
- Self-register the leisure CPT + taxonomies when conexao-data-model is absent (fallback)

## Key Components

| File | Class | Purpose |
|------|-------|---------|
| `conexao-leisure-migration.php` | `Conexao_Lazer_Migration` | Main plugin, self-registration fallback |
| `includes/class-leisure-exporter.php` | `Conexao_Lazer_Exporter` | Build ZIP with data.json + image files |
| `includes/class-leisure-importer.php` | `Conexao_Lazer_Importer` | Import ZIP with dry-run, dedupe, media handling |
| `includes/class-leisure-maintenance.php` | `Conexao_Lazer_Maintenance` | Legacy external-image data cleanup |
| `includes/class-leisure-transfer-admin.php` | `Conexao_Lazer_Transfer_Admin` | Admin pages: Export, Import, Manutenção |

## Hooks

### Actions
- `init` at priority 20 — self-register leisure CPT/taxonomies if missing
- `plugins_loaded` — boot admin UI
- `admin_menu` — register submenu pages

### Admin Pages

Under Lazer menu:
- **Exportar Lazer** — download ZIP with all leisure data + images
- **Importar Lazer** — upload ZIP with dry-run preview + confirm
- **Manutenção** — cleanup legacy external-image data

## Data Flow

### Export
1. Query all leisure posts
2. Collect all taxonomy terms (incl. `conexao_leisure_attribute` — Características)
3. Collect image files from Media Library (by `_leisure_image_attachment_id`)
4. Generate stable UUID per item/image
5. Package into ZIP: `data.json` + `images/` directory

Exported/imported meta includes the Phase 2 practical-information keys
(`_leisure_practical_notes`, `_leisure_practical_source_url`,
`_leisure_practical_last_checked`). The importer sanitizes them explicitly:
`sanitize_textarea_field` for the notes, `esc_url_raw` for the source URL.

The Phase 3B `_leisure_internal_page` flag (keep the internal `/lazer/` page
even when an Official Website / Discover Ireland URL is set) is also part of
the supported meta: the exporter emits `'1'` when the flag is set (empty or
absent values are simply not exported), and the importer normalizes any
truthy representation (`1`, `true`, `yes`, `on`, non-zero number) to `'1'`
via the same `normalize_boolean_meta()` used for the other leisure checkbox
fields — arbitrary values are never stored, and `0`/false values normalize
to "no flag". Absent/false therefore round-trips as "no flag", which
preserves the external-URL 302 redirect classification exactly as before
(see `conexao_leisure_external_url()` in `inc/seo.php` — unchanged by this
plugin).

### Import
1. Validate ZIP structure
2. Dry run: count found/created/updated/skipped/failed
3. Real import: create/update posts, import images, assign taxonomies
4. Matching order: UUID → slug → title
5. Images matched by stable image ID → reused (no duplicates)

#### Upload size limits

The **Importar Lazer** screen displays the effective maximum upload size
(`min(upload_max_filesize, post_max_size)`), validates the selected file
client-side before submitting, and redirects back with a clear error notice
when PHP rejects an oversized POST (`post_max_size`) or the file exceeds
`upload_max_filesize`. Locally, `docker/php/uploads.ini` raises both limits to
300M (see `docs/development.md`).

## Data

The importer only touches:
- Leisure CPT posts
- Leisure taxonomies (conexao_category, conexao_county, conexao_tag — matched by name)
- Media Library attachments created for leisure images

It never touches: events, guides, jobs, courses, blog posts, pages, supporters, or unrelated media.

## Self-Registration Fallback

When `conexao-data-model` is not active, this plugin registers the `leisure` CPT and the shared taxonomies on `init` (priority 20) so the importer can write into a consistent schema. When data-model is present, this fallback is skipped.

## Dependencies

- `conexao-data-model` (recommended, but optional — fallback self-registers the types)

## Important Rules

- Uses stable UUIDs for deduplication — never WordPress post IDs.
- Images are always imported as local Media Library attachments.
- Wikimedia Commons metadata is preserved as reference only, never hotlinked.
- Legacy external-image data can be cleaned up via the Manutenção page.
- **Multilingual (Stage 2):** `_leisure_export_uuid` is language-neutral. A linked
  English translation shares the UUID verbatim; `find_by_uuid()` prefers the
  record in the import language and `update_item()` refuses to write to a
  translation (`WP_Error`), so an EN variant can never become a competing UUID
  target or duplicate the identity. `includes/class-language-guard.php` assigns
  the default language (`pt_BR`) to imported Lazer records without ever
  reassigning an existing language. Gate coverage:
  `tests/test-language-uuid.php`.

### Legacy attribute backfill (`scripts/backfill-leisure-attributes.php`)

Companion WP-CLI script that maps legacy `_leisure_*` checkbox meta onto the
`conexao_leisure_attribute` taxonomy. Idempotent, safe to rerun, and **never
deletes legacy meta** (transition-safe fallback). Run:

```
wp eval-file scripts/backfill-leisure-attributes.php --allow-root          # apply
wp eval-file scripts/backfill-leisure-attributes.php dry-run --allow-root  # report only
```

The `_leisure_free` meta is classified into a five-way report — Claramente
Gratuito / Claramente Pago / Claramente condicional / Ambíguo (manual
review) / Vazio-desconhecido. Only unambiguous values migrate automatically
('1', "Gratuito", "Free" → Gratuito; "Pago"/"Paid" → Pago; explicit
conditional free-period text → Gratuito em determinadas condições).
Ambiguous text is listed for manual review and never converted; admission
terms are mutually exclusive per record.

## Files to Inspect First

- `conexao-leisure-migration.php` — main plugin, fallback registration
- `includes/class-leisure-exporter.php` — export logic
- `includes/class-leisure-importer.php` — import logic with dedupe + media
- `includes/class-leisure-transfer-admin.php` — admin UI