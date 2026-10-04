#!/usr/bin/env bash
#
# g10-mutations.sh — negative proofs for the G10 checkpoint protections.
#
# Engineering standard §8 / G10 brief §21: a protection that is never shown to
# FAIL when removed is not a protection, it is a comment.
#
# Each mutation applies a small, surgical patch to the working tree, runs the
# G10 suite, and asserts that the suite FAILS. A mutation that leaves the suite
# green is a VACUOUS PROOF and fails this script loudly.
#
# Safety:
#   - operates only on the local repository working tree;
#   - always restores the original files (trap on EXIT);
#   - never contacts a production host and never writes production.
#
# Usage:
#   ./scripts/g10-mutations.sh            # run every mutation
#   ./scripts/g10-mutations.sh M3         # run one mutation by id
#
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

SRC_EVENTBRITE="wp-content/plugins/conexao-event-importer/includes/sources/class-eventbrite-source.php"
SRC_ENGINE="wp-content/plugins/conexao-event-importer/includes/class-event-importer.php"
SUITE="wp-content/plugins/conexao-event-importer/tests/test-eventbrite-checkpoint.php"

BACKUP_DIR="$(mktemp -d)"
PROVEN=0
FAILED=0

cleanup() {
	for f in "$SRC_EVENTBRITE" "$SRC_ENGINE"; do
		if [ -f "$BACKUP_DIR/$(basename "$f")" ]; then
			cp "$BACKUP_DIR/$(basename "$f")" "$f"
		fi
	done
	rm -rf "$BACKUP_DIR"
	echo
	echo "Originals restored."
}
trap cleanup EXIT

cp "$SRC_EVENTBRITE" "$BACKUP_DIR/$(basename "$SRC_EVENTBRITE")"
cp "$SRC_ENGINE" "$BACKUP_DIR/$(basename "$SRC_ENGINE")"

restore() {
	cp "$BACKUP_DIR/$(basename "$SRC_EVENTBRITE")" "$SRC_EVENTBRITE"
	cp "$BACKUP_DIR/$(basename "$SRC_ENGINE")" "$SRC_ENGINE"
}

run_suite() {
	docker compose exec -T wordpress bash -lc "cd /var/www/html && timeout 280 php $SUITE" 2>&1 | tr -d '\000'
}

# apply_patch <target-file> <python-body>
apply_patch() {
	python3 - "$1" <<PYEOF
import sys
path = sys.argv[1]
s = open(path).read()
$2
open(path, 'w').write(s)
PYEOF
}
run_mutation() {
	local id="$1"
	local desc="$2"

	restore

	if ! apply_step "$id"; then
		printf '%-5s %-56s %s\n' "$id" "$desc" "PATCH-FAILED"
		FAILED=$((FAILED + 1))
		return
	fi

	# A mutated file must still parse, so a failure below is BEHAVIOURAL and
	# not a parse error.
	if ! php -l "$SRC_EVENTBRITE" >/dev/null 2>&1 || ! php -l "$SRC_ENGINE" >/dev/null 2>&1; then
		printf '%-5s %-56s %s\n' "$id" "$desc" "PARSE-ERROR"
		FAILED=$((FAILED + 1))
		return
	fi

	local out summary failed
	out="$(run_suite)"
	summary="$(printf '%s' "$out" | tr -d '\000' | grep -a 'passed,' | tail -1)"

	# Extract the FAILED count. The summary line looks like
	# "G10 checkpoint: 51 passed, 0 failed" — note the label itself contains a
	# colon, so the count is taken from the number immediately preceding the
	# word "failed", never from the text before the last colon.
	failed="$(printf '%s' "$summary" | grep -o '[0-9][0-9]* failed' | grep -o '[0-9][0-9]*' | head -1)"

	if [ -z "$summary" ] || [ -z "$failed" ]; then
		printf '%-5s %-56s %s\n' "$id" "$desc" "NO-RESULT"
		FAILED=$((FAILED + 1))
		return
	fi

	if [ "$failed" -gt 0 ]; then
		printf '%-5s %-56s %s\n' "$id" "$desc" "PROVEN  ($summary)"
		PROVEN=$((PROVEN + 1))
	else
		printf '%-5s %-56s %s\n' "$id" "$desc" "VACUOUS - SUITE STILL GREEN ($summary)"
		FAILED=$((FAILED + 1))
	fi
}

apply_step() {
	case "$1" in
	M1)
		# Advance the checkpoint BEFORE the fetch succeeds (the dangerous
		# inversion). Expected: the ordering proof fails.
		apply_patch "$SRC_EVENTBRITE" '
old = """\t\t\t$html = $client->fetch_page( $base_url, $page );\n\t\t\tif ( empty( $html ) ) {"""
new = """\t\t\t$this->persist_checkpoint( $page, $page_count, false, "" ); // MUTANT\n\t\t\t$html = $client->fetch_page( $base_url, $page );\n\t\t\tif ( empty( $html ) ) {"""
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M2)
		# Resume always restarts at page 1. Expected: resume tests fail.
		apply_patch "$SRC_EVENTBRITE" '
old = "\t\t$start_at = max( 2, $last_page - $overlap );"
new = "\t\t$start_at = 2; // MUTANT: ignore the stored checkpoint"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M3)
		# An incomplete enumeration is reported as complete.
		apply_patch "$SRC_ENGINE" '
old = """\t\tif ( $fetch_complete && $reconciliation_safe ) {"""
new = """\t\t$fetch_complete = true; // MUTANT: always complete\n\t\tif ( $fetch_complete && $reconciliation_safe ) {"""
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M4)
		# Remove the G8 upstream-truncation guard.
		apply_patch "$SRC_EVENTBRITE" '
old = "\t\tif ( $this->is_upstream_truncated( $object_count, $page_count, $page_size ) ) {"
new = "\t\tif ( false && $this->is_upstream_truncated( $object_count, $page_count, $page_size ) ) {"
assert old in s
s = s.replace(old, new, 1)
'
		;;
M5)
		# One checkpoint shared across all sources (source isolation removed).
		apply_patch "$SRC_EVENTBRITE" '
old = "\t\t$this->checkpoint = Conexao_Source_Enumeration_Checkpoint::get( $this->get_id() );"
new = "\t\t$this->checkpoint = Conexao_Source_Enumeration_Checkpoint::get( 1 ); // MUTANT: shared"
assert old in s
s = s.replace(old, new, 1)
old2 = """\t\tConexao_Source_Enumeration_Checkpoint::record(
\t\t\t$this->get_id(),"""
new2 = """\t\tConexao_Source_Enumeration_Checkpoint::record(
\t\t\t1, // MUTANT: shared"""
assert old2 in s
s = s.replace(old2, new2, 1)
'
		;;
	M6)
		# A failed page advances the checkpoint to the page being tried.
		apply_patch "$SRC_EVENTBRITE" '
old = "\t\t\t\t$this->persist_checkpoint( $this->confirmed_page, $page_count, false, $stop_failure );"
new = "\t\t\t\t$this->persist_checkpoint( $page, $page_count, false, $stop_failure ); // MUTANT"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M7)
		# The checkpoint authorises absence-based reconciliation.
		apply_patch "$SRC_ENGINE" '
old = "\t\t$reconciliation_safe = $this->is_handler_reconciliation_safe( $handler );"
new = "\t\t$reconciliation_safe = true; // MUTANT: a checkpoint authorises reconciliation"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M8)
		# Identity deduplication of overlap duplicates removed.
		apply_patch "$SRC_EVENTBRITE" '
old = """\t\t\tif ( isset( $seen_ids[ $id ] ) ) {
\t\t\t\t++$duplicates;
\t\t\t\tcontinue;
\t\t\t}"""
new = """\t\t\t// MUTANT: identity dedup removed"""
assert old in s
s = s.replace(old, new, 1)
'
		;;
	*)
		return 1
		;;
	esac
	return 0
}

echo "G10 mutation proofs — each mutation MUST make the suite fail"
echo "--------------------------------------------------------------------------"

SELECTED="${1:-}"

for spec in \
	"M1|M1 checkpoint advanced BEFORE a successful fetch" \
	"M2|M2 resume always restarts at page 1" \
	"M3|M3 an incomplete run is reported as complete" \
	"M4|M4 upstream-truncation guard removed" \
	"M5|M5 one checkpoint shared across all sources" \
	"M6|M6 a failed page advances the checkpoint" \
	"M7|M7 checkpoint authorises missing-event reconciliation" \
	"M8|M8 overlap identity deduplication removed" \
; do
	id="${spec%%|*}"
	desc="${spec#*|}"

	if [ -n "$SELECTED" ] && [ "$SELECTED" != "$id" ]; then
		continue
	fi

	run_mutation "$id" "$desc"
done

echo "--------------------------------------------------------------------------"
echo "Proven: $PROVEN | vacuous/failed: $FAILED"

if [ "$FAILED" -gt 0 ]; then
	exit 1
fi

exit 0