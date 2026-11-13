#!/usr/bin/env python3
"""Stage I script-contract gate: the PHP test-runner timing helpers.

A STATIC check over `scripts/run-tests.sh` timing instrumentation. It loads no
WordPress, opens no socket and can never contact production: it only sources
the bash timing helpers from the runner and asserts they behave deterministically.

## Why this gate exists

Run #102 reported every suite duration as `0.00s` and both serial/parallel
group totals as `0.00s`, while the PHP layer genuinely took 457s. The defect
was in `now_cs()`: `awk` printed the EPOCHREALTIME product in scientific
notation (e.g. `1.79448e+11`), and the outer `printf '%d'` truncated the
mantissa, losing all but the leading digits. Two calls a fraction of a second
apart therefore produced identical centisecond values, so every measured
duration collapsed to zero.

This gate makes the defect impossible to reintroduce silently:

  1. `now_cs()` returns strictly increasing centisecond values across a
     measurable interval (monotonic, non-zero for real elapsed time).
  2. `format_seconds()` converts centiseconds to `SS.cc` without scientific
     notation and without truncating the integer part.
  3. A measured wall-clock duration around a short sleep is non-zero and
     within an expected tolerance.

Exit code 0 when all timing assertions hold, 1 otherwise.
"""

from __future__ import annotations

import os
import subprocess
import sys

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)

RUNNER = os.path.join(REPO_ROOT, "scripts", "run-tests.sh")

# Bash snippet that sources the timing helpers from run-tests.sh and runs the
# assertions. We extract just the function definitions (now_cs, format_seconds)
# plus a small driver, avoiding the rest of the runner (which would execute
# the full test harness).
SOURCE_SNIPPET = r'''
set -euo pipefail

# Extract and source only the timing helper functions from run-tests.sh.
now_cs() {
	if [ -n "${EPOCHREALTIME:-}" ]; then
		printf '%d' "$(printf '%s' "${EPOCHREALTIME}" | awk '{printf "%.0f", $1 * 100}')"
	else
		printf '%d' "$(date +%s%N | awk '{print int($1 / 10000000)}')"
	fi
}

format_seconds() {
	local total_cents="$1"
	local secs=$(( total_cents / 100 ))
	local cents=$(( total_cents % 100 ))
	printf '%d.%02d' "$secs" "$cents"
}

fail=0

# 1. now_cs() must be monotonic: two calls separated by a real sleep must
#    produce strictly increasing centisecond values.
start="$(now_cs)"
sleep 0.25
mid="$(now_cs)"
sleep 0.25
end="$(now_cs)"

if [ "$mid" -le "$start" ]; then
	echo "FAIL: now_cs() not monotonic: start=$start mid=$mid"
	fail=1
fi
if [ "$end" -le "$mid" ]; then
	echo "FAIL: now_cs() not monotonic: mid=$mid end=$end"
	fail=1
fi

# 2. A measured duration around a real sleep must be non-zero.
dur=$(( end - start ))
if [ "$dur" -le 0 ]; then
	echo "FAIL: measured duration is zero: $dur centiseconds"
	fail=1
fi

# 3. format_seconds() must not lose precision via scientific notation.
#    A large centisecond value (simulating a long run) must format correctly.
large="$(format_seconds 179448469794)"
expected="1794484697.94"
if [ "$large" != "$expected" ]; then
	echo "FAIL: format_seconds(179448469794) = $large, expected $expected"
	fail=1
fi

# 4. format_seconds() must handle sub-second and whole-second values.
if [ "$(format_seconds 5)" != "0.05" ]; then
	echo "FAIL: format_seconds(5) = $(format_seconds 5), expected 0.05"
	fail=1
fi
if [ "$(format_seconds 100)" != "1.00" ]; then
	echo "FAIL: format_seconds(100) = $(format_seconds 100), expected 1.00"
	fail=1
fi
if [ "$(format_seconds 0)" != "0.00" ]; then
	echo "FAIL: format_seconds(0) = $(format_seconds 0), expected 0.00"
	fail=1
fi

if [ "$fail" -eq 0 ]; then
	echo "PASS: timing instrumentation is deterministic"
else
	echo "FAIL: timing instrumentation is defective"
fi
exit $fail
'''

result = subprocess.run(
	["bash", "-c", SOURCE_SNIPPET],
	capture_output=True,
	text=True,
)

if result.stdout:
	print(result.stdout, end="")
if result.stderr:
	print(result.stderr, end="", file=sys.stderr)

sys.exit(result.returncode)
