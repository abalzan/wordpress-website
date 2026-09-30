# The WordPress agent skills

This directory is the repository's **operational layer**: one directory per
reusable agent workflow, each an executable `SKILL.md` (purpose → when to
use/not use → required reading → authoritative sources → preconditions →
steps → guardrails → verification → failure handling → evidence → definition
of done). Skills answer *"how should an agent perform this kind of work?"* —
durable project facts, specifications and policy live in
[`docs/`](../../docs/README.md), and the historical record lives in
[`docs/reports/`](../../docs/reports/README.md) and
[`docs/evidence/`](../../docs/evidence/README.md).

This index is the single list of active skills. The CI gate
`tests/scripts/verify-agent-governance.py` checks it against the actual skill
directories and enforces the format, the path references and the scope rules;
adding, renaming or retiring a skill means updating this index in the same
change.

| Skill | Use when | Primary canonical docs |
|---|---|---|
| `wp-add-content-type` | a new or changed post type, taxonomy, meta field, archive or route | `docs/content-model.md`, `docs/routing.md`, `plugins.json` |
| `wp-add-admin-screen` | a maintainer capability operated through wp-admin | `docs/engineering-standard.md` §4.2, `docs/plugins/conexao-admin-ux.md` |
| `wp-write-in-process-test` | writing an in-process PHP test suite | `docs/testing.md`, `tests/bootstrap.php`, `tests/lib/assertions.php` |
| `wp-http-acceptance-matrix` | an HTTP acceptance row or matrix for request-visible behaviour | `docs/testing.md`, `docs/routing.md` |
| `wp-run-tests` | running the full test contract, diagnosing failures, comparing regressions | `docs/testing.md`, `tests/baseline/permanent-gates.json` |
| `wp-content-change` | a content-writing change (records, terms, menus, meta, migrations) that is not EN coverage or schema | `docs/engineering-standard.md` §5.2, `scripts/README.md` |
| `wp-translation-rollout` | English coverage for a content type, or re-running/extending the `/en/` layer | `docs/content-model.md`, `docs/routing.md`, `docs/plugins/conexao-translation-rollout.md` |
| `wp-add-strings` | adding or changing user-facing PHP strings (text domains, catalogue regeneration) | `docs/engineering-standard.md` §9.3, `scripts/README.md` |
| `wp-security-review` | reviewing capabilities, nonces, escaping, SQL, secrets, REST boundaries | `docs/engineering-standard.md` §2.1/§4.2/§7 |
| `wp-frontend-perf` | templates, queries, caching, assets, images (measurable performance discipline) | `docs/frontend.md`, `docs/themes/conexao-br-irlanda.md` |
| `wp-plugin-registry` | adding/re-classifying a plugin, or changing build/production/mount facts | `plugins.json`, `docs/plugins/README.md` |
| `wp-release-deploy` | building release artifacts, the release record, deployment verification, rollback | `docs/releases.md`, `docs/deployment.md`, `plugins.json` |
| `wp-production-operations` | read-only production audits/verification and explicitly authorised production actions | `docs/releases.md`, `docs/deployment.md`, `plugins.json` |
| `wp-update-docs` | updating documentation, indexes, reports and evidence without drift | `docs/README.md`, `scripts/README.md`, `docs/engineering-standard.md` §9 |

Selection shortcut — match the task, then the skill, then the docs:

- New post type → `wp-add-content-type` → content model + routing.
- English translation work → `wp-translation-rollout` → content model +
  routing + testing.
- Any other content write → `wp-content-change` → engineering standard §5.2.
- Tests (write/run) → `wp-write-in-process-test` /
  `wp-http-acceptance-matrix` / `wp-run-tests` → testing.
- Ship it → `wp-release-deploy`; operate/verify production →
  `wp-production-operations` → releases + deployment.
- Strings → `wp-add-strings`; performance → `wp-frontend-perf`; security →
  `wp-security-review`; plugins → `wp-plugin-registry`; docs →
  `wp-update-docs`.

The retired Dart/Flutter skill set that predated these is kept for provenance
under `.agents/legacy-flutter-skills/` and is **not** an active skill set; the
WordPress-only scope rule is absolute — never touch the Flutter/mobile
repository from this repository's tasks.

_Last verified: 2026-09-30 by the agent-skills documentation migration_
