# Evidence

Machine-readable proof that a named verification actually ran and passed, kept
tracked in Git under `docs/evidence/<YYYY-MM-DD>-<topic>/`.

## What belongs here

| Location | Status | Rule |
|---|---|---|
| `docs/evidence/<date>-<topic>/` | **Tracked** | Curated proof one stage or feature cites: a `gate.json`, a matrix, a before/after pair, a curated inventory |
| `*-work/`, `stage*-work/` | Git-ignored | Scratch trees (probe output, captures, caches). Regenerate on demand |
| `*.body`, `*.log`, `.local/`, `dist/` | Git-ignored | Generated responses, run logs, local toolchains, build output |

A folder and its report are two halves of one record: the report is the
narrative, the folder is the machine output it cites. Neither copies the other.
Name both with the same `<YYYY-MM-DD>-<topic>` prefix. Number files (`01-`,
`02-`, …) in the order the report presents them. A `*-before` / `*-after` pair
that is byte-identical is the **proof of invariance**, not a duplicate.

Never commit a credential, a `.env` file, a production database dump or a
localhost URL inside a captured response.

## Retention — keep only what a current process needs

Evidence is **not** an archive of finished work. Keep a folder only when at least
one of these holds:

- a **current operational procedure** depends on it for reproducibility
  (for example an operator runbook's dry-run/apply proof);
- it is **security or compliance proof** that must survive;
- it is the **machine-readable output of a live gate or CI job**;
- an **explicit mandate** requires the audit record.

Otherwise the evidence for a completed change belongs in Git history: delete the
folder when its report is pruned. Local and implementation evidence for a
finished stage is not retained indefinitely.

Never delete evidence referenced by active code, a current governance rule or a
still-relevant operational procedure. Removing or renaming an evidence path that
code cites is a documentation defect, not a cleanup.

## Current contents

| Folder | Why it is retained |
|---|---|
| [`2026-09-26-stage-l/`](2026-09-26-stage-l/) | Permanent invariant gate output (`gate.json`) — the artifact the CI integration job uploads |
| [`2026-10-02-stage-18-operator-runbook/`](2026-10-02-stage-18-operator-runbook/) | Dry-run/apply proof for the manual translation operator runbook, a live procedure |
| [`2026-11-20-event-cleanup-scalability/`](2026-11-20-event-cleanup-scalability/) | The event-cleanup batching design decision cited by the importer's runtime code |
| [`2026-10-04-eventbrite-api-g6/`](2026-10-04-eventbrite-api-g6/) | Required by `tests/scripts/verify-eventbrite-token-retirement.py`: the proof that the official Eventbrite API cannot enumerate a county listing, which keeps the "no Eventbrite credential" rule truthful |

## Adding evidence

1. Create `docs/evidence/<YYYY-MM-DD>-<topic>/`.
2. Write the machine output there — never at the repository root.
3. Cite the path from the report that explains it.
4. If no report will follow, the folder's own `README.md` is the record.

_Last verified: 2026-11-27 by the documentation and agent-skill rationalisation_
