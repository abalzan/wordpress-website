#!/usr/bin/env python3
"""Stage 0.5 gate — operator-log isolation is structural, not remembered.

A STATIC repository check. It loads no WordPress, opens no socket, starts no
container and reads no database: it only reads files in the local checkout.

## Why this gate exists

The Stage 0.5 storage seam redirects the four operator stores (import log,
import history, cleanup status, cleanup history) to a per-process test option.
That property is easy to lose in one careless edit: a suite that isolates a
redirected store again, a child that inherits the parent suffix, a
`delete_option()` on a production key, a `PARALLEL` re-promotion.

Fourteen checks make each of those a build failure instead of a future incident.
The gate is discovered by convention (`tests/scripts/verify-*.py`), so it needs
no registration.

## What it deliberately does NOT prove

Runtime behaviour. "A test process really writes the test row" is proven by the
acceptance suite (`test-operator-log-isolation.php`), and the live-hash
comparison in the Stage 0.5 report. Static proof of the *structure* plus runtime
proof of the *behaviour* is the contract; neither is claimed to be the other.

Exit code 0 when every check passes, 1 on a structural regression or a missing
prerequisite.
"""

from __future__ import annotations

import os
import re
import sys

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)

GATE_ID = "operator_log_isolation"

FAILURES: list[str] = []
PASSED = 0

BOOTSTRAP = "tests/bootstrap.php"
HELPER = "tests/lib/option-isolation.php"
RUNNER = "scripts/run-tests.sh"

IMPORTER_TESTS = "wp-content/plugins/conexao-event-importer/tests"

# The four protected stores: symbolic key -> the facts the gate pins.
STORES = {
    "import_log": {
        "file": "wp-content/plugins/conexao-event-importer/includes/class-import-log.php",
        "class": "Conexao_Import_Log",
        "method": "option_key",
        "const": "OPTION_KEY",
        "literal": "conexao_event_import_log",
        "prefix_const": "TEST_OPTION_PREFIX",
        "prefix": "conexao_event_import_log__test__",
    },
    "import_history": {
        "file": "wp-content/plugins/conexao-event-importer/includes/class-import-history.php",
        "class": "Conexao_Import_History",
        "method": "option_key",
        "const": "OPTION_KEY",
        "literal": "conexao_event_import_history",
        "prefix_const": "TEST_OPTION_PREFIX",
        "prefix": "conexao_event_import_history__test__",
    },
    "cleanup_status": {
        "file": "wp-content/plugins/conexao-event-importer/includes/class-event-cleanup.php",
        "class": "Conexao_Event_Cleanup",
        "method": "status_option",
        "const": "STATUS_OPTION",
        "literal": "conexao_event_cleanup_status",
        "prefix_const": "STATUS_TEST_OPTION_PREFIX",
        "prefix": "conexao_event_cleanup_status__test__",
    },
    "cleanup_history": {
        "file": "wp-content/plugins/conexao-event-importer/includes/class-event-cleanup.php",
        "class": "Conexao_Event_Cleanup",
        "method": "history_option",
        "const": "HISTORY_OPTION",
        "literal": "conexao_event_cleanup_history",
        "prefix_const": "HISTORY_TEST_OPTION_PREFIX",
        "prefix": "conexao_event_cleanup_history__test__",
    },
}

PRODUCTION_LITERALS = {meta["literal"]: key for key, meta in STORES.items()}
TEST_PREFIXES = {meta["prefix"]: key for key, meta in STORES.items()}

GUARD_CALL = "test_require_operator_store_isolation()"

# Suites that MUST be in the store-touching set even if a refactor moved the
# reference: a renamed/moved tree fails loudly instead of scanning an empty set.
SENTINEL_SUITES = (
    "test-import-log.php",
    "test-event-cleanup-purge.php",
    "test-operator-log-isolation.php",
    "test-import-history-dashboard.php",
    "test-import-history-logs.php",
    "test-import-log-run-correlation.php",
)

# The exact §7.3 migration inventory (18 discovered suites) plus the
# non-discovered experiment. Used by check 14.
STORE_TOUCHING_SUITES = (
    "test-import-log.php",
    "test-import-log-run-correlation.php",
    "test-import-history-dashboard.php",
    "test-import-history-logs.php",
    "test-import-settings-retirement.php",
    "test-eventbrite-checkpoint.php",
    "test-error-handling.php",
    "test-eventbrite-api-contract.php",
    "test-eventbrite-rate-limit.php",
    "test-eventbrite-source-expansion.php",
    "test-eventbrite-token-retirement.php",
    "test-import-continuation.php",
    "test-request-budget.php",
    "test-request-retry-policy.php",
    "test-request-reuse.php",
    "test-source-fetch-failure.php",
    "test-source-pagination-completeness.php",
    "test-event-cleanup-purge.php",
    "g10-convergence-experiment.php",
)


def read(rel_path: str) -> str:
    full = os.path.join(REPO_ROOT, rel_path)
    if not os.path.isfile(full):
        return ""
    with open(full, "r", encoding="utf-8", errors="replace") as handle:
        return handle.read()


def exists(rel_path: str) -> bool:
    return os.path.isfile(os.path.join(REPO_ROOT, rel_path))


def fail(check_id: str, detail: str) -> None:
    FAILURES.append(f"FAIL {check_id}: {detail}")


def ok() -> None:
    global PASSED
    PASSED += 1


def check(condition: bool, check_id: str, detail: str) -> bool:
    if condition:
        ok()
        return True
    fail(check_id, detail)
    return False


def php_files(root_rel: str) -> list[str]:
    """Every .php file under a repository-relative directory."""
    root = os.path.join(REPO_ROOT, root_rel)
    found: list[str] = []
    for dirpath, _dirnames, filenames in os.walk(root):
        for filename in filenames:
            if filename.endswith(".php"):
                found.append(
                    os.path.relpath(os.path.join(dirpath, filename), REPO_ROOT)
                )
    return sorted(found)


def is_test_path(rel_path: str) -> bool:
    parts = rel_path.replace(os.sep, "/").split("/")
    return "tests" in parts


def production_php_files() -> list[str]:
    """Shipped PHP: wp-content plugins/themes and scripts, excluding test trees."""
    found = []
    for root in ("wp-content", "scripts"):
        for rel in php_files(root):
            if is_test_path(rel):
                continue
            found.append(rel)
    return found


def line_of(text: str, needle: str) -> int:
    """1-based line number of the first occurrence, or 0."""
    for index, line in enumerate(text.splitlines(), start=1):
        if needle in line:
            return index
    return 0


def strip_php_comments(text: str) -> str:
    """Blank out PHP comments while preserving every line number.

    Static checks must look at CODE, not prose: this repository documents the
    very names the gate guards, so a comment mentioning `run_cleanup()` or
    `delete_option( 'conexao_event_cleanup_history' )` is evidence of intent,
    not of behaviour. Blanking (rather than deleting) keeps line numbers honest.
    """
    out: list[str] = []
    index = 0
    length = len(text)
    state = "code"  # code | line | block | single | double

    while index < length:
        char = text[index]
        nxt = text[index + 1] if index + 1 < length else ""

        if state == "code":
            if char == "/" and nxt == "/":
                state = "line"
                out.append("  ")
                index += 2
                continue
            if char == "#":
                state = "line"
                out.append(" ")
                index += 1
                continue
            if char == "/" and nxt == "*":
                state = "block"
                out.append("  ")
                index += 2
                continue
            if char == "'":
                state = "single"
            elif char == '"':
                state = "double"
            out.append(char)
            index += 1
            continue

        if state == "line":
            if char == "\n":
                state = "code"
                out.append("\n")
            else:
                out.append(" ")
            index += 1
            continue

        if state == "block":
            if char == "*" and nxt == "/":
                state = "code"
                out.append("  ")
                index += 2
                continue
            out.append("\n" if char == "\n" else " ")
            index += 1
            continue

        # Inside a string literal: copy verbatim, honouring backslash escapes.
        if char == "\\":
            out.append(char)
            if index + 1 < length:
                out.append(text[index + 1])
            index += 2
            continue

        if (state == "single" and char == "'") or (state == "double" and char == '"'):
            state = "code"

        out.append(char)
        index += 1

    return "".join(out)



# ---------------------------------------------------------------------------
# Check 1 — the four production option names are the exact literals, declared
# as a const in their owning class, and appear as an option key nowhere else.
# ---------------------------------------------------------------------------
def check_1_production_names() -> None:
    for key, meta in STORES.items():
        body = read(meta["file"])
        if not check(
            bool(body),
            "1",
            f"missing prerequisite: {meta['file']}",
        ):
            continue

        check(
            re.search(
                r"const\s+" + re.escape(meta["const"]) + r"\s*=\s*'"
                + re.escape(meta["literal"]) + r"';",
                body,
            )
            is not None,
            "1",
            f"{meta['file']} does not declare const {meta['const']} = '{meta['literal']}'",
        )

    for rel in production_php_files():
        body = read(rel)
        for literal, key in PRODUCTION_LITERALS.items():
            owning = STORES[key]["file"]
            for number, line in enumerate(body.splitlines(), start=1):
                if literal not in line:
                    continue
                if rel == owning:
                    continue
                fail(
                    "1",
                    f"{rel}:{number} names the production option '{literal}' outside {owning}",
                )


# ---------------------------------------------------------------------------
# Check 2 — each class declares a TEST_OPTION_PREFIX that starts with its
# production name + '__test__' and is not equal to it.
# ---------------------------------------------------------------------------
def check_2_test_prefixes() -> None:
    for key, meta in STORES.items():
        body = read(meta["file"])
        pattern = (
            r"const\s+" + re.escape(meta["prefix_const"]) + r"\s*=\s*'"
            + re.escape(meta["prefix"]) + r"';"
        )
        check(
            re.search(pattern, body) is not None,
            "2",
            f"{meta['file']} does not declare const {meta['prefix_const']} = '{meta['prefix']}'",
        )
        check(
            meta["prefix"].startswith(meta["literal"] + "__test__"),
            "2",
            f"{key}: test prefix '{meta['prefix']}' does not start with '{meta['literal']}__test__'",
        )
        check(
            meta["prefix"] != meta["literal"],
            "2",
            f"{key}: test prefix equals the production option name",
        )


# ---------------------------------------------------------------------------
# Check 3 — each accessor keeps the production branch.
# ---------------------------------------------------------------------------
def check_3_production_branch() -> None:
    for key, meta in STORES.items():
        body = read(meta["file"])
        marker = f"function {meta['method']}("
        start = body.find(marker)
        if not check(start >= 0, "3", f"{meta['file']} has no {marker}"):
            continue

        end = body.find("\n\t}", start)
        accessor = body[start:end if end > start else len(body)]

        check(
            "! defined( 'CONEXAO_TESTS_ROOT' )" in accessor
            and "! defined( 'CONEXAO_TEST_OPTION_SUFFIX' )" in accessor,
            "3",
            f"{key}: the accessor does not guard on the two harness markers",
        )
        check(
            f"return self::{meta['const']};" in accessor,
            "3",
            f"{key}: the accessor has no production branch `return self::{meta['const']};`",
        )
        check(
            "throw new RuntimeException(" in accessor,
            "3",
            f"{key}: the accessor does not fail closed",
        )
        check(
            f"return self::{meta['prefix_const']} . $suffix;" in accessor,
            "3",
            f"{key}: the accessor does not return the test-prefixed key",
        )


# ---------------------------------------------------------------------------
# Check 4 — the bootstrap defines the suffix BEFORE it requires wp-load.php.
# ---------------------------------------------------------------------------
def check_4_bootstrap_order() -> None:
    body = read(BOOTSTRAP)
    if not check(bool(body), "4", f"missing prerequisite: {BOOTSTRAP}"):
        return

    define_line = line_of(body, "define( 'CONEXAO_TEST_OPTION_SUFFIX'")
    call_line = line_of(body, "conexao_tests_establish_isolation();")
    load_line = line_of(body, "require_once CONEXAO_TESTS_WP_ROOT . '/wp-load.php'")

    check(define_line > 0, "4", f"{BOOTSTRAP} never defines CONEXAO_TEST_OPTION_SUFFIX")
    check(call_line > 0, "4", f"{BOOTSTRAP} never calls conexao_tests_establish_isolation()")
    check(load_line > 0, "4", f"{BOOTSTRAP} has no wp-load.php require")

    if define_line and call_line and load_line:
        check(
            define_line < load_line,
            "4",
            f"the suffix is defined at line {define_line}, after the wp-load.php require at {load_line}",
        )
        check(
            call_line < load_line,
            "4",
            f"the establish call is at line {call_line}, after the wp-load.php require at {load_line}",
        )


# ---------------------------------------------------------------------------
# Check 5 — the bootstrap fails closed on every prerequisite.
# ---------------------------------------------------------------------------
def check_5_fail_closed() -> None:
    body = read(BOOTSTRAP)
    if not check(bool(body), "5", f"missing prerequisite: {BOOTSTRAP}"):
        return

    check("function conexao_tests_establish_isolation()" in body, "5", "the establish function is missing")
    check("function conexao_tests_verify_isolation()" in body, "5", "the post-load verification function is missing")
    check(
        "refusing a pre-defined CONEXAO_TEST_OPTION_SUFFIX" in body,
        "5",
        "the pre-defined-suffix refusal is missing",
    )
    check("getmypid()" in body, "5", "the pid validation is missing")
    check("random_bytes( 8 )" in body, "5", "the random token is missing")
    check(
        "/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/" in body,
        "5",
        "the suffix pattern is missing",
    )
    check(
        "wp_get_environment_type()" in body and "'production' === $environment" in body,
        "5",
        "the production-environment refusal is missing",
    )
    check(
        "function conexao_tests_is_local_host(" in body
        and "conexao_tests_is_local_host( $home_host )" in body,
        "5",
        "the production-environment refusal is missing its documented local-host exception",
    )
    check(
        "'conexaobr.ie'" in body and "'www.conexaobr.ie'" in body,
        "5",
        "the production-host refusal is missing",
    )
    check(
        body.count("conexao_tests_bootstrap_fail(") >= 6,
        "5",
        "several prerequisite failures do not route to conexao_tests_bootstrap_fail()",
    )
    check(
        "add_action( 'shutdown', 'conexao_tests_clean_option_stores', 999 )" in body,
        "5",
        "the store cleaner is not registered at shutdown priority 999",
    )


# ---------------------------------------------------------------------------
# Check 6 — every store-touching entry point requires the bootstrap, calls the
# guard, and calls it BEFORE its first store reference.
# ---------------------------------------------------------------------------
STORE_REFERENCE_RE = re.compile(
    r"Conexao_Import_Log::|Conexao_Import_History::|Conexao_Event_Cleanup::"
    r"|conexao_event_import_log\b|conexao_event_import_history\b"
    r"|conexao_event_cleanup_status\b|conexao_event_cleanup_history\b"
    r"|test_operator_store_|test_options_write_test_store\(|test_options_reset_test_store\("
)


def store_touching_suites() -> list[str]:
    suites = []
    for rel in php_files(IMPORTER_TESTS):
        body = read(rel)
        if STORE_REFERENCE_RE.search(body):
            suites.append(rel)
    return sorted(suites)


def check_6_entry_points() -> None:
    discovered = store_touching_suites()

    check(bool(discovered), "6", "no store-touching suite was discovered (the scan is broken)")

    names = {os.path.basename(rel) for rel in discovered}
    for sentinel in SENTINEL_SUITES:
        check(
            sentinel in names,
            "6",
            f"missing prerequisite: the discovered set does not contain the sentinel {sentinel}",
        )

    # The exact §7.3 inventory is required in addition to whatever the scan
    # discovers: a suite can touch a store indirectly (through the importer's
    # own logging) without naming a store symbol.
    required = {os.path.join(IMPORTER_TESTS, name) for name in STORE_TOUCHING_SUITES}
    required.update(discovered)

    for rel in sorted(required):
        body = read(rel)
        label = os.path.basename(rel)

        if not check(
            bool(body),
            "6",
            f"missing prerequisite: {rel}",
        ):
            continue

        if not check(
            "tests/bootstrap.php" in body,
            "6",
            f"{label} does not require the shared test bootstrap",
        ):
            continue

        guard_line = line_of(body, GUARD_CALL)
        if not check(guard_line > 0, "6", f"{label} never calls {GUARD_CALL}"):
            continue

        code = strip_php_comments(body)
        first_reference = 0
        for number, line in enumerate(code.splitlines(), start=1):
            if STORE_REFERENCE_RE.search(line):
                first_reference = number
                break

        if first_reference:
            check(
                guard_line < first_reference,
                "6",
                f"{label}: the guard is at line {guard_line}, after the first store reference at {first_reference}",
            )

        check(
            body.count(GUARD_CALL) == 1,
            "6",
            f"{label}: the guard must be called exactly once (found {body.count(GUARD_CALL)})",
        )


# ---------------------------------------------------------------------------
# Check 7 — child-process code paths require the bootstrap, and no test hands
# its suffix to a child.
# ---------------------------------------------------------------------------
CHILD_SPAWN_RE = re.compile(r"\bexec\s*\(|\bproc_open\s*\(")
CHILD_WRITER_RE = re.compile(r"file_put_contents\s*\(")


def check_7_child_processes() -> None:
    checked = 0

    for rel in php_files(IMPORTER_TESTS):
        body = read(rel)
        if not CHILD_SPAWN_RE.search(body):
            continue

        checked += 1
        label = os.path.basename(rel)

        check(
            "tests/bootstrap.php" in body,
            "7",
            f"{label} spawns children but does not load the shared bootstrap for itself",
        )

        if CHILD_WRITER_RE.search(body):
            has_bootstrap_var = re.search(
                r"\$\w*bootstrap\w*\s*=\s*[^;]*tests/bootstrap\.php", body
            )
            passes_it_on = re.search(r"var_export\(\s*\$\w*bootstrap\w*", body)

            check(
                has_bootstrap_var is not None,
                "7",
                f"{label} writes a child script but never holds the bootstrap path in a variable",
            )
            check(
                passes_it_on is not None,
                "7",
                f"{label} writes a child script but never passes the bootstrap path to it",
            )

        check(
            "putenv( 'CONEXAO_TEST_OPTION_SUFFIX'" not in body
            and 'putenv( "CONEXAO_TEST_OPTION_SUFFIX"' not in body,
            "7",
            f"{label} exports the isolation suffix to children through the environment",
        )
        check(
            "getenv( 'CONEXAO_TEST_OPTION_SUFFIX' )" not in body
            and 'getenv( "CONEXAO_TEST_OPTION_SUFFIX" )' not in body,
            "7",
            f"{label} reads the isolation suffix from the environment",
        )

    check(checked > 0, "7", "no child-spawning suite was discovered (the scan is broken)")


# ---------------------------------------------------------------------------
# Check 8 — the reset helper keeps its allowlist and its rejections.
# ---------------------------------------------------------------------------
def check_8_reset_helper() -> None:
    body = read(HELPER)
    if not check(bool(body), "8", f"missing prerequisite: {HELPER}"):
        return

    for function in (
        "test_operator_store_registry",
        "test_operator_store_resolve",
        "test_options_reset_test_store",
        "test_options_write_test_store",
        "test_require_operator_store_isolation",
    ):
        check(
            f"function {function}(" in body,
            "8",
            f"the helper is missing function {function}()",
        )

    for key, meta in STORES.items():
        check(f"'{key}'" in body, "8", f"the allowlist does not carry '{key}'")
        check(
            meta["prefix_const"] in body,
            "8",
            f"the allowlist does not use the {meta['prefix_const']} constant",
        )

    check("'production_key'" in body, "8", "the production-key rejection branch is missing")
    check("'foreign_process'" in body, "8", "the foreign-suffix rejection branch is missing")
    check("'arbitrary_option'" in body, "8", "the arbitrary-option rejection branch is missing")
    check("'malformed'" in body, "8", "the malformed-key rejection branch is missing")
    check(
        "// Validate-all-then-delete: one rejection means zero deletions." in body,
        "8",
        "the validate-all-then-delete rule is missing",
    )

    # Every delete_option() in the helper must use a validated variable.
    code = strip_php_comments(body)
    deletes = [
        match.strip()
        for match in re.findall(r"delete_option\s*\(\s*([^)]*)\)", code)
        if match.strip()
    ]
    check(
        "$key" in deletes,
        "8",
        "the reset helper does not delete through the validated $key",
    )
    check(
        bool(deletes) and set(deletes) <= {"$key", "$option_name"},
        "8",
        f"the helper deletes through an unexpected argument: {deletes}",
    )

    isolate_start = code.find("function test_options_isolate(")
    isolate = code[isolate_start:] if isolate_start >= 0 else ""
    check(
        "test_operator_store_protection_reason(" in isolate,
        "8",
        "test_options_isolate() does not refuse the protected stores",
    )
    if "wp_load_alloptions()" in isolate:
        check(
            isolate.find("test_operator_store_protection_reason(")
            < isolate.find("wp_load_alloptions()"),
            "8",
            "test_options_isolate() validates AFTER it starts snapshotting",
        )
    check(
        "refuses the protected operator store" in isolate,
        "8",
        "test_options_isolate() has no refusal message",
    )


# ---------------------------------------------------------------------------
# Check 9 — nothing deletes a production operator option.
# ---------------------------------------------------------------------------
DELETE_OPTION_RE = re.compile(r"delete_option\s*\(\s*([^)]*)\)")


def check_9_no_production_delete() -> None:
    owning_files = {meta["file"] for meta in STORES.values()}
    scanned = 0

    for rel in php_files("wp-content") + php_files("scripts") + php_files("tests"):
        if rel in owning_files:
            continue
        body = strip_php_comments(read(rel))
        if "delete_option(" not in body:
            continue
        scanned += 1

        for match in DELETE_OPTION_RE.finditer(body):
            argument = match.group(1)
            for literal in PRODUCTION_LITERALS:
                if literal in argument:
                    fail("9", f"{rel} deletes the production option '{literal}'")
            for meta in STORES.values():
                if (
                    f"{meta['class']}::{meta['const']}" in argument
                    or f"{meta['class']}::{meta['prefix_const']}" in argument
                ):
                    fail(
                        "9",
                        f"{rel} deletes an operator-store constant: delete_option( {argument.strip()} )",
                    )

    check(scanned > 0, "9", "no delete_option() call site was found (the scan is broken)")


# ---------------------------------------------------------------------------
# Check 10 — the cleanup purge suite runs no global cleanup engine.
# ---------------------------------------------------------------------------
def check_10_no_global_cleanup() -> None:
    rel = os.path.join(IMPORTER_TESTS, "test-event-cleanup-purge.php")
    raw = read(rel)
    if not check(bool(raw), "10", f"missing prerequisite: {rel}"):
        return

    body = strip_php_comments(raw)

    check("run_cleanup()" not in body, "10", "test-event-cleanup-purge.php still calls run_cleanup()")
    check(
        "delete_option( 'conexao_event_cleanup_status' )" not in body,
        "10",
        "test-event-cleanup-purge.php still deletes the live cleanup status option",
    )
    check(
        "delete_option( 'conexao_event_cleanup_history' )" not in body,
        "10",
        "test-event-cleanup-purge.php still deletes the live cleanup history option",
    )
    check(
        "test_options_reset_test_store(" in body,
        "10",
        "test-event-cleanup-purge.php does not reset through the validated helper",
    )

    script_rel = "scripts/test-event-cleanup.php"
    script = read(script_rel)
    if check(bool(script), "10", f"missing prerequisite: {script_rel}"):
        check(
            "delete_option( 'conexao_event_cleanup_status' )" not in script
            and "delete_option( 'conexao_event_cleanup_history' )" not in script,
            "10",
            f"{script_rel} still deletes the operator cleanup rows",
        )
        check(
            "do not need" in script,
            "10",
            f"{script_rel} does not document that it is a local-write manual tool",
        )


# ---------------------------------------------------------------------------
# Check 11 — only the test bootstrap may define the harness markers.
# ---------------------------------------------------------------------------
MARKER_DEFINITION_RE = re.compile(
    r"define\s*\(\s*'(CONEXAO_TEST_OPTION_SUFFIX|CONEXAO_TESTS_ROOT)'"
)


def check_11_no_production_marker() -> None:
    scanned = 0

    for rel in production_php_files():
        body = read(rel)
        for match in MARKER_DEFINITION_RE.finditer(body):
            fail("11", f"{rel} defines {match.group(1)} in production code")
        scanned += 1

    check(scanned > 0, "11", "no production PHP file was scanned (the scan is broken)")

    for rel in php_files("tests"):
        if rel == BOOTSTRAP:
            continue
        body = read(rel)
        if not body:
            continue
        for match in MARKER_DEFINITION_RE.finditer(body):
            # The acceptance suite writes deliberate negative-control child
            # scripts; each runs in its own process on purpose.
            if "ois_run_child" in body:
                continue
            fail("11", f"{rel} defines {match.group(1)} outside {BOOTSTRAP}")


# ---------------------------------------------------------------------------
# Check 12 — no wildcard/pattern cleanup of options.
# ---------------------------------------------------------------------------
WILDCARD_DELETE_RE = re.compile(
    r"delete\s+from\s+[^\n;]*options[^\n;]*like", re.IGNORECASE
)


def check_12_no_wildcard_delete() -> None:
    scanned = 0

    for rel in production_php_files() + php_files("tests"):
        body = read(rel)
        scanned += 1
        for match in WILDCARD_DELETE_RE.finditer(body):
            fail("12", f"{rel} deletes options by LIKE pattern: {match.group(0)[:80]}")

    check(scanned > 0, "12", "no PHP file was scanned (the scan is broken)")


# ---------------------------------------------------------------------------
# Check 13 — the shutdown cleaner derives its key set from the accessors.
# ---------------------------------------------------------------------------
def check_13_cleaner_structure() -> None:
    body = read(BOOTSTRAP)
    if not check(bool(body), "13", f"missing prerequisite: {BOOTSTRAP}"):
        return

    start = body.find("function conexao_tests_clean_option_stores()")
    if not check(start >= 0, "13", "the shutdown cleaner is missing"):
        return

    cleaner = body[start : start + 4500]

    check(
        "'option_key'" in cleaner and "'status_option'" in cleaner and "'history_option'" in cleaner,
        "13",
        "the cleaner does not derive its keys from the four accessors",
    )
    check(
        "delete_option( $key )" in cleaner,
        "13",
        "the cleaner does not delete through a resolved key variable",
    )
    for literal in PRODUCTION_LITERALS:
        check(literal not in cleaner, "13", f"the cleaner names the literal '{literal}'")
    for meta in STORES.values():
        check(
            f"'{meta['prefix']}'" not in cleaner,
            "13",
            f"the cleaner hard-codes the test prefix literal for {meta['class']}",
        )
    check(
        "preg_match( '/^p[1-9][0-9]{0,9}_[a-f0-9]{16}$/'" in cleaner,
        "13",
        "the cleaner does not validate the suffix shape before deleting",
    )
    check(
        "conexao-test-cleanup:" in cleaner,
        "13",
        "the cleaner has no refusal message for an unexpected key",
    )


# ---------------------------------------------------------------------------
# Check 14 — no store-touching suite is PARALLEL in run-tests.sh.
# ---------------------------------------------------------------------------
def check_14_no_parallel_promotion() -> None:
    body = read(RUNNER)
    if not check(bool(body), "14", f"missing prerequisite: {RUNNER}"):
        return

    start = body.find("suite_classification() {")
    end = body.find("\n}\n", start)
    if not check(start >= 0 and end > start, "14", "suite_classification() was not found"):
        return

    block = body[start:end]

    check(
        "PARALLEL" in block,
        "14",
        "the classification block has no PARALLEL entries at all (did the parse break?)",
    )

    for name in STORE_TOUCHING_SUITES:
        path = f"wp-content/plugins/conexao-event-importer/tests/{name}"
        if re.search(re.escape(path) + r"\)\s*\n\s*printf 'PARALLEL", block):
            fail("14", f"{name} is classified PARALLEL but touches an operator store")

    check(
        "neither references a redirected operator" in body,
        "14",
        "the parallel-safety rule is not stated in the runner",
    )


def main() -> int:
    if not os.path.isdir(os.path.join(REPO_ROOT, "wp-content")):
        print(f"FAIL prerequisites: {REPO_ROOT} does not look like the repository root")
        return 1

    check_1_production_names()
    check_2_test_prefixes()
    check_3_production_branch()
    check_4_bootstrap_order()
    check_5_fail_closed()
    check_6_entry_points()
    check_7_child_processes()
    check_8_reset_helper()
    check_9_no_production_delete()
    check_10_no_global_cleanup()
    check_11_no_production_marker()
    check_12_no_wildcard_delete()
    check_13_cleaner_structure()
    check_14_no_parallel_promotion()

    print()
    for failure in FAILURES:
        print(f"  {failure}")

    print(f"\n  {PASSED} passed, {len(FAILURES)} failed (14 checks)")
    print(f"GATE-RESULT {GATE_ID}: passed={PASSED} failed={len(FAILURES)}")

    if FAILURES:
        return 1

    print("GATE PASS")
    return 0


if __name__ == "__main__":
    sys.exit(main())







