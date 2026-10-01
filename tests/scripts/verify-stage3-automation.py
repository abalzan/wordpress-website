#!/usr/bin/env python3
"""Stage 3 structural gate: change detection, provider, trigger and audit.

Static only. No WordPress, no network, no production. Complements the in-process
PHP suites by asserting facts about the SOURCE that are impossible to observe
from behaviour alone -- in particular, that the shared engine's bytes have not
moved and that no second translation engine or provider implementation exists.

It deliberately does NOT duplicate the PHP suites' behavioural assertions. Its
job is to fail if a future edit quietly reintroduces a bypass.
"""

from __future__ import annotations

import hashlib
import json
import pathlib
import re
import sys

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
PLUGIN_DIR = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation"
ENGINE = (
    REPO_ROOT
    / "wp-content"
    / "plugins"
    / "conexao-translation-rollout"
    / "includes"
    / "class-conexao-translation-rollout-engine.php"
)

# Pinned by the Stage 1/Stage 2 integrity invariant and re-asserted by Stage 3.
ENGINE_SHA256 = "baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4"

PASSED = 0
FAILURES: list[str] = []


def ok(label: str, condition: bool, detail: str = "") -> None:
    global PASSED
    if condition:
        PASSED += 1
        print(f"  PASS: {label}")
    else:
        message = f"{label}{f' — {detail}' if detail else ''}"
        FAILURES.append(message)
        print(f"  FAIL: {message}")


def strip_php_comments(source: str) -> str:
    """Remove comments so a docblock NAMING a forbidden call cannot trip a scan."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    source = re.sub(r"//[^\n]*", "", source)
    source = re.sub(r"#[^\n]*", "", source)
    return source


def php_files() -> list[pathlib.Path]:
    files = [PLUGIN_DIR / "conexao-translation-automation.php"]
    files += sorted((PLUGIN_DIR / "includes").glob("*.php"))
    return files


def main() -> int:
    print("Stage 3 structural gate — change detection, provider, trigger, audit")

    if not PLUGIN_DIR.is_dir():
        print(f"  FAIL: the plugin directory is missing: {PLUGIN_DIR}")
        return 1

    files = php_files()
    ok("the plugin source tree is readable", bool(files))

    bodies = {f.name: strip_php_comments(f.read_text(encoding="utf-8")) for f in files}

    # ---------------------------------------------------------------- engine
    print("\n-- shared engine integrity --")
    digest = hashlib.sha256(ENGINE.read_bytes()).hexdigest()
    ok(
        f"the shared engine is byte-identical ({ENGINE_SHA256[:16]}…)",
        digest == ENGINE_SHA256,
        f"got {digest}",
    )

    registry = json.loads((REPO_ROOT / "plugins.json").read_text(encoding="utf-8"))
    by_slug = {p["slug"]: p for p in registry["load_order"]}

    for slug in ("conexao-translation-rollout", "conexao-translation-automation"):
        entry = by_slug.get(slug, {})
        ok(
            f"{slug} is classified platform/production/build",
            entry.get("class") == "platform"
            and entry.get("production") is True
            and entry.get("build") is True,
            f"got class={entry.get('class')} production={entry.get('production')} build={entry.get('build')}",
        )

    index = {slug: i for i, slug in enumerate(by_slug)}
    ok(
        "the shared engine loads before the automation plugin",
        index["conexao-translation-rollout"] < index["conexao-translation-automation"],
    )

    # ------------------------------------------------------- no second engine
    print("\n-- no second translation engine --")

    plan_vocabulary = ("build_plan", "validate_manifest", "would-create", "would-update")
    for name, body in bodies.items():
        hits = [t for t in plan_vocabulary if re.search(rf"\b{re.escape(t)}\s*\(", body)]
        ok(f"{name} does not reimplement the engine lifecycle", not hits, ", ".join(hits))

    ok(
        "the plugin never redeclares the engine class",
        not any("class Conexao_Translation_Rollout_Engine" in b for b in bodies.values()),
    )

    # ------------------------------------------------- no direct WP mutation
    print("\n-- no direct WordPress mutation from the automation plugin --")

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
    )
    for name, body in bodies.items():
        hits = [m for m in mutations if re.search(rf"\b{re.escape(m)}\s*\(", body)]
        ok(f"{name} issues no WordPress content write", not hits, ", ".join(hits))

    # ------------------------------------------------ provider boundary
    #
    # STAGE 4 SUPERSESSION, recorded rather than hidden.
    #
    # Stage 3 asserted "there is NO provider: no outbound call, no vendor".
    # Stage 4 was AUTHORISED to add exactly one provider implementation, so that
    # assertion is no longer true and is replaced by a NARROWER one: the outbound
    # call and the vendor reference are confined to the designated provider
    # implementation, and no other file may make a call or name a vendor.
    #
    # Nothing else in this gate is relaxed. The provider still performs no
    # WordPress write (asserted above, for every file), schedules nothing,
    # exposes no route, and embeds no credential.
    print("\n-- provider boundary: exactly one implementation (Stage 4) --")

    STAGE4_PROVIDER = "class-conexao-translation-automation-provider-openai.php"
    STAGE4_CONFIG = "class-conexao-translation-automation-provider-config.php"

    for name, body in bodies.items():
        hits = [
            t
            for t in ("wp_remote_post", "wp_remote_get", "wp_remote_request", "curl_exec", "fsockopen")
            if re.search(rf"\b{re.escape(t)}\s*\(", body)
        ]
        if not hits:
            continue
        ok(
            f"{name} makes the outbound request (designated: {STAGE4_PROVIDER})",
            name == STAGE4_PROVIDER,
            ", ".join(hits),
        )

    ok(
        "exactly one file makes an outbound request",
        sum(
            1
            for b in bodies.values()
            if any(
                re.search(rf"\b{re.escape(t)}\s*\(", b)
                for t in ("wp_remote_post", "wp_remote_get", "wp_remote_request", "curl_exec", "fsockopen")
            )
        )
        == 1,
    )

    provider = bodies.get("class-conexao-translation-automation-provider.php", "")
    ok(
        "the provider contract is declared as an INTERFACE",
        re.search(r"^interface\s+Conexao_Translation_Automation_Provider_Interface", provider, re.M)
        is not None,
    )

    # The contract itself is UNCHANGED by Stage 4: the validator still does not
    # implement it, so a provider can never grade its own homework.
    validator = bodies.get("class-conexao-translation-automation-provider-result.php", "")
    ok(
        "the Stage 3 validator still does not implement the provider contract",
        "implements" not in validator.split("class Conexao_Translation_Automation_Provider_Result")[-1][:200],
    )

    # The vendor is confined to the provider configuration and implementation.
    for vendor in ("OpenAI", "anthropic", "Claude", "Gemini", "deepl", "AWS", "azure"):
        hits = [n for n, b in bodies.items() if vendor.lower() in b.lower()]
        allowed = {STAGE4_PROVIDER, STAGE4_CONFIG, "conexao-translation-automation.php"}
        ok(
            f"vendor reference confined to the provider boundary: {vendor}",
            set(hits) <= allowed,
            ", ".join(sorted(set(hits) - allowed)),
        )

    # ------------------------------------------------- trigger model: no cron
    print("\n-- trigger model: explicit invocation, no cron --")

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

    # STAGE 6 SUPERSESSION. Through Stage 5 the plugin had NO HTTP surface and
    # this forbade admin_post_/admin_menu outright. Stage 6 adds exactly one
    # authenticated admin entry point plus the Tools screen carrying its nonce,
    # so the assertion is narrowed rather than removed. What it still forbids,
    # in EVERY file, is everything that could expose the trigger to an
    # unauthenticated or unintended caller: no REST route, no AJAX handler and
    # no anonymous admin handler. The entry point itself is checked separately,
    # to exactly one file, below.
    admin_allowlist = {"class-conexao-translation-automation-admin-trigger.php"}

    for name, body in bodies.items():
        hits = [
            t
            for t in ("register_rest_route", "rest_api_init", "wp_ajax_", "admin_post_nopriv_")
            if re.search(rf"\b{re.escape(t)}", body)
        ]
        ok(f"{name} exposes no public route, no AJAX handler and no anonymous admin handler", not hits, ", ".join(hits))

        entry = [
            t
            for t in ("admin_post_", "admin_menu")
            if re.search(rf"\b{re.escape(t)}", body)
        ]
        if entry:
            ok(
                f"{name} is an allowed admin-surface file",
                name in admin_allowlist,
                f"unexpected admin surface in {name}: {', '.join(entry)}",
            )

    trigger = bodies.get("class-conexao-translation-automation-trigger.php", "")
    ok("the trigger never names MODE_APPLY", "MODE_APPLY" not in trigger)
    ok("the trigger invokes the orchestrator in MODE_PROOF", "MODE_PROOF" in trigger)
    ok(
        "the orchestrator is invoked from exactly one place",
        sum(b.count("Orchestrator::run(") for b in bodies.values()) == 1,
    )

    hooks = bodies.get("class-conexao-translation-automation-hooks.php", "")
    ok("the hooks never invoke the orchestrator", "Orchestrator::run(" not in hooks)
    ok("the hooks never invoke the engine", "Rollout_Engine::run(" not in hooks)

    # ------------------------------------------- state is namespaced, no secrets
    print("\n-- persisted state is namespaced and content-free --")

    option_constants = re.findall(
        r"const\s+(?:OPTION|MARKER_OPTION|STATE_OPTION)\s*=\s*'([^']+)'", "\n".join(bodies.values())
    )
    for option in sorted(set(option_constants)):
        ok(
            f"option {option} is namespaced to this plugin",
            option.startswith("conexao_translation_automation_"),
        )

    content_keys = ("post_content", "post_excerpt", "en_title", "en_content", "en_description")
    for name, body in bodies.items():
        if not name.startswith("class-conexao-translation-automation-source-state"):
            continue
        hits = [k for k in content_keys if f"'{k}'" in body]
        ok(f"{name} stores digests, not content", not hits, ", ".join(hits))

    print("\n-- no credentials in source --")
    for name, body in bodies.items():
        hits = [
            c
            for c in ("WP_APPLICATION_PASSWORD", "WP_USERNAME", "DB_PASSWORD", "AUTH_KEY", "OPENAI_API_KEY")
            if c in body
        ]
        ok(f"{name} references no credential constant", not hits, ", ".join(hits))

    print(f"\n{PASSED} passed, {len(FAILURES)} failed")

    if FAILURES:
        print("\nFAILURES:")
        for failure in FAILURES:
            print(f"  - {failure}")
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
