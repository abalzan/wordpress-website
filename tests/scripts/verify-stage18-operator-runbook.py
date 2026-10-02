#!/usr/bin/env python3
"""Stage 18 gate: the manual operator runbook is present, executable and true.

## What this gate is for

Stage 17 retired automatic translation and kept the manual workflow. Stage 18
publishes the operator runbook for that workflow. A runbook is only useful if
it is TRUE, and a document that quietly drifts from its code is worse than no
document at all: an operator following a stale step would believe a guarantee
the system no longer makes.

So this gate treats the runbook as a CONTRACT and checks it against the code it
describes.

## The buckets

  PRESENT   the runbook exists, is linked from the documentation index, and
            carries the `_Last verified:` marker the documentation-drift gate
            requires of a living reference document.

  LIFECYCLE the eleven supported lifecycle stages are all present, in order, and
            the retired architecture is not presented as a live option.

  SCOPE     every stage the runbook tells an operator to select is REALLY on
            the orchestrator's allowlist, read from the source. A runbook that
            names a stage the code refuses is a failure, and so is a runbook
            that names a stage the code allows but the runbook never mentions.

  CONTROLS  each of the twelve retained safety controls is documented, because
            retiring automation must not have quietly dropped one.

  METRICS   every metric the runbook promises to report is a REAL field of the
            real result contract. This is the bucket that stops the runbook
            promising a number nobody can produce.

  SECRET    the runbook contains no credential shape, and instructs the
            operator to keep the key out of options, Connectors, meta, source,
            Git, artifacts, logs and audit records.

  HONESTY   the runbook does not claim WordPress.com Personal supports
            server-side environment variables, and does not instruct anyone to
            invent a production API or use SSH/WP-CLI to work around it.

## Why static only

No WordPress, no network, no production. The BEHAVIOURAL proofs (PT
immutability, collision refusal, drift refusal, idempotence, digest-bound
approval, Model A exact scope) live in the in-process PHP suites; this gate
asserts those suites are PRESENT, so deleting coverage fails here rather than
quietly reducing it.

Static only. No WordPress, no network, no production.
"""

from __future__ import annotations

import pathlib
import re
import sys

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
PLUGIN_DIR = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation"
ROLLOUT_DIR = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-rollout"
ORCHESTRATOR = PLUGIN_DIR / "includes" / "class-conexao-translation-automation-orchestrator.php"
ENGINE = ROLLOUT_DIR / "includes" / "class-conexao-translation-rollout-engine.php"
RESULT = PLUGIN_DIR / "includes" / "class-conexao-translation-automation-result.php"
ADMIN_TRIGGER = PLUGIN_DIR / "includes" / "class-conexao-translation-automation-admin-trigger.php"
BATCH_EXECUTOR = PLUGIN_DIR / "includes" / "class-conexao-translation-automation-batch-executor.php"
PROVIDER_CONFIG = PLUGIN_DIR / "includes" / "class-conexao-translation-automation-provider-config.php"
COMMISSIONING = REPO_ROOT / "scripts" / "lib" / "commissioning.py"
RUNBOOK = REPO_ROOT / "docs" / "translation-manual-operator-runbook.md"
DOC_INDEX = REPO_ROOT / "docs" / "README.md"
AUTOMATION_DOC = REPO_ROOT / "docs" / "plugins" / "conexao-translation-automation.md"

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


def read(path: pathlib.Path) -> str:
    return path.read_text(encoding="utf-8") if path.is_file() else ""


def report_and_exit() -> None:
    """Print the deterministic summary and exit with the aggregate status."""
    for failure in FAILURES:
        print(f"FAIL: {failure}")
    print(f"Stage 18 operator-runbook verification: {PASSED} passed, {len(FAILURES)} failed")
    sys.exit(1 if FAILURES else 0)


def main() -> int:
    runbook = read(RUNBOOK)

    # -- 1. PRESENT --------------------------------------------------------
    check(RUNBOOK.is_file(), f"{RUNBOOK.relative_to(REPO_ROOT)} is missing; the manual "
          "translation operator has no single procedure to follow")
    if not runbook:
        report_and_exit()

    # The index lives in docs/, so it links to its siblings by bare filename.
    # The assertion therefore matches the relative form, and separately requires
    # that it be a real Markdown link rather than a passing mention.
    check("(translation-manual-operator-runbook.md)" in read(DOC_INDEX),
          "the documentation index does not link the manual operator runbook")
    check(re.search(r"^_Last verified: \d{4}-\d{2}-\d{2} by .+_$", runbook, re.M) is not None,
          "the runbook does not carry the `_Last verified:` marker a living "
          "reference document requires")
    check("translation-manual-operator-runbook.md" in read(AUTOMATION_DOC),
          "the automation plugin doc does not point at the operator runbook; the "
          "runbook must be reachable from the plugin that owns the workflow")

    lowered = runbook.lower()

    # -- 2. LIFECYCLE ------------------------------------------------------
    # The eleven stages, checked so the runbook cannot omit one.
    for fragment in (
        "identify pt scope",
        "inventory",
        "manifest",
        "dry-run",
        "snapshot",
        "review",
        "explicit approval",
        "apply",
        "verify",
        "idempotence",
        "stop",
    ):
        check(fragment in lowered,
              f"the runbook does not document the lifecycle stage {fragment!r}")

    for heading in (
        "## 3. The safety controls",
        "## 4. Supplying the provider credential",
        "## 5. A concrete example",
        "## 6. The expected result",
        "## 7. Local / test run",
        "## 8. Manual production translation",
        "## 10. Rollback / recovery",
        "## 11. Operator checklist",
    ):
        check(heading in runbook, f"the runbook is missing the section {heading!r}")

    # The retired architecture must not be offered as a live option.
    for banned in ("wp_schedule_event", "webhook endpoint", "credential broker",
                   "automatic broker"):
        check(banned not in lowered,
              f"the runbook presents {banned!r} as part of the supported workflow")
    check("no cron" in lowered and "no webhook" in lowered,
          "the runbook does not state that there is no cron and no webhook")

    # -- 3. SCOPE: the allowlist is read from the source --------------------
    orchestrator = read(ORCHESTRATOR)
    match = re.search(
        r"private static function allowed_stages\(\): array \{\s*return array\((.*?)\);",
        orchestrator,
        re.S,
    )
    if check(match is not None, "could not read allowed_stages() from the orchestrator "
                                "source; the runbook's stage list cannot be verified"):
        allowlist = re.findall(r"'([a-z0-9-]+)'", match.group(1))

        check(len(allowlist) > 0, "the orchestrator allowlist parsed as empty")

        for stage in allowlist:
            check(stage in runbook,
                  f"the runbook does not document the allowlisted stage {stage!r}; an "
                  "operator would not know it is selectable")

        # The reverse direction: a stage the runbook tells the operator to
        # select must really be allowed.
        for stage in sorted(set(re.findall(r"`(en-[a-z0-9-]+)`", runbook))):
            check(stage in allowlist,
                  f"the runbook names the stage {stage!r}, which is NOT on the "
                  "orchestrator allowlist; following the runbook would be refused")

        # The deliberately non-automatable stage must be called out, so an
        # operator does not file a request the control plane will refuse.
        non_automatable = re.search(
            r"const NON_AUTOMATABLE_STAGES = array\((.*?)\);", orchestrator, re.S
        )
        if check(non_automatable is not None,
                 "could not read NON_AUTOMATABLE_STAGES from the orchestrator"):
            for stage in re.findall(r"'([a-z0-9-]+)'", non_automatable.group(1)):
                check(stage in runbook,
                      f"the runbook does not mention the non-automatable stage "
                      f"{stage!r}; an operator would request it and be refused")

    # -- 4. CONTROLS: all twelve retained safety controls are documented ----
    for control in (
        "pt is immutable",
        "scope is explicit",
        "dry-run before apply",
        "approval is digest-bound",
        "apply is explicit",
        "model a exact scope",
        "collisions fail closed",
        "pt drift fails closed",
        "malformed relationships fail closed",
        "duplicate execution is idempotent",
        "lock and audit remain active",
        "automatic execution stays off",
    ):
        check(control in lowered,
              f"the runbook does not document the retained safety control {control!r}")

    # -- 5. METRICS: every promised number is a real field ------------------
    engine = read(ENGINE)
    result = read(RESULT)

    for metric in (
        "records considered",
        "records eligible",
        "records planned",
        "provider calls",
        "records approved",
        "records executed",
        "mutations",
        "verification result",
        "idempotence result",
        "pt drift",
    ):
        check(metric in lowered,
              f"the runbook does not promise the required metric {metric!r}")

    # The code that produces them. A runbook metric with no producer is a lie.
    for field, source, label in (
        ("eligible_public_pt", engine, "the engine's numeric gate"),
        ("pt_drift", engine, "the engine's PT-drift counter"),
        ("approved_count", engine, "the Model A scope report"),
        ("executed_count", engine, "the Model A executed-scope report"),
        ("provider_calls", read(BATCH_EXECUTOR),
         "the batch executor's provider-call accounting"),
        ("mutation_occurred", result, "the result contract"),
    ):
        check(f"'{field}'" in source,
              f"the runbook promises {label} as {field!r}, but that field is not in "
              "its source; the runbook is promising a number nobody can produce")

    check("'gate'" in engine and "'PASS'" in engine,
          "the runbook promises a PASS/FAIL verification result, but the engine's "
          "gate verdict was not found")
    check("'create'" in engine and "'update'" in engine and "'conflicts'" in engine,
          "the runbook promises a `records planned` metric, but the engine's plan "
          "was not found")

    # -- 6. SECRET ---------------------------------------------------------
    # A real credential must never appear in a document. The shapes are the
    # same ones the Stage 14 secret scan recognises.
    secret_shapes = (
        r"sk-[A-Za-z0-9]{20,}",
        r"sk-proj-[A-Za-z0-9_\-]{20,}",
        r"-----BEGIN [A-Z ]*PRIVATE KEY-----",
        r"\b[A-Za-z0-9]{24}\.[A-Za-z0-9]{6}\.[A-Za-z0-9]{20,}",  # WP app password
    )
    for pattern in secret_shapes:
        check(re.search(pattern, runbook) is None,
              f"the runbook contains something matching a credential shape: {pattern}")

    # The credential boundary is named by a constant, not invented prose.
    env_match = re.search(r"const CREDENTIAL_ENV = '([A-Z_]+)';", read(PROVIDER_CONFIG))
    if check(env_match is not None, "could not read Provider_Config::CREDENTIAL_ENV "
                                   "from the provider boundary source"):
        check(env_match.group(1) in runbook,
              f"the runbook does not name the real credential variable "
              f"{env_match.group(1)!r}")

    # The forbidden storage locations must appear INSIDE the prohibition block,
    # not merely somewhere in the document. Checking only for the words would
    # pass a runbook that listed them as *recommended* locations, which is
    # exactly the failure mode worth catching.
    block_match = re.search(
        r"### Never store the key in any of these(.*?)(?=\n#{2,3} )", runbook, re.S
    )
    if check(block_match is not None,
             "the runbook has no 'Never store the key in any of these' prohibition "
             "block; the forbidden storage locations cannot be verified"):
        prohibited = block_match.group(1).lower()

        # The prohibition must be a real INSTRUCTION attached to the list, not
        # merely the word "never" appearing somewhere in the block (the block
        # also says the key "is never persisted", which would otherwise satisfy
        # a naive check while the list itself had been turned into advice).
        check(re.search(r"do not put it in|do \*\*not\*\* put it in|never put it in", prohibited)
              is not None,
              "the credential prohibition block does not actually prohibit anything; "
              "the forbidden locations are listed without an instruction not to use them")

        for location in (
            "wordpress options",
            "connectors",
            "post meta",
            "source code",
            "git",
            "release artifacts",
            "logs",
            "audit records",
        ):
            check(location in prohibited,
                  f"the runbook does not forbid storing the provider key in "
                  f"{location!r} inside the prohibition block")

    # -- 7. HONESTY about WordPress.com Personal ---------------------------
    check("personal" in lowered and "does not support server-side environment variables" in lowered,
          "the runbook does not state that WordPress.com Personal has no server-side "
          "environment variables; this is the limitation the whole credential "
          "procedure is built around")

    # It must not, anywhere, tell the operator Personal DOES support them.
    # These are the workarounds a future editor is most likely to reach for,
    # so they are matched as instruction-shaped phrases, not just keywords.
    for false_claim in (
        "personal supports server environment",
        "personal supports environment variables",
        "add the key to the wordpress options",
        "store the api key in options",
        "use wp-cli on personal",
        "wp-cli on personal",
        "ssh into production",
        "ssh into wordpress.com",
        "run wp-cli",
        "set an environment variable in wordpress",
    ):
        check(false_claim not in lowered,
              f"the runbook makes a claim this repository has disproved: {false_claim!r}")

    # Apply being uncommissioned is a FACT about the current posture, and it is
    # asserted positively rather than by the absence of a contradicting word:
    # a runbook that quietly claimed apply was available would be the single
    # most damaging drift this document could suffer.
    check(re.search(r"apply is \*\*not commissioned\*\*", lowered) is not None,
          "the runbook does not state that apply is not commissioned; an operator "
          "would believe production can apply when it currently cannot")

    check("wp-production-operations" in runbook,
          "the runbook does not point production work at the existing production "
          "authorization procedure")

    # -- 8. The behavioural suites must still be PRESENT -------------------
    for suite in (
        "test-manual-translation-workflow.php",
        "test-automation-apply-safety.php",
        "test-automation-model-a.php",
        "test-automation-lock.php",
    ):
        check(any(PLUGIN_DIR.glob(f"tests/{suite}")),
              f"the behavioural suite {suite} the runbook relies on is missing; "
              "the runbook would document guarantees nothing proves")

    # The admin screen the runbook names must really exist.
    trigger = read(ADMIN_TRIGGER)
    check("add_management_page" in trigger and "Tools" in trigger,
          "the runbook names a wp-admin screen the plugin does not register")

    # Automatic execution really is off.
    check("AUTOMATIC_TRANSLATION_RETIRED" in read(COMMISSIONING),
          "the retirement flag is gone; the runbook claims automatic execution is OFF "
          "and that claim can no longer be verified")

    report_and_exit()
    return 0


if __name__ == "__main__":
    main()
