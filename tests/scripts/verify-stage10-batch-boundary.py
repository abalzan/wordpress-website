#!/usr/bin/env python3
"""Stage 10 negative proofs: every new gate, shown to fail.

## Why a gate never shown to fail is not a gate

Stage 10 adds structural assertions — to the control-plane registry, to the
batch layer's ability to reach content, to the one-batch default, to the
absence of a loop in the executor, and to the evidence-only nature of the
expansion gate. A structural check that has never been observed to reject
anything might be asserting nothing at all.

So each case below injects a REAL violation into a THROWAWAY COPY of the
repository (never the working tree), runs the real gate against that copy, and
asserts the gate exits NON-ZERO. If a proof ever passes, this script reports
MISSED and exits non-zero, so it fails if the gates go soft.

Static only. No WordPress, no network, no production. The behavioural
counterparts — an unauthorised write refused, an over-sized batch refused, a
tampered approval refused — live in the in-process suite
`test-automation-batch.php`, which uses an adapter that THROWS on any write.

Usage: python3 tests/scripts/verify-stage10-batch-boundary.py
"""

from __future__ import annotations

import json
import os
import pathlib
import re
import shutil
import subprocess
import sys
import tempfile

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
PLUGIN_REL = "wp-content/plugins/conexao-translation-automation/includes"
GATE_REL = "tests/scripts/verify-stage8-control-plane.py"

PASSED = 0
FAILED = 0
WORK = pathlib.Path(tempfile.mkdtemp(prefix="conexao-stage10-neg-"))


def fresh_copy() -> pathlib.Path:
    """Rebuild the throwaway repository the gate reads."""
    root = WORK / "repo"
    if root.exists():
        shutil.rmtree(root)
    (root / PLUGIN_REL).mkdir(parents=True)
    (root / "tests/scripts").mkdir(parents=True)
    (root / "wp-content/plugins/conexao-translation-rollout/includes").mkdir(parents=True)

    for f in sorted((REPO_ROOT / PLUGIN_REL).glob("*.php")):
        shutil.copy2(f, root / PLUGIN_REL / f.name)
    # BOTH engine files the Stage 8 gate reads. Copying only the engine core
    # left the CLOSED-endpoint assertions red in the clean state, which would
    # make every proof below pass for entirely the wrong reason.
    for engine_file in (
        "class-conexao-translation-rollout-engine.php",
        "class-conexao-translation-rollout-admin.php",
    ):
        shutil.copy2(
            REPO_ROOT / "wp-content/plugins/conexao-translation-rollout/includes" / engine_file,
            root / "wp-content/plugins/conexao-translation-rollout/includes" / engine_file,
        )
    shutil.copy2(REPO_ROOT / GATE_REL, root / GATE_REL)
    return root


def inject(relative: str, old: str, new: str) -> None:
    """Replace `old` with `new` in a copy. Fails loudly if the anchor is gone."""
    path = WORK / "repo" / relative
    body = path.read_text(encoding="utf-8")
    if old not in body:
        raise SystemExit(f"anchor not found in {relative}: {old!r}")
    path.write_text(body.replace(old, new, 1), encoding="utf-8")


def run_gate() -> tuple[int, str]:
    proc = subprocess.run(
        [sys.executable, str(WORK / "repo" / GATE_REL)],
        capture_output=True,
        text=True,
        env={**os.environ, "CONEXAO_REPO_ROOT": str(WORK / "repo")},
    )
    return proc.returncode, proc.stdout + proc.stderr


def proof(name: str) -> None:
    global PASSED, FAILED
    code, out = run_gate()
    if code != 0:
        PASSED += 1
        first = next(
            (line.strip() for line in out.splitlines() if line.strip().startswith("FAIL:")),
            "",
        )
        print(f"  PROVEN  {name}")
        if first:
            print(f"            {first[:150]}")
    else:
        FAILED += 1
        print(f"  MISSED  {name} — the gate exited 0 on a real violation")


def expect_pass(name: str) -> None:
    global PASSED, FAILED
    code, out = run_gate()
    if code == 0:
        PASSED += 1
        print(f"  PROVEN  {name}")
    else:
        FAILED += 1
        print(f"  BROKEN  {name} — the clean state FAILED")
        for line in out.splitlines():
            if "FAIL:" in line:
                print(f"            {line.strip()[:150]}")


LIMITS = f"{PLUGIN_REL}/class-conexao-translation-automation-batch-limits.php"
BATCH = f"{PLUGIN_REL}/class-conexao-translation-automation-batch.php"
APPROVAL = f"{PLUGIN_REL}/class-conexao-translation-automation-batch-approval.php"
EXECUTOR = f"{PLUGIN_REL}/class-conexao-translation-automation-batch-executor.php"
COMPOSER = f"{PLUGIN_REL}/class-conexao-translation-automation-batch-composer.php"
EXPANSION = f"{PLUGIN_REL}/class-conexao-translation-automation-batch-expansion.php"
STATE = f"{PLUGIN_REL}/class-conexao-translation-automation-batch-state.php"
KILL = f"{PLUGIN_REL}/class-conexao-translation-automation-emergency-stop.php"


def main() -> int:
    print("Stage 10 negative proofs — the clean state first")
    fresh_copy()
    expect_pass("clean repository PASSES the control-plane gate")

    print("\n-- the one-batch default --")

    fresh_copy()
    inject(LIMITS, "const MAX_PRODUCTION_BATCHES_PER_INVOCATION = 1;", "const MAX_PRODUCTION_BATCHES_PER_INVOCATION = 2;")
    proof("1. a second batch per invocation becomes allowed")

    fresh_copy()
    inject(LIMITS, "const MAX_PRODUCTION_BATCHES_PER_INVOCATION = 1;", "const MAX_PRODUCTION_BATCHES_PER_INVOCATION = 1000;")
    proof("2. the one-batch default is raised to a thousand")

    print("\n-- the batch layer must not become a second mutation path --")

    for index, (relative, token) in enumerate(
        [
            (BATCH, "wp_insert_post"),
            (BATCH, "Conexao_Translation_Rollout_Engine::"),
            (APPROVAL, "wp_update_post"),
            (STATE, "Conexao_Translation_Rollout_Engine::"),
            (KILL, "wp_delete_post"),
            (COMPOSER, "Conexao_Translation_Rollout_Engine::"),
        ],
        start=3,
    ):
        fresh_copy()
        body = (WORK / "repo" / relative).read_text(encoding="utf-8")
        marker = body.rindex("}")
        inject(relative, body[marker - 1 : marker], f" {token} // {body[marker - 1]}")
        proof(f"{index}. {relative.split('/')[-1]} gains `{token}`")

    print("\n-- no autonomous progression --")

    fresh_copy()
    inject(
        EXECUTOR,
        "$loop = self::run_operations( $batch, $context );",
        "while ( $more = self::next_batch_after( $batch_id ) ) {\n"
        "\t\t$loop = self::run_operations( $more, $context, $dry );\n"
        "\t}\n"
        "\t$loop = self::run_operations( $batch, $context, $dry );",
    )
    proof("7. the executor gains a loop that pulls a second batch")

    fresh_copy()
    inject(EXECUTOR, "$result = Conexao_Translation_Automation_Orchestrator::run(", "$_ = Conexao_Translation_Automation_Batch_Composer::compose( array() ); $result = Conexao_Translation_Automation_Orchestrator::run(")
    proof("8. the executor starts composing batches of its own")

    fresh_copy()
    inject(EXECUTOR, "$count = Conexao_Translation_Automation_Batch_Limits::assert_batch_count( 1 );", "$count = true;")
    proof("9. the one-batch guard in the executor is removed")

    fresh_copy()
    inject(EXECUTOR, "$result = Conexao_Translation_Automation_Orchestrator::run(", "$result = Conexao_Translation_Automation_Orchestrator::run(")
    # A SECOND orchestrator call site: two engine routes, one of them unscoped.
    body = (WORK / "repo" / EXECUTOR).read_text(encoding="utf-8")
    (WORK / "repo" / EXECUTOR).write_text(
        body.replace(
            "\t\t$base = array(",
            "\t\tConexao_Translation_Automation_Orchestrator::run( array( 'authorized' => true, 'stage' => 'en-guide' ) );\n\n\t\t$base = array(",
            1,
        ),
        encoding="utf-8",
    )
    proof("10. a second, unscoped orchestrator call site appears")

    fresh_copy()
    inject(EXECUTOR, "Conexao_Translation_Automation_Batch_Approval::verify( $current )", "Conexao_Translation_Automation_Batch_Approval::verify( $current ) // bypassed")
    body = (WORK / "repo" / EXECUTOR).read_text(encoding="utf-8")
    (WORK / "repo" / EXECUTOR).write_text(
        body.replace(
            "if ( is_wp_error( $verified ) ) {",
            "if ( false ) {",
            1,
        ),
        encoding="utf-8",
    )
    proof("11. the per-execution approval check is removed")

    fresh_copy()
    inject(EXECUTOR, "if ( is_wp_error( $kill ) ) {", "if ( false ) {")
    proof("12. the emergency stop is bypassed")

    fresh_copy()
    inject(EXECUTOR, "if ( array() !== $outside ) {", "if ( false ) {")
    proof("13. the plan-scope assertion is removed")

    print("\n-- the expansion gate stays evidence-only --")

    fresh_copy()
    inject(EXPANSION, "$requirements = array(", "$requirements = array( 'ok' => true, ")
    body = (WORK / "repo" / EXPANSION).read_text(encoding="utf-8")
    (WORK / "repo" / EXPANSION).write_text(
        body.replace("Conexao_Translation_Automation_Batch::VERIFIED === $state", "true", 1),
        encoding="utf-8",
    )
    proof("14. the expansion gate stops requiring a verified predecessor")

    fresh_copy()
    inject(EXPANSION, "'eligible'     => array() === $unmet,", "'eligible'     => true,")
    proof("15. the expansion gate returns eligible despite unmet requirements")

    print("\n-- a new batch file cannot escape the scan --")

    fresh_copy()
    (WORK / "repo" / PLUGIN_REL / "class-conexao-translation-automation-batch-secret.php").write_text(
        "<?php\nfunction secret_batch_helper() { return wp_insert_post( array() ); }\n",
        encoding="utf-8",
    )
    proof("16. an unnamed batch file that writes content is not scanned")

    print("\n-- the batch layer must register no endpoint --")

    fresh_copy()
    inject(
        EXECUTOR,
        "defined( 'ABSPATH' ) || exit;",
        "defined( 'ABSPATH' ) || exit;\nadd_action( 'admin_post_conexao_translation_automation_batch_run', '__return_true' );",
    )
    proof("17. the batch layer registers an admin-post endpoint")

    print()
    print(f"negative proofs: {PASSED} proven, {FAILED} missed")

    if FAILED:
        print("A gate went soft. Do not treat the suite as green.")
        return 1

    print("ALL NEGATIVE PROOFS HELD")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    finally:
        shutil.rmtree(WORK, ignore_errors=True)
