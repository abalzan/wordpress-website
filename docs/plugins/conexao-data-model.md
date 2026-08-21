# Conexão Data Model

- **Path**: `wp-content/plugins/conexao-data-model/`
- **Version**: 1.3.0
- **Purpose**: Registers custom post types, shared taxonomies, and editorial meta fields.

## Responsibilities

- Register 6 CPTs: `guide`, `event`, `job`, `sponsor`, `course_provider`, `leisure`
- Register 3 shared taxonomies: `conexao_category`, `conexao_county`, `conexao_tag`
- Register editorial meta fields for guides, events, sponsors, jobs
- Seed default category/county taxonomy terms on activation

## Key Components

| File | Purpose |
|------|---------|
| `conexao-data-model.php` | Main plugin file, class `Conexao_Data_Model` |
| `includes/class-meta.php` | Editorial meta boxes (legacy, replaced by admin-ux for supported types) |
| `includes/class-relationships.php` | Taxonomies and default term seeding |

## Data Model

### Custom Post Types

All registered in `Conexao_Data_Model::register_content_types()`. Portuguese slugs are canonical. All are public, show in REST, have archives.

See `docs/content-model.md` for complete details.

### Taxonomies

| Taxonomy | Slug | Apps to | Hierarchical |
|----------|------|---------|--------------|
| `conexao_category` | `categories` | All 6 CPTs | Yes |
| `conexao_county` | `counties` | All 6 CPTs | Yes |
| `conexao_tag` | `tags` | All 6 CPTs | No |

### Meta Fields (editorial)

| Post Type | Meta Keys |
|-----------|-----------|
| `guide` | `_conexao_featured` |
| `event` | `_event_date`, `_event_time`, `_event_location` |
| `sponsor` | `_sponsor_link`, `_sponsor_display_order` |
| `job` | `_job_company`, `_job_location`, `_job_salary`, `_job_employment_type`, `_job_expiration_date` |

Leisure meta (`_leisure_*`) and course provider meta (`_provider_*`) are registered in this plugin's `register_leisure_meta()` and `register_provider_meta()` methods.

## Admin UI

Legacy meta boxes (Class `Conexao_Data_Model_Meta`). For event/guide/job/sponsor/course_provider/leisure, the admin-ux plugin replaces these with its own editor. The legacy handler skips types managed by admin-ux.

## Dependencies

- None. Must be activated first.
- Other plugins depend on its CPTs/taxonomies existing.

## Important Rules

- Do not rename CPT slugs without updating `.htaccess`, `inc/seo.php`, and all menu filters.
- Term seeding is idempotent.

## Files to Inspect First

- `conexao-data-model.php` — main plugin file, CPT/taxonomy registration
- `includes/class-relationships.php` — taxonomy seeds
- `includes/class-meta.php` — meta registration and editorial boxes
