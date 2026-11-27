# Reports

A report is the **completion** half of one change: what was done, the real
verification numbers, the PT-integrity result, the gate result, the rollback and
the limitations.

## When a report is required

Copy [`../templates/report.md`](../templates/report.md) to
`docs/reports/<date>-<topic>.md` when the change is:

- multi-file, or
- a content write (importer, migration, seed, repair), or
- anything touching routes, English/Polylang, release or deployment.

Documentation-only or single-file fixes need a report only when they change a
rule a maintainer must know. Use one of the four permitted statuses — `PASS`,
`PASS WITH LIMITATION`, `BLOCKED`, `NOT TESTABLE` — and never claim "fully
verified" when a verifier could not run.

## Retention

**This repository does not keep a report archive.** A report exists to close the
change it belongs to; after merge, Git history is its provenance. Before pruning
a report, extract anything enduring into the canonical document that owns it:

| Finding | New home |
|---|---|
| A current behaviour, contract or invariant | `docs/architecture.md`, `content-model.md`, `routing.md`, `frontend.md`, `testing.md`, `releases.md`, or the plugin/theme doc |
| A repeatable procedure | the matching `.agents/skills/<name>/SKILL.md` |
| Reproducible machine output a current process depends on | [`../evidence/README.md`](../evidence/README.md) |

A report is never the source of a current instruction, and a historical number is
never a current fact. Do not add a report file at the repository root.

**One deliberate exception:**
[`2026-10-04-eventbrite-api-feasibility.md`](2026-10-04-eventbrite-api-feasibility.md)
is retained because `tests/scripts/verify-eventbrite-token-retirement.py` requires
it. That gate exists so the clean "no Eventbrite credential" grep cannot be
achieved by deleting the record that justifies it. Evidence referenced by a
current governance rule is never pruned.

_Last verified: 2026-11-27 by the documentation and agent-skill rationalisation_
