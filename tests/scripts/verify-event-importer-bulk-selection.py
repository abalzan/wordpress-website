#!/usr/bin/env python3
"""Gate — the Event Sources bulk-selection controller, proven at runtime.

The Event Sources table ships a JavaScript bulk-selection controller. Source text
is not evidence that a checkbox counter, an indeterminate header or a disabled
Apply button actually behave: those are RUNTIME behaviours of a real DOM mutation
sequence. This gate therefore executes the real `assets/admin.js` under
`node --test` with `tests/node/bulk-selection.test.js`, which loads the shipped
file and drives it against a fake DOM that mirrors the markup
`Conexao_Event_Sources` renders.

It also checks ONE cross-file invariant that source text can prove: the bulk
action values the page RENDERS (`<option value="...">` in
`render_bulk_actions_controls()`) must be exactly the values the server
WHITELISTS (`$allowed_actions` in `apply_bulk_action()`). The JavaScript derives
its own validation from the rendered options, so a drift between those two lists
would silently disable a real action or leave a dead one in the dropdown.

Contract:

  - a passing runtime run exits 0 and emits `GATE-RESULT {...} status: pass`;
  - a failing assertion exits 1 and emits `status: fail` with the failing count;
  - fewer executed runtime cases than the suite defines is a FAILURE, so a
    suite that silently stops running cannot pass;
  - Node being unavailable emits `status: blocked` and exits 1 — an unevaluated
    runtime invariant is NEVER reported as a pass.

Static, offline: it starts a local Node process and reads repository files. No
WordPress, no socket, no production access.
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

GATE_ID = "event_importer_bulk_selection_runtime"

TEST_FILE = os.path.join("tests", "node", "bulk-selection.test.js")
ADMIN_JS = os.path.join("wp-content", "plugins", "conexao-event-importer", "assets", "admin.js")
SOURCES_PHP = os.path.join(
    "wp-content", "plugins", "conexao-event-importer", "includes", "class-event-sources.php"
)

# The suite defines one case per selection behaviour. Fewer executed cases means
# coverage was silently removed.
MIN_CASES = 20

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


def read(path: str) -> str:
    """Read a repository file as UTF-8."""
    with open(os.path.join(REPO_ROOT, path), encoding="utf-8") as handle:
        return handle.read()


def function_body(source: str, signature: str) -> str:
    """Return one PHP method body, up to the next method docblock or declaration."""
    start = source.find(signature)
    if start < 0:
        return ""
    end = len(source)
    for marker in ("\n\t/**\n", "\n\tprotected function ", "\n\tpublic function "):
        nxt = source.find(marker, start + len(signature))
        if 0 <= nxt < end:
            end = nxt
    return source[start:end]


def bulk_action_contract(details: list[str]) -> int:
    """Assert rendered bulk actions == server-side whitelist. Returns failures."""
    sources = read(SOURCES_PHP)
    rendered_block = function_body(sources, "function render_bulk_actions_controls(")
    whitelist_block = function_body(sources, "function apply_bulk_action(")

    rendered = sorted(re.findall(r'<option value="([a-z_]+)">', rendered_block))
    match = re.search(r"\$allowed_actions\s*=\s*array\(([^)]*)\)", whitelist_block)
    allowed = sorted(re.findall(r"'([^']+)'", match.group(1))) if match else []

    if not rendered:
        print("FAIL: no bulk <option> values found in render_bulk_actions_controls().")
        details.append("rendered_options_missing")
        return 1
    if not allowed:
        print("FAIL: $allowed_actions not found in apply_bulk_action().")
        details.append("server_whitelist_missing")
        return 1
    if rendered != allowed:
        print(f"FAIL: rendered bulk actions {rendered} != server whitelist {allowed}")
        details.append("bulk_action_drift")
        return 1

    print(f"   rendered bulk actions == server whitelist: {', '.join(rendered)}")
    return 0


def main() -> int:
    print("\n== PERMANENT GATE: " + GATE_ID + " ==")
    print("The Event Sources bulk-selection behaviours are executed under node --test:")
    print("  - " + TEST_FILE)
    print("  - markup cross-check: " + SOURCES_PHP)
    print("  prerequisites: node")

    missing = [
        name
        for name in (TEST_FILE, ADMIN_JS, SOURCES_PHP)
        if not os.path.isfile(os.path.join(REPO_ROOT, name))
    ]

    if missing:
        print("FAIL: required file(s) missing: " + ", ".join(missing))
        return emit("blocked", 0, 0, ["missing_file:" + name for name in missing])

    details: list[str] = []
    contract_failures = bulk_action_contract(details)

    node = shutil.which("node")

    if not node:
        print("BLOCKED: `node` is not installed, so the runtime behaviour cannot be evaluated.")
        print("         An unevaluated invariant is NOT a pass.")
        return emit("blocked", 0, 0, details + ["node_unavailable"])

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
    cases = len(re.findall(r"^✔", output, re.M))

    if proc.returncode != 0:
        print("FAIL: the bulk-selection runtime suite reported failures.")
        for line in output.splitlines():
            if line.startswith("✖") or "AssertionError" in line:
                print("  " + line.strip())
        details.append("runtime_assertion_failure")
        return emit("fail", passed, max(1, failed), details)

    print(f"   runtime cases executed: {cases}")
    print(f"   assertions passed:      {passed}")

    if cases < MIN_CASES:
        print(f"FAIL: only {cases} runtime cases ran; the suite defines at least {MIN_CASES}.")
        details.append("runtime_cases_missing")
        return emit("fail", passed, 1, details)

    if contract_failures:
        return emit("fail", passed, contract_failures, details)

    print(f"\n{passed} passed, 0 failed")
    return emit("pass", passed + cases, 0, [])


if __name__ == "__main__":
    sys.exit(main())
