#!/usr/bin/env bash
# Reproduces ONE clean GitHub Actions integration run locally, end to end:
#
#   docker compose down -v --remove-orphans
#   docker compose up -d
#   ci-env-bootstrap.sh                       (core install + plugins, = CI env)
#   scripts/bootstrap-ci-fixtures.php --apply --verify
#   ./scripts/run-tests.sh
#
# Everything runs from ONE process so two bootstraps can never overlap: the
# fixture corpus is written by a single writer, which is what "deterministic"
# means here. Output goes to /tmp/<label>.log.
#
# Usage: ./fresh-ci-run.sh <label>   e.g. ./fresh-ci-run.sh run1
set -euo pipefail

cd "$(dirname "$0")/../../.."
HERE="docs/evidence/2026-10-02-ci-fixture-translation-regression"
LABEL="${1:-run}"
LOG="/tmp/${LABEL}.log"

: > "$LOG"
say() { echo "=== $* ===" | tee -a "$LOG"; }

say "docker compose down -v --remove-orphans"
docker compose down -v --remove-orphans >> "$LOG" 2>&1

say "docker compose up -d"
docker compose up -d >> "$LOG" 2>&1

# Wait for the web container to accept requests.
for _ in $(seq 1 60); do
  code="$(curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/ || true)"
  if [ "$code" != "000" ]; then break; fi
  sleep 2
done

say "CI environment bootstrap (core install, theme, plugins, Polylang)"
bash "$HERE/ci-env-bootstrap.sh" >> "$LOG" 2>&1

say "Build the deterministic synthetic site (bootstrap-ci-fixtures.php)"
docker compose exec -T wordpress env HTTP_HOST=localhost REQUEST_URI=/ \
  php /var/www/html/scripts/bootstrap-ci-fixtures.php --apply --verify >> "$LOG" 2>&1

say "run-tests.sh"
./scripts/run-tests.sh >> "$LOG" 2>&1

say "DONE"
echo "log: $LOG"