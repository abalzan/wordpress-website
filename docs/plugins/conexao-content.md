# Conexão Content

<!-- BEGIN GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->
| | |
|---|---|
| **Status** | active |
| **Class** | platform |
| **Production** | yes |
| **Build** | yes |
| **Compose mount** | yes |
| **Dependencies** | none |
| **Version** | 1.0.1 (authoritative source: `wp-content/plugins/conexao-content/conexao-content.php` header) |
| **Registry** | [`plugins.json`](../../plugins.json) |

> **Production platform plugin.** Part of the production steady state.
<!-- END GENERATED PLUGIN REGISTRY: plugin lifecycle metadata -->

- **Path**: `wp-content/plugins/conexao-content/`
- **Version**: 1.0.1
- **Purpose**: Creates static pages, navigation menus, and provides display shortcodes.

## Responsibilities

- Create all static pages on activation (main pages, category pages, county pages, Ireland/Europe pages)
- Create and configure navigation menus (Menu Principal, Menu Rodapé)
- Provide `[conexao_grid]` shortcode for category-filtered card grids
- Provide `[conexao_blog_categories]` shortcode for blog category pills
- Set front page and posts page on activation

## Key Components

| File | Purpose |
|------|---------|
| `conexao-content.php` | Main plugin file, shortcodes, activation hook |
| `create-pages.php` | Page/menu creation script (runs on activation, or manually via `wp eval-file wp-content/plugins/conexao-content/create-pages.php --allow-root`) |
| `assets.css` | Styles for shortcode grids |

## Shortcodes

### `[conexao_grid]`

Renders an SEO-friendly grid of links filtered by conexao_category slugs.

```text
[conexao_grid categories="moradia,saude" title="Recursos Úteis" count="10"]
```

Parameters:
- `categories` — comma-separated conexao_category slugs (optional)
- `title` — section heading (optional)
- `count` — max items (default: 10)

Links resolve to external URLs when the post has one (events → `_event_url`, sponsors → `_sponsor_link`, jobs → `_job_url`, course_providers → `_provider_url`).

Event visibility is enforced by the shortcode itself: because it queries with `post_type=any`, it mirrors the Event Runtime's public rule (`_event_status = published` OR no status for legacy events). Hidden events — `draft`, `expired`, `source_not_found`, `rejected` — never appear in the grid, with or without the `categories` filter. Non-event posts are unaffected (they never carry `_event_status`).

### `[conexao_blog_categories]`

Renders category pills linking to `/blog/` filtered by native WordPress category.

### `[conexao_course_providers]`

Renders the Cursos directory: filter bar + provider card grid. Registered by the theme in `functions.php`.

## Pages Created

Full list in `create-pages.php`. Includes:
- Main pages: inicio, blog, sobre-nos, contato, newsletter, anuncie, politica-de-privacidade, termos-de-uso, cookies, search
- Category pages: ~15 community category pages
- Ireland page, Europe page, 9 county pages
- Redirect pages for legacy slugs

## Dependencies

- `conexao-data-model` (for CPTs used in grid queries)

## Files to Inspect First

- `conexao-content.php` — shortcodes and hooks
- `create-pages.php` — page creation logic
