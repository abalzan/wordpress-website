# Documentation index

One index. It answers three questions: **where do I start**, **which document
owns this concern**, and **which specialist reference do I need**.

## Where to start

| You are… | Start at |
|---|---|
| A coding agent, anywhere | [`AGENTS.md`](../AGENTS.md) — orientation, boundaries, safety rules, skill selection, and the plan → build → verify → report loop |
| Picking a workflow | [`.agents/skills/README.md`](../.agents/skills/README.md) — 15 executable workflows; each names the canonical documents it needs |
| New to the project (human) | [`README.md`](../README.md) — stack, local development, build, deploy |
| Looking for a rule you must satisfy | [`engineering-standard.md`](engineering-standard.md) — the authoritative engineering standard (§0 change-safety first) |

An agent should not need to read this whole directory. Pick the skill for the
task, then read only the documents it names.

## How knowledge is split

| Layer | Holds | Lives in |
|---|---|---|
| **Procedure** | *how to do a kind of work* — steps, guardrails, verification, failure handling | [`.agents/skills/`](../.agents/skills/) |
| **Canonical fact** | *what is true* — architecture, content model, routes, policy, contracts | `docs/` (this index) |
| **Point-in-time record** | *what happened* — a plan for the change in flight, a completion report, machine proof | [`plans/`](plans/README.md), [`reports/`](reports/README.md), [`evidence/`](evidence/README.md) |

A procedure has exactly **one** operational home: a skill. A canonical document
links to the skill instead of restating its steps. A historical record is never
the source of a current instruction.

## Canonical references — one owner per concern

| Concern | Owner |
|---|---|
| Engineering requirements, coding/theme/plugin/testing/documentation standards, definition of done | [`engineering-standard.md`](engineering-standard.md) |
| Architecture: Docker layout, plugin and theme boundaries, data flow, SEO | [`architecture.md`](architecture.md) |
| Custom post types, taxonomies, meta fields, language policy, Empregos editorial rules | [`content-model.md`](content-model.md) |
| URLs, archives, filters, redirects, sitemap, the `/en/` layer, REST | [`routing.md`](routing.md) |
| Design tokens, CSS/JS conventions, cache-key scoping, accessibility, dark mode | [`frontend.md`](frontend.md) |
| Theme internals: templates, template parts, `inc/` modules, tests | [`themes/conexao-br-irlanda.md`](themes/conexao-br-irlanda.md), inventory in [`themes/README.md`](themes/README.md) |
| Test model, layers, discovery, isolation, fixtures, permanent gates, CI | [`testing.md`](testing.md) |
| Release contract, artifacts, `dist/release.json`, tags, versioning, rollback | [`releases.md`](releases.md) |
| Build and deploy to WordPress.com, production constraints | [`deployment.md`](deployment.md) |
| Local setup, Docker, quality tooling, common commands | [`development.md`](development.md) |
| Script catalogue: purpose, safety level, flags, default mode | [`../scripts/README.md`](../scripts/README.md) |
| Plugin facts: load order, class, status, production, build, mount, dependencies | [`../plugins.json`](../plugins.json) — never a second list |
| At-a-glance inventory of plugins, CPTs, taxonomies, routes, cron, shortcodes | [`project-inventory.md`](project-inventory.md) (derived — not an index) |

## Specialist references

| Reference | Use it for |
|---|---|
| [`plugins/README.md`](plugins/README.md) | Plugin lifecycle, load order, third-party plugins, build |
| [`plugins/conexao-*.md`](plugins/) | One document per custom plugin: responsibility, data, hooks, admin surface, verification |
| [`commemorative-events.md`](commemorative-events.md) | The seasonal presentation system: event vs package vs placement, the theme registry and dispatcher, route kinds, conditional assets, preview/cache boundaries |
| [`commemorative-content.md`](commemorative-content.md) | The content playbooks: one seven-field brief per package, the content calendar, the overlap watchlist |
| [`translation-manual-operator-runbook.md`](translation-manual-operator-runbook.md) | An occasional manual PT→EN run: lifecycle, inputs, safety controls, credentials, expected numbers, recovery, checklist |
| [`importers/ivvcc-importer.md`](importers/ivvcc-importer.md), [`importers/mondello-park-importer.md`](importers/mondello-park-importer.md), [`importers/motorsport-ireland.md`](importers/motorsport-ireland.md) | How those three event sources are discovered and parsed |

## Templates and record directories

| Path | Use it for |
|---|---|
| [`templates/plan.md`](templates/plan.md) | Before multi-file work, and before any content, route or English/Polylang change → copy to `docs/plans/` |
| [`templates/report.md`](templates/report.md) | After the work → copy to `docs/reports/`; four permitted statuses, real numbers, stated limitations |
| [`plans/README.md`](plans/README.md) | The plan retention rule |
| [`reports/README.md`](reports/README.md) | The report retention rule |
| [`evidence/README.md`](evidence/README.md) | Machine proof: naming, what may be committed, retention |

## The plugin registry (single source of truth)

[`plugins.json`](../plugins.json) owns load order, dependencies, lifecycle class
and status, production activation order, release build inclusion and Compose
mounts. Every derived list is generated by
[`scripts/generate-registry-docs.php`](../scripts/generate-registry-docs.php)
into a marked region: the AGENTS.md inventory, the README summary, the plugin
inventory and load-order tables, per-plugin lifecycle metadata, the deployment
activation order, the release build list and the Compose mounts.

```bash
php scripts/generate-registry-docs.php --check   # drift gate, zero writes (blocking in CI)
php scripts/generate-registry-docs.php --write   # regenerate the marked regions
```

Never hand-edit a generated block. To add, remove or re-classify a plugin: edit
`plugins.json`, add its `docs/plugins/<slug>.md`, then run `--write`.

## Permanent invariant gates

Six standing, fail-closed gates turn the standard's safety rules into CI
checks. They run inside `./scripts/run-tests.sh` and are discovered by
convention — no second runner, no hand-maintained list. The domains, their
enforcement and the failure policy are owned by
[`testing.md`](testing.md) §"The permanent invariant gates"; the aggregate
`python3 scripts/verify-permanent-gates.py` writes one `gate.json` built from
real execution output.

_Last verified: 2026-11-27 by the documentation and agent-skill rationalisation_
