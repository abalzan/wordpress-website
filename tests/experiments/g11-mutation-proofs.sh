#!/usr/bin/env bash
#
# G11 — mutation proofs for the volatility MEASUREMENT logic.
#
# ## Why
#
# G11 claims its measurement cannot lie. That claim is only worth something if
# breaking the safety rules actually makes the suite fail. Each mutation below
# weakens exactly one rule that the suite asserts, and the script requires the
# suite to FAIL. A mutation that leaves the suite green is VACUOUS — it proves
# the assertion was not load-bearing — and the script reports it as such.
#
# ## Safety
#
# Every mutation is applied to tests/lib/eb-volatility.php, which is TEST
# INFRASTRUCTURE. It is never a production file, and the originals are restored
# from a backup on exit, including on interrupt.
#
# ## Usage
#
#     ./tests/experiments/g11-mutation-proofs.sh
#
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

LIB="tests/lib/eb-volatility.php"
BACKUP="$(mktemp)"
SUITE="wp-content/plugins/conexao-event-importer/tests/test-eventbrite-volatility.php"

cp "$LIB" "$BACKUP"

restore() {
  cp "$BACKUP" "$LIB"
  rm -f "$BACKUP"
}
trap restore EXIT INT TERM

# Run the G11 suite inside the container and report its pass/fail counts.
run_suite() {
  docker compose exec -T wordpress \
    php "/var/www/html/${SUITE}" 2>/dev/null \
    | grep -aoE '[0-9]+ passed, [0-9]+ failed' | tail -1
}

# mutate <label> <python-replacement-expression-file>
# The python snippet receives the source in `src` and must assign the
# mutated source to `out`.
mutate() {
  local label="$1"
  local snippet="$2"

  python3 - "$LIB" "$snippet" <<'PYEOF'
import sys
path, snippet = sys.argv[1], sys.argv[2]
src = open(path).read()
ns = {'src': src}
exec(snippet, ns)
open(path, 'w').write(ns['out'])
PYEOF

  local result
  result="$(run_suite)"
  local failed
  failed="$(printf '%s' "$result" | grep -oE '[0-9]+ failed' | grep -oE '[0-9]+')"
  failed="${failed:-0}"

  # The mutation id is the leading token (M1, M2, ...); the rest is the text.
  local id description
  id="$(printf '%s' "$label" | awk '{print $1}')"
  description="$(printf '%s' "$label" | cut -d' ' -f2-)"

  printf '%-5s %-58s' "$id" "$description"

  if [ "$failed" -gt 0 ]; then
    printf ' PROVEN  (%s)\n' "$result"
  else
    printf ' VACUOUS (%s) -- the assertion was NOT load-bearing\n' "${result:-no output}"
  fi

  cp "$BACKUP" "$LIB"
}

echo "G11 mutation proofs — each mutation MUST make the suite fail"
echo "--------------------------------------------------------------------------"

# M1: an incomplete observation is treated as complete.
mutate "M1    incomplete observation treated as complete" "
src = src.replace(
    \"\$removals_valid = (bool) ( \$previous['complete'] && \$current['complete'] );\",
    \"\$removals_valid = true;\"
)
out = src
"

# M2: removals are kept even when the comparison is not valid.
mutate "M2    removals kept despite invalid comparison" "
src = src.replace(
    \"if ( ! \$removals_valid ) {\",
    \"if ( false ) {\"
)
out = src
"

# M3: page number is used as the event identity.
mutate "M3    page number used as event identity" "
src = src.replace(
    \"if ( ! isset( \$index[ \$event['event_id'] ] ) ) {\",
    \"if ( ! isset( \$index[ 'page-' . \$event['page'] ] ) ) {\"
)
src = src.replace(
    \"\$index[ \$event['event_id'] ] = array(\",
    \"\$index[ 'page-' . \$event['page'] ] = array(\"
)
out = src
"

# M4: page movement is never recorded.
mutate "M4    page movement never recorded" "
src = src.replace(
    \"if ( (int) \$prev_event['page'] !== (int) \$curr_event['page'] ) {\",
    \"if ( false ) {\"
)
out = src
"

# M5: boundary crossings are never computed.
mutate "M5    boundary crossing never computed" "
src = src.replace(
    \"if ( \$from <= \$boundary && \$to > \$boundary ) {\",
    \"if ( false ) {\"
)
src = src.replace(
    \"if ( \$from > \$boundary && \$to <= \$boundary ) {\",
    \"if ( false ) {\"
)
out = src
"

# M6: a rate-limited observation is classified as clean.
mutate "M6    HTTP 429 not classified as rate limited" "
src = src.replace(
    \"if ( 429 === \$status ) {\",
    \"if ( false ) {\"
)
out = src
"

# M7: a WAF response is not classified as a WAF event.
mutate "M7    HTTP 405/403 not classified as WAF" "
src = src.replace(
    \"if ( 405 === \$status || 403 === \$status ) {\",
    \"if ( false ) {\"
)
out = src
"

# M8: upstream truncation is allowed to read as complete.
mutate "M8    upstream truncation treated as complete" "
src = src.replace(
    \"if ( ! empty( \$raw['truncated_upstream'] ) ) {\",
    \"if ( false ) {\"
)
out = src
"

# M9: a change rate is computed even when the denominator is invalid.
mutate "M9    change rate computed from an invalid denominator" "
src = src.replace(
    \"if ( empty( \$comparison['removals_valid'] ) ) {\",
    \"if ( false ) {\"
)
out = src
"

# M10: duplicate event IDs are not detected.
mutate "M10   duplicate event IDs not detected" "
src = src.replace(
    \"if ( \$count > 1 ) {\",
    \"if ( false ) {\"
)
out = src
"

echo "--------------------------------------------------------------------------"
restore
trap - EXIT INT TERM
echo "Originals restored."