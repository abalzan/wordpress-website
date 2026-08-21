# Conexão Content

- **Path**: `wp-content/plugins/conexao-content/`
- **Version**: 1.0.0
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
| `create-pages.php` | Page/menu creation script (runs on activation + WP-CLI) |
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
