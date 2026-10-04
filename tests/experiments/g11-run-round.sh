#!/usr/bin/env bash
#
# G11 — run one observation round against Eventbrite (read-only).
#
# Each round captures ONE observation per configured source. The round is a
# separate PHP process per source, so no observation inherits another's state.
#
# Usage:
#   ./tests/experiments/g11-run-round.sh <label> [max_pages_all] [max_pages_window]
#
# Safety:
#   - read-only: the harness never calls a source's fetch_events(), never
#     imports, and snapshots/restores every shared importer option;
#   - it reuses Conexao_Eventbrite_Pacing, so the 2s page delay, the 20s->40s
#     rate-limit ladder and the 60s cap all apply unchanged;
#   - it never retries around a 429 or a WAF response.
#
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

LABEL="${1:?usage: g11-run-round.sh <label> [max_pages_all] [max_pages_window]}"
PAGES_ALL="${2:-8}"
PAGES_WINDOW="${3:-6}"

# Observations land in the MOUNTED tests/ tree, then are copied into the
# evidence directory. docs/ is not bind-mounted into the container.
STAGE_DIR="/var/www/html/tests/experiments/observations"
mkdir -p "$STAGE_DIR"

EVENTBRITE_SOURCES=(
  "eventbrite_cork:${PAGES_ALL}"
  "eventbrite_cork_events_this_week:${PAGES_WINDOW}"
  "eventbrite_cork_events_today:${PAGES_WINDOW}"
  "eventbrite_cork_events_tomorrow:${PAGES_WINDOW}"
)

echo "=== G11 round ${LABEL} ==="

for entry in "${EVENTBRITE_SOURCES[@]}"; do
  src="${entry%%:*}"
  pages="${entry##*:}"

  docker compose exec -T wordpress \
    php "/var/www/html/tests/experiments/g11-eventbrite-volatility.php" \
      --source="$src" \
      --label="$LABEL" \
      --max-pages="$pages" \
      --evidence-dir="$STAGE_DIR" 2>&1 | grep -E '^\[g11\]' || echo "  (no output for ${src})"

  # Deliberate inter-source gap: never hammer Eventbrite back to back.
  sleep 20
done

echo "=== round ${LABEL} complete ==="