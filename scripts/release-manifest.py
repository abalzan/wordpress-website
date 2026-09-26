#!/usr/bin/env python3
"""release-manifest.py — emit and verify dist/release.json (Stage J).

Purpose: implement the two halves of the engineering standard §11 release
record.

  * **Emit** (`--write`): read the artifacts in the build directory and write
    ``dist/release.json`` describing exactly what was built — component, kind,
    version, git SHA, built-at, file count, byte size and SHA-256 — alongside
    the allowlist the registry permits.
  * **Verify** (`--verify`, the default): re-derive the allowlist from
    ``plugins.json`` and re-check every recorded hash against the artifact on
    disk. A drifted, stale or tampered release fails loudly.

The derivation itself lives in ``scripts/lib/release.py`` so the build scripts,
this CLI and the release-integrity gate cannot disagree about what a release is.

Scope: reads the repository and the build output directory, and writes exactly
one file (``dist/release.json``) in ``--write`` mode. It never loads WordPress,
never contacts the network, never uses credentials and never touches production.

Safety: local-write. ``--write`` only writes inside the build output directory
(default ``dist/``, which is git-ignored). Verification modes are read-only.

Usage:
  release-manifest.py --write                 Build and write dist/release.json
  release-manifest.py --verify                Verify an existing manifest
  release-manifest.py --check                 Structural check only (no disk re-hash)
  release-manifest.py --print                 Print the manifest, write nothing
  release-manifest.py --help

Options:
  --dist DIR     Build output directory (default: <repo>/dist).
  --root DIR     Repository root (default: auto-detected).
  --tag TAG      Override the release tag (must match vYYYY.MM.DD[-N]).
  --json         Emit the machine-readable result on stdout.
"""

from __future__ import annotations

import argparse
import json
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "lib"))

import release  # noqa: E402  (path insertion above is deliberate)


def build_parser():
    parser = argparse.ArgumentParser(
        prog="release-manifest.py",
        description="Emit and verify the release manifest (dist/release.json).",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=(
            "Modes are mutually exclusive:\n"
            "  (default)  --verify  verify allowlist + hashes against the artifacts\n"
            "  --write              build the record and write dist/release.json\n"
            "  --check              validate the record's structure only\n"
            "  --print              print the record and exit\n"
        ),
    )
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument(
        "--write", action="store_true", help="build the record and write it"
    )
    mode.add_argument(
        "--verify",
        action="store_true",
        help="verify allowlist and hashes against the artifacts (default)",
    )
    mode.add_argument(
        "--check", action="store_true", help="validate the record structure only"
    )
    mode.add_argument(
        "--print", action="store_true", help="print the record; write nothing"
    )
    parser.add_argument("--dist", dest="dist", help="build output directory")
    parser.add_argument("--root", dest="root", help="repository root")
    parser.add_argument("--tag", dest="tag", help="override the release tag")
    parser.add_argument(
        "--json", dest="as_json", action="store_true", help="machine-readable output"
    )
    return parser


def print_header(action, root, dist_dir, manifest_path):
    print("script: release-manifest.py")
    print("target: %s (local build output)" % dist_dir)
    print("mode:   %s" % action)
    print(
        "scope:  Reads %s and the artifacts in the build directory; writes only "
        "dist/release.json." % os.path.relpath(os.path.join(root, "plugins.json"), root)
    )
    print("record: %s" % manifest_path)


def main(argv=None):
    args = build_parser().parse_args(argv)
    root = os.path.abspath(args.root or release.REPO_ROOT)
    dist_dir = os.path.abspath(args.dist or os.path.join(root, "dist"))
    manifest_path = os.path.join(dist_dir, release.MANIFEST_FILENAME)

    if args.print:
        action = "print"
    elif args.check:
        action = "check"
    elif args.write:
        action = "write"
    else:
        action = "verify"

    if action != "print" and not args.as_json:
        print_header(action, root, dist_dir, manifest_path)

    try:
        if action == "write":
            manifest = release.build_manifest(
                root=root, dist_dir=dist_dir, tag=args.tag
            )
            path = release.write_manifest(manifest, dist_dir)
            problems = release.validate_manifest(manifest)
            if problems:
                for problem in problems:
                    print("ERROR: the manifest just written is invalid: %s" % problem, file=sys.stderr)
                return 1
            if not args.as_json:
                _print_summary(manifest, path)
            else:
                print(json.dumps(manifest, indent=2, ensure_ascii=False))
            return 0

        manifest = release.load_manifest(manifest_path)

        if action == "print":
            print(json.dumps(manifest, indent=2, ensure_ascii=False))
            return 0

        if action == "check":
            problems = release.validate_manifest(manifest)
        else:
            problems, warnings = release.verify_manifest(
                manifest, root=root, dist_dir=dist_dir
            )
            if not args.as_json:
                for warning in warnings:
                    print("WARNING: %s" % warning)

        if problems:
            if args.as_json:
                print(json.dumps({"ok": False, "problems": problems}, indent=2))
            else:
                print("")
                for problem in problems:
                    print("FAIL: %s" % problem, file=sys.stderr)
                print("")
                print("release manifest verification: FAILED (%d problem(s))" % len(problems))
            return 1

        if args.as_json:
            print(json.dumps({"ok": True, "problems": []}, indent=2))
        else:
            print("")
            print("release manifest verification: OK")
            _print_summary(manifest, manifest_path)
        return 0

    except release.ReleaseError as exc:
        if args.as_json:
            print(json.dumps({"ok": False, "error": str(exc)}, indent=2))
        else:
            print("ERROR: %s" % exc, file=sys.stderr)
        return 1


def _print_summary(manifest, path):
    git = manifest["release"].get("git", {}) or {}
    totals = manifest.get("totals", {})

    print("")
    print("release:  %s  (built %s)" % (manifest["release"]["tag"], manifest["release"]["built_at"]))
    if git.get("available"):
        print(
            "commit:   %s%s%s on %s"
            % (
                git.get("short_sha"),
                " (dirty working tree)" if git.get("dirty") else "",
                "",
                git.get("branch") or "-",
            )
        )
    else:
        print("commit:   unavailable (no git metadata)")
    print("tag:      %s" % manifest["release"]["tag"])
    print("record:   %s" % path)
    print("")
    print("allowlist (%s): %d artifact(s)" % (manifest["allowlist"]["source"], len(manifest["allowlist"]["artifacts"])))
    print("built:          %d/%d artifact(s), %d file(s), %d byte(s)"
          % (totals.get("built", 0), totals.get("artifacts", 0), totals.get("files", 0), totals.get("bytes", 0)))
    print("")
    print("  %-32s %-8s %-9s %7s  %s" % ("artifact", "kind", "version", "files", "sha256"))
    for artifact in manifest["artifacts"]:
        print(
            "  %-32s %-8s %-9s %7s  %s"
            % (
                artifact["slug"],
                artifact["kind"],
                artifact["version"],
                artifact["file_count"] if artifact["built"] else "-",
                (artifact["sha256"] or "-")[:16],
            )
        )
    print("")
    print("summary: release=%s artifacts=%d built=%d" % (
        manifest["release"]["tag"], totals.get("artifacts", 0), totals.get("built", 0)))


if __name__ == "__main__":
    sys.exit(main())
