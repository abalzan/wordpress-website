# AGENTS.md — AI Agent Orientation

## Project

Conexão BR Irlanda: a WordPress community portal for Brazilians in Ireland. Content: Guides, Events, Courses, Jobs, Sponsors, Lazer, Blog.

## Stack

- WordPress 7.0.2 (PHP 8.5, Apache), MySQL (Docker), vanilla JS/CSS.
- Polylang 3.8.9 (Free) for English (`/en/`) — see docs/reports/CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md.
- Production: WordPress.com, domain https://conexaobr.ie.
- No build tooling for JS/CSS. Assets are plain files.

## Repository layout

```
compose.yaml                      # Local Docker (WordPress + MySQL)
.htaccess                         # Rewrites, redirects, caching, security headers
docker/                           # Apache AllowOverride + permission entrypoint
scripts/                          # WP-CLI/build/seed/migration scripts (PHP + bash)
content-inventory/                # Wix migration inventory CSVs
wp-content/plugins/               # Custom plugins — inventory: plugins.json (see below)
wp-content/themes/conexao-br-irlanda/  # Active theme (only theme)
```

## Plugins (custom, this repo)

All under `wp-content/plugins/`. Load order matters:

<!-- BEGIN GENERATED PLUGIN REGISTRY: AGENTS.md plugin inventory -->
All custom plugins live in `wp-content/plugins/`. **Load order matters.**

This table is generated from [`plugins.json`](../plugins.json) - the single
authoritative registry. Edit the registry and run
`php scripts/generate-registry-docs.php --write`; never hand-edit this table.

| # | Plugin | Class | Status | Production | Build | Compose mount | Documentation |
|---|--------|-------|--------|------------|-------|---------------|---------------|
| 1 | `conexao-data-model` | platform | active | yes | yes | yes | `docs/plugins/conexao-data-model.md` |
| 2 | `conexao-content` | platform | active | yes | yes | yes | `docs/plugins/conexao-content.md` |
| 3 | `conexao-admin-ux` | platform | active | yes | yes | yes | `docs/plugins/conexao-admin-ux.md` |
| 4 | `conexao-event-runtime` | platform | active | yes | yes | yes | `docs/plugins/conexao-event-runtime.md` |
| 5 | `conexao-event-importer` | tooling | active | no | yes | yes | `docs/plugins/conexao-event-importer.md` |
| 6 | `conexao-leisure-migration` | tooling | active | no | yes | yes | `docs/plugins/conexao-leisure-migration.md` |
| 7 | `conexao-sponsor-migration` | tooling | active | no | yes | yes | `docs/plugins/conexao-sponsor-migration.md` |
| 8 | `conexao-translation-rollout` | tooling | active | no | no | yes | `docs/plugins/conexao-translation-rollout.md` |
| 9 | `conexao-page-translation` | rollout | retired | no | no | yes | `docs/plugins/conexao-page-translation.md` |
| 10 | `conexao-blog-translation` | rollout | retired | no | no | yes | `docs/plugins/conexao-blog-translation.md` |
| 11 | `conexao-job-translation` | rollout | retired | no | no | yes | `docs/plugins/conexao-job-translation.md` |
| 12 | `conexao-leisure-translation` | rollout | retired | no | no | yes | `docs/plugins/conexao-leisure-translation.md` |
| 13 | `conexao-guide-translation` | rollout | retired | no | no | yes | `docs/plugins/conexao-guide-translation.md` |

**Production steady state** (platform, `production: true`) - activate in this order: conexao-data-model -> conexao-content -> conexao-admin-ux -> conexao-event-runtime.

**Local-only tooling** (never production): conexao-event-importer, conexao-leisure-migration, conexao-sponsor-migration, conexao-translation-rollout.

**Retired rollout plugins** (historical tooling, *activate → apply → remove*; not a production dependency and not in any release ZIP): conexao-page-translation, conexao-blog-translation, conexao-job-translation, conexao-leisure-translation, conexao-guide-translation.
<!-- END GENERATED PLUGIN REGISTRY: AGENTS.md plugin inventory -->

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
11. **English is an additional language layer, never a fork.** Polylang adds `/en/`; Portuguese URLs/slugs/identity stay canonical. An English record is a *linked translation* of the same Event/Lazer identity — never a second identity record. Theme language logic lives only in `inc/i18n/` (plus the SEO modules in `inc/seo/`); theme/plugin code reads locale through `conexao_current_locale()`. Transient/object caches must be language-scoped. See docs/routing.md §English and docs/reports/CONEXAO_BR_ENGLISH_STAGE_2_REPORT.md.
12. **Theme runtime is loader + modules.** `functions.php` is loader-only (constants + `require_once`); logic lives with one concern per file in `inc/*.php`. Language policy → `inc/i18n/`, SEO output → `inc/seo/`, bilingual REST → `inc/rest-language.php`. Public `conexao_*` function names and the filter/action surface are stable across modules. See docs/themes/conexao-br-irlanda.md §Runtime Architecture.
13. **Release: the registry decides what may ship; the manifest records what did.** The artifact allowlist is *derived* from `plugins.json` (`build: true`, in load order, plus the theme) — never hand-maintained. Every build emits `dist/release.json` recording, per artifact, the version (read from the component header), the git SHA, the file count and the SHA-256. Packaging rules and determinism live once in `scripts/lib/zip-build.sh`; the release record lives once in `scripts/lib/release.py`. `scripts/verify-deploy.py` is GET-only and requires an explicit `--site`. See docs/releases.md.

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
| Add/change a translation rollout | docs/plugins/conexao-translation-rollout.md (shared engine: stage config + data manifest, no `apply.php`/`audit.php` copies) |
| Recruitment agencies / Empregos agency directory | docs/plugins/conexao-data-model.md, theme inc/recruitment-agencies.php |
| Templates/components | docs/themes/conexao-br-irlanda.md |
| Run/extend the test suite | docs/testing.md, `./scripts/run-tests.sh --help` |
| Write or run a repository script | `scripts/README.md` (authoritative catalogue), docs/development.md |
| CSS/design | docs/frontend.md, theme assets/css/ |
| SEO/redirects/sitemap | theme `inc/seo/` (see `redirects.php`, `sitemap.php`) |
| Language policy (Polylang) | theme `inc/i18n/` |
| Deploy/build ZIPs | docs/deployment.md, scripts/ |
| **Release: manifest, tag, verification, rollback** | **docs/releases.md**, `./scripts/verify-release.sh` |
| Plugin registry / lifecycle / load order | `plugins.json` (authoritative), `php scripts/generate-registry-docs.php --check` |

## Running the project

```bash
docker compose up -d          # http://localhost:8080
./scripts/run-tests.sh        # ALL tests: in-process PHP + script contract + HTTP acceptance
./scripts/run-tests.sh --scripts   # the Stage I script-contract gate only
docker compose exec wordpress wp ...   # WP-CLI
./scripts/build-plugins-zip.sh  # → dist/*.zip + dist/release.json
./scripts/build-theme-zip.sh    # → dist/conexao-br-irlanda.zip + dist/release.json
./scripts/verify-release.sh     # the whole release workflow, proven locally
python3 scripts/verify-deploy.py --site <url>   # read-only HTTP deployment check
```

## Releases

`plugins.json` determines **what can be released** (the artifact allowlist);
`dist/release.json` records **what was actually built** (version, git SHA,
file count, byte size, SHA-256). Artifacts are built deterministically, so the
recorded hash is reproducible. `scripts/verify-deploy.py` then checks the
deployed site over HTTP without mutating it. See [docs/releases.md](docs/releases.md).

## Tests

`./scripts/run-tests.sh` is the single test command (in-process PHP +
script-contract + HTTP acceptance, one aggregate exit code). Shared bootstrap:
`tests/bootstrap.php`. Shared assertions: `tests/lib/assertions.php`.
Acceptance + matrices: `tests/acceptance/`. Script conventions:
`tests/scripts/`. Suites are discovered by convention
(`<component>/tests/test-*.php`, `tests/scripts/verify-*.py`,
`tests/acceptance/verify-*-http.py`) — never from a hardcoded list. See
[docs/testing.md](docs/testing.md).

## Scripts

**`scripts/README.md` is the authoritative script catalogue** (purpose, safety
level, arguments, default mode, target, write behaviour, last verified).

- `scripts/lib/bootstrap.php` — the canonical PHP bootstrap: the only place in
  `scripts/` allowed to locate and load WordPress, plus the standard CLI
  contract, run header and production write guard.
- `scripts/lib/rest.py` — the shared Python REST client (target, credentials,
  retries, pagination, errors). Credentials come from `WP_USERNAME` /
  `WP_APPLICATION_PASSWORD` only.
- Write-capable scripts use `--dry-run` (the default; zero writes) / `--apply`,
  print `script`/`target`/`mode`/`scope` and a `summary:`, and refuse an
  unconfirmed production write.
- `scripts/historical/` = provenance only, not supported tooling.
  `scripts/diagnostics/` = ad-hoc helpers, not the supported workflow.

See docs/development.md.

## Documentation index

Task → Read first

- Full docs index → docs/README.md
- Architecture → docs/architecture.md
- Content model → docs/content-model.md
- Routes → docs/routing.md
- Frontend/UI → docs/frontend.md
- Deployment → docs/deployment.md
- **Releases (manifest, tag, verification, rollback) → docs/releases.md**
- Local development → docs/development.md
- **Engineering standard (how to build here) → docs/engineering-standard.md**
- Plugin inventory → docs/plugins/README.md
- Theme → docs/themes/conexao-br-irlanda.md
- Inventory (machine-readable) → docs/project-inventory.md
- Standardisation audit (2026-09-25) → docs/audit/2026-09-25-wordpress-engineering-standardisation-audit.md

## Documentation maintenance

- **New work must follow `docs/engineering-standard.md`** (it is mandatory for new plugins, scripts, tests, migrations and docs). Existing code is grandfathered until that standard's adoption stages land.
- New plugin / content type / route / deployment mechanism → update the relevant docs in the same change.
- **Plugin lifecycle rule:** `plugins.json` is the single source of truth for plugin load order, dependencies, production activation order, release build inclusion and local Compose mounts. Edit it, then run `php scripts/generate-registry-docs.php --write`. Never hand-edit a region marked `GENERATED PLUGIN REGISTRY`, and never add a second plugin list. `--check` is a blocking CI gate.
- Plugin docs owned by plugin maintainers; theme docs by theme maintainers; architecture/README by the project maintainer.
- Keep AGENTS.md short. Put depth in `docs/`.

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_
