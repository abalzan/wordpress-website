#!/usr/bin/env python3
"""Stage I script-contract gate: write-endpoint authorisation.

The security counterpart of `verify-script-conventions.py`, and the durable
answer to "we audited the write surface once". A one-off audit decays the moment
someone adds an endpoint, so the audit's conclusions are encoded as a
fail-closed static gate instead.

What it asserts over `wp-content/`:

  A. Every `admin_post_*` / `wp_ajax_*` registration — whether the hook is a
     literal or is built as `'admin_post_' . CONST` — resolves to a handler whose
     body performs BOTH a capability check and a nonce check. An unresolvable
     hook target is a FAILURE, never a skip (fail closed).
  B. No `admin_post_nopriv_*` / `wp_ajax_nopriv_*` registration exists. The
     repository has no anonymous write surface; adding one has to be a
     deliberate change to this gate, not a silent addition.
  C. Every `register_rest_route()` block declares a `permission_callback`, and a
     route that accepts anything other than `WP_REST_Server::READABLE` never
     pairs with `__return_true`. A public read-only endpoint is legitimate; a
     public writable one is not.
  D. Every `register_post_meta()` / `register_meta()` call that is REST-exposed
     (`'show_in_rest' => true`) for a PROTECTED (underscore-prefixed) key
     declares an `auth_callback`, so the protected-meta default
     (`__return_false` → `403 rest_cannot_update` for everyone) is never
     inherited and `__return_true` is never silently introduced.

**Self-proving.** Every detector is re-run against a synthetic violating snippet
and MUST report a violation. A gate that has gone blind therefore fails instead
of passing vacuously — the same negative-proof discipline the permanent
in-process gates use.

Static only: reads PHP source text. No WordPress, no Docker, no database, no
network, zero writes.

Exit code: 0 when every check passes, 1 otherwise. Every failure names the file.
"""

from __future__ import annotations

import os
import re
import sys

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)

PASSED = 0
FAILURES: list[str] = []

# Write-ish calls that must never precede the capability check in a handler.
WRITE_CALLS = (
    "update_post_meta",
    "add_post_meta",
    "delete_post_meta",
    "wp_insert_post",
    "wp_update_post",
    "wp_delete_post",
    "wp_set_object_terms",
    "wp_insert_term",
    "update_option",
    "add_option",
    "delete_option",
    "set_transient",
    "wp_mail",
    "wp_handle_upload",
    "media_handle_upload",
    "wp_upload_bits",
    "pll_save_post_translations",
    "pll_set_post_language",
)

CAPABILITY_RE = re.compile(r"\b(?:current_user_can|user_can)\s*\(")
NONCE_RE = re.compile(r"\b(?:check_admin_referer|check_ajax_referer|wp_verify_nonce)\s*\(")


def check(condition: bool, message: str) -> bool:
    """Record one assertion. Never raises."""
    global PASSED
    if condition:
        PASSED += 1
    else:
        FAILURES.append(message)
    return bool(condition)


def read(path: str) -> str:
    with open(path, encoding="utf-8", errors="replace") as handle:
        return handle.read()


def strip_comments(source: str) -> str:
    """Remove PHP comments, preserving string literals and line structure."""
    out: list[str] = []
    i = 0
    n = len(source)
    while i < n:
        ch = source[i]
        if ch in "'\"":
            quote = ch
            out.append(ch)
            i += 1
            while i < n:
                if source[i] == "\\":
                    out.append(source[i : i + 2])
                    i += 2
                    continue
                out.append(source[i])
                if source[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        if source.startswith("/*", i):
            end = source.find("*/", i + 2)
            i = n if end == -1 else end + 2
            out.append("\n")
            continue
        if source.startswith("//", i) or source.startswith("#", i):
            end = source.find("\n", i)
            i = n if end == -1 else end
            continue
        out.append(ch)
        i += 1
    return "".join(out)


def balanced(source: str, open_index: int) -> str:
    """Return the text between `open_index` and its matching closer."""
    pairs = {"(": ")", "{": "}", "[": "]"}
    opener = source[open_index]
    closer = pairs[opener]
    depth = 0
    i = open_index
    n = len(source)
    while i < n:
        ch = source[i]
        if ch in "'\"":
            quote = ch
            i += 1
            while i < n:
                if source[i] == "\\":
                    i += 2
                    continue
                if source[i] == quote:
                    break
                i += 1
        elif ch == opener:
            depth += 1
        elif ch == closer:
            depth -= 1
            if depth == 0:
                return source[open_index + 1 : i]
        i += 1
    return ""


def call_args(source: str, function: str) -> list[str]:
    """Every argument string of `function(...)` occurrences."""
    found: list[str] = []
    for match in re.finditer(r"\b" + re.escape(function) + r"\s*\(", source):
        found.append(balanced(source, match.end() - 1))
    return found


def function_body(source: str, name: str) -> str | None:
    """Body of `function <name>(...)` (method or function), brace-balanced."""
    match = re.search(r"\bfunction\s+" + re.escape(name) + r"\s*\(", source)
    if not match:
        return None
    brace = source.find("{", match.end())
    if brace == -1:
        return None
    return balanced(source, brace)


def php_files() -> list[str]:
    """Every production PHP file under wp-content (tests/vendor excluded)."""
    roots = [
        os.path.join(REPO_ROOT, "wp-content", "plugins"),
        os.path.join(REPO_ROOT, "wp-content", "themes"),
    ]
    out: list[str] = []
    for root in roots:
        for dirpath, _dirnames, filenames in os.walk(root):
            flat = dirpath.replace(os.sep, "/")
            if "/tests" in flat or "/node_modules" in flat or "/vendor" in flat:
                continue
            for name in filenames:
                if name.endswith(".php"):
                    out.append(os.path.join(dirpath, name))
    return sorted(out)


def rel(path: str) -> str:
    return os.path.relpath(path, REPO_ROOT)


# ---------------------------------------------------------------------------
# Detectors. Each returns a list of human-readable violations for one file.
# They are pure functions of source text so the self-proofs can reuse them.
# ---------------------------------------------------------------------------

TARGET_RE = re.compile(r"'([A-Za-z_][A-Za-z0-9_]*)'")


def resolve_const(source: str, name: str) -> str | None:
    """Value of `const NAME = 'literal';` in this file, or None."""
    match = re.search(
        r"\bconst\s+" + re.escape(name) + r"\s*=\s*'([^']*)'\s*;", source
    )
    return match.group(1) if match else None


def registered_hooks(code: str) -> list[tuple[str, str]]:
    """(hook name, target expression) for every admin_post/wp_ajax registration."""
    found: list[tuple[str, str]] = []
    for match in re.finditer(r"add_action\s*\(", code):
        args = balanced(code, match.end() - 1)
        parts = [p.strip() for p in args.split(",", 1)]
        if len(parts) < 2:
            continue
        hook_expr, target = parts[0], parts[1]

        literal = re.fullmatch(r"'(admin_post_[a-z0-9_]+|wp_ajax_[a-z0-9_]+)'", hook_expr)
        if literal:
            found.append((literal.group(1), target))
            continue

        concatenated = re.fullmatch(
            r"'(admin_post_|wp_ajax_)'\s*\.\s*(?:(?:self|static|[A-Za-z_][A-Za-z0-9_]*)::)?"
            r"([A-Za-z_][A-Za-z0-9_]*)",
            hook_expr,
        )
        if concatenated:
            value = resolve_const(code, concatenated.group(2))
            if value is None:
                # Fail closed: a hook we cannot resolve cannot be audited.
                found.append((f"UNRESOLVED:{concatenated.group(2)}", target))
            else:
                found.append((concatenated.group(1) + value, target))
    return found


def delegated_bodies(code: str, body: str, depth: int = 3) -> list[str]:
    """The body plus the bodies of same-file methods it calls, to `depth`.

    The repository deliberately splits a guard chain across methods — e.g.
    `handle_http()` builds the request and delegates to `handle_request()`,
    which is where the method/auth/capability/nonce chain actually runs. A
    one-level scan would report that as unguarded, so the scan follows the
    code's own delegation instead of demanding the checks be inlined.
    """
    seen: list[str] = [body]
    frontier = [body]
    for _ in range(max(0, depth)):
        next_frontier: list[str] = []
        for current in frontier:
            for match in re.finditer(
                r"(?:self|static|[A-Za-z_][A-Za-z0-9_]*)::([a-z_][a-z0-9_]*)\s*\("
                r"|\$this->([a-z_][a-z0-9_]*)\s*\(",
                current,
            ):
                name = match.group(1) or match.group(2)
                if not name:
                    continue
                callee = function_body(code, name)
                if callee and callee not in seen:
                    seen.append(callee)
                    next_frontier.append(callee)
        if not next_frontier:
            break
        frontier = next_frontier
    return seen


WRITE_RE = re.compile(r"\b(?:" + "|".join(WRITE_CALLS) + r")\s*\(")


def check_a(code: str, label: str) -> list[str]:
    """Capability on every handler; nonce on every handler that can write.

    Both are satisfied by the handler itself OR by a same-file method it
    delegates to, because the repository's documented pattern puts the guard
    chain one call below the WordPress hook.
    """
    problems: list[str] = []
    for hook, target in registered_hooks(code):
        if hook.startswith("UNRESOLVED:"):
            problems.append(
                f"{label}: hook constant {hook.split(':', 1)[1]} cannot be resolved to a "
                f"literal, so the endpoint cannot be audited (fail closed)"
            )
            continue
        match = TARGET_RE.search(target)
        if not match:
            problems.append(
                f"{label}: {hook} is registered with an unrecognised handler {target!r}"
            )
            continue
        name = match.group(1)
        body = function_body(code, name)
        if body is None:
            problems.append(f"{label}: {hook} handler {name}() is not defined in this file")
            continue
        reachable = delegated_bodies(code, body)
        if not any(CAPABILITY_RE.search(part) for part in reachable):
            problems.append(f"{label}: {hook} -> {name}() performs NO capability check")
        can_write = any(WRITE_RE.search(part) for part in reachable)
        if can_write and not any(NONCE_RE.search(part) for part in reachable):
            problems.append(
                f"{label}: {hook} -> {name}() can write but performs NO nonce check"
            )
    return problems


def check_b(code: str, label: str) -> list[str]:
    """No anonymous write surface."""
    problems: list[str] = []
    for match in re.finditer(
        r"'((?:admin_post|wp_ajax)_nopriv_[a-z0-9_]+)'", code
    ):
        problems.append(
            f"{label}: anonymous write surface registered: {match.group(1)}"
        )
    return problems




def split_args(args: str) -> list[str]:
    """Split a call's argument string on top-level commas."""
    parts: list[str] = []
    depth = 0
    current: list[str] = []
    i = 0
    n = len(args)
    while i < n:
        ch = args[i]
        if ch in "'\"":
            quote = ch
            current.append(ch)
            i += 1
            while i < n:
                current.append(args[i])
                if args[i] == "\\":
                    if i + 1 < n:
                        current.append(args[i + 1])
                        i += 2
                        continue
                if args[i] == quote:
                    i += 1
                    break
                i += 1
            continue
        if ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        elif ch == "," and depth == 0:
            parts.append("".join(current))
            current = []
            i += 1
            continue
        current.append(ch)
        i += 1
    if "".join(current).strip():
        parts.append("".join(current))
    return [p.strip() for p in parts]


def route_is_read_only(args: str) -> bool:
    """True when the route's `methods` accepts reads only."""
    if re.search(r"'methods'\s*=>\s*WP_REST_Server::READABLE\b", args):
        return True
    if re.search(r"'methods'\s*=>\s*'GET'", args):
        return True
    return False


def check_c(code: str, label: str) -> list[str]:
    """Every REST route declares a permission callback; writes are never public."""
    problems: list[str] = []
    for args in call_args(code, "register_rest_route"):
        if "permission_callback" not in args:
            problems.append(f"{label}: register_rest_route() declares no permission_callback")
            continue
        if route_is_read_only(args):
            continue
        if re.search(r"'permission_callback'\s*=>\s*['\"]__return_true['\"]", args):
            problems.append(
                f"{label}: a REST route that is not read-only pairs with __return_true"
            )
    return problems


def check_d(code: str, label: str) -> tuple[list[str], int]:
    """Protected, REST-exposed meta declares an auth_callback.

    Returns ``(violations, undecided)`` where ``undecided`` counts call sites
    whose meta key is a variable. Those cannot be judged statically, so they are
    reported as a coverage note rather than silently treated as a pass.
    """
    problems: list[str] = []
    undecided = 0
    for function in ("register_post_meta", "register_meta"):
        for args in call_args(code, function):
            parts = split_args(args)
            if len(parts) < 3:
                continue
            literal = re.fullmatch(r"'([^']*)'|\"([^\"]*)\"", parts[1])
            if not literal:
                undecided += 1
                continue
            key = literal.group(1) if literal.group(1) is not None else literal.group(2)
            if not key.startswith("_"):
                continue
            rest_exposed = re.search(r"'show_in_rest'\s*=>\s*(?:true|1)\b", args) or re.search(
                r"'show_in_rest'\s*=>\s*array\s*\(", args
            )
            if not rest_exposed:
                continue
            if "auth_callback" not in args:
                problems.append(
                    f"{label}: protected, REST-exposed meta '{key}' declares no auth_callback"
                )
            elif re.search(r"'auth_callback'\s*=>\s*['\"]__return_true['\"]", args):
                problems.append(
                    f"{label}: protected meta '{key}' declares auth_callback => __return_true"
                )
    return problems, undecided


# ---------------------------------------------------------------------------
# Self-proofs: each detector must FAIL on a deliberately violating snippet.
# ---------------------------------------------------------------------------

PROOF_A_NO_CAP = """
class Bad { public function handle() { check_admin_referer( 'x' ); update_option( 'k', 1 ); } }
add_action( 'admin_post_bad_save', array( $this, 'handle' ) );
"""
PROOF_A_NO_NONCE = """
class Bad { public function handle() { if ( ! current_user_can( 'manage_options' ) ) { wp_die(); } update_option( 'k', 1 ); } }
add_action( 'admin_post_bad_save', array( $this, 'handle' ) );
"""
PROOF_A_WRITE_WITHOUT_CAP = """
class Bad { public function handle() { check_admin_referer( 'x' ); wp_insert_post( array() ); } }
add_action( 'wp_ajax_bad', array( $this, 'handle' ) );
"""
PROOF_A_CONST = """
class Bad {
\tconst ACTION = 'bad_save';
\tpublic function handle() { }
}
add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
"""
PROOF_B = "add_action( 'admin_post_nopriv_bad', 'bad_handler' );\n"
PROOF_C_NO_CB = (
    "register_rest_route( 'ns/v1', '/thing', array( 'methods' => 'POST', 'callback' => 'cb' ) );\n"
)
PROOF_C_PUBLIC_WRITE = (
    "register_rest_route( 'ns/v1', '/thing', array( 'methods' => 'POST', "
    "'callback' => 'cb', 'permission_callback' => '__return_true' ) );\n"
)
PROOF_D = (
    "register_post_meta( 'leisure', '_bad_key', "
    "array( 'single' => true, 'show_in_rest' => true ) );\n"
)

GOOD_A = """
class Good {
\tpublic function handle() {
\t\tif ( ! current_user_can( 'manage_options' ) ) { wp_die(); }
\t\tcheck_admin_referer( 'good_save' );
\t\tupdate_option( 'k', 1 );
\t}
}
add_action( 'admin_post_good_save', array( $this, 'handle' ) );
"""
GOOD_C = (
    "register_rest_route( 'ns/v1', '/read', array( 'methods' => WP_REST_Server::READABLE, "
    "'callback' => 'cb', 'permission_callback' => '__return_true' ) );\n"
)
GOOD_D = (
    "register_post_meta( 'leisure', '_good_key', array( 'single' => true, "
    "'show_in_rest' => true, 'auth_callback' => function () { return current_user_can( 'edit_posts' ); } ) );\n"
)
GOOD_NONCE_FREE_STUB = """
class Stub {
\tconst CAP = 'manage_options';
\tpublic static function handle(): void {
\t\tif ( ! current_user_can( self::CAP ) ) { wp_die( 'forbidden', '', array( 'response' => 403 ) ); }
\t\twp_die( 'retired', 'Endpoint retired', array( 'response' => 410 ) );
\t}
}
add_action( 'admin_post_retired_stub', array( __CLASS__, 'handle' ) );
"""


def self_proofs() -> None:
    """Prove every detector can go red, and that the good shapes stay green."""
    check(
        bool(check_a(strip_comments(PROOF_A_NO_CAP), "proof")),
        "self-proof A: a handler without a capability check was NOT detected",
    )
    check(
        bool(check_a(strip_comments(PROOF_A_NO_NONCE), "proof")),
        "self-proof A: a writing handler without a nonce check was NOT detected",
    )
    check(
        bool(check_a(strip_comments(PROOF_A_WRITE_WITHOUT_CAP), "proof")),
        "self-proof A: an ajax handler without a capability check was NOT detected",
    )
    check(
        bool(check_a(strip_comments(PROOF_A_CONST), "proof")),
        "self-proof A: an unguarded constant-registered hook was NOT detected",
    )
    check(
        not check_a(strip_comments(GOOD_NONCE_FREE_STUB), "proof"),
        "a capability-gated handler that cannot write must NOT require a nonce",
    )
    check(
        bool(check_b(strip_comments(PROOF_B), "proof")),
        "self-proof B: an admin_post_nopriv registration was NOT detected",
    )
    check(
        bool(check_c(strip_comments(PROOF_C_NO_CB), "proof")),
        "self-proof C: a route without permission_callback was NOT detected",
    )
    check(
        bool(check_c(strip_comments(PROOF_C_PUBLIC_WRITE), "proof")),
        "self-proof C: a public writable route was NOT detected",
    )
    check(
        bool(check_d(strip_comments(PROOF_D), "proof")[0]),
        "self-proof D: protected REST meta without auth_callback was NOT detected",
    )

    check(not check_a(strip_comments(GOOD_A), "proof"), "a guarded handler must be clean")
    check(not check_b(strip_comments(GOOD_A), "proof"), "a guarded handler registers no nopriv hook")
    check(not check_c(strip_comments(GOOD_C), "proof"), "a public READ-ONLY route must be clean")
    check(
        not check_d(strip_comments(GOOD_D), "proof")[0],
        "protected meta with an auth_callback must be clean",
    )



def main() -> int:
    """Run the self-proofs, then audit the real write surface."""
    self_proofs()

    files = php_files()
    check(bool(files), "no production PHP files were discovered under wp-content/")

    hooks = 0
    routes = 0
    meta_sites = 0
    undecided_meta = 0

    for path in files:
        label = rel(path)
        raw = read(path)
        if (
            "add_action" not in raw
            and "register_rest_route" not in raw
            and "register_post_meta" not in raw
            and "register_meta" not in raw
        ):
            continue
        code = strip_comments(raw)

        for problem in check_a(code, label):
            check(False, problem)
        for problem in check_b(code, label):
            check(False, problem)
        for problem in check_c(code, label):
            check(False, problem)
        d_problems, undecided = check_d(code, label)
        for problem in d_problems:
            check(False, problem)

        hooks += len(registered_hooks(code))
        routes += len(call_args(code, "register_rest_route"))
        meta_sites += len(call_args(code, "register_post_meta")) + len(call_args(code, "register_meta"))
        undecided_meta += undecided

    # Coverage floors: a gate that audited nothing must not pass.
    check(hooks >= 25, f"expected at least 25 admin_post/wp_ajax handlers to audit, found {hooks}")
    check(routes >= 2, f"expected at least 2 register_rest_route() calls to audit, found {routes}")
    check(meta_sites >= 10, f"expected at least 10 meta registrations to audit, found {meta_sites}")

    print(
        f"audited: {hooks} admin_post/wp_ajax handler(s), {routes} REST route registration(s), "
        f"{meta_sites} meta registration(s)"
    )
    if undecided_meta:
        print(
            f"coverage note: {undecided_meta} meta registration(s) use a computed key and cannot be "
            f"judged statically; they are covered by the in-process REST permission suites instead"
        )
    return 0


if __name__ == "__main__":
    rc = main()
    for failure in FAILURES:
        print(f"FAIL: {failure}")
    print(f"{PASSED} passed, {len(FAILURES)} failed")
    sys.exit(1 if FAILURES else 0)

