# Agent skills

The **operational** half of this repository's agent contract. `docs/` says *what
is true*; a skill says *how to do a kind of work*. An agent picks a skill, follows
its workflow, and consults the canonical documents the skill names.

## How to use this directory

1. Enter through [`AGENTS.md`](../../AGENTS.md) — scope, safety rules, and the
   authoritative-source map.
2. Find the workflow below, or let `AGENTS.md` route you.
3. Read the skill's **Required reading** and **Authoritative sources** — those,
   not a historical report, define the rules you must satisfy.
4. Execute the **Steps**, then satisfy **Verification** and
   **Definition of done**.
5. Record the outcome with [`docs/templates/report.md`](../../docs/templates/report.md)
   and machine evidence under `docs/evidence/<date>-<stage>/`.

## Skill index

| Skill | Use when | Primary canonical docs |
|---|---|---|
| [`wp-repository`](wp-repository/SKILL.md) | orienting in the repository, planning multi-file work, controlling scope, or reviewing a change before merge | `AGENTS.md`, `docs/engineering-standard.md` §0/§12/§14, `docs/templates/plan.md` |
| [`wp-add-content-type`](wp-add-content-type/SKILL.md) | a new or changed post type, taxonomy, meta field, archive or single route | `docs/content-model.md`, `docs/routing.md` |
| [`wp-add-admin-screen`](wp-add-admin-screen/SKILL.md) | a maintainer capability that must be operable through wp-admin | `docs/engineering-standard.md` §4.2, `docs/releases.md` |
| [`wp-content-change`](wp-content-change/SKILL.md) | any write of content, meta or terms that is **not** an English rollout — importers, migrations, seeds, repairs | `docs/engineering-standard.md` §5.2, `docs/content-model.md` |
| [`wp-translation-rollout`](wp-translation-rollout/SKILL.md) | English coverage for a content type, or extending the `/en/` layer | `docs/content-model.md`, `docs/routing.md` |
| [`wp-testing`](wp-testing/SKILL.md) | running, diagnosing or comparing the test contract; bootstrapping Docker; adding a layer, fixture or permanent gate | `docs/testing.md`, `docs/engineering-standard.md` §8 |
| [`wp-write-in-process-test`](wp-write-in-process-test/SKILL.md) | authoring an in-process PHP suite for a component | `docs/testing.md`, `tests/bootstrap.php` |
| [`wp-http-acceptance-matrix`](wp-http-acceptance-matrix/SKILL.md) | anything request-visible: a route, archive, filter, redirect, canonical, hreflang or sitemap row | `docs/testing.md`, `docs/routing.md` |
| [`wp-security-review`](wp-security-review/SKILL.md) | reading a request, writing the database, adding a REST/AJAX endpoint, admin writes, or moving data between environments | `docs/engineering-standard.md` §2.1/§4.2/§7, `docs/routing.md` |
| [`wp-frontend-perf`](wp-frontend-perf/SKILL.md) | templates, queries, transient caching, invalidation, assets or LCP | `docs/frontend.md`, `docs/engineering-standard.md` §3 |
| [`wp-plugin-registry`](wp-plugin-registry/SKILL.md) | adding, removing, re-classifying or re-ordering a plugin; changing build, mount, production or dependency facts | `plugins.json`, `docs/deployment.md` |
| [`wp-release-deploy`](wp-release-deploy/SKILL.md) | building artifacts, recording a release, verifying a deployment, rolling one back | `docs/releases.md`, `plugins.json` |
| [`wp-production-operations`](wp-production-operations/SKILL.md) | auditing or operating the live WordPress.com site: plugin/theme state, admin-only capabilities, safe updates | `docs/releases.md`, `docs/deployment.md` |
| [`wp-update-docs`](wp-update-docs/SKILL.md) | any change that alters what a maintainer or agent must know, or that must not rot | `docs/README.md`, `docs/engineering-standard.md` §9 |

## Ownership rules

- **A procedure has exactly one operational home.** If a canonical document needs
  to mention a procedure, it links to the skill — it does not restate the steps.
- **A skill never becomes a second source of truth.** Versions, load order, build
  and mount facts, routes, taxonomies and the script inventory are read from
  `plugins.json`, `docs/routing.md`, `docs/content-model.md` and
  `scripts/README.md`, never copied into a skill.
- **History is not instruction.** `docs/reports/` and `docs/evidence/` are an audit
  record. A number in a report is a historical measurement, not a current fact,
  unless the report is still the authoritative source for it.
- **Retired material stays retired.** `.agents/legacy-flutter-skills/` is
  provenance for a retired Dart/Flutter skill set. It is **not** active, is never
  loaded, and the Flutter/mobile repository is permanently out of scope.

## Format and governance

Every active skill is an executable workflow with these sections, in this order:

`Purpose` · `When to use` · `When not to use` · `Required reading` ·
`Authoritative sources` · `Preconditions` · `Steps` · `Guardrails` ·
`Verification` · `Failure handling` · `Evidence and reporting` ·
`Definition of done`

The six sections `When to use`, `Required reading`, `Steps`, `Guardrails`,
`Verification` and `Definition of done` are required by
`docs/engineering-standard.md` §13.1 and are enforced, in order, by
`tests/scripts/verify-agent-governance.py` — together with the index-drift,
dangling-reference and single-source-of-truth checks. Run it directly with:

```bash
python3 tests/scripts/verify-agent-governance.py
```

_Last verified: 2026-09-30 by the agent skills / documentation migration_
