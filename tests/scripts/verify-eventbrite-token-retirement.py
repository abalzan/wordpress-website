#!/usr/bin/env python3
"""G7 gate — the dead Eventbrite API-token path stays retired.

A STATIC repository check. It loads no WordPress, opens no socket and can
never contact production: it only reads files in the local checkout.

## Why this gate exists

G7 retired the `eventbrite_api_token` plumbing. Deleting the variable proves
nothing on its own: a single surviving reference in a settings default, an admin
field, a `.env` template or a maintainer instruction would silently restore the
false prerequisite the stage set out to remove.

So the retirement needs a standing check, exactly like every other invariant in
this repository. The rule it enforces is the live-vs-historical distinction:

  LIVE / OPERATIONAL  -> zero tolerance. Runtime code, configuration, tests,
                         fixtures and CURRENT maintainer documentation must not
                         reference the retired token.
  HISTORICAL          -> allowed and REQUIRED. G6's report and evidence record
                         that the token existed and was dead; deleting that
                         history to obtain a clean grep would destroy evidence.

A repository that merely greps clean is not necessarily correct (history may
have been deleted), and a repository with history is not necessarily wrong. This
gate classifies every occurrence and fails only on the first category.

## What it checks

  1. No shipped PHP implementation file mentions the retired key in EXECUTABLE
     code (comments are excluded: they record why it was removed).
  2. No live configuration surface (.env.example, compose.yaml) declares it.
  3. The importer's test tree does not require the token path to exist.
  4. Current maintainer documentation carries no instruction to configure an
     Eventbrite token, and no API-mode claim.
  5. Historical G6 evidence still EXISTS and is still truthful.
  6. No Eventbrite credential is committed anywhere in the repository.

Exit code 0 when the retirement holds, 1 when a live reference reappears.
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

GATE_ID = "eventbrite_token_retirement"

# The retired key.
TOKEN_KEY = "eventbrite_api_token"

# The G7 in-process suite, relative to the repository root.
IMPORTER_REL_TESTS = os.path.join(
    "wp-content", "plugins", "conexao-event-importer", "tests"
)

# Directories whose contents are historical records, not live behaviour.
HISTORICAL_DIRS = ("docs/evidence", "docs/reports", "docs/importers")

# Current, operational maintainer documentation that must not instruct a token.
CURRENT_DOCS = (
    os.path.join("docs", "plugins", "conexao-event-importer.md"),
    os.path.join("docs", "plugins", "conexao-event-runtime.md"),
    os.path.join("docs", "README.md"),
    "AGENTS.md",
    "README.md",
    os.path.join("docs", "testing.md"),
    os.path.join("docs", "development.md"),
    os.path.join("docs", "deployment.md"),
    os.path.join("docs", "architecture.md"),
)

# Instruction shapes a maintainer could follow to configure a token.
FORBIDDEN_DOC_PATTERNS = (
    re.compile(r"set a personal OAuth token", re.I),
    re.compile(r"Create a free token at", re.I),
    re.compile(r"Settings\s*[—-]\s*Eventbrite API token", re.I),
    re.compile(r"two fetch modes", re.I),
    re.compile(r"https://www\.eventbriteapi\.com", re.I),
)

# The G7 suite itself NAMES the key while asserting its absence; that is the
# intended use, not a live dependency.
G7_SUITE_NAMES = (
    "test-eventbrite-token-retirement.php",
    "verify-eventbrite-token-retirement.py",
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


def strip_php_comments(source: str) -> str:
    """Remove PHP comments/docblocks so only executable code is scanned.

    A comment explaining the retirement is documentation, not a live reference.
    Counting it as live would force the removal rationale to be deleted; ignoring
    it entirely would let a real reference hide inside a comment. Stripping draws
    the line where it actually falls.
    """
    out = []
    i, n = 0, len(source)
    in_string = None
    while i < n:
        ch = source[i]
        if in_string:
            out.append(ch)
            if ch == "\\" and i + 1 < n:
                out.append(source[i + 1])
                i += 2
                continue
            if ch == in_string:
                in_string = None
            i += 1
            continue
        if ch in "'\"":
            in_string = ch
            out.append(ch)
            i += 1
            continue
        pair = source[i : i + 2]
        if pair == "//":
            j = source.find("\n", i)
            i = n if j == -1 else j
            continue
        if pair == "/*":
            j = source.find("*/", i + 2)
            i = n if j == -1 else j + 2
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def relative(path: str) -> str:
    return os.path.relpath(path, REPO_ROOT)


def is_historical(rel_path: str) -> bool:
    normalised = rel_path.replace(os.sep, "/")
    return any(normalised.startswith(d + "/") for d in HISTORICAL_DIRS)


RETIREMENT_VOCABULARY = (
    "retired",
    "removed",
    "no longer",
    "never read",
    "never did",
    "was dead",
    "dead token",
    "not viable",
    "dead-path",
    "dead path",
)


def occurrence_is_retirement_note(body: str, index: int) -> bool:
    """Whether the mention at `index` is framed as retired rather than as advice.

    Naming a retired key is legitimate and necessary: a maintainer reading the
    changelog needs to learn the setting is GONE. What is forbidden is a
    mention that reads as an instruction or as a live prerequisite. The two are
    separated by the sentence around the mention, so the whole sentence is
    examined for retirement vocabulary.
    """
    start = body.rfind(".", 0, index)
    start = 0 if start == -1 else start + 1
    for terminator in (".", "\n"):
        end = body.find(terminator, index)
        end = len(body) if end == -1 else end
        sentence = body[start:end]
        lowered = sentence.lower()
        if any(word in lowered for word in RETIREMENT_VOCABULARY):
            return True
        break
    return False


def all_repository_files() -> list[str]:
    """Tracked files PLUS untracked-but-present files.

    G6's report and evidence are not committed yet, so `git ls-files` alone would
    classify zero historical references and this gate would pass for the wrong
    reason. Untracked files must be visible to it.
    """
    collected: list[str] = []
    for args in (
        ["git", "ls-files"],
        ["git", "ls-files", "--others", "--exclude-standard"],
    ):
        proc = subprocess.run(
            args,
            cwd=REPO_ROOT,
            capture_output=True,
            text=True,
            check=False,
        )
        if proc.returncode == 0 and proc.stdout.strip():
            collected.extend(
                line for line in proc.stdout.splitlines() if line.strip()
            )
    if collected:
        return sorted(set(collected))
    # Fall back to a walk when git cannot answer (e.g. an exported tree).
    found = []
    for dirpath, dirnames, filenames in os.walk(REPO_ROOT):
        dirnames[:] = [
            d for d in dirnames if d not in (".git", "node_modules", "__pycache__", "vendor")
        ]
        for name in filenames:
            found.append(relative(os.path.join(dirpath, name)))
    return sorted(found)


def read(path: str) -> str:
    try:
        with open(path, encoding="utf-8", errors="replace") as handle:
            return handle.read()
    except OSError:
        return ""



def classify_references(tracked: list[str]) -> tuple[list[str], list[str]]:
    """Split every occurrence of the retired key into live vs historical."""
    live: list[str] = []
    historical: list[str] = []

    for rel in tracked:
        absolute = os.path.join(REPO_ROOT, rel)
        if not os.path.isfile(absolute):
            continue
        body = read(absolute)
        if TOKEN_KEY not in body:
            continue

        normalised = rel.replace(os.sep, "/")

        if is_historical(normalised):
            historical.append(normalised)
            continue

        # The G7 suites name the key on purpose, while asserting its absence.
        if normalised.endswith(G7_SUITE_NAMES):
            continue

        # Shipped implementation: judge executable code only, so an explanatory
        # docblock recording the retirement is not counted as a dependency.
        if normalised.startswith(
            "wp-content/plugins/conexao-event-importer/includes/"
        ) and normalised.endswith(".php"):
            if TOKEN_KEY not in strip_php_comments(body):
                continue

        # Current maintainer documentation may NAME the retired key, but only to
        # record that it is gone. A mention framed as advice, or as a live
        # prerequisite, is a live reference and fails.
        if normalised in CURRENT_DOCS:
            position = body.find(TOKEN_KEY)
            framed_as_retired = True
            while position != -1:
                if not occurrence_is_retirement_note(body, position):
                    framed_as_retired = False
                    break
                position = body.find(TOKEN_KEY, position + 1)
            if framed_as_retired:
                continue

        live.append(normalised)

    return live, historical


def main() -> int:
    print("== G7 Eventbrite API-token retirement gate ==")
    print(f"   repo:  {REPO_ROOT}")

    tracked = all_repository_files()
    check(len(tracked) > 0, "the repository file list is resolvable")

    live_hits, historical_hits = classify_references(tracked)

    print(f"   files scanned:                     {len(tracked)}")
    print(f"   historical references retained:    {len(historical_hits)}")
    print(f"   LIVE references:                   {len(live_hits)}")

    check(
        not live_hits,
        "no live (non-historical) file references the retired token key: "
        + ", ".join(sorted(live_hits)),
    )

    # The historical record must still EXIST. A clean grep achieved by deleting
    # G6's evidence is a FAILURE, not a pass.
    g6_report = os.path.join(
        REPO_ROOT, "docs", "reports", "2026-10-04-eventbrite-api-feasibility.md"
    )
    check(
        os.path.isfile(g6_report),
        "the G6 feasibility report still exists (history must not be deleted to pass)",
    )
    g6_evidence = os.path.join(
        REPO_ROOT, "docs", "evidence", "2026-10-04-eventbrite-api-g6"
    )
    check(
        os.path.isdir(g6_evidence) and bool(os.listdir(g6_evidence)),
        "the G6 evidence directory still exists and is non-empty",
    )

    g6_body = read(g6_report)
    check(
        TOKEN_KEY in g6_body,
        "the G6 report still records the retired key (its finding stays truthful)",
    )
    check(
        "NOT VIABLE" in g6_body,
        "the G6 report still carries its NOT VIABLE verdict",
    )

    # Current operational documentation must not instruct a token setup.
    for rel in CURRENT_DOCS:
        absolute = os.path.join(REPO_ROOT, rel)
        if not os.path.isfile(absolute):
            continue
        body = read(absolute)
        for pattern in FORBIDDEN_DOC_PATTERNS:
            check(
                not pattern.search(body),
                f"{rel} carries no Eventbrite-token instruction "
                f"(matched {pattern.pattern!r})",
            )

    # The importer doc must positively state the credential-free model.
    importer_doc = os.path.join(REPO_ROOT, "docs", "plugins", "conexao-event-importer.md")
    importer_body = read(importer_doc)
    if importer_body:
        lowered = importer_body.lower()
        check(
            "no eventbrite credential" in lowered or "no eventbrite api token" in lowered,
            "the importer doc states that no Eventbrite credential is required",
        )
        check(
            "reopening the question" in lowered,
            "the importer doc records the condition for reopening the API question",
        )
        check(
            "not viable" in lowered,
            "the importer doc carries the G6 NOT VIABLE conclusion",
        )

    # No committed Eventbrite credential value anywhere.
    credential_pattern = re.compile(
        r"eventbrite[_-]?(api|oauth|private|access)?[_-]?token\s*[:=]\s*['\"][A-Za-z0-9_\-]{16,}",
        re.I,
    )
    credential_hits = [
        rel.replace(os.sep, "/")
        for rel in tracked
        if os.path.isfile(os.path.join(REPO_ROOT, rel))
        and credential_pattern.search(read(os.path.join(REPO_ROOT, rel)))
    ]
    check(
        not credential_hits,
        "no Eventbrite credential value is committed anywhere: " + ", ".join(credential_hits),
    )

    for surface in (".env.example", "compose.yaml"):
        body = read(os.path.join(REPO_ROOT, surface))
        if body:
            check(TOKEN_KEY not in body, f"{surface} declares no Eventbrite API token")

    # The G7 runtime suite and this gate must both exist and be discoverable.
    check(
        os.path.isfile(
            os.path.join(
                IMPORTER_REL_TESTS, "test-eventbrite-token-retirement.php"
            )
        ),
        "the G7 in-process negative-proof suite exists",
    )
    check(
        os.path.isfile(os.path.abspath(__file__)),
        "the G7 repository retirement gate exists",
    )

    print()
    for failure in FAILURES:
        print(f"  FAIL: {failure}")
    print(f"\n  {PASSED} passed, {len(FAILURES)} failed")

    result = {
        "gate": GATE_ID,
        "passed": PASSED,
        "failed": len(FAILURES),
        "historical_references_retained": len(historical_hits),
        "live_references": len(live_hits),
        "failures": FAILURES,
    }
    print("GATE-RESULT " + json.dumps(result))

    return 1 if FAILURES else 0


if __name__ == "__main__":
    sys.exit(main())

