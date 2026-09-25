# AGENTS.md — AI Agent Orientation

## Project

Conexão BR Irlanda: a WordPress community portal for Brazilians in Ireland. Content: Guides, Events, Courses, Jobs, Sponsors, Lazer, Blog.

## Stack

- WordPress 7.0.2 (PHP 8.5, Apache), MySQL (Docker), vanilla JS/CSS.
- Polylang 3.8.9 (Free) for English (`/en/`) — see CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md.
- Production: WordPress.com, domain https://conexaobr.ie.
- No build tooling for JS/CSS. Assets are plain files.

## Repository layout

```
compose.yaml                      # Local Docker (WordPress + MySQL)
.htaccess                         # Rewrites, redirects, caching, security headers
docker/                           # Apache AllowOverride + permission entrypoint
scripts/                          # WP-CLI/build/seed/migration scripts (PHP + bash)
content-inventory/                # Wix migration inventory CSVs
wp-content/plugins/               # 6 custom plugins (see below)
wp-content/themes/conexao-br-irlanda/  # Active theme (only theme)
```

## Plugins (custom, this repo)

All under `wp-content/plugins/`. Load order matters:

| Plugin | Purpose | Docs |
|---|---|---|
| `conexao-data-model` | CPTs, taxonomies, meta | docs/plugins/conexao-data-model.md |
| `conexao-content` | Static pages + shortcodes | docs/plugins/conexao-content.md |
| `conexao-admin-ux` | Custom wp-admin UI + statuses | docs/plugins/conexao-admin-ux.md |
| `conexao-event-runtime` | Production event runtime (event meta, `conexao_town`, `_event_status` gate, status admin UI, recurring-event model + evaluator + query helper) | docs/plugins/conexao-event-runtime.md |
| `conexao-event-importer` | Local-only event import/export tooling (manual, no cron) | docs/plugins/conexao-event-importer.md |
| `conexao-leisure-migration` | Lazer export/import (ZIP) | docs/plugins/conexao-leisure-migration.md |
| `conexao-sponsor-migration` | Apoiadores export/import (JSON + embedded images) | docs/plugins/conexao-sponsor-migration.md |
| `conexao-page-translation` | Stage 4.5 EN page-translation migration (admin importer; activate for the rollout, then remove) | docs/plugins/conexao-page-translation.md |
| `conexao-blog-translation` | Stage 5 Blog EN translation (EN posts page + one linked EN post per public PT post; admin importer + WP-CLI runner; no frontend effect — activate for the rollout, then remove) | docs/plugins/conexao-blog-translation.md |
| `conexao-job-translation` | Stage 6 Job EN translation (one linked EN `job` per eligible public PT job; verbatim `_job_*` meta + shared media; Jobs page pair verified, never created; admin importer + WP-CLI runner; no frontend effect — activate for the rollout, then remove) | docs/plugins/conexao-job-translation.md |
| `conexao-leisure-translation` | Stage 7 EN Leisure card descriptions (one authored EN description per published PT `leisure` record, stored as `_leisure_excerpt_en` on the SAME record; slug-matched, PT-drift-guarded, uuid-safe, reversible; the theme renders it on `/en/lazer/` — activate for the rollout, then remove) | docs/plugins/conexao-leisure-translation.md |
| `conexao-guide-translation` | Stage 9 EN Guide translation (one linked EN `guide` per eligible public PT guide + linked EN `conexao_category` terms; PT date/author/menu order preserved, body authored in English with the PT block structure; gate `eligible public PT guides missing EN = 0`; admin importer + local runner; no frontend effect — activate for the rollout, then remove) | docs/plugins/conexao-guide-translation.md |

## Theme

Active theme: `conexao-br-irlanda` (wp-content/themes/). Docs: docs/themes/conexao-br-irlanda.md.

## Content model (summary)

| Post type | Archive URL | Notes |
|---|---|---|
| `guide` | `/guias/` | Practical guides |
| `event` | `/eventos/` | Imported + manual; `_event_status` gates visibility |
| `course_provider` | `/cursos/` | Directory, links externally |
| `job` | `/empregos/` | Job listings |
| `sponsor` | `/apoiadores/` | Business directory |
| `leisure` | `/lazer/` | Tourism directory; local images |
| `post` | `/blog/` | Native posts (relabelled "Blog"); `/en/blog/` is a real EN archive since Stage 5 (B2 fallback while untranslated) |

Taxonomies: `conexao_category` (shared), `conexao_county` (shared), `conexao_tag` (shared), `conexao_town` (events only).

Language policy (Stage 3.2): `conexao_category` / `conexao_tag` are Polylang-translated (linked EN terms); `conexao_county` / `conexao_town` are NOT — one shared proper-noun term per county/town, identical filters in both languages. See docs/routing.md §English rollout state.

See docs/content-model.md for full details.

## Key routes

- CPT archives: `/guias/`, `/eventos/`, `/cursos/`, `/empregos/`, `/apoiadores/`, `/lazer/`
- Blog: `/blog/`
- Singles: `/{cpt}/{slug}/`, leisure uses `single-leisure.php`
- Filters: `/eventos/?cidade=slug&categoria=slug`, `/lazer/?county=slug&categoria=slug`, `/cursos/?categoria=slug`, `/guias/?categoria=slug`, `/blog/?categoria=slug`, `/empregos/?tipo=&area=&localizacao=&contrato=` (unified opportunities directory)
- Static pages: `/irlanda/`, `/sobre-nos/`, `/contato/`, `/moradia/`, `/saude/`, county pages, etc.

See docs/routing.md.

## Architecture rules (do not break)

1. **Portuguese slugs are canonical.** English URLs 301-redirect. Preserve clean URLs.
2. **Do not use local WordPress IDs (post/attachment) as portable migration identifiers.** Lazer uses stable UUIDs; events use source + source_id + export UUID.
3. **No localhost URLs in production data.**
4. **Events and Lazer are separate.** Lazer pages never render an event calendar.
5. **Production leisure images are local Media Library attachments.** Wikimedia metadata is attribution-only, never a hotlink.
6. **Reuse shared template parts** (`template-parts/`) and the design-system CSS variables. No page-specific CSS hacks.
7. **Dark mode:** reuse existing variables in `assets/css/dark-mode.css`.
8. **Preserve image attribution/license metadata** (`_leisure_image_author`, `_license`, `_attribution`, etc.).
9. **Public event queries must respect `_event_status`** (published or no status). The event-runtime plugin enforces this via `pre_get_posts`.
10. **Front page caching:** transients (`conexao_home_*`, `conexao_404_*`) are invalidated on save. Keep new homepage queries cached.
11. **English is an additional language layer, never a fork.** Polylang adds `/en/`; Portuguese URLs/slugs/identity stay canonical. An English record is a *linked translation* of the same Event/Lazer identity — never a second identity record. Theme language logic lives only in `inc/polylang.php` + `inc/seo.php`; theme/plugin code reads locale through `conexao_current_locale()`. Transient/object caches must be language-scoped. See docs/routing.md §English and CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md.

## Common tasks — where to look

| Task | Read first |
|---|---|
| Change CPT/archive/URL | docs/routing.md, docs/content-model.md |
| Register/change content types | plugins/conexao-data-model |
| Event import pipeline (local) | docs/plugins/conexao-event-importer.md |
| Event runtime / `_event_status` gate | docs/plugins/conexao-event-runtime.md |
| Lazer data/migration | docs/plugins/conexao-leisure-migration.md |
| Apoiador data/migration | docs/plugins/conexao-sponsor-migration.md |
| Admin UI/statuses | plugins/conexao-admin-ux |
| Recruitment agencies / Empregos agency directory | docs/plugins/conexao-data-model.md, theme inc/recruitment-agencies.php |
| Templates/components | docs/themes/conexao-br-irlanda.md |
| CSS/design | docs/frontend.md, theme assets/css/ |
| SEO/redirects/sitemap | theme inc/seo.php |
| Deploy/build ZIPs | docs/deployment.md, scripts/ |

## Running the project

```bash
docker compose up -d          # http://localhost:8080
docker compose exec wordpress wp ...   # WP-CLI
./scripts/build-plugins-zip.sh  # → dist/*.zip
./scripts/build-theme-zip.sh    # → dist/conexao-br-irlanda.zip
```

See docs/development.md.

## Documentation index

Task → Read first

- Architecture → docs/architecture.md
- Content model → docs/content-model.md
- Routes → docs/routing.md
- Frontend/UI → docs/frontend.md
- Deployment → docs/deployment.md
- Local development → docs/development.md
- Plugin inventory → docs/plugins/README.md
- Theme → docs/themes/conexao-br-irlanda.md
- Inventory (machine-readable) → docs/project-inventory.md

## Documentation maintenance

- New plugin / content type / route / deployment mechanism → update the relevant docs in the same change.
- Plugin docs owned by plugin maintainers; theme docs by theme maintainers; architecture/README by the project maintainer.
- Keep AGENTS.md short. Put depth in `docs/`.