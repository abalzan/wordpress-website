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

There are **15 active WordPress skills**, each an executable workflow with a
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
| `wp-commemorative-event` | Plan, build, test, review and report a seasonal event on the existing engine + theme registry — reuse packages and placements, never add a mechanism. |

The index of record is [`.agents/skills/README.md`](../.agents/skills/README.md);
`tests/scripts/verify-agent-governance.py` fails if it drifts from the directory.
The retired Dart/Flutter skill set that predates these is kept for provenance
under [`.agents/legacy-flutter-skills/`](../.agents/legacy-flutter-skills/) and
is **not** an active skill set.

## Getting started

| Document | What it is |
|---|---|
| [README.md](../README.md) | Project overview, tech stack, local development setup, main functionality and important routes |
| [architecture.md](architecture.md) | System architecture overview — Docker layout, plugin architecture, theme architecture, data flow, SEO architecture |
| [development.md](development.md) | Local setup, Docker, dev-only quality toolchain, CI |
| [testing.md](testing.md) | How to run and write tests — three-layer model, `./scripts/run-tests.sh`, permanent invariant gates |

## Architecture and content model

| Document | What it is |
|---|---|
| [content-model.md](content-model.md) | Custom post types, taxonomies, meta fields, language policy, shared vs translated taxonomies |
| [routing.md](routing.md) | URLs, archives, filters, redirects, sitemap and the `/en/` layer |
| [commemorative-events.md](commemorative-events.md) | **The commemorative presentation system** — event vs package vs placement vs component, the theme-owned registry and dispatcher, route kinds, conditional assets, preview/cache boundaries |
| [commemorative-content.md](commemorative-content.md) | **The commemorative content playbooks** — one seven-field brief per package (implemented and planned), the content calendar, the manual overlap watchlist |

## Development and deployment

| Document | What it is |
|---|---|
| [../scripts/README.md](../scripts/README.md) | **The authoritative script catalogue** — every current script with safety level, arguments, default mode, target and last-verified date, plus the new-script contract |
| [deployment.md](deployment.md) | Build ZIPs, deployment to WordPress.com, verification |
| [releases.md](releases.md) | **The release contract** — artifact allowlist, `dist/release.json`, tag convention, deployment verification, rollback procedure and production constraints |

## Frontend

| Document | What it is |
|---|---|
| [frontend.md](frontend.md) | UI/design conventions, CSS and JS rules |
| [themes/conexao-br-irlanda.md](themes/conexao-br-irlanda.md) | The active theme — templates, template parts, assets, `inc/` modules |
| [themes/README.md](themes/README.md) | Theme inventory |
| [ui/](ui/) | UI change reports (mobile CTA, county filter labels, …) |

## Plugins

| Document | What it is |
|---|---|
| [plugins/README.md](plugins/README.md) | Plugin inventory, load order, lifecycle statuses |
| [plugins/conexao-*.md](plugins/) | One doc per custom plugin (purpose, data, verification) |
| [plugins/archived/](plugins/archived/) | Docs for Stage 19-removed rollout plugins (historical only) |
| [plugins/conexao-commemorative.md](plugins/conexao-commemorative.md) | **The commemorative events engine** — window rules, occurrence-year identity, priority + manual annual precedence, one-active-event resolution with reasons, signed administrator preview, targeted activation-boundary cache invalidation, the enforced Polylang single-owner rule, and the deliberate no-REST deviation |
| [plugins/conexao-translation-rollout.md](plugins/conexao-translation-rollout.md) | **The shared translation-rollout engine** — the six-step lifecycle, the stage-config and data-manifest contracts, the numeric gate, the remove/rollback contract, and how to add the next rollout |

## Translation (English layer)

| Document | What it is |
|---|---|
| [translation-manual-operator-runbook.md](translation-manual-operator-runbook.md) | **The operator runbook** — how a human or coding agent performs an occasional manual PT→EN translation run: the lifecycle, required inputs, safety controls, credential procedure, worked example, expected numbers, recovery, checklist |

## Standards, audits and engineering

| Document | What it is |
|---|---|
| [engineering-standard.md](engineering-standard.md) | The authoritative engineering standard (WP-ES) — read this before changing code |
| [audit/](audit/) | Engineering standardisation audits |
| [audit/2026-10-03-ui-ux-audit.md](audit/2026-10-03-ui-ux-audit.md) | **UI / UX audit and design direction** — the current visual system measured across 24 pages × 2 viewports, the inconsistency inventory, three design directions, the recommended direction, the Tier 1–3 backlog and the implementation map. Evaluation only; no implementation. |
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
./scripts/i18n-check.sh                     #

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

## History and reference

| Document | What it is |
|---|---|
| [reports/](reports/) | English rollout (Stages 0–9) + navigation-fix reports + feature implementation reports |
| [reports/README.md](reports/README.md) | Index of dated stage/feature reports + the no-root-reports rule |
| [reports/site/](reports/site/) | Repository-engineering stage reports (Stage B repository hygiene, Stage C static quality tooling, …) |
| [evidence/README.md](evidence/README.md) | Standing rule for machine-readable evidence: naming, a complete index of every evidence folder, the A–E retention policy, what must never be committed, and how to add new evidence |

### Selected reports

| Report | Content |
|---|---|
| [reports/english-stage43-production-deployment.md](reports/english-stage43-production-deployment.md) | Stage 4.3 production deployment record |
| [reports/2026-10-02-event-source-restoration.md](reports/2026-10-02-event-source-restoration.md) | Why the event-source registry reported 6 sources, and how the full 58 (6 legacy + 52 county) were recovered |
| [reports/2026-09-26-events-location-filters-stage-a-report.md](reports/2026-09-26-events-location-filters-stage-a-report.md) | County + City/Town filtering for the Events archive (`/eventos/`) |
| [reports/2026-09-26-events-location-filters-stage-b-report.md](reports/2026-09-26-events-location-filters-stage-b-report.md) | Events filter UI standardization onto the Lazer/Empregos widget standard |
| [reports/2026-09-27-guias-breadcrumb-filter-report.md](reports/2026-09-27-guias-breadcrumb-filter-report.md) | Guias breadcrumb category crumb → filtered archive fix |
| [reports/2026-11-09-homepage-hero-events-cta-hover-fix-report.md](reports/2026-11-09-homepage-hero-events-cta-hover-fix-report.md) | Homepage hero "Ver Eventos" CTA hover readability fix (dark mode) |
| [reports/2026-09-11-lazer-card-actions-style-report.md](reports/2026-09-11-lazer-card-actions-style-report.md) | Lazer card action buttons — shared button style |
| [reports/2026-01-09-lazer-card-map-action-report.md](reports/2026-01-09-lazer-card-map-action-report.md) | Lazer card "Ver no mapa" map action |
| [reports/2026-08-21-lazer-expansion-report.md](reports/2026-08-21-lazer-expansion-report.md) | Lazer (/lazer/) expansion — 178 new items across all 26 counties |
| [reports/2026-09-11-lazer-remove-ver-local-report.md](reports/2026-09-11-lazer-remove-ver-local-report.md) | Remove "Ver local" from Lazer cards, simplify CTA architecture |
| [reports/2026-10-03-ui-convergence-t1-plan.md](reports/2026-10-03-ui-convergence-t1-plan.md) | Plan for UI convergence Phase 1 |
| [reports/2026-10-03-ui-convergence-t1-report.md](reports/2026-10-03-ui-convergence-t1-report.md) | UI convergence Phase 1 implementation record |
| [reports/2026-10-03-ui-convergence-master-report.md](reports/2026-10-03-ui-convergence-master-report.md) | UI convergence Phase 2 implementation record |
| [reports/2026-10-10-ui-polish.md](reports/2026-10-10-ui-polish.md) | UI polish Phase 3 |
| [reports/2026-10-26-halloween-homepage-plan.md](reports/2026-10-26-halloween-homepage-plan.md) | Plan for the centered, dismissible Halloween homepage refinement |
| [reports/2026-10-26-halloween-homepage-report.md](reports/2026-10-26-halloween-homepage-report.md) | Implementation and verification record for the Halloween homepage experience |
| [reports/2026-10-27-halloween-navigation-continuity-plan.md](reports/2026-10-27-halloween-navigation-continuity-plan.md) | Plan for one-second Halloween navigation-effect continuity |
| [reports/2026-10-27-halloween-navigation-continuity-report.md](reports/2026-10-27-halloween-navigation-continuity-report.md) | Implementation and verification record for cross-navigation Halloween effects |
| [reports/2026-10-27-halloween-witch-animation-plan.md](reports/2026-10-27-halloween-witch-animation-plan.md) | Plan for the rare, three-second witch-on-a-broom navigation effect |
| [reports/2026-10-27-halloween-witch-animation-report.md](reports/2026-10-27-halloween-witch-animation-report.md) | Implementation, responsive browser checks, and test results for the witch animation |
| [reports/2026-10-27-halloween-theme-compatibility-plan.md](reports/2026-10-27-halloween-theme-compatibility-plan.md) | Plan for adapting Halloween visuals to the existing light and dark themes |
| [reports/2026-10-27-halloween-theme-compatibility-report.md](reports/2026-10-27-halloween-theme-compatibility-report.md) | Light/dark implementation, browser verification, tests, and limitations |
| [reports/2026-10-27-halloween-mobile-experience-plan.md](reports/2026-10-27-halloween-mobile-experience-plan.md) | Plan for safe, occasional mobile Halloween appearances |
| [reports/2026-10-27-halloween-mobile-experience-report.md](reports/2026-10-27-halloween-mobile-experience-report.md) | Implementation, timing, responsive browser evidence, tests, and limitations |
| [reports/2026-11-02-lazer-location-services.md](reports/2026-11-02-lazer-location-services.md) | **Lazer location services** — leisure coordinate meta + validation + provenance + admin inventory column, visitor-activated browser geolocation on `/lazer/`, client-side Haversine distances, two read-only REST endpoints, nearest-first ordering with proximity-aware infinite scroll |

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

_Last verified: 2026-10-03 — added the UI / UX audit to the index_

_Last verified: 2026-09-26 by Stage L — Permanent Invariant Gates_

_Last verified: 2026-10-10 by UI polish Phase 3 — component-heading tokens, card-CTA 44px floor, single editorial measure_

_Last verified: 2026-10-27 by Halloween navigation continuity_
_Last verified: 2026-10-27 by Halloween witch animation_
_Last verified: 2026-10-27 by Halloween theme compatibility_
_Last verified: 2026-10-27 by Halloween mobile spontaneous appearances_
_Last verified: 2026-11-03 by the Lazer coordinate research and population task_
_Last verified: 2026-11-03 by the leisure-location cleanup and consolidation task_
_Last verified: 2026-11-04 by the Lazer proximity feature (the unindexed 2026-11-02 location-services report and the 2026-11-04 proximity-verification report added to the index)_
_Last verified: 2026-11-05 by the Lazer location production audit and migration preparation (the 2026-11-05 audit report and migration plan added to the index)_
_Last verified: 2026-11-05 by the Lazer coordinate REST permission fix (the 1.6.3 permission-fix report added to the index)_
_Last verified: 2026-11-05 by Stage 7 (the 2026-11-05 production REST permission verification report added to the index; blocked at its write checkpoint, 0 production writes)_
_Last verified: 2026-11-05 by Stage 8 (the 2026-11-05 production migration dry-run report added to the index; read-only, 0 production writes, migration not applied)_
_Last verified: 2026-11-05 by Stage 9 (the 2026-11-05 production migration report added to the index; 578 authorised production writes, independently verified, PASS)_
_Last verified: 2026-11-06 by the evidence directory audit and cleanup (the evidence rule now carries a complete folder index, the A–E retention policy, the commit prohibitions and the add-evidence procedure; the 2026-11-06 audit report added to the reports index)_

_Last verified: 2026-11-06 by the Christmas homepage popup UI fix (the report and evidence directory added to the index)_
_Last verified: 2026-11-07 by the Christmas welcome top-of-screen popup fix (the report and evidence directory added to the index)_

_Last verified: 2026-11-08 by the Christmas micro-animations task (report + evidence directory added to the index)_

_Last verified: 2026-11-08 by the commemorative-event skill (skill added to the index and the count; `AGENTS.md` routes event work to it)_

_Last verified: 2026-11-09 by the agent skills review and hardening_

_Last verified: 2026-11-09 by documentation restructure — moved 8 root-level reports into `docs/reports/`, archived 5 Stage 19 removed-plugin docs into `docs/plugins/archived/`, reorganized `docs/README.md` into subject categories_
