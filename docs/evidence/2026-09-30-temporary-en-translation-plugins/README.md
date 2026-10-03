# Temporary EN translation plugins — evidence
#
# This directory holds the machine evidence for
# docs/reports/2026-09-30-temporary-en-translation-plugins.md.
#
# Scope: repository/artifact preparation only. Everything here is LOCAL. No
# production write, install, activation, EN record, translation link, B2 field
# or taxonomy term was created at any point.
#
# | file | what it is |
# |---|---|
# | `runtime-verification.php` | In-process, READ-ONLY proof that the installed `conexao-translation-rollout` engine and `conexao-en-translation` stage layer load together, that all seven current stage IDs resolve, that B1/B2 classification is correct, and that the shared-slug policy resolves `newsletter`. It is placed here, not in `tests/`, because it is task evidence rather than a maintained suite. |
# | `zip-integrity.py` | Programmatic inspection of both temporary ZIPs: entry list, file count, byte count, SHA-256, plugin header version, and the leakage checks (`.env`, secrets, tests/fixtures, unrelated plugins, Flutter/mobile files). |
#
# The canonical dry-run is NOT re-implemented here: Phase 7 uses the
# repository's own `scripts/run-en-translation.php --dry-run`, and the
# deterministic packaging uses the repository's own
# `scripts/build-temporary-plugins-zip.sh`.
