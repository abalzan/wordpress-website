# Plugins

This project contains 10 custom WordPress plugins. All are in `wp-content/plugins/`.

## Plugin Inventory

| Plugin | Path | Version | Purpose | Docs |
|--------|------|---------|---------|------|
| conexao-data-model | `wp-content/plugins/conexao-data-model/` | 1.3.0 | CPTs, taxonomies, editorial meta | conexao-data-model.md |
| conexao-content | `wp-content/plugins/conexao-content/` | 1.0.0 | Static pages, shortcodes | conexao-content.md |
| conexao-admin-ux | `wp-content/plugins/conexao-admin-ux/` | 1.0.0 | Custom admin UI, statuses, bulk actions | conexao-admin-ux.md |
| conexao-event-runtime | `wp-content/plugins/conexao-event-runtime/` | 1.0.0 | **Production** event runtime: event meta, `conexao_town`, `_event_status` gate, status admin UI | conexao-event-runtime.md |
| conexao-event-importer | `wp-content/plugins/conexao-event-importer/` | 1.5.0 | Local-only event import + export to production (manual, no cron) | conexao-event-importer.md |
| conexao-leisure-migration | `wp-content/plugins/conexao-leisure-migration/` | 2.0.0 | Lazer ZIP export/import with images | conexao-leisure-migration.md |
| conexao-sponsor-migration | `wp-content/plugins/conexao-sponsor-migration/` | 1.0.0 | Apoiadores JSON export/import with embedded Desktop/Mobile images | conexao-sponsor-migration.md |
| conexao-page-translation | `wp-content/plugins/conexao-page-translation/` | 1.0.0 | Stage 4.5 EN page-translation migration (admin importer; Polylang-linked pages; PT originals never modified) | conexao-page-translation.md |
| conexao-blog-translation | `wp-content/plugins/conexao-blog-translation/` | 1.0.0 | Stage 5 Blog EN translation (linked EN posts page + one linked EN translation per public PT post; PT originals never modified; no frontend effect) | conexao-blog-translation.md |
| conexao-job-translation | `wp-content/plugins/conexao-job-translation/` | 1.0.0 | Stage 6 Job EN translation (one linked EN `job` per eligible public PT job; verbatim `_job_*` meta + shared media; Jobs page pair verified, never created; admin importer + WP-CLI runner; no frontend effect) | conexao-job-translation.md |
| conexao-leisure-translation | `wp-content/plugins/conexao-leisure-translation/` | 1.0.0 | Stage 7 EN Leisure card descriptions (one authored EN description per published PT `leisure` record, stored as `_leisure_excerpt_en` on the SAME record — no duplicate records, no UUID changes, PT-drift-guarded, reversible; rendered by the theme on `/en/lazer/`) | conexao-leisure-translation.md |
| conexao-guide-translation | `wp-content/plugins/conexao-guide-translation/` | 1.0.0 | Stage 9 EN Guide translation (one linked EN `guide` per eligible public PT guide + linked EN `conexao_category` terms; PT date/author/menu order preserved, body authored in English with the PT block structure; PT originals never modified; admin importer + local runner; no frontend effect) | conexao-guide-translation.md |

## Load Order

Plugins must be activated in this order (dependencies first):

1. `conexao-data-model`
2. `conexao-content`
3. `conexao-admin-ux`
4. `conexao-event-runtime`
5. `conexao-event-importer` *(local tooling only — not needed on production)*
6. `conexao-leisure-migration`
7. `conexao-sponsor-migration`
8. `conexao-page-translation` *(migration tooling — activate for the Stage 4.5 rollout, then deactivate/remove)*
9. `conexao-blog-translation` *(migration tooling — activate for the Stage 5 Blog rollout, then deactivate/remove)*
10. `conexao-job-translation` *(migration tooling — activate for the Stage 6 Jobs rollout, then deactivate/remove)*
11. `conexao-leisure-translation` *(migration tooling — activate for the Stage 7 Leisure-description rollout, then deactivate/remove; no frontend effect)*
12. `conexao-guide-translation` *(migration tooling — activate for the Stage 9 Guides rollout, then deactivate/remove; no frontend effect)*

`conexao-event-importer` declares `Requires Plugins: conexao-data-model, conexao-event-runtime`,
so WordPress refuses to activate it (and keeps it from running) without the runtime plugin.

## Third-Party Plugins

No third-party plugins are bundled in this repository. The project relies only on these 10 custom plugins and core WordPress functionality (plus Polylang Free 3.8.9 for the English layer — see `CONEXAO_BR_ENGLISH_ARCHITECTURE_DECISION.md`).

## Building

```bash
./scripts/build-plugins-zip.sh
```

Output: `dist/*.zip` — one ZIP per plugin, structured for WordPress plugin upload.
