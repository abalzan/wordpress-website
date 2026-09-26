# AGENTS.md — AI Agent Orientation

Concise entry point for coding agents working on this repository. **Depth lives
in [`docs/`](docs/README.md); do not duplicate it here.** If this file and
`docs/engineering-standard.md` disagree, the standard wins.

## What this repository is

Conexão BR Irlanda: a **WordPress** community portal for Brazilians in Ireland.
Content: Guides, Events, Courses, Jobs, Sponsors, Lazer and Blog. A bilingual
Portuguese/English site served by Polylang. Local development runs in Docker
(`compose.yaml`); production is **WordPress.com**.

## Read this before changing anything

1. **This file** — orientation and the non-negotiable rules below.
2. **[`docs/engineering-standard.md`](docs/engineering-standard.md)** — the
   authoritative engineering standard. It is mandatory for all new work.
3. **[`docs/README.md`](docs/README.md)** — the documentation index.

Then, for the kind of work you are doing, the matching agent skill in
[`.agents/skills/`](.agents/skills/) and the documents it lists under
**Required reading**. Each skill is an executable workflow, not a summary:

| Skill | Use it for |
|---|---|
| [`wp-add-content-type`](.agents/skills/wp-add-content-type/SKILL.md) | a new/changed post type, taxonomy, meta field, archive or route |
| [`wp-add-admin-screen`](.agents/skills/wp-add-admin-screen/SKILL.md) | a maintainer capability operated through wp-admin |
| [`wp-write-in-process-test`](.agents/skills/wp-write-in-process-test/SKILL.md) | an in-process PHP test suite |
| [`wp-http-acceptance-matrix`](.agents/skills/wp-http-acceptance-matrix/SKILL.md) | an HTTP acceptance row or matrix |
| [`wp-release-deploy`](.agents/skills/wp-release-deploy/SKILL.md) | build, manifest, deploy, verify, rollback |
| [`wp-update-docs`](.agents/skills/wp-update-docs/SKILL.md) | keeping documentation and the index current |
| [`wp-security-review`](.agents/skills/wp-security-review/SKILL.md) | capabilities, nonces, escaping, SQL, secrets |
| [`wp-frontend-perf`](.agents/skills/wp-frontend-perf/SKILL.md) | templates, queries, caching, assets |
| [`wp-translation-rollout`](.agents/skills/wp-translation-rollout/SKILL.md) | English coverage for a content type |
| [`wp-plugin-registry`](.agents/skills/wp-plugin-registry/SKILL.md) | adding/re-classifying a plugin, build or mount facts |

## Scope: what you may and may not touch

| In scope | Never touch |
|---|---|
| The WordPress repository only | **The Flutter/mobile application repository** — never its code, tests, docs, REST clients or deployment workflows |
| `wp-content/`, `scripts/`, `tests/`, `docs/`, `.github/`, build/deploy | Production state, unless a task explicitly authorises a production action |
| Local Docker development | The external Flutter/mobile repository, from any task in this repository |

A task that requires changing the mobile app is out of scope **here**; say so
rather than reaching outside this repository.

## What is authoritative

| Concern | The single source of truth |
|---|---|
| Engineering rules | [`docs/engineering-standard.md`](docs/engineering-standard.md) |
| Plugin registry — load order, class, status, production, build, mount, dependencies, version source, docs path | **[`plugins.json`](plugins.json)** — see [`wp-plugin-registry`](.agents/skills/wp-plugin-registry/SKILL.md) |
| Script inventory | **[`scripts/README.md`](scripts/README.md)** |
| Routes, filters, redirects, the `/en/` layer | [`docs/routing.md`](docs/routing.md) |
| Content types, taxonomies, meta, language policy | [`docs/content-model.md`](docs/content-model.md) |
| Release contract, manifest, deploy verification, rollback | [`docs/releases.md`](docs/releases.md) |
| Documentation index | [`docs/README.md`](docs/README.md) |
| Theme internals | [`docs/themes/conexao-br-irlanda.md`](docs/themes/conexao-br-irlanda.md) |

**Never introduce a second source of truth.** Any list that must stay in sync
is generated from or checked against one of the above — never hand-maintained
twice.

## Workflow: plan, build, verify, report

1. **Plan.** For multi-file work, or anything touching content, routes or
   English/Polylang, fill in **[`docs/templates/plan.md`](docs/templates/plan.md)**.
2. **Reuse, do not reinvent.** Prefer the existing shared engines and helpers
   over new copies; copying a plugin, script or engine requires a written
   justification in the report. Known shared pieces: `scripts/lib/bootstrap.php`
   and `scripts/lib/rest.py` (scripts), `scripts/lib/zip-build.sh` and
   `scripts/lib/release.py` (packaging and the release record),
   `scripts/lib/plan.py` (machine-readable plans), the
   `conexao-translation-rollout` engine (rollout lifecycle), `tests/bootstrap.php`
   and `tests/lib/assertions.php` (tests), and
   `scripts/generate-registry-docs.php` (every generated registry region).
3. **Verify with real numbers.** Run the checks the skill names and paste the
   actual output. `./scripts/run-tests.sh` is the single test command (in-process
   PHP + script contract + HTTP acceptance, one aggregate exit code).
4. **Report.** Fill in **[`docs/templates/report.md`](docs/templates/report.md)**
   and put machine evidence in `docs/evidence/<date>-<stage>/`. Never add a
   report or evidence file at the repository root. No claim of "fully verified"
   when a verifier was unavailable — use `PASS WITH LIMITATION` and say why.

## Safety rules that are not negotiable

- **PT content is immutable** in an English change. PT URLs, slugs and record
  identity never change as a side effect of EN work. An EN record is a *linked
  translation* of the same identity, never a second identity record.
- **Preserve the Polylang rules.** `conexao_category` and `conexao_tag` are
  translated; `conexao_county` and `conexao_town` are shared. Locale is read
  only through `conexao_current_locale()`. Content-derived cache keys are
  language-scoped and invalidation clears every language. A B2 fallback is
  canonical → PT, noticed, and out of the sitemap.
- **No production writes** unless the task explicitly authorises them. No
  production content mutation, Polylang change, DB migration, upload or
  activation from this repository.
- **Production has no CLI.** WordPress.com: no SSH, no WP-CLI, no filesystem, no
  database. Anything operated in production must have an admin screen.
- **Content writes are dry-run first** and follow the six-step content-change
  contract: inventory → manifest → dry-run plan → snapshot → idempotent apply →
  verify + numeric gate.
- **Portable identifiers only.** Never use local post/attachment IDs as
  cross-environment identity.
- **No secrets, no localhost URLs in production data, no hotlinked media.**
  Credentials come from the environment (`WP_USERNAME` /
  `WP_APPLICATION_PASSWORD`).
- **Behaviour-preserving refactors carry no feature change**, and are never mixed
  with a behaviour change in one commit.
- **Never touch the Flutter/mobile repository.**

## Running things

```bash
docker compose up -d                       # start the local site (see docs/development.md)
./scripts/run-tests.sh                     # ALL tests, one aggregate exit code
./scripts/run-tests.sh --scripts           # script-contract gates only
./scripts/lint.sh                          # PHP syntax + PHPCS + PHPStan
php scripts/generate-registry-docs.php --check   # registry drift gate (0 writes)
python3 scripts/verify-permanent-gates.py # Stage L permanent invariants -> gate.json
./scripts/verify-release.sh                # whole release workflow, proven locally
```

The **permanent invariant gates** (Stage L: taxonomy policy, translation
completeness, language-scoped caching, legacy redirect precedence,
documentation drift, i18n freshness) run inside `./scripts/run-tests.sh` — they
are discovered by convention, so there is no second runner. They fail closed.
See [`docs/testing.md`](docs/testing.md) §"The permanent invariant gates" for
what each gate proves, how to read a red gate, and where `gate.json` lives.

Full command reference: [`docs/testing.md`](docs/testing.md) and
[`scripts/README.md`](scripts/README.md).

## Plugins (custom, this repo)

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

## Where to go next

| Task | Read first |
|---|---|
| Full documentation index | [`docs/README.md`](docs/README.md) |
| Architecture / content model / routes | `docs/architecture.md`, `docs/content-model.md`, `docs/routing.md` |
| Frontend, CSS, theme | `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md` |
| Build and deploy | `docs/deployment.md`, `docs/releases.md` |
| Local development and quality tooling | `docs/development.md` |
| Testing model and conventions | `docs/testing.md` |
| Per-plugin purpose, data and verification | `docs/plugins/conexao-*.md` |
| Stage/feature history | `docs/reports/`, `docs/evidence/` |

_Last verified: 2026-09-26 by Stage K — Agent Skills + Templates_
