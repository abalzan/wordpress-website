# Conexão BR Irlanda — WordPress Website

Community portal for Brazilians in Ireland. A digital magazine and community hub featuring guides, events, courses, jobs, sponsors, and a leisure/tourism directory.

## Tech Stack

- **WordPress** 7.0.2 (PHP 8.5, Apache)
- **PHP** 8.5
- **JavaScript** (vanilla, no framework)
- **CSS** (custom design system, no framework)
- **Docker** (local development)
- **MySQL** (local database)
- **WordPress.com** (production platform)

## Local Development

```bash
docker compose up -d
```

- WordPress: http://localhost:8080
- Admin: http://localhost:8080/wp-admin
- MySQL: localhost:3306 (user: `wordpress`, password: `wordpress`, database: `wordpress`)

### Environment

Copy `.env.example` to `.env` only when overriding defaults. All defaults work out of the box.

### Build

```bash
./scripts/build-plugins-zip.sh   # packages the registry's build:true plugins into dist/
./scripts/build-theme-zip.sh     # packages the theme into dist/
```

Both builds are deterministic and emit `dist/release.json`, which records the
version, git SHA, file count and SHA-256 of every artifact that was actually
built, alongside the allowlist `plugins.json` permits.

```bash
python3 scripts/release-manifest.py --verify       # allowlist + hash verification
./scripts/verify-release.sh                         # prove the whole release workflow locally
python3 scripts/verify-deploy.py --site <url>       # read-only HTTP check of a deployment
```

Release procedure, tag convention and rollback: **[docs/releases.md](docs/releases.md)**.

## Plugins

<!-- BEGIN GENERATED PLUGIN REGISTRY: README.md plugin registry -->
### Plugin registry

**The authoritative plugin list is [`plugins.json`](plugins.json)** - it owns load order,
dependencies, lifecycle status, the production activation order, release build inclusion
and local Compose mounts. The table below is generated from it by
`scripts/generate-registry-docs.php`; every derived list in this repository comes from
that one file.

| # | Plugin | Class | Status | Production | Build | Compose mount | Docs |
|---|--------|-------|--------|------------|-------|---------------|------|
| 1 | `conexao-data-model` | platform | active | yes | yes | yes | [conexao-data-model](docs/plugins/conexao-data-model.md) |
| 2 | `conexao-content` | platform | active | yes | yes | yes | [conexao-content](docs/plugins/conexao-content.md) |
| 3 | `conexao-admin-ux` | platform | active | yes | yes | yes | [conexao-admin-ux](docs/plugins/conexao-admin-ux.md) |
| 4 | `conexao-event-runtime` | platform | active | yes | yes | yes | [conexao-event-runtime](docs/plugins/conexao-event-runtime.md) |
| 5 | `conexao-event-importer` | tooling | active | no | yes | yes | [conexao-event-importer](docs/plugins/conexao-event-importer.md) |
| 6 | `conexao-leisure-migration` | tooling | active | no | yes | yes | [conexao-leisure-migration](docs/plugins/conexao-leisure-migration.md) |
| 7 | `conexao-sponsor-migration` | tooling | active | no | yes | yes | [conexao-sponsor-migration](docs/plugins/conexao-sponsor-migration.md) |
| 8 | `conexao-translation-rollout` | tooling | active | no | no | yes | [conexao-translation-rollout](docs/plugins/conexao-translation-rollout.md) |
| 9 | `conexao-en-translation` | tooling | active | no | no | yes | [conexao-en-translation](docs/plugins/conexao-en-translation.md) |
| 10 | `conexao-translation-automation` | tooling | active | no | no | yes | [conexao-translation-automation](docs/plugins/conexao-translation-automation.md) |
| 11 | `conexao-page-translation` | rollout | retired | no | no | yes | [conexao-page-translation](docs/plugins/conexao-page-translation.md) |
| 12 | `conexao-blog-translation` | rollout | retired | no | no | yes | [conexao-blog-translation](docs/plugins/conexao-blog-translation.md) |
| 13 | `conexao-job-translation` | rollout | retired | no | no | yes | [conexao-job-translation](docs/plugins/conexao-job-translation.md) |
| 14 | `conexao-leisure-translation` | rollout | retired | no | no | yes | [conexao-leisure-translation](docs/plugins/conexao-leisure-translation.md) |
| 15 | `conexao-guide-translation` | rollout | retired | no | no | yes | [conexao-guide-translation](docs/plugins/conexao-guide-translation.md) |

**Production steady state** (platform, `production: true`) - activate in this order: conexao-data-model -> conexao-content -> conexao-admin-ux -> conexao-event-runtime.

**Local-only tooling** (never production): conexao-event-importer, conexao-leisure-migration, conexao-sponsor-migration, conexao-translation-rollout, conexao-en-translation, conexao-translation-automation.

**Retired rollout plugins** (historical tooling, *activate → apply → remove*; not a production dependency and not in any release ZIP): conexao-page-translation, conexao-blog-translation, conexao-job-translation, conexao-leisure-translation, conexao-guide-translation.

Versions are **not** duplicated in the registry: the WordPress plugin header is the
authoritative source, and each entry records only where to read it (`version_source`).
<!-- END GENERATED PLUGIN REGISTRY: README.md plugin registry -->

## Production

- **Platform**: WordPress.com
- **Domain**: https://conexaobr.ie
- **Deployment**: Manual ZIP upload via WordPress admin
- **Media**: Local Media Library (no external image hosting)
- **Cron**: WordPress.com handles scheduled events

## Project Structure

```
├── plugins.json                       # Authoritative plugin registry (load order, build, mounts, lifecycle)
├── compose.yaml                    # Docker Compose
├── .htaccess                       # Rewrite rules, caching, security
├── docker/                         # Apache config, entrypoint
├── scripts/                        # Build, seed, migration, test scripts
├── content-inventory/              # Migration inventory CSVs
├── docs/                           # Project documentation
└── wp-content/
    ├── plugins/                    # Custom plugins (inventory: plugins.json)
    └── themes/
        └── conexao-br-irlanda      # Active theme
```

## Main Functionality

| Section | Post Type | Archive URL | Description |
|---------|-----------|-------------|-------------|
| Guides | `guide` | `/guias/` | Practical guides for Brazilians |
| Events | `event` | `/eventos/` | Community events (imported + manual) |
| Courses | `course_provider` | `/cursos/` | Course provider directory |
| Jobs | `job` | `/empregos/` | Job listings |
| Sponsors | `sponsor` | `/apoiadores/` | Business directory |
| Lazer | `leisure` | `/lazer/` | Tourism/leisure directory |
| Blog | `post` | `/blog/` | Native WordPress posts |

## Important Frontend Routes

| Route | Template | Description |
|-------|----------|-------------|
| `/` | `front-page.php` | Homepage with hero, quick access, sections |
| `/guias/` | `archive.php` | Guides archive |
| `/eventos/` | `archive.php` | Events archive (upcoming, filtered) |
| `/cursos/` | `archive.php` | Course providers archive |
| `/empregos/` | `archive.php` | Jobs archive |
| `/apoiadores/` | `archive.php` | Sponsors archive |
| `/lazer/` | `archive.php` | Leisure archive |
| `/lazer/{slug}/` | `single-leisure.php` | Single leisure location |
| `/blog/` | `home.php` | Blog archive |
| `/{slug}/` | `page.php` | Static pages |
| `/search/` | `search.php` | Search results |

## Development Conventions

- Portuguese slugs are canonical; English URLs redirect via 301.
- Do not use WordPress attachment IDs as portable migration identifiers.
- Do not use localhost URLs in production.
- Keep Events separate from Lazer (no event calendar on leisure pages).
- Reuse shared template parts (`template-parts/`).
- Reuse existing dark-mode CSS variables.
- Preserve attribution/license metadata for imported images.
- Avoid page-specific CSS hacks when a shared component can be fixed.
- Custom plugin load order, production activation order, release build list and local
  Compose mounts all come from `plugins.json`; see the plugin registry section below.

## Plugin registry workflow

`plugins.json` is the **only** authoritative plugin list. Every derived list
(load order, production activation order, release build list, Compose mounts,
lifecycle documentation) is generated from it:

```bash
php scripts/generate-registry-docs.php --check   # validate + drift gate (zero writes)
php scripts/generate-registry-docs.php --write   # regenerate the marked regions
```

Never hand-edit a generated block; edit `plugins.json` and re-run `--write`.

## Documentation

See `AGENTS.md` for AI-agent orientation and `docs/` for detailed documentation.
_Last verified: 2026-09-26 by Stage L — Permanent Invariant Gates_
