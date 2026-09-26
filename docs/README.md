# Documentation index

Single entry point for navigating this repository's documentation. It links
to each document; it is navigation only — the standards live in the linked
documents themselves. New docs belong under `docs/` and must be added here
in the same change.

## Standards, audits and engineering

| Document | What it is |
|---|---|
| [engineering-standard.md](engineering-standard.md) | The authoritative engineering standard (WP-ES) — read this before changing code |
| [audit/](audit/) | Engineering standardisation audits |
| [reports/README.md](reports/README.md) | Index of dated stage/feature reports + the no-root-reports rule |
| [reports/site/](reports/site/) | Repository-engineering stage reports (Stage B repository hygiene, Stage C static quality tooling, …) |
| [evidence/README.md](evidence/README.md) | Standing rule for machine-readable evidence + contents map |
| [project-inventory.md](project-inventory.md) | Project/plugin inventory (versions generated from plugin headers) |

## The plugin registry (single source of truth)

**[`plugins.json`](../plugins.json) is the authoritative plugin list.** It owns
plugin load order, dependencies, lifecycle class/status, the production
activation order, release build inclusion and local Compose mounts.

Every derived list is generated from it by
[`scripts/generate-registry-docs.php`](../scripts/generate-registry-docs.php)
and is marked in the file it lives in, so no list is maintained twice:

```bash
php scripts/generate-registry-docs.php --check   # validate + drift gate (zero writes)
php scripts/generate-registry-docs.php --write   # regenerate the marked regions
```

| Generated region | File |
|---|---|
| Plugin inventory table | `AGENTS.md` |
| Plugin registry summary | `README.md` |
| Load order + lifecycle table | `docs/plugins/README.md` |
| Per-plugin lifecycle metadata | `docs/plugins/conexao-*.md` |
| Production activation order | `docs/deployment.md` |
| Plugin inventory (versions) | `docs/project-inventory.md` |
| Plugin load order | `docs/architecture.md` |
| Release build list | `scripts/build-plugins-zip.sh` |
| Local Compose mounts | `compose.yaml` |

`php scripts/generate-registry-docs.php --check` is a **blocking** CI gate.
To add, remove or re-classify a plugin: edit `plugins.json`, add its doc under
`docs/plugins/`, then run `--write`. Never hand-edit a generated block.

## Architecture and content model

| Document | What it is |
|---|---|
| [architecture.md](architecture.md) | System architecture overview |
| [content-model.md](content-model.md) | Content types, taxonomies, meta and language policy |
| [routing.md](routing.md) | URLs, archives, filters, redirects, sitemap and the `/en/` layer |

## Development and deployment

| Document | What it is |
|---|---|
| [development.md](development.md) | Local setup, Docker, the dev-only quality toolchain (`composer lint` / `analyse` / `./scripts/lint.sh`), and the one test command |
| [testing.md](testing.md) | **How to run and write tests** — the two-layer model, `./scripts/run-tests.sh`, the shared bootstrap/assertions, prerequisites, fixtures, acceptance base URL, CI |
| [deployment.md](deployment.md) | Build ZIPs, deployment to WordPress.com, verification |

## Frontend

| Document | What it is |
|---|---|
| [frontend.md](frontend.md) | UI/design conventions, CSS and JS rules |
| [themes/conexao-br-irlanda.md](themes/conexao-br-irlanda.md) | The active theme — templates, template parts, assets, `inc/` modules |
| [ui/](ui/) | UI change reports (mobile CTA, county filter labels, …) |

## Plugins

| Document | What it is |
|---|---|
| [plugins/README.md](plugins/README.md) | Plugin inventory, load order, lifecycle statuses |
| [plugins/conexao-*.md](plugins/) | One doc per custom plugin (purpose, data, verification) |

## History and reference

| Document | What it is |
|---|---|
| [reports/](reports/) | English rollout (Stages 0–9) + navigation-fix reports |
| [english-stage43-production-deployment.md](english-stage43-production-deployment.md) | Stage 4.3 production deployment record |
| [events/](events/) | Events location-filters and copy reports |
| [importers/](importers/) | Event/Lazer/IVVCC/Mondello import pipeline reports and plans |
| [research/](research/) | Topic research notes (e.g. recruitment agencies, employment permits) |
| Root-level `*-report.md` files in `docs/` | Older single-stage feature reports (breadcrumb filter, Lazer cards, homepage hero, …) kept in place for citation stability |

## Testing

One command runs every maintained test — in-process PHP plus HTTP acceptance:

```bash
./scripts/run-tests.sh
```

See **[testing.md](testing.md)** for the model, the shared bootstrap and
assertion library, data prerequisites, the acceptance base URL and the
manual/historical/production-only classification. The authoritative policy is
[engineering-standard.md §8](engineering-standard.md).

_Last verified: 2026-09-25 by Stage E (unified test harness)_
