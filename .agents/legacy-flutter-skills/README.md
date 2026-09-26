# Retired agent skill material (Flutter/Dart) — provenance, NOT active skills

This directory is **not** part of the active WordPress agent-skill set and is
**not** a source of truth. It is kept only so the repository preserves the
material and the decision that retired it (engineering standard §13.1; Stage K).

| Item | What it is | Status |
|---|---|---|
| `dart-*/`, `flutter-*/` | 23 Dart/Flutter skill definitions. They came from the external Flutter/mobile toolchain (`skills-lock.json` records `source: flutter/agent-plugins`), not from this repository. | **Retired.** Ignored by `.gitignore`; never loaded, never extended. |
| `skills-lock.json` | The Flutter toolchain's own pin file (computed hashes for those 23 skills). | **Retired.** It pinned only the Dart/Flutter set; nothing in this repository reads it. |

The active, WordPress-domain skill set lives in
[`.agents/skills/`](../skills/) — one directory per skill, each with a
`SKILL.md`. The standard requires **no** lock file, so no replacement lock model
was invented: the standard's drift protection is the
`.agents/skills/*/SKILL.md` structure plus the CI governance gate
(`tests/scripts/verify-agent-governance.py`), not a hash manifest.

Moved by Stage K (2026-09-26). Rationale, evidence and negative proofs:
`docs/evidence/2026-09-26-stage-k/`.
