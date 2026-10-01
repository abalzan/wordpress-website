#!/usr/bin/env python3
"""Stage 17 gate: permanent AUTOMATIC translation is retired; the MANUAL
workflow is retained.

## The decision this gate makes permanent

Stage 17 retired permanent automatic PT->EN translation. The site needs English
translation only occasionally, so the operational cost of a credential broker, a
delegated provider credential, production automation, commissioning controls and
permanent provider infrastructure was disproportionate to the expected usage.

This gate is the machine-checkable statement of that decision. It proves, over
the checked-out tree, that the retired architecture CANNOT silently return.

## The buckets

  RETIRED     must be ABSENT. A file that comes back, a hook that gets
              registered again, a broker token field that reappears, or a
              readiness status that becomes reachable again is a FAILURE.
              Absence is asserted POSITIVELY (the file is gone, the token is not
              in the source), because a gate that only greps for problems passes
              just as happily when pointed at the wrong directory.

  MANUAL-ONLY the human-triggered workflow must remain AVAILABLE and must still
              reach the shared engine. Retiring automation must not have taken
              the manual path with it, so its existence is asserted positively.

  SAFEGUARDS  the manual path's controls must not have been weakened to achieve
              the retirement: capability, nonce, POST-only, dry-run first,
              digest-bound approval, lock, audit, batch ceilings.

  CREDENTIALS the provider key must not exist in source, artifacts, docs or
              WordPress options, and no broker credential may persist.

  ENGINE      the shared engine must be byte-identical, and Stage 11 Model A
              exact-scope behaviour must still be asserted somewhere.

## Why static only

No WordPress, no network, no production. This gate reads files and imports the
readiness engine. The behavioural proofs (PT immutability, collision refusal,
drift refusal, duplicate-execution idempotence, manual-path authentication) live
in the in-process PHP suites, which this gate checks are PRESENT — so deleting a
behavioural suite fails here rather than quietly reducing coverage.

Static only. No WordPress, no network, no production.
"""

from __future__ import annotations

import hashlib
import json
import pathlib
import re
import sys

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
PLUGIN_DIR = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation"
ROLLOUT_DIR = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-rollout"
ENGINE_FILE = ROLLOUT_DIR / "includes" / "class-conexao-translation-rollout-engine.php"

sys.path.insert(0, str(REPO_ROOT / "scripts" / "lib"))
import commissioning  # noqa: E402  (path insertion above is the documented pattern)

PASSED = 0
FAILURES: list = []


def ok(label: str, condition: bool, detail: str = "") -> None:
    global PASSED
    if condition:
        PASSED += 1
        print(f"  PASS: {label}")
    else:
        FAILURES.append(f"{label}{': ' + detail if detail else ''}")
        print(f"  FAIL: {label}{': ' + detail if detail else ''}")


def section(title: str) -> None:
    print(f"\n-- {title} --")


def strip_comments(text: str) -> str:
    """Remove comments only, KEEPING string literals.

    Used for PRESENCE checks ("the manual endpoint requires manage_options",
    "the orchestrator calls Engine::run"). Those identifiers legitimately live
    inside string literals, so a scanner that blanks strings cannot see them.
    """
    text = re.sub(r"/\*.*?\*/", " ", text, flags=re.S)
    return re.sub(r"//[^\n]*", " ", text)


def strip_code(text: str) -> str:
    """Remove comments AND string literals, so a scan tests CODE, not prose.

    Used for ABSENCE checks. Several assertions below search for tokens this
    file's own documentation necessarily NAMES, and the plugin's own test suites
    legitimately carry the forbidden tokens as DETECTION NEEDLES. Without
    stripping, a docblock saying "no broker token is accepted" would satisfy a
    check for a broker token.
    """
    text = strip_comments(text)
    text = re.sub(r"'(?:\\.|[^'\\])*'", "''", text)
    return re.sub(r'"(?:\\.|[^"\\])*"', '""', text)


def php_sources(directory: pathlib.Path, stripper=strip_comments) -> dict:
    """Map every PHP file under `directory` to its scanned source."""
    out = {}
    for path in sorted(directory.rglob("*.php")):
        rel = str(path.relative_to(REPO_ROOT))
        out[rel] = stripper(path.read_text(encoding="utf-8", errors="replace"))
    return out


def is_test(path: str) -> bool:
    """Is this a test file rather than shipped runtime code?"""
    return "/tests/" in path or path.endswith("live-provider-smoke.php")



def main() -> int:
    # `sources` keeps string literals and covers every file, tests included.
    #
    # `runtime` is what the ABSENCE checks scan: shipped, non-test files with
    # comments stripped but STRING LITERALS KEPT.
    #
    # Both halves of that definition are load-bearing, and an injected mutation
    # proved each one:
    #
    #   * tests are excluded because a suite that greps for `wp_schedule_event`
    #     is a detection NEEDLE, not a scheduler — scanning it would only prove
    #     the safety assertions exist;
    #   * strings are KEPT because the thing being banned is usually a string:
    #     `add_action( "save_post", ... )` is invisible to a scanner that blanks
    #     literals. Comments are stripped instead, which is what removes prose.
    sources = php_sources(PLUGIN_DIR)
    rollout = php_sources(ROLLOUT_DIR)
    all_code = {**sources, **rollout}
    runtime = {n: b for n, b in all_code.items() if not is_test(n)}
    plugin_bootstrap = strip_comments(
        (PLUGIN_DIR / "conexao-translation-automation.php").read_text(encoding="utf-8", errors="replace")
    )
    plugin_bootstrap_code = strip_code(
        (PLUGIN_DIR / "conexao-translation-automation.php").read_text(encoding="utf-8", errors="replace")
    )
    provider = sources.get(
        "wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-provider-openai.php", ""
    )
    config = sources.get(
        "wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-provider-config.php", ""
    )
    admin = sources.get(
        "wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-admin-trigger.php", ""
    )
    audit = sources.get(
        "wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-audit.php", ""
    )
    orchestrator = sources.get(
        "wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-orchestrator.php", ""
    )

    # ------------------------------------------------------------------
    section("1. RETIRED - the Stage 16 broker architecture is gone")
    # ------------------------------------------------------------------
    ok(
        "the broker class file does not exist",
        not (PLUGIN_DIR / "includes" / "class-conexao-translation-automation-provider-broker.php").exists(),
    )
    ok(
        "no plugin source declares a Provider_Broker class",
        not any("class Conexao_Translation_Automation_Provider_Broker" in b for b in runtime.values()),
    )
    ok(
        "no plugin source references Provider_Broker at all",
        not any("Provider_Broker" in b for b in runtime.values()),
        ", ".join(n for n, b in runtime.items() if "Provider_Broker" in b),
    )
    for token in ("BROKER_ENDPOINT", "MODE_BROKER", "BROKER_FIELD", "broker_token"):
        hits = [n for n, b in runtime.items() if token.lower() in b.lower()]
        ok(f"no plugin source carries the broker token {token}", not hits, ", ".join(hits))

    # The credential resolution must be the ORIGINAL direct one, with no
    # indirection that could smuggle a second credential path back in.
    ok(
        "the provider resolves its credential directly from Provider_Config",
        "Provider_Config::credentials()" in provider,
    )
    ok(
        "the provider resolves no second credential through a broker",
        "Broker::resolve" not in provider and "Broker::" not in provider and "resolve(" not in provider,
    )

    # ------------------------------------------------------------------
    section("2. RETIRED - no automatic execution mechanism remains")
    # ------------------------------------------------------------------
    ok(
        "the wake-up hook class file does not exist",
        not (PLUGIN_DIR / "includes" / "class-conexao-translation-automation-hooks.php").exists(),
    )
    ok(
        "no plugin source references the deleted Hooks class",
        not any("Automation_Hooks" in b for b in runtime.values()),
        ", ".join(n for n, b in runtime.items() if "Automation_Hooks" in b),
    )
    # Both quote styles and any whitespace are matched. An injected mutation
    # proved a single-quote-only pattern evadable: `add_action( "save_post", ... )`
    # sailed past it. A detector that a rename defeats is not a detector.
    for hook in ("save_post", "before_delete_post", "wp_trash_post", "untrashed_post", "set_object_terms"):
        pattern = r"""add_action\(\s*['"]""" + hook + r"""['"]"""
        hits = [n for n, b in runtime.items() if re.search(pattern, b)]
        ok(f"no plugin registers the {hook} wake-up hook", not hits, ", ".join(hits))
    for token in ("wp_schedule_event", "wp_schedule_single_event", "wp_next_scheduled", "cron_schedules", "wp_clear_scheduled_hook"):
        hits = [n for n, b in runtime.items() if token in b]
        ok(f"no plugin uses {token}", not hits, ", ".join(hits))

    # Automatic provider invocation: the provider must be reachable only from
    # the manual control plane, never from a hook or a scheduler.
    allowed_callers = {
        "wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-translation-plan.php",
        "wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-batch-executor.php",
        "wp-content/plugins/conexao-translation-automation/tests/test-automation-provider-implementation.php",
        "wp-content/plugins/conexao-translation-automation/tests/test-automation-provider.php",
        "wp-content/plugins/conexao-translation-automation/tests/test-automation-provider-secrets.php",
        "wp-content/plugins/conexao-translation-automation/tests/test-automation-transport-shape.php",
        "wp-content/plugins/conexao-translation-automation/tests/live-provider-smoke.php",
    }
    callers = {n for n, b in sources.items() if re.search(r"Provider_OpenAI::translate\s*\(", b)}
    ok(
        "the provider is invoked only from the manual control plane",
        not (callers - allowed_callers),
        ", ".join(sorted(callers - allowed_callers)),
    )

    for token in ("admin_post_nopriv_", "wp_ajax_nopriv_", "wp_ajax_", "register_rest_route", "rest_api_init"):
        hits = [n for n, b in runtime.items() if token in b]
        ok(f"no plugin exposes {token}", not hits, ", ".join(hits))
    for token in ("webhook", "wphook", "inbound_hook"):
        hits = [n for n, b in runtime.items() if token in b.lower()]
        ok(f"no plugin exposes a {token} entry point", not hits, ", ".join(hits))

    # The automatic trigger vocabulary itself. `hook` and `scheduled` were the
    # two audit kinds that named runs nobody requested; with them gone, the
    # audit trail can no longer record an unattended run even by accident.
    ok("the audit vocabulary has no 'hook' trigger kind", "TRIGGER_HOOK" not in audit)
    ok("the audit vocabulary has no 'scheduled' trigger kind", "TRIGGER_SCHEDULED" not in audit)
    ok("the audit vocabulary still records manual runs", "TRIGGER_MANUAL" in audit)
    ok("the audit vocabulary still records the admin proof run", "TRIGGER_ADMIN_PROOF" in audit)

    # The plugin registers exactly ONE thing, and it is the manual admin screen.
    # This is the positive statement of "manual only": the load side effects are
    # enumerated, not merely un-banned.
    registrations = re.findall(r"(\w+)::register\(\s*\)\s*;", plugin_bootstrap_code)
    ok(
        "the plugin bootstraps exactly one registered surface, the manual admin trigger",
        registrations == ["Conexao_Translation_Automation_Admin_Trigger"],
        f"found {registrations}",
    )
    ok(
        "the uncommissioned batch-control surface is still NOT registered",
        not re.search(r"^\s*Conexao_Translation_Automation_Batch_Control::register", plugin_bootstrap_code, re.M),
    )

    # ------------------------------------------------------------------
    section("3. RETAINED - the manual workflow is still available")
    # ------------------------------------------------------------------
    ok(
        "the manual admin endpoint class still exists",
        "class Conexao_Translation_Automation_Admin_Trigger" in admin,
    )
    ok("the manual endpoint requires manage_options", "'manage_options'" in admin)
    ok("the manual endpoint verifies a nonce", "wp_verify_nonce" in admin)
    ok("the manual endpoint is POST-only", "is_post" in admin)
    ok("the manual endpoint can only request MODE_PROOF", "MODE_PROOF" in admin)
    ok("MODE_APPLY is not reachable from the manual endpoint", "MODE_APPLY" not in admin)
    ok(
        "the manual endpoint cannot pass dry_run => false to the engine",
        "dry_run" not in admin or "'dry_run' => false" not in admin,
    )

    # The manual path must reach the SHARED engine, not a second engine.
    ok("the shared engine file still exists", ENGINE_FILE.is_file())
    # The engine invokes each stage's own DECLARED `run_callback`, so the
    # plugin never calls `Engine::run()` itself. Asserting that literal would be
    # asserting something that was never true; what matters is that the manual
    # path goes through the engine's callback contract and reaches its
    # exact-scope (Stage 11 Model A) method.
    ok("the orchestrator reaches the shared engine's stage callback contract", "run_callback" in orchestrator)
    ok(
        "the manual control plane reaches the engine's exact-scope method",
        "narrow_manifest" in orchestrator,
    )
    ok("the orchestrator addresses the shared engine by name", "Conexao_Translation_Rollout_Engine" in orchestrator)
    # No shipped file may call the engine lifecycle directly: that would be a
    # second, review-bypassing route around the orchestrator.
    direct_runs = [n for n, b in runtime.items() if "Conexao_Translation_Rollout_Engine::run(" in b]
    ok("no shipped plugin file calls Engine::run() directly", not direct_runs, ", ".join(direct_runs))
    engine_body = strip_comments(ENGINE_FILE.read_text(encoding="utf-8", errors="replace")) if ENGINE_FILE.is_file() else ""
    ok("the shared engine still exposes its run() lifecycle", re.search(r"public static function run\s*\(", engine_body) is not None)

    # ------------------------------------------------------------------
    section("4. SAFEGUARDS - the manual path's controls are not weakened")
    # ------------------------------------------------------------------
    # The bootstrap loads includes by kebab-case PATH, so the class names never
    # appear in it; the honest assertion is that each safety class's FILE is
    # still shipped AND still loaded by the bootstrap.
    for stub, label in (
        ("lock", "the concurrency lock"),
        ("audit", "the audit trail"),
        ("apply-gate", "the dry-run-before-apply gate"),
        ("digest", "digest-bound approval"),
        ("batch-limits", "batch ceilings"),
        ("emergency-stop", "the emergency stop"),
        ("batch-approval", "digest-bound batch approval"),
        ("source-state", "the persisted source state"),
    ):
        file_name = f"class-conexao-translation-automation-{stub}.php"
        ok(f"{label} is still shipped", (PLUGIN_DIR / "includes" / file_name).is_file())
        ok(f"{label} is still loaded by the plugin bootstrap", file_name in plugin_bootstrap)
    ok(
        "the batch-control surface is still declared and uncommissioned",
        "is_commissioned" in sources.get(
            "wp-content/plugins/conexao-translation-automation/includes/class-conexao-translation-automation-batch-control.php", ""
        ),
    )

    # The behavioural suites that prove the safety properties must still EXIST.
    # This gate is static, so it cannot run them; requiring them to be present
    # is what stops a behavioural suite being deleted to make a change look
    # green.
    for suite in (
        "test-automation-model-a.php",
        "test-automation-apply-safety.php",
        "test-automation-lock.php",
        "test-automation-batch.php",
        "test-automation-change-detection.php",
        "test-automation-audit.php",
        "test-automation-admin-trigger.php",
        "test-automation-provider-secrets.php",
    ):
        ok(f"the behavioural suite {suite} still exists", (PLUGIN_DIR / "tests" / suite).is_file())
    ok(
        "the shared engine's own suite still exists",
        (ROLLOUT_DIR / "tests" / "test-translation-rollout-engine.php").is_file(),
    )

    # ------------------------------------------------------------------
    section("5. ENGINE - Stage 11 Model A and the engine are intact")
    # ------------------------------------------------------------------
    digest = hashlib.sha256(ENGINE_FILE.read_bytes()).hexdigest() if ENGINE_FILE.is_file() else ""
    ok(
        "the shared engine is byte-identical to the pinned digest",
        digest == commissioning.PINNED_ENGINE_SHA256,
        f"is {digest}",
    )
    model_a = PLUGIN_DIR / "tests" / "test-automation-model-a.php"
    body = model_a.read_text(encoding="utf-8", errors="replace") if model_a.is_file() else ""
    for phrase in ("approved scope", "executed scope", "planned"):
        ok(f"the Model A suite still asserts on {phrase!r}", phrase.lower() in body.lower())

    # ------------------------------------------------------------------
    section("6. CREDENTIALS - no key anywhere, and no broker credential")
    # ------------------------------------------------------------------
    # Source, docs and evidence: a provider-key SHAPE must not appear. The
    # prefix is assembled rather than written literally so this detection rule
    # does not itself look like what it detects.
    # NOTE: the REPOSITORY-WIDE credential scan is owned by
    # `tests/scripts/verify-stage14-secret-scan.py`, which also owns the
    # deliberate detection corpus under `tests/fixtures/`. Re-implementing it
    # here would only trip over that corpus — a fixture OF the scan, not a leak —
    # so this gate scans the shipped runtime, the docs and the evidence, and
    # leaves the whole-tree sweep to its owner.
    key_shape = re.compile(r"\bs" + r"k-[A-Za-z0-9]{16,}")
    skip_suffixes = (".zip", ".png", ".jpg", ".jpeg", ".gif", ".woff", ".woff2", ".pot")
    scanned = 0
    violations = []
    for directory in (REPO_ROOT / "wp-content", REPO_ROOT / "docs"):
        for path in sorted(directory.rglob("*")):
            if not path.is_file() or path.suffix.lower() in skip_suffixes:
                continue
            if "__pycache__" in path.parts or "vendor" in path.parts:
                continue
            try:
                text = path.read_text(encoding="utf-8", errors="ignore")
            except OSError:
                continue
            scanned += 1
            if key_shape.search(text):
                violations.append(str(path.relative_to(REPO_ROOT)))
    ok("no provider-key shape exists in shipped code, docs or evidence", not violations, ", ".join(violations))
    ok("the credential scan actually inspected the tree", scanned > 100, f"only {scanned} files")

    # No project-specific credential OPTION. The provider credential must come
    # from the environment of the run, never from wp_options.
    option_hits = [
        n for n, b in sources.items()
        if not is_test(n)
        and re.search(r"OPTION\s*=\s*'[a-z_]*(api_key|apikey|provider_key|credential|secret|token)", b)
    ]
    ok("no plugin option name looks like a provider credential store", not option_hits, ", ".join(option_hits))
    ok("the credential boundary is still environment-only", "CREDENTIAL_ENV" in config or "getenv" in config)
    ok(
        "the credential boundary names a variable rather than holding a value",
        "CONEXAO_TRANSLATION_PROVIDER_KEY" in config or "CREDENTIAL_ENV" in config,
    )


    # ------------------------------------------------------------------
    section("7. READINESS - the automatic commissioning programme is retired")
    # ------------------------------------------------------------------
    ok("the readiness engine records the retirement", commissioning.AUTOMATIC_TRANSLATION_RETIRED is True)
    ok("RETIRED is a defined status", "RETIRED" in commissioning.ALL_STATUSES)
    dispositions = {e["id"]: e.get("disposition") for e in commissioning.PREREQUISITES}
    ok(
        "every prerequisite is classified RETAINED or RETIRED",
        all(v in ("RETAINED", "RETIRED") for v in dispositions.values()),
    )
    for pid in ("P03", "P10", "P11", "P15", "P18"):
        ok(f"{pid} (an automatic-commissioning prerequisite) is RETIRED", dispositions.get(pid) == "RETIRED")
    for pid in ("P07", "P09", "P14", "P16", "P17"):
        ok(f"{pid} (a manual-workflow safety fact) is RETAINED", dispositions.get(pid) == "RETAINED")

    # The decisive property: evaluate everything, THEN retire. A retired
    # aggregate must never skip the evaluation the manual workflow relies on.
    #
    # The REAL `Observation` is used rather than a stub, so this gate exercises
    # the same code path production would, and cannot drift from it.
    good_repo = {
        "engine_digest_matches": True,
        "batch_limits_within_ceilings": True,
        "control_plane_declared_not_commissioned": True,
        "versions": {},
        "artifacts": {},
        "release_built": True,
        "release_deterministic": True,
        "release_secret_free": True,
        "release_test_free": True,
        "release_local_free": True,
    }
    report = commissioning.evaluate(
        commissioning.Observation(env_present=set(), evidence={}, repo=good_repo)
    )
    ok("the aggregate is RETIRED", report["status"] == "RETIRED", report["status"])
    ok("commissioning is never permitted", report["commissioning_permitted"] is False)
    ok("production mutation is never permitted", report["production_mutation_permitted"] is False)
    ok("the report names the retirement", report["automatic_translation"] == "RETIRED")
    ok("every prerequisite is still evaluated individually", len(report["prerequisites"]) == 18)
    ok(
        "integrity invariants are still checked under retirement",
        isinstance(report["integrity_invariants"], dict) and "engine_digest_matches" in report["integrity_invariants"],
    )
    # A genuine integrity failure must still be SURFACED under retirement: a
    # retired aggregate must never hide a real problem.
    broken = commissioning.evaluate(
        commissioning.Observation(env_present=set(), evidence={}, repo=dict(good_repo, engine_digest_matches=False))
    )
    ok(
        "an integrity failure is still surfaced under retirement",
        "engine_digest_matches" in broken["failed_invariants"],
    )
    ok("the retired report still passes its own secret boundary", commissioning.assert_no_secrets(report) is not None)

    runbook = commissioning.render_runbook()
    ok("the runbook is the manual workflow", "Manual translation runbook (Stage 17)" in runbook)
    for phrase in ("dry run", "Approve the exact digest", "Apply explicitly", "idempotence"):
        ok(f"the manual runbook still names the {phrase!r} step", phrase in runbook)


    print()
    print(f"{PASSED} passed, {len(FAILURES)} failed")
    for failure in FAILURES:
        print(f"  FAIL: {failure}")
    print("GATE-RESULT " + json.dumps(
        {
            "gate": "stage17_translation_automation_retirement",
            "status": "fail" if FAILURES else "pass",
            "passed": PASSED,
            "failed": len(FAILURES),
            "negative_proofs": 0,
            "negative_proofs_missed": 0,
            "violations": len(FAILURES),
            "details": FAILURES,
        },
        sort_keys=True,
    ))
    return 1 if FAILURES else 0


if __name__ == "__main__":
    sys.exit(main())
