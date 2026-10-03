"""scripts/lib/release.py — shared release-record logic (Stage J).

Engineering standard §11 turns two things into MUSTs that are easy to get
subtly wrong, and both are *derived* facts rather than hand-typed ones:

  1. ``plugins.json`` determines what can be released (artifact allowlist).
  2. ``dist/release.json`` records exactly what was actually built
     (component, version, git SHA, built-at, file count, sha256).

This module is the single implementation of both derivations. The build
scripts, ``scripts/release-manifest.py`` and the release-integrity gate all
import it, so a change to the release contract cannot be applied to one caller
and forgotten in another.

Design rules
------------
1. **One source of truth.** The allowlist is derived from ``plugins.json``
   (``build: true``, in load order) plus the single active theme. This module
   never maintains its own plugin list.
2. **Versions are read, never recorded twice.** A component's version is the
   WordPress header ``Version:`` field in its ``version_source`` file, or the
   theme's ``style.css``. The manifest copies it; nothing else stores it.
3. **A manifest is only as good as its verification.** :func:`verify_manifest`
   re-derives the allowlist and re-hashes every artifact on disk, so a manifest
   that has drifted from the build (or from the registry) fails loudly.
4. **Standard library only.** The repository has no third-party Python
   dependency and this module adds none.

Scope: read-only with respect to sources. It writes exactly one file, the
release manifest, and only when the caller asks it to. It never contacts the
network, never loads WordPress and never touches production.
"""

from __future__ import annotations

import datetime
import hashlib
import json
import os
import re
import subprocess
import zipfile

__all__ = [
    "ReleaseError",
    "MANIFEST_SCHEMA_VERSION",
    "THEME_SLUG",
    "REPO_ROOT",
    "MANIFEST_FILENAME",
    "RELEASE_TAG_RE",
    "load_registry",
    "build_slugs",
    "production_slugs",
    "expected_allowlist",
    "component_version",
    "theme_version",
    "version_for",
    "sha256_file",
    "git_meta",
    "release_tag",
    "built_at",
    "build_manifest",
    "write_manifest",
    "load_manifest",
    "validate_manifest",
    "verify_manifest",
]

#: Bumped only on an incompatible change to the manifest shape.
MANIFEST_SCHEMA_VERSION = 1

#: The single active theme (one theme in this repository).
THEME_SLUG = "conexao-br-irlanda"

#: The release record emitted next to the artifacts it describes.
MANIFEST_FILENAME = "release.json"

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)

#: Release tag convention: vYYYY.MM.DD, with an optional -N suffix when more
#: than one release ships on the same day. Documented in docs/releases.md and
#: asserted by the release-integrity gate.
RELEASE_TAG_RE = re.compile(r"^v(\d{4})\.(\d{2})\.(\d{2})(-\d+)?$")


class ReleaseError(RuntimeError):
    """Raised when the registry or a release manifest is not usable."""


# --- Registry ----------------------------------------------------------------


def load_registry(root=None):
    """Load and minimally validate ``plugins.json``."""
    root = root or REPO_ROOT
    path = os.path.join(root, "plugins.json")
    if not os.path.isfile(path):
        raise ReleaseError("plugin registry not found: %s" % path)
    try:
        with open(path, encoding="utf-8") as handle:
            data = json.load(handle)
    except json.JSONDecodeError as exc:
        raise ReleaseError("plugins.json is not valid JSON: %s" % exc) from exc

    if not isinstance(data, dict) or not isinstance(data.get("load_order"), list):
        raise ReleaseError("plugins.json must contain a 'load_order' array")
    if not data["load_order"]:
        raise ReleaseError("plugins.json 'load_order' is empty")
    return data


def _entries(registry):
    entries = registry.get("load_order", [])
    for entry in entries:
        if not isinstance(entry, dict) or not entry.get("slug"):
            raise ReleaseError("every plugins.json load_order entry needs a 'slug'")
    return entries


def build_slugs(registry):
    """Slugs that may be packaged, in registry load order (build: true).

    This is the release allowlist for plugins. A ``retired`` rollout is
    excluded twice over: the registry generator already forbids ``build: true``
    on a retired entry, and this function refuses the combination outright so a
    hand-edited registry can never ship historical rollout code.
    """
    slugs = []
    for entry in _entries(registry):
        if entry.get("build") is not True:
            continue
        if entry.get("status") == "retired":
            raise ReleaseError(
                "registry entry %r is retired and must never be build-enabled"
                % entry.get("slug")
            )
        slugs.append(str(entry["slug"]))
    return slugs


def production_slugs(registry):
    """Slugs in the production steady state, in registry load order."""
    return [
        str(entry["slug"])
        for entry in _entries(registry)
        if entry.get("production") is True and entry.get("status") == "active"
    ]


def expected_allowlist(registry):
    """The full ordered artifact allowlist for a release.

    Plugin artifacts (registry build order) followed by the theme. A retired or
    ``build: false`` plugin can never appear here, so an artifact that is not in
    this list is by definition not releasable.
    """
    return [("plugin", slug) for slug in build_slugs(registry)] + [("theme", THEME_SLUG)]


# --- Versions ----------------------------------------------------------------

_VERSION_RE = re.compile(
    r"^\s*\*?\s*Version:\s*(?P<version>[0-9A-Za-z][0-9A-Za-z.\-+]*)\s*$", re.M
)


def component_version(root=None, slug=None, version_source=None):
    """Read a component's version from its authoritative header.

    ``plugins.json`` names the file whose WordPress header ``Version:`` field is
    authoritative. The version is deliberately never duplicated into the
    registry, so this is the only place a version is read from.
    """
    root = root or REPO_ROOT

    if version_source is None:
        version_source = None
        for entry in _entries(load_registry(root)):
            if entry.get("slug") == slug:
                version_source = entry.get("version_source")
                break
        if version_source is None:
            raise ReleaseError("no registry entry for component %r" % slug)

    if not version_source:
        raise ReleaseError("component %r declares no version_source" % slug)

    path = os.path.join(root, version_source)
    if not os.path.isfile(path):
        raise ReleaseError(
            "version source not found for %r: %s" % (slug, version_source)
        )

    with open(path, encoding="utf-8", errors="replace") as handle:
        match = _VERSION_RE.search(handle.read())
    if not match:
        raise ReleaseError("no 'Version:' header in %s" % version_source)
    return match.group("version")


def theme_version(root=None):
    """Read the theme version from ``style.css`` (the WordPress convention)."""
    root = root or REPO_ROOT
    path = os.path.join(root, "wp-content", "themes", THEME_SLUG, "style.css")
    if not os.path.isfile(path):
        raise ReleaseError("theme stylesheet not found: %s" % path)
    with open(path, encoding="utf-8", errors="replace") as handle:
        match = _VERSION_RE.search(handle.read())
    if not match:
        raise ReleaseError("no 'Version:' header in %s" % path)
    return match.group("version")


def version_for(kind, slug, root=None):
    """Version of a plugin artifact or of the theme."""
    return theme_version(root) if kind == "theme" else component_version(root, slug)


# --- Hashing -----------------------------------------------------------------


def sha256_file(path):
    """SHA-256 of a file, streamed so a large theme ZIP never sits in memory."""
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def zip_entry_count(path):
    """Number of FILE entries in a ZIP, read from its central directory.

    Uses :mod:`zipfile` rather than shelling out, so the manifest needs no extra
    dependency. Directory entries are not counted: the file count a release
    record should state is the payload, not the folders.
    """
    with zipfile.ZipFile(path) as archive:
        return sum(1 for info in archive.infolist() if not info.is_dir())


# --- Git identity ------------------------------------------------------------


def _git(root, *args):
    """Run a read-only git command; None when git is unavailable or it fails."""
    try:
        completed = subprocess.run(
            ("git",) + args, cwd=root, check=False, capture_output=True, text=True
        )
    except (OSError, ValueError):
        return None
    if completed.returncode != 0:
        return None
    return completed.stdout.strip()


def git_meta(root=None):
    """Commit identity for the release record.

    ``dirty`` is recorded because a manifest built from a working tree with
    uncommitted changes does not describe anything reproducible. It is advisory
    rather than fatal: building from a dirty tree is legitimate during
    development, so the flag informs the reader instead of blocking the build.
    """
    root = root or REPO_ROOT
    sha = _git(root, "rev-parse", "HEAD")

    if not sha:
        # A source export with no .git directory. The release can still be built
        # and recorded; it simply has no commit to point at.
        return {
            "available": False,
            "sha": None,
            "short_sha": None,
            "branch": None,
            "dirty": None,
            "tag": None,
        }

    status = _git(root, "status", "--porcelain")
    branch = _git(root, "rev-parse", "--abbrev-ref", "HEAD")
    exact = _git(root, "describe", "--tags", "--exact-match")

    return {
        "available": True,
        "sha": sha,
        "short_sha": sha[:12],
        "branch": None if branch in (None, "", "HEAD") else branch,
        "dirty": bool(status),
        "tag": exact or None,
    }


def release_tag(moment=None, git=None, root=None):
    """The release tag this build would carry (``vYYYY.MM.DD``).

    The date comes from the build moment, or from ``SOURCE_DATE_EPOCH`` when it
    is set, so a reproducible build reproduces its tag as well. A same-day
    re-release appends ``-2``, ``-3``, ... per docs/releases.md.
    """
    root = root or REPO_ROOT

    if git is None:
        git = git_meta(root)

    # A checkout already sitting on a conforming release tag IS that release.
    tag = git.get("tag")
    if tag and RELEASE_TAG_RE.match(str(tag)):
        return str(tag)

    if moment is None:
        epoch = os.environ.get("SOURCE_DATE_EPOCH", "").strip()
        if epoch.isdigit():
            moment = datetime.datetime.fromtimestamp(
                int(epoch), tz=datetime.timezone.utc
            )
    if moment is None:
        moment = datetime.datetime.now(tz=datetime.timezone.utc)

    return "v" + moment.strftime("%Y.%m.%d")


def built_at(moment=None):
    """ISO-8601 UTC build timestamp for the manifest."""
    if moment is None:
        moment = datetime.datetime.now(tz=datetime.timezone.utc)
    if moment.tzinfo is None:
        moment = moment.replace(tzinfo=datetime.timezone.utc)
    return moment.astimezone(datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


# --- The release manifest ----------------------------------------------------
#
# Shape (schema_version 1):
#
#   {
#     "schema_version": 1,
#     "release": { "tag", "built_at", "git": {...} },
#     "allowlist": { "source", "artifacts": [...], "production_order": [...] },
#     "artifacts": [ { "slug", "kind", "version", "file", "built",
#                      "file_count", "bytes", "sha256" } ],
#     "totals": { "artifacts", "built", "files", "bytes" }
#   }
#
# The `allowlist` block is what makes the record auditable: it states which
# artifacts the registry PERMITS, so a reader can tell an incomplete build from
# a complete one without re-running anything.

_ARTIFACT_REQUIRED = (
    "slug",
    "kind",
    "version",
    "file",
    "built",
    "file_count",
    "bytes",
    "sha256",
)


def build_manifest(root=None, dist_dir=None, built=None, tag=None, git=None):
    """Assemble the release record for the artifacts present in ``dist_dir``.

    An allowlisted artifact that has not been built is recorded with
    ``built: false`` and a null sha256, so an incomplete build is visible in the
    record instead of silently looking complete.
    """
    root = root or REPO_ROOT
    dist_dir = dist_dir or os.path.join(root, "dist")
    registry = load_registry(root)
    git = git if git is not None else git_meta(root)

    artifacts = []
    for kind, slug in expected_allowlist(registry):
        filename = "%s.zip" % slug
        path = os.path.join(dist_dir, filename)
        present = os.path.isfile(path)

        record = {
            "slug": slug,
            "kind": kind,
            "version": version_for(kind, slug, root),
            "file": filename,
            "built": present,
            "file_count": None,
            "bytes": None,
            "sha256": None,
        }
        if present:
            record["bytes"] = os.path.getsize(path)
            record["sha256"] = sha256_file(path)
            record["file_count"] = zip_entry_count(path)
        artifacts.append(record)

    return {
        "schema_version": MANIFEST_SCHEMA_VERSION,
        "release": {
            "tag": tag or release_tag(built, git, root),
            "built_at": built_at(built),
            "git": git,
        },
        "allowlist": {
            "source": "plugins.json",
            "artifacts": [slug for _kind, slug in expected_allowlist(registry)],
            "production_order": production_slugs(registry),
        },
        "artifacts": artifacts,
        "totals": {
            "artifacts": len(artifacts),
            "built": sum(1 for a in artifacts if a["built"]),
            "files": sum(a["file_count"] or 0 for a in artifacts),
            "bytes": sum(a["bytes"] or 0 for a in artifacts),
        },
    }


def write_manifest(manifest, dist_dir):
    """Write ``dist/release.json`` and return its path."""
    os.makedirs(dist_dir, exist_ok=True)
    path = os.path.join(dist_dir, MANIFEST_FILENAME)
    with open(path, "w", encoding="utf-8") as handle:
        json.dump(manifest, handle, indent=2, ensure_ascii=False, sort_keys=False)
        handle.write("\n")
    return path


def load_manifest(path):
    """Read a release manifest, raising ReleaseError on anything malformed."""
    if not os.path.isfile(path):
        raise ReleaseError("release manifest not found: %s" % path)
    try:
        with open(path, encoding="utf-8") as handle:
            data = json.load(handle)
    except json.JSONDecodeError as exc:
        raise ReleaseError("%s is not valid JSON: %s" % (path, exc)) from exc
    if not isinstance(data, dict):
        raise ReleaseError("%s must contain a JSON object" % path)
    return data


def validate_manifest(manifest):
    """Structural problems with a manifest. Returns a list of strings (empty = ok).

    This checks the record against its OWN schema, so it can run without the
    artifacts, without git and without a WordPress — which is what makes it
    usable as a cheap CI gate.
    """
    problems = []

    if manifest.get("schema_version") != MANIFEST_SCHEMA_VERSION:
        problems.append(
            "schema_version is %r, expected %d"
            % (manifest.get("schema_version"), MANIFEST_SCHEMA_VERSION)
        )

    release = manifest.get("release")
    if not isinstance(release, dict):
        problems.append("'release' must be an object")
    else:
        if not RELEASE_TAG_RE.match(str(release.get("tag", ""))):
            problems.append(
                "release.tag %r does not match the documented convention "
                "vYYYY.MM.DD[-N] (docs/releases.md)" % release.get("tag")
            )
        if not str(release.get("built_at", "")):
            problems.append("release.built_at is missing")
        git = release.get("git")
        if not isinstance(git, dict):
            problems.append("release.git must be an object")
        elif git.get("available") and not re.match(
            r"^[0-9a-f]{40}$", str(git.get("sha", ""))
        ):
            problems.append("release.git.sha is not a full 40-character commit SHA")

    allowlist = manifest.get("allowlist")
    if not isinstance(allowlist, dict) or not isinstance(allowlist.get("artifacts"), list):
        problems.append("'allowlist.artifacts' must be an array")
    elif not allowlist["artifacts"]:
        problems.append("'allowlist.artifacts' is empty")

    artifacts = manifest.get("artifacts")
    if not isinstance(artifacts, list) or not artifacts:
        problems.append("'artifacts' must be a non-empty array")
    else:
        seen = set()
        for index, artifact in enumerate(artifacts):
            where = "artifacts[%d]" % index
            if not isinstance(artifact, dict):
                problems.append("%s must be an object" % where)
                continue
            for field in _ARTIFACT_REQUIRED:
                if field not in artifact:
                    problems.append("%s is missing %r" % (where, field))
            slug = artifact.get("slug")
            if slug in seen:
                problems.append("%s duplicates slug %r" % (where, slug))
            seen.add(slug)
            if not artifact.get("built"):
                continue
            if not re.match(r"^[0-9a-f]{64}$", str(artifact.get("sha256", ""))):
                problems.append("%s sha256 is not a 64-character hex digest" % where)
            for numeric in ("file_count", "bytes"):
                value = artifact.get(numeric)
                if not isinstance(value, int) or isinstance(value, bool) or value < 0:
                    problems.append(
                        "%s %s must be a non-negative integer" % (where, numeric)
                    )

    return problems


def verify_manifest(manifest, root=None, dist_dir=None):
    """Check a manifest against the registry AND against the artifacts on disk.

    This is the "verify artifact allowlist / hashes" step of the release
    sequence. It answers three questions independently, so a failure names its
    own cause:

    1. **Allowlist agreement** — does the recorded allowlist still match what
       ``plugins.json`` permits? (Catches a registry change made after a build.)
    2. **Artifact set** — is every allowlisted artifact present, and is there
       anything in the manifest the allowlist does not permit?
    3. **Integrity** — does each recorded sha256 / size / file-count still
       describe the file in ``dist_dir``? (Catches a tampered or stale artifact.)

    Returns ``(problems, warnings)``. Problems are failures; warnings are
    informational (for example an allowlisted artifact that was not built).
    """
    root = root or REPO_ROOT
    dist_dir = dist_dir or os.path.join(root, "dist")
    registry = load_registry(root)

    expected = expected_allowlist(registry)
    expected_slugs = [slug for _kind, slug in expected]

    problems = list(validate_manifest(manifest))
    warnings = []

    recorded = list((manifest.get("allowlist") or {}).get("artifacts") or [])
    if recorded != expected_slugs:
        problems.append(
            "allowlist drift: manifest records %r but plugins.json permits %r"
            % (recorded, expected_slugs)
        )

    artifacts = manifest.get("artifacts") or []
    by_slug = {a.get("slug"): a for a in artifacts if isinstance(a, dict)}

    missing = [slug for slug in expected_slugs if slug not in by_slug]
    if missing:
        problems.append(
            "manifest omits allowlisted artifact(s): %s" % ", ".join(missing)
        )

    unexpected = [slug for slug in by_slug if slug not in expected_slugs]
    if unexpected:
        problems.append(
            "manifest records artifact(s) the registry does not permit: %s"
            % ", ".join(sorted(unexpected))
        )

    for kind, slug in expected:
        artifact = by_slug.get(slug)
        if artifact is None:
            continue

        expected_version = version_for(kind, slug, root)
        if artifact.get("version") != expected_version:
            problems.append(
                "%s: manifest records version %r but the component header says %r"
                % (slug, artifact.get("version"), expected_version)
            )

        path = os.path.join(dist_dir, str(artifact.get("file", "%s.zip" % slug)))

        if not artifact.get("built"):
            warnings.append(
                "%s: recorded as not built (allowlisted but absent)" % slug
            )
            if os.path.isfile(path):
                problems.append(
                    "%s: manifest says not built, but %s exists"
                    % (slug, os.path.basename(path))
                )
            continue

        if not os.path.isfile(path):
            problems.append("%s: artifact missing from %s" % (slug, dist_dir))
            continue

        actual_sha = sha256_file(path)
        if actual_sha != artifact.get("sha256"):
            problems.append(
                "%s: sha256 mismatch (manifest %s, file %s)"
                % (slug, artifact.get("sha256"), actual_sha)
            )

        actual_bytes = os.path.getsize(path)
        if actual_bytes != artifact.get("bytes"):
            problems.append(
                "%s: byte size mismatch (manifest %s, file %s)"
                % (slug, artifact.get("bytes"), actual_bytes)
            )

        try:
            actual_count = zip_entry_count(path)
        except Exception as exc:  # noqa: BLE001 - report, never crash the gate
            problems.append("%s: unreadable ZIP (%s)" % (slug, exc))
            continue
        if actual_count != artifact.get("file_count"):
            problems.append(
                "%s: file count mismatch (manifest %s, archive %s)"
                % (slug, artifact.get("file_count"), actual_count)
            )

    return problems, warnings
