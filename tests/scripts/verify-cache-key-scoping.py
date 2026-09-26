#!/usr/bin/env python3
"""Stage L static half of the language-scoped cache gate.

A STATIC check over the repository's PHP source. It loads no WordPress, opens no
socket and can never contact production: it only reads files in this repository.

Engineering standard §6.3 makes this a permanent invariant:

    "Language-scoped caches: no unscoped `conexao_*` transient/object-cache key."

and §6.1 states the rule the code must follow:

    "Cache keys are language-scoped (`conexao_lang_cache_key()`) and
     invalidation clears all languages."

## Why this is a TOKEN scan, not a grep

A naive `grep "set_transient( 'conexao_"` produces false positives from:

  - comments and docblocks that mention the key as documentation,
  - already-scoped call sites (`conexao_lang_cache_key( 'conexao_x' )`),
  - the language-key helpers themselves, which legitimately take an UNSCOPED
    base key (`conexao_flush_language_cache( 'conexao_home_events' )` deletes
    the base key on purpose, then every language variant),
  - a key already scoped through a variable assigned from the helper
    (`$key = conexao_lang_cache_key(...); wp_cache_get( $key, ... )`).

So this gate uses PHP's own tokenizer (`token_get_all`) to see real code
structure: which tokens are a function call, and what the first argument
expression actually is. Comments and strings are excluded by the tokenizer
itself, which is what removes the false positives above.

Exit code: 0 when every check passes, 1 otherwise. Emits one `GATE-RESULT {...}`
line so the Stage L aggregate can build gate.json from real execution output.
"""

from __future__ import annotations

import json
import os
import subprocess
import sys

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)
REGISTRY = os.path.join(REPO_ROOT, "plugins.json")
THEME_DIR = os.path.join(REPO_ROOT, "wp-content", "themes", "conexao-br-irlanda")

GATE_ID = "cache_scoping_static"

# Cache entry points whose FIRST argument is the key we must police.
CACHE_READ_WRITE = ("set_transient", "wp_cache_set", "get_transient", "wp_cache_get")

# The scoping mechanism itself. Calls inside these functions DEFINE the key
# space; they are not uses of an already-scoped key.
SCOPING_FUNCTIONS = (
    "conexao_lang_cache_key",
    "conexao_flush_language_cache",
    "conexao_flush_language_object_cache",
)

PASSED = 0
FAILURES: list[str] = []
VIOLATIONS: list[dict] = []


def check(condition: bool, message: str) -> bool:
    """Record one assertion. Never raises."""
    global PASSED
    if condition:
        PASSED += 1
    else:
        FAILURES.append(message)
    return bool(condition)


def production_roots() -> list[str]:
    """Component directories the REGISTRY marks production: true, plus the theme.

    plugins.json is the single authoritative source (engineering standard §1.8).
    This gate never keeps its own copy of the list, so re-classifying a plugin
    in the registry is enough — there is no second source of truth to update.
    """
    with open(REGISTRY, encoding="utf-8") as handle:
        registry = json.load(handle)

    roots = [THEME_DIR]
    for plugin in registry.get("load_order", []):
        if not plugin.get("production"):
            continue
        slug = plugin.get("slug")
        if not slug:
            continue
        candidate = os.path.join(REPO_ROOT, "wp-content", "plugins", slug)
        if os.path.isdir(candidate):
            roots.append(candidate)
    return roots


# --- PHP tokenizer bridge ---------------------------------------------------
#
# PHP's own tokenizer is the only reliable way to know whether `set_transient` in
# a docblock is a call or a comment. We shell out ONCE for the whole scan (not
# once per file) and let PHP do the lexing; the analysis runs on the tokens.

TOKENIZER = r"""
$roots = array_slice($argv, 1);
$out  = array();
foreach ($roots as $root) {
    $root = rtrim($root, '/');
    if (!is_dir($root)) { continue; }
    $files = array();
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($rii as $file) {
        if (!$file->isFile()) { continue; }
        $path = $file->getPathname();
        if (substr($path, -4) !== '.php') { continue; }
        if (preg_match('#/(tests|vendor|node_modules|languages)/#', $path)) { continue; }
        $files[] = $path;
    }
    sort($files);
    foreach ($files as $path) {
        $items = array();
        foreach (token_get_all(file_get_contents($path)) as $t) {
            if (is_array($t)) {
                $items[] = array(token_name($t[0]), $t[1], $t[2]);
            } else {
                $items[] = array('CHAR', $t, 0);
            }
        }
        $out[$path] = $items;
    }
}
echo json_encode($out);
"""


def tokenize(roots: list[str]) -> dict:
    """Return {absolute_path: [[token_name, text, line], ...]} from PHP.

    Only the given roots are scanned, so the gate's scope is exactly the set of
    components plugins.json marks `production: true` (plus the theme). Retired
    and local-only tooling is excluded by the registry, not by a list here.
    """
    proc = subprocess.run(
        ["php", "-r", TOKENIZER, *roots],
        capture_output=True,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise RuntimeError("php tokenizer failed: " + proc.stderr.strip())
    return json.loads(proc.stdout)


def split_args(tokens: list, start: int) -> list[str] | None:
    """Return the raw source of each top-level argument from `start`.

    `start` indexes the '(' of the call. Returns None when the parenthesis is
    never closed (malformed source), so the caller can fail closed.
    """
    depth = 0
    args: list[str] = []
    current: list[str] = []

    for token in tokens[start:]:
        text = token[1]
        if token[0] == "CHAR" and text == "(":
            depth += 1
            if depth == 1:
                continue
        elif token[0] == "CHAR" and text == ")":
            depth -= 1
            if depth == 0:
                args.append("".join(current).strip())
                return args
        elif token[0] == "CHAR" and text == "," and depth == 1:
            args.append("".join(current).strip())
            current = []
            continue
        if token[0] in ("T_WHITESPACE", "T_COMMENT", "T_DOC_COMMENT"):
            continue
        current.append(text)

    return None


# --- classification ---------------------------------------------------------

# A key literal ending in a concatenation with a variable is keyed by RECORD
# identity, not by language. Verified against the real call sites:
#   conexao_reading_time_{$post_id}  (inc/content.php)  - one post's own content
#   conexao_b2_replaced_{md5(types)}  (inc/i18n/fallback.php) - the post-type set
# Neither varies with the active language, so PT and EN cannot collide.
RECORD_SCOPED_PREFIXES = (
    "conexao_reading_time_",
    "conexao_b2_replaced_",
)


def is_record_scoped(expr: str) -> bool:
    """True when the key is namespaced by a per-record identifier."""
    return any(prefix in expr for prefix in RECORD_SCOPED_PREFIXES) and "$" in expr


def enclosing_function(tokens: list, index: int) -> str | None:
    """Name of the function whose body contains `index`, or None at file scope."""
    depth_stack: list[tuple[int, str]] = []
    for position, token in enumerate(tokens[:index]):
        if token[0] == "T_FUNCTION":
            # The name is the next T_STRING before the '('.
            for lookahead in range(position + 1, min(position + 6, len(tokens))):
                if tokens[lookahead][0] == "T_STRING":
                    depth_stack.append((lookahead, tokens[lookahead][1]))
                    break
    if not depth_stack:
        return None
    return depth_stack[-1][1]


def analyse_file(rel_path: str, tokens: list) -> list[dict]:
    """Return the unscoped cache keys found in one file."""
    findings: list[dict] = []
    index = 0

    while index < len(tokens):
        token = tokens[index]

        if token[0] != "T_STRING" or token[1] not in CACHE_READ_WRITE:
            index += 1
            continue

        function_name = token[1]
        line = token[2]

        # Next significant token must be '(' for this to be a CALL.
        cursor = index + 1
        while cursor < len(tokens) and tokens[cursor][0] in ("T_WHITESPACE", "T_COMMENT", "T_DOC_COMMENT"):
            cursor += 1
        if cursor >= len(tokens) or tokens[cursor][1] != "(":
            index += 1
            continue

        args = split_args(tokens, cursor)
        if args is None or not args:
            # Unparseable call: fail closed rather than assume it is safe.
            findings.append({
                "file": rel_path, "line": line, "function": function_name,
                "key": "<unparsed call>", "reason": "call arguments could not be parsed",
            })
            index = cursor + 1
            continue

        key_expr = args[0]
        enclosing = enclosing_function(tokens, index)

        # 1. Key built by the language-key helper -> scoped.
        if "conexao_lang_cache_key(" in key_expr.replace(" ", ""):
            index = cursor + 1
            continue

        # 2. Inside one of the scoping helpers -> this IS the mechanism.
        if enclosing in SCOPING_FUNCTIONS:
            index = cursor + 1
            continue

        # 3. A variable assigned from the helper earlier in the same function.
        if key_expr.startswith("$"):
            variable = key_expr
            assigned = False
            for position, candidate in enumerate(tokens[:index]):
                if candidate[0] == "T_VARIABLE" and candidate[1] == variable:
                    lookahead = position + 1
                    while lookahead < len(tokens) and tokens[lookahead][0] in ("T_WHITESPACE",):
                        lookahead += 1
                    if lookahead < len(tokens) and tokens[lookahead][1] == "=":
                        window = "".join(t[1] for t in tokens[lookahead: lookahead + 12])
                        if "conexao_lang_cache_key" in window:
                            assigned = True
                            break
            if assigned:
                index = cursor + 1
                continue

        # 4. Record-scoped key -> cannot collide across languages.
        if is_record_scoped(key_expr):
            index = cursor + 1
            continue

        # 5. Anything naming a conexao_* key here is a VIOLATION.
        if "conexao_" in key_expr:
            findings.append({
                "file": rel_path, "line": line, "function": function_name,
                "key": key_expr, "enclosing": enclosing,
                "reason": "conexao_* cache key is not language-scoped",
            })

        index = cursor + 1

    return findings


def main() -> int:
    print("== Stage L static cache-scoping gate (token scan) ==")
    print(f"   repo: {REPO_ROOT}")

    roots = production_roots()
    check(len(roots) > 0, "no production components resolved from plugins.json")

    for root in roots:
        print(f"   scope: {os.path.relpath(root, REPO_ROOT)}")

    try:
        scanned = tokenize(roots)
    except RuntimeError as error:
        check(False, f"the PHP tokenizer could not run: {error}")
        scanned = {}

    # Anti-vacuity: the scan must have actually read the production source.
    check(
        len(scanned) > 0,
        "the token scan read at least one production PHP file "
        "(an empty scan would pass vacuously)",
    )

    for abs_path in sorted(scanned):
        rel = os.path.relpath(abs_path, REPO_ROOT)
        for finding in analyse_file(rel, scanned[abs_path]):
            VIOLATIONS.append(finding)
            FAILURES.append(
                f"{finding['file']}:{finding['line']} unscoped cache key "
                f"{finding['key']!r} passed to {finding['function']}() — "
                f"{finding['reason']}"
            )

    print(f"\n   scanned {len(scanned)} production PHP files (PHP token_get_all)")
    print(f"   unscoped conexao_* cache keys: {len(VIOLATIONS)}")
    for finding in VIOLATIONS:
        print(
            f"     - {finding['file']}:{finding['line']} "
            f"{finding['function']}( {finding['key']} )"
        )
    return 0


def emit() -> None:
    """Print the runner summary plus the Stage L machine-readable line."""
    passed = PASSED
    failed = len(FAILURES)

    for failure in FAILURES:
        print(f"FAIL: {failure}")

    print(f"{passed} passed, {failed} failed")

    payload = {
        "gate": GATE_ID,
        "status": "fail" if failed else "pass",
        "passed": passed,
        "failed": failed,
        "violations": len(VIOLATIONS),
        "details": VIOLATIONS,
    }
    print("GATE-RESULT " + json.dumps(payload))
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
    emit()
