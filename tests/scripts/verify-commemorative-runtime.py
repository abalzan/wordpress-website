#!/usr/bin/env python3
"""Stage 4–6 gate — the commemorative ambient scheduler, proven at runtime.

The repository's other gates read source text. That is not enough evidence for a
timer, a session cap or a visibility pause: those are RUNTIME behaviours. This gate
therefore executes the real shared scheduler (`assets/js/commemorative.js`) under
`node --test` with `tests/node/commemorative-ambient.test.js`, which drives it with
fake timers, a fake DOM and fake session storage.

Contract:

  - a passing runtime run exits 0 and emits `GATE-RESULT {...} status: pass`;
  - a failing assertion exits 1 and emits `status: fail` with the failing count;
  - Node being unavailable emits `status: blocked` and exits 1 — an unevaluated
    runtime invariant is NEVER reported as a pass.

Static, offline: it starts a local Node process and nothing else. No WordPress, no
socket, no production access.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)

GATE_ID = "commemorative_ambient_runtime"

# Every Node runtime case of the commemorative surface, in one gate: the shared
# ambient scheduler, the Christmas two-sky journey controller and the Christmas
# homepage welcome dialog. A new runtime test joins this tuple rather than adding a
# second runner.
TEST_FILES = (
    {"path": os.path.join("tests", "node", "commemorative-ambient.test.js"), "min_cases": 11},
    {"path": os.path.join("tests", "node", "christmas-journey.test.js"), "min_cases": 8},
    {"path": os.path.join("tests", "node", "christmas-welcome.test.js"), "min_cases": 19},
)

PASS_RE = re.compile(r"^(?:# |ℹ )pass (\d+)$", re.M)
FAIL_RE = re.compile(r"^(?:# |ℹ )fail (\d+)$", re.M)


def emit(status: str, passed: int, failed: int, details: list[str]) -> int:
    """Print the machine-readable line and return the exit code."""
    payload = {
        "gate": GATE_ID,
        "status": status,
        "passed": passed,
        "failed": failed,
        "violations": failed,
        "pre_existing": 0,
        "new": failed,
        "prerequisites": ["node"],
        "allowlisted": [],
        "allowlisted_count": 0,
        "details": details,
    }
    print("GATE-RESULT " + json.dumps(payload))
    return 0 if status == "pass" else 1


def main() -> int:
    print("\n== PERMANENT GATE: " + GATE_ID + " ==")
    print("The commemorative runtime behaviours are executed under node --test:")
    for entry in TEST_FILES:
        print("  - " + entry["path"])
    print("  prerequisites: node")

    missing = [entry["path"] for entry in TEST_FILES if not os.path.isfile(os.path.join(REPO_ROOT, entry["path"]))]

    if missing:
        print("FAIL: runtime test file(s) missing: " + ", ".join(missing))
        return emit("blocked", 0, 0, ["missing_test_file:" + name for name in missing])

    node = shutil.which("node")

    if not node:
        print("BLOCKED: `node` is not installed, so the runtime behaviour cannot be evaluated.")
        print("         An unevaluated invariant is NOT a pass.")
        return emit("blocked", 0, 0, ["node_unavailable"])

    total_passed = 0
    total_failed = 0
    details: list[str] = []

    for entry in TEST_FILES:
        print("\n   -- " + entry["path"] + " --")

        proc = subprocess.run(
            [node, "--test", entry["path"]],
            cwd=REPO_ROOT,
            capture_output=True,
            text=True,
            check=False,
        )

        output = proc.stdout + proc.stderr

        passed = int(PASS_RE.search(output).group(1)) if PASS_RE.search(output) else 0
        failed = int(FAIL_RE.search(output).group(1)) if FAIL_RE.search(output) else 0

        # Node prints per-test tick marks; a zero-exit run with no parsed counters is
        # still a pass, but assert the expected number of runtime cases ran.
        if proc.returncode == 0:
            cases = len(re.findall(r"^✔", output, re.M))
            print(f"   runtime cases executed: {cases}")
            print(f"   assertions passed:      {passed}")

            if cases < entry["min_cases"]:
                print("FAIL: fewer runtime cases ran than the suite defines.")
                total_failed += 1
                details.append("runtime_cases_missing:" + entry["path"])
                continue

            total_passed += passed + cases
            continue

        print("FAIL: the runtime suite reported failures.")
        for line in output.splitlines():
            if line.startswith("✖") or "AssertionError" in line:
                print("  " + line.strip())

        total_passed += passed
        total_failed += max(1, failed)
        details.append("runtime_assertion_failure:" + entry["path"])

    if total_failed:
        return emit("fail", total_passed, total_failed, details)

    print(f"\n{total_passed} passed, 0 failed")
    return emit("pass", total_passed, 0, [])


if __name__ == "__main__":
    sys.exit(main())
