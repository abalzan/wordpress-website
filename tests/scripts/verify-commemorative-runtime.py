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
TEST_FILE = os.path.join("tests", "node", "commemorative-ambient.test.js")

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
    print("The shared ambient scheduler is executed under node --test.")
    print("  prerequisites: node")

    if not os.path.isfile(os.path.join(REPO_ROOT, TEST_FILE)):
        print("FAIL: the runtime test file is missing: " + TEST_FILE)
        return emit("blocked", 0, 0, ["missing_test_file"])

    node = shutil.which("node")

    if not node:
        print("BLOCKED: `node` is not installed, so the runtime behaviour cannot be evaluated.")
        print("         An unevaluated invariant is NOT a pass.")
        return emit("blocked", 0, 0, ["node_unavailable"])

    proc = subprocess.run(
        [node, "--test", TEST_FILE],
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
        print(f"  runtime cases executed: {cases}")
        print(f"  assertions passed:      {passed}")
        if cases < 11:
            print("FAIL: fewer runtime cases ran than the suite defines.")
            return emit("fail", passed, 1, ["runtime_cases_missing"])

        print(f"{passed + cases} passed, 0 failed")
        return emit("pass", passed + cases, 0, [])

    print("FAIL: the runtime suite reported failures.")
    for line in output.splitlines():
        if line.startswith("✖") or "AssertionError" in line:
            print("  " + line.strip())

    return emit("fail", passed, max(1, failed), ["runtime_assertion_failure"])


if __name__ == "__main__":
    sys.exit(main())
