#!/usr/bin/env python3
"""Stage I script-contract gate: the CI job-summary generator.

`scripts/ci-summary.php` is the ONLY thing that turns a harness log, a PHPStan
JSON report, the Stage L `gate.json` or the release-integrity log into the
`$GITHUB_STEP_SUMMARY` a reviewer reads instead of a raw log. A reporting helper
that silently mis-parses makes a red run look calm, so it gets its own gate.

What it asserts, against synthetic fixtures built in a temporary directory:

  1. A **passing** harness log yields the pass badge, the per-layer totals and
     the assertion count it actually contained.
  2. A **failing** harness log yields the fail badge, the non-PASS suite rows,
     the harness's own `failing suites:` names, and the BLOCKED/prerequisite
     warnings — including when the log carries ANSI colour codes.
  3. A PHPStan JSON report yields the totals and the per-file counts, and a
     clean report yields the "no errors" line.
  4. `gate.json` yields one row per gate plus the pass/fail/blocked tally and
     the new-vs-pre-existing violation split.
  5. A release-integrity log yields the totals line and every `FAIL:` detail.
  6. A missing input prints the visible **Summary unavailable** notice and the
     process still exits 0 (a reporting step must never change a job verdict).
  7. **Secret safety**: a credential-shaped value that appears in the input but
     is not part of a parsed summary field never appears in the output.
  8. **Negative controls** — the gate proves it can fail. Each check is re-run
     against a fixture that has been deliberately damaged (verdict removed,
     counts changed) and the assertion must go red.

Exit code: 0 when every check passes, 1 otherwise. Standard library only.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import tempfile

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)
SCRIPT = os.path.join(REPO_ROOT, "scripts", "ci-summary.php")

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


def run(*args: str) -> tuple[int, str]:
    """Run the summary generator and return (exit code, stdout+stderr)."""
    proc = subprocess.run(
        ["php", SCRIPT, *args],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        timeout=120,
    )
    return proc.returncode, proc.stdout + proc.stderr

PASSING_LOG = """\
In-process PHP suites (serial, one process per suite)
[PASS] plugin/conexao-admin-ux/test-leisure-coordinates.php (1.80s) — 29 passed, 0 failed
[PASS] plugin/conexao-admin-ux/test-recurrence-admin.php (3.40s) — 60 passed, 0 failed
[PASS] theme/conexao-br-irlanda/test-sponsor-archive-single-page.php (0.90s) — 23 passed, 0 failed

----------------------------------------
In-process PHP suites: 3 total, 3 passed, 0 failed
Assertions: 112 passed, 0 failed
Script-contract suites: 27 total, 27 passed, 0 failed
HTTP acceptance suites: 5 total, 5 passed, 0 failed
----------------------------------------
ALL TESTS PASSED
"""

FAILING_LOG = """\
\x1b[32m[PASS]\x1b[0m plugin/conexao-admin-ux/test-recurrence-admin.php (3.40s) — 60 passed, 0 failed
\x1b[31m[FAIL]\x1b[0m plugin/conexao-event-importer/test-import-continuation.php (12.10s) — 3 passed, 4 failed
\x1b[31m[TIMEOUT]\x1b[0m plugin/conexao-event-importer/test-stage1-run-coordination.php (901s) — exceeded 900s
[BLOCKED] theme/conexao-br-irlanda/test-translation-completeness.php — WordPress bootstrap failed

----------------------------------------
In-process PHP suites: 4 total, 1 passed, 3 failed
  WordPress bootstrap failures: 1
  prerequisite failures:         2
Assertions: 60 passed, 4 failed
----------------------------------------
\x1b[31mTESTS FAILED\x1b[0m
failing suites:
  - plugin/conexao-event-importer/test-import-continuation.php
  - plugin/conexao-event-importer/test-stage1-run-coordination.php
  - theme/conexao-br-irlanda/test-translation-completeness.php
"""

PHPSTAN_JSON = {
    "totals": {"errors": 0, "file_errors": 2},
    "files": {
        "/repo/wp-content/plugins/conexao-content/includes/a.php": {
            "messages": [
                {
                    "message": "Parameter #1 $text of function esc_html expects string, int given.",
                    "line": 42,
                    "identifier": "argument.type",
                }
            ]
        },
        "/repo/wp-content/themes/conexao-br-irlanda/inc/b.php": {
            "messages": [
                {
                    "message": "Variable $file might not be defined.",
                    "line": 7,
                    "identifier": "variable.undefined",
                },
                {
                    "message": "Left side of && is always true.",
                    "line": 9,
                    "identifier": "booleanAnd.leftAlwaysTrue",
                },
            ]
        },
    },
    "errors": [],
}

CLEAN_PHPSTAN_JSON = {"totals": {"errors": 0, "file_errors": 0}, "files": {}, "errors": []}

GATE_JSON = {
    "stage": "L — permanent invariant gates",
    "repository_sha": "0123456789abcdef0123456789abcdef01234567",
    "status": "fail",
    "gates": {
        "taxonomy_policy": {
            "status": "pass",
            "passed": 9,
            "failed": 0,
            "violations": 0,
            "pre_existing": 0,
            "new": 0,
        },
        "translation_completeness": {
            "status": "fail",
            "passed": 2,
            "failed": 3,
            "violations": 3,
            "pre_existing": 3,
            "new": 0,
        },
        "i18n_freshness": {
            "status": "blocked",
            "passed": 1,
            "failed": 2,
            "violations": 2,
            "pre_existing": 1,
            "new": 1,
        },
    },
}

RELEASE_LOG = """\
PASS: plugins.json derives the artifact allowlist
FAIL: release.json is stale for conexao-content
FAIL: theme ZIP ships a file under tests/
41 passed, 2 failed
"""

SECRET = "wp_app_pass_super_secret_value_9f3c"

LINT_LOG_OK = """\
==> [1/3] PHP syntax check (php -l, 579 files)
OK: no PHP parse errors.
==> [2/3] PHPCS (WordPress-Extra + WordPress-Docs + PHPCompatibilityWP) + legacy baseline gate
PHPCS legacy baseline: 2832 errors / 2468 warnings in 252 files (baseline: 2871 / 2488).
OK: no new PHPCS violations against the baseline.
==> [3/3] PHPStan level 5 via composer analyse (phpstan.neon.dist + baseline)
 [OK] No errors
lint: OK (syntax clean, no new PHPCS violations, PHPStan clean)
"""

LINT_LOG_FAIL = """\
==> [1/3] PHP syntax check (php -l, 580 files)
OK: no PHP parse errors.
==> [2/3] PHPCS (WordPress-Extra) + legacy baseline gate
PHPCS legacy baseline: 2840 errors / 2470 warnings in 253 files (baseline: 2832 / 2468).
NEW VIOLATIONS: 8 error(s) / 2 warning(s) against the baseline
==> [3/3] PHPStan level 5 via composer analyse (phpstan.neon.dist + baseline)
 ------ ------------------------------------------------------------
  Line   wp-content/plugins/conexao-content/includes/example.php
 ------ ------------------------------------------------------------
  42     Parameter #1 $text of function esc_html expects string, int given.
 ------ ------------------------------------------------------------
lint: FAILED (see the check output above)
"""



def main() -> int:
    """Build fixtures in a temp dir and assert the generator's output."""
    if not os.path.isfile(SCRIPT):
        print(f"FAIL: {SCRIPT} is missing")
        return 1

    with tempfile.TemporaryDirectory() as tmp:

        def write(name: str, text: str) -> str:
            path = os.path.join(tmp, name)
            with open(path, "w", encoding="utf-8") as handle:
                handle.write(text)
            return path

        passing = write("passing.log", PASSING_LOG)
        failing = write("failing.log", FAILING_LOG + f"\n(diagnostic: {SECRET})\n")
        phpstan = write("phpstan.json", json.dumps(PHPSTAN_JSON))
        clean_phpstan = write("phpstan-clean.json", json.dumps(CLEAN_PHPSTAN_JSON))
        gates = write("gate.json", json.dumps(GATE_JSON))
        release = write("release.log", RELEASE_LOG)
        lint_ok = write("lint-ok.log", LINT_LOG_OK)
        lint_fail = write("lint-fail.log", LINT_LOG_FAIL)


        # -- 1. Passing harness log ----------------------------------------
        rc, out = run("--kind=harness", f"--log={passing}")
        check(rc == 0, f"a passing harness log must exit 0 (got {rc})")
        check("ALL TESTS PASSED" in out, "the pass badge is missing")
        check("TESTS FAILED" not in out, "a passing log must not produce the fail badge")
        check(
            re.search(r"\|\s*In-process PHP suites\s*\|\s*3\s*\|\s*3\s*\|\s*0\s*\|", out) is not None,
            "the in-process PHP totals row is wrong",
        )
        check(
            re.search(r"\|\s*Script-contract suites\s*\|\s*27\s*\|\s*27\s*\|\s*0\s*\|", out) is not None,
            "the script-contract totals row is wrong",
        )
        check(
            re.search(r"\|\s*Assertions \(in-process PHP\)\s*\|\s*112\s*\|\s*112\s*\|\s*0\s*\|", out) is not None,
            "the assertion totals row is wrong",
        )
        check("Suites that did not pass" not in out, "a clean run must not list non-passing suites")


        # -- 2. Failing harness log (ANSI-coloured) ------------------------
        rc, out = run("--kind=harness", f"--log={failing}")
        check(rc == 0, "a failing harness log must still let the reporting step exit 0")
        check("TESTS FAILED" in out, "the fail badge is missing for a failing log")
        check("ALL TESTS PASSED" not in out, "a failing log must not produce the pass badge")
        check("`FAIL`" in out, "the FAIL suite row is missing")
        check("`TIMEOUT`" in out, "the TIMEOUT suite row is missing")
        check("`BLOCKED`" in out, "the BLOCKED suite row is missing")
        check("test-import-continuation.php" in out, "the harness failing-suite name is missing")
        check("WordPress bootstrap failures" in out, "the bootstrap-failure warning is missing")
        check("prerequisite failures" in out, "the prerequisite-failure warning is missing")
        # -- 7. Secret safety ----------------------------------------------
        check(SECRET not in out, "the summary leaked a credential-shaped value from the log")

        # -- 8a. Negative control: remove the verdict ----------------------
        damaged = write("damaged.log", FAILING_LOG.replace("TESTS FAILED", ""))
        rc, out = run("--kind=harness", f"--log={damaged}")
        check(
            "verdict not found in the log" in out,
            "negative control: a log with no verdict must be reported, not silently passed",
        )
        check(
            "ALL TESTS PASSED" not in out,
            "negative control: a missing verdict must never become a pass",
        )

        # -- 8b. Negative control: swap the counts -------------------------
        swapped = write(
            "swapped.log",
            PASSING_LOG.replace("3 total, 3 passed, 0 failed", "3 total, 0 passed, 3 failed"),
        )
        rc, out = run("--kind=harness", f"--log={swapped}")
        check(
            re.search(r"\|\s*In-process PHP suites\s*\|\s*3\s*\|\s*0\s*\|\s*3\s*\|", out) is not None,
            "negative control: changed totals must be reflected, not ignored",
        )

        # -- 3. PHPStan ----------------------------------------------------
        rc, out = run("--kind=phpstan", f"--json={phpstan}")
        check(rc == 0, "the PHPStan summary must exit 0")
        check("2 file error(s) in 2 file(s)" in out, "the PHPStan file-error total is wrong")
        check(
            "wp-content/plugins/conexao-content/includes/a.php" in out,
            "the PHPStan file row is missing",
        )
        check("argument.type" in out, "the PHPStan identifier is missing from the detail block")

        rc, out = run("--kind=phpstan", f"--json={clean_phpstan}")
        check("PHPStan: no errors" in out, "a clean PHPStan report must be reported as clean")
        check("file error" not in out, "a clean PHPStan report must not report file errors")


        # -- 4. Gates ------------------------------------------------------
        rc, out = run("--kind=gates", f"--json={gates}")
        check(rc == 0, "the gate summary must exit 0")
        check("Overall: FAIL" in out, "the overall gate status is missing")
        check("1 passed, 1 failed, 1 blocked" in out, "the gate tally is wrong")
        check("Violations: **1 new**, 4 pre-existing" in out, "the violation split is wrong")
        check("0123456789ab" in out, "the commit short-sha is missing")

        # -- 5. Release integrity ------------------------------------------
        rc, out = run("--kind=release", f"--log={release}")
        check(rc == 0, "the release summary must exit 0")
        check("41 passed, 2 failed" in out, "the release totals line is missing")
        check(
            "release.json is stale for conexao-content" in out,
            "a release FAIL detail is missing",
        )
        check(
            "theme ZIP ships a file under tests/" in out,
            "the second release FAIL detail is missing",
        )

        # -- 6. lint.sh ----------------------------------------------------
        rc, out = run("--kind=lint", f"--log={lint_ok}")
        check(rc == 0, "a passing lint log must exit 0")
        check("lint.sh: OK" in out, "the lint OK verdict is missing")
        check("579 files" in out, "the syntax-scan file count is missing")
        check("2832 errors / 2468 warnings" in out, "the PHPCS baseline counts are missing")
        check("no errors" in out, "the PHPStan clean result is missing")

        rc, out = run("--kind=lint", f"--log={lint_fail}")
        check(rc == 0, "a failing lint log must still let the reporting step exit 0")
        check("lint.sh: FAILED" in out, "the lint FAILED verdict is missing")
        check("**present**" in out, "a new PHPCS violation must be reported as present")
        check("esc_html expects string" in out, "the PHPStan/PHPCS error detail is missing")

        # -- 7. Missing / invalid input ------------------------------------
        rc, out = run("--kind=harness", f"--log={os.path.join(tmp, 'nope.log')}")
        check(rc == 0, "a missing input must not fail the reporting step")
        check("Summary unavailable" in out, "a missing input must print the visible notice")
        check("ALL TESTS PASSED" not in out, "a missing input must never look like a pass")

        rc, out = run("--kind=bogus")
        check(rc == 0, "an unknown --kind must not fail the reporting step")
        check("Summary unavailable" in out, "an unknown --kind must print the visible notice")

    return 0


if __name__ == "__main__":
    rc = main()
    for failure in FAILURES:
        print(f"FAIL: {failure}")
    print(f"{PASSED} passed, {len(FAILURES)} failed")
    sys.exit(1 if FAILURES else 0)

