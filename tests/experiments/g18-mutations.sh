#!/usr/bin/env bash
#
# G18 — negative proofs for automatic continuation of a resumable enumeration.
#
# ## Why
#
# "Resumable" must mean resumable AUTOMATICALLY. The claim is only worth
# anything if a suite fails when the automatic part is removed, so every
# mutation below breaks EXACTLY ONE mechanism and requires
# `test-import-continuation.php` to FAIL.
#
# A mutation that leaves the suite GREEN is VACUOUS — it proves the assertions
# were not protecting anything — and this script reports it loudly as a
# failure.
#
# ## Coverage map
#
#   M1  the engine never reconciles            -> no continuation is ever created
#   M2  the trigger is never armed             -> scheduled, but nothing will run
#   M3  the cooldown is not enforced           -> retries before it expires
#   M4  the delay is aggressive (1 s)          -> the 20-30 minute policy is gone
#   M5  the resumable predicate is bypassed    -> reschedules a finished listing
#   M6  `complete` is ignored by the predicate -> a truncated listing retries forever
#   M7  duplicate protection removed           -> one source acquires many jobs
#   M8  the source lock is bypassed            -> two workers resume one source
#   M9  the lock becomes global                -> one source blocks the others
#   M10 the checkpoint is not used to resume   -> the walk restarts at the beginning
#   M11 the stall guard is removed             -> a stalled source retries forever
#   M12 a scheduling failure is hidden         -> UI claims resumable, nothing runs
#   M13 a DRY RUN schedules                     -> classification mutates scheduling
#   M14 a lost timer is never re-armed         -> a restart strands the job
#   M15 the arming-failure branch is skipped   -> a failed schedule is hidden
#
# ## Safety
#
# Operates only on the local repository working tree, always restores the
# originals from a backup (trap on EXIT/INT/TERM), never contacts a production
# host and never writes production.
#
# ## Usage
#
#   ./tests/experiments/g18-mutations.sh          # every mutation
#   ./tests/experiments/g18-mutations.sh M3       # one mutation by id
#
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

CONT="wp-content/plugins/conexao-event-importer/includes/class-conexao-import-continuation.php"
ENGINE="wp-content/plugins/conexao-event-importer/includes/class-event-importer.php"
CHECK="wp-content/plugins/conexao-event-importer/includes/class-source-enumeration-checkpoint.php"
EB="wp-content/plugins/conexao-event-importer/includes/sources/class-eventbrite-source.php"

SUITE="wp-content/plugins/conexao-event-importer/tests/test-import-continuation.php"

BACKUP_DIR="$(mktemp -d)"
PROVEN=0
FAILED=0

restore() {
	for pair in "cont:$CONT" "engine:$ENGINE" "check:$CHECK" "eb:$EB"; do
		key="${pair%%:*}"
		path="${pair#*:}"
		[ -f "$BACKUP_DIR/$key" ] && cp "$BACKUP_DIR/$key" "$path"
	done
	return 0
}

cleanup() {
	restore
	rm -rf "$BACKUP_DIR"
	echo
	echo "Originals restored."
}
trap cleanup EXIT INT TERM

cp "$CONT" "$BACKUP_DIR/cont"
cp "$ENGINE" "$BACKUP_DIR/engine"
cp "$CHECK" "$BACKUP_DIR/check"
cp "$EB" "$BACKUP_DIR/eb"

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

# The AUTHORITATIVE signal is the suite's own exit code: `test_finish()` exits 1
# when any assertion failed, and assertions.php exits 1 immediately when a
# PREREQUISITE assertion fails (so no summary line is printed at all). Parsing
# only the summary would report a real failure as "0 failed" — a vacuous PASS.
report() {
	local tmp summary status
	tmp="$(mktemp)"

	run_suite "$2" >"$tmp" 2>&1
	status=$?

	summary="$(tr -d '\000' <"$tmp" | grep -a 'passed,' | tail -1)"

	if [ "$status" -eq 124 ]; then
		printf '%-4s %-56s %s\n' "$1" "$3" "PROVEN (by TIMEOUT - the mutant hung; not an assertion-level proof)"
		PROVEN=$((PROVEN + 1))
		rm -f "$tmp"
		return
	fi

	if [ -z "$summary" ]; then
		# assertions.php exits 1 IMMEDIATELY on a failed prerequisite, so a
		# genuine failure can print no summary line at all. The exit code is
		# therefore the authoritative signal.
		summary="(the suite exited early on a failed assertion)"
	fi

	rm -f "$tmp"

	if [ "$status" -eq 0 ]; then
		printf '%-4s %-56s %s\n' "$1" "$3" "VACUOUS - SUITE STILL GREEN ($summary)"
		FAILED=$((FAILED + 1))
	else
		printf '%-4s %-56s %s\n' "$1" "$3" "PROVEN  ($summary)"
		PROVEN=$((PROVEN + 1))
	fi
}

run_mutation() {
	local id="$1" desc="$2" suite="$3"

	restore

	if ! apply_step "$id"; then
		printf '%-4s %-56s %s\n' "$id" "$desc" "PATCH-FAILED"
		FAILED=$((FAILED + 1))
		return
	fi

	# A mutated file must still parse, so a failure below is BEHAVIOURAL and not
	# a parse error.
	if ! php -l "$CONT" >/dev/null 2>&1 || ! php -l "$ENGINE" >/dev/null 2>&1 \
		|| ! php -l "$CHECK" >/dev/null 2>&1 || ! php -l "$EB" >/dev/null 2>&1; then
		printf '%-4s %-56s %s\n' "$id" "$desc" "PARSE-ERROR"
		FAILED=$((FAILED + 1))
		return
	fi

	report "$id" "$suite" "$desc"
}

apply_step() {
	case "$1" in
	M1)
		# The engine never reconciles: the resumable state has no owner again.
		apply_patch "$ENGINE" '
old = "\t\t\t\tConexao_Import_Continuation::reconcile( $source_id, is_array( $stats ) ? $stats : array() );"
new = "\t\t\t\t// MUTANT: the continuation is never reconciled"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M2)
		# The trigger is never armed: the record says scheduled, nothing runs.
		# This is the mutation that models "the scheduler exists but its timer
		# is never set", so the suite must notice that no event is armed.
		apply_patch "$CONT" '
old = "\t\treturn (bool) wp_schedule_single_event( $run_at, self::HOOK, $args );"
new = "\t\treturn true; // MUTANT: the trigger is never armed"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M15)
		# The arming-FAILURE branch is skipped: an unarmed continuation is
		# recorded as scheduled, so the UI would promise a run that cannot
		# happen. (This is the mutation the first draft mislabelled as M2.)
		apply_patch "$CONT" '
old = "\t\tif ( ! self::arm_cron( $source_id, $run_at ) ) {"
new = "\t\tif ( false ) { // MUTANT: the arming-failure branch is skipped"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M3)
		# The cooldown is not enforced: a trigger may run before it expires.
		apply_patch "$CONT" '
old = "\t\t\tif ( self::now() < (int) $record[\x27run_at\x27] ) {"
new = "\t\t\tif ( false ) { // MUTANT: the cooldown is not enforced"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M4)
		# The delay is aggressive: the 20-30 minute policy is gone.
		apply_patch "$CONT" '
old = "\t\t$seconds = self::COOLDOWN_SECONDS * (int) pow( 2, $stalled );"
new = "\t\t$seconds = 1; // MUTANT: an aggressive retry delay"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M5)
		# The resumable predicate is bypassed: a finished listing is rescheduled.
		apply_patch "$CONT" '
old = "\t\t$owed    = $knows && Conexao_Source_Enumeration_Checkpoint::is_resumable( $check );"
new = "\t\t$owed    = true; // MUTANT: the resumable predicate is bypassed"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M6)
		# `complete` is ignored: an upstream-truncated listing retries forever.
		apply_patch "$CHECK" '
old = "\t\tif ( ! empty( $record[\x27complete\x27] ) ) {"
new = "\t\tif ( false ) { // MUTANT: a completed generation is treated as resumable"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M7)
		# Duplicate protection removed: one source acquires many pending jobs.
		apply_patch "$CONT" '
old = "\t\tif ( self::STATE_SCHEDULED === $existing[\x27state\x27] && (int) $existing[\x27run_at\x27] > 0 ) {"
new = "\t\tif ( false ) { // MUTANT: duplicate protection removed"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M8)
		# The source lock is bypassed: two workers can resume one source.
		apply_patch "$CONT" '
old = "\t\t\tif ( ! self::acquire_lock( $id ) ) {"
new = "\t\t\tif ( false ) { // MUTANT: the source lock is bypassed"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M9)
		# The lock becomes GLOBAL: one source blocks every other source.
		apply_patch "$CONT" '
old = "\t\treturn self::LOCK_PREFIX . sanitize_key( (string) $source_id );"
new = "\t\treturn self::LOCK_PREFIX . \x27global\x27; // MUTANT: one global lock"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M10)
		# The checkpoint is not used to resume: the walk restarts at the start.
		apply_patch "$EB" '
old = "\t\t$last_page = (int) $this->checkpoint[\x27last_page\x27];"
new = "\t\t$last_page = 1; // MUTANT: the checkpoint is ignored"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M11)
		# The stall guard is removed: a stalled source retries forever.
		apply_patch "$CONT" '
old = "\t\tif ( $stalled >= self::MAX_STALLED ) {"
new = "\t\tif ( false ) { // MUTANT: the stall guard is removed"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M12)
		# A scheduling failure is hidden: the record still says scheduled.
		apply_patch "$CONT" '
old = "\t\t\t$record[\x27state\x27]   = self::STATE_FAILED;"
new = "\t\t\t$record[\x27state\x27]   = self::STATE_SCHEDULED; // MUTANT: a scheduling failure is hidden"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M13)
		# A DRY RUN schedules: a classification pass mutates scheduling state.
		apply_patch "$ENGINE" '
old = "\t\t\tif ( ! $dry_run ) {"
new = "\t\t\tif ( true ) { // MUTANT: a dry run schedules"
assert old in s
s = s.replace(old, new, 1)
'
		;;
	M14)
		# A lost timer is never re-armed: a restart strands the continuation.
		apply_patch "$CONT" '
old = "\t\t\tif ( self::arm_cron( $id, (int) $record[\x27run_at\x27] ) ) {"
new = "\t\t\tif ( false ) { // MUTANT: a lost timer is never re-armed"
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

# --- Run -------------------------------------------------------------------

echo "G18 mutation proofs -- each mutation MUST make the suite fail"
echo "-------------------------------------------------------------------------"

if [ $# -gt 0 ]; then
	IDS="$@"
else
	IDS="M1 M2 M3 M4 M5 M6 M7 M8 M9 M10 M11 M12 M13 M14 M15"
fi

for id in $IDS; do
	case "$id" in
	M1)  run_mutation "$id" "the engine never reconciles" "$SUITE" ;;
	M2)  run_mutation "$id" "the trigger is never armed" "$SUITE" ;;
	M3)  run_mutation "$id" "the cooldown is not enforced" "$SUITE" ;;
	M4)  run_mutation "$id" "the delay is aggressive (1s)" "$SUITE" ;;
	M5)  run_mutation "$id" "the resumable predicate is bypassed" "$SUITE" ;;
	M6)  run_mutation "$id" "the complete term is ignored" "$SUITE" ;;
	M7)  run_mutation "$id" "duplicate protection removed" "$SUITE" ;;
	M8)  run_mutation "$id" "the source lock is bypassed" "$SUITE" ;;
	M9)  run_mutation "$id" "the lock becomes global" "$SUITE" ;;
	M10) run_mutation "$id" "the checkpoint is ignored on resume" "$SUITE" ;;
	M11) run_mutation "$id" "the stall guard is removed" "$SUITE" ;;
	M12) run_mutation "$id" "a scheduling failure is hidden" "$SUITE" ;;
	M13) run_mutation "$id" "a dry run schedules" "$SUITE" ;;
	M14) run_mutation "$id" "a lost timer is never re-armed" "$SUITE" ;;
	M15) run_mutation "$id" "the arming-failure branch is skipped" "$SUITE" ;;
	esac
done

echo "-------------------------------------------------------------------------"
echo "PROVEN (suite failed as required): $PROVEN"
echo "VACUOUS or broken:                $FAILED"

if [ "$FAILED" -gt 0 ]; then
	exit 1
fi

exit 0
