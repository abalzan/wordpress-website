# Evidence — 2026-11-08 — commemorative-event skill

Documentation- and skills-only change: the `wp-commemorative-event` agent skill,
the `AGENTS.md` routing/guardrail section, the skills index and the documentation
index — plus the same-day hardening review of the skill (registry split,
brief/calendar workflow, template handling, contract-driven preview and cadence
rules, event-scoped tests, deliberate local startup). **No runtime file under
`wp-content/` was touched**, no content, database, production, release or git
operation was performed.

| File | What it proves |
|---|---|
| `verify-agent-governance.txt` | `python3 tests/scripts/verify-agent-governance.py` → **372 passed, 0 failed**: the new skill carries the full standard format in order, its index entry and every repository path it references resolve, and `AGENTS.md` is back under the 250-line orientation limit |
| `verify-documentation-drift.txt` | `python3 tests/scripts/verify-documentation-drift.py` → **14 passed, 0 failed**; `AGENTS.md` measured at 249 lines (gate) and every living document still carries its `_Last verified_` marker |
| `registry-check.txt` | `php scripts/generate-registry-docs.php --check` → 11 plugins validated, 20 generated regions current, **zero writes** (the change did not disturb the generated regions) |
| `git-state-before.txt` | branch `commemorative-events`, HEAD `d7f7ca5`, the three **pre-existing** worktree entries and the 9 stashes, as captured before the first edit |
| `git-state-after.txt` | the same four facts after the edits — branch and HEAD unchanged, all 9 stashes present, no pre-existing change reverted |
| `scripts-gate.txt` | `./scripts/run-tests.sh --scripts`: the whole script-contract layer, including the pre-existing `i18n_freshness` and `stage8_release_consistency` failures that this docs-only change neither causes nor fixes (identical failing list before and after the hardening pass) |
| `link-check.txt` | every markdown link target in the seven edited/added files resolves (**169**, 0 dangling), and every backticked repository path in the skill exists (16, 0 missing) |

Cited by `docs/reports/2026-11-08-commemorative-event-skill.md`.
