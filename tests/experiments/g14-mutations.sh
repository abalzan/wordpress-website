#!/usr/bin/env bash
#
# G14 — negative proofs for the controlled Eventbrite import path.
#
# ## Why
#
# G14 activates a source and runs the importer for real. That is only safe if
# every protection in front of the destructive operation is genuinely
# load-bearing. §32 of the brief enumerates ten critical regression proofs; each
# one below breaks EXACTLY one rule and requires the suite to FAIL.
#
# A mutation that leaves the suite green is VACUOUS — it proves the assertion was
# not protecting anything — and this script reports it loudly as a failure.
#
# ## Coverage map (§32)
#
#   N1  failing source must not become a successful empty   -> test-source-fetch-failure
#   N2  incomplete enumeration must not mark events missing   -> checkpoint suite
#   N3  checkpoint resume must advance, not restart          -> checkpoint suite
#   N4  rate limit must preserve checkpoint + incomplete     -> checkpoint suite
#   N5  duplicate overlapping event stays one event          -> checkpoint suite
#   N6  truncated listing stays incomplete                   -> checkpoint suite
#   N7  remove checkpoint guard -> resume test fails         -> checkpoint suite
#   N8  remove reconciliation gate -> safety test fails      -> checkpoint suite
#   N9  remove source isolation -> isolation test fails      -> checkpoint suite
#   N10 remove duplicate identity protection -> fails        -> checkpoint suite
#
# ## Safety
#
# Operates only on the local repository working tree, always restores the
# originals from a backup (trap on EXIT/INT/TERM), never contacts a production
# host and never writes production.
#
# ## Usage
#
#   ./tests/experiments/g14-mutations.sh          # every mutation
#   ./tests/experiments/g14-mutations.sh N7       # one mutation by id
#
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

SRC_EVENTBRITE="wp-content/plugins/conexao-event-importer/includes/sources/class-eventbrite-source.php"
SRC_ENGINE="wp-content/plugins/conexao-event-importer/includes/class-event-importer.php"

SUITE_CHECKPOINT="wp-content/plugins/conexao-event-importer/tests/test-eventbrite-checkpoint.php"
SUITE_FETCHFAIL="wp-content/plugins/conexao-event-importer/tests/test-source-fetch-failure.php"

BACKUP_DIR="$(mktemp -d)"
PROVEN=0
FAILED=0

restore() {
	[ -f "$BACKUP_DIR/eventbrite-source.php" ] && cp "$BACKUP_DIR/eventbrite-source.php" "$SRC_EVENTBRITE"
	[ -f "$BACKUP_DIR/event-importer.php" ] && cp "$BACKUP_DIR/event-importer.php" "$SRC_ENGINE"
	return 0
}

cleanup() {
	restore
	rm -rf "$BACKUP_DIR"
	echo
	echo "Originals restored."
}
trap cleanup EXIT INT TERM

cp "$SRC_EVENTBRITE" "$BACKUP_DIR/eventbrite-source.php"
cp "$SRC_ENGINE" "$BACKUP_DIR/event-importer.php"

# apply_patch <target> <python-body>
apply_patch() {
	python3 - "$1" <<PYEOF
import sys
path = sys.argv[1]
s = open(path).read()
$2
open(path, 'w').write(s)
PYEOF
}

run_suite() {
	docker compose exec -T wordpress bash -lc \
		"cd /var/www/html && timeout 280 php $1" 2>&1 | tr -d '\000'
}

report() {
	# The AUTHORITATIVE signal is the suite's own exit code.
	#
	# `test_finish()` exits 1 when any assertion failed, but assertions.php also
	# calls `exit(1)` the moment a PREREQUISITE assertion fails, so no
	# "N passed, M failed" summary line is printed at all. Parsing only the
	# summary therefore reports a real failure as "0 failed" — a
	# vacuous-looking PASS for a mutation that genuinely broke behaviour.
	#
	# So: the exit code decides. The summary line is only the human note.
	local tmp summary status
	tmp="$(mktemp)"

	run_suite "$2" >"$tmp" 2>&1
	status=$?

	summary="$(tr -d '\000' <"$tmp" | grep -a 'passed,' | tail -1)"

	if [ -z "$summary" ]; then
		summary="(no summary line - suite exited early on a failed assertion)"
	fi

	rm -f "$tmp"

	if [ "$status" -eq 0 ]; then
		printf '%-5s %-58s %s\n' "$1" "$3" "VACUOUS - SUITE STILL GREEN ($summary)"
		FAILED=$((FAILED + 1))
	else
		printf '%-5s %-58s %s\n' "$1" "$3" "PROVEN  ($summary)"
		PROVEN=$((PROVEN + 1))
	fi
}

run_mutation() {
	local id="$1" desc="$2" suite="$3"

	restore

	if ! apply_step "$id"; then
		printf '%-5s %-58s %s\n' "$id" "$desc" "PATCH-FAILED"
		FAILED=$((FAILED + 1))
		return
	fi

	# A mutated file must still parse, so a failure below is BEHAVIOURAL and not
	# a parse error.
	if ! php -l "$SRC_EVENTBRITE" >/dev/null 2>&1 || ! php -l "$SRC_ENGINE" >/dev/null 2>&1; then
		printf '%-5s %-58s %s\n' "$id" "$desc" "PARSE-ERROR"
		FAILED=$((FAILED + 1))
		return
	fi

	report "$id" "$suite" "$desc"
}

apply_step() {
	case "$1" in
	N1)
		# The failure is recorded as an ordinary result instead of a failure:
		# no fatal error, no incomplete flag, and the history row is told it is
		# a complete enumeration. This is exactly the "unreachable looks like an
		# empty calendar" defect G1 existed to kill.
		apply_patch "$SRC_ENGINE" '
old = """\t\t\t$result->add_fatal_error( $message, $e->getMessage() );
\t\t\t$result->add_incomplete_enumeration( $message );"""
new = """\t\t\t// MUTANT: a transport failure is recorded as an ordinary result"""
assert old in s
s = s.replace(old, new, 1)
'
		;;
	N2)
		# An incomplete enumeration is reported as complete.
		apply_patch "$SRC_ENGINE" '
old = """\t\tif ( $fetch_complete && $reconciliation_safe ) {"""
new = """\t\t$fetch_complete = true; // MUTANT: always complete
\t\tif ( $fetch_complete && $reconciliation_safe ) {"""
assert old in s
s = s.replace(old, new, 1)
'
		;;
	N3)
		# The stored checkpoint is discarded, so resume restarts at page 1.
		apply_patch "$SRC_EVENTBRITE" '
old = "\t\t$start_at = max( 2, $last_page - $overlap );"
new = "\t\t$start_at = 2; // MUTANT: ignore the stored checkpoint"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	N4)
		# A rate-limited walk is reported as complete: the truthfulness of the
		# 429 path is removed.
		apply_patch "$SRC_ENGINE" '
old = "\t\t$fetch_complete = $this->is_handler_fetch_complete( $handler );"
new = "\t\t$fetch_complete = true; // MUTANT: a throttled walk is called complete"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	N5)
		# Identity deduplication of the overlap region removed: an event that
		# appears on both the re-walked overlap page and the new page is
		# appended twice to the result set.
		apply_patch "$SRC_EVENTBRITE" '
old = """\t\t\tif ( isset( $seen_ids[ $id ] ) ) {
\t\t\t\t++$duplicates;
\t\t\t\tcontinue;
\t\t\t}"""
new = """\t\t\t$seen_ids[ $id ] = true; // MUTANT: a duplicate id is appended again
\t\t\t$unique_events[] = $event;"""
assert old in s
s = s.replace(old, new, 1)
'
		;;
	N6)
		# The upstream-truncation guard removed: a capped listing is called
		# complete, which is exactly what authorises destructive reconciliation.
		apply_patch "$SRC_EVENTBRITE" '
old = "\t\tif ( $this->is_upstream_truncated( $object_count, $page_count, $page_size ) ) {"
new = "\t\tif ( false && $this->is_upstream_truncated( $object_count, $page_count, $page_size ) ) { // MUTANT"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	N7)
		# Checkpoint usability guard removed: a checkpoint beyond the declared
		# page count is trusted instead of discarded.
		apply_patch "$SRC_EVENTBRITE" '
old = "\t\tif ( ! Conexao_Source_Enumeration_Checkpoint::is_usable( $this->checkpoint, $this->checkpoint_fingerprint, $page_count ) ) {"
new = "\t\tif ( false ) { // MUTANT: the checkpoint guard is removed"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	N8)
		# The reconciliation-safety gate removed: a checkpoint would authorise
		# absence-based reconciliation and hide live events.
		apply_patch "$SRC_ENGINE" '
old = "\t\t$reconciliation_safe = $this->is_handler_reconciliation_safe( $handler );"
new = "\t\t$reconciliation_safe = true; // MUTANT: a checkpoint authorises reconciliation"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	N9)
		# Source isolation removed: every source shares one checkpoint slot.
		apply_patch "$SRC_EVENTBRITE" '
old = "\t\t$this->checkpoint = Conexao_Source_Enumeration_Checkpoint::get( $this->get_id() );"
new = "\t\t$this->checkpoint = Conexao_Source_Enumeration_Checkpoint::get( 1 ); // MUTANT: shared"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	N10)
		# Duplicate identity protection removed: an event present on both the
		# overlap page and the new page is emitted twice.
		apply_patch "$SRC_EVENTBRITE" '
old = "\t\t\t\t++$duplicates;\n\t\t\t\tcontinue;"
new = "\t\t\t\t// MUTANT: an overlapping id is processed again"
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

echo "G14 negative proofs — each mutation MUST make the suite fail"
echo "--------------------------------------------------------------------------"

SELECTED="${1:-}"

for spec in \
	"N1|N1 a failing source becomes a successful empty|$SUITE_FETCHFAIL" \
	"N2|N2 an incomplete enumeration is called complete|$SUITE_CHECKPOINT" \
	"N3|N3 the checkpoint is ignored (resume restarts)|$SUITE_CHECKPOINT" \
	"N4|N4 a rate-limited walk is reported complete|$SUITE_CHECKPOINT" \
	"N5|N5 duplicate overlapping event not deduplicated|$SUITE_CHECKPOINT" \
	"N6|N6 a truncated listing is treated as complete|$SUITE_CHECKPOINT" \
	"N7|N7 the checkpoint usability guard removed|$SUITE_CHECKPOINT" \
	"N8|N8 the reconciliation-safety gate removed|$SUITE_CHECKPOINT" \
	"N9|N9 source isolation removed (shared checkpoint)|$SUITE_CHECKPOINT" \
	"N10|N10 duplicate identity protection removed|$SUITE_CHECKPOINT" \
	; do
	id="${spec%%|*}"
	rest="${spec#*|}"
	desc="${rest%%|*}"
	suite="${rest#*|}"

	if [ -n "$SELECTED" ] && [ "$SELECTED" != "$id" ]; then
		continue
	fi

	run_mutation "$id" "$desc" "$suite"
done

echo "--------------------------------------------------------------------------"
echo "Proven: $PROVEN | vacuous/failed: $FAILED"

if [ "$FAILED" -gt 0 ]; then
	echo "RESULT: FAIL — at least one negative proof was vacuous."
	exit 1
fi

echo "RESULT: PASS — every negative proof was genuinely load-bearing."
exit 0