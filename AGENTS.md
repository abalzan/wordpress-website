# AGENTS.md — AI agent orientation

Start here. This file gives you the shape of the system, the boundaries, the
rules that must survive every change, and how to pick a workflow. Depth lives in
[`docs/`](docs/README.md), procedures in [`.agents/skills/`](.agents/skills/). If
this file and [`docs/engineering-standard.md`](docs/engineering-standard.md)
disagree, the standard wins.

## What this repository is

Conexão BR Irlanda: a WordPress community portal for Brazilians in Ireland.
Content types: Guides, Events, Courses, Jobs, Sponsors, Lazer and Blog. A
bilingual Portuguese/English site served by Polylang. Local development runs in
Docker (`compose.yaml`); **production is WordPress.com**.

## Read before you change anything

1. This file.
2. [`docs/engineering-standard.md`](docs/engineering-standard.md) — the
   authoritative standard; §0 (change-safety) binds every change.
3. [`docs/README.md`](docs/README.md) — the index: one owner per concern.
4. The skill for your kind of work (table below), and only the canonical
   documents it names.

## Architecture in one screen

**WordPress with one active theme and a set of custom plugins** (the generated
inventory below is authoritative — never count them by hand). The split is
deliberate and enforced, not stylistic:

| Layer | Owns | Must never contain |
|---|---|---|
| **Theme** (`wp-content/themes/conexao-br-irlanda/`) | Templates, template parts, CSS, JavaScript, browser-facing behaviour, accessibility, presentation of data it is handed | Domain rules, data model registration, import/migration logic, admin workflows |
| **Plugins** (`wp-content/plugins/conexao-*`) | Domain logic and data: post types, taxonomies, meta, scheduling, eligibility, importers, migrations, translation rollout, admin capabilities, reusable services | Template markup or presentation decisions |
| **Scripts** (`scripts/`) | Operator tooling: seeding, repair, verification, build, release | Runtime behaviour that must exist in production |
| **Tests** (`tests/`, per-component `tests/`) | The executable contract | Production data |

The load order matters and is a hard dependency graph, so it is never guessed:
[`plugins.json`](plugins.json) is the registry and
[`docs/architecture.md`](docs/architecture.md) explains the boundaries. A plugin
never renders a page on the theme's behalf; a template never re-implements a
domain rule it could call.

Production has **no CLI**: no SSH, no WP-CLI, no filesystem, no database. Any
capability a maintainer must operate in production has to be an admin screen —
which is why several "just run a script" ideas are wrong here.

## Boundaries: what you may and may not touch

| In scope | Never touch |
|---|---|
| This repository only: `wp-content/`, `scripts/`, `tests/`, `docs/`, `.agents/`, `.github/`, build and deploy | **The Flutter/mobile application repository.** Never its code, tests, docs, REST clients or deployment workflows. A task that needs a mobile change is out of scope here — say so |
| Local Docker development | **Production state**, unless the task explicitly authorises a production action |
| Documentation, tests and governance | Secrets, deployment settings, CI gates (never weaken a check to make a change pass) |

## Safety rules that are not negotiable

- **PT content is immutable** in an English change. PT URLs, slugs and record
  identity never change as a side effect of EN work. An EN record is a *linked
  translation* of the same identity, never a second identity record.
- **Preserve the Polylang rules.** `conexao_category` and `conexao_tag` are
  translated; `conexao_county` and `conexao_town` are shared. Read locale only
  through `conexao_current_locale()`. Content-derived cache keys are
  language-scoped and invalidation clears every language. A B2 fallback is
  canonical → PT, noticed, and out of the sitemap.
- **No production write** unless the task explicitly authorises it — no content
  mutation, Polylang change, DB migration, upload or activation from here.
- **Content writes are dry-run first** and follow the six-step contract:
  inventory → manifest → dry-run plan → snapshot → idempotent apply → verify +
  numeric gate.
- **Portable identifiers only.** Never use a local post or attachment ID as
  cross-environment identity.
- **No secrets, no localhost URLs in production data, no hotlinked media.**
  Credentials come from the environment (`WP_USERNAME` /
  `WP_APPLICATION_PASSWORD`).
- **Behaviour-preserving refactors carry no feature change** and are never mixed
  with a behaviour change in one commit.
- **One source of truth.** A list that must stay in sync is generated from or
  checked against its owner — never hand-maintained twice.
- **Fail closed.** When a verifier cannot run, report `PASS WITH LIMITATION`,
  `BLOCKED` or `NOT TESTABLE` — never an unsupported "fully verified".

## What is authoritative

| Concern | Single source of truth |
|---|---|
| Engineering rules | [`docs/engineering-standard.md`](docs/engineering-standard.md) |
| Plugin facts: load order, class, status, production, build, mount, dependencies, version source, doc path | [`plugins.json`](plugins.json) — edit it and run `php scripts/generate-registry-docs.php --write`; never hand-edit a generated table |
| Script inventory | [`scripts/README.md`](scripts/README.md) |
| Routes, filters, redirects, the `/en/` layer, REST | [`docs/routing.md`](docs/routing.md) |
| Content types, taxonomies, meta, language policy | [`docs/content-model.md`](docs/content-model.md) |
| Release contract, manifest, verification, rollback | [`docs/releases.md`](docs/releases.md) |
| Test model and executable gates | [`docs/testing.md`](docs/testing.md) + the suites themselves |
| Documentation index | [`docs/README.md`](docs/README.md) |
| Theme internals | [`docs/themes/conexao-br-irlanda.md`](docs/themes/conexao-br-irlanda.md) |

When two documents disagree, the owner above wins and the other is the defect.

## Choose a skill

Each skill in [`.agents/skills/`](.agents/skills/) is an executable workflow —
purpose, use and non-use, required reading, authoritative sources, preconditions,
steps, guardrails, verification, failure handling, evidence, definition of done.

| Skill | Use when | Canonical docs |
|---|---|---|
| [`wp-repository`](.agents/skills/wp-repository/SKILL.md) | Orienting, planning multi-file work, holding scope, reviewing a diff before merge — **the default entry point for non-trivial work** | `AGENTS.md`, standard §0/§12/§14 |
| [`wp-add-content-type`](.agents/skills/wp-add-content-type/SKILL.md) | A new or changed post type, taxonomy, meta field, archive or single route | `docs/content-model.md`, `docs/routing.md` |
| [`wp-add-admin-screen`](.agents/skills/wp-add-admin-screen/SKILL.md) | A maintainer capability that must be operable through wp-admin | standard §4.2, `docs/releases.md` |
| [`wp-content-change`](.agents/skills/wp-content-change/SKILL.md) | Any content write that is **not** an English rollout: importer, migration, seed, repair | standard §5.2, `docs/content-model.md` |
| [`wp-translation-rollout`](.agents/skills/wp-translation-rollout/SKILL.md) | English coverage for a content type, or an occasional manual PT→EN run | `docs/content-model.md`, `docs/routing.md`, `docs/translation-manual-operator-runbook.md` |
| [`wp-testing`](.agents/skills/wp-testing/SKILL.md) | Running, diagnosing or comparing the test contract; adding a layer, fixture or permanent gate | `docs/testing.md`, standard §8 |
| [`wp-write-in-process-test`](.agents/skills/wp-write-in-process-test/SKILL.md) | Authoring an in-process PHP suite | `docs/testing.md`, `tests/bootstrap.php` |
| [`wp-http-acceptance-matrix`](.agents/skills/wp-http-acceptance-matrix/SKILL.md) | Anything request-visible: route, archive, filter, redirect, canonical, hreflang, sitemap | `docs/testing.md`, `docs/routing.md` |
| [`wp-security-review`](.agents/skills/wp-security-review/SKILL.md) | A request, a database write, a REST/AJAX endpoint, an admin write, or data moving between environments | standard §2.1/§4.2/§7, `docs/routing.md` |
| [`wp-frontend-perf`](.agents/skills/wp-frontend-perf/SKILL.md) | Templates, queries, transient caching, invalidation, assets, LCP | `docs/frontend.md`, standard §3 |
| [`wp-plugin-registry`](.agents/skills/wp-plugin-registry/SKILL.md) | Adding, removing, re-classifying or re-ordering a plugin; build, mount or dependency facts | `plugins.json`, `docs/deployment.md` |
| [`wp-release-deploy`](.agents/skills/wp-release-deploy/SKILL.md) | Building artifacts, recording a release, verifying a deployment, rolling one back | `docs/releases.md`, `plugins.json` |
| [`wp-production-operations`](.agents/skills/wp-production-operations/SKILL.md) | Auditing or operating the live WordPress.com site: plugin/theme state, admin-only capabilities, safe updates | `docs/releases.md`, `docs/deployment.md` |
| [`wp-update-docs`](.agents/skills/wp-update-docs/SKILL.md) | A change that alters what a maintainer or agent must know | `docs/README.md`, standard §9 |
| [`wp-commemorative-event`](.agents/skills/wp-commemorative-event/SKILL.md) | A seasonal event: new record, package/placement/asset change, landing page, preview/modal/dispatch fix | `docs/commemorative-events.md`, `docs/plugins/conexao-commemorative.md` |

## Workflow: plan → build → verify → report

`wp-repository` owns this loop in full. In short:

1. **Inspect before editing.** `git status`, `git log -1`, and read the code you
   are about to change. Find the existing helper, engine or shared service
   **before** writing a new one; copying a plugin, script or engine requires a
   written justification in the report.
2. **Scope.** Name the smallest correct change, the files that must change and
   the files that must not.
3. **Plan.** For multi-file work, or anything touching content, routes or
   English/Polylang, copy [`docs/templates/plan.md`](docs/templates/plan.md) to
   `docs/plans/<date>-<topic>.md` and fill in that copy — never the template.
4. **Build incrementally.** One concern per commit. No behaviour change hidden in
   a refactor.
5. **Verify with real numbers.** Targeted suite first, then the full command.
   Paste the actual output; a claim without output is not verification.
6. **Review your own diff** before reporting: scope, accidental changes,
   generated regions, escaping, capability checks.
7. **Report.** Copy [`docs/templates/report.md`](docs/templates/report.md) to
   `docs/reports/<date>-<topic>.md`. Machine proof goes under
   `docs/evidence/<date>-<stage>/`. Never put a report or evidence file at the
   repository root.

Reuse, do not re-invent: `scripts/lib/bootstrap.php`, `scripts/lib/rest.py`,
`scripts/lib/zip-build.sh`, `scripts/lib/release.py`, `scripts/lib/plan.py`, the
`conexao-translation-rollout` engine, `tests/bootstrap.php`,
`tests/lib/assertions.php` and `scripts/generate-registry-docs.php` are the shared
engines. Copying one requires a written justification.

**Stop instead of guessing** when a production or destructive action lacks
written authorisation, when a verifier cannot run, or when the request conflicts
with a rule above — report `BLOCKED` and say what you need.

## Commands

Inspect the running stack first (`docker compose ps`) and reuse it. Do not start,
recreate or rebuild containers, reset volumes, seed data or mint an admin session
as a side effect — make those deliberate and justified.

```bash
./scripts/run-tests.sh                     # every layer, one aggregate exit code
./scripts/run-tests.sh --scripts           # static script-contract gates (no PHP/Docker/DB)
./scripts/run-tests.sh --php               # in-process PHP layer (isolated DB required)
./scripts/run-tests.sh --acceptance        # HTTP acceptance layer (local site required)
./scripts/run-tests.sh --only <component>  # one theme/plugin slug or layer
./scripts/run-tests.sh --list              # discovered suites and components
./scripts/lint.sh                          # PHP syntax + PHPCS baseline + PHPStan
php scripts/generate-registry-docs.php --check   # registry drift gate (zero writes)
python3 scripts/verify-permanent-gates.py  # permanent invariants -> gate.json
./scripts/verify-release.sh                # whole release workflow, proven locally
```

Suites are discovered by convention (`wp-content/<themes|plugins>/<slug>/tests/test-*.php`,
`tests/scripts/verify-*.py`, `tests/acceptance/verify-*-http.py`) — no
hand-maintained list. The permanent invariant gates run inside
`./scripts/run-tests.sh`, fail closed. A WordPress-dependent layer needs
`CONEXAO_TEST_DB_NAME` pointing at a prepared isolated database; the acceptance
runner refuses a production base URL. Full reference: [`docs/testing.md`](docs/testing.md),
[`scripts/README.md`](scripts/README.md).

## Commemorative events

The most foot-gun-prone area. Read
[`wp-commemorative-event`](.agents/skills/wp-commemorative-event/SKILL.md) and
[`docs/commemorative-events.md`](docs/commemorative-events.md) before touching it.

The `conexao-commemorative` plugin owns scheduling and eligibility; the theme owns
the registry, dispatcher, placements and assets. **Never add** an engine,
resolver, scheduler, plugin, route, CPT, meta key, endpoint, activation path or
timer for a visual event — reuse the registry, packages, placements, templates
and helpers. The CPT stays private (routes, archives, REST, sitemap) and shared
`_ce_*` config lives on the canonical PT record only. Preview is signed,
capability-gated, nonce-verified and persists nothing. Modals are real
`showModal()` dialogs, never `open`-attributed popups. Zero commemorative bytes
ship on ordinary routes with no active event. Local green tests never prove
release or production readiness.

## Event archives

The `/eventos/` City filter was **intentionally removed** from the interface — do
not reintroduce it; `?cidade=` stays handled for backward compatibility only
([`docs/routing.md`](docs/routing.md)).

## Custom plugins (generated from plugins.json)

<!-- BEGIN GENERATED PLUGIN REGISTRY: AGENTS.md plugin inventory -->
All custom plugins live in `wp-content/plugins/`. **Load order matters.**

This table is generated from [`plugins.json`](plugins.json) - the single
authoritative registry. Edit the registry and run
`php scripts/generate-registry-docs.php --write`; never hand-edit this table.

| # | Plugin | Class | Status | Production | Build | Compose mount | Documentation |
|---|--------|-------|--------|------------|-------|---------------|---------------|
| 1 | `conexao-data-model` | platform | active | yes | yes | yes | `docs/plugins/conexao-data-model.md` |
| 2 | `conexao-content` | platform | active | yes | yes | yes | `docs/plugins/conexao-content.md` |
| 3 | `conexao-admin-ux` | platform | active | yes | yes | yes | `docs/plugins/conexao-admin-ux.md` |
| 4 | `conexao-event-runtime` | platform | active | yes | yes | yes | `docs/plugins/conexao-event-runtime.md` |
| 5 | `conexao-commemorative` | platform | active | yes | yes | yes | `docs/plugins/conexao-commemorative.md` |
| 6 | `conexao-event-importer` | tooling | active | no | yes | yes | `docs/plugins/conexao-event-importer.md` |
| 7 | `conexao-leisure-migration` | tooling | active | no | yes | yes | `docs/plugins/conexao-leisure-migration.md` |
| 8 | `conexao-sponsor-migration` | tooling | active | no | yes | yes | `docs/plugins/conexao-sponsor-migration.md` |
| 9 | `conexao-translation-rollout` | platform | active | yes | yes | yes | `docs/plugins/conexao-translation-rollout.md` |
| 10 | `conexao-en-translation` | tooling | active | no | no | yes | `docs/plugins/conexao-en-translation.md` |
| 11 | `conexao-translation-automation` | platform | active | yes | yes | yes | `docs/plugins/conexao-translation-automation.md` |

**Production steady state** (platform, `production: true`) - activate in this order: conexao-data-model -> conexao-content -> conexao-admin-ux -> conexao-event-runtime -> conexao-commemorative -> conexao-translation-rollout -> conexao-translation-automation.

**Local-only tooling** (never production): conexao-event-importer, conexao-leisure-migration, conexao-sponsor-migration, conexao-en-translation.

**Retired rollout plugins** (historical tooling, *activate → apply → remove*; not a production dependency and not in any release ZIP): none.
<!-- END GENERATED PLUGIN REGISTRY: AGENTS.md plugin inventory -->

## CI

[`.github/workflows/ci.yml`](.github/workflows/ci.yml) is the single CI entrypoint
and runs three **blocking** jobs on every push and pull request: **static
quality**, **release integrity** and **integration**. The integration job builds
the deterministic synthetic site from the committed fixtures in an ephemeral
Docker WordPress and runs `./scripts/run-tests.sh`. A local green run does not
prove CI green; a CI gate is never disabled to make a change pass.

_Last verified: 2026-11-27 by the documentation and agent-skill rationalisation (the entry point was rewritten; architecture, boundaries, safety rules and skill routing re-verified against the repository)_
