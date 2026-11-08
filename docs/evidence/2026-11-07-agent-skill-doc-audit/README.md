# Evidence — comprehensive repository agent, skills and documentation audit (2026-11-07)

Narrative: [`docs/reports/2026-11-07-agent-skill-doc-audit.md`](../../reports/2026-11-07-agent-skill-doc-audit.md).
Plan: [`docs/plans/2026-11-07-agent-skill-doc-audit.md`](../../plans/2026-11-07-agent-skill-doc-audit.md).
Every file below is read-only capture; nothing here was produced against
production.

| File | What it proves |
|---|---|
| `01-git-preflight-baseline.txt` | Branch `commemorative-events`, HEAD `438c2ebb…`, clean worktree before edits, and the 9 preserved stashes (`stash@{0}`–`stash@{8}`) |
| `02-governance-gate-baseline.txt` | `verify-agent-governance.py` before any change: **357 passed, 0 failed** (exit 0) |
| `03-registry-check-baseline.txt` | `generate-registry-docs.php --check` before any change: 11 plugins, 20 regions current |
| `04-documentation-drift-baseline.txt` | `verify-documentation-drift.py` before any change: 14 passed, 0 failed, gate `pass` |
| `05-full-suite-baseline-summary.txt` | The full-suite run **before** edits: per-layer totals (137/21/5), the named failing-suite list, and the script/acceptance failure detail — the "before" column of the regression comparison |
| `06-final-governance-gate.txt` | Same gate **after** the change: 357 passed, 0 failed (identical) |
| `07-final-registry-check.txt` | Same drift gate after regeneration: 20 regions current (identical) |
| `08-final-documentation-drift.txt` | Same Stage L gate after the change: 14 passed, 0 failed, `pass` |
| `09-final-lint.txt` | `./scripts/lint.sh` after the change: exit 0, syntax clean, no new PHPCS violations, PHPStan clean |
| `10-full-suite-final-summary.txt` | The full-suite run **after** edits: per-layer totals and the failing-suite list, for the §11 comparison |
| `11-final-link-audit.txt` | The relative-link audit after the change: 618 links checked, 20 broken (19 historical, deliberately untouched + this report link before the report existed), vs 28 at baseline |
| `12-final-git-status.txt` | The final `git status --short` and `git diff --stat` — only the files the plan named; 0 files under `wp-content/` |
| `13-negative-proofs.txt` | Injected-violation runs proving the registry drift gate and the agent-governance gate can fail (exit ≠ 0), with the exact output |

_Last verified: 2026-11-07 by the agent, skills and documentation audit_