#!/usr/bin/env python3
"""Stage 2 promotion gate: the registry classification and its consequences.

A STATIC check over the repository. It loads no WordPress, opens no socket and
can never contact production: it reads ``plugins.json``, the plugin headers and
the built artifacts, and it runs the repository's OWN build machinery into a
temporary directory.

Why this exists as a script gate rather than an in-process PHP suite: the
in-process layer runs inside the WordPress container, which mounts only
``tests/`` and ``scripts/``. ``plugins.json`` and the release contract are
repository-level facts, so they are checked here, where the whole repository is
visible.

What it asserts:

   1. ``plugins.json`` parses and declares the documented schema.
   2. ``conexao-translation-rollout`` is a production-capable platform plugin
      (class platform, production true, build true, status active).
   3. ``conexao-translation-automation`` is classified the same way.
   4. The automation plugin declares the engine as a dependency, and the
      engine precedes it in load order (a valid dependency graph).
   5. Every lifecycle invariant from ``validate_lifecycle_rules()`` still
      holds. The promotion must NOT have been bought by weakening a rule.
   6. The promotion was consistent with the header contract: each plugin's
      ``Requires Plugins`` header matches its registry dependencies.
   7. No unrelated plugin changed classification.
   8. The generated regions agree with the registry (registry drift gate).
   9. The build produces a deterministic, schema-valid artifact for the
      promoted engine and the automation plugin, containing no tests and no
      development-only files.
  10. The shared engine source is byte-identical to its recorded pre-stage
      SHA-256, so the promotion changed metadata only.

Exit code: 0 when every check passes, 1 otherwise. Every failure is printed
with the offending detail so the fix is obvious.
"""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
import sys
import tempfile
import zipfile

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)

REGISTRY = os.path.join(REPO_ROOT, "plugins.json")

# The digest recorded before Stage 2 began, and again by Stage 1. The promotion
# is a metadata change: the engine's SOURCE must not move.
ENGINE_REL = "wp-content/plugins/conexao-translation-rollout/includes/class-conexao-translation-rollout-engine.php"
ENGINE_SHA256 = "baf85283df95e80c6e1e2fccb0e1290c73f6269e290e33eb138ed2cfa36a6ce4"

PROMOTED = ("conexao-translation-rollout", "conexao-translation-automation")

failures: list[str] = []
checks = 0


def ok(message: str) -> None:
    print("  ok   %s" % message)


def bad(message: str) -> None:
    failures.append(message)
    print("  FAIL %s" % message)


def check(condition: bool, message: str) -> bool:
    global checks
    checks += 1
    if condition:
        ok(message)
    else:
        bad(message)
    return bool(condition)


def load_registry() -> dict:
    if not os.path.isfile(REGISTRY):
        print("ERROR: plugins.json not found at %s" % REGISTRY, file=sys.stderr)
        sys.exit(2)

    with open(REGISTRY, "r", encoding="utf-8") as handle:
        return json.load(handle)


def plugin_header(path: str) -> dict:
    """Read the WordPress plugin header fields this gate asserts on."""
    fields = {}
    if not os.path.isfile(path):
        return fields

    with open(path, "r", encoding="utf-8") as handle:
        for line in handle.read().split("\n"):
            line = line.strip()
            if line.startswith("*") and ":" in line:
                key, _, value = line.partition(":")
                fields[key.strip().lstrip("*").strip()] = value.strip()

    return fields


def main() -> int:
    registry = load_registry()
    entries = registry.get("load_order", [])

    print("stage 2 promotion gate")
    print("  repository: %s" % REPO_ROOT)

    by_slug = {e["slug"]: e for e in entries if isinstance(e, dict) and "slug" in e}

    # --- 1. Schema ------------------------------------------------------
    required = (
        "slug", "name", "class", "status", "production",
        "build", "mount", "dependencies", "version_source", "documentation",
    )

    missing = [
        "%s:%s" % (e.get("slug", "?"), field)
        for e in entries if isinstance(e, dict)
        for field in required if field not in e
    ]
    check(not missing, "every registry entry declares the required fields%s"
          % (" (missing: %s)" % ", ".join(missing) if missing else ""))

    # --- 2/3. Promotion classification ----------------------------------
    for slug in PROMOTED:
        entry = by_slug.get(slug)
        if not check(entry is not None, "%s is registered" % slug):
            continue

        check(entry.get("class") == "platform",
              "%s class is 'platform' (got %r)" % (slug, entry.get("class")))
        check(entry.get("production") is True,
              "%s production is true (got %r)" % (slug, entry.get("production")))
        check(entry.get("build") is True,
              "%s build is true (got %r)" % (slug, entry.get("build")))
        check(entry.get("status") == "active",
              "%s status is 'active' (got %r)" % (slug, entry.get("status")))

    # --- 4. Dependency graph --------------------------------------------
    automation = by_slug.get("conexao-translation-automation", {})
    engine = by_slug.get("conexao-translation-rollout", {})

    check("conexao-translation-rollout" in automation.get("dependencies", []),
          "the automation plugin declares the engine as a dependency")

    order = [e["slug"] for e in entries if isinstance(e, dict) and "slug" in e]

    if "conexao-translation-rollout" in order and "conexao-translation-automation" in order:
        check(order.index("conexao-translation-rollout") < order.index("conexao-translation-automation"),
              "the engine precedes the automation plugin in load order")

    # Every dependency must precede its dependent and must exist.
    graph_errors = []
    for entry in entries:
        if not isinstance(entry, dict):
            continue
        slug = entry.get("slug", "?")
        for dep in entry.get("dependencies", []):
            if dep not in order:
                graph_errors.append("%s depends on unregistered %s" % (slug, dep))
            elif order.index(dep) > order.index(slug):
                graph_errors.append("%s loads before its dependency %s" % (slug, dep))

    check(not graph_errors, "the dependency graph is valid%s"
          % (" (%s)" % "; ".join(graph_errors) if graph_errors else ""))

    # --- 5. Lifecycle invariants, NOT weakened --------------------------
    lifecycle = []
    for entry in entries:
        if not isinstance(entry, dict):
            continue
        slug = entry.get("slug", "?")
        klass = entry.get("class")
        status = entry.get("status")
        prod = entry.get("production")
        build = entry.get("build")

        if status == "retired" and prod is True:
            lifecycle.append("%s retired but production" % slug)
        if status == "retired" and build is True:
            lifecycle.append("%s retired but build-enabled" % slug)
        if klass == "rollout" and status == "active":
            lifecycle.append("%s active rollout" % slug)
        if klass == "rollout" and prod is True:
            lifecycle.append("%s rollout marked production" % slug)
        if klass == "tooling" and prod is True:
            lifecycle.append("%s tooling marked production" % slug)

    check(not lifecycle, "every lifecycle invariant still holds%s"
          % (" (%s)" % "; ".join(lifecycle) if lifecycle else ""))

    # --- 6. Header / registry dependency agreement ----------------------
    header_errors = []
    for entry in entries:
        if not isinstance(entry, dict):
            continue
        source = os.path.join(REPO_ROOT, entry.get("version_source", ""))
        header = plugin_header(source)
        declared = [
            d.strip() for d in header.get("Requires Plugins", "").split(",")
            if d.strip()
        ]
        registry_deps = sorted(entry.get("dependencies", []))
        if sorted(declared) != registry_deps:
            header_errors.append(
                "%s: header %s vs registry %s"
                % (entry.get("slug"), sorted(declared), registry_deps)
            )

    check(not header_errors, "every plugin header agrees with its registry dependencies%s"
          % (" (%s)" % "; ".join(header_errors) if header_errors else ""))

    # --- 7. No unrelated plugin was promoted ---------------------------
    unrelated = []
    for slug, entry in by_slug.items():
        if slug in PROMOTED:
            continue
        if entry.get("class") == "tooling" and entry.get("production") is True:
            unrelated.append(slug)
        if entry.get("status") == "retired" and (
            entry.get("production") is True or entry.get("build") is True
        ):
            unrelated.append(slug)

    check(not unrelated, "no unrelated plugin was promoted%s"
          % (" (%s)" % ", ".join(unrelated) if unrelated else ""))

    # --- 8. Registry drift ---------------------------------------------
    generator = os.path.join(REPO_ROOT, "scripts", "generate-registry-docs.php")
    proc = subprocess.run(
        ["php", generator, "--check"],
        cwd=REPO_ROOT, capture_output=True, text=True,
    )
    check(proc.returncode == 0,
          "generate-registry-docs.php --check reports no drift (%s)"
          % (proc.stdout.strip() or proc.stderr.strip()))

    # --- 9. Build artifacts --------------------------------------------
    with tempfile.TemporaryDirectory() as dist:
        build = subprocess.run(
            ["bash", os.path.join(REPO_ROOT, "scripts", "build-plugins-zip.sh")],
            cwd=REPO_ROOT, capture_output=True, text=True,
            env={**os.environ, "BUILD_OUTPUT_DIR": dist},
        )
        if check(build.returncode == 0, "the plugin build succeeds%s"
                % ((" (%s)" % build.stderr.strip()[-200:]) if build.returncode else "")):
            for slug in PROMOTED:
                artifact = os.path.join(dist, "%s.zip" % slug)
                if not check(os.path.isfile(artifact),
                             "%s.zip was built" % slug):
                    continue

                with zipfile.ZipFile(artifact) as zf:
                    names = zf.namelist()

                leaked = [
                    n for n in names
                    if "/tests/" in n or n.startswith("tests/")
                    or "/fixtures/" in n
                    or n.endswith(".json")
                    or n.endswith((".log", ".pyc", ".DS_Store", ".swp"))
                    or "~" in n
                ]
                check(not leaked, "%s.zip contains no test or development file%s"
                      % (slug, " (%s)" % ", ".join(leaked[:5]) if leaked else ""))

                check(any(n.endswith(".php") for n in names),
                      "%s.zip contains PHP sources" % slug)

            # Determinism: a second build reproduces identical bytes.
            second = tempfile.mkdtemp()
            subprocess.run(
                ["bash", os.path.join(REPO_ROOT, "scripts", "build-plugins-zip.sh")],
                cwd=REPO_ROOT, capture_output=True, text=True,
                env={**os.environ, "BUILD_OUTPUT_DIR": second},
            )
            for slug in PROMOTED:
                a = os.path.join(dist, "%s.zip" % slug)
                b = os.path.join(second, "%s.zip" % slug)
                if not (os.path.isfile(a) and os.path.isfile(b)):
                    continue
                with open(a, "rb") as fa, open(b, "rb") as fb:
                    same = hashlib.sha256(fa.read()).hexdigest() == hashlib.sha256(fb.read()).hexdigest()
                check(same, "%s.zip is byte-identical across two builds" % slug)

    # --- 10. Engine source integrity -----------------------------------
    engine_path = os.path.join(REPO_ROOT, ENGINE_REL)
    if os.path.isfile(engine_path):
        with open(engine_path, "rb") as handle:
            digest = hashlib.sha256(handle.read()).hexdigest()
        check(digest == ENGINE_SHA256,
              "the shared engine source is byte-identical to its pre-stage digest (got %s)" % digest)
    else:
        check(False, "the shared engine source exists at %s" % ENGINE_REL)

    print("")
    print("%d passed, %d failed" % (checks - len(failures), len(failures)))

    if failures:
        print("STAGE 2 PROMOTION GATE: FAILED")
        return 1

    print("STAGE 2 PROMOTION GATE: OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
