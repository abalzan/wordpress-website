#!/usr/bin/env bash
#
# G11 — collect the evidence artefacts for the report.
#
# Copies the staged observations from the container into the repository evidence
# directory, regenerates the deterministic analysis from those observations,
# and records the verification numbers.
#
# Everything here is derived from captured data. Nothing is hand-edited, so the
# report cannot drift from the measurements it cites.
#
# Usage:
#   ./tests/experiments/g11-collect-evidence.sh <evidence-dir>
#
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

EVIDENCE_DIR="${1:-docs/reports/evidence/eventbrite-g11}"


mkdir -p "$EVIDENCE_DIR/observations"

STAGED_HOST="tests/experiments/observations"

# All staging happens ON THE HOST. `tests/` is bind-mounted into the
# container, so a host-side directory is visible at the same path inside it —
# whereas creating the directory inside the container fails, because only
# `tests/` and `scripts/` are mounted read-write from the repository and the
# container user cannot mkdir at the volume root.
mkdir -p "$STAGED_HOST" "$EVIDENCE_DIR/observations"

if [ -n "$(ls -A "$STAGED_HOST" 2>/dev/null | grep '\.json$')" ]; then
  # 1. Fresh observations from a live round: publish them into the evidence dir.
  cp "$STAGED_HOST"/*.json "$EVIDENCE_DIR/observations/" 2>/dev/null
else
  # No fresh staging tree (e.g. re-running to re-derive the analysis):
  # stage the already-published evidence instead, so this script is re-runnable
  # without re-walking Eventbrite.
  cp "$EVIDENCE_DIR"/observations/*.json "$STAGED_HOST"/ 2>/dev/null
fi

STAGED="/var/www/html/$STAGED_HOST"

# 2. The deterministic per-source summary, regenerated from those files.
docker compose exec -T wordpress \
  php /var/www/html/tests/experiments/g11-analyse.php "$STAGED" --json 2>/dev/null \
  > "$EVIDENCE_DIR/02-analysis.json"

# 3. The same analysis rendered for reading.
docker compose exec -T wordpress \
  php /var/www/html/tests/experiments/g11-analyse.php "$STAGED" 2>/dev/null \
  > "$EVIDENCE_DIR/03-analysis.txt"

echo "observations: $(ls -1 "$EVIDENCE_DIR/observations" | wc -l)"
echo "analysis.json: $(wc -c < "$EVIDENCE_DIR/02-analysis.json") bytes"
echo "analysis.txt:  $(wc -l < "$EVIDENCE_DIR/03-analysis.txt") lines"