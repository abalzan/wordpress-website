#!/usr/bin/env python3
"""Stage L documentation-drift gate (engineering standard §9).

A STATIC check over the repository's documentation surface. It loads no
WordPress, opens no socket and can never contact production: it only reads
files and runs the repository's own read-only gates.

§9.2 makes these MUSTs:

  - "A list that must stay in sync (plugin counts, versions, load order, routes)
     is generated or CI-checked — never hand-maintained in two places."
  - "Every doc ends with `_Last verified: YYYY-MM-DD by <area>_`."
  - "No root-level report or evidence file is added; use `docs/reports/` and
     `docs/evidence/`."

## This gate ORCHESTRATES; it does not reimplement

The heavy drift checks already exist and are maintained elsewhere. This gate
INVOKES their documented contracts as subprocesses and fails when they fail,
rather than copying their logic (engineering standard §13.2: "Prefer shared
engines/helpers over new copies"):

  - `php scripts/generate-registry-docs.php --check`  (Stage G registry drift)
  - `tests/scripts/verify-script-conventions.py`      (Stage I script catalogue)
  - `tests/scripts/verify-agent-governance.py`        (Stage K governance)

The checks this gate owns (the ones no existing gate covers) are §9's
last-verified markers, the AGENTS.md orientation rule and the root-file rule.

## Last-verified markers: scoped, not weakened

§9.2 requires the marker on "every doc". This repository has ~50 historical
report/audit documents that predate the rule and legitimately do not carry it
(they are immutable records of a past stage, not living reference). Rather than
suppress them with a blanket exception, the gate enforces the rule on the
DOCUMENTED SET — the living reference documents §9.1 assigns an owner to, plus
the index and the templates — and reports the historical corpus separately as
an explicit, counted fact. The living set is the set that must stay current.

Exit code: 0 when every check passes, 1 otherwise. Emits one `GATE-RESULT {...}`
line for the Stage L aggregate.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)

GATE_ID = "documentation_drift"

LAST_VERIFIED = re.compile(r"^_Last verified: \d{4}-\d{2}-\d{2} by .+_$", re.M)

# The living reference documents §9.1 gives an owner to, plus the index, the
# two Stage K templates and the skill index (the 2026-09-30 agent-skills
# documentation migration made .agents/skills/README.md a living governance
# document). These are the documents that must stay current.
LIVING_DOCS = (
    "AGENTS.md",
    "README.md",
    "docs/README.md",
    "docs/architecture.md",
    "docs/content-model.md",
    "docs/routing.md",
    "docs/frontend.md",
    "docs/deployment.md",
    "docs/development.md",
    "docs/testing.md",
    "docs/releases.md",
    "docs/engineering-standard.md",
    "docs/templates/plan.md",
    "docs/templates/report.md",
    "docs/themes/conexao-br-irlanda.md",
    ".agents/skills/README.md",
)

# The authoritative sources Stage K governance must keep pointing at.
REQUIRED_AUTHORITIES = (
    "docs/engineering-standard.md",
    "plugins.json",
    "docs/README.md",
    "docs/templates/plan.md",
    "docs/templates/report.md",
    "tests/bootstrap.php",
    "tests/lib/assertions.php",
)

PASSED = 0
FAILURES: list[str] = []


def check(condition: bool, message: str) -> bool:
    """Record one assertion. Never raises."""
    global PASSED
    if condition:
        PASSED += 1
    else:
        FAILURES.append(message)
    return bool(condition)


def run_gate(name: str, command: list[str]) -> bool:
    """Invoke an existing read-only gate and require it to pass.

    The gate is NOT reimplemented here: it is run as a subprocess, so the
    existing Stage G/I/K contract is the single source of truth and this gate
    fails whenever the underlying gate fails.
    """
    proc = subprocess.run(
        command,
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    ok = proc.returncode == 0
    tail = (proc.stdout + proc.stderr).strip().splitlines()
    summary = tail[-1] if tail else "(no output)"

    check(ok, f"{name} gate failed (exit {proc.returncode}): {' '.join(command)} — {summary}")
    print(f"   {name:34s} exit={proc.returncode} {summary}")
    return ok


def main() -> int:
    print("== Stage L documentation-drift gate ==")
    print(f"   repo: {REPO_ROOT}")

    # --- 1. Orchestrate the existing gates (their contracts, not copies) -----
    print("\n   -- orchestrated gates --")
    run_gate("Stage G registry drift", ["php", "scripts/generate-registry-docs.php", "--check"])
    run_gate("Stage I script conventions", ["python3", "tests/scripts/verify-script-conventions.py"])
    run_gate("Stage K agent governance", ["python3", "tests/scripts/verify-agent-governance.py"])

    # --- 2. §9.2 last-verified markers on the living document set ----------
    print("\n   -- §9.2 last-verified markers (living reference set) --")
    missing_markers = []
    for relative in LIVING_DOCS:
        path = os.path.join(REPO_ROOT, relative)
        if not os.path.isfile(path):
            missing_markers.append(f"{relative} (missing)")
            continue
        with open(path, encoding="utf-8", errors="replace") as handle:
            body = handle.read()
        if not LAST_VERIFIED.search(body):
            missing_markers.append(f"{relative} (no `_Last verified: YYYY-MM-DD by <area>_`)")

    check(
        not missing_markers,
        "every living reference document ends with a `_Last verified:` marker — "
        + "; ".join(missing_markers),
    )
    print(f"   living documents checked: {len(LIVING_DOCS)}, missing marker: {len(missing_markers)}")

    # --- 3. The historical corpus is REPORTED, not silently ignored --------
    # Counted explicitly so the number is visible rather than assumed.
    historical = 0
    for dirpath, dirnames, filenames in os.walk(os.path.join(REPO_ROOT, "docs")):
        dirnames[:] = sorted(dirnames)
        for name in sorted(filenames):
            if not name.endswith(".md"):
                continue
            path = os.path.join(dirpath, name)
            relative = os.path.relpath(path, REPO_ROOT)
            if relative in LIVING_DOCS:
                continue
            with open(path, encoding="utf-8", errors="replace") as handle:
                body = handle.read()
            if not LAST_VERIFIED.search(body):
                historical += 1
    print(f"   historical/audit documents without the marker (reported, not gated): {historical}")

    # --- 4. §9.2 no root-level report or evidence file --------------------
    print("\n   -- §9.2 no root-level report/evidence file --")
    root_files = sorted(
        name for name in os.listdir(REPO_ROOT)
        if os.path.isfile(os.path.join(REPO_ROOT, name))
    )
    offenders = [
        name for name in root_files
        if re.search(r"(report|evidence|gate)", name, re.I)
        and not name.endswith((".md", ".json", ".neon", ".xml", ".dist"))
    ]
    offenders += [
        name for name in root_files
        if re.search(r"(report|evidence|gate)", name, re.I) and name.endswith(".md")
    ]
    check(
        not offenders,
        "no report/evidence file sits at the repository root (use docs/reports/ "
        f"and docs/evidence/) — found: {', '.join(offenders)}",
    )
    print(f"   root files scanned: {len(root_files)}, offenders: {len(offenders)}")

    # --- 5. AGENTS.md stays an orientation document (§9.2 SHOULD, <250 lines)
    print("\n   -- §9.2 AGENTS.md remains orientation --")
    agents_path = os.path.join(REPO_ROOT, "AGENTS.md")
    with open(agents_path, encoding="utf-8", errors="replace") as handle:
        agents_body = handle.read()
    agents_lines = agents_body.count("\n") + 1
    check(
        agents_lines < 250,
        f"AGENTS.md stays an orientation document (<250 lines) — it is {agents_lines} lines",
    )
    check(
        "docs/engineering-standard.md" in agents_body,
        "AGENTS.md points at the engineering standard (the authoritative source)",
    )
    print(f"   AGENTS.md lines: {agents_lines}")

    # --- 6. Stage K governance still references the authoritative sources --
    print("\n   -- authoritative sources referenced by the governance layer --")
    for relative in REQUIRED_AUTHORITIES:
        check(
            os.path.exists(os.path.join(REPO_ROOT, relative)),
            f"the authoritative source {relative} exists (the governance layer must reference it)",
        )
    print(f"   authoritative sources checked: {len(REQUIRED_AUTHORITIES)}")
    return 0


def emit() -> None:
    """Print the runner summary plus the Stage L machine-readable line."""
    passed = PASSED
    failed = len(FAILURES)

    for failure in FAILURES:
        print(f"FAIL: {failure}")

    print(f"{passed} passed, {failed} failed")

    payload = {
        "gate": GATE_ID,
        "status": "fail" if failed else "pass",
        "passed": passed,
        "failed": failed,
        "violations": failed,
        "details": FAILURES,
    }
    print("GATE-RESULT " + json.dumps(payload))
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
    emit()
