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
./scripts/build-plugins-zip.sh   # packages all plugins into dist/
./scripts/build-theme-zip.sh     # packages the theme into dist/
```

## Production

- **Platform**: WordPress.com
- **Domain**: https://conexaobr.ie
- **Deployment**: Manual ZIP upload via WordPress admin
- **Media**: Local Media Library (no external image hosting)
- **Cron**: WordPress.com handles scheduled events

## Project Structure

```
├── compose.yaml                    # Docker Compose
├── .htaccess                       # Rewrite rules, caching, security
├── docker/                         # Apache config, entrypoint
├── scripts/                        # Build, seed, migration, test scripts
├── content-inventory/              # Migration inventory CSVs
├── docs/                           # Project documentation
└── wp-content/
    ├── plugins/
    │   ├── conexao-data-model      # CPTs, taxonomies, meta
    │   ├── conexao-content         # Pages, shortcodes
    │   ├── conexao-admin-ux        # Custom admin UI
    │   ├── conexao-event-importer  # Event aggregation
    │   └── conexao-leisure-migration # Lazer export/import
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
- All custom plugins must be loaded in order: data-model → content → admin-ux → event-importer → leisure-migration.

## Documentation

See `AGENTS.md` for AI-agent orientation and `docs/` for detailed documentation.