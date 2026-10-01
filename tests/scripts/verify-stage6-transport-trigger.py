#!/usr/bin/env python3
"""Stage 6 structural gate: the transport contract and the protected trigger.

Static only. No WordPress, no network, no production. Complements the in-process
PHP suites by asserting facts about the SOURCE that are impossible to observe
from behaviour alone -- in particular, that the shared engine's bytes have not
moved, that the real WordPress HTTP response shape is the one the transport
normaliser reads, and that the one production entry point cannot reach apply.

It deliberately does NOT duplicate the PHP suites' behavioural assertions. Its
job is to fail if a future edit quietly reintroduces a bypass.
"""

from __future__ import annotations

import hashlib
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

# Pinned by the Stage 1/Stage 2 integrity invariant and re-asserted by Stages
# 3, 4 and 6. The engine is NOT modified by Stage 6.
ENGINE_SHA256 = "baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4"

ENTRY = PLUGIN_DIR / "includes" / "class-conexao-translation-automation-admin-trigger.php"
PROVIDER = PLUGIN_DIR / "includes" / "class-conexao-translation-automation-provider-openai.php"
CONFIG = PLUGIN_DIR / "includes" / "class-conexao-translation-automation-provider-config.php"

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


def read(path: pathlib.Path) -> str:
    return strip_php_comments(path.read_text(encoding="utf-8"))


def main() -> int:
    entry = read(ENTRY)
    provider = read(PROVIDER)
    config = read(CONFIG)

    # -- 1. The shared engine has not moved. ---------------------------------
    ok(
        "the shared engine SHA-256 is unchanged",
        hashlib.sha256(ENGINE.read_bytes()).hexdigest() == ENGINE_SHA256,
    )

    # -- 2. The transport reads the REAL WordPress contract. ----------------
    # This is the Stage 5 defect, asserted structurally: the status lives at
    # `response.code`, and a top-level `code` is the shape that caused it.
    #
    # The check is scoped to `normalise()` itself. Elsewhere the provider
    # legitimately reads `$response['code']` — but that is the ALREADY
    # NORMALISED `array( 'code' => int, 'body' => string )` this method
    # returns, which is a different value from the raw transport result.
    normalise = provider.split("private static function normalise(")[1].split("private static function is_quota_exhausted(")[0]
    ok("the transport normaliser reads response.code", "$envelope['code']" in normalise)
    ok(
        "the transport normaliser never reads a top-level code from the transport result",
        "$response['code']" not in normalise,
    )
    ok("a missing response envelope is refused", "conexao_automation_provider_missing_response" in provider)
    ok("a missing HTTP status is refused", "conexao_automation_provider_missing_status" in provider)
    ok("a missing body is refused", "conexao_automation_provider_missing_body" in provider)
    ok("a non-numeric status is refused rather than cast", "is_numeric( $envelope['code'] )" in provider)

    # Quota is classified apart from a transient rate limit, and is
    # non-retryable: the quota check runs BEFORE the status classification.
    ok("insufficient_quota has its own failure category", "conexao_automation_provider_insufficient_quota" in provider)
    ok("insufficient_quota is read from the vendor error body", "'insufficient_quota' === (string) $error[ $key ]" in provider)
    quota_pos = provider.find("is_quota_exhausted(")
    classify_pos = provider.find("classify_status(")
    ok(
        "the quota check runs BEFORE the status classification, so quota is never retried",
        quota_pos != -1 and classify_pos != -1 and quota_pos < classify_pos,
        f"quota={quota_pos} classify={classify_pos}",
    )

    # -- 3. Exactly one admin surface, in exactly one file. -----------------
    surfaces = [
        f.name
        for f in sorted((PLUGIN_DIR / "includes").glob("*.php"))
        if re.search(r"\badmin_post_|\badmin_menu", read(f))
    ]
    ok(
        "exactly one file owns the admin surface",
        surfaces == ["class-conexao-translation-automation-admin-trigger.php"],
        ", ".join(surfaces),
    )

    # -- 4. The entry point is POST-only. ----------------------------------
    ok("the entry point defines a wrong-method failure", "FAILURE_WRONG_METHOD" in entry)
    ok("the entry point requires the method to be POST", "'POST' === $method" in entry)
    ok("the entry point never reads $_GET", "$_GET" not in entry)
    ok(
        "the entry point reads $_POST only inside its single helper",
        entry.count("$_POST") == 2,
        str(entry.count("$_POST")),
    )

    # -- 5. The chain: POST -> auth -> capability -> nonce -> validate. ----
    for label, token in (
        ("authentication is checked", "is_authenticated()"),
        ("the capability is manage_options", "const CAPABILITY = 'manage_options'"),
        ("the nonce is verified", "verify_nonce()"),
    ):
        ok(f"the entry point {label}", token in entry)

    chain = [
        entry.find("if ( ! self::is_post() )"),
        entry.find("if ( ! self::is_authenticated() )"),
        entry.find("if ( ! self::has_capability() )"),
        entry.find("if ( ! self::verify_nonce() )"),
        entry.find("self::validate_request( $request )"),
        entry.find("self::invoke( $request )"),
    ]
    ok(
        "the chain runs POST -> auth -> capability -> nonce -> validate -> invoke, in order",
        all(pos != -1 for pos in chain) and chain == sorted(chain),
        str(chain),
    )

    # -- 6. No unauthenticated surface, and no cron. -----------------------
    for token in (
        "admin_post_nopriv_",
        "wp_ajax_",
        "rest_api_init",
        "register_rest_route",
        "__return_true",
        "wp_schedule_event",
        "wp_schedule_single_event",
    ):
        ok(f"the entry point contains no {token}", token not in entry)

    # -- 7. No translation logic, no provider call, no direct mutation. ----
    for token in (
        "wp_insert_post",
        "wp_update_post",
        "update_post_meta",
        "pll_",
        "Conexao_Translation_Automation_Provider_OpenAI",
        "Conexao_Translation_Rollout_Engine",
        "Conexao_Translation_Automation_Translation_Plan",
    ):
        ok(f"the entry point never calls {token}", token not in entry)

    # -- 8. One orchestration path: the EXISTING trigger. -----------------
    ok("the entry point invokes the existing trigger", "Conexao_Translation_Automation_Trigger::fire(" in entry)
    ok("the entry point fires the trigger exactly once", entry.count("::fire(") == 1, str(entry.count("::fire(")))
    ok("the entry point can never adopt a baseline from a request", "'bootstrap'   => false" in entry)

    # -- 9. APPLY IS UNREACHABLE. ------------------------------------------
    # The mode vocabulary has ONE member and apply is not it. A deny-list
    # would only be a weaker restatement of the same property.
    ok(
        "the mode vocabulary is a single proof value",
        "return array( Conexao_Translation_Automation_Orchestrator::MODE_PROOF );" in entry,
    )
    ok("apply is never named as a callable mode", "MODE_APPLY" not in entry)
    ok("no request field can carry a dry_run flag", "'dry_run'" not in entry)
    ok("no request field can carry an environment", "'environment' => array" not in entry)
    ok(
        "the environment is derived server-side",
        "wp_get_environment_type()" in entry,
    )

    # -- 10. The result boundary is safe and the flags are hard-coded. ----
    ok("the result hard-codes mutation_permitted false", "'mutation_permitted' => false," in entry)
    ok("the result hard-codes mutation_occurred false", "'mutation_occurred'  => false," in entry)
    ok("the result boundary runs assert_no_secrets", "assert_no_secrets( $result )" in entry)

    # -- 11. The credential is environment-only, never stored in WordPress.
    ok("the provider credential is read from the environment", "getenv(" in config)
    ok(
        "the provider credential is never written to an option",
        not re.search(r"(add|update)_option\s*\(", config),
    )
    # The wake-up marker, the lock, the audit trail and the persisted source
    # state are the FOUR legitimate option writers this plugin owns. The
    # invariant is that the set of writers did not GROW to include anything
    # credential-shaped — asserted in the boundary suite, and here re-asserted
    # as "no file writes a credential-named option".
    ok(
        "no plugin file writes a credential-named option",
        not any(
            re.search(r"(add|update)_option\s*\([^)]*(api_key|apikey|secret|credential|token|provider_key)", read(f))
            for f in sorted((PLUGIN_DIR / "includes").glob("*.php"))
        ),
    )
    ok(
        "the credential env var is named, never its value",
        "CONEXAO_TRANSLATION_PROVIDER_KEY" in config,
    )

    print()
    print(f"{PASSED} passed, {len(FAILURES)} failed")
    for failure in FAILURES:
        print(f"  FAIL: {failure}")
    return 1 if FAILURES else 0


if __name__ == "__main__":
    sys.exit(main())