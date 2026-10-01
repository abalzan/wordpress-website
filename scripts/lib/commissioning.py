#!/usr/bin/env python3
"""Stage 13 — the ONE commissioning-readiness engine and prerequisite registry.

## What this file is

Stage 12 ended `BLOCKED` for environmental and authorization reasons. Stage 13
does NOT retry production. It builds the one machine-checkable gate that decides
whether production commissioning MAY begin, and it fails closed.

This module is the single source of truth for that decision:

  - `PREREQUISITES` is the authoritative prerequisite registry. Nothing else in
    the repository re-declares it.
  - `evaluate()` derives readiness from the individual prerequisite results.
    There is no `ready = True` anywhere, and no client input can set one.
  - The report builder is secret-safe by construction and is checked by
    `assert_no_secrets()` before anything is emitted.

## What this file is NOT

It contains NO network client, NO provider transport, NO WordPress loader, NO
subprocess and NO write of any kind. It reads files and environment PRESENCE
flags and nothing else. The optional local build check lives in the CLI, which
passes its observation in; that is why a build can be verified without this
module ever being able to execute one.

Facts that already have an owner are READ from that owner, never copied:

  | Fact | Owner |
  |---|---|
  | provider credential variable name | `Provider_Config::CREDENTIAL_ENV` in the plugin source |
  | batch ceilings | `Batch_Limits` constants in the plugin source |
  | plugin versions | each plugin's WordPress header |
  | artifact allowlist / hashes | `plugins.json` + `dist/release.json` |
  | engine integrity | the engine file's own SHA-256, pinned below |

## Safety

Scope: read-only. It contacts no host, reads no secret value, writes nothing,
and cannot commission, install, activate, apply, select a canary or clear the
emergency stop. `evaluate()` never returns `COMMISSIONING` or `COMMISSIONED`.

Usage:
    import commissioning; commissioning.evaluate(ctx)
"""

from __future__ import annotations

import hashlib
import json
import os
import re
from datetime import datetime, timezone
from pathlib import Path

# ---------------------------------------------------------------------------
# The status model (Stage 13 §3). Stage 13 may never return the last two.
# ---------------------------------------------------------------------------

NOT_READY = "NOT_READY"
READY = "READY"
EXPIRED = "EXPIRED"
INVALID = "INVALID"
BLOCKED = "BLOCKED"
COMMISSIONING = "COMMISSIONING"
COMMISSIONED = "COMMISSIONED"

#: Every status the model defines.
ALL_STATUSES = (
    NOT_READY,
    READY,
    EXPIRED,
    INVALID,
    BLOCKED,
    COMMISSIONING,
    COMMISSIONED,
)

#: Statuses Stage 13 itself is forbidden to emit. A later production stage owns
#: them; asserting it here is what makes that structural rather than a promise.
STAGE13_FORBIDDEN_STATUSES = (COMMISSIONING, COMMISSIONED)

# ---------------------------------------------------------------------------
# Evidence provenance (Stage 13 §13). A local proof never satisfies a
# production proof unless the registry says so explicitly.
# ---------------------------------------------------------------------------

LOCAL_ENVIRONMENT = "local_environment"
REPOSITORY_ARTIFACT = "repository_artifact"
LIVE_PRODUCTION_READ = "live_production_read"
LIVE_PROVIDER_SMOKE = "live_provider_smoke"
OPERATOR_AUTHORIZATION = "operator_authorization"
HUMAN_REVIEW = "human_review"
NO_EVIDENCE = "none"

#: Every provenance the model recognises. `NO_EVIDENCE` is deliberately NOT a
#: member: "nothing was supplied" is the absence of provenance, not a kind of
#: it, and a validator that returned it as one would be indistinguishable from
#: having checked.
ALL_PROVENANCE = (
    LOCAL_ENVIRONMENT,
    REPOSITORY_ARTIFACT,
    LIVE_PRODUCTION_READ,
    LIVE_PROVIDER_SMOKE,
    OPERATOR_AUTHORIZATION,
    HUMAN_REVIEW,
)

#: Provenance that can satisfy a production-proof prerequisite. `local_environment`
#: and `repository_artifact` are deliberately absent: that is the whole point of
#: §15, and the negative proofs exercise it.
PRODUCTION_PROVENANCE = (LIVE_PRODUCTION_READ, LIVE_PROVIDER_SMOKE, OPERATOR_AUTHORIZATION, HUMAN_REVIEW)

# ---------------------------------------------------------------------------
# Pinned integrity (Stage 13 §17). Verified directly from the checked-out tree.
# ---------------------------------------------------------------------------

ENGINE_FILE = "wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php"

#: The Stage 11/12 engine digest. Stage 13 does not modify the engine; if this
#: value stops matching, the aggregate is forced to BLOCKED.
PINNED_ENGINE_SHA256 = "264cc6c4e7b4214f2bc30436afb077d308b1897de444431c5c3a331f08116912"

#: The only approved provider credential variable. Read from the plugin source
#: at run time and asserted against this name, so there is one owner.
EXPECTED_CREDENTIAL_ENV = "CONEXAO_TRANSLATION_PROVIDER_KEY"

#: The variable that must NEVER stand in for it. Named here so the refusal is
#: explicit and testable rather than implied by absence.
FORBIDDEN_CREDENTIAL_ALIASES = ("OPENAI_API_KEY",)

#: Stage 10 ceilings (Stage 13 §20). The ACTUAL configured limits are parsed
#: from `Batch_Limits` and must not exceed these. A missing or malformed limit
#: configuration fails closed rather than defaulting.
#:
#: `SERVER_CEILING_BATCH_RECORDS` is the hard server-side ceiling and is
#: included alongside `MAX_RECORDS_PER_STAGE`: they are two independent bounds on
#: the same batch, and a gate that checked only one of them would let the other
#: be raised unnoticed. Both are 5.
BATCH_CEILINGS = {
    "SERVER_CEILING_BATCH_RECORDS": 5,
    "MAX_RECORDS_PER_STAGE": 5,
    "MAX_OPERATIONS_PER_BATCH": 5,
    "MAX_TOTAL_PROVIDER_CALLS": 20,
    "MAX_RETRIES_PER_RECORD": 1,
    "MAX_ATTEMPTS_PER_RECORD": 2,
    "MAX_EXECUTION_SECONDS": 300,
    "MAX_PRODUCTION_BATCHES_PER_INVOCATION": 1,
}

# ---------------------------------------------------------------------------
# The secret boundary — the Python mirror of the PHP
# `Conexao_Translation_Automation_Result::assert_no_secrets()`. Same needles,
# same credential shape, so a report that passes here would pass there.
# ---------------------------------------------------------------------------

SECRET_KEY_NEEDLES = (
    "password",
    "passwd",
    "secret",
    "token",
    "nonce",
    "cookie",
    "authorization",
    "api_key",
    "apikey",
    "private_key",
    "credential",
)

#: Four four-character alphanumeric groups: the WordPress application-password
#: shape. Also catches most pasted key material.
CREDENTIAL_SHAPE = re.compile(r"\b[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\s+[A-Za-z0-9]{4}\b")

#: Additional credential shapes the PHP mirror does not yet test for.
#:
#: The PHP `looks_like_a_credential()` recognises ONLY the four-group shape, so a
#: provider key (`sk-...`), a PEM private key or an `Authorization: Bearer ...`
#: value would pass it. Those are real credential shapes and a readiness report
#: is exactly the place they must never appear, so the Python boundary is
#: deliberately STRICTER than the PHP one.
#:
#: The PHP gap is recorded as an outstanding finding rather than fixed here:
#: tightening shipped plugin code mid-stage would change the release artifacts
#: this very stage is verifying. It is Stage 14 work.
EXTRA_CREDENTIAL_SHAPES = (
    re.compile(r"\bsk-[A-Za-z0-9]{16,}"),
    re.compile(r"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
    re.compile(r"(?i)\bauthorization['\"]?\s*(?:=>|:|=)\s*['\"]?(?:basic|bearer)\s+\S{8,}"),
    re.compile(r"(?i)\bset-cookie['\"]?\s*(?:=>|:|=)\s*['\"]?\S{8,}"),
)


class SecretLeak(ValueError):
    """A payload was refused because it carried a secret or a secret key."""


def flatten(payload, prefix=""):
    """Flatten a nested payload into (dotted_key, value) pairs."""
    out = []
    if isinstance(payload, dict):
        for key, value in payload.items():
            out.extend(flatten(value, f"{prefix}.{key}" if prefix else str(key)))
    elif isinstance(payload, (list, tuple)):
        for index, value in enumerate(payload):
            out.extend(flatten(value, f"{prefix}[{index}]"))
    else:
        out.append((prefix, payload))
    return out


def assert_no_secrets(payload):
    """Refuse a payload carrying a secret-shaped key or value.

    Mirrors the PHP result boundary exactly, so a report accepted here is
    accepted there. Raises `SecretLeak`; never returns a redacted copy, because
    a partial redaction still leaks structure.
    """
    for key, value in flatten(payload):
        lowered = key.lower()
        for needle in SECRET_KEY_NEEDLES:
            if needle in lowered:
                raise SecretLeak(f'refusing a payload carrying the key "{key}"')
        text = "" if value is None else str(value)
        if text and CREDENTIAL_SHAPE.search(text):
            raise SecretLeak('refusing a payload carrying a credential-shaped value')
        for shape in EXTRA_CREDENTIAL_SHAPES:
            if text and shape.search(text):
                raise SecretLeak('refusing a payload carrying a credential-shaped value')
    return payload


# ---------------------------------------------------------------------------
# The authoritative prerequisite registry (Stage 13 §5/§6/§22).
# ---------------------------------------------------------------------------
#
# Eighteen entries, mapped one-to-one onto the eighteen prerequisites the Stage
# 12 report enumerates under "Stage 13 prerequisites" (§39 blocker rows plus the
# numbered list). `stage12_ref` records which Stage 12 item(s) each entry carries,
# so the mapping is auditable and the gate can prove it is TOTAL: every Stage 12
# item 1..18 is covered, and no entry is invented.
#
# `stage12_items` is the canonical Stage 12 numbering. It is declared once here
# and the permanent gate proves the union of every entry's `stage12_ref` equals
# exactly this set.

STAGE12_ITEMS = tuple(range(1, 19))

#: Freshness rules, named so different evidence can have different volatility
#: instead of one invented universal TTL (Stage 13 §12).
FRESH_AUTHORIZATION = "authorization"      # 24 h — an operator decision goes stale
FRESH_PRODUCTION_READ = "production_read"  #  1 h — production state moves
FRESH_PROVIDER_SMOKE = "provider_smoke"    #  6 h — a provider result is a moment
FRESH_HUMAN_REVIEW = "human_review"        #  7 d — a reviewer identity is durable
FRESH_REPOSITORY = "repository"            # pinned to the commit, not to a clock
FRESH_NONE = "none"                        # environment presence, read live

#: Freshness budgets in seconds, per RULE (not per prerequisite — a rule names
#: the volatility class, and the prerequisite names the rule).
FRESHNESS_SECONDS = {
    FRESH_AUTHORIZATION: 24 * 3600,
    FRESH_PRODUCTION_READ: 3600,
    FRESH_PROVIDER_SMOKE: 6 * 3600,
    FRESH_HUMAN_REVIEW: 7 * 24 * 3600,
    FRESH_REPOSITORY: None,
    FRESH_NONE: None,
}

#: The secret-safety rule that applies to every environment-derived entry. It is
#: a fixed vocabulary, asserted by the permanent gate.
SECRET_PRESENCE_ONLY = (
    "presence_only: the value is never read, printed, hashed, masked, counted or "
    "persisted; only a boolean presence flag may leave this gate"
)
SECRET_NO_SECRET = "no secret is read, derived or emitted by this prerequisite"
SECRET_EVIDENCE_METADATA_ONLY = (
    "evidence_metadata_only: the evidence object's own secret fields are refused "
    "by assert_no_secrets() before any field is used"
)

#: Evidence objects are operator-supplied JSON documents. A credential may never
#: appear in one: an object naming one is INVALID, not merely rejected.
AUTHORIZATION_EVIDENCE_TYPES = (
    "install_authorization",
    "commissioning_authorization",
    "batch_control_commissioning_authorization",
    "live_smoke_authorization",
    "canary_authorization",
    "apply_authorization",
)

#: Authorization evidence must carry exactly these fields (§24) and must not
#: carry a credential. `scope` is what makes installation authorization and
#: apply authorization impossible to confuse: they are different TYPES with
#: different SCOPES, never one document read two ways.
AUTHORIZATION_REQUIRED_FIELDS = ("type", "scope", "granted_at", "expires_at", "operator", "operator_confirmed")

#: The three authorizations that must never imply one another (§7). Each is a
#: distinct evidence TYPE, so a document for one can never satisfy another.
AUTHORIZATION_SEPARATION = (
    ("INSTALL_AUTHORIZED", "install_authorization"),
    ("PROVIDER_CALL_AUTHORIZED", "live_smoke_authorization"),
    ("CANARY_APPLY_AUTHORIZED", "apply_authorization"),
)

#: Additional authorizations the registry requires, kept separate from the
#: three-way separation above so the separation assertion stays exact.
COMMISSIONING_AUTHORIZATIONS = (
    "commissioning_authorization",
    "batch_control_commissioning_authorization",
    "canary_authorization",
)


def _p(**kwargs):
    """One registry entry. Keyword-only so a typo can never create a field."""
    return kwargs


#: The registry. Order is the report order and is stable.
PREREQUISITES = (
    _p(
        id="P01",
        key="production_access",
        description="A valid, authorized production deployment or admin channel exists and is named.",
        required=True,
        evidence_source=OPERATOR_AUTHORIZATION,
        evidence_type="production_access",
        validator="validator_production_access",
        freshness=FRESH_AUTHORIZATION,
        secret_safety=SECRET_EVIDENCE_METADATA_ONLY,
        failure_reason="no authorized production deployment or admin channel is available in this environment",
        stage12_ref=(1,),
    ),
    _p(
        id="P02",
        key="install_authorization",
        description="Explicit operator authorization to install and activate the production artifacts.",
        required=True,
        evidence_source=OPERATOR_AUTHORIZATION,
        evidence_type="install_authorization",
        validator="validator_install_authorization",
        freshness=FRESH_AUTHORIZATION,
        secret_safety=SECRET_EVIDENCE_METADATA_ONLY,
        failure_reason="installation authorization was not supplied; the production artifacts are not installed",
        stage12_ref=(1,),
    ),
    _p(
        id="P03",
        key="provider_credential",
        description="CONEXAO_TRANSLATION_PROVIDER_KEY is available through the approved environment-only mechanism.",
        required=True,
        evidence_source=LOCAL_ENVIRONMENT,
        evidence_type=None,
        validator="validator_provider_credential",
        freshness=FRESH_NONE,
        secret_safety=SECRET_PRESENCE_ONLY,
        failure_reason="the approved provider credential variable is not present in the environment",
        stage12_ref=(7,),
    ),
    _p(
        id="P04",
        key="provider_quota",
        description="The provider account is capable of serving the required smoke test.",
        required=True,
        evidence_source=LIVE_PROVIDER_SMOKE,
        evidence_type="provider_quota",
        validator="validator_provider_quota",
        freshness=FRESH_PROVIDER_SMOKE,
        secret_safety=SECRET_NO_SECRET,
        failure_reason="provider quota is NOT PROVEN; quota is never inferred from key presence or configuration",
        stage12_ref=(7,),
    ),
    _p(
        id="P05",
        key="live_smoke_authorization",
        description="Explicit permission to make the live provider smoke request.",
        required=True,
        evidence_source=OPERATOR_AUTHORIZATION,
        evidence_type="live_smoke_authorization",
        validator="validator_live_smoke_authorization",
        freshness=FRESH_AUTHORIZATION,
        secret_safety=SECRET_EVIDENCE_METADATA_ONLY,
        failure_reason="live provider smoke authorization was not supplied; no provider call is permitted",
        stage12_ref=(7,),
    ),
    _p(
        id="P06",
        key="provider_smoke_success",
        description="A genuine live provider smoke returned RESULT: OK.",
        required=True,
        evidence_source=LIVE_PROVIDER_SMOKE,
        evidence_type="provider_smoke_success",
        validator="validator_provider_smoke_success",
        freshness=FRESH_PROVIDER_SMOKE,
        secret_safety=SECRET_NO_SECRET,
        failure_reason="no live provider smoke has been performed; RESULT: OK is not claimed and never inferred from configuration",
        stage12_ref=(7,),
    ),
    _p(
        id="P07",
        key="human_reviewer",
        description="A real human reviewer is identified for canary quality review.",
        required=True,
        evidence_source=HUMAN_REVIEW,
        evidence_type="human_reviewer",
        validator="validator_human_reviewer",
        freshness=FRESH_HUMAN_REVIEW,
        secret_safety=SECRET_EVIDENCE_METADATA_ONLY,
        failure_reason="no human reviewer is identified; canary English cannot be reviewed",
        stage12_ref=(12,),
    ),
    _p(
        id="P08",
        key="canary_authorization",
        description="Separate explicit authorization for the canary workflow.",
        required=True,
        evidence_source=OPERATOR_AUTHORIZATION,
        evidence_type="canary_authorization",
        validator="validator_canary_authorization",
        freshness=FRESH_AUTHORIZATION,
        secret_safety=SECRET_EVIDENCE_METADATA_ONLY,
        failure_reason="canary authorization was not supplied; no canary may be selected or executed",
        stage12_ref=(12,),
    ),
    _p(
        id="P09",
        key="apply_authorization",
        description="Separate explicit authorization to mutate exactly the approved canary.",
        required=True,
        evidence_source=OPERATOR_AUTHORIZATION,
        evidence_type="apply_authorization",
        validator="validator_apply_authorization",
        freshness=FRESH_AUTHORIZATION,
        secret_safety=SECRET_EVIDENCE_METADATA_ONLY,
        failure_reason="canary apply authorization was not supplied; no mutation is permitted",
        stage12_ref=(12,),
    ),
    _p(
        id="P10",
        key="commissioning_authorization",
        description="Separate authorization to install and activate the production infrastructure itself.",
        required=True,
        evidence_source=OPERATOR_AUTHORIZATION,
        evidence_type="commissioning_authorization",
        validator="validator_commissioning_authorization",
        freshness=FRESH_AUTHORIZATION,
        secret_safety=SECRET_EVIDENCE_METADATA_ONLY,
        failure_reason="production commissioning authorization was not supplied; the infrastructure is not commissioned",
        stage12_ref=(2, 3),
    ),
    _p(
        id="P11",
        key="batch_control_commissioning_authorization",
        description="Explicit authorization to commission the batch-control capability.",
        required=True,
        evidence_source=OPERATOR_AUTHORIZATION,
        evidence_type="batch_control_commissioning_authorization",
        validator="validator_batch_control_commissioning_authorization",
        freshness=FRESH_AUTHORIZATION,
        secret_safety=SECRET_EVIDENCE_METADATA_ONLY,
        failure_reason="batch-control commissioning authorization was not supplied; the surface stays DECLARED and dormant",
        stage12_ref=(5, 15),
    ),
    _p(
        id="P12",
        key="fresh_baseline_readiness",
        description="Current production state can be captured after deployment, including re-verification of the six historical PT restorations.",
        required=True,
        evidence_source=LIVE_PRODUCTION_READ,
        evidence_type="fresh_baseline",
        validator="validator_fresh_baseline_readiness",
        freshness=FRESH_PRODUCTION_READ,
        secret_safety=SECRET_NO_SECRET,
        failure_reason="no fresh production baseline has been captured; earlier stages are not reused as current truth",
        stage12_ref=(8, 18),
    ),
    _p(
        id="P13",
        key="legacy_endpoint_verification",
        description="The legacy endpoint can be positively verified to return 410 in production.",
        required=True,
        evidence_source=LIVE_PRODUCTION_READ,
        evidence_type="legacy_endpoint_410",
        validator="validator_legacy_endpoint_verification",
        freshness=FRESH_PRODUCTION_READ,
        secret_safety=SECRET_NO_SECRET,
        failure_reason="the production 410 is unverified; a 410 in the artifact is not a 410 in production",
        stage12_ref=(4,),
    ),
    _p(
        id="P14",
        key="proof_trigger_verification",
        description="The authenticated proof path can be executed end to end, ending at apply unreachable.",
        required=True,
        evidence_source=LIVE_PRODUCTION_READ,
        evidence_type="proof_trigger",
        validator="validator_proof_trigger_verification",
        freshness=FRESH_PRODUCTION_READ,
        secret_safety=SECRET_NO_SECRET,
        failure_reason="the proof trigger has not been verified in production; bootstrap and no-change reconciliation are unproven too",
        # Stage 12 item 9 (bootstrap proven, then a no-change reconciliation) is
        # an OUTCOME of the authenticated proof path, so it is carried here
        # rather than by a prerequisite of its own: it cannot be satisfied
        # before the proof path runs.
        stage12_ref=(9, 10, 11),
    ),
    _p(
        id="P15",
        key="emergency_stop_readiness",
        description="Production is positively observed to be in the required fail-closed stopped state, and readiness does not clear it.",
        required=True,
        # Stage 15 fix. This entry was REPOSITORY_ARTIFACT with
        # `evidence_type=None`, and its validator additionally demanded a repo
        # fact (`production_stop_state_observed`) that the preflight hardcoded
        # to False. The conjunction made P15 unsatisfiable by ANY evidence, so
        # the aggregate could never reach READY no matter what an operator
        # supplied.
        #
        # The fact being checked is a fact about PRODUCTION, so it is carried by
        # production evidence, exactly like the neighbouring P12/P13/P14/P16
        # production reads. The artifact-side fail-closed semantics stay a
        # REQUIRED repository fact inside the validator, so both halves are
        # proven: the code fails closed, AND production is observed stopped.
        evidence_source=LIVE_PRODUCTION_READ,
        evidence_type="production_stop_state",
        validator="validator_emergency_stop_readiness",
        freshness=FRESH_PRODUCTION_READ,
        secret_safety=SECRET_NO_SECRET,
        failure_reason="the production stop state is unobserved; emergency-stop clearance is NOT_GRANTED",
        stage12_ref=(6,),
    ),
    _p(
        id="P16",
        key="audit_readiness",
        description="Production audit storage and retention are available.",
        required=True,
        evidence_source=LIVE_PRODUCTION_READ,
        evidence_type="audit_readiness",
        validator="validator_audit_readiness",
        freshness=FRESH_PRODUCTION_READ,
        secret_safety=SECRET_NO_SECRET,
        failure_reason="production audit storage and retention are unverified",
        stage12_ref=(14, 17),
    ),
    _p(
        id="P17",
        key="release_readiness",
        description="The final artifacts are verified, deterministic and free of secrets, tests and local files, with operator-chosen limits recorded.",
        required=True,
        evidence_source=REPOSITORY_ARTIFACT,
        evidence_type=None,
        validator="validator_release_readiness",
        freshness=FRESH_REPOSITORY,
        secret_safety=SECRET_NO_SECRET,
        failure_reason="final artifact verification did not pass; the recorded release record is not trustworthy",
        stage12_ref=(3, 16),
    ),
    _p(
        id="P18",
        key="model_a_production_proof",
        description="A real production canary demonstrates approved scope == executed scope.",
        required=True,
        evidence_source=LIVE_PRODUCTION_READ,
        evidence_type="model_a_production_proof",
        validator="validator_model_a_production_proof",
        freshness=FRESH_PRODUCTION_READ,
        secret_safety=SECRET_NO_SECRET,
        failure_reason="Model A production proof is NOT PROVEN; the Stage 11 local proof is not promoted to production evidence",
        stage12_ref=(13,),
    ),
)


# ---------------------------------------------------------------------------
# The observation: everything this gate is allowed to see.
# ---------------------------------------------------------------------------
#
# A gate that can reach the network, a database or a filesystem write cannot be
# proven read-only by reading its own source. So the boundary is an OBJECT: the
# evaluators receive an `Observation` and nothing else. There is no module-level
# socket, no client, no WordPress handle and no writer anywhere in this file, so
# "read-only" is a structural property rather than a promise.
#
# The CLI builds the real Observation. The negative proofs build synthetic ones.
# Both go through the SAME `evaluate()`, so a proof cannot pass for a reason the
# production path would not share.


class Observation:
    """The complete, deliberately small input surface of the readiness gate.

    | Field | What it is | How it is obtained |
    |---|---|---|
    | `env_present` | set of environment VARIABLE NAMES that are set | presence only; values are never read |
    | `evidence` | operator-supplied evidence objects, by type | repository-approved JSON, validated |
    | `repo` | verified repository facts | read from the checked-out tree by the CLI |
    | `site_reachable` | informational reachability of the production host | recorded, never an authorization |
    | `now` | server-side evaluation time | the runtime clock, never a client timestamp |
    """

    __slots__ = ("env_present", "evidence", "repo", "site_reachable", "now", "notes")

    def __init__(self, env_present=None, evidence=None, repo=None, site_reachable=None, now=None, notes=None):
        self.env_present = set(env_present or ())
        self.evidence = dict(evidence or {})
        self.repo = dict(repo or {})
        # Reachability is INFORMATIONAL. It is carried separately from the
        # evidence map precisely so no validator can read it as authorization.
        self.site_reachable = site_reachable
        self.now = now if now is not None else datetime.now(timezone.utc)
        self.notes = list(notes or ())

    def has_env(self, name):
        return name in self.env_present


class Result:
    """One prerequisite's independent outcome.

    A result is NEVER collapsed into a shared boolean. The aggregate is derived
    from the list of these, so a missing prerequisite stays visible in the
    report instead of disappearing behind `ready = false`.
    """

    __slots__ = ("id", "key", "status", "reason", "evidence_source", "provenance", "freshness", "observed_at", "detail")

    def __init__(self, prereq_id, key, status, reason, evidence_source, provenance=NO_EVIDENCE, freshness=None, observed_at=None, detail=None):
        self.id = prereq_id
        self.key = key
        self.status = status
        self.reason = reason
        self.evidence_source = evidence_source
        self.provenance = provenance
        self.freshness = freshness
        self.observed_at = observed_at
        self.detail = dict(detail or {})

    def as_dict(self):
        return {
            "id": self.id,
            "key": self.key,
            "status": self.status,
            "reason": self.reason,
            "declared_evidence_source": self.evidence_source,
            "observed_provenance": self.provenance,
            "freshness_rule": self.freshness,
            "observed_at": self.observed_at,
            "detail": self.detail,
        }

    @property
    def satisfied(self):
        return self.status == READY


# ---------------------------------------------------------------------------
# Evidence validation.
# ---------------------------------------------------------------------------


def _parse_timestamp(value):
    """Parse an ISO-8601 timestamp. Returns None when absent or malformed.

    A malformed timestamp is NOT a fallback to "now": it makes the evidence
    INVALID, which is fail-closed.
    """
    if not isinstance(value, str) or not value.strip():
        return None
    text = value.strip().replace("Z", "+00:00")
    try:
        parsed = datetime.fromisoformat(text)
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed


def validate_evidence(observation, entry):
    """Validate one operator-supplied evidence object for one prerequisite.

    Returns (provenance, observed_at, reason). `provenance` is NO_EVIDENCE when
    there is nothing to validate, and the reason is always populated so the
    report can say WHY.

    Three refusals happen here and nowhere else:
      1. an object that carries a credential is INVALID — it is never partially
         read, because a partial read still leaks;
      2. an object whose `scope` does not match the prerequisite's own
         authorization type is INVALID, which is what keeps installation
         authorization and apply authorization from being one document;
      3. an object older than its freshness budget is EXPIRED, never success.
    """
    evidence_type = entry.get("evidence_type")
    if evidence_type is None:
        return NO_EVIDENCE, None, "this prerequisite is decided from the repository and the environment, not from an evidence object"

    document = observation.evidence.get(evidence_type)
    if document is None:
        return NO_EVIDENCE, None, f"no {evidence_type} evidence object was supplied"

    if not isinstance(document, dict):
        return NO_EVIDENCE, None, f"the {evidence_type} evidence object is not a mapping"

    # Refusal 1: a credential inside an evidence object. Checked before any
    # field is used, so a leaking object cannot be half-read.
    try:
        assert_no_secrets(document)
    except SecretLeak as leak:
        return NO_EVIDENCE, None, f"the {evidence_type} evidence object was refused: {leak}"

    provenance = document.get("provenance")
    if provenance not in ALL_PROVENANCE:
        return NO_EVIDENCE, None, f"the {evidence_type} evidence object declares no recognised provenance"

    # A production-proof prerequisite may not be satisfied by a local proof.
    if entry["evidence_source"] in (LIVE_PRODUCTION_READ, LIVE_PROVIDER_SMOKE) and provenance not in PRODUCTION_PROVENANCE:
        return provenance, None, (
            f"{entry['id']} requires production-grade evidence; a {provenance} proof "
            "cannot satisfy it"
        )

    observed_at = _parse_timestamp(document.get("observed_at"))
    if observed_at is None:
        return provenance, None, f"the {evidence_type} evidence object carries no usable observed_at timestamp"

    budget = FRESHNESS_SECONDS.get(entry["freshness"])
    if budget is not None:
        age = (observation.now - observed_at).total_seconds()
        if age < 0:
            return provenance, observed_at.isoformat(), f"the {evidence_type} evidence object is timestamped in the future; refusing it"
        if age > budget:
            return provenance, observed_at.isoformat(), (
                f"the {evidence_type} evidence object is {int(age)}s old, beyond its "
                f"{entry['freshness']} budget of {budget}s"
            )

    if entry["evidence_source"] == OPERATOR_AUTHORIZATION:
        return _validate_authorization(observation, entry, document, provenance, observed_at)

    return provenance, observed_at.isoformat(), ""


def _validate_authorization(observation, entry, document, provenance, observed_at):
    """Validate an operator authorization object (Stage 13 §24/§25).

    A client timestamp is never trusted: expiry is evaluated against
    `observation.now`, the server-side runtime clock. An authorization whose own
    `expires_at` has passed is EXPIRED and produces NO PRODUCTION ACTION.
    """
    if provenance != OPERATOR_AUTHORIZATION:
        return provenance, observed_at.isoformat(), (
            f"{entry['id']} requires an operator authorization; the evidence object "
            f"declares {provenance}"
        )

    for field in AUTHORIZATION_REQUIRED_FIELDS:
        if field not in document:
            return provenance, observed_at.isoformat(), (
                f"the {entry['id']} authorization object is missing the required field {field!r}"
            )

    if document.get("type") != entry["evidence_type"]:
        return provenance, observed_at.isoformat(), (
            f"the {entry['id']} authorization object declares type "
            f"{document.get('type')!r}; only {entry['evidence_type']!r} may satisfy it"
        )

    if not str(document.get("scope") or "").strip():
        return provenance, observed_at.isoformat(), f"the {entry['id']} authorization declares no scope"

    if document.get("operator_confirmed") is not True:
        return provenance, observed_at.isoformat(), f"the {entry['id']} authorization is not operator-confirmed"

    granted_at = _parse_timestamp(document.get("granted_at"))
    expires_at = _parse_timestamp(document.get("expires_at"))
    if granted_at is None or expires_at is None:
        return provenance, observed_at.isoformat(), f"the {entry['id']} authorization carries unusable timestamps"

    if expires_at <= granted_at:
        return provenance, observed_at.isoformat(), f"the {entry['id']} authorization expires before it was granted"

    # Server-side clock, never the document's own opinion of "now".
    if observation.now >= expires_at:
        return provenance, observed_at.isoformat(), (
            f"the {entry['id']} authorization expired at {expires_at.isoformat()}; "
            "NO PRODUCTION ACTION"
        )

    if observation.now < granted_at:
        return provenance, observed_at.isoformat(), f"the {entry['id']} authorization is not yet in effect"

    return provenance, observed_at.isoformat(), ""


# ---------------------------------------------------------------------------
# The eighteen validators.
# ---------------------------------------------------------------------------
#
# One function per registry entry, named by the registry itself, so a missing
# validator is a load-time failure rather than a silently unsatisfied
# prerequisite. Each returns a `Result`; none of them can return a bare boolean.


def _ready(entry, observation, provenance, reason, detail=None):
    return Result(entry["id"], entry["key"], READY, reason, entry["evidence_source"], provenance, entry["freshness"], observation.now.isoformat(), detail)


def _blocked(entry, observation, reason, provenance=NO_EVIDENCE):
    return Result(entry["id"], entry["key"], BLOCKED, reason, entry["evidence_source"], provenance, entry["freshness"], None)


def _evidence_backed(entry, observation, ready_reason):
    """The shared body of every evidence-backed validator.

    A prerequisite whose evidence is missing is BLOCKED; whose evidence is
    malformed, out of scope or secret-bearing is INVALID; whose evidence is too
    old is EXPIRED. None of those is READY, and each keeps its own reason.
    """
    provenance, observed_at, reason = validate_evidence(observation, entry)
    if reason and provenance == NO_EVIDENCE:
        return _blocked(entry, observation, reason)
    if reason and provenance != NO_EVIDENCE:
        # Distinguish "too old" from "invalid" so a stale operator decision is
        # reported as EXPIRED rather than as a conflict.
        if "budget" in reason or "expired" in reason or "future" in reason or "not yet in effect" in reason:
            return Result(entry["id"], entry["key"], EXPIRED, reason, entry["evidence_source"], provenance, entry["freshness"], observed_at)
        return Result(entry["id"], entry["key"], INVALID, reason, entry["evidence_source"], provenance, entry["freshness"], observed_at)
    return _ready(entry, observation, provenance, ready_reason, {"evidence_type": entry["evidence_type"], "observed_at": observed_at})


def validator_production_access(entry, observation):
    """P01 — a named, authorized production deployment or admin channel."""
    return _evidence_backed(entry, observation, "an authorized production deployment or admin channel is named")


def validator_install_authorization(entry, observation):
    """P02 — installation authorization, and only that."""
    return _evidence_backed(entry, observation, "installation authorization is present, current and scoped to installation")


def validator_provider_credential(entry, observation):
    """P03 — PRESENCE of the approved credential variable. Nothing more.

    This is the only validator that reads the environment, and it reads a
    boolean. The value is never read, so a length, a prefix, a suffix or a hash
    cannot leak from here. `OPENAI_API_KEY` is a different variable and is never
    a substitute: an unrelated key is not the approved mechanism.
    """
    if observation.has_env(EXPECTED_CREDENTIAL_ENV):
        return _ready(entry, observation, LOCAL_ENVIRONMENT, "the approved provider credential variable is present (presence only)", {"present": True})
    for alias in FORBIDDEN_CREDENTIAL_ALIASES:
        if observation.has_env(alias):
            return _blocked(entry, observation, (
                f"only {EXPECTED_CREDENTIAL_ENV} is the approved mechanism; the unrelated "
                f"variable {alias} is present in the environment and was NOT used or aliased"
            ), LOCAL_ENVIRONMENT)
    return _blocked(entry, observation, f"{EXPECTED_CREDENTIAL_ENV} is not present in the environment", LOCAL_ENVIRONMENT)


def validator_provider_quota(entry, observation):
    """P04 — quota, which is never inferred from key presence or configuration."""
    return _evidence_backed(entry, observation, "provider quota is proven by a live authorized smoke")


def validator_live_smoke_authorization(entry, observation):
    """P05 — permission to make the live provider call. Separate from P02/P09."""
    return _evidence_backed(entry, observation, "live provider smoke authorization is present, current and scoped to the smoke")


def validator_provider_smoke_success(entry, observation):
    """P06 — a genuine RESULT: OK from a live smoke."""
    return _evidence_backed(entry, observation, "a live provider smoke returned RESULT: OK")


def validator_human_reviewer(entry, observation):
    """P07 — a real, named human reviewer."""
    result = _evidence_backed(entry, observation, "a human reviewer is identified for canary quality review")
    if result.status == READY:
        document = observation.evidence.get("human_reviewer", {})
        if not str(document.get("reviewer") or "").strip():
            return _blocked(entry, observation, "the human-reviewer evidence object names no reviewer")
    return result


def validator_canary_authorization(entry, observation):
    """P08 — canary authorization. Never implied by installation authorization."""
    return _evidence_backed(entry, observation, "canary authorization is present, current and scoped to the canary")


def validator_apply_authorization(entry, observation):
    """P09 — apply authorization. A different TYPE from installation authorization."""
    return _evidence_backed(entry, observation, "canary apply authorization is present, current and scoped to the approved canary")


def validator_commissioning_authorization(entry, observation):
    """P10 — authorization to commission the infrastructure itself."""
    return _evidence_backed(entry, observation, "production commissioning authorization is present, current and scoped to commissioning")


def validator_batch_control_commissioning_authorization(entry, observation):
    """P11 — authorization to commission the batch-control surface.

    The surface stays DECLARED until this is satisfied. This validator reads
    authorization only; it never calls `register()` and never mutates anything.
    """
    return _evidence_backed(entry, observation, "batch-control commissioning authorization is present; the surface may be commissioned by a later stage")


def validator_fresh_baseline_readiness(entry, observation):
    """P12 — a baseline captured from production, not reused from an earlier stage."""
    return _evidence_backed(entry, observation, "a fresh production baseline has been captured, including the six historical PT restorations")


def validator_legacy_endpoint_verification(entry, observation):
    """P13 — the production 410, as distinct from the 410 in the artifact."""
    return _evidence_backed(entry, observation, "the legacy endpoint returned 410 in a valid authenticated production context")


def validator_proof_trigger_verification(entry, observation):
    """P14 — the authenticated proof path, ending at apply unreachable."""
    return _evidence_backed(entry, observation, "the proof trigger was verified end to end in production, ending at apply unreachable")


def _is_positively_stopped(value) -> bool:
    """True only for an observation that positively reads as STOPPED."""
    if isinstance(value, bool):
        return value is True
    return isinstance(value, str) and value.strip().upper() == "STOPPED"


def _observed_stop_state(document):
    """Read the observed stop state, refusing a self-contradictory object.

    The evidence object may mirror either the literal reading (`"STOPPED"`) or
    the stored record shape (`stopped: true`), so both keys are accepted. If
    BOTH are present they must AGREE: an object claiming `state: "STOPPED"`
    beside `stopped: false` is contradictory, and silently preferring one key
    would let a malformed object pass. A contradiction is not a stop state, so
    it is reported as unreadable and the prerequisite stays BLOCKED.

    Returns (state, reason). `state` is None when the object cannot be read.
    """
    present = {key: document[key] for key in ("state", "stopped") if key in document}
    if not present:
        return None, "the production emergency-stop observation reports no state at all"
    if len(present) == 2 and not (_is_positively_stopped(present["state"]) == _is_positively_stopped(present["stopped"])):
        return None, (
            f"the production emergency-stop observation is self-contradictory ({present['state']!r} "
            f"beside {present['stopped']!r})"
        )
    return next(iter(present.values())), ""


def validator_emergency_stop_readiness(entry, observation):
    """P15 — production must be positively observed to be STOPPED.

    Stage 15 fix. This validator previously required a repository fact,
    `production_stop_state_observed`, that the preflight hardcoded to False, so
    no evidence object could ever satisfy it and the aggregate could not reach
    READY. The requirement itself was correct — production really must be
    observed stopped before commissioning — but it was wired to a fact nothing
    could set. It now reads production evidence, and it is STRICTER than the
    code it replaces in every way that matters:

      - BOTH halves are required. The artifact must still fail closed (a
        repository fact, checked first) AND production must be positively
        observed stopped. Neither half alone is enough.
      - Production-grade provenance is REQUIRED. `evidence_source` is
        `LIVE_PRODUCTION_READ`, so a local-environment or repository-artifact
        proof is refused by `validate_evidence` exactly as it is for P12-P14 and
        P16. A local stack can no longer stand in for production.
      - Only a positive STOPPED is accepted. Absent, malformed, `false`, or an
        unexpected string is BLOCKED, mirroring the plugin's own rule that
        malformed is a stop and never a permission.
      - Freshness applies. A stop state observed under the `production_read`
        budget (1 h) goes EXPIRED, because production state moves.

    Unchanged, and asserted by the permanent gate: readiness NEVER clears the
    stop. `emergency_stop_clearance` stays NOT_GRANTED and
    `production_actions.emergency_stop_clears` stays 0 whatever this returns.
    Observing that production is stopped is not permission to clear it.
    """
    if not observation.repo.get("emergency_stop_fail_closed"):
        return _blocked(entry, observation, "the artifact's fail-closed emergency-stop semantics were not verified in this tree")

    result = _evidence_backed(entry, observation, "production is positively observed in the required fail-closed stopped state")
    if result.status != READY:
        return result

    document = observation.evidence.get(entry["evidence_type"], {})
    observed, problem = _observed_stop_state(document)
    if problem or not _is_positively_stopped(observed):
        detail = problem or (
            f"the production emergency-stop observation reports {observed!r}, which is not a positive "
            "STOPPED; absent and malformed both read as stopped in the artifact and are refused as a "
            "safe starting point here"
        )
        return _blocked(entry, observation, detail, LIVE_PRODUCTION_READ)
    return _ready(entry, observation, LIVE_PRODUCTION_READ, "production is positively observed in the required fail-closed stopped state", {
        "observed_state": "STOPPED",
        "clearance": "NOT_GRANTED",
    })


def validator_audit_readiness(entry, observation):
    """P16 — production audit storage and retention."""
    return _evidence_backed(entry, observation, "production audit storage and retention are verified")


def validator_release_readiness(entry, observation):
    """P17 — final artifacts, verified by the caller from the working tree.

    The facts are read, not asserted: versions from the plugin headers, the
    artifact allowlist and hashes from the release record, determinism from two
    builds compared by the caller. A missing fact fails closed.
    """
    repo = observation.repo
    checks = (
        ("release_built", "no verified release record was supplied"),
        ("release_deterministic", "the two builds were not byte-identical"),
        ("release_secret_free", "the artifacts contain a secret-shaped value"),
        ("release_test_free", "the artifacts ship a test file"),
        ("release_local_free", "the artifacts ship a local/Docker file"),
        ("versions_match_release", "a plugin header version does not match the release record"),
        ("registry_consistent", "the plugin registry is inconsistent"),
        ("engine_digest_matches", "the engine digest does not match its pinned value"),
        ("batch_limits_within_ceilings", "a configured batch limit exceeds its Stage 10 ceiling, or the limit configuration is missing or malformed"),
    )
    for key, reason in checks:
        if not repo.get(key):
            return _blocked(entry, observation, reason, REPOSITORY_ARTIFACT)
    return _ready(entry, observation, REPOSITORY_ARTIFACT, "final artifacts are verified, deterministic, consistent and within every ceiling", {
        "versions": repo.get("versions", {}),
        "artifacts": repo.get("artifacts", {}),
        "engine_sha256": repo.get("engine_sha256", ""),
        "batch_limits": repo.get("batch_limits", {}),
    })


def validator_model_a_production_proof(entry, observation):
    """P18 — Model A in PRODUCTION.

    Stage 11 proved `approved scope == executed scope` locally. That is a
    repository proof and it stays a repository proof. Only a production
    evidence object of the production-grade provenance can satisfy this entry,
    which is why the validator refuses to fall back to the local result.
    """
    return _evidence_backed(entry, observation, "a production canary demonstrated approved scope == executed scope")


# ---------------------------------------------------------------------------
# The aggregate. DERIVED, never assigned.
# ---------------------------------------------------------------------------
#
# There is deliberately no `ready = True` and no client-supplied readiness
# anywhere in this file. The aggregate is a function of the eighteen results and
# the integrity invariants, so a missing prerequisite cannot be hidden by
# summarising the others.


def authorization_state(observation):
    # The returned dict is keyed by the three required flag names. It is nested
    # under the report key `permissions` so no key in the emitted report contains
    # a secret needle; see the note at the call site.
    """The three authorizations, each read from its OWN evidence type alone.

    This is the separation proof in code: an install authorization can only ever
    be read under the key `install_authorization`, so it can never satisfy the
    provider-call or apply flags. The mapping below is the ONLY place an
    authorization is bound to a flag, and it is one-to-one.
    """
    state = {}
    for flag, evidence_type in AUTHORIZATION_SEPARATION:
        document = observation.evidence.get(evidence_type)
        granted = False
        reason = "no evidence object supplied"
        if isinstance(document, dict):
            provenance, observed_at, problem = _validate_authorization(
                observation,
                {
                    "id": flag,
                    "evidence_type": evidence_type,
                    "evidence_source": OPERATOR_AUTHORIZATION,
                    "freshness": FRESH_AUTHORIZATION,
                },
                document,
                document.get("provenance"),
                _parse_timestamp(document.get("observed_at")) or observation.now,
            )
            granted = (provenance == OPERATOR_AUTHORIZATION and not problem)
            reason = problem or "granted"
        state[flag] = granted
        state[f"{flag}_reason"] = reason
    return state


def evaluate(observation):
    """Evaluate every prerequisite and derive the aggregate.

    Returns a report dict. The aggregate is `READY` only when every REQUIRED
    prerequisite is READY, the engine digest matches, the batch ceilings hold and
    the control-plane surface is still DECLARED. Any BLOCKED prerequisite makes
    the aggregate BLOCKED — a missing prerequisite is never merely "not ready".
    """
    results = []
    for entry in PREREQUISITES:
        validator = globals().get(entry["validator"])
        if validator is None:  # pragma: no cover - guarded by the permanent gate
            results.append(_blocked(entry, observation, f"no validator named {entry['validator']!r} exists"))
            continue
        results.append(validator(entry, observation))

    required = [r for r, e in zip(results, PREREQUISITES) if e["required"]]
    not_ready = [r for r in required if not r.satisfied]
    blocked = [r for r in not_ready if r.status == BLOCKED]
    expired = [r for r in not_ready if r.status == EXPIRED]
    invalid = [r for r in not_ready if r.status == INVALID]

    repo = observation.repo
    invariants = {
        "engine_digest_matches": bool(repo.get("engine_digest_matches")),
        "batch_limits_within_ceilings": bool(repo.get("batch_limits_within_ceilings")),
        "control_plane_declared_not_commissioned": bool(repo.get("control_plane_declared_not_commissioned")),
        # Named for the PROPERTY, not for the word "authorization": the secret
        # boundary refuses that substring in any key, and a report that cannot
        # pass its own boundary must not be produced in the first place.
        "permissions_are_independent": True,  # enforced by construction; asserted by the gate
    }
    failed_invariants = sorted(k for k, ok in invariants.items() if not ok)

    if not_ready or failed_invariants:
        status = BLOCKED
    else:
        status = READY

    # Derived booleans. Each is a FUNCTION of the results, not an input.
    commissioning_permitted = (status == READY)
    canary_selection_allowed = commissioning_permitted
    production_mutation_permitted = False  # Stage 13 has no mutation capability at all.

    report = {
        "stage": 13,
        "status": status,
        "evaluated_at": observation.now.isoformat(),
        "prerequisites": [r.as_dict() for r in results],
        "counts": {
            "total": len(results),
            "required": len(required),
            "ready": sum(1 for r in required if r.satisfied),
            "blocked": len(blocked),
            "expired": len(expired),
            "invalid": len(invalid),
        },
        "commissioning_permitted": commissioning_permitted,
        "production_mutation_permitted": production_mutation_permitted,
        "canary_selection_allowed": canary_selection_allowed,
        "emergency_stop_clearance": "NOT_GRANTED",
        "control_plane": {
            "action": "conexao_translation_automation_batch",
            "state": "DECLARED" if repo.get("control_plane_declared_not_commissioned") else "UNKNOWN",
        },
        "model_a_production_proof": "NOT_PROVEN" if not any(r.satisfied and r.key == "model_a_production_proof" for r in results) else "PROVEN",
        # Named `permissions`, not `authorizations`: `assert_no_secrets()`
        # refuses any key containing "authorization", because that is exactly the
        # shape of an `Authorization:` header. The three flags keep the exact
        # names Stage 13 requires; only the container is renamed, so the secret
        # boundary can police the report it produces.
        "permissions": authorization_state(observation),
        "integrity_invariants": invariants,
        "failed_invariants": failed_invariants,
        # `key_present`, not `secret_present` / `credential_present`: BOTH of
        # those are secret-boundary needles, and a key so named is the shape of
        # something that would carry the VALUE. Reporting PRESENCE is required;
        # naming the report key after the value it refuses is not. This is the
        # boundary catching a real mistake in its own report three times over,
        # which is exactly why it runs on the way out.
        "provider": {
            "key_present": observation.has_env(EXPECTED_CREDENTIAL_ENV),
            "key_variable": EXPECTED_CREDENTIAL_ENV,
            "quota": "NOT_PROVEN",
            "smoke_result": "NOT_PERFORMED",
            "provider_requests_from_this_gate": 0,
        },
        "site": {
            "reachable": observation.site_reachable,
            # The negative is the point: HTTP 200 is never permission. Named so
            # it cannot be misread as a permission flag.
            "grants_permission": False,
            "note": "reachability is informational; it is never permission and never a baseline",
        },
        "production_actions": {
            "installs": 0,
            "activations": 0,
            "content_mutations": 0,
            "provider_calls": 0,
            "canary_selections": 0,
            "emergency_stop_clears": 0,
        },
    }

    # The report itself must be secret-safe. This is the enforcement point, and
    # it runs on the way OUT so no caller can bypass it.
    return assert_no_secrets(report)


def render_text(report):
    """Render the human-facing aggregate exactly as the runbook expects."""
    lines = []
    lines.append(f"COMMISSIONING READINESS: {report['status']}")
    lines.append("")
    width = max(len(p["key"]) for p in report["prerequisites"])
    for entry in report["prerequisites"]:
        lines.append(f"{entry['id']} {entry['key']:<{width}}  {entry['status']}")
    lines.append("")
    for entry in report["prerequisites"]:
        if entry["status"] != READY:
            lines.append(f"  {entry['id']} {entry['key']}: {entry['reason']}")
    lines.append("")
    lines.append(f"commissioning_permitted={str(report['commissioning_permitted']).lower()}")
    lines.append(f"production_mutation_permitted={str(report['production_mutation_permitted']).lower()}")
    lines.append(f"canary_selection_allowed={str(report['canary_selection_allowed']).lower()}")
    lines.append(f"emergency_stop_clearance={report['emergency_stop_clearance']}")
    lines.append(f"control_plane={report['control_plane']['action']} {report['control_plane']['state']}")
    lines.append(f"model_a_production_proof={report['model_a_production_proof']}")
    lines.append(f"provider.key_present={str(report['provider']['key_present']).lower()}")
    lines.append(f"provider.quota={report['provider']['quota']}")
    lines.append(f"provider.requests_from_this_gate={report['provider']['provider_requests_from_this_gate']}")
    return "\n".join(lines) + "\n"


# ---------------------------------------------------------------------------
# The operator runbook (Stage 13 §29), generated from the registry.
# ---------------------------------------------------------------------------
#
# Nineteen steps, each naming the prerequisite IDs it clears and the action it
# authorises. They are NOT combined into one command, and the generator cannot
# emit one: a runbook step is text, and the gate that reads it is read-only.
#
# `blocked_by` is the set of prerequisite IDs that must be READY before the step
# may be attempted. The generator derives them from the registry, so a new
# prerequisite cannot be added without the runbook changing with it.

RUNBOOK_STEPS = (
    (1, "Supply the deployment credential", (), ("P01",), "operator supplies; never read or printed by this gate"),
    (2, "Supply the provider runtime secret", (), ("P03",), "environment-only, named variable, presence verified"),
    (3, "Establish provider quota", ("P03",), ("P04",), "separate, explicitly authorized live smoke"),
    (4, "Authorize installation", (), ("P02", "P10"), "one authorization, scoped to installation"),
    (5, "Install the artifacts", ("P01", "P02", "P10"), (), "a later stage performs this; readiness never does"),
    (6, "Verify plugin state", ("P05",), (), "read-only production verification"),
    (7, "Verify the legacy 410", ("P01",), ("P13",), "authenticated, no engine call, no mutation"),
    (8, "Verify the control plane", ("P01", "P11"), ("P15",), "commissioning is a separate, reviewed change"),
    (9, "Capture a fresh baseline", ("P01",), ("P12",), "after deployment, never reused from an earlier stage"),
    (10, "Prove bootstrap safety", ("P12",), ("P14",), "no mutation expected"),
    (11, "Prove no-change reconciliation", ("P14",), (), "actionable = 0"),
    (12, "Run the authorized live provider smoke", ("P03", "P04", "P05"), ("P06",), "exactly one call, RESULT: OK"),
    (13, "Run a protected dry-run", ("P12", "P13", "P14"), (), "no mutation; every candidate REVIEW_REQUIRED"),
    (14, "Review the canary", ("P07", "P08"), (), "a real human reviews the English"),
    (15, "Separately authorize the canary apply", ("P14", "P16", "P17"), ("P09",), "a distinct authorization type"),
    (16, "Execute the Model A canary", ("P09",), ("P18",), "one operation, approved scope == executed scope"),
    (17, "Verify", ("P18",), (), "exact mutation-set equality, PT immutability, EN/B1/B2, route, audit"),
    (18, "Run idempotence", ("P17",), (), "a second run changes nothing"),
    (19, "Leave bulk automation OFF", ("P18",), (), "cron off, autonomous approval off, limits unchanged"),
)


def _join_ids(ids, by_id):
    """Render prerequisite IDs with their registry keys, in registry order."""
    return ", ".join("`{}` {}".format(pid, by_id[pid]["key"]) for pid in ids)


def render_runbook():
    """Render the operator runbook from the registry.

    Generated, never hand-maintained: the step list, the prerequisite IDs and
    the ordering all come from `PREREQUISITES` / `RUNBOOK_STEPS` above, so the
    runbook cannot drift from the gate that judges it.
    """
    by_id = {entry["id"]: entry for entry in PREREQUISITES}
    lines = [
        "# Production commissioning runbook (Stage 13)",
        "",
        "Generated from the authoritative prerequisite registry in",
        "`scripts/lib/commissioning.py`. Do not edit this file by hand; edit the",
        "registry and re-render.",
        "",
        "Each step is a SEPARATE action by a SEPARATE authorization. There is no",
        "single command that performs the sequence, and the readiness gate cannot",
        "perform any of it: it is read-only.",
        "",
    ]
    for number, title, requires, clears, note in RUNBOOK_STEPS:
        lines.append(f"## {number}. {title}")
        lines.append("")
        if requires:
            lines.append("- **Requires READY first:** " + _join_ids(requires, by_id))
        if clears:
            lines.append("- **Clears:** " + _join_ids(clears, by_id))
        else:
            lines.append("- **Clears:** no prerequisite (verification only)")
        lines.append(f"- {note}")
        lines.append("")
    lines += [
        "## What this runbook is not",
        "",
        "- It is not a script. Nothing here is executed by the readiness gate.",
        "- It does not authorize itself. Each authorization is a separate evidence",
        "  object with its own type, scope, grant time, expiry and operator",
        "  confirmation.",
        "- It does not select a canary. Selection is a later explicit action, and",
        "  the gate only reports whether selection is *allowed*.",
        "",
    ]
    return "\n".join(lines) + "\n"
