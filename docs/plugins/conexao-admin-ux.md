# Conexão Admin UX

- **Path**: `wp-content/plugins/conexao-admin-ux/`
- **Version**: 1.0.1
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

Event-specific: `source_not_found`, `expired`, `rejected`. Events have no
review state — imported events are published immediately.

## First-Save Behavior (empty-content guard bypass)

The sectioned editor stores the real title/description in `conexao_fields[...]`
and renders hidden mirror inputs (`post_title`, `content`) that carry the
post's current values. On a brand-new post (auto-draft) those mirrors are empty,
so a first submission used to hit core's `wp_insert_post()` "empty content"
guard (all managed types support title + editor + excerpt). Core aborted the
update before any hook fired, so `save_post_{type}` never ran and every field
except `_edit_last` was silently lost while the UI reported success — the
"first save loses everything, second save works" bug.

`Conexao_Admin_Ux::bypass_empty_content_guard_for_editor()` (filter
`wp_insert_post_empty_content`) returns `false` only when an editor form is
being submitted: valid `conexao_admin_ux_nonce`, `action=editpost`, managed
post type, existing post ID, and never during autosaves. This makes the first
save behave exactly like an update — one request persists title, content,
excerpt, all meta, taxonomies and the featured-image sync. All other write
paths (autosave, Quick Edit, bulk edit, REST, importers) keep core's default
guard behavior.

## Media Fields & Featured Image Sync

`media`-type fields (e.g. the Apoiador `_sponsor_image`) store a **Media
Library attachment ID** — never a URL as the source of truth, and never
local-only IDs as portable identifiers. The relationship is:
post → attachment ID → WordPress featured image.

### Apoiador canonical image (Imagem do Apoiador)

Each Apoiador carries ONE Media Library relationship for the homepage carousel
and detail page — a single portrait artwork used identically on desktop and
mobile:

- **Imagem do Apoiador** (`_sponsor_image`) — portrait artwork. Recommended
  ~3:4 or 4:5 (e.g. 800×1000 / 900×1200). No separate desktop/mobile fields;
  there is no responsive source switching between different sponsor assets.

Legacy records created before the single-image consolidation keep working
without any migration step:

- The editor's Imagem do Apoiador field falls back to the pre-consolidation
  metas when `_sponsor_image` is empty (`Conexao_Admin_Ux_Fields::get_value()`):
  `_sponsor_mobile_image` → `_sponsor_desktop_image` → `_sponsor_logo`, so
  editing an existing Apoiador shows its current artwork pre-filled; the first
  save persists the resolved value into `_sponsor_image`.
- Explicitly clearing the image field also clears all three legacy image
  relationships so removed artwork cannot resurrect through the front-end
  fallback chain. Attachments themselves are never deleted.

- The editor's save handler (`Conexao_Admin_Ux_Editor::save()`) syncs each
  type's media field to the core featured image via `set_post_thumbnail()` /
  `delete_post_thumbnail()` so public templates using
  `has_post_thumbnail()` / `the_post_thumbnail()` display it.
- For sponsors, the sync resolves the effective artwork with the same chain
  the front end uses (Desktop → Mobile → remaining legacy logo) and only
  clears the featured image when the record previously had an admin-managed
  image relationship — sponsors whose artwork lived solely in the featured
  image are left untouched.
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

## Sponsor Contacts Repeater ("Contatos")

The Apoiador editor's **Contatos** section holds:

1. **Site oficial (link principal)** — the canonical `_sponsor_link` meta,
   unchanged in behavior: archive cards, the homepage carousel and the SEO
   schema click through to it.
2. **Outros contatos** — a structured repeater (`contacts` field type) for
   any number of additional contact/social links.

Repeater behavior:

- Each row is `[ Tipo ▼ ] [ URL ] [ ↑ ↓ Remover ]`; `+ Adicionar contato`
  clones an inline `<script type="text/html">` template via admin.js.
- Rows are rendered server-side from stored meta; JS only adds/removes/
  reorders, so the first save of a brand-new Apoiador persists every row
  added before submission (no client-side hydration involved).
- The save handler iterates rows in submission order and never trusts row
  indexes, so removing/reordering rows needs no renumbering.
- Types: Website, Instagram, Facebook, WhatsApp, LinkedIn, TikTok, E-mail,
  Outro (`Conexao_Data_Model_Contacts::types()`).
- Inputs are plain text (no native `type=url`/`email` constraint validation)
  so mid-edit drafts never get blocked by browser popups; per-type rules are
  enforced server-side with specific pt-BR error messages surfaced through
  the standard validation notice.
- Storage is a single array meta `_sponsor_contacts` owned by
  `Conexao_Data_Model_Contacts` (see the data-model plugin docs). Empty
  repeaters delete the meta entirely.

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