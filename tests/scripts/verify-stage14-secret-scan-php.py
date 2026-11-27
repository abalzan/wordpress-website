#!/usr/bin/env python3
"""Compare the real PHP secret detector with the canonical corpus, without WordPress."""

from __future__ import annotations

import base64
import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO_ROOT = Path(os.environ.get("CONEXAO_REPO_ROOT") or Path(__file__).resolve().parents[2])
CORPUS = REPO_ROOT / "tests" / "fixtures" / "secret-scan-corpus.json"
DETECTOR = REPO_ROOT / "wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-result.php"
GATE_ID = "stage14_secret_scan_php"


def php_string(value: str) -> str:
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"


def main() -> int:
    php = shutil.which("php")
    if php is None:
        print("BLOCKED: PHP CLI is required for the Stage 14 runtime conformance gate")
        print(f"GATE-RESULT {json.dumps({'gate': GATE_ID, 'passed': 0, 'failed': 1, 'violations': 1})}")
        return 1

    try:
        cases = json.loads(CORPUS.read_text(encoding="utf-8"))["cases"]
        if not cases:
            raise ValueError("the canonical corpus carries no cases")
    except (OSError, ValueError, KeyError) as exc:
        print(f"FAIL: the canonical corpus could not be read: {exc}")
        print(f"GATE-RESULT {json.dumps({'gate': GATE_ID, 'passed': 0, 'failed': 1, 'violations': 1})}")
        return 1

    encoded = base64.b64encode(json.dumps(cases).encode()).decode()
    driver = (
        "<?php\n"
        "define( 'ABSPATH', '/' );\n"
        f"require_once {php_string(str(DETECTOR))};\n"
        f"$cases = json_decode( base64_decode( '{encoded}' ), true );\n"
        "$out = array( 'total' => 0, 'mismatches' => array() );\n"
        "foreach ( $cases as $case ) {\n"
        "  $finding = Conexao_Translation_Automation_Result::detect_secret( array( 'note' => $case['payload'] ) );\n"
        "  $got = null === $finding ? 'allow' : $finding['category'];\n"
        "  $want = 'detect' === $case['expect'] ? $case['category'] : 'allow';\n"
        "  ++$out['total'];\n"
        "  if ( $got !== $want ) { $out['mismatches'][] = array( $case['id'], $want, $got ); }\n"
        "}\n"
        "echo json_encode( $out );\n"
    )

    try:
        with tempfile.TemporaryDirectory(prefix="conexao-stage14-php-") as scratch:
            driver_path = Path(scratch) / "driver.php"
            driver_path.write_text(driver, encoding="utf-8")
            proc = subprocess.run(
                [php, str(driver_path)],
                capture_output=True,
                text=True,
                check=False,
                timeout=60,
            )
        if proc.returncode != 0:
            raise RuntimeError(f"PHP detector exited {proc.returncode}: {proc.stderr.strip()}")
        result = json.loads(proc.stdout.strip().splitlines()[-1])
    except (OSError, ValueError, IndexError, subprocess.SubprocessError, RuntimeError) as exc:
        print(f"FAIL: PHP detector conformance could not be established: {exc}")
        print(f"GATE-RESULT {json.dumps({'gate': GATE_ID, 'passed': 0, 'failed': 1, 'violations': 1})}")
        return 1

    total = int(result.get("total", 0))
    mismatches = result.get("mismatches", [])
    passed = int(total == len(cases)) + int(not mismatches)
    failed = 2 - passed
    print(f"PHP detector evaluated {total}/{len(cases)} cases; mismatches: {len(mismatches)}")
    for case_id, expected, actual in mismatches:
        print(f"FAIL {case_id}: expected {expected}, got {actual}")
    print(f"stage14_secret_scan_php: {passed} passed, {failed} failed")
    print(f"GATE-RESULT {json.dumps({'gate': GATE_ID, 'passed': passed, 'failed': failed, 'violations': len(mismatches) + int(total != len(cases))})}")
    return int(failed > 0)


if __name__ == "__main__":
    sys.exit(main())
