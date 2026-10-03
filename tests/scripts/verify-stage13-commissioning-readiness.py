#!/usr/bin/env python3
"""Stage 13 permanent gate: the commissioning-readiness contract.

## What this gate makes permanent

Stage 13 introduced ONE readiness gate and one authoritative prerequisite
registry (`scripts/lib/commissioning.py`). This file is the permanent, fail-closed
assertion that the gate still means what it claimed:

  1. the registry is COMPLETE — eighteen prerequisites, the exact expected count,
     no duplicate IDs, every required prerequisite has a validator, and the
     mapping onto the Stage 12 prerequisites is total;
  2. the report is SECRET-SAFE — it passes `assert_no_secrets()`, and a set of
     INJECTED secrets is refused rather than merely absent;
  3. readiness cannot reach production — no production-write capability, no
     provider call, no apply path, no commissioning path;
  4. authorization SEPARATION holds — install, provider-call and apply
     authorization are three independent evidence types, and satisfying one
     never satisfies another;
  5. the artifact, batch-ceiling, emergency-stop and control-plane invariants
     are asserted from source, not assumed.

## Why the negative proofs exist

A gate that has never been observed to reject anything might be asserting
nothing. So section 6 injects a REAL violation — into a throwaway observation or
a throwaway repository copy — runs the REAL `evaluate()` or the REAL structural
checks, and asserts the result is a readiness FAILURE with
`production_mutation_permitted == false`.

The CLEAN state is asserted to reach the expected baseline FIRST, because a
proof that passes because the baseline is already red proves nothing.

## What is deliberately NOT here

No WordPress, no network, no production, no credential. The gate reads files and
runs the repository's own read-only checks as subprocesses.

Usage: python3 tests/scripts/verify-stage13-commissioning-readiness.py
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path

#: A triple-single-quoted string literal, for docstring stripping. Built by
#: concatenation so this file has no nested quote syntax to escape.
TRIPLE_SINGLE = chr(39) * 3 + r"[\s\S]*?" + chr(39) * 3

REPO_ROOT = Path(os.environ.get("CONEXAO_REPO_ROOT") or Path(__file__).resolve().parents[2])
sys.path.insert(0, str(REPO_ROOT / "scripts" / "lib"))

import commissioning  # noqa: E402

GATE_ID = "stage13_commissioning_readiness"
ENGINE_LIB = REPO_ROOT / "scripts" / "lib" / "commissioning.py"
PREFLIGHT = REPO_ROOT / "scripts" / "verify-commissioning-readiness.py"
WORK = Path(tempfile.mkdtemp(prefix="conexao-stage13-"))

PASSED = 0
FAILED = 0
PROOF_PASSED = 0
PROOF_MISSED = 0
FAILURES: list[str] = []


def check(condition, message):
    global PASSED
    if condition:
        PASSED += 1
    else:
        FAILURES.append(message)
    return bool(condition)


def proof(label, condition):
    """Record one injected-negative proof."""
    global PROOF_PASSED, PROOF_MISSED
    if condition:
        PROOF_PASSED += 1
        print(f"  [proven] {label}")
    else:
        PROOF_MISSED += 1
        print(f"  [MISSED] {label}")
    return bool(condition)


# ---------------------------------------------------------------------------
# Synthetic observations
# ---------------------------------------------------------------------------

NOW = datetime(2026, 10, 1, 12, 0, 0, tzinfo=timezone.utc)


def good_repo():
    """A repository whose every invariant holds, so evidence is the only variable."""
    return {
        "engine_digest_matches": True,
        "engine_sha256": commissioning.PINNED_ENGINE_SHA256,
        "control_plane_declared_not_commissioned": True,
        "emergency_stop_fail_closed": True,
        "production_stop_state_observed": True,
        "batch_limits_within_ceilings": True,
        "batch_limits": dict(commissioning.BATCH_CEILINGS),
        "versions": {"conexao-translation-rollout": "1.2.0", "conexao-translation-automation": "0.4.0"},
        "versions_match_release": True,
        "registry_consistent": True,
        "release_built": True,
        "release_deterministic": True,
        "release_secret_free": True,
        "release_test_free": True,
        "release_local_free": True,
    }


def authorization(evidence_type, provenance=commissioning.OPERATOR_AUTHORIZATION, **overrides):
    """A well-formed authorization object, correct in every field but the override."""
    document = {
        "type": evidence_type,
        "scope": f"stage 13 {evidence_type}",
        "granted_at": (NOW - timedelta(hours=1)).isoformat(),
        "expires_at": (NOW + timedelta(hours=1)).isoformat(),
        "observed_at": (NOW - timedelta(minutes=5)).isoformat(),
        "operator": "stage-13-operator",
        "operator_confirmed": True,
        "provenance": provenance,
    }
    document.update(overrides)
    return document


def full_evidence():
    """Every evidence-backed prerequisite satisfied, each on its own provenance."""
    return {
        # P01's declared evidence source is an operator authorization, so its
        # object carries the full authorization shape. A production-access claim
        # is an operator's statement about a channel they control, NOT a
        # repository fact — which is exactly why substituting the local Docker
        # `.env` for it must fail.
        "production_access": authorization("production_access", scope="named production deployment channel"),
        "install_authorization": authorization("install_authorization"),
        "provider_quota": {"provenance": commissioning.LIVE_PROVIDER_SMOKE, "observed_at": (NOW - timedelta(minutes=5)).isoformat(), "quota": "usable"},
        "live_smoke_authorization": authorization("live_smoke_authorization"),
        "provider_smoke_success": {"provenance": commissioning.LIVE_PROVIDER_SMOKE, "observed_at": (NOW - timedelta(minutes=5)).isoformat(), "result": "RESULT: OK"},
        "human_reviewer": {"provenance": commissioning.HUMAN_REVIEW, "observed_at": (NOW - timedelta(days=1)).isoformat(), "reviewer": "named-reviewer"},
        "canary_authorization": authorization("canary_authorization"),
        "apply_authorization": authorization("apply_authorization"),
        "commissioning_authorization": authorization("commissioning_authorization"),
        "batch_control_commissioning_authorization": authorization("batch_control_commissioning_authorization"),
        "fresh_baseline": {"provenance": commissioning.LIVE_PRODUCTION_READ, "observed_at": (NOW - timedelta(minutes=10)).isoformat(), "baseline": "captured"},
        "legacy_endpoint_410": {"provenance": commissioning.LIVE_PRODUCTION_READ, "observed_at": (NOW - timedelta(minutes=10)).isoformat(), "status": 410},
        "proof_trigger": {"provenance": commissioning.LIVE_PRODUCTION_READ, "observed_at": (NOW - timedelta(minutes=10)).isoformat(), "outcome": "apply_unreachable"},
        "audit_readiness": {"provenance": commissioning.LIVE_PRODUCTION_READ, "observed_at": (NOW - timedelta(minutes=10)).isoformat(), "audit": "available"},
        "model_a_production_proof": {"provenance": commissioning.LIVE_PRODUCTION_READ, "observed_at": (NOW - timedelta(minutes=10)).isoformat(), "approved_scope": "one", "executed_scope": "one"},
        # P15 (Stage 15 fix). The production stop state is a production read, so
        # it is supplied as production evidence. The gate requires a POSITIVE
        # STOPPED; see the P15 negative proofs below for every shape it refuses.
        "production_stop_state": {"provenance": commissioning.LIVE_PRODUCTION_READ, "observed_at": (NOW - timedelta(minutes=10)).isoformat(), "state": "STOPPED"},
    }


def observation(env_present=(), evidence=None, repo=None, **kwargs):
    return commissioning.Observation(
        env_present=set(env_present),
        evidence=dict(evidence or {}),
        repo=repo if repo is not None else good_repo(),
        now=NOW,
        **kwargs,
    )


def result_for(report, key):
    for entry in report["prerequisites"]:
        if entry["key"] == key:
            return entry
    raise AssertionError(f"no prerequisite named {key}")


def ready_environment():
    """A fully evidenced environment. Used as the POSITIVE control."""
    env = {commissioning.EXPECTED_CREDENTIAL_ENV}
    return observation(env_present=env, evidence=full_evidence())


# ---------------------------------------------------------------------------
# 1. Registry completeness
# ---------------------------------------------------------------------------

def section_registry():
    print("\n-- 1. the prerequisite registry is complete and singular --")
    entries = commissioning.PREREQUISITES

    check(len(entries) == 18, f"the registry must hold exactly 18 prerequisites; it holds {len(entries)}")

    ids = [e["id"] for e in entries]
    check(len(set(ids)) == len(ids), f"duplicate prerequisite IDs: {sorted(i for i in ids if ids.count(i) > 1)}")
    keys = [e["key"] for e in entries]
    check(len(set(keys)) == len(keys), f"duplicate prerequisite keys: {sorted(k for k in keys if keys.count(k) > 1)}")

    check(ids == [f"P{n:02d}" for n in range(1, 19)], "prerequisite IDs must be P01..P18 in order")

    for entry in entries:
        pid = entry["id"]
        for field in ("id", "key", "description", "required", "evidence_source", "validator", "freshness", "secret_safety", "failure_reason", "stage12_ref"):
            check(field in entry, f"{pid} is missing the required registry field {field!r}")
        check(isinstance(entry["required"], bool), f"{pid} 'required' must be a real boolean, not a truthy value")
        check(entry["evidence_source"] in commissioning.ALL_PROVENANCE, f"{pid} declares an unknown evidence source {entry['evidence_source']!r}")
        check(entry["freshness"] in commissioning.FRESHNESS_SECONDS, f"{pid} declares an unknown freshness rule {entry['freshness']!r}")
        check(bool(entry["failure_reason"].strip()), f"{pid} has no failure reason")
        check(entry["secret_safety"] in (commissioning.SECRET_PRESENCE_ONLY, commissioning.SECRET_NO_SECRET, commissioning.SECRET_EVIDENCE_METADATA_ONLY), f"{pid} declares an unknown secret-safety rule")

        # Every required prerequisite must HAVE a validator, and it must exist.
        validator = getattr(commissioning, entry["validator"], None)
        if entry["required"]:
            check(callable(validator), f"{pid} is required but names no callable validator {entry['validator']!r}")

    # The Stage 12 mapping must be TOTAL: every Stage 12 item 1..18 covered.
    covered = set()
    for entry in entries:
        covered |= set(entry["stage12_ref"])
    check(covered == set(commissioning.STAGE12_ITEMS), f"the Stage 12 prerequisite mapping is not total; uncovered: {sorted(set(commissioning.STAGE12_ITEMS) - covered)}")

    # The categories Stage 13 requires must all be present by key.
    required_keys = {
        "production_access", "install_authorization", "provider_credential", "provider_quota",
        "live_smoke_authorization", "provider_smoke_success", "human_reviewer", "canary_authorization",
        "apply_authorization", "commissioning_authorization", "batch_control_commissioning_authorization",
        "fresh_baseline_readiness", "legacy_endpoint_verification", "proof_trigger_verification",
        "emergency_stop_readiness", "audit_readiness", "release_readiness", "model_a_production_proof",
    }
    check(required_keys == set(keys), f"the required prerequisite categories are not exactly covered; missing: {sorted(required_keys - set(keys))}, extra: {sorted(set(keys) - required_keys)}")

    # The status model is exactly the seven Stage 13 defines, plus the one
    # STAGE 17 adds for the retired automatic commissioning programme.
    check(set(commissioning.ALL_STATUSES) == {"NOT_READY", "READY", "EXPIRED", "INVALID", "BLOCKED", "COMMISSIONING", "COMMISSIONED", "RETIRED"}, "the status model does not match the Stage 13 + Stage 17 vocabulary")
    check(set(commissioning.STAGE13_FORBIDDEN_STATUSES) == {"COMMISSIONING", "COMMISSIONED"}, "Stage 13 must forbid itself from emitting COMMISSIONING/COMMISSIONED")

    # STAGE 17: the retirement. Asserted here, at the top, because it is the
    # decision every other aggregate assertion below now rests on.
    check(commissioning.AUTOMATIC_TRANSLATION_RETIRED is True, "the automatic translation commissioning programme is not marked retired")
    check(commissioning.RETIREMENT_REASON.strip() != "", "the retirement carries no stated reason; an operator could read RETIRED as 'not yet'")

    # Every prerequisite carries an explicit Stage 17 disposition, and the
    # split is total: nothing is unclassified, so the classification cannot be
    # quietly re-stated by adding a prerequisite without deciding its fate.
    dispositions = {e["id"]: e.get("disposition") for e in commissioning.PREREQUISITES}
    check(all(v in ("RETAINED", "RETIRED") for v in dispositions.values()), f"every prerequisite needs a Stage 17 disposition; got {sorted(set(dispositions.values()))}")
    for pid, why in (("P15", "P15 is retired"), ("P03", "the permanent provider credential is retired"), ("P10", "automatic commissioning authorization is retired")):
        check(dispositions[pid] == "RETIRED", f"{why}, so it must be classified RETIRED")
        entry = next(e for e in commissioning.PREREQUISITES if e["id"] == pid)
        check(str(entry.get("disposition_reason", "")).strip() != "", f"{pid} is retired without recording why")
    for pid in ("P09", "P14", "P16", "P17"):
        check(dispositions[pid] == "RETAINED", f"{pid} still guards the retained manual workflow and must stay RETAINED")


# ---------------------------------------------------------------------------
# 2. Readiness is derived, never assigned
# ---------------------------------------------------------------------------

def section_derived():
    print("\n-- 2. readiness is derived from the individual results --")

    raw = ENGINE_LIB.read_text(encoding="utf-8")
    lib = python_code(raw)

    # No boolean-only readiness. Checked against CODE, because the engine's own
    # docblock must be able to SAY "there is no ready = True": asserting the
    # phrase is absent from the file would forbid documenting the rule.
    for pattern in (r"^\s*ready\s*=\s*(?:True|False)\b", r"^\s*auto_ready\b", r"['\"]auto_ready['\"]\s*:"):
        check(re.search(pattern, lib, re.M) is None, f"the engine contains a boolean-only readiness assignment matching {pattern!r}")

    # No client input can set readiness: nothing reads argv, stdin, or a request.
    for forbidden in ("sys.argv", "input(", "os.environ.get(\"READY", "REQUEST"):
        check(forbidden not in lib, f"the engine reads client input via {forbidden!r}; readiness must not be settable by a caller")

    # The aggregate is a function of the results.
    check("def evaluate(" in raw, "the engine exposes no evaluate() entry point")
    check("commissioning_permitted" in raw, "the report exposes no commissioning_permitted field")

    # A missing prerequisite stays VISIBLE in the report.
    report = commissioning.evaluate(observation())
    check(len(report["prerequisites"]) == 18, "the report does not expose every prerequisite independently")
    check(all("status" in p and "reason" in p for p in report["prerequisites"]), "a prerequisite result lacks a status or a reason")


# ---------------------------------------------------------------------------
# 3. Secret safety
# ---------------------------------------------------------------------------

def section_secret_safety():
    print("\n-- 3. the report is secret-safe --")

    report = commissioning.evaluate(ready_environment())
    text = json.dumps(report)

    # The real report passes the boundary.
    try:
        commissioning.assert_no_secrets(report)
        check(True, "")
    except commissioning.SecretLeak as leak:
        check(False, f"the real report was refused by assert_no_secrets(): {leak}")

    # No secret-shaped substring identifying a value.
    check("sk-" not in text, "the report contains a provider-key prefix")
    check(not re.search(r"\b[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\b", text), "the report contains a four-group credential shape")

    # INJECTED secrets must be REFUSED, not merely absent.
    injected = {
        "a provider key": {"CONEXAO_TRANSLATION_PROVIDER_KEY": "sk-abcdefghij1234567890ABCDEFGH"},
        "an application password": {"wp_app_password": "abcd efgh ijkl mnop"},
        "a nonce": {"_wpnonce": "a1b2c3d4e5f6a7b8"},
        "a cookie": {"cookie": "wordpress_logged_in=xyz98765"},
        "an auth header": {"Authorization": "Basic dXNlcjpwYXNzd29yZA=="},
        "a private key": {"private_key": "-----BEGIN RSA PRIVATE KEY-----"},
        "a secret-named key": {"api_key": "harmless-looking-value"},
    }
    for label, payload in injected.items():
        refused = False
        try:
            commissioning.assert_no_secrets(payload)
        except commissioning.SecretLeak:
            refused = True
        proof(f"assert_no_secrets() refuses {label}", refused)

    # A credential smuggled into an EVIDENCE object invalidates the prerequisite
    # rather than being read.
    evidence = full_evidence()
    evidence["install_authorization"]["operator_api_key"] = "sk-abcdefghij1234567890ABCDEFGH"
    report = commissioning.evaluate(observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=evidence))
    entry = result_for(report, "install_authorization")
    check(entry["status"] in ("INVALID", "BLOCKED"), f"an evidence object carrying a credential produced {entry['status']} instead of being refused")
    check("sk-" not in json.dumps(report), "a credential smuggled into evidence reached the report")

    # The presence check reads NAMES only. Prove it by supplying a value that
    # would be catastrophic if read, and showing it is absent from the report.
    os.environ["CONEXAO_STAGE13_CANARY_VALUE"] = "sk-abcdefghij1234567890ABCDEFGH"
    try:
        report = commissioning.evaluate(observation(env_present={"CONEXAO_STAGE13_CANARY_VALUE"}))
        check("sk-abcdefghij" not in json.dumps(report), "an environment VALUE reached the report; presence must be all that is read")
    finally:
        os.environ.pop("CONEXAO_STAGE13_CANARY_VALUE", None)

    # No environment dump: no key enumerates the process environment. The WORD
    # "environment" is legitimate — a reason may say the variable is not present
    # in the environment — so this check is structural, not lexical.
    for key, _value in commissioning.flatten(report):
        check(not re.search(r"(?i)environ", key), f"the report contains an environment-shaped key {key!r}")
    nested = sum(1 for _key, value in commissioning.flatten(report) if isinstance(value, (dict, list)))
    check(nested < 40, "the report carries an unexpectedly large nested structure that may be an environment dump")


# ---------------------------------------------------------------------------
# 4. No production-write capability, no provider call, no apply path
# ---------------------------------------------------------------------------

#: Tokens that would indicate a production-mutation or provider capability.
FORBIDDEN_CAPABILITY = (
    "wp_insert_post",
    "wp_update_post",
    "wp_delete_post",
    "wp_insert_term",
    "wp_update_term",
    "wp_delete_term",
    "update_option",
    "add_option",
    "delete_option",
    "pll_set_post_language",
    "pll_save_post_translations",
    "wp_update_nav_menu",
    "wp_set_object_terms",
    "wp_remote_post",
    "wp_remote_get",
    "wp_remote_request",
    "wp_schedule_event",
    "wp_schedule_single_event",
    "register_rest_route",
    "wp_insert_attachment",
    "wp_delete_attachment",
    "update_post_meta",
    "delete_post_meta",
    "wp_cache_flush",
)

#: Provider-transport and mutation-authority tokens.
FORBIDDEN_TRANSPORT = (
    "wp_remote_post",
    "curl_exec",
    "file_get_contents(\"http",
    "requests.post",
    "httpx.post",
    "urllib.request",
    "api.openai.com",
    "chat/completions",
    "Conexao_Translation_Automation_Provider_OpenAI",
    "Conexao_Translation_Rollout_Engine::run",
    "Orchestrator::run",
    "Batch_Executor",
    "Emergency_Stop::set",
    "Emergency_Stop::clear",
    "Batch_Control::register",
    "is_commissioned( true",
    # A readiness layer written in Python could reach the same PHP class through
    # a transliterated attribute call, so the DOTTED form is forbidden too. A
    # gate that only knew the `::` spelling would be satisfied by a rename.
    "Emergency_Stop.set",
    "Batch_Control.register",
)


def strip_comments(source: str) -> str:
    """Remove C-style and `#` comments so prose cannot satisfy a code check."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    source = re.sub(r"^\s*#.*$", "", source, flags=re.M)
    return source


def python_code(source: str) -> str:
    """Strip comments, docstrings AND string literals from Python source.

    A docblock must be able to state the rule it helps enforce ("there is no
    `ready = True` here"), and a DETECTOR must be able to name what it looks
    for (`if "Batch_Control::register(" in line`). Neither is a capability.

    So a capability check runs against CODE only. That is the difference between
    "this code cannot call the batch-control `register()`" and "this file does
    not contain the characters `register()`". Nothing in these two files uses
    `eval`/`exec`, which is what makes discarding literals sound; that is
    asserted separately rather than assumed.
    """
    source = strip_comments(source)
    source = re.sub(r'"""[\s\S]*?"""', "", source)
    source = re.sub(TRIPLE_SINGLE, "", source)
    # Single- and double-quoted literals, with the usual escapes honoured.
    source = re.sub(r"'(?:\\.|[^'\\\n])*'", '""', source)
    source = re.sub(r'"(?:\\.|[^"\\\n])*"', '""', source)
    return source


def section_no_production_write():
    print("\n-- 4. readiness cannot reach production --")

    lib = python_code(ENGINE_LIB.read_text(encoding="utf-8"))
    preflight = python_code(PREFLIGHT.read_text(encoding="utf-8"))

    for token in FORBIDDEN_CAPABILITY:
        check(token not in lib, f"the readiness engine contains the production-write capability {token!r}")
        check(token not in preflight, f"the preflight contains the production-write capability {token!r}")

    for token in FORBIDDEN_TRANSPORT:
        check(token not in lib, f"the readiness engine contains the provider/mutation token {token!r}")
        check(token not in preflight, f"the preflight contains the provider/mutation token {token!r}")

    # No apply path and no commissioning path in EITHER file.
    for name, source in (("engine", lib), ("preflight", preflight)):
        check("--apply" not in source, f"the {name} exposes an --apply flag; readiness must have no write mode")
        check(not re.search(r"\bmode\s*=\s*['\"]apply['\"]", source), f"the {name} contains an apply mode selector")
        check("COMMISSIONED" not in source.replace("COMMISSIONED\"", "", 1) or "STAGE13_FORBIDDEN_STATUSES" in source, f"the {name} can set COMMISSIONED")

    # The engine has no network client and no WordPress loader at all.
    for forbidden in ("import socket", "import requests", "import urllib", "import http", "urlopen(", "wp-load", "wp-load.php"):
        check(forbidden not in lib, f"the readiness engine imports/opens {forbidden!r}; it must have no network or WordPress surface")
    check("import subprocess" not in lib, "the readiness engine spawns a subprocess; the boundary is the Observation object")

    # The preflight's ONLY subprocess use is the build, the registry check and
    # `unzip`. Matched against the RAW source because the command lives in string
    # literals, which `python_code()` deliberately discards.
    preflight_raw = PREFLIGHT.read_text(encoding="utf-8")
    subprocess_calls = re.findall(r"subprocess\.run\(\s*\[([^\]]*)\]", preflight_raw)
    check(len(subprocess_calls) > 0, "the preflight runs no subprocess; the double build and registry check must be visible")
    for call in subprocess_calls:
        check(
            "build-plugins-zip.sh" in call or "generate-registry-docs.php" in call or "unzip" in call,
            f"the preflight runs an unexpected subprocess: {call.strip()[:80]}",
        )

    # Every subprocess is invoked WITHOUT a shell, so a path cannot become a
    # command. `shell=True` would void every other argument allow-list here.
    check("shell=True" not in preflight_raw, "a subprocess is invoked with shell=True; the argument allow-list would not hold")
    for forbidden in ("os.system", "os.popen", "subprocess.Popen", "subprocess.call", "subprocess.check_output"):
        check(forbidden not in python_code(preflight_raw), f"the preflight uses the unallow-listed process API {forbidden!r}")

    # It contacts no host.
    for forbidden in ("socket.socket", "http.client", "requests.get", "curl ", "wget "):
        check(forbidden not in preflight, f"the preflight contains the network capability {forbidden!r}")

    # The preflight writes only to a temporary directory or the requested --out.
    check("TemporaryDirectory" in preflight, "the preflight does not confine its build to a temporary directory")
    check(not re.search(r"open\(\s*['\"]/(?!tmp)", preflight), "the preflight opens a path outside the repository or a temp dir")

    # A reachability probe may not be the thing that decides anything.
    check("site_reachable=None" in preflight or "site_reachable = None" in preflight, "the preflight does not record reachability as unprobed")


# ---------------------------------------------------------------------------
# 5. Authorization separation
# ---------------------------------------------------------------------------

def section_authorization_separation():
    print("\n-- 5. installation, provider-call and apply authorization are independent --")

    check(len(commissioning.AUTHORIZATION_SEPARATION) == 3, "the separation set must name exactly three permissions")
    flags = [flag for flag, _ in commissioning.AUTHORIZATION_SEPARATION]
    check(flags == ["INSTALL_AUTHORIZED", "PROVIDER_CALL_AUTHORIZED", "CANARY_APPLY_AUTHORIZED"], f"the three required permission flags are not exactly named: {flags}")
    types = [t for _, t in commissioning.AUTHORIZATION_SEPARATION]
    check(len(set(types)) == 3, "two permissions share an evidence type; they could not be independent")

    # Each flag is satisfied ONLY by its own evidence type.
    for flag, evidence_type in commissioning.AUTHORIZATION_SEPARATION:
        for other_flag, other_type in commissioning.AUTHORIZATION_SEPARATION:
            if other_flag == flag:
                continue
            evidence = {evidence_type: authorization(evidence_type)}
            state = commissioning.authorization_state(observation(evidence=evidence))
            check(state[flag] is True, f"{flag} was not satisfied by its own evidence object")
            check(state[other_flag] is False, f"an {evidence_type} object also satisfied {other_flag}; the permissions are not separated")

    # An object of the WRONG type is refused, not coerced.
    for flag, evidence_type in commissioning.AUTHORIZATION_SEPARATION:
        for other_flag, other_type in commissioning.AUTHORIZATION_SEPARATION:
            if other_flag == flag:
                continue
            evidence = {evidence_type: authorization(other_type, type=other_type)}
            state = commissioning.authorization_state(observation(evidence=evidence))
            check(state[flag] is False, f"an {other_type} object was accepted as {flag}")

    # The positive control: ALL evidence present, all three granted.
    state = commissioning.authorization_state(observation(evidence=full_evidence()))
    check(all(state[flag] for flag, _ in commissioning.AUTHORIZATION_SEPARATION), "the positive control did not grant all three permissions")

    # Commissioning authorization is separate from installation authorization.
    evidence = full_evidence()
    del evidence["commissioning_authorization"]
    report = commissioning.evaluate(observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=evidence))
    check(result_for(report, "install_authorization")["status"] == "READY", "installation authorization should still hold when only commissioning authorization is absent")
    check(result_for(report, "commissioning_authorization")["status"] != "READY", "commissioning authorization must not be implied by installation authorization")
    check(report["commissioning_permitted"] is False, "readiness permitted commissioning without commissioning authorization")

    # Canary authorization is separate from apply authorization.
    evidence = full_evidence()
    del evidence["apply_authorization"]
    report = commissioning.evaluate(observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=evidence))
    check(result_for(report, "canary_authorization")["status"] == "READY", "canary authorization should hold when only apply authorization is absent")
    check(result_for(report, "apply_authorization")["status"] != "READY", "apply authorization must not be implied by canary authorization")


# ---------------------------------------------------------------------------
# 6. The injected-negative proofs
# ---------------------------------------------------------------------------

def section_negative_proofs():
    print("\n-- 6. injected-negative proofs: the gate must FAIL, shown to fail --")

    # The CLEAN baseline first. A proof that passes because the baseline is
    # already red proves nothing, so the fully-evidenced control must reach the
    # BEST status the gate can now produce.
    #
    # STAGE 17: that best status is RETIRED, not READY. Eighteen pieces of
    # evidence can no longer produce READY, because permanent automatic
    # commissioning was retired rather than satisfied. What this assertion
    # really guards — and what every negative below depends on — is that the
    # baseline reaches the CEILING and still permits no commissioning.
    report = commissioning.evaluate(ready_environment())
    check(report["status"] == "RETIRED", f"the positive control is not RETIRED ({report['status']}); the negatives below would prove nothing")
    check(report["commissioning_permitted"] is False, "the positive control permitted commissioning; the negatives below would prove nothing")
    check(report["automatic_translation"] == "RETIRED", "the positive control does not report the automatic programme as retired")
    # Every prerequisite still reached READY on its own merits: retiring the
    # AGGREGATE must not have silently skipped the evaluation itself.
    check(all(p["status"] == "READY" for p in report["prerequisites"]), "retiring the aggregate skipped or altered individual prerequisite evaluation")

    def fails(label, obs, expect_key=None, expect_status=None):
        """A readiness failure, with production mutation still forbidden."""
        result = commissioning.evaluate(obs)
        ready = result["status"] == "READY"
        no_mutation = result["production_mutation_permitted"] is False
        ok = (not ready) and no_mutation
        if expect_key is not None:
            entry = result_for(result, expect_key)
            ok = ok and entry["status"] == (expect_status or "BLOCKED")
        return proof(f"{label} -> readiness failure, production_mutation_permitted=false", ok)

    def without(key):
        evidence = full_evidence()
        evidence.pop(key, None)
        return evidence

    # 1. missing production credential
    fails("1. missing production credential", observation(evidence=without("production_access")), "production_access")

    # 2. local Docker credential substituted. A repository artifact is not a
    #    production access proof, and the gate says so PRECISELY: the status is
    #    INVALID (evidence exists and conflicts with what is required), not a
    #    vague BLOCKED. Asserting the precise status is what makes the proof
    #    meaningful — a gate that merely went red for the wrong reason would
    #    pass a weaker test.
    evidence = full_evidence()
    evidence["production_access"] = {
        "provenance": commissioning.REPOSITORY_ARTIFACT,
        "observed_at": (NOW - timedelta(minutes=1)).isoformat(),
        "source": "the local Docker .env",
    }
    fails(
        "2. local Docker credential substituted for a production channel",
        observation(evidence=evidence),
        "production_access",
        "INVALID",
    )

    # 3. unrelated OPENAI_API_KEY substituted for the approved variable
    fails(
        "3. unrelated OPENAI_API_KEY substituted for the provider credential",
        observation(env_present={"OPENAI_API_KEY"}, evidence=full_evidence()),
        "provider_credential",
    )

    # 4. missing provider credential
    fails("4. missing provider credential", observation(env_present=set(), evidence=full_evidence()), "provider_credential")

    # 5. missing provider quota proof
    fails("5. missing provider quota proof", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=without("provider_quota")), "provider_quota")

    # 6. missing installation authorization
    fails("6. missing installation authorization", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=without("install_authorization")), "install_authorization")

    # 7. missing provider-call authorization
    fails("7. missing provider-call authorization", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=without("live_smoke_authorization")), "live_smoke_authorization")

    # 8. missing human reviewer
    fails("8. missing human reviewer", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=without("human_reviewer")), "human_reviewer")

    # 9. missing canary authorization
    fails("9. missing canary authorization", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=without("canary_authorization")), "canary_authorization")

    # 10. missing apply authorization
    fails("10. missing apply authorization", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=without("apply_authorization")), "apply_authorization")

    # 11. stale authorization: valid, but older than its freshness budget.
    evidence = full_evidence()
    stale = (NOW - timedelta(seconds=commissioning.FRESHNESS_SECONDS[commissioning.FRESH_AUTHORIZATION] + 60)).isoformat()
    evidence["apply_authorization"] = authorization("apply_authorization", observed_at=stale, granted_at=stale)
    fails("11. stale authorization (beyond its freshness budget)", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=evidence), "apply_authorization", "EXPIRED")

    # 12. expired authorization: its own expiry has passed.
    evidence = full_evidence()
    evidence["canary_authorization"] = authorization(
        "canary_authorization",
        granted_at=(NOW - timedelta(hours=5)).isoformat(),
        expires_at=(NOW - timedelta(hours=1)).isoformat(),
    )
    fails("12. expired authorization", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=evidence), "canary_authorization", "EXPIRED")

    # 13. stale production baseline
    evidence = full_evidence()
    evidence["fresh_baseline"] = {
        "provenance": commissioning.LIVE_PRODUCTION_READ,
        "observed_at": (NOW - timedelta(seconds=commissioning.FRESHNESS_SECONDS[commissioning.FRESH_PRODUCTION_READ] + 60)).isoformat(),
        "baseline": "captured",
    }
    fails("13. stale production baseline", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=evidence), "fresh_baseline_readiness", "EXPIRED")

    # 14. missing legacy 410 production proof
    fails("14. missing production 410 proof", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=without("legacy_endpoint_410")), "legacy_endpoint_verification")

    # 15. missing production Model A proof
    fails("15. missing production Model A proof", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=without("model_a_production_proof")), "model_a_production_proof")

    # 16. batch limit over ceiling
    repo = good_repo()
    repo["batch_limits_within_ceilings"] = False
    fails("16. a configured batch limit over its Stage 10 ceiling", observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=full_evidence(), repo=repo), "release_readiness")

    # 17. emergency stop "cleared" by client input
    obs = ready_environment()
    obs.evidence["emergency_stop_override"] = {"stopped": False, "clear_emergency_stop": True}
    result = commissioning.evaluate(obs)
    # STAGE 17: the baseline assertion moves READY -> RETIRED. What this proof
    # guards is that client input cannot clear the emergency stop on an
    # otherwise-ceiling control; the retirement changed which status the
    # ceiling IS, not whether the clearance stays refused.
    ok = result["emergency_stop_clearance"] == "NOT_GRANTED" and result["production_actions"]["emergency_stop_clears"] == 0 and result["status"] == "RETIRED"
    proof("17. emergency stop cleared by client input -> clearance stays NOT_GRANTED, zero clears", ok)

    # 17b-17i. P15 (Stage 15 fix): the production stop state must be a POSITIVE
    # production observation. Each of these is a way an operator might
    # otherwise make P15 pass without production actually being observed
    # stopped, and every one of them must fail closed.
    def p15_evidence(**overrides):
        document = dict(full_evidence()["production_stop_state"])
        document.update(overrides)
        return document

    def p15_observation(document=None, repo=None, omit=False):
        """An observation carrying a (possibly doctored) P15 evidence object."""
        evidence = full_evidence()
        if omit:
            evidence.pop("production_stop_state", None)
        else:
            evidence["production_stop_state"] = document
        return observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=evidence, repo=repo or good_repo())

    def p15_status(report):
        return result_for(report, "emergency_stop_readiness")["status"]

    # The positive control already proves the accepting shapes, so assert the
    # canonical one is READY here before the refusals, or they prove nothing.
    check(p15_status(commissioning.evaluate(ready_environment())) == "READY", "P15 does not accept a positive production STOPPED observation")

    fails("17b. a production stop state that is not stopped", p15_observation(p15_evidence(state="CLEARED")), "emergency_stop_readiness")
    fails("17c. a production stop state that is explicitly false", p15_observation(p15_evidence(state=False)), "emergency_stop_readiness")
    fails("17d. an unobserved production stop state", p15_observation(p15_evidence(state=None)), "emergency_stop_readiness")
    fails("17e. a malformed production stop state", p15_observation(p15_evidence(state="garbage")), "emergency_stop_readiness")
    # A self-contradictory object must not be rescued by preferring one key.
    fails("17e2. a self-contradictory stop state (STOPPED beside stopped=false)", p15_observation(p15_evidence(state="STOPPED", stopped=False)), "emergency_stop_readiness")
    fails("17f. a missing production stop state", p15_observation(omit=True), "emergency_stop_readiness")
    # A local or artifact proof of "stopped" must never stand in for production.
    # These are INVALID rather than BLOCKED: the object was read and its
    # provenance refused, which is a stronger statement than "not supplied".
    for provenance in (commissioning.LOCAL_ENVIRONMENT, commissioning.REPOSITORY_ARTIFACT):
        fails(f"17g. a {provenance} stop-state proof substituted for production", p15_observation(p15_evidence(provenance=provenance)), "emergency_stop_readiness", "INVALID")
    # Both halves are required: a production STOPPED does not excuse an artifact
    # whose emergency stop does not fail closed.
    fails("17h. production STOPPED but the artifact does not fail closed",
          p15_observation(p15_evidence(), repo={**good_repo(), "emergency_stop_fail_closed": False}),
          "emergency_stop_readiness")
    # A stale production read goes EXPIRED: production state moves, so an old
    # observation of "stopped" is not a current one.
    fails("17i. a production stop state observed beyond the freshness budget",
          p15_observation(p15_evidence(observed_at=(NOW - timedelta(hours=2)).isoformat())),
          "emergency_stop_readiness", "EXPIRED")

    # 17j. THE REGRESSION THIS FIX EXISTS FOR: a preflight that hardcodes the
    # production stop state can never reach READY. P15 must be satisfiable by a
    # production observation, and P15 must not be satisfiable by a repository
    # fact alone.
    check(
        next(e for e in commissioning.PREREQUISITES if e["id"] == "P15")["evidence_type"] == "production_stop_state",
        "P15 no longer reads a production_stop_state evidence object; it would be unsatisfiable again",
    )
    # Both of these must be CODE usages, not prose: the identifiers appear in the
    # explanatory comments that record why they were removed, so a bare substring
    # search would match the very comment documenting the fix.
    check(
        not re.search(r"""(?:repo|observation\.repo)\s*(?:\.get\(|\[)[\"']production_stop_state_observed[\"']""", ENGINE_LIB.read_text(encoding="utf-8")),
        "the readiness engine still READS a production_stop_state_observed repository fact; it would be "
        "unsatisfiable again",
    )
    check(
        not re.search(r"[\"']production_stop_state_observed[\"']\s*:", PREFLIGHT.read_text(encoding="utf-8")),
        "the preflight still hardcodes a production_stop_state_observed fact; a credential-independent "
        "check must not assert a fact about production in either direction",
    )

    # 18-21 are STRUCTURAL: they inject into a throwaway copy of the repository
    # and assert the real structural checks reject it.
    section_structural_proofs()

    # 22. the engine digest must block when it does not match.
    repo = good_repo()
    repo["engine_digest_matches"] = False
    result = commissioning.evaluate(observation(env_present={commissioning.EXPECTED_CREDENTIAL_ENV}, evidence=full_evidence(), repo=repo))
    # STAGE 17: this proof guards that engine-digest drift is DETECTED and
    # REPORTED. Asserting a particular aggregate status would have tested the
    # retirement rather than the digest, so it asserts the property that
    # matters: the invariant still fails, it is still named in
    # `failed_invariants`, and the aggregate still grants no permission.
    # That is exactly the "evaluate first, decide last" guarantee — a retired
    # aggregate must never HIDE an integrity failure.
    proof(
        "22. engine digest drift -> detected, reported and never permitted",
        "engine_digest_matches" in result["failed_invariants"]
        and result["status"] == "RETIRED"
        and result["commissioning_permitted"] is False
        and result["production_mutation_permitted"] is False,
    )


def fresh_repo_copy():
    """A throwaway copy of the files the structural proofs read."""
    root = WORK / "repo"
    if root.exists():
        shutil.rmtree(root)
    (root / "wp-content" / "plugins" / "conexao-translation-automation" / "includes").mkdir(parents=True)
    (root / "wp-content" / "plugins" / "conexao-translation-rollout" / "includes").mkdir(parents=True)
    (root / "scripts" / "lib").mkdir(parents=True)
    for name in ("class-conexao-translation-automation-batch-limits.php",
                 "class-conexao-translation-automation-batch-control.php",
                 "class-conexao-translation-automation-emergency-stop.php"):
        shutil.copy2(REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation" / "includes" / name,
                     root / "wp-content" / "plugins" / "conexao-translation-automation" / "includes" / name)
    shutil.copy2(REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation" / "conexao-translation-automation.php",
                 root / "wp-content" / "plugins" / "conexao-translation-automation" / "conexao-translation-automation.php")
    shutil.copy2(REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-rollout" / "includes" / "class-conexao-translation-rollout-engine.php",
                 root / "wp-content" / "plugins" / "conexao-translation-rollout" / "includes" / "class-conexao-translation-rollout-engine.php")
    shutil.copy2(ENGINE_LIB, root / "scripts" / "lib" / "commissioning.py")
    shutil.copy2(PREFLIGHT, root / "scripts" / "verify-commissioning-readiness.py")
    return root


def inject(root, relative, old, new):
    """Replace `old` with `new` in the copy. Fails loudly if the anchor is gone."""
    path = root / relative
    body = path.read_text(encoding="utf-8")
    if old not in body:
        raise AssertionError(f"negative-proof anchor missing in {relative}: {old[:60]!r}")
    path.write_text(body.replace(old, new, 1), encoding="utf-8")


LIMITS_REL = "wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-batch-limits.php"
CONTROL_REL = "wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-batch-control.php"
BOOTSTRAP_REL = "wp-content/plugins/conexao-translation-automation/conexao-translation-automation.php"


def section_structural_proofs():
    print("\n   -- structural proofs against a throwaway repository copy --")

    # The CLEAN copy must pass the structural checks first.
    root = fresh_repo_copy()
    proc = subprocess.run(
        [sys.executable, str(REPO_ROOT / "tests" / "scripts" / "verify-stage13-commissioning-readiness.py"), "--structural-only", "--repo", str(root)],
        capture_output=True, text=True, check=False,
    )
    check(proc.returncode == 0, f"the clean structural copy did not pass: {proc.stdout.strip()[-400:]}")

    # 16. a batch limit raised over its ceiling.
    fresh_repo_copy()
    inject(WORK / "repo", LIMITS_REL, "const SERVER_CEILING_BATCH_RECORDS = 5;", "const SERVER_CEILING_BATCH_RECORDS = 500;")
    proof("16b. SERVER_CEILING_BATCH_RECORDS raised to 500", not structural_ok())

    # A missing limit constant must fail closed, not default.
    fresh_repo_copy()
    inject(WORK / "repo", LIMITS_REL, "const MAX_EXECUTION_SECONDS = 300;", "const MAX_EXECUTION_SECONDS_UNSET = 300;")
    proof("16c. a batch limit constant removed entirely (must fail closed)", not structural_ok())

    # 18. a second batch-control surface.
    fresh_repo_copy()
    inject(WORK / "repo", CONTROL_REL,
           "public static function is_commissioned(): bool {\n\t\treturn false;",
           "public static function is_commissioned(): bool {\n\t\treturn true;")
    proof("18. the batch-control surface reports itself commissioned", not structural_ok())

    fresh_repo_copy()
    inject(WORK / "repo", BOOTSTRAP_REL, "// Conexao_Translation_Automation_Batch_Control::register();", "Conexao_Translation_Automation_Batch_Control::register();")
    proof("18b. the batch-control register() call site is activated", not structural_ok())

    # 19. a direct apply path inside the readiness layer.
    fresh_repo_copy()
    # A real CALL, not a string: the capability scan runs on code with literals
    # stripped, so injecting a quoted token would test the stripper, not the gate.
    inject(WORK / "repo", "scripts/lib/commissioning.py",
           "def evaluate(observation):",
           "def _hidden_apply():\n    return wp_insert_post(array())\n\n\ndef evaluate(observation):")
    proof("19. a direct apply path (a real wp_insert_post call) appears in the readiness engine", not structural_ok())

    # 20. a provider call inside readiness.
    fresh_repo_copy()
    inject(WORK / "repo", "scripts/lib/commissioning.py",
           "def evaluate(observation):",
           "def _smoke():\n    return wp_remote_post('https://api.openai.com/v1/chat/completions', array())\n\n\ndef evaluate(observation):")
    proof("20. a provider call (a real wp_remote_post call) appears inside the readiness engine", not structural_ok())

    # 21. a hidden production mutation.
    fresh_repo_copy()
    inject(WORK / "repo", "scripts/verify-commissioning-readiness.py",
           "def sha256_file(path: Path) -> str:",
           "def _hidden():\n    return pll_set_post_language(1, 'en')\n\n\ndef sha256_file(path: Path) -> str:")
    proof("21. a hidden production mutation (a real pll_set_post_language call) appears in the preflight", not structural_ok())

    # 22. an emergency-stop clear path.
    fresh_repo_copy()
    inject(WORK / "repo", "scripts/lib/commissioning.py",
           "def evaluate(observation):",
           "def _clear():\n    return Emergency_Stop.set(False, array())\n\n\ndef evaluate(observation):")
    proof("22b. an emergency-stop clear path (a real Emergency_Stop::set call) appears in the readiness engine", not structural_ok())

    # A batch limit that a request parameter could raise must be refused: the
    # structural check must find the raise.
    fresh_repo_copy()
    inject(WORK / "repo", LIMITS_REL,
           "public static function configuration(): array {",
           "public static function configuration(): array {\n\t\t$size = isset( $_REQUEST['batch_size'] ) ? (int) $_REQUEST['batch_size'] : 0;")
    proof("22c. a batch limit becomes raisable by a request parameter", not structural_ok())


def structural_ok():
    """Run ONLY the structural checks of this gate against the throwaway copy."""
    proc = subprocess.run(
        [sys.executable, str(REPO_ROOT / "tests" / "scripts" / "verify-stage13-commissioning-readiness.py"), "--structural-only", "--repo", str(WORK / "repo")],
        capture_output=True, text=True, check=False,
    )
    return proc.returncode == 0


# ---------------------------------------------------------------------------
# 7. Artifact, ceiling, emergency-stop and control-plane invariants
# ---------------------------------------------------------------------------

def section_invariants():
    print("\n-- 7. artifact, ceiling, emergency-stop and control-plane invariants --")

    engine = REPO_ROOT / commissioning.ENGINE_FILE
    check(engine.is_file(), f"the shared engine is missing at {commissioning.ENGINE_FILE}")
    if engine.is_file():
        import hashlib
        digest = hashlib.sha256(engine.read_bytes()).hexdigest()
        check(digest == commissioning.PINNED_ENGINE_SHA256, f"the engine digest changed: {digest} != {commissioning.PINNED_ENGINE_SHA256}")

    # The ceiling set is the Stage 10 set, unchanged.
    check(commissioning.BATCH_CEILINGS == {
        "SERVER_CEILING_BATCH_RECORDS": 5,
        "MAX_RECORDS_PER_STAGE": 5,
        "MAX_OPERATIONS_PER_BATCH": 5,
        "MAX_TOTAL_PROVIDER_CALLS": 20,
        "MAX_RETRIES_PER_RECORD": 1,
        "MAX_ATTEMPTS_PER_RECORD": 2,
        "MAX_EXECUTION_SECONDS": 300,
        "MAX_PRODUCTION_BATCHES_PER_INVOCATION": 1,
    }, "the batch ceilings no longer match the Stage 10 set")

    # The REAL configured limits, read from source, are within them.
    limits_file = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation" / "includes" / "class-conexao-translation-automation-batch-limits.php"
    source = limits_file.read_text(encoding="utf-8") if limits_file.is_file() else ""
    for name, ceiling in commissioning.BATCH_CEILINGS.items():
        match = re.search(r"const\s+" + name + r"\s*=\s*(\d+)\s*;", source)
        if check(match is not None, f"the batch limit {name} is missing from the source; a missing limit must fail closed"):
            check(int(match.group(1)) <= ceiling, f"the configured {name}={match.group(1)} exceeds its ceiling of {ceiling}")

    # No request parameter may raise a limit.
    check(not re.search(r"\$_REQUEST\s*\[\s*['\"]batch_size", source), "a request parameter can read a batch size")
    check(not re.search(r"\$_REQUEST\s*\[\s*['\"]operations", source), "a request parameter can read an operation count")

    # The emergency stop is STOPPED by default and cannot be cleared by input.
    stop_file = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation" / "includes" / "class-conexao-translation-automation-emergency-stop.php"
    stop = stop_file.read_text(encoding="utf-8") if stop_file.is_file() else ""
    code = re.sub(r"/\*.*?\*/", "", stop, flags=re.S)
    check(code.count("'stopped' => true") >= 2, "the emergency stop does not fail closed on both the absent and the malformed case")
    check("FAILURE_MALFORMED" in code, "a malformed emergency-stop record is not treated as STOPPED")
    check(not re.search(r"\$_(?:REQUEST|POST|GET)\s*\[\s*['\"]stopped", code), "a request field can be read as the emergency stop")
    check(not re.search(r"\$_(?:REQUEST|POST|GET)\s*\[\s*['\"]clear_emergency_stop", code), "a request field can clear the emergency stop")

    # The batch-control surface is DECLARED, not commissioned.
    control_file = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation" / "includes" / "class-conexao-translation-automation-batch-control.php"
    control = control_file.read_text(encoding="utf-8") if control_file.is_file() else ""
    check(re.search(r"function\s+is_commissioned\s*\(\s*\)\s*:\s*bool\s*\{\s*return\s+false\s*;", control) is not None, "is_commissioned() no longer returns false; the surface is not DECLARED")
    # Exactly one action-name constant. The five action constants share the
    # `ACTION_` prefix, so the count is of the exact declaration, not the
    # substring: `ACTION_PREPARE` is not a second action name.
    check(len(re.findall(r"const\s+ACTION\s*=", control)) == 1, "the batch-control action name is not declared exactly once")

    bootstrap_file = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation" / "conexao-translation-automation.php"
    bootstrap = bootstrap_file.read_text(encoding="utf-8") if bootstrap_file.is_file() else ""
    for line in bootstrap.splitlines():
        if "Batch_Control::register(" in line and not line.strip().startswith("//"):
            check(False, "the batch-control register() call site is live code in the bootstrap")
            break
    else:
        check(True, "")

    # Declared in the ONE control-plane registry, not a second inventory.
    registry = REPO_ROOT / "tests" / "scripts" / "verify-stage8-control-plane.py"
    registry_text = registry.read_text(encoding="utf-8") if registry.is_file() else ""
    check("conexao_translation_automation_batch" in registry_text, "the batch-control action is not declared in the single control-plane registry")
    check("class-conexao-translation-automation-batch-control.php" in registry_text, "the batch-control owning file is not declared in the single control-plane registry")

    # The preflight exits non-zero when not READY, and exposes no write mode.
    proc = subprocess.run([sys.executable, str(PREFLIGHT), "--help"], capture_output=True, text=True, check=False)
    check("--apply" not in proc.stdout, "the preflight advertises an --apply flag")
    check("--confirm-production" not in proc.stdout, "the preflight advertises a production write confirmation")
    check("Read-only" in proc.stdout or "read-only" in proc.stdout, "the preflight does not declare its read-only scope")

    # The runbook is generated from the registry, not hand-written.
    runbook = commissioning.render_runbook()
    # STAGE 17: the nineteen-step AUTOMATIC commissioning runbook is replaced by
    # the ten-step MANUAL translation workflow. The count is asserted exactly,
    # as before, so neither list can grow or shrink unnoticed.
    check(len(commissioning.RUNBOOK_STEPS) == 10, f"the manual runbook must have 10 steps; it has {len(commissioning.RUNBOOK_STEPS)}")
    check("Manual translation runbook (Stage 17)" in runbook, "the runbook is not the Stage 17 manual runbook")
    check("commission automatic" not in runbook.lower() or "retired" in runbook.lower(), "the runbook still presents automatic commissioning as a live procedure")
    # The manual workflow must still name every lifecycle stage an operator
    # relies on, so the retirement cannot quietly shorten the procedure.
    for phrase in ("dry run", "Approve the exact digest", "Apply explicitly", "Verify", "idempotence", "automatic execution OFF"):
        check(phrase in runbook, f"the manual runbook no longer names the \"{phrase}\" step")
    for number, title, *_ in commissioning.RUNBOOK_STEPS:
        check(f"## {number}. {title}" in runbook, f"runbook step {number} is missing from the generated runbook")
    for pid, entry in ((e["id"], e) for e in commissioning.PREREQUISITES):
        check(f"`{pid}` {entry['key']}" in runbook, f"prerequisite {pid} does not appear in the generated runbook")


# ---------------------------------------------------------------------------
# 8. The preflight is read-only against the real repository
# ---------------------------------------------------------------------------

def section_preflight_run():
    print("\n-- 8. the real preflight, run against this repository --")

    proc = subprocess.run([sys.executable, str(PREFLIGHT)], cwd=str(REPO_ROOT), capture_output=True, text=True, check=False)
    output = proc.stdout
    check(proc.returncode == 0, f"the preflight exited {proc.returncode}; RETIRED is a definitive answer and must exit 0")
    check("AUTOMATIC TRANSLATION COMMISSIONING:" in output, "the preflight did not print the aggregate status line")
    check("RETIRED (Stage 17)" in output, "the preflight does not lead with the retirement")
    check("commissioning_permitted=" in output, "the preflight did not print commissioning_permitted")
    check("production_mutation_permitted=" in output, "the preflight did not print production_mutation_permitted")

    # Every prerequisite appears with its own line, so none is hidden.
    for entry in commissioning.PREREQUISITES:
        check(f"{entry['id']} {entry['key']}" in output, f"the preflight output does not expose {entry['id']} {entry['key']} independently")

    # The JSON report is machine-readable and secret-safe.
    proc = subprocess.run([sys.executable, str(PREFLIGHT), "--json"], cwd=str(REPO_ROOT), capture_output=True, text=True, check=False)
    try:
        report = json.loads(proc.stdout)
        check(True, "")
    except ValueError as error:
        check(False, f"the preflight did not emit valid JSON: {error}")
        return
    try:
        commissioning.assert_no_secrets(report)
        check(True, "")
    except commissioning.SecretLeak as leak:
        check(False, f"the real preflight report was refused by assert_no_secrets(): {leak}")

    # The current environment honestly reports BLOCKED: the external
    # prerequisites are genuinely absent, and the gate must say so rather than
    # infer them.
    check(report["status"] == "RETIRED", f"the real preflight reported {report['status']}; permanent automatic translation is retired, so the aggregate must be RETIRED")
    check(report["automatic_translation"] == "RETIRED", "the real preflight does not report the automatic programme as retired")
    # The dispositions are reported, so the classification is visible to an
    # operator and not only to this gate.
    check(report["dispositions"]["P15"] == "RETIRED", "the real preflight does not report P15 as retired")
    check(report["commissioning_permitted"] is False, "the real preflight permitted commissioning with no prerequisites supplied")
    check(report["production_mutation_permitted"] is False, "the real preflight permitted a production mutation")
    check(report["emergency_stop_clearance"] == "NOT_GRANTED", "the real preflight granted emergency-stop clearance")
    check(report["model_a_production_proof"] == "NOT_PROVEN", "the real preflight claimed a production Model A proof")
    check(report["canary_selection_allowed"] is False, "the real preflight allowed canary selection")
    check(report["provider"]["provider_requests_from_this_gate"] == 0, "the readiness gate reported a provider request")
    check(sum(report["production_actions"].values()) == 0, "the readiness gate reported a production action")

    # The three permissions are all false in the real environment.
    for flag, _ in commissioning.AUTHORIZATION_SEPARATION:
        check(report["permissions"][flag] is False, f"{flag} was granted in an environment with no authorization evidence")

    # The release-readiness detail carries the exact hashes and versions.
    entry = result_for(report, "release_readiness")
    check(entry["status"] == "READY", f"release_readiness is {entry['status']} in a clean tree: {entry['reason']}")
    detail = entry["detail"]
    check(detail.get("engine_sha256") == commissioning.PINNED_ENGINE_SHA256, "the release detail does not record the engine digest")
    check(set(detail.get("versions", {})) == {"conexao-translation-rollout", "conexao-translation-automation"}, "the release detail does not record both plugin versions")
    for slug, digest in detail.get("artifacts", {}).items():
        check(re.fullmatch(r"[0-9a-f]{64}", digest) is not None, f"the recorded artifact hash for {slug} is not a SHA-256")


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def structural_only(repo_root):
    """Run ONLY the structural checks against `repo_root`, for the proofs.

    Exits 0 when the copy is structurally sound, 1 when a violation is present.
    This is the SAME code the full gate uses for these checks, so a proof cannot
    pass against a weaker implementation than the real gate runs.

    Scope is deliberately narrow. A throwaway copy carries no `dist/` release
    record and no generated plugin-registry state, so the release and preflight
    sections are not what the structural proofs are about; only the CAPABILITY
    surface, the batch ceilings, the emergency-stop semantics and the
    control-plane declaration are — and each injected proof targets one of those.
    """
    global REPO_ROOT, ENGINE_LIB, PREFLIGHT, FAILURES
    real_root, real_lib, real_preflight = REPO_ROOT, ENGINE_LIB, PREFLIGHT
    real_failures = FAILURES
    # The proofs call this AFTER other sections have run, so the parent's
    # accumulated failures must not decide this mode's exit code — otherwise a
    # clean copy is reported as failing for an unrelated reason.
    FAILURES = []
    REPO_ROOT = Path(repo_root)
    ENGINE_LIB = REPO_ROOT / "scripts" / "lib" / "commissioning.py"
    PREFLIGHT = REPO_ROOT / "scripts" / "verify-commissioning-readiness.py"
    try:
        section_structural_capability()
        section_structural_invariants()
        if FAILURES:
            for failure in FAILURES:
                print(f"STRUCTURAL-FAIL: {failure}", file=sys.stderr)
        return 1 if FAILURES else 0
    finally:
        REPO_ROOT, ENGINE_LIB, PREFLIGHT, FAILURES = real_root, real_lib, real_preflight, real_failures


def section_structural_capability():
    """The capability scan only. Usable against a throwaway repository copy."""
    for token in FORBIDDEN_CAPABILITY:
        check(token not in python_code(ENGINE_LIB.read_text(encoding="utf-8")), f"the readiness engine contains the production-write capability {token!r}")
        check(token not in python_code(PREFLIGHT.read_text(encoding="utf-8")), f"the preflight contains the production-write capability {token!r}")
    for token in FORBIDDEN_TRANSPORT:
        check(token not in python_code(ENGINE_LIB.read_text(encoding="utf-8")), f"the readiness engine contains the provider/mutation token {token!r}")
        check(token not in python_code(PREFLIGHT.read_text(encoding="utf-8")), f"the preflight contains the provider/mutation token {token!r}")


def section_structural_invariants():
    """Ceilings, stop semantics and the control-plane declaration, from source."""
    limits = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation" / "includes" / "class-conexao-translation-automation-batch-limits.php"
    source = limits.read_text(encoding="utf-8") if limits.is_file() else ""
    for name, ceiling in commissioning.BATCH_CEILINGS.items():
        match = re.search(r"const\s+" + name + r"\s*=\s*(\d+)\s*;", source)
        if check(match is not None, f"the batch limit {name} is missing from the source; a missing limit must fail closed"):
            check(int(match.group(1)) <= ceiling, f"the configured {name}={match.group(1)} exceeds its ceiling of {ceiling}")
    check(not re.search(r"\$_(?:REQUEST|POST|GET)\s*\[\s*['\"]batch_size", source), "a request parameter can raise a batch size")
    check(not re.search(r"\$_(?:REQUEST|POST|GET)\s*\[\s*['\"]operations", source), "a request parameter can read an operation count")

    stop_file = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation" / "includes" / "class-conexao-translation-automation-emergency-stop.php"
    stop = stop_file.read_text(encoding="utf-8") if stop_file.is_file() else ""
    code = re.sub(r"/\*.*?\*/", "", stop, flags=re.S)
    check(code.count("'stopped' => true") >= 2, "the emergency stop does not fail closed on both the absent and the malformed case")
    check(not re.search(r"\$_(?:REQUEST|POST|GET)\s*\[\s*['\"](?:stopped|clear_emergency_stop)", code), "a request field can move the emergency stop")

    control_file = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation" / "includes" / "class-conexao-translation-automation-batch-control.php"
    control = control_file.read_text(encoding="utf-8") if control_file.is_file() else ""
    check(re.search(r"function\s+is_commissioned\s*\(\s*\)\s*:\s*bool\s*\{\s*return\s+false\s*;", control) is not None, "is_commissioned() no longer returns false; the surface is not DECLARED")

    bootstrap_file = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation" / "conexao-translation-automation.php"
    bootstrap = bootstrap_file.read_text(encoding="utf-8") if bootstrap_file.is_file() else ""
    for line in bootstrap.splitlines():
        if "Batch_Control::register(" in line and not line.strip().startswith("//"):
            check(False, "the batch-control register() call site is live code in the bootstrap")
            break
    else:
        check(True, "")


def main():
    global FAILURES
    FAILURES = []
    args = sys.argv[1:]

    if "--structural-only" in args:
        repo = REPO_ROOT
        if "--repo" in args:
            repo = args[args.index("--repo") + 1]
        return structural_only(repo)

    print("Stage 13 commissioning-readiness verification")
    print(f"  repository: {REPO_ROOT}")

    section_registry()
    section_derived()
    section_secret_safety()
    section_no_production_write()
    section_authorization_separation()
    section_negative_proofs()
    section_invariants()
    section_preflight_run()

    print()
    for failure in FAILURES:
        print(f"FAIL: {failure}")
    print(f"negative proofs: {PROOF_PASSED} proven, {PROOF_MISSED} missed")
    print(f"{PASSED} passed, {len(FAILURES)} failed")

    payload = {
        "gate": GATE_ID,
        "status": "fail" if FAILURES or PROOF_MISSED else "pass",
        "passed": PASSED,
        "failed": len(FAILURES),
        "negative_proofs": PROOF_PASSED,
        "negative_proofs_missed": PROOF_MISSED,
        "violations": len(FAILURES) + PROOF_MISSED,
        "details": FAILURES,
    }
    print("GATE-RESULT " + json.dumps(payload))
    return 1 if FAILURES or PROOF_MISSED else 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    finally:
        shutil.rmtree(WORK, ignore_errors=True)
