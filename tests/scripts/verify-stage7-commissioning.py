#!/usr/bin/env python3
"""Stage 7 gate: the Stage 6 SUPERSESSION contract, formalized.

## Why this file exists

Through Stage 5 the automation plugin asserted an ABSOLUTE invariant: no
`admin_post_` anywhere. Stage 6 added exactly one authenticated production entry
point, which is correct for WordPress.com (no SSH, no WP-CLI, no filesystem --
an operable capability must have an admin screen). Stage 7 is the production
commissioning gate, so that narrow exception is no longer an informal note in
three test files. It becomes ONE named, counted, per-action contract.

## The contract

Exactly ONE approved automation admin-post endpoint:

    admin_post_conexao_translation_automation_proof

registered in ONE approved file:
`includes/class-conexao-translation-automation-admin-trigger.php`

And ZERO forbidden entry surfaces, in EVERY file of the plugin.

This gate asserts the endpoint by its RESOLVED ACTION NAME, not merely by the
presence of the substring `admin_post_`. Substring matching alone cannot tell
the approved endpoint from a second, differently-named one -- which is exactly
the generalization this contract exists to prevent.

## Scope, stated honestly

The contract covers the AUTOMATION plugin. `conexao-translation-rollout`
(the shared engine) ships its own long-standing operator endpoint
`admin_post_conexao_translation_rollout_run`, which IS apply-capable. That
endpoint PREDATES this program (Stage H) and belongs to a different component
with its own capability + nonce checks.

It is DECLARED here explicitly, in DEFERRED_ENGINE_SURFACES, rather than being
silently ignored or silently "fixed". Removing or narrowing it would be an
unauthorized behaviour change to a component outside Stage 7's scope; ignoring
it would hide a real apply-capable production surface from a reader who greps
for `admin_post_`. It is therefore reported as an operator decision for
Stage 8, not asserted as either safe or unsafe by this gate.

Static only. No WordPress, no network, no production.
"""

from __future__ import annotations

import pathlib
import re
import sys

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
PLUGIN_DIR = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation"
ENGINE_DIR = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-rollout"

ENTRY_FILE = "class-conexao-translation-automation-admin-trigger.php"

# The ONE approved automation admin-post endpoint, by exact action name.
APPROVED_ACTION = "conexao_translation_automation_proof"
APPROVED_FILE = ENTRY_FILE

# A pre-existing, apply-capable endpoint in a DIFFERENT component. Declared,
# not asserted clean and not asserted forbidden -- see the module docstring.
DEFERRED_ENGINE_SURFACES = {
    "conexao_translation_rollout_run": (
        "conexao-translation-rollout (Stage H, pre-existing). Apply-capable "
        "operator endpoint with its own capability + nonce checks. OUT of the "
        "automation contract; an operator decision for Stage 8."
    ),
}

# Entry surfaces forbidden in EVERY file of the automation plugin.
FORBIDDEN_SURFACES = (
    "admin_post_nopriv_",
    "wp_ajax_nopriv_",
    "wp_ajax_",
    "rest_api_init",
    "register_rest_route",
    "__return_true",
    "wp_schedule_event",
    "wp_schedule_single_event",
    "wp_next_scheduled",
    "wp_unschedule_event",
    "cron_schedules",
)

# Webhook-style inbound entry: reachable without the wp-admin
# POST + capability + nonce chain.
WEBHOOK_TOKENS = ("webhook", "wphook", "inbound_hook")

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
    """Remove comments so a docblock NAMING a surface cannot trip a scan."""
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    source = re.sub(r"//[^\n]*", "", source)
    source = re.sub(r"#[^\n]*", "", source)
    return source


def php_files(directory: pathlib.Path) -> list[pathlib.Path]:
    """Every shipped PHP file: main + includes, excluding tests."""
    return sorted(
        p
        for p in directory.rglob("*.php")
        if "tests" not in p.relative_to(directory).parts
    )


def resolve_admin_post_actions(body: str) -> list[str]:
    """Extract the resolved action name behind every `admin_post_` registration.

    Handles both spellings the codebase could use:
        add_action( 'admin_post_conexao_x', ... )       -> conexao_x
        add_action( 'admin_post_' . self::ACTION, ... )  -> resolved via const
    """
    found: list[str] = []

    for literal in re.findall(r"add_action\(\s*'admin_post_([A-Za-z0-9_]+)'", body):
        found.append(literal)

    for const in re.findall(r"add_action\(\s*'admin_post_'\s*\.\s*self::([A-Z_]+)", body):
        match = re.search(rf"const\s+{re.escape(const)}\s*=\s*'([A-Za-z0-9_]+)'", body)
        found.append(match.group(1) if match else f"<unresolved:{const}>")

    return found


def main() -> int:
    print("== Stage 7: the Stage 6 supersession contract ==")

    files = php_files(PLUGIN_DIR)
    bodies = {
        f.relative_to(PLUGIN_DIR).as_posix(): strip_php_comments(
            f.read_text(encoding="utf-8")
        )
        for f in files
    }

    # -- 1. Exactly one approved admin-post endpoint, by exact action name. --
    registrations: list[tuple[str, str]] = []
    for name, body in bodies.items():
        for action in resolve_admin_post_actions(body):
            registrations.append((action, name))

    actions = sorted({a for a, _ in registrations})
    owners = sorted({f for _, f in registrations})

    ok(
        f"exactly one admin-post endpoint is registered ({APPROVED_ACTION})",
        actions == [APPROVED_ACTION],
        f"found {actions}",
    )
    ok(
        "the approved endpoint is registered exactly once",
        len(registrations) == 1,
        f"{len(registrations)} registration(s): {registrations}",
    )
    ok(
        f"the approved endpoint lives in the approved file ({APPROVED_FILE})",
        owners == [f"includes/{APPROVED_FILE}"],
        f"owners={owners}",
    )

    # A differently-named endpoint is the failure this contract exists to catch.
    ok(
        "no second, differently-named automation admin endpoint exists",
        all(a == APPROVED_ACTION for a in actions),
        f"unexpected: {[a for a in actions if a != APPROVED_ACTION]}",
    )

    # -- 2. Zero forbidden entry surfaces, in EVERY file. -------------------
    for name, body in bodies.items():
        hits = sorted(t for t in FORBIDDEN_SURFACES if t in body)
        ok(f"{name} exposes no forbidden entry surface", not hits, ", ".join(hits))

        # Deliberately a SUBSTRING scan, not `\b`-anchored: PHP identifiers
        # commonly embed a word after an underscore (conexao_translation_webhook),
        # and `_` IS a word character, so a `\b` anchor would MISS the very
        # surface this check exists to catch. A negative proof caught that.
        hooks = sorted(t for t in WEBHOOK_TOKENS if t in body.lower())
        ok(f"{name} exposes no webhook entry point", not hooks, ", ".join(hooks))

    # -- 3. The approved endpoint is not apply-capable. ---------------------
    entry = bodies.get(f"includes/{APPROVED_FILE}", "")
    ok("the approved endpoint never names an apply mode", "MODE_APPLY" not in entry)
    ok(
        "the approved endpoint's action constant is the approved name",
        f"const ACTION = '{APPROVED_ACTION}'" in entry,
    )
    ok(
        "the approved endpoint stays behind capability and nonce",
        "has_capability()" in entry and "verify_nonce()" in entry,
    )

    # -- 4. The pre-existing engine endpoint stays DECLARED. ----------------
    # If it is renamed the declaration goes stale and this fails closed,
    # prompting a re-decision rather than letting it drift unnoticed.
    engine_actions: list[str] = []
    for f in php_files(ENGINE_DIR):
        engine_actions.extend(
            resolve_admin_post_actions(strip_php_comments(f.read_text(encoding="utf-8")))
        )

    declared = set(DEFERRED_ENGINE_SURFACES)
    observed = set(engine_actions)
    ok(
        "every engine admin-post endpoint is explicitly DECLARED in this gate",
        observed <= declared,
        f"undeclared engine endpoint(s): {sorted(observed - declared)}",
    )
    ok(
        "no declared engine endpoint has silently disappeared",
        declared <= observed,
        f"declared but absent: {sorted(declared - observed)}",
    )

    for action, note in DEFERRED_ENGINE_SURFACES.items():
        print(f"  NOTE: pre-existing engine endpoint '{action}' — {note}")

    print()
    print(f"{PASSED} passed, {len(FAILURES)} failed")
    for failure in FAILURES:
        print(f"  FAIL: {failure}")
    return 1 if FAILURES else 0


if __name__ == "__main__":
    sys.exit(main())
