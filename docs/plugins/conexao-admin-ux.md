# Conexão Admin UX

- **Path**: `wp-content/plugins/conexao-admin-ux/`
- **Version**: 1.0.0
- **Purpose**: Professional, reusable CMS admin experience for all custom content types. Replaces generic meta boxes with structured sections, clear statuses, bulk actions, duplicate/archive workflows, dashboard summaries, and leisure image management.

## Responsibilities

- Provide structured editor UI (sections, fields) for event/guide/job/sponsor/course_provider/leisure
- Manage custom publishing statuses per content type (draft, needs_review, published, archived, etc.)
- Provide custom admin list columns, filters, bulk actions, and row actions
- Render dashboard summary cards
- Integrate Wikimedia Commons image search for leisure images
- Register admin pages for leisure image management
- Manage legacy meta synchronization for events

## Supported Content Types

`Conexao_Admin_Ux_Config::SUPPORTED_TYPES`: `event`, `guide`, `job`, `sponsor`, `course_provider`, `leisure`

## Key Components

| File | Class | Purpose |
|------|-------|---------|
| `conexao-admin-ux.php` | `Conexao_Admin_Ux` | Main plugin, boot components, label filters |
| `includes/class-config.php` | `Conexao_Admin_Ux_Config` | Per-type configuration (sections, fields, statuses, columns, bulk actions) |
| `includes/class-fields.php` | `Conexao_Admin_Ux_Fields` | Field rendering, saving, validation, virtual field handling |
| `includes/class-editor.php` | `Conexao_Admin_Ux_Editor` | Custom meta box editor, publish box, save handler |
| `includes/class-list.php` | `Conexao_Admin_Ux_List` | Custom list columns, filters, bulk actions, row actions, summary cards |
| `includes/class-actions.php` | `Conexao_Admin_Ux_Actions` | Duplicate, archive, delete, bulk status/taxonomy operations |
| `includes/class-leisure-image-admin.php` | `Conexao_Leisure_Image_Admin` | Leisure image management page, Wikimedia search/import |
| `includes/class-wikimedia-client.php` | `Conexao_Wikimedia_Client` | API client for Wikimedia Commons search and image import |

## Publishing Statuses

Each content type defines its own statuses in the config. Common statuses:

| Status | Badge | Description |
|--------|-------|-------------|
| `draft` | draft | Rascunho |
| `needs_review` | review | Revisão |
| `published` | published | Publicado |
| `archived` | archived | Arquivado |

Event-specific: `source_not_found`, `expired`, `rejected`.

## Media Fields & Featured Image Sync

`media`-type fields (e.g. the Apoiador `_sponsor_logo`) store a **Media Library
attachment ID** — never a URL, and never local-only IDs as portable identifiers.
The relationship is: post → attachment ID → WordPress featured image.

- The editor's save handler (`Conexao_Admin_Ux_Editor::save()`) syncs each
  type's media field to the core featured image via `set_post_thumbnail()` /
  `delete_post_thumbnail()` so public templates using
  `has_post_thumbnail()` / `the_post_thumbnail()` display it.
- Sync only runs when the editor form submitted the field; autosaves,
  Quick Edit, bulk actions and importers never touch thumbnails through it.
- `Conexao_Admin_Ux_Fields::normalize_media_value()` keeps values as numeric
  attachment IDs and resolves legacy URL values back to their attachment via
  `attachment_url_to_postid()`.
- The rendered hidden input always carries the stored value verbatim; preview
  resolution failures must never empty it (that previously caused saves to
  wipe the saved image).
- Removing an image clears the relationship only — the Media Library
  attachment itself is never deleted.

## Admin Pages

- **Leisure images**: Tools → Gerenciador de Imagens (Lazer) — Wikimedia search and import
- Leisure image management is also accessible via AJAX: `conexao_leisure_wiki_search`, `conexao_leisure_wiki_import`

## Hooks

### AJAX
- `wp_ajax_conexao_leisure_wiki_search` — Wikimedia image search
- `wp_ajax_conexao_leisure_wiki_import` — Import selected Wikimedia image

### Actions
- `admin_menu` — register submenu pages
- `add_meta_boxes` — register custom editor meta boxes
- `save_post_{type}` — custom save handler per post type
- `manage_{type}_posts_columns` / `custom_column` — list columns
- `restrict_manage_posts` / `pre_get_posts` — admin list filters
- `post_row_actions` — custom row actions
- `bulk_actions-edit-{type}` / `handle_bulk_actions` — bulk actions

## Dependencies

- `conexao-data-model` (depends on its CPTs existing)

## Files to Inspect First

- `conexao-admin-ux.php` — main plugin, component boot
- `includes/class-config.php` — per-type configuration (the best overview of what each type does)
- `includes/class-editor.php` — editor UI save logic
- `includes/class-wikimedia-client.php` — Wikimedia API client