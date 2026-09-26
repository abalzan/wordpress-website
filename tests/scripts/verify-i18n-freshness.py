#!/usr/bin/env python3
"""Stage L i18n catalogue freshness gate (engineering standard §9.3).

A STATIC check over the repository's translation catalogues. It loads no
WordPress, opens no socket and can never contact production: it only reads
files (and asks `git` for commit timestamps).

§9.3 requires:

    "Freshness check (CI): fail when a .pot is older than the PHP defining its
     strings."

and states that the theme's pt_BR catalogue is an identity catalogue by design
which MUST be regenerated, never hand-edited. This gate therefore never edits a
catalogue: it only reports staleness.

## The freshness signal: git commit time, not filesystem mtime

mtime is unusable for this rule. A fresh `git clone` (exactly what CI does)
gives every file the checkout time, so mtime would report every catalogue as
fresh regardless of the real order of changes, and a `touch` would fabricate a
failure. Git's per-file commit timestamp is the deterministic, reproducible
signal: it survives a clone, a branch switch and a CI runner, and it reflects
the actual last change to the strings.

## Scope

Components are discovered from `plugins.json` (the authoritative registry) plus
the theme. For each component:

  - the catalogue is `<component>/languages/<component-slug>.pot`;
  - the sources are the component's PHP files that actually DEFINE user-facing
    strings, i.e. files containing at least one gettext call.

Excluded from the source set, so the gate has no false positives:

  - `tests/`, `vendor/`, `node_modules/` — fixtures and dependencies, not
    shipped strings;
  - `languages/` — catalogues themselves;
  - `historical/` and `diagnostics/` — one-shot tooling, never deployed;
  - PHP files with no gettext call at all — they define no user-facing string,
    so they cannot make a catalogue stale.

A component with no catalogue is reported (the registry says which components
ship user-facing strings) but is not itself a failure unless it also has
gettext-bearing sources, because a component with no translatable string has
nothing to keep fresh.

Exit code: 0 when every catalogue is fresh, 1 otherwise. Emits one
`GATE-RESULT {...}` line for the Stage L aggregate.
"""

from __future__ import annotations

import json
import os
import re
import subprocess
import sys

REPO_ROOT = os.environ.get("CONEXAO_REPO_ROOT") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
)
REGISTRY = os.path.join(REPO_ROOT, "plugins.json")
THEME_DIR = os.path.join(REPO_ROOT, "wp-content", "themes", "conexao-br-irlanda")

GATE_ID = "i18n_freshness"

# A file "defines user-facing strings" when it calls at least one of these.
GETTEXT_CALL = re.compile(
    r"\b(__|_e|_x|_n|esc_html__|esc_attr__|esc_html_e|esc_attr_e|esc_html_x|esc_attr_x)\s*\("
)

EXCLUDED_DIRS = ("tests", "vendor", "node_modules", "languages", "historical", "diagnostics", "__pycache__")

BASELINE = os.path.join(REPO_ROOT, "tests", "baseline", "permanent-gates.json")

PASSED = 0
FAILURES: list[str] = []
STALE: list[dict] = []


def baseline_keys() -> set[str]:
    """Violation keys recorded as pre-existing at the Stage L baseline.

    Reporting ONLY — a stale catalogue listed here still fails the gate, still
    prints FAIL and still exits non-zero. It exists so the report can separate
    repository debt from a Stage L regression, never to make the gate pass.
    """
    try:
        with open(BASELINE, encoding="utf-8") as handle:
            data = json.load(handle)
    except (OSError, ValueError):
        return set()
    gates = data.get("gates", {}) if isinstance(data, dict) else {}
    return set(gates.get(GATE_ID, []))


def check(condition: bool, message: str) -> bool:
    """Record one assertion. Never raises."""
    global PASSED
    if condition:
        PASSED += 1
    else:
        FAILURES.append(message)
    return bool(condition)


def components() -> list[tuple[str, str]]:
    """(slug, directory) for the theme plus every registry component."""
    found = [("conexao-br-irlanda", THEME_DIR)]

    with open(REGISTRY, encoding="utf-8") as handle:
        registry = json.load(handle)

    for plugin in registry.get("load_order", []):
        slug = plugin.get("slug")
        if not slug:
            continue
        directory = os.path.join(REPO_ROOT, "wp-content", "plugins", slug)
        if os.path.isdir(directory):
            found.append((slug, directory))

    return found


def git_timestamp(path: str) -> int | None:
    """Last commit timestamp for a path, or None when Git cannot answer.

    None means "unknown freshness", which the caller treats as a FAILURE so the
    gate can never pass because the signal was unavailable.
    """
    proc = subprocess.run(
        ["git", "log", "-1", "--format=%ct", "--", path],
        cwd=REPO_ROOT,
        capture_output=True,
        text=True,
        check=False,
    )
    value = proc.stdout.strip()
    if proc.returncode != 0 or not value:
        return None
    try:
        return int(value)
    except ValueError:
        return None


def translatable_sources(directory: str) -> list[str]:
    """PHP files in a component that actually define user-facing strings."""
    sources: list[str] = []
    for dirpath, dirnames, filenames in os.walk(directory):
        dirnames[:] = sorted(d for d in dirnames if d not in EXCLUDED_DIRS)
        for name in sorted(filenames):
            if not name.endswith(".php"):
                continue
            path = os.path.join(dirpath, name)
            try:
                with open(path, encoding="utf-8", errors="replace") as handle:
                    body = handle.read()
            except OSError:
                continue
            if GETTEXT_CALL.search(body):
                sources.append(path)
    return sorted(sources)


def main() -> int:
    print("== Stage L i18n catalogue freshness gate ==")
    print(f"   repo:  {REPO_ROOT}")
    print("   signal: git commit time per file (mtime is not reproducible in CI)")

    pairs = components()
    check(len(pairs) > 0, "components resolved from plugins.json + the theme")

    for slug, directory in pairs:
        catalogue = os.path.join(directory, "languages", f"{slug}.pot")
        rel_catalogue = os.path.relpath(catalogue, REPO_ROOT)
        sources = translatable_sources(directory)

        if not os.path.isfile(catalogue):
            # No catalogue and no translatable strings: nothing to keep fresh.
            if sources:
                check(
                    False,
                    f"{slug}: {len(sources)} file(s) define user-facing strings but "
                    f"{rel_catalogue} does not exist",
                )
            else:
                check(True, f"{slug}: no translatable strings and no catalogue (nothing to keep fresh)")
            continue

        if not sources:
            check(True, f"{slug}: catalogue present, no user-facing strings to make it stale")
            continue

        catalogue_ts = git_timestamp(catalogue)
        if catalogue_ts is None:
            check(
                False,
                f"{slug}: could not determine the commit time of {rel_catalogue}; "
                f"an unknown freshness signal is a FAILURE, never a pass",
            )
            continue

        # The newest source that defines user-facing strings.
        newest_source = None
        newest_ts = -1
        for source in sources:
            stamp = git_timestamp(source)
            if stamp is None:
                continue
            if stamp > newest_ts:
                newest_ts = stamp
                newest_source = source

        if newest_source is None:
            check(
                False,
                f"{slug}: could not determine the commit time of any of the "
                f"{len(sources)} translatable source file(s)",
            )
            continue

        rel_newest = os.path.relpath(newest_source, REPO_ROOT)

        if newest_ts > catalogue_ts:
            STALE.append({
                "component": slug,
                "catalogue": rel_catalogue,
                "newest_source": rel_newest,
                "catalogue_ts": catalogue_ts,
                "newest_source_ts": newest_ts,
                "lag_seconds": newest_ts - catalogue_ts,
            })

        print(
            f"   {slug:24s} catalogue={catalogue_ts} newest-source={newest_ts} "
            f"({rel_newest}) {'STALE' if newest_ts > catalogue_ts else 'fresh'}"
        )

    print(f"\n   stale catalogues: {len(STALE)}")
    for entry in STALE:
        FAILURES.append(
            f"{entry['component']}: {entry['catalogue']} is older than "
            f"{entry['newest_source']} by {entry['lag_seconds']}s — "
            f"regenerate the catalogue with `wp i18n make-pot` (never hand-edit)"
        )
    return 0


def emit() -> None:
    """Print the runner summary plus the Stage L machine-readable line."""
    passed = PASSED
    failed = len(FAILURES)

    for failure in FAILURES:
        print(f"FAIL: {failure}")

    print(f"{passed} passed, {failed} failed")

    known = baseline_keys()
    pre_existing = sum(1 for e in STALE if f"i18n:{e['component']}:stale_catalogue" in known)
    new = len(STALE) - pre_existing

    if pre_existing or new:
        print(f"  stale catalogues: {pre_existing} pre-existing, {new} new")

    payload = {
        "gate": GATE_ID,
        "status": "fail" if failed else "pass",
        "passed": passed,
        "failed": failed,
        "violations": len(STALE),
        "pre_existing": pre_existing,
        "new": new,
        "details": STALE,
    }
    print("GATE-RESULT " + json.dumps(payload))
    sys.exit(1 if failed else 0)


if __name__ == "__main__":
    main()
    emit()
