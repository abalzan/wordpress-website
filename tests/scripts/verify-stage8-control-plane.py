#!/usr/bin/env python3
"""Stage 8 gate: ONE production translation-mutation control plane.

## Why this file exists

Stage 7 closed the supersession contract for the AUTOMATION plugin and DECLARED
one pre-existing apply-capable surface it could not itself resolve:
`admin_post_conexao_translation_rollout_run`, in the shared engine
`conexao-translation-rollout`. Stage 8 resolves it (hard-fail deprecation) and
this gate is what makes the resolution PERMANENT.

The contract, stated as three buckets:

  APPROVED    exactly one production mutation control plane:
              `conexao-translation-automation`
              -> `admin_post_conexao_translation_automation_proof`

  PROOF-ONLY  that endpoint performs proof/dry-run work only and is
              structurally unable to request apply.

  FORBIDDEN   any surface that maps directly to apply, calls `Engine::run()`
              with a caller-controlled apply mode, invokes mutation callbacks
              directly, bypasses the orchestrator, bypasses lock/approval/F7,
              or exposes anonymous access.

## The legacy endpoint's disposition, asserted structurally

`admin_post_conexao_translation_rollout_run` is CLOSED. The gate asserts that
by PARSED SOURCE, not by the absence of a substring:

  - the action is still registered (so a bookmark gets a named refusal, and so
    its disappearance fails closed rather than passing unnoticed);
  - its handler contains NO `call_user_func`, NO `run_callback` reference and
    NO `Engine::` call, so no path in that file reaches the engine;
  - it reads NO superglobal, so no request value can become a mode;
  - its handler has no apply/remove vocabulary at all.

## Substring matching is not enough, and never was

Stage 7 learned this the hard way: a `\b`-anchored regex cannot match
`conexao_translation_webhook`, because `_` IS a word character. So here:

  - endpoints are resolved to their REAL action names from source, following
    `'admin_post_' . self::CONST` to the constant's VALUE;
  - ownership is asserted by exact file, not by "a file that mentions it";
  - counts are exact, so a second endpoint in the SAME file is caught too.

Static only. No WordPress, no network, no production.
"""

from __future__ import annotations

import hashlib
import pathlib
import re
import sys

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
PLUGIN_DIR = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-automation"
ENGINE_DIR = REPO_ROOT / "wp-content" / "plugins" / "conexao-translation-rollout"

# The mutation authority itself. Stage 8 closes an ENTRY POINT; it does not
# touch the engine's lifecycle, so this digest MUST NOT MOVE. Asserted here so
# "we only changed the endpoint" is a checkable fact, not a claim.
ENGINE_CORE = ENGINE_DIR / "includes" / "class-conexao-translation-rollout-engine.php"
ENGINE_SHA256 = "baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4"
ENGINE_DIGEST_OK = (
    ENGINE_CORE.is_file()
    and hashlib.sha256(ENGINE_CORE.read_bytes()).hexdigest() == ENGINE_SHA256
)

ENTRY_FILE = "class-conexao-translation-automation-admin-trigger.php"
ORCHESTRATOR_FILE = "class-conexao-translation-automation-orchestrator.php"
ENGINE_ADMIN_FILE = "class-conexao-translation-rollout-admin.php"

# The ONE approved production control plane, by exact action name.
APPROVED_ACTION = "conexao_translation_automation_proof"
APPROVED_FILE = ENTRY_FILE

# The legacy endpoint Stage 8 closed.
CLOSED_ACTION = "conexao_translation_rollout_run"
CLOSED_FILE = ENGINE_ADMIN_FILE

# Entry surfaces forbidden in EVERY shipped file of the production pair.
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

# Deliberately SUBSTRING, never `\b`-anchored: `_` is a word character, so a
# `\b` anchor would MISS `conexao_translation_webhook` -- the exact surface
# this scan exists to catch. (Stage 7 negative proof 4 caught that defect.)
WEBHOOK_TOKENS = ("webhook", "wphook", "inbound_hook")

# Tokens that would mean the closed endpoint can still reach the engine.
ENGINE_REACH_TOKENS = ("call_user_func", "run_callback", "Engine::")

# Narrower set for the APPROVED entry file, which legitimately contains
# `call_user_func( self::$orchestrator, ... )` -- the approved seam. Only a call
# to a STAGE callback or to the engine itself is forbidden there.
ENTRY_FORBIDDEN_TOKENS = ("run_callback", "Engine::")

# Superglobals: the closed endpoint must read none of them.
SUPERGLOBALS = ("$_POST", "$_GET", "$_REQUEST")

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
    """Remove comments so a docblock NAMING a surface cannot trip a scan.

    This matters here specifically: the closed endpoint's docblock documents the
    call graph it USED to have, including `call_user_func` and `Engine::run`.
    Scanning unstripped source would flag its own documentation.
    """
    source = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    source = re.sub(r"//[^\n]*", "", source)
    source = re.sub(r"#[^\n]*", "", source)
    return source


def php_files(directory: pathlib.Path) -> list[pathlib.Path]:
    """Every shipped PHP file: main + includes, excluding tests."""
    return sorted(
        p for p in directory.rglob("*.php") if "tests" not in p.relative_to(directory).parts
    )


def resolve_admin_post_actions(body: str) -> list[tuple[str, str]]:
    """(action, callback) for every `admin_post_` registration, resolved.

    Follows both spellings to the real action name:
        add_action( 'admin_post_conexao_x', 'cb' )        -> conexao_x
        add_action( 'admin_post_' . self::CONST, ... )    -> CONST's value
    """
    found: list[tuple[str, str]] = []

    for action, callback in re.findall(
        r"add_action\(\s*'admin_post_([A-Za-z0-9_]+)'\s*,\s*([^)]+)\)", body
    ):
        found.append((action, callback.strip()))

    for const, callback in re.findall(
        r"add_action\(\s*'admin_post_'\s*\.\s*self::([A-Z_]+)\s*,\s*([^)]+)\)", body
    ):
        match = re.search(rf"const\s+{re.escape(const)}\s*=\s*'([A-Za-z0-9_]+)'", body)
        found.append((match.group(1) if match else f"<unresolved:{const}>", callback.strip()))

    return found


def method_body(body: str, method: str) -> str:
    """The source of one method body, brace-matched from its signature."""
    match = re.search(rf"function\s+{re.escape(method)}\s*\([^)]*\)[^{{]*\{{", body)
    if not match:
        return ""

    start = match.end()
    depth = 1
    index = start

    while index < len(body) and depth:
        if body[index] == "{":
            depth += 1
        elif body[index] == "}":
            depth -= 1
        index += 1

    return body[start : index - 1]


def collect() -> dict[str, str]:
    """Comment-stripped source of every shipped file of both plugins, keyed."""
    bodies: dict[str, str] = {}

    for base, prefix in ((ENGINE_DIR, "engine"), (PLUGIN_DIR, "automation")):
        for path in php_files(base):
            key = f"{prefix}/{path.relative_to(base).as_posix()}"
            bodies[key] = strip_php_comments(path.read_text(encoding="utf-8"))

    return bodies


def main() -> int:
    print("== Stage 8: one production translation-mutation control plane ==")

    bodies = collect()

    # --- 1. APPROVED: exactly one control plane, by exact action name ------
    registrations: list[tuple[str, str]] = []

    for key, body in bodies.items():
        for action, _callback in resolve_admin_post_actions(body):
            registrations.append((action, key))

    approved = [r for r in registrations if r[0] == APPROVED_ACTION]
    closed = [r for r in registrations if r[0] == CLOSED_ACTION]

    ok(
        f"exactly one production control plane is registered ({APPROVED_ACTION})",
        len(approved) == 1,
        f"found {[r[0] for r in approved]}",
    )
    ok(
        "the approved control plane lives in its approved file",
        [r[1] for r in approved] == [f"automation/includes/{APPROVED_FILE}"],
        f"owner={[r[1] for r in approved]}",
    )

    # The decisive assertion: NO OTHER action exists across BOTH plugins.
    unexpected = sorted({a for a, _ in registrations} - {APPROVED_ACTION, CLOSED_ACTION})
    ok(
        "no second, differently-named mutation or proof endpoint exists in either plugin",
        not unexpected,
        f"unexpected: {unexpected}",
    )
    ok(
        f"exactly 2 admin-post endpoint(s) exist across both plugins ({APPROVED_ACTION} + the closed one)",
        len(registrations) == 2,
        f"{len(registrations)}: {[r[0] for r in registrations]}",
    )

    # --- 2. CLOSED: the legacy endpoint can no longer reach the engine ------
    ok(
        f"the closed endpoint is still registered ({CLOSED_ACTION}), so its removal fails closed",
        len(closed) == 1,
        f"found {[r[0] for r in closed]}",
    )
    ok(
        "the closed endpoint lives in the engine admin class",
        [r[1] for r in closed] == [f"engine/includes/{CLOSED_FILE}"],
        f"owner={[r[1] for r in closed]}",
    )

    closed_body = bodies.get(f"engine/includes/{CLOSED_FILE}", "")
    handler = method_body(closed_body, "handle_run")

    ok("the closed endpoint has an auditable handle_run method", bool(handler))

    reach = [t for t in ENGINE_REACH_TOKENS if t in handler]
    ok(
        "the closed endpoint cannot reach the engine, a stage callback, or apply",
        not reach,
        f"found {reach}",
    )

    supers = [s for s in SUPERGLOBALS if s in handler]
    ok(
        "the closed endpoint reads no request superglobal (no mode can be caller-supplied)",
        not supers,
        f"found {supers}",
    )

    modes = [m for m in ("'apply'", '"apply"', "'remove'", '"remove"') if m in handler]
    ok(
        "the closed endpoint carries no apply/remove mode vocabulary",
        not modes,
        f"found {modes}",
    )

    ok(
        "the closed endpoint refuses with a hard failure, not a redirect",
        "wp_die(" in handler and "wp_safe_redirect" not in handler and "410" in handler,
    )

    # The whole FILE must be engine-free, not just the handler: a helper method
    # added later must not become a second route to apply.
    file_reach = [t for t in ENGINE_REACH_TOKENS if t in closed_body]
    ok(
        f"{CLOSED_FILE} contains NO engine reachability token at all",
        not file_reach,
        f"found {file_reach}",
    )

    file_supers = [s for s in SUPERGLOBALS if s in closed_body]
    ok(
        f"{CLOSED_FILE} reads no superglobal anywhere",
        not file_supers,
        f"found {file_supers}",
    )

    # --- 3. PROOF-ONLY: the approved endpoint cannot request apply ---------
    #
    # BUG FOUND BY NEGATIVE PROOF 11: this section originally checked the
    # approved endpoint's method bodies but never asserted that the ENTRY FILE
    # as a whole cannot reach a stage run_callback. A `const BYPASS = ...`
    # closure added to that file invoked `call_user_func($config['run_callback'],
    # ['dry_run' => false])` and the gate still exited 0 -- a real hole, since
    # the entry file is the only production-reachable surface.
    #
    # The token set is deliberately NARROWER than the closed endpoint's. This
    # file legitimately contains `call_user_func( self::$orchestrator, ... )`:
    # that is the APPROVED seam, and scanning for the bare `call_user_func`
    # token flagged it -- a false positive caught by re-running the clean
    # baseline after adding the check. What must be absent is a call to a STAGE
    # run_callback or to the engine, not the orchestrator invocation itself.
    entry = bodies.get(f"automation/includes/{APPROVED_FILE}", "")
    entry_reach = [t for t in ENTRY_FORBIDDEN_TOKENS if t in entry]
    ok(
        f"{APPROVED_FILE} cannot reach a stage run_callback or the engine directly",
        not entry_reach,
        f"found {entry_reach}",
    )
    ok(
        f"{APPROVED_FILE} contains no dry_run=false literal",
        "'dry_run' => false" not in entry and '"dry_run" => false' not in entry,
    )
    entry_handler = method_body(entry, "handle_http")

    ok("the approved endpoint has an auditable handle_http method", bool(entry_handler))
    ok("the approved endpoint exposes no apply mode constant", "MODE_APPLY" not in entry)
    ok(
        "the approved endpoint's action constant is the approved name",
        f"const ACTION = '{APPROVED_ACTION}'" in entry,
    )
    ok(
        "the approved endpoint stays behind capability and nonce",
        "has_capability()" in entry and "verify_nonce()" in entry,
    )
    # The POST guard is enforced in handle_request() (the method that also
    # performs the auth chain), which handle_http() delegates to. Asserting it
    # in handle_http() or validate_request() alone was wrong in an earlier
    # draft of this gate; the check belongs where the code actually is.
    handler_request = method_body(entry, "handle_request")

    ok("the approved endpoint has an auditable handle_request method", bool(handler_request))
    ok("the approved endpoint enforces POST-only before anything else", "is_post()" in handler_request)
    ok(
        "the POST check precedes the capability check in the auth chain",
        0 <= handler_request.find("is_post()") < handler_request.find("has_capability()"),
    )
    ok(
        "the capability check precedes the nonce check",
        0 <= handler_request.find("has_capability()") < handler_request.find("verify_nonce()"),
    )
    ok(
        "the nonce check precedes the orchestrator invocation",
        0 <= handler_request.find("verify_nonce()") < handler_request.find("self::invoke("),
    )
    ok(
        "input validation precedes the orchestrator invocation",
        0 <= handler_request.find("validate_request(") < handler_request.find("self::invoke("),
    )
    ok(
        "the approved endpoint never reads a superglobal for its mode",
        not [s for s in SUPERGLOBALS if s in entry_handler],
        f"found {[s for s in SUPERGLOBALS if s in entry_handler]}",
    )

    # --- 4. The orchestrator remains the only route to apply ---------------
    orchestrator = bodies.get(f"automation/includes/{ORCHESTRATOR_FILE}", "")
    write_at = orchestrator.find("'dry_run' => false")

    # Match the ACTUAL invocation (`call_user_func( $config['run_callback']`),
    # not the bare token: the token also appears in prose and in the
    # validate_config() key list, which are not calls. Ordering is asserted
    # against real call sites only.
    invocations = [
        m.start()
        for m in re.finditer(
            r"call_user_func\(\s*\$config\['run_callback'\]", orchestrator
        )
    ]
    lock_at = orchestrator.find("Lock::acquire")

    ok(
        "the orchestrator is still the file that invokes a stage run_callback",
        len(invocations) >= 2,
        f"found {len(invocations)} invocation(s)",
    )
    ok(
        "the orchestrator acquires the lock BEFORE every run_callback invocation",
        bool(invocations) and lock_at >= 0 and all(lock_at < i for i in invocations),
        f"lock={lock_at} invocations={invocations}",
    )
    ok("the orchestrator's apply branch is guarded by the digest-bound approval", "Apply_Gate::verify_approval" in orchestrator)
    ok(
        "the approval is verified BEFORE the engine is asked to write",
        0 <= orchestrator.find("Apply_Gate::verify_approval") < write_at,
    )
    ok(
        "the snapshot identity is persisted BEFORE the engine is asked to write",
        0 <= orchestrator.find("Apply_Gate::persist_state") < write_at,
    )
    ok(
        "the environment guard runs before any write",
        0 <= orchestrator.find("Environment::evaluate") < write_at,
    )
    ok(
        "the F7 dry-run verdict is evaluated before any write",
        0 <= orchestrator.find("Apply_Gate::evaluate") < write_at,
    )
    ok(
        "the engine core is byte-identical to the pinned historical baseline",
        ENGINE_DIGEST_OK,
        "engine core SHA-256 moved; the mutation authority must not change",
    )

    # --- 5. FORBIDDEN: entry surfaces, in EVERY file of BOTH plugins ------
    for key, body in sorted(bodies.items()):
        hits = sorted(t for t in FORBIDDEN_SURFACES if t in body)
        ok(f"{key} exposes no forbidden entry surface", not hits, ", ".join(hits))

        hooks = sorted(t for t in WEBHOOK_TOKENS if t in body.lower())
        ok(f"{key} exposes no webhook entry point", not hooks, ", ".join(hooks))

    print()
    print(f"{PASSED} passed, {len(FAILURES)} failed")
    for failure in FAILURES:
        print(f"  FAIL: {failure}")

    return 1 if FAILURES else 0


if __name__ == "__main__":
    sys.exit(main())