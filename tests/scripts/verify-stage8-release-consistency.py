#!/usr/bin/env python3
"""Stage 8 gate: release metadata must equal source, exactly.

## The defect this gate exists to prevent

Stage 7 found that the built `dist/release.json` recorded
`conexao-translation-automation` at **0.2.0** while the plugin header — the
authoritative version source — said **0.3.0**. The artifact had been rebuilt but
the record had not, so the two disagreed and nothing failed. Uploading the stale
artifact would have shipped Stage 6 code labelled 0.2.0.

That is the regression test this file carries. The Stage 7 mismatch is now a
permanent assertion, not a historical anecdote.

## The values that must agree

For every plugin the release allowlist permits to be built:

    plugin header  ==  dist/release.json  ==  the version inside the built ZIP

The registry stores no version of its own — by design, so there is nothing to
drift (`scripts/lib/release.py` reads the header and calls it the only
authoritative source). So the header IS the registry's answer, and this gate
asserts the header, the manifest and the artifact all state the same version.

## A stale release.json is a FAILURE, not a warning

If `dist/release.json` exists and disagrees with the source, this gate exits
non-zero. If it does not exist, there is nothing stale to catch, so the gate
reports the artifact assertions as not-applicable rather than inventing a
failure — `dist/` is git-ignored build output, and a missing build directory is
a normal state for a fresh clone.

Static only. Reads files; runs no WordPress, opens no socket, contacts nothing.
"""

from __future__ import annotations

import json
import os
import pathlib
import re
import sys
import zipfile

REPO_ROOT = pathlib.Path(
    os.environ.get("CONEXAO_REPO_ROOT") or pathlib.Path(__file__).resolve().parents[2]
)
DIST = REPO_ROOT / "dist"
REGISTRY = REPO_ROOT / "plugins.json"

# The exact Stage 7 finding, asserted as a live contract.
STAGE7_COMPONENT = "conexao-translation-automation"
STAGE7_STALE_VERSION = "0.2.0"

VERSION_RE = re.compile(
    r"^\s*\*?\s*Version:\s*(?P<version>[0-9A-Za-z][0-9A-Za-z.+\-]*)\s*$", re.M
)

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


def header_version(path: pathlib.Path) -> str | None:
    """The `Version:` header of a plugin/theme file, or None."""
    if not path.is_file():
        return None
    match = VERSION_RE.search(path.read_text(encoding="utf-8", errors="replace"))
    return match.group("version") if match else None


def zip_version(archive: pathlib.Path) -> str | None:
    """The `Version:` declared INSIDE a built artifact, or None.

    Read from the artifact itself rather than trusted from the manifest: this is
    what makes "the ZIP you would upload says X" a checked fact.

    Handles BOTH WordPress version carriers:
      - a plugin main file at the archive root (`Version:` in the docblock);
      - a theme's `style.css` (`Version:` in the CSS header).
    """
    try:
        with zipfile.ZipFile(archive) as zf:
            for name in sorted(zf.namelist()):
                if name.endswith("style.css") and name.count("/") <= 1:
                    source = zf.read(name).decode("utf-8", errors="replace")
                    match = re.search(
                        r"^Version:\s*(?P<version>[0-9A-Za-z][0-9A-Za-z.+\-]*)\s*$",
                        source,
                        re.M,
                    )
                    if match:
                        return match.group("version")

                if name.count("/") == 1 and name.endswith(".php"):
                    source = zf.read(name).decode("utf-8", errors="replace")
                    match = VERSION_RE.search(source)
                    if match:
                        return match.group("version")
    except (zipfile.BadZipFile, OSError):
        return None
    return None


def theme_version() -> str | None:
    """The theme's `Version:` from style.css -- the WordPress theme convention.

    The theme is NOT a plugins.json load_order entry (the registry lists
    plugins), so it is read by its own documented convention rather than
    through a `version_source`.
    """
    style = REPO_ROOT / "wp-content" / "themes" / "conexao-br-irlanda" / "style.css"

    if not style.is_file():
        return None

    match = re.search(
        r"^Version:\s*(?P<version>[0-9A-Za-z][0-9A-Za-z.+\-]*)\s*$",
        style.read_text(encoding="utf-8", errors="replace"),
        re.M,
    )
    return match.group("version") if match else None


def expected_version(slug: str, kind: str, entries: list[dict]) -> str | None:
    """The version the SOURCE says this artifact must carry."""
    if kind == "theme":
        return theme_version()

    entry = next((e for e in entries if e["slug"] == slug), None)
    source = (entry or {}).get("version_source")

    return header_version(REPO_ROOT / source) if source else None


def main() -> int:
    print("== Stage 8: release metadata consistency ==")

    if not REGISTRY.is_file():
        print(f"  FAIL: no plugin registry at {REGISTRY}")
        return 1

    registry = json.loads(REGISTRY.read_text(encoding="utf-8"))
    entries = registry["load_order"]

    manifest_path = DIST / "release.json"
    manifest = None

    if manifest_path.is_file():
        try:
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            ok("dist/release.json parses as JSON", True)
        except json.JSONDecodeError as exc:
            ok("dist/release.json parses as JSON", False, str(exc))
    else:
        print("  NOTE: dist/release.json is absent (dist/ is git-ignored build output).")
        print("        Artifact assertions below are reported as not-applicable.")

    recorded = {}
    if manifest is not None:
        for artifact in manifest.get("artifacts", []):
            recorded[artifact.get("slug")] = artifact

    # --- 1. Every declared version_source resolves to a real header --------
    for entry in entries:
        source = entry.get("version_source")

        if not source:
            continue

        ok(
            f"{entry['slug']}: its declared version_source carries a Version header",
            header_version(REPO_ROOT / source) is not None,
            f"{source} has no readable Version header",
        )

    # --- 2. Source == release.json, for every allowlisted, built artifact ---
    compared = 0

    for entry in entries:
        slug = entry["slug"]
        source = entry.get("version_source")

        if not entry.get("build") or not source:
            continue

        record = recorded.get(slug)

        if record is None:
            print(f"  NOTE: {slug} is buildable but absent from release.json (nothing built).")
            continue

        compared += 1
        header = header_version(REPO_ROOT / source)

        ok(
            f"{slug}: plugin header version == dist/release.json version",
            header is not None and header == record.get("version"),
            f"header={header} release.json={record.get('version')}",
        )

    # --- 3. release.json == the artifact actually on disk -----------------
    if manifest is not None:
        for slug, record in sorted(recorded.items()):
            if not record.get("built"):
                continue

            archive = DIST / str(record.get("file", ""))
            kind = str(record.get("kind", "plugin"))

            if not archive.is_file():
                ok(f"{slug}: the recorded artifact exists", False, f"missing {archive.name}")
                continue

            ok(f"{slug}: the recorded artifact exists", True)

            header = expected_version(slug, kind, entries)
            inside = zip_version(archive)

            ok(
                f"{slug}: the version INSIDE the artifact == the source version",
                inside is not None and header is not None and inside == header,
                f"zip={inside} source={header}",
            )

    # --- 4. The Stage 7 finding, as a live regression assertion ------------
    entry = next((e for e in entries if e["slug"] == STAGE7_COMPONENT), None)

    ok(
        f"{STAGE7_COMPONENT} is still a known registry entry",
        entry is not None,
        "the Stage 7 regression subject vanished from plugins.json",
    )

    if entry is not None:
        header = header_version(REPO_ROOT / entry["version_source"])
        record = recorded.get(STAGE7_COMPONENT)

        ok(
            f"REGRESSION (Stage 7): {STAGE7_COMPONENT} header is not the stale {STAGE7_STALE_VERSION}",
            header != STAGE7_STALE_VERSION,
            f"header is {header}; Stage 7 shipped a stale {STAGE7_STALE_VERSION} record",
        )

        if record is not None:
            ok(
                f"REGRESSION (Stage 7): {STAGE7_COMPONENT} release.json matches its header",
                record.get("version") == header,
                f"release.json={record.get('version')} header={header}",
            )

    # --- 5. Dependency metadata agrees with the registry -------------------
    for entry in entries:
        if not entry.get("dependencies"):
            continue

        source = entry.get("version_source")

        if not source or not (REPO_ROOT / source).is_file():
            continue

        text = (REPO_ROOT / source).read_text(encoding="utf-8", errors="replace")
        match = re.search(r"^\s*\*?\s*Requires Plugins:\s*(?P<plugins>.+?)\s*$", text, re.M)

        declared = (
            sorted(p.strip() for p in match.group("plugins").split(",") if p.strip())
            if match
            else []
        )
        expected = sorted(entry["dependencies"])

        ok(
            f"{entry['slug']}: 'Requires Plugins' header == plugins.json dependencies",
            declared == expected,
            f"header={declared} registry={expected}",
        )

    print()
    print(f"{PASSED} passed, {len(FAILURES)} failed")
    print(f"versions compared header-vs-manifest: {compared}")
    for failure in FAILURES:
        print(f"  FAIL: {failure}")

    return 1 if FAILURES else 0


if __name__ == "__main__":
    sys.exit(main())