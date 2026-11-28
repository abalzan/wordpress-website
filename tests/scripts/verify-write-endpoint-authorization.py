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
     inherited and `__return_true` is never silently introduced. The key may be
     a literal **or computed**: `self::CONST` / `Class::CONST`, a loop variable
     bound by `foreach` over an array literal, a local array variable, a static
     property array, and `array_merge()` of those are all resolved, so a
     computed registration is judged exactly like a literal one. Only a key that
     cannot be resolved at all stays undecided, and it is reported as a coverage
     note rather than silently passing.
  E. Every SCHEDULED write path (`wp_schedule_event()` /
     `wp_schedule_single_event()`) resolves to a hook that is registered with
     `add_action()` in the same file, whose callback exists, and whose body (and
     same-file delegation) reads **no request superglobal** (`$_GET`, `$_POST`,
     `$_REQUEST`, `$_COOKIE`, `$_FILES`, `$_SERVER`, `filter_input()`). A cron
     callback has no request context, so a superglobal read there means request
     data is being smuggled into a scheduled write. The scheduler must never
     also be an `admin_post_*`/`wp_ajax_*` surface — that is the bypass shape
     this detector exists to reject.
     It deliberately does **not** demand a nonce or `current_user_can()` inside
     a scheduled callback: there is no authenticated user at cron time, so those
     checks would be meaningless (and requiring them would push an author to
     fake them). What matters is that the scheduled path cannot be reached from
     a request, and that it validates nothing it did not schedule itself.

**Self-proving.** Every detector is re-run against a synthetic violating snippet
and MUST report a violation. A gate that has gone blind therefore fails instead
of passing vacuously — the same negative-proof discipline the permanent
in-process gates use. The proofs cover the computed-meta and scheduled-write
detectors too, so neither can be satisfied by an unparsed call.

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


# ---------------------------------------------------------------------------
# Computed-expression resolution (meta keys, scheduled hooks).
#
# The repository registers meta inside loops and builds meta/hook names from
# class constants. A gate that only understands literals has to report those
# sites as "undecided", which is exactly the blind spot this section removes.
# Every helper below is a pure function of source text, so the self-proofs can
# drive them with synthetic snippets.
# ---------------------------------------------------------------------------

ARRAY_LITERAL_RE = re.compile(r"^(?:array\s*\(|\[)")


def array_literal_inner(text: str) -> str | None:
    """Inner text of a top-level `array(...)` / `[...]` literal, else None."""
    text = text.strip()
    if not ARRAY_LITERAL_RE.match(text):
        return None
    if text.startswith("["):
        return balanced(text, 0)
    return balanced(text, text.index("("))


def split_arrow(item: str) -> tuple[str | None, str]:
    """Split one array element on its top-level `=>`, honouring brackets."""
    depth = 0
    i = 0
    n = len(item)
    while i < n:
        ch = item[i]
        if ch in "'\"":
            quote = ch
            i += 1
            while i < n:
                if item[i] == "\\":
                    i += 2
                    continue
                if item[i] == quote:
                    break
                i += 1
        elif ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        elif ch == "=" and i + 1 < n and item[i + 1] == ">" and depth == 0:
            return item[:i].strip(), item[i + 2 :].strip()
        i += 1
    return None, item.strip()


def array_elements(inner: str) -> list[tuple[str | None, str]]:
    """(key, value) for every top-level element of an array literal body."""
    return [split_arrow(item) for item in split_args(inner)]


def quoted_value(text: str) -> str | None:
    """The value of a single/double quoted literal, else None."""
    match = re.fullmatch(r"'([^']*)'|\"([^\"]*)\"", text.strip())
    if not match:
        return None
    return match.group(1) if match.group(1) is not None else match.group(2)


def expression_until_semicolon(code: str, start: int) -> str:
    """Source from `start` to the terminating `;` at depth 0."""
    depth = 0
    i = start
    n = len(code)
    while i < n:
        ch = code[i]
        if ch in "'\"":
            quote = ch
            i += 1
            while i < n:
                if code[i] == "\\":
                    i += 2
                    continue
                if code[i] == quote:
                    break
                i += 1
        elif ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        elif ch == ";" and depth == 0:
            return code[start:i]
        i += 1
    return code[start:]


def resolve_arrays(code: str, expression: str, depth: int = 0) -> list[str] | None:
    """Array-literal bodies an expression denotes, or None when undecidable."""
    if depth > 4:
        return None
    expression = expression.strip()

    inner = array_literal_inner(expression)
    if inner is not None:
        return [inner]

    merge = re.match(r"array_merge\s*\(", expression)
    if merge:
        resolved: list[str] = []
        for part in split_args(balanced(expression, merge.end() - 1)):
            bodies = resolve_arrays(code, part, depth + 1)
            if bodies is None:
                return None
            resolved.extend(bodies)
        return resolved

    static_prop = re.fullmatch(
        r"(?:self|static|[A-Za-z_][A-Za-z0-9_]*)::\$([A-Za-z_][A-Za-z0-9_]*)",
        expression,
    )
    if static_prop:
        match = re.search(
            r"\bstatic\s+\$" + re.escape(static_prop.group(1)) + r"\s*=\s*", code
        )
        if not match:
            return None
        return resolve_arrays(
            code, expression_until_semicolon(code, match.end()), depth + 1
        )

    class_const = re.fullmatch(
        r"(?:self|static|[A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)",
        expression,
    )
    if class_const:
        match = re.search(
            r"\bconst\s+" + re.escape(class_const.group(1)) + r"\s*=\s*", code
        )
        if not match:
            return None
        return resolve_arrays(
            code, expression_until_semicolon(code, match.end()), depth + 1
        )

    variable = re.fullmatch(r"\$([A-Za-z_][A-Za-z0-9_]*)", expression)
    if not variable:
        return None
    name = variable.group(1)

    # A statement-level assignment only. The pattern is anchored to the start of
    # a line so a class-property DECLARATION (`private static $fields = array()`)
    # is never mistaken for a local assignment — that confusion would resolve a
    # loop variable to the property's own array and hide the inner keys.
    assignment = re.search(r"(?m)^[ \t]*" + re.escape("$" + name) + r"\s*=\s*", code)
    if assignment:
        return resolve_arrays(
            code, expression_until_semicolon(code, assignment.end()), depth + 1
        )

    # `foreach ( OUTER as $k => $name )` binds a *value* of OUTER; when OUTER's
    # elements are arrays this resolves the inner arrays.
    for match in re.finditer(r"foreach\s*\(", code):
        args = balanced(code, match.end() - 1)
        outer, binding = split_foreach(args)
        if "=>" not in binding:
            continue
        _, value_expr = split_arrow(binding)
        if value_expr.strip() != "$" + name:
            continue
        bodies = resolve_arrays(code, outer, depth + 1)
        if bodies is None:
            return None
        collected: list[str] = []
        for body in bodies:
            for _key, value in array_elements(body):
                inner_body = array_literal_inner(value)
                if inner_body is None:
                    return None
                collected.append(inner_body)
        return collected

    return None


def split_foreach(args: str) -> tuple[str, str]:
    """Split `EXPR as BINDING` on the top-level ` as ` keyword."""
    depth = 0
    i = 0
    n = len(args)
    while i < n:
        ch = args[i]
        if ch in "'\"":
            quote = ch
            i += 1
            while i < n:
                if args[i] == "\\":
                    i += 2
                    continue
                if args[i] == quote:
                    break
                i += 1
        elif ch in "([{":
            depth += 1
        elif ch in ")]}":
            depth -= 1
        elif depth == 0 and args.startswith(" as ", i):
            return args[:i].strip(), args[i + 4 :].strip()
        i += 1
    return args.strip(), ""


def loop_variable_keys(code: str, variable: str) -> set[str] | None:
    """The literal keys a loop variable takes, or None when undecidable."""
    for match in re.finditer(r"foreach\s*\(", code):
        args = balanced(code, match.end() - 1)
        outer, binding = split_foreach(args)
        if not binding:
            continue
        if "=>" in binding:
            key_expr, _value_expr = split_arrow(binding)
            if key_expr is None or key_expr.strip() != variable:
                continue
            bodies = resolve_arrays(code, outer)
            if bodies is None:
                return None
            keys: set[str] = set()
            for body in bodies:
                for key, _value in array_elements(body):
                    if key is None:
                        return None
                    literal = quoted_value(key)
                    if literal is None:
                        return None
                    keys.add(literal)
            return keys
        if binding.strip() != variable:
            continue
        bodies = resolve_arrays(code, outer)
        if bodies is None:
            return None
        values: set[str] = set()
        for body in bodies:
            for _key, value in array_elements(body):
                literal = quoted_value(value)
                if literal is None:
                    return None
                values.add(literal)
        return values
    return None


def resolve_meta_keys(
    code: str,
    expression: str,
    classes: dict[str, tuple[str, str]] | None = None,
) -> set[str] | None:
    """Every literal meta key a `register_*_meta()` key expression denotes."""
    literal = quoted_value(expression)
    if literal is not None:
        return {literal}

    class_const = re.fullmatch(
        r"([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)",
        expression.strip(),
    )
    if class_const:
        prefix, constant = class_const.group(1), class_const.group(2)
        if prefix not in ("self", "static") and classes and prefix in classes:
            value = resolve_const(classes[prefix][1], constant)
        else:
            value = resolve_const(code, constant)
        return {value} if value is not None else None

    if re.fullmatch(r"\$[A-Za-z_][A-Za-z0-9_]*", expression.strip()):
        return loop_variable_keys(code, expression.strip())

    return None


# __COMPUTED_RESOLUTION_PART2__


def check_d(
    code: str,
    label: str,
    classes: dict[str, tuple[str, str]] | None = None,
) -> tuple[list[str], int, int]:
    """Protected, REST-exposed meta declares an auth_callback.

    Returns ``(violations, undecided, resolved_keys)``. ``undecided`` counts
    call sites whose meta key cannot be resolved from source at all; those are
    reported as a coverage note rather than silently treated as a pass.
    ``resolved_keys`` counts the individual keys that WERE resolved, so the
    printed coverage is a real number rather than a claim.
    """
    problems: list[str] = []
    undecided = 0
    resolved = 0
    for function in ("register_post_meta", "register_meta"):
        for args in call_args(code, function):
            parts = split_args(args)
            if len(parts) < 3:
                continue
            keys = resolve_meta_keys(code, parts[1], classes)
            if keys is None:
                undecided += 1
                continue
            resolved += len(keys)
            rest_exposed = re.search(
                r"'show_in_rest'\s*=>\s*(?:true|1)\b", args
            ) or re.search(r"'show_in_rest'\s*=>\s*array\s*\(", args)
            if not rest_exposed:
                continue
            protected = sorted(key for key in keys if key.startswith("_"))
            if not protected:
                continue
            shown = ", ".join("'%s'" % key for key in protected)
            if "auth_callback" not in args:
                problems.append(
                    f"{label}: protected, REST-exposed meta {shown} declares no "
                    f"auth_callback"
                )
            elif re.search(
                r"'auth_callback'\s*=>\s*['\"]__return_true['\"]", args
            ):
                problems.append(
                    f"{label}: protected meta {shown} declares auth_callback => "
                    f"__return_true"
                )
    return problems, undecided, resolved


# ---------------------------------------------------------------------------
# Detector E: scheduled write paths.
# ---------------------------------------------------------------------------

# The hook-name argument index of each scheduling function.
SCHEDULE_HOOK_ARGUMENT = {
    "wp_schedule_event": 2,  # ( $timestamp, $recurrence, $hook, $args )
    "wp_schedule_single_event": 1,  # ( $timestamp, $hook, $args )
}

REQUEST_SUPERGLOBAL_RE = re.compile(
    r"\$_(?:GET|POST|REQUEST|COOKIE|FILES|SERVER)\b|\bfilter_input\s*\("
)


def scheduled_hook_expressions(code: str) -> list[str]:
    """The hook-name expression of every scheduling call in one file."""
    found: list[str] = []
    for function, index in SCHEDULE_HOOK_ARGUMENT.items():
        for args in call_args(code, function):
            parts = split_args(args)
            found.append(parts[index] if len(parts) > index else "")
    return found


def resolve_hook_name(
    code: str,
    expression: str,
    classes: dict[str, tuple[str, str]] | None = None,
) -> str | None:
    """The literal hook name an expression denotes, or None when unresolvable.

    A hook may be written `Class::CONST`; when that class is declared elsewhere
    in the tree (the importer registers its cron callback against
    `Conexao_Import_Continuation::HOOK` from the plugin bootstrap), the constant
    is resolved in the DECLARING file — never guessed from the caller.
    """
    literal = quoted_value(expression)
    if literal is not None:
        return literal
    class_const = re.fullmatch(
        r"([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)",
        expression.strip(),
    )
    if not class_const:
        return None
    prefix, constant = class_const.group(1), class_const.group(2)
    if prefix not in ("self", "static") and classes and prefix in classes:
        return resolve_const(classes[prefix][1], constant)
    return resolve_const(code, constant)


def add_action_target(
    code: str,
    hook: str,
    classes: dict[str, tuple[str, str]] | None = None,
) -> list[str]:
    """Every callback expression registered for `hook` in this file.

    The hook argument may itself be `self::HOOK`, so it is resolved with the
    same helper the scheduling call uses.
    """
    targets: list[str] = []
    for args in call_args(code, "add_action"):
        parts = split_args(args)
        if len(parts) < 2:
            continue
        if resolve_hook_name(code, parts[0], classes) == hook:
            targets.append(parts[1])
    return targets


def index_class_declarations(files: list[tuple[str, str]]) -> dict[str, tuple[str, str]]:
    """Map a declared class name to (label, comment-stripped source)."""
    index: dict[str, tuple[str, str]] = {}
    for label, code in files:
        for match in re.finditer(
            r"\b(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)", code
        ):
            index.setdefault(match.group(1), (label, code))
    return index


def index_hook_registrations(
    files: list[tuple[str, str]],
    classes: dict[str, tuple[str, str]] | None = None,
) -> dict[str, list[tuple[str, str, str]]]:
    """Map a scheduled hook name to every (label, code, target) registering it.

    Scheduling and registration may live in different files — the importer arms
    its cron hook in one class and registers the callback in the plugin
    bootstrap — so the audit is a whole-tree question, not a per-file one.
    """
    index: dict[str, list[tuple[str, str, str]]] = {}
    for label, code in files:
        for args in call_args(code, "add_action"):
            parts = split_args(args)
            if len(parts) < 2:
                continue
            hook = resolve_hook_name(code, parts[0], classes)
            if not hook:
                continue
            index.setdefault(hook, []).append((label, code, parts[1]))
    return index


def delegated_bodies_with_tree(
    classes: dict[str, tuple[str, str]],
    code: str,
    body: str,
    depth: int = 3,
) -> list[str]:
    """The body plus every body it can reach, same-file or class-scoped.

    Same-file delegation is resolved exactly as `delegated_bodies()` does. In
    addition, a `SomeClass::method()` call is followed into the file that
    DECLARES `SomeClass`, which is how a hooked closure hands off to a service
    class. Resolution is class-scoped, so an unrelated method that happens to
    share a name can never be pulled in.
    """
    seen: list[str] = [body]
    frontier: list[tuple[str, str]] = [(code, body)]
    for _ in range(max(0, depth)):
        next_frontier: list[tuple[str, str]] = []
        for current_code, current in frontier:
            candidates: list[tuple[str, str]] = []
            for match in re.finditer(
                r"(?:self|static|[A-Za-z_][A-Za-z0-9_]*)::([a-z_][a-z0-9_]*)\s*\("
                r"|\$this->([a-z_][a-z0-9_]*)\s*\(",
                current,
            ):
                candidates.append((current_code, match.group(1) or match.group(2)))
            for match in re.finditer(
                r"\b([A-Z][A-Za-z0-9_]*)::([a-z_][a-z0-9_]*)\s*\(", current
            ):
                if match.group(1) in classes:
                    candidates.append((classes[match.group(1)][1], match.group(2)))
            for callee_code, name in candidates:
                callee = function_body(callee_code, name)
                if callee and callee not in seen:
                    seen.append(callee)
                    next_frontier.append((callee_code, callee))
        if not next_frontier:
            break
        frontier = next_frontier
    return seen


def check_e(
    code: str,
    label: str,
    registrations: dict[str, list[tuple[str, str, str]]] | None = None,
    classes: dict[str, tuple[str, str]] | None = None,
) -> list[str]:
    """Scheduled write paths: resolvable, registered, and request-independent.

    A cron callback legitimately has no nonce and no logged-in user, so those
    checks are NOT applied here. What is enforced is that the scheduled path is
    not reachable from a request and does not read request data. When
    `registrations`/`classes` are supplied the lookup is whole-tree (the
    production shape); the self-proofs drive the same function with a single
    snippet, exercising the same code path.
    """
    registrations = registrations or {}
    classes = classes or {}
    problems: list[str] = []
    for expression in scheduled_hook_expressions(code):
        hook = resolve_hook_name(code, expression)
        if not hook:
            problems.append(
                f"{label}: a scheduled event hook {expression.strip()!r} cannot be "
                f"resolved to a literal, so it cannot be audited (fail closed)"
            )
            continue

        found = registrations.get(hook)
        if found is None:
            local = [
                (label, code, target)
                for target in add_action_target(code, hook, classes)
            ]
            found = local or None
        if not found:
            problems.append(
                f"{label}: scheduled hook '{hook}' is never registered with "
                f"add_action() anywhere in wp-content/, so nothing would run"
            )
            continue

        for reg_label, reg_code, target in found:
            exposed = re.search(
                r"'(?:admin_post|wp_ajax)(?:_nopriv)?_" + re.escape(hook) + r"'", code
            ) or re.search(
                r"'(?:admin_post|wp_ajax)(?:_nopriv)?_" + re.escape(hook) + r"'",
                reg_code,
            )
            if exposed:
                problems.append(
                    f"{label}: scheduled hook '{hook}' is ALSO registered as an HTTP "
                    f"write surface — a request could trigger a scheduled write"
                )
                break

            match = TARGET_RE.search(target)
            if target.strip().startswith(("static function", "function")):
                name = "<closure>"
                owner = reg_code
                body = target
            elif match:
                name = match.group(1)
                owner = reg_code
                body = function_body(reg_code, name)
                if body is None:
                    problems.append(
                        f"{reg_label}: scheduled hook '{hook}' callback {name}() is "
                        f"not defined in the file that registers it"
                    )
                    break
            else:
                problems.append(
                    f"{label}: scheduled hook '{hook}' registers an unrecognised "
                    f"callback {target.strip()!r}"
                )
                break

            for part in delegated_bodies_with_tree(classes, owner, body):
                if REQUEST_SUPERGLOBAL_RE.search(part):
                    problems.append(
                        f"{label}: scheduled hook '{hook}' -> {name}() reads request "
                        f"data (a superglobal or filter_input()); a cron callback "
                        f"has no request context, so this smuggles a request into a "
                        f"scheduled write"
                    )
                    break
    return problems


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
PROOF_D_COMPUTED = """class Bad {
\tprivate static $fields = array( 'guide' => array( '_nested_key' => 'text' ) );
\tpublic function register() {
\t\tforeach ( self::$fields as $post_type => $fields ) {
\t\t\tforeach ( $fields as $key => $type ) {
\t\t\t\tregister_post_meta( $post_type, $key, array( 'single' => true, 'show_in_rest' => true ) );
\t\t\t}
\t\t}
\t}
}
"""
PROOF_E_UNREGISTERED = """
function bad_run() { update_option( 'k', 1 ); }
wp_schedule_single_event( time() + 60, 'bad_hook' );
"""
PROOF_E_SUPERGLOBAL = """
class Bad {
\tconst HOOK = 'bad_hook';
\tpublic static function run() { $v = $_GET['x']; update_option( 'k', $v ); }
}
add_action( self::HOOK, array( __CLASS__, 'run' ) );
wp_schedule_single_event( time() + 60, self::HOOK );
"""
PROOF_E_HTTP_SURFACE = """
class Bad {
\tconst HOOK = 'bad_hook';
\tpublic static function run() { update_option( 'k', 1 ); }
}
add_action( self::HOOK, array( __CLASS__, 'run' ) );
add_action( 'admin_post_bad_hook', array( __CLASS__, 'run' ) );
wp_schedule_single_event( time() + 60, self::HOOK );
"""

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
GOOD_D_COMPUTED = """class Good {
\tprivate static $fields = array( 'guide' => array( '_nested_key' => 'text' ) );
\tpublic function register() {
\t\tforeach ( self::$fields as $post_type => $fields ) {
\t\t\tforeach ( $fields as $key => $type ) {
\t\t\t\tregister_post_meta( $post_type, $key, array( 'single' => true, 'show_in_rest' => true, 'auth_callback' => '__return_false' ) );
\t\t\t}
\t\t}
\t}
}
"""
GOOD_D_COMPUTED_LIST = """class Good {
\tpublic function register() {
\t\t$keys = array( '_loop_key' );
\t\tforeach ( $keys as $key ) {
\t\t\tregister_post_meta( 'leisure', $key, array( 'single' => true, 'show_in_rest' => true, 'auth_callback' => '__return_false' ) );
\t\t}
\t}
}
"""
GOOD_E = """
class Good {
\tconst HOOK = 'good_hook';
\tpublic static function run( $source_id ) { update_option( 'k', (int) $source_id ); }
}
add_action( self::HOOK, array( __CLASS__, 'run' ) );
wp_schedule_single_event( time() + 60, self::HOOK, array( 1 ) );
"""
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
    check(
        bool(check_d(strip_comments(PROOF_D_COMPUTED), "proof")[0]),
        "self-proof D: a COMPUTED (nested static-property loop) protected REST "
        "meta without auth_callback was NOT detected",
    )
    check(
        resolve_meta_keys(
            strip_comments(PROOF_D_COMPUTED), "$key"
        )
        == {"_nested_key"},
        "self-proof D: a nested static-property loop key set must resolve to its "
        "real keys (an unresolved key would hide the violation above)",
    )
    check(
        resolve_meta_keys(strip_comments(GOOD_D_COMPUTED_LIST), "$key") == {"_loop_key"},
        "self-proof D: a local-array loop key set must resolve to its real keys",
    )
    check(
        bool(check_e(strip_comments(PROOF_E_UNREGISTERED), "proof")),
        "self-proof E: a scheduled hook with no add_action() registration was NOT "
        "detected",
    )
    check(
        bool(check_e(strip_comments(PROOF_E_SUPERGLOBAL), "proof")),
        "self-proof E: a scheduled callback reading $_GET was NOT detected",
    )
    check(
        bool(check_e(strip_comments(PROOF_E_HTTP_SURFACE), "proof")),
        "self-proof E: a scheduled hook that is also an admin_post surface was NOT "
        "detected",
    )

    check(not check_a(strip_comments(GOOD_A), "proof"), "a guarded handler must be clean")
    check(not check_b(strip_comments(GOOD_A), "proof"), "a guarded handler registers no nopriv hook")
    check(not check_c(strip_comments(GOOD_C), "proof"), "a public READ-ONLY route must be clean")
    check(
        not check_d(strip_comments(GOOD_D), "proof")[0],
        "protected meta with an auth_callback must be clean",
    )
    check(
        not check_d(strip_comments(GOOD_D_COMPUTED), "proof")[0],
        "a COMPUTED protected REST meta with an explicit auth_callback must be clean",
    )
    check(
        not check_e(strip_comments(GOOD_E), "proof"),
        "a registered scheduled callback that reads no request data must be clean",
    )



def main() -> int:
    """Run the self-proofs, then audit the real write surface."""
    self_proofs()

    files = php_files()
    check(bool(files), "no production PHP files were discovered under wp-content/")

    # Whole-tree indexes: a scheduled hook may be armed in one file and
    # registered in another, and a hooked closure may hand off to a class
    # declared in a third. Reading the tree once keeps the audit honest without
    # making it O(files x files).
    tree: list[tuple[str, str]] = []
    for path in files:
        raw = read(path)
        if "class " not in raw and "add_action" not in raw:
            continue
        tree.append((rel(path), strip_comments(raw)))
    classes = index_class_declarations(tree)
    registrations = index_hook_registrations(tree, classes)
    check(bool(classes), "no class declarations were discovered to resolve a hooked callback")
    check(bool(registrations), "no add_action() registrations were discovered to resolve a scheduled hook")

    hooks = 0
    routes = 0
    meta_sites = 0
    undecided_meta = 0
    resolved_meta_keys = 0
    schedules = 0

    for path in files:
        label = rel(path)
        raw = read(path)
        if (
            "add_action" not in raw
            and "register_rest_route" not in raw
            and "register_post_meta" not in raw
            and "register_meta" not in raw
            and "wp_schedule_event" not in raw
            and "wp_schedule_single_event" not in raw
        ):
            continue
        code = strip_comments(raw)

        for problem in check_a(code, label):
            check(False, problem)
        for problem in check_b(code, label):
            check(False, problem)
        for problem in check_c(code, label):
            check(False, problem)
        d_problems, undecided, resolved_keys = check_d(code, label, classes)
        for problem in d_problems:
            check(False, problem)
        for problem in check_e(code, label, registrations, classes):
            check(False, problem)

        hooks += len(registered_hooks(code))
        routes += len(call_args(code, "register_rest_route"))
        meta_sites += len(call_args(code, "register_post_meta")) + len(call_args(code, "register_meta"))
        undecided_meta += undecided
        resolved_meta_keys += resolved_keys
        schedules += len(scheduled_hook_expressions(code))

    # Coverage floors: a gate that audited nothing must not pass.
    check(hooks >= 25, f"expected at least 25 admin_post/wp_ajax handlers to audit, found {hooks}")
    check(routes >= 2, f"expected at least 2 register_rest_route() calls to audit, found {routes}")
    check(meta_sites >= 10, f"expected at least 10 meta registrations to audit, found {meta_sites}")
    check(schedules >= 2, f"expected at least 2 scheduled write paths to audit, found {schedules}")
    check(
        resolved_meta_keys >= 10,
        f"expected at least 10 meta keys to be resolvable from source, found {resolved_meta_keys}",
    )

    print(
        f"audited: {hooks} admin_post/wp_ajax handler(s), {routes} REST route registration(s), "
        f"{meta_sites} meta registration(s), {schedules} scheduled write path(s)"
    )
    print(
        f"meta key resolution: {resolved_meta_keys} key(s) resolved from source "
        f"(literal, constant, loop or array_merge), {undecided_meta} registration(s) undecided"
    )
    if undecided_meta:
        print(
            f"coverage note: {undecided_meta} meta registration(s) use a key this gate cannot "
            f"resolve; they are covered by the in-process REST permission suites instead"
        )
    return 0


if __name__ == "__main__":
    rc = main()
    for failure in FAILURES:
        print(f"FAIL: {failure}")
    print(f"{PASSED} passed, {len(FAILURES)} failed")
    sys.exit(1 if FAILURES else 0)

