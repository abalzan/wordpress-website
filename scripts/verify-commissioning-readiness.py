#!/usr/bin/env python3
"""verify-commissioning-readiness.py — the atomic commissioning preflight.

Purpose: evaluate EVERY production commissioning prerequisite independently and
print ONE aggregate result. Read-only. It is the only command an operator runs
before asking for production action, and it can never itself be that action.

Scope: read-only over the local repository and the local process environment
PRESENCE flags. It contacts no host, loads no WordPress, opens no socket, and
performs no production action of any kind. Reachability is recorded as
information only and is never authorization.

Safety: read-only. It writes nothing except, with `--out`, the report file the
operator asked for. It cannot install, activate, apply, select a canary, clear
the emergency stop, make a provider call, or commission the batch-control
surface. There is no `--apply` flag, and adding one would fail the permanent
Stage 13 gate.

What it refuses to do, and why each refusal is structural rather than a promise:

  - It reads environment VALUES never. `env_present` carries NAMES only, so no
    credential, length, prefix, suffix or hash can reach the report.
  - It never substitutes a credential variable. `OPENAI_API_KEY` is not the
    approved mechanism and the gate says so in its report when it sees one.
  - It never reuses the local Docker credentials in `.env` as production
    credentials; the `.env` file is not read at all.
  - It makes no provider call, so it cannot spend budget to find out whether the
    key works. "credential present" and "live smoke passed" are different
    prerequisites with different evidence.
  - It never infers one authorization from another. Each is a distinct evidence
    type read under its own key.

Usage:
  ./scripts/verify-commissioning-readiness.py
  ./scripts/verify-commissioning-readiness.py --json
  ./scripts/verify-commissioning-readiness.py --runbook
  ./scripts/verify-commissioning-readiness.py --evidence <dir>

Exit codes:
  0  the preflight ran and the aggregate is READY
  1  the preflight ran and the aggregate is NOT_READY or BLOCKED
  2  the preflight could not run (a prerequisite fact could not be read)
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

REPO_ROOT = Path(os.environ.get("CONEXAO_REPO_ROOT") or Path(__file__).resolve().parents[1])
sys.path.insert(0, str(REPO_ROOT / "scripts" / "lib"))

import commissioning  # noqa: E402  (path insertion above is the documented pattern)

PLUGIN_DIR = REPO_ROOT / "wp-content" / "plugins"
AUTOMATION = PLUGIN_DIR / "conexao-translation-automation"
ROLLOUT = PLUGIN_DIR / "conexao-translation-rollout"
LIMITS_FILE = AUTOMATION / "includes" / "class-conexao-translation-automation-batch-limits.php"
CONTROL_FILE = AUTOMATION / "includes" / "class-conexao-translation-automation-batch-control.php"
BOOTSTRAP_FILE = AUTOMATION / "conexao-translation-automation.php"
STOP_FILE = AUTOMATION / "includes" / "class-conexao-translation-automation-emergency-stop.php"
PROVIDER_CONFIG = AUTOMATION / "includes" / "class-conexao-translation-automation-provider-config.php"

#: The two production-relevant plugins, and the versions Stage 13 expects. The
#: expectation is a POLICY input, checked against the plugin headers and the
#: release record rather than being taken from either of them.
EXPECTED_VERSIONS = {
    "conexao-translation-rollout": "1.2.0",
    "conexao-translation-automation": "0.4.0",
}

#: Credential-shaped patterns scanned for inside a built artifact.
#:
#: The 4x4 word shape is deliberately NOT used here. Scanned over arbitrary
#: source text it matches English prose — "code path from this" is four
#: four-letter words — so a shape-only rule reports a false positive on the
#: repository's own docblocks and would train an operator to ignore the gate.
#: Instead a credential is detected by ASSIGNMENT: a secret-named variable
#: bound to a non-empty literal. That catches the real failure (a credential
#: committed into source) without firing on documentation.
SECRET_ASSIGNMENT = re.compile(
    r"""(?ix)
    \b([A-Za-z_][A-Za-z0-9_]*)   # the variable name
    \s*=\s*                      # bound to
    ['"]([^'"]{8,})['"]           # a non-trivial string literal
    """
)

#: A variable name that would indicate a committed credential.
SECRET_NAME = re.compile(
    r"(?i)(password|passwd|secret|api_?key|private_?key|access_?token|bearer|credential"
    r"|nonce|cookie|auth_?header)"
)

#: Names that hold the NAME of a credential variable, not the credential itself.
#: `Provider_Config::CREDENTIAL_ENV` is exactly this: it is deliberately public,
#: and gating on it would flag the very code that makes the credential
#: environment-only. Distinguishing "names the variable" from "is the secret" is
#: the difference between a gate an operator trusts and one they disable.
CREDENTIAL_NAME_HOLDER = re.compile(r"(?i)(_ENV|_VARNAME|_VARIABLE|_ENVIRONMENT)$")

#: The same idea for a nonce: `NONCE_ACTION` names the action a nonce is minted
#: for. It is not a nonce. A nonce VALUE is caught by the value shapes below
#: regardless of the variable it is bound to.
NAME_HOLDER = re.compile(r"(?i)(_ENV|_VARNAME|_VARIABLE|_ENVIRONMENT|_ACTION)$")

#: Value shapes that are credentials whatever they are assigned to.
# The separator is `:` , `=` or `=>` so an array literal
# (`'Authorization' => 'Basic ...'`) is caught as well as a header map.
SECRET_VALUE = (
    re.compile(r"\bsk-[A-Za-z0-9]{16,}"),
    re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
    re.compile(r"(?i)\bauthorization['\"]?\s*(?:=>|:|=)\s*['\"]?(basic|bearer)\s+\S{8,}"),
    re.compile(r"(?i)['\"]?(?:wp_?nonce|_wpnonce)['\"]?\s*(?:=>|:|=)\s*['\"]?[0-9a-f]{8,}"),
    re.compile(r"(?i)\bset-?cookie['\"]?\s*(?:=>|:|=)\s*['\"]?\S{8,}"),
)

#: A bare environment-variable name, e.g. CONEXAO_TRANSLATION_PROVIDER_KEY.
ENV_VAR_NAME = re.compile(r"^[A-Z][A-Z0-9_]{3,}$")

#: A bare identifier used as a public NAME rather than a value: an action slug, a
#: key name, a constant's referent. `conexao_translation_automation_batch` is the
#: name of a nonce action, not a nonce. Combined with NAME_HOLDER this exempts
#: only the specific "this constant names the secret" pattern; a value-shaped
#: secret is still caught by SECRET_VALUE regardless, because those patterns are
#: checked over the whole text and are not exempted here.
BARE_IDENTIFIER = re.compile(r"^[A-Za-z0-9_.\-]{4,}$")

#: A FAILURE_CATEGORY / ERROR_ constant holds a machine slug such as
#: 'batch_control_invalid_nonce' — the NAME of a failure, not a nonce. The
#: `nonce` substring in such a name is a naming collision, so the class is
#: exempted ONLY when the value is a lowercase word-slug.
#:
#: The exemption is deliberately narrow and does not create a hole: a slug that
#: is pure hex of nonce length (`^[0-9a-f]{16,}$`) is EXCLUDED, so a real nonce
#: bound to a failure-category name is still reported, as is any nonce-shaped
#: value anywhere in the text (SECRET_VALUE is not exempted at all).
FAILURE_CATEGORY_NAME = re.compile(r"(?i)^(FAILURE|ERROR|REASON)_")
CATEGORY_SLUG = re.compile(r"^[a-z][a-z0-9_]*$")
HEX_BLOB = re.compile(r"^[0-9a-f]{16,}$")

#: PHP/Python placeholders that are documentation, not credentials.
PLACEHOLDER = re.compile(r"(?i)(xxxx|<[^>]+>|\{[a-z_]+\}|your[-_]|example|placeholder|redacted|changeme|\*\*\*)")


def scan_for_secrets(text: str) -> list:
    """Return the variable names that look like a committed credential.

    Fails closed in the sense that matters: an unrecognised literal assigned to
    a secret-named variable is reported. Documentation placeholders are
    excluded, because a gate that fires on `your-app-password` is a gate that
    gets disabled.
    """
    hits = []
    for name, value in SECRET_ASSIGNMENT.findall(text):
        if not SECRET_NAME.search(name):
            continue
        if PLACEHOLDER.search(value):
            continue
        if FAILURE_CATEGORY_NAME.match(name) and CATEGORY_SLUG.fullmatch(value) and not HEX_BLOB.fullmatch(value):
            # A failure category slug, e.g. FAILURE_NONCE = 'invalid_nonce'.
            continue
        if NAME_HOLDER.search(name) and (ENV_VAR_NAME.fullmatch(value) or BARE_IDENTIFIER.fullmatch(value)):
            # Holds the NAME of a credential variable. Public by design.
            continue
        if CREDENTIAL_NAME_HOLDER.search(name):
            # A name-holder bound to anything that is not a bare variable name
            # is still suspicious, so it is reported.
            hits.append(name)
            continue
        hits.append(name)
        continue
    for pattern in SECRET_VALUE:
        if pattern.search(text):
            hits.append("value-shape")
    return hits


LOCAL_ONLY_PATTERNS = ("docker-compose", "compose.yaml", "Dockerfile", ".env")


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 16), b""):
            digest.update(chunk)
    return digest.hexdigest()


def plugin_version(plugin_dir: Path) -> str:
    """The authoritative version: the plugin's own WordPress header."""
    main = plugin_dir / f"{plugin_dir.name}.php"
    for line in main.read_text(encoding="utf-8", errors="replace").splitlines()[:40]:
        if line.strip().startswith("* Version:"):
            return line.split(":", 1)[1].strip()
    return ""


def check_engine_digest() -> bool:
    """Recompute the engine digest directly from the checked-out tree (§17)."""
    engine = REPO_ROOT / commissioning.ENGINE_FILE
    if not engine.is_file():
        return False
    return sha256_file(engine) == commissioning.PINNED_ENGINE_SHA256


def check_batch_limits() -> tuple[bool, dict]:
    """Parse the ACTUAL configured limits and compare them to the ceilings.

    A missing file, an unparseable constant or a missing constant all fail
    closed: `False` is returned rather than a permissive default, so a malformed
    limit configuration can never be read as "within limits".
    """
    if not LIMITS_FILE.is_file():
        return False, {}
    source = LIMITS_FILE.read_text(encoding="utf-8", errors="replace")
    configured = {}
    for name in commissioning.BATCH_CEILINGS:
        match = re.search(r"const\s+" + name + r"\s*=\s*(\d+)\s*;", source)
        if not match:
            return False, {}
        configured[name] = int(match.group(1))
    within = all(configured[name] <= ceiling for name, ceiling in commissioning.BATCH_CEILINGS.items())
    return within, configured


def check_control_plane_declared() -> bool:
    """The batch-control surface must be DECLARED, not commissioned (§18).

    Asserted from source: `is_commissioned()` returns false, and the bootstrap
    call site that would register the endpoint stays commented out. The gate does
    not call `register()` and cannot.
    """
    if not CONTROL_FILE.is_file() or not BOOTSTRAP_FILE.is_file():
        return False
    control = CONTROL_FILE.read_text(encoding="utf-8", errors="replace")
    bootstrap = BOOTSTRAP_FILE.read_text(encoding="utf-8", errors="replace")
    if not re.search(r"function\s+is_commissioned\s*\(\s*\)\s*:\s*bool\s*\{\s*return\s+false\s*;", control):
        return False
    # The registration call must not be live code: a commented call site is the
    # Stage 11 end state, and an active one would mean a second control plane.
    for line in bootstrap.splitlines():
        stripped = line.strip()
        if stripped.startswith("//"):
            continue
        if "Batch_Control::register(" in stripped:
            return False
    return True


def check_emergency_stop_semantics() -> bool:
    """Assert the fail-closed stop semantics in the artifact (§19)."""
    if not STOP_FILE.is_file():
        return False
    source = STOP_FILE.read_text(encoding="utf-8", errors="replace")
    code = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    code = re.sub(r"^\s*//.*$", "", code, flags=re.M)
    # Absent, malformed and non-boolean all read as stopped.
    if code.count("'stopped' => true") < 2:
        return False
    if "FAILURE_MALFORMED" not in code:
        return False
    # Clearing requires an explicit authorization AND the capability.
    if "FAILURE_UNAUTHORIZED" not in code:
        return False
    return True


def build_artifacts() -> dict:
    """Build twice into a temp dir and compare. Record the exact hashes (§16).

    This is the ONLY place the preflight writes anything, it writes to a
    temporary directory it removes, and it runs the repository's own shared
    packaging script rather than reimplementing it. `--skip-build` skips it, and
    a skipped build is reported as NOT BUILT rather than assumed identical.
    """
    facts = {"built": False, "deterministic": False, "secret_free": True, "test_free": True, "local_free": True, "artifacts": {}}
    with tempfile.TemporaryDirectory(prefix="conexao-stage13-build-") as scratch:
        hashes = []
        for attempt in (1, 2):
            out = Path(scratch) / f"build{attempt}"
            env = dict(os.environ, BUILD_OUTPUT_DIR=str(out))
            proc = subprocess.run(
                [str(REPO_ROOT / "scripts" / "build-plugins-zip.sh")],
                cwd=str(REPO_ROOT), env=env, capture_output=True, text=True, check=False,
            )
            if proc.returncode != 0:
                return facts
            attempt_hashes = {}
            for slug in EXPECTED_VERSIONS:
                archive = out / f"{slug}.zip"
                if not archive.is_file():
                    return facts
                attempt_hashes[slug] = sha256_file(archive)
            hashes.append(attempt_hashes)
        facts["built"] = True
        facts["artifacts"] = hashes[0]
        facts["deterministic"] = hashes[0] == hashes[1]

        # Content scans against the SHIPPED file list, read out of the archive.
        listing = subprocess.run(
            ["unzip", "-Z1", str(Path(scratch) / "build1" / f"{'conexao-translation-automation'}.zip")],
            capture_output=True, text=True, check=False,
        )
        names = [n for n in listing.stdout.splitlines() if n.strip()]
        facts["test_free"] = not any("/tests/" in n or n.endswith(".pot.check") for n in names)
        facts["local_free"] = not any(Path(n).name in LOCAL_ONLY_PATTERNS for n in names)

        blob = b""
        for slug in EXPECTED_VERSIONS:
            archive = Path(scratch) / "build1" / f"{slug}.zip"
            extracted = subprocess.run(["unzip", "-p", str(archive)], capture_output=True, check=False)
            blob += extracted.stdout
        text = blob.decode("utf-8", errors="replace")
        facts["secret_hits"] = sorted(set(scan_for_secrets(text)))
        facts["secret_free"] = not facts["secret_hits"]
    return facts


def gather_repo_facts(args) -> dict:
    """Read every repository fact the release-readiness prerequisite needs."""
    facts = {
        "engine_digest_matches": check_engine_digest(),
        "engine_sha256": commissioning.PINNED_ENGINE_SHA256 if check_engine_digest() else sha256_file(REPO_ROOT / commissioning.ENGINE_FILE) if (REPO_ROOT / commissioning.ENGINE_FILE).is_file() else "",
        "control_plane_declared_not_commissioned": check_control_plane_declared(),
        "emergency_stop_fail_closed": check_emergency_stop_semantics(),
        # The production stop state is NOT a credential-independent fact and is
        # deliberately absent from this dict. Stage 15 removed the hardcoded
        # `production_stop_state_observed: False` that used to live here: P15
        # now reads a `production_stop_state` evidence object carrying
        # production-grade provenance, so the preflight neither observes
        # production nor asserts anything about it. A preflight that cannot see
        # production must not claim a fact about it in either direction.
        "versions": {},
        "versions_match_release": True,
        "registry_consistent": True,
    }

    within, configured = check_batch_limits()
    facts["batch_limits_within_ceilings"] = within
    facts["batch_limits"] = configured

    for slug, expected in EXPECTED_VERSIONS.items():
        found = plugin_version(PLUGIN_DIR / slug)
        facts["versions"][slug] = found
        if found != expected:
            facts["versions_match_release"] = False

    # The plugin registry is checked through the repository's own generator, in
    # --check mode (zero writes). Its exit code decides; nothing is recomputed.
    proc = subprocess.run(
        ["php", str(REPO_ROOT / "scripts" / "generate-registry-docs.php"), "--check"],
        cwd=str(REPO_ROOT), capture_output=True, text=True, check=False,
    )
    facts["registry_consistent"] = proc.returncode == 0

    # The release record, when present, must agree with the headers.
    release = REPO_ROOT / "dist" / "release.json"
    if release.is_file():
        record = json.loads(release.read_text(encoding="utf-8"))
        for artifact in record.get("artifacts", []):
            slug = artifact.get("slug")
            if slug in facts["versions"] and artifact.get("version") != facts["versions"][slug]:
                facts["versions_match_release"] = False
    else:
        facts["versions_match_release"] = False

    facts["release_built"] = False
    facts["release_deterministic"] = False
    facts["release_secret_free"] = True
    facts["release_test_free"] = True
    facts["release_local_free"] = True
    if not args.skip_build:
        built = build_artifacts()
        facts["release_built"] = built["built"]
        facts["release_deterministic"] = built["deterministic"]
        facts["release_secret_free"] = built["secret_free"]
        facts["release_test_free"] = built["test_free"]
        facts["release_local_free"] = built["local_free"]
        facts["artifacts"] = built["artifacts"]
    return facts


def load_evidence(directory: Path) -> dict:
    """Load operator-supplied evidence objects from an approved directory.

    Each file is `<evidence_type>.json`. A file that is not valid JSON, or that
    carries a credential, is passed through UNCHANGED so the validator can refuse
    it and report why — dropping it here would hide the reason.
    """
    evidence = {}
    if not directory.is_dir():
        return evidence
    for path in sorted(directory.glob("*.json")):
        try:
            evidence[path.stem] = json.loads(path.read_text(encoding="utf-8"))
        except (ValueError, OSError):
            evidence[path.stem] = {"__unreadable__": True}
    return evidence


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Evaluate every production commissioning prerequisite and print one aggregate result. Read-only.",
    )
    parser.add_argument("--json", action="store_true", help="emit the machine-readable report on stdout")
    parser.add_argument("--runbook", action="store_true", help="print the generated operator runbook and exit")
    parser.add_argument("--out", help="also write the JSON report to this path")
    parser.add_argument("--evidence", help="directory of operator-supplied evidence objects (<type>.json)")
    parser.add_argument(
        "--skip-build",
        action="store_true",
        help="skip the double build; artifact determinism is then reported as NOT BUILT, never assumed",
    )
    args = parser.parse_args()

    if args.runbook:
        sys.stdout.write(commissioning.render_runbook())
        return 0

    # Presence ONLY. No value is read from the environment, so nothing secret
    # can enter the process's decision surface, let alone the report.
    env_present = {name for name in os.environ if name.strip()}

    observation = commissioning.Observation(
        env_present=env_present,
        evidence=load_evidence(Path(args.evidence).resolve()) if args.evidence else {},
        repo=gather_repo_facts(args),
        # Reachability is recorded as a fact and is not consulted by any
        # validator. It is not probed here: a readiness check that needs the
        # network to decide would not be credential-independent.
        site_reachable=None,
    )

    report = commissioning.evaluate(observation)
    payload = json.dumps(report, indent=2, sort_keys=True)

    if args.json:
        sys.stdout.write(payload + "\n")
    else:
        sys.stdout.write(commissioning.render_text(report))

    if args.out:
        Path(args.out).write_text(payload + "\n", encoding="utf-8")

    return 0 if report["status"] == commissioning.READY else 1


if __name__ == "__main__":
    sys.exit(main())
