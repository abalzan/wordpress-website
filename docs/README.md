# Documentation index

Single entry point for navigating this repository's documentation. It links
to each document; it is navigation only — the standards live in the linked
documents themselves. New docs belong under `docs/` and must be added here
in the same change.

## Working with this repository as an agent

If you are an AI agent (or a human following the same discipline), start at
[`AGENTS.md`](../AGENTS.md), then the engineering standard, then the skill that
matches your task:

| Document | What it is |
|---|---|
| [../AGENTS.md](../AGENTS.md) | **Start here** — scope, safety rules, what is authoritative, skill selection |
| [engineering-standard.md](engineering-standard.md) | The authoritative engineering standard (WP-ES) — read this before changing code |
| [templates/plan.md](templates/plan.md) | **Plan template** — copy it before multi-file work, and before any content, route or English/Polylang change |
| [templates/report.md](templates/report.md) | **Report template** — copy it to `docs/reports/` to close a stage or feature with real verification numbers and stated limitations |
| [../.github/PULL_REQUEST_TEMPLATE.md](../.github/PULL_REQUEST_TEMPLATE.md) | The pull-request template aligned with the same standard |
| [testing.md](testing.md) | How to run and write tests, and the stage classification of each suite |

### Agent workflows live in `.agents/skills/`

One directory per reusable agent workflow, each an executable `SKILL.md`
(purpose → when to use/not use → required reading → authoritative sources →
preconditions → steps → guardrails → verification → failure handling →
evidence → definition of done). The full index, with each skill's primary
canonical docs, is [`.agents/skills/README.md`](../.agents/skills/README.md).

| Skill | What it is for |
|---|---|
| `wp-add-content-type` | Registering or changing a post type, taxonomy, meta field, archive or route |
| `wp-add-admin-screen` | A maintainer capability operated through wp-admin (capability, nonce, dry-run preview) |
| `wp-write-in-process-test` | Writing an in-process PHP test suite |
| `wp-http-acceptance-matrix` | Writing an HTTP acceptance row or matrix |
| `wp-run-tests` | Running the full test contract, diagnosing failures, comparing regressions |
| `wp-content-change` | Any content-writing change that is not EN coverage or schema |
| `wp-translation-rollout` | English coverage for a content type and extending the `/en/` layer |
| `wp-add-strings` | Adding/changing user-facing strings and regenerating i18n catalogues |
| `wp-release-deploy` | Building release artifacts, the release record, deployment verification, rollback |
| `wp-production-operations` | Read-only production audits and explicitly authorised production actions |
| `wp-update-docs` | Keeping documentation, indexes, reports and evidence current without drift |
| `wp-security-review` | Reviewing capabilities, nonces, escaping, SQL, secrets and REST boundaries |
| `wp-frontend-perf` | Templates, queries, caching, assets and images with measurable performance discipline |
| `wp-plugin-registry` | Adding/re-classifying a plugin and keeping `plugins.json` the single registry |

The skills are WordPress-domain only. The retired Dart/Flutter skill set that
predated them is kept for provenance under
[`.agents/legacy-flutter-skills/`](../.agents/legacy-flutter-skills/) and is
**not** an active skill set.

The agent-governance contract is machine-checked by
`tests/scripts/verify-agent-governance.py`, which runs with the script-contract
layer of `./scripts/run-tests.sh`.

## Standards, audits and engineering

| Document | What it is |
|---|---|
| [engineering-standard.md](engineering-standard.md) | The authoritative engineering standard (WP-ES) — read this before changing code |
| [audit/](audit/) | Engineering standardisation audits |
| [reports/README.md](reports/README.md) | Index of dated stage/feature reports + the no-root-reports rule |
| [reports/site/](reports/site/) | Repository-engineering stage reports (Stage B repository hygiene, Stage C static quality tooling, …) |
| [evidence/README.md](evidence/README.md) | Standing rule for machine-readable evidence + contents map |
| [project-inventory.md](project-inventory.md) | Project/plugin inventory (versions generated from plugin headers) |

### Permanent invariant gates (Stage L)

Six standing, fail-closed gates turn the engineering standard's safety rules
into CI-enforced checks. They run in the **default** suite
(`./scripts/run-tests.sh`) and are discovered by convention — no separate
runner, no hand-maintained list.

| Domain | Enforces | Where it is documented |
|---|---|---|
| Taxonomy policy | shared `conexao_county`/`conexao_town`; translated `conexao_category`/`conexao_tag` linked both ways | [testing.md](testing.md) §"The permanent invariant gates" |
| Translation completeness | `eligible public PT <type> missing EN = 0` | [testing.md](testing.md) |
| Language-scoped caching | no unscoped `conexao_*` cache key; PT ≠ EN at runtime | [testing.md](testing.md), [frontend.md](frontend.md) |
| Legacy EN→PT redirect precedence | the legacy 301 to PT still wins over a colliding EN page | [testing.md](testing.md), [routing.md](routing.md) |
| Documentation drift | generated regions, script catalogue, `_Last verified:` markers, no root reports | [testing.md](testing.md) |
| i18n catalogue freshness | a `.pot` is never older than the PHP defining its strings | [testing.md](testing.md), §9.3 of the standard |

```bash
python3 scripts/verify-permanent-gates.py   # run every gate, write gate.json
./scripts/i18n-check.sh                     # §9.3's documented entry point
```

A failed gate means a real repository/data violation. Gates are never
downgraded to warnings; the recorded baseline only labels a violation
`pre_existing` vs `new` for reporting, and never suppresses it. Machine-readable
evidence: [evidence/2026-09-26-stage-l/gate.json](evidence/2026-09-26-stage-l/gate.json).

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

The release **artifact allowlist** is likewise derived from `plugins.json` (the
`build: true` entries in load order, plus the theme) by
`scripts/lib/release.py`, and is recorded — together with the version, git SHA,
file count and SHA-256 of every artifact that was actually built — in
`dist/release.json`. See [`docs/releases.md`](releases.md).

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
| [testing.md](testing.md) | **How to run and write tests** — the three-layer model, `./scripts/run-tests.sh`, the shared bootstrap/assertions, prerequisites, fixtures, acceptance base URL, CI |
| [../scripts/README.md](../scripts/README.md) | **The authoritative script catalogue** — every current script with safety level, arguments, default mode, target and last-verified date, plus the new-script contract |
| [deployment.md](deployment.md) | Build ZIPs, deployment to WordPress.com, verification |
| [releases.md](releases.md) | **The release contract** — artifact allowlist, `dist/release.json`, tag convention, deployment verification, rollback procedure and production constraints |

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
| [plugins/conexao-translation-rollout.md](plugins/conexao-translation-rollout.md) | **The shared translation-rollout engine** — the six-step lifecycle, the stage-config and data-manifest contracts, the numeric gate, the remove/rollback contract, and how to add the next rollout |

## History and reference

**Archive, not instructions.** The documents below are the dated historical
record — what happened, how it was verified, what was decided. Operational
procedures live in `.agents/skills/`; never treat a report as the current
procedure.

| Document | What it is |
|---|---|
| [reports/](reports/) | English rollout (Stages 0–9) + navigation-fix reports |
| [english-stage43-production-deployment.md](english-stage43-production-deployment.md) | Stage 4.3 production deployment record |
| [events/](events/) | Events location-filters and copy reports |
| [importers/](importers/) | Event/Lazer/IVVCC/Mondello import pipeline reports and plans |
| [research/](research/) | Topic research notes (e.g. recruitment agencies, employment permits) |
| Root-level `*-report.md` files in `docs/` | Older single-stage feature reports (breadcrumb filter, Lazer cards, homepage hero, …) kept in place for citation stability |

## Testing

One command runs every maintained test — in-process PHP, the Stage I
script-contract gate and HTTP acceptance:

```bash
./scripts/run-tests.sh            # all layers
./scripts/run-tests.sh --scripts   # script-contract gate only
```

See **[testing.md](testing.md)** for the model, the shared bootstrap and
assertion library, data prerequisites, the acceptance base URL and the
manual/historical/production-only classification. The authoritative policy is
[engineering-standard.md §8](engineering-standard.md).

_Last verified: 2026-09-26 by Stage I — Scripts Standardisation_

_Last verified: 2026-09-26 by Stage L — Permanent Invariant Gates_

_Last verified: 2026-09-30 by the agent-skills documentation migration_
