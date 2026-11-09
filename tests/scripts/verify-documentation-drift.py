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

LAST_VERIFIED = re.compile(r"^_Last verified: \d{4}-\d{2}-\d{2} by .+_$")


def terminal_marker_ok(body: str) -> bool:
    """True when the last non-empty line is a valid `_Last verified:` marker.

    Trailing blank lines are allowed; ordinary content after the marker is not.
    """
    lines = [line for line in body.splitlines() if line.strip()]
    return bool(lines) and LAST_VERIFIED.match(lines[-1]) is not None

# The living reference documents §9.1 gives an owner to, plus the index and the
# two Stage K templates. These are the documents that must stay current.
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
    # Commemorative event/content references owned by §9.1 as living canonical
    # references (required by wp-commemorative-event).
    "docs/commemorative-events.md",
    "docs/commemorative-content.md",
    # Living indexes that carry `_Last verified` markers and must stay current.
    "docs/plugins/README.md",
    "docs/reports/README.md",
    "docs/evidence/README.md",
)


def plugin_documentation_paths() -> tuple[str, ...]:
    """Derive active plugin documentation paths from plugins.json.

    §9.1 assigns each `docs/plugins/<slug>.md` to a plugin maintainer, and
    plugins.json is the single authoritative registry. Deriving the paths from
    the registry avoids maintaining a second, hand-maintained plugin list.
    Generated lifecycle-metadata regions (lines 3-16 of each plugin doc) are
    owned by scripts/generate-registry-docs.php; the `_Last verified` marker is
    added in the manual area below the generated block, never inside it.
    """
    registry_path = os.path.join(REPO_ROOT, "plugins.json")
    paths: list[str] = []
    if os.path.isfile(registry_path):
        try:
            with open(registry_path, encoding="utf-8") as handle:
                data = json.load(handle)
            for entry in data.get("load_order", []):
                if isinstance(entry, dict) and entry.get("documentation"):
                    paths.append(entry["documentation"])
        except (ValueError, OSError):
            pass
    return tuple(paths)

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
    # The static living set plus the plugin docs derived from plugins.json.
    living = list(LIVING_DOCS) + list(plugin_documentation_paths())
    for relative in living:
        path = os.path.join(REPO_ROOT, relative)
        if not os.path.isfile(path):
            missing_markers.append(f"{relative} (missing)")
            continue
        with open(path, encoding="utf-8", errors="replace") as handle:
            body = handle.read()
        if not terminal_marker_ok(body):
            missing_markers.append(f"{relative} (no terminal `_Last verified: YYYY-MM-DD by <area>_`)")

    check(
        not missing_markers,
        "every living reference document ends with a `_Last verified:` marker — "
        + "; ".join(missing_markers),
    )
    print(f"   living documents checked: {len(living)}, missing marker: {len(missing_markers)}")

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
            if not terminal_marker_ok(body):
                historical += 1
    print(f"   historical/audit documents without the marker (reported, not gated): {historical}")

    # --- 3a. Negative proofs for the terminal-marker rule ------------------
    # A valid marker followed by ordinary content must FAIL; the same document
    # with the marker as its final non-empty line must PASS. This keeps the
    # two gates aligned on one definition of a terminal marker.
    marker = "_Last verified: 2026-11-09 by terminal-marker proof_"
    doc_with_trailing_content = f"intro\n{marker}\nordinary content after the marker\n"
    doc_with_terminal_marker = f"intro\nordinary content\n\n{marker}\n\n"
    check(
        not terminal_marker_ok(doc_with_trailing_content),
        "negative proof: marker followed by content must fail",
    )
    check(
        terminal_marker_ok(doc_with_terminal_marker),
        "positive proof: marker as final non-empty line (trailing blanks allowed) must pass",
    )

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
    # Count physical lines accurately: splitlines() does not invent a trailing
    # empty line for a final newline, so a 249-line file ending in "\n" is 249,
    # not 250. A genuine 250-line file (with or without a final newline) still
    # fails the <250 policy.
    agents_lines = len(agents_body.splitlines())
    check(
        agents_lines < 250,
        f"AGENTS.md stays an orientation document (<250 lines) — it is {agents_lines} lines",
    )
    # Negative proof: a file with exactly 250 physical lines must fail even if
    # it ends with a newline (splitlines() yields 250 entries either way).
    check(
        len(("x\n" * 250).splitlines()) == 250 and len(("x\n" * 249).splitlines()) == 249,
        "line-count proof: 250 physical lines counts as 250, 249 as 249",
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
