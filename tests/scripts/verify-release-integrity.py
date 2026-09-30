#!/usr/bin/env python3
"""Stage J release-integrity gate.

A STATIC + build-level check over the release contract (engineering standard
§11). It loads no WordPress, opens no socket and can never contact production: it
reads files and runs the repository's own build scripts into a temporary
directory.

The two invariants this gate makes executable:

  1. **plugins.json determines what can be released.** The allowlist is derived
     from the registry, so a build that packages anything else — or omits
     something allowlisted — is a failure.
  2. **release.json records exactly what was actually built.** The manifest must
     be schema-valid, must match the artifacts on disk, and must DETECT a
     tampered artifact rather than rubber-stamping it.

What it asserts:

   1. The release contract files exist (library, manifest CLI, deploy verifier,
      orchestrator, allowlist matrix, release documentation).
   2. The allowlist derives from plugins.json, in load order, theme last.
   3. A retired or build:false plugin can never enter the allowlist.
   4. The manifest records component, kind, version, git SHA, built-at, file
      count, byte size and SHA-256 for every artifact.
   5. Versions in the manifest equal the versions in the component headers.
   6. A real build produces exactly the allowlisted artifacts, deterministically.
   7. No artifact contains tests/, fixtures/, *.json reports or OS junk.
   8. Tampering with a built artifact makes verification FAIL (negative proof:
      a verifier that cannot fail is not a verifier).
   9. A retired rollout plugin is refused by the allowlist derivation.
  10. The deployment smoke matrix covers every category §11 requires, and every
      row is schema-valid.
  11. The deploy verifier is read-only: GET only, no credentials, no default
      production target, no mutating verb.
  12. The documented release tag convention matches the code that enforces it.

Exit code: 0 when every check passes, 1 otherwise. Every failure is printed with
the offending path so the fix is obvious.
"""

from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import sys
import tempfile

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)
sys.path.insert(0, os.path.join(REPO_ROOT, "scripts", "lib"))

import release  # noqa: E402

PASSED = 0
FAILURES: list[str] = []


def check(condition: bool, message: str) -> bool:
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
    """Remove comment lines so documentation cannot satisfy a code check."""
    return "\n".join(
        line for line in source.splitlines() if not line.lstrip().startswith("#")
    )


def run(*command, **kwargs):
    return subprocess.run(
        command,
        cwd=REPO_ROOT,
        check=False,
        capture_output=True,
        text=True,
        **kwargs,
    )


def main() -> int:
    lib_release = os.path.join(REPO_ROOT, "scripts", "lib", "release.py")
    lib_zip = os.path.join(REPO_ROOT, "scripts", "lib", "zip-build.sh")
    manifest_cli = os.path.join(REPO_ROOT, "scripts", "release-manifest.py")
    deploy_cli = os.path.join(REPO_ROOT, "scripts", "verify-deploy.py")
    orchestrator = os.path.join(REPO_ROOT, "scripts", "verify-release.sh")
    matrix = os.path.join(REPO_ROOT, "scripts", "data", "release-smoke-matrix.json")
    releases_doc = os.path.join(REPO_ROOT, "docs", "releases.md")

    # -- 1. The release contract exists --------------------------------------
    for path, label in (
        (lib_release, "scripts/lib/release.py"),
        (lib_zip, "scripts/lib/zip-build.sh"),
        (manifest_cli, "scripts/release-manifest.py"),
        (deploy_cli, "scripts/verify-deploy.py"),
        (orchestrator, "scripts/verify-release.sh"),
        (matrix, "scripts/data/release-smoke-matrix.json"),
        (releases_doc, "docs/releases.md"),
    ):
        check(os.path.isfile(path), "%s is missing (the Stage J release contract)" % label)

    check(
        os.access(orchestrator, os.X_OK),
        "scripts/verify-release.sh is not executable",
    )

    # -- 2/3. The allowlist is derived from plugins.json ----------------------
    registry = release.load_registry(REPO_ROOT)
    allowlist = release.expected_allowlist(registry)
    slugs = [slug for _kind, slug in allowlist]

    check(
        slugs == release.build_slugs(registry) + [release.THEME_SLUG],
        "the allowlist is not the registry build order followed by the theme: %r" % slugs,
    )
    check(
        allowlist[-1] == ("theme", release.THEME_SLUG),
        "the theme must be the last allowlisted artifact",
    )

    entries = {e["slug"]: e for e in registry["load_order"]}
    for slug in release.build_slugs(registry):
        entry = entries.get(slug, {})
        check(
            entry.get("build") is True and entry.get("status") != "retired",
            "%s is allowlisted but is not an active build:true registry entry" % slug,
        )
    for slug, entry in entries.items():
        if entry.get("status") == "retired" or entry.get("build") is not True:
            check(
                slug not in slugs,
                "%s is retired/build:false and must never be allowlisted" % slug,
            )

    # A registry that build-enables a retired rollout must be refused outright
    # (negative proof: the derivation is not merely filtering, it is enforcing).
    poisoned = json.loads(json.dumps(registry))
    for entry in poisoned["load_order"]:
        if entry["slug"] == "conexao-data-model":
            entry["status"] = "retired"
    try:
        release.build_slugs(poisoned)
        check(False, "a retired build:true registry entry was not refused")
    except release.ReleaseError:
        check(True, "a retired build:true registry entry is refused")

    # -- 4/5. The manifest records what §11 requires -------------------------
    build_env = dict(os.environ)
    if "CONEXAO_REPO_ROOT" not in build_env:
        build_env["CONEXAO_REPO_ROOT"] = REPO_ROOT

    workdir = tempfile.mkdtemp(prefix="conexao-stage-j-")
    try:
        dist = os.path.join(workdir, "dist")

        plugins_build = run(
            "bash", os.path.join(REPO_ROOT, "scripts", "build-plugins-zip.sh"),
            env={**build_env, "BUILD_OUTPUT_DIR": dist},
        )
        theme_build = run(
            "bash", os.path.join(REPO_ROOT, "scripts", "build-theme-zip.sh"),
            env={**build_env, "BUILD_OUTPUT_DIR": dist},
        )
        check(
            plugins_build.returncode == 0,
            "build-plugins-zip.sh failed: %s" % (plugins_build.stderr or plugins_build.stdout)[-400:],
        )
        check(
            theme_build.returncode == 0,
            "build-theme-zip.sh failed: %s" % (theme_build.stderr or theme_build.stdout)[-400:],
        )

        manifest_path = os.path.join(dist, release.MANIFEST_FILENAME)
        check(
            os.path.isfile(manifest_path),
            "the build did not emit dist/release.json (engineering standard §11 MUST)",
        )
        if not os.path.isfile(manifest_path):
            return 0

        manifest = release.load_manifest(manifest_path)

        check(
            not release.validate_manifest(manifest),
            "the emitted manifest is not schema-valid: %s"
            % "; ".join(release.validate_manifest(manifest)),
        )

        for field in ("tag", "built_at", "git"):
            check(
                field in manifest.get("release", {}),
                "the manifest release record is missing %r" % field,
            )
        git = manifest.get("release", {}).get("git", {})
        if git.get("available"):
            check(
                bool(re.match(r"^[0-9a-f]{40}$", str(git.get("sha", "")))),
                "the manifest does not record a full git SHA",
            )

        by_slug = {a["slug"]: a for a in manifest["artifacts"]}
        for kind, slug in allowlist:
            artifact = by_slug.get(slug)
            if not check(artifact is not None, "%s is missing from the manifest" % slug):
                continue
            check(artifact["kind"] == kind, "%s has the wrong kind in the manifest" % slug)
            check(
                artifact["version"] == release.version_for(kind, slug, REPO_ROOT),
                "%s: the manifest version does not match the component header" % slug,
            )
            check(
                isinstance(artifact["file_count"], int) and artifact["file_count"] > 0,
                "%s: the manifest does not record a file count" % slug,
            )
            check(
                isinstance(artifact["bytes"], int) and artifact["bytes"] > 0,
                "%s: the manifest does not record a byte size" % slug,
            )
            check(
                bool(re.match(r"^[0-9a-f]{64}$", str(artifact.get("sha256", "")))),
                "%s: the manifest does not record a SHA-256" % slug,
            )
            check(
                os.path.isfile(os.path.join(dist, artifact["file"])),
                "%s: the manifest names an artifact that was not built" % slug,
            )

        # -- 6. Exactly the allowlisted set, and nothing else ----------------
        built_zips = sorted(
            name for name in os.listdir(dist) if name.endswith(".zip")
        )
        expected_zips = sorted("%s.zip" % slug for _kind, slug in allowlist)
        check(
            built_zips == expected_zips,
            "the build produced %r but the allowlist is %r"
            % (built_zips, expected_zips),
        )

        # -- 6b. Determinism: a second build must reproduce the bytes -------
        dist2 = os.path.join(workdir, "dist2")
        run(
            "bash", os.path.join(REPO_ROOT, "scripts", "build-plugins-zip.sh"),
            env={**build_env, "BUILD_OUTPUT_DIR": dist2},
        )
        run(
            "bash", os.path.join(REPO_ROOT, "scripts", "build-theme-zip.sh"),
            env={**build_env, "BUILD_OUTPUT_DIR": dist2},
        )
        for name in expected_zips:
            first, second = os.path.join(dist, name), os.path.join(dist2, name)
            if not (os.path.isfile(first) and os.path.isfile(second)):
                check(False, "%s: the determinism re-build did not produce the artifact" % name)
                continue
            check(
                release.sha256_file(first) == release.sha256_file(second),
                "%s: the build is not deterministic (two builds differ)" % name,
            )

        # -- 7. No test/fixture/report/junk file ships ----------------------
        import zipfile

        banned = re.compile(
            r"(^|/)(tests|fixtures|node_modules|__pycache__)/"
            r"|\.json$|\.DS_Store$|Thumbs\.db$|\.swp$|~$"
        )
        for name in expected_zips:
            path = os.path.join(dist, name)
            if not os.path.isfile(path):
                continue
            with zipfile.ZipFile(path) as archive:
                leaked = [
                    name
                    for name in archive.namelist()
                    if not name.endswith("/") and banned.search(name)
                ]
            check(
                not leaked,
                "%s ships excluded paths: %s" % (name, ", ".join(leaked[:5])),
            )

        # -- 8. Negative proof: tampering must be detected ------------------
        problems, _warnings = release.verify_manifest(manifest, REPO_ROOT, dist)
        check(not problems, "a freshly built manifest failed verification: %s" % "; ".join(problems))

        tampered = os.path.join(workdir, "dist-tampered")
        shutil.copytree(dist, tampered)
        victim = os.path.join(tampered, "conexao-content.zip")
        with open(victim, "ab") as handle:
            handle.write(b"tampered")
        tampered_problems, _ = release.verify_manifest(manifest, REPO_ROOT, tampered)
        check(
            bool(tampered_problems),
            "verification did NOT detect a tampered artifact (a verifier that cannot "
            "fail is not a verifier)",
        )

        # A manifest naming a non-allowlisted artifact must also be rejected.
        #
        # Stage 2: the fixture slug changed. It used to be
        # `conexao-translation-rollout`, which WAS a valid example while that
        # engine was `build: false`. Stage 2 promoted the engine to a
        # production platform plugin (`build: true`), so it is now legitimately
        # allowlisted and can no longer stand for a non-allowlisted component.
        # `conexao-en-translation` is the same kind of negative fixture: an
        # active, local-only TOOLING plugin that must never enter a release.
        rogue = json.loads(json.dumps(manifest))
        rogue["artifacts"].append(
            {
                "slug": "conexao-en-translation",
                "kind": "plugin",
                "version": "1.0.0",
                "file": "conexao-en-translation.zip",
                "built": True,
                "file_count": 1,
                "bytes": 1,
                "sha256": "0" * 64,
            }
        )
        rogue_problems, _ = release.verify_manifest(rogue, REPO_ROOT, dist)
        check(
            any("does not permit" in problem for problem in rogue_problems),
            "verification accepted a non-allowlisted (build:false tooling) artifact",
        )
    finally:
        shutil.rmtree(workdir, ignore_errors=True)

    # -- 10. The smoke matrix covers every §11 category ---------------------
    if os.path.isfile(matrix):
        data = json.loads(read(matrix))
        rows = data.get("rows", [])
        ids = " ".join(row["id"] for row in rows)
        urls = " ".join(row["url"] for row in rows)

        for label, needle in (
            ("homepage", "smoke-home-pt"),
            ("English home", "smoke-home-en"),
            ("sitemap", "smoke-sitemap"),
            ("404", "smoke-404-pt"),
            ("legacy redirect", "smoke-legacy-redirect"),
        ):
            check(needle in ids, "the release smoke matrix has no %s row" % label)

        for archive in ("guias", "eventos", "cursos", "empregos", "apoiadores", "lazer", "blog"):
            check(
                "/%s/" % archive in urls,
                "the release smoke matrix has no /%s/ archive row" % archive,
            )
        check("/en/guias/" in urls, "the release smoke matrix has no /en/ pair row")

        for row in rows:
            where = row.get("id", "<no id>")
            check(
                isinstance(row.get("url"), str) and row["url"].startswith("/"),
                "smoke row %s: url must be a relative path" % where,
            )
            check(
                isinstance(row.get("expect_status"), int),
                "smoke row %s: expect_status must be an integer" % where,
            )
            check(
                bool(str(row.get("note", "")).strip()),
                "smoke row %s: must carry a note explaining why it exists" % where,
            )
            check(
                "conexaobr.ie" not in row.get("url", ""),
                "smoke row %s: a matrix must never hard-code a production host" % where,
            )

    # -- 11. The deploy verifier is read-only and has no default target -----
    #
    # These are behavioural, not textual: the script is actually executed with
    # no target and must refuse. A regex over its source would only prove the
    # wording of an error message, whereas running it proves the contract.
    if os.path.isfile(deploy_cli):
        code = strip_comments(read(deploy_cli))

        for verb in ('data=b"', "data='", 'method="POST"', "method='POST'",
                     'method="PUT"', 'method="DELETE"', 'method="PATCH"'):
            check(
                verb not in code,
                "scripts/verify-deploy.py appears to send %r; it must be GET-only" % verb,
            )
        check(
            "WP_APPLICATION_PASSWORD" not in code
            and "Authorization" not in code,
            "scripts/verify-deploy.py references credentials; a verifier must "
            "authenticate nothing",
        )
        check(
            "conexaobr.ie" not in code,
            "scripts/verify-deploy.py hard-codes the production host; the target "
            "must come from --site only",
        )

        refused = run("python3", deploy_cli)
        check(
            refused.returncode != 0,
            "scripts/verify-deploy.py exits 0 with no --site; it must refuse to run "
            "without an explicit target (a default could probe the wrong site)",
        )
        check(
            "--site" in (refused.stderr + refused.stdout),
            "scripts/verify-deploy.py does not explain that --site is required",
        )

        for bad_site in ("ftp://example.invalid", "not-a-url", "https://"):
            result = run("python3", deploy_cli, "--site", bad_site, "--json")
            check(
                result.returncode != 0,
                "scripts/verify-deploy.py accepted the invalid target %r" % bad_site,
            )

    # -- 12. The tag convention and the enforcement agree ------------------
    check(
        docs_state_tag_convention(releases_doc),
        "docs/releases.md does not state the vYYYY.MM.DD release tag convention",
    )
    check(
        re.search(r"v\(\\d\{4\}\)", read(lib_release)) is not None,
        "scripts/lib/release.py no longer encodes the documented release tag convention",
    )

    return 0


def docs_state_tag_convention(path: str) -> bool:
    """Does the release documentation state the tag convention the code enforces?"""
    if not os.path.isfile(path):
        return False
    body = read(path)
    return "vYYYY.MM.DD" in body and "rollback" in body.lower()


if __name__ == "__main__":
    main()
    passed = PASSED
    failed = len(FAILURES)
    for failure in FAILURES:
        print(f"FAIL: {failure}")
    print(f"{passed} passed, {failed} failed")
    sys.exit(1 if failed else 0)
