#!/usr/bin/env python3
"""
Stage 4 structural gate — provider implementation, translation plan, adapter.

Stage 3 proved there was NO provider. Stage 4 adds exactly ONE, so this gate
inverts that assertion into a NARROWER and STRONGER one: exactly one
implementation, in exactly one designated file, which makes exactly the
designated outbound call.

Every guarantee Stage 3 proved that is still true — no WordPress write, no
cron, no public route, no second engine, no credential in source — is
re-asserted here, now INCLUDING the new files. Nothing is relaxed: the only
assertion that changes is "no provider exists" becoming "exactly one provider
exists, and it is the audited one".

Static checks over the plugin source. No WordPress, no network, no production.
"""
from __future__ import annotations

import hashlib
import json
import pathlib
import re
import sys

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
PLUGIN_DIR = REPO_ROOT / "wp-content/plugins/conexao-translation-automation"
INCLUDES = PLUGIN_DIR / "includes"
ENGINE = (
    REPO_ROOT
    / "wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php"
)
ENGINE_SHA256 = "baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4"

# The ONE file permitted to hold the provider implementation and the ONE
# outbound call it is allowed to make. Designated, not inferred.
PROVIDER_FILE = "class-conexao-translation-automation-provider-openai.php"
CONFIG_FILE = "class-conexao-translation-automation-provider-config.php"
PLAN_FILE = "class-conexao-translation-automation-translation-plan.php"
ADAPTER_FILE = "class-conexao-translation-automation-plan-adapter.php"
STAGE4_FILES = (PROVIDER_FILE, CONFIG_FILE, PLAN_FILE, ADAPTER_FILE)

PASSED = 0
FAILURES: list[str] = []


def ok(label: str, condition: bool, detail: str = "") -> None:
    global PASSED
    if condition:
        PASSED += 1
        print(f"  PASS: {label}")
    else:
        message = f"{label}" + (f" — {detail}" if detail else "")
        FAILURES.append(message)
        print(f"  FAIL: {message}")


def strip_php_comments(source: str) -> str:
    """Remove comments so a docblock that NAMES a forbidden call cannot fail the gate."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    return re.sub(r"//[^\n]*", "", source)


def php_files() -> list[pathlib.Path]:
    files = [PLUGIN_DIR / "conexao-translation-automation.php"]
    if INCLUDES.is_dir():
        files += sorted(INCLUDES.glob("*.php"))
    return [f for f in files if f.is_file()]


def main() -> int:
    print("Stage 4 structural gate — provider, translation plan, plan adapter")

    if not PLUGIN_DIR.is_dir():
        print(f"  FAIL: the plugin directory is missing: {PLUGIN_DIR}")
        return 1

    files = php_files()
    ok("the plugin source tree is readable", bool(files))

    bodies = {f.name: strip_php_comments(f.read_text(encoding="utf-8")) for f in files}

    for required in STAGE4_FILES:
        ok(f"{required} exists", required in bodies)

    # ---------------------------------------------------------------- engine
    print("\n-- shared engine integrity --")
    digest = hashlib.sha256(ENGINE.read_bytes()).hexdigest()
    ok(
        f"the shared engine is byte-identical ({ENGINE_SHA256[:16]}…)",
        digest == ENGINE_SHA256,
        f"got {digest}",
    )

    # ------------------------------------------------- exactly one provider
    print("\n-- exactly one provider implementation --")

    for name, body in bodies.items():
        hits = re.findall(r"^(?:final |abstract )?class\s+(\w+)[^\n]*\bimplements\b[^\n]*Provider_Interface", body, re.M)
        if hits:
            ok(
                f"the only provider implementation lives in the designated file ({PROVIDER_FILE})",
                name == PROVIDER_FILE,
                f"{name} declares {hits}",
            )

    ok(
        "the provider implementation declares exactly one implementing class",
        sum(
            len(re.findall(r"^(?:final |abstract )?class\s+(\w+)[^\n]*\bimplements\b[^\n]*Provider_Interface", b, re.M))
            for b in bodies.values()
        )
        == 1,
    )

    # The provider interface itself is unchanged: still an interface, and the
    # validator still does not implement it.
    interface = bodies.get("class-conexao-translation-automation-provider.php", "")
    ok(
        "the provider contract is still declared as an interface",
        bool(re.search(r"^interface\s+Conexao_Translation_Automation_Provider_Interface", interface, re.M)),
    )
    validator = bodies.get("class-conexao-translation-automation-provider-result.php", "")
    ok(
        "the validator still does not implement the provider contract",
        "implements" not in validator.split("class Conexao_Translation_Automation_Provider_Result")[-1][:200],
    )

    # ------------------------------------------- outbound requests: one file
    print("\n-- outbound requests are confined to the provider --")

    network = ("wp_remote_post", "wp_remote_get", "wp_remote_request", "curl_exec", "fsockopen")

    for name, body in bodies.items():
        hits = [t for t in network if re.search(rf"\b{re.escape(t)}\s*\(", body)]
        if hits:
            ok(
                f"{name} makes the outbound request (designated: {PROVIDER_FILE})",
                name == PROVIDER_FILE,
                f"unexpected {hits}",
            )

    ok(
        f"exactly one file makes an outbound request ({PROVIDER_FILE})",
        sum(
            1
            for b in bodies.values()
            if any(re.search(rf"\b{re.escape(t)}\s*\(", b) for t in network)
        )
        == 1,
    )

    # ----------------------------------------- no WordPress write, anywhere
    print("\n-- the provider layer performs no WordPress write --")

    mutations = (
        "wp_insert_post",
        "wp_update_post",
        "wp_delete_post",
        "wp_trash_post",
        "update_post_meta",
        "add_post_meta",
        "delete_post_meta",
        "wp_insert_term",
        "wp_update_term",
        "wp_delete_term",
        "wp_set_object_terms",
        "set_post_thumbnail",
        "pll_set_post_language",
        "pll_save_post_translations",
        "pll_save_term_translations",
    )

    # The Stage 3 guarantee still holds for EVERY file, the new ones included.
    for name, body in bodies.items():
        hits = [m for m in mutations if re.search(rf"\b{re.escape(m)}\s*\(", body)]
        ok(f"{name} issues no WordPress content write", not hits, ", ".join(hits))

    # The plan layer persists nothing of its own.
    for name in STAGE4_FILES:
        body = bodies.get(name, "")
        hits = [
            p
            for p in ("update_option", "add_option", "set_transient")
            if re.search(rf"\b{p}\s*\(", body)
        ]
        ok(f"{name} persists nothing of its own", not hits, ", ".join(hits))

    # ------------------------------------------- no cron, no public surface
    print("\n-- no autonomous trigger --")

    for name, body in bodies.items():
        hits = [
            t
            for t in (
                "wp_schedule_event",
                "wp_schedule_single_event",
                "wp_next_scheduled",
                "wp_unschedule_event",
                "cron_schedules",
            )
            if re.search(rf"\b{re.escape(t)}\s*\(", body)
        ]
        ok(f"{name} schedules nothing", not hits, ", ".join(hits))

    for name, body in bodies.items():
        hits = [
            t
            for t in ("register_rest_route", "rest_api_init", "admin_post_", "wp_ajax_", "admin_menu")
            if re.search(rf"\b{re.escape(t)}", body)
        ]
        ok(f"{name} exposes no public route or admin handler", not hits, ", ".join(hits))

    # ------------------------------------------ the plan is not an engine
    print("\n-- the translation plan is not a second engine --")

    plan_vocabulary = (
        "build_plan",
        "validate_manifest",
        "would-create",
        "would-update",
        "calculate_gate",
        "diff_snapshots",
        "collect_verify",
        "assert_no_pt_drift",
        "register_stage",
    )

    for name in STAGE4_FILES:
        body = bodies.get(name, "")
        hits = [t for t in plan_vocabulary if re.search(rf"\b{re.escape(t)}\s*\(", body)]
        ok(f"{name} does not reimplement the engine lifecycle", not hits, ", ".join(hits))

    # Plan creation is not an alternative APPLY engine.
    for name in (PLAN_FILE, ADAPTER_FILE):
        body = bodies.get(name, "")
        ok(
            f"{name} names no apply mode",
            "MODE_APPLY" not in body and "'apply'" not in body,
        )

    ok(
        "the plugin never redeclares the engine class",
        not any("class Conexao_Translation_Rollout_Engine" in b for b in bodies.values()),
    )


    # --------------------------------------------------- the review gate
    print("\n-- every generated plan requires human review --")

    plan_body = bodies.get(PLAN_FILE, "")
    ok("the plan declares the REVIEW_REQUIRED state", "REVIEW_REQUIRED" in plan_body)
    ok(
        "the plan declares the human_review_required quality boundary",
        "human_review_required" in plan_body,
    )
    ok(
        "the plan declares NO approval state",
        not any(f"'{state}'" in plan_body for state in ("APPROVED", "PUBLISHED", "APPROVE", "APPLY")),
        "an approval constant exists and could be set later without review",
    )
    ok("the plan offers no approve() method", "function approve" not in plan_body)
    ok("the plan offers no way to set a review status", "set_review_status" not in plan_body)

    # ------------------------------------------------- the credential edge
    print("\n-- the credential boundary --")

    config_body = bodies.get(CONFIG_FILE, "")

    ok("the credential is read from the environment", "getenv" in config_body)
    ok(
        "no credential constant is declared",
        not any(
            c in config_body
            for c in (
                "WP_APPLICATION_PASSWORD",
                "WP_USERNAME",
                "DB_PASSWORD",
                "AUTH_KEY",
                "OPENAI_API_KEY",
            )
        ),
    )

    # No file may hard-code a provider credential shape.
    for name, body in bodies.items():
        ok(f"{name} embeds no provider key literal", "sk-" not in body)

    # The credential VARIABLE NAME may appear only in the config boundary.
    env_name = "CONEXAO_TRANSLATION_PROVIDER_KEY"
    for name, body in bodies.items():
        if env_name in body:
            ok(
                f"{name} names the credential variable (configuration boundary only)",
                name == CONFIG_FILE,
                f"{name} names it too",
            )

    # The provider must NOT be able to grade its own homework.
    provider_body = bodies.get(PROVIDER_FILE, "")
    ok("the provider never calls the Stage 3 validator", "Provider_Result::" not in provider_body)

    # Slugs and taxonomy: the provider invents neither.
    adapter_body = bodies.get(ADAPTER_FILE, "")
    ok(
        "the adapter refuses a provider-supplied slug",
        "post_name" in adapter_body and "FORBIDDEN_FIELDS" in adapter_body,
    )
    ok(
        "the adapter invents no taxonomy term",
        "wp_insert_term" not in adapter_body and "wp_set_object_terms" not in adapter_body,
    )

    print(f"\n{PASSED} passed, {len(FAILURES)} failed")

    if FAILURES:
        print("\nFAILURES:")
        for failure in FAILURES:
            print(f"  - {failure}")
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())

