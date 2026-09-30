# Stage 1 evidence — permanent automation plugin boundary

Curated proof for
`docs/reports/2026-09-30-stage-1-auto-translation-plugin-boundary.md`.

**No production write occurred in this stage.** No plugin was uploaded,
installed, activated or scheduled; no production option, route, cron job or
content was created or changed. Production was not contacted at all.

| File | Proof |
|---|---|
| `01-engine-integrity.json` | Shared engine SHA-256 before and after implementation, plus the Git proof that its whole tree is unmodified |
| `02-invocation-matrix.json` | Every invocation path with its outcome, failure category and whether the engine was reached |
| `03-build-release.txt` | The registry-driven build, the `release.json` manifest and the packaging proof for the new plugin |
| `04-boundary-test-run.txt` | The 132-assertion boundary suite output |
| `05-stage0-to-stage1-blockers.md` | B1 resolution status and what remains for Stage 2 |

Regenerate the machine evidence with the commands quoted in the report §12.