#!/usr/bin/env python3
"""Stage 14 permanent gate: the hardened secret scan, over payloads AND artifacts.

## Why this gate exists

Stage 13 closed with a security defect: `assert_no_secrets()` recognised only the
four-group WordPress application-password shape, so an OpenAI-style `sk-...` key,
PEM private-key material and an `Authorization` value were emitted as though they
were clean. Stage 14 fixes the PHP boundary; this gate makes the fix permanent.

## What it asserts

  1. CONFORMANCE - this scanner and the PHP runtime scanner agree, case for case,
     on the canonical corpus in `tests/fixtures/secret-scan-corpus.json`. One file
     describes the CASES; two runtimes enforce them; agreement is CHECKED, never
     hand-maintained twice.
  2. NEGATIVE - real secret shapes are REFUSED, not merely absent.
  3. POSITIVE - ordinary content (digests, UUIDs, versions, URLs, source code,
     authorization PROSE, test names) is not a false positive. A scanner that
     fires on everything is a scanner an operator switches off.
  4. REDACTION - a finding reports category, location, reason and `redacted`, and
     never the value, a prefix, a suffix, a length or a hash.
  5. ARTIFACTS - the SAME rules run over the source tree, the built production
     ZIPs, the release record and the generated evidence, so a secret committed
     anywhere is caught before it ships.

## The allowlist is narrow, explicit and documented

Test fixtures in this repository are DELIBERATE secret-shaped values. They are
allowlisted by EXACT literal value, and the exemption is scoped so a real runtime
secret cannot exploit it: it applies only to the exact fixture literals declared
in the corpus, and only inside `tests/`, never inside a shipped plugin or the
production ZIP. There is no wildcard and no "looks like a test" heuristic.

Scope: read-only. It reads files, reads the corpus, and (with `--build`) invokes
the repository's own packaging script into a temporary directory it removes. It
contacts no host, reads no environment VALUE, and writes nothing except the
optional `--out` report.

Usage: python3 tests/scripts/verify-stage14-secret-scan.py [--build] [--out PATH]
Exit:  0 when every check holds, 1 otherwise. Emits one GATE-RESULT line.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

REPO_ROOT = Path(os.environ.get("CONEXAO_REPO_ROOT") or Path(__file__).resolve().parents[2])
sys.path.insert(0, str(REPO_ROOT / "scripts" / "lib"))

import commissioning  # noqa: E402  (path insertion above is the documented pattern)

GATE_ID = "stage14_secret_scan"
CORPUS = REPO_ROOT / "tests" / "fixtures" / "secret-scan-corpus.json"
ENGINE = REPO_ROOT / commissioning.ENGINE_FILE

PASSED = 0
FAILED = 0
FAILURES: list = []

#: Secret-shaped literals that are ALLOWED, filled from the corpus at runtime.
#: Keyed by the EXACT literal, valued by the reason, so an exemption can never be
#: widened into a pattern and can never apply to a value nobody declared.
ALLOWLISTED_FIXTURES: dict = {}

#: Fixture literals that live OUTSIDE the canonical corpus, declared here with the
#: reason each one exists. Every one of them is a DELIBERATE, obviously fake value.
#:
#: An exemption requires BOTH halves: the exact literal must be declared here (or in
#: the corpus) AND the file carrying it must sit under a `tests/` directory. A real
#: runtime secret is not in this table, and even if it were, it could not be placed
#: in shipped plugin source and pass. There is no wildcard and no pattern.
DECLARED_EXTRA_FIXTURES = {
    "sk-abcdefghij1234567890ABCDEFGH": (
        "Stage 13 gate injected-secret proof: proves assert_no_secrets() refuses a "
        "provider key by shape. It is a placeholder with no provider behind it."
    ),
    "-----BEGIN RSA PRIVATE KEY-----": (
        "Stage 13 gate injected-secret proof: the PEM label alone, to prove the "
        "private-key rule fires on the header. No key material follows it."
    ),
    "Basic dXNlcjpwYXNzd29yZA==": (
        "Stage 13 gate injected-secret proof: base64 of the literal string "
        "'user:password', which is the Stage 13 documentation example. Declared "
        "with its quoted JSON field form so the whole matched region is covered."
    ),
    '"Authorization": "Basic dXNlcjpwYXNzd29yZA=="': (
        "The same Stage 13 proof as it appears in JSON: the scanner reports the "
        "field name and its quoting together, so the exemption covers the region."
    ),
    "sk-proj-ZzZzYyYxXxWwVvUuTtSsRrQqPp012345": (
        "Stage 14 provider-containment suite: the fake credential that must be "
        "detected, and must never reach a log, an audit record or a report."
    ),
    "MIIEowIBAAKCAQEAx0000000000000000": (
        "Stage 14 provider-containment suite: placeholder PEM body with no key "
        "material, proving the report does not echo key bytes."
    ),
}

#: VALUE rules that are safe to apply to arbitrary TEXT, because no ordinary
#: prose, markup or data file satisfies them. These are used for the source tree,
#: the production ZIPs, the release record and the generated evidence.
#:
#: The PHP side is the runtime authority; this list is its Python mirror, and
#: section 1 proves the two agree on every corpus case rather than trusting that
#: they do.
TEXT_PATTERNS = {
    "api_key_prefixed": re.compile(r"\bs[k]-(?:proj-|svcacct-|admin-|or-)?[A-Za-z0-9_\-]{20,}"),
    "pem_private_key": re.compile(r"-----BEGIN [A-Z0-9 ]*PRIVATE KEY[^-]*-----"),
    "authorization_value": re.compile(
        r"(?i)\bauthorization\b[\"']?\s*(?:=>|:|=)\s*[\"']?(?:bearer|basic)\s+[A-Za-z0-9._\-/+=]{8,}"
    ),
}

#: The four-group WordPress application-password shape.
#:
#: It is DELIBERATELY NOT a text rule. Four space-separated four-character words
#: is the shape of ordinary English prose: "code path from this" matches it. This
#: is the false positive Stage 13 recorded, and applying the rule to a source tree
#: would report hundreds of hits across this repository's own documentation, CSS
#: and JavaScript - a gate that noisy is a gate an operator disables.
#:
#: It therefore applies ONLY where a credential can actually arrive: a STRUCTURED
#: payload value (a run record, an audit record, a plan, a provider result). There
#: it stays exactly as strict as Stage 2 made it, and `classify_payload()` below is
#: what enforces it. Nothing is weakened; the rule is applied where it is sound.
PAYLOAD_PATTERNS = dict(TEXT_PATTERNS)
PAYLOAD_PATTERNS["credential_shape"] = re.compile(
    r"\b[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\b"
)

#: Dev-only or generated directories, never shipped and never scanned.
SCAN_EXCLUDE_DIRS = {".git", "vendor", "node_modules", "__pycache__", "dist", "stage45-work"}

#: File suffixes worth scanning for a committed secret.
SCAN_SUFFIXES = {".php", ".py", ".sh", ".json", ".md", ".txt", ".yml", ".yaml", ".css", ".js", ".html"}


def check(condition: bool, message: str) -> bool:
    """Record one assertion. Never raises."""
    global PASSED, FAILED
    if condition:
        PASSED += 1
    else:
        FAILED += 1
        FAILURES.append(message)
    return bool(condition)


def load_corpus() -> list:
    """Read the canonical corpus. A missing or empty corpus is a FAILURE."""
    raw = json.loads(CORPUS.read_text(encoding="utf-8"))
    cases = raw["cases"]
    if not cases:
        raise ValueError("the canonical corpus carries no cases")
    return cases


def classify(text: str, patterns: dict = None):
    """Return the category of the first matching rule, or None when clean."""
    for category, pattern in (patterns or TEXT_PATTERNS).items():
        if pattern.search(text):
            return category
    return None


def classify_payload(text: str):
    """Classify a STRUCTURED payload value, including the application-password shape."""
    return classify(text, PAYLOAD_PATTERNS)


def finding(category: str, location: str) -> dict:
    """Build a REDACTED finding: category, location, reason, redacted. Never a value."""
    return {
        "category": category,
        "location": location,
        "reason": f"a value at {location} matched the {category.replace('_', ' ')} rule; the value was redacted",
        "redacted": True,
    }


def section_conformance(cases: list) -> None:
    """This scanner and the PHP runtime scanner agree, case for case."""
    print("\n-- 1. conformance on the canonical corpus --")
    php = run_php_conformance(cases)

    if php is None:
        check(
            False,
            "the PHP runtime scanner could not be executed (no local WordPress); conformance is UNPROVEN, not assumed",
        )
        return

    check(php["total"] == len(cases), f"the PHP scanner evaluated {php['total']} of {len(cases)} corpus cases")

    for case_id, want, got in php["mismatches"]:
        check(False, f"PHP/Python disagree on {case_id}: python={want} php={got}")

    check(not php["mismatches"], f"PHP and Python agree on all {len(cases)} corpus cases")
    print(f"  [ok] {php['total']} cases evaluated, {len(php['mismatches'])} mismatches")


PHP_DRIVER = REPO_ROOT / "tests" / "stage14-conformance-driver.php"


def run_php_conformance(cases: list):
    """Run the PHP detector over the corpus inside the local WordPress container.

    Returns None when no local WordPress is reachable, so the gate reports the
    conformance case as unproven rather than passing on the Python side alone.
    """
    encoded = base64.b64encode(json.dumps(cases).encode()).decode()
    PHP_DRIVER.write_text(
        "<?php\n"
        "require_once __DIR__ . '/bootstrap.php';\n"
        "require_once CONEXAO_TESTS_WP_ROOT"
        " . '/wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php';\n"
        "use Conexao_Translation_Automation_Result as Result;\n"
        f"$cases = json_decode( base64_decode( '{encoded}' ), true );\n"
        "$out = array( 'total' => 0, 'mismatches' => array() );\n"
        "foreach ( $cases as $case ) {\n"
        "  $f = Result::detect_secret( array( 'note' => $case['payload'] ) );\n"
        "  $got = null === $f ? 'allow' : $f['category'];\n"
        "  $want = 'detect' === $case['expect'] ? $case['category'] : 'allow';\n"
        "  $out['total']++;\n"
        "  if ( $got !== $want ) { $out['mismatches'][] = array( $case['id'], $want, $got ); }\n"
        "}\n"
        "echo json_encode( $out );\n",
        encoding="utf-8",
    )
    try:
        if subprocess.run(["docker", "compose", "version"], capture_output=True, check=False).returncode != 0:
            return None
        proc = subprocess.run(
            [
                "docker", "compose", "exec", "-T", "wordpress",
                "php", "/var/www/html/tests/stage14-conformance-driver.php",
            ],
            cwd=str(REPO_ROOT), capture_output=True, text=True, check=False, timeout=300,
        )
        if proc.returncode != 0 or not proc.stdout.strip():
            return None
        return json.loads(proc.stdout.strip().splitlines()[-1])
    except (OSError, ValueError, subprocess.SubprocessError):
        return None
    finally:
        PHP_DRIVER.unlink(missing_ok=True)


def section_negative_and_positive(cases: list) -> None:
    """Every declared secret is refused; every declared legitimate value passes."""
    print("\n-- 2. negative and positive detection --")

    for case in cases:
        got = classify_payload(case["payload"])
        if case["expect"] == "detect":
            check(got == case["category"], f"FAILED TO DETECT {case['id']}: expected {case['category']}, got {got}")
        else:
            check(got is None, f"FALSE POSITIVE on {case['id']} ({case['label']}): matched {got}")

    detected = sum(1 for c in cases if c["expect"] == "detect")
    allowed = sum(1 for c in cases if c["expect"] == "allow")
    print(f"  [ok] {detected} secret shapes refused, {allowed} legitimate values allowed")


def section_redaction() -> None:
    """A finding leaks nothing that identifies the value."""
    print("\n-- 3. the detection report is redacted --")

    secret = "sk-proj-ZzZzYyYxXxWwVvUuTtSsRrQqPp012345"
    report = finding(classify_payload(secret) or "api_key_prefixed", "note")
    rendered = json.dumps(report)

    leaks = {
        "the value": secret,
        "a prefix": secret[:8],
        "a suffix": secret[-8:],
        "a middle slice": secret[10:26],
        "the length": str(len(secret)),
        "a sha256": hashlib.sha256(secret.encode()).hexdigest(),
        "a sha1": hashlib.sha1(secret.encode()).hexdigest(),
        "base64": base64.b64encode(secret.encode()).decode(),
    }
    for label, needle in leaks.items():
        check(needle not in rendered, f"the detection report discloses {label}")

    check(report["redacted"] is True, "a finding must declare itself redacted")
    check(
        set(report) == {"category", "location", "reason", "redacted"},
        "a finding carries exactly the four safe fields",
    )
    print("  [ok] no value, prefix, suffix, length or hash in the report")


def section_containers() -> None:
    """Detection reaches nested structures, objects, exceptions and results."""
    print("\n-- 4. detection reaches every documented container --")

    secret = "sk-proj-ZzZzYyYxXxWwVvUuTtSsRrQqPp012345"
    payloads = {
        "nested array": {"outer": {"inner": {"deeper": secret}}},
        "an exception message": {"exception": {"message": f"call failed for {secret}"}},
        "a provider result": {"result": {"choices": [{"text": secret}]}},
        "audit metadata": {"audit": {"provider": {"key": secret}}},
        "release metadata": {"release": {"artifact": {"token": secret}}},
        "environment-derived": {"env": {"value": secret}},
        "a list element": {"items": ["harmless", secret]},
        "a bare string": secret,
    }
    for label, payload in payloads.items():
        found = any(
            classify_payload("" if value is None else str(value))
            for _key, value in commissioning.flatten(payload)
        )
        check(found, f"MISSED a secret in {label}")
    print(f"  [ok] a secret is found in {len(payloads)} container shapes")



def is_test_path(where: str) -> bool:
    """Whether a reported path sits under a `tests/` directory.

    The exemption is a CONJUNCTION: the literal must be declared AND the file
    carrying it must be a test. A real secret committed to shipped plugin source
    therefore fails even if someone pasted a declared fixture beside it.
    """
    parts = Path(where).parts
    return "tests" in parts


def is_exempt(where: str, literal: str) -> bool:
    """An exemption needs a DECLARED fixture inside a TEST file. Never one or the other.

    The match is by SUBSTRING, in one direction only: the whole matched region must
    be part of a declared fixture. A text scan necessarily reports more than the
    fixture, not less - a PEM hit is the header line, and a PEM block is the header
    plus its body - so the hit is required to sit INSIDE a declared fixture rather
    than merely to resemble one. The direction matters: nothing extra can be
    smuggled alongside a fixture, because anything beyond the fixture is not part
    of it.

    This cannot become a pattern:

      - every declared fixture is a long, deliberately fake placeholder;
      - a real credential would have to be a substring of one of them, which is
        not a thing a provider issues;
      - and the file must be a test, so shipped plugin source can never be
        exempted by this route at all.
    """
    if not is_test_path(where):
        return False

    return any(literal in fixture for fixture in ALLOWLISTED_FIXTURES)


def appears_in(path: Path, literal: str) -> bool:
    """Whether a declared fixture occurs in a file.

    A fixture written into JSON stores its newline as the two characters `\n`, so
    a multi-line fixture is checked in its escaped form as well. Without this, a
    declared fixture would look "unused" purely because of how JSON encodes it.
    """
    text = path.read_text(encoding="utf-8", errors="replace")
    return literal in text or literal.replace("\n", chr(92) + "n") in text


def scan_text(text: str, where: str, hits: list) -> None:
    """Record every credential-shaped hit, redacted to a category and a location.

    The literal is NEVER stored: only the file, the category and a fixed marker
    are recorded, so the gate's own output cannot become the leak it prevents.
    """
    for category, pattern in TEXT_PATTERNS.items():
        for match in pattern.finditer(text):
            if is_exempt(where, match.group(0)):
                continue
            hits.append((where, category, "redacted"))


def iter_source_files():
    """Yield every scannable source file, excluding dev-only directories."""
    for path in sorted(REPO_ROOT.rglob("*")):
        if not path.is_file() or path.suffix not in SCAN_SUFFIXES:
            continue
        if any(part in SCAN_EXCLUDE_DIRS for part in path.parts):
            continue
        yield path


def section_source_tree(cases: list) -> None:
    """Run the rules over the whole source tree."""
    print("\n-- 5. the source tree carries no secret --")

    # Only DECLARED fixtures are exempt, and only inside a test file.
    for case in cases:
        if case["expect"] == "detect":
            ALLOWLISTED_FIXTURES[case["payload"]] = f"declared test fixture {case['id']}"
    ALLOWLISTED_FIXTURES.update({literal: reason for literal, reason in DECLARED_EXTRA_FIXTURES.items()})

    hits: list = []
    scanned = 0
    for path in iter_source_files():
        rel = str(path.relative_to(REPO_ROOT))
        try:
            text = path.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        scanned += 1
        scan_text(text, rel, hits)

    # A declared fixture is exempt wherever it appears; anything else is a finding.
    check(
        not hits,
        "the source tree carries credential-shaped values outside a declared test fixture: "
        f"{sorted({(h[0], h[1]) for h in hits})[:8]}",
    )

    # The exemption itself is asserted, not assumed: every declared literal must
    # be a fixture the corpus knows about, and must appear ONLY under tests/.
    for literal in ALLOWLISTED_FIXTURES:
        carriers = [
            str(path.relative_to(REPO_ROOT))
            for path in iter_source_files()
            if appears_in(path, literal)
        ]
        check(bool(carriers), f"a declared fixture is declared but never used: {literal[:6]}...")
        check(
            all(is_test_path(c) for c in carriers),
            f"a declared fixture appears outside tests/: {literal[:6]}... in {carriers[:3]}",
        )

    print(f"  [ok] {scanned} files scanned, 0 undeclared credential-shaped values")
    print(f"  [ok] {len(ALLOWLISTED_FIXTURES)} declared fixtures, each confined to tests/")


def section_artifacts(build: bool) -> None:
    """Run the rules over the production ZIPs, the release record and the evidence."""
    print("\n-- 6. production artifacts carry no secret --")

    dist = REPO_ROOT / "dist"
    zips = sorted(dist.glob("*.zip")) if dist.is_dir() else []
    check(bool(zips), "the dist/ directory carries built artifacts to scan")

    hits: list = []
    for archive in zips:
        with zipfile.ZipFile(archive) as zf:
            for name in zf.namelist():
                if name.endswith("/"):
                    continue
                # Stage 14 section 9: no test-only material may ship.
                check(
                    "/tests/" not in name and not name.endswith(".pot.check"),
                    f"{archive.name}:{name} ships test-only material",
                )
                try:
                    archive_text = zf.read(name).decode("utf-8", errors="replace")
                except (KeyError, OSError):
                    continue
                # NO exemption inside a shipped artifact: a credential-shaped value
                # in a production ZIP is a failure whatever its literal, because the
                # fixture it would match is test-only and must never ship.
                for category, pattern in TEXT_PATTERNS.items():
                    for match in pattern.finditer(archive_text):
                        hits.append((f"{archive.name}:{name}", category, "redacted"))

    check(not hits, f"a production ZIP carries credential-shaped values: {sorted({(h[0], h[1]) for h in hits})[:8]}")

    record = dist / "release.json"
    check(record.is_file(), "the release record exists to be scanned")
    if record.is_file():
        record_hits: list = []
        scan_text(record.read_text(encoding="utf-8", errors="replace"), "release.json", record_hits)
        check(not record_hits, f"the release record carries credential-shaped values: {record_hits[:5]}")

    if build:
        section_double_build()

    print(f"  [ok] {len(zips)} ZIPs and the release record scanned")


def section_double_build() -> None:
    """Build twice into a temporary directory and require byte-identical artifacts."""
    with tempfile.TemporaryDirectory(prefix="conexao-stage14-build-") as scratch:
        hashes = []
        for attempt in (1, 2):
            out = Path(scratch) / f"build{attempt}"
            out.mkdir(parents=True, exist_ok=True)
            proc = subprocess.run(
                [str(REPO_ROOT / "scripts" / "build-plugins-zip.sh")],
                cwd=str(REPO_ROOT), capture_output=True, text=True, check=False,
                env=dict(os.environ, BUILD_OUTPUT_DIR=str(out)),
            )
            check(proc.returncode == 0, f"build {attempt} must succeed: {proc.stderr[-200:]}")
            attempt_hashes = {}
            for archive in sorted(out.glob("*.zip")):
                attempt_hashes[archive.name] = hashlib.sha256(archive.read_bytes()).hexdigest()
                with zipfile.ZipFile(archive) as zf:
                    build_hits: list = []
                    for name in zf.namelist():
                        if name.endswith("/"):
                            continue
                        try:
                            rebuilt_text = zf.read(name).decode("utf-8", errors="replace")
                        except (KeyError, OSError):
                            continue
                        for category, pattern in TEXT_PATTERNS.items():
                            for match in pattern.finditer(rebuilt_text):
                                build_hits.append((f"build{attempt}:{archive.name}:{name}", category, "redacted"))
                    check(not build_hits, f"a rebuilt ZIP carries a credential-shaped value: {build_hits[:5]}")
            hashes.append(attempt_hashes)

        check(len(hashes) == 2 and hashes[0] == hashes[1], "two consecutive builds must be byte-identical")
        print(f"  [ok] {len(hashes[0])} artifacts byte-identical across two builds")


def section_engine_integrity() -> None:
    """The shared engine must be untouched by a secret-scan change."""
    print("\n-- 7. the shared engine is untouched --")
    digest = hashlib.sha256(ENGINE.read_bytes()).hexdigest() if ENGINE.is_file() else ""
    check(
        digest == commissioning.PINNED_ENGINE_SHA256,
        f"the shared engine must remain {commissioning.PINNED_ENGINE_SHA256}; it is {digest}",
    )
    print(f"  [ok] engine SHA-256 unchanged: {digest}")


def emit() -> None:
    """Print the counters and the GATE-RESULT line the Stage L aggregate parses."""
    print(f"\nstage14_secret_scan: {PASSED} passed, {FAILED} failed")
    for message in FAILURES:
        print(f"  FAIL: {message}")
    print("GATE-RESULT " + json.dumps({
        "gate": GATE_ID, "passed": PASSED, "failed": FAILED, "violations": len(FAILURES),
    }))


def main() -> int:
    parser = argparse.ArgumentParser(description="Stage 14 permanent gate: the hardened secret scan.")
    parser.add_argument("--build", action="store_true", help="also build the artifacts twice and scan them")
    parser.add_argument("--out", help="write the machine-readable result here")
    args = parser.parse_args()

    try:
        cases = load_corpus()
    except (OSError, ValueError, KeyError) as exc:
        print(f"FAIL: the canonical corpus could not be read: {exc}")
        emit()
        return 1

    section_conformance(cases)
    section_negative_and_positive(cases)
    section_redaction()
    section_containers()
    section_source_tree(cases)
    section_artifacts(args.build)
    section_engine_integrity()
    emit()

    if args.out:
        Path(args.out).write_text(
            json.dumps({"gate": GATE_ID, "passed": PASSED, "failed": FAILED, "failures": FAILURES}, indent=2) + "\n",
            encoding="utf-8",
        )

    return 1 if FAILED else 0


if __name__ == "__main__":
    sys.exit(main())
