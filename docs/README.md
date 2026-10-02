# Documentation index

The **human** documentation index for this repository: what each document is for
and which one is authoritative. It is navigation only — the standards live in the
linked documents themselves. New docs belong under `docs/` and must be added here
in the same change.

This is a documentation index, not a workflow manual. If you are an agent, start
at [`AGENTS.md`](../AGENTS.md) and select a skill.

## How this repository splits its knowledge

| | What it holds | Where |
|---|---|---|
| **Reusable agent procedure** | *how to do a kind of work* — steps, guardrails, verification, failure handling | [`.agents/skills/`](../.agents/skills/) — index in [`skills/README.md`](../.agents/skills/README.md) |
| **Canonical fact** | *what is true about this project* — architecture, content model, routes, policy, contracts | `docs/` (this index) |
| **History** | *what happened and what was measured* | [`reports/`](reports/), [`evidence/`](evidence/), [`audit/`](audit/) |

**A procedure has one operational home.** A canonical document that needs to
mention a procedure links to the skill rather than restating the steps, and a
historical report is never the source of a current instruction.

## Agent workflows live in `.agents/skills/`

There are **14 active WordPress skills**, each an executable workflow with a
standard format (Purpose → When to use → When not to use → Required reading →
Authoritative sources → Preconditions → Steps → Guardrails → Verification →
Failure handling → Evidence and reporting → Definition of done). One sentence
each; the full workflow is in the skill.

| Skill | One sentence |
|---|---|
| `wp-repository` | Orient in the repository, plan the work, hold the scope, prefer a shared engine, and review before merge. |
| `wp-add-content-type` | Add or change a post type, taxonomy, meta field, archive or single route. |
| `wp-add-admin-screen` | Make a maintainer capability operable through wp-admin — required because production has no CLI. |
| `wp-content-change` | Write content, meta or terms safely via the six-step contract (dry-run, snapshot, apply, verify, rollback). |
| `wp-translation-rollout` | Add English coverage for a content type on the shared rollout engine, with PT provably unchanged. |
| `wp-testing` | Run, diagnose and compare the test contract; add layers, fixtures and permanent fail-closed gates. |
| `wp-write-in-process-test` | Author an in-process PHP suite against a real WordPress, discovered by convention. |
| `wp-http-acceptance-matrix` | Assert what a real request returns — status, redirect, canonical, hreflang, sitemap, filters. |
| `wp-security-review` | Prove capability, nonce, sanitisation, escaping and prepared statements before shipping. |
| `wp-frontend-perf` | Keep cache keys language-scoped, invalidation complete, and any improvement measurable. |
| `wp-plugin-registry` | Keep `plugins.json` the only plugin registry and regenerate every derived region. |
| `wp-release-deploy` | Build, record, verify and deploy a release deterministically, with a working rollback. |
| `wp-production-operations` | Audit and safely update the live WordPress.com site; update in place, never deactivate to update. |
| `wp-update-docs` | Keep documentation truthful: one owner per fact, one home per procedure, no drift. |

The index of record is [`.agents/skills/README.md`](../.agents/skills/README.md);
`tests/scripts/verify-agent-governance.py` fails if it drifts from the directory.
The retired Dart/Flutter skill set that predates these is kept for provenance
under [`.agents/legacy-flutter-skills/`](../.agents/legacy-flutter-skills/) and
is **not** an active skill set.

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
| [translation-manual-operator-runbook.md](translation-manual-operator-runbook.md) | **The operator runbook** — how a human or coding agent performs an occasional manual PT→EN translation run: the lifecycle, the required inputs, the retained safety controls, the provider-credential procedure, a worked example, the expected result numbers, local vs production, recovery, and the final checklist |

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

| Document | What it is |
|---|---|
| [reports/](reports/) | English rollout (Stages 0–9) + navigation-fix reports |
| [reports/english-stage43-production-deployment.md](reports/english-stage43-production-deployment.md) | Stage 4.3 production deployment record |
| [events/](events/) | Events location-filters and copy reports |
| [importers/](importers/) | Event/Lazer/IVVCC/Mondello import pipeline reports and plans |
| [research/](research/) | Topic research notes (e.g. recruitment agencies, employment permits) |
| Root-level `*-report.md` files in `docs/` | Older single-stage feature reports (breadcrumb filter, Lazer cards, homepage hero, …) kept in place for citation stability |

## Testing

One command runs every maintained test — in-process PHP, the script-contract
gate and HTTP acceptance:

```bash
./scripts/run-tests.sh            # all layers
./scripts/run-tests.sh --scripts   # script-contract gate only
```

The **model** — layers, suite classification, permanent fail-closed gates, the
baseline/pre-existing-failure policy, the shared bootstrap and assertion library,
fixtures, the acceptance base URL and the CI contract — is
**[testing.md](testing.md)**; the normative policy is
[engineering-standard.md §8](engineering-standard.md). The **procedure** — what
to run, in what order, how to classify a failure and how to compare regressions —
is [`wp-testing`](../.agents/skills/wp-testing/SKILL.md).

## Templates

| Template | Use it for |
|---|---|
| [templates/plan.md](templates/plan.md) | Before multi-file work, and before any content, route or English/Polylang change |
| [templates/report.md](templates/report.md) | After the work, to close a stage with real verification numbers and stated limitations |

_Last verified: 2026-09-30 by the agent skills / documentation migration_

_Last verified: 2026-09-26 by Stage L — Permanent Invariant Gates_
