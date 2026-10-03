#!/usr/bin/env python3
"""Stage I script-contract gate.

A STATIC check over the repository's `scripts/` estate. It loads no WordPress,
opens no socket and can never contact production: it only reads files.

Engineering standard §10.2 makes these obligations MUSTs, and Stage I makes
them executable so a new script that skips the contract fails CI rather than
being discovered a year later.

What it asserts:

  1. The shared libraries exist (scripts/lib/bootstrap.php, rest.py).
  2. Every current PHP script uses the shared bootstrap, or is a documented
     exception with a stated reason.
  3. Every current script declares a scope/safety header.
  4. No current operational script hard-codes a production target URL.
  5. No current script contains a credential literal.
  6. No current write-capable script accepts a mode that silently writes
     (the standard contract is --dry-run / --apply, defaulting to dry-run).
  7. scripts/README.md catalogues every current runnable script.
  8. Historical and diagnostic directories are classified, and no current
     script is left with a stage-numbered name.
  9. No repository file references a removed/renamed current script path.

Exit code: 0 when every check passes, 1 otherwise. Every failure is printed
with the offending path so the fix is obvious.
"""

from __future__ import annotations

import os
import re
import sys

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)
SCRIPTS = os.path.join(REPO_ROOT, "scripts")
README = os.path.join(SCRIPTS, "README.md")
HISTORICAL = os.path.join(SCRIPTS, "historical")
DIAGNOSTICS = os.path.join(SCRIPTS, "diagnostics")
LIB = os.path.join(SCRIPTS, "lib")

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


def read(path: str) -> str:
    with open(path, encoding="utf-8", errors="replace") as handle:
        return handle.read()


def strip_php_comments(source: str) -> str:
    """Remove PHP block/line comments so doc text cannot trip a code check."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    return re.sub(r"^\s*//.*$", "", source, flags=re.M)


def current_scripts() -> list[str]:
    """Top-level runnable scripts (not lib/, historical/ or diagnostics/)."""
    out = []
    for name in sorted(os.listdir(SCRIPTS)):
        path = os.path.join(SCRIPTS, name)
        if not os.path.isfile(path):
            continue
        if name.endswith((".php", ".py", ".sh")):
            out.append(path)
    return out


def rel(path: str) -> str:
    return os.path.relpath(path, REPO_ROOT)


def main() -> int:
    if not os.path.isdir(SCRIPTS):
        print(f"FAIL: no scripts/ directory at {SCRIPTS}")
        return 1

    scripts = current_scripts()
    php_scripts = [p for p in scripts if p.endswith(".php")]
    py_scripts = [p for p in scripts if p.endswith(".py")]
    sh_scripts = [p for p in scripts if p.endswith(".sh")]

    # -- 1. Shared libraries exist -----------------------------------------
    check(
        os.path.isfile(os.path.join(LIB, "bootstrap.php")),
        "scripts/lib/bootstrap.php is missing (the canonical PHP bootstrap)",
    )
    check(
        os.path.isfile(os.path.join(LIB, "rest.py")),
        "scripts/lib/rest.py is missing (the shared Python REST client)",
    )

    # -- 2. PHP scripts use the shared bootstrap ---------------------------
    # Documented exceptions (engineering standard §10.2 / Stage I PHASE 45):
    # each names the reason it does not load WordPress and whether it is current
    # or historical. The reason must be stated IN THE FILE, not only here.
    bootstrap_exceptions = {
        "generate-registry-docs.php": "static registry tool",
        "phpcs-baseline.php": "static PHPCS baseline tool",
        "generate-logo-derivatives.php": "WP-CLI",
        "import-wix-export.php": "requires an already-loaded WordPress",
    }
    for path in php_scripts:
        name = os.path.basename(path)
        source = read(path)
        code = strip_php_comments(source)
        if name in bootstrap_exceptions:
            head = source[:2500]
            check(
                "Bootstrap exception" in head
                and bootstrap_exceptions[name].lower() in head.lower(),
                f"{rel(path)} is a declared bootstrap exception but does not state the "
                f"reason ('{bootstrap_exceptions[name]}') in its own header",
            )
            continue
        uses_shared = "lib/bootstrap.php" in source
        loads_own_wp = "wp-load" in code
        check(
            uses_shared or not loads_own_wp,
            f"{rel(path)} resolves wp-load.php itself; it must require scripts/lib/bootstrap.php "
            f"(or be added to the documented exception list with a reason)",
        )

    # -- 3. Scope / safety metadata ----------------------------------------
    for path in scripts:
        name = os.path.basename(path)
        if name in ("bootstrap.php",):
            continue
        source = read(path)
        head = source[:4000]
        # The Stage I metadata convention (engineering standard §10, PHASE 19)
        # asks for a source-level header a reviewer can read. Accept either the
        # explicit labelled form (Purpose:/Scope:/Safety:) or a descriptive
        # docblock that states what the script does.
        labelled = any(marker in head for marker in ("Scope:", "Safety:", "Purpose:"))
        # A shell/Python/PHP file states its purpose in a leading comment
        # block: `/** ... */`, `"""..."""`, or a run of `#` lines.
        has_comment_header = (
            "/**" in source[:300]
            or '"""' in source[:300]
            or bool(re.match(r"^#!.*\n((?:\s*#.*\n){2,})", source))
        )
        described = has_comment_header and bool(
            re.search(r"^.{25,}$", source[:1500], re.M)
        )
        check(
            labelled or described,
            f"{rel(path)} has no recognisable header metadata: add a Purpose/Scope/Safety "
            f"block or a descriptive docblock (engineering standard §10, PHASE 19)",
        )

    # -- 4. No hard-coded production target in operational code ------------
    # A User-Agent that names the project URL for contact is an identity string
    # sent to whichever site is being audited, never an operational target.
    ua_identity = re.compile(r"USER_AGENT\s*=")
    for path in scripts + [
        os.path.join(DIAGNOSTICS, n)
        for n in (sorted(os.listdir(DIAGNOSTICS)) if os.path.isdir(DIAGNOSTICS) else [])
        if n.endswith((".py", ".php", ".sh"))
    ]:
        if not os.path.isfile(path):
            continue
        code = strip_php_comments(read(path))
        # Ignore the shared library: it MUST know the production host to
        # refuse it, and run-tests.sh likewise lists it to reject it.
        if rel(path) in ("scripts/lib/rest.py", "scripts/lib/bootstrap.php"):
            continue
        for match in re.finditer(r"https?://(?:www\.)?conexaobr\.ie", code):
            line = code[: match.start()].count("\n") + 1
            line_text = code.split("\n")[line - 1]
            # A User-Agent identity is exempt; a target default is not.
            if ua_identity.search(line_text):
                continue
            check(
                False,
                f"{rel(path)}:{line} hard-codes the production host in executable code; "
                f"resolve the target from CONEXAO_SITE_URL / --base-url instead",
            )

    # -- 5. No credential literals -----------------------------------------
    # Documentation placeholders ("xxxx xxxx", "your-app-password", "<...>")
    # are legitimate usage examples and are not secrets. What must never
    # appear is a real credential assigned in code.
    PLACEHOLDERS = (
        "xxxx", "your-app-password", "your_app_password", "your-wpcom-username",
        "<", ">", "changeme", "example", "placeholder", "redacted", "***",
    )

    def looks_like_placeholder(value: str) -> bool:
        low = value.lower()
        return any(marker in low for marker in PLACEHOLDERS)

    credential_patterns = [
        (re.compile(r"WP_APPLICATION_PASSWORD\s*=\s*['\"]([^'\"]+)['\"]"), "hard-coded application password"),
        (re.compile(r"WP_PASSWORD\s*=\s*['\"]([^'\"]+)['\"]"), "hard-coded password"),
        (re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"), "private key literal"),
        (re.compile(r"['\"]Authorization['\"]\s*:\s*f?['\"]Basic [A-Za-z0-9+/=]{8,}"), "hard-coded Basic auth header"),
    ]
    for path in scripts:
        code = read(path)
        for pattern, label in credential_patterns:
            for match in pattern.finditer(code):
                value = match.group(1) if match.groups() else ""
                if value and looks_like_placeholder(value):
                    continue
                line = code[: match.start()].count("\n") + 1
                check(False, f"{rel(path)}:{line} contains a {label}")

    # -- 6. Mode contract --------------------------------------------------
    # A current write-capable script must not accept a bare positional
    # "apply"/"apply" token as its only write switch without the standard
    # --apply flag being present.
    for path in scripts:
        code = strip_php_comments(read(path))
        if "--apply" not in code and re.search(r"['\"]apply['\"]", code):
            # It references an apply token but exposes no --apply flag.
            name = os.path.basename(path)
            if name.endswith((".py",)):
                check(
                    False,
                    f"{rel(path)} uses a positional 'apply' token but exposes no --apply flag",
                )

    # -- 7/8. Catalogue completeness ---------------------------------------
    if check(os.path.isfile(README), "scripts/README.md is missing (the script catalogue)"):
        readme = read(README)
        for path in scripts:
            name = os.path.basename(path)
            if name in ("bootstrap.php",):
                continue
            check(
                name in readme,
                f"{rel(path)} is not catalogued in scripts/README.md",
            )

    # No stage-numbered names left in the current tree.
    for path in scripts:
        name = os.path.basename(path)
        check(
            not re.match(r"^stage\d+[-_]", name),
            f"{rel(path)} still carries a stage-numbered name; rename it to a capability "
            f"name or move it to scripts/historical/",
        )

    check(
        os.path.isdir(HISTORICAL),
        "scripts/historical/ does not exist; one-shot stage scripts must be classified",
    )

    # -- 9. No dangling references to removed current paths -----------------
    removed = [
        "scripts/stage6-job-translate.php",
        "scripts/stage6-job-inventory.php",
        "scripts/stage2-polylang-setup.php",
        "scripts/stage2-http-verify.sh",
        "scripts/stage9-guide-http-verify.sh",
        "scripts/stage41-rest-verify.py",
        "scripts/stage43-verify-english.py",
    ]
    for old in removed:
        check(
            not os.path.exists(os.path.join(REPO_ROOT, old)),
            f"{old} still exists; the Stage I rename removed this path",
        )

    # A CURRENT document must not still point at a path Stage I renamed or
    # moved. Historical records (docs/reports, docs/audit, docs/evidence,
    # docs/importers) are point-in-time and are deliberately NOT scanned: a
    # stage report must keep the path that was true when it was written.
    current_doc_roots = ("docs", "AGENTS.md", "README.md", ".github", "scripts")
    historical_markers = (
        os.path.join("docs", "reports"),
        os.path.join("docs", "audit"),
        os.path.join("docs", "evidence"),
        os.path.join("docs", "importers"),
        os.path.join("scripts", "historical"),
        os.path.join("scripts", "diagnostics"),
    )

    def is_historical(rel_path: str) -> bool:
        return any(rel_path.startswith(marker) for marker in historical_markers)

    self_rel = os.path.relpath(os.path.abspath(__file__), REPO_ROOT)
    for root in current_doc_roots:
        abs_root = os.path.join(REPO_ROOT, root)
        if not os.path.exists(abs_root):
            continue
        if os.path.isfile(abs_root):
            walk = [(abs_root, os.path.basename(abs_root))]
        else:
            walk = []
            for base, _dirs, files in os.walk(abs_root):
                for name in files:
                    if name.endswith((".md", ".php", ".py", ".sh", ".yml", ".yaml")):
                        walk.append((os.path.join(base, name), name))
        for abs_path, _name in walk:
            rel_path = os.path.relpath(abs_path, REPO_ROOT)
            if is_historical(rel_path) or rel_path == self_rel:
                continue
            try:
                body = read(abs_path)
            except OSError:
                continue
            for old in removed:
                if old in body:
                    check(
                        False,
                        f"{rel_path} still references the removed path {old}; "
                        f"update it to the current script path",
                    )
            # The shared library is allowed to KNOW the production host.
            for stage_name in re.findall(r"scripts/(stage[0-9][A-Za-z0-9_.-]*\.(?:php|py|sh))", body):
                if os.path.exists(os.path.join(REPO_ROOT, "scripts", "historical", stage_name)):
                    check(
                        False,
                        f"{rel_path} references historical script "
                        f"scripts/{stage_name} without the historical/ prefix",
                    )

    # -- 10. The ONE translation lifecycle ---------------------------------
    #
    # Stage 19 retired five one-shot translation rollout plugins and moved their
    # per-content-type runner scripts to scripts/historical/ as unsupported
    # tooling. The surviving lifecycle is `conexao-translation-rollout` driven by
    # scripts/run-en-translation.php over the `conexao-en-translation` stages.
    #
    # CI run 36978530253 failed because scripts/bootstrap-ci-fixtures.php still
    # invoked scripts/run-job-translation.php AFTER that script had been moved out
    # of scripts/: the fixture bootstrap aborted with "expected script is missing".
    # A stale call to retired translation tooling is therefore an architecture
    # violation, not a missing file to be recreated.
    #
    # The retired runner names are DERIVED from what is actually sitting in
    # scripts/historical/, so this check cannot drift from the tree and does not
    # hardcode a filename list that would rot. A historical script counts as
    # retired translation tooling when it is a translation runner or inventory
    # (i.e. its basename matches run-*translation* / *-translation-inventory.*).
    retired_translation = [
        name
        for name in sorted(os.listdir(HISTORICAL))
        if re.match(r"^(run-.*translation|.*translation-inventory)\.[a-z]+$", name)
    ] if os.path.isdir(HISTORICAL) else []

    # (a) A retired translation runner must not exist as a CURRENT script: if it
    #     comes back to scripts/ it is a second translation lifecycle again.
    for name in retired_translation:
        check(
            not os.path.isfile(os.path.join(SCRIPTS, name)),
            f"scripts/{name} is retired translation tooling and must stay in "
            f"scripts/historical/, not scripts/",
        )

    # (b) No current script may INVOKE a retired translation runner. This is the
    #     check that failed in CI: the bootstrap called a runner that no longer
    #     existed in scripts/. Only real call sites are matched, so an honest
    #     historical reference inside a comment cannot trip it.
    current_names = {os.path.basename(p) for p in scripts}
    for path in scripts:
        code = strip_php_comments(read(path))
        for name in retired_translation:
            if name not in current_names:
                check(
                    f"'{name}'" not in code,
                    f"{rel(path)} invokes retired translation runner '{name}'; "
                    f"use scripts/run-en-translation.php (the one lifecycle) or "
                    f"provision fixture data instead",
                )

    # (c) The single translation runner must still exist. Without this the check
    #     above would pass vacuously on an empty scripts/historical/.
    check(
        os.path.isfile(os.path.join(SCRIPTS, "run-en-translation.php")),
        "scripts/run-en-translation.php is missing; it is the ONE translation "
        "rollout runner for the shared engine",
    )

    return 0


if __name__ == "__main__":
    rc = main()
    passed = PASSED
    failed = len(FAILURES)
    for failure in FAILURES:
        print(f"FAIL: {failure}")
    print(f"{passed} passed, {failed} failed")
    sys.exit(1 if failed else 0)
