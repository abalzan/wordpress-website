# Routing

## Canonical URL Structure

Portuguese slugs are canonical. English URLs redirect to Portuguese equivalents via 301.

### Custom Post Type Archives

| Content Type | Portuguese URL | English URL (redirects) |
|-------------|----------------|------------------------|
| Guides | `/guias/` | `/guides/` |
| Events | `/eventos/` | `/events/` |
| Courses | `/cursos/` | `/courses/` |
| Jobs | `/empregos/` — **landing page** (job archive disabled) | `/jobs/` |
| Sponsors | `/apoiadores/` | `/sponsors/` |
| Lazer | `/lazer/` | `/leisure/` |

> **Note (Empregos):** the `job` CPT archive is disabled (see
> [`plugins/conexao-data-model`](plugins/README.md)). `/empregos/` is now a
> normal WordPress page — the "Jobs Landing" hub — rendered by the theme's
> `page-empregos.php` template. Individual job posts keep their
> `/empregos/{slug}/` URLs (e.g. `/empregos/oportunidades/`).
| Blog | `/blog/` | n/a |

### Single Items

- `/{post_type}/{slug}/` — all CPTs except leisure (which uses `single-leisure.php`)
- `/lazer/{slug}/` — uses `single-leisure.php`
- `/blog/{slug}/` — native WordPress posts

### Static Pages

Created by `conexao-content` plugin:

- `/inicio/`, `/blog/`, `/sobre-nos/`, `/contato/`, `/newsletter/`, `/revista/`, `/anuncie/`
- `/politica-de-privacidade/`, `/termos-de-uso/`, `/cookies/`
- `/irlanda/`, `/europa/`, county pages (e.g., `/laois/`, `/dublin/`)
- Category landing pages: `/moradia/`, `/saude/`, `/familia/`, etc.

### Filters (Query Parameters)

| Archive | Parameters | Taxonomy/Meta |
|---------|-----------|---------------|
| `/eventos/` | `?cidade=slug` | `conexao_town` |
| `/eventos/` | `?categoria=slug` | `conexao_category` |
| `/lazer/` | `?county=slug` | `conexao_county` |
| `/lazer/` | `?categoria=slug` | `conexao_category` |
| `/cursos/` | `?categoria=slug` | `_provider_category` (meta) |
| `/guias/` | `?categoria=slug` | `conexao_category` |
| `/blog/` | `?categoria=slug` | `category` (native) |
| `/empregos/` | `?area=`, `?localizacao=`, `?contrato=` | `_agency_job_types` / normalized `_agency_location` / `_agency_temporary`+`_agency_permanent` (meta) — agency directory only |

Filters are content-type-aware: the same `?categoria=` parameter resolves to different taxonomies/meta on different archives. Blog category links point at `/blog/?categoria=slug` (the Blog archive itself), not at the native `/category/{slug}/` archive — which remains intact for direct access, feeds and wp-admin. The `/empregos/` parameters are page-level (static landing template) and affect ONLY the "Agências de recrutamento" directory rendered by `template-parts/recruitment-agencies.php` — never the public-sector or Employment Permit sections.

## Redirect Architecture

Two-layer redirect system:

### 1. .htaccess (Apache mod_rewrite)

English → Portuguese CPT archives:
```
/guides/ → /guias/
/events/ → /eventos/
/courses/ → /cursos/
/jobs/ → /empregos/
/sponsors/ → /apoiadores/
```

English → Portuguese static pages:
```
/ireland/ → /irlanda/
/about-us/ → /sobre-nos/
/contact/ → /contato/
```

Legacy Wix redirects:
```
/turismo-e-lazer/ → /eventos/
/guias-praticos/ → /guias/
/post/{slug}/ → /blog/{slug}/
/categories/{slug}/ → /{slug}/
/counties/{slug}/ → /{slug}/
```

### 2. PHP (inc/seo.php — `conexao_seo_redirects()`)

Runs at `template_redirect` priority 5. Handles:

- English guide paths: `/guides/{slug}` → `/guias/{slug}/`
- Legacy guide paths: `/guias-praticos/{slug}` → `/guias/{slug}/`
- English paths: `/events/{slug}`, `/jobs/{slug}`, `/courses/{slug}`, `/sponsors/{slug}`
- Legacy `/post/{slug}` → `/blog/{slug}/`
- Legacy `/blog/categories/{slug}` → `/category/{slug}/`
- Direct 301 map with ~60+ entries

### 3. Leisure External Redirect (PHP — `conexao_leisure_redirect()`)

Runs at `template_redirect` priority 6. Redirects individual leisure posts to their configured external URL (Official Website or Discover Ireland URL) via 302 if one is set.

## Template Routing

```
front-page.php          → /
archive.php             → /guias/, /eventos/, /cursos/, /apoiadores/, /lazer/
page-empregos.php       → /empregos/ (Jobs Landing page; job archive disabled)
single-leisure.php      → /lazer/{slug}/
single.php              → /{cpt}/{slug}/ (all other CPTs)
page.php                → /{slug}/ (static pages)
home.php                → /blog/
search.php              → /search/
404.php                 → 404 errors
page-landing.php        → landing page template (specific pages)
```

## Navigation Architecture

The theme dynamically modifies the primary navigation at render time via `wp_nav_menu_objects` filters:

1. **Priority 20** (`conexao_modify_primary_nav_items`): Removes "Notícias" and "Sobre Nós" items, changes "Home" to "Início", inserts "Blog" and "Cursos" items.
2. **Priority 25** (`conexao_normalize_primary_nav_sections`): Binds each nav item to its canonical WordPress object for reliable active-state detection.

Canonical nav order: Início, Blog, Guias, Eventos, Cursos, Lazer, Empregos, Apoiadores, Contato.

Note: "Irlanda" is intentionally NOT a navigation item. The /irlanda/ page remains published and directly accessible; it is simply not linked from the main navigation (desktop and mobile share the same `primary` menu). See `scripts/remove-irlanda-menu-item.php` for removing any legacy "Irlanda" item from an existing menu.

Note: "Sobre Nós" is intentionally NOT a navigation item either. The /sobre-nos/ page remains published and directly accessible at /sobre-nos/; it is simply not linked from the main navigation (desktop and mobile share the same `primary` menu). See `scripts/remove-sobre-nos-menu-item.php` for removing any legacy "Sobre Nós" item from an existing menu.

Active-state resolution uses URL pattern matching in `conexao_fix_nav_active_states()`.