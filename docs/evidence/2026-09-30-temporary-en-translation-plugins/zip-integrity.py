#!/usr/bin/env python3
"""Temporary ZIP integrity and leakage inspection.

Evidence for docs/reports/2026-09-30-temporary-en-translation-plugins.md.

Inspects each artifact in ``dist/temporary/`` programmatically and asserts the
Phase 5 / Phase 10 rules:

  * the archive root is exactly ``<plugin-slug>/`` (WordPress' required layout);
  * the plugin main file is present and its header version / PHP / WP minima
    are reported;
  * only ``.php`` runtime files ship, and every one of them parses;
  * the shipped bytes are byte-identical to the repository source, so the
    artifact cannot have been doctored relative to ``wp-content/plugins/``;
  * leakage counts are all zero: ``.env``, secrets/credentials, tests,
    fixtures, reports, docs, caches, node_modules, temporary worktrees,
    unrelated plugins, Flutter/mobile files.

Read-only: it opens the ZIPs and the source tree and writes nothing.
"""

from __future__ import annotations

import hashlib
import os
import re
import subprocess
import sys
import zipfile

REPO_ROOT = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", ".."))
DIST = os.path.join(REPO_ROOT, "dist", "temporary")
PLUGINS_DIR = os.path.join(REPO_ROOT, "wp-content", "plugins")

SLUGS = ("conexao-translation-rollout", "conexao-en-translation")

# Directories that must never appear inside a temporary artifact.
FORBIDDEN_DIR_PARTS = (
    "tests", "fixtures", "docs", "reports", "evidence", "screenshots",
    "node_modules", "vendor", "__pycache__", "logs", "tmp", "worktree",
)
FORBIDDEN_NAME_RE = re.compile(
    r"(\.env$|^\.env\.|\.log$|\.bak$|\.orig$|\.rej$|\.swp$|~$|\.pyc$|\.DS_Store$|Thumbs\.db$)",
    re.IGNORECASE,
)
# Anything that smells like a mobile/Flutter artifact.
MOBILE_RE = re.compile(
    r"(\.dart$|pubspec\.ya?ml$|android/|ios/|\.apk$|\.ipa$|lib/main\.dart)",
    re.IGNORECASE,
)
# Credential-ish content. Placeholders are fine; real values are not.
SECRET_RE = re.compile(
    r"(WP_APPLICATION_PASSWORD\s*=\s*['\"][^'\"]+['\"]"
    r"|WP_PASSWORD\s*=\s*['\"][^'\"]+['\"]"
    r"|-----BEGIN [A-Z ]*PRIVATE KEY-----"
    r"|Authorization['\"]\s*:\s*f?['\"]Basic [A-Za-z0-9+/=]{8,})"
)
SECRET_PLACEHOLDER = ("xxxx", "example", "placeholder", "changeme", "redacted", "<", ">", "***")

PASSED = 0
FAILURES: list[str] = []


def check(condition: bool, message: str) -> bool:
    global PASSED
    if condition:
        PASSED += 1
        print("  [PASS] %s" % message)
    else:
        FAILURES.append(message)
        print("  [FAIL] %s" % message)
    return bool(condition)


def main() -> int:
    print("TEMPORARY ZIP INTEGRITY — dist/temporary/")
    print("=" * 72)

    for slug in SLUGS:
        path = os.path.join(DIST, "%s.zip" % slug)
        print("\n%s" % slug)
        print("-" * 72)

        if not check(os.path.isfile(path), "%s.zip exists" % slug):
            continue

        raw = open(path, "rb").read()
        sha = hashlib.sha256(raw).hexdigest()

        with zipfile.ZipFile(path) as zf:
            names = [n for n in zf.namelist() if not n.endswith("/")]

            # -- layout ------------------------------------------------------
            roots = {n.split("/")[0] for n in names}
            check(roots == {slug}, "archive root is exactly '%s/' (got %r)" % (slug, sorted(roots)))

            main_file = "%s/%s.php" % (slug, slug)
            check(main_file in names, "contains the plugin main file %s" % main_file)

            # -- headers -----------------------------------------------------
            header = zf.read(main_file).decode("utf-8", "replace")

            def hdr(label: str) -> str:
                match = re.search(r"^\s*\*\s*%s:\s*(.+?)\s*$" % label, header, re.M)
                return match.group(1) if match else ""

            version = hdr("Version")
            print("  file count:   %d" % len(names))
            print("  bytes:        %d" % len(raw))
            print("  sha256:       %s" % sha)
            print("  plugin name:  %s" % (hdr("Plugin Name") or "(none)"))
            print("  version:      %s" % (version or "(undeclared)"))
            print("  requires php: %s" % (hdr("Requires PHP") or "(undeclared)"))
            print("  requires wp:  %s" % (hdr("Requires at least") or "(undeclared)"))
            print("  requires plg: %s" % (hdr("Requires Plugins") or "(none)"))

            check(bool(version), "declares a Version header")

            # -- only runtime PHP --------------------------------------------
            non_php = [n for n in names if not n.endswith((".php", ".md"))]
            check(not non_php, "ships only .php runtime files (+ documented .md): %r" % non_php)

            # -- leakage ------------------------------------------------------
            env_hits = [n for n in names if FORBIDDEN_NAME_RE.search(os.path.basename(n))]
            check(not env_hits, "no .env / log / editor-junk files: %r" % env_hits)

            dir_hits = [
                n for n in names
                if any(part in FORBIDDEN_DIR_PARTS for part in n.split("/")[:-1])
            ]
            check(not dir_hits, "no tests/fixtures/docs/reports/caches/vendor dirs: %r" % dir_hits)

            other_plugins = sorted({n.split("/")[0] for n in names} - {slug})
            check(not other_plugins, "no unrelated plugin directories: %r" % other_plugins)

            mobile = [n for n in names if MOBILE_RE.search(n)]
            check(not mobile, "no Flutter/mobile files: %r" % mobile)

            secret_hits = []
            for name in names:
                if not name.endswith((".php", ".md")):
                    continue
                body = zf.read(name).decode("utf-8", "replace")
                for match in SECRET_RE.finditer(body):
                    if any(marker in match.group(0).lower() for marker in SECRET_PLACEHOLDER):
                        continue
                    secret_hits.append(name)
                    break
            check(not secret_hits, "no credential literals: %r" % secret_hits)

            # -- byte-identity with the source tree ---------------------------
            # The archive is rooted at "<slug>/", which is exactly
            # wp-content/plugins/<slug>/ with the container stripped.
            mismatched = []
            for name in names:
                source = os.path.join(PLUGINS_DIR, name)
                if not os.path.isfile(source):
                    mismatched.append(name + " (absent in source)")
                elif open(source, "rb").read() != zf.read(name):
                    mismatched.append(name + " (differs from source)")
            check(not mismatched, "every shipped file is byte-identical to wp-content/plugins/: %r" % mismatched)

            # -- the shipped PHP actually parses -------------------------------
            unparsable = []
            for name in names:
                if not name.endswith(".php"):
                    continue
                proc = subprocess.run(["php", "-l"], input=zf.read(name), capture_output=True)
                if proc.returncode != 0:
                    unparsable.append(name)
            check(not unparsable, "every shipped .php file parses: %r" % unparsable)

    print("\n" + "=" * 72)
    print("assertions: %d total, %d passed, %d failed" % (PASSED + len(FAILURES), PASSED, len(FAILURES)))
    for failure in FAILURES:
        print("  FAILED: %s" % failure)
    return 1 if FAILURES else 0


if __name__ == "__main__":
    sys.exit(main())
