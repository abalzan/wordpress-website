#!/usr/bin/env bash
#
# i18n-check.sh — i18n catalogue freshness (engineering standard §9.3).
#
# Purpose:
#   Fail when a component's `.pot` catalogue is older than the PHP that defines
#   its user-facing strings. §9.3 names exactly this command as the CI
#   freshness check.
#
# Scope:
#   Read-only. Inspects the theme and every plugin in plugins.json, compares each
#   catalogue against the newest gettext-bearing source, and reports which
#   catalogue is stale and which source made it stale. It NEVER writes, never
#   regenerates and never hand-edits a catalogue (§9.3: the theme's pt_BR
#   catalogue is an identity catalogue and MUST be regenerated, never edited).
#
# Safety:
#   - No WordPress, no database, no network, no production endpoint.
#   - No writes of any kind: this script only reads and reports.
#   - Exit code is non-zero when any catalogue is stale, so CI blocks.
#
# Usage:
#   ./scripts/i18n-check.sh
#
# To FIX a stale catalogue, regenerate it (never hand-edit):
#   wp i18n make-pot wp-content/themes/conexao-br-irlanda \
#     wp-content/themes/conexao-br-irlanda/languages/conexao-br-irlanda.pot
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

GATE="tests/scripts/verify-i18n-freshness.py"

echo "conexao i18n-check"
echo "  repository: $REPO_ROOT"
echo "  mode:       read-only (no writes, no regeneration)"
echo "  signal:     git commit time per file (mtime is not reproducible in CI)"

if ! command -v python3 >/dev/null 2>&1; then
	echo "ERROR: required command not found: python3" >&2
	exit 2
fi

if [ ! -f "$GATE" ]; then
	echo "ERROR: the i18n freshness gate is missing: $GATE" >&2
	exit 2
fi

# The gate is the single implementation; this script is the documented entry
# point §9.3 requires, so the rule is not maintained twice.
python3 "$GATE"
