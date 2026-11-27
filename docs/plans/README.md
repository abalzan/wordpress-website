# Plans

A plan is the **pre-change** half of one change: scope, non-goals, expected and
forbidden file changes, content/route/Polylang/security/performance/production
impact, test plan, rollback, risks and completion criteria.

## When a plan is required

Copy [`../templates/plan.md`](../templates/plan.md) to
`docs/plans/<date>-<topic>.md` and fill in **that copy** before starting when the
work is:

- multi-file, or
- touches content, routes or English/Polylang, or
- is destructive or production-adjacent.

Trivial one-file work does not need a plan. Never edit the template itself.

## Retention

A plan is an in-flight artefact. **Once the change is merged, the plan is
pruned** — Git history is its provenance. Keep a plan only when it records a
decision that is still live and is not captured anywhere else; in that case move
the decision into the canonical document that owns it and prune the plan.

Do not accumulate a plan archive here, and do not make an agent read past plans
as preparation. The owning rules are
[`../engineering-standard.md`](../engineering-standard.md) §9 and
[`../../.agents/skills/wp-repository/SKILL.md`](../../.agents/skills/wp-repository/SKILL.md).

_Last verified: 2026-11-27 by the documentation and agent-skill rationalisation_
